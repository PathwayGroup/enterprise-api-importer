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
 * Initialise the reporting engine and register all metric modules.
 */
function tporapdi_init_reporting() {
	$dir = __DIR__;

	// Core classes.
	require_once $dir . '/class-tporapdi-reporter-base.php';
	require_once $dir . '/class-tporapdi-reporting-aggregator.php';

	// Reporter modules.
	require_once $dir . '/reporters/class-tporapdi-reporter-cron-heartbeat.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-queue-depth.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-daily-success-rate.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-ssrf-hardening.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-audit-integrity.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-protocol-enforcement.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-api-latency.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-active-connections.php';
	require_once $dir . '/reporters/class-tporapdi-reporter-throughput.php';

	$aggregator = TPORAPDI_Reporting_Aggregator::get_instance();

	// Environment Health.
	$aggregator->register_reporter( new TPORAPDI_Reporter_Cron_Heartbeat() );
	$aggregator->register_reporter( new TPORAPDI_Reporter_Queue_Depth() );
	$aggregator->register_reporter( new TPORAPDI_Reporter_Daily_Success_Rate() );

	// Security & Compliance.
	$aggregator->register_reporter( new TPORAPDI_Reporter_SSRF_Hardening() );
	$aggregator->register_reporter( new TPORAPDI_Reporter_Audit_Integrity() );
	$aggregator->register_reporter( new TPORAPDI_Reporter_Protocol_Enforcement() );

	// Connectivity & Performance.
	$aggregator->register_reporter( new TPORAPDI_Reporter_API_Latency() );
	$aggregator->register_reporter( new TPORAPDI_Reporter_Active_Connections() );
	$aggregator->register_reporter( new TPORAPDI_Reporter_Throughput() );

	// REST endpoint for dashboard data.
	require_once $dir . '/rest-dashboard.php';
}
add_action( 'init', 'tporapdi_init_reporting' );
