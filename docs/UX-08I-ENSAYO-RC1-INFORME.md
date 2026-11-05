# UX-08I · Ensayo obligatorio del RC1 de la Fase 2B — informe para revisión

Candidato validado: commit **`120ea841e12c05ec5f39825a3295969725483cb7`** (rama `feat/cervesur-ux-08i-fase-2b-usuarios`, árbol limpio).
Paquete: `cervesur-react-admin-fase2b-120ea841e12c-20261105-132240Z.tar.gz` · SHA-256 `3323035290ecdec3f44393d6c6825366983f8ab1830bea84c2ca7cb11cead585`.
**Reemplaza al paquete `25000c0`**: ese RC1 tenía un bloqueo de liberación (§3) que este ensayo encontró. Este informe es posterior a `120ea84` y solo añade documentación.

> **Actualización:** el candidato vigente es `ae28e6c57d5f9be7ac0806e4fa9bb7a805ed1e24` (paquete `…ae28e6c57d5f-20261105-141442Z`, SHA-256 `414b4b20eb29a3593eb8fab83f91e8ef979389309608e5482ab11e5855c5d465`), que añade el guardia de forma del alta y la corrección del texto sobre Flutter (ver `UX-08I-P2-INFORME.md` §8). El ensayo se repitió sobre él: **220 PASS / 0 FAIL**. Lo que sigue describe el ensayo de `120ea84`.

Entorno: réplica Docker (Ubuntu 24.04, Apache 2.4, mod_php 8.3, MySQL 8 con `validate_password` MEDIUM; Flutter simulado en 80, trabajadores solo en 192.168.200.12:443, QA 8094) con **la Fase 1 instalada de verdad y llevada a la Fase 2A (RC2 `f539c9f`) con el instalador real y B1/B2/B3 verificados uno a uno**. Datos 100 % sintéticos. Sin SSH, sin producción, sin push ni merge.
Comando: `node deploy/produccion-escritura/ensayo-docker-2b.mjs` → **220 PASS / 0 FAIL**.

## 1. Matriz de resultados
| Bloque | PASS | FAIL |
|---|---|---|
| Línea base 2A (RC2 operando: `admin_escritura`, 3 módulos, usuarios bloqueado, cuenta exacta de 2A, 2 triggers, lecturas, verificador) | 5 | 0 |
| P3 · código 2B (simular, negativas, restos, 3 fallos inyectados, real, repetición, reversión) | 24 | 0 |
| P4a · privilegios 2A → 2B (simular, negativas, fallo parcial, estado parcial persistente, columna ajena, real) | 20 | 0 |
| P4b · interruptor (simular/estado, confirmaciones, credenciales, sensible rechazado, fallo de verificación, encender) | 13 | 0 |
| P4c · operaciones (alta, edición, 409, desactivar/reactivar, atomicidad, pérdida de estado/permiso, Empresas intacta) | 18 | 0 |
| Recuperación integral | 15 | 0 |
| Higiene (ningún secreto en la salida de todas las ejecuciones de scripts) | 1 | 0 |
| Preparación de la réplica, Fase 1, A1, A2 y Paso B (la línea base se construye en cada ensayo) | 124 | 0 |
| **Total** | **220** | **0** |

Un intento intermedio dio 1 FAIL dentro de la verificación interna del instalador de la **Fase 1** (paso de preparación, ajeno a la 2B); no se repitió en dos corridas completas posteriores. Queda anotado como intermitente, no explicado.

