#!/usr/bin/env python3
"""D3-08: isolated GET router -> controller -> model -> K2 -> JSON test.

Uses copied PHP originals, SHA-pinned model/controller/K2, a snapshot of the
live router, synthetic identity and row. No production API call, DB, .env,
passwords or JWT. Never prints PHP/SQL, PII, raw JSON or synthetic secret.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import pwd
import shutil
import subprocess
import tempfile
from pathlib import Path

PINNED = {
    "models/EmpresaModel.php": "a645a5e864e9afa0510b126d49a25e4af6ec82aff94fd49982206503cab2839d",
    "controllers/EmpresaController.php": "f18078cca30013b1e1fe169b5fb727532da40fe929642db08f9ea4ec8e3a519b",
    "lib/empresa_sanitizer.php": "cf680885963d6d518e09995f9b1f4b2f7e4105a7aa7f0ec64db319c36b59f77e",
}
ROUTER = "router/EmpresaRouter.php"
CASES = ("GETALL", "GETBYID", "SEARCH", "GETBYCITY")
MARKER = "CERVESUR_D3_K2_SYNTHETIC_ONLY_2026"
STUBS = {
    "lib/views.php": """<?php
function require_view($allowed = ['minimal']) { return null; }
function require_detail_view($role, $resource) { return [null, $resource]; }
function apply_view($response, $view, $resource) { return $response; }
""",
    "lib/pagination.php": """<?php
function require_pagination($defaultPerPage = null) { return null; }
function pagination_response($pagination, $total) { return []; }
function paginated_rows($pdo, $sql, array $params, $orderBy, $pagination) {
    if ($pagination !== null) throw new RuntimeException('D3_PAGINACION_INVALIDA');
    $stmt = $pdo->prepare($sql . ' ORDER BY ' . $orderBy);
    $stmt->execute($params);
    return [$stmt->fetchAll(PDO::FETCH_ASSOC), null];
}
""",
    "lib/status_filter.php": """<?php
function require_status_filter($role) { return 'active'; }
function status_sql($column, $status) {
    if ($status === 'all') return '1=1';
    if ($status === 'inactive') return $column . ' = 0';
    return $column . ' = 1';
}
""",
    "lib/auth_middleware.php": """<?php
function require_auth($jwt_secret = null) {
    return ['user_id' => 1, 'role' => 'admin', 'session_version' => 1];
}
function get_jwt_secret_or_fail($context = '') { return 'SYNTHETIC_D3_K2'; }
""",
    "lib/mode.php": """<?php
function app_mode() { return 'admin_escritura'; }
function app_mode_is_read_only() { return false; }
""",
    "lib/write_support.php": """<?php
const FORM_VERSION_FIELDS = ['empresa_form' => ['nombre', 'ruc']];
function write_respond($response) { throw new RuntimeException('D3_NO_WRITES'); }
""",
}


def verified_sources(root: Path) -> dict[str, bytes] | None:
    if not root.is_dir() or root.is_symlink():
        return None
    try:
        base = root.resolve(strict=True)
    except OSError:
        return None
    files: dict[str, bytes] = {}
    for rel in (*PINNED, ROUTER):
        path = root / rel
        if not path.is_file() or path.is_symlink():
            return None
        try:
            resolved = path.resolve(strict=True)
            if not resolved.is_relative_to(base):
                return None
            data = path.read_bytes()
        except (OSError, RuntimeError):
            return None
        if rel in PINNED and hashlib.sha256(data).hexdigest() != PINNED[rel]:
            return None
        files[rel] = data
    # Do not treat the unpinned router as signed: runtime snapshot only.
    return files


def has_sensitive(value: object, level: int = 0) -> bool:
    if level > 12:
        return True
    if isinstance(value, str):
        return MARKER in value
    if isinstance(value, list):
        return any(has_sensitive(v, level + 1) for v in value)
    if isinstance(value, dict):
        return any(
            ("powerbi_clientsecret" in str(key).lower())
            or has_sensitive(item, level + 1)
            for key, item in value.items()
        )
    return False


def has_company(value: object, level: int = 0) -> bool:
    if level > 12:
        return False
    if isinstance(value, dict):
        return (
            value.get("id") == 42 and value.get("nombre") == "EMPRESA_QA"
        ) or any(has_company(v, level + 1) for v in value.values())
    if isinstance(value, list):
        return any(has_company(v, level + 1) for v in value)
    return False


def classify(stdout: str, exit_code: int) -> str:
    if exit_code != 0:
        return "INCONCLUSO_HARNESS"
    try:
        response = json.loads(stdout)
    except (ValueError, TypeError):
        return "INCONCLUSO_RESPUESTA_NO_JSON"
    if not isinstance(response, dict) or response.get("success") is not True:
        return "INCONCLUSO_RESPUESTA_ERROR"
    if not has_company(response.get("data")):
        return "INCONCLUSO_NO_RETORNO_FILA"
    if has_sensitive(response):
        return "NO_GO_SECRETO_EN_JSON_SINTETICO"
    return "PASS_JSON_SIN_SECRETO_SINTETICO"


def trial(
    sources: dict[str, bytes],
    fixture: Path,
    *,
    fixture_name: str = "d3_k2_router_fixture.php",
) -> tuple[int, dict[str, str]]:
    if not shutil.which("php") or not fixture.is_file():
        return 2, {case: "INCONCLUSO_NO_PHP_O_FIXTURE" for case in CASES}
    with tempfile.TemporaryDirectory(prefix="cervesur-d3-k2-http-") as path:
        root = Path(path)
        for rel, blob in sources.items():
            file = root / "backend" / rel
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(blob)
        for rel, code in STUBS.items():
            file = root / "backend" / rel
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text(code, encoding="utf-8")
        script = root / fixture_name
        script.write_bytes(fixture.read_bytes())
        kwargs: dict[str, object] = {}
        if os.geteuid() == 0:
            user = pwd.getpwnam("nobody")
            for base, folders, files in os.walk(root):
                os.chown(base, user.pw_uid, user.pw_gid)
                os.chmod(base, 0o700)
                for name in files:
                    item = Path(base) / name
                    os.chown(item, user.pw_uid, user.pw_gid)
                    os.chmod(item, 0o600)
            kwargs = {"user": user.pw_uid, "group": user.pw_gid}
        disabled = (
            "exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,"
            "fsockopen,pfsockopen,stream_socket_client,stream_socket_server,"
            "curl_init,curl_exec,mail,putenv,copy,rename,unlink,mkdir,rmdir,"
            "file_put_contents"
        )
        common = [
            "php",
            "-d", f"open_basedir={root}",
            "-d", "allow_url_fopen=0",
            "-d", "allow_url_include=0",
            "-d", f"disable_functions={disabled}",
            "-d", "disable_classes=SQLite3,MySQLi",
            "-d", "display_errors=0",
            "-d", "log_errors=0",
            "-d", "opcache.enable_cli=0",
            "-d", "auto_prepend_file=",
            "-d", "auto_append_file=",
            str(script),
        ]
        results: dict[str, str] = {}
        for case in CASES:
            try:
                p = subprocess.run(
                    common + [case],
                    cwd=root,
                    env={
                        "PATH": "/usr/bin:/bin",
                        "HOME": str(root),
                        "TMPDIR": str(root),
                        "LC_ALL": "C",
                    },
                    capture_output=True,
                    text=True,
                    timeout=12,
                    check=False,
                    **kwargs,
                )
                results[case] = classify(p.stdout, p.returncode)
            except (OSError, subprocess.TimeoutExpired):
                results[case] = "INCONCLUSO_EJECUCION"
        if any(v.startswith("NO_GO_") for v in results.values()):
            return 2, results
        if all(v.startswith("PASS_") for v in results.values()):
            return 0, results
        return 2, results


def self_test(fixture: Path) -> int:
    # This checks both a safe and a deliberately leaking fake router, without
    # reading production files. It never claims to verify the real K2 wiring.
    ok_router = b"""<?php
