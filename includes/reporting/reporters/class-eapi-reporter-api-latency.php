<?php
/**
 * Reporter: API Latency — average processing time from recent log entries.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports recent API processing latency.
 */
class EAPI_Reporter_API_Latency extends EAPI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'api_latency';

	/**
	 * Reporter category.
	 *
	 * @var string
	 */
	protected string $category = 'Performance';

	/**
	 * Reporter label.
	 *
	 * @var string
	 */
	protected string $label = 'API Latency';

	/**
	 * Calculate API latency metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$logs_table = eai_db_logs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT errors FROM %i WHERE status NOT IN (%s, %s) ORDER BY created_at DESC LIMIT 100',
				$logs_table,
				'template_audit',
				'no_data'
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No import log entries to calculate latency from.',
			);
		}

		$durations = array();

		foreach ( $rows as $json_string ) {
			$details = json_decode( $json_string, true );
			if ( ! is_array( $details ) ) {
				continue;
			}
			if ( empty( $details['start_time'] ) || empty( $details['end_time'] ) ) {
				continue;
			}
			$start = strtotime( $details['start_time'] );
			$end   = strtotime( $details['end_time'] );
			if ( false === $start || false === $end || $end < $start ) {
				continue;
			}
			$durations[] = $end - $start;
		}

		if ( empty( $durations ) ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No timing data available in recent log entries.',
			);
		}

		$avg_seconds = array_sum( $durations ) / count( $durations );

		$status = $this->get_status_color(
			$avg_seconds,
			array(
				'green'  => 30,
				'yellow' => 120,
			)
		);

		return array(
			'status' => $status,
			'value'  => round( $avg_seconds, 1 ) . 's avg',
			'detail' => sprintf(
				'Average processing time: %.1fs across %d recent run(s).',
				$avg_seconds,
				count( $durations )
			),
		);
	}
}
