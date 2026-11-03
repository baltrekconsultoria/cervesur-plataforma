# UX-08G · Informe de liberación: RELEASE CANDIDATE «versión 1 · solo lectura» del administrador React (8443)

Fecha: 2026-11-04 · Rama `feat/cervesur-ux-08g-release-candidate` (worktree `wt-ux08g`, base `36eef81`) · Nada se instaló, ejecutó ni transfirió a ningún servidor; sin SSH, push ni merge.

## 0. Resumen ejecutivo
1. **Hallazgo principal:** el administrador que corre en producción **no es la aplicación de UX-08**. Es el *piloto administrativo* (UX-07D.3A → UX-07E.D), modo `piloto_admin`, **de solo lectura por diseño** (5 capas) y **sin un solo commit**: vive como cambios sin confirmar y archivos sin seguir en el worktree `wt-ux07d3a` (168 archivos, rama `feat/cervesur-ux-07d3a-readonly-access`, última construcción `cef58da900e8`, 2026-10-30). Hasta hoy el código de producción no tenía control de versiones.
2. **Consecuencia:** las escrituras de UX-08 **no se pueden “activar” sobre ese administrador**: el modo `piloto_admin` las prohíbe a propósito (cuenta con escritura sobre una base productiva = el arranque se rechaza). Habilitarlas es una **fase 2** con un modo nuevo, una cuenta nueva y migraciones (diseño en `docs/UX-08G-PLAN-ESCRITURAS-Y-MIGRACIONES.md`).
3. **Lo entregado como RC (fase 1):** el código del piloto **importado tal cual** (un único commit de importación y fusión; la procedencia archivo por archivo, con su SHA-256 original, queda en `deploy/produccion-admin/procedencia-piloto-wt-ux07d3a.txt`) y **fusionado** con la interfaz y el código de UX-08 (filtros activos por defecto, paginación de 25, páginas de administración, contrato V2), conservando todos los controles del piloto, más endurecimientos: un informe inactivo ya no entrega enlace; las 14 rutas nuevas de UX-08 están probadas como inalcanzables aun con `USER_ADMIN_WRITES=1`.
4. **Recomendación:** **GO** para auditoría y *simulación* con el paquete de la fase 1; **NO-GO** para activar en 8443 hasta cerrar los pendientes de §12 (evidencia real del servidor y autorización expresa).

## 1. Qué se encontró y cómo se obtuvo
Los archivos de evidencia 01–08 de UX-08F.2 (incluidos 03a/03b/03c/06b) **no estaban en el equipo ni en esta conversación**, así que no pude revisarlos. La reconciliación se hizo con la **fuente real del piloto**: `wt-ux07d3a` y su paquete `cervesur-react-admin-703e92d91c6a-con-cambios-cef58da900e8-20261030-041810Z` (hosts autorizados `localhost` y `192.168.200.12`, modo `admin`). **Hipótesis a confirmar con el servidor:** que lo desplegado equivale a ese paquete. Se confirma con `inventario-divergencia.sh` pasando `deploy/produccion-admin/manifiesto-piloto-cef58da900e8.txt` (huellas de ese paquete) como **segundo argumento**, en lugar del manifiesto del repositorio.

Arquitectura de publicación real (de `docs/PUBLICACION-IP-INTERNA.md` del piloto): Flutter `http://192.168.200.12:80` · trabajadores `https://192.168.200.12:443` (misma app/backend que 8092) · **administrador `https://192.168.200.12:8443` (VirtualHost `cervesur-react-admin-corp`, misma app y backend que el 8093 de túnel: `/var/www/cervesur-react-admin`, `/opt/cervesur-react-admin`)**, con restricción de redes obligatoria, TLS de una CA privada y sin HSTS. Tres conexiones MySQL: catálogo `cervesur_ro_catalogo_adm`, autenticación `cervesur_ro_auth_adm` (ambas SELECT por columna) y estado propio `cervesur_react_estado_adm` (base `cervesur_react_estado_admin`, DML solo ahí).

