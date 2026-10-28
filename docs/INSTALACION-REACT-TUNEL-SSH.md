# Instalación de PRUEBA de React por túnel SSH (UX-07D.1B / UX-07D.1C)

Estado: **instalada y validada en el servidor de pruebas de Cervesur (ver §10 y §11).** Este documento describe el procedimiento y registra el resultado.
Sustituye `<servidor>` por la dirección del servidor; este documento no contiene IP privadas, contraseñas ni valores de `.env`.

> **Esto NO es producción.** El React instalado es una instalación de prueba con base de datos y usuarios sintéticos. **Flutter permanece operativo** y sin
> modificaciones. No existe dominio ni HTTPS: el portal **no está abierto a usuarios de la red corporativa**; solo se accede por **VPN + túnel SSH**.
> No se ejecutaron consultas de inventario contra la base productiva y **no se migraron usuarios ni informes productivos**.

## 1. Arquitectura

```
Windows (operador)                          Servidor (Ubuntu 24.04, Apache 2.4, PHP 8.3, MySQL 8)
 navegador ─ http://localhost:18089 ─ túnel SSH ─▶ 127.0.0.1:8088  VirtualHost "cervesur-react-prueba"
                                                       ├─ /            /var/www/cervesur-react-prueba/public   (React, solo lectura)
                                                       └─ /api         /opt/cervesur-react-prueba/backend      (PHP de prueba + su .env)
                                                                         └─ MySQL: base «cervesur_react_prueba» (datos sintéticos)
 Flutter (sin cambios): *:80  /var/www/cervesur  +  cervesur.conf  +  su .htaccess  +  su base productiva
```

- El sitio React escucha **solo en 127.0.0.1:8088**: no existe ningún socket nuevo en la red corporativa.
- El navegador ve `http://localhost:18089`. La compilación de servidor admite HTTP **únicamente en loopback** (`localhost`, `127.0.0.1`, `[::1]`);
  en cualquier otro host sigue exigiendo HTTPS y un dominio autorizado. **Esta protección no se relajó.**
- La API se consume del mismo origen (`/api`), sin CORS.
- Base, usuario MySQL, `.env`, `JWT_SECRET` y cookie de sesión (`CERVESUR_REACT`) son propios de la prueba. No se usa la base productiva.

## 2. Requisitos (se comprueban solos con `instalar.sh --simular`)

| Requisito | Detalle |
|---|---|
| Apache | configtest correcto; módulos `alias`, `rewrite` y PHP activos. `headers` se habilita **solo al instalar** (ver §6) |
| PHP | 8.3 con `pdo_mysql` (CLI y Apache) |
| MySQL | **servidor local** escuchando en `127.0.0.1:3306`, y un administrador accesible (`sudo mysql` por socket o `CERVESUR_MYSQL_ADMIN="mysql -u admin -p"`). Compatible con `validate_password` en política MEDIUM (la contraseña generada es `Aa1!` + 48 hex): la política **no se modifica** |
| Herramientas | `openssl`, `curl`, `ss`, `tar` |
| Recursos | ≥ 500 MB libres en `/var` y `/opt` (el sitio ocupa menos de 5 MB) |
| Libres | puerto 8088; carpetas `/var/www/cervesur-react-prueba`, `/opt/cervesur-react-prueba`, `/etc/apache2/cervesur-react-prueba`; base `cervesur_react_prueba`; usuario MySQL `cervesur_react` |
| Windows | Node ≥ 22.12 (solo para generar el paquete), cliente OpenSSH (`ssh`, `scp`) |

El servidor **no necesita Node**: el frontend se compila en el equipo del operador.

## 3. Generar el paquete (en Windows, sin tocar el servidor)

```powershell
cd <repositorio>\frontend
npm ci                                          # solo si faltan dependencias (ya instaladas en el entorno de desarrollo)
cd ..
node deploy/tunel-ssh/empaquetar.mjs            # compila con CERVESUR_ALLOWED_HOSTS=localhost, verifica y crea dist-paquete\*.tar.gz
```

