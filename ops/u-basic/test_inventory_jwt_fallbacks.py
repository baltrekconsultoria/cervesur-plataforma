"""Synthetic-only QA: safe and deterministic JWT fallback inventory."""
import contextlib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location(
    "inventory", Path(__file__).with_name("inventory_jwt_fallbacks.py"))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class InventoryTests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        for sub in m.SUBDIRS:
            (self.root / sub).mkdir()
        self.secret = "FAKE_LITERAL_DO_NOT_PRINT_123456"
        self.src = self.root / "router" / "RouteA.php"
        self.src.write_text(
            "<?php\nfunction validate_my_token() {\n"
            " $key = getenv('JWT_SECRET') ?: '" + self.secret + "';\n"
            " $again = getenv(\"JWT_SECRET\") ?: \"" + self.secret + "\";\n"
            "}\n"
        )
    def test_scans_and_redacts_both_quote_styles(self):
        items = m.scan(self.root)
        self.assertEqual(len(items), 2)
        self.assertEqual([item["line"] for item in items], [3, 4])
        self.assertEqual(items[0]["function"], "validate_my_token")
        self.assertEqual(items[0]["assignment"], "key")
        self.assertNotIn(self.secret, repr(items))
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(m.report(items, expected=2), 0)
        self.assertNotIn(self.secret, output.getvalue())
        self.assertIn("INVENTARIO=PASS_SOLO_LECTURA", output.getvalue())
        self.assertEqual(items[0]["file"], "router/RouteA.php")
        self.assertEqual(len(items[0]["sha256"]), 64)
    def test_multiline_ternary_is_detected(self):
        self.src.write_text("<?php\n $key = getenv('JWT_SECRET')\n ?:\n 'synthetic';\n")
        actual = m.scan(self.root)
        self.assertEqual(len(actual), 1)
        self.assertEqual(actual[0]["line"], 2)
    def test_other_secret_sources_not_listed(self):
        self.src.write_text(
            "<?php\n$k = getenv('JWT_SECRET');\n"
            "$x = getenv('OTHER_SECRET') ?: 'other-value';\n"
        )
        self.assertEqual(m.scan(self.root), [])
    def test_count_discrepancy_is_no_go(self):
        result = io.StringIO()
        with contextlib.redirect_stdout(result):
            self.assertEqual(m.report(m.scan(self.root), expected=9), 2)
        self.assertIn("INVENTARIO=NO_GO_CONTEO_INESPERADO", result.getvalue())
    def test_symlink_file_denied(self):
        alias = self.root / "lib" / "danger.php"
        alias.symlink_to(self.src)
        with self.assertRaisesRegex(m.NoGo, "ARCHIVO_INVALIDO"):
            m.scan(self.root)
    def test_symlink_dir_denied(self):
        (self.root / "controllers").rmdir()
        (self.root / "controllers").symlink_to(self.root / "router", target_is_directory=True)
        with self.assertRaisesRegex(m.NoGo, "DIRECTORIO_INVALIDO"):
            m.scan(self.root)
    def test_no_env_read_or_extra_directories(self):
        (self.root / ".env").write_text("JWT_SECRET=" + self.secret)
        (self.root / "uploads").mkdir()
        (self.root / "uploads" / "u.php").write_text(
            "<?php $key = getenv('JWT_SECRET') ?: '" + self.secret + "';"
        )
        self.assertEqual(len(m.scan(self.root)), 2)

if __name__ == "__main__":
    unittest.main(verbosity=2)
