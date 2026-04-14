=== Enterprise API Importer ===
Contributors: tporret
Donate link: https://github.com/sponsors/tporret
Tags: api, import, etl, json, cron
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enterprise ETL importer for WordPress that turns complex API payloads into reliable, automation-ready content workflows.

== Description ==
Enterprise API Importer gives WordPress teams an enterprise-grade ETL pipeline for importing external API data with confidence.

This readme is written for WordPress administrators and site owners evaluating or using the plugin from the Plugins screen.

Use Enterprise API Importer to run clean, repeatable import workflows without sacrificing flexibility:

- Multi-Connection Job Manager for organizing and scaling imports
- React Tabbed Import Job Workspace (Source/Auth, Data Rules, Mapping/Templating, Automation)
- Advanced JSON array traversal and pre-stage data filtering to remove noise before insertion
- Twig Templating Engine for complex logic, loops, and nested object mapping without drag-and-drop limitations
- Twig-powered Post Title Templates with safe sanitization and fallback handling
- Optional templates for new jobs (start with connection setup, add templates later)
- Multiple API auth modes: none, bearer token, custom API-key header, and basic auth
- Per-import Target Post Type selection (posts, pages, and public custom post types)
- Per-import Default Target Settings (post status, post author, comment status, pingback/trackback status)
- Per-import editing lock toggle for imported posts (allow editing or enforce read-only)
- Time-aware batch processing via WP-Cron to reduce timeout and memory-risk scenarios
- Multisite support with per-site importer dashboards and an optional Network Admin summary dashboard when the plugin is also active on the primary site
- **[New v1.2] Tableau-Style Reporting Dashboard:** Real-time metrics on environment health, security posture, and API performance with interactive charts, status indicators, and audit activity feed
- **[New] Credential Encryption & REST Masking:**
  - AES-256-CBC encryption at rest for auth_token and auth_password fields
  - REST GET responses mask credentials; boolean flags indicate stored state
  - Blank credential fields on update preserve existing encrypted values
  - React UI shows saved-credential indicators with placeholder text
  - apply_filters no longer exposes raw tokens to third-party hooks
- **[New] Import Pipeline Sanitization:**
  - Twig-rendered post content sanitized via wp_kses_post before wp_insert_post
  - Custom meta values sanitized via sanitize_text_field before update_post_meta
  - Admin menu pages restricted to manage_options capability (was read)
- **[New] Enterprise-Grade Security Hardening:**
  - Dedicated template management capability with multisite support
  - Twig template security validation (disallowed tags, size/complexity limits, syntax checking)
  - Template change audit logging with before/after hashes and actor metadata
  - SSRF prevention via hostname and CIDR allowlisting with DNS resolution
  - Twig strict variables mode enabled by default for better error visibility
  - Imported items locked read-only (no editing, deletion, or quick-edit)

Whether you import catalogs, directory records, listings, events, or custom business data, Enterprise API Importer provides a scalable framework for structured API-to-WordPress ETL.

Built for real-world production workflows:

- Title templates are rendered via Twig and sanitized before save
- Target post type safely falls back to post if invalid or unavailable
- Attachment is excluded by default from target post type selection
- Default target post settings are validated and applied at load time for consistent publishing/discussion behavior
- Imports are staged and processed in queue batches for safer long-running jobs
- Imported items are cryptographically linked to their origin import (read-only)
- Template configuration changes are audit-logged with full actor context
- Endpoints validated against configurable SSRF allowlists and HTTPS enforcement
- Inline API connection testing, sample payload preview, and template dry-run rendering from the edit workspace

== Installation ==
1. Upload the plugin folder to the /wp-content/plugins/ directory, or install it through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to EAPI -> Manage Imports to configure your API connections, filtering rules, target post type, and Twig templates.

Multisite note: activate the plugin on each subsite that should run imports. If you also want the Network Admin summary dashboard, activate it on the primary site too. Do not use Network Activate; that mode is intentionally not supported.

