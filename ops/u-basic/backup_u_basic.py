#!/usr/bin/env python3
"""Cervesur U-BASIC P2: private MySQL snapshot. Never enables user writes."""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

BUSINESS_DB = "cervesur_portalbi"
STATE_DB = "cervesur_react_estado_admin"
TABLES = ("user", "empresa", "reporte", "empresa_usuario",
          "empresa_reporte", "user_empresa_reporte", "audit_log")
ENV_FILE = Path("/opt/cervesur-react-admin/backend/.env")
BACKUP_ROOT = Path("/var/backups/cervesur-react-admin/u-basic")
LOCK_FILE = Path("/run/lock/cervesur-u-basic-p2.lock")
CONFIRM = "CERVESUR_P2_RESPALDO_USUARIOS"
MIN_RESERVE = 256 * 1024 * 1024

class Blocked(Exception):
    pass

def sha_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()

def guarded_file(path: Path) -> bytes:
    if path.is_symlink() or not path.is_file():
        raise Blocked("RUTA_INVALIDA_" + path.name)
    return path.read_bytes()

def read_public_flags(data: bytes) -> dict[str, str]:
    allowed = {"CERVESUR_MODE", "ADMIN_WRITES_USUARIOS", "ADMIN_WRITES_USUARIOS_SENSIBLE"}
    found = {}
    for line in data.decode("utf-8").splitlines():
        if not line.strip() or line.lstrip().startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key in allowed:
            if key in found:
                raise Blocked("FLAG_DUPLICADA_" + key)
            found[key] = value.strip().strip("'\"")
    if found.get("CERVESUR_MODE") != "admin_escritura":
        raise Blocked("MODO_ADMIN_NO_ESPERADO")
    if found.get("ADMIN_WRITES_USUARIOS") == "1":
        raise Blocked("USUARIOS_YA_ACTIVOS")
    if found.get("ADMIN_WRITES_USUARIOS_SENSIBLE") == "1":
        raise Blocked("SENSIBLES_ACTIVOS")
    return found

def mysql_query(sql: str) -> str:
    p = subprocess.run(
        ["mysql", "--protocol=socket", "-uroot", "--batch",
         "--skip-column-names", "--execute", sql],
        capture_output=True, check=False, timeout=45,
    )
    if p.returncode:
        raise Blocked("MYSQL_CONSULTA_NO_DISPONIBLE")
    return p.stdout.decode("utf-8").strip()

def db_exists(name: str) -> bool:
    return mysql_query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + name + "'") == "1"

def table_exists(name: str) -> bool:
    return mysql_query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='" +
        BUSINESS_DB + "' AND TABLE_NAME='" + name + "' AND TABLE_TYPE='BASE TABLE'"
    ) == "1"

def table_counts() -> dict[str, int]:
    counts = {}
    q = chr(96)
    for name in TABLES:
        value = mysql_query("SELECT COUNT(*) FROM " + q + BUSINESS_DB + q + "." + q + name + q)
        if not value.isdigit():
            raise Blocked("CONTEO_INVALIDO_" + name)
        counts[name] = int(value)
    return counts

def table_checksums() -> dict[str, int]:
    checksums = {}
    q = chr(96)
    for name in TABLES:
        output = mysql_query("CHECKSUM TABLE " + q + BUSINESS_DB + q + "." + q + name + q)
        cells = output.split("\t")
        if len(cells) != 2 or not cells[1].isdigit():
            raise Blocked("CHECKSUM_TABLE_NO_DISPONIBLE_" + name)
        checksums[name] = int(cells[1])
    return checksums

