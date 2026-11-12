#!/usr/bin/env python3
"""Cervesur D3: auditoria ESTATICA sin secretos, sin red y sin DB.

Chequeos estructurales sobre ocho PHP huellados del legado REAL.
Ningun resultado equivale a PASS funcional ni cierra D3 / P4b.
"""
from __future__ import annotations

import argparse
import hashlib
import re
from pathlib import Path

EXPECTED = {
    "lib/auth_middleware.php": "c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298",
    "router/index.php": "7add7e450c6a8d8ae50c4a659d1e3078edfd68825f624aa07ca3256e11cbd6c5",
    "controllers/EmpresaController.php": "f18078cca30013b1e1fe169b5fb727532da40fe929642db08f9ea4ec8e3a519b",
    "models/EmpresaModel.php": "a645a5e864e9afa0510b126d49a25e4af6ec82aff94fd49982206503cab2839d",
    "models/UserModel.php": "44aa41c5810a148d8c4b6ff389e19ecf84f7f546b95dfb8f80e33e961c8b4649",
    "router/StatsRouter.php": "fe6bca7920db82184e06a77a54d7740d88fe7278c0bd46769222adaf503300e3",
    "router/wiki.php": "a014201cbd4b18d7f8b1fb1217df75ac284e0052725ff2daa44c5354a9157e9c",
    "lib/db.php": "50a1ddd477cc957b522c20dd4a12d03d3b39c11acf1be62ada87b9cb190d72c1",
}
REQUIRED_ROUTERS = (
    "auth.php", "UserRouter.php", "ReporteRouter.php",
    "EmpresaRouter.php", "EmpresaUsuarioRouter.php",
    "EmpresaReporteUsuarioRouter.php", "AsignacionRouter.php",
    "PowerBIRouter.php", "StatsRouter.php", "wiki.php",
)


def load_verified(root: Path, expected: dict[str, str]) -> dict[str, str] | None:
    if not root.is_dir() or root.is_symlink():
        return None
    sources: dict[str, str] = {}
    for path, sha in expected.items():
        candidate = root / path
        if candidate.is_symlink() or not candidate.is_file():
            return None
        # Reject symlinks inside the backend tree; do not follow external paths.
        if any(part.is_symlink() for part in candidate.parents if part != Path("/")):
            return None
        try:
            content = candidate.read_bytes()
        except OSError:
            return None
        if hashlib.sha256(content).hexdigest() != sha:
            return None
        sources[path] = content.decode("utf-8", errors="replace")
    return sources


