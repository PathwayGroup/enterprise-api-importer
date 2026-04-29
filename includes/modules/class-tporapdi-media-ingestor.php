<?php
/**
 * Media Ingestor – sideload and deduplication for import media.
 *
 * Single responsibility: download remote images into the media library,
 * deduplicate by source URL, rewrite HTML content image sources in-place.
 * All callers ask "ingest this image" – no SQL, no DOM parsing leaks out.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encapsulates every media-ingestion concern for the import pipeline.
 *
 * Idempotency guarantee: if an attachment already exists with a matching
 * `_tporapdi_source_url` meta value, the existing ID is returned immediately
 * and no re-download occurs.
 */
class Tporapdi_Media_Ingestor {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Downloads and sideloads one remote image into the media library.
	 *
	 * @param mixed $image_url   Absolute remote image URL.
	 * @param mixed $post_id     Parent post ID.
	 * @param mixed $is_featured Whether to assign the image as the post featured image.
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
				__( 'Invalid media URL or post ID supplied for sideload.', 'tporret-api-data-importer' )
			);

			return false;
		}

		$source_url = esc_url_raw( $image_url );

		// Deduplication: return existing attachment if source URL already ingested.
		$existing = new WP_Query(
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
						'key'     => '_tporapdi_source_url',
						'value'   => $source_url,
						'compare' => '=',
					),
				),
			)
		);

		if ( ! empty( $existing->posts ) ) {
			return (int) $existing->posts[0];
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
		update_post_meta( $attachment_id, '_tporapdi_source_url', $source_url );

		if ( $is_featured ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Parses rendered HTML, sideloads external images, and rewrites img src attributes.
	 *
	 * External images whose src does not belong to the current site host are downloaded
	 * and replaced with local attachment URLs. The returned string is safe to store as
	 * post_content.
	 *
	 * @param mixed $html_content Rendered HTML that may contain external IMG tags.
	 * @param mixed $post_id      Target post ID used as attachment parent.
	 *
	 * @return string Updated HTML with local image URLs where ingestion succeeded.
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
		$loaded            = $dom->loadHTML( $wrapped_html, LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED );

		if ( false === $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_internal );

			return $html_content;
		}

		$site_host = wp_parse_url( site_url(), PHP_URL_HOST );
		$site_host = is_string( $site_host ) ? strtolower( $site_host ) : '';
		$images    = $dom->getElementsByTagName( 'img' );

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

			// Skip relative URLs and images already hosted locally.
			if ( '' === $src_host || ( '' !== $site_host && $src_host === $site_host ) ) {
				continue;
			}

			$attachment_id = self::sideload_image( $src, $post_id );

			if ( false === $attachment_id ) {
				continue;
			}

			$new_src = wp_get_attachment_url( absint( $attachment_id ) );

			if ( ! is_string( $new_src ) || '' === $new_src ) {
				continue;
			}

			$image_node->setAttribute( 'src', esc_url_raw( $new_src ) );

			// Add wp-image-{id} CSS class for block editor compatibility.
			$class_attr = trim( (string) $image_node->getAttribute( 'class' ) );
			$classes    = '' === $class_attr ? array() : preg_split( '/\s+/', $class_attr );

			if ( ! is_array( $classes ) ) {
				$classes = array();
			}

			$classes  = array_filter( $classes, 'is_string' );
			$wp_class = 'wp-image-' . absint( $attachment_id );

			if ( ! in_array( $wp_class, $classes, true ) ) {
				$classes[] = $wp_class;
			}

			$image_node->setAttribute( 'class', implode( ' ', $classes ) );
		}

		$rewritten = $dom->saveHTML();
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;
		$rewritten = preg_replace( '/\A\s*<!DOCTYPE[^>]*>\s*/i', '', $rewritten );
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;
		$rewritten = preg_replace( '/\A\s*<html[^>]*>\s*<head>.*?<\/head>\s*<body[^>]*>/is', '', $rewritten );
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;
		$rewritten = preg_replace( '/<\/body>\s*<\/html>\s*\z/is', '', $rewritten );
		$rewritten = is_string( $rewritten ) ? $rewritten : $html_content;

		libxml_clear_errors();
		libxml_use_internal_errors( $previous_internal );

		return $rewritten;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

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
	private static function log_media_error( string $status, string $image_url, int $post_id, string $message ): void {
		$now        = gmdate( 'Y-m-d H:i:s', time() );
		$run_id     = wp_generate_uuid4();
		$error_json = wp_json_encode(
			array(
				'media_error' => true,
				'image_url'   => esc_url_raw( $image_url ),
				'post_id'     => $post_id,
				'message'     => sanitize_text_field( $message ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $error_json ) {
			$error_json = '{"media_error":true,"message":"JSON encoding failed"}';
		}

		Tporapdi_Log_Repository::insert( 0, $run_id, sanitize_text_field( $status ), 0, 0, 0, (string) $error_json, $now );
	}
}
