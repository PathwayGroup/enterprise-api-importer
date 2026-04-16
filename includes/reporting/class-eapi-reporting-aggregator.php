<?php
/**
 * Singleton aggregator that collects data from all registered reporters.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates results from all registered reporter modules.
 */
class EAPI_Reporting_Aggregator {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Registered reporter modules keyed by reporter ID.
	 *
	 * @var EAPI_Reporter_Base[]
	 */
	private array $reporters = array();

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a reporter module.
	 *
	 * @param EAPI_Reporter_Base $reporter Reporter instance.
	 *
	 * @return void
	 */
	public function register_reporter( EAPI_Reporter_Base $reporter ): void {
		$this->reporters[ $reporter->get_id() ] = $reporter;
	}

	/**
	 * Aggregate data from all reporters, grouped by category.
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	public function get_dashboard_data(): array {
		$grouped = array();

		foreach ( $this->reporters as $reporter ) {
			$category = $reporter->get_category();
			if ( ! isset( $grouped[ $category ] ) ) {
				$grouped[ $category ] = array();
			}
			$grouped[ $category ][ $reporter->get_id() ] = array(
				'label'   => $reporter->get_label(),
				'metrics' => $reporter->get_cached_results(),
			);
		}

		return $grouped;
	}
}
