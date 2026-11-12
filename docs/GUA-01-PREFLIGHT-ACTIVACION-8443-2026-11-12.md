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

## 12/11/2026 — D4 REAL: 6 PASS / 3 FAIL / 2 PENDIENTE, P4b NO-GO

El operador ejecutó `d4-atestacion-solo-lectura.sh` sobre `/var/www/cervesur/backend` con `--expiracion-prevista 86400`. El kit oficial tuvo `KIT_D4=PASS` (huella SHA-256 comparada contra el manifiesto), y el resumen real fue:

```text
KIT_D4=PASS
D4-01=PASS
D4-02=PASS
D4-03=PASS
D4-04=PASS
D4-05=PASS
D4-06=PASS
D4-06b=PENDIENTE
D4-07=FAIL
D4-08=FAIL
D4-08b=PENDIENTE
D4-09=FAIL
D4-RESUMEN: 6 PASS, 3 FAIL, 2 PENDIENTE  (PENDIENTE no es aprobacion)
D4_EXIT_CODE=1
```

**Diagnóstico de naturaleza estrictamente documental / lectura; NO modificar todavía el servidor:**

- `D4-01..06 PASS`: la atestación del secreto JWT pasó sus comprobaciones de existencia, longitud, valores débiles, variedad y no coincidencia con literales por defecto. Esto no exonera las 3 fallas restantes.
- `D4-06b PENDIENTE`: el script detecta literales por defecto en los archivos heredados, potencial riesgo si alguna ruta cae al literal; verificar los detalles antes de decidir parche de seguridad.
- `D4-07 FAIL`: la duración real de `JWT_EXPIRATION` **no coincide** con `86400` esperado. La salida filtrada no muestra el valor observado. Un dictamen previo de P5-R reportó `10800` (3 h), pero **no** debe tratarse como valor actual sin confirmación en la salida detallada. No cambiar TTL ni secreto automáticamente: cualquier cambio afecta sesiones Flutter.
- `D4-08 FAIL`: la revisión estática del código no acredita `POWERBI_AUTH_MODE=retirar` como único literal. `K1_HTTP=410` fue observado aparte contra el vhost HTTP del 80; puede haber otro `define`/fallback, o rutas/raíces distintas. No marcar K1 GO universal con la discrepancia sin verificar la ejecución efectiva en las entradas relevantes.
- `D4-08b PENDIENTE`: el script **siempre marca PENDIENTE** porque él mismo no hace red; conservar `K1_HTTP=410` como evidencia externa del vhost probado y cotejarla formalmente. No repetir llamadas sin razón.
- `D4-09 FAIL`: el verificador no acredita F-10 instalado en `router/auth.php` del backend heredado. Puede ser el marcador ausente o una alternativa no reconocida; revisar el texto de diagnóstico y ruta efectiva antes de declarar registro público abierto o desplegar el parche. F-10 condiciona P4b por el riesgo de cuentas sin aprobación.

**Resultado:** `P4A_NO_SENSIBLES=PASS_REAL`, `K1_RUTA_RETIRADA_HTTP=PASS`, `D4_FINAL=FAIL`, `P4B=NO_GO`; `GESTION_USUARIOS=OFF`, `GESTION_SENSIBLE=OFF`. Usuario quiere habilitación prioritaria; no son necesarios más chequeos de catálogos, empresas ni reportes, solo resolver estas puertas reales y A1/A2/K2/A3.

**Siguiente paso agrupado (única ejecución SOLO LECTURA):** repetir el script D4 oficial con el mismo `--expiracion-prevista 86400` y devolver SOLO las líneas con motivo de `D4-06b`, `D4-07`, `D4-08`, `D4-08b`, `D4-09` (el comando anterior imprimió solo estado, ocultando las razones). Su salida está diseñada para no contener secretos; filtrar estrictamente esas líneas, sin imprimir contenido de `.env`, hash ni nombres privados. Con los motivos exactos se prepara la intervención K1/F-10/JWT correspondiente, con firmas de DBA/Infra y rollback antes de cualquier escritura productiva.

## D4 causas detalladas verificadas (12/11/2026) — plan de corrección, no activación

El operador ejecutó nuevamente el verificador D4 autenticado por huella oficial y publicó **solo los motivos despersonalizados**:

```text
KIT_D4=PASS
PENDIENTE  D4-06b 9 rutas del backend aun traen un literal por defecto
FAIL       D4-07  JWT_EXPIRATION=10800 s; previsto=86400 s
FAIL       D4-08  el modo 'retirar' NO es el unico valor definido (valores: observar retirar )
PENDIENTE  D4-08b prueba funcional GET /api/powerbi/tokens/0 sin Authorization (ya fue 410 en la prueba HTTP del operador)
FAIL       D4-09  F-10 NO instalado en router/auth.php: POST /api/register sigue abierto al publico
```

