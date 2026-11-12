#!/usr/bin/env python3
"""Cervesur P2 -- restore the already verified backup into a SECOND local mysqld.

This does not touch the production server's datadir, socket, port, service or
configuration. Every mysql and mysqladmin command has --no-defaults and the
explicit QA-only socket. The temporary mysqld has --no-defaults, --skip-networking,
--mysqlx=OFF and separate private datadir/socket; it is stopped and removed
before a private success receipt is written. No data gets printed.

This script is exclusively for preflight and restore; it never enables U-BASIC.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timezone
import fcntl
import gzip
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
import time
import uuid

import backup_u_basic as backup
import restore_verify as restore

APPROVAL = "CERVESUR_AUTORIZO_P2_MYSQL_NATIVO_AISLADO"
LOCK = Path("/run/lock/cervesur-p2-native-restore.lock")
TMP_PARENT = Path("/tmp")
RECEIPT = "restore-native-receipt.json"
MIN_MEMORY_BYTES = 3 * 1024**3
MIN_FREE_BYTES = 2 * 1024**3
MAX_RUNTIME_SECONDS = 900

class NoGo(Exception):
    pass

def execute(argv: list[str], timeout: int = 30) -> str:
    """No stderr, SQL content or credentials in the operator's output."""
    try:
        p = subprocess.run(argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                           check=False, timeout=timeout)
    except (FileNotFoundError, subprocess.TimeoutExpired) as exc:
        raise NoGo("COMANDO_NO_DISPONIBLE_O_TIMEOUT") from exc
    if p.returncode:
        raise NoGo("COMANDO_NO_PASS")
    return p.stdout.decode("utf-8", errors="replace").strip()

def available_memory() -> int:
    try:
        with open("/proc/meminfo", encoding="ascii") as stream:
            for line in stream:
                if line.startswith("MemAvailable:"):
                    return int(line.split()[1]) * 1024
    except (OSError, ValueError, IndexError):
        pass
    raise NoGo("MEMORIA_DISPONIBLE_NO_MEDIBLE")

def mysql_version() -> str:
    if not shutil.which("mysql") or not shutil.which("mysqld"):
        raise NoGo("MYSQL_BINARIOS_NO_ENCONTRADOS")
    version = execute(["mysqld", "--no-defaults", "--version"], 15)
    if "mariadb" in version.lower():
        raise NoGo("MYSQLD_VERSION_MARIADB_NO_APROBADA")
    match = re.search(r"Ver (8\.(?:0|4)\.\d+)", version)
    if not match:
        raise NoGo("MYSQLD_VERSION_FUERA_DE_8_0_8_4")
    return match.group(1)

def uncompressed_bytes(archive: Path) -> int:
    if archive.is_symlink() or not archive.is_file():
        raise NoGo("ARCHIVO_RESPALDO_INVALIDO")
    count = 0
    try:
        with gzip.open(archive, "rb") as stream:
            while chunk := stream.read(1024 * 1024):
                count += len(chunk)
    except (EOFError, OSError) as exc:
        raise NoGo("GZIP_NO_PASS") from exc
    return count

def preflight(folder: Path) -> tuple[dict, str]:
    if os.geteuid() != 0:
        raise NoGo("REQUIERE_SUDO")
    backup.read_public_flags(backup.guarded_file(backup.ENV_FILE))
    manifest = restore.validate_manifest(folder)
    if (folder / RECEIPT).exists() or (folder / RECEIPT).is_symlink():
        raise NoGo("RECIBO_NATIVO_YA_EXISTE")
    if not TMP_PARENT.is_dir() or TMP_PARENT.is_symlink():
        raise NoGo("DIRECTORIO_TEMPORAL_INVALIDO")
    try:
        mysql_account = pwd.getpwnam("mysql")
    except KeyError as exc:
        raise NoGo("USUARIO_OS_MYSQL_AUSENTE") from exc
    if mysql_account.pw_uid == 0 or mysql_account.pw_gid == 0:
        raise NoGo("USUARIO_OS_MYSQL_NO_AISLADO")
    version = mysql_version()
    if available_memory() < MIN_MEMORY_BYTES:
        raise NoGo("MEMORIA_NO_SUFICIENTE_3G")
    sql_size = sum(uncompressed_bytes(folder / name)
                   for name in ("negocio7.sql.gz", "estado-admin.sql.gz"))
    required_disk = max(MIN_FREE_BYTES, sql_size * 3 + 512 * 1024**2)
    if shutil.disk_usage(TMP_PARENT).free < required_disk:
        raise NoGo("DISCO_NO_SUFICIENTE_PARA_AISLADO")
    return manifest, version

def client(socket: Path, sql: str, timeout: int = 60) -> str:
    if not socket.is_absolute() or not socket.name == "mysql.sock" or "cervesur-p2-qa-" not in str(socket):
        raise NoGo("SOCKET_NO_AISLADO")
    return execute(
        ["mysql", "--no-defaults", "--protocol=socket", "--socket=" + str(socket),
         "-uroot", "--batch", "--skip-column-names", "--execute", sql],
        timeout,
    )

