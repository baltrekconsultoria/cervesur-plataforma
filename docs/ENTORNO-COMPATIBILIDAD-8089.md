# UX-07D.2C · Entorno aislado de compatibilidad productiva (React en 127.0.0.1:8089)

Estado: **instalado en el servidor de pruebas por el operador y con QA manual aprobado (ver §8); ensayado además en Docker.** Sigue sin ser producción.
Sustituye `<servidor>` por la dirección del servidor; este documento no contiene IP privadas, contraseñas ni valores de `.env`.

## 1. Arquitectura: tres portales separados

| Portal | Acceso | Archivos | Base de datos | Cookie PHP | Estado |
|---|---|---|---|---|---|
| **Flutter productivo** | HTTP puerto 80 | `/var/www/cervesur` | `cervesur_portalbi` (productiva) | la suya | **No se toca** |
| **React UX-07D.1** | túnel → `127.0.0.1:8088` (`localhost:18089`) | `/var/www/cervesur-react-prueba`, `/opt/cervesur-react-prueba` | `cervesur_react_prueba` | `CERVESUR_REACT` | **No se toca** (QA manual ya aprobado) |
| **React UX-07D.2C (nuevo)** | túnel → `127.0.0.1:8089` (`localhost:18090`) | `/var/www/cervesur-react-compat`, `/opt/cervesur-react-compat`, `/etc/apache2/cervesur-react-compat`, `/var/lib/cervesur-react-compat` | `cervesur_react_compat_qa` (usuario `cervesur_react_compat`) | `CERVESUR_REACT_COMPAT` | Por instalar |

El entorno nuevo es 100 % sintético, no se conecta al MySQL productivo y solo es accesible por VPN + túnel SSH. **No es producción.**

**Sin colisiones** (comprobado por código y por el ensayo): ningún nombre, puerto, ruta, base, usuario ni cookie coincide ni se solapa con los de Flutter o los de 8088. El instalador lo revalida
(`verificar_no_colision`) y además exige que sus carpetas, sitio, puerto, base y usuario no existan; si algo existe, **aborta sin modificar nada**.

Sus scripts son **propios** (`deploy/tunel-ssh-compat/`): no se reutiliza ni se modifica `instalar.sh`/`revertir.sh` de 8088.

## 2. Aislamiento de sesiones y cookies

Las cookies del navegador no se separan por puerto: `localhost:18089` y `localhost:18090` comparten el mismo frasco. Por eso el sitio nuevo usa:

- Nombre de cookie propio `CERVESUR_REACT_COMPAT` (el de 8088 es `CERVESUR_REACT`; ninguno usa `PHPSESSID`).
- Almacenamiento de sesiones propio `/var/lib/cervesur-react-compat/sessions` (modo 700, `www-data`), con recolección propia (`gc_*`).
- Ajustes con `php_admin_*` (el código no puede cambiarlos): `HttpOnly`, `SameSite=Strict` y `Secure` **desactivado de forma explícita** (el túnel es HTTP de loopback; un `php.ini` global con `session.cookie_secure=1` no lo rompe).
- `open_basedir` propio: su PHP solo ve su backend, `/tmp` y su directorio de sesiones (no ve Flutter ni el `.env` de 8088).
- Secretos propios (`JWT_SECRET`, contraseña de MySQL) y base propia: un JWT de un portal no sirve en el otro.

Probado: cada sitio emite su cookie; iniciar sesión en uno **no sobrescribe** la del otro (mismo frasco, valores intactos); los JWT cruzados dan 401; cerrar sesión en 8089 no invalida 8088;
los archivos de sesión de cada uno están en su propio directorio.

## 3. Esquema sintético compatible

Tipos reales: `reporte.id INT UNSIGNED`, `reporte_id VARCHAR(50) NOT NULL`, `enlace VARCHAR(500) NOT NULL`, `user.username VARCHAR(50)`, `user.role VARCHAR(30)`, `user.session_version INT UNSIGNED`.
El sembrador (`sembrar-compat.php`) evalúa **dos estados** de `grupo_id`:

1. **Estado histórico (como producción):** `grupo_id VARCHAR(50) NOT NULL`. Se cargan los 95 informes (82 activos, 82 referencias y 82 grupos no UUID, longitudes máximas 50 y 26–36) y se guarda una huella de sus datos.
2. **Estado "Publicar en web" sin grupo:** migración **mínima y exclusiva de este esquema sintético**: `ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NULL` (conserva los 50 caracteres; no se reduce a 36).
   Se comprueba que el resto del esquema no cambia y que los datos históricos quedan **idénticos** (huella igual). **Esta migración no existe como archivo de producción y no se aplica en producción.**

