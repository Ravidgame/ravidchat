<?php
/**
 * auth.php
 * ورود و ثبت‌نام
 */

require_once __DIR__ . '/includes/data.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$mode = 'login';
$error = '';
$oldName = '';
$oldUsername = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $mode = ($_POST['action'] ?? '') === 'register' ? 'register' : 'login';
    $oldName = trim((string) ($_POST['name'] ?? ''));
    $oldUsername = strtolower(trim((string) ($_POST['username'] ?? '')));

    if (!csrf_is_valid($_POST['_csrf'] ?? null)) {
        $error = 'نشست شما منقضی شده است. صفحه را دوباره بارگذاری کنید.';
    } elseif ($mode === 'register') {
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');

        $nameLength = function_exists('mb_strlen') ? mb_strlen($oldName, 'UTF-8') : strlen($oldName);
        if ($nameLength < 2 || $nameLength > 60) {
            $error = 'نام نمایشی باید بین ۲ تا ۶۰ کاراکتر باشد.';
        } elseif (!preg_match('/^[a-z0-9_]{3,24}$/', $oldUsername)) {
            $error = 'نام کاربری باید ۳ تا ۲۴ کاراکتر و فقط شامل حروف انگلیسی، عدد یا _ باشد.';
        } elseif (strlen($password) < 8) {
            $error = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        } elseif (strlen($password) > 72) {
            $error = 'رمز عبور نباید بیشتر از ۷۲ کاراکتر باشد.';
        } elseif ($password !== $confirm) {
            $error = 'تکرار رمز عبور مطابقت ندارد.';
        } else {
            $stmt = db()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$oldUsername]);
            if ($stmt->fetch()) {
                $error = 'این نام کاربری قبلاً گرفته شده است.';
            } else {
                try {
                    $hue = random_int(0, 359);
                    $stmt = db()->prepare(
                        'INSERT INTO users (username, name, password_hash, avatar_hue, last_seen_at)
                         VALUES (?, ?, ?, ?, NOW())'
                    );
                    $stmt->execute([
                        $oldUsername,
                        $oldName,
                        password_hash($password, PASSWORD_DEFAULT),
                        $hue,
                    ]);
                    $newId = (int) db()->lastInsertId();
                    rc_saved_chat($newId);
                    login_user($newId);
                    header('Location: index.php');
                    exit;
                } catch (Throwable $e) {
                    rc_log('register: ' . $e->getMessage());
                    $error = 'ساخت حساب ناموفق بود. لطفاً دوباره تلاش کنید.';
                }
            }
        }
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if ($oldUsername === '' || $password === '') {
            $error = 'نام کاربری و رمز عبور را وارد کنید.';
        } elseif (rc_too_many_login_attempts($oldUsername)) {
            $error = 'تلاش‌های ناموفق زیاد بوده است. ۱۵ دقیقه بعد دوباره تلاش کنید.';
        } else {
            $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$oldUsername]);
            $user = $stmt->fetch();

            // برای جلوگیری از افشای وجود کاربر از طریق زمان پاسخ
            $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $hash = $user ? (string) $user['password_hash'] : $dummyHash;
            $ok = password_verify($password, $hash);

            if ($user && $ok) {
                rc_record_login_attempt($oldUsername, true);
                db()->prepare('UPDATE users SET last_seen_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
                login_user((int) $user['id']);
                header('Location: index.php');
                exit;
            }

            rc_record_login_attempt($oldUsername, false);
            $error = 'نام کاربری یا رمز عبور اشتباه است.';
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>ورود | Ravidchat</title>
    <meta name="theme-color" content="#0f3d37">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <style>
        :root {
            --acc: #1f7a6d;
            --acc-2: #2a9d8f;
            --ink: #102a27;
            --muted: #5b716d;
            --line: #dbe6e3;
            --panel: #ffffff;
            --bg: #eef4f2;
            --danger: #b23a3a;
        }

        * { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            font-family: Vazirmatn, Tahoma, "Segoe UI", Arial, sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at 15% 15%, rgba(42, 157, 143, .28), transparent 38%),
                radial-gradient(circle at 85% 85%, rgba(31, 122, 109, .25), transparent 40%),
                linear-gradient(145deg, #0c2b27, #123f39 55%, #0a2320);
        }

        .shell {
            width: 100%;
            max-width: 940px;
            min-height: 560px;
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            background: var(--panel);
            border-radius: 26px;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .45);
        }

        .brand {
            position: relative;
            padding: 46px 40px;
            color: #eaf6f3;
            background:
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='60' height='60' viewBox='0 0 60 60'%3E%3Cpath d='M30 4 37 23 56 30 37 37 30 56 23 37 4 30 23 23Z' fill='none' stroke='%23ffffff' stroke-opacity='.10' stroke-width='1.4'/%3E%3C/svg%3E"),
                linear-gradient(160deg, #0f3d37, #1f7a6d);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .logo i {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-style: normal;
            font-weight: 800;
            font-size: 1.4rem;
            color: #0f3d37;
            background: #eaf6f3;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
        }

        .brand h1 {
            margin: 0 0 14px;
            font-size: 2rem;
            line-height: 1.55;
        }

        .brand p {
            margin: 0;
            color: rgba(234, 246, 243, .78);
            line-height: 2;
            font-size: .95rem;
        }

        .brand ul {
            margin: 26px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
            font-size: .9rem;
            color: rgba(234, 246, 243, .86);
        }

        .brand li::before {
            content: "✓";
            display: inline-grid;
            place-items: center;
            width: 20px;
            height: 20px;
            margin-left: 8px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .14);
            font-size: .72rem;
        }

        .brand footer {
            font-size: .78rem;
            color: rgba(234, 246, 243, .55);
            margin-top: 30px;
        }

        .form-side {
            padding: 38px 34px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: #fbfdfc;
        }

        .tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            padding: 5px;
            border-radius: 14px;
            background: #e9f1ef;
            margin-bottom: 26px;
        }

        .tabs button {
            border: 0;
            padding: 11px 8px;
            border-radius: 10px;
            background: transparent;
            font: inherit;
            font-weight: 600;
            color: var(--muted);
            cursor: pointer;
            transition: .18s;
        }

        .tabs button.on {
            background: #fff;
            color: var(--acc);
            box-shadow: 0 6px 18px rgba(16, 42, 39, .09);
        }

        .pane { display: none; }
        .pane.on { display: block; }

        .pane h2 {
            margin: 0 0 6px;
            font-size: 1.35rem;
        }

        .pane > p {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: .86rem;
            line-height: 1.9;
        }

        .field {
            display: block;
            margin-bottom: 15px;
        }

        .field span {
            display: block;
            font-size: .8rem;
            font-weight: 600;
            color: var(--muted);
            margin-bottom: 7px;
        }

        .field input {
            width: 100%;
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 12px;
            padding: 12px 14px;
            font: inherit;
            font-size: 16px;
            color: var(--ink);
            outline: none;
            transition: border-color .18s, box-shadow .18s;
        }

        .field input:focus {
            border-color: var(--acc-2);
            box-shadow: 0 0 0 4px rgba(42, 157, 143, .14);
        }

        .field small {
            display: block;
            margin-top: 6px;
            font-size: .72rem;
            color: #8aa09c;
        }

        .btn {
            width: 100%;
            border: 0;
            border-radius: 12px;
            padding: 13px 16px;
            margin-top: 8px;
            font: inherit;
            font-size: .98rem;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            background: linear-gradient(135deg, var(--acc), var(--acc-2));
            box-shadow: 0 10px 24px rgba(31, 122, 109, .28);
            transition: transform .15s, box-shadow .15s;
        }

        .btn:hover { transform: translateY(-1px); box-shadow: 0 14px 28px rgba(31, 122, 109, .34); }
        .btn:active { transform: translateY(0); }

        .error {
            margin: 0 0 18px;
            padding: 11px 14px;
            border-radius: 11px;
            background: #fdecec;
            color: #8f2b2b;
            font-size: .84rem;
            line-height: 1.9;
            border: 1px solid #f5cfcf;
        }

        .form-side footer {
            margin-top: 22px;
            text-align: center;
            font-size: .72rem;
            color: #93a7a3;
            line-height: 1.9;
        }

        @media (max-width: 760px) {
            body { padding: 14px; }
            .shell { grid-template-columns: 1fr; min-height: 0; }
            .brand { padding: 28px 24px; }
            .brand h1 { font-size: 1.4rem; }
            .brand ul, .brand footer { display: none; }
            .form-side { padding: 26px 22px 30px; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <section class="brand">
            <div>
                <div class="logo"><i>R</i> Ravidchat</div>
                <h1 style="margin-top:34px">گفتگو، ساده و امن.</h1>
                <p>پیام‌های خصوصی، گروه‌ها و کانال‌ها — همه در یک فضای تمیز و سریع.</p>
                <ul>
                    <li>گفتگوی خصوصی و گروهی</li>
                    <li>ارسال عکس و فایل</li>
                    <li>ویرایش و حذف پیام</li>
                    <li>وضعیت آنلاین و تیک خوانده‌شدن</li>
                </ul>
            </div>
            <footer>Ravidchat · نسخه ۰٫۱ آلفا</footer>
        </section>

        <section class="form-side">
            <?php if ($error !== ''): ?>
                <div class="error"><?= e($error) ?></div>
            <?php endif; ?>

            <div class="tabs">
                <button type="button" class="<?= $mode === 'login' ? 'on' : '' ?>" data-tab="login">ورود</button>
                <button type="button" class="<?= $mode === 'register' ? 'on' : '' ?>" data-tab="register">ثبت‌نام</button>
            </div>

            <div class="pane <?= $mode === 'login' ? 'on' : '' ?>" data-pane="login">
                <h2>خوش برگشتی</h2>
                <p>برای دیدن پیام‌ها وارد حساب خودت شو.</p>
                <form method="post" action="auth.php" autocomplete="on">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="login">
                    <label class="field">
                        <span>نام کاربری</span>
                        <input type="text" name="username" dir="ltr" required maxlength="24"
                               value="<?= e($mode === 'login' ? $oldUsername : '') ?>" autocomplete="username">
                    </label>
                    <label class="field">
                        <span>رمز عبور</span>
                        <input type="password" name="password" required autocomplete="current-password">
                    </label>
                    <button class="btn" type="submit">ورود به حساب</button>
                </form>
            </div>

            <div class="pane <?= $mode === 'register' ? 'on' : '' ?>" data-pane="register">
                <h2>ساخت حساب جدید</h2>
                <p>چند ثانیه وقت بگذار و وارد گفتگو شو.</p>
                <form method="post" action="auth.php" autocomplete="on">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="register">
                    <label class="field">
                        <span>نام نمایشی</span>
                        <input type="text" name="name" required maxlength="60" value="<?= e($mode === 'register' ? $oldName : '') ?>">
                    </label>
                    <label class="field">
                        <span>نام کاربری</span>
                        <input type="text" name="username" dir="ltr" required maxlength="24" pattern="[a-z0-9_]{3,24}"
                               value="<?= e($mode === 'register' ? $oldUsername : '') ?>" autocomplete="username">
                        <small>فقط حروف انگلیسی کوچک، عدد و آندرلاین · مثال: ravid_01</small>
                    </label>
                    <label class="field">
                        <span>رمز عبور</span>
                        <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password">
                    </label>
                    <label class="field">
                        <span>تکرار رمز عبور</span>
                        <input type="password" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password">
                    </label>
                    <button class="btn" type="submit">ساخت حساب</button>
                </form>
            </div>

            <footer>با ورود، قوانین استفاده از سرویس را می‌پذیرید.</footer>
        </section>
    </div>

    <script>
        document.querySelectorAll('[data-tab]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.querySelectorAll('[data-tab]').forEach(function (b) { b.classList.toggle('on', b === btn); });
                document.querySelectorAll('[data-pane]').forEach(function (p) { p.classList.toggle('on', p.dataset.pane === btn.dataset.tab); });
            });
        });
    </script>
</body>
</html>
