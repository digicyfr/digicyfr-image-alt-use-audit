# QA — 0.1.0

## Current GitHub repository candidate (2026-11-05)

- ZIP: `digicyfr-image-alt-use-audit-0.1.0.zip` from the public GitHub `v0.1.0` release; the local candidate has the same SHA-256.
- SHA-256: `fcfe2ea4d925a078edfc722c27361bfbcc58ae0acee9d97c88c6b10c490e9385`
- The new repository snapshot adds a per-plugin GitHub `Plugin URI`, updates packaged README instructions, and adds standalone build/verification tools, Docker harness, CI, contribution/security policies and release notes outside the ZIP. Scanner, storage, authorization and admin behavior are unchanged.
- Both standalone and workspace `verify_zip.py --against-source` passed on this ZIP; its `.sha256` sidecar passed. PHP and JavaScript syntax checks passed.
- Fresh disposable WordPress 7.1/PHP 8.3.33 install, activation/deactivation/reactivation, and the exact-ZIP 1,000-post integration run passed. The adapted standalone Docker harness was exercised locally. Manual screen-reader validation is still outstanding.
- The Plugin Check results below were collected on the earlier candidate hash and were not rerun on the current packaged README/header revision. The exact public GitHub ZIP browser run below closes that browser evidence gap. GitHub Actions passed on the release tag and branch.

## Post-tag browser QA on the exact public GitHub ZIP (2026-11-05)

- Downloaded both release assets from `https://github.com/digicyfr/digicyfr-image-alt-use-audit/releases/tag/v0.1.0`; `sha256sum --check` passed. ZIP SHA-256: `fcfe2ea4d925a078edfc722c27361bfbcc58ae0acee9d97c88c6b10c490e9385`.
- Installed, activated, deactivated and reactivated that downloaded ZIP in a fresh disposable WordPress 7.1/PHP 8.3.33/MariaDB 11.4.13 site. The four synthetic published posts contained saved `img` tags with missing, empty, present and whitespace-only `alt`; no real business content or image files were used.
- Chromium admin session started a scan and completed one bounded batch. The auto-run Pause link received focus on the queued screen. The complete report showed four saved uses with one row and one count for each synthetic alt state; six published posts were processed, with zero skipped and zero unsupported coverage notes. The [complete scan](evidence/0.1.0/complete-scan.png) and [full report](evidence/0.1.0/report-all.png) are captured.
- The [missing-alt filter](evidence/0.1.0/missing-filter.png) showed only the matching source. **Export selected CSV** downloaded a header plus that one matching row, excluding the other three fixture posts.
- At a 390px viewport, the [report](evidence/0.1.0/report-390px.png) and [overview](evidence/0.1.0/overview-390px.png) had document width 390px with no page overflow. The report table itself scrolls inside its container. The browser recorded no page script errors.
- This verifies a synthetic admin browser flow and layout. Manual NVDA/VoiceOver testing and a representative content/site review remain open; the plugin does not assess whether alt text is good or whether an image is decorative.

## Earlier candidate evidence (ZIP SHA-256 a8388cb4b05d66e13d72abc9aff056dea73546175514c659c6a4664c49ce47e3)

## Candidate

- ZIP: `dist/digicyfr-image-alt-use-audit-0.1.0.zip`
- SHA-256: `a8388cb4b05d66e13d72abc9aff056dea73546175514c659c6a4664c49ce47e3`
- `tools/verify_zip.py --against-source`: 14 allowlisted files matched source byte for byte. The ZIP excludes development tests, compiler, and release manifest.
- Tested stack: WordPress 7.1, PHP 8.3.33, MariaDB 11.4.13, InnoDB plugin tables. These are tested versions, not a claim about every newer version.

## Automated gates

