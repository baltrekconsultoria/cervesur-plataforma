#!/usr/bin/env python3
"""Cervesur V1: conciliacion semantica de snapshots HMAC, SOLO LECTURA.

No abre conexion a MySQL ni ejecuta INSERT/UPDATE/DELETE. No escribe registros
individuales en los resultados. El operador debe proteger los snapshots.
"""
import argparse
import json
import os
from pathlib import Path
from collections import Counter


LISTAS = ("users", "empresas", "reportes", "empresa_usuario",
          "empresa_reporte", "user_empresa_reporte")


class PreflightError(Exception):
    pass


def leer_snapshot(path, origen):
    with open(path, encoding="utf-8") as f:
        obj = json.load(f)
    if obj.get("schema") != "cervesur-snapshot-v1":
        raise PreflightError("SCHEMA_INVALIDO")
    if obj.get("origin") != origen:
        raise PreflightError("ORIGEN_INCORRECTO_" + origen.upper())
    if not isinstance(obj.get("key_id"), str) or len(obj["key_id"]) != 16:
        raise PreflightError("HUELLA_DE_CLAVE_INVALIDA")
    for nombre in LISTAS:
        if not isinstance(obj.get(nombre), list):
            raise PreflightError("ESTRUCTURA_INVALIDA_" + nombre.upper())
    return obj


def es_activo(row):
    return int(row.get("activo", 0)) == 1


def mapa_unico(rows, campo, solo_activos=False):
    result = {}
    duplicados = 0
    for r in rows:
        if solo_activos and not es_activo(r):
            continue
        key = r.get(campo)
        if not key:  # No inventar correspondencias sin clave
            continue
        if key in result:
            duplicados += 1
        else:
            result[key] = r
    return result, duplicados


def relaciones_efectivas(snapshot):
    usuarios = {u["id"]: u for u in snapshot["users"]}
    empresas = {e["id"]: e for e in snapshot["empresas"]}
    reportes = {r["id"]: r for r in snapshot["reportes"]}
    er = {v["id"]: v for v in snapshot["empresa_reporte"]}
    eu_activos = {(v["user_id"], v["empresa_id"])
                  for v in snapshot["empresa_usuario"] if es_activo(v)}
    relaciones = set()
    invalidas = Counter()
    duplicadas = 0
    for a in snapshot["user_empresa_reporte"]:
        if not es_activo(a):
            continue
        user = usuarios.get(a["user_id"])
        empresa_reporte = er.get(a["empresa_reporte_id"])
        empresa = empresas.get(empresa_reporte["empresa_id"]) if empresa_reporte else None
        reporte = reportes.get(empresa_reporte["reporte_id"]) if empresa_reporte else None
        if not all((user, empresa_reporte, empresa, reporte)):
            invalidas["REFERENCIA_AUSENTE"] += 1
        elif not all(map(es_activo, (user, empresa_reporte, empresa, reporte))):
            invalidas["CADENA_INACTIVA"] += 1
        elif (a["user_id"], empresa_reporte["empresa_id"]) not in eu_activos:
            invalidas["SIN_VINCULO_EMPRESA"] += 1
        elif not all((user.get("email_h"), empresa.get("ruc_h"), reporte.get("ref_h"))):
            invalidas["CLAVE_NEGOCIO_AUSENTE"] += 1
        else:
            key = (user["email_h"], empresa["ruc_h"], reporte["ref_h"])
            if key in relaciones:
                duplicadas += 1
            relaciones.add(key)
    return relaciones, dict(invalidas), duplicadas


