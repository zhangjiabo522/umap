<?php
require_once __DIR__ . '/config.php';

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function verifyRecaptcha($token) {
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
