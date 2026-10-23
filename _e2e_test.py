#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""End-to-end HTTP tests for Ravidchat (not deployed)."""

from __future__ import annotations

import base64
import binascii
import json
import re
import struct
import sys
import time
import zlib
from typing import Any

import requests

BASE = "http://ravid.m.ihweb.ir/"
UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) OpenCode/2.1.1 Chrome/152.0.7977.130 Electron/44.4.5 Safari/537.36"
TEST_COOKIE = "8b855323415df9a6361897f17709c7fe"

PASSWORD = "TestPass1234"

USERS = {
    "one": {"name": "راوید تست", "username": "ravid_test"},
    "two": {"name": "آزاده تست", "username": "azade_test"},
}


def new_session() -> requests.Session:
    s = requests.Session()
    s.headers.update({
        "User-Agent": UA,
        "Accept-Language": "fa-IR,fa;q=0.9,en;q=0.8",
    })
    s.cookies.set("__test", TEST_COOKIE, domain="ravid.m.ihweb.ir")
    return s


def is_challenge(text: str) -> bool:
    return "aes.js" in text and "__test" in text and "toNumbers" in text


def get(s: requests.Session, path: str, **kwargs: Any) -> requests.Response:
    r = s.get(BASE + path, timeout=40, **kwargs)
    if is_challenge(r.text):
        raise RuntimeError(f"hosting challenge on GET {path}")
    return r


def csrf_from(html: str) -> str:
    m = re.search(r'name="_csrf"\s+value="([^"]+)"', html)
    if not m:
        raise RuntimeError("csrf not found")
    return m.group(1)


def login_or_register(s: requests.Session, name: str, username: str) -> None:
    r = get(s, "auth.php")
    token = csrf_from(r.text)
    payload = {
        "_csrf": token,
        "name": name,
        "username": username,
        "password": PASSWORD,
        "password_confirm": PASSWORD,
    }
    r = s.post(BASE + "auth.php", data={**payload, "action": "register"}, timeout=40, allow_redirects=False)
    if is_challenge(r.text):
        raise RuntimeError("hosting challenge on register")
    if r.status_code == 302:
        print(f"registered {username}")
        return
    # maybe already exists
    r = get(s, "auth.php")
    token = csrf_from(r.text)
    r = s.post(BASE + "auth.php", data={
        "_csrf": token,
        "action": "login",
        "username": username,
        "password": PASSWORD,
    }, timeout=40, allow_redirects=False)
    if is_challenge(r.text):
        raise RuntimeError("hosting challenge on login")
    if r.status_code != 302:
        raise RuntimeError(f"login failed for {username}: {r.status_code} {r.text[:300]}")


def boot(s: requests.Session) -> dict[str, Any]:
    r = get(s, "index.php")
    if "window.RC_BOOT" not in r.text:
        raise RuntimeError(f"no boot data, status={r.status_code}, url={r.url}, head={r.text[:200]}")
    m = re.search(r"window\.RC_BOOT\s*=\s*(\{.*?\});\s*</script>", r.text, re.S)
    if not m:
        raise RuntimeError("boot json not matched")
    return json.loads(m.group(1))


def raw_api(
    s: requests.Session,
    action: str,
    data: dict[str, Any] | None = None,
    csrf: str = "",
    method: str = "POST",
    **kwargs: Any,
) -> tuple[int, Any]:
    url = BASE + "api.php?action=" + action
    headers = {"Accept": "application/json"}
    if csrf:
        headers["X-CSRF-Token"] = csrf
    if method == "POST":
        r = s.post(url, json=data or {}, headers=headers, timeout=40, **kwargs)
    else:
        r = s.get(url, params=data or {}, headers=headers, timeout=40, **kwargs)
    if is_challenge(r.text):
        raise RuntimeError(f"hosting challenge on api {action}")
    try:
        # Free-hosting ad markup is appended after the JSON document.
        payload, _ = json.JSONDecoder().raw_decode(r.text.lstrip("\ufeff \r\n\t"))
    except Exception as exc:
        raise RuntimeError(f"api {action}: non-JSON status={r.status_code} ({exc}) body={r.text[:500]}")
    return r.status_code, payload


def api(
    s: requests.Session,
    action: str,
    data: dict[str, Any] | None = None,
    csrf: str = "",
    method: str = "POST",
) -> Any:
    status, payload = raw_api(s, action, data, csrf, method)
    if not payload.get("ok"):
        raise RuntimeError(f"api {action} failed status={status}: {payload}")
    return payload


def sync(s: requests.Session, csrf: str) -> dict[str, Any]:
    return api(s, "sync", {"since": "1970-01-01 00:00:00"}, csrf, "GET")


