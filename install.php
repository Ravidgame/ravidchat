<?php
/**
 * install.php
 * نصب اولیه‌ی جدول‌های دیتابیس — فقط با توکن.
 * بعد از نصب این فایل را از هاست حذف کنید.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($token === '' || !hash_equals(INSTALL_TOKEN, $token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "403 — دسترسی غیرمجاز. توکن نصب صحیح نیست.\n";
    exit;
}

$messages = [];
$errors = [];
$okCount = 0;

$phpVersion = PHP_VERSION;
$mysqlVersion = '—';
$pdoDrivers = implode(', ', PDO::getAvailableDrivers());
$serverInfo = [
    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
    'post_max_size' => (string) ini_get('post_max_size'),
    'max_execution_time' => (string) ini_get('max_execution_time'),
    'mbstring' => extension_loaded('mbstring') ? 'فعال' : 'غیرفعال',
    'fileinfo' => extension_loaded('fileinfo') ? 'فعال' : 'غیرفعال',
    'openssl' => extension_loaded('openssl') ? 'فعال' : 'غیرفعال',
];

try {
    $pdo = db();
    $mysqlVersion = (string) $pdo->query('SELECT VERSION() AS v')->fetch()['v'];

    $schemaPath = __DIR__ . '/schema.sql';
    if (!is_file($schemaPath)) {
        throw new RuntimeException('فایل schema.sql پیدا نشد.');
    }

    $sql = (string) file_get_contents($schemaPath);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
    $statements = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $statements[] = $part;
        }
    }

    if (!$statements) {
        throw new RuntimeException('هیچ دستور SQL پیدا نشد.');
    }

    foreach ($statements as $index => $statement) {
        try {
            $pdo->exec($statement);
            $okCount++;
            $messages[] = 'دستور ' . ($index + 1) . ' با موفقیت اجرا شد.';
        } catch (Throwable $e) {
            $errors[] = 'دستور ' . ($index + 1) . ': ' . $e->getMessage();
        }
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $counts = [];
    foreach (['users', 'chats', 'chat_members', 'messages', 'reactions', 'typing_status', 'stories', 'story_views'] as $table) {
        if (in_array($table, $tables, true)) {
            try {
                $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            } catch (Throwable $e) {
                $counts[$table] = -1;
            }
        }
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

function rc_install_e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>نصب Ravidchat</title>
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: #eef4f2; color: #17322e; margin: 0; padding: 28px; line-height: 1.9; }
        .wrap { max-width: 820px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #d7e4e1; border-radius: 16px; padding: 24px; margin-bottom: 18px; box-shadow: 0 10px 30px rgba(18, 60, 53, .08); }
        h1 { font-size: 1.35rem; margin: 0 0 14px; }
        h2 { font-size: 1.05rem; margin: 0 0 12px; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th, td { text-align: right; padding: 7px 9px; border-bottom: 1px solid #edf3f1; }
        th { width: 42%; color: #5a706c; font-weight: 600; }
        .ok { color: #166e51; background: #e7f7ef; border: 1px solid #b9e8d3; padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; }
        .err { color: #8f2323; background: #fdecec; border: 1px solid #f3c5c5; padding: 12px 14px; border-radius: 10px; margin-bottom: 10px; white-space: pre-wrap; }
        .warn { color: #8a5a0a; background: #fff6df; border: 1px solid #f0d999; padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; }
        code { direction: ltr; display: inline-block; background: #f2f7f5; padding: 2px 7px; border-radius: 6px; }
        .muted { color: #6b7f7b; font-size: .82rem; }
        ul { margin: 8px 0 0; padding-right: 20px; }
        .tag { display: inline-block; background: #e7f7ef; color: #14684c; padding: 2px 9px; border-radius: 999px; font-size: .76rem; margin-right: 4px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>نصب دیتابیس Ravidchat</h1>
        <p class="muted">این صفحه فقط با توکن نصب در دسترس است. بعد از اجرای موفق، فایل <code>install.php</code> را از هاست حذف کنید.</p>

        <table>
            <tr><th>نسخه PHP</th><td><?= rc_install_e($phpVersion) ?></td></tr>
            <tr><th>نسخه MySQL / MariaDB</th><td><?= rc_install_e($mysqlVersion) ?></td></tr>
            <tr><th>درایورهای PDO</th><td><?= rc_install_e($pdoDrivers) ?></td></tr>
            <tr><th>upload_max_filesize</th><td><?= rc_install_e($serverInfo['upload_max_filesize']) ?></td></tr>
            <tr><th>post_max_size</th><td><?= rc_install_e($serverInfo['post_max_size']) ?></td></tr>
            <tr><th>max_execution_time</th><td><?= rc_install_e($serverInfo['max_execution_time']) ?></td></tr>
            <tr><th>افزونه‌ها</th><td>mbstring: <?= rc_install_e($serverInfo['mbstring']) ?> · fileinfo: <?= rc_install_e($serverInfo['fileinfo']) ?> · openssl: <?= rc_install_e($serverInfo['openssl']) ?></td></tr>
        </table>
    </div>

    <?php if ($errors): ?>
        <div class="card">
            <h2>خطاها</h2>
            <?php foreach ($errors as $error): ?>
                <div class="err"><?= rc_install_e($error) ?></div>
            <?php endforeach; ?>
            <p class="warn">اگر خطا مربوط به نسخه‌ی MySQL است، احتمالاً دیتابیس از <code>DATETIME(3)</code> یا کلیدهای خارجی پشتیبانی نمی‌کند. متن خطا را برای بررسی نگه دارید.</p>
        </div>
    <?php endif; ?>

    <?php if ($okCount > 0): ?>
        <div class="card">
            <h2>نتیجه</h2>
            <div class="ok"><?= (int) $okCount ?> دستور SQL با موفقیت اجرا شد.</div>
            <p>
                <?php foreach ($counts as $table => $count): ?>
                    <span class="tag"><?= rc_install_e($table) ?>: <?= (int) $count ?></span>
                <?php endforeach; ?>
            </p>
            <p class="warn">اکنون <code>install.php</code> را حذف کنید. برای امنیت بیشتر، فایل <code>schema.sql</code> هم می‌تواند حذف شود.</p>
            <p><a href="index.php">ورود به Ravidchat</a> · <a href="auth.php">ثبت‌نام</a></p>
        </div>
    <?php endif; ?>

    <?php if (!$errors && !$okCount): ?>
        <div class="card"><div class="err">هیچ دستوری اجرا نشد.</div></div>
    <?php endif; ?>

    <div class="card muted">
        <p>پیشنهاد: پس از نصب، یک حساب کاربری بسازید و با دو مرورگر مختلف پیام‌رسان را تست کنید.</p>
        <p>اگر آپلود فایل کار نکرد، مقادیر <code>upload_max_filesize</code> و <code>post_max_size</code> را در هاست بررسی کنید.</p>
    </div>
</div>
</body>
</html>
