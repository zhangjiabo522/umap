<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$username = sanitizeInput($input['username'] ?? '');
$email = filter_var(trim($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$password = $input['password'] ?? '';
$recaptchaToken = $input['recaptcha_token'] ?? '';

if (empty($username) || strlen($username) < 3 || strlen($username) > 50) {
    jsonResponse(['success' => false, 'message' => '用户名长度需要3-50个字符']);
}

if (!$email) {
    jsonResponse(['success' => false, 'message' => '请输入有效的邮箱地址']);
}

if (empty($password) || strlen($password) < 8) {
    jsonResponse(['success' => false, 'message' => '密码长度至少8个字符']);
}

if (empty($recaptchaToken)) {
    jsonResponse(['success' => false, 'message' => '请完成人机验证']);
}

$recaptchaResult = verifyRecaptcha($recaptchaToken);
if (empty($recaptchaResult['success']) || ($recaptchaResult['score'] ?? 0) < 0.5) {
    jsonResponse(['success' => false, 'message' => '人机验证失败，请重试']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => '用户名或邮箱已被注册']);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$username, $email, $passwordHash]);
    $userId = $db->lastInsertId();

    $token = generateToken();
    $expiresAt = date('Y-m-d H:i:s', time() + VERIFICATION_EXPIRE_HOURS * 3600);

    $stmt = $db->prepare("INSERT INTO email_verifications (user_id, token, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $token, $expiresAt]);

    $verifyLink = SITE_URL . '/api/verify-email.php?token=' . $token;

    $htmlBody = '
    <div style="max-width:600px;margin:0 auto;padding:20px;font-family:Arial,sans-serif;">
        <h2 style="color:#333;">欢迎注册 UMap</h2>
        <p>您好，' . htmlspecialchars($username) . '：</p>
        <p>感谢您注册 UMap，请点击以下链接验证您的邮箱：</p>
        <p style="margin:20px 0;">
            <a href="' . $verifyLink . '" style="background:#1890ff;color:#fff;padding:10px 24px;text-decoration:none;border-radius:4px;">验证邮箱</a>
        </p>
        <p style="color:#999;font-size:12px;">此链接将在 ' . VERIFICATION_EXPIRE_HOURS . ' 小时后失效。</p>
        <p style="color:#999;font-size:12px;">如果按钮无法点击，请复制以下链接到浏览器打开：</p>
        <p style="color:#1890ff;font-size:12px;word-break:break-all;">' . $verifyLink . '</p>
    </div>';

    $emailSent = sendEmail($email, '【UMap】验证您的邮箱', $htmlBody);

    if ($emailSent) {
        jsonResponse(['success' => true, 'message' => '注册成功，请查收验证邮件']);
    } else {
        jsonResponse(['success' => true, 'message' => '注册成功，但验证邮件发送失败，请联系管理员']);
    }
} catch (PDOException $e) {
    error_log('Register error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '注册失败，请稍后重试'], 500);
}
