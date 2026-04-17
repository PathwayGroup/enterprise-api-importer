<?php
/**
 * Reporter: Daily Success Rate — success vs error ratio in logs (last 24h).
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the recent import success-to-error ratio.
 */
class TPORAPDI_Reporter_Daily_Success_Rate extends TPORAPDI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'daily_success_rate';

	/**
	 * Reporter category.
	 *
	 * @var string
	 */
	protected string $category = 'Health';

	/**
	 * Reporter label.
	 *
	 * @var string
	 */
	protected string $label = 'Daily Success Rate';

	/**
	 * Calculate daily success rate metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$logs_table = tporapdi_db_logs_table();
		$since      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt FROM %i WHERE created_at >= %s GROUP BY status',
				$logs_table,
				$since
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No log entries in the last 24 hours.',
			);
		}

		$success_statuses = array( 'completed', 'success' );
		$error_statuses   = array( 'failed', 'error' );
		$success_count    = 0;
		$error_count      = 0;
		$total            = 0;

		foreach ( $rows as $row ) {
			$cnt    = (int) $row->cnt;
			$total += $cnt;
			if ( in_array( $row->status, $success_statuses, true ) ) {
				$success_count += $cnt;
			} elseif ( in_array( $row->status, $error_statuses, true ) ) {
				$error_count += $cnt;
			}
		}

		$percentage = $this->format_percentage( $success_count, $total );
		$rate       = $total > 0 ? ( $success_count / $total ) * 100 : 100;

		$status = $this->get_status_color(
			100 - $rate,
			array(
				'green'  => 5,
				'yellow' => 20,
			)
		);

		return array(
			'status' => $status,
			'value'  => $percentage,
			'detail' => sprintf(
				'%d success, %d error out of %d total runs in the last 24h.',
				$success_count,
				$error_count,
				$total
			),
		);
	}
}
