# UX-07D.4B · Piloto con usuarios reales y permisos individuales

Estado: **solo trabajo local, sin desplegar.** Nada de esto se ha ejecutado contra la VPS, ni existe SQL productivo ejecutado. El modo está **desactivado por defecto**: sin `CERVESUR_MODE=piloto_usuarios` el servicio se comporta exactamente como antes (o se niega a arrancar sobre la base productiva).

## 1. Qué hace

Un usuario existente de Cervesur entra con **su usuario o correo y su contraseña habitual** (se verifica con `password_verify` contra el hash bcrypt que ya existe). Solo ve **sus** informes y empresas, calculados con la cadena de relaciones activas. La sesión es propia del piloto y no toca la base de Flutter.

Condiciones para entrar (todas): el usuario existe · contraseña correcta · `is_active = 1` · rol normalizado `user` (los administradores **no** entran) · `session_version` válida · figura en la **lista blanca** del piloto. Cualquier fallo devuelve la misma respuesta genérica («Credenciales inválidas»), con 400 ms de retraso y `password_verify` siempre ejecutado (hash ficticio si el usuario no existe).

## 2. Arquitectura

```
Navegador ──HTTPS──> Apache/TLS (misma máquina) ──> PHP (modo piloto_usuarios)
                                                       │
        cervesur_ro_catalogo  (SELECT 21 columnas; sin user, sin powerbi_*) ─┐
        cervesur_ro_auth      (SELECT 9 columnas de user; único que lee el hash) ├─> cervesur_portalbi (solo lectura)
        cervesur_react_estado (SELECT/INSERT/UPDATE/DELETE) ──> cervesur_react_estado (base PROPIA)
```

* **Tres cuentas distintas**, comprobadas al arrancar (rehúsa servir si coinciden). Cada conexión tiene un **catálogo cerrado de sentencias**; lo demás se rechaza antes de llegar a MySQL.
* Al arrancar se verifican `SELECT DATABASE()`, la sesión de solo lectura (cuentas de lectura) y `SHOW GRANTS` de la cuenta de estado (solo su base, solo DML).
* El modo es *fail-closed*: configuración inválida → 503 `mode_config`. `CERVESUR_REQUIRED_MODE` impide arrancar en otro modo por descuido.
* Las sentencias de administración (alta/baja de lista blanca, revocación masiva) solo existen en la **CLI**, nunca en el servidor web.

### Variables de entorno

| Variable | Uso |
|---|---|
| `CERVESUR_MODE=piloto_usuarios`, `CERVESUR_REQUIRED_MODE=piloto_usuarios` | activa el modo (y lo exige) |
| `DB_NAME`, `DB_USER`/`DB_PASS` | base de lectura y cuenta `cervesur_ro_catalogo` |
| `AUTH_DB_USER`/`AUTH_DB_PASS` | cuenta `cervesur_ro_auth` |
| `STATE_DB_NAME`, `STATE_DB_USER`/`STATE_DB_PASS` | base y cuenta de estado (≠ base de lectura, ≠ bases protegidas) |
| `CERVESUR_PILOT_SECRET` | ≥ 32 caracteres; clave del HMAC de huellas (login, IP, contraseña) |
| `CERVESUR_PILOT_VISOR_IDS` | `''` (ninguno, por defecto) · `TODOS` · lista de ids |
| `CERVESUR_PILOT_SESSION_SECONDS` | 300–28800 (3600 por defecto) |
| `CERVESUR_PILOT_MAX_FALLOS_IP` | límite por IP (20 por defecto) |
| `CERVESUR_INSECURE_TESTS=SI` | **solo pruebas**; prohibido con una base protegida |

## 3. Sesiones y esquema de estado

Token opaco de 256 bits; en la base solo se guarda su SHA-256. Se **revalida en cada petición** contra el usuario real (activo, rol, `session_version`, huella de contraseña, lista blanca). Una sesión inválida se revoca para siempre.

| Tabla (base de estado) | Contenido |
|---|---|
| `usuarios_piloto` | lista blanca: `user_id`, `activo`, nota, quién/cuándo |
| `sesiones` | `token_hash`, `user_id`, `session_version`, `huella_clave` (HMAC del hash de contraseña), `expira_en`, `revocada_en`, `motivo_revocacion`, huella de IP |
| `intentos_login` | `clave_hash` (HMAC de login o IP), `tipo`, `fallos`, `primer_fallo`, `bloqueado_hasta` |
| `auditoria` | evento, `user_id`, `reporte_id`, huella del login, fecha |

Eventos: `login_ok`, `login_fallido_<motivo>`, `login_bloqueado`, `logout`, `visor_abierto`, `visor_denegado`, `visor_no_autorizado_piloto`, `sesion_invalidada_<motivo>`. **Nunca** se guardan IP, usuario, hash de contraseña ni enlaces en claro.

Se invalida la sesión al: cerrar sesión · desactivar al usuario · cambiar `session_version` · **cambiar su contraseña en Flutter** · cambiar su rol · quitarlo de la lista blanca · revocar por CLI · caducar.

Límite de intentos: 5 fallos/15 min por login (bloqueo 15 min) y 20 por IP.

## 4. Permisos

Un informe/empresa es visible solo si toda la cadena está activa para ese usuario: `user_empresa_reporte` → `empresa_reporte` → `empresa` → `empresa_usuario` (mismo usuario y empresa) → `reporte`. Los listados **nunca** incluyen el enlace. `GET /api/reportes/id/{id}/visor` revalida el permiso en ese instante; un informe ajeno es indistinguible de uno inexistente (404). Desactivar cualquier eslabón lo quita en la siguiente petición.

