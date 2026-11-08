# Solicitud a Infraestructura · D4 y entrega de fuentes (solo lectura)

**Este documento no autoriza nada por sí mismo.** Las acciones de abajo solo se ejecutan después de que el responsable funcional firme el **adendo A-C5b** (sección 4). Ninguna modifica el backend, el `.env`, Apache, Firebase ni la base. Servidor: `192.168.200.12`. Quien ejecuta: la persona de Infraestructura con su cuenta nominal.

## 1. Verificar los archivos antes de usarlos

```
sha256sum -c SHA256SUMS
```
Debe imprimir `OK` para `d4-atestacion-solo-lectura.sh` y `entrega-fuentes-enmascaradas.sh`. Si no, **detenerse e informar**.

## 2. Atestación D4 (decisión funcional: `JWT_EXPIRATION` previsto = 86400 s)

```
bash d4-atestacion-solo-lectura.sh --expiracion-prevista 86400 > salida-d4-<fecha>.txt
```
- Solo lee el `.env` y los archivos PHP; **no imprime** el secreto, valores de `.env`, JWT ni contraseñas; no hace peticiones de red; no escribe en el servidor salvo la redirección de la salida a su propio archivo.
- Entregar `salida-d4-<fecha>.txt` completo. `PENDIENTE` **no es aprobación**.
- Si `JWT_SECRET` no está en el `.env` (se define en el entorno de Apache/PHP-FPM), el script lo marcará `PENDIENTE`: **no** averiguarlo imprimiéndolo. Indicar por escrito dónde se define y confirmar, sin mostrar el valor, que es distinto de los literales por defecto de los routers.

### Prueba funcional D4-08b (K1)
Desde una máquina con acceso al sitio, **sin** `Authorization`:
```
curl -s -o /dev/null -w "%{http_code}\n" http://<sitio>/api/powerbi/tokens/0
```
Anotar el código. **410** = modo `retirar` efectivo. **400** = `observar` o `mixto`. **401** = `exigir`. Con el id `0` no se emite ningún token en ningún modo. Hoy se espera 400 o 404 si el Lote 2 aún no está desplegado.

## 3. Entrega de fuentes reales (para repetir D3)

```
bash entrega-fuentes-enmascaradas.sh --destino /ruta/vacia/fuera/del/backend
# o, para dejar la réplica sin esqueletos:
bash entrega-fuentes-enmascaradas.sh --destino /ruta/vacia/fuera/del/backend --completo
```
- Copia los `.php` de la lista (mínimo: `lib/auth_middleware.php`, `lib/db.php`, `lib/jwt.php`, `lib/env.php`, `router/index.php`, `controllers/EmpresaController.php`, `models/EmpresaModel.php`, `controllers/UserController.php`, `models/UserModel.php`, `router/StatsRouter.php`, `router/wiki.php`), con los literales secretos reemplazados por `<MASK>`.
- Genera `HUELLAS-ORIGINALES.txt` (SHA-256 completo y bytes de **cada original**, para contrastar con la tabla de trazabilidad) y `HUELLAS-ENMASCARADOS.txt`.
- **Nunca** copia el `.env`. El destino debe estar fuera del backend y vacío (el script lo exige).
- Antes de enviar: revisar a ojo que no quede ninguna credencial. Entregar por el canal acordado, no por correo abierto.

## 4. Adendo A-C5b (para firma del funcional; no está firmado)

Yo, ______________________ (responsable funcional), con fecha ____ / ____ / ________, **amplío A-C5** para autorizar, además de lo ya firmado, la ejecución de **solo lectura** de:

1. `d4-atestacion-solo-lectura.sh` (huella `31e63a1b74db10838f23fbc38a6099f86c0a137058e3a75bcffca5317e591842`) con `--expiracion-prevista 86400`.
2. `entrega-fuentes-enmascaradas.sh` (huella `99740e64ab2ed6228d9ddf2927f99a33a3d49f0111f81b345e3290cf7d664619`), con destino fuera del backend.
3. La prueba `GET /api/powerbi/tokens/0` sin `Authorization` descrita en 2.

Ejecuta: ______________________ (cuenta ______________). Salidas por el canal: ______________________.
**No autoriza:** modificar archivos del servidor, el `.env`, Apache, Firebase o la base; desplegar lotes; rotar `JWT_SECRET`; ni ejecutar P2, P3, P4, P5.

Firma (funcional): ______________________

## 5. Orden sugerido y qué se devuelve

1. Firmar A-C5 y A-C5b (y A, si el DBA ya decidió dónde restaurar).
2. Ejecutar C-5 (partes 1 y 2), D4, la prueba D4-08b y la entrega de fuentes.
3. Devolver: salidas de C-5 censuradas, `salida-d4-<fecha>.txt`, el código HTTP de D4-08b, y la carpeta de fuentes enmascaradas con sus huellas.
4. Con eso se repite D3 sobre los archivos reales y se presenta el resultado consolidado **antes** de pedir autorización para P4b.
