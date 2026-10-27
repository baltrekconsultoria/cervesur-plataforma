<?php

/**
 * Codifica datos en base64url (seguro para URLs, sin relleno =)
 * @param string $data
 * @return string
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Genera un JSON Web Token (JWT) firmado con HMAC SHA256
 * @param array $payload  Datos a incluir en el token (ej: user_id, exp, email)
 * @param string $secret Clave secreta para firmar el token
 * @param string $alg    Algoritmo de firma (por defecto HS256)
 * @return string        El JWT generado
 */
function generate_jwt($payload, $secret, $alg = 'HS256') {
    if ($alg !== 'HS256') {
        throw new InvalidArgumentException('Algoritmo no soportado');
    }
    // 1. Header: tipo y algoritmo
    $header = [
        'typ' => 'JWT',
        'alg' => $alg
    ];

    // 2. Codificar header y payload en base64url
    $header_encoded = base64url_encode(json_encode($header));
    $payload_encoded = base64url_encode(json_encode($payload));

    // 3. Concatenar header y payload
    $signing_input = $header_encoded . '.' . $payload_encoded;

    // 4. Firmar el token
    $signature = hash_hmac('sha256', $signing_input, $secret, true);
    $signature_encoded = base64url_encode($signature);

    // 5. Unir las tres partes: header.payload.signature
    return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
}

/**
 * Valida y decodifica un JWT
 * @param string $token  El JWT a validar
 * @param string $secret La clave secreta para verificar la firma
 * @return array|false  El payload decodificado o false si es inválido
 */
function validate_jwt($token, $secret) {
    if (!is_string($token) || $token === '' || !is_string($secret) || $secret === '') {
        return false;
    }

    $parts = explode('.', $token);

    // Verificar que tenga 3 partes (header.payload.signature)
    if (count($parts) !== 3) {
        return false;
    }

    list($header_b64, $payload_b64, $signature_b64) = $parts;

    foreach ([$header_b64, $payload_b64, $signature_b64] as $part) {
        if ($part === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $part)) {
            return false;
        }
    }

    // Decodificar header y payload
    $header_json = base64url_decode($header_b64);
    $payload_json = base64url_decode($payload_b64);
    if (!is_string($header_json) || !is_string($payload_json)) {
        return false;
    }
    $header = json_decode($header_json, true);
    $payload = json_decode($payload_json, true);

    if (!is_array($header) || !is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    // Aceptar exclusivamente el tipo y algoritmo emitidos por este backend.
    if (($header['typ'] ?? null) !== 'JWT' || ($header['alg'] ?? null) !== 'HS256') {
        return false;
    }

    // Verificar firma
    $signing_input = $header_b64 . '.' . $payload_b64;
    $expected_signature = base64url_encode(hash_hmac('sha256', $signing_input, $secret, true));

    if (!hash_equals($expected_signature, $signature_b64)) {
        return false;
    }

    // Los campos obligatorios impiden tokens incompletos y permiten revocación por sesión.
    if (!isset($payload['user_id'], $payload['session_version'], $payload['exp'], $payload['username'], $payload['email'])
        || !is_int($payload['user_id']) || $payload['user_id'] <= 0
        || !is_int($payload['session_version']) || $payload['session_version'] < 1
        || !is_int($payload['exp']) || $payload['exp'] <= time()
        || !is_string($payload['username']) || $payload['username'] === ''
        || !is_string($payload['email']) || $payload['email'] === '') {
        return false;
    }

    return $payload;
}

/**
 * Decodifica base64url
 * @param string $data
 * @return string
 */
function base64url_decode($data) {
    if (!is_string($data) || $data === '' || strlen($data) % 4 === 1) {
        return false;
    }
    $padding = (4 - strlen($data) % 4) % 4;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $padding), true);
}
