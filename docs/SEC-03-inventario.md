# SEC-03 — Inventario anterior a los cambios

La copia inicial tenía 33 archivos PHP, dos documentos Markdown y un HTML; no tenía repositorio Git, frontend Flutter compilado, Dart, esquema SQL ni manifiesto de dependencias.

- Raíz: `README.md`, `API_DOCUMENTATION.md`.
- `backend/`: `api.php`, `test.html`.
- `backend/lib/`: `auth_middleware.php`, `db.php`, `env.php`, `jwt.php`.
- `backend/controllers/`: `AsignacionController.php`, `AuthController.php`, `EmpresaController.php`, `EmpresaReporteUsuarioController.php`, `EmpresaUsuarioController.php`, `PowerBIController.php`, `RegisterController.php`, `ReporteController.php`, `StatsController.php`, `UserController.php`.
- `backend/models/`: `AsignacionModel.php`, `EmpresaModel.php`, `EmpresaReporteUsuarioModel.php`, `EmpresaUsuarioModel.php`, `PowerBIModel.php`, `ReporteModel.php`, `UserModel.php`.
- `backend/router/`: `AsignacionRouter.php`, `EmpresaReporteUsuarioRouter.php`, `EmpresaRouter.php`, `EmpresaUsuarioRouter.php`, `PowerBIRouter.php`, `ReporteRouter.php`, `StatsRouter.php`, `UserRouter.php`, `auth.php`, `index.php`, `wiki.php`.

Se retiraron valores de respaldo de credenciales antes de inicializar Git. Se sustituyeron literales de ejemplos en la documentación. El inventario no contiene valores ni datos del respaldo productivo. El índice está vacío y no existe una instantánea Git del original. No se creó ningún commit.
