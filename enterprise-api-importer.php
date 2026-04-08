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

/**
 * Build a list of required dependency files that must exist in packaged installs.
 *
 * @return array<string>
 */
function eai_get_required_dependency_files() {
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
function eai_get_missing_dependency_files() {
	$missing = array();

	foreach ( eai_get_required_dependency_files() as $file ) {
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
function eai_render_dependency_notice( $message ) {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html( $message );
	echo '</p></div>';
}

$eai_dependency_error = '';
$eai_missing_files   = eai_get_missing_dependency_files();

if ( ! empty( $eai_missing_files ) ) {
	$eai_dependency_error = sprintf(
		/* translators: %s: missing plugin dependency files. */
		__( 'Enterprise API Importer is missing required dependency files: %s. Rebuild/redeploy the plugin package including the full vendor directory.', 'enterprise-api-importer' ),
		implode( ', ', $eai_missing_files )
	);
} else {
	try {
		require_once __DIR__ . '/vendor/autoload.php';
	} catch ( Throwable $e ) {
		$eai_dependency_error = sprintf(
			/* translators: %s: low-level dependency load error. */
			__( 'Enterprise API Importer failed to load dependencies: %s. Rebuild/redeploy the plugin package including composer vendor files.', 'enterprise-api-importer' ),
			$e->getMessage()
		);
	}
}

if ( '' !== $eai_dependency_error ) {
	add_action(
		'admin_notices',
		static function () use ( $eai_dependency_error ) {
			eai_render_dependency_notice( $eai_dependency_error );
		}
	);

	return;
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
