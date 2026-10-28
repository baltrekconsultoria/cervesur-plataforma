# QA manual en Windows · React + PHP + MySQL sintético

Todo es local: el backend y la base de datos corren en Docker Desktop sin salida a Internet y solo escuchan en `127.0.0.1`. No se necesita VPN, Microsoft ni credenciales reales.

## Requisitos
Docker Desktop iniciado (con las imágenes `cervesur-ci-php:local` y `mysql:8.0` ya en caché) y Node ≥ 22.12. Carpeta de trabajo:

```powershell
cd frontend   # desde la raíz del repositorio
```

## 1. Levantar el entorno

```powershell
npm run qa:up        # MySQL sintético + API PHP + reenviador (≈ 30 s)
npm run qa:status    # debe mostrar: salida a Internet BLOQUEADA, db sin puertos, puertos solo en 127.0.0.1
npm run build:qa
npm run preview:qa   # http://127.0.0.1:4175
```

Alternativa con recarga en caliente: `npm run dev:qa` → http://127.0.0.1:5173. Deben verse el aviso «Entorno QA local» en el login y la insignia **«QA · backend local aislado»** arriba a la derecha (no aparecen los controles de escenario del simulado).

Para probar el **JWT que expira a los 2 s**, en otra ventana de PowerShell:
```powershell
$env:QA_API_TARGET = 'http://127.0.0.1:8081'; npm run preview:qa -- --port 4176
```

## 2. Cuentas sintéticas (contraseña pública del fixture: `synthetic-password`)

| Usuario | Perfil | Qué debe ocurrir |
|---|---|---|
| `usera` | usuario | Mis reportes: **Flujo semanal, Inventario norte, Reporte A, Ventas regionales**. Mis empresas: solo **Empresa A**. |
| `userb` | usuario | Solo **Reporte B** y **Empresa B**. |
| `nobody` | usuario sin asignaciones | «Aún no tiene informes disponibles». |
| `legacy` | rol heredado `usuario` | Entra como usuario; solo **Reporte A**. |
| `weird` | rol `superadmin` | **Rechazado por PHP** (SEC-05A): «Credenciales inválidas»; no se emite JWT y no queda sesión. |
| `inactive` | inactivo | «Credenciales inválidas» (igual que una contraseña incorrecta). |
| `admin` | administrador | `/admin`: usuarios, empresas, reportes y accesos reales; **Usuarios** (alta), **Accesos y asignaciones** (asistente y retirada) funcionales (UX-07A). |

## 3. Recorridos a revisar

1. **Login**: contraseña incorrecta → mensaje y campo de contraseña vacío. Con `weird` → el mismo mensaje «Credenciales inválidas» (el backend rechaza el rol antes de emitir JWT).
2. **Búsqueda** (usera): «ventas» (nombre), «cobertura» (descripción), «proyeccion» (sin tilde), «zzz» (sin resultados) y «Limpiar filtros». Observe que **no hay filtros de empresa ni grupo**: el backend actual no entrega esos datos.
3. **Visor**: abra un informe (visor simulado, sin enlaces de Power BI), vuelva y compruebe que se conserva la búsqueda y el foco; pruebe el botón Atrás del navegador.
4. **Aislamiento** (usera): en «Mis reportes» y «Mis empresas» no debe aparecer nada de userb ni de la Empresa C inactiva. Los intentos directos contra informes o perfiles ajenos se comprueban con la API en los pasos 6 y 7 (la URL de un informe ajeno dentro de la app muestra «Acceso denegado» neutral, sin nombres).
5. **Sesión en memoria**: pulse F5 en cualquier pantalla → vuelve a `/login` (esperado; no es una expiración, no aparece el aviso 401).
6. **Sesión revocada (401)**: con usera conectada en el navegador, en PowerShell:
   ```powershell
   $t = (Invoke-RestMethod http://127.0.0.1:8080/api/login -Method Post -ContentType 'application/json' -Body '{"login":"usera","password":"synthetic-password"}').token
   Invoke-RestMethod http://127.0.0.1:8080/api/logout -Method Post -Headers @{Authorization="Bearer $t"}
   ```
   Al navegar a otra sección en el navegador debe aparecer el login con «Su sesión venció (401)», contraseña vacía y usuario conservado; al reingresar vuelve a la sección en que estaba.
