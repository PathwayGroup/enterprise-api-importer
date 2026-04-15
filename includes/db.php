<?php
declare( strict_types=1 );
/**
 * Database access layer for Enterprise API Importer.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EAI_CACHE_GROUP' ) ) {
	define( 'EAI_CACHE_GROUP', 'eapi_plugin' );
}

/**
 * Returns the imports table name.
 *
 * @return string
 */
function eai_db_imports_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'eapi_imports';
}

/**
 * Returns the temporary staging table name.
 *
 * @return string
 */
function eai_db_temp_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'custom_import_temp';
}

/**
 * Returns the logs table name.
 *
 * @return string
 */
function eai_db_logs_table(): string {
	global $wpdb;

	return $wpdb->prefix . 'custom_import_logs';
}

/**
 * Returns the multisite network dashboard snapshot table name.
 *
 * @return string
 */
function eai_db_network_dashboard_table(): string {
	global $wpdb;

	return $wpdb->base_prefix . 'eapi_network_dashboard_sites';
}

/**
 * Persists one multisite dashboard snapshot row.
 *
 * @param array<string, mixed> $snapshot Snapshot payload.
 *
 * @return bool
 */
function eai_db_save_network_snapshot( array $snapshot ): bool {
	global $wpdb;

	$table = eai_db_network_dashboard_table();
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
function eai_db_get_network_snapshots(): array {
	if ( ! is_multisite() ) {
		return array();
	}

	global $wpdb;
	$table = eai_db_network_dashboard_table();

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
function eai_db_delete_network_snapshot( int $blog_id ): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	global $wpdb;
	$table  = eai_db_network_dashboard_table();
	$blog_id = absint( $blog_id );

	if ( $blog_id <= 0 ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$deleted = $wpdb->delete( $table, array( 'blog_id' => $blog_id ), array( '%d' ) );

	return false !== $deleted;
}

/**
 * Builds the cache key for one import config row.
 *
 * @param int $import_id Import job ID.
 *
 * @return string
 */
function eai_db_import_config_cache_key( int $import_id ): string {
	return 'import_config:' . absint( $import_id );
}

/**
 * Invalidates all import-configuration cache entries.
 *
 * @param int $import_id Import ID that changed.
 *
 * @return void
 */
function eai_db_invalidate_imports_cache( int $import_id = 0 ): void {
	$import_id = absint( $import_id );

	wp_cache_delete( 'import_configs:all', EAI_CACHE_GROUP );
	wp_cache_delete( 'import_configs:custom_intervals', EAI_CACHE_GROUP );

	if ( $import_id > 0 ) {
		wp_cache_delete( eai_db_import_config_cache_key( $import_id ), EAI_CACHE_GROUP );
	}
}

/**
 * Fetches all import configurations (cache-backed).
 *
 * @return array<int, array<string, mixed>>
 */
function eai_db_get_import_configs(): array {
	$cache_key = 'import_configs:all';
	$cached    = wp_cache_get( $cache_key, EAI_CACHE_GROUP );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$table = eai_db_imports_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, name, endpoint_url, auth_method, auth_token, auth_header_name, auth_username, auth_password, array_path, unique_id_path, recurrence, custom_interval_minutes, filter_rules, target_post_type, featured_image_source_path, title_template, mapping_template, lock_editing, post_status, comment_status, ping_status, custom_meta_mappings, created_at
			FROM %i
			ORDER BY id DESC",
			$table
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		$rows = array();
	}

	// Decrypt credentials transparently so consumers receive plaintext.
	$rows = array_map( 'eai_decrypt_import_credentials', $rows );

	wp_cache_set( $cache_key, $rows, EAI_CACHE_GROUP, 60 );

	return $rows;
}

/**
 * Fetches one import configuration by ID (cache-backed).
 *
 * @param int $import_id Import job ID.
 *
 * @return array<string, mixed>|null
 */
function eai_db_get_import_config( int $import_id ): ?array {
	$import_id = absint( $import_id );
	if ( $import_id <= 0 ) {
		return null;
	}

	$cache_key = eai_db_import_config_cache_key( $import_id );
	$cached    = wp_cache_get( $cache_key, EAI_CACHE_GROUP );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$table = eai_db_imports_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, name, endpoint_url, auth_method, auth_token, auth_header_name, auth_username, auth_password, array_path, unique_id_path, recurrence, custom_interval_minutes, filter_rules, target_post_type, featured_image_source_path, title_template, mapping_template, lock_editing, post_status, comment_status, ping_status, custom_meta_mappings, created_at
			FROM %i
			WHERE id = %d",
			$table,
			$import_id
		),
		ARRAY_A
	);

	if ( ! is_array( $row ) ) {
		return null;
	}

	// Decrypt credentials transparently so consumers receive plaintext.
	$row = eai_decrypt_import_credentials( $row );

	wp_cache_set( $cache_key, $row, EAI_CACHE_GROUP, 60 );

	return $row;
}

