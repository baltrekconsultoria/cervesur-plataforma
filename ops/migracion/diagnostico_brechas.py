#!/usr/bin/env python3
"""Cervesur: clasificacion agregada de brechas entre dos snapshots HMAC.

SOLO LECTURA de JSON locales. No SQL, no DML, no PII, hashes ni IDs de fila
en stdout. Ejecutar exclusivamente sobre snapshots privados ya validados.
"""
import argparse
import json
from collections import Counter
from pathlib import Path

from compare_snapshots import (
    PreflightError, comparar, es_activo, leer_snapshot, relaciones_efectivas,
)


def index_unico(rows, key):
    index = {}
    for row in rows:
        value = row.get(key)
        if value:
            if value in index:
                raise PreflightError("CLAVE_NEGOCIO_DUPLICADA")
            index[value] = row
    return index


def cruce_estados(origen, destino, campo):
    src, dst = index_unico(origen, campo), index_unico(destino, campo)
    estados = Counter()
    for key, old in src.items():
        new = dst.get(key)
        old_status = "activo" if es_activo(old) else "inactivo"
        new_status = ("ausente" if new is None else
                      "activo" if es_activo(new) else "inactivo")
        estados["origen_" + old_status + "_destino_" + new_status] += 1
    return {
        "origen_activo_destino_activo": estados["origen_activo_destino_activo"],
        "origen_activo_destino_inactivo": estados["origen_activo_destino_inactivo"],
        "origen_activo_destino_ausente": estados["origen_activo_destino_ausente"],
        "origen_inactivo_destino_activo": estados["origen_inactivo_destino_activo"],
        "origen_inactivo_destino_inactivo": estados["origen_inactivo_destino_inactivo"],
        "origen_inactivo_destino_ausente": estados["origen_inactivo_destino_ausente"],
        "solo_destino": len(dst.keys() - src.keys()),
        "sin_clave_origen": sum(not x.get(campo) for x in origen),
        "sin_clave_destino": sum(not x.get(campo) for x in destino),
    }


def diagnosticar(legacy, react):
    validated = comparar(legacy, react)
    if validated["puertas"]["colisiones_de_claves_o_ids"] != 0:
        raise PreflightError("COLISIONES_IMPIDEN_DIAGNOSTICO")

    users_src = index_unico(legacy["users"], "email_h")
    users_dst = index_unico(react["users"], "email_h")
    company_src = index_unico(legacy["empresas"], "ruc_h")
    company_dst = index_unico(react["empresas"], "ruc_h")
    reports_src = index_unico(legacy["reportes"], "ref_h")
    reports_dst = index_unico(react["reportes"], "ref_h")

    links_src, _, _ = relaciones_efectivas(legacy)
    links_dst, _, _ = relaciones_efectivas(react)
    pendientes = links_src - links_dst
    causa_principal = Counter()
    blockers_independientes = Counter()

    for email, ruc, ref in pendientes:
        user = users_dst.get(email)
        company = company_dst.get(ruc)
        report = reports_dst.get(ref)
        missing_user = user is None
        inactive_user = user is not None and not es_activo(user)
        missing_company = company is None
        inactive_company = company is not None and not es_activo(company)
        missing_report = report is None
        inactive_report = report is not None and not es_activo(report)

        # Cada causa independiente puede solaparse con otras.
        checks = [
            ("USUARIO_AUSENTE", missing_user),
            ("USUARIO_INACTIVO", inactive_user),
            ("EMPRESA_AUSENTE", missing_company),
            ("EMPRESA_INACTIVA", inactive_company),
            ("REPORTE_AUSENTE", missing_report),
            ("REPORTE_INACTIVO", inactive_report),
        ]
        for name, condition in checks:
            if condition:
                blockers_independientes[name] += 1
        # Una sola causa por asignacion; precedencia documentada.
        primary = next((name for name, condition in checks if condition),
                       "TRIPLE_ACTIVO_SIN_CADENA_DESTINO")
        causa_principal[primary] += 1

    enlaces = Counter()
    for ref in reports_src.keys() & reports_dst.keys():
        old, new = reports_src[ref], reports_dst[ref]
        if old.get("link_h") != new.get("link_h"):
            label = ("origen_" + ("activo" if es_activo(old) else "inactivo") +
                     "_destino_" + ("activo" if es_activo(new) else "inactivo"))
            enlaces[label] += 1

    return {
        "version": "cervesur-brechas-agregadas-v1",
        "modo": "SOLO_LECTURA_JSON_PRIVADOS",
        "usuarios_cruce_estados": cruce_estados(legacy["users"], react["users"], "email_h"),
        "empresas_cruce_estados": cruce_estados(legacy["empresas"], react["empresas"], "ruc_h"),
        "reportes_cruce_estados": cruce_estados(legacy["reportes"], react["reportes"], "ref_h"),
        "reportes_enlace_distinto_por_estado": dict(sorted(enlaces.items())),
        "asignaciones": {
            "legado_efectivas": len(links_src),
            "destino_efectivas": len(links_dst),
            "pendientes": len(pendientes),
            "causa_principal_exclusiva": dict(sorted(causa_principal.items())),
            "bloqueos_independientes_pueden_solaparse": dict(
                sorted(blockers_independientes.items())
            ),
            "suma_causas_primarias": sum(causa_principal.values()),
            "precedencia_primaria": [
                "USUARIO_AUSENTE", "USUARIO_INACTIVO",
                "EMPRESA_AUSENTE", "EMPRESA_INACTIVA",
                "REPORTE_AUSENTE", "REPORTE_INACTIVO",
                "TRIPLE_ACTIVO_SIN_CADENA_DESTINO",
            ],
        },
        "puertas": {
            "comparacion_hmac_validada": True,
            "colisiones": 0,
            "requiere_revision_humana": True,
            "migracion_productiva_autorizada": False,
            "escrituras_mysql": False,
        },
        "privacidad": "SOLO_CONTEOS_SIN_IDENTIFICADORES",
    }


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--legado", type=Path, required=True)
    p.add_argument("--react", type=Path, required=True)
    args = p.parse_args()
    try:
        legacy = leer_snapshot(args.legado, "legado")
        react = leer_snapshot(args.react, "react")
        print("CERVESUR_BRECHAS=PASS")
        print(json.dumps(diagnosticar(legacy, react), indent=2, sort_keys=True,
                         ensure_ascii=False))
    except (PreflightError, KeyError, TypeError, ValueError, OSError) as error:
        code = str(error) if isinstance(error, PreflightError) else type(error).__name__
        print("CERVESUR_BRECHAS=ERROR_" + code)
        raise SystemExit(2)


if __name__ == "__main__":
    main()
