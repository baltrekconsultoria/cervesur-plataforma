import hashlib
import tempfile
import unittest
from pathlib import Path

from d3_secret_read_triage import classify_reads, read_verified


def model(read_projection: str, filtered: bool = False) -> str:
    filter_def = """
private function withoutSecrets($empresa) {
    unset($empresa['powerbi_clientsecret']);
    return $empresa;
}
""" if filtered else ""
    methods = []
    for name in ("getAll", "getById", "search", "getByCity"):
        if filtered:
            return_expr = "return $this->withoutSecrets($row);"
        else:
            return_expr = "return $row;"
        methods.append(
            f"public function {name}($id) {{\n"
            f"  $sql = 'SELECT {read_projection} FROM empresa WHERE id= :id';\n"
            f"  {return_expr}\n"
            f"}}"
        )
    return "<?php class EmpresaModel {\n" + filter_def + "\n".join(methods) + "\n}"


class SecretReadTriageTest(unittest.TestCase):
    def test_secret_excluded_by_projection(self):
        r = classify_reads(model("id, nombre, powerbi_client"))
        self.assertTrue(all(r[name] == "INDICIO_SELECCION_EXCLUYE_SECRETO"
                            for name in ("getAll", "getById", "search", "getByCity")))

    def test_secret_selected_without_filter_remains_review(self):
        r = classify_reads(model("id, powerbi_clientsecret"))
        self.assertEqual(r["getAll"], "REVISAR_SELECCION_SECRETO")

    def test_dynamic_projection_requires_review(self):
        r = classify_reads(model("*"))
        self.assertEqual(r["getAll"], "REVISAR_PROYECCION_DINAMICA")

    def test_real_sanitizer_plus_call_is_structural_indicator_only(self):
        r = classify_reads(model("id, powerbi_clientsecret", filtered=True))
        self.assertEqual(r["getById"], "INDICIO_FILTRADO_VERIFICADO")

    def test_name_only_does_not_count_as_sanitizer(self):
        code = model("id, powerbi_clientsecret")
        code = code.replace(
            "class EmpresaModel {",
            "class EmpresaModel {\nprivate function withoutSecrets($x) { return $x; }"
        )
        self.assertEqual(classify_reads(code)["search"], "REVISAR_SELECCION_SECRETO")

    def test_missing_method_and_unknown_sql_do_not_pass(self):
        code = model("id, nombre").replace(
            "public function search($id) {", "public function find($id) {"
        )
        self.assertEqual(classify_reads(code)["search"], "REVISAR_METODO_AUSENTE")

    def test_sha_fail_closed(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            (root / "models").mkdir()
            path = root / "models/EmpresaModel.php"
            path.write_text("<?php")
            expected = {"models/EmpresaModel.php": hashlib.sha256(b"<?php").hexdigest()}
            self.assertIsNotNone(read_verified(root, expected))
            self.assertIsNone(read_verified(root, {"models/EmpresaModel.php": "0"*64}))
            path.unlink()
            path.symlink_to("/etc/passwd")
            self.assertIsNone(read_verified(root, expected))


if __name__ == "__main__":
    unittest.main()
