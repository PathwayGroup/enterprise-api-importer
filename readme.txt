=== Enterprise API Importer ===
Contributors: tporret
Tags: import, api, etl, cron, automation
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Highly secure enterprise ETL importer for WordPress with staged processing and recurring schedules.

== Description ==
Enterprise API Importer runs structured API-to-WordPress ETL jobs.

It supports:
- Multiple import connections
- JSON array-path extraction
- Rule-based data filtering (Key/Operator/Value, AND logic) before staging
- Template-based mapping into imported_item posts
- Per-import recurrence (off, hourly, twicedaily, daily, custom minutes)
- Queue-based processing for safer long runs
- Schedules and health dashboard

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to EAPI -> Manage Imports to create your first import.

== Frequently Asked Questions ==
= Does this require WP-Cron? =
Yes. For production reliability, configure a real server cron to trigger wp-cron.php.

== Changelog ==
= 0.1.0 =
* Initial public release.

== Upgrade Notice ==
= 0.1.0 =
Initial release.
