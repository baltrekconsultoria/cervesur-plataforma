# D3-08: distinguir indicador falso de exposición del secreto Power BI

**Motivo:** el operador obtuvo ocho fuentes SHA-256 PASS y los indicadores D3-03/06/07 correctos, pero `D3_08_SECRET_UNSET=REVISAR` y `D3_08_SECRET_READ_PATHS=REVISAR`. Este resultado no demuestra fuga de `powerbi_clientsecret`: el analizador anterior buscaba exclusivamente una función `withoutSecrets` y un `unset` sobre una variable concreta. Una consulta que ni selecciona el secreto puede ser igualmente segura sin `unset`. La fuente de producción tiene un SHA distinto al código genérico de `main`; **no adjudicarle comportamiento de otra rama**.

## Control seguro para diagnosticar

`ops/u-basic/d3_secret_read_triage.py` es solo lectura, stdlib Python y SHA-pinned a `models/EmpresaModel.php` y `controllers/EmpresaController.php` del inventario 8/8. **No imprime código, valores, claves, credenciales, datos ni respuestas**; no hace HTTP ni abre MySQL. Clasifica por cada método de consulta (`getAll`, `getById`, `search`, `getByCity`):

- `INDICIO_SELECCION_EXCLUYE_SECRETO`: la proyección SELECT observada no contiene el campo sensible ni un comodín. Es un indicio estático, no una prueba de todos los caminos de ejecución.
- `INDICIO_FILTRADO_VERIFICADO`: existe un `unset` del campo y el método parece invocar el filtro. Es indicativo, no un PASS dinámico.
- `REVISAR_SELECCION_SECRETO`: aparece el campo seleccionado sin filtro reconocido; **posible exposición, requiere análisis experto** antes de P4b.
- `REVISAR_PROYECCION_DINAMICA`: `SELECT *` o proyección construida dinámicamente; no puede determinarse en estático.
- `REVISAR_METODO_AUSENTE`, `REVISAR_SQL_NO_IDENTIFICADO`, `REVISAR_MEZCLA_OPERACIONES`: no inferir seguridad; inspección adicional en copia aislada.

No hace inferencia sobre métodos desconocidos ni sobre cambios de secretos por POST/PUT (K2). La ausencia de un filtro no se reporta automáticamente como vulnerabilidad; la selección explícita del secreto sí impide declarar cierre.

## Uso (después de CI PASS y descarga SHA verificada)

```bash
sudo python3 /opt/cervesur-u-basic-kit/d3_secret_read_triage.py \
  --backend /var/www/cervesur/backend
```

Entregar únicamente las líneas `D3_08_*`, sin transcribir fragmentos PHP. Si aparece `REVISAR_*`, no cambiar producción ni dar D3-08 por aprobado. El estudio estático nunca cambia `D3_GLOBAL=PENDIENTE`, ni autoriza U-BASIC.

## Criterio de cierre D3-08

Conseguir evidencia dinámica en una réplica fiel **aislada** con datos sintéticos: GET de empresa autorizado, listados, búsquedas y GET ajeno; verificar que ninguna respuesta incluya el secreto (ni como campo nulo con valor sensible, ni como valor derivado). Separadamente verificar K2: POST/PUT no ofrecen un camino alternativo para leer o sobrescribir credenciales. Se requieren permisos formales, registros de ejecución y firmas A-L1 correspondientes. P4b y sensible siguen OFF.
