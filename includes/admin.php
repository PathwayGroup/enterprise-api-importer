<?php
/**
 * Admin job manager UI and handlers.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Determines whether the current user can manage import configuration features.
 *
 * @return bool
 */
function tporapdi_current_user_can_manage_imports() {
	// phpcs:ignore WordPress.WP.Capabilities.Unknown -- Custom capability is registered for administrators during activation and admin bootstrap.
	if ( current_user_can( 'tporapdi_manage_templates' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}

	if ( is_multisite() && is_super_admin() ) {
		return true;
	}

	return false;
}

/**
 * Returns the capability used to manage import configuration UI.
 *
 * @return string
 */
function tporapdi_get_manage_imports_capability() {
	return 'tporapdi_manage_templates';
}

/**
 * Validates the REST request nonce for admin tooling.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return true|WP_Error
 */

/**
 * Shared REST route permission callback for admin tooling.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return true|WP_Error
 */

/**
 * Determines whether the current user can access network-level importer features.
 *
 * @return bool
 */
function tporapdi_current_user_can_manage_network_imports() {
	return is_multisite() && current_user_can( 'manage_network_options' );
}

/**
 * Registers admin menu pages.
 */
function tporapdi_add_admin_pages() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		return;
	}

	add_menu_page(
		__( 'tporret API Data Importer', 'tporret-api-data-importer' ),
		__( 'EAPI', 'tporret-api-data-importer' ),
		tporapdi_get_manage_imports_capability(),
		TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
		'tporapdi_render_manage_imports_page',
		'dashicons-database-import',
		58
	);

	add_submenu_page(
		TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
		__( 'Manage Imports', 'tporret-api-data-importer' ),
		__( 'Manage Imports', 'tporret-api-data-importer' ),
		tporapdi_get_manage_imports_capability(),
		TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
		'tporapdi_render_manage_imports_page'
	);

	add_submenu_page(
		TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
		__( 'Schedules', 'tporret-api-data-importer' ),
		__( 'Schedules', 'tporret-api-data-importer' ),
		tporapdi_get_manage_imports_capability(),
		TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG,
		'tporapdi_render_schedules_page'
	);

	add_submenu_page(
		TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
		__( 'Settings', 'tporret-api-data-importer' ),
		__( 'Settings', 'tporret-api-data-importer' ),
		tporapdi_get_manage_imports_capability(),
		TPORAPDI_ADMIN_PAGE_SETTINGS_SLUG,
		'tporapdi_render_settings_page'
	);

	add_submenu_page(
		TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
		__( 'Dashboard', 'tporret-api-data-importer' ),
		__( 'Dashboard', 'tporret-api-data-importer' ),
		tporapdi_get_manage_imports_capability(),
		TPORAPDI_ADMIN_PAGE_DASHBOARD_SLUG,
		'tporapdi_render_dashboard_page'
	);
}
add_action( 'admin_menu', 'tporapdi_add_admin_pages' );

/**
 * Registers multisite-only Network Admin pages.
 *
 * @return void
 */
function tporapdi_add_network_admin_pages() {
	if ( ! tporapdi_current_user_can_manage_network_imports() ) {
		return;
	}

	add_menu_page(
		__( 'tporret API Data Importer Network', 'tporret-api-data-importer' ),
		__( 'EAPI Network', 'tporret-api-data-importer' ),
		'manage_network_options',
		TPORAPDI_ADMIN_PAGE_NETWORK_DASHBOARD_SLUG,
		'tporapdi_render_network_dashboard_page',
		'dashicons-database-import',
		58
	);
}
add_action( 'network_admin_menu', 'tporapdi_add_network_admin_pages' );

/**
 * Registers admin post handlers.
 */
function tporapdi_register_admin_post_handlers() {
	add_action( 'admin_post_tporapdi_save_import', 'tporapdi_handle_save_import' );
	add_action( 'admin_post_tporapdi_delete_import', 'tporapdi_handle_delete_import' );
	add_action( 'admin_post_tporapdi_run_import', 'tporapdi_handle_manual_import_run' );
	add_action( 'admin_post_tporapdi_test_import_endpoint', 'tporapdi_handle_test_import_endpoint' );
	add_action( 'admin_post_tporapdi_schedule_run_now', 'tporapdi_handle_schedule_run_now_action' );
	add_action( 'admin_post_tporapdi_save_settings', 'tporapdi_handle_save_settings' );
}
add_action( 'admin_init', 'tporapdi_register_admin_post_handlers' );
add_action( 'admin_init', 'tporapdi_sync_template_management_capabilities' );

/**
 * Renders plugin settings page.
 */
