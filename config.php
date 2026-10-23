<?php
/**
 * config.php
 * تنظیمات اصلی پروژه Ravidchat
 */

// ---------------- Database ----------------
define('DB_HOST', 'sql202.m.ihweb.ir');
define('DB_NAME', 'ihw0_43247552_site');
define('DB_USER', 'ihw0_43247552');
define('DB_PASS', '7c6b3e05a5a17f7');
define('DB_CHARSET', 'utf8mb4');

// ---------------- Application ----------------
define('APP_NAME', 'Ravidchat');
define('APP_TZ', 'Asia/Tehran');
define('APP_DEBUG', false);

// برای هش کردن IP در جدول تلاش‌های ورود
define('APP_SALT', 'rc_salt_7d2f9a4c6e8b1d3f5a7c9e0b2d4f6a8c');

// توکن اجرای install.php — بعد از نصب این فایل را حذف کنید
define('INSTALL_TOKEN', 'rc_install_9f3a7c1e5b2d4a6f8c0e');

// ---------------- Uploads ----------------
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');
define('MAX_UPLOAD_BYTES', 8 * 1024 * 1024); // 8 MB
define('MAX_STORY_BYTES', 18 * 1024 * 1024); // 18 MB
