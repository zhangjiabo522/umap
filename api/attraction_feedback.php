<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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
    ensureFeedbackTable($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $type = $_GET['type'] ?? '';
        $where = $type === 'like' || $type === 'dislike' ? ' AND feedback_type = ?' : '';
        $params = $type === 'like' || $type === 'dislike' ? [$_SESSION['user_id'], $type] : [$_SESSION['user_id']];
        $stmt = $db->prepare("SELECT id, name, city, description, address, tags, feedback_type, created_at FROM user_attraction_feedback WHERE user_id = ?{$where} ORDER BY created_at DESC");
        $stmt->execute($params);
        jsonResponse(['success' => true, 'items' => array_map('formatFeedback', $stmt->fetchAll())]);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim($data['name'] ?? '');
    if (!$name) jsonResponse(['success' => false, 'message' => '景点名称缺失']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $type = $data['type'] ?? '';
        if ($type !== 'like' && $type !== 'dislike') jsonResponse(['success' => false, 'message' => '反馈类型错误']);

        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        $stmt = $db->prepare("
            INSERT INTO user_attraction_feedback (user_id, name, city, description, address, tags, feedback_type)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                city = VALUES(city), description = VALUES(description), address = VALUES(address), tags = VALUES(tags),
                feedback_type = VALUES(feedback_type), created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $name,
            trim($data['city'] ?? ''),
            trim($data['description'] ?? ''),
            trim($data['address'] ?? ''),
            json_encode(array_values($tags), JSON_UNESCAPED_UNICODE),
            $type,
        ]);
        jsonResponse(['success' => true, 'message' => $type === 'like' ? '已点赞' : '已标记不喜欢']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $stmt = $db->prepare("DELETE FROM user_attraction_feedback WHERE user_id = ? AND name = ?");
        $stmt->execute([$_SESSION['user_id'], $name]);
        jsonResponse(['success' => true, 'message' => '已删除']);
    }

    jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => '服务器错误'], 500);
}

function ensureFeedbackTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_attraction_feedback` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `city` VARCHAR(80) NOT NULL DEFAULT '',
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `address` VARCHAR(255) NOT NULL DEFAULT '',
            `tags` JSON DEFAULT NULL,
            `feedback_type` ENUM('like','dislike') NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_user_name` (`user_id`, `name`),
            KEY `idx_user_type` (`user_id`, `feedback_type`, `created_at`),
            CONSTRAINT `fk_uaf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function formatFeedback(array $row): array {
    $row['tags'] = $row['tags'] ? (json_decode($row['tags'], true) ?: []) : [];
    return $row;
}
