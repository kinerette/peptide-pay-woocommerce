"""
Builds peptide-pay-woocommerce.zip with proper Unix file permissions
(0644 files, 0755 dirs) and explicit folder entries.

PowerShell Compress-Archive on Windows produces zips with mode=0 on all
entries — when extracted on a Linux WP host, files end up unreadable by
the PHP user, and WordPress reports "Plugin file does not exist" on
activation. This script fixes that by rebuilding the zip with proper
metadata that matches what WordPress.org / wp-cli build pipelines emit.

Run: python build-zip.py
Output: ../peptide-pay/public/downloads/peptide-pay-woocommerce.zip
"""
import os
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SRC = ROOT  # the plugin source dir is where this script lives
OUT = ROOT.parent / "peptide-pay" / "public" / "downloads" / "peptide-pay-woocommerce.zip"

# Unix file modes shifted into the high bits of external_attr
FILE_MODE = 0o100644 << 16  # regular file, rw-r--r--
DIR_MODE = 0o040755 << 16   # directory, rwxr-xr-x

# Files / patterns to skip
SKIP_NAMES = {".DS_Store", "Thumbs.db", "build-zip.py", ".gitignore", ".gitattributes"}
SKIP_SUFFIXES = {".zip"}
SKIP_DIRS = {".git", "node_modules", "__pycache__"}


def should_skip(p: Path) -> bool:
    if p.name in SKIP_NAMES:
        return True
    if p.suffix in SKIP_SUFFIXES:
        return True
    if p.name.startswith("._"):  # macOS resource forks
        return True
    # Skip if any parent dir is in SKIP_DIRS
    for part in p.parts:
        if part in SKIP_DIRS:
            return True
    return False


def main():
    if not SRC.exists():
        print(f"ERROR: source dir not found: {SRC}", file=sys.stderr)
        return 1

    OUT.parent.mkdir(parents=True, exist_ok=True)
    if OUT.exists():
        OUT.unlink()

    # Walk in deterministic order so the zip is reproducible
    all_paths = sorted(SRC.rglob("*"))

    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        seen_dirs = set()
        # Top-level folder name in the zip
        top = SRC.name
        # Always include the top folder as the first entry
        zi = zipfile.ZipInfo(f"{top}/")
        zi.external_attr = DIR_MODE
        zf.writestr(zi, b"")
        seen_dirs.add(top)

        for p in all_paths:
            if should_skip(p):
                continue

            rel_parts = p.relative_to(SRC).parts
            rel = "/".join((top,) + rel_parts)

            if p.is_dir():
                if rel in seen_dirs:
                    continue
                zi = zipfile.ZipInfo(rel + "/")
                zi.external_attr = DIR_MODE
                zf.writestr(zi, b"")
                seen_dirs.add(rel)
                continue

            # Ensure every parent dir has an explicit entry
            parts = rel.split("/")
            for i in range(1, len(parts)):
                parent = "/".join(parts[:i])
                if parent and parent not in seen_dirs:
                    pzi = zipfile.ZipInfo(parent + "/")
                    pzi.external_attr = DIR_MODE
                    zf.writestr(pzi, b"")
                    seen_dirs.add(parent)

            zi = zipfile.ZipInfo(rel)
            zi.external_attr = FILE_MODE
            zi.compress_type = zipfile.ZIP_DEFLATED
            with open(p, "rb") as f:
                zf.writestr(zi, f.read())

    # Sanity check
    with zipfile.ZipFile(OUT) as zf:
        for info in zf.infolist():
            mode = (info.external_attr >> 16) & 0o7777
            if info.is_dir():
                if mode != 0o755:
                    print(f"WARN dir {info.filename} mode={oct(mode)}", file=sys.stderr)
            else:
                if mode != 0o644:
                    print(f"WARN file {info.filename} mode={oct(mode)}", file=sys.stderr)

    size = OUT.stat().st_size
    print(f"Built {OUT} ({size:,} bytes)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
