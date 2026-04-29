<?php
/**
 * LockPolicy — single seam for import-managed post edit-locking.
 *
 * Every UI affordance (row actions, edit links, cap checks, admin notices)
 * must call Tporapdi_Lock_Policy::is_locked() to decide whether a post is
 * protected. All knowledge about how locking is determined lives here:
 * the meta key, the config column, the "deleted config = unlocked" rule.
 *
 * @package Enterprise_API_Importer
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether a post is edit-locked by an import job.
 */
class Tporapdi_Lock_Policy {

	/**
	 * Returns true when the given post is managed by an import job that has
	 * edit-locking enabled.
	 *
	 * Decision logic (all must hold):
	 *  1. The post carries a non-empty `_tporapdi_import_id` meta value.
	 *  2. The referenced import job exists (not deleted).
	 *  3. The job's `lock_editing` flag is truthy.
	 *
	 * @param int $post_id Post ID to evaluate.
	 *
	 * @return bool True when the post is locked; false otherwise.
	 */
	public static function is_locked( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$raw_import_id = get_post_meta( $post_id, '_tporapdi_import_id', true );

		if ( '' === (string) $raw_import_id ) {
			return false;
		}

		$import_id = (int) $raw_import_id;
		if ( $import_id <= 0 ) {
			return false;
		}

		$config = Tporapdi_Job_Repository::find( $import_id );

		// Import config deleted — treat the post as unlocked.
		if ( ! is_array( $config ) ) {
			return false;
		}

		return ! empty( $config['lock_editing'] );
	}
}
