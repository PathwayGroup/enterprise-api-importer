<?php
/**
 * Reporter: Throughput — records processed in the last 60 minutes.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports recent import throughput.
 */
class TPORAPDI_Reporter_Throughput extends TPORAPDI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'throughput';

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
	protected string $label = 'Throughput';

	/**
	 * Calculate throughput metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$logs_table = tporapdi_db_logs_table();
		$since      = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( rows_processed ), 0 ) FROM %i WHERE created_at >= %s AND status NOT IN (%s)',
				$logs_table,
				$since,
				'template_audit'
			)
		);

		$total = (int) $total;

		if ( 0 === $total ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No records processed in the last 60 minutes.',
			);
		}

		return array(
			'status' => 'green',
			'value'  => number_format_i18n( $total ) . ' rows',
			'detail' => sprintf(
				'%s record(s) processed across all jobs in the last 60 minutes.',
				number_format_i18n( $total )
			),
		);
	}
}
