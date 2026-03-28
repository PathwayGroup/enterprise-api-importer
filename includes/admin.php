<?php
/**
 * Admin job manager UI and handlers.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
exit;
}

/**
 * Registers admin menu pages.
 */
function eai_add_admin_pages() {
add_menu_page(
__( 'Enterprise API Importer', 'enterprise-api-importer' ),
__( 'EAPI', 'enterprise-api-importer' ),
'manage_options',
'eapi-manage',
'eai_render_manage_imports_page',
'dashicons-database-import',
58
);

add_submenu_page(
'eapi-manage',
__( 'Manage Imports', 'enterprise-api-importer' ),
__( 'Manage Imports', 'enterprise-api-importer' ),
'manage_options',
'eapi-manage',
'eai_render_manage_imports_page'
);

add_submenu_page(
'eapi-manage',
__( 'Schedules', 'enterprise-api-importer' ),
__( 'Schedules', 'enterprise-api-importer' ),
'manage_options',
'eapi-schedules',
'eai_render_schedules_page'
);
}
add_action( 'admin_menu', 'eai_add_admin_pages' );

/**
 * Registers admin post handlers.
 */
function eai_register_admin_post_handlers() {
add_action( 'admin_post_eai_save_import', 'eai_handle_save_import' );
add_action( 'admin_post_eai_delete_import', 'eai_handle_delete_import' );
add_action( 'admin_post_eai_run_import', 'eai_handle_manual_import_run' );
add_action( 'admin_post_eai_test_import_endpoint', 'eai_handle_test_import_endpoint' );
add_action( 'admin_post_eai_schedule_run_now', 'eai_handle_schedule_run_now_action' );
}
add_action( 'admin_init', 'eai_register_admin_post_handlers' );

/**
 * Renders admin notices for this plugin.
 */
