<?php
/**
 * index.php
 * پوسته‌ی اصلی پیام‌رسان
 */

require_once __DIR__ . '/includes/data.php';

$me = require_login_page();
$meId = (int) $me['id'];

// اطمینان از وجود گفتگوی «پیام‌های ذخیره‌شده»
rc_saved_chat($meId);

$boot = rc_bootstrap_data($me);
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
$bootJson = json_encode($boot, $jsonFlags);
if ($bootJson === false) {
    $bootJson = '{"ok":false}';
}
?>
<!doctype html>
<html lang="fa" dir="rtl" data-theme="light" data-accent="firouzeh" data-wall="girih">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<title>Ravidchat</title>
<meta name="description" content="Ravidchat — پیام‌رسان تحت وب">
<meta name="theme-color" content="#fbfaf6">
<link rel="preconnect" href="https://cdn.jsdelivr.net">
<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
<link rel="stylesheet" href="css/app.css?v=<?= (int) @filemtime(__DIR__ . '/css/app.css') ?>">
</head>
<body>
<div class="app" id="app">
  <!-- Sidebar: stories + chat list -->
  <aside class="side" id="side">
    <header class="side-head">
      <button class="icon-btn" id="menuBtn" aria-label="menu"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg></button>
      <div class="search">
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></svg>
        <input id="search" type="search" dir="auto" autocomplete="off">
      </div>
    </header>
    <div class="stories" id="stories"></div>
    <nav class="tabs" id="tabs"></nav>
    <ul class="chat-list" id="chatList"></ul>
    <button class="fab" id="newBtn" aria-label="new"><svg viewBox="0 0 24 24"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg></button>
  </aside>

  <!-- Conversation -->
  <main class="conv wall-bg" id="conv">
    <div class="empty" id="empty">
      <div class="empty-mark">R</div>
      <p id="emptyText"></p>
      <small><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg><span id="emptyE2E"></span></small>
    </div>
    <section class="room hidden" id="room">
      <header class="room-head">
        <button class="icon-btn back" id="backBtn" aria-label="back"><svg viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></svg></button>
        <button class="room-who" id="roomWho">
          <span class="av" id="roomAv"></span>
          <span><b id="roomName"></b><small id="roomSub"></small></span>
        </button>
        <button class="icon-btn" id="roomSearch" aria-label="search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></svg></button>
        <button class="icon-btn" id="roomInfoBtn" aria-label="info"><svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg></button>
      </header>
      <div class="pinned hidden" id="pinned"></div>
      <div class="msgs" id="msgs"></div>
      <button class="down-btn" id="downBtn" aria-label="scroll down"><svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></svg></button>
      <footer class="composer" id="composer">
        <div class="reply-bar hidden" id="replyBar"><span id="replyText"></span><button class="icon-btn" id="replyX" aria-label="cancel">✕</button></div>
        <div class="comp-row">
          <button class="icon-btn" id="emojiBtn" aria-label="emoji"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M8.5 14a4 4 0 0 0 7 0M9 10h.01M15 10h.01"/></svg></button>
          <textarea id="input" rows="1" dir="auto"></textarea>
          <button class="icon-btn" id="attachBtn" aria-label="attach"><svg viewBox="0 0 24 24"><path d="m20 11-8 8a5 5 0 0 1-7-7l8-8a3.4 3.4 0 0 1 5 5l-8 8a1.8 1.8 0 0 1-2.5-2.5L15 7"/></svg></button>
          <button class="send" id="sendBtn" aria-label="send"></button>
        </div>
        <div class="emoji-pop hidden" id="emojiPop"></div>
      </footer>
      <div class="readonly hidden" id="readonly"><button id="roBtn"></button></div>
    </section>
  </main>

  <!-- Info panel -->
  <aside class="info hidden" id="info"></aside>
</div>

<div class="scrim hidden" id="scrim"></div>
<nav class="drawer" id="drawer"></nav>
<section class="settings" id="settings" aria-hidden="true"></section>

<!-- Story viewer -->
<section class="story-view hidden" id="storyView">
  <div class="sv-bars" id="svBars"></div>
  <header class="sv-head">
    <span class="av" id="svAv"></span><b id="svName"></b><small id="svTime"></small>
    <button class="icon-btn" id="svPause" aria-label="pause">❚❚</button>
    <button class="icon-btn" id="svClose" aria-label="close">✕</button>
  </header>
  <div class="sv-stage" id="svStage"></div>
  <div class="sv-tap prev" id="svPrev"></div><div class="sv-tap next" id="svNext"></div>
  <footer class="sv-foot" id="svFoot"></footer>
</section>

<div class="modal hidden" id="modal"><div class="modal-card" id="modalCard"></div></div>
<div class="ctx hidden" id="ctx"></div>
<div class="toast" id="toast" role="status"></div>

<noscript style="display:block;padding:20px;text-align:center;font-family:Tahoma">برای استفاده از Ravidchat باید جاوااسکریپت مرورگر فعال باشد.</noscript>

<!-- 1) bootstrap data  2) backend hooks  3) UI engine -->
<script>
window.RC_BOOT = <?= $bootJson ?>;
</script>
<script src="js/config.js?v=<?= (int) @filemtime(__DIR__ . '/js/config.js') ?>"></script>
<script src="js/integration.js?v=<?= (int) @filemtime(__DIR__ . '/js/integration.js') ?>"></script>
<script src="js/app.js?v=<?= (int) @filemtime(__DIR__ . '/js/app.js') ?>"></script>
</body>
</html>
