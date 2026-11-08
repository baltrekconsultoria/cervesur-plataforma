# UX-09B · D3 · Pruebas de no-elusión

Estado: **ejecutado localmente. Sin fallos en lo que se pudo ejercitar; 8 pruebas INCONCLUSAS por archivos que no están disponibles.** Todo con datos sintéticos, sin red, sin producción. RC1c `2f88cfb` intacto; P4b y P5 bloqueados.

> **Actualización (cierre de los 8 INCONCLUSOS):** ver `UX-09B-D3-CIERRE-Y-P4B.md`. Resultado: **ninguno de los 8 casos pasa a PASS** porque los archivos reales no están en ninguna copia local; sí se avanzó con evidencia real (`jwt.php` de producción exacto: 15 PASS; `index.php` y `authenticate_user()` literales; `UserController` exacto) y apareció un **hallazgo nuevo** sobre sesiones antiguas y roles obsoletos. 0 FAIL en 2 207 peticiones reales a routers. Veredicto de P4b: **NO-GO**.

## 1. Veredicto específico de D3

> **D3 = PARCIAL, SIN FALLOS.** Ninguna variante probada permitió ejecutar una operación prohibida ni dejó datos o auditoría falsos. **No basta por sí sola para habilitar P4b**, porque la autenticación real del legado, `index.php` y los controladores reales del legado no están en la copia local (8 INCONCLUSOS, sección 5) y D4 sigue siendo evidencia de infraestructura.

| Alcance | Qué se ejerció | Resultado |
|---|---|---|
| **Legado**: routers reales + parches reales (árbol `v6`, `patch -F0`) | 1 872 peticiones reales a los routers | **154 PASS · 0 FAIL · 8 INCONCLUSO · 7 INFO** |
| **RC1c**: pila real (index.php + `auth_middleware.php` + MySQL 8) | matriz de sesión existente + 51 comprobaciones nuevas | **ALL PASSED + 51/51** |

El detector no es vacío: en el árbol **sin parches** el mismo ataque produce efecto en las escrituras de A2 (control G1b) y el registro anónimo llega al controlador (control G7).

## 2. Matriz por grupo (legado)

Detalle completo, un caso por fila: `deploy/flutter-heredado/d3/matriz-d3-legado.csv`.

| Grupo | Qué prueba | Casos | Resultado |
|---|---|---|---|
| G1 URL y método | Las 15 escrituras de A2, perfil ajeno y cambio de rol (17 rutas): canónica sin sesión (401) y como usuario normal; 11 métodos distintos (PATCH, HEAD, OPTIONS, TRACE, minúsculas, con espacio o salto de línea); 14–19 variantes de URL (barra final, `//`, `/./`, mayúsculas, `?role=admin`, `#`, `%00`, `\n` final, espacio, `.json`, `/v1`, `..`, singular/alias, id con ceros, `%3x`, `+`, decimal, sufijo) × 3 métodos | 72 PASS |
| G1b Control | Árbol sin parches: el detector ve el efecto | 1 PASS |
| G2 Identidad | `role=admin`, `user_id=1`, `created_by`, `updated_by` en cuerpo, query, `$_POST` y encabezados `X-Role`, `X-User-Id`, `X-HTTP-Method-Override`, `_method`: ninguna de las 17 rutas se eleva; un administrador legítimo no pierde la operación por datos del cliente | 37 PASS |
| G3 Roles | 10 valores de rol no administrativos (`''`, `USER`, `admin `, ` admin`, `administrador`, `superadmin`, `root`, `0`, `adm`) rechazados en 6 routers | 10 PASS · 3 INFO |
| G4 A1 | Perfil ajeno GET/PUT con 10 identificadores ajenos o deformados (`1`, `3`, `4`, `5`, `03`, `+3`, `3abc`, `3.0`, desbordado, `%33`); propio y administrador siguen funcionando; `PUT /api/user/profile` con id ajeno en el cuerpo usa la sesión | 7 PASS · 1 INCONCLUSO |
| G5 K1 | Modo `retirar`: 7 métodos × 17 URL × 3 identidades = 410 o sin ruta, sin token y sin tocar la base; 9 valores de modo mal escritos fallan **cerrado** (401); `exigir`: anónimo 401, no asignado 403, asignado pasa | 9 PASS · 1 INFO |
| G6 K2 | 8 variantes de la clave del secreto × 3 modos (mayúsculas, espacios, `\u0000`, escape Unicode); secreto como matriz, objeto, número, clave duplicada, anidado, por formulario/`$_POST`; alta con secreto; lecturas de empresa (usuario y admin) sin secreto | 9 PASS · 1 INCONCLUSO |
| G7 A3 | `POST /api/register` cerrado para anónimo, usuario y admin (403, sin cuenta); `REGISTRO_ABIERTO` como `'true'`, `1` o `false` **no** lo abre; 5 métodos × 17 URL con override | 4 PASS · 1 INFO · 1 INCONCLUSO |
| G8 Estático | Ningún router/librería real lee GET/POST/REQUEST/COOKIE/FILES ni encabezados para decidir identidad, rol o método (3 usos conocidos y justificados); inventario exacto de 22 sitios de escritura; ninguna ruta sin ancla; `\n` final pasa por la misma guardia | 5 PASS · 2 INFO |

