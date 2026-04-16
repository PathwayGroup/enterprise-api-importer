<?php
/**
 * Import processor helpers.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core import processing helpers.
 */
class EAI_Import_Processor {
	/**
	 * Downloads and sideloads one remote image into the media library.
	 *
	 * Idempotency: if an attachment already exists with matching _eapi_source_url,
	 * the existing attachment ID is returned immediately and no new download occurs.
	 *
	 * @param mixed $image_url   Absolute image URL.
	 * @param mixed $post_id     Parent post ID.
	 * @param mixed $is_featured Whether to assign as featured image.
	 *
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public static function sideload_image( $image_url, $post_id, $is_featured = false ) {
		$image_url   = is_string( $image_url ) ? trim( $image_url ) : '';
		$post_id     = absint( $post_id );
		$is_featured = (bool) $is_featured;

		if ( '' === $image_url || $post_id <= 0 || ! wp_http_validate_url( $image_url ) ) {
			self::log_media_error(
				'Invalid Media URL',
				$image_url,
				$post_id,
				__( 'Invalid media URL or post ID supplied for sideload.', 'enterprise-api-importer' )
			);

			return false;
		}

		$source_url = esc_url_raw( $image_url );

		$existing_attachment_query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => '_eapi_source_url',
						'value'   => $source_url,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $existing_attachment_query->posts ) ) {
			return (int) $existing_attachment_query->posts[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $source_url );

		if ( is_wp_error( $temp_file ) ) {
			self::log_media_error(
				'Media Download Error',
				$source_url,
				$post_id,
				$temp_file->get_error_message()
			);

			return false;
		}

		$url_path  = wp_parse_url( $source_url, PHP_URL_PATH );
		$file_name = is_string( $url_path ) ? basename( $url_path ) : '';

		if ( '' === $file_name ) {
			$file_name = 'eapi-image-' . wp_generate_password( 12, false ) . '.jpg';
		}

		$file_array = array(
			'name'     => sanitize_file_name( rawurldecode( $file_name ) ),
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			if ( isset( $file_array['tmp_name'] ) && is_string( $file_array['tmp_name'] ) ) {
				wp_delete_file( $file_array['tmp_name'] );
			}

			self::log_media_error(
				'Media Sideload Error',
				$source_url,
				$post_id,
				$attachment_id->get_error_message()
			);

			return false;
		}

		$attachment_id = (int) $attachment_id;
		update_post_meta( $attachment_id, '_eapi_source_url', $source_url );

		if ( $is_featured ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Parses rendered HTML, sideloads external images, and rewrites image sources to local URLs.
	 *
	 * @param mixed $html_content Rendered HTML content that may contain IMG tags.
	 * @param mixed $post_id      Target post ID used as the parent for sideloaded attachments.
	 *
	 * @return string Updated HTML content with rewritten IMG src attributes where sideloading succeeds.
	 */
	public static function parse_and_sideload_content_images( $html_content, $post_id ) {
		$html_content = is_string( $html_content ) ? $html_content : '';
		$post_id      = absint( $post_id );

		if ( '' === $html_content || $post_id <= 0 || ! class_exists( 'DOMDocument' ) ) {
			return $html_content;
		}

		$dom               = new DOMDocument();
		$previous_internal = libxml_use_internal_errors( true );
		$wrapped_html      = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html_content . '</body></html>';

		$loaded = $dom->loadHTML( $wrapped_html, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );

		if ( false === $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_internal );

			return $html_content;
		}

		$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
		$site_host = is_string( $site_host ) ? strtolower( $site_host ) : '';

		$images = $dom->getElementsByTagName( 'img' );

		foreach ( $images as $image_node ) {
			if ( ! $image_node instanceof DOMElement ) {
				continue;
			}

			$src = trim( (string) $image_node->getAttribute( 'src' ) );

			if ( '' === $src ) {
				continue;
			}

			$src_host = wp_parse_url( $src, PHP_URL_HOST );
			$src_host = is_string( $src_host ) ? strtolower( $src_host ) : '';

			if ( '' === $src_host || ( '' !== $site_host && $src_host === $site_host ) ) {
				continue;
			}

			$attachment_id = self::sideload_image( $src, $post_id );

			if ( false === $attachment_id ) {
				continue;
			}

			$attachment_id = absint( $attachment_id );
			$new_src       = wp_get_attachment_url( $attachment_id );

			if ( ! is_string( $new_src ) || '' === $new_src ) {
				continue;
			}

			$image_node->setAttribute( 'src', esc_url_raw( $new_src ) );

			$class_attr = trim( (string) $image_node->getAttribute( 'class' ) );
			$classes    = '' === $class_attr ? array() : preg_split( '/\s+/', $class_attr );

			if ( ! is_array( $classes ) ) {
				$classes = array();
			}

			$classes  = array_filter( $classes, 'is_string' );
			$wp_class = 'wp-image-' . $attachment_id;

			if ( ! in_array( $wp_class, $classes, true ) ) {
				$classes[] = $wp_class;
			}

			$image_node->setAttribute( 'class', implode( ' ', $classes ) );
		}