function eai_render_admin_notices() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code for admin UI messaging.
$notice_code = isset( $_GET['eai_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['eai_notice'] ) ) : '';
if ( '' === $notice_code ) {
return;
}

$messages = array(
'import_saved'   => array( 'success', __( 'Import job saved.', 'enterprise-api-importer' ) ),
'import_deleted' => array( 'success', __( 'Import job deleted.', 'enterprise-api-importer' ) ),
'import_started' => array( 'success', __( 'Import queued successfully.', 'enterprise-api-importer' ) ),
'template_sync_started' => array( 'success', __( 'Template sync started. Existing imported items will be re-rendered for this import job.', 'enterprise-api-importer' ) ),
'schedule_now'   => array( 'success', __( 'Import scheduled to run now.', 'enterprise-api-importer' ) ),
'import_error'   => array( 'error', __( 'The import request failed. Please review your inputs and try again.', 'enterprise-api-importer' ) ),
'schedule_error' => array( 'error', __( 'Unable to schedule this import right now.', 'enterprise-api-importer' ) ),
);

if ( ! isset( $messages[ $notice_code ] ) ) {
return;
}

$type    = $messages[ $notice_code ][0];
$message = $messages[ $notice_code ][1];
$class   = 'success' === $type ? 'notice notice-success is-dismissible' : 'notice notice-error';

echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Renders manage imports page.
 */
function eai_render_manage_imports_page() {
if ( ! current_user_can( 'manage_options' ) ) {
return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing state for admin screen rendering.
$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';

if ( 'edit' === $action ) {
eai_render_import_edit_page();
return;
}

eai_render_imports_list_page();
}

/**
 * Renders list view using WP_List_Table.
 */
function eai_render_imports_list_page() {
$list_table = new EAI_Imports_List_Table();
$list_table->prepare_items();
	$ownership_counts = eai_get_import_post_ownership_counts();
	$total_owned_posts = 0;
	foreach ( $ownership_counts as $ownership_row ) {
		$total_owned_posts += isset( $ownership_row['post_count'] ) ? (int) $ownership_row['post_count'] : 0;
	}

$new_url = add_query_arg(
array(
'page'   => 'eapi-manage',
'action' => 'edit',
),
admin_url( 'admin.php' )
);

$active_state   = eai_get_active_run_state();
?>
<div class="wrap">
<h1 class="wp-heading-inline"><?php esc_html_e( 'Manage Imports', 'enterprise-api-importer' ); ?></h1>
<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'enterprise-api-importer' ); ?></a>
<hr class="wp-header-end" />
<?php eai_render_admin_notices(); ?>

<?php if ( ! empty( $active_state['run_id'] ) ) : ?>
<div class="notice notice-info"><p>
<?php
echo esc_html(
sprintf(
/* translators: 1: run ID, 2: import ID. */
__( 'Active queue run %1$s for import #%2$d.', 'enterprise-api-importer' ),
(string) $active_state['run_id'],
isset( $active_state['import_id'] ) ? (int) $active_state['import_id'] : 0
)
);
?>
</p></div>
<?php endif; ?>

<form method="post">
<?php $list_table->display(); ?>
</form>

<hr />
<h2><?php esc_html_e( 'Debug: Imported Post Ownership', 'enterprise-api-importer' ); ?></h2>
<p>
	<?php
	echo esc_html(
		sprintf(
			/* translators: %d is total number of imported posts linked to any import job via _eai_import_id meta. */
			__( 'Total imported posts linked to import jobs (_eai_import_id): %d', 'enterprise-api-importer' ),
			(int) $total_owned_posts
		)
	);
	?>
</p>

<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Import ID Meta Value', 'enterprise-api-importer' ); ?></th>
			<th><?php esc_html_e( 'Matched Import Job', 'enterprise-api-importer' ); ?></th>
			<th><?php esc_html_e( 'Imported Post Count', 'enterprise-api-importer' ); ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( empty( $ownership_counts ) ) : ?>
		<tr>
			<td colspan="3"><?php esc_html_e( 'No imported posts with _eai_import_id found.', 'enterprise-api-importer' ); ?></td>
		</tr>
	<?php else : ?>
		<?php foreach ( $ownership_counts as $ownership_row ) : ?>
			<tr>
				<td><?php echo esc_html( isset( $ownership_row['import_id_raw'] ) ? (string) $ownership_row['import_id_raw'] : '' ); ?></td>
				<td>
					<?php
					echo esc_html( isset( $ownership_row['import_name'] ) ? (string) $ownership_row['import_name'] : '' );
					if ( ! empty( $ownership_row['has_match'] ) && ! empty( $ownership_row['import_id'] ) ) {
						echo ' ';
						echo esc_html(
							sprintf(
								/* translators: %d is the matched import job ID. */
								__( '(ID #%d)', 'enterprise-api-importer' ),
								(int) $ownership_row['import_id']
							)
						);
					}
					?>
				</td>
				<td><?php echo esc_html( isset( $ownership_row['post_count'] ) ? (string) (int) $ownership_row['post_count'] : '0' ); ?></td>
			</tr>
		<?php endforeach; ?>
	<?php endif; ?>
	</tbody>
</table>
</div>
<?php
}

/**
 * Gets imported post ownership grouped by _eai_import_id.
 *
 * @return array<int, array<string, mixed>>
 */
function eai_get_import_post_ownership_counts() {
	global $wpdb;

	$postmeta_table = $wpdb->postmeta;
	$posts_table    = $wpdb->posts;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.meta_value AS import_id_raw, COUNT(p.ID) AS post_count
			FROM %i pm
			INNER JOIN %i p
				ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
				AND p.post_type = %s
				AND p.post_status NOT IN ('trash', 'auto-draft')
			GROUP BY pm.meta_value
			ORDER BY post_count DESC, pm.meta_value ASC",
			$postmeta_table,
			$posts_table,
			'_eai_import_id',
			'imported_item'
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$import_rows  = eai_db_get_import_configs();
	$import_names = array();
	foreach ( $import_rows as $import_row ) {
		$import_id = isset( $import_row['id'] ) ? absint( $import_row['id'] ) : 0;
		if ( $import_id > 0 ) {
			$import_names[ $import_id ] = isset( $import_row['name'] ) ? (string) $import_row['name'] : '';
		}
	}

	$ownership = array();
	foreach ( $rows as $row ) {
		$import_id_raw = isset( $row['import_id_raw'] ) ? trim( (string) $row['import_id_raw'] ) : '';
		$import_id     = absint( $import_id_raw );
		$has_match     = $import_id > 0 && isset( $import_names[ $import_id ] );
		$import_name   = $has_match ? (string) $import_names[ $import_id ] : __( 'Unknown import job', 'enterprise-api-importer' );

		$ownership[] = array(
			'import_id_raw' => $import_id_raw,
			'import_id'     => $import_id,
			'import_name'   => $import_name,
			'has_match'     => $has_match,
			'post_count'    => isset( $row['post_count'] ) ? (int) $row['post_count'] : 0,
		);
	}

	return $ownership;
}

/**
 * Builds schedule dashboard metrics for all imports.
 *
 * @return array<int, array<string, mixed>>
 */
function eai_get_dashboard_metrics() {
	$rows          = eai_db_get_import_configs();
	$latest_logs   = eai_db_get_latest_logs_indexed_by_import_id();
	$pending_index = eai_db_get_pending_counts_by_import_id();

$metrics = array();
$active_state = eai_get_active_run_state();

foreach ( $rows as $row ) {
$import_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
if ( $import_id <= 0 ) {
continue;
}

$pending_count = isset( $pending_index[ $import_id ] ) ? (int) $pending_index[ $import_id ] : 0;

$next_scheduled = wp_next_scheduled( 'eai_recurring_import_trigger', array( $import_id, 'recurring' ) );
if ( false === $next_scheduled ) {
$next_scheduled = wp_next_scheduled( 'eai_recurring_import_trigger', array( $import_id ) );
}

$status = 'idle';
if ( $pending_count > 0 ) {
$status = 'processing';
} elseif ( ! empty( $latest_logs[ $import_id ]['status'] ) ) {
$status = strtolower( (string) $latest_logs[ $import_id ]['status'] );
}

$error_count = 0;
$details_summary = '';
$trigger_source = 'unknown';
$rows_trashed = 0;
if ( ! empty( $latest_logs[ $import_id ]['errors'] ) ) {
$decoded_errors = json_decode( (string) $latest_logs[ $import_id ]['errors'], true );
if ( is_array( $decoded_errors ) && isset( $decoded_errors['orphans_trashed'] ) ) {
	$rows_trashed = absint( $decoded_errors['orphans_trashed'] );
}
if ( is_array( $decoded_errors ) && isset( $decoded_errors['processing_errors'] ) && is_array( $decoded_errors['processing_errors'] ) ) {
$error_count = count( $decoded_errors['processing_errors'] );
	if ( ! empty( $decoded_errors['processing_errors'][0] ) ) {
		$details_summary = sanitize_text_field( (string) $decoded_errors['processing_errors'][0] );
	}
	if ( ! empty( $decoded_errors['trigger_source'] ) ) {
		$trigger_source = sanitize_key( (string) $decoded_errors['trigger_source'] );
	}
} elseif ( is_array( $decoded_errors ) && ! empty( $decoded_errors['error'] ) ) {
$error_count = 1;
$details_summary = sanitize_text_field( (string) $decoded_errors['error'] );
	if ( ! empty( $decoded_errors['trigger_source'] ) ) {
		$trigger_source = sanitize_key( (string) $decoded_errors['trigger_source'] );
	}
} else {
$error_count = 1;
	$details_summary = sanitize_text_field( (string) $latest_logs[ $import_id ]['errors'] );
}
}

if ( 'unknown' === $trigger_source && ! empty( $active_state['import_id'] ) && (int) $active_state['import_id'] === $import_id && ! empty( $active_state['trigger_source'] ) ) {
$trigger_source = sanitize_key( (string) $active_state['trigger_source'] );
}

if ( '' === $details_summary ) {
	if ( 'processing' === $status ) {
		$details_summary = __( 'Import has staged rows waiting for worker processing.', 'enterprise-api-importer' );
	} elseif ( ! empty( $latest_logs[ $import_id ]['status'] ) ) {
		$details_summary = sprintf(
			/* translators: %s is the latest log status. */
			__( 'Last status: %s', 'enterprise-api-importer' ),
			sanitize_text_field( (string) $latest_logs[ $import_id ]['status'] )
		);
	} else {
		$details_summary = __( 'No execution details available yet.', 'enterprise-api-importer' );
	}
}

$metrics[] = array(
'id'                => $import_id,
'name'              => isset( $row['name'] ) ? (string) $row['name'] : '',
'endpoint_url'      => isset( $row['endpoint_url'] ) ? (string) $row['endpoint_url'] : '',
'status'            => $status,
'last_status'       => isset( $latest_logs[ $import_id ]['status'] ) ? (string) $latest_logs[ $import_id ]['status'] : '',
'last_run_at'       => isset( $latest_logs[ $import_id ]['last_run_at'] ) ? (string) $latest_logs[ $import_id ]['last_run_at'] : '',
'rows_processed'    => isset( $latest_logs[ $import_id ]['rows_processed'] ) ? (int) $latest_logs[ $import_id ]['rows_processed'] : 0,
'rows_created'      => isset( $latest_logs[ $import_id ]['rows_created'] ) ? (int) $latest_logs[ $import_id ]['rows_created'] : 0,
'rows_updated'      => isset( $latest_logs[ $import_id ]['rows_updated'] ) ? (int) $latest_logs[ $import_id ]['rows_updated'] : 0,
'rows_trashed'      => $rows_trashed,
'error_count'       => $error_count,
'trigger_source'    => $trigger_source,
'details_summary'   => $details_summary,
'pending_count'     => $pending_count,
'next_scheduled_ts' => false !== $next_scheduled ? (int) $next_scheduled : 0,
'next_scheduled'    => false !== $next_scheduled ? wp_date( 'Y-m-d H:i:s', (int) $next_scheduled ) : __( 'Not scheduled', 'enterprise-api-importer' ),
);
}

return $metrics;
}

/**
 * Returns status badge class/label.
 *
 * @param string $status Status key.
 *
 * @return array{class:string,label:string}
 */
function eai_get_status_badge_data( $status ) {
$status = strtolower( (string) $status );

if ( 'processing' === $status ) {
return array( 'class' => 'eai-badge is-processing', 'label' => __( 'Processing', 'enterprise-api-importer' ) );
}

if ( 'success' === $status ) {
return array( 'class' => 'eai-badge is-success', 'label' => __( 'Success', 'enterprise-api-importer' ) );
}

if ( 'failed' === $status || 'completed_with_errors' === $status ) {
return array( 'class' => 'eai-badge is-failed', 'label' => __( 'Failed', 'enterprise-api-importer' ) );
}

return array( 'class' => 'eai-badge is-idle', 'label' => __( 'Idle', 'enterprise-api-importer' ) );
}

/**
 * Returns user-facing trigger source label.
 *
 * @param string $trigger_source Trigger source key.
 *
 * @return string
 */
function eai_get_trigger_source_label( $trigger_source ) {
$trigger_source = sanitize_key( (string) $trigger_source );

if ( 'manual' === $trigger_source ) {
return __( 'Manual Run', 'enterprise-api-importer' );
}

if ( 'run_now' === $trigger_source ) {
return __( 'Run Now', 'enterprise-api-importer' );
}

if ( 'recurring' === $trigger_source ) {
return __( 'Recurring Schedule', 'enterprise-api-importer' );
}

return __( 'Unknown', 'enterprise-api-importer' );
}

/**
 * Renders schedules dashboard page.
 */
function eai_render_schedules_page() {
if ( ! current_user_can( 'manage_options' ) ) {
return;
}

$metrics = eai_get_dashboard_metrics();
?>
<div class="wrap">
<h1><?php esc_html_e( 'Schedules & Health Dashboard', 'enterprise-api-importer' ); ?></h1>
<?php eai_render_admin_notices(); ?>

<style>
.eai-schedules-table .column-status,
.eai-schedules-table .column-actions {
white-space: nowrap;
}
.eai-badge {
display: inline-block;
padding: 4px 10px;
border-radius: 999px;
font-size: 12px;
font-weight: 600;
}
.eai-badge.is-success { background: #dcfce7; color: #166534; }
.eai-badge.is-failed { background: #fee2e2; color: #991b1b; }
.eai-badge.is-processing { background: #dbeafe; color: #1e40af; }
.eai-badge.is-idle { background: #e5e7eb; color: #374151; }
</style>

<table class="widefat striped eai-schedules-table">
<thead>
<tr>
<th><?php esc_html_e( 'Import Name & ID', 'enterprise-api-importer' ); ?></th>
<th class="column-status"><?php esc_html_e( 'Status', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Trigger Source', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Last Run Time', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Last Run Metrics', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Details', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Next Scheduled Run', 'enterprise-api-importer' ); ?></th>
<th class="column-actions"><?php esc_html_e( 'Actions', 'enterprise-api-importer' ); ?></th>
</tr>
</thead>
<tbody>
<?php if ( empty( $metrics ) ) : ?>
<tr><td colspan="8"><?php esc_html_e( 'No import jobs found.', 'enterprise-api-importer' ); ?></td></tr>
<?php else : ?>
<?php foreach ( $metrics as $metric ) : ?>
<?php $badge = eai_get_status_badge_data( isset( $metric['status'] ) ? (string) $metric['status'] : 'idle' ); ?>
<tr>
<td>
<strong><?php echo esc_html( isset( $metric['name'] ) ? (string) $metric['name'] : '' ); ?></strong><br />
<span><?php
/* translators: %d is the import job ID. */
echo esc_html( sprintf( __( 'ID: %d', 'enterprise-api-importer' ), isset( $metric['id'] ) ? (int) $metric['id'] : 0 ) );
?></span>
</td>
<td class="column-status"><span class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span></td>
<td><?php echo esc_html( eai_get_trigger_source_label( isset( $metric['trigger_source'] ) ? (string) $metric['trigger_source'] : 'unknown' ) ); ?></td>
<td><?php echo esc_html( ! empty( $metric['last_run_at'] ) ? (string) $metric['last_run_at'] : __( 'Never', 'enterprise-api-importer' ) ); ?></td>
<td>
<?php
echo esc_html(
sprintf(
	/* translators: 1: rows created, 2: rows updated, 3: rows trashed, 4: error count. */
	__( 'Created: %1$d | Updated: %2$d | Trashed: %3$d | Errors: %4$d', 'enterprise-api-importer' ),
isset( $metric['rows_created'] ) ? (int) $metric['rows_created'] : 0,
isset( $metric['rows_updated'] ) ? (int) $metric['rows_updated'] : 0,
	isset( $metric['rows_trashed'] ) ? (int) $metric['rows_trashed'] : 0,
isset( $metric['error_count'] ) ? (int) $metric['error_count'] : 0
)
);
?>
</td>
<td><?php echo esc_html( isset( $metric['details_summary'] ) ? substr( (string) $metric['details_summary'], 0, 280 ) : '' ); ?></td>
<td><?php echo esc_html( isset( $metric['next_scheduled'] ) ? (string) $metric['next_scheduled'] : __( 'Not scheduled', 'enterprise-api-importer' ) ); ?></td>
<td class="column-actions">
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="eai_schedule_run_now" />
<input type="hidden" name="import_id" value="<?php echo esc_attr( (string) ( isset( $metric['id'] ) ? (int) $metric['id'] : 0 ) ); ?>" />
<?php wp_nonce_field( 'eai_schedule_run_now_' . (int) $metric['id'], 'eai_schedule_run_now_nonce' ); ?>
<?php submit_button( __( 'Run Now', 'enterprise-api-importer' ), 'secondary', 'submit', false ); ?>
</form>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<?php
}

/**
 * Returns supported data filter operators.
 *
 * @return array<string, string>
 */
function eai_get_filter_operator_options() {
	return array(
		'equals'       => __( 'Equals', 'enterprise-api-importer' ),
		'not_equals'   => __( 'Not Equals', 'enterprise-api-importer' ),
		'contains'     => __( 'Contains', 'enterprise-api-importer' ),
		'not_contains' => __( 'Not Contains', 'enterprise-api-importer' ),
		'is_empty'     => __( 'Is Empty', 'enterprise-api-importer' ),
		'not_empty'    => __( 'Not Empty', 'enterprise-api-importer' ),
		'greater_than' => __( 'Greater Than', 'enterprise-api-importer' ),
		'less_than'    => __( 'Less Than', 'enterprise-api-importer' ),
	);
}

/**
 * Renders create/edit import page.
 */
function eai_render_import_edit_page() {
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only import ID for loading edit form data.
$import_id     = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
$import_row    = null;

if ( $import_id > 0 ) {
	$import_row = eai_db_get_import_config( $import_id );
}

$defaults = array(
'id'               => 0,
'name'             => '',
'endpoint_url'     => '',
'auth_token'       => '',
'array_path'       => '',
		'unique_id_path'   => 'id',
'recurrence'       => 'off',
'custom_interval_minutes' => 30,
'filter_rules'     => '[]',
'mapping_template' => '',
);
$import = is_array( $import_row ) ? wp_parse_args( $import_row, $defaults ) : $defaults;
$import['custom_interval_minutes'] = absint( $import['custom_interval_minutes'] );
if ( 'custom' === (string) $import['recurrence'] && $import['custom_interval_minutes'] <= 0 ) {
	$import['custom_interval_minutes'] = 30;
}
$filter_operator_options = eai_get_filter_operator_options();
$decoded_filter_rules    = json_decode( (string) $import['filter_rules'], true );
$filter_rules_for_ui     = array();
if ( is_array( $decoded_filter_rules ) ) {
	foreach ( $decoded_filter_rules as $filter_rule ) {
		if ( ! is_array( $filter_rule ) ) {
			continue;
		}

		$rule_key      = isset( $filter_rule['key'] ) ? sanitize_text_field( (string) $filter_rule['key'] ) : '';
		$rule_operator = isset( $filter_rule['operator'] ) ? sanitize_key( (string) $filter_rule['operator'] ) : '';
		$rule_value    = isset( $filter_rule['value'] ) ? sanitize_text_field( (string) $filter_rule['value'] ) : '';

		if ( '' === $rule_key || ! isset( $filter_operator_options[ $rule_operator ] ) ) {
			continue;
		}

		$filter_rules_for_ui[] = array(
			'key'      => $rule_key,
			'operator' => $rule_operator,
			'value'    => $rule_value,
		);
	}
}

if ( empty( $filter_rules_for_ui ) ) {
	$filter_rules_for_ui[] = array(
		'key'      => '',
		'operator' => 'equals',
		'value'    => '',
	);
}
$is_edit = (int) $import['id'] > 0;
?>
<div class="wrap">
<h1><?php echo esc_html( $is_edit ? __( 'Edit Import Job', 'enterprise-api-importer' ) : __( 'Create Import Job', 'enterprise-api-importer' ) ); ?></h1>
<?php eai_render_admin_notices(); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="eai_save_import" />
<input type="hidden" name="import_id" value="<?php echo esc_attr( (string) $import['id'] ); ?>" />
<?php wp_nonce_field( 'eai_save_import', 'eai_save_import_nonce' ); ?>

<table class="form-table" role="presentation">
<tbody>
<tr>
<th scope="row"><label for="eai_import_name"><?php esc_html_e( 'Name', 'enterprise-api-importer' ); ?></label></th>
<td><input name="name" type="text" id="eai_import_name" class="regular-text" value="<?php echo esc_attr( (string) $import['name'] ); ?>" required /></td>
</tr>
<tr>
<th scope="row"><label for="eai_import_endpoint_url"><?php esc_html_e( 'Endpoint URL', 'enterprise-api-importer' ); ?></label></th>
<td><input name="endpoint_url" type="url" id="eai_import_endpoint_url" class="large-text" value="<?php echo esc_attr( (string) $import['endpoint_url'] ); ?>" required /></td>
</tr>
<tr>
<th scope="row"><label for="eai_import_auth_token"><?php esc_html_e( 'Bearer Token', 'enterprise-api-importer' ); ?></label></th>
<td>
<input name="auth_token" type="password" id="eai_import_auth_token" class="regular-text" value="<?php echo esc_attr( (string) $import['auth_token'] ); ?>" autocomplete="new-password" />
<p class="description"><?php esc_html_e( 'Leave blank only if your endpoint does not require bearer authentication.', 'enterprise-api-importer' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="eai_import_array_path"><?php esc_html_e( 'JSON Array Path', 'enterprise-api-importer' ); ?></label></th>
<td>
<input name="array_path" type="text" id="eai_import_array_path" class="regular-text" value="<?php echo esc_attr( (string) $import['array_path'] ); ?>" />
<p class="description"><?php esc_html_e( 'Example: data.employees. Leave empty if the API root is already an array.', 'enterprise-api-importer' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="eai_import_unique_id_path"><?php esc_html_e( 'Unique ID Path', 'enterprise-api-importer' ); ?></label></th>
<td>
<input name="unique_id_path" type="text" id="eai_import_unique_id_path" class="regular-text" value="<?php echo esc_attr( (string) $import['unique_id_path'] ); ?>" />
<p class="description"><?php esc_html_e( 'Dot-path to the source unique identifier (example: CourseIDFull or data.course.id). Defaults to id when empty.', 'enterprise-api-importer' ); ?></p>
</td>
</tr>
<tr>
<th scope="row"><label for="eai_import_recurrence"><?php esc_html_e( 'Recurrence', 'enterprise-api-importer' ); ?></label></th>
<td>
<select name="recurrence" id="eai_import_recurrence">
<option value="off" <?php selected( (string) $import['recurrence'], 'off' ); ?>><?php esc_html_e( 'Off', 'enterprise-api-importer' ); ?></option>
<option value="hourly" <?php selected( (string) $import['recurrence'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'enterprise-api-importer' ); ?></option>
<option value="twicedaily" <?php selected( (string) $import['recurrence'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'enterprise-api-importer' ); ?></option>
<option value="daily" <?php selected( (string) $import['recurrence'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'enterprise-api-importer' ); ?></option>
<option value="custom" <?php selected( (string) $import['recurrence'], 'custom' ); ?>><?php esc_html_e( 'Custom', 'enterprise-api-importer' ); ?></option>
</select>
<span id="eai_custom_interval_wrapper" style="margin-left:10px;">
<label for="eai_import_custom_interval_minutes"><?php esc_html_e( 'Custom minutes', 'enterprise-api-importer' ); ?></label>
<input name="custom_interval_minutes" type="number" id="eai_import_custom_interval_minutes" min="1" step="1" class="small-text" value="<?php echo esc_attr( (string) $import['custom_interval_minutes'] ); ?>" />
</span>
<p class="description"><?php esc_html_e( 'When set to Custom, the import runs every N minutes. Use Off to disable recurring automation.', 'enterprise-api-importer' ); ?></p>
<script>
( function() {
	var recurrenceSelect = document.getElementById( 'eai_import_recurrence' );
	var customWrapper = document.getElementById( 'eai_custom_interval_wrapper' );
	var customInput = document.getElementById( 'eai_import_custom_interval_minutes' );

	if ( ! recurrenceSelect || ! customWrapper || ! customInput ) {
		return;
	}

	var toggleCustomMinutes = function() {
		var isCustom = recurrenceSelect.value === 'custom';

		customWrapper.style.display = isCustom ? 'inline-flex' : 'none';
		customWrapper.style.alignItems = isCustom ? 'center' : '';
		customWrapper.style.gap = isCustom ? '6px' : '';
		customInput.disabled = ! isCustom;

		if ( isCustom && parseInt( customInput.value, 10 ) <= 0 ) {
			customInput.value = '30';
		}
	};

	recurrenceSelect.addEventListener( 'change', toggleCustomMinutes );
	toggleCustomMinutes();
} )();
</script>
</td>
</tr>
<tr>
<th scope="row"><?php esc_html_e( 'Data Filters', 'enterprise-api-importer' ); ?></th>
<td>
<p class="description"><?php esc_html_e( 'Only records matching every filter are staged for import (AND logic).', 'enterprise-api-importer' ); ?></p>
<table class="widefat striped" style="max-width:900px; margin-top:8px;">
<thead>
<tr>
<th><?php esc_html_e( 'Key', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Operator', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Value', 'enterprise-api-importer' ); ?></th>
<th><?php esc_html_e( 'Actions', 'enterprise-api-importer' ); ?></th>
</tr>
</thead>
<tbody id="eai-filter-rules-body">
<?php foreach ( $filter_rules_for_ui as $filter_rule ) : ?>
<tr>
<td><input type="text" name="filter_rules[key][]" class="regular-text" value="<?php echo esc_attr( (string) $filter_rule['key'] ); ?>" placeholder="department" /></td>
<td>
<select name="filter_rules[operator][]">
<?php foreach ( $filter_operator_options as $operator_key => $operator_label ) : ?>
<option value="<?php echo esc_attr( $operator_key ); ?>" <?php selected( (string) $filter_rule['operator'], $operator_key ); ?>><?php echo esc_html( $operator_label ); ?></option>
<?php endforeach; ?>
</select>
</td>
<td><input type="text" name="filter_rules[value][]" class="regular-text" value="<?php echo esc_attr( (string) $filter_rule['value'] ); ?>" placeholder="Engineering" /></td>
<td><button type="button" class="button eai-remove-filter-row"><?php esc_html_e( 'Remove', 'enterprise-api-importer' ); ?></button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<p style="margin-top:8px;">
<button type="button" class="button" id="eai-add-filter-row"><?php esc_html_e( 'Add Filter', 'enterprise-api-importer' ); ?></button>
</p>

<template id="eai-filter-rule-template">
<tr>
<td><input type="text" name="filter_rules[key][]" class="regular-text" value="" placeholder="department" /></td>
<td>
<select name="filter_rules[operator][]">
<?php foreach ( $filter_operator_options as $operator_key => $operator_label ) : ?>
<option value="<?php echo esc_attr( $operator_key ); ?>"><?php echo esc_html( $operator_label ); ?></option>
<?php endforeach; ?>
</select>
</td>
<td><input type="text" name="filter_rules[value][]" class="regular-text" value="" placeholder="Engineering" /></td>
<td><button type="button" class="button eai-remove-filter-row"><?php esc_html_e( 'Remove', 'enterprise-api-importer' ); ?></button></td>
</tr>
</template>

<script>
( function() {
	var filterBody = document.getElementById( 'eai-filter-rules-body' );
	var addFilterButton = document.getElementById( 'eai-add-filter-row' );
	var rowTemplate = document.getElementById( 'eai-filter-rule-template' );

	if ( ! filterBody || ! addFilterButton || ! rowTemplate ) {
		return;
	}

	addFilterButton.addEventListener( 'click', function() {
		var templateRow = rowTemplate.content.firstElementChild.cloneNode( true );
		filterBody.appendChild( templateRow );
	} );

	filterBody.addEventListener( 'click', function( event ) {
		var target = event.target;
		if ( ! target.classList.contains( 'eai-remove-filter-row' ) ) {
			return;
		}

		var row = target.closest( 'tr' );
		if ( ! row ) {
			return;
		}

		if ( filterBody.children.length <= 1 ) {
			var keyInput = row.querySelector( 'input[name="filter_rules[key][]"]' );
			var valueInput = row.querySelector( 'input[name="filter_rules[value][]"]' );
			var operatorSelect = row.querySelector( 'select[name="filter_rules[operator][]"]' );
			if ( keyInput ) { keyInput.value = ''; }
			if ( valueInput ) { valueInput.value = ''; }
			if ( operatorSelect ) { operatorSelect.value = 'equals'; }
			return;
		}

		row.remove();
	} );
} )();
</script>
</td>
</tr>
<tr>
<th scope="row"><label for="eai_import_mapping_template"><?php esc_html_e( 'Mapping Template', 'enterprise-api-importer' ); ?></label></th>
<td><textarea name="mapping_template" id="eai_import_mapping_template" rows="12" class="large-text code" required><?php echo esc_textarea( (string) $import['mapping_template'] ); ?></textarea></td>
</tr>
</tbody>
</table>

<?php submit_button( $is_edit ? __( 'Update Import', 'enterprise-api-importer' ) : __( 'Create Import', 'enterprise-api-importer' ) ); ?>
</form>

<?php if ( $is_edit ) : ?>
<p><?php esc_html_e( 'Template changed? Use this to re-render existing imported items for this import job.', 'enterprise-api-importer' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="eai_run_import" />
<input type="hidden" name="import_id" value="<?php echo esc_attr( (string) $import['id'] ); ?>" />
<input type="hidden" name="eai_template_sync" value="1" />
<?php wp_nonce_field( 'eai_manual_run', 'eai_manual_run_nonce' ); ?>
<?php submit_button( __( 'Update Existing Imported Items From Template', 'enterprise-api-importer' ), 'secondary', 'submit', false ); ?>
</form>
<?php endif; ?>

<?php if ( $is_edit ) : ?>
<hr />
<h2><?php esc_html_e( 'Run This Import', 'enterprise-api-importer' ); ?></h2>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="eai_run_import" />
<input type="hidden" name="import_id" value="<?php echo esc_attr( (string) $import['id'] ); ?>" />
<?php wp_nonce_field( 'eai_manual_run', 'eai_manual_run_nonce' ); ?>
<?php submit_button( __( 'Run Import Now', 'enterprise-api-importer' ), 'secondary' ); ?>
</form>

<?php
$test_result_key = 'eai_endpoint_test_result_' . (int) $import['id'];
$test_result = get_transient( $test_result_key );
if ( false !== $test_result ) {
delete_transient( $test_result_key );
}
?>

<hr />
<div class="eai-panel">
<style>
.eai-panel { margin-top: 18px; padding: 18px 20px; border: 1px solid #dcdcde; border-radius: 10px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow: 0 1px 0 rgba(0,0,0,0.03); }
.eai-panel h2 { margin-top: 0; }
.eai-metrics { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0 14px; }
.eai-chip { display: inline-block; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #eef2ff; color: #1e3a8a; }
.eai-chip.is-error { background: #fef2f2; color: #991b1b; }
.eai-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 12px; }
.eai-stat { border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; background: #fff; }
.eai-stat-label { display: block; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.03em; }
.eai-stat-value { display: block; font-size: 15px; font-weight: 600; margin-top: 2px; }
.eai-preview { border: 1px solid #cbd5e1; border-radius: 8px; background: #0f172a; color: #e2e8f0; padding: 12px; max-height: 300px; overflow: auto; font-size: 12px; line-height: 1.5; }
.eai-record { border: 1px solid #dbe3ec; border-radius: 10px; padding: 12px; background: #fff; margin-bottom: 12px; }
.eai-record h4 { margin: 0 0 8px; }
.eai-mapped-render { border: 1px dashed #cbd5e1; border-radius: 8px; padding: 10px; background: #f8fafc; max-height: 220px; overflow: auto; }
.eai-mode-row { display: flex; gap: 16px; flex-wrap: wrap; margin: 12px 0; }
</style>

<h2><?php esc_html_e( 'Endpoint Test & Preview', 'enterprise-api-importer' ); ?></h2>
<p><?php esc_html_e( 'Validate connectivity and inspect sample response data before running an import.', 'enterprise-api-importer' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="eai_test_import_endpoint" />
<input type="hidden" name="import_id" value="<?php echo esc_attr( (string) $import['id'] ); ?>" />
<?php wp_nonce_field( 'eai_test_import_endpoint', 'eai_test_import_endpoint_nonce' ); ?>

<div class="eai-mode-row">
<label><input type="radio" name="eai_preview_mode" value="structure" checked="checked" /> <?php esc_html_e( 'Structure Preview', 'enterprise-api-importer' ); ?></label>
<label><input type="radio" name="eai_preview_mode" value="mapping" /> <?php esc_html_e( 'Normalized + Mapping Preview (first 3 records)', 'enterprise-api-importer' ); ?></label>
</div>

<?php submit_button( __( 'Test Endpoint', 'enterprise-api-importer' ), 'primary', 'submit', false ); ?>
</form>

<?php if ( is_array( $test_result ) && ! empty( $test_result ) ) : ?>
<div class="eai-metrics">
<span class="eai-chip <?php echo ( isset( $test_result['type'] ) && 'error' === $test_result['type'] ) ? 'is-error' : ''; ?>"><?php echo esc_html( isset( $test_result['type'] ) && 'error' === $test_result['type'] ? __( 'Test Failed', 'enterprise-api-importer' ) : __( 'Test Passed', 'enterprise-api-importer' ) ); ?></span>
<span class="eai-chip"><?php echo esc_html( ! empty( $test_result['used_cache'] ) ? __( 'Source: Cached Response', 'enterprise-api-importer' ) : __( 'Source: Live API Call', 'enterprise-api-importer' ) ); ?></span>
</div>
<p><strong><?php esc_html_e( 'Message:', 'enterprise-api-importer' ); ?></strong> <?php echo esc_html( isset( $test_result['message'] ) ? (string) $test_result['message'] : '' ); ?></p>

<div class="eai-grid">
<div class="eai-stat">
<span class="eai-stat-label"><?php esc_html_e( 'HTTP Code', 'enterprise-api-importer' ); ?></span>
<span class="eai-stat-value"><?php echo esc_html( isset( $test_result['http_code'] ) ? (string) $test_result['http_code'] : 'n/a' ); ?></span>
</div>
<div class="eai-stat">
<span class="eai-stat-label"><?php esc_html_e( 'Payload Type', 'enterprise-api-importer' ); ?></span>
<span class="eai-stat-value"><?php echo esc_html( isset( $test_result['payload_type'] ) ? (string) $test_result['payload_type'] : 'unknown' ); ?></span>
</div>
<div class="eai-stat">
<span class="eai-stat-label"><?php esc_html_e( 'Items Found', 'enterprise-api-importer' ); ?></span>
<span class="eai-stat-value"><?php echo esc_html( isset( $test_result['item_count'] ) ? (string) $test_result['item_count'] : '0' ); ?></span>
</div>
<div class="eai-stat">
<span class="eai-stat-label"><?php esc_html_e( 'JSON Path Used', 'enterprise-api-importer' ); ?></span>
<span class="eai-stat-value"><?php echo esc_html( isset( $test_result['json_path'] ) && '' !== (string) $test_result['json_path'] ? (string) $test_result['json_path'] : 'root' ); ?></span>
</div>
</div>

<?php if ( ! empty( $test_result['sample_keys'] ) && is_array( $test_result['sample_keys'] ) ) : ?>
<p><strong><?php esc_html_e( 'Sample Item Keys:', 'enterprise-api-importer' ); ?></strong> <?php echo esc_html( implode( ', ', array_slice( $test_result['sample_keys'], 0, 25 ) ) ); ?></p>
<?php endif; ?>

<?php if ( isset( $test_result['sample_json'] ) && '' !== (string) $test_result['sample_json'] ) : ?>
<p><strong><?php esc_html_e( 'Sample Preview', 'enterprise-api-importer' ); ?></strong></p>
<pre class="eai-preview"><?php echo esc_html( (string) $test_result['sample_json'] ); ?></pre>
<?php endif; ?>

<?php if ( isset( $test_result['preview_mode'] ) && 'mapping' === (string) $test_result['preview_mode'] ) : ?>
<h3><?php esc_html_e( 'Normalized + Mapping Preview', 'enterprise-api-importer' ); ?></h3>
<?php if ( ! empty( $test_result['normalized_preview'] ) && is_array( $test_result['normalized_preview'] ) ) : ?>
<?php foreach ( $test_result['normalized_preview'] as $preview_row ) : ?>
<div class="eai-record">
<h4><?php
/* translators: %d is the preview record sequence number. */
echo esc_html( sprintf( __( 'Record %d', 'enterprise-api-importer' ), isset( $preview_row['record_number'] ) ? (int) $preview_row['record_number'] : 0 ) );
?></h4>
<?php if ( ! empty( $preview_row['mapping_error'] ) ) : ?>
<p><strong><?php esc_html_e( 'Mapping Error:', 'enterprise-api-importer' ); ?></strong> <?php echo esc_html( (string) $preview_row['mapping_error'] ); ?></p>
<?php else : ?>
<p><strong><?php esc_html_e( 'Mapped Content (Rendered Safely):', 'enterprise-api-importer' ); ?></strong></p>
<div class="eai-mapped-render"><?php echo wp_kses_post( isset( $preview_row['mapped_content'] ) ? (string) $preview_row['mapped_content'] : '' ); ?></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php else : ?>
<p><?php esc_html_e( 'No normalized records were available for mapping preview.', 'enterprise-api-importer' ); ?></p>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php
}

/**
 * Handles create/update import form submits.
 */
function eai_handle_save_import() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You are not allowed to manage imports.', 'enterprise-api-importer' ) );
}

check_admin_referer( 'eai_save_import', 'eai_save_import_nonce' );

$post_data = wp_unslash( $_POST );
$post_data = is_array( $post_data ) ? $post_data : array();

$import_id     = isset( $post_data['import_id'] ) ? absint( $post_data['import_id'] ) : 0;


$name         = isset( $post_data['name'] ) ? sanitize_text_field( (string) $post_data['name'] ) : '';
$endpoint_url = isset( $post_data['endpoint_url'] ) ? esc_url_raw( trim( (string) $post_data['endpoint_url'] ) ) : '';
$auth_token   = isset( $post_data['auth_token'] ) ? sanitize_text_field( (string) $post_data['auth_token'] ) : '';
$array_path   = isset( $post_data['array_path'] ) ? sanitize_text_field( (string) $post_data['array_path'] ) : '';
	$unique_id_path = isset( $post_data['unique_id_path'] ) ? sanitize_text_field( (string) $post_data['unique_id_path'] ) : 'id';
$recurrence   = isset( $post_data['recurrence'] ) ? sanitize_key( (string) $post_data['recurrence'] ) : 'off';
$custom_interval_minutes = isset( $post_data['custom_interval_minutes'] ) ? absint( $post_data['custom_interval_minutes'] ) : 0;
$template_raw = isset( $post_data['mapping_template'] ) ? (string) $post_data['mapping_template'] : '';
	$unique_id_path = trim( (string) $unique_id_path );

$allowed_recurrence = array( 'off', 'hourly', 'twicedaily', 'daily', 'custom' );
if ( ! in_array( $recurrence, $allowed_recurrence, true ) ) {
$recurrence = 'off';
}

if ( 'custom' === $recurrence ) {
	$custom_interval_minutes = $custom_interval_minutes > 0 ? $custom_interval_minutes : 30;
} else {
$custom_interval_minutes = 0;
}

	if ( '' === $unique_id_path ) {
		$unique_id_path = 'id';
	}

$allowed_mapping_html = array(
'h1'     => array(),
'h2'     => array(),
'h3'     => array(),
'h4'     => array(),
'h5'     => array(),
'h6'     => array(),
'p'      => array(),
'br'     => array(),
'strong' => array(),
'em'     => array(),
'ul'     => array(),
'ol'     => array(),
'li'     => array(),
'a'      => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ),
);
$mapping_template = wp_kses( (string) $template_raw, $allowed_mapping_html );

$filter_operator_options = eai_get_filter_operator_options();
$allowed_operators       = array_keys( $filter_operator_options );
$filter_rules            = array();

if ( isset( $post_data['filter_rules'] ) && is_array( $post_data['filter_rules'] ) ) {
	$raw_filter_rules = $post_data['filter_rules'];
	$rule_keys        = isset( $raw_filter_rules['key'] ) && is_array( $raw_filter_rules['key'] ) ? $raw_filter_rules['key'] : array();
	$rule_operators   = isset( $raw_filter_rules['operator'] ) && is_array( $raw_filter_rules['operator'] ) ? $raw_filter_rules['operator'] : array();
	$rule_values      = isset( $raw_filter_rules['value'] ) && is_array( $raw_filter_rules['value'] ) ? $raw_filter_rules['value'] : array();

	$rule_count = max( count( $rule_keys ), count( $rule_operators ), count( $rule_values ) );
	for ( $index = 0; $index < $rule_count; $index++ ) {
		$rule_key      = isset( $rule_keys[ $index ] ) ? sanitize_text_field( trim( (string) $rule_keys[ $index ] ) ) : '';
		$rule_operator = isset( $rule_operators[ $index ] ) ? sanitize_key( (string) $rule_operators[ $index ] ) : '';
		$rule_value    = isset( $rule_values[ $index ] ) ? sanitize_text_field( (string) $rule_values[ $index ] ) : '';

		if ( '' === $rule_key || ! in_array( $rule_operator, $allowed_operators, true ) ) {
			continue;
		}

		$filter_rules[] = array(
			'key'      => $rule_key,
			'operator' => $rule_operator,
			'value'    => $rule_value,
		);
	}
}

$filter_rules_json = wp_json_encode( $filter_rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( false === $filter_rules_json ) {
	$filter_rules_json = '[]';
}

if ( '' === $name || '' === $endpoint_url || '' === $mapping_template ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

$data = array(
'name'             => $name,
'endpoint_url'     => $endpoint_url,
'auth_token'       => $auth_token,
'array_path'       => $array_path,
		'unique_id_path'   => $unique_id_path,
'recurrence'       => $recurrence,
'custom_interval_minutes' => $custom_interval_minutes,
'filter_rules'     => (string) $filter_rules_json,
'mapping_template' => $mapping_template,
);
	$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

$persisted_import_id = eai_db_save_import_config( $import_id, $data, $formats );
if ( is_wp_error( $persisted_import_id ) ) {
	wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
	exit;
}

$import_id = (int) $persisted_import_id;

$schedule_synced = eai_sync_import_recurrence_schedule( $import_id, $recurrence, $custom_interval_minutes );
if ( ! $schedule_synced ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => 'import_saved' ), admin_url( 'admin.php' ) ) );
exit;
}

/**
 * Handles delete import action.
 */
function eai_handle_delete_import() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You are not allowed to manage imports.', 'enterprise-api-importer' ) );
}

$import_id = isset( $_GET['import_id'] ) ? absint( wp_unslash( $_GET['import_id'] ) ) : 0;
if ( $import_id <= 0 ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

check_admin_referer( 'eai_delete_import_' . $import_id, 'eai_delete_nonce' );

eai_clear_import_scheduled_hooks( $import_id );

$deleted = eai_db_delete_import_config( $import_id );

wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'eai_notice' => false === $deleted ? 'import_error' : 'import_deleted' ), admin_url( 'admin.php' ) ) );
exit;
}

/**
 * Handles manual import execution for a specific import job.
 */
function eai_handle_manual_import_run() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You are not allowed to run imports.', 'enterprise-api-importer' ) );
}

check_admin_referer( 'eai_manual_run', 'eai_manual_run_nonce' );

$import_id = isset( $_POST['import_id'] ) ? absint( wp_unslash( $_POST['import_id'] ) ) : 0;
if ( $import_id <= 0 ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

$active_state = eai_get_active_run_state();
if ( ! empty( $active_state['run_id'] ) ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

$extract_result = eai_extract_and_stage_data( $import_id );
if ( is_wp_error( $extract_result ) ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

eai_set_active_run_state(
array(
'run_id'               => wp_generate_uuid4(),
'import_id'            => $import_id,
'trigger_source'       => 'manual',
'start_timestamp'      => time(),
'start_time'           => gmdate( 'Y-m-d H:i:s', time() ),
'rows_processed'       => 0,
'rows_created'         => 0,
'rows_updated'         => 0,
'temp_rows_found'      => 0,
'temp_rows_processed'  => 0,
'errors'               => array(),
'slices'               => 0,
)
);

// Process one slice immediately; remaining slices will self-schedule if needed.
eai_handle_scheduled_import_batch();

$notice_code = 'import_started';
if ( isset( $_POST['eai_template_sync'] ) && '1' === (string) wp_unslash( $_POST['eai_template_sync'] ) ) {
	$notice_code = 'template_sync_started';
}

wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id, 'eai_notice' => $notice_code ), admin_url( 'admin.php' ) ) );
exit;
}

/**
 * Handles endpoint test and payload preview for a specific import job.
 */
function eai_handle_test_import_endpoint() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You are not allowed to test endpoints.', 'enterprise-api-importer' ) );
}

check_admin_referer( 'eai_test_import_endpoint', 'eai_test_import_endpoint_nonce' );

$import_id = isset( $_POST['import_id'] ) ? absint( wp_unslash( $_POST['import_id'] ) ) : 0;
if ( $import_id <= 0 ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'eai_notice' => 'import_error' ), admin_url( 'admin.php' ) ) );
exit;
}

$preview_mode = isset( $_POST['eai_preview_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['eai_preview_mode'] ) ) : 'structure';
if ( 'mapping' !== $preview_mode ) {
$preview_mode = 'structure';
}

$import_job = eai_db_get_import_config( $import_id );

$result_key = 'eai_endpoint_test_result_' . $import_id;
$preview_result = array(
'type'         => 'error',
'message'      => __( 'Endpoint test failed.', 'enterprise-api-importer' ),
'http_code'    => 0,
'used_cache'   => false,
'json_path'    => '',
'preview_mode' => $preview_mode,
);

if ( ! is_array( $import_job ) ) {
$preview_result['message'] = __( 'Import job could not be found.', 'enterprise-api-importer' );
set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id ), admin_url( 'admin.php' ) ) );
exit;
}

$endpoint = trim( (string) $import_job['endpoint_url'] );
$token    = trim( (string) $import_job['auth_token'] );
$json_path = trim( (string) $import_job['array_path'] );

if ( '' === $endpoint ) {
$preview_result['message'] = __( 'No endpoint URL available to test.', 'enterprise-api-importer' );
set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id ), admin_url( 'admin.php' ) ) );
exit;
}

$response = eai_fetch_api_payload( $endpoint, $token, true );
if ( is_wp_error( $response ) ) {
$preview_result['message'] = $response->get_error_message();
set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id ), admin_url( 'admin.php' ) ) );
exit;
}

