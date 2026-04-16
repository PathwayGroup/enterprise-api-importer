<?php
/**
 * REST endpoint for the reporting dashboard.
 *
 * @package Enterprise_API_Importer
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the dashboard REST route.
 */
function eai_register_dashboard_rest_route() {
	register_rest_route(
		EAI_ADMIN_REST_NAMESPACE,
		'/dashboard',
		array(
			'methods'             => 'GET',
			'callback'            => 'eai_rest_dashboard_callback',
			'permission_callback' => static function () {
				return eai_current_user_can_manage_imports();
			},
			'args'                => array(
				'refresh' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		EAI_ADMIN_REST_NAMESPACE,
		'/dashboard/history',
		array(
			'methods'             => 'GET',
			'callback'            => 'eai_rest_dashboard_history_callback',
			'permission_callback' => static function () {
				return eai_current_user_can_manage_imports();
			},
		)
	);
}
add_action( 'rest_api_init', 'eai_register_dashboard_rest_route' );

/**
 * Serve aggregated dashboard data.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function eai_rest_dashboard_callback( WP_REST_Request $request ): WP_REST_Response {
	if ( $request->get_param( 'refresh' ) ) {
		eai_flush_reporting_transients();
	}

	$aggregator = EAPI_Reporting_Aggregator::get_instance();
	$data       = $aggregator->get_dashboard_data();
	eai_store_current_site_network_snapshot( $data );

	return new WP_REST_Response( $data, 200 );
}

/**
 * Returns the worst dashboard status from a metrics group.
 *
 * @param array<int, string> $statuses Status values.
 * @return string
 */
function eai_reduce_dashboard_statuses( array $statuses ): string {
	$rank_map     = array(
		'green'  => 1,
		'yellow' => 2,
		'red'    => 3,
	);
	$best_match   = 'green';
	$highest_rank = 0;

	foreach ( $statuses as $status ) {
		$status = sanitize_key( (string) $status );
		if ( ! isset( $rank_map[ $status ] ) ) {
			continue;
		}

		if ( $rank_map[ $status ] > $highest_rank ) {
			$highest_rank = $rank_map[ $status ];
			$best_match   = $status;
		}
	}

	return $best_match;
}

/**
 * Returns the worst status for one dashboard category.
 *
 * @param array<string, array<string, array<string, mixed>>> $dashboard_data Dashboard payload.
 * @param string                                             $category       Category key.
 * @return string
 */
function eai_get_dashboard_category_status( array $dashboard_data, string $category ): string {
	$statuses = array();
	$items    = $dashboard_data[ $category ] ?? array();

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || empty( $item['metrics']['status'] ) ) {
			continue;
		}

		$statuses[] = (string) $item['metrics']['status'];
	}

	return eai_reduce_dashboard_statuses( $statuses );
}

/**
 * Returns the worst status across the full dashboard payload.
 *
 * @param array<string, array<string, array<string, mixed>>> $dashboard_data Dashboard payload.
 * @return string
 */
function eai_get_dashboard_overall_status( array $dashboard_data ): string {
	return eai_reduce_dashboard_statuses(
		array(
			eai_get_dashboard_category_status( $dashboard_data, 'Health' ),
			eai_get_dashboard_category_status( $dashboard_data, 'Security' ),
			eai_get_dashboard_category_status( $dashboard_data, 'Performance' ),
		)
	);
}

/**
 * Determines whether the plugin is active for a given blog.
 *
 * @param int $blog_id Blog ID.
 * @return bool
 */
function eai_is_plugin_active_on_blog( int $blog_id ): bool {
	if ( ! is_multisite() ) {
		return true;
	}

	$blog_id         = absint( $blog_id );
	$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
	$active_plugins  = (array) get_blog_option( $blog_id, 'active_plugins', array() );

	if ( isset( $network_plugins[ EAI_PLUGIN_BASENAME ] ) ) {
		return true;
	}

	return in_array( EAI_PLUGIN_BASENAME, $active_plugins, true );
}

