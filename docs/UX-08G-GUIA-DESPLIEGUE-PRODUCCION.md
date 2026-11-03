# UX-08G · Guía del operador: liberación del administrador (8443) · versión 1 «solo lectura» (Windows / PowerShell / PuTTY)

> **Esta guía es un procedimiento para APROBAR, no una orden de ejecutar.** Cada etapa necesita su autorización; las etapas E0–E2 no cambian nada en el servidor. La etapa **E3 (activación) exige una autorización expresa y separada** y una ventana acordada. Ninguna etapa activa escrituras, toca Flutter (80), los trabajadores (443), cuentas o bases MySQL, ni autoriza ningún informe (DB031 incluido).

Qué cambia en la versión 1: la interfaz de administración de UX-08 (filtros «activos» por defecto, paginación de 25, pantallas de Usuarios/Empresas/Informes/Accesos de **consulta**) sobre el mismo administrador de solo lectura que ya corre, con un endurecimiento (un informe inactivo no entrega enlace). **No** hay migraciones, ni cuentas nuevas, ni cambios de Apache, certificados ni `.env`.

Datos fijos del entorno: servidor `192.168.200.12`; administrador `https://192.168.200.12:8443` (VirtualHost `cervesur-react-admin-corp`), túnel `127.0.0.1:8093`; directorios `/var/www/cervesur-react-admin` y `/opt/cervesur-react-admin`; respaldos `/var/backups/cervesur-react-admin`.

## E0 · Construir y verificar el paquete (Windows; no toca el servidor)
```powershell
PS> cd "C:\Users\Alexis Casas\Desktop\Proyectos Baltrek\Cervesur\wt-ux08g"
PS> git branch --show-current                    # feat/cervesur-ux-08g-release-candidate
PS> git status --short                           # DEBE estar vacío: el empaquetador se niega a construir con cambios
PS> git log -1 --format="%H %s"                  # commit que se empaqueta (queda en RELEASE.json)
PS> $out = "$HOME\Desktop\cervesur-paquetes\produccion"
PS> node deploy\produccion-admin\empaquetar.mjs --host=192.168.200.12 --out $out
PS> node deploy\auditoria\empaquetar-auditoria.mjs --out $out        # herramientas de solo lectura (preflight, inventario, interruptor)
PS> cd $out; Get-ChildItem *.tar.gz, *.RELEASE.json | Select-Object Name, Length
PS> Get-FileHash -Algorithm SHA256 *.tar.gz | Format-Table Hash, @{n='Archivo';e={Split-Path $_.Path -Leaf}} -AutoSize   # = contenido de los .sha256
PS> Get-Content *.RELEASE.json                    # commit, rama, árbol limpio, hosts, modo, «migraciones_incluidas: []»
```
**Aborte si:** el nombre del paquete contiene `-con-cambios`; `migraciones_incluidas` no está vacío; el hash no coincide con el `.sha256`; `hosts` no contiene exactamente `localhost` y `192.168.200.12`.
Revisión de contenido (opcional pero recomendada): `tar -tzf <paquete>.tar.gz | Select-String '\.env|\.pem|\.key|syn0'` → sin resultados.

