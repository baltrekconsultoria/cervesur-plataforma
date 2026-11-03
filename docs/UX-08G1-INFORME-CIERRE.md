# UX-08G.1 · Cierre del release candidate de solo lectura (fase 1 · actualización in situ de 8443)

Fecha: 2026-11-04 · Rama `feat/cervesur-ux-08g-release-candidate` · Sin SSH, push, merge, deploy, migraciones ni cambios productivos. Fase 2 (escrituras) fuera de alcance; `admin_escritura` no se implementó; DB031 (72) no se habilitó.

## 1. Paquete vigente (sustituye al de UX-08G)
El anterior (`…36c5135…`) **queda descartado**: su `actualizar.sh` tenía el defecto de §5. Vigente, construido desde el commit `b5d6a39ab4c3` (árbol limpio), en `Desktop/Proyectos Baltrek/Cervesur/paquetes-qa/produccion-v2/`:

| Archivo | SHA-256 del `.tar.gz` |
|---|---|
| `cervesur-react-admin-b5d6a39ab4c3-20261103-173523Z.tar.gz` | `461e576223afb18d2e59675b29e58e805e18495f17892f6af9d42c501547c24c` |
| `cervesur-auditoria-solo-lectura-b5d6a39ab4c3-20261103-173525Z.tar.gz` | `5b48f1584838c04e8744b7e1d1654b23da9ab9ad1b68ce02d34bab540395e7ed` |

`SHA256SUMS` interno verificado; hosts `localhost` y `192.168.200.12`; sin `.env`, claves, `syn###`, migraciones ni datos de QA.

## 2. A · Conciliación de procedencia (resultado)
| Comprobación | Resultado |
|---|---|
| Backend del paquete original `cef58da900e8` (53 archivos PHP) vs fuente en `wt-ux07d3a` | **53/53 idénticos** (LF normalizado) → el paquete es fiel a la fuente |
| Aplicación compilada del paquete vs `frontend/dist-pilot-admin` de `wt-ux07d3a` | **idéntica** (misma huella de árbol) |
| `scripts/`, `apache/`, `admin-mysql/`, `docs/` del paquete original vs paquete RC | **sin diferencias** salvo `actualizar.sh` (UX-08G.1) |
| Procedencia SHA-256 (167 archivos importados) vs fuente actual | 148 byte a byte iguales; 15 fusionados a tres vías (marcados `*`); 4 modificados a propósito después de importar: `admin_catalogo.php` (visor solo de informes activos), `http_admin_piloto_test.php` (rutas nuevas), `ensayo-docker.mjs` (ensayo 8443), `frontend/.gitignore` (`dist-pilot-admin/`) |
| Backend RC vs paquete original | 24 archivos modificados (todos UX-08, 1 el endurecimiento del visor), 7 nuevos (capa de escritura inalcanzable), **0 retirados** |
**Pendiente (requiere el servidor):** que lo *desplegado* sea el paquete `cef58da900e8`. Se decide con `inventario-divergencia.sh … deploy/auditoria/manifiesto-piloto-cef58da900e8.txt` (E1 · evidencia 12). Si aparecen archivos «solo en el servidor» o «distintos», el plan in situ **se detiene** hasta clasificarlos (cambio local legítimo que hay que conservar, o deriva).

## 3. B · QA del árbol fusionado (todo ejecutado sobre ESTA versión)
| Prueba | Resultado |
|---|---|
| Vitest (incluye `pilotAdmin` y la nueva `adminReadOnlyUi`) | **277 PASS** |
| `tsc`, `eslint`, `build:pilot-admin`, `verify:pilot-admin`, `scan-secrets` | limpio |
| E2E de navegador (pantallas de UX-08 con proveedor simulado: inicio de sesión, listados, filtros activos por defecto, paginación, historial, permisos) | **89 PASS / 0 FAIL** |
| E2E de maquetación / selector móvil | **41/41 PASS** |
| E2E del navegador real contra el backend `piloto_admin` por HTTPS en 443/8443 (ensayo del kit: login, listados, visor, 403, cierre de sesión, sin escrituras en la red) | PASS (dentro de los 352) |
| HTTP (réplica de la CI sobre el árbol fusionado: autenticación y autorización, listados y paginación, visor y 403, 14 rutas de escritura, cuentas MySQL) | **1501 PASS / 0 FAIL** (UX-08G) |
| «Botones de escritura»: en la compilación de solo lectura **ninguna** de las 4 pantallas (Usuarios, Empresas, Reportes, Accesos) muestra Nuevo/Editar/Desactivar/Reactivar/Asignar/Historial ni columna «Acciones» de escritura; control positivo con escrituras habilitadas sí los muestra | PASS (`adminReadOnlyUi.test.tsx`) |
La QA del entorno 8094 **no** se usó como sustituto. No se ejecutó la CI de GitHub (nada subido).

### Las 14 rutas protegidas
`GET /api/auditoria`, `GET /api/auditoria?entity=user`, `GET /api/users/capabilities`, `GET /api/users/1`, `PUT /api/users/1/password`, `POST /api/users/1/deactivate`, `POST /api/users/1/reactivate`, `POST /api/{empresas,reportes,asignacion,empresa-usuario,empresa-reporte-usuario}/1/reactivate`, `PUT /api/empresa-reporte-usuario/1`, `GET /api/empresas/1/form` → **403 con sesión de administrador válida y `USER_ADMIN_WRITES=1` + `AUDIT_LOG_MODE=required` en el entorno de la instancia** (los formularios `?view=form` responden 400, ya cubierto por el piloto). Más las rutas de escritura y variantes que el piloto ya cubría (matriz de la suite `http_admin_piloto_test`). Estáticamente, `AdminRouter` no usa la capa de escritura y la puerta de rutas solo admite GET de lectura + login/logout.