/**
 * Fetches configured custom recurrence interval values (cache-backed).
 *
 * @return array<int, int>
 */
function eai_db_get_custom_recurrence_minutes(): array {
	$cache_key = 'import_configs:custom_intervals';
	$cached    = wp_cache_get( $cache_key, EAI_CACHE_GROUP );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$table = eai_db_imports_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT custom_interval_minutes
			FROM %i
			WHERE recurrence = 'custom'
				AND custom_interval_minutes > 0",
			$table
		)
	);

	if ( ! is_array( $rows ) ) {
		$rows = array();
	}

	$minutes = array();
	foreach ( $rows as $minutes_value ) {
		$interval = max( 1, absint( $minutes_value ) );
		if ( $interval > 0 ) {
			$minutes[] = $interval;
		}
	}

	$minutes = array_values( array_unique( $minutes ) );
	wp_cache_set( $cache_key, $minutes, EAI_CACHE_GROUP, 60 );

	return $minutes;
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
function eai_db_save_import_config( int $import_id, array $data, array $formats ) {
	global $wpdb;

	$table     = eai_db_imports_table();
	$import_id = absint( $import_id );

	if ( $import_id > 0 ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$updated = $wpdb->update( $table, $data, array( 'id' => $import_id ), $formats, array( '%d' ) );
		if ( false === $updated ) {
			$last_error = is_string( $wpdb->last_error ) ? trim( $wpdb->last_error ) : '';
			$message    = __( 'Failed to update import configuration.', 'enterprise-api-importer' );
			if ( '' !== $last_error ) {
				$message .= ' ' . sprintf(
					/* translators: %s is the SQL/database error message. */
					__( 'Database error: %s', 'enterprise-api-importer' ),
					$last_error
				);
			}

			return new WP_Error( 'eai_import_update_failed', $message );
		}

		eai_db_invalidate_imports_cache( $import_id );
		return $import_id;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$inserted = $wpdb->insert(
		$table,
		array_merge( $data, array( 'created_at' => current_time( 'mysql', true ) ) ),
		array_merge( $formats, array( '%s' ) )
	);

	if ( false === $inserted ) {
		$last_error = is_string( $wpdb->last_error ) ? trim( $wpdb->last_error ) : '';
		$message    = __( 'Failed to create import configuration.', 'enterprise-api-importer' );
		if ( '' !== $last_error ) {
			$message .= ' ' . sprintf(
				/* translators: %s is the SQL/database error message. */
				__( 'Database error: %s', 'enterprise-api-importer' ),
				$last_error
			);
		}

		return new WP_Error( 'eai_import_insert_failed', $message );
	}

	$new_import_id = (int) $wpdb->insert_id;
	eai_db_invalidate_imports_cache( $new_import_id );

	return $new_import_id;
}

/**
 * Deletes one import configuration and invalidates cache.
 *
 * @param int $import_id Import job ID.
 *
 * @return bool
 */
function eai_db_delete_import_config( int $import_id ): bool {
	global $wpdb;

	$import_id = absint( $import_id );
	if ( $import_id <= 0 ) {
		return false;
	}

	$table = eai_db_imports_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$deleted = $wpdb->delete( $table, array( 'id' => $import_id ), array( '%d' ) );

	if ( false === $deleted ) {
		return false;
	}

	eai_db_invalidate_imports_cache( $import_id );
	return true;
}

/**
 * Gets the next unprocessed staging rows for one import (real-time query).
 *
 * @param int $import_id Import job ID.
 *
 * @return array<int, array<string, mixed>>
 */
function eai_db_get_unprocessed_staging_rows( int $import_id, int $limit = 10 ): array {
	global $wpdb;

	$import_id = absint( $import_id );
	$limit     = absint( $limit );
	$table     = eai_db_temp_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, import_id, raw_json
			FROM %i
			WHERE is_processed = 0
				AND import_id = %d
			ORDER BY id ASC
			LIMIT %d",
			$table,
			$import_id,
			$limit
		),
		ARRAY_A
	);

	return is_array( $rows ) ? $rows : array();
}

/**
 * Counts unprocessed staging rows for one import (real-time query).
 *
 * @param int $import_id Import job ID.
 *
 * @return int
 */
function eai_db_count_unprocessed_staging_rows( int $import_id ): int {
	global $wpdb;

	$table = eai_db_temp_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(1)
			FROM %i
			WHERE is_processed = 0
				AND import_id = %d",
			$table,
			absint( $import_id )
		)
	);

	return (int) $count;
}

/**
 * Marks one staging row as processed (real-time queue mutation).
 *
 * @param int $row_id Temp row ID.
 *
 * @return bool
 */
