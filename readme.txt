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
- Twig template-based mapping into imported_item posts (loops, conditionals, nested data)
- Per-import recurrence (off, hourly, twicedaily, daily, custom minutes)
- Queue-based processing for safer long runs
- Schedules and health dashboard

Transform templates are stored in the import configuration table and rendered as strings through Twig ArrayLoader.
The transform context exposes the record payload as record, item, and data.
Auto-escaping is disabled so intended HTML is preserved in post_content.
Malformed templates are caught and logged with status Template Syntax Error, and the batch safely continues.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/.
2. In the plugin root, install Twig with Composer: composer require twig/twig
3. Activate the plugin through the Plugins menu in WordPress.
4. Go to EAPI -> Manage Imports to create your first import.

== Frequently Asked Questions ==
= Does this require WP-Cron? =
Yes. For production reliability, configure a real server cron to trigger wp-cron.php.

= What template syntax can I use? =
Twig syntax is supported, including:
- Variable output: {{ record.title }}
- Nested values: {{ record.course.name }}
- Conditionals: {% if record.isActive %}...{% endif %}
- Loops: {% for row in record.items %}...{% endfor %}

Built-in Twig helpers:
- Numeric test: {% if record.Amount is numeric %}...{% endif %}
- Currency filter: {{ record.Amount|format_us_currency }}
- Date filter: {{ record.BeginDate|format_date_mdy }} (MM/DD/YYYY when parsable)

== Changelog ==
= 0.1.0 =
* Initial public release.

== Upgrade Notice ==
= 0.1.0 =
Initial release.
