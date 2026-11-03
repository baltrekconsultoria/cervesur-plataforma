# UX-08G.2 · Instrucciones literales: E1 (auditoría de solo lectura) y E2 (simulación de la actualización)

Sustituye a la sección E1/E2 de `UX-08G-GUIA-DESPLIEGUE-PRODUCCION.md` (el resto de esa guía —E0 construir, E3 activación, E4 QA, E5 rollback— sigue vigente). **Nada de lo que sigue modifica el servidor**: lee archivos, consulta metadatos de MySQL (`SHOW GRANTS`) y hace peticiones HTTP GET a `/api/ping`. Efectos laterales posibles y aceptados: líneas en los registros de acceso de Apache y, en portales que abren sesión PHP, un archivo de sesión en su propio directorio. **No hay autorización para activar**; E3 se pide aparte.

Datos ya confirmados por las evidencias del servidor (no se repiten): 8094 libre; `USER_ADMIN_WRITES` apagado en ambos portales; backend PHP de `/opt/cervesur-react-admin` **= 53/53 archivos idénticos al paquete `cef58da900e8`** (huellas SHA-256 de la evidencia 04); el administrador de 8443 y el de trabajadores comparten el mismo código; Flutter usa `cervesur_portalbi_admin` y los portales React cuentas `cervesur_ro_*`.

## E0 · Construir y enviar (Windows · PowerShell)
```powershell
PS> cd "C:\Users\Alexis Casas\Desktop\Proyectos Baltrek\Cervesur\wt-ux08g"
PS> git status --short                      # debe estar VACÍO
PS> git log -1 --format="%H %s"
PS> $out = "$HOME\Desktop\cervesur-paquetes\produccion-final"
PS> node deploy\produccion-admin\empaquetar.mjs --host=192.168.200.12 --out $out      # paquete de liberación (compila)
PS> node deploy\auditoria\empaquetar-auditoria.mjs --out $out                          # herramientas de solo lectura (necesita Git Bash en el PATH)
PS> cd $out; Get-ChildItem *.tar.gz, *.sha256, *.RELEASE.json | Select-Object Name, Length
PS> Get-FileHash -Algorithm SHA256 *.tar.gz | Format-Table Hash, @{n='Archivo';e={Split-Path $_.Path -Leaf}} -AutoSize     # = contenido de cada .sha256
PS> Get-Content *.RELEASE.json                # commit, árbol limpio, hosts [localhost, 192.168.200.12], migraciones_incluidas: []
```
Aborte si el nombre lleva `-con-cambios` o si algún hash difiere de su `.sha256`. Envío (primera conexión: compare la huella del servidor; no use `-pw`):
```powershell
PS> $p = "C:\Program Files\PuTTY\pscp.exe"; $d = "<usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-transferencia/"
PS> Get-ChildItem *.tar.gz, *.sha256 | ForEach-Object { & $p -P 22 -i "<clave.ppk>" $_.FullName $d }
```
(antes, en PuTTY, una sola vez: `mkdir -p ~/cervesur-qa-transferencia && chmod 700 ~/cervesur-qa-transferencia`)

## E1 · Auditoría (PuTTY · Ubuntu)
```bash
cd ~/cervesur-qa-transferencia && sha256sum -c *.sha256                      # ambos: OK
D=~/cervesur-qa-trabajo/$(date +%Y%m%d-%H%M); mkdir -p "$D" && chmod 700 "$D"
for f in ~/cervesur-qa-transferencia/*.tar.gz; do tar -xzf "$f" -C "$D"; done
for d in "$D"/*/; do (cd "$d" && sha256sum -c SHA256SUMS --quiet && echo "$d OK"); done
PKG=$(ls -d "$D"/cervesur-react-admin-*/ | head -1); AUD=$(ls -d "$D"/cervesur-auditoria-solo-lectura-*/ | head -1)
E=~/cervesur-qa-evidencia/$(date +%Y%m%d)-E1; mkdir -p "$E"; cd "$AUD"

# 10 · PREFLIGHT: estado del administrador publicado + contenido del paquete
sudo bash deploy/auditoria/preflight-produccion.sh --paquete "$PKG" 2>&1 | tee "$E/10-preflight.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/10-preflight.txt"
# 11 · INTERRUPTORES de escritura (por defecto: /opt/cervesur-react-usuarios y /opt/cervesur-react-admin)
sudo bash deploy/auditoria/interruptor-escrituras.sh 2>&1 | tee "$E/11-interruptores.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/11-interruptores.txt"
# 12 · IDENTIDAD de lo instalado frente al piloto cef58da900e8 (backend .php y aplicación public/, hash a hash)
sudo bash deploy/auditoria/identidad-instalada-solo-lectura.sh 2>&1 | tee "$E/12-identidad-admin.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/12-identidad-admin.txt"
# 13 · INFORMES con enlace autorizado (solo ids; el comando NO modifica nada)
sudo bash "$PKG/scripts/usuarios.sh" informes --listar 2>&1 | tee "$E/13-informes-autorizados.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/13-informes-autorizados.txt"
# 15 · OPCACHE: ¿garantiza la configuración real que tras actualizar se sirve el código nuevo?
sudo bash deploy/auditoria/opcache-solo-lectura.sh --paquete "$PKG" --url-local https://localhost:8093 2>&1 | tee "$E/15-opcache.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/15-opcache.txt"
```
Argumentos verificados contra el código: `preflight-produccion.sh [--paquete DIR]`; `interruptor-escrituras.sh [instancia …]`; `identidad-instalada-solo-lectura.sh [--back DIR] [--public DIR] [--ref-backend F] [--ref-public F]`; `usuarios.sh informes --listar`; `opcache-solo-lectura.sh [--espera N] [--paquete DIR] [--url-local URL]`. Los manifiestos de referencia van dentro del paquete de auditoría, junto a los scripts.

