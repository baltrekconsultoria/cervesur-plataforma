#!/usr/bin/env python3
"""Cervesur D3: comprobaciones de solo lectura, sin secretos en la salida.

Subcomandos:
  fuentes   Huellas SHA-256 y php -l de los 8 archivos faltantes del D3 historico.
  http      Solo GET a vhost local, con JWT de prueba introducidos sin eco.
No da un D3 global PASS: los vectores con escritura necesitan otra puerta.
"""
from __future__ import annotations

import argparse
import getpass
import hashlib
import ipaddress
import re
import shutil
import ssl
import stat
import subprocess
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import (
    HTTPSHandler, HTTPHandler, ProxyHandler, HTTPRedirectHandler, Request, build_opener
)

FILES = (
    "lib/auth_middleware.php",
    "router/index.php",
    "controllers/EmpresaController.php",
    "models/EmpresaModel.php",
    "models/UserModel.php",
    "router/StatsRouter.php",
    "router/wiki.php",
    "lib/db.php",
)
TOKEN_PATTERN = re.compile(r"^[A-Za-z0-9_\-=]+\.[A-Za-z0-9_\-=]+\.[A-Za-z0-9_\-=]+$")


def is_regular_private_path(path: Path) -> bool:
    """No seguir enlaces simbolicos en ningun componente bajo root."""
    try:
        if not path.is_file():
            return False
        for part in (path, *path.parents):
            if part.is_symlink():
                return False
        return stat.S_ISREG(path.lstat().st_mode)
    except OSError:
        return False


