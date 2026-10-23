from __future__ import annotations

import json

import _e2e_test as t

BASE = t.BASE


def parse(r):
    return json.JSONDecoder().raw_decode(r.text.lstrip("\ufeff \r\n\t"))[0]


def main():
    s1 = t.new_session()
    s2 = t.new_session()
    t.login_or_register(s1, **t.USERS["one"])
    t.login_or_register(s2, **t.USERS["two"])
    b1 = t.boot(s1)
    b2 = t.boot(s2)
    csrf1 = b1["csrf"]
    uid1 = int(b1["me"]["uid"])
    uid2 = int(b2["me"]["uid"])

    # Unauthenticated API must not leak data.
    s0 = t.new_session()
    r = s0.get(BASE + "api.php?action=sync", params={"since": "1970-01-01 00:00:00"}, timeout=40)
    p = parse(r)
    assert r.status_code == 401 and p.get("ok") is False, (r.status_code, p)
    print("unauthenticated API: 401")

    # Opening a private chat with yourself should return the saved chat.
    self_chat = t.api(s1, "open_private", {"user_id": uid1}, csrf1)["chat"]
    assert self_chat["type"] == "saved", self_chat
    print("self private chat: saved")

    chat = t.api(s1, "open_private", {"user_id": uid2}, csrf1)["chat"]
    cid = chat["id"]

    # Executable upload is rejected before touching disk.
    r = s1.post(
        BASE + "api.php?action=upload",
        data={"chat_id": cid},
        files={"file": ("shell.php", b"<?php echo 1; ?>", "application/x-php")},
        headers={"X-CSRF-Token": csrf1, "Accept": "application/json"},
        timeout=40,
    )
    p = parse(r)
    assert r.status_code == 422 and p.get("code") == "invalid_ext", (r.status_code, p)
    print("executable upload: rejected")

    # A file claiming to be a PNG must actually be an image.
    r = s1.post(
        BASE + "api.php?action=upload",
        data={"chat_id": cid},
        files={"file": ("fake.png", b"not a real png", "image/png")},
        headers={"X-CSRF-Token": csrf1, "Accept": "application/json"},
        timeout=40,
    )
    p = parse(r)
    assert r.status_code == 422 and p.get("code") == "invalid_image", (r.status_code, p)
    print("fake image: rejected")

    # Text upload, then delete it: the physical file must be removed too.
    r = s1.post(
        BASE + "api.php?action=upload",
        data={"chat_id": cid},
        files={"file": ("../traversal.txt", b"delete me\n", "text/plain")},
        headers={"X-CSRF-Token": csrf1, "Accept": "application/json"},
        timeout=40,
    )
    p = parse(r)
    assert r.status_code == 200 and p.get("ok"), (r.status_code, p)
    msg = p["message"]
    assert msg["file"]["name"] == "traversal.txt", msg
    url = msg["file"]["url"]
    f = s1.get(BASE + url, timeout=40)
    assert f.status_code == 200 and b"delete me" in f.content, f.status_code
    t.api(s1, "delete", {"message_id": msg["id"]}, csrf1)
    f = s1.get(BASE + url + "?nocache=1", timeout=40)
    # The host answers missing static paths with its JS challenge page (HTTP 200).
    assert b"delete me" not in f.content, f.text[:200]
    print("upload delete: file content no longer served")

    # HTML-looking text must round-trip as text, not be interpreted by the API.
    payload = '<img src=x onerror=alert(1)><script>alert(2)</script>'
    m = t.send(s1, csrf1, cid, payload, temp_id="security-xss")
    d = t.sync(s2, b2["csrf"])
    seen = t.find_message(d, m["id"])
    assert seen is not None and seen["text"] == payload, seen
    t.api(s1, "delete", {"message_id": m["id"]}, csrf1)
    print("HTML-looking text: preserved as text")

    print("SECURITY TESTS PASSED")


if __name__ == "__main__":
    main()
