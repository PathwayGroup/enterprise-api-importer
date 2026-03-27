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
	define( 'EAI_DB_SCHEMA_VERSION', '20260327-3' );
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
		auth_token text NOT NULL,
		array_path varchar(191) NOT NULL DEFAULT '',
		unique_id_path varchar(191) NOT NULL DEFAULT 'id',
		recurrence varchar(32) NOT NULL DEFAULT 'off',
		custom_interval_minutes int(10) unsigned NOT NULL DEFAULT 0,
		filter_rules longtext NULL,
		mapping_template longtext NOT NULL,
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

	update_option( 'eai_db_schema_version', EAI_DB_SCHEMA_VERSION );
}

/**
 * Ensures schema updates run for existing active installs.
 *
 * @return void
 */
function eai_maybe_upgrade_schema() {
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
	);
}
