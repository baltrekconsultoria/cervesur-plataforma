#!/usr/bin/env python3
"""Cervesur: diagnostico agregado de la puerta del importador, sin SQL ni PII.

Compara el plan privado con sus dos fuentes y cuenta infracciones de la
validacion del importador. No imprime identificadores, correos ni registros.
"""
import argparse
import json
import re
import sys
from collections import Counter
from pathlib import Path

from plan_usuarios import PlanError, archivo_privado, clave, construir, leer


class DiagnosticoError(Exception):
    pass


def diagnosticar(plan, legado, react):
    esperado = construir(legado, react)
    if (plan.get("schema") != "cervesur-plan-usuarios-v1"
            or plan.get("modo") != "SIMULACION_SIN_ESCRITURAS"
            or plan.get("resumen") != esperado["resumen"]
            or plan.get("creaciones_propuestas_privadas")
            != esperado["creaciones_propuestas_privadas"]
            or plan.get("revisiones_privadas")
            != esperado["revisiones_privadas"]):
        raise DiagnosticoError("PLAN_DIFIERE_DE_FUENTES")
    propuestas = esperado["creaciones_propuestas_privadas"]
    if (len(legado) != 190 or len(react) != 76 or len(propuestas) != 114
            or esperado["resumen"]["revision_requerida"] != 0):
        raise DiagnosticoError("CONTEOS_INESPERADOS")
    de = {clave(x["email"]) for x in react}
    du = {clave(x["username"]) for x in react}
    pe = Counter(clave(x["email"]) for x in propuestas)
    pu = Counter(clave(x["username"]) for x in propuestas)
    reasons = Counter()
    for x in propuestas:
        email = clave(x["email"])
        username = clave(x["username"])
        if not re.fullmatch(r"[a-z0-9._-]{3,30}", username):
            reasons["username_fuera_regla_frontend_3_30"] += 1
        if len(username) < 3:
            reasons["username_menos_de_3_caracteres"] += 1
        if len(username) > 30:
            reasons["username_mas_de_30_caracteres"] += 1
        if not re.fullmatch(r"[a-z0-9._-]*", username):
            reasons["username_contiene_caracteres_no_permitidos"] += 1
        if not isinstance(x.get("email"), str) or not email or "@" not in email:
            reasons["email_formato_basico_invalido"] += 1
        if not str(x.get("first_name", "")).strip() or not str(x.get("last_name", "")).strip():
            reasons["nombre_o_apellido_vacio"] += 1
        for field, maximum in (("first_name", 50), ("last_name", 50),
                               ("username", 50), ("email", 100), ("phone", 20)):
            if x.get(field) is not None and len(str(x[field])) > maximum:
                reasons["excede_columna_" + field] += 1
        if pe[email] > 1:
            reasons["email_duplicado_en_propuestas"] += 1
        if pu[username] > 1:
            reasons["username_duplicado_en_propuestas"] += 1
        if email in de:
            reasons["email_ya_en_destino"] += 1
        if username in du:
            reasons["username_ya_en_destino"] += 1
        if username in de:
            reasons["username_coincide_con_email_existente"] += 1
        if email in du:
            reasons["email_coincide_con_username_existente"] += 1
        if email in pu and email != username:
            reasons["email_coincide_con_username_de_otro_nuevo"] += 1
        if username in pe and email != username:
            reasons["username_coincide_con_email_de_otro_nuevo"] += 1
        if x.get("rol_propuesto") != "user" or x.get("activo_propuesto") != 0:
            reasons["estado_o_rol_propuesto_incorrecto"] += 1
    return {
        "version": "cervesur-usuarios-check-razones-v1",
        "modo": "DIAGNOSTICO_OFFLINE",
        "fuentes_y_plan_coinciden": True,
        "total_candidatos": len(propuestas),
        "motivos": dict(sorted(reasons.items())),
        "contadores_pueden_solaparse": True,
        "datos_personales_en_salida": False,
        "base_modificada": False,
        "importacion_autorizada": False,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--legado", type=Path, required=True)
    parser.add_argument("--react", type=Path, required=True)
    parser.add_argument("--plan", type=Path, required=True)
    args = parser.parse_args()
    try:
        archivo_privado(args.plan)
        with args.plan.open(encoding="utf-8") as stream:
            plan = json.load(stream)
        result = diagnosticar(plan, leer(args.legado, "legado"), leer(args.react, "react"))
    except (PlanError, DiagnosticoError, KeyError, TypeError, ValueError, OSError) as error:
        code = str(error) if isinstance(error, (PlanError, DiagnosticoError)) else type(error).__name__
        print("CERVESUR_DIAGNOSTICO_IMPORT=ERROR_" + code)
        raise SystemExit(2)
    print("CERVESUR_DIAGNOSTICO_IMPORT=PASS")
    print(json.dumps(result, sort_keys=True, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
