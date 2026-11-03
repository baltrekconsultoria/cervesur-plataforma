# UX-08G.3 · Endurecimiento del respaldo antes de E3

Fecha: 2026-11-04 · Rama `feat/cervesur-ux-08g-release-candidate` · Árbol limpio · Sin SSH, push, merge ni despliegue. Sin cambios en UX-08, permisos, base de datos, Apache ni autenticación: **solo cambia `scripts/actualizar.sh`** (y su ensayo).

## 1. Qué se corrigió en `actualizar.sh` (el script no usa `set -e`: ahora cada paso se comprueba)
| # | Requisito | Corrección |
|---|---|---|
| 1 | Ningún reemplazo sin respaldo creado y verificado | El bloque «Respaldo» termina en `respaldo_fallo` (→ `abortar`, código 1, **antes** de «== Reemplazo») ante cualquier error |
| 2 | Comprobar directorios, archivos, permisos y manifiestos | `install -d DIR_RESPALDO`, `mkdir -m 700 RESP` (no sobrescribe uno existente), cada `tar`, la generación de `SHA256SUMS`, los `chmod` y `INFO.txt` se comprueban uno a uno |
| 3 | Validar el SHA-256 de ambos respaldos | `SHA256SUMS` con **exactamente 2 entradas** + `sha256sum -c`; además estructura (`public/`, `backend/`), **mismo nº de archivos que el disco**, sin `.env`, permisos 700/600 |
| 4 | Ante error de respaldo: código ≠ 0 y publicado intacto | El aborto ocurre antes de copiar nada a `.nuevo-*` y antes de los renombrados |
| 5 | No borrar respaldos anteriores | Nunca se borra nada de `/var/backups/…`; lo parcial de un fallo queda como evidencia |
| 6 | `.env` intacto | sin cambios: se copia al backend nuevo con `cp -p`, ahora también comprobado en la reversión |
| 7 | Reversión automática existente | conservada; ahora además deja `verificacion-fallida.log` (600) dentro del respaldo y `copiar_solo_lectura` falla si falla **cualquier** paso (antes solo contaba el último `find`) |
| 8 | Reversión manual con verificación fallida | **código ≠ 0**, conserva evidencia (`/var/backups/…/reversion-<sello>.log`, `.diagnostico-public-<sello>`, `.diagnostico-backend-<sello>` con la versión reemplazada) y no borra nada; los dos reemplazos son atómicos entre sí (si falla el segundo se deshace el primero) |
Extra encontrado al revisar: varios `… | grep -q` bajo `pipefail` podían dar falsos negativos por SIGPIPE; uno (`ss … | grep -qv … && abortar`) **podía fallar en abierto** (un SIGPIPE impedía abortar aunque 8093 escuchara fuera de loopback). Ahora usan variables capturadas y exigen que algo escuche. `shellcheck -S warning` limpio.
Inyección de fallos para ensayos: `CERVESUR_INYECTAR_FALLO=respaldo-dir|manifiesto|respaldo-corrupto|frontend|backend|verificacion`. **Solo puede hacer que un paso falle**; nunca omite ni relaja una comprobación. El operador no la usa.

## 2. Pruebas (Docker; base: piloto original `cef58da900e8` publicado en 8443 por IP; actualización con el RC)
**Ensayo completo del kit: 365 PASS / 0 FAIL** (antes 352; +13 de esta sección), incluidos:
| Escenario pedido | Resultado |
|---|---|
| Actualización exitosa con respaldo íntegro (SHA-256, estructura, nº de archivos, sin `.env`, 700/600) | PASS |
| Error al crear el directorio de respaldo | PASS: código ≠ 0, no empieza el reemplazo, sin respaldo nuevo, aplicación/backend/`.env` intactos |
| Error al generar el manifiesto SHA-256 | PASS: aborta antes de reemplazar; carpeta parcial conservada como evidencia |
| Respaldo corrupto | PASS: `SHA-256 … no coincide`, aborta antes de reemplazar |
| Respaldos anteriores intactos | PASS: sus `SHA256SUMS` idénticos antes/después de 5 fallos |
| Error durante el reemplazo del frontend | PASS: vuelta automática, código ≠ 0, bit a bit, verificado |
| Error durante el reemplazo del backend (frontend ya reemplazado) | PASS: se deshace también el frontend, bit a bit |
| Error en la verificación posterior (inyectado) | PASS: reversión automática, código ≠ 0 |
| Verificación posterior **real** fallida (backend roto en ejecución) | PASS: vuelve sola y deja `verificacion-fallida.log` (600, sin hashes, tokens ni contraseña) |
| Reversión manual con verificación fallida | PASS: código ≠ 0, evidencia conservada (log + versión reemplazada), versión anterior restaurada bit a bit |
| Integridad de los demás portales | PASS: trabajadores, Apache (sin reiniciar), Flutter y demás portales idénticos; el administrador entra por 8443 |
Además: pruebas estáticas 95 OK, preflight 14/14, opcache 10/10, shellcheck limpio. No se repitieron el HTTP de la CI (1501) ni Vitest porque **backend y frontend no cambiaron** (ver §3).

