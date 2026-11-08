# Cierre integral del MVP React 8092 (UX-07D.5)

Alcance: visor React para **usuarios existentes de Flutter**, con sus asignaciones de empresas e informes, en `https://127.0.0.1:8092` (túnel SSH). No es una plataforma administrativa. No se modificó Flutter, el puerto 80, `cervesur_portalbi`, contraseñas ni asignaciones, y nada se desplegó automáticamente.

## 1. Funcionalidades terminadas

| Área | Estado |
|---|---|
| Inicio y cierre de sesión con la cuenta de Flutter (`password_verify` sobre el hash existente) | Terminado. Cierre revoca la sesión en el servidor. |
| Lista blanca nominal, solo rol «usuario» activo; administradores e inactivos no entran | Terminado. |
| Mis informes (búsqueda, filtro por empresa y grupo, conteo, estado vacío, errores) | Terminado. |
| Mis empresas, perfil, navegación y migas de pan | Terminado. |
| Visor del informe (Power BI «Publicar en la web»): solo si la asignación es válida **y** el informe está autorizado para el piloto | Terminado. |
| Títulos largos (p. ej. `DASHBOARD_PRESUPUESTAL_CERVESUR_031`) en tarjetas, detalle, migas y enlaces relacionados, en escritorio, tablet y móvil | **Corregido en esta fase** (bloque A). |
| HSTS: ya no se emite para `localhost` desde el `.htaccess` estático (afectaba a los túneles de los otros portales) | **Corregido en esta fase.** |
| Lista de informes autorizados tolera espacios (`72, 122`) | **Corregido en esta fase.** |
| Actualización reversible de un piloto ya instalado (`scripts/actualizar.sh`) | **Nuevo en esta fase.** |

## 2. Bloque A · Correcciones visuales (evidencia)

* Reglas nuevas en `frontend/src/styles/app.css`: partir palabras largas (`overflow-wrap: anywhere`) en títulos, descripciones, migas, etiquetas y enlaces; `min-width: 0` en tarjetas y columnas; rejilla `minmax(min(260px, 100%), 1fr)`; pie de tarjeta que envuelve y mantiene el botón alineado.
* Prueba `frontend/e2e/run-layout.mjs` (`npm run test:e2e:layout`): navegador real, anchos 1366 / 820 / 360, títulos de 36 y de 100+ caracteres sin espacios. Comprueba que nada sale de su tarjeta, que no hay desbordamiento horizontal, que las tarjetas de una fila tienen la misma altura y el botón alineado, y que ninguna se superpone. Incluye un **control negativo** (con las reglas antiguas la prueba debe fallar) y compara con el ancho configurado, no con `innerWidth`, que en emulación móvil crece con el desbordamiento.
* Conservada la identidad visual (colores, tipografía, logotipos).

## 3. Bloque B · Acceso a informes

Cubierto por `tests/isolated/http_usuarios_test.php` sobre una base sintética con 76 usuarios y 82 informes activos:

* Cada usuario ve **exactamente** lo que da la cadena de relaciones activas (SQL independiente).
* Por URL directa, un informe no asignado devuelve 404 idéntico a un inexistente, sin enlace (más de 100 casos).
* Caso de aceptación «cuenta con **exactamente dos** informes» (el perfil del usuario piloto real): el catálogo trae esos dos; sin enlace autorizado ambos visores dan **403**; con el enlace autorizado ambos abren (**200**); los demás informes activos dan 404.
* Permisos por empresa: al agregar o quitar una empresa, el catálogo cambia en la siguiente petición con la misma sesión.
* No hay escrituras: las seis tablas de Flutter quedan idénticas bit a bit (`CHECKSUM TABLE`, `last_login` y `session_version` no se tocan).
* No se otorgan asignaciones nuevas: el procedimiento de autorización de enlaces (`usuarios.sh informes`) solo modifica `CERVESUR_PILOT_VISOR_IDS` en el `.env`.
* **Enlaces entregados correctos y disponibles:** el servidor valida el formato (host oficial de Power BI, esquema HTTPS, sin credenciales). **Que Microsoft siga sirviendo el informe publicado solo se comprueba a mano** abriendo cada informe con la cuenta de prueba (matriz §6, casos M4 y M5). No se probó contra producción.

## 4. Bloque C · Autenticación y sesiones

