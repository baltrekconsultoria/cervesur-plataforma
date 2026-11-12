"""Offline QA for Cervesur U-BASIC P2. All DB and dump operations are mocked."""
from pathlib import Path
from unittest import TestCase, main, mock
import gzip
import importlib.util
import json
import os
import tempfile
from types import SimpleNamespace

spec = importlib.util.spec_from_file_location("backup_u_basic", Path(__file__).with_name("backup_u_basic.py"))
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

def stub_query(sql):
    if "information_schema.SCHEMATA" in sql:
        return "1"
    if "information_schema.TABLES" in sql and "TABLE_TYPE=" in sql:
        return "1"
    if "DATA_LENGTH" in sql:
        return "1000"
    if sql.startswith("CHECKSUM TABLE "):
        return "cervesur_portalbi.table\t123456"
    if "COUNT(*) FROM " in sql:
        return "190" if ("." + chr(96) + "user" + chr(96)) in sql else "0"
    raise AssertionError("unexpected query type")

def fake_dump(path, cmd):
    if path.exists():
        raise AssertionError("attempted overwrite")
    with gzip.open(path, "wb") as f:
        f.write(b"-- synthetic MySQL fixture\nCREATE TABLE test(id INT);\n-- Dump completed on 2026-11-12\n")
    os.chmod(path, 0o600)

