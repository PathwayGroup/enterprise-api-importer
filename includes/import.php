<?php
/**
 * Import pipeline and queue processing.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'tporapdi_process_import_queue', 'tporapdi_handle_scheduled_import_batch' );
add_action( 'tporapdi_immediate_import_trigger', 'tporapdi_handle_import_batch_hook', 10, 2 );
add_action( 'tporapdi_recurring_import_trigger', 'tporapdi_handle_import_batch_hook', 10, 2 );
add_action( 'tporapdi_daily_garbage_collection', array( 'TPORAPDI_Import_Processor', 'run_garbage_collection' ) );
add_filter( 'cron_schedules', 'tporapdi_register_custom_cron_schedules' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'eai garbage-collect', 'tporapdi_wp_cli_run_garbage_collection' );
}

/**
 * Runs tporret API Data Importer garbage collection from WP-CLI.
 *
 * ## EXAMPLES
 *
 *     wp eai garbage-collect
 *
 * @param array<int, string>         $args       Positional WP-CLI args.
 * @param array<string, string|bool> $assoc_args Associative WP-CLI args.
 *
 * @return void
 */
function tporapdi_wp_cli_run_garbage_collection( $args, $assoc_args ) {
	unset( $args, $assoc_args );

	$results = TPORAPDI_Import_Processor::run_garbage_collection();

	if ( ! is_array( $results ) ) {
		WP_CLI::error( 'Garbage collection failed: invalid response payload.' );
	}

	$temp_deleted = isset( $results['temp_deleted'] ) ? absint( $results['temp_deleted'] ) : 0;
	$logs_deleted = isset( $results['logs_deleted'] ) ? absint( $results['logs_deleted'] ) : 0;
	$errors       = isset( $results['errors'] ) && is_array( $results['errors'] ) ? $results['errors'] : array();

	WP_CLI::log( sprintf( 'Temp rows deleted: %d', $temp_deleted ) );
	WP_CLI::log( sprintf( 'Log rows deleted: %d', $logs_deleted ) );

	if ( ! empty( $errors ) ) {
		foreach ( $errors as $error_message ) {
			if ( is_scalar( $error_message ) ) {
				WP_CLI::warning( (string) $error_message );
			}
		}

		WP_CLI::error( 'Garbage collection completed with errors.' );
	}

	WP_CLI::success( 'Garbage collection completed successfully.' );
}

/**
 * Registers custom recurrence schedules used by import jobs.
 *
 * @param array<string, array<string, mixed>> $schedules Existing schedules.
 *
 * @return array<string, array<string, mixed>>
 */
function tporapdi_register_custom_cron_schedules( $schedules ) {
	$rows = tporapdi_db_get_custom_recurrence_minutes();

	foreach ( $rows as $minutes_value ) {
		$minutes = max( 1, absint( $minutes_value ) );

		if ( $minutes <= 0 ) {
			continue;
		}

		$key = 'tporapdi_every_' . $minutes . '_minutes';

		if ( isset( $schedules[ $key ] ) ) {
			continue;
		}

		$schedules[ $key ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d is number of minutes. */
				__( 'Every %d minutes (tporret API Data Importer)', 'tporret-api-data-importer' ),
				$minutes
			),
		);
	}

	return $schedules;
}

/**
 * Converts recurrence settings to a cron schedule slug.
 *
 * @param string $recurrence             Recurrence key.
 * @param int    $custom_interval_minutes Custom interval in minutes.
 *
 * @return string Empty string means schedule is disabled.
 */
function tporapdi_get_recurrence_schedule_slug( $recurrence, $custom_interval_minutes = 0 ) {
	$recurrence = sanitize_key( (string) $recurrence );

	if ( 'hourly' === $recurrence || 'twicedaily' === $recurrence || 'daily' === $recurrence ) {
		return $recurrence;
	}

	if ( 'custom' === $recurrence ) {
		$minutes = max( 1, absint( $custom_interval_minutes ) );

		if ( $minutes > 0 ) {
			return 'tporapdi_every_' . $minutes . '_minutes';
		}
	}

	return '';
}

/**
 * Clears all scheduled trigger events for one import.
 *
 * @param int $import_id Import job ID.
 *
 * @return void
 */
function tporapdi_clear_import_scheduled_hooks( $import_id ) {
	$import_id = absint( $import_id );

	if ( $import_id <= 0 ) {
		return;
	}

	wp_clear_scheduled_hook( 'tporapdi_recurring_import_trigger', array( $import_id, 'recurring' ) );
	wp_clear_scheduled_hook( 'tporapdi_recurring_import_trigger', array( $import_id ) );
	wp_clear_scheduled_hook( 'tporapdi_immediate_import_trigger', array( $import_id, 'run_now' ) );
	wp_clear_scheduled_hook( 'tporapdi_immediate_import_trigger', array( $import_id ) );
}

/**
 * Synchronizes recurring cron registration for an import.
 *
 * @param int    $import_id                Import job ID.
 * @param string $recurrence               Recurrence key.
 * @param int    $custom_interval_minutes  Custom interval in minutes.
 *
 * @return bool
 */
function tporapdi_sync_import_recurrence_schedule( $import_id, $recurrence, $custom_interval_minutes = 0 ) {
	$import_id = absint( $import_id );

	if ( $import_id <= 0 ) {
		return false;
	}

	wp_clear_scheduled_hook( 'tporapdi_recurring_import_trigger', array( $import_id, 'recurring' ) );
	wp_clear_scheduled_hook( 'tporapdi_recurring_import_trigger', array( $import_id ) );

	$schedule_slug = tporapdi_get_recurrence_schedule_slug( $recurrence, $custom_interval_minutes );

	if ( '' === $schedule_slug ) {
		return true;
	}

	$next_scheduled = wp_next_scheduled( 'tporapdi_recurring_import_trigger', array( $import_id, 'recurring' ) );

	if ( false !== $next_scheduled ) {
		return true;
	}

	$start_timestamp = time() + MINUTE_IN_SECONDS;

	return false !== wp_schedule_event( $start_timestamp, $schedule_slug, 'tporapdi_recurring_import_trigger', array( $import_id, 'recurring' ) );
}

/**
 * Handles import-specific cron trigger.
 *
 * @param int    $import_id       Import job ID.
 * @param string $trigger_source  Trigger source context.
 *
 * @return void
 */
function tporapdi_handle_import_batch_hook( $import_id, $trigger_source = 'run_now' ) {
	$import_id       = absint( $import_id );
	$trigger_source  = sanitize_key( (string) $trigger_source );
	$allowed_sources = array( 'manual', 'run_now', 'recurring' );

	if ( ! in_array( $trigger_source, $allowed_sources, true ) ) {
		$trigger_source = 'run_now';
	}

	if ( $import_id <= 0 ) {
		return;
	}

	$active_state = tporapdi_get_active_run_state();

	if ( ! empty( $active_state['run_id'] ) ) {
		if ( isset( $active_state['import_id'] ) && absint( $active_state['import_id'] ) === $import_id ) {
			tporapdi_handle_scheduled_import_batch();
		}
		return;
	}

	$extract_result = tporapdi_extract_and_stage_data( $import_id );

	if ( is_wp_error( $extract_result ) ) {
		$now = gmdate( 'Y-m-d H:i:s', time() );

		tporapdi_write_import_log(
			$import_id,
			wp_generate_uuid4(),
			'failed',
			0,
			0,
			0,
			array(
				'start_time'          => $now,
				'end_time'            => $now,
				'orphans_trashed'     => 0,
				'temp_rows_found'     => 0,
				'temp_rows_processed' => 0,
				'slices'              => 0,
				'trigger_source'      => $trigger_source,
				'processing_errors'   => array( $extract_result->get_error_message() ),
			),
			$now
		);

		return;
	}

	$started_unix = time();

	tporapdi_set_active_run_state(
		array(
			'run_id'              => wp_generate_uuid4(),
			'import_id'           => $import_id,
			'trigger_source'      => $trigger_source,
			'start_timestamp'     => $started_unix,
			'start_time'          => gmdate( 'Y-m-d H:i:s', $started_unix ),
			'rows_processed'      => 0,
			'rows_created'        => 0,
			'rows_updated'        => 0,
			'temp_rows_found'     => 0,
			'temp_rows_processed' => 0,
			'errors'              => array(),
			'slices'              => 0,
		)
	);

	tporapdi_handle_scheduled_import_batch();
}

/**
 * Returns true when an IP address is private/reserved and should be blocked by default.
 *
 * @param string $ip_address IP address to evaluate.
 *
 * @return bool
 */
function tporapdi_is_private_or_reserved_ip( $ip_address ) {
	$ip_address = is_string( $ip_address ) ? trim( $ip_address ) : '';

	if ( '' === $ip_address || false === filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
		return true;
	}

	return false === filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
}

/**
 * Resolves a hostname to a list of IPv4/IPv6 addresses.
 *
 * @param string $host Hostname.
 * @return string[]
 */
function tporapdi_resolve_host_ips( $host ) {
	$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';

	if ( '' === $host ) {
		return array();
	}

	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return array( $host );
	}

	$resolved_ips = array();

	if ( function_exists( 'dns_get_record' ) ) {
		$ipv4_records = dns_get_record( $host, DNS_A );
		$ipv4_records = is_array( $ipv4_records ) ? $ipv4_records : array();

		foreach ( $ipv4_records as $ipv4_record ) {
			if ( isset( $ipv4_record['ip'] ) && is_string( $ipv4_record['ip'] ) ) {
				$resolved_ips[] = $ipv4_record['ip'];
			}
		}

		if ( defined( 'DNS_AAAA' ) ) {
			$ipv6_records = dns_get_record( $host, DNS_AAAA );
			$ipv6_records = is_array( $ipv6_records ) ? $ipv6_records : array();

			foreach ( $ipv6_records as $ipv6_record ) {
				if ( isset( $ipv6_record['ipv6'] ) && is_string( $ipv6_record['ipv6'] ) ) {
					$resolved_ips[] = $ipv6_record['ipv6'];
				}
			}
		}
	}

	if ( empty( $resolved_ips ) && function_exists( 'gethostbynamel' ) ) {
		$ipv4_fallback = gethostbynamel( $host );
		if ( is_array( $ipv4_fallback ) ) {
			$resolved_ips = array_merge( $resolved_ips, $ipv4_fallback );
		}
	}

	return array_values( array_unique( array_filter( $resolved_ips, 'is_string' ) ) );
}

/**
 * Returns true when a host resolves to local/private/reserved networks.
 *
 * @param string $host Hostname or IP.
 *
 * @return bool
 */