Imprime la ruta y el **SHA-256** del `.tar.gz`. El nombre incluye commit y fecha-hora UTC (`<id>`), así que cada paquete es único. El paquete trae `public/`, `backend/` (sin `.env` ni pruebas), `apache/`, `scripts/`,
`database/migrations/UX-07C…` , `VERSION` y `SHA256SUMS`. Los scripts y configuraciones van en LF.

## 4. Instalación (la ejecuta el operador, solo tras autorización)

```powershell
# Windows: copiar el paquete al directorio personal (solo escribe en /home de usr_cervesur)
scp dist-paquete\cervesur-react-prueba-<id>.tar.gz usr_cervesur@<servidor>:~/
```

```bash
# Servidor
sha256sum cervesur-react-prueba-<id>.tar.gz          # comparar con el SHA-256 que imprimió empaquetar.mjs
tar xzf cervesur-react-prueba-<id>.tar.gz && cd cervesur-react-prueba-<id>
sha256sum -c SHA256SUMS --quiet && echo íntegro

sudo bash scripts/instalar.sh --simular     # SOLO LECTURA: comprobaciones previas; no cambia nada
sudo bash scripts/instalar.sh               # comprobaciones + plan + hay que escribir INSTALAR
```

Qué hace `instalar.sh`, en este orden, y se detiene al primer error revirtiendo automáticamente:

1. Comprobaciones previas (solo lectura). Si fallan: «No se modificó nada».
2. Respaldo de `/etc/apache2` y **línea base de Flutter** en `/var/backups/cervesur-react-prueba` (modo 700): hash de `cervesur.conf` y `.htaccess`,
   hash del árbol `/var/www/cervesur` y nombres de las cabeceras HTTP de su página principal.
3. Copia frontend y backend de prueba a carpetas nuevas (root:root, sin escritura para Apache).
4. Crea la base `cervesur_react_prueba` y el usuario `cervesur_react` (`@localhost` y `@127.0.0.1`) con contraseña aleatoria; escribe el `.env`
   (640 root:www-data) con `DB_HOST=127.0.0.1` y un `JWT_SECRET` aleatorio. Los secretos no se muestran.
5. Siembra esquema + migración UX-07C + datos sintéticos **solo en esa base** (el sembrador se niega si el host no es local, la base no se llama
   `cervesur_react_prueba` o no está vacía) y reduce el usuario a `SELECT, INSERT, UPDATE, DELETE`.
6. Instala el sitio Apache, habilita `mod_headers` si no lo estaba, `configtest` y **recarga suave** (`graceful`; nunca reinicia).
7. Ejecuta `verificar.sh` y muestra **una sola vez** las contraseñas de las cuentas sintéticas `admin`, `usera`, `userb` (guárdelas en un gestor).

## 5. Acceso y verificación

```powershell
ssh -N -L 18089:127.0.0.1:8088 usr_cervesur@<servidor>      # dejar abierta; el navegador usa http://localhost:18089
```

```bash
sudo bash scripts/verificar.sh      # 18 comprobaciones: loopback, rutas de React, /api, 401/403/404 JSON, endpoint heredado 403, archivos sensibles, CSP, Flutter intacto
```

Informes con Power BI real: inicie sesión como `admin` y registre un enlace público «Publicar en web» desde la interfaz; el navegador del operador
carga `https://app.powerbi.com` (el servidor no necesita Internet). Los dos informes sembrados usan enlaces ficticios.

## 6. `mod_headers` (cambio global, controlado)

Habilitar `headers` es la única modificación global de Apache. Es necesaria para la CSP y las cabeceras de seguridad. Riesgo: si Flutter tiene
directivas `Header` dentro de `<IfModule mod_headers.c>`, hoy están inactivas y se activarían. `instalar.sh` lo **detecta antes de tocar nada** y se
niega (hay que revisar esos archivos y repetir con `CERVESUR_ACEPTAR_HEADERS=SI` solo si es aceptable). `revertir.sh` lo deshabilita si lo habilitó el instalador.

