<?php
/**
 * Server-side IP geolocation endpoint
 * GET /api/ip_location.php
 * Returns lat/lng based on client IP using APIPPro
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$clientIP = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($clientIP, ',') !== false) {
    $clientIP = trim(explode(',', $clientIP)[0]);
}

if (empty($clientIP) || $clientIP === '127.0.0.1' || $clientIP === '::1') {
    echo json_encode(['success' => false, 'message' => 'Cannot determine client IP'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Call APIPPro
$url = 'https://ipv4.ink/ipv4?ip=' . urlencode($clientIP);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
$response = curl_exec($ch);
$err = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || !$response) {
    echo json_encode(['success' => false, 'message' => 'APIPPro request failed: ' . $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);
$d = $data['data'] ?? $data;

$lat = $d['latitude'] ?? null;
$lng = $d['longitude'] ?? null;

if ($lat === null || $lng === null) {
    echo json_encode(['success' => false, 'message' => 'No coordinates in response', 'raw' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'lng' => (float)$lng,
    'lat' => (float)$lat,
    'city' => $d['city_name'] ?? $d['city'] ?? '',
    'province' => $d['province_name'] ?? $d['province'] ?? '',
    'country' => $d['country_name'] ?? $d['country'] ?? '',
    'isp' => $d['isp'] ?? '',
    'ip' => $clientIP,
], JSON_UNESCAPED_UNICODE);
