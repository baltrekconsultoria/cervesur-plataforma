-- UX-08B3 · Reversión: `reporte.grupo_id` vuelve a VARCHAR(50) NOT NULL.
-- NO SE APLICA AUTOMÁTICAMENTE: requiere autorización expresa y copia de seguridad previa.
--
-- SE NIEGA A EJECUTARSE (y no cambia nada) si algún informe tiene grupo_id NULL: nunca inventa valores ni borra informes.
-- Para revertir, primero hay que resolver esos informes (asignarles un grupo real o darlos de baja/eliminarlos por el
-- procedimiento autorizado) y volver a ejecutar este archivo.
--
-- Funcionamiento de la guarda (SQL plano, sentencia por sentencia; vale para el cliente mysql SIN --force y para PDO):
--  1) El SELECT provoca el error 1242 «Subquery returns more than 1 row» solo si existen filas con grupo_id NULL
--     (IF evalúa la subconsulta únicamente en esa rama). El cliente se detiene y el ALTER no llega a ejecutarse.
--  2) Además, el modo SQL estricto de sesión hace que, aun ante una carrera (fila insertada entre 1 y 3), el ALTER falle
--     con «Invalid use of NULL value» en lugar de convertir los NULL en cadenas vacías.
SELECT IF(COUNT(*) = 0, 1, (SELECT 1 UNION ALL SELECT 2)) AS guarda_sin_grupo_id_null FROM reporte WHERE grupo_id IS NULL;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES');
ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NOT NULL;
