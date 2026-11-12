# D3-08 — QA del JSON final del router con datos sintéticos (sin HTTP de red)

**Fecha:** 12/11/2026 · **Estado:** ensayo preparado, no ejecutado sobre copia productiva en VPS.

## Evidencia previa que se conserva

El operador reportó `K2_SHA=PASS`, cuatro referencias `K2_ROUTER_GETALL/GETBYID/SEARCH/GETBYCITY=REFERENCIA_K2_DETECTADA` y `K2_QA_SINTETICO=PASS_4_DE_4`. La prueba anterior del modelo, sin sanitizador, mostraba el marcador `powerbi_clientsecret` en los cuatro métodos. **Esto no confirma exposición HTTP real, ni permite concluir que la respuesta completa está protegida.**

## Objetivo nuevo

Usar `ops/u-basic/d3_k2_router_sandbox.py` + `ops/u-basic/d3_k2_router_fixture.php` para copiar y ejecutar de forma aislada:
- `router/EmpresaRouter.php` exactamente como está instalado (snapshot **sin SHA dorado previo**; se prueba el contenido copiado, no se da por atestado históricamente);
- `controllers/EmpresaController.php`, `models/EmpresaModel.php`, `lib/empresa_sanitizer.php` con **SHA-256 exacto** verificado antes de copiarlos;
- stubs sintéticos solo para `views.php`, `pagination.php`, `status_filter.php`, `auth_middleware.php`, `mode.php`, `write_support.php`.

El JWT/usuario es simulado (`admin`), el PDO es una subclase sin constructor/DSN y entrega únicamente una empresa ficticia, incluida la columna `powerbi_clientsecret` con un marcador no real. `apply_view()` en el stub **no elimina columnas**, para que el ensayo detecte un fallo de K2 si el router lo omite. **No** se abre una petición de red, no se inicia Apache ni se importa `db.php` o `.env`.

El proceso PHP corre en un directorio temporal privado con `open_basedir` restringido, funciones de red y proceso deshabilitadas, entorno limpio y usuario `nobody` cuando el orquestador es root. Este confinamiento reduce la superficie pero **no es una máquina virtual ni demuestra aislamiento de red del kernel**. El script no registra ni imprime la respuesta original, mensajes de excepción, tokens, SQL ni valores: solo estados de lista cerrada.

## Comprobación e interpretación

```text
D3_K2_ROUTER_FUENTES=PASS_3_PINNED_Y_ROUTER_COPIADO
D3_K2_ROUTER_GETALL=PASS_JSON_SIN_SECRETO_SINTETICO
D3_K2_ROUTER_GETBYID=PASS_JSON_SIN_SECRETO_SINTETICO
D3_K2_ROUTER_SEARCH=PASS_JSON_SIN_SECRETO_SINTETICO
D3_K2_ROUTER_GETBYCITY=PASS_JSON_SIN_SECRETO_SINTETICO
D3_K2_ROUTER_GLOBAL=PASS_ACOTADO_JSON_4_DE_4
D3_K2_AUTH=SIMULADA_NO_ES_PRUEBA_DE_ROLE_403
D3_K2_HTTP_REAL=NO_PROBADO
U_BASIC_ACTIVADO=NO
```

- `PASS_ACOTADO_JSON_4_DE_4`: el JSON generado por la copia de router/controlador/modelo/K2 no devuelve el marcador. **No** acredita JWT real, roles distintos, HTTP real o ausencia de rutas alternativas.
- `NO_GO_EXPOSICION_SINTETICA`: la ruta simulada propagó secreto ficticio; detener GO y preparar corrección exacta.
- `PENDIENTE_COMPATIBILIDAD`: el simulador no puede ejecutar una o más rutas completas (e.g. SQL no soportado, import incompatible); no cambiar producción ni dar PASS.
- CI `--self-test` verifica el orquestador con un router falso benigno y otro deliberadamente contaminado; **no prueba el router instalado**. Una ejecución de producción/sistema requiere la respuesta del operador.

## Gate P4b

**Sigue NO-GO** incluso con cuatro resultados PASS hasta cerrar D3-03/05/06/07, A1/A2/A3, K2 POST/PUT y todos los roles en copia aislada, más firmas A-L1..A-L4/A-ALTA y continuidad del alta Flutter. `writes.usuarios=false` y `users_sensitive=false`; PR #32 en borrador sin merge ni despliegue.
