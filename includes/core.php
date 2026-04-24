<?php
/**
 * Core bootstrap concerns: defaults and activation setup.
 *
 * @package EnterpriseAPIImporter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TPORAPDI_DB_SCHEMA_VERSION' ) ) {
	define( 'TPORAPDI_DB_SCHEMA_VERSION', '20260410-1' );
}

if ( ! defined( 'TPORAPDI_NETWORK_DB_SCHEMA_VERSION' ) ) {
	define( 'TPORAPDI_NETWORK_DB_SCHEMA_VERSION', '20260412-1' );
}

/**
 * Ensures template-management capability exists for administrators.
 *
 * @return void
 */
function tporapdi_sync_template_management_capabilities() {
	$admin_role = get_role( 'administrator' );

	if ( $admin_role instanceof WP_Role ) {
		$admin_role->add_cap( 'tporapdi_manage_templates' );
	}
}

/**
 * Runs on plugin activation.
 *
 * Creates and migrates ETL tables required by the import pipeline.
 *
 * @param bool $network_wide Whether activation was requested network-wide.
 */
function tporapdi_activate_plugin( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		tporapdi_block_network_activation();
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();

	$imports_table = $wpdb->prefix . 'tporapdi_imports';
	$logs_table    = $wpdb->prefix . 'custom_import_logs';
	$temp_table    = $wpdb->prefix . 'custom_import_temp';

	$sql_imports = "CREATE TABLE {$imports_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		name varchar(191) NOT NULL,
		endpoint_url text NOT NULL,
		auth_method varchar(50) NOT NULL DEFAULT 'none',
		auth_token text NOT NULL,
		auth_header_name varchar(191) NOT NULL DEFAULT '',
		auth_username varchar(191) NOT NULL DEFAULT '',
		auth_password text NOT NULL DEFAULT '',
		array_path varchar(191) NOT NULL DEFAULT '',
		unique_id_path varchar(191) NOT NULL DEFAULT 'id',
		recurrence varchar(32) NOT NULL DEFAULT 'off',
		custom_interval_minutes int(10) unsigned NOT NULL DEFAULT 0,
		filter_rules longtext NULL,
		target_post_type varchar(100) NOT NULL DEFAULT 'post',
		featured_image_source_path varchar(191) NOT NULL DEFAULT 'image.url',
		title_template varchar(255) NOT NULL DEFAULT '',
		excerpt_template varchar(255) NOT NULL DEFAULT '',
		post_name_template varchar(255) NOT NULL DEFAULT '',
		mapping_template longtext NOT NULL,
		post_author bigint(20) unsigned NOT NULL DEFAULT 0,
		lock_editing tinyint(1) unsigned NOT NULL DEFAULT 1,
		post_status varchar(20) NOT NULL DEFAULT 'draft',
		comment_status varchar(20) NOT NULL DEFAULT 'closed',
		ping_status varchar(20) NOT NULL DEFAULT 'closed',
		custom_meta_mappings longtext NULL,
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
	tporapdi_ensure_imports_auth_columns();
	tporapdi_ensure_imports_featured_image_column();
	tporapdi_ensure_imports_post_status_columns();
	tporapdi_ensure_imports_custom_meta_mappings_column();
	tporapdi_ensure_imports_template_columns();

	tporapdi_sync_template_management_capabilities();

	if ( false === wp_next_scheduled( 'tporapdi_daily_garbage_collection' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tporapdi_daily_garbage_collection' );
	}

	if ( is_multisite() ) {
		tporapdi_activate_network_dashboard_storage();
	}

	update_option( 'tporapdi_db_schema_version', TPORAPDI_DB_SCHEMA_VERSION );
}

/**
 * Prevents unsupported network activation in multisite installs.
 *
 * @return void
 */
function tporapdi_block_network_activation() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	deactivate_plugins( TPORAPDI_PLUGIN_BASENAME, true, true );

	wp_die(
		esc_html__( 'tporret API Data Importer cannot be network-activated. Activate it on the primary site to expose the Network Admin dashboard, then activate it only on the subsites that should run imports.', 'tporret-api-data-importer' ),
		esc_html__( 'Network activation is not supported', 'tporret-api-data-importer' ),
		array(
			'back_link' => true,
		)
	);
}

/**
 * Creates multisite storage used by the Network Admin dashboard.
 *
 * @return void
 */
