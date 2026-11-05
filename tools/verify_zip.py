#!/usr/bin/env python3
"""Check the structure and integrity of a WordPress plugin release ZIP."""

from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import re
import stat
import zipfile

try:
    from . import build_plugin
except ImportError:
    import build_plugin


SLUG = re.compile(r"[a-z0-9]+(?:-[a-z0-9]+)*\Z")
DENIED_PARTS = {".git", ".github", "node_modules", "tests", "test", "dist", "__pycache__"}
DENIED_FILES = {".env", "release-files.txt", "composer.json", "composer.lock", "package.json", "package-lock.json"}


def verify(archive: Path, slug: str) -> int:
    if not SLUG.fullmatch(slug):
        raise ValueError("Invalid plugin slug")
    with zipfile.ZipFile(archive) as zf:
        names = zf.namelist()
        if not names or len(names) != len({name.casefold() for name in names}):
            raise ValueError("Archive is empty or contains duplicate or case-colliding paths")
        for info in zf.infolist():
            name = info.filename
            parts = PurePosixPath(name).parts
            if info.is_dir() or name.startswith("/") or "\\" in name or any(part in {".", ".."} for part in name.split("/")):
                raise ValueError(f"Unsafe archive entry: {name}")
            if not parts or parts[0] != slug or len(parts) < 2:
                raise ValueError(f"Entry is not inside the {slug}/ plugin root: {name}")
            if any(part in DENIED_PARTS for part in parts[1:]) or parts[-1] in DENIED_FILES or parts[-1].startswith(".env."):
                raise ValueError(f"Development or secret file cannot be packaged: {name}")
            if stat.S_IFMT(info.external_attr >> 16) == stat.S_IFLNK:
                raise ValueError(f"Symlink entry is forbidden: {name}")
        required = {f"{slug}/{slug}.php", f"{slug}/readme.txt", f"{slug}/LICENSE"}
        if not required.issubset(names):
            raise ValueError("Archive is missing its main plugin file, readme.txt, or LICENSE")
        bad = zf.testzip()
        if bad:
            raise ValueError(f"Archive integrity check failed at {bad}")
        return len(names)


def verify_against_source(archive: Path, slug: str) -> None:
    """Require the ZIP to match the current allowlisted source byte for byte."""
    plugin_dir = build_plugin.ROOT
    files, _ = build_plugin.release_files(plugin_dir, slug)
    expected = {f"{slug}/{path.as_posix()}" for path in files}
    with zipfile.ZipFile(archive) as zf:
        actual = set(zf.namelist())
        if actual != expected:
            raise ValueError("Archive file list differs from current release manifest")
        for path in files:
            name = f"{slug}/{path.as_posix()}"
            if zf.read(name) != (plugin_dir / path).read_bytes():
                raise ValueError(f"Archive contains stale source bytes: {name}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive", type=Path)
    parser.add_argument("--slug", required=True)
    parser.add_argument("--against-source", action="store_true", help="Compare every archive entry to the current release manifest and source bytes")
    args = parser.parse_args()
    try:
        count = verify(args.archive, args.slug)
        if args.against_source:
            verify_against_source(args.archive, args.slug)
    except (ValueError, build_plugin.BuildError, OSError, zipfile.BadZipFile) as exc:
        parser.exit(1, f"verify error: {exc}\n")
    print(f"Verified {count} files in {args.archive}")


if __name__ == "__main__":
    main()
