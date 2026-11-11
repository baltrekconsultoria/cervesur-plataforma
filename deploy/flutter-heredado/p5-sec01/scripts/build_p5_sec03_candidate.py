#!/usr/bin/env python3
"""Prepara un candidato P5-SEC03 LOCAL sobre el PHP Flutter REAL, sin instalarlo.

No sube el original a GitHub; nunca escribe en /var/www ni en /opt.
Uso: python build_p5_sec03_candidate.py --source RUTA_ORIGINAL --out DIR_NUEVO
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import sys
from pathlib import Path

# Línea base D3/P5-A5 comprobada contra los bytes del archivo del operador.
EXPECTED_SOURCE_SHA256 = "fbdf6253eec6bbf4648446d84ee3214556b85baaa2f8d3cb662e2e9bb8ea5476"
REQUIRE_JWT = "require_once __DIR__ . '/jwt.php';"
AUTH_START = "function authenticate_user($jwt_secret) {"
AUTH_END = "/**\n * Middleware para endpoints protegidos"
REQUIRE_ADAPTER = "require_once __DIR__ . '/p5_legacy_adapter.php';"

# Validación de política de expiración: leer el TTL YA configurado en el servidor.
# No modifica JWT_EXPIRATION, JWT_SECRET ni las funciones públicas del middleware.
STRICT_FUNCTION = r"""function p5_sec03_ttl_from_env() {
    $value = getenv('JWT_EXPIRATION');
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,4}\z/D', $value)) {
        return false; // Falta configuracion -> fail closed
    }
    $ttl = (int)$value;
    return $ttl >= 1 && $ttl <= 86400 ? $ttl : false;
}

function authenticate_user($jwt_secret) {
    global $pdo;
    if (!$pdo instanceof PDO) {
        error_log('P5-SEC03: PDO no disponible');
        return false;
    }
    $ttl = p5_sec03_ttl_from_env();
    if ($ttl === false) {
        error_log('P5-SEC03: JWT_EXPIRATION no valido');
        return false;
    }
    try {
        $headers = getallheaders();
        if (!is_array($headers)) {
            return false;
        }
        return p5_legacy_authenticate_headers($headers, $jwt_secret, $pdo, null, $ttl);
    } catch (Throwable $e) {
        // No revelar SQL, JWT, usuario ni secreto.
        error_log('P5-SEC03: error al autenticar');
        return false;
    }
}
"""


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def rewrite_middleware(text: str) -> str:
    """Transformación exacta, rechaza bases inesperadas o repetidas."""
    if text.count(REQUIRE_JWT) != 1 or text.count(AUTH_START) != 1 or text.count(AUTH_END) != 1:
        raise ValueError("P5-SEC03: marcadores del middleware distintos de la línea base D3")
    if REQUIRE_ADAPTER in text or "function p5_sec03_ttl_from_env" in text:
        raise ValueError("P5-SEC03: el archivo ya contiene parte del parche")
    # El código original debe enlazar al JWT legado; no sustituir jwt.php.
    text = text.replace(REQUIRE_JWT, REQUIRE_JWT + "\n" + REQUIRE_ADAPTER, 1)
    start = text.index(AUTH_START)
    stop = text.index(AUTH_END, start)
    if stop <= start:
        raise ValueError("P5-SEC03: límites de authenticate_user inválidos")
    return text[:start] + STRICT_FUNCTION + "\n" + text[stop:]


def ensure_safe_output(source: Path, target: Path) -> None:
    source = source.resolve()
    target = target.resolve()
    if target == source or target == source.parent:
        raise ValueError("P5-SEC03: no sobrescribir fuente ni su directorio")
    if target.exists():
        raise FileExistsError("P5-SEC03: el directorio destino ya existe, no sobrescribir")
    if any(str(target).startswith(prefix) for prefix in (
        "/var/www/", "/opt/cervesur", "/etc/", "/usr/", "/root/"
    )):
        raise ValueError("P5-SEC03: destino productivo o del sistema prohibido")
    if target in source.parents:
        raise ValueError("P5-SEC03: destino ancestro de fuente no permitido")
    # No almacenar el paquete dentro del repositorio (riesgo de commitear fuente).
    repo_root = Path(__file__).resolve().parents[4]
    if target == repo_root or repo_root in target.parents:
        raise ValueError("P5-SEC03: generar candidato FUERA del repositorio")


def stage(source: Path, target: Path) -> None:
    raw = source.read_bytes()
    if sha256(raw) != EXPECTED_SOURCE_SHA256:
        raise ValueError("P5-SEC03: SHA256 de fuente NO coincide con original D3; detener")
    decoded = raw.decode("utf-8")
    converted = rewrite_middleware(decoded)
    helpers = Path(__file__).resolve().parents[1] / "candidato"
    expected_helpers = ["p5_strict_auth.php", "p5_legacy_adapter.php"]
    for name in expected_helpers:
        if not (helpers / name).is_file():
            raise FileNotFoundError("P5-SEC03: helper no encontrado: " + name)
    ensure_safe_output(source, target)
    os.umask(0o077)
    target.mkdir(mode=0o700, parents=True, exist_ok=False)
    files = {"auth_middleware.php": converted.encode("utf-8")}
    for name in expected_helpers:
        files[name] = (helpers / name).read_bytes()
    for name, data in files.items():
        output = target / name
        output.write_bytes(data)
        output.chmod(0o600)
    report = {
        "source_sha256": EXPECTED_SOURCE_SHA256,
        "files_sha256": {name: sha256(data) for name, data in files.items()},
        "status": "CANDIDATO_LOCAL_SIN_DESPLIEGUE",
        "jwt_expiration": "Se valida JWT_EXPIRATION existente, no se cambia",
        "jwt_php": "Se conserva el jwt.php original; el helper estricto valida dentro de authenticate_user"
    }
    (target / "MANIFIESTO-P5-SEC03.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
    )
    print("P5_SEC03_FUENTE_SHA256=PASS")
    print("P5_SEC03_CANDIDATO_LOCAL=PREPARADO")
    print("P5_SEC03_PRODUCCION=NO_MODIFICADA")
    print("P5_SEC03_DESTINO=" + str(target))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--out", type=Path, required=True)
    opts = parser.parse_args()
    try:
        stage(opts.source, opts.out)
    except (OSError, ValueError, UnicodeError) as exc:
        print(f"P5_SEC03=FAIL_CERRADO ({type(exc).__name__})", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
