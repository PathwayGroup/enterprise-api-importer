<?php
/**
 * Content registration.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the tporapdi_item custom post type with conservative visibility.
 */
function tporapdi_register_imported_item_cpt() {
	$labels = array(
		'name'          => __( 'Imported Items', 'tporret-api-data-importer' ),
		'singular_name' => __( 'Imported Item', 'tporret-api-data-importer' ),
		'menu_name'     => __( 'Imported Items', 'tporret-api-data-importer' ),
		'add_new_item'  => __( 'Add Imported Item', 'tporret-api-data-importer' ),
		'edit_item'     => __( 'Edit Imported Item', 'tporret-api-data-importer' ),
		'view_item'     => __( 'View Imported Item', 'tporret-api-data-importer' ),
		'not_found'     => __( 'No imported items found.', 'tporret-api-data-importer' ),
	);

	register_post_type(
		'tporapdi_item',
		array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'exclude_from_search' => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'has_archive'         => true,
			'rewrite'             => array(
				'slug'       => 'imported-item',
				'with_front' => false,
			),
			'query_var'           => true,
			'map_meta_cap'        => true,
			'capability_type'     => 'post',
			'capabilities'        => array(
				'create_posts' => 'do_not_allow',
			),
			'menu_icon'           => 'dashicons-database-import',
			'supports'            => array( 'title', 'editor', 'custom-fields' ),
			'delete_with_user'    => false,
		)
	);
}
add_action( 'init', 'tporapdi_register_imported_item_cpt' );

/**
 * Determines whether a post is an imported item whose import job has editing locked.
 *
 * Checks any post type — returns true when the post carries an `_tporapdi_import_id`
 * meta value whose corresponding import configuration has `lock_editing` enabled.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function tporapdi_is_managed_imported_item( $post_id ) {
	$import_id = get_post_meta( $post_id, '_tporapdi_import_id', true );

	if ( '' === (string) $import_id ) {
		return false;
	}

	$import_id = (int) $import_id;
	if ( $import_id <= 0 ) {
		return false;
	}

	$config = tporapdi_db_get_import_config( $import_id );
	if ( ! is_array( $config ) ) {
		// Import configuration deleted — treat post as unlocked.
		return false;
	}

	return ! empty( $config['lock_editing'] );
}

/**
 * Prevents editing and deleting plugin-managed imported items.
 *
 * @param string[] $caps Primitive caps for meta cap checks.
 * @param string   $cap  Meta capability being checked.
 * @param int      $user_id User ID.
 * @param mixed[]  $args Optional args for the capability check.
 * @return string[]
 */
function tporapdi_lock_managed_imported_items_caps( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) ) {
		return $caps;
	}

	if ( empty( $args[0] ) ) {
		return $caps;
	}

	$post_id = (int) $args[0];

	if ( $post_id > 0 && tporapdi_is_managed_imported_item( $post_id ) ) {
		return array( 'do_not_allow' );
	}

	return $caps;
}
add_filter( 'map_meta_cap', 'tporapdi_lock_managed_imported_items_caps', 10, 4 );

/**
 * Removes edit affordances from managed imported items in the list table.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Current post object.
 * @return array
 */
function tporapdi_filter_managed_imported_item_row_actions( $actions, $post ) {
	if ( ! $post instanceof WP_Post || ! tporapdi_is_managed_imported_item( $post->ID ) ) {
		return $actions;
	}

	unset( $actions['edit'] );
	unset( $actions['inline hide-if-no-js'] );
	unset( $actions['trash'] );

	return $actions;
}
add_filter( 'post_row_actions', 'tporapdi_filter_managed_imported_item_row_actions', 10, 2 );

/**
 * Removes edit links for managed imported items to avoid dead-end edit screens.
 *
 * @param string $link    Edit link.
 * @param int    $post_id Post ID.
 * @param string $_context Link context.
 * @return string
 */
function tporapdi_filter_managed_imported_item_edit_link( $link, $post_id, $_context ) {
	unset( $_context );

	if ( tporapdi_is_managed_imported_item( $post_id ) ) {
		return '';
	}

	return $link;
}
add_filter( 'get_edit_post_link', 'tporapdi_filter_managed_imported_item_edit_link', 10, 3 );

/**
 * Shows a read-only notice when viewing a list of import-locked posts.
 */
function tporapdi_render_imported_items_read_only_notice() {
	$screen = get_current_screen();

	if ( ! $screen || 'edit' !== $screen->base ) {
		return;
	}

	// Quick check: does this post type have any import-locked posts?
	// The tporapdi_item CPT is always relevant; for other types check meta existence.
	if ( 'tporapdi_item' !== $screen->post_type ) {
		$has_locked = get_posts(
			array(
				'post_type'      => $screen->post_type,
				'meta_key'       => '_tporapdi_import_id',
				'meta_compare'   => 'EXISTS',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		if ( empty( $has_locked ) ) {
			return;
		}
	}

	echo '<div class="notice notice-info"><p>';
	echo esc_html__( 'Some items on this screen are managed by tporret API Data Importer and may be read-only.', 'tporret-api-data-importer' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'tporapdi_render_imported_items_read_only_notice' );
