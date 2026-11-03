import pathlib
import re
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
        # La lógica del alta vive en register_handler.php (compartida con admin_escritura): exige administrador ANTES de leer el cuerpo.
        self.assertLess(block.index("require_auth("), block.index("handle_register_request("))
        handler = source("backend/lib/register_handler.php")
        self.assertIn("!== 'admin'", handler)
        self.assertLess(handler.index("!== 'admin'"), handler.index("file_get_contents('php://input')"))
        controller = source("backend/controllers/RegisterController.php")
        # El rol de un alta nunca sale de la petición: lo fija new_user_role() (por defecto 'user'; 'usuario' solo por configuración del servidor).
        self.assertIn("'role' => new_user_role()", controller)
        self.assertIn("getenv('NEW_USER_ROLE') === 'usuario' ? 'usuario' : 'user'", source("backend/lib/roles.php"))
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


class ReadOnlyModeStaticChecks(unittest.TestCase):
    """UX-07D.3A: el modo de solo lectura es fail-closed y no puede degradarse por un cambio descuidado."""

    WRITE_SQL = re.compile(r"\b(INSERT\s+INTO|UPDATE\s+[`\w]+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|CREATE\s+(TABLE|DATABASE|USER)|ALTER\s+TABLE|DROP\s+|TRUNCATE|GRANT\s)", re.I)
    # Únicos archivos del backend que contienen SQL de escritura. Todos quedan detrás de la puerta de rutas, de la guardia de SQL y de la cuenta de solo lectura.
    EXPECTED_WRITERS = {
        "backend/controllers/AuthController.php", "backend/controllers/RegisterController.php", "backend/models/AsignacionModel.php", "backend/models/EmpresaModel.php",
        "backend/models/EmpresaReporteUsuarioModel.php", "backend/models/EmpresaUsuarioModel.php", "backend/models/ReporteModel.php", "backend/models/UserModel.php", "backend/router/auth.php",
        # UX-08B: capa común de escrituras administrativas (transacciones + audit_log). Se distribuye en la versión 1 pero NO es alcanzable: en piloto_admin la puerta de rutas
        # (app_mode_route_gate) rechaza todo método distinto de GET/login/logout y GuardedPDO rechaza todo SQL que no sea SELECT (ver AdminPilotWriteLayerIsUnreachable).
        "backend/lib/write_support.php",
        # UX-08H: la guarda SQL de la cuenta de escritura de «admin_escritura» (solo existe en ese modo; la acota AdminEscrituraStaticChecks).
        "backend/lib/admin_escritura.php",
    }

    def backend_files(self):
        return [p for p in (ROOT / "backend").rglob("*.php") if p.name != "wiki.php"]

    def test_inventory_of_files_that_write_is_closed(self):
        writers = {p.relative_to(ROOT).as_posix() for p in self.backend_files() if self.WRITE_SQL.search(p.read_text(encoding="utf-8"))}
        # lib/mode.php solo MENCIONA las palabras para rechazarlas (expresiones regulares y mensajes), no las ejecuta
        writers.discard("backend/lib/mode.php")
        # lib/estado_piloto.php escribe SOLO en la base de estado propia del piloto (cuenta aislada, catálogo cerrado); lo acota PilotoUsuariosStaticChecks
        writers.discard("backend/lib/estado_piloto.php")
        self.assertEqual(writers, self.EXPECTED_WRITERS, "apareció (o desapareció) un archivo con SQL de escritura: revisar la matriz de endpoints de solo lectura")

    def test_new_read_only_code_never_writes(self):
        for name in ("backend/controllers/DiagnosticoController.php", "backend/router/DiagnosticoRouter.php", "backend/lib/inventario_esperado.php", "backend/lib/diagnostico_catalogo.php"):
            self.assertIsNone(self.WRITE_SQL.search(source(name)), name)

    def test_no_select_star_in_backend(self):
        star = re.compile(r"SELECT\s+\*|SELECT\s+[a-z]{1,3}\.\*|,\s*[a-z]{1,3}\.\*\s*(,|FROM)", re.I)
        offenders = [p.relative_to(ROOT).as_posix() for p in self.backend_files() if star.search(p.read_text(encoding="utf-8"))]
        self.assertEqual(offenders, [], "SELECT * impide los permisos por columna y puede exponer columnas sensibles")

    def test_mode_is_decided_before_connecting_and_before_any_route(self):
        db = source("backend/lib/db.php")
        self.assertLess(db.index("app_mode_boot()"), db.index("getenv('DB_HOST')"))
        self.assertLess(db.index("app_mode_boot()"), db.index("new GuardedPDO"))
        self.assertIn("SET SESSION TRANSACTION READ ONLY", db)
        self.assertIn("app_mode_assert_readonly_account", db)
        index = source("backend/router/index.php")
        gate = index.index("app_mode_route_gate(")
        for later in ("/api/ping", "DiagnosticoRouter.php", "/auth.php", "UserRouter.php", "ReporteRouter.php", "PowerBIRouter.php"):
            self.assertLess(gate, index.index(later), later)

    def test_session_writes_are_skipped_in_read_only(self):
        auth = source("backend/controllers/AuthController.php")
        self.assertLess(auth.index("app_mode_is_read_only()"), auth.index("$this->updateLastLogin($user['id'])"))
        route = source("backend/router/auth.php")
        logout = route[route.index("'/api/logout'"):]
        self.assertLess(logout.index("app_mode_is_read_only()"), logout.index("UPDATE user SET session_version"))

    def test_mode_defaults_are_fail_closed(self):
        mode = source("backend/lib/mode.php")
        self.assertIn("'cervesur_portalbi'", mode)
        for refusal in ("app_mode_refuse(", "http_response_code(503)"):
            self.assertIn(refusal, mode)
        self.assertIn("hash_equals", source("backend/router/DiagnosticoRouter.php"))

    def test_diagnostic_controller_reads_only_the_minimum_columns(self):
        text = source("backend/controllers/DiagnosticoController.php") + source("backend/lib/diagnostico_catalogo.php")
        code = re.sub(r"/\*.*?\*/", "", text, flags=re.S)  # los comentarios pueden mencionar lo que NO se lee
        for forbidden in (r"\bpassword\b", r"\bemail\b", r"\bphone\b", r"\benlace\b", r"powerbi", r"\bnombre\b", r"(?<![\w.])reporte_id\b", r"\bgrupo_id\b", r"\bfirst_name\b", r"\busername\b", r"SELECT\s+\*"):
            self.assertNotRegex(code, forbidden)

    def test_metrics_are_the_same_in_inventory_diagnostics_and_preflight(self):
        names = re.findall(r"'(\w+)' => \d+,", source("backend/lib/inventario_esperado.php"))
        self.assertEqual(len(names), 27)
        controller = source("backend/lib/diagnostico_catalogo.php")
        preflight = source("database/schema/UX-07D3A-preflight-produccion-solo-lectura.sql")
        for name in names:
            self.assertIn(f"'{name}'", controller, name)
            self.assertIn(f"'{name}'", preflight, name)

    def test_admin_procedure_is_separate_column_level_and_secret_free(self):
        directory = ROOT / "database" / "schema" / "admin-requiere-autorizacion"
        files = sorted(directory.glob("*.sql"))
        self.assertEqual([f.name for f in files], ["01-cuenta-diagnostico.sql", "02-verificar-privilegios-diagnostico.sql", "02b-verificar-privilegios-piloto.sql", "03-cuenta-piloto-autenticado.sql", "04-verificar-ausencia-de-escrituras.sql", "99-revocar-cuenta-diagnostico.sql", "99-revocar-cuenta-piloto.sql"])
        for f in files:
            text = f.read_text(encoding="utf-8")
            body = "\n".join(line for line in text.splitlines() if not line.lstrip().startswith("--"))
            self.assertIn("AUTORIZACI", "\n".join(text.splitlines()[:3]).upper(), f"{f.name} debe declarar en su encabezado que requiere autorización")
            for grant in re.findall(r"^GRANT .*$", body, re.M):
                self.assertRegex(grant, r"^GRANT SELECT \(", f.name)
                self.assertNotRegex(grant, r"INSERT|UPDATE|DELETE|ALL|GRANT OPTION|CREATE|DROP|ALTER|EXECUTE")
            self.assertNotRegex(body, r"IDENTIFIED BY '(?!__CLAVE_)")
        self.assertFalse(list((ROOT / "database" / "migrations").glob("*3A*")), "ninguna migración nueva: el piloto no cambia el esquema")
        pilot = (directory / "03-cuenta-piloto-autenticado.sql").read_text(encoding="utf-8")
        self.assertNotRegex(pilot, r"GRANT SELECT \([^)]*powerbi_")
        diag = (directory / "01-cuenta-diagnostico.sql").read_text(encoding="utf-8")
        granted = {m.group(2): [c.strip() for c in m.group(1).split(",")] for m in re.finditer(r"^GRANT SELECT \(([^)]*)\) ON `__BASE__`\.`(\w+)`", diag, re.M)}
        self.assertEqual(granted, {
            "user": ["id", "role", "is_active", "session_version"], "empresa": ["id", "activo"], "reporte": ["id", "activo"],
            "empresa_usuario": ["empresa_id", "user_id", "activo"], "empresa_reporte": ["id", "empresa_id", "reporte_id", "activo"],
            "user_empresa_reporte": ["user_id", "empresa_reporte_id", "activo"]}, "la cuenta de diagnóstico solo recibe estas columnas (reporte_id de empresa_reporte es el id numérico de la relación)")

    # ------------------------------------------------------------------ UX-07D.3A.1
    def test_sql_guard_is_not_fooled_by_with_or_comments(self):
        mode = source("backend/lib/mode.php")
        guard = mode[mode.index("function app_sql_guard"):mode.index("function app_sql_catalog")]
        # el verbo permitido es SELECT (y dos sentencias de control exactas); WITH, SHOW genérico y los comentarios se rechazan
        self.assertNotRegex(guard, r"\(SELECT\|SHOW\|WITH\)")
        self.assertIn(r"SELECT\b", guard)
        self.assertIn(r"\bwith\b", guard)  # WITH (CTE) figura entre las construcciones rechazadas dentro de un SELECT
        for needle in ("/*", "--", "#", "SHOW GRANTS FOR CURRENT_USER", "SET SESSION TRANSACTION READ ONLY"):
            self.assertIn(needle, guard, needle)

    def test_diagnostic_mode_runs_only_the_closed_catalog(self):
        db = source("backend/lib/db.php")
        self.assertIn("useCatalog(diagnostico_catalogo_sentencias())", db)
        self.assertLess(db.index("app_mode_assert_readonly_account"), db.index("useCatalog("))
        self.assertIn("MYSQL_ATTR_INIT_COMMAND", db)
        self.assertIn("app_mode_verify_connection", db)
        controller = source("backend/controllers/DiagnosticoController.php")
        code = re.sub(r"/\*.*?\*/", "", controller, flags=re.S)
        # el controlador no contiene SQL propio: todo viene del catálogo
        self.assertNotRegex(code, r"->(query|prepare|exec)\(\s*[\x27\"]")
        self.assertNotRegex(code, r"\bSELECT\b")
        index = source("backend/router/index.php")
        diag = index[index.index("DiagnosticoRouter.php"):]
        self.assertLess(diag.index("exit;"), diag.index("auth.php"), "tras el router de diagnóstico no debe alcanzarse ningún otro router")
        self.assertIn("'diagnostico', 'piloto_visual'", index.split("session_start")[0], "los modos diagnóstico y piloto no abren sesión PHP")

    def test_required_mode_and_database_name_checks(self):
        mode = source("backend/lib/mode.php")
        self.assertIn("CERVESUR_REQUIRED_MODE", mode)
        self.assertIn("[A-Za-z0-9_$-]{1,64}", mode)
        gate = mode[mode.index("function app_mode_route_gate"):mode.index("function app_sql_guard")]
        diag = gate[gate.index("if ($mode === \x27diagnostico\x27)"):]
        self.assertNotIn("rtrim", diag.split("return [\x27status\x27 => 403")[0], "en diagnóstico la ruta se compara EXACTA, sin normalizar")
        self.assertNotIn("HEAD", diag.split("return [\x27status\x27 => 403")[0])

    def test_published_diagnostic_sql_is_exactly_the_catalog(self):
        catalog = source("backend/lib/diagnostico_catalogo.php")
        published = source("database/schema/UX-07D3A1-consultas-cuenta-diagnostica.sql")
        statements = [s.strip() for s in re.sub(r"^\s*--.*$", "", published, flags=re.M).split(";") if s.strip()]
        self.assertEqual(len(statements), 27 + 3 + 1)
        self.assertIn("SET SESSION TRANSACTION READ ONLY", statements)
        self.assertTrue(all(s.startswith(("SELECT", "SHOW", "SET SESSION TRANSACTION READ ONLY")) for s in statements))
        self.assertEqual(len(re.findall(r"'visibilidad' => \$[pc]\]", catalog)), 27)
        # el preflight del OPERADOR declara que no lo ejecuta la cuenta de diagnóstico
        preflight = source("database/schema/UX-07D3A-preflight-produccion-solo-lectura.sql")
        self.assertIn("OPERADOR", preflight[:900].upper())
        self.assertIn("cervesur_ro_diag", preflight[:900])

    def test_catalog_columns_match_the_account_grants(self):
        # Toda columna que cite el catálogo debe estar concedida y toda columna concedida debe citarse (la prueba Docker comprueba además la necesidad con MySQL real)
        catalog = source("backend/lib/diagnostico_catalogo.php")
        sqls = " ".join(re.findall(r"\x27sql\x27 => (.+?), \x27visibilidad\x27", catalog)) + " " + " ".join(re.findall(r"\$(?:effective|vigentes) = (.+?);", catalog, re.S))
        diag = (ROOT / "database" / "schema" / "admin-requiere-autorizacion" / "01-cuenta-diagnostico.sql").read_text(encoding="utf-8")
        for table, columns in re.findall(r"^GRANT SELECT \(([^)]*)\) ON `__BASE__`\.`(\w+)`", diag, re.M)[:0]:
            pass
        granted = {m.group(2): [c.strip() for c in m.group(1).split(",")] for m in re.finditer(r"^GRANT SELECT \(([^)]*)\) ON `__BASE__`\.`(\w+)`", diag, re.M)}
        self.assertEqual(sum(len(v) for v in granted.values()), 18)
        for table, columns in granted.items():
            self.assertIn(table, sqls, table)
            for column in columns:
                self.assertRegex(sqls, r"\b" + column + r"\b", f"{table}.{column} se concede pero el catálogo no lo usa")

