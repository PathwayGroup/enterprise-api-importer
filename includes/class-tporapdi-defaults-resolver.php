<?php
/**
 * Post Type Defaults Resolver
 *
 * @package TPORAPDI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and validates post defaults for a given post type.
 */
class TPORAPDI_Defaults_Resolver {

	/**
	 * Normalizes raw post-field values against the registered capabilities of a post type.
	 *
	 * This is the single seam for all "what are valid field values for this post type" logic.
	 * Both the REST save path (via Validator) and the import runtime (via import.php) must
	 * call this method — callers receive clean, ready-to-use values and never need to know
	 * the allowed lists or per-type compatibility rules themselves.
	 *
	 * Normalization rules applied:
	 *  - post_status  : must be one of draft|publish|pending|private|scheduled|future; falls back to 'draft'
	 *  - comment_status: forced to 'closed' when the post type does not support comments
	 *  - ping_status  : forced to 'closed' when the post type does not support trackbacks
	 *
	 * @param string $post_type Post type slug. Falls back to 'post' when invalid or 'attachment'.
	 * @param array  $raw_input Associative array that may contain post_status, comment_status,
	 *                          and/or ping_status keys with raw/unsanitized values.
	 *
	 * @return array{post_status: string, comment_status: string, ping_status: string}
	 */
	public static function normalize( string $post_type, array $raw_input ): array {
		if ( '' === $post_type || 'attachment' === $post_type || ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}

		$post_type_obj = get_post_type_object( $post_type );

		$post_status    = isset( $raw_input['post_status'] ) ? sanitize_key( (string) $raw_input['post_status'] ) : 'draft';
		$comment_status = isset( $raw_input['comment_status'] ) ? sanitize_key( (string) $raw_input['comment_status'] ) : 'closed';
		$ping_status    = isset( $raw_input['ping_status'] ) ? sanitize_key( (string) $raw_input['ping_status'] ) : 'closed';

		$post_status = self::validate_post_status( $post_status );

		if ( $post_type_obj ) {
			$comment_status = self::validate_comment_status( $comment_status, $post_type_obj );
			$ping_status    = self::validate_ping_status( $ping_status, $post_type_obj );
		} else {
			$comment_status = in_array( $comment_status, array( 'open', 'closed' ), true ) ? $comment_status : 'closed';
			$ping_status    = in_array( $ping_status, array( 'open', 'closed' ), true ) ? $ping_status : 'closed';
		}

		return array(
			'post_status'    => $post_status,
			'comment_status' => $comment_status,
			'ping_status'    => $ping_status,
		);
	}

	/**
	 * Get defaults for a given post type.
	 *
	 * Returns a full post object with defaults that are compatible with the post type's
	 * capabilities and supports. Validates that all defaults are appropriate for the post type.
	 *
	 * @param string $post_type Post type name.
	 * @return array|WP_Error Post defaults array or WP_Error if invalid post type.
	 */
	public static function get_defaults_for_post_type( $post_type ) {
		// Validate post type exists.
		if ( ! post_type_exists( $post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				sprintf( 'Post type "%s" does not exist.', sanitize_key( $post_type ) )
			);
		}

		$post_type_obj = get_post_type_object( $post_type );
		if ( ! $post_type_obj ) {
			return new WP_Error(
				'post_type_object_error',
				sprintf( 'Could not retrieve post type object for "%s".', sanitize_key( $post_type ) )
			);
		}

		// Build defaults array inspecting post type supports.
		$defaults = array(
			'post_type'           => $post_type,
			'post_status'         => self::resolve_post_status( $post_type_obj ),
			'post_author'         => get_current_user_id(),
			'post_title'          => '',
			'post_content'        => '',
			'post_excerpt'        => '',
			'post_name'           => '',
			'comment_status'      => self::resolve_comment_status( $post_type_obj ),
			'ping_status'         => self::resolve_ping_status( $post_type_obj ),
			'supports_comments'   => post_type_supports( $post_type, 'comments' ),
			'supports_trackbacks' => post_type_supports( $post_type, 'trackbacks' ),
			'menu_order'          => 0,
		);

		// Add parent field if post type is hierarchical.
		if ( $post_type_obj->hierarchical ) {
			$defaults['post_parent'] = 0;
		}

		// Add post_date if we want to expose it (future phase).
		// For now, omit it to avoid timezone complexity.

		/**
		 * Filter post type defaults before validation.
		 *
		 * @param array  $defaults      Post defaults array.
		 * @param object $post_type_obj Post type object.
		 */
		$defaults = apply_filters( 'tporapdi_post_defaults', $defaults, $post_type_obj );

		// Validate and sanitize defaults are compatible with post type.
		$validated = self::validate_defaults( $defaults, $post_type_obj );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $validated;
	}

	/**
	 * Resolve post_status default based on post type supports.
	 *
	 * @param object $post_type_obj Post type object.
	 * @return string Default post status.
	 */
	private static function resolve_post_status( $post_type_obj ) {
		// Check if post type is publicly queryable or has UI.
		if ( $post_type_obj->public || $post_type_obj->show_ui ) {
			// Default to draft for safety during import.
			return 'draft';
		}

		// Private post types default to publish (won't show publicly anyway).
		return 'publish';
	}