$decoded_json = json_decode( (string) $response['body'], true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
/* translators: %s is the JSON parser error message. */
$preview_result['message'] = sprintf( __( 'Endpoint responded but JSON decode failed: %s', 'enterprise-api-importer' ), json_last_error_msg() );
set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id ), admin_url( 'admin.php' ) ) );
exit;
}

$selected_payload = '' === $json_path ? $decoded_json : eai_resolve_json_array_path( $decoded_json, $json_path );
if ( is_wp_error( $selected_payload ) ) {
$preview_result['message'] = $selected_payload->get_error_message();
set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id ), admin_url( 'admin.php' ) ) );
exit;
}

$sample_item = null;
if ( is_array( $selected_payload ) ) {
$sample_item = eai_array_is_list( $selected_payload ) && ! empty( $selected_payload ) ? $selected_payload[0] : $selected_payload;
}

$sample_json = wp_json_encode( $sample_item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( false === $sample_json ) {
$sample_json = '';
}

$normalized_preview = array();
if ( 'mapping' === $preview_mode && is_array( $selected_payload ) ) {
$normalized_items = eai_normalize_staged_items( $selected_payload );
$preview_items = array_slice( $normalized_items, 0, 3 );
$record_number = 0;
foreach ( $preview_items as $preview_item ) {
++$record_number;
$mapped_content = eai_render_mapping_template_for_item( $preview_item, (string) $import_job['mapping_template'] );
$mapping_error = '';
if ( is_wp_error( $mapped_content ) ) {
$mapping_error = $mapped_content->get_error_message();
$mapped_content = '';
}
$normalized_preview[] = array(
'record_number' => $record_number,
'mapping_error' => $mapping_error,
'mapped_content' => substr( (string) $mapped_content, 0, 5000 ),
);
}
}

$preview_result = array(
'type'         => 'success',
'message'      => __( 'Endpoint test passed and payload preview generated.', 'enterprise-api-importer' ),
'http_code'    => isset( $response['status_code'] ) ? (int) $response['status_code'] : 0,
'used_cache'   => ! empty( $response['used_cache'] ),
'json_path'    => $json_path,
'payload_type' => gettype( $selected_payload ),
'item_count'   => is_array( $selected_payload ) ? count( $selected_payload ) : 0,
'sample_keys'  => is_array( $sample_item ) ? array_keys( $sample_item ) : array(),
'sample_json'  => substr( (string) $sample_json, 0, 6000 ),
'preview_mode' => $preview_mode,
'normalized_preview' => $normalized_preview,
);

set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-manage', 'action' => 'edit', 'id' => $import_id ), admin_url( 'admin.php' ) ) );
exit;
}