class ReadOnlyInstallerStaticChecks(unittest.TestCase):
    """UX-07D.3B: el instalador del diagnóstico 8090 no puede degradarse (aislamiento, MySQL intacto, nada de reinicios ni de módulos)."""

    DIR = ROOT / "deploy" / "tunel-ssh-readonly"

    def scripts(self):
        return sorted((self.DIR / "scripts").glob("*.sh"))

    @staticmethod
    def code(text):
        return "\n".join(line for line in text.splitlines() if not line.lstrip().startswith("#"))

    def test_expected_files_exist(self):
        for name in ("scripts/instalar.sh", "scripts/pasos-instalacion.sh", "scripts/verificar.sh", "scripts/revertir.sh", "scripts/lib.sh", "scripts/preflight-infra.sh", "scripts/preflight-acceso.sh",
                     "scripts/preflight-acceso.php", "scripts/token.sh", "scripts/conciliar.sh", "scripts/conciliar.php", "apache/cervesur-react-ro.conf", "apache/react-ro-common.conf",
                     "admin-mysql/preparar-sql-cuenta.sh", "empaquetar.mjs", "ensayo-docker.mjs"):
            self.assertTrue((self.DIR / name).is_file(), name)
        for doc in ("docs/INSTALACION-DIAGNOSTICO-8090.md", "docs/RECUPERACION-DIAGNOSTICO-8090.md"):
            self.assertTrue((ROOT / doc).is_file(), doc)

    def test_installer_never_writes_to_mysql(self):
        forbidden = re.compile(r"\b(CREATE\s+(USER|DATABASE)|DROP\s+(USER|DATABASE)|ALTER\s+USER|GRANT\s+(?!OPTION)\w|REVOKE\s|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE|FLUSH\s+PRIVILEGES)", re.I)
        for script in self.scripts() + [self.DIR / "scripts" / "preflight-acceso.php", self.DIR / "scripts" / "conciliar.php"]:
            body = self.code(script.read_text(encoding="utf-8"))
            body = re.sub(r"^\s*//.*$", "", body, flags=re.M)
            self.assertIsNone(forbidden.search(body), f"{script.name}: el instalador no puede crear, modificar ni eliminar cuentas, bases ni datos de MySQL")
        # el único SQL con CREATE USER/GRANT vive en el procedimiento administrativo y está separado
        self.assertIn("CREATE USER", (ROOT / "database/schema/admin-requiere-autorizacion/01-cuenta-diagnostico.sql").read_text(encoding="utf-8"))

    def test_no_apache_restart_no_module_changes_no_foreign_resources(self):
        for script in self.scripts():
            body = self.code(script.read_text(encoding="utf-8"))
            for pattern in (r"\ba2enmod\b", r"\ba2dismod\b", r"systemctl\s+(re)?start\s+apache2", r"systemctl\s+restart", r"service\s+apache2\s+(re)?start", r"apache2ctl\s+(restart|stop|start)\b", r"\bkillall\b", r"\bpkill\b", r"\biptables\b", r"\bufw\b"):
                self.assertIsNone(re.search(pattern, body), f"{script.name}: {pattern}")
        revert = self.code((self.DIR / "scripts" / "revertir.sh").read_text(encoding="utf-8"))
        self.assertEqual(sorted(set(re.findall(r"borrar_exacto \"\$(\w+)\"", revert))), ["DIR_BACK", "DIR_CONF", "DIR_VAR"])
        self.assertEqual([m for m in re.findall(r"rm -rf -- [^\n]+", revert)], ['rm -rf -- "$ruta"'])

    def test_apache_listens_only_on_loopback_and_serves_nothing_static(self):
        site = (self.DIR / "apache" / "cervesur-react-ro.conf").read_text(encoding="utf-8")
        self.assertEqual(re.findall(r"^\s*Listen\s+(\S+)", site, re.M), ["127.0.0.1:8090"])
        self.assertEqual(re.findall(r"<VirtualHost\s+(\S+)>", site), ["127.0.0.1:8090"])
        common = (self.DIR / "apache" / "react-ro-common.conf").read_text(encoding="utf-8")
        self.assertIn('AliasMatch "^/(.*)$"', common)
        self.assertIn("TraceEnable Off", common)
        self.assertIn("<LimitExcept GET>", common)
        self.assertIn("session.name CERVESUR_REACT_RO", common)
        self.assertEqual(len(re.findall(r"php_admin_value open_basedir", common)), 2)
        self.assertNotIn("Options Indexes", common)
        self.assertNotRegex(common, r"Options\s+[^\n]*(Indexes|FollowSymLinks|ExecCGI)")

    def test_environment_file_is_diagnostic_only_and_private(self):
        steps = self.code((self.DIR / "scripts" / "pasos-instalacion.sh").read_text(encoding="utf-8"))
        for needed in ("CERVESUR_MODE=diagnostico", "CERVESUR_REQUIRED_MODE=diagnostico", "CERVESUR_DIAG_TOKEN=%s", "generar_token", "chmod 640", "chown root:www-data"):
            self.assertIn(needed, steps)
        self.assertNotIn("JWT_SECRET", steps)
        lib = self.code((self.DIR / "scripts" / "lib.sh").read_text(encoding="utf-8"))
        self.assertIn("openssl rand -hex 32", lib)
        # el token solo se imprime en token.sh (--mostrar/--rotar); ningún otro script lo escribe en la salida
        for script in self.scripts():
            if script.name in ("token.sh", "lib.sh"):
                continue
            body = self.code(script.read_text(encoding="utf-8"))
            self.assertIsNone(re.search(r"(echo|printf)[^\n|>]*\$\{?(TOKEN|t)\b", body), f"{script.name} podría imprimir el token")

    def test_account_columns_match_the_catalog_grants_and_access_preflight(self):
        grants = (ROOT / "database/schema/admin-requiere-autorizacion/01-cuenta-diagnostico.sql").read_text(encoding="utf-8")
        granted = {m.group(2): sorted(c.strip() for c in m.group(1).split(",")) for m in re.finditer(r"^GRANT SELECT \(([^)]*)\) ON `__BASE__`\.`(\w+)`", grants, re.M)}
        php = (self.DIR / "scripts" / "preflight-acceso.php").read_text(encoding="utf-8")
        block = php[php.index("$esperadas = ["):php.index("];", php.index("$esperadas = ["))]
        expected = {m.group(1): sorted(re.findall(r"'(\w+)'", m.group(2))) for m in re.finditer(r"'(\w+)' => \[([^\]]*)\]", block)}
        self.assertEqual(granted, expected)
        self.assertEqual(sum(len(v) for v in expected.values()), 18)

    ADMIN = ROOT / "database" / "schema" / "admin-requiere-autorizacion"

    def sql_body(self, name):
        text = (self.ADMIN / name).read_text(encoding="utf-8")
        return "\n".join(line for line in text.splitlines() if not line.lstrip().startswith("--"))

    def test_diagnostic_revocation_can_only_drop_the_diagnostic_account(self):
        body = self.sql_body("99-revocar-cuenta-diagnostico.sql").strip()
        self.assertEqual(body, "DROP USER IF EXISTS 'cervesur_ro_diag'@'127.0.0.1';")
        pilot = self.sql_body("99-revocar-cuenta-piloto.sql").strip()
        self.assertEqual(pilot, "DROP USER IF EXISTS 'cervesur_ro_pilot'@'__HOST__';")
        self.assertNotIn("cervesur_ro_pilot", body)
        self.assertNotIn("cervesur_ro_diag", pilot)
        self.assertFalse((self.ADMIN / "99-revocar-cuentas.sql").exists(), "el archivo antiguo que eliminaba ambas cuentas ya no debe existir")
        packaged = (self.DIR / "empaquetar.mjs").read_text(encoding="utf-8")
        self.assertIn("99-revocar-cuenta-diagnostico.sql", packaged)
        self.assertNotIn("99-revocar-cuenta-piloto.sql", packaged)
        self.assertNotIn("02b-verificar", packaged)

    def test_diagnostic_verification_does_not_depend_on_the_pilot_account(self):
        for name in ("02-verificar-privilegios-diagnostico.sql", "04-verificar-ausencia-de-escrituras.sql"):
            self.assertNotRegex(self.sql_body(name), r"SHOW GRANTS FOR 'cervesur_ro_pilot'", name)
        self.assertNotIn("cervesur_ro_pilot", self.sql_body("02-verificar-privilegios-diagnostico.sql"))
        self.assertNotIn("cervesur_ro_diag", self.sql_body("02b-verificar-privilegios-piloto.sql"))

    def test_diagnostic_grants_are_18_columns_by_table(self):
        grants = self.sql_body("01-cuenta-diagnostico.sql")
        counts = {m.group(2): len([c for c in m.group(1).split(",") if c.strip()]) for m in re.finditer(r"^GRANT SELECT \(([^)]*)\) ON `__BASE__`\.`(\w+)`", grants, re.M)}
        self.assertEqual(counts, {"user": 4, "empresa": 2, "reporte": 2, "empresa_usuario": 3, "empresa_reporte": 4, "user_empresa_reporte": 3})
        self.assertEqual(sum(counts.values()), 18)
        self.assertIn("4+2+2+3+4+3", (self.ADMIN / "02-verificar-privilegios-diagnostico.sql").read_text(encoding="utf-8"))

    def test_generated_sql_never_places_the_password_in_comments(self):
        for name in ("01-cuenta-diagnostico.sql", "03-cuenta-piloto-autenticado.sql"):
            for line in (self.ADMIN / name).read_text(encoding="utf-8").splitlines():
                if line.lstrip().startswith("--"):
                    self.assertNotRegex(line, r"__CLAVE_\w+__", f"{name}: un comentario contiene el marcador de la clave y el generador lo sustituiría")
        script = (self.DIR / "admin-mysql" / "preparar-sql-cuenta.sh").read_text(encoding="utf-8")
        self.assertIn("/^[[:space:]]*--/!s|__CLAVE_DIAGNOSTICO__|", script)
        for needed in ("en_comentarios", "en_total", "rm -f --", "grep -cF"):
            self.assertIn(needed, script)
        viewer = (self.DIR / "admin-mysql" / "mostrar-sql-redactado.sh").read_text(encoding="utf-8")
        self.assertIn("********", viewer)
        self.assertLess(viewer.index("se niega a mostrar el SQL sin poder redactarlo"), viewer.index("printf"))
        self.assertNotIn("echo \"$CLAVE\"", script + viewer)
        self.assertNotRegex(script + viewer, r"echo \"\$\{?CLAVE")

    def test_diagnostic_token_is_only_read_from_the_header(self):
        router = source("backend/router/DiagnosticoRouter.php")
        self.assertIn("HTTP_X_DIAGNOSTIC_TOKEN", router)
        for forbidden in ("$_GET", "$_REQUEST", "$_POST", "QUERY_STRING", "php://input"):
            self.assertNotIn(forbidden, router)

    def test_packager_excludes_secrets_and_versions_the_tested_code(self):
        packager = (self.DIR / "empaquetar.mjs").read_text(encoding="utf-8")
        for needed in ("startsWith('.env')", "-con-cambios", "contentDigest", "SHA256SUMS", ".sha256", "PRIVATE KEY"):
            self.assertIn(needed, packager)
        self.assertNotIn("build-production", packager, "el diagnóstico no publica ninguna aplicación React")

    def test_preflight_b_never_reports_pass_without_the_account(self):
        pre = self.code((self.DIR / "scripts" / "preflight-acceso.sh").read_text(encoding="utf-8"))
        self.assertLess(pre.index("PENDIENTE"), pre.index("preflight-acceso.php"))
        self.assertIn("exit 3", pre)
        install = self.code((self.DIR / "scripts" / "instalar.sh").read_text(encoding="utf-8"))
        self.assertIn("3) abortar", install)