### RC1c (pila real)

| Bloque | Qué prueba | Resultado |
|---|---|---|
| Matriz de sesión existente | caducado, sin `session_version`, otro secreto, firma alterada, rol desde MySQL, rol inesperado | ALL PASSED |
| A Authorization | ausente, vacía, `Bearer` sin token, sin esquema, `Basic`, basura tras el token, dos tokens en una cabecera, `alg=none`, firma vacía, otro algoritmo, 70 000 bytes, cabecera duplicada en ambos órdenes | 401; duplicada no abre rutas de administrador |
| B URL/método | 12 variantes × 3 métodos sobre una ruta de administrador y 9 × 3 sobre `/api/register` con encabezados falsos de rol | nada se abre, nada se crea, **0 filas de auditoría por peticiones rechazadas** |
| C A1 | perfil ajeno con 6 formas de identificador, `PUT /api/user/profile` con id ajeno | 403/404, administrador sin cambios |
| D Sesión | usuario inactivo y sesión revocada con token válido | 401 |
| Controles positivos | administrador legítimo, usuario legítimo, alta del administrador | funcionan; el alta con `role` en el cuerpo se rechaza (400) y sin él deja su auditoría |

## 3. Hallazgos que condicionan P4b

1. **K1 no queda cerrado con solo desplegar el Lote 2.** En el modo por defecto `observar` el token anónimo **se sigue entregando** (INFO en G5). P4b necesita la atestación de que `POWERBI_AUTH_MODE=retirar`.
2. **La identidad es el `user_id` firmado** (A16 de RC1c; igual en el legado): quien posea `JWT_SECRET` puede acuñar cualquier sesión. Es lo esperado, y por eso D4 (atestación de `JWT_SECRET`) sigue siendo un requisito, no un trámite.
3. **Comportamiento heredado, sin cambio:** la guardia del legado acepta `Admin`/`ADMIN` (compara `strtolower`). No es una elusión; el emisor del JWT fija el rol.
4. **Hipótesis [R], no probada:** según C-12, el middleware real del legado toma el rol del JWT y acepta tokens sin `session_version` o sin `user_id`. Si es así, una baja o un cambio de rol no surte efecto en el legado hasta que caduque el token (`JWT_EXPIRATION`). No hay ningún parche del legado para esto en los lotes. **Se probará sobre una copia del archivo real**; hasta entonces es INCONCLUSO.

## 4. Cobertura de los criterios de aceptación

