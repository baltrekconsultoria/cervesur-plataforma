<?php
/**
 * Paginación de servidor (UX-06B.0).
 *
 * Contrato (aditivo, compatible con clientes anteriores):
 *  - Parámetros de consulta: `page` (entero >= 1) y `per_page` (entero entre 1 y PAGINATION_MAX_PER_PAGE).
 *  - Sin parámetros, los listados que ya tenían `LIMIT 20` siguen devolviendo esas 20 filas (ahora con orden
 *    determinista y con el bloque `pagination` que indica el total real); los listados que no tenían límite
 *    siguen sin límite hasta que el cliente envía `page` o `per_page`.
 *  - Parámetros inválidos -> HTTP 400 `{ success:false, message }`. Nunca se ignoran en silencio.
 *  - Los filtros de identidad, pertenencia y estado activo forman parte de la consulta ANTES de contar y paginar,
 *    por lo que `total` solo cuenta lo que el usuario autenticado está autorizado a ver.
 *  - Respuesta: se conservan `success`, `data`, `count` (filas de esta página) y `message`; se añade
 *    `pagination: { page, per_page, total, total_pages, has_more }`.
 */
const PAGINATION_MAX_PER_PAGE = 100;
const PAGINATION_MAX_PAGE = 1000000;

/**
 * Lee y valida `page` / `per_page`.
 * @param array $query Normalmente $_GET.
 * @param int|null $defaultPerPage Tamaño por defecto; null = sin paginación si el cliente no envía parámetros.
 * @return array|null ['page','per_page','offset'] | ['error' => string] | null (sin paginación)
 */
function parse_pagination(array $query, $defaultPerPage = null) {
    $hasPage = array_key_exists('page', $query);
    $hasPerPage = array_key_exists('per_page', $query);
    if (!$hasPage && !$hasPerPage && $defaultPerPage === null) {
        return null;
    }
    $page = 1;
    $perPage = $defaultPerPage === null ? 50 : (int)$defaultPerPage;
    if ($hasPage) {
        $page = filter_var($query['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PAGINATION_MAX_PAGE]]);
        if ($page === false) {
            return ['error' => 'El parámetro page debe ser un entero mayor o igual a 1'];
        }
    }
    if ($hasPerPage) {
        $perPage = filter_var($query['per_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PAGINATION_MAX_PER_PAGE]]);
        if ($perPage === false) {
            return ['error' => 'El parámetro per_page debe ser un entero entre 1 y ' . PAGINATION_MAX_PER_PAGE];
        }
    }
    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
}

/**
 * Para routers: valida y, si hay error, responde 400 y termina. Devuelve el arreglo de paginación o null.
 */
function require_pagination($defaultPerPage = null) {
    $pagination = parse_pagination($_GET, $defaultPerPage);
    if (is_array($pagination) && isset($pagination['error'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $pagination['error']]);
        exit;
    }
    return $pagination;
}

function pagination_meta(array $pagination, $total) {
    $total = (int)$total;
    return [
        'page' => $pagination['page'],
        'per_page' => $pagination['per_page'],
        'total' => $total,
        'total_pages' => (int)ceil($total / $pagination['per_page']),
        'has_more' => ($pagination['offset'] + $pagination['per_page']) < $total
    ];
}

/** Fragmento `['pagination' => ...]` para unir (+) a una respuesta; vacío si no se pagina. */
function pagination_response($pagination, $total) {
    return $pagination === null ? [] : ['pagination' => pagination_meta($pagination, $total)];
}

function pagination_bind($stmt, array $params) {
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . ltrim((string)$name, ':'), $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

/**
 * Ejecuta una consulta SELECT ya acotada por autorización y devuelve [filas, total].
 *
 * @param string $sql SELECT completo SIN `ORDER BY` ni `LIMIT` (constante del código, sin datos del usuario).
 * @param array $params Parámetros con nombre de ese SELECT.
 * @param string $orderBy Orden determinista (constante del código); debe terminar en un identificador único.
 * @param array|null $pagination Resultado de parse_pagination(); null = sin límite.
 * @return array [array $rows, int|null $total]
 */
function paginated_rows($pdo, $sql, array $params, $orderBy, $pagination) {
    $total = null;
    if ($pagination !== null) {
        $count = $pdo->prepare("SELECT COUNT(*) FROM ($sql) AS pagination_count");
        pagination_bind($count, $params);
        $count->execute();
        $total = (int)$count->fetchColumn();
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY ' . $orderBy . ($pagination !== null ? ' LIMIT :pagination_limit OFFSET :pagination_offset' : ''));
    if ($pagination === null) {
        // Sin paginación: exactamente el mismo modo de ejecución que antes (compatibilidad).
        $stmt->execute($params);
    } else {
        // Con paginación LIMIT/OFFSET se enlazan como enteros (nunca interpolados).
        pagination_bind($stmt, $params);
        $stmt->bindValue(':pagination_limit', (int)$pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue(':pagination_offset', (int)$pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();
    }
    return [$stmt->fetchAll(PDO::FETCH_ASSOC), $total];
}
