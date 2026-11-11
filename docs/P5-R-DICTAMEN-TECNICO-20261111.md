# Cervesur · Dictamen técnico P5-R y habilitación de Usuarios

**Fecha:** 11/11/2026  
**Estado:** Dictamen para revisión; no es autorización para escribir en producción.

## 1. Decisión por componente

| Componente | Evidencia recibida | Dictamen |
| --- | --- | --- |
| P2 respaldo | .sql.gz y manifiesto existen; `gzip -t` PASS; SHA-256 coincide con manifiesto. Restauración histórica PASS reportada, evidencia de ejecución no localizada por nombre | **PASS integridad**, restaurabilidad histórica referida, no sustituye backup/ensayo de ventana |
| P3/RC1c | 4/4 archivos de backend cotejados contra referencia en 8443 | **PASS código base** |
| P4a | `RESULTADO-PRIVILEGIOS: PASS no-sensibles`, vista `v_user_alta` insertable WITH CHECK OPTION, cuenta mínima, audit_log con triggers | **PASS real MySQL** |
| U-BASIC | Alta, nombres, apellidos, teléfono, activar/desactivar; correo y username existentes sin editar; roles sin modificar | **GO para preparar activación controlada**; **NO-GO para activar** sin K1/K2/A1/A2/A3-F10/D4 y autorizaciones |
| P5-UI | PR #14 draft, formulario fail-closed y validaciones 12–72 bytes | **PASS aislado**, no desplegado |
| P5-R | PR #19 draft, gate `usuarios_password_reset` independiente y roles prohibidos | **PASS pruebas focalizadas previo**; nuevo gate de GRANT y rollback en QA del commit actual |
| JWT backend | PR #15/#20 draft, QA helper y HTTP sintético 64/64 + P5-SEC03 76/76; middleware literal D3 5/5 | **PASS aislado**, sin integración/deploy productivos |
| T-EXP real Flutter | Firebase/Cloud Functions POST HTTP 200 con `result.statusCode: 401`, JSON error 401; Flutter muestra tablas vacías | **PASS rechazo interno observado; FAIL UX 401; recuperación no comprobada** |
| Revocación P5-R en rutas efectivas | No existe prueba que conecte `session_version+1` con rechazo de JWT antiguo en PHP *y* en Cloud Functions usadas por Flutter | **NO-GO productivo** |
| Gestión de roles | No solicitada ni permitida | **EXCLUIDA** |

## 2. Diagnóstico de la ruta real y riesgo

La evidencia de Firefox identifica `QueryBusinessesCall` a un dominio Firebase/Cloud Functions. El transporte devuelve **HTTP 200** pero el JSON encapsula **401**. Esto prueba rechazo de autenticación en una solicitud real, **no** que el token se haya revocado por `session_version+1`, ni cuál capa de autenticación rechazó.

Antes de P5-R, Infraestructura/desarrollo deben demostrar **qué token y qué autoridad valida Cloud Functions** y si comparte el estado de sesiones MySQL/Flutter:
- Si Cloud Functions reenvía JWT PHP y el backend valida `session_version`, se debe demostrar revocación HTTP completa y manejar correctamente el 401 encapsulado en Flutter.
- Si Cloud Functions valida Firebase ID tokens o usa otra autoridad, `session_version` MySQL podría **no revocar ese token**. Requiere mecanismo de revocación en la autoridad efectiva y validación del proxy, además del backend PHP.
- Sin fuente Dart disponible, el defecto UX 401 se gestiona en Issue #21; no modificar `main.dart.js` minificado en producción sin autorización.

En una captura se expuso parcialmente un JWT. No reenviar, publicar ni copiar su valor. Gestionar su invalidación segura con Infraestructura; relogin sin invalidación del token antiguo no es suficiente.

## 3. Liberación separada, no mezclar alcances

