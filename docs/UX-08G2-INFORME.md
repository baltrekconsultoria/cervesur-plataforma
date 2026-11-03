# UX-08G.2 · Evidencias reales integradas y E1 preparado

Fecha: 2026-11-04 · Rama `feat/cervesur-ux-08g-release-candidate` · Árbol limpio · Sin SSH, push, merge, deploy ni actualización real. Las evidencias del servidor se leyeron **desde `C:\Users\Alexis Casas\Downloads\Cervesur-UX08-Evidencias`** y no se copiaron al repositorio ni a ningún paquete. Se usaron `01, 02, 03a, 03b, 03c, 04, 05, 06b, 07, 08`; `03` y `06` (mal invocadas) se descartaron.

## 1. Paquetes vigentes (sustituyen a los de UX-08G.1: `…b5d6a39…`)
Commit `2327c9cd1c1b`, árbol limpio, en `Desktop/Proyectos Baltrek/Cervesur/paquetes-qa/produccion-v3/`:
| Archivo | SHA-256 del `.tar.gz` |
|---|---|
| `cervesur-react-admin-2327c9cd1c1b-20261103-194642Z.tar.gz` | `01c0725dff43b35715b8f6464f6e5b43f456c4c48c89099ebf16c320fdb5b25e` |
| `cervesur-auditoria-solo-lectura-2327c9cd1c1b-20261103-194643Z.tar.gz` | `6105421034dd01bf59b1f4bc094e0e1d29f5f29541517f2582815ccd18a5d6df` |
`SHA256SUMS` internos verificados; sin `.env`, claves, `syn###`, migraciones; frase `ACTUALIZAR-8093`; **sin dependencia del entorno QA 8094** (la única coincidencia «8094» es el puerto local `18094` del túnel de instrucciones del piloto); el paquete de auditoría trae ahora `identidad-instalada-solo-lectura.sh`, `opcache-solo-lectura.sh` y los manifiestos de referencia.

## 2. Análisis de evidencias (hecho confirmado ≠ inferencia)
| Evidencia | Hechos confirmados | Inferencias / pendiente |
|---|---|---|
| 01 puerto | 8094 sin listener, sin menciones en Apache, se puede vincular a 127.0.0.1; ocupados: 8088–8093, 80, 443, 8443; 22, 3306, 33060 | los `.symbols` de canvaskit que coinciden con «8094» son coincidencias numéricas (falso positivo) |
| 02 interruptor | `USER_ADMIN_WRITES` sin definir en `/opt/cervesur-react-usuarios` y `/opt/cervesur-react-admin`; el código desplegado no contiene el interruptor | — |
| 03a Flutter | 33 archivos PHP, **ninguno exclusivo del servidor**; 21 difieren del repositorio (el repositorio es posterior a Flutter) | — |
| 03b/03c | administrador y trabajadores: 53 archivos; 15 exclusivos del servidor (el piloto), 25 distintos del repositorio, 13 idénticos; 6 del repositorio ausentes (la capa UX-08) | — |
| **04 §3 huellas** | **SHA-256 de cada PHP de los tres backends.** Contrastadas con el paquete `cef58da900e8`: **administrador 53/53 idénticos**; **trabajadores = administrador 53/53** | la *aplicación* (`public/`) no tiene huellas en las evidencias: falta la 12 |
| 04 §2 | claves del `.env` del administrador (solo nombres): `CERVESUR_MODE`, `CERVESUR_REQUIRED_MODE`, `CERVESUR_PILOT_VISOR_IDS`, `CERVESUR_PILOT_SECRET`, `STATE_DB_*`, `AUTH_DB_*`…; **no** hay `CERVESUR_INSECURE_TESTS`, `CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT`, `USER_ADMIN_WRITES`, `AUDIT_LOG_MODE` ni `ADMIN_VIEWER_ALLOWED_REPORT_IDS` | valores ocultos (correcto) |
| 04 §1 | `192.168.200.12:8443` → `cervesur-react-admin-corp.conf`; `127.0.0.1:8093` → `cervesur-react-admin.conf`; 443 → `…usuarios-corp`; Flutter `*:80` | que 8443 y 8093 compartan directorio sale de la documentación del piloto, no de esta salida (los `Define` mostrados son de los demás portales) |
| 06b | el código lee `CERVESUR_PILOT_VISOR_IDS` (2 puntos: validación del modo y `usr_visor_autorizado`); **ninguna tabla guarda aprobaciones de informes** (solo `usuarios_piloto` = administradores autorizados, `auditoria`, `sesiones`, `intentos_login`); bases de estado `cervesur_react_estado` (trabajadores) y `cervesur_react_estado_admin` | el **valor** de la lista (¿está el 72?) sigue desconocido → evidencia 13 |
| 07 Flutter | ver §4 | — |
| 08 | el instalador de la QA 8094 dio **GO** en el servidor real (MySQL 8.0.46, `log_bin=1`, SUPER ok, puerto libre, interruptores PASS); es la simulación del paquete **anterior** (ff3252d), no del RC | no se instaló nada (ya estaba aprobado solo simular) |

