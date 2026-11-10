# QA HTTP sintetico K1/F-10 v2 — evidencia y limites

**Proyecto:** Cervesur, endurecimiento de backend PHP heredado  
**Fecha:** 2026-11-10  
**PR:** #13 — borrador (NO-GO para fusion/despliegue)  
**Estado:** QA HTTP sintetico PASS; regresion HTTP real del backend heredado pendiente.

## 1. Artefactos versionados y huellas

| Archivo | SHA-256 verificado |
| --- | --- |
| `patches/K1-v2-cierre-incondicional.diff` | `4564cc38cf4cb94c5a79347c2b27f2ebc839158ec204e58c76ec5ce0480d8233` |
| `patches/F10-v2-cierre-incondicional.diff` | `1c363ecb1ec8baac31ef7513ac17da290407506fe8cef4ab5a0150268cee9f62` |
| `lib/powerbi_authz.php` | `4137cff3cab2d2390dbd82e73500e37d432e9f259b1f0931da8f163d5350e3a1` |

Ruta de paquete: `deploy/flutter-heredado/k1-f10-v2-qa/`.

## 2. Evidencias recibidas del operador

- PHP lint sobre copias QA: **3/3 PASS**.
- CLI funcional aislado: **11/11 PASS**; K1 410 en cinco modos, F-10 403 en tres configuraciones y comprobaciones de no intercepcion de login/logout.
- SHA-256, aplicacion exacta `patch --dry-run -F0`, reproduccion bit a bit desde copias originales y regresion estructural: **PASS**.
- Paquete local tar.gz publicado como evidencia del operador (no subido a GitHub), SHA-256 `8538cb3fce686e845d7eabfadda58bd28e50679026ef51ab95b4f6106bc90143`.

## 3. QA HTTP aislado reproducido por asistente

**Herramienta:** `python3 tests/isolated/test_k1_f10_v2_http.py`  
**Entorno de ejecucion:** PHP CLI 8.4.24, Python 3.13.5, GNU patch 2.8, localhost `127.0.0.1`.  
**Resultado:** 8/8 metodos `unittest`, 0 errores, 0 fallos. Dentro de los metodos hay multiples casos/subtests.

Comprobaciones:
1. Hashes exactos de los tres artefactos y coherencia del archivo `SHA256SUMS`.
2. Aplicacion de ambos diffs sobre routers PHP **sinteticos** con contexto concordante; `--dry-run` y aplicacion `-F0` sin fuzz.
3. `php -l` de los tres archivos reconstruidos.
4. HTTP real sobre servidor PHP `php -S` restringido a `127.0.0.1`: K1 devuelve 410 para IDs 0 y 123 bajo modos sin definir, retirar, observar, mixto, exigir y desconocido.
5. K1 devuelve 410 aun con cabecera Authorization; respuesta JSON sin `token` ni `access_token`.
6. F-10 devuelve 403 con `REGISTRO_ABIERTO` sin definir, true o false.
7. Rutas *ficticias* de login/logout no interceptadas por la guardia; `GET /api/register` sigue el fallback 404 de la fixture.

El test esta guardado en GitHub con Git blob SHA `ceec04f2f71e5fe6f9450025629495bb37b0302d`, identico al archivo local que se ejecuto.

## 4. Limites: lo que estas pruebas NO prueban

- **No se uso el backend real completo:** se generaron routers y controladores minimos de prueba. Las pruebas HTTP no representan un E2E de Apache, Flutter, Azure ni MySQL.
- No se verifico inicio/cierre de sesion real: el 200 de login/logout procede solo de las fixtures.
- El cierre F-10 puede afectar la creacion de usuarios desde el Flutter heredado.
- K1 puede afectar consumidores legitimos desconocidos. La muestra de logs C5-A no prueba ausencia total de consumidores.
- El comentario de rollback dentro de `powerbi_authz.php` corresponde a su diseño original configurable; **el parche v2 fuerza cierre incondicional**, de modo que su rollback necesita restauracion controlada del router, no cambiar `POWERBI_AUTH_MODE`.
- El CI de GitHub debe reportarse por separado. **No se debe registrar CI como PASS hasta disponer de un run comprobable.**

## 5. Puertas pendientes para liberacion

- Validar consumidores K1 y autorizar Lote 2 separadamente; respetar secuencia de K2.
- DBA + funcional: procedimiento alternativo de altas F-10 aprobado y ensayado; ventana Lote 4.
- C5-B SQL de solo lectura pendiente del DBA (sin credenciales disponibles para el operador).
- Regresiones reales sobre copia aislada del backend heredado, sin alterar produccion.
- Respaldos, criterios de rollback, autorizaciones de cada lote y verificaciones funcionales posteriores.
- **P4b Administracion de Usuarios permanece NO-GO.**

**Conclusion:** Es valido conservar y revisar el paquete como PR en borrador. NO se autoriza la fusion ni el despliegue productivo por esta evidencia.
