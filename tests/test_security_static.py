import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
def source(name):
    return (ROOT / name).read_text(encoding="utf-8")

class SecurityStaticChecks(unittest.TestCase):
    def test_powerbi_auth_before_credentials_and_provider(self):
        text = source("backend/router/PowerBIRouter.php")
        self.assertLess(text.index("require_auth("), text.index("getCredentialsEmpresaReporte("))
        self.assertLess(text.index("empty($credentials['data'])"), text.index("getAccessToken("))
        self.assertIn("exit;", text[text.index("empty($credentials['data'])"):text.index("getAccessToken(")])

    def test_powerbi_has_explicit_user_and_company_assignments(self):
        text = source("backend/models/PowerBIModel.php")
        for condition in ("uer.user_id = :user_id", "uer.activo = 1", "eu.user_id = :empresa_user_id", "eu.activo = 1", "er.activo = 1", "e.activo = 1", "r.activo = 1"):
            self.assertIn(condition, text)

    def test_company_queries_scope_and_no_secret_selection(self):
        text = source("backend/models/EmpresaModel.php")
        for method in ("getAll", "getById", "search", "getByCity"):
            start = text.index("public function " + method + "(")
            body = text[start:text.index("\n    }", start)]
            self.assertIn("visibleEmpresaSql(", body)
            self.assertNotIn("SELECT e.*", body)
            self.assertNotIn("powerbi_clientsecret", body)
        self.assertIn("eu.activo = 1", text)

    def test_mutating_routes_require_administrator(self):
        for name in ("EmpresaRouter.php", "EmpresaUsuarioRouter.php", "EmpresaReporteUsuarioRouter.php", "AsignacionRouter.php", "ReporteRouter.php"):
            text = source("backend/router/" + name)
            self.assertGreaterEqual(text.count("!== 'admin'"), 3, name)

    def test_report_reads_use_active_assignment_scope(self):
        text = source("backend/models/ReporteModel.php")
        for condition in (
            "empresa_reporte er", "empresa_usuario eu", "user_empresa_reporte uer",
            "er.activo = 1", "eu.activo = 1", "uer.activo = 1",
            "authorized_company_user_id", "authorized_report_user_id",
        ):
            self.assertIn(condition, text)
        router = source("backend/router/ReporteRouter.php")
        self.assertNotIn("$_SESSION['user_id']", router)

    def test_current_role_read_from_database(self):
        text = source("backend/lib/auth_middleware.php")
        self.assertIn("SELECT session_version, is_active, role FROM user", text)
        self.assertIn("normalize_role($row['role']", text)
        self.assertIn("'role' => $normalized_role", text)
        self.assertNotIn("$payload['role']", text)

    def test_jwt_requires_expiration_and_session_version(self):
        text = source("backend/lib/jwt.php")
        for condition in ("$payload['session_version']", "$payload['exp']", "($header['typ'] ?? null) !== 'JWT'", "($header['alg'] ?? null) !== 'HS256'"):
            self.assertIn(condition, text)
        middleware = source("backend/lib/auth_middleware.php")
        self.assertNotIn("se permite por compatibilidad con tokens antiguos", middleware)
        self.assertIn("(int)$row['session_version'] !== $payload['session_version']", middleware)

    def test_profiles_and_relationship_reads_are_scoped(self):
        profile_router = source("backend/router/UserRouter.php")
        self.assertIn("$target_user_id !== (int)$user['user_id']", profile_router)

        company_relation = source("backend/models/EmpresaUsuarioModel.php")
        self.assertIn("eu.user_id = :authorized_user_id", company_relation)
        self.assertIn("visibleScope($userId, $role", company_relation)

        report_relation = source("backend/models/EmpresaReporteUsuarioModel.php")
        for condition in ("eru.user_id = :authorized_user_id", "er.activo = 1", "e.activo = 1", "r.activo = 1", "eu.activo = 1"):
            self.assertIn(condition, report_relation)

        assignment = source("backend/models/AsignacionModel.php")
        for condition in ("user_empresa_reporte eru", "empresa_usuario eu", "eru.activo = 1", "eu.activo = 1", "authorized_assignment_user_id"):
            self.assertIn(condition, assignment)

    def test_errors_do_not_return_upstream_or_database_details(self):
        for path in ("backend/controllers/PowerBIController.php", "backend/controllers/EmpresaController.php", "backend/lib/db.php"):
            self.assertNotIn(". $e->getMessage()", source(path))
        self.assertNotIn('response["error_description"]', source("backend/controllers/PowerBIController.php"))

    def test_register_requires_administrator_and_ignores_request_roles(self):
        text = source("backend/router/auth.php")
        block = text[text.index("$uri === '/api/register'"):text.index("/api/logout")]
        self.assertIn("require_auth(", block)
        self.assertLess(block.index("require_auth("), block.index("file_get_contents('php://input')"))
        self.assertIn("!== 'admin'", block)
        controller = source("backend/controllers/RegisterController.php")
        self.assertIn("'role' => 'user'", controller)
        self.assertNotIn("$data['role']", controller)
        self.assertNotIn("'usuario'", controller)
        self.assertIn("is_active, session_version", controller)

    def test_roles_are_normalised_in_one_place(self):
        self.assertIn("normalize_role(", source("backend/lib/auth_middleware.php"))
        self.assertIn("normalize_role(", source("backend/controllers/AuthController.php"))

    def test_no_exception_message_reaches_http_responses(self):
        import re
        for path in sorted((ROOT / "backend").rglob("*.php")):
            for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
                if "getMessage()" in line and "error_log(" not in line and "public_error_message" not in line and "domain_error_message" not in line and "lib/errors.php" not in str(path).replace("\\", "/"):
                    self.fail(f"{path.relative_to(ROOT)}:{number} expone getMessage()")
                self.assertNotIn("'sqlstate'", line)
        self.assertIn("display_errors", source("backend/router/index.php"))

    def test_viewer_endpoint_requires_auth_and_is_not_cached(self):
        text = source("backend/router/ReporteRouter.php")
        block = text[text.index("/visor$/"):text.index("// GET /api/reportes/reporteid/{uuid} - Obtener reporte por reporte_id")]
        self.assertLess(block.index("require_auth("), block.index("getViewer("))
        self.assertIn("no-store", block)
        controller = source("backend/controllers/ReporteController.php")
        viewer = controller[controller.index("public function getViewer"):controller.index("public function getAll")]
        for forbidden in ("powerbi_clientsecret", "access_token", "powerbi_tenant", "getAccessToken"):
            self.assertNotIn(forbidden, viewer)
        self.assertLess(viewer.index("getByReporteId("), viewer.index("classify_powerbi_link("))

    def test_report_links_are_always_classified_before_saving(self):
        controller = source("backend/controllers/ReporteController.php")
        self.assertIn("classify_powerbi_link(", controller[controller.index("private function validateReport"):controller.index("public function create")])
        link = source("backend/lib/powerbi_link.php")
        self.assertIn("app.powerbi.com", link)
        self.assertNotIn("file_get_contents(", link)
        self.assertNotIn("curl_", link)

    def test_legacy_token_endpoint_refuses_reports_without_power_bi_ids(self):
        router = source("backend/router/PowerBIRouter.php")
        self.assertLess(router.index("grupo_id"), router.index("getAccessToken("))


class CompatInstallerIsolationChecks(unittest.TestCase):
    """UX-07D.3A: el instalador de compatibilidad (127.0.0.1:8089) no puede afectar a Flutter, al React de 8088 ni a la configuración global."""

    SCRIPTS = ("lib.sh", "instalar.sh", "pasos-instalacion.sh", "verificar.sh", "revertir.sh")

    def scripts(self):
        return {name: source("deploy/tunel-ssh-compat/scripts/" + name) for name in self.SCRIPTS}

    @staticmethod
    def code(text):
        return "\n".join(line for line in text.splitlines() if not line.lstrip().startswith("#"))

    def test_never_enables_disables_or_restarts_anything_global(self):
        for name, text in self.scripts().items():
            body = self.code(text)
            for forbidden in ("a2enmod", "a2dismod", "a2enconf", "a2disconf", "phpenmod", "phpdismod", "restart", "ufw ", "iptables", "firewall-cmd", "SET GLOBAL", "--force", "chmod -R 777"):
                self.assertNotIn(forbidden, body, f"{name} usa «{forbidden}»")

    def test_only_reloads_apache_gracefully_after_configtest(self):
        lib = self.code(source("deploy/tunel-ssh-compat/scripts/lib.sh"))
        self.assertLess(lib.index("apache2ctl configtest"), lib.index("reload apache2"))
        self.assertIn("apache2ctl graceful", lib)

    def test_recursive_deletes_only_go_through_the_exact_path_guard(self):
        for name, text in self.scripts().items():
            lines = [line for line in self.code(text).splitlines() if "rm -rf" in line or "rm -r " in line]
            if name == "revertir.sh":
                self.assertEqual(len(lines), 1, "revertir.sh debe tener un único rm -rf, dentro de borrar_exacto()")
                self.assertIn('rm -rf -- "$ruta"', lines[0])
                body = text[text.index("borrar_exacto() {"):text.index("\n}\n", text.index("borrar_exacto() {"))]
                for protected in ("$FLUTTER_DIR", "$DIR_FRONT_PRUEBA", "$DIR_BACK_PRUEBA", "$DIR_CONF_PRUEBA", "$DIR_RESPALDO_PRUEBA", "$DIR_RESPALDO"):
                    self.assertIn(protected, body)
            else:
                self.assertEqual(lines, [], f"{name} no debe borrar recursivamente")

    def test_database_changes_use_only_its_own_exact_names(self):
        for name, text in self.scripts().items():
            body = self.code(text)
            for statement in ("DROP DATABASE", "DROP USER", "CREATE DATABASE", "CREATE USER"):
                for line in (l for l in body.splitlines() if statement in l):
                    self.assertTrue("$DB_NOMBRE" in line or "$DB_USUARIO" in line, f"{name}: {line.strip()}")
            self.assertNotIn("DB_PRUEBA", body.replace("DB_PRUEBA=", "").replace("$DB_PRUEBA", "") if name != "lib.sh" else "")

    def test_resource_names_do_not_collide_with_the_other_portals(self):
        def values(path):
            import re
            return dict(re.findall(r"^([A-Z_]+)=([^\n(\$]+)$", source(path), re.M))
        old = values("deploy/tunel-ssh/scripts/lib.sh")
        new = values("deploy/tunel-ssh-compat/scripts/lib.sh")
        for key in ("SITIO", "PUERTO", "DIR_FRONT", "DIR_BACK", "DIR_CONF", "SITE_CONF", "DB_NOMBRE", "DB_USUARIO"):
            self.assertNotEqual(old[key], new[key], key)
        self.assertEqual(new["PUERTO"], "8089")
        self.assertIn("verificar_no_colision", source("deploy/tunel-ssh-compat/scripts/instalar.sh"))
        self.assertIn("verificar_no_colision", source("deploy/tunel-ssh-compat/scripts/revertir.sh"))

    def test_listens_only_on_loopback_with_its_own_session_cookie(self):
        site = source("deploy/tunel-ssh-compat/apache/cervesur-react-compat.conf")
        self.assertIn("Listen 127.0.0.1:8089", site)
        self.assertIn("<VirtualHost 127.0.0.1:8089>", site)
        self.assertNotIn("Listen 8089", site)
        common = source("deploy/tunel-ssh-compat/apache/react-compat-common.conf")
        for setting in ("session.name CERVESUR_REACT_COMPAT", "session.save_path", "open_basedir", "session.cookie_httponly on", "session.cookie_samesite Strict", "session.cookie_secure off"):
            self.assertIn(setting, common)
        self.assertEqual(common.count("php_admin_value session.name CERVESUR_REACT_COMPAT"), 2)

    def test_installer_requires_mod_headers_but_never_touches_modules(self):
        self.assertIn('modulo_apache headers', source("deploy/tunel-ssh-compat/scripts/instalar.sh"))
        self.assertNotIn("headers-habilitado-por-instalador", "".join(self.scripts().values()))

if __name__ == "__main__":
    unittest.main(verbosity=2)
