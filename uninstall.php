<?php
/**
 * Uninstall handler for Enterprise API Importer.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

if ( ! $wpdb instanceof wpdb ) {
	exit;
}

$imports_table = $wpdb->prefix . 'eapi_imports';
$logs_table    = $wpdb->prefix . 'custom_import_logs';
$temp_table    = $wpdb->prefix . 'custom_import_temp';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS `{$imports_table}`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS `{$logs_table}`" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS `{$temp_table}`" );

delete_option( 'eai_db_schema_version' );
delete_option( 'eai_active_import_run' );
delete_option( 'eai_settings' );

wp_clear_scheduled_hook( 'eapi_daily_garbage_collection' );
wp_clear_scheduled_hook( 'eai_process_import_queue' );
wp_clear_scheduled_hook( 'eai_recurring_import_trigger' );
wp_clear_scheduled_hook( 'ncsu_api_importer_batch_hook' );
