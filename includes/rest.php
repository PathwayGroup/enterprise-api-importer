<?php
/**
 * REST route registration and admin REST handlers.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Permission callback for post-type-specific defaults endpoint.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return true|WP_Error
 */
function tporapdi_rest_permission_callback_post_type_defaults( WP_REST_Request $request ) {
	$nonce_check = tporapdi_rest_validate_request_nonce( $request );
	if ( is_wp_error( $nonce_check ) ) {
		return $nonce_check;
	}

	$post_type = $request->get_param( 'post_type' );
	if ( ! post_type_exists( $post_type ) ) {
		return new WP_Error(
			'invalid_post_type',
			esc_html__( 'Invalid post type.', 'tporret-api-data-importer' ),
			array( 'status' => 404 )
		);
	}

	// Check post-type-specific edit capability.
	$capability = "edit_{$post_type}s";
	if ( ! current_user_can( $capability ) ) {
		return new WP_Error(
			'rest_forbidden',
			esc_html__( 'You do not have permission to access defaults for this post type.', 'tporret-api-data-importer' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Validates the REST request nonce for admin tooling.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return true|WP_Error
 */
function tporapdi_rest_validate_request_nonce( WP_REST_Request $request ) {
	$nonce = (string) $request->get_header( 'x-wp-nonce' );

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return new WP_Error(
			'tporapdi_rest_nonce_invalid',
			esc_html__( 'Invalid request verification.', 'tporret-api-data-importer' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Shared REST route permission callback for admin tooling.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return true|WP_Error
 */
function tporapdi_rest_permission_callback( WP_REST_Request $request ) {
	$nonce_check = tporapdi_rest_validate_request_nonce( $request );
	if ( is_wp_error( $nonce_check ) ) {
		return $nonce_check;
	}

	if ( ! tporapdi_current_user_can_manage_imports() ) {
		return new WP_Error(
			'rest_forbidden',
			esc_html__( 'You are not allowed to access this resource.', 'tporret-api-data-importer' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Registers REST routes for async admin tooling.
 *
 * @return void
 */
function tporapdi_register_rest_routes() {
	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/dry-run',
		array(
			'methods'             => 'POST',
			'callback'            => 'tporapdi_rest_dry_run_template_preview',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback( $request );
			},
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/test-api-connection',
		array(
			'methods'             => 'POST',
			'callback'            => 'tporapdi_rest_test_api_connection',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback( $request );
			},
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/import-jobs/(?P<id>[\d]+)',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'tporapdi_rest_get_import_job',
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return tporapdi_rest_permission_callback( $request );
				},
			),
			array(
				'methods'             => 'PUT',
				'callback'            => 'tporapdi_rest_update_import_job',
				'permission_callback' => static function ( WP_REST_Request $request ) {
					return tporapdi_rest_permission_callback( $request );
				},
			),
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/import-jobs',
		array(
			'methods'             => 'POST',
			'callback'            => 'tporapdi_rest_create_import_job',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback( $request );
			},
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/import-jobs/(?P<id>[\d]+)/run',
		array(
			'methods'             => 'POST',
			'callback'            => 'tporapdi_rest_run_import_job',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback( $request );
			},
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/import-jobs/(?P<id>[\d]+)/template-sync',
		array(
			'methods'             => 'POST',
			'callback'            => 'tporapdi_rest_template_sync_import_job',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback( $request );
			},
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/import-jobs/(?P<id>[\d]+)/cleanup',
		array(
			'methods'             => 'POST',
			'callback'            => 'tporapdi_rest_cleanup_import_job',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback( $request );
			},
		)
	);

	register_rest_route(
		TPORAPDI_ADMIN_REST_NAMESPACE,
		'/post-type-defaults/(?P<post_type>[a-zA-Z0-9_-]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'tporapdi_rest_get_post_type_defaults',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				return tporapdi_rest_permission_callback_post_type_defaults( $request );
			},
		)
	);
}
add_action( 'rest_api_init', 'tporapdi_register_rest_routes' );

/**
 * Executes a dry-run Twig preview against a live API response without persisting any data.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_dry_run_template_preview( WP_REST_Request $request ) {
	$params           = $request->get_json_params();
	$params           = is_array( $params ) ? $params : array();
	$api_url          = isset( $params['api_url'] ) ? esc_url_raw( trim( (string) $params['api_url'] ) ) : '';
	$data_filters     = isset( $params['data_filters'] ) && is_array( $params['data_filters'] ) ? $params['data_filters'] : array();
	$title_template   = isset( $params['title_template'] ) ? (string) $params['title_template'] : '';
	$body_template    = isset( $params['body_template'] ) ? (string) $params['body_template'] : '';
	$auth_token       = isset( $params['auth_token'] ) ? trim( (string) $params['auth_token'] ) : '';
	$auth_method      = isset( $params['auth_method'] ) ? sanitize_key( (string) $params['auth_method'] ) : 'none';
	$auth_header_name = isset( $params['auth_header_name'] ) ? sanitize_text_field( (string) $params['auth_header_name'] ) : '';
	$auth_username    = isset( $params['auth_username'] ) ? sanitize_text_field( (string) $params['auth_username'] ) : '';
	$auth_password    = isset( $params['auth_password'] ) ? (string) $params['auth_password'] : '';

	$title_template       = mb_substr( trim( sanitize_text_field( $title_template ) ), 0, 255 );
	$allowed_mapping_html = array(
		'h1'      => array( 'class' => true ),
		'h2'      => array( 'class' => true ),
		'h3'      => array( 'class' => true ),
		'h4'      => array( 'class' => true ),
		'h5'      => array( 'class' => true ),
		'h6'      => array( 'class' => true ),
		'p'       => array( 'class' => true ),
		'br'      => array(),
		'strong'  => array( 'class' => true ),
		'em'      => array( 'class' => true ),
		'ul'      => array( 'class' => true ),
		'ol'      => array( 'class' => true ),
		'li'      => array( 'class' => true ),
		'article' => array( 'class' => true ),
		'header'  => array( 'class' => true ),
		'section' => array( 'class' => true ),
		'footer'  => array( 'class' => true ),
		'div'     => array( 'class' => true ),
		'span'    => array( 'class' => true ),
		'a'       => array(
			'href'   => true,
			'title'  => true,
			'target' => true,
			'rel'    => true,
			'class'  => true,
		),
	);
	$body_template        = tporapdi_kses_mapping_template( $body_template, $allowed_mapping_html );

	$title_template_validation = tporapdi_validate_twig_template_security( $title_template, 'title' );
	if ( is_wp_error( $title_template_validation ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $title_template_validation->get_error_code(),
				'message' => $title_template_validation->get_error_message(),
			),
			400
		);
	}

	$body_template_validation = tporapdi_validate_twig_template_security( $body_template, 'mapping' );
	if ( is_wp_error( $body_template_validation ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $body_template_validation->get_error_code(),
				'message' => $body_template_validation->get_error_message(),
			),
			400
		);
	}

	$validated_endpoint = tporapdi_validate_remote_endpoint_url( $api_url );

	if ( is_wp_error( $validated_endpoint ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $validated_endpoint->get_error_code(),
				'message' => $validated_endpoint->get_error_message(),
			),
			400
		);
	}

	$response = wp_remote_get(
		$api_url,
		tporapdi_get_remote_request_args( $auth_method, $auth_token, $auth_header_name, $auth_username, $auth_password )
	);

	if ( is_wp_error( $response ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_remote_request_failed',
				'message' => $response->get_error_message(),
			),
			400
		);
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_remote_http_error',
				'message' => sprintf(
					/* translators: %d is HTTP status code. */
					esc_html__( 'Dry run request failed with HTTP status %d.', 'tporret-api-data-importer' ),
					$status_code
				),
			),
			400
		);
	}

	$raw_body = (string) wp_remote_retrieve_body( $response );
	$decoded  = json_decode( $raw_body, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_invalid_json',
				'message' => sprintf(
					/* translators: %s is JSON decode error text. */
					esc_html__( 'Unable to parse API JSON: %s', 'tporret-api-data-importer' ),
					json_last_error_msg()
				),
			),
			400
		);
	}

	$array_path = isset( $data_filters['array_path'] ) ? sanitize_text_field( (string) $data_filters['array_path'] ) : '';
	$records    = '' === $array_path ? $decoded : tporapdi_resolve_json_array_path( $decoded, $array_path );

	if ( is_wp_error( $records ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_invalid_array_path',
				'message' => $records->get_error_message(),
			),
			400
		);
	}

	$incoming_rules = isset( $data_filters['rules'] ) && is_array( $data_filters['rules'] ) ? $data_filters['rules'] : array();
	$filter_rules   = tporapdi_decode_filter_rules_json( wp_json_encode( $incoming_rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );

	if ( ! empty( $filter_rules ) ) {
		$records = tporapdi_apply_filter_rules_to_records( is_array( $records ) ? $records : array(), $filter_rules );
	}

	$record = null;
	if ( is_array( $records ) && ! empty( $records ) ) {
		$record = tporapdi_array_is_list( $records ) ? $records[0] : $records;
	}

	if ( ! is_array( $record ) || empty( $record ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_no_record_found',
				'message' => esc_html__( 'Dry run could not find a record after applying filters.', 'tporret-api-data-importer' ),
			),
			400
		);
	}

	$engine = tporapdi_get_template_engine();

	$rendered_title = '' !== $title_template
		? $engine->render( $title_template, $record, TPORAPDI_Template_Engine::TYPE_TITLE )
		: '';

	if ( is_wp_error( $rendered_title ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $rendered_title->get_error_code(),
				'message' => $rendered_title->get_error_message(),
			),
			400
		);
	}

	$rendered_body = '' !== $body_template
		? $engine->render( $body_template, $record, TPORAPDI_Template_Engine::TYPE_BODY )
		: '';

	if ( is_wp_error( $rendered_body ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $rendered_body->get_error_code(),
				'message' => $rendered_body->get_error_message(),
			),
			400
		);
	}

	return new WP_REST_Response(
		array(
			'raw_data'       => $record,
			'rendered_title' => $rendered_title,
			'rendered_body'  => $rendered_body,
		),
		200
	);
}

