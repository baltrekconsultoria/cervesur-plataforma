# Cervesur — Piloto U-BASIC de administrador único (alcance excepcional)

**Fecha:** 12/11/2026. **Solicitud:** habilitar cuanto antes gestión de usuarios exclusivamente para un administrador autorizado del piloto. Este documento NO es un GO global P4b, NO ejecuta comandos ni sustituye las autorizaciones de DBA/negocio. Se conserva `UX-09B-PUERTAS-DE-SEGURIDAD.md` v2 intacto.

## 1. Objetivo y funcionalidad permitida

Permitir desde el portal administrativo React en 8443, usando el código P3 ya instalado y los privilegios P4a no sensibles ya verificados:
- Crear cuentas **estándar**; el backend fija el rol `user` y no acepta que el cliente elija `admin`.
- Editar nombre, apellido y teléfono, con validación de versión/auditoría.
- Desactivar; reactivar con espera operativa de 24 horas.

**No habilitar:** edición de correo (acceso y privilegios de producción deben mantenerlo bloqueado), cambios de rol, cambio/reset de contraseñas (P5), auto-asignación de accesos a informes, ni apertura de acceso a otros administradores durante el piloto. El alta inicial requiere contraseña inicial generada/tratada mediante canal seguro; no adjuntarla a registros o capturas.

## 2. Punto importante del interruptor

El flag `ADMIN_WRITES_USUARIOS=1` **no es por identidad**: abre la capacidad para TODAS las sesiones de administrador que pasen la autenticación y lista blanca administrativa del 8443. **Condición de piloto:** el custodio verifica que la allowlist vigente admite ÚNICAMENTE al administrador designado, sin habilitar usuarios adicionales. No bastan menús ocultos, la frase «solo entra el administrador» ni la existencia de cuenta admin. Nunca compartir un usuario/contraseña administrativa.

El entorno `admin_escritura` revalida sesión opaca, rol admin, estado y `session_version` por solicitud. No se cambia el modo, ni la base, ni la política de altas.

## 3. Excepción expresamente delimitada al control v2

A solicitud funcional se propone **postergar la ejecución de pruebas adicionales D3 de no elusión A1/A2/A3** únicamente para una ventana de piloto de cuenta admin única, manteniendo TODOS los controles de código actualmente desplegados: middleware H1, F10, K1, K2, bloqueos de roles/correos/contraseñas, RLS y límites de rutas. La excepción es de **evidencia y alcance**, jamás de desactivar protecciones existentes. Se acepta que los controles no probados conservan riesgo residual; ello se registrará como `P4B_PILOTO=GO_CONDICIONADO` **solo si** se satisfacen las condiciones siguientes. El P4b global aprobado en UX-09B v2 continúa NO-GO.

## 3b. Preflight productivo ejecutado por operador (12/11/2026)

Evidencia recibida, sin valores sensibles:

```text
INTERRUPTOR_RC=0
ADMIN_WRITES_USUARIOS=<sin definir>
ADMIN_WRITES_USUARIOS_SENSIBLE=<sin definir>
ADMIN_USER_REACTIVATE_HOURS=<sin definir> (default funcional 24h, confirmar capacidades)
RESULTADO-PRIVILEGIOS: PASS no-sensibles
PRIVILEGIOS_RC=0
BACKEND_ADMIN=/opt/cervesur-react-admin/backend/lib/admin_escritura.php
VHOST=192.168.200.12:8443
CAMBIOS_PRODUCTIVOS=NO
U_BASIC_ACTIVADO=NO
```

Se localizó el kit operativo exacto en `/home/usr_cervesur/ux08i-rc1c-candidato/cervesur-react-admin-fase2b-2f88cfbc5098-20261110-181457Z/scripts/`. La auditoría P4a verificó la cuenta `cervesur_escritura_adm@127.0.0.1`, estado no sensible, `v_user_alta` con `WITH CHECK OPTION`, rol `user/usuario`, DEFINER bloqueado, sin `UPDATE(email)` ni permisos excesivos y ambos triggers. No repetir migraciones SQL ni P3.

