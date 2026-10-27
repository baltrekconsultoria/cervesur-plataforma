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
        self.assertIn("'role' => $row['role']", text)

    def test_errors_do_not_return_upstream_or_database_details(self):
        for path in ("backend/controllers/PowerBIController.php", "backend/controllers/EmpresaController.php", "backend/lib/db.php"):
            self.assertNotIn(". $e->getMessage()", source(path))
        self.assertNotIn('response["error_description"]', source("backend/controllers/PowerBIController.php"))

if __name__ == "__main__":
    unittest.main(verbosity=2)
