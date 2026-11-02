# UX-08 · Compatibilidad entre el backend original (Flutter) y los backends React

**Estado: auditoría parcial con evidencia real.** El responsable inspeccionó el servidor por PuTTY (solo lectura) y aportó los hallazgos de §2. Este documento los clasifica en cuatro niveles de certeza y no mezcla hechos con deducciones:

| Etiqueta | Significado |
|---|---|
| **CONFIRMADO** | Observado en el código instalado o en la base (evidencia aportada por el operador) |
| **INFERIDO** | Se deduce de lo confirmado; no se ha observado directamente |
| **PENDIENTE INSPECCIÓN** | Hay que leer más código del servidor (comandos en §6) |
| **PENDIENTE QA** | Solo se sabe probándolo funcionalmente contra una copia, nunca en producción |

No se ha modificado ningún backend productivo ni se ha cambiado la autenticación.

## 0. Actualización UX-08B.3 (tercera ronda de evidencia)

### Nuevo y CONFIRMADO

| # | Hallazgo | Fuente |
|---|---|---|
| O4b | El JWT original contiene `user_id`, `username`, `email`, `role`, `session_version` y `exp`. La condición de login observada es `!$user \|\| !password_verify($password, $user['password'])` (sin `is_active` en ese fragmento). Se actualiza `last_login` | Código instalado (PuTTY) |
| O7b | `cervesur_portalbi_admin`@localhost tiene `ALL PRIVILEGES` sobre `cervesur_portalbi.*` (permisos excesivos para una aplicación basada en bajas lógicas). `cervesur_react_estado`@127.0.0.1 y `cervesur_react_estado_adm`@127.0.0.1: SELECT/INSERT/UPDATE/DELETE sobre sus bases de estado aisladas | `mysql.db` / `SHOW GRANTS` |
| O11 | Existen además las cuentas `cervesur_react`, `cervesur_react_compat`, `cervesur_ro_auth`, `cervesur_ro_auth_adm`, `cervesur_ro_catalogo`, `cervesur_ro_catalogo_adm`, `cervesur_ro_diag`, `cervesur_ro_visor` (con hosts específicos) | `mysql.user` |
| O12 | El piloto administrativo desplegado tiene componentes que no están en este repositorio: `lib/piloto_catalogo.php`, `lib/estado_piloto.php`, `lib/admin_catalogo.php`, `lib/mode.php`, `lib/pilot_sesion.php`, `lib/powerbi_link.php`, `router/AdminRouter.php`, `router/PilotoRouter.php`, `router/ReporteRouter.php` | Listado de archivos |
| O13 | En `cervesur_react_estado_admin` existen `auditoria.reporte_id` y `usuarios_piloto.activo`. **Esto no demuestra dónde está la lista de aprobación de informes**: la consulta solo buscó esos nombres de columna | Consulta a `information_schema` |

### INFERIDO a partir de lo anterior (no observado directamente)

| # | Inferencia | Por qué | Consecuencia |
|---|---|---|---|
| I-A | **El piloto desplegado es de lectura sobre `cervesur_portalbi` y guarda su propio estado aparte** | Cuentas `cervesur_ro_*` (solo lectura por función: auth, catálogo, visor) más una base de estado propia con `auditoria` y `usuarios_piloto` | Las escrituras de UX-08B (usuarios, empresas, informes, accesos) **no tienen hoy un camino de escritura hacia `cervesur_portalbi` en el piloto**. Requieren una decisión de arquitectura (ver `UX-08E-PRIVILEGIOS-MINIMOS.md` §4) |
| I-B | **La sesión del piloto probablemente no vive en `user.session_version`** | Con cuentas de solo lectura, un logout no puede ejecutar `UPDATE user SET session_version…` | R4 (logout cruzado) hoy **no** afectaría a Flutter desde el piloto; reaparecería si React pasara a una cuenta con escritura sobre `user` |
| I-C | El piloto tiene su propio historial (`auditoria`) | Nombre de tabla en la base de estado | `audit_log` de UX-08B sería un segundo mecanismo: decidir si convive o se reutiliza |

