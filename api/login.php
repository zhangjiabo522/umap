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

$account = sanitizeInput($input['account'] ?? '');
$password = $input['password'] ?? '';
$recaptchaToken = $input['recaptcha_token'] ?? '';

if (empty($account)) {
    jsonResponse(['success' => false, 'message' => '请输入用户名或邮箱']);
}

if (empty($password)) {
    jsonResponse(['success' => false, 'message' => '请输入密码']);
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

    $stmt = $db->prepare("SELECT id, username, email, password_hash, email_verified FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$account, $account]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['success' => false, 'message' => '用户名或密码错误']);
    }

    if (!$user['email_verified']) {
        jsonResponse(['success' => false, 'message' => '请先验证邮箱后再登录', 'need_verify' => true]);
    }

    session_start();
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];

    jsonResponse([
        'success' => true,
        'message' => '登录成功',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
        ]
    ]);
} catch (PDOException $e) {
    error_log('Login error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '登录失败，请稍后重试'], 500);
}
