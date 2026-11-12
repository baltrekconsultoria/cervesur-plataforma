# Cervesur – inventario controlado de los 9 fallbacks JWT pendientes

El informe D4 v2 aplicado en producción confirmó:

- D4_CRITICOS=PASS, D4-F10-SHA=PASS, D4-H1-SHA=PASS, D4-08 y D4-08b PASS (410), D4-07=PASS_JWT_86400.
- D4-06=PASS_SECRET_DISTINTO_DE_LITERALES; D4-06b=PENDIENTE_LITERAL_POR_DEFECTO_CANTIDAD_9.
- JWT_FALLBACKS_REMOVIDOS=NO; QA_HTTP_USER_ADMIN=PENDIENTE; U-BASIC OFF.

D4-06 PASS significa que el secreto configurado difiere de las constantes de respaldo; NO significa que los nueve puntos hayan desaparecido o sean seguros cuando falte JWT_SECRET.

## Por qué este inventario

El proyecto Cervesur conserva una versión desplegada del backend PHP que no coincide necesariamente con los archivos homónimos de main o de la rama histórica UX-09A. No corresponde reemplazar routers completos solo para cambiar literales de seguridad: podrían perderse F10/K2/A1/A2 ya instalados.

El comando read-only inventory_jwt_fallbacks.py identifica exactamente las nueve ubicaciones encontradas por el patrón D4-06b dentro de router/, controllers/, lib/, y muestra exclusivamente:

- ruta relativa del archivo PHP;
- número de línea y nombre de función cercana, si existe;
- nombre de variable destino cuando es una asignación sencilla;
- SHA-256 completo del archivo original.

No imprime el valor del fallback, fragmentos de PHP, tokens, contraseñas, SQL ni .env. No modifica archivos, Apache, MySQL, usuarios, flags ni código productivo; devuelve NO-GO si el conteo no coincide con 9.

## Uso PuTTY

Después de pasar la CI y fusionar el PR, obtener el archivo desde el commit inmutable, comparar Git blob SHA y copiarlo root-only fuera de /var/www. Ejecutar:

    sudo python3 /opt/cervesur-u-basic-kit/inventory_jwt_fallbacks.py

El informe resultante sí se puede compartir (no incluye valores de secretos). NO compartir source PHP completo, .env ni respaldos.

## Siguiente incremento técnico

Con la lista de 9 archivos/líneas/SHA productivos, cotejar con las versiones ya disponibles en GitHub y desarrollar parches diferenciados fail-closed: al faltar JWT_SECRET debe fallar la operación protegida, nunca usar un secreto público. Revisar requisitos PHP y centralización en H1 sin cambiar API/roles. Implementar tests de regresión por ruta y SHA pin del código existente, backup individual previo y rollback por SHA. Se requiere autorización para deploy.

En paralelo, preparar QA HTTP role-specific de F10, A1 y A2, evitando exponer tokens o contraseñas. No activar U-BASIC hasta cerrar ese QA, D3 y aprobaciones funcional/DBA. P2 respaldo/restauración PASS, privilegios no-sensibles PASS, se reutilizan.
