<?php
/**
 * Centralized security guard module.
 *
 * Single authority for SSRF/CIDR endpoint validation and Twig template security.
 * Both the "Save Job" path (admin/REST) and the "Run Job" path (cron/import)
 * must call this module — never re-implement the checks inline.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deep security module — simple interface, complex implementation.
 *
 * Public interface:
 *   check_endpoint( $url )             → true|WP_Error
 *   check_template( $template, $type ) → true|WP_Error
 */
class TPORAPDI_Security_Guard {

	/**
	 * Validates a remote endpoint URL against the full plugin security policy:
	 * HTTPS requirement, hostname allowlist, CIDR allowlist, and SSRF block-list.
	 *
	 * @param string $endpoint Raw endpoint URL.
	 *
	 * @return true|WP_Error
	 */
	public function check_endpoint( $endpoint ) {
		$endpoint = is_string( $endpoint ) ? trim( $endpoint ) : '';

		if ( '' === $endpoint || ! wp_http_validate_url( $endpoint ) ) {
			return new WP_Error( 'tporapdi_invalid_endpoint_url', __( 'A valid endpoint URL is required.', 'tporret-api-data-importer' ) );
		}

		$settings = wp_parse_args( get_option( 'tporapdi_settings', array() ), tporapdi_get_default_settings() );

		$allow_internal = ! empty( $settings['allow_internal_endpoints'] ) && '0' !== (string) $settings['allow_internal_endpoints'];
		$allow_internal = (bool) apply_filters( 'tporapdi_allow_internal_endpoints', $allow_internal, $endpoint );

		$require_https = (bool) apply_filters( 'tporapdi_require_https_endpoints', true, $endpoint );
		$scheme        = wp_parse_url( $endpoint, PHP_URL_SCHEME );
		$host          = wp_parse_url( $endpoint, PHP_URL_HOST );
		$scheme        = is_string( $scheme ) ? strtolower( $scheme ) : '';
		$host          = is_string( $host ) ? strtolower( trim( $host ) ) : '';

		if ( $require_https && 'https' !== $scheme ) {
			return new WP_Error(
				'tporapdi_endpoint_requires_https',
				__( 'Only HTTPS endpoint URLs are allowed.', 'tporret-api-data-importer' )
			);
		}

		$allowed_hosts = $this->normalize_allowlist(
			apply_filters(
				'tporapdi_allowed_endpoint_hosts',
				isset( $settings['allowed_endpoint_hosts'] ) ? $settings['allowed_endpoint_hosts'] : array(),
				$endpoint
			)
		);

		if ( ! empty( $allowed_hosts ) ) {
			$host_match = false;
			foreach ( $allowed_hosts as $allowed_host ) {
				if ( $this->host_matches_rule( $host, $allowed_host ) ) {
					$host_match = true;
					break;
				}
			}

			if ( ! $host_match ) {
				return new WP_Error(
					'tporapdi_endpoint_not_in_allowed_hosts',
					__( 'Endpoint host is not in the configured host allowlist.', 'tporret-api-data-importer' )
				);
			}
		}

		$allowed_cidrs = $this->normalize_allowlist(
			apply_filters(
				'tporapdi_allowed_endpoint_cidrs',
				isset( $settings['allowed_endpoint_cidrs'] ) ? $settings['allowed_endpoint_cidrs'] : array(),
				$endpoint
			)
		);

		if ( ! empty( $allowed_cidrs ) ) {
			$resolved_ips = $this->resolve_ips( $host );
			$cidr_match   = false;

			foreach ( $resolved_ips as $ip ) {
				foreach ( $allowed_cidrs as $cidr ) {
					if ( $this->ip_in_cidr( $ip, $cidr ) ) {
						$cidr_match = true;
						break 2;
					}
				}
			}

			if ( ! $cidr_match ) {
				return new WP_Error(
					'tporapdi_endpoint_not_in_allowed_cidrs',
					__( 'Endpoint IP is not in the configured CIDR allowlist.', 'tporret-api-data-importer' )
				);
			}
		}

		if ( ! $allow_internal && $this->is_disallowed_host( $host ) ) {
			return new WP_Error(
				'tporapdi_endpoint_disallowed_host',
				__( 'This endpoint host is blocked by security policy. Use a trusted public host or explicitly allow internal endpoints.', 'tporret-api-data-importer' )
			);
		}

		return true;
	}

