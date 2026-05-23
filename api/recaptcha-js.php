<?php
// reCAPTCHA JS proxy — serve from server to bypass GFW
header('Content-Type: application/javascript');
header('Cache-Control: public, max-age=86400');
header('Access-Control-Allow-Origin: *');

$hosts = ['recaptcha.net', 'www.gstatic.com'];
$basePath = '/recaptcha/api.js?render=explicit';

foreach ($hosts as $host) {
    $ch = curl_init('https://' . $host . $basePath);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36',
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$err && $response && strpos($response, 'grecaptcha') !== false) {
        echo $response;
        exit;
    }
}

// All hosts failed — return empty script so fallback triggers immediately
http_response_code(502);
echo '// reCAPTCHA unavailable';
