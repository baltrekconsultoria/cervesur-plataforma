# UX-08F.1 · Guía del operador (Windows · PowerShell · PuTTY): del paquete local a la simulación

Todo lo que sigue lo ejecuta **la persona operadora**. Nada requiere `push`, `merge` ni despliegue: los paquetes se construyen desde la carpeta local `wt-ux08a` y se copian por el acceso SSH ya autorizado. **Ninguno de estos pasos instala la QA.** El último paso es solo la *simulación* y únicamente después de que usted valide los anteriores.

Convenciones: `PS>` es PowerShell en Windows; `$` es la sesión PuTTY en Ubuntu. `<usuario>` y `<clave.ppk>` son los suyos (no escriba contraseñas en la línea de comandos: use Pageant o la clave `.ppk`).

## 0. Qué se envía y qué NO
| Se envía | NO se envía nunca |
|---|---|
| Paquete de auditoría de solo lectura (`cervesur-auditoria-solo-lectura-*.tar.gz`) | `.env` con valores, claves SSH/TLS, contraseñas, tokens |
| Paquete de la QA aislada (`cervesur-react-admin-qa-*.tar.gz`), **sin instalar** | datos reales de usuarios, volcados (dumps) de producción |

Los empaquetadores rechazan `.env`, claves privadas y valores de secretos, y exigen una carpeta de salida **fuera del repositorio**.

## 1. Identificar la carpeta del paquete (Windows)
```powershell
PS> cd "C:\Users\Alexis Casas\Desktop\Proyectos Baltrek\Cervesur\wt-ux08a"
PS> git branch --show-current          # feat/cervesur-ux-08a-active-filters
PS> git status --short                 # lo ideal: vacío (todo confirmado). Si no, el nombre del paquete llevará «-con-cambios»
PS> git log -1 --format="%h %s"        # commit que se empaqueta
PS> Get-ChildItem deploy\qa-admin-aislado, deploy\auditoria -Name
```

## 2. Construir los paquetes (reproducibles: mismo commit → mismo contenido y mismo `SHA256SUMS` interno)
```powershell
PS> $out = "$HOME\Desktop\cervesur-paquetes"
PS> node deploy\auditoria\empaquetar-auditoria.mjs --out $out
PS> node deploy\qa-admin-aislado\empaquetar.mjs --out $out          # compila el frontend de servidor (host «localhost»); tarda unos minutos
```
El nombre incluye el commit y la fecha UTC; el `.tar.gz` lleva la hora de construcción, así que **su hash cambia si lo reconstruye**: transfiera el archivo ya construido, no lo reconstruya en medio del proceso. Necesita Node 20+ y, solo para el paquete de auditoría, `bash` de Git Bash: si en PowerShell `bash` abre WSL en lugar de Git Bash, ejecute ese primer comando desde la consola «Git Bash» (misma sintaxis, con `/` en las rutas).

