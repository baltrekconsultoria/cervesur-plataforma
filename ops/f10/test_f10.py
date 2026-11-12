#!/usr/bin/env python3
"""QA aislada: la guardia protege solo POST /api/register, sin BD."""
from pathlib import Path
import tempfile
import subprocess
import hashlib
import os
from patch_register import patch

SAMPLE = b'''<?php
$method = 'POST';
$uri = '/api/register';
function get_jwt_secret_or_fail($context) { return 'fixture-only'; }
function require_auth($secret) {
  $role = getenv('F10_TEST_ROLE');
  if ($role === 'NONE') { http_response_code(401); echo 'AUTH401'; exit; }
  return ['role' => $role];
}
if ($method === 'POST' && $uri === '/api/register') {
    echo 'REGISTER_REACHED';
    exit;
}
'''
sha = hashlib.sha256(SAMPLE).hexdigest()
changed = patch(SAMPLE, sha)
assert changed.count(b'F10-20261111') == 1
with tempfile.TemporaryDirectory() as tmp:
    f = Path(tmp) / 'fixture.php'
    f.write_bytes(changed)
    lint = subprocess.run(['php', '-l', str(f)], capture_output=True, text=True)
    assert lint.returncode == 0, lint.stderr
    for role, expected, should_register in [
        ('NONE', 'AUTH401', False),
        ('user', 'Acceso denegado', False),
        ('usuario', 'Acceso denegado', False),
        ('admin', 'REGISTER_REACHED', True),
    ]:
        proc = subprocess.run(['php', str(f)], capture_output=True, text=True, env={**os.environ, 'F10_TEST_ROLE':role})
        assert proc.returncode == 0, proc.stderr
        assert expected in proc.stdout, (role, proc.stdout)
        assert ('REGISTER_REACHED' in proc.stdout) == should_register, role
        print('F10_QA_ROLE_' + role.upper() + '=PASS')
    try:
        patch(changed, hashlib.sha256(changed).hexdigest())
        raise AssertionError('acepto doble parche')
    except ValueError:
        print('F10_QA_IDEMPOTENCIA_FAIL_CLOSED=PASS')
    try:
        patch(SAMPLE, '0' * 64)
        raise AssertionError('acepto SHA incorrecta')
    except ValueError:
        print('F10_QA_SHA_GATE=PASS')
print('F10_QA_SINTETICO=PASS_6_DE_6')
print('F10_QA_DB=NO_CONECTADA')
