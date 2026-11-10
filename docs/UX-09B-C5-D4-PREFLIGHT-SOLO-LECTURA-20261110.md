# Cervesur UX-09B — C-5 + D4: preflight y ejecución aprobada de solo lectura

**Corte:** 10/11/2026. **Estado:** preparación revisada sobre GitHub, **NO EJECUTADA** contra el servidor ni la base productiva.  
**Objetivo:** obtener evidencia de sistema, permisos MySQL y seguridad JWT para evaluar la habilitación U-BASIC de Gestión de Usuarios, **sin activar Usuarios**.  
**Autorizaciones previas requeridas:** A-C5 firmada (operador + DBA), A-C5b firmada (Infraestructura, D4). Las plantillas presentes en GitHub **no son constancia de firma**. Sin ellas, detenerse. Este procedimiento no autoriza P2/P3/P4a/P4b/P4c/P5, cambios de configuración, GRANT ni despliegue.

## 1. Hallazgos de la revisión en GitHub

| Bloque | Fuente versionada | Preparado | Ejecución productiva |
| --- | --- | --- | --- |
| C-5 sistema | `deploy/auditoria/c5-solo-lectura.sh` | Auditoría y pruebas sintéticas registradas | **PENDIENTE A-C5 y operador** |
| C-5 SQL | `deploy/auditoria/c5-dba-solo-lectura.sql.template` | Restricción de transacción READ ONLY, SELECT/SHOW; DBA debe completar cuentas/hosts | **PENDIENTE A-C5 y DBA** |
| Revisión de salida | `deploy/auditoria/revisar-salida-c5.sh` | Advierte por posibles valores sensibles SIN imprimirlos | Pendiente de salidas |
| D4 | `deploy/flutter-heredado/d4/d4-atestacion-solo-lectura.sh` | Validación en memoria; no imprime el secreto | **PENDIENTE A-C5b y cuenta Infraestructura** |
| D4 manifest | `deploy/flutter-heredado/d4/SHA256SUMS` | Contiene SHA-256 esperados de D4 y entrega enmascarada | Comprobar localmente antes de ejecutar |
| D4-08b | GET sin Authorization a `/api/powerbi/tokens/0` | Caso previsto (id=0 no genera token), pero NO se debe usar por anticipado | **PENDIENTE A-C5b** |

**Precisión:** el estado de estas filas corresponde a documentación y código bajo control de versiones; **no acredita todavía configuración efectiva, firmas recibidas ni salud del sistema actual**.

## 2. Orden operativo

1. **Funcionario responsable:** formalizar A-C5 y A-C5b, indicar fecha, alcance, ejecutores nominales, DBA y canal privado de resultados. El permiso informal «continuemos» autoriza preparación en GitHub, **no sustituye esas constancias**.
2. **Antes de consultar producción:** obtener de la rama actual los scripts y plantillas; cotejar el SHA-256 del C-5 del documento `docs/UX-09A-C5-REVISION-Y-EJECUCION.md` y ejecutar `sha256sum -c SHA256SUMS` sobre D4 desde una copia confiable. No aceptar un archivo con hash distinto.
3. **C-5 sistema:** el operador autorizado ejecuta `c5-solo-lectura.sh` en modo de entrada estándar por SSH/terminal, indicando únicamente archivos de **access.log** realmente existentes y legibles bajo `/var/log/apache2`. Evitar cargar scripts en `/var/www/cervesur` o editar permisos. Salida: `salida-c5-sistema-AAAAMMDD.txt`, guardada **solo en la estación autorizada**. La guía de ejecución exacta está en `docs/UX-09A-C5-REVISION-Y-EJECUCION.md`.
4. **C-5 SQL:** DBA revisa, completa/descomenta los `SHOW GRANTS FOR` correctos y ejecuta la plantilla en sesión `READ ONLY`. No emitir DML/DDL. Salida `salida-c5-dba-AAAAMMDD.txt` y revisión del DBA. No delegar cuentas DBA ni contraseñas.
5. **D4:** Infraestructura, con firma A-C5b, ejecuta su script en la raíz `/var/www/cervesur/backend` usando **`--expiracion-prevista 86400`**, bajo cuenta nominal que pueda leer los archivos necesarios. No imprimir `.env`, `JWT_SECRET` ni valores JWT. La salida puede contener **PENDIENTE** (por ejemplo si el secreto se define fuera del .env), que NO cuenta como PASS.
6. **D4-08b:** solo con A-C5b, sondeo `GET /api/powerbi/tokens/0` sin Authorization, registrar **únicamente código**. Se espera 410 solo cuando K1 esté en modo retirar; 400/401/404 o cualquier otro código **no cierra** K1.
7. **Revisión:** pasar C-5 por `revisar-salida-c5.sh`, examinar a ojo y retirar cualquier valor sensible antes de compartir. No subir `.env`, JWT, HAR, contraseñas ni dumps. Entregar C-5 sistema + SQL + D4 + código D4-08b **por canal autorizado**.
8. **Interpretación y firmas:** contrastar roles reales (admin/user/usuario/otros), columnas y permisos de cuenta mínima, consumo de API/registro público, K1, K2, JWT y expiración. Registrar PASS, FAIL o PENDIENTE por bloque; replanificar dependencias antes de P2/P3/P4a.

