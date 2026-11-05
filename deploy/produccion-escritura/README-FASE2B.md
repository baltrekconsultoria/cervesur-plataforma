# Cervesur · Administrador React · Paquete FASE 2B (Usuarios) — LEEME

**Release candidate. Nada de este paquete se ha ejecutado en producción.** Se instala sobre la **Fase 2A ya operando** (RC2 `f539c9fd543d`, modo `admin_escritura`, empresas/reportes/accesos encendidos). Usuarios nace **APAGADO**.
Lea primero `docs/UX-08I-FASE-2B-LIBERACION.md` (orden, riesgos, diferencias de permisos) y `docs/UX-08I-FASE-2B-AUDITORIA-GO-NO-GO.md`.

## Qué trae
| Carpeta / script | Para qué |
|---|---|
| `backend/`, `public/` | código 2B (con Usuarios bloqueado por defecto en cuatro capas) |
| `fase2b/repo/` | 4 plantillas SQL aditivas de privilegios (`no-sensibles` y `sensibles`, cada una con su `.down`) + `SHA256SUMS` |
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
4. **P4b** — `CERVESUR_CONFIRMAR_USUARIOS=SI sudo -E bash scripts/interruptor-usuarios.sh encender --autorizacion "TEXTO"` (necesita `CERVESUR_E_USUARIO`/`CERVESUR_E_CLAVE` en el entorno).
5. **P4c** — UNA operación real autorizada y comprobar exactamente una fila nueva en `audit_log`.
6. **Apagar en cualquier momento**: `sudo bash scripts/interruptor-usuarios.sh apagar` (elimina las claves; inmediato, sin redeploy).

**Rol y contraseña (P5) no se habilitan con este paquete.** `GRANT`/`REVOKE` son DDL no transaccionales. No existe prueba de restauración del respaldo: resuélvala antes de P2.
