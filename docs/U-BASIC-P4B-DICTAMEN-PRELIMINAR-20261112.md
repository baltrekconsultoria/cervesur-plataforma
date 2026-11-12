# Cervesur — Dictamen técnico preliminar P4b / U-BASIC
**Corte:** 12/11/2026. **Estado:** PREPARADO, **NO es autorización de activación**. **Proyecto:** portal React admin HTTPS 8443 / backend heredado HTTP 80 / portal usuarios HTTPS 443.

## 1. Decisión ejecutiva

**P4b = NO-GO de activación hasta nueva evidencia y aprobación formal.**
No se ha demostrado que todos los ocho vectores D3 estén cerrados, ni constan en esta acta las firmas y el procedimiento operativo de continuidad del alta de Flutter exigidos por UX-09B v2. No dar GO por un resultado de lint, un GET 401 anónimo o una captura HTTP 200 de capacidades. **No ejecutar `ADMIN_WRITES_USUARIOS=1`.**

## 2. Evidencia ya obtenida, que NO se debe repetir

| Puerta | Evidencia real | Dictamen acotado |
| --- | --- | --- |
| P2 | Respaldo de 7 tablas + 2 triggers audit + 4 tablas de estado restaurado en segundo MySQL nativo sin red; instancia eliminada; no cambió DB productiva | **PASS** para recuperación de datos. GRANTS/DEFINER se atestan aparte |
| P3 | Código Fase 2B detectado en backend 8443 y `mode=admin_escritura`; funcionalidad consulta OK | **PASS** de presencia/lectura |
| P4a | `privilegios-usuarios.sh --etapa no-sensibles --verificar`: `RESULTADO-PRIVILEGIOS: PASS`, vista de alta, DEFINER y 2 triggers | **PASS** sin rol/contraseña |
| D4 | JWT secreto fuerte y TTL 86400; F10 y H1 SHA firmados; K1 retirar HTTP410; 9 fallbacks JWT retirados 9→0; `D4_CRITICOS=PASS` | **PASS** de la atestación efectuada |
| D3 fuentes | Archivos reales de los 8 casos faltantes: SHA-256 y `php -l` 8/8 | **PASS** inventario/sintaxis; NO cierre funcional |
| D3 anónimo | `/api/ping=200`, `/api/user=401`, `/api/users=401` con vhost correcto; verificador D3 2/2 | **PASS** de anónimo; NO prueba 403 por rol normal |
| React usuarios 443 | Sesión normal, 1 empresa, 2 informes, un visor Power BI renderizado visualmente | **PASS** funcional acotado a una cuenta y un informe |
| React admin 8443 | 176/190 usuarios activos, 82/95 reportes, 5/26 empresas, 91 accesos vigentes; lectura de perfil/listados HTTP200 | **PASS** de lectura |
| Flags productivos 8443 | `writes.empresas=true`, `writes.reportes=true`, `writes.accesos=true`, `writes.usuarios=false`, `users_sensitive=false`, `audit=true`, `reactivate_after_hours=24` | **U-BASIC OFF confirmado**; sensibles OFF |

## 3. Matriz de ocho pendientes históricos D3

