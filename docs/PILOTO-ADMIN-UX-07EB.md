# Piloto administrativo de solo lectura · arquitectura y seguridad (UX-07E.B)

Objetivo: que una persona con cuenta **administradora real** pueda hacer QA manual del módulo Administrador de React **sin riesgo para producción**. Alcance: consulta. Las escrituras se prueban solo contra datos sintéticos (§6).

## 1. Por qué una instancia separada (y no habilitar «admin» en el 8092)

El piloto de usuarios (8092) excluye a los administradores **a propósito**: es un control de seguridad (un administrador ve todo; el 8092 se diseñó para que cada persona vea solo lo suyo). Habilitarlos en el mismo proceso mezclaría dos modelos de permiso en el mismo código de rutas y en las mismas cuentas de base de datos. Se propone, y se implementó, **una instancia aparte**:

| | Piloto de usuarios | Piloto administrativo |
|---|---|---|
| Dirección | `https://127.0.0.1:8092` | `https://127.0.0.1:8093` (túnel `…-L 18094:127.0.0.1:8093`) |
| Modo (`CERVESUR_MODE`) | `piloto_usuarios` | `piloto_admin` |
| Quién entra | Rol «usuario» activo + lista blanca | Rol **«admin»** activo + lista de **administradores autorizados** de la instancia |
| Qué ve | Solo sus informes (cadena de relaciones) | Directorio completo de solo lectura (usuarios, empresas, informes, asignaciones) |
| Cuentas MySQL | `cervesur_ro_auth`, `cervesur_ro_catalogo`, `cervesur_react_estado` | `…_adm` (propias) |
| Base de estado | `cervesur_react_estado` | `cervesur_react_estado_admin` |
| Carpetas / sitio Apache | `…-react-usuarios` | `…-react-admin` |
| Retirada | Sin tocar la otra | Sin tocar la otra (`revertir.sh`, `99-revocar-cuentas.sql` propios) |

El instalador de la instancia administrativa compara la huella del piloto de usuarios y de los demás portales antes y después: no los modifica.

## 2. Autenticación y autorización (lado servidor)

* Mismas credenciales que Flutter (`password_verify` sobre el hash existente). **No se modifica ninguna credencial.**
* Condiciones para entrar (todas): el usuario existe · contraseña correcta · `is_active = 1` · rol normalizado **`admin`** · `session_version` válida · figura en la **lista de administradores autorizados** de la instancia. Cualquier fallo devuelve la misma respuesta genérica («Credenciales inválidas») con 400 ms de retraso y `password_verify` siempre ejecutado.
* **Un administrador no entra por estar en el sistema, ni por estar en la lista de trabajadores**: la lista de la instancia administrativa es una base de estado distinta. Se agrega **solo por línea de comandos** (`usuarios.sh agregar <id>`, que rechaza trabajadores, inactivos e inexistentes). Un trabajador, aunque se colara en esa lista, es rechazado por el rol.
* Rutas separadas: la puerta de rutas de `piloto_admin` es una lista **cerrada** de 10 GET + login/logout. El frontend no decide permisos: aunque alguien manipule la interfaz, el servidor responde 403 a todo lo demás.
* Sesión opaca de 256 bits (se guarda su SHA-256), revalidada en **cada** petición: se invalida al quitarlo de la lista, desactivar la cuenta, cambiar rol, `session_version` o contraseña, o por CLI (`revocar`). Bloqueo por intentos (5/15 min por cuenta) y auditoría sin datos sensibles (solo ids y huellas).
* HTTPS obligatorio (el sitio Apache solo escucha TLS; el backend rechaza cualquier petición sin TLS; Apache borra `X-Forwarded-*`).

## 3. Qué datos entrega (y cuáles nunca)

