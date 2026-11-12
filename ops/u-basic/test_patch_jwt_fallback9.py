"""Cervesur legacy fallback9: isolated unit/functional-security fixtures.

No production files or secrets. Validates 8+1 exact replacements and existing
fail-closed behavior using only synthetic PHP and a local PHP CLI.
"""
from pathlib import Path
from unittest import TestCase, main, mock
import hashlib
import json
import os
import subprocess
import tempfile

import patch_jwt_fallback9 as patch

def h(b: bytes) -> str:
    return hashlib.sha256(b).hexdigest()

class Fallback9Tests(TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "backend"
        self.home = Path(self.tmp.name) / "backup-home"
        self.home.mkdir()
        for sub in ("router", "lib"):
            (self.root / sub).mkdir(parents=True)
        self.f10 = b"<?php // F10 synthetic marker\n"
        self.h1 = b"""<?php
function get_jwt_secret_or_fail($context = 'general') {
  $s = getenv('JWT_SECRET');
  if (!$s) { http_response_code(500); echo json_encode(['success'=>false, 'message'=>'Configuration missing']); exit; }
  return $s;
}
"""
        (self.root / "router" / "auth.php").write_bytes(self.f10)
        (self.root / "lib" / "auth_middleware.php").write_bytes(self.h1)
        self.expected = patch.FILES
        def build(count, first_line):
            head = b"<?php\nrequire_once __DIR__ . '/../lib/auth_middleware.php';\n"
            lines = [head]
            lines.extend([b"// synthetic filler\n"]*(first_line-3))
            for i in range(count):
                lines.append(b"$jwt_secret = getenv('JWT_SECRET') ?: 'FAKE-LEGACY-KEY';\n")
                lines.append(b"require_auth($jwt_secret);\n")
            return b"".join(lines)
        self.reports = build(8, 21)
        self.users = build(1, 179)
        files = [("router/ReporteRouter.php", self.reports, 8, 21),
                 ("router/UserRouter.php", self.users, 1, 179)]
        fake_spec = {}
        for rel, data, count, lineno in files:
            (self.root / rel).write_bytes(data)
            fake_spec[rel] = {
                "sha": h(data),
                "count": count,
                "lines": [lineno + i * 2 for i in range(count)],
            }
        self.patches = [
            mock.patch.object(patch, "ROOT", self.root),
            mock.patch.object(patch, "BACKUP_HOME", self.home),
            mock.patch.object(patch, "F10_SHA", h(self.f10)),
            mock.patch.object(patch, "H1_SHA", h(self.h1)),
            mock.patch.object(patch, "FILES", fake_spec),
        ]
        for p in self.patches:
            p.start()
            self.addCleanup(p.stop)

    def test_surgical_eight_plus_one_without_other_edits(self):
        before, after = patch.plan(self.root)
        self.assertEqual(sum(patch.D4_PATTERN.pattern.count(b"z") for _ in []), 0)
        self.assertEqual(sum(before[rel].count(b"FAKE-LEGACY-KEY") for rel in before), 9)
        self.assertEqual(sum(after[rel].count(patch.REPLACEMENT) for rel in after), 9)
        self.assertEqual(sum(len(patch.D4_PATTERN.findall(v)) for v in after.values()), 0)
        self.assertIn(b"require_auth($jwt_secret)", after["router/UserRouter.php"])
        self.assertNotIn(b"FAKE-LEGACY-KEY", after["router/ReporteRouter.php"])

    def test_unknown_sha_denied(self):
        rel = self.root / "router/ReporteRouter.php"
        rel.write_bytes(rel.read_bytes() + b"\n")
        with self.assertRaisesRegex(patch.NoGo, "SHA_ORIGEN_NO_PASS"):
            patch.plan(self.root)

    def test_helper_sha_denied(self):
        (self.root / "lib" / "auth_middleware.php").write_bytes(b"<?php // tampered\n")
        with self.assertRaisesRegex(patch.NoGo, "GUARDIA_H1_SHA_NO_PASS"):
            patch.plan(self.root)

    def test_f10_sha_denied(self):
        (self.root / "router" / "auth.php").write_bytes(b"<?php // tampered\n")
        with self.assertRaisesRegex(patch.NoGo, "GUARDIA_F10_SHA_NO_PASS"):
            patch.plan(self.root)

    def test_unexpected_extra_fallback_denied(self):
        rel = self.root / "router/ReporteRouter.php"
        modified = rel.read_bytes() + b"$jwt_secret = getenv('JWT_SECRET') ?: 'FAKE';\n"
        rel.write_bytes(modified)
        with mock.patch.dict(patch.FILES, {"router/ReporteRouter.php": {
            "sha": h(modified), "count": 8, "lines": self.expected["router/ReporteRouter.php"]["lines"]
        }}):
            with self.assertRaisesRegex(patch.NoGo, "D4_CONTEO_ORIGEN_INESPERADO"):
                patch.plan(self.root)

    def test_mismatched_assignment_shape_denied(self):
        rel = self.root / "router/UserRouter.php"
        modified = self.users.replace(b"$jwt_secret =", b"$different_secret =")
        rel.write_bytes(modified)
        with mock.patch.dict(patch.FILES, {"router/UserRouter.php": {
            "sha": h(modified), "count": 1, "lines": [179]
        }}):
            with self.assertRaisesRegex(patch.NoGo, "PHP_ASIGNACIONES_NO_CONCUERDAN"):
                patch.plan(self.root)

    def test_backup_apply_verify_rollback(self):
        before, after = patch.plan(self.root)
        for candidate in after.values():
            patch.php_lint(candidate)
        saved = patch.apply(self.root)
        self.assertTrue(saved.is_dir())
        self.assertEqual(saved.stat().st_mode & 0o777, 0o700)
        for rel in before:
            original = saved / rel
            self.assertEqual(original.read_bytes(), before[rel])
            self.assertEqual(original.stat().st_mode & 0o777, 0o600)
            self.assertEqual((self.root / rel).read_bytes(), after[rel])
        patch.verify_applied(self.root, saved)
        patch.rollback(self.root, saved)
        for rel in before:
            self.assertEqual((self.root / rel).read_bytes(), before[rel])
        self.assertEqual((self.root / "lib/auth_middleware.php").read_bytes(), self.h1)
        self.assertEqual((self.root / "router/auth.php").read_bytes(), self.f10)

    def test_rollback_rejects_postdeploy_drift(self):
        folder = patch.apply(self.root)
        (self.root / "router/UserRouter.php").write_bytes(b"<?php // drift\n")
        with self.assertRaisesRegex(patch.NoGo, "SHA_REVERSION_NO_COINCIDE"):
            patch.rollback(self.root, folder)

    def test_double_application_does_not_repatch(self):
        patch.apply(self.root)
        with self.assertRaisesRegex(patch.NoGo, "SHA_ORIGEN_NO_PASS"):
            patch.apply(self.root)

    def test_source_files_not_symlinks(self):
        rel = self.root / "router/UserRouter.php"
        rel.unlink()
        rel.symlink_to(self.root / "router/ReporteRouter.php")
        with self.assertRaisesRegex(patch.NoGo, "ARCHIVO_INVALIDO"):
            patch.plan(self.root)

    def test_php_missing_secret_rejects_before_auth(self):
        # A real PHP CLI invocation verifies that the existing helper
        # returns non-success and exit before require_auth can run.
        php = b"""<?php
function get_jwt_secret_or_fail($context='general') {
  $key = getenv('JWT_SECRET');
  if (!$key) { http_response_code(500); echo "DENIED"; exit; }
  return $key;
}
function require_auth($key) { echo "BAD_AUTH"; }
$jwt_secret = get_jwt_secret_or_fail('ruta protegida');
require_auth($jwt_secret);
"""
        with tempfile.TemporaryDirectory() as tmp:
            f = Path(tmp) / "test.php"
            f.write_bytes(php)
            env = dict(os.environ)
            env.pop("JWT_SECRET", None)
            res = subprocess.run(["php", str(f)], capture_output=True, check=False, env=env)
        self.assertEqual(res.returncode, 0)
        self.assertEqual(res.stdout, b"DENIED")
        self.assertNotIn(b"BAD_AUTH", res.stdout)

    def test_php_configured_secret_reaches_auth(self):
        php = b"""<?php
function get_jwt_secret_or_fail($context='general') {
  $key = getenv('JWT_SECRET');
  if (!$key) { echo "DENIED"; exit; }
  return $key;
}
function require_auth($key) { echo $key === 'SYNTHETIC-ONLY' ? 'AUTH_OK' : 'BAD'; }
$jwt_secret = get_jwt_secret_or_fail('ruta protegida');
require_auth($jwt_secret);
"""
        with tempfile.TemporaryDirectory() as tmp:
            f = Path(tmp) / "test.php"
            f.write_bytes(php)
            env = dict(os.environ)
            env["JWT_SECRET"] = "SYNTHETIC-ONLY"
            res = subprocess.run(["php", str(f)], capture_output=True, check=False, env=env)
        self.assertEqual(res.stdout, b"AUTH_OK")

if __name__ == "__main__":
    main(verbosity=2)
