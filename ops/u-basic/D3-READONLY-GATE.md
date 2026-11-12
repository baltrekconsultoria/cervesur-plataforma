# Cervesur D3 - QA focalizado previo a U-BASIC (solo lectura)

Estado: kit de prueba, **no significa D3 cerrado ni autorización P4b**. El código PHP heredado de producción puede diferir de `main`; no copiar el backend completo desde GitHub. Las evidencias previas siguen vigentes: P2 restauración aislada PASS, P4a no sensibles PASS, D4 JWT/K1/F10 PASS y 9 JWT fallbacks retirados; las 4 respuestas anónimas 401 corresponden a autenticación ausente, **no** a la política de roles.

## Qué verifica

1. `fuentes`: lee exactamente ocho archivos que el D3 histórico no tenía completos, muestra SHA-256 y resultado de `php -l`. No publica su contenido ni secretos. Tener los archivos y sintaxis correcta **no acredita** automáticamente los ocho casos D3.
2. `http`: hace **solo GET** al backend heredado local y prueba rechazo anónimo, rol `user` frente a listado/perfil ajeno y acceso `admin` a lecturas. Requiere una consola interactiva, dos JWT temporales de cuentas QA ya autorizadas y un ID *ajeno* al usuario normal. Los JWT se introducen con entrada oculta; no deben copiarse en el chat, archivos, comandos, historial ni logs. No se permite redirección ni host remoto; HTTPS verifica certificado con la CA suministrada (sin `-k`). No hace login, no captura credenciales ni modifica usuarios.
3. Para 8443, mantener el QA U01..U10 documentado en `docs/UX-09B-QA-USUARIOS.md`: el portal React usa sesión de admin independiente de JWT heredado. No trasladar sin adaptación tokens JWT al portal React.

## Operación en PuTTY (sin cambiar producción)

El operador descarga desde un **commit inmutable** de la rama PR y verifica integridad con `git hash-object`. Para usar la carpeta del kit existente:

```bash
cd /opt/cervesur-u-basic-kit
python3 -m py_compile d3_readonly_gate.py
sudo python3 d3_readonly_gate.py fuentes --backend /var/www/cervesur/backend
```

El paso HTTP debe ejecutarse **solo** si están disponibles cuentas QA, el responsable ha autorizado estos GET y se puede introducir cada JWT sin exposición. El identificador debe corresponder a un perfil existente y ajeno a NOR-T. En el backend legado HTTP:

```bash
python3 /opt/cervesur-u-basic-kit/d3_readonly_gate.py http --base-url http://127.0.0.1 --id-ajeno ID_QA_AJENO
```

**No introducir un token personal en chat.** El script hace 2 GET anónimos antes de pedir JWT. El operador debe ejecutar directamente por terminal TTY privada (no `sudo -S`, no tuberías de tokens) y solo comunicar códigos de resultado. Cualquier 200 para usuario normal en listado o perfil ajeno es **NO-GO**. Las rutas de otros módulos y las pruebas negativas de escritura requieren una matriz específica posterior; no se envían peticiones que puedan escribir.

## VirtualHost corporativo sin editar Apache (caso diagnostico 404)

En Apache con varios sitios en puerto 80, `http://127.0.0.1/api/user` puede ir al `000-default.conf` y devolver 404 aunque el backend legacy siga operativo. Primero verificar (solo GET) con el Host del VirtualHost corporativo:

```bash
for ruta in /api/ping /api/user /api/users; do
  curl -sS -m 8 -H "Host: IP_VHOST_CORP" -o /dev/null -w "$ruta HTTP_%{http_code}\\n" "http://127.0.0.1$ruta"
done
```

Esperado: 200, 401, 401. Sustituir `IP_VHOST_CORP` por la IPv4 privada mostrada por `apache2ctl -S`. **No registrar esa dirección en GitHub**. No cambiar `/etc/hosts`, puertos, certificado, Apache ni la base de datos.

El subcomando HTTP admite `--vhost IP_VHOST_CORP` con validación estricta de IPv4 privada; la conexión sigue a localhost y la cabecera `Host` selecciona el vhost. Esta opción se admite solo con URL local HTTP, no con HTTPS, sin redirecciones y sin cargar JWT en argumentos:

```bash
sudo python3 /opt/cervesur-u-basic-kit/d3_readonly_gate.py http \\
  --base-url http://127.0.0.1 \\
  --vhost IP_VHOST_CORP \\
  --id-ajeno ID_ADMIN_QA
```

El ID ajeno debe pertenecer a una cuenta controlada distinta de la NOR-T. Si los GET anónimos fallan, detener: la herramienta no solicita tokens ni efectúa operaciones adicionales. Antes de ejecutar con JWT comprobar que las cuentas QA están autorizadas para pruebas y que los tokens son de ese backend.

## Observación 12/11: login correcto en React usuarios (443), token opaco

