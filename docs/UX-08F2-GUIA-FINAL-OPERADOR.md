# UX-08F.2 · Guía final del operador: empaquetado, auditoría de solo lectura y simulación (commit `ff3252d`)

Complementa `UX-08F1-GUIA-OPERADOR-WINDOWS.md` con los nombres reales de los paquetes construidos. **Nada de esta guía instala la QA.** Sustituya `<usuario>` y `<clave.ppk>` por los suyos; no ponga contraseñas en la línea de comandos.

## Evidencia ya confirmada (PuTTY, 2026-11-03)
8094 sin listener ni referencias en Apache (candidato, se vuelve a comprobar antes de instalar) · MySQL 8.0.46, `root@localhost` con SUPER, CREATE, ALTER, TRIGGER, CREATE USER · `log_bin=1`, `log_bin_trust_function_creators=0` (no se cambia) · `cervesur_qa_admin` y `cervesur_qa_admin_w` inexistentes · 80 Flutter, 443 trabajadores, 8443 administrador, 8088-8093 Apache (no se tocan ni reutilizan).

## Paquetes construidos (carpeta fuera del repositorio)
`C:\Users\Alexis Casas\Desktop\Proyectos Baltrek\Cervesur\paquetes-qa\`

| Paquete | Archivo | SHA-256 del `.tar.gz` |
|---|---|---|
| Auditoría (solo lectura, 10 archivos) | `cervesur-auditoria-solo-lectura-ff3252d4f4f9-20261103-152006Z.tar.gz` | `b8500f5adc17d677fa0c37b84bd45a11b62e9aaa0b9753cd703ec8a862f3d84f` |
| QA aislada (69 archivos) | `cervesur-react-admin-qa-ff3252d4f4f9-20261103-152025Z.tar.gz` | `fc0ee778ae39ddf31ac0e7f577a5320d87b876537256ea9cb50d9e22ec39cd6e` |

Verificado al construir: `SHA256SUMS` interno correcto en ambos; sin `.env` (solo `.env.qa-admin.example` sin valores), sin claves privadas ni secretos; commit `ff3252d4f4f9` sin cambios pendientes; el puerto no está fijo (se exige `--puerto`; `8090-8093` solo aparecen en la lista de rechazo). Si reconstruye, el hash cambia: use siempre el archivo ya construido.

## Momento 1 · PowerShell (Windows): verificar y transferir
```powershell
PS> cd "C:\Users\Alexis Casas\Desktop\Proyectos Baltrek\Cervesur\paquetes-qa"
PS> Get-ChildItem *.tar.gz | Select-Object Name, Length
PS> Get-FileHash -Algorithm SHA256 *.tar.gz | Format-Table Hash, @{n='Archivo';e={Split-Path $_.Path -Leaf}} -AutoSize   # deben coincidir con la tabla de arriba
PS> Get-Content *.sha256
```
En PuTTY (una vez): `mkdir -p ~/cervesur-qa-transferencia && chmod 700 ~/cervesur-qa-transferencia`. Luego, en PowerShell (compare la huella del servidor en la primera conexión):
```powershell
PS> $p = "C:\Program Files\PuTTY\pscp.exe"; $d = "<usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-transferencia/"
PS> & $p -P 22 -i "<clave.ppk>" (Get-ChildItem *.tar.gz, *.sha256 | ForEach-Object Name) $d
```
Si PowerShell no expande bien la lista, envíe los cuatro archivos con cuatro comandos `pscp` usando los nombres exactos de la tabla (y sus `.sha256`).

## Momento 2 · PuTTY (Ubuntu): integridad, extracción y auditoría de solo lectura
```bash
cd ~/cervesur-qa-transferencia && sha256sum -c *.sha256            # ambos OK
D=~/cervesur-qa-trabajo/$(date +%Y%m%d-%H%M) && mkdir -p "$D" && chmod 700 "$D"
for f in ~/cervesur-qa-transferencia/*.tar.gz; do tar -xzf "$f" -C "$D"; done
for d in "$D"/*/; do (cd "$d" && sha256sum -c SHA256SUMS --quiet && echo "$d OK"); done
E=~/cervesur-qa-evidencia/$(date +%Y%m%d) && mkdir -p "$E"
cd "$D"/cervesur-auditoria-solo-lectura-ff3252d4f4f9-*/

# Bloqueantes (si fallan, no avance):
bash deploy/auditoria/puerto-candidato-solo-lectura.sh 8094 | tee "$E/01-puerto-8094.txt"
sudo bash deploy/auditoria/interruptor-escrituras.sh          | tee "$E/02-interruptor.txt"