### Lo que REALMENTE queda pendiente (nada de lo ya confirmado se repite)

| # | Pendiente | Tipo |
|---|---|---|
| P1 | ¿La consulta que usa el login (`UserModel`) filtra `is_active`? (la condición de `AuthController` no lo hace; el efecto final depende de esa consulta) | Inspección |
| P2 | Valor real de `exp` de los JWT originales | Inspección |
| P5/P6 | ¿Existe en el original algún alta, edición o activación/desactivación de usuarios? ¿Una edición invalida sesiones? | Inspección |
| P7 | ¿El original usa transacciones? | Inspección |
| P8 | Cuenta que usa cada conexión PHP (solo `DB_USER`/`DB_NAME`) y `SHOW GRANTS` de las cuentas efectivas, incluidas las `ro_*` | Inspección |
| Q1 | Comportamiento funcional de Flutter con `grupo_id NULL`, rol `user`, sesiones cruzadas | QA sobre copia |

Bloque único de PuTTY (solo lectura; no repite el mapa del servidor; ningún comando imprime `.env`, contraseñas ni hashes):

```bash
cd /var/www/cervesur/backend
# P1: consultas de usuario por login (solo líneas con is_active/WHERE/FROM user)
grep -n -E "function |is_active|WHERE|FROM user" models/UserModel.php | cut -c1-150
# P2: vigencia del JWT (se omiten líneas que mencionen "secret")
grep -n -i -E "exp|ttl|lifetime|time\(" controllers/AuthController.php lib/*.php | grep -v -i secret | cut -c1-150
# P5/P6: rutas y escrituras de usuario en el original
grep -rn -E "INSERT INTO user|UPDATE user|is_active *=|session_version" --include=*.php . | cut -c1-150
grep -rn -E "\$uri *(===|==) *'/api" router/ | cut -c1-120
# P7: transacciones
grep -rn -c "beginTransaction" --include=*.php . | grep -v ":0"
# P8: cuenta y base de cada backend (solo nombres) y sus privilegios
for d in /var/www/cervesur/backend /opt/cervesur-react-usuarios/backend /opt/cervesur-react-admin/backend; do echo "== $d"; sudo grep -E '^DB_(USER|NAME|HOST)=' "$d/.env"; done
sudo mysql -N -e "SELECT CONCAT('SHOW GRANTS FOR ', QUOTE(user), '@', QUOTE(host), ';') FROM mysql.user WHERE user LIKE 'cervesur%'" | sudo mysql -N
```

## 1. Dónde está cada cosa (CONFIRMADO)

| Componente | Ubicación |
|---|---|
| Flutter (puerto 80) | VirtualHost `/etc/apache2/sites-enabled/cervesur.conf`, `DocumentRoot /var/www/cervesur` |
| Backend PHP original | `/var/www/cervesur/backend` (`lib/auth_middleware.php`, `controllers/AuthController.php`, `controllers/UserController.php`, `models/UserModel.php`, `router/auth.php`) |
| React trabajadores | `/opt/cervesur-react-usuarios/backend` |
| React administrador | `/opt/cervesur-react-admin/backend` |
| Código Dart de Flutter | No encontrado en los directorios inspeccionados |

## 2. Qué hace el backend original (evidencia de PuTTY)

| # | Hallazgo | Nivel |
|---|---|---|
| O1 | El middleware consulta `session_version` e `is_active` de `user` en **cada** petición y **rechaza** si `is_active != 1` | CONFIRMADO |
| O2 | Compara `session_version` del JWT con la de la base **solo si el JWT la trae**; un JWT sin ese campo **continúa** (compatibilidad) | CONFIRMADO |
| O3 | El **rol que usa el middleware sale del payload del JWT**, no de la base | CONFIRMADO |
| O4 | Login: `password_verify`; **en la condición observada no aparece una comprobación de `is_active`**; incluye `session_version` en los JWT nuevos; el rol del token sale del registro; actualiza `last_login` | CONFIRMADO |
| O5 | Logout: `UPDATE user SET session_version = session_version + 1 WHERE id = :id` | CONFIRMADO |
| O6 | El modelo valida roles `admin` y `user`; los datos productivos contienen además `usuario` (62 de 76) | CONFIRMADO |
| O7 | Cuentas MySQL (`mysql.db`): `cervesur_portalbi_admin`@localhost → `cervesur_portalbi`; `cervesur_react_estado`@127.0.0.1 → `cervesur_react_estado`; `cervesur_react_estado_adm`@127.0.0.1 → `cervesur_react_estado_admin`; las tres con SELECT/INSERT/UPDATE/DELETE | CONFIRMADO (nivel base de datos) |
| O8 | Cuál es la cuenta efectiva que usa Flutter y cuáles las de React; privilegios globales, por tabla y roles | PENDIENTE INSPECCIÓN |

