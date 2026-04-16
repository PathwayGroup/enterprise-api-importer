# OmniFetch - REST API ETL Importer

OmniFetch - REST API ETL Importer is a WordPress plugin for secure, repeatable API-to-WordPress ETL.
It is built for teams that need enterprise-grade reliability without losing flexibility.

This README is the technical overview for developers, maintainers, and operators working from the repository.
If you want the WordPress admin-facing plugin summary, installation help, and FAQ copy, use [readme.txt](readme.txt).

At a glance:

- Site builders get a guided import workspace, schedule controls, safety checks, and per-site dashboards.
- Developers get a staged, idempotent, template-driven pipeline with REST tooling, custom tables, and extendable reporters.

## Why This Plugin Exists

Most import tools break down when payloads are nested, schedules are long-running, or data quality is inconsistent.
This plugin is designed for those hard cases.

It gives you:

- Control over what records are imported.
- Control over how records are transformed.
- Control over where records are loaded (selected post type).
- Safety rails for long-running jobs and production operations.

## Core Capabilities

- Multi-import job manager in wp-admin.
- Multisite support with per-site importer dashboards and an optional Network Admin summary dashboard when the plugin is also active on the primary site.
- External API extraction with flexible auth methods (`none`, `bearer`, `api_key_custom`, `basic_auth`).
- JSON array path resolution for nested payloads.
- Rule-based pre-stage filtering (AND logic).
- Twig-based mapping templates for complex transformations.
- Twig-based post title templates with safe sanitization.
- Optional templates for new jobs (save connection first, map later).
- Configurable target post type per import job.
- Configurable default target post settings per import job (status, author, comment status, ping status).
- React-powered tabbed Import Job Workspace for add/edit flows.
- Queue-based batch processor to avoid timeout-heavy monolithic runs.
- Per-import recurrence schedules (`off`, `hourly`, `twicedaily`, `daily`, custom N-minute intervals).
- Health and schedule dashboard with run stats and trigger source visibility.
- Endpoint test, API data preview, and Twig dry-run tools before production execution.
- Per-import edit-lock toggle to allow or prevent wp-admin edits on imported posts.

## Latest Release (1.2.5)

- Plugin naming update for resubmission: the public plugin name is now OmniFetch - REST API ETL Importer.
- No functional changes are bundled with this release.

## Multisite Operation

In WordPress multisite, the plugin follows a per-site execution model with an optional network summary view.

- Activate the plugin on each subsite that should actually run imports.
- If you want the Network Admin dashboard, also activate the plugin on the primary site.
- Network Activate is intentionally blocked.
- Site admins continue to use each subsite dashboard; the Network Admin dashboard is a summary layer, not a replacement for local job management.

## Default Security Posture

The plugin ships locked down for production-oriented installs.

- HTTPS is required for remote endpoints unless you explicitly loosen that via code.
- Private and loopback targets are blocked by default unless you opt into internal endpoints.
- The SSRF Hardening reporter shows a warning on new installs until you configure an endpoint allowlist.
- Once Allowed Endpoint Hosts or Allowed Endpoint CIDR Blocks are configured, that reporter moves to a healthy state.

## Repository Audience

Use this repository README when you need technical context such as:

- how the import pipeline is structured
- what the reporting subsystem exposes
- how multisite activation is supposed to work
- what security defaults and extension points exist
- how to reason about packaging or testing the plugin from source

## What Was Recently Added

### 1) Dynamic Post Title Templates (Twig)

You can now define a `Post Title Template` per import.

- Uses Twig syntax (for example, `{{ user.first_name }} {{ user.last_name }}`).
- Rendered with the same context aliases: `record`, `item`, `data`.
- Rendered output is sanitized with `wp_strip_all_tags()`.
- Title is truncated to 255 characters via `mb_substr()` to avoid DB truncation issues.
- If blank (or render result is empty), fallback title remains `Imported Item {ID}`.

### 2) Target Post Type Selector

You can now choose the destination post type per import job.

