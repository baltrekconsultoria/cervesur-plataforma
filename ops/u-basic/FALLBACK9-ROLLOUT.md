# Cervesur Fallback9: parche minimo fail-closed con backup

## Evidencia productiva 12/11/2026

- Inventario solo lectura: nueve fallbacks JWT en dos routers, sin valores revelados.
- router/ReporteRouter.php: 8 instancias en lineas 21,40,58,75,86,97,108,119; SHA256 ea48e5b011e945e9569bb177bf1c6afa5eee025ddd0287163db78e93581c59a6.
- router/UserRouter.php: 1 instancia en linea 179; SHA256 41610318f9bb5cd11ad7daa40cfbcf95f1742e5f14d5c4d4b59e4613a5206709.
- H1 auth_middleware.php instalado: SHA256 c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298.
- F10 auth.php instalado: SHA256 4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366.
- D4_CRITICOS PASS, pero D4-06b=9 y QA_HTTP_USER_ADMIN pendiente. U-BASIC/P5 siguen OFF.

## Cambio exacto

Unicamente reemplazar nueve expresiones PHP que usaban getenv(JWT_SECRET) con fallback literal, por llamadas a get_jwt_secret_or_fail('ruta protegida'). El helper ya existe en H1 y devuelve HTTP 500 si falta JWT_SECRET, sin intentar autenticar. Si la clave esta configurada, sigue empleando la misma clave y controles de autorizacion.

El kit nunca imprime ni conserva en GitHub los literales originales. Una copia root-only preserva el estado anterior para rollback. No reemplaza routers enteros ni toca F10/H1, MySQL, .env, frontend, usuarios ni banderas.

Puertas fail-closed: coincide SHA de los 2 routers, H1, F10; ocho mas uno en las lineas esperadas; matches con asignacion PHP simple; importacion del middleware, candidate PHP lint. Si hay drift, el script aborta sin cambios. El preflight lee archivos y crea solo un lock.

## Operacion PuTTY (solo desde commit aprobado y Git blob validado)

1) Preflight: sudo python3 /opt/cervesur-u-basic-kit/patch_jwt_fallback9.py preflight
   Esperado F10_SHA=PASS, H1_SHA=PASS, JWT_FALLBACKS_ANTES=9, JWT_FALLBACKS_CANDIDATO=0, PHP_LINT_CANDIDATO=PASS, PREFLIGHT=PASS_SOLO_LECTURA.

2) Aplicar en ventana autorizada: sudo python3 /opt/cervesur-u-basic-kit/patch_jwt_fallback9.py apply --confirm CERVESUR_APLICAR_FALLBACK9_CON_BACKUP
   Primero backup privado root-only bajo /home/usr_cervesur/cervesur-fallback9-*, con manifest before/after SHA; despues actualiza exclusivamente 2 archivos con reemplazo atomico y conserva propietario/permisos. Resultado esperado BACKUP_VERIFICADO=SI; JWT_FALLBACK9_APLICADO=SI; JWT_FALLBACKS_RESTANTES=0.

3) Verificar con la ruta exacta BACKUP_PATH:
   sudo python3 /opt/cervesur-u-basic-kit/patch_jwt_fallback9.py verify --backup /home/usr_cervesur/cervesur-fallback9-VALOR_REAL
   Verifica SHA posterior, sin fallbacks y PHP lint.

4) Rollback (solo ante regresion, con ruta exacta):
   sudo python3 /opt/cervesur-u-basic-kit/patch_jwt_fallback9.py rollback --backup /home/usr_cervesur/cervesur-fallback9-VALOR_REAL --confirm CERVESUR_APLICAR_FALLBACK9_CON_BACKUP
   Rechaza sobreescribir cambios que un tercero haya efectuado tras el deploy.

5) Correr D4 nuevamente: se espera D4-06b=PASS_SIN_FALLBACKS si el resto del backend sigue igual. QA funcional Flutter reportes y avatar, mas permisos HTTP por roles siguen por separado.

Backups contienen codigo con literales antiguos potencialmente secretos: no publicar, copiar ni pegar su contenido. U-BASIC y sensibles siguen OFF. El cierre requiere QA HTTP user/admin, D3 y autorizaciones funcional y DBA, con backup reciente al encender.
