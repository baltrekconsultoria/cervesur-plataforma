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

## 3c. Empresas, reportes y asociaciones (UX-07B) — con `admin`

Todo con datos **sintéticos**. Los cambios se guardan en MySQL y sobreviven a F5 (la sesión no: vuelve a `/login`, es lo esperado).

1. **Nueva empresa** (Empresas → «Nueva empresa»): con el formulario vacío aparecen mensajes junto a Nombre, Razón social y RUC (11 dígitos). No hay campos de Power BI. Pruebe el RUC de otra empresa (`00000000001`): PHP lo rechaza, el diálogo sigue abierto y **no** dice «creada». Cree **Empresa C** con un RUC nuevo de 11 dígitos (p. ej. `20999999991`), ciudad «Cusco» → aviso verde y fila nueva.
2. **Editar empresa**: «Editar» en su fila → los datos cargan desde el servidor → cambie la ciudad/teléfono → «Guardar cambios». Vaciar el nombre debe dar error.
3. **Nuevo reporte** (Reportes → «Nuevo reporte»): con datos inválidos (URL «abc», UUID «123») salen mensajes sin llamar al servidor. Datos sintéticos válidos: nombre «Informe C», enlace `https://example.invalid/informes/c`, grupo `11111111-1111-1111-1111-111111111111`, informe `22222222-2222-2222-2222-222222222222`. Repetir el UUID de otro informe → «Ya existe un informe con ese ID de Power BI». Al crearlo aparece sin empresa (filtro Estado → «Sin empresa»).
4. **Editar reporte**: «Editar» → cargan el enlace y los UUID (solo el administrador los ve) → cambie la descripción → guardar.
5. **Informes por empresa** (menú lateral): elija **Empresa C** → «aún no tiene informes asociados» → marque **Informe C** en «disponibles» → «Asociar seleccionados» → resultado «Asociado» y pasa a «Informes asociados». Un informe puede asociarse también a otra empresa (verá «Compartido con N más»); uno ya asociado no vuelve a ofrecerse.
6. **Circuito completo**: Usuarios → «Nuevo usuario» (`operador.c`) → «Asignar accesos» → Empresa C → Informe C → guardar. «Cerrar sesión» → ingrese como `operador.c`: solo ve **Informe C**; abrir `/reportes/00000000-0000-0000-0000-000000000022` da «Acceso denegado».
7. **Retirar el acceso** (Accesos y asignaciones → «Retirar» → confirmar): `operador.c` ya no ve el informe. La asociación empresa↔informe sigue.
8. **Desvincular** (Informes por empresa → Empresa C → «Desvincular» → el aviso explica que los usuarios dejarán de verlo; «Cancelar» no cambia nada → confirmar): el informe vuelve a «disponibles»; sigue existiendo en Reportes. Si se vuelve a asociar, PHP **reactiva** la misma relación.
9. **Persistencia**: F5, ingrese como `admin` y repita las consultas: empresa, reporte y asociaciones tal como las dejó.
10. **Móvil** (390 px): los formularios de empresa y reporte y «Informes por empresa» caben sin desplazamiento horizontal.

## 3d. Visor de Power BI y registro por enlace (UX-07C)

El visor **no usa Power BI real** en este entorno: los informes sembrados traen enlaces sintéticos de «Publicar en web» y el iframe abre una **página local de pruebas** (`/qa/powerbi-view.html`) que muestra el token recibido. No hay acceso a Microsoft.