def find_chat(data: dict[str, Any], chat_id: Any) -> dict[str, Any] | None:
    for c in data.get("chats", []):
        if str(c.get("id")) == str(chat_id):
            return c
    return None


def find_message(data: dict[str, Any], message_id: Any) -> dict[str, Any] | None:
    for m in data.get("messages", []):
        if str(m.get("id")) == str(message_id):
            return m
    return None


def latest_message(data: dict[str, Any], chat_id: Any) -> dict[str, Any] | None:
    rows = [m for m in data.get("messages", []) if str(m.get("chat_id")) == str(chat_id) and not m.get("deleted")]
    return max(rows, key=lambda m: int(m["id"])) if rows else None


def send(s: requests.Session, csrf: str, chat_id: Any, text: str, **extra: Any) -> dict[str, Any]:
    for attempt in range(2):
        try:
            return api(s, "send", {"chat_id": chat_id, "text": text, **extra}, csrf)["message"]
        except RuntimeError as exc:
            if "rate_limited" in str(exc) and attempt == 0:
                print("  (rate limit, waiting 10s)")
                time.sleep(10)
                continue
            raise
    raise AssertionError("unreachable")


def png_1x1() -> bytes:
    def chunk(tag: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + tag + data + struct.pack(">I", binascii.crc32(tag + data) & 0xFFFFFFFF)

    raw = b"\x00\xff\x00\x00"
    return (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", 1, 1, 8, 2, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(raw))
        + chunk(b"IEND", b"")
    )


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


def expect_error(
    s: requests.Session,
    action: str,
    data: dict[str, Any],
    csrf: str,
    expected_status: int,
    expected_code: str | None = None,
    method: str = "POST",
) -> None:
    status, payload = raw_api(s, action, data, csrf, method)
    check(status == expected_status, f"{action}: expected {expected_status}, got {status}: {payload}")
    check(not payload.get("ok"), f"{action}: expected failure, got {payload}")
    if expected_code is not None:
        check(payload.get("code") == expected_code, f"{action}: expected code {expected_code}, got {payload}")


def main() -> int:
    s1 = new_session()
    s2 = new_session()
    login_or_register(s1, **USERS["one"])
    login_or_register(s2, **USERS["two"])
    b1 = boot(s1)
    b2 = boot(s2)
    uid1 = int(b1["me"]["uid"])
    uid2 = int(b2["me"]["uid"])
    csrf1 = b1["csrf"]
    csrf2 = b2["csrf"]
    print(f"users: {uid1} / {uid2}")

    # ------------------------------------------------------------
    # Private chat
    # ------------------------------------------------------------
    p = api(s1, "open_private", {"user_id": uid2}, csrf1)["chat"]
    pid = p["id"]
    check(p["type"] == "private", "private chat type")
    check(int(p["user"]) == uid2, "private peer")
    p2 = api(s2, "open_private", {"user_id": uid1}, csrf2)["chat"]
    check(str(p2["id"]) == str(pid), "private chat reused")

    sy2 = sync(s2, csrf2)
    check(str(pid) in [str(x) for x in sy2["active_chat_ids"]], "private active for user2")

    # mark old private messages read, so unread assertions are deterministic
    old = latest_message(sync(s2, csrf2), pid)
    if old:
        api(s2, "read", {"chat_id": pid, "message_id": old["id"]}, csrf2)

    m1 = send(s1, csrf1, pid, "سلام آزاده — تست خصوصی", temp_id="t-private-1")
    mid1 = m1["id"]
    check(m1.get("mine") is True, "sender mine")
    check(m1.get("read") is False, "unread private should not be marked read yet")

    sy2 = sync(s2, csrf2)
    incoming = find_message(sy2, mid1)
    check(incoming is not None, "private message in sync")
    check(incoming["text"] == "سلام آزاده — تست خصوصی", "private text")
    check(incoming.get("mine") is False, "recipient mine false")

    b2u = boot(s2)
    c2u = find_chat(b2u, pid)
    check(c2u is not None and c2u["unread"] >= 1, f"private unread: {c2u}")

    api(s2, "read", {"chat_id": pid, "message_id": mid1}, csrf2)
    b2r = boot(s2)
    c2r = find_chat(b2r, pid)
    check(c2r is not None and c2r["unread"] == 0, f"private read cleared: {c2r}")

    sy1 = sync(s1, csrf1)
    check(int(sy1["reads"].get(str(pid), 0)) >= int(mid1), "read receipt map")
    m_seen = find_message(sy1, mid1)
    check(m_seen is not None and m_seen["read"] is True, "sender read tick")

    api(s1, "typing", {"chat_id": pid}, csrf1)
    sy2 = sync(s2, csrf2)
    check(uid1 in sy2["typing"].get(str(pid), []), "typing indicator")

    api(s1, "edit", {"message_id": mid1, "text": "سلام آزاده — ویرایش شد"}, csrf1)
    sy2 = sync(s2, csrf2)
    edited = find_message(sy2, mid1)
    check(edited is not None and edited["text"] == "سلام آزاده — ویرایش شد", "edit text")
    check(edited.get("edited") is True, "edited flag")

    api(s2, "react", {"message_id": mid1, "emoji": "❤️"}, csrf2)
    sy1 = sync(s1, csrf1)
    reacted = find_message(sy1, mid1)
    check(reacted is not None and uid2 in reacted.get("react", {}).get("❤️", []), f"reaction map: {reacted}")

    reply = send(s1, csrf1, pid, "پاسخ تستی", reply_to=mid1, temp_id="t-private-reply")
    rid = reply["id"]
    sy2 = sync(s2, csrf2)
    reply_seen = find_message(sy2, rid)
    check(reply_seen is not None and int(reply_seen.get("reply", 0)) == int(mid1), "reply link")

    api(s1, "delete", {"message_id": rid}, csrf1)
    sy2 = sync(s2, csrf2)
    deleted = find_message(sy2, rid)
    check(deleted is not None and deleted.get("deleted") is True, "soft delete propagation")

    # non-owner cannot edit/delete in a private chat
    expect_error(s2, "edit", {"message_id": mid1, "text": "hack"}, csrf2, 403, "forbidden")
    expect_error(s2, "delete", {"message_id": mid1}, csrf2, 403, "forbidden")

    # ------------------------------------------------------------
    # Group
    # ------------------------------------------------------------
    g = api(s1, "create_chat", {
        "type": "group",
        "name": "گروه تست",
        "description": "گروه آزمایشی",
        "member_ids": [uid2],
    }, csrf1)["chat"]
    gid = g["id"]
    check(g["type"] == "group" and g["role"] == "owner", "group created as owner")
    check(any(str(x[0]) == str(uid2) and x[1] == "member" for x in g["members"]), "group initial member")

    sy2 = sync(s2, csrf2)
    g2 = find_chat(sy2, gid)
    check(g2 is not None and g2["role"] == "member", f"group seen by member: {g2}")

    gm = send(s1, csrf1, gid, "پیام گروه", temp_id="t-group-1")
    gmid = gm["id"]
    sy2 = sync(s2, csrf2)
    gmsg = find_message(sy2, gmid)
    check(gmsg is not None and int(gmsg["from"]) == uid1, "group message sender")

    gm2 = send(s2, csrf2, gid, "پاسخ عضو", temp_id="t-group-2")
    sy1 = sync(s1, csrf1)
    check(find_message(sy1, gm2["id"]) is not None, "group member reply")

    # admins only
    api(s1, "chat_settings", {"chat_id": gid, "admins_only": True}, csrf1)
    sy2 = sync(s2, csrf2)
    check(find_chat(sy2, gid)["adminsOnly"] is True, "adminsOnly sync")
    expect_error(s2, "send", {"chat_id": gid, "text": "blocked"}, csrf2, 403, "readonly")

    # pin and mute
    api(s1, "chat_settings", {"chat_id": gid, "pin": gmid}, csrf1)
    sy2 = sync(s2, csrf2)
    check(int(find_chat(sy2, gid)["pin"]) == int(gmid), "message pin sync")
    api(s2, "chat_settings", {"chat_id": gid, "muted": True}, csrf2)
    sy2 = sync(s2, csrf2)
    check(find_chat(sy2, gid)["muted"] is True, "mute sync")
    b2m = boot(s2)
    check(find_chat(b2m, gid)["muted"] is True, "mute persisted")
    api(s1, "chat_settings", {"chat_id": gid, "admins_only": False}, csrf1)

    # linked reply in group
    gm3 = send(s2, csrf2, gid, "پاسخ گروهی", reply_to=gmid, temp_id="t-group-3")
    sy1 = sync(s1, csrf1)
    gm3s = find_message(sy1, gm3["id"])
    check(gm3s is not None and int(gm3s.get("reply", 0)) == int(gmid), "group reply")

    # ------------------------------------------------------------
    # Add member and owner promotion
    # ------------------------------------------------------------
    g3 = api(s1, "create_chat", {"type": "group", "name": "گروه افزودن", "member_ids": []}, csrf1)["chat"]
    g3id = g3["id"]
    api(s1, "add_members", {"chat_id": g3id, "user_ids": [uid2]}, csrf1)
    sy2 = sync(s2, csrf2)
    g3m = find_chat(sy2, g3id)
    check(g3m is not None and g3m["role"] == "member", "added group member")
    send(s2, csrf2, g3id, "پیام عضو جدید", temp_id="t-group-add")
    expect_error(s2, "add_members", {"chat_id": g3id, "user_ids": [uid1]}, csrf2, 403, "forbidden")
    expect_error(s2, "chat_settings", {"chat_id": g3id, "admins_only": True}, csrf2, 403, "forbidden")

    # owner leaves; member promoted
    api(s1, "leave", {"chat_id": g3id}, csrf1)
    sy2 = sync(s2, csrf2)
    g3after = find_chat(sy2, g3id)
    check(g3after is not None and g3after["role"] == "owner", f"owner promotion: {g3after}")

    # ------------------------------------------------------------
    # Channel
    # ------------------------------------------------------------
    ch = api(s1, "create_chat", {
        "type": "channel",
        "name": "کانال تست",
        "description": "کانال آزمایشی",
        "member_ids": [uid2],
    }, csrf1)["chat"]
    cid = ch["id"]
    check(ch["type"] == "channel", "channel type")
    check(any(str(x[0]) == str(uid2) and x[1] == "subscriber" for x in ch["members"]), "channel subscriber")

    sy2 = sync(s2, csrf2)
    c2 = find_chat(sy2, cid)
    check(c2 is not None and c2["role"] == "subscriber" and c2["subs"] == 2, f"channel meta: {c2}")

    cm = send(s1, csrf1, cid, "پیام کانال", temp_id="t-channel-1")
    cmid = cm["id"]
    check(cm.get("mine") is True and cm.get("read") is True, "channel own payload")
    sy2 = sync(s2, csrf2)
    cmsg = find_message(sy2, cmid)
    check(cmsg is not None and cmsg["from"] == "ch" and cmsg.get("mine") is False, "channel message shape")

    expect_error(s2, "send", {"chat_id": cid, "text": "subscriber cannot post"}, csrf2, 403, "readonly")
    expect_error(s2, "delete", {"message_id": cmid}, csrf2, 403, "forbidden")
    expect_error(s2, "chat_settings", {"chat_id": cid, "pin": cmid}, csrf2, 403, "forbidden")

    # owner can delete and pin
    api(s1, "chat_settings", {"chat_id": cid, "pin": cmid}, csrf1)
    api(s1, "delete", {"message_id": cmid}, csrf1)
    sy2 = sync(s2, csrf2)
    cd = find_message(sy2, cmid)
    check(cd is not None and cd.get("deleted") is True, "channel message deleted")

    # ------------------------------------------------------------
    # Uploads
    # ------------------------------------------------------------
    r = s1.post(
        BASE + "api.php?action=upload",
        data={"chat_id": pid},
        files={"file": ("pixel.png", png_1x1(), "image/png")},
        headers={"X-CSRF-Token": csrf1, "Accept": "application/json"},
        timeout=40,
    )
    upload = r.json()
    check(r.status_code == 200 and upload.get("ok"), f"image upload failed: {r.status_code} {r.text[:500]}")
    uthumb = upload["message"]
    check("media" in uthumb and "src" in uthumb["media"], f"image payload: {uthumb}")
    img = s1.get(BASE + uthumb["media"]["src"], timeout=40)
    check(img.status_code == 200 and len(img.content) > 20, f"image fetch: {img.status_code}")
    check(img.headers.get("content-type", "").startswith("image/"), f"image content-type: {img.headers.get('content-type')}")

    r = s1.post(
        BASE + "api.php?action=upload",
        data={"chat_id": pid, "caption": "فایل تست"},
        files={"file": ("note.txt", b"hello ravidchat\n", "text/plain")},
        headers={"X-CSRF-Token": csrf1, "Accept": "application/json"},
        timeout=40,
    )
    upload2 = r.json()
    check(r.status_code == 200 and upload2.get("ok"), f"file upload failed: {r.status_code} {r.text[:500]}")
    fmsg = upload2["message"]
    check("file" in fmsg and fmsg["file"]["name"] == "note.txt", f"file payload: {fmsg}")
    fget = s1.get(BASE + fmsg["file"]["url"], timeout=40)
    check(fget.status_code == 200 and b"hello ravidchat" in fget.content, f"file fetch: {fget.status_code}")

    sy2 = sync(s2, csrf2)
    check(find_message(sy2, uthumb["id"]) is not None, "uploaded image synced")
    check(find_message(sy2, fmsg["id"]) is not None, "uploaded file synced")

    # ------------------------------------------------------------
    # Story
    # ------------------------------------------------------------
    story_text = "استوری تست " + str(int(time.time()))
    st = api(s1, "story", {"text": story_text, "bg": "linear-gradient(160deg,#1f7a6d,#0f3d37)"}, csrf1)["story"]
    sid = st["id"]
    sy2 = sync(s2, csrf2)
    found_story = None
    for entry in sy2.get("stories", []):
        item = entry.get("item") or {}
        if int(item.get("id", 0)) == int(sid):
            found_story = item
    check(found_story is not None and found_story["text"] == story_text, f"story sync (id={sid}, text={story_text})")
    api(s2, "story_view", {"user_id": uid1}, csrf2)
    b1s = boot(s1)
    mine = [x for x in b1s.get("my_stories", []) if int(x["id"]) == int(sid)]
    check(mine and mine[0]["views"] >= 1, f"story views: {mine}")

    # ------------------------------------------------------------
    # Saved chat
    # ------------------------------------------------------------
    saved = next((c for c in b1["chats"] if c["type"] == "saved"), None)
    check(saved is not None, "saved chat exists")
    sm = send(s1, csrf1, saved["id"], "یادداشت شخصی", temp_id="t-saved-1")
    check(sm.get("read") is True, "saved read true")
    saved_sync = sync(s1, csrf1)
    check(find_message(saved_sync, sm["id"]) is not None, "saved message sync")

    # ------------------------------------------------------------
    # Private leave / rejoin and peer metadata
    # ------------------------------------------------------------
    api(s2, "leave", {"chat_id": pid}, csrf2)
    sy2 = sync(s2, csrf2)
    check(str(pid) not in [str(x) for x in sy2["active_chat_ids"]], "private removed for leaver")
    api(s1, "typing", {"chat_id": pid}, csrf1)  # just ensure still member
    sy1 = sync(s1, csrf1)
    p_after = find_chat(sy1, pid)
    check(p_after is not None and int(p_after.get("user", 0)) == uid2, f"private peer retained: {p_after}")
    pmsg = send(s1, csrf1, pid, "بعد از ترک", temp_id="t-private-after-leave")
    expect_error(s2, "send", {"chat_id": pid, "text": "cannot"}, csrf2, 403, "forbidden")
    rejoined = api(s2, "open_private", {"user_id": uid1}, csrf2)["chat"]
    check(str(rejoined["id"]) == str(pid), "private rejoin reuses chat")
    check(find_message({"messages": rejoined["msgs"]}, pmsg["id"]) is not None, "history available on rejoin")
    send(s2, csrf2, pid, "دوباره برگشتم", temp_id="t-private-rejoined")

    # ------------------------------------------------------------
    # Security / validation
    # ------------------------------------------------------------
    status, payload = raw_api(s1, "send", {"chat_id": pid, "text": "no csrf"}, "", "POST")
    check(status == 403 and payload.get("code") == "csrf", f"csrf rejection: {status} {payload}")
    expect_error(s1, "send", {"chat_id": pid, "text": "get"}, csrf1, 405, "method_not_allowed", "GET")
    expect_error(s1, "story_view", {"user_id": uid2}, csrf1, 405, "method_not_allowed", "GET")
    expect_error(s1, "upload", {}, csrf1, 405, "method_not_allowed", "GET")

    status, payload = raw_api(s1, "leave", {"chat_id": saved["id"]}, csrf1)
    check(status == 422 and payload.get("code") == "saved_chat", f"saved leave: {status} {payload}")

    # search users
    found = api(s1, "search_users", {"q": "azade"}, csrf1, "GET")
    check(any(int(u["id"]) == uid2 for u in found["users"]), "user search")

    # profile update and propagation
    new_name = USERS["two"]["name"] + " ★"
    api(s2, "update_profile", {"name": new_name, "username": USERS["two"]["username"]}, csrf2)
    sy1 = sync(s1, csrf1)
    check(int(sy1["users"][str(uid2)]["name"] == new_name), "profile name sync")
    api(s2, "update_profile", {"name": USERS["two"]["name"], "username": USERS["two"]["username"]}, csrf2)

    # logout
    r = s2.get(BASE + "logout.php", timeout=40, allow_redirects=False)
    check(r.status_code == 302, f"logout status: {r.status_code}")
    r = s2.get(BASE + "index.php", timeout=40, allow_redirects=False)
    check(r.status_code == 302 and "auth.php" in r.headers.get("Location", ""), "index requires auth after logout")

    print("ALL E2E TESTS PASSED")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print("ERROR:", exc, file=sys.stderr)
        raise SystemExit(1)