class PilotoVisualStaticChecks(unittest.TestCase):
    """UX-07D.4 express: el piloto visual es de consulta, de un solo operador, con 15 columnas y sin tocar la tabla user."""

    DIR = ROOT / "deploy" / "tunel-ssh-piloto"
    SQL = ROOT / "database" / "schema" / "piloto-visual"

    @staticmethod
    def code(text):
        return "\n".join(line for line in text.splitlines() if not line.lstrip().startswith(("#", "--", "//")))

    def scripts(self):
        return sorted((self.DIR / "scripts").glob("*.sh")) + sorted((self.DIR / "admin-mysql").glob("*.sh"))

    def test_expected_files_exist(self):
        for name in ("scripts/instalar.sh", "scripts/pasos-instalacion.sh", "scripts/verificar.sh", "scripts/revertir.sh", "scripts/lib.sh", "scripts/preflight-infra.sh", "scripts/preflight-acceso.sh", "scripts/preflight-acceso.php",
                     "scripts/operador.sh", "scripts/informes.sh", "apache/cervesur-react-piloto.conf", "apache/react-piloto-common.conf", "admin-mysql/preparar-sql-cuenta.sh", "admin-mysql/mostrar-sql-redactado.sh", "empaquetar.mjs", "ensayo-docker.mjs"):
            self.assertTrue((self.DIR / name).is_file(), name)
        for name in ("01-cuenta-visor.sql", "02-verificar-privilegios-visor.sql", "99-revocar-cuenta-visor.sql"):
            self.assertTrue((self.SQL / name).is_file(), name)
        for doc in ("docs/INSTALACION-PILOTO-VISUAL-8091.md", "docs/RECUPERACION-PILOTO-VISUAL-8091.md"):
            self.assertTrue((ROOT / doc).is_file(), doc)

    def test_installer_never_writes_to_mysql_nor_touches_apache_modules(self):
        forbidden = re.compile(r"\b(CREATE\s+(USER|DATABASE)|DROP\s+(USER|DATABASE)|ALTER\s+USER|GRANT\s+(?!OPTION)\w|REVOKE\s|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE|FLUSH\s+PRIVILEGES)", re.I)
        for script in self.scripts() + [self.DIR / "scripts" / "preflight-acceso.php"]:
            body = self.code(script.read_text(encoding="utf-8"))
            if script.name != "preparar-sql-cuenta.sh":  # el generador solo escribe archivos de texto (nunca invoca mysql); se comprueba en la rama siguiente
                self.assertIsNone(forbidden.search(body), f"{script.name}: el instalador no puede crear, modificar ni eliminar cuentas, bases ni datos de MySQL")
            else:
                self.assertNotRegex(body, r"(?m)^\s*(mysql|\$MYSQL_ADMIN)\b")
            for pattern in (r"\ba2enmod\b", r"\ba2dismod\b", r"systemctl\s+(re)?start", r"service\s+apache2\s+(re)?start", r"apache2ctl\s+(restart|stop|start)\b", r"\bkillall\b", r"\bpkill\b", r"\biptables\b", r"\bufw\b"):
                self.assertIsNone(re.search(pattern, body), f"{script.name}: {pattern}")
        revert = self.code((self.DIR / "scripts" / "revertir.sh").read_text(encoding="utf-8"))
        self.assertEqual(sorted(set(re.findall(r"borrar_exacto \"\$(\w+)\"", revert))), ["DIR_BACK", "DIR_CONF", "DIR_FRONT", "DIR_VAR"])
        self.assertEqual(re.findall(r"rm -rf -- [^\n]+", revert), ['rm -rf -- "$ruta"'])

    def test_apache_is_loopback_only_and_allows_only_get_and_post(self):
        site = (self.DIR / "apache" / "cervesur-react-piloto.conf").read_text(encoding="utf-8")
        self.assertEqual(re.findall(r"^\s*Listen\s+(\S+)", site, re.M), ["127.0.0.1:8091"])
        self.assertEqual(re.findall(r"<VirtualHost\s+(\S+)>", site), ["127.0.0.1:8091"])
        common = (self.DIR / "apache" / "react-piloto-common.conf").read_text(encoding="utf-8")
        for needle in ("TraceEnable Off", "<LimitExcept GET POST>", "session.name CERVESUR_REACT_PILOTO", "RewriteRule \"^/api/powerbi"):
            self.assertIn(needle, common)
        self.assertEqual(len(re.findall(r"php_admin_value open_basedir .*CERVESUR_PILOTO_ESTADO", common)), 2)
        self.assertNotRegex(common, r"Options\s+[^\n]*(Indexes|FollowSymLinks|ExecCGI)(?<!-Indexes)")

    def test_account_has_exactly_the_15_catalog_columns_and_no_user_table(self):
        grants = "\n".join(l for l in (self.SQL / "01-cuenta-visor.sql").read_text(encoding="utf-8").splitlines() if not l.lstrip().startswith("--"))
        granted = {m.group(2): sorted(c.strip() for c in m.group(1).split(",")) for m in re.finditer(r"^GRANT SELECT \(([^)]*)\) ON `__BASE__`\.`(\w+)`", grants, re.M)}
        self.assertEqual(granted, {"reporte": ["activo", "descripcion", "enlace", "id", "nombre", "reporte_id"], "empresa": ["activo", "ciudad", "id", "nombre", "ruc"], "empresa_reporte": ["activo", "empresa_id", "id", "reporte_id"]})
        self.assertEqual(sum(len(v) for v in granted.values()), 15)
        self.assertEqual(len(re.findall(r"^GRANT ", grants, re.M)), 3)
        self.assertNotIn("`user`", grants)
        php = (self.DIR / "scripts" / "preflight-acceso.php").read_text(encoding="utf-8")
        block = php[php.index("$esperadas = ["):php.index("];", php.index("$esperadas = ["))]
        expected = {m.group(1): sorted(re.findall(r"'(\w+)'", m.group(2))) for m in re.finditer(r"'(\w+)' => \[([^\]]*)\]", block)}
        self.assertEqual(granted, expected)
        # las columnas concedidas son exactamente las que citan las consultas del catálogo cerrado
        catalog = source("backend/lib/piloto_catalogo.php")
        sqls = " ".join(re.findall(r"=> '(SELECT[^']*)'", catalog))
        for table, columns in granted.items():
            self.assertIn(table, sqls)
            for column in columns:
                self.assertRegex(sqls, r"\b" + column + r"\b", f"{table}.{column} se concede pero el catálogo no lo usa")

    def test_generated_sql_never_places_the_password_in_comments_and_revocation_is_scoped(self):
        for line in (self.SQL / "01-cuenta-visor.sql").read_text(encoding="utf-8").splitlines():
            if line.lstrip().startswith("--"):
                self.assertNotRegex(line, r"__CLAVE_\w+__")
        script = (self.DIR / "admin-mysql" / "preparar-sql-cuenta.sh").read_text(encoding="utf-8")
        self.assertIn("/^[[:space:]]*--/!s|__CLAVE_VISOR__|", script)
        for needed in ("en_comentarios", "en_total", "rm -f --", "grep -cF"):
            self.assertIn(needed, script)
        body = "\n".join(l for l in (self.SQL / "99-revocar-cuenta-visor.sql").read_text(encoding="utf-8").splitlines() if not l.lstrip().startswith("--")).strip()
        self.assertEqual(body, "DROP USER IF EXISTS 'cervesur_ro_visor'@'127.0.0.1';")
        packaged = (self.DIR / "empaquetar.mjs").read_text(encoding="utf-8")
        self.assertIn("99-revocar-cuenta-visor.sql", packaged)
        self.assertNotIn("diagnostico", packaged.split("// SQL revisable")[1].split("const files")[0])

    def test_catalog_never_touches_user_table_or_credentials_and_enlace_only_in_viewer(self):
        catalog = source("backend/lib/piloto_catalogo.php")
        code = re.sub(r"/\*.*?\*/", "", catalog, flags=re.S)
        queries = re.findall(r"=> '(SELECT[^']*)'", code.split("function piloto_catalogo_controles")[0])
        self.assertEqual(len(queries), 8)
        for sql in queries:
            self.assertNotRegex(sql, r"(?i)\b(user|password|powerbi_\w+|email|telefono|razon_social|grupo_id|created_by|notas)\b")
            self.assertNotRegex(sql, r"(?i)SELECT\s+\*|\.\*")
        self.assertEqual([m for m in re.findall(r"'(\w+)' => '[^']*\benlace\b", code)], ["reporte_visor"])
        router = source("backend/router/PilotoRouter.php")
        self.assertNotRegex(self.code(router), r"(?i)\bFROM\s+user\b|INSERT|UPDATE\s+\w+\s+SET|DELETE\s+FROM|\$_GET\['token'\]|auth_middleware")
        for needed in ("hash_equals", "password_verify", "usleep", "visor_no_autorizado", "Cache-Control: no-store"):
            self.assertIn(needed, router)

    def test_pilot_mode_is_fail_closed_and_has_a_closed_route_list(self):
        mode = source("backend/lib/mode.php")
        self.assertIn("'piloto_visual'", mode)
        for needed in ("CERVESUR_PILOT_OPERATOR_HASH", "CERVESUR_PILOT_STATE_DIR", "CERVESUR_PILOT_VISOR_IDS"):
            self.assertIn(needed, mode)
        db = source("backend/lib/db.php")
        self.assertIn("useCatalog(piloto_catalogo_sentencias())", db)
        index = source("backend/router/index.php")
        self.assertLess(index.index("app_mode_route_gate("), index.index("PilotoRouter.php"))
        pasos = self.code((self.DIR / "scripts" / "pasos-instalacion.sh").read_text(encoding="utf-8"))
        for needed in ("CERVESUR_MODE=piloto_visual", "CERVESUR_REQUIRED_MODE=piloto_visual", "chmod 640", "chown root:www-data", "hash_bcrypt", "generar_secreto"):
            self.assertIn(needed, pasos)
        self.assertNotIn("CERVESUR_DIAG_TOKEN", pasos)

    def test_secrets_are_never_echoed_outside_the_operator_tool(self):
        for script in self.scripts():
            if script.name in ("operador.sh", "lib.sh", "verificar.sh"):
                continue
            body = self.code(script.read_text(encoding="utf-8"))
            for line in body.splitlines():  # una línea que redirige a un archivo (>) escribe, no imprime
                if ">" not in line:
                    self.assertIsNone(re.search(r"(echo|printf)[^\n|]*\$\{?(CLAVE_OP|CLAVE_DB|JWT|TOKEN|HASH_OP)\b", line), f"{script.name} podría imprimir un secreto: {line.strip()}")
        op = self.code((self.DIR / "scripts" / "operador.sh").read_text(encoding="utf-8"))
        self.assertEqual(op.count('echo "$c"'), 1, "la clave solo se imprime en --mostrar")

    def test_pilot_frontend_has_no_admin_screens_and_is_a_distinct_build(self):
        app = source("frontend/src/app/App.tsx")
        self.assertIn("import.meta.env.VITE_PILOT === 'true'", app)
        # solo el piloto administrativo (VITE_PILOT_MODE=admin) y la compilación de escritura (admin-write, UX-08H) conservan las pantallas de administración;
        # los pilotos visuales (operador, usuarios) siguen sin ellas
        self.assertIn("{(!PILOT || ADMIN_PILOT || ADMIN_WRITE) && (", app)
        self.assertIn("const ADMIN_WRITE = PILOT && import.meta.env.VITE_PILOT_MODE === 'admin-write'", app)
        self.assertIn("const ADMIN_PILOT = PILOT && import.meta.env.VITE_PILOT_MODE === 'admin'", app)
        build = source("frontend/scripts/build-production.mjs")
        for needed in ("--pilot", "dist-pilot", "VITE_PILOT: 'true'", "pilot: true"):
            self.assertIn(needed, build)
        packager = (self.DIR / "empaquetar.mjs").read_text(encoding="utf-8")
        for needed in ("info.pilot !== true", "startsWith('.env')", "-con-cambios", "contentDigest", "SHA256SUMS", ".sha256", "PRIVATE KEY"):
            self.assertIn(needed, packager)
        self.assertIn("dist-pilot", source("frontend/scripts/scan-secrets.mjs"))


