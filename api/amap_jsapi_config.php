<?php
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$_envFile = __DIR__ . '/../.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strpos(trim($_line), '#') === 0) continue;
        [$_k, $_v] = array_map('trim', explode('=', $_line, 2));
        $_ENV[$_k] = $_v;
    }
}

jsonResponse([
    'success' => true,
    'config' => [
        'key' => $_ENV['AMAP_JSAPI_KEY'] ?? $_ENV['AMAP_KEY'] ?? '',
        'serviceHost' => $_ENV['AMAP_SERVICE_HOST'] ?? '',
        'securityJsCode' => $_ENV['AMAP_SECURITY_JS_CODE'] ?? '',
    ],
]);
