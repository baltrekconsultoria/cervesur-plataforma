# Cervesur · Gestión de Usuarios — Cierre funcional para demostración

**Corte de evidencia:** 10/11/2026. **Alcance:** portal administrativo React en puerto 8443, con contraste de candidatos U-BASIC (RC1c, Fase 2B) y U-SENSITIVE (P5). **Clasificación:** cierre de la **lectura funcional disponible**, no aceptación de las escrituras no liberadas.  
**Fuente de verdad:** `UX-09B-AUDITORIA-FASE2A-QA-FUNCIONAL-20261110.md` (8/8 PASS reportados por operador, sin capturas/HTTP), `UX-09B-QA-USUARIOS.md` (U01–U27), `UX-09B-TABLERO-EJECUCION.md`, PR #14 / PR #15, Issue #16.  
**Prohibiciones:** no se habilitan interruptores, no se cambia `.env`, no se escriben usuarios, no se modifican permisos SQL, no se fusiona ni despliega P5 como consecuencia de este cierre documental.

## 1. Alcance de la presentación de hoy

| Capacidad funcional | Portal 8443 actualmente desplegado | Candidato/QA | Dictamen para demostración |
| --- | --- | --- | --- |
| Login del administrador | Disponible, Q-01 reportado PASS | — | **DEMO funcional** |
| Listado de usuarios | Disponible, Q-02 reportado PASS | U04 | **DEMO funcional** |
| Búsqueda y filtros por rol/estado | Disponible, Q-03 reportado PASS | U04 | **DEMO funcional** |
| Detalle y accesos asignados | Disponible, Q-04 reportado PASS | U06–U07 | **DEMO funcional** |
| Gestión de Empresas | Consulta disponible, Q-06 reportado PASS | — | **DEMO solo lectura** |
| Gestión de Reportes | Consulta disponible, Q-07 reportado PASS | — | **DEMO solo lectura** |
| Gestión de accesos/asignaciones | Consulta disponible, Q-08 reportado PASS | — | **DEMO solo lectura** |
| Creación de usuario con rol normal fijo `user` y contraseña inicial | **NO habilitada en producción**; ausencia de controles Q-05 reportada PASS | RC1c probado en Docker aislado; requiere P3/P4a/P4b/P4c-1 | **Mostrar como entregable preparado; NO hacer alta productiva** |
| Edición de nombre, apellido y teléfono (username/correo/rol existentes no editables) | **NO habilitada en producción** | RC1c; P4c-2 pendiente | **No demostrar como productivo** |
| Desactivación / reactivación después de 24 h | **NO habilitada en producción** | RC1c, reloj simulado 15/15; P4c-3/4 pendiente | **No demostrar como productivo** |
| Cambio de rol `user` ↔ `admin` | **NO habilitado** | P5 UI PR #14 DRAFT; seguridad de JWT PR #15 DRAFT | **No demostrar como productivo** |
| Reasignación de contraseña existente | **NO habilitada** | P5 UI PR #14 DRAFT; motor sensible sujeto a permisos/atestaciones | **No demostrar como productivo** |
| Sesión caducada Flutter / respuesta 401 | **NO evaluada dinámicamente**; navegación actual sin errores | Issue #16 T-EXP BACKLOG | **Declarar deuda técnica, nunca PASS** |

**Nota sobre Q-05:** la ausencia de controles de escritura es un comportamiento esperado en Fase 2A; no equivale a verificación de autorización HTTP ni a una falla funcional.

## 2. Evidencia consolidada, sin mezclar alcances