def comparar(legacy, nuevo):
    if legacy["key_id"] != nuevo["key_id"]:
        raise PreflightError("CLAVES_HMAC_DISTINTAS")
    for origin in (legacy, nuevo):
        for nombre in LISTAS:
            # La presencia de ID repetido imposibilita un mapeo fiable
            ids = [x.get("id") for x in origin[nombre]]
            if len(ids) != len(set(ids)) or None in ids:
                raise PreflightError("ID_REPETIDO_O_AUSENTE_" + nombre.upper())

    old_users, old_du = mapa_unico(legacy["users"], "email_h")
    new_users, new_du = mapa_unico(nuevo["users"], "email_h")
    old_emp, old_de = mapa_unico(legacy["empresas"], "ruc_h")
    new_emp, new_de = mapa_unico(nuevo["empresas"], "ruc_h")
    old_rep, old_dr = mapa_unico(legacy["reportes"], "ref_h")
    new_rep, new_dr = mapa_unico(nuevo["reportes"], "ref_h")

    # Verificar si username coincide pero correo no: requiere revision humana.
    new_by_username, new_un = mapa_unico(nuevo["users"], "username_h")
    posibles_colisiones = sum(
        1 for u in legacy["users"]
        if u.get("username_h") in new_by_username
        and u.get("email_h") != new_by_username[u["username_h"]].get("email_h")
    )
    viejo_activos = [u for u in legacy["users"] if es_activo(u)]
    nuevos_activos = [u for u in nuevo["users"] if es_activo(u)]
    sin_destino = [u for u in viejo_activos if u.get("email_h") not in new_users]

    old_links, old_err, old_dup = relaciones_efectivas(legacy)
    new_links, new_err, new_dup = relaciones_efectivas(nuevo)
    pendientes = old_links - new_links
    preparables = sum(
        1 for email, ruc, ref in pendientes
        if email in new_users and ruc in new_emp and ref in new_rep
        and es_activo(new_users[email]) and es_activo(new_emp[ruc])
        and es_activo(new_rep[ref])
    )
    reportes_match = old_rep.keys() & new_rep.keys()
    enlaces_diferentes = sum(
        old_rep[k].get("link_h") != new_rep[k].get("link_h")
        for k in reportes_match
    )
    bloqueos = (old_du+new_du+old_de+new_de+old_dr+new_dr+new_un
                +posibles_colisiones+old_dup+new_dup)

    return {
        "version": "cervesur-dryrun-v1",
        "modo": "SOLO_DIAGNOSTICO",
        "usuarios": {
            "origen_activos": len(viejo_activos),
            "destino_activos": len(nuevos_activos),
            "identidades_coincidentes_por_email": len(old_users.keys() & new_users.keys()),
            "activos_origen_sin_correo_coincidente": len(sin_destino),
            "coincidencia_username_con_email_distinto": posibles_colisiones,
        },
        "empresas": {
            "origen_total": len(legacy["empresas"]),
            "destino_total": len(nuevo["empresas"]),
            "coincidentes_por_ruc": len(old_emp.keys() & new_emp.keys()),
            "origen_sin_ruc_coincidente": len(old_emp.keys() - new_emp.keys()),
        },
        "reportes": {
            "origen_total": len(legacy["reportes"]),
            "destino_total": len(nuevo["reportes"]),
            "coincidentes_por_reporte_id": len(reportes_match),
            "referencias_antiguas_sin_equivalente": len(old_rep.keys() - new_rep.keys()),
            "coincidentes_con_enlace_distinto": enlaces_diferentes,
            "fuente_de_enlaces_finales": "CHECKLIST_POWERBI_ACTUALIZADO",
        },
        "asignaciones": {
            "legado_cadenas_unicas_efectivas": len(old_links),
            "nuevo_cadenas_unicas_efectivas": len(new_links),
            "coincidencias_efectivas": len(old_links & new_links),
            "legado_sin_equivalente_nuevo": len(pendientes),
            "pendientes_con_tres_entidades_existentes_y_activas": preparables,
            "pendientes_bloqueados_por_mapeo_o_estado": len(pendientes)-preparables,
            "legado_descartadas_por_cadena": old_err,
            "nuevo_descartadas_por_cadena": new_err,
            "duplicados_semanticos_legado": old_dup,
            "duplicados_semanticos_nuevo": new_dup,
        },
        "puertas": {
            "colisiones_de_claves_o_ids": bloqueos,
            "revisiones_humanas_pendientes": True,
            "qa_rol_usuario_local_403_pendiente": True,
            "fase_2b_habilitada": False,
            "migracion_productiva_autorizada": False,
            "escrituras_de_base": False,
            "puede_aplicar_migracion": False,
        },
        "privacidad": "NO_PII_NO_HASHES_NO_TOKENS_EN_REPORTE",
    }


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--legado", type=Path, required=True)
    ap.add_argument("--react", type=Path, required=True)
    ap.add_argument("--salida", type=Path)
    args = ap.parse_args()
    try:
        legacy = leer_snapshot(args.legado, "legado")
        nuevo = leer_snapshot(args.react, "react")
        result = comparar(legacy, nuevo)
        text = json.dumps(result, indent=2, ensure_ascii=False, sort_keys=True)
        if args.salida:
            if args.salida.exists() or args.salida.is_symlink():
                raise PreflightError("SALIDA_EXISTENTE")
            os.umask(0o077)
            with args.salida.open("x", encoding="utf-8") as f:
                f.write(text + "\n")
            args.salida.chmod(0o600)
        print(text)
    except (ValueError, KeyError, TypeError, OSError, PreflightError) as e:
        # Sin valores o filas en los mensajes de error
        code = str(e) if isinstance(e, PreflightError) else type(e).__name__
        print("CERVESUR_DRYRUN=ERROR_" + code)
        raise SystemExit(2)


if __name__ == "__main__":
    main()
