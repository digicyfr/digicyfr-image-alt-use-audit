#!/usr/bin/env python3
"""Build a deterministic, allowlisted WordPress plugin ZIP."""

from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import stat
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
SLUG = re.compile(r"[a-z0-9]+(?:-[a-z0-9]+)*\Z")
VERSION = re.compile(r"[0-9]+(?:\.[0-9]+){1,3}(?:[-.][A-Za-z0-9]+)*\Z")
FIXED_TIME = (1980, 1, 1, 0, 0, 0)
DENIED_PARTS = {".git", ".github", "node_modules", "tests", "test", "dist", "__pycache__"}
DENIED_FILES = {".env", "release-files.txt", "composer.json", "composer.lock", "package.json", "package-lock.json"}


class BuildError(ValueError):
    """A plugin cannot safely be packaged."""


def header_value(text: str, key: str) -> str | None:
    match = re.search(rf"^[ \t]*(?:\*[ \t]*)?{re.escape(key)}:[ \t]*(.+)$", text, re.I | re.M)
    return match.group(1).strip() if match else None


def release_files(plugin_dir: Path, slug: str) -> tuple[list[Path], str]:
    if not SLUG.fullmatch(slug):
        raise BuildError("Slug must contain only lowercase letters, digits, and hyphens")
    if not plugin_dir.is_dir() or plugin_dir.is_symlink():
        raise BuildError(f"Plugin directory is missing or a symlink: {plugin_dir}")

    manifest = plugin_dir / "release-files.txt"
    if not manifest.is_file() or manifest.is_symlink():
        raise BuildError(f"Missing release manifest: {manifest}")

    selected: set[Path] = set()
    for number, raw in enumerate(manifest.read_text(encoding="utf-8").splitlines(), 1):
        entry = raw.strip()
        if not entry or entry.startswith("#"):
            continue
        if "\\" in entry or "*" in entry or "?" in entry or "[" in entry:
            raise BuildError(f"Manifest line {number}: use literal POSIX paths, not globs")
        relative = PurePosixPath(entry)
        if relative.is_absolute() or any(part in {"", ".", ".."} for part in entry.split("/")):
            raise BuildError(f"Manifest line {number}: unsafe path {entry!r}")
        path = plugin_dir.joinpath(*relative.parts)
        if not path.exists() and not path.is_symlink():
            raise BuildError(f"Manifest line {number}: missing path {entry!r}")
        paths = [path] if path.is_file() or path.is_symlink() else sorted(path.rglob("*"))
        for candidate in paths:
            rel = candidate.relative_to(plugin_dir)
            if any(part in DENIED_PARTS for part in rel.parts) or candidate.name in DENIED_FILES or candidate.name.startswith(".env."):
                raise BuildError(f"Development or secret file cannot be packaged: {rel}")
            if candidate.is_symlink() or any(parent.is_symlink() for parent in candidate.parents if parent != plugin_dir):
                raise BuildError(f"Symlinks cannot be packaged: {rel}")
            if candidate.is_dir():
                continue
            if not candidate.is_file() or not stat.S_ISREG(candidate.stat().st_mode):
                raise BuildError(f"Only regular files can be packaged: {rel}")
            selected.add(rel)

    required = {Path(f"{slug}.php"), Path("readme.txt"), Path("LICENSE")}
    missing = required - selected
    if missing:
        raise BuildError("Required release files are missing from the manifest: " + ", ".join(str(p) for p in sorted(missing)))
    if len(selected) != len({path.as_posix().casefold() for path in selected}):
        raise BuildError("Release paths collide on case-insensitive filesystems")

    main = (plugin_dir / f"{slug}.php").read_text(encoding="utf-8")[:8192]
    if not header_value(main, "Plugin Name"):
        raise BuildError("Main plugin file lacks a Plugin Name header")
    version = header_value(main, "Version")
    if not version or not VERSION.fullmatch(version):
        raise BuildError("Main plugin file has a missing or invalid Version header")
    if header_value(main, "Text Domain") != slug:
        raise BuildError("Text Domain must equal the plugin slug")
    if header_value(main, "License") != "GPL-2.0-or-later":
        raise BuildError("License header must be GPL-2.0-or-later")
    readme = (plugin_dir / "readme.txt").read_text(encoding="utf-8")
    if header_value(readme, "Stable tag") != version:
        raise BuildError("readme.txt Stable tag must equal the plugin Version")
    if not (plugin_dir / "LICENSE").read_text(encoding="utf-8").strip():
        raise BuildError("LICENSE must not be empty")
    return sorted(selected, key=lambda p: p.as_posix()), version


def build(slug: str, output_dir: Path) -> Path:
    plugin_dir = ROOT
    files, version = release_files(plugin_dir, slug)
    output_dir.mkdir(parents=True, exist_ok=True)
    archive = output_dir / f"{slug}-{version}.zip"
    with tempfile.NamedTemporaryFile(prefix=f".{slug}-", suffix=".zip", dir=output_dir, delete=False) as temp:
        temporary = Path(temp.name)
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
            for relative in files:
                info = zipfile.ZipInfo(f"{slug}/{relative.as_posix()}", FIXED_TIME)
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = zipfile.ZIP_DEFLATED
                zf.writestr(info, (plugin_dir / relative).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        os.replace(temporary, archive)
        archive.chmod(0o644)
    finally:
        temporary.unlink(missing_ok=True)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n", encoding="ascii")
    return archive


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("slug", help="Plugin slug (repository root directory name)")
    parser.add_argument("--output-dir", type=Path, default=ROOT / "dist")
    args = parser.parse_args()
    try:
        archive = build(args.slug, args.output_dir)
    except (BuildError, OSError, UnicodeError) as exc:
        parser.exit(1, f"build error: {exc}\n")
    print(archive)
    print(archive.with_suffix(".zip.sha256"))


if __name__ == "__main__":
    main()
