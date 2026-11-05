=== Digicyfr Image Alt Use Audit ===
Tags: image alt, accessibility, editorial, audit, images
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A private, bounded inventory of alt attributes saved on image uses in published posts and pages.

== Description ==

Find saved image uses with missing, explicitly empty, whitespace-only, present, or invalid alt values. Inspect the source block/path and edit the source post or page. Empty alt may be suitable for decoration; an editor decides. The manual scan is bounded, resumable, and visibly incomplete when posts are skipped or media is outside scope. The plugin neither changes alt text nor fetches public pages or image URLs. Administrators can filter a private report and export a formula-guarded CSV. English and Polish strings are bundled. See README.md for exact coverage, limits, storage and data lifecycle.

== Installation ==

1. Upload the plugin ZIP at Plugins > Add New > Upload Plugin and activate it. InnoDB tables are required.
2. Open Tools > Image Alt Use Audit and start a scan. Pause or resume it from the same screen.
3. Filter findings and open the source item to decide what to change.

== Frequently Asked Questions ==

= Does an empty alt mean an accessibility problem? =

Not necessarily. Decorative images may appropriately use alt="". The report asks an editor to review purpose and context.

= Does the scan include featured images and CSS backgrounds? =

No. It inspects saved img tags in post_content only, and explicitly notes some unsupported coverage.

= What happens on uninstall? =

The plugin drops its three scan tables and options. Export needed reports first. Deactivation retains them.

== Changelog ==

= 0.1.0 =

* Initial candidate: bounded saved-use scan, resumable status, source report, coverage reasons, guarded CSV and bundled Polish strings.
