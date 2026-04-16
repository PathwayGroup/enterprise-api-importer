<?php
/**
 * Abstract base class for all reporting metric modules.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base reporter implementation shared by dashboard metric modules.
 */
abstract class EAPI_Reporter_Base {

	/**
	 * Unique reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = '';

	/**
	 * Category grouping for dashboard sections.
	 *
	 * @var string
	 */
	protected string $category = '';

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	protected string $label = '';

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	protected int $cache_ttl = 600;

	/**
	 * Return the reporter ID.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Return the category.
	 */
	public function get_category(): string {
		return $this->category;
	}

	/**
	 * Return the label.
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Get cached results or calculate fresh ones.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cached_results(): array {
		$transient_key = 'eapi_report_' . $this->id;
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$results = $this->calculate_metrics();
		set_transient( $transient_key, $results, $this->cache_ttl );

		return $results;
	}

	/**
	 * Calculate the actual metrics. Implemented by each module.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function calculate_metrics(): array;

	/**
	 * Format a value as a percentage string.
	 *
	 * @param int|float $current Current count.
	 * @param int|float $total   Total count.
	 * @return string
	 */
	protected function format_percentage( $current, $total ): string {
		if ( (float) $total <= 0 ) {
			return 'No Data';
		}
		return round( ( (float) $current / (float) $total ) * 100, 1 ) . '%';
	}

	/**
	 * Format a datetime string as a human-readable "time ago".
	 *
	 * @param string $datetime_string MySQL datetime (UTC).
	 * @return string
	 */
	protected function format_time_ago( string $datetime_string ): string {
		if ( empty( $datetime_string ) ) {
			return 'No Data';
		}
		$timestamp = strtotime( $datetime_string );
		if ( false === $timestamp || -1 === $timestamp ) {
			return 'No Data';
		}
		return human_time_diff( $timestamp, time() ) . ' ago';
	}

	/**
	 * Determine a status colour based on thresholds.
	 *
	 * @param int|float $value      The metric value.
	 * @param array     $thresholds Associative array with 'green' and 'yellow' keys.
	 *                              Values at or below 'green' → green, at or below 'yellow' → yellow, else red.
	 * @return string 'green'|'yellow'|'red'
	 */
	protected function get_status_color( $value, array $thresholds ): string {
		if ( $value <= $thresholds['green'] ) {
			return 'green';
		}
		if ( $value <= $thresholds['yellow'] ) {
			return 'yellow';
		}
		return 'red';
	}
}
