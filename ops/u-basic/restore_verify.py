#!/usr/bin/env python3
"""Cervesur P2: verify restoring an existing backup in an isolated MySQL container.

Never connects to production MySQL, writes production databases, starts a service
on the host, publishes ports or mounts host application/database files.
The two SQL archives are streamed into a disposable MySQL server in Docker.
The original backup files and manifest are never modified; an isolated QA
receipt (no account data) is written only after every comparison succeeds.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import fcntl
import gzip
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import uuid

import backup_u_basic as backup

IMAGE = "mysql:8.0"
APPROVAL = "CERVESUR_AUTORIZO_RESTAURACION_AISLADA_P2"
LOCK = Path("/run/lock/cervesur-u-basic-restore.lock")
RECEIPT = "restore-isolated-receipt.json"

class NoGo(Exception):
    pass


def exec_sanitized(args: list[str], timeout: int = 40) -> str:
    """Do not emit stdout or stderr unless known to contain public status."""
    try:
        result = subprocess.run(
            args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            check=False, timeout=timeout,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired) as exc:
        raise NoGo("COMANDO_NO_DISPONIBLE_O_TIMEOUT") from exc
    if result.returncode:
        raise NoGo("COMANDO_NO_PASS")
    return result.stdout.decode("utf-8", errors="replace").strip()


def docker(*params: str, timeout: int = 40) -> str:
    return exec_sanitized(["docker", *params], timeout=timeout)


def local_image_id() -> str:
    if not shutil.which("docker"):
        raise NoGo("DOCKER_NO_DISPONIBLE")
    docker("info", "--format", "{{.ServerVersion}}", timeout=20)
    image = docker("image", "inspect", IMAGE, "--format", "{{.Id}}")
    if not re.fullmatch(r"sha256:[a-f0-9]{64}", image):
        raise NoGo("IMAGEN_MYSQL_LOCAL_INVALIDA")
    return image


def validate_manifest(folder: Path) -> dict:
    if folder.is_symlink():
        raise NoGo("BACKUP_RUTA_SYMLINK")
    backup.verify_backup(folder)
    meta = json.loads(backup.guarded_file(folder / "manifest.json"))
    if (meta.get("business_db") != backup.BUSINESS_DB or
            meta.get("state_db") != backup.STATE_DB or
            meta.get("business_tables") != list(backup.TABLES)):
        raise NoGo("BACKUP_ESQUEMA_DISTINTO")
    for field in ("rows", "checksums"):
        values = meta.get(field)
        if not isinstance(values, dict) or set(values) != set(backup.TABLES):
            raise NoGo("BACKUP_" + field.upper() + "_INCOMPLETO")
        if any(type(n) is not int or n < 0 for n in values.values()):
            raise NoGo("BACKUP_" + field.upper() + "_INVALIDO")
    if meta["rows"]["user"] <= 0:
        raise NoGo("RESPALDO_USUARIOS_VACIO")
    return meta


def preflight(folder: Path) -> tuple[dict, str]:
    if os.geteuid() != 0:
        raise NoGo("REQUIERE_SUDO")
    # This file is read without printing any secret or other environment keys.
    backup.read_public_flags(backup.guarded_file(backup.ENV_FILE))
    meta = validate_manifest(folder)
    if (folder / RECEIPT).exists():
        raise NoGo("ENSAYO_YA_REGISTRADO_NO_SOBRESCRIBIR")
    image_id = local_image_id()
    return meta, image_id


def mysql(container: str, sql: str) -> str:
    return docker("exec", container, "mysql", "--protocol=socket", "-uroot",
                  "--batch", "--skip-column-names", "--execute", sql, timeout=90)


def await_mysql(container: str) -> None:
    for attempt in range(90):
        try:
            if mysql(container, "SELECT 1") == "1":
                return
        except NoGo:
            pass
        time.sleep(2)
    raise NoGo("MYSQL_AISLADO_NO_INICIA")


def import_archive(container: str, gz_file: Path, db: str | None) -> None:
    """Send expanded SQL only to docker exec stdin; never write a host SQL file."""
    argv = ["docker", "exec", "-i", container,
            "mysql", "--protocol=socket", "-uroot"]
    if db:
        argv.append(db)
    try:
        proc = subprocess.Popen(
            argv, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
    except FileNotFoundError as exc:
        raise NoGo("DOCKER_CLIENTE_NO_DISPONIBLE") from exc
    try:
        assert proc.stdin is not None
        with gzip.open(gz_file, "rb") as archive:
            for chunk in iter(lambda: archive.read(256 * 1024), b""):
                proc.stdin.write(chunk)
        proc.stdin.close()
        if proc.wait(timeout=900) != 0:
            raise NoGo("IMPORT_SQL_AISLADA_FALLO")
    except BaseException:
        if proc.poll() is None:
            proc.kill()
        proc.wait()
        raise


def read_integer(raw: str, label: str) -> int:
    if not raw.isdigit():
        raise NoGo("CONTEO_INVALIDO_" + label)
    return int(raw)


def check_tables(container: str, meta: dict) -> tuple[int, int]:
    ident = chr(96)
    db = meta["business_db"]
    for table in backup.TABLES:
        actual = read_integer(mysql(
            container, "SELECT COUNT(*) FROM " + ident + db + ident +
            "." + ident + table + ident,
        ), table)
        if actual != meta["rows"][table]:
            raise NoGo("FILAS_RESTAURADAS_NO_COINCIDEN_" + table)
        result = mysql(
            container, "CHECKSUM TABLE " + ident + db + ident +
            "." + ident + table + ident
        )
        pieces = result.split("\t")
        if len(pieces) != 2:
            raise NoGo("CHECKSUM_RESTAURACION_INVALIDO_" + table)
        checksum = read_integer(pieces[1], "checksum-" + table)
        if checksum != meta["checksums"][table]:
            raise NoGo("CHECKSUM_RESTAURACION_DISTINTO_" + table)

    triggers = read_integer(mysql(
        container, "SELECT COUNT(*) FROM information_schema.TRIGGERS "
        "WHERE TRIGGER_SCHEMA='" + db + "' "
        "AND EVENT_OBJECT_TABLE='audit_log'"
    ), "audit_triggers")
    if triggers < 2:
        raise NoGo("AUDIT_LOG_TRIGGERS_INCOMPLETOS")
    state = meta["state_db"]
    state_tables = read_integer(mysql(
        container, "SELECT COUNT(*) FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA='" + state + "' AND TABLE_TYPE='BASE TABLE'"
    ), "state_tables")
    if state_tables < 1:
        raise NoGo("ESTADO_ADMIN_RESTAURADO_SIN_TABLAS")
    return triggers, state_tables


def receipt_for(meta: dict, image_id: str,
                triggers: int, state_tables: int) -> dict:
    return {
        "kind": "cervesur-u-basic-p2-restore-isolated-v1",
        "completed_utc": datetime.now(timezone.utc).isoformat(),
        "source_manifest_sha256": "not-included",
        "image_sha256": image_id,
        "network": "none",
        "published_ports": 0,
        "host_database_mounts": 0,
        "business_tables_checked": len(backup.TABLES),
        "business_rows_checked": meta["rows"],
        "source_archive_sha256": meta["archives_sha256"],
        "audit_triggers_restored": triggers,
        "admin_state_tables_restored": state_tables,
        "all_business_checksums_equal": True,
        "restoration_tested": True,
        "production_mysql_modified": False,
        "u_basic_activated": False,
    }


def save_receipt(folder: Path, receipt: dict) -> None:
    if (folder / RECEIPT).is_symlink() or (folder / RECEIPT).exists():
        raise NoGo("RECIBO_EXISTENTE_NO_SOBRESCRIBIR")
    # Create only after the disposable server is removed successfully.
    dest = folder / RECEIPT
    flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY
    fd = os.open(dest, flags, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as output:
        json.dump(receipt, output, sort_keys=True, indent=2)
        output.flush()
        os.fsync(output.fileno())


def restore_isolated(folder: Path, meta: dict, image_id: str) -> dict:
    container = "cervesur-p2-qa-" + uuid.uuid4().hex[:12]
    started = False
    done = False
    try:
        docker(
            "run", "--detach", "--rm", "--name", container,
            "--network", "none", "--log-driver", "none",
            "--security-opt", "no-new-privileges",
            "--cap-drop", "NET_RAW",
            "--memory", "2g", "--pids-limit", "256",
            "--tmpfs", "/var/lib/mysql:rw,nosuid,nodev,size=1536m",
            "--tmpfs", "/var/run/mysqld:rw,nosuid,nodev,size=64m",
            "--tmpfs", "/tmp:rw,nosuid,nodev,size=128m",
            "--env", "MYSQL_ALLOW_EMPTY_PASSWORD=yes",
            image_id, timeout=90,
        )
        started = True
        if docker("inspect", container, "--format", "{{.HostConfig.NetworkMode}}") != "none":
            raise NoGo("CONTENEDOR_SIN_AISLAMIENTO_RED")
        await_mysql(container)
        mysql(container, "CREATE DATABASE " + chr(96) + meta["business_db"] +
              chr(96) + " CHARACTER SET utf8mb4")
        import_archive(container, folder / "negocio7.sql.gz", meta["business_db"])
        import_archive(container, folder / "estado-admin.sql.gz", None)
        triggers, state_tables = check_tables(container, meta)
        done = True
        return receipt_for(meta, image_id, triggers, state_tables)
    finally:
        if started:
            try:
                docker("rm", "--force", container, timeout=40)
                print("MYSQL_CONTENEDOR_AISLADO_ELIMINADO=SI")
            except NoGo as exc:
                # If Docker cleanup fails, do not claim success/leave a receipt.
                raise NoGo("CONTENEDOR_NO_PUDO_ELIMINARSE") from exc


def run() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("preflight", "run"))
    parser.add_argument("--path", required=True)
    parser.add_argument("--confirm", default="")
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise NoGo("REQUIERE_SUDO")
    folder = Path(args.path)
    os.umask(0o077)
    with LOCK.open("w") as f:
        fcntl.flock(f, fcntl.LOCK_EX)
        meta, image_id = preflight(folder)
        print("RESPALDO_INTEGRIDAD=PASS")
        print("BACKUP_RESTAURACION_PREVIA=NO")
        print("MYSQL_IMAGE_LOCAL=PASS")
        print("USUARIOS_ESCRITURA=OFF")
        print("SENSIBLES_ESCRITURA=OFF")
        if args.action == "preflight":
            print("P2_RESTORE_PREFLIGHT=PASS")
            print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
            return 0
        if args.confirm != APPROVAL:
            raise NoGo("CONFIRMACION_DE_ENSAYO_FALTANTE")
        print("MYSQL_AISLADO_RED=NONE")
        receipt = restore_isolated(folder, meta, image_id)
        save_receipt(folder, receipt)
        print("P2_RESTAURACION_AISLADA=PASS")
        print("TABLAS_NEGOCIO_VERIFICADAS=7")
        print("AUDIT_TRIGGERS_RESTAURADOS=" + str(receipt["audit_triggers_restored"]))
        print("ESTADO_ADMIN_TABLAS_RESTAURADAS=" + str(receipt["admin_state_tables_restored"]))
        print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
        print("ACTIVACION_U_BASIC=NO_REALIZADA")
        print("RECIBO_PRIVADO=" + str(folder / RECEIPT))
        return 0


if __name__ == "__main__":
    try:
        sys.exit(run())
    except (NoGo, backup.Blocked, OSError, ValueError, json.JSONDecodeError,
            subprocess.SubprocessError) as exc:
        print("P2_RESTORE_NO_GO=" + str(exc), file=sys.stderr)
        sys.exit(2)