- Dropdown is dynamically populated from public post types.
- Saved value is sanitized using `sanitize_key()`.
- Runtime fallback is automatic:
  - if empty, fallback to `post`
  - if unregistered on the site, fallback to `post`
- `attachment` is intentionally blocked by default for safety.

### 3) Default Target Post Settings (Status, Author, Comment, Ping)

Each import job can now define default publishing and discussion behavior for imported posts.

- Default Post Status: `draft`, `publish`, or `pending`.
- Post Author: selected from available WordPress users in the import workspace.
- Comment Status: `open` or `closed`.
- Pingback/Trackback Status: `open` or `closed`.

Implementation details:

- Values are validated and persisted with import-job REST create/update sanitization.
- Import load now maps these settings into `wp_insert_post()` payloads.
- Existing imported posts also receive discussion-setting updates through `wp_update_post()` during sync.

### 4) Secure Media Sideload Helper (Foundation)

A new static helper is available in the processing layer:

- `EAI_Import_Processor::sideload_image( $image_url, $post_id, $is_featured = false )`

Design goals:

- Idempotent media handling using `_eapi_source_url` matching.
- Secure download flow via `download_url()` and WP media APIs.
- Safe error handling with logs in `wp_custom_import_logs`.
- Optional featured image assignment via `set_post_thumbnail()`.

Note: this helper is now implemented and available for integration into your field mapping/media ingestion strategy.

### 5) Enterprise Reporting Engine & Dashboard (Tableau-Style)

A pluggable, high-performance reporting aggregator with real-time operational metrics and a React-based UI.

#### Architecture Overview

**Three-Tier Design:**

1. **Reporter Base Class** (`EAPI_Reporter_Base`)
   - Abstract base with transient-backed caching (600s TTL default)
   - Formatting helpers: `format_percentage()`, `format_time_ago()`, `get_status_color()`
   - Each reporter implements `calculate_metrics(): array` for domain logic

2. **Singleton Aggregator** (`EAPI_Reporting_Aggregator`)
   - Registry pattern for reporter instances
   - `register_reporter( EAPI_Reporter_Base $reporter )`
   - `get_dashboard_data()` returns nested array grouped by category (Health, Security, Performance)

3. **Nine Metric Modules (Out of the Box)**
   - **Environment Health:**
     - `Cron_Heartbeat`: Checks recurring import schedules vs `wp_next_scheduled()`
     - `Queue_Depth`: Counts unprocessed staging rows (with color-coded thresholds)
     - `Daily_Success_Rate`: Success/error ratio from last 24h logs
   - **Security & Compliance:**
     - `SSRF_Hardening`: Tests if endpoint allowlist is configured
     - `Audit_Integrity`: Counts template change audit entries (7 days)
     - `Protocol_Enforcement`: HTTPS vs HTTP endpoint ratio
   - **Connectivity & Performance:**
     - `API_Latency`: Average processing time from last 100 runs (with sparkline)
     - `Active_Connections`: Distinct endpoint URL count
     - `Throughput`: Total rows processed in last 60 minutes

#### REST API Routes

**Get aggregated metrics:**
```
GET /wp-json/eapi/v1/dashboard
```
Returns nested structure grouped by category and reporter ID.

```json
{
  "Health": {
    "daily_success_rate": {
      "label": "Daily Success Rate",
      "metrics": { "status": "green", "value": "98.5%", "detail": "..." }
    }
  },
  "Security": { ... },
  "Performance": { ... }
}
```

**Get time-series history (for charts and feeds):**
```
GET /wp-json/eapi/v1/dashboard/history
```
Returns sparkline data, latency charts, audit entries, and throughput trends (600s cache).

**Force refresh (flush transients):**
```
GET /wp-json/eapi/v1/dashboard?refresh=1
```

#### React Dashboard UI

