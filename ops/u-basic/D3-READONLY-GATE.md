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

## Regla de decisión

- `D3_FUENTES_VALIDAS=8/8` significa **inventario+lint PASS**, no D3 funcional PASS.
- `D3_HTTP_GET=PASS_7_DE_7` es **solo** autorización de cinco GET autenticados/2 anónimos del backend heredado; no cierra A2/A3 ni el portal 8443.
- Mantener 8 inconclusos históricos hasta una evaluación controlada de código real y cobertura de cada vector; registrar evidencias de autorizaciones A-L1/A-L2/A-L3/A-L4, A-ALTA y P4b.
- No ejecutar `interruptor-usuarios.sh encender`, GRANT, migraciones o POST/PUT/DELETE como parte de este PR.
- `ADMIN_WRITES_USUARIOS_SENSIBLE` debe continuar OFF; P5/contraseñas/roles separado.
- Garantizar continuidad del alta: F10 del legado protege `/api/register`, mientras Flutter no envía JWT para esa ruta. Verificar procedimiento alternativo firmado y disponible antes de P4b.
- Riesgo SQL independiente: el servicio del portal de usuarios 443 carece del SELECT necesario en `user`; no concederlo sin procedimiento DBA aprobado.

## Secuencia de cierre posterior

Validar reporte de fuentes (sin publicarlas), ejecutar GET de roles QA, revisar los 8 vectores D3 contra archivos reales **localmente en entorno controlado**, recoger autorizaciones pendientes y después emitir dictamen GO/NO-GO P4b. Solo con GO expreso proceder al interruptor oficial, que respalda `.env` y comprueba capacidades automáticamente. P4c introduce una sola alta de prueba posteriormente y con autorización propia.