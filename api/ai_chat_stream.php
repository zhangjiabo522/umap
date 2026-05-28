<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

loadEnv(__DIR__ . '/../.env');

define('MIMO_API_KEY', $_ENV['MIMO_API_KEY'] ?? '');
define('MIMO_BASE_URL', rtrim($_ENV['MIMO_BASE_URL'] ?? 'https://api.xiaomimimo.com/v1', '/'));
define('MIMO_MODEL', $_ENV['MIMO_MODEL'] ?? 'mimo-v2.5-pro');
define('MIMO_VISION_MODEL', $_ENV['MIMO_VISION_MODEL'] ?? 'mimo-v2.5');

set_time_limit(180);
ignore_user_abort(true);
if (ob_get_level()) ob_end_clean();

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
corsHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendEvt('error', ['message' => '不支持的请求方法']); exit; }

session_start();
if (empty($_SESSION['user_id'])) { sendEvt('error', ['message' => '未登录']); exit; }
if (!MIMO_API_KEY) { sendEvt('error', ['message' => 'AI 服务未配置']); exit; }

try {
    $contentLength = intval($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 50 * 1024 * 1024) {
        sendEvt('error', ['message' => '上传内容过大，请压缩后再试']);
        exit;
    }

    $message = trim($_POST['message'] ?? '');
    $favorite = parseFavorite($_POST['favorite'] ?? '');
    $attachments = normalizeUploadedFiles($_FILES['attachments'] ?? null);
    $sessionId = intval($_POST['session_id'] ?? 0);
    $searchResults = trim($_POST['search_results'] ?? '');
    $userLng = floatval($_POST['user_lng'] ?? 0);
    $userLat = floatval($_POST['user_lat'] ?? 0);
    $searchResultsData = [];
    $rawJson = $_POST['search_results_json'] ?? '';
    if ($rawJson !== '') {
        $decoded = json_decode($rawJson, true);
        if (is_array($decoded)) $searchResultsData = $decoded;
    }
    $isContinue = ($_POST['continue'] ?? '0') === '1';
    $prevToolResults = trim($_POST['previous_tool_results'] ?? '');

    if (!$isContinue && $message === '' && empty($attachments)) {
        sendEvt('error', ['message' => '请输入问题或上传文件']);
        exit;
    }

    $db = getDB();
    $userId = intval($_SESSION['user_id']);
    ensureChatTables($db);

    // Create session if not provided (only for new conversations)
    if ($sessionId <= 0) {
        $title = mb_substr($message ?: '工具调用', 0, 20);
        $stmt = $db->prepare("INSERT INTO ai_chat_sessions (user_id, title) VALUES (?, ?)");
        $stmt->execute([$userId, $title]);
        $sessionId = intval($db->lastInsertId());
        sendEvt('session', ['session_id' => $sessionId, 'title' => $title]);
    }

    // Save user message (skip for continue mode - tool calls are system-orchestrated)
    if (!$isContinue) {
        $attachJson = empty($attachments) ? null : json_encode(array_map(fn($a) => ['name' => $a['name'], 'type' => $a['type']], $attachments), JSON_UNESCAPED_UNICODE);
        $favJson = empty($favorite) ? null : json_encode($favorite, JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare("INSERT INTO ai_chat_messages (session_id, role, content, favorite, attachments) VALUES (?, 'user', ?, ?, ?)");
        $stmt->execute([$sessionId, $message ?: '(上传文件)', $favJson, $attachJson]);
    }

    sendEvt('status', ['message' => '正在校验上传内容...']);
    $mediaItems = buildMediaItems($attachments);

    sendEvt('status', ['message' => '正在读取你的旅行画像...']);
    $profile = loadUserProfile($db, $userId);

    // Load conversation history (last 10 rounds)
    $historyMessages = loadHistoryMessages($db, $sessionId, 10);

    $text = $isContinue
        ? "以下是工具调用返回的实时数据，请基于这些数据继续回答用户的问题：\n\n{$prevToolResults}"
        : buildUserText($message, $favorite, $mediaItems, $searchResults);

    $mimoMessages = [['role' => 'system', 'content' => buildSystemPrompt($profile, $userLng, $userLat)]];
    foreach ($historyMessages as $hm) {
        $mimoMessages[] = ['role' => $hm['role'] === 'user' ? 'user' : 'assistant', 'content' => $hm['content']];
    }
    // Replace last user message with current (unless continue mode uses all history)
    if (!$isContinue && !empty($historyMessages)) array_pop($mimoMessages);
    $mimoMessages[] = ['role' => 'user', 'content' => $text];

    $payload = [
        'model' => MIMO_MODEL,
        'messages' => $mimoMessages,
        'max_completion_tokens' => 16384,
        'stream' => true,
    ];

    sendEvt('status', ['message' => 'AI 正在分析并生成回答...']);

    // Pre-insert empty assistant message to get ID for incremental saves
    $searchResultsJson = !empty($searchResultsData) ? json_encode($searchResultsData, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $db->prepare("INSERT INTO ai_chat_messages (session_id, role, content, think, search_results, is_continuation) VALUES (?, 'assistant', '', NULL, ?, ?)");
    $stmt->execute([$sessionId, $searchResultsJson, $isContinue ? 1 : 0]);
    $assistantMsgId = intval($db->lastInsertId());

    // Incremental save callback
    $saveDb = $db;
    $saveMsgId = $assistantMsgId;
    $latestThink = '';
    $latestContent = '';
    $finalSaved = false;

    // Fast incremental save: write to temp file (microseconds) instead of DB (10-50ms)
    $tmpFile = sys_get_temp_dir() . '/umap_stream_' . $assistantMsgId . '.tmp';
    $onProgress = function (string $think, string $content) use ($tmpFile, &$latestThink, &$latestContent) {
        $latestThink = $think;
        $latestContent = $content;
        file_put_contents($tmpFile, json_encode([$think, $content], JSON_UNESCAPED_UNICODE), LOCK_EX);
    };

    // Shutdown function: guarantee final save even on unexpected termination
    register_shutdown_function(function () use ($saveDb, $saveMsgId, $tmpFile, &$latestThink, &$latestContent, &$finalSaved) {
        if ($finalSaved) return;
        // Try to recover from temp file first
        if (file_exists($tmpFile)) {
            $recovered = json_decode(file_get_contents($tmpFile), true);
            if ($recovered) { $latestThink = $recovered[0]; $latestContent = $recovered[1]; }
        }
        try {
            if ($latestContent !== '') {
                $stmt = $saveDb->prepare("UPDATE ai_chat_messages SET content = ?, think = ? WHERE id = ?");
                $stmt->execute([$latestContent, $latestThink ?: null, $saveMsgId]);
            } else {
                $saveDb->prepare("DELETE FROM ai_chat_messages WHERE id = ?")->execute([$saveMsgId]);
            }
        } catch (Throwable $e) {
            error_log('Shutdown save error: ' . $e->getMessage());
        }
        @unlink($tmpFile);
    });

    $fullThink = '';
    $fullContent = '';
    $streamResult = callMimo($payload, true, $fullThink, $fullContent, $onProgress);
    if (!$streamResult['ok'] || !$streamResult['emitted']) {
        $payload['stream'] = false;
        $result = callMimoNonStream($payload);
        $fullThink = $result['think'];
        $fullContent = $result['content'];
        if (!empty($fullThink)) sendEvt('think', ['text' => $fullThink]);
        if ($fullContent === '') {
            // Remove the empty assistant message on failure
            $db->prepare("DELETE FROM ai_chat_messages WHERE id = ?")->execute([$assistantMsgId]);
            sendEvt('error', ['message' => 'AI 暂时没有返回内容，请稍后重试']);
            exit;
        }
        streamText($fullContent);
    }

    // Final save with complete content
    $stmt = $db->prepare("UPDATE ai_chat_messages SET content = ?, think = ? WHERE id = ?");
    $stmt->execute([$fullContent, $fullThink ?: null, $assistantMsgId]);
    $finalSaved = true;
    @unlink($tmpFile);

    // Save tool results to the previous assistant message if this is a continuation
    if ($isContinue && $prevToolResults !== '') {
        $prevStmt = $db->prepare("SELECT id FROM ai_chat_messages WHERE session_id = ? AND role = 'assistant' AND is_continuation = 0 ORDER BY id DESC LIMIT 1");
        $prevStmt->execute([$sessionId]);
        $prevMsgId = $prevStmt->fetchColumn();
        if ($prevMsgId) {
            $toolJson = json_decode($prevToolResults, true) ?: json_decode('[' . $prevToolResults . ']', true);
            $db->prepare("UPDATE ai_chat_messages SET tool_results = ? WHERE id = ?")->execute([json_encode($toolJson, JSON_UNESCAPED_UNICODE), $prevMsgId]);
        }
    }

    // Extract and update userlike
    updateUserLikeFromResponse($db, $userId, $fullContent);

    // Auto-generate title after first exchange
    $msgCount = $db->prepare("SELECT COUNT(*) FROM ai_chat_messages WHERE session_id = ?");
    $msgCount->execute([$sessionId]);
    if ($msgCount->fetchColumn() <= 2) {
        generateSessionTitle($db, $sessionId, $message ?: '图片分析');
    }

    sendEvt('done', ['message' => '完成', 'session_id' => $sessionId]);
} catch (RuntimeException $e) {
    sendEvt('error', ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Mimo AI error: ' . $e->getMessage());
    sendEvt('error', ['message' => 'AI 分析失败，请稍后重试']);
}

function sendEvt(string $type, array $data): void {
    echo "event: {$type}\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

function parseFavorite(string $raw): array {
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalizeUploadedFiles($files): array {
    if (!$files || empty($files['name'])) return [];
    $normalized = [];
    if (is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => intval($files['size'][$i] ?? 0),
            ];
        }
    } else {
        $normalized[] = $files;
    }
    return array_values(array_filter($normalized, fn($file) => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function buildMediaItems(array $files): array {
    if (empty($files)) return [];
    if (count($files) > 3) throw new RuntimeException('最多上传 3 个文件');
    if (!function_exists('finfo_open')) throw new RuntimeException('服务器暂不支持文件类型识别');

    $allowed = [
        'image/jpeg' => ['kind' => 'image', 'max' => 15 * 1024 * 1024],
        'image/png' => ['kind' => 'image', 'max' => 15 * 1024 * 1024],
        'image/gif' => ['kind' => 'image', 'max' => 15 * 1024 * 1024],
        'image/webp' => ['kind' => 'image', 'max' => 15 * 1024 * 1024],
        'image/bmp' => ['kind' => 'image', 'max' => 15 * 1024 * 1024],
        'image/x-ms-bmp' => ['kind' => 'image', 'max' => 15 * 1024 * 1024],
        'audio/mpeg' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/wav' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/x-wav' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/flac' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/x-flac' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/mp4' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/x-m4a' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/ogg' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'audio/webm' => ['kind' => 'audio', 'max' => 20 * 1024 * 1024],
        'video/mp4' => ['kind' => 'video', 'max' => 30 * 1024 * 1024],
        'video/quicktime' => ['kind' => 'video', 'max' => 30 * 1024 * 1024],
        'video/x-msvideo' => ['kind' => 'video', 'max' => 30 * 1024 * 1024],
        'video/x-ms-wmv' => ['kind' => 'video', 'max' => 30 * 1024 * 1024],
    ];

    $total = 0;
    $items = [];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('文件上传失败');
        if (!is_uploaded_file($file['tmp_name'] ?? '')) throw new RuntimeException('上传文件无效');
        $mime = finfo_file($finfo, $file['tmp_name']);
        if (!isset($allowed[$mime])) throw new RuntimeException('不支持的文件类型');
        $size = intval($file['size'] ?? 0);
        if ($size <= 0 || $size > $allowed[$mime]['max']) throw new RuntimeException('文件大小超出限制');
        $total += $size;
        if ($total > 40 * 1024 * 1024) throw new RuntimeException('上传文件总大小超出限制');
        $raw = file_get_contents($file['tmp_name']);
        if ($raw === false) throw new RuntimeException('读取上传文件失败');
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($raw);
        $kind = $allowed[$mime]['kind'];
        if ($kind === 'image') {
            $items[] = ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]];
        } elseif ($kind === 'audio') {
            $items[] = ['type' => 'input_audio', 'input_audio' => ['data' => $dataUrl]];
        } else {
            $items[] = ['type' => 'video_url', 'video_url' => ['url' => $dataUrl], 'fps' => 2, 'media_resolution' => 'default'];
        }
    }
    if ($finfo) finfo_close($finfo);
    return $items;
}

function loadUserProfile(PDO $db, int $userId): array {
    $profile = ['prefs' => [], 'favorites' => [], 'likes' => [], 'dislikes' => [], 'userlike' => ''];

    // Load userlike
    try {
        $stmt = $db->prepare('SELECT userlike FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        $profile['userlike'] = $row['userlike'] ?? '';
    } catch (Throwable $e) {
        // Column may not exist yet
    }

    try {
        $stmt = $db->prepare('SELECT tt.name FROM user_preferences up JOIN travel_tags tt ON tt.id = up.tag_id WHERE up.user_id = ? LIMIT 50');
        $stmt->execute([$userId]);
        $profile['prefs'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        try {
            $stmt = $db->prepare('SELECT t.name FROM user_preference_tags upt JOIN preference_tags t ON t.id = upt.tag_id WHERE upt.user_id = ? LIMIT 50');
            $stmt->execute([$userId]);
            $profile['prefs'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e2) {}
    }

    try {
        ensureFavoritesTable($db);
        $stmt = $db->prepare('SELECT name, city, address, tags FROM user_favorites WHERE user_id = ? ORDER BY created_at DESC LIMIT 80');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $profile['favorites'][] = formatProfileItem($row);
        }
    } catch (Throwable $e) {}

    try {
        ensureFeedbackTable($db);
        $stmt = $db->prepare('SELECT name, city, address, tags, feedback_type FROM user_attraction_feedback WHERE user_id = ? ORDER BY created_at DESC LIMIT 80');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $row) {
            $item = formatProfileItem($row);
            if (($row['feedback_type'] ?? '') === 'like') $profile['likes'][] = $item;
            else $profile['dislikes'][] = $item;
        }
    } catch (Throwable $e) {}

    return $profile;
}

function ensureFavoritesTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `user_favorites` (
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
        CONSTRAINT `fk_uf_user_ai` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureFeedbackTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `user_attraction_feedback` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `name` VARCHAR(120) NOT NULL,
        `city` VARCHAR(80) NOT NULL DEFAULT '',
        `description` VARCHAR(255) NOT NULL DEFAULT '',
        `address` VARCHAR(255) NOT NULL DEFAULT '',
        `tags` JSON DEFAULT NULL,
        `feedback_type` ENUM('like','dislike') NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_user_name` (`user_id`, `name`),
        KEY `idx_user_type` (`user_id`, `feedback_type`, `created_at`),
        CONSTRAINT `fk_uaf_user_ai` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function formatProfileItem(array $row): array {
    $tags = $row['tags'] ? (json_decode($row['tags'], true) ?: []) : [];
    return [
        'name' => $row['name'] ?? '',
        'city' => $row['city'] ?? '',
        'address' => $row['address'] ?? '',
        'tags' => $tags,
    ];
}

function profileItemsText(array $items): string {
    if (empty($items)) return '暂无';
    $lines = [];
    foreach (array_slice($items, 0, 30) as $item) {
        $tags = empty($item['tags']) ? '' : '（标签：' . implode('、', $item['tags']) . '）';
        $city = empty($item['city']) ? '' : '，城市：' . $item['city'];
        $lines[] = ($item['name'] ?? '') . $tags . $city;
    }
    return implode('；', $lines);
}

function buildSystemPrompt(array $profile, float $userLng = 0, float $userLat = 0): string {
    $prefs = empty($profile['prefs']) ? '暂无明确偏好' : implode('、', $profile['prefs']);
    $favorites = profileItemsText($profile['favorites']);
    $likes = profileItemsText($profile['likes']);
    $dislikes = profileItemsText($profile['dislikes']);
    $userlike = empty($profile['userlike']) ? '暂无记录' : $profile['userlike'];
    $locationInfo = '';
    if ($userLng != 0 && $userLat != 0) {
        $locationInfo = "- 用户当前位置坐标：经度 {$userLng}，纬度 {$userLat}（高德GCJ-02坐标系）";
    }
    return <<<PROMPT
你是 UMap 的 AI 旅行助手，请使用中文回答，输出 Markdown。你有能力调用外部工具获取实时数据，不要凭训练数据猜测。

用户画像：
- 旅行喜好标签：{$prefs}
- 已收藏地点：{$favorites}
- 点赞过的地点：{$likes}
- 踩过/不喜欢的地点：{$dislikes}
- 用户深度喜好档案：{$userlike}
{$locationInfo}

══════════════════════════════════════
核心行为准则（必须严格遵守）
══════════════════════════════════════

1.【强制调用工具】当用户问题涉及以下任何场景时，必须先调用工具获取实时数据，严禁凭训练数据编造：
  - 天气查询（如"天气怎么样"、"适合穿什么"）→ 调用 get_weather
  - 搜索具体地点/餐厅/酒店/景点（如"有什么好吃的"、"推荐XX"）→ 调用 search_places
  - 附近搜索（如"附近有什么"、"周边"）→ 调用 get_nearby
  - 实时信息（如门票价格、营业时间、最新攻略、活动、政策）→ 调用 web_search
  - 酒店比价/订酒店/住宿推荐（如"比较酒店价格"、"推荐酒店"、"住哪里便宜"）→ 调用 search_hotels
  - 路线规划/怎么去/交通（如"怎么去"、"多远"、"路线"、"坐什么车"）→ 调用 get_route
  - 需要深入了解某篇搜索结果 → 调用 fetch_page

2.【工具调用格式】每次回复最多调用 3 个执行工具，工具调用代码块放在回复末尾：
```tool_call
{"tool":"工具名","params":{具体参数}}
```

3.【展示工具】以下工具由你直接生成内容，展示在正文之后：
  - location：推荐具体景点时使用，一个景点一个代码块
  ```location
  {"name":"景点名","city":"城市","address":"地址","desc":"一句话推荐理由","location":"经度,纬度"}
  ```
  location为GCJ-02坐标，不确定可省略该字段。不要在正文里重复卡片已有信息。
  
  - itinerary：规划多日行程时使用
  ```itinerary
  {"title":"行程标题","days":[{"day":1,"title":"第1天主题","items":[{"time":"09:00","activity":"活动","tip":"贴士"}]}]}
  ```
  
  - comparison：对比多个地点/酒店/餐厅时使用
  ```comparison
  {"title":"对比标题","items":[{"name":"名称","attrs":[{"label":"属性","value":"值"}],"recommend":true}]}
  ```
  
  - travel_tip：重要注意事项/警告/提示时使用
  ```travel_tip
  {"type":"warning|info|success","title":"标题","content":"内容"}
  ```
  
  - userlike_update：仅在发现用户新的旅行偏好时使用（不要频繁使用）
  ```userlike_update
  新增喜好：xxx
  ```

══════════════════════════════════════
工具速查表
══════════════════════════════════════

search_places → {"tool":"search_places","params":{"keyword":"关键词","city":"城市"}}
get_nearby   → {"tool":"get_nearby","params":{"lng":经度,"lat":纬度,"type":"餐饮|酒店|景点|购物","radius":3000}}
get_weather  → {"tool":"get_weather","params":{"city":"城市"}}
web_search   → {"tool":"web_search","params":{"query":"具体搜索词"}}
fetch_page   → {"tool":"fetch_page","params":{"url":"完整URL"}}
search_hotels → {"tool":"search_hotels","params":{"city":"城市","area":"区域","checkin":"YYYY-MM-DD","checkout":"YYYY-MM-DD"}}
get_route    → {"tool":"get_route","params":{"origin":"起点lng,lat","destination":"终点lng,lat","mode":"driving|walking|transit","city":"城市"}}

示例对话：
用户："北京故宫附近有什么好吃的"
你的回复：我先搜索一下故宫附近的美食。
```tool_call
{"tool":"search_places","params":{"keyword":"美食","city":"北京"}}
```

用户："杭州明天天气"
你的回复：我来查一下杭州天气。
```tool_call
{"tool":"get_weather","params":{"city":"杭州"}}
```
PROMPT;
}

function buildUserText(string $message, array $favorite, array $mediaItems, string $searchResults = ''): string {
    $text = $message !== '' ? $message : '请分析我上传的内容，并结合我的旅行偏好给出建议。';
    if (!empty($favorite)) {
        $favText = json_encode([
            'name' => $favorite['name'] ?? '',
            'city' => $favorite['city'] ?? '',
            'address' => $favorite['address'] ?? '',
            'description' => $favorite['description'] ?? '',
            'tags' => $favorite['tags'] ?? [],
            'location' => $favorite['location'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        $text .= "\n\n用户选中的收藏地点：{$favText}\n请把这个地点作为回答上下文。";
    }
    if (!empty($mediaItems)) {
        $text .= "\n\n用户同时上传了媒体文件，请结合媒体内容分析。";
    }
    if ($searchResults !== '') {
        $text .= "\n\n以下是从互联网搜索到的实时信息，请结合这些信息回答用户问题：\n{$searchResults}";
    }
    return $text;
}

function updateUserLikeFromResponse(PDO $db, int $userId, string $content): void {
    // Extract userlike_update blocks from AI response
    if (preg_match_all('/```userlike_update\s*\n(.*?)\n```/s', $content, $matches)) {
        $updates = [];
        foreach ($matches[1] as $block) {
            $line = trim($block);
            if (preg_match('/新增喜好[：:]\s*(.+)/', $line, $m)) {
                $updates[] = trim($m[1]);
            }
        }

        if (!empty($updates)) {
            try {
                // Ensure userlike column exists
                $db->exec("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `userlike` TEXT DEFAULT NULL");

                // Get current userlike
                $stmt = $db->prepare('SELECT userlike FROM users WHERE id = ?');
                $stmt->execute([$userId]);
                $current = $stmt->fetchColumn() ?: '';

                // Append new likes (avoid duplicates, keep last 500 chars)
                $existing = array_filter(array_map('trim', explode('；', $current)));
                foreach ($updates as $new) {
                    if (!in_array($new, $existing)) {
                        $existing[] = $new;
                    }
                }

                // Keep reasonable length
                $updated = implode('；', array_slice($existing, -30));
                if (mb_strlen($updated) > 500) {
                    $updated = mb_substr($updated, -500);
                }

                $stmt = $db->prepare('UPDATE users SET userlike = ? WHERE id = ?');
                $stmt->execute([$updated, $userId]);
            } catch (Throwable $e) {
                error_log('Update userlike error: ' . $e->getMessage());
            }
        }
    }
}

function callMimo(array $payload, bool $stream, string &$outThink = null, string &$outContent = null, callable $onProgress = null): array {
    $buffer = '';
    $raw = '';
    $emitted = false;
    $done = false;
    $thinkBuf = '';
    $contentBuf = '';
    $lastSaveTime = time();

    $ch = curl_init(MIMO_BASE_URL . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . MIMO_API_KEY],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 150,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, &$raw, &$emitted, &$done, &$thinkBuf, &$contentBuf, &$lastSaveTime, $onProgress) {
            $raw .= $chunk;
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $block = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                foreach (explode("\n", $block) as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data:') !== 0) continue;
                    $data = trim(substr($line, 5));
                    if ($data === '[DONE]') { $done = true; continue; }
                    $json = json_decode($data, true);
                    if (!is_array($json)) continue;
                    $delta = $json['choices'][0]['delta'] ?? $json['choices'][0]['message'] ?? [];
                    $think = $delta['reasoning_content'] ?? '';
                    if ($think !== '') {
                        $thinkBuf .= $think;
                        sendEvt('think', ['text' => $think]);
                    }
                    $text = $delta['content'] ?? '';
                    if ($text !== '') {
                        $emitted = true;
                        $contentBuf .= $text;
                        sendEvt('delta', ['text' => $text]);
                    }
                }
            }
            // Incremental save every 3 seconds
            if ($onProgress && time() - $lastSaveTime >= 3 && ($thinkBuf !== '' || $contentBuf !== '')) {
                $lastSaveTime = time();
                $onProgress($thinkBuf, $contentBuf);
            }
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($outThink !== null) $outThink = $thinkBuf;
    if ($outContent !== null) $outContent = $contentBuf;
    return ['ok' => $ok !== false && $status >= 200 && $status < 300 && ($emitted || $done), 'emitted' => $emitted, 'status' => $status, 'body' => $raw, 'error' => $err];
}

function callMimoNonStream(array $payload): array {
    $ch = curl_init(MIMO_BASE_URL . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . MIMO_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 150,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || !$resp || $status < 200 || $status >= 300) return ['think' => '', 'content' => ''];
    $data = json_decode($resp, true);
    $msg = $data['choices'][0]['message'] ?? [];
    return ['think' => trim($msg['reasoning_content'] ?? ''), 'content' => trim($msg['content'] ?? '')];
}

function streamText(string $text): void {
    $len = mb_strlen($text, 'UTF-8');
    for ($i = 0; $i < $len; $i += 24) {
        sendEvt('delta', ['text' => mb_substr($text, $i, 24, 'UTF-8')]);
    }
}

function ensureChatTables(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS `ai_chat_sessions` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `user_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(100) NOT NULL DEFAULT '新对话',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY `idx_user_updated` (`user_id`, `updated_at`),
        CONSTRAINT `fk_acs_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `session_id` INT UNSIGNED NOT NULL,
        `role` ENUM('user','assistant') NOT NULL,
        `content` TEXT NOT NULL,
        `think` TEXT DEFAULT NULL,
        `favorite` JSON DEFAULT NULL,
        `attachments` JSON DEFAULT NULL,
        `search_results` JSON DEFAULT NULL,
        `tool_results` JSON DEFAULT NULL,
        `is_continuation` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_session_created` (`session_id`, `created_at`),
        CONSTRAINT `fk_acm_session` FOREIGN KEY (`session_id`) REFERENCES `ai_chat_sessions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Add columns if table already exists
    try { $db->exec("ALTER TABLE `ai_chat_messages` ADD COLUMN `tool_results` JSON DEFAULT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE `ai_chat_messages` ADD COLUMN `is_continuation` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
}

function loadHistoryMessages(PDO $db, int $sessionId, int $maxRounds): array {
    $limit = $maxRounds * 2;
    $stmt = $db->prepare("SELECT role, content FROM ai_chat_messages WHERE session_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$sessionId, $limit]);
    $rows = array_reverse($stmt->fetchAll());
    return $rows;
}

function generateSessionTitle(PDO $db, int $sessionId, string $firstMsg): void {
    $apiKey = MIMO_API_KEY;
    if (!$apiKey) return;

    $prompt = "根据以下用户消息，生成一个简短的对话标题（不超过15个字，不要引号和标点）：\n" . mb_substr($firstMsg, 0, 200);

    $ch = curl_init(MIMO_BASE_URL . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => MIMO_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => '你是一个标题生成器。只输出标题文字，不要其他内容。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_completion_tokens' => 50,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return;
    $data = json_decode($resp, true);
    $title = trim($data['choices'][0]['message']['content'] ?? '');
    $title = trim($title, "\"' \t\n\r\0\x0B");
    if ($title !== '' && mb_strlen($title) <= 30) {
        $stmt = $db->prepare("UPDATE ai_chat_sessions SET title = ? WHERE id = ?");
        $stmt->execute([$title, $sessionId]);
        sendEvt('title', ['title' => $title]);
    }
}
