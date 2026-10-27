# API Documentation - Baltrek Backend

## Información General

- **Base URL:** `http://baltrek.test`
- **Content-Type:** `application/json`
- **Autenticación:** JWT (JSON Web Token)

## Autenticación

### JWT Token
Para endpoints protegidos, incluye el token JWT en el header:
```
Authorization: Bearer [tu_token_jwt]
```

## Endpoints

### 1. Ping (Prueba de conectividad)

**GET** `/api/ping`

Verifica que la API esté funcionando.

**Respuesta:**
```json
{
  "pong": true
}
```

---

### 2. Registro de Usuario

**POST** `/api/register`

Registra un nuevo usuario en el sistema.

**Body:**
```json
{
  "first_name": "Juan",
  "last_name": "Pérez",
  "username": "juanp",
  "email": "juan@ejemplo.com",
  "phone": "123456789",
  "password": "<ejemplo>"
}
```

**Campos requeridos:**
- `first_name` (string)
- `last_name` (string)
- `username` (string, único)
- `email` (string, único)
- `password` (string)

**Campos opcionales:**
- `phone` (string)

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Usuario registrado correctamente."
}
```

**Respuesta de error (400):**
```json
{
  "success": false,
  "message": "Faltan campos obligatorios"
}
```

**Respuesta de error (duplicado):**
```json
{
  "success": false,
  "message": "El email o username ya está registrado."
}
```

---

### 3. Login de Usuario

**POST** `/api/login`

Autentica un usuario y devuelve un token JWT.

**Body:**
```json
{
  "login": "juanp",
  "password": "<ejemplo>"
}
```

**Nota:** El campo `login` puede ser el username o el email.

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "token": "<ejemplo>",
  "user": {
    "user_id": 1,
    "username": "juanp",
    "email": "juan@ejemplo.com",
    "role": "usuario"
  }
}
```

**Respuesta de error (400):**
```json
{
  "success": false,
  "message": "Faltan datos de login o password"
}
```

**Respuesta de error (credenciales incorrectas):**
```json
{
  "success": false,
  "message": "Usuario o contraseña incorrectos."
}
```

---

### 4. Logout

**POST** `/api/logout`

Cierra la sesión del usuario (el frontend debe eliminar el token).

**Body:** No requiere body

**Respuesta (200):**
```json
{
  "success": true,
  "message": "Logout exitoso. El token debe ser eliminado en el frontend."
}
```

---

### 5. Obtener Usuario Autenticado

**GET** `/api/user`

Obtiene los datos del usuario autenticado.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "user": {
    "user_id": 1,
    "username": "juanp",
    "email": "juan@ejemplo.com",
    "role": "usuario"
  }
}
```

**Respuesta de error (401):**
```json
{
  "success": false,
  "message": "No autorizado. Token inválido o expirado."
}
```

---

## Reportes (Power BI Dashboards)

### 6. Listar Todos los Reportes

**GET** `/api/reportes`

Obtiene todos los reportes del usuario autenticado.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "nombre": "Dashboard de Ventas",
      "descripcion": "Análisis de ventas mensuales",
      "enlace": "https://app.powerbi.com/view?r=ventas",
      "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743",
      "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b",
      "created_by": "user-uuid-here",
      "updated_by": "user-uuid-here",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z"
    }
  ],
  "count": 1
}
```

---

### 7. Obtener Reporte por ID Numérico

**GET** `/api/reportes/{id}`

Obtiene un reporte específico por su ID numérico.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "nombre": "Dashboard de Ventas",
    "descripcion": "Análisis de ventas mensuales",
    "enlace": "https://app.powerbi.com/view?r=ventas",
    "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743",
    "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b",
    "created_by": "user-uuid-here",
    "updated_by": "user-uuid-here",
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

**Respuesta de error (404):**
```json
{
  "success": false,
  "message": "Reporte no encontrado"
}
```

---

### 8. Obtener Reporte por UUID

**GET** `/api/reportes/reporteid/{uuid}`