**Diagnóstico y decisión propuesta (no aplicar automáticamente):**

1. **D4-07** es una discrepancia real: el backend heredado configura sesiones JWT de **10800 segundos (3 horas)** mientras que `UX-09B-DECISION-JWT-Y-RIESGO-RESIDUAL.md` documenta aprobación de **86400 segundos (24 horas)**. El TTL menor NO prueba por sí mismo un defecto de seguridad, pero no cumple lo decidido. No cambiarlo sin una ventana y QA Flutter que pruebe login, expiración 401 y recuperación de sesión. Alternativa formal: decisión del funcional de aceptar 3h con justificación y actualizar el criterio de D4; no modificar parámetros de ejecución del verificador para simular PASS.
2. **D4-08** refleja los dos valores estáticos `observar` y `retirar`, mientras la prueba HTTP específica `GET /api/powerbi/tokens/0` del vhost corporativo devolvió **410**. Esto es una discrepancia entre análisis estático de definiciones (posible fallback `observar`) y comportamiento observado para esa entrada; NO concluir que el endpoint comprobado sigue emitiendo tokens, pero tampoco declarar K1 seguro en todas las entradas. Corregir/eliminar fallback inseguro o atestar alcance según release y todas las rutas efectivas; comprobar consumidores legítimos/acta A-L2.
3. **D4-09 es el bloqueo prioritario**: el backend legado no contiene el marcador F-10 de cierre en `router/auth.php`. Según el procedimiento ya versionado `UX-09A-F10-REGISTRO-PUBLICO.md`, la API de registro heredada Flutter no envía Authorization y cerrarla elimina también el alta administrativa de Flutter. Requiere antes **puente de altas temporal** firmado/ensayado por DBA y negocio; sin ello se rompe continuidad operativa. La propuesta existente indica F-10 junto al **Lote 4 A2**, más compensaciones de A1/K2, respaldos y reversión. Una prueba con POST real sin datos no debe improvisarse por riesgo de DML; ya existe evidencia de código/rutas.
4. **D4-06b**: 9 rutas con fallback literal de JWT. D4-02..06 indican que la clave efectiva actual es fuerte y distinta de los defaults, pero los defaults siguen siendo riesgo si deja de cargarse. Planear correctivo fail-closed (usar secreto obligatorio, no literal); no sustituir `JWT_SECRET` ni rotarlo sin plan de sesiones/Flutter.
5. **Parche F-10 no materializado en la rama inspeccionada**: se consultó `feat/cervesur-ux-09a-cierre-usuarios` y la ruta referida `deploy/flutter-heredado/lote4-a2/F-10-registro-cerrado.diff` devolvió 404. Existe diseño `docs/UX-09A-F10-REGISTRO-PUBLICO.md` y procedimiento de alta temporal `docs/UX-09A-PROCEDIMIENTO-ALTA-TEMPORAL.md` (23/23 local reportado, firma y ensayo DBA pendientes). **No inventar un parche ni aplicarlo en producción**: recuperar el artefacto fuente confiable o generarlo contra el `router/auth.php` heredado exacto, en rama aislada con pruebas, hash y rollback.

**Puertas finales antes de P4b:** K2 lote1 y firma A-L1; K1 config efectiva y A-L2; A2 lote4 + A3/F-10 procedimiento de altas + firma A-L4; A1 lote3 + firma A-L3; D3 legado real, D4 sin bloqueos, respaldos P2 y autorizaciones D/E. P4a ya PASS REAL; no repetir. `GESTION_USUARIOS=OFF` y `GESTION_SENSIBLE=OFF` se mantienen; no ejecutar interruptor.

**Siguiente trabajo concreto:** en GitHub, recuperar/implementar en una rama correctiva controlada el parche F-10 sobre la versión efectiva del archivo, con guía de instalación/rollback y QA. Paralelamente obtener firma/ensayo del procedimiento puente para no paralizar altas de Flutter; corregir la configuración K1 y acordar la política JWT 3h vs 24h. Solo al cerrar esas puertas se activa U-BASIC. Nada de cambios de rol/contraseña ni activación masiva de 114 usuarios.

### Hallazgo complementario: PR #22 contiene el correctivo F-10 controlado (12/11/2026)

**Corrección sobre comentario anterior:** no existe el parche en la ruta `deploy/flutter-heredado/lote4-a2/F-10-registro-cerrado.diff` de UX09A, **PERO sí hay un correctivo versionado en el PR #22 DRAFT** [`fix/f10-proteger-registro-legacy-controlado`](https://github.com/baltrekconsultoria/cervesur-plataforma/pull/22), SHA de cabeza `d76e46395b2c40c464d5c4d140906c5c1eb08a2a`. No duplicar desarrollo.