/**
 * Tests API connection and returns sample data structure (for new import setup).
 *
 * @param WP_REST_Request $request REST request object.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_test_api_connection( WP_REST_Request $request ) {
	$params           = $request->get_json_params();
	$params           = is_array( $params ) ? $params : array();
	$api_url          = isset( $params['api_url'] ) ? esc_url_raw( trim( (string) $params['api_url'] ) ) : '';
	$array_path       = isset( $params['array_path'] ) ? sanitize_text_field( (string) $params['array_path'] ) : '';
	$auth_method      = isset( $params['auth_method'] ) ? sanitize_key( (string) $params['auth_method'] ) : 'none';
	$auth_token       = isset( $params['auth_token'] ) ? trim( (string) $params['auth_token'] ) : '';
	$auth_header_name = isset( $params['auth_header_name'] ) ? sanitize_text_field( (string) $params['auth_header_name'] ) : '';
	$auth_username    = isset( $params['auth_username'] ) ? sanitize_text_field( (string) $params['auth_username'] ) : '';
	$auth_password    = isset( $params['auth_password'] ) ? (string) $params['auth_password'] : '';

	$validated_endpoint = tporapdi_validate_remote_endpoint_url( $api_url );
	if ( is_wp_error( $validated_endpoint ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $validated_endpoint->get_error_code(),
				'message' => $validated_endpoint->get_error_message(),
			),
			400
		);
	}

	$response = wp_remote_get(
		$api_url,
		tporapdi_get_remote_request_args( $auth_method, $auth_token, $auth_header_name, $auth_username, $auth_password )
	);

	if ( is_wp_error( $response ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_remote_request_failed',
				'message' => $response->get_error_message(),
			),
			400
		);
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	if ( $status_code < 200 || $status_code >= 300 ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_remote_http_error',
				'message' => sprintf(
					/* translators: %d is HTTP status code. */
					esc_html__( 'API connection failed with HTTP status %d.', 'tporret-api-data-importer' ),
					$status_code
				),
			),
			400
		);
	}

	$body         = wp_remote_retrieve_body( $response );
	$decoded_json = json_decode( (string) $body, true );

	if ( JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_REST_Response(
			array(
				'code'    => 'tporapdi_invalid_json',
				'message' => sprintf(
					/* translators: %s is the JSON parser error message. */
					esc_html__( 'API returned invalid JSON: %s', 'tporret-api-data-importer' ),
					json_last_error_msg()
				),
			),
			400
		);
	}

	$selected_array = tporapdi_resolve_json_array_path( $decoded_json, $array_path );
	if ( is_wp_error( $selected_array ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $selected_array->get_error_code(),
				'message' => $selected_array->get_error_message(),
			),
			400
		);
	}

	$sample_item    = null;
	$available_keys = array();

	if ( is_array( $selected_array ) ) {
		$sample_item = tporapdi_array_is_list( $selected_array ) && ! empty( $selected_array ) ? $selected_array[0] : $selected_array;
	}

	if ( is_array( $sample_item ) ) {
		$available_keys = array_keys( $sample_item );
	}

	$sample_json = wp_json_encode( $sample_item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $sample_json ) {
		$sample_json = '';
	}

	$item_count = 0;
	if ( is_array( $selected_array ) ) {
		$item_count = tporapdi_array_is_list( $selected_array ) ? count( $selected_array ) : 1;
	}

	return new WP_REST_Response(
		array(
			'success'        => true,
			'message'        => esc_html__( 'API connection successful.', 'tporret-api-data-importer' ),
			'status_code'    => $status_code,
			'item_count'     => $item_count,
			'available_keys' => $available_keys,
			'sample_data'    => $sample_item,
			'sample_json'    => $sample_json,
		),
		200
	);
}

/**
 * REST: Returns a single import job for the React workspace.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_get_import_job( WP_REST_Request $request ) {
	$id  = absint( $request->get_param( 'id' ) );
	$row = tporapdi_db_get_import_config( $id );

	if ( ! is_array( $row ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'not_found',
				'message' => esc_html__( 'Import job not found.', 'tporret-api-data-importer' ),
			),
			404
		);
	}

	$row['id']                      = (int) $row['id'];
	$row['custom_interval_minutes'] = absint( $row['custom_interval_minutes'] );
	$row['lock_editing']            = (int) $row['lock_editing'];
	$row                            = tporapdi_mask_import_credentials( $row );

	return new WP_REST_Response( $row, 200 );
}

/**
 * Returns the import job validator module.
 *
 * @return TPORAPDI_Validator
 */
function tporapdi_get_validator() {
	static $validator = null;

	if ( ! $validator instanceof TPORAPDI_Validator ) {
		$validator = new TPORAPDI_Validator();
	}

	return $validator;
}

/**
 * Shared import-job field sanitisation used by create and update REST handlers.
 *
 * @param array<string, mixed> $params Raw request params.
 *
 * @return array{data: array<string, mixed>, formats: array<int, string>}|WP_REST_Response
 */
function tporapdi_rest_sanitize_import_job_fields( array $params ) {
	return tporapdi_get_validator()->validate_import_job_fields( $params );
}

/**
 * REST: Creates a new import job.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_create_import_job( WP_REST_Request $request ) {
	$params    = $request->get_json_params();
	$params    = is_array( $params ) ? $params : array();
	$sanitized = tporapdi_rest_sanitize_import_job_fields( $params );

	if ( $sanitized instanceof WP_REST_Response ) {
		return $sanitized;
	}

	$result = tporapdi_db_save_import_config( 0, $sanitized['data'], $sanitized['formats'] );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			),
			500
		);
	}

	$import_id = (int) $result;

	tporapdi_audit_template_configuration_change( $import_id, null, $sanitized['data'] );
	tporapdi_sync_import_recurrence_schedule( $import_id, $sanitized['data']['recurrence'], $sanitized['data']['custom_interval_minutes'] );

	$saved = tporapdi_db_get_import_config( $import_id );

	return new WP_REST_Response(
		is_array( $saved ) ? array_merge( $saved, array( 'id' => $import_id ) ) : array( 'id' => $import_id ),
		201
	);
}

/**
 * REST: Updates an existing import job.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_update_import_job( WP_REST_Request $request ) {
	$id     = absint( $request->get_param( 'id' ) );
	$params = $request->get_json_params();
	$params = is_array( $params ) ? $params : array();

	$previous = tporapdi_db_get_import_config( $id );
	if ( ! is_array( $previous ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'not_found',
				'message' => esc_html__( 'Import job not found.', 'tporret-api-data-importer' ),
			),
			404
		);
	}

	$sanitized = tporapdi_rest_sanitize_import_job_fields( $params );
	if ( $sanitized instanceof WP_REST_Response ) {
		return $sanitized;
	}

	tporapdi_preserve_unchanged_credentials( $sanitized['data'], $id );

	$result = tporapdi_db_save_import_config( $id, $sanitized['data'], $sanitized['formats'] );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
			),
			500
		);
	}

	tporapdi_audit_template_configuration_change( $id, $previous, $sanitized['data'] );
	tporapdi_sync_import_recurrence_schedule( $id, $sanitized['data']['recurrence'], $sanitized['data']['custom_interval_minutes'] );

	$saved = tporapdi_db_get_import_config( $id );

	return new WP_REST_Response(
		is_array( $saved ) ? array_merge( $saved, array( 'id' => $id ) ) : array( 'id' => $id ),
		200
	);
}

/**
 * REST: Triggers a manual import run.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_run_import_job( WP_REST_Request $request ) {
	$id  = absint( $request->get_param( 'id' ) );
	$row = tporapdi_db_get_import_config( $id );

	if ( ! is_array( $row ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'not_found',
				'message' => esc_html__( 'Import job not found.', 'tporret-api-data-importer' ),
			),
			404
		);
	}

	$start_result = tporapdi_get_import_runner()->start_manual_run( $id, 'manual' );
	if ( is_wp_error( $start_result ) ) {
		$status = 'import_running' === $start_result->get_error_code() ? 409 : 400;

		return new WP_REST_Response(
			array(
				'code'    => $start_result->get_error_code(),
				'message' => $start_result->get_error_message(),
			),
			$status
		);
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => esc_html__( 'Import run started.', 'tporret-api-data-importer' ),
		),
		200
	);
}

/**
 * REST: Re-renders existing imported items using updated templates.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_template_sync_import_job( WP_REST_Request $request ) {
	$id  = absint( $request->get_param( 'id' ) );
	$row = tporapdi_db_get_import_config( $id );

	if ( ! is_array( $row ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'not_found',
				'message' => esc_html__( 'Import job not found.', 'tporret-api-data-importer' ),
			),
			404
		);
	}

	$start_result = tporapdi_get_import_runner()->start_manual_run( $id, 'manual' );
	if ( is_wp_error( $start_result ) ) {
		$status = 'import_running' === $start_result->get_error_code() ? 409 : 400;

		return new WP_REST_Response(
			array(
				'code'    => $start_result->get_error_code(),
				'message' => $start_result->get_error_message(),
			),
			$status
		);
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => esc_html__( 'Template sync started.', 'tporret-api-data-importer' ),
		),
		200
	);
}

/**
 * REST: Clears imported posts and related runtime rows for one import job.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function tporapdi_rest_cleanup_import_job( WP_REST_Request $request ) {
	$id     = absint( $request->get_param( 'id' ) );
	$params = $request->get_json_params();
	$params = is_array( $params ) ? $params : array();

	$row = tporapdi_db_get_import_config( $id );
	if ( ! is_array( $row ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'not_found',
				'message' => esc_html__( 'Import job not found.', 'tporret-api-data-importer' ),
			),
			404
		);
	}

	$active_state = tporapdi_get_active_run_state();
	if ( ! empty( $active_state['run_id'] ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'import_running',
				'message' => esc_html__( 'An import is already running.', 'tporret-api-data-importer' ),
			),
			409
		);
	}

	$confirmation = isset( $params['confirmation'] ) ? trim( (string) $params['confirmation'] ) : '';
	if ( 'DELETE' !== $confirmation ) {
		return new WP_REST_Response(
			array(
				'code'    => 'invalid_confirmation',
				'message' => esc_html__( 'Type DELETE to confirm this cleanup action.', 'tporret-api-data-importer' ),
			),
			400
		);
	}

	$mode = isset( $params['mode'] ) ? sanitize_key( (string) $params['mode'] ) : 'trash';
	if ( ! in_array( $mode, array( 'trash', 'delete' ), true ) ) {
		return new WP_REST_Response(
			array(
				'code'    => 'invalid_cleanup_mode',
				'message' => esc_html__( 'Cleanup mode must be either trash or delete.', 'tporret-api-data-importer' ),
			),
			400
		);
	}

	$cleanup_result = tporapdi_cleanup_import_job_content( $id, $mode );
	if ( is_wp_error( $cleanup_result ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $cleanup_result->get_error_code(),
				'message' => $cleanup_result->get_error_message(),
			),
			500
		);
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => 'delete' === $mode
				? esc_html__( 'Imported posts and related data were permanently deleted.', 'tporret-api-data-importer' )
				: esc_html__( 'Imported posts were moved to trash and related data was cleared.', 'tporret-api-data-importer' ),
			'results' => $cleanup_result,
		),
		200
	);
}

/**
 * Returns post type defaults for a given post type.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */
function tporapdi_rest_get_post_type_defaults( WP_REST_Request $request ) {
	$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );

	// Get defaults from resolver.
	$defaults = TPORAPDI_Defaults_Resolver::get_defaults_for_post_type( $post_type );

	// If error, return 404 or appropriate error code.
	if ( is_wp_error( $defaults ) ) {
		return new WP_REST_Response(
			array(
				'code'    => $defaults->get_error_code(),
				'message' => $defaults->get_error_message(),
			),
			404
		);
	}

	return new WP_REST_Response( $defaults, 200 );
}
