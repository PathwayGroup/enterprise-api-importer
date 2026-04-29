<?php
/**
 * Import execution runner module.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deep runner module for import execution orchestration.
 */
class TPORAPDI_Import_Runner {
	/**
	 * Handles an import trigger request.
	 *
	 * @param int    $import_id      Import job ID.
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return void
	 */
	public function handle_import_batch_hook( $import_id, $trigger_source = 'run_now' ): void {
		$this->start_or_continue_triggered_run(
			absint( $import_id ),
			$this->normalize_trigger_source( $trigger_source )
		);
	}

	/**
	 * Starts a manual import run and returns actionable failures to the caller.
	 *
	 * @param int    $import_id      Import job ID.
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return true|WP_Error
	 */
	public function start_manual_run( int $import_id, string $trigger_source = 'manual' ) {
		$startup_policy = $this->resolve_manual_startup_policy( $import_id, $trigger_source );

		if ( is_wp_error( $startup_policy ) ) {
			return $startup_policy;
		}

		return $this->start_extract_stage_run(
			(int) $startup_policy['import_id'],
			(string) $startup_policy['trigger_source']
		);
	}

	/**
	 * Builds the normalized processing-stage configuration for one import job.
	 *
	 * @param array<string, mixed> $import_job Import job configuration row.
	 *
	 * @return array<string, mixed>
	 */
	public function get_processing_stage_config( array $import_job ): array {
		$custom_meta_mappings = array();

		if ( ! empty( $import_job['custom_meta_mappings'] ) ) {
			$decoded_meta_mappings = is_string( $import_job['custom_meta_mappings'] )
				? json_decode( $import_job['custom_meta_mappings'], true )
				: $import_job['custom_meta_mappings'];

			if ( is_array( $decoded_meta_mappings ) ) {
				$custom_meta_mappings = $decoded_meta_mappings;
			}
		}

		$unique_id_path = isset( $import_job['unique_id_path'] ) ? trim( (string) $import_job['unique_id_path'] ) : 'id';
		if ( '' === $unique_id_path ) {
			$unique_id_path = 'id';
		}

		$featured_image_source_path = isset( $import_job['featured_image_source_path'] ) ? trim( (string) $import_job['featured_image_source_path'] ) : 'image.url';
		if ( '' === $featured_image_source_path ) {
			$featured_image_source_path = 'image.url';
		}

		return array(
			'mapping_template'           => (string) $import_job['mapping_template'],
			'title_template'             => isset( $import_job['title_template'] ) ? (string) $import_job['title_template'] : '',
			'excerpt_template'           => isset( $import_job['excerpt_template'] ) ? (string) $import_job['excerpt_template'] : '',
			'post_name_template'         => isset( $import_job['post_name_template'] ) ? (string) $import_job['post_name_template'] : '',
			'target_post_type'           => isset( $import_job['target_post_type'] ) ? (string) $import_job['target_post_type'] : 'post',
			'unique_id_path'             => $unique_id_path,
			'featured_image_source_path' => $featured_image_source_path,
			'post_author'                => isset( $import_job['post_author'] ) ? absint( $import_job['post_author'] ) : 0,
			'post_status'                => isset( $import_job['post_status'] ) ? (string) $import_job['post_status'] : 'draft',
			'comment_status'             => isset( $import_job['comment_status'] ) ? (string) $import_job['comment_status'] : 'closed',
			'ping_status'                => isset( $import_job['ping_status'] ) ? (string) $import_job['ping_status'] : 'closed',
			'custom_meta_mappings'       => $custom_meta_mappings,
		);
	}

