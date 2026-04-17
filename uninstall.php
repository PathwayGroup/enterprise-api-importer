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

$imports_table = $wpdb->prefix . 'tporapdi_imports';
$logs_table    = $wpdb->prefix . 'custom_import_logs';
$temp_table    = $wpdb->prefix . 'custom_import_temp';
$network_table = $wpdb->base_prefix . 'tporapdi_network_dashboard_sites';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $imports_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $logs_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $temp_table ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $network_table ) );

delete_option( 'tporapdi_db_schema_version' );
delete_option( 'tporapdi_active_import_run' );
delete_option( 'tporapdi_settings' );
delete_site_option( 'tporapdi_network_db_schema_version' );

wp_clear_scheduled_hook( 'tporapdi_daily_garbage_collection' );
wp_clear_scheduled_hook( 'tporapdi_process_import_queue' );
wp_clear_scheduled_hook( 'tporapdi_recurring_import_trigger' );
wp_clear_scheduled_hook( 'tporapdi_import_batch_hook' );