Usuarios: id, usuario, correo, nombres, rol, estado. **Nunca** contraseña, hash, `session_version`, teléfono ni avatar. Empresas, informes y relaciones: los campos del catálogo. **Nunca** el `enlace` de Power BI en los listados ni en el detalle; solo el visor lo entrega, solo para los informes autorizados en la instancia (`CERVESUR_PILOT_VISOR_IDS`, vacío por defecto) y solo para sesiones válidas. Los enlaces «Publicar en la web» siguen siendo públicos para quien los conozca (`POWERBI-PUBLICAR-WEB-RIESGO.md`): un administrador autorizado verá el enlace de los informes que se autoricen.

## 4. Compatibilidad con la base de datos (B4)

Se reutilizan las tablas existentes (`user`, `empresa`, `reporte`, `empresa_usuario`, `empresa_reporte`, `user_empresa_reporte`); no se duplica nada. Las tablas `empresa_usuario` y `user_empresa_reporte` **no tienen `id` propio** en el esquema que usan los permisos de columna (21 columnas): el listado muestra un identificador compuesto «usuario-empresa» / «usuario-relación» solo para mostrar. Filtros: usuarios activos/inactivos/todos; relaciones de usuario solo vigentes (`activo = 1`); empresas, informes y relaciones empresa–informe, todas. **Pendiente de verificar en producción** (solo lectura): estructura real, claves primarias y únicas de estas tablas; duplicados en `user_empresa_reporte`.

Privilegios (idénticos en forma a los del piloto de usuarios): `…_auth_adm` SELECT por columna sobre 9 columnas de `user`; `…_catalogo_adm` SELECT por columna sobre 21 columnas de catálogo; `…_estado_adm` SELECT/INSERT/UPDATE/DELETE **solo** en `cervesur_react_estado_admin`. Ninguna con GRANT OPTION, privilegios globales ni escritura sobre `cervesur_portalbi`. El listado de usuarios no selecciona `password`.

## 5. Plan de mínimo privilegio para las ESCRITURAS (no activado)

Si más adelante se autorizan escrituras administrativas en producción, **no** se amplían estas cuentas. Se propone una cuarta cuenta, `cervesur_adm_escritura`, usada solo por un modo distinto (`piloto_admin_rw`) y con privilegios por tabla y columna:

| Función | Privilegio mínimo |
|---|---|
| Alta de empresa / informe | INSERT (columnas concretas) sobre `empresa` / `reporte`; UPDATE de las columnas editables; sin DELETE |
| Asignar empresas a usuarios | INSERT/UPDATE (`activo`) sobre `empresa_usuario` |
| Asignar informes a empresas | INSERT/UPDATE (`activo`) sobre `empresa_reporte` |
| Asignar permisos individuales | INSERT/UPDATE (`activo`) sobre `user_empresa_reporte` |
| Alta de usuario | **No se recomienda desde React**: debe seguir la política de Flutter (`/api/register`: hash bcrypt, rol fijado por el servidor). Requiere INSERT sobre `user` y una decisión expresa |

Condiciones para activarlo: autorización escrita, copia de seguridad de la base antes, ventana acordada, pruebas en el backend sintético con **exactamente** esos privilegios, auditoría de cada escritura (quién, qué fila, cuándo) y plan de reversión por fila. Nada de esto está implementado en producción.

## 6. Pruebas de escritura: entorno aislado y sintético

El backend completo en modo `read_write` ya existe para QA (`frontend/qa`, Docker, MySQL desechable, sin salida a Internet). Ver `frontend/docs/QA-MANUAL-WINDOWS.md`. Allí funcionan las acciones **Q** de `PARIDAD-ADMIN-FLUTTER-REACT.md` con las mismas reglas funcionales. **No se simulan como exitosas operaciones de producción que no se ejecutaron**: en producción esas acciones devuelven 403.

## 7. Límites declarados

* Solo se probó con datos sintéticos; no se ha ejecutado contra `cervesur_portalbi`.
* El cliente Flutter no se pudo revisar (no está en el repositorio): la paridad es respecto al backend.
* Contar con una cuenta administradora **real** autorizada es un paso humano (§ INSTALACION §5).
