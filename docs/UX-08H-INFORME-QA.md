# UX-08H · Informe de QA de la Fase 2

> **Actualización UX-08H.1:** se descartó el QA aislado (8095); la ruta vigente hacia producción está en `docs/UX-08H1-LIBERACION-DIRECTA-PRODUCCION.md`. Fase 2B (Usuarios) está **bloqueada por código** sobre la base productiva. Las secciones sobre el kit QA de este documento son históricas.

Rama `feat/cervesur-ux-08h-admin-writes`. Todo se ejecutó en **Docker aislado con datos sintéticos** (MySQL 8.0 desechable, red interna). **No se tocó** producción, SSH, Apache, `cervesur_portalbi`, Flutter, 8443, QA 8094 ni DB031. Lo no ejecutado está marcado como **pendiente**.

## 1. Resultados observados
| Suite | Qué cubre | Resultado |
|---|---|---|
| `tests/admin_write_unit.php` (sin red ni BD) | configuración fail-closed, puerta de rutas por capacidad (25 rutas de escritura, 17 de lectura), rutas fuera de la tabla, guardia de SQL, privilegios de la cuenta, controles 2B | **55 PASS / 0 FAIL** |
| `tests/test_security_static.py` | `piloto_admin` intacto, inventario de archivos que escriben, plantilla de cuenta sin DELETE/DDL/GRANT OPTION/`password`/`powerbi_*`, auditoría transaccional | **100 OK** |
| `tests/isolated/http_admin_escritura_test.php` (backend **real**, cuenta MySQL **mínima**) | arranque fail-closed, autenticación y lista blanca, privilegios reales, capacidades por servidor, CRUD y bajas lógicas de empresas/informes/accesos/usuarios, duplicados 409, versión vencida 409, integridad de asignaciones, enlace de solo escritura, auditoría atómica (falla de auditoría ⇒ no se confirma), concurrencia (6 altas simultáneas ⇒ 1 gana), revocación de sesiones, ausencia de filtraciones | **0 FAIL** (suite completa) |
| `tests/isolated/flutter_compat_test.php` (referencia **reconstruida**) | escenarios F1–F8 de §3 | **0 FAIL** |
| **Réplica completa de la CI** (fase 1 + fase 2, un solo MySQL) | regresión: las 1501 comprobaciones de la fase 1 siguen verdes junto a las nuevas | **1604 PASS / 0 FAIL**, salida 0 |
| Vitest | cliente HTTP, capacidades, enlace de solo escritura, seguridad de endpoints, UI | **289 PASS / 0 FAIL** (27 archivos) |
| `tsc --noEmit`, ESLint (`--max-warnings 0`) | tipos y estilo | limpios |
| Build `build:pilot-admin` y `build:admin-write` + `verify` + `scan:secrets` | las dos compilaciones; sin secretos; el bundle de `piloto_admin` no contiene la llamada a `/api/admin/capacidades` | OK |
| E2E de navegador `frontend/e2e/run-admin-write.mjs` (Edge/CDP → build `admin-write` → backend real en 3 instancias) | alta con **doble envío** (1 sola fila), RUC duplicado con mensaje comprensible, informe con enlace, edición **sin ver ni reenviar el enlace**, listado sin enlaces, botones según capacidades reales (todo / solo empresas / nada), ruta directa sin capacidad ⇒ 403, nota de solo consulta | **22 PASS / 0 FAIL** |
| Kit QA instalable `deploy/qa-escritura/` | ensayo Docker completo (`node deploy/qa-escritura/ensayo-docker.mjs`) | **93 PASS / 0 FAIL**; shellcheck `-S warning` limpio (re-verificado) |

### Defectos encontrados y corregidos por estas pruebas
1. `usr_rol_modo()` trataba `admin_escritura` como rol `user` ⇒ el administrador no podía iniciar sesión.
2. Faltaba `GET /api/empresas/{id}` en la tabla cerrada ⇒ el formulario de edición de empresas fallaba.
3. La cuenta mínima descubrió que la baja de una asignación escribe `empresa_reporte.updated_at` sin permiso (error 1143). Corregido en la plantilla UX-08H **y en la UX-08E** (esta última tenía el mismo hueco).
4. Una edición de informe exigía reenviar el enlace; ahora conserva el vigente si no se envía uno nuevo.

