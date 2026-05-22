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

// ── Step 1: AMap ──────────────────────────────────────────────────────────────
sendEvt('status', ['step' => 1, 'message' => '正在搜索高德地图景点...']);
$pois = amapSearch($city, $page);

if (empty($pois)) {
    sendEvt('error', ['message' => '高德地图未找到景点，请尝试其他城市名称']);
    exit;
}

$total = count($pois);
sendEvt('status', ['step' => 1, 'message' => "高德地图找到 {$total} 个景点", 'done' => true]);

// ── Step 2: Parallel web search ───────────────────────────────────────────────
sendEvt('status', ['step' => 2, 'message' => "正在搜索景点评价 (0/{$total})..."]);
$searchResults = parallelSearch($city, $pois, $total);

sendEvt('status', ['step' => 2, 'message' => "景点评价搜索完成", 'done' => true]);

// ── Step 3: AI analysis ───────────────────────────────────────────────────────
sendEvt('status', ['step' => 3, 'message' => 'AI 正在分析景点匹配度...']);
$attractions = aiAnalyze($city, $pois, $searchResults, $userPrefs);
sendEvt('status', ['step' => 3, 'message' => 'AI 分析完成', 'done' => true]);

foreach ($attractions as $a) {
    sendEvt('attraction', $a);
}

$hasMore = ($total >= 10);
sendEvt('done', ['total' => count($attractions), 'hasMore' => $hasMore, 'nextPage' => $page + 1]);

// ── helpers ───────────────────────────────────────────────────────────────────

function sendEvt(string $type, $data): void {
    echo "event: {$type}\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function amapSearch(string $city, int $page): array {
    $url = 'https://restapi.amap.com/v3/place/text?' . http_build_query([
        'key'        => 'AMAP_KEY_REMOVED',
        'keywords'   => '景点',
        'city'       => $city,
        'types'      => '110000',
        'offset'     => 10,
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

function parallelSearch(string $city, array $pois, int $total): array {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($pois as $i => $poi) {
        $q = "{$city} {$poi['name']} 景点评价 特色";
        $ch = curl_init('http://localhost:3000/search');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['query' => $q, 'engine' => 'bing', 'limit' => 5, 'searchMode' => 'playwright']),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['ch' => $ch, 'name' => $poi['name'], 'done' => false];
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
                    sendEvt('status', ['step' => 2, 'message' => "正在搜索景点评价 ({$completed}/{$total})..."]);
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
        if ($resp) {
            $d     = json_decode($resp, true);
            $items = $d['data']['results'] ?? [];
            $lines = [];
            foreach ($items as $r) {
                $t = $r['title'] ?? '';
                $s = $r['description'] ?? $r['snippet'] ?? '';
                if ($t || $s) $lines[] = "- {$t}: {$s}";
            }
            $results[$i] = implode("\n", $lines);
        } else {
            $results[$i] = '';
        }
    }
    curl_multi_close($mh);
    return $results;
}

function aiAnalyze(string $city, array $pois, array $searchResults, array $userPrefs): array {
    $prefsText = empty($userPrefs) ? '无特定偏好' : implode('、', $userPrefs);
    $poisText  = '';
    foreach ($pois as $i => $poi) {
        $rating   = $poi['biz_ext']['rating'] ?? '';
        $poisText .= "\n【{$poi['name']}】\n地址: " . ($poi['address'] ?? '') . "\n类型: " . ($poi['type'] ?? '') . "\n评分: " . ($rating ?: '暂无');
        if (!empty($searchResults[$i])) $poisText .= "\n网络评价:\n{$searchResults[$i]}";
        $poisText .= "\n";
    }

    $prompt = "以下是{$city}的景点信息和网络评价：\n{$poisText}\n用户偏好标签：{$prefsText}\n\n请根据用户偏好筛选并排序景点，返回最适合该用户的景点列表。\n要求：\n1. 优先推荐符合用户偏好的景点\n2. 每个景点包含：name、description（基于评价的50字内简介）、address、tags（2-4个）、rating（如有）\n3. 只返回JSON数组，不要任何其他文字或代码块标记\n示例：[{\"name\":\"故宫\",\"description\":\"明清皇宫\",\"address\":\"北京市东城区\",\"tags\":[\"历史\"],\"rating\":\"4.9\"}]";

    $result = callMimo($prompt);
    if (!empty($result)) return $result;
    return callClaude($prompt);
}

function callMimo(string $prompt): array {
    $ch = curl_init('https://token-plan-cn.xiaomimimo.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model' => 'mimo-v2-flash', 'max_tokens' => 2000, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.3]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer MIMO_API_KEY_REMOVED'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    if (isset($d['error']) || empty($d['choices'])) return [];
    return parseJson($d['choices'][0]['message']['content'] ?? '');
}

function callClaude(string $prompt): array {
    $ch = curl_init('https://plusbackend.654301.xyz/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['model' => 'claude-sonnet-4-6', 'max_tokens' => 2000, 'messages' => [['role' => 'user', 'content' => $prompt]]]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ANTHROPIC_API_KEY_REMOVED', 'anthropic-version: 2023-06-01'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return [];
    $d = json_decode($resp, true);
    return parseJson($d['content'][0]['text'] ?? '');
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