- **Productivo 8443 lectura:** Q-01…Q-08 = **8/8 PASS reportado por el operador** el 10/11/2026. No hay captura ni traza HTTP anexa; **no se ejecutó ningún caso de escritura de usuarios**.
- **U-BASIC candidato RC1c:** ensayo Docker publicado en documentación **235 PASS / 0 FAIL**, con usuarios sintéticos. **No es verificación de P4c en producción**.
- **P5 UI PR #14:** TypeScript/ESLint + tests (incluidos P5 focalizados) y build validados en CI de rama aislada; PR sin merge/despliegue.
- **P5-SEC01/02 PR #15:** 64/64 helper/adaptador/HTTP SQLite CI; 20/20 middleware original parchado con doble PDO en Windows; 16/16 helper + MySQL 8 aislado CI; **24/24 middleware parchado + HTTP + MySQL 8 Docker Desktop**, según salida del operador. Estas matrices son **independientes**: no convierten P5 en entrega productiva.
- **Flutter heredado:** pantallas Empresas y Reportes visibles sin errores con sesión iniciada ese mismo día; respuestas 200/204 principalmente de Firebase. No se observó 401; por tanto, **T-EXP INCONCLUSO**, diferido al [Issue #16](https://github.com/baltrekconsultoria/cervesur-plataforma/issues/16).

## 3. Guion de demostración seguro (≈ 5–7 min)

1. **Entrada (30 s).** Mostrar `https://192.168.200.12:8443/login` y acceder solo con credenciales propias del administrador. Ocultar datos privados antes de presentar pantalla.
2. **Listado y filtros (2 min).** Abrir Usuarios; mostrar paginación, búsqueda por texto, filtros por perfil y por estado, sin exponer correos/identificadores privados al público.
3. **Ficha y accesos (1–2 min).** Abrir un usuario previamente autorizado para demostración; mostrar la ficha y la consulta de accesos, sin cambiar asignaciones.
4. **Integración de la gestión (1 min).** Mostrar las listas de Empresas, Reportes y Accesos sin crear ni editar registros.
5. **Alcance de próxima liberación (1 min).** Explicar que altas de rol normal, edición y baja lógica se encuentran **desarrolladas y probadas en laboratorio**; la apertura productiva depende de pasos de seguridad P2–P4 y autorizaciones.
6. **Cierre (30 s).** Explicar que cambio de rol y contraseña son una **segunda liberación sensible P5**. T-EXP (sesión caducada Flutter) está en backlog #16; eso no equivale a un GO de seguridad.

**No hacer:** click en Crear/Editar/Baja si llegasen a aparecer inesperadamente; llamadas POST/PUT/DELETE; cambio de flags, SQL, password o permisos; usar usuarios reales para demostrar las altas.

## 4. Checklist de cierre funcional de la versión disponible

| ID | Evidencia a exhibir hoy | Criterio | Estado inicial |
| --- | --- | --- | --- |
| CF-01 | Inicio sesión y menú Usuarios | Abre en 8443 con sesión autorizada | PASS reportado Q-01 |
| CF-02 | Listado de usuarios | Lista renderizada y paginada | PASS reportado Q-02 |
| CF-03 | Búsqueda y filtros | Cambian resultados sin errores | PASS reportado Q-03 |
| CF-04 | Detalle de usuario | Datos permitidos sin hash/contraseña | PASS reportado Q-04, sin evidencia HTTP |
| CF-05 | Accesos del usuario | Muestra solo asignaciones consultables | PASS reportado Q-04 |
| CF-06 | Usuarios en solo lectura | Alta/edición/baja no habilitadas | PASS reportado Q-05 (UI solamente) |
| CF-07 | Listados Empresas/Reportes/Accesos | Lecturas sin error | PASS reportado Q-06…Q-08 |
| CF-08 | No regresión de las pantallas mostradas | No se observan errores técnicos ni 503 en la navegación | Por confirmar con capturas de esta demostración |
| CF-09 | Evidencia de autorización HTTP/SQL para Usuarios OFF | Backend rechaza escrituras y SQL sin permisos | **No probado en esta ronda** |
| CF-10 | Alta, edición, baja y reactivación **en producción** | U14/U16/U19/U22 y auditoría atómica | **Pendiente P4a/b/c; NO-GO** |
| CF-11 | Cambio rol y contraseña productivo | P5 + validación completa de seguridad | **Pendiente, NO-GO** |

**Aceptación de este cierre:** la entrega actual puede declararse **operativa para CONSULTA de usuarios**; no se acepta la Administración de Usuarios completa hasta que CF-09/10/11 y todas las puertas productivas estén en PASS documentado.

## 5. Camino mínimo posterior a la demostración

1. **C-5** sistema y SQL + firmas A-C5/A; confirmar cuentas, roles, columnas y privilegios sin imprimir datos personales.
2. **D4** atestar sin exponer secreto: `JWT_SECRET` fuerte/no literal y `JWT_EXPIRATION=86400`; cerrar K2, K1, A2, A1 y A3/F-10 antes de abrir P4b.
3. **P2** respaldo con restauración demostrada; **P3** instalar RC1c con Usuarios apagado y prueba de regresión; **P4a** vista y privilegios mínimos revisados por DBA.
4. **P4b** solo con puertas completas y firma funcional; **P4c-1…4**: alta, edición, desactivación y reactivación (≥24 h), con autorización por operación y exactamente una auditoría.
5. **P5** separado: roles y contraseñas, requisitos de sesión, permisos sensibles, QA y autorización específica. El Issue #16 queda diferido por decisión del responsable para **la presentación**, no se considera dispensa automática de liberación segura.

## 6. Texto sugerido para exponer

> «La plataforma administrativa ya permite iniciar sesión, consultar usuarios, buscar y filtrar, revisar detalles y verificar sus accesos, además de navegar por los módulos de empresas, reportes y asignaciones. La creación, edición y baja lógica de usuarios están desarrolladas y han superado pruebas en un entorno aislado; su activación en producción está planificada con controles de seguridad, permisos y auditoría. Los cambios de rol y contraseña son una liberación sensible independiente. La prueba de sesión caducada del cliente Flutter queda documentada en el backlog y no afecta la demostración de lectura de hoy».

**Veredicto:** **CF-LECTURA = PASS (evidencia funcional reportada)**; **CF-ESCRITURA = NO-GO productivo**; **P5 = NO-GO productivo**; **T-EXP = BACKLOG, no PASS**.