	/**
	 * Resolve comment_status default based on post type supports.
	 *
	 * @param object $post_type_obj Post type object.
	 * @return string Default comment status.
	 */
	private static function resolve_comment_status( $post_type_obj ) {
		// If post type doesn't support comments, default to closed.
		if ( isset( $post_type_obj->supports ) && is_array( $post_type_obj->supports ) ) {
			if ( ! in_array( 'comments', $post_type_obj->supports, true ) ) {
				return 'closed';
			}
		}

		// Default to closed for safety during import.
		return 'closed';
	}

	/**
	 * Resolve ping_status default based on post type supports.
	 *
	 * @param object $post_type_obj Post type object.
	 * @return string Default ping status.
	 */
	private static function resolve_ping_status( $post_type_obj ) {
		// If post type doesn't support trackbacks, default to closed.
		if ( isset( $post_type_obj->supports ) && is_array( $post_type_obj->supports ) ) {
			if ( ! in_array( 'trackbacks', $post_type_obj->supports, true ) ) {
				return 'closed';
			}
		}

		// Default to closed for safety during import.
		return 'closed';
	}

	/**
	 * Validate and sanitize defaults against post type capabilities.
	 *
	 * @param array  $defaults      Post defaults array.
	 * @param object $post_type_obj Post type object.
	 * @return array|WP_Error Sanitized defaults or WP_Error on validation failure.
	 */
	private static function validate_defaults( $defaults, $post_type_obj ) {
		$validated = array();

		// Validate post_type.
		if ( ! isset( $defaults['post_type'] ) || $defaults['post_type'] !== $post_type_obj->name ) {
			return new WP_Error(
				'invalid_post_type_in_defaults',
				'Post type mismatch in defaults.'
			);
		}
		$validated['post_type'] = sanitize_key( $defaults['post_type'] );

		// Validate post_status is allowed for this post type.
		$validated['post_status'] = self::validate_post_status( $defaults['post_status'] ?? 'draft' );

		// Validate comment_status.
		$validated['comment_status'] = self::validate_comment_status(
			$defaults['comment_status'] ?? 'closed',
			$post_type_obj
		);

		// Validate ping_status.
		$validated['ping_status'] = self::validate_ping_status(
			$defaults['ping_status'] ?? 'closed',
			$post_type_obj
		);

		// Validate post_author.
		$validated['post_author'] = absint( $defaults['post_author'] ?? 0 );

		// Validate text fields.
		$validated['post_title']   = sanitize_text_field( $defaults['post_title'] ?? '' );
		$validated['post_content'] = wp_kses_post( $defaults['post_content'] ?? '' );
		$validated['post_excerpt'] = sanitize_textarea_field( $defaults['post_excerpt'] ?? '' );
		$validated['post_name']    = sanitize_title( $defaults['post_name'] ?? '' );

		// Validate menu_order.
		$validated['menu_order'] = absint( $defaults['menu_order'] ?? 0 );

		// Validate post_parent (only if hierarchical).
		if ( $post_type_obj->hierarchical ) {
			$post_parent = absint( $defaults['post_parent'] ?? 0 );
			// Don't validate parent exists here; that's for runtime.
			$validated['post_parent'] = $post_parent;
		}

		$validated['supports_comments']   = post_type_supports( $post_type_obj->name, 'comments' );
		$validated['supports_trackbacks'] = post_type_supports( $post_type_obj->name, 'trackbacks' );

		return $validated;
	}

	/**
	 * Validate post_status is in allowed list.
	 *
	 * @param string $status Post status to validate.
	 * @return string Validated status or safe fallback.
	 */
	private static function validate_post_status( $status ) {
		$allowed = array( 'draft', 'publish', 'pending', 'private', 'scheduled', 'future' );
		$status  = sanitize_key( $status );

		if ( in_array( $status, $allowed, true ) ) {
			return $status;
		}

		return 'draft';
	}

	/**
	 * Validate comment_status is allowed and applicable to post type.
	 *
	 * @param string $status         Comment status to validate.
	 * @param object $post_type_obj  Post type object.
	 * @return string Validated status or safe fallback.
	 */
	private static function validate_comment_status( $status, $post_type_obj ) {
		$allowed = array( 'open', 'closed' );
		$status  = sanitize_key( $status );

		if ( ! in_array( $status, $allowed, true ) ) {
			return 'closed';
		}

		// If post type doesn't support comments, force closed.
		if ( isset( $post_type_obj->supports ) && is_array( $post_type_obj->supports ) ) {
			if ( ! in_array( 'comments', $post_type_obj->supports, true ) ) {
				return 'closed';
			}
		}

		return $status;
	}

	/**
	 * Validate ping_status is allowed and applicable to post type.
	 *
	 * @param string $status         Ping status to validate.
	 * @param object $post_type_obj  Post type object.
	 * @return string Validated status or safe fallback.
	 */
	private static function validate_ping_status( $status, $post_type_obj ) {
		$allowed = array( 'open', 'closed' );
		$status  = sanitize_key( $status );

		if ( ! in_array( $status, $allowed, true ) ) {
			return 'closed';
		}

		// If post type doesn't support trackbacks, force closed.
		if ( isset( $post_type_obj->supports ) && is_array( $post_type_obj->supports ) ) {
			if ( ! in_array( 'trackbacks', $post_type_obj->supports, true ) ) {
				return 'closed';
			}
		}

		return $status;
	}
}