function eai_db_mark_staging_row_processed( int $row_id ): bool {
	global $wpdb;

	$table = eai_db_temp_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->update(
		$table,
		array( 'is_processed' => 1 ),
		array( 'id' => absint( $row_id ) ),
		array( '%d' ),
		array( '%d' )
	);

	return false !== $updated;
}

/**
 * Inserts one staging payload row (real-time queue mutation).
 *
 * @param int    $import_id Import job ID.
 * @param string $raw_json  Serialized payload.
 *
 * @return int|WP_Error New row ID or WP_Error on failure.
 */
function eai_db_insert_staging_payload( int $import_id, string $raw_json ) {
	global $wpdb;

	$table = eai_db_temp_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert(
		$table,
		array(
			'import_id'    => absint( $import_id ),
			'raw_json'     => (string) $raw_json,
			'is_processed' => 0,
			'created_at'   => current_time( 'mysql', true ),
		),
		array( '%d', '%s', '%d', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'eai_temp_insert_failed', __( 'Failed to insert staging payload.', 'enterprise-api-importer' ) );
	}

	return (int) $wpdb->insert_id;
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
function eai_db_insert_import_log( int $import_id, string $import_run_id, string $status, int $rows_processed, int $rows_created, int $rows_updated, string $errors_json, string $created_at ): bool {
	global $wpdb;

	$table = eai_db_logs_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->insert(
		$table,
		array(
			'import_id'      => absint( $import_id ),
			'import_run_id'  => (string) $import_run_id,
			'status'         => (string) $status,
			'rows_processed' => (int) $rows_processed,
			'rows_created'   => (int) $rows_created,
			'rows_updated'   => (int) $rows_updated,
			'errors'         => (string) $errors_json,
			'created_at'     => (string) $created_at,
		),
		array( '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
	);

	return false !== $inserted;
}

/**
 * Gets latest log rows keyed by import_id (real-time, uncached).
 *
 * @return array<int, array<string, mixed>>
 */
function eai_db_get_latest_logs_indexed_by_import_id(): array {
	global $wpdb;

	$logs_table = eai_db_logs_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.import_id, l.status, l.rows_processed, l.rows_created, l.rows_updated, l.errors, l.created_at AS last_run_at
			FROM %i l
			INNER JOIN (
				SELECT import_id, MAX(id) AS max_id
				FROM %i
				GROUP BY import_id
			) latest
				ON l.import_id = latest.import_id
				AND l.id = latest.max_id",
			$logs_table,
			$logs_table
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$indexed = array();
	foreach ( $rows as $row ) {
		$import_id = isset( $row['import_id'] ) ? absint( $row['import_id'] ) : 0;
		if ( $import_id > 0 ) {
			$indexed[ $import_id ] = $row;
		}
	}

	return $indexed;
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
function eai_db_get_recent_import_log_trends( int $points_per_import = 12 ): array {
	global $wpdb;

	$logs_table         = eai_db_logs_table();
	$points_per_import  = max( 3, min( 30, absint( $points_per_import ) ) );
	$global_sample_size = max( 150, $points_per_import * 250 );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT import_id, rows_created, rows_updated
			FROM %i
			WHERE import_id > 0
				AND status NOT IN ('template_audit')
			ORDER BY id DESC
			LIMIT %d",
			$logs_table,
			$global_sample_size
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$trends = array();

	foreach ( $rows as $row ) {
		$import_id = isset( $row['import_id'] ) ? absint( $row['import_id'] ) : 0;
		if ( $import_id <= 0 ) {
			continue;
		}

		if ( ! isset( $trends[ $import_id ] ) ) {
			$trends[ $import_id ] = array();
		}

		if ( count( $trends[ $import_id ] ) >= $points_per_import ) {
			continue;
		}

		$trends[ $import_id ][] = array(
			'created' => isset( $row['rows_created'] ) ? (int) $row['rows_created'] : 0,
			'updated' => isset( $row['rows_updated'] ) ? (int) $row['rows_updated'] : 0,
		);
	}

	foreach ( $trends as $import_id => $points ) {
		$trends[ $import_id ] = array_reverse( $points );
	}

	return $trends;
}

/**
 * Gets pending queue counts keyed by import_id (real-time, uncached).
 *
 * @return array<int, int>
 */
function eai_db_get_pending_counts_by_import_id(): array {
	global $wpdb;

	$temp_table = eai_db_temp_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT import_id, COUNT(id) AS pending_count
			FROM %i
			WHERE is_processed = 0
			GROUP BY import_id",
			$temp_table
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$counts = array();
	foreach ( $rows as $row ) {
		$import_id = isset( $row['import_id'] ) ? absint( $row['import_id'] ) : 0;
		if ( $import_id > 0 ) {
			$counts[ $import_id ] = isset( $row['pending_count'] ) ? (int) $row['pending_count'] : 0;
		}
	}

	return $counts;
}