	/**
	 * Executes one item transform/load operation using a normalized processing bundle.
	 *
	 * @param array<string, mixed> $item              Item payload.
	 * @param array<string, mixed> $processing_config Normalized processing-stage configuration.
	 * @param int                  $import_id         Import job ID.
	 * @param array<string, int>   $existing_post_ids Map of external IDs to existing post IDs.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_chunk_item( array $item, array $processing_config, int $import_id, array $existing_post_ids ) {
		return tporapdi_transform_and_load_item(
			$item,
			(string) $processing_config['mapping_template'],
			(string) $processing_config['title_template'],
			(string) $processing_config['target_post_type'],
			(string) $processing_config['unique_id_path'],
			$import_id,
			(string) $processing_config['featured_image_source_path'],
			(int) $processing_config['post_author'],
			(string) $processing_config['post_status'],
			(string) $processing_config['comment_status'],
			(string) $processing_config['ping_status'],
			is_array( $processing_config['custom_meta_mappings'] ) ? $processing_config['custom_meta_mappings'] : array(),
			$existing_post_ids,
			(string) $processing_config['excerpt_template'],
			(string) $processing_config['post_name_template']
		);
	}

	/**
	 * Processes one chunk of staged items for a row.
	 *
	 * @param array<int, array<string, mixed>> $chunk_items             Chunk item payloads.
	 * @param array<string, mixed>             $processing_config       Normalized processing-stage configuration.
	 * @param int                              $row_id                 Staging row ID.
	 * @param int                              $row_import_id          Import job ID for the row.
	 * @param float                            $started_at_microtime   Slice start time.
	 * @param int                              $max_runtime_seconds    Maximum allowed runtime.
	 *
	 * @return array{rows_processed: int, rows_created: int, rows_updated: int, errors: array<int, string>, timed_out: bool}
	 */
	public function process_chunk( array $chunk_items, array $processing_config, int $row_id, int $row_import_id, float $started_at_microtime, int $max_runtime_seconds ): array {
		$existing_post_ids = tporapdi_get_existing_imported_post_ids_by_external_ids(
			$this->collect_chunk_external_ids( $chunk_items, (string) $processing_config['unique_id_path'] ),
			$row_import_id
		);

		$result = array(
			'rows_processed' => 0,
			'rows_created'   => 0,
			'rows_updated'   => 0,
			'errors'         => array(),
			'timed_out'      => false,
		);

		foreach ( $chunk_items as $item ) {
			if ( ( microtime( true ) - $started_at_microtime ) >= $max_runtime_seconds ) {
				$result['timed_out'] = true;
				break;
			}

			++$result['rows_processed'];

			$item_result = $this->process_chunk_item( $item, $processing_config, $row_import_id, $existing_post_ids );

			if ( is_wp_error( $item_result ) ) {
				$result['errors'][] = sprintf(
					/* translators: 1: staging row ID, 2: error message. */
					__( 'Row %1$d item failed: %2$s', 'tporret-api-data-importer' ),
					$row_id,
					$item_result->get_error_message()
				);
				continue;
			}

			if ( isset( $item_result['action'] ) && 'inserted' === $item_result['action'] ) {
				++$result['rows_created'];
			} elseif ( isset( $item_result['action'] ) && 'updated' === $item_result['action'] ) {
				++$result['rows_updated'];
			}
		}

		return $result;
	}

