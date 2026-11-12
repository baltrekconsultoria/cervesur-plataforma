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

## 12/11/2026 — CORRECCIÓN CRÍTICA: auth.php contiene marcador F-10 de PR #22

Resultado real de solo lectura aportado por el operador:

```text
AUTH_SHA256=4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366
AUTH_BYTES=4971
AUTH_SINTAXIS=PASS
MARCADOR_PR22=PRESENTE
MARCADOR_F10_LEGACY=AUSENTE
CAMBIOS_PRODUCTIVOS=NO
```

**Reinterpretación obligatoria** del hallazgo anterior `AUTH_PHP=NO_COINCIDE`: el PR #22 exige como preimagen SHA `a4f063a5...`, de modo que un archivo **ya parchado** naturalmente NO coincidirá con esa preimagen. En `ops/f10/patch_register.py` el guardia añade exactamente **373 bytes** de código (marcador `F10-20261111`, `get_jwt_secret_or_fail`, `require_auth`, rol `admin`), que sumados a los **4598 bytes** del `auth.php` heredado documentado producen **4971 bytes**, idéntico al tamaño observado. El marcador PR22 aparece en el fichero, el lint PHP pasa y la huella H1 estaba en PASS. **Hipótesis fuerte: F-10 de PR22 YA ESTÁ INSTALADO EN PRODUCCIÓN**; falta cotejar el contenido del bloque y verificar HTTP 401 sin token. No atribuir el cambio a una acción del asistente: se observó en el servidor, no se ejecutó apply aquí.

**D4-09 FAIL debe reclasificarse como `VERIFICADOR_NO_RECONOCE_MARCADOR_PR22` hasta confirmar la protección real**. D4 busca exclusivamente `REGISTRO_ABIERTO` de diseño F10 legacy anterior; PR22 instala una guardia `F10-20261111` sin ese literal. Es un posible **falso negativo del detector**, NO prueba de registro abierto. NO cambiar `.env`, ni reinstalar PR22, ni revertir el `auth.php` actual; no alterar SHA esperado del instalador. PR #22 sigue GitHub DRAFT, lo que no demuestra nada del estado efectivo del servidor.

**Siguiente control sin modificar cuentas:** verificar por lectura (en mismo archivo y dentro de la ruta POST `/api/register`) una sola guardia `F10-20261111`, `get_jwt_secret_or_fail`, `require_auth`, comparación de rol `admin`, salida 403 para rol distinto; luego probar **solo petición POST sintética con JSON `{}` y SIN token**, desde la ruta HTTP conocida del backend heredado, exigiendo HTTP 401. No probar alta con datos válidos. Si la guardia o 401 fallan, detenerse y diagnosticar. En caso PASS, `F10_GUARDIA_Y_NEGATIVO=PASS`, pero queda verificar 403 para rol no admin en cuenta de QA autorizada y documentar continuidad de alta en Flutter.

**P4b aún no se enciende:** revisar los otros controles K2/A1/A2/D3, política JWT real 10800 vs 86400 y K1 estático `observar`+`retirar` con 410 HTTP. P4a PASS; no repetir. La discrepancia F10 quizá ya no sea una deuda de despliegue, sino de verificación/evidencia. P5 sensible OFF.

## 12/11/2026 — F10 guardia instalada y rechazo anonimo QA PASS REAL

El operador ejecuto el QA focalizado de la ruta legacy usando `auth.php` con SHA-256 `4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366`, sin alterar archivos y enviando exclusivamente POST con JSON `{}` SIN token. Salida real:

```text
=== CERVESUR: QA F10 INSTALADO ===
F10_GUARDIA=PASS
F10_HTTP_SIN_TOKEN=401
F10_NEGATIVO=PASS
```

**Resultado acotado:** la guardia esta presente dentro de `POST /api/register` y contiene marcador `F10-20261111`, `get_jwt_secret_or_fail`, `require_auth`, chequeo de `role=admin` y respuesta 403 para rol no admin. La entrada HTTP del legado retorna **401** ante POST anonimo de datos invalidos. Por tanto **F10_REGISTRO_ANONIMO=PASS_REAL**; el falso negativo D4-09 del verificador esta desactualizado y busca `REGISTRO_ABIERTO`, no el marcador PR22. No alterar ni reinstalar PR22.

