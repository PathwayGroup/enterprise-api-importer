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
			'status'   => __( 'Status', 'enterprise-api-importer' ),
			'health'   => __( 'Endpoint', 'enterprise-api-importer' ),
			'endpoint' => __( 'API URL', 'enterprise-api-importer' ),
			'trend'    => __( 'Trend (Blue = Created, Teal = Updated)', 'enterprise-api-importer' ),
			'actions'  => __( 'Actions', 'enterprise-api-importer' ),
		);
	}

	/**
	 * Returns sortable columns.
	 *
	 * @return array<string, array<int, string|bool>>
	 */
	protected function get_sortable_columns() {
		return array(
			'id'       => array( 'id', true ),
			'name'     => array( 'name', false ),
			'status'   => array( 'status', false ),
			'endpoint' => array( 'endpoint', false ),
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
			case 'status':
				return $this->render_status_column( $item );
			case 'health':
				return $this->render_health_column( $item );
			case 'endpoint':
				return isset( $item['endpoint_url'] ) ? esc_html( (string) $item['endpoint_url'] ) : '';
			case 'trend':
				return $this->render_trend_column( $item );
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
		$latest_logs   = eai_db_get_latest_logs_indexed_by_import_id();
		$pending_index = eai_db_get_pending_counts_by_import_id();
		$trend_index   = eai_db_get_recent_import_log_trends( 12 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting parameters.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) wp_unslash( $_GET['orderby'] ) ) : 'id';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting parameters.
		$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( (string) wp_unslash( $_GET['order'] ) ) ) : 'desc';

		foreach ( $all_configs as &$config ) {
			$import_id = isset( $config['id'] ) ? absint( $config['id'] ) : 0;
			$pending   = isset( $pending_index[ $import_id ] ) ? (int) $pending_index[ $import_id ] : 0;
			$latest    = ( $import_id > 0 && isset( $latest_logs[ $import_id ] ) ) ? $latest_logs[ $import_id ] : array();

			$status = 'idle';
			if ( $pending > 0 ) {
				$status = 'processing';
			} elseif ( isset( $latest['status'] ) && '' !== (string) $latest['status'] ) {
				$status = strtolower( (string) $latest['status'] );
			}

			$error_count = 0;
			if ( isset( $latest['errors'] ) && '' !== (string) $latest['errors'] ) {
				$decoded_errors = json_decode( (string) $latest['errors'], true );
				if ( is_array( $decoded_errors ) && isset( $decoded_errors['processing_errors'] ) && is_array( $decoded_errors['processing_errors'] ) ) {
					$error_count = count( $decoded_errors['processing_errors'] );
				} elseif ( ! empty( $latest['errors'] ) ) {
					$error_count = 1;
				}
			}

			$config['computed_status']      = $status;
			$config['last_status']          = isset( $latest['status'] ) ? (string) $latest['status'] : '';
			$config['pending_count']        = $pending;
			$config['error_count']          = $error_count;
			$config['rows_created']         = isset( $latest['rows_created'] ) ? (int) $latest['rows_created'] : 0;
			$config['rows_updated']         = isset( $latest['rows_updated'] ) ? (int) $latest['rows_updated'] : 0;
			$config['trend_points']         = isset( $trend_index[ $import_id ] ) ? $trend_index[ $import_id ] : array();
		}
		unset( $config );

		$allowed_orderby = array( 'id', 'name', 'status', 'endpoint' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'id';
		}

		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'desc';
		}

		usort(
			$all_configs,
			static function ( $left, $right ) use ( $orderby, $order ) {
				$left_value  = '';
				$right_value = '';

				if ( 'id' === $orderby ) {
					$left_value  = isset( $left['id'] ) ? (int) $left['id'] : 0;
					$right_value = isset( $right['id'] ) ? (int) $right['id'] : 0;
					$comparison  = $left_value <=> $right_value;
				} elseif ( 'status' === $orderby ) {
					$left_value  = isset( $left['computed_status'] ) ? (string) $left['computed_status'] : '';
					$right_value = isset( $right['computed_status'] ) ? (string) $right['computed_status'] : '';
					$comparison  = strcasecmp( $left_value, $right_value );
				} elseif ( 'endpoint' === $orderby ) {
					$left_value  = isset( $left['endpoint_url'] ) ? (string) $left['endpoint_url'] : '';
					$right_value = isset( $right['endpoint_url'] ) ? (string) $right['endpoint_url'] : '';
					$comparison  = strcasecmp( $left_value, $right_value );
				} else {
					$left_value  = isset( $left['name'] ) ? (string) $left['name'] : '';
					$right_value = isset( $right['name'] ) ? (string) $right['name'] : '';
					$comparison  = strcasecmp( $left_value, $right_value );
				}

				return 'asc' === $order ? $comparison : -1 * $comparison;
			}
		);

		$total_items   = count( $all_configs );

		$this->items = array_slice( $all_configs, $offset, $per_page );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Renders status badge column.
	 *
	 * @param array<string, mixed> $item Row data.
	 *
	 * @return string
	 */
	private function render_status_column( $item ) {
		$status = isset( $item['computed_status'] ) ? (string) $item['computed_status'] : 'idle';
		$badge  = eai_get_status_badge_data( $status );

		return '<span class="' . esc_attr( $badge['class'] ) . '">' . esc_html( $badge['label'] ) . '</span>';
	}

	/**
	 * Renders endpoint health chip column.
	 *
	 * @param array<string, mixed> $item Row data.
	 *
	 * @return string
	 */
	private function render_health_column( $item ) {
		$status      = isset( $item['computed_status'] ) ? strtolower( (string) $item['computed_status'] ) : 'idle';
		$error_count = isset( $item['error_count'] ) ? (int) $item['error_count'] : 0;
		$pending     = isset( $item['pending_count'] ) ? (int) $item['pending_count'] : 0;

		$label = __( 'Healthy', 'enterprise-api-importer' );
		$class = 'eapi-health-chip is-good';

		if ( $error_count > 0 || in_array( $status, array( 'failed', 'completed_with_errors', 'template syntax error' ), true ) ) {
			$label = __( 'Degraded', 'enterprise-api-importer' );
			$class = 'eapi-health-chip is-bad';
		} elseif ( $pending > 0 || 'processing' === $status ) {
			$label = __( 'Active', 'enterprise-api-importer' );
			$class = 'eapi-health-chip is-warn';
		}

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Renders compact created/updated trend bars.
	 *
	 * @param array<string, mixed> $item Row data.
	 *
	 * @return string
	 */
	private function render_trend_column( $item ) {
		$points = isset( $item['trend_points'] ) && is_array( $item['trend_points'] ) ? $item['trend_points'] : array();

		if ( empty( $points ) ) {
			return '<span class="eapi-trend-empty">' . esc_html__( 'No history', 'enterprise-api-importer' ) . '</span>';
		}

		$max_value = 1;
		foreach ( $points as $point ) {
			$created = isset( $point['created'] ) ? (int) $point['created'] : 0;
			$updated = isset( $point['updated'] ) ? (int) $point['updated'] : 0;
			$max_value = max( $max_value, $created, $updated );
		}

		$html = '<div class="eapi-sparkline" aria-label="' . esc_attr__( 'Created and updated trend', 'enterprise-api-importer' ) . '">';

		foreach ( $points as $point ) {
			$created = isset( $point['created'] ) ? max( 0, (int) $point['created'] ) : 0;
			$updated = isset( $point['updated'] ) ? max( 0, (int) $point['updated'] ) : 0;

			$created_height = max( 2, (int) round( ( $created / $max_value ) * 22 ) );
			$updated_height = max( 2, (int) round( ( $updated / $max_value ) * 22 ) );

			$html .= '<span class="eapi-spark-pair">';
			$html .= '<span class="eapi-mini-bar is-created" style="height:' . esc_attr( (string) $created_height ) . 'px"></span>';
			$html .= '<span class="eapi-mini-bar is-updated" style="height:' . esc_attr( (string) $updated_height ) . 'px"></span>';
			$html .= '</span>';
		}

		$html .= '</div>';

		return $html;
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
			'<a class="eapi-action-btn is-edit" href="%s">%s</a>',
			esc_url( $edit_url ),
			esc_html__( 'Edit', 'enterprise-api-importer' )
		);

		$delete_link = sprintf(
			'<a class="eapi-action-btn is-delete" href="%s" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( $delete_url ),
			esc_js( __( 'Are you sure you want to delete this import job?', 'enterprise-api-importer' ) ),
			esc_html__( 'Delete', 'enterprise-api-importer' )
		);

		return $edit_link . ' ' . $delete_link;
	}
}
