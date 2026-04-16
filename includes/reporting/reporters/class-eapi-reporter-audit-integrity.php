<?php
/**
 * Reporter: Audit Integrity — template change audit log entries (last 7 days).
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports recent template audit-log activity.
 */
class EAPI_Reporter_Audit_Integrity extends EAPI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'audit_integrity';

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
	protected string $label = 'Audit Integrity';

	/**
	 * Calculate audit integrity metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$logs_table = eai_db_logs_table();
		$since      = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status = %s AND created_at >= %s',
				$logs_table,
				'template_audit',
				$since
			)
		);

		return array(
			'status' => $count > 0 ? 'yellow' : 'green',
			'value'  => number_format_i18n( $count ) . ' change(s)',
			'detail' => sprintf(
				'%d template configuration change(s) logged in the last 7 days.',
				$count
			),
		);
	}
}
