# UX-08 · Compatibilidad con el backend original (Flutter) y sesiones

**Estado: INCOMPLETO por falta de acceso autorizado al servidor.** La columna «Backend original» está **sin verificar**: no se ha leído `/var/www/cervesur/backend`. Este documento fija lo que sí consta (código del repositorio y DDL productivo aportado por el responsable) y los comandos de solo lectura que cierran el resto. **No se habilita la desactivación de usuarios en producción hasta completar la columna original.**

## 1. DDL productivo confirmado (`cervesur_portalbi`, MySQL 8.0.46, InnoDB, utf8mb4_unicode_ci)

Aportado por el responsable (`SHOW CREATE TABLE`, solo lectura). Implicaciones para escribir desde React:

| Tabla | Restricciones | Consecuencia para las escrituras |
|---|---|---|
| `user` | PK `id` INT UNSIGNED; `username` VARCHAR(50) **UNIQUE**; `email` VARCHAR(100) **UNIQUE**; `role` VARCHAR(30); `is_active` TINYINT(1) DEFAULT 1; `session_version` INT UNSIGNED NOT NULL DEFAULT 1 | Alta/edición deben validar longitud y mapear el error 1062 a «ya existe» (409), no a un error interno |
| `empresa` | `ruc` VARCHAR(20) **UNIQUE**; `created_by`/`updated_by` FK → `user`; contiene `powerbi_clientsecret` | RUC duplicado = 1062. El secreto jamás se lee, devuelve ni registra |
| `reporte` | `enlace` VARCHAR(500) NOT NULL **UNIQUE**; `reporte_id` VARCHAR(50) NOT NULL **UNIQUE**; `grupo_id` VARCHAR(50) NOT NULL | Enlace repetido = 1062; `grupo_id` obligatorio (la migración UX-07C que lo relaja **no está aplicada** en producción) |
| `empresa_usuario` | **UNIQUE**(`empresa_id`,`user_id`); FK `ON DELETE CASCADE` | Reactivar la fila inactiva en lugar de insertar otra |
| `empresa_reporte` | **UNIQUE**(`empresa_id`,`reporte_id`); FK `ON DELETE CASCADE` | Ídem |
| `user_empresa_reporte` | **UNIQUE**(`user_id`,`empresa_reporte_id`); FK `ON DELETE CASCADE` | Ídem |

Dos hechos cambian lo que decía la auditoría inicial («FK y UNIQUE por confirmar»):

1. **Sí hay UNIQUE reales**: el backend ya reactiva en vez de duplicar (`EmpresaUsuarioModel.php:63`, `EmpresaReporteUsuarioModel.php:82`, `AsignacionModel.php:148`), de modo que no choca con ellos. Una carrera entre dos administradores sí produciría un 1062 que hoy llega como error genérico.
2. **`ON DELETE CASCADE`**: un `DELETE` físico de `user`, `empresa` o `reporte` borra en cascada todas sus relaciones. React solo hace bajas lógicas (`activo = 0`, verificado en los 5 modelos) y **nunca debe recibir privilegio `DELETE`** sobre estas tablas.

## 2. Matriz de compatibilidad

«Backend React» = código de este repositorio (con evidencia). «Backend original» = pendiente (ver §3). «Compatible» solo se marca cuando ambas columnas constan.

| Operación | Backend original | Backend React (evidencia) | Compatible | Riesgo |
|---|---|---|---|---|
| Login | **Sin verificar** | `AuthController.php:35-65`: `password_verify`, exige `is_active = 1`, `session_version` ≥ 1 y rol reconocido; respuesta 200 `success:false` si falla; emite JWT con `session_version`; actualiza `last_login` (`:91`) | No determinado | JWT de React exige `session_version` (`lib/jwt.php:97`); un token sin ese campo se rechaza |
| Logout | **Sin verificar** | `router/auth.php:124-138`: `UPDATE user SET session_version = session_version + 1` — revoca **todas** las sesiones del usuario | **Riesgo probable** | Si Flutter y React comparten `cervesur_portalbi` y Flutter valida `session_version`, un logout en React cierra la sesión de Flutter (y viceversa si Flutter incrementa). Hoy no ocurre porque React usa bases aisladas |
| Usuario inactivo | **Sin verificar** | `lib/auth_middleware.php:107`: cada petición consulta `is_active`; si es 0 → 401. Login lo rechaza (`UserModel.php:217`). Probado: `http_auth_hardening_test.php:129-133` | No determinado | Si Flutter **no** consulta `is_active` por petición, un usuario desactivado desde React seguiría operando en Flutter hasta que expire su token |
| Revocación de sesión | **Sin verificar** | `session_version` en JWT y en cada petición (`auth_middleware.php:114`). Probado (`:134-140`) | No determinado | Desactivar un usuario sin incrementar `session_version` no revoca en un backend que solo mire `session_version` |
| Cambio de rol | **Sin verificar** | `PUT /api/user/{id}/role` (`UserRouter.php:291`), solo admin; el rol se lee de MySQL en **cada** petición (`auth_middleware.php:124`) y un rol no reconocido da 401 (probado `:120-124`) | No determinado | Productivo mezcla `usuario` (62), `user` (10), `admin` (4). React normaliza `usuario`→`user` al leer, pero **un rol escrito por React como `user` puede no ser reconocido por Flutter** (`docs/COMPATIBILIDAD-ROLES-SEC-05A.md:24` lo declara inconcluso) |
| Creación de usuario | **Sin verificar** | `RegisterController.php:77`: solo admin; rol `user`, `is_active = 1`, `session_version = 1`; **sin transacción** | No determinado | Rol `user` frente a `usuario` (ver arriba); 1062 en `username`/`email` hoy llega como error genérico |
| Desactivación | **Sin verificar** | **No existe** endpoint que ponga `is_active = 0` | N/A | Hay que construirlo; su compatibilidad depende de las dos filas anteriores |
| Asignaciones | **Sin verificar** | `POST/PUT/DELETE` en `empresa-usuario`, `asignacion`, `empresa-reporte-usuario`; baja lógica; reactivan filas inactivas; el alta valida usuario/empresa activos (`EmpresaReporteUsuarioModel.php:68`, `EmpresaUsuarioModel.php:56`); **no usan transacciones** (grep sin resultados en `models/` y `controllers/`) | No determinado | Alta de usuario + empresa + informe son 3 escrituras no atómicas: un fallo intermedio deja una relación parcial |

