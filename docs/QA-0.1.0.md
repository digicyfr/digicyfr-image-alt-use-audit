# Local QA — 0.1.0

## Current GitHub repository candidate (2026-11-05)

- ZIP: `dist/digicyfr-image-alt-use-audit-0.1.0.zip`
- SHA-256: `fcfe2ea4d925a078edfc722c27361bfbcc58ae0acee9d97c88c6b10c490e9385`
- The new repository snapshot adds a per-plugin GitHub `Plugin URI`, updates packaged README instructions, and adds standalone build/verification tools, Docker harness, CI, contribution/security policies and release notes outside the ZIP. Scanner, storage, authorization and admin behavior are unchanged.
- Both standalone and workspace `verify_zip.py --against-source` passed on this ZIP; its `.sha256` sidecar passed. PHP and JavaScript syntax checks passed.
- Fresh disposable WordPress 7.1/PHP 8.3.33 install, activation/deactivation/reactivation, and the exact-ZIP 1,000-post integration run passed. The adapted standalone Docker harness was exercised locally. Manual screen-reader validation is still outstanding.
- The browser and Plugin Check results below were collected on the earlier candidate hash; they were not rerun on the current packaged README/header revision. GitHub Actions has not yet run because the repository is not published.

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
- No digicyfr.com publication, GitHub release, WordPress.org submission, or live-site installation was performed.