/**
 * Stores the current site's dashboard snapshot for the Network Admin dashboard.
 *
 * @param array<string, array<string, array<string, mixed>>>|null $dashboard_data Optional dashboard payload.
 * @return void
 */
function eai_store_current_site_network_snapshot( ?array $dashboard_data = null ): void {
	if ( ! is_multisite() ) {
		return;
	}

	$blog_id = get_current_blog_id();
	if ( ! eai_is_plugin_active_on_blog( $blog_id ) ) {
		return;
	}

	if ( null === $dashboard_data ) {
		$aggregator     = EAPI_Reporting_Aggregator::get_instance();
		$dashboard_data = $aggregator->get_dashboard_data();
	}

	$site_name = (string) get_option( 'blogname', '' );
	$site_url  = home_url( '/' );

	eai_db_save_network_snapshot(
		array(
			'blog_id'            => $blog_id,
			'site_url'           => $site_url,
			'site_name'          => '' !== $site_name ? $site_name : wp_parse_url( $site_url, PHP_URL_HOST ),
			'overall_status'     => eai_get_dashboard_overall_status( $dashboard_data ),
			'health_status'      => eai_get_dashboard_category_status( $dashboard_data, 'Health' ),
			'security_status'    => eai_get_dashboard_category_status( $dashboard_data, 'Security' ),
			'performance_status' => eai_get_dashboard_category_status( $dashboard_data, 'Performance' ),
			'import_count'       => count( eai_db_get_import_configs() ),
			'dashboard_data'     => $dashboard_data,
		)
	);
}

/**
 * Refreshes multisite dashboard snapshots for every active site.
 *
 * @param bool $force_refresh Whether to clear per-site reporter caches first.
 * @return array<int, array<string, mixed>>
 */
function eai_refresh_network_dashboard_snapshots( bool $force_refresh = false ): array {
	if ( ! is_multisite() ) {
		return array();
	}

	$active_site_ids = array();
	$site_ids        = get_sites(
		array(
			'fields'   => 'ids',
			'number'   => 0,
			'deleted'  => 0,
			'archived' => 0,
			'spam'     => 0,
		)
	);

	if ( ! is_array( $site_ids ) ) {
		return eai_db_get_network_snapshots();
	}

	foreach ( $site_ids as $site_id ) {
		$site_id = absint( $site_id );
		if ( $site_id <= 0 || ! eai_is_plugin_active_on_blog( $site_id ) ) {
			continue;
		}

		$active_site_ids[] = $site_id;

		switch_to_blog( $site_id );

		if ( $force_refresh ) {
			eai_flush_reporting_transients();
		}

		eai_maybe_upgrade_schema();
		eai_store_current_site_network_snapshot();

		restore_current_blog();
	}

	$active_site_ids = array_map( 'absint', $active_site_ids );
	foreach ( eai_db_get_network_snapshots() as $snapshot ) {
		$blog_id = absint( $snapshot['blog_id'] ?? 0 );
		if ( $blog_id > 0 && ! in_array( $blog_id, $active_site_ids, true ) ) {
			eai_db_delete_network_snapshot( $blog_id );
		}
	}

	return eai_db_get_network_snapshots();
}

/**
 * Serve recent log history for charts (sparklines, latency timeline, audit feed).
 *
 * @return WP_REST_Response
 */