def fuentes(root: Path, php_binary: str = "php") -> int:
    if not root.is_dir() or root.is_symlink():
        print("D3_FUENTES=NO_GO_ROOT")
        return 2
    if shutil.which(php_binary) is None:
        print("D3_FUENTES=NO_GO_PHP_NO_DISPONIBLE")
        return 2
    total = 0
    for n, relative in enumerate(FILES, 1):
        file_path = root / relative
        if not is_regular_private_path(file_path):
            print(f"D3_F{n:02d}=NO_GO_AUSENTE_O_ENLACE")
            continue
        try:
            digest = hashlib.sha256(file_path.read_bytes()).hexdigest()
            check = subprocess.run(
                [php_binary, "-l", str(file_path)],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                timeout=15, check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            print(f"D3_F{n:02d}=NO_GO_LECTURA_O_LINT")
            continue
        if check.returncode == 0:
            print(f"D3_F{n:02d}=PASS_SHA256_{digest}")
            total += 1
        else:
            print(f"D3_F{n:02d}=NO_GO_SINTAXIS")
    print(f"D3_FUENTES_VALIDAS={total}/{len(FILES)}")
    print("D3_VEREDICTO_GLOBAL=PENDIENTE_QA_ROLES_Y_CASOS_NO_ELUSION")
    print("ESCRITURAS_MYSQL=NO_REALIZADAS")
    return 0 if total == len(FILES) else 2


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def validate_local_base(raw: str) -> str:
    p = urlsplit(raw)
    if (
        p.scheme not in ("http", "https")
        or p.hostname not in ("localhost", "127.0.0.1")
        or p.username or p.password or p.query or p.fragment
        or p.path not in ("", "/")
        or p.port not in (None, 80, 443, 8443)
    ):
        raise ValueError("solo vhost local HTTP(S), sin ruta, credenciales ni parametros")
    return raw.rstrip("/")


def build_client(base: str, ca_cert: str | None):
    parsed = urlsplit(base)
    if ca_cert and parsed.scheme != "https":
        raise ValueError("--ca-cert requiere HTTPS")
    handlers = [ProxyHandler({}), NoRedirect(), HTTPHandler()]
    if parsed.scheme == "https":
        handlers.append(HTTPSHandler(context=ssl.create_default_context(cafile=ca_cert)))
    return build_opener(*handlers)


def validate_vhost(host: str | None, base: str) -> str | None:
    """Only a private IPv4 Host header with an HTTP loopback connection."""
    if host is None:
        return None
    if urlsplit(base).scheme != "http":
        raise ValueError("--vhost solo se admite con --base-url HTTP local")
    try:
        ip = ipaddress.IPv4Address(host)
    except ipaddress.AddressValueError as exc:
        raise ValueError("--vhost debe ser una IPv4 privada sin puerto ni caracteres extra") from exc
    if not ip.is_private or ip.is_multicast or ip.is_unspecified:
        raise ValueError("--vhost debe ser una IPv4 privada")
    return str(ip)


def request_status(opener, base: str, path: str, token: str | None, vhost: str | None = None) -> int:
    headers = {"Accept": "application/json", "Cache-Control": "no-store"}
    if vhost:
        headers["Host"] = vhost
    if token:
        headers["Authorization"] = "Bearer " + token
    request = Request(base + path, headers=headers, method="GET")
    try:
        with opener.open(request, timeout=8) as response:
            return response.status
    except HTTPError as error:
        return error.code


def prompt_token(label: str) -> str:
    token = getpass.getpass(f"JWT de prueba {label} (entrada oculta, no se almacena): ").strip()
    if len(token) > 4096 or not TOKEN_PATTERN.fullmatch(token):
        raise ValueError("formato JWT no valido o longitud excesiva")
    return token


def http_lectura(
    base_url: str,
    other_id: int | None,
    ca_cert: str | None = None,
    vhost: str | None = None,
    solo_anonimos: bool = False,
) -> int:
    base = validate_local_base(base_url)
    vhost = validate_vhost(vhost, base)
    if not solo_anonimos and (other_id is None or not 1 <= other_id <= 2147483647):
        raise ValueError("--id-ajeno debe ser un ID existente y distinto del usuario normal")
    opener = build_client(base, ca_cert)
    cases = [
        ("ANON_USER", "/api/user", None, 401),
        ("ANON_USUARIOS", "/api/users", None, 401),
    ]
    result = []
    for label, path, token, expected in cases:
        observed = request_status(opener, base, path, token, vhost)
        result.append(observed == expected)
        print(f"D3_GET_{label}={'PASS' if observed == expected else 'FAIL'}_HTTP_{observed}")
    if not all(result):
        print("D3_HTTP_GET=NO_GO_ANONIMO")
        return 2

    if solo_anonimos:
        print("D3_HTTP_ANON=PASS_2_DE_2")
        print("D3_HTTP_ROLES=PENDIENTE_NO_EJECUTADO")
        print("D3_VEREDICTO_GLOBAL=PENDIENTE_QA_ROLES_Y_CASOS_NO_ELUSION")
        print("OPERACIONES_ESCRITURA=NO_REALIZADAS")
        return 0

    if not sys.stdin.isatty():
        print("D3_HTTP_GET=PENDIENTE_CONSOLA_INTERACTIVA")
        return 2
    user_token = prompt_token("NOR-T (rol normal)")
    admin_token = prompt_token("ADM-T (administrador)")
    cases = [
        ("NORMAL_PROPIO", "/api/user", user_token, 200),
        ("NORMAL_LISTADO", "/api/users", user_token, 403),
        ("NORMAL_PERFIL_AJENO", f"/api/user/profile/{other_id}", user_token, 403),
        ("ADMIN_LISTADO", "/api/users", admin_token, 200),
        ("ADMIN_PROPIO", "/api/user", admin_token, 200),
    ]
    for label, path, token, expected in cases:
        observed = request_status(opener, base, path, token, vhost)
        result.append(observed == expected)
        print(f"D3_GET_{label}={'PASS' if observed == expected else 'FAIL'}_HTTP_{observed}")
    print(f"D3_HTTP_GET={'PASS_7_DE_7' if all(result) else 'NO_GO_REVISAR_ROLES'}")
    print("D3_VEREDICTO_GLOBAL=PENDIENTE_CASOS_DE_ESCRITURA_Y_ATTESTACION")
    print("OPERACIONES_ESCRITURA=NO_REALIZADAS")
    return 0 if all(result) else 2


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Cervesur D3, solo lectura y sin secretos")
    sub = parser.add_subparsers(dest="accion", required=True)
    source_parser = sub.add_parser("fuentes")
    source_parser.add_argument("--backend", default="/var/www/cervesur/backend")
    http_parser = sub.add_parser("http")
    http_parser.add_argument("--base-url", default="http://127.0.0.1")
    http_parser.add_argument("--id-ajeno", type=int, default=None)
    http_parser.add_argument(
        "--solo-anonimos", action="store_true",
        help="Solo dos GET 401 anonimos; no solicita JWT ni IDs",
    )
    http_parser.add_argument("--ca-cert", default=None)
    http_parser.add_argument("--vhost", default=None, help="IPv4 privada del ServerName de Apache, sin cambiar la IP de conexion")
    args = parser.parse_args(argv)
    try:
        if args.accion == "fuentes":
            return fuentes(Path(args.backend))
        return http_lectura(args.base_url, args.id_ajeno, args.ca_cert, args.vhost, args.solo_anonimos)
    except (ValueError, URLError, OSError, TimeoutError, ssl.SSLError, EOFError, KeyboardInterrupt) as exc:
        # No imprimir detalles de excepciones: algunas librerias incluyen URLs/cabeceras.
        print("D3=NO_GO_ENTORNO_O_CREDENCIALES")
        return 2


if __name__ == "__main__":
    sys.exit(main())
