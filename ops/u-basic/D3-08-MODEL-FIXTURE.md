# Cervesur D3-08 — QA funcional de EmpresaModel con dato ficticio y sin DB

**Fecha:** 12/11/2026. **Estado:** kit en PR #32, pendiente de ejecución en VPS. Ningún cambio en producción.

## Antecedente y criterio

El operador obtuvo `D3_08_ORIGEN_GETALL/GETBYID/SEARCH/GETBYCITY=REVISAR_SELECT_STAR` con dos fuentes del legado idénticas a las huellas. Antes `D3_08_SANEAMIENTO_UNSET=NO_DETECTADO` y `D3_08_SANEAMIENTO_METODO=NO_DETECTADO`. Esto demuestra **que los analizadores no pueden certificar exclusión** del campo sensible, NO una filtración confirmada. El Modelo en GitHub no tiene el SHA del desplegado; no sirve como sustituto.

## Diseño del ensayo

Archivos `ops/u-basic/d3_empresa_sandbox.py` + `ops/u-basic/d3_empresa_fixture.php`:

1. El orquestador verifica SHA-256 exactos de `models/EmpresaModel.php` y `controllers/EmpresaController.php` **antes de copiarlos**. Si hay drift, aborta.
2. Copia solo el modelo real a un directorio temporal privado; escribe **stubs sintéticos** de `pagination.php`, `status_filter.php`, `mode.php` y `write_support.php`. No importa `db.php`, ningún archivo `.env` ni el controlador. No abre socket, DB ni API.
3. Invoca PHP CLI mediante un proceso secundario con **variables de entorno vaciadas**, `open_basedir` limitado a la carpeta temporal, `allow_url_fopen=0`, varias funciones de red y ejecución bloqueadas y, si se invoca el orquestador con `sudo`, el proceso PHP corre como **nobody**.
4. `D3FakePDO` extiende PDO pero **no llama a su constructor**: ninguna conexión/DSN. Acepta exclusivamente proyecciones SELECT del fixture y devuelve una fila ficticia con marcador `powerbi_clientsecret`. Las operaciones de escritura se rechazan. `paginated_rows` también se sustituye por una rutina sin MySQL.
5. Invoca `getAll`, `getById`, `search`, `getByCity` contra el modelo copiado. Cada método debe producir una fila ficticia; si el SQL, parámetro o dependencia no puede modelarse, informa **INCONCLUSO** sin conceder PASS.
6. La salida compara solo **presencia de clave o marcador secreto en la respuesta del modelo**, sin mostrar datos, SQL, modelo, secretos, tokens ni valores de la fila.

## Resultado y su interpretación

| Etiqueta | Interpretación |
| --- | --- |
| `D3_08_SELFTEST=PASS` | El laboratorio diferencia SELECT explícito seguro vs SELECT estrella con marcador sensible |
| `D3_08_REAL_SHA=PASS_2_DE_2` | El modelo y controlador reales coinciden con las fuentes atestadas |
| `D3_08_FIXTURE_GETALL=SIN_SECRETO_EN_FIXTURE` (etc.) | En esta simulación el método no devolvió el marcador: PASS acotado de **un escenario** |
| `D3_08_FIXTURE_* =SECRETO_EN_RESPUESTA_SINTETICA` | Se reproduce una posible fuga **con dato ficticio**: bloquear P4b; preparar correctivo K2 antes de habilitar |
| `D3_08_FIXTURE_* =INCONCLUSO_*` | El simulador no pudo ejecutar el flujo; no hay conclusión, no cambiar fuente ni falsificar PASS |
| `D3_08_FIXTURE_GLOBAL=PASS_ACOTADO_DATOS_SINTETICOS` | Solo pasa el modelo con estos cuatro caminos y con PDO ficticio, NO el endpoint HTTP real |
| `D3_08_FIXTURE_GLOBAL=NO_GO_EXPOSICION_SINTETICA` | Bloqueo de P4b hasta análisis, corrección y QA |
| `D3_08_FIXTURE_GLOBAL=PENDIENTE_COMPATIBILIDAD` | Revisar adaptación de laboratorio sin modificar backend productivo |

## Alcance pendiente antes de GO

El ensayo **no acredita** el resto de D3 (HTTP/roles A1/A2/A3, K2 escritura y otras rutas), ni firmas A-L1..A-L4/A-ALTA, ni continuidad del alta Flutter, ni P4b. Incluso con 4/4 `SIN_SECRETO_EN_FIXTURE` hay que verificar rutas y el comportamiento del controlador/serializador en una réplica fiel, con auditoría y autorizaciones. **U-BASIC y P5 permanecen OFF.**

El kit no debe instalarse sobre el backend desplegado: se coloca solo en `/opt/cervesur-u-basic-kit/` y las copias temporales son eliminadas después. Si falla cualquier SHA o entorno, abortar sin instalar paquetes.