Rutas permitidas (las demás, 403): `POST /api/login`, `POST /api/logout`, `GET /api/ping`, `GET /api/user/profile`, `GET /api/reportes`, `GET /api/reportes/{id}`, `GET /api/reportes/id/{id}/visor`, `GET /api/empresas`, `GET /api/asignacion`. No hay escrituras en la base de Flutter (ni `last_login` ni `session_version`).

## 5. Interfaz

Build propio: `npm run build:pilot-usuarios` → `dist-pilot-usuarios` (con `CERVESUR_ALLOWED_HOSTS`). Mismo diseño aprobado del 8091: Inicio de sesión («use su cuenta de Cervesur»), Mis informes, Mis empresas, Mi perfil, visor y Cerrar sesión (revoca en el servidor). Sin pantallas de administración (las rutas se eliminan en compilación). Aviso fijo: «PILOTO · Usuarios autorizados · Solo consulta · Sus informes asignados».

## 6. HTTPS (obligatorio)

* El servicio **rechaza con 403 `https_requerido`** toda petición que no llegue por TLS, antes de cualquier ruta (incluido el login). Se acepta `HTTPS` del servidor o `X-Forwarded-Proto: https` **solo** si la conexión viene de loopback (terminador TLS en la misma máquina). Una cabecera enviada desde fuera no cuenta.
* **Despliegue (UX-07D.4B.1):** el piloto se instala con TLS real en su propio socket (`https://127.0.0.1:8092`, solo por túnel SSH; Apache elimina las cabeceras de protocolo del cliente; HSTS solo con nombre propio vía `CERVESUR_PILOT_HSTS=SI`). Las dos opciones (pruebas privadas con certificado autofirmado y futuro acceso corporativo con CA y nombre propio), los datos que faltan por verificar en el servidor y el efecto de habilitar `mod_ssl` están en `docs/HTTPS-PILOTO-USUARIOS.md`.
* Hoy la infraestructura de la VPS **no tiene HTTPS** para este portal: antes de usar el piloto con personas reales debe cumplirse `HTTPS-PILOTO-USUARIOS.md`. No se debe enviar la contraseña de nadie por HTTP.

## 7. Riesgos y pendientes

1. **Los enlaces «Publicar en web» son secretos portadores**: quien tenga la URL ve el informe sin autenticarse. El portal solo controla el *descubrimiento*; revocar el permiso no invalida una URL ya entregada. Requiere decisión del cliente (Power BI Embedded / RLS si se necesita control real).
2. Bloqueo por login permite que un tercero bloquee a un usuario concreto 15 min (compromiso aceptado frente a fuerza bruta); el límite global por IP puede afectar a una oficina tras una misma IP.
3. La contraseña se valida contra el hash de Flutter; el cambio de contraseña se detecta por huella, no por evento. Cerrar sesión aquí no toca la sesión de Flutter.
4. Operación por CLI (sin pantalla de administración): alta/baja de la lista blanca, revocación, desbloqueo, auditoría.
5. La base de estado necesita copia de seguridad y limpieza periódica (sesiones caducadas se purgan de forma oportunista).
6. No se ha podido verificar la estructura real (PK/UNIQUE, tipos) de las tablas de producción; las pruebas usan datos sintéticos equivalentes. Verificar con las consultas de solo lectura antes del piloto.
7. El piloto del operador (8091) sigue siendo independiente; ambos modos no pueden coexistir en el mismo proceso.

## 8. Condiciones para un piloto con ≤ 5 usuarios reales

1. HTTPS operativo y verificado (sección 6).
2. Lista nominal de ≤ 5 personas autorizadas por el cliente, avisadas de que es un piloto de solo consulta.
3. Decisión escrita sobre el riesgo de enlaces públicos (§7.1) y sobre `CERVESUR_PILOT_VISOR_IDS` (informes concretos, no `TODOS`).
4. Las tres cuentas y la base de estado creadas por el administrador de MySQL con el SQL revisado (`database/schema/piloto-usuarios/`), contraseñas generadas en el servidor, `03-verificar-privilegios.sql` sin desviaciones.
5. Preflights de infraestructura, copia de seguridad previa de la base de estado y ensayo de recuperación.
6. Plan de reversión: `piloto-usuarios.php revocar todos`, retirar el modo, `99-revocar-cuentas.sql`.
7. Revisión diaria de `auditoria` durante el piloto.

## 9. SQL para revisión (no ejecutado)

`database/schema/piloto-usuarios/`: `01-cuentas-lectura.sql` (auth: 9 columnas de `user`; catálogo: 21 columnas), `02-estado-base-y-cuenta.sql` (base de estado + cuenta DML limitada a ella), `03-verificar-privilegios.sql`, `99-revocar-cuentas.sql`. Los marcadores `__BASE__`, `__HOST__`, `__BASE_ESTADO__`, `__CLAVE_*__` los sustituye el administrador; no hay contraseñas en el repositorio.

## 10. CLI

`php backend/cli/piloto-usuarios.php` · `listar` · `agregar <id> [nota]` (comprueba que existe, está activo y es rol `user`) · `quitar <id>` · `revocar <id|todos>` · `sesiones` · `desbloquear <login>` · `auditoria`. Solo escribe en la base de estado.

## 11. Pruebas (sintéticas, en Docker)

`tests/mode_unit.php`, `tests/test_security_static.py` (`PilotoUsuariosStaticChecks`), `tests/isolated/piloto_usuarios_privilegios_test.php` (columnas exactas 9/21, suficiencia y necesidad, denegaciones), `tests/isolated/http_usuarios_test.php` (matriz de login sobre 76 usuarios, permisos por usuario = SQL, revocación por eslabón, invalidación de sesiones, throttling, HTTPS, rutas cerradas, sin escrituras en tablas de Flutter, higiene de la base de estado). Ver `.github/workflows/backend-ci.yml`.