**Conclusión provisional:** con la evidencia disponible, **no es seguro** habilitar la desactivación de usuarios en producción. Lo que se puede afirmar es qué debe cumplir React (`is_active` y `session_version` se verifican en cada petición) y qué hay que averiguar del original.

## 3. Comandos de solo lectura para el operador (PuTTY)

Ninguno modifica archivos, servicios ni datos, y ninguno imprime credenciales. Revise la salida antes de pegarla; si aparece algún secreto, bórrelo.

```bash
# 1) Qué backend sirve cada puerto (Apache)
sudo apachectl -S 2>&1 | head -40
sudo grep -rn -E "ServerName|DocumentRoot|Listen|Alias|ProxyPass|<VirtualHost|SetEnv" /etc/apache2/sites-enabled/ /etc/apache2/ports.conf | sed -E 's/(SetEnv +[A-Za-z_]+ +).*/\1<oculto>/'

# 2) Claves (NO valores) de la configuración de cada backend
for d in /var/www/cervesur/backend /opt/cervesur-react-usuarios/backend /opt/cervesur-react-admin/backend; do
  echo "== $d"; sudo sed -E 's/^([A-Za-z_]+)=.*/\1=<oculto>/' "$d/.env" 2>/dev/null | grep -v '^#' | grep -v '^$'
done

# 3) Base y cuenta que usa cada backend (solo nombres, sin contraseña)
for d in /var/www/cervesur/backend /opt/cervesur-react-usuarios/backend /opt/cervesur-react-admin/backend; do
  echo "== $d"; sudo grep -E '^DB_(HOST|NAME|USER)=' "$d/.env" 2>/dev/null
done

# 4) Privilegios REALES de esas cuentas (sustituya CUENTA y HOST con lo obtenido en el paso 3)
sudo mysql -e "SELECT user, host FROM mysql.user ORDER BY user;"
sudo mysql -e "SHOW GRANTS FOR 'CUENTA'@'HOST';"

# 5) Código de sesión del backend ORIGINAL (líneas, no archivos completos)
cd /var/www/cervesur/backend
grep -n -E "session_version|is_active|last_login|password_verify|beginTransaction|rollBack|UPDATE user|INSERT INTO user" \
  lib/auth_middleware.php controllers/AuthController.php controllers/UserController.php router/auth.php models/UserModel.php

# 6) Qué rutas expone el original
grep -rn -E "\$uri *(===|==) *'/api|preg_match\('#?\^?/api" router/ | cut -c1-140

# 7) Huella de los archivos, para compararlos con este repositorio
sha256sum lib/*.php controllers/*.php models/*.php router/*.php
```

Los pasos 5 y 6 responden las filas «Backend original». Si prefiere, puede enviar los archivos `lib/auth_middleware.php`, `controllers/AuthController.php`, `controllers/UserController.php`, `router/auth.php` y `models/UserModel.php` **excluyendo** `.env`.

## 4. Requisitos para habilitar la desactivación (cuando haya evidencia)

1. Confirmar que el original consulta `is_active` en cada petición **o** que expira por JWT en un plazo aceptable.
2. Desactivar en una sola transacción: `is_active = 0` **y** `session_version = session_version + 1` (revoca en ambos backends si ambos validan versión).
3. Decidir el valor de rol que React escribe (`usuario` o `user`) según lo que reconozca Flutter.
4. Impedir que un administrador se desactive o se quite el rol a sí mismo, y que se desactive al último administrador activo.
5. Probar contra una copia de la base productiva, nunca contra la productiva.