# Evidencia para cerrar el piloto (puede ejecutarse en paralelo; nada se modifica):
sudo bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-admin/backend    > "$E/03-divergencia-admin.txt"
sudo bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-usuarios/backend > "$E/04-divergencia-usuarios.txt"
sudo bash deploy/auditoria/inventario-divergencia.sh /var/www/cervesur/backend            > "$E/05-divergencia-original.txt"
sudo bash deploy/auditoria/autorizacion-403-solo-lectura.sh /opt/cervesur-react-admin/backend --mysql > "$E/06-autorizacion-admin.txt"
sudo bash deploy/auditoria/flutter-original-solo-lectura.sh > "$E/07-flutter-original.txt" 2>&1
grep -niE 'password|secret|token|BEGIN .*KEY|reportEmbed|groupId=' "$E"/*.txt | head -40    # solo deben salir NOMBRES de claves o texto enmascarado
```
Revise cada archivo con `less` antes de compartirlo.

## Qué archivos compartir y qué decide cada uno
| Archivo | Qué responde | Bloquea la instalación si… |
|---|---|---|
| `01-puerto-8094.txt` | ¿8094 libre para `127.0.0.1`? | termina en `NO-GO`, hay listener o mención en Apache/otro servicio |
| `02-interruptor.txt` | ¿`USER_ADMIN_WRITES` apagado en `/opt/cervesur-react-usuarios` y `/opt/cervesur-react-admin`? | cualquier instancia `FAIL` (`ENCENDIDO` o `no-se-puede-demostrar`) |
| `03/04/05-divergencia-*.txt` | archivos del piloto que no existen en el repositorio (sección 1), archivos distintos (2), dependencias (6), marcadores de seguridad (7), interruptores (8) | informativo; **prohíbe** reemplazar el directorio publicado. Sección 8 con `USER_ADMIN_WRITES` = 1 → bloquea |
| `06-autorizacion-admin.txt` | qué produce el 403 y dónde vive la lista de informes aprobados (variables, archivos, constantes, tablas; tablas candidatas en MySQL) | informativo; sin ella **no** se autoriza DB031 (id 72) |
| `07-flutter-original.txt` | P1 (login), P2 (JWT), P5/P6 (usuarios), P7 (transacciones), P8 (privilegios) | informativo; sin ella no se habilitan escrituras de usuarios contra producción |

Con `06` y `03` sabré cuál es el mecanismo del 403 (lista en código / archivo / base de estado / sesión), qué archivos propios del piloto hay que conservar y qué falta para aprobar el id 72 por el procedimiento del propio piloto. **No comparta** `.env`, hashes de contraseñas ni enlaces Power BI completos.

## Momento 3 · Simulación (solo si `01` y `02` están en PASS y yo he revisado el resto)
```bash
cd "$D"/cervesur-react-admin-qa-ff3252d4f4f9-*/
sudo bash scripts/instalar.sh --simular --puerto 8094 2>&1 | tee "$E/08-simulacion-8094.txt"
```
Argumentos reales: `--simular` (obligatorio aquí) y `--puerto 8094` (obligatorio; sin él aborta). **No** añada `--aplicar-migracion-grupo-nullable` ni quite `--simular`. La simulación vuelve a comprobar, en solo lectura:
puerto 8094 (listeners, Apache, `apache2ctl -S`) · `configtest`, `mod_alias`/`rewrite`/`php`/`headers` · acceso administrativo a MySQL, versión ≥ 8.0.21, `log_bin`, y que la cuenta puede crear triggers (SUPER) · base y cuenta QA inexistentes · carpetas/sitio inexistentes · integridad del paquete · `USER_ADMIN_WRITES` apagado en los portales publicados. No crea bases, usuarios, archivos ni cambia servicios, y rechaza cualquier base que contenga `portalbi`.

Resultado esperado: línea `RESULTADO: GO` y «Modo --simular: fin. No se modificó nada.». Cualquier `RESULTADO: NO-GO`, `FALLO` o error **bloquea** y se me envía `08-simulacion-8094.txt`.

## Después
Comparta `01`…`08`. Con un `GO` real y esas evidencias pediré su autorización expresa para la instalación QA (incluye aprobar la directiva `Listen 127.0.0.1:8094` en el sitio nuevo). Hasta entonces: **sin instalación, sin migraciones, sin cambios en Apache, MySQL, Flutter ni portales publicados.**
