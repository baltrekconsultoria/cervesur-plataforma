# Cervesur · Fase 2A del administrador React (UX-08H.1) · Procedimiento del operador

> **Estado: PREPARADO. Nada de esto se ha ejecutado en el servidor real.** Se ensayó solo en Docker con datos sintéticos (`node deploy/produccion-escritura/ensayo-docker.mjs`).
> Cada etapa exige **autorización expresa y separada**. Todos los scripts corren **en el servidor, como root (`sudo`)** y tienen `--ayuda`.

Este paquete actualiza el administrador publicado (8443 / 8093; instalado: fase 1 `3f859210c5c4`, modo `piloto_admin`, solo lectura) a la **fase 2A**: Empresas, Informes y Accesos con
escrituras auditadas, **abiertas de una en una**. **Usuarios (fase 2B) sigue bloqueado**: el backend lo niega en tres capas sobre la base productiva y estos scripts además **se niegan** a
escribir `ADMIN_WRITES_USUARIOS` / `ADMIN_WRITES_USUARIOS_SENSIBLE` y las marcan como **ERROR** si las encuentran en el `.env`.

No usa el `revertir.sh` del piloto (es un desinstalador). No hay instalador: esto **actualiza** lo ya instalado.

## 1. Orden de los pasos

| # | Quién | Qué | Comando | Toca |
|---|---|---|---|---|
| 0 | operador | Descomprimir y comprobar el paquete | `sha256sum -c <paquete>.tar.gz.sha256` · `tar xzf` · `cd <carpeta>` | nada |
| E0 | operador | Auditoría de solo lectura (kit `e0/`) | `sudo bash e0/e0-auditoria-fase2a.sh --ayuda` | nada |
| 1 | operador | Preflight (go/no-go) | `sudo bash scripts/preflight-fase2a.sh` | nada |
| 2 | DBA | Respaldo de tablas y **migraciones M1/M2 + cuenta mínima** (kit `migraciones/`, **no** estos scripts) | ver `migraciones/` | base de datos |
| **A1** | operador | Código nuevo, **`.env` sin cambios** (sigue `piloto_admin`) | `sudo bash scripts/actualizar.sh --simular` y luego `sudo bash scripts/actualizar.sh` | `public/` y `backend/` |
| **A2** | operador | `.env` → `admin_escritura` con **todo apagado** | `sudo bash scripts/modo-escritura.sh --simular --archivo-clave <archivo>` y luego sin `--simular` | `.env` |
| (Apache) | operador, **otra autorización** | Permitir `PUT`/`DELETE` en el Apache del administrador (ver §5) | `sudo bash scripts/apache-metodos-escritura.sh --simular` y luego sin `--simular` | 1 línea de Apache |
| **B1** | operador | Encender **Empresas** + una operación autorizada + comprobar auditoría | `interruptores.sh --habilitar empresas` … | `.env` y 1 fila real |
| **B2** | operador | Encender **Informes** (solo con B1 verificado) | `interruptores.sh --habilitar reportes` … | idem |
| **B3** | operador | Encender **Accesos** (solo con B2 verificado) | `interruptores.sh --habilitar accesos` … | idem |

Credenciales de las verificaciones (**nunca por argumentos**): variables de entorno de un administrador autorizado del 8443:
`sudo CERVESUR_E_USUARIO=… CERVESUR_E_CLAVE=… bash scripts/…` (o `read -rs` y `export` antes de `sudo -E`). Sin ellas, lo que necesita sesión sale como `OMIT` y se declara «verificación PARCIAL».

## 2. Qué hace cada script

* **`preflight-fase2a.sh`** — solo lectura. Identidad de lo instalado (debe ser **archivo por archivo** la fase 1, por el manifiesto `base/MANIFIESTO-FASE1-3f859210c5c4.sha256`), espacio,
  carpeta de respaldos escribible, puertos (8093 solo loopback; 80, 443, 8443, 8094), `apache2ctl configtest`, `php -l` del candidato, integridad y limpieza del paquete, Flutter/443/8094
  respondiendo, y que el `.env` no tenga `USER_ADMIN_WRITES` ni `ADMIN_WRITES_USUARIOS*`. Avisa (no bloquea) de migraciones pendientes y de Apache (§5). Código 0 = GO.
* **`actualizar.sh`** (**A1**) — `--simular` | real | `--revertir`. Exige que lo instalado sea la fase 1, pide escribir `ACTUALIZAR-ADMIN-FASE2A`, hace un **respaldo con SHA-256 verificado** de
  `public/` y `backend/` (`/var/backups/cervesur-react-admin/actualizacion-<sello>`, 700/600, sin `.env`) **antes** de reemplazar nada, reemplaza por renombrado, espera a OPcache y verifica el
  comportamiento de la fase 1 con el código nuevo (login y lecturas bien, **toda** ruta de escritura en 403, `/api/admin/capacidades` **inaccesible**), que el `.env` y Apache quedaron idénticos y que
  Flutter/443/8094 no cambiaron. **Si algo falla, restaura solo el código anterior.** No borra respaldos.
