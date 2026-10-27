<?php
// HTTP integration tests: only the isolated Docker network and fake identities.
$failures = 0;
function requestApi($method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents('http://app:8080' . $path, false, stream_context_create($options));
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; }
}
function login($name) {
    [$status, $data] = requestApi('POST', '/api/login', ['login' => $name, 'password' => 'synthetic-password']);
    expectCase($status === 200 && ($data['success'] ?? false) && isset($data['token']), 'login ' . $name);
    return $data['token'] ?? '';
}
[$s] = requestApi('GET','/api/powerbi/tokens/1');
expectCase($s === 401, 'Power BI rejects missing authentication');
[$s] = requestApi('GET','/api/reportes/1');
expectCase($s === 401, 'reports reject missing authentication');
$a = login('usera'); $b = login('userb'); $admin = login('admin');
[$s,$data,$raw] = requestApi('GET','/api/empresas',null,$a);
expectCase($s === 200 && ($data['success'] ?? false) && count($data['data'] ?? []) === 1 && ($data['data'][0]['id'] ?? null) == 1, 'user A sees only assigned company');
expectCase(strpos($raw, 'powerbi_clientsecret') === false && strpos($raw, 'fixture-private-value') === false, 'company response omits private credentials');
[$s,$data] = requestApi('GET','/api/empresas/2',null,$a);
expectCase(!($data['success'] ?? false), 'user A cannot read company B by ID');
[$s,$data] = requestApi('GET','/api/empresas/ciudad/Ciudad%20B',null,$a);
expectCase(count($data['data'] ?? []) === 0, 'user A cannot discover company B by city');
[$s,$data] = requestApi('GET','/api/empresas',null,$admin);
expectCase(count($data['data'] ?? []) === 2, 'administrator sees companies');
[$s,$data] = requestApi('GET','/api/powerbi/tokens/1',null,$a);
expectCase($s === 200 && ($data['access_token'] ?? '') === 'synthetic-provider-token', 'assigned user obtains simulated Power BI token (status ' . $s . ')' );
[$s] = requestApi('GET','/api/powerbi/tokens/2',null,$a);
expectCase($s === 404, 'inactive or absent report assignment rejected');
[$s] = requestApi('GET','/api/powerbi/tokens/2',null,$b);
expectCase($s === 200, 'second assigned user gets simulated token (status ' . $s . ')' );
[$s] = requestApi('GET','/api/powerbi/tokens/2',null,$admin);
expectCase($s === 200, 'administrator accesses other active assignment (status ' . $s . ')' );
[$s] = requestApi('POST','/api/empresa-usuario', ['empresa_id'=>2,'user_id'=>1],$a);
expectCase($s === 403, 'user cannot self assign company');
[$s] = requestApi('POST','/api/empresa-reporte-usuario', ['empresa_reporte_id'=>2,'user_id'=>1],$a);
expectCase($s === 403, 'user cannot self assign report');
[$s] = requestApi('DELETE','/api/empresas/2',null,$a);
expectCase($s === 403, 'user cannot delete company');
[$s,$data] = requestApi('GET','/api/reportes/2',null,$a);
expectCase(!($data['success'] ?? false), 'user A cannot read foreign report by ID');
[$s,$data] = requestApi('GET','/api/reportes/3',null,$a);
expectCase(!($data['success'] ?? false), 'inactive user-report assignment blocks report access');
[$s,$data] = requestApi('GET','/api/reportes/1',null,$a);
expectCase($s === 200 && ($data['data']['id'] ?? null) == 1, 'user A reads assigned report by ID');
[$s,$data] = requestApi('GET','/api/reportes',null,$a);
expectCase($s === 200 && count($data['data'] ?? []) === 1 && ($data['data'][0]['id'] ?? null) == 1, 'user A list contains only assigned report');
[$s,$data] = requestApi('GET','/api/reportes/grupoid/00000000-0000-0000-0000-000000000002',null,$a);
expectCase($s === 200 && count($data['data'] ?? []) === 0, 'user A cannot read foreign report group');
[$s,$data] = requestApi('GET','/api/reportes/grupoid/00000000-0000-0000-0000-000000000001',null,$a);
expectCase($s === 200 && count($data['data'] ?? []) === 1 && ($data['data'][0]['id'] ?? null) == 1, 'user A reads assigned report group');
[$s,$data] = requestApi('GET','/api/reportes/reporteid/00000000-0000-0000-0000-000000000022',null,$a);
expectCase(!($data['success'] ?? false), 'user A cannot read foreign report UUID');
[$s,$data] = requestApi('GET','/api/reportes/reporteid/00000000-0000-0000-0000-000000000011',null,$a);
expectCase($s === 200 && ($data['data']['id'] ?? null) == 1, 'user A reads assigned report UUID');
[$s,$data] = requestApi('GET','/api/reportes/2',null,$b);
expectCase($s === 200 && ($data['data']['id'] ?? null) == 2, 'user B retains assigned report access');
[$s,$data] = requestApi('GET','/api/reportes',null,$admin);
expectCase($s === 200 && count($data['data'] ?? []) === 3, 'administrator lists active reports');
[$s,$data] = requestApi('GET','/api/reportes/2',null,$admin);
expectCase($s === 200 && ($data['data']['id'] ?? null) == 2, 'administrator reads active report');
[$s,$data,$raw] = requestApi('GET','/api/powerbi/tokens/999',null,$admin);
expectCase($s === 404 && strpos($raw, 'SQLSTATE') === false && strpos($raw, 'fixture-private-value') === false, 'missing report returns sanitized error');
echo "Failures: $failures\n";
exit($failures ? 1 : 0);
