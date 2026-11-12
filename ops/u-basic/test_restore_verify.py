"""Isolated P2 restoration gate tests: no real Docker or production MySQL."""
from pathlib import Path
from unittest import TestCase, mock
import gzip
import json
import os
import tempfile

import backup_u_basic as backup
import restore_verify as target

IMAGE = "sha256:" + "a" * 64


class RestoreGateTests(TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.folder = Path(self.tmp.name) / "u-basic-20261112"
        self.folder.mkdir(mode=0o700)
        self.env = Path(self.tmp.name) / "env"
        self.env.write_text("CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS=0\n")
        self.manifest = {
            "format": "cervesur-u-basic-p2-v1",
            "business_db": backup.BUSINESS_DB,
            "state_db": backup.STATE_DB,
            "business_tables": list(backup.TABLES),
            "rows": {name: (190 if name == "user" else 1) for name in backup.TABLES},
            "checksums": {name: 123456 for name in backup.TABLES},
            "archives_sha256": {"negocio7.sql.gz": "a" * 64,
                                "estado-admin.sql.gz": "b" * 64},
            "restoration_tested": False,
        }
        (self.folder / "manifest.json").write_text(json.dumps(self.manifest))
        self.patches = [
            mock.patch.object(backup, "ENV_FILE", self.env),
            mock.patch.object(backup, "BACKUP_ROOT", Path(self.tmp.name)),
            mock.patch.object(backup, "verify_backup", return_value=None),
            mock.patch.object(target.os, "geteuid", return_value=0),
        ]
        for patcher in self.patches:
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_manifest_is_exactly_pinned_to_business_tables(self):
        self.assertEqual(target.validate_manifest(self.folder), self.manifest)

    def test_unknown_database_denied(self):
        self.manifest["business_db"] = "other_db"
        (self.folder / "manifest.json").write_text(json.dumps(self.manifest))
        with self.assertRaisesRegex(target.NoGo, "BACKUP_ESQUEMA_DISTINTO"):
            target.validate_manifest(self.folder)

    def test_missing_checksum_denied(self):
        self.manifest["checksums"].pop("user")
        (self.folder / "manifest.json").write_text(json.dumps(self.manifest))
        with self.assertRaisesRegex(target.NoGo, "BACKUP_CHECKSUMS_INCOMPLETO"):
            target.validate_manifest(self.folder)

    def test_manifest_has_190_users(self):
        self.assertEqual(target.validate_manifest(self.folder)["rows"]["user"], 190)

    def test_flag_activated_denied_before_container(self):
        self.env.write_text("CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS=1\n")
        with mock.patch.object(target, "local_image_id") as image:
            with self.assertRaisesRegex(backup.Blocked, "USUARIOS_YA_ACTIVOS"):
                target.preflight(self.folder)
            image.assert_not_called()

    def test_valid_preflight_never_creates_container(self):
        with mock.patch.object(target, "local_image_id", return_value=IMAGE):
            meta, image = target.preflight(self.folder)
        self.assertEqual(image, IMAGE)
        self.assertEqual(meta["rows"]["user"], 190)

    def test_fails_on_existing_receipt(self):
        (self.folder / target.RECEIPT).write_text("{}")
        with mock.patch.object(target, "local_image_id"):
            with self.assertRaisesRegex(target.NoGo, "ENSAYO_YA_REGISTRADO"):
                target.preflight(self.folder)

    def test_only_docker_local_image_accepted(self):
        with mock.patch.object(target.shutil, "which", return_value="docker"):
            with mock.patch.object(target, "docker", side_effect=["27.5.0", IMAGE]):
                self.assertEqual(target.local_image_id(), IMAGE)

    def test_docker_malformed_image_denied(self):
        with mock.patch.object(target.shutil, "which", return_value="docker"):
            with mock.patch.object(target, "docker", side_effect=["27.5.0", "not-a-sha"]):
                with self.assertRaisesRegex(target.NoGo, "IMAGEN_MYSQL_LOCAL_INVALIDA"):
                    target.local_image_id()

    def test_count_and_checksum_pass(self):
        def answer(_container, sql):
            if sql.startswith("CHECKSUM TABLE"):
                return "test.table\t123456"
            if "EVENT_OBJECT_TABLE='audit_log'" in sql:
                return "2"
            if "TABLE_SCHEMA='" + backup.STATE_DB in sql:
                return "3"
            if "COUNT(*) FROM" in sql:
                return "190" if (chr(96)+"user"+chr(96)) in sql else "1"
            raise AssertionError(sql)
        with mock.patch.object(target, "mysql", side_effect=answer):
            self.assertEqual(target.check_tables("sample", self.manifest), (2, 3))

    def test_checksum_drift_denied(self):
        with mock.patch.object(target, "mysql", return_value="0"):
            with self.assertRaisesRegex(target.NoGo, "FILAS_RESTAURADAS"):
                target.check_tables("sample", self.manifest)

    def test_audit_triggers_required(self):
        def answer(_container, sql):
            if sql.startswith("CHECKSUM TABLE"):
                return "test.table\t123456"
            if "EVENT_OBJECT_TABLE='audit_log'" in sql:
                return "1"
            if "COUNT(*) FROM" in sql:
                return "190" if (chr(96)+"user"+chr(96)) in sql else "1"
            raise AssertionError(sql)
        with mock.patch.object(target, "mysql", side_effect=answer):
            with self.assertRaisesRegex(target.NoGo, "AUDIT_LOG_TRIGGERS_INCOMPLETOS"):
                target.check_tables("sample", self.manifest)

    def test_success_run_keeps_docker_network_none_and_cleans(self):
        commands = []
        def docker_fake(*args, timeout=40):
            commands.append(args)
            if args[0] == "inspect":
                return "none"
            return "done"
        with mock.patch.object(target, "docker", side_effect=docker_fake):
            with mock.patch.object(target, "await_mysql"):
                with mock.patch.object(target, "mysql"):
                    with mock.patch.object(target, "import_archive"):
                        with mock.patch.object(target, "check_tables", return_value=(2, 3)):
                            receipt = target.restore_isolated(self.folder, self.manifest, IMAGE)
        run = next(cmd for cmd in commands if cmd[0] == "run")
        self.assertIn("--network", run)
        self.assertEqual(run[run.index("--network")+1], "none")
        self.assertNotIn("-v", run)
        self.assertNotIn("--mount", run)
        self.assertNotIn("-p", run)
        self.assertTrue(any(cmd[0:2] == ("rm", "--force") for cmd in commands))
        self.assertTrue(receipt["restoration_tested"])
        self.assertFalse(receipt["production_mysql_modified"])

    def test_cleanup_is_mandatory_even_on_sql_failure(self):
        commands = []
        def docker_fake(*args, timeout=40):
            commands.append(args)
            if args[0] == "inspect":
                return "none"
            return "done"
        with mock.patch.object(target, "docker", side_effect=docker_fake):
            with mock.patch.object(target, "await_mysql", side_effect=target.NoGo("MYSQL_OFF")):
                with self.assertRaisesRegex(target.NoGo, "MYSQL_OFF"):
                    target.restore_isolated(self.folder, self.manifest, IMAGE)
        self.assertTrue(any(cmd[0:2] == ("rm", "--force") for cmd in commands))

    def test_private_receipt_no_overwrite(self):
        receipt = target.receipt_for(self.manifest, IMAGE, 2, 3)
        target.save_receipt(self.folder, receipt)
        file = self.folder / target.RECEIPT
        self.assertEqual(file.stat().st_mode & 0o777, 0o600)
        self.assertTrue(json.loads(file.read_text())["restoration_tested"])
        with self.assertRaisesRegex(target.NoGo, "RECIBO_EXISTENTE"):
            target.save_receipt(self.folder, receipt)


if __name__ == "__main__":
    import unittest
    unittest.main(verbosity=2)