## 3. Validar el contenido antes de enviar (Windows)
```powershell
PS> cd $out
PS> Get-ChildItem *.tar.gz | ForEach-Object { "$($_.Name)"; tar -tzf $_.Name | Select-String -Pattern '\.env($|\.)' | Where-Object { $_ -notmatch 'qa-admin\.example' } }   # no debe listar ningún .env real
PS> New-Item -ItemType Directory "$out\verificacion" -Force | Out-Null
PS> Get-ChildItem *.tar.gz | ForEach-Object { tar -xzf $_.Name -C "$out\verificacion" }
PS> Select-String -Path "$out\verificacion\*\*" -Pattern 'BEGIN .*PRIVATE KEY' -Recurse -ErrorAction SilentlyContinue   # sin resultados
PS> Get-ChildItem "$out\verificacion" -Directory | ForEach-Object { Get-Content "$($_.FullName)\VERSION", "$($_.FullName)\LEEME.txt" -ErrorAction SilentlyContinue | Select-Object -First 4 }
```
Revise a ojo `scripts\` y `deploy\auditoria\` extraídos (son texto): nada de lo que contienen modifica el servidor en el modo de auditoría; `instalar.sh` solo instala si usted lo ejecuta **sin** `--simular` y escribe la confirmación.

## 4. SHA-256 (Windows)
```powershell
PS> Get-FileHash -Algorithm SHA256 .\cervesur-*.tar.gz | Format-Table Hash, @{n='Archivo';e={Split-Path $_.Path -Leaf}} -AutoSize
PS> Get-Content .\*.sha256        # debe coincidir con lo anterior
```
Anote los dos hash: los comparará en Ubuntu.

## 5. Transferir por SCP (PuTTY `pscp` o el `scp` de Windows)
1. En una sesión PuTTY **ya autorizada**, cree una carpeta propia y privada (solo en su directorio personal):
   ```bash
   $ mkdir -p ~/cervesur-qa-transferencia && chmod 700 ~/cervesur-qa-transferencia
   ```
2. Desde PowerShell (la primera conexión muestra la huella del servidor: **compárela** con la que ya conoce):
   ```powershell
   PS> & "C:\Program Files\PuTTY\pscp.exe" -P 22 -i "<clave.ppk>" cervesur-auditoria-solo-lectura-*.tar.gz cervesur-auditoria-solo-lectura-*.tar.gz.sha256 <usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-transferencia/
   PS> & "C:\Program Files\PuTTY\pscp.exe" -P 22 -i "<clave.ppk>" cervesur-react-admin-qa-*.tar.gz cervesur-react-admin-qa-*.tar.gz.sha256 <usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-transferencia/
   ```
   (Con OpenSSH de Windows: `scp -i <clave> archivo <usuario>@192.168.200.12:~/cervesur-qa-transferencia/`.) No use `-pw`: la contraseña quedaría en el historial.

## 6. Verificar integridad en Ubuntu
```bash
$ cd ~/cervesur-qa-transferencia && ls -l
$ sha256sum -c *.sha256            # ambos «correcto»/OK; compárelos además con los hash anotados en el paso 4
```

## 7. Extraer en una carpeta temporal dedicada (su directorio personal; nada del sistema)
```bash
$ D=~/cervesur-qa-trabajo/$(date +%Y%m%d-%H%M) && mkdir -p "$D" && chmod 700 "$D"
$ for f in ~/cervesur-qa-transferencia/*.tar.gz; do tar -xzf "$f" -C "$D"; done
$ cd "$D" && ls && for d in */; do (cd "$d" && sha256sum -c SHA256SUMS --quiet && echo "$d OK"); done
```

## 8. Auditorías de solo lectura (en este orden; guardan la evidencia en su carpeta)
```bash
$ mkdir -p ~/cervesur-qa-evidencia/$(date +%Y%m%d) && E=~/cervesur-qa-evidencia/$(date +%Y%m%d)
$ cd "$D"/cervesur-auditoria-solo-lectura-*/
# 8.1 Puerto candidato (nada se modifica; el «bind» abre y cierra un socket de prueba en loopback)
$ bash deploy/auditoria/puerto-candidato-solo-lectura.sh 8094 | tee "$E/puerto-8094.txt"
# 8.2 Interruptor USER_ADMIN_WRITES de los portales publicados (sudo solo para poder leer .env 640; no imprime valores)
$ sudo bash deploy/auditoria/interruptor-escrituras.sh | tee "$E/interruptor.txt"
# 8.3 Divergencia repositorio ↔ servidor (3 backends)
$ sudo bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-admin/backend    > "$E/divergencia-admin.txt"
$ sudo bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-usuarios/backend > "$E/divergencia-usuarios.txt"
$ sudo bash deploy/auditoria/inventario-divergencia.sh /var/www/cervesur/backend            > "$E/divergencia-original.txt"
# 8.4 ¿Qué produce el 403 del administrador y dónde vive la lista de informes aprobados? (enmascara URLs, GUID y secretos)
$ sudo bash deploy/auditoria/autorizacion-403-solo-lectura.sh /opt/cervesur-react-admin/backend --mysql > "$E/autorizacion-admin.txt"
# 8.5 Pendientes de Flutter (P1, P2, P5–P8) y mapa general
$ sudo bash deploy/auditoria/flutter-original-solo-lectura.sh > "$E/flutter-original.txt" 2>&1
$ sudo bash deploy/auditoria/auditoria-solo-lectura.sh        > "$E/auditoria.txt" 2>&1
```
Revise **cada** archivo antes de compartirlo (`less`): no debe haber contraseñas, claves, tokens ni enlaces completos de Power BI. Para una comprobación rápida:
`grep -niE 'password|secret|token|BEGIN .*KEY|reportEmbed|powerbi\.com/.*groupId' "$E"/*.txt` (lo que aparezca debe ser nombres de claves o texto enmascarado, no valores).

## 9. Simulación del instalador (solo cuando 8.1 y 8.2 den PASS y usted lo apruebe)
```bash
$ cd "$D"/cervesur-react-admin-qa-*/
$ sudo bash scripts/instalar.sh --simular --puerto 8094 2>&1 | tee "$E/simulacion-8094.txt"
```
Debe terminar con `RESULTADO: GO` y «Modo --simular: fin. No se modificó nada.» o con `RESULTADO: NO-GO` y la razón. **No** quite `--simular`. La simulación no escribe nada en bases, cuentas, carpetas, Apache ni servicios (verificado en el ensayo; ver README §11).

## 10. Conservar la evidencia para revisión
```powershell
PS> New-Item -ItemType Directory "$HOME\Desktop\cervesur-evidencia" -Force | Out-Null
PS> & "C:\Program Files\PuTTY\pscp.exe" -P 22 -i "<clave.ppk>" "<usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-evidencia/*/*.txt" "$HOME\Desktop\cervesur-evidencia\"
PS> Select-String -Path "$HOME\Desktop\cervesur-evidencia\*.txt" -Pattern 'password|secret|BEGIN .*KEY|reportEmbed' | Select-Object -First 20
```
Comparta únicamente los `.txt` revisados. Las carpetas `~/cervesur-qa-transferencia`, `~/cervesur-qa-trabajo` y `~/cervesur-qa-evidencia` son suyas: puede borrarlas cuando termine (`rm -rf`), lo que no afecta a ningún portal.

## 11. Qué NO hacer
Quitar `--simular`; ejecutar `revertir.sh` o `instalar.sh` sin `--puerto`; usar 8090, 8091, 8092 ni 8093 (están ocupados); activar `USER_ADMIN_WRITES` en 443/8443; copiar `.env` o claves al paquete; pegar salidas sin revisar.
