<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = user();
if ($u) {
    q("UPDATE notification SET is_read = 1 WHERE user_id = ?", [(int)$u['user_id']]);
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
