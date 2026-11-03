# Instalación del piloto visual con informes reales (127.0.0.1:8091) · UX-07D.4 express

Sitio nuevo `cervesur-react-piloto`: **la aplicación React existente, reducida a consulta**, para **UN operador autorizado**, conectada de solo lectura a `cervesur_portalbi`. Muestra el catálogo de empresas e informes y el visor de los informes que el operador autorice. **No** incluye pantallas de alta, edición, eliminación ni asignación, **no** usa usuarios ni contraseñas de empleados, **no** consulta la tabla `user`.
**Esta guía describe cómo se instalará; nada se ha desplegado.**

## Arquitectura mínima
| Elemento | Valor |
|---|---|
| Apache | `cervesur-react-piloto`, **solo `127.0.0.1:8091`**; desde Windows por túnel SSH a `localhost:18092` |
| Aplicación | `/var/www/cervesur-react-piloto/public` (compilación `build:pilot`: sin pantallas de administración, aviso permanente «PILOTO · Datos reales · Solo consulta · Operador autorizado») |
| Backend + `.env` | `/opt/cervesur-react-piloto/backend` (`.env` 640 `root:www-data`; modo `piloto_visual` exigido, fail-closed) |
| Operador | usuario `operador`; clave aleatoria de 24 caracteres, **solo su hash bcrypt** en el `.env`; la clave en `/opt/cervesur-react-piloto/operador.clave` (root 600) |
| Sesión | JWT de 1 h firmado con un secreto exclusivo; sin cookies ni estado en MySQL; 5 fallos de acceso = bloqueo de 15 min (archivo propio) |
| MySQL | cuenta **nueva y dedicada** `cervesur_ro_visor`@`127.0.0.1`: `SELECT` por columna sobre **15 columnas**; no se toca `cervesur_ro_diag` |
| Estado y respaldos | `/var/lib/cervesur-react-piloto` (estado 700 `www-data`), `/var/backups/cervesur-react-piloto` (700; nunca se borran) |
| No se toca | Flutter (80), 8088, 8089, 8090, módulos de Apache (`mod_headers`), configuración global; sin reinicio de Apache (solo recarga suave tras `configtest`) |

### Columnas MySQL exactas de `cervesur_ro_visor` (15 = 6+5+4)
| Tabla | Columnas | Uso |
|---|---|---|
| `reporte` | `id`, `reporte_id`, `nombre`, `descripcion`, `activo`, **`enlace`** | catálogo; `enlace` SOLO en la consulta del visor |
| `empresa` | `id`, `nombre`, `ciudad`, `ruc`, `activo` | catálogo de empresas |
| `empresa_reporte` | `id`, `empresa_id`, `reporte_id`, `activo` | relación empresa–informe |

Sin acceso a `user` (hashes, correos, roles), `empresa.powerbi_*`, contacto, razón social, grupo, notas ni auditoría. Solo 8 consultas SQL fijas (catálogo cerrado `backend/lib/piloto_catalogo.php`); cualquier otra se rechaza antes de llegar a MySQL.

## Qué ve y qué no ve el operador
- **Sí:** empresas e informes activos, filtros y navegación por id numérico, visor de los informes autorizados.
- **Enlaces:** nunca en los listados; solo el visor de un informe **autorizado** devuelve el enlace de «Publicar en web». Por defecto **ningún** informe está autorizado: el catálogo se ve, el visor responde «aún no está autorizado para el piloto». Los enlaces «Publicar en web» son públicos para quien los conozca; el portal solo controla su descubrimiento (ver `docs/DISENO-INTEGRACION-INFORMES-UX-07D4A.md` §4).
- **No:** usuarios, asignaciones de usuarios, estadísticas, administración, Power BI heredado, escrituras. Todo eso responde 403 aunque se manipule la API.

## Orden obligatorio
1. **Preflight A** (infraestructura, sin cuenta): `sudo bash scripts/preflight-infra.sh`.
2. **Crear la cuenta `cervesur_ro_visor`** (administrador autorizado, sección siguiente).
3. **Preflight B** (acceso): `sudo bash scripts/preflight-acceso.sh --clave-archivo /root/cervesur-ro-visor.clave` — `0` PASS · `1` FAIL · **`3` PENDIENTE** (nunca un PASS ficticio).
4. **Simulación**: `sudo bash scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-visor.clave`.
5. **Instalación**: `sudo bash scripts/instalar.sh --clave-archivo /root/cervesur-ro-visor.clave` (pide `INSTALAR-8091`). Para autorizar informes desde el inicio: `--informes-autorizados 12,15` (pide además `AUTORIZO-INFORMES`) o `TODOS` (pide `AUTORIZO-TODOS-LOS-INFORMES`).
6. **Verificación**: `sudo bash scripts/verificar.sh` (también al final de la instalación).

## Procedimiento separado: crear `cervesur_ro_visor`
```
sudo bash admin-mysql/preparar-sql-cuenta.sh                                   # genera /root/cervesur-ro-visor.sql y .clave (600); no se conecta a MySQL ni muestra la clave
sudo bash admin-mysql/mostrar-sql-redactado.sh /root/cervesur-ro-visor.sql      # REVISIÓN HUMANA con la clave redactada: 1 CREATE USER + 3 GRANT SELECT por columna (6+5+4 = 15). NO use cat/less/sed
sudo mysql < /root/cervesur-ro-visor.sql                                        # solo con autorización explícita
sudo mysql < admin-mysql/02-verificar-privilegios-visor.sql                     # privilegios efectivos: 15 columnas, ninguna de la tabla user
```
Al terminar, destruya el `.sql` y la `.clave` (la clave de la cuenta queda solo en el `.env`).

## Uso por túnel SSH
```
ssh -N -L 18092:127.0.0.1:8091 usr_cervesur@<servidor>        # en Windows; deje la ventana abierta
# abrir http://localhost:18092  · usuario: operador · clave: sudo bash scripts/operador.sh --mostrar
```
Operación: `scripts/operador.sh` (`--mostrar`, `--rotar-clave`, `--revocar-sesiones`, `--desbloquear`) y `scripts/informes.sh` (`--listar`, `--autorizar <ids|TODOS>`, `--retirar`). Ninguno recarga Apache.
