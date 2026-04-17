<?php
/**
 * Reporter: SSRF Hardening — checks endpoint allowlist configuration.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the current SSRF allowlist posture.
 */
class TPORAPDI_Reporter_SSRF_Hardening extends TPORAPDI_Reporter_Base {

	/**
	 * Reporter identifier.
	 *
	 * @var string
	 */
	protected string $id = 'ssrf_hardening';

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
	protected string $label = 'SSRF Hardening';

	/**
	 * Calculate SSRF hardening metrics.
	 *
	 * @return array<string, mixed>
	 */
	protected function calculate_metrics(): array {
		$settings      = get_option( 'tporapdi_settings', array() );
		$allowed_hosts = isset( $settings['allowed_endpoint_hosts'] ) ? trim( (string) $settings['allowed_endpoint_hosts'] ) : '';
		$allowed_cidrs = isset( $settings['allowed_endpoint_cidrs'] ) ? trim( (string) $settings['allowed_endpoint_cidrs'] ) : '';
		$is_restricted = '' !== $allowed_hosts || '' !== $allowed_cidrs;

		if ( $is_restricted ) {
			return array(
				'status' => 'green',
				'value'  => 'Restricted',
				'detail' => 'Endpoint allowlist is configured.',
			);
		}

		return array(
			'status' => 'yellow',
			'value'  => 'Open',
			'detail' => 'No endpoint host or CIDR restrictions are configured yet. Any remote URL can currently be fetched until an allowlist is added.',
		);
	}
}
