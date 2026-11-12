"""Regresion sintetica para brechas; nunca incluir snapshots ni HMAC reales."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "ops" / "migracion"))
from diagnostico_brechas import diagnosticar
from compare_snapshots import PreflightError


def snap(origin, users, reports, triples=None):
    triples = triples or []
    return {
        "schema": "cervesur-snapshot-v1", "origin": origin,
        "key_id": "0000000000000000",
        "users": users,
        "empresas": [{"id": 1, "ruc_h": "ruc-a", "activo": 1}],
        "reportes": reports,
        "empresa_usuario": [
            {"id": i + 10, "user_id": u["id"], "empresa_id": 1, "activo": 1}
            for i, u in enumerate(users)
        ],
        "empresa_reporte": [
            {"id": i + 20, "empresa_id": 1, "reporte_id": r["id"], "activo": 1}
            for i, r in enumerate(reports)
        ],
        "user_empresa_reporte": [
            {"id": i + 30, "user_id": uid, "empresa_reporte_id": rid + 19,
             "activo": 1}
            for i, (uid, rid) in enumerate(triples)
        ],
    }


class BrechasTests(unittest.TestCase):
    def test_blockers_primarios_no_dobles_y_sin_identificadores(self):
        old = snap(
            "legado",
            [
                {"id": 1, "email_h": "email-a", "username_h": "u1", "activo": 1},
                {"id": 2, "email_h": "email-b", "username_h": "u2", "activo": 1},
                {"id": 3, "email_h": "email-c", "username_h": "u3", "activo": 1},
            ],
            [{"id": 1, "ref_h": "rep-a", "link_h": "antiguo", "activo": 1},
             {"id": 2, "ref_h": "rep-b", "link_h": "antiguo2", "activo": 1}],
            [(1, 1), (2, 1), (3, 1), (1, 2)],
        )
        new = snap(
            "react",
            [{"id": 10, "email_h": "email-a", "username_h": "u1", "activo": 1},
             {"id": 30, "email_h": "email-c", "username_h": "u3", "activo": 0}],
            [{"id": 10, "ref_h": "rep-a", "link_h": "nuevo", "activo": 1}],
        )
        result = diagnosticar(old, new)
        a = result["asignaciones"]
        self.assertEqual(a["pendientes"], 4)
        self.assertEqual(a["suma_causas_primarias"], 4)
        for reason in ("USUARIO_AUSENTE", "USUARIO_INACTIVO",
                       "REPORTE_AUSENTE", "TRIPLE_ACTIVO_SIN_CADENA_DESTINO"):
            self.assertEqual(a["causa_principal_exclusiva"][reason], 1)
        self.assertEqual(result["reportes_enlace_distinto_por_estado"][
            "origen_activo_destino_activo"], 1)
        self.assertEqual(result["usuarios_cruce_estados"][
            "origen_activo_destino_ausente"], 1)
        self.assertFalse(result["puertas"]["migracion_productiva_autorizada"])
        import json
        output = json.dumps(result)
        for forbidden in ("email-a", "ruc-a", "rep-a", "antiguo", "nuevo"):
            self.assertNotIn(forbidden, output)

    def test_clave_incompatible_detenida(self):
        old = snap("legado", [], [])
        new = snap("react", [], [])
        new["key_id"] = "1111111111111111"
        with self.assertRaisesRegex(PreflightError, "CLAVES_HMAC_DISTINTAS"):
            diagnosticar(old, new)


if __name__ == "__main__":
    unittest.main()