DDL productivo (UNIQUE, FK con `ON DELETE CASCADE`, `grupo_id NOT NULL`, `session_version` INT UNSIGNED NOT NULL DEFAULT 1): ver `UX-08B-ESCRITURAS-Y-AUDITORIA.md` §2; sigue vigente.

## 3. Qué hacen los backends React (código de este repositorio, CONFIRMADO)

| Aspecto | Comportamiento | Evidencia |
|---|---|---|
| Token | El JWT **exige** `session_version` entera ≥ 1 (si falta, se rechaza) | `backend/lib/jwt.php` |
| Cada petición | Consulta `session_version`, `is_active` y `role` en MySQL; rechaza si `is_active != 1`, si la versión no coincide o si el rol no es reconocido | `backend/lib/auth_middleware.php` |
| Rol | Se toma **de la base en cada petición**, no del token | ídem |
| Login | Rechaza inactivo, versión inválida o rol no reconocido; respuesta uniforme «Credenciales inválidas» | `AuthController.php` |
| Logout | `session_version + 1` (igual que el original) | `router/auth.php` |
| Campos sensibles | `role`, `is_active`, `session_version`, `id` se rechazan en el cuerpo de cualquier edición de perfil | `router/auth.php` |

## 4. Matriz Flutter / React

Leyenda de compatibilidad: ✔ compatible · ⚠ distinto, con efecto cruzado · ? no determinable todavía.