## 2. Etapa A · Matriz de conciliación (repositorio UX-08 · QA 8094 · administrador publicado)
| Componente | Publicado (piloto) | QA 8094 / UX-08 | Decisión |
|---|---|---|---|
| `lib/mode.php`, `pilot_sesion.php`, `estado_piloto.php`, `piloto_catalogo.php`, `admin_catalogo.php`, `usuarios_catalogo.php`, `db_usuarios.php`, `diagnostico_catalogo.php`, `inventario_esperado.php` | sí (control de modo, sesiones, catálogos cerrados) | no existen | **Conservar** (importados byte a byte) |
| `router/AdminRouter.php`, `PilotoRouter.php`, `UsuariosRouter.php`, `DiagnosticoRouter.php`, `controllers/DiagnosticoController.php`, `cli/piloto-usuarios.php` | sí | no existen | **Conservar** (AdminRouter con un único endurecimiento: visor solo de informes activos) |
| `lib/db.php`, `controllers/AuthController.php` | modificados por el piloto (modo, columnas explícitas, sin `last_login` en solo lectura) | UX-08 no los cambia | **Conservar versión del piloto** |
| `router/index.php`, `router/auth.php` | puerta de rutas y routers del modo | UX-08 registra rutas nuevas | **Integrar** (fusión a tres vías sin conflictos; la puerta de rutas va antes de todo router de UX-08) |
| `models/EmpresaModel`, `ReporteModel`, `AsignacionModel` | columnas explícitas (permisos por columna), sin `powerbi_*` en solo lectura | filtro de estado, capa de escrituras | **Integrar** (3 conflictos resueltos a mano: se conservan las columnas explícitas y se añade UX-08) |
| `lib/write_support.php`, `status_filter.php`, `views.php`, `roles.php`, `admin_viewer_allowlist.php`, controladores/modelos/routers de escritura, `AuditoriaRouter` | no existen | sí | **Sustituir después de validar → hoy inalcanzable**: se distribuyen pero ninguna ruta los alcanza en `piloto_admin` (pruebas §8). `ADMIN_VIEWER_ALLOWED_REPORT_IDS` **no aplica** en producción |
| Frontend `pilot.ts`, `DeniedState`, `Dialog`, `OtherReportsDialog`, `LoginPage`, `ViewerPage`, estilos | sí | no | **Conservar** |
| Frontend `AdminPages`, `App`, `httpApi`, `adapters`, `types`, `layout` | consulta de solo lectura | UX-08A/B | **Integrar** (fusión limpia salvo `App.tsx`: las rutas de escritura/historial quedan tras `!ADMIN_PILOT`) |
| `deploy/tunel-ssh-piloto*`, `deploy/tunel-ssh-readonly`, `database/schema/piloto-*` | instaladores, `actualizar.sh` (con reversión automática), `usuarios.sh`, `corporativo.sh`, SQL de cuentas | — | **Conservar** (son el mecanismo de actualización y reversión de producción) |
| `deploy/qa-admin-aislado/`, `database/migrations/`, `database/privileges/` | — | QA / fase 2 | **No se distribuyen** en la fase 1 (el empaquetador y el preflight lo exigen) |
| Pruebas (`mode_unit`, `http_admin_piloto_test`, suites `piloto_*`) | sí | — | **Conservar** y ampliar |

Regresiones de compatibilidad detectadas y tratadas: (a) el inventario de archivos que escriben SQL (`EXPECTED_WRITERS`) rechazó `write_support.php` → añadido de forma explícita y comentada junto a una prueba nueva `AdminPilotWriteLayerIsUnreachable`; (b) el flujo `status=` de UX-08A funciona en producción **en el cliente** (el administrador pide `status=all` y filtra/pagina en pantalla; solo `/api/users` filtra en servidor); no hay cambio de servidor.

