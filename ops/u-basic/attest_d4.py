#!/usr/bin/env python3
"""Cervesur D4 v2: read-only security attestation of legacy PHP before P4b.

No passwords/tokens/.env values are printed. No HTTP write, SQL, DDL or config
changes. Exit 0 means all *critical file and K1 GET checks* passed, not that
real JWT authorization tests with normal/admin accounts passed. JWT fallback
literals are reported as unresolved residual debt, never mislabeled PASS.
"""
from __future__ import annotations
import argparse
import hashlib
import hmac
import os
from pathlib import Path
import re
import stat
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

LEGACY = Path("/var/www/cervesur/backend")
F10_SHA = "4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366"
H1_SHA = "c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298"
EXPECTED_TTL = "86400"
EXPECTED_K1 = "retirar"
DEFAULT_K1 = "define('POWERBI_AUTH_MODE', 'retirar');"
OLD_K1 = "define('POWERBI_AUTH_MODE', 'observar');"
KNOWN_BAD = {
    "secret", "secreto", "changeme", "change-me", "password", "contrasena",
    "jwt_secret", "jwtsecret", "your-secret-key", "your_secret_key",
    "supersecret", "clave", "123456", "qwerty", "admin", "cervesur",
    "baltrek", "test", "default",
}
JWT_FALLBACK_PATTERN = re.compile(
    r"""JWT_SECRET['"]\s*\)\s*\?:\s*(['"])(.*?)\1""",
    flags=re.S,
)
KEY_PATTERN = re.compile(r"^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$")

class AuditFailure(Exception):
    pass