function tporapdi_activate_network_dashboard_storage() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$table           = tporapdi_db_network_dashboard_table();
	$sql             = "CREATE TABLE {$table} (
		blog_id bigint(20) unsigned NOT NULL,
		site_url varchar(255) NOT NULL,
		site_name varchar(191) NOT NULL,
		overall_status varchar(20) NOT NULL DEFAULT 'green',
		health_status varchar(20) NOT NULL DEFAULT 'green',
		security_status varchar(20) NOT NULL DEFAULT 'green',
		performance_status varchar(20) NOT NULL DEFAULT 'green',
		import_count bigint(20) unsigned NOT NULL DEFAULT 0,
		dashboard_data longtext NULL,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (blog_id),
		KEY overall_status (overall_status),
		KEY updated_at (updated_at)
	) {$charset_collate};";

	dbDelta( $sql );
	update_site_option( 'tporapdi_network_db_schema_version', TPORAPDI_NETWORK_DB_SCHEMA_VERSION );
}

/**
 * Ensures multisite network schema updates run for existing installs.
 *
 * @return void
 */
function tporapdi_maybe_upgrade_network_schema() {
	if ( ! is_multisite() ) {
		return;
	}

	$installed_version = (string) get_site_option( 'tporapdi_network_db_schema_version', '' );

	if ( TPORAPDI_NETWORK_DB_SCHEMA_VERSION === $installed_version ) {
		return;
	}

	tporapdi_activate_network_dashboard_storage();
}

/**
 * Ensures auth-related columns exist on the imports table.
 *
 * Handles migration from the legacy auth_header_type column to the new
 * auth_method column, and adds auth_username / auth_password columns.
 *
 * @return void
 */
function tporapdi_ensure_imports_auth_columns() {
	global $wpdb;

	$table = $wpdb->prefix . 'tporapdi_imports';

	// Migrate legacy auth_header_type → auth_method.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$legacy_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_header_type' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$method_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_method' ) );

	if ( null !== $legacy_col && null === $method_col ) {
		// Add the new column first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_method varchar(50) NOT NULL DEFAULT 'none' AFTER endpoint_url", $table ) );

		// Map legacy values → new values.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET auth_method = 'bearer' WHERE auth_header_type = 'bearer'", $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET auth_method = 'api_key_custom', auth_header_name = 'Authorization-Key' WHERE auth_header_type = 'api-key' AND ( auth_header_name = '' OR auth_header_name IS NULL )", $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET auth_method = 'api_key_custom' WHERE auth_header_type = 'api-key-header'", $table ) );

		// Drop legacy column.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN auth_header_type', $table ) );
	} elseif ( null === $method_col ) {
		// Fresh install without legacy column — just add auth_method.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_method varchar(50) NOT NULL DEFAULT 'none' AFTER endpoint_url", $table ) );
	}

	// Ensure auth_header_name exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$header_name_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_header_name' ) );
	if ( null === $header_name_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_header_name varchar(191) NOT NULL DEFAULT '' AFTER auth_token", $table ) );
	}

	// Ensure auth_username exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$username_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_username' ) );
	if ( null === $username_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_username varchar(191) NOT NULL DEFAULT '' AFTER auth_header_name", $table ) );
	}

	// Ensure auth_password exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$password_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'auth_password' ) );
	if ( null === $password_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN auth_password text NOT NULL DEFAULT '' AFTER auth_username", $table ) );
	}
}

/**
 * Ensures featured image source path column exists on imports table.
 *
 * @return void
 */
function tporapdi_ensure_imports_featured_image_column() {
	global $wpdb;

	$table = $wpdb->prefix . 'tporapdi_imports';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$featured_image_path_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'featured_image_source_path' ) );

	if ( null === $featured_image_path_col ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN featured_image_source_path varchar(191) NOT NULL DEFAULT 'image.url' AFTER target_post_type", $table ) );
	}
}

/**
 * Ensures post_status, comment_status, and ping_status columns exist on the imports table.
 *
 * @return void
 */