**Limitaciones pendientes:** No se ha efectuado prueba HTTP con una cuenta QA de rol `user`/`usuario` validamente autenticada para confirmar rechazo 403. El test de cuerpo `{}` no sustituye un examen de toda la cadena de servidor ni acredita autorizacion de flujo admin. Flutter heredado no envia JWT en `RegisterCall`: su flujo anterior de alta probablemente se interrumpe, por lo que se requiere decision de continuidad/alternativa DBA mientras la alta 8443 siga OFF. No crear cuentas reales para demostrar.

**Puertas acumuladas para GUA-01:** P4a permisos MySQL no sensibles PASS REAL; K1 HTTP 410 PASS en una entrada, cierre formal/consumidores pendiente; F10 anonimato PASS REAL, 403 no-admin pendiente. D4 JWT real 10800 s frente a objetivo 86400; D4 K1 ve `observar` y `retirar`; nueve literales default heredados. K2, A1, A2, D3 y autorizaciones son pendientes de verificacion de sus evidencias. **P4b permanece OFF** y P5 sensible OFF. Ningun cambio productivo realizado desde ChatGPT.

## 12/11/2026 — K2 y parte de A2 verificados por huellas, A1 requiere discriminación

El operador ejecutó en `/var/www/cervesur/backend` comprobación SOLO LECTURA contra el manifiesto de GitHub y obtuvo:

```text
=== CERVESUR: ESTADO CORRECTIVOS K2 A1 A2 ===
K2_ROUTER=COINCIDE
K2_SANITIZADOR=COINCIDE
A1_ROUTER=MODIFICADO_VERIFICAR
A2_ASIGNACIONES=COINCIDE
A2_EMPRESA_USUARIO=COINCIDE
CAMBIOS_PRODUCTIVOS=NO
```

**Lectura precisa:** K2 (EmpresaRouter + empresa_sanitizer.php) coincide con las huellas esperadas y F-4 (los routers de Asignacion y EmpresaUsuario) coincide con el manifiesto del Lote 4. Esto es **PASS de version/huella para esos archivos** y no equivale a QA HTTP completo ni a certificacion del lote A2 completo (F-5a/F-5b/F-5c todavia deben verificarse). El chequeo de K2 aceptaba tanto `0c710e...` (K2 solo) como `3b0e38...` (K2 + F-5a), por lo que no permite inferir si F-5a esta instalado.

**A1_ROUTER=MODIFICADO_VERIFICAR no es FAIL**: el comando previo comparó únicamente con la huella **base** `60b011d54265...`. Cualquier F-3/A1 instalado cambia la huella y genera ese mensaje. La documentacion `deploy/flutter-heredado/MANIFIESTO-SHA256.md` indica base A1 `[R]` textual parcial y NO publica huella de salida completa; por ello se requiere leer únicamente las guardias `GET/PUT /api/user/profile/{id}` de forma local y compararlas con el patrón de autenticacion + dueño/admin + 403, sin imprimir JWT ni archivos enteros.

**Próximo chequeo, solo A1 y discriminación F-5a:** comprobar la existencia y posición de guardia `require_auth` y `user_id` propio/admin en `UserRouter.php`; reportar SHA y booleans, además de identificar si EmpresaRouter tiene K2 solo o K2+F5a. Si A1 está instalada, no re-parchar. Con ello priorizar QA negativo de perfil ajeno en cuenta de prueba autorizada. Mantener P4b OFF hasta K2/A1/A2/D3, JWT 10800 vs 86400 y autorización de seguridad. No se escribio en produccion.

## 12/11/2026 — A1 F-3 y F5a confirmados por el operador

Resultados del cotejo SOLO LECTURA en servidor legado:

```text
=== CERVESUR: VALIDACION A1 Y F5A ===
F5A=VERSION_CON_K2_Y_F5A
A1_SHA256=41610318f9bb5cd11ad7daa40cfbcf95f1742e5f14d5c4d4b59e4613a5206709
A1_GUARDIA=PASS_ESTRUCTURAL
CAMBIOS_PRODUCTIVOS=NO
```

**Interpretación estricta:** `router/EmpresaRouter.php` coincide exactamente con el SHA de K2 + F-5a reportado en `deploy/flutter-heredado/MANIFIESTO-SHA256.md` (`3b0e381c...`); no se necesita reinstalar F5a. El `router/UserRouter.php` de SHA completo `41610318...` contiene la estructura de F-3/A1: autenticación `require_auth` antes de denegar con 403 a un actor no administrador que solicita un perfil ajeno. Esto es **PASS estructural de protección A1 en la ruta inspeccionada**, no suite HTTP real ni cotejo de todos los verbos/alias; su cambio de SHA con respecto a la huella BASE es esperado para un parche instalado.

