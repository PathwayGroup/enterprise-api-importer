<?php
/**
 * Plugin Name: tporret API Data Importer
 * Plugin URI:  https://github.com/tporret/enterprise-api-importer
 * Description: Highly secure enterprise ETL importer for WordPress.
 * Version:     1.2.5
 * Author:      tporret
 * License:     GPL-2.0-or-later
 * Donate link: https://porretto.com/donate
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Tested up to: 6.9
 * Text Domain: tporret-api-data-importer
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TPORAPDI_PLUGIN_BASENAME' ) ) {
	define( 'TPORAPDI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'TPORAPDI_PLUGIN_FILE' ) ) {
	define( 'TPORAPDI_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'TPORAPDI_PLUGIN_VERSION' ) ) {
	define( 'TPORAPDI_PLUGIN_VERSION', '1.2.5' );
}

if ( ! defined( 'TPORAPDI_ADMIN_REST_NAMESPACE' ) ) {
	define( 'TPORAPDI_ADMIN_REST_NAMESPACE', 'tporret-api-data-importer/v1' );
}

if ( ! defined( 'TPORAPDI_ADMIN_PAGE_MANAGE_SLUG' ) ) {
	define( 'TPORAPDI_ADMIN_PAGE_MANAGE_SLUG', 'tporret-api-data-importer-manage' );
}

if ( ! defined( 'TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG' ) ) {
	define( 'TPORAPDI_ADMIN_PAGE_SCHEDULES_SLUG', 'tporret-api-data-importer-schedules' );
}

if ( ! defined( 'TPORAPDI_ADMIN_PAGE_SETTINGS_SLUG' ) ) {
	define( 'TPORAPDI_ADMIN_PAGE_SETTINGS_SLUG', 'tporret-api-data-importer-settings' );
}

if ( ! defined( 'TPORAPDI_ADMIN_PAGE_DASHBOARD_SLUG' ) ) {
	define( 'TPORAPDI_ADMIN_PAGE_DASHBOARD_SLUG', 'tporret-api-data-importer-dashboard' );
}

if ( ! defined( 'TPORAPDI_ADMIN_PAGE_NETWORK_DASHBOARD_SLUG' ) ) {
	define( 'TPORAPDI_ADMIN_PAGE_NETWORK_DASHBOARD_SLUG', 'tporret-api-data-importer-network-dashboard' );
}

if ( ! defined( 'TPORAPDI_ADMIN_STYLE_MANAGE_HANDLE' ) ) {
	define( 'TPORAPDI_ADMIN_STYLE_MANAGE_HANDLE', 'tporret-api-data-importer-manage-list' );
}

if ( ! defined( 'TPORAPDI_ADMIN_STYLE_SCHEDULES_HANDLE' ) ) {
	define( 'TPORAPDI_ADMIN_STYLE_SCHEDULES_HANDLE', 'tporret-api-data-importer-schedules' );
}

if ( ! defined( 'TPORAPDI_ADMIN_STYLE_NETWORK_DASHBOARD_HANDLE' ) ) {
	define( 'TPORAPDI_ADMIN_STYLE_NETWORK_DASHBOARD_HANDLE', 'tporret-api-data-importer-network-dashboard' );
}

if ( ! defined( 'TPORAPDI_ADMIN_SCRIPT_DASHBOARD_HANDLE' ) ) {
	define( 'TPORAPDI_ADMIN_SCRIPT_DASHBOARD_HANDLE', 'tporret-api-data-importer-dashboard' );
}

if ( ! defined( 'TPORAPDI_ADMIN_SCRIPT_IMPORT_JOB_HANDLE' ) ) {
	define( 'TPORAPDI_ADMIN_SCRIPT_IMPORT_JOB_HANDLE', 'tporret-api-data-importer-import-job' );
}

if ( ! defined( 'TPORAPDI_ADMIN_DASHBOARD_ROOT_ID' ) ) {
	define( 'TPORAPDI_ADMIN_DASHBOARD_ROOT_ID', 'tporret-api-data-importer-dashboard-root' );
}

if ( ! defined( 'TPORAPDI_ADMIN_IMPORT_JOB_ROOT_ID' ) ) {
	define( 'TPORAPDI_ADMIN_IMPORT_JOB_ROOT_ID', 'tporret-api-data-importer-import-job-root' );
}

/**
 * Determines whether the plugin is currently network-active.
 *
 * @return bool
 */
