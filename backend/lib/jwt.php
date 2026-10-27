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
    switch ($alg) {
        case 'HS256':
            $signature = hash_hmac('sha256', $signing_input, $secret, true);
            break;
        default:
            throw new Exception('Algoritmo no soportado');
    }
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
    $parts = explode('.', $token);

    // Verificar que tenga 3 partes (header.payload.signature)
    if (count($parts) !== 3) {
        return false;
    }

    list($header_b64, $payload_b64, $signature_b64) = $parts;

    // Decodificar header y payload
    $header = json_decode(base64url_decode($header_b64), true);
    $payload = json_decode(base64url_decode($payload_b64), true);

    if (!$header || !$payload) {
        return false;
    }

    // Verificar algoritmo
    if ($header['alg'] !== 'HS256') {
        return false;
    }

    // Verificar expiración
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }

    // Verificar firma
    $signing_input = $header_b64 . '.' . $payload_b64;
    $expected_signature = base64url_encode(hash_hmac('sha256', $signing_input, $secret, true));

    if (!hash_equals($expected_signature, $signature_b64)) {
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
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}