Obtiene un reporte específico por su UUID (reporte_id).

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Ejemplo:**
```
GET /api/reportes/reporteid/3c240793-823e-46b4-b775-3de039d4e743
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "nombre": "Dashboard de Ventas",
    "descripcion": "Análisis de ventas mensuales",
    "enlace": "https://app.powerbi.com/view?r=ventas",
    "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743",
    "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b",
    "created_by": "user-uuid-here",
    "updated_by": "user-uuid-here",
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  },
  "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743"
}
```

---

### 9. Obtener Reportes por Grupo

**GET** `/api/reportes/grupoid/{uuid}`

Obtiene todos los reportes de un grupo específico.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Ejemplo:**
```
GET /api/reportes/grupoid/438f0814-c0a1-4d17-91f1-740933d62a3b
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "nombre": "Dashboard de Ventas",
      "descripcion": "Análisis de ventas mensuales",
      "enlace": "https://app.powerbi.com/view?r=ventas",
      "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743",
      "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b",
      "created_by": "user-uuid-here",
      "updated_by": "user-uuid-here",
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z"
    }
  ],
  "count": 1,
  "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b"
}
```

---

### 10. Crear Nuevo Reporte

**POST** `/api/reportes`

Crea un nuevo reporte en el sistema.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
Content-Type: application/json
```

**Body:**
```json
{
  "nombre": "Dashboard de Ventas",
  "descripcion": "Análisis de ventas mensuales",
  "enlace": "https://app.powerbi.com/view?r=ventas",
  "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b",
  "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743"
}
```

**Campos requeridos:**
- `nombre` (string) - Nombre del reporte
- `enlace` (string) - URL del dashboard de Power BI
- `grupo_id` (string) - UUID del grupo
- `reporte_id` (string) - UUID único del reporte

**Campos opcionales:**
- `descripcion` (string) - Descripción del reporte

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Reporte creado exitosamente",
  "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743"
}
```

**Respuesta de error (400):**
```json
{
  "success": false,
  "message": "El campo 'nombre' es obligatorio"
}
```

**Respuesta de error (URL inválida):**
```json
{
  "success": false,
  "message": "El enlace no tiene un formato válido"
}
```

---

### 11. Actualizar Reporte

**PUT** `/api/reportes/{id}`

Actualiza un reporte existente.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
Content-Type: application/json
```

**Body (campos opcionales):**
```json
{
  "nombre": "Dashboard de Ventas Actualizado",
  "descripcion": "Análisis actualizado con nuevas métricas",
  "enlace": "https://app.powerbi.com/view?r=ventas_v2",
  "grupo_id": "438f0814-c0a1-4d17-91f1-740933d62a3b",
  "reporte_id": "3c240793-823e-46b4-b775-3de039d4e743"
}
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Reporte actualizado exitosamente"
}
```

**Respuesta de error (404):**
```json
{
  "success": false,
  "message": "Reporte no encontrado o no tienes permisos"
}
```

---

### 12. Eliminar Reporte

**DELETE** `/api/reportes/{id}`

Elimina un reporte (soft delete - marca como inactivo).

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Reporte eliminado exitosamente"
}
```

**Respuesta de error (404):**
```json
{
  "success": false,
  "message": "Reporte no encontrado o no tienes permisos"
}
```

---

## Estructura de Datos de Reportes

### Campos del Reporte:
- `id` (int) - ID numérico interno
- `nombre` (string) - Nombre del reporte
- `descripcion` (string) - Descripción del reporte
- `enlace` (string) - URL del dashboard de Power BI
- `reporte_id` (string) - UUID único del reporte
- `grupo_id` (string) - UUID del grupo al que pertenece
- `created_by` (string) - UUID del usuario que creó el reporte
- `updated_by` (string) - UUID del usuario que modificó por última vez
- `created_at` (datetime) - Fecha de creación
- `updated_at` (datetime) - Fecha de última actualización

### Permisos:
- **ADMIN:** Puede ver, crear, actualizar y eliminar cualquier reporte
- **USER:** Solo puede ver, crear, actualizar y eliminar sus propios reportes

---

## Códigos de Estado HTTP

- **200 OK:** Petición exitosa
- **400 Bad Request:** Datos incorrectos o faltantes
- **401 Unauthorized:** Token inválido o expirado
- **404 Not Found:** Endpoint o recurso no encontrado
- **500 Internal Server Error:** Error del servidor

