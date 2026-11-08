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
                if "getMessage()" in line and "error_log(" not in line and "public_error_message" not in line and "lib/errors.php" not in str(path).replace("\\", "/"):
                    self.fail(f"{path.relative_to(ROOT)}:{number} expone getMessage()")
                self.assertNotIn("'sqlstate'", line)
        self.assertIn("display_errors", source("backend/router/index.php"))

if __name__ == "__main__":
    unittest.main(verbosity=2)
