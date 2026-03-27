<?php
/**
 * Imports List Table.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Displays import jobs in wp-admin.
 */
class EAI_Imports_List_Table extends WP_List_Table {
	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'eapi_import',
				'plural'   => 'eapi_imports',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Returns table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'id'       => __( 'ID', 'enterprise-api-importer' ),
			'name'     => __( 'Name', 'enterprise-api-importer' ),
			'endpoint' => __( 'Endpoint', 'enterprise-api-importer' ),
			'actions'  => __( 'Actions', 'enterprise-api-importer' ),
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param array<string, mixed> $item        Row data.
	 * @param string               $column_name Column key.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'id':
				return isset( $item['id'] ) ? (string) absint( $item['id'] ) : '';
			case 'name':
				return isset( $item['name'] ) ? esc_html( (string) $item['name'] ) : '';
			case 'endpoint':
				return isset( $item['endpoint_url'] ) ? esc_html( (string) $item['endpoint_url'] ) : '';
			case 'actions':
				return $this->render_actions_column( $item );
			default:
				return '';
		}
	}

	/**
	 * Prepares items for rendering.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page      = 20;
		$current_page  = $this->get_pagenum();
		$offset        = ( $current_page - 1 ) * $per_page;
		$all_configs   = eai_db_get_import_configs();
		$total_items   = count( $all_configs );

		$this->items = array_slice( $all_configs, $offset, $per_page );

		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Renders action links.
	 *
	 * @param array<string, mixed> $item Row data.
	 *
	 * @return string
	 */
	private function render_actions_column( $item ) {
		$import_id = isset( $item['id'] ) ? absint( $item['id'] ) : 0;
		if ( $import_id <= 0 ) {
			return '';
		}

		$edit_url = add_query_arg(
			array(
				'page'   => 'eapi-manage',
				'action' => 'edit',
				'id'     => $import_id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'eai_delete_import',
					'import_id' => $import_id,
				),
				admin_url( 'admin-post.php' )
			),
			'eai_delete_import_' . $import_id,
			'eai_delete_nonce'
		);

		$edit_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $edit_url ),
			esc_html__( 'Edit', 'enterprise-api-importer' )
		);

		$delete_link = sprintf(
			'<a href="%s" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( 'Are you sure you want to delete this import job?', 'enterprise-api-importer' ) ),
			esc_html__( 'Delete', 'enterprise-api-importer' )
		);

		return $edit_link . ' | ' . $delete_link;
	}
}
