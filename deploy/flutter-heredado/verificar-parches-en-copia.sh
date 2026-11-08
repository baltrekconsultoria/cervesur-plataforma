#!/usr/bin/env bash
# Verifica, SIN modificar nada, que cada parche aplica exactamente sobre una COPIA del backend.
# Uso:  verificar-parches-en-copia.sh <directorio_copia_del_backend> <directorio_correctivos-v6> [lote]
#   <copia>  : copia de /var/www/cervesur/backend hecha ANTES de la ventana (nunca el directorio vivo).
#   [lote]   : 1, 2, 3 o 4 (por omision todos los de ese lote; se pasa uno por ventana).
# Cada parche exige: (1) SHA-256 base igual al de produccion, (2) `patch --dry-run -F0` sin fuzz ni desplazamiento.
# OMITIR_SHA=1 solo para pruebas locales sobre la replica; no se usa en una ventana real.
set -u
COPIA="${1:?falta la copia del backend}"; PAQ="${2:?falta correctivos-v6}"; LOTE="${3:-}"
ok=0; mal=0
verificar() {  # lote archivo sha_base parche...
  local lote="$1" f="$2" sha="$3"; shift 3
  [ -n "$LOTE" ] && [ "$LOTE" != "$lote" ] && return 0
  local obj="$COPIA/$f"
  if [ ! -r "$obj" ]; then echo "NO LEGIBLE  $f"; mal=$((mal+1)); return 0; fi
  if [ "${OMITIR_SHA:-0}" != "1" ]; then
    local h; h=$(sha256sum "$obj" | cut -d' ' -f1)
    case "$h" in "$sha"*) ;; *) echo "SHA DIFERENTE  $f  (esperada ${sha:0:12}, hay ${h:0:12})"; mal=$((mal+1)); return 0;; esac
  fi
  for p in "$@"; do
    local sal; sal=$(patch --dry-run -p1 -F0 -d "$COPIA/.." -i "$PAQ/$p" 2>&1)
    if [ $? -ne 0 ] || echo "$sal" | grep -qE 'offset|fuzz|FAILED|rejected'; then
      echo "PARCHE NO APLICA EXACTO  $p"; echo "$sal" | sed 's/^/    /'; mal=$((mal+1))
    else echo "OK  lote $lote  $f  <-  $p"; ok=$((ok+1)); fi
  done
}
# El directorio padre de la copia debe llamarse de modo que `-p1` con prefijo backend/ resuelva a la copia:
#   <padre>/backend  ==  <copia>   (es decir, la copia se llama "backend")
[ "$(basename "$COPIA")" = "backend" ] || { echo "La copia debe llamarse 'backend' para que -p1 resuelva a/backend/..."; exit 2; }
verificar 1 router/EmpresaRouter.php          a50a3c1c2a78 lote1-k2/K2a+K2c+K2d+K2e-empresa-router.diff
verificar 2 router/PowerBIRouter.php          8ee4f7ee7529 lote2-k1/F-2-powerbi-router-autenticacion-y-asignacion.diff
verificar 3 router/UserRouter.php             60b011d54265 lote3-a1/F-3-user-router-perfil-propio-o-admin.diff
verificar 4 router/AsignacionRouter.php       4b4962ced9f0 lote4-a2/F-4-asignacion-empresausuario.diff
verificar 4 router/EmpresaUsuarioRouter.php   c4433d63c213 lote4-a2/F-4-asignacion-empresausuario.diff
verificar 4 router/ReporteRouter.php          14406e81fdf3 lote4-a2/F-5b-reporte-router-guardias-admin.diff
verificar 4 router/EmpresaReporteUsuarioRouter.php b854eaa4d40f lote4-a2/F-5c-eru-router-guardias-admin.diff
verificar 4 controllers/ReporteController.php 880d799275ca0646766d254b6755e357fdff4c288ac48107eca1689a63afcb89 lote4-a2/F-5d-reporte-controller-updated_by.diff
verificar 4 models/ReporteModel.php           18b2e253a955a25373811dcd1c3d29aa90a124abdf59405c564cffd5e69fcc41 lote4-a2/F-5e-reporte-model-updated_by.diff
verificar 4 models/EmpresaModel.php           a645a5e864e9afa0510b126d49a25e4af6ec82aff94fd49982206503cab2839d lote4-a2/F-5f-empresa-model-rol-admin.diff
verificar 4 models/EmpresaReporteUsuarioModel.php 2aa5b626a9faf22dcb3b58f3b40e8b7d12585cab3d981b23dc44edd5b6db914d lote4-a2/F-5g-eru-model-valida-destinos.diff
verificar 4 router/auth.php                   a4f063a53db4 lote4-a2/F-10-registro-cerrado.diff
echo "Resumen: $ok parches exactos, $mal con problemas. NADA fue modificado."
[ "$mal" -eq 0 ]
