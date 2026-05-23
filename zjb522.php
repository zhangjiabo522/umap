<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

session_start();

$adminUser = 'admin';
$adminPass = 'zhang522';

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: /zjb522.php');
    exit;
}

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    if ($_POST['username'] === $adminUser && $_POST['password'] === $adminPass) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: /zjb522.php');
        exit;
    }
    $loginError = '账号或密码错误';
}

if (empty($_SESSION['admin_logged_in'])) {
    showLogin($loginError ?? '');
    exit;
}

try {
    $db = getDB();
} catch (Throwable $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

$tab = $_GET['tab'] ?? 'users';
$userId = intval($_GET['uid'] ?? 0);
$sessionId = intval($_GET['sid'] ?? 0);

showAdmin($db, $tab, $userId, $sessionId);

// ==================== Login Page ====================
function showLogin(?string $error): void {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UMap 管理后台</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f0f4f8;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.login-card{background:#fff;border-radius:16px;padding:40px 32px;width:360px;box-shadow:0 4px 24px rgba(0,0,0,0.08)}
.login-card h1{font-size:22px;font-weight:700;text-align:center;margin-bottom:8px;color:#1a1a2e}
.login-card p{text-align:center;font-size:13px;color:#8896ab;margin-bottom:24px}
.login-card input{width:100%;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:14px;margin-bottom:12px;outline:none;transition:border .2s}
.login-card input:focus{border-color:#1565EF}
.login-card button{width:100%;padding:12px;background:#1565EF;color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;transition:opacity .2s}
.login-card button:hover{opacity:.9}
.error{color:#DC2626;font-size:13px;text-align:center;margin-bottom:12px}
</style>
</head>
<body>
<form class="login-card" method="POST">
    <h1>UMap 管理后台</h1>
    <p>请登录以继续</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <input name="username" placeholder="账号" autocomplete="username" required>
    <input name="password" type="password" placeholder="密码" autocomplete="current-password" required>
    <button type="submit">登录</button>
</form>
</body>
</html>
<?php
}

// ==================== Admin Dashboard ====================
function showAdmin(PDO $db, string $tab, int $userId, int $sessionId): void {
    $stats = getStats($db);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>UMap 管理后台</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#f5f7fa;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1a1a2e;font-size:14px}
.topbar{background:#fff;border-bottom:1px solid #e8ecf1;padding:12px 20px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:10}
.topbar h1{font-size:18px;font-weight:700}
.topbar a{color:#1565EF;font-size:13px;text-decoration:none}
.tabs{display:flex;gap:4px;padding:12px 20px;background:#fff;border-bottom:1px solid #e8ecf1;overflow-x:auto}
.tabs a{padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;white-space:nowrap;transition:all .15s}
.tabs a.active{background:#1565EF;color:#fff}
.tabs a:hover:not(.active){background:#EEF4FF;color:#1565EF}
.container{max-width:960px;margin:0 auto;padding:16px 20px}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.stat-card{background:#fff;border-radius:12px;padding:16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,0.04)}
.stat-card .num{font-size:28px;font-weight:700;color:#1565EF}
.stat-card .label{font-size:12px;color:#8896ab;margin-top:4px}
.card{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,0.04);margin-bottom:16px;overflow:hidden}
.card-header{padding:14px 16px;border-bottom:1px solid #f0f2f5;font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:space-between}
table{width:100%;border-collapse:collapse}
th,td{padding:10px 14px;text-align:left;border-bottom:1px solid #f0f2f5;font-size:13px}
th{background:#fafbfc;font-weight:600;color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.5px}
tr:hover{background:#f8fafc}
.badge{display:inline-block;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:600}
.badge-green{background:#ecfdf5;color:#059669}
.badge-blue{background:#eff6ff;color:#1565EF}
.badge-amber{background:#fffbeb;color:#D97706}
.badge-red{background:#fef2f2;color:#DC2626}
.btn{padding:6px 12px;border-radius:8px;border:none;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:all .15s}
.btn-primary{background:#1565EF;color:#fff}
.btn-primary:hover{opacity:.9}
.btn-sm{padding:4px 10px;font-size:11px}
.profile-section{padding:16px}
.profile-section h3{font-size:14px;font-weight:700;margin-bottom:10px;color:#1a1a2e}
.tag-list{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.tag{padding:4px 10px;border-radius:8px;font-size:12px;background:#f0f4f8;color:#475569}
.tag-like{background:#ecfdf5;color:#059669}
.tag-dislike{background:#fef2f2;color:#DC2626}
.tag-pref{background:#eff6ff;color:#1565EF}
.empty{text-align:center;padding:32px;color:#8896ab;font-size:13px}
.back-link{display:inline-flex;align-items:center;gap:4px;color:#1565EF;text-decoration:none;font-size:13px;font-weight:600;margin-bottom:12px}
.msg-preview{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(max-width:640px){
    .container{padding:12px}
    .stats{grid-template-columns:repeat(2,1fr)}
    th,td{padding:8px 10px;font-size:12px}
    .card-header{padding:12px}
}
</style>
</head>
<body>
<div class="topbar">
    <h1>UMap 管理后台</h1>
    <a href="?logout=1">退出登录</a>
</div>
<div class="tabs">
    <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">用户管理</a>
    <a href="?tab=chats" class="<?= $tab === 'chats' ? 'active' : '' ?>">AI对话</a>
    <a href="?tab=favorites" class="<?= $tab === 'favorites' ? 'active' : '' ?>">收藏数据</a>
    <a href="?tab=feedback" class="<?= $tab === 'feedback' ? 'active' : '' ?>">点赞/踩</a>
    <a href="?tab=system" class="<?= $tab === 'system' ? 'active' : '' ?>">系统信息</a>
</div>
<div class="container">
<?php if ($sessionId > 0 && $tab === 'chat'): ?>
    <?php showChatMessages($db, $sessionId); ?>
<?php elseif ($userId > 0 && $tab === 'profile'): ?>
    <?php showUserProfile($db, $userId); ?>
<?php else: ?>
    <?php
    // Stats
    echo '<div class="stats">';
    echo statCard($stats['users'], '注册用户');
    echo statCard($stats['sessions'], 'AI对话');
    echo statCard($stats['messages'], '消息总数');
    echo statCard($stats['favorites'], '收藏地点');
    echo statCard($stats['likes'], '点赞');
    echo statCard($stats['dislikes'], '踩');
    echo '</div>';

    switch ($tab) {
        case 'users': showUsers($db); break;
        case 'chats': showChats($db); break;
        case 'favorites': showFavorites($db); break;
        case 'feedback': showFeedback($db); break;
        case 'system': showSystem($db, $stats); break;
        default: showUsers($db);
    }
    ?>
<?php endif; ?>
</div>
</body>
</html>
<?php
}

// ==================== Stats ====================
function getStats(PDO $db): array {
    $s = [];
    $s['users'] = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $s['sessions'] = $db->query("SELECT COUNT(*) FROM ai_chat_sessions")->fetchColumn();
    $s['messages'] = $db->query("SELECT COUNT(*) FROM ai_chat_messages")->fetchColumn();
    $s['favorites'] = $db->query("SELECT COUNT(*) FROM user_favorites")->fetchColumn();
    $s['likes'] = $db->query("SELECT COUNT(*) FROM user_attraction_feedback WHERE feedback_type='like'")->fetchColumn();
    $s['dislikes'] = $db->query("SELECT COUNT(*) FROM user_attraction_feedback WHERE feedback_type='dislike'")->fetchColumn();
    $s['prefs'] = $db->query("SELECT COUNT(*) FROM user_preferences")->fetchColumn();
    return $s;
}

function statCard(int $num, string $label): string {
    return '<div class="stat-card"><div class="num">' . number_format($num) . '</div><div class="label">' . $label . '</div></div>';
}

// ==================== Users List ====================
function showUsers(PDO $db): void {
    $users = $db->query("
        SELECT u.id, u.username, u.email, u.email_verified, u.created_at,
            (SELECT COUNT(*) FROM ai_chat_sessions WHERE user_id = u.id) AS chat_count,
            (SELECT COUNT(*) FROM user_favorites WHERE user_id = u.id) AS fav_count,
            (SELECT COUNT(*) FROM user_attraction_feedback WHERE user_id = u.id) AS fb_count
        FROM users u ORDER BY u.created_at DESC
    ")->fetchAll();
?>
<div class="card">
    <div class="card-header">用户列表 (<?= count($users) ?>)</div>
    <table>
        <tr><th>ID</th><th>用户名</th><th>邮箱</th><th>状态</th><th>对话</th><th>收藏</th><th>反馈</th><th>注册时间</th><th>操作</th></tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= $u['id'] ?></td>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= $u['email_verified'] ? '<span class="badge badge-green">已验证</span>' : '<span class="badge badge-amber">未验证</span>' ?></td>
            <td><?= $u['chat_count'] ?></td>
            <td><?= $u['fav_count'] ?></td>
            <td><?= $u['fb_count'] ?></td>
            <td><?= date('m-d H:i', strtotime($u['created_at'])) ?></td>
            <td><a class="btn btn-primary btn-sm" href="?tab=profile&uid=<?= $u['id'] ?>">查看画像</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php
}

// ==================== User Profile ====================
function showUserProfile(PDO $db, int $uid): void {
    $user = $db->prepare("SELECT * FROM users WHERE id = ?");
    $user->execute([$uid]);
    $u = $user->fetch();
    if (!$u) { echo '<div class="empty">用户不存在</div>'; return; }

    // Preferences
    $prefs = $db->prepare("SELECT tt.name, tt.category FROM user_preferences up JOIN travel_tags tt ON tt.id = up.tag_id WHERE up.user_id = ?");
    $prefs->execute([$uid]);
    $prefList = $prefs->fetchAll();

    // Custom tags
    $customTags = $db->prepare("SELECT name FROM user_custom_tags WHERE user_id = ?");
    $customTags->execute([$uid]);
    $customList = $customTags->fetchAll(PDO::FETCH_COLUMN);

    // Favorites
    $favs = $db->prepare("SELECT * FROM user_favorites WHERE user_id = ? ORDER BY created_at DESC");
    $favs->execute([$uid]);
    $favList = $favs->fetchAll();

    // Feedback
    $fb = $db->prepare("SELECT * FROM user_attraction_feedback WHERE user_id = ? ORDER BY created_at DESC");
    $fb->execute([$uid]);
    $fbList = $fb->fetchAll();

    // Chat sessions
    $sessions = $db->prepare("SELECT s.*, (SELECT COUNT(*) FROM ai_chat_messages WHERE session_id = s.id) AS msg_count FROM ai_chat_sessions s WHERE s.user_id = ? ORDER BY s.updated_at DESC LIMIT 20");
    $sessions->execute([$uid]);
    $sessList = $sessions->fetchAll();
?>

<a class="back-link" href="?tab=users">&larr; 返回用户列表</a>

<div class="card">
    <div class="card-header">
        用户画像: <?= htmlspecialchars($u['username']) ?>
        <span class="badge badge-blue">ID: <?= $u['id'] ?></span>
    </div>
    <div class="profile-section">
        <p><strong>邮箱:</strong> <?= htmlspecialchars($u['email']) ?> <?= $u['email_verified'] ? '<span class="badge badge-green">已验证</span>' : '<span class="badge badge-amber">未验证</span>' ?></p>
        <p><strong>注册:</strong> <?= $u['created_at'] ?></p>
    </div>
</div>

<div class="card">
    <div class="card-header">旅行偏好 (<?= count($prefList) + count($customList) ?>)</div>
    <div class="profile-section">
        <?php if (empty($prefList) && empty($customList)): ?>
            <div class="empty">暂无偏好数据</div>
        <?php else: ?>
            <div class="tag-list">
                <?php foreach ($prefList as $p): ?>
                    <span class="tag tag-pref"><?= htmlspecialchars($p['name']) ?> <small>(<?= $p['category'] ?>)</small></span>
                <?php endforeach; ?>
                <?php foreach ($customList as $c): ?>
                    <span class="tag"><?= htmlspecialchars($c) ?> <small>(自定义)</small></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">收藏地点 (<?= count($favList) ?>)</div>
    <?php if (empty($favList)): ?>
        <div class="empty">暂无收藏</div>
    <?php else: ?>
    <table>
        <tr><th>名称</th><th>城市</th><th>地址</th><th>坐标</th><th>时间</th></tr>
        <?php foreach ($favList as $f): ?>
        <tr>
            <td><strong><?= htmlspecialchars($f['name']) ?></strong></td>
            <td><?= htmlspecialchars($f['city']) ?></td>
            <td><?= htmlspecialchars($f['address']) ?></td>
            <td><small><?= htmlspecialchars($f['location']) ?></small></td>
            <td><?= date('m-d H:i', strtotime($f['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">点赞/踩 (<?= count($fbList) ?>)</div>
    <?php if (empty($fbList)): ?>
        <div class="empty">暂无反馈</div>
    <?php else: ?>
    <table>
        <tr><th>名称</th><th>城市</th><th>类型</th><th>时间</th></tr>
        <?php foreach ($fbList as $f): ?>
        <tr>
            <td><?= htmlspecialchars($f['name']) ?></td>
            <td><?= htmlspecialchars($f['city']) ?></td>
            <td><?= $f['feedback_type'] === 'like' ? '<span class="badge badge-green">点赞</span>' : '<span class="badge badge-red">踩</span>' ?></td>
            <td><?= date('m-d H:i', strtotime($f['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">AI对话记录 (<?= count($sessList) ?>)</div>
    <?php if (empty($sessList)): ?>
        <div class="empty">暂无对话</div>
    <?php else: ?>
    <table>
        <tr><th>ID</th><th>标题</th><th>消息数</th><th>最后活跃</th><th>操作</th></tr>
        <?php foreach ($sessList as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><?= htmlspecialchars($s['title']) ?></td>
            <td><?= $s['msg_count'] ?></td>
            <td><?= date('m-d H:i', strtotime($s['updated_at'])) ?></td>
            <td><a class="btn btn-primary btn-sm" href="?tab=chat&sid=<?= $s['id'] ?>">查看对话</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
<?php
}

// ==================== Chat Messages ====================
function showChatMessages(PDO $db, int $sid): void {
    $stmt = $db->prepare("SELECT s.*, u.username, u.id AS user_id FROM ai_chat_sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ?");
    $stmt->execute([$sid]);
    $session = $stmt->fetch();
    if (!$session) { echo '<div class="empty">对话不存在</div>'; return; }

    $msgs = $db->prepare("SELECT * FROM ai_chat_messages WHERE session_id = ? ORDER BY created_at ASC");
    $msgs->execute([$sid]);
    $msgList = $msgs->fetchAll();
?>
<style>
.chat-container{max-width:800px;margin:0 auto}
.msg-item{margin-bottom:16px;display:flex;flex-direction:column}
.msg-item.user{align-items:flex-end}
.msg-item.assistant{align-items:flex-start}
.msg-bubble{max-width:85%;padding:12px 16px;border-radius:14px;font-size:14px;line-height:1.6;word-break:break-word;white-space:pre-wrap}
.msg-item.user .msg-bubble{background:#1565EF;color:#fff;border-bottom-right-radius:4px}
.msg-item.assistant .msg-bubble{background:#f0f4f8;color:#1a1a2e;border-bottom-left-radius:4px}
.msg-meta{font-size:11px;color:#8896ab;margin-top:4px;padding:0 4px}
.msg-role{font-weight:600;font-size:12px;margin-bottom:2px;padding:0 4px}
.msg-role.user-role{color:#1565EF}
.msg-role.ai-role{color:#059669}
.msg-think{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-top:8px;font-size:13px;color:#92400e;line-height:1.5;max-height:200px;overflow-y:auto}
.msg-think summary{cursor:pointer;font-weight:600;font-size:12px;color:#D97706}
.msg-attachment{display:inline-flex;align-items:center;gap:4px;background:#eff6ff;color:#1565EF;padding:4px 10px;border-radius:8px;font-size:12px;margin-top:6px}
.msg-search{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:10px 14px;margin-top:8px;font-size:12px;color:#166534;line-height:1.5}
.msg-search summary{cursor:pointer;font-weight:600;font-size:12px;color:#059669}
.msg-favorite{background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:8px 12px;margin-top:8px;font-size:12px;color:#9a3412}
</style>

<a class="back-link" href="?tab=chats">&larr; 返回对话列表</a>

<div class="card">
    <div class="card-header">
        对话详情
        <span class="badge badge-blue">ID: <?= $sid ?></span>
    </div>
    <div class="profile-section">
        <p><strong>用户:</strong> <a href="?tab=profile&uid=<?= $session['user_id'] ?>" style="color:#1565EF"><?= htmlspecialchars($session['username']) ?></a></p>
        <p><strong>标题:</strong> <?= htmlspecialchars($session['title']) ?></p>
        <p><strong>消息数:</strong> <?= count($msgList) ?></p>
        <p><strong>创建时间:</strong> <?= $session['created_at'] ?></p>
        <p><strong>最后活跃:</strong> <?= $session['updated_at'] ?></p>
    </div>
</div>

<div class="card">
    <div class="card-header">对话内容 (<?= count($msgList) ?> 条消息)</div>
    <div class="profile-section chat-container">
        <?php if (empty($msgList)): ?>
            <div class="empty">暂无消息</div>
        <?php else: ?>
            <?php foreach ($msgList as $m): ?>
            <div class="msg-item <?= $m['role'] ?>">
                <div class="msg-role <?= $m['role'] === 'user' ? 'user-role' : 'ai-role' ?>"><?= $m['role'] === 'user' ? '用户' : 'AI助手' ?></div>
                <div class="msg-bubble"><?= nl2br(htmlspecialchars($m['content'])) ?></div>

                <?php if (!empty($m['think'])): ?>
                <details class="msg-think">
                    <summary>AI 思考过程</summary>
                    <?= nl2br(htmlspecialchars($m['think'])) ?>
                </details>
                <?php endif; ?>

                <?php
                $attachments = $m['attachments'] ? json_decode($m['attachments'], true) : null;
                if (!empty($attachments)):
                    foreach ($attachments as $a):
                ?>
                <div class="msg-attachment"><?= $a['type'] === 'image' ? '图片' : ($a['type'] === 'audio' ? '音频' : '视频') ?>: <?= htmlspecialchars($a['name']) ?></div>
                <?php
                    endforeach;
                endif;
                ?>

                <?php
                $fav = $m['favorite'] ? json_decode($m['favorite'], true) : null;
                if (!empty($fav)):
                ?>
                <div class="msg-favorite">收藏地点: <?= htmlspecialchars($fav['name'] ?? '') ?> (<?= htmlspecialchars($fav['city'] ?? '') ?>)</div>
                <?php endif; ?>

                <?php
                $searchResults = $m['search_results'] ? json_decode($m['search_results'], true) : null;
                if (!empty($searchResults)):
                ?>
                <details class="msg-search">
                    <summary>搜索结果 (<?= count($searchResults) ?> 条)</summary>
                    <?php foreach ($searchResults as $r): ?>
                    <div style="margin-bottom:6px"><strong><?= htmlspecialchars($r['title'] ?? '') ?></strong><br><small><?= htmlspecialchars($r['snippet'] ?? '') ?></small></div>
                    <?php endforeach; ?>
                </details>
                <?php endif; ?>

                <div class="msg-meta"><?= date('Y-m-d H:i:s', strtotime($m['created_at'])) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php
}

// ==================== AI Chats ====================
function showChats(PDO $db): void {
    $sessions = $db->query("
        SELECT s.id, s.title, s.created_at, s.updated_at, s.user_id, u.username,
            (SELECT COUNT(*) FROM ai_chat_messages WHERE session_id = s.id) AS msg_count
        FROM ai_chat_sessions s
        JOIN users u ON u.id = s.user_id
        ORDER BY s.updated_at DESC LIMIT 100
    ")->fetchAll();
?>
<div class="card">
    <div class="card-header">AI对话列表 (<?= count($sessions) ?>)</div>
    <table>
        <tr><th>ID</th><th>用户</th><th>标题</th><th>消息</th><th>创建</th><th>最后活跃</th><th>操作</th></tr>
        <?php foreach ($sessions as $s): ?>
        <tr>
            <td><?= $s['id'] ?></td>
            <td><a href="?tab=profile&uid=<?= $s['user_id'] ?>" style="color:#1565EF;text-decoration:none"><?= htmlspecialchars($s['username']) ?></a></td>
            <td><?= htmlspecialchars($s['title']) ?></td>
            <td><?= $s['msg_count'] ?></td>
            <td><?= date('m-d H:i', strtotime($s['created_at'])) ?></td>
            <td><?= date('m-d H:i', strtotime($s['updated_at'])) ?></td>
            <td><a class="btn btn-primary btn-sm" href="?tab=chat&sid=<?= $s['id'] ?>">查看对话</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php
}

// ==================== Favorites ====================
function showFavorites(PDO $db): void {
    $favs = $db->query("
        SELECT f.*, u.username FROM user_favorites f
        JOIN users u ON u.id = f.user_id
        ORDER BY f.created_at DESC LIMIT 200
    ")->fetchAll();
?>
<div class="card">
    <div class="card-header">全部收藏 (<?= count($favs) ?>)</div>
    <table>
        <tr><th>用户</th><th>名称</th><th>城市</th><th>地址</th><th>坐标</th><th>时间</th></tr>
        <?php foreach ($favs as $f): ?>
        <tr>
            <td><?= htmlspecialchars($f['username']) ?></td>
            <td><strong><?= htmlspecialchars($f['name']) ?></strong></td>
            <td><?= htmlspecialchars($f['city']) ?></td>
            <td><?= htmlspecialchars(mb_substr($f['address'], 0, 20)) ?></td>
            <td><small><?= htmlspecialchars($f['location']) ?></small></td>
            <td><?= date('m-d H:i', strtotime($f['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php
}

// ==================== Feedback ====================
function showFeedback(PDO $db): void {
    $fb = $db->query("
        SELECT f.*, u.username FROM user_attraction_feedback f
        JOIN users u ON u.id = f.user_id
        ORDER BY f.created_at DESC LIMIT 200
    ")->fetchAll();
?>
<div class="card">
    <div class="card-header">点赞/踩记录 (<?= count($fb) ?>)</div>
    <table>
        <tr><th>用户</th><th>地点</th><th>城市</th><th>类型</th><th>时间</th></tr>
        <?php foreach ($fb as $f): ?>
        <tr>
            <td><?= htmlspecialchars($f['username']) ?></td>
            <td><?= htmlspecialchars($f['name']) ?></td>
            <td><?= htmlspecialchars($f['city']) ?></td>
            <td><?= $f['feedback_type'] === 'like' ? '<span class="badge badge-green">点赞</span>' : '<span class="badge badge-red">踩</span>' ?></td>
            <td><?= date('m-d H:i', strtotime($f['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php
}

// ==================== System Info ====================
function showSystem(PDO $db, array $stats): void {
    $dbVersion = $db->query("SELECT VERSION()")->fetchColumn();
    $dbSize = $db->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
    $tableSizes = $db->query("
        SELECT table_name AS table_name, table_rows AS table_rows, ROUND((data_length + index_length) / 1024, 1) AS size_kb
        FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_rows DESC
    ")->fetchAll();
?>
<div class="card">
    <div class="card-header">服务器信息</div>
    <div class="profile-section">
        <p><strong>PHP版本:</strong> <?= PHP_VERSION ?></p>
        <p><strong>MySQL版本:</strong> <?= htmlspecialchars($dbVersion) ?></p>
        <p><strong>数据库大小:</strong> <?= $dbSize ?> MB</p>
        <p><strong>服务器时间:</strong> <?= date('Y-m-d H:i:s') ?></p>
        <p><strong>服务器软件:</strong> <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'N/A') ?></p>
    </div>
</div>

<div class="card">
    <div class="card-header">数据表统计</div>
    <table>
        <tr><th>表名</th><th>行数</th><th>大小</th></tr>
        <?php foreach ($tableSizes as $t): ?>
        <tr>
            <td><strong><?= htmlspecialchars($t['table_name']) ?></strong></td>
            <td><?= number_format($t['table_rows']) ?></td>
            <td><?= $t['size_kb'] ?> KB</td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<div class="card">
    <div class="card-header">今日活跃</div>
    <div class="profile-section">
        <?php
        $todayUsers = $db->query("SELECT COUNT(DISTINCT user_id) FROM ai_chat_sessions WHERE updated_at >= CURDATE()")->fetchColumn();
        $todayMsgs = $db->query("SELECT COUNT(*) FROM ai_chat_messages WHERE created_at >= CURDATE()")->fetchColumn();
        $todaySessions = $db->query("SELECT COUNT(*) FROM ai_chat_sessions WHERE created_at >= CURDATE()")->fetchColumn();
        ?>
        <p><strong>今日活跃用户:</strong> <?= $todayUsers ?></p>
        <p><strong>今日新建对话:</strong> <?= $todaySessions ?></p>
        <p><strong>今日消息数:</strong> <?= $todayMsgs ?></p>
    </div>
</div>
<?php
}