- `ops/f10/patch_register.py`: parche mínimo que exige JWT y rol admin para `POST /api/register`, sin sustituir el router completo; sólo admite la huella esperada del `auth.php` heredado.
- `ops/f10/f10-install-controlled.sh`: `preflight` (sin tocar productivo), `apply` con respaldo y reemplazo atómico y `rollback` validado por SHA. Expectativas de SHA registradas en PR: `auth.php` `a4f063a53db42d738b4b6ee9b69cdad946bb99ba496f7aebd8027a67c86621f2` y middleware H1 `c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298`.
- `ops/f10/test_f10.py` prueba 6 escenarios sintéticos, reportados PASS. CI de commit en GitHub consultado: workflow F10 aislado run 43124118090 **success** y Backend CI run 43124118119 **success**. Son ensayos y no equivalen a prueba PHP/HTTP de la versión productiva.
- **Advertencia funcional:** en `UX-09A-F10-REGISTRO-PUBLICO.md` se constató que la llamada `RegisterCall` desde Flutter no envía JWT. Por tanto, aun si PR #22 permite alta con JWT admin, bloquearía **las altas desde el Flutter heredado**; la continuidad requiere puente DBA firmado/ensayado o alternativa funcional aprobada. No hacer merge/deploy de #22 automáticamente por tener CI verde.
- `D4-09` no detectará necesariamente este esquema de guardia admin (busca el literal `REGISTRO_ABIERTO`); después de un eventual parche, verificar F-10 mediante QA HTTP negativa y revisión de la guardia instalada, no falsificar el resultado D4. La interpretación de D4-09 deberá adaptarse a la evidencia del correctivo elegido, sin declarar PASS estático si falla por semántica diferente.

**Próxima decisión operacional:** para acortar el camino, utilizar **PR #22 como candidato** y realizar `preflight` de compatibilidad con huellas reales del router y H1 (sin aplicar). Antes de `apply`, definir continuidad para el alta de Flutter, verificar respaldos y contar con autorización DBA/funcional; antes de `P4b` cerrar además K2/A1/A2/D3/D4 y la política JWT. En ningún caso `K1_HTTP=410` o CI PASS abren automáticamente U-BASIC.

## 12/11/2026 — Bloqueo de compatibilidad de PR #22 F-10: auth.php NO_COINCIDE

El operador ejecutó la comprobación de SHA-256 de la versión real de `/var/www/cervesur/backend/router/auth.php` y del middleware H1, contrastadas contra el PR #22 (head `d76e46395b2c40c464d5c4d140906c5c1eb08a2a`). Salida reportada:

```text
=== CERVESUR: COMPATIBILIDAD F10 PR22 ===
AUTH_PHP=NO_COINCIDE
MIDDLEWARE_H1=PASS
CAMBIOS_PRODUCTIVOS=NO
```

**Veredicto inmediato: PR #22 no es aplicable tal cual**, su instalador `ops/f10/f10-install-controlled.sh` exige `auth.php` SHA `a4f063a53db42d738b4b6ee9b69cdad946bb99ba496f7aebd8027a67c86621f2` y `auth_middleware.php` H1 SHA `c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298`. Solo el segundo coincide. No ejecutar `apply`, no reemplazar el router por versión de `main`, no cambiar el `EXPECTED` del instalador para superar la puerta: la diferencia puede ser una modificación legítima de producción o una ruta ya protegida por implementación diferente.

**Próximo control focalizado SOLO LECTURA:** obtener SHA-256 vigente de `router/auth.php`, resultado `php -l` y presencia de marcadores `F10-20261111` o `REGISTRO_ABIERTO` (solo sí/no). No imprimir código ni variables secretas. Si existe protección alternativa, revisarla antes de parchear; si no, recuperar únicamente una copia saneada del bloque `POST /api/register` por canal privado, calcular huella original y actualizar el PR #22 con nuevo parche mínimo y nuevas pruebas aisladas. La confirmación `D4-09 FAIL` (marcador ausente) no sustituye revisión del bloque activo.

**Pendencias adicionales intactas:** JWT 10800 s frente a 86400 previsto; K1 `observar` y `retirar` pese a 410 en una entrada; 9 rutas JWT fallback; K2/A1/A2/A3-F10 y D3. P4a ya PASS; Usuario/sensible siguen OFF. Procedimiento puente de altas Flutter y autorizaciones de DBA/funcional siguen necesarios antes de liberar F10. No se realizó ninguna operación en producción desde el asistente.