function tporapdi_render_settings_page() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'tporret-api-data-importer' ) );
	}

	$settings = wp_parse_args( get_option( 'tporapdi_settings', array() ), tporapdi_get_default_settings() );

	// Ensure the settings array has the necessary keys.
	if ( ! is_array( $settings ) ) {
		$settings = tporapdi_get_default_settings();
	}

	$hosts          = isset( $settings['allowed_endpoint_hosts'] ) ? (string) $settings['allowed_endpoint_hosts'] : '';
	$cidrs          = isset( $settings['allowed_endpoint_cidrs'] ) ? (string) $settings['allowed_endpoint_cidrs'] : '';
	$allow_internal = isset( $settings['allow_internal_endpoints'] ) ? (string) $settings['allow_internal_endpoints'] : '0';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'tporret API Data Importer Settings', 'tporret-api-data-importer' ); ?></h1>
		<?php tporapdi_render_admin_notices(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tporapdi_save_settings" />
			<?php wp_nonce_field( 'tporapdi_save_settings', 'tporapdi_settings_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="tporapdi_allowed_endpoint_hosts">
								<?php esc_html_e( 'Allowed Endpoint Hosts', 'tporret-api-data-importer' ); ?>
							</label>
						</th>
						<td>
							<textarea
								name="allowed_endpoint_hosts"
								id="tporapdi_allowed_endpoint_hosts"
								rows="6"
								class="large-text code"
								placeholder="api.example.com&#10;*.internal.example.com"
							><?php echo esc_textarea( $hosts ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Comma or newline-separated list of allowed hostnames. Use *.example.com for wildcard subdomains. Leave empty to allow any HTTPS host.', 'tporret-api-data-importer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="tporapdi_allowed_endpoint_cidrs">
								<?php esc_html_e( 'Allowed Endpoint CIDR Blocks', 'tporret-api-data-importer' ); ?>
							</label>
						</th>
						<td>
							<textarea
								name="allowed_endpoint_cidrs"
								id="tporapdi_allowed_endpoint_cidrs"
								rows="6"
								class="large-text code"
								placeholder="192.168.1.0/24&#10;10.0.0.0/8"
							><?php echo esc_textarea( $cidrs ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Comma or newline-separated list of CIDR blocks. Apply only when a corresponding hostname is in the allowed hosts list. Leave empty to disable CIDR-based filtering.', 'tporret-api-data-importer' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Allow Internal Endpoints', 'tporret-api-data-importer' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="allow_internal_endpoints"
									value="1"
									<?php checked( '1' === $allow_internal, true ); ?>
								/>
								<?php esc_html_e( 'Allow connections to private/internal IP addresses and localhost.', 'tporret-api-data-importer' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'For controlled/development environments only. Production should leave unchecked.', 'tporret-api-data-importer' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save Settings', 'tporret-api-data-importer' ) ); ?>
		</form>
	</div>
	<?php
}

/**
 * Handles plugin settings form submission.
 */
function tporapdi_handle_save_settings() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to perform this action.', 'tporret-api-data-importer' ) );
	}

	if ( ! isset( $_POST['tporapdi_settings_nonce'] ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_SETTINGS_SLUG,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	check_admin_referer( 'tporapdi_save_settings', 'tporapdi_settings_nonce' );

	$allowed_endpoint_hosts = isset( $_POST['allowed_endpoint_hosts'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['allowed_endpoint_hosts'] ) ) : '';
	$allowed_endpoint_cidrs = isset( $_POST['allowed_endpoint_cidrs'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['allowed_endpoint_cidrs'] ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Reduced to a strict 1/0 toggle immediately after unslashing.
	$allow_internal_endpoints = isset( $_POST['allow_internal_endpoints'] ) && '1' === (string) wp_unslash( $_POST['allow_internal_endpoints'] ) ? '1' : '0';

	$current_settings = get_option( 'tporapdi_settings', array() );
	$current_settings = is_array( $current_settings ) ? $current_settings : array();

	$defaults = tporapdi_get_default_settings();
	$defaults = is_array( $defaults ) ? $defaults : array();

	$settings = wp_parse_args( $current_settings, $defaults );

	$settings['allowed_endpoint_hosts']   = $allowed_endpoint_hosts;
	$settings['allowed_endpoint_cidrs']   = $allowed_endpoint_cidrs;
	$settings['allow_internal_endpoints'] = $allow_internal_endpoints;

	$updated = update_option( 'tporapdi_settings', $settings );

	$redirect_url = add_query_arg(
		array(
			'page'            => TPORAPDI_ADMIN_PAGE_SETTINGS_SLUG,
			'tporapdi_notice' => 'settings_saved',
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $redirect_url );
	exit;
}

/**
 * Preserves existing encrypted credential values when the frontend sends blank fields.
 *
 * The REST GET masks credentials, so the frontend cannot echo them back.
 * When updating, blank credential values mean "unchanged" — we must read
 * the existing encrypted value from the DB and re-use it.
 *
 * @param array<string, mixed> &$data      Sanitized save data (modified by reference).
 * @param int                  $import_id  Existing import job ID.
 */
function tporapdi_preserve_unchanged_credentials( array &$data, int $import_id ) {
	global $wpdb;

	$table = tporapdi_db_imports_table();

	// Read raw (encrypted) values directly — do NOT decrypt.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$existing_raw = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT auth_token, auth_password FROM %i WHERE id = %d',
			$table,
			absint( $import_id )
		),
		ARRAY_A
	);

	if ( ! is_array( $existing_raw ) ) {
		return;
	}

	foreach ( tporapdi_get_credential_field_names() as $field ) {
		if ( isset( $data[ $field ] ) && '' === (string) $data[ $field ] && isset( $existing_raw[ $field ] ) ) {
			$data[ $field ] = $existing_raw[ $field ];
		}
	}
}

/**
 * REST: Creates a new import job.
 *
 * @param WP_REST_Request $request REST request.
 *
 * @return WP_REST_Response
 */

/**
 * Renders admin notices for this plugin.
 */
function tporapdi_render_admin_notices() {
	$network_activation_reverted = (int) get_site_option( 'tporapdi_network_activation_reverted', 0 );
	if ( $network_activation_reverted > 0 && ( is_network_admin() || is_main_site() ) ) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Multisite note: a network activation attempt was automatically reverted. Keep tporret API Data Importer active on the primary site for the Network Admin dashboard, and activate it separately on each subsite that should run imports.', 'tporret-api-data-importer' );
		echo '</p></div>';
		delete_site_option( 'tporapdi_network_activation_reverted' );
	}

	if ( tporapdi_should_render_primary_site_dashboard_notice() ) {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Multisite note: the Network Admin dashboard appears only when tporret API Data Importer is also active on the primary site. Keep this subsite active for local imports, and activate the plugin on the primary site if you want the network-level dashboard.', 'tporret-api-data-importer' );
		echo '</p></div>';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code for admin UI messaging.
	$notice_code = isset( $_GET['tporapdi_notice'] ) ? sanitize_key( (string) wp_unslash( $_GET['tporapdi_notice'] ) ) : '';
	if ( '' === $notice_code ) {
		return;
	}

	$messages = array(
		'import_saved'          => array( 'success', __( 'Import job saved.', 'tporret-api-data-importer' ) ),
		'import_deleted'        => array( 'success', __( 'Import job deleted.', 'tporret-api-data-importer' ) ),
		'import_started'        => array( 'success', __( 'Import queued successfully.', 'tporret-api-data-importer' ) ),
		'template_sync_started' => array( 'success', __( 'Template sync started. Existing imported items will be re-rendered for this import job.', 'tporret-api-data-importer' ) ),
		'schedule_now'          => array( 'success', __( 'Import scheduled to run now.', 'tporret-api-data-importer' ) ),
		'import_error'          => array( 'error', __( 'The import request failed. Please review your inputs and try again.', 'tporret-api-data-importer' ) ),
		'schedule_error'        => array( 'error', __( 'Unable to schedule this import right now.', 'tporret-api-data-importer' ) ),
		'settings_saved'        => array( 'success', __( 'Settings saved.', 'tporret-api-data-importer' ) ),
	);

	if ( ! isset( $messages[ $notice_code ] ) ) {
		return;
	}

	list( $type, $message ) = $messages[ $notice_code ];

	$class = 'success' === $type ? 'notice notice-success is-dismissible' : 'notice notice-error';

	echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';

	if ( 'import_error' === $notice_code ) {
		$save_error = get_transient( 'tporapdi_last_import_save_error' );
		if ( is_string( $save_error ) && '' !== $save_error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $save_error ) . '</p></div>';
			delete_transient( 'tporapdi_last_import_save_error' );
		}
	}
}

/**
 * Determines whether to show the primary-site activation reminder in multisite.
 *
 * @return bool
 */
function tporapdi_should_render_primary_site_dashboard_notice() {
	if ( ! is_multisite() || is_network_admin() || is_main_site() ) {
		return false;
	}

	$main_site_id = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id() : 1;

	if ( get_current_blog_id() === $main_site_id ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( is_plugin_active_for_network( TPORAPDI_PLUGIN_BASENAME ) ) {
		return false;
	}

	$active_plugins = (array) get_blog_option( $main_site_id, 'active_plugins', array() );

	return ! in_array( TPORAPDI_PLUGIN_BASENAME, $active_plugins, true );
}

/**
 * Renders manage imports page.
 */
function tporapdi_render_manage_imports_page() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing state for admin screen rendering.
	$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';

	if ( 'edit' === $action ) {
		tporapdi_render_import_edit_page();
		return;
	}

	tporapdi_render_imports_list_page();
}

/**
 * Renders list view using WP_List_Table.
 */
function tporapdi_render_imports_list_page() {
	$list_table = new TPORAPDI_Imports_List_Table();
	$list_table->prepare_items();
	$total_imports     = count( tporapdi_db_get_import_configs() );
	$ownership_counts  = tporapdi_get_import_post_ownership_counts();
	$total_owned_posts = 0;
	foreach ( $ownership_counts as $ownership_row ) {
		$total_owned_posts += isset( $ownership_row['post_count'] ) ? (int) $ownership_row['post_count'] : 0;
	}

	$new_url = add_query_arg(
		array(
			'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
			'action' => 'edit',
		),
		admin_url( 'admin.php' )
	);

	$active_state = tporapdi_get_active_run_state();
	?>
	<div class="wrap eapi-manage-shell">

		<div class="eapi-manage-topbar">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Manage Imports', 'tporret-api-data-importer' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'tporret-api-data-importer' ); ?></a>
		</div>

		<hr class="wp-header-end" />
		<?php tporapdi_render_admin_notices(); ?>

		<div class="eapi-manage-kpis">
			<div class="eapi-manage-kpi">
				<div class="eapi-manage-kpi-label"><?php esc_html_e( 'Import Jobs', 'tporret-api-data-importer' ); ?></div>
				<div class="eapi-manage-kpi-value"><?php echo esc_html( (string) (int) $total_imports ); ?></div>
			</div>
			<div class="eapi-manage-kpi">
				<div class="eapi-manage-kpi-label"><?php esc_html_e( 'Imported Posts Linked', 'tporret-api-data-importer' ); ?></div>
				<div class="eapi-manage-kpi-value"><?php echo esc_html( (string) (int) $total_owned_posts ); ?></div>
			</div>
			<div class="eapi-manage-kpi">
				<div class="eapi-manage-kpi-label"><?php esc_html_e( 'Queue State', 'tporret-api-data-importer' ); ?></div>
				<div class="eapi-manage-kpi-value">
					<?php if ( ! empty( $active_state['run_id'] ) ) : ?>
						<span class="eapi-manage-running">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d is the active import job ID. */
									__( 'Running #%d', 'tporret-api-data-importer' ),
									isset( $active_state['import_id'] ) ? (int) $active_state['import_id'] : 0
								)
							);
							?>
						</span>
					<?php else : ?>
						<?php esc_html_e( 'Idle', 'tporret-api-data-importer' ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="eapi-manage-card">
			<div class="eapi-manage-card-header">
				<h2 class="eapi-manage-card-title"><?php esc_html_e( 'Import Jobs Table', 'tporret-api-data-importer' ); ?></h2>
			</div>
			<div class="eapi-manage-card-body">
				<form method="post">
					<?php $list_table->display(); ?>
				</form>
			</div>
		</div>

		<div class="eapi-manage-card">
			<div class="eapi-manage-card-header">
				<h2 class="eapi-manage-card-title"><?php esc_html_e( 'Debug: Imported Post Ownership', 'tporret-api-data-importer' ); ?></h2>
			</div>
			<div class="eapi-manage-card-body">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d is total number of imported posts linked to any import job via _tporapdi_import_id meta. */
							__( 'Total imported posts linked to import jobs (_tporapdi_import_id): %d', 'tporret-api-data-importer' ),
							(int) $total_owned_posts
						)
					);
					?>
				</p>

				<table class="widefat striped eapi-admin-table eapi-ownership-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Import ID Meta Value', 'tporret-api-data-importer' ); ?></th>
							<th><?php esc_html_e( 'Matched Import Job', 'tporret-api-data-importer' ); ?></th>
							<th><?php esc_html_e( 'Imported Post Count', 'tporret-api-data-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $ownership_counts ) ) : ?>
						<tr>
							<td colspan="3"><?php esc_html_e( 'No imported posts with _tporapdi_import_id found.', 'tporret-api-data-importer' ); ?></td>
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
												__( '(ID #%d)', 'tporret-api-data-importer' ),
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
		</div>
	</div>
	<?php
}

/**
 * Gets imported post ownership grouped by _tporapdi_import_id.
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_get_import_post_ownership_counts() {
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
			'_tporapdi_import_id',
			'tporapdi_item'
		),
		ARRAY_A
	);

	if ( ! is_array( $rows ) ) {
		return array();
	}

	$import_rows  = tporapdi_db_get_import_configs();
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
		$import_name   = $has_match ? (string) $import_names[ $import_id ] : __( 'Unknown import job', 'tporret-api-data-importer' );

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
function tporapdi_get_dashboard_metrics() {
	$rows          = tporapdi_db_get_import_configs();
	$latest_logs   = tporapdi_db_get_latest_logs_indexed_by_import_id();
	$pending_index = tporapdi_db_get_pending_counts_by_import_id();

	$metrics      = array();
	$active_state = tporapdi_get_active_run_state();

	foreach ( $rows as $row ) {
		$import_id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
		if ( $import_id <= 0 ) {
			continue;
		}

		$pending_count = isset( $pending_index[ $import_id ] ) ? (int) $pending_index[ $import_id ] : 0;

		$next_scheduled = wp_next_scheduled( 'tporapdi_recurring_import_trigger', array( $import_id, 'recurring' ) );
		if ( false === $next_scheduled ) {
			$next_scheduled = wp_next_scheduled( 'tporapdi_recurring_import_trigger', array( $import_id ) );
		}

		$status = 'idle';
		if ( $pending_count > 0 ) {
			$status = 'processing';
		} elseif ( ! empty( $latest_logs[ $import_id ]['status'] ) ) {
			$status = strtolower( (string) $latest_logs[ $import_id ]['status'] );
		}

		$error_count     = 0;
		$details_summary = '';
		$trigger_source  = 'unknown';
		$rows_trashed    = 0;
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
				$error_count     = 1;
				$details_summary = sanitize_text_field( (string) $decoded_errors['error'] );
				if ( ! empty( $decoded_errors['trigger_source'] ) ) {
					$trigger_source = sanitize_key( (string) $decoded_errors['trigger_source'] );
				}
			} else {
				$error_count     = 1;
				$details_summary = sanitize_text_field( (string) $latest_logs[ $import_id ]['errors'] );
			}
		}

		if ( 'unknown' === $trigger_source && ! empty( $active_state['import_id'] ) && (int) $active_state['import_id'] === $import_id && ! empty( $active_state['trigger_source'] ) ) {
			$trigger_source = sanitize_key( (string) $active_state['trigger_source'] );
		}

		if ( '' === $details_summary ) {
			if ( 'processing' === $status ) {
				$details_summary = __( 'Import has staged rows waiting for worker processing.', 'tporret-api-data-importer' );
			} elseif ( ! empty( $latest_logs[ $import_id ]['status'] ) ) {
				$details_summary = sprintf(
				/* translators: %s is the latest log status. */
					__( 'Last status: %s', 'tporret-api-data-importer' ),
					sanitize_text_field( (string) $latest_logs[ $import_id ]['status'] )
				);
			} else {
				$details_summary = __( 'No execution details available yet.', 'tporret-api-data-importer' );
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
			'next_scheduled'    => false !== $next_scheduled ? wp_date( 'Y-m-d H:i:s', (int) $next_scheduled ) : __( 'Not scheduled', 'tporret-api-data-importer' ),
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
function tporapdi_get_status_badge_data( $status ) {
	$status = strtolower( (string) $status );

	if ( 'processing' === $status ) {
		return array(
			'class' => 'eai-badge is-processing',
			'label' => __( 'Processing', 'tporret-api-data-importer' ),
		);
	}

	if ( 'success' === $status ) {
		return array(
			'class' => 'eai-badge is-success',
			'label' => __( 'Success', 'tporret-api-data-importer' ),
		);
	}

	if ( 'failed' === $status || 'completed_with_errors' === $status ) {
		return array(
			'class' => 'eai-badge is-failed',
			'label' => __( 'Failed', 'tporret-api-data-importer' ),
		);
	}

	return array(
		'class' => 'eai-badge is-idle',
		'label' => __( 'Idle', 'tporret-api-data-importer' ),
	);
}

/**
 * Returns user-facing trigger source label.
 *
 * @param string $trigger_source Trigger source key.
 *
 * @return string
 */
function tporapdi_get_trigger_source_label( $trigger_source ) {
	$trigger_source = sanitize_key( (string) $trigger_source );

	if ( 'manual' === $trigger_source ) {
		return __( 'Manual Run', 'tporret-api-data-importer' );
	}

	if ( 'run_now' === $trigger_source ) {
		return __( 'Run Now', 'tporret-api-data-importer' );
	}

	if ( 'recurring' === $trigger_source ) {
		return __( 'Recurring Schedule', 'tporret-api-data-importer' );
	}

	return __( 'Unknown', 'tporret-api-data-importer' );
}

/**
 * Renders shared Tableau-style admin UI CSS for EAPI tables.
 *
 * @return void
 */
function tporapdi_render_shared_tableau_admin_styles() {
	static $printed = false;

	if ( $printed ) {
		return;
	}

	$printed = true;
	// CSS is now enqueued via tporapdi_enqueue_admin_page_styles().
}

/**
 * Returns validated dashboard sorting arguments from request.
 *
 * @return array{orderby:string,order:string}
 */
function tporapdi_get_dashboard_sorting_args() {
	$allowed_orderby = array( 'name', 'status', 'trigger_source', 'last_run_at', 'rows_created', 'rows_updated', 'rows_trashed', 'error_count', 'next_scheduled' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting parameters.
	$orderby = isset( $_GET['orderby'] ) ? sanitize_key( (string) wp_unslash( $_GET['orderby'] ) ) : 'name';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting parameters.
	$order = isset( $_GET['order'] ) ? strtolower( sanitize_key( (string) wp_unslash( $_GET['order'] ) ) ) : 'asc';

	if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
		$orderby = 'name';
	}

	if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
		$order = 'asc';
	}

	return array(
		'orderby' => $orderby,
		'order'   => $order,
	);
}

