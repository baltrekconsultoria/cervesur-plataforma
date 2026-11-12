#!/usr/bin/env python3
"""Cervesur: plan SOLO USUARIOS; lee JSON privados, no DB ni DML.

Los archivos de entrada y la salida son sensibles: incluyen PII. Solo se
imprimen conteos agregados, nunca correos, nombres, IDs, passwords ni hashes.
"""
import argparse
import json
import os
import re
import stat
from collections import Counter
from pathlib import Path

SCHEMA = "cervesur-usuarios-privados-v1"
BASES = {"legado": "databalt_portalbi", "react": "cervesur_portalbi"}
FIELDS = ("id", "first_name", "last_name", "username", "email", "phone",
          "role", "activo")
EMAIL = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")


class PlanError(Exception):
    pass


def clave(x):
    return str(x or "").strip().lower()


def archivo_privado(path):
    if path.is_symlink() or not path.is_file():
        raise PlanError("ARCHIVO_INVALIDO")
    if stat.S_IMODE(path.stat().st_mode) & 0o077:
        raise PlanError("ARCHIVO_NO_PRIVADO")


def leer(path, origen):
    archivo_privado(path)
    with path.open(encoding="utf-8") as f:
        obj = json.load(f)
    if (obj.get("schema") != SCHEMA or obj.get("origin") != origen or
            obj.get("database") != BASES[origen] or
            obj.get("includes_passwords") is not False or
            not isinstance(obj.get("users"), list)):
        raise PlanError("ESQUEMA_O_ORIGEN")
    ids, emails, usernames = set(), set(), set()
    for u in obj["users"]:
        if not isinstance(u, dict) or set(u) != set(FIELDS):
            raise PlanError("CAMPOS_INESPERADOS")
        if isinstance(u["id"], bool) or not isinstance(u["id"], int) or u["id"] <= 0:
            raise PlanError("ID_INVALIDO")
        if u["id"] in ids:
            raise PlanError("ID_DUPLICADO")
        ids.add(u["id"])
        if u["activo"] not in (0, 1) or isinstance(u["activo"], bool):
            raise PlanError("ESTADO_INVALIDO")
        for k in ("first_name", "last_name", "username", "email", "role"):
            if not isinstance(u[k], str):
                raise PlanError("TIPO_INVALIDO")
        if u["phone"] is not None and not isinstance(u["phone"], str):
            raise PlanError("TIPO_INVALIDO")
        email, username = clave(u["email"]), clave(u["username"])
        # Datos faltantes van a REVISION, pero claves no unicas bloquean el plan.
        if email:
            if email in emails:
                raise PlanError("EMAIL_DUPLICADO")
            emails.add(email)
        if username:
            if username in usernames:
                raise PlanError("USERNAME_DUPLICADO")
            usernames.add(username)
    return obj["users"]


