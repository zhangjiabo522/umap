<?php
/**
 * Geolocation diagnostic logging endpoint
 * POST /api/geo_log.php
 * Body: JSON with geolocation event details
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$logFile = $logDir . '/geolocation.log';

$entry = [
    'time' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'event' => $input['event'] ?? 'unknown',
    'step' => $input['step'] ?? '',
    'status' => $input['status'] ?? '',
    'type' => $input['type'] ?? '',
    'lng' => $input['lng'] ?? null,
    'lat' => $input['lat'] ?? null,
    'error' => $input['error'] ?? '',
    'errorCode' => $input['errorCode'] ?? '',
    'detail' => $input['detail'] ?? '',
    'duration_ms' => $input['duration_ms'] ?? null,
    'sdk_loaded' => $input['sdk_loaded'] ?? null,
    'config_ok' => $input['config_ok'] ?? null,
    'security_type' => $input['security_type'] ?? '',
    'is_mobile' => $input['is_mobile'] ?? null,
    'platform' => $input['platform'] ?? '',
    'geolocation_api' => $input['geolocation_api'] ?? '',
    'https' => $input['https'] ?? null,
];

$logLine = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";

file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);

echo json_encode(['success' => true]);