---

## Manejo de Errores

Todos los errores devuelven un JSON con la siguiente estructura:
```json
{
  "success": false,
  "message": "Descripción del error"
}
```

---

## Ejemplos de Uso

### Flujo completo de autenticación:

1. **Registrar usuario:**
   ```bash
   curl -X POST http://baltrek.test/api/register \
     -H "Content-Type: application/json" \
     -d '{"first_name":"Juan","last_name":"Pérez","username":"juanp","email":"juan@ejemplo.com","password":"<ejemplo>"}'
   ```

2. **Hacer login:**
   ```bash
   curl -X POST http://baltrek.test/api/login \
     -H "Content-Type: application/json" \
     -d '{"login":"juanp","password":"<ejemplo>"}'
   ```

3. **Obtener datos del usuario (con token):**
   ```bash
   curl -X GET http://baltrek.test/api/user \
     -H "Authorization: Bearer [token_del_login]"
   ```

4. **Hacer logout:**
   ```bash
   curl -X POST http://baltrek.test/api/logout
   ```

---

### Flujo completo de reportes:

1. **Crear reporte:**
   ```bash
   curl -X POST http://baltrek.test/api/reportes \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer [token]" \
     -d '{"nombre":"Dashboard de Ventas","descripcion":"Análisis de ventas","enlace":"https://app.powerbi.com/view?r=ventas","grupo_id":"438f0814-c0a1-4d17-91f1-740933d62a3b","reporte_id":"3c240793-823e-46b4-b775-3de039d4e743"}'
   ```

2. **Listar reportes:**
   ```bash
   curl -X GET http://baltrek.test/api/reportes \
     -H "Authorization: Bearer [token]"
   ```

3. **Obtener reportes por grupo:**
   ```bash
   curl -X GET http://baltrek.test/api/reportes/grupoid/438f0814-c0a1-4d17-91f1-740933d62a3b \
     -H "Authorization: Bearer [token]"
   ```

4. **Actualizar reporte:**
   ```bash
   curl -X PUT http://baltrek.test/api/reportes/1 \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer [token]" \
     -d '{"nombre":"Dashboard Actualizado","descripcion":"Descripción actualizada"}'
   ```

5. **Eliminar reporte:**
   ```bash
   curl -X DELETE http://baltrek.test/api/reportes/1 \
     -H "Authorization: Bearer [token]"
   ```

---

### Flujo completo de empresas:

1. **Crear empresa:**
   ```bash
   curl -X POST http://baltrek.test/api/empresas \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer [token]" \
     -d '{"nombre":"TechCorp Solutions S.A.C.","razon_social":"TechCorp Solutions Sociedad Anónima Cerrada","ruc":"20123456789","email":"contacto@techcorp.com","ciudad":"Lima"}'
   ```

2. **Listar empresas:**
   ```bash
   curl -X GET http://baltrek.test/api/empresas \
     -H "Authorization: Bearer [token]"
   ```

3. **Buscar empresas:**
   ```bash
   curl -X GET http://baltrek.test/api/empresas/buscar/ABC \
     -H "Authorization: Bearer [token]"
   ```

4. **Obtener empresas por ciudad:**
   ```bash
   curl -X GET http://baltrek.test/api/empresas/ciudad/Lima \
     -H "Authorization: Bearer [token]"
   ```

5. **Actualizar empresa:**
   ```bash
   curl -X PUT http://baltrek.test/api/empresas/1 \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer [token]" \
     -d '{"nombre":"TechCorp Solutions Actualizada S.A.C.","email":"nuevo@techcorp.com"}'
   ```

6. **Eliminar empresa:**
   ```bash
   curl -X DELETE http://baltrek.test/api/empresas/1 \
     -H "Authorization: Bearer [token]"
   ```

---

## Empresas

### 13. Listar Todas las Empresas

**GET** `/api/empresas`

