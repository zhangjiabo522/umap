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

// ── Step 1: DeepSeek 搜索候选景点 ─────────────────────────────────────────────
sendEvt('status', ['step' => 1, 'message' => 'AI 正在分析喜好并搜索景点...']);
$candidates = deepseekSearchAttractions($city, $userPrefs, $page);

if (empty($candidates)) {
    sendEvt('error', ['message' => 'AI 景点分析失败，请重试']);
    exit;
}

$total = count($candidates);
sendEvt('status', ['step' => 1, 'message' => "AI 已筛选出 {$total} 个候选景点", 'done' => true]);

// ── Step 2: AMap 补充坐标和评分 ──────────────────────────────────────────────
sendEvt('status', ['step' => 2, 'message' => "正在从高德地图补充详情 (0/{$total})..."]);
$enriched = parallelAmapLookup($city, $candidates, $total);
sendEvt('status', ['step' => 2, 'message' => '高德地图详情补充完成', 'done' => true]);

foreach ($enriched as $a) {
    sendEvt('attraction', $a);
}

$hasMore = ($total >= 12);
sendEvt('done', ['total' => count($enriched), 'hasMore' => $hasMore, 'nextPage' => $page + 1]);

// ── helpers ───────────────────────────────────────────────────────────────────

function sendEvt(string $type, $data): void {
    echo "event: {$type}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function deepseekSearchAttractions(string $city, array $userPrefs, int $page): array {
    $prefsText = empty($userPrefs) ? '无特定偏好（推荐该城市综合热度高、适合大多数游客的景点）' : implode('、', $userPrefs);
    $offset = ($page - 1) * 12 + 1;
    $end = $offset + 11;

    $prompt = <<<PROMPT
用户的喜好标签：{$prefsText}

请你搜索并推荐{$city}的景点（第{$offset}到第{$end}个），并根据用户喜好对景点分类，只返回用户可能喜欢的景点。

筛选规则：
1. 谨慎筛选，筛掉用户可能不喜欢的景点
2. 用户不想看的景点不要出现在返回列表
3. 按 confidence 从高到低排序
4. confidence 表示用户可能喜欢该景点的置信度，范围 0.01 到 1.00
5. 不同页尽量返回不同景点
6. 每次最多返回12个景点
7. 只返回 JSON 数组，不要任何其他文字或代码块标记

返回格式：
[{"name":"景点名","description":"50字内简介，突出与用户偏好的匹配点","tags":["标签1","标签2"],"confidence":0.95}]
PROMPT;

    $result = callDeepSeek($prompt, '你是专业旅游顾问。严格按用户偏好搜索并筛选景点，只返回JSON数组，不输出任何解释。');
    usort($result, fn($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
    return $result;
}

function parallelAmapLookup(string $city, array $candidates, int $total): array {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($candidates as $i => $candidate) {
        $url = 'https://restapi.amap.com/v3/place/text?' . http_build_query([
            'key'        => AMAP_KEY,
            'keywords'   => $candidate['name'] ?? '',
            'city'       => $city,
            'offset'     => 1,
            'page'       => 1,
            'extensions' => 'all',
            'output'     => 'json',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['ch' => $ch, 'candidate' => $candidate, 'done' => false];
    }

    $running = null;
    $completed = 0;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
        while ($info = curl_multi_info_read($mh)) {
            if ($info['msg'] !== CURLMSG_DONE) continue;
            foreach ($handles as &$h) {
                if ($h['ch'] === $info['handle'] && !$h['done']) {
                    $h['done'] = true;
                    $completed++;
                    sendEvt('status', ['step' => 2, 'message' => "正在从高德地图补充详情 ({$completed}/{$total})..."]);
                    break;
                }
            }
            unset($h);
        }
    } while ($running > 0);

    $results = [];
    foreach ($handles as $i => $h) {
        $resp = curl_multi_getcontent($h['ch']);
        curl_multi_remove_handle($mh, $h['ch']);
        curl_close($h['ch']);

        $poi = null;
        if ($resp) {
            $d = json_decode($resp, true);
            $pois = $d['pois'] ?? [];
            if (!empty($pois)) $poi = $pois[0];
        }

        $candidate = $h['candidate'];
        $address = '';
        $rating = '';
        $location = '';
        if ($poi) {
            $address = $poi['address'] ?? (($poi['pname'] ?? '') . ($poi['cityname'] ?? '') . ($poi['adname'] ?? ''));
            $rating = $poi['biz_ext']['rating'] ?? '';
            $location = $poi['location'] ?? '';
        }

        $results[$i] = [
            'name' => $candidate['name'] ?? '',
            'description' => $candidate['description'] ?? '',
            'tags' => $candidate['tags'] ?? [],
            'confidence' => $candidate['confidence'] ?? 0,
            'address' => $address,
            'rating' => $rating,
            'location' => $location,
        ];
    }
    curl_multi_close($mh);
    ksort($results);
    return array_values($results);
}

function callDeepSeek(string $prompt, string $system): array {
    $payload = json_encode([
        'model' => 'deepseek-v4-flash',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ],
        'thinking' => ['type' => 'enabled'],
        'reasoning_effort' => 'high',
        'stream' => false,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_KEY,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return [];
    $d = json_decode($resp, true);
    if (isset($d['error']) || empty($d['choices'])) return [];
    return parseJson($d['choices'][0]['message']['content'] ?? '');
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
