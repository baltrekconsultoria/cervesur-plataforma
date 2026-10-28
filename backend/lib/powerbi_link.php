<?php
/**
 * Clasificación de enlaces de Power BI (UX-07C).
 *
 * Solo se admiten URLs HTTPS del host oficial `app.powerbi.com`, con un conjunto cerrado de rutas y parámetros.
 * No se descarga nada ni se sigue ninguna redirección: la clasificación es puramente léxica.
 *
 *   public  → «Publicar en web»  https://app.powerbi.com/view?r=…           (informe público en Power BI; se inserta en un iframe)
 *   embed   → inserción autenticada https://app.powerbi.com/reportEmbed?reportId=…[&groupId=…]  (requiere Microsoft; no configurado)
 *   portal  → enlace normal del informe https://app.powerbi.com/groups/{g}/reports/{r}  (NO apto para iframe; se reconoce para extraer los ids)
 *   otro    → rechazado (dominios externos, HTTP, HTML/iframe pegado, rutas o parámetros desconocidos)
 *
 * Los UUID solo se devuelven cuando están presentes en la URL y son válidos; jamás se inventan.
 * Debe mantenerse idéntica a frontend/src/domain/powerbiLink.ts (ambas se prueban con tests/fixtures/link_vectors.json).
 */

const POWERBI_LINK_HOST = 'app.powerbi.com';
const POWERBI_LINK_MAX_LENGTH = 1024;
const POWERBI_UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

if (!function_exists('classify_powerbi_link')) {
    /**
     * @param mixed $url
     * @return array{ok:bool, kind:?string, token:?string, report_id:?string, group_id:?string, error:?string}
     */
    function classify_powerbi_link($url)
    {
        $fail = function ($message) {
            return ['ok' => false, 'kind' => null, 'token' => null, 'report_id' => null, 'group_id' => null, 'error' => $message];
        };
        if (!is_string($url) || trim($url) === '') {
            return $fail('Ingrese el enlace del informe de Power BI.');
        }
        $url = trim($url);
        if (strpos($url, '<') !== false || stripos($url, 'iframe') !== false) {
            return $fail('Pegue solo la URL del informe, no el código HTML (iframe).');
        }
        if (strlen($url) > POWERBI_LINK_MAX_LENGTH) {
            return $fail('El enlace es demasiado largo (máximo ' . POWERBI_LINK_MAX_LENGTH . ' caracteres).');
        }
        if (!preg_match('#^https://#i', $url)) {
            return $fail('El enlace debe usar HTTPS.');
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return $fail('El enlace no puede contener espacios, saltos de línea ni barras invertidas.');
        }
        if (!preg_match('#^https://([^/?\#]+)(/[^?\#]*)?(\?[^\#]*)?$#i', $url, $m)) {
            return $fail('El enlace no es una URL válida (no se admiten fragmentos «#»).');
        }
        if (strtolower($m[1]) !== POWERBI_LINK_HOST) {
            return $fail('El enlace debe pertenecer a ' . POWERBI_LINK_HOST . '.');
        }
        $path = $m[2] ?? '';
        $queryString = isset($m[3]) ? substr($m[3], 1) : '';

        // Parámetros: sin duplicados ni segmentos vacíos; la clave y el valor se validan por tipo de enlace.
        $query = [];
        if ($queryString !== '') {
            foreach (explode('&', $queryString) as $pair) {
                if (!preg_match('/^([A-Za-z][A-Za-z0-9]*)=([^=&]*)$/', $pair, $kv) || array_key_exists($kv[1], $query)) {
                    return $fail('El enlace tiene parámetros no válidos o repetidos.');
                }
                $query[$kv[1]] = $kv[2];
            }
        }
        $uuid = '/^' . POWERBI_UUID_PATTERN . '$/';
        $bool = '/^(true|false)$/';
        $validate = function (array $rules) use ($query, $fail) {
            foreach ($query as $key => $value) {
                if (!isset($rules[$key])) {
                    return $fail("El parámetro «{$key}» no está permitido en este tipo de enlace.");
                }
                if (!preg_match($rules[$key], $value)) {
                    return $fail("El valor del parámetro «{$key}» no es válido.");
                }
            }
            return null;
        };

        // A) Publicar en web
        if ($path === '/view') {
            if ($error = $validate([
                'r' => '/^[A-Za-z0-9_-]{16,1000}(=|%3D|%3d){0,2}$/',
                'pageName' => '/^[A-Za-z0-9]{1,64}$/',
                'navContentPaneEnabled' => $bool,
                'filterPaneEnabled' => $bool,
                'ctid' => $uuid,
            ])) {
                return $error;
            }
            if (!isset($query['r'])) {
                return $fail('El enlace de publicación en web debe incluir el parámetro «r».');
            }
            return ['ok' => true, 'kind' => 'public', 'token' => $query['r'], 'report_id' => null, 'group_id' => null, 'error' => null];
        }

        // B) Inserción autenticada
        if ($path === '/reportEmbed') {
            if ($error = $validate([
                'reportId' => $uuid,
                'groupId' => $uuid,
                'autoAuth' => $bool,
                'ctid' => $uuid,
                'config' => '/^[A-Za-z0-9_%=-]{1,1000}$/',
            ])) {
                return $error;
            }
            if (!isset($query['reportId'])) {
                return $fail('El enlace de inserción debe incluir un «reportId» válido.');
            }
            return [
                'ok' => true, 'kind' => 'embed', 'token' => null,
                'report_id' => strtolower($query['reportId']),
                'group_id' => isset($query['groupId']) ? strtolower($query['groupId']) : null,
                'error' => null,
            ];
        }

        // C) Enlace normal del informe (no apto para iframe)
        if (preg_match('#^/groups/(' . POWERBI_UUID_PATTERN . ')/reports/(' . POWERBI_UUID_PATTERN . ')(/[A-Za-z0-9_]{1,64})?$#', $path, $p)
            || preg_match('#^/()reports/(' . POWERBI_UUID_PATTERN . ')(/[A-Za-z0-9_]{1,64})?$#', $path, $p)) {
            if ($error = $validate(['experience' => '/^power-bi$/', 'ctid' => $uuid])) {
                return $error;
            }
            return [
                'ok' => true, 'kind' => 'portal', 'token' => null,
                'report_id' => strtolower($p[2]),
                'group_id' => $p[1] !== '' ? strtolower($p[1]) : null,
                'error' => null,
            ];
        }

        return $fail('El enlace de Power BI no es de un tipo compatible: use «Publicar en web» (…/view?r=…), un enlace de inserción (…/reportEmbed?reportId=…) o el enlace del informe (…/groups/…/reports/…).');
    }
}

if (!function_exists('generate_report_ref')) {
    /**
     * Referencia interna de Cervesur (UUID v4) para informes identificados solo por su enlace público.
     * NO es un identificador de Power BI y nunca debe enviarse a Microsoft.
     */
    function generate_report_ref()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