## 3. Etapa B · Autorización de Power BI (mecanismo real)
- **Quién decide el 403:** `AdminRouter` (ruta `GET /api/reportes/id/{id}/visor`) mediante `usr_visor_autorizado()`: el id debe figurar en **`CERVESUR_PILOT_VISOR_IDS`** del `.env` **de esa instancia** (vacío = ninguno; `TODOS` existe pero exige frase aparte y no debe usarse). **Dónde se guarda:** el `.env` (se lee en cada petición, sin recargar Apache). **Cómo se gestiona:** `scripts/usuarios.sh informes --listar | --agregar 72 | --quitar | --retirar` con bitácora `/var/backups/cervesur-react-admin/autorizaciones-informes.log`. Los listados nunca llevan enlaces (solo `visor_autorizado` 0/1); cada apertura se audita (`visor_abierto`, `visor_no_autorizado_piloto`).
- **UX-08 no introduce un segundo control en producción:** su `ADMIN_VIEWER_ALLOWED_REPORT_IDS` solo opera en `read_write` (QA). Los id **31 (QA, sintético) y 72 (DB031 real) no son intercambiables** y no se mezclan: la QA no toca la lista de producción.
- **Endurecimiento incluido:** `reporte_visor` ahora exige `activo = 1`; un informe inactivo devuelve 404 sin enlace aunque esté autorizado (igual que el piloto visual).
- **Pruebas (CI local):** autorizado → 200 con URL; no autorizado → 403 `visor_no_autorizado` sin `url`; inexistente → 404; **inactivo → 404 sin URL aun con `TODOS`**; los parámetros de URL (`?id=`) no cambian el informe; el detalle y los listados no incluyen `enlace`; trabajadores intactos (suite `http_usuarios_test`, 8092).
- **Estado de DB031 (72):** *no se habilita ni se toca*. La lista actual de producción es **desconocida** (necesito `usuarios.sh informes --listar`, §12 P-1). Para autorizarlo el procedimiento ya existe y es el del piloto; falta la clasificación de confidencialidad del responsable de datos (la matriz del piloto lo marca «probablemente C», es decir **no apto para enlace público**).
- **Riesgo permanente:** los enlaces «Publicar en la web» son públicos para quien los conozca; el portal solo controla a quién se **entrega**. Si el informe es confidencial, la alternativa es inserción «para su organización» o Embedded con RLS (`docs/POWERBI-PUBLICAR-WEB-RIESGO.md`).

## 4. Etapa C · Seguridad de Usuarios y compatibilidad con Flutter
Sin cambios de comportamiento en la fase 1 (solo lectura). R1–R5, condiciones de cierre y el arnés de pruebas sobre copia aislada están en `docs/UX-08G-PLAN-ESCRITURAS-Y-MIGRACIONES.md` §4. **Matriz de riesgos actualizada:** R1/R2/R3 verificados en código (sin control técnico desde React → regla operativa de caducidad del token), R4 *reducido* en producción (en `piloto_admin` el cierre de sesión revoca la sesión opaca en la base de estado propia y **no escribe `session_version` en `user`**; las sesiones del administrador no cruzan con las de Flutter), R5 aceptado (sin escrituras de React no hay carrera). **Escrituras de Usuarios: bloqueadas.** El arnés de compatibilidad con el backend original de Flutter sobre una copia **no está construido** (P-13).

## 5. Etapa D · Base de datos
La fase 1 **no cambia ningún objeto de `cervesur_portalbi`**. Plan completo (DDL exacto, precondiciones, efecto en Flutter, bloqueo, verificación, reversión y casos no reversibles), cuenta de escritura mínima y habilitación progresiva: `docs/UX-08G-PLAN-ESCRITURAS-Y-MIGRACIONES.md`. UX-07C no se aplica. La variante de la cuenta con `SELECT` por columnas está **sin escribir ni probar** (P-12).

## 6. Etapa E · Paquete productivo
`deploy/produccion-admin/` (envoltura estricta del empaquetador del piloto administrativo): `empaquetar.mjs` (exige árbol limpio, hosts explícitos, inspecciona el paquete: sin `.env`/claves/certificados/`syn###`/datos de QA/migraciones; escribe `RELEASE.json` y SHA-256 fuera del repo), `preflight-produccion.sh` (solo lectura), `probar-preflight.sh`, `manifiesto-piloto-cef58da900e8.txt`. El paquete trae los scripts del piloto (instalar/actualizar/revertir/verificar/usuarios/corporativo). Resultado de la construcción y hash: §9.

## 7. Etapa F · Estrategia de despliegue (decisión pendiente del responsable)
**Hallazgo:** 8443 y 8093 comparten directorios; el mecanismo existente y probado es `scripts/actualizar.sh` (simular → respaldo de `public`/`backend` sin `.env` → reemplazo por renombrado → `verificar.sh` → **vuelta automática** si algo falla → `--revertir <respaldo>`), que **no** toca Apache, `.env`, certificados, cuentas ni la base de estado.
- **Opción A (recomendada, ya implementada):** actualización in situ con ese mecanismo. Impacto acotado: solo el administrador; sin escrituras posibles; reversión automática y manual.
- **Opción B (instalación paralela real):** requiere un puerto nuevo, VirtualHost propio, directorios `-ux08`, base de estado propia y *cuentas MySQL nuevas* (`GRANT` reales) + `Listen`/recarga de Apache: son intervenciones separadas que **no** he preparado porque el kit no las soporta sin reescribirlo. Si el responsable la exige, se diseña aparte (no asumo ningún puerto libre: usar `puerto-candidato-solo-lectura.sh`).
Fases F1–F5 adaptadas a la Opción A: ver `docs/UX-08G-GUIA-DESPLIEGUE-PRODUCCION.md`.

