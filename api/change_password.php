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

session_start();

if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

$data = json_decode(file_get_contents('php://input'), true);
$currentPassword = $data['current_password'] ?? '';
$newPassword = $data['new_password'] ?? '';

if (!$currentPassword || !$newPassword) {
    jsonResponse(['success' => false, 'message' => '参数缺失']);
}

if (strlen($newPassword) < 6) {
    jsonResponse(['success' => false, 'message' => '新密码至少6位']);
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        jsonResponse(['success' => false, 'message' => '当前密码错误']);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, $_SESSION['user_id']]);

    jsonResponse(['success' => true, 'message' => '密码修改成功']);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => '服务器错误'], 500);
}
