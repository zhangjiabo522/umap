<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

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

// ── Step 1: DeepSeek generates attraction list ────────────────────────────────
sendEvt('status', ['step' => 1, 'message' => 'DeepSeek AI 正在推荐景点...']);
$aiAttractions = deepseekSearch($city, $userPrefs, $page);

if (empty($aiAttractions)) {
    sendEvt('error', ['message' => 'AI 推荐失败，请重试']);
    exit;
}

$total = count($aiAttractions);
sendEvt('status', ['step' => 1, 'message' => "AI 推荐了 {$total} 个景点", 'done' => true]);

// ── Step 2: AMap enrichment (parallel) ───────────────────────────────────────
sendEvt('status', ['step' => 2, 'message' => "正在从高德地图获取景点详情 (0/{$total})..."]);
$enriched = parallelAmapLookup($city, $aiAttractions, $total);
sendEvt('status', ['step' => 2, 'message' => '高德地图数据获取完成', 'done' => true]);

foreach ($enriched as $a) {
    sendEvt('attraction', $a);
}

$hasMore = ($total >= 10);
sendEvt('done', ['total' => count($enriched), 'hasMore' => $hasMore, 'nextPage' => $page + 1]);

// ── helpers ───────────────────────────────────────────────────────────────────

function sendEvt(string $type, $data): void {
    echo "event: {$type}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function deepseekSearch(string $city, array $userPrefs, int $page): array {
    $prefsText = empty($userPrefs) ? '无特定偏好' : implode('、', $userPrefs);
    $offset    = ($page - 1) * 10 + 1;
    $end       = $offset + 9;

    $prompt = "请推荐{$city}最值得游览的景点（第{$offset}到第{$end}个）。\n用户偏好标签：{$prefsText}\n\n要求：\n1. 优先推荐符合用户偏好的景点，不同页返回不同景点\n2. 返回10个景点\n3. 每个景点包含：name（景点全称，用于高德地图搜索）、description（50字内简介，突出特色和用户偏好匹配点）、tags（2-4个标签数组）\n4. 只返回JSON数组，不要任何其他文字或代码块标记\n\n示例：[{\"name\":\"故宫博物院\",\"description\":\"明清皇家宫殿，世界最大古建筑群之一\",\"tags\":[\"历史\",\"文化\",\"世界遗产\"]}]";

    $payload = json_encode([
        'model'            => 'deepseek-v4-flash',
        'messages'         => [
            ['role' => 'system', 'content' => '你是专业旅游顾问，熟悉中国各地景点。只返回JSON，不要任何解释。'],
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
            'Authorization: Bearer DEEPSEEK_API_KEY_REMOVED',
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
    return parseJson($content);
}

function parallelAmapLookup(string $city, array $aiAttractions, int $total): array {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($aiAttractions as $i => $ai) {
        $url = 'https://restapi.amap.com/v3/place/text?' . http_build_query([
            'key'        => 'AMAP_KEY_REMOVED',
            'keywords'   => $ai['name'],
            'city'       => $city,
            'offset'     => 1,
            'page'       => 1,
            'extensions' => 'all',
            'output'     => 'json',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['ch' => $ch, 'ai' => $ai, 'done' => false];
    }

    $running   = null;
    $completed = 0;

    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);

        while ($info = curl_multi_info_read($mh)) {
            if ($info['msg'] !== CURLMSG_DONE) continue;
            foreach ($handles as $i => &$h) {
                if ($h['ch'] === $info['handle'] && !$h['done']) {
                    $h['done'] = true;
                    $completed++;
                    sendEvt('status', ['step' => 2, 'message' => "正在从高德地图获取景点详情 ({$completed}/{$total})..."]);
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
            $d    = json_decode($resp, true);
            $pois = $d['pois'] ?? [];
            if (!empty($pois)) $poi = $pois[0];
        }

        $ai      = $h['ai'];
        $address = '';
        $rating  = '';
        $location = '';
        if ($poi) {
            $address  = $poi['address'] ?? (($poi['pname'] ?? '') . ($poi['cityname'] ?? '') . ($poi['adname'] ?? ''));
            $rating   = $poi['biz_ext']['rating'] ?? '';
            $location = $poi['location'] ?? '';
        }

        $results[$i] = [
            'name'        => $ai['name'],
            'description' => $ai['description'] ?? '',
            'tags'        => $ai['tags'] ?? [],
            'address'     => $address,
            'rating'      => $rating,
            'location'    => $location,
        ];
    }

    curl_multi_close($mh);
    ksort($results);
    return array_values($results);
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
