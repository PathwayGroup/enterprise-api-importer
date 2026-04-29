<?php
/**
 * Queue Repository – canonical persistence layer for the staging queue.
 *
 * Encapsulates all SQL for the temp staging table.
 * Callers enqueue payloads and dequeue batches; no SQL leaks out.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain repository for the custom_import_temp (staging queue) table.
 *
 * All writes are real-time (no cache); reads are also real-time since queue
 * depth is transient and must always reflect the live state.
 */
class Tporapdi_Queue_Repository {

	// -------------------------------------------------------------------------
	// Public read operations
	// -------------------------------------------------------------------------

	/**
	 * Returns the next batch of unprocessed staging rows for a given import.
	 *
	 * @param int $import_id Import job ID.
	 * @param int $limit     Maximum rows to return (default 10).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_unprocessed( int $import_id, int $limit = 10 ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, import_id, raw_json
				FROM %i
				WHERE is_processed = 0
					AND import_id = %d
				ORDER BY id ASC
				LIMIT %d',
				$table,
				absint( $import_id ),
				absint( $limit )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Returns the count of unprocessed rows for a given import.
	 *
	 * @param int $import_id Import job ID.
	 *
	 * @return int
	 */
	public static function count_pending( int $import_id ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1)
				FROM %i
				WHERE is_processed = 0
					AND import_id = %d',
				$table,
				absint( $import_id )
			)
		);

		return (int) $count;
	}

	/**
	 * Returns pending queue depths keyed by import_id.
	 *
	 * Useful for bulk admin list views.
	 *
	 * @return array<int, int>  import_id → pending count.
	 */
	public static function pending_counts_by_import(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT import_id, COUNT(id) AS pending_count
				FROM %i
				WHERE is_processed = 0
				GROUP BY import_id',
				$table
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['import_id'] ) ? absint( $row['import_id'] ) : 0;
			if ( $id > 0 ) {
				$counts[ $id ] = isset( $row['pending_count'] ) ? (int) $row['pending_count'] : 0;
			}
		}

		return $counts;
	}

	// -------------------------------------------------------------------------
	// Public write operations
	// -------------------------------------------------------------------------

	/**
	 * Inserts one payload into the staging queue.
	 *
	 * @param int    $import_id Import job ID.
	 * @param string $raw_json  JSON-serialised payload.
	 *
	 * @return int|\WP_Error New row ID, or WP_Error on failure.
	 */
	public static function enqueue( int $import_id, string $raw_json ) {
		global $wpdb;

		$table = self::table();

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
			return new WP_Error(
				'tporapdi_temp_insert_failed',
				__( 'Failed to insert staging payload.', 'tporret-api-data-importer' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Marks one staging row as processed.
	 *
	 * @param int $row_id Staging row ID.
	 *
	 * @return bool True on success.
	 */
	public static function mark_processed( int $row_id ): bool {
		global $wpdb;

		$table = self::table();

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
	 * Deletes all staging rows belonging to an import job.
	 *
	 * Typically called during import deletion or full reset.
	 *
	 * @param int $import_id Import job ID.
	 *
	 * @return int|false Number of deleted rows, or false on DB error.
	 */
	public static function delete_for_import( int $import_id ) {
		$import_id = absint( $import_id );
		if ( $import_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->delete( $table, array( 'import_id' => $import_id ), array( '%d' ) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the fully-qualified staging table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'custom_import_temp';
	}
}