## 8. Etapa G · Rollback (plan independiente por escenario; cada una conserva datos y evidencia)
| Escenario | Detección | Acción | Qué NO hace |
|---|---|---|---|
| 1 Fallo antes del cambio | `actualizar.sh --simular`, preflight | abortar; no se escribió nada | — |
| 2 Fallo de frontend | `verificar.sh` (HTTP) tras el reemplazo | **vuelta automática** a la versión anterior | tocar Apache/.env |
| 3 Fallo de autenticación | `verificar.sh` y prueba manual de login | vuelta automática o `--revertir` | borrar sesiones/auditoría de la base de estado |
| 4 Fallo de autorización Power BI | prueba manual 403/200 | `--revertir`; la lista `CERVESUR_PILOT_VISOR_IDS` no cambia | cambiar la lista |
| 5 Fallo de conexión MySQL | `verificar.sh`; el arranque rehúsa servir (503) si la cuenta tiene más que SELECT | `--revertir` | modificar cuentas |
| 6 Fallo de migración | no aplica en fase 1 (sin migraciones) | — | — |
| 7 Error de auditoría obligatoria | no aplica en fase 1 (`AUDIT_LOG_MODE` sin efecto) | — | — |
| 8 Regresión en Flutter o trabajadores | huella de los demás portales antes/después (el kit la compara y revierte) | vuelta automática | tocar Flutter/443 |
| 9 Activación accidental de escrituras | `USER_ADMIN_WRITES=1` no abre rutas (probado); `interruptor-escrituras.sh` y preflight lo detectan | quitar la variable | — |
| 10 Pérdida de compatibilidad de sesiones | login falla/401 masivo | `--revertir`; las sesiones viven en la base de estado, no cambian | — |
**Prueba de rollback en entorno aislado:** ver §9 (ensayo del kit del piloto: actualizar → verificar → revertir con el paquete nuevo).

## 9. Etapa H · Resultados de QA
> **SUPERADO por `docs/UX-08G1-INFORME-CIERRE.md`:** el paquete de esta tabla (`…36c5135…`) se descartó por el defecto de opcache en `actualizar.sh`; el vigente y sus hashes están en ese documento.

**Paquete de liberación (fase 1, solo lectura)** — construido desde el commit `36c5135452eb` (rama `feat/cervesur-ux-08g-release-candidate`, árbol limpio), fuera del repositorio, en `Desktop/Proyectos Baltrek/Cervesur/paquetes-qa/produccion/`:

| Archivo | SHA-256 del `.tar.gz` |
|---|---|
| `cervesur-react-admin-36c5135452eb-20261103-164618Z.tar.gz` (+ `.sha256`, `.RELEASE.json`) | `57def58c8b8057b26dfe70c8880a63008843dce4265cf2faa5a97b9aa18f07c4` |
| `cervesur-auditoria-solo-lectura-36c5135452eb-20261103-164620Z.tar.gz` (+ `.sha256`) | `e37f3c3cebc5763320c53d03af4b8dc257cf892fd12af16e11501be1495426a1` |

Verificado en el paquete: `SHA256SUMS` interno correcto; hosts `localhost` y `192.168.200.12`; `pilotMode admin`; sin `.env`, claves, certificados, `syn###`, datos de QA ni migraciones; frases de confirmación `ACTUALIZAR-8093` / `REVERTIR-ACTUALIZACION-8093`; incluye `write_support.php` (inalcanzable). El preflight sobre el paquete extraído da PASS en las 7 comprobaciones del paquete; los FAIL restantes son los esperados al no existir la instalación en este equipo.

| Suite (ejecutada sobre ESTA versión, en este equipo) | Resultado |
|---|---|
| Réplica local del paso HTTP de la CI (`backend-ci.yml` fusionado: suites UX-08 + todas las del piloto, MySQL sintético) | **1501 PASS / 0 FAIL** (exit 0) |
| Ensayo Docker del kit del piloto (instala 8092/8093, publicación por IP 443/8443 con navegador real, **actualización reversible `ACTUALIZAR-8093` y `--revertir` bit a bit del administrador**, reversión total) | **339 PASS / 0 FAIL** |
| Pruebas estáticas de seguridad (`test_security_static.py`, incl. `AdminPilotWriteLayerIsUnreachable`) | 95 OK |
| `security_unit.php` / `mode_unit.php` | 0 FAIL |
| Frontend: `tsc`, `eslint`, Vitest | limpio · 272 PASS (256 de UX-08 + 16 del piloto) |
| Compilación `build:pilot-admin`, `verify:pilot-admin`, `scan-secrets` | OK, sin secretos |
| Preflight de producción (`probar-preflight.sh`) | 14 PASS / 0 FAIL |
| 14 rutas/métodos nuevos de UX-08 con sesión de administrador y `USER_ADMIN_WRITES=1` en el entorno → 403 | PASS |
| Visor: informe inactivo → 404 sin URL aun con `TODOS`; `?id=` no altera el informe | PASS |
| shellcheck (`-S warning`) de los scripts nuevos; PHP lint | limpio |