/**
 * Sorts schedule dashboard metrics by one supported column.
 *
 * @param array<int, array<string, mixed>> $metrics Metrics rows.
 * @param string                           $orderby Order by field.
 * @param string                           $order   Sort direction.
 *
 * @return array<int, array<string, mixed>>
 */
function tporapdi_sort_dashboard_metrics( array $metrics, $orderby, $order ) {
	usort(
		$metrics,
		static function ( $left, $right ) use ( $orderby, $order ) {
			$comparison = 0;

			switch ( $orderby ) {
				case 'status':
				case 'trigger_source':
					$left_value  = isset( $left[ $orderby ] ) ? (string) $left[ $orderby ] : '';
					$right_value = isset( $right[ $orderby ] ) ? (string) $right[ $orderby ] : '';
					$comparison  = strcasecmp( $left_value, $right_value );
					break;

				case 'last_run_at':
					$left_value  = isset( $left['last_run_at'] ) && '' !== (string) $left['last_run_at'] ? strtotime( (string) $left['last_run_at'] ) : 0;
					$right_value = isset( $right['last_run_at'] ) && '' !== (string) $right['last_run_at'] ? strtotime( (string) $right['last_run_at'] ) : 0;
					$left_value  = false === $left_value ? 0 : (int) $left_value;
					$right_value = false === $right_value ? 0 : (int) $right_value;
					$comparison  = $left_value <=> $right_value;
					break;

				case 'rows_created':
				case 'rows_updated':
				case 'rows_trashed':
				case 'error_count':
					$left_value  = isset( $left[ $orderby ] ) ? (int) $left[ $orderby ] : 0;
					$right_value = isset( $right[ $orderby ] ) ? (int) $right[ $orderby ] : 0;
					$comparison  = $left_value <=> $right_value;
					break;

				case 'next_scheduled':
					$left_value  = isset( $left['next_scheduled_ts'] ) ? (int) $left['next_scheduled_ts'] : 0;
					$right_value = isset( $right['next_scheduled_ts'] ) ? (int) $right['next_scheduled_ts'] : 0;
					$comparison  = $left_value <=> $right_value;
					break;

				case 'name':
				default:
					$left_value  = isset( $left['name'] ) ? (string) $left['name'] : '';
					$right_value = isset( $right['name'] ) ? (string) $right['name'] : '';
					$comparison  = strcasecmp( $left_value, $right_value );
					break;
			}

			return 'asc' === $order ? $comparison : -1 * $comparison;
		}
	);

	return $metrics;
}

/**
 * Renders one sortable dashboard table header link.
 *
 * @param string $label            Header label.
 * @param string $column_orderby   Orderby key for this column.
 * @param string $current_orderby  Current orderby value.
 * @param string $current_order    Current order direction.
 *
 * @return void
 */
function tporapdi_render_dashboard_sortable_header( $label, $column_orderby, $current_orderby, $current_order ) {
	$is_active  = $column_orderby === $current_orderby;
	$next_order = ( $is_active && 'asc' === $current_order ) ? 'desc' : 'asc';

	$url = add_query_arg(
		array(
			'page'    => TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG,
			'orderby' => $column_orderby,
			'order'   => $next_order,
		),
		admin_url( 'admin.php' )
	);

	$indicator = '';
	if ( $is_active ) {
		$indicator = 'asc' === $current_order ? ' ↑' : ' ↓';
	}

	echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label . $indicator ) . '</a>';
}

/**
 * Renders schedules dashboard page.
 */
function tporapdi_render_schedules_page() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		return;
	}

	$metrics = tporapdi_get_dashboard_metrics();
	$sorting = tporapdi_get_dashboard_sorting_args();
	$metrics = tporapdi_sort_dashboard_metrics( $metrics, $sorting['orderby'], $sorting['order'] );
	?>
<div class="wrap">
<h1><?php esc_html_e( 'Schedules & Health Dashboard', 'tporret-api-data-importer' ); ?></h1>
	<?php tporapdi_render_admin_notices(); ?>