### Resultados esperados y NO-GO
| Evidencia | Esperado | NO-GO si |
|---|---|---|
| 10 | `RESULTADO: GO para pasar a la simulación…`, `EXIT_CODE=0` (los AVISO son informativos) | `RESULTADO: NO-GO` / cualquier `FAIL`: modo ≠ `piloto_admin`, cuenta que no es `cervesur_ro_*`, paquete con `.env`/`syn###`/migraciones, hash del paquete roto, mod_headers ausente… |
| 11 | ambas instancias `PASS`, `EXIT_CODE=0` | cualquier `FAIL` o `ENCENDIDO` |
| 12 | `RESULTADO: IDÉNTICO al piloto de referencia`, `EXIT_CODE=0` (backend 53/53 y aplicación 7/7) | `RESULTADO: HAY DIFERENCIAS`: cada `DISTINTO`/`FALTA`/`SOBRA` debe clasificarse (cambio local legítimo que hay que conservar, o deriva) **antes** de autorizar nada. Un `SOBRA` en backend se perdería al reemplazar |
| 13 | `Informes con enlace autorizado en el visor: NINGUNO` o una lista de ids (anote cuáles) | el valor es `TODOS` (autorización masiva) o aparece un id que no esté aprobado por quien responde por los datos |
| 15 | `RESULTADO: GO` (con la espera por defecto de 4 s) | `RESULTADO: NO-GO`: si pide `CERVESUR_ESPERA_OPCACHE=N` se usa N en E2/E3; si dice `validate_timestamps=0` se necesita una recarga suave de Apache **aprobada aparte** (no se reinicia el servicio) |

### Recuperar las evidencias (Windows)
```powershell
PS> $dest = "C:\Users\Alexis Casas\Downloads\Cervesur-UX08-Evidencias\E1"; New-Item -ItemType Directory $dest -Force | Out-Null
PS> & "C:\Program Files\PuTTY\pscp.exe" -P 22 -i "<clave.ppk>" "<usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-evidencia/*-E1/*.txt" $dest
PS> Select-String -Path "$dest\*.txt" -Pattern 'password|secret|BEGIN .*KEY|reportEmbed|groupId=' | Select-Object -First 10     # no debe salir ningún valor
```
Revíselos con `less` antes de compartirlos. **Nunca** se copian a carpetas versionadas ni a paquetes.

## E2 · Simulación de la actualización (solo lectura) — evidencia 14
Condición previa: 10, 11, 12, 13 y 15 en GO/aceptables.
```bash
cd "$PKG" && sudo bash scripts/actualizar.sh --simular 2>&1 | tee "$E/14-simulacion-actualizar.txt"; echo "EXIT_CODE=${PIPESTATUS[0]}" | tee -a "$E/14-simulacion-actualizar.txt"
# solo si 15 pidió otra espera:  sudo CERVESUR_ESPERA_OPCACHE=<N> bash scripts/actualizar.sh --simular
```
**Qué hace** (verificado en `actualizar.sh` y por el ensayo: mismos archivos, mismo `.env`, sin respaldos nuevos): comprueba que el administrador está instalado, sano (`/api/ping` por HTTPS local), en `piloto_admin` y sin `CERVESUR_INSECURE_TESTS`; que el paquete es íntegro, sin `.env`/claves/certificados, con `pilotMode: admin` y **sintaxis PHP correcta en todo el backend**; que MySQL está activo; que 8093 escucha solo en loopback; calcula la huella de los demás portales; imprime qué reemplazaría; y **termina antes de crear respaldos**. **No** escribe en el servidor, no toca Apache ni recarga servicios, no ejecuta migraciones, no toca cuentas ni la base MySQL (solo `SHOW DATABASES`/`SELECT user,host FROM mysql.user` para la huella), no modifica el `.env` ni la lista `CERVESUR_PILOT_VISOR_IDS`, y no habilita escrituras (el paquete trae `piloto_admin`). Esperado: `SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede actualizar.` y `EXIT_CODE=0`. **NO-GO:** cualquier `FALLO` o código ≠ 0 (p. ej. «Error de sintaxis PHP en el paquete», «El piloto actual no responde por HTTPS», puerto fuera de loopback).

## Qué compartir
`10`, `11`, `12`, `13`, `15` y, si todo lo anterior es aceptable, `14`. Con eso se emite el GO/NO-GO final para **pedir** la autorización de E3.
