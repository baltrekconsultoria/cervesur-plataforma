# 🚀 Baltrek - Frontend + Backend Integration

Este proyecto aloja tanto el **frontend** (desarrollado por otro equipo) como el **backend** (API REST) en el mismo servidor.

## 📁 Estructura del Proyecto

```
📁 BALTREK/
├── 📄 index.html          ← Frontend principal (reemplazable por el equipo frontend)
├── 📄 .htaccess           ← Reglas de reescritura para frontend y backend
├── 📄 README.md           ← Esta documentación
├── 📁 backend/            ← Todo el backend (API)
│   ├── 📄 api.php         ← Punto de entrada de la API
│   ├── 📄 .env            ← Variables de entorno (no subir a Git)
│   ├── 📁 controllers/    ← Controladores del backend
│   │   ├── 📄 AuthController.php
│   │   └── 📄 RegisterController.php
│   └── 📁 lib/           ← Librerías del backend
│       ├── 📄 db.php     ← Conexión a base de datos
│       ├── 📄 env.php    ← Cargador de variables de entorno
│       ├── 📄 jwt.php    ← Funciones JWT
│       └── 📄 auth_middleware.php ← Middleware de autenticación
└── 📄 API_DOCUMENTATION.md ← Documentación de la API
```

## 🔧 Configuración del .htaccess

El archivo `.htaccess` está configurado para manejar tanto el frontend como el backend:

### Para el Backend (API):
- Todas las rutas que empiecen con `/api/` se redirigen a `backend/api.php`
- Ejemplo: `/api/login` → `backend/api.php`

### Para el Frontend:
- Todas las demás rutas se redirigen a `index.html`
- Ejemplo: `/dashboard` → `index.html`

## 📡 API Backend Disponible

### Endpoints:

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/api/ping` | Prueba de conectividad |
| `POST` | `/api/register` | Registro de usuarios |
| `POST` | `/api/login` | Autenticación |
| `POST` | `/api/logout` | Cerrar sesión |
| `GET` | `/api/user` | Datos del usuario (requiere JWT) |

### Ejemplo de uso desde el frontend:

```javascript
// Login
const response = await fetch('/api/login', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        login: 'usuario@email.com',
        password: '<ejemplo>'
    })
});

const data = await response.json();
// data.token contiene el JWT
```

## 👥 Para el Equipo Frontend

### Integración:

1. **Reemplazar `index.html`** con tu aplicación frontend
2. **Usar las rutas de la API** para autenticación y datos
3. **Incluir el JWT** en el header `Authorization: Bearer [token]` para rutas protegidas

### Ejemplo de configuración para el frontend:

```javascript
// Configuración base para las llamadas a la API
const API_BASE = ''; // URL base (vacía para el mismo dominio)

// Función para hacer llamadas a la API
async function apiCall(endpoint, options = {}) {
    const token = localStorage.getItem('jwt_token');

    const config = {
        headers: {
            'Content-Type': 'application/json',
            ...(token && { 'Authorization': `Bearer ${token}` })
        },
        ...options
    };

    const response = await fetch(`${API_BASE}${endpoint}`, config);
    return response.json();
}
```

## 🔒 Autenticación JWT

El backend usa JWT para autenticación:

1. **Login**: El usuario recibe un token JWT
2. **Rutas protegidas**: Incluir el token en el header `Authorization`
3. **Logout**: El frontend elimina el token del localStorage

## 📝 Variables de Entorno

Crear archivo `backend/.env` con las siguientes variables:

```env
DB_HOST=tu_host
DB_NAME=tu_base_de_datos
DB_USER=tu_usuario
DB_PASS=tu_contraseña
DB_CHARSET=utf8mb4
JWT_SECRET=tu_clave_secreta_para_jwt
```

## 🚀 Despliegue

1. Subir todos los archivos al servidor
2. Configurar el archivo `backend/.env` con las credenciales correctas
3. Verificar que Apache tenga `mod_rewrite` habilitado
4. Probar el endpoint `/api/ping` para verificar que funciona

## 📚 Documentación Completa

Ver `API_DOCUMENTATION.md` para documentación detallada de todos los endpoints.

## ✅ Ventajas de esta estructura:

- **Separación clara**: Frontend y backend completamente separados
- **Sin conflictos**: El equipo frontend puede crear sus carpetas sin problemas
- **Fácil mantenimiento**: Cada equipo trabaja en su área específica
- **Escalabilidad**: Fácil agregar más funcionalidades al backend