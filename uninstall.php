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
$network_table = $wpdb->base_prefix . 'eapi_network_dashboard_sites';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $imports_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $logs_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $temp_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $network_table ) );

delete_option( 'eai_db_schema_version' );
delete_option( 'eai_active_import_run' );
delete_option( 'eai_settings' );
delete_site_option( 'eai_network_db_schema_version' );

wp_clear_scheduled_hook( 'eapi_daily_garbage_collection' );
wp_clear_scheduled_hook( 'eai_process_import_queue' );
wp_clear_scheduled_hook( 'eai_recurring_import_trigger' );
wp_clear_scheduled_hook( 'eai_import_batch_hook' );