| Control | Resultado |
|---|---|
| Token Bearer | Opaco de 256 bits; en el servidor solo se guarda su SHA-256. En el navegador vive **solo en memoria** de la pestaña: no usa `localStorage`, `sessionStorage`, cookies ni archivos (verificado por las pruebas E2E: almacenamiento vacío). Recargar la página cierra la sesión (compromiso aceptado). |
| Revalidación | En **cada** petición contra el usuario real: activo, rol, `session_version`, huella de contraseña y lista blanca. |
| Caducidad | 1 h por defecto (300 s – 8 h, `CERVESUR_PILOT_SESSION_SECONDS`). Sesión caducada → 401. |
| Revocación | Cierre de sesión, desactivar al usuario, cambio de `session_version`, cambio de contraseña en Flutter, cambio de rol, quitar de la lista blanca, CLI (`revocar <id|todos>`). La sesión revocada no revive al revertir el cambio. |
| Rechazo | Inactivos, administradores y no listados: misma respuesta genérica, 400 ms de retraso y `password_verify` siempre ejecutado (no se enumeran cuentas). |
| Intentos repetidos | 5 fallos/15 min por cuenta (bloqueo 15 min, 429 con `Retry-After`) y un tope por dirección (20; 100 en el túnel porque todos llegan desde 127.0.0.1). Solo se guardan huellas HMAC. |
| Exposición en el navegador | CSP estricta (`script-src 'self'`, `connect-src 'self'`, `frame-src https://app.powerbi.com`, `frame-ancestors 'none'`), `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`. Un XSS podría leer el token en memoria mientras dure la pestaña: la CSP sin `unsafe-inline` y sin terceros es la defensa. |
| Endurecimiento HTTPS | Solo TLS 1.2/1.3; HTTP plano responde 400 y no llega a PHP; `X-Forwarded-*` eliminado por Apache; cookies (si alguna apareciera) con `Secure` y `HttpOnly`; **HSTS ya no se emite para `localhost`**. |
| Contraseñas | No se cambió ninguna. Las pruebas automatizadas usan contraseñas sintéticas generadas al vuelo. |

**Propuestas no implementadas** (requieren cambio de esquema en la base de estado, por lo tanto una migración aprobada): caducidad por inactividad (columna `ultimo_uso`), límite de sesiones simultáneas por usuario y rotación periódica del token.

## 5. Bloques D y E

* D: `docs/POWERBI-PUBLICAR-WEB-RIESGO.md`. **Hay un riesgo que impide ampliar el uso del piloto** (enlaces públicos; no es RLS).
* E: `docs/PROPUESTA-ACCESO-CORPORATIVO-8092.md`. Solo propuesta; no se ejecutó nada.

## 6. Bloque F · Matriz QA

Automática (A) se ejecuta en CI/ensayo; manual (M) la hace una persona sobre el piloto instalado. Estado «Pendiente» = no ejecutado todavía.

| ID | Caso | Tipo | Evidencia / procedimiento | Estado |
|---|---|---|---|---|
| A1 | Inicio de sesión con credenciales válidas | A | `http_usuarios_test` «usuarios activos… inician sesión»; E2E del ensayo Docker (navegador real, HTTPS) | PASS |
| A2 | Rechazo de inactivos, admin y no listados; sin enumerar cuentas | A | `http_usuarios_test` | PASS |
| A3 | Cuenta con dos informes: ambos se abren; el resto, 404 | A | `http_usuarios_test` (nuevo) | PASS |
| A4 | Sin enlace autorizado → 403 sin enlace | A | `http_usuarios_test` | PASS |
| A5 | Informe de otra cuenta por URL directa → 404 | A | `http_usuarios_test` (>100 casos) | PASS |
| A6 | Filtro por empresa y búsqueda | A | `vitest` (biblioteca) y `npm run test:e2e` | PASS |
| A7 | Navegación y diseño responsive con títulos largos | A | `npm run test:e2e:layout` (1366/820/360, con control negativo) | PASS |
| A8 | Cierre de sesión y caducidad | A | `http_usuarios_test` | PASS |
| A9 | Revocación de permisos y de sesión durante una sesión | A | `http_usuarios_test`, ensayo Docker «Revocación…» | PASS |
| A10 | Ausencia de escrituras a la base productiva | A | `CHECKSUM TABLE` antes/después + cuentas de lectura con 0 filas afectadas | PASS |
| A11 | HTTP plano y `X-Forwarded-Proto` falsificado rechazados | A | `http_usuarios_test`, ensayo Docker | PASS |
| A12 | Sin contraseñas, hashes ni tokens en respuestas, logs y base de estado | A | `http_usuarios_test`, ensayo Docker | PASS |
| A13 | Actualización con éxito, con fallo (vuelve sola) y reversión | A | ensayo Docker «Actualización de un piloto instalado» | PASS |
| M1 | Entrar por `https://localhost:18093` con `mortega` (credenciales reales, solo el titular) | M | Abrir el túnel, importar el certificado tras comprobar su huella | Pendiente |
| M2 | Mis informes muestra exactamente los dos informes (72 y 122), sin otros | M | Comparar con `usuarios.sh listar` | Pendiente |
| M3 | Sin autorizar enlaces, «Abrir» muestra el aviso de no autorizado (403 neutral) | M | Antes de `informes --autorizar` | Pendiente |
| M4 | Tras `informes --autorizar 72,122` ambos informes cargan en el visor | M | Navegador, ambos informes | Pendiente |
| M5 | Cada enlace publicado sigue disponible en Power BI | M | Abrir cada informe; anotar fecha y hora | Pendiente |
| M6 | Probar `/reportes/<id-ajeno>` pegado en la barra de direcciones | M | Debe mostrar «acceso denegado» sin datos | Pendiente |
| M7 | Escritorio, tablet y móvil reales con los títulos largos | M | Capturas | Pendiente |
| M8 | `usuarios.sh quitar <id>` con la sesión abierta → 401 en la siguiente acción | M | Con una cuenta de prueba | Pendiente |
| M9 | Cerrar sesión y volver atrás con el botón del navegador: no muestra datos | M | | Pendiente |
| M10 | Contraseña incorrecta ×5 → aviso de espera; `usuarios.sh desbloquear` | M | | Pendiente |

