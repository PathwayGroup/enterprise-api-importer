<?php
/**
 * Validation module for import job payloads.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deep validator module for import job REST payloads.
 */
class TPORAPDI_Validator {
	/**
	 * Validates and sanitizes import job payload fields.
	 *
	 * @param array<string, mixed> $params Raw request params.
	 *
	 * @return array{data: array<string, mixed>, formats: array<int, string>}|WP_REST_Response
	 */
	public function validate_import_job_fields( array $params ) {
		$name                       = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		$endpoint_url               = isset( $params['endpoint_url'] ) ? esc_url_raw( trim( (string) $params['endpoint_url'] ) ) : '';
		$auth_method                = isset( $params['auth_method'] ) ? sanitize_key( (string) $params['auth_method'] ) : 'none';
		$auth_token                 = isset( $params['auth_token'] ) ? sanitize_text_field( trim( (string) $params['auth_token'] ) ) : '';
		$auth_header_name           = isset( $params['auth_header_name'] ) ? sanitize_text_field( (string) $params['auth_header_name'] ) : '';
		$auth_username              = isset( $params['auth_username'] ) ? sanitize_text_field( (string) $params['auth_username'] ) : '';
		$auth_password              = isset( $params['auth_password'] ) ? (string) $params['auth_password'] : '';
		$array_path                 = isset( $params['array_path'] ) ? sanitize_text_field( (string) $params['array_path'] ) : '';
		$unique_id_path             = isset( $params['unique_id_path'] ) ? sanitize_text_field( (string) $params['unique_id_path'] ) : 'id';
		$recurrence                 = isset( $params['recurrence'] ) ? sanitize_key( (string) $params['recurrence'] ) : 'off';
		$custom_interval_minutes    = isset( $params['custom_interval_minutes'] ) ? absint( $params['custom_interval_minutes'] ) : 0;
		$target_post_type           = isset( $params['target_post_type'] ) ? sanitize_key( (string) $params['target_post_type'] ) : 'post';
		$featured_image_source_path = isset( $params['featured_image_source_path'] ) ? sanitize_text_field( (string) $params['featured_image_source_path'] ) : 'image.url';
		$title_template             = isset( $params['title_template'] ) ? sanitize_text_field( (string) $params['title_template'] ) : '';
		$excerpt_template           = isset( $params['excerpt_template'] ) ? sanitize_text_field( (string) $params['excerpt_template'] ) : '';
		$post_name_template         = isset( $params['post_name_template'] ) ? sanitize_text_field( (string) $params['post_name_template'] ) : '';
		$post_author                = isset( $params['post_author'] ) ? absint( $params['post_author'] ) : 0;
		$template_raw               = isset( $params['mapping_template'] ) ? (string) $params['mapping_template'] : '';
		$post_status                = isset( $params['post_status'] ) ? sanitize_key( (string) $params['post_status'] ) : 'draft';
		$comment_status             = isset( $params['comment_status'] ) ? sanitize_key( (string) $params['comment_status'] ) : 'closed';
		$ping_status                = isset( $params['ping_status'] ) ? sanitize_key( (string) $params['ping_status'] ) : 'closed';

		$required_check = $this->validate_required_fields( $name, $endpoint_url );
		if ( $required_check instanceof WP_REST_Response ) {
			return $required_check;
		}

		$auth_state       = $this->sanitize_auth_fields( $auth_method, $auth_token, $auth_header_name, $auth_username, $auth_password );
		$auth_method      = $auth_state['auth_method'];
		$auth_token       = $auth_state['auth_token'];
		$auth_header_name = $auth_state['auth_header_name'];
		$auth_username    = $auth_state['auth_username'];
		$auth_password    = $auth_state['auth_password'];

		$recurrence_state        = $this->sanitize_recurrence_fields( $recurrence, $custom_interval_minutes );
		$recurrence              = $recurrence_state['recurrence'];
		$custom_interval_minutes = $recurrence_state['custom_interval_minutes'];

		if ( '' === trim( $unique_id_path ) ) {
			$unique_id_path = 'id';
		}

		if ( '' === $target_post_type ) {
			$target_post_type = 'post';
		}

		$target_post_type_check = $this->validate_target_post_type( $target_post_type );
		if ( $target_post_type_check instanceof WP_REST_Response ) {
			return $target_post_type_check;
		}

		$featured_image_source_path = trim( (string) $featured_image_source_path );
		if ( '' === $featured_image_source_path ) {
			$featured_image_source_path = 'image.url';
		}

		$title_template     = mb_substr( trim( $title_template ), 0, 255 );
		$excerpt_template   = mb_substr( trim( $excerpt_template ), 0, 255 );
		$post_name_template = mb_substr( trim( $post_name_template ), 0, 255 );

		$normalized     = TPORAPDI_Defaults_Resolver::normalize( $target_post_type, compact( 'post_status', 'comment_status', 'ping_status' ) );
		$post_status    = $normalized['post_status'];
		$comment_status = $normalized['comment_status'];
		$ping_status    = $normalized['ping_status'];

		if ( $post_author > 0 && false === get_userdata( $post_author ) ) {
			$post_author = 0;
		}

		$template_check = $this->validate_template_fields( $title_template, $excerpt_template, $post_name_template );
		if ( $template_check instanceof WP_REST_Response ) {
			return $template_check;
		}

		$mapping_template = tporapdi_kses_mapping_template( $template_raw, $this->get_allowed_mapping_html() );
		if ( '' !== $mapping_template ) {
			$mapping_check = tporapdi_validate_twig_template_security( $mapping_template, 'mapping' );
			if ( is_wp_error( $mapping_check ) ) {
				return $this->rest_error( $mapping_check->get_error_code(), $mapping_check->get_error_message(), 400 );
			}
		}

		$filter_rules_json         = $this->sanitize_filter_rules( $params['filter_rules'] ?? null );
		$custom_meta_mappings_json = $this->sanitize_custom_meta_mappings( $params['custom_meta_mappings'] ?? null );

		$auth_token    = tporapdi_encrypt_credential( $auth_token );
		$auth_password = tporapdi_encrypt_credential( $auth_password );

		$data = array(
			'name'                       => $name,
			'endpoint_url'               => $endpoint_url,
			'auth_method'                => $auth_method,
			'auth_token'                 => $auth_token,
			'auth_header_name'           => $auth_header_name,
			'auth_username'              => $auth_username,
			'auth_password'              => $auth_password,
			'array_path'                 => $array_path,
			'unique_id_path'             => $unique_id_path,
			'recurrence'                 => $recurrence,
			'custom_interval_minutes'    => $custom_interval_minutes,
			'filter_rules'               => $filter_rules_json,
			'target_post_type'           => $target_post_type,
			'featured_image_source_path' => $featured_image_source_path,
			'title_template'             => $title_template,
			'excerpt_template'           => $excerpt_template,
			'post_name_template'         => $post_name_template,
			'mapping_template'           => $mapping_template,
			'post_author'                => $post_author,
			'lock_editing'               => isset( $params['lock_editing'] ) ? absint( (bool) $params['lock_editing'] ) : 1,
			'post_status'                => $post_status,
			'comment_status'             => $comment_status,
			'ping_status'                => $ping_status,
			'custom_meta_mappings'       => $custom_meta_mappings_json,
		);

		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );

		return array(
			'data'    => $data,
			'formats' => $formats,
		);
	}

	/**
	 * Validates required name and endpoint fields.
	 *
	 * @param string $name         Import job name.
	 * @param string $endpoint_url Endpoint URL.
	 *
	 * @return true|WP_REST_Response
	 */
	private function validate_required_fields( string $name, string $endpoint_url ) {
		if ( '' === $name || '' === $endpoint_url ) {
			return $this->rest_error( 'missing_fields', __( 'Name and Endpoint URL are required.', 'tporret-api-data-importer' ), 400 );
		}

		return true;
	}

	/**
	 * Normalizes auth fields based on the selected auth method.
	 *
	 * @param string $auth_method      Authentication method.
	 * @param string $auth_token       Authentication token.
	 * @param string $auth_header_name Custom auth header name.
	 * @param string $auth_username    Basic auth username.
	 * @param string $auth_password    Basic auth password.
	 *
	 * @return array{auth_method: string, auth_token: string, auth_header_name: string, auth_username: string, auth_password: string}
	 */
	private function sanitize_auth_fields( string $auth_method, string $auth_token, string $auth_header_name, string $auth_username, string $auth_password ): array {
		$allowed_auth_methods = array( 'none', 'bearer', 'api_key_custom', 'basic_auth' );
		if ( ! in_array( $auth_method, $allowed_auth_methods, true ) ) {
			$auth_method = 'none';
		}

		if ( 'api_key_custom' !== $auth_method ) {
			$auth_header_name = '';
		}

		if ( 'bearer' !== $auth_method && 'api_key_custom' !== $auth_method ) {
			$auth_token = '';
		}

		if ( 'basic_auth' !== $auth_method ) {
			$auth_username = '';
			$auth_password = '';
		}

		return array(
			'auth_method'      => $auth_method,
			'auth_token'       => $auth_token,
			'auth_header_name' => $auth_header_name,
			'auth_username'    => $auth_username,
			'auth_password'    => $auth_password,
		);
	}

	/**
	 * Normalizes recurrence configuration fields.
	 *
	 * @param string $recurrence              Recurrence slug.
	 * @param int    $custom_interval_minutes Custom interval in minutes.
	 *
	 * @return array{recurrence: string, custom_interval_minutes: int}
	 */
	private function sanitize_recurrence_fields( string $recurrence, int $custom_interval_minutes ): array {
		$allowed_recurrence = array( 'off', 'hourly', 'twicedaily', 'daily', 'custom' );
		if ( ! in_array( $recurrence, $allowed_recurrence, true ) ) {
			$recurrence = 'off';
		}

		if ( 'custom' === $recurrence ) {
			$custom_interval_minutes = $custom_interval_minutes > 0 ? $custom_interval_minutes : 30;
		} else {
			$custom_interval_minutes = 0;
		}

		return array(
			'recurrence'              => $recurrence,
			'custom_interval_minutes' => $custom_interval_minutes,
		);
	}

	/**
	 * Validates target post type selection.
	 *
	 * @param string $target_post_type Target post type slug.
	 *
	 * @return true|WP_REST_Response
	 */
	private function validate_target_post_type( string $target_post_type ) {
		if ( 'attachment' === $target_post_type ) {
			return $this->rest_error( 'invalid_target_post_type', __( 'Attachment is not a supported target post type for import jobs.', 'tporret-api-data-importer' ), 400 );
		}

		if ( ! post_type_exists( $target_post_type ) ) {
			return $this->rest_error( 'invalid_target_post_type', __( 'Target post type does not exist.', 'tporret-api-data-importer' ), 400 );
		}

		return true;
	}

	/**
	 * Validates title, excerpt, and slug template fields.
	 *
	 * @param string $title_template     Title template.
	 * @param string $excerpt_template   Excerpt template.
	 * @param string $post_name_template Slug template.
	 *
	 * @return true|WP_REST_Response
	 */
	private function validate_template_fields( string $title_template, string $excerpt_template, string $post_name_template ) {
		$template_checks = array(
			array(
				'template' => $title_template,
				'type'     => 'title',
			),
			array(
				'template' => $excerpt_template,
				'type'     => 'title',
			),
			array(
				'template' => $post_name_template,
				'type'     => 'title',
			),
		);

		foreach ( $template_checks as $check ) {
			if ( '' === $check['template'] ) {
				continue;
			}

			$result = tporapdi_validate_twig_template_security( $check['template'], $check['type'] );
			if ( is_wp_error( $result ) ) {
				return $this->rest_error( $result->get_error_code(), $result->get_error_message(), 400 );
			}
		}

		return true;
	}

	/**
	 * Returns the allowed HTML map for mapping template sanitization.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function get_allowed_mapping_html(): array {
		return array(
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
	}

	/**
	 * Sanitizes filter rule definitions from request payload.
	 *
	 * @param mixed $raw_rules Raw filter rules payload.
	 *
	 * @return string
	 */
	private function sanitize_filter_rules( $raw_rules ): string {
		$filter_rules_json = '[]';

		if ( null === $raw_rules ) {
			return $filter_rules_json;
		}

		$decoded_rules = is_string( $raw_rules ) ? json_decode( $raw_rules, true ) : $raw_rules;
		if ( ! is_array( $decoded_rules ) ) {
			return $filter_rules_json;
		}

		$filter_operator_options = tporapdi_get_filter_operator_options();
		$allowed_operators       = array_keys( $filter_operator_options );
		$sanitized_rules         = array();

		foreach ( $decoded_rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$rk = isset( $rule['key'] ) ? sanitize_text_field( trim( (string) $rule['key'] ) ) : '';
			$ro = isset( $rule['operator'] ) ? sanitize_key( (string) $rule['operator'] ) : '';
			$rv = isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '';

			if ( '' === $rk || ! in_array( $ro, $allowed_operators, true ) ) {
				continue;
			}

			$sanitized_rules[] = array(
				'key'      => $rk,
				'operator' => $ro,
				'value'    => $rv,
			);
		}

		$encoded = wp_json_encode( $sanitized_rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false !== $encoded ) {
			$filter_rules_json = $encoded;
		}

		return $filter_rules_json;
	}

	/**
	 * Sanitizes custom meta mapping definitions from request payload.
	 *
	 * @param mixed $raw_mappings Raw custom meta mappings payload.
	 *
	 * @return string
	 */
	private function sanitize_custom_meta_mappings( $raw_mappings ): string {
		$custom_meta_mappings_json = '[]';

		if ( null === $raw_mappings ) {
			return $custom_meta_mappings_json;
		}

		$decoded_mappings = is_string( $raw_mappings ) ? json_decode( $raw_mappings, true ) : $raw_mappings;
		if ( ! is_array( $decoded_mappings ) ) {
			return $custom_meta_mappings_json;
		}

		$sanitized_mappings = array();
		foreach ( $decoded_mappings as $mapping ) {
			if ( ! is_array( $mapping ) ) {
				continue;
			}

			$mk = isset( $mapping['key'] ) ? sanitize_text_field( trim( (string) $mapping['key'] ) ) : '';
			$mv = isset( $mapping['value'] ) ? sanitize_text_field( (string) $mapping['value'] ) : '';

			if ( '' === $mk ) {
				continue;
			}

			$sanitized_mappings[] = array(
				'key'   => $mk,
				'value' => $mv,
			);
		}

		$encoded_mappings = wp_json_encode( $sanitized_mappings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false !== $encoded_mappings ) {
			$custom_meta_mappings_json = $encoded_mappings;
		}

		return $custom_meta_mappings_json;
	}

	/**
	 * Builds a standard REST error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 *
	 * @return WP_REST_Response
	 */
	private function rest_error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => esc_html( $message ),
			),
			$status
		);
	}
}