def preflight() -> dict[str, int]:
    read_public_flags(guarded_file(ENV_FILE))
    if not shutil.which("mysql") or not shutil.which("mysqldump"):
        raise Blocked("CLIENTES_MYSQL_NO_DISPONIBLES")
    if not db_exists(BUSINESS_DB) or not db_exists(STATE_DB):
        raise Blocked("BASE_NEGOCIO_O_ESTADO_AUSENTE")
    for name in TABLES:
        if not table_exists(name):
            raise Blocked("TABLA_AUSENTE_" + name)
    counts = table_counts()
    if counts["user"] == 0:
        raise Blocked("TABLA_USUARIOS_VACIA")
    parent = BACKUP_ROOT.parent
    if parent.is_symlink() or not parent.is_dir():
        raise Blocked("BASE_RESPALDOS_INVALIDA")
    capacity = mysql_query(
        "SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM "
        "information_schema.TABLES WHERE TABLE_SCHEMA IN ('" + BUSINESS_DB +
        "','" + STATE_DB + "')"
    )
    if not capacity.isdigit():
        raise Blocked("ESTIMACION_DISCO_NO_DISPONIBLE")
    if shutil.disk_usage(parent).free < MIN_RESERVE + 2 * int(capacity):
        raise Blocked("DISCO_INSUFICIENTE")
    return counts

def dump_cmd(db: str, tables: tuple[str, ...] | None) -> list[str]:
    cmd = [
        "mysqldump", "--protocol=socket", "-uroot", "--single-transaction",
        "--quick", "--hex-blob", "--set-gtid-purged=OFF",
        "--no-tablespaces", "--default-character-set=utf8mb4",
        "--triggers", "--routines=false", "--events=false",
    ]
    return cmd + (["--databases", db] if tables is None else [db, *tables])

def dump_gzip(dest: Path, command: list[str]) -> None:
    if dest.exists() or dest.is_symlink():
        raise Blocked("RESPALDO_DESTINO_EXISTENTE")
    with dest.open("xb") as output:
        os.chmod(dest, 0o600)
        process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        assert process.stdout is not None
        with gzip.GzipFile(fileobj=output, mode="wb", mtime=0) as zipped:
            for chunk in iter(lambda: process.stdout.read(1024 * 1024), b""):
                zipped.write(chunk)
        process.stdout.close()
        if process.wait(timeout=300) != 0:
            raise Blocked("MYSQLDUMP_NO_GO")

def verified_archive(path: Path) -> None:
    if path.is_symlink() or not path.is_file() or path.stat().st_size == 0:
        raise Blocked("ARCHIVO_RESPALDO_INVALIDO")
    tail = b""
    try:
        with gzip.open(path, "rb") as f:
            while chunk := f.read(1024 * 1024):
                tail = (tail + chunk)[-4096:]
    except (EOFError, OSError) as exc:
        raise Blocked("RESPALDO_GZIP_CORRUPTO") from exc
    if b"-- Dump completed" not in tail:
        raise Blocked("VOLCADO_SQL_INCOMPLETO")

def safe_root() -> None:
    if BACKUP_ROOT.is_symlink():
        raise Blocked("RESPALDO_RAIZ_SYMLINK")
    BACKUP_ROOT.mkdir(mode=0o700, parents=False, exist_ok=True)
    if BACKUP_ROOT.stat().st_uid != 0:
        raise Blocked("RESPALDO_RAIZ_NO_ROOT")
    BACKUP_ROOT.chmod(0o700)

def verify_backup(folder: Path) -> None:
    if folder.is_symlink() or folder.parent.resolve() != BACKUP_ROOT.resolve() or not folder.name.startswith("u-basic-"):
        raise Blocked("BACKUP_RUTA_INVALIDA")
    meta = json.loads(guarded_file(folder / "manifest.json"))
    if meta.get("format") != "cervesur-u-basic-p2-v1" or meta.get("restoration_tested") is not False:
        raise Blocked("BACKUP_MANIFEST_INVALIDO")
    names = {"negocio7.sql.gz", "estado-admin.sql.gz"}
    if set(meta.get("archives_sha256", {})) != names:
        raise Blocked("BACKUP_ARCHIVOS_INCOMPLETOS")
    for name in names:
        f = folder / name
        if f.is_symlink() or f.stat().st_mode & 0o077:
            raise Blocked("BACKUP_PERMISOS_INVALIDOS")
        if sha_file(f) != meta["archives_sha256"][name]:
            raise Blocked("BACKUP_SHA_DISTINTO")
        verified_archive(f)
    if folder.stat().st_mode & 0o077:
        raise Blocked("BACKUP_CARPETA_NO_PRIVADA")