	/**
	 * Validates a Twig template string for size, complexity, disallowed tags,
	 * nesting depth, and parse syntax.
	 *
	 * @param string $template Twig template source.
	 * @param string $type     Template type: 'title' or 'mapping'.
	 *
	 * @return true|WP_Error
	 */
	public function check_template( $template, $type = 'mapping' ) {
		$template = (string) $template;
		$type     = in_array( $type, array( 'title', 'mapping' ), true ) ? $type : 'mapping';

		$max_bytes_default = 'title' === $type ? 2048 : 50000;
		$max_bytes         = (int) apply_filters( 'tporapdi_template_max_bytes', $max_bytes_default, $type );
		$template_size     = strlen( $template );

		if ( $max_bytes > 0 && $template_size > $max_bytes ) {
			return new WP_Error(
				'tporapdi_template_too_large',
				sprintf(
					/* translators: %1$d is current bytes, %2$d is max bytes. */
					__( 'Template is too large (%1$d bytes). Maximum allowed is %2$d bytes.', 'tporret-api-data-importer' ),
					$template_size,
					$max_bytes
				)
			);
		}

		$max_expressions  = (int) apply_filters( 'tporapdi_template_max_expressions', 250, $type );
		$expression_count = substr_count( $template, '{{' ) + substr_count( $template, '{%' );
		if ( $max_expressions > 0 && $expression_count > $max_expressions ) {
			return new WP_Error( 'tporapdi_template_too_complex', __( 'Template has too many Twig expressions.', 'tporret-api-data-importer' ) );
		}

		if ( 1 === preg_match( '/\{\%\s*(include|source|import|from|embed|extends|use|macro)\b/i', $template ) ) {
			return new WP_Error( 'tporapdi_template_disallowed_tag', __( 'Template uses disallowed Twig tags.', 'tporret-api-data-importer' ) );
		}

		$max_nesting = (int) apply_filters( 'tporapdi_template_max_nesting_depth', 12, $type );
		$tokens      = array();
		$token_match = preg_match_all( '/\{\%\s*(if|for|endif|endfor)\b[^%]*\%\}/i', $template, $tokens );
		$depth       = 0;
		$max_seen    = 0;

		if ( false !== $token_match && ! empty( $tokens[1] ) ) {
			foreach ( $tokens[1] as $token ) {
				$token = strtolower( (string) $token );
				if ( 'if' === $token || 'for' === $token ) {
					++$depth;
					$max_seen = max( $max_seen, $depth );
				} elseif ( 'endif' === $token || 'endfor' === $token ) {
					$depth = max( 0, $depth - 1 );
				}
			}
		}

		if ( $max_nesting > 0 && $max_seen > $max_nesting ) {
			return new WP_Error( 'tporapdi_template_excessive_nesting', __( 'Template nesting depth is too high.', 'tporret-api-data-importer' ) );
		}

		$twig = tporapdi_get_template_engine()->environment();
		if ( is_wp_error( $twig ) ) {
			return $twig;
		}

		try {
			$source = new \Twig\Source( $template, 'eai-validate-' . $type );
			$twig->parse( $twig->tokenize( $source ) );
		} catch ( \Twig\Error\Error $error ) {
			return new WP_Error(
				'tporapdi_template_syntax_error',
				sprintf(
					/* translators: %s is the Twig exception message. */
					__( 'Twig template syntax error: %s', 'tporret-api-data-importer' ),
					sanitize_text_field( $error->getMessage() )
				)
			);
		}

		return true;
	}

