"""Solo datos inventados. Prueba plan de usuarios sin SQL ni secretos."""
import contextlib
import io
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "ops" / "migracion"))
from plan_usuarios import PlanError, construir, leer, guardar


def user(id, email, username, role="user", activo=1,
         first="Nombre", last="Apellido"):
    return {"id": id, "first_name": first, "last_name": last,
            "email": email, "username": username, "phone": None,
            "role": role, "activo": activo}


def payload(origin, users):
    return {"schema": "cervesur-usuarios-privados-v1",
            "origin": origin,
            "database": "databalt_portalbi" if origin == "legado" else "cervesur_portalbi",
            "includes_passwords": False, "users": users}


class UsuariosTests(unittest.TestCase):
    def test_preserva_existentes_y_no_autoriza_activaciones(self):
        old = [
            user(1, "existente@test.example", "ex", activo=1),
            user(2, "nuevo@test.example", "nuevo", role="usuario"),
            user(3, "admin@test.example", "admin", role="admin"),
            user(4, "otro@test.example", "ocupado"),
        ]
        new = [
            user(44, "existente@test.example", "ex"),
            user(45, "destino@test.example", "ocupado"),
        ]
        plan = construir(old, new)
        self.assertEqual(plan["resumen"]["coincidentes_conservados"], 1)
        self.assertEqual(plan["resumen"]["sin_correo_coincidente"], 3)
        self.assertEqual(plan["resumen"]["candidatos_inactivos"], 1)
        self.assertEqual(plan["resumen"]["revision_requerida"], 2)
        self.assertEqual(plan["resumen"]["destino_no_presente_en_origen"], 1)
        self.assertEqual(plan["creaciones_propuestas_privadas"][0]["activo_propuesto"], 0)
        self.assertEqual(plan["creaciones_propuestas_privadas"][0]["rol_propuesto"], "user")
        self.assertEqual({x["motivo"] for x in plan["revisiones_privadas"]},
                         {"USERNAME_OCUPADO", "ADMIN_REQUIERE_APROBACION"})
        self.assertFalse(plan["resumen"]["aplicacion_autorizada"])
        self.assertFalse(plan["resumen"]["mysql_modificado"])
        self.assertNotIn("password", json.dumps(plan["creaciones_propuestas_privadas"]))

    def test_privado_0600_no_sobrescribir_y_validacion_origen(self):
        with tempfile.TemporaryDirectory() as folder:
            old = Path(folder) / "usuarios-legado.json"
            new = Path(folder) / "usuarios-react.json"
            out = Path(folder) / "plan.json"
            old.write_text(json.dumps(payload("legado", [user(1, "a@test.example", "a")])),"utf-8")
            new.write_text(json.dumps(payload("react", [])),"utf-8")
            os.chmod(old, 0o600)
            os.chmod(new, 0o600)
            legacy = leer(old, "legado")
            target = leer(new, "react")
            plan = construir(legacy, target)
            guardar(out, plan)
            self.assertEqual(out.stat().st_mode & 0o777, 0o600)
            with self.assertRaises(PlanError):
                guardar(out, plan)
            os.chmod(new, 0o644)
            with self.assertRaisesRegex(PlanError, "ARCHIVO_NO_PRIVADO"):
                leer(new, "react")
            os.chmod(new, 0o600)
            with self.assertRaisesRegex(PlanError, "ESQUEMA_O_ORIGEN"):
                leer(new, "legado")

    def test_origen_duplicado_detiene(self):
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / "usuarios-legado.json"
            p.write_text(json.dumps(payload("legado", [
                user(1, "a@test.example", "a"),
                user(2, "a@test.example", "b")])), "utf-8")
            os.chmod(p, 0o600)
            with self.assertRaisesRegex(PlanError, "EMAIL_DUPLICADO"):
                leer(p, "legado")


if __name__ == "__main__":
    unittest.main()