## 3. Paquete nuevo y diferencias respecto al RC anterior
Commit `3f859210c5c4`, árbol limpio, en `Desktop/Proyectos Baltrek/Cervesur/paquetes-qa/produccion-v4/`:
| Archivo | SHA-256 del `.tar.gz` |
|---|---|
| `cervesur-react-admin-3f859210c5c4-20261103-211438Z.tar.gz` | `862fafd3040bab99097c076bdc4cfcfe8f108f3667d829ebc561a02640eb148c` |
**Diferencias con `…2327c9cd1c1b…` (verificado con `diff -rq`):** únicamente `scripts/actualizar.sh` (+61/−22 líneas), `VERSION` y `SHA256SUMS`. `public/` y `backend/` son **byte a byte iguales**: la aplicación, el backend, las 14 rutas bloqueadas, el modo `piloto_admin`, la lista de informes y el `.env` no cambian. Sigue sin `.env`, claves, `syn###` ni migraciones.
**El paquete de auditoría de la ronda anterior (`…2327c9cd1c1b…-auditoria…`) sigue siendo válido**: sus herramientas no cambiaron (no hace falta reenviarlo; el de `produccion-v4` es equivalente).

## 4. Cómo sustituir el paquete anterior en el servidor (solo se reemplaza el paquete de liberación)
Windows (PowerShell):
```powershell
PS> cd "C:\Users\Alexis Casas\Desktop\Proyectos Baltrek\Cervesur\paquetes-qa\produccion-v4"
PS> Get-FileHash -Algorithm SHA256 .\cervesur-react-admin-3f859210c5c4-20261103-211438Z.tar.gz    # debe ser 862fafd3040bab99097c076bdc4cfcfe8f108f3667d829ebc561a02640eb148c
PS> Get-Content .\cervesur-react-admin-3f859210c5c4-20261103-211438Z.tar.gz.sha256
PS> $p = "C:\Program Files\PuTTY\pscp.exe"; $d = "<usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-transferencia/"
PS> & $p -P 22 -i "<clave.ppk>" .\cervesur-react-admin-3f859210c5c4-20261103-211438Z.tar.gz .\cervesur-react-admin-3f859210c5c4-20261103-211438Z.tar.gz.sha256 $d
```
PuTTY (no se borra ni se modifica el paquete anterior; solo se deja de usar):
```bash
cd ~/cervesur-qa-transferencia && sha256sum -c cervesur-react-admin-3f859210c5c4-*.sha256        # OK
D=$(ls -dt ~/cervesur-qa-trabajo/*/ | head -1); tar -xzf cervesur-react-admin-3f859210c5c4-*.tar.gz -C "$D"
PKG=$(ls -d "$D"cervesur-react-admin-3f859210c5c4-*/); (cd "$PKG" && sha256sum -c SHA256SUMS --quiet && echo "PKG OK")
AUD=$(ls -d "$D"cervesur-auditoria-solo-lectura-*/ | tail -1)      # el de la ronda anterior sirve
E=~/cervesur-qa-evidencia/$(date +%Y%m%d)-G3; mkdir -p "$E"; cd "$AUD"
```
## 5. Verificaciones afectadas que se repiten (las demás NO cambian y no se repiten: 11 interruptores, 12 identidad, 13 informes autorizados)
```bash
# 10 · preflight con el paquete nuevo
sudo bash deploy/auditoria/preflight-produccion.sh --paquete "$PKG" 2>&1 | tee "$E/10-preflight.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/10-preflight.txt"
# 15 · opcache con el paquete nuevo (compara también el bundle servido)
sudo bash deploy/auditoria/opcache-solo-lectura.sh --paquete "$PKG" --url-local https://localhost:8093 2>&1 | tee "$E/15-opcache.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/15-opcache.txt"
# 14 · E2 simulación con el actualizar.sh NUEVO (no escribe nada)
cd "$PKG" && sudo bash scripts/actualizar.sh --simular 2>&1 | tee "$E/14-simulacion-actualizar.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/14-simulacion-actualizar.txt"
```
Esperado: `10` → `RESULTADO: GO` (EXIT_CODE=0); `15` → `RESULTADO: GO` (EXIT_CODE=0); `14` → «sintaxis PHP correcta», «SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede actualizar.» (EXIT_CODE=0). **NO-GO** ante cualquier `FAIL`/`FALLO`/código ≠ 0. Descargue los tres a `…\Cervesur-UX08-Evidencias\G3` con `pscp` y compártalos revisados.
**E3 debe ejecutarse con este paquete, nunca con `…2327c9cd1c1b…`**: `cd "$PKG" && sudo bash scripts/actualizar.sh` (tras las copias previas de la guía y con la autorización expresa).

## 6. Riesgos residuales
1. La inyección de fallos prueba el comportamiento del script, no fallos reales del disco: un disco lleno real se manifestará como el mismo error de `tar` (que aborta antes de reemplazar). 2. La reversión manual exitosa borra la **versión reemplazada** (no un respaldo) tras verificar; en caso de fallo la conserva. 3. Los demás scripts del kit (`verificar.sh`, etc.) no se auditaron en esta ronda en busca de `grep -q` bajo `pipefail`; no se observaron fallos y `actualizar.sh` (el único que escribe en producción) quedó limpio. 4. E3 sigue **sin autorización**; DB031 (72) y las escrituras siguen sin habilitar.