| Operación | Backend original | Backend React | ¿Compatible? | Riesgo | Impacto | Recomendación |
|---|---|---|---|---|---|---|
| **Login** | `password_verify`; token con `session_version` (O4) | `password_verify` + exige `is_active`, versión y rol válidos | ⚠ | R2 | Un usuario inactivo podría recibir token del original (si ninguna otra capa lo impide) y actualizar `last_login`; el token no sirve en el API (O1) | Verificar el flujo completo (P1). No cambiar el original; React ya es estricto |
| **Logout** | `session_version + 1` (O5) | Igual | ⚠ | R4 | Con la **misma tabla `user`**, un logout en una app invalida los tokens con versión de la otra (la invalidación la decide la base, no el secreto JWT) | Mientras React use bases propias no ocurre. **No apuntar React a `cervesur_portalbi` sin QA de sesiones (§5)** |
| **Usuario inactivo** | Rechazado en cada petición (O1) | Rechazado en cada petición y en el login | ✔ | Bajo | Desactivar a un usuario corta su acceso en ambos, con cualquier token, incluso uno sin versión | Se puede diseñar la desactivación sobre `is_active` con confianza **para el API**; la sesión ya abierta en pantalla de Flutter dejará de funcionar en su siguiente llamada |
| **Revocación de sesión** | Por `session_version`, **solo** si el token la trae (O2) | Por `session_version`, obligatoria | ⚠ | **R1** | Un token heredado **sin** `session_version` no se revoca con logout ni con incremento de versión; vive hasta su caducidad (no observada todavía: P2) | Medir la vigencia real de los JWT (P2). Mitigación futura en el original (acortar vida, exigir versión) requiere autorización y QA; no se toca aquí |
| **Cambio de rol** | El rol efectivo es el del token (O3): un cambio en MySQL **no** afecta a tokens ya emitidos | El rol se lee de la base en cada petición: el cambio es inmediato | ⚠ | **R3** | Un administrador degradado desde React o MySQL **conserva poder de administrador en Flutter** hasta que caduque su token. Un administrador promovido no lo tiene hasta volver a iniciar sesión | Para degradar/desactivar: hacerlo con **`is_active = 0` + `session_version + 1`** (O1 lo hace efectivo de inmediato). Documentar que un cambio de rol no es inmediato en Flutter |
| **Valores de rol** | Valida `admin` y `user` (O6); hay datos `usuario` | React normaliza `usuario → user` al leer; al crear escribe `user` | ✔ (con reserva) | Medio | El valor `user` lo admite la validación del original (O6); si la **pantalla** de Flutter reconoce `user` es PENDIENTE QA | No normalizar ni reescribir roles existentes. Al crear desde React, decidir `user` o `usuario` tras la QA |
| **Creación de usuario** | PENDIENTE INSPECCIÓN (P5) | `POST /api/register` (admin): rol `user`, `is_active=1`, `session_version=1`; 1062 de `username`/`email` ya se mapea a «duplicado» | ? | Medio | Hash de contraseña: ambos usan `password_hash/verify` de PHP (O4); falta confirmar el algoritmo de hash del original | Inspeccionar P5; no crear cuentas reales hasta QA |
| **Desactivación** | PENDIENTE INSPECCIÓN: no consta endpoint (P6) | No existe aún (diseñada en `UX-08H-USUARIOS-DISENO.md`) | ? | Alto si es incorrecta | Debe cumplir O1 (`is_active`) y la regla del último administrador | Implementar solo en local; habilitar tras QA sobre copia |
| **Asignaciones** | PENDIENTE INSPECCIÓN (P6) | Transaccionales y auditadas en local (UX-08B.2); reactivan en vez de duplicar | ? | Medio | Respetan los `UNIQUE` productivos; el original podría crear duplicados lógicos | Confirmar qué escribe el original sobre las tres relaciones |
| **Transacciones** | PENDIENTE INSPECCIÓN (P7) | `db_tx` en Empresas, Reportes y Accesos | ? | Bajo | — | — |
| **Cuenta MySQL y privilegios** | O7/O8 | O7/O8 | ? | **Alto** | Las tres cuentas conocidas tienen `DELETE` a nivel de base; con `ON DELETE CASCADE`, un `DELETE` físico borraría relaciones | No dar nuevas escrituras productivas; cuenta dedicada con privilegios mínimos (ver `UX-08B-DISENO.md` §5) |

### Riesgos, ordenados

| Id | Riesgo | Nivel de certeza | Gravedad | Dónde ocurre |
|---|---|---|---|---|
| R1 | Tokens heredados sin `session_version` no se revocan | CONFIRMADO el mecanismo (O2); su alcance depende de la vigencia (P2) | Alta | Original (Flutter) |
| R3 | Rol del token no refleja cambios de rol hechos después | CONFIRMADO el mecanismo (O3); efecto INFERIDO | Alta | Original (Flutter) |
| R2 | Login del original podría emitir token a un usuario inactivo | CONFIRMADO que la condición observada no lo excluye; efecto final PENDIENTE (P1) | Media (el token no sirve: O1) | Original (Flutter) |
| R4 | Logout cruzado por `session_version` compartido | INFERIDO | Media | Al unificar la base |
| R5 | `DELETE` físico con cascada si se concede el privilegio | CONFIRMADO privilegio (O7) / sin uso en código React | Alta | Cuentas MySQL |

## 5. Pruebas negativas

### 5.1 Contra el backend React, en local (Docker; no tocan servidores)
Implementadas en `tests/isolated/http_session_matrix_test.php` (resultados en `UX-08B2-INFORME.md`):

