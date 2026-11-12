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