<table class="widefat striped eapi-admin-table eai-schedules-table">
<thead>
<tr>
<th><?php tporapdi_render_dashboard_sortable_header( __( 'Import Name & ID', 'tporret-api-data-importer' ), 'name', $sorting['orderby'], $sorting['order'] ); ?></th>
<th class="column-status"><?php tporapdi_render_dashboard_sortable_header( __( 'Status', 'tporret-api-data-importer' ), 'status', $sorting['orderby'], $sorting['order'] ); ?></th>
<th><?php tporapdi_render_dashboard_sortable_header( __( 'Trigger Source', 'tporret-api-data-importer' ), 'trigger_source', $sorting['orderby'], $sorting['order'] ); ?></th>
<th><?php tporapdi_render_dashboard_sortable_header( __( 'Last Run Time', 'tporret-api-data-importer' ), 'last_run_at', $sorting['orderby'], $sorting['order'] ); ?></th>
<th><?php esc_html_e( 'Last Run Metrics', 'tporret-api-data-importer' ); ?></th>
<th><?php esc_html_e( 'Details', 'tporret-api-data-importer' ); ?></th>
<th><?php tporapdi_render_dashboard_sortable_header( __( 'Next Scheduled Run', 'tporret-api-data-importer' ), 'next_scheduled', $sorting['orderby'], $sorting['order'] ); ?></th>
<th class="column-actions"><?php esc_html_e( 'Actions', 'tporret-api-data-importer' ); ?></th>
</tr>
</thead>
<tbody>
	<?php if ( empty( $metrics ) ) : ?>
<tr><td colspan="8"><?php esc_html_e( 'No import jobs found.', 'tporret-api-data-importer' ); ?></td></tr>
<?php else : ?>
	<?php foreach ( $metrics as $metric ) : ?>
		<?php $badge = tporapdi_get_status_badge_data( isset( $metric['status'] ) ? (string) $metric['status'] : 'idle' ); ?>
<tr>
<td>
<strong><?php echo esc_html( isset( $metric['name'] ) ? (string) $metric['name'] : '' ); ?></strong><br />
<span>
		<?php
		/* translators: %d is the import job ID. */
		echo esc_html( sprintf( __( 'ID: %d', 'tporret-api-data-importer' ), isset( $metric['id'] ) ? (int) $metric['id'] : 0 ) );
		?>
</span>
</td>
<td class="column-status"><span class="<?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge['label'] ); ?></span></td>
<td><?php echo esc_html( tporapdi_get_trigger_source_label( isset( $metric['trigger_source'] ) ? (string) $metric['trigger_source'] : 'unknown' ) ); ?></td>
<td><?php echo esc_html( ! empty( $metric['last_run_at'] ) ? (string) $metric['last_run_at'] : __( 'Never', 'tporret-api-data-importer' ) ); ?></td>
<td>
		<?php
		echo esc_html(
			sprintf(
			/* translators: 1: rows created, 2: rows updated, 3: rows trashed, 4: error count. */
				__( 'Created: %1$d | Updated: %2$d | Trashed: %3$d | Errors: %4$d', 'tporret-api-data-importer' ),
				isset( $metric['rows_created'] ) ? (int) $metric['rows_created'] : 0,
				isset( $metric['rows_updated'] ) ? (int) $metric['rows_updated'] : 0,
				isset( $metric['rows_trashed'] ) ? (int) $metric['rows_trashed'] : 0,
				isset( $metric['error_count'] ) ? (int) $metric['error_count'] : 0
			)
		);
		?>
</td>
<td><?php echo esc_html( isset( $metric['details_summary'] ) ? substr( (string) $metric['details_summary'], 0, 280 ) : '' ); ?></td>
<td><?php echo esc_html( isset( $metric['next_scheduled'] ) ? (string) $metric['next_scheduled'] : __( 'Not scheduled', 'tporret-api-data-importer' ) ); ?></td>
<td class="column-actions">
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
<input type="hidden" name="action" value="tporapdi_schedule_run_now" />
<input type="hidden" name="import_id" value="<?php echo esc_attr( (string) ( isset( $metric['id'] ) ? (int) $metric['id'] : 0 ) ); ?>" />
		<?php wp_nonce_field( 'tporapdi_schedule_run_now_' . (int) $metric['id'], 'tporapdi_schedule_run_now_nonce' ); ?>
		<?php submit_button( __( 'Run Now', 'tporret-api-data-importer' ), 'secondary', 'submit', false ); ?>
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
function tporapdi_get_filter_operator_options() {
	return array(
		'equals'       => __( 'Equals', 'tporret-api-data-importer' ),
		'not_equals'   => __( 'Not Equals', 'tporret-api-data-importer' ),
		'contains'     => __( 'Contains', 'tporret-api-data-importer' ),
		'not_contains' => __( 'Not Contains', 'tporret-api-data-importer' ),
		'is_empty'     => __( 'Is Empty', 'tporret-api-data-importer' ),
		'not_empty'    => __( 'Not Empty', 'tporret-api-data-importer' ),
		'greater_than' => __( 'Greater Than', 'tporret-api-data-importer' ),
		'less_than'    => __( 'Less Than', 'tporret-api-data-importer' ),
	);
}

/**
 * Renders create/edit import page (React-powered workspace).
 */
function tporapdi_render_import_edit_page() {
	echo '<div class="wrap"><div id="' . esc_attr( TPORAPDI_ADMIN_IMPORT_JOB_ROOT_ID ) . '"></div></div>';
}

/**
 * Handles create/update import form submits.
 */