== Frequently Asked Questions ==
= Does this require WP-CLI? =
No. It uses native WP-Cron scheduling, but supports CLI triggers.

= Can I parse nested JSON arrays? =
Yes. Nested objects and arrays can be parsed and transformed through Twig templating.

= Does it handle API authentication? =
Yes. It supports four modes per import job: none, bearer token, custom API-key header, and basic auth.

= Can I set dynamic titles for imported posts? =
Yes. Use Post Title Template with Twig syntax. If left blank, the importer falls back to Imported Item {ID}.

= Can I import into custom post types? =
Yes. Select any public post type from Target Post Type. If the selected post type becomes unavailable, imports safely fall back to post.

= How does this work on multisite? =
Each subsite keeps its own import jobs, schedules, settings, and dashboard. A separate Network Admin dashboard can summarize active subsites when the plugin is also active on the primary site. Activate the plugin per site; Network Activate is intentionally blocked.

= Does this import media attachments automatically from URL fields? =
The plugin includes a secure media sideload helper foundation with source URL deduplication. Full field-level media mapping workflows can be added on top of this helper.

= Who can edit imported items and templates? =
Template configuration requires the `eai_manage_templates` capability or `manage_options` role. Multisite super admins always have access. Imported item editing can now be controlled per import job with the "Lock editing of imported posts" setting.

