<?php
/**
 * Reporter: Cron Heartbeat — checks recurring import schedule health.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports recurring import scheduling health.
 */
class TPORAPDI_Reporter_Cron_Heartbeat extends TPORAPDI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'cron_heartbeat';

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
	protected string $label = 'Cron Heartbeat';

	/**
	 * Calculate cron heartbeat metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		global $wpdb;

		$imports_table = tporapdi_db_imports_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, name, recurrence, custom_interval_minutes FROM %i WHERE recurrence != %s',
				$imports_table,
				'off'
			)
		);

		if ( empty( $rows ) ) {
			return array(
				'status' => 'green',
				'value'  => 'No Data',
				'detail' => 'No recurring imports configured.',
			);
		}

		$issues = array();

		foreach ( $rows as $row ) {
			$next = wp_next_scheduled( 'tporapdi_recurring_import_trigger', array( (int) $row->id, 'recurring' ) );
			if ( false === $next ) {
				$next = wp_next_scheduled( 'tporapdi_recurring_import_trigger', array( (int) $row->id ) );
			}

			if ( false === $next ) {
				$issues[] = sprintf( '%s (#%d): not scheduled', $row->name, $row->id );
				continue;
			}

			$expected_interval = $this->get_expected_interval( $row->recurrence, (int) $row->custom_interval_minutes );
			$delta             = $next - time();

			if ( $expected_interval > 0 && $delta > $expected_interval ) {
				$issues[] = sprintf(
					'%s (#%d): overdue by %s',
					$row->name,
					$row->id,
					human_time_diff( time(), $next )
				);
			}
		}

		if ( ! empty( $issues ) ) {
			return array(
				'status' => 'yellow',
				'value'  => count( $issues ) . ' issue(s)',
				'detail' => implode( '; ', $issues ),
			);
		}

		return array(
			'status' => 'green',
			'value'  => 'On Schedule',
			'detail' => count( $rows ) . ' recurring import(s) on schedule.',
		);
	}

	/**
	 * Resolve the expected interval in seconds for a recurrence slug.
	 *
	 * @param string $recurrence     Recurrence slug.
	 * @param int    $custom_minutes Custom interval length in minutes.
	 *
	 * @return int
	 */
	private function get_expected_interval( string $recurrence, int $custom_minutes ): int {
		$schedules = wp_get_schedules();

		if ( 'custom' === $recurrence && $custom_minutes > 0 ) {
			$recurrence = 'tporapdi_every_' . $custom_minutes . '_minutes';
		}

		if ( isset( $schedules[ $recurrence ]['interval'] ) ) {
			return (int) $schedules[ $recurrence ]['interval'];
		}

		return 0;
	}
}