| ID | Escenario | Evidencia de base | Estado para liberar |
| --- | --- | --- | --- |
| D3-01 | Token/cabecera inválido, adulterado o duplicado | H1 firmado, 2 GET sin Authorization 401; ensayo JWT con fixture preparado en PR #32 | **PARCIAL**, falta resultado real del fixture y variantes de cabecera |
| D3-02 | Baja, revocación, tokens antiguos sin `session_version` | Sesiones RC1c aisladas PASS históricas; riesgo residual descrito en UX-09B | **PARCIAL**, falta prueba sintética de H1 real y criterio formal residual |
| D3-03 | Normalización de URL/override | SHA de `router/index.php` exacto, lint PASS; suite de routers reparados histórica sin elusión | **PARCIAL**, falta cobertura dinámica en el index real |
| D3-04 | Fortaleza secreta JWT/TTL y K1 | D4 v2 productivo PASS, TTL 86400, 0 fallback, HTTP410 | **PASS D4** |
| D3-05 | Controllers/Models de reportes/asociaciones/empresa | SHA de fuentes identificadas, pruebas antiguas con reconstructores | **PARCIAL**, falta prueba con ruta y modelo real |
| D3-06 | Perfil ajeno y cambios de campos no autorizados | `UserModel` real SHA/lint; ruta UserRouter con guardas, pero no HTTP real de rol normal | **PARCIAL**, falta 403 al consultar perfil ajeno y prueba de edición en entorno aislado |
| D3-07 | Alias de registro público y otras rutas | F10 firmado; `StatsRouter.php` y `wiki.php` presentes y válidos | **PARCIAL**, falta demostrar no elusión de rutas alternativas |
| D3-08 | Exposición/alteración de secreto de empresa (K2) | `EmpresaController`/`EmpresaModel` SHA/lint exacto | **PARCIAL**, falta cierre de K2 en código/función real |

**Regla:** no promocionar los 8 casos a PASS por estar físicamente presentes los archivos. Un resultado `D3_AUTH_CRITICOS=PASS_9_DE_9` (si lo entrega el operador) reduce solo D3-01/02; no cierra D3-03/05/06/07/08.

## 4. Condiciones pendientes de GO de P4b

- **Evidencia técnica D3:** completar no-elusión bajo rol `user`/admin para A1/A2/A3, con pruebas contra el código realmente instalado o en una copia aislada fiel; garantizar que HTTP403 es decisión del backend, no solo botones ocultos. No utilizar JWT del portal React 443 en el backend legado 80.
- **Continuidad F10:** corroborar que el alta Flutter que no enviaba JWT no se queda sin alternativa. Procedimiento DBA/custodio firmado y ensayado, o confirmar formalmente que el alta de React sustituye al flujo y funciona. No declarar aprobado un puente solo por un documento.
- **K2/A1/A2/A3 y firmas:** verificar actas A-L1/A-L2/A-L3/A-L4, A-ALTA, DBA, responsable funcional y autorización específica P4b; el código firmado y los tests en CI son condiciones necesarias, no suficientes.
- **Rollback y operación:** mantener backup privado P2; antes de ventana revalidar acceso, `interruptor-usuarios.sh estado`, `privilegios-usuarios.sh --etapa no-sensibles --verificar`, capacidad `users_sensitive=false` y plan de reversión a `apagar`.
- **P5 separado:** roles y restablecimiento de contraseña NO se habilitan en P4b. Las cuentas migradas activas no equivalen a contraseñas inicializadas ni login listo.

## 5. Acciones inmediatas propuestas, sin escritura

1. Ejecutar **una vez** `ops/u-basic/d3_auth_synthetic.php` verificado desde SHA inmutable, en PHP CLI con SQLite RAM; no se proporcionan ni usan tokens reales.
2. Registrar el resumen y riesgos de H1, sin datos personales.
3. Completar prueba estructural y funcional restante A1/A2/A3 conforme a matriz, con autorización y ambiente seguro.
4. Reunir acta formal aprobatoria. **Solo entonces** proponer un cambio a GO y ventana para P4b.

## 6. Comandos de activación, deliberadamente NO AUTORIZADOS todavía

El kit existente es `deploy/produccion-escritura/scripts/interruptor-usuarios.sh`. **No ejecutar `encender`, no editar .env, no conceder GRANT nuevo, no hacer merge ni aplicar SQL por la existencia de esta acta.**

Cuando todas las condiciones estén en GO y el responsable confirme autorización por escrito, el operador utilizará el interruptor oficial con su comprobación de capacidad y rollback. Las primeras altas/ediciones/desactivaciones (P4c) requieren autorizaciones específicas por cuenta y operación, auditoría atómica y verificación de sesiones.
