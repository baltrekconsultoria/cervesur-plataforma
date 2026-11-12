#!/usr/bin/env python3
"""Cervesur legacy release: fail-closed K1 and approved 24h JWT TTL.

Only touches:
  /var/www/cervesur/backend/.env
  /var/www/cervesur/backend/lib/powerbi_authz.php

F10/auth.php and H1 are SHA-pinned and never changed. Root-owned backups
are created before modifications. No JWT values, .env contents or secrets
are printed. All changes are reversible and checked against SHA-256.
"""
from __future__ import annotations

import argparse
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
from datetime import datetime, timezone

ROOT = Path("/var/www/cervesur/backend")
BACKUP_HOME = Path("/home/usr_cervesur")
LOCK = Path("/run/lock/cervesur-legacy-policy.lock")
AUTH_SHA = "4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366"
H1_SHA = "c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298"
FILES = (".env", "lib/powerbi_authz.php")
AUDIT_FILES = ("router/auth.php", "lib/auth_middleware.php")
FROM_TTL = "10800"
TO_TTL = "86400"
DEFAULT_OBSERVE = "define('POWERBI_AUTH_MODE', 'observar');"
DEFAULT_RETIRE = "define('POWERBI_AUTH_MODE', 'retirar');"
CONFIRM = "CERVESUR_AUTORIZO_K1_JWT_24H_CON_BACKUP"