**Components:**
- `Dashboard.js` – Main layout router with header, KPIs, 3-column grid, footer
- `StatCard.js` – KPI card with Lucide icon + Recharts sparkline (7-day trend)
- `StatusList.js` – Health checks with icons + progress bars
- `SecurityDonut.js` – Interactive donut chart (Recharts) with hover legends
- `LatencyChart.js` – Area chart for latency timeline
- `AuditMarquee.js` – Scrolling marquee of last 5 audit events

**Tech Stack:**
- React (via `@wordpress/element`)
- Recharts (SVG data viz)
- Lucide React (icons)
- Tailwind CSS (scoped with `eapi-` prefix to avoid wp-admin collisions)
- `@wordpress/api-fetch` for REST communication

**Menu Location:** `EAPI → Dashboard` (requires `read` capability)

#### Multisite Dashboard Behavior

- Subsite dashboards remain site-scoped and continue to show each site's own import health.
- The Network Admin dashboard is populated from multisite snapshot rows stored in a network-level table.
- Unsupported network activation is automatically rejected so the supported model stays explicit.

#### Extending the Reporting System

**Add a custom reporter:**

```php
class My_Custom_Reporter extends EAPI_Reporter_Base {
  protected string $id = 'my_metric';
  protected string $category = 'Performance';
  protected string $label = 'My Custom KPI';
  
  protected function calculate_metrics(): array {
    global $wpdb;
    
    // Your DB query logic here
    $count = $wpdb->get_var( ... );
    
    return array(
      'status' => $this->get_status_color( $count, array( 'green' => 10, 'yellow' => 50 ) ),
      'value'  => number_format_i18n( $count ),
      'detail' => 'Custom metric explanation.',
    );
  }
}

// Register on init
add_action( 'init', function() {
  $aggregator = EAPI_Reporting_Aggregator::get_instance();
  $aggregator->register_reporter( new My_Custom_Reporter() );
}, 20 ); // Run after eai_init_reporting (priority 10)
```

**Customize thresholds:**

All status-to-color logic uses `get_status_color( $value, ['green' => X, 'yellow' => Y] )` for consistent UX.

**Clear reporting cache:**

```php
eai_flush_reporting_transients(); // Clears all 9 reporter transients + history
```

**Monitoring via WP-Cron:** All metrics are calculated on-demand and cached, so no background jobs required. Transient eviction is natural.

### 6) Security Hardening — Credential Encryption & Data Sanitization

Credential fields (`auth_token`, `auth_password`) are now encrypted at rest using AES-256-CBC with a key derived from `wp_salt('auth')`. Encrypted values are prefixed with `eai_enc:` for identification — legacy plaintext values are handled gracefully during the transition.

- **REST GET masking**: Credential values are replaced with empty strings in API responses. Boolean flags (`has_auth_token`, `has_auth_password`) indicate whether a credential is stored.
- **Credential preservation on update**: When the frontend sends blank credential fields (because they were masked), the server preserves the existing encrypted values instead of overwriting with blanks.
- **React UI indicators**: Auth credential fields show "Credential saved. Leave blank to keep existing value." with a `••••••••` placeholder when a credential is already stored.
- **Filter safety**: `apply_filters( 'eai_remote_request_args', $args, $auth_method )` now passes the auth method string instead of the raw token.
- **Post content sanitization**: Twig-rendered content is passed through `wp_kses_post()` before `wp_insert_post()` to prevent stored XSS from untrusted API payloads.
- **Custom meta sanitization**: Twig-compiled meta values are sanitized with `sanitize_text_field()` before `update_post_meta()`.
- **Admin menu capability**: Menu pages now require `manage_options` instead of `read`, preventing subscriber-level access.

### 7) Enhanced Security Hardening (5-Layer Defense)

#### ✅ Dedicated Template Management Capability

- New capability: `eai_manage_templates` (assigned to Administrator role).
- Permission check: `eai_manage_templates` OR `manage_options` OR `is_super_admin()` on multisite.
- Imported content becomes read-only (no editing, deletion, or quick-edit allowed).
- Template configuration is locked to the new capability across:
  - Form save in wp-admin
  - REST endpoint dry-run/preview
  - Admin post handlers