**Pendientes críticos reducidos:** comprobar con lectura local (sin publicar user_ids) que `usuarios_piloto` contiene exactamente **un** registro activo para el admin designado; autorización explícita y trazable de responsable funcional/DBA para excepción de alcance y responsabilidad de respaldo/rollback; cuenta QA y operación de auditoría posterior. La sentencia permitida `estado_ejecutar($pdoEstado,'lista_blanca_listar',[])` está disponible en PHP **CLI** del backend, ya que `db_usuarios.php` usa `estado_sentencias(PHP_SAPI === 'cli')`. No imprimir filas, IDs, contraseñas, secretos ni SQL. El script `interruptor-usuarios.sh encender` sigue prohibido sin esos checks y autorización real.

## 3c. Verificación real del administrador único (12/11/2026)

El operador ejecutó contra el backend de 8443, sin modificar producción:

```text
PHP_LINT_ADMIN_ESCRITURA=PASS
ADMIN_MODO=PASS
ADMIN_ALLOWLIST_ACTIVOS=1
ADMIN_UNICO=PASS
PILOTO_ACCESO=RESTRINGIDO_A_UNA_CUENTA
CAMBIOS_PRODUCTIVOS=NO
U_BASIC_ACTIVADO=NO
```

El listado de la base de estado confirmó **una sola fila activa**, pero **no se verificó en la salida su identidad, la titularidad o la inexistencia de otra vía de login**. Condición operativa: el custodio corrobora en privado que es el administrador designado y que el ingreso está limitado a VPN/red interna. El backend `admin_escritura` revalida la lista blanca en cada solicitud, pero `ADMIN_WRITES_USUARIOS=1` habilitará la capacidad a todos los administradores admitidos por la lista, no a un usuario específico del flag.

**GO técnico PREPARADO** para activación acotada: P3 instalado, P4a no sensibles PASS, un admin en allowlist, K1/D4/H1 y K2 aislado PASS históricos, rollback nativo del interruptor. **GO operativo PENDIENTE**: identidad admin designada y autorización comprobable del DBA y responsable funcional para la excepción temporal a UX-09B v2. Si ambos confirman y se conserva respaldo vigente, el operador puede ejecutar `interruptor-usuarios.sh encender --autorizacion "responsables/fecha/alcance"`, con las dos credenciales de verificación 8443 cargadas de forma no visible en el entorno, sin pegarlas en el chat, y el script debe revalidar HTTP403, P4a y capabilities, respaldando .env con reversión automática si falla. Después verificar `writes.usuarios=true`, `users_sensitive=false`, que solo hay un admin, y realizar una acción ficticia autorizada con un único registro en auditoría. El PR #32 sigue DRAFT.

## 3d. Activación técnica ejecutada — P4b piloto (12/11/2026)

Evidencia informada por operador después de ejecución en VPS (se omiten identificadores de acceso):

```text
PRE: ADMIN_UNICO=PASS (allowlist_activos=1)
PRE: RESULTADO-PRIVILEGIOS=PASS no-sensibles
PRE: ADMIN_WRITES_USUARIOS=<sin definir>
PRE: ADMIN_WRITES_USUARIOS_SENSIBLE=<sin definir>
SCRIPT: UX-08I interruptor de usuarios -- accion=encender
SCRIPT: backend 2B con usuarios bloqueados previamente (403 fase_2b_bloqueada): PASS
SCRIPT: empresas/reportes/accesos intactos: PASS
SCRIPT: privilegios no-sensibles verificados: PASS
SCRIPT: capacidades usuarios=true y users_sensitive=false: PASS
ACTIVACION_SCRIPT=PASS
POST: ADMIN_WRITES_USUARIOS=1
POST: ADMIN_WRITES_USUARIOS_SENSIBLE=<sin definir>
POST: ADMIN_USER_REACTIVATE_HOURS=<sin definir> (código: default 24 horas)
```

**Estado real actual:** U-BASIC activado técnicamente en portal 8443 para operaciones no sensibles; P5/roles/contraseñas OFF; otros módulos intactos. El script oficial hizo copia de `.env` y validó capacidades.

**Defecto documental pendiente, NO encubrir:** en el texto de `--autorizacion` el operador escribió literalmente los marcadores `DBA: [Nombre]` y `Funcional: [Nombre]`. El script solo verifica que la referencia tiene longitud mínima: **NO acredita que existan las firmas reales ni quiénes aprobaron**. Se debe complementar el expediente con referencia verificable de responsables y sus aprobaciones, SIN modificar o borrar la evidencia histórica. Hasta entonces, declarar `GO_TECNICO=PASS`, `GO_DOCUMENTAL=PENDIENTE`, `P4C_PRIMERA_ESCRITURA=RETENIDA`. Si las aprobaciones no existen, suspender el acceso a las funciones de edición mediante el interruptor `apagar`.

