# Sanitizador E0 (sed -E, GNU). Toda salida del kit pasa por aquí ANTES de tocar el disco. Enmascara enlaces de Power BI y cualquier URL con
# parámetros, hashes (bcrypt, argon2, MySQL), JWT, bearer, claves privadas, «IDENTIFIED BY '…'» y pares CLAVE=valor cuyo nombre sugiere un secreto.
# El orden importa: primero los enlaces, después los hashes, al final los pares clave=valor.
s#[A-Za-z][A-Za-z0-9+.-]*://[^[:space:]"'<>)]*(powerbi|microsoftonline|windows\.net|analysis\.windows)[^[:space:]"'<>)]*#<ENLACE-ENMASCARADO>#Ig
s#[A-Za-z0-9.-]*powerbi\.com[^[:space:]"'<>)]*#<ENLACE-ENMASCARADO>#Ig
s#(://[^[:space:]"'<>)?]*)\?[^[:space:]"'<>)]*#\1?<oculto>#g
s#(^|[?&;[:space:]])(r|reportId|groupId|ctid|tenantId|access_token|sig)=[^&[:space:]"']+#\1\2=<oculto>#Ig
s#\$2[abxy]?\$[0-9]{2}\$[./A-Za-z0-9]{20,}#<HASH-BCRYPT>#g
s#\$argon2[a-z]*\$[^[:space:]"']+#<HASH-ARGON2>#g
s#\$A\$[0-9]{3}\$[^[:space:]"']+#<HASH-MYSQL>#g
s#\*[0-9A-Fa-f]{40}#<HASH-MYSQL>#g
s#\$[0-9]\$[A-Za-z0-9./$]{20,}#<HASH-CRYPT>#g
s#eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}(\.[A-Za-z0-9_-]*)?#<JWT-ENMASCARADO>#g
s#([Bb]earer|[Bb]asic)[[:space:]]+[A-Za-z0-9._~+/=-]{8,}#\1 <oculto>#g
s#-----BEGIN [A-Z ]*PRIVATE KEY-----.*#<CLAVE-PRIVADA-ENMASCARADA>#g
s#(IDENTIFIED[[:space:]]+(WITH[[:space:]]+[A-Za-z_0-9]+[[:space:]]+)?(BY|AS)([[:space:]]+(RANDOM[[:space:]]+PASSWORD|PASSWORD))?)[[:space:]]+('[^']*'|"[^"]*")#\1 <oculto>#Ig
s#([A-Za-z0-9_.-]*(PASS|PASSWD|PASSWORD|SECRET|TOKEN|APIKEY|API_KEY|PRIVATE_KEY|CREDENTIAL)[A-Za-z0-9_.-]*)([[:space:]]*[=:][[:space:]]*)[^[:space:]]+#\1\3<oculto>#Ig
