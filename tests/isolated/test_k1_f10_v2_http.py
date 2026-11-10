#!/usr/bin/env python3
"""QA HTTP sintetico de los parches K1/F-10 v2. NO usa el backend productivo.

Ejecutar desde la raiz del repositorio:
    python3 tests/isolated/test_k1_f10_v2_http.py

Solo utiliza Python stdlib, PHP CLI y GNU patch. Levanta PHP en 127.0.0.1.
Las rutas de login/logout son dobles sinteticos, NO regresiones reales.
"""

import hashlib
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[2]
PKG = ROOT / "deploy/flutter-heredado/k1-f10-v2-qa"
SUMS = {
    "patches/K1-v2-cierre-incondicional.diff": "4564cc38cf4cb94c5a79347c2b27f2ebc839158ec204e58c76ec5ce0480d8233",
    "patches/F10-v2-cierre-incondicional.diff": "1c363ecb1ec8baac31ef7513ac17da290407506fe8cef4ab5a0150268cee9f62",
    "lib/powerbi_authz.php": "4137cff3cab2d2390dbd82e73500e37d432e9f259b1f0931da8f163d5350e3a1",
}

# Las fixtures solo incluyen las lineas de contexto necesarias para aplicar
# exactamente los diffs. No copian ni simulan la aplicacion corporativa entera.
K1_FIXTURE = r'''<?php
/**
 * QA sintetico: ruta Power BI.
 */

// require_once __DIR__ . '/../lib/auth_middleware.php';
require_once __DIR__ . '/../controllers/PowerBIController.php';

// GET /api/powerbi/tokens/{id} - Obtener tokens de Power BI para empresa-reporte
if ($method === 'GET' && preg_match('/^\/api\/powerbi\/tokens\/(\d+)$/', $uri, $matches)) {
    http_response_code(599); // No debe alcanzarse.
    echo json_encode(['error' => 'K1_FALLO_CIERRE']);
    exit;
}
'''

F10_FIXTURE = r'''<?php
if ($method === 'GET' && $uri === '/api/health') {
    http_response_code(200);
    echo json_encode(['source' => 'fixture', 'alive' => true]);
    exit;
}

// Endpoint de registro
if ($method === 'POST' && $uri === '/api/register') {
    require_once __DIR__ . '/../controllers/RegisterController.php';

    http_response_code(599); // No debe alcanzarse.
    echo json_encode(['error' => 'F10_FALLO_CIERRE']);
    exit;
}
if ($method === 'POST' && ($uri === '/api/login' || $uri === '/api/logout')) {
    http_response_code(200);
    echo json_encode(['source' => 'fixture', 'route' => $uri]);
    exit;
}
http_response_code(404);
echo json_encode(['source' => 'fixture', 'message' => 'No encontrado']);
'''

SERVER_ROUTER = r'''<?php
header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pdo = null;
// Los headers siguientes son exclusivamente del servidor QA sintetico.
if (isset($_SERVER['HTTP_X_QA_MODE']) && $_SERVER['HTTP_X_QA_MODE'] !== 'sin_definir') {
    define('POWERBI_AUTH_MODE', $_SERVER['HTTP_X_QA_MODE']);
}
if (isset($_SERVER['HTTP_X_QA_REGISTER']) && $_SERVER['HTTP_X_QA_REGISTER'] !== 'sin_definir') {
    define('REGISTRO_ABIERTO', $_SERVER['HTTP_X_QA_REGISTER'] === 'abierto');
}
if (str_starts_with($uri, '/api/powerbi/tokens/')) {
    require __DIR__ . '/backend/router/PowerBIRouter.php';
} else {
    require __DIR__ . '/backend/router/auth.php';
}
'''