**Próximas operaciones:** confirmar identidad del único admin autorizado, adjuntar aprobación real DBA/funcional, comprobar en UI capacidades (usuarios habilitado, sensible apagado) sin alterar datos; después **una sola alta de cuenta QA estándar autorizada**, con correo controlado y contraseña privada, y comprobar exactamente una fila de `audit_log` `user.create`. Si falla o hay duplicados/no auditoría, apagar inmediatamente, conservar respaldo y diagnosticar antes de reintentar. Prohibido crear administradores, modificar roles/contraseñas o publicar datos/tokens.

La excepción temporal A1/A2/A3 no modifica las protecciones existentes y conserva P4b general NO-GO para apertura general. Se requiere cierre D3 y firmas para expandir acceso.

## 4. Condiciones imprescindibles antes de encender (breve preflight)

1. Confirmación nominativa del **responsable funcional y DBA** de que autorizan *esta excepción acotada*, la cuenta única autorizada, el alcance y el plan de retorno. No escribir `CERVESUR_CONFIRMAR_USUARIOS=SI` sin esa autorización real.
2. Acceso al 8443 restringido a red/VPN y lista blanca con **solo un admin** designado, comprobada por custodio. No abrir el 8443 a Internet ni a otros admins.
3. P2: respaldo vigente verificable y rollback de `.env` y cuenta; P3 código 2B, P4a `privilegios-usuarios.sh --etapa no-sensibles --verificar` PASS; D4 y K1 `retirar` PASS; el ensayo H1 9/9 y K2 JSON 4/4 ya PASARON. No repetir pruebas ya aprobadas.
4. Estado previo: `CERVESUR_MODE=admin_escritura`, `writes.usuarios=false`, `users_sensitive=false`, `audit=true`, `reactivate_after_hours=24` y otros módulos activos sin cambios. F10 sigue cerrado y se confirma que el alta **admin React** ofrece continuidad para las nuevas cuentas piloto; no desplegar puente de alta público.
5. El script oficial `interruptor-usuarios.sh encender` debe satisfacer **todos** sus propios checks (login, 403 previo, P4a, capacidades, backup y verificación). Prohibido eliminar o alterar validaciones para forzar encendido.
6. Una única acción de prueba con usuario estándar ficticio autorizado y **una sola** auditoría `user.create`; luego una edición autorizada `user.update` y comprobar auditoría (si el responsable lo permite). Cualquier anomalía → `interruptor-usuarios.sh apagar` y documentar. No asignar más privilegios, cambiar roles o contraseñas.

## 5. Go/No-Go y cierre posterior

- Si alguno de los puntos 1–5 falta, **NO-GO para el piloto**, aunque los controles de K2 y H1 estén en PASS. No se ejecuta `encender`.
- Si se satisfacen 1–5, se puede declarar **GO condicionado SOLO al piloto de admin único**, luego ejecutar **exclusivamente** el interruptor oficial, sin reinstalar código o hacer nuevos GRANT. El comando de activación se entrega cuando estén comprobados estos preflights y las aprobaciones.
- Después, A1/A2/A3, pruebas HTTP con roles y casos alternativos, firma completa A-L1..A-L4/A-ALTA siguen en backlog **bloqueante para la apertura general**, NO se consideran PASS y no autorizan P5.
- P4b general sigue NO-GO hasta cierre v2. Todos los usuarios estándar nuevos deben operar bajo las restricciones existentes del portal 443; su alta no los incorpora automáticamente a listas blancas del piloto.

## 6. Operación segura

Primero localizar paquete de scripts, comprobar `estado` y la etapa P4a `--verificar`; no usar `encender` en el preflight. Mantener credenciales de chequeo `CERVESUR_E_USUARIO`/`CERVESUR_E_CLAVE` solo en entorno privado y no imprimirlas. El interruptor tiene recuperación de `.env` ante capacidades inválidas, y `apagar` siempre queda disponible al operador. Registrar hora/operador, acta de autorización, backup exacto y resultado HTTP/capacidades sin tokens ni datos personales.