function tporapdi_ensure_imports_post_status_columns() {
	global $wpdb;

	$table = $wpdb->prefix . 'tporapdi_imports';

	$columns = array(
		'post_status'    => "varchar(20) NOT NULL DEFAULT 'draft'",
		'comment_status' => "varchar(20) NOT NULL DEFAULT 'closed'",
		'ping_status'    => "varchar(20) NOT NULL DEFAULT 'closed'",
	);

	foreach ( $columns as $col_name => $col_def ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $col_name ) );
		if ( null === $exists ) {
			$query = '';

			switch ( $col_name ) {
				case 'post_status':
					$query = "ALTER TABLE %i ADD COLUMN `post_status` varchar(20) NOT NULL DEFAULT 'draft' AFTER lock_editing";
					break;
				case 'comment_status':
					$query = "ALTER TABLE %i ADD COLUMN `comment_status` varchar(20) NOT NULL DEFAULT 'closed' AFTER lock_editing";
					break;
				case 'ping_status':
					$query = "ALTER TABLE %i ADD COLUMN `ping_status` varchar(20) NOT NULL DEFAULT 'closed' AFTER lock_editing";
					break;
			}

			if ( '' === $query ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Query text is selected from a fixed whitelist of literal ALTER statements.
			$wpdb->query( $wpdb->prepare( $query, $table ) );
		}
	}
}

/**
 * Ensures custom_meta_mappings column exists on the imports table.
 *
 * @return void
 */
function tporapdi_ensure_imports_custom_meta_mappings_column() {
	global $wpdb;

	$table = tporapdi_db_imports_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'custom_meta_mappings' ) );
	if ( null === $exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN custom_meta_mappings longtext NULL AFTER ping_status', $table ) );
	}
}

/**
 * Ensures excerpt_template and post_name_template columns exist on the imports table.
 *
 * @return void
 */
function tporapdi_ensure_imports_template_columns() {
	global $wpdb;

	$table = tporapdi_db_imports_table();

	$columns = array(
		'excerpt_template'   => "varchar(255) NOT NULL DEFAULT '' AFTER title_template",
		'post_name_template' => "varchar(255) NOT NULL DEFAULT '' AFTER excerpt_template",
	);

	foreach ( $columns as $col_name => $col_def ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $col_name ) );
		if ( null === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `{$col_name}` {$col_def}", $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}

/**
 * Runs on plugin deactivation.
 *
 * Clears plugin-owned scheduled cron events.
 *
 * @return void
 */
function tporapdi_deactivate_plugin() {
	wp_clear_scheduled_hook( 'tporapdi_daily_garbage_collection' );

	if ( is_multisite() ) {
		tporapdi_db_delete_network_snapshot( get_current_blog_id() );
	}
}

/**
 * Ensures schema updates run for existing active installs.
 *
 * @return void
 */
function tporapdi_maybe_upgrade_schema() {
	if ( is_multisite() ) {
		tporapdi_maybe_upgrade_network_schema();
	}

	tporapdi_sync_template_management_capabilities();
	tporapdi_ensure_imports_auth_columns();
	tporapdi_ensure_imports_featured_image_column();
	tporapdi_ensure_imports_post_status_columns();
	tporapdi_ensure_imports_custom_meta_mappings_column();
	tporapdi_ensure_imports_template_columns();

	$installed_version = (string) get_option( 'tporapdi_db_schema_version', '' );

	if ( TPORAPDI_DB_SCHEMA_VERSION === $installed_version ) {
		return;
	}

	tporapdi_activate_plugin();
}

/**
 * Returns default plugin settings.
 *
 * @return array<string, string>
 */
function tporapdi_get_default_settings() {
	return array(
		'cron_initial_delay_seconds' => '5',
		'cron_batch_delay_seconds'   => '15',
		'allow_internal_endpoints'   => '0',
		'allowed_endpoint_hosts'     => '',
		'allowed_endpoint_cidrs'     => '',
	);
}

// ---------------------------------------------------------------------------
// Credential encryption helpers.
// ---------------------------------------------------------------------------

if ( ! defined( 'TPORAPDI_CIPHER_METHOD' ) ) {
	define( 'TPORAPDI_CIPHER_METHOD', 'aes-256-cbc' );
}

if ( ! defined( 'TPORAPDI_ENCRYPTED_PREFIX' ) ) {
	define( 'TPORAPDI_ENCRYPTED_PREFIX', 'tporapdi_enc:' );
}

/**
 * Derives a 256-bit encryption key from the WordPress AUTH salts.
 *
 * @return string Raw binary key (32 bytes).
 */
function tporapdi_get_encryption_key() {
	return hash( 'sha256', wp_salt( 'auth' ) . 'tporapdi_credential_encryption', true );
}

/**
 * Encrypts a plaintext credential for safe at-rest storage.
 *
 * Returns the original value unchanged when the OpenSSL extension is
 * unavailable or the input is already encrypted.
 *
 * @param string $plaintext Credential to encrypt.
 *
 * @return string Encrypted string prefixed with TPORAPDI_ENCRYPTED_PREFIX, or original on failure.
 */
function tporapdi_encrypt_credential( $plaintext ) {
	$plaintext = (string) $plaintext;

	if ( '' === $plaintext ) {
		return '';
	}

	// Already encrypted.
	if ( str_starts_with( $plaintext, TPORAPDI_ENCRYPTED_PREFIX ) ) {
		return $plaintext;
	}

	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return $plaintext;
	}

	$key    = tporapdi_get_encryption_key();
	$iv_len = openssl_cipher_iv_length( TPORAPDI_CIPHER_METHOD );

	if ( false === $iv_len || $iv_len <= 0 ) {
		return $plaintext;
	}

	$iv = openssl_random_pseudo_bytes( $iv_len );

	if ( false === $iv ) {
		return $plaintext;
	}

	$ciphertext = openssl_encrypt( $plaintext, TPORAPDI_CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $ciphertext ) {
		return $plaintext;
	}

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for safe binary storage in DB text columns.
	return TPORAPDI_ENCRYPTED_PREFIX . base64_encode( $iv . $ciphertext );
}