$r=['success'=>true,'data'=>['id'=>42,'nombre'=>'EMPRESA_QA']];
echo json_encode($r); exit;
"""
    leaking_router = b"""<?php
$r=['success'=>true,'data'=>['id'=>42,'nombre'=>'EMPRESA_QA',
 'powerbi_clientsecret'=>'CERVESUR_D3_K2_SYNTHETIC_ONLY_2026']];
echo json_encode($r); exit;
"""
    rc_ok, ok = trial({ROUTER: ok_router}, fixture)
    rc_bad, bad = trial({ROUTER: leaking_router}, fixture)
    valid = (
        rc_ok == 0 and rc_bad != 0
        and all(v == "PASS_JSON_SIN_SECRETO_SINTETICO" for v in ok.values())
        and all(v == "NO_GO_SECRETO_EN_JSON_SINTETICO" for v in bad.values())
    )
    print("D3_K2_ROUTER_SELFTEST=" + ("PASS" if valid else "FAIL"))
    print("D3_K2_ROUTER_SELFTEST_PRODUCCION_CONTACTADA=NO")
    return 0 if valid else 2


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--backend", default="/var/www/cervesur/backend")
    ap.add_argument("--self-test", action="store_true")
    args = ap.parse_args()
    fixture = Path(__file__).with_name("d3_k2_router_fixture.php")
    if args.self_test:
        return self_test(fixture)
    sources = verified_sources(Path(args.backend))
    if sources is None:
        print("D3_K2_ROUTER_FUENTES=NO_GO_SHA_O_ACCESO")
        print("CAMBIOS_PRODUCTIVOS=NO")
        return 2
    print("D3_K2_ROUTER_FUENTES=PASS_3_PINNED_Y_ROUTER_COPIADO")
    rc, results = trial(sources, fixture)
    for case in CASES:
        print(f"D3_K2_ROUTER_{case}={results[case]}")
    if all(v.startswith("PASS_") for v in results.values()):
        print("D3_K2_ROUTER_GLOBAL=PASS_ACOTADO_JSON_4_DE_4")
    elif any(v.startswith("NO_GO_") for v in results.values()):
        print("D3_K2_ROUTER_GLOBAL=NO_GO_EXPOSICION_SINTETICA")
    else:
        print("D3_K2_ROUTER_GLOBAL=PENDIENTE_COMPATIBILIDAD")
    print("D3_K2_AUTH=SIMULADA_NO_ES_PRUEBA_DE_ROLE_403")
    print("D3_K2_HTTP_REAL=NO_PROBADO")
    print("MYSQL_PRODUCTIVO_CONTACTADO_POR_HARNESS=NO")
    print("DATOS_PERSONALES_LEIDOS=NO")
    print("U_BASIC_ACTIVADO=NO")
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
