<?php
/**
 * Reporter: Active Connections — count of unique API endpoints.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the number of unique configured API endpoints.
 */
class TPORAPDI_Reporter_Active_Connections extends TPORAPDI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'active_connections';

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
	protected string $label = 'Active Connections';

	/**
	 * Calculate active connection metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$imports_table = tporapdi_db_imports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT( DISTINCT endpoint_url ) FROM %i',
				$imports_table
			)
		);

		return array(
			'status' => 'green',
			'value'  => number_format_i18n( $count ),
			'detail' => $count . ' unique API endpoint(s) configured.',
		);
	}
}
