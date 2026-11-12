# D3 H1 — Ensayo sintético de autenticación heredada (preparación P4b)

**Carácter:** ensayo **sin red, sin sesiones reales, sin lectura de datos personales, sin MySQL productivo y sin escritura productiva**. No habilita U-BASIC. Sustituye la necesidad de copiar JWT de usuarios al chat; **no sustituye las pruebas de autorización completa de A1/A2/A3 ni firmas**.

## Controles de seguridad de la ejecución

- Archivo: `ops/u-basic/d3_auth_synthetic.php`. Requiere PHP CLI con `pdo_sqlite`; si la extensión no existe informa `NO_GO_PHP_EXTENSION_O_HEADER` sin intentar conectar a MySQL.
- Verifica el SHA-256 **exacto** del middleware H1 firmado `c717dcb9...` y el prefijo atestado de `jwt.php` `b2fe4af95848` antes de cargar código. Si hay drift, **ABORTA**. No sustituir los SHA ni saltarse la validación.
- Crea cuatro usuarios **ficticios** en una base `sqlite::memory:`, que no utiliza el esquema productivo. El secreto JWT es sintético y literal de laboratorio; no consulta `.env`.
- Mediante `authenticate_user()` **real** de producción prueba sesiones válidas admin/user, usuario inactivo, sesión revocada, ID inexistente, firma ajena, JWT caducado, cabecera ausente y token malformado.
- Expone tres hallazgos de compatibilidad sin ocultarlos: rol embebido en JWT, token firmado sin `session_version` y token firmado sin `exp`. Estos casos, si se aceptan, requieren análisis y controles compensatorios formales; **no** se convierten automáticamente en PASS.
- No importa `db.php`, no arranca PHP-FPM/Apache, no genera solicitudes HTTP y no escribe usuarios ni auditoría reales. El resultado solo muestra estado de casos y conteos, nunca tokens o nombres.

## Uso en PuTTY

**1. Preflight sin tocar servicios:**

```bash
php -r 'echo "PHP_CLI=", PHP_VERSION, "\n"; echo "PDO_SQLITE=", extension_loaded("pdo_sqlite") ? "SI" : "NO", "\n"; echo "CLI_GETALLHEADERS=", function_exists("getallheaders") ? "SI" : "NO", "\n";'
```

Se requiere `PDO_SQLITE=SI` y `CLI_GETALLHEADERS=NO`. No instalar extensiones en el servidor para satisfacer el ensayo; si faltan, registrar bloqueo y usar entorno aislado autorizado.

**2. Instalar con SHA fijado a un commit inmutable verificado** (ver instrucción operativa en el PR). No descargar `main` mutable ni cambiar fuentes del legado.

**3. Solo lectura + prueba en RAM:**

```bash
sudo php -l /opt/cervesur-u-basic-kit/d3_auth_synthetic.php
sudo php /opt/cervesur-u-basic-kit/d3_auth_synthetic.php --backend /var/www/cervesur/backend
```

Si los nueve casos principales dan `D3_AUTH_CRITICOS=PASS_9_DE_9`, se acredita **solamente** el middleware H1 real con fixtures sintéticos. Las tres líneas `D3_RIESGO_*` pueden contener `HALLAZGO`: conservarlas en el informe, no editar la salida para dar un PASS total.

## Matriz: qué cierra y qué NO cierra

| Alcance de D3 | Qué demuestra este ensayo | Pendiente |
|---|---|---|
| 1. JWT/Authorization | Rechazos básicos de cabecera/JWT y secretos ajenos; misma función real H1 | Cabeceras duplicadas/proxy y otros vectores |
| 2. Sesión, baja, revocación | `is_active` y `session_version` contrastados en RAM | Comportamiento con sesión real tras baja P4c, tokens legacy sin SV |
| 3. URL/rutas | No cubre | Normalización, override, aliases con rutas reales |
| 4. Entorno JWT | D4 separado ya PASS | Riesgo de token firmado sin exp/sv según política |
| 5. Controllers/models | No cubre | Controles A2 en funciones reales |
| 6. Perfil ajeno | No cubre | A1 sobre controlador y modelo real |
| 7. Alias de alta | No cubre | Prueba F-10 rutas de alias |
| 8. Empresa/secretos K2 | No cubre | Verificación de K2 en controlador/modelo real |

## Criterio formal para autorizar P4b

El script **no emite D3 global PASS** aunque los nueve casos den PASS. El cierre de D3 requiere la revisión y las pruebas de las rutas restantes (especialmente A1/A2/A3), más las firmas de responsables de seguridad/DBA/funcional, y confirmar continuidad del alta Flutter tras F10. El usuario 443 con token opaco **no** tiene una sesión JWT intercambiable con Flutter 80. Ni P2/P4a/D4 previos ni la UI 8443 justifican por sí solos encender el interruptor.

PR específico en borrador; no hacer merge, activar `ADMIN_WRITES_USUARIOS` ni P5 con este ensayo.
