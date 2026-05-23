<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        jsonResponse(['success' => false, 'message' => '用户不存在'], 401);
    }

    // Try to get userlike
    try {
        $likeStmt = $db->prepare("SELECT userlike FROM users WHERE id = ?");
        $likeStmt->execute([$_SESSION['user_id']]);
        $user['userlike'] = $likeStmt->fetchColumn() ?: '';
    } catch (Throwable $e) {
        $user['userlike'] = '';
    }

    $prefStmt = $db->prepare("SELECT COUNT(*) FROM user_preferences WHERE user_id = ?");
    $prefStmt->execute([$_SESSION['user_id']]);
    $user['has_preferences'] = $prefStmt->fetchColumn() > 0;

    jsonResponse(['success' => true, 'user' => $user]);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => '获取用户信息失败'], 500);
}
