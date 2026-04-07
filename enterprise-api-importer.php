<?php
/**
 * Plugin Name: Enterprise API Importer
 * Plugin URI:  https://github.com/tporret/enterprise-api-importer
 * Description: Highly secure enterprise ETL importer for WordPress.
 * Version:     1.2.0
 * Author:      tporret
 * License:     GPL-2.0-or-later
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Text Domain: enterprise-api-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$eai_composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $eai_composer_autoload ) ) {
	require_once $eai_composer_autoload;
}

// Load plugin modules in dependency order.
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/class-eapi-imports-list-table.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/reporting/reporting.php';

register_activation_hook( __FILE__, 'eai_activate_plugin' );
register_deactivation_hook( __FILE__, 'eai_deactivate_plugin' );
add_action( 'plugins_loaded', 'eai_maybe_upgrade_schema' );