function tporapdi_handle_save_import() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to manage imports.', 'tporret-api-data-importer' ) );
	}

	check_admin_referer( 'tporapdi_save_import', 'tporapdi_save_import_nonce' );

	$import_id       = isset( $_POST['import_id'] ) ? absint( wp_unslash( $_POST['import_id'] ) ) : 0;
	$previous_import = $import_id > 0 ? tporapdi_db_get_import_config( $import_id ) : null;

	$name = isset( $_POST['name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['name'] ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with esc_url_raw() after unslashing.
	$endpoint_url     = isset( $_POST['endpoint_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['endpoint_url'] ) ) ) : '';
	$auth_method      = isset( $_POST['auth_method'] ) ? sanitize_key( (string) wp_unslash( $_POST['auth_method'] ) ) : 'none';
	$auth_header_name = isset( $_POST['auth_header_name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['auth_header_name'] ) ) : '';
	$auth_username    = isset( $_POST['auth_username'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['auth_username'] ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords are unslashed verbatim, validated by auth mode, then encrypted before storage.
	$auth_password = isset( $_POST['auth_password'] ) ? (string) wp_unslash( $_POST['auth_password'] ) : '';

	// Resolve the token value from the correct form field per method.
	$auth_token = '';
	if ( 'bearer' === $auth_method ) {
		$auth_token = isset( $_POST['auth_token'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['auth_token'] ) ) : '';
	} elseif ( 'api_key_custom' === $auth_method ) {
		$auth_token = isset( $_POST['auth_token_apikey'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['auth_token_apikey'] ) ) : '';
	}
	$array_path              = isset( $_POST['array_path'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['array_path'] ) ) : '';
	$unique_id_path          = isset( $_POST['unique_id_path'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['unique_id_path'] ) ) : 'id';
	$recurrence              = isset( $_POST['recurrence'] ) ? sanitize_key( (string) wp_unslash( $_POST['recurrence'] ) ) : 'off';
	$custom_interval_minutes = isset( $_POST['custom_interval_minutes'] ) ? absint( wp_unslash( $_POST['custom_interval_minutes'] ) ) : 0;
	$target_post_type        = isset( $_POST['target_post_type'] ) ? sanitize_key( (string) wp_unslash( $_POST['target_post_type'] ) ) : 'post';
	$title_template          = isset( $_POST['title_template'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['title_template'] ) ) : '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Template content must remain raw until Twig validation and wp_kses filtering run.
	$template_raw     = isset( $_POST['mapping_template'] ) ? (string) wp_unslash( $_POST['mapping_template'] ) : '';
	$unique_id_path   = trim( (string) $unique_id_path );
	$target_post_type = trim( (string) $target_post_type );
	if ( '' === $target_post_type || 'attachment' === $target_post_type ) {
		$target_post_type = 'post';
	}
	$title_template = trim( (string) $title_template );
	$title_template = mb_substr( $title_template, 0, 255 );

	$allowed_recurrence = array( 'off', 'hourly', 'twicedaily', 'daily', 'custom' );
	if ( ! in_array( $recurrence, $allowed_recurrence, true ) ) {
		$recurrence = 'off';
	}

	$allowed_auth_methods = array( 'none', 'bearer', 'api_key_custom', 'basic_auth' );
	if ( ! in_array( $auth_method, $allowed_auth_methods, true ) ) {
		$auth_method = 'none';
	}

	// Clear fields that don't apply to the selected method.
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

	// Encrypt credential fields before storage.
	$auth_token    = tporapdi_encrypt_credential( $auth_token );
	$auth_password = tporapdi_encrypt_credential( $auth_password );

	if ( 'custom' === $recurrence ) {
		$custom_interval_minutes = $custom_interval_minutes > 0 ? $custom_interval_minutes : 30;
	} else {
		$custom_interval_minutes = 0;
	}

	if ( '' === $unique_id_path ) {
		$unique_id_path = 'id';
	}

	$allowed_mapping_html = array(
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
	$mapping_template     = tporapdi_kses_mapping_template( (string) $template_raw, $allowed_mapping_html );

	$title_template_validation = tporapdi_validate_twig_template_security( $title_template, 'title' );
	if ( is_wp_error( $title_template_validation ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action'          => 'edit',
					'id'              => $import_id,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// For new imports, templates are optional. For existing imports, require mapping template.
	$is_new_import            = 0 === $import_id;
	$requires_templates       = ! $is_new_import;
	$mapping_template_missing = '' === $mapping_template;

	if ( $requires_templates || ( $is_new_import && $mapping_template_missing && $title_template_missing ) ) {
		// Only validate if template is provided.
		if ( '' !== $mapping_template ) {
			$mapping_template_validation = tporapdi_validate_twig_template_security( $mapping_template, 'mapping' );
			if ( is_wp_error( $mapping_template_validation ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
							'action'          => 'edit',
							'id'              => $import_id,
							'tporapdi_notice' => 'import_error',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}
	}

	$filter_operator_options = tporapdi_get_filter_operator_options();
	$allowed_operators       = array_keys( $filter_operator_options );
	$filter_rules            = array();

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Filter rule arrays are unslashed first, then each member is sanitized per field below.
	$raw_filter_rules = isset( $_POST['filter_rules'] ) ? wp_unslash( $_POST['filter_rules'] ) : array();
	if ( ! empty( $raw_filter_rules ) ) {
		$rule_keys      = isset( $raw_filter_rules['key'] ) && is_array( $raw_filter_rules['key'] ) ? $raw_filter_rules['key'] : array();
		$rule_operators = isset( $raw_filter_rules['operator'] ) && is_array( $raw_filter_rules['operator'] ) ? $raw_filter_rules['operator'] : array();
		$rule_values    = isset( $raw_filter_rules['value'] ) && is_array( $raw_filter_rules['value'] ) ? $raw_filter_rules['value'] : array();

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

	if ( '' === $name || '' === $endpoint_url || ( $import_id > 0 && '' === $mapping_template ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action'          => 'edit',
					'id'              => $import_id,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$data    = array(
		'name'                    => $name,
		'endpoint_url'            => $endpoint_url,
		'auth_method'             => $auth_method,
		'auth_token'              => $auth_token,
		'auth_header_name'        => $auth_header_name,
		'auth_username'           => $auth_username,
		'auth_password'           => $auth_password,
		'array_path'              => $array_path,
		'unique_id_path'          => $unique_id_path,
		'recurrence'              => $recurrence,
		'custom_interval_minutes' => $custom_interval_minutes,
		'filter_rules'            => (string) $filter_rules_json,
		'target_post_type'        => $target_post_type,
		'title_template'          => $title_template,
		'mapping_template'        => $mapping_template,
	);
	$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );

	// Preserve existing encrypted credentials when blank values are submitted for an existing import.
	if ( $import_id > 0 ) {
		tporapdi_preserve_unchanged_credentials( $data, $import_id );
	}

	$persisted_import_id = tporapdi_db_save_import_config( $import_id, $data, $formats );
	if ( is_wp_error( $persisted_import_id ) ) {
		set_transient( 'tporapdi_last_import_save_error', $persisted_import_id->get_error_message(), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action'          => 'edit',
					'id'              => $import_id,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$import_id = (int) $persisted_import_id;

	tporapdi_audit_template_configuration_change( $import_id, $previous_import, $data );

	$schedule_synced = tporapdi_sync_import_recurrence_schedule( $import_id, $recurrence, $custom_interval_minutes );
	if ( ! $schedule_synced ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action'          => 'edit',
					'id'              => $import_id,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
				'action'          => 'edit',
				'id'              => $import_id,
				'tporapdi_notice' => 'import_saved',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Writes audit entries when template fields are created or changed.
 *
 * @param int                       $import_id      Persisted import ID.
 * @param array<string, mixed>|null $previous_import Previous saved import row.
 * @param array<string, mixed>      $new_data       New import data payload.
 * @return void
 */
function tporapdi_audit_template_configuration_change( $import_id, $previous_import, $new_data ) {
	$previous_import = is_array( $previous_import ) ? $previous_import : array();
	$new_data        = is_array( $new_data ) ? $new_data : array();

	$old_title   = isset( $previous_import['title_template'] ) ? (string) $previous_import['title_template'] : '';
	$old_mapping = isset( $previous_import['mapping_template'] ) ? (string) $previous_import['mapping_template'] : '';
	$new_title   = isset( $new_data['title_template'] ) ? (string) $new_data['title_template'] : '';
	$new_mapping = isset( $new_data['mapping_template'] ) ? (string) $new_data['mapping_template'] : '';

	$is_create = empty( $previous_import );
	$is_change = $is_create || $old_title !== $new_title || $old_mapping !== $new_mapping;

	if ( ! $is_change ) {
		return;
	}

	$current_user = wp_get_current_user();
	$actor_login  = $current_user instanceof WP_User ? (string) $current_user->user_login : '';
	$actor_email  = $current_user instanceof WP_User ? (string) $current_user->user_email : '';

	$details = array(
		'audit_type'               => $is_create ? 'template_config_created' : 'template_config_updated',
		'template_import_id'       => (int) $import_id,
		'actor_user_id'            => get_current_user_id(),
		'actor_user_login'         => $actor_login,
		'actor_user_email'         => $actor_email,
		'title_template_changed'   => $old_title !== $new_title,
		'mapping_template_changed' => $old_mapping !== $new_mapping,
		'old_title_sha256'         => '' !== $old_title ? hash( 'sha256', $old_title ) : '',
		'new_title_sha256'         => '' !== $new_title ? hash( 'sha256', $new_title ) : '',
		'old_mapping_sha256'       => '' !== $old_mapping ? hash( 'sha256', $old_mapping ) : '',
		'new_mapping_sha256'       => '' !== $new_mapping ? hash( 'sha256', $new_mapping ) : '',
		'old_title_length'         => strlen( $old_title ),
		'new_title_length'         => strlen( $new_title ),
		'old_mapping_length'       => strlen( $old_mapping ),
		'new_mapping_length'       => strlen( $new_mapping ),
	);

	tporapdi_write_import_log(
		0,
		wp_generate_uuid4(),
		'template_audit',
		0,
		0,
		0,
		$details,
		gmdate( 'Y-m-d H:i:s', time() )
	);
}

/**
 * Handles delete import action.
 */
function tporapdi_handle_delete_import() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to manage imports.', 'tporret-api-data-importer' ) );
	}

	$import_id = isset( $_GET['import_id'] ) ? absint( wp_unslash( $_GET['import_id'] ) ) : 0;
	if ( $import_id <= 0 ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	check_admin_referer( 'tporapdi_delete_import_' . $import_id, 'tporapdi_delete_nonce' );

	tporapdi_clear_import_scheduled_hooks( $import_id );

	$deleted = tporapdi_db_delete_import_config( $import_id );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
				'tporapdi_notice' => false === $deleted ? 'import_error' : 'import_deleted',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handles manual import execution for a specific import job.
 */
function tporapdi_handle_manual_import_run() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to run imports.', 'tporret-api-data-importer' ) );
	}

	check_admin_referer( 'tporapdi_manual_run', 'tporapdi_manual_run_nonce' );

	$import_id = isset( $_POST['import_id'] ) ? absint( wp_unslash( $_POST['import_id'] ) ) : 0;
	if ( $import_id <= 0 ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$active_state = tporapdi_get_active_run_state();
	if ( ! empty( $active_state['run_id'] ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action'          => 'edit',
					'id'              => $import_id,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$extract_result = tporapdi_extract_and_stage_data( $import_id );
	if ( is_wp_error( $extract_result ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action'          => 'edit',
					'id'              => $import_id,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	tporapdi_set_active_run_state(
		array(
			'run_id'              => wp_generate_uuid4(),
			'import_id'           => $import_id,
			'trigger_source'      => 'manual',
			'start_timestamp'     => time(),
			'start_time'          => gmdate( 'Y-m-d H:i:s', time() ),
			'rows_processed'      => 0,
			'rows_created'        => 0,
			'rows_updated'        => 0,
			'temp_rows_found'     => 0,
			'temp_rows_processed' => 0,
			'errors'              => array(),
			'slices'              => 0,
		)
	);

	// Process one slice immediately; remaining slices will self-schedule if needed.
	tporapdi_handle_scheduled_import_batch();

	$notice_code   = 'import_started';
	$template_sync = isset( $_POST['tporapdi_template_sync'] )
		? sanitize_text_field( (string) wp_unslash( $_POST['tporapdi_template_sync'] ) )
		: '';

	if ( '1' === $template_sync ) {
		$notice_code = 'template_sync_started';
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
				'action'          => 'edit',
				'id'              => $import_id,
				'tporapdi_notice' => $notice_code,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handles endpoint test and payload preview for a specific import job.
 */
function tporapdi_handle_test_import_endpoint() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to test endpoints.', 'tporret-api-data-importer' ) );
	}

	check_admin_referer( 'tporapdi_test_import_endpoint', 'tporapdi_test_import_endpoint_nonce' );

	$import_id = isset( $_POST['import_id'] ) ? absint( wp_unslash( $_POST['import_id'] ) ) : 0;
	if ( $import_id <= 0 ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'tporapdi_notice' => 'import_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$preview_mode = isset( $_POST['tporapdi_preview_mode'] ) ? sanitize_key( (string) wp_unslash( $_POST['tporapdi_preview_mode'] ) ) : 'structure';
	if ( 'mapping' !== $preview_mode ) {
		$preview_mode = 'structure';
	}

	$import_job = tporapdi_db_get_import_config( $import_id );

	$result_key     = 'tporapdi_endpoint_test_result_' . $import_id;
	$preview_result = array(
		'type'         => 'error',
		'message'      => __( 'Endpoint test failed.', 'tporret-api-data-importer' ),
		'http_code'    => 0,
		'used_cache'   => false,
		'json_path'    => '',
		'preview_mode' => $preview_mode,
	);

	if ( ! is_array( $import_job ) ) {
		$preview_result['message'] = __( 'Import job could not be found.', 'tporret-api-data-importer' );
		set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action' => 'edit',
					'id'     => $import_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$endpoint         = trim( (string) $import_job['endpoint_url'] );
	$auth_method      = trim( (string) ( $import_job['auth_method'] ?? 'none' ) );
	$token            = trim( (string) $import_job['auth_token'] );
	$auth_header_name = trim( (string) ( $import_job['auth_header_name'] ?? '' ) );
	$auth_username    = trim( (string) ( $import_job['auth_username'] ?? '' ) );
	$auth_password    = (string) ( $import_job['auth_password'] ?? '' );
	$json_path        = trim( (string) $import_job['array_path'] );

	if ( '' === $endpoint ) {
		$preview_result['message'] = __( 'No endpoint URL available to test.', 'tporret-api-data-importer' );
		set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action' => 'edit',
					'id'     => $import_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$response = tporapdi_fetch_api_payload( $endpoint, $auth_method, $token, $auth_header_name, $auth_username, $auth_password, true );
	if ( is_wp_error( $response ) ) {
		$preview_result['message'] = $response->get_error_message();
		set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action' => 'edit',
					'id'     => $import_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$decoded_json = json_decode( (string) $response['body'], true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		/* translators: %s is the JSON parser error message. */
		$preview_result['message'] = sprintf( __( 'Endpoint responded but JSON decode failed: %s', 'tporret-api-data-importer' ), json_last_error_msg() );
		set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action' => 'edit',
					'id'     => $import_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$selected_payload = '' === $json_path ? $decoded_json : tporapdi_resolve_json_array_path( $decoded_json, $json_path );
	if ( is_wp_error( $selected_payload ) ) {
		$preview_result['message'] = $selected_payload->get_error_message();
		set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
					'action' => 'edit',
					'id'     => $import_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	$sample_item = null;
	if ( is_array( $selected_payload ) ) {
		$sample_item = tporapdi_array_is_list( $selected_payload ) && ! empty( $selected_payload ) ? $selected_payload[0] : $selected_payload;
	}

	$sample_json = wp_json_encode( $sample_item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $sample_json ) {
		$sample_json = '';
	}

	$normalized_preview = array();
	if ( 'mapping' === $preview_mode && is_array( $selected_payload ) ) {
		$normalized_items = tporapdi_normalize_staged_items( $selected_payload );
		$preview_items    = array_slice( $normalized_items, 0, 3 );
		$record_number    = 0;
		foreach ( $preview_items as $preview_item ) {
			++$record_number;
			$mapped_content = tporapdi_render_mapping_template_for_item( $preview_item, (string) $import_job['mapping_template'] );
			$mapping_error  = '';
			if ( is_wp_error( $mapped_content ) ) {
				$mapping_error  = $mapped_content->get_error_message();
				$mapped_content = '';
			}
			$normalized_preview[] = array(
				'record_number'  => $record_number,
				'mapping_error'  => $mapping_error,
				'mapped_content' => substr( (string) $mapped_content, 0, 5000 ),
			);
		}
	}

	$preview_result = array(
		'type'               => 'success',
		'message'            => __( 'Endpoint test passed and payload preview generated.', 'tporret-api-data-importer' ),
		'http_code'          => isset( $response['status_code'] ) ? (int) $response['status_code'] : 0,
		'used_cache'         => ! empty( $response['used_cache'] ),
		'json_path'          => $json_path,
		'payload_type'       => gettype( $selected_payload ),
		'item_count'         => is_array( $selected_payload ) ? count( $selected_payload ) : 0,
		'sample_keys'        => is_array( $sample_item ) ? array_keys( $sample_item ) : array(),
		'sample_json'        => substr( (string) $sample_json, 0, 6000 ),
		'preview_mode'       => $preview_mode,
		'normalized_preview' => $normalized_preview,
	);

	set_transient( $result_key, $preview_result, 5 * MINUTE_IN_SECONDS );
	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => TPORAPDI_ADMIN_PAGE_MANAGE_SLUG,
				'action' => 'edit',
				'id'     => $import_id,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Handles scheduling an immediate cron run for one import.
 */
function tporapdi_handle_schedule_run_now_action() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to schedule imports.', 'tporret-api-data-importer' ) );
	}

	$import_id = isset( $_POST['import_id'] ) ? absint( wp_unslash( $_POST['import_id'] ) ) : 0;
	if ( $import_id <= 0 ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG,
					'tporapdi_notice' => 'schedule_error',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	check_admin_referer( 'tporapdi_schedule_run_now_' . $import_id, 'tporapdi_schedule_run_now_nonce' );

	// Execute immediately so updates/deletes happen even when WP-Cron is delayed.
	tporapdi_handle_import_batch_hook( $import_id, 'run_now' );

	$active_state = tporapdi_get_active_run_state();
	$scheduled    = empty( $active_state['run_id'] ) || ( isset( $active_state['import_id'] ) && (int) $active_state['import_id'] === $import_id );

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'            => TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG,
				'tporapdi_notice' => $scheduled ? 'schedule_now' : 'schedule_error',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Renders the React dashboard page.
 */
function tporapdi_render_dashboard_page() {
	if ( ! tporapdi_current_user_can_manage_imports() ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'tporret-api-data-importer' ) );
	}
	echo '<div id="' . esc_attr( TPORAPDI_ADMIN_DASHBOARD_ROOT_ID ) . '" class="wrap"></div>';
}

/**
 * Renders one dashboard status badge for the network summary table.
 *
 * @param string $status Status key.
 * @return string
 */
function tporapdi_get_network_dashboard_status_badge( string $status ): string {
	$status = sanitize_key( $status );
	$map    = array(
		'green'  => array(
			'label' => __( 'Healthy', 'tporret-api-data-importer' ),
			'bg'    => '#dcfce7',
			'fg'    => '#166534',
		),
		'yellow' => array(
			'label' => __( 'Warning', 'tporret-api-data-importer' ),
			'bg'    => '#fef3c7',
			'fg'    => '#92400e',
		),
		'red'    => array(
			'label' => __( 'Critical', 'tporret-api-data-importer' ),
			'bg'    => '#fee2e2',
			'fg'    => '#991b1b',
		),
		'gray'   => array(
			'label' => __( 'Unknown', 'tporret-api-data-importer' ),
			'bg'    => '#e5e7eb',
			'fg'    => '#374151',
		),
	);

	if ( ! isset( $map[ $status ] ) ) {
		$status = 'gray';
	}

	return sprintf(
		'<span class="eai-network-status-badge eai-network-status-badge--%1$s">%2$s</span>',
		esc_attr( $status ),
		esc_html( $map[ $status ]['label'] )
	);
}

/**
 * Renders the multisite Network Admin dashboard.
 *
 * @return void
 */
function tporapdi_render_network_dashboard_page() {
	if ( ! tporapdi_current_user_can_manage_network_imports() ) {
		wp_die( esc_html__( 'You are not allowed to access this page.', 'tporret-api-data-importer' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Refresh action validates a dedicated nonce.
	$refresh = isset( $_GET['tporapdi_refresh_network'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tporapdi_refresh_network'] ) );
	if ( $refresh ) {
		check_admin_referer( 'tporapdi_refresh_network_dashboard' );
	}

	$snapshots = tporapdi_db_get_network_snapshots();
	if ( $refresh || empty( $snapshots ) ) {
		$snapshots = tporapdi_refresh_network_dashboard_snapshots( $refresh );
	}

	$summary = array(
		'total'    => count( $snapshots ),
		'healthy'  => 0,
		'warning'  => 0,
		'critical' => 0,
	);

	foreach ( $snapshots as $snapshot ) {
		switch ( $snapshot['overall_status'] ?? '' ) {
			case 'red':
				++$summary['critical'];
				break;
			case 'yellow':
				++$summary['warning'];
				break;
			default:
				++$summary['healthy'];
				break;
		}
	}

	$refresh_url = wp_nonce_url(
		add_query_arg(
			array(
				'page'                     => TPORAPDI_ADMIN_PAGE_NETWORK_DASHBOARD_SLUG,
				'tporapdi_refresh_network' => '1',
			),
			network_admin_url( 'admin.php' )
		),
		'tporapdi_refresh_network_dashboard'
	);

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'tporret API Data Importer Network Dashboard', 'tporret-api-data-importer' ) . '</h1>';
	echo '<p>' . esc_html__( 'This view summarizes every subsite where tporret API Data Importer is activated. Site admins continue to manage and monitor imports from each subsite dashboard.', 'tporret-api-data-importer' ) . '</p>';
	echo '<p><a href="' . esc_url( $refresh_url ) . '" class="button button-secondary">' . esc_html__( 'Refresh Network Snapshot', 'tporret-api-data-importer' ) . '</a></p>';

	echo '<div class="eai-network-summary">';
	echo '<div class="eai-network-summary-card"><strong class="eai-network-summary-card-title">' . esc_html__( 'Sites Monitored', 'tporret-api-data-importer' ) . '</strong><div class="eai-network-summary-card-value">' . esc_html( number_format_i18n( $summary['total'] ) ) . '</div></div>';
	echo '<div class="eai-network-summary-card eai-network-summary-card--healthy"><strong class="eai-network-summary-card-title">' . esc_html__( 'Healthy Sites', 'tporret-api-data-importer' ) . '</strong><div class="eai-network-summary-card-value">' . esc_html( number_format_i18n( $summary['healthy'] ) ) . '</div></div>';
	echo '<div class="eai-network-summary-card eai-network-summary-card--warning"><strong class="eai-network-summary-card-title">' . esc_html__( 'Warnings', 'tporret-api-data-importer' ) . '</strong><div class="eai-network-summary-card-value">' . esc_html( number_format_i18n( $summary['warning'] ) ) . '</div></div>';
	echo '<div class="eai-network-summary-card eai-network-summary-card--critical"><strong class="eai-network-summary-card-title">' . esc_html__( 'Critical Sites', 'tporret-api-data-importer' ) . '</strong><div class="eai-network-summary-card-value">' . esc_html( number_format_i18n( $summary['critical'] ) ) . '</div></div>';
	echo '</div>';

	if ( empty( $snapshots ) ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No multisite snapshots are available yet. Activate the plugin on at least one subsite, or refresh after a site dashboard has loaded.', 'tporret-api-data-importer' ) . '</p></div>';
		echo '</div>';
		return;
	}

	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Site', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Overall', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Health', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Security', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Performance', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Import Jobs', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Updated', 'tporret-api-data-importer' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'tporret-api-data-importer' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $snapshots as $snapshot ) {
		$blog_id        = absint( $snapshot['blog_id'] ?? 0 );
		$site_name      = (string) ( $snapshot['site_name'] ?? '' );
		$site_url       = (string) ( $snapshot['site_url'] ?? '' );
		$dashboard_link = $blog_id > 0 ? get_admin_url( $blog_id, 'admin.php?page=' . TPORAPDI_ADMIN_PAGE_DASHBOARD_SLUG ) : '';
		$updated_at     = isset( $snapshot['updated_at'] ) ? mysql2date( get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'g:i a' ), (string) $snapshot['updated_at'], false ) : '';

		echo '<tr>';
		echo '<td><strong>' . esc_html( $site_name ) . '</strong><br /><a href="' . esc_url( $site_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $site_url ) . '</a></td>';
		echo '<td>' . wp_kses_post( tporapdi_get_network_dashboard_status_badge( (string) ( $snapshot['overall_status'] ?? 'gray' ) ) ) . '</td>';
		echo '<td>' . wp_kses_post( tporapdi_get_network_dashboard_status_badge( (string) ( $snapshot['health_status'] ?? 'gray' ) ) ) . '</td>';
		echo '<td>' . wp_kses_post( tporapdi_get_network_dashboard_status_badge( (string) ( $snapshot['security_status'] ?? 'gray' ) ) ) . '</td>';
		echo '<td>' . wp_kses_post( tporapdi_get_network_dashboard_status_badge( (string) ( $snapshot['performance_status'] ?? 'gray' ) ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( absint( $snapshot['import_count'] ?? 0 ) ) ) . '</td>';
		echo '<td>' . esc_html( $updated_at ) . '</td>';
		echo '<td>';
		if ( '' !== $dashboard_link ) {
			echo '<a class="button button-small" href="' . esc_url( $dashboard_link ) . '">' . esc_html__( 'Open Site Dashboard', 'tporret-api-data-importer' ) . '</a>';
		}
		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
	echo '</div>';
}

/**
 * Enqueues inline admin page styles for the EAPI manage-list, schedules, and network dashboard pages.
 *
 * Styles are anchored to handles registered with a false source so they are output
 * in the <head> via wp_head() rather than as raw inline <style> tags in page content.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function tporapdi_enqueue_admin_page_styles( string $hook_suffix ): void {
	$is_manage            = 'toplevel_page_' . TPORAPDI_ADMIN_PAGE_MANAGE_SLUG === $hook_suffix;
	$is_schedules         = TPORAPDI_ADMIN_PAGE_MANAGE_SLUG . '_page_' . TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG === $hook_suffix;
	$is_network_dashboard = 'toplevel_page_' . TPORAPDI_ADMIN_PAGE_NETWORK_DASHBOARD_SLUG === $hook_suffix;

	if ( ! $is_manage && ! $is_schedules && ! $is_network_dashboard ) {
		return;
	}

	// The edit view loads its own React styles; only target the list view here.
	if ( $is_manage ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check.
		$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
		if ( 'edit' === $action ) {
			return;
		}
	}

	// Shared tableau-style CSS used on both the manage-list and schedules pages.
	$shared_css = '
		:root {
			--eapi-border: #e2e8f0;
			--eapi-slate-50: #f8fafc;
			--eapi-slate-300: #cbd5e1;
			--eapi-slate-500: #64748b;
			--eapi-slate-900: #0f172a;
		}

		.eapi-admin-table {
			border: 1px solid var(--eapi-border);
			border-radius: 10px;
			overflow: visible;
		}

		.eapi-admin-table thead th {
			background: var(--eapi-slate-50);
			color: var(--eapi-slate-500);
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.06em;
			text-transform: uppercase;
			border-bottom: 1px solid var(--eapi-border);
			position: static;
		}

		.eapi-admin-table tbody tr:hover {
			background: var(--eapi-slate-50);
		}

		.eapi-admin-table td {
			border-bottom: 1px solid #f1f5f9;
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

		.eapi-health-chip {
			display: inline-block;
			padding: 3px 9px;
			border-radius: 999px;
			font-size: 11px;
			font-weight: 700;
			letter-spacing: 0.02em;
			border: 1px solid transparent;
		}

		.eapi-health-chip.is-good {
			background: #ecfdf5;
			color: #166534;
			border-color: #bbf7d0;
		}

		.eapi-health-chip.is-warn {
			background: #fffbeb;
			color: #92400e;
			border-color: #fde68a;
		}

		.eapi-health-chip.is-bad {
			background: #fef2f2;
			color: #991b1b;
			border-color: #fecaca;
		}

		.eapi-sparkline {
			display: inline-flex;
			align-items: flex-end;
			gap: 2px;
			height: 24px;
			padding: 1px 0;
		}

		.eapi-spark-pair {
			display: inline-flex;
			align-items: flex-end;
			gap: 1px;
			height: 24px;
		}

		.eapi-mini-bar {
			display: inline-block;
			width: 3px;
			border-radius: 2px;
		}

		.eapi-mini-bar.is-created {
			background: #2563eb;
		}

		.eapi-mini-bar.is-updated {
			background: #0d9488;
		}

		.eapi-mini-bar--h-2 { height: 2px; }
		.eapi-mini-bar--h-3 { height: 3px; }
		.eapi-mini-bar--h-4 { height: 4px; }
		.eapi-mini-bar--h-5 { height: 5px; }
		.eapi-mini-bar--h-6 { height: 6px; }
		.eapi-mini-bar--h-7 { height: 7px; }
		.eapi-mini-bar--h-8 { height: 8px; }
		.eapi-mini-bar--h-9 { height: 9px; }
		.eapi-mini-bar--h-10 { height: 10px; }
		.eapi-mini-bar--h-11 { height: 11px; }
		.eapi-mini-bar--h-12 { height: 12px; }
		.eapi-mini-bar--h-13 { height: 13px; }
		.eapi-mini-bar--h-14 { height: 14px; }
		.eapi-mini-bar--h-15 { height: 15px; }
		.eapi-mini-bar--h-16 { height: 16px; }
		.eapi-mini-bar--h-17 { height: 17px; }
		.eapi-mini-bar--h-18 { height: 18px; }
		.eapi-mini-bar--h-19 { height: 19px; }
		.eapi-mini-bar--h-20 { height: 20px; }
		.eapi-mini-bar--h-21 { height: 21px; }
		.eapi-mini-bar--h-22 { height: 22px; }

		.eapi-trend-empty {
			color: var(--eapi-slate-500);
			font-size: 12px;
		}
	';

	if ( $is_manage ) {
		$manage_css = "
			.eapi-manage-shell {
				max-width: 1320px;
			}
			.eapi-manage-topbar {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				margin-bottom: 16px;
			}
			.eapi-manage-topbar h1 {
				margin: 0;
			}
			.eapi-manage-kpis {
				display: grid;
				grid-template-columns: repeat( auto-fit, minmax( 220px, 1fr ) );
				gap: 12px;
				margin: 16px 0 18px;
			}
			.eapi-manage-kpi {
				background: #fff;
				border: 1px solid #e2e8f0;
				border-radius: 12px;
				padding: 14px;
				box-shadow: 0 1px 2px rgba( 15, 23, 42, 0.05 );
			}
			.eapi-manage-kpi-label {
				font-size: 11px;
				line-height: 1.4;
				font-weight: 600;
				letter-spacing: 0.06em;
				text-transform: uppercase;
				color: #64748b;
				margin-bottom: 6px;
			}
			.eapi-manage-kpi-value {
				font-size: 25px;
				line-height: 1.15;
				font-weight: 700;
				color: #0f172a;
			}
			.eapi-manage-running {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				padding: 6px 10px;
				border-radius: 999px;
				background: #dbeafe;
				color: #1e40af;
				font-size: 12px;
				font-weight: 600;
			}
			.eapi-manage-running::before {
				content: '';
				width: 8px;
				height: 8px;
				border-radius: 999px;
				background: #3b82f6;
			}
			.eapi-manage-card {
				background: #fff;
				border: 1px solid #e2e8f0;
				border-radius: 14px;
				box-shadow: 0 2px 4px rgba( 15, 23, 42, 0.04 );
				padding: 0;
				overflow: visible;
				margin-bottom: 18px;
			}
			.eapi-manage-card-header {
				padding: 14px 16px;
				border-bottom: 1px solid #e2e8f0;
				background: linear-gradient( 180deg, #ffffff 0%, #f8fafc 100% );
			}
			.eapi-manage-card-title {
				margin: 0;
				font-size: 14px;
				font-weight: 600;
				color: #334155;
				letter-spacing: 0.03em;
				text-transform: uppercase;
				text-align: center;
			}
			.eapi-manage-card-body {
				padding: 12px 16px 16px;
			}
			.eapi-manage-card .wp-list-table,
			.eapi-ownership-table {
				border: 1px solid #e2e8f0;
				border-radius: 10px;
				overflow: visible;
			}
			.eapi-manage-card .wp-list-table thead th,
			.eapi-ownership-table thead th {
				background: #f8fafc;
				color: #64748b;
				font-size: 11px;
				font-weight: 700;
				letter-spacing: 0.06em;
				text-transform: uppercase;
				border-bottom: 1px solid #e2e8f0;
				position: static;
			}
			.eapi-manage-card .wp-list-table tbody tr:hover,
			.eapi-ownership-table tbody tr:hover {
				background: #f8fafc;
			}
			.eapi-manage-card .wp-list-table td,
			.eapi-ownership-table td {
				border-bottom: 1px solid #f1f5f9;
			}
			.eapi-manage-card .wp-list-table th.column-actions,
			.eapi-manage-card .wp-list-table td.column-actions {
				text-align: center;
			}
			.eapi-manage-card .wp-list-table td.column-actions a.eapi-action-btn {
				display: inline-block;
				padding: 2px 7px;
				border-radius: 999px;
				text-decoration: none;
				border: 1px solid transparent;
				font-size: 11px;
				font-weight: 600;
				line-height: 1.2;
				margin: 0 2px;
			}
			.eapi-manage-card .wp-list-table td.column-actions a.eapi-action-btn.is-edit {
				background: #16a34a;
				border-color: #15803d;
				color: #ffffff;
				padding: 4px 10px;
				font-size: 16px;
				font-weight: 700;
			}
			.eapi-manage-card .wp-list-table td.column-actions a.eapi-action-btn.is-edit:hover {
				background: #15803d;
			}
			.eapi-manage-card .wp-list-table td.column-actions a.eapi-action-btn.is-delete {
				background: #dc2626;
				border-color: #b91c1c;
				color: #ffffff;
			}
			.eapi-manage-card .wp-list-table td.column-actions a.eapi-action-btn.is-delete:hover {
				background: #b91c1c;
			}
			.eapi-manage-card .wp-list-table td.column-endpoint,
			.eapi-manage-card .wp-list-table td.column-id {
				font-family: Menlo, Consolas, Monaco, \"Liberation Mono\", monospace;
				font-size: 12px;
			}
			.eapi-manage-card .wp-list-table th.column-id,
			.eapi-manage-card .wp-list-table td.column-id {
				width: 56px;
			}
			.eapi-manage-card .wp-list-table th.column-status,
			.eapi-manage-card .wp-list-table td.column-status {
				width: 112px;
				white-space: nowrap;
			}
			.eapi-manage-card .wp-list-table th.column-health,
			.eapi-manage-card .wp-list-table td.column-health {
				width: 108px;
				white-space: nowrap;
			}
			.eapi-manage-card .tablenav.top,
			.eapi-manage-card .tablenav.bottom {
				padding: 10px 0;
			}
		";

		wp_register_style( TPORAPDI_ADMIN_STYLE_MANAGE_HANDLE, false, array(), TPORAPDI_PLUGIN_VERSION );
		wp_enqueue_style( TPORAPDI_ADMIN_STYLE_MANAGE_HANDLE );
		wp_add_inline_style( TPORAPDI_ADMIN_STYLE_MANAGE_HANDLE, $shared_css );
		wp_add_inline_style( TPORAPDI_ADMIN_STYLE_MANAGE_HANDLE, $manage_css );
	}

	if ( $is_schedules ) {
		$schedules_css = '
			.eai-schedules-table .column-status,
			.eai-schedules-table .column-actions {
				white-space: nowrap;
			}
		';

		wp_register_style( TPORAPDI_ADMIN_STYLE_SCHEDULES_HANDLE, false, array(), TPORAPDI_PLUGIN_VERSION );
		wp_enqueue_style( TPORAPDI_ADMIN_STYLE_SCHEDULES_HANDLE );
		wp_add_inline_style( TPORAPDI_ADMIN_STYLE_SCHEDULES_HANDLE, $shared_css );
		wp_add_inline_style( TPORAPDI_ADMIN_STYLE_SCHEDULES_HANDLE, $schedules_css );
	}

	if ( $is_network_dashboard ) {
		$network_dashboard_css = '
			.eai-network-summary {
				display:flex;
				gap:16px;
				flex-wrap:wrap;
				margin:20px 0 24px;
			}
			.eai-network-summary-card {
				min-width:180px;
				background:#fff;
				border:1px solid #dcdcde;
				border-radius:8px;
				padding:16px;
			}
			.eai-network-summary-card-title {
				display:block;
				font-weight:700;
				margin-bottom:8px;
			}
			.eai-network-summary-card-value {
				font-size:28px;
				line-height:1.2;
				margin-top:8px;
			}
			.eai-network-summary-card--healthy .eai-network-summary-card-value {
				color:#166534;
			}
			.eai-network-summary-card--warning .eai-network-summary-card-value {
				color:#92400e;
			}
			.eai-network-summary-card--critical .eai-network-summary-card-value {
				color:#991b1b;
			}
			.eai-network-status-badge {
				display:inline-block;
				padding:4px 10px;
				border-radius:999px;
				font-weight:600;
				font-size:12px;
				line-height:1.4;
			}
			.eai-network-status-badge--green {
				background:#dcfce7;
				color:#166534;
			}
			.eai-network-status-badge--yellow {
				background:#fef3c7;
				color:#92400e;
			}
			.eai-network-status-badge--red {
				background:#fee2e2;
				color:#991b1b;
			}
			.eai-network-status-badge--gray {
				background:#e5e7eb;
				color:#374151;
			}
		';

		wp_register_style( TPORAPDI_ADMIN_STYLE_NETWORK_DASHBOARD_HANDLE, false, array(), TPORAPDI_PLUGIN_VERSION );
		wp_enqueue_style( TPORAPDI_ADMIN_STYLE_NETWORK_DASHBOARD_HANDLE );
		wp_add_inline_style( TPORAPDI_ADMIN_STYLE_NETWORK_DASHBOARD_HANDLE, $network_dashboard_css );
	}
}
add_action( 'admin_enqueue_scripts', 'tporapdi_enqueue_admin_page_styles' );

/**
 * Enqueue dashboard assets on the dashboard admin page only.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function tporapdi_enqueue_dashboard_assets( string $hook_suffix ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check.
	$current_page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
	$is_dashboard = TPORAPDI_ADMIN_PAGE_MANAGE_SLUG . '_page_' . TPORAPDI_ADMIN_PAGE_DASHBOARD_SLUG === $hook_suffix
		|| TPORAPDI_ADMIN_PAGE_DASHBOARD_SLUG === $current_page;

	if ( ! $is_dashboard ) {
		return;
	}

	$asset_file = plugin_dir_path( __DIR__ ) . 'build/dashboard.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		TPORAPDI_ADMIN_SCRIPT_DASHBOARD_HANDLE,
		plugins_url( 'build/dashboard.js', TPORAPDI_PLUGIN_FILE ),
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_enqueue_style(
		TPORAPDI_ADMIN_SCRIPT_DASHBOARD_HANDLE,
		plugins_url( 'build/style-dashboard.css', TPORAPDI_PLUGIN_FILE ),
		array(),
		$asset['version']
	);

	wp_localize_script(
		TPORAPDI_ADMIN_SCRIPT_DASHBOARD_HANDLE,
		'tporapdiDashboard',
		array(
			'restUrl' => esc_url_raw( rest_url( TPORAPDI_ADMIN_REST_NAMESPACE . '/dashboard' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'tporapdi_enqueue_dashboard_assets' );

/**
 * Enqueue import job workspace assets on the manage imports edit page only.
 *
 * @param string $hook_suffix Current admin page hook suffix.
 */
function tporapdi_enqueue_import_job_assets( string $hook_suffix ): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check.
	$current_page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
	$is_manage    = 'toplevel_page_' . TPORAPDI_ADMIN_PAGE_MANAGE_SLUG === $hook_suffix
		|| TPORAPDI_ADMIN_PAGE_MANAGE_SLUG === $current_page;

	if ( ! $is_manage ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check.
	$action = isset( $_GET['action'] ) ? sanitize_key( (string) wp_unslash( $_GET['action'] ) ) : '';
	if ( 'edit' !== $action ) {
		return;
	}

	$asset_file = plugin_dir_path( __DIR__ ) . 'build/import-job.asset.php';
	if ( ! file_exists( $asset_file ) ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		TPORAPDI_ADMIN_SCRIPT_IMPORT_JOB_HANDLE,
		plugins_url( 'build/import-job.js', TPORAPDI_PLUGIN_FILE ),
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_enqueue_style(
		TPORAPDI_ADMIN_SCRIPT_IMPORT_JOB_HANDLE,
		plugins_url( 'build/style-import-job.css', TPORAPDI_PLUGIN_FILE ),
		array( 'wp-components' ),
		$asset['version']
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only import ID for localized config.
	$import_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

	$post_types = array();
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
		if ( 'attachment' === $pt->name ) {
			continue;
		}
		$post_types[] = array(
			'label' => $pt->labels->singular_name,
			'value' => $pt->name,
		);
	}

	$authors      = array();
	$author_query = get_users(
		array(
			'capability' => array( 'edit_posts' ),
			'fields'     => array( 'ID', 'display_name' ),
			'orderby'    => 'display_name',
		)
	);
	foreach ( $author_query as $author ) {
		$authors[] = array(
			'label' => $author->display_name,
			'value' => (int) $author->ID,
		);
	}

	wp_localize_script(
		TPORAPDI_ADMIN_SCRIPT_IMPORT_JOB_HANDLE,
		'tporapdiImportJob',
		array(
			'importId'  => $import_id,
			'postTypes' => $post_types,
			'authors'   => $authors,
			'nonce'     => wp_create_nonce( 'wp_rest' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'tporapdi_enqueue_import_job_assets' );
