<?php
$token = $_POST['token'] ?? $_GET['token'] ?? '';
$secret = $_POST['secret'] ?? $_GET['secret'] ?? '';

if (empty($token) || empty($secret)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$urls = [
    'https://recaptcha.google.cn/recaptcha/api/siteverify',
    'https://www.recaptcha.net/recaptcha/api/siteverify',
];

$result = null;
foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$err && $response) {
        $result = $response;
        break;
    }
}

if ($result) {
    header('Content-Type: application/json');
    echo $result;
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'reCAPTCHA verification failed']);
}
