<?php
/**
 * Reporter: Protocol Enforcement — HTTPS vs HTTP ratio in configured endpoints.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports configured endpoint protocol compliance.
 */
class TPORAPDI_Reporter_Protocol_Enforcement extends TPORAPDI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'protocol_enforcement';

	/**
	 * Reporter category.
	 *
	 * @var string
	 */
	protected string $category = 'Security';

	/**
	 * Reporter label.
	 *
	 * @var string
	 */
	protected string $label = 'Protocol Enforcement';

	/**
	 * Calculate protocol enforcement metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$imports_table = tporapdi_db_imports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$urls = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT endpoint_url FROM %i',
				$imports_table
			)
		);

		if ( empty( $urls ) ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No import endpoints configured.',
			);
		}

		$https_count = 0;
		$http_count  = 0;

		foreach ( $urls as $url ) {
			if ( str_starts_with( strtolower( $url ), 'https://' ) ) {
				++$https_count;
			} else {
				++$http_count;
			}
		}

		$total  = count( $urls );
		$status = 0 === $http_count ? 'green' : 'red';
		$ratio  = $this->format_percentage( $https_count, $total );

		return array(
			'status' => $status,
			'value'  => $ratio . ' HTTPS',
			'detail' => sprintf(
				'%d HTTPS, %d HTTP out of %d endpoint(s).',
				$https_count,
				$http_count,
				$total
			),
		);
	}
}
