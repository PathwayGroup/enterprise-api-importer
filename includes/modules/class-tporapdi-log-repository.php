<?php
/**
 * Log Repository – canonical persistence layer for import run logs.
 *
 * Hides all SQL for the import logs table from callers.
 * Callers ask for "Run histories" and "Trends," not raw SQL rows.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Domain repository for the custom_import_logs table.
 *
 * All reads are real-time (no cache) since log data is append-only and
 * must always reflect the live state for dashboards and audit views.
 */
class Tporapdi_Log_Repository {

	// -------------------------------------------------------------------------
	// Public write operations
	// -------------------------------------------------------------------------

	/**
	 * Appends one import run log record.
	 *
	 * @param int    $import_id      Import job ID (0 for system-level records).
	 * @param string $run_id         Unique run identifier (UUID or similar).
	 * @param string $status         Final status string (e.g. 'success', 'error').
	 * @param int    $rows_processed Count of total items processed.
	 * @param int    $rows_created   Count of posts created.
	 * @param int    $rows_updated   Count of posts updated.
	 * @param string $errors_json    JSON-encoded error detail string.
	 * @param string $created_at     Timestamp in UTC MySQL format.
	 *
	 * @return bool True on success.
	 */
	public static function insert(
		int $import_id,
		string $run_id,
		string $status,
		int $rows_processed,
		int $rows_created,
		int $rows_updated,
		string $errors_json,
		string $created_at
	): bool {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$table,
			array(
				'import_id'      => absint( $import_id ),
				'import_run_id'  => (string) $run_id,
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

	// -------------------------------------------------------------------------
	// Public read operations
	// -------------------------------------------------------------------------

	/**
	 * Returns the most recent log row for every import, keyed by import_id.
	 *
	 * Used to render last-run status in the admin list table.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function latest_indexed_by_import(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.import_id, l.status, l.rows_processed, l.rows_created, l.rows_updated, l.errors, l.created_at AS last_run_at
				FROM %i l
				INNER JOIN (
					SELECT import_id, MAX(id) AS max_id
					FROM %i
					GROUP BY import_id
				) latest
					ON l.import_id = latest.import_id
					AND l.id = latest.max_id',
				$table,
				$table
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$indexed = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['import_id'] ) ? absint( $row['import_id'] ) : 0;
			if ( $id > 0 ) {
				$indexed[ $id ] = $row;
			}
		}

		return $indexed;
	}

	/**
	 * Returns sparkline trend data: recent created/updated counts per import.
	 *
	 * @param int $points_per_import Maximum recent data points to include per import (3–30).
	 *
	 * @return array<int, array<int, array<string, int>>>  import_id → ordered point array.
	 */
	public static function trends( int $points_per_import = 12 ): array {
		global $wpdb;

		$table              = self::table();
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
				$table,
				$global_sample_size
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['import_id'] ) ? absint( $row['import_id'] ) : 0;
			if ( $id <= 0 ) {
				continue;
			}

			if ( ! isset( $result[ $id ] ) ) {
				$result[ $id ] = array();
			}

			if ( count( $result[ $id ] ) >= $points_per_import ) {
				continue;
			}

			$result[ $id ][] = array(
				'created' => isset( $row['rows_created'] ) ? (int) $row['rows_created'] : 0,
				'updated' => isset( $row['rows_updated'] ) ? (int) $row['rows_updated'] : 0,
			);
		}

		foreach ( $result as $id => $points ) {
			$result[ $id ] = array_reverse( $points );
		}

		return $result;
	}

	/**
	 * Deletes all log rows belonging to an import job.
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
	 * Returns the fully-qualified logs table name.
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'custom_import_logs';
	}
}