/**
 * Decrypts an encrypted credential string.
 *
 * Returns the original value unchanged when it is not encrypted (legacy
 * plaintext) or when the OpenSSL extension is unavailable.
 *
 * @param string $encrypted Encrypted credential.
 *
 * @return string Decrypted plaintext, or original if not encrypted.
 */
function tporapdi_decrypt_credential( $encrypted ) {
	$encrypted = (string) $encrypted;

	if ( '' === $encrypted ) {
		return '';
	}

	if ( ! str_starts_with( $encrypted, TPORAPDI_ENCRYPTED_PREFIX ) ) {
		// Legacy plaintext — return as-is.
		return $encrypted;
	}

	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}

	$payload = substr( $encrypted, strlen( TPORAPDI_ENCRYPTED_PREFIX ) );

	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for reading encrypted binary stored in DB text columns.
	$raw = base64_decode( $payload, true );

	if ( false === $raw ) {
		return '';
	}

	$iv_len = openssl_cipher_iv_length( TPORAPDI_CIPHER_METHOD );

	if ( false === $iv_len || $iv_len <= 0 || strlen( $raw ) <= $iv_len ) {
		return '';
	}

	$iv         = substr( $raw, 0, $iv_len );
	$ciphertext = substr( $raw, $iv_len );

	$key       = tporapdi_get_encryption_key();
	$decrypted = openssl_decrypt( $ciphertext, TPORAPDI_CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $decrypted ) {
		return '';
	}

	return $decrypted;
}

/**
 * Returns the list of import-config column names that contain sensitive credentials.
 *
 * @return string[]
 */
function tporapdi_get_credential_field_names() {
	return array( 'auth_token', 'auth_password' );
}

/**
 * Decrypts all credential fields in an import configuration row.
 *
 * @param array<string, mixed> $row Import config row.
 *
 * @return array<string, mixed>
 */
function tporapdi_decrypt_import_credentials( array $row ) {
	foreach ( tporapdi_get_credential_field_names() as $field ) {
		if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) {
			$row[ $field ] = tporapdi_decrypt_credential( $row[ $field ] );
		}
	}

	return $row;
}

/**
 * Masks credential fields in an import configuration row for REST responses.
 *
 * Replaces raw credential values with empty strings and adds boolean
 * `has_*` flags so the frontend can show appropriate UI cues.
 *
 * @param array<string, mixed> $row Import config row (already decrypted).
 *
 * @return array<string, mixed>
 */
function tporapdi_mask_import_credentials( array $row ) {
	foreach ( tporapdi_get_credential_field_names() as $field ) {
		$has_value              = isset( $row[ $field ] ) && '' !== (string) $row[ $field ];
		$row[ 'has_' . $field ] = $has_value;
		$row[ $field ]          = '';
	}

	return $row;
}
