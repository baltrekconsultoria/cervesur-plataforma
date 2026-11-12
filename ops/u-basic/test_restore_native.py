"""P2 native mysqld safety regression tests; mocks only, zero real MySQL/DB calls."""
from pathlib import Path
from types import SimpleNamespace
from unittest import TestCase, main, mock
import json
import os
import tempfile

import backup_u_basic as backup
import restore_native as target


class NativeRestoreTests(TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.folder = self.base / "u-basic-test"
        self.folder.mkdir(mode=0o700)
        self.env = self.base / "admin.env"
        self.env.write_text("CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS=0\n")
        self.manifest = {
            "rows": {name: (190 if name == "user" else 1) for name in backup.TABLES},
            "checksums": {name: 123456 for name in backup.TABLES},
            "archives_sha256": {"negocio7.sql.gz": "a"*64, "estado-admin.sql.gz": "b"*64},
        }
        (self.folder / "manifest.json").write_text(json.dumps(self.manifest))
        self.patches = [
            mock.patch.object(backup, "ENV_FILE", self.env),
            mock.patch.object(target.restore, "validate_manifest", return_value=self.manifest),
            mock.patch.object(target, "mysql_version", return_value="8.0.45"),
            mock.patch.object(target, "available_memory", return_value=6*1024**3),
            mock.patch.object(target, "uncompressed_bytes", return_value=1000),
            mock.patch.object(target.shutil, "disk_usage", return_value=SimpleNamespace(free=12*1024**3)),
            mock.patch.object(target, "TMP_PARENT", self.base),
            mock.patch.object(target.pwd, "getpwnam", return_value=SimpleNamespace(pw_uid=100, pw_gid=101)),
            mock.patch.object(target.os, "geteuid", return_value=0),
        ]
        for p in self.patches:
            p.start()
            self.addCleanup(p.stop)

    def test_preflight_existing_backup_and_resources(self):
        meta, version = target.preflight(self.folder)
        self.assertEqual(version, "8.0.45")
        self.assertEqual(meta["rows"]["user"], 190)

    def test_preflight_rejects_admin_writes_enabled(self):
        self.env.write_text("CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS=1\n")
        with self.assertRaisesRegex(backup.Blocked, "USUARIOS_YA_ACTIVOS"):
            target.preflight(self.folder)

    def test_preflight_rejects_sensitive_enabled(self):
        self.env.write_text("CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS_SENSIBLE=1\n")
        with self.assertRaisesRegex(backup.Blocked, "SENSIBLES_ACTIVOS"):
            target.preflight(self.folder)

    def test_memory_3gb_gate(self):
        with mock.patch.object(target, "available_memory", return_value=2*1024**3):
            with self.assertRaisesRegex(target.NoGo, "MEMORIA_NO_SUFICIENTE"):
                target.preflight(self.folder)

    def test_disk_gate(self):
        with mock.patch.object(target.shutil, "disk_usage", return_value=SimpleNamespace(free=1024)):
            with self.assertRaisesRegex(target.NoGo, "DISCO_NO_SUFICIENTE"):
                target.preflight(self.folder)

    def test_duplicate_receipt_prevents_import(self):
        (self.folder / target.RECEIPT).write_text("{}")
        with self.assertRaisesRegex(target.NoGo, "RECIBO_NATIVO_YA_EXISTE"):
            target.preflight(self.folder)

    def test_no_default_or_network_settings_possible(self):
        args = target.daemon_args(self.base / "cervesur-p2-qa-a1")
        self.assertEqual(args[:2], ["mysqld", "--no-defaults"])
        self.assertIn("--skip-networking", args)
        self.assertIn("--mysqlx=OFF", args)
        self.assertIn("--skip-log-bin", args)
        self.assertFalse(any(a.startswith("--port=") for a in args))
        self.assertIn("--datadir=" + str(self.base / "cervesur-p2-qa-a1" / "data"), args)

    def test_mysql_queries_are_bound_to_isolated_socket(self):
        with self.assertRaisesRegex(target.NoGo, "SOCKET_NO_AISLADO"):
            target.client(Path("/var/run/mysqld/mysqld.sock"), "SELECT 1")
        with mock.patch.object(target, "execute", return_value="1") as executor:
            ans = target.client(self.base / "cervesur-p2-qa-random" / "mysql.sock", "SELECT 1")
            self.assertEqual(ans, "1")
            argv = executor.call_args.args[0]
            self.assertEqual(argv[1], "--no-defaults")
            self.assertIn("--protocol=socket", argv)
            self.assertTrue(any(a.startswith("--socket=") and "cervesur-p2-qa-" in a for a in argv))

    def test_actual_counts_and_checksums_pass(self):
        def fake_client(_, sql, timeout=60):
            if sql.startswith("CHECKSUM TABLE"):
                return "db.table\t123456"
            if "EVENT_OBJECT_TABLE='audit_log'" in sql:
                return "2"
            if "TABLE_SCHEMA='" + backup.STATE_DB in sql:
                return "3"
            if "COUNT(*) FROM" in sql:
                return "190" if (chr(96) + "user" + chr(96)) in sql else "1"
            raise AssertionError(sql)
        with mock.patch.object(target, "client", side_effect=fake_client):
            self.assertEqual(target.verify_restored(Path("/tmp/ignored"), self.manifest), (2, 3))

    def test_mismatch_count_fails(self):
        with mock.patch.object(target, "client", return_value="0"):
            with self.assertRaisesRegex(target.NoGo, "RESTAURADO_FILAS_DISTINTAS"):
                target.verify_restored(Path("/tmp/no"), self.manifest)

    def test_trigger_count_requires_two(self):
        def fake_client(_, sql, timeout=60):
            if sql.startswith("CHECKSUM TABLE"):
                return "db.table\t123456"
            if "EVENT_OBJECT_TABLE='audit_log'" in sql:
                return "1"
            if "COUNT(*) FROM" in sql:
                return "190" if (chr(96) + "user" + chr(96)) in sql else "1"
            raise AssertionError(sql)
        with mock.patch.object(target, "client", side_effect=fake_client):
            with self.assertRaisesRegex(target.NoGo, "RESTAURADO_AUDIT_TRIGGERS_INCOMPLETO"):
                target.verify_restored(Path("/tmp/no"), self.manifest)

    def test_startup_failure_shuts_down_child_before_cleanup(self):
        qa = self.base / "cervesur-p2-qa-startup"
        qa.mkdir()
        process = mock.Mock()
        process.poll.return_value = 1
        with mock.patch.object(target.subprocess, "Popen", return_value=process):
            with mock.patch.object(target, "shutdown_daemon") as shutdown:
                with self.assertRaisesRegex(target.NoGo, "MYSQL_AISLADO_NO_INICIO"):
                    target.start_daemon(qa)
                shutdown.assert_called_once_with(process, qa)
        with mock.patch.object(target.subprocess, "Popen", return_value=process) as pop:
            with mock.patch.object(target, "shutdown_daemon"):
                with self.assertRaises(target.NoGo):
                    target.start_daemon(qa)
            kwargs = pop.call_args.kwargs
            self.assertEqual(kwargs["user"], 100)
            self.assertEqual(kwargs["group"], 101)
            self.assertTrue(kwargs["start_new_session"])

    def test_restore_failure_always_cleans_qa(self):
        temp = self.base / "cervesur-p2-qa-a"
        temp.mkdir()
        manifest = self.manifest
        with mock.patch.object(target, "private_tmp", return_value=temp):
            with mock.patch.object(target, "initialize"):
                with mock.patch.object(target, "start_daemon", return_value=mock.Mock()):
                    with mock.patch.object(target, "client"):
                        with mock.patch.object(target, "import_dump", side_effect=target.NoGo("IMPORT_FAILED")):
                            with mock.patch.object(target, "cleanup_qa") as cleanup:
                                with self.assertRaisesRegex(target.NoGo, "IMPORT_FAILED"):
                                    target.run_restore(self.folder, manifest, "8.0.45")
                                cleanup.assert_called_once()

    def test_private_success_receipt_never_overwrites(self):
        target.save_receipt(self.folder, {"restoration_tested": True})
        self.assertEqual((self.folder / target.RECEIPT).stat().st_mode & 0o777, 0o600)
        with self.assertRaisesRegex(target.NoGo, "RECIBO_YA_EXISTE"):
            target.save_receipt(self.folder, {"restoration_tested": False})


if __name__ == "__main__":
    main(verbosity=2)
