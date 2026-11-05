<?php
/**
 * Política de roles.
 *
 * Roles vigentes: 'admin' y 'user'.
 * Rol heredado: 'usuario' (lo asignaba el antiguo /api/register). Se trata como alias de 'user'
 * (sin privilegios adicionales) y se normaliza al leerlo; la base de datos no se modifica.
 * Cualquier otro valor es inesperado: no se reconoce como rol y no concede ningún acceso.
 */

if (!function_exists('new_user_role')) {
    /**
     * Rol con el que `POST /api/register` crea usuarios nuevos (UX-08E · D3). Nunca proviene de la petición.
     * Por defecto 'user'. `NEW_USER_ROLE=usuario` crea el valor heredado que hoy domina los datos productivos (62 de 76), para poder
     * validar con QA real de Flutter y React cuál es el apropiado antes de decidirlo. Cualquier otro valor se ignora ('user').
     * Los roles ya almacenados no se modifican ni se normalizan.
     * @return string 'user' | 'usuario'
     */
    function new_user_role()
    {
        return getenv('NEW_USER_ROLE') === 'usuario' ? 'usuario' : 'user';
    }
}

if (!function_exists('new_user_role_sql_literal')) {
    /**
     * UX-08I · El mismo rol de new_user_role() como LITERAL de SQL (lista cerrada: 'user' | 'usuario'). El alta lo escribe dentro de la sentencia, no como parámetro, para que el guardia de SQL de
     * admin_escritura pueda exigir la forma exacta y ningún valor de la petición pueda llegar a la columna `role`.
     * @return string "'user'" | "'usuario'"
     */
    function new_user_role_sql_literal()
    {
        return new_user_role() === 'usuario' ? "'usuario'" : "'user'";
    }
}

if (!function_exists('normalize_role')) {
    /**
     * @param mixed $role Valor almacenado o recibido
     * @return string|null 'admin' | 'user', o null si el rol no es reconocido
     */
    function normalize_role($role)
    {
        if (!is_string($role)) {
            return null;
        }
        switch (strtolower(trim($role))) {
            case 'admin':
                return 'admin';
            case 'user':
            case 'usuario': // alias heredado
                return 'user';
            default:
                return null;
        }
    }
}