class PilotoUsuariosStaticChecks(unittest.TestCase):
    """UX-07D.4B: usuarios reales con permisos individuales; tres cuentas, catálogos cerrados, HTTPS obligatorio y sin escrituras en la base de Flutter."""

    SQL = ROOT / "database" / "schema" / "piloto-usuarios"

    def test_expected_files_exist(self):
        for name in ("01-cuentas-lectura.sql", "02-estado-base-y-cuenta.sql", "03-verificar-privilegios.sql", "99-revocar-cuentas.sql"):
            self.assertTrue((self.SQL / name).is_file(), name)
        for name in ("backend/lib/usuarios_catalogo.php", "backend/lib/estado_piloto.php", "backend/lib/db_usuarios.php", "backend/router/UsuariosRouter.php", "backend/cli/piloto-usuarios.php"):
            self.assertTrue((ROOT / name).is_file(), name)

    def test_read_accounts_only_select_and_only_listed_columns(self):
        sql = re.sub(r"--[^\n]*", "", (self.SQL / "01-cuentas-lectura.sql").read_text(encoding="utf-8"))
        self.assertNotRegex(sql, r"(?i)\b(INSERT|UPDATE|DELETE|DROP\s+TABLE|ALTER\s+TABLE|ALL\s+PRIVILEGES|WITH\s+GRANT)\b")
        grants = re.findall(r"(?is)GRANT\s+SELECT\s*\((.*?)\)\s*ON\s+`?__BASE__`?\.`?(\w+)`?", sql)
        by_table = {}
        for cols, table in grants:
            by_table.setdefault(table, []).extend(c.strip(" `\n") for c in cols.split(","))
        self.assertEqual(len(by_table.get("user", [])), 9)
        others = sum(len(v) for k, v in by_table.items() if k != "user")
        self.assertEqual(others, 21)
        for table, cols in by_table.items():
            if table != "user":
                self.assertFalse([c for c in cols if c.startswith("powerbi")], table)
        self.assertNotIn("enlace", " ".join(c for k, v in by_table.items() if k != "reporte" for c in v))

    def test_state_account_is_scoped_to_its_own_database(self):
        sql = re.sub(r"--[^\n]*", "", (self.SQL / "02-estado-base-y-cuenta.sql").read_text(encoding="utf-8"))
        for g in re.findall(r"(?is)GRANT\s+(.*?)\s+TO\b", sql):
            self.assertRegex(g, r"(?is)ON\s+`?__BASE_ESTADO__`?\.\*")
            self.assertNotRegex(g, r"(?i)ALL|GRANT OPTION|CREATE|DROP|ALTER|FILE|SUPER|PROCESS")

    def test_revocation_script_drops_only_the_three_accounts(self):
        sql = re.sub(r"--[^\n]*", "", (self.SQL / "99-revocar-cuentas.sql").read_text(encoding="utf-8"))
        drops = re.findall(r"(?i)DROP\s+USER\s+IF\s+EXISTS\s+'(\w+)'", sql)
        self.assertEqual(sorted(drops), ["cervesur_react_estado", "cervesur_ro_auth", "cervesur_ro_catalogo"])
        self.assertNotRegex(sql, r"(?i)DROP\s+DATABASE")

    def test_router_never_writes_flutter_tables_nor_returns_links_in_catalog(self):
        router = source("backend/router/UsuariosRouter.php") + source("backend/lib/pilot_sesion.php")
        self.assertNotRegex(router, r"(?i)\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b")
        self.assertNotRegex(router, r"(?i)\bpowerbi_(?!link)")
        self.assertIn("password_verify", router)
        self.assertIn("estado_ejecutar", router)

    def test_state_statements_only_touch_the_four_state_tables(self):
        state = source("backend/lib/estado_piloto.php")
        tables = set(re.findall(r"(?i)(?:INSERT\s+INTO|(?<!KEY )UPDATE|DELETE\s+FROM)\s+`?(\w+)`?", state))
        self.assertTrue(tables <= {"usuarios_piloto", "sesiones", "intentos_login", "auditoria"}, tables)

    def test_admin_statements_are_cli_only(self):
        state = source("backend/lib/estado_piloto.php")
        self.assertIn("estado_catalogo_administracion", state)
        self.assertIn("PHP_SAPI === 'cli'", source("backend/lib/db_usuarios.php"))

    def test_https_check_precedes_route_gate(self):
        index = source("backend/router/index.php")
        self.assertIn("https_requerido", index)
        self.assertLess(index.index("app_https_ok"), index.index("app_mode_route_gate"))

    def test_user_frontend_build_variant_exists(self):
        self.assertIn("--pilot-usuarios", source("frontend/scripts/build-production.mjs"))
        self.assertIn("dist-pilot-usuarios", source("frontend/scripts/scan-secrets.mjs"))
        self.assertIn("build:pilot-usuarios", source("frontend/package.json"))


class PilotoUsuariosDeployStaticChecks(unittest.TestCase):
    """UX-07D.4B.1: el paquete del piloto con usuarios (8092) es aislado, reversible, solo HTTPS y no escribe en MySQL."""

    DIR = ROOT / "deploy" / "tunel-ssh-piloto-usuarios"
    SQL = ROOT / "database" / "schema" / "piloto-usuarios"

    @staticmethod
    def code(text):
        return "\n".join(line for line in text.splitlines() if not line.lstrip().startswith(("#", "--", "//")))

    def scripts(self):
        return sorted((self.DIR / "scripts").glob("*.sh")) + sorted((self.DIR / "admin-mysql").glob("*.sh"))

    def test_expected_files_exist(self):
        for name in ("scripts/instalar.sh", "scripts/pasos-instalacion.sh", "scripts/verificar.sh", "scripts/revertir.sh", "scripts/lib.sh", "scripts/preflight-infra.sh",
                     "scripts/preflight-acceso.sh", "scripts/preflight-acceso.php", "scripts/actualizar.sh", "scripts/usuarios.sh", "scripts/tls.sh", "apache/cervesur-react-usuarios.conf",
                     "apache/react-usuarios-common.conf", "admin-mysql/preparar-sql-cuentas.sh", "admin-mysql/mostrar-sql-redactado.sh", "empaquetar.mjs", "ensayo-docker.mjs"):
            self.assertTrue((self.DIR / name).is_file(), name)
        for doc in ("INSTALACION-PILOTO-USUARIOS-8092.md", "HTTPS-PILOTO-USUARIOS.md", "RECUPERACION-PILOTO-USUARIOS-8092.md", "CIERRE-MVP-REACT-8092.md", "POWERBI-PUBLICAR-WEB-RIESGO.md", "PROPUESTA-ACCESO-CORPORATIVO-8092.md"):
            self.assertTrue((ROOT / "docs" / doc).is_file(), doc)

    def test_installer_never_writes_to_mysql_nor_touches_apache_modules(self):
        forbidden = re.compile(r"\b(CREATE\s+(USER|DATABASE)|DROP\s+(USER|DATABASE)|ALTER\s+USER|GRANT\s+(?!OPTION)\w|REVOKE\s|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE|FLUSH\s+PRIVILEGES)", re.I)
        for script in self.scripts() + [self.DIR / "scripts" / "preflight-acceso.php"]:
            text = self.code(script.read_text(encoding="utf-8"))
            if script.name in ("preparar-sql-cuentas.sh", "mostrar-sql-redactado.sh"):
                continue  # generan/redactan un SQL para REVISIÓN: no se conectan a MySQL (se comprueba aparte)
            self.assertIsNone(forbidden.search(text), script.name)
            self.assertNotRegex(text, r"\ba2en(mod|conf)\b|\ba2dismod\b|systemctl\s+(restart|stop)|apache2ctl\s+(restart|stop)")
        for script in (self.DIR / "admin-mysql").glob("*.sh"):
            self.assertNotRegex(self.code(script.read_text(encoding="utf-8")), r"(^|\s)mysql(\s|$)|mysqladmin|mysqldump")

    def test_tls_only_and_no_http_vhost(self):
        site = self.code((self.DIR / "apache" / "cervesur-react-usuarios.conf").read_text(encoding="utf-8"))
        common = self.code((self.DIR / "apache" / "react-usuarios-common.conf").read_text(encoding="utf-8"))
        self.assertEqual(re.findall(r"^Listen\s+(\S+)", site, re.M), ["127.0.0.1:8092"])
        self.assertEqual(len(re.findall(r"<VirtualHost", site)), 1)
        self.assertIn("SSLEngine on", site)
        self.assertIn("SSLProtocol -all +TLSv1.2 +TLSv1.3", site)
        for header in ("X-Forwarded-Proto", "X-Forwarded-For", "Forwarded", "X-Real-IP"):
            self.assertRegex(common, r"RequestHeader unset " + re.escape(header) + r"\b")
        self.assertNotIn("Strict-Transport-Security", common)  # HSTS solo con nombre propio (CERVESUR_PILOT_HSTS=SI)
        self.assertRegex(common, r"session\.cookie_secure on")
        self.assertRegex(common, r"Header edit Set-Cookie[^\n]*Secure")
        self.assertNotRegex(site + common, r"SetEnv|SSLVerifyClient\s+none|SSLProtocol[^\n]*(SSLv|TLSv1\.0|TLSv1\.1|\+TLSv1\s)")

    def test_installer_never_sets_insecure_http_nor_hsts(self):
        steps = self.code((self.DIR / "scripts" / "pasos-instalacion.sh").read_text(encoding="utf-8"))
        self.assertNotRegex(steps, r"CERVESUR_INSECURE_TESTS=")
        self.assertNotRegex(steps, r"CERVESUR_PILOT_HSTS=")
        self.assertIn("CERVESUR_MODE=piloto_usuarios", steps)
        self.assertIn("CERVESUR_REQUIRED_MODE=piloto_usuarios", steps)
        self.assertIn("CERVESUR_PILOT_MAX_FALLOS_IP=100", steps)

    def test_port_is_checked_not_assumed(self):
        pre = source("deploy/tunel-ssh-piloto-usuarios/scripts/preflight-infra.sh")
        self.assertIn('ss -ltnH "sport = :$PUERTO"', pre)
        self.assertIn("ninguna configuración de Apache menciona el puerto", pre)
        self.assertIn("modulo_apache ssl", pre)

    def test_preflight_columns_match_the_sql_and_the_backend(self):
        sql = re.sub(r"--[^\n]*", "", (self.SQL / "01-cuentas-lectura.sql").read_text(encoding="utf-8"))
        grants = {}
        for cols, table in re.findall(r"(?is)GRANT\s+SELECT\s*\((.*?)\)\s*ON\s+`?__BASE__`?\.`?(\w+)`?", sql):
            grants[table] = sorted(c.strip(" `\n") for c in cols.split(","))
        php = source("deploy/tunel-ssh-piloto-usuarios/scripts/preflight-acceso.php")

        def expected(var):
            body = re.search(r"\$" + var + r" = \[(.*?)\];", php, re.S).group(1)
            return {t: sorted(re.findall(r"'(\w+)'", c)) for t, c in re.findall(r"'(\w+)' => \[(.*?)\]", body)}

        self.assertEqual(expected("esperadasAuth"), {"user": grants["user"]})
        self.assertEqual(expected("esperadasCatalogo"), {t: c for t, c in grants.items() if t != "user"})
        self.assertEqual(sum(len(c) for c in expected("esperadasAuth").values()), 9)
        self.assertEqual(sum(len(c) for c in expected("esperadasCatalogo").values()), 21)

    def test_packager_excludes_secrets_and_certificates(self):
        packager = source("deploy/tunel-ssh-piloto-usuarios/empaquetar.mjs")
        for needed in ("pilotMode !== 'usuarios'", "startsWith('.env')", "key|pem|crt", "-con-cambios", "contentDigest", "SHA256SUMS", ".sha256", "PRIVATE KEY", "IDENTIFIED BY"):
            self.assertIn(needed, packager)
        self.assertIn("dist-pilot-usuarios", source("frontend/scripts/scan-secrets.mjs"))

    def test_revert_never_touches_mysql_or_other_portals(self):
        revert = self.code((self.DIR / "scripts" / "revertir.sh").read_text(encoding="utf-8"))
        self.assertNotRegex(revert, r"(^|\s)mysql\s|DROP\s+(USER|DATABASE)")
        for fixed in ("/etc/apache2/cervesur-react-usuarios", "/var/www/cervesur-react-usuarios", "/opt/cervesur-react-usuarios", "/var/lib/cervesur-react-usuarios"):
            self.assertIn(fixed, revert)
        sql = self.code((self.SQL / "99-revocar-cuentas.sql").read_text(encoding="utf-8"))
        self.assertEqual(sorted(re.findall(r"DROP USER IF EXISTS '(\w+)'@'127\.0\.0\.1'", sql)), ["cervesur_react_estado", "cervesur_ro_auth", "cervesur_ro_catalogo"])

    def test_update_script_is_scoped_reversible_and_never_touches_secrets(self):
        upd = self.code((self.DIR / "scripts" / "actualizar.sh").read_text(encoding="utf-8"))
        self.assertNotRegex(upd, r"(^|\s)mysql(\s|$)|DROP\s+(USER|DATABASE)|a2en|a2dis|systemctl\s+(restart|stop)|apache2ctl\s+(restart|stop)")
        raw = (self.DIR / "scripts" / "actualizar.sh").read_text(encoding="utf-8")
        self.assertIn("--simular)", raw)
        self.assertIn("--revertir)", raw)
        for needed in ("ACTUALIZAR-8092", "SHA256SUMS", "huella_portales", "volver_atras", "--exclude=backend/.env", "cp -p \"$ENV_FILE\""):
            self.assertIn(needed, upd)
        # solo reemplaza public y backend del propio piloto
        for other in ("/var/www/cervesur/", "/etc/apache2/sites", "cervesur-react-prueba", "cervesur-react-compat", "cervesur-react-ro", "cervesur-react-piloto"):
            self.assertNotIn(other, upd)

    def test_static_htaccess_never_emits_hsts_for_localhost(self):
        # UX-07D.5: HSTS es por nombre, no por puerto: en el túnel («localhost») afectaría a los otros portales.
        for name in ("frontend/public/.htaccess",):
            text = self.code((ROOT / name).read_text(encoding="utf-8"))
            block = re.search(r"<If\s+\"([^\"]*)\">\s*Header always set Strict-Transport-Security", text)
            self.assertIsNotNone(block, name)
            self.assertIn("HTTP_HOST", block.group(1))
            self.assertIn("localhost", block.group(1))
            self.assertIn("!~", block.group(1))

    def test_visor_ids_list_tolerates_spaces_and_layout_e2e_exists(self):
        router = source("backend/lib/pilot_sesion.php")
        self.assertIn("array_map('trim', explode(',', $cfg))", router)
        self.assertTrue((ROOT / "frontend" / "e2e" / "run-layout.mjs").is_file())
        self.assertIn("test:e2e:layout", source("frontend/package.json"))

    def test_sql_generator_redacts_and_never_shows_passwords(self):
        gen = self.code((self.DIR / "admin-mysql" / "preparar-sql-cuentas.sh").read_text(encoding="utf-8"))
        self.assertIn("openssl rand", gen)
        self.assertIn("umask 077", gen)
        self.assertNotRegex(gen, r"echo[^\n]*\$(C_AUTH|C_CAT|C_EST)\b|printf[^\n]*\$(C_AUTH|C_CAT|C_EST)\b[^\n]*>\s*/dev/(stdout|stderr)")
        viewer = self.code((self.DIR / "admin-mysql" / "mostrar-sql-redactado.sh").read_text(encoding="utf-8"))
        self.assertIn("********", viewer)
        self.assertIn("se niega a mostrar", viewer)