## 7. Reversión

```bash
sudo bash scripts/revertir.sh                    # pide escribir REVERTIR; elimina sitio, carpetas, base y usuario de PRUEBA
sudo bash scripts/revertir.sh --conservar-base   # igual, pero deja la base de prueba
```

Deshabilita el sitio, borra su configuración, deshabilita `headers` si lo habilitó el instalador, `configtest` + recarga suave, elimina únicamente las dos
carpetas y la base/usuario de prueba, y compara Flutter con la línea base. **Conserva siempre** `/var/backups/cervesur-react-prueba`. Es idempotente.
Cierre también el túnel en Windows (Ctrl+C). No toca `/var/www/cervesur`, `cervesur.conf`, ninguna base productiva ni la configuración SSH.

## 8. Qué se ensayó (Docker, sin servidor real)

`node deploy/tunel-ssh/ensayo-docker.mjs` levanta una réplica (Ubuntu 24.04, Apache 2.4.58, mod_php 8.3, MySQL 8 locales, Flutter simulado en el 80 con
`cervesur.conf` y `.htaccess` propios, `headers` desactivado, `socat` como túnel) y ejecuta los mismos scripts. Resultado: **67 PASS / 0 FAIL**:
preflight y rechazo ante `Header` de Flutter sin dejar rastro; fallo forzado a mitad de instalación con reversión automática; instalación; Flutter idéntico
(configuración, archivos, cabeceras); 8088 solo en 127.0.0.1 e inalcanzable desde otra máquina de la red; permisos del `.env` y del usuario MySQL; migración
UX-07C solo en la base de prueba; `open_basedir` (el PHP de la prueba no lee el backend de Flutter); cookie `CERVESUR_REACT` HttpOnly + SameSite=Strict;
endpoint heredado de tokens 403; navegador real en `http://localhost` con login y administración; reversión completa e idempotente.
Defectos que el ensayo detectó y se corrigieron (el último, ERROR 1819, lo detectó la instalación real y ahora lo cubre una regresión con la política MEDIUM de MySQL): `grep -r` no seguía los enlaces de `sites-enabled/` (la detección de `Header` no habría funcionado) y el
backend no conectaba por socket cuando `www-data` no puede entrar en `/var/run/mysqld` (ahora conecta por TCP a `127.0.0.1`); y la contraseña del usuario MySQL (solo hexadecimal) incumplía `validate_password` MEDIUM (ERROR 1819): ahora es `Aa1!` + 48 hex, se autocomprueba y la réplica de ensayo impone la misma política.

## 9. Riesgos pendientes

- **mod_php comparte usuario** (`www-data`) con Flutter: `open_basedir` protege al PHP de la prueba, pero el PHP de Flutter podría leer el `.env` de la prueba. Aceptable
  para una prueba con datos sintéticos; en producción usar php-fpm con pools separados.
- **Endpoint heredado de tokens OAuth**: sigue existiendo en el backend PHP. Está bloqueado en este sitio, pero su compatibilidad con Flutter debe evaluarse antes
  de cualquier despliegue productivo (no se modifica ni elimina).
- **MySQL remoto o con `skip_networking`**: la instalación exige un servidor local en `127.0.0.1:3306`; si no, se detiene en las comprobaciones previas.
- **Cualquier cuenta local** del servidor puede abrir `127.0.0.1:8088` (no solo el túnel). Los datos son sintéticos; el acceso real requiere cuenta SSH.
- **Contraseñas sintéticas**: se muestran una vez en la terminal; quedan en el historial/registro del terminal del operador.
- El respaldo de `/etc/apache2` puede contener rutas de certificados; por eso la carpeta es modo 700.
- No hay HTTPS: no usar credenciales reales ni datos reales en esta prueba. Inventario SQL y migración reales siguen sin ejecutarse (ver `docs/DESPLIEGUE-REACT-PRUEBA.md`).

