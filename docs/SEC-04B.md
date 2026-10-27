# SEC-04B: JWT estricto y transición de sesiones

## Cambios locales

La validación acepta únicamente JWT con encabezado `typ=JWT` y `alg=HS256`, firma válida y campos completos: `user_id` positivo, `username`, `email`, `session_version` entero positivo y `exp` futuro. Las cuentas inactivas no pueden iniciar una sesión nueva.

El middleware obtiene el rol y el estado actual del usuario desde la base de datos en cada petición. Un cambio de rol tiene efecto inmediato y logout incrementa `session_version`, invalidando los tokens emitidos con la versión anterior.

## Impacto de compatibilidad

Los JWT heredados que no contienen `session_version` o `exp` se rechazan. Esto obliga a iniciar sesión otra vez cuando el cambio llegue a producción. El frontend Flutter debe tratar `401` como una sesión inválida: eliminar el token almacenado y llevar al usuario a login.

## Requisitos previos para producción

1. Ejecutar una consulta autorizada de solo lectura para confirmar que `user.session_version` existe, no contiene valores nulos y es mayor o igual que uno.
2. Confirmar la duración real de los JWT actuales y anunciar la reautenticación.
3. Validar el flujo de `401` en el código Flutter original o mediante una prueba controlada del frontend compilado.
4. Comprobar en un entorno de ensayo que login, logout, cambio de rol, usuario inactivo y acceso Power BI simulado mantienen los contratos esperados.

## Despliegue y reversión propuestos

Publicar primero la rama tras revisión y ejecutar CI. Durante el despliegue, conservar una copia de la versión anterior de la aplicación y monitorizar respuestas `401` y errores de login. Revertir código restaura la aceptación anterior, pero no restaura tokens ya rechazados por el cliente; la recuperación segura consiste en volver a iniciar sesión.