class K1F10HttpQA(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        for command in ("php", "patch"):
            if not shutil.which(command):
                raise RuntimeError(f"Falta dependencia: {command}")
        cls.temp = tempfile.TemporaryDirectory(prefix="cervesur-k1-f10-v2-")
        cls.stage = Path(cls.temp.name)
        for directory in ("backend/router", "backend/controllers", "backend/lib"):
            (cls.stage / directory).mkdir(parents=True, exist_ok=True)
        (cls.stage / "backend/router/PowerBIRouter.php").write_text(K1_FIXTURE, encoding="utf-8")
        (cls.stage / "backend/router/auth.php").write_text(F10_FIXTURE, encoding="utf-8")
        (cls.stage / "backend/lib/powerbi_authz.php").write_bytes((PKG / "lib/powerbi_authz.php").read_bytes())
        (cls.stage / "backend/controllers/PowerBIController.php").write_text(
            '<?php throw new RuntimeException("Controlador K1 alcanzado");\n', encoding="utf-8"
        )
        (cls.stage / "backend/controllers/RegisterController.php").write_text(
            '<?php throw new RuntimeException("Controlador F10 alcanzado");\n', encoding="utf-8"
        )
        (cls.stage / "router.php").write_text(SERVER_ROUTER, encoding="utf-8")
        cls.patch_results = []
        for name in ("K1-v2-cierre-incondicional.diff", "F10-v2-cierre-incondicional.diff"):
            patch_path = PKG / "patches" / name
            for extra in (["--dry-run"], []):
                result = subprocess.run(
                    ["patch", "--batch", "-F0", "-p1", "-d", str(cls.stage), "-i", str(patch_path), *extra],
                    capture_output=True, text=True, timeout=15,
                )
                cls.patch_results.append((name, bool(extra), result.returncode, result.stdout + result.stderr))
                if result.returncode != 0:
                    raise RuntimeError(f"Fallo de aplicacion exacta {name}: {result.stdout} {result.stderr}")
        cls.lint_results = {}
        for rel in ("backend/router/PowerBIRouter.php", "backend/router/auth.php", "backend/lib/powerbi_authz.php"):
            result = subprocess.run(["php", "-l", str(cls.stage / rel)], capture_output=True, text=True, timeout=15)
            cls.lint_results[rel] = (result.returncode, result.stdout + result.stderr)
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            cls.port = sock.getsockname()[1]
        cls.logfile = open(cls.stage / "php-server.log", "w", encoding="utf-8")
        cls.server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{cls.port}", str(cls.stage / "router.php")],
            cwd=cls.stage, stdin=subprocess.DEVNULL, stdout=cls.logfile, stderr=subprocess.STDOUT,
            env={**os.environ, "PHP_CLI_SERVER_WORKERS": "1"},
        )
        for _ in range(50):
            if cls.server.poll() is not None:
                raise RuntimeError("Servidor PHP QA finalizo antes de arrancar")
            try:
                code, data = cls.http("GET", "/api/health")
                if code == 200 and data.get("alive"):
                    break
            except (OSError, ValueError, json.JSONDecodeError):
                pass
            time.sleep(0.1)
        else:
            raise RuntimeError("Servidor HTTP QA no disponible en 127.0.0.1")

    @classmethod
    def tearDownClass(cls):
        if hasattr(cls, "server"):
            cls.server.terminate()
            try:
                cls.server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                cls.server.kill()
                cls.server.wait(timeout=5)
        if hasattr(cls, "logfile"):
            cls.logfile.close()
        if hasattr(cls, "temp"):
            cls.temp.cleanup()

    @classmethod
    def http(cls, method, path, headers=None):
        conn = http.client.HTTPConnection("127.0.0.1", cls.port, timeout=5)
        try:
            conn.request(method, path, headers=headers or {})
            response = conn.getresponse()
            body = response.read().decode("utf-8")
            return response.status, json.loads(body)
        finally:
            conn.close()

    def test_manifest_sha256(self):
        for relative, wanted in SUMS.items():
            with self.subTest(relative=relative):
                digest = hashlib.sha256((PKG / relative).read_bytes()).hexdigest()
                self.assertEqual(digest, wanted)

    def test_manifiesto_archivo(self):
        entries = {}
        for line in (PKG / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
            digest, relative = line.split(maxsplit=1)
            entries[relative.strip()] = digest
        self.assertEqual(entries, SUMS)

    def test_parches_aplicados_sin_fuzz(self):
        self.assertEqual(len(self.patch_results), 4)
        for name, dry, rc, output in self.patch_results:
            with self.subTest(name=name, dry=dry):
                self.assertEqual(rc, 0, output)

    def test_php_lint(self):
        for relative, (rc, output) in self.lint_results.items():
            with self.subTest(relative=relative):
                self.assertEqual(rc, 0, output)

    def test_k1_todos_los_modos(self):
        for mode in ("sin_definir", "retirar", "observar", "mixto", "exigir", "desconocido"):
            for report_id in (0, 123):
                with self.subTest(mode=mode, report_id=report_id):
                    status, body = self.http("GET", f"/api/powerbi/tokens/{report_id}", {"X-QA-Mode": mode})
                    self.assertEqual(status, 410)
                    self.assertEqual(body, {"success": False, "message": "Recurso retirado"})

    def test_k1_con_authorization(self):
        status, body = self.http("GET", "/api/powerbi/tokens/123", {
            "X-QA-Mode": "observar", "Authorization": "Bearer marcador_sintetico_no_valido"
        })
        self.assertEqual(status, 410)
        self.assertEqual(body.get("success"), False)
        self.assertNotIn("access_token", body)
        self.assertNotIn("token", body)

    def test_f10_configuraciones(self):
        for flag in ("sin_definir", "abierto", "cerrado"):
            with self.subTest(flag=flag):
                status, body = self.http("POST", "/api/register", {"X-QA-Register": flag})
                self.assertEqual(status, 403)
                self.assertEqual(body.get("success"), False)
                self.assertIn("registro de usuarios esta deshabilitado", body.get("message", ""))

    def test_f10_no_intercepta_rutas_mock(self):
        for method, path, expected in (
            ("GET", "/api/register", 404),
            ("POST", "/api/login", 200),
            ("POST", "/api/logout", 200),
        ):
            with self.subTest(method=method, path=path):
                status, body = self.http(method, path, {"X-QA-Register": "abierto"})
                self.assertEqual(status, expected)
                self.assertEqual(body.get("source"), "fixture")


if __name__ == "__main__":
    unittest.main(verbosity=2)
