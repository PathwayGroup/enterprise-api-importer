<?php
/**
 * Core bootstrap concerns: defaults and activation setup.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'EAI_DB_SCHEMA_VERSION' ) ) {
	define( 'EAI_DB_SCHEMA_VERSION', '20260408-1' );
}

/**
 * Ensures template-management capability exists for administrators.
 *
 * @return void
 */
function eai_sync_template_management_capabilities() {
	$admin_role = get_role( 'administrator' );

	if ( $admin_role instanceof WP_Role ) {
		$admin_role->add_cap( 'eai_manage_templates' );
	}
}

/**
 * Runs on plugin activation.
 *
 * Creates and migrates ETL tables required by the import pipeline.
 */
function eai_activate_plugin() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$imports_table = $wpdb->prefix . 'eapi_imports';
	$logs_table = $wpdb->prefix . 'custom_import_logs';
	$temp_table = $wpdb->prefix . 'custom_import_temp';

	$sql_imports = "CREATE TABLE {$imports_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(191) NOT NULL,
		endpoint_url text NOT NULL,
		auth_method varchar(50) NOT NULL DEFAULT 'none',
		auth_token text NOT NULL,
		auth_header_name varchar(191) NOT NULL DEFAULT '',
		auth_username varchar(191) NOT NULL DEFAULT '',
		auth_password text NOT NULL DEFAULT '',
		array_path varchar(191) NOT NULL DEFAULT '',
		unique_id_path varchar(191) NOT NULL DEFAULT 'id',
		recurrence varchar(32) NOT NULL DEFAULT 'off',
		custom_interval_minutes int(10) unsigned NOT NULL DEFAULT 0,
		filter_rules longtext NULL,
		target_post_type varchar(100) NOT NULL DEFAULT 'post',
		featured_image_source_path varchar(191) NOT NULL DEFAULT 'image.url',
		title_template varchar(255) NOT NULL DEFAULT '',
		mapping_template longtext NOT NULL,
		post_author bigint(20) unsigned NOT NULL DEFAULT 0,
		lock_editing tinyint(1) unsigned NOT NULL DEFAULT 1,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY name (name)
	) {$charset_collate};";

	$sql_logs = "CREATE TABLE {$logs_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		import_id bigint(20) unsigned NOT NULL DEFAULT 0,
		import_run_id varchar(191) NOT NULL,
		status varchar(50) NOT NULL,
		rows_processed bigint(20) unsigned NOT NULL DEFAULT 0,
		rows_created bigint(20) unsigned NOT NULL DEFAULT 0,
		rows_updated bigint(20) unsigned NOT NULL DEFAULT 0,
		errors longtext NULL,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY import_id (import_id),
		KEY import_run_id (import_run_id),
		KEY status (status),
		KEY created_at (created_at)
	) {$charset_collate};";

	$sql_temp = "CREATE TABLE {$temp_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		import_id bigint(20) unsigned NOT NULL DEFAULT 0,
		raw_json longtext NOT NULL,
		is_processed tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY import_id (import_id),
		KEY is_processed (is_processed),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $sql_imports );
	dbDelta( $sql_logs );
	dbDelta( $sql_temp );
	eai_ensure_imports_auth_columns();
	eai_ensure_imports_featured_image_column();

	eai_sync_template_management_capabilities();

	if ( false === wp_next_scheduled( 'eapi_daily_garbage_collection' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eapi_daily_garbage_collection' );
	}

	update_option( 'eai_db_schema_version', EAI_DB_SCHEMA_VERSION );
}

/**
 * Ensures auth-related columns exist on the imports table.
 *
 * Handles migration from the legacy auth_header_type column to the new
 * auth_method column, and adds auth_username / auth_password columns.
 *
 * @return void
 */
function eai_ensure_imports_auth_columns() {
	global $wpdb;

	$table = $wpdb->prefix . 'eapi_imports';

	// Migrate legacy auth_header_type → auth_method.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$legacy_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_header_type' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$method_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_method' ) );

	if ( null !== $legacy_col && null === $method_col ) {
		// Add the new column first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_method varchar(50) NOT NULL DEFAULT 'none' AFTER endpoint_url", $table ) );

		// Map legacy values → new values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET auth_method = 'bearer' WHERE auth_header_type = 'bearer'", $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET auth_method = 'api_key_custom', auth_header_name = 'Authorization-Key' WHERE auth_header_type = 'api-key' AND ( auth_header_name = '' OR auth_header_name IS NULL )", $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET auth_method = 'api_key_custom' WHERE auth_header_type = 'api-key-header'", $table ) );

		// Drop legacy column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN auth_header_type', $table ) );
	} elseif ( null === $method_col ) {
		// Fresh install without legacy column — just add auth_method.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_method varchar(50) NOT NULL DEFAULT 'none' AFTER endpoint_url", $table ) );
	}

	// Ensure auth_header_name exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$header_name_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_header_name' ) );
	if ( null === $header_name_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_header_name varchar(191) NOT NULL DEFAULT '' AFTER auth_token", $table ) );
	}

	// Ensure auth_username exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$username_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_username' ) );
	if ( null === $username_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_username varchar(191) NOT NULL DEFAULT '' AFTER auth_header_name", $table ) );
	}

	// Ensure auth_password exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$password_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_password' ) );
	if ( null === $password_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_password text NOT NULL DEFAULT '' AFTER auth_username", $table ) );
	}
}

/**
 * Ensures featured image source path column exists on imports table.
 *
 * @return void
 */
function eai_ensure_imports_featured_image_column() {
	global $wpdb;

	$table = $wpdb->prefix . 'eapi_imports';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$featured_image_path_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'featured_image_source_path' ) );

	if ( null === $featured_image_path_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN featured_image_source_path varchar(191) NOT NULL DEFAULT 'image.url' AFTER target_post_type", $table ) );
	}
}

/**
 * Runs on plugin deactivation.
 *
 * Clears plugin-owned scheduled cron events.
 *
 * @return void
 */
function eai_deactivate_plugin() {
	wp_clear_scheduled_hook( 'eapi_daily_garbage_collection' );
}

/**
 * Ensures schema updates run for existing active installs.
 *
 * @return void
 */
function eai_maybe_upgrade_schema() {
	eai_sync_template_management_capabilities();
	eai_ensure_imports_auth_columns();
	eai_ensure_imports_featured_image_column();

	$installed_version = (string) get_option( 'eai_db_schema_version', '' );

	if ( EAI_DB_SCHEMA_VERSION === $installed_version ) {
		return;
	}

	eai_activate_plugin();
}

/**
 * Returns default plugin settings.
 *
 * @return array<string, string>
 */
function eai_get_default_settings() {
	return array(
		'cron_initial_delay_seconds'   => '5',
		'cron_batch_delay_seconds'     => '15',
		'allow_internal_endpoints'     => '0',
		'allowed_endpoint_hosts'       => '',
		'allowed_endpoint_cidrs'       => '',
	);
}