## E1 · Transferir y auditar (solo lectura)
Crear en PuTTY una carpeta propia (`mkdir -p ~/cervesur-qa-transferencia && chmod 700 ~/cervesur-qa-transferencia`) y enviar con `pscp` (primera conexión: compare la huella del servidor; no use `-pw`):
```powershell
PS> $p = "C:\Program Files\PuTTY\pscp.exe"; $d = "<usuario>@192.168.200.12:/home/<usuario>/cervesur-qa-transferencia/"
PS> Get-ChildItem *.tar.gz, *.sha256 | ForEach-Object { & $p -P 22 -i "<clave.ppk>" $_.FullName $d }
```
```bash
$ cd ~/cervesur-qa-transferencia && sha256sum -c *.sha256                  # ambos OK
$ D=~/cervesur-qa-trabajo/$(date +%Y%m%d-%H%M) && mkdir -p "$D" && chmod 700 "$D"
$ for f in ~/cervesur-qa-transferencia/*.tar.gz; do tar -xzf "$f" -C "$D"; done
$ for d in "$D"/*/; do (cd "$d" && sha256sum -c SHA256SUMS --quiet && echo "$d OK"); done
$ E=~/cervesur-qa-evidencia/$(date +%Y%m%d) && mkdir -p "$E"
$ PKG=$(ls -d "$D"/cervesur-react-admin-*/ | head -1); AUD=$(ls -d "$D"/cervesur-auditoria-solo-lectura-*/ | head -1)
$ cd "$AUD"
# 1) ¿el administrador publicado está en el estado que el paquete espera? (nada se modifica)
$ sudo bash deploy/auditoria/preflight-produccion.sh --paquete "$PKG" | tee "$E/10-preflight-produccion.txt"
# 2) ¿escrituras apagadas?
$ sudo bash deploy/auditoria/interruptor-escrituras.sh | tee "$E/11-interruptor.txt"
# 3) ¿lo desplegado es el piloto cef58da900e8? (diferencias, nombres de archivo, conteos; sin contenido ni URLs)
$ sudo bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-admin/backend deploy/auditoria/manifiesto-piloto-cef58da900e8.txt > "$E/12-divergencia-admin-vs-piloto.txt"
# 4) lista actual de informes con enlace autorizado (solo ids; no cambia nada)
$ sudo bash "$PKG/scripts/usuarios.sh" informes --listar | tee "$E/13-informes-autorizados.txt"
```
**Comparta** `10`…`13` (revisados con `less`: sin contraseñas ni enlaces). **Bloquea la liberación:** `10` con `RESULTADO: NO-GO`; `11` con algún FAIL; `12` con archivos «solo en el servidor» o «distintos» que no se comprendan (se me envían y se decide si la diferencia es un cambio local del servidor que habría que conservar); cualquier sorpresa en `13` (p. ej. `TODOS`).

## E2 · Simulación de la actualización (solo lectura; escribe únicamente su salida)
```bash
$ cd "$PKG" && sudo bash scripts/actualizar.sh --simular 2>&1 | tee "$E/14-simulacion-actualizar.txt"
```
Debe terminar con «SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede actualizar.». Comprueba: instalación sana y en `piloto_admin`, paquete íntegro y sin `.env`/claves, **sintaxis PHP de todo el backend del paquete (`php -l`; un archivo roto se rechaza antes de tocar nada)**, `pilotMode: admin`, MySQL activo, el administrador responde por HTTPS, 8093 solo en loopback, y avisa si la configuración de Apache del paquete difiere de la instalada (la actualización **no** la cambia).
**Bloquea:** cualquier `FALLO`/código distinto de 0.

## E3 · ACTIVACIÓN (requiere autorización expresa y separada; no ejecutar sin ella)
Antes, el operador deja a salvo lo que el respaldo del kit **no** incluye (por seguridad no copia secretos):
```bash
$ sudo install -d -m 700 /root/cervesur-pre-ux08g
$ sudo cp -p /opt/cervesur-react-admin/backend/.env /root/cervesur-pre-ux08g/env-admin.bak       # y guarde esta copia fuera del servidor
$ sudo tar czf /root/cervesur-pre-ux08g/apache2-etc.tgz -C / etc/apache2
$ sudo sha256sum /root/cervesur-pre-ux08g/* | tee "$E/15-copias-previas.sha256"
$ sudo bash -c 'ss -ltn | sort > /root/cervesur-pre-ux08g/puertos-antes.txt; for p in 80 443 8443; do echo "== $p"; curl -sk -m 8 -o /dev/null -D - https://127.0.0.1:$p/ 2>/dev/null | head -1; done' | tee "$E/16-estado-antes.txt"
```
Nota de diseño (hallazgo del ensayo UX-08G.1): `mod_php` usa opcache y revalida cada ~2 s, por eso `actualizar.sh` **espera 4 s antes de cada verificación** (también antes de verificar una restauración); sin esa espera una versión rota puede pasar la verificación atendida desde caché. No acorte la espera (`CERVESUR_ESPERA_OPCACHE`).

