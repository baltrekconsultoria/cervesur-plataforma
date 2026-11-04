# Entorno React de ESCRITURA ADMINISTRATIVA de QA (modo `admin_escritura`), aislado, solo por túnel SSH

> **DESCARTADO (UX-08H.1).** Por decisión del proyecto **no se instala** este entorno QA (puerto 8095) ni su paquete. Se conserva solo como referencia técnica. La liberación sigue `docs/UX-08H1-LIBERACION-DIRECTA-PRODUCCION.md` (E0 → migraciones → publicación con escrituras apagadas → activación gradual en 8443).


Paquete **instalable pero NO instalado** del entorno de QA para el modo de backend `admin_escritura` (UX-08H, fase 2): administración **con escrituras** (empresas, informes, accesos,
usuarios), cada una tras su propio interruptor, con auditoría obligatoria. Es una bifurcación del kit ya ensayado `deploy/qa-admin-aislado/` (mismas puertas de seguridad: puerto explícito,
aborta sin cambios, marcas de instalación, nunca borra respaldos, reversión completa). Los datos son **100 % sintéticos**: nada usa ni toca `cervesur_portalbi`.

> **AVISO — nada de este paquete se ha ejecutado en ningún servidor real.** Solo se ensayó en Docker (`ensayo-docker.mjs`).
> **`admin_escritura` jamás debe configurarse en 443 ni 8443.** Ni `CERVESUR_MODE=admin_escritura`, ni `ADMIN_WRITES_*`, ni `USER_ADMIN_WRITES` en los portales publicados: la simulación de la instalación lo comprueba y da NO-GO si no puede demostrarlo.
> `USER_ADMIN_WRITES` **no debe existir** en el `.env` de este entorno (el modo no lo usa; `verificar.sh` lo exige ausente).

## 1. Arquitectura

| Elemento | Valor (todos nuevos; el instalador comprueba que NO existen) |
|---|---|
| Puerto | `127.0.0.1:<puerto>` (nunca `0.0.0.0`), **siempre explícito**: `--puerto N`. Candidato: **8095**, pendiente de verificar en el servidor. Se rechazan **8090-8093** (ocupados) y **8094** (reservado al QA administrativo aislado), 80, 443, 8443, 8088, 8089, 22, 3306 y 33060 |
| Sitio Apache | `cervesur-react-escritura-qa` (`Listen 127.0.0.1:<puerto>` dentro del propio sitio; no se edita `ports.conf`) |
| Frontend | `/var/www/cervesur-react-escritura-qa/public` (`build:admin-write`, host `localhost`) |
| Backend PHP | `/opt/cervesur-react-escritura-qa/backend` (`.env` 640 `root:www-data`) |
| Snippets Apache | `/etc/apache2/cervesur-react-escritura-qa/` |
| Estado / sesiones | `/var/lib/cervesur-react-escritura-qa` (el modo usa token opaco en la base de estado; no hay cookie de sesión PHP; el nombre `CERVESUR_REACT_ESCRITURA_QA` queda reservado) |
| Bases | `cervesur_qa_escritura` (negocio, DDL productivo + 8 UNIQUE) y `cervesur_qa_escritura_estado` (lista blanca, sesiones, intentos) |
| Cuentas MySQL (`@127.0.0.1`) | `cervesur_qa_esc_w` (escritura, plantilla `UX-08H-cuenta-escritura-produccion.sql.template`), `cervesur_qa_esc_auth` y `cervesur_qa_esc_cat` (solo lectura por columnas, `piloto-admin/01`), `cervesur_qa_esc_estado` (solo la base de estado, `piloto-admin/02`) |
| Respaldos / línea base | `/var/backups/cervesur-react-escritura-qa/` (**nunca se borra**) |

Los nombres de cuenta del SQL original (`..._adm`) se renombran **en memoria** al instalar; los archivos de `database/` no se editan. Contraseñas y secretos se generan en el servidor y se escriben solo en el `.env` (640): no se imprimen.
`.env` generado (plantilla sin valores: `env-qa-escritura.example`): `CERVESUR_MODE`/`CERVESUR_REQUIRED_MODE=admin_escritura`, `CERVESUR_INSECURE_TESTS=SI` (HTTP de loopback por túnel; exclusivo de la QA y solo con una base no protegida),
`DB_*` (cuenta de escritura), `AUTH_DB_*`, `STATE_DB_*`, `CERVESUR_PILOT_SECRET`, `CERVESUR_PILOT_VISOR_IDS=31`, `JWT_SECRET` propio (64 hex, nunca el de Flutter), `AUDIT_LOG_MODE=required`, `REACT_STRICT_VIEWS=1`,
`ADMIN_WRITES_{EMPRESAS,REPORTES,ACCESOS,USUARIOS,USUARIOS_SENSIBLE}` y `ADMIN_USER_REACTIVATE_HOURS=24`. Solo el administrador sintético `syn001` está en la lista blanca: los demás usuarios (aunque tengan clave correcta) no pueden entrar.

