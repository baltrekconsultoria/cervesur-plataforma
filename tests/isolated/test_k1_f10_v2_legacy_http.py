#!/usr/bin/env python3
"""HTTP QA sobre las copias reales ya parcheadas de los routers heredados.

SOLO QA. No usa la instalacion /var/www, no lee .env y no accede a DB/Azure.
Ejemplo en el host con copias verificadas:
  python3 test_k1_f10_v2_legacy_http.py \
    --source-dir "$HOME/cervesur-k1-f10-qa-v2"

Los controladores y auth_middleware.php se reemplazan por STUBS que fallan
si llegan a ejecutarse. Esto NO valida login/logout real ni la capa Apache.
"""
import argparse
import hashlib
import http.client as http_client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import sys
import tempfile
import time

EXPECTED = {
    "router/PowerBIRouter.php": "307fac9a023b1b16b800c94cd0a7e7f8cff2bc832618c0762c1278302448e544",
    "router/auth.php": "8ea0c0a417113420c56a24f3cbda2b2e5d684a87d29fe6fa0b3a3ba28a4f0cd6",
    "lib/powerbi_authz.php": "4137cff3cab2d2390dbd82e73500e37d432e9f259b1f0931da8f163d5350e3a1",
}

# Solo se necesita ejecutar las guardias K1/F-10. Las rutas ajenas no se prueban.
FRONT_CONTROLLER = r"""<?php
header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pdo = null;
if (isset($_SERVER['HTTP_X_QA_K1_MODE']) &&
    $_SERVER['HTTP_X_QA_K1_MODE'] !== 'sin_definir') {
    define('POWERBI_AUTH_MODE', $_SERVER['HTTP_X_QA_K1_MODE']);
}
if (isset($_SERVER['HTTP_X_QA_F10_FLAG']) &&
    $_SERVER['HTTP_X_QA_F10_FLAG'] !== 'sin_definir') {
    define('REGISTRO_ABIERTO', $_SERVER['HTTP_X_QA_F10_FLAG'] === 'abierto');
}
if ($method === 'GET' && preg_match('/^\/api\/powerbi\/tokens\/\d+$/', $uri)) {
    require __DIR__ . '/router/PowerBIRouter.php';
} elseif ($method === 'POST' && $uri === '/api/register') {
    require __DIR__ . '/router/auth.php';
} elseif ($method === 'GET' && $uri === '/qa/health') {
    http_response_code(200);
    echo json_encode(['qa' => true]);
} else {
    http_response_code(404);
    echo json_encode(['qa' => true, 'message' => 'Ruta fuera de alcance']);
}
"""

# Si una solicitud entra a un controlador, el test debe FALLAR sin producir
# ninguna consulta a bases de datos o sistemas externos.
STUB = """<?php
throw new RuntimeException('QA_STUB_CONTROLLER_ACCESO_NO_PERMITIDO');
"""
AUTH_STUB = """<?php
function require_auth(...$args) {
    throw new RuntimeException('QA_STUB_AUTH_ACCESO_NO_PERMITIDO');
}
function get_jwt_secret_or_fail(...$args) {
    throw new RuntimeException('QA_STUB_SECRET_ACCESO_NO_PERMITIDO');
}
"""

def http(port, method, path, headers=None, body=None):
    conn = http_client.HTTPConnection("127.0.0.1", port, timeout=5)
    try:
        conn.request(method, path, body=body, headers=headers or {})
        response = conn.getresponse()
        raw = response.read().decode("utf-8", errors="replace")
        try:
            parsed = json.loads(raw)
        except ValueError:
            parsed = {"_non_json": raw[:150]}
        return response.status, parsed
    finally:
        conn.close()

def self_test_http_helper():
    """Prueba autonoma: detecta colisiones de nombres antes del QA HTTP."""
    from unittest.mock import Mock, patch

    response = Mock()
    response.status = 200
    response.read.return_value = b'{"qa":true}'
    connection = Mock()
    connection.getresponse.return_value = response

    with patch.object(http_client, "HTTPConnection", return_value=connection) as constructor:
        status, payload = http(18432, "GET", "/qa/health")
        constructor.assert_called_once_with("127.0.0.1", 18432, timeout=5)
        connection.request.assert_called_once_with("GET", "/qa/health", body=None, headers={})
        connection.close.assert_called_once_with()

    if status != 200 or payload != {"qa": True}:
        raise RuntimeError("QA_HTTP_HELPER_SELFTEST=FAIL")
    print("QA_HTTP_HELPER_SELFTEST=PASS")
    return 0