Este trabajo **no concluye que la base productiva deba modificarse**: solo demuestra que la aplicación funciona con ambos estados. La decisión sobre producción sigue abierta hasta estudiar por completo lectura, escritura y Flutter.

El fixture es el de UX-07D.2B (`tests/isolated/productive_fixture.php`, ahora una biblioteca compartida con la suite de 54 pruebas) y **se autoverifica**: reproduce 76 usuarios (62 activos; `usuario` 62/61, `user` 10/0, `admin` 4/1), 26 empresas (6 activas), 95 informes (82 activos), relaciones 82/79, 103/100 y 102/93,
84 relaciones vigentes, 91 permisos efectivos, 61 usuarios con acceso, 79 informes con usuarios y 2 asignaciones de usuarios inactivos. Todos los valores son inventados; los enlaces ficticios no abren ningún informe real.
**Cuentas:** el instalador genera contraseñas aleatorias para `syn001` (administrador) y `syn005`–`syn007` (usuarios con informes) y las muestra una sola vez; las demás cuentas reciben un hash inutilizable.

## 4. Procedimiento (solo tras autorización)

Requisitos (los comprueba `--simular`): Apache con `alias`, `rewrite`, PHP y **`mod_headers` ya cargado** (lo habilitó el instalador de 8088; este instalador **no lo toca nunca**, ni al instalar ni al revertir);
PHP con `pdo_mysql`, `mbstring`, `json` y `openssl`; MySQL local en `127.0.0.1:3306` con acceso administrador y política `validate_password` MEDIUM (la contraseña generada es `Aa1!` + 48 hex); puerto 8089 y todos los recursos del §1 libres.

```powershell
# Windows: generar y copiar el paquete
node deploy/tunel-ssh-compat/empaquetar.mjs
scp dist-paquete\cervesur-react-compat-<id>.tar.gz usr_cervesur@<servidor>:~/
```
```bash
# Servidor
sha256sum cervesur-react-compat-<id>.tar.gz              # comparar con el SHA-256 que imprimió empaquetar.mjs
tar xzf cervesur-react-compat-<id>.tar.gz && cd cervesur-react-compat-<id>
sudo bash scripts/instalar.sh --simular                  # SOLO LECTURA
sudo bash scripts/instalar.sh                            # plan + hay que escribir INSTALAR-COMPAT
sudo bash scripts/verificar.sh
```
```powershell
ssh -N -L 18090:127.0.0.1:8089 usr_cervesur@<servidor>    # y abrir http://localhost:18090
```
```bash
sudo bash scripts/revertir.sh                            # pide escribir REVERTIR-COMPAT; --conservar-base deja la base
```

El instalador: respalda `/etc/apache2` y toma una **línea base** de Flutter, del React de 8088, de los módulos de Apache, de los puertos y de los metadatos de MySQL (solo nombres de bases y usuarios; nunca datos);
instala con confirmación explícita; usa **recarga suave** (nunca reinicia Apache); no habilita ni deshabilita módulos; no imprime secretos; ante cualquier fallo **revierte solo lo que creó**.
`revertir.sh` borra únicamente los recursos con nombre fijo del §1 (con salvaguardas contra rutas de otros portales) y **conserva siempre** los respaldos.

## 5. Qué se ensayó (Docker, sin servidor real) · 112 PASS / 0 FAIL

`node deploy/tunel-ssh-compat/ensayo-docker.mjs`: réplica Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (MEDIUM) con **tres portales a la vez** (Flutter simulado en el 80, React 8088 instalado con sus propios scripts, y el nuevo en 8089).

- **Preflight y colisiones:** sin `mod_headers`, sin `mbstring`, paquete alterado (SHA-256), base ya existente, usuario MySQL ya existente, puerto 8089 ocupado, carpeta y sitio ya existentes → el instalador **aborta con «No se modificó nada»**, no toca el recurso ajeno y el servidor queda idéntico a la línea base.
- **Fallo forzado** a mitad de instalación → reversión automática; **`mod_headers` sigue habilitado** y Apache conserva su proceso maestro.
- **Instalación:** `verificar.sh` 27 PASS / 0 FAIL (26 si el React de 8088 no está instalado); 80 escucha en todas las interfaces, 8088 y 8089 solo en `127.0.0.1` y desde otra máquina de la red se rechazan; secretos propios, sin fugas en logs ni respaldos.
- **Aislamiento:** archivos, configuración, cabeceras, respuestas, módulos, puertos, bases y usuarios MySQL de Flutter y de 8088 **idénticos** antes y después de instalar y de revertir 8089 (la única diferencia admitida es el `last_login` de la propia prueba de cookies en la base de 8088).
- **QA completo** (API PHP real + frontend React de producción + MySQL sintético; 56 comprobaciones): administrador, roles históricos (`usuario`, `user`, `admin`, inactivos), 82 informes activos, navegación por id numérico, catálogo por empresa, informe en dos empresas (una sola vez, sin perder asociaciones),
  edición de empresa, **edición de informes históricos sin tocar `reporte_id`/`grupo_id`/enlace**, **alta de «Publicar en web» con `grupo_id` NULL** y referencia UUID v4 propia, asociación, asignación individual, retiro, **acceso directo por URL rechazado tras la revocación**, ids malformados, rutas históricas por UUID y `/grupoid`,
  usuario desactivado con asignación activa (401 inmediato). Alta y edición de usuarios; la **desactivación se simula en la base** porque ni el backend ni React ofrecen esa función (no se añadió).
