import hashlib
import tempfile
import unittest
from pathlib import Path
from d3_projection_origin import audit, helper_status, load_verified


def example_model(helper: str) -> str:
    return """<?php class EmpresaModel {
 private function powerbiColumns() {
 """ + helper + """
 }
 public function getAll($id) {
   $sql = 'SELECT e.id, e.nombre, ' . $this->powerbiColumns() . 'e.logo FROM empresa e WHERE e.id=:id';
 }
 public function getById($id) {
   $sql = 'SELECT e.id, ' . $this->powerbiColumns() . 'e.nombre FROM empresa e WHERE e.id=:id';
 }
 public function search($id) {
   $sql = 'SELECT e.id, ' . $this->powerbiColumns() . 'e.nombre FROM empresa e';
 }
 public function getByCity($id) {
   $sql = 'SELECT e.id, ' . $this->powerbiColumns() . 'e.ciudad FROM empresa e';
 }
}"""


class TestD3ProjectionOrigin(unittest.TestCase):
    def test_literal_helper_returns_excluding_secret(self):
        src = example_model(
            "return app_mode_is_read_only() ? '' : 'e.powerbi_tenant, e.powerbi_client, ';"
        )
        r = audit(src)
        self.assertEqual(r["HELPER_POWERBI_COLUMNS"], "INDICIO_LITERALES_SIN_SECRETO")
        self.assertEqual(r["LECTURA_GETALL"], "INDICIO_HELPER_LITERALES_SIN_SECRETO")

    def test_helper_explicit_secret(self):
        r = audit(example_model("return 'e.powerbi_clientsecret, ';"))
        self.assertEqual(r["HELPER_POWERBI_COLUMNS"], "REVISAR_MENCION_EXPLICITA")
        self.assertEqual(r["LECTURA_GETBYID"], "REVISAR_HELPER_DINAMICO")

    def test_function_generated_projection_is_review(self):
        r = audit(example_model("return build_sql_columns();"))
        self.assertEqual(r["HELPER_POWERBI_COLUMNS"], "REVISAR_FUNCION")

    def test_variable_generated_projection_is_review(self):
        r = audit(example_model("return $dynamic_columns;"))
        self.assertEqual(r["HELPER_POWERBI_COLUMNS"], "REVISAR_DINAMICO")

    def test_select_star_is_review(self):
        src = example_model("return '';").replace(
            "'SELECT e.id, e.nombre, ' . $this->powerbiColumns() . 'e.logo FROM empresa e",
            "'SELECT e.* FROM empresa e"
        )
        r = audit(src)
        self.assertEqual(r["LECTURA_GETALL"], "REVISAR_SELECT_STAR")

    def test_sha_preflight_and_symlinks(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            (root / "models").mkdir()
            (root / "models/EmpresaModel.php").write_text("<?php")
            original = {"models/EmpresaModel.php": hashlib.sha256(b"<?php").hexdigest()}
            self.assertIsNotNone(load_verified(root, original))
            self.assertIsNone(load_verified(root, {"models/EmpresaModel.php": "f"*64}))
            (root / "models/EmpresaModel.php").unlink()
            (root / "models/EmpresaModel.php").symlink_to("/etc/passwd")
            self.assertIsNone(load_verified(root, original))


if __name__ == "__main__":
    unittest.main()
