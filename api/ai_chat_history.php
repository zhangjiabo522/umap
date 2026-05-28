<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

loadEnv(__DIR__ . '/../.env');

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
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY `idx_session_created` (`session_id`, `created_at`),
        CONSTRAINT `fk_acm_session` FOREIGN KEY (`session_id`) REFERENCES `ai_chat_sessions`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

header('Content-Type: application/json; charset=utf-8');
corsHeaders();

session_start();
if (empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => '未登录'], 401);
}

$userId = intval($_SESSION['user_id']);
$db = getDB();
ensureChatTables($db);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            handleList($db, $userId);
            break;
        case 'messages':
            handleMessages($db, $userId);
            break;
        case 'create':
            handleCreate($db, $userId);
            break;
        case 'update_title':
            handleUpdateTitle($db, $userId);
            break;
        case 'delete':
            handleDelete($db, $userId);
            break;
        case 'auto_title':
            handleAutoTitle($db, $userId);
            break;
        default:
            jsonResponse(['success' => false, 'message' => '未知操作'], 400);
    }
} catch (Throwable $e) {
    error_log('AI chat history error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '操作失败，请稍后重试'], 500);
}

function handleList(PDO $db, int $userId): void {
    $stmt = $db->prepare("
        SELECT s.id, s.title, s.created_at, s.updated_at,
               (SELECT COUNT(*) FROM ai_chat_messages WHERE session_id = s.id) AS message_count
        FROM ai_chat_sessions s
        WHERE s.user_id = ?
        ORDER BY s.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $sessions = $stmt->fetchAll();
    jsonResponse(['success' => true, 'sessions' => $sessions]);
}

function handleMessages(PDO $db, int $userId): void {
    $sessionId = intval($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        jsonResponse(['success' => false, 'message' => '参数错误'], 400);
    }

    $stmt = $db->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $stmt->execute([$sessionId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => '会话不存在'], 404);
    }

    $stmt = $db->prepare("SELECT id, role, content, think, favorite, attachments, search_results, tool_results, is_continuation, created_at FROM ai_chat_messages WHERE session_id = ? ORDER BY created_at ASC");
    $stmt->execute([$sessionId]);
    $rawMessages = $stmt->fetchAll();

    // Merge continuation messages into the previous assistant message
    $messages = [];
    foreach ($rawMessages as $row) {
        $isCont = intval($row['is_continuation'] ?? 0);
        if ($row['role'] === 'assistant' && $isCont) {
            // Append continuation content to the last assistant message
            $lastIdx = count($messages) - 1;
            while ($lastIdx >= 0 && $messages[$lastIdx]['role'] !== 'assistant') $lastIdx--;
            if ($lastIdx >= 0) {
                $messages[$lastIdx]['content'] .= "\n\n" . $row['content'];
            } else {
                // No previous assistant message (shouldn't happen), add as new
                $messages[] = buildMsgRow($row);
            }
        } else {
            $messages[] = buildMsgRow($row);
        }
    }
    jsonResponse(['success' => true, 'messages' => $messages]);
}

function buildMsgRow(array $row): array {
    return [
        'id' => $row['id'],
        'role' => $row['role'],
        'content' => $row['content'],
        'think' => $row['think'],
        'favorite' => $row['favorite'] ? json_decode($row['favorite'], true) : null,
        'attachments' => $row['attachments'] ? json_decode($row['attachments'], true) : null,
        'searchResults' => $row['search_results'] ? json_decode($row['search_results'], true) : null,
        'toolResults' => $row['tool_results'] ? json_decode($row['tool_results'], true) : null,
        'created_at' => $row['created_at'],
    ];
}

function handleCreate(PDO $db, int $userId): void {
    $title = sanitizeInput($_POST['title'] ?? '新对话');
    if (mb_strlen($title) > 100) $title = mb_substr($title, 0, 100);

    $stmt = $db->prepare("INSERT INTO ai_chat_sessions (user_id, title) VALUES (?, ?)");
    $stmt->execute([$userId, $title]);
    jsonResponse(['success' => true, 'session_id' => intval($db->lastInsertId()), 'title' => $title]);
}

function handleUpdateTitle(PDO $db, int $userId): void {
    $sessionId = intval($_POST['session_id'] ?? 0);
    $title = sanitizeInput($_POST['title'] ?? '');
    if ($sessionId <= 0 || $title === '') {
        jsonResponse(['success' => false, 'message' => '参数错误'], 400);
    }
    if (mb_strlen($title) > 100) $title = mb_substr($title, 0, 100);

    $stmt = $db->prepare("UPDATE ai_chat_sessions SET title = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$title, $sessionId, $userId]);
    jsonResponse(['success' => true]);
}

function handleDelete(PDO $db, int $userId): void {
    $sessionId = intval($_POST['session_id'] ?? 0);
    if ($sessionId <= 0) {
        jsonResponse(['success' => false, 'message' => '参数错误'], 400);
    }

    $stmt = $db->prepare("DELETE FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $stmt->execute([$sessionId, $userId]);
    jsonResponse(['success' => true]);
}

function handleAutoTitle(PDO $db, int $userId): void {
    $sessionId = intval($_POST['session_id'] ?? 0);
    if ($sessionId <= 0) {
        jsonResponse(['success' => false, 'message' => '参数错误'], 400);
    }

    $stmt = $db->prepare("SELECT id FROM ai_chat_sessions WHERE id = ? AND user_id = ?");
    $stmt->execute([$sessionId, $userId]);
    if (!$stmt->fetch()) {
        jsonResponse(['success' => false, 'message' => '会话不存在'], 404);
    }

    $stmt = $db->prepare("SELECT content FROM ai_chat_messages WHERE session_id = ? AND role = 'user' ORDER BY created_at ASC LIMIT 3");
    $stmt->execute([$sessionId]);
    $userMessages = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($userMessages)) {
        jsonResponse(['success' => true, 'title' => '新对话']);
        return;
    }

    $firstMsg = implode('；', array_slice($userMessages, 0, 3));
    $title = generateTitle($firstMsg);

    $stmt = $db->prepare("UPDATE ai_chat_sessions SET title = ? WHERE id = ?");
    $stmt->execute([$title, $sessionId]);
    jsonResponse(['success' => true, 'title' => $title]);
}

function generateTitle(string $text): string {
    $apiKey = $_ENV['MIMO_API_KEY'] ?? '';
    if (!$apiKey) return mb_substr($text, 0, 20);

    $prompt = "根据以下用户消息，生成一个简短的对话标题（不超过15个字，不要引号和标点）：\n" . mb_substr($text, 0, 200);

    $ch = curl_init(($_ENV['MIMO_BASE_URL'] ?? 'https://token-plan-sgp.xiaomimimo.com/v1') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $_ENV['MIMO_MODEL'] ?? 'mimo-v2.5-pro',
            'messages' => [
                ['role' => 'system', 'content' => '你是一个标题生成器。只输出标题文字，不要其他内容。'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'max_completion_tokens' => 200,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    if (!$resp) return mb_substr($text, 0, 15);
    $data = json_decode($resp, true);
    $title = trim($data['choices'][0]['message']['content'] ?? '');
    $title = trim($title, "\"' \t\n\r\0\x0B");
    if ($title === '' || mb_strlen($title) > 30) return mb_substr($text, 0, 15);
    return $title;
}
