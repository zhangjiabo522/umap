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
    ensureFavoritesTable($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $db->prepare("SELECT id, name, city, description, address, tags, rating, location, confidence, created_at FROM user_favorites WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$_SESSION['user_id']]);
        $favorites = array_map('formatFavorite', $stmt->fetchAll());
        jsonResponse(['success' => true, 'favorites' => $favorites]);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($data['name'] ?? '');
        if (!$name) jsonResponse(['success' => false, 'message' => '景点名称缺失']);

        $tags = $data['tags'] ?? [];
        if (!is_array($tags)) $tags = [];

        $stmt = $db->prepare("
            INSERT INTO user_favorites (user_id, name, city, description, address, tags, rating, location, confidence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                city = VALUES(city), description = VALUES(description), address = VALUES(address), tags = VALUES(tags),
                rating = VALUES(rating), location = VALUES(location), confidence = VALUES(confidence), created_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $name,
            trim($data['city'] ?? ''),
            trim($data['description'] ?? ''),
            trim($data['address'] ?? ''),
            json_encode(array_values($tags), JSON_UNESCAPED_UNICODE),
            trim($data['rating'] ?? ''),
            trim($data['location'] ?? ''),
            isset($data['confidence']) ? floatval($data['confidence']) : null,
        ]);
        jsonResponse(['success' => true, 'message' => '已收藏']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $name = trim($data['name'] ?? '');
        if (!$name) jsonResponse(['success' => false, 'message' => '景点名称缺失']);
        $stmt = $db->prepare("DELETE FROM user_favorites WHERE user_id = ? AND name = ?");
        $stmt->execute([$_SESSION['user_id'], $name]);
        jsonResponse(['success' => true, 'message' => '已取消收藏']);
    }

    jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => '服务器错误'], 500);
}

function ensureFavoritesTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_favorites` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `name` VARCHAR(120) NOT NULL,
            `city` VARCHAR(80) NOT NULL DEFAULT '',
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `address` VARCHAR(255) NOT NULL DEFAULT '',
            `tags` JSON DEFAULT NULL,
            `rating` VARCHAR(20) NOT NULL DEFAULT '',
            `location` VARCHAR(60) NOT NULL DEFAULT '',
            `confidence` DECIMAL(4,3) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uk_user_name` (`user_id`, `name`),
            KEY `idx_user_created` (`user_id`, `created_at`),
            CONSTRAINT `fk_uf_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function formatFavorite(array $row): array {
    $row['tags'] = $row['tags'] ? (json_decode($row['tags'], true) ?: []) : [];
    if ($row['confidence'] !== null) $row['confidence'] = floatval($row['confidence']);
    return $row;
}