= What Twig features are blocked for security? =
The following Twig tags are disallowed to prevent file inclusion and code injection: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro`.

= Can I allow imports from internal/private networks? =
Yes, but not recommended for production. Go to Settings → Allow Internal Endpoints to permit RFC1918 and loopback addresses. For whitelisting specific hosts/CIDR blocks, use Settings → Allowed Endpoint Hosts and Settings → Allowed Endpoint CIDR Blocks.

= Why does the dashboard show a security warning on a new install? =
The SSRF Hardening check starts in a warning state until you configure Allowed Endpoint Hosts or Allowed Endpoint CIDR Blocks. That warning is there to remind you that outbound API access is still open until you add an allowlist.

= What should I configure in EAPI → Settings first? =
Start with Allowed Endpoint Hosts and list only trusted API domains. Leave Allow Internal Endpoints disabled unless you intentionally import from internal services. Add CIDR blocks only when you need additional IP-range restrictions.

= Where are template changes logged? =
All template configuration changes are logged to the wp_custom_import_logs database table with before/after hashes, actor information, and precise timestamps. Review logs via EAPI → Manage Imports → Import edit screen.

== External services ==
This plugin connects to external APIs that you configure in each import job.

- What service is used: Your configured API endpoint URL.
- What data is sent: Request headers (including optional Bearer token) and normal HTTP request metadata.
- When data is sent: During endpoint tests, dry-run template previews, and scheduled/manual import runs.
- Why data is sent: To fetch remote JSON payloads for preview, transform, and import workflows.

**Security policy notes:**

- Configure only trusted API endpoints that you control or explicitly trust.
- **HTTPS is required by default** for all endpoints (can be disabled via code filter for development).
- **Private/internal network hosts are blocked by default** (RFC1918 ranges and loopback). Enable only if you need to import from internal APIs in controlled environments.
- **Hostname allowlisting:** Restrict imports to specific domains (exact or wildcard subdomains). Configure at Settings → Allowed Endpoint Hosts.
- **CIDR allowlisting:** Restrict imports to specific IPv4/IPv6 network ranges. Configure at Settings → Allowed Endpoint CIDR Blocks.
- **Endpoint validation:** All endpoints are validated before request execution.
- **Audit logging:** All endpoint changes and template modifications are logged with full actor context.

The plugin does not hardcode any third-party API vendor. Data destination, terms, and privacy practices depend on the endpoint(s) you configure.

== Screenshots ==
1. The Twig Mapping Interface for transforming JSON data.
2. The Schedules and Logs Health Dashboard.
3. API Connection and Data Filtering rules.

== Changelog ==
= 1.2.4 =
* Aligned Twig dry-run template normalization with save-time normalization so preview output matches persisted output.
* Expanded mapping template HTML allowlist to support modern layout markup and CSS hooks (`article`, `header`, `section`, `footer`, `div`, `span`, and `class` attributes on common tags).
* Kept mapping allowlist behavior consistent across dry-run, REST import-job save, and classic admin save handlers.

= 1.2.3 =
* Added multisite Network Admin dashboard support while preserving per-site importer dashboards.
* Added multisite activation safeguards so unsupported Network Activate attempts are blocked and reverted.
* Added multisite admin guidance for primary-site activation requirements and network dashboard availability.
* Softened the SSRF Hardening reporter so fresh installs show a warning until an endpoint allowlist is configured.
* Tightened and de-duplicated release packaging workflows.

= 1.2.2 =
* Security hardening release.
* Encrypted credential storage at rest for auth_token and auth_password.
* REST import-job GET now masks credentials and returns has_auth_token / has_auth_password flags.
* Update handlers preserve existing encrypted credentials when masked fields are submitted blank.
* Removed raw token exposure from eai_remote_request_args filter context.
* Added post-content sanitization with wp_kses_post before post insert/update.
* Added custom-meta sanitization with sanitize_text_field before update_post_meta.
* Tightened admin menu capability from read to manage_options.

= 1.2.1 =
* Maintenance release from cleaned git history.
* Removed accidentally committed documentation/test-data directories from repository history and release packaging.
* No runtime feature changes.

= 1.2.0 =
* **Updated: React Import Job Workspace**
  - Replaced the legacy linear create/edit form with a tabbed workspace in wp-admin.
  - Added sticky footer actions for save, run now, and update existing item content.
* **Updated: REST Import Job CRUD + actions**
  - Added REST routes for create/read/update import jobs and run/template sync actions.
  - Added centralized sanitization for import job payloads used by create/update handlers.
* **Updated: API validation and preview tooling**
  - Added in-workspace API test button with sample record preview support.
  - Added Twig dry-run test support from the Mapping & Templating tab.
* **Updated: Authentication model**
  - Added four auth methods per import job: none, bearer, custom API-key header, and basic auth.
* **Updated: Optional templates for new imports**
  - New imports can be created without title or mapping templates; templates can be added later.
* **Updated: Per-import editing lock control**
  - Added "Lock editing of imported posts" toggle in Mapping & Templating.
  - Edit/delete restrictions now apply by import configuration and can be disabled per job.
* **Updated: Default target post settings**
  - Added per-import defaults for Post Status, Post Author, Comment Status, and Pingback/Trackback Status in Mapping & Templating.
  - Added REST sanitization and persistence for these fields in import job create/update handlers.
  - Import load now applies these defaults during `wp_insert_post()` and sync updates discussion settings during `wp_update_post()`.
* **New: Enterprise Reporting Dashboard (Tableau-Style)**
  - Real-time operations command center with global health status badge and force-refresh controls.
  - Nine enterprise-grade metrics across three pillars:
    - **Environment Health:** Cron heartbeat, queue depth, daily success rate.
    - **Security & Compliance:** SSRF hardening status, audit integrity tracking, protocol enforcement (HTTPS vs HTTP).
    - **Connectivity & Performance:** API latency trends, active endpoint count, hourly throughput.
  - High-performance React UI with Recharts data visualization and Lucide icons.
  - Responsive grid layout with KPI stat cards (sparkline trends), status lists, donut charts, area charts, and system pulse marquee.
  - REST API endpoints: `/wp-json/eapi/v1/dashboard` (metrics) and `/wp-json/eapi/v1/dashboard/history` (time-series data).
  - Transient-backed caching (600s TTL) with force-refresh capability.
  - Shimmer loading states and dark/light theme-aware styling via Tailwind CSS (namespaced to avoid wp-admin conflicts).
  - Menu: EAPI → Dashboard (requires read capability).
* **Credential Encryption & Data Sanitization**
  - Credential fields (auth_token, auth_password) are now encrypted at rest with AES-256-CBC using a key derived from wp_salt('auth').
  - REST GET responses mask credential values and expose boolean has_auth_token / has_auth_password flags.
  - Credential preservation on update: blank credential fields retain existing encrypted values.
  - React auth fields show "Credential saved" indicators with placeholder text for stored credentials.
  - apply_filters('eai_remote_request_args') no longer passes raw token — passes auth_method instead.
  - Twig-rendered post content now passes through wp_kses_post() before wp_insert_post() (stored XSS prevention).
  - Twig-compiled custom meta values now pass through sanitize_text_field() before update_post_meta().
  - Admin menu pages capability changed from 'read' to 'manage_options' (subscriber access blocked).
* **Enhanced Security Hardening (Enterprise-Grade)**
  - Added dedicated `eai_manage_templates` capability for template configuration access (separate from `manage_options`).
  - Implemented multisite-aware permission system: `eai_manage_templates` OR `manage_options` OR `is_super_admin()`.
  - Locked imported items as read-only (no editing, deletion, or quick-edit permissions via `map_meta_cap`).
  - Added comprehensive Twig template security validator:
    - Disallowed tags: include, source, import, from, embed, extends, use, macro (prevents SSTI and file inclusion).
    - Size limits: 50 KB for mapping templates, 2 KB for title templates (filterable).
    - Expression count limit: 250 default (filterable).
    - Nesting depth limit: 12 levels default (filterable).
    - Parse-time syntax validation via Twig tokenizer.
  - Added template change audit logging:
    - Logs to wp_custom_import_logs with before/after SHA256 hashes.
    - Captures actor metadata (user login, role, display name).
    - Tracks template lengths and create vs. update distinction.
  - Implemented SSRF prevention via allowlist system:
    - Hostname allowlisting (exact + wildcard *.example.com support).
    - CIDR allowlisting (IPv4 and IPv6 support).
    - DNS resolution for hostname validation.
    - Settings UI for managing allowed hosts and CIDR blocks.
  - Enabled Twig strict variables mode by default (prevents undefined variable silent rendering).
  - Added Settings page (EAPI → Settings) for endpoint security configuration.

= 1.0.0 =
* Initial Release.
* Added dynamic Twig Post Title Template support with sanitization and fallback logic.
* Added Target Post Type selector for loading imports into posts, pages, or public custom post types.
* Added runtime fallback to post when target post type is invalid or missing.
* Added secure media sideload helper foundation with source URL deduplication metadata.

== Upgrade Notice ==
= 1.2.4 =
Dry-run rendering now mirrors save-time template normalization, and mapping template HTML allowlist support has been expanded for card-style/class-based templates while preserving centralized safety checks.

= 1.2.3 =
Multisite release. Adds a Network Admin summary dashboard, preserves per-site dashboards, blocks unsupported network activation, and changes the default SSRF dashboard state on new installs from critical to warning.

= 1.2.2 =
Security hardening release. Includes encrypted credential storage, masked REST credential responses, safer filter context handling, stronger content/meta sanitization, and tighter admin capability checks.

= 1.2.1 =
Maintenance release with cleaned repository history and packaging only. No functional upgrade steps required.

= 1.2.0 =
Security and UX release: Includes the new React import workspace, expanded auth methods, API preview/dry-run tools, optional templates on job creation, and per-import editing lock controls, plus enterprise hardening features (capabilities, Twig validation, audit logs, SSRF allowlisting).
= 1.0.0 =
Initial stable release with enterprise ETL controls, Twig templating, dynamic post titles, and target post type support.
