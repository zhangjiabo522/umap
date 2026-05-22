<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = sanitizeInput($input['name'] ?? '');

    if (empty($name) || mb_strlen($name) > 50) {
        jsonResponse(['success' => false, 'message' => '标签名称1-50个字符']);
    }

    try {
        $stmt = $db->prepare("INSERT INTO user_custom_tags (user_id, name) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $name]);
        $id = $db->lastInsertId();

        jsonResponse(['success' => true, 'message' => '自定义标签已添加', 'tag' => ['id' => (int)$id, 'name' => $name]]);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => '添加失败'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    try {
        $stmt = $db->prepare("DELETE FROM user_custom_tags WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $_SESSION['user_id']]);
        jsonResponse(['success' => true, 'message' => '已删除']);
    } catch (PDOException $e) {
        jsonResponse(['success' => false, 'message' => '删除失败'], 500);
    }
}
