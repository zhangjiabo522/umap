<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$_envFile = __DIR__ . '/../.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (strpos(trim($_line), '#') === 0) continue;
        [$_k, $_v] = array_map('trim', explode('=', $_line, 2));
        $_ENV[$_k] = $_v;
    }
}
define('DEEPSEEK_KEY', $_ENV['DEEPSEEK_API_KEY'] ?? '');

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

$attractions = callAI($city, $userPrefs);

jsonResponse(['success' => true, 'attractions' => $attractions, 'city' => $city]);

function callAI($city, $userPrefs) {
    $prefsText = empty($userPrefs) ? '无特定偏好' : implode('、', $userPrefs);
    $prompt = <<<PROMPT
用户的喜好标签：{$prefsText}

请你推荐{$city}的景点，并根据用户喜好对景点分类，只返回用户可能喜欢的景点。

筛选规则：
1. 谨慎筛选，筛掉用户可能不喜欢的景点
2. 用户不想看的景点不要出现在返回列表
3. 按 confidence 从高到低排序
4. confidence 表示用户可能喜欢该景点的置信度，范围 0.01 到 1.00
5. 只返回 JSON 数组，不要任何其他文字或代码块标记

返回格式：
[{"name":"景点名","description":"50字内简介，突出与用户偏好的匹配点","address":"详细地址","tags":["标签1","标签2"],"confidence":0.95}]
PROMPT;

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'deepseek-v4-flash',
            'messages' => [
                ['role' => 'system', 'content' => '你是专业旅游顾问。严格按用户偏好筛选景点，只返回JSON数组，不输出任何解释。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'thinking' => ['type' => 'enabled'],
            'reasoning_effort' => 'high',
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    if (!$response) return [];
    $d = json_decode($response, true);
    $content = $d['choices'][0]['message']['content'] ?? '';
    if (preg_match('/\[.*\]/s', $content, $m)) {
        $arr = json_decode($m[0], true);
        if (is_array($arr)) {
            usort($arr, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
            return $arr;
        }
    }
    return [];
}