		$rewritten_html = $dom->saveHTML();
		$rewritten_html = is_string( $rewritten_html ) ? $rewritten_html : $html_content;

		$rewritten_html = preg_replace( '/\A\s*<!DOCTYPE[^>]*>\s*/i', '', $rewritten_html );
		$rewritten_html = is_string( $rewritten_html ) ? $rewritten_html : $html_content;
		$rewritten_html = preg_replace( '/\A\s*<html[^>]*>\s*<head>.*?<\/head>\s*<body[^>]*>/is', '', $rewritten_html );
		$rewritten_html = is_string( $rewritten_html ) ? $rewritten_html : $html_content;
		$rewritten_html = preg_replace( '/<\/body>\s*<\/html>\s*\z/is', '', $rewritten_html );
		$rewritten_html = is_string( $rewritten_html ) ? $rewritten_html : $html_content;

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_internal );

		return $rewritten_html;
	}

	/**
	 * Runs daily garbage collection for import temp and log tables using chunked deletions.
	 *
	 * Chunking with LIMIT reduces lock duration and helps avoid large-table lock contention.
	 *
	 * @return array<string, mixed> Summary including deleted row counts and any query errors.
	 */
	public static function run_garbage_collection() {
		global $wpdb;

		if ( ! $wpdb instanceof wpdb ) {
			return array(
				'temp_deleted' => 0,
				'logs_deleted' => 0,
				'errors'       => array( 'Database connection is unavailable.' ),
			);
		}

		$temp_table = $wpdb->prefix . 'custom_import_temp';
		$logs_table = $wpdb->prefix . 'custom_import_logs';
		$chunk_size = 1000;

		$temp_deleted_total = 0;
		$logs_deleted_total = 0;
		$errors             = array();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$temp_result  = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE is_processed = 1 OR created_at < ( UTC_TIMESTAMP() - INTERVAL 7 DAY ) LIMIT %d',
					$temp_table,
					$chunk_size
				)
			);
			$temp_deleted = max( 0, (int) $wpdb->rows_affected );

			if ( false === $temp_result ) {
				$errors[] = 'Failed to purge records from custom_import_temp.';
				break;
			}

			$temp_deleted_total += $temp_deleted;
		} while ( $temp_deleted > 0 );

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$logs_result  = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE created_at < ( UTC_TIMESTAMP() - INTERVAL 30 DAY ) LIMIT %d',
					$logs_table,
					$chunk_size
				)
			);
			$logs_deleted = max( 0, (int) $wpdb->rows_affected );

			if ( false === $logs_result ) {
				$errors[] = 'Failed to purge records from custom_import_logs.';
				break;
			}

			$logs_deleted_total += $logs_deleted;
		} while ( $logs_deleted > 0 );

		return array(
			'temp_deleted' => $temp_deleted_total,
			'logs_deleted' => $logs_deleted_total,
			'errors'       => $errors,
		);
	}

	/**
	 * Writes a media-processing error row to the import logs table.
	 *
	 * @param string $status    Log status label.
	 * @param string $image_url Source image URL.
	 * @param int    $post_id   Target post ID.
	 * @param string $message   Error message.
	 *
	 * @return void
	 */
	private static function log_media_error( $status, $image_url, $post_id, $message ) {
		$now        = gmdate( 'Y-m-d H:i:s', time() );
		$run_id     = wp_generate_uuid4();
		$status     = sanitize_text_field( (string) $status );
		$image_url  = esc_url_raw( (string) $image_url );
		$post_id    = absint( $post_id );
		$message    = sanitize_text_field( (string) $message );
		$error_json = wp_json_encode(
			array(
				'media_error' => true,
				'image_url'   => $image_url,
				'post_id'     => $post_id,
				'message'     => $message,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $error_json ) {
			$error_json = '{"media_error":true,"message":"JSON encoding failed"}';
		}

		eai_db_insert_import_log( 0, $run_id, $status, 0, 0, 0, (string) $error_json, $now );
	}
}