if __name__ == "__main__":
    unittest.main(verbosity=2)


class PilotoAdminStaticChecks(unittest.TestCase):
    """UX-07E.B: piloto administrativo de SOLO LECTURA (instancia 8093 separada del piloto de usuarios)."""

    def test_admin_router_and_catalog_never_write_or_expose_secrets(self):
        router = source("backend/router/AdminRouter.php")
        catalog = source("backend/lib/admin_catalogo.php")
        for text in (router, catalog):
            self.assertNotRegex(text, r"(?i)\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|TRUNCATE|DROP\s|ALTER\s|CREATE\s+(TABLE|USER))\b")
        cat_part = re.sub(r"/\*.*?\*/", "", catalog.split("function admin_catalogo_sentencias")[0], flags=re.S)  # sin comentarios
        auth_part = catalog.split("function admin_auth_consultas")[1].split("function admin_auth_sentencias")[0]
        self.assertNotRegex(cat_part, r"(?i)FROM\s+user")  # el catálogo no toca la tabla user
        listado = re.search(r"'usuarios_pagina' => '([^']*)'", auth_part).group(1)
        self.assertNotRegex(listado, r"(?i)password|session_version|phone|avatar")
        self.assertEqual(len(re.findall(r"enlace", cat_part)), 1)  # solo en la consulta del visor

    def test_admin_mode_is_declared_validated_and_route_gated(self):
        mode = source("backend/lib/mode.php")
        self.assertIn("'piloto_admin'", mode)
        self.assertIn("$mode === 'piloto_usuarios' || $mode === 'piloto_admin'", mode)
        gate = mode.split("if ($mode === 'piloto_admin') {")[1].split("if ($mode === 'piloto_visual'")[0]
        self.assertNotIn("'PUT'", gate)
        self.assertNotIn("'DELETE'", gate)
        self.assertEqual(re.findall(r"'(POST)' =>", gate), ["POST"])
        self.assertIn("'~^/api/login$~D', '~^/api/logout$~D'", gate)
        index = source("backend/router/index.php")
        self.assertIn("in_array(app_mode(), ['piloto_usuarios', 'piloto_admin', 'admin_escritura'], true) && !app_https_ok(", index)
        self.assertIn("AdminRouter.php", index)

    def test_only_admins_enter_the_admin_pilot_and_only_workers_the_user_pilot(self):
        shared = source("backend/lib/pilot_sesion.php")
        self.assertIn("function usr_rol_modo() { return in_array(app_mode(), ['piloto_admin', 'admin_escritura'], true) ? 'admin' : 'user'; }", shared)
        self.assertIn("normalize_role($u['role'] ?? null) !== usr_rol_modo()", shared)
        cli = source("backend/cli/piloto-usuarios.php")
        self.assertIn("$rolModo = app_mode() === 'piloto_admin' ? 'admin' : 'user'", cli)

    def test_admin_sql_has_own_accounts_and_no_write_privileges_on_the_flutter_base(self):
        base = ROOT / "database" / "schema" / "piloto-admin"
        for name in ("01-cuentas-lectura.sql", "02-estado-base-y-cuenta.sql", "03-verificar-privilegios.sql", "99-revocar-cuentas.sql"):
            self.assertTrue((base / name).is_file(), name)
        sql = "\n".join(re.sub(r"--[^\n]*", "", (base / n).read_text(encoding="utf-8")) for n in ("01-cuentas-lectura.sql", "02-estado-base-y-cuenta.sql"))
        accounts = sorted(re.findall(r"CREATE USER '(\w+)'@", sql))
        self.assertEqual(accounts, ["cervesur_react_estado_adm", "cervesur_ro_auth_adm", "cervesur_ro_catalogo_adm"])
        self.assertNotRegex(sql, r"(?i)GRANT[^;]*\b(INSERT|UPDATE|DELETE|ALL|CREATE|DROP|ALTER|FILE|SUPER)\b[^;]*`__BASE__`")
        self.assertNotRegex(sql, r"(?i)GRANT OPTION|`cervesur_portalbi`")
        self.assertRegex(sql, r"GRANT SELECT, INSERT, UPDATE, DELETE ON `__BASE_ESTADO__`\.\*")
        revoke = re.sub(r"--[^\n]*", "", (base / "99-revocar-cuentas.sql").read_text(encoding="utf-8"))
        self.assertEqual(sorted(re.findall(r"DROP USER IF EXISTS '(\w+)'@'127\.0\.0\.1'", revoke)), accounts)
        # mismas columnas que el piloto de usuarios: nada más
        usr = (ROOT / "database" / "schema" / "piloto-usuarios" / "01-cuentas-lectura.sql").read_text(encoding="utf-8")
        cols = lambda t: sorted(re.findall(r"GRANT SELECT \(([^)]*)\) ON", re.sub(r"--[^\n]*", "", t)))
        self.assertEqual(cols((base / "01-cuentas-lectura.sql").read_text(encoding="utf-8")), cols(usr))

    def test_admin_package_is_a_deterministic_transform_without_secrets_and_checks_the_port(self):
        packager = source("deploy/tunel-ssh-piloto-admin/empaquetar.mjs")
        for needed in ("info.pilotMode !== 'admin'", "startsWith('.env')", "key|pem|crt", "-con-cambios", "contentDigest", "SHA256SUMS", ".sha256", "PRIVATE KEY", "IDENTIFIED BY",
                       "cervesur_ro_auth_adm", "cervesur_react_estado_admin", "huella_usuarios_8092", "hace referencia a"):
            self.assertIn(needed, packager)
        self.assertIn("/8092/g, '8093'", packager)
        # el 8093 se comprueba, no se asume (preflight del kit heredado)
        self.assertIn("ninguna configuración de Apache menciona el puerto", source("deploy/tunel-ssh-piloto-usuarios/scripts/preflight-infra.sh"))
        for doc in ("INSTALACION-PILOTO-ADMIN-8093.md", "PILOTO-ADMIN-UX-07EB.md", "PARIDAD-ADMIN-FLUTTER-REACT.md", "GUIA-QA-ADMIN-A1-A10.md"):
            self.assertTrue((ROOT / "docs" / doc).is_file(), doc)
        self.assertIn("dist-pilot-admin", source("frontend/scripts/scan-secrets.mjs"))
        self.assertIn("build:pilot-admin", source("frontend/package.json"))

    def test_admin_frontend_is_read_only_by_capability(self):
        http = source("frontend/src/api/http/httpApi.ts")
        self.assertIn("adminWrites: import.meta.env.VITE_PILOT_MODE !== 'admin'", http)
        self.assertIn("--pilot-admin", source("frontend/scripts/build-production.mjs"))

    def test_corporate_publication_script_is_guarded_reversible_and_read_only_diagnostic(self):
        corp = source("deploy/tunel-ssh-piloto-usuarios/scripts/corporativo.sh")
        code = "\n".join(l for l in corp.splitlines() if not l.lstrip().startswith("#"))
        self.assertNotRegex(code, r"a2en(mod|conf)\b|a2dismod|systemctl\s+(restart|stop)|apache2ctl\s+(restart|stop)|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|DROP\s+(USER|DATABASE)|CREATE\s+(USER|DATABASE)|\bGRANT\s|curl[^\n]*\s-k\b|--insecure")
        for needed in ("INSTALAR-CORP", "REVERTIR-CORP", "es_ip_privada", "estado_puerto", "OTRO proceso", "cert_no_autofirmado", "cert_nombre", "cert_vigente", "clave_corresponde",
                       "apache2ctl configtest", "apache_recargar", "revertir_corp", "corp-env-previo.txt", "v_tunel_sin_hsts", "v_http_plano", "v_vecinos_iguales", "v_hsts", "tls-corp"):
            self.assertIn(needed, corp)
        self.assertIn("600", corp)  # la clave privada se instala root:root 600
        diag = source("deploy/tunel-ssh-piloto-usuarios/scripts/diagnostico-red-corporativa.sh")
        diag_code = "\n".join(l for l in diag.splitlines() if not l.lstrip().startswith("#"))
        self.assertNotRegex(diag_code, r"a2en|a2dis|systemctl|apache2ctl\s+(restart|graceful|stop)|\brm\s|\bmv\s|\btee\b|iptables\s+-[AIDFPX]|ufw\s+(allow|deny|enable|disable|delete)")
        self.assertNotRegex(diag_code, r"\*\.key|cat\s+\S*\.env|openssl\s+(pkey|rsa|ec)\b")  # no abre claves privadas ni .env

    def test_link_authorization_is_additive_audited_and_never_automatic(self):
        sh = source("deploy/tunel-ssh-piloto-usuarios/scripts/usuarios.sh")
        for needed in ("--agregar|--quitar)", "registrar_autorizacion", "autorizaciones-informes.log", "AUTORIZO-INFORMES", "AUTORIZO-TODOS-LOS-INFORMES"):
            self.assertIn(needed, sh)
        self.assertNotRegex(sh, r"CERVESUR_PILOT_VISOR_IDS=TODOS")  # jamás se autoriza todo por defecto
        step = source("deploy/tunel-ssh-piloto-usuarios/scripts/pasos-instalacion.sh")
        self.assertIn('IDS=$INFORMES; [ "$IDS" != "NINGUNO" ] || IDS=""', step)  # la instalación parte sin informes autorizados
        shared = source("backend/lib/pilot_sesion.php")
        self.assertIn("function usr_marcar_visor(array $r) { $r['visor_autorizado'] = usr_visor_autorizado($r['id']) ? 1 : 0; return $r; }", shared)
        self.assertNotRegex(source("backend/router/AdminRouter.php").split("/api/reportes/id/")[0], r"'enlace'")  # los listados no devuelven el enlace
        self.assertIn("!preg_match('/^(localhost|[0-9]{1,3}", shared)  # HSTS jamás para localhost ni para una IP

    def test_viewer_uses_a_compact_picker_instead_of_sibling_tags(self):
        page = source("frontend/src/features/viewer/ViewerPage.tsx")
        self.assertIn("OtherReportsDialog", page)
        self.assertNotIn("sibling-nav", page)
        dialog = source("frontend/src/components/OtherReportsDialog.tsx")
        for needed in ("Buscar por nombre o código", "Sin enlace autorizado", "closeOnBackdrop", "report-picker-list"):
            self.assertIn(needed, dialog)
        self.assertNotRegex(dialog, r"https?://|powerbi\.com")
        admin_pages = source("frontend/src/features/admin/AdminPages.tsx")
        self.assertIn("Calculado con los datos reales", admin_pages)
        self.assertNotIn("Calculado con los datos sintéticos actuales.</p>", admin_pages)
        for doc in ("GUIA-QA-UX-07EC.md", "DIAGNOSTICO-INDICADORES-ADMIN.md", "MATRIZ-AUTORIZACION-ENLACES.md", "PUBLICACION-CORPORATIVA-HTTPS.md"):
            self.assertTrue((ROOT / "docs" / doc).is_file(), doc)

    def test_ip_publication_requires_networks_never_weakens_tls_and_is_reversible(self):
        corp = source("deploy/tunel-ssh-piloto-usuarios/scripts/corporativo.sh")
        code = "\n".join(l for l in corp.splitlines() if not l.lstrip().startswith("#"))
        self.assertNotRegex(code, r"curl[^\n]*\s-k\b|--insecure|SSLVerify\w*\s+none|SSLProtocol[^\n]*\+TLSv1(\s|$)|SSLProtocol[^\n]*\+TLSv1\.[01](\s|$)")
        for needed in ("--redes", "Require ip $REDES", "cidr_valido", "redes_validas", "-checkip", "-verify_ip", "MODO=$([ -n \"$HOST\" ] && echo dns || echo ip)", "PUERTO_PUB_DEFECTO=443",
                       "AuthMerging And", "renovar)", "RENOVAR-CORP", "tls-corp-anterior", "v_sin_hsts_en_ip", "cert_no_ca", "es_ip_privada", "estado_puerto"):
            self.assertIn(needed, corp)
        self.assertIn("faltan argumentos".lower(), corp.lower())
        # la red cliente nunca puede ser pública ni 0.0.0.0/0
        self.assertRegex(corp, r"\[ \"\$pref\" -ge 8 \]")
        packager = source("deploy/tunel-ssh-piloto-admin/empaquetar.mjs")
        self.assertIn("PUERTO_PUB_DEFECTO=8443", packager)  # el administrador se publica en el 8443
        self.assertIn("startsWith('--host=')", packager)

    def test_private_ca_is_name_constrained_and_keeps_its_key_off_the_server(self):
        ca = source("deploy/tunel-ssh-piloto-usuarios/scripts/emitir-certificado-ip.sh")
        for needed in ("nameConstraints = critical", "permitted;IP.", "permitted;DNS.1 = cervesur-interno.invalid", "pathlen:0", "subjectAltName = IP:$IP", "extendedKeyUsage = serverAuth", "CA:FALSE",
                       "-aes-256-cbc", "no es privada", "Ninguna de las --red indicadas contiene la IP"):
            self.assertIn(needed, ca)
        self.assertNotRegex(ca, r"scp\s|ssh\s|curl\s")  # nunca envía la clave a ningún sitio
        self.assertNotIn("CN=$IP", ca)  # sin CN con forma de nombre: OpenSSL le aplicaría las restricciones DNS de la CA

    def test_one_shot_publication_orchestrator_checks_first_and_rolls_everything_back(self):
        orq = source("deploy/tunel-ssh-piloto-usuarios/scripts/publicar-ip.sh")
        for needed in ("PUBLICAR-IP", "REVERTIR-IP", "--simular", "actualizar.sh\" --simular", "corporativo.sh\" preflight", "--previo", "deshacer()", "revertir_publicaciones", "actualizar.sh\" --revertir", "SHA256SUMS"):
            self.assertIn(needed, orq)
        code = "\n".join(l for l in orq.splitlines() if not l.lstrip().startswith("#"))
        self.assertNotRegex(code, r"a2enmod|a2dismod|systemctl\s+(restart|stop)|apache2ctl\s+(restart|stop)|\bmysql\b|ufw\s|iptables|curl\s[^\n]*-k\b")
        for doc in ("PUBLICACION-IP-INTERNA.md", "GUIA-QA-PUBLICACION-IP.md"):
            self.assertTrue((ROOT / "docs" / doc).is_file(), doc)
        self.assertIn("[0-9]{1,3}(\\.[0-9]{1,3}){3}", source("frontend/public/.htaccess"))  # sin HSTS para IPv4 ni localhost