## 2. Empaquetar (equipo de desarrollo, Git Bash)
```bash
node deploy/qa-escritura/empaquetar.mjs --out "$TEMP/cervesur-paquetes"            # compila build:admin-write (localhost) en una COPIA temporal y empaqueta
node deploy/qa-escritura/empaquetar.mjs --bundle <dir> --out <carpeta>              # usa una compilación admin-write ya hecha (debe admitir localhost)
node deploy/qa-escritura/empaquetar.mjs --sin-compilar --out <carpeta>               # lee frontend/dist-admin-write sin modificarla (si admite localhost)
```
`--out` debe estar fuera del repositorio. Resultado: `cervesur-react-escritura-qa-<commit>-<fecha>.tar.gz` + `.sha256`; dentro, `SHA256SUMS`. Contiene backend, frontend, las SQL necesarias (migraciones UX-08B, cuentas de lectura, base de estado, plantilla de escritura) y los scripts.
**Sin `.env`, sin pruebas, sin secretos.** La compilación nunca sobrescribe `frontend/dist-admin-write` (puede ser la de producción, con otro host).

## 3. Instalación (en el servidor, SOLO tras aprobación expresa)
1. Copiar tarball y `.sha256`; `sha256sum -c *.tar.gz.sha256`; extraer y entrar en la carpeta.
2. **Simulación (solo lectura):** `sudo bash scripts/instalar.sh --simular --puerto 8095` → `RESULTADO: GO` o `NO-GO`. No escribe nada.
3. **Instalación:** `sudo bash scripts/instalar.sh --puerto 8095 [--escrituras usuarios,empresas] [--aplicar-migracion-grupo-nullable]`. Pide `INSTALAR-QA-ESCRITURA`. Por defecto solo `usuarios` arranca encendido; lo sensible siempre apagado.
4. Se imprimen **una vez** las claves sintéticas (`syn001` admin; `syn005`, `syn006`, `syn007` no pueden entrar). Guardarlas en un gestor. `verificar.sh` corre al final.
5. Si un paso falla, se revierte **solo lo creado por ese intento**.

Túnel: `ssh -N -L 18095:127.0.0.1:8095 <usuario>@<servidor>` y abrir `http://localhost:18095` (el frontend solo admite `localhost`).

## 4. Verificación y GO / NO-GO
`sudo CERVESUR_QAE_ADMIN_PASSWORD=... bash scripts/verificar.sh` (opcionales: `CERVESUR_QAE_USUARIO_PASSWORD`, `CERVESUR_QAE_USER_PASSWORD`). Sin la clave del administrador las pruebas autenticadas se **omiten** y el resultado es NO-GO. Comprueba, entre otras:
- `/api/ping` responde (el modo arranca válido con la bandera de QA) y no hay cookies; `GET /api/admin/capacidades` (con sesión) refleja exactamente los interruptores del `.env`.
- Toda escritura con su interruptor **apagado** da `403 escritura_desactivada`; una ruta fuera de la tabla cerrada da `403 admin_escritura`.
- `SHOW GRANTS` de la cuenta de escritura **sin** `DELETE`, `ALL PRIVILEGES`, `GRANT OPTION` ni `TRIGGER`; no lee `user.password`; `audit_log` solo `SELECT`/`INSERT`, con sus **2 triggers** de solo inserción.
- Una escritura real (editar el usuario 40) deja **una fila** `user.update` en `audit_log` (si `usuarios` está apagado esa prueba se omite → NO-GO hasta encenderlo).
- `USER_ADMIN_WRITES` ausente del `.env`; los demás portales idénticos a la línea base.

