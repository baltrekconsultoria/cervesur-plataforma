import contextlib
import io
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import d3_readonly_gate as gate


class TestGate(unittest.TestCase):
    def test_local_host_only(self):
        for url in ("http://localhost", "https://127.0.0.1:8443", "http://127.0.0.1:80"):
            self.assertEqual(gate.validate_local_base(url), url)
        for url in (
            "https://external.example", "http://169.254.169.254",
            "http://127.0.0.1:1234", "http://user:pass@localhost",
            "http://localhost/api/users", "http://localhost?token=x",
            "file:///etc/passwd", "http://localhost#fragment"
        ):
            with self.assertRaises(ValueError):
                gate.validate_local_base(url)

    def test_private_vhost_and_header_injection_rejected(self):
        self.assertEqual(
            gate.validate_vhost("192.168.10.15", "http://127.0.0.1"),
            "192.168.10.15",
        )
        self.assertIsNone(gate.validate_vhost(None, "http://127.0.0.1"))
        for host in (
            "8.8.8.8",
            "192.168.10.15:80",
            "192.168.10.15\\r\\nX-Injected: yes",
            "untrusted.example",
            "0.0.0.0",
        ):
            with self.assertRaises(ValueError):
                gate.validate_vhost(host, "http://127.0.0.1")
        with self.assertRaises(ValueError):
            gate.validate_vhost("192.168.10.15", "https://127.0.0.1:8443")

    def test_http_get_uses_configured_vhost(self):
        output = io.StringIO()
        statuses = [401, 401, 200, 403, 403, 200, 200]
        with (
            patch("d3_readonly_gate.request_status", side_effect=statuses) as call,
            patch("d3_readonly_gate.sys.stdin.isatty", return_value=True),
            patch("d3_readonly_gate.prompt_token", side_effect=["a.b.c", "d.e.f"]),
            contextlib.redirect_stdout(output),
        ):
            code = gate.http_lectura("http://127.0.0.1", 999, vhost="192.168.10.15")
        self.assertEqual(code, 0)
        self.assertTrue(all(c.args[-1] == "192.168.10.15" for c in call.call_args_list))
        self.assertIn("D3_HTTP_GET=PASS_7_DE_7", output.getvalue())

    def test_anon_only_does_not_ask_jwt_or_id(self):
        output = io.StringIO()
        with (
            patch("d3_readonly_gate.request_status", side_effect=[401, 401]) as requests,
            patch("d3_readonly_gate.prompt_token") as prompt,
            patch("d3_readonly_gate.sys.stdin.isatty", return_value=False),
            contextlib.redirect_stdout(output),
        ):
            status = gate.http_lectura(
                "http://127.0.0.1",
                None,
                vhost="192.168.10.15",
                solo_anonimos=True,
            )
        self.assertEqual(status, 0)
        self.assertEqual(requests.call_count, 2)
        prompt.assert_not_called()
        self.assertIn("D3_HTTP_ANON=PASS_2_DE_2", output.getvalue())
        self.assertIn("D3_HTTP_ROLES=PENDIENTE_NO_EJECUTADO", output.getvalue())

    def test_anon_only_fails_closed_on_404(self):
        output = io.StringIO()
        with (
            patch("d3_readonly_gate.request_status", side_effect=[404, 404]),
            patch("d3_readonly_gate.prompt_token") as prompt,
            contextlib.redirect_stdout(output),
        ):
            status = gate.http_lectura(
                "http://127.0.0.1", None,
                vhost="192.168.10.15", solo_anonimos=True
            )
        self.assertEqual(status, 2)
        prompt.assert_not_called()
        self.assertIn("D3_HTTP_GET=NO_GO_ANONIMO", output.getvalue())

    def test_id_necessary_only_for_role_checks(self):
        with self.assertRaises(ValueError):
            gate.http_lectura("http://127.0.0.1", None)

    def test_source_missing_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            output = io.StringIO()
            with patch("d3_readonly_gate.shutil.which", return_value="/usr/bin/php"), contextlib.redirect_stdout(output):
                code = gate.fuentes(Path(directory))
            self.assertEqual(code, 2)
            self.assertIn("D3_FUENTES_VALIDAS=0/8", output.getvalue())

    def test_symlink_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "actual.php").write_text("<?php\n")
            (root / "link.php").symlink_to(root / "actual.php")
            self.assertFalse(gate.is_regular_private_path(root / "link.php"))

    def test_http_missing_tty_is_fail_closed(self):
        output = io.StringIO()
        with (
            patch("d3_readonly_gate.request_status", return_value=401),
            patch("d3_readonly_gate.sys.stdin.isatty", return_value=False),
            contextlib.redirect_stdout(output),
        ):
            code = gate.http_lectura("http://127.0.0.1", 123)
        self.assertEqual(code, 2)
        self.assertIn("PENDIENTE_CONSOLA_INTERACTIVA", output.getvalue())

    def test_http_never_post_and_403_required(self):
        output = io.StringIO()
        statuses = [401, 401, 200, 403, 403, 200, 200]
        with (
            patch("d3_readonly_gate.request_status", side_effect=statuses) as call,
            patch("d3_readonly_gate.sys.stdin.isatty", return_value=True),
            patch("d3_readonly_gate.prompt_token", side_effect=["a.b.c", "d.e.f"]),
            contextlib.redirect_stdout(output),
        ):
            code = gate.http_lectura("http://127.0.0.1", 999)
        self.assertEqual(code, 0)
        self.assertEqual(call.call_count, 7)
        self.assertIn("D3_HTTP_GET=PASS_7_DE_7", output.getvalue())
        self.assertIn("OPERACIONES_ESCRITURA=NO_REALIZADAS", output.getvalue())

    def test_user_200_when_should_403_fails(self):
        statuses = [401, 401, 200, 200, 403, 200, 200]
        with (
            patch("d3_readonly_gate.request_status", side_effect=statuses),
            patch("d3_readonly_gate.sys.stdin.isatty", return_value=True),
            patch("d3_readonly_gate.prompt_token", side_effect=["a.b.c", "d.e.f"]),
            contextlib.redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(gate.http_lectura("http://127.0.0.1", 999), 2)


if __name__ == "__main__":
    unittest.main()