## 3. Divergencias conocidas y clasificación
| Componente | Estado | Clasificación |
|---|---|---|
| Backend PHP del administrador (53) | idéntico al piloto `cef58da900e8` (hash a hash, evidencia 04) | **Sin divergencia**; la actualización reemplaza código conocido |
| Los 15 archivos exclusivos del piloto (`mode.php`, `AdminRouter`, catálogos, sesiones…) | presentes e idénticos | **Conservar** (el RC los trae sin cambios salvo `admin_catalogo.php`: visor solo de informes activos) |
| Backend de trabajadores (443) | idéntico al del administrador | no se toca en esta liberación |
| Aplicación `public/` del administrador | **no verificada** | pendiente: `identidad-instalada-solo-lectura.sh` (7 archivos) |
| Archivos extra en el backend instalado (no PHP) | no inspeccionados | pendiente: el mismo script los lista como informativos |
| Flutter (33 PHP) | sin archivos exclusivos; 21 distintos al repo por diseño | fuera de alcance (no se toca) |
Si la evidencia 12 muestra cualquier `DISTINTO/FALTA/SOBRA`, el plan in situ **se detiene** hasta clasificarlo.

## 4. Compatibilidad con Flutter (evidencia 07)
**Hechos:** login original filtra `AND is_active = 1` (inactivos no entran); JWT HS256 con `exp = now + JWT_EXPIRATION` (por defecto **86 400 s** si falta o es inválido; el valor real está oculto); el middleware revisa `is_active` y `session_version` en cada petición, **pero solo compara la versión si el token la trae** (tokens antiguos sin versión pasan); existen `PUT /api/user/{id}/role`, `PUT /api/user/profile`, alta (`/api/register`) y logout que sube `session_version`; **no existe** en el original activar/desactivar usuarios; **ninguna transacción**; Flutter conecta como `cervesur_portalbi_admin` (`ALL PRIVILEGES` sobre la base); React usa cuentas `cervesur_ro_*` de solo `SELECT` por columnas.
**Consecuencias confirmadas:** desactivar a un usuario revoca sus tokens de Flutter de inmediato (por `is_active`); reactivarlo **revive** los tokens aún vigentes (no cambia `session_version`) → R2 real; el rol efectivo en Flutter sale del token (R3, de la auditoría previa); Flutter escribe sin transacciones ni versión (R5).
**Inferencias:** el valor real de `JWT_EXPIRATION` (si es 86 400 el riesgo R2 dura hasta 24 h); que las cuentas `@127.0.0.1` casen con la conexión PHP (confirmado para las `cervesur_ro_*`, que ya funcionan en producción).
**Para el futuro modo de escritura:** exige cuenta de escritura propia (nunca `cervesur_portalbi_admin`), regla operativa de revocación por caducidad (R1–R3), bloqueo optimista ya diseñado (R5) y un arnés con el backend original sobre una copia; en la fase 1 **no aplica** (solo lectura).

## 5. Mecanismo 403 y DB031 (72)
**Confirmado:** el visor entrega el enlace solo si el id figura en `CERVESUR_PILOT_VISOR_IDS` (lista en el `.env`, se lee en cada petición, la gestiona `usuarios.sh informes`), el informe existe y —desde el RC— **está activo**. **Para conservar la política:** la actualización no toca el `.env` (probado bit a bit en el ensayo) y el RC lee la misma variable; no se añade `ADMIN_VIEWER_ALLOWED_REPORT_IDS` ni ninguna lista nueva. **Riesgo para el 72:** si el valor actual ya lo incluyera, el portal entregaría su enlace público; el 13 lo dirá. Esta liberación **no autoriza ni retira** ningún informe.

## 6. Evaluación de la corrección OPcache
- **Hallazgo previo:** una versión rota pasaba la verificación (y la de su rollback) por el caché de opcache.
- **Suficiencia de la protección (4 s):** correcta si `validate_timestamps=1` y `revalidate_freq ≤ 2` (los valores por defecto de Ubuntu: la revalidación ocurre como mucho 2 s después; `file_update_protection` no alarga el desfase). **No hay evidencia de la configuración real** de opcache en el servidor (ninguna salida la incluye).
- **Comprobación preparada (solo lectura, no recarga Apache):** `opcache-solo-lectura.sh` lee `/etc/php/<v>/apache2/php.ini` + `conf.d` (el último valor manda), directivas `php_value/php_admin_value` en Apache y `.htaccess`, calcula la espera mínima (`freq + 2 s`) y dictamina **GO/NO-GO** con el mecanismo exacto: subir `CERVESUR_ESPERA_OPCACHE=N` si `freq` es mayor, o —si `validate_timestamps=0`— una **recarga suave aprobada aparte** (`configtest` + `reload`, nunca reinicio). Probado con 10 casos simulados.
- **Identidad de la versión servida:** (a) *en disco*, nuevo `identidad_disco` en `actualizar.sh`: tras reemplazar, **todos** los archivos de `backend/` y `public/` del paquete deben coincidir con su `SHA256SUMS`; si no, la actualización vuelve atrás; (b) *aplicación servida*: el nombre del bundle JS que entrega Apache se compara con el del paquete (`--paquete` + `--url-local`); (c) *backend en ejecución*: PHP no expone la versión de código por HTTP; se garantiza con la espera de opcache + `verificar.sh` que ejerce las rutas. Tras estos cambios el ensayo del 8443 sigue en **352 PASS / 0 FAIL**.
- **Clasificación:** **GO condicionado a la evidencia 15** (sin ella, NO-GO por falta de datos).