## 2. Evidencia de instalación y rollback (P3)
- `--simular`: 0, sin tocar carpetas, `.env` ni código. Paquete manipulado → rechazado por SHA-256. Clave `ADMIN_WRITES_USUARIOS=0` en el `.env` → rechazado (el verificador de la 2A trata la sola presencia como prohibida).
- **Estados parciales**: una carpeta `.previo-*` de una ejecución interrumpida se identifica, el instalador se niega a seguir y no modifica nada.
- **Fallos inyectados** `respaldo` (aborta, sin respaldo a medias), `backend` (falla con la aplicación ya reemplazada y antes del backend) y `verificacion`: los tres terminan con la Fase 2A **exacta** (identidad archivo por archivo con RC2), `.env` byte a byte, sin restos, Apache sin recargas, y la Fase 2A completa operando.
- Instalación real: respaldo 700/600 con SHA-256, sin `.env`; lo instalado es el paquete 2B; `.env` y grants idénticos; Flutter/443/QA idénticos; Usuarios 403 `fase_2b_bloqueada`; una escritura real de Empresas con su auditoría; verificador `--etapa accesos` PASS.
- Repetición: «YA es este paquete», sin crear respaldos. `--revertir <respaldo>`: vuelve a la Fase 2A exacta; con Usuarios encendido se niega.

## 3. Resultado específico P4a → P4b y hallazgos
**BLOQUEO DE LIBERACIÓN (hallado y corregido): el RC `25000c0` devolvía 503 en la transición P4a.** El chequeo de arranque cruzaba `SHOW GRANTS` con los interruptores como violación fatal; al aplicar las plantillas 2B con `ADMIN_WRITES_USUARIOS` aún ausente (orden obligatorio: privilegios antes que interruptor) el backend se negaba a servir y habría interrumpido **todo** el administrador, Empresas, Reportes y Accesos incluidos. Estaba a la vista en la suite HTTP (`appawx` → 503) pero se interpretó como el comportamiento deseado.
Diseño de la transición segura (implementado, `120ea84`):
- Las concesiones **exactas de las plantillas UX-08I** con el interruptor apagado ya no son fatales: no amplían nada, porque la **puerta de rutas y el guardia de SQL siguen cerrados**; la capacidad efectiva no cambia ni temporalmente.
- Siguen siendo **fatales** (503): columnas ajenas a las plantillas (p. ej. `UPDATE(username)`), `INSERT`/`UPDATE` de tabla completa, y cualquier privilegio de más. Probado en la réplica: `UPDATE(username)` → 503; al retirarlo, vuelve a servir.
- Medido en la réplica: tras `--aplicar no-sensibles` con la clave ausente → ping 200, login OK, `usuarios=false`, `users_sensitive=false`, las 6 rutas de Usuarios 403 `fase_2b_bloqueada`, una escritura de Empresas OK, tabla `user` sin cambios. Orden seguro reforzado: `encender` se niega si los privilegios no están verificados.
- El motor confirma los permisos: la cuenta no puede `UPDATE role/password`, ni leer `password`, ni `DELETE` en `user`.

**Fallos parciales de GRANT/REVOKE** (no transaccionales): (a) fallo inyectado en el aplicar → salida 20, el script informa y deshace → cuenta idéntica a 2A; (b) estado parcial persistente (solo el `INSERT` concedido) → `--verificar` falla (30) e identifica «otro», el backend sigue sirviendo, `--aplicar` se niega, y `--revertir` lo recupera a la Fase 2A **exacta** (se toleran los `REVOKE` de lo no concedido; nada más); (c) fallo a mitad de la reversión → salida 20, estado parcial identificado, segunda reversión completa. Hallazgo corregido: antes `--revertir` no aceptaba estados parciales.

**P4b**: confirmación `CERVESUR_CONFIRMAR_USUARIOS=SI`, texto de autorización y credenciales son obligatorios (cada omisión se niega sin tocar el `.env`); `sensible` se rechaza siempre; un fallo de verificación inyectado restaura el `.env` **byte a byte**; encendido real → solo `ADMIN_WRITES_USUARIOS=1` (la clave sensible ni existe), capacidades `usuarios=true`, `users_sensitive=false`; rol y contraseña → 403 `escritura_desactivada` y los hashes de `role`+`password` de todos los usuarios no cambian. Controles sensibles en el frontend: la interfaz los oculta cuando `users_sensitive=false` (prueba unitaria Vitest + capacidad real devuelta por el backend); **no se renderizó el navegador en el ensayo**.