**Acumulado:** K2 archivo router y sanitizador coincidieron; A2 F-4 (dos routers) coincidió, F-5a coincidió; A1 estructura PASS; F10 guardia PASS y POST anónimo HTTP401; K1 HTTP410 para una entrada; P4a de permisos no sensibles PASS. No repetir ni desplegar estos correctivos. Quedan F-5b y F-5c sin comprobar, pruebas negativas con cuenta autorizada (roles A1/F10/A2), conciliación K1 de modo observar/retirar, JWT 10800 vs 86400, D3 restante y constancias/rollback/decisiones de continuidad Flutter. `ADMIN_WRITES_USUARIOS` / sensible siguen OFF y P4b NO-GO hasta liberar la fase.

**Siguiente prueba agrupada y acotada:** inspección estructural sin escritura de rutas POST/PUT/DELETE en `ReporteRouter.php` (F5b) y `EmpresaReporteUsuarioRouter.php` (F5c). Requerir en cada operación `require_auth`, validación explicita de rol `admin` y respuesta 403 antes de controlador. No inferir PASS HTTP solo de patrones estáticos. No hacer otra auditoría general de empresa/reportes ni repetir huellas ya aprobadas.

## 12/11/2026 — F-5b y F-5c: 6/6 comprobaciones de autorización estructural PASS

El operador ejecutó comprobación SOLO LECTURA en los routers productivos del legado, evaluando la secuencia `require_auth(...)` → chequeo de `role == admin` → `403` → `exit` en cada ruta de escritura POST, PUT y DELETE. Salida:

```text
=== CERVESUR: CIERRE ESTRUCTURAL F5B F5C ===
F5B_POST=PASS_ESTRUCTURAL
F5B_PUT=PASS_ESTRUCTURAL
F5B_DELETE=PASS_ESTRUCTURAL
F5B_RESULTADO=PASS
F5C_POST=PASS_ESTRUCTURAL
F5C_PUT=PASS_ESTRUCTURAL
F5C_DELETE=PASS_ESTRUCTURAL
F5C_RESULTADO=PASS
CAMBIOS_PRODUCTIVOS=NO
```

**Alcance del PASS:** seis patrones estructurales en `router/ReporteRouter.php` (F-5b) y `router/EmpresaReporteUsuarioRouter.php` (F-5c) conformes; no demuestra autorización HTTP extremo a extremo, el cierre de todas las rutas alternativas ni comportamiento en la base de datos. No repetir lectura ni reinstalar F-5b/F-5c. Queda con evidencia favorable de versión o estructura el conjunto K2 (router+sanitizador), A1 (F3 en UserRouter), A2 F4 (asignaciones, empresa-usuario), F5a (router empresas), F5b, F5c y F10 (guardia y 401 anonimo). `P4A_PRIVILEGIOS=PASS`, `K1_HTTP_410=PASS` para entrada probada.

**Decisión operativa:** cerrar fase de verificaciones estáticas por archivo. Preparar **una sola revisión de liberación P4b** con evidencias existentes, y cubrir solamente huecos que cambian seguridad/continuidad:

1. Autorización funcional + DBA e informe de gates K1/K2/A1/A2/A3 y D3 aceptados en entrada(s) reales; verificar comportamiento con una cuenta QA `user` y otra admin, sin operaciones de negocio salvo prueba expresamente aprobada.
2. Diferencia **JWT_EXPIRATION=10800 (3h)** productivo frente a **86400 (24h)** de la decisión previa: resolver por cambio planeado + prueba Flutter o decisión documentada de aceptar duración actual. No forzar el resultado del verificador ni tocar secreto sin autorización.
3. K1 tiene literales `observar` y `retirar`; confirmar cierre formal de modo efectivo en todas las entradas y conservar resultado 410, con criterio de detector D4 ajustado a la implementación, no declarando falsamente que no existe `observar`.
4. F10 PR22 guardia instalada y POST anónimo 401; prueba 403 con `user` válido pendiente. Alta de Flutter sin JWT bloqueada por F10: aprobar continuidad de altas por 8443 ya liberado o puente DBA. Las 114 cuentas activas siguen sin inicialización segura de contraseña y el portal 443 tiene otra cuenta MySQL sin SELECT de `user`.
5. Plan de respaldo/reversión del interruptor P4b; activar **solo** U-BASIC una vez que todas las puertas se declaren GO; sensible/P5 sigue OFF.

