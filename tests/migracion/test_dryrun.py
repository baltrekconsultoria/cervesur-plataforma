"""Pruebas con datos inventados: nunca exportar filas reales al repositorio."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "ops" / "migracion"))
from compare_snapshots import comparar, PreflightError


def snapshot(origin, users=None, reports=None, empresas=None,
             empresa_usuario=None, empresa_reporte=None, asignaciones=None,
             key_id="0000000000000000"):
    return {
        "schema": "cervesur-snapshot-v1", "origin": origin, "key_id": key_id,
        "users": users or [], "empresas": empresas or [], "reportes": reports or [],
        "empresa_usuario": empresa_usuario or [],
        "empresa_reporte": empresa_reporte or [],
        "user_empresa_reporte": asignaciones or [],
    }


class DryrunTests(unittest.TestCase):
    def test_semantica_relaciones_ids_independientes(self):
        origen = snapshot(
            "legado",
            users=[dict(id=79, email_h="email-a", username_h="usr-a", activo=1)],
            empresas=[dict(id=5, ruc_h="ruc-a", activo=1)],
            reports=[dict(id=264, ref_h="ref-a", link_h="old-link", activo=1)],
            empresa_usuario=[dict(id=4, user_id=79, empresa_id=5, activo=1)],
            empresa_reporte=[dict(id=8, empresa_id=5, reporte_id=264, activo=1)],
            asignaciones=[dict(id=12, user_id=79, empresa_reporte_id=8, activo=1)],
        )
        nuevo = snapshot(
            "react",
            users=[dict(id=4, email_h="email-a", username_h="usr-a", activo=1)],
            empresas=[dict(id=17, ruc_h="ruc-a", activo=1)],
            reports=[dict(id=3, ref_h="ref-a", link_h="new-link", activo=1)],
            empresa_usuario=[dict(id=10, user_id=4, empresa_id=17, activo=1)],
            empresa_reporte=[dict(id=11, empresa_id=17, reporte_id=3, activo=1)],
            asignaciones=[dict(id=13, user_id=4, empresa_reporte_id=11, activo=1)],
        )
        r = comparar(origen, nuevo)
        self.assertEqual(r["usuarios"]["identidades_coincidentes_por_email"], 1)
        self.assertEqual(r["reportes"]["coincidentes_con_enlace_distinto"], 1)
        self.assertEqual(r["asignaciones"]["coincidencias_efectivas"], 1)
        self.assertEqual(r["asignaciones"]["legado_sin_equivalente_nuevo"], 0)
        self.assertFalse(r["puertas"]["puede_aplicar_migracion"])

    def test_no_restaurar_cuentas_inactivas(self):
        old = snapshot(
            "legado",
            users=[dict(id=10, email_h="a", username_h="x", activo=0)],
            empresas=[dict(id=20, ruc_h="b", activo=1)],
            reports=[dict(id=30, ref_h="c", link_h="d", activo=1)],
            empresa_usuario=[dict(id=40, user_id=10, empresa_id=20, activo=1)],
            empresa_reporte=[dict(id=50, empresa_id=20, reporte_id=30, activo=1)],
            asignaciones=[dict(id=60, user_id=10, empresa_reporte_id=50, activo=1)],
        )
        new = snapshot("react")
        r = comparar(old, new)
        self.assertEqual(r["asignaciones"]["legado_cadenas_unicas_efectivas"], 0)
        self.assertEqual(r["asignaciones"]["legado_descartadas_por_cadena"]["CADENA_INACTIVA"], 1)
        self.assertEqual(r["usuarios"]["activos_origen_sin_correo_coincidente"], 0)

    def test_clave_hmac_no_coincidente_bloquea(self):
        a = snapshot("legado", key_id="0000000000000000")
        b = snapshot("react", key_id="1111111111111111")
        with self.assertRaisesRegex(PreflightError, "CLAVES_HMAC_DISTINTAS"):
            comparar(a, b)

    def test_colision_username_requiere_revision(self):
        a = snapshot("legado", users=[
            dict(id=1, email_h="old-email", username_h="same-name", activo=1)
        ])
        b = snapshot("react", users=[
            dict(id=9, email_h="other-email", username_h="same-name", activo=1)
        ])
        r = comparar(a, b)
        self.assertEqual(r["usuarios"]["coincidencia_username_con_email_distinto"], 1)
        self.assertGreater(r["puertas"]["colisiones_de_claves_o_ids"], 0)

    def test_id_duplicado_rechazado(self):
        a = snapshot("legado", users=[
            dict(id=1, email_h="one", username_h="u1", activo=1),
            dict(id=1, email_h="two", username_h="u2", activo=1),
        ])
        with self.assertRaises(PreflightError):
            comparar(a, snapshot("react"))


if __name__ == "__main__":
    unittest.main()
