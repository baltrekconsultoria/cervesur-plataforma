<?php
/**
 * CERVESUR P5-SEC01 — candidato AISLADO. NO instalado ni integrado en producción.
 *
 * Autenticación estricta de sesiones JWT para el backend PHP heredado de Flutter.
 * No reemplaza a lib/auth_middleware.php ni a lib/jwt.php existentes. El empalme
 * con el middleware desplegado depende de una copia exacta autorizada de ese archivo,
 * del inventario de roles C-5 y del QA de Flutter frente a 401.
 *
 * CONTRATO: invocar SOLO después de extraer un Bearer token completo por una ruta
 * HTTP autorizada. Esta función valida POR SÍ MISMA firma, expiración y claims.
 * No aceptar role, id ni session_version suministrados en query/body/cabeceras.
 * @return array{user_id:int,username:string,email:string,role:string}|false
 */

function p5_strict_b64url_decode($encoded) {
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) % 4 === 1 ||
        !preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) {
        return false;
    }
    $padding = (4 - strlen($encoded) % 4) % 4;
    return base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);
}

/**
 * Solo los roles conocidos tienen representación autenticada.
 * 'usuario' es el valor histórico que el modelo normaliza a 'user'.
 */
function p5_strict_db_role($raw) {
    if (!is_string($raw)) {
        return null;
    }
    $value = strtolower(trim($raw));
    if ($value === 'admin') {
        return 'admin';
    }
    if ($value === 'user' || $value === 'usuario') {
        return 'user';
    }
    return null;
}

/**
 * @param string $token Token compactado HS256, sin "Bearer ".
 * @param string $secret JWT_SECRET real — nunca loguearlo ni pasarlo por CLI.
 * @param PDO $pdo Conexión ya existente del backend PHP (solo SELECT).
 * @param ?int $now Reloj inyectable solo para pruebas aisladas.
 * @return array{user_id:int,username:string,email:string,role:string}|false
 */
function p5_strict_authenticate_jwt($token, $secret, PDO $pdo, $now = null) {
    if (!is_string($token) || strlen($token) < 20 || strlen($token) > 8192 ||
        !is_string($secret) || $secret === '') {
        return false;
    }
    if ($now !== null && (!is_int($now) || $now <= 0)) {
        return false;
    }
    $clock = $now ?? time();
    $segments = explode('.', $token);
    if (count($segments) !== 3) {
        return false;
    }
    [$headerEnc, $payloadEnc, $signatureEnc] = $segments;
    $headerRaw = p5_strict_b64url_decode($headerEnc);
    $payloadRaw = p5_strict_b64url_decode($payloadEnc);
    $signatureRaw = p5_strict_b64url_decode($signatureEnc);
    if (!is_string($headerRaw) || !is_string($payloadRaw) ||
        !is_string($signatureRaw) || strlen($signatureRaw) !== 32) {
        return false;
    }
    try {
        $header = json_decode($headerRaw, true, 16, JSON_THROW_ON_ERROR);
        $payload = json_decode($payloadRaw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return false;
    }
    if (!is_array($header) || !is_array($payload) ||
        ($header['typ'] ?? null) !== 'JWT' ||
        ($header['alg'] ?? null) !== 'HS256') {
        return false;
    }
    $expected = hash_hmac('sha256', $headerEnc . '.' . $payloadEnc, $secret, true);
    if (!hash_equals($expected, $signatureRaw)) {
        return false;
    }
    if (!isset($payload['user_id'], $payload['session_version'], $payload['exp'],
                $payload['username'], $payload['email']) ||
        !is_int($payload['user_id']) || $payload['user_id'] <= 0 ||
        !is_int($payload['session_version']) || $payload['session_version'] < 1 ||
        !is_int($payload['exp']) || $payload['exp'] <= $clock ||
        !is_string($payload['username']) || $payload['username'] === '' ||
        !is_string($payload['email']) || $payload['email'] === '') {
        return false;
    }

    // D4 mantiene JWT_EXPIRATION=86400. Un token firmado con expiración
    // excepcionalmente lejana no se acepta aunque su firma sea correcta.
    // Se admite una tolerancia de 60 s por desfase de reloj.
    if ($payload['exp'] > $clock + 86400 + 60) {
        return false;
    }

    try {
        $query = $pdo->prepare(
            'SELECT id, username, email, role, is_active, session_version FROM user WHERE id = :id LIMIT 1'
        );
        if (!$query->execute(['id' => $payload['user_id']])) {
            return false;
        }
        $row = $query->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Cualquier problema al consultar BD rechaza la sesión.
        // No incluir secretos o información de usuario en respuestas o logs.
        return false;
    }
    if (!is_array($row) ||
        !isset($row['is_active'], $row['session_version'], $row['role'], $row['username'], $row['email']) ||
        (int)$row['is_active'] !== 1 ||
        !ctype_digit((string)$row['session_version']) ||
        (int)$row['session_version'] !== $payload['session_version']) {
        return false;
    }
    $role = p5_strict_db_role($row['role']);
    if ($role === null ||
        !is_string($row['username']) || $row['username'] === '' ||
        !is_string($row['email']) || $row['email'] === '') {
        return false;
    }

    // La identidad y el rol efectivos salen exclusivamente de la BASE,
    // nunca del contenido del token ni de parámetros HTTP no autenticados.
    return [
        'user_id' => $payload['user_id'],
        'username' => $row['username'],
        'email' => $row['email'],
        'role' => $role
    ];
}
