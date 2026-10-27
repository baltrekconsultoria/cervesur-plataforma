<?php

/**
 * Router para el endpoint /api/wiki
 * Devuelve una página HTML con la documentación de la API
 */

// GET /api/wiki - Documentación de la API
if ($uri === '/api/wiki') {
    if ($method === 'GET') {
        // Cambiar el Content-Type a HTML
        header('Content-Type: text/html; charset=utf-8');

        // Devolver la página HTML con la documentación
        echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Baltrek API - Documentación</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .section {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .section h2 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .endpoint {
            background: #f8f9fa;
            border-left: 4px solid #3498db;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 5px;
        }

        .method {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.9rem;
            margin-right: 10px;
        }

        .method.get { background: #28a745; color: white; }
        .method.post { background: #007bff; color: white; }
        .method.put { background: #ffc107; color: #212529; }
        .method.delete { background: #dc3545; color: white; }

        .url {
            font-family: "Courier New", monospace;
            background: #e9ecef;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 1rem;
        }

        .description {
            margin-top: 10px;
            color: #6c757d;
        }

        .auth-required {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 8px 12px;
            border-radius: 4px;
            margin-top: 10px;
            font-size: 0.9rem;
        }

        .example {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-top: 10px;
            font-family: "Courier New", monospace;
            font-size: 0.9rem;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #6c757d;
            border-top: 1px solid #dee2e6;
            margin-top: 30px;
        }

        .nav {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .nav ul {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }

        .nav a {
            text-decoration: none;
            color: #3498db;
            padding: 8px 16px;
            border-radius: 20px;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        .nav a:hover {
            background: #3498db;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 Baltrek API</h1>
            <p>Documentación completa de endpoints RESTful</p>
        </div>

        <div class="nav">
            <ul>
                <li><a href="#autenticacion">🔐 Autenticación</a></li>
                <li><a href="#usuarios">👥 Usuarios</a></li>
                <li><a href="#reportes">📊 Reportes</a></li>
                <li><a href="#empresas">🏢 Empresas</a></li>
                <li><a href="#asignaciones">🔗 Asignaciones</a></li>
                <li><a href="#utilidades">🛠️ Utilidades</a></li>
            </ul>
        </div>

        <div class="section" id="autenticacion">
            <h2>🔐 Autenticación</h2>

            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="url">/api/login</span>
                <div class="description">Inicia sesión y obtiene un token JWT</div>
                <div class="example">
{
  "login": "usuario@email.com",
  "password": "<ejemplo>"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="url">/api/register</span>
                <div class="description">Registra un nuevo usuario</div>
                <div class="example">
{
  "first_name": "Juan",
  "last_name": "Pérez",
  "username": "juanperez",
  "email": "juan@email.com",
  "password": "<ejemplo>",
  "phone": "123456789"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="url">/api/logout</span>
                <div class="description">Cierra la sesión del usuario</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>
        </div>

        <div class="section" id="usuarios">
            <h2>👥 Usuarios</h2>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/user</span>
                <div class="description">Obtiene datos básicos del usuario autenticado</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
{
  "success": true,
  "user": {
    "id": "123",
    "username": "juanperez",
    "email": "juan@email.com",
    "first_name": "Juan",
    "last_name": "Pérez",
    "role": "USER"
  }
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/user/profile</span>
                <div class="description">Obtiene el perfil completo del usuario autenticado</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
{
  "success": true,
  "profile": {
    "id": "123",
    "first_name": "Juan",
    "last_name": "Pérez",
    "username": "juanperez",
    "email": "juan@email.com",
    "phone": "123456789",
    "avatar": "https://example.com/avatar.jpg",
    "password": "<ejemplo>",
    "role": "USER",
    "last_login": "2024-01-20 14:45:00",
    "is_active": true
  }
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method put">PUT</span>
                <span class="url">/api/user/profile</span>
                <div class="description">Actualiza el perfil del usuario autenticado</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
{
  "first_name": "Juan Carlos",
  "last_name": "Pérez García",
  "phone": "987654321",
  "avatar": "https://example.com/new-avatar.jpg"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/users</span>
                <div class="description">Lista todos los usuarios (solo administradores)</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
{
  "success": true,
  "users": [
    {
      "id": "123",
      "first_name": "Juan",
      "last_name": "Pérez",
      "username": "juanperez",
      "email": "juan@email.com",
      "phone": "123456789",
      "avatar": "https://example.com/avatar.jpg",
      "role": "USER",
      "last_login": "2024-01-20 14:45:00",
      "is_active": true,
      "created_at": "2024-01-15 10:30:00"
    }
  ],
  "total": 1
}
                </div>
            </div>
        </div>

        <div class="section" id="reportes">
            <h2>📊 Reportes</h2>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/reportes</span>
                <div class="description">Lista todos los reportes del usuario</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="url">/api/reportes</span>
                <div class="description">Crea un nuevo reporte</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
{
  "nombre": "Reporte de Ventas",
  "descripcion": "Análisis mensual de ventas",
  "enlace": "https://app.powerbi.com/view?r=eyJrIjoi...",
  "grupo_id": "550e8400-e29b-41d4-a716-446655440000",
  "reporte_id": "550e8400-e29b-41d4-a716-446655440001"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/reportes/{id}</span>
                <div class="description">Obtiene un reporte específico</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method put">PUT</span>
                <span class="url">/api/reportes/{id}</span>
                <div class="description">Actualiza un reporte</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method delete">DELETE</span>
                <span class="url">/api/reportes/{id}</span>
                <div class="description">Elimina un reporte (soft delete)</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/reportes/grupoid/{uuid}</span>
                <div class="description">Obtiene reportes por grupo (UUID)</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/reportes/reporteid/{uuid}</span>
                <div class="description">Obtiene reportes por ID de reporte (UUID)</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/reportes/activos</span>
                <div class="description">Obtiene solo reportes activos</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>
        </div>

        <div class="section" id="empresas">
            <h2>🏢 Empresas</h2>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/empresas</span>
                <div class="description">Lista todas las empresas</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="url">/api/empresas</span>
                <div class="description">Crea una nueva empresa</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
{
  "nombre": "Empresa ABC S.A.C.",
  "razon_social": "Empresa ABC Sociedad Anónima Cerrada",
  "ruc": "20123456789",
  "email": "contacto@empresaabc.com",
  "telefono": "+51 1 234 5678",
  "website": "https://www.empresaabc.com",
  "direccion": "Av. Principal 123",
  "ciudad": "Lima",
  "pais": "Perú"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/empresas/{id}</span>
                <div class="description">Obtiene una empresa específica</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method put">PUT</span>
                <span class="url">/api/empresas/{id}</span>
                <div class="description">Actualiza una empresa</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method delete">DELETE</span>
                <span class="url">/api/empresas/{id}</span>
                <div class="description">Elimina una empresa (soft delete)</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/empresas/buscar/{termino}</span>
                <div class="description">Busca empresas por nombre, RUC o razón social</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/empresas/ciudad/{ciudad}</span>
                <div class="description">Filtra empresas por ciudad</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
            </div>
        </div>

        <div class="section" id="asignaciones">
            <h2>🔗 Asignaciones</h2>
            <p style="margin-bottom: 20px; color: #666;">Gestión de asignaciones de reportes a empresas. Permite vincular reportes específicos con empresas para control de acceso y organización.</p>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/asignacion</span>
                <div class="description">Lista todas las asignaciones activas con información de empresa y reporte</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>Respuesta:</strong>
{
  "success": true,
  "data": [
    {
      "id": 1,
      "empresa_id": 5,
      "reporte_id": 12,
      "notas": "Reporte de ventas mensual",
      "empresa_nombre": "Empresa ABC S.A.C.",
      "reporte_nombre": "Reporte de Ventas",
      "created_by": 1,
      "created_at": "2024-01-15 10:30:00",
      "activo": 1
    }
  ],
  "count": 1
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method post">POST</span>
                <span class="url">/api/asignacion</span>
                <div class="description">Crea una nueva asignación de reporte a empresa</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>Body JSON:</strong>
{
  "empresa_id": 5,
  "reporte_id": 12,
  "notas": "Reporte de ventas mensual para esta empresa"
}

<strong>Respuesta:</strong>
{
  "success": true,
  "message": "Asignación creada exitosamente",
  "data": {
    "id": 1,
    "empresa_id": 5,
    "reporte_id": 12
  }
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/asignacion/{id}</span>
                <div class="description">Obtiene una asignación específica por ID</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>URL:</strong> /api/asignacion/1

<strong>Respuesta:</strong>
{
  "success": true,
  "data": {
    "id": 1,
    "empresa_id": 5,
    "reporte_id": 12,
    "notas": "Reporte de ventas mensual",
    "empresa_nombre": "Empresa ABC S.A.C.",
    "reporte_nombre": "Reporte de Ventas",
    "created_by": 1,
    "created_at": "2024-01-15 10:30:00",
    "activo": 1
  }
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method put">PUT</span>
                <span class="url">/api/asignacion/{id}</span>
                <div class="description">Actualiza una asignación existente</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>URL:</strong> /api/asignacion/1

<strong>Body JSON:</strong>
{
  "empresa_id": 5,
  "reporte_id": 12,
  "notas": "Reporte actualizado con nuevas métricas"
}

<strong>Respuesta:</strong>
{
  "success": true,
  "message": "Asignación actualizada exitosamente"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method delete">DELETE</span>
                <span class="url">/api/asignacion/{id}</span>
                <div class="description">Elimina una asignación (soft delete - marca como inactiva)</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>URL:</strong> /api/asignacion/1

<strong>Respuesta:</strong>
{
  "success": true,
  "message": "Asignación eliminada exitosamente"
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/asignacion/empresa/{empresa_id}</span>
                <div class="description">Obtiene todas las asignaciones de una empresa específica</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>URL:</strong> /api/asignacion/empresa/5

<strong>Respuesta:</strong>
{
  "success": true,
  "data": [
    {
      "id": 1,
      "empresa_id": 5,
      "reporte_id": 12,
      "notas": "Reporte de ventas mensual",
      "empresa_nombre": "Empresa ABC S.A.C.",
      "reporte_nombre": "Reporte de Ventas",
      "created_by": 1,
      "created_at": "2024-01-15 10:30:00",
      "activo": 1
    }
  ],
  "count": 1
}
                </div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/asignacion/reporte/{reporte_id}</span>
                <div class="description">Obtiene todas las empresas asignadas a un reporte específico</div>
                <div class="auth-required">⚠️ Requiere autenticación</div>
                <div class="example">
<strong>URL:</strong> /api/asignacion/reporte/12

<strong>Respuesta:</strong>
{
  "success": true,
  "data": [
    {
      "id": 1,
      "empresa_id": 5,
      "reporte_id": 12,
      "notas": "Reporte de ventas mensual",
      "empresa_nombre": "Empresa ABC S.A.C.",
      "reporte_nombre": "Reporte de Ventas",
      "created_by": 1,
      "created_at": "2024-01-15 10:30:00",
      "activo": 1
    }
  ],
  "count": 1
}
                </div>
            </div>
        </div>

        <div class="section" id="utilidades">
            <h2>🛠️ Utilidades</h2>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/ping</span>
                <div class="description">Verifica el estado de la API</div>
            </div>

            <div class="endpoint">
                <span class="method get">GET</span>
                <span class="url">/api/wiki</span>
                <div class="description">Esta página de documentación</div>
            </div>
        </div>

    </div>
</body>
</html>';
        exit;
    }
}