def initialize(qa_root: Path) -> None:
    path = qa_root / "data"
    tmp = qa_root / "tmp"
    argv = [
        "mysqld", "--no-defaults", "--initialize-insecure", "--user=mysql",
        "--datadir=" + str(path), "--tmpdir=" + str(tmp),
        "--log-error=" + str(qa_root / "error.log"),
    ]
    execute(argv, 180)

def daemon_args(qa_root: Path) -> list[str]:
    return [
        "mysqld", "--no-defaults", "--user=mysql",
        "--datadir=" + str(qa_root / "data"),
        "--socket=" + str(qa_root / "mysql.sock"),
        "--pid-file=" + str(qa_root / "mysql.pid"),
        "--log-error=" + str(qa_root / "error.log"),
        "--tmpdir=" + str(qa_root / "tmp"),
        "--skip-networking", "--mysqlx=OFF", "--skip-log-bin",
        "--innodb-buffer-pool-size=128M", "--max-connections=10",
        "--performance-schema=OFF", "--local-infile=OFF",
    ]

def start_daemon(qa_root: Path) -> subprocess.Popen:
    with open(os.devnull, "wb") as silent:
        process = subprocess.Popen(
            daemon_args(qa_root), stdin=subprocess.DEVNULL,
            stdout=silent, stderr=silent, start_new_session=True,
        )
    socket = qa_root / "mysql.sock"
    try:
        deadline = time.monotonic() + 90
        while time.monotonic() < deadline:
            if process.poll() is not None:
                raise NoGo("MYSQL_AISLADO_NO_INICIO")
            if socket.exists():
                try:
                    if client(socket, "SELECT 1", timeout=5) == "1":
                        return process
                except NoGo:
                    pass
            time.sleep(1)
        raise NoGo("MYSQL_AISLADO_TIEMPO_ESPERA")
    except BaseException:
        # Caller does not own process until this function returns. Never
        # discard a live child and remove its datadir during startup failure.
        try:
            shutdown_daemon(process, qa_root)
        except BaseException as exc:
            raise NoGo("MYSQL_AISLADO_ARRANQUE_NO_PUDO_DETENERSE") from exc
        raise

def import_dump(socket: Path, archive: Path, target_database: str | None) -> None:
    cmd = ["mysql", "--no-defaults", "--protocol=socket",
           "--socket=" + str(socket), "-uroot"]
    if target_database:
        cmd.append(target_database)
    try:
        process = subprocess.Popen(
            cmd, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except FileNotFoundError as exc:
        raise NoGo("CLIENTE_MYSQL_NO_ENCONTRADO") from exc
    try:
        assert process.stdin is not None
        with gzip.open(archive, "rb") as inp:
            for chunk in iter(lambda: inp.read(256 * 1024), b""):
                process.stdin.write(chunk)
        process.stdin.close()
        if process.wait(timeout=MAX_RUNTIME_SECONDS) != 0:
            raise NoGo("IMPORTACION_SQL_AISLADA_FALLO")
    except BaseException:
        if process.poll() is None:
            process.kill()
        process.wait()
        raise

def as_number(text: str, marker: str) -> int:
    if not text.isdigit():
        raise NoGo("NUMERO_INVALIDO_" + marker)
    return int(text)

def verify_restored(socket: Path, manifest: dict) -> tuple[int, int]:
    q = chr(96)
    db = backup.BUSINESS_DB
    for table in backup.TABLES:
        source = q + db + q + "." + q + table + q
        count = as_number(client(socket, "SELECT COUNT(*) FROM " + source), table)
        if count != manifest["rows"][table]:
            raise NoGo("RESTAURADO_FILAS_DISTINTAS_" + table)
        raw = client(socket, "CHECKSUM TABLE " + source).split("\t")
        if len(raw) != 2 or as_number(raw[1], table + "_checksum") != manifest["checksums"][table]:
            raise NoGo("RESTAURADO_CHECKSUM_DISTINTO_" + table)
    triggers = as_number(client(
        socket, "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE "
                "TRIGGER_SCHEMA='" + db + "' AND EVENT_OBJECT_TABLE='audit_log'"
    ), "audit_triggers")
    if triggers < 2:
        raise NoGo("RESTAURADO_AUDIT_TRIGGERS_INCOMPLETO")
    state_tables = as_number(client(
        socket, "SELECT COUNT(*) FROM information_schema.TABLES "
                "WHERE TABLE_SCHEMA='" + backup.STATE_DB + "' AND TABLE_TYPE='BASE TABLE'"
    ), "state_tables")
    if state_tables < 1:
        raise NoGo("RESTAURADO_ESTADO_ADMIN_INCOMPLETO")
    return triggers, state_tables

def private_tmp(mysql_uid: int, mysql_gid: int) -> Path:
    root = Path(tempfile.mkdtemp(prefix="cervesur-p2-qa-", dir=TMP_PARENT))
    os.chown(root, mysql_uid, mysql_gid)
    root.chmod(0o700)
    for name in ("data", "tmp"):
        sub = root / name
        sub.mkdir(mode=0o700)
        os.chown(sub, mysql_uid, mysql_gid)
    return root

def shutdown_daemon(process: subprocess.Popen | None, qa_root: Path) -> None:
    if process is None:
        return
    if process.poll() is None:
        socket = qa_root / "mysql.sock"
        if socket.exists():
            try:
                execute(["mysqladmin", "--no-defaults", "--protocol=socket",
                         "--socket=" + str(socket), "-uroot", "shutdown"], timeout=30)
            except NoGo:
                pass
        try:
            process.wait(timeout=35)
        except subprocess.TimeoutExpired:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=10)
    if process.poll() is None:
        raise NoGo("MYSQL_AISLADO_NO_SE_PUDO_DETENER")

def cleanup_qa(root: Path, process: subprocess.Popen | None) -> None:
    shutdown_daemon(process, root)
    # Only delete the exact unpredictable QA path allocated by this process.
    if root.parent != TMP_PARENT or not root.name.startswith("cervesur-p2-qa-"):
        raise NoGo("RUTA_LIMPIEZA_NO_AISLADA")
    shutil.rmtree(root)
    if root.exists():
        raise NoGo("QA_TEMPORAL_NO_ELIMINADO")
    print("MYSQL_AISLADO_ELIMINADO=SI")

def run_restore(folder: Path, manifest: dict, version: str) -> dict:
    acct = pwd.getpwnam("mysql")
    qa_root = private_tmp(acct.pw_uid, acct.pw_gid)
    process = None
    error: BaseException | None = None
    result = None
    try:
        initialize(qa_root)
        process = start_daemon(qa_root)
        socket = qa_root / "mysql.sock"
        client(socket, "CREATE DATABASE " + chr(96) + backup.BUSINESS_DB +
               chr(96) + " CHARACTER SET utf8mb4")
        import_dump(socket, folder / "negocio7.sql.gz", backup.BUSINESS_DB)
        import_dump(socket, folder / "estado-admin.sql.gz", None)
        triggers, state_tables = verify_restored(socket, manifest)
        result = {
            "kind": "cervesur-p2-native-isolated-restore-v1",
            "completed_utc": datetime.now(timezone.utc).isoformat(),
            "source_manifest_sha256": backup.sha_file(folder / "manifest.json"),
            "source_archive_sha256": manifest["archives_sha256"],
            "mysqld_version": version,
            "isolation": "second mysqld, no defaults, distinct socket and datadir, no TCP/MySQLX",
            "tables_verified": len(backup.TABLES),
            "rows_verified": manifest["rows"],
            "checksums_equal": True,
            "audit_triggers_restored": triggers,
            "admin_state_tables_restored": state_tables,
            "restoration_tested": True,
            "production_mysql_modified_by_script": False,
            "u_basic_activated": False,
        }
    except BaseException as exc:
        error = exc
    try:
        cleanup_qa(qa_root, process)
    except BaseException as exc:
        raise NoGo("LIMPIEZA_AISLADO_NO_GO") from exc
    if error is not None:
        raise error
    assert result is not None
    return result

def save_receipt(folder: Path, receipt: dict) -> None:
    path = folder / RECEIPT
    if path.exists() or path.is_symlink():
        raise NoGo("RECIBO_YA_EXISTE")
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as out:
        json.dump(receipt, out, sort_keys=True, indent=2)
        out.flush()
        os.fsync(out.fileno())

def run() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=["preflight", "run"])
    parser.add_argument("--path", required=True)
    parser.add_argument("--confirm", default="")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise NoGo("REQUIERE_SUDO")
    os.umask(0o077)
    folder = Path(args.path)
    with LOCK.open("w") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        manifest, version = preflight(folder)
        print("BACKUP_INTEGRIDAD=PASS")
        print("MY_SQL_VERSION=" + version)
        print("MYSQL_NATIVO=DISPONIBLE")
        print("MEMORIA_DISCO=PASS")
        print("USUARIOS_ESCRITURA=OFF")
        print("SENSIBLES_ESCRITURA=OFF")
        if args.action == "preflight":
            print("P2_NATIVE_PREFLIGHT=PASS")
            print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
            return 0
        if args.confirm != APPROVAL:
            raise NoGo("FALTA_AUTORIZACION_ENSAYO")
        print("MYSQL_NATIVO_AISLADO_INICIO=SI", flush=True)
        receipt = run_restore(folder, manifest, version)
        save_receipt(folder, receipt)
        print("P2_RESTAURACION_NATIVA=PASS")
        print("TABLAS_VERIFICADAS=7")
        print("AUDIT_TRIGGERS=" + str(receipt["audit_triggers_restored"]))
        print("ESTADO_ADMIN_TABLAS=" + str(receipt["admin_state_tables_restored"]))
        print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
        print("U_BASIC_ACTIVADO=NO")
        print("RECIBO_PRIVADO=" + str(folder / RECEIPT))
        return 0

if __name__ == "__main__":
    try:
        sys.exit(run())
    except (NoGo, backup.Blocked, restore.NoGo, OSError, ValueError,
            RuntimeError, subprocess.SubprocessError, json.JSONDecodeError) as exc:
        print("P2_NATIVE_NO_GO=" + str(exc), file=sys.stderr)
        sys.exit(2)
