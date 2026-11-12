"""Real native second-mysqld integration with synthetic data ONLY (runner/VM).

No production backup, no production socket, no Docker, no MySQL TCP ports.
Requires root, OS mysql account, mysql-server / mysqld and mysql client installed.
"""
from pathlib import Path
import gzip
import json
import os
import pwd
import subprocess
import tempfile
import unittest

import backup_u_basic as backup
import restore_native as native
import restore_verify as restore


@unittest.skipUnless(os.environ.get("CERVESUR_NATIVE_INTEGRATION") == "1",
                     "Native MySQL8 integration opt-in")
class NativeMySQL8Fixture(unittest.TestCase):
    def test_real_second_mysqld_sql_import_and_checksum(self):
        self.assertEqual(os.geteuid(), 0)
        version = native.mysql_version()
        with tempfile.TemporaryDirectory(prefix="cervesur-p2-itest-") as t:
            parent = Path(t)
            backup_root = parent / "backups"
            backup_root.mkdir(mode=0o700)
            folder = backup_root / "u-basic-synthetic"
            folder.mkdir(mode=0o700)
            account = pwd.getpwnam("mysql")
            qa = native.private_tmp(account.pw_uid, account.pw_gid)
            process = None
            try:
                try:
                    native.initialize(qa)
                except native.NoGo:
                    # CI uses ONLY synthetic data. Show filtered init diagnostics
                    # to identify an OS/AppArmor or MySQL option incompatibility.
                    f = qa / "init-diagnostic.log"
                    if f.is_file():
                        for line in f.read_text(errors="replace").splitlines()[-12:]:
                            if "ERROR" in line or "MY-" in line:
                                print("MYSQL_FIXTURE_INIT_DIAGNOSTICO=" + line[-260:])
                    else:
                        print("MYSQL_FIXTURE_INIT_LOG=NO_CREAT")
                    raise
                process = native.start_daemon(qa)
                sock = qa / "mysql.sock"
                bdb, sdb = backup.BUSINESS_DB, backup.STATE_DB
                quote = chr(96)
                native.client(sock, "CREATE DATABASE " + quote + bdb + quote)
                native.client(sock, "CREATE DATABASE " + quote + sdb + quote)
                for table in backup.TABLES:
                    src = quote + bdb + quote + "." + quote + table + quote
                    native.client(sock, "CREATE TABLE " + src +
                                  " (id INT PRIMARY KEY, note VARCHAR(20)) ENGINE=InnoDB")
                    native.client(sock, "INSERT INTO " + src + " VALUES (1,'fixture')")
                for action in ("UPDATE", "DELETE"):
                    table = quote + bdb + quote + "." + quote + "audit_log" + quote
                    native.client(sock,
                        "CREATE TRIGGER " + quote + bdb + quote + "." +
                        quote + "audit_no_" + action.lower() + quote +
                        " BEFORE " + action + " ON " + table +
                        " FOR EACH ROW SIGNAL SQLSTATE '45000' "
                        "SET MESSAGE_TEXT='immutable'")
                native.client(sock, "CREATE TABLE " + quote + sdb + quote +
                              ".state(id INT PRIMARY KEY, note VARCHAR(20)) ENGINE=InnoDB")
                native.client(sock, "INSERT INTO " + quote + sdb +
                              ".state VALUES (1,'fixture')")

                def dump(db: str, tables: tuple[str, ...] | None) -> bytes:
                    cmd = ["mysqldump", "--no-defaults", "--protocol=socket",
                           "--socket=" + str(sock), "-uroot",
                           "--single-transaction", "--quick", "--hex-blob",
                           "--no-tablespaces", "--set-gtid-purged=OFF",
                           "--triggers", "--routines=false", "--events=false"]
                    cmd += (["--databases", db] if tables is None else [db, *tables])
                    p = subprocess.run(cmd, stdout=subprocess.PIPE,
                                       stderr=subprocess.DEVNULL, timeout=90)
                    self.assertEqual(p.returncode, 0)
                    return p.stdout
                archives = {
                    "negocio7.sql.gz": dump(bdb, backup.TABLES),
                    "estado-admin.sql.gz": dump(sdb, None)
                }
                for name, sql in archives.items():
                    f = folder / name
                    with f.open("xb") as out:
                        with gzip.GzipFile(fileobj=out, mode="wb", mtime=0) as gz:
                            gz.write(sql)
                    f.chmod(0o600)

                rows = {}
                checksums = {}
                for table in backup.TABLES:
                    src = quote + bdb + quote + "." + quote + table + quote
                    rows[table] = int(native.client(sock, "SELECT COUNT(*) FROM " + src))
                    checksums[table] = int(native.client(
                        sock, "CHECKSUM TABLE " + src).split("\t")[1])
                manifest = {
                    "format": "cervesur-u-basic-p2-v1",
                    "business_db": bdb, "state_db": sdb,
                    "business_tables": list(backup.TABLES),
                    "rows": rows, "checksums": checksums,
                    "archives_sha256": {
                        name: backup.sha_file(folder / name)
                        for name in archives
                    },
                    "restoration_tested": False,
                    "users_write_flag": "OFF",
                    "sensitive_write_flag": "OFF"
                }
                mf = folder / "manifest.json"
                mf.write_text(json.dumps(manifest), encoding="utf-8")
                mf.chmod(0o600)
            finally:
                native.cleanup_qa(qa, process)

            old_root = backup.BACKUP_ROOT
            backup.BACKUP_ROOT = backup_root
            try:
                metadata = restore.validate_manifest(folder)
                receipt = native.run_restore(folder, metadata, version)
                self.assertEqual(receipt["tables_verified"], 7)
                self.assertTrue(receipt["checksums_equal"])
                self.assertEqual(receipt["audit_triggers_restored"], 2)
                self.assertEqual(receipt["admin_state_tables_restored"], 1)
                self.assertTrue(receipt["restoration_tested"])
                native.save_receipt(folder, receipt)
                self.assertEqual((folder / native.RECEIPT).stat().st_mode & 0o777, 0o600)
                self.assertEqual(json.loads((folder / native.RECEIPT).read_text())["tables_verified"], 7)
            finally:
                backup.BACKUP_ROOT = old_root


if __name__ == "__main__":
    unittest.main(verbosity=2)
