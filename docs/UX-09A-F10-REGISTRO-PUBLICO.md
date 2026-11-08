# UX-09A · F-10: cerrar el registro público del backend heredado

> **Decisión definitiva: opción 1.** F-10 se despliega junto con el Lote 4, **condicionado** a que exista un procedimiento temporal seguro de altas aprobado por el DBA y el responsable funcional (`UX-09A-PROCEDIMIENTO-ALTA-TEMPORAL.md`, generador probado 23/23). **No se cierra el registro público sin esa alternativa.** Si C-5 identifica consumidores legítimos de `/api/register`, se evalúa su continuidad antes del cierre.

Estado: **propuesta, parche y pruebas locales. No desplegado.** Decisión recibida: cerrar el registro público junto con el Lote 4, evaluando si puede adelantarse como correctivo independiente de bajo riesgo.

## 1. Verificación de consumidores de `POST /api/register`

| Consumidor | Evidencia | Nivel |
|---|---|---|
| Pantalla «Crear Cuenta» (inicio de sesión) del Flutter desplegado | `RegisterCall` con seis variables; cadenas «Crear Cuenta» / «Crear Nueva Cuenta» | [R] paquete (idéntico a producción, C-4) |
| Pantalla de administración «Crear Usuario» / «Crear nuevo Usuario» | La misma llamada, con dos puntos de uso | [R] llamada, [I] pantallas |
| React (todas las modalidades) | No usa `/api/register`; el alta de React es la vista `v_user_alta` (2B) | [R] repositorio |
| Otros (scripts, integraciones) | Sin evidencia | [P]: `c5-solo-lectura.sh` cuenta las llamadas por mes, origen y cliente |

**Hallazgo clave:** `RegisterCall` **no envía el token de sesión** (variables: `firstName`, `lastName`, `username`, `email`, `password`, `phone`). Por lo tanto el Flutter desplegado no puede crear usuarios autenticado como administrador. Cualquier alternativa «solo administradores» (como hizo SEC-05A en el backend del repositorio) **rompe también el alta de administrador en Flutter**, salvo que la función intermedia inyecte una credencial, algo que no se ha revisado.
**Consecuencia:** cerrar el registro significa que **nadie puede crear cuentas desde Flutter**, ni público ni administrador, hasta que el alta de React (P4b/P4c) esté operativa.

## 2. ¿Se puede adelantar como correctivo independiente de bajo riesgo?

| Dimensión | Evaluación |
|---|---|
| Técnica | **Sí, bajo riesgo.** Siete líneas dentro de la ruta, falla cerrado (si la constante no existe, queda cerrado), reversión por restauración del archivo o por la constante `REGISTRO_ABIERTO = true`. Probado en local |
| Funcional | **No es independiente del alta de React.** Detiene toda creación de cuentas en Flutter. Si hay altas pendientes o frecuentes, adelantarlo sin alternativa tiene un coste operativo |
| De seguridad | Cierra A3 (cuentas sin administrador). La exposición actual es real: cualquiera con acceso a la API crea cuentas |
| Dependencias | Inspección C-5 (cuántas altas por mes y cuántas por la API) |

## 3. Orden propuesto (a decidir)

La decisión «cerrar junto con el Lote 4» choca con que React aún no puede crear usuarios en producción (P4b). Tres formas de cumplirla, de menor a mayor impacto operativo:

| Opción | Cuándo se despliega F-10 | Efecto |
|---|---|---|
| **1 (recomendada)** | En la ventana del Lote 4 **si** C-5 muestra pocas altas (p. ej. ≤ 1 al mes) y no hay altas pendientes; el alta se cubre por el DBA con el procedimiento de emergencia hasta P4c | Cumple la decisión; el hueco es de semanas y controlado |
| 2 | En la ventana siguiente a P4c (alta de React verificada) | No hay hueco, pero A3 queda abierto hasta entonces |
| 3 | En la ventana del Lote 4 sin alternativa | Cumple la decisión literal; el alta de usuarios queda pausada hasta P4c |

Mientras exista el hueco, el procedimiento de emergencia es una intervención del DBA con autorización escrita (no forma parte de este paquete).