class GateError(Exception):
    pass


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def safe_file(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise GateError("ARCHIVO_INVALIDO_" + path.name)
    return path.read_bytes()


def ensure_identity(root: Path) -> None:
    if digest(safe_file(root / "router/auth.php")) != AUTH_SHA:
        raise GateError("AUTH_F10_SHA_CAMBIO")
    if digest(safe_file(root / "lib/auth_middleware.php")) != H1_SHA:
        raise GateError("H1_SHA_CAMBIO")


def ttl_candidate(data: bytes) -> tuple[bytes, str]:
    text = data.decode("utf-8")
    entries = list(re.finditer(rb"(?m)^[ \t]*(?:export[ \t]+)?JWT_EXPIRATION[ \t]*=", data))
    if len(entries) != 1:
        raise GateError("JWT_EXPIRATION_AMBIGUO_O_AUSENTE")
    pat = re.compile(r"(?m)^([ \t]*JWT_EXPIRATION[ \t]*=[ \t]*)([\"']?)([0-9]+)\2([ \t]*\r?)$")
    matches = list(pat.finditer(text))
    if len(matches) != 1:
        raise GateError("JWT_EXPIRATION_FORMATO_NO_ADMITIDO")
    old = matches[0].group(3)
    if old not in (FROM_TTL, TO_TTL):
        raise GateError("JWT_EXPIRATION_VALOR_INESPERADO")
    output = pat.sub(lambda m: m.group(1) + m.group(2) + TO_TTL + m.group(2) + m.group(4), text, count=1)
    return output.encode("utf-8"), old


def k1_candidate(data: bytes) -> tuple[bytes, str]:
    text = data.decode("utf-8")
    if "if (!defined('POWERBI_AUTH_MODE'))" not in text:
        raise GateError("K1_FALLBACK_CONDICION_NO_ENCONTRADA")
    observar = text.count(DEFAULT_OBSERVE)
    retirar = text.count(DEFAULT_RETIRE)
    if observar == 1 and retirar == 0:
        return text.replace(DEFAULT_OBSERVE, DEFAULT_RETIRE, 1).encode("utf-8"), "observar"
    if observar == 0 and retirar == 1:
        return data, "retirar"
    raise GateError("K1_FALLBACK_AMBIGUO")


def plan(root: Path) -> tuple[dict[str, bytes], dict[str, bytes], dict[str, str]]:
    ensure_identity(root)
    before = {name: safe_file(root / name) for name in FILES}
    env_after, ttl = ttl_candidate(before[".env"])
    k1_after, k1 = k1_candidate(before["lib/powerbi_authz.php"])
    after = {".env": env_after, "lib/powerbi_authz.php": k1_after}
    return before, after, {"jwt_current": ttl, "k1_default": k1}


def atomic_replace(path: Path, data: bytes) -> None:
    old = path.stat()
    fd, tmp = tempfile.mkstemp(prefix=".cervesur-policy-", dir=str(path.parent))
    try:
        os.fchmod(fd, stat.S_IMODE(old.st_mode))
        os.fchown(fd, old.st_uid, old.st_gid)
        with os.fdopen(fd, "wb") as fp:
            fp.write(data)
            fp.flush()
            os.fsync(fp.fileno())
        os.replace(tmp, path)
        directory = os.open(str(path.parent), os.O_RDONLY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def lint_php(data: bytes) -> None:
    with tempfile.TemporaryDirectory(prefix="cervesur-k1-") as folder:
        f = Path(folder) / "powerbi_authz.php"
        f.write_bytes(data)
        proc = subprocess.run(["php", "-l", str(f)], capture_output=True, check=False)
        if proc.returncode != 0:
            raise GateError("K1_PHP_LINT_FAIL")


def make_backup(root: Path, backup_home: Path, before: dict[str, bytes],
                after: dict[str, bytes]) -> Path:
    if not backup_home.is_dir() or backup_home.is_symlink():
        raise GateError("BACKUP_HOME_INVALIDO")
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = backup_home / ("cervesur-p4b-policy-" + stamp + "-" + str(os.getpid()))
    backup.mkdir(mode=0o700)
    entries = {}
    for name in FILES + AUDIT_FILES:
        raw = before[name] if name in before else safe_file(root / name)
        target = backup / name
        target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        target.write_bytes(raw)
        target.chmod(0o600)
        entries[name] = {
            "original": digest(raw),
            "candidate": digest(after[name]) if name in after else digest(raw),
        }
    manifest = {"kind": "cervesur-p4b-policy-v1", "entries": entries}
    dest = backup / "manifest.json"
    dest.write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    dest.chmod(0o600)
    return backup


def apply_to_root(root: Path, backup_home: Path) -> Path:
    before, after, info = plan(root)
    if info["jwt_current"] != FROM_TTL or info["k1_default"] != "observar":
        raise GateError("ESTADO_NO_ESPERADO_PARA_APLICAR")
    lint_php(after["lib/powerbi_authz.php"])
    backup = make_backup(root, backup_home, before, after)
    try:
        for name in FILES:
            live = root / name
            if digest(safe_file(live)) != digest(before[name]):
                raise GateError("RACE_CAMBIO_" + live.name)
            atomic_replace(live, after[name])
            if digest(safe_file(live)) != digest(after[name]):
                raise GateError("POST_SHA_" + live.name)
    except BaseException:
        for name in FILES:
            live = root / name
            if digest(safe_file(live)) == digest(after[name]):
                atomic_replace(live, before[name])
        raise
    return backup


def rollback_root(root: Path, backup_home: Path, backup: Path) -> None:
    home = backup_home.resolve()
    if backup.is_symlink() or backup.parent.resolve() != home or not backup.name.startswith("cervesur-p4b-policy-"):
        raise GateError("BACKUP_RUTA_INVALIDA")
    manifest = json.loads(safe_file(backup / "manifest.json").decode("utf-8"))
    if manifest.get("kind") != "cervesur-p4b-policy-v1":
        raise GateError("BACKUP_MANIFEST_INVALIDO")
    originals = {}
    for name in FILES:
        entry = manifest["entries"][name]
        original = safe_file(backup / name)
        if digest(original) != entry["original"]:
            raise GateError("BACKUP_ORIGINAL_SHA_INCORRECTO")
        current = digest(safe_file(root / name))
        if current != entry["candidate"]:
            raise GateError("LIVE_CAMBIO_DESDE_DEPLOY_" + Path(name).name)
        originals[name] = original
    for name in FILES:
        atomic_replace(root / name, originals[name])
        if digest(safe_file(root / name)) != manifest["entries"][name]["original"]:
            raise GateError("ROLLBACK_SHA_FALLO_" + Path(name).name)


def run() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("action", choices=("preflight", "apply", "rollback"))
    p.add_argument("--confirm", default="")
    p.add_argument("--backup", default="")
    a = p.parse_args()
    if os.geteuid() != 0:
        raise GateError("REQUIERE_SUDO")
    with LOCK.open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if a.action == "rollback":
            if a.confirm != CONFIRM:
                raise GateError("CONFIRMACION_FALTANTE")
            if not a.backup:
                raise GateError("BACKUP_NO_INDICADO")
            rollback_root(ROOT, BACKUP_HOME, Path(a.backup))
            print("ROLLBACK=PASS")
            print("REINICIO_APACHE=NO")
            return 0
        before, after, info = plan(ROOT)
        lint_php(after["lib/powerbi_authz.php"])
        print("F10_SHA=PASS")
        print("H1_SHA=PASS")
        print("JWT_ACTUAL_SEG=" + info["jwt_current"])
        print("K1_FALLBACK_ACTUAL=" + info["k1_default"])
        print("CAMBIO_JWT=" + ("SI" if before[".env"] != after[".env"] else "NO"))
        print("CAMBIO_K1=" + ("SI" if before["lib/powerbi_authz.php"] != after["lib/powerbi_authz.php"] else "NO"))
        if a.action == "preflight":
            print("PREFLIGHT=PASS_SOLO_LECTURA")
            print("PRODUCCION_MODIFICADA=NO")
            return 0
        if a.confirm != CONFIRM:
            raise GateError("CONFIRMACION_FALTANTE")
        backup = apply_to_root(ROOT, BACKUP_HOME)
        print("BACKUP_VERIFICADO=SI")
        print("BACKUP_PATH=" + str(backup))
        print("JWT_NUEVO_SEG=86400")
        print("K1_FALLBACK_NUEVO=retirar")
        print("APLICADO=SI")
        print("REINICIO_APACHE=NO")
        return 0


if __name__ == "__main__":
    try:
        sys.exit(run())
    except (GateError, OSError, ValueError, KeyError, subprocess.SubprocessError) as exc:
        print("RELEASE_NO_GO=" + type(exc).__name__ + ":" + str(exc), file=sys.stderr)
        sys.exit(2)
