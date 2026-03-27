# Enterprise API Importer

Enterprise API Importer is a WordPress plugin for running structured API-to-WordPress ETL jobs.
It supports multiple import connections, staged processing, job health visibility, and per-import recurring schedules.

## What this plugin does

- Creates and manages multiple import jobs from wp-admin.
- Pulls JSON data from external endpoints (Bearer token optional).
- Resolves array payloads via configurable JSON path.
- Filters records before staging using configurable rule-based conditions.
- Maps records into `imported_item` posts using HTML template placeholders.
- Supports configurable unique ID path per import (defaults to `id`).
- Processes records in batches through a queue worker for safer long runs.
- Supports per-import recurrence:
  - `off`
  - `hourly`
  - `twicedaily`
  - `daily`
  - `custom` (every N minutes)
- Provides schedules and health dashboard with trigger source and run details.
- Includes endpoint testing and data preview before running an import.

## Quickstart

### 1. Install and activate

1. Place this plugin folder in `wp-content/plugins/`.
2. In WordPress admin, go to Plugins.
3. Activate Enterprise API Importer.

On activation, the plugin creates/migrates required tables.

### 2. Create your first import job

1. Go to **EAPI -> Manage Imports**.
2. Click **Add New**.
3. Fill in:
   - Name
   - Endpoint URL
   - Bearer Token (if needed)
   - JSON Array Path (optional)
   - Unique ID Path (for example `CourseIDFull`; defaults to `id`)
  - Data Filters (optional): Key, Operator, Value rows using AND logic
   - Recurrence (Off/Hourly/Twice Daily/Daily/Custom)
   - Custom minutes (only for Custom recurrence)
   - Mapping Template
4. Save the import.

### 3. Validate endpoint before import

1. Open the import job edit page.
2. Use **Endpoint Test & Preview**.
3. Review payload shape, sample keys, and preview output.

### 4. Run import

1. Click **Run Import Now** on the import edit page, or
2. Use **EAPI -> Schedules** and click **Run Now** for that import.

### 5. Monitor health

1. Go to **EAPI -> Schedules**.
2. Review:
   - Status
   - Trigger Source (`Manual Run`, `Run Now`, `Recurring Schedule`)
   - Last run metrics
   - Details
   - Next scheduled run

## Mapping template basics

The mapping template accepts placeholders wrapped in braces.

Examples:

- `{title}`
- `{CourseIDFull}`
- `{description|html}`
- `{data.course.name}`

Notes:

- Dot notation is supported for nested fields.
- `|html` allows safe HTML rendering for that placeholder.
- Missing fields render as empty strings.

## Detailed functional spec

### Admin surfaces

- Top-level menu: `EAPI`
- Submenus:
  - `Manage Imports`
  - `Schedules`

### Data model

Primary tables used by the plugin:

- `wp_eapi_imports`
  - stores import definitions (endpoint, auth, mapping, unique id path, recurrence)
- `wp_custom_import_temp`
  - staging area for extracted payload rows
- `wp_custom_import_logs`
  - run logs and processing details

### ETL workflow

1. **Extract**
   - Fetch endpoint payload.
   - Validate HTTP response and JSON decoding.
   - Resolve `array_path` target.
  - Apply configured Data Filters (AND logic) to records before staging.
2. **Stage**
   - Store serialized selected payload in temp table with `import_id`.
3. **Transform**
   - Normalize staged items.
   - Resolve external ID from configurable `unique_id_path`.
   - Render mapping template placeholders.
4. **Load**
   - Upsert into `imported_item` by:
     - `_my_custom_api_id`
     - `_eai_import_id`
   - Track `_last_synced_timestamp`.
5. **Finalize**
   - Mark staged rows processed.
   - Trash orphaned imported posts for this import scope.
   - Write log with counts, errors, and trigger source.

### Scheduling model

The plugin uses two trigger paths:

- **Recurring trigger**
  - Hook: `eai_recurring_import_trigger`
  - Registered per import when recurrence is enabled.
- **Immediate trigger (Run Now)**
  - Hook: `ncsu_api_importer_batch_hook`

Both trigger paths feed the same import processor.
Long-running jobs are chunked via queue worker:

- Hook: `eai_process_import_queue`

### Trigger source tracking

Each run carries a trigger source in logs:

- `manual`
- `run_now`
- `recurring`

This is exposed in the Schedules dashboard as user-friendly labels.

### Security model

- Admin actions are restricted with capability checks (`manage_options`).
- Forms and actions use nonces for CSRF protection.
- Inputs are sanitized before persistence.
- Output is escaped in admin views.

## Operational notes

- WP-Cron execution depends on site traffic unless a real cron is configured.
- For production reliability, consider a server cron hitting `wp-cron.php`.
- If an import appears idle, check recurring setting and next scheduled run in Schedules.

## File structure

- `enterprise-api-importer.php` plugin bootstrap
- `includes/core.php` activation and schema migration
- `includes/content.php` custom post type registration
- `includes/import.php` ETL engine, queue, and cron logic
- `includes/admin.php` admin UI and admin-post handlers
- `includes/class-eapi-imports-list-table.php` import list table

## Development notes

Recommended quick checks:

```bash
php -l enterprise-api-importer.php
php -l includes/core.php
php -l includes/content.php
php -l includes/import.php
php -l includes/admin.php
php -l includes/class-eapi-imports-list-table.php
```

## License

GPL-2.0-or-later