	// -----------------------------------------------------------------------
	// Private: SSRF helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns true when an IP is private or reserved (RFC1918, loopback, etc.).
	 *
	 * @param string $ip IP address.
	 *
	 * @return bool
	 */
	private function is_private_ip( string $ip ): bool {
		$ip = trim( $ip );

		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Resolves a hostname to its IPv4 and IPv6 addresses.
	 *
	 * @param string $host Hostname or IP literal.
	 *
	 * @return string[]
	 */
	private function resolve_ips( string $host ): array {
		$host = strtolower( trim( $host ) );

		if ( '' === $host ) {
			return array();
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips = array();

		if ( function_exists( 'dns_get_record' ) ) {
			$a_records = dns_get_record( $host, DNS_A );
			$a_records = is_array( $a_records ) ? $a_records : array();
			foreach ( $a_records as $r ) {
				if ( isset( $r['ip'] ) && is_string( $r['ip'] ) ) {
					$ips[] = $r['ip'];
				}
			}

			if ( defined( 'DNS_AAAA' ) ) {
				$aaaa_records = dns_get_record( $host, DNS_AAAA );
				$aaaa_records = is_array( $aaaa_records ) ? $aaaa_records : array();
				foreach ( $aaaa_records as $r ) {
					if ( isset( $r['ipv6'] ) && is_string( $r['ipv6'] ) ) {
						$ips[] = $r['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$fallback = gethostbynamel( $host );
			if ( is_array( $fallback ) ) {
				$ips = array_merge( $ips, $fallback );
			}
		}

		return array_values( array_unique( array_filter( $ips, 'is_string' ) ) );
	}

	/**
	 * Returns true when a host should be blocked by SSRF policy.
	 *
	 * @param string $host Hostname or IP.
	 *
	 * @return bool
	 */
	private function is_disallowed_host( string $host ): bool {
		$host = strtolower( trim( $host ) );

		if ( '' === $host ) {
			return true;
		}

		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $this->is_private_ip( $host );
		}

		if ( 0 === substr_count( $host, '.' ) || str_ends_with( $host, '.local' ) ) {
			return true;
		}

		foreach ( $this->resolve_ips( $host ) as $ip ) {
			if ( $this->is_private_ip( $ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes comma/newline-separated allowlist entries into a clean array.
	 *
	 * @param mixed $raw Raw list (string or array).
	 *
	 * @return string[]
	 */
	private function normalize_allowlist( $raw ): array {
		$entries = is_array( $raw ) ? $raw : preg_split( '/[\r\n,]+/', (string) $raw );
		$entries = is_array( $entries ) ? $entries : array();

		$out = array();
		foreach ( $entries as $entry ) {
			$entry = strtolower( trim( (string) $entry ) );
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Returns true when a hostname matches an allowlist rule.
	 *
	 * Supports exact match and wildcard prefix (*.example.com).
	 *
	 * @param string $host Hostname.
	 * @param string $rule Allowlist rule.
	 *
	 * @return bool
	 */
	private function host_matches_rule( string $host, string $rule ): bool {
		$host = strtolower( trim( $host ) );
		$rule = strtolower( trim( $rule ) );

		if ( '' === $host || '' === $rule ) {
			return false;
		}

		if ( 0 === strpos( $rule, '*.' ) ) {
			$base = substr( $rule, 2 );
			if ( '' === $base ) {
				return false;
			}

			return $host === $base || str_ends_with( $host, '.' . $base );
		}

		return $host === $rule;
	}

	/**
	 * Returns true when an IP address falls within a CIDR block.
	 *
	 * Supports both IPv4 and IPv6.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr CIDR notation (e.g. 10.0.0.0/8).
	 *
	 * @return bool
	 */
	private function ip_in_cidr( string $ip, string $cidr ): bool {
		$ip   = trim( $ip );
		$cidr = trim( $cidr );

		if ( '' === $ip || '' === $cidr || false === strpos( $cidr, '/' ) ) {
			return false;
		}

		list( $network, $prefix ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		$prefix                   = (int) $prefix;

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! filter_var( $network, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$ip_bin      = inet_pton( $ip );
		$network_bin = inet_pton( $network );

		if ( false === $ip_bin || false === $network_bin || strlen( $ip_bin ) !== strlen( $network_bin ) ) {
			return false;
		}

		$max_bits = 8 * strlen( $ip_bin );
		if ( $prefix < 0 || $prefix > $max_bits ) {
			return false;
		}

		$full_bytes = (int) floor( $prefix / 8 );
		$extra_bits = $prefix % 8;

		if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $network_bin, 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $extra_bits ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $extra_bits ) ) & 0xFF;

		return ( ord( $ip_bin[ $full_bytes ] ) & $mask ) === ( ord( $network_bin[ $full_bytes ] ) & $mask );
	}
}
