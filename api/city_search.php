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
$city = trim($data['city'] ?? '');

if (!$city) {
    jsonResponse(['success' => false, 'message' => '请输入城市名称']);
}

$userPrefs = [];
try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT t.name FROM user_preference_tags upt
        JOIN preference_tags t ON t.id = upt.tag_id
        WHERE upt.user_id = ?
        LIMIT 30
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userPrefs = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$searchResult = callWebSearch($city . ' 著名景点 旅游推荐 必去');
$attractions = callAI($city, $searchResult, $userPrefs);

jsonResponse(['success' => true, 'attractions' => $attractions, 'city' => $city]);

function callWebSearch($query) {
    $ch = curl_init('http://localhost:3000/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'engine' => 'bing']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$response) return null;
    $d = json_decode($response, true);
    if (!$d) return null;
    $results = $d['results'] ?? $d['organic'] ?? $d['web'] ?? [];
    $text = '';
    foreach ($results as $r) {
        $title = $r['title'] ?? $r['name'] ?? '';
        $snippet = $r['snippet'] ?? $r['description'] ?? $r['body'] ?? '';
        if ($title || $snippet) $text .= $title . ': ' . $snippet . "\n";
    }
    return $text ?: null;
}

function callAI($city, $searchResults, $userPrefs) {
    $prefsText = empty($userPrefs) ? '无特定偏好' : implode('、', $userPrefs);
    $context = $searchResults
        ? "以下是关于{$city}景点的网络搜索结果：\n{$searchResults}\n\n"
        : '';
    $prompt = "{$context}请列出{$city}的著名旅游景点，根据用户偏好标签排序：{$prefsText}。\n\n"
        . "返回JSON数组，8-12个景点，每项格式：{\"name\":\"景点名\",\"description\":\"简介50字内\",\"address\":\"详细地址\",\"tags\":[\"标签\"]}。只返回JSON数组。";

    $ch = curl_init('https://token-plan-cn.xiaomimimo.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'mimo-v2-flash',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.3,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer MIMO_API_KEY_REMOVED',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return [];
    $d = json_decode($response, true);
    $content = $d['choices'][0]['message']['content'] ?? '';
    if (preg_match('/\[.*\]/s', $content, $m)) {
        $arr = json_decode($m[0], true);
        if (is_array($arr)) return $arr;
    }
    return [];
}
