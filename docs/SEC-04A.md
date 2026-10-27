# SEC-04A: seguridad de perfiles y relaciones

## Regla aplicada

- Un usuario normal solo puede consultar o actualizar su propio perfil por ID.
- Un administrador conserva la lectura operativa de perfiles y la gestión de asignaciones.
- Las relaciones empresa-usuario se limitan al propio usuario salvo para administradores.
- Las relaciones usuario-empresa-reporte requieren que estén activas y que exista la relación activa correspondiente entre usuario y empresa.
- Las asignaciones empresa-reporte solo se muestran a un usuario normal cuando existe una asignación activa usuario-empresa-reporte y una relación activa empresa-usuario para esa empresa.

## Compatibilidad

Las rutas y estructuras de respuesta existentes se mantienen. En particular, el listado histórico de `/api/asignacion` conserva el resultado del modelo anidado en `data`; las pruebas verifican ese contrato.

## Validación aislada

La cobertura HTTP usa usuarios, empresas, reportes y credenciales sintéticos en una red Docker interna, sin puertos publicados. Cubre perfiles propios y ajenos, relaciones propias y ajenas, relaciones inactivas y lecturas administrativas.

## Riesgos residuales

- La política definitiva sobre el alcance administrativo sigue siendo provisional.
- El endurecimiento adicional de JWT y el reemplazo del token Power BI amplio pertenecen a fases posteriores.
- La comparación con la referencia original saneada continúa pendiente.