function tporapdi_is_disallowed_remote_host( $host ) {
	$host = is_string( $host ) ? strtolower( trim( $host ) ) : '';

	if ( '' === $host ) {
		return true;
	}

	if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
		return true;
	}

	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return tporapdi_is_private_or_reserved_ip( $host );
	}

	if ( 0 === substr_count( $host, '.' ) || str_ends_with( $host, '.local' ) ) {
		return true;
	}

	$resolved_ips = tporapdi_resolve_host_ips( $host );

	foreach ( $resolved_ips as $resolved_ip ) {
		if ( tporapdi_is_private_or_reserved_ip( $resolved_ip ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Normalizes comma/newline separated allowlist entries.
 *
 * @param mixed $raw_list Raw list string or array.
 * @return string[]
 */
function tporapdi_normalize_security_allowlist( $raw_list ) {
	$entries = is_array( $raw_list ) ? $raw_list : preg_split( '/[\r\n,]+/', (string) $raw_list );
	$entries = is_array( $entries ) ? $entries : array();

	$normalized = array();
	foreach ( $entries as $entry ) {
		$entry = strtolower( trim( (string) $entry ) );
		if ( '' !== $entry ) {
			$normalized[] = $entry;
		}
	}

	return array_values( array_unique( $normalized ) );
}

/**
 * Returns true when host matches an allowlist rule.
 *
 * Supports exact host and wildcard prefix rules (for example *.example.com).
 *
 * @param string $host Hostname.
 * @param string $rule Allowlist rule.
 * @return bool
 */
function tporapdi_host_matches_allow_rule( $host, $rule ) {
	$host = strtolower( trim( (string) $host ) );
	$rule = strtolower( trim( (string) $rule ) );

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
 * Checks whether an IP matches a CIDR block.
 *
 * @param string $ip   IP address.
 * @param string $cidr CIDR block.
 * @return bool
 */
function tporapdi_ip_matches_cidr( $ip, $cidr ) {
	$ip   = trim( (string) $ip );
	$cidr = trim( (string) $cidr );

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

/**
 * Validates a configured endpoint URL against plugin security policy.
 *
 * @param string $endpoint Endpoint URL.
 *
 * @return true|WP_Error
 */
function tporapdi_validate_remote_endpoint_url( $endpoint ) {
	$endpoint = is_string( $endpoint ) ? trim( $endpoint ) : '';

	if ( '' === $endpoint || ! wp_http_validate_url( $endpoint ) ) {
		return new WP_Error( 'tporapdi_invalid_endpoint_url', __( 'A valid endpoint URL is required.', 'tporret-api-data-importer' ) );
	}

	$settings = wp_parse_args( get_option( 'tporapdi_settings', array() ), tporapdi_get_default_settings() );

	$allow_internal_endpoints = ! empty( $settings['allow_internal_endpoints'] ) && '0' !== (string) $settings['allow_internal_endpoints'];
	$allow_internal_endpoints = (bool) apply_filters( 'tporapdi_allow_internal_endpoints', $allow_internal_endpoints, $endpoint );

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

	$allowed_hosts = tporapdi_normalize_security_allowlist(
		apply_filters(
			'tporapdi_allowed_endpoint_hosts',
			isset( $settings['allowed_endpoint_hosts'] ) ? $settings['allowed_endpoint_hosts'] : array(),
			$endpoint
		)
	);

	if ( ! empty( $allowed_hosts ) ) {
		$host_match = false;
		foreach ( $allowed_hosts as $allowed_host ) {
			if ( tporapdi_host_matches_allow_rule( $host, $allowed_host ) ) {
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

	$allowed_cidrs = tporapdi_normalize_security_allowlist(
		apply_filters(
			'tporapdi_allowed_endpoint_cidrs',
			isset( $settings['allowed_endpoint_cidrs'] ) ? $settings['allowed_endpoint_cidrs'] : array(),
			$endpoint
		)
	);

	if ( ! empty( $allowed_cidrs ) ) {
		$resolved_ips = tporapdi_resolve_host_ips( $host );
		$cidr_match   = false;

		foreach ( $resolved_ips as $resolved_ip ) {
			foreach ( $allowed_cidrs as $allowed_cidr ) {
				if ( tporapdi_ip_matches_cidr( $resolved_ip, $allowed_cidr ) ) {
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

	if ( ! $allow_internal_endpoints ) {
		if ( tporapdi_is_disallowed_remote_host( $host ) ) {
			return new WP_Error(
				'tporapdi_endpoint_disallowed_host',
				__( 'This endpoint host is blocked by security policy. Use a trusted public host or explicitly allow internal endpoints.', 'tporret-api-data-importer' )
			);
		}
	}

	return true;
}

/**
 * Builds hardened default arguments for remote endpoint requests.
 *
 * Supports four authentication methods:
 * - 'none':           No authentication headers
 * - 'bearer':         Standard OAuth Bearer token (Authorization: Bearer <token>)
 * - 'api_key_custom': Custom header name with API key value
 * - 'basic_auth':     HTTP Basic Auth (Authorization: Basic base64(user:pass))
 *
 * @param string $auth_method      Authentication method.
 * @param string $token            Token / API key value (bearer and api_key_custom).
 * @param string $auth_header_name Custom header name (api_key_custom only).
 * @param string $auth_username    Username (basic_auth only).
 * @param string $auth_password    Password (basic_auth only).
 * @param int    $timeout          Request timeout seconds.
 *
 * @return array<string, mixed>
 */
function tporapdi_get_remote_request_args( $auth_method = 'none', $token = '', $auth_header_name = '', $auth_username = '', $auth_password = '', $timeout = 30 ) {
	$headers = array(
		'Accept' => 'application/json',
	);

	$auth_method = sanitize_key( (string) $auth_method );

	switch ( $auth_method ) {
		case 'bearer':
			if ( '' !== $token ) {
				$headers['Authorization'] = 'Bearer ' . $token;
			}
			break;

		case 'api_key_custom':
			if ( '' !== $token && '' !== $auth_header_name ) {
				$headers[ $auth_header_name ] = $token;
			}
			break;

		case 'basic_auth':
			if ( '' !== $auth_username ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth per RFC 7617.
				$headers['Authorization'] = 'Basic ' . base64_encode( $auth_username . ':' . $auth_password );
			}
			break;

		case 'none':
		default:
			// No authentication headers.
			break;
	}

	$args = array(
		'timeout'            => max( 1, (int) $timeout ),
		'redirection'        => 3,
		'headers'            => $headers,
		'reject_unsafe_urls' => true,
	);

	return apply_filters( 'tporapdi_remote_request_args', $args, $auth_method );
}

/**
 * Fetches API payload with optional transient caching.
 *
 * @param string $endpoint        Endpoint URL.
 * @param string $auth_method     Authentication method.
 * @param string $token           Token / API key value.
 * @param string $auth_header_name Custom header name (api_key_custom only).
 * @param string $auth_username   Username (basic_auth only).
 * @param string $auth_password   Password (basic_auth only).
 * @param bool   $bypass_cache    Whether to bypass cache and force a live call.
 *
 * @return array<string, mixed>|WP_Error
 */
function tporapdi_fetch_api_payload( $endpoint, $auth_method = 'none', $token = '', $auth_header_name = '', $auth_username = '', $auth_password = '', $bypass_cache = false ) {
	$validated_endpoint = tporapdi_validate_remote_endpoint_url( $endpoint );
	if ( is_wp_error( $validated_endpoint ) ) {
		return $validated_endpoint;
	}

	$cache_key      = 'tporapdi_api_cache_' . md5( $endpoint );
	$cached_payload = false;
	$used_cache     = false;

	if ( ! $bypass_cache ) {
		$cached_payload = get_transient( $cache_key );
	}

	if ( false === $cached_payload ) {
		$response = wp_remote_get(
			$endpoint,
			tporapdi_get_remote_request_args( $auth_method, $token, $auth_header_name, $auth_username, $auth_password )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$response_message = (string) wp_remote_retrieve_response_message( $response );
			$response_body    = (string) wp_remote_retrieve_body( $response );
			$body_excerpt     = trim( wp_strip_all_tags( $response_body ) );

			if ( '' !== $body_excerpt ) {
				$body_excerpt = substr( $body_excerpt, 0, 400 );
			}

			$error_message = sprintf(
				/* translators: 1: HTTP status code, 2: HTTP response message. */
				__( 'API request failed with status code %1$d (%2$s).', 'tporret-api-data-importer' ),
				$status_code,
				'' !== $response_message ? $response_message : __( 'No response message', 'tporret-api-data-importer' )
			);

			if ( '' !== $body_excerpt ) {
				$error_message .= ' ' . sprintf(
					/* translators: %s is a trimmed response body excerpt. */
					__( 'Response: %s', 'tporret-api-data-importer' ),
					$body_excerpt
				);
			}

			return new WP_Error(
				'tporapdi_api_http_error',
				$error_message,
				array(
					'status_code'      => $status_code,
					'response_message' => $response_message,
					'response_excerpt' => $body_excerpt,
				)
			);
		}

		$cached_payload = wp_remote_retrieve_body( $response );

		if ( '' === $cached_payload ) {
			return new WP_Error( 'tporapdi_empty_response', __( 'API returned an empty response body.', 'tporret-api-data-importer' ) );
		}

		set_transient( $cache_key, $cached_payload, 5 * MINUTE_IN_SECONDS );
	} else {
		$used_cache  = true;
		$status_code = 200;
	}

	return array(
		'body'        => (string) $cached_payload,
		'used_cache'  => $used_cache,
		'status_code' => (int) $status_code,
	);
}

/**
 * Extracts API data and stages the selected array into the temp table.
 *
 * @param int $import_id Import job ID.
 *
 * @return array<string, mixed>|WP_Error
 */
function tporapdi_extract_and_stage_data( $import_id ) {
	$import_id  = absint( $import_id );
	$import_job = tporapdi_db_get_import_config( $import_id );

	if ( ! is_array( $import_job ) ) {
		return new WP_Error( 'tporapdi_import_not_found', __( 'Import job could not be found.', 'tporret-api-data-importer' ) );
	}

	$endpoint         = trim( (string) $import_job['endpoint_url'] );
	$auth_method      = trim( (string) ( $import_job['auth_method'] ?? 'none' ) );
	$token            = trim( (string) $import_job['auth_token'] );
	$auth_header_name = trim( (string) ( $import_job['auth_header_name'] ?? '' ) );
	$auth_username    = trim( (string) ( $import_job['auth_username'] ?? '' ) );
	$auth_password    = (string) ( $import_job['auth_password'] ?? '' );
	$json_path        = trim( (string) $import_job['array_path'] );

	if ( '' === $endpoint ) {
		return new WP_Error( 'tporapdi_missing_endpoint', __( 'API endpoint URL is not configured.', 'tporret-api-data-importer' ) );
	}

	$fetched_payload = tporapdi_fetch_api_payload( $endpoint, $auth_method, $token, $auth_header_name, $auth_username, $auth_password );

	if ( is_wp_error( $fetched_payload ) ) {
		return $fetched_payload;
	}

	$cached_payload = (string) $fetched_payload['body'];
	$used_cache     = ! empty( $fetched_payload['used_cache'] );

	$decoded_json = json_decode( (string) $cached_payload, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error(
			'tporapdi_invalid_json',
			sprintf(
				/* translators: %s is a JSON parser error message. */
				__( 'Unable to decode API JSON payload: %s', 'tporret-api-data-importer' ),
				json_last_error_msg()
			)
		);
	}

	$selected_array = tporapdi_resolve_json_array_path( $decoded_json, $json_path );

	if ( is_wp_error( $selected_array ) ) {
		return $selected_array;
	}

	$filter_rules = tporapdi_decode_filter_rules_json( isset( $import_job['filter_rules'] ) ? (string) $import_job['filter_rules'] : '' );
	if ( ! empty( $filter_rules ) ) {
		$selected_array = tporapdi_apply_filter_rules_to_records( $selected_array, $filter_rules );
	}

	$serialized_selected_array = wp_json_encode( $selected_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $serialized_selected_array ) {
		return new WP_Error( 'tporapdi_json_encode_failed', __( 'Failed to serialize extracted array for staging.', 'tporret-api-data-importer' ) );
	}

	$insert_id = tporapdi_db_insert_staging_payload( $import_id, $serialized_selected_array );
	if ( is_wp_error( $insert_id ) ) {
		return $insert_id;
	}

	$row_count = 0;
	if ( is_array( $selected_array ) ) {
		if ( tporapdi_array_is_list( $selected_array ) ) {
			$row_count = count( $selected_array );
		} elseif ( ! empty( $selected_array ) ) {
			$row_count = 1;
		}
	}

	return array(
		'import_id'  => $import_id,
		'insert_id'  => (int) $insert_id,
		'used_cache' => $used_cache,
		'row_count'  => (int) $row_count,
	);
}

/**
 * Decodes and sanitizes filter rules JSON.
 *
 * @param string $filter_rules_json JSON string from import settings.
 *
 * @return array<int, array<string, string>>
 */
function tporapdi_decode_filter_rules_json( $filter_rules_json ) {
	$decoded = json_decode( (string) $filter_rules_json, true );
	if ( ! is_array( $decoded ) ) {
		return array();
	}

	$allowed_operators = array(
		'equals',
		'not_equals',
		'contains',
		'not_contains',
		'is_empty',
		'not_empty',
		'greater_than',
		'less_than',
	);

	$rules = array();
	foreach ( $decoded as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$key      = isset( $rule['key'] ) ? trim( sanitize_text_field( (string) $rule['key'] ) ) : '';
		$operator = isset( $rule['operator'] ) ? sanitize_key( (string) $rule['operator'] ) : '';
		$value    = isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '';

		if ( '' === $key || ! in_array( $operator, $allowed_operators, true ) ) {
			continue;
		}

		$rules[] = array(
			'key'      => $key,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	return $rules;
}

/**
 * Applies all configured filter rules to selected records using AND logic.
 *
 * @param array<mixed>                      $selected_array Records selected from API payload.
 * @param array<int, array<string, string>> $filter_rules   Sanitized filter rules.
 *
 * @return array<mixed>
 */
function tporapdi_apply_filter_rules_to_records( $selected_array, $filter_rules ) {
	if ( ! is_array( $selected_array ) || empty( $filter_rules ) ) {
		return is_array( $selected_array ) ? $selected_array : array();
	}

	$is_list_array = tporapdi_array_is_list( $selected_array );
	$records       = $is_list_array ? $selected_array : array( $selected_array );
	$filtered      = array();

	foreach ( $records as $record ) {
		if ( ! is_array( $record ) ) {
			continue;
		}

		$passes_all_rules = true;
		foreach ( $filter_rules as $filter_rule ) {
			$record_value = tporapdi_get_item_value_by_path( $record, $filter_rule['key'] );
			if ( ! tporapdi_evaluate_filter_rule( $record_value, $filter_rule['operator'], $filter_rule['value'] ) ) {
				$passes_all_rules = false;
				break;
			}
		}

		if ( $passes_all_rules ) {
			$filtered[] = $record;
		}
	}

	if ( $is_list_array ) {
		return array_values( $filtered );
	}

	if ( empty( $filtered ) ) {
		return array();
	}

	return (array) $filtered[0];
}

/**
 * Evaluates one filter rule against a single record value.
 *
 * @param mixed  $record_value Record value from payload.
 * @param string $operator     Rule operator.
 * @param string $filter_value Rule value.
 *
 * @return bool
 */
function tporapdi_evaluate_filter_rule( $record_value, $operator, $filter_value ) {
	$operator = sanitize_key( (string) $operator );

	$record_is_empty = null === $record_value;
	if ( is_string( $record_value ) ) {
		$record_is_empty = '' === trim( $record_value );
	} elseif ( is_array( $record_value ) ) {
		$record_is_empty = empty( $record_value );
	}

	if ( 'is_empty' === $operator ) {
		return $record_is_empty;
	}

	if ( 'not_empty' === $operator ) {
		return ! $record_is_empty;
	}

	$record_string = is_scalar( $record_value ) ? trim( (string) $record_value ) : '';
	$filter_string = trim( (string) $filter_value );

	if ( is_numeric( $record_string ) && is_numeric( $filter_string ) ) {
		$record_number = (float) $record_string;
		$filter_number = (float) $filter_string;

		switch ( $operator ) {
			case 'equals':
				return $record_number === $filter_number;
			case 'not_equals':
				return $record_number !== $filter_number;
			case 'greater_than':
				return $record_number > $filter_number;
			case 'less_than':
				return $record_number < $filter_number;
		}
	}

	$record_lower = strtolower( $record_string );
	$filter_lower = strtolower( $filter_string );

	switch ( $operator ) {
		case 'equals':
			return $record_lower === $filter_lower;
		case 'not_equals':
			return $record_lower !== $filter_lower;
		case 'contains':
			return '' !== $filter_lower && false !== strpos( $record_lower, $filter_lower );
		case 'not_contains':
			return '' === $filter_lower || false === strpos( $record_lower, $filter_lower );
		case 'greater_than':
			return strcmp( $record_lower, $filter_lower ) > 0;
		case 'less_than':
			return strcmp( $record_lower, $filter_lower ) < 0;
		default:
			return false;
	}
}

/**
 * Resolves a dot-notation JSON path and ensures the result is an array.
 *
 * Example path: data.employees
 *
 * @param mixed  $decoded_json Parsed JSON payload.
 * @param string $path         Dot-notation path.
 *
 * @return array<mixed>|WP_Error
 */
function tporapdi_resolve_json_array_path( $decoded_json, $path ) {
	$segments = array_filter(
		explode( '.', $path ),
		static function ( $segment ) {
			return '' !== $segment;
		}
	);

	$current = $decoded_json;

	foreach ( $segments as $segment ) {
		if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
			$current = $current[ $segment ];
			continue;
		}

		if ( is_array( $current ) && ctype_digit( $segment ) && array_key_exists( (int) $segment, $current ) ) {
			$current = $current[ (int) $segment ];
			continue;
		}

		return new WP_Error(
			'tporapdi_json_path_not_found',
			sprintf(
				/* translators: %s is the configured JSON path. */
				__( 'JSON Array Path not found in payload: %s', 'tporret-api-data-importer' ),
				$path
			)
		);
	}

	if ( ! is_array( $current ) ) {
		return new WP_Error(
			'tporapdi_json_path_not_array',
			sprintf(
				/* translators: %s is the configured JSON path. */
				__( 'JSON Array Path did not resolve to an array: %s', 'tporret-api-data-importer' ),
				$path
			)
		);
	}

	return $current;
}

/**
 * Transforms and loads a single API record into the configured post type.
 *
 * @param array<string, mixed> $item Single API record.
 *
 * @param string               $mapping_template Mapping template for this import job.
 * @param string               $title_template   Optional title template for this import job.
 * @param string               $target_post_type          Target post type for this import job.
 * @param string               $unique_id_path            Unique identifier path. Defaults to id.
 * @param int                  $import_id                 Import job ID.
 * @param string               $featured_image_source_path Dot-notation item path for featured image URL.
 * @param int                  $post_author               WordPress user ID to assign as post author. 0 = default.
 * @param string               $post_status               WordPress post status for new items. Default 'draft'.
 * @param string               $comment_status            Comment status for new items. Default 'closed'.
 * @param string               $ping_status               Ping status for new items. Default 'closed'.
 * @param array                $custom_meta_mappings      Array of {key, value} custom meta mappings. Default empty.
 * @param array<string, int>   $existing_post_ids         Map of external IDs to existing post IDs.
 * @param string               $excerpt_template          Optional excerpt template for this import job.
 * @param string               $post_name_template        Optional slug template for this import job.
 *
 * @return array<string, mixed>|WP_Error
 */
function tporapdi_transform_and_load_item( $item, $mapping_template, $title_template = '', $target_post_type = 'post', $unique_id_path = 'id', $import_id = 0, $featured_image_source_path = 'image.url', $post_author = 0, $post_status = 'draft', $comment_status = 'closed', $ping_status = 'closed', $custom_meta_mappings = array(), $existing_post_ids = array(), $excerpt_template = '', $post_name_template = '' ) {
	if ( ! is_array( $item ) ) {
		return new WP_Error( 'tporapdi_invalid_item', __( 'Transform input must be an array.', 'tporret-api-data-importer' ) );
	}

	$mapping_template           = (string) $mapping_template;
	$title_template             = trim( (string) $title_template );
	$excerpt_template           = trim( (string) $excerpt_template );
	$post_name_template         = trim( (string) $post_name_template );
	$target_post_type           = sanitize_key( (string) $target_post_type );
	$unique_id_path             = trim( (string) $unique_id_path );
	$import_id                  = absint( $import_id );
	$featured_image_source_path = trim( (string) $featured_image_source_path );
	$post_author                = absint( $post_author );

	$allowed_post_statuses = array( 'draft', 'publish', 'pending' );
	$post_status           = in_array( (string) $post_status, $allowed_post_statuses, true ) ? (string) $post_status : 'draft';
	$comment_status        = in_array( (string) $comment_status, array( 'open', 'closed' ), true ) ? (string) $comment_status : 'closed';
	$ping_status           = in_array( (string) $ping_status, array( 'open', 'closed' ), true ) ? (string) $ping_status : 'closed';

	if ( '' === $target_post_type || 'attachment' === $target_post_type || ! post_type_exists( $target_post_type ) ) {
		$target_post_type = 'post';
	}

	if ( '' === $unique_id_path ) {
		$unique_id_path = 'id';
	}

	if ( '' === $featured_image_source_path ) {
		$featured_image_source_path = 'image.url';
	}

	$external_id_value = tporapdi_get_item_value_by_path( $item, $unique_id_path );

	if ( null === $external_id_value || '' === (string) $external_id_value ) {
		return new WP_Error(
			'tporapdi_missing_item_id',
			sprintf(
				/* translators: %s is the configured unique ID path. */
				__( 'Item is missing required unique ID at path: %s', 'tporret-api-data-importer' ),
				$unique_id_path
			)
		);
	}

	if ( ! is_scalar( $external_id_value ) ) {
		return new WP_Error( 'tporapdi_invalid_item_id', __( 'Unique ID field must resolve to a scalar value.', 'tporret-api-data-importer' ) );
	}

	$external_id = (string) $external_id_value;

	if ( '' === $mapping_template ) {
		return new WP_Error( 'tporapdi_missing_mapping_template', __( 'Mapping Template is not configured.', 'tporret-api-data-importer' ) );
	}

	$post_content = tporapdi_render_mapping_template_for_item( $item, $mapping_template );

	if ( ! is_wp_error( $post_content ) ) {
		$post_content = wp_kses_post( $post_content );
	}

	if ( is_wp_error( $post_content ) ) {
		if ( 'tporapdi_template_syntax_error' === $post_content->get_error_code() ) {
			$now = gmdate( 'Y-m-d H:i:s', time() );

			tporapdi_write_import_log(
				$import_id,
				wp_generate_uuid4(),
				'Template Syntax Error',
				0,
				0,
				0,
				array(
					'start_time'        => $now,
					'end_time'          => $now,
					'template_error'    => true,
					'import_id'         => $import_id,
					'external_id'       => $external_id,
					'processing_errors' => array( $post_content->get_error_message() ),
				),
				$now
			);
		}

		return $post_content;
	}

	$fallback_record_id = $external_id;
	if ( isset( $item['id'] ) && is_scalar( $item['id'] ) && '' !== (string) $item['id'] ) {
		$fallback_record_id = (string) $item['id'];
	}

	$post_title = sprintf(
		/* translators: %s is the external API record ID. */
		__( 'Imported Item %s', 'tporret-api-data-importer' ),
		$fallback_record_id
	);

	if ( '' !== $title_template ) {
		$twig = tporapdi_get_twig_environment();

		if ( is_wp_error( $twig ) ) {
			return $twig;
		}

		$loader = $twig->getLoader();
		if ( ! $loader instanceof \Twig\Loader\ArrayLoader ) {
			return new WP_Error( 'tporapdi_twig_loader_invalid', __( 'Twig loader is not configured for string templates.', 'tporret-api-data-importer' ) );
		}

		$template_name = 'title-template-' . md5( $title_template );

		try {
			$loader->setTemplate( $template_name, $title_template );

			$rendered_title = (string) $twig->render(
				$template_name,
				array(
					'record' => $item,
					'item'   => $item,
					'data'   => $item,
				)
			);
		} catch ( \Twig\Error\Error $error ) {
			return new WP_Error(
				'tporapdi_template_syntax_error',
				sprintf(
					/* translators: %s is the Twig exception message. */
					__( 'Twig template syntax error: %s', 'tporret-api-data-importer' ),
					$error->getMessage()
				)
			);
		}

		$rendered_title = wp_strip_all_tags( $rendered_title );
		$rendered_title = trim( $rendered_title );
		$rendered_title = mb_substr( $rendered_title, 0, 255 );

		if ( '' !== $rendered_title ) {
			$post_title = $rendered_title;
		}
	}

	$post_excerpt = '';
	if ( '' !== $excerpt_template ) {
		$twig = tporapdi_get_twig_environment();
		if ( ! is_wp_error( $twig ) ) {
			$loader = $twig->getLoader();
			if ( $loader instanceof \Twig\Loader\ArrayLoader ) {
				$excerpt_tpl_name = 'excerpt-template-' . md5( $excerpt_template );
				try {
					$loader->setTemplate( $excerpt_tpl_name, $excerpt_template );
					$rendered_excerpt = (string) $twig->render(
						$excerpt_tpl_name,
						array(
							'record' => $item,
							'item'   => $item,
							'data'   => $item,
						)
					);
					$post_excerpt     = wp_strip_all_tags( trim( $rendered_excerpt ) );
					$post_excerpt     = mb_substr( $post_excerpt, 0, 1000 );
				} catch ( \Twig\Error\Error $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Non-fatal: fall through with empty excerpt.
				}
			}
		}
	}

	$post_name = '';
	if ( '' !== $post_name_template ) {
		$twig = tporapdi_get_twig_environment();
		if ( ! is_wp_error( $twig ) ) {
			$loader = $twig->getLoader();
			if ( $loader instanceof \Twig\Loader\ArrayLoader ) {
				$slug_tpl_name = 'slug-template-' . md5( $post_name_template );
				try {
					$loader->setTemplate( $slug_tpl_name, $post_name_template );
					$rendered_slug = (string) $twig->render(
						$slug_tpl_name,
						array(
							'record' => $item,
							'item'   => $item,
							'data'   => $item,
						)
					);
					$post_name     = sanitize_title( trim( $rendered_slug ) );
				} catch ( \Twig\Error\Error $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// Non-fatal: fall through with empty slug (WordPress will auto-generate).
				}
			}
		}
	}

	$existing_post_id = 0;

	if ( is_array( $existing_post_ids ) && isset( $existing_post_ids[ $external_id ] ) ) {
		$existing_post_id = absint( $existing_post_ids[ $external_id ] );
	}

	if ( 0 === $existing_post_id ) {
		$existing_posts = get_posts(
			array(
				'post_type'              => $target_post_type,
				'posts_per_page'         => 1,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_my_custom_api_id',
						'value'   => $external_id,
						'compare' => '=',
					),
					array(
						'key'     => '_tporapdi_import_id',
						'value'   => $import_id,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $existing_posts ) ) {
			$existing_post_id = (int) $existing_posts[0];
		}
	}

	$timestamp = time();

	if ( $existing_post_id > 0 ) {
		$post_content = TPORAPDI_Import_Processor::parse_and_sideload_content_images( $post_content, $existing_post_id );
	}

	if ( 0 === $existing_post_id ) {
		$insert_args = array(
			'post_type'      => $target_post_type,
			'post_status'    => $post_status,
			'post_title'     => $post_title,
			'post_content'   => $post_content,
			'post_excerpt'   => $post_excerpt,
			'comment_status' => $comment_status,
			'ping_status'    => $ping_status,
		);
		if ( '' !== $post_name ) {
			$insert_args['post_name'] = $post_name;
		}
		if ( $post_author > 0 ) {
			$insert_args['post_author'] = $post_author;
		}

		$insert_post_id = wp_insert_post( $insert_args, true );

		if ( is_wp_error( $insert_post_id ) ) {
			return $insert_post_id;
		}

		$post_content = TPORAPDI_Import_Processor::parse_and_sideload_content_images( $post_content, $insert_post_id );

		if ( (string) get_post_field( 'post_content', $insert_post_id ) !== (string) $post_content ) {
			$updated_insert_post_id = wp_update_post(
				array(
					'ID'           => $insert_post_id,
					'post_content' => $post_content,
				),
				true
			);

			if ( is_wp_error( $updated_insert_post_id ) ) {
				return $updated_insert_post_id;
			}
		}

		tporapdi_assign_featured_image_from_item( $item, $insert_post_id, $import_id, $featured_image_source_path );

		update_post_meta( $insert_post_id, '_my_custom_api_id', $external_id );
		update_post_meta( $insert_post_id, '_tporapdi_import_id', $import_id );
		update_post_meta( $insert_post_id, '_last_synced_timestamp', $timestamp );
		tporapdi_save_item_meta_with_manifest( $insert_post_id, $item, $import_id );
		tporapdi_apply_custom_meta_mappings( $insert_post_id, $item, $custom_meta_mappings );

		return array(
			'action'  => 'inserted',
			'post_id' => (int) $insert_post_id,
		);
	}

	$existing_post = get_post( $existing_post_id );

	if ( ! $existing_post instanceof WP_Post ) {
		return new WP_Error( 'tporapdi_existing_post_not_found', __( 'Existing imported item post could not be loaded.', 'tporret-api-data-importer' ) );
	}

	$featured_image_updated = tporapdi_assign_featured_image_from_item( $item, $existing_post_id, $import_id, $featured_image_source_path );

	$update_args = array(
		'ID' => $existing_post_id,
	);

	if ( (string) $existing_post->post_title !== (string) $post_title ) {
		$update_args['post_title'] = $post_title;
	}

	if ( (string) $existing_post->post_content !== (string) $post_content ) {
		$update_args['post_content'] = $post_content;
	}

	if ( (string) $existing_post->post_excerpt !== (string) $post_excerpt ) {
		$update_args['post_excerpt'] = $post_excerpt;
	}

	if ( (string) $existing_post->post_status !== (string) $post_status ) {
		$update_args['post_status'] = $post_status;
	}

	if ( (string) $existing_post->comment_status !== (string) $comment_status ) {
		$update_args['comment_status'] = $comment_status;
	}

	if ( (string) $existing_post->ping_status !== (string) $ping_status ) {
		$update_args['ping_status'] = $ping_status;
	}

	if ( '' !== $post_name && (string) $existing_post->post_name !== (string) $post_name ) {
		$update_args['post_name'] = $post_name;
	}

	if ( $post_author > 0 && (int) $existing_post->post_author !== (int) $post_author ) {
		$update_args['post_author'] = $post_author;
	}

	if ( count( $update_args ) > 1 ) {
		$updated_post_id = wp_update_post( $update_args, true );

		if ( is_wp_error( $updated_post_id ) ) {
			return $updated_post_id;
		}

		update_post_meta( $existing_post_id, '_last_synced_timestamp', $timestamp );
		tporapdi_save_item_meta_with_manifest( $existing_post_id, $item, $import_id );
		tporapdi_apply_custom_meta_mappings( $existing_post_id, $item, $custom_meta_mappings );

		return array(
			'action'  => 'updated',
			'post_id' => $existing_post_id,
		);
	}

	// Touch sync timestamp even when content and title are unchanged so valid items are not treated as orphans.
	update_post_meta( $existing_post_id, '_last_synced_timestamp', $timestamp );
	tporapdi_save_item_meta_with_manifest( $existing_post_id, $item, $import_id );
	tporapdi_apply_custom_meta_mappings( $existing_post_id, $item, $custom_meta_mappings );

	return array(
		'action'  => $featured_image_updated ? 'updated' : 'unchanged',
		'post_id' => $existing_post_id,
	);
}

/**
 * Applies custom meta mappings by rendering each value through Twig.
 *
 * @param int                  $post_id  The WordPress post ID.
 * @param array<string, mixed> $item     The raw API record for Twig context.
 * @param array                $mappings Array of {key, value} mapping objects.
 */
function tporapdi_apply_custom_meta_mappings( $post_id, $item, $mappings ) {
	if ( empty( $mappings ) || ! is_array( $mappings ) ) {
		return;
	}

	$post_id = absint( $post_id );
	if ( 0 === $post_id ) {
		return;
	}

	$twig = tporapdi_get_twig_environment();
	if ( is_wp_error( $twig ) ) {
		return;
	}

	$loader = $twig->getLoader();
	if ( ! $loader instanceof \Twig\Loader\ArrayLoader ) {
		return;
	}

	foreach ( $mappings as $mapping ) {
		if ( ! is_array( $mapping ) ) {
			continue;
		}

		$meta_key  = isset( $mapping['key'] ) ? sanitize_text_field( $mapping['key'] ) : '';
		$raw_value = isset( $mapping['value'] ) ? (string) $mapping['value'] : '';

		if ( '' === $meta_key ) {
			continue;
		}

		$template_name = 'custom-meta-' . md5( $meta_key . $raw_value );

		try {
			$loader->setTemplate( $template_name, $raw_value );

			$compiled_value = (string) $twig->render(
				$template_name,
				array(
					'record' => $item,
					'item'   => $item,
					'data'   => $item,
				)
			);
		} catch ( \Twig\Error\Error $error ) {
			do_action(
				'tporapdi_custom_meta_mapping_error',
				$post_id,
				sanitize_text_field( $error->getMessage() )
			);
			continue;
		}

		if ( 'true' === $compiled_value ) {
			$compiled_value = true;
		} elseif ( 'false' === $compiled_value ) {
			$compiled_value = false;
		} elseif ( is_string( $compiled_value ) ) {
			$compiled_value = sanitize_text_field( $compiled_value );
		}

		update_post_meta( $post_id, $meta_key, $compiled_value );
	}
}

/**
 * Save API item data and metadata manifest for imported posts.
 *
 * Stores the raw API item payload as a single JSON meta value and tracks
 * which fields contain array/object values. Writes `_tporapdi_raw_record`,
 * `_tporapdi_import_job_id`, `_tporapdi_manifest_array_keys`, and `_tporapdi_field_schema`.
 *
 * @param int                  $post_id   The WordPress post ID.
 * @param array<string, mixed> $item      The raw API record.
 * @param int                  $import_id The import job ID.
 */
function tporapdi_save_item_meta_with_manifest( $post_id, $item, $import_id ) {
	if ( ! is_array( $item ) || empty( $item ) ) {
		return;
	}

	$post_id   = absint( $post_id );
	$import_id = absint( $import_id );

	if ( 0 === $post_id ) {
		return;
	}

	$payload             = array();
	$tporapdi_array_keys = array();
	$array_key_map       = array();

	foreach ( $item as $key => $value ) {
		if ( ! is_string( $key ) || '' === $key ) {
			continue;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$meta_key                   = '_tporapdi_' . sanitize_key( $key );
			$tporapdi_array_keys[]      = $meta_key;
			$array_key_map[ $meta_key ] = $key;
			$payload[ $key ]            = $value;
		} else {
			$payload[ $key ] = sanitize_text_field( (string) $value );
		}
	}

	$raw_record = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $raw_record ) {
		$raw_record = '';
	}

	update_post_meta( $post_id, '_tporapdi_raw_record', $raw_record );
	update_post_meta( $post_id, '_tporapdi_import_job_id', $import_id );
	update_post_meta( $post_id, '_tporapdi_manifest_array_keys', array_unique( $tporapdi_array_keys ) );

	static $schema_cache = array();

	if ( ! empty( $array_key_map ) && ! isset( $schema_cache[ $import_id ] ) ) {
		$schema_cache[ $import_id ] = tporapdi_build_field_schema_for_item( $item, $array_key_map );
	}

	$field_schema = isset( $schema_cache[ $import_id ] ) ? $schema_cache[ $import_id ] : array();

	if ( ! empty( $field_schema ) ) {
		update_post_meta( $post_id, '_tporapdi_field_schema', $field_schema );
	}
}

/**
 * Builds a field schema describing each array-type dataset in an API item.
 *
 * For every meta key in `$tporapdi_array_keys`, samples the first child record
 * and infers type/role/label for each scalar field. The result is a schema
 * the Block Designer companion plugin can use for binding dropdowns,
 * auto-mapping, and dataset selectors.
 *
 * @param array<string, mixed>  $item          Raw API record.
 * @param array<string, string> $array_key_map Mapping of meta key to original API key.
 *
 * @return array<string, array<string, mixed>> Schema keyed by meta key.
 */
function tporapdi_build_field_schema_for_item( $item, $array_key_map ) {
	$schema = array();

	foreach ( $array_key_map as $meta_key => $original_key ) {
		if ( ! isset( $item[ $original_key ] ) || ! is_array( $item[ $original_key ] ) ) {
			continue;
		}

		$array_value   = $item[ $original_key ];
		$dataset_label = tporapdi_humanize_field_name( $original_key );

		// Find a representative sample record.
		$sample = null;

		if ( tporapdi_array_is_list( $array_value ) ) {
			foreach ( $array_value as $candidate ) {
				if ( is_array( $candidate ) && ! empty( $candidate ) ) {
					$sample = $candidate;
					break;
				}
			}
		} else {
			$sample = $array_value;
		}

		if ( ! is_array( $sample ) || empty( $sample ) ) {
			continue;
		}

		$fields = array();

		foreach ( $sample as $field_key => $field_value ) {
			if ( ! is_string( $field_key ) || '' === $field_key ) {
				continue;
			}

			// Skip nested arrays/objects; they would be their own dataset.
			if ( is_array( $field_value ) || is_object( $field_value ) ) {
				continue;
			}

			$type = tporapdi_infer_field_type( $field_value );
			$role = tporapdi_infer_field_role( $field_key, $type );

			$fields[ $field_key ] = array(
				'label' => tporapdi_humanize_field_name( $field_key ),
				'type'  => $type,
				'role'  => $role,
			);
		}

		if ( ! empty( $fields ) ) {
			$schema[ $meta_key ] = array(
				'label'  => $dataset_label,
				'fields' => $fields,
			);
		}
	}

	return $schema;
}

/**
 * Infers a field type from a sample value.
 *
 * @param mixed $value Sample field value.
 *
 * @return string One of: string, number, date, url, html, boolean.
 */
function tporapdi_infer_field_type( $value ) {
	if ( is_bool( $value ) ) {
		return 'boolean';
	}

	if ( is_int( $value ) || is_float( $value ) ) {
		return 'number';
	}

	if ( ! is_string( $value ) ) {
		return 'string';
	}

	$trimmed = trim( $value );

	if ( '' === $trimmed ) {
		return 'string';
	}

	if ( is_numeric( $trimmed ) ) {
		return 'number';
	}

	if ( in_array( strtolower( $trimmed ), array( 'true', 'false' ), true ) ) {
		return 'boolean';
	}

	if ( false !== filter_var( $trimmed, FILTER_VALIDATE_URL ) ) {
		return 'url';
	}

	if ( wp_strip_all_tags( $trimmed ) !== $trimmed ) {
		return 'html';
	}

	if ( 1 === preg_match( '/\d{4}[\-\/]\d{1,2}[\-\/]\d{1,2}/', $trimmed ) && false !== strtotime( $trimmed ) ) {
		return 'date';
	}

	return 'string';
}

/**
 * Infers a semantic role from a field name and its detected type.
 *
 * Roles let the Block Designer auto-wire fields to block bindings:
 * title → heading, date → paragraph, url → link href, image → image src, etc.
 *
 * @param string $field_name Original API field name.
 * @param string $field_type Inferred field type from tporapdi_infer_field_type().
 *
 * @return string|null Semantic role or null when no role can be inferred.
 */
function tporapdi_infer_field_role( $field_name, $field_type ) {
	$lower = strtolower( $field_name );

	if ( 'id' === $lower || 1 === preg_match( '/(^|[_\-])id$|(?<=[a-z])Id$/i', $field_name ) ) {
		return 'id';
	}

	if ( 1 === preg_match( '/title|(?<![a-z])name(?![a-z])|heading/i', $field_name ) ) {
		return 'title';
	}

	if ( 1 === preg_match( '/desc|summary|body|content|abstract|excerpt/i', $field_name ) ) {
		return 'description';
	}

	if ( 1 === preg_match( '/image|photo|thumbnail|avatar|logo|picture|icon/i', $field_name ) ) {
		return 'image';
	}

	if ( 'date' === $field_type || 1 === preg_match( '/date|(?<![a-z])time(?![a-z])|created|updated|published|started|ended/i', $field_name ) ) {
		return 'date';
	}

	if ( 'url' === $field_type || 1 === preg_match( '/url|link|href|deeplink|permalink/i', $field_name ) ) {
		return 'url';
	}

	if ( 1 === preg_match( '/location|address|city|state|zip|postal|country|latitude|longitude/i', $field_name ) ) {
		return 'location';
	}

	return null;
}

/**
 * Converts an API field name into a human-readable label.
 *
 * Handles camelCase, snake_case, and kebab-case conventions.
 *
 * @param string $field_name Original API field name.
 *
 * @return string Human-readable label.
 */
function tporapdi_humanize_field_name( $field_name ) {
	$label = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $field_name );
	$label = is_string( $label ) ? $label : $field_name;
	$label = str_replace( array( '_', '-' ), ' ', $label );

	return ucwords( trim( $label ) );
}

/**
 * Resolves a value from an item using dot-notation.
 *
 * @param array<string, mixed> $item Item payload.
 * @param string               $path Dot-notation key path.
 *
 * @return mixed|null
 */
function tporapdi_get_item_value_by_path( $item, $path ) {
	$segments = array_filter(
		explode( '.', $path ),
		static function ( $segment ) {
			return '' !== $segment;
		}
	);

	$current = $item;

	foreach ( $segments as $segment ) {
		if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
			$current = $current[ $segment ];
			continue;
		}

		if ( is_array( $current ) && ctype_digit( $segment ) && array_key_exists( (int) $segment, $current ) ) {
			$current = $current[ (int) $segment ];
			continue;
		}

		return null;
	}

	return $current;
}

/**
 * Retrieves existing imported post IDs keyed by external record IDs.
 *
 * @param array<string> $external_ids External identifiers from the payload.
 * @param int           $import_id    Import job ID.
 *
 * @return array<string,int>
 */
function tporapdi_get_existing_imported_post_ids_by_external_ids( array $external_ids, $import_id ) {
	global $wpdb;

	$import_id    = absint( $import_id );
	$external_ids = array_values( array_filter( array_map( 'strval', $external_ids ), 'strlen' ) );

	if ( $import_id <= 0 || empty( $external_ids ) ) {
		return array();
	}

	$placeholders = implode( ', ', array_fill( 0, count( $external_ids ), '%s' ) );
	$query        = "
		SELECT pm1.meta_value AS external_id, pm1.post_id
		FROM %i pm1
		INNER JOIN %i pm2 ON pm1.post_id = pm2.post_id
		WHERE pm1.meta_key = %s
			AND pm1.meta_value IN ( {$placeholders} )
			AND pm2.meta_key = %s
			AND pm2.meta_value = %d
	";
	$query_args   = array_merge(
		array(
			$wpdb->postmeta,
			$wpdb->postmeta,
			'_my_custom_api_id',
		),
		$external_ids,
		array(
			'_tporapdi_import_id',
			$import_id,
		)
	);
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query text is assembled from fixed placeholders and prepared values only.
	$prepared_query = $wpdb->prepare( $query, $query_args );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query text above is prepared before execution.
	$rows = $wpdb->get_results( $prepared_query, ARRAY_A );
	if ( ! is_array( $rows ) ) {
		return array();
	}

	$map = array();
	foreach ( $rows as $row ) {
		if ( isset( $row['external_id'] ) && '' !== (string) $row['external_id'] ) {
			$map[ (string) $row['external_id'] ] = (int) $row['post_id'];
		}
	}

	return $map;
}

/**
 * Assigns a featured image from a configured item field path.
 *
 * Default path is image.url and can be overridden with
 * the tporapdi_featured_image_source_path filter.
 *
 * @param array<string, mixed> $item        Item payload.
 * @param int                  $post_id     Target post ID.
 * @param int                  $import_id   Import job ID.
 * @param string               $source_path Dot-notation path for image URL.
 *
 * @return bool True when thumbnail changed, otherwise false.
 */
function tporapdi_assign_featured_image_from_item( $item, $post_id, $import_id = 0, $source_path = '' ) {
	if ( ! is_array( $item ) ) {
		return false;
	}

	$post_id   = absint( $post_id );
	$import_id = absint( $import_id );

	if ( $post_id <= 0 ) {
		return false;
	}

	$source_path = trim( (string) $source_path );
	$source_path = (string) apply_filters(
		'tporapdi_featured_image_source_path',
		'' !== $source_path ? $source_path : 'image.url',
		$item,
		$post_id,
		$import_id
	);
	$source_path = trim( $source_path );

	if ( '' === $source_path ) {
		return false;
	}

	$featured_image_url = tporapdi_get_item_value_by_path( $item, $source_path );

	if ( ! is_scalar( $featured_image_url ) ) {
		return false;
	}

	$featured_image_url = trim( (string) $featured_image_url );

	if ( '' === $featured_image_url || ! wp_http_validate_url( $featured_image_url ) ) {
		return false;
	}

	if ( is_wp_error( tporapdi_validate_remote_endpoint_url( $featured_image_url ) ) ) {
		return false;
	}

	$current_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
	$attachment_id        = TPORAPDI_Import_Processor::sideload_image( $featured_image_url, $post_id, true );

	if ( false === $attachment_id ) {
		return false;
	}

	return $current_thumbnail_id !== (int) $attachment_id;
}

/**
 * Initializes the Twig templating environment for import transforms.
 *
 * Uses ArrayLoader because templates are saved in the database and rendered from strings.
 * Auto-escaping is disabled so mapped HTML remains intact in post_content.
 *
 * @return Twig\Environment|WP_Error
 */
function tporapdi_get_twig_environment() {
	static $twig = null;

	if ( $twig instanceof \Twig\Environment ) {
		return $twig;
	}

	if ( ! class_exists( '\\Twig\\Environment' ) || ! class_exists( '\\Twig\\Loader\\ArrayLoader' ) ) {
		return new WP_Error(
			'tporapdi_twig_missing_dependency',
			__( 'Twig is not installed. Run Composer install for twig/twig.', 'tporret-api-data-importer' )
		);
	}

	$strict_variables = (bool) apply_filters( 'tporapdi_twig_strict_variables', false );

	$loader = new \Twig\Loader\ArrayLoader( array() );
	$twig   = new \Twig\Environment(
		$loader,
		array(
			'autoescape'       => false,
			'strict_variables' => $strict_variables,
			'cache'            => false,
		)
	);

	// Add custom Twig tests here (for example, domain-specific validation checks).
	$twig->addTest(
		new \Twig\TwigTest(
			'numeric',
			static function ( $value ) {
				return is_numeric( $value );
			}
		)
	);

	// Add custom Twig filters here (for example, formatting helpers used by mapping templates).
	$twig->addFilter(
		new \Twig\TwigFilter(
			'format_us_currency',
			static function ( $value ) {
				if ( ! is_numeric( $value ) ) {
					return (string) $value;
				}

				return '$' . number_format( (float) $value, 2, '.', ',' );
			}
		)
	);

	$twig->addFilter(
		new \Twig\TwigFilter(
			'format_date_mdy',
			static function ( $value ) {
				$date_value = trim( (string) $value );
				if ( '' === $date_value ) {
					return '';
				}

				$timestamp = strtotime( $date_value );
				if ( false === $timestamp ) {
					return $date_value;
				}

				return gmdate( 'm/d/Y', (int) $timestamp );
			}
		)
	);

	return $twig;
}

/**
 * Applies wp_kses to a Twig mapping template while preserving Twig syntax blocks.
 *
 * The wp_kses function treats bare `>` and `<` characters as HTML and encodes them to `&gt;`/`&lt;`,
 * which corrupts Twig comparison operators (e.g. `{% if x > 0 %}`). This function
 * temporarily replaces Twig blocks ({{ }}, {% %}, {# #}) with safe placeholders before
 * calling wp_kses and restores them afterwards.
 *
 * @param string               $template     Twig template source.
 * @param array<string, mixed> $allowed_html wp_kses allowed HTML array.
 * @return string Sanitized template with Twig blocks intact.
 */
function tporapdi_kses_mapping_template( $template, $allowed_html ) {
	$template   = (string) $template;
	$twig_blocks = array();

	$template = preg_replace_callback(
		'/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s',
		static function ( $matches ) use ( &$twig_blocks ) {
			$key               = 'TWIGEAIBLOCK' . count( $twig_blocks ) . 'X';
			$twig_blocks[ $key ] = $matches[0];
			return $key;
		},
		$template
	);

	$template = wp_kses( (string) $template, $allowed_html );

	foreach ( $twig_blocks as $key => $block ) {
		$template = str_replace( $key, $block, $template );
	}

	return $template;
}

/**
 * Validates template size, complexity, and Twig syntax safety.
 *
 * @param string $template Template string.
 * @param string $type     Template type (title|mapping).
 * @return true|WP_Error
 */
function tporapdi_validate_twig_template_security( $template, $type = 'mapping' ) {
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

	$disallowed_tag_pattern = '/\{\%\s*(include|source|import|from|embed|extends|use|macro)\b/i';
	if ( 1 === preg_match( $disallowed_tag_pattern, $template ) ) {
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

	$twig = tporapdi_get_twig_environment();
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

/**
 * Renders mapping template content for a single item using Twig.
 *
 * @param array<string, mixed> $item             Item payload.
 * @param string|null          $mapping_template Optional template override.
 *
 * @return string|WP_Error
 */
function tporapdi_render_mapping_template_for_item( $item, $mapping_template = null ) {
	if ( ! is_array( $item ) ) {
		return new WP_Error( 'tporapdi_invalid_item', __( 'Transform input must be an array.', 'tporret-api-data-importer' ) );
	}

	$mapping_template = null === $mapping_template ? '' : (string) $mapping_template;

	if ( '' === (string) $mapping_template ) {
		return new WP_Error( 'tporapdi_missing_mapping_template', __( 'Mapping Template is not configured.', 'tporret-api-data-importer' ) );
	}

	$template_security = tporapdi_validate_twig_template_security( (string) $mapping_template, 'mapping' );
	if ( is_wp_error( $template_security ) ) {
		return $template_security;
	}

	$twig = tporapdi_get_twig_environment();

	if ( is_wp_error( $twig ) ) {
		return $twig;
	}

	$loader = $twig->getLoader();

	if ( ! $loader instanceof \Twig\Loader\ArrayLoader ) {
		return new WP_Error( 'tporapdi_twig_loader_invalid', __( 'Twig loader is not configured for string templates.', 'tporret-api-data-importer' ) );
	}

	try {
		$template_name = 'tporapdi_import_template';
		$loader->setTemplate( $template_name, (string) $mapping_template );

		$post_content = $twig->render(
			$template_name,
			array(
				'record' => $item,
				'item'   => $item,
				'data'   => $item,
			)
		);
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

	return (string) $post_content;
}

/**
 * Formats a placeholder value for safe template rendering.
 *
 * @param mixed $value      Value to render.
 * @param bool  $allow_html Whether to allow safe HTML tags.
 *
 * @return string
 */
function tporapdi_prepare_template_value( $value, $allow_html = false ) {
	if ( is_scalar( $value ) ) {
		$string_value = (string) $value;
		return $allow_html ? wp_kses_post( $string_value ) : esc_html( $string_value );
	}

	$json_value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $json_value ) {
		return '';
	}

	return $allow_html ? wp_kses_post( $json_value ) : esc_html( $json_value );
}

/**
 * Writes an import run record to the custom log table.
 *
 * @param int                  $import_id     Import job ID.
 * @param string               $import_run_id Run identifier.
 * @param string               $status        Final run status.
 * @param int                  $rows_processed Total processed rows.
 * @param int                  $rows_created  Total created posts.
 * @param int                  $rows_updated  Total updated posts.
 * @param array<string, mixed> $details       Additional run metadata.
 * @param string               $created_at    Log creation timestamp (mysql format, UTC).
 *
 * @return bool
 */
function tporapdi_write_import_log( $import_id, $import_run_id, $status, $rows_processed, $rows_created, $rows_updated, $details, $created_at ) {
	$details_js = wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $details_js ) {
		$details_js = wp_json_encode( array( 'error' => 'Failed to encode run details.' ) );
	}

	return tporapdi_db_insert_import_log(
		(int) $import_id,
		(string) $import_run_id,
		(string) $status,
		(int) $rows_processed,
		(int) $rows_created,
		(int) $rows_updated,
		(string) $details_js,
		(string) $created_at
	);
}

/**
 * Returns true when there are unprocessed staging rows.
 *
 * @param int $import_id Import job ID.
 *
 * @return bool
 */
function tporapdi_has_unprocessed_staging_rows( $import_id ) {
	$count = tporapdi_db_count_unprocessed_staging_rows( absint( $import_id ) );

	return $count > 0;
}

/**
 * Schedules one import worker event.
 *
 * @param int|null $delay_seconds Delay before scheduling. If null, uses settings.
 * @param bool     $is_initial    Whether this is the first schedule for a run.
 *
 * @return bool
 */
function tporapdi_schedule_import_batch_event( $delay_seconds = null, $is_initial = false ) {
	$options = wp_parse_args( get_option( 'tporapdi_settings', array() ), tporapdi_get_default_settings() );

	if ( null === $delay_seconds ) {
		$delay_seconds = $is_initial
			? (int) $options['cron_initial_delay_seconds']
			: (int) $options['cron_batch_delay_seconds'];
	}

	$delay_seconds = max( 0, (int) $delay_seconds );

	if ( wp_next_scheduled( 'tporapdi_process_import_queue' ) ) {
		return true;
	}

	return (bool) wp_schedule_single_event( time() + $delay_seconds, 'tporapdi_process_import_queue' );
}

/**
 * Returns the active import run state.
 *
 * @return array<string, mixed>
 */
function tporapdi_get_active_run_state() {
	$state = get_option( 'tporapdi_active_import_run', array() );

	if ( ! is_array( $state ) ) {
		$state = array();
	}

	return $state;
}

/**
 * Saves the active import run state.
 *
 * @param array<string, mixed> $state Run state.
 *
 * @return void
 */
function tporapdi_set_active_run_state( $state ) {
	update_option( 'tporapdi_active_import_run', $state, false );
}

/**
 * Clears the active import run state.
 * , 10
 *
 * @return void
 */
function tporapdi_clear_active_run_state() {
	delete_option( 'tporapdi_active_import_run' );
}

/**
 * Processes unprocessed staging rows for up to the given runtime limit.
 *
 * Exits gracefully when runtime is exhausted and leaves unfinished rows as
 * unprocessed so a future worker can continue.
 *
 * @param float $started_at_microtime Start timestamp from microtime(true).
 * @param int   $import_id            Import job ID.
 * @param int   $max_runtime_seconds  Maximum allowed runtime.
 *
 * @return array<string, mixed>|WP_Error
 */
function tporapdi_process_unprocessed_staging_rows( $started_at_microtime, $import_id, $max_runtime_seconds = 45 ) {
	$import_id   = absint( $import_id );
	$staged_rows = tporapdi_db_get_unprocessed_staging_rows( $import_id, 10 );

	if ( ! is_array( $staged_rows ) ) {
		return new WP_Error( 'tporapdi_staging_query_failed', __( 'Failed to query unprocessed staging rows.', 'tporret-api-data-importer' ) );
	}

	$result = array(
		'temp_rows_found'     => count( $staged_rows ),
		'temp_rows_processed' => 0,
		'rows_processed'      => 0,
		'rows_created'        => 0,
		'rows_updated'        => 0,
		'errors'              => array(),
		'timed_out'           => false,
		'has_remaining'       => false,
	);

	if ( empty( $staged_rows ) ) {
		return $result;
	}

	foreach ( $staged_rows as $staged_row ) {
		if ( ( microtime( true ) - $started_at_microtime ) >= $max_runtime_seconds ) {
			$result['timed_out']     = true;
			$result['has_remaining'] = true;
			break;
		}

		$row_id        = isset( $staged_row['id'] ) ? (int) $staged_row['id'] : 0;
		$row_import_id = isset( $staged_row['import_id'] ) ? (int) $staged_row['import_id'] : 0;

		if ( $row_id <= 0 ) {
			$result['errors'][] = __( 'Encountered an invalid staging row identifier.', 'tporret-api-data-importer' );
			continue;
		}

		$import_job = tporapdi_db_get_import_config( $row_import_id );

		if ( ! is_array( $import_job ) ) {
			$result['errors'][] = sprintf(
				/* translators: %d is import job ID. */
				__( 'Import job %d could not be loaded for processing.', 'tporret-api-data-importer' ),
				$row_import_id
			);
			continue;
		}

		$decoded_items = json_decode( (string) $staged_row['raw_json'], true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$result['errors'][] = sprintf(
				/* translators: 1: staging row ID, 2: JSON error message. */
				__( 'Staging row %1$d has invalid JSON: %2$s', 'tporret-api-data-importer' ),
				$row_id,
				json_last_error_msg()
			);
			continue;
		}

		if ( ! is_array( $decoded_items ) ) {
			$result['errors'][] = sprintf(
				/* translators: %d is the staging row ID. */
				__( 'Staging row %d does not contain an array payload.', 'tporret-api-data-importer' ),
				$row_id
			);
			continue;
		}

		$items                      = tporapdi_normalize_staged_items( $decoded_items );
		$mapping_template           = (string) $import_job['mapping_template'];
		$title_template             = isset( $import_job['title_template'] ) ? (string) $import_job['title_template'] : '';
		$excerpt_template           = isset( $import_job['excerpt_template'] ) ? (string) $import_job['excerpt_template'] : '';
		$post_name_template         = isset( $import_job['post_name_template'] ) ? (string) $import_job['post_name_template'] : '';
		$target_post_type           = isset( $import_job['target_post_type'] ) ? (string) $import_job['target_post_type'] : 'post';
		$unique_id_path             = isset( $import_job['unique_id_path'] ) ? trim( (string) $import_job['unique_id_path'] ) : 'id';
		$featured_image_source_path = isset( $import_job['featured_image_source_path'] ) ? trim( (string) $import_job['featured_image_source_path'] ) : 'image.url';
		$post_author                = isset( $import_job['post_author'] ) ? absint( $import_job['post_author'] ) : 0;
		$post_status                = isset( $import_job['post_status'] ) ? (string) $import_job['post_status'] : 'draft';
		$comment_status             = isset( $import_job['comment_status'] ) ? (string) $import_job['comment_status'] : 'closed';
		$ping_status                = isset( $import_job['ping_status'] ) ? (string) $import_job['ping_status'] : 'closed';
		$custom_meta_mappings       = array();
		if ( ! empty( $import_job['custom_meta_mappings'] ) ) {
			$decoded_meta_mappings = is_string( $import_job['custom_meta_mappings'] )
				? json_decode( $import_job['custom_meta_mappings'], true )
				: $import_job['custom_meta_mappings'];
			if ( is_array( $decoded_meta_mappings ) ) {
				$custom_meta_mappings = $decoded_meta_mappings;
			}
		}
		$chunks        = array_chunk( $items, 50 );
		$row_completed = true;

		if ( '' === $unique_id_path ) {
			$unique_id_path = 'id';
		}

		if ( '' === $featured_image_source_path ) {
			$featured_image_source_path = 'image.url';
		}

		foreach ( $chunks as $chunk_items ) {
			$chunk_external_ids = array();
			foreach ( $chunk_items as $chunk_item ) {
				$chunk_external_id = tporapdi_get_item_value_by_path( $chunk_item, $unique_id_path );
				if ( is_scalar( $chunk_external_id ) ) {
					$chunk_external_ids[] = trim( (string) $chunk_external_id );
				}
			}

			$existing_post_ids = tporapdi_get_existing_imported_post_ids_by_external_ids( $chunk_external_ids, $row_import_id );

			foreach ( $chunk_items as $item ) {
				if ( ( microtime( true ) - $started_at_microtime ) >= $max_runtime_seconds ) {
					$result['timed_out']     = true;
					$result['has_remaining'] = true;
					$row_completed           = false;
					break 2;
				}

				++$result['rows_processed'];

				$item_result = tporapdi_transform_and_load_item(
					$item,
					$mapping_template,
					$title_template,
					$target_post_type,
					$unique_id_path,
					$row_import_id,
					$featured_image_source_path,
					$post_author,
					$post_status,
					$comment_status,
					$ping_status,
					$custom_meta_mappings,
					$existing_post_ids,
					$excerpt_template,
					$post_name_template
				);

				if ( is_wp_error( $item_result ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: staging row ID, 2: error message. */
						__( 'Row %1$d item failed: %2$s', 'tporret-api-data-importer' ),
						$row_id,
						$item_result->get_error_message()
					);
					continue;
				}

				if ( isset( $item_result['action'] ) && 'inserted' === $item_result['action'] ) {
					++$result['rows_created'];
				} elseif ( isset( $item_result['action'] ) && 'updated' === $item_result['action'] ) {
					++$result['rows_updated'];
				}
			}

			unset( $chunk_items );
		}

		if ( $row_completed ) {
			$marked_processed = tporapdi_db_mark_staging_row_processed( $row_id );

			if ( ! $marked_processed ) {
				$result['errors'][] = sprintf(
					/* translators: %d is the staging row ID. */
					__( 'Failed to mark staging row %d as processed.', 'tporret-api-data-importer' ),
					$row_id
				);
				$result['has_remaining'] = true;
			} else {
				++$result['temp_rows_processed'];
			}
		}

		unset( $items, $chunks, $decoded_items );

		if ( $result['timed_out'] ) {
			break;
		}
	}

	if ( ! $result['has_remaining'] ) {
		$result['has_remaining'] = tporapdi_has_unprocessed_staging_rows( $import_id );
	}

	return $result;
}

/**
 * Handles one scheduled import batch event.
 *
 * @return void
 */
function tporapdi_handle_scheduled_import_batch() {
	$state = tporapdi_get_active_run_state();

	if ( empty( $state ) || empty( $state['import_id'] ) ) {
		return;
	}

	$import_id = absint( $state['import_id'] );

	$state['slices'] = isset( $state['slices'] ) ? ( (int) $state['slices'] + 1 ) : 1;

	$processing_result = tporapdi_process_unprocessed_staging_rows( microtime( true ), $import_id, 45 );

	if ( is_wp_error( $processing_result ) ) {
		$end_time = gmdate( 'Y-m-d H:i:s', time() );
		$details  = array(
			'start_time'          => $state['start_time'],
			'end_time'            => $end_time,
			'orphans_trashed'     => 0,
			'temp_rows_found'     => (int) $state['temp_rows_found'],
			'temp_rows_processed' => (int) $state['temp_rows_processed'],
			'slices'              => (int) $state['slices'],
			'trigger_source'      => isset( $state['trigger_source'] ) ? sanitize_key( (string) $state['trigger_source'] ) : 'unknown',
			'processing_errors'   => array( $processing_result->get_error_message() ),
		);

		tporapdi_write_import_log(
			$import_id,
			(string) $state['run_id'],
			'failed',
			(int) $state['rows_processed'],
			(int) $state['rows_created'],
			(int) $state['rows_updated'],
			$details,
			(string) $state['start_time']
		);

		tporapdi_clear_active_run_state();
		return;
	}

	$state['rows_processed']      = (int) $state['rows_processed'] + (int) $processing_result['rows_processed'];
	$state['rows_created']        = (int) $state['rows_created'] + (int) $processing_result['rows_created'];
	$state['rows_updated']        = (int) $state['rows_updated'] + (int) $processing_result['rows_updated'];
	$state['temp_rows_found']     = (int) $state['temp_rows_found'] + (int) $processing_result['temp_rows_found'];
	$state['temp_rows_processed'] = (int) $state['temp_rows_processed'] + (int) $processing_result['temp_rows_processed'];

	if ( ! empty( $processing_result['errors'] ) && is_array( $processing_result['errors'] ) ) {
		$state['errors'] = array_merge( $state['errors'], $processing_result['errors'] );
	}

	if ( ! empty( $processing_result['has_remaining'] ) ) {
		tporapdi_set_active_run_state( $state );
		tporapdi_schedule_import_batch_event( null, false );
		return;
	}

	$orphans_trashed = tporapdi_trash_orphaned_imported_posts( (int) $state['start_timestamp'], $import_id );
	if ( is_wp_error( $orphans_trashed ) ) {
		$state['errors'][] = $orphans_trashed->get_error_message();
		$orphans_trashed   = 0;
	}

	$end_time = gmdate( 'Y-m-d H:i:s', time() );

	if ( 0 === (int) $state['rows_processed'] && 0 === (int) $state['temp_rows_found'] ) {
		$status = 'no_data';
	} elseif ( empty( $state['errors'] ) ) {
		$status = 'success';
	} else {
		$status = 'completed_with_errors';
	}

	$details = array(
		'start_time'          => $state['start_time'],
		'end_time'            => $end_time,
		'orphans_trashed'     => (int) $orphans_trashed,
		'temp_rows_found'     => (int) $state['temp_rows_found'],
		'temp_rows_processed' => (int) $state['temp_rows_processed'],
		'slices'              => (int) $state['slices'],
		'trigger_source'      => isset( $state['trigger_source'] ) ? sanitize_key( (string) $state['trigger_source'] ) : 'unknown',
		'processing_errors'   => $state['errors'],
	);

	tporapdi_write_import_log(
		$import_id,
		(string) $state['run_id'],
		$status,
		(int) $state['rows_processed'],
		(int) $state['rows_created'],
		(int) $state['rows_updated'],
		$details,
		(string) $state['start_time']
	);

	tporapdi_clear_active_run_state();
}

/**
 * Normalizes staged payload into a list of item arrays.
 *
 * @param array<mixed> $decoded_items Decoded staged payload.
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_normalize_staged_items( $decoded_items ) {
	$items = array();

	if ( tporapdi_array_is_list( $decoded_items ) ) {
		foreach ( $decoded_items as $entry ) {
			if ( is_array( $entry ) ) {
				$items[] = $entry;
			}
		}

		return $items;
	}

	if ( ! empty( $decoded_items ) ) {
		$items[] = $decoded_items;
	}

	return $items;
}

/**
 * Backport helper for list-array detection.
 *
 * @param array<mixed> $input_array Input array.
 *
 * @return bool
 */
function tporapdi_array_is_list( $input_array ) {
	$index = 0;

	foreach ( $input_array as $key => $unused_value ) {
		if ( $key !== $index ) {
			return false;
		}

		++$index;
	}

	return true;
}

/**
 * Finds and trashes orphaned imported items whose sync timestamp is stale.
 *
 * @param int $run_started_unix Unix timestamp when the run started.
 * @param int $import_id        Import job ID.
 *
 * @return int|WP_Error
 */
function tporapdi_trash_orphaned_imported_posts( $run_started_unix, $import_id ) {
	global $wpdb;

	$posts_table    = $wpdb->posts;
	$postmeta_table = $wpdb->postmeta;
	$import_id      = absint( $import_id );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$orphan_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM %i p
			LEFT JOIN %i pm
				ON p.ID = pm.post_id
				AND pm.meta_key = %s
			INNER JOIN %i pim
				ON p.ID = pim.post_id
				AND pim.meta_key = %s
				AND CAST(pim.meta_value AS UNSIGNED) = %d
			WHERE p.post_type = %s
				AND p.post_status NOT IN ('trash', 'auto-draft')
				AND (
					pm.meta_value IS NULL
					OR CAST(pm.meta_value AS UNSIGNED) < %d
				)",
			$posts_table,
			$postmeta_table,
			'_last_synced_timestamp',
			$postmeta_table,
			'_tporapdi_import_id',
			$import_id,
			'tporapdi_item',
			$run_started_unix
		)
	);

	if ( ! is_array( $orphan_ids ) ) {
		return new WP_Error( 'tporapdi_orphan_query_failed', __( 'Failed to query orphaned imported items.', 'tporret-api-data-importer' ) );
	}

	$trashed_count = 0;

	foreach ( $orphan_ids as $post_id ) {
		$trashed = wp_trash_post( (int) $post_id );

		if ( false !== $trashed && null !== $trashed ) {
			++$trashed_count;
		}
	}

	return $trashed_count;
}

/**
 * Returns imported post IDs for a specific import job.
 *
 * @param int $import_id Import job ID.
 * @return int[]
 */
function tporapdi_get_imported_post_ids_for_job( $import_id ) {
	global $wpdb;

	$import_id      = absint( $import_id );
	$postmeta_table = $wpdb->postmeta;
	$posts_table    = $wpdb->posts;

	if ( $import_id <= 0 ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM %i pm
			INNER JOIN %i p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND CAST(pm.meta_value AS UNSIGNED) = %d
				AND p.post_type <> 'attachment'",
			$postmeta_table,
			$posts_table,
			'_tporapdi_import_id',
			$import_id
		)
	);

	if ( ! is_array( $post_ids ) ) {
		return array();
	}

	return array_map( 'absint', $post_ids );
}

/**
 * Deletes or trashes an owned featured image attachment for a post.
 *
 * Only attachments created by this import flow and parented to the current post
 * are eligible, which avoids deleting shared media reused across posts/jobs.
 *
 * @param int  $post_id    Post ID.
 * @param bool $permanent  Whether to permanently delete.
 * @return bool True when an attachment was removed or trashed.
 */
function tporapdi_cleanup_featured_image_for_post( $post_id, $permanent = false ) {
	$post_id       = absint( $post_id );
	$attachment_id = absint( get_post_thumbnail_id( $post_id ) );

	if ( $post_id <= 0 || $attachment_id <= 0 ) {
		return false;
	}

	$attachment = get_post( $attachment_id );
	if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
		return false;
	}

	$source_url = get_post_meta( $attachment_id, '_tporapdi_source_url', true );
	if ( ! is_string( $source_url ) || '' === $source_url ) {
		return false;
	}

	if ( (int) $attachment->post_parent !== $post_id ) {
		return false;
	}

	if ( $permanent ) {
		$deleted = wp_delete_attachment( $attachment_id, true );
		return false !== $deleted && null !== $deleted;
	}

	$trashed = wp_trash_post( $attachment_id );
	return false !== $trashed && null !== $trashed;
}

/**
 * Fresh-start cleanup for one import job.
 *
 * @param int    $import_id Import job ID.
 * @param string $mode      Cleanup mode: trash or delete.
 * @return array<string, int>|WP_Error
 */
function tporapdi_cleanup_import_job_content( $import_id, $mode = 'trash' ) {
	$import_id = absint( $import_id );
	$mode      = 'delete' === $mode ? 'delete' : 'trash';

	if ( $import_id <= 0 ) {
		return new WP_Error( 'tporapdi_invalid_import_id', __( 'Invalid import job ID.', 'tporret-api-data-importer' ) );
	}

	$post_ids = tporapdi_get_imported_post_ids_for_job( $import_id );
	$summary  = array(
		'posts_affected'       => 0,
		'featured_media_count' => 0,
		'staging_rows_cleared' => 0,
		'log_rows_cleared'     => 0,
	);

	foreach ( $post_ids as $post_id ) {
		if ( tporapdi_cleanup_featured_image_for_post( $post_id, 'delete' === $mode ) ) {
			++$summary['featured_media_count'];
		}

		if ( 'delete' === $mode ) {
			$deleted = wp_delete_post( $post_id, true );
			if ( false !== $deleted && null !== $deleted ) {
				++$summary['posts_affected'];
			}
			continue;
		}

		$trashed = wp_trash_post( $post_id );
		if ( false !== $trashed && null !== $trashed ) {
			++$summary['posts_affected'];
		}
	}

	$staging_deleted = tporapdi_db_delete_staging_rows_for_import( $import_id );
	$logs_deleted    = tporapdi_db_delete_log_rows_for_import( $import_id );

	if ( false === $staging_deleted || false === $logs_deleted ) {
		return new WP_Error( 'tporapdi_cleanup_db_failed', __( 'Failed to clear staging or log rows for the import job.', 'tporret-api-data-importer' ) );
	}

	$summary['staging_rows_cleared'] = (int) $staging_deleted;
	$summary['log_rows_cleared']     = (int) $logs_deleted;

	return $summary;
}
