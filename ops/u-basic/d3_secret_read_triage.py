#!/usr/bin/env python3
"""D3-08: triaje estructural de exposicion del secreto Power BI.

Solo lectura del modelo real, con SHA-256 fijado. No imprime fragmentos
de codigo, valores, datos personales ni secreto. No da PASS global D3.
"""
from __future__ import annotations

import argparse
import hashlib
import re
from pathlib import Path

EXPECTED = {
    "models/EmpresaModel.php": "a645a5e864e9afa0510b126d49a25e4af6ec82aff94fd49982206503cab2839d",
    "controllers/EmpresaController.php": "f18078cca30013b1e1fe169b5fb727532da40fe929642db08f9ea4ec8e3a519b",
}
READ_METHODS = ("getAll", "getById", "search", "getByCity")
SECRET = "powerbi_clientsecret"
FUNCTION = re.compile(r"\bfunction\s+([A-Za-z_]\w*)\s*\(", re.I)
SELECT = re.compile(r"\bSELECT\b(.*?)\bFROM\s+empresa\b", re.I | re.S)
CLEAR_UNSET = re.compile(
    r"\bunset\s*\(\s*\$[A-Za-z_]\w*\s*\[\s*['\"]powerbi_clientsecret['\"]\s*\]\s*\)", re.I
)
# Detect callback or direct sanitizer references. Merely mentioning its
# name is never sufficient for a PASS.
CLEAN_CALL = re.compile(
    r"(?:->\s*withoutSecrets\s*\(|['\"]withoutSecrets['\"])", re.I
)
DYNAMIC = re.compile(r"\$[A-Za-z_]|\{|\}|\b(?:CONCAT|COALESCE)\s*\(", re.I)
STAR = re.compile(r"(?<!\w)\*|\b[A-Za-z_]\w*\s*\.\s*\*")
WRITE_LIKE = re.compile(r"\b(?:INSERT|UPDATE|DELETE)\b", re.I)


def read_verified(root: Path, expected: dict[str, str] = EXPECTED) -> dict[str, str] | None:
    if not root.is_dir() or root.is_symlink():
        return None
    out = {}
    for rel, expected_hash in expected.items():
        file = root / rel
        if not file.is_file() or file.is_symlink():
            return None
        try:
            resolved = file.resolve(strict=True)
            base = root.resolve(strict=True)
            if not resolved.is_relative_to(base):
                return None
            data = file.read_bytes()
        except (OSError, RuntimeError):
            return None
        if hashlib.sha256(data).hexdigest() != expected_hash:
            return None
        out[rel] = data.decode("utf-8", "replace")
    return out


def method_segments(code: str) -> dict[str, str]:
    """Limit each indicator to one method, not the rest of the file."""
    matches = list(FUNCTION.finditer(code))
    out = {}
    for i, match in enumerate(matches):
        end = matches[i + 1].start() if i + 1 < len(matches) else len(code)
        out[match.group(1)] = code[match.end():end]
    return out


def classify_reads(model: str) -> dict[str, str]:
    methods = method_segments(model)
    sanitizer = methods.get("withoutSecrets", "")
    filters_secret = bool(CLEAR_UNSET.search(sanitizer))
    results: dict[str, str] = {}
    for name in READ_METHODS:
        body = methods.get(name)
        if body is None:
            results[name] = "REVISAR_METODO_AUSENTE"
            continue
        # A method with several queries needs ALL result projections accounted for.
        projections = [m.group(1) for m in SELECT.finditer(body)]
        if not projections:
            results[name] = "REVISAR_SQL_NO_IDENTIFICADO"
            continue
        if WRITE_LIKE.search(body):
            results[name] = "REVISAR_MEZCLA_OPERACIONES"
            continue
        has_dynamic = any(DYNAMIC.search(p) or STAR.search(p) for p in projections)
        selects_secret = any(re.search(r"\b" + SECRET + r"\b", p, re.I) for p in projections)
        proven_filter = filters_secret and bool(CLEAN_CALL.search(body))
        # A direct selected secret with no verified local scrubber is a
        # review trigger, NOT a confirmed leak (another sanitizer may exist).
        if selects_secret and not proven_filter:
            results[name] = "REVISAR_SELECCION_SECRETO"
        elif has_dynamic and not proven_filter:
            results[name] = "REVISAR_PROYECCION_DINAMICA"
        elif proven_filter:
            results[name] = "INDICIO_FILTRADO_VERIFICADO"
        elif not selects_secret and not has_dynamic:
            results[name] = "INDICIO_SELECCION_EXCLUYE_SECRETO"
        else:
            results[name] = "REVISAR_NO_DETERMINADO"
    results["FILTRO_UNSET"] = "DETECTADO" if filters_secret else "NO_DETECTADO"
    results["FILTRO_METODO"] = "DETECTADO" if sanitizer else "NO_DETECTADO"
    return results


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--backend", default="/var/www/cervesur/backend")
    args = ap.parse_args()
    src = read_verified(Path(args.backend))
    if src is None:
        print("D3_08=NO_GO_HUELLA_O_ACCESO")
        print("CAMBIOS_PRODUCTIVOS=NO")
        return 2
    print("D3_08_HUELLAS=PASS_2_DE_2")
    results = classify_reads(src["models/EmpresaModel.php"])
    for name in READ_METHODS:
        print(f"D3_08_LECTURA_{name.upper()}={results[name]}")
    print(f"D3_08_SANEAMIENTO_UNSET={results['FILTRO_UNSET']}")
    print(f"D3_08_SANEAMIENTO_METODO={results['FILTRO_METODO']}")
    print("D3_08_CONTROLLER_PRESENTE=SI")
    print("D3_08_DIAGNOSTICO=INDICATIVO_NO_VERIFICA_HTTP_NI_FLUJO_DINAMICO")
    print("D3_08_CIERRE=PENDIENTE_VERIFICACION_FUNCIONAL")
    print("DATOS_BD_LEIDOS=NO")
    print("SECRETOS_PUBLICADOS=NO")
    print("CAMBIOS_PRODUCTIVOS=NO")
    print("U_BASIC_ACTIVADO=NO")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
