#!/usr/bin/env bash
# CERVESUR F10. Ejecutar como root: preflight | apply | rollback BACKUP_DIR
set -euo pipefail
umask 077

ACTION="${1:-}"
BASE="/var/www/cervesur/backend"
LIVE="$BASE/router/auth.php"
MW="$BASE/lib/auth_middleware.php"
EXPECTED="a4f063a53db42d738b4b6ee9b69cdad946bb99ba496f7aebd8027a67c86621f2"
EXPECTED_H1="c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298"
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
PATCHER="$SOURCE_DIR/patch_register.py"
BACKUPS="/home/usr_cervesur"

abort() { printf 'F10_NO_GO=%s\n' "$1" >&2; exit 2; }
[[ $EUID -eq 0 ]] || abort 'REQUIERE_SUDO'
[[ -f "$PATCHER" ]] || abort 'PATCHER_AUSENTE'
[[ -f "$LIVE" && ! -L "$LIVE" ]] || abort 'RUTA_PRODUCTIVA_INVALIDA'
[[ -f "$MW" && ! -L "$MW" ]] || abort 'MIDDLEWARE_INVALIDO'
command -v python3 >/dev/null || abort 'PYTHON3_AUSENTE'
command -v php >/dev/null || abort 'PHP_AUSENTE'

sha_file() { sha256sum -- "$1" | awk '{print $1}'; }

check_live() {
  [[ "$(sha_file "$LIVE")" == "$EXPECTED" ]] || abort 'SHA_PRODUCTIVO_CAMBIO'
  [[ "$(sha_file "$MW")" == "$EXPECTED_H1" ]] || abort 'SHA_H1_CAMBIO'
  grep -Eq 'function[[:space:]]+get_jwt_secret_or_fail[[:space:]]*\(' "$MW" || abort 'H1_SECRET_HELPER_NO_DISPONIBLE'
  grep -Eq 'function[[:space:]]+require_auth[[:space:]]*\(' "$MW" || abort 'H1_REQUIRE_AUTH_NO_DISPONIBLE'
  grep -Eq "require_once.*auth_middleware.php" "$LIVE" || abort 'ROUTER_NO_CARGA_MIDDLEWARE'
  php -l "$LIVE" >/dev/null || abort 'PHP_LINT_LIVE'
  php -l "$MW" >/dev/null || abort 'PHP_LINT_H1'
}

make_candidate() {
  python3 "$PATCHER" --input "$LIVE" --output "$1/auth.php" --expected-sha "$EXPECTED"
  php -l "$1/auth.php" >/dev/null || abort 'PHP_LINT_CANDIDATO'
  local found
  found="$(grep -c 'F10-20261111' "$1/auth.php")"
  [[ "$found" == 1 ]] || abort 'MARCADOR_INCORRECTO'
}

case "$ACTION" in
  preflight)
    check_live
    TMP="$(mktemp -d /tmp/cervesur-f10-preflight.XXXXXXXX)"
    trap 'rm -rf -- "$TMP"' EXIT
    make_candidate "$TMP"
    printf 'F10_PREFLIGHT=PASS_SOLO_STAGE\nF10_PRODUCCION_MODIFICADA=NO\n'
    ;;
  apply)
    check_live
    [[ -d "$BACKUPS" ]] || abort 'RUTA_BACKUPS_INEXISTENTE'
    TMP="$(mktemp -d /tmp/cervesur-f10-stage.XXXXXXXX)"
    trap 'rm -rf -- "$TMP"' EXIT
    make_candidate "$TMP"
    CAND_SHA="$(sha_file "$TMP/auth.php")"
    TS="$(date -u +%Y%m%dT%H%M%SZ)"
    BACKUP="$BACKUPS/cervesur-f10-backup-$TS-$$"
    install -d -m 700 "$BACKUP"
    cp -a -- "$LIVE" "$BACKUP/auth.php"
    [[ "$(sha_file "$BACKUP/auth.php")" == "$EXPECTED" ]] || abort 'BACKUP_VERIFICACION_SHA'
    printf '%s\n' "$CAND_SHA" > "$BACKUP/candidate.sha256"
    printf '%s\n' "$EXPECTED" > "$BACKUP/original.sha256"
    chmod 600 "$BACKUP"/*
    DESTTMP="$(mktemp -p "$(dirname "$LIVE")" .auth.php.f10.XXXXXXXX)"
    trap 'rm -f -- "${DESTTMP:-}"; rm -rf -- "${TMP:-}"' EXIT
    cp -a -- "$LIVE" "$DESTTMP"
    cat "$TMP/auth.php" > "$DESTTMP"
    php -l "$DESTTMP" >/dev/null || abort 'PHP_LINT_DESTTMP'
    [[ "$(sha_file "$LIVE")" == "$EXPECTED" ]] || abort 'RACE_SHA_PRODUCTIVO_CAMBIO'
    [[ "$(sha_file "$DESTTMP")" == "$CAND_SHA" ]] || abort 'SHA_DESTTMP_CAMBIO'
    mv -Tf -- "$DESTTMP" "$LIVE"
    [[ "$(sha_file "$LIVE")" == "$CAND_SHA" ]] || abort 'SHA_POST_CAMBIO'
    printf 'F10_INSTALADO=SI\nF10_BACKUP=%s\nF10_INSTALADO_SHA256=%s\n' "$BACKUP" "$CAND_SHA"
    printf 'F10_APACHE_REINICIADO=NO\nF10_DB_MODIFICADA=NO\n'
    ;;
  rollback)
    BACKUP="${2:-}"
    [[ -n "$BACKUP" && "$BACKUP" == "$BACKUPS"/cervesur-f10-backup-* ]] || abort 'BACKUP_RUTA_NO_PERMITIDA'
    [[ -d "$BACKUP" && ! -L "$BACKUP" ]] || abort 'BACKUP_AUSENTE'
    [[ -f "$BACKUP/auth.php" && -f "$BACKUP/candidate.sha256" ]] || abort 'BACKUP_INCOMPLETO'
    [[ "$(sha_file "$BACKUP/auth.php")" == "$EXPECTED" ]] || abort 'BACKUP_SHA_INVALIDO'
    CAND_SHA="$(cat "$BACKUP/candidate.sha256")"
    [[ "$CAND_SHA" =~ ^[a-f0-9]{64}$ ]] || abort 'SHA_CANDIDATO_INVALIDO'
    [[ "$(sha_file "$LIVE")" == "$CAND_SHA" ]] || abort 'LIVE_CAMBIO_DESPUES_INSTALACION'
    DESTTMP="$(mktemp -p "$(dirname "$LIVE")" .auth.php.f10-rollback.XXXXXXXX)"
    trap 'rm -f -- "${DESTTMP:-}"' EXIT
    cp -a -- "$BACKUP/auth.php" "$DESTTMP"
    php -l "$DESTTMP" >/dev/null || abort 'PHP_LINT_ROLLBACK'
    mv -Tf -- "$DESTTMP" "$LIVE"
    [[ "$(sha_file "$LIVE")" == "$EXPECTED" ]] || abort 'SHA_ROLLBACK_INVALIDO'
    printf 'F10_ROLLBACK=PASS\nF10_APACHE_REINICIADO=NO\n'
    ;;
  *)
    printf 'Uso: sudo bash %s preflight|apply|rollback /home/usr_cervesur/cervesur-f10-backup-...\n' "$0" >&2
    exit 2
    ;;
esac
