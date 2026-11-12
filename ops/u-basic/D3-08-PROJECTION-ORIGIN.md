# Cervesur D3-08 — Origen de proyecciones dinámicas en EmpresaModel

**Motivo (12/11/2026):** el operador comprobó integridad SHA de modelo/controlador (2/2), pero los cuatro métodos de lectura devolvieron `REVISAR_PROYECCION_DINAMICA`. No se encontró filtro `withoutSecrets` ni `unset`. Esto **no demuestra fuga**, pero tampoco descarta que una proyección dinámica incluya `powerbi_clientsecret`.

La versión de `EmpresaModel` en GitHub utiliza un helper `powerbiColumns()` que construye parte de la lista de columnas; **ese código no tiene el mismo SHA que producción**, por lo que no se puede extrapolar. El script nuevo identifica **sin imprimir código fuente** si la lista de columnas se forma con un helper y si sus retornos son literales que no mencionan el secreto.

## Qué comprueba `d3_projection_origin.py`

- Verifica exactamente las dos huellas SHA-256 previamente atestadas de `EmpresaModel.php` y `EmpresaController.php`. Aborta si hay drift.
- Inspecciona la proyección de `SELECT ... FROM empresa` en `getAll`, `getById`, `search` y `getByCity`.
- Marca expresamente `REVISAR_SECRET_EN_PROYECCION`, `REVISAR_SELECT_STAR`, `REVISAR_MULTIPLES_HELPERS` o `REVISAR_HELPER_DINAMICO` si no puede demostrar un indicio favorable.
- Señala `INDICIO_HELPER_LITERALES_SIN_SECRETO` si el helper observado retorna expresiones literales sin el campo; **sigue siendo solo un indicio estático**, no prueba de ejecución ni garantía sobre otros métodos.
- Nunca consulta valores MySQL, no ejecuta PHP y no abre HTTP. No imprime SQL, secretos, credenciales ni datos del usuario.

## Para el operador

Descargar desde un **commit fijo**, comparar blob SHA antes de instalar y ejecutar:

```bash
sudo python3 /opt/cervesur-u-basic-kit/d3_projection_origin.py \
  --backend /var/www/cervesur/backend
```

Compartir únicamente `D3_08_ORIGEN_*` y `D3_08_POWERBI_COLUMNS`, sin enviar el contenido del PHP.

## Decisión D3/P4b

Aunque el resultado indique ausencia literal del secreto, **D3-08 no se cierra** hasta probar en copia fiel aislada (sin acceso a producción) las respuestas GET y verificar que no aparece el secreto o su valor. En paralelo deben completarse D3-05, perfil ajeno, rutas alias y controles K2/A1/A2/A3; se mantienen firmas/continuidad del alta como condiciones de P4b. U-BASIC y P5 siguen OFF. No ejecutar GRANT, patch, `.env` ni comandos de activación por un indicador estático.
