# Image Alt Use Audit — 0.1.0 release notes

## What this release does

Inventory saved image uses and their alt states in published posts and pages; bounded manual scans; private filtered report and guarded CSV; English and Polish admin text.

## Requirements and tested stack

Requires WordPress 7.1+, PHP 8.3+ and InnoDB. WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13. On 2026-11-05, the exact public GitHub release ZIP passed a fresh synthetic Chromium admin scan, four-state report, selected CSV, focus and 390px layout check; see [QA evidence](docs/QA-0.1.0.md). Earlier Plugin Check results were from a prior ZIP candidate, so Plugin Check on the released ZIP remains an open verification item. Manual screen-reader and representative site validation are also outstanding.

## Known limits

Saved post_content only. It does not judge accessibility or scan theme, dynamic, CSS, widget, synced-block or page-builder output. Skipped/unsupported content prevents a clean conclusion. The scan is a best-effort high-water pass, not an immutable snapshot; rescan after content or catalog changes. See [README.md](README.md) for full scope and lifecycle details.

**Release ZIP SHA-256:** `fcfe2ea4d925a078edfc722c27361bfbcc58ae0acee9d97c88c6b10c490e9385`. Verify downloaded bytes against the sidecar before installing.

## Install and update

Download the versioned ZIP and SHA-256 sidecar from the GitHub release for this repository. Verify the checksum, then use **Plugins → Add New → Upload Plugin** in WordPress. For updates, back up the site and database, upload the newer ZIP, and verify the plugin version and expected behavior. Version 0.1.0 has no automatic update endpoint; the `Update URI` header only prevents unrelated directory updates. Deactivation retains private scan data; uninstall deletes plugin-owned data.

## Source and support

Source: https://github.com/digicyfr/digicyfr-image-alt-use-audit. Report ordinary bugs through Issues; use [SECURITY.md](SECURITY.md) for private vulnerability reports. The GPL-2.0-or-later license is in [LICENSE](LICENSE). Detailed local QA, including open gates and the exact ZIP hash, is in [docs/QA-0.1.0.md](docs/QA-0.1.0.md).

This release candidate targets Digicyfr/GitHub self-hosted distribution. WordPress.org Plugin Check reports an updater-policy error for the self-hosted `Update URI`, so this package is not a WordPress.org submission.