## 5. Interruptores de escritura
```bash
sudo bash scripts/interruptor-escrituras.sh --estado
sudo bash scripts/interruptor-escrituras.sh --encender empresas|reportes|accesos|usuarios|usuarios-sensible
sudo bash scripts/interruptor-escrituras.sh --apagar empresas|reportes|accesos|usuarios|usuarios-sensible|todo
sudo bash scripts/interruptor-escrituras.sh --horas 24        # ADMIN_USER_REACTIVATE_HOURS, 0..720
```
Cambia **solo** el `.env` de esta instancia (escritura atómica, 640 `root:www-data`) y no recarga Apache: `mod_php` relee el `.env` en cada petición. Si tras el cambio `/api/ping` no responde, restaura el `.env` anterior.
`usuarios-sensible` (rol y contraseña) exige `usuarios`; apagar `usuarios` apaga también lo sensible. `scripts/comprobar-portales-publicados.sh` es la comprobación de solo lectura de que 443/8443 no tienen escrituras ni `admin_escritura`.

## 6. Actualización
```bash
sudo CERVESUR_QAE_ADMIN_PASSWORD=... bash scripts/actualizar.sh --simular          # comprueba todo y no escribe nada
sudo CERVESUR_QAE_ADMIN_PASSWORD=... bash scripts/actualizar.sh                    # pide ACTUALIZAR-QA-ESCRITURA
sudo CERVESUR_QAE_ADMIN_PASSWORD=... bash scripts/actualizar.sh --revertir /var/backups/cervesur-react-escritura-qa/actualizacion-<sello>
```
Antes de reemplazar crea y **verifica** un respaldo (`public.tgz`, `backend.tgz`, `SHA256SUMS` con ambos hashes, estructura y número de archivos, permisos 700/600, sin `.env`); si no se puede verificar, **no se toca nada**. Reemplaza solo `public/` y `backend/` (el `.env`, con sus interruptores, las bases y Apache no cambian),
comprueba la sintaxis PHP de todo el backend del paquete antes de empezar, ejecuta `verificar.sh` y la identidad en disco frente al `SHA256SUMS` y compara la huella de los demás portales. Si algo falla, **vuelve sola** a la versión anterior y la verifica. Los respaldos no se borran jamás.

## 7. Reversión y desinstalación
```bash
sudo bash scripts/revertir.sh                  # pide REVERTIR-QA-ESCRITURA
sudo bash scripts/revertir.sh --conservar-base # deja las bases y las cuentas (con su marca)
```
Elimina **solo** lo que el instalador registró y que conserva la marca de ESA instalación (sitio, snippets, carpetas, las dos bases, las cuatro cuentas); sin marca se niega. No toca otros sitios, bases, cuentas, módulos ni respaldos; deja un registro en `/var/backups/cervesur-react-escritura-qa/revertir-<fecha>.log` y compara el servidor con la línea base.

## 8. Ensayo en Docker
`node deploy/qa-escritura/ensayo-docker.mjs` (réplica Ubuntu 24.04 + Apache + MySQL 8 con Flutter simulado y señuelos 443/8443/8088/8089/8090-8094 y `cervesur_portalbi`). Recorre instalar → verificar → escritura real por la API → interruptores → actualizar (`--simular`, real, fallo forzado con vuelta automática, respaldo corrupto, paquete roto) → revertir la actualización → desinstalar, y exige que Flutter, los directorios de 443/8443 y la base señuelo queden **byte a byte idénticos**.
Inyección de fallos solo para ensayos (`CERVESUR_QAE_INYECTAR_FALLO`): únicamente hace que un paso FALLE; nunca relaja una comprobación.

## 9. Lo que el paquete NUNCA hace
Conectarse a otro servidor; tocar `cervesur_portalbi` o cualquier otra base; conceder privilegios sobre otras bases; usar `ALL PRIVILEGES`, `DELETE`, `GRANT OPTION` o `TRIGGER` en la cuenta de escritura; guardar o imprimir contraseñas o secretos; abrir puertos en la red; reiniciar Apache; habilitar/deshabilitar módulos; modificar Flutter, 443, 8443, 8088, 8089 u 8090-8094; activar `admin_escritura`, `ADMIN_WRITES_*` o `USER_ADMIN_WRITES` en otro portal; borrar respaldos; usar el id real 72.

## 10. Suposiciones que el ensayo no puede probar
La cuenta `@127.0.0.1` debe coincidir con las conexiones TCP de PHP; `ALTER USER ... COMMENT` requiere MySQL ≥ 8.0.21; con `log_bin=1` y `log_bin_trust_function_creators=0` crear los triggers exige `SUPER` a la cuenta administradora (la simulación lo comprueba);
las migraciones las aplica el administrador de MySQL, nunca la cuenta de la aplicación; los portales 443/8443 reales se protegen con la huella genérica de `/etc/apache2` y de `/var/www`/`/opt`, no por nombre.
