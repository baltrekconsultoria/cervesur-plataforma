"""D4 v2 offline regression: synthetic environment only, no production access."""
import contextlib
import hashlib
import importlib.util
import io
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

spec = importlib.util.spec_from_file_location("attest_d4", Path(__file__).with_name("attest_d4.py"))
d4 = importlib.util.module_from_spec(spec)
spec.loader.exec_module(d4)

class D4Tests(unittest.TestCase):
    def setUp(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        self.root = Path(tmp.name)
        for name in ("router", "lib", "controllers"):
            (self.root / name).mkdir()
        self.secret = "This-is-only-a-synthetic-secret-ABC-1234-XYZ-000"
        self.env = self.root / ".env"
        self.env.write_text("JWT_SECRET=" + self.secret + "\nJWT_EXPIRATION=86400\n")
        self.env.chmod(0o600)
        self.auth = self.root / "router" / "auth.php"
        self.auth.write_bytes(b"<?php // F10-20261111 synthetic guard\n")
        self.middleware = self.root / "lib" / "auth_middleware.php"
        self.middleware.write_bytes(b"<?php // authentication middleware fixture\n")
        (self.root / "lib" / "powerbi_authz.php").write_text(
            "<?php\nif (!defined('POWERBI_AUTH_MODE')) {\n"
            "    define('POWERBI_AUTH_MODE', 'retirar');\n}\n"
        )
        self.f10 = mock.patch.object(d4, "F10_SHA", hashlib.sha256(self.auth.read_bytes()).hexdigest())
        self.h1 = mock.patch.object(d4, "H1_SHA", hashlib.sha256(self.middleware.read_bytes()).hexdigest())
        self.k1 = mock.patch.object(d4, "k1_http_code", return_value=410)
        for patch in (self.f10, self.h1, self.k1):
            patch.start()
            self.addCleanup(patch.stop)

    def statuses(self):
        return dict(d4.review(self.root, "http://192.168.200.12/api/powerbi/tokens/0"))

    def test_all_critical_pass_and_no_secrets_in_result(self):
        s = self.statuses()
        self.assertEqual(s["D4-01"], "PASS_PERMISOS_OTROS_CERRADOS")
        self.assertEqual(s["D4-03"], "PASS_LONGITUD")
        self.assertEqual(s["D4-07"], "PASS_JWT_86400")
        self.assertEqual(s["D4-F10-SHA"], "PASS")
        self.assertEqual(s["D4-H1-SHA"], "PASS")
        self.assertEqual(s["D4-08b"], "PASS_HTTP_410")
        self.assertEqual(s["D4-09"], "PASS_F10_VERSION_FIRMADA")
        self.assertEqual(s["D4-06b"], "PASS_SIN_FALLBACKS")
        self.assertNotIn(self.secret, repr(s))

    def test_f10_existing_marker_not_required(self):
        self.auth.write_bytes(b"<?php // valid synthetic code but no REGISTRO_ABIERTO\n")
        with mock.patch.object(d4, "F10_SHA", hashlib.sha256(self.auth.read_bytes()).hexdigest()):
            self.assertEqual(self.statuses()["D4-09"], "PASS_F10_VERSION_FIRMADA")

    def test_f10_mismatch_rejected(self):
        self.auth.write_bytes(b"<?php // modified route\n")
        self.assertEqual(self.statuses()["D4-09"], "FAIL_F10_NO_VERIFICADA")

    def test_jwt_ttl_mismatch_rejected(self):
        self.env.write_text("JWT_SECRET=" + self.secret + "\nJWT_EXPIRATION=10800\n")
        self.assertEqual(self.statuses()["D4-07"], "FAIL_JWT_EXPIRACION")

    def test_duplicates_fail_closed(self):
        with self.assertRaisesRegex(d4.AuditFailure, "ENV_DUPLICADO"):
            self.env.write_text("JWT_SECRET=a\nJWT_SECRET=b\n")
            self.statuses()

    def test_file_other_readable_fails(self):
        self.env.chmod(0o644)
        self.assertEqual(self.statuses()["D4-01"], "FAIL_PERMISOS_OTROS")

    def test_weak_secret_rejected(self):
        self.env.write_text("JWT_SECRET=secret\nJWT_EXPIRATION=86400\n")
        self.assertEqual(self.statuses()["D4-03"], "FAIL_LONGITUD")
        self.assertEqual(self.statuses()["D4-04"], "FAIL_SECRET_DE_EJEMPLO")

    def test_known_fallback_is_reported_not_hidden(self):
        (self.root / "router" / "reporte.php").write_text(
            "<?php $key = getenv('JWT_SECRET') ?: 'another-test-fallback';\n"
        )
        s = self.statuses()
        self.assertEqual(s["D4-06"], "PASS_SECRET_DISTINTO_DE_LITERALES")
        self.assertEqual(s["D4-06b"], "PENDIENTE_LITERAL_POR_DEFECTO_CANTIDAD_1")

    def test_secret_matches_fallback_fails(self):
        (self.root / "router" / "reporte.php").write_text(
            "<?php $key = getenv('JWT_SECRET') ?: '" + self.secret + "';\n"
        )
        self.assertEqual(self.statuses()["D4-06"], "FAIL_SECRETO_IGUAL_A_LITERAL")

    def test_k1_non_retired_fails(self):
        (self.root / "lib" / "powerbi_authz.php").write_text(
            "<?php\nif (!defined('POWERBI_AUTH_MODE')) {\n"
            "    define('POWERBI_AUTH_MODE', 'observar');\n}\n"
        )
        self.assertEqual(self.statuses()["D4-08"], "FAIL_FALLBACK_NO_UNICO")

    def test_http_401_fails(self):
        with mock.patch.object(d4, "k1_http_code", return_value=401):
            self.assertEqual(self.statuses()["D4-08b"], "FAIL_HTTP_401")

    def test_url_forgery_denied_before_http(self):
        with self.assertRaisesRegex(d4.AuditFailure, "URL_HTTP_FUERA_DE_ALCANCE"):
            d4.k1_http_code.__wrapped__("http://attacker.example/api/powerbi/tokens/0") if hasattr(d4.k1_http_code, "__wrapped__") else d4.k1_http_code("http://attacker.example/api/powerbi/tokens/0")

    def test_no_output_from_review(self):
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            self.statuses()
        self.assertEqual(buf.getvalue(), "")

if __name__ == "__main__":
    unittest.main(verbosity=2)