function eai_rest_dashboard_history_callback(): WP_REST_Response {
	global $wpdb;

	$logs_table = eai_db_logs_table();

	$transient_key = 'eapi_dashboard_history';
	$cached        = get_transient( $transient_key );
	if ( false !== $cached ) {
		return new WP_REST_Response( $cached, 200 );
	}

	// Last 7 daily success rates for sparkline.
	$daily_rates = array();
	for ( $i = 6; $i >= 0; $i-- ) {
		$day_start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$i} days" ) );
		$day_end   = gmdate( 'Y-m-d 23:59:59', strtotime( "-{$i} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$day_rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS cnt FROM %i WHERE created_at BETWEEN %s AND %s AND status NOT IN (%s) GROUP BY status',
				$logs_table,
				$day_start,
				$day_end,
				'template_audit'
			)
		);

		$success = 0;
		$total   = 0;
		foreach ( $day_rows as $row ) {
			$cnt    = (int) $row->cnt;
			$total += $cnt;
			if ( in_array( $row->status, array( 'completed', 'success' ), true ) ) {
				$success += $cnt;
			}
		}

		$daily_rates[] = array(
			'date' => gmdate( 'M j', strtotime( "-{$i} days" ) ),
			'rate' => $total > 0 ? round( ( $success / $total ) * 100, 1 ) : null,
		);
	}

	// Last 20 latency data points.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$latency_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT errors, created_at FROM %i WHERE status NOT IN (%s, %s) AND errors IS NOT NULL ORDER BY created_at DESC LIMIT 20',
			$logs_table,
			'template_audit',
			'no_data'
		)
	);

	$latency_points = array();
	foreach ( array_reverse( $latency_rows ) as $row ) {
		$details = json_decode( $row->errors, true );
		if ( ! is_array( $details ) || empty( $details['start_time'] ) || empty( $details['end_time'] ) ) {
			continue;
		}
		$start = strtotime( $details['start_time'] );
		$end   = strtotime( $details['end_time'] );
		if ( false === $start || false === $end || $end < $start ) {
			continue;
		}
		$latency_points[] = array(
			'time'    => gmdate( 'M j H:i', strtotime( $row->created_at ) ),
			'seconds' => $end - $start,
		);
	}

	// Last 5 audit entries for the footer marquee.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$audit_rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT errors, created_at FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT 5',
			$logs_table,
			'template_audit'
		)
	);

	$audit_entries = array();
	foreach ( $audit_rows as $row ) {
		$details         = json_decode( $row->errors, true );
		$audit_entries[] = array(
			'time'   => gmdate( 'M j, g:ia', strtotime( $row->created_at ) ),
			'type'   => isset( $details['audit_type'] ) ? $details['audit_type'] : 'unknown',
			'user'   => isset( $details['actor_user_login'] ) ? $details['actor_user_login'] : 'system',
			'import' => isset( $details['template_import_id'] ) ? (int) $details['template_import_id'] : 0,
		);
	}

	// Last 7 throughput data points (daily).
	$throughput_points = array();
	for ( $i = 6; $i >= 0; $i-- ) {
		$day_start = gmdate( 'Y-m-d 00:00:00', strtotime( "-{$i} days" ) );
		$day_end   = gmdate( 'Y-m-d 23:59:59', strtotime( "-{$i} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$sum = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE( SUM( rows_processed ), 0 ) FROM %i WHERE created_at BETWEEN %s AND %s AND status NOT IN (%s)',
				$logs_table,
				$day_start,
				$day_end,
				'template_audit'
			)
		);

		$throughput_points[] = array(
			'date' => gmdate( 'M j', strtotime( "-{$i} days" ) ),
			'rows' => $sum,
		);
	}

	$history = array(
		'daily_rates'       => $daily_rates,
		'latency_points'    => $latency_points,
		'audit_entries'     => $audit_entries,
		'throughput_points' => $throughput_points,
	);

	set_transient( $transient_key, $history, 600 );

	return new WP_REST_Response( $history, 200 );
}

/**
 * Flush all reporting transients for a forced refresh.
 */
function eai_flush_reporting_transients(): void {
	$reporter_ids = array(
		'cron_heartbeat',
		'queue_depth',
		'daily_success_rate',
		'ssrf_hardening',
		'audit_integrity',
		'protocol_enforcement',
		'api_latency',
		'active_connections',
		'throughput',
	);

	foreach ( $reporter_ids as $id ) {
		delete_transient( 'eapi_report_' . $id );
	}

	delete_transient( 'eapi_dashboard_history' );
}