El operador confirmó que un login funcional en **React usuarios (443)** devolvió sesión con rol `user` y un token hexadecimal opaco, **no un JWT** en formato `segmento.segmento.segmento`. No copiar ese token al comprobador D3: esta herramienta solo acepta el esquema JWT del backend **Flutter heredado (80)**. **Nunca adjuntar tokens, respuestas completas de login, correos o datos personales a GitHub.** Por haberse compartido el token, usar cierre y revocación de sesión según los controles disponibles; un simple cierre de interfaz no garantiza revocación de tokens emitidos.

La prueba del puerto 443 acredita que **una cuenta** consiguió iniciar sesión. No prueba por sí misma ni que la cuenta de servicio tenga ahora `SELECT` completo sobre `user` ni que todos los reportes estén disponibles. El diagnóstico SQL 443 de `SELECT` denegado continúa **pendiente de una verificación mínima independiente** antes de declarar resuelto; no inventar una reparación.

### Matriz de observación sin compartir secretos

| Entorno | Cuenta/control | Qué observar | Resultado que sí acredita |
| --- | --- | --- | --- |
| 443 React usuarios | Sesión normal de prueba | Iniciar sesión, abrir perfil y reportes asignados; desde Network anotar solo método, ruta **sin query**, y código HTTP | Login y navegación de **esa** cuenta |
| 8443 React admin | Sesión admin nominal de prueba | Abrir Usuarios, observar listado/detalle y capacidades; no crear ni editar | Lecturas administrativas, **no** U-BASIC |
| 80 Flutter legado | Sin sesión | GET `/api/user` y `/api/users` con Host corporativo responden 401 | Protección anónima únicamente |
| 80 Flutter legado | Cuenta normal/admin de prueba | Examinar en el navegador los **códigos HTTP** de las solicitudes ya hechas por la app; no extraer ni pegar JWT | Evidencia parcial de GET según rutas realmente observadas |

No ejecutar JavaScript arbitrario ni copiar un `fetch` desde DevTools. No reutilizar el token de 443 en 80/8443. Las pruebas de no elusión legado que requieran rol normal/admin seguirán pendientes si no hay una sesión del backend legacy autorizada. No elegir cuentas productivas únicamente por su ID.

### Modo seguro de comprobación anónima sin JWT

El kit admite `--solo-anonimos` para comprobar **exclusivamente** los dos GET anónimos del legado. No pide ni interpreta JWT, cookie ni `--id-ajeno`:

```bash
sudo python3 /opt/cervesur-u-basic-kit/d3_readonly_gate.py http \
  --base-url http://127.0.0.1 \
  --vhost IP_VHOST_CORP \
  --solo-anonimos
```

Esperado: `D3_HTTP_ANON=PASS_2_DE_2` y `D3_HTTP_ROLES=PENDIENTE_NO_EJECUTADO`. Es **repetición opcional** porque el operador ya obtuvo los dos 401 el 12/11; no constituye D3 global PASS. Debe instalarse un script verificado desde un commit inmutable actualizado de PR #32 antes de usar esta opción.

## Regla de decisión

- `D3_FUENTES_VALIDAS=8/8` significa **inventario+lint PASS**, no D3 funcional PASS.
- `D3_HTTP_GET=PASS_7_DE_7` es **solo** autorización de cinco GET autenticados/2 anónimos del backend heredado; no cierra A2/A3 ni el portal 8443.
- Mantener 8 inconclusos históricos hasta una evaluación controlada de código real y cobertura de cada vector; registrar evidencias de autorizaciones A-L1/A-L2/A-L3/A-L4, A-ALTA y P4b.
- No ejecutar `interruptor-usuarios.sh encender`, GRANT, migraciones o POST/PUT/DELETE como parte de este PR.
- `ADMIN_WRITES_USUARIOS_SENSIBLE` debe continuar OFF; P5/contraseñas/roles separado.
- Garantizar continuidad del alta: F10 del legado protege `/api/register`, mientras Flutter no envía JWT para esa ruta. Verificar procedimiento alternativo firmado y disponible antes de P4b.
- Riesgo SQL independiente: el acceso SQL del portal usuarios 443 tuvo un 1142/1143 histórico; el nuevo login exitoso no demuestra que ese permiso se reparó. Verificarlo de forma focalizada con DBA antes de declararlo resuelto, sin conceder GRANT en este incremento.

## Secuencia de cierre posterior

Validar reporte de fuentes (sin publicarlas), ejecutar GET de roles QA, revisar los 8 vectores D3 contra archivos reales **localmente en entorno controlado**, recoger autorizaciones pendientes y después emitir dictamen GO/NO-GO P4b. Solo con GO expreso proceder al interruptor oficial, que respalda `.env` y comprueba capacidades automáticamente. P4c introduce una sola alta de prueba posteriormente y con autorización propia.