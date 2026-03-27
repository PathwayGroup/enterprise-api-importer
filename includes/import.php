<?php
/**
 * Import pipeline and queue processing.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'eai_process_import_queue', 'eai_handle_scheduled_import_batch' );
add_action( 'ncsu_api_importer_batch_hook', 'eai_handle_import_batch_hook', 10, 2 );
add_action( 'eai_recurring_import_trigger', 'eai_handle_import_batch_hook', 10, 2 );
add_filter( 'cron_schedules', 'eai_register_custom_cron_schedules' );

/**
 * Registers custom recurrence schedules used by import jobs.
 *
 * @param array<string, array<string, mixed>> $schedules Existing schedules.
 *
 * @return array<string, array<string, mixed>>
 */
function eai_register_custom_cron_schedules( $schedules ) {
	$rows = eai_db_get_custom_recurrence_minutes();

	foreach ( $rows as $minutes_value ) {
		$minutes = max( 1, absint( $minutes_value ) );

		if ( $minutes <= 0 ) {
			continue;
		}

		$key = 'eai_every_' . $minutes . '_minutes';

		if ( isset( $schedules[ $key ] ) ) {
			continue;
		}

		$schedules[ $key ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d is number of minutes. */
				__( 'Every %d minutes (Enterprise API Importer)', 'enterprise-api-importer' ),
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
function eai_get_recurrence_schedule_slug( $recurrence, $custom_interval_minutes = 0 ) {
	$recurrence = sanitize_key( (string) $recurrence );

	if ( 'hourly' === $recurrence || 'twicedaily' === $recurrence || 'daily' === $recurrence ) {
		return $recurrence;
	}

	if ( 'custom' === $recurrence ) {
		$minutes = max( 1, absint( $custom_interval_minutes ) );

		if ( $minutes > 0 ) {
			return 'eai_every_' . $minutes . '_minutes';
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
function eai_clear_import_scheduled_hooks( $import_id ) {
	$import_id = absint( $import_id );

	if ( $import_id <= 0 ) {
		return;
	}

	wp_clear_scheduled_hook( 'eai_recurring_import_trigger', array( $import_id, 'recurring' ) );
	wp_clear_scheduled_hook( 'eai_recurring_import_trigger', array( $import_id ) );
	wp_clear_scheduled_hook( 'ncsu_api_importer_batch_hook', array( $import_id, 'run_now' ) );
	wp_clear_scheduled_hook( 'ncsu_api_importer_batch_hook', array( $import_id ) );
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
function eai_sync_import_recurrence_schedule( $import_id, $recurrence, $custom_interval_minutes = 0 ) {
	$import_id = absint( $import_id );

	if ( $import_id <= 0 ) {
		return false;
	}

	wp_clear_scheduled_hook( 'eai_recurring_import_trigger', array( $import_id, 'recurring' ) );
	wp_clear_scheduled_hook( 'eai_recurring_import_trigger', array( $import_id ) );

	$schedule_slug = eai_get_recurrence_schedule_slug( $recurrence, $custom_interval_minutes );

	if ( '' === $schedule_slug ) {
		return true;
	}

	$next_scheduled = wp_next_scheduled( 'eai_recurring_import_trigger', array( $import_id, 'recurring' ) );

	if ( false !== $next_scheduled ) {
		return true;
	}

	$start_timestamp = time() + MINUTE_IN_SECONDS;

	return false !== wp_schedule_event( $start_timestamp, $schedule_slug, 'eai_recurring_import_trigger', array( $import_id, 'recurring' ) );
}

/**
 * Handles import-specific cron trigger.
 *
 * @param int    $import_id       Import job ID.
 * @param string $trigger_source  Trigger source context.
 *
 * @return void
 */
function eai_handle_import_batch_hook( $import_id, $trigger_source = 'run_now' ) {
	$import_id       = absint( $import_id );
	$trigger_source  = sanitize_key( (string) $trigger_source );
	$allowed_sources = array( 'manual', 'run_now', 'recurring' );

	if ( ! in_array( $trigger_source, $allowed_sources, true ) ) {
		$trigger_source = 'run_now';
	}

	if ( $import_id <= 0 ) {
		return;
	}

	$active_state = eai_get_active_run_state();

	if ( ! empty( $active_state['run_id'] ) ) {
		if ( isset( $active_state['import_id'] ) && absint( $active_state['import_id'] ) === $import_id ) {
			eai_handle_scheduled_import_batch();
		}
		return;
	}

	$extract_result = eai_extract_and_stage_data( $import_id );

	if ( is_wp_error( $extract_result ) ) {
		$now = gmdate( 'Y-m-d H:i:s', time() );

		eai_write_import_log(
			$import_id,
			wp_generate_uuid4(),
			'failed',
			0,
			0,
			0,
			array(
				'start_time'        => $now,
				'end_time'          => $now,
				'orphans_trashed'   => 0,
				'temp_rows_found'   => 0,
				'temp_rows_processed' => 0,
				'slices'            => 0,
				'trigger_source'    => $trigger_source,
				'processing_errors' => array( $extract_result->get_error_message() ),
			),
			$now
		);

		return;
	}

	$started_unix = time();

	eai_set_active_run_state(
		array(
			'run_id'               => wp_generate_uuid4(),
			'import_id'            => $import_id,
			'trigger_source'       => $trigger_source,
			'start_timestamp'      => $started_unix,
			'start_time'           => gmdate( 'Y-m-d H:i:s', $started_unix ),
			'rows_processed'       => 0,
			'rows_created'         => 0,
			'rows_updated'         => 0,
			'temp_rows_found'      => 0,
			'temp_rows_processed'  => 0,
			'errors'               => array(),
			'slices'               => 0,
		)
	);

	eai_handle_scheduled_import_batch();
}

/**
 * Fetches API payload with optional transient caching.
 *
 * @param string $endpoint     Endpoint URL.
 * @param string $token        Bearer token, optional.
 * @param bool   $bypass_cache Whether to bypass cache and force a live call.
 *
 * @return array<string, mixed>|WP_Error
 */
function eai_fetch_api_payload( $endpoint, $token = '', $bypass_cache = false ) {
	$cache_key      = 'api_importer_cache_' . md5( $endpoint );
	$cached_payload = false;
	$used_cache     = false;

	if ( ! $bypass_cache ) {
		$cached_payload = get_transient( $cache_key );
	}

	if ( false === $cached_payload ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => $headers,
			)
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
				__( 'API request failed with status code %1$d (%2$s).', 'enterprise-api-importer' ),
				$status_code,
				'' !== $response_message ? $response_message : __( 'No response message', 'enterprise-api-importer' )
			);

			if ( '' !== $body_excerpt ) {
				$error_message .= ' ' . sprintf(
					/* translators: %s is a trimmed response body excerpt. */
					__( 'Response: %s', 'enterprise-api-importer' ),
					$body_excerpt
				);
			}

			return new WP_Error(
				'eai_api_http_error',
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
			return new WP_Error( 'eai_empty_response', __( 'API returned an empty response body.', 'enterprise-api-importer' ) );
		}

		set_transient( $cache_key, $cached_payload, 5 * MINUTE_IN_SECONDS );
	} else {
		$used_cache = true;
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
function eai_extract_and_stage_data( $import_id ) {
	$import_id     = absint( $import_id );
	$import_job    = eai_db_get_import_config( $import_id );

	if ( ! is_array( $import_job ) ) {
		return new WP_Error( 'eai_import_not_found', __( 'Import job could not be found.', 'enterprise-api-importer' ) );
	}

	$endpoint  = trim( (string) $import_job['endpoint_url'] );
	$token     = trim( (string) $import_job['auth_token'] );
	$json_path = trim( (string) $import_job['array_path'] );

	if ( '' === $endpoint ) {
		return new WP_Error( 'eai_missing_endpoint', __( 'API endpoint URL is not configured.', 'enterprise-api-importer' ) );
	}

	$fetched_payload = eai_fetch_api_payload( $endpoint, $token, false );

	if ( is_wp_error( $fetched_payload ) ) {
		return $fetched_payload;
	}

	$cached_payload = (string) $fetched_payload['body'];
	$used_cache     = ! empty( $fetched_payload['used_cache'] );

	$decoded_json = json_decode( (string) $cached_payload, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error(
			'eai_invalid_json',
			sprintf(
				/* translators: %s is a JSON parser error message. */
				__( 'Unable to decode API JSON payload: %s', 'enterprise-api-importer' ),
				json_last_error_msg()
			)
		);
	}

	$selected_array = eai_resolve_json_array_path( $decoded_json, $json_path );

	if ( is_wp_error( $selected_array ) ) {
		return $selected_array;
	}

	$filter_rules = eai_decode_filter_rules_json( isset( $import_job['filter_rules'] ) ? (string) $import_job['filter_rules'] : '' );
	if ( ! empty( $filter_rules ) ) {
		$selected_array = eai_apply_filter_rules_to_records( $selected_array, $filter_rules );
	}

	$serialized_selected_array = wp_json_encode( $selected_array, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $serialized_selected_array ) {
		return new WP_Error( 'eai_json_encode_failed', __( 'Failed to serialize extracted array for staging.', 'enterprise-api-importer' ) );
	}

	$insert_id = eai_db_insert_staging_payload( $import_id, $serialized_selected_array );
	if ( is_wp_error( $insert_id ) ) {
		return $insert_id;
	}

	$row_count = 0;
	if ( is_array( $selected_array ) ) {
		if ( eai_array_is_list( $selected_array ) ) {
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
function eai_decode_filter_rules_json( $filter_rules_json ) {
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
 * @param array<mixed>                           $selected_array Records selected from API payload.
 * @param array<int, array<string, string>>      $filter_rules   Sanitized filter rules.
 *
 * @return array<mixed>
 */
function eai_apply_filter_rules_to_records( $selected_array, $filter_rules ) {
	if ( ! is_array( $selected_array ) || empty( $filter_rules ) ) {
		return is_array( $selected_array ) ? $selected_array : array();
	}

	$is_list_array = eai_array_is_list( $selected_array );
	$records       = $is_list_array ? $selected_array : array( $selected_array );
	$filtered      = array();

	foreach ( $records as $record ) {
		if ( ! is_array( $record ) ) {
			continue;
		}

		$passes_all_rules = true;
		foreach ( $filter_rules as $filter_rule ) {
			$record_value = eai_get_item_value_by_path( $record, $filter_rule['key'] );
			if ( ! evaluate_filter_rule( $record_value, $filter_rule['operator'], $filter_rule['value'] ) ) {
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
function evaluate_filter_rule( $record_value, $operator, $filter_value ) {
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
function eai_resolve_json_array_path( $decoded_json, $path ) {
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
			'eai_json_path_not_found',
			sprintf(
				/* translators: %s is the configured JSON path. */
				__( 'JSON Array Path not found in payload: %s', 'enterprise-api-importer' ),
				$path
			)
		);
	}

	if ( ! is_array( $current ) ) {
		return new WP_Error(
			'eai_json_path_not_array',
			sprintf(
				/* translators: %s is the configured JSON path. */
				__( 'JSON Array Path did not resolve to an array: %s', 'enterprise-api-importer' ),
				$path
			)
		);
	}

	return $current;
}

/**
 * Transforms and loads a single API record into the imported_item post type.
 *
 * @param array<string, mixed> $item Single API record.
 *
 * @param string $mapping_template Mapping template for this import job.
 * @param string $unique_id_path   Unique identifier path. Defaults to id.
 * @param int    $import_id        Import job ID.
 *
 * @return array<string, mixed>|WP_Error
 */
function eai_transform_and_load_item( $item, $mapping_template, $unique_id_path = 'id', $import_id = 0 ) {
	if ( ! is_array( $item ) ) {
		return new WP_Error( 'eai_invalid_item', __( 'Transform input must be an array.', 'enterprise-api-importer' ) );
	}

	$mapping_template = (string) $mapping_template;
	$unique_id_path   = trim( (string) $unique_id_path );
	$import_id        = absint( $import_id );

	if ( '' === $unique_id_path ) {
		$unique_id_path = 'id';
	}

	$external_id_value = eai_get_item_value_by_path( $item, $unique_id_path );

	if ( null === $external_id_value || '' === (string) $external_id_value ) {
		return new WP_Error(
			'eai_missing_item_id',
			sprintf(
				/* translators: %s is the configured unique ID path. */
				__( 'Item is missing required unique ID at path: %s', 'enterprise-api-importer' ),
				$unique_id_path
			)
		);
	}

	if ( ! is_scalar( $external_id_value ) ) {
		return new WP_Error( 'eai_invalid_item_id', __( 'Unique ID field must resolve to a scalar value.', 'enterprise-api-importer' ) );
	}

	$external_id = (string) $external_id_value;

	if ( '' === $mapping_template ) {
		return new WP_Error( 'eai_missing_mapping_template', __( 'Mapping Template is not configured.', 'enterprise-api-importer' ) );
	}

	$post_content = eai_render_mapping_template_for_item( $item, $mapping_template );

	if ( is_wp_error( $post_content ) ) {
		return $post_content;
	}

	$post_title = sprintf(
		/* translators: %s is the external API record ID. */
		__( 'Imported Item %s', 'enterprise-api-importer' ),
		$external_id
	);

	$existing_posts = get_posts(
		array(
			'post_type'              => 'imported_item',
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
					'key'     => '_eai_import_id',
					'value'   => $import_id,
					'compare' => '=',
				),
			),
		)
	);

	$timestamp = time();

	if ( empty( $existing_posts ) ) {
		$insert_post_id = wp_insert_post(
			array(
				'post_type'    => 'imported_item',
				'post_status'  => 'publish',
				'post_title'   => $post_title,
				'post_content' => $post_content,
			),
			true
		);

		if ( is_wp_error( $insert_post_id ) ) {
			return $insert_post_id;
		}

		update_post_meta( $insert_post_id, '_my_custom_api_id', $external_id );
		update_post_meta( $insert_post_id, '_eai_import_id', $import_id );
		update_post_meta( $insert_post_id, '_last_synced_timestamp', $timestamp );

		return array(
			'action'  => 'inserted',
			'post_id' => (int) $insert_post_id,
		);
	}

	$existing_post_id = (int) $existing_posts[0];
	$existing_post    = get_post( $existing_post_id );

	if ( ! $existing_post instanceof WP_Post ) {
		return new WP_Error( 'eai_existing_post_not_found', __( 'Existing imported item post could not be loaded.', 'enterprise-api-importer' ) );
	}

	if ( (string) $existing_post->post_content !== (string) $post_content ) {
		$updated_post_id = wp_update_post(
			array(
				'ID'           => $existing_post_id,
				'post_content' => $post_content,
			),
			true
		);

		if ( is_wp_error( $updated_post_id ) ) {
			return $updated_post_id;
		}

		update_post_meta( $existing_post_id, '_last_synced_timestamp', $timestamp );

		return array(
			'action'  => 'updated',
			'post_id' => $existing_post_id,
		);
	}

	// Touch sync timestamp even when content is unchanged so valid items are not treated as orphans.
	update_post_meta( $existing_post_id, '_last_synced_timestamp', $timestamp );

	return array(
		'action'  => 'unchanged',
		'post_id' => $existing_post_id,
	);
}

/**
 * Resolves a value from an item using dot-notation.
 *
 * @param array<string, mixed> $item Item payload.
 * @param string               $path Dot-notation key path.
 *
 * @return mixed|null
 */
function eai_get_item_value_by_path( $item, $path ) {
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
 * Renders mapping template content for a single item.
 *
 * Supports placeholders like {KeyName} and {KeyName|html}.
 *
 * @param array<string, mixed> $item             Item payload.
 * @param string|null          $mapping_template Optional template override.
 *
 * @return string|WP_Error
 */
function eai_render_mapping_template_for_item( $item, $mapping_template = null ) {
	if ( ! is_array( $item ) ) {
		return new WP_Error( 'eai_invalid_item', __( 'Transform input must be an array.', 'enterprise-api-importer' ) );
	}

	$mapping_template = null === $mapping_template ? '' : (string) $mapping_template;

	if ( '' === (string) $mapping_template ) {
		return new WP_Error( 'eai_missing_mapping_template', __( 'Mapping Template is not configured.', 'enterprise-api-importer' ) );
	}

	$post_content = preg_replace_callback(
		'/\{([^{}]+)\}/',
		static function ( $matches ) use ( $item ) {
			$placeholder = isset( $matches[1] ) ? trim( (string) $matches[1] ) : '';
			$key_path    = $placeholder;
			$allow_html  = false;

			if ( false !== strpos( $placeholder, '|' ) ) {
				$parts      = explode( '|', $placeholder, 2 );
				$key_path   = trim( (string) $parts[0] );
				$mode       = strtolower( trim( (string) $parts[1] ) );
				$allow_html = 'html' === $mode;
			}

			if ( '' === $key_path ) {
				return '';
			}

			$value = eai_get_item_value_by_path( $item, $key_path );

			if ( null === $value ) {
				return '';
			}

			if ( is_bool( $value ) ) {
				return $value ? '1' : '0';
			}

			return eai_prepare_template_value( $value, $allow_html );
		},
		(string) $mapping_template
	);

	if ( null === $post_content ) {
		return new WP_Error( 'eai_template_transform_failed', __( 'Failed to transform mapping template.', 'enterprise-api-importer' ) );
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
function eai_prepare_template_value( $value, $allow_html = false ) {
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
function eai_write_import_log( $import_id, $import_run_id, $status, $rows_processed, $rows_created, $rows_updated, $details, $created_at ) {
	$details_js = wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( false === $details_js ) {
		$details_js = wp_json_encode( array( 'error' => 'Failed to encode run details.' ) );
	}

	return eai_db_insert_import_log(
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
function eai_has_unprocessed_staging_rows( $import_id ) {
	$count = eai_db_count_unprocessed_staging_rows( absint( $import_id ) );

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
function eai_schedule_import_batch_event( $delay_seconds = null, $is_initial = false ) {
	$options = wp_parse_args( get_option( 'eai_settings', array() ), eai_get_default_settings() );

	if ( null === $delay_seconds ) {
		$delay_seconds = $is_initial
			? (int) $options['cron_initial_delay_seconds']
			: (int) $options['cron_batch_delay_seconds'];
	}

	$delay_seconds = max( 0, (int) $delay_seconds );

	if ( wp_next_scheduled( 'eai_process_import_queue' ) ) {
		return true;
	}

	return (bool) wp_schedule_single_event( time() + $delay_seconds, 'eai_process_import_queue' );
}

/**
 * Returns the active import run state.
 *
 * @return array<string, mixed>
 */
function eai_get_active_run_state() {
	$state = get_option( 'eai_active_import_run', array() );

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
function eai_set_active_run_state( $state ) {
	update_option( 'eai_active_import_run', $state, false );
}

/**
 * Clears the active import run state.
 *
 * @return void
 */
function eai_clear_active_run_state() {
	delete_option( 'eai_active_import_run' );
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
function eai_process_unprocessed_staging_rows( $started_at_microtime, $import_id, $max_runtime_seconds = 45 ) {
	$import_id    = absint( $import_id );
	$staged_rows  = eai_db_get_unprocessed_staging_rows( $import_id );

	if ( ! is_array( $staged_rows ) ) {
		return new WP_Error( 'eai_staging_query_failed', __( 'Failed to query unprocessed staging rows.', 'enterprise-api-importer' ) );
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

		$row_id = isset( $staged_row['id'] ) ? (int) $staged_row['id'] : 0;
		$row_import_id = isset( $staged_row['import_id'] ) ? (int) $staged_row['import_id'] : 0;

		if ( $row_id <= 0 ) {
			$result['errors'][] = __( 'Encountered an invalid staging row identifier.', 'enterprise-api-importer' );
			continue;
		}

		$import_job = eai_db_get_import_config( $row_import_id );

		if ( ! is_array( $import_job ) ) {
			$result['errors'][] = sprintf(
				/* translators: %d is import job ID. */
				__( 'Import job %d could not be loaded for processing.', 'enterprise-api-importer' ),
				$row_import_id
			);
			continue;
		}

		$decoded_items = json_decode( (string) $staged_row['raw_json'], true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$result['errors'][] = sprintf(
				/* translators: 1: staging row ID, 2: JSON error message. */
				__( 'Staging row %1$d has invalid JSON: %2$s', 'enterprise-api-importer' ),
				$row_id,
				json_last_error_msg()
			);
			continue;
		}

		if ( ! is_array( $decoded_items ) ) {
			$result['errors'][] = sprintf(
				/* translators: %d is the staging row ID. */
				__( 'Staging row %d does not contain an array payload.', 'enterprise-api-importer' ),
				$row_id
			);
			continue;
		}

		$items            = eai_normalize_staged_items( $decoded_items );
		$mapping_template = (string) $import_job['mapping_template'];
		$unique_id_path   = isset( $import_job['unique_id_path'] ) ? trim( (string) $import_job['unique_id_path'] ) : 'id';
		$chunks           = array_chunk( $items, 50 );
		$row_completed    = true;

		if ( '' === $unique_id_path ) {
			$unique_id_path = 'id';
		}

		foreach ( $chunks as $chunk_items ) {
			foreach ( $chunk_items as $item ) {
				if ( ( microtime( true ) - $started_at_microtime ) >= $max_runtime_seconds ) {
					$result['timed_out']     = true;
					$result['has_remaining'] = true;
					$row_completed           = false;
					break 2;
				}

				++$result['rows_processed'];

				$item_result = eai_transform_and_load_item( $item, $mapping_template, $unique_id_path, $row_import_id );

				if ( is_wp_error( $item_result ) ) {
					$result['errors'][] = sprintf(
						/* translators: 1: staging row ID, 2: error message. */
						__( 'Row %1$d item failed: %2$s', 'enterprise-api-importer' ),
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
			wp_cache_flush();
		}

		if ( $row_completed ) {
			$marked_processed = eai_db_mark_staging_row_processed( $row_id );

			if ( ! $marked_processed ) {
				$result['errors'][] = sprintf(
					/* translators: %d is the staging row ID. */
					__( 'Failed to mark staging row %d as processed.', 'enterprise-api-importer' ),
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
		$result['has_remaining'] = eai_has_unprocessed_staging_rows( $import_id );
	}

	return $result;
}

/**
 * Handles one scheduled import batch event.
 *
 * @return void
 */
function eai_handle_scheduled_import_batch() {
	$state = eai_get_active_run_state();

	if ( empty( $state ) || empty( $state['import_id'] ) ) {
		return;
	}

	$import_id = absint( $state['import_id'] );

	$state['slices'] = isset( $state['slices'] ) ? ( (int) $state['slices'] + 1 ) : 1;

	$processing_result = eai_process_unprocessed_staging_rows( microtime( true ), $import_id, 45 );

	if ( is_wp_error( $processing_result ) ) {
		$end_time = gmdate( 'Y-m-d H:i:s', time() );
		$details  = array(
			'start_time'         => $state['start_time'],
			'end_time'           => $end_time,
			'orphans_trashed'    => 0,
			'temp_rows_found'    => (int) $state['temp_rows_found'],
			'temp_rows_processed'=> (int) $state['temp_rows_processed'],
			'slices'             => (int) $state['slices'],
			'trigger_source'     => isset( $state['trigger_source'] ) ? sanitize_key( (string) $state['trigger_source'] ) : 'unknown',
			'processing_errors'  => array( $processing_result->get_error_message() ),
		);

		eai_write_import_log(
			$import_id,
			(string) $state['run_id'],
			'failed',
			(int) $state['rows_processed'],
			(int) $state['rows_created'],
			(int) $state['rows_updated'],
			$details,
			(string) $state['start_time']
		);

		eai_clear_active_run_state();
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
		eai_set_active_run_state( $state );
		eai_schedule_import_batch_event( null, false );
		return;
	}

	$orphans_trashed = eai_trash_orphaned_imported_posts( (int) $state['start_timestamp'], $import_id );
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

	eai_write_import_log(
		$import_id,
		(string) $state['run_id'],
		$status,
		(int) $state['rows_processed'],
		(int) $state['rows_created'],
		(int) $state['rows_updated'],
		$details,
		(string) $state['start_time']
	);

	eai_clear_active_run_state();
}

/**
 * Normalizes staged payload into a list of item arrays.
 *
 * @param array<mixed> $decoded_items Decoded staged payload.
 *
 * @return array<int, array<string, mixed>>
 */
function eai_normalize_staged_items( $decoded_items ) {
	$items = array();

	if ( eai_array_is_list( $decoded_items ) ) {
		foreach ( $decoded_items as $entry ) {
			if ( is_array( $entry ) ) {
				$items[] = $entry;
			}
		}

		return $items;
	}

	if ( isset( $decoded_items['id'] ) ) {
		$items[] = $decoded_items;
	}

	return $items;
}

/**
 * Backport helper for list-array detection.
 *
 * @param array<mixed> $array Input array.
 *
 * @return bool
 */
function eai_array_is_list( $array ) {
	$index = 0;

	foreach ( $array as $key => $unused_value ) {
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
function eai_trash_orphaned_imported_posts( $run_started_unix, $import_id ) {
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
			$postmeta_table,
			'_last_synced_timestamp',
			'_eai_import_id',
			$import_id,
			'imported_item',
			$run_started_unix
		)
	);

	if ( ! is_array( $orphan_ids ) ) {
		return new WP_Error( 'eai_orphan_query_failed', __( 'Failed to query orphaned imported items.', 'enterprise-api-importer' ) );
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