## 7. E1 y E2
Instrucciones literales, argumentos verificados contra el código, resultados esperados, NO-GO y recuperación de evidencias: **`docs/UX-08G2-E1-E2-INSTRUCCIONES.md`**. E2 (`actualizar.sh --simular`): comprobado en el código y en el ensayo que **no** escribe archivos, no toca Apache, no migra, no cambia cuentas ni el `.env`, no habilita escrituras y no cambia la lista de informes; termina antes de crear respaldos.

## 8. QA: ejecutado vs pendiente
| Prueba | Estado | Backend |
|---|---|---|
| HTTP de la CI (1501): autenticación, autorización, listados/paginación, visor, 14 rutas bloqueadas, cuentas MySQL | **ejecutado** (UX-08G; backend sin cambios desde entonces) | **real** (PHP + MySQL) |
| Ensayo del 8443 desde el piloto antiguo (352): instalar, actualizar, fallo con vuelta, rollback bit a bit, otros portales intactos, navegador real por HTTPS | **ejecutado** sobre esta versión | **real** + navegador real |
| Vitest 277, `tsc`, `eslint`, `build:pilot-admin`, scan de secretos | **ejecutado** | — |
| E2E 89 (pantallas UX-08) y 41 (maquetación) | **ejecutado**, pero con **proveedor simulado**: *no* se considera cerrado el E2E con backend real de las pantallas nuevas; lo cubren el navegador real del ensayo (pantallas del piloto) y el HTTP de la CI | simulado |
| Fallo intermitente del verificador del estado de partida (visto 1 vez de 5 pasadas) | **hipótesis, no reproducida**: ocurrió una vez en la instalación inicial del piloto antiguo (comprobaciones `escucha SOLO en 127.0.0.1` y «lista blanca» de `verificar.sh`, sensibles a que Apache/MySQL ya estén listos con la máquina cargada); no afecta a la ruta de actualización y no reapareció en 4 pasadas posteriores | ensayo |
| QA manual de pantallas con navegador real y datos reales | **pendiente** (operador, E4) | real |
| CI de GitHub | **pendiente** (nada subido) | — |
| Power BI real / DB031 | **pendiente y no autorizado** | — |
| Compatibilidad con Flutter sobre copia (fase 2) | **no existe** | — |

## 9. Matriz GO / NO-GO
| Condición | Estado | Quién |
|---|---|---|
| 12: backend PHP = piloto | **cumplida** (evidencia 04) | hecho |
| 12: aplicación `public/` y extras = piloto | pendiente | E1 |
| 10 preflight GO | pendiente | E1 |
| 11 interruptores PASS | **cumplida** (evidencia 02) — repetir en E1 por deriva | E1 |
| 13 sin `TODOS` y ids aprobados | pendiente | E1 |
| 15 opcache GO | pendiente | E1 |
| 14 simulación código 0 | pendiente | E2 |
| copias previas, ventana, responsable | pendiente | operador |
| clasificación de DB031 por el responsable de datos | pendiente (no se habilita) | datos |
| autorización expresa de E3 | **no otorgada** | usted |
**NO-GO** automático: cualquier FAIL, hash distinto, `-con-cambios`, `TODOS` en 13, `USER_ADMIN_WRITES=1`, diferencia sin clasificar en 12, opcache NO-GO sin mecanismo aprobado.

## 10. Riesgos residuales
1. `public/` y archivos extra no verificados hasta E1. 2. Configuración real de opcache desconocida (E1·15). 3. Valor de `CERVESUR_PILOT_VISOR_IDS` desconocido (E1·13); si contiene el 72 hay un enlace público ya entregable. 4. `JWT_EXPIRATION` real desconocido (afecta R1/R2, no a la fase 1). 5. La réplica no es el servidor (cadena de TLS, red restringida, hosts). 6. Respaldo del kit no incluye `.env`: copia manual obligatoria (E3). 7. `actualizar.sh` tiene avisos latentes de shellcheck (`grep -q` con `pipefail`) en precondiciones heredadas, sin fallos observados. 8. Código del piloto aún sin remoto/CI.

## 11. Recomendación sobre la actualización in situ
**Mantener la actualización in situ como vía**, porque el backend instalado es exactamente el conocido, el procedimiento tiene rollback automático y manual probado, y el impacto está acotado al administrador de solo lectura. **No pedir todavía la autorización de E3**: primero ejecutar E1 completo (10–13, 15) y E2 (14). Si E1 sale limpio, solicitar autorización con la matriz de §9.
