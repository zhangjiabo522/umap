<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$token = $_GET['token'] ?? '';

if (empty($token)) {
    jsonResponse(['success' => false, 'message' => '无效的验证链接']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT ev.id, ev.user_id, ev.expires_at, u.email_verified
        FROM email_verifications ev
        JOIN users u ON u.id = ev.user_id
        WHERE ev.token = ?
    ");
    $stmt->execute([$token]);
    $record = $stmt->fetch();

    if (!$record) {
        jsonResponse(['success' => false, 'message' => '验证链接无效或已过期']);
    }

    if ($record['email_verified']) {
        header('Location: /?verified=already');
        exit;
    }

    if (strtotime($record['expires_at']) < time()) {
        jsonResponse(['success' => false, 'message' => '验证链接已过期，请重新注册']);
    }

    $db->beginTransaction();

    $stmt = $db->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
    $stmt->execute([$record['user_id']]);

    $stmt = $db->prepare("DELETE FROM email_verifications WHERE user_id = ?");
    $stmt->execute([$record['user_id']]);

    $db->commit();

    header('Location: /?verified=success');
    exit;
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Verify email error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => '验证失败，请稍后重试'], 500);
}