## 4. D · Ensayo final de actualización sobre una réplica del 8443 publicado
Réplica Docker (Ubuntu 24.04 + Apache + mod_php + MySQL, Flutter/8088/8089/8090/8091/8092 simulados, trabajadores en 443 y **administrador publicado en `https://IP:8443`** con certificado de CA privada y red restringida, directorios compartidos con 8093). **Estado de partida: el paquete original `cef58da900e8`** (no una compilación nueva), actualizado con el RC. Resultado: **352 PASS / 0 FAIL**, con estas pruebas específicas de 8443:
- `actualizar.sh --simular`: código 0, no escribe nada.
- Paquete con **error de sintaxis PHP**: rechazado antes de tocar nada.
- Paquete con **backend roto en ejecución**: la verificación lo detecta, la actualización falla y **vuelve sola** a la versión anterior, bit a bit, mismo `.env`, 8443 y el inicio de sesión intactos.
- Actualización real: respaldo con `SHA256SUMS` válido, **sin ningún `.env`**, permisos 700/600; QA en 8443 (versión nueva servida, el administrador entra, **un trabajador no entra al administrador**); trabajadores, Flutter, Apache (sin reiniciar) y demás portales idénticos.
- `--revertir`: bit a bit, marca nueva 404, `.env` intacto; respaldo corrupto rechazado sin tocar nada.
- Las demás secciones del ensayo (instalación, publicación por IP, renovación de certificados, reversión total) no cambiaron.
**Límites honestos:** es una réplica, no el servidor; que 8093 y 8443 compartan directorios, el `.env`, los hosts y los certificados reales son supuestos del diseño del piloto que la simulación real (E2) debe confirmar. En una pasada anterior, el verificador del *estado de partida* falló una vez (`8093 escucha SOLO en 127.0.0.1` y la lista blanca) y no se repitió en las dos siguientes: se trata como intermitencia del arnés y se vigila.

## 5. Hallazgo importante del ensayo (corregido)
Un backend roto **pasaba** la verificación posterior, e incluso la de su propia reversión, porque `mod_php` usa **opcache** y sigue sirviendo el código antiguo en caché durante ~2 s. En producción habría dado «ACTUALIZACIÓN COMPLETA» sobre una versión rota. Corrección en `actualizar.sh`: (a) `php -l` de todo el backend del paquete antes de tocar nada; (b) espera de 4 s (`CERVESUR_ESPERA_OPCACHE`) antes de **cada** verificación, incluida la de la restauración. La corrección está en el `actualizar.sh` del kit (compartido con el piloto de trabajadores); no se ha desplegado en ninguna otra instancia.

## 6. C · Etapa E1 y evidencias
Comandos exactos: `docs/UX-08G-GUIA-DESPLIEGUE-PRODUCCION.md` (E0 construir y transferir desde Windows, E1 auditar, E2 simular). **Estado de las evidencias:**
| Evidencia | Estado |
|---|---|
| 10 preflight · 11 interruptor · 12 divergencia vs piloto · 13 informes autorizados | **PENDIENTES**: requieren ejecutar en el servidor (yo no puedo, sin SSH) |
| 01–08 de UX-08F.2 (incl. 03a/03b/03c/06b) | **NO disponibles para mí** (ni en el equipo ni en la conversación). Para no repetir inspecciones, adjúntelas; sin ellas el cruce con §2 lo hace el 12 |
| 14 simulación de la actualización | pendiente de E1 en GO |

## 7. Criterios GO / NO-GO definitivos para la fase 1 en 8443
**GO para pedir autorización de activar** solo si TODO: (1) 12: lo desplegado = `cef58da900e8` o cada diferencia clasificada y resuelta; (2) 10 `RESULTADO: GO`; (3) 11 PASS en ambos portales; (4) 13 revisado y sin `TODOS` ni sorpresas; (5) 14 con código 0; (6) copias previas hechas (`.env`, `apache2`, estado de puertos); (7) paquete con el hash de §1 y sin `-con-cambios`; (8) autorización expresa, ventana acordada y responsable; (9) quien responde por los datos confirma que **esta liberación no autoriza ningún informe nuevo**. **NO-GO** ante cualquier FAIL, hash distinto, `USER_ADMIN_WRITES=1`, cuenta con escritura, `configtest` fallido, o diferencia sin clasificar en 12.
Criterios de volver atrás tras activar: `verificar.sh` con fallos, 5xx sostenidos, el administrador no entra, Flutter/trabajadores afectados, un informe no autorizado entrega enlace.

## 8. Autorizaciones y decisiones pendientes
1. Ejecutar E1 (solo lectura) y entregar 10–13 (y 01–08).
2. Clasificación de confidencialidad de DB031 (72) por el responsable de datos (hasta entonces **no** se autoriza).
3. Autorización expresa y separada de E3 (activación) y de su ventana.
4. Decidir si se hace commit/push de la rama y se ejecuta la CI de GitHub (hoy el código del piloto sigue sin estar en ningún remoto).
5. Fase 2 (modo `admin_escritura`, cuenta con `SELECT` por columnas, migraciones, arnés Flutter): proyecto independiente, sin fecha.
