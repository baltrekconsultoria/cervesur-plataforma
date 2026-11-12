"""End-to-end Docker MySQL 8 restore check using only synthetic SQL.

Run with CERVESUR_RESTORE_INTEGRATION=1 and a locally cached mysql:8.0 image.
No network port, no production MySQL, no real backup, no host database mounts.
"""
from __future__ import annotations
import gzip
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest
import uuid

import backup_u_basic as backup
import restore_verify as restore


@unittest.skipUnless(os.environ.get("CERVESUR_RESTORE_INTEGRATION") == "1",
                     "Docker integration opt-in")
class SyntheticMySQL8Restore(unittest.TestCase):
    def test_real_offline_import_and_checksums(self):
        image = restore.local_image_id()
        name = "cervesur-p2-fixture-" + uuid.uuid4().hex[:12]
        with tempfile.TemporaryDirectory() as temp:
            backup_root = Path(temp)
            folder = backup_root / "u-basic-synthetic"
            folder.mkdir(mode=0o700)
            previous_root = backup.BACKUP_ROOT
            backup.BACKUP_ROOT = backup_root
            try:
                restore.docker(
                    "run", "--detach", "--rm", "--name", name,
                    "--network", "none", "--log-driver", "none",
                    "--security-opt", "no-new-privileges",
                    "--cap-drop", "NET_RAW",
                    "--memory", "2g", "--pids-limit", "256",
                    "--tmpfs", "/var/lib/mysql:rw,nosuid,nodev,size=1536m",
                    "--tmpfs", "/var/run/mysqld:rw,nosuid,nodev,size=64m",
                    "--tmpfs", "/tmp:rw,nosuid,nodev,size=128m",
                    "--env", "MYSQL_ALLOW_EMPTY_PASSWORD=yes",
                    image, timeout=90,
                )
                try:
                    restore.await_mysql(name)
                    backtick = chr(96)
                    biz = backup.BUSINESS_DB
                    state = backup.STATE_DB
                    restore.mysql(name, "CREATE DATABASE " + backtick + biz + backtick)
                    restore.mysql(name, "CREATE DATABASE " + backtick + state + backtick)
                    for table in backup.TABLES:
                        restore.mysql(name,
                            "CREATE TABLE " + backtick + biz + backtick + "." +
                            backtick + table + backtick +
                            " (id INT PRIMARY KEY, payload VARCHAR(20)) ENGINE=InnoDB")
                        restore.mysql(name,
                            "INSERT INTO " + backtick + biz + backtick + "." +
                            backtick + table + backtick +
                            " VALUES (1,'synthetic')")
                    for verb in ("UPDATE", "DELETE"):
                        restore.mysql(name,
                            "CREATE TRIGGER audit_no_" + verb.lower() +
                            " BEFORE " + verb + " ON " + backtick + biz +
                            backtick + ".audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' "
                            "SET MESSAGE_TEXT='immutable'")
                    restore.mysql(name,
                        "CREATE TABLE " + backtick + state + backtick +
                        ".sessions (id INT PRIMARY KEY, sample VARCHAR(20))")
                    restore.mysql(name,
                        "INSERT INTO " + backtick + state +
                        backtick + ".sessions VALUES (1,'fixture')")

                    def dump(db, tables=None):
                        cmd = [
                            "docker", "exec", name, "mysqldump",
                            "--protocol=socket", "-uroot",
                            "--single-transaction", "--quick",
                            "--set-gtid-purged=OFF", "--no-tablespaces",
                            "--triggers", "--routines=false", "--events=false"
                        ]
                        if tables is None:
                            cmd += ["--databases", db]
                        else:
                            cmd += [db, *tables]
                        p = subprocess.run(cmd, stdout=subprocess.PIPE,
                                           stderr=subprocess.DEVNULL, check=False, timeout=90)
                        self.assertEqual(p.returncode, 0)
                        return p.stdout

                    paths = {
                        "negocio7.sql.gz": dump(biz, backup.TABLES),
                        "estado-admin.sql.gz": dump(state, None)
                    }
                    for filename, data in paths.items():
                        file = folder / filename
                        with file.open("wb") as out:
                            with gzip.GzipFile(fileobj=out, mode="wb", mtime=0) as gz:
                                gz.write(data)
                        file.chmod(0o600)
                    counts = {}
                    checksums = {}
                    for table in backup.TABLES:
                        counts[table] = restore.read_integer(restore.mysql(
                            name, "SELECT COUNT(*) FROM " +
                            backtick + biz + backtick + "." + backtick + table + backtick
                        ), table)
                        cells = restore.mysql(
                            name, "CHECKSUM TABLE " + backtick + biz + backtick +
                            "." + backtick + table + backtick
                        ).split("\t")
                        checksums[table] = int(cells[1])
                    manifest = {
                        "format": "cervesur-u-basic-p2-v1",
                        "business_db": biz, "state_db": state,
                        "business_tables": list(backup.TABLES),
                        "rows": counts, "checksums": checksums,
                        "archives_sha256": {
                            filename: backup.sha_file(folder / filename)
                            for filename in paths
                        },
                        "restoration_tested": False,
                        "users_write_flag": "OFF",
                        "sensitive_write_flag": "OFF"
                    }
                    manifest_file = folder / "manifest.json"
                    manifest_file.write_text(json.dumps(manifest))
                    manifest_file.chmod(0o600)
                finally:
                    restore.docker("rm", "--force", name)

                meta = restore.validate_manifest(folder)
                receipt = restore.restore_isolated(folder, meta, image)
                self.assertEqual(receipt["business_tables_checked"], 7)
                self.assertEqual(receipt["audit_triggers_restored"], 2)
                self.assertGreater(receipt["admin_state_tables_restored"], 0)
                self.assertTrue(receipt["all_business_checksums_equal"])
                self.assertTrue(receipt["restoration_tested"])
                restore.save_receipt(folder, receipt)
                self.assertEqual((folder / restore.RECEIPT).stat().st_mode & 0o777, 0o600)
            finally:
                backup.BACKUP_ROOT = previous_root


if __name__ == "__main__":
    unittest.main(verbosity=2)
