#!/usr/bin/env python3
"""Cervesur Fallback9: minimal, SHA-pinned, backup-first fail-closed JWT patch.

Target: only legacy router/ReporteRouter.php (8 literals) and
router/UserRouter.php (1 literal), never an entire router replacement.
The installed, SHA-pinned auth_middleware.php already defines
get_jwt_secret_or_fail(). It returns HTTP 500 and exits if JWT_SECRET is
missing, preventing any hardcoded fallback key from authenticating a token.

PRE:  exact original SHA, matching code shape and D4 fallback counts 8+1.
POST: exactly 9 replacements, 0 D4 fallbacks, PHP lint, H1/F10 untouched.
BACKUP: private root-only snapshot with before/after SHA.
ROLLBACK: will not overwrite files edited by another actor since deployment.
No SQL, login, user edits, logs, tokens, secrets or U-BASIC activation.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile

ROOT = Path("/var/www/cervesur/backend")
BACKUP_HOME = Path("/home/usr_cervesur")
LOCK = Path("/run/lock/cervesur-fallback9.lock")
FILES = {
    "router/ReporteRouter.php": {
        "sha": "ea48e5b011e945e9569bb177bf1c6afa5eee025ddd0287163db78e93581c59a6",
        "count": 8,
        "lines": [21, 40, 58, 75, 86, 97, 108, 119],
    },
    "router/UserRouter.php": {
        "sha": "41610318f9bb5cd11ad7daa40cfbcf95f1742e5f14d5c4d4b59e4613a5206709",
        "count": 1,
        "lines": [179],
    },
}
H1_SHA = "c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298"
F10_SHA = "4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366"
D4_PATTERN = re.compile(rb"""JWT_SECRET['"]\s*\)\s*\?:\s*(['"])(.*?)\1""", re.S)
# Only one-line, simple PHP assignment to the existing auth variable is allowed.
PATCH_PATTERN = re.compile(
    rb"""(?m)^([ \t]*\$jwt_secret[ \t]*=[ \t]*)getenv\([ \t]*(['"])JWT_SECRET\2[ \t]*\)[ \t]*\?:[ \t]*(['"])[^\r\n]*?\3([ \t]*;)"""
)
REPLACEMENT = b"get_jwt_secret_or_fail('ruta protegida')"
CONFIRM = "CERVESUR_APLICAR_FALLBACK9_CON_BACKUP"

class NoGo(Exception):
    pass

