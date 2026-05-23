<?php
/**
 * Server-side IP geolocation endpoint
 * GET /api/ip_location.php
 * Returns lat/lng based on client IP
 * Tries APIPPro first, falls back to ipwho.is
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

$logDir = __DIR__ . '/../logs';
$logFile = $logDir . '/ip_location_debug.log';

// Tier 1: APIPPro
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

$lat = null;
$lng = null;
$city = '';
$province = '';
$country = '';
$isp = '';
$source = 'apipro';

if (!$err && $response) {
    $data = json_decode($response, true);
    $d = $data['data'] ?? $data;
    $lat = $d['latitude'] ?? null;
    $lng = $d['longitude'] ?? null;
    $city = $d['city_name'] ?? $d['city'] ?? '';
    $province = $d['province_name'] ?? $d['province'] ?? '';
    $country = $d['country_name'] ?? $d['country'] ?? '';
    $isp = $d['isp'] ?? '';

    if ($lat === null || $lng === null) {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' | APIPPro no coords | ip=' . $clientIP . ' | raw=' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }
} else {
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' | APIPPro error | ip=' . $clientIP . ' | err=' . $err . ' | http=' . $httpCode . "\n", FILE_APPEND | LOCK_EX);
}

// Tier 2: ipwho.is fallback (server-side, no CORS issue)
if ($lat === null || $lng === null) {
    $source = 'ipwhois';
    $ch2 = curl_init('https://ipwho.is/' . urlencode($clientIP));
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp2 = curl_exec($ch2);
    $err2 = curl_error($ch2);
    curl_close($ch2);

    if (!$err2 && $resp2) {
        $d2 = json_decode($resp2, true);
        if (($d2['success'] ?? false) !== false && isset($d2['latitude']) && isset($d2['longitude'])) {
            $lat = $d2['latitude'];
            $lng = $d2['longitude'];
            $city = $d2['city'] ?? '';
            $province = $d2['region'] ?? '';
            $country = $d2['country'] ?? '';
            $isp = $d2['connection']['isp'] ?? '';
        } else {
            file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ipwho.is no coords | ip=' . $clientIP . ' | raw=' . ($resp2 ?: 'empty') . "\n", FILE_APPEND | LOCK_EX);
        }
    } else {
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' | ipwho.is error | ip=' . $clientIP . ' | err=' . $err2 . "\n", FILE_APPEND | LOCK_EX);
    }
}

if ($lat === null || $lng === null) {
    echo json_encode(['success' => false, 'message' => 'All IP geolocation services failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'lng' => (float)$lng,
    'lat' => (float)$lat,
    'city' => $city,
    'province' => $province,
    'country' => $country,
    'isp' => $isp,
    'ip' => $clientIP,
    'source' => $source,
], JSON_UNESCAPED_UNICODE);