## 4. P4c · operaciones (usuarios sintéticos)
Alta: rol `user`, hash únicamente, **una** auditoría `user.create` sin contraseña, hash, correo ni teléfono; reintento → 409; `role` en el cuerpo → 400. Edición: ignora `role/username/is_active/password`; una auditoría. Escritura simultánea de «Flutter» → 409 sin cambio ni auditoría. Desactivar: `session_version +1`, una auditoría; repetir → 409; reactivar antes de 24 h → 409; no a uno mismo. **Atomicidad**: sin permiso `INSERT` en `audit_log`, alta, edición y hasta una empresa fallan (5xx genérico) sin dejar nada; restablecido el permiso la cuenta recupera sus grants exactos. **Pérdida de estado**: administrador inactivado o degradado a mitad → 401/403 y la operación no se aplica. Empresas con Usuarios ON: alta con su auditoría. Bitácora de solo inserción y con acciones únicamente `user.create/update/deactivate`.

## 5. Recuperación integral
Apagar (claves eliminadas, `.env` **byte a byte** igual a la línea base) → revertir privilegios (con fallo a medias y recuperación) → revertir código. Final: grants **idénticos** a la Fase 2A, sin claves `ADMIN_WRITES_USUARIOS*`, código = RC2, 3 módulos operando, verificador de la Fase 2A PASS, Flutter/443/QA idénticos, y **datos intactos** (la recuperación no toca datos; las filas de prueba y su auditoría permanecen: nada se borra). Los respaldos se conservan.

## 6. Veredicto GO/NO-GO por paso (sobre el candidato `120ea84`)
| Paso | Veredicto | Condiciones |
|---|---|---|
| **P3** código con Usuarios OFF | **GO técnico** | P2 (autorización DBA y negocio + respaldo verificable; **hoy no existe prueba de restauración**). Simular primero en el servidor. No ensayado: el 443 real y los certificados reales, solo su réplica. |
| **P4a** privilegios no sensibles | **GO técnico**, condicionado | Tras P3 verificado; autorización explícita del DBA; respaldo <24 h; `--simular` en 0. La transición ya no produce 503. |
| **P4b** interruptor | **GO técnico**, condicionado | Tras P4a `--verificar`; una operación real autorizada y su fila de auditoría (P4c). El riesgo de Flutter (R1–R4) sigue vigente: el arnés es una reconstrucción. |
| P5 rol y contraseña | **NO-GO / fuera de alcance** | No hay forma de encenderlos con este kit. |

Pendientes que este ensayo NO puede cerrar: prueba de restauración del respaldo (R9), excepción `debian-sys-maint` (R10), ID 114 (R11), backend real de Flutter, navegador real sobre la interfaz.

## 7. Procedimiento productivo con puntos de parada
Cada paso se detiene si el anterior no dio PASS; cada paso requiere su autorización. `PARAR` = no continuar y recuperar.
1. **P2** respaldo + autorizaciones. PARAR si no hay respaldo <24 h.
2. **P3** `instalar-codigo-2b.sh --simular` → 0; `--aplicar`. Fallo = vuelve solo (salida 20); si sale 21, `--revertir <respaldo>`. Verificar Usuarios 403 y 2A.
3. **P4a** DBA: `privilegios-usuarios.sh --etapa no-sensibles --simular` → `--aplicar --respaldo … --confirmo-autorizacion …` → `--verificar` (PASS). Estado parcial → `--revertir`. PARAR ante «otro» no recuperable o 503.
4. **P4b** `interruptor-usuarios.sh encender`. Si las capacidades no son las esperadas se restaura solo; manual: `apagar`.
5. **P4c** UNA operación real autorizada; exactamente una fila en `audit_log`. Cualquier otra cosa → `apagar`.
6. Recuperación total, en este orden: `interruptor-usuarios.sh apagar` → `privilegios-usuarios.sh --revertir` → `instalar-codigo-2b.sh --revertir <respaldo>`.

**No se desplegó nada en producción.** Esperando revisión y autorización independiente de cada paso.
