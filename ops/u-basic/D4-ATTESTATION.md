# Cervesur – D4 v2: atestación JWT y F-10 actual (solo lectura)

Este kit comprueba el backend PHP heredado real antes de habilitar U-BASIC. No modifica aplicación, variables .env, MySQL ni usuarios, y no imprime valores de JWT_SECRET, contraseñas ni tokens.

## Necesidad

D4 anterior buscaba la constante REGISTRO_ABIERTO, pero F-10 instalado usa guardia JWT y admin con marcador F10-20261111 y SHA fijada. Eso producía un falso FAIL. La variante D4 v2 coteja huella F10 exacta y H1; comprueba K1 fallback retirar y GET anónimo HTTP 410, expiración JWT 86400 segundos y fortaleza de JWT_SECRET leída de .env, y cuenta los literales de secreto por defecto sin ocultar la deuda D4-06b.

## Uso autorizado (solo tras CI y cotejo Git hash)

Transferir ops/u-basic/attest_d4.py desde el commit aprobado a /opt/cervesur-u-basic-kit. No descargar una rama mutable ni subir .env a GitHub.

    sudo python3 /opt/cervesur-u-basic-kit/attest_d4.py

Salida: D4-01..09 y marcas de F10/H1, D4_CRITICOS PASS o NO_GO, JWT_FALLBACKS_REMOVIDOS SI/NO, QA_HTTP_USER_ADMIN=PENDIENTE, ACTIVACION_U_BASIC=NO_REALIZADA.

## Interpretación

- D4_CRITICOS=PASS valida archivo/configuración y GET K1 410, no la autenticación de roles en HTTP. La firma F10/H1 se comprueba por SHA, sin asumir que el antiguo detector REGISTRO_ABIERTO siga vigente.
- D4-06b=PENDIENTE_LITERAL_POR_DEFECTO_CANTIDAD_N NO es PASS: deben evaluarse los N JWT fallback aún presentes en código, especialmente si una ruta carece de JWT_SECRET efectivo.
- D4-07 PASS significa línea .env 86400 y no constituye demostración de la expiración efectiva de JWT emitido; probar con nueva sesión y decodificación local segura, sin exponer token.
- D4-08b=PASS_HTTP_410 es GET anónimo al endpoint de prueba conocido. No acredita otros aliases.
- D4-09 PASS verifica versión esperada de F10 y no implica que el flujo de alta Flutter esté adaptado.
- QA negativa del usuario regular/admin (F10 -> 403; A1 perfil ajeno -> 403; A2 POST/PUT/DELETE -> 403) y cierre D3/continuidad/firmas siguen siendo puertas antes de P4b.
- 2B privileges no-sensibles y respaldo P2/restore ya dieron PASS real y se registraron en Issue #24. El interruptor U-BASIC y P5 permanecen OFF.

No se ejecutará el interruptor de usuarios ni se concederán permisos con este script. No compartir .env ni tokens con el asistente. Si una validación devuelve FAIL/NO_GO, no reinterpretar el resultado como verde.
