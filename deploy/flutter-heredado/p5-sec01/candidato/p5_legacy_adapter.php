<?php
/**
 * P5-SEC01: adaptador de autenticacion para futura integracion CONTROLADA en
 * el middleware heredado. NO es un router, no se instala ni se usa por defecto.
 * No lee JWT_SECRET, .env, cabeceras globales ni una base productiva.
 *
 * Cuando se disponga de la copia exacta del middleware heredado, el parche
 * de integracion debera llamar a p5_legacy_authenticate_headers() desde
 * authenticate_user() con el PDO existente y el secreto ya validado.
 * require_auth() y el formato de 401 del legado permanecen bajo su control.
 */
require_once __DIR__ . '/p5_strict_auth.php';

/**
 * Extraccion conservadora de una unica cabecera Authorization: Bearer <JWT>.
 * Rechaza cabeceras duplicadas con distinto casing, arrays y tokens mezclados.
 * @param array<string,mixed> $headers Cabeceras del servidor, nunca del cuerpo.
 * @return string|false Token compacto o false
 */
function p5_legacy_extract_bearer(array $headers) {
    $found = null;
    foreach ($headers as $key => $value) {
        if (!is_string($key) || strcasecmp($key, 'Authorization') !== 0) {
            continue;
        }
        if ($found !== null || !is_string($value)) {
            return false;
        }
        $found = $value;
    }
    if ($found === null || strlen($found) > 8200) {
        return false;
    }
    if (!preg_match('/\ABearer ([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)\z/iD', $found, $match)) {
        return false;
    }
    return $match[1];
}

/**
 * @param array<string,mixed> $headers
 * @return array{user_id:int,username:string,email:string,role:string}|false
 */
function p5_legacy_authenticate_headers(array $headers, $jwtSecret, PDO $pdo, $clock = null, $maxRemainingSeconds = null) {
    $token = p5_legacy_extract_bearer($headers);
    if ($token === false) {
        return false;
    }
    return p5_strict_authenticate_jwt($token, $jwtSecret, $pdo, $clock, $maxRemainingSeconds);
}
