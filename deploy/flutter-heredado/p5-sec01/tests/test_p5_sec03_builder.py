"""Pruebas aisladas del constructor P5-SEC03. No incluye fuente productiva."""
import importlib.util
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parents[1] / "scripts/build_p5_sec03_candidate.py"
spec = importlib.util.spec_from_file_location("p5_sec03_builder", SCRIPT)
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

SYNTHETIC = """<?php
require_once __DIR__ . '/jwt.php';
function authenticate_user($jwt_secret) {
    return false;
}

/**
 * Middleware para endpoints protegidos
 */
function require_auth($jwt_secret) {
    $user = authenticate_user($jwt_secret);
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado. Token inválido o expirado.']);
        exit;
    }
    return $user;
}
"""


class BuilderTest(unittest.TestCase):
    def test_patch_auth_only_without_changing_401_contract(self):
        result = builder.rewrite_middleware(SYNTHETIC)
        self.assertIn("require_once __DIR__ . '/p5_legacy_adapter.php';", result)
        self.assertIn("p5_sec03_ttl_from_env()", result)
        self.assertIn("p5_legacy_authenticate_headers($headers, $jwt_secret, $pdo, null, $ttl)", result)
        self.assertIn("getenv('JWT_EXPIRATION')", result)
        self.assertIn("No autorizado. Token inválido o expirado.", result)
        self.assertIn("function require_auth($jwt_secret)", result)
        self.assertNotIn("return false;\n}\n\n/**\n * Middleware para endpoints protegidos", result)

    def test_multiple_or_missing_anchors_rejected(self):
        for fixture in [SYNTHETIC + SYNTHETIC, SYNTHETIC.replace("authenticate_user(", "different("),
                        SYNTHETIC.replace("require_once __DIR__ . '/jwt.php';", "")]:
            with self.assertRaises(ValueError):
                builder.rewrite_middleware(fixture)

    def test_already_patched_rejected(self):
        once = builder.rewrite_middleware(SYNTHETIC)
        with self.assertRaises(ValueError):
            builder.rewrite_middleware(once)

    def test_wrong_sha_refused_before_writing(self):
        with tempfile.TemporaryDirectory(prefix="p5-sec03-qa-") as root:
            src = Path(root) / "synthetic.php"
            target = Path(root) / "staged"
            src.write_text(SYNTHETIC, encoding="utf-8")
            with self.assertRaises(ValueError):
                builder.stage(src, target)
            self.assertFalse(target.exists())

    def test_source_dir_overwrite_refused(self):
        with tempfile.TemporaryDirectory(prefix="p5-sec03-qa-") as root:
            src = Path(root) / "synthetic.php"
            src.write_text(SYNTHETIC, encoding="utf-8")
            with self.assertRaises(ValueError):
                builder.ensure_safe_output(src, src.parent)

    def test_original_hash_pinned(self):
        self.assertEqual(len(builder.EXPECTED_SOURCE_SHA256), 64)
        self.assertEqual(builder.EXPECTED_SOURCE_SHA256[:12], "fbdf6253eec6")


if __name__ == "__main__":
    unittest.main(verbosity=2)