	/**
	 * Processes one staged row through decode, chunking, and completion.
	 *
	 * @param array<string, mixed> $staged_row            Raw staging row.
	 * @param float                $started_at_microtime Slice start time.
	 * @param int                  $max_runtime_seconds  Maximum allowed runtime.
	 *
	 * @return array{temp_rows_processed: int, rows_processed: int, rows_created: int, rows_updated: int, errors: array<int, string>, timed_out: bool, has_remaining: bool}
	 */
	public function process_staging_row( array $staged_row, float $started_at_microtime, int $max_runtime_seconds ): array {
		$result = array(
			'temp_rows_processed' => 0,
			'rows_processed'      => 0,
			'rows_created'        => 0,
			'rows_updated'        => 0,
			'errors'              => array(),
			'timed_out'           => false,
			'has_remaining'       => false,
		);

		$row_id        = isset( $staged_row['id'] ) ? (int) $staged_row['id'] : 0;
		$row_import_id = isset( $staged_row['import_id'] ) ? (int) $staged_row['import_id'] : 0;

		if ( $row_id <= 0 ) {
			$result['errors'][] = __( 'Encountered an invalid staging row identifier.', 'tporret-api-data-importer' );
			return $result;
		}

		$import_job = Tporapdi_Job_Repository::find( $row_import_id );

		if ( ! is_array( $import_job ) ) {
			$result['errors'][] = sprintf(
				/* translators: %d is import job ID. */
				__( 'Import job %d could not be loaded for processing.', 'tporret-api-data-importer' ),
				$row_import_id
			);
			return $result;
		}

		$decoded_items = json_decode( (string) $staged_row['raw_json'], true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$result['errors'][] = sprintf(
				/* translators: 1: staging row ID, 2: JSON error message. */
				__( 'Staging row %1$d has invalid JSON: %2$s', 'tporret-api-data-importer' ),
				$row_id,
				json_last_error_msg()
			);
			return $result;
		}

		if ( ! is_array( $decoded_items ) ) {
			$result['errors'][] = sprintf(
				/* translators: %d is the staging row ID. */
				__( 'Staging row %d does not contain an array payload.', 'tporret-api-data-importer' ),
				$row_id
			);
			return $result;
		}

		$items             = tporapdi_normalize_staged_items( $decoded_items );
		$processing_config = $this->get_processing_stage_config( $import_job );
		$chunks            = array_chunk( $items, 50 );

		foreach ( $chunks as $chunk_items ) {
			$chunk_result = $this->process_chunk(
				$chunk_items,
				$processing_config,
				$row_id,
				$row_import_id,
				$started_at_microtime,
				$max_runtime_seconds
			);

			$result['rows_processed'] += (int) $chunk_result['rows_processed'];
			$result['rows_created']   += (int) $chunk_result['rows_created'];
			$result['rows_updated']   += (int) $chunk_result['rows_updated'];

			if ( ! empty( $chunk_result['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], $chunk_result['errors'] );
			}

			if ( ! empty( $chunk_result['timed_out'] ) ) {
				$result['timed_out']     = true;
				$result['has_remaining'] = true;
				return $result;
			}
		}

		$marked_processed = Tporapdi_Queue_Repository::mark_processed( $row_id );

		if ( ! $marked_processed ) {
			$result['errors'][] = sprintf(
				/* translators: %d is the staging row ID. */
				__( 'Failed to mark staging row %d as processed.', 'tporret-api-data-importer' ),
				$row_id
			);
			$result['has_remaining'] = true;
			return $result;
		}

		$result['temp_rows_processed'] = 1;

		return $result;
	}

	/**
	 * Processes unprocessed staging rows for one scheduled batch slice.
	 *
	 * @param float $started_at_microtime Slice start time from microtime(true).
	 * @param int   $import_id            Import job ID.
	 * @param int   $max_runtime_seconds  Maximum allowed runtime.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function process_unprocessed_staging_rows( float $started_at_microtime, int $import_id, int $max_runtime_seconds = 45 ) {
		$import_id   = absint( $import_id );
		$staged_rows = Tporapdi_Queue_Repository::get_unprocessed( $import_id, 10 );

		if ( ! is_array( $staged_rows ) ) {
			return new WP_Error( 'tporapdi_staging_query_failed', __( 'Failed to query unprocessed staging rows.', 'tporret-api-data-importer' ) );
		}

		$result = array(
			'temp_rows_found'     => count( $staged_rows ),
			'temp_rows_processed' => 0,
			'rows_processed'      => 0,
			'rows_created'        => 0,
			'rows_updated'        => 0,
			'errors'              => array(),
			'timed_out'           => false,
			'has_remaining'       => false,
		);

		if ( empty( $staged_rows ) ) {
			return $result;
		}

		foreach ( $staged_rows as $staged_row ) {
			if ( ( microtime( true ) - $started_at_microtime ) >= $max_runtime_seconds ) {
				$result['timed_out']     = true;
				$result['has_remaining'] = true;
				break;
			}

			$row_result = $this->process_staging_row(
				is_array( $staged_row ) ? $staged_row : array(),
				$started_at_microtime,
				$max_runtime_seconds
			);

			$result['temp_rows_processed'] += (int) $row_result['temp_rows_processed'];
			$result['rows_processed']      += (int) $row_result['rows_processed'];
			$result['rows_created']        += (int) $row_result['rows_created'];
			$result['rows_updated']        += (int) $row_result['rows_updated'];

			if ( ! empty( $row_result['errors'] ) ) {
				$result['errors'] = array_merge( $result['errors'], $row_result['errors'] );
			}

			if ( ! empty( $row_result['timed_out'] ) ) {
				$result['timed_out'] = true;
			}

			if ( ! empty( $row_result['has_remaining'] ) ) {
				$result['has_remaining'] = true;
			}

			if ( $result['timed_out'] ) {
				break;
			}
		}

		if ( ! $result['has_remaining'] ) {
			$result['has_remaining'] = tporapdi_has_unprocessed_staging_rows( $import_id );
		}

		return $result;
	}

	/**
	 * Handles one scheduled batch slice for the active import run.
	 *
	 * @return void
	 */
	public function handle_scheduled_import_batch(): void {
		$state = $this->get_active_run_state();

		if ( empty( $state ) || empty( $state['import_id'] ) ) {
			return;
		}

		$this->run_scheduled_batch_slice( $state );
	}

	/**
	 * Runs one scheduled batch slice from the current active state.
	 *
	 * @param array<string, mixed> $state Active run state.
	 *
	 * @return void
	 */
	private function run_scheduled_batch_slice( array $state ): void {

		$import_id       = absint( $state['import_id'] );
		$state['slices'] = isset( $state['slices'] ) ? ( (int) $state['slices'] + 1 ) : 1;

		$processing_result = $this->process_unprocessed_staging_rows( microtime( true ), $import_id, 45 );

		if ( is_wp_error( $processing_result ) ) {
			$this->write_failed_processing_log( $import_id, $state, $processing_result );
			$this->clear_active_run_state();
			return;
		}

		$state = $this->merge_processing_result( $state, $processing_result );

		if ( ! empty( $processing_result['has_remaining'] ) ) {
			$this->set_active_run_state( $state );
			tporapdi_schedule_import_batch_event( null, false );
			return;
		}

		$this->finalize_run( $import_id, $state );
	}

	/**
	 * Returns the active import run state.
	 *
	 * @return array<string, mixed>
	 */
	public function get_active_run_state(): array {
		$state = get_option( 'tporapdi_active_import_run', array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return $state;
	}

	/**
	 * Saves the active import run state.
	 *
	 * @param array<string, mixed> $state Run state.
	 *
	 * @return void
	 */
	public function set_active_run_state( array $state ): void {
		update_option( 'tporapdi_active_import_run', $state, false );
	}

	/**
	 * Clears the active import run state.
	 *
	 * @return void
	 */
	public function clear_active_run_state(): void {
		delete_option( 'tporapdi_active_import_run' );
	}

	/**
	 * Normalizes the trigger source to a supported value.
	 *
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return string
	 */
	private function normalize_trigger_source( string $trigger_source ): string {
		$trigger_source  = sanitize_key( $trigger_source );
		$allowed_sources = array( 'manual', 'run_now', 'recurring' );

		if ( ! in_array( $trigger_source, $allowed_sources, true ) ) {
			return 'run_now';
		}

		return $trigger_source;
	}

	/**
	 * Starts a new triggered run or continues an active run for the same import.
	 *
	 * @param int    $import_id      Import job ID.
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return void
	 */
	private function start_or_continue_triggered_run( int $import_id, string $trigger_source ): void {
		$startup_policy = $this->resolve_triggered_startup_policy( $import_id, $trigger_source );

		if ( 'skip' === $startup_policy['action'] ) {
			return;
		}

		if ( 'resume' === $startup_policy['action'] ) {
			$this->handle_scheduled_import_batch();
			return;
		}

		$this->start_extract_stage_run(
			(int) $startup_policy['import_id'],
			(string) $startup_policy['trigger_source'],
			true
		);
	}

	/**
	 * Evaluates whether startup may begin for an import and if an active run may resume.
	 *
	 * @param int  $import_id                Import job ID.
	 * @param bool $allow_same_import_resume Whether the same import may resume active processing.
	 *
	 * @return string One of start|resume|blocked.
	 */
	private function evaluate_startup_gate( int $import_id, bool $allow_same_import_resume = false ): string {
		$active_state = $this->get_active_run_state();

		if ( empty( $active_state['run_id'] ) ) {
			return 'start';
		}

		$active_import_id = isset( $active_state['import_id'] ) ? absint( $active_state['import_id'] ) : 0;

		if ( $allow_same_import_resume && $active_import_id === $import_id ) {
			return 'resume';
		}

		return 'blocked';
	}

	/**
	 * Applies manual start startup policy: input normalization plus gate/error mapping.
	 *
	 * @param int    $import_id      Import job ID.
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return array{import_id:int, trigger_source:string}|WP_Error
	 */
	private function resolve_manual_startup_policy( int $import_id, string $trigger_source ) {
		$normalized_import_id      = absint( $import_id );
		$normalized_trigger_source = $this->normalize_trigger_source( $trigger_source );

		if ( $normalized_import_id <= 0 ) {
			return new WP_Error( 'invalid_import_id', __( 'Import job ID is invalid.', 'tporret-api-data-importer' ) );
		}

		if ( 'blocked' === $this->evaluate_startup_gate( $normalized_import_id ) ) {
			return new WP_Error( 'import_running', __( 'An import is already running.', 'tporret-api-data-importer' ) );
		}

		return array(
			'import_id'      => $normalized_import_id,
			'trigger_source' => $normalized_trigger_source,
		);
	}

	/**
	 * Applies triggered startup policy: input normalization plus gate-to-action mapping.
	 *
	 * @param int    $import_id      Import job ID.
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return array{action:string, import_id:int, trigger_source:string}
	 */
	private function resolve_triggered_startup_policy( int $import_id, string $trigger_source ): array {
		$normalized_import_id      = absint( $import_id );
		$normalized_trigger_source = $this->normalize_trigger_source( $trigger_source );

		if ( $normalized_import_id <= 0 ) {
			return array(
				'action'         => 'skip',
				'import_id'      => $normalized_import_id,
				'trigger_source' => $normalized_trigger_source,
			);
		}

		$startup_gate = $this->evaluate_startup_gate( $normalized_import_id, true );

		if ( 'resume' === $startup_gate ) {
			return array(
				'action'         => 'resume',
				'import_id'      => $normalized_import_id,
				'trigger_source' => $normalized_trigger_source,
			);
		}

		if ( 'blocked' === $startup_gate ) {
			return array(
				'action'         => 'skip',
				'import_id'      => $normalized_import_id,
				'trigger_source' => $normalized_trigger_source,
			);
		}

		return array(
			'action'         => 'start',
			'import_id'      => $normalized_import_id,
			'trigger_source' => $normalized_trigger_source,
		);
	}

	/**
	 * Collects external IDs for one chunk using the configured item path.
	 *
	 * @param array<int, array<string, mixed>> $chunk_items     Chunk item payloads.
	 * @param string                           $unique_id_path Unique ID path.
	 *
	 * @return array<int, string>
	 */
	private function collect_chunk_external_ids( array $chunk_items, string $unique_id_path ): array {
		$chunk_external_ids = array();

		foreach ( $chunk_items as $chunk_item ) {
			$chunk_external_id = tporapdi_get_item_value_by_path( $chunk_item, $unique_id_path );

			if ( is_scalar( $chunk_external_id ) ) {
				$chunk_external_ids[] = trim( (string) $chunk_external_id );
			}
		}

		return $chunk_external_ids;
	}

	/**
	 * Creates a new active run state payload.
	 *
	 * @param int    $import_id      Import job ID.
	 * @param string $trigger_source Trigger source context.
	 *
	 * @return array<string, mixed>
	 */
	private function create_run_state( int $import_id, string $trigger_source ): array {
		$started_unix = time();

		return array(
			'run_id'              => wp_generate_uuid4(),
			'import_id'           => $import_id,
			'trigger_source'      => $trigger_source,
			'start_timestamp'     => $started_unix,
			'start_time'          => gmdate( 'Y-m-d H:i:s', $started_unix ),
			'rows_processed'      => 0,
			'rows_created'        => 0,
			'rows_updated'        => 0,
			'temp_rows_found'     => 0,
			'temp_rows_processed' => 0,
			'errors'              => array(),
			'slices'              => 0,
		);
	}

	/**
	 * Starts extract/stage and initializes active state for scheduled processing.
	 *
	 * @param int    $import_id         Import job ID.
	 * @param string $trigger_source    Trigger source context.
	 * @param bool   $log_start_failure Whether to write a failure log when startup fails.
	 *
	 * @return true|WP_Error
	 */
	private function start_extract_stage_run( int $import_id, string $trigger_source, bool $log_start_failure = false ) {
		$extract_result = tporapdi_extract_and_stage_data( $import_id );

		if ( is_wp_error( $extract_result ) ) {
			if ( $log_start_failure ) {
				$this->write_failed_start_log( $import_id, $trigger_source, $extract_result );
			}

			return $extract_result;
		}

		$this->set_active_run_state( $this->create_run_state( $import_id, $trigger_source ) );
		$this->handle_scheduled_import_batch();

		return true;
	}

	/**
	 * Writes a failed log when the extract/stage step cannot start.
	 *
	 * @param int      $import_id      Import job ID.
	 * @param string   $trigger_source Trigger source context.
	 * @param WP_Error $error          Startup failure.
	 *
	 * @return void
	 */
	private function write_failed_start_log( int $import_id, string $trigger_source, WP_Error $error ): void {
		$now = gmdate( 'Y-m-d H:i:s', time() );

		tporapdi_write_import_log(
			$import_id,
			wp_generate_uuid4(),
			'failed',
			0,
			0,
			0,
			array(
				'start_time'          => $now,
				'end_time'            => $now,
				'orphans_trashed'     => 0,
				'temp_rows_found'     => 0,
				'temp_rows_processed' => 0,
				'slices'              => 0,
				'trigger_source'      => $trigger_source,
				'processing_errors'   => array( $error->get_error_message() ),
			),
			$now
		);
	}

	/**
	 * Writes a failed log when scheduled processing cannot continue.
	 *
	 * @param int      $import_id Import job ID.
	 * @param array    $state     Active run state.
	 * @param WP_Error $error     Processing failure.
	 *
	 * @return void
	 */
	private function write_failed_processing_log( int $import_id, array $state, WP_Error $error ): void {
		$end_time = gmdate( 'Y-m-d H:i:s', time() );
		$details  = array(
			'start_time'          => $state['start_time'],
			'end_time'            => $end_time,
			'orphans_trashed'     => 0,
			'temp_rows_found'     => (int) $state['temp_rows_found'],
			'temp_rows_processed' => (int) $state['temp_rows_processed'],
			'slices'              => (int) $state['slices'],
			'trigger_source'      => isset( $state['trigger_source'] ) ? sanitize_key( (string) $state['trigger_source'] ) : 'unknown',
			'processing_errors'   => array( $error->get_error_message() ),
		);

		tporapdi_write_import_log(
			$import_id,
			(string) $state['run_id'],
			'failed',
			(int) $state['rows_processed'],
			(int) $state['rows_created'],
			(int) $state['rows_updated'],
			$details,
			(string) $state['start_time']
		);
	}

	/**
	 * Merges one processing slice into the active run state.
	 *
	 * @param array<string, mixed> $state             Active run state.
	 * @param array<string, mixed> $processing_result Processing slice result.
	 *
	 * @return array<string, mixed>
	 */
	private function merge_processing_result( array $state, array $processing_result ): array {
		$state['rows_processed']      = (int) $state['rows_processed'] + (int) $processing_result['rows_processed'];
		$state['rows_created']        = (int) $state['rows_created'] + (int) $processing_result['rows_created'];
		$state['rows_updated']        = (int) $state['rows_updated'] + (int) $processing_result['rows_updated'];
		$state['temp_rows_found']     = (int) $state['temp_rows_found'] + (int) $processing_result['temp_rows_found'];
		$state['temp_rows_processed'] = (int) $state['temp_rows_processed'] + (int) $processing_result['temp_rows_processed'];

		if ( ! empty( $processing_result['errors'] ) && is_array( $processing_result['errors'] ) ) {
			$existing_errors = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();
			$state['errors'] = array_merge( $existing_errors, $processing_result['errors'] );
		}

		return $state;
	}

	/**
	 * Finalizes a completed run and writes the terminal log.
	 *
	 * @param int                  $import_id Import job ID.
	 * @param array<string, mixed> $state     Active run state.
	 *
	 * @return void
	 */
	private function finalize_run( int $import_id, array $state ): void {
		$orphans_trashed = tporapdi_trash_orphaned_imported_posts( (int) $state['start_timestamp'], $import_id );

		if ( is_wp_error( $orphans_trashed ) ) {
			$existing_errors   = isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array();
			$existing_errors[] = $orphans_trashed->get_error_message();
			$state['errors']   = $existing_errors;
			$orphans_trashed   = 0;
		}

		$end_time = gmdate( 'Y-m-d H:i:s', time() );

		if ( 0 === (int) $state['rows_processed'] && 0 === (int) $state['temp_rows_found'] ) {
			$status = 'no_data';
		} elseif ( empty( $state['errors'] ) ) {
			$status = 'success';
		} else {
			$status = 'completed_with_errors';
		}

		$details = array(
			'start_time'          => $state['start_time'],
			'end_time'            => $end_time,
			'orphans_trashed'     => (int) $orphans_trashed,
			'temp_rows_found'     => (int) $state['temp_rows_found'],
			'temp_rows_processed' => (int) $state['temp_rows_processed'],
			'slices'              => (int) $state['slices'],
			'trigger_source'      => isset( $state['trigger_source'] ) ? sanitize_key( (string) $state['trigger_source'] ) : 'unknown',
			'processing_errors'   => isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array(),
		);

		tporapdi_write_import_log(
			$import_id,
			(string) $state['run_id'],
			$status,
			(int) $state['rows_processed'],
			(int) $state['rows_created'],
			(int) $state['rows_updated'],
			$details,
			(string) $state['start_time']
		);

		$this->clear_active_run_state();
	}
}
