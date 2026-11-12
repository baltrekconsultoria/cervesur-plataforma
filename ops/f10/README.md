# Cervesur F-10 — Corrección aislada del registro heredado

**Alcance:** proteger POST /api/register en /var/www/cervesur/backend/router/auth.php para exigir JWT válido y rol admin. NO modifica el backend React, roles de usuarios, middleware H1, A1, A2, MySQL ni Apache.

- patch_register.py: añade una sola guardia y rechaza si el archivo no tiene SHA-256 a4f063a53db42d738b4b6ee9b69cdad946bb99ba496f7aebd8027a67c86621f2, si la ruta es ambigua o si ya hay autenticación.
- f10-install-controlled.sh: preflight (temporal), apply (respaldo root-only, reemplazo atómico), rollback BACKUP_DIR.
- test_f10.py: QA sintética con PHP CLI, sin BD: 401 sin token, 403 usuario/user heredado, admin permitido, idempotencia y comprobación SHA.

## Pasos

1. Ejecutar python3 ops/f10/test_f10.py en un equipo con PHP y Python3.
2. Copiar solo patch_register.py y f10-install-controlled.sh al servidor, juntos, a una carpeta temporal controlada. Ejecutar sudo bash ./f10-install-controlled.sh preflight. Si falla una puerta, NO continuar.
3. Bajo autorización productiva, ejecutar sudo bash ./f10-install-controlled.sh apply y guardar la ruta exacta F10_BACKUP.
4. QA HTTP negativo con cuerpos SINTÉTICOS inválidos que no puedan dar altas: sin Bearer debe responder 401, con token de usuario normal debe responder 403. No usar un token real en logs ni hacer POST con body válido. QA visual de login admin y usuario vigente.
5. Si hay error 500, problemas de sesión o regresión de acceso, ejecutar sudo bash ./f10-install-controlled.sh rollback /home/usr_cervesur/cervesur-f10-backup-... (ruta exacta del paso 3). Validar H1 y QA funcional otra vez.
6. Comprobar si PHP OPcache necesita refresco. No reiniciar Apache de forma automática.

## Límites

La QA es SINTÉTICA y NO demuestra funcionamiento real HTTP; necesita puerta de QA tras despliegue. GitHub main contiene protección similar, pero el backend productivo es diferente y NO debe reemplazarse con main de forma indiscriminada. Mantener Fase 2B bloqueada: no activar ADMIN_WRITES_USUARIOS ni los cambios sensibles de rol/contraseña. Sin cambios MySQL ni frontend.
