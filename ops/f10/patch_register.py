#!/usr/bin/env python3
"""Parche F-10: una única guardia en POST /api/register del PHP legado.
No escribe en producción; el instalador hace la copia atómica.
"""
import argparse
import hashlib
import re
from pathlib import Path

MARKER = '// F10-20261111: only authenticated admins can create users'
ROUTE = re.compile(
    rb"if\s*\(\s*\$method\s*===\s*['\"]POST['\"]\s*&&\s*\$uri\s*===\s*['\"]/api/register['\"]\s*\)\s*\{"
)
GUARD = ("\n"
    "    " + MARKER + "\n"
    "    $f10Secret = get_jwt_secret_or_fail('/api/register');\n"
    "    $f10Actor = require_auth($f10Secret);\n"
    "    if (!is_array($f10Actor) || ($f10Actor['role'] ?? null) !== 'admin') {\n"
    "        http_response_code(403);\n"
    "        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);\n"
    "        exit;\n"
    "    }\n"
).encode('utf-8')


def patch(source: bytes, expected_sha: str) -> bytes:
    actual_sha = hashlib.sha256(source).hexdigest()
    if actual_sha != expected_sha:
        raise ValueError('F10_NO_GO_SHA_PRODUCTIVO: huella distinta')
    if MARKER.encode() in source:
        raise ValueError('F10_NO_GO_YA_PARCHEADO')
    matches = list(ROUTE.finditer(source))
    if len(matches) != 1:
        raise ValueError(f'F10_NO_GO_RUTA_POST_REGISTER: coincidencias={len(matches)}')
    current = source[matches[0].end():]
    next_route = re.search(rb"\nif\s*\(\s*\$method\s*===", current)
    block = current[:next_route.start()] if next_route else current
    if (b'require_auth(' in block or b'authenticate_user(' in block
            or b'F10-' in block):
        raise ValueError('F10_NO_GO_GUARDIA_EXISTENTE_O_CODIGO_INESPERADO')
    new = source[:matches[0].end()] + GUARD + source[matches[0].end():]
    if new.count(MARKER.encode()) != 1:
        raise ValueError('F10_NO_GO_MARCADOR_INCORRECTO')
    return new


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--input', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--expected-sha', required=True)
    args = parser.parse_args()
    source = args.input.read_bytes()
    updated = patch(source, args.expected_sha)
    if args.input.resolve() == args.output.resolve():
        raise ValueError('F10_NO_GO_NO_ESCRIBIR_ENTRADA')
    args.output.write_bytes(updated)
    print('F10_CANDIDATO=GENERADO')
    print('F10_CANDIDATO_SHA256=' + hashlib.sha256(updated).hexdigest())
    print('F10_CAMBIO=SOLO_GUARDIA_POST_REGISTER')


if __name__ == '__main__':
    try:
        main()
    except (ValueError, OSError) as exc:
        raise SystemExit(str(exc))