### U-BASIC — edición ordinaria
Funciones: crear usuario común mediante `v_user_alta`, editar `first_name`, `last_name`, `phone`, activar/desactivar, consultar usuarios y sus accesos. Mantener `role`, `email` y `username` existentes inalterados.

**Puertas antes de encender:** control de autorización y rol, auditoría obligatoria, P4a ya PASS, K1 `retirar`, K2, A1/A2, A3/F-10 con procedimiento de alta firmado y DBA, D4, C-5 y firmas de negocio/Infra. Las evidencias disponibles NO permiten afirmar que estos últimos estén cerrados en producción. No ejecutar `interruptor-usuarios.sh encender` sin comprobarlas.

**QA funcional mínimo (primera operación autorizada):** cuenta QA de rol común, alta sin escalamiento, editar datos, desactivar/reactivar respetando espera, usuario propio/último admin bloqueados, una auditoría por operación, asignaciones sin modificación, navegación/login Flutter sin regresión. Apagar con kit verificado y devolver 403 ante falla.

### P5-R — contraseña independiente
Funciones: seleccionar cuenta existente activa, contraseña nueva, no autorestauración, hash de servidor, `session_version+1` en misma transacción/auditoría y ninguna modificación de rol/relaciones.

**Puertas antes de activar:** PR #14/#19 y #15/#20 revisados e integrados, bug 401 Issue #21 o decisión funcional formal, prueba real de revocación por *todos* los accesos de Flutter, TTL D4 efectivo (última lectura 10800 s, no cambiar sin decisión), cuenta MySQL con `UPDATE(password)` exclusivamente por columnas y `UPDATE(role)` ausente, rollback ensayado y autorizaciones específicas. `ADMIN_WRITES_USUARIOS_SENSIBLE` debe seguir ausente o 0. Nunca usar `privilegios-usuarios.sh --etapa sensibles`.

**QA de seguridad mínimo:** recuperar JWT anterior sin divulgarlo; efectuar reset con cuenta QA autorizada; **PHP 401** y **ruta Cloud Functions no acepta sesión anterior**, relogin con contraseña nueva y datos visibles, antiguo password rechazado, auditoría exactamente una vez, sin secretos y sin pérdida de asignaciones. Si Flutter muestra tablas vacías con 401 o Firebase acepta sesión previa, **NO-GO**.

## 4. Riesgos del despliegue y rollback

- `GRANT`/`REVOKE` no son transaccionales; la revisión SQL está en `deploy/produccion-escritura/p5r-review/SQL-PASSWORD-ONLY-REVISION.md` y no es un kit aprobado para producción.
- La puerta P5-R requiere además un `SHOW GRANTS` efectivo con `UPDATE(password)` de columna. Ausencia debe ser **503 y NO-GO**, y privilegio amplio o `UPDATE(role)` también bloqueado.
- La rama P5-R actualiza `interruptor-usuarios.sh apagar` para retirar conjuntamente flags U-BASIC y P5-R; es un cambio **candidato**, no instalado aún.
- Backup/rollback desde ventana reciente: conservar rutas y hashes del PHP y configuración, reversión por lote y prueba HTTP de accesos.
- No confluir CI PASS con aprobación de implementación: cada paso productivo necesita autorización explícita y evidencia del responsable.

## 5. Dictamen final

**GO**: consolidar el código, las puertas y la preparación de U-BASIC; continuar pruebas aisladas y preparar solicitud de liberación controlada.  
**NO-GO todavía**: fusionar/desplegar P5-R o encender reset de contraseñas en producción; falta demostrar revocación del token en la ruta Firebase real y recuperación UI, además de control externo de seguridad y DBA.

**Decisión operativa:** dos fases, primera U-BASIC cuando firmas y gates estén cerrados; después P5-R cuando revocación end-to-end sea **PASS**. El cambio de roles no se habilita.

**Referencias:** PR #14, #15, #19, #20; Issues #17, #18 y #21; pruebas P2, P3, P4a y T-EXP de este hilo.