7. **Informe ajeno por la API** (debe ser 404, igual que uno inexistente):
   ```powershell
   $t = (Invoke-RestMethod http://127.0.0.1:8080/api/login -Method Post -ContentType 'application/json' -Body '{"login":"usera","password":"synthetic-password"}').token
   try { Invoke-RestMethod http://127.0.0.1:8080/api/reportes/reporteid/00000000-0000-0000-0000-000000000022 -Headers @{Authorization="Bearer $t"} } catch { $_.Exception.Response.StatusCode.value__ }   # 404
   try { Invoke-RestMethod http://127.0.0.1:8080/api/user/profile/2 -Headers @{Authorization="Bearer $t"} } catch { $_.Exception.Response.StatusCode.value__ }   # 403
   ```
8. **Error recuperable**: `docker stop cervesur-qa-proxy`, abra «Mis empresas» → mensaje con «Reintentar»; `docker start cervesur-qa-proxy` y pulse «Reintentar».
9. **Móvil**: DevTools (F12) → modo dispositivo 390 px: menú ☰, tablas como tarjetas, 403 y 401 sin desbordamiento.
10. **Teclado**: Tab recorre «Saltar al contenido», menú y contenido; Esc cierra el menú móvil.

## 3b. Administración de usuarios y accesos (UX-07A) — con `admin`

Los cambios se guardan en el MySQL sintético y sobreviven a F5 (la sesión no: vuelve a `/login`, es lo esperado).

1. **Usuarios** (menú lateral): lista real (activos e inactivos, con paginación de 25 filas por página); `weird` aparece como «Rol no reconocido · sin acceso». Busque por nombre, usuario o correo y filtre por rol y estado.
2. **Nuevo usuario**: pulse el botón con el formulario vacío → mensajes junto a cada campo, sin llamada al servidor. Cree `operador.prueba` (correo `operador.prueba@example.invalid`, contraseña de 8+ caracteres). Si usa un correo ya registrado (p. ej. `usera@example.invalid`) el diálogo muestra el rechazo de PHP, **sigue abierto y no dice «creado»**. Al guardar bien, aparece el aviso y el usuario en la tabla (rol Usuario, Activo). El formulario no permite elegir rol ni estado.
3. **Asignar accesos**: en la fila del usuario nuevo → asistente de 4 pasos: usuario → **Empresa A** → marque **Reporte A** → resumen → «Guardar accesos». El resultado indica por informe «Concedido». «Ya asignado» aparece deshabilitado si el usuario ya lo tiene.
4. **Accesos y asignaciones**: busque «operador»: debe aparecer *Vigente*.
5. **Probar como el usuario**: «Cerrar sesión» → ingrese como `operador.prueba`. Solo ve **Reporte A**; abrir un informe de Empresa B por URL (`/reportes/00000000-0000-0000-0000-000000000022`) da «Acceso denegado» y `/admin/usuarios` da 403 de sección.
6. **Retirar**: vuelva como `admin` → Accesos → «Retirar» → confirme (Cancelar no cambia nada). La fila desaparece y el aviso lo confirma. Como `operador.prueba` ya no ve informes.
7. **Persistencia**: F5, ingrese de nuevo como `admin`: el acceso sigue retirado y el usuario sigue existiendo. Repita el paso 3 y confirme que se vuelve a conceder (PHP reactiva la relación en vez de duplicarla).
8. **Verificar en la base** (opcional, solo lectura, base desechable): `docker exec cervesur-qa-db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" fixture -e "SELECT id,username,role,is_active FROM user ORDER BY id DESC LIMIT 3"'`.
9. **Móvil** (390 px): Usuarios, el diálogo «Nuevo usuario» y el asistente caben sin desplazamiento horizontal.

## 4. Pruebas automáticas

```powershell
npm test                   # 101 unitarias y de componentes
npm run build; npm run test:e2e        # 84 E2E del modo simulado
npm run test:contract      # 136 comprobaciones de contrato y permisos contra PHP real
npm run build:qa; npm run test:e2e:qa  # 118 E2E navegador → React → PHP real (incluye el escenario completo de UX-07A)
npm run qa:backend-ci      # CI del backend (PHP lint, seguridad, HTTP base, paginación, SEC-05A y UX-07A)
```

## 5. Apagar y limpiar

```powershell
npm run qa:down            # elimina contenedores, redes y la base de datos (efímera)
```
Los datos sintéticos se regeneran en cada `qa:up`.