* **`modo-escritura.sh`** (**A2**) — `--simular` | real | `--revertir-env`. Comprueba antes de tocar: A1 hecho, migraciones aplicadas (`audit_log` + 2 triggers), la cuenta de escritura existe, **se
  autentica** con la clave de `--archivo-clave` y su `SHOW GRANTS` pasa **las mismas reglas que el backend** (`admin_write_grants_violations`) más las de la fase 2A (sin `DELETE`/`ALL`/`GRANT OPTION`/
  `TRIGGER`/DDL; `user` solo `UPDATE(last_login)`; `audit_log` solo `SELECT`+`INSERT`). La clave se lee **solo** de un archivo de root (modo 600; `CERVESUR_ESCRITURA_PASS=…` o una línea sola),
  nunca por argumentos ni se imprime. Respalda el `.env` (600, con sello, no se borra), lo edita **atómicamente** (`CERVESUR_MODE`=`CERVESUR_REQUIRED_MODE`=`admin_escritura`, `DB_USER`/`DB_PASS`,
  `AUDIT_LOG_MODE=required`, `REACT_STRICT_VIEWS=1`, los 3 `ADMIN_WRITES_*`=0, `JWT_SECRET` propio y distinto de los demás `.env` legibles, y **elimina** las claves de usuarios/`INSECURE_TESTS`),
  conserva `AUTH_DB_*`, `STATE_DB_*`, `CERVESUR_PILOT_*` (la autorización del visor y DB031/id 72 no cambian) y verifica: arranque, login, capacidades **todas false**, escrituras 403
  `escritura_desactivada`, usuarios 403 `fase_2b_bloqueada`, lecturas. **Ante cualquier fallo restaura el `.env` anterior solo.**
* **`interruptores.sh`** (**Paso B**) — `--estado` · `--habilitar empresas|reportes|accesos` · `--marcar-verificado <módulo> --evidencia <archivo>` · `--apagar <módulo>` · `--apagar-todo`.
  Orden obligatorio; `usuarios` y `usuarios-sensible` se **rechazan siempre**. `--habilitar` respalda el `.env`, cambia **una** clave, verifica la etapa (`verificar-posdespliegue.sh --etapa <módulo>`
  `--linea-base`) y, si falla, restaura. No recarga Apache (mod_php relee el `.env` en cada petición).
* **`verificar-posdespliegue.sh --etapa A1|A2|empresas|reportes|accesos`** — lista PASS/FAIL, solo lectura de datos. Las sondas de escritura usan siempre el **id inexistente 999999999** y el cuerpo
  `{}`, y en módulos encendidos solo `PUT`/`DELETE`/reactivar (jamás un alta): no pueden escribir aunque una puerta fallara. Con `--confirmar-operacion <acción> --tabla <t> --delta <n>`
  lee `audit_log` por el socket del DBA y exige **una** fila nueva con esa acción y que `<t>` cambió exactamente `<n>` desde la línea base (la toma `--habilitar`). Nunca imprime `before_json/after_json`.
* **`apagar-escrituras.sh`** — emergencia, sin dependencias de `lib.sh`: pone los 3 `ADMIN_WRITES_*` en 0 y quita las claves de usuarios de forma atómica en segundos; antes guarda evidencia en
  `/var/backups/cervesur-react-admin/evidencia-apagado-<sello>` (700: nombres de clave y estado de los interruptores, hora, cola **sanitizada** del log de Apache); verifica `capacidades` si hay
  credenciales (si no, **lo dice**). No toca datos ni auditoría.
* **`revertir-codigo.sh`** — restaura `public/` y `backend/` desde un respaldo verificado de `actualizar.sh` **y**, si el `.env` está en `admin_escritura`, el `.env` de `piloto_admin` que dejó A2 (el
  código viejo no conoce el modo nuevo); si no hay un respaldo de `.env` válido **se niega** y explica. Verifica el comportamiento de la fase 1. No toca la base, Flutter ni trabajadores.
* **`comprobar-otros-portales.sh`** — Flutter (80), trabajadores (443) y QA (8094): código HTTP + SHA-256 de la página de entrada; `--guardar`/`--comparar` antes y después.
* **`apache-metodos-escritura.sh`** — **opcional y aparte** (§5). Ningún otro script lo llama.

## 3. Qué NO hacen los scripts