/**
 * Handles scheduling an immediate cron run for one import.
 */
function eai_handle_schedule_run_now_action() {
if ( ! current_user_can( 'manage_options' ) ) {
wp_die( esc_html__( 'You are not allowed to schedule imports.', 'enterprise-api-importer' ) );
}

$post_data = wp_unslash( $_POST );
$post_data = is_array( $post_data ) ? $post_data : array();

$import_id = isset( $post_data['import_id'] ) ? absint( $post_data['import_id'] ) : 0;
if ( $import_id <= 0 ) {
wp_safe_redirect( add_query_arg( array( 'page' => 'eapi-schedules', 'eai_notice' => 'schedule_error' ), admin_url( 'admin.php' ) ) );
exit;
}

check_admin_referer( 'eai_schedule_run_now_' . $import_id, 'eai_schedule_run_now_nonce' );

// Execute immediately so updates/deletes happen even when WP-Cron is delayed.
eai_handle_import_batch_hook( $import_id, 'run_now' );

$active_state = eai_get_active_run_state();
$scheduled = empty( $active_state['run_id'] ) || ( isset( $active_state['import_id'] ) && (int) $active_state['import_id'] === $import_id );

wp_safe_redirect(
add_query_arg(
array(
'page'       => 'eapi-schedules',
'eai_notice' => $scheduled ? 'schedule_now' : 'schedule_error',
),
admin_url( 'admin.php' )
)
);
exit;
}
