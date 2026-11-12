#!/usr/bin/env python3
"""Cervesur D3-08: ejecutar SOLO el modelo REAL copiado, con datos sinteticos.

- No conecta a MySQL, no necesita SQLite, no toca backend ni DB.
- Aborta si SHA de modelo/controlador real cambia.
- Copia efimera 0700, PHP hijo sin env original, open_basedir aislado
  y usuario nobody cuando el proceso padre es root.
- Solo emite codigos de prueba, nunca fuentes ni valores.
"""
from __future__ import annotations

import argparse
import hashlib
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

MODEL_SHA = "a645a5e864e9afa0510b126d49a25e4af6ec82aff94fd49982206503cab2839d"
CONTROLLER_SHA = "f18078cca30013b1e1fe169b5fb727532da40fe929642db08f9ea4ec8e3a519b"
EXPECTED = {
    "models/EmpresaModel.php": MODEL_SHA,
    "controllers/EmpresaController.php": CONTROLLER_SHA,
}
PHPS = {
    "pagination.php": """<?php
function paginated_rows($pdo, $sql, array $params, $orderBy, $pagination) {
    if ($pagination !== null) throw new RuntimeException('D3_PAGINACION_NO_SOPORTADA');
    $stmt = $pdo->prepare($sql . ' ORDER BY ' . $orderBy);
    $stmt->execute($params);
    return [$stmt->fetchAll(PDO::FETCH_ASSOC), null];
}
""",
    "status_filter.php": """<?php
function status_sql($column, $status) {
    if ($status === 'all') return '1=1';
    if ($status === 'inactive') return $column . ' = 0';
    return $column . ' = 1';
}
""",
    "mode.php": """<?php
function app_mode() { return 'admin_escritura'; }
function app_mode_is_read_only() { return false; }
""",
    "write_support.php": """<?php
const FORM_VERSION_FIELDS = ['empresa_form' => ['nombre', 'ruc']];
""",
}


def check_sources(backend: Path) -> bytes | None:
    if not backend.is_dir() or backend.is_symlink():
        return None
    base = backend.resolve()
    model_bytes: bytes | None = None
    for rel, sha in EXPECTED.items():
        path = backend / rel
        if path.is_symlink() or not path.is_file():
            return None
        try:
            if not path.resolve(strict=True).is_relative_to(base):
                return None
            data = path.read_bytes()
        except (OSError, RuntimeError):
            return None
        if hashlib.sha256(data).hexdigest() != sha:
            return None
        if rel == "models/EmpresaModel.php":
            model_bytes = data
    return model_bytes


