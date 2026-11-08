# Guía de QA manual M1–M10 · piloto React 8092 (UX-07D.6)

Para el operador, desde **Brave en Windows**, con el piloto ya actualizado. Ningún caso se da por **PASS** sin que una persona lo haya visto; anote resultado, fecha, hora y captura.

## 0. Preparación

1. Túnel: `ssh -N -L 18093:127.0.0.1:8092 usr_cervesur@192.168.200.12` (déjelo abierto).
2. Certificado: el de prueba ya debe estar importado como de confianza **tras comprobar su huella** (`sudo bash scripts/tls.sh huella` en el servidor). Abra `https://localhost:18093`. Debe mostrar el candado sin advertencias.
3. Brave: si algún portal HTTP por túnel (8088–8091) se abre ahora forzado a HTTPS, es HSTS heredado de `localhost`: `brave://net-internals/#hsts` → «Delete domain security policies» → `localhost`. Las versiones nuevas ya no lo emiten.
4. Contraseña de `mortega`: escríbala solo el titular de la cuenta; no la comparta, no la guarde en capturas. (La contraseña temporal usada en el QA debe regularizarse después: ver riesgos.)
5. Ventana normal de Brave (no Tor ni «Shields» agresivo que bloquee el iframe de `app.powerbi.com`).

## 1. Matriz

| ID | Qué probar | Pasos | Resultado esperado | Resultado real / PASS-FAIL |
|---|---|---|---|---|
| **M1** | Ingreso con `mortega` (ID 19) | Abrir `https://localhost:18093`, usuario `mortega` y su contraseña. | Entra a «Mis reportes». Candado válido. Sin mensajes de error. | |
| **M2** | Sus dos informes de CREDITEX | Ver «Mis reportes» y «Mis empresas». Comparar con `sudo bash scripts/usuarios.sh listar` y la asignación real. | Exactamente dos informes (`DASHBOARD_PRESUPUESTAL_CERVESUR_031`, `DASHBOARD_RESPONSABLE_CERVESUR_031`), empresa CREDITEX, ningún otro. | |
| **M3** | Sin enlace autorizado | Solo si **no** están autorizados (`usuarios.sh informes --listar`). Pulsar «Abrir». | Pantalla neutral de acceso denegado, sin enlace ni datos. (Si ya están autorizados, anótelo como «no aplica» y no los retire sin acordarlo.) | |
| **M4** | Apertura del informe interno 72 y 122 | Pulsar «Abrir» en cada uno. | El visor carga el informe Power BI embebido; el título entero es visible y no invade nada. | |
| **M5** | Contenido Power BI | En cada informe: esperar la carga, comprobar páginas, visuales y datos plausibles; anotar fecha/hora. | Visuales con datos, sin error de Power BI, sin pantallas en blanco. Si pide iniciar sesión en Microsoft o muestra «no disponible», FAIL y avisar. | |
| **M6** | Informe no asignado por URL | Con sesión iniciada, pegar `https://localhost:18093/reportes/<otro-id>` (p. ej. 1) en la barra. | «Acceso denegado» sin título, descripción ni enlace. Idéntico a un id inexistente (pruebe `/reportes/999999`). | |
| **M7** | Filtros, búsqueda y títulos largos | Buscar «PRESUPUESTAL», luego «RESPONSABLE», luego algo inexistente; filtrar por empresa; limpiar filtros. Revisar tarjetas y detalle. | Los resultados coinciden; contador «mostrando N de M»; estado vacío claro; **ningún título se sale de su tarjeta**; botones alineados. | |
| **M8** | Responsive | `F12` → modo dispositivo: 1366, 820 y 360 px (y un móvil real si es posible). Revisar biblioteca, detalle y menú. | Sin barra de desplazamiento horizontal; tarjetas apiladas en móvil; menú lateral utilizable. | |
| **M9** | Cerrar sesión y retroceder | «Cerrar sesión»; luego botón «Atrás» del navegador y pegar `…/reportes`. | Vuelve al login; ningún dato visible; pegar la ruta redirige al login. | |
| **M10** | Revocación y bloqueo (cuenta de prueba, **no** `mortega`) | a) Con una cuenta de prueba en la lista, entrar; en el servidor `usuarios.sh quitar <id>`; hacer cualquier acción. b) Equivocarse 5 veces de contraseña en una cuenta de prueba. | a) Sesión inválida en la siguiente acción (vuelve al login). b) Aviso de espera; `usuarios.sh desbloquear <login>` lo libera. Reponer la lista blanca si corresponde. | |

## 2. Si algo falla

* Anote el caso, la hora exacta y una captura **sin** contraseñas ni enlaces visibles. No intente repararlo en el servidor.
* Para revisar: `sudo bash scripts/usuarios.sh auditoria` (no muestra contraseñas, hashes ni enlaces).
* Para volver a la versión anterior: `sudo bash scripts/actualizar.sh --revertir /var/backups/cervesur-react-usuarios/actualizacion-<sello>`.

## 3. Cierre

El MVP **no** se declara cerrado hasta que el responsable funcional firme la conformidad con M1–M10. Esta guía no autoriza la apertura general.
