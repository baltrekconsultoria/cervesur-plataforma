# GUA-01 — Activación controlada de Gestión Administrativa de Usuarios (8443)

**Fecha:** 2026-11-12. **Estado:** preparación de activación; NO equivale a despliegue ni autorización para sortear puertas de seguridad.

## Objetivo y exclusiones

Prioridad solicitada por el operador: habilitar en 8443 la gestión básica de cuentas reales (alta de `user`, editar nombres/apellidos/teléfono y desactivar/reactivar con espera de 24 h). No repetir la importación de los 114, no editar `role`, `password`, correo o username existentes. Sin cambiar Flutter, empresas, reportes ni asignaciones.

## Código recuperado en GitHub

- Rama **`feat/cervesur-ux-09a-cierre-usuarios`**: `frontend/src/features/admin/AdminPages.tsx` ya muestra formularios y botones de editar/desactivar/reactivar cuando `capabilities.users` lo permite; `frontend/src/features/admin/UserDialogs.tsx` contiene edición versionada con conflicto 409 y correo de solo lectura.
- Backend `backend/lib/admin_escritura.php`: puerta independiente de rutas de Usuarios, guardia SQL, chequeo de privilegios de DB y `ADMIN_WRITES_USUARIOS` apagado por defecto; `ADMIN_WRITES_USUARIOS_SENSIBLE` aparte.
- En esta rama, `deploy/produccion-escritura/scripts/interruptor-usuarios.sh` soporta `estado`, `apagar`, `encender`; NO usar `encender` sin que las puertas K2/K1/A2/A1/A3, D3/D4 y los vistos buenos del DBA/negocio estén verificados. El script exige verificar el código 2B, privilegios `no-sensibles`, `api/capacidades` con sesión admin y respaldo del `.env`; revierte el valor si la comprobación de capacidades no coincide.
- Rama **`feat/cervesur-p5r-password-only`** (PR #19): restablecimiento de contraseña sin cambio de rol, pero documentado **NO-GO** productivo; requiere integración JWT/Flutter, revocación real y autorización de privilegio `UPDATE(password)` exclusivo. No usarlo para desbloquear a los 114 nuevos todavía.

## Evidencia nueva de 12/11

- Portal admin 8443 se ve y lee 190 usuarios / 176 activos / 14 inactivos. MySQL admin declarado: PDO SELECT PASS; los 114 nuevos están activos en DB pero sin contraseñas conocidas.
- Portal usuarios 443: en la misma instancia MySQL, otra identidad de servicio, denegación SQL 1142/1143 en SELECT de `user`. Impide considerar el onboarding listo, aunque U-BASIC admin pueda avanzar separadamente.
- Catálogos/admin y auditoría de empresas/reportes ya validados; el usuario pidió **detener más auditoría de catálogos** y activar Usuarios.

## Estado más reciente de gates encontrado

- Dictamen P5-R del 11/11 en `docs/P5-R-DICTAMEN-TECNICO-20261111.md`: P3 RC1c comparado **4/4**, P4a privilegios de `no-sensibles` PASS real y vista de alta con CHECK OPTION; ello **no** equivale a autorización P4b.
- `docs/UX-09B-CIERRE-FUNCIONAL-GESTION-USUARIOS-20261110.md` en rama UX09A: admin lectura PASS, escritura usuarios NO habilitada en producción; P4b NO-GO hasta cierres de seguridad y autorizaciones.
- `docs/UX-09B-P5-PROPUESTA-HABILITACION-USUARIOS-Y-SENSIBLES-20261110.md` exige K2/K1/A2/A1/A3-F10, D3/D4 y firmas para U-BASIC; P5 diferido. **No inferir** que estas puertas se hayan cerrado al ver conteos de MySQL o QA de reportes.
- A falta de evidencia posterior que declare P4b GO, no ejecutar `ADMIN_WRITES_USUARIOS=1` a mano ni `interruptor-usuarios.sh encender`.

## Preflight inmediato y acotado en PuTTY (SOLO LECTURA)

Objetivo: comprobar si el backend efectivamente declarado para 8443 ya contiene las rutas 2B y cuál es el estado de los interruptores, **sin imprimir .env ni secretos**. Este comando no es una autorización de encendido ni sustituye la puerta K1/K2/D4.

```sh
echo '=== GUA-01 PREFLIGHT 8443 ==='
CONF=/etc/apache2/sites-enabled/cervesur-react-admin-corp.conf
BACKEND=$(sudo awk '$1=="Define" && $2=="CERVESUR_USUARIOS_BACKEND" {print $3; exit}' "$CONF")
BACKEND=${BACKEND#\"}
BACKEND=${BACKEND%\"}
if [ -z "$BACKEND" ] || [ "${BACKEND#/}" = "$BACKEND" ]; then
  echo 'BACKEND=NOT_CONFIRMED'
elif ! sudo test -f "$BACKEND/lib/env.php"; then
  echo 'LOADER=NOT_FOUND'
else
  if sudo grep -q 'fase_2b_bloqueada' "$BACKEND/lib/admin_escritura.php" 2>/dev/null; then
    echo 'CODE_2B=PRESENT'
  else
    echo 'CODE_2B=NOT_CONFIRMED'
  fi
  if sudo test -f "$BACKEND/router/UsuariosRouter.php"; then echo 'USERS_ROUTER=PRESENT'; else echo 'USERS_ROUTER=NOT_FOUND'; fi
  sudo -u www-data -- env -u DB_HOST -u DB_USER -u DB_PASS -u DB_NAME php -d display_errors=0 -d log_errors=0 -r '
    $l=$argv[1]; $base=ob_get_level(); ob_start(); $v=[];
    try { require $l; foreach(["CERVESUR_MODE","ADMIN_WRITES_USUARIOS","ADMIN_WRITES_USUARIOS_SENSIBLE","ADMIN_USER_REACTIVATE_HOURS"] as $k) { $x=getenv($k); $v[$k]=($x===false?"UNSET":$x); } }
    catch(Throwable $e){$v=[];}
    while(ob_get_level()>$base) ob_end_clean();
    echo "MODE=".(($v["CERVESUR_MODE"]??"")==="admin_escritura"?"ADMIN_ESCRITURA":"OTHER_OR_ERROR")."\n";
    echo "USERS_FLAG=".(($v["ADMIN_WRITES_USUARIOS"]??"UNSET")==="1"?"ON":"OFF_OR_INVALID")."\n";
    echo "SENSITIVE_FLAG=".(($v["ADMIN_WRITES_USUARIOS_SENSIBLE"]??"UNSET")==="1"?"ON":"OFF_OR_INVALID")."\n";
    echo "REACTIVATE_HOURS=".(in_array(($v["ADMIN_USER_REACTIVATE_HOURS"]??""),["0","24","48"],true)?$v["ADMIN_USER_REACTIVATE_HOURS"]:"CUSTOM_OR_UNSET")."\n";
  ' "$BACKEND/lib/env.php"
fi
```

Salida orientativa: `CODE_2B=PRESENT`, `USERS_ROUTER=PRESENT`, `MODE=ADMIN_ESCRITURA`, `USERS_FLAG=OFF_OR_INVALID`, `SENSITIVE_FLAG=OFF_OR_INVALID`. **No activar** si CODE_2B es desconocido, si algún sensible está ON inesperadamente o si falta cierre de las puertas de seguridad.

## Liberación propuesta tras cierre formal de gates

1. Verificar P2 backup/reversión vigentes y P3 código 2B instalado; confirmar P4a no-sensibles PASS (se reportó PASS el 11/11).
2. Registrar cierre técnico con evidencia verificable de K2, K1, A1, A2, A3/F-10 y D3/D4, más autorización del dueño de operación y DBA para P4b.
3. Usar el **kit existente** `interruptor-usuarios.sh encender`, con sesión admin validada suministrada de manera segura al operador en el propio servidor; nunca enviar contraseña por chat ni imprimir tokens.
4. Confirmar `usuarios=true`, `usuarios_sensible=false` y empresas/reportes/accesos sin cambios. Si falla: `interruptor-usuarios.sh apagar`, verificar reversión.
5. P4c: **una sola cuenta de prueba autorizada** para alta, edición, baja y reactivación con espera, auditoría atómica y revocación de sesiones. No ejecutar operaciones sobre 190 reales ni passwords de los 114 sin plan aprobado.
6. P5-R queda bloqueado hasta verificación end-to-end de JWT antiguo rechazado en PHP y Firebase/Flutter, más credenciales individuales y GRANT mínimo. Error MySQL SELECT en 443 se resuelve por línea independiente.

**Fuente de verdad de cambios ejecutados:** evidencias observadas en PuTTY y CI; esta guía NO afirma activación realizada.

## Resultado de preflight REAL 8443 (12/11/2026) — verificado por operador

El operador ejecuto desde PuTTY el preflight focalizado que lee el codigo y las variables del backend declarado del portal administrativo 8443. Salida reportada:

```text
=== CERVESUR: ACTIVACION USUARIOS - ESTADO ===
CODIGO_FASE_2B=PRESENTE
MODO=PASS
GESTION_USUARIOS=OFF
GESTION_SENSIBLE=OFF
```

**Interpretacion:** existe el marcador `fase_2b_bloqueada` en el archivo `backend/lib/admin_escritura.php` del directorio declarado por 8443; el loader PHP reporta `CERVESUR_MODE=admin_escritura` y ambas banderas de escritura de usuarios apagadas. No es SHA/atestacion de que todo el codigo RC1c este instalado ni prueba de autorizacion HTTP. **U-BASIC NO ESTA ACTIVADO** y P5 sigue OFF. Se mantienen 190 usuarios (176 activos/14 inactivos) y 114 migrados sin credenciales inicializadas.

**Puertas antes del comando `encender`:** en dictamen del 11/11 P4a `no-sensibles` se verifico PASS y P3 4/4; faltaba evidencia verificable de K2, K1, A1/A2/A3-F10, D3/D4 y autorizaciones. Ninguna de estas puertas se cierra por el nuevo resultado de banderas. No ejecutar `interruptor-usuarios.sh encender` ni editar `.env` manualmente hasta que esos controles esten GO en la instalacion real.

**Proximo paso acotado:** localizar el kit oficial `privilegios-usuarios.sh` y `interruptor-usuarios.sh` del paquete de produccion (nunca descargar scripts no verificados para ejecutarlos como root), ejecutar solo `interruptor-usuarios.sh estado` y `privilegios-usuarios.sh --etapa no-sensibles --verificar` despues de comprobar el origen/huella del kit, y cotejar la hoja K1/K2/D3/D4 antes de activar. Si ya hay verificacion firmada reciente de las puertas, reutilizarla en vez de repetir auditoria. Con gates aprobados, la activacion puede ser un solo comando oficial mas QA focalizado. Sin SSH directo desde ChatGPT, el operador debe ejecutar los comandos en PuTTY y devolver solo codigos/resultados.

## P4a real verificado con kit oficial, 12/11/2026 — PASS

Operador ejecutó el comando de verificación de huella Git (`git hash-object`) y `privilegios-usuarios.sh --etapa no-sensibles --verificar`, sin aplicar GRANT ni cambiar configuración. Salida literal:

```text
=== CERVESUR: PUERTA P4A USUARIOS ===
KIT_OFICIAL=PASS
PRIVILEGIOS_USUARIOS=PASS
```

**Conclusión acotada:** se localizó por lo menos una copia del verificador cuyo hash Git coincide con el blob oficial de `feat/cervesur-ux-09a-cierre-usuarios` (`d849a014ff938e67d3a31d88da955be7fc453462`), y **esa copia** completó la validación de privilegios de fase `no-sensibles` con PASS (script de solo lectura). Esto confirma **P4a de permisos mínimos** y evita repetir su comprobación. La salida no demuestra por sí sola la huella del paquete `interruptor-usuarios.sh`, ni K1/K2/A1/A2/A3/D3/D4, ni una autorización P4b; no hay evidencia de que el operador haya encendido gestión.

**Decisión:** mantener `GESTION_USUARIOS=OFF` y `GESTION_SENSIBLE=OFF` hasta verificar las salvaguardas bloqueantes restantes y autorización administrativa/DBA; luego activar exclusivamente U-BASIC con el kit oficial y una cuenta sintética autorizada. No usar `.env` directo ni habilitar `usuarios_sensible` o P5-R para contraseñas de los 114 migrados. Lote de catálogos y Power BI fuera de alcance.

## K1: prueba HTTP funcional de ruta retirada — 410 (12/11/2026)

El operador, siguiendo el comando de solo lectura y SIN Authorization, obtuvo:

```text
=== CERVESUR: VERIFICACION K1 ===
K1_HTTP=410
```

Prueba contra el VirtualHost corporativo del servidor por puerto HTTP 80 (ruta `/api/powerbi/tokens/0`). El correctivo K1 documenta que el modo `retirar` devuelve **410** antes de consultar la base o emitir token, mientras `observar` / `mixto` devuelven respuesta distinta para id 0. Por ello, `K1_RUTA_RETIRADA_HTTP=PASS` para esa ruta y ese origen. La prueba no contiene ni expone credenciales o tokens.

**Límite:** una respuesta 410 en una única URL no atesta por sí sola la ausencia de consumidores legítimos, que la política está aplicada homogéneamente en todos los hosts/entradas ni el cierre formal A-L2. Puede considerarse una evidencia fuerte del modo `retirar` efectivo en ese vhost, no una firma de liberación. El script de D4 incluye D4-08/D4-08b y debe cruzarse con la evidencia antes de marcar `K1=GO_FINAL`.

**Estado acumulado:** `P4A_NO_SENSIBLES=PASS_REAL`, `K1_RUTA_RETIRADA_HTTP=PASS`, `CODIGO_FASE_2B=PRESENTE`, `MODO=PASS`, `GESTION_USUARIOS=OFF`, `GESTION_SENSIBLE=OFF`. Continúan pendientes de evidencia para P4b K2, A1/A2, A3/F-10, D3, D4 y cierre de autorizaciones. No encender ni editar `.env` hasta entonces.

**Próximo bloque recomendado, sin volver a auditar catálogos:** ejecutar la atestación de solo lectura D4 con script verificado SHA-256, previo consentimiento/constancia de la persona responsable para esa inspección. Reportar exclusivamente PASS/FAIL/PENDIENTE por código D4-01 a D4-09, sin copiar el contenido de `.env`. Esto permite evaluar JWT, estado K1/PowerBI, F-10 y huellas en un lote; después únicamente resolver controles faltantes antes de autorizar el kit `interruptor-usuarios.sh encender`.
