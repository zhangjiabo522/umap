<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$tagIds = $input['tag_ids'] ?? [];
$tier = $input['tier'] ?? 'quick';

if (!is_array($tagIds)) {
    jsonResponse(['success' => false, 'message' => 'Invalid data']);
}

$limits = ['quick' => 10, 'medium' => 20, 'full' => 30];
$minRequired = $limits[$tier] ?? 10;

if (count($tagIds) < $minRequired) {
    jsonResponse(['success' => false, 'message' => "至少需要选择 {$minRequired} 个标签"]);
}

try {
    $db = getDB();

    // Validate no opposite tags are both selected
    if (!empty($tagIds)) {
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $stmt = $db->prepare("
            SELECT t1.id, t1.name, t2.name as opposite_name
            FROM travel_tags t1
            JOIN travel_tags t2 ON t2.id = t1.opposite_id
            WHERE t1.id IN ($placeholders) AND t2.id IN ($placeholders)
        ");
        $stmt->execute(array_merge($tagIds, $tagIds));
        $conflict = $stmt->fetch();

        if ($conflict) {
            jsonResponse([
                'success' => false,
                'message' => "「{$conflict['name']}」和「{$conflict['opposite_name']}」是互斥标签，不能同时选择",
            ]);
        }
    }

    $db->beginTransaction();

    // Clear previous selections
    $stmt = $db->prepare("DELETE FROM user_preferences WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);

    // Insert new selections
    $stmt = $db->prepare("INSERT INTO user_preferences (user_id, tag_id) VALUES (?, ?)");
    foreach ($tagIds as $tagId) {
        $stmt->execute([$_SESSION['user_id'], $tagId]);
    }

    $db->commit();

    jsonResponse(['success' => true, 'message' => '偏好保存成功', 'count' => count($tagIds)]);

} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse(['success' => false, 'message' => '保存失败，请稍后重试'], 500);
}
