<?php
require_once __DIR__ . '/config.php';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function verifyRecaptcha($token) {
    // Check if this is an arithmetic captcha token
    if (strpos($token, 'arithmetic_verified_') === 0) {
        $answer = intval(str_replace('arithmetic_verified_', '', $token));
        if ($answer > 0) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'Arithmetic verification failed'];
    }

    // Original reCAPTCHA verification
    $urls = [
        'https://recaptcha.net/recaptcha/api/siteverify',
        'https://www.recaptcha.net/recaptcha/api/siteverify',
        'https://recaptcha.google.cn/recaptcha/api/siteverify',
    ];
    $data = [
        'secret' => RECAPTCHA_SECRET_KEY,
        'response' => $token,
    ];

    foreach ($urls as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if (!$err && $response) {
            $result = json_decode($response, true);
            if ($result) return $result;
        }
    }

    return ['success' => false, 'error' => 'reCAPTCHA verification failed'];
}

function sendEmail($to, $subject, $htmlBody) {
    require_once __DIR__ . '/vendor/autoload.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Mailer Error: ' . $mail->ErrorInfo);
        return false;
    }
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function sanitizeInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

function loadEnv(string $path): void {
    if (!file_exists($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!isset($_ENV[$key])) $_ENV[$key] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

function corsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
}

function requireAuth(): void {
    session_start();
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['success' => false, 'message' => '未登录'], 401);
    }
}