class AdminPilotWriteLayerIsUnreachable(unittest.TestCase):
    """UX-08G · versión 1 (solo lectura): la capa de escrituras de UX-08 se distribuye pero NO es alcanzable en el modo piloto_admin."""

    def test_admin_router_never_uses_the_write_layer(self):
        code = source("backend/router/AdminRouter.php")
        for symbol in ("write_support", "db_tx(", "run_write(", "audit_record(", "user_admin_writes_enabled", "->create(", "->update(", "->delete(", "->reactivate("):
            self.assertNotIn(symbol, code, symbol)

    def test_route_gate_of_piloto_admin_only_allows_reads_and_session_routes(self):
        mode = source("backend/lib/mode.php")
        block = mode[mode.index("if ($mode === 'piloto_admin') {"):mode.index("if ($mode === 'piloto_visual' || $mode === 'piloto_usuarios') {")]
        self.assertIn("'GET' =>", block)
        self.assertRegex(block, r"'POST' => \['~\^/api/login\$~D', '~\^/api/logout\$~D'\]")
        self.assertNotRegex(block, r"'(PUT|DELETE|PATCH)'")
        for forbidden in ("/api/auditoria", "/api/users/", "reactivate", "deactivate", "/api/register"):
            self.assertNotIn(forbidden, block, forbidden)

    def test_index_stops_at_the_admin_router_before_any_other_router(self):
        index = source("backend/router/index.php")
        gate = index.index("app_mode_route_gate(")
        admin = index.index("AdminRouter.php")
        auth = index.index("auth.php", admin)
        self.assertLess(gate, admin)
        self.assertLess(admin, auth)

    def test_user_admin_writes_flag_cannot_enable_writes_in_a_read_only_mode(self):
        code = source("backend/lib/write_support.php")
        self.assertIn("getenv('USER_ADMIN_WRITES') === '1'", code)
        self.assertIn("piloto_admin", source("backend/lib/mode.php"))