## 7. Registro de riesgos y pendientes

| # | Riesgo / pendiente | Gravedad | Estado |
|---|---|---|---|
| R1 | **Enlaces «Publicar en la web» son públicos** (no RLS); revocar permisos no invalida un enlace conocido | **Alta, bloqueante para ampliar** | Documentado; decisión del cliente |
| R2 | Falta decidir si el contenido de los informes presupuestales puede ser público por enlace | Alta | Pendiente de decisión por escrito |
| R3 | Acceso solo por túnel SSH; no apto para empleados | Media | Propuesta E; requiere aprobación |
| R4 | Bloqueo por cuenta permite bloquear a otra persona 15 min | Media | Aceptado; `usuarios.sh desbloquear` |
| R5 | Tope por IP en el túnel (100): no distingue personas | Baja | Revisar al salir del túnel |
| R6 | Token solo en memoria: recargar cierra sesión | Baja | Aceptado (seguridad > comodidad) |
| R7 | Sin caducidad por inactividad ni límite de sesiones | Media | Propuesta; requiere migración de esquema |
| R8 | Administración solo por línea de comandos | Media | Aceptado para el piloto |
| R9 | Base de estado sin copia programada | Media | Pendiente de operación |
| R10 | `mod_ssl` abre `0.0.0.0:443` si se habilita sin `Listen` acotado | Media | Documentado en `HTTPS-PILOTO-USUARIOS.md` |
| R11 | No se verificó la estructura real (PK/UNIQUE) de las tablas productivas | Media | Verificar con consultas de solo lectura |
| R12 | Cambio de contraseña en Flutter se detecta por huella, no por evento | Baja | Aceptado |
| R13 | Contraseñas: se usa la de Flutter; no hay recuperación en el portal | Media | Proceso de soporte |

## 8. Condiciones que todavía impiden abrir el MVP a todos los empleados

1. **R1/R2:** decisión escrita sobre los enlaces públicos de Power BI (o paso a RLS / incrustación con identidad).
2. **R3:** acceso desde la red corporativa con nombre y certificado válidos, aprobado por el administrador de red (propuesta E).
3. **R7/R8:** administración de usuarios sin línea de comandos y caducidad por inactividad, si se supera un grupo reducido.
4. **R9:** copia de seguridad y limpieza programadas de la base de estado.
5. **R11:** verificación de la estructura real de las tablas.
6. Pruebas manuales M1–M10 ejecutadas y firmadas.
7. Operación: quién da soporte, quién autoriza altas, revisión diaria de la auditoría.

## 9. Despliegue y reversión de esta versión

Ver `INSTALACION-PILOTO-USUARIOS-8092.md` §6 (**Actualización de un piloto ya instalado**). Resumen:

```
# Servidor, con el paquete extraído y verificado (sha256sum -c SHA256SUMS --quiet)
sudo bash scripts/actualizar.sh --simular     # no escribe nada
sudo bash scripts/actualizar.sh               # escribe ACTUALIZAR-8092; respalda, reemplaza, verifica y, si falla, vuelve solo
sudo bash scripts/actualizar.sh --revertir /var/backups/cervesur-react-usuarios/actualizacion-<sello>
```

No reinicia Apache, no toca el `.env`, la base de estado, las cuentas MySQL, el certificado ni la configuración de Apache, ni Flutter ni los otros portales (compara su huella antes y después).
