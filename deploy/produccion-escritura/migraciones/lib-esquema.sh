#!/usr/bin/env bash
# Cervesur · UX-08H · Biblioteca COMPARTIDA de comparación de esquema (solo lógica; no abre conexiones).
# Hay DOS copias idénticas: e0/lib-esquema.sh y migraciones/lib-esquema.sh (cada carpeta debe ser autónoma; un ensayo comprueba que son idénticas).
# Uso:  esquema_comparar <real.tsv> <esperado.tsv> <grupo>
#   real.tsv     columnas separadas por TAB: tabla, columna, column_type, is_nullable, ... (salida de information_schema.columns, sin cabecera)
#   esperado.tsv esquema-esperado.tsv (grupo, tabla, columna, column_type, is_nullable, critico)
#   grupo        negocio | auditoria
# Imprime una línea por hallazgo:
#   FALTA_TABLA t · FALTA t.c · TIPO_CRITICO t.c esperado=… real=… · NULO_CRITICO t.c · TIPO t.c esperado=… real=… (aviso) · SOBRA t.c (aviso)
#   y al final RESUMEN faltan=N criticos=N avisos=N sobran=N
# Código de salida: 0 = coincide · 1 = hay FALTA/…_CRITICO (bloquea) · 2 = solo avisos (TIPO no crítico o columnas SOBRANTES)
esquema_comparar() {
  local real="$1" esp="$2" grupo="$3"
  awk -F'\t' -v grupo="$grupo" '
    FNR == NR {
      if ($0 ~ /^#/ || $0 == "" || $1 != grupo) next
      t = tolower($2); c = tolower($3)
      et[t, c] = $4; en[t, c] = $5; ec[t, c] = $6; tabla[t] = 1; orden[++n] = t SUBSEP c
      next
    }
    {
      t = tolower($1); c = tolower($2)
      realtab[t] = 1
      rt[t, c] = $3; rn[t, c] = $4; rc[t, c] = 1
    }
    END {
      falta = 0; crit = 0; avisos = 0; sobran = 0
      for (t in tabla) if (!(t in realtab)) { print "FALTA_TABLA " t; falta++ }
      for (i = 1; i <= n; i++) {
        split(orden[i], k, SUBSEP); t = k[1]; c = k[2]
        if (!(t in realtab)) continue
        if (!((t, c) in rc)) { print "FALTA " t "." c; falta++; continue }
        if (rt[t, c] != et[t, c]) {
          if (ec[t, c] == "1") { print "TIPO_CRITICO " t "." c " esperado=" et[t, c] " real=" rt[t, c]; crit++ }
          else { print "TIPO " t "." c " esperado=" et[t, c] " real=" rt[t, c]; avisos++ }
        }
        if (ec[t, c] == "1" && en[t, c] == "NO" && rn[t, c] != "NO") { print "NULO_CRITICO " t "." c " esperado=NOT NULL real=NULL"; crit++ }
      }
      for (key in rc) {
        split(key, k, SUBSEP)
        if ((k[1] in tabla) && !((k[1], k[2]) in et)) { print "SOBRA " k[1] "." k[2]; sobran++ }
      }
      print "RESUMEN faltan=" falta " criticos=" crit " avisos=" avisos " sobran=" sobran
      exit (falta + crit > 0) ? 1 : ((avisos + sobran > 0) ? 2 : 0)
    }
  ' "$esp" "$real"
}