def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def guarded(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise NoGo("ARCHIVO_INVALIDO_" + path.name)
    return path.read_bytes()

def verify_guards(root: Path) -> None:
    for rel, expected, code in (
        ("lib/auth_middleware.php", H1_SHA, "H1"),
        ("router/auth.php", F10_SHA, "F10"),
    ):
        if sha(guarded(root / rel)) != expected:
            raise NoGo("GUARDIA_" + code + "_SHA_NO_PASS")
    middleware = guarded(root / "lib/auth_middleware.php")
    if b"function get_jwt_secret_or_fail(" not in middleware:
        raise NoGo("GUARDIA_H1_HELPER_AUSENTE")

def patch_one(raw: bytes, expected_count: int, expected_lines: list[int]) -> bytes:
    raw_matches = list(D4_PATTERN.finditer(raw))
    if len(raw_matches) != expected_count:
        raise NoGo("D4_CONTEO_ORIGEN_INESPERADO")
    lines = [raw.count(b"\n", 0, match.start()) + 1 for match in raw_matches]
    if lines != expected_lines:
        raise NoGo("D4_LINEAS_ORIGEN_INESPERADAS")
    precise = list(PATCH_PATTERN.finditer(raw))
    if len(precise) != expected_count:
        raise NoGo("PHP_ASIGNACIONES_NO_CONCUERDAN_CON_D4")
    output, count = PATCH_PATTERN.subn(
        lambda m: m.group(1) + REPLACEMENT + m.group(4), raw
    )
    if count != expected_count or D4_PATTERN.search(output):
        raise NoGo("D4_AUN_TIENE_FALLBACK")
    if output.count(REPLACEMENT) - raw.count(REPLACEMENT) != expected_count:
        raise NoGo("REEMPLAZOS_INCOMPLETOS")
    if output.replace(REPLACEMENT, b"get_jwt_secret_or_fail('ruta protegida')") != output:
        raise NoGo("POST_CAMBIO_INVALIDO")
    return output

def plan(root: Path) -> tuple[dict[str, bytes], dict[str, bytes]]:
    verify_guards(root)
    before: dict[str, bytes] = {}
    after: dict[str, bytes] = {}
    for rel, expected in FILES.items():
        original = guarded(root / rel)
        if sha(original) != expected["sha"]:
            raise NoGo("SHA_ORIGEN_NO_PASS_" + Path(rel).name)
        if b"auth_middleware.php" not in original:
            raise NoGo("RUTA_SIN_IMPORT_MIDDLEWARE_" + Path(rel).name)
        before[rel] = original
        after[rel] = patch_one(original, expected["count"], expected["lines"])
    if sum(x.count(REPLACEMENT) - before[p].count(REPLACEMENT)
           for p, x in after.items()) != 9:
        raise NoGo("REEMPLAZOS_TOTAL_NO_ES_9")
    return before, after

def php_lint(data: bytes) -> None:
    with tempfile.TemporaryDirectory(prefix="cervesur-fallback9-") as directory:
        path = Path(directory) / "check.php"
        path.write_bytes(data)
        result = subprocess.run(["php", "-l", str(path)], capture_output=True,
                                timeout=30, check=False)
        if result.returncode:
            # Do not print the source or error excerpt (it may contain a literal).
            raise NoGo("PHP_LINT_NO_PASS")

def atomic_copy(path: Path, data: bytes) -> None:
    info = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".fallback9-", dir=str(path.parent))
    try:
        os.fchmod(fd, stat.S_IMODE(info.st_mode))
        os.fchown(fd, info.st_uid, info.st_gid)
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.replace(tmp, path)
        directory = os.open(str(path.parent), os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def save_backup(root: Path, before: dict[str, bytes], after: dict[str, bytes]) -> Path:
    if not BACKUP_HOME.is_dir() or BACKUP_HOME.is_symlink():
        raise NoGo("BACKUP_HOME_NO_VALIDO")
    if shutil.disk_usage(BACKUP_HOME).free < 16 * 1024 * 1024:
        raise NoGo("SIN_ESPACIO_PARA_BACKUP")
    when = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    folder = BACKUP_HOME / ("cervesur-fallback9-" + when + "-" + str(os.getpid()))
    folder.mkdir(mode=0o700)
    manifest = {"kind": "cervesur-fallback9-v1", "entries": {}}
    for rel in FILES:
        dest = folder / rel
        dest.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        dest.write_bytes(before[rel])
        dest.chmod(0o600)
        if sha(guarded(dest)) != sha(before[rel]):
            raise NoGo("BACKUP_SHA_NO_PASS")
        manifest["entries"][rel] = {
            "before": sha(before[rel]), "after": sha(after[rel]),
        }
    manifest_path = folder / "manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    manifest_path.chmod(0o600)
    return folder

def apply(root: Path) -> Path:
    before, after = plan(root)
    for candidate in after.values():
        php_lint(candidate)
    folder = save_backup(root, before, after)
    updated = []
    try:
        for rel in FILES:
            path = root / rel
            if sha(guarded(path)) != sha(before[rel]):
                raise NoGo("RACE_MODIFICACION_" + Path(rel).name)
            atomic_copy(path, after[rel])
            updated.append(rel)
            if sha(guarded(path)) != sha(after[rel]):
                raise NoGo("POST_SHA_NO_PASS_" + Path(rel).name)
    except BaseException:
        for rel in reversed(updated):
            path = root / rel
            if sha(guarded(path)) == sha(after[rel]):
                atomic_copy(path, before[rel])
        raise
    return folder

def verify_applied(root: Path, folder: Path) -> None:
    verify_guards(root)
    meta = json.loads(guarded(folder / "manifest.json"))
    if meta.get("kind") != "cervesur-fallback9-v1":
        raise NoGo("MANIFEST_NO_VALIDO")
    for rel in FILES:
        candidate = guarded(root / rel)
        if sha(candidate) != meta["entries"][rel]["after"]:
            raise NoGo("POST_SHA_DRIFT_" + Path(rel).name)
        if D4_PATTERN.search(candidate):
            raise NoGo("FALLBACK_RESIDUAL_" + Path(rel).name)
        php_lint(candidate)

def rollback(root: Path, folder: Path) -> None:
    if folder.is_symlink() or folder.parent.resolve() != BACKUP_HOME.resolve() or not folder.name.startswith("cervesur-fallback9-"):
        raise NoGo("BACKUP_PATH_INVALIDO")
    verify_guards(root)
    meta = json.loads(guarded(folder / "manifest.json"))
    if meta.get("kind") != "cervesur-fallback9-v1":
        raise NoGo("MANIFEST_NO_VALIDO")
    originals = {}
    for rel in FILES:
        raw = guarded(folder / rel)
        candidate = guarded(root / rel)
        info = meta["entries"][rel]
        if sha(raw) != info["before"] or sha(candidate) != info["after"]:
            raise NoGo("SHA_REVERSION_NO_COINCIDE_" + Path(rel).name)
        originals[rel] = raw
    for rel in FILES:
        atomic_copy(root / rel, originals[rel])
        if sha(guarded(root / rel)) != meta["entries"][rel]["before"]:
            raise NoGo("ROLLBACK_SHA_NO_PASS_" + Path(rel).name)

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("action", choices=["preflight", "apply", "verify", "rollback"])
    p.add_argument("--backup", default="")
    p.add_argument("--confirm", default="")
    args = p.parse_args()
    if os.geteuid() != 0:
        raise NoGo("REQUIERE_SUDO")
    os.umask(0o077)
    with LOCK.open("w") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        if args.action == "rollback":
            if args.confirm != CONFIRM or not args.backup:
                raise NoGo("CONFIRMACION_O_BACKUP_FALTANTE")
            rollback(ROOT, Path(args.backup))
            print("JWT_FALLBACK9_ROLLBACK=PASS")
        elif args.action == "verify":
            if not args.backup:
                raise NoGo("BACKUP_FALTANTE")
            verify_applied(ROOT, Path(args.backup))
            print("JWT_FALLBACKS_RESTANTES=0")
            print("JWT_FALLBACK9_VERIFY=PASS")
        else:
            before, after = plan(ROOT)
            for item in after.values():
                php_lint(item)
            print("F10_SHA=PASS")
            print("H1_SHA=PASS")
            print("JWT_FALLBACKS_ANTES=9")
            print("JWT_FALLBACKS_CANDIDATO=0")
            print("PHP_LINT_CANDIDATO=PASS")
            if args.action == "preflight":
                print("PREFLIGHT=PASS_SOLO_LECTURA")
            else:
                if args.confirm != CONFIRM:
                    raise NoGo("CONFIRMACION_FALTANTE")
                folder = apply(ROOT)
                verify_applied(ROOT, folder)
                print("BACKUP_VERIFICADO=SI")
                print("BACKUP_PATH=" + str(folder))
                print("JWT_FALLBACK9_APLICADO=SI")
                print("JWT_FALLBACKS_RESTANTES=0")
        print("MYSQL_MODIFICADO=NO")
        print("U_BASIC_ACTIVADO=NO")
        return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except (NoGo, OSError, KeyError, ValueError, subprocess.SubprocessError) as exc:
        print("JWT_FALLBACK9_NO_GO=" + str(exc), file=sys.stderr)
        sys.exit(2)
