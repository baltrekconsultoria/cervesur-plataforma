import hashlib
import tempfile
import unittest
from pathlib import Path

from d3_source_indicators import analyze, load_verified


class D3SourceIndicatorsTest(unittest.TestCase):
    def sample(self):
        return {
            "router/index.php": """<?php
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/UserRouter.php';
require_once __DIR__ . '/ReporteRouter.php';
require_once __DIR__ . '/EmpresaRouter.php';
require_once __DIR__ . '/EmpresaUsuarioRouter.php';
require_once __DIR__ . '/EmpresaReporteUsuarioRouter.php';
require_once __DIR__ . '/AsignacionRouter.php';
require_once __DIR__ . '/PowerBIRouter.php';
require_once __DIR__ . '/StatsRouter.php';
require_once __DIR__ . '/wiki.php';""",
            "models/UserModel.php": """<?php class UserModel {
 public function updateUserRole($id, $role) {
   $sql = "UPDATE user SET role=:role WHERE id=:id";
 }
 public function updateProfile($id, $data) {
   $allowedFields = ['first_name','last_name','email','phone','avatar'];
 }
 public function end() {}
}""",
            "models/EmpresaModel.php": """<?php class EmpresaModel {
 private function withoutSecrets($empresa) {
  unset($empresa['powerbi_clientsecret']);
  return $empresa;
 }
 public function getAll($id) { return array_map([$this,'withoutSecrets'], $rows); }
 public function getById($id) { return $this->withoutSecrets($row); }
 public function search($name) { return array_map([$this,'withoutSecrets'], $rows); }
 public function getByCity($city) { return array_map([$this,'withoutSecrets'], $rows); }
}""",
            "controllers/EmpresaController.php": "<?php require_once __DIR__ . '/../models/EmpresaModel.php';",
            "router/StatsRouter.php": "<?php $user=require_auth(get_jwt_secret_or_fail('StatsRouter'));",
            "router/wiki.php": "<?php if ($uri === '/api/wiki') { if ($method === 'GET') { echo 'wiki'; }}",
        }

    def test_positive_structural_signals_are_not_global_d3_pass(self):
        results = analyze(self.sample())
        self.assertEqual(results["D3_03_METHOD_FROM_SERVER"], "INDICIO_OK")
        self.assertEqual(results["D3_07_ROUTER_IMPORTS"], "INDICIO_OK")
        self.assertEqual(results["D3_06_USER_PROFILE_ALLOWLIST"], "INDICIO_OK")
        self.assertEqual(results["D3_06_EMAIL_EDITABLE"], "SI_REVISAR_POLITICA")
        self.assertEqual(results["D3_08_SECRET_READ_PATHS"], "INDICIO_OK")

    def test_sensitive_profile_field_detected(self):
        sample = self.sample()
        sample["models/UserModel.php"] = sample["models/UserModel.php"].replace(
            "'phone','avatar'", "'phone','password'"
        )
        results = analyze(sample)
        self.assertEqual(results["D3_06_USER_PROFILE_ALLOWLIST"], "REVISAR")

    def test_missing_filter_in_one_method_detected(self):
        sample = self.sample()
        sample["models/EmpresaModel.php"] = sample["models/EmpresaModel.php"].replace(
            "public function search($name) { return array_map([$this,'withoutSecrets'], $rows); }",
            "public function search($name) { return $rows; }"
        )
        results = analyze(sample)
        self.assertEqual(results["D3_08_SECRET_READ_PATHS"], "REVISAR")

    def test_method_override_detected(self):
        sample = self.sample()
        sample["router/index.php"] += "\n$method = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'];"
        self.assertEqual(analyze(sample)["D3_03_NO_OVERRIDE_IN_ENTRY"], "REVISAR")

    def test_source_hash_fail_closed_and_no_symlink(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            (root / "router").mkdir()
            (root / "router/index.php").write_bytes(b"test-source")
            expected = {"router/index.php": hashlib.sha256(b"test-source").hexdigest()}
            self.assertIsNotNone(load_verified(root, expected))
            self.assertIsNone(load_verified(root, {"router/index.php": "f" * 64}))
            (root / "router/index.php").unlink()
            (root / "router/index.php").symlink_to("/etc/hosts")
            self.assertIsNone(load_verified(root, expected))


if __name__ == "__main__":
    unittest.main()
