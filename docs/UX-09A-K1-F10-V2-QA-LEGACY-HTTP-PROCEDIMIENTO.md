# K1/F-10 v2 — QA HTTP de routers heredados reales en copia aislada

**Estado:** procedimiento listo para ejecucion por operador. **NO es despliegue.**
**PR:** #13; mantener en Draft. **No** modifica `/var/www/cervesur`, Apache, MySQL, Azure, Flutter o la configuracion del servidor.

## Diferencia entre el repositorio y el servidor

`backend/router/PowerBIRouter.php` en `main` de GitHub requiere autenticacion y difiere del fichero realmente extraido del servidor Cervesur en el diagnostico de noviembre 2026. La CI general del repo es util para el repo, pero **no prueba la integracion de los parches K1/F-10 v2 sobre los routers desplegados**.

El nuevo arnes `tests/isolated/test_k1_f10_v2_legacy_http.py` trabaja con las copias ya verificadas en el servidor, sin subirlas a GitHub.

## Requisitos

- Ejecutar en el host de QA que dispone de PHP 8.3 CLI y Python 3.
- Mantener los tres archivos validados en `$HOME/cervesur-k1-f10-qa-v2`:
  - `router/PowerBIRouter.php` SHA-256 `307fac9a023b1b16b800c94cd0a7e7f8cff2bc832618c0762c1278302448e544`.
  - `router/auth.php` SHA-256 `8ea0c0a417113420c56a24f3cbda2b2e5d684a87d29fe6fa0b3a3ba28a4f0cd6`.
  - `lib/powerbi_authz.php` SHA-256 `4137cff3cab2d2390dbd82e73500e37d432e9f259b1f0931da8f163d5350e3a1`.
- **Nunca** ejecutar desde `/var/www/cervesur` ni copiar `.env`, secretos, sesiones, logs personales o bases de datos.
- No publicar los routers originales del servidor en GitHub.

## Procedimiento

1. Descargar con navegador autenticado el archivo `tests/isolated/test_k1_f10_v2_legacy_http.py` desde el PR #13 (opcion **Download raw file**). Windows: `C:\Users\Alexis Casas\Downloads\test_k1_f10_v2_legacy_http.py`.
2. En Ubuntu local WSL, copiar **solo este arnes de QA** a la carpeta aislada del servidor:

```bash
LOCAL="/mnt/c/Users/Alexis Casas/Downloads/test_k1_f10_v2_legacy_http.py"
test -s "$LOCAL" || { echo "QA_SCRIPT_NO_ENCONTRADO"; exit 1; }
scp "$LOCAL" usr_cervesur@192.168.200.12:/home/usr_cervesur/cervesur-k1-f10-qa-v2/
```

3. Ejecutar de forma sincrona; no usar `set -e` en la shell interactiva WSL:

```bash
ssh usr_cervesur@192.168.200.12 \
  'python3 "$HOME/cervesur-k1-f10-qa-v2/test_k1_f10_v2_legacy_http.py" --source-dir "$HOME/cervesur-k1-f10-qa-v2"'
```

4. Adjuntar el resumen `LEGACY_QA_HTTP_PASS`, `LEGACY_QA_HTTP_FAIL` y `LEGACY_QA_HTTP` al PR. No copiar payloads ni secretos.

## Aislamiento y criterios

El programa valida primero SHA-256 de las copias de routers reales; a continuacion las copia a `/tmp` y crea stubs de `auth_middleware.php` y controladores que fallan si son utilizados. Inicia PHP `-n` sin extensiones PDO/MySQL en `127.0.0.1`, `open_basedir` confinado al directorio temporal, sin variables de BD ni Azure. Termina el servidor PHP QA y elimina `/tmp` al finalizar.

Pruebas: K1 410 para 6 modos y IDs 0/123 + Authorization simulada; F-10 403 con bandera sin definir/abierta/cerrada; sintaxis y hashes. Si `LEGACY_QA_HTTP_FAIL=0` y `LEGACY_QA_HTTP=PASS`, puede cerrarse la evidencia HTTP sobre copias reales **con dependencias simuladas**.

**No demuestra** login/logout autenticos, Apache ni ausencia de consumidores. K1 Lote 2 y F-10 Lote 4 requieren autorizaciones independientes y su comprobacion funcional. P4b sigue **NO-GO**.
