<?php
// Reenviador TCP mínimo para QA local (sin lógica HTTP, sin registro de contenido).
// Uso: php proxy.php 8080=app:8080,8081=app-short:8080
// Existe solo para publicar 127.0.0.1 hacia APIs que viven en una red Docker interna (sin salida a Internet).
$maps = [];
foreach (explode(',', $argv[1] ?? '') as $pair) {
    [$listen, $target] = explode('=', $pair, 2);
    $maps[(int)$listen] = $target;
}
if (!$maps) { fwrite(STDERR, "Sin mapeos\n"); exit(2); }
$servers = [];
foreach ($maps as $port => $target) {
    $server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
    if (!$server) { fwrite(STDERR, "No se pudo escuchar en $port: $errstr\n"); exit(1); }
    stream_set_blocking($server, false);
    $servers[$port] = $server;
}
$conns = [];
while (true) {
    $read = array_values($servers);
    $write = [];
    foreach ($conns as $c) {
        $read[] = $c['c']; $read[] = $c['u'];
        if ($c['c2u'] !== '') $write[] = $c['u'];
        if ($c['u2c'] !== '') $write[] = $c['c'];
    }
    $except = null;
    if (@stream_select($read, $write, $except, 1) === false) continue;
    foreach ($read as $r) {
        $port = array_search($r, $servers, true);
        if ($port !== false) {
            $client = @stream_socket_accept($r, 0);
            if (!$client) continue;
            $up = @stream_socket_client('tcp://' . $maps[$port], $errno, $errstr, 3);
            if (!$up) { fclose($client); continue; }
            stream_set_blocking($client, false); stream_set_blocking($up, false);
            $conns[] = ['c' => $client, 'u' => $up, 'c2u' => '', 'u2c' => '', 'closing' => false];
            continue;
        }
        foreach ($conns as $i => &$c) {
            if ($r === $c['c']) { $data = fread($r, 65536); if ($data === '' || $data === false) { if (feof($r)) $c['closing'] = true; } else $c['c2u'] .= $data; }
            elseif ($r === $c['u']) { $data = fread($r, 65536); if ($data === '' || $data === false) { if (feof($r)) $c['closing'] = true; } else $c['u2c'] .= $data; }
        }
        unset($c);
    }
    foreach ($write as $w) {
        foreach ($conns as &$c) {
            if ($w === $c['u'] && $c['c2u'] !== '') { $n = @fwrite($w, $c['c2u']); if ($n) $c['c2u'] = (string)substr($c['c2u'], $n); }
            elseif ($w === $c['c'] && $c['u2c'] !== '') { $n = @fwrite($w, $c['u2c']); if ($n) $c['u2c'] = (string)substr($c['u2c'], $n); }
        }
        unset($c);
    }
    foreach ($conns as $i => $c) {
        if ($c['closing'] && $c['u2c'] === '' && $c['c2u'] === '') { @fclose($c['c']); @fclose($c['u']); unset($conns[$i]); }
    }
}