| # | Caso | Resultado esperado |
|---|---|---|
| 1 | Usuario inactivo intenta iniciar sesión | Rechazado |
| 2 | Usuario activo inicia sesión | Sesión |
| 3 | Usuario desactivado reutiliza su token | 401 |
| 4 | Token con `session_version` antigua (tras logout) | 401 |
| 5 | Token heredado **sin** `session_version` | 401 (a diferencia del original, O2) |
| 6 | Cambio de rol en MySQL con el token anterior | El poder cambia **de inmediato** |
| 7 | Rol inesperado | 401 |
| 8 | Firma o rol del token manipulados / `alg:none` | 401 |
| 9 | Logout y reutilización | 401 |
| 10 | Rutas administrativas con token de trabajador / sin token | 403 / 401 |

### 5.2 Contra el original (solo sobre una **copia** aislada, nunca producción) — PENDIENTE QA
Mismos casos 1–10. Los que se espera que **difieran** y deben medirse: 5 (se esperaría 200), 6 (rol del token, sin cambio), 1 (¿emite token?). Y el caso cruzado: *login en Flutter → logout en React → ¿sigue operando Flutter?* (solo con tabla `user` compartida).

## 6. Comandos de PuTTY, solo lectura, fragmentos mínimos

Ejecutar desde `/var/www/cervesur/backend`. Ninguno imprime `.env`, contraseñas, hashes ni claves; cada uno limita las líneas mostradas. Revise antes de pegar.

```bash
# P1 — ¿el login excluye usuarios inactivos? Muestra solo las consultas SQL de usuario por login
grep -n -E "is_active|WHERE|FROM user" models/UserModel.php | cut -c1-160

# P2 — vigencia del token (líneas con 'exp'; se omiten las que mencionan 'secret')
grep -n -i -E "exp|ttl|lifetime" controllers/AuthController.php lib/*.php | grep -v -i secret | cut -c1-160

# P3 — dónde se usa el rol (solo lugares)
grep -rn "\['role'\]" lib/auth_middleware.php router/ controllers/ | cut -c1-140 | head -60

# P4 — cambio de rol: valores aceptados y efecto sobre la sesión
grep -n -E "function updateUserRole|allowed|admin|user'|session_version|UPDATE user" controllers/UserController.php models/UserModel.php | cut -c1-160

# P5 — alta de usuarios en el original (¿existe?, ¿con qué columnas?)
grep -rn -E "INSERT INTO user|password_hash|register" router/ controllers/ models/ | cut -c1-160

# P6 — rutas que expone el original (sin cuerpos)
grep -rn -E "\\\$uri *(===|==) *'/api|preg_match\('#?\^?/api" router/ | cut -c1-140

# P7 — uso de transacciones
grep -rn -c "beginTransaction" . --include=*.php | grep -v ":0"

# O8 — cuenta y base que usa cada backend (solo nombres; sin contraseña) y sus privilegios reales
for d in /var/www/cervesur/backend /opt/cervesur-react-usuarios/backend /opt/cervesur-react-admin/backend; do
  echo "== $d"; sudo grep -E '^DB_(USER|NAME|HOST)=' "$d/.env"
done
sudo mysql -N -e "SELECT CONCAT('SHOW GRANTS FOR ', QUOTE(user), '@', QUOTE(host), ';') FROM mysql.user WHERE user LIKE 'cervesur%'" | sudo mysql -N
```

También sirve `deploy/auditoria/auditoria-solo-lectura.sh` (ver `UX-08-AUDITORIA-SERVIDOR.md`).

## 7. Qué se puede hacer ya y qué no

| Se puede (local) | Condición |
|---|---|
| Diseñar la desactivación de usuarios sobre `is_active` + `session_version` en una transacción | O1 la hace efectiva en ambos backends para el API |
| Implementarla y probarla en Docker con protección del último administrador | Ver `UX-08H-USUARIOS-DISENO.md` |

| No se hace | Motivo |
|---|---|
| Habilitar desactivación o edición de usuarios en producción | P1, P2, P5, P6 y la QA de §5.2 sin resolver |
| Normalizar o reescribir roles (`usuario`/`user`) | O6: el original mezcla ambos; pantalla de Flutter sin verificar |
| Cambiar la autenticación del original o sus JWT | Fuera de alcance; requiere QA y autorización |
| Dar `UPDATE/INSERT` nuevos o crear cuentas MySQL | Sin `SHOW GRANTS` completo (O8) |