class AdminEscrituraStaticChecks(unittest.TestCase):
    """UX-08H: el modo de escritura es explícito, cerrado y no debilita a «piloto_admin»."""

    def test_pilot_admin_is_unchanged_in_its_five_layers(self):
        mode = source("backend/lib/mode.php")
        # piloto_admin sigue exigiendo el modo de solo lectura: la rama de escritura es otro modo con su propia puerta
        self.assertIn("if ($mode === 'admin_escritura') {", mode)
        self.assertIn("app_mode_write_gate($method, $uri)", mode)
        db = source("backend/lib/db.php")
        self.assertIn("new GuardedPDO", db)
        self.assertIn("SET SESSION TRANSACTION READ ONLY", db)
        # el interruptor histórico no sirve para saltarse piloto_admin
        write_support = source("backend/lib/write_support.php")
        self.assertIn("ADMIN_WRITES_USUARIOS", write_support)

    def test_write_mode_requires_audit_and_strict_views(self):
        text = source("backend/lib/admin_escritura.php")
        self.assertIn("AUDIT_LOG_MODE", text)
        self.assertIn("REACT_STRICT_VIEWS", text)
        self.assertIn("class WriteGuardedPDO extends PDO", text)

    def test_every_capability_is_off_unless_exactly_one(self):
        text = source("backend/lib/admin_escritura.php")
        self.assertIn("=== '1'", text)
        self.assertNotIn("filter_var(getenv('ADMIN_WRITES", text)

    def test_write_account_template_has_no_delete_ddl_or_grant_option(self):
        tpl = source("database/privileges/UX-08H-cuenta-escritura-produccion.sql.template")
        grants = "\n".join(l for l in tpl.splitlines() if not l.strip().startswith("--"))
        self.assertNotRegex(grants, r"(?i)\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE ROUTINE|SUPER)\b")
        self.assertNotRegex(grants, r"(?i)GRANT\s+SELECT\s*\([^)]*\bpassword\b")
        self.assertNotRegex(grants, r"(?i)GRANT\s+(SELECT|INSERT|UPDATE)\s*\([^)]*powerbi_")
        self.assertRegex(grants, r"(?i)GRANT SELECT ON `\{DB\}`\.`audit_log`")
        self.assertRegex(grants, r"(?i)GRANT INSERT ON `\{DB\}`\.`audit_log`")
        self.assertNotRegex(grants, r"(?i)GRANT\s+UPDATE[^;]*ON `\{DB\}`\.`audit_log`")

    def test_audit_is_written_in_the_same_transaction(self):
        text = source("backend/lib/write_support.php")
        self.assertIn("function audit_record", text)
        self.assertIn("function db_tx", text)