## 4. Parche y pruebas

- **Parche:** `deploy/flutter-heredado/lote4-a2/F-10-registro-cerrado.diff`. Base: `router/auth.php` de producción (`a4f063a53db4…`, texto literal, líneas 55 a 134 impresas; el parche usa solo las líneas 63 y 64, ambas sin enmascarar). Se verifica en la copia con `patch --dry-run -F0` (cero desplazamiento).
- **Qué hace:** `POST /api/register` responde `403 {"success":false,"message":"El registro de usuarios esta deshabilitado. Solicite el alta a un administrador."}` antes de leer el cuerpo y antes de instanciar el controlador. El cliente de Flutter lo muestra como «Observación» con ese texto.
- **No toca:** inicio de sesión, cierre de sesión ni ninguna otra ruta.

Pruebas (réplica sintética; el esqueleto de `auth.php` usa las líneas reales 55 a 134 y un inicio de sesión de prueba para las líneas no impresas):

| Caso | Resultado |
|---|---|
| Producción simulada: sin sesión y con usuario normal crean cuentas (A3, rojo) | Reproducido |
| Con el parche: sin sesión ni cabecera, usuario normal, administrador y token inválido | 403 y **no se crea ninguna cuenta** |
| Cuerpo no JSON y campos faltantes | 403 (se cierra antes de validar) |
| `POST /api/login`, `POST /api/logout`, `GET /api/register` | Respuesta idéntica a la de antes del parche |
| Reversión por `REGISTRO_ABIERTO = true` | Vuelve a registrar |
| Mutaciones (guardia invertida, 200 en vez de 403) | Detectadas |

Limitación: las líneas 1 a 54 de `auth.php` (inicio de sesión) no se imprimieron; su comportamiento se comprueba con la huella y con el humo de la ventana.

## 5. Verificación en producción sin crear cuentas

Después de aplicar: `POST /api/register` con un cuerpo vacío. Esperado **403** (si el parche no estuviera activo respondería 400 «Body JSON inválido» y tampoco crearía nada). Inicio de sesión de una cuenta de prueba: debe seguir funcionando.

## 6. Reversión

Restaurar `auth.php` desde la copia (`sha256sum = a4f063a53db4…`). Menos de 5 minutos.

## 7. Condiciones para incluir F-10 en la ventana del Lote 4 (todas)

| # | Condición | Estado |
|---|---|---|
| 1 | Procedimiento temporal de altas aprobado y firmado por el DBA y el funcional, **con el DBA como único ejecutor SQL** y la **aceptación explícita del riesgo R-PWD** (contraseña inicial que el usuario no puede cambiar mientras P5 siga bloqueado) | PENDIENTE |
| 2 | Ensayo del DBA con el generador y su SQL | PENDIENTE |
| 3 | Personas nombradas: solicitantes autorizados, autorizador, **custodio sin permisos de base de datos** (confirmado por el DBA), DBA ejecutor, **verificador funcional distinto del ejecutor**; canal de entrega que cumpla los controles C1 a C8; tiempo de atención acordado | PENDIENTE |
| 4 | C-5 ejecutado: altas por mes y consumidores de `/api/register` conocidos | PENDIENTE |
| 5 | Si hay consumidores legítimos: decisión escrita sobre su continuidad (atenderlos por el procedimiento, o aplazar F-10) | PENDIENTE (depende de 4) |
| 6 | Lote 4 mínimo (F-4, F-5a a F-5c) listo para la misma ventana | Preparado |
| 7 | Fecha de caducidad del procedimiento fijada (P4c + 5 días hábiles; tope 60 días), dejando claro que **las contraseñas emitidas no caducan con él** | Propuesta |
| 8 | **No se crea ninguna cuenta nueva con escritura en la base** para el procedimiento | Aprobado |
| 9 | Decisiones D-PWD1 (reemisión excepcional) y D-PWD2 (diseño del cambio de contraseña propio tras P4c) tomadas, aunque sea para aplazarlas | PENDIENTE |

**Si cualquiera falla en la fecha de la ventana, F-10 sale de esa ventana y el Lote 4 mínimo sigue.** El resto del Lote 4 no depende de F-10.
