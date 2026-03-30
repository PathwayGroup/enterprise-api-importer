=== Enterprise API Importer ===
Contributors: tporret
Tags: api, import, etl, json, cron, twig
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise ETL importer for WordPress that turns complex API payloads into reliable, automation-ready content workflows.

== Description ==
Enterprise API Importer gives WordPress teams an enterprise-grade ETL pipeline for importing external API data with confidence.

Use Enterprise API Importer to run clean, repeatable import workflows without sacrificing flexibility:

- Multi-Connection Job Manager for organizing and scaling imports
- Advanced JSON array traversal and pre-stage data filtering to remove noise before insertion
- Twig Templating Engine for complex logic, loops, and nested object mapping without drag-and-drop limitations
- Twig-powered Post Title Templates with safe sanitization and fallback handling
- Per-import Target Post Type selection (posts, pages, and public custom post types)
- Time-aware batch processing via WP-Cron to reduce timeout and memory-risk scenarios
- Single-pane-of-glass Health and Schedules Dashboard for operational visibility
- Secure media sideload helper foundation with source URL deduplication support

Whether you import catalogs, directory records, listings, events, or custom business data, Enterprise API Importer provides a scalable framework for structured API-to-WordPress ETL.

Built for real-world production workflows:

- Title templates are rendered via Twig and sanitized before save
- Target post type safely falls back to post if invalid or unavailable
- Attachment is excluded by default from target post type selection
- Imports are staged and processed in queue batches for safer long-running jobs

== Installation ==
1. Upload the plugin folder to the /wp-content/plugins/ directory, or install it through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to EAPI -> Manage Imports to configure your API connections, filtering rules, target post type, and Twig templates.

== Frequently Asked Questions ==
= Does this require WP-CLI? =
No. It uses native WP-Cron scheduling, but supports CLI triggers.

= Can I parse nested JSON arrays? =
Yes. Nested objects and arrays can be parsed and transformed through Twig templating.

= Does it handle API authentication? =
Yes. It supports Bearer Tokens and custom headers.

= Can I set dynamic titles for imported posts? =
Yes. Use Post Title Template with Twig syntax. If left blank, the importer falls back to Imported Item {ID}.

= Can I import into custom post types? =
Yes. Select any public post type from Target Post Type. If the selected post type becomes unavailable, imports safely fall back to post.

= Does this import media attachments automatically from URL fields? =
The plugin includes a secure media sideload helper foundation with source URL deduplication. Full field-level media mapping workflows can be added on top of this helper.

== External services ==
This plugin connects to external APIs that you configure in each import job.

- What service is used: Your configured API endpoint URL.
- What data is sent: Request headers (including optional Bearer token) and normal HTTP request metadata.
- When data is sent: During endpoint tests, dry-run template previews, and scheduled/manual import runs.
- Why data is sent: To fetch remote JSON payloads for preview, transform, and import workflows.

The plugin does not hardcode any third-party API vendor. Data destination, terms, and privacy practices depend on the endpoint(s) you configure.

== Screenshots ==
1. The Twig Mapping Interface for transforming JSON data.
2. The Schedules and Logs Health Dashboard.
3. API Connection and Data Filtering rules.
4. Post Title Template and Target Post Type controls on the Edit Import screen.

== Changelog ==
= 1.0.0 =
* Initial Release.
* Added dynamic Twig Post Title Template support with sanitization and fallback logic.
* Added Target Post Type selector for loading imports into posts, pages, or public custom post types.
* Added runtime fallback to post when target post type is invalid or missing.
* Added secure media sideload helper foundation with source URL deduplication metadata.

== Upgrade Notice ==
= 1.0.0 =
Initial stable release with enterprise ETL controls, Twig templating, dynamic post titles, and target post type support.
