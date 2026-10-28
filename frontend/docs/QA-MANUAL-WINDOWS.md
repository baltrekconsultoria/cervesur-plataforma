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
| `weird` | rol `superadmin` | **Rechazado**: «rol no reconocido»; no queda sesión. |
| `inactive` | inactivo | «Credenciales inválidas» (igual que una contraseña incorrecta). |
| `admin` | administrador | `/admin`: empresas 2 de 2, reportes 6 de 6; Reportes y Empresas con datos reales; Usuarios y Accesos indican «se conecta en la siguiente etapa». |

## 3. Recorridos a revisar

1. **Login**: contraseña incorrecta → mensaje y campo de contraseña vacío. Con `weird` → rechazo por rol.
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

## 4. Pruebas automáticas

```powershell
npm test                   # 68 unitarias y de componentes
npm run build; npm run test:e2e        # 84 E2E del modo simulado
npm run test:contract      # 56 comprobaciones de contrato y permisos contra PHP real
npm run build:qa; npm run test:e2e:qa  # 66 E2E navegador → React → PHP real
npm run qa:backend-ci      # CI del backend (PHP lint, seguridad, HTTP aisladas)
```

## 5. Apagar y limpiar

```powershell
npm run qa:down            # elimina contenedores, redes y la base de datos (efímera)
```
Los datos sintéticos se regeneran en cada `qa:up`.
