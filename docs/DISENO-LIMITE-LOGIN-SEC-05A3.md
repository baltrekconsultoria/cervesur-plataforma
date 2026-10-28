# SEC-05A.3 — Diseño de limitación de intentos de login (SOLO DISEÑO)

Estado: **propuesta para revisión**. No hay código, tabla ni migración asociados. Nada de esto se aplica sin aprobación explícita.

## 1. Problema (B-03)
`POST /api/login` no limita intentos: permite adivinar contraseñas y enumerar cuentas sin freno. PHP no comparte memoria entre procesos (php-fpm/Apache), así que un contador en memoria, `$_SESSION` o APCu por proceso no sirve: cada proceso vería un contador distinto.

## 2. Requisitos
- Seguro con N procesos/servidores PHP concurrentes (estado en un almacén compartido y atómico).
- Límite por cuenta **y** por IP (cubre fuerza bruta a una cuenta y *password spraying* desde una IP).
- Bloqueo temporal con retroceso, no permanente (un bloqueo permanente permite denegar el servicio a cualquier usuario).
- Respuesta uniforme: mismo cuerpo/forma exista o no la cuenta, esté bloqueada o no.
- Compatibilidad con el contrato histórico: el fallo de login hoy es HTTP 200 `{success:false,"Credenciales inválidas"}`.

## 3. Almacén propuesto: tabla en MySQL (la que ya usa el sistema)
Evita dependencias nuevas (Redis/APCu). La atomicidad la da `INSERT … ON DUPLICATE KEY UPDATE`.

```sql
-- PROPUESTA (no ejecutar todavía)
CREATE TABLE login_attempt (
  scope        ENUM('account','ip') NOT NULL,
  subject_hash CHAR(64)    NOT NULL,        -- HMAC-SHA256(clave del servidor, login normalizado | IP). Nunca el dato en claro.
  window_start DATETIME    NOT NULL,        -- inicio de la ventana de conteo
  failures     INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME    NULL,
  updated_at   DATETIME    NOT NULL,
  PRIMARY KEY (scope, subject_hash),
  KEY idx_updated_at (updated_at)           -- para purga
) ENGINE=InnoDB;
```

- Clave HMAC con secreto del servidor (variable de entorno distinta de `JWT_SECRET`): la tabla no revela cuentas ni IP reales aunque se filtre.
- La cuenta se normaliza (trim + minúsculas) **antes** de aplicar el HMAC y se cuenta aunque no exista en `user` (no hay diferencia observable).

## 4. Algoritmo
1. Normalizar `login`; calcular `account_key` e `ip_key`.
2. `SELECT … FOR UPDATE` (o lectura simple) de ambas filas. Si `locked_until > NOW()` en cualquiera → responder **como un fallo normal**, sin verificar la contraseña (y sin coste de `password_verify` extra).
3. Si no está bloqueado: verificar credenciales como hoy. Para no filtrar si la cuenta existe, ejecutar `password_verify` contra un hash ficticio cuando la cuenta no exista (igualar tiempos).
4. Fallo: por cada scope `INSERT … ON DUPLICATE KEY UPDATE failures = IF(window vencida, 1, failures + 1)`; si `failures` ≥ umbral, fijar `locked_until = NOW() + duración`.
5. Éxito: borrar/reiniciar solo la fila de la **cuenta** (no la de IP: un atacante no debe limpiar su contador acertando una cuenta propia).
6. Purga periódica de filas con `updated_at` antiguo (tarea o purga oportunista, p. ej. 1 de cada 100 peticiones).

### Umbrales iniciales (a calibrar con datos reales)
| Scope | Ventana | Fallos | Bloqueo |
|---|---|---|---|
| Cuenta | 15 min | 5 | 15 min, duplicando en bloqueos sucesivos hasta 1 h |
| IP | 15 min | 30 | 15 min |
La IP tiene un umbral mayor porque varias personas de una empresa pueden compartir NAT.

## 5. Respuesta uniforme
- Siempre el mismo HTTP 200 + `{success:false,message:"Credenciales inválidas"}` (contrato actual; Flutter y React ya lo manejan).
- **No** devolver 429 ni `Retry-After` en la primera versión: revelaría el bloqueo de una cuenta concreta (enumeración) y podría romper al cliente Flutter. Opcional posterior: 429 **solo por IP** (no revela datos de cuentas) tras acordarlo con los clientes.
- Mismo tiempo de respuesta aproximado en todos los caminos.

## 6. Riesgos y mitigaciones
| Riesgo | Mitigación |
|---|---|
| **Denegación de servicio dirigida**: un atacante bloquea una cuenta ajena a propósito | Bloqueo corto y con retroceso; no permanente; el login correcto desde una IP con buen historial podría excluirse del bloqueo de cuenta (evaluar); alertar al administrador |
| IP compartida (NAT, VPN corporativa) bloquea a todos | Umbral IP alto; no bloquear la IP a partir de fallos de una sola cuenta |
| **IP incorrecta** detrás de proxy/balanceador: `REMOTE_ADDR` sería la del proxy, o se confía en `X-Forwarded-For` falsificable | Usar `REMOTE_ADDR` por defecto; aceptar `X-Forwarded-For` solo si `REMOTE_ADDR` está en una lista de proxies de confianza configurada (decisión de infraestructura pendiente) |
| Contención/bloqueos en MySQL bajo ataque | Upsert atómico sobre PK; sin transacciones largas; índice de purga; el limitador **no** debe tumbar el login (si falla la tabla: política *fail-open con registro* vs *fail-closed*; recomendado fail-open + alarma, porque fail-closed deja a todos sin acceso) |
| Crecimiento de la tabla (ataque con cuentas inventadas) | Claves HMAC fijas por sujeto, purga por antigüedad, tope de filas |
| Relojes entre servidores | Usar `NOW()` de MySQL, nunca la hora de PHP |
| Enumeración por tiempo o por mensaje | Hash ficticio + respuesta idéntica + cuenta inexistente también cuenta |
| Cuentas administrativas son el blanco principal | Posible umbral más estricto para `role=admin` (sin revelarlo) y alerta |

## 7. Alternativas descartadas
- Contador en APCu/memoria/`$_SESSION`: por proceso, inútil con varios procesos y evadible sin cookie.
- Redis: la opción técnicamente más cómoda, pero añade infraestructura y dependencia no autorizada.
- Bloqueo en Apache/WAF por IP: útil como capa extra, pero no distingue cuentas y es ajeno al código; complementario, no sustituto.
- CAPTCHA: impacta a los clientes Flutter/React; queda para una fase posterior si hay abuso.

## 8. Plan de adopción (cada paso con aprobación)
1. Aprobar este diseño y decidir: almacén (tabla MySQL), umbrales, política de IP/proxy, fail-open/closed.
2. Migración SQL revisada (crear `login_attempt`) → probar **solo** en MySQL sintético; script de reversa (`DROP TABLE`).
3. Implementar tras una interfaz (`LoginThrottle`) con pruebas: concurrencia (varios procesos PHP simultáneos), bloqueo/expiración, respuesta uniforme, éxito reinicia cuenta pero no IP, fail-open ante error de BD.
4. Primero en modo **observación** (solo registra qué bloquearía) antes de bloquear de verdad.
5. Despliegue gradual; monitorizar tasa de falsos positivos.
