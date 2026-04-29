<?php
/**
 * Cleanup Service – garbage collection for the import pipeline tables.
 *
 * Single responsibility: purge stale staging rows and expired log records
 * in safe, chunked batches to avoid lock contention on large tables.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsulates all garbage-collection logic for the import pipeline.
 *
 * Deletion is performed in chunks (default 1 000 rows per pass) so that
 * lock duration stays short even on large tables. Callers receive a plain
 * summary array; no SQL leaks out.
 */
class Tporapdi_Cleanup_Service {

	/**
	 * Rows deleted per DELETE statement.
	 */
	private const CHUNK_SIZE = 1000;

	/**
	 * Staging rows older than this many days are eligible for purge (safety net).
	 */
	private const TEMP_RETENTION_DAYS = 7;

	/**
	 * Log rows older than this many days are eligible for purge.
	 */
	private const LOG_RETENTION_DAYS = 30;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Runs garbage collection for staging and log tables.
	 *
	 * Staging table: deletes rows that are already processed OR older than
	 * {@see TEMP_RETENTION_DAYS} days (safety net for stuck rows).
	 *
	 * Logs table: deletes rows older than {@see LOG_RETENTION_DAYS} days.
	 *
	 * @return array{temp_deleted: int, logs_deleted: int, errors: string[]}
	 */
	public static function run(): array {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return array(
				'temp_deleted' => 0,
				'logs_deleted' => 0,
				'errors'       => array( 'Database connection is unavailable.' ),
			);
		}

		$errors = array();

		$temp_deleted = self::purge_staging( $wpdb, $errors );
		$logs_deleted = self::purge_logs( $wpdb, $errors );

		return array(
			'temp_deleted' => $temp_deleted,
			'logs_deleted' => $logs_deleted,
			'errors'       => $errors,
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Deletes processed and stale staging rows in chunks.
	 *
	 * @param wpdb     $wpdb   DB handle.
	 * @param string[] $errors Error accumulator (passed by reference).
	 *
	 * @return int Total rows deleted.
	 */
	private static function purge_staging( wpdb $wpdb, array &$errors ): int {
		$table   = $wpdb->prefix . 'custom_import_temp';
		$deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE is_processed = 1 OR created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY ) LIMIT %d',
					$table,
					self::TEMP_RETENTION_DAYS,
					self::CHUNK_SIZE
				)
			);

			$batch = max( 0, (int) $wpdb->rows_affected );

			if ( false === $result ) {
				$errors[] = 'Failed to purge records from custom_import_temp.';
				break;
			}

			$deleted += $batch;
		} while ( $batch > 0 );

		return $deleted;
	}

	/**
	 * Deletes expired log rows in chunks.
	 *
	 * @param wpdb     $wpdb   DB handle.
	 * @param string[] $errors Error accumulator (passed by reference).
	 *
	 * @return int Total rows deleted.
	 */
	private static function purge_logs( wpdb $wpdb, array &$errors ): int {
		$table   = $wpdb->prefix . 'custom_import_logs';
		$deleted = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY ) LIMIT %d',
					$table,
					self::LOG_RETENTION_DAYS,
					self::CHUNK_SIZE
				)
			);

			$batch = max( 0, (int) $wpdb->rows_affected );

			if ( false === $result ) {
				$errors[] = 'Failed to purge records from custom_import_logs.';
				break;
			}

			$deleted += $batch;
		} while ( $batch > 0 );

		return $deleted;
	}
}