- **MySQL MEDIUM:** la réplica impone la política; la contraseña antigua solo hexadecimal da ERROR 1819; 300 de 300 contraseñas nuevas la cumplen.

Los enlaces de Power BI del fixture son **ficticios**: el QA comprueba que el iframe se construye con la URL esperada y bloquea las peticiones a `*.powerbi.com`; **no demuestra que Power BI funcione**.

**Defecto real encontrado por el QA:** el backend usa `mb_strlen()` al validar informes y la réplica no tenía `php8.3-mbstring` (la edición fallaba con un error fatal). Ahora el instalador **exige** la extensión y `verificar.sh` comprueba por Apache que la validación responde JSON.

## 6. Riesgos pendientes

1. **`grupo_id` en producción:** las pruebas cubren ambos estados, pero la decisión de si la base productiva necesita cambiar queda abierta (creación de «Publicar en web» nuevo sigue rechazada con `NOT NULL`).
2. **Parámetros de los enlaces reales y columnas no inventariadas:** el SQL de solo lectura de UX-07D.2B sigue sin ejecutarse contra producción.
3. **Dependencia de 8088:** `mod_headers` debe estar habilitado; si se revierte 8088 (que lo deshabilita), habría que habilitarlo con autorización antes de instalar 8089.
4. **PHP del servidor real:** el instalador comprueba las extensiones del PHP de línea de comandos y `verificar.sh` las confirma por Apache; si Apache usara otro PHP con otras extensiones, `verificar.sh` lo detectaría.
5. **mod_php comparte `www-data`** con Flutter y 8088: `open_basedir` protege al PHP del sitio nuevo, no al de los demás.
6. **Cualquier cuenta local** del servidor puede abrir `127.0.0.1:8089`; los datos son sintéticos y el acceso real requiere cuenta SSH.
7. **Sin HTTPS:** no usar credenciales ni datos reales; los enlaces «Publicar en web» son públicos para quien conozca la URL.
8. Compartir backend con Flutter sigue sin evaluarse (las validaciones de escritura de informes rechazan identificadores no UUID).

## 7. GO / NO-GO para instalar 8089

**GO**, bajo autorización explícita y con el operador ejecutando primero `instalar.sh --simular`. **NO-GO** para apuntar este entorno a la base productiva o exponerlo fuera del túnel.

## 8. Instalación real y QA manual (registro) · UX-07D.2C

Lo que sigue **lo informó el equipo de Cervesur**; esta sesión no tuvo acceso al servidor y no lo verificó directamente. Los ensayos propios (§5) se ejecutaron solo en Docker.

- **Paquete instalado** (artefacto de prueba, construido antes del commit): `cervesur-react-compat-334d91f45f71-con-cambios-20261028-224749Z.tar.gz`
  · SHA-256 `422eee39d1c3494aeaa657d495fe5d09549c77abcecb9a3d3ecb327d65332bf3`. Su `VERSION` dice `334d91f45f71-con-cambios` porque se generó desde `main` con cambios aún sin commit.
- **Resultado de la instalación:** `verificar.sh` **27 PASS / 0 FAIL**; Apache en `127.0.0.1:8089`, acceso por túnel `localhost:18090`, base `cervesur_react_compat_qa` con datos exclusivamente sintéticos.
- **QA manual funcional: aprobado por el usuario**, confirmado en conversación. No hay capturas incorporadas al repositorio y **no es un ensayo independiente de Codex**.
- **No se tocaron** Flutter (puerto 80) ni el React de 8088; esto último lo informa el operador (el verificador compara su línea base).

**Trazabilidad paquete ↔ código.** El **SHA-256** identifica el archivo instalado; el **commit de Git** (registrado en el Pull Request) identifica el código fuente: son identificadores distintos y no deben confundirse.
Se comprobó, sin tocar el servidor, que los archivos del paquete instalado coinciden con los del código versionado (ver la descripción del PR para el resultado). Reconstruir el paquete desde el commit publicado dará otro nombre (fecha-hora) y otro SHA-256.