def verify_sources(source):
    if not source.is_dir():
        raise RuntimeError("No existe el directorio QA")
    # Rechaza rutas productivas, links simbolicos y carpetas no esperadas.
    if source.name != "cervesur-k1-f10-qa-v2":
        raise RuntimeError("Carpeta no permitida: debe llamarse cervesur-k1-f10-qa-v2")
    if source.is_symlink() or not str(source.resolve()).startswith("/home/"):
        raise RuntimeError("Directorio QA no permitido; usar la copia bajo /home")
    contents = {}
    for relative, expected in EXPECTED.items():
        path = source / relative
        if path.is_symlink() or not path.is_file():
            raise RuntimeError(f"Archivo QA ausente o enlace simbolico: {relative}")
        data = path.read_bytes()
        actual = hashlib.sha256(data).hexdigest()
        if actual != expected:
            raise RuntimeError(f"HUELLA_INVALIDA {relative}: esperado {expected}, actual {actual}")
        contents[relative] = data
        print(f"PASS SHA256 {relative}")
    return contents

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-dir", type=Path,
                        help="Solo ruta de la carpeta QA cervesur-k1-f10-qa-v2")
    parser.add_argument("--self-test-http", action="store_true",
                        help="Prueba la funcion de transporte HTTP sin router ni servidor")
    args = parser.parse_args()
    if args.self_test_http:
        return self_test_http_helper()
    if args.source_dir is None:
        parser.error("--source-dir es obligatorio, excepto con --self-test-http")
    source = args.source_dir.expanduser()
    contents = verify_sources(source)
    if shutil.which("php") is None:
        raise RuntimeError("PHP CLI no disponible")

    passed = 0
    failed = 0
    with tempfile.TemporaryDirectory(prefix="cervesur-legacy-http-qa-") as tmp:
        root = Path(tmp)
        for folder in ("router", "lib", "controllers"):
            (root / folder).mkdir()
        for relative, data in contents.items():
            (root / relative).write_bytes(data)
        (root / "router.php").write_text(FRONT_CONTROLLER, encoding="utf-8")
        (root / "lib/auth_middleware.php").write_text(AUTH_STUB, encoding="utf-8")
        for name in ("PowerBIController.php", "RegisterController.php",
                     "AuthController.php", "LogoutController.php"):
            (root / "controllers" / name).write_text(STUB, encoding="utf-8")

        for relative in EXPECTED:
            result = subprocess.run(
                ["php", "-n", "-l", str(root / relative)],
                capture_output=True, text=True, timeout=10,
            )
            if result.returncode != 0:
                raise RuntimeError(f"PHP lint fallo: {relative}: {result.stdout} {result.stderr}")
            passed += 1
            print(f"PASS PHP lint {relative}")

        with socket.socket() as listener:
            listener.bind(("127.0.0.1", 0))
            port = listener.getsockname()[1]

        # php -n evita extensiones y conexiones PDO/MySQL; open_basedir
        # impide cargar archivos del backend productivo o .env.
        php_cmd = [
            "php", "-n", "-d", f"open_basedir={root}",
            "-d", "allow_url_fopen=0", "-d", "allow_url_include=0",
            "-d", "display_errors=0",
            "-d", "disable_functions=exec,system,shell_exec,passthru,popen,proc_open,fsockopen,pfsockopen,stream_socket_client,stream_socket_server",
            "-S", f"127.0.0.1:{port}", str(root / "router.php"),
        ]
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": str(root),
            "TMPDIR": str(root),
        }
        log_path = root / "server.log"
        with log_path.open("w+") as log:
            server = subprocess.Popen(
                php_cmd, cwd=root, env=env, stdin=subprocess.DEVNULL,
                stdout=log, stderr=subprocess.STDOUT,
            )
            try:
                for _ in range(50):
                    if server.poll() is not None:
                        raise RuntimeError("Servidor PHP QA se cerro en el arranque")
                    try:
                        status, body = http(port, "GET", "/qa/health")
                        if status == 200 and body.get("qa") is True:
                            break
                    except (OSError, ValueError):
                        pass
                    time.sleep(0.1)
                else:
                    raise RuntimeError("No se pudo iniciar servidor QA en 127.0.0.1")
                print("PASS localhost HTTP server; PHP -n; open_basedir; sin DB")

                cases = []
                for mode in ("sin_definir", "retirar", "observar",
                             "mixto", "exigir", "modo_desconocido"):
                    for report_id in (0, 123):
                        cases.append((
                            f"K1 mode={mode} id={report_id}",
                            "GET", f"/api/powerbi/tokens/{report_id}",
                            {"X-QA-K1-Mode": mode}, None, 410, "Recurso retirado"
                        ))
                cases.append((
                    "K1 Authorization simulada", "GET", "/api/powerbi/tokens/123",
                    {"X-QA-K1-Mode": "observar",
                     "Authorization": "Bearer fake_test_only"},
                    None, 410, "Recurso retirado"
                ))
                for flag in ("sin_definir", "abierto", "cerrado"):
                    cases.append((
                        f"F10 flag={flag}", "POST", "/api/register",
                        {"X-QA-F10-Flag": flag, "Content-Type": "application/json"},
                        '{"login":"qa_ficticio"}', 403,
                        "El registro de usuarios esta deshabilitado"
                    ))
                for name, method, path, headers, body, wanted_status, wanted_message in cases:
                    status, data = http(port, method, path, headers, body)
                    if (status == wanted_status and isinstance(data, dict)
                            and data.get("success") is False
                            and wanted_message in str(data.get("message", ""))):
                        passed += 1
                        print(f"PASS {name}: HTTP {status}")
                    else:
                        failed += 1
                        print(f"FAIL {name}: HTTP {status}, {data!r}")

                # Se prohibe considerar como smoke real las rutas simuladas.
                print("NOTA: login/logout, Apache, Azure y MySQL NO probados")
            finally:
                server.terminate()
                try:
                    server.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=5)
                log.flush()
                if failed or server.returncode not in (-15, -9, 0):
                    log.seek(0)
                    print("=== PHP QA log (recortado) ===")
                    print(log.read()[-2000:])

    print(f"LEGACY_QA_HTTP_PASS={passed}")
    print(f"LEGACY_QA_HTTP_FAIL={failed}")
    if failed:
        print("LEGACY_QA_HTTP=FAIL")
        return 1
    print("LEGACY_QA_HTTP=PASS")
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:
        print(f"LEGACY_QA_HTTP=ERROR: {exc}", file=sys.stderr)
        sys.exit(2)