def make_backup() -> Path:
    before = preflight()
    before_checksums = table_checksums()
    safe_root()
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    folder = Path(tempfile.mkdtemp(prefix="u-basic-" + stamp + "-", dir=BACKUP_ROOT))
    folder.chmod(0o700)
    print("RESPALDO_INICIADO=SI", flush=True)
    try:
        business = folder / "negocio7.sql.gz"
        state = folder / "estado-admin.sql.gz"
        dump_gzip(business, dump_cmd(BUSINESS_DB, TABLES))
        verified_archive(business)
        dump_gzip(state, dump_cmd(STATE_DB, None))
        verified_archive(state)
        after = table_counts()
        after_checksums = table_checksums()
        if before != after or before_checksums != after_checksums:
            raise Blocked("TABLAS_CAMBIARON_DURANTE_RESPALDO")
        manifest = {
            "format": "cervesur-u-basic-p2-v1",
            "created_utc": datetime.now(timezone.utc).isoformat(),
            "business_db": BUSINESS_DB,
            "state_db": STATE_DB,
            "business_tables": list(TABLES),
            "rows": before,
            "checksums": before_checksums,
            "archives_sha256": {
                business.name: sha_file(business),
                state.name: sha_file(state),
            },
            "restoration_tested": False,
            "users_write_flag": "OFF",
            "sensitive_write_flag": "OFF",
        }
        output = folder / "manifest.json"
        with output.open("x", encoding="utf-8") as f:
            os.chmod(output, 0o600)
            json.dump(manifest, f, indent=2, sort_keys=True)
        verify_backup(folder)
    except BaseException:
        print("RESPALDO_NO_GO=CARPETA_PRIVADA_INCOMPLETA", file=sys.stderr)
        raise
    return folder

def run() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("action", choices=["preflight", "backup", "verify"])
    p.add_argument("--confirm", default="")
    p.add_argument("--path", default="")
    a = p.parse_args()
    if os.geteuid() != 0:
        raise Blocked("REQUIERE_SUDO")
    os.umask(0o077)
    with LOCK_FILE.open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if a.action == "verify":
            if not a.path:
                raise Blocked("BACKUP_RUTA_REQUERIDA")
            verify_backup(Path(a.path))
            print("BACKUP_INTEGRIDAD=PASS")
            print("BACKUP_RESTAURACION_ENSAYADA=NO")
            return 0
        before = preflight()
        print("BASE_NEGOCIO=PASS")
        print("BASE_ESTADO_ADMIN=PASS")
        print("TABLAS_RESPALDO=7")
        print("USUARIOS_CUENTA=" + str(before["user"]))
        print("USUARIOS_ESCRITURA=OFF")
        print("SENSIBLES_ESCRITURA=OFF")
        if a.action == "preflight":
            print("P2_PREFLIGHT=PASS_SOLO_LECTURA")
            print("MYSQL_MODIFICADO=NO")
            return 0
        if a.confirm != CONFIRM:
            raise Blocked("AUTORIZACION_RESPALDO_FALTANTE")
        folder = make_backup()
        print("BACKUP_PATH=" + str(folder))
        print("BACKUP_INTEGRIDAD=PASS")
        print("BACKUP_RESTAURACION_ENSAYADA=NO")
        print("MYSQL_MODIFICADO=NO")
        print("ACTIVACION_U_BASIC=NO_REALIZADA")
        return 0

if __name__ == "__main__":
    try:
        sys.exit(run())
    except (Blocked, OSError, ValueError, RuntimeError, subprocess.SubprocessError) as exc:
        print("P2_NO_GO=" + str(exc), file=sys.stderr)
        sys.exit(2)