def read_secure(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise AuditFailure("ARCHIVO_INVALIDO_" + path.name)
    return path.read_bytes()

def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()

def env_public_private_checks(path: Path) -> tuple[dict[str, str], list[tuple[str, str]]]:
    raw = read_secure(path)
    errors: list[tuple[str, str]] = []
    flags = {}
    if path.stat().st_mode & stat.S_IROTH | path.stat().st_mode & stat.S_IWOTH:
        errors.append(("D4-01", "FAIL_PERMISOS_OTROS"))
    else:
        errors.append(("D4-01", "PASS_PERMISOS_OTROS_CERRADOS"))
    for line in raw.decode("utf-8").splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        m = KEY_PATTERN.fullmatch(line.strip())
        if m and m.group(1) in {"JWT_SECRET", "JWT_EXPIRATION"}:
            key = m.group(1)
            if key in flags:
                raise AuditFailure("ENV_DUPLICADO_" + key)
            value = m.group(2).strip()
            if len(value) >= 2 and value[0] in "'\"" and value[-1] == value[0]:
                value = value[1:-1]
            flags[key] = value
    return flags, errors

def secret_checks(secret: str | None) -> list[tuple[str, str]]:
    if secret is None or not secret:
        return [("D4-02", "FAIL_SECRET_NO_DEFINIDO")]
    chars = len(secret.encode("utf-8"))
    safe = chars >= 32 and len(set(secret)) >= 12
    weak = secret.lower() in KNOWN_BAD or (
        chars < 24 and any(secret.lower().startswith(w) for w in KNOWN_BAD)
    )
    return [
        ("D4-02", "PASS_SECRET_DEFINIDO"),
        ("D4-03", "PASS_LONGITUD" if chars >= 32 else "FAIL_LONGITUD"),
        ("D4-04", "PASS_NO_EJEMPLO" if not weak else "FAIL_SECRET_DE_EJEMPLO"),
        ("D4-05", "PASS_VARIACION" if len(set(secret)) >= 12 else "FAIL_VARIACION"),
    ]

def fallback_checks(base: Path, secret: str | None) -> list[tuple[str, str]]:
    n = 0
    matches = 0
    for sub in ("router", "controllers", "lib"):
        path = base / sub
        if not path.is_dir() or path.is_symlink():
            raise AuditFailure("DIRECTORIO_PHP_AUSENTE_" + sub)
        for script in path.glob("*.php"):
            content = read_secure(script).decode("utf-8", errors="replace")
            for m in JWT_FALLBACK_PATTERN.finditer(content):
                n += 1
                if secret is not None and hmac.compare_digest(secret.encode(), m.group(2).encode()):
                    matches += 1
    if matches:
        return [
            ("D4-06", "FAIL_SECRETO_IGUAL_A_LITERAL"),
            ("D4-06b", "PENDIENTE_LITERAL_POR_DEFECTO_CANTIDAD_" + str(n)),
        ]
    return [
        ("D4-06", "PASS_SECRET_DISTINTO_DE_LITERALES" if secret else "FAIL_SIN_SECRET"),
        ("D4-06b", "PENDIENTE_LITERAL_POR_DEFECTO_CANTIDAD_" + str(n)
         if n else "PASS_SIN_FALLBACKS"),
    ]

def file_guard_checks(base: Path) -> list[tuple[str, str]]:
    expected = [
        ("D4-F10-SHA", base / "router/auth.php", F10_SHA),
        ("D4-H1-SHA", base / "lib/auth_middleware.php", H1_SHA),
    ]
    result = []
    for label, path, known in expected:
        actual = sha(read_secure(path))
        result.append((label, "PASS" if hmac.compare_digest(actual, known)
                       else "FAIL_HUELLA_DESCONOCIDA"))
    k1 = read_secure(base / "lib/powerbi_authz.php").decode("utf-8", errors="replace")
    if ("if (!defined('POWERBI_AUTH_MODE'))" in k1
            and k1.count(DEFAULT_K1) == 1 and OLD_K1 not in k1):
        result.append(("D4-08", "PASS_FALLBACK_RETIRAR"))
    else:
        result.append(("D4-08", "FAIL_FALLBACK_NO_UNICO"))
    # F10 is SHA-verified above, not guessed from a removed REGISTRO_ABIERTO marker.
    if result[0][1] == "PASS":
        result.append(("D4-09", "PASS_F10_VERSION_FIRMADA"))
    else:
        result.append(("D4-09", "FAIL_F10_NO_VERIFICADA"))
    return result

def k1_http_code(url: str) -> int:
    if not re.fullmatch(r"http://(?:127\.0\.0\.1|192\.168\.200\.12)/api/powerbi/tokens/0", url):
        raise AuditFailure("URL_HTTP_FUERA_DE_ALCANCE")
    req = Request(url, method="GET")
    try:
        with urlopen(req, timeout=8) as response:
            return response.status
    except HTTPError as exc:
        return exc.code
    except (URLError, TimeoutError) as exc:
        raise AuditFailure("K1_HTTP_NO_DISPONIBLE") from exc

def review(base: Path, url: str) -> list[tuple[str, str]]:
    env, outputs = env_public_private_checks(base / ".env")
    outputs.extend(secret_checks(env.get("JWT_SECRET")))
    outputs.extend(fallback_checks(base, env.get("JWT_SECRET")))
    ttl = env.get("JWT_EXPIRATION")
    outputs.append(("D4-07", "PASS_JWT_86400" if ttl == EXPECTED_TTL
                    else "FAIL_JWT_EXPIRACION"))
    outputs.extend(file_guard_checks(base))
    http = k1_http_code(url)
    outputs.append(("D4-08b", "PASS_HTTP_410" if http == 410
                    else "FAIL_HTTP_" + str(http)))
    return outputs

def run() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", default=str(LEGACY))
    parser.add_argument("--k1-url", default="http://192.168.200.12/api/powerbi/tokens/0")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise AuditFailure("REQUIERE_SUDO")
    result = review(Path(args.root), args.k1_url)
    critical = [k for _, k in result if k.startswith("FAIL")]
    for label, status in result:
        print(label + "=" + status)
    print("D4_CRITICOS=" + ("PASS" if not critical else "NO_GO"))
    print("JWT_FALLBACKS_REMOVIDOS=" + (
        "SI" if any(k == "PASS_SIN_FALLBACKS" for _, k in result) else "NO"))
    print("QA_HTTP_USER_ADMIN=PENDIENTE")
    print("ACTIVACION_U_BASIC=NO_REALIZADA")
    print("MYSQL_MODIFICADO=NO")
    return 0 if not critical else 2

if __name__ == "__main__":
    try:
        sys.exit(run())
    except (AuditFailure, OSError, ValueError, UnicodeError) as exc:
        print("D4_NO_GO=" + str(exc), file=sys.stderr)
        sys.exit(2)