## 10. Resultado del despliegue real de prueba (UX-07D.1B → UX-07D.1C)

Informado por el operador; esta sesión **no** tuvo acceso al servidor y no lo verificó directamente.

**Arquitectura final instalada**

| Componente | Ubicación / valor |
|---|---|
| Acceso | **VPN corporativa + SSH**; túnel desde Windows `localhost:18089` → `127.0.0.1:8088` del servidor |
| Apache (sitio de prueba) | `/etc/apache2/sites-available/cervesur-react-prueba.conf`, solo en `127.0.0.1:8088` |
| Frontend React | `/var/www/cervesur-react-prueba/public` |
| Backend PHP de prueba | `/opt/cervesur-react-prueba/backend` (con su propio `.env`, fuera del DocumentRoot) |
| MySQL de prueba | base `cervesur_react_prueba` y usuario `cervesur_react`; datos y usuarios **exclusivamente sintéticos** |
| Respaldos y línea base de Flutter | `/var/backups/cervesur-react-prueba` (modo 700) |
| Flutter | sin cambios, puerto 80 |

**Cronología**

1. **Primer intento: falló** en la creación del usuario MySQL con `ERROR 1819 (HY000): Your password does not satisfy the current policy requirements`.
   Causa raíz: la contraseña generada (`openssl rand -hex 18`) solo tenía caracteres hexadecimales y el servidor aplica `validate_password` con
   `policy=MEDIUM`, `length=8`, `mixed_case_count=1`, `number_count=1`, `special_char_count=1`, `check_user_name=ON`.
2. **Reversión automática del primer intento: correcta.** Flutter idéntico a su línea base; base y usuario MySQL de prueba eliminados; carpetas de React eliminadas;
   puerto 8088 libre; respaldos conservados.
3. **Corrección:** la contraseña pasó a `Aa1!` + 48 caracteres hexadecimales (`openssl rand -hex 24`), con autocomprobación de la política MEDIUM antes de usarla.
   **La política de MySQL no se modificó.** Regresión: el ensayo Docker impone la misma política (reproduce el ERROR 1819 con el generador antiguo) y comprueba
   300 de 300 contraseñas nuevas, su aceptación por MySQL y que no aparecen en los registros.
4. **Segundo intento: correcto.** Simulación previa PASS; instalación completa; Apache configurado; base y usuario creados; datos sintéticos cargados;
   `verificar.sh` **18 PASS / 0 FAIL**; puerto 8088 solo en `127.0.0.1`; `/api/ping` responde desde PHP; el endpoint heredado de tokens OAuth responde 403;
   Flutter idéntico a su línea base según el verificador.

**Paquete desplegado** (artefacto de prueba, construido **antes** del commit; no se reconstruyó ni se reinstaló)

- Nombre: `cervesur-react-prueba-4bfdd1e57834-con-cambios-20261028-204729Z.tar.gz`
- SHA-256: `a59e1f6a3f719eab45e49922d0c7b17bd8f59e930d0aae86d5f61dca083a8446`

**Correspondencia entre el paquete y el código versionado.** Son dos identificadores distintos y no deben confundirse: el **SHA-256** identifica el *archivo
desplegado*; el **commit de Git** (registrado en el Pull Request de UX-07D.1C) identifica el *código fuente*. El paquete se generó desde `main` (`4bfdd1e`) con cambios
todavía sin commit, por eso su `VERSION` dice `4bfdd1e57834-con-cambios`. Se verificó, sin tocar el servidor:

- Los 55 archivos del paquete (todos salvo `SHA256SUMS` y `VERSION`) son **idénticos** a sus archivos fuente del repositorio (fin de línea normalizado en los de texto),
  e incluyen el generador de contraseñas corregido.