## 2. Matriz de requisitos
| Requisito | Evidencia |
|---|---|
| `piloto_admin` sigue de solo lectura | unit + estáticas + HTTP de la fase 1 dentro de las 1604 |
| Modo nuevo explícito; no se usa `USER_ADMIN_WRITES` | `admin_write_unit` + estática (el `.env` del QA no lo lleva; `verificar.sh` lo comprueba) |
| Cuenta MySQL nueva y mínima | plantilla UX-08H; HTTP: sin DELETE, sin leer `password`/`powerbi_*`, `audit_log` inmutable; `SHOW GRANTS` verificado al arrancar |
| Auditoría en la misma transacción | HTTP: quitar `INSERT` en `audit_log` ⇒ 5xx y **sin** cambio (empresa y edición) |
| Autorización de rutas en el backend | HTTP + E2E: 403 con capacidad apagada y rutas fuera de la tabla |
| Visor con la misma política | HTTP: el enlace solo sale por el visor; ninguna otra respuesta lo lleva |
| Escrituras apagables desde el servidor | HTTP: instancias `appaw0`/`appawe`/`appawna`; efecto sin redeploy |
| Usuarios con interruptor independiente | HTTP + unit |
| `grupo_id` NOT NULL, `reporte_id` único, `enlace` ≤ 500, bajas lógicas, sin DB031, sin enlaces públicos generados | HTTP (enlace sin grupo ⇒ rechazado; sin DELETE) |

## 3. Compatibilidad con Flutter (reconstruida)
`flutter_compat_test.php` reproduce **solo** lo verificado en la evidencia 07 de producción (login con `is_active=1`, JWT HS256, middleware que revisa `is_active` en cada petición y compara `session_version` solo si el token la trae, rol tomado del JWT). **No es el backend real**: no está disponible en este entorno.
| Escenario | Resultado |
|---|---|
| F1 usuario creado desde React entra por Flutter | OK (hash compatible, rol no admin) |
| F2 desactivar | Flutter rechaza tokens modernos **y** antiguos de inmediato |
| F3 reactivar sin espera | **Riesgo R2 confirmado**: el token antiguo revive |
| F4 reactivar con espera de 24 h | 409 antes de la espera; token antiguo sigue rechazado; permitido pasado el plazo |
| F5 cambio de rol | token moderno cae; el antiguo **conserva el rol viejo** (R3) ⇒ tras interruptor aparte |
| F6 cambio de contraseña | contraseña nueva rige; token antiguo sobrevive (R4) ⇒ tras interruptor aparte |
| F7 `session_version` compartido | el logout de Flutter cierra también la sesión de React del mismo administrador |
| F8 último administrador | no se puede desactivar |

## 4. Riesgos pendientes
1. **Flutter real sin probar** (bloquea Usuarios, ver plan §7). 2. R2–R4 solo se **mitigan** (espera, interruptor sensible), no se eliminan: se cierran del lado de Flutter. 3. Flutter escribe las mismas tablas sin transacciones y con una cuenta amplia: sin coordinación de escrituras simultáneas. 4. `log_bin_trust_function_creators=0` exige `SUPER` para crear los triggers (M2). 5. La espera se calcula con la bitácora: un usuario desactivado fuera de React no tiene fila y no queda sujeto a espera. 6. Las pruebas usan datos sintéticos y MySQL 8.0 de Docker; falta la corrida en el MySQL del servidor.

## 5. Kit QA y ensayos de actualización/reversión
Ver `deploy/qa-escritura/README.md` y el resultado del ensayo Docker (instalar → verificar → escritura real → apagar interruptor ⇒ 403 → `actualizar --simular` → actualizar → fallo de verificación forzado ⇒ reversión automática → `revertir`, con los portales señuelo idénticos antes y después). **Resultado observado: 93 PASS / 0 FAIL.** Cubre instalar, `verificar` en GO, una escritura real con su fila de auditoría, 403 con el interruptor apagado, `actualizar --simular`, actualización real, fallo de verificación forzado con reversión automática, fallos forzados al reemplazar frontend/backend, respaldo corrupto, paquete PHP roto, `--revertir` manual y desinstalación; Flutter, los directorios señuelo de 443/8443 y la base señuelo de `cervesur_portalbi` quedan **idénticos byte a byte**. Puerto candidato 8095 (el 8094 queda reservado al otro QA). Límites: el ensayo corre en Docker; no se probó contra el MySQL ni el Apache reales (log_bin/SUPER, resolución de la cuenta `@127.0.0.1`). El ensayo tarda 5–8 min.

## 6. Cómo repetir las pruebas (sin tocar nada real)
```bash
# réplica completa de CI (Docker): extraer el paso «Run HTTP tests…» de .github/workflows/backend-ci.yml y ejecutarlo con
# MSYS_NO_PATHCONV=1 GITHUB_RUN_ID=<n> GITHUB_RUN_ATTEMPT=1 GITHUB_WORKSPACE=<ruta de la rama>
python -m unittest tests.test_security_static
docker run --rm -v "<rama>:/w" -w /w cervesur-ci-php:local php tests/admin_write_unit.php
cd frontend && npx tsc --noEmit && npx eslint src --max-warnings 0 && npx vitest run
CERVESUR_ALLOWED_HOSTS=127.0.0.1 npm run build:admin-write && npm run scan:secrets   # E2E: node e2e/run-admin-write.mjs (con 3 instancias del backend publicadas en 18080/18081/18082)
```
