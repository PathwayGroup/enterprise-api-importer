<?php
/**
 * Job Repository – canonical persistence layer for import configurations.
 *
 * Hides all SQL, cache management, and credential decryption from callers.
 * Callers ask for "Jobs," not SQL rows.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain repository for the tporapdi_imports table.
 *
 * All public methods return fully decoded, plaintext-credential job arrays.
 * Cache is managed transparently; callers never touch wp_cache directly.
 */
class Tporapdi_Job_Repository {

	// -------------------------------------------------------------------------
	// Public read operations
	// -------------------------------------------------------------------------

	/**
	 * Returns all import job configurations, newest first (cache-backed).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function find_all(): array {
		$cache_key = 'import_configs:all';
		$cached    = wp_cache_get( $cache_key, TPORAPDI_CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, name, endpoint_url, auth_method, auth_token, auth_header_name, auth_username, auth_password, array_path, unique_id_path, recurrence, custom_interval_minutes, filter_rules, target_post_type, featured_image_source_path, title_template, excerpt_template, post_name_template, mapping_template, lock_editing, post_status, comment_status, ping_status, custom_meta_mappings, created_at
				FROM %i
				ORDER BY id DESC',
				$table
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$rows = array_map( 'tporapdi_decrypt_import_credentials', $rows );
		wp_cache_set( $cache_key, $rows, TPORAPDI_CACHE_GROUP, 60 );

		return $rows;
	}

	/**
	 * Returns one import job configuration by ID (cache-backed).
	 *
	 * @param int $id Import job ID.
	 *
	 * @return array<string, mixed>|null Null when not found.
	 */
	public static function find( int $id ): ?array {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return null;
		}

		$cache_key = self::cache_key( $id );
		$cached    = wp_cache_get( $cache_key, TPORAPDI_CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, name, endpoint_url, auth_method, auth_token, auth_header_name, auth_username, auth_password, array_path, unique_id_path, recurrence, custom_interval_minutes, filter_rules, target_post_type, featured_image_source_path, title_template, excerpt_template, post_name_template, mapping_template, lock_editing, post_status, comment_status, ping_status, custom_meta_mappings, created_at
				FROM %i
				WHERE id = %d',
				$table,
				$id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row = tporapdi_decrypt_import_credentials( $row );
		wp_cache_set( $cache_key, $row, TPORAPDI_CACHE_GROUP, 60 );

		return $row;
	}

	/**
	 * Returns the distinct set of custom recurrence interval values in minutes (cache-backed).
	 *
	 * @return array<int, int>
	 */
	public static function find_custom_recurrence_minutes(): array {
		$cache_key = 'import_configs:custom_intervals';
		$cached    = wp_cache_get( $cache_key, TPORAPDI_CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::table();

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

		$minutes = array();
		foreach ( is_array( $rows ) ? $rows : array() as $value ) {
			$interval = max( 1, absint( $value ) );
			if ( $interval > 0 ) {
				$minutes[] = $interval;
			}
		}

		$minutes = array_values( array_unique( $minutes ) );
		wp_cache_set( $cache_key, $minutes, TPORAPDI_CACHE_GROUP, 60 );

		return $minutes;
	}

	// -------------------------------------------------------------------------
	// Public write operations
	// -------------------------------------------------------------------------

	/**
	 * Persists an import configuration.
	 *
	 * Pass $id = 0 to create a new record; pass an existing ID to update.
	 * Cache is invalidated automatically.
	 *
	 * @param int                $id      Existing job ID, or 0 to create.
	 * @param array<string,mixed> $data    Column → value map.
	 * @param array<int,string>  $formats wpdb format strings matching $data.
	 *
	 * @return int|\WP_Error Persisted job ID, or WP_Error on failure.
	 */
	public static function save( int $id, array $data, array $formats ) {
		global $wpdb;

		$table = self::table();
		$id    = absint( $id );

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$updated = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

			if ( false === $updated ) {
				$last_error = is_string( $wpdb->last_error ) ? trim( $wpdb->last_error ) : '';
				$message    = __( 'Failed to update import configuration.', 'tporret-api-data-importer' );
				if ( '' !== $last_error ) {
					$message .= ' ' . sprintf(
						/* translators: %s is the SQL/database error message. */
						__( 'Database error: %s', 'tporret-api-data-importer' ),
						$last_error
					);
				}

				return new WP_Error( 'tporapdi_import_update_failed', $message );
			}

			self::bust_cache( $id );
			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			array_merge( $data, array( 'created_at' => current_time( 'mysql', true ) ) ),
			array_merge( $formats, array( '%s' ) )
		);

		if ( false === $inserted ) {
			$last_error = is_string( $wpdb->last_error ) ? trim( $wpdb->last_error ) : '';
			$message    = __( 'Failed to create import configuration.', 'tporret-api-data-importer' );
			if ( '' !== $last_error ) {
				$message .= ' ' . sprintf(
					/* translators: %s is the SQL/database error message. */
					__( 'Database error: %s', 'tporret-api-data-importer' ),
					$last_error
				);
			}

			return new WP_Error( 'tporapdi_import_insert_failed', $message );
		}

		$new_id = (int) $wpdb->insert_id;
		self::bust_cache( $new_id );

		return $new_id;
	}

	/**
	 * Deletes one import configuration and invalidates its cache entry.
	 *
	 * @param int $id Import job ID.
	 *
	 * @return bool True on success.
	 */
	public static function delete( int $id ): bool {
		$id = absint( $id );
		if ( $id <= 0 ) {
			return false;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		if ( false === $deleted ) {
			return false;
		}

		self::bust_cache( $id );
		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the fully-qualified imports table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tporapdi_imports';
	}

	/**
	 * Returns the object-cache key for a single job config.
	 *
	 * @param int $id Job ID.
	 *
	 * @return string
	 */
	private static function cache_key( int $id ): string {
		return 'import_config:' . absint( $id );
	}

	/**
	 * Invalidates all job-config cache entries affected by a write.
	 *
	 * @param int $id The job ID that changed.
	 *
	 * @return void
	 */
	private static function bust_cache( int $id ): void {
		wp_cache_delete( 'import_configs:all', TPORAPDI_CACHE_GROUP );
		wp_cache_delete( 'import_configs:custom_intervals', TPORAPDI_CACHE_GROUP );
		wp_cache_delete( self::cache_key( $id ), TPORAPDI_CACHE_GROUP );
	}
}
