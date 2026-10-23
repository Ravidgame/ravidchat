<?php
/**
 * api.php
 * نقطه‌ی ورود JSON برای همه‌ی عملیات پیام‌رسان
 */

require_once __DIR__ . '/includes/data.php';

$me = require_login_api();
$meId = (int) $me['id'];
rc_cleanup_expired_stories();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost) {
    require_csrf();
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
$jsonInput = request_json();
$input = array_merge($_GET, $_POST, $jsonInput);

// همه‌ی عملیات تغییردهنده باید POST باشند (محافظت در برابر CSRF از طریق GET)
$postOnlyActions = [
    'send', 'edit', 'delete', 'react', 'read', 'typing', 'open_private',
    'create_chat', 'add_members', 'leave', 'chat_settings', 'update_profile',
    'upload', 'upload_avatar', 'upload_chat_avatar', 'story', 'story_view',
];
if (in_array($action, $postOnlyActions, true) && !$isPost) {
    api_error('این عملیات فقط با متد POST مجاز است.', 405, 'method_not_allowed');
}

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */
function api_chat_or_fail(int $chatId, int $meId): array
{
    if ($chatId <= 0) {
        api_error('گفتگو پیدا نشد.', 404, 'not_found');
    }
    $chat = rc_get_chat_for_user($chatId, $meId);
    if (!$chat) {
        api_error('شما عضو این گفتگو نیستید.', 403, 'forbidden');
    }
    return $chat;
}

function api_is_admin(array $chat): bool
{
    return in_array((string) $chat['role'], ['owner', 'admin'], true);
}

function api_can_write(array $chat): bool
{
    $type = (string) $chat['type'];
    $role = (string) $chat['role'];

    if ($type === 'channel') {
        return in_array($role, ['owner', 'admin'], true);
    }
    if ($type === 'group') {
        if ($role === 'subscriber') {
            return false;
        }
        return empty($chat['admins_only']) || in_array($role, ['owner', 'admin'], true);
    }
    return true;
}

function api_require_write(array $chat): void
{
    if (!api_can_write($chat)) {
        api_error('شما اجازه‌ی ارسال پیام در این گفتگو را ندارید.', 403, 'readonly');
    }
}

function api_chat_payload(int $chatId, int $meId, bool $withMessages = true): array
{
    $chat = rc_get_chat_for_user($chatId, $meId);
    if (!$chat) {
        api_error('گفتگو پیدا نشد.', 404, 'not_found');
    }

    $meta = rc_chat_meta_for_user($chat, $meId);
    if ($withMessages) {
        $meta['msgs'] = rc_chat_messages($chatId, $chat, $meId, rc_other_read($chatId, $meId), 80);
    }
    return $meta;
}

function api_message_row(int $messageId): ?array
{
    $stmt = db()->prepare('SELECT * FROM messages WHERE id = ? LIMIT 1');
    $stmt->execute([$messageId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function api_remove_upload(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }
    $base = realpath(UPLOAD_DIR);
    $full = realpath(UPLOAD_DIR . '/' . $relativePath);
    if ($base && $full && strpos($full, $base . DIRECTORY_SEPARATOR) === 0 && is_file($full)) {
        @unlink($full);
    }
}

function api_valid_emoji(string $emoji): bool
{
    return in_array($emoji, ['❤️', '👍', '🔥', '😂', '😮', '😢', '🙏'], true);
}

function api_now(): string
{
    $row = db()->query('SELECT NOW(3) AS t')->fetch();
    return (string) $row['t'];
}

function api_me_payload(int $userId): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        api_error('کاربر پیدا نشد.', 404, 'not_found');
    }

    return [
        'id' => 'me',
        'uid' => (int) $user['id'],
        'name' => (string) $user['name'],
        'user' => (string) $user['username'],
        'h' => (int) $user['avatar_hue'],
        'avatar' => rc_media_url($user['avatar_path'] ?? null),
        'bio' => (string) $user['bio'],
        'phone' => (string) $user['phone'],
    ];
}

/* ------------------------------------------------------------------
 * Actions
 * ------------------------------------------------------------------ */
try {
    switch ($action) {

        /* ----------------------------------------------------------
         * Send a text message
         * ---------------------------------------------------------- */
        case 'send': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $text = trim((string) ($input['text'] ?? ''));
            $tempId = $input['temp_id'] ?? null;
            $forwardedFrom = trim(input_string($input, 'forwarded_from', 60));
            $replyTo = isset($input['reply_to']) && $input['reply_to'] !== null && $input['reply_to'] !== ''
                ? (int) $input['reply_to'] : null;

            $chat = api_chat_or_fail($chatId, $meId);
            api_require_write($chat);

            if ($text === '') {
                api_error('متن پیام خالی است.', 422, 'empty');
            }
            if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 4000 : strlen($text) > 16000) {
                api_error('پیام بیش از حد طولانی است (حداکثر ۴۰۰۰ کاراکتر).', 422, 'too_long');
            }

            $stmt = db()->prepare(
                'SELECT COUNT(*) AS c FROM messages
                 WHERE sender_id = ? AND created_at >= (NOW(3) - INTERVAL 10 SECOND)'
            );
            $stmt->execute([$meId]);
            if ((int) $stmt->fetch()['c'] >= 12) {
                api_error('کمی آرام‌تر! تعداد پیام‌های شما زیاد است.', 429, 'rate_limited');
            }

            if ($replyTo !== null) {
                $stmt = db()->prepare('SELECT id FROM messages WHERE id = ? AND chat_id = ? AND deleted_at IS NULL LIMIT 1');
                $stmt->execute([$replyTo, $chatId]);
                if (!$stmt->fetch()) {
                    $replyTo = null;
                }
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO messages (chat_id, sender_id, type, text, reply_to_id, forwarded_from)
                     VALUES (?, ?, 'text', ?, ?, ?)"
                );
                $stmt->execute([$chatId, $meId, $text, $replyTo, $forwardedFrom !== '' ? $forwardedFrom : null]);
                $messageId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare('UPDATE chats SET last_message_id = ?, last_message_at = NOW(3), updated_at = NOW(3) WHERE id = ?');
                $stmt->execute([$messageId, $chatId]);

                $stmt = $pdo->prepare('UPDATE chat_members SET last_read_message_id = ? WHERE chat_id = ? AND user_id = ?');
                $stmt->execute([$messageId, $chatId, $meId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            $row = api_message_row($messageId);
            $payload = rc_message_payload(
                $row,
                $chat,
                $meId,
                rc_other_read($chatId, $meId),
                rc_reactions_for_messages([$messageId], $meId)[$messageId] ?? []
            );
            $payload['chat_id'] = $chatId;

            json_response([
                'ok' => true,
                'message' => $payload,
                'temp_id' => $tempId,
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Edit message
         * ---------------------------------------------------------- */
        case 'edit': {
            $messageId = (int) ($input['message_id'] ?? 0);
            $text = trim((string) ($input['text'] ?? ''));

            if ($text === '') {
                api_error('متن پیام خالی است.', 422, 'empty');
            }
            if (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') > 4000 : strlen($text) > 16000) {
                api_error('پیام بیش از حد طولانی است.', 422, 'too_long');
            }

            $msg = api_message_row($messageId);
            if (!$msg || $msg['deleted_at'] !== null) {
                api_error('پیام پیدا نشد.', 404, 'not_found');
            }
            $chat = api_chat_or_fail((int) $msg['chat_id'], $meId);
            if ((int) $msg['sender_id'] !== $meId) {
                api_error('فقط فرستنده می‌تواند پیام را ویرایش کند.', 403, 'forbidden');
            }
            if ($msg['type'] !== 'text') {
                api_error('فقط پیام‌های متنی قابل ویرایش هستند.', 422, 'invalid_type');
            }

            $stmt = db()->prepare('UPDATE messages SET text = ?, is_edited = 1, updated_at = NOW(3) WHERE id = ?');
            $stmt->execute([$text, $messageId]);

            $chatId = (int) $msg['chat_id'];
            if ((int) ($chat['last_message_id'] ?? 0) === $messageId) {
                db()->prepare('UPDATE chats SET updated_at = NOW(3) WHERE id = ?')->execute([$chatId]);
            }

            json_response(['ok' => true, 'message_id' => $messageId, 'server_time' => api_now()]);
        }

        /* ----------------------------------------------------------
         * Delete message (soft delete, propagates via sync)
         * ---------------------------------------------------------- */
        case 'delete': {
            $messageId = (int) ($input['message_id'] ?? 0);
            $msg = api_message_row($messageId);
            if (!$msg || $msg['deleted_at'] !== null) {
                api_error('پیام پیدا نشد.', 404, 'not_found');
            }

            $chatId = (int) $msg['chat_id'];
            $chat = api_chat_or_fail($chatId, $meId);
            $isOwn = (int) $msg['sender_id'] === $meId;
            $admin = api_is_admin($chat) && in_array($chat['type'], ['group', 'channel'], true);

            if (!$isOwn && !$admin) {
                api_error('اجازه‌ی حذف این پیام را ندارید.', 403, 'forbidden');
            }

            $filePath = $msg['file_path'];

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    "UPDATE messages
                     SET deleted_at = NOW(), text = NULL, file_path = NULL, file_name = NULL,
                         file_size = NULL, file_mime = NULL, is_edited = 0, updated_at = NOW(3)
                     WHERE id = ?"
                );
                $stmt->execute([$messageId]);

                $pdo->prepare('DELETE FROM reactions WHERE message_id = ?')->execute([$messageId]);

                $pdo->prepare('UPDATE chats SET pinned_message_id = NULL WHERE id = ? AND pinned_message_id = ?')
                    ->execute([$chatId, $messageId]);

                $stmt = $pdo->prepare(
                    'SELECT id, created_at FROM messages
                     WHERE chat_id = ? AND deleted_at IS NULL
                     ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([$chatId]);
                $last = $stmt->fetch();

                if ($last) {
                    $pdo->prepare('UPDATE chats SET last_message_id = ?, last_message_at = ?, updated_at = NOW(3) WHERE id = ?')
                        ->execute([(int) $last['id'], $last['created_at'], $chatId]);
                } else {
                    $pdo->prepare('UPDATE chats SET last_message_id = NULL, last_message_at = created_at, updated_at = NOW(3) WHERE id = ?')
                        ->execute([$chatId]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            api_remove_upload($filePath);
            json_response(['ok' => true, 'message_id' => $messageId, 'server_time' => api_now()]);
        }

        /* ----------------------------------------------------------
         * Reactions
         * ---------------------------------------------------------- */
        case 'react': {
            $messageId = (int) ($input['message_id'] ?? 0);
            $emoji = (string) ($input['emoji'] ?? '');

            if (!api_valid_emoji($emoji)) {
                api_error('ایموجی نامعتبر است.', 422, 'invalid_emoji');
            }

            $msg = api_message_row($messageId);
            if (!$msg || $msg['deleted_at'] !== null) {
                api_error('پیام پیدا نشد.', 404, 'not_found');
            }
            $chatId = (int) $msg['chat_id'];
            api_chat_or_fail($chatId, $meId);

            $stmt = db()->prepare('SELECT emoji FROM reactions WHERE message_id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$messageId, $meId]);
            $current = $stmt->fetch();

            if ($current && (string) $current['emoji'] === $emoji) {
                db()->prepare('DELETE FROM reactions WHERE message_id = ? AND user_id = ?')->execute([$messageId, $meId]);
            } else {
                db()->prepare(
                    'INSERT INTO reactions (message_id, user_id, emoji) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), created_at = NOW()'
                )->execute([$messageId, $meId, $emoji]);
            }

            db()->prepare('UPDATE messages SET updated_at = NOW(3) WHERE id = ?')->execute([$messageId]);

            $reactions = rc_reactions_for_messages([$messageId], $meId)[$messageId] ?? [];
            json_response(['ok' => true, 'message_id' => $messageId, 'react' => $reactions, 'server_time' => api_now()]);
        }

        /* ----------------------------------------------------------
         * Read receipt
         * ---------------------------------------------------------- */
        case 'read': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $messageId = isset($input['message_id']) ? (int) $input['message_id'] : 0;
            api_chat_or_fail($chatId, $meId);

            if ($messageId <= 0) {
                $stmt = db()->prepare('SELECT COALESCE(MAX(id), 0) AS m FROM messages WHERE chat_id = ?');
                $stmt->execute([$chatId]);
                $messageId = (int) $stmt->fetch()['m'];
            } else {
                $stmt = db()->prepare('SELECT id FROM messages WHERE id = ? AND chat_id = ? LIMIT 1');
                $stmt->execute([$messageId, $chatId]);
                if (!$stmt->fetch()) {
                    $messageId = 0;
                }
            }

            if ($messageId > 0) {
                db()->prepare(
                    'UPDATE chat_members SET last_read_message_id = GREATEST(last_read_message_id, ?)
                     WHERE chat_id = ? AND user_id = ?'
                )->execute([$messageId, $chatId, $meId]);
            }

            db()->prepare('DELETE FROM typing_status WHERE chat_id = ? AND user_id = ?')->execute([$chatId, $meId]);

            json_response(['ok' => true, 'last_read' => $messageId, 'server_time' => api_now()]);
        }

        /* ----------------------------------------------------------
         * Typing indicator
         * ---------------------------------------------------------- */
        case 'typing': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);
            api_require_write($chat);

            db()->prepare(
                'INSERT INTO typing_status (chat_id, user_id, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE updated_at = NOW()'
            )->execute([$chatId, $meId]);

            if (random_int(1, 50) === 1) {
                db()->exec('DELETE FROM typing_status WHERE updated_at < (NOW() - INTERVAL 2 MINUTE)');
            }

            json_response(['ok' => true]);
        }

        /* ----------------------------------------------------------
         * Synchronization (polling)
         * ---------------------------------------------------------- */
        case 'sync': {
            $since = isset($input['since']) ? trim((string) $input['since']) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $since)) {
                $since = '1970-01-01 00:00:00';
            }

            // زمان مبنا قبل از کوئری‌ها گرفته می‌شود تا رویدادهای بین کوئری و پاسخ از دست نروند.
            $syncTime = api_now();

            // online heartbeat (at most once a minute per session)
            if (empty($_SESSION['last_seen_ping']) || (time() - (int) $_SESSION['last_seen_ping']) > 55) {
                db()->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([$meId]);
                $_SESSION['last_seen_ping'] = time();
            }

            // active chats
            $stmt = db()->prepare('SELECT chat_id FROM chat_members WHERE user_id = ?');
            $stmt->execute([$meId]);
            $activeIds = array_map(static function ($r) { return (int) $r['chat_id']; }, $stmt->fetchAll());

            // updated chats
            $stmt = db()->prepare(
                'SELECT c.*, cm.role, cm.last_read_message_id, cm.muted
                 FROM chats c
                 JOIN chat_members cm ON cm.chat_id = c.id
                 WHERE cm.user_id = ? AND c.updated_at >= ?
                 ORDER BY c.updated_at ASC
                 LIMIT 100'
            );
            $stmt->execute([$meId, $since]);
            $updatedChats = [];
            foreach ($stmt->fetchAll() as $chatRow) {
                $updatedChats[] = rc_chat_meta_for_user($chatRow, $meId);
            }

            // read map (other members)
            $stmt = db()->prepare(
                'SELECT cm.chat_id, MAX(cm.last_read_message_id) AS last_read
                 FROM chat_members cm
                 JOIN chat_members mine ON mine.chat_id = cm.chat_id AND mine.user_id = ?
                 WHERE cm.user_id <> ?
                 GROUP BY cm.chat_id'
            );
            $stmt->execute([$meId, $meId]);
            $reads = [];
            foreach ($stmt->fetchAll() as $r) {
                $reads[(int) $r['chat_id']] = (int) $r['last_read'];
            }

            // updated messages
            $stmt = db()->prepare(
                'SELECT m.*, c.type AS chat_type
                 FROM messages m
                 JOIN chats c ON c.id = m.chat_id
                 JOIN chat_members cm ON cm.chat_id = m.chat_id AND cm.user_id = ?
                 WHERE m.updated_at >= ?
                 ORDER BY m.updated_at ASC, m.id ASC
                 LIMIT 500'
            );
            $stmt->execute([$meId, $since]);
            $messageRows = $stmt->fetchAll();

            $messageIds = array_map(static function ($r) { return (int) $r['id']; }, $messageRows);
            $reactions = rc_reactions_for_messages($messageIds, $meId);

            $messages = [];
            foreach ($messageRows as $row) {
                $chatId = (int) $row['chat_id'];
                $payload = rc_message_payload(
                    $row,
                    ['type' => $row['chat_type']],
                    $meId,
                    $reads[$chatId] ?? 0,
                    $reactions[(int) $row['id']] ?? []
                );
                $payload['chat_id'] = $chatId;
                $messages[] = $payload;
            }

            // typing
            $stmt = db()->prepare(
                'SELECT t.chat_id, t.user_id
                 FROM typing_status t
                 JOIN chat_members cm ON cm.chat_id = t.chat_id AND cm.user_id = ?
                 WHERE t.user_id <> ? AND t.updated_at >= (NOW() - INTERVAL 5 SECOND)'
            );
            $stmt->execute([$meId, $meId]);
            $typing = [];
            foreach ($stmt->fetchAll() as $t) {
                $typing[(int) $t['chat_id']][] = (int) $t['user_id'];
            }

            // users in shared chats + newly registered users
            $stmt = db()->prepare(
                'SELECT DISTINCT u.id, u.name, u.username, u.avatar_hue, u.avatar_path, u.last_seen_at, u.bio, u.phone
                 FROM users u
                 JOIN chat_members cm ON cm.user_id = u.id
                 WHERE cm.chat_id IN (SELECT chat_id FROM chat_members WHERE user_id = ?)
                   AND u.id <> ?'
            );
            $stmt->execute([$meId, $meId]);
            $userRows = $stmt->fetchAll();

            $stmt = db()->prepare(
                'SELECT id, name, username, avatar_hue, avatar_path, last_seen_at, bio, phone
                 FROM users WHERE id <> ? AND created_at >= ?'
            );
            $stmt->execute([$meId, $since]);
            foreach ($stmt->fetchAll() as $u) {
                $userRows[] = $u;
            }

            $users = [];
            foreach ($userRows as $u) {
                $users[(int) $u['id']] = rc_user_public($u);
            }

            // new stories (other users)
            $stmt = db()->prepare(
                'SELECT s.*, u.name, u.avatar_path FROM stories s
                 JOIN users u ON u.id = s.user_id
                 WHERE s.created_at >= ? AND s.expires_at > NOW() AND s.user_id <> ?
                 ORDER BY s.created_at ASC LIMIT 30'
            );
            $stmt->execute([$since, $meId]);
            $stories = [];
            foreach ($stmt->fetchAll() as $s) {
                $stories[] = [
                    'user' => (int) $s['user_id'],
                    'avatar' => rc_media_url($s['avatar_path'] ?? null),
                    'item' => rc_story_item($s),
                ];
            }

            json_response([
                'ok' => true,
                'server_time' => $syncTime,
                'active_chat_ids' => $activeIds,
                'chats' => $updatedChats,
                'messages' => $messages,
                'reads' => $reads,
                'typing' => $typing,
                'users' => $users,
                'stories' => $stories,
            ]);
        }

        /* ----------------------------------------------------------
         * Open / create private chat
         * ---------------------------------------------------------- */
        case 'open_private': {
            $otherId = (int) ($input['user_id'] ?? 0);
            if ($otherId === $meId) {
                $saved = rc_saved_chat($meId);
                json_response([
                    'ok' => true,
                    'chat' => api_chat_payload((int) $saved['id'], $meId, true),
                    'server_time' => api_now(),
                ]);
            }

            $stmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$otherId]);
            if (!$stmt->fetch()) {
                api_error('کاربر پیدا نشد.', 404, 'not_found');
            }

            $chatId = rc_create_private_chat($meId, $otherId);
            json_response([
                'ok' => true,
                'chat' => api_chat_payload($chatId, $meId, true),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Chat history (for newly discovered chats)
         * ---------------------------------------------------------- */
        case 'history': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);
            json_response([
                'ok' => true,
                'chat_id' => $chatId,
                'messages' => rc_chat_messages($chatId, $chat, $meId, rc_other_read($chatId, $meId), 80),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Create group / channel
         * ---------------------------------------------------------- */
        case 'create_chat': {
            $type = (string) ($input['type'] ?? '');
            if (!in_array($type, ['group', 'channel'], true)) {
                api_error('نوع گفتگو نامعتبر است.', 422, 'invalid_type');
            }

            $name = trim((string) ($input['name'] ?? ''));
            $desc = trim(input_string($input, 'description', 255));
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($nameLength < 1 || $nameLength > 80) {
                api_error('نام باید بین ۱ تا ۸۰ کاراکتر باشد.', 422, 'invalid_name');
            }

            $memberIds = [];
            if (isset($input['member_ids']) && is_array($input['member_ids'])) {
                foreach ($input['member_ids'] as $mid) {
                    $mid = (int) $mid;
                    if ($mid > 0 && $mid !== $meId) {
                        $memberIds[$mid] = $mid;
                    }
                }
            }
            $memberIds = array_values($memberIds);
            if (count($memberIds) > 50) {
                api_error('حداکثر ۵۰ عضو قابل افزودن است.', 422, 'too_many_members');
            }

            if ($memberIds) {
                $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
                $stmt = db()->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
                $stmt->execute($memberIds);
                $valid = [];
                foreach ($stmt->fetchAll() as $u) {
                    $valid[(int) $u['id']] = true;
                }
                $memberIds = array_values(array_filter($memberIds, static function ($id) use ($valid) {
                    return isset($valid[$id]);
                }));
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO chats (type, name, description, created_by, last_message_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(3), NOW(3))'
                );
                $stmt->execute([$type, $name, $desc, $meId]);
                $chatId = (int) $pdo->lastInsertId();

                $memberStmt = $pdo->prepare('INSERT INTO chat_members (chat_id, user_id, role) VALUES (?, ?, ?)');
                $memberStmt->execute([$chatId, $meId, 'owner']);
                foreach ($memberIds as $mid) {
                    $memberStmt->execute([$chatId, $mid, $type === 'channel' ? 'subscriber' : 'member']);
                }

                $sysText = ($type === 'group' ? 'گروه' : 'کانال') . ' «' . $name . '» ساخته شد';
                $stmt = $pdo->prepare(
                    "INSERT INTO messages (chat_id, sender_id, type, text) VALUES (?, ?, 'system', ?)"
                );
                $stmt->execute([$chatId, $meId, $sysText]);
                $sysId = (int) $pdo->lastInsertId();

                $pdo->prepare('UPDATE chats SET last_message_id = ?, last_message_at = NOW(3), updated_at = NOW(3) WHERE id = ?')
                    ->execute([$sysId, $chatId]);
                $pdo->prepare('UPDATE chat_members SET last_read_message_id = ? WHERE chat_id = ?')
                    ->execute([$sysId, $chatId]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            json_response([
                'ok' => true,
                'chat' => api_chat_payload($chatId, $meId, true),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Add members to group / channel
         * ---------------------------------------------------------- */
        case 'add_members': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);

            if (!in_array($chat['type'], ['group', 'channel'], true) || !api_is_admin($chat)) {
                api_error('اجازه‌ی افزودن عضو را ندارید.', 403, 'forbidden');
            }

            $userIds = [];
            if (isset($input['user_ids']) && is_array($input['user_ids'])) {
                foreach ($input['user_ids'] as $uid) {
                    $uid = (int) $uid;
                    if ($uid > 0 && $uid !== $meId) {
                        $userIds[$uid] = $uid;
                    }
                }
            }
            $userIds = array_values($userIds);
            if (!$userIds) {
                api_error('کاربری انتخاب نشده است.', 422, 'empty');
            }

            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = db()->prepare("SELECT id FROM users WHERE id IN ($placeholders)");
            $stmt->execute($userIds);
            $validUsers = [];
            foreach ($stmt->fetchAll() as $u) {
                $validUsers[(int) $u['id']] = true;
            }

            $stmt = db()->prepare('SELECT user_id FROM chat_members WHERE chat_id = ?');
            $stmt->execute([$chatId]);
            $existing = [];
            foreach ($stmt->fetchAll() as $m) {
                $existing[(int) $m['user_id']] = true;
            }

            $lastRead = (int) ($chat['last_message_id'] ?? 0);
            $added = 0;
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare('INSERT INTO chat_members (chat_id, user_id, role, last_read_message_id) VALUES (?, ?, ?, ?)');
                foreach ($userIds as $uid) {
                    if (!isset($validUsers[$uid]) || isset($existing[$uid])) {
                        continue;
                    }
                    $insert->execute([$chatId, $uid, $chat['type'] === 'channel' ? 'subscriber' : 'member', $lastRead]);
                    $added++;
                }
                $pdo->prepare('UPDATE chats SET updated_at = NOW(3) WHERE id = ?')->execute([$chatId]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            json_response([
                'ok' => true,
                'added' => $added,
                'chat' => api_chat_payload($chatId, $meId, false),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Leave chat
         * ---------------------------------------------------------- */
        case 'leave': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);

            if ($chat['type'] === 'saved') {
                api_error('پیام‌های ذخیره‌شده قابل ترک نیستند.', 422, 'saved_chat');
            }

            $pdo = db();
            $filesToRemove = [];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM chat_members WHERE chat_id = ? AND user_id = ?')->execute([$chatId, $meId]);
                $pdo->prepare('DELETE FROM typing_status WHERE chat_id = ? AND user_id = ?')->execute([$chatId, $meId]);

                $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM chat_members WHERE chat_id = ?');
                $stmt->execute([$chatId]);
                $remaining = (int) $stmt->fetch()['c'];

                if ($remaining === 0) {
                    // آخرین عضو رفته است؛ گفتگو و فایل‌هایش پاک می‌شوند.
                    $stmt = $pdo->prepare('SELECT file_path FROM messages WHERE chat_id = ? AND file_path IS NOT NULL');
                    $stmt->execute([$chatId]);
                    foreach ($stmt->fetchAll() as $f) {
                        $filesToRemove[] = (string) $f['file_path'];
                    }
                    $pdo->prepare('DELETE FROM chats WHERE id = ?')->execute([$chatId]);
                } else {
                    if (in_array($chat['type'], ['group', 'channel'], true) && $chat['role'] === 'owner') {
                        $stmt = $pdo->prepare(
                            "SELECT user_id FROM chat_members WHERE chat_id = ?
                             ORDER BY FIELD(role, 'admin', 'member', 'subscriber'), joined_at ASC LIMIT 1"
                        );
                        $stmt->execute([$chatId]);
                        $next = $stmt->fetch();
                        if ($next) {
                            $pdo->prepare('UPDATE chat_members SET role = ? WHERE chat_id = ? AND user_id = ?')
                                ->execute(['owner', $chatId, (int) $next['user_id']]);
                        }
                    }
                    $pdo->prepare('UPDATE chats SET updated_at = NOW(3) WHERE id = ?')->execute([$chatId]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            foreach ($filesToRemove as $filePath) {
                api_remove_upload($filePath);
            }

            json_response(['ok' => true, 'server_time' => api_now()]);
        }

        /* ----------------------------------------------------------
         * Chat settings: pin / mute / admins only
         * ---------------------------------------------------------- */
        case 'chat_settings': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);

            if (array_key_exists('pin', $input)) {
                if (!api_is_admin($chat) && $chat['type'] !== 'private') {
                    api_error('فقط مدیران می‌توانند پیام سنجاق کنند.', 403, 'forbidden');
                }
                $pinId = $input['pin'] !== null && $input['pin'] !== '' ? (int) $input['pin'] : null;
                if ($pinId !== null) {
                    $stmt = db()->prepare('SELECT id FROM messages WHERE id = ? AND chat_id = ? AND deleted_at IS NULL LIMIT 1');
                    $stmt->execute([$pinId, $chatId]);
                    if (!$stmt->fetch()) {
                        api_error('پیام سنجاق‌شده پیدا نشد.', 404, 'not_found');
                    }
                }
                db()->prepare('UPDATE chats SET pinned_message_id = ?, updated_at = NOW(3) WHERE id = ?')
                    ->execute([$pinId, $chatId]);
            }

            if (array_key_exists('muted', $input)) {
                db()->prepare('UPDATE chat_members SET muted = ? WHERE chat_id = ? AND user_id = ?')
                    ->execute([!empty($input['muted']) ? 1 : 0, $chatId, $meId]);
            }

            if (array_key_exists('admins_only', $input)) {
                if ($chat['type'] !== 'group' || !api_is_admin($chat)) {
                    api_error('اجازه‌ی تغییر این تنظیم را ندارید.', 403, 'forbidden');
                }
                db()->prepare('UPDATE chats SET admins_only = ?, updated_at = NOW(3) WHERE id = ?')
                    ->execute([!empty($input['admins_only']) ? 1 : 0, $chatId]);
            }

            json_response([
                'ok' => true,
                'chat' => api_chat_payload($chatId, $meId, false),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Profile
         * ---------------------------------------------------------- */
        case 'update_profile': {
            $name = trim((string) ($input['name'] ?? ''));
            $username = strtolower(trim((string) ($input['username'] ?? '')));
            $bio = trim(input_string($input, 'bio', 190));
            $phone = trim(input_string($input, 'phone', 32));

            $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
            if ($nameLength < 2 || $nameLength > 60) {
                api_error('نام باید بین ۲ تا ۶۰ کاراکتر باشد.', 422, 'invalid_name');
            }
            if (!preg_match('/^[a-z0-9_]{3,24}$/', $username)) {
                api_error('نام کاربری باید ۳ تا ۲۴ کاراکتر و شامل حروف انگلیسی، عدد یا _ باشد.', 422, 'invalid_username');
            }

            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1');
            $stmt->execute([$username, $meId]);
            if ($stmt->fetch()) {
                api_error('این نام کاربری قبلاً گرفته شده است.', 409, 'username_taken');
            }

            $stmt = db()->prepare('UPDATE users SET name = ?, username = ?, bio = ?, phone = ? WHERE id = ?');
            $stmt->execute([$name, $username, $bio, $phone, $meId]);

            json_response([
                'ok' => true,
                'me' => api_me_payload($meId),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Search users
         * ---------------------------------------------------------- */
        case 'search_users': {
            $q = trim((string) ($input['q'] ?? ''));
            if (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') < 2 : strlen($q) < 2) {
                json_response(['ok' => true, 'users' => []]);
            }
            $like = '%' . $q . '%';
            $stmt = db()->prepare(
                'SELECT id, name, username, avatar_hue, avatar_path, last_seen_at, bio, phone
                 FROM users
                 WHERE id <> ? AND (name LIKE ? OR username LIKE ?)
                 ORDER BY last_seen_at DESC LIMIT 20'
            );
            $stmt->execute([$meId, $like, $like]);
            $users = [];
            foreach ($stmt->fetchAll() as $u) {
                $users[] = rc_user_public($u);
            }
            json_response(['ok' => true, 'users' => $users]);
        }

        /* ----------------------------------------------------------
         * Profile avatar (converted to WebP on the server)
         * ---------------------------------------------------------- */
        case 'upload_avatar': {
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                api_error('فایلی ارسال نشده است.', 422, 'no_file');
            }

            try {
                $stored = rc_store_webp_image($_FILES['file'], 'avatars', 512, 512, true, 84, MAX_UPLOAD_BYTES);
            } catch (Throwable $e) {
                api_error($e->getMessage(), 422, 'invalid_image');
            }

            $old = (string) ($me['avatar_path'] ?? '');
            try {
                db()->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')
                    ->execute([$stored['path'], $meId]);
            } catch (Throwable $e) {
                rc_media_delete($stored['path']);
                throw $e;
            }

            if ($old !== '' && $old !== $stored['path']) {
                rc_media_delete($old);
            }

            json_response([
                'ok' => true,
                'avatar' => rc_media_url($stored['path']),
                'me' => api_me_payload($meId),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Group / channel avatar (converted to WebP on the server)
         * ---------------------------------------------------------- */
        case 'upload_chat_avatar': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);
            if (!in_array((string) $chat['type'], ['group', 'channel'], true) || !api_is_admin($chat)) {
                api_error('اجازه‌ی تغییر عکس این گفتگو را ندارید.', 403, 'forbidden');
            }
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                api_error('فایلی ارسال نشده است.', 422, 'no_file');
            }

            try {
                $stored = rc_store_webp_image($_FILES['file'], 'chats', 512, 512, true, 84, MAX_UPLOAD_BYTES);
            } catch (Throwable $e) {
                api_error($e->getMessage(), 422, 'invalid_image');
            }

            $old = (string) ($chat['avatar_path'] ?? '');
            try {
                db()->prepare('UPDATE chats SET avatar_path = ?, updated_at = NOW(3) WHERE id = ?')
                    ->execute([$stored['path'], $chatId]);
            } catch (Throwable $e) {
                rc_media_delete($stored['path']);
                throw $e;
            }

            if ($old !== '' && $old !== $stored['path']) {
                rc_media_delete($old);
            }

            json_response([
                'ok' => true,
                'avatar' => rc_media_url($stored['path']),
                'chat' => api_chat_payload($chatId, $meId, false),
                'server_time' => api_now(),
            ]);
        }

        /* ----------------------------------------------------------
         * Upload image / file
         * ---------------------------------------------------------- */
        case 'upload': {
            $chatId = (int) ($input['chat_id'] ?? 0);
            $chat = api_chat_or_fail($chatId, $meId);
            api_require_write($chat);

            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                api_error('فایلی ارسال نشده است.', 422, 'no_file');
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                api_error('آپلود فایل ناموفق بود (کد ' . (int) ($file['error'] ?? -1) . ').', 422, 'upload_error');
            }
            $size = (int) ($file['size'] ?? 0);
            if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
                api_error('حجم فایل باید حداکثر ' . rc_human_size(MAX_UPLOAD_BYTES) . ' باشد.', 422, 'too_large');
            }

            $original = (string) ($file['name'] ?? 'file');
            $original = basename(str_replace('\\', '/', $original));
            $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $original);
            if (is_string($cleaned)) {
                $original = $cleaned;
            }
            if ($original === '') {
                $original = 'file';
            }
            if (function_exists('mb_substr')) {
                $original = mb_substr($original, 0, 120, 'UTF-8');
            }
            $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

            $allowed = [
                'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
                'pdf' => 'file', 'zip' => 'file', 'txt' => 'file', 'doc' => 'file', 'docx' => 'file',
                'xls' => 'file', 'xlsx' => 'file', 'ppt' => 'file', 'pptx' => 'file',
                'mp3' => 'file', 'm4a' => 'file', 'ogg' => 'file', 'mp4' => 'file',
            ];
            if (!isset($allowed[$ext])) {
                api_error('فرمت فایل مجاز نیست.', 422, 'invalid_ext');
            }

            $kind = $allowed[$ext];
            if ($kind === 'image') {
                $info = @getimagesize($file['tmp_name']);
                if ($info === false) {
                    api_error('تصویر معتبر نیست.', 422, 'invalid_image');
                }
            }

            $subDir = date('Y/m');
            $targetDir = UPLOAD_DIR . '/' . $subDir;
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                api_error('پوشه‌ی آپلود قابل ساخت نیست.', 500, 'mkdir_failed');
            }

            $stored = bin2hex(random_bytes(16)) . '.' . $ext;
            $relative = $subDir . '/' . $stored;
            $target = $targetDir . '/' . $stored;

            if (!move_uploaded_file($file['tmp_name'], $target)) {
                api_error('ذخیره‌ی فایل ناموفق بود.', 500, 'move_failed');
            }
            @chmod($target, 0644);

            $caption = trim((string) ($input['caption'] ?? ''));
            if (function_exists('mb_strlen') && mb_strlen($caption, 'UTF-8') > 1000) {
                $caption = mb_substr($caption, 0, 1000, 'UTF-8');
            }

            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                    $mime = (string) finfo_file($fi, $target);
                    finfo_close($fi);
                }
            }

            $pdo = db();
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO messages (chat_id, sender_id, type, text, file_path, file_name, file_size, file_mime)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $chatId, $meId, $kind, $caption !== '' ? $caption : null,
                    $relative, $original, $size, $mime !== '' ? $mime : null,
                ]);
                $messageId = (int) $pdo->lastInsertId();

                $pdo->prepare('UPDATE chats SET last_message_id = ?, last_message_at = NOW(3), updated_at = NOW(3) WHERE id = ?')
                    ->execute([$messageId, $chatId]);
                $pdo->prepare('UPDATE chat_members SET last_read_message_id = ? WHERE chat_id = ? AND user_id = ?')
                    ->execute([$messageId, $chatId, $meId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                @unlink($target);
                throw $e;
            }

            $row = api_message_row($messageId);
            $payload = rc_message_payload($row, $chat, $meId, rc_other_read($chatId, $meId), []);
            $payload['chat_id'] = $chatId;

            json_response(['ok' => true, 'message' => $payload, 'server_time' => api_now()]);
        }

        /* ----------------------------------------------------------
         * Stories
         * ---------------------------------------------------------- */
        case 'story': {
            $text = trim((string) ($input['text'] ?? ''));
            $bg = trim(input_string($input, 'bg', 150));
            $ink = trim(input_string($input, 'ink', 20));
            $mediaType = trim((string) ($input['media_type'] ?? ''));
            $hasMedia = isset($_FILES['media']) && is_array($_FILES['media'])
                && (int) ($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            $textLength = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($textLength > 190) {
                api_error('متن استوری باید حداکثر ۱۹۰ کاراکتر باشد.', 422, 'invalid_text');
            }
            if (!$hasMedia && $textLength < 1) {
                api_error('متن یا عکس/ویدیوی استوری لازم است.', 422, 'invalid_text');
            }
            if ($hasMedia && !in_array($mediaType, ['image', 'video'], true)) {
                api_error('نوع رسانه‌ی استوری نامعتبر است.', 422, 'invalid_media_type');
            }

            if ($bg === '') {
                $bg = $hasMedia ? '#0b0b0b' : 'linear-gradient(160deg,#1f7a6d,#0f3d37)';
            }

            $mediaPath = null;
            $mediaMime = null;
            $mediaSize = null;
            $mediaDuration = null;
            $stored = null;

            try {
                if ($hasMedia) {
                    if ($mediaType === 'image') {
                        $stored = rc_store_webp_image(
                            $_FILES['media'],
                            'stories/' . date('Y/m'),
                            1080,
                            1920,
                            false,
                            82,
                            MAX_STORY_BYTES
                        );
                        $mediaMime = 'image/webp';
                    } else {
                        $duration = isset($input['duration']) ? (float) $input['duration'] : 0;
                        if ($duration <= 0 || $duration > 15.05) {
                            api_error('مدت ویدیوی استوری باید حداکثر ۱۵ ثانیه باشد.', 422, 'video_too_long');
                        }
                        $stored = rc_store_webm_upload($_FILES['media'], MAX_STORY_BYTES);
                        $mediaMime = 'video/webm';
                        $mediaDuration = round($duration, 2);
                    }

                    $mediaPath = $stored['path'];
                    $mediaSize = (int) $stored['size'];
                }

                $stmt = db()->prepare(
                    'INSERT INTO stories
                        (user_id, bg, ink, text, media_type, media_path, media_mime, media_size, media_duration, expires_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
                );
                $stmt->execute([
                    $meId,
                    $bg,
                    $ink !== '' ? $ink : null,
                    $text,
                    $hasMedia ? $mediaType : null,
                    $mediaPath,
                    $mediaMime,
                    $mediaSize,
                    $mediaDuration,
                ]);
            } catch (Throwable $e) {
                if ($stored && !empty($stored['path'])) {
                    rc_media_delete($stored['path']);
                }
                throw $e;
            }

            $storyId = (int) db()->lastInsertId();
            $row = [
                'id' => $storyId,
                'bg' => $bg,
                'ink' => $ink !== '' ? $ink : null,
                'text' => $text,
                'created_at' => rc_now_sql(),
                'views' => 0,
                'media_type' => $hasMedia ? $mediaType : null,
                'media_path' => $mediaPath,
                'media_mime' => $mediaMime,
                'media_duration' => $mediaDuration,
            ];

            json_response([
                'ok' => true,
                'story' => rc_story_item($row),
                'server_time' => api_now(),
            ]);
        }

        case 'story_view': {
            $userId = (int) ($input['user_id'] ?? 0);
            if ($userId <= 0 || $userId === $meId) {
                json_response(['ok' => true]);
            }
            $stmt = db()->prepare(
                'INSERT IGNORE INTO story_views (story_id, user_id)
                 SELECT id, ? FROM stories WHERE user_id = ? AND expires_at > NOW()'
            );
            $stmt->execute([$meId, $userId]);
            json_response(['ok' => true]);
        }

        default:
            api_error('عملیات ناشناخته است.', 404, 'unknown_action');
    }
} catch (Throwable $e) {
    rc_log('API ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_error('خطای داخلی سرور. لطفاً دوباره تلاش کنید.', 500, 'server_error');
}
