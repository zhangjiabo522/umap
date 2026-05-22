<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

$tier = $_GET['tier'] ?? 'quick';
$limits = ['quick' => 25, 'medium' => 50, 'full' => 100];
$limit = $limits[$tier] ?? 100;

try {
    $db = getDB();

    // Get tags ordered by sort_order, limited to the requested tier
    $stmt = $db->prepare("
        SELECT id, category, name, opposite_id, sort_order, tier
        FROM travel_tags
        ORDER BY sort_order ASC
    ");
    $stmt->execute();
    $allTags = $stmt->fetchAll();

    // For quick and medium, take top N tags but ensure pairs are complete
    if ($tier !== 'full') {
        $selectedIds = [];
        $selectedNames = [];
        $extraIds = []; // opposite tags beyond the limit

        foreach ($allTags as $i => $tag) {
            if ($i < $limit) {
                $selectedIds[$tag['id']] = true;
                $selectedNames[] = $tag['id'];
            } else if (isset($tag['opposite_id']) && isset($selectedIds[$tag['opposite_id']])) {
                // Include opposite tag even if beyond limit
                $extraIds[] = $tag['id'];
            }
        }

        $tags = [];
        foreach ($allTags as $tag) {
            if (isset($selectedIds[$tag['id']]) || in_array($tag['id'], $extraIds)) {
                $tags[] = $tag;
            }
        }
    } else {
        $tags = array_slice($allTags, 0, 100);
    }

    // Get user's current selections
    $userStmt = $db->prepare("SELECT tag_id FROM user_preferences WHERE user_id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $selectedTagIds = $userStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get user's custom tags
    $customStmt = $db->prepare("SELECT id, name FROM user_custom_tags WHERE user_id = ?");
    $customStmt->execute([$_SESSION['user_id']]);
    $customTags = $customStmt->fetchAll();

    // Group tags by category
    $grouped = [];
    foreach ($tags as $tag) {
        $cat = $tag['category'];
        if (!isset($grouped[$cat])) $grouped[$cat] = [];
        unset($tag['sort_order'], $tag['tier']);
        $tag['selected'] = in_array($tag['id'], $selectedTagIds);
        $tag['opposite_selected'] = false;
        if ($tag['opposite_id']) {
            $tag['opposite_selected'] = in_array($tag['opposite_id'], $selectedTagIds);
        }
        $grouped[$cat][] = $tag;
    }

    jsonResponse([
        'success' => true,
        'categories' => $grouped,
        'selected_count' => count($selectedTagIds),
        'selected_ids' => $selectedTagIds,
        'custom_tags' => $customTags,
        'tier' => $tier,
        'total_available' => count($tags),
    ]);

} catch (PDOException $e) {
    jsonResponse(['success' => false, 'message' => 'Server error'], 500);
}
