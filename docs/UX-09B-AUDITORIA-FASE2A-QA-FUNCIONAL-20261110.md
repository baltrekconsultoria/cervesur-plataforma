# Cervesur · Auditoría de Fase 2A y piloto de usuarios — evidencia 10/11/2026

**Estado:** consulta del administrador **QA FUNCIONAL 8/8 (autodeclarado por operador)**. No es evidencia de que Fase 2B esté desplegada ni autorización de escritura.
**Procedencia:** salidas compartidas por operador desde el servidor `presupuestos`, más checklist de navegación respondido por el usuario. Sin captura, HAR ni ejecución de QA autónoma por el redactor.

## 1. Infraestructura y versión observadas

Inspección del 10/11/2026 a las 06:55:11 UTC: servicios `apache2=active`, `mysql=active`; `mariadb=inactive`. Puertos 80, 443, 8092, 8093, 8094 y 8443 en escucha; 8092/8093/8094 limitados a loopback. Frontend y backend tanto del administrador como del piloto de usuarios existen.

Respuestas GET sin autenticación: Flutter/80 HTTP 200; piloto usuarios/8092 HTTP 200; administrador/8093 HTTP 200; administrador corporativo/8443 HTTP 200; 443 **no verificable con el destino loopback utilizado**, sin inferir una caída en la interfaz específica donde escucha.

Comparación de **cinco archivos** de backend instalado con objetos Git de las revisiones RC2 y RC1c: cuatro compartidos coinciden con ambas; `lib/admin_escritura.php` coincide únicamente con **RC2 Fase 2A**. Esta evidencia no constituye verificación integral del release.

`.env` del administrador fue clasificado en lectura sin revelar valores sensibles: `CERVESUR_MODE=admin_escritura` y `CERVESUR_REQUIRED_MODE=admin_escritura`; `ADMIN_WRITES_EMPRESAS`, `ADMIN_WRITES_REPORTES` y `ADMIN_WRITES_ACCESOS` **encendidos**; `ADMIN_WRITES_USUARIOS` y `ADMIN_WRITES_USUARIOS_SENSIBLE` **no definidos**; `AUDIT_LOG_MODE=required`; `REACT_STRICT_VIEWS=1`; sin claves duplicadas. Son valores **declarados**, no atestación independiente de los permisos SQL, el bloqueo HTTP ni la auditoría de una escritura real.

## 2. QA funcional del administrador 8443 (declaración del usuario)

| ID | Prueba en navegador | Estado reportado |
|---|---|---|
| Q-01 | Inicio de sesión y menú administrativo | PASS |
| Q-02 | Listado de usuarios | PASS |
| Q-03 | Búsqueda y filtros por estado/perfil | PASS |
| Q-04 | Detalle de usuario y consulta de accesos asignados | PASS |
| Q-05 | Sin controles de crear/editar/desactivar usuarios habilitados | PASS |
| Q-06 | Listado de Empresas | PASS |
| Q-07 | Listado de Reportes | PASS |
| Q-08 | Listado de Accesos/asignaciones | PASS |

**Resultado:** `FASE2A_QA_LECTURA_UI=8/8 PASS (REPORTADO)`. No se ingresaron evidencias de capturas ni trazas HTTP; no se ejecutaron escrituras. Esto demuestra únicamente el recorrido de lectura realizado por el usuario en la interfaz. **La ausencia de botones no demuestra por sí sola bloqueo del backend**.

## 3. Próxima fase de auditoría sin escritura

El portal en 8092 es un **visor para usuarios existentes** de Flutter, **no** la administración de usuarios. Requiere usuario activo, de rol normal, autorizado en lista blanca y acceso mediante túnel/certificado aprobado. La matriz manual `CIERRE-MVP-REACT-8092.md` contiene M1–M10.

Siguiente revisión no destructiva y con usuario piloto ya autorizado, si está disponible:
1. Confirmar entrada por túnel TLS a 8092 con credenciales **del propio titular**, nunca con cuenta administrativa ni compartiendo claves (M1).
2. Revisar exclusivamente "Mis informes": número de informes asignados y ausencia de informes ajenos, contrastando con asignación autorizada (M2), sin copiar títulos ni enlaces sensibles al expediente.
3. Comprobar que un informe no autorizado muestra denegación sin enlace si ese estado ya existe; **no cambiar la lista blanca ni autorizaciones de informes** para provocar escenarios (parte de M3).
4. Comprobar que el cierre de sesión impide volver a contenido con el botón Atrás (M9), evitando modificar cuentas, permisos o sesiones ajenas.
5. Verificar disponibilidad general del 8443 sin operaciones de escritura. La comprobación de seguridad HTTP de rutas no se considera cerrada sin prueba/atestación específica autorizada.

**No ejecutar en producción todavía:** M4 (autorizar informes), M8 (quitar usuarios), M10 (cinco contraseñas fallidas/desbloqueo), ni operaciones P4a/P4b/P4c. M6 (ID ajeno) solo con identificador sintético autorizado y procedimiento acordado; omitir por ahora para evitar exposición.

## 4. Dictamen y puertas

| Componente | Dictamen |
|---|---|
| Salud base y portales web | Parcialmente verificada por procesos/escuchas y GET |
| Administración 8443 lectura de usuarios/empresas/reportes/accesos | **PASS 8/8 reportado por usuario** |
| Escrituras Fase 2A (Empresas/Reportes/Accesos) | Configuradas ON; **no ejercitadas ni auditadas en esta comprobación** |
| Piloto 8092 — acceso y lista de informes de usuario normal | **Pendiente de QA manual** |
| Bloqueo efectivo de escrituras de Usuarios (HTTP + SQL) | No probado en esta ronda; RC2 e interruptores ausentes son evidencia favorable parcial |
| Fase 2B P3/P4a/P4b/P4c | **NO-GO** hasta puertas UX-09B, firmas y ventanas |
| K1/F-10 | **NO-GO**, evaluación independiente en PR #13 |

**Restricción:** no publicar, fusionar, reiniciar servicios, ejecutar SQL, habilitar interruptores, actualizar el piloto, modificar permisos ni ejecutar cambios productivos con base en este documento.