def analyze(sources: dict[str, str]) -> dict[str, str]:
    """Criterios deliberadamente conservadores. No se emite D3 PASS."""
    entry = sources["router/index.php"]
    model = sources["models/UserModel.php"]
    company = sources["models/EmpresaModel.php"]
    company_controller = sources["controllers/EmpresaController.php"]
    stats = sources["router/StatsRouter.php"]
    wiki = sources["router/wiki.php"]

    entry_method = bool(re.search(r"\$method\s*=\s*\$_SERVER\s*\[\s*['\"]REQUEST_METHOD['\"]\s*\]", entry))
    entry_path = bool(re.search(r"\$uri\s*=\s*parse_url\s*\(\s*\$_SERVER\s*\[\s*['\"]REQUEST_URI['\"]\s*\]\s*,\s*PHP_URL_PATH", entry))
    no_override = not any(x in entry for x in (
        "HTTP_X_HTTP_METHOD_OVERRIDE", "HTTP_X_METHOD_OVERRIDE", "$_REQUEST", "$_POST", "eval("
    ))
    routers_included = all(re.search(
        r"require_once\s+__DIR__\s*\.\s*['\"]/" + re.escape(name) + r"['\"]",
        entry
    ) for name in REQUIRED_ROUTERS)
    # Scope the allowedFields check specifically to updateProfile. An unrelated
    # admin updateUserRole elsewhere in this class must not create a false alarm.
    section = re.search(
        r"function\s+updateProfile\s*\([^)]*\)\s*\{(?P<body>.*?)"
        r"(?=\n\s*(?:public|protected|private)\s+function|\Z)",
        model, re.DOTALL
    )
    profile_fields: set[str] | None = None
    if section:
        fields_match = re.search(
            r"\$allowedFields\s*=\s*\[(.*?)\]\s*;", section["body"], re.DOTALL
        )
        if fields_match:
            profile_fields = set(re.findall(r"['\"]([a-z_]+)['\"]", fields_match[1]))
    safe_fields = {"first_name", "last_name", "email", "phone", "avatar"}
    profile_allowed = profile_fields is not None and profile_fields.issubset(safe_fields) and bool(profile_fields)
    profile_no_sensitive = profile_allowed and not bool({"role", "password", "is_active", "session_version"} & profile_fields)

    secret_strip = bool(re.search(
        r"unset\s*\(\s*\$empresa\s*\[\s*['\"]powerbi_clientsecret['\"]\s*\]\s*\)",
        company
    ))
    secret_read_paths = all(re.search(
        r"function\s+" + name + r"\s*\([^)]*\).*?withoutSecrets\s*\(",
        company, re.DOTALL
    ) for name in ("getAll", "getById", "search", "getByCity"))
    controller_has_model = "EmpresaModel.php" in company_controller
    stats_guard = all(x in stats for x in ("require_auth(", "get_jwt_secret_or_fail("))
    wiki_get_only_structural = "if ($method === 'GET')" in wiki and "/api/wiki" in wiki
    return {
        "D3_03_METHOD_FROM_SERVER": "INDICIO_OK" if entry_method else "REVISAR",
        "D3_03_PATH_FROM_URI": "INDICIO_OK" if entry_path else "REVISAR",
        "D3_03_NO_OVERRIDE_IN_ENTRY": "INDICIO_OK" if no_override else "REVISAR",
        "D3_07_ROUTER_IMPORTS": "INDICIO_OK" if routers_included else "REVISAR",
        "D3_07_STATS_AUTH_GUARD": "INDICIO_OK" if stats_guard else "REVISAR",
        "D3_07_WIKI_GET_BRANCH": "INDICIO_OK" if wiki_get_only_structural else "REVISAR",
        "D3_06_USER_PROFILE_ALLOWLIST": "INDICIO_OK" if profile_no_sensitive else "REVISAR",
        "D3_06_EMAIL_EDITABLE": (
            "SI_REVISAR_POLITICA" if profile_fields is not None and "email" in profile_fields
            else ("NO_EN_LISTA" if profile_fields is not None else "NO_DETERMINADO")
        ),
        "D3_08_SECRET_UNSET": "INDICIO_OK" if secret_strip else "REVISAR",
        "D3_08_SECRET_READ_PATHS": "INDICIO_OK" if secret_read_paths else "REVISAR",
        "D3_08_EMPRESA_CONTROLLER_MODEL": "INDICIO_OK" if controller_has_model else "REVISAR",
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--backend", default="/var/www/cervesur/backend")
    args = parser.parse_args()
    verified = load_verified(Path(args.backend), EXPECTED)
    if verified is None:
        print("D3_ESTRUCTURA=NO_GO_FUENTE_CAMBIADA_O_INACCESIBLE")
        print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
        return 2

    print("D3_FUENTES_SHA=PASS_8_DE_8")
    for key, value in analyze(verified).items():
        print(f"{key}={value}")
    print("D3_ANALISIS=ESTATICO_INDICATIVO_SIN_EJECUTAR_RUTAS")
    print("D3_05_CONTROLLER_RUNTIME=PENDIENTE")
    print("D3_06_PROFILE_RUNTIME=PENDIENTE")
    print("D3_07_ALIAS_RUNTIME=PENDIENTE")
    print("D3_08_SECRET_RUNTIME=PENDIENTE")
    print("D3_GLOBAL=PENDIENTE_PRUEBAS_DINAMICAS_Y_FIRMAS")
    print("DATOS_PERSONALES_LEIDOS=NO")
    print("MYSQL_PRODUCTIVO_MODIFICADO=NO")
    print("U_BASIC_ACTIVADO=NO")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