- Una recompilación local de la compilación de servidor (`CERVESUR_ALLOWED_HOSTS=localhost`) produjo exactamente los mismos archivos del frontend.
  Esto se comprobó **solo en la máquina de desarrollo** (mismo Node y mismo `package-lock.json`); no se afirma reproducibilidad idéntica en otros entornos.
- Para recrear el paquete desde el commit publicado hay que ejecutar `empaquetar.mjs`; el resultado llevará otro nombre (fecha y hora) y otro SHA-256, y es otro artefacto.

## 11. QA manual en el servidor (resultados)

Todos los resultados de la tabla fueron **confirmados verbalmente por el usuario** tras probar con el navegador y el túnel SSH. **No hay capturas incorporadas al
repositorio** (si existen, se conservan fuera de él; no deben subirse con correos personales ni datos reales sin anonimizar). Las comprobaciones automáticas del
instalador (18 PASS / 0 FAIL) están respaldadas por la salida del script que ejecutó el operador.

| Prueba | Estado | Evidencia |
|---|---|---|
| Inicio de sesión administrativo | Aprobada | Confirmado verbalmente |
| Panel de indicadores | Aprobada | Confirmado verbalmente |
| Creación de «Usuario QA» | Aprobada | Confirmado verbalmente |
| Creación y edición de «Empresa C» | Aprobada | Confirmado verbalmente |
| Rechazo de RUC duplicado | Aprobada | Confirmado verbalmente |
| Creación y edición de «Reporte QA Test» | Aprobada | Confirmado verbalmente |
| Asociación del reporte con «Empresa C» | Aprobada | Confirmado verbalmente |
| Asignación del reporte al «Usuario QA» | Aprobada | Confirmado verbalmente |
| Inicio de sesión como «Usuario QA»; catálogo limitado al reporte asignado | Aprobada | Confirmado verbalmente |
| Visualización de un dashboard real de Power BI («Publicar en web») | Aprobada | Confirmado verbalmente (no se publican datos del dashboard) |
| Retiro individual del acceso; el informe desaparece del catálogo del usuario | Aprobada | Confirmado verbalmente |
| **Acceso directo por URL al informe después de la revocación individual** | **PENDIENTE de QA manual** | **Sin evidencia**: no se da por aprobado |

## 12. Procedimiento de verificación y reversión (sin cambios)

Verificación: §5 (`sudo bash scripts/verificar.sh`). Reversión: §7 (`sudo bash scripts/revertir.sh`). **No se ejecutó ninguna reversión** del segundo intento y
**el entorno de prueba permanece instalado**. Para cerrarlo basta cerrar el túnel de Windows; para retirarlo, `revertir.sh` (con autorización).

## 13. Limitaciones conocidas

- **No es producción.** Es una instalación de prueba con datos sintéticos; no se migraron usuarios ni informes productivos y no se ejecutó ninguna consulta de
  inventario contra la base productiva.
- **No hay dominio ni HTTPS.** El acceso exige VPN + SSH. No debe habilitarse HTTP corporativo para React ni usarse con credenciales o datos reales.
- **No está abierto a la red corporativa:** solo quien tenga una cuenta SSH y el túnel puede usarlo (y cualquier cuenta local del servidor puede abrir `127.0.0.1:8088`).
- **Los enlaces de Power BI «Publicar en web» son públicos** para cualquier persona que posea su URL: React controla quién los ve en el portal, pero no puede ocultarlos de
  quien ya los conozca.
- **mod_php comparte el usuario `www-data` con Flutter:** `open_basedir` protege al PHP de la prueba, pero no impide que el PHP de Flutter lea los archivos de la prueba.
- **Endpoint heredado de tokens OAuth:** sigue en el código PHP; está bloqueado (403) en este sitio, y su compatibilidad con Flutter debe evaluarse antes de producción.
- **Acceso directo por URL tras revocar:** pendiente de QA manual (§11).
- Las contraseñas sintéticas se mostraron una sola vez en la terminal del operador; no se registran en el repositorio.