def trial(model: bytes, php_script: Path) -> tuple[int, list[str]]:
    """Execute a sandboxed copied model with synthetic fake PDO."""
    if not shutil.which("php") or not php_script.is_file():
        return 2, ["D3_08_FIXTURE=NO_GO_FALTA_PHP_O_KIT"]
    with tempfile.TemporaryDirectory(prefix="cervesur-d3-empresa-") as temp:
        root = Path(temp)
        model_dir = root / "backend" / "models"
        lib_dir = root / "backend" / "lib"
        model_dir.mkdir(parents=True)
        lib_dir.mkdir(parents=True)
        (model_dir / "EmpresaModel.php").write_bytes(model)
        for name, source in PHPS.items():
            (lib_dir / name).write_text(source, encoding="utf-8")
        (root / "d3_empresa_fixture.php").write_bytes(php_script.read_bytes())

        # No privileged access for the child. Parent reads ONLY hashed sources.
        child_uid = child_gid = None
        if os.geteuid() == 0:
            nobody = pwd.getpwnam("nobody")
            child_uid, child_gid = nobody.pw_uid, nobody.pw_gid
            # Mantener TODO el arbol privado al usuario aislado. No usar
            # 755/644: la copia puede contener texto confidencial.
            for dirpath, dirs, files in os.walk(root):
                os.chown(dirpath, child_uid, child_gid)
                os.chmod(dirpath, 0o700)
                for name in files:
                    file = Path(dirpath) / name
                    os.chown(file, child_uid, child_gid)
                    os.chmod(file, 0o600)
        forbidden = (
            "exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,"
            "fsockopen,pfsockopen,stream_socket_client,stream_socket_server,"
            "curl_init,curl_exec,file_put_contents,copy,rename,unlink,mkdir,rmdir,"
            "mail,putenv"
        )
        cmd = [
            "php",
            "-d", f"open_basedir={root}",
            "-d", "allow_url_fopen=0",
            "-d", "allow_url_include=0",
            "-d", f"disable_functions={forbidden}",
            "-d", "disable_classes=SQLite3,MySQLi",
            "-d", "display_errors=0",
            "-d", "log_errors=0",
            "-d", "opcache.enable_cli=0",
            "-d", "auto_prepend_file=",
            "-d", "auto_append_file=",
            str(root / "d3_empresa_fixture.php"),
        ]
        kwargs = {}
        if child_uid is not None:
            kwargs.update(user=child_uid, group=child_gid)
        try:
            process = subprocess.run(
                cmd, capture_output=True, text=True, timeout=15, check=False,
                cwd=root,
                env={"PATH": "/usr/bin:/bin", "HOME": str(root),
                     "TMPDIR": str(root), "LC_ALL": "C"},
                **kwargs,
            )
        except (OSError, subprocess.TimeoutExpired, PermissionError):
            return 2, ["D3_08_FIXTURE=NO_GO_EJECUCION_AISLADA"]
        # Never echo arbitrary stdout/stderr from the model; allowlist only
        # fixed result tags, never SQL, exceptions, secret values or sources.
        lines = [
            l for l in process.stdout.splitlines()
            if l.startswith("D3_08_") or l.startswith("MYSQL_PRODUCTIVO_")
            or l.startswith("DATOS_PERSONALES_") or l.startswith("U_BASIC_")
        ]
        if not any(l.startswith("D3_08_FIXTURE_GLOBAL=") for l in lines):
            return 2, ["D3_08_FIXTURE=NO_GO_RESULTADO_NO_VERIFICABLE"]
        return process.returncode, lines


def self_test() -> int:
    # Self-test must work without accessing any production files or DB.
    safe = b"""<?php
class EmpresaModel {
    private $pdo;
    public function __construct($pdo) { $this->pdo=$pdo; }
    private function row() {
        $s=$this->pdo->prepare('SELECT e.id, e.nombre FROM empresa e WHERE e.activo=1');
        $s->execute([]);
        return $s->fetch(PDO::FETCH_ASSOC);
    }
    public function getAll($id, $role='admin') { return [$this->row()]; }
    public function getById($id, $user=1, $role='admin') { return $this->row(); }
    public function search($s, $id=1, $role='admin') { return [$this->row()]; }
    public function getByCity($s, $id=1, $role='admin') { return [$this->row()]; }
}
"""
    leak = safe.replace(
        b"SELECT e.id, e.nombre FROM empresa",
        b"SELECT e.* FROM empresa"
    )
    passed, a = trial(safe, Path(__file__).with_name("d3_empresa_fixture.php"))
    blocked, b = trial(leak, Path(__file__).with_name("d3_empresa_fixture.php"))
    okay = (
        passed == 0 and "D3_08_FIXTURE_GLOBAL=PASS_ACOTADO_DATOS_SINTETICOS" in a
        and blocked != 0 and "D3_08_FIXTURE_GLOBAL=NO_GO_EXPOSICION_SINTETICA" in b
    )
    print("D3_08_SELFTEST=" + ("PASS" if okay else "FAIL"))
    print("D3_08_SELFTEST_MYSQL_CONTACTADO=NO")
    return 0 if okay else 2


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--backend", default="/var/www/cervesur/backend")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        return self_test()

    model = check_sources(Path(args.backend))
    if model is None:
        print("D3_08_REAL_SHA=NO_GO_DRIFT_O_PERMISOS")
        print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
        return 2
    print("D3_08_REAL_SHA=PASS_2_DE_2")
    status, messages = trial(model, Path(__file__).with_name("d3_empresa_fixture.php"))
    for message in messages:
        print(message)
    print("D3_08_CIERRE=PENDIENTE_ANTERIOR_Y_POSTERIOR_QA")
    return status


if __name__ == "__main__":
    raise SystemExit(main())
