#!/usr/bin/env python3
"""Offline regression suite for the legacy K1/JWT release. No production access."""
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("legacy_policy", Path(__file__).with_name("legacy_policy.py"))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class PolicyTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "backend"
        self.home = Path(self.tmp.name) / "backups"
        self.home.mkdir()
        (self.root / "lib").mkdir(parents=True)
        (self.root / "router").mkdir(parents=True)
        self.secret = "THIS_IS_ONLY_A_SYNTHETIC_SECRET_NEVERSHARE"
        self.env = ("JWT_SECRET=" + self.secret + "\nJWT_EXPIRATION=10800\nDB_PASSWORD=only-a-fixture\n").encode()
        self.auth = b"<?php // F10-20261111 fixture\n"
        self.h1 = b"<?php // H1 fixture\n"
        self.k1 = b"<?php\nif (!defined('POWERBI_AUTH_MODE')) {\n    define('POWERBI_AUTH_MODE', 'observar');\n}\n"
        (self.root / ".env").write_bytes(self.env)
        (self.root / "lib/powerbi_authz.php").write_bytes(self.k1)
        (self.root / "router/auth.php").write_bytes(self.auth)
        (self.root / "lib/auth_middleware.php").write_bytes(self.h1)
        self.orig_auth = mod.AUTH_SHA
        self.orig_h1 = mod.H1_SHA
        mod.AUTH_SHA = mod.digest(self.auth)
        mod.H1_SHA = mod.digest(self.h1)
        self.addCleanup(self.reset_hashes)

    def reset_hashes(self):
        mod.AUTH_SHA = self.orig_auth
        mod.H1_SHA = self.orig_h1

    def test_ttl_only_target_changes_and_secrets_preserved(self):
        candidate, value = mod.ttl_candidate(self.env)
        self.assertEqual(value, "10800")
        self.assertIn(b"JWT_EXPIRATION=86400", candidate)
        self.assertIn(self.secret.encode(), candidate)
        self.assertIn(b"DB_PASSWORD=only-a-fixture", candidate)

    def test_ttl_idempotent(self):
        candidate, _ = mod.ttl_candidate(self.env)
        self.assertEqual(mod.ttl_candidate(candidate)[0], candidate)

    def test_ttl_ambiguous_rejected(self):
        with self.assertRaises(mod.GateError):
            mod.ttl_candidate(self.env + b"JWT_EXPIRATION=86400\n")

    def test_ttl_unknown_rejected(self):
        with self.assertRaises(mod.GateError):
            mod.ttl_candidate(self.env.replace(b"10800", b"1800"))

    def test_ttl_export_rejected(self):
        with self.assertRaises(mod.GateError):
            mod.ttl_candidate(self.env.replace(b"JWT_EXPIRATION=", b"export JWT_EXPIRATION="))

    def test_k1_secure_default_and_idempotent(self):
        candidate, old = mod.k1_candidate(self.k1)
        self.assertEqual(old, "observar")
        self.assertIn(b"'retirar'", candidate)
        self.assertNotIn(b"'observar'", candidate)
        self.assertEqual(mod.k1_candidate(candidate)[0], candidate)

    def test_k1_ambiguous_rejected(self):
        with self.assertRaises(mod.GateError):
            mod.k1_candidate(self.k1 + b"\ndefine('POWERBI_AUTH_MODE', 'retirar');\n")

    def test_k1_missing_guard_rejected(self):
        with self.assertRaises(mod.GateError):
            mod.k1_candidate(b"<?php\ndefine('POWERBI_AUTH_MODE', 'observar');\n")

    def test_f10_sha_pin_fails_closed(self):
        (self.root / "router/auth.php").write_bytes(b"<?php // changed\n")
        with self.assertRaises(mod.GateError):
            mod.plan(self.root)

    def test_apply_backups_permissions_and_rollback(self):
        before, after, info = mod.plan(self.root)
        self.assertEqual(info, {"jwt_current": "10800", "k1_default": "observar"})
        mod.lint_php(after["lib/powerbi_authz.php"])
        backup = mod.apply_to_root(self.root, self.home)
        self.assertTrue(backup.is_dir())
        self.assertEqual(backup.stat().st_mode & 0o777, 0o700)
        self.assertEqual((backup / ".env").stat().st_mode & 0o777, 0o600)
        self.assertEqual((backup / ".env").read_bytes(), self.env)
        self.assertEqual(mod.plan(self.root)[2], {"jwt_current": "86400", "k1_default": "retirar"})
        self.assertEqual((self.root / "router/auth.php").read_bytes(), self.auth)
        self.assertEqual((self.root / "lib/auth_middleware.php").read_bytes(), self.h1)
        with self.assertRaises(mod.GateError):
            mod.apply_to_root(self.root, self.home)
        mod.rollback_root(self.root, self.home, backup)
        self.assertEqual((self.root / ".env").read_bytes(), self.env)
        self.assertEqual((self.root / "lib/powerbi_authz.php").read_bytes(), self.k1)

    def test_rollback_rejects_any_post_deploy_drift(self):
        backup = mod.apply_to_root(self.root, self.home)
        (self.root / ".env").write_bytes(b"JWT_EXPIRATION=3600\n")
        with self.assertRaises(mod.GateError):
            mod.rollback_root(self.root, self.home, backup)

    def test_no_secret_printed_by_plan(self):
        import contextlib
        import io
        out = io.StringIO()
        with contextlib.redirect_stdout(out):
            _, _, info = mod.plan(self.root)
            print(info)
        self.assertNotIn(self.secret, out.getvalue())


if __name__ == "__main__":
    unittest.main(verbosity=2)