Obtiene todas las empresas activas del sistema.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "nombre": "Empresa ABC S.A.C.",
      "razon_social": "Empresa ABC Sociedad Anónima Cerrada",
      "ruc": "20123456789",
      "email": "contacto@empresaabc.com",
      "telefono": "+51 1 234 5678",
      "website": "https://www.empresaabc.com",
      "direccion": "Av. Principal 123, Oficina 456",
      "ciudad": "Lima",
      "pais": "Perú",
      "powerbi_tenant": "tenant-abc.onmicrosoft.com",
      "powerbi_client": "client-id-abc",
      "powerbi_clientsecret": "<ejemplo>",
      "logo": "https://example.com/logo.png",
      "contacto_principal": "Juan Pérez",
      "created_by": 1,
      "updated_by": 1,
      "created_at": "2024-01-15T10:30:00Z",
      "updated_at": "2024-01-15T10:30:00Z"
    }
  ],
  "count": 1
}
```

---

### 14. Obtener Empresa por ID

**GET** `/api/empresas/{id}`

Obtiene una empresa específica por su ID.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "nombre": "Empresa ABC S.A.C.",
    "razon_social": "Empresa ABC Sociedad Anónima Cerrada",
    "ruc": "20123456789",
    "email": "contacto@empresaabc.com",
    "telefono": "+51 1 234 5678",
    "website": "https://www.empresaabc.com",
    "direccion": "Av. Principal 123, Oficina 456",
    "ciudad": "Lima",
    "pais": "Perú",
    "powerbi_tenant": "tenant-abc.onmicrosoft.com",
    "powerbi_client": "client-id-abc",
    "powerbi_clientsecret": "<ejemplo>",
    "logo": "https://example.com/logo.png",
    "contacto_principal": "Juan Pérez",
    "created_by": 1,
    "updated_by": 1,
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-01-15T10:30:00Z"
  }
}
```

---

### 15. Crear Nueva Empresa

**POST** `/api/empresas`

Crea una nueva empresa en el sistema.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
Content-Type: application/json
```

**Body:**
```json
{
  "nombre": "Empresa ABC S.A.C.",
  "razon_social": "Empresa ABC Sociedad Anónima Cerrada",
  "ruc": "20123456789",
  "email": "contacto@empresaabc.com",
  "telefono": "+51 1 234 5678",
  "website": "https://www.empresaabc.com",
  "direccion": "Av. Principal 123, Oficina 456",
  "ciudad": "Lima",
  "pais": "Perú",
  "powerbi_tenant": "tenant-abc.onmicrosoft.com",
  "powerbi_client": "client-id-abc",
  "powerbi_clientsecret": "<ejemplo>",
  "logo": "https://example.com/logo.png",
  "contacto_principal": "Juan Pérez"
}
```

**Campos requeridos:**
- `nombre` (string) - Nombre de la empresa
- `razon_social` (string) - Razón social completa
- `ruc` (string) - RUC de 11 dígitos

**Campos opcionales:**
- `email` (string) - Email de contacto
- `telefono` (string) - Teléfono de contacto
- `website` (string) - Sitio web
- `direccion` (string) - Dirección física
- `ciudad` (string) - Ciudad
- `pais` (string) - País
- `powerbi_tenant` (string) - Tenant de Power BI
- `powerbi_client` (string) - Client ID de Power BI
- `powerbi_clientsecret` (string) - Client Secret de Power BI
- `logo` (string) - URL del logo
- `contacto_principal` (string) - Nombre del contacto principal

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Empresa creada exitosamente",
  "id": 1
}
```

**Respuesta de error (400):**
```json
{
  "success": false,
  "message": "El campo 'nombre' es obligatorio"
}
```

**Respuesta de error (RUC inválido):**
```json
{
  "success": false,
  "message": "El RUC debe tener 11 dígitos"
}
```

**Respuesta de error (RUC duplicado):**
```json
{
  "success": false,
  "message": "El RUC ya está registrado"
}
```

---

### 16. Actualizar Empresa

**PUT** `/api/empresas/{id}`