def construir(legado, react):
    dest_email = {clave(u["email"]): u for u in react if clave(u["email"])}
    dest_username = {clave(u["username"]): u for u in react if clave(u["username"])}
    creaciones, revisiones = [], []
    totales = Counter()
    for old in legado:
        email, username = clave(old["email"]), clave(old["username"])
        if email and email in dest_email:
            totales["existente_conservado"] += 1
            if username != clave(dest_email[email]["username"]) or (
                    clave(old["role"]) != clave(dest_email[email]["role"])):
                totales["existente_con_diferencia_rol_o_username"] += 1
            continue
        if not EMAIL.fullmatch(email):
            motivo = "EMAIL_INVALIDO"
        elif not username or not old["first_name"].strip() or not old["last_name"].strip():
            motivo = "DATOS_OBLIGATORIOS_AUSENTES"
        elif username in dest_username:
            motivo = "USERNAME_OCUPADO"
        elif username in dest_email or email in dest_username:
            motivo = "COLISION_CREDENCIAL_CRUZADA"
        elif clave(old["role"]) not in ("admin", "user", "usuario"):
            motivo = "ROL_DESCONOCIDO"
        elif clave(old["role"]) == "admin":
            motivo = "ADMIN_REQUIERE_APROBACION"
        else:
            motivo = "PREPARABLE"
        entry = {
            "origen_id": old["id"],
            "first_name": old["first_name"].strip(),
            "last_name": old["last_name"].strip(),
            "username": old["username"].strip(),
            "email": old["email"].strip(),
            "phone": old["phone"],
            "role_origen": old["role"],
            "activo_origen": old["activo"],
        }
        if motivo == "PREPARABLE":
            # Es una PROPUESTA, nunca un INSERT. Desactivado hasta reset seguro.
            creaciones.append({
                **entry,
                "rol_propuesto": "user",
                "activo_propuesto": 0,
                "requiere_inicializacion_credenciales": True,
            })
            totales["preparables_desactivados"] += 1
        else:
            revisiones.append({**entry, "motivo": motivo})
            totales["revision_" + motivo.lower()] += 1
    coincidentes = totales["existente_conservado"]
    pendientes = len(legado) - coincidentes
    if len(creaciones) + len(revisiones) != pendientes:
        raise PlanError("INVARIANTE_NO_CUMPLIDA")
    return {
        "schema": "cervesur-plan-usuarios-v1",
        "modo": "SIMULACION_SIN_ESCRITURAS",
        "no_contiene_passwords": True,
        "politica": "EXISTENTES_INTACTOS_ALTAS_INACTIVAS",
        "resumen": {
            "origen_total": len(legado),
            "destino_total": len(react),
            "coincidentes_conservados": coincidentes,
            "destino_no_presente_en_origen": sum(
                1 for u in react if clave(u["email"]) not in
                {clave(x["email"]) for x in legado}
            ),
            "sin_correo_coincidente": pendientes,
            "candidatos_inactivos": len(creaciones),
            "revision_requerida": len(revisiones),
            "existentes_con_diferencia_rol_o_username":
                totales["existente_con_diferencia_rol_o_username"],
            "motivos_revision": {
                k.removeprefix("revision_"): v for k, v in sorted(totales.items())
                if k.startswith("revision_")
            },
            "aplicacion_autorizada": False,
            "mysql_modificado": False,
        },
        "creaciones_propuestas_privadas": creaciones,
        "revisiones_privadas": revisiones,
    }


def guardar(path, plan):
    parent = path.parent.resolve(strict=True)
    if (not parent.is_dir() or
            stat.S_IMODE(parent.stat().st_mode) & 0o007 or
            path.is_symlink() or path.exists() or
            any(s in str(parent) for s in
                ("/public_html", "/var/www", "/htdocs", "/httpdocs", "/wwwroot"))):
        raise PlanError("RUTA_SALIDA_NO_PRIVADA_O_EXISTENTE")
    os.umask(0o077)
    with path.open("x", encoding="utf-8") as f:
        json.dump(plan, f, ensure_ascii=False, indent=2, sort_keys=True)
        f.write("\n")
    path.chmod(0o600)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--legado", type=Path, required=True)
    parser.add_argument("--react", type=Path, required=True)
    parser.add_argument("--salida", type=Path, required=True)
    args = parser.parse_args()
    try:
        plan = construir(leer(args.legado, "legado"), leer(args.react, "react"))
        guardar(args.salida, plan)
        print("CERVESUR_PLAN_USUARIOS=PASS")
        print(json.dumps(plan["resumen"], indent=2, sort_keys=True))
        print("DATOS_PERSONALES_EN_CONSOLA=NO")
    except (PlanError, OSError, KeyError, TypeError, ValueError) as e:
        code = str(e) if isinstance(e, PlanError) else type(e).__name__
        print("CERVESUR_PLAN_USUARIOS=ERROR_" + code)
        raise SystemExit(2)


if __name__ == "__main__":
    main()
