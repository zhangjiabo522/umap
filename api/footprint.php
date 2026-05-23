<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
    ensureFootprintsTable($db);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $userId = $_SESSION['user_id'];

        $stmt = $db->prepare("SELECT DISTINCT province FROM user_footprints WHERE user_id = ? AND province != ''");
        $stmt->execute([$userId]);
        $rawProvinces = array_column($stmt->fetchAll(), 'province');
        // Normalize and deduplicate (handles legacy inconsistent data like "天津" vs "天津市")
        $provinces = array_values(array_unique(array_map('normalizeProvince', $rawProvinces)));

        $stmt = $db->prepare("SELECT id, city, province, country, ip, created_at FROM user_footprints WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
        $stmt->execute([$userId]);
        $records = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT COUNT(*) FROM user_footprints WHERE user_id = ?");
        $stmt->execute([$userId]);
        $totalVisits = (int)$stmt->fetchColumn();

        jsonResponse([
            'success' => true,
            'provinces' => $provinces,
            'records' => $records,
            'total_visits' => $totalVisits,
            'province_count' => count($provinces),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $province = normalizeProvince(trim($data['province'] ?? ''));
        $city = normalizeCity(trim($data['city'] ?? ''));
        $country = trim($data['country'] ?? '');
        $ip = trim($data['ip'] ?? '');
        $lng = isset($data['lng']) ? floatval($data['lng']) : null;
        $lat = isset($data['lat']) ? floatval($data['lat']) : null;
        $userId = $_SESSION['user_id'];

        $isNewProvince = false;
        if ($province !== '') {
            // Check if this is a new province (never seen before)
            $stmt = $db->prepare("SELECT DISTINCT province FROM user_footprints WHERE user_id = ? AND province != ''");
            $stmt->execute([$userId]);
            $existing = array_map('normalizeProvince', array_column($stmt->fetchAll(), 'province'));
            $isNewProvince = !in_array($province, $existing);

            // Avoid duplicates: skip if already recorded this province today
            $stmt = $db->prepare("SELECT COUNT(*) FROM user_footprints WHERE user_id = ? AND province = ? AND DATE(created_at) = CURDATE()");
            $stmt->execute([$userId, $province]);
            if ($stmt->fetchColumn() > 0) {
                jsonResponse(['success' => true, 'is_new_province' => false, 'province' => $province, 'message' => '今日已记录']);
            }
        }

        $stmt = $db->prepare("INSERT INTO user_footprints (user_id, ip, city, province, country, lng, lat) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $ip, $city, $province, $country, $lng, $lat]);

        jsonResponse([
            'success' => true,
            'is_new_province' => $isNewProvince,
            'province' => $province,
            'message' => '足迹已记录',
        ]);
    }

    jsonResponse(['success' => false, 'message' => '不支持的请求方法'], 405);
} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => '服务器错误'], 500);
}

function normalizeProvince(string $name): string {
    if ($name === '') return '';
    // Remove ethnic group suffixes, autonomous regions, SAR suffixes, province/city suffixes
    return preg_replace('/壮族|回族|维吾尔/', '', preg_replace('/自治区|特别行政区|省|市$/', '', $name));
}

function normalizeCity(string $name): string {
    if ($name === '') return '';
    return preg_replace('/市$/', '', $name);
}

function ensureFootprintsTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `user_footprints` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT UNSIGNED NOT NULL,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `city` VARCHAR(80) NOT NULL DEFAULT '',
            `province` VARCHAR(50) NOT NULL DEFAULT '',
            `country` VARCHAR(50) NOT NULL DEFAULT '',
            `lng` DECIMAL(10,6) DEFAULT NULL,
            `lat` DECIMAL(10,6) DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_user_province` (`user_id`, `province`),
            KEY `idx_user_created` (`user_id`, `created_at`),
            CONSTRAINT `fk_footprint_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