class BackupTests(TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name)
        self.env = self.base / "app.env"
        self.env.write_text("CERVESUR_MODE=admin_escritura\nJWT_SECRET=fixture-dont-print\n")
        self.root = self.base / "backups"
        self.root.mkdir()
        self.ps = [
            mock.patch.object(mod, "safe_root", side_effect=self.create_fixture_root),
            mock.patch.object(mod, "ENV_FILE", self.env),
            mock.patch.object(mod, "BACKUP_ROOT", self.root / "u-basic"),
            mock.patch.object(mod, "mysql_query", side_effect=stub_query),
            mock.patch.object(mod.shutil, "which", return_value="/usr/bin/fixture"),
            mock.patch.object(mod.shutil, "disk_usage", return_value=SimpleNamespace(free=10**10)),
        ]
        for p in self.ps:
            p.start()
            self.addCleanup(p.stop)

    def create_fixture_root(self):
        self.root.joinpath("u-basic").mkdir(mode=0o700, exist_ok=True)
        self.root.joinpath("u-basic").chmod(0o700)

    def test_flags_off(self):
        value = mod.read_public_flags(self.env.read_bytes())
        self.assertEqual(value["CERVESUR_MODE"], "admin_escritura")

    def test_flag_on_rejected(self):
        with self.assertRaisesRegex(mod.Blocked, "USUARIOS_YA_ACTIVOS"):
            mod.read_public_flags(b"CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS=1\n")

    def test_sensitive_flag_on_rejected(self):
        with self.assertRaisesRegex(mod.Blocked, "SENSIBLES_ACTIVOS"):
            mod.read_public_flags(b"CERVESUR_MODE=admin_escritura\nADMIN_WRITES_USUARIOS_SENSIBLE=1\n")

    def test_mode_unknown_rejected(self):
        with self.assertRaisesRegex(mod.Blocked, "MODO_ADMIN_NO_ESPERADO"):
            mod.read_public_flags(b"CERVESUR_MODE=piloto_admin\n")

    def test_duplicate_flags_rejected(self):
        with self.assertRaisesRegex(mod.Blocked, "FLAG_DUPLICADA"):
            mod.read_public_flags(b"CERVESUR_MODE=admin_escritura\nCERVESUR_MODE=admin_escritura\n")

    def test_preflight_is_readonly_and_tables_exist(self):
        count = mod.preflight()
        self.assertEqual(count["user"], 190)
        self.assertEqual(len(count), 7)
        self.assertFalse((self.root / "u-basic").exists())

    def test_dump_commands_have_no_password_and_include_triggers(self):
        business = mod.dump_cmd(mod.BUSINESS_DB, mod.TABLES)
        state = mod.dump_cmd(mod.STATE_DB, None)
        self.assertIn("--triggers", business)
        self.assertIn("audit_log", business)
        self.assertIn("--single-transaction", state)
        self.assertNotIn("--password", " ".join(business + state))
        self.assertEqual(state[-2], "--databases")

    def test_snapshot_and_verify_integrity(self):
        with mock.patch.object(mod, "dump_gzip", side_effect=fake_dump):
            folder = mod.make_backup()
        mod.verify_backup(folder)
        self.assertEqual((folder / "manifest.json").stat().st_mode & 0o777, 0o600)
        meta = json.loads((folder / "manifest.json").read_text())
        self.assertEqual(meta["rows"]["user"], 190)
        self.assertFalse(meta["restoration_tested"])
        self.assertEqual(set(meta["archives_sha256"]), {"negocio7.sql.gz", "estado-admin.sql.gz"})
        self.assertEqual((folder / "negocio7.sql.gz").stat().st_mode & 0o777, 0o600)

    def test_tamper_detection(self):
        with mock.patch.object(mod, "dump_gzip", side_effect=fake_dump):
            folder = mod.make_backup()
        with (folder / "negocio7.sql.gz").open("ab") as f:
            f.write(b"tampered")
        with self.assertRaisesRegex(mod.Blocked, "BACKUP_SHA_DISTINTO"):
            mod.verify_backup(folder)

    def test_incomplete_dump_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / "incomplete.sql.gz"
            with gzip.open(file, "wb") as f:
                f.write(b"CREATE TABLE fake(id INT);\n")
            with self.assertRaisesRegex(mod.Blocked, "VOLCADO_SQL_INCOMPLETO"):
                mod.verified_archive(file)

    def test_concurrent_user_changes_prevent_pass(self):
        original = stub_query
        counter = {"reads": 0}
        def changing(sql):
            if "COUNT(*) FROM " in sql and ("." + chr(96) + "user" + chr(96)) in sql:
                counter["reads"] += 1
                if counter["reads"] >= 2:
                    return "191"
            return original(sql)
        with mock.patch.object(mod, "mysql_query", side_effect=changing):
            with mock.patch.object(mod, "dump_gzip", side_effect=fake_dump):
                with self.assertRaisesRegex(mod.Blocked, "TABLAS_CAMBIARON"):
                    mod.make_backup()

    def test_in_place_edits_with_same_row_count_rejected(self):
        counter = {"checksum": 0}
        def changed_checksum(sql):
            if sql.startswith("CHECKSUM TABLE "):
                counter["checksum"] += 1
                return "cervesur_portalbi.table\t" + (
                    "999999" if counter["checksum"] > 7 else "123456"
                )
            return stub_query(sql)
        with mock.patch.object(mod, "mysql_query", side_effect=changed_checksum):
            with mock.patch.object(mod, "dump_gzip", side_effect=fake_dump):
                with self.assertRaisesRegex(mod.Blocked, "TABLAS_CAMBIARON"):
                    mod.make_backup()

    def test_manifest_without_restoration_cannot_claim_restored(self):
        with mock.patch.object(mod, "dump_gzip", side_effect=fake_dump):
            folder = mod.make_backup()
        manifest = folder / "manifest.json"
        meta = json.loads(manifest.read_text())
        meta["restoration_tested"] = True
        manifest.write_text(json.dumps(meta))
        with self.assertRaisesRegex(mod.Blocked, "BACKUP_MANIFEST_INVALIDO"):
            mod.verify_backup(folder)

    def test_archive_filename_symlink_denied(self):
        with mock.patch.object(mod, "dump_gzip", side_effect=fake_dump):
            folder = mod.make_backup()
        (folder / "negocio7.sql.gz").unlink()
        (folder / "negocio7.sql.gz").symlink_to(self.env)
        with self.assertRaises(mod.Blocked):
            mod.verify_backup(folder)

if __name__ == "__main__":
    main(verbosity=2)