**NO ejecutado / NO afirmado:** la **CI de GitHub no se ha ejecutado** (nada se ha subido); el E2E de navegador de las pantallas de UX-08 (`npm run test:e2e`) no se repitió sobre el árbol fusionado; **no hay QA manual con navegador real ni con Power BI real** (la lista de la guía E4 es para el operador); la QA con credenciales (45/0 en la instalación; 35 PASS / 0 FAIL / 3 omitidas sin credenciales) es del entorno **8094 (modo `read_write`, otro camino de código que producción)** y no se usa como prueba del administrador publicado; la compatibilidad con el backend real de Flutter sobre una copia no existe (fase 2). Etiqueta heredada del piloto: en `http_admin_piloto_test.php` el texto «N rutas…» imprime el número de fallos (0), no el de rutas probadas.

## 10. Entregables → archivos
Paquete y hash (§9) · este informe · `docs/UX-08G-GUIA-DESPLIEGUE-PRODUCCION.md` (operador, PowerShell/PuTTY) · `docs/UX-08G-PLAN-ESCRITURAS-Y-MIGRACIONES.md` (migraciones, cuenta mínima, matriz R1–R5, habilitación progresiva, límites de reversión) · `deploy/produccion-admin/*` (empaquetado, preflight, referencia del piloto) · pruebas nuevas: `AdminPilotWriteLayerIsUnreachable`, ampliación de `http_admin_piloto_test.php`, `probar-preflight.sh`.

## 11. Checklist GO / NO-GO para liberar en 8443
GO requiere **todo**: (1) evidencia 03/divergencia que confirme que lo desplegado equivale al paquete `cef58da900e8` (o diferencias comprendidas); (2) `usuarios.sh informes --listar` revisado; (3) `preflight-produccion.sh` en GO sobre el servidor real; (4) `interruptor-escrituras.sh` PASS en ambos portales; (5) `actualizar.sh --simular` con código 0; (6) respaldo de `.env`, certificados y base de estado hecho por el operador; (7) QA manual §H firmada; (8) autorización expresa y ventana acordada; (9) quien responde por los datos confirma que **no se autoriza ningún informe nuevo** con esta liberación. NO-GO ante cualquier FAIL, `-con-cambios` en el nombre del paquete, o hash que no coincida.

## 12. Pendientes que requieren evidencia o autorización reales
| # | Pendiente | Qué se necesita |
|---|---|---|
| P-1 | Lista actual de informes autorizados y estado de DB031 (72) | salida de `sudo bash scripts/usuarios.sh informes --listar` en el administrador (solo ids) |
| P-2 | Confirmar que lo desplegado = paquete `cef58da900e8` | `inventario-divergencia.sh /opt/cervesur-react-admin/backend deploy/produccion-admin/manifiesto-piloto-cef58da900e8.txt` (el manifiesto es el segundo argumento) |
| P-3 | Evidencias 01–08 de UX-08F.2 (no disponibles para mí) | adjuntar los archivos, especialmente `03*` y `06b`; solo para cruzarlas con §1–§3 |
| P-4 | Flutter P1, P2, P5–P8 | `07-flutter-original.txt` |
| P-5 | Decisión Opción A vs B (§7) | responsable técnico |
| P-6 | Clasificación de confidencialidad de DB031 y de los demás informes | responsable de datos |
| P-7 | Confirmar los hosts del certificado/compilación (`192.168.200.12`) y la ruta de respaldos fuera del servidor | operador |
| P-8 | CI de GitHub nunca ejecutada; el código del piloto no estaba en GitHub | autorizar commit de la rama y ejecución de CI (push/PR = decisión humana) |
| P-12 | Cuenta de escritura con `SELECT` por columnas | fase 2 |
| P-13 | Arnés Flutter sobre copia aislada | fase 2 |
| P-14 | Pruebas de navegador real y Power BI real | operador |