1. **Nuevo reporte simplificado** (admin → Reportes → «Nuevo reporte»): solo Nombre, Descripción y **Enlace de Power BI** (ya no se piden IDs). Pegue un enlace inválido y observe el aviso: `abc` («debe usar HTTPS»), `https://example.invalid/x` («debe pertenecer a app.powerbi.com»), un `<iframe …>` («pegue solo la URL») y `http://app.powerbi.com/view?r=…` («HTTPS»). Ninguno envía nada al servidor.
2. **Enlace válido**: `https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE` → «Tipo de enlace detectado: Publicación web… no se inventan». Cree el reporte (p. ej. «Informe público QA») y asócielo a **Empresa C** («Informes por empresa»), cree/asigne un usuario («Accesos»).
3. **Inserción autenticada** (opcional): enlace `https://app.powerbi.com/reportEmbed?reportId=11111111-2222-4333-8444-555555555555` → «Inserción autenticada. Se detectó: ID del informe… · sin ID de grupo en el enlace».
4. **Visor del usuario**: inicie sesión como ese usuario y abra el informe: se ve la página local «Informe de Power BI simulado» con el token recibido, el estado de carga, y debajo «¿No ve el informe?… **Abrir en una pestaña nueva**». La ayuda sigue visible aunque el marco haya cargado (el evento *load* no demuestra que Power BI lo mostró).
5. **Modos** (con `usera`): **Reporte A** → visor local; **Inventario norte** → «Este informe requiere configuración de acceso a Power BI» (inserción autenticada, sin tokens); **Ventas regionales** → «El enlace de este informe no es compatible con el visor» (dato antiguo).
6. **Seguridad**: copie la URL de un informe ajeno (`/reportes/00000000-0000-0000-0000-000000000022`) → «Acceso denegado». Con el admin retire el acceso o desvincule el informe de la empresa y recargue la página del informe del usuario → deja de abrirse (el servidor ya no entrega la configuración).
7. **Editar el enlace**: Reportes → «Editar» el informe → cambie el enlace por otro `…/view?r=…&pageName=ReportSection1` → guardar. Las asociaciones y el acceso del usuario siguen; el visor muestra el token y la página nuevos.
8. **Móvil** (390 px): el visor y el formulario caben sin desplazamiento horizontal.
9. **Power BI real**: **no se prueba aquí.** Solo con un enlace público explícitamente autorizado, en un entorno aprobado, y comprobando visualmente su contenido.

## 3e. Visor con un informe público REAL de Power BI (UX-07C.1, opt-in)

**Requiere conectividad externa del navegador** (solo para este apartado y solo el navegador: PHP y MySQL siguen aislados). Use únicamente un enlace de «Publicar en web» **explícitamente autorizado** para difusión pública. El enlace real vive solo en su MySQL sintético local (no en Git).

1. **No** ejecute `qa:up`, `qa:down`, `test:contract` ni `test:e2e:qa` sobre su entorno (se perderían los datos del QA manual).
2. Compile la variante real (no toca `dist-qa` ni `.env.qa`) y sírvala en otro puerto:
   ```powershell
   cd <carpeta del worktree wt-ux07c>/frontend
   npm run build:qa:real
   npm run preview:qa:real      # http://127.0.0.1:4177 (déjela abierta; Ctrl+C para detenerla)
   ```
   Su API/MySQL del QA (puerto 8080) y la vista previa simulada (4175) siguen funcionando.
3. Abra http://127.0.0.1:4177, inicie sesión (el usuario con el informe asignado, o `admin`) y abra el reporte con el enlace real ya registrado.
4. Verifique: arriba del visor aparece **«QA · Power BI público REAL…»** (en 4175 aparece «QA · visor simulado»). En DevTools → Elements, el `<iframe>` tiene `src="https://app.powerbi.com/view?r=…"` y `sandbox="allow-scripts allow-same-origin allow-popups"`; en Network, la petición del marco va a `app.powerbi.com`. La advertencia del sandbox puede seguir apareciendo (justificada: ver `INFORME-UX-07C1.md`).
5. Compruebe **visualmente** gráficos, filtros/segmentadores y navegación entre páginas del informe. Si el marco queda en blanco, pruebe «Abrir en una pestaña nueva» y anote lo que muestre DevTools (Console/Network); el evento *load* no prueba que el informe se muestre.
6. Seguridad: con otro usuario sin asignación, abra `/reportes/<id del informe>` → «Acceso denegado» (no obtiene la URL). Retire el acceso o desvincule el informe y recargue → deja de abrirse.
7. **Volver al modo simulado:** detenga el 4177 (Ctrl+C) y use http://127.0.0.1:4175 (compilación `dist-qa`, `npm run build:qa` si necesita reconstruirla). No hay nada más que revertir.

## 4. Pruebas automáticas

```powershell
npm test                   # 185 unitarias y de componentes
npm run build; npm run test:e2e        # 85 E2E del modo simulado
npm run test:contract      # 199 comprobaciones de contrato y permisos contra PHP real
npm run build:qa; npm run test:e2e:qa  # (NO sobre el entorno de QA manual: use QA_PREFIX=cervesur-qa2 QA_API_PORT=18080 con su propio qa:up/qa:down) 185 E2E navegador → React → PHP real (escenarios UX-07A, UX-07B y UX-07C). Si ya tiene una vista previa en el puerto 4175: `$env:E2E_PORT=4275`
npm run qa:backend-ci      # CI del backend (PHP lint, seguridad, HTTP base, paginación, SEC-05A, UX-07A, UX-07B y UX-07C)
```

## 5. Apagar y limpiar

```powershell
npm run qa:down            # elimina contenedores, redes y la base de datos (efímera)
```
Los datos sintéticos se regeneran en cada `qa:up`.