function tporapdi_is_network_active() {
	if ( ! is_multisite() ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	return is_plugin_active_for_network( TPORAPDI_PLUGIN_BASENAME );
}

/**
 * Reverts unsupported multisite network activation when encountered at runtime.
 *
 * This catches activation paths that bypass or ignore the activation-hook guard,
 * including WP-CLI based network activation.
 *
 * @return bool True when plugin loading should stop for this request.
 */
function tporapdi_enforce_supported_multisite_activation_mode() {
	if ( ! tporapdi_is_network_active() ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	deactivate_plugins( TPORAPDI_PLUGIN_BASENAME, true, true );
	update_site_option( 'tporapdi_network_activation_reverted', time() );

	if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
		WP_CLI::warning( 'tporret API Data Importer cannot remain network-activated. The plugin has been automatically reverted to the supported per-site activation model.' );
	}

	return true;
}

/**
 * Build a list of required dependency files that must exist in packaged installs.
 *
 * @return array<string>
 */
function tporapdi_get_required_dependency_files() {
	return array(
		__DIR__ . '/vendor/autoload.php',
		__DIR__ . '/vendor/twig/twig/src/Resources/core.php',
		__DIR__ . '/vendor/twig/twig/src/Resources/debug.php',
		__DIR__ . '/vendor/twig/twig/src/Resources/escaper.php',
		__DIR__ . '/vendor/twig/twig/src/Resources/string_loader.php',
	);
}

/**
 * Returns missing dependency files as plugin-relative paths.
 *
 * @return array<string>
 */
function tporapdi_get_missing_dependency_files() {
	$missing = array();

	foreach ( tporapdi_get_required_dependency_files() as $file ) {
		if ( ! file_exists( $file ) ) {
			$missing[] = ltrim( str_replace( __DIR__, '', $file ), '/' );
		}
	}

	return $missing;
}

/**
 * Render a dependency error notice for administrators.
 *
 * @param string $message Error message.
 * @return void
 */
function tporapdi_render_dependency_notice( $message ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html( $message );
	echo '</p></div>';
}

$tporapdi_dependency_error = '';
$tporapdi_missing_files    = tporapdi_get_missing_dependency_files();

if ( ! empty( $tporapdi_missing_files ) ) {
	$tporapdi_dependency_error = sprintf(
		/* translators: %s: missing plugin dependency files. */
		__( 'tporret API Data Importer is missing required dependency files: %s. Rebuild/redeploy the plugin package including the full vendor directory.', 'tporret-api-data-importer' ),
		implode( ', ', $tporapdi_missing_files )
	);
} else {
	try {
		require_once __DIR__ . '/vendor/autoload.php';
	} catch ( Throwable $e ) {
		$tporapdi_dependency_error = sprintf(
			/* translators: %s: low-level dependency load error. */
			__( 'tporret API Data Importer failed to load dependencies: %s. Rebuild/redeploy the plugin package including composer vendor files.', 'tporret-api-data-importer' ),
			$e->getMessage()
		);
	}
}

if ( '' !== $tporapdi_dependency_error ) {
	add_action(
		'admin_notices',
		static function () use ( $tporapdi_dependency_error ) {
			tporapdi_render_dependency_notice( $tporapdi_dependency_error );
		}
	);

	return;
}

if ( tporapdi_enforce_supported_multisite_activation_mode() ) {
	return;
}

// Load plugin modules in dependency order.
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/class-eai-imports-list-table.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/class-eai-import-processor.php';
require_once __DIR__ . '/includes/import.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';
require_once __DIR__ . '/includes/reporting/reporting.php';

register_activation_hook( __FILE__, 'tporapdi_activate_plugin' );
register_deactivation_hook( __FILE__, 'tporapdi_deactivate_plugin' );
add_action( 'plugins_loaded', 'tporapdi_maybe_upgrade_schema' );
