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
class TPORAPDI_Import_Processor {
	/**
	 * Downloads and sideloads one remote image into the media library.
	 *
	 * Idempotency: if an attachment already exists with matching _tporapdi_source_url,
	 * the existing attachment ID is returned immediately and no new download occurs.
	 *
	 * @param mixed $image_url   Absolute image URL.
	 * @param mixed $post_id     Parent post ID.
	 * @param mixed $is_featured Whether to assign as featured image.
	 *
	 * @return int|false Attachment ID on success, false on failure.
	 */
	public static function sideload_image( $image_url, $post_id, $is_featured = false ) {
		return Tporapdi_Media_Ingestor::sideload_image( $image_url, $post_id, $is_featured );
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
		return Tporapdi_Media_Ingestor::parse_and_sideload_content_images( $html_content, $post_id );
	}

	/**
	 * Runs daily garbage collection for import temp and log tables using chunked deletions.
	 *
	 * Chunking with LIMIT reduces lock duration and helps avoid large-table lock contention.
	 *
	 * @return array<string, mixed> Summary including deleted row counts and any query errors.
	 */
	public static function run_garbage_collection() {
		return Tporapdi_Cleanup_Service::run();
	}
}
