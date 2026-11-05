# Cervesur · Administrador React · Paquete FASE 2B (Usuarios) — LEEME

**Release candidate. Nada de este paquete se ha ejecutado en producción.** Se instala sobre la **Fase 2A ya operando** (RC2 `f539c9fd543d`, modo `admin_escritura`, empresas/reportes/accesos encendidos). Usuarios nace **APAGADO**.
Lea primero `docs/UX-08I-FASE-2B-LIBERACION.md` (orden, riesgos, diferencias de permisos) y `docs/UX-08I-FASE-2B-AUDITORIA-GO-NO-GO.md`.

## Qué trae
| Carpeta / script | Para qué |
|---|---|
| `backend/`, `public/` | código 2B (con Usuarios bloqueado por defecto en cuatro capas) |
| `fase2b/repo/` | 6 plantillas SQL aditivas (vista de alta con `WITH CHECK OPTION` y su cuenta DEFINER bloqueada; privilegios `no-sensibles` y `sensibles`; cada una con su `.down`) + `SHA256SUMS` |
| `scripts/instalar-codigo-2b.sh` | **P3** · `--simular` / `--aplicar` / `--revertir <respaldo>`; respaldo con SHA-256, restauración automática si falla; no toca el `.env` |
| `scripts/privilegios-usuarios.sh` | **P4a** · el DBA: `--simular` / `--verificar` / `--aplicar` / `--revertir` por etapa |
| `scripts/interruptor-usuarios.sh` | **P4b** · `estado` / `apagar` / `encender` (solo lo no sensible); `sensible` está rechazado |
| `scripts/verificar-posdespliegue.sh`, `interruptores.sh`, `apagar-escrituras.sh`, `comprobar-otros-portales.sh` | los de la Fase 2A (siguen valiendo con Usuarios apagado) |
| `base/MANIFIESTO-FASE2A-f539c9fd543d.sha256` | huella de lo que debe estar instalado antes de empezar |
| `migraciones/`, `e0/` | los de la Fase 2A (no se vuelven a aplicar: `audit_log` y sus triggers ya existen) |

El instalador A1/A2 de la Fase 2A **no** viene en este paquete: lo instalado ya es la Fase 2A.

## Orden (cada paso con su autorización; ver el documento de liberación)
1. **Verificar el paquete**: `sha256sum -c` del `.tar.gz` contra su `.sha256` y `cd paquete && sha256sum -c SHA256SUMS`.
2. **P3** — `sudo bash scripts/instalar-codigo-2b.sh --simular`; si da 0: `sudo bash scripts/instalar-codigo-2b.sh`. Usuarios sigue 403 `fase_2b_bloqueada`.
3. **P4a** (DBA) — `sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles --simular`; luego `--aplicar --respaldo ARCHIVO.sql.gz --confirmo-autorizacion "TEXTO"` y `--verificar`.
   La etapa **crea** la cuenta `cervesur_vista_alta@localhost` (bloqueada, solo `SELECT`/`INSERT` por columnas sobre `user`) y la vista `v_user_alta` (`WITH CHECK OPTION`: el motor rechaza cualquier rol distinto de `user`/`usuario`), y concede a la cuenta de escritura `INSERT` **solo sobre la vista** y `UPDATE` de datos **sin** `email`, `role` ni `password`. Requiere **MySQL ≥ 8.0.18**; si la cuenta o la vista ya existen, se niega (no adopta identidades ajenas). `--verificar` comprueba la vista (insertable, `CHECK OPTION`, `DEFINER`, columnas, `WHERE`) y el alcance de la cuenta DEFINER; cualquier alteración da código 30.
   La vista y las cuentas **no** entran en un `mysqldump` de tablas: antes de aplicar, guarde `SHOW GRANTS` y (si ya existiera) `SHOW CREATE VIEW` (el script guarda ambas en `privilegios-*/` como evidencia).
4. **P4b** — `CERVESUR_CONFIRMAR_USUARIOS=SI sudo -E bash scripts/interruptor-usuarios.sh encender --autorizacion "TEXTO"` (necesita `CERVESUR_E_USUARIO`/`CERVESUR_E_CLAVE` en el entorno).
5. **P4c** — UNA operación real autorizada y comprobar exactamente una fila nueva en `audit_log`.
6. **Apagar en cualquier momento**: `sudo bash scripts/interruptor-usuarios.sh apagar` (elimina las claves; inmediato, sin redeploy).
7. **Volver a la Fase 2A**: apagar → `privilegios-usuarios.sh --etapa no-sensibles --revertir --confirmo-autorizacion "TEXTO"` (revoca primero a la cuenta y después elimina la vista y la cuenta DEFINER; un corte a mitad se recupera repitiendo `--revertir`) → `instalar-codigo-2b.sh --revertir <respaldo-de-P3>`.

**Correo de los usuarios**: desde React el correo es de solo lectura (la cuenta no tiene `UPDATE(email)` y la API rechaza un correo distinto con 400): cambiarlo podría colisionar con el login de Flutter (correo o usuario) o facilitar la recuperación de una cuenta ajena. **Limitación vigente**: el backend de Flutter desplegado NO ha sido inspeccionado; todo lo dicho sobre Flutter proviene del código del repositorio y de una reconstrucción.

**Rol y contraseña (P5) no se habilitan con este paquete.** `GRANT`/`REVOKE` son DDL no transaccionales. No existe prueba de restauración del respaldo: resuélvala antes de P2.