Actualiza una empresa existente.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
Content-Type: application/json
```

**Body (campos opcionales):**
```json
{
  "nombre": "Empresa ABC Actualizada S.A.C.",
  "email": "nuevo@empresaabc.com",
  "telefono": "+51 1 234 5679",
  "ciudad": "Arequipa"
}
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Empresa actualizada exitosamente"
}
```

---

### 17. Eliminar Empresa

**DELETE** `/api/empresas/{id}`

Elimina una empresa (soft delete - marca como inactivo).

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Empresa eliminada exitosamente"
}
```

---

### 18. Buscar Empresas

**GET** `/api/empresas/buscar/{termino}`

Busca empresas por nombre, RUC o razón social.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Ejemplo:**
```
GET /api/empresas/buscar/ABC
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "nombre": "Empresa ABC S.A.C.",
      "razon_social": "Empresa ABC Sociedad Anónima Cerrada",
      "ruc": "20123456789",
      "email": "contacto@empresaabc.com",
      "ciudad": "Lima"
    }
  ],
  "count": 1,
  "search_term": "ABC"
}
```

---

### 19. Obtener Empresas por Ciudad

**GET** `/api/empresas/ciudad/{ciudad}`

Obtiene todas las empresas de una ciudad específica.

**Headers requeridos:**
```
Authorization: Bearer [tu_token_jwt]
```

**Ejemplo:**
```
GET /api/empresas/ciudad/Lima
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "nombre": "Empresa ABC S.A.C.",
      "razon_social": "Empresa ABC Sociedad Anónima Cerrada",
      "ruc": "20123456789",
      "email": "contacto@empresaabc.com",
      "ciudad": "Lima"
    }
  ],
  "count": 1,
  "ciudad": "Lima"
}
```

---

## Estructura de Datos de Empresas

### Campos de la Empresa:
- `id` (int) - ID numérico interno
- `nombre` (string) - Nombre de la empresa
- `razon_social` (string) - Razón social completa
- `ruc` (string) - RUC de 11 dígitos (único)
- `email` (string) - Email de contacto
- `telefono` (string) - Teléfono de contacto
- `website` (string) - Sitio web
- `direccion` (string) - Dirección física
- `ciudad` (string) - Ciudad
- `pais` (string) - País
- `powerbi_tenant` (string) - Tenant de Power BI
- `powerbi_client` (string) - Client ID de Power BI
- `powerbi_clientsecret` (string) - Client Secret de Power BI
- `logo` (string) - URL del logo
- `contacto_principal` (string) - Nombre del contacto principal
- `created_by` (int) - ID del usuario que creó la empresa
- `updated_by` (int) - ID del usuario que modificó por última vez
- `created_at` (datetime) - Fecha de creación
- `updated_at` (datetime) - Fecha de última actualización
- `activo` (boolean) - Estado activo/inactivo

### Validaciones:
- **RUC:** Debe tener exactamente 11 dígitos
- **Email:** Formato válido si se proporciona
- **Website:** URL válida si se proporciona
- **Campos únicos:** RUC debe ser único en el sistema

### Ejemplos de Validación:

#### RUC Válido:
```json
{
  "ruc": "20123456789"
}
```

#### RUC Inválido:
```json
{
  "success": false,
  "message": "El RUC debe tener 11 dígitos"
}
```

#### Email Válido:
```json
{
  "email": "contacto@empresaabc.com"
}
```

#### Email Inválido:
```json
{
  "success": false,
  "message": "El formato del email no es válido"
}
```

#### Website Válido:
```json
{
  "website": "https://www.empresaabc.com"
}
```

#### Website Inválido:
```json
{
  "success": false,
  "message": "El formato del website no es válido"
}
```

---

## Notas Importantes

- Los tokens JWT expiran en 1 hora
- El frontend debe eliminar el token después del logout
- Todos los endpoints protegidos requieren el header `Authorization`
- Los campos `username` y `email` deben ser únicos en el sistema
- Los UUIDs de `grupo_id` y `reporte_id` deben ser únicos
- Las URLs de Power BI deben ser válidas
- Los reportes eliminados se marcan como inactivos (soft delete)
- Los usuarios ADMIN pueden ver y modificar cualquier reporte
- Los usuarios USER solo pueden ver y modificar sus propios reportes
- Los RUCs de empresas deben ser únicos en el sistema
- Las empresas eliminadas se marcan como inactivas (soft delete)

