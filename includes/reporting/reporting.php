<?php
/**
 * Bootstraps the reporting subsystem — loads classes and registers all reporters.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialise the reporting engine.
 *
 * Reporter discovery is automatic: every file matching
 * reporters/class-tporapdi-reporter-*.php is required here.
 * Each reporter file self-registers by calling
 * TPORAPDI_Reporting_Aggregator::register( new Self() ) at its own end,
 * so adding a new reporter only requires dropping a file into reporters/ —
 * no edits to this file or any other core file are needed.
 */
function tporapdi_init_reporting() {
	$dir = __DIR__;

	// Core classes must load before any reporter file self-registers.
	require_once $dir . '/class-tporapdi-reporter-base.php';
	require_once $dir . '/class-tporapdi-reporting-aggregator.php';

	// Auto-discover all reporter modules.  Each file self-registers via
	// TPORAPDI_Reporting_Aggregator::register() at load time.
	$reporter_files = glob( $dir . '/reporters/class-tporapdi-reporter-*.php' );

	foreach ( $reporter_files ?: array() as $reporter_file ) {
		require_once $reporter_file;
	}

	// REST endpoint for dashboard data.
	require_once $dir . '/rest-dashboard.php';
}
add_action( 'init', 'tporapdi_init_reporting' );