**Extend capability assignment:**
```php
// Allow custom roles to manage templates
wp_cap_map_meta_cap( 'eai_manage_templates', $user_id );
add_role( 'api_manager', 'API Manager', array( 'eai_manage_templates' => true ) );
```

#### ✅ Twig Template Security Validation

- Pre-save and pre-render validation prevents dangerous Twig features.
- Enforcement rules:
  - Max template size: 50 KB (mappings), 2 KB (titles) — filterable via `eai_template_max_bytes`
  - Max expression count: 250 — filterable via `eai_template_max_expressions`
  - Disallowed tags: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro` (prevents SSTI/file inclusion)
  - Max nesting depth: 12 levels — filterable via `eai_template_max_nesting_depth`
  - Syntax validation: parsed via Twig tokenizer at save time

**Customize limits:**
```php
add_filter( 'eai_template_max_bytes', function() { return 100 * 1024; } ); // 100 KB
add_filter( 'eai_template_max_expressions', function() { return 500; } );
add_filter( 'eai_template_max_nesting_depth', function() { return 15; } );
```

#### ✅ Template Change Audit Logging

- All template configuration changes logged to `wp_custom_import_logs`.
- Audit metadata includes:
  - Actor (user login, role, and display name)
  - Before/after SHA256 hashes (change detection without storing full history)
  - Template lengths (payload size tracking)
  - Create vs. update distinction
  - Precise timestamp

**Query audit logs:**
```sql
SELECT * FROM wp_custom_import_logs 
WHERE type = 'template_config_change' 
ORDER BY timestamp DESC LIMIT 10;
```

#### ✅ SSRF Prevention (Endpoint Allowlisting)

- Endpoints are validated against hostname and CIDR allowlists.
- Available in: **Settings → Allowed Endpoint Hosts** and **Allowed Endpoint CIDR Blocks**.
- Validation flow:
  1. HTTPS required (opt-out via `eai_skip_https_check` filter)
  2. Private/internal IPs blocked by default (opt-in via settings checkbox)
  3. Hostname allowlist matching (exact + `*.example.com` wildcards)
  4. CIDR allowlist matching (IPv4 and IPv6 support)

**Programmatic allowlist configuration:**
```php
apply_filters( 'eai_allowed_endpoint_hosts', array( 'api.example.com', '*.internal.local' ) );
apply_filters( 'eai_allowed_endpoint_cidrs', array( '192.168.1.0/24', '10.0.0.0/8' ) );
apply_filters( 'eai_allow_internal_endpoints', false ); // default: block RFC1918/loopback
```

#### ✅ Twig Strict Variables by Default

- Strict variables mode enabled on Twig environment to prevent undefined variable silently rendering as empty.
- Missing variable access now raises `Twig\Error\RuntimeError` (caught and logged).
- Filterable via `eai_twig_strict_variables`:

```php
add_filter( 'eai_twig_strict_variables', function() { return false; } ); // permissive mode
```

### 8) React Import Job Workspace + REST CRUD

The import add/edit screen has been rebuilt as a React tabbed workspace using `@wordpress/components`.

- Tabs: Source & Auth, Data Rules, Mapping & Templating, Automation.
- Sticky action footer with Save, Run Import Now, and Update Existing Items.
- REST-backed job management for modern UI workflows:
  - `GET /wp-json/eapi/v1/import-jobs/{id}`
  - `POST /wp-json/eapi/v1/import-jobs`
  - `PUT /wp-json/eapi/v1/import-jobs/{id}`
  - `POST /wp-json/eapi/v1/import-jobs/{id}/run`
  - `POST /wp-json/eapi/v1/import-jobs/{id}/template-sync`
- API test endpoint (`/wp-json/eapi/v1/test-api-connection`) now powers in-UI sample preview for mapping.
- Dry-run endpoint (`/wp-json/eapi/v1/dry-run`) supports template verification before import runs.

### 9) Per-Import Edit-Lock Toggle

You can now control edit behavior per import job from Mapping & Templating.

- New setting: `lock_editing` (stored on the import config).
- When enabled, imported posts from that job are read-only in wp-admin (edit/delete/quick-edit restricted).
- When disabled, imported posts remain fully editable by normal WordPress permissions.
- Applies to all imported post types (not only the `imported_item` CPT).

## Data Flow (ETL)

1. Extract
- API payload is fetched.
- JSON is validated and decoded.
- `array_path` is resolved to the target data collection.

2. Filter
- Configured filter rules are applied before staging.
- Only matching rows move forward.

3. Stage
- Selected rows are written to `wp_custom_import_temp`.
- This decouples remote API latency from transform/load runtime.

4. Transform
- Data is normalized.
- Unique external key is resolved via `unique_id_path`.
- Twig template renders post content.
- Optional Twig title template renders post title.

5. Load
- Upsert logic identifies records by `_my_custom_api_id` + `_eai_import_id`.
- Records are inserted/updated in the selected post type.
- Sync timestamp and ownership metadata are maintained.

6. Finalize
- Staging rows are marked processed.
- Orphan handling and run logging complete the cycle.

## Admin Workflow (Step-by-Step)

### 1. Create an Import Job

Go to `EAPI -> Manage Imports` and click `Add New`.

Use the tabbed workspace to configure the job in phases.

Configure:

- Name
- Endpoint URL
- Auth Method (`none`, `bearer`, `api_key_custom`, `basic_auth`)
- JSON Array Path (optional)
- Unique ID Path (optional, defaults to `id`)
- Recurrence and custom interval (if needed)
- Target Post Type
- Lock editing of imported posts (optional)
- Data Filters
- Post Title Template (optional Twig)
- Mapping Template (optional Twig for initial creation)

### 2. Test Before Running

Use `Endpoint Test & Preview` from the import edit screen.

This validates connectivity and lets you inspect:

- payload shape
- sample keys
- normalized preview
- mapped output preview

### 3. Run and Monitor

Run manually from the import edit screen or from `EAPI -> Schedules`.
Track status, run counts, and errors from the dashboard.

### 4. Configure Endpoint Security Settings

Go to `EAPI -> Settings` to control endpoint allowlisting behavior used by endpoint tests, dry runs, and import runs.

Available settings:

- Allowed Endpoint Hosts: exact hosts and wildcard subdomains (for example, `api.example.com`, `*.internal.example.com`).
- Allowed Endpoint CIDR Blocks: IPv4/IPv6 ranges to constrain resolved endpoint IPs.
- Allow Internal Endpoints: optional override to permit private/internal and loopback targets.

Recommended baseline:

- Keep `Allow Internal Endpoints` disabled in production.
- Restrict `Allowed Endpoint Hosts` to trusted domains you control.
- Use `Allowed Endpoint CIDR Blocks` only when you need explicit network-level constraints.

## Security and Reliability Model

### Credential Storage & Handling
- **AES-256-CBC encryption at rest**: `auth_token` and `auth_password` are encrypted before database storage using a key derived from `wp_salt('auth')`.
- **Transparent decryption**: Credentials are decrypted transparently at the DB read layer for import execution.
- **REST API masking**: GET responses never expose credentials — fields are replaced with empty strings and `has_auth_token` / `has_auth_password` boolean flags.
- **Credential preservation**: Blank credential fields on update are treated as "unchanged" — existing encrypted values are preserved.
- **Filter safety**: `apply_filters( 'eai_remote_request_args' )` no longer passes raw credentials to third-party hooks.

### Capability-Based Access Control
- **Admin menu access**: Restricted to `manage_options` capability (administrators only).
- **Template management**: Restricted to `eai_manage_templates` capability (or `manage_options` / multisite super-admin).
- **Import operations**: Guarded by permission checks on admin pages and REST endpoints.
- **Imported content**: Read-only behavior is configurable per import job (`lock_editing`) via `map_meta_cap` filter.

### Template & Twig Security
- **Validation layers**: Pre-save, pre-render, and REST dry-run all validate template safety.
- **Disallowed features**: `include`, `source`, `import`, `from`, `embed`, `extends`, `use`, `macro` tags blocked.
- **Resource limits**: Size, expression count, and nesting depth throttled to prevent DoS.
- **Strict variables**: Undefined variable access caught and logged vs. silently rendering as empty.
- **Audit trail**: All template changes logged with before/after hashes and actor metadata.

### Network & SSRF Defense
- **HTTPS enforcement**: All endpoints must use HTTPS (filterable via `eai_skip_https_check`).
- **Private IP blocking**: RFC1918 and loopback addresses blocked by default (opt-in via settings).
- **Hostname allowlisting**: Exact matching and wildcard subdomain patterns (e.g., `*.internal.local`).
- **CIDR allowlisting**: IPv4 and IPv6 network ranges supported and validated via `inet_pton()`.

### Import Pipeline Sanitization
- **Post content**: Twig-rendered content is passed through `wp_kses_post()` before `wp_insert_post()` to prevent stored XSS from malicious API data.
- **Custom meta values**: Twig-compiled meta values are sanitized with `sanitize_text_field()` before `update_post_meta()`.
- **Post titles**: Rendered titles are stripped of all HTML via `wp_strip_all_tags()` and truncated to 255 characters.

### Data Handling & Nonces
- Input sanitization before database persistence.
- Output escaping in admin views and REST responses.
- Nonce checks on admin-post actions and REST endpoints.
- Queue-first architecture isolates long-running workloads.
- Staging table isolation supports resumable processing with audit trails.

## Database Tables

- `wp_eapi_imports`
  - import definitions and processing settings
  - includes fields such as:
    - endpoint/auth/paths
    - recurrence settings
    - `target_post_type`
    - `lock_editing`
    - `title_template`
    - `mapping_template`

- `wp_custom_import_temp`
  - staged payload fragments per import

- `wp_custom_import_logs`
  - run outcomes, row counts, and error details

## Scheduling Model

- Recurring trigger hook: `eai_recurring_import_trigger`
- Immediate trigger hook: `ncsu_api_importer_batch_hook`
- Queue worker hook: `eai_process_import_queue`

Each run tracks trigger context (`manual`, `run_now`, `recurring`) for operational traceability.

## Twig Examples

Basic value:

```twig
{{ record.title }}
```

Nested path:

```twig
{{ record.course.name }}
```

Loop:

```twig
<ul>
{% for module in record.modules %}
  <li>{{ module.name }}</li>
{% endfor %}
</ul>
```

Conditional:

```twig
{% if record.isActive %}
  <p>Active</p>
{% endif %}
```

Title template example:

```twig
{{ record.last_name }}, {{ record.first_name }}
```

Built-in Twig extensions:

- test: `numeric`
- filter: `format_us_currency`
- filter: `format_date_mdy`

## Requirements

- WordPress 6.x
- PHP 8.1+
- Composer dependency: `twig/twig`

Install dependency in plugin root:

```bash
composer require twig/twig
```

## Operational Recommendations

- Use real server cron for production reliability.
- Validate endpoint previews before enabling recurrence.
- Keep mapping/title templates under source control (copy into your repo/docs).
- Start with smaller intervals and observe logs before scaling throughput.

## File Map

- `enterprise-api-importer.php` bootstrap
- `includes/core.php` activation and schema migrations
- `includes/content.php` CPT registration
- `includes/import.php` ETL engine, queue, cron orchestration, media helper
- `includes/admin.php` admin UI and admin-post handlers
- `includes/db.php` database access and log writes
- `includes/class-eapi-imports-list-table.php` admin list rendering

## Developer Quick Checks

```bash
php -l enterprise-api-importer.php
php -l includes/core.php
php -l includes/content.php
php -l includes/db.php
php -l includes/import.php
php -l includes/admin.php
php -l includes/class-eapi-imports-list-table.php
```

## License

GPL-2.0-or-later