- PHP lint, Node syntax, and WordPress Coding Standards (PHP runtime files, tests excluded from WPCS): passed without findings.
- Disposable exact-ZIP install, activation, deactivation, reactivation: passed. Activation created three InnoDB tables.
- Real WordPress exact-ZIP integration: passed. It verifies all five alt states; nested Gallery counted once per saved `img`; linked and Cover saved `img`; color-only Cover versus background Cover; URL query/credential stripping; HTML source labels; oversize and 301-image skips; a clean complete pointer; crash-window pointer repair; 1,000 published posts across 101 bounded Continue requests including two pause/resume requests; skip/unsupported coverage; row cap with unscanned IDs; selected CSV, formula guard, and incomplete status; stale/deleted sources; denied subscriber actions/report/export; deactivation retention; and uninstall cleanup. SQL errors are injected into pointer, high-water, batch, remaining-post, report, and CSV reads to verify that failures never appear as a clean scan or empty report.
- Plugin Check on this exact ZIP: one `plugin_updater_detected` ERROR for the intentional self-hosted `Update URI`. It is incompatible with WordPress.org hosting; this package is for manual distribution from digicyfr.com. One `load_plugin_textdomainFound` WARNING reflects bundled Polish translations outside WordPress.org. Eleven `UnescapedDBParameter` static-analysis WARNINGS refer to table names assembled only from WordPress's trusted `$wpdb->prefix` and fixed plugin suffixes, or dynamic report SQL whose clauses are fixed/whitelisted and whose values use `$wpdb->prepare()`. The SQL is private, capability checked, and covered by exact-ZIP integration. No other Plugin Check finding remained.

## Browser and accessibility

- Root's exact-ZIP Chromium check on this hash passed: keyboard Start; automatic bounded Continue; missing/empty report rows; CSV download; 390px overview/report without horizontal overflow. A second browser run verified Pause receives focus after redirect, Tab cancels the 10-second timer, the URL and Queued state remain unchanged after 11.5 seconds, and manual Continue completes. The disposable site was removed.
- Independent Chromium check on the same hash passed at 390px: Pause received focus, a one-fixture run automatically reached Complete after the 10-second timer, and there was no page script error. With 30 extra published posts, Enter on the focused Pause link stopped automatic progress, the run stayed Queued after 11 seconds, manual keyboard Continue advanced one batch, and Enter paused again.

## Scope and release limits

- This audit checks saved `post_content` only. It does not inspect rendered theme output, dynamic blocks, synced block contents, CSS backgrounds, widgets, templates, page-builder data outside `post_content`, or every public filter. A `complete_with_skips`, failed, cancelled, or active run cannot establish that the site is clear.
- The scan is a high-water ID walk, not an immutable publication snapshot. Published status and edits may change while it runs. Source rows become stale or deleted after later changes.
- A public GitHub stable self-hosted release exists. No digicyfr.com production publication, WordPress.org submission, or live customer-site installation was performed.

## Automated accessibility audit on the public ZIP — 2026-11-05

The exact public ZIP and SHA sidecar above were verified again, then installed on a fresh disposable WordPress 7.1 / PHP 8.3.33 site with synthetic saved image content. Chromium 156.0.8078.4 and axe-core 4.10.3 ran the WCAG 2/2.1/2.2 A/AA rule tags on the scan overview and completed report. Both plugin regions had **zero axe violations**; the completed report also had zero full-page violations, matching the WordPress Dashboard baseline. At 390px, both screens had document width 390px. Keyboard Tab moved from the labelled **Alt state** select to **Post ID**, and Chromium's accessibility tree exposed the select as a named combobox. Axe could not determine contrast for the native WordPress select because its background uses an image; this is an inconclusive check, not a passing contrast measurement. See [machine-readable audit evidence](evidence/0.1.0/axe-2026-11-05.json).

This automated check did not find a plugin-owned defect, so the `v0.1.0` ZIP, tag, and release branch were not changed. It does not replace a human screen-reader pass or a representative site content review.

## Stable self-hosted release decision — 2026-11-05

The public ZIP and sidecar were verified again at SHA-256 `fcfe2ea4d925a078edfc722c27361bfbcc58ae0acee9d97c88c6b10c490e9385`; all 14 packaged files matched source. The protected `release/0.1.0` branch and `v0.1.0` tag matched, and their CI plus the latest main CI passed. The exact-ZIP browser and automated accessibility evidence above supports stable self-hosted publication with its documented scope limits. Human screen-reader and representative content checks were not performed; this decision does not claim WCAG conformance or WordPress.org approval. The ZIP, tag and release branch bytes remain unchanged.