---

## Resumen de Endpoints Disponibles

### Autenticación
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `POST` | `/api/register` | Registrar nuevo usuario |
| `POST` | `/api/login` | Iniciar sesión |
| `POST` | `/api/logout` | Cerrar sesión |
| `GET` | `/api/user` | Obtener datos del usuario |

### Reportes
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `GET` | `/api/reportes` | Listar todos los reportes |
| `GET` | `/api/reportes/{id}` | Obtener reporte por ID |
| `GET` | `/api/reportes/reporteid/{uuid}` | Obtener reporte por UUID |
| `GET` | `/api/reportes/grupoid/{uuid}` | Obtener reportes por grupo |
| `POST` | `/api/reportes` | Crear nuevo reporte |
| `PUT` | `/api/reportes/{id}` | Actualizar reporte |
| `DELETE` | `/api/reportes/{id}` | Eliminar reporte |

### Empresas
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `GET` | `/api/empresas` | Listar todas las empresas |
| `GET` | `/api/empresas/{id}` | Obtener empresa por ID |
| `POST` | `/api/empresas` | Crear nueva empresa |
| `PUT` | `/api/empresas/{id}` | Actualizar empresa |
| `DELETE` | `/api/empresas/{id}` | Eliminar empresa |
| `GET` | `/api/empresas/buscar/{termino}` | Buscar empresas |
| `GET` | `/api/empresas/ciudad/{ciudad}` | Empresas por ciudad |

### Utilidades
| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `GET` | `/api/ping` | Prueba de conectividad |

---

## Troubleshooting

### Problemas Comunes y Soluciones

#### 1. Error 401 - No autorizado
**Síntoma:** `"No autorizado. Token inválido o expirado."`
**Solución:**
- Verificar que el token JWT esté presente en el header `Authorization: Bearer [token]`
- Hacer login nuevamente para obtener un token fresco
- Verificar que el token no haya expirado (expira en 1 hora)

#### 2. Error 400 - Datos inválidos
**Síntoma:** `"El campo 'nombre' es obligatorio"`
**Solución:**
- Verificar que todos los campos requeridos estén presentes
- Validar el formato de los datos (email, RUC, website)
- Asegurar que el Content-Type sea `application/json`

#### 3. Error 404 - No encontrado
**Síntoma:** `"Reporte no encontrado"` o `"Empresa no encontrada"`
**Solución:**
- Verificar que el ID o UUID sea correcto
- Confirmar que el recurso existe en la base de datos
- Verificar permisos de usuario (USER solo ve sus propios reportes)

#### 4. Error SQL - Invalid parameter number
**Síntoma:** `"SQLSTATE[HY093]: Invalid parameter number"`
**Solución:**
- Este error ya está corregido en la versión actual
- Si persiste, verificar que estés usando la versión más reciente del código

#### 5. Error de RUC duplicado
**Síntoma:** `"El RUC ya está registrado"`
**Solución:**
- Verificar que el RUC no esté ya registrado en otra empresa
- Usar un RUC único para cada empresa
- El RUC debe tener exactamente 11 dígitos

#### 6. Error de email duplicado
**Síntoma:** `"El email o username ya está registrado"`
**Solución:**
- Verificar que el email no esté ya registrado
- Usar un email único para cada usuario
- Verificar también el username

### Consejos de Desarrollo

1. **Testing con Insomnia:**
   - Usar el script de "After-response" para capturar automáticamente el token
   - Configurar variables de entorno para `base_url` y `token`
   - Usar la función `urldecode()` para términos de búsqueda con espacios

2. **Validación de Datos:**
   - Siempre validar el formato de email antes de enviar
   - Verificar que el RUC tenga exactamente 11 dígitos
   - Asegurar que las URLs de Power BI sean válidas

3. **Manejo de Errores:**
   - Siempre verificar el campo `success` en las respuestas
   - Manejar los códigos de estado HTTP apropiadamente
   - Mostrar mensajes de error descriptivos al usuario

4. **Seguridad:**
   - Nunca exponer tokens JWT en logs o consola
   - Usar HTTPS en producción
   - Validar todos los inputs del usuario