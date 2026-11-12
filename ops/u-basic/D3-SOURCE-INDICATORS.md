# D3 — Indicadores estructurales sobre 8 fuentes reales, sin copiar código

**Propósito:** continuar D3-03/06/07/08 con evidencia adicional tras `D3_AUTH_CRITICOS=PASS_9_DE_9` de H1. Este es un **diagnóstico estático preliminar**; incluso cuando todas las señales son `INDICIO_OK`, **no** significa prueba de no elusión ni GO de P4b.

## Seguridad y límites

- Script: `ops/u-basic/d3_source_indicators.py`; Python 3 stdlib únicamente. Lee los ocho PHP reales ya inventariados, **sin imprimir sus contenidos**, variables, consultas, tokens ni secretos. No abre red, DB, archivos de configuración, ni modifica nada.
- Exige los ocho SHA-256 atestados por el operador. Si un archivo difiere, el script devuelve `D3_ESTRUCTURA=NO_GO_FUENTE_CAMBIADA_O_INACCESIBLE` y no intenta inferir semántica.
- Busca indicadores estructurales (no ejecuta rutas):
  - **D3-03:** método desde REQUEST_METHOD, ruta desde REQUEST_URI parseada, ausencia de override en el index real.
  - **D3-06:** allowlist de `updateProfile` en el `UserModel` real; si `email` está permitido emite `SI_REVISAR_POLITICA` sin considerarlo falla automática.
  - **D3-07:** importación de routers, guard de StatsRouter y rama GET de wiki.
  - **D3-08:** eliminación de `powerbi_clientsecret` en el modelo y en los cuatro métodos de lectura, incluido el uso por callback; controladora ligada al modelo.
- **D3-05** no se evalúa dinámicamente ni se infieren respuestas de controladores/reportes/asociaciones.
- Si un indicador da `REVISAR`, detener la promoción de ese caso a PASS y revisar localmente sin compartir el código ni documentos sensibles.

## Procedimiento para VPS (solo lectura)

La descarga debe realizarse desde un **commit inmutable** y verificarse con blob SHA calculado en GitHub (consultar la instrucción del PR). Luego:

```bash
sudo python3 /opt/cervesur-u-basic-kit/d3_source_indicators.py --backend /var/www/cervesur/backend
```

Salida esperada:
```text
D3_FUENTES_SHA=PASS_8_DE_8
D3_03_METHOD_FROM_SERVER=INDICIO_OK
D3_03_PATH_FROM_URI=INDICIO_OK
D3_03_NO_OVERRIDE_IN_ENTRY=INDICIO_OK
D3_07_ROUTER_IMPORTS=INDICIO_OK
D3_07_STATS_AUTH_GUARD=INDICIO_OK
D3_07_WIKI_GET_BRANCH=INDICIO_OK
D3_06_USER_PROFILE_ALLOWLIST=INDICIO_OK
D3_06_EMAIL_EDITABLE=SI_REVISAR_POLITICA
D3_08_SECRET_UNSET=INDICIO_OK
D3_08_SECRET_READ_PATHS=INDICIO_OK
D3_08_EMPRESA_CONTROLLER_MODEL=INDICIO_OK
D3_ANALISIS=ESTATICO_INDICATIVO_SIN_EJECUTAR_RUTAS
D3_05_CONTROLLER_RUNTIME=PENDIENTE
D3_06_PROFILE_RUNTIME=PENDIENTE
D3_07_ALIAS_RUNTIME=PENDIENTE
D3_08_SECRET_RUNTIME=PENDIENTE
D3_GLOBAL=PENDIENTE_PRUEBAS_DINAMICAS_Y_FIRMAS
```

**No forzar PASS:** la VPS puede tener implementaciones y nombres distintos de GitHub. Si el indicador es `REVISAR`, eso significa análisis manual requerido, no un resultado de ataque; nunca se hacen cambios productivos por este script.

## Regla para P4b

Después del resultado, completar los escenarios negativos/autorizados A1/A2/A3 y K2 sobre una copia fiel aislada con datos sintéticos, sin llamar a rutas POST/PUT/DELETE del servidor en vivo. Confirmar firmas A-L1..A-L4 / A-ALTA y continuidad de registro tras F10. PR #32 permanece DRAFT, U-BASIC y sensibles OFF.
