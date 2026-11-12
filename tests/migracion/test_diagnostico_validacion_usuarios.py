"""QA sintetico de la clasificacion agregada: no usa usuarios reales ni DB."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "ops" / "migracion"))
from diagnostico_validacion_usuarios import diagnosticar, DiagnosticoError
from plan_usuarios import construir


def user(i, prefix="existente", username=None):
    return {"id": i, "first_name": "Nombre", "last_name": "Apellido",
            "username": username if username is not None else f"{prefix}{i}",
            "email": f"{prefix}{i}@example.test", "phone": None,
            "role": "user", "activo": 1}


class DiagnosticoImportTests(unittest.TestCase):
    def setUp(self):
        self.react = [user(i) for i in range(1, 77)]
        self.legado = [dict(x) for x in self.react] + [
            user(i, "nuevo") for i in range(77, 191)
        ]
        self.legado[76]["username"] = "ab"
        self.plan = construir(self.legado, self.react)

    def test_reporta_regla_estricta_username_sin_pii(self):
        report = diagnosticar(self.plan, self.legado, self.react)
        self.assertEqual(report["total_candidatos"], 114)
        self.assertEqual(report["motivos"]["username_fuera_regla_frontend_3_30"], 1)
        self.assertEqual(report["motivos"]["username_menos_de_3_caracteres"], 1)
        self.assertEqual(report["importacion_autorizada"], False)
        self.assertEqual(report["base_modificada"], False)
        import json
        value = json.dumps(report)
        self.assertNotIn("nuevo77", value)
        self.assertNotIn("example.test", value)

    def test_plan_alterado_bloqueado(self):
        self.plan["creaciones_propuestas_privadas"][0]["username"] = "otro"
        with self.assertRaisesRegex(DiagnosticoError, "PLAN_DIFIERE_DE_FUENTES"):
            diagnosticar(self.plan, self.legado, self.react)


if __name__ == "__main__":
    unittest.main()