| Criterio | Evidencia |
|---|---|
| Ninguna variante ejecuta una operación prohibida | G1, G2, G3, G4, G7 (legado); B y C (RC1c) |
| Sin autorización → rechazo HTTP correspondiente | 401 sin sesión; 403 usuario normal; 410 K1 retirado; 404/«sin ruta» para variantes que no existen |
| Sin modificación de datos ante rechazos | cada caso compara el hash de **todas** las tablas antes y después |
| Sin auditoría falsa | RC1c B02a: 0 filas de auditoría por rechazos. En el legado no hay bitácora que medir (INCONCLUSO para la auditoría del legado: no existe en ese backend) |
| Acciones legítimas siguen | admin en las 15 escrituras, perfil propio, perfil ajeno como admin, alta del admin en RC1c, usuario asignado en K1 `exigir` |
| Datos sintéticos | sí; el escaneo de salida no contiene secretos reales (los `SYNTH-*` son marcas) |
| Una prueba con mock no cuenta como PASS | el sustituto del middleware solo fija identidad; las guardias de rol y de A1 viven en los **routers reales**. Lo que dependía del sustituto está en G9 como INCONCLUSO |

## 5. INCONCLUSOS y limitaciones

| # | Qué no se pudo probar | Por qué | Cómo se resuelve |
|---|---|---|---|
| 1 | Authorization ausente/deformada/duplicada/inválida **en el legado** | `lib/auth_middleware.php` real del legado no está local | Prueba D3 sobre la copia de seguridad de la ventana (la suite lo admite sustituyendo el archivo) |
| 2 | Cuentas inactivas y sesiones revocadas **en el legado** | idem | idem |
| 3 | Normalización de URL y override de método en el front controller **del legado** | `index.php` real del legado no está local | idem; mientras tanto se entregó a los routers la peor variante posible |
| 4 | `JWT_SECRET` definido y no literal (D4) | no es propiedad del código | Atestación de infraestructura |
| 5 | Controladores/modelos reales de Reporte, Empresa-Reporte-Usuario y Empresa | solo existen como esqueletos con las líneas impresas por C-3 | `verificar-parches-en-copia.sh` y humo de la ventana |
| 6 | `UserController` real: que el perfil propio ignore correo/rol/contraseña | stub | copia de seguridad antes de A-L3 |
| 7 | Rutas alias hacia el alta en `index.php`/`UserController` reales | idem | idem |
| 8 | `EmpresaController` real: otra salida del secreto | esqueleto | idem |

Otras limitaciones: SQLite en lugar de MySQL para el legado (las guardias son de PHP, no de SQL); los 4 routers con SHA exacta son [X] y el resto está construido sobre texto literal [R]; la matriz de sesión y las pruebas de RC1c prueban RC1c, no el legado.

## 6. Correctivos propuestos

Ninguno por fallos (0 FAIL). Propuestas de evidencia, no de código:

1. Pedir la atestación de `POWERBI_AUTH_MODE=retirar` en la ventana del Lote 2 (y repetir `K1` desde fuera de la red).
2. Pedir la atestación D4 (`JWT_SECRET` definido, no literal, longitud; `JWT_EXPIRATION`).
3. En la ventana del Lote 4, con copia de seguridad en mano, ejecutar esta misma suite sustituyendo el middleware, `index.php` y los controladores por los reales: convierte los INCONCLUSOS 1, 2, 3, 6, 7 y 8 en PASS o FAIL.
4. Si la hipótesis 4 de la sección 3 se confirma, decidir entonces (no antes) si se acepta el riesgo con `JWT_EXPIRATION` corto o se porta la validación estricta al legado.

## 7. Reproducir

```
# Legado (routers y parches reales; contenedor sin red):
docker run --rm --network none -v <correctivos-v6>:/w:ro -v <salida>:/out php:8.3-cli php /w/tests/d3/d3_no_elusion.php /w /out
# RC1c (pila real con MySQL desechable), desde la raíz del repositorio:
bash tests/isolated/probar-d3-rc1c.sh
```

Archivos: `deploy/flutter-heredado/d3/` (scripts, `matriz-d3-legado.csv`, `resultado-d3-legado.txt`, `resultado-d3-rc1c.txt`) y `tests/isolated/d3_rc1c_test.php`.