## 3. Checks de integridad esperados

- C-5 sistema: SHA-256 `9a2600d3bf7242e68304355f4ff4d7c003a934c091eaa05551412256b15222a5`.
- C-5 SQL: SHA-256 `7e28a281be9fec257a37de2ccc368dcec91b682aaf20c85a66f4ebea4fc81836`.
- D4: el manifiesto en `deploy/flutter-heredado/d4/SHA256SUMS` exige `31e63a1b74db10838f23fbc38a6099f86c0a137058e3a75bcffca5317e591842` para `d4-atestacion-solo-lectura.sh` y `99740e64ab2ed6228d9ddf2927f99a33a3d49f0111f81b345e3290cf7d664619` para `entrega-fuentes-enmascaradas.sh`.
- Si alguno difiere, **PARAR**, no forzar ejecución. Estas son huellas **documentales esperadas**; el cotejo físico es trabajo del ejecutor.

## 4. Criterios de evaluación

| Frente | PASS significa | NO es PASS |
| --- | --- | --- |
| C-5 sistema | huellas, versiones, espacio y agregados disponibles; sin filas sensibles expuestas | que exista el script o su suite sintética |
| C-5 SQL | versión, roles, esquema, GRANT, triggers y conteos revisados por DBA | plantilla preparada sin ejecutar |
| D4 JWT | lectura efectiva de configuración segura y expiración 86400, sin valores débiles ni literales iguales | default 86400 cuando no se atestó valor efectivo |
| K1 | retirada del token confirmada con modo y prueba 410, después de estudiar consumidores legítimos | script registra modo preparado o 400/401 |
| F-10 | registro público protegido y procedimiento alternativo aprobado y ensayado | existencia del parche local sin despliegue |
| Activación U-BASIC | P2 verificado, P3 (Usuarios OFF), P4a, puertas K2/K1/A2/A1/A3, C-5/D4/firmas y P4b/P4c aprobados | C-5 o D4 solos |

**Atención a posibles resultados D4:** antes de desplegar K1 (Lote 2) y F-10, `D4-08` / `D4-09` pueden dar FAIL por controles no instalados. Ese resultado sería evidencia de pendientes anteriores, **no motivo para alterar el servidor sin autorización**.

## 5. Requisitos para proceder

- [ ] A-C5 firmada e identificados operador y DBA.
- [ ] A-C5b firmada e identificada cuenta Infraestructura.
- [ ] Script y SQL C-5 cotejados contra SHA-256.
- [ ] D4 cotejado contra SHA-256.
- [ ] Ruta de logs validada por operador.
- [ ] Canal privado de custodia de resultados definido.

**Dictamen hoy:** **GO para documentación / revisión de fuentes**, **NO-GO para ejecutar C-5 o D4 contra producción hasta firmas**, y **NO-GO para escribir, habilitar Usuarios o desplegar P5**.

## Referencias

- `docs/UX-09A-C5-REVISION-Y-EJECUCION.md`
- `docs/UX-09A-MATRIZ-AUTORIZACIONES-Y-VEREDICTOS.md`
- `deploy/auditoria/HOJA-DE-REVISION-A-C5-Y-A.md`
- `deploy/flutter-heredado/d4/SOLICITUD-INFRAESTRUCTURA.md`
- `docs/UX-09B-CIERRE-FUNCIONAL-GESTION-USUARIOS-20261110.md`
