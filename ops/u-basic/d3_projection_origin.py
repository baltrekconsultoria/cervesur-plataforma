#!/usr/bin/env python3
"""D3-08: identifica el origen de proyecciones SQL dinamicas sin imprimirlas.

Alcance estrictamente estatico, SOLO LECTURA, SHA-pinned a los dos PHP reales
del inventario validado. Ningun INDICIO equivale a PASS de no-exposicion.
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
READS = ("getAll", "getById", "search", "getByCity")
FUNC = re.compile(r"\bfunction\s+([A-Za-z_]\w*)\s*\(", re.I)
SELECT = re.compile(r"\bSELECT\b(.*?)\bFROM\s+empresa\b", re.I | re.S)
DYNAMIC_HELPER = re.compile(r"\$this\s*->\s*([A-Za-z_]\w*)\s*\(")
SECRET = "powerbi_clientsecret"
SQL_STAR = re.compile(r"(?<![A-Za-z_0-9])\*(?![A-Za-z_0-9])|\b[A-Za-z_]\w*\s*\.\s*\*")
QUOTED = re.compile(r"""(['"])((?:\\.|(?!\1).)*?)\1""", re.S)


def load_verified(base: Path, expected: dict[str, str] = EXPECTED) -> dict[str, str] | None:
    if not base.is_dir() or base.is_symlink():
        return None
    root = base.resolve()
    result = {}
    for rel, sha in expected.items():
        path = base / rel
        if not path.is_file() or path.is_symlink():
            return None
        try:
            actual = path.resolve(strict=True)
            if not actual.is_relative_to(root):
                return None
            blob = path.read_bytes()
        except (OSError, RuntimeError):
            return None
        if hashlib.sha256(blob).hexdigest() != sha:
            return None
        result[rel] = blob.decode("utf-8", errors="replace")
    return result


def functions(src: str) -> dict[str, str]:
    spans = list(FUNC.finditer(src))
    return {
        hit.group(1): src[hit.end(): spans[i + 1].start() if i + 1 < len(spans) else len(src)]
        for i, hit in enumerate(spans)
    }


def helper_status(body: str | None) -> str:
    if body is None:
        return "AUSENTE"
    # We can prove only that literal quoted fragments in return statements
    # do not include a direct secret column. We DO NOT execute/evaluate PHP,
    # so this NEVER implies that the final SQL is safe.
    returns = re.findall(r"\breturn\s+(.+?);", body, re.S)
    if not returns:
        return "REVISAR_SIN_RETURN"
    ret = "\n".join(returns)
    if re.search(r"\bpowerbi_clientsecret\b", ret, re.I):
        return "REVISAR_MENCION_EXPLICITA"
    if SQL_STAR.search(ret):
        return "REVISAR_STAR"
    # Reject variables, eval, interpolation and calls which could produce
    # projected SQL as values. Only a narrow conditional on environment
    # with literal return values is recognized.
    unquoted = QUOTED.sub("''", ret)
    if "$" in unquoted or re.search(r"\b(?:eval|include|require|sprintf|implode|concat)\s*\(", unquoted, re.I):
        return "REVISAR_DINAMICO"
    # Any nested string-generation calls can create new SQL fragments:
    # allow only condition-branch predicates, not string-returning calls.
    for call in re.finditer(r"\b([A-Za-z_]\w*)\s*\(", unquoted):
        if call.group(1) not in {"app_mode", "app_mode_is_read_only", "strtolower"}:
            return "REVISAR_FUNCION"
    if re.search(r"\breturn\b", unquoted, re.I):
        return "INDICIO_LITERALES_SIN_SECRETO"
    return "REVISAR_AMBIGUO"


def audit(src: str) -> dict[str, str]:
    bodies = functions(src)
    output: dict[str, str] = {}
    statuses = {}
    for name in READS:
        body = bodies.get(name)
        if body is None:
            output[f"LECTURA_{name.upper()}"] = "REVISAR_METODO_AUSENTE"
            continue
        matches = list(SELECT.finditer(body))
        if not matches:
            output[f"LECTURA_{name.upper()}"] = "REVISAR_SELECT_NO_IDENTIFICADO"
            continue
        fragments = [m.group(1) for m in matches]
        if any(re.search(r"\b" + SECRET + r"\b", x, re.I) for x in fragments):
            output[f"LECTURA_{name.upper()}"] = "REVISAR_SECRET_EN_PROYECCION"
            continue
        if any(SQL_STAR.search(x) for x in fragments):
            output[f"LECTURA_{name.upper()}"] = "REVISAR_SELECT_STAR"
            continue
        detected_helpers = set()
        for fragment in fragments:
            detected_helpers.update(DYNAMIC_HELPER.findall(fragment))
        unquoted = [QUOTED.sub("''", fragment) for fragment in fragments]
        if any(re.search(r"\$this\s*->\s*\w+\s*\(", x) for x in unquoted):
            if len(detected_helpers) == 1:
                name_of_helper = next(iter(detected_helpers))
                status = helper_status(bodies.get(name_of_helper))
                statuses[name_of_helper] = status
                output[f"LECTURA_{name.upper()}"] = (
                    "INDICIO_HELPER_LITERAl_SIN_SECRETO" if status == "INDICIO_LITERALES_SIN_SECRETO"
                    else "REVISAR_HELPER_DINAMICO"
                )
            else:
                output[f"LECTURA_{name.upper()}"] = "REVISAR_MULTIPLES_HELPERS"
        else:
            output[f"LECTURA_{name.upper()}"] = "INDICIO_SELECT_LITERAL_SIN_SECRETO"
    output["HELPER_POWERBI_COLUMNS"] = (
        helper_status(bodies.get("powerbiColumns")) if "powerbiColumns" in bodies
        else "NO_IDENTIFICADO"
    )
    output["HELPERS_EN_PROYECCION"] = "SI" if statuses else "NO_DETECTADOS"
    return output


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--backend", default="/var/www/cervesur/backend")
    args = parser.parse_args()
    sources = load_verified(Path(args.backend))
    if sources is None:
        print("D3_08_ORIGEN=NO_GO_HUELLAS")
        return 2
    print("D3_08_ORIGEN_HUELLAS=PASS_2_DE_2")
    results = audit(sources["models/EmpresaModel.php"])
    for name in READS:
        print(f"D3_08_ORIGEN_{name.upper()}={results.get('LECTURA_' + name.upper())}")
    print(f"D3_08_POWERBI_COLUMNS={results['HELPER_POWERBI_COLUMNS']}")
    print(f"D3_08_HELPERS_EN_PROYECCION={results['HELPERS_EN_PROYECCION']}")
    print("D3_08_ORIGEN=ANALISIS_ESTATICO_NO_CONCLUYENTE")
    print("D3_08_CIERRE=PENDIENTE_QA_AISLADO")
    print("DATOS_MYSQL_LEIDOS=NO")
    print("SECRETOS_MOSTRADOS=NO")
    print("CAMBIOS_PRODUCTIVOS=NO")
    print("U_BASIC_ACTIVADO=NO")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