* **E0** (auditoría) y **las migraciones M1/M2 y la cuenta de escritura** los ejecuta el DBA con los kits `e0/` y `migraciones/`. Estos scripts solo comprueban que existan.
* No hacen `push`, ni `git`, ni tocan **Apache** (salvo `apache-metodos-escritura.sh`, a petición y con su propia autorización), Flutter (80), trabajadores (443) ni la QA (8094).
* No habilitan nada de **Usuarios**. No hay forma de hacerlo con este kit.
* No hay rollback automático de **datos** una vez habilitadas las escrituras (§4, clase 3).

## 4. Tres clases de recuperación (distintas)

| Clase | Qué | Cómo | Datos |
|---|---|---|---|
| **1. Restaurar código** | vuelve la fase 1 (con su `.env` de `piloto_admin`) | `sudo bash scripts/revertir-codigo.sh` (`--simular` primero) | no toca BD |
| **2. Apagar escrituras** (segundos) | `ADMIN_WRITES_*` a 0 | `sudo bash scripts/apagar-escrituras.sh` o `interruptores.sh --apagar-todo` | no toca datos ni auditoría; conserva evidencia |
| **3. Recuperar datos** | **manual, nunca automático** | respaldo de tablas (`migraciones/`) + `audit_log` (qué cambió, quién, antes/después sin secretos); los `.down.sql` solo con autorización y segunda confirmación (`audit_log` es evidencia) | sí |

Primero siempre la clase 2. Las bajas son lógicas (se deshacen reactivando).

## 5. Apache: `PUT` y `DELETE` (hallazgo del ensayo)

El Apache instalado del administrador (`/etc/apache2/cervesur-react-admin/react-usuarios-common.conf`, usado por 8093 **y** por la publicación 8443) contiene
`<Location "/"> <LimitExcept GET POST> Require all denied`. Con eso **`PUT` y `DELETE` reciben 403 de Apache antes de llegar a PHP**, y la interfaz usa `PUT` (editar empresas e informes) y `DELETE`
(bajas, revocaciones). Consecuencias:

* **A1 y A2 funcionan igual** (todo sigue en 403; `verificar-posdespliegue` lo declara como «403 de Apache»).
* **El Paso B no puede funcionar** hasta cambiar esa línea. `interruptores.sh --habilitar` **se niega** mientras Apache rechace `PUT`/`DELETE` y no cambia nada.
* El cambio es de **una línea** (`<LimitExcept GET POST>` → `<LimitExcept GET POST PUT DELETE>`; `PATCH`/`OPTIONS`/`TRACE` siguen rechazados; la restricción de redes del 8443 sigue aplicándose).
  `scripts/apache-metodos-escritura.sh` lo hace con respaldo SHA-256, `configtest`, recarga **suave**, verificación y vuelta atrás automática, y tiene `--revertir`. **Requiere su propia autorización**
  (el kit, por defecto, no modifica Apache).

## 6. Una operación real por módulo (Paso B)

1. `sudo -E bash scripts/interruptores.sh --habilitar empresas` → queda encendido, verificado y con la **línea base** de recuentos/auditoría.
2. El responsable autorizado hace **una** operación real en la interfaz (sin inventar datos).
3. `sudo -E bash scripts/verificar-posdespliegue.sh --etapa empresas --confirmar-operacion <acción> --tabla <tabla> --delta <n> | tee evidencia-empresas.txt`
   (acciones de `audit_log`: `empresa.create`, `reporte.create`, `empresa_reporte.create`…; para editar/desactivar `--delta 0`/`-1`/`1` según el caso).
4. `sudo -E bash scripts/interruptores.sh --marcar-verificado empresas --evidencia evidencia-empresas.txt` → ahora se permite `--habilitar reportes`; y así con accesos.
5. Ante cualquier duda: `sudo bash scripts/apagar-escrituras.sh`.

Checklist de QA en 8443 por etapa: ver `docs/UX-08H1-LIBERACION-DIRECTA-PRODUCCION.md` §11. Las diferencias frente a `3f859210c5c4` están en `DIFERENCIAS-vs-3f859210c5c4.txt` (junto al paquete).

## 7. Construcción y ensayo (en el equipo de desarrollo, no en el servidor)

```
node deploy/produccion-escritura/empaquetar.mjs --host=192.168.200.12 --out <carpeta fuera del repo> [--sin-compilar] [--permitir-arbol-sucio] [--base <SHA256SUMS de la fase 1>]
node deploy/produccion-escritura/ensayo-docker.mjs        # ENSAYO_ADMIN_BASE_TGZ=<tar.gz de la fase 1>
```
`empaquetar.mjs` se niega con árbol sucio (salvo `--permitir-arbol-sucio`, solo ensayos: nombre `-con-cambios`, `produccion:false`), compila en una copia temporal (no toca
`frontend/dist-admin-write`), inspecciona el resultado (sin `.env`, claves, certificados, QA, `syn###`, secretos) y produce `.tar.gz` + `.sha256` + `RELEASE.json` + las diferencias.
