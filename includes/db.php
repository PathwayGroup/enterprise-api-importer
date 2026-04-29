<?php
/**
 * Database access layer for Enterprise API Importer.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TPORAPDI_CACHE_GROUP' ) ) {
	define( 'TPORAPDI_CACHE_GROUP', 'tporapdi_plugin' );
}

/**
 * Returns the imports table name.
 *
 * @return string
 */
function tporapdi_db_imports_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'tporapdi_imports';
}

/**
 * Returns the temporary staging table name.
 *
 * @return string
 */
function tporapdi_db_temp_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'custom_import_temp';
}

/**
 * Returns the logs table name.
 *
 * @return string
 */
function tporapdi_db_logs_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'custom_import_logs';
}

/**
 * Returns the multisite network dashboard snapshot table name.
 *
 * @return string
 */
function tporapdi_db_network_dashboard_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'tporapdi_network_dashboard_sites';
}

/**
 * Persists one multisite dashboard snapshot row.
 *
 * @param array<string, mixed> $snapshot Snapshot payload.
 *
 * @return bool
 */
function tporapdi_db_save_network_snapshot( array $snapshot ): bool {
	global $wpdb;

	$table = tporapdi_db_network_dashboard_table();
	$data  = array(
		'blog_id'            => absint( $snapshot['blog_id'] ?? 0 ),
		'site_url'           => esc_url_raw( (string) ( $snapshot['site_url'] ?? '' ) ),
		'site_name'          => sanitize_text_field( (string) ( $snapshot['site_name'] ?? '' ) ),
		'overall_status'     => sanitize_key( (string) ( $snapshot['overall_status'] ?? 'green' ) ),
		'health_status'      => sanitize_key( (string) ( $snapshot['health_status'] ?? 'green' ) ),
		'security_status'    => sanitize_key( (string) ( $snapshot['security_status'] ?? 'green' ) ),
		'performance_status' => sanitize_key( (string) ( $snapshot['performance_status'] ?? 'green' ) ),
		'import_count'       => absint( $snapshot['import_count'] ?? 0 ),
		'dashboard_data'     => wp_json_encode( $snapshot['dashboard_data'] ?? array() ),
		'updated_at'         => current_time( 'mysql', true ),
	);

	if ( $data['blog_id'] <= 0 ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$result = $wpdb->replace(
		$table,
		$data,
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
	);

	return false !== $result;
}

/**
 * Fetches multisite dashboard snapshots ordered by site name.
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_db_get_network_snapshots(): array {
	if ( ! is_multisite() ) {
		return array();
	}

	global $wpdb;
	$table = tporapdi_db_network_dashboard_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT blog_id, site_url, site_name, overall_status, health_status, security_status, performance_status, import_count, dashboard_data, updated_at FROM %i ORDER BY site_name ASC',
			$table
		),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Deletes one multisite dashboard snapshot row.
 *
 * @param int $blog_id Blog ID.
 * @return bool
 */
function tporapdi_db_delete_network_snapshot( int $blog_id ): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	global $wpdb;
	$table   = tporapdi_db_network_dashboard_table();
	$blog_id = absint( $blog_id );

	if ( $blog_id <= 0 ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$deleted = $wpdb->delete( $table, array( 'blog_id' => $blog_id ), array( '%d' ) );

	return false !== $deleted;
}

// Cache management is now owned by Tporapdi_Job_Repository.

/**
 * Fetches all import configurations (cache-backed).
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_db_get_import_configs(): array {
	return Tporapdi_Job_Repository::find_all();
}

/**
 * Fetches one import configuration by ID (cache-backed).
 *
 * @param int $import_id Import job ID.
 *
 * @return array<string, mixed>|null
 */
function tporapdi_db_get_import_config( int $import_id ): ?array {
	return Tporapdi_Job_Repository::find( $import_id );
}

/**
 * Fetches configured custom recurrence interval values (cache-backed).
 *
 * @return array<int, int>
 */
function tporapdi_db_get_custom_recurrence_minutes(): array {
	return Tporapdi_Job_Repository::find_custom_recurrence_minutes();
}

/**
 * Creates or updates one import configuration and invalidates cache.
 *
 * @param int                  $import_id Existing import ID or 0 for create.
 * @param array<string, mixed> $data      Columns and values to save.
 * @param array<int, string>   $formats   wpdb formats for $data.
 *
 * @return int|WP_Error Persisted import ID or WP_Error on failure.
 */
function tporapdi_db_save_import_config( int $import_id, array $data, array $formats ) {
	return Tporapdi_Job_Repository::save( $import_id, $data, $formats );
}

/**
 * Deletes one import configuration and invalidates cache.
 *
 * @param int $import_id Import job ID.
 *
 * @return bool
 */
function tporapdi_db_delete_import_config( int $import_id ): bool {
	return Tporapdi_Job_Repository::delete( $import_id );
}

/**
 * Deletes staging rows for one import job.
 *
 * @param int $import_id Import job ID.
 * @return int|false Number of deleted rows, or false on error.
 */
function tporapdi_db_delete_staging_rows_for_import( int $import_id ) {
	return Tporapdi_Queue_Repository::delete_for_import( $import_id );
}

/**
 * Deletes log rows for one import job.
 *
 * @param int $import_id Import job ID.
 * @return int|false Number of deleted rows, or false on error.
 */
function tporapdi_db_delete_log_rows_for_import( int $import_id ) {
	return Tporapdi_Log_Repository::delete_for_import( $import_id );
}

/**
 * Gets the next unprocessed staging rows for one import (real-time query).
 *
 * @param int $import_id Import job ID.
 * @param int $limit     Maximum number of rows to fetch.
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_db_get_unprocessed_staging_rows( int $import_id, int $limit = 10 ): array {
	return Tporapdi_Queue_Repository::get_unprocessed( $import_id, $limit );
}

/**
 * Counts unprocessed staging rows for one import (real-time query).
 *
 * @param int $import_id Import job ID.
 *
 * @return int
 */
function tporapdi_db_count_unprocessed_staging_rows( int $import_id ): int {
	return Tporapdi_Queue_Repository::count_pending( $import_id );
}

/**
 * Marks one staging row as processed (real-time queue mutation).
 *
 * @param int $row_id Temp row ID.
 *
 * @return bool
 */
function tporapdi_db_mark_staging_row_processed( int $row_id ): bool {
	return Tporapdi_Queue_Repository::mark_processed( $row_id );
}

/**
 * Inserts one staging payload row (real-time queue mutation).
 *
 * @param int    $import_id Import job ID.
 * @param string $raw_json  Serialized payload.
 *
 * @return int|WP_Error New row ID or WP_Error on failure.
 */
function tporapdi_db_insert_staging_payload( int $import_id, string $raw_json ) {
	return Tporapdi_Queue_Repository::enqueue( $import_id, $raw_json );
}

/**
 * Writes one import log record (real-time, uncached).
 *
 * @param int    $import_id      Import job ID.
 * @param string $import_run_id  Unique run identifier.
 * @param string $status         Final status.
 * @param int    $rows_processed Processed row count.
 * @param int    $rows_created   Created post count.
 * @param int    $rows_updated   Updated post count.
 * @param string $errors_json    JSON-encoded details string.
 * @param string $created_at     Created at in UTC mysql format.
 *
 * @return bool
 */
function tporapdi_db_insert_import_log( int $import_id, string $import_run_id, string $status, int $rows_processed, int $rows_created, int $rows_updated, string $errors_json, string $created_at ): bool {
	return Tporapdi_Log_Repository::insert( $import_id, $import_run_id, $status, $rows_processed, $rows_created, $rows_updated, $errors_json, $created_at );
}

/**
 * Gets latest log rows keyed by import_id (real-time, uncached).
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_db_get_latest_logs_indexed_by_import_id(): array {
	return Tporapdi_Log_Repository::latest_indexed_by_import();
}

/**
 * Gets recent created/updated metrics grouped by import ID.
 *
 * Used for compact trend sparklines in admin tables.
 *
 * @param int $points_per_import Number of recent points to keep per import.
 *
 * @return array<int, array<int, array<string, int>>>
 */
function tporapdi_db_get_recent_import_log_trends( int $points_per_import = 12 ): array {
	return Tporapdi_Log_Repository::trends( $points_per_import );
}

/**
 * Gets pending queue counts keyed by import_id (real-time, uncached).
 *
 * @return array<int, int>
 */
function tporapdi_db_get_pending_counts_by_import_id(): array {
	return Tporapdi_Queue_Repository::pending_counts_by_import();
}