Activación (pide escribir `ACTUALIZAR-8093`; respalda `public` y `backend` sin `.env`, reemplaza por renombrado, ejecuta `verificar.sh` y **vuelve sola** a la versión anterior si algo falla; compara la huella de los demás portales antes y después):
```bash
$ cd "$PKG" && sudo bash scripts/actualizar.sh 2>&1 | tee "$E/17-actualizacion.txt"
```
Anote la línea final «Para volver atrás: … --revertir <RESPALDO>». Hora, responsable y versión: `date -u; whoami; cat "$PKG/VERSION"` → `"$E/18-registro.txt"`.

## E4 · QA posterior (el mismo día) y observación
Manual (navegador, equipo de la red autorizada): entrar a `https://192.168.200.12:8443` con un administrador de la lista; **Usuarios / Empresas / Informes / Accesos**: listas cargan, filtro «Activos» por defecto, «Inactivos» y «Todos» funcionan, paginación de 25, buscador; **no** aparecen botones de crear/editar/desactivar/asignar ni «Historial» (banner «Solo consulta»); informe **no autorizado** → mensaje de acceso denegado sin enlace; informe autorizado → visor; un informe inactivo → no abre; cerrar sesión; un trabajador **no** entra al administrador. Comprobar además: Flutter `http://192.168.200.12/` y trabajadores `https://192.168.200.12/` responden como antes. Automático: `sudo bash scripts/verificar.sh` (del paquete) y `sudo bash deploy/auditoria/preflight-produccion.sh` otra vez (`RESULTADO: GO`). Revisar `sudo tail -50 /var/log/apache2/error.log` (sin errores PHP nuevos) y `sudo bash scripts/usuarios.sh auditoria` (eventos de consulta). Observación sugerida: 7 días antes de planear la fase 2.

## E5 · Rollback (cualquier etapa; no borra datos ni auditoría)
- Falló la activación: **vuelve sola** (verá «versión anterior restaurada y verificada»). Si no lo hace: `sudo bash scripts/actualizar.sh --revertir <RESPALDO>` (pide `REVERTIR-ACTUALIZACION-8093`).
- Falló después (regresión funcional): mismo comando con el respaldo anotado en E3. Restaura `public` y `backend`; el `.env` conserva su contenido y permisos.
- Si el `.env` se hubiera dañado: restaurar `/root/cervesur-pre-ux08g/env-admin.bak` (`sudo cp -p … /opt/cervesur-react-admin/backend/.env; sudo chown root:www-data …; sudo chmod 640 …`).
- Apache solo si se hubiera alterado (no debería): `/root/cervesur-pre-ux08g/apache2-etc.tgz` y `apache2ctl configtest` **antes** de cualquier recarga.
- **No** se usa `DELETE`, no se borran respaldos, no se toca la QA 8094 ni Flutter.
- Criterios de **abortar antes** de activar: cualquier NO-GO de E1/E2, hash que no coincida, `-con-cambios`, `USER_ADMIN_WRITES=1` o cuenta con escritura detectada, Apache `configtest` fallido. Criterios de **volver atrás** después: `verificar.sh` con fallos, 5xx sostenidos, el administrador no entra, Flutter/trabajadores afectados, un informe no autorizado entrega enlace.

## Qué NO hacer
Ejecutar E3 sin autorización; añadir `USER_ADMIN_WRITES` o `AUDIT_LOG_MODE` al `.env` (no tienen efecto en esta versión y confundirían el preflight); usar `usuarios.sh informes --agregar` (autorizar un informe es otra decisión: la hace quien responde por los datos); copiar archivos a mano sobre `/opt/cervesur-react-admin`; reutilizar usuarios, claves, JWT o cookies de la QA 8094.