**Estado final de este bloque:** `F5B=PASS_ESTRUCTURAL`, `F5C=PASS_ESTRUCTURAL`, `P4B=NO_GO_PENDIENTE_LIBERACION`; `GESTION_USUARIOS=OFF` y `GESTION_SENSIBLE=OFF` según último chequeo de entorno. Ninguna modificación productiva se ejecutó en esta conversación.

## 12/11/2026 — RELEASE K1/JWT APLICADO EN PRODUCCION CON BACKUP, VERIFICADO

El operador ejecuto el paquete fusionado en GitHub [PR #25](https://github.com/baltrekconsultoria/cervesur-plataforma/pull/25), commit inmutable `c2f01a7942f12427a9267d8325c432554a2c7214`, `ops/p4b/legacy_policy.py` con blob `3253b72bbb2d1685a3df886f16e85ad84d6340ed`. Primero valido `git hash-object` del kit, sin transferir secretos. Salida exacta resumida:

```text
KIT_INTEGRIDAD=PASS
ANTES: F10_SHA=PASS H1_SHA=PASS JWT_ACTUAL_SEG=10800 K1_FALLBACK_ACTUAL=observar
PREFLIGHT=PASS_SOLO_LECTURA
BACKUP_VERIFICADO=SI
BACKUP_PATH=/home/usr_cervesur/cervesur-p4b-policy-20261112T113010Z-1975881
JWT_NUEVO_SEG=86400
K1_FALLBACK_NUEVO=retirar
APLICADO=SI
REINICIO_APACHE=NO
DESPUES: F10_SHA=PASS H1_SHA=PASS JWT_ACTUAL_SEG=86400 K1_FALLBACK_ACTUAL=retirar
CAMBIO_JWT=NO
CAMBIO_K1=NO
PREFLIGHT=PASS_SOLO_LECTURA
K1_HTTP=410
```

**Veredicto del lote:** `K1_JWT_RELEASE=PASS_PRODUCTIVO_ARCHIVOS_Y_HTTP`, backup privado previo reportado `BACKUP_VERIFICADO=SI`. Se modificaron unicamente los archivos que permite el instalador: `/var/www/cervesur/backend/.env` (JWT_EXPIRATION de 10800 a 86400) y `lib/powerbi_authz.php` (fallback `observar` a `retirar`). PHP F10 `router/auth.php` y middleware H1 permanecen con huellas invariantes. No SQL, usuarios, roles, contrasenas, Apache ni frontends modificados por este lote. No reproducir, publicar ni adjuntar contenido del respaldo porque contiene `.env`/secretos. Conservar su ruta exacta para eventual rollback. El preflight posterior no modifica la aplicacion; la frase `PRODUCCION_MODIFICADA=NO` en ese paso significa que **la verificacion no hizo nuevas escrituras**, no que el apply anterior no hubiera cambiado archivos.

**Limitaciones:** `JWT_ACTUAL_SEG=86400` acredita la linea de configuracion del archivo, pero no por si solo la emision efectiva de JWT 24h; comprobar inicio de sesion de cuenta controlada y claim exp/iat en entorno autorizado sin revelar tokens ni contrasenas. `K1_HTTP=410` valida la entrada HTTP especifica y el fallback de codigo ya es `retirar`; otras entradas/consumidores y acta A-L2 se verifican aparte. D4-09 es detector heredado que no reconoce el guard `F10-20261111` pese a QA F10 guardia PASS/POST anonimo 401. Nueve literales default JWT D4-06b aun requieren seguimiento separado. Portal de usuarios 443 carece de SELECT en cuenta MySQL distinta, incidencia independiente.

**Proximo hito:** QA funcional minimo de autenticacion y autorizacion con cuentas de prueba (no enviar tokens), cierre D3/firmas/continuidad altas Flutter, respaldo SQL previo a P4b y autorizacion para encender exclusivamente U-BASIC. Las banderas de Usuarios y Usuarios sensible NO fueron activadas por este release: conservar OFF hasta nueva atestacion y GO. No repetir auditorias de codigo K2/A1/A2/F10.
