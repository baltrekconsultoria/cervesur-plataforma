#!/usr/bin/env python3
"""Cervesur JWT fallback inventory. Read only. Never prints JWT literals or .env.

Scans only legacy backend PHP in router/, controllers/ and lib/ for the exact
D4-06b getenv('JWT_SECRET') ?: 'fallback' pattern. Emits filenames, line
numbers, SHA256, enclosing function, and assignment variable only.
Does NOT print source lines, secret values, SQL, credentials or tokens.
Does NOT modify production and refuses a count different from the approved 9.
"""
from __future__ import annotations
import argparse
import hashlib
import os
from pathlib import Path
import re
import sys

ROOT = Path("/var/www/cervesur/backend")
SUBDIRS = ("router", "controllers", "lib")
FALLBACK = re.compile(r"""JWT_SECRET['"]\s*\)\s*\?:\s*(['"])(.*?)\1""", re.S)
FUNC = re.compile(r"\bfunction\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(")
ASSIGN = re.compile(r"\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*$")
EXPECTED = 9

class NoGo(Exception):
    pass

def file_items(root: Path):
    for sub in SUBDIRS:
        directory = root / sub
        if directory.is_symlink() or not directory.is_dir():
            raise NoGo("DIRECTORIO_INVALIDO_" + sub)
        for path in sorted(directory.glob("*.php")):
            if path.is_symlink() or not path.is_file():
                raise NoGo("ARCHIVO_INVALIDO_" + path.name)
            yield path

def scan(root: Path) -> list[dict]:
    if root.is_symlink() or not root.is_dir():
        raise NoGo("BACKEND_INVALIDO")
    findings = []
    for path in file_items(root):
        raw = path.read_bytes()
        data = raw.decode("utf-8", errors="replace")
        matches = list(FALLBACK.finditer(data))
        if not matches:
            continue
        name = path.relative_to(root).as_posix()
        digest = hashlib.sha256(raw).hexdigest()
        for match in matches:
            line_num = data.count("\n", 0, match.start()) + 1
            preceding = data[:match.start()]
            start_line = preceding.rsplit("\n", 1)[-1]
            context = FUNC.findall(preceding)
            # The match starts on JWT_SECRET, after the preceding getenv( quote.
            # Strip only this syntactic prefix so we identify the assignment safely.
            assignment_prefix = re.sub(r"""getenv\s*\(\s*[\x27\x22]\s*$""", "", start_line)
            v = ASSIGN.search(assignment_prefix)
            findings.append({
                "file": name,
                "sha256": digest,
                "line": line_num,
                "function": context[-1] if context else "global",
                "assignment": v.group(1) if v else "expression",
                # Never materialize or persist the matched fallback literal.
            })
    return sorted(findings, key=lambda x: (x["file"], x["line"]))

def report(findings: list[dict], expected: int = EXPECTED) -> int:
    print("=== CERVESUR: INVENTARIO JWT FALLBACKS SOLO LECTURA ===")
    print("PATRON=getenv(JWT_SECRET) TERNARIO FALLBACK LITERAL")
    print("LITERALES_MOSTRADOS=NO")
    print("ARCHIVOS_AFECTADOS=" + str(len({x["file"] for x in findings})))
    for i, f in enumerate(findings, 1):
        print("CASO_{:02d}={}#L{} FUNCION={} VARIABLE={} SHA256={}".format(
            i, f["file"], f["line"], f["function"], f["assignment"], f["sha256"]))
    print("JWT_FALLBACKS_DETECTADOS=" + str(len(findings)))
    print("JWT_FALLBACKS_ESPERADOS=" + str(expected))
    print("CAMBIOS_PRODUCTIVOS=NO")
    print("U_BASIC_ACTIVADO=NO")
    if len(findings) != expected:
        print("INVENTARIO=NO_GO_CONTEO_INESPERADO")
        return 2
    print("INVENTARIO=PASS_SOLO_LECTURA")
    return 0

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--root", default=str(ROOT))
    p.add_argument("--expected", type=int, default=EXPECTED)
    args = p.parse_args()
    if args.expected < 1 or args.expected > 100:
        raise NoGo("CONTEO_OBJETIVO_INVALIDO")
    if os.geteuid() != 0:
        raise NoGo("REQUIERE_SUDO")
    return report(scan(Path(args.root)), args.expected)

if __name__ == "__main__":
    try:
        sys.exit(main())
    except (NoGo, OSError, UnicodeError) as exc:
        print("JWT_INVENTARIO_NO_GO=" + str(exc), file=sys.stderr)
        sys.exit(2)
