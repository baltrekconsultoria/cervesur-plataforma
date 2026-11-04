# Sanitizador E0, segunda pasada (awk portable, sin intervalos {n,}): enmascara palabras opacas largas que parecen tokens o claves
# (>= 32 caracteres, mezcla de mayúsculas, minúsculas y dígitos, no solo hexadecimal, no parte de una ruta). Los SHA-256 hexadecimales y los
# nombres de objetos (minúsculas con guion bajo) NO se tocan.
{
  line = $0
  out = ""
  while (match(line, /[A-Za-z0-9_-]+/)) {
    tok = substr(line, RSTART, RLENGTH)
    pre = (RSTART > 1) ? substr(line, RSTART - 1, 1) : ""
    out = out substr(line, 1, RSTART - 1)
    line = substr(line, RSTART + RLENGTH)
    if (length(tok) >= 32 && pre != "/" && tok !~ /^[0-9a-fA-F]+$/ && tok ~ /[0-9]/ && tok ~ /[a-z]/ && tok ~ /[A-Z]/) {
      out = out "<TOKEN-ENMASCARADO>"
    } else {
      out = out tok
    }
  }
  print out line
}
