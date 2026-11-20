<?php
// Usage: php test_api.php   (the server must be running: php -S 127.0.0.1:8000)
$base = $argv[1] ?? 'http://127.0.0.1:8000/api/students.php';

function req(string $method, string $url, ?string $body = null): array {
    $opts = ['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ]];
    if ($body !== null) $opts['http']['content'] = $body;
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!isset($http_response_header)) return [0, null, ''];
    preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m);
    return [(int)$m[1], json_decode((string)$res, true), (string)$res];
}

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("[%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok ? '' : "  -> $detail");
}

$valid = '{"first_name":"Test","middle_initial":"T","last_name":"Student","program":"BSIT","year_level":1}';

[$s] = req('GET', $base);
if ($s === 0) { exit("Cannot reach $base. Is the server running?\n"); }

[$s, $j] = req('GET', $base);
check('GET all -> 200 with data array', $s === 200 && isset($j['data']) && is_array($j['data']), "status $s");

[$s, $j] = req('POST', $base, $valid);
$id = $j['data']['id'] ?? 0;
check('POST valid -> 201 with id', $s === 201 && $id > 0, "status $s");

[$s, $j] = req('GET', "$base?id=$id");
check('GET one (exists) -> 200', $s === 200 && ($j['data']['last_name'] ?? '') === 'Student', "status $s");

[$s] = req('GET', "$base?id=999999");
check('GET one (missing) -> 404', $s === 404, "status $s");

[$s] = req('GET', "$base?id=abc");
check('GET id=abc -> 400', $s === 400, "status $s");

[$s, $j] = req('POST', $base, '{"first_name":"","last_name":"X","program":"BSIT","year_level":1}');
check('POST empty first_name -> 422', $s === 422, "status $s");

[$s] = req('POST', $base, '{bad json');
check('POST malformed JSON -> 400', $s === 400, "status $s");

[$s] = req('POST', $base, '{"first_name":"A","last_name":"B","program":"BSBA","year_level":1}');
check('POST program=BSBA -> 422', $s === 422, "status $s");

[$s] = req('POST', $base, '{"first_name":"A","last_name":"B","program":"BSIT","year_level":5}');
check('POST year_level=5 -> 422', $s === 422, "status $s");

[$s, $j] = req('PUT', "$base?id=$id", '{"first_name":"Test","middle_initial":"T","last_name":"Student","program":"BSCS","year_level":2}');
check('PUT update -> 200', $s === 200 && ($j['data']['program'] ?? '') === 'BSCS', "status $s");

[$s, $j] = req('GET', "$base?id=$id");
check('PUT change is saved', ($j['data']['program'] ?? '') === 'BSCS' && ($j['data']['year_level'] ?? 0) === 2, "status $s");

[$s] = req('PUT', "$base?id=999999", $valid);
check('PUT missing id -> 404', $s === 404, "status $s");

[$s] = req('PATCH', "$base?id=$id", $valid);
check('PATCH (unsupported) -> 405', $s === 405, "status $s");

[$s, $j] = req('GET', "$base?program=BSIT");
$allBsit = $s === 200 && array_reduce($j['data'] ?? [], fn($c, $r) => $c && $r['program'] === 'BSIT', true);
check('Filter ?program=BSIT', $allBsit, "status $s");

[$s, $j] = req('GET', "$base?limit=5");
check('Pagination ?limit=5', $s === 200 && count($j['data'] ?? []) <= 5, "status $s");

[$s, $j] = req('GET', "$base?search=Student");
check('Search ?search=Student', $s === 200 && count($j['data'] ?? []) >= 1, "status $s");

[$s] = req('DELETE', "$base?id=$id");
check('DELETE -> 200', $s === 200, "status $s");

[$s] = req('GET', "$base?id=$id");
check('Deleted record is gone (404)', $s === 404, "status $s");

[$s] = req('DELETE', "$base?id=$id");
check('DELETE again -> 404', $s === 404, "status $s");

echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
