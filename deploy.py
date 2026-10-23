#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
deploy.py
آپلود فایل‌های پروژه به هاست VistaPanel از طریق FTP در حالت Passive.

استفاده:
    python deploy.py
"""

from __future__ import annotations

import ftplib
import posixpath
import sys
from pathlib import Path

# ---------------------------------------------------------------
# تنظیمات اتصال FTP
# ---------------------------------------------------------------
FTP_HOST = "ftpupload.net"
FTP_PORT = 21
FTP_USER = "ihw0_43247552"
FTP_PASS = "7c6b3e05a5a17f7"
REMOTE_ROOT = "/htdocs"

# پوشه‌ای که این اسکریپت در آن قرار دارد، مبنای آپلود است
LOCAL_ROOT = Path(__file__).resolve().parent

# فایل‌هایی که نباید آپلود شوند
SKIP_FILES = {
    "deploy.py",                    # خود اسکریپت
    "maddy-linux-amd64.tar.gz",     # آرشیو حجیم و نامرتبط با سایت
    "install.php",                  # فقط یک‌بار برای نصب استفاده می‌شود
    "schema.sql",                   # فقط برای نصب محلی/اولیه لازم است
    "README.md",                    # مستندات محلی؛ روی هاست لازم نیست
    "_cleanup.php",                 # فقط برای پاک‌سازی موقت
    "_e2e_test.py",                 # اسکریپت تست حاوی اطلاعات ورود
    "Thumbs.db",
    ".DS_Store",
}

# پسوندهایی که هرگز نباید روی هاست آپلود شوند
SKIP_SUFFIXES = {".zip", ".rar", ".7z", ".tar", ".gz", ".bz2", ".xz", ".py"}

# پوشه‌هایی که نباید آپلود شوند
SKIP_DIRS = {
    ".git", ".idea", ".vscode", "__pycache__",
    ".venv", "venv", "node_modules",
}


def iter_local_files() -> list[Path]:
    """برگرداندن همه فایل‌های قابل آپلود به‌صورت نسبی."""
    files: list[Path] = []
    for path in sorted(LOCAL_ROOT.rglob("*")):
        if not path.is_file():
            continue
        rel = path.relative_to(LOCAL_ROOT)
        if rel.as_posix() == "deploy.py" or path.name in SKIP_FILES:
            continue
        # اسکریپت‌های موقت/تستی با پیشوند _ هرگز نباید روی هاست بروند
        if path.name.startswith("_") and path.suffix.lower() in {".php", ".py"}:
            continue
        if path.suffix.lower() in SKIP_SUFFIXES:
            continue
        if any(part in SKIP_DIRS for part in rel.parts[:-1]):
            continue
        files.append(rel)
    return files


def ensure_remote_dir(ftp: ftplib.FTP, remote_dir: str) -> None:
    """ساخت بازگشتی مسیر روی سرور (در صورت نبود) و رفتن به آن."""
    ftp.cwd("/")
    for part in [p for p in remote_dir.strip("/").split("/") if p]:
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)


def upload_file(ftp: ftplib.FTP, rel_path: Path) -> None:
    """آپلود یک فایل در حالت باینری + بررسی اندازه پس از آپلود."""
    local_path = LOCAL_ROOT / rel_path
    remote_dir = posixpath.join(REMOTE_ROOT, rel_path.parent.as_posix())
    remote_name = rel_path.name

    ensure_remote_dir(ftp, remote_dir)

    with open(local_path, "rb") as fh:
        ftp.storbinary(f"STOR {remote_name}", fh, blocksize=64 * 1024)

    # بررسی موفقیت با مقایسه اندازه فایل محلی و ریموت
    local_size = local_path.stat().st_size
    remote_size = None
    try:
        ftp.voidcmd("TYPE I")
        remote_size = ftp.size(remote_name)
    except ftplib.all_errors:
        pass  # بعضی سرورها دستور SIZE را پشتیبانی نمی‌کنند

    if remote_size is not None and remote_size != local_size:
        raise IOError(
            f"اندازه فایل ریموت ({remote_size}) با فایل محلی ({local_size}) یکسان نیست."
        )

    print(f"  [OK] {rel_path.as_posix()}  ({local_size:,} bytes)")


def main() -> int:
    files = iter_local_files()

    print("=" * 60)
    print("  شروع آپلود به هاست VistaPanel (FTP Passive)")
    print(f"  مقصد: {FTP_HOST}:{FTP_PORT}{REMOTE_ROOT}")
    print("=" * 60)

    if not files:
        print("هیچ فایلی برای آپلود پیدا نشد.")
        return 1

    ftp = ftplib.FTP(timeout=90)
    ftp.encoding = "utf-8"
    ftp.set_pasv(True)  # اتصال در حالت Passive

    try:
        ftp.connect(FTP_HOST, FTP_PORT)
        ftp.login(FTP_USER, FTP_PASS)
        print(f"اتصال برقرار شد: {ftp.getwelcome()}")
        print(f"تعداد فایل‌های آماده آپلود: {len(files)}\n")

        ensure_remote_dir(ftp, REMOTE_ROOT)

        ok = 0
        for rel_path in files:
            try:
                upload_file(ftp, rel_path)
                ok += 1
            except ftplib.all_errors as exc:
                print(f"  [FAIL] {rel_path.as_posix()} -> {exc}")

        try:
            ftp.quit()
        except ftplib.all_errors:
            ftp.close()

        print("-" * 60)
        print(f"پایان: {ok} از {len(files)} فایل با موفقیت آپلود شد.")
        print("=" * 60)
        return 0 if ok == len(files) else 2

    except ftplib.all_errors as exc:
        print(f"\n[خطای FTP] {exc}", file=sys.stderr)
        try:
            ftp.close()
        except Exception:
            pass
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
