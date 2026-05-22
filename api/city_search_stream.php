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
define('AMAP_KEY',     $_ENV['AMAP_KEY'] ?? '');

set_time_limit(120);
if (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

session_start();
if (empty($_SESSION['user_id'])) { sendEvt('error', ['message' => '未登录']); exit; }

$data = json_decode(file_get_contents('php://input'), true);
$city = trim($data['city'] ?? '');
$page = max(1, intval($data['page'] ?? 1));

if (!$city) { sendEvt('error', ['message' => '请输入城市名称']); exit; }

$userPrefs = [];
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT t.name FROM user_preference_tags upt JOIN preference_tags t ON t.id = upt.tag_id WHERE upt.user_id = ? LIMIT 30");
    $stmt->execute([$_SESSION['user_id']]);
    $userPrefs = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Step 1: AMap 广搜候选景点 ─────────────────────────────────────────────────
sendEvt('status', ['step' => 1, 'message' => '正在从高德地图搜索景点...']);
$pois = amapSearch($city, $page);

if (empty($pois)) {
    sendEvt('error', ['message' => '高德地图未找到景点，请尝试其他城市名称']);
    exit;
}

$total = count($pois);
sendEvt('status', ['step' => 1, 'message' => "找到 {$total} 个候选景点", 'done' => true]);

// ── Step 2: DeepSeek 按偏好筛选 + 置信度排序 ──────────────────────────────────
sendEvt('status', ['step' => 2, 'message' => 'DeepSeek AI 正在根据偏好筛选景点...']);
$filtered = deepseekFilter($city, $pois, $userPrefs);
sendEvt('status', ['step' => 2, 'message' => '筛选完成，共 ' . count($filtered) . ' 个匹配景点', 'done' => true]);

foreach ($filtered as $a) {
    sendEvt('attraction', $a);
}

$hasMore = ($total >= 20);
sendEvt('done', ['total' => count($filtered), 'hasMore' => $hasMore, 'nextPage' => $page + 1]);

// ── helpers ───────────────────────────────────────────────────────────────────

function sendEvt(string $type, $data): void {
    echo "event: {$type}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function amapSearch(string $city, int $page): array {
    $url = 'https://restapi.amap.com/v3/place/text?' . http_build_query([
        'key'        => AMAP_KEY,
        'keywords'   => '景点',
        'city'       => $city,
        'types'      => '110000',
        'offset'     => 20,
        'page'       => $page,
        'extensions' => 'all',
        'output'     => 'json',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    if (($d['status'] ?? '') !== '1') return [];
    return $d['pois'] ?? [];
}

function deepseekFilter(string $city, array $pois, array $userPrefs): array {
    $prefsText = empty($userPrefs) ? '无特定偏好（返回所有景点，置信度按综合热度排序）' : implode('、', $userPrefs);

    // 构建景点列表供 AI 分析
    $poiLines = [];
    foreach ($pois as $i => $poi) {
        $rating  = $poi['biz_ext']['rating'] ?? '';
        $type    = $poi['type'] ?? '';
        $address = $poi['address'] ?? (($poi['pname'] ?? '') . ($poi['cityname'] ?? '') . ($poi['adname'] ?? ''));
        $loc     = $poi['location'] ?? '';
        $poiLines[] = ($i + 1) . ". 【{$poi['name']}】类型:{$type} 地址:{$address} 评分:{$rating} 坐标:{$loc}";
    }
    $poiText = implode("\n", $poiLines);

    $prompt = <<<PROMPT
用户的喜好标签：{$prefsText}

请你根据用户喜好对以下{$city}景点进行筛选和排序，只返回用户可能喜欢的景点：

{$poiText}

筛选规则：
1. 谨慎筛选——只保留与用户偏好标签高度或中度匹配的景点
2. 将用户明显不感兴趣的景点完全排除，不出现在返回列表中
3. 对保留的景点按 confidence 从高到低排序
4. confidence 表示该景点与用户偏好的匹配置信度（0.01~1.00），越高越匹配
5. 用户不想看到的景点绝对不出现在返回列表中
6. 只返回 JSON 数组，不要任何其他文字或代码块标记

返回格式（严格遵守）：
[{"name":"景点名","description":"50字内简介，突出与用户偏好的匹配点","address":"详细地址","tags":["标签1","标签2"],"rating":"评分（原样保留，无则空字符串）","location":"经度,纬度（原样保留，无则空字符串）","confidence":0.95}]
PROMPT;

    $payload = json_encode([
        'model'            => 'deepseek-v4-flash',
        'messages'         => [
            ['role' => 'system', 'content' => '你是专业旅游顾问。严格按用户偏好筛选景点，只返回JSON数组，不输出任何解释。'],
            ['role' => 'user',   'content' => $prompt],
        ],
        'thinking'         => ['type' => 'enabled'],
        'reasoning_effort' => 'high',
        'stream'           => false,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return [];
    $d = json_decode($resp, true);
    if (isset($d['error']) || empty($d['choices'])) return [];
    $content = $d['choices'][0]['message']['content'] ?? '';
    $result  = parseJson($content);

    // 按 confidence 降序排列
    usort($result, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
    return $result;
}

function parseJson(string $content): array {
    if (!$content) return [];
    $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = preg_replace('/\s*```$/m', '', $content);
    $content = trim($content);
    if (preg_match('/\[.*\]/s', $content, $m)) {
        $arr = json_decode($m[0], true);
        if (is_array($arr)) return $arr;
    }
    return [];
}
