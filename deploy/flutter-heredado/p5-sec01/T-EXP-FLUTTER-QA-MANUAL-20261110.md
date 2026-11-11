# P5 / T-EXP — Prueba controlada de caducidad de sesión Flutter

**Fecha:** 10/11/2026  
**Estado inicial:** PENDIENTE DE EVIDENCIA DINÁMICA DEL USUARIO. No declarar PASS por resultados solo PHP/MySQL.  
**Rama:** `feat/cervesur-p5-sec01-strict-session` / PR #15 (DRAFT).  
**Alcance:** solo lectura sobre una cuenta QA de Flutter ya existente; no cambia BD, permisos, sesión, código, flags ni producción.

## 1. Base verificada y límites

- P5-SEC01: 20/20 QA local Windows con middleware exacto parchado y doble PDO; 64/64 matriz helper/adaptador/HTTP SQLite CI.
- P5-SEC02: 16/16 helper con MySQL 8 aislado CI; **24/24 middleware real parchado con PHP HTTP/MySQL 8 en Docker Desktop de Windows**. Datos solo sintéticos, red sin puertos publicados y recursos retirados (evidencia del operador).
- `docs/UX-09B-DECISION-JWT-Y-RIESGO-RESIDUAL.md` registra una **hipótesis**, no un hecho probado: Flutter podría mantenerse en sesión después del 401, mostrar listas vacías o diálogo «Observación» en vez de redirigir al login automáticamente.
- D3 deja pendiente comprobar el tipo de `user_id` emitido por el **login productivo real**. No solicitar ni copiar un JWT real por chat, tickets, logs, HAR, capturas o GitHub.
- **No hay fuentes Dart de Flutter** disponibles para un correctivo inmediato. El bundle compilado puede servir para inspección estática, pero no sustituye QA dinámico.

## 2. Condiciones de la prueba T-EXP real

1. Disponer de una **cuenta QA ya autorizada** (nunca desactivar usuarios reales), con sesión en un navegador desde hace más de **3 horas** cuando D4 confirme que el TTL efectivo sigue siendo **10800 s**; si cambia, usar el TTL atestado. No cerrar sesión antes de capturar el comportamiento. El caso no es ejecutable inmediatamente si no existe una sesión QA caducada.
2. Utilizar exclusivamente la URL de Flutter actualmente autorizada por el usuario / Infraestructura. No usar puertos internos o entornos de producción alternativos improvisados.
3. Tener permiso funcional para abrir una pantalla de solo lectura, p. ej. listado de empresas o reportes. No editar, guardar, eliminar o asignar datos.
4. En Chrome / Edge: F12 → **Network** → **Fetch/XHR**; activar **Preserve log** y limpiar el panel. No grabar HAR, no compartir pestaña Headers ni Request Payload, no pegar tokens/cookies.
5. Recargar la pantalla QA o visitar la lista de lectura. Seleccionar una solicitud protegida cuyo **status = 401**; registrar **solo método GET, nombre general de pantalla/ruta (sin identificadores), status y hora**.
6. Observar la interfaz: ¿redirige a Login? ¿pide reautenticarse? ¿muestra «Observación»/error técnico? ¿queda con listas vacías? Tomar captura recortada y ocultar datos personales de terceros, URLs con parámetros sensibles y tokens.
7. Cerrar sesión y volver a iniciar sesión con la cuenta QA; acceder a la misma pantalla de lectura. Confirmar que recupera datos (esperado 200). No registrar la contraseña ni la cabecera Authorization.
8. Si la respuesta es 403/404, error de red o no se observa petición protegida, **el resultado no evalúa 401**; anotar INCONCLUSO, no PASS ni FAIL.

## 3. Resultados y clasificación

| Caso | Evidencia mínima | Resultado |
| --- | --- | --- |
| TEXP-01: sesión QA caducada | Mayor que TTL efectivo atestado (actualmente 3 h), sin reautenticación | PASS / INCONCLUSO |
| TEXP-02: petición protegida | HTTP 401 documentado en petición **GET** | PASS / INCONCLUSO |
| TEXP-03: reacción UI | Login automático, aviso claro, «Observación», listas vacías u otra | OBSERVADO / INCONCLUSO |
| TEXP-04: recuperación | Tras login QA, pantalla GET vuelve a funcionar con HTTP 200 | PASS / FAIL / INCONCLUSO |
| TEXP-05: confidencialidad | Capturas sin token, cookies, contraseñas, correos o IDs personales | PASS / FAIL |

**Criterio deseado:** frente a 401, Flutter debe pedir reautenticación de manera comprensible y recuperarse tras login, sin errores inesperados. Si se queda en pantalla, no hay que convertir el 401 del servidor en 200: abrir correctivo del cliente Flutter sujeto a disponer de fuente Dart o solución autorizada. Si negocio acepta temporalmente la experiencia previa, documentar explícitamente el riesgo y la decisión. Ninguna de ambas decisiones equivale por sí sola a autorizar P5.

## 4. Precondiciones adicionales de compatibilidad

- **D3/JWT tipado:** `user_id` y `session_version` deben salir como números enteros en el token generado por el login legado. Confirmar mediante QA aislado con **fuente exacta** de `controllers/AuthController.php` y `lib/db.php` o informe de tipos **no sensible** emitido por Infraestructura (`user_id_int=PASS`, `session_version_int=PASS`), nunca copiando token. El repositorio React no prueba que esas fuentes sean idénticas al servidor legacy.
- **D4:** atestación sin exponer `JWT_SECRET`. Valor efectivo observado previamente `JWT_EXPIRATION=10800` (3 horas), diferente del valor propuesto `86400` (24 horas). No cambiar secretos ni expiraciones para forzar la prueba; documentar decisión de política antes de liberar.
- **C-5:** distribución de roles reales sin datos individuales. `admin` / `user` / `usuario` son los únicos aceptados por el helper P5.
- Mantener bloqueos K1/K2/F-10/P4b y planificación de rollback hasta cierre.

## 5. Resumen que debe enviarse al equipo (sin secretos)

```text
TEXP_FECHA=AAAA-MM-DD
TEXP_CUENTA=QA (sin nombre ni correo)
TEXP_SESION_SUPERA_TTL_ATESTADO=SI|NO|DESCONOCIDO
TEXP_GET_PROTEGIDO_HTTP=401|OTRO|NO_OBSERVADO
TEXP_UI=LOGIN|AVISO|OBSERVACION|VACIO|OTRO|NO_OBSERVADO
TEXP_RECUPERACION_POSTLOGIN=200|FALLO|NO_EJECUTADA
TEXP_DATOS_SENSIBLES_EXCLUIDOS=SI|NO
TEXP_VEREDICTO=PASS|FAIL|INCONCLUSO
```

**Nunca remitir logs de DevTools completos/HAR, encabezados Authorization o cookies de sesión.**

## 6. Puerta de decisión

Hasta obtener evidencia real del Flutter heredado y verificar tipo de JWT real + D4/C-5: **T-EXP PENDIENTE / P5 NO-GO PARA MERGE, DESPLIEGUE O ACTIVACIÓN**. P5-SEC02 aislado PASS no sustituye estas verificaciones.
