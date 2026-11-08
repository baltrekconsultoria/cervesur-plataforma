# Instantánea de los correctivos del backend Flutter heredado (UX-09A)

Estado: **preparados y probados en local; nada desplegado.** El backend heredado (`/var/www/cervesur/backend`, 33 archivos) **no está en este repositorio**: los parches se aplican sobre los archivos de producción, con respaldo y verificación previa en una copia. Esta carpeta es una **instantánea** para versionar los parches y sus resultados junto con el plan; la fuente de trabajo y las pruebas completas (réplica, esqueletos, cadena cliente → función → backend) están en `inspeccion-flutter/correctivos-v6/` fuera del repositorio.

| Carpeta | Contenido |
|---|---|
| `lote1-k2/` | K2a + K2c + K2d + K2e: sanitizador de respuestas, rechazo del cambio de credenciales en `PUT` y en el alta de empresas |
| `lote2-k1/` | K1: modos `observar`, `mixto`, `exigir` y **`retirar`** (410 sin tocar base ni Azure) |
| `lote3-a1/` | A1: perfil propio o administrador |
| `lote4-a2/` | F-4, F-5a a F-5g y **F-10** (registro cerrado) |
| `MANIFIESTO-SHA256.md` | Huella base de cada archivo de producción, huella de cada parche y del resultado cuando la base es exacta |
| `verificar-parches-en-copia.sh` | Comprueba, sin modificar nada, huella base y `patch --dry-run -F0` sobre una copia llamada `backend` |
| `resultados/` | Últimas corridas: suite v6 con regresión de K2 y mutaciones, y cadena completa |

Subconjunto recomendado a desplegar (ver `docs/UX-09A-ALTERNATIVAS-BACKEND-HEREDADO.md`): Lote 1, K1 «retirar», F-4 + F-5a a F-5c + F-10, y F-3. `F-5d/e/f/g` quedan preparados pero **no** se despliegan salvo que el retiro del legado supere las 12 semanas.

Cada despliegue exige autorización específica, respaldo con huella, ventana, verificación en copia y punto de parada. P4b y P5 siguen bloqueados.
