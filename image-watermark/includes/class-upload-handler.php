<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Watermark_Upload_Handler {

	/**
	 * Private attachment meta key holding the deferred client-side upload claim.
	 * WordPress removes it with the attachment's other post meta.
	 */
	const CLIENT_SIDE_CLAIM_META_KEY = '_iw_client_side_upload';

	/** Deferred client-side claim schema version. */
	const CLIENT_SIDE_CLAIM_VERSION = 1;

	/** Retryable finalize attempts before a pending claim becomes terminal. */
	const CLIENT_SIDE_CLAIM_MAX_ATTEMPTS = 3;

	/** Seconds a pending claim may wait for its finalize request. */
	const CLIENT_SIDE_CLAIM_TTL = DAY_IN_SECONDS;

	/** Seconds after which diagnostics report a pending claim as incomplete. */
	const CLIENT_SIDE_CLAIM_STALE_AFTER = HOUR_IN_SECONDS;

	/**
	 * Private attachment meta key holding the sub-size payload of a finalize
	 * withheld because another watermark operation held the lease.
	 */
	const CLIENT_SIDE_PAYLOAD_META_KEY = '_iw_client_side_finalize_payload';

	/** WordPress 7.1 sideload provenance meta key (one row per produced file). */
	const CLIENT_SIDE_PROVENANCE_META_KEY = '_wp_sideloaded_file';

	/** Maximum aged claims removed per sweep. */
	const CLIENT_SIDE_SWEEP_LIMIT = 100;

	/** Maximum unregistered sideloaded files listed in diagnostics. */
	const CLIENT_SIDE_ORPHAN_REPORT_LIMIT = 20;

	/**
	 * Journal reservation limit (bytes) for a name-stable remove or
	 * reapply, including room for its first retry. The reservation
	 * bounds what the operation writes for generation results within the known
	 * size names and measured widths, so it is admitted up to 6 KB under the
	 * reader's 131,072-byte cap; results outside that envelope can still reach
	 * the cap after live changes. 2 KB above the 2.0.17 value, for the
	 * manifest evidence a 3.0 reapply journals.
	 */
	const NAME_STABILITY_JOURNAL_BUDGET = 124928;

	/** Upper bound (seconds) of one remove or reapply, from its lease acquisition. */
	const OPERATION_TIME_LIMIT = 25;

	/** Seconds kept back for restore, generation, and commit after ownership passes. */
	const OPERATION_TIME_RESERVE = 5;

	/** Attachment rows one derivative ownership pass may read. */
	const OWNERSHIP_ROW_CEILING = 50000;

	/** Inherited pre-operation tokens kept in a journal record. */
	const PRE_OPERATION_TOKEN_LIMIT = 3;

	/** Intermediate candidate paths one size may carry; never silently truncated. */
	const PRE_OPERATION_CANDIDATE_LIMIT = 16;

	/** Maximum retained upload paths and insertions before the residue check runs early. */
	const UPLOAD_LEDGER_LIMIT = 200;

	/**
	 * Private attachment meta key holding the upload provenance record: the size
	 * and SHA-256 of the file the creating request uploaded, observed before any
	 * watermark. Written once, never updated; removed with the attachment.
	 */
	const PROVENANCE_META_KEY = '_iw_original_provenance';

	/** Upload provenance record schema version. */
	const PROVENANCE_VERSION = 1;

	/** Serialized size bound of the provenance record; a larger record is not written. */
	const PROVENANCE_MAX_BYTES = 1024;

	/** Post meta key of the watermark manifest of the last completed apply. */
	const MANIFEST_META_KEY = '_iw_watermark_manifest';

	/** Watermark manifest schema version. */
	const MANIFEST_VERSION = 1;

	/** Serialized size bound of the watermark manifest. */
	const MANIFEST_MAX_BYTES = 32768;

	/** Selected size names, and physical alias files, a manifest can hold. */
	const MANIFEST_NAME_LIMIT = 50;

	/** Reserved OD03-1 legacy size entries allowed on one attachment (P08-A R12). */
	const LEGACY_SIZE_LIMIT = 50;

	/** Serialized size bound of the complete marked legacy-entry map (P08-A R12). */
	const LEGACY_SIZE_MAX_BYTES = 32768;

	/** Reserved OD03-1 legacy size-name prefix. */
	const LEGACY_SIZE_PREFIX = 'iw-legacy-';

	/**
	 * Post meta key of the derivative drift marker: a metadata generation or
	 * update of a watermarked attachment that Image Watermark did not make.
	 */
	const DRIFT_META_KEY = '_iw_derivative_drift';

	/** Derivative drift marker schema version. */
	const DRIFT_VERSION = 1;

	/** Serialized size bound of the derivative drift marker. */
	const DRIFT_MAX_BYTES = 8192;

	/** Mismatch entries a drift marker can hold; the count keeps the true number. */
	const DRIFT_MISMATCH_LIMIT = 50;

	/** Remaining request seconds below which drift verification stops. */
	const DRIFT_VERIFY_RESERVE = 2;

	/** Marker reads and swaps one signal attempts before it is left to shutdown. */
	const DRIFT_SIGNAL_ATTEMPTS = 10;


	/**
	 * Plugin instance.
	 *
	 * @var Image_Watermark
	 */
	private $plugin;

	/**
	 * Tracks whether the current request originates from the admin.
	 *
	 * @var bool
	 */
	private $is_admin = true;

	/** Read-only upload lookup policy for this handler's active preview. */
	private $preview_lookup_read_only = false;

	/**
	 * Request-local upload claims keyed by claim token. A claim is opened by the
	 * priority-10 upload callback, sealed with the final file by its own
	 * finalizer, and consumed by its matching attachment only. Unsealed claims
	 * are never matched.
	 *
	 * @var array<string,array>
	 */
	private $pending_auto_uploads = [];

	/**
	 * Request-local wp_handle_upload brackets, innermost last. Each entry is
	 * tagged with the filter depth of the invocation that pushed it.
	 *
	 * @var array[]
	 */
	private $upload_brackets = [];

	/**
	 * Final paths of the most recent sealed uploads, eligible or not, bounded
	 * by UPLOAD_LEDGER_LIMIT so unclaimed uploads are still recognized.
	 *
	 * @var array[]
	 */
	private $sealed_upload_paths = [];

	/**
	 * Attachments inserted in this request, with site and sequence number.
	 *
	 * @var array[]
	 */
	private $inserted_attachments = [];

	/**
	 * Request-local sequence that orders seals and attachment inserts.
	 *
	 * @var int
	 */
	private $upload_sequence = 0;

	/**
	 * Whether the shutdown residue check is registered.
	 *
	 * @var bool
	 */
	private $upload_residue_scheduled = false;

	/**
	 * Number of automatic watermark callbacks currently processing an upload.
	 *
	 * @var int
	 */
	private $upload_callback_depth = 0;

	/**
	 * Automatic applies of this request whose metadata handoff is still to be
	 * verified, keyed by "site ID:attachment ID", each with its site ID,
	 * attachment ID, and operation token.
	 *
	 * @var array[]
	 */
	private $pending_handoffs = [];

	/**
	 * Whether the shutdown handoff verification is registered.
	 *
	 * @var bool
	 */
	private $handoff_shutdown_scheduled = false;

	/**
	 * Owned automatic metadata passes of this request, keyed by "site ID:
	 * attachment ID". Each holds the filter depth of the generation dispatch
	 * that rendered it, that hook's dispatch count (`did_filter()`)
	 * when it rendered, and the number of deeper dispatches observed since.
	 * Its own observer is the one at that depth that finds the count advanced
	 * by exactly those nested dispatches; any later dispatch adds itself.
	 *
	 * @var array[]
	 */
	private $owned_metadata_passes = [];

	/**
	 * Attachments, keyed like the owned passes, whose owned pass ended at its
	 * own observer, each with a hash of the metadata the pass returned. The
	 * attachment's next metadata write, with no generation of it observed in
	 * between, is its save and is exempt once when it saves exactly that
	 * value. Work on other attachments does not end it.
	 *
	 * @var string[]
	 */
	private $owned_metadata_saves = [];

	/**
	 * Signals, keyed like the owned passes, whose marker write kept losing to
	 * verification writes; shutdown tries again before verifying.
	 *
	 * @var string[] Hook of each signal.
	 */
	private $drift_invalidation_pending = [];

	/**
	 * Attachments, keyed like the owned passes, whose operation lease this
	 * request holds, each with the lease token.
	 *
	 * @var array<string,string>
	 */
	private $held_operation_leases = [];

	/**
	 * Attachments of this request with a drift signal to verify at shutdown,
	 * keyed like the owned passes, each with its site ID and attachment ID.
	 *
	 * @var array[]
	 */
	private $suspected_drift = [];

	/**
	 * Attachments, keyed like the owned passes, whose files this request has
	 * already read for drift verification. A later signal updates the marker
	 * but is not verified again in this request.
	 *
	 * @var array<string,bool>
	 */
	private $verified_drift = [];

	/**
	 * Whether the shutdown drift verification is registered.
	 *
	 * @var bool
	 */
	private $drift_shutdown_scheduled = false;

	/**
	 * Tracks if the missing font notice was added.
	 *
	 * @var bool
	 */
	private $missing_font_notice_added = false;

	/**
	 * Tracks if the backup failure notice was added.
	 *
	 * @var bool
	 */
	private $backup_failure_notice_added = false;

	/**
	 * Tracks if the timestamp preservation notice was added.
	 *
	 * @var bool
	 */
	private $timestamp_preserve_notice_added = false;

	/**
	 * Request-local internal operation carrier. It is not
	 * persisted and cannot suppress a later request's ordinary upload callback.
	 *
	 * @var array|null
	 */
	private $internal_operation_guard = null;

	/**
	 * The most recent private operation result for native consumers to adopt
	 * without changing current success payloads.
	 *
	 * @var array
	 */
	private $last_operation_outcome = [];

	/** @var int Nested common-operation dispatch depth. */
	private $operation_dispatch_depth = 0;

	/**
	 * Test-only operation fault seam. Production leaves it null, so it adds no
	 * runtime policy, filter, option, or public response behavior.
	 *
	 * @var callable|null
	 */
	private $operation_fault_injector = null;

	/** @var array Request-local exact deletion fences keyed by attachment ID. */
	private $attachment_deletion_fences = [];

	/**
	 * Request-local renderer failure detail, consumed by the staged operation
	 * boundary without changing public success payloads.
	 *
	 * @var array{code:string,message:string}
	 */
	private $last_renderer_failure = [ 'code' => '', 'message' => '' ];

	/**
	 * Request-local stack of recognized WordPress 7.1 client-side media REST
	 * phases. Each entry is pushed before and popped after its own callback.
	 *
	 * @var array[]
	 */
	private $client_side_phases = [];

	/**
	 * Request-local eligible uploads of a client-side create request, waiting
	 * for rest_after_insert_attachment to provide the owning attachment.
	 *
	 * @var array[]
	 */
	private $client_side_candidates = [];

	/**
	 * Request-local upload provenance candidates: uploads this request created,
	 * whose final file is still the file the priority-10 upload callback saw.
	 * Each is bound to at most one attachment and then removed. Bounded by
	 * UPLOAD_LEDGER_LIMIT.
	 *
	 * @var array[]
	 */
	private $provenance_candidates = [];

	/**
	 * Whether provenance stopped for the rest of this request: a candidate was
	 * evicted at UPLOAD_LEDGER_LIMIT (it could have made a later match
	 * ambiguous), or a binding was interrupted by an exception (an unconsumed
	 * candidate could otherwise bind bytes the automatic watermark changed
	 * afterwards). No further record is written in this request.
	 *
	 * @var bool
	 */
	private $provenance_halted = false;

	/**
	 * Request-local finalize pass evidence keyed by the REST request object.
	 * Each entry is used to verify the matching metadata commit.
	 *
	 * @var array<string,array>
	 */
	private $client_side_finalize = [];

	/**
	 * Attachment ID whose running automatic operation may not take the reapply
	 * (restore and regenerate) branch. Scoped by attachment so a nested operation
	 * cannot inherit another finalize request's policy.
	 *
	 * @var int
	 */
	private $initial_apply_only_attachment_id = 0;

	/**
	 * Request-local attachment IDs whose next finalize metadata write is
	 * withheld. Armed after every metadata filter has run and used once.
	 *
	 * @var array<int,bool>
	 */
	private $client_side_withheld_writes = [];

	/**
	 * Request-local observation of one guarded metadata generation: the main
	 * file's MIME type for the scoped suppression filters and the size names core
	 * was finally asked to create. Null outside that call.
	 *
	 * @var array|null
	 */
	private $guarded_generation = null;

	/**
	 * Holds on `_wp_attachment_metadata` writes, keyed by "site ID:attachment
	 * ID" with a count, that token-named generations keep from their start
	 * until their final metadata filter has run. A nested operation adds and
	 * releases its own hold without ending an outer one.
	 *
	 * @var array<string,int>
	 */
	private $withheld_metadata = [];

	/** Final-commit guards, keyed by site and attachment, innermost last. */
	private $metadata_commit_guards = [];

	/**
	 * Upload handler constructor.
	 *
	 * @param Image_Watermark $plugin
	 */
	public function __construct( Image_Watermark $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_notices', [ $this, 'render_persisted_auto_notices' ] );
		add_filter( 'pre_delete_attachment', [ $this, 'pre_delete_attachment' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'after_delete_attachment' ], 10, 2 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'begin_client_side_media_request' ], 10, 3 );
		add_filter( 'rest_dispatch_request', [ $this, 'gate_client_side_media_request' ], 10, 4 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'end_client_side_media_request' ], 10, 3 );
		add_action( 'rest_after_insert_attachment', [ $this, 'claim_client_side_upload' ], 10, 3 );
		add_action( 'wp_scheduled_delete', [ $this, 'sweep_client_side_claims' ] );
		// Internal upload bracketing around the priority-10 handle_upload_files()
		// callback. They open no claim themselves, so removing that
		// callback still disables automatic watermarking.
		add_filter( 'wp_handle_upload', [ $this, 'enter_upload_bracket' ], PHP_INT_MIN );
		add_filter( 'wp_handle_upload', [ $this, 'seal_upload_bracket' ], PHP_INT_MAX );
		add_action( 'add_attachment', [ $this, 'observe_inserted_attachment' ] );
		// Passive drift observers. They run last and are never removed, so they
		// cannot make WordPress skip another plugin's callback group.
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'observe_metadata_generation' ], PHP_INT_MAX, 3 );
		add_filter( 'wp_update_attachment_metadata', [ $this, 'observe_metadata_update' ], PHP_INT_MAX, 2 );
		// Core's automatic delivery paths never serve a clean reserved legacy
		// entry on a watermarked attachment (design 4.10). These use core's
		// existing filters, so no new Image Watermark hook is introduced.
		add_filter( 'wp_calculate_image_srcset_meta', [ $this, 'exclude_legacy_sizes_from_srcset' ], 10, 4 );
		add_filter( 'image_get_intermediate_size', [ $this, 'exclude_legacy_size_from_downsize' ], 10, 3 );
		register_shutdown_function( [ $this, 'release_pending_attachment_deletion_fences' ] );
	}

	/**
	 * Return the request-local guard only for the matching attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public function get_internal_operation_guard( $attachment_id ) {
		if ( ! is_array( $this->internal_operation_guard ) || (int) $this->internal_operation_guard['attachment_id'] !== (int) $attachment_id ) {
			return null;
		}

		return $this->internal_operation_guard;
	}

	/**
	 * Return the last structured operation result.
	 *
	 * @return array
	 */
	public function get_last_operation_outcome() {
		return $this->last_operation_outcome;
	}

	/**
	 * Describe the saved terminal result without rereading files or mutating state.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array
	 */
	public function get_attachment_operation_outcome( $attachment_id ) {
		$last = get_post_meta( (int) $attachment_id, '_iw_last_operation', true );
		if ( ! is_array( $last ) || empty( $last['code'] ) ) {
			return [];
		}

		$outcome_map = [ 'success' => 'complete', 'warning' => 'partial', 'skipped' => 'skipped', 'error' => 'failed' ];
		$status      = isset( $last['status'] ) ? $last['status'] : 'error';
		return [
			'outcome'      => isset( $last['outcome'] ) ? $last['outcome'] : ( isset( $outcome_map[ $status ] ) ? $outcome_map[ $status ] : 'failed' ),
			'operation_id' => '',
			'code'         => sanitize_key( $last['code'] ),
			'message'      => isset( $last['message'] ) ? $this->sanitize_operation_message( $last['message'] ) : '',
			'retryable'    => array_key_exists( 'operation', $last ) ? false : ! empty( $last['retryable'] ),
			'context'      => isset( $last['context'] ) ? sanitize_key( $last['context'] ) : '',
			'time'         => isset( $last['time'] ) ? (int) $last['time'] : 0,
			'sizes'        => array_key_exists( 'sizes', $last ) ? $this->normalize_operation_sizes( $last['sizes'] ) : [],
		];
	}

	/** Project only the saved message, after checking permission and revalidating it. */
	public function get_saved_result_hint( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return null;
		}
		$last = get_post_meta( $attachment_id, '_iw_last_operation', true );
		if ( ! is_array( $last ) || ! isset( $last['outcome'], $last['message'] ) || ! is_string( $last['outcome'] ) || ! in_array( $last['outcome'], [ 'partial', 'skipped', 'failed', 'interrupted' ], true ) || ! is_string( $last['message'] ) || $last['message'] === '' || preg_match( '//u', $last['message'] ) !== 1 ) {
			return null;
		}
		$message = $this->sanitize_operation_message( $last['message'], true );
		if ( ! is_string( $message ) || $message === '' || preg_match( '//u', $message ) !== 1 ) {
			return null;
		}
		$length = preg_match_all( '/./us', $message, $matches );
		if ( $length === false ) {
			return null;
		}
		if ( $length > 500 ) {
			$fallbacks = [
				'partial'     => __( 'The last image operation completed only partially.', 'image-watermark' ),
				'skipped'     => __( 'The last image operation was skipped.', 'image-watermark' ),
				'failed'      => __( 'The last image operation failed.', 'image-watermark' ),
				'interrupted' => __( 'The last image operation was interrupted.', 'image-watermark' ),
			];
			$message = $fallbacks[ $last['outcome'] ];
		}
		return [ 'outcome' => $last['outcome'], 'message' => $message ];
	}

	/** Read a single authoritative saved summary, without relying on post-meta cache. */
	private function read_contract_summary_row( $attachment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2", $attachment_id, '_iw_last_operation' ), ARRAY_A );
		if ( $wpdb->last_error !== '' || ! is_array( $rows ) || count( $rows ) !== 1 ) { return false; }
		$value = maybe_unserialize( $rows[0]['meta_value'] );
		return is_array( $value ) ? $value : false;
	}

	/** @internal Observe the exact database bytes for runner admission and reconciliation. */
	public function observe_runner_summary( $attachment_id ) {
		global $wpdb;
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 ) { return [ 'state' => 'error' ]; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2", $attachment_id, '_iw_last_operation' ), ARRAY_A );
		if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return [ 'state' => 'error' ]; }
		if ( ! $rows ) { return [ 'state' => 'absent', 'digest' => str_repeat( '0', 64 ) ]; }
		if ( count( $rows ) !== 1 ) { return [ 'state' => 'malformed' ]; }
		$raw = $rows[0]['meta_value'];
		$value = is_string( $raw ) && is_serialized( $raw ) ? @unserialize( $raw, [ 'allowed_classes' => false ] ) : false;
		$outcome = $this->project_contract_operation_outcome( $value );
		if ( ! $outcome ) { return [ 'state' => 'malformed' ]; }
		return [ 'state' => 'valid', 'meta_id' => (int) $rows[0]['meta_id'], 'raw' => $raw, 'digest' => hash( 'sha256', $raw ), 'outcome' => $outcome ];
	}

	/** Read the exact journal row for abandoned-attempt decisions, bypassing cache. */
	private function observe_runner_journal( $attachment_id, &$query_error = null ) {
		global $wpdb;
		$query_error = false;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2", $attachment_id, '_iw_operation_journal' ), ARRAY_A );
		if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { $query_error = true; return false; }
		if ( count( $rows ) > 1 ) { return false; }
		if ( ! $rows ) { return [ 'raw' => null, 'id' => 0, 'value' => [ 'version' => 1, 'current' => null, 'recoverable_predecessor' => null, 'history' => [] ] ]; }
		$raw = $rows[0]['meta_value'];
		$value = is_string( $raw ) ? @unserialize( $raw, [ 'allowed_classes' => false ] ) : false;
		if ( ! $this->is_valid_operation_journal( $value ) ) { return false; }
		return [ 'raw' => $raw, 'id' => (int) $rows[0]['meta_id'], 'value' => $value ];
	}

	/**
	 * Observe the authoritative evidence once for final settlement and release.
	 * An invalid or duplicate value is distinct from a failed read.
	 * @internal
	 */
	public function runner_release_observation( $attachment_id ) {
		$error = false;
		$journal = $this->observe_runner_journal( $attachment_id, $error );
		if ( $error ) { return [ 'state' => 'error' ]; }
		$summary = $this->observe_runner_summary( $attachment_id );
		if ( $summary['state'] === 'error' ) { return [ 'state' => 'error' ]; }
		return [ 'state' => 'ok', 'journal' => $journal, 'summary' => $summary ];
	}

	/**
	 * The closed A-P04-5 release predicate; no attachment write is authorized.
	 * @internal
	 */
	public function runner_unknown_evidence( $attachment_id, $identity, $type, $baseline, $observation, $disposition = null ) {
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 || $this->normalize_operation_identity( $identity ) !== $identity || ! is_array( $observation ) || $observation['state'] !== 'ok' ) { return false; }
		$observed = $observation['journal'];
		$summary = $observation['summary'];
		if ( $observed === false || $summary['state'] === 'malformed' ) { return true; }
		$journal = $observed['value'];
		$records = array_merge( [ 'current' => $journal['current'], 'recoverable_predecessor' => isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ], $journal['history'] );
		$compact = false;
		$same_pair = false;
		$eligible = [];
		foreach ( $records as $source => $record ) {
			if ( $record === null ) { continue; }
			$full = isset( $record['site_id'], $record['type'], $record['context'], $record['started_at'], $record['heartbeat_at'], $record['intended_flag'], $record['metadata_committed'] );
			if ( $full && isset( $record['run_identity'] ) && $this->normalize_operation_identity( $record['run_identity'] ) === $identity ) {
				$same_pair = true;
				if ( $record['site_id'] === get_current_blog_id() && $record['type'] === $type && in_array( $record['context'], [ 'auto-apply', 'manual-apply', 'manual-remove', 'manual-regenerate', 'bulk-apply', 'bulk-remove', 'bulk-regenerate' ], true ) && $this->operation_from_context( $record['context'] ) === $type ) { $eligible[ $source ] = $record; }
			}
			$compact_keys = [ 'token', 'type', 'state', 'started_at', 'ended_at', 'code', 'counts', 'targets' ];
			if ( ! $full && count( $record ) === count( $compact_keys ) && ! array_diff( $compact_keys, array_keys( $record ) ) ) { $compact = true; }
		}
		if ( ! $same_pair && $compact ) { return true; }
		if ( $summary['state'] === 'valid' && $summary['digest'] !== $baseline && $eligible ) {
			foreach ( $eligible as $source => $record ) {
				if ( $source === 'current' && ! $this->operation_is_terminal( $record ) ) {
					$lease = $this->read_operation_lock_row( $this->operation_lock_name( $attachment_id ) );
					if ( $lease === false ) { return false; }
					if ( $lease !== null ) {
						$lock = @unserialize( $lease['option_value'], [ 'allowed_classes' => false ] );
						if ( ! $this->runner_interpretable_lease( $lock ) ) { break; }
						if ( $lock['token'] === $record['token'] && $lock['heartbeat_at'] > time() - 900 ) { break; }
					}
				}
				return true;
			}
		}
		if ( is_array( $disposition ) && isset( $disposition['journal'], $disposition['summary'], $disposition['token'] ) && $disposition['journal'] === $observed && $disposition['summary'] === $summary ) {
			$lease = $this->read_operation_lock_row( $this->operation_lock_name( $attachment_id ) );
			if ( $lease === false ) { return false; }
			if ( $lease !== null ) {
				$lock = @unserialize( $lease['option_value'], [ 'allowed_classes' => false ] );
				if ( ! $this->runner_interpretable_lease( $lock ) || $lock['token'] === $disposition['token'] ) { return false; }
			}
			return true;
		}
		$terminal = $this->runner_terminal_from_journal( $journal, $identity );
		if ( $terminal && $terminal['operation'] === $type && isset( $eligible[ $terminal['source'] ] ) && $terminal['retryable'] === null ) { return true; }
		if ( $summary['state'] === 'valid' ) {
			$s = $summary['outcome'];
			if ( isset( $s['run'], $s['attempt'] ) && $s['run'] === $identity['run'] && $s['attempt'] === $identity['attempt'] && $s['operation'] === $type && $this->classify_operation_retryability( $s['outcome'], $s['code'] ) === null ) { return true; }
		}
		return false;
	}

	/** An uninterpretable row cannot establish that a particular lease is absent. */
	private function runner_interpretable_lease( $lock ) {
		return is_array( $lock ) && isset( $lock['version'], $lock['token'], $lock['acquired_at'], $lock['heartbeat_at'] ) && $lock['version'] === 1 && is_string( $lock['token'] ) && $lock['token'] !== '' && is_int( $lock['acquired_at'] ) && $lock['acquired_at'] > 0 && is_int( $lock['heartbeat_at'] ) && $lock['heartbeat_at'] >= $lock['acquired_at'];
	}

	/** Recovery is authorized for the current user and site, not the run owner. */
	public static function can_recover_runner_item( $attachment_id ) {
		$post = get_post( $attachment_id );
		return $post && $post->post_type === 'attachment' && in_array( $post->post_mime_type, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Settlement-only abandoned mutation recovery. $current supplies an exact
	 * active-row check and $release clears that same row while the lease is held.
	 * Current terminal records may also repair a lost summary before abandonment.
	 * @internal
	 */
	public function recover_runner_attempt( $attachment_id, $identity, $type, $baseline, $current, $release, $abandoned = true, &$disposition = null ) {
		$disposition = null;
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 || $this->normalize_operation_identity( $identity ) !== $identity || ! in_array( $type, [ 'apply', 'remove', 'regenerate' ], true ) || ! is_string( $baseline ) || ! is_callable( $current ) || ! is_callable( $release ) ) { return 'pending'; }
		$site = get_current_blog_id();
		$user = get_current_user_id();
		$authorized = static function() use ( $attachment_id, $site, $user ) {
			return get_current_blog_id() === $site && get_current_user_id() === $user && self::can_recover_runner_item( $attachment_id );
		};
		if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
		$token = $this->operation_token();
		$lease_read_error = false;
		$lock = $this->acquire_operation_lock( $attachment_id, $token, $lease_read_error );
		if ( ! $lock ) { return $lease_read_error ? 'error' : 'pending'; }
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ];
		try {
			$query_error = false;
			$observed = $this->observe_runner_journal( $attachment_id, $query_error );
			if ( $observed === false ) { return $query_error ? 'error' : 'pending'; }
			$journal = $observed['value'];
			$matching = [];
			foreach ( [ 'current', 'recoverable_predecessor' ] as $source ) {
				$record = isset( $journal[ $source ] ) ? $journal[ $source ] : null;
				if ( is_array( $record ) && isset( $record['run_identity'] ) && $this->normalize_operation_identity( $record['run_identity'] ) === $identity ) { $matching[ $source ] = $record; }
			}
			foreach ( $journal['history'] as $record ) {
				if ( is_array( $record ) && isset( $record['run_identity'] ) && $this->normalize_operation_identity( $record['run_identity'] ) === $identity ) { return 'pending'; }
			}
			if ( count( $matching ) > 1 ) { return 'pending'; }
			if ( ! $matching ) {
				if ( ! $abandoned ) { return 'pending'; }
				// Compacted records lack identity; only full unrelated records prove no match.
				$records = array_merge( [ $journal['current'], isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ], $journal['history'] );
				foreach ( $records as $record ) {
					if ( $record !== null && ! isset( $record['site_id'], $record['type'], $record['context'], $record['started_at'], $record['heartbeat_at'], $record['intended_flag'], $record['metadata_committed'] ) ) { return 'pending'; }
				}
				if ( ! $current() ) { return 'pending'; }
				$again = $this->observe_runner_journal( $attachment_id, $query_error );
				if ( $query_error ) { return 'error'; }
				if ( $again !== $observed ) { return 'pending'; }
				if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
				$result = $release( function() use ( &$operation, $authorized ) { return $authorized() && $this->renew_operation_lock( $operation ) && $authorized(); } );
				return ! $authorized() ? 'iw_run_item_forbidden' : $result;
			}
			$source = key( $matching );
			$record = $matching[ $source ];
			$terminal = $this->operation_is_terminal( $record );
			if ( $source !== 'current' || ! isset( $record['site_id'], $record['type'], $record['context'], $record['started_at'], $record['heartbeat_at'], $record['intended_flag'], $record['metadata_committed'] ) || $record['site_id'] !== get_current_blog_id() || $record['type'] !== $type || ! in_array( $record['context'], [ 'auto-apply', 'manual-apply', 'manual-remove', 'manual-regenerate', 'bulk-apply', 'bulk-remove', 'bulk-regenerate' ], true ) || $this->operation_from_context( $record['context'] ) !== $type || ( ! $terminal && ! $abandoned ) ) { return 'pending'; }
			$projection = $terminal ? $this->describe_terminal_operation_record( $attachment_id, $identity['run'], $identity['attempt'], $query_error ) : [];
			if ( $query_error ) { return 'error'; }
			if ( $terminal && ( ! $projection || $projection['source'] !== 'current' || $projection['operation'] !== $type || ! is_bool( $projection['retryable'] ) ) ) { return 'pending'; }
			$summary = $this->observe_runner_summary( $attachment_id );
			if ( $summary['state'] === 'error' ) { return 'error'; }
			if ( ! in_array( $summary['state'], [ 'valid', 'absent' ], true ) ) { return 'pending'; }
			$existing = $summary['state'] === 'valid' ? $summary['outcome'] : [];
			$confirmed = ! $terminal && isset( $existing['run'], $existing['attempt'], $existing['operation'], $existing['outcome'], $existing['code'], $existing['retryable'] ) && $existing['run'] === $identity['run'] && $existing['attempt'] === $identity['attempt'] && $existing['operation'] === $type && $existing['outcome'] === 'interrupted' && $existing['code'] === 'interrupted' && $existing['retryable'] === true;
			if ( ! $confirmed && $summary['digest'] !== $baseline ) { return 'pending'; }
			if ( ! $current() ) { return 'pending'; }
			$again = $this->observe_runner_journal( $attachment_id, $query_error );
			if ( $query_error ) { return 'error'; }
			if ( $again !== $observed ) { return 'pending'; }
			if ( ! $confirmed ) {
				$outcome = $terminal ? $projection['outcome'] : 'interrupted';
				$code = $terminal ? $projection['code'] : 'interrupted';
				$retryable = $terminal ? $projection['retryable'] : true;
				$statuses = [ 'complete' => 'success', 'partial' => 'warning', 'skipped' => 'skipped', 'failed' => 'error', 'interrupted' => 'error' ];
				if ( ! isset( $statuses[ $outcome ] ) ) { return 'pending'; }
				$value = [ 'status' => $statuses[ $outcome ], 'code' => $code, 'context' => $record['context'], 'outcome' => $outcome, 'operation' => $type, 'retryable' => $retryable ] + $identity;
				if ( ! $this->project_contract_operation_outcome( $value ) ) { return 'pending'; }
				$raw = maybe_serialize( $value );
				global $wpdb;
				if ( $summary['state'] === 'absent' ) {
					$sql = $wpdb->prepare( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT %d, %s, %s WHERE NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s)", $attachment_id, '_iw_last_operation', $raw, $attachment_id, '_iw_last_operation' );
				} else {
					$sql = $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND BINARY meta_value = %s", $raw, $summary['meta_id'], $attachment_id, '_iw_last_operation', $summary['raw'] );
				}
				if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
				if ( ! $this->renew_operation_lock( $operation ) ) { return 'pending'; }
				if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
				$written = $wpdb->query( $sql );
				wp_cache_delete( $attachment_id, 'post_meta' );
				$after = $this->observe_runner_summary( $attachment_id );
				if ( $after['state'] === 'error' ) { return 'error'; }
				if ( $written !== 1 ) {
					$post_journal = $this->observe_runner_journal( $attachment_id, $query_error );
					if ( $query_error ) { return 'error'; }
					if ( $authorized() && $current() && $post_journal === $observed && $after === $summary ) {
						$disposition = [ 'journal' => $observed, 'summary' => $summary, 'token' => $token ];
					}
					return 'pending';
				}
				if ( $after['state'] !== 'valid' || $after['raw'] !== $raw || ! $this->renew_operation_lock( $operation ) ) { return 'pending'; }
			}
			if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
			return $terminal ? 'terminal' : 'interrupted';
		} finally {
			$this->release_operation_lock( $operation );
		}
	}

	/** @internal Read the canonical saved outcome; this grants no repair authority. */
	public function read_contract_operation_outcome( $attachment_id ) {
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 ) { return []; }
		return $this->project_contract_operation_outcome( $this->read_contract_summary_row( $attachment_id ) );
	}

	/** @internal Project an authoritative raw-row value captured by a bounded reader. */
	public function project_contract_operation_outcome( $last ) {
		if ( ! is_array( $last ) || ! isset( $last['code'] ) || ! is_string( $last['code'] ) || $last['code'] === '' || sanitize_key( $last['code'] ) !== $last['code'] || ! isset( $last['context'] ) || ! is_string( $last['context'] ) || ( array_key_exists( 'outcome', $last ) && ! is_string( $last['outcome'] ) ) || ( array_key_exists( 'message', $last ) && ! is_string( $last['message'] ) ) || ( array_key_exists( 'sizes', $last ) && ! is_array( $last['sizes'] ) ) || ( array_key_exists( 'status', $last ) && ! is_string( $last['status'] ) ) ) { return []; }
		$operation = array_key_exists( 'operation', $last ) ? $last['operation'] : $this->operation_from_context( $last['context'] );
		if ( ! in_array( $operation, [ 'apply', 'remove', 'regenerate' ], true ) || ( array_key_exists( 'retryable', $last ) && ! is_bool( $last['retryable'] ) ) || array_key_exists( 'run', $last ) !== array_key_exists( 'attempt', $last ) ) { return []; }
		$identity = [];
		if ( array_key_exists( 'run', $last ) ) {
			$identity = $this->normalize_operation_identity( [ 'run' => $last['run'], 'attempt' => $last['attempt'] ] );
			if ( $identity === false ) { return []; }
		}
		$projection = [ 'operation' => $operation ];
		if ( array_key_exists( 'retryable', $last ) ) { $projection['retryable'] = $last['retryable']; }
		if ( $identity ) { $projection += $identity; }
		if ( strlen( serialize( $projection ) ) > 256 ) { return []; }
		$outcome_map = [ 'success' => 'complete', 'warning' => 'partial', 'skipped' => 'skipped', 'error' => 'failed' ];
		$status = isset( $last['status'] ) && is_string( $last['status'] ) ? $last['status'] : 'error';
		$outcome = isset( $last['outcome'] ) ? $last['outcome'] : ( isset( $outcome_map[ $status ] ) ? $outcome_map[ $status ] : 'failed' );
		if ( ! in_array( $outcome, [ 'complete', 'partial', 'skipped', 'failed', 'interrupted' ], true ) ) { return []; }
		$legacy = [ 'outcome' => $outcome, 'code' => $last['code'], 'message' => isset( $last['message'] ) ? $last['message'] : '', 'sizes' => isset( $last['sizes'] ) ? $last['sizes'] : [] ];
		$result = $this->contract_operation_outcome( $operation, $legacy, $identity );
		if ( ! array_key_exists( 'sizes', $last ) ) { $result['sizes'] = []; }
		$result['retryable'] = isset( $last['retryable'] ) ? $last['retryable'] : false;
		return $result;
	}

	/** @internal Project matching full terminal journal evidence, not settlement authority. */
	public function describe_terminal_operation_record( $attachment_id, $run, $attempt, &$query_error = null ) {
		$query_error = false;
		$identity = $this->normalize_operation_identity( [ 'run' => $run, 'attempt' => $attempt ] );
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 || $identity === false ) { return []; }
		// Settlement must not miss a newer terminal record behind stale post-meta
		// cache when deciding whether run-only unknown cancellation is permitted.
		$observed = $this->observe_runner_journal( $attachment_id, $query_error );
		if ( $observed === false ) { return []; }
		return $this->runner_terminal_from_journal( $observed['value'], $identity );
	}

	/** Project the existing selected sources from the same raw observation. @internal */
	public function runner_terminal_from_journal( $journal, $identity ) {
		foreach ( [ 'current', 'recoverable_predecessor' ] as $source ) {
			$record = isset( $journal[ $source ] ) ? $journal[ $source ] : null;
			if ( ! is_array( $record ) || ! $this->operation_is_terminal( $record ) || ! isset( $record['run_identity'], $record['site_id'], $record['type'], $record['code'], $record['context'], $record['started_at'], $record['heartbeat_at'], $record['intended_flag'], $record['metadata_committed'] ) || $this->normalize_operation_identity( $record['run_identity'] ) !== $identity || $record['site_id'] !== get_current_blog_id() || ! in_array( $record['type'], [ 'apply', 'remove', 'regenerate' ], true ) || ! is_string( $record['code'] ) || $record['code'] === '' ) { continue; }
			if ( ! in_array( $record['context'], [ 'auto-apply', 'manual-apply', 'manual-remove', 'manual-regenerate', 'bulk-apply', 'bulk-remove', 'bulk-regenerate' ], true ) || $this->operation_from_context( $record['context'] ) !== $record['type'] ) { continue; }
			$code = $record['code'];
			if ( $record['state'] === 'failed' && $this->operation_record_mutated_derivatives( $record, true ) ) { $code = 'failed_after_mutation'; }
			return [ 'operation' => $record['type'], 'outcome' => $record['state'], 'code' => $code, 'retryable' => $this->classify_operation_retryability( $record['state'], $code ), 'run' => $identity['run'], 'attempt' => $identity['attempt'], 'source' => $source ];
		}
		return [];
	}

	/** Infer the legacy operation from its registered context. */
	private function operation_from_context( $context ) {
		foreach ( [ 'apply', 'remove', 'regenerate' ] as $type ) {
			if ( is_string( $context ) && substr( $context, -strlen( $type ) - 1 ) === '-' . $type ) { return $type; }
		}
		return '';
	}

	/**
	 * Remove marked reserved legacy entries from the metadata core uses to
	 * build a srcset on a watermarked attachment. A prefix-only entry is left
	 * untouched, because a reserved entry needs both the prefix and the marker.
	 *
	 * @param array  $image_meta    Attachment metadata.
	 * @param int[]  $size_array    Requested width and height.
	 * @param string $image_src     Image source URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function exclude_legacy_sizes_from_srcset( $image_meta, $size_array = [], $image_src = '', $attachment_id = 0 ) {
		if ( ! is_array( $image_meta ) || empty( $image_meta['sizes'] ) || ! is_array( $image_meta['sizes'] ) || (int) $attachment_id <= 0 || ! $this->is_attachment_watermarked( $attachment_id ) ) {
			return $image_meta;
		}

		foreach ( $image_meta['sizes'] as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) ) {
				unset( $image_meta['sizes'][ $name ] );
			}
		}

		return $image_meta;
	}

	/**
	 * Let a dimension-based core selection fall through to the main file when
	 * core chose a marked reserved legacy entry on a watermarked attachment.
	 * A by-name request is explicit and is answered as stored (design 4.10).
	 *
	 * @param array        $data          Intermediate-size data.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested size name or dimensions.
	 * @return array|false
	 */
	public function exclude_legacy_size_from_downsize( $data, $attachment_id = 0, $size = null ) {
		if ( ! is_array( $data ) || ! is_array( $size ) || (int) $attachment_id <= 0 || empty( $data['file'] ) || ! is_string( $data['file'] ) || ! $this->is_attachment_watermarked( $attachment_id ) ) {
			return $data;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $data;
		}

		foreach ( $metadata['sizes'] as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) && isset( $entry['file'] ) && $entry['file'] === $data['file'] ) {
				return false;
			}
		}

		return $data;
	}

	/**
	 * Describe a deferred client-side upload claim without mutating it. A claim
	 * still pending after CLIENT_SIDE_CLAIM_STALE_AFTER seconds with no recorded
	 * finalize attempt means WordPress never finalized the upload, so its sizes
	 * were not automatically watermarked.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Empty when there is no valid claim.
	 */
	public function describe_client_side_claim( $attachment_id ) {
		$row = $this->read_client_side_claim_row( (int) $attachment_id );
		if ( ! is_array( $row ) ) {
			return [];
		}
		$claim = $row['claim'];

		return [
			'state'      => $claim['state'],
			'code'       => $claim['code'],
			'attempts'   => $claim['attempts'],
			'created_at' => $claim['created_at'],
			'stale'      => $claim['state'] === 'pending' && $claim['created_at'] + self::CLIENT_SIDE_CLAIM_STALE_AFTER < time(),
			'aged'       => $claim['state'] !== 'pending' && $claim['updated_at'] + self::CLIENT_SIDE_CLAIM_TTL < time(),
			'deferred'   => $this->read_client_side_payload( (int) $attachment_id ) !== false,
		];
	}

	/**
	 * Return the execution service's current eligibility decision without mutation.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Operation context.
	 * @param array|null $options Optional request-local options snapshot.
	 * @return array
	 */
	public function describe_operation_eligibility( $attachment_id, $context = 'manual-apply', $options = null ) {
		$attachment_id = (int) $attachment_id;
		if ( in_array( $context, [ 'manual-apply', 'manual-remove' ], true ) && $this->is_unfinalized_client_side_replacement( $attachment_id, wp_get_attachment_metadata( $attachment_id, true ) ) ) {
			return [
				'valid'   => false,
				'error'   => $this->client_side_unfinalized_message(),
				'warning' => null,
				'code'    => 'client_side_upload_unfinalized',
			];
		}
		$options = $this->normalize_rotation_options( $options === null ? apply_filters( 'iw_watermark_options', $this->plugin->options ) : $options );
		return $this->validate_watermark_eligibility( $options, $attachment_id, $context );
	}

	/**
	 * Describe the active engine's rotation capability without changing global
	 * engine availability. Zero degrees must keep working on hosts that cannot
	 * rotate layers.
	 *
	 * @param array|null $options Optional request-local option snapshot.
	 * @return array{available:bool,code:string,message:string,rotation:int}
	 */
	public function describe_rotation_capability( $options = null ) {
		$options  = $this->normalize_rotation_options( is_array( $options ) ? $options : $this->plugin->options );
		$rotation = $options['watermark_image']['rotation'];
		$result   = [
			'available' => true,
			'code'      => '',
			'message'   => '',
			'rotation'  => $rotation,
		];

		if ( $rotation === 0 ) {
			return $result;
		}

		if ( $this->plugin->get_extension() === 'gd' ) {
			if ( ! $this->gd_function_available( 'imagerotate' ) ) {
				$result['available'] = false;
				$result['code']      = 'rotation_unavailable';
				$result['message']   = __( 'Watermark rotation requires the GD imagerotate() function on this server.', 'image-watermark' );
			}
			return $result;
		}

		if ( $this->plugin->get_extension() === 'imagick' ) {
			$required = [ 'rotateImage', 'setImageAlphaChannel', 'setImagePage', 'newImage', 'compositeImage', 'getImageGeometry', 'evaluateImage' ];

			// The rotated text layer additionally measures and draws the line, so a
			// build without those methods must report an actionable unavailable
			// capability instead of failing later as a generic rotation failure.
			if ( isset( $options['watermark_image']['type'] ) && $options['watermark_image']['type'] === 'text' ) {
				$required[] = 'queryFontMetrics';
				$required[] = 'annotateImage';
			}

			foreach ( $required as $method ) {
				if ( ! class_exists( 'Imagick', false ) || ! defined( 'Imagick::ALPHACHANNEL_ACTIVATE' ) || ! defined( 'Imagick::ALPHACHANNEL_OPAQUE' ) || ! $this->imagick_method_available( $method ) ) {
					$result['available'] = false;
					$result['code']      = 'rotation_unavailable';
					$result['message']   = __( 'Watermark rotation requires additional Imagick layer support on this server.', 'image-watermark' );
					break;
				}
			}
			return $result;
		}

		$result['available'] = false;
		$result['code']      = 'rotation_unavailable';
		$result['message']   = __( 'Watermark rotation requires an available image processing engine.', 'image-watermark' );
		return $result;
	}

	/** @return void */
	public function render_persisted_auto_notices() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$records = get_user_meta( $user_id, '_iw_auto_operation_notices', true );
		$records = is_array( $records ) ? $records : [];
		$now     = time();
		$site_id = get_current_blog_id();
		$keep    = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['expires_at'] ) || (int) $record['expires_at'] < $now ) {
				continue;
			}
			// Records without a site ID predate site scoping and keep their behavior.
			$on_site = ! isset( $record['site_id'] ) || (int) $record['site_id'] === $site_id;
			if ( isset( $record['scope'] ) && $record['scope'] === 'unattributed' ) {
				if ( $on_site && isset( $record['site_id'] ) ) {
					$files = isset( $record['files'] ) && is_array( $record['files'] ) ? array_slice( array_filter( $record['files'], 'is_string' ), 0, 5 ) : [];
					// A record always names at least one file; a malformed one is dropped.
					if ( $files ) {
						/* translators: 1: Comma-separated uploaded file names. */
						$message = sprintf( __( 'Automatic watermarking was skipped for %1$s: Image Watermark could not confirm which attachment the upload became. Check the image and apply the watermark manually if needed.', 'image-watermark' ), implode( ', ', $files ) );
						echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
					}
					continue;
				}
				$keep[] = $record;
				continue;
			}
			$attachment_id = isset( $record['attachment_id'] ) ? (int) $record['attachment_id'] : 0;
			if ( $on_site && $attachment_id > 0 && current_user_can( 'edit_post', $attachment_id ) && ! empty( $record['message'] ) ) {
				echo '<div class="notice notice-' . esc_attr( isset( $record['type'] ) ? $record['type'] : 'warning' ) . ' is-dismissible"><p>' . esc_html( $record['message'] ) . '</p></div>';
				continue;
			}
			$keep[] = $record;
		}
		update_user_meta( $user_id, '_iw_auto_operation_notices', array_slice( $keep, -20 ) );
	}

	/** @return array */
	public function record_manual_precondition_failure( $attachment_id, $type, $code, $message ) {
		return $this->persist_terminal_outcome( (int) $attachment_id, $type, $type === 'remove' ? 'manual-remove' : 'manual-apply', 'failed', $code, $message, 'error' );
	}

	/**
	 * Install a test-only fault callback. The callback receives a stable boundary
	 * name and context and returns true to stop at that boundary.
	 *
	 * @internal Test-only fault injection seam.
	 * @param callable|null $injector Fault callback.
	 * @return void
	 */
	public function set_test_operation_fault_injector( $injector ) {
		$this->operation_fault_injector = is_callable( $injector ) ? $injector : null;
	}

	/**
	 * Handles uploads and registers metadata generation filter when needed.
	 *
	 * @param array $file
	 *
	 * @return array
	 */
	public function handle_upload_files( $file, $context = 'upload' ) {
		$token = null;
		$provenance = $this->capture_provenance_candidate( $file, $context );
		try {
			$token = $this->open_upload_claim( $file );
		} finally {
			// Exactly one bracket entry on every return path, so the finalizer of
			// this invocation always finds its own entry on top.
			$this->upload_brackets[] = [
				'depth'    => $this->current_filter_depth(),
				'token'    => $token,
				'site_id'  => get_current_blog_id(),
				'basename' => is_array( $file ) && ! empty( $file['file'] ) && is_string( $file['file'] ) ? sanitize_file_name( wp_basename( $file['file'] ) ) : '',
				'type'     => is_array( $file ) && isset( $file['type'] ) && is_string( $file['type'] ) ? $file['type'] : '',
				'provenance' => $provenance,
			];
		}

		return $file;
	}

	/**
	 * Provenance candidate of this upload invocation, captured at priority 10
	 * before the engine and automatic gates, so manual-only sites, engine-less
	 * requests, and the watermark image's own upload are covered too. A sideload
	 * qualifies only as the create request's own upload of a recognized
	 * client-side create; a client-side sideload phase never does. Only image
	 * uploads qualify, so other files cannot fill the candidate bound. Passive:
	 * any exception records nothing and leaves the upload untouched.
	 *
	 * @param mixed  $file Upload result at priority 10.
	 * @param string $context Upload context passed by wp_handle_upload.
	 * @return array|null
	 */
	private function capture_provenance_candidate( $file, $context ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['file'] ) || ! is_string( $file['file'] ) || strpos( $file['file'], "\0" ) !== false || ! isset( $file['type'] ) || ! is_string( $file['type'] ) || strpos( $file['type'], 'image/' ) !== 0 ) {
			return null;
		}
		try {
			$phase = $this->current_client_side_phase();
			if ( $phase !== null && $phase['phase'] === 'sideload' ) {
				return null;
			}
			$create_owned = $phase !== null && $phase['phase'] === 'create' && isset( $phase['filter_depth'] ) && $phase['filter_depth'] === $this->current_filter_depth();
			if ( $context !== 'upload' && ! ( $context === 'sideload' && $create_owned ) ) {
				return null;
			}
			$identity = $this->operation_path_identity( $file['file'], wp_upload_dir() );
		} catch ( \Throwable $error ) {
			return null;
		}
		if ( $identity === false ) {
			return null;
		}

		return [
			'identity' => $identity['identity'],
			'path'     => $identity['path'],
			'client'   => $create_owned,
			'request'  => $create_owned ? $phase['request'] : null,
		];
	}

	/**
	 * Bind a server upload's provenance candidate to the attachment whose
	 * creating metadata pass names it, before any watermark can change it.
	 * The candidate must match the attached file of an unscaled upload, or the
	 * original_image of a scaled or rotated one (the file core never changes).
	 * Exactly one candidate must match; every matched candidate is consumed, so
	 * a later attachment that references the same file gets no record. This is
	 * a byte observation, not proof of who created the attachment: every use
	 * re-hashes the file. Passive: any exception records nothing and returns
	 * the metadata unchanged. Called first by apply_upload_watermark(), which
	 * stays registered while a server candidate is pending.
	 *
	 * @param array  $data Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Metadata generation context.
	 * @return array Unchanged metadata.
	 */
	private function bind_upload_provenance( $data, $attachment_id, $context = '' ) {
		$attachment_id = (int) $attachment_id;
		if ( empty( $this->provenance_candidates ) ) {
			return $data;
		}
		try {
			// Only the creating pass records. Any other pass (an update, or a
			// legacy call without a context) still consumes the matching
			// candidates, because a watermark in that pass could change the file
			// before a later creating pass hashes it.
			$this->bind_server_provenance( $data, $attachment_id, $context === 'create' );
		} catch ( \Throwable $error ) {
			// Provenance is optional evidence; the attachment stays legacy. A
			// candidate left unconsumed could later bind changed bytes, so stop.
			$this->provenance_halted = true;
			$this->provenance_candidates = [];
		}

		return $data;
	}

	/**
	 * Match and bind one server provenance candidate (see bind_upload_provenance()).
	 *
	 * @param array $data Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @param bool  $record Whether this pass may write the record.
	 * @return void
	 */
	private function bind_server_provenance( $data, $attachment_id, $record ) {
		if ( $this->get_internal_operation_guard( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}
		$upload_dir = wp_upload_dir();
		$paths = $this->attachment_upload_paths( $data, $attachment_id );
		$attached = isset( $paths[0] ) ? $this->operation_path_identity( $paths[0], $upload_dir ) : false;
		$original = isset( $paths[1] ) ? $this->operation_path_identity( $paths[1], $upload_dir ) : false;
		$identities = array_filter( [ $attached === false ? null : $attached['identity'], $original === false ? null : $original['identity'] ] );
		if ( empty( $identities ) ) {
			return;
		}
		$site_id = get_current_blog_id();
		$matched = [];
		foreach ( $this->provenance_candidates as $index => $candidate ) {
			if ( empty( $candidate['client'] ) && $candidate['site_id'] === $site_id && in_array( $candidate['identity'], $identities, true ) ) {
				$matched[ $index ] = $candidate;
			}
		}
		if ( empty( $matched ) ) {
			return;
		}
		foreach ( array_keys( $matched ) as $index ) {
			unset( $this->provenance_candidates[ $index ] );
		}
		$this->provenance_candidates = array_values( $this->provenance_candidates );
		if ( ! $record || count( $matched ) !== 1 ) {
			return;
		}
		$candidate = reset( $matched );
		// The uploaded file is the original of a scaled or rotated main, and the
		// main itself only when there is no original. An original_image the stem
		// rule rejects is not "no original": the main is then of unknown origin.
		$declares_original = is_array( $data ) && isset( $data['original_image'] ) && $data['original_image'] !== '';
		if ( $declares_original && ! isset( $paths[1] ) ) {
			return;
		}
		$expected = isset( $paths[1] ) ? $original : $attached;
		if ( $expected !== false && $expected['identity'] === $candidate['identity'] ) {
			$this->write_provenance_record( $attachment_id, $candidate['path'], 'server-upload' );
		}
	}

	/**
	 * Write the provenance record once. A hash failure, an unreadable file, a
	 * record over the 1 KB serialized bound, a request where provenance halted
	 * (candidate overflow or an interrupted binding), or a failed meta write
	 * records nothing and never fails the upload.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $path Canonical path of the uploaded file.
	 * @param string $method server-upload or client-upload.
	 * @return bool Whether a record was written.
	 */
	private function write_provenance_record( $attachment_id, $path, $method ) {
		if ( $this->provenance_halted || ! is_file( $path ) || ! is_readable( $path ) || metadata_exists( 'post', $attachment_id, self::PROVENANCE_META_KEY ) ) {
			return false;
		}
		$size = @filesize( $path );
		$sha256 = @hash_file( 'sha256', $path );
		if ( $this->should_inject_operation_fault( 'provenance_record', [ 'attachment_id' => $attachment_id, 'path' => $path ] ) || ! is_int( $size ) || $size < 1 || ! is_string( $sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return false;
		}
		$record = [
			'version' => self::PROVENANCE_VERSION,
			'file'    => wp_basename( $path ),
			'size'    => $size,
			'sha256'  => $sha256,
			'method'  => $method,
			'time'    => time(),
		];
		if ( strlen( serialize( $record ) ) > self::PROVENANCE_MAX_BYTES ) {
			return false;
		}

		return (bool) add_post_meta( $attachment_id, self::PROVENANCE_META_KEY, $record, true );
	}

	/**
	 * Decide whether an upload may own an automatic watermark and open its
	 * provisional, unsealed claim.
	 *
	 * @param mixed $file Upload result at priority 10.
	 * @return string|null Claim token, or null when no claim was opened.
	 */
	private function open_upload_claim( $file ) {
		if ( ! $this->ensure_image_engine() ) {
			return null;
		}

		$is_admin = $this->is_admin_upload_request();

		$upload_context = isset( $_REQUEST['iw_watermark_upload'] ) && is_string( $_REQUEST['iw_watermark_upload'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['iw_watermark_upload'] ) ) : '';

		if ( $upload_context === '1' ) {
			return null;
		}

		$phase = $this->current_client_side_phase();

		// Browser-generated derivatives are processed by the finalize request that
		// registers them; a sideloaded file never owns an automatic callback.
		if ( $phase !== null && $phase['phase'] === 'sideload' ) {
			return null;
		}

		if ( ! is_array( $file ) || ! isset( $file['type'] ) || ! is_string( $file['type'] ) || ! empty( $file['error'] ) || ! $this->is_automatic_upload_enabled( $file['type'], $is_admin ) ) {
			return null;
		}

		// Only the create request's own upload, at the filter depth recorded when
		// its REST callback began, is deferred to finalize. A nested upload (for
		// example from another upload filter) keeps the conventional path.
		$depth = $this->current_filter_depth();
		$client_side_request = $phase !== null && $phase['phase'] === 'create' && isset( $phase['filter_depth'] ) && $phase['filter_depth'] === $depth ? $phase['request'] : null;

		$token = wp_generate_password( 20, false, false );
		$this->pending_auto_uploads[ $token ] = [
			'sealed'   => false,
			'is_admin' => (bool) $is_admin,
			'site_id'  => get_current_blog_id(),
			'basename' => ! empty( $file['file'] ) && is_string( $file['file'] ) ? sanitize_file_name( wp_basename( $file['file'] ) ) : '',
			'request'  => $client_side_request,
		];

		return $token;
	}

	/**
	 * Number of hook invocations currently running, measured on the PHP call
	 * stack. $wp_current_filter is not used: apply_filters() skips its
	 * array_pop() when a callback throws, so after a caught exception that
	 * count stays inflated for the rest of the request and a stale bracket
	 * entry could share a later invocation's depth.
	 *
	 * @return int
	 */
	private function current_filter_depth() {
		$depth = 0;
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( isset( $frame['class'], $frame['function'] ) && $frame['class'] === 'WP_Hook' && $frame['function'] === 'apply_filters' ) {
				$depth++;
			}
		}

		return $depth;
	}

	/**
	 * Internal entry guard at PHP_INT_MIN on wp_handle_upload. Any bracket entry
	 * at this invocation's depth or deeper is stale (left by a caught exception),
	 * because a live enclosing invocation is always shallower.
	 *
	 * @param mixed $file Upload result.
	 * @return mixed Unchanged upload result.
	 */
	public function enter_upload_bracket( $file ) {
		$this->discard_upload_brackets( $this->current_filter_depth(), true );
		return $file;
	}

	/**
	 * Internal finalizer at PHP_INT_MAX on wp_handle_upload. Seals this
	 * invocation's claim with the final file and type. It never changes $file.
	 *
	 * @param mixed $file Final upload result.
	 * @return mixed Unchanged upload result.
	 */
	public function seal_upload_bracket( $file ) {
		$depth = $this->current_filter_depth();
		$this->discard_upload_brackets( $depth, false );
		$usable = is_array( $file ) && empty( $file['error'] ) && ! empty( $file['file'] ) && is_string( $file['file'] ) && strpos( $file['file'], "\0" ) === false;
		$raw = $usable ? wp_normalize_path( $file['file'] ) : '';
		$identity = $usable ? $this->operation_path_identity( $file['file'], wp_upload_dir() ) : false;
		$this->upload_sequence++;

		// Keep recent sealed paths even when no claim opened, so their attachments
		// are recognized without growing the ledger throughout a long import.
		if ( $usable ) {
			$this->sealed_upload_paths[] = [
				'file'     => $raw,
				'identity' => $identity === false ? null : $identity['identity'],
				'site_id'  => get_current_blog_id(),
				'sequence' => $this->upload_sequence,
			];
			$this->sealed_upload_paths = array_slice( $this->sealed_upload_paths, -self::UPLOAD_LEDGER_LIMIT );
		}

		$count = count( $this->upload_brackets );
		// A shallower top entry belongs to an enclosing upload whose opening
		// callback ran; this upload opened none (its callback was removed).
		if ( $count === 0 || $this->upload_brackets[ $count - 1 ]['depth'] !== $depth ) {
			return $file;
		}

		$entry = array_pop( $this->upload_brackets );
		// A later callback that moved or renamed the file leaves no candidate:
		// only the file the priority-10 callback saw is ever bound.
		if ( ! $this->provenance_halted && $usable && $identity !== false && ! empty( $entry['provenance'] ) && $entry['provenance']['identity'] === $identity['identity'] ) {
			$this->provenance_candidates[] = array_merge( $entry['provenance'], [ 'site_id' => get_current_blog_id(), 'sequence' => $this->upload_sequence ] );
			if ( count( $this->provenance_candidates ) > self::UPLOAD_LEDGER_LIMIT ) {
				// The evicted upload could still be named by a later attachment, so
				// a single remaining match no longer proves an unambiguous binding.
				$this->provenance_halted = true;
				$this->provenance_candidates = [];
			} elseif ( empty( $entry['provenance']['client'] ) ) {
				// The metadata callback binds server candidates before it watermarks.
				add_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10, 3 );
			}
		}
		$token = $entry['token'];
		if ( $token === null || ! isset( $this->pending_auto_uploads[ $token ] ) ) {
			return $file;
		}
		if ( ! $usable ) {
			unset( $this->pending_auto_uploads[ $token ] );
			return $file;
		}

		$claim = $this->pending_auto_uploads[ $token ];
		$final_type = isset( $file['type'] ) && is_string( $file['type'] ) ? $file['type'] : '';
		$claim['sealed'] = true;
		$claim['file'] = $raw;
		$claim['identity'] = $identity === false ? null : $identity['identity'];
		$claim['type'] = $final_type;
		$claim['code'] = in_array( $final_type, $this->plugin->get_allowed_mime_types(), true ) ? '' : 'upload_type_changed_unsupported';
		$claim['sequence'] = $this->upload_sequence;

		// The client-side create upload becomes the finalize candidate only when
		// its final file is a supported, resolvable image; otherwise its outcome
		// is attributed through the conventional metadata callback.
		if ( $claim['request'] !== null && $claim['code'] === '' && $identity !== false ) {
			unset( $this->pending_auto_uploads[ $token ] );
			$this->client_side_candidates[] = [
				'file'     => $identity['path'],
				'is_admin' => $claim['is_admin'],
				'request'  => $claim['request'],
				'site_id'  => $claim['site_id'],
				'basename' => $claim['basename'],
				'sequence' => $claim['sequence'],
			];
			return $file;
		}

		$this->pending_auto_uploads[ $token ] = $claim;
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10, 3 );
		if ( ! $this->upload_residue_scheduled ) {
			$this->upload_residue_scheduled = true;
			add_action( 'shutdown', [ $this, 'report_unattributed_upload_residue' ] );
		}

		return $file;
	}

	/**
	 * Drop bracket entries at (inclusive) or below the given depth together with
	 * their unsealed claims.
	 *
	 * @param int  $depth Filter depth.
	 * @param bool $inclusive Whether entries at exactly this depth are stale.
	 * @return void
	 */
	private function discard_upload_brackets( $depth, $inclusive ) {
		while ( ! empty( $this->upload_brackets ) ) {
			$top = $this->upload_brackets[ count( $this->upload_brackets ) - 1 ];
			if ( $top['depth'] < $depth || ( ! $inclusive && $top['depth'] === $depth ) ) {
				return;
			}
			array_pop( $this->upload_brackets );
			if ( $top['token'] !== null && isset( $this->pending_auto_uploads[ $top['token'] ] ) && empty( $this->pending_auto_uploads[ $top['token'] ]['sealed'] ) ) {
				unset( $this->pending_auto_uploads[ $top['token'] ] );
			}
		}
	}

	/**
	 * Record each attachment inserted in this request for the residue check.
	 * Only the ID and site are kept; paths are read when the check runs.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function observe_inserted_attachment( $attachment_id ) {
		if ( empty( $this->client_side_candidates ) && ! $this->has_sealed_upload_claims() ) {
			return;
		}
		$this->upload_sequence++;
		$this->inserted_attachments[] = [
			'id'       => (int) $attachment_id,
			'site_id'  => get_current_blog_id(),
			'sequence' => $this->upload_sequence,
		];
		if ( count( $this->inserted_attachments ) >= self::UPLOAD_LEDGER_LIMIT ) {
			$this->trim_upload_ledger();
		}
	}

	/**
	 * Keep the ledger bounded in long-running processes. A claim that is never
	 * consumed (for example an importer whose remote fetch failed after
	 * wp_upload_bits()) would otherwise keep it growing: claims sealed more than
	 * UPLOAD_LEDGER_LIMIT events ago are evaluated now, and entries older than
	 * every remaining claim are dropped. The current upload's claim is recent,
	 * so it is never evaluated before its own metadata callback.
	 *
	 * @return void
	 */
	private function trim_upload_ledger() {
		$cutoff = $this->upload_sequence - self::UPLOAD_LEDGER_LIMIT;
		$stale = [];
		foreach ( $this->pending_auto_uploads as $token => $claim ) {
			if ( ! empty( $claim['sealed'] ) && $claim['sequence'] < $cutoff ) {
				$stale[] = $claim;
				unset( $this->pending_auto_uploads[ $token ] );
			}
		}
		$this->queue_unattributed_upload_notices( $stale );

		$oldest = PHP_INT_MAX;
		foreach ( array_merge( array_values( $this->pending_auto_uploads ), $this->client_side_candidates ) as $claim ) {
			if ( isset( $claim['sequence'] ) ) {
				$oldest = min( $oldest, (int) $claim['sequence'] );
			}
		}
		$this->inserted_attachments = array_values( array_filter( $this->inserted_attachments, function( $inserted ) use ( $oldest ) {
			return $inserted['sequence'] > $oldest;
		} ) );
		$this->sealed_upload_paths = array_values( array_filter( $this->sealed_upload_paths, function( $sealed ) use ( $cutoff ) {
			return $sealed['sequence'] >= $cutoff;
		} ) );
		$this->prune_upload_ledger();
	}

	/**
	 * Server residue check at shutdown: sealed eligible claims that no
	 * attachment consumed.
	 *
	 * @return void
	 */
	public function report_unattributed_upload_residue() {
		$claims = [];
		foreach ( $this->pending_auto_uploads as $token => $claim ) {
			if ( ! empty( $claim['sealed'] ) ) {
				$claims[] = $claim;
				unset( $this->pending_auto_uploads[ $token ] );
			}
		}
		$this->queue_unattributed_upload_notices( $claims );
	}

	/**
	 * Queue an unattributed notice for each site where an unconsumed sealed claim
	 * is followed by an attachment inserted after its seal that consumed no
	 * claim and matches no sealed upload path. No attachment record is written
	 * and no ownership is inferred.
	 *
	 * @param array[] $claims Unconsumed sealed claims or client candidates.
	 * @return void
	 */
	private function queue_unattributed_upload_notices( $claims ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || empty( $claims ) ) {
			return;
		}

		$files_by_site = [];
		$upload_dir = null;
		$matches = [];
		foreach ( $claims as $claim ) {
			$site_id = (int) $claim['site_id'];
			foreach ( $this->inserted_attachments as $inserted ) {
				// Paths are read on the current site only; a claim left on another
				// site after switch_to_blog() is not evaluated. An attachment that
				// consumed a claim always matches its own sealed path.
				if ( $site_id !== get_current_blog_id() || $inserted['site_id'] !== $site_id || $inserted['sequence'] <= $claim['sequence'] ) {
					continue;
				}
				$key = $site_id . ':' . $inserted['id'];
				if ( ! isset( $matches[ $key ] ) ) {
					if ( $upload_dir === null ) {
						$upload_dir = wp_upload_dir();
					}
					$matches[ $key ] = $this->attachment_matches_sealed_upload( $inserted['id'], $site_id, $upload_dir );
				}
				if ( $matches[ $key ] ) {
					continue;
				}
				$files_by_site[ $site_id ][] = $claim['basename'] !== '' ? $claim['basename'] : sanitize_file_name( wp_basename( $claim['file'] ) );
				break;
			}
		}

		foreach ( $files_by_site as $site_id => $files ) {
			$files = array_slice( array_values( array_unique( array_filter( $files ) ) ), 0, 5 );
			$records = get_user_meta( $user_id, '_iw_auto_operation_notices', true );
			$records = is_array( $records ) ? $records : [];
			$records[] = [
				'scope'      => 'unattributed',
				'site_id'    => (int) $site_id,
				'code'       => 'upload_unattributed',
				'files'      => array_map( function( $name ) {
					return substr( sanitize_file_name( $name ), 0, 100 );
				}, $files ),
				'type'       => 'warning',
				'created_at' => time(),
				'expires_at' => time() + DAY_IN_SECONDS,
			];
			update_user_meta( $user_id, '_iw_auto_operation_notices', array_slice( $records, -20 ) );
		}
	}

	/**
	 * Whether an attachment's current files match any sealed upload path of this
	 * request, by canonical identity, or by exact raw path when either side has
	 * no identity.
	 *
	 * @return bool
	 */
	private function attachment_matches_sealed_upload( $attachment_id, $site_id, $upload_dir ) {
		$data = wp_get_attachment_metadata( $attachment_id, true );
		$paths = $this->attachment_upload_paths( $data, $attachment_id );
		$attached = get_attached_file( $attachment_id );
		$raw_original = is_string( $attached ) && $attached !== '' ? $this->raw_original_upload_path( $data, $attachment_id, wp_normalize_path( $attached ) ) : false;
		if ( $raw_original !== false ) {
			$paths[] = $raw_original;
		}
		foreach ( $paths as $path ) {
			$identity = $this->operation_path_identity( $path, $upload_dir );
			foreach ( $this->sealed_upload_paths as $sealed ) {
				if ( $sealed['site_id'] !== $site_id ) {
					continue;
				}
				if ( $identity !== false && $sealed['identity'] !== null ? $identity['identity'] === $sealed['identity'] : $this->paths_match( $sealed['file'], wp_normalize_path( $path ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Make sure an image engine is selected, detecting one when needed.
	 *
	 * @return bool
	 */
	private function ensure_image_engine() {
		if ( ! $this->plugin->get_extension() ) {
			$this->plugin->check_extensions();
		}

		return (bool) $this->plugin->get_extension();
	}

	/**
	 * Shared automatic-upload gate for conventional and client-side uploads:
	 * engine, admin plugin_off versus frontend frontend_active, allowed MIME type,
	 * and a usable image or text watermark configuration.
	 *
	 * @param string $mime_type Upload MIME type.
	 * @param bool   $is_admin Whether admin automatic rules apply.
	 * @return bool
	 */
	private function is_automatic_upload_enabled( $mime_type, $is_admin ) {
		if ( ! $this->ensure_image_engine() ) {
			return false;
		}

		$options = $this->plugin->options;
		$flag = $is_admin ? 'plugin_off' : 'frontend_active';

		if ( ! isset( $options['watermark_image'][ $flag ] ) || $options['watermark_image'][ $flag ] != 1 || ! in_array( $mime_type, $this->plugin->get_allowed_mime_types(), true ) ) {
			return false;
		}

		$watermark_type = isset( $options['watermark_image']['type'] ) ? $options['watermark_image']['type'] : 'image';

		if ( $watermark_type === 'image' ) {
			return (bool) wp_attachment_is_image( $options['watermark_image']['url'] );
		}

		if ( $watermark_type === 'text' ) {
			$text_string = isset( $options['watermark_image']['text_string'] ) ? trim( $options['watermark_image']['text_string'] ) : '';
			if ( empty( $text_string ) ) {
				return false;
			}

			// Validate font availability
			$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
			$font_path = $this->plugin->get_font_path( $font );
			$available = $font_path && file_exists( $font_path );
			if ( ! $available && $is_admin ) {
				$this->maybe_add_missing_font_notice( $font );
			}

			return (bool) $available;
		}

		return false;
	}

	/**
	 * Apply an automatic watermark only for the upload whose sealed claim this
	 * attachment matches. The claim is removed before processing so a failure,
	 * nested call, or later regeneration cannot apply it again. A claim whose
	 * final file is unsupported or unresolvable records a skipped outcome on the
	 * matching attachment instead.
	 *
	 * Upload provenance is bound first, so an unscaled main is hashed before
	 * the watermark changes it.
	 *
	 * @param array  $data Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Metadata generation context.
	 * @return array
	 */
	public function apply_upload_watermark( $data, $attachment_id, $context = '' ) {
		$attachment_id = (int) $attachment_id;
		// Writing the record can start an inner metadata dispatch, which must not
		// retire this callback while it runs.
		$this->upload_callback_depth++;
		try {
			$data = $this->bind_upload_provenance( $data, $attachment_id, $context );
		} finally {
			$this->upload_callback_depth--;
		}
		if ( $this->get_internal_operation_guard( $attachment_id ) ) {
			return $data;
		}
		if ( ! $this->has_sealed_upload_claims() ) {
			// Registered for a provenance candidate only, or left registered
			// because removing it earlier was unsafe.
			$this->retire_upload_callback();
			return $data;
		}

		$match = $this->find_pending_auto_upload( $data, $attachment_id );
		if ( $match === false ) {
			return $data;
		}

		$pending = $this->pending_auto_uploads[ $match['token'] ];
		unset( $this->pending_auto_uploads[ $match['token'] ] );
		$previous_is_admin = $this->is_admin;
		$this->is_admin = $pending['is_admin'];
		$this->upload_callback_depth++;
		$aborted = false;

		try {
			if ( $pending['code'] === 'upload_type_changed_unsupported' ) {
				$this->persist_terminal_outcome( $attachment_id, 'apply', 'auto-apply', 'skipped', $pending['code'], __( 'Automatic watermarking was skipped because another plugin changed the upload to a file type Image Watermark does not support.', 'image-watermark' ) );
				return $data;
			}
			// A client-side create upload is deferred to finalize only with a
			// canonical identity, and is never watermarked on this create pass.
			if ( $match['raw'] && ( $pending['request'] !== null || ! $this->is_resolvable_upload_file( get_attached_file( $attachment_id ) ) ) ) {
				$this->persist_terminal_outcome( $attachment_id, 'apply', 'auto-apply', 'skipped', 'upload_path_unresolved', __( 'Automatic watermarking was skipped because Image Watermark could not resolve the uploaded file inside the uploads folder.', 'image-watermark' ) );
				return $data;
			}
			$dispatch = $this->dispatch_operation_with_capture( 'apply', $attachment_id, [ 'metadata' => $data, 'method' => '' ], [], $rendered );
			$result = $dispatch['legacy_result'];
			// The upload's own pass, identified by this dispatch: its observer and
			// the save that follows raise no signal.
			if ( isset( $rendered['outcome'], $rendered['operation_id'] ) && $rendered['outcome'] === 'complete' && $this->is_owned_handoff_pending( $attachment_id, $rendered['operation_id'] ) ) {
				$this->owned_metadata_passes[ get_current_blog_id() . ':' . $attachment_id ] = [ 'depth' => $this->current_filter_depth(), 'count' => did_filter( 'wp_generate_attachment_metadata' ), 'nested' => 0 ];
			}
			return $result;
		} catch ( \Throwable $error ) {
			// The dispatch is aborting, so no later group of it can run; remove
			// the callback now rather than leave it for a later dispatch.
			$aborted = true;
			throw $error;
		} finally {
			$this->is_admin = $previous_is_admin;
			$this->upload_callback_depth--;
			if ( $aborted && $this->upload_callback_depth === 0 && ! $this->has_pending_upload_metadata_work() ) {
				remove_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10 );
				remove_filter( 'wp_generate_attachment_metadata', [ $this, 'retire_deferred_upload_callback' ], PHP_INT_MAX );
			} else {
				$this->retire_upload_callback();
			}
			$this->prune_upload_ledger();
		}
	}

	/**
	 * Remove the automatic watermark callback once no sealed claim or server
	 * provenance candidate remains, without making WordPress skip other
	 * metadata callbacks. Removing the last
	 * callback of the running priority 10 makes WP_Hook re-sort the iteration;
	 * when a lower priority remains, the re-sort lands on the next priority
	 * group and the loop then steps past it, so that group never runs. Only
	 * when a lower and a higher priority both exist is the removal moved to the
	 * end of the same dispatch (priority PHP_INT_MAX), where removing priority
	 * 10 leaves the running position intact.
	 *
	 * @return void
	 */
	private function retire_upload_callback() {
		// While an outer automatic callback still processes an upload, its own
		// retirement decides; an inner dispatch never removes it.
		if ( $this->upload_callback_depth > 0 || $this->has_pending_upload_metadata_work() ) {
			return;
		}
		if ( ! $this->can_remove_upload_callback_now() ) {
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'retire_deferred_upload_callback' ], PHP_INT_MAX );
			return;
		}
		remove_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10 );
	}

	/**
	 * Whether removing the automatic callback now leaves every running
	 * iteration of the metadata filter intact: the filter is idle, another
	 * callback keeps priority 10 occupied, or priority 10 is not between two
	 * registered priorities (with no lower one WP_Hook keeps the position; with
	 * no higher one there is no group left to skip).
	 *
	 * @return bool
	 */
	private function can_remove_upload_callback_now() {
		global $wp_filter;
		if ( ! doing_filter( 'wp_generate_attachment_metadata' ) || ! isset( $wp_filter['wp_generate_attachment_metadata'] ) || ! $wp_filter['wp_generate_attachment_metadata'] instanceof WP_Hook || has_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ] ) !== 10 ) {
			return true;
		}
		$callbacks = $wp_filter['wp_generate_attachment_metadata']->callbacks;
		if ( isset( $callbacks[10] ) && count( $callbacks[10] ) > 1 ) {
			return true;
		}
		$lower = false;
		$higher = false;
		foreach ( array_keys( $callbacks ) as $priority ) {
			$lower = $lower || $priority < 10;
			$higher = $higher || $priority > 10;
		}

		return ! ( $lower && $higher );
	}

	/**
	 * Deferred removal of the automatic callback (see retire_upload_callback()),
	 * at the end of the metadata dispatch. No running iteration is then at the
	 * automatic callback, unless it is still processing an upload; a later
	 * evaluation retires it in that case. Removing this callback from the last
	 * priority cannot skip a later group.
	 *
	 * @param array $data Attachment metadata.
	 * @return array Unchanged metadata.
	 */
	public function retire_deferred_upload_callback( $data ) {
		if ( $this->upload_callback_depth > 0 ) {
			return $data;
		}
		if ( ! $this->has_pending_upload_metadata_work() ) {
			remove_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10 );
		}
		remove_filter( 'wp_generate_attachment_metadata', [ $this, 'retire_deferred_upload_callback' ], PHP_INT_MAX );
		return $data;
	}

	/**
	 * Raw form of the untouched original upload of a scaled or rotated main
	 * file. It carries only the trust of the raw attached-file match, plus the
	 * canonical original_image rules of attachment_upload_paths(): the attached
	 * file matches the metadata file (including its basename), original_image
	 * is a bare basename, the -scaled/-rotated stem rule holds against the
	 * metadata file, and the directory is the attached file's own. It matters
	 * only where paths cannot be canonicalized, such as a stream-wrapper
	 * uploads directory.
	 *
	 * @param mixed  $data Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $attached Normalized raw attached file.
	 * @return string|false
	 */
	private function raw_original_upload_path( $data, $attachment_id, $attached ) {
		if ( $attached === '' || ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) || empty( $data['original_image'] ) || ! is_string( $data['original_image'] ) ) {
			return false;
		}
		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $relative ) || $relative === '' || ! $this->paths_match( wp_normalize_path( $relative ), wp_normalize_path( $data['file'] ) ) ) {
			return false;
		}
		$original = $data['original_image'];
		if ( basename( $original ) !== $original || strpos( $original, '..' ) !== false || strpos( $original, ':' ) !== false || strpos( $original, "\0" ) !== false ) {
			return false;
		}
		$metadata_file = basename( wp_normalize_path( $data['file'] ) );
		if ( ! $this->paths_match( basename( $attached ), $metadata_file ) ) {
			return false;
		}
		$original_stem = strtolower( pathinfo( $original, PATHINFO_FILENAME ) );
		$file_stem = strtolower( pathinfo( $metadata_file, PATHINFO_FILENAME ) );
		if ( $original_stem === '' || ! preg_match( '/^' . preg_quote( $original_stem, '/' ) . '-(?:scaled|rotated|scaled-rotated)$/', $file_stem ) ) {
			return false;
		}

		return dirname( $attached ) . '/' . $original;
	}

	/** @return bool Whether a sealed claim or a server provenance candidate still waits for its metadata pass. */
	private function has_pending_upload_metadata_work() {
		if ( $this->has_sealed_upload_claims() ) {
			return true;
		}
		if ( $this->provenance_halted ) {
			return false;
		}
		foreach ( $this->provenance_candidates as $candidate ) {
			if ( empty( $candidate['client'] ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return bool Whether a sealed claim still waits for its attachment. */
	private function has_sealed_upload_claims() {
		foreach ( $this->pending_auto_uploads as $claim ) {
			if ( ! empty( $claim['sealed'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * With no sealed claim or client candidate left, no earlier insert can ever
	 * meet the residue trigger, so long-running processes do not accumulate the
	 * ledger. Sealed paths are cleared too; that can only add a notice when an
	 * upload's attachment is inserted after a later claim was sealed and left
	 * unconsumed, with a full prune in between.
	 *
	 * @return void
	 */
	private function prune_upload_ledger() {
		if ( empty( $this->client_side_candidates ) && ! $this->has_sealed_upload_claims() ) {
			$this->inserted_attachments = [];
			$this->sealed_upload_paths = [];
		}
	}

	/**
	 * A raw-matched attachment file must exist under the uploads base directory.
	 *
	 * @param mixed $path Attached file path.
	 * @return bool
	 */
	private function is_resolvable_upload_file( $path ) {
		if ( ! is_string( $path ) || $path === '' || strpos( $path, "\0" ) !== false || ! is_file( $path ) ) {
			return false;
		}
		$upload_dir = wp_upload_dir();
		$base = rtrim( wp_normalize_path( $upload_dir['basedir'] ), '/' ) . '/';
		$path = wp_normalize_path( $path );

		return DIRECTORY_SEPARATOR === '\\' ? stripos( $path, $base ) === 0 : strpos( $path, $base ) === 0;
	}

	/**
	 * Find the sealed claim belonging to an attachment metadata callback. A claim
	 * with a canonical identity matches any canonical attachment candidate; a
	 * claim without one matches only the raw attached file exactly.
	 *
	 * @param array $data Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array{token:string,raw:bool}|false
	 */
	private function find_pending_auto_upload( $data, $attachment_id ) {
		$paths = $this->attachment_upload_paths( $data, $attachment_id );

		if ( empty( $paths ) ) {
			return false;
		}
		$upload_dir = wp_upload_dir();
		$identities = [];
		foreach ( $paths as $candidate ) {
			$identity = $this->operation_path_identity( $candidate, $upload_dir );
			if ( $identity !== false ) {
				$identities[] = $identity['identity'];
			}
		}
		$attached = get_attached_file( $attachment_id );
		$attached = is_string( $attached ) && $attached !== '' ? wp_normalize_path( $attached ) : '';
		$raw_original = $this->raw_original_upload_path( $data, $attachment_id, $attached );
		$site_id = get_current_blog_id();

		foreach ( $this->pending_auto_uploads as $token => $pending ) {
			if ( empty( $pending['sealed'] ) || $pending['site_id'] !== $site_id ) {
				continue;
			}
			if ( $pending['identity'] !== null ) {
				if ( in_array( $pending['identity'], $identities, true ) ) {
					return [ 'token' => $token, 'raw' => false ];
				}
			} elseif ( $attached !== '' && ( $this->paths_match( $pending['file'], $attached ) || ( $raw_original !== false && $this->paths_match( $pending['file'], $raw_original ) ) ) ) {
				return [ 'token' => $token, 'raw' => true ];
			}
		}

		return false;
	}

	/**
	 * Return the upload paths an attachment metadata callback owns: its attached
	 * file and, for a scaled or rotated main file, the untouched original upload.
	 *
	 * @param array $data Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return string[]
	 */
	private function attachment_upload_paths( $data, $attachment_id ) {
		$paths = [];
		$metadata_path = false;
		$upload_dir = wp_upload_dir();
		if ( is_array( $data ) && ! empty( $data['file'] ) && is_string( $data['file'] ) ) {
			$metadata_path = $this->pending_metadata_file_path( $data['file'], $upload_dir );
		}
		$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$attached_file_matches_metadata = is_string( $attached_file ) && $attached_file !== '' && is_array( $data ) && ! empty( $data['file'] ) && is_string( $data['file'] ) && $this->paths_match( wp_normalize_path( $attached_file ), wp_normalize_path( $data['file'] ) );
		$path = get_attached_file( $attachment_id );
		if ( ( ! is_string( $path ) || $path === '' ) && $attached_file_matches_metadata ) {
			$path = $metadata_path;
		}
		if ( is_string( $path ) && $path !== '' ) {
			$paths[] = wp_normalize_path( $path );
		}
		if ( $metadata_path !== false && $attached_file_matches_metadata && is_array( $data ) && ! empty( $data['original_image'] ) && is_string( $data['original_image'] ) && basename( $data['original_image'] ) === $data['original_image'] && strpos( $data['original_image'], '..' ) === false && strpos( $data['original_image'], ':' ) === false && strpos( $data['original_image'], "\0" ) === false ) {
			$original_stem = strtolower( pathinfo( $data['original_image'], PATHINFO_FILENAME ) );
			$file_stem = strtolower( pathinfo( basename( $data['file'] ), PATHINFO_FILENAME ) );
			$original_path = wp_normalize_path( dirname( $metadata_path ) . DIRECTORY_SEPARATOR . $data['original_image'] );
			if ( $original_stem !== '' && preg_match( '/^' . preg_quote( $original_stem, '/' ) . '-(?:scaled|rotated|scaled-rotated)$/', $file_stem ) && $this->paths_match( dirname( $metadata_path ), dirname( $original_path ) ) ) {
				$paths[] = $original_path;
			}
		}

		return $paths;
	}

	/** @return string|false */
	private function pending_metadata_file_path( $file, $upload_dir ) {
		if ( ! is_string( $file ) || $file === '' || strpos( $file, "\0" ) !== false || strpos( $file, '..' ) !== false || strpos( $file, ':' ) !== false ) {
			return false;
		}
		$file = wp_normalize_path( $file );
		if ( strpos( $file, '/' ) === 0 || strpos( $file, '//' ) !== false || basename( $file ) === '' || basename( $file ) === '.' ) {
			return false;
		}
		$directory = dirname( $file );
		if ( $directory !== '.' && ( strpos( $directory, '..' ) !== false || strpos( $directory, ':' ) !== false || strpos( $directory, "\0" ) !== false ) ) {
			return false;
		}
		$base = realpath( $upload_dir['basedir'] );
		if ( $base === false ) {
			return false;
		}
		$candidate = wp_normalize_path( rtrim( $base, '/' ) . '/' . $file );
		if ( is_file( $candidate ) ) {
			$identity = $this->operation_path_identity( $candidate, $upload_dir );
			return $identity === false ? false : $identity['path'];
		}
		$normalized_base = rtrim( wp_normalize_path( $base ), '/' );
		$compare_base = DIRECTORY_SEPARATOR === '\\' ? strtolower( $normalized_base ) : $normalized_base;
		$compare_candidate = DIRECTORY_SEPARATOR === '\\' ? strtolower( $candidate ) : $candidate;
		return strpos( $compare_candidate, $compare_base . '/' ) === 0 ? $candidate : false;
	}

	/**
	 * Compare upload paths without changing path ownership semantics.
	 *
	 * @param string $first First path.
	 * @param string $second Second path.
	 * @return bool
	 */
	private function paths_match( $first, $second ) {
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			return strcasecmp( $first, $second ) === 0;
		}

		return $first === $second;
	}

	/**
	 * Recognize the exact WordPress 7.1 client-side media REST phases before
	 * their callback runs. This filter runs before the route permission check,
	 * so it only records the phase; decisions with side effects wait for
	 * gate_client_side_media_request(). Other requests, and WordPress versions
	 * without the sideload/finalize routes, pass through untouched.
	 *
	 * @param mixed           $response Current response or error.
	 * @param array           $handler Matched route handler.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function begin_client_side_media_request( $response, $handler, $request ) {
		$phase = $this->recognize_client_side_media_phase( $handler, $request );
		if ( $phase === null ) {
			return $response;
		}

		$phase['dispatched'] = false;
		$phase['withheld'] = null;
		// The REST callback runs outside any filter, so the create request's own
		// upload runs wp_handle_upload at exactly this depth.
		$phase['filter_depth'] = $this->current_filter_depth();
		if ( $phase['phase'] === 'sideload' ) {
			// A 'scaled' or 'original' sideload replaces the attached file; the
			// after-callback extends the claim's ownership chain to the new file.
			$phase['attached_before'] = get_post_meta( $phase['attachment_id'], '_wp_attached_file', true );
		}
		$this->client_side_phases[] = $phase;
		if ( $phase['phase'] === 'finalize' ) {
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_client_side_finalize_watermark' ], 10, 3 );
			add_filter( 'wp_generate_attachment_metadata', [ $this, 'capture_client_side_finalize_metadata' ], PHP_INT_MAX, 3 );
			add_filter( 'update_post_metadata', [ $this, 'withhold_client_side_metadata_write' ], PHP_INT_MAX, 3 );
		}

		return $response;
	}

	/**
	 * Gate a recognized sideload or finalize after WordPress has authorized it
	 * and before its callback writes anything. A non-null result replaces the
	 * callback, so a refused request leaves files and metadata untouched.
	 *
	 * @param mixed           $result Dispatch result.
	 * @param WP_REST_Request $request Request.
	 * @param string          $route Matched route.
	 * @param array           $handler Matched route handler.
	 * @return mixed
	 */
	public function gate_client_side_media_request( $result, $request, $route = '', $handler = [] ) {
		$index = $this->client_side_phase_index( $request );
		if ( $result !== null || $index === false ) {
			return $result;
		}

		$this->client_side_phases[ $index ]['dispatched'] = true;
		$phase = $this->client_side_phases[ $index ];
		$attachment_id = $phase['attachment_id'];

		if ( $phase['phase'] === 'sideload' ) {
			if ( $this->is_attachment_watermarked( $attachment_id, true ) ) {
				return $this->refuse_client_side_request( $index, 'iw_client_side_sideload_withheld', __( 'This image is already watermarked, so a new unwatermarked file cannot be added to it. Re-upload the image instead.', 'image-watermark' ), false );
			}
			if ( in_array( $request['image_size'], [ 'scaled', 'original' ], true ) && $this->is_operation_lease_held( $attachment_id ) ) {
				return $this->refuse_client_side_request( $index, 'iw_client_side_sideload_busy', __( 'Another watermark operation is running on this image. Please retry shortly.', 'image-watermark' ), true );
			}
		} elseif ( $phase['phase'] === 'finalize' ) {
			$sub_sizes = $this->client_side_request_sub_sizes( $request );
			$row = $this->read_client_side_claim_row( $attachment_id );
			$rendered = is_array( $row ) && $row['claim']['state'] === 'rendered';
			if ( ! $rendered && $this->is_attachment_watermarked( $attachment_id, true ) ) {
				$stored   = wp_get_attachment_metadata( $attachment_id, true );
				$context  = $this->client_side_reference_context( $stored );
				$proposal = $this->client_side_sub_size_reference_proposal( $context['map'], $sub_sizes );
				if ( ! $this->client_side_reference_proposal_is_idempotent( $context, $proposal ) ) {
					$this->settle_withheld_client_side_finalize( $attachment_id );
					return $this->refuse_client_side_request( $index, 'iw_client_side_finalize_withheld', $this->client_side_withheld_message(), false );
				}
			}
			if ( $this->is_operation_lease_held( $attachment_id ) ) {
				$this->defer_busy_client_side_finalize( $attachment_id, $sub_sizes );
				return $this->refuse_client_side_request( $index, 'iw_client_side_finalize_busy', $this->client_side_busy_message(), true );
			}
		}

		return $result;
	}

	/**
	 * Close the phase opened for this exact request, report a withheld
	 * finalize truthfully, consume a claim whose finalized metadata WordPress
	 * persisted, and remove temporary callbacks.
	 *
	 * @param mixed           $response Callback response.
	 * @param array           $handler Matched route handler.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function end_client_side_media_request( $response, $handler, $request ) {
		$index = $this->client_side_phase_index( $request );
		if ( $index === false ) {
			return $response;
		}

		$phase = $this->client_side_phases[ $index ];
		array_splice( $this->client_side_phases, $index, 1 );

		try {
			if ( ! empty( $phase['withheld'] ) ) {
				// The callback's success response would imply that the submitted
				// sizes were registered; nothing was committed.
				$response = new WP_Error( $phase['withheld']['code'], $phase['withheld']['message'], [ 'status' => 409, 'retryable' => $phase['withheld']['retryable'] ] );
			} elseif ( $phase['phase'] === 'finalize' ) {
				$this->complete_client_side_finalize( $phase, $response );
			} elseif ( $phase['phase'] === 'sideload' ) {
				$this->track_client_side_replacement( $phase, $response );
			}
		} finally {
			if ( $phase['phase'] === 'create' ) {
				$unconsumed = [];
				$this->client_side_candidates = array_values( array_filter( $this->client_side_candidates, function( $candidate ) use ( $phase, &$unconsumed ) {
					if ( isset( $candidate['request'] ) && $candidate['request'] === $phase['request'] ) {
						$unconsumed[] = $candidate;
						return false;
					}
					return true;
				} ) );
				// Client-side residue is evaluated here, before the candidates are gone.
				$this->queue_unattributed_upload_notices( $unconsumed );
				$this->prune_upload_ledger();
				// A create's own provenance candidate binds only within that request.
				$this->provenance_candidates = array_values( array_filter( $this->provenance_candidates, function( $candidate ) use ( $phase ) {
					return empty( $candidate['client'] ) || $candidate['request'] !== $phase['request'];
				} ) );
			}
			if ( $phase['phase'] === 'finalize' ) {
				unset( $this->client_side_finalize[ $this->client_side_request_key( $phase['request'] ) ] );
				unset( $this->client_side_withheld_writes[ $phase['attachment_id'] ] );
				$finalize_open = false;
				foreach ( $this->client_side_phases as $remaining ) {
					$finalize_open = $finalize_open || $remaining['phase'] === 'finalize';
				}
				if ( ! $finalize_open ) {
					remove_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_client_side_finalize_watermark' ], 10 );
					remove_filter( 'wp_generate_attachment_metadata', [ $this, 'capture_client_side_finalize_metadata' ], PHP_INT_MAX );
					remove_filter( 'update_post_metadata', [ $this, 'withhold_client_side_metadata_write' ], PHP_INT_MAX );
				}
			}
		}

		return $response;
	}

	/**
	 * Persist the deferred claim once the client-side create request has inserted
	 * the attachment that owns an eligible candidate upload.
	 *
	 * @param WP_Post         $attachment Inserted attachment.
	 * @param WP_REST_Request $request Request.
	 * @param bool            $creating Whether the attachment was created.
	 * @return void
	 */
	public function claim_client_side_upload( $attachment, $request, $creating ) {
		$phase = $this->current_client_side_phase();
		if ( $creating && $phase !== null && $phase['phase'] === 'create' && $phase['request'] === $request && $attachment instanceof WP_Post && $attachment->post_type === 'attachment' ) {
			// Independent of the automatic claim below: provenance is recorded
			// whether or not automatic watermarking is enabled.
			$this->bind_client_side_provenance( (int) $attachment->ID, $request );
		}
		if ( ! $creating || empty( $this->client_side_candidates ) || $phase === null || $phase['phase'] !== 'create' || $phase['request'] !== $request || ! $attachment instanceof WP_Post || $attachment->post_type !== 'attachment' ) {
			return;
		}

		$attachment_id = (int) $attachment->ID;
		$relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$identity = $this->operation_path_identity( get_attached_file( $attachment_id ), wp_upload_dir() );
		if ( ! is_string( $relative ) || $relative === '' || $identity === false ) {
			return;
		}

		foreach ( $this->client_side_candidates as $index => $candidate ) {
			if ( ! isset( $candidate['request'] ) || $candidate['request'] !== $request || ! $this->paths_match( $candidate['file'], $identity['path'] ) ) {
				continue;
			}

			unset( $this->client_side_candidates[ $index ] );
			$now = time();
			add_post_meta( $attachment_id, self::CLIENT_SIDE_CLAIM_META_KEY, [
				'version'       => self::CLIENT_SIDE_CLAIM_VERSION,
				'attachment_id' => $attachment_id,
				'file'          => wp_normalize_path( $relative ),
				'files'         => [ wp_normalize_path( $relative ) ],
				'is_admin'      => (bool) $candidate['is_admin'],
				'state'         => 'pending',
				'code'          => '',
				'fingerprint'   => '',
				'operation_id'  => '',
				'attempts'      => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			], true );
			return;
		}
	}

	/**
	 * Bind the client-side create request's own upload to the attachment it
	 * inserted, by exact canonical identity with the create-stored attached
	 * file. Exactly one candidate of this request and site must match. Passive:
	 * any exception records nothing and never blocks the automatic claim.
	 *
	 * @param int             $attachment_id Inserted attachment.
	 * @param WP_REST_Request $request Create request.
	 * @return void
	 */
	private function bind_client_side_provenance( $attachment_id, $request ) {
		if ( empty( $this->provenance_candidates ) ) {
			return;
		}
		try {
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return;
			}
			$identity = $this->operation_path_identity( get_attached_file( $attachment_id ), wp_upload_dir() );
			if ( $identity === false ) {
				return;
			}
			$site_id = get_current_blog_id();
			$matched = [];
			foreach ( $this->provenance_candidates as $index => $candidate ) {
				if ( ! empty( $candidate['client'] ) && $candidate['request'] === $request && $candidate['site_id'] === $site_id && $candidate['identity'] === $identity['identity'] ) {
					$matched[ $index ] = $candidate;
				}
			}
			foreach ( array_keys( $matched ) as $index ) {
				unset( $this->provenance_candidates[ $index ] );
			}
			$this->provenance_candidates = array_values( $this->provenance_candidates );
			if ( count( $matched ) === 1 ) {
				$candidate = reset( $matched );
				$this->write_provenance_record( $attachment_id, $candidate['path'], 'client-upload' );
			}
		} catch ( \Throwable $error ) {
			// Provenance is optional evidence; the attachment stays legacy. A
			// candidate left unconsumed could later bind changed bytes, so stop.
			$this->provenance_halted = true;
			$this->provenance_candidates = [];
		}
	}

	/**
	 * Apply the deferred automatic watermark during the matching finalize
	 * request's "update" metadata pass, once, through the existing operation.
	 *
	 * @param array  $data Finalized attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Metadata generation context.
	 * @return array
	 */
	public function apply_client_side_finalize_watermark( $data, $attachment_id, $context = '' ) {
		$attachment_id = (int) $attachment_id;
		$phase = $this->current_client_side_phase();
		if ( $context !== 'update' || $phase === null || $phase['phase'] !== 'finalize' || $phase['attachment_id'] !== $attachment_id || ! empty( $phase['withheld'] ) || $this->get_internal_operation_guard( $attachment_id ) ) {
			return $data;
		}

		$index = $this->client_side_phase_index( $phase['request'] );
		$fingerprint = $this->client_side_metadata_fingerprint( $data );
		if ( $fingerprint !== false ) {
			$this->begin_client_side_finalize_pass( $phase, $fingerprint );
		}

		// No claim, duplicate rows, and unreadable values all fail closed.
		$row = $this->read_client_side_claim_row( $attachment_id );

		// Never register a new clean file on a watermarked attachment, whether or
		// not a claim still exists. Rechecked here because another owner may have
		// committed the flag after the dispatch gate. The proposal must write
		// only the stored layout, except for the exact layout a rendered claim
		// already watermarked, whose first commit was lost: those files are this
		// claim's watermarked output, and only the keys its fingerprint covers.
		if ( $this->is_attachment_watermarked( $attachment_id, true ) ) {
			$stored   = wp_get_attachment_metadata( $attachment_id, true );
			$context  = $this->client_side_reference_context( $stored );
			$proposal = $this->client_side_metadata_reference_proposal( $stored, $data );
			if ( ! $this->client_side_reference_proposal_is_idempotent( $context, $proposal ) ) {
				$own_retry = $fingerprint !== false && is_array( $row ) && $row['claim']['state'] === 'rendered' && $row['claim']['fingerprint'] !== '' && hash_equals( $row['claim']['fingerprint'], $fingerprint ) && $this->client_side_claim_owns_metadata( $row['claim'], $attachment_id, $data ) && $this->client_side_retry_admits_proposal( $attachment_id, $context, $proposal );
				if ( ! $own_retry ) {
					$this->withhold_client_side_finalize( $index, $attachment_id, 'withheld' );
					return $data;
				}
			}
		}
		if ( $fingerprint === false ) {
			return $data;
		}

		if ( ! is_array( $row ) ) {
			return $data;
		}
		$claim = $row['claim'];

		if ( ! $this->client_side_claim_owns_metadata( $claim, $attachment_id, $data ) ) {
			// Finalize did run, so this is recorded as a mismatch rather than
			// left pending as though the browser never finished.
			if ( $claim['state'] === 'pending' ) {
				$this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'claim_mismatch', 'fingerprint' => $fingerprint ] );
				$this->record_client_side_outcome( $attachment_id, 'client_side_claim_mismatch', __( 'Automatic watermarking was skipped because the finalized upload did not match the file recorded when the upload started. Apply the watermark manually.', 'image-watermark' ) );
			}
			return $data;
		}

		// Only a pending claim renders. A retried finalize of a rendered or
		// terminal claim lets WordPress recommit the metadata without a second
		// render, and never reaches the reapply/restore path.
		if ( $claim['state'] !== 'pending' ) {
			return $data;
		}

		if ( $claim['created_at'] + self::CLIENT_SIDE_CLAIM_TTL < time() ) {
			$this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'claim_expired', 'fingerprint' => $fingerprint ] );
			$this->record_client_side_outcome( $attachment_id, 'client_side_claim_expired', __( 'Automatic watermarking was skipped because the upload was finalized more than 24 hours after it started. Apply the watermark manually.', 'image-watermark' ) );
			return $data;
		}

		if ( $this->is_attachment_watermarked( $attachment_id ) ) {
			$this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'already_watermarked', 'fingerprint' => $fingerprint ] );
			return $data;
		}

		// Current settings decide, under the admin/frontend mode of the upload.
		if ( ! $this->is_automatic_upload_enabled( get_post_mime_type( $attachment_id ), $claim['is_admin'] ) ) {
			$this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'automatic_disabled', 'fingerprint' => $fingerprint ] );
			return $data;
		}

		$previous_is_admin = $this->is_admin;
		$this->is_admin = $claim['is_admin'];
		if ( $this->operation_dispatch_depth === 0 ) {
			$this->last_operation_outcome = [];
		}
		$previous_initial_apply_only_attachment_id = $this->initial_apply_only_attachment_id;
		$this->initial_apply_only_attachment_id = $attachment_id;

		try {
			$dispatch = $this->dispatch_operation_with_capture( 'apply', $attachment_id, [ 'metadata' => $data, 'method' => '' ], [], $outcome );
			$result = $dispatch['legacy_result'];
		} finally {
			$this->is_admin = $previous_is_admin;
			$this->initial_apply_only_attachment_id = $previous_initial_apply_only_attachment_id;
		}

		$code = isset( $outcome['code'] ) ? $outcome['code'] : 'not_processed';

		if ( $code === 'busy' ) {
			// Another attachment operation owns the files. Nothing was rendered,
			// so keep the claim pending and the payload for a server-side retry.
			$this->defer_busy_client_side_finalize( $attachment_id, $this->client_side_request_sub_sizes( $phase['request'] ) );
			$this->withhold_client_side_finalize( $index, $attachment_id, 'busy' );
			return $data;
		}

		if ( $code === 'already_watermarked' && ! $this->client_side_metadata_write_is_idempotent( $attachment_id, $data ) ) {
			// The initial-apply-only guard found a flag committed under the lease;
			// the clean layout it returned must not be registered.
			$this->withhold_client_side_finalize( $index, $attachment_id, 'withheld' );
			return $data;
		}

		$attempts = $claim['attempts'] + 1;
		if ( isset( $outcome['outcome'] ) && $outcome['outcome'] === 'complete' ) {
			$state = 'rendered';
		} elseif ( isset( $outcome['outcome'] ) && $outcome['outcome'] === 'failed' && ! empty( $outcome['retryable'] ) && empty( $outcome['sizes']['processed'] ) && $attempts < self::CLIENT_SIDE_CLAIM_MAX_ATTEMPTS && ! $this->is_attachment_watermarked( $attachment_id ) ) {
			// Nothing was promoted, so a retried finalize may safely try again.
			$state = 'pending';
		} else {
			$state = 'terminal';
		}

		// A lost transition leaves the stored claim as another request left it;
		// consumption below re-reads it, so nothing proceeds on this write alone.
		$this->transition_client_side_claim( $attachment_id, $row, [
			'state'        => $state,
			'code'         => $code,
			'fingerprint'  => $fingerprint,
			'operation_id' => isset( $outcome['operation_id'] ) ? $outcome['operation_id'] : '',
			'attempts'     => $attempts,
		] );

		return is_array( $result ) ? $result : $data;
	}

	/**
	 * Record the finalized metadata that later callbacks hand to WordPress, so
	 * the commit can be verified before the claim is consumed, and arm the
	 * withheld write once every other metadata filter has run.
	 *
	 * @param array  $data Finalized attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Metadata generation context.
	 * @return array
	 */
	public function capture_client_side_finalize_metadata( $data, $attachment_id, $context = '' ) {
		$phase = $this->current_client_side_phase();
		if ( $context !== 'update' || $phase === null || $phase['phase'] !== 'finalize' || $phase['attachment_id'] !== (int) $attachment_id ) {
			return $data;
		}

		if ( ! empty( $phase['withheld'] ) ) {
			$this->client_side_withheld_writes[ (int) $attachment_id ] = true;
			return $data;
		}

		$key = $this->client_side_request_key( $phase['request'] );
		if ( isset( $this->client_side_finalize[ $key ] ) ) {
			$fingerprint = $this->client_side_metadata_fingerprint( $data );
			$this->client_side_finalize[ $key ]['final'] = $fingerprint === false ? '' : $fingerprint;
		}

		return $data;
	}

	/**
	 * Skip exactly one armed finalize metadata write for an attachment whose
	 * finalize was withheld. WordPress then keeps the unconsumed provenance rows,
	 * so the same payload remains valid for a later retry.
	 *
	 * @param mixed  $check Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function withhold_client_side_metadata_write( $check, $object_id, $meta_key ) {
		if ( $meta_key !== '_wp_attachment_metadata' || empty( $this->client_side_withheld_writes[ (int) $object_id ] ) ) {
			return $check;
		}

		unset( $this->client_side_withheld_writes[ (int) $object_id ] );
		return false;
	}

	/**
	 * Replay a finalize that was withheld because another watermark operation
	 * held the lease. The stored payload goes through the real finalize route,
	 * so WordPress repeats its permission, schema, and provenance checks.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{success:bool,code:string,message:string}
	 */
	public function complete_client_side_upload( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$payload = $this->read_client_side_payload( $attachment_id );
		if ( $payload === false ) {
			return $this->client_side_repair_result( false, 'client_side_nothing_to_complete', __( 'There is no deferred upload finalization to complete for this image.', 'image-watermark' ) );
		}
		if ( $this->is_attachment_watermarked( $attachment_id, true ) ) {
			return $this->client_side_repair_result( false, 'client_side_already_watermarked', $this->client_side_withheld_message() );
		}
		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( $row === false || ( is_array( $row ) && $row['claim']['state'] !== 'pending' ) ) {
			return $this->client_side_repair_result( false, 'client_side_claim_settled', __( 'This upload was already settled, so its deferred finalization is not replayed.', 'image-watermark' ) );
		}
		if ( $this->is_operation_lease_held( $attachment_id ) ) {
			return $this->client_side_repair_result( false, 'client_side_lease_busy', __( 'Another watermark operation is still running on this image. Please retry shortly.', 'image-watermark' ) );
		}

		$request = new WP_REST_Request( 'POST', '/wp/v2/media/' . $attachment_id . '/finalize' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'sub_sizes' => $payload['sub_sizes'] ] ) );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			$error = $response->as_error();
			return $this->client_side_repair_result( false, sanitize_key( $error->get_error_code() ), $this->sanitize_operation_message( $error->get_error_message() ) );
		}

		$result = $this->client_side_repair_result( true, 'client_side_upload_completed', __( 'The deferred upload finalization completed.', 'image-watermark' ) );
		$result['outcome'] = $this->get_attachment_operation_outcome( $attachment_id );
		return $result;
	}

	/**
	 * Report sideloaded files recorded for an attachment that its metadata does
	 * not reference. Only basenames and states are exposed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{total:int,files:array}
	 */
	public function describe_client_side_orphans( $attachment_id ) {
		$inventory = $this->inventory_client_side_orphans( (int) $attachment_id );
		$files = [];
		foreach ( array_slice( $inventory['files'], 0, self::CLIENT_SIDE_ORPHAN_REPORT_LIMIT ) as $entry ) {
			$files[] = [ 'file' => $entry['file'], 'state' => $entry['state'] ];
		}

		return [ 'total' => count( $inventory['files'] ), 'files' => $files ];
	}

	/**
	 * Remove unregistered sideloaded files that provably belong to the
	 * attachment and that no pending finalize can still consume, together with
	 * their provenance rows. Anything unprovable is left for manual action.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{success:bool,code:string,message:string,removed:string[],kept:array}
	 */
	public function cleanup_client_side_orphans( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$inventory = $this->inventory_client_side_orphans( $attachment_id );
		if ( empty( $inventory['files'] ) ) {
			return $this->client_side_repair_result( true, 'client_side_no_orphans', __( 'No unregistered sideloaded files were found.', 'image-watermark' ) );
		}
		if ( $inventory['in_flight'] ) {
			return $this->client_side_repair_result( false, 'client_side_upload_in_progress', __( 'This upload may still be finalizing. Try again after it completes, or after one hour.', 'image-watermark' ) );
		}

		$token = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->client_side_repair_result( false, 'client_side_lease_busy', __( 'Another watermark operation is still running on this image. Please retry shortly.', 'image-watermark' ) );
		}

		$removed = [];
		$kept = [];
		try {
			// Re-inventory under the lease so the decision matches what is deleted.
			$inventory = $this->inventory_client_side_orphans( $attachment_id );
			if ( $inventory['in_flight'] ) {
				return $this->client_side_repair_result( false, 'client_side_upload_in_progress', __( 'This upload may still be finalizing. Try again after it completes, or after one hour.', 'image-watermark' ) );
			}
			foreach ( $inventory['files'] as $entry ) {
				if ( $entry['state'] === 'removable' ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes one contained, unreferenced, attachment-owned file while holding the attachment lease.
					@unlink( $entry['path'] );
					clearstatcache( true, $entry['path'] );
					if ( file_exists( $entry['path'] ) ) {
						$kept[] = [ 'file' => $entry['file'], 'state' => 'manual' ];
						continue;
					}
				} elseif ( $entry['state'] !== 'missing' ) {
					$kept[] = [ 'file' => $entry['file'], 'state' => $entry['state'] ];
					continue;
				}
				delete_post_meta( $attachment_id, self::CLIENT_SIDE_PROVENANCE_META_KEY, wp_slash( $entry['name'] ) );
				$removed[] = $entry['file'];
			}
			if ( $this->is_attachment_watermarked( $attachment_id ) ) {
				// A deferred payload can never register on a watermarked attachment.
				delete_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY );
			}
		} finally {
			$this->release_operation_lock( [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ] );
		}

		$result = $this->client_side_repair_result( true, 'client_side_orphans_removed', __( 'Unregistered sideloaded files were removed. Files that could not be proven to belong to this image were left for manual review.', 'image-watermark' ) );
		$result['removed'] = $removed;
		$result['kept'] = $kept;
		return $result;
	}

	/**
	 * Remove aged rendered or terminal claims whose commit could not be
	 * verified. Protection is flag-based, so removal never reopens a
	 * watermarked attachment to clean derivatives. Pending claims are kept
	 * because they carry the incomplete-upload diagnostic.
	 *
	 * @return int Number of claims removed.
	 */
	public function sweep_client_side_claims() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded scan of one private meta key; each removal below is an exact-row delete.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT LIKE %s ORDER BY meta_id ASC LIMIT %d",
			self::CLIENT_SIDE_CLAIM_META_KEY,
			'%' . $wpdb->esc_like( 's:5:"state";s:7:"pending";' ) . '%',
			self::CLIENT_SIDE_SWEEP_LIMIT
		), ARRAY_A );

		$removed = 0;
		foreach ( (array) $rows as $row ) {
			$attachment_id = (int) $row['post_id'];
			$claim = is_serialized( $row['meta_value'] ) ? maybe_unserialize( $row['meta_value'] ) : null;
			if ( ! $this->is_valid_client_side_claim( $claim, $attachment_id ) || $claim['state'] === 'pending' || $claim['updated_at'] + self::CLIENT_SIDE_CLAIM_TTL >= time() ) {
				continue;
			}
			$current = $this->read_client_side_claim_row( $attachment_id );
			if ( ! is_array( $current ) || $current['meta_id'] !== (int) $row['meta_id'] || $current['raw'] !== $row['meta_value'] ) {
				continue;
			}
			if ( $this->delete_client_side_claim( $attachment_id, $current ) ) {
				delete_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY );
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * @param array           $handler Matched route handler.
	 * @param WP_REST_Request $request Request.
	 * @return array|null
	 */
	private function recognize_client_side_media_phase( $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! class_exists( 'WP_REST_Attachments_Controller', false ) || strtoupper( $request->get_method() ) !== 'POST' || ! is_array( $handler ) || empty( $handler['callback'] ) || ! is_array( $handler['callback'] ) || count( $handler['callback'] ) !== 2 ) {
			return null;
		}

		list( $controller, $method ) = array_values( $handler['callback'] );
		if ( ! $controller instanceof WP_REST_Attachments_Controller || ! is_string( $method ) ) {
			return null;
		}

		$route = $request->get_route();
		if ( $method === 'create_item' && $route === '/wp/v2/media' && false === $request['generate_sub_sizes'] && $this->client_side_finalize_route_exists() ) {
			return [ 'phase' => 'create', 'attachment_id' => 0, 'request' => $request ];
		}

		if ( ( $method === 'sideload_item' || $method === 'finalize_item' ) && preg_match( '#^/wp/v2/media/([1-9][0-9]*)/(sideload|finalize)$#', $route, $matches ) && $matches[2] . '_item' === $method && (int) $matches[1] === (int) $request['id'] ) {
			return [ 'phase' => $matches[2], 'attachment_id' => (int) $matches[1], 'request' => $request ];
		}

		return null;
	}

	/**
	 * A create request only defers its watermark when WordPress can finalize it.
	 *
	 * @return bool
	 */
	private function client_side_finalize_route_exists() {
		$routes = rest_get_server()->get_routes( 'wp/v2' );
		return isset( $routes['/wp/v2/media/(?P<id>[\d]+)/finalize'] );
	}

	/** @return array|null */
	private function current_client_side_phase() {
		return empty( $this->client_side_phases ) ? null : $this->client_side_phases[ count( $this->client_side_phases ) - 1 ];
	}

	/** @return int|false Index of the phase opened for this exact request. */
	private function client_side_phase_index( $request ) {
		$index = false;
		foreach ( $this->client_side_phases as $candidate_index => $candidate ) {
			if ( $candidate['request'] === $request ) {
				$index = $candidate_index;
			}
		}

		return $index;
	}

	/** @return string */
	private function client_side_request_key( $request ) {
		return is_object( $request ) ? spl_object_hash( $request ) : '';
	}

	/** @return void */
	private function begin_client_side_finalize_pass( $phase, $fingerprint ) {
		$key = $this->client_side_request_key( $phase['request'] );
		$this->client_side_finalize[ $key ] = [
			'attachment_id' => (int) $phase['attachment_id'],
			'input'         => $fingerprint,
			'final'         => '',
		];
	}

	/**
	 * The finalized main file must be the attached file, and the attached file
	 * must be the claimed upload or a replacement recorded during sideload.
	 *
	 * @param array $claim Valid claim.
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data Finalized attachment metadata.
	 * @return bool
	 */
	private function client_side_claim_owns_metadata( $claim, $attachment_id, $data ) {
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $attached ) || $attached === '' || ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			return false;
		}

		$attached = wp_normalize_path( $attached );
		if ( ! $this->paths_match( wp_normalize_path( $data['file'] ), $attached ) ) {
			return false;
		}
		foreach ( $claim['files'] as $file ) {
			if ( $this->paths_match( $file, $attached ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extend a pending claim's ownership chain when a 'scaled' or 'original'
	 * sideload replaced the claimed attached file. WordPress names these files
	 * with numeric suffixes (for example "photo-rotated-1.jpg"), so ownership is
	 * tracked from the observed replacement rather than inferred from names.
	 *
	 * @param array $phase Closed sideload phase.
	 * @param mixed $response Callback response.
	 * @return void
	 */
	private function track_client_side_replacement( $phase, $response ) {
		if ( is_wp_error( $response ) || ( $response instanceof WP_HTTP_Response && $response->get_status() >= 400 ) || ! isset( $phase['attached_before'] ) || ! is_string( $phase['attached_before'] ) || $phase['attached_before'] === '' ) {
			return;
		}

		$attachment_id = $phase['attachment_id'];
		$before = wp_normalize_path( $phase['attached_before'] );
		$after = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $after ) || ! $this->is_valid_client_side_relative_path( wp_normalize_path( $after ) ) ) {
			return;
		}
		$after = wp_normalize_path( $after );
		if ( $this->paths_match( $before, $after ) || dirname( $before ) !== dirname( $after ) ) {
			return;
		}

		// Concurrent sideloads may race on the claim; retry the exact transition.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$row = $this->read_client_side_claim_row( $attachment_id );
			if ( ! is_array( $row ) || $row['claim']['state'] !== 'pending' || count( $row['claim']['files'] ) >= 8 ) {
				return;
			}
			$owns_before = false;
			foreach ( $row['claim']['files'] as $file ) {
				if ( $this->paths_match( $file, $after ) ) {
					return;
				}
				$owns_before = $owns_before || $this->paths_match( $file, $before );
			}
			if ( ! $owns_before || $this->transition_client_side_claim( $attachment_id, $row, [ 'files' => array_merge( $row['claim']['files'], [ $after ] ) ] ) ) {
				return;
			}
		}
	}

	/** @return bool */
	private function is_valid_client_side_relative_path( $path ) {
		return is_string( $path ) && $path !== '' && strlen( $path ) <= 1024 && strpos( $path, "\0" ) === false && strpos( $path, '..' ) === false && strpos( $path, ':' ) === false && strpos( $path, '/' ) !== 0;
	}

	/** @return bool */
	private function is_valid_client_side_claim( $claim, $attachment_id ) {
		if ( ! is_array( $claim ) || strlen( maybe_serialize( $claim ) ) > 4096 ) {
			return false;
		}

		foreach ( [ 'version', 'attachment_id', 'attempts', 'created_at', 'updated_at' ] as $key ) {
			if ( ! isset( $claim[ $key ] ) || ! is_int( $claim[ $key ] ) ) {
				return false;
			}
		}
		foreach ( [ 'file', 'state', 'code', 'fingerprint', 'operation_id' ] as $key ) {
			if ( ! isset( $claim[ $key ] ) || ! is_string( $claim[ $key ] ) ) {
				return false;
			}
		}
		if ( ! isset( $claim['files'] ) || ! is_array( $claim['files'] ) || array_values( $claim['files'] ) !== $claim['files'] || count( $claim['files'] ) < 1 || count( $claim['files'] ) > 8 || $claim['files'][0] !== $claim['file'] ) {
			return false;
		}
		foreach ( $claim['files'] as $file ) {
			if ( ! $this->is_valid_client_side_relative_path( $file ) ) {
				return false;
			}
		}

		return $claim['version'] === self::CLIENT_SIDE_CLAIM_VERSION
			&& $claim['attachment_id'] === (int) $attachment_id
			&& $claim['attachment_id'] > 0
			&& isset( $claim['is_admin'] ) && is_bool( $claim['is_admin'] )
			&& in_array( $claim['state'], [ 'pending', 'rendered', 'terminal' ], true )
			&& ( $claim['fingerprint'] === '' || preg_match( '/^[a-f0-9]{64}$/', $claim['fingerprint'] ) )
			&& preg_match( '/^[A-Za-z0-9]{0,64}$/', $claim['operation_id'] )
			&& preg_match( '/^[a-z0-9_\-]{0,64}$/', $claim['code'] )
			&& $claim['attempts'] >= 0 && $claim['attempts'] <= self::CLIENT_SIDE_CLAIM_MAX_ATTEMPTS
			&& $claim['created_at'] > 0;
	}

	/**
	 * Read the attachment's single claim row directly from the database, so a
	 * transition compares against the exact stored row and value rather than a
	 * cached or filtered copy. Duplicate rows or an invalid value fail closed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{meta_id:int,raw:string,claim:array}|false|null Null when there is no claim.
	 */
	private function read_client_side_claim_row( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact-row read for the claim compare-and-swap; the cache may be stale across requests.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2",
			(int) $attachment_id,
			self::CLIENT_SIDE_CLAIM_META_KEY
		), ARRAY_A );
		if ( $wpdb->last_error !== '' ) {
			return false;
		}

		if ( empty( $rows ) ) {
			return null;
		}
		if ( count( $rows ) !== 1 || ! is_serialized( $rows[0]['meta_value'] ) ) {
			return false;
		}
		$claim = maybe_unserialize( $rows[0]['meta_value'] );
		if ( ! $this->is_valid_client_side_claim( $claim, $attachment_id ) ) {
			return false;
		}

		return [ 'meta_id' => (int) $rows[0]['meta_id'], 'raw' => $rows[0]['meta_value'], 'claim' => $claim ];
	}

	/**
	 * Write a claim transition only to the exact row and value this request
	 * read. Unlike update_post_meta() with a previous value, a row that was
	 * deleted or changed in the meantime is never recreated or overwritten.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $row Row returned by read_client_side_claim_row().
	 * @param array $changes Changed fields.
	 * @return array|false The new row, or false when the transition was lost.
	 */
	private function transition_client_side_claim( $attachment_id, $row, $changes ) {
		$claim = $row['claim'];
		$changes['code'] = isset( $changes['code'] ) ? substr( sanitize_key( $changes['code'] ), 0, 64 ) : $claim['code'];
		$changes['operation_id'] = isset( $changes['operation_id'] ) && is_string( $changes['operation_id'] ) && preg_match( '/^[A-Za-z0-9]{1,64}$/', $changes['operation_id'] ) ? $changes['operation_id'] : $claim['operation_id'];
		$changes['updated_at'] = time();
		$next = array_merge( $claim, $changes );
		if ( ! $this->is_valid_client_side_claim( $next, $attachment_id ) ) {
			return false;
		}

		$raw = maybe_serialize( $next );
		if ( $raw === $row['raw'] ) {
			return $row;
		}
		if ( $this->should_inject_operation_fault( 'client_side_claim_before_write', [ 'attachment_id' => (int) $attachment_id ] ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact-row compare-and-swap; core meta APIs recreate a deleted row.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND BINARY meta_value = %s",
			$raw,
			$row['meta_id'],
			(int) $attachment_id,
			self::CLIENT_SIDE_CLAIM_META_KEY,
			$row['raw']
		) );
		wp_cache_delete( (int) $attachment_id, 'post_meta' );

		return $updated === 1 ? [ 'meta_id' => $row['meta_id'], 'raw' => $raw, 'claim' => $next ] : false;
	}

	/**
	 * Delete exactly the claim row and value this request read.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $row Row returned by read_client_side_claim_row().
	 * @return bool
	 */
	private function delete_client_side_claim( $attachment_id, $row ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact-row compare-and-delete for the private claim.
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND BINARY meta_value = %s",
			$row['meta_id'],
			(int) $attachment_id,
			self::CLIENT_SIDE_CLAIM_META_KEY,
			$row['raw']
		) );
		wp_cache_delete( (int) $attachment_id, 'post_meta' );

		return $deleted === 1;
	}

	/**
	 * Bounded fingerprint of the finalized file layout: main file, original
	 * image, and the file registered for every size.
	 *
	 * @param mixed $data Attachment metadata.
	 * @return string|false
	 */
	private function client_side_metadata_fingerprint( $data ) {
		if ( ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			return false;
		}

		$sizes = [];
		if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
			foreach ( $data['sizes'] as $name => $size ) {
				$sizes[ (string) $name ] = is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ? $size['file'] : '';
			}
			ksort( $sizes, SORT_STRING );
		}

		return hash( 'sha256', maybe_serialize( [
			'file'           => $data['file'],
			'original_image' => isset( $data['original_image'] ) && is_string( $data['original_image'] ) ? $data['original_image'] : '',
			'sizes'          => $sizes,
		] ) );
	}

	/**
	 * Consume a rendered or terminal claim, and any deferred payload, only
	 * after WordPress persisted the finalized metadata this request produced.
	 * A pending claim or an unverified commit keeps the claim for a retry. A
	 * finalize WordPress rejected after authorization is recorded on the claim
	 * so diagnostics do not report an unfinished browser upload.
	 *
	 * @param array $phase Closed finalize phase.
	 * @param mixed $response Callback response.
	 * @return void
	 */
	private function complete_client_side_finalize( $phase, $response ) {
		$attachment_id = (int) $phase['attachment_id'];
		if ( is_wp_error( $response ) || ( $response instanceof WP_HTTP_Response && $response->get_status() >= 400 ) ) {
			$row = ! empty( $phase['dispatched'] ) ? $this->read_client_side_claim_row( $attachment_id ) : null;
			if ( is_array( $row ) && $row['claim']['state'] === 'pending' && $row['claim']['code'] === '' ) {
				$this->transition_client_side_claim( $attachment_id, $row, [ 'code' => 'finalize_rejected' ] );
			}
			return;
		}

		$key = $this->client_side_request_key( $phase['request'] );
		$pass = isset( $this->client_side_finalize[ $key ] ) ? $this->client_side_finalize[ $key ] : null;
		if ( ! is_array( $pass ) || $pass['attachment_id'] !== $attachment_id || $pass['final'] === '' || $this->client_side_metadata_fingerprint( wp_get_attachment_metadata( $attachment_id, true ) ) !== $pass['final'] ) {
			return;
		}

		// The finalized metadata is committed: complete the rendered apply's
		// metadata handoff before the claim is consumed.
		$this->verify_metadata_handoff( $attachment_id );
		delete_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY );
		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( is_array( $row ) && $row['claim']['state'] !== 'pending' ) {
			$this->delete_client_side_claim( $attachment_id, $row );
		}
	}

	/**
	 * Mark the current finalize as withheld. Its metadata write is skipped by
	 * withhold_client_side_metadata_write() and its response replaced by a 409.
	 *
	 * @param int|false $index Phase index.
	 * @param int       $attachment_id Attachment ID.
	 * @param string    $reason 'withheld' or 'busy'.
	 * @return void
	 */
	private function withhold_client_side_finalize( $index, $attachment_id, $reason ) {
		if ( $index === false ) {
			return;
		}

		if ( $reason === 'busy' ) {
			$this->client_side_phases[ $index ]['withheld'] = [ 'code' => 'iw_client_side_finalize_busy', 'message' => $this->client_side_busy_message(), 'retryable' => true ];
			return;
		}

		$this->settle_withheld_client_side_finalize( $attachment_id );
		$this->client_side_phases[ $index ]['withheld'] = [ 'code' => 'iw_client_side_finalize_withheld', 'message' => $this->client_side_withheld_message(), 'retryable' => false ];
	}

	/**
	 * @param int|false $index Phase index.
	 * @return WP_Error
	 */
	private function refuse_client_side_request( $index, $code, $message, $retryable ) {
		$this->client_side_phases[ $index ]['withheld'] = [ 'code' => $code, 'message' => $message, 'retryable' => (bool) $retryable ];
		return new WP_Error( $code, $message, [ 'status' => 409, 'retryable' => (bool) $retryable ] );
	}

	/**
	 * A finalize for a watermarked attachment can never register its new files.
	 * Settle a pending claim and keep the reason visible.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function settle_withheld_client_side_finalize( $attachment_id ) {
		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( is_array( $row ) && $row['claim']['state'] === 'pending' ) {
			$this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'finalize_withheld' ] );
		}
		delete_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY );
		$this->record_client_side_outcome( $attachment_id, 'client_side_finalize_withheld', $this->client_side_withheld_message() );
	}

	/**
	 * Keep a busy finalize retryable: store its payload and mark a pending claim.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $sub_sizes Finalize sub-size entries.
	 * @return void
	 */
	private function defer_busy_client_side_finalize( $attachment_id, $sub_sizes ) {
		$payload = $this->sanitize_client_side_payload( [ 'version' => 1, 'stored_at' => time(), 'sub_sizes' => $sub_sizes ] );
		if ( $payload !== false ) {
			update_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY, wp_slash( $payload ) );
		}

		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( is_array( $row ) && $row['claim']['state'] === 'pending' ) {
			$this->transition_client_side_claim( $attachment_id, $row, [ 'code' => 'busy' ] );
		}
	}

	/**
	 * After a completed manual or bulk apply/remove, record that choice on a
	 * pending claim so a late finalize neither renders nor reports the upload
	 * as unfinished.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Operation context.
	 * @param array  $operation Current operation and lease.
	 * @return bool Whether the lease remained held.
	 */
	private function settle_client_side_claim_after_manual( $attachment_id, $context, &$operation ) {
		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		// The latest manual choice wins over an earlier one; other terminal
		// dispositions and rendered claims are left as they are.
		if ( is_array( $row ) && ( $row['claim']['state'] === 'pending' || ( $row['claim']['state'] === 'terminal' && in_array( $row['claim']['code'], [ 'manual_apply', 'manual_remove' ], true ) ) ) ) {
			$this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => $context === 'manual-remove' ? 'manual_remove' : 'manual_apply' ] );
		}
		// The claim transition and metadata deletion are separate effects.
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		// The manual choice supersedes the deferred finalize.
		delete_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY );
		return $this->renew_operation_lock( $operation );
	}

	/**
	 * Persist a client-side terminal skip through the operation journal so its
	 * reason and next action survive claim cleanup.
	 *
	 * @return void
	 */
	private function record_client_side_outcome( $attachment_id, $code, $message ) {
		$this->persist_terminal_outcome( (int) $attachment_id, 'apply', 'auto-apply', 'skipped', $code, $message, 'skipped' );
	}

	/**
	 * A 'scaled' or 'original' sideload points _wp_attached_file at a new main
	 * file before finalize updates the metadata. Until finalize commits, the
	 * backup key (metadata file) and the render target (attached file) differ,
	 * so no operation may run against the attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data Attachment metadata.
	 * @return bool
	 */
	private function is_unfinalized_client_side_replacement( $attachment_id, $data ) {
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $attached ) || $attached === '' || ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) || wp_normalize_path( $attached ) === wp_normalize_path( $data['file'] ) ) {
			return false;
		}

		return $this->read_client_side_claim_row( $attachment_id ) !== null || get_post_meta( $attachment_id, self::CLIENT_SIDE_PROVENANCE_META_KEY ) !== [] || get_post_meta( $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY ) !== [];
	}

	/** @return string */
	private function client_side_unfinalized_message() {
		return __( 'This upload was not finalized: the editor replaced its main file, but the image metadata was never updated. Complete the deferred upload if one is available, or re-upload the image.', 'image-watermark' );
	}

	/** @return string */
	private function client_side_withheld_message() {
		return __( 'New image sizes from this upload were not registered because the image was already watermarked before the upload finished. Apply the watermark again to regenerate every size, or re-upload the image.', 'image-watermark' );
	}

	/** @return string */
	private function client_side_busy_message() {
		return __( 'Another watermark operation was running, so this upload was not finalized. Its image sizes were kept and the upload can be completed once that operation finishes.', 'image-watermark' );
	}

	/** @return array */
	private function client_side_repair_result( $success, $code, $message ) {
		return [ 'success' => (bool) $success, 'code' => $code, 'message' => $message, 'removed' => [], 'kept' => [] ];
	}

	/**
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $fresh Whether to bypass this request's post-meta cache.
	 * @return bool
	 */
	private function is_attachment_watermarked( $attachment_id, $fresh = false ) {
		if ( $fresh ) {
			wp_cache_delete( (int) $attachment_id, 'post_meta' );
		}

		return (int) get_post_meta( (int) $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
	}

	/**
	 * Advisory check for a live attachment lease or deletion fence. The lease
	 * taken inside apply_watermark() remains authoritative.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_operation_lease_held( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached read of the lease written by another request.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->operation_lock_name( $attachment_id ) ) );
		if ( $raw === null ) {
			return false;
		}
		$lock = maybe_unserialize( $raw );

		return ! is_array( $lock ) || empty( $lock['heartbeat_at'] ) || ! empty( $lock['deleting'] ) || (int) $lock['heartbeat_at'] > time() - 900;
	}

	/** @return array */
	private function client_side_request_sub_sizes( $request ) {
		$sub_sizes = $request instanceof WP_REST_Request ? $request['sub_sizes'] : [];
		return is_array( $sub_sizes ) ? $sub_sizes : [];
	}

	/**
	 * Build the normalized `{ key: file }` reference map of attachment
	 * metadata: the five special keys, plus one `sizes:{name}` key per size
	 * name exactly as given. Entries without a file are skipped, as core
	 * skips them.
	 *
	 * @param mixed $metadata Attachment metadata.
	 * @return array<string,string>
	 */
	private function client_side_reference_map( $metadata ) {
		$map = [];
		if ( ! is_array( $metadata ) ) {
			return $map;
		}

		foreach ( [ 'file', 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ] as $key ) {
			if ( isset( $metadata[ $key ] ) && is_string( $metadata[ $key ] ) && $metadata[ $key ] !== '' ) {
				$map[ $key ] = $metadata[ $key ];
			}
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $entry ) {
				if ( is_array( $entry ) && isset( $entry['file'] ) && is_string( $entry['file'] ) && $entry['file'] !== '' ) {
					$map[ 'sizes:' . (string) $name ] = $entry['file'];
				}
			}
		}

		return $map;
	}

	/**
	 * The stored reference map plus the entries recognized as marked legacy
	 * entries: an `iw-legacy-` name and the stored `iw_legacy` marker.
	 *
	 * @param mixed $metadata Attachment metadata.
	 * @return array{map:array<string,string>,marked:array<string,bool>,files:array<int,array{file:string,legacy:bool}>}
	 */
	private function client_side_reference_context( $metadata ) {
		$context = [ 'map' => $this->client_side_reference_map( $metadata ), 'marked' => [], 'files' => [] ];
		if ( ! is_array( $metadata ) ) {
			return $context;
		}

		foreach ( [ 'file', 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ] as $key ) {
			if ( isset( $context['map'][ $key ] ) ) {
				$context['files'][] = [ 'file' => $context['map'][ $key ], 'legacy' => false ];
			}
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $entry ) {
				$key = 'sizes:' . (string) $name;
				if ( ! isset( $context['map'][ $key ] ) ) {
					continue;
				}
				$legacy = $this->is_marked_legacy_size( $name, $entry );
				if ( $legacy ) {
					$context['marked'][ $key ] = true;
				}
				$context['files'][] = [ 'file' => $context['map'][ $key ], 'legacy' => $legacy ];
			}
		}

		return $context;
	}

	/**
	 * A reserved legacy entry is recognizable only by both its `iw-legacy-`
	 * name and its stored `iw_legacy` marker, so an unrelated size that happens
	 * to use the prefix is never reclassified.
	 *
	 * @param mixed $name  Size name.
	 * @param mixed $entry Size entry.
	 * @return bool
	 */
	private function is_marked_legacy_size( $name, $entry ) {
		return is_string( $name ) && strpos( $name, self::LEGACY_SIZE_PREFIX ) === 0 && is_array( $entry ) && isset( $entry['iw_legacy'] ) && $entry['iw_legacy'] === true;
	}

	/**
	 * Compare reference files by exact relative path or basename, after path
	 * normalization, as stored metadata generates them.
	 *
	 * @param mixed $first First file.
	 * @param mixed $second Second file.
	 * @return bool
	 */
	private function client_side_reference_files_match( $first, $second ) {
		if ( ! is_string( $first ) || ! is_string( $second ) || $first === '' || $second === '' ) {
			return false;
		}

		$first  = wp_normalize_path( $first );
		$second = wp_normalize_path( $second );

		return $first === $second || wp_basename( $first ) === wp_basename( $second );
	}

	/**
	 * Build the proposal from a finalize payload exactly as core's
	 * finalize_item() assembles metadata: grouped names expand to one entry
	 * each, `scaled`/`original` write the main file and `original_image`, the
	 * source-format original and the animated companions write their own
	 * keys, and any other name writes a size entry. A later entry replaces an
	 * earlier one, as in core.
	 *
	 * @param array<string,string> $stored_map Stored reference map.
	 * @param mixed                $sub_sizes  Request sub-size entries.
	 * @return array{map:array<string,string>,keys:string[],files:string[]}
	 */
	private function client_side_sub_size_reference_proposal( $stored_map, $sub_sizes ) {
		$proposal = [ 'map' => $stored_map, 'keys' => [], 'files' => [] ];
		foreach ( (array) $sub_sizes as $sub_size ) {
			if ( ! is_array( $sub_size ) || ! isset( $sub_size['image_size'] ) || empty( $sub_size['file'] ) || ! is_string( $sub_size['file'] ) ) {
				continue;
			}

			$image_size = $sub_size['image_size'];
			if ( is_array( $image_size ) ) {
				foreach ( $image_size as $name ) {
					if ( ! is_string( $name ) ) {
						continue;
					}
					$key = 'sizes:' . $name;
					$proposal['map'][ $key ] = $sub_size['file'];
					$proposal['keys'][] = $key;
					$proposal['files'][] = $sub_size['file'];
				}
				continue;
			}
			if ( ! is_string( $image_size ) ) {
				continue;
			}

			if ( $image_size === 'original' || $image_size === 'scaled' ) {
				if ( ! empty( $sub_size['original_image'] ) && is_string( $sub_size['original_image'] ) ) {
					$proposal['map']['original_image'] = $sub_size['original_image'];
					$proposal['keys'][] = 'original_image';
					$proposal['files'][] = $sub_size['original_image'];
				}
				$proposal['map']['file'] = $sub_size['file'];
				$proposal['keys'][] = 'file';
				$proposal['files'][] = $sub_size['file'];
			} elseif ( $image_size === 'source_original' ) {
				$proposal['map']['source_image'] = $sub_size['file'];
				$proposal['keys'][] = 'source_image';
				$proposal['files'][] = $sub_size['file'];
			} elseif ( $image_size === 'animated_video' ) {
				$proposal['map']['animated_video'] = $sub_size['file'];
				$proposal['keys'][] = 'animated_video';
				$proposal['files'][] = $sub_size['file'];
			} elseif ( $image_size === 'animated_video_poster' ) {
				$proposal['map']['animated_video_poster'] = $sub_size['file'];
				$proposal['keys'][] = 'animated_video_poster';
				$proposal['files'][] = $sub_size['file'];
			} else {
				$key = 'sizes:' . $image_size;
				$proposal['map'][ $key ] = $sub_size['file'];
				$proposal['keys'][] = $key;
				$proposal['files'][] = $sub_size['file'];
			}
		}

		return $proposal;
	}

	/**
	 * Build the proposal from core's assembled metadata: every reference key
	 * whose entry differs in any field from the stored entry, or that the
	 * stored map lacks.
	 *
	 * @param mixed $stored Stored attachment metadata.
	 * @param mixed $data   Core's assembled attachment metadata.
	 * @return array{map:array<string,string>,keys:string[],files:string[]}
	 */
	private function client_side_metadata_reference_proposal( $stored, $data ) {
		$map      = $this->client_side_reference_map( $data );
		$stored   = is_array( $stored ) ? $stored : [];
		$data     = is_array( $data ) ? $data : [];
		$proposal = [ 'map' => $map, 'keys' => [], 'files' => [] ];

		foreach ( [ 'file', 'original_image', 'source_image', 'animated_video', 'animated_video_poster' ] as $key ) {
			if ( ! isset( $map[ $key ] ) || ! $this->client_side_metadata_field_differs( $stored, $data, $key ) ) {
				continue;
			}
			$proposal['keys'][] = $key;
			$proposal['files'][] = $map[ $key ];
		}

		$names = [];
		foreach ( [ $stored, $data ] as $metadata ) {
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( array_keys( $metadata['sizes'] ) as $name ) {
					$names[ (string) $name ] = true;
				}
			}
		}
		foreach ( array_keys( $names ) as $name ) {
			$key = 'sizes:' . $name;
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}
			$stored_entry = isset( $stored['sizes'][ $name ] ) ? $stored['sizes'][ $name ] : null;
			$data_entry   = isset( $data['sizes'][ $name ] ) ? $data['sizes'][ $name ] : null;
			if ( ! $this->client_side_metadata_entries_differ( $stored_entry, $data_entry ) ) {
				continue;
			}
			$proposal['keys'][] = $key;
			$proposal['files'][] = $map[ $key ];
		}

		return $proposal;
	}

	/**
	 * @param array $stored Stored metadata.
	 * @param array $data   Assembled metadata.
	 * @param string $key   Reference key.
	 * @return bool
	 */
	private function client_side_metadata_field_differs( $stored, $data, $key ) {
		$has_stored = array_key_exists( $key, (array) $stored );
		$has_data   = array_key_exists( $key, (array) $data );
		if ( $has_stored !== $has_data ) {
			return true;
		}
		if ( ! $has_stored ) {
			return false;
		}

		return $this->client_side_metadata_entries_differ( $stored[ $key ], $data[ $key ] );
	}

	/**
	 * Compare metadata entries field by field. Array order is not a field, so
	 * it is not compared.
	 *
	 * @param mixed $stored_entry Stored entry.
	 * @param mixed $data_entry   Assembled entry.
	 * @return bool
	 */
	private function client_side_metadata_entries_differ( $stored_entry, $data_entry ) {
		if ( ! is_array( $stored_entry ) || ! is_array( $data_entry ) ) {
			return $stored_entry !== $data_entry;
		}

		$fields = array_keys( $stored_entry + $data_entry );
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $stored_entry ) || ! array_key_exists( $field, $data_entry ) ) {
				return true;
			}
			if ( $stored_entry[ $field ] !== $data_entry[ $field ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A proposal is idempotent only when every key it writes is stored with
	 * the same file, no written key is a marked legacy entry, and no written
	 * file is stored only under a marked legacy entry.
	 *
	 * @param array $context  Stored reference context.
	 * @param array $proposal Proposal from either gate.
	 * @return bool
	 */
	private function client_side_reference_proposal_is_idempotent( $context, $proposal ) {
		foreach ( $proposal['keys'] as $key ) {
			if ( isset( $context['marked'][ $key ] ) || ! isset( $context['map'][ $key ] ) ) {
				return false;
			}
			if ( ! $this->client_side_reference_files_match( $context['map'][ $key ], $proposal['map'][ $key ] ) ) {
				return false;
			}
		}
		foreach ( $proposal['files'] as $file ) {
			if ( $this->client_side_file_is_legacy_only( $file, $context ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether stored metadata references this file only under marked legacy
	 * entries.
	 *
	 * @param string $file    Proposed file.
	 * @param array  $context Stored reference context.
	 * @return bool
	 */
	private function client_side_file_is_legacy_only( $file, $context ) {
		$matched_legacy = false;
		foreach ( $context['files'] as $reference ) {
			if ( ! $this->client_side_reference_files_match( $file, $reference['file'] ) ) {
				continue;
			}
			if ( ! $reference['legacy'] ) {
				return false;
			}
			$matched_legacy = true;
		}

		return $matched_legacy;
	}

	/**
	 * The rendered-claim exact retry covers only the keys its fingerprint
	 * authenticates (`file`, `original_image`, `sizes`). It never admits a
	 * marked legacy key or a legacy-only file. A companion key passes only
	 * unchanged, or as a new key whose exact `_wp_sideloaded_file` row names
	 * the proposed file.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $context       Stored reference context.
	 * @param array $proposal      Proposal from the filter gate.
	 * @return bool
	 */
	private function client_side_retry_admits_proposal( $attachment_id, $context, $proposal ) {
		foreach ( $proposal['keys'] as $key ) {
			if ( isset( $context['marked'][ $key ] ) ) {
				return false;
			}
			if ( $key === 'file' || $key === 'original_image' || strpos( $key, 'sizes:' ) === 0 ) {
				continue;
			}
			if ( ! in_array( $key, [ 'source_image', 'animated_video', 'animated_video_poster' ], true ) ) {
				return false;
			}

			$file = isset( $proposal['map'][ $key ] ) ? $proposal['map'][ $key ] : '';
			if ( isset( $context['map'][ $key ] ) ) {
				if ( ! $this->client_side_reference_files_match( $context['map'][ $key ], $file ) ) {
					return false;
				}
				continue;
			}
			if ( ! $this->client_side_sideload_provenance_names_file( $attachment_id, $file ) ) {
				return false;
			}
		}
		foreach ( $proposal['files'] as $file ) {
			if ( $this->client_side_file_is_legacy_only( $file, $context ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this attachment's own sideload provenance recorded exactly this
	 * file.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Proposed file.
	 * @return bool
	 */
	private function client_side_sideload_provenance_names_file( $attachment_id, $file ) {
		if ( ! is_string( $file ) || $file === '' ) {
			return false;
		}
		foreach ( (array) get_post_meta( $attachment_id, self::CLIENT_SIDE_PROVENANCE_META_KEY ) as $name ) {
			if ( is_string( $name ) && $name === $file ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether core's assembled metadata writes only the stored layout while
	 * the attachment is flagged.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param mixed $data          Core's assembled metadata.
	 * @return bool
	 */
	private function client_side_metadata_write_is_idempotent( $attachment_id, $data ) {
		wp_cache_delete( (int) $attachment_id, 'post_meta' );
		$stored  = wp_get_attachment_metadata( $attachment_id, true );
		$context = $this->client_side_reference_context( $stored );

		return $this->client_side_reference_proposal_is_idempotent( $context, $this->client_side_metadata_reference_proposal( $stored, $data ) );
	}

	/**
	 * Names the attachment's stored metadata and attached file already
	 * reference, in both their stored and basename forms.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param mixed $metadata      Stored metadata, or null to read it.
	 * @return array<string,bool>
	 */
	private function client_side_referenced_names( $attachment_id, $metadata = null ) {
		$names = array_values( $this->client_side_reference_map( $metadata === null ? wp_get_attachment_metadata( $attachment_id, true ) : $metadata ) );
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( is_string( $attached ) && $attached !== '' ) {
			$names[] = $attached;
		}

		$referenced = [];
		foreach ( $names as $name ) {
			$referenced[ wp_normalize_path( $name ) ] = true;
			$referenced[ wp_basename( $name ) ] = true;
		}

		return $referenced;
	}

	/**
	 * Bound and type-check a finalize payload before storing or replaying it.
	 * WordPress repeats its own schema and provenance validation on replay.
	 *
	 * @param mixed $payload Payload.
	 * @return array|false
	 */
	private function sanitize_client_side_payload( $payload ) {
		if ( ! is_array( $payload ) || ! isset( $payload['version'], $payload['stored_at'], $payload['sub_sizes'] ) || (int) $payload['version'] !== 1 || ! is_array( $payload['sub_sizes'] ) || count( $payload['sub_sizes'] ) > 100 ) {
			return false;
		}

		$sub_sizes = [];
		foreach ( $payload['sub_sizes'] as $sub_size ) {
			if ( ! is_array( $sub_size ) || ! isset( $sub_size['image_size'] ) ) {
				return false;
			}
			$entry = [];
			$image_size = $sub_size['image_size'];
			if ( is_array( $image_size ) ) {
				if ( count( $image_size ) > 64 ) {
					return false;
				}
				foreach ( $image_size as $name ) {
					if ( ! is_string( $name ) || strlen( $name ) > 255 ) {
						return false;
					}
				}
				$entry['image_size'] = array_values( $image_size );
			} elseif ( is_string( $image_size ) && strlen( $image_size ) <= 255 ) {
				$entry['image_size'] = $image_size;
			} else {
				return false;
			}
			foreach ( [ 'file', 'original_image', 'mime_type' ] as $key ) {
				if ( isset( $sub_size[ $key ] ) ) {
					if ( ! is_string( $sub_size[ $key ] ) || strlen( $sub_size[ $key ] ) > 1024 ) {
						return false;
					}
					$entry[ $key ] = $sub_size[ $key ];
				}
			}
			foreach ( [ 'width', 'height', 'filesize' ] as $key ) {
				if ( isset( $sub_size[ $key ] ) ) {
					if ( ! is_int( $sub_size[ $key ] ) || $sub_size[ $key ] < 0 ) {
						return false;
					}
					$entry[ $key ] = $sub_size[ $key ];
				}
			}
			$sub_sizes[] = $entry;
		}

		$clean = [ 'version' => 1, 'stored_at' => (int) $payload['stored_at'], 'sub_sizes' => $sub_sizes ];
		return strlen( maybe_serialize( $clean ) ) <= 65536 ? $clean : false;
	}

	/** @return array|false */
	private function read_client_side_payload( $attachment_id ) {
		$rows = get_post_meta( (int) $attachment_id, self::CLIENT_SIDE_PAYLOAD_META_KEY );
		return is_array( $rows ) && count( $rows ) === 1 ? $this->sanitize_client_side_payload( $rows[0] ) : false;
	}

	/** @return bool|null Whether any deferred payload row exists, or null on a read error. */
	private function client_side_payload_row_exists( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cleanup must distinguish an absent payload from a failed read.
		$meta_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
			(int) $attachment_id,
			self::CLIENT_SIDE_PAYLOAD_META_KEY
		) );
		return $wpdb->last_error !== '' ? null : $meta_id !== null;
	}

	/**
	 * Classify every recorded sideloaded file the attachment's metadata does not
	 * reference. States: awaiting_finalize (a finalize may still consume it),
	 * removable (provably owned and unreferenced), missing (row without a file),
	 * and manual (ownership or containment cannot be proven).
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $deleting Whether the attachment itself is being deleted.
	 * @return array{in_flight:bool,files:array}
	 */
	private function inventory_client_side_orphans( $attachment_id, $deleting = false ) {
		$inventory = [ 'in_flight' => false, 'files' => [] ];
		$names = [];
		foreach ( (array) get_post_meta( $attachment_id, self::CLIENT_SIDE_PROVENANCE_META_KEY ) as $name ) {
			if ( is_string( $name ) && $name !== '' && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		if ( empty( $names ) ) {
			return $inventory;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		$metadata_valid = is_array( $metadata ) && ! empty( $metadata['file'] ) && is_string( $metadata['file'] );
		$metadata_raw = $metadata_valid ? maybe_serialize( $metadata ) : '';
		$referenced = $metadata_valid ? $this->client_side_referenced_names( $attachment_id, $metadata ) : [];
		$row = $this->read_client_side_claim_row( $attachment_id );
		$payload_row = $this->client_side_payload_row_exists( $attachment_id );
		$payload_valid = $payload_row === true && $this->read_client_side_payload( $attachment_id ) !== false;
		$ownership_uncertain = ! $metadata_valid || $row === false || $payload_row === null || ( $payload_row === true && ! $payload_valid );
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$upload_dir = wp_upload_dir();
		$directory = false;
		$relative_dir = '';
		if ( is_string( $attached ) && $this->is_valid_client_side_relative_path( wp_normalize_path( $attached ) ) && empty( $upload_dir['error'] ) ) {
			$relative_dir = dirname( wp_normalize_path( $attached ) );
			$relative_dir = $relative_dir === '.' ? '' : $relative_dir;
			$base = realpath( $upload_dir['basedir'] );
			$directory = realpath( $upload_dir['basedir'] . ( $relative_dir === '' ? '' : '/' . $relative_dir ) );
			if ( $base === false || $directory === false || ! $this->operation_path_is_within_root( wp_normalize_path( $directory ), wp_normalize_path( $base ), true ) ) {
				$directory = false;
			}
		}

		$watermarked = $this->is_attachment_watermarked( $attachment_id );
		$in_flight = false;
		if ( ! $deleting && ! $watermarked ) {
			$in_flight = ( is_array( $row ) && $row['claim']['state'] === 'pending' && $row['claim']['created_at'] + self::CLIENT_SIDE_CLAIM_STALE_AFTER > time() ) || $payload_valid;
		}
		if ( ! $deleting ) {
			// Files a rendered claim watermarked but could not commit may still be
			// registered by that claim's own retry until the claim ages out.
			$in_flight = $in_flight || ( is_array( $row ) && $row['claim']['state'] === 'rendered' && $row['claim']['updated_at'] + self::CLIENT_SIDE_CLAIM_TTL > time() );
		}
		$inventory['in_flight'] = $in_flight;

		foreach ( $names as $name ) {
			$normalized = wp_normalize_path( $name );
			$basename = wp_basename( $normalized );
			// A malformed or extended metadata field can still name this file.
			// Keeping a false positive is safer than deleting a registered file.
			if ( isset( $referenced[ $normalized ] ) || isset( $referenced[ $basename ] ) || ( $basename !== '' && strpos( $metadata_raw, $basename ) !== false ) ) {
				continue;
			}

			$entry = [ 'file' => sanitize_text_field( $basename ), 'name' => $name, 'path' => '', 'state' => 'manual' ];
			$canonical = $relative_dir === '' ? $basename : $relative_dir . '/' . $basename;
			$valid_name = $basename !== '' && $basename[0] !== '.' && $this->is_valid_client_side_relative_path( $basename ) && ( $normalized === $basename || $normalized === $canonical );
			if ( ! $ownership_uncertain && $directory !== false && $valid_name ) {
				$path = wp_normalize_path( $directory ) . '/' . $basename;
				if ( ! file_exists( $path ) && ! is_link( $path ) ) {
					$entry['state'] = 'missing';
				} elseif ( ! is_link( $path ) && is_file( $path ) && wp_normalize_path( dirname( (string) realpath( $path ) ) ) === wp_normalize_path( $directory ) && ! $this->is_file_referenced_by_other_attachment( $attachment_id, $canonical ) ) {
					$entry['path'] = $path;
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A stat failure must keep the file for manual review.
					$modified = @filemtime( $path );
					if ( $modified !== false ) {
						$entry['state'] = ( ! $deleting && ! $watermarked && ( $in_flight || $modified > time() - self::CLIENT_SIDE_CLAIM_STALE_AFTER ) ) ? 'awaiting_finalize' : 'removable';
					}
				}
			}
			$inventory['files'][] = $entry;
		}

		return $inventory;
	}

	/** @return bool */
	private function is_file_referenced_by_other_attachment( $attachment_id, $relative ) {
		global $wpdb;
		// A basename match may belong to another directory. Keep the file for
		// manual review rather than risk deleting a shared file.
		$basename = wp_basename( $relative );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conservative ownership probe before deleting an unregistered file.
		$owner = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_sideloaded_file' ) AND meta_value LIKE %s LIMIT 1",
			(int) $attachment_id,
			'%' . $wpdb->esc_like( $basename ) . '%'
		) );
		return $owner !== null || $wpdb->last_error !== '';
	}

	/**
	 * Remove provably owned unregistered sideloaded files of an attachment that
	 * is being deleted. WordPress removes only files its metadata references.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function delete_client_side_orphans_with_attachment( $attachment_id ) {
		$inventory = $this->inventory_client_side_orphans( $attachment_id, true );
		foreach ( $inventory['files'] as $entry ) {
			if ( $entry['state'] === 'removable' ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletes one contained, unreferenced, attachment-owned file under the attachment deletion fence.
				@unlink( $entry['path'] );
			}
		}
	}

	/**
	 * Determine whether the current upload should follow admin automatic watermark rules.
	 *
	 * @return bool
	 */
	private function is_admin_upload_request() {
		$script_filename = isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] ) ? (string) wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) : '';
		$ref = $this->get_request_referer();

		if ( $this->is_rest_admin_media_upload( $ref ) ) {
			return true;
		}

		if ( wp_doing_ajax() ) {
			if ( ( strpos( $ref, admin_url() ) === false ) && ( basename( $script_filename ) === 'admin-ajax.php' ) ) {
				return false;
			}

			return true;
		}

		return is_admin();
	}

	/**
	 * Get the current request referer.
	 *
	 * @return string
	 */
	private function get_request_referer() {
		if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) {
			return (string) wp_unslash( $_REQUEST['_wp_http_referer'] );
		}

		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			return (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
		}

		return '';
	}

	/**
	 * Detect REST media uploads that originate from wp-admin screens such as Gutenberg.
	 * The route comes from the matched request, so pretty and plain
	 * (?rest_route=) permalinks classify the same way.
	 *
	 * @param string $ref Request referer.
	 * @return bool
	 */
	private function is_rest_admin_media_upload( $ref ) {
		$phase = $this->current_client_side_phase();
		if ( $phase === null && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( ! preg_match( '#^/wp/v2/media(?:/|$)#', $this->current_rest_route( $phase ) ) ) {
			return false;
		}

		return ( $ref !== '' && strpos( $ref, admin_url() ) !== false );
	}

	/**
	 * Resolve the REST route being served: the matched client-side request,
	 * then the rest_route query variable WordPress sets for both permalink
	 * styles, then the request URI below the REST prefix.
	 *
	 * @param array|null $phase Current client-side phase.
	 * @return string
	 */
	private function current_rest_route( $phase ) {
		if ( $phase !== null ) {
			return (string) $phase['request']->get_route();
		}

		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) && is_string( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			return '/' . trim( $GLOBALS['wp']->query_vars['rest_route'], '/' );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		$position = strpos( $path, $prefix );

		return $position === false ? '' : '/' . substr( $path, $position + strlen( $prefix ) );
	}

	/**
	 * Adds an admin notice when a text watermark font is missing (debug only).
	 *
	 * @param string $font Font filename.
	 * @return void
	 */
	private function maybe_add_missing_font_notice( $font ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		if ( $this->missing_font_notice_added ) {
			return;
		}

		$this->missing_font_notice_added = true;

		add_action( 'admin_notices', function() use ( $font ) {
			$label = $font ? $font : __( '(unknown)', 'image-watermark' );
			/* translators: 1: Missing font file name. */
			echo '<div class="notice notice-warning"><p>' . sprintf( esc_html__( 'Image Watermark: Text watermark skipped because the font file "%s" is missing.', 'image-watermark' ), esc_html( $label ) ) . '</p></div>';
		} );
	}

	/**
	 * Adds an admin notice for auto-apply issues.
	 *
	 * @param string $message Notice message.
	 * @param string $type Notice type: 'warning' or 'error'.
	 * @return void
	 */
	private function add_admin_notice( $message, $type = 'warning' ) {
		if ( ! is_admin() || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$notice_type = in_array( $type, [ 'error', 'warning', 'success', 'info' ], true ) ? $type : 'warning';

		add_action( 'admin_notices', function() use ( $message, $notice_type ) {
			echo '<div class="notice notice-' . esc_attr( $notice_type ) . '"><p>' . esc_html( $message ) . '</p></div>';
		} );
	}

	/** @return void */
	private function queue_auto_operation_notice( $attachment_id, $result ) {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! is_array( $result ) || empty( $result['message'] ) ) {
			return;
		}
		$records = get_user_meta( $user_id, '_iw_auto_operation_notices', true );
		$records = is_array( $records ) ? $records : [];
		$records[] = [
			'attachment_id' => (int) $attachment_id,
			'site_id'       => get_current_blog_id(),
			'code'          => sanitize_key( $result['code'] ),
			'message'       => $this->sanitize_operation_message( $result['message'] ),
			'type'          => $result['outcome'] === 'failed' ? 'error' : 'warning',
			'created_at'    => time(),
			'expires_at'    => time() + DAY_IN_SECONDS,
		];
		update_user_meta( $user_id, '_iw_auto_operation_notices', array_slice( $records, -20 ) );
	}

	/**
	 * Normalize only the additive rotation leaf for every runtime snapshot.
	 * Stored options are never written here: this also protects filter-provided
	 * and text-preview-local values before they reach an engine API.
	 *
	 * @param mixed $options Candidate option snapshot.
	 * @return array
	 */
	private function normalize_rotation_options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = [];
		}
		if ( ! isset( $options['watermark_image'] ) || ! is_array( $options['watermark_image'] ) ) {
			$options['watermark_image'] = [];
		}

		$rotation = 0;
		// Booleans are deliberately rejected: the contract is integer-only, so a
		// true value must resolve to 0 instead of a one-degree rotation.
		if ( array_key_exists( 'rotation', $options['watermark_image'] ) && is_scalar( $options['watermark_image']['rotation'] ) && ! is_bool( $options['watermark_image']['rotation'] ) && filter_var( $options['watermark_image']['rotation'], FILTER_VALIDATE_INT ) !== false ) {
			$candidate = (int) $options['watermark_image']['rotation'];
			if ( $candidate >= 0 && $candidate <= 360 ) {
				$rotation = $candidate === 360 ? 0 : $candidate;
			}
		}

		$options['watermark_image']['rotation'] = $rotation;
		return $options;
	}

	/**
	 * Validates watermark eligibility for apply/remove operations.
	 *
	 * Shared validation logic used by both automatic and manual watermarking flows.
	 * Context determines whether issues are blocking (manual) or non-blocking (auto).
	 *
	 * @param array $options Plugin options.
	 * @param int $attachment_id Attachment being processed.
	 * @param string $context Operation context: 'auto-apply', 'manual-apply', or 'manual-remove'.
	 * @return array Array with 'valid' (bool), 'error' (string|null), 'warning' (string|null), 'code' (string).
	 */
	private function validate_watermark_eligibility( $options, $attachment_id, $context ) {
		$options = $this->normalize_rotation_options( $options );
		$result = [
			'valid'   => true,
			'error'   => null,
			'warning' => null,
			'code'    => '',
		];

		// Check if engine is available
		if ( ! $this->plugin->get_extension() ) {
			$result['valid'] = false;
			$result['code']  = 'no_engine';
			$result['error'] = __( 'No image processing engine available (GD or Imagick required).', 'image-watermark' );
			return $result;
		}

		if ( in_array( $context, [ 'auto-apply', 'manual-apply' ], true ) ) {
			$rotation = $this->describe_rotation_capability( $options );
			if ( ! $rotation['available'] ) {
				$result['valid'] = false;
				$result['code']  = $rotation['code'];
				$result['error'] = $rotation['message'];
				return $result;
			}
		}

		// Check MIME type
		$allowed_mime = $this->plugin->get_allowed_mime_types();
		$mime_type = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime_type, $allowed_mime, true ) ) {
			$result['valid'] = false;
			$result['code']  = 'unsupported_mime';
			$result['error'] = sprintf(
				/* translators: 1: Unsupported MIME type. */
				__( 'Unsupported file type (%s). Only JPEG, PNG, and WebP are supported.', 'image-watermark' ),
				$mime_type ?: 'unknown'
			);
			return $result;
		}

		// Prevent watermarking the watermark image itself
		$watermark_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
		if ( $attachment_id === $watermark_id ) {
			$result['valid'] = false;
			$result['code']  = 'is_watermark_source';
			$result['error'] = __( 'Cannot watermark the selected watermark image itself.', 'image-watermark' );
			return $result;
		}

		// For manual operations, check if manual watermarking is enabled
		if ( in_array( $context, [ 'manual-apply', 'manual-remove' ], true ) ) {
			if ( empty( $options['watermark_image']['manual_watermarking'] ) ) {
				$result['valid'] = false;
				$result['code']  = 'manual_disabled';
				$result['error'] = __( 'Manual watermarking is disabled in settings.', 'image-watermark' );
				return $result;
			}
		}

		// Validate watermark configuration (for apply operations)
		if ( in_array( $context, [ 'auto-apply', 'manual-apply' ], true ) ) {
			if ( ! $this->has_valid_custom_watermark_dimensions( $options ) ) {
				$result['valid'] = false;
				$result['code']  = 'invalid_custom_dimensions';
				$result['error'] = __( 'Custom watermark dimensions must both be positive whole numbers.', 'image-watermark' );
				return $result;
			}

			$watermark_type = isset( $options['watermark_image']['type'] ) ? $options['watermark_image']['type'] : 'image';

			if ( $watermark_type === 'image' ) {
				if ( ! wp_attachment_is_image( $watermark_id ) ) {
					$result['valid'] = false;
					$result['code']  = 'watermark_source_missing';
					$result['error'] = __( 'Please select a valid watermark image.', 'image-watermark' );
					return $result;
				}
			} elseif ( $watermark_type === 'text' ) {
				$text_string = isset( $options['watermark_image']['text_string'] ) ? trim( $options['watermark_image']['text_string'] ) : '';
				if ( empty( $text_string ) ) {
					$result['valid'] = false;
					$result['code']  = 'watermark_text_empty';
					$result['error'] = __( 'Please enter watermark text.', 'image-watermark' );
					return $result;
				}

				// Validate font availability
				$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
				$font_path = $this->plugin->get_font_path( $font );

				if ( ! $font_path || ! file_exists( $font_path ) ) {
					$result['valid'] = false;
					$result['code']  = 'watermark_font_missing';
					$result['error'] = sprintf(
						/* translators: 1: Selected font file name. */
						__( 'Selected font "%s" is not available. Please choose a different font.', 'image-watermark' ),
						$font
					);
					return $result;
				}
			}
		}

		// For remove operations, check backup availability
		if ( $context === 'manual-remove' ) {
			$data = wp_get_attachment_metadata( $attachment_id, false );
			if ( ! is_array( $data ) || empty( $data['file'] ) ) {
				$result['valid'] = false;
				$result['code']  = 'invalid_metadata';
				$result['error'] = __( 'Invalid attachment metadata.', 'image-watermark' );
				return $result;
			}

			$backup = $this->describe_backup_path( $data['file'] );
			$backup_filepath = $backup['path'];
			if ( $backup['code'] !== '' && $backup['code'] !== 'backup_missing' ) {
				$result['valid'] = false;
				$result['code']  = $backup['code'];
				$result['error'] = $backup['message'];
				return $result;
			}
			if ( $backup_filepath === false || ! $this->is_valid_backup_file( $backup_filepath ) ) {
				$result['valid'] = false;
				$result['code']  = 'backup_missing';
				$result['error'] = __( 'No watermark backup found for this image.', 'image-watermark' );
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Reject impossible custom render dimensions before either image engine allocates.
	 * This is a runtime safety net for programmatic/filter-provided option snapshots;
	 * settings saves enforce the same positive-integer contract.
	 *
	 * @param array $options Operation settings snapshot.
	 * @return bool
	 */
	private function has_valid_custom_watermark_dimensions( $options ) {
		if ( ! isset( $options['watermark_image'] ) || ! is_array( $options['watermark_image'] ) || ! isset( $options['watermark_image']['watermark_size_type'] ) || (int) $options['watermark_image']['watermark_size_type'] !== 1 ) {
			return true;
		}

		foreach ( [ 'absolute_width', 'absolute_height' ] as $dimension ) {
			if ( ! isset( $options['watermark_image'][$dimension] ) || filter_var( $options['watermark_image'][$dimension], FILTER_VALIDATE_INT ) === false || (int) $options['watermark_image'][$dimension] <= 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether watermarking should be skipped for a small original image.
	 *
	 * @param array $options Plugin options.
	 * @param int $original_width Original image width.
	 * @param int $original_height Original image height.
	 * @return bool
	 */
	private function should_skip_small_image( $options, $original_width, $original_height ) {
		if ( empty( $options['watermark_image']['skip_small_images'] ) ) {
			return false;
		}

		$min_width = isset( $options['watermark_image']['min_image_width'] ) ? max( 0, (int) $options['watermark_image']['min_image_width'] ) : 0;
		$min_height = isset( $options['watermark_image']['min_image_height'] ) ? max( 0, (int) $options['watermark_image']['min_image_height'] ) : 0;

		if ( $min_width <= 0 && $min_height <= 0 ) {
			return false;
		}

		if ( $min_width > 0 && $original_width < $min_width ) {
			return true;
		}

		if ( $min_height > 0 && $original_height < $min_height ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the selected image sizes that are available for watermarking.
	 *
	 * @param array $options Plugin options.
	 * @param array $data Attachment metadata.
	 * @param string $original_file Original file path.
	 * @param array $upload_dir Upload directory data.
	 * @return array<string,string>
	 */
	private function get_target_image_paths( $options, $data, $original_file, $upload_dir ) {
		$targets = [];

		if ( empty( $options['watermark_on'] ) || ! is_array( $options['watermark_on'] ) ) {
			return $targets;
		}

		foreach ( $options['watermark_on'] as $image_size => $active_size ) {
			if ( (int) $active_size !== 1 ) {
				continue;
			}

			switch ( $image_size ) {
				case 'full':
					$filepath = $original_file;
					break;

				default:
					if ( empty( $data['sizes'] ) || ! array_key_exists( $image_size, $data['sizes'] ) ) {
						continue 2;
					}

					$filepath = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . dirname( $data['file'] ) . DIRECTORY_SEPARATOR . $data['sizes'][ $image_size ]['file'];
					break;
			}

			if ( is_file( $filepath ) ) {
				$targets[ $image_size ] = $filepath;
			}
		}

		return $targets;
	}

	/**
	 * Build a private, structured operation result. This deliberately remains out
	 * of existing successful AJAX payloads; native consumers read it separately.
	 *
	 * @param string $outcome Terminal outcome.
	 * @param string $token Operation token.
	 * @param string $code Stable code.
	 * @param string $message User-safe message.
	 * @param bool   $retryable Whether a retry may be useful.
	 * @param array  $sizes Logical size accounting.
	 * @return array
	 */
	private function operation_result( $outcome, $token, $code, $message, $retryable = false, $sizes = [], $read_only = false ) {
		$result = [
			'outcome'      => $outcome,
			'operation_id' => $token,
			'code'         => sanitize_key( $code ),
			'message'      => $this->sanitize_operation_message( $message, $read_only ),
			'retryable'    => (bool) $retryable,
			'sizes'        => $this->normalize_operation_sizes( $sizes ),
		];
		if ( ! $read_only ) {
			$this->last_operation_outcome = $result;
		}

		return $result;
	}

	/**
	 * Describe a candidate operation without taking a lease or publishing state.
	 *
	 * @internal
	 * @return array{operation:string,eligible:bool,code:string,message:string,sizes:array,details:array}
	 */
	public function preview_operation( $operation, $attachment_id, $request = [] ) {
		$previous_lookup = $this->preview_lookup_read_only;
		$this->preview_lookup_read_only = true;
		try {
			return $this->preview_operation_inspection( $operation, $attachment_id, $request );
		} finally {
			$this->preview_lookup_read_only = $previous_lookup;
		}
	}

	/** Inspect a preview under its caller's scoped lookup policy. */
	private function preview_operation_inspection( $operation, $attachment_id, $request ) {
		if ( ! in_array( $operation, [ 'apply', 'remove', 'regenerate', 'scan' ], true ) || ! is_array( $request ) ) {
			throw new InvalidArgumentException( 'Invalid operation preview request.' );
		}
		$preview = [ 'operation' => $operation, 'eligible' => false, 'code' => '', 'message' => '', 'sizes' => [], 'details' => [ 'read_only' => true, 'authoritative' => false ] ];
		if ( $operation === 'scan' ) {
			$scan = $this->inspect_attachment_for_scan( $attachment_id, $request );
			$preview['code'] = $scan['outcome']['code'];
			$preview['message'] = $scan['outcome']['message'];
			$preview['eligible'] = ! in_array( $preview['code'], [ 'scan_forbidden', 'attachment_not_found' ], true )
				&& ! ( $scan['facts']['limits']['registered_total'] === null && $scan['facts']['watermarked']['reason'] === 'query_failed' );
			$preview['sizes'] = array_keys( array_filter( $scan['facts']['sizes'], static function( $size ) { return $size['state']['observed_at'] !== null; } ) );
			$preview['details']['scan'] = $scan['facts'];
			return $preview;
		}
		if ( $operation === 'regenerate' ) {
			return $this->preview_common_regeneration( (int) $attachment_id, $request, $preview );
		}
		$data = array_key_exists( 'metadata', $request ) ? $request['metadata'] : wp_get_attachment_metadata( $attachment_id, false );
		$method = array_key_exists( 'method', $request ) ? $request['method'] : '';
		$prepared = $operation === 'apply'
			? $this->prepare_apply_request( $data, $attachment_id, $method, true )
			: $this->prepare_remove_request( $data, $attachment_id, $method, array_key_exists( 'options', $request ) ? $request['options'] : null, true );
		if ( isset( $prepared['noop'] ) ) {
			$preview['code'] = 'invalid_request';
			return $preview;
		}
		if ( isset( $prepared['code'] ) ) {
			$preview['code'] = $prepared['code'];
			$preview['message'] = $this->sanitize_operation_message( $prepared['message'], true );
			return $preview;
		}
		if ( $operation === 'apply' ) {
			if ( $this->operation_target_groups( $prepared['target_files'], $prepared['upload_dir'] ) === false ) {
				$preview['code'] = 'invalid_targets';
				$preview['message'] = __( 'Selected image paths could not be safely prepared.', 'image-watermark' );
				return $preview;
			}
			if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1 ) {
				if ( ! $this->watermark_source_is_available( $prepared['options'], $prepared['upload_dir'] ) ) {
					$preview['code'] = 'watermark_source_missing';
					$preview['message'] = __( 'Watermark not applied: the watermark source image file is missing from the server. Please re-select a watermark image in settings.', 'image-watermark' );
					return $preview;
				}
				$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
				$backup = $this->describe_backup_path( is_string( $attached ) && $attached !== '' ? $attached : $data['file'] );
				if ( $backup['code'] !== '' && $backup['code'] !== 'backup_missing' ) {
					$preview['code'] = $backup['code'];
					$preview['message'] = $backup['message'];
					return $preview;
				}
				if ( $backup['path'] === false || ! $this->is_valid_backup_file( $backup['path'] ) ) {
					$preview['code'] = 'backup_missing';
					$preview['message'] = __( 'Watermark not applied because the original backup is missing.', 'image-watermark' );
					return $preview;
				}
			}
			$preview['sizes'] = array_map( 'strval', array_keys( $prepared['target_files'] ) );
		} else {
			$preview['sizes'] = array_merge( [ 'full' ], isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? array_map( 'strval', array_keys( $data['sizes'] ) ) : [] );
		}
		$preview['eligible'] = true;
		return $preview;
	}

	/**
	 * Dispatch an ordinary operation while preserving the legacy getter across
	 * nested calls and calls which do not produce an outcome.
	 *
	 * @internal
	 * @return array{legacy_result:mixed,outcome:array}
	 */
	public function execute_operation( $operation, $attachment_id, $request = [], $execution = [], $regenerate_context = 'manual-regenerate' ) {
		$captured = [];
		$no_work = null;
		$runner_options = null;
		return $this->dispatch_operation_with_capture( $operation, $attachment_id, $request, $execution, $captured, $no_work, $runner_options, $regenerate_context );
	}

	/** Dispatch one runner attempt and report request-local proof of no operation work. @internal */
	public function execute_runner_operation( $operation, $attachment_id, $request, $execution, &$runner_options = null ) {
		$captured = [];
		$no_work = false;
		$result = $this->dispatch_operation_with_capture( $operation, $attachment_id, $request, $execution, $captured, $no_work, $runner_options );
		return [ 'result' => $result, 'no_work' => $no_work ];
	}

	/** Keep the unnormalized outcome local to the caller that owns this dispatch. */
	private function dispatch_operation_with_capture( $operation, $attachment_id, $request, $execution, &$captured, &$no_work = null, &$runner_options = null, $regenerate_context = 'manual-regenerate' ) {
		$captured = [];
		$previous_lookup = $this->preview_lookup_read_only;
		$this->preview_lookup_read_only = false;
		try {
			return $this->execute_operation_dispatch( $operation, $attachment_id, $request, $execution, $captured, $no_work, $runner_options, $regenerate_context );
		} finally {
			$this->preview_lookup_read_only = $previous_lookup;
		}
	}

	/** Dispatch with ordinary lookup policy, even inside a preview callback. */
	private function execute_operation_dispatch( $operation, $attachment_id, $request, $execution, &$captured, &$no_work, &$runner_options, $regenerate_context ) {
		if ( ! in_array( $operation, [ 'apply', 'remove', 'regenerate' ], true ) || ! is_array( $execution ) || ! in_array( $regenerate_context, [ 'manual-regenerate', 'bulk-regenerate' ], true ) ) {
			throw new InvalidArgumentException( 'Unsupported operation or execution input.' );
		}
		$invocation = null;
		if ( $execution !== [] ) {
			$identity = $this->normalize_operation_identity( [ 'run' => isset( $execution['run'] ) ? $execution['run'] : null, 'attempt' => isset( $execution['attempt'] ) ? $execution['attempt'] : null ] );
			if ( count( $execution ) !== 3 || $identity === false || ! isset( $execution['revalidate'] ) || ! is_callable( $execution['revalidate'] ) || strlen( serialize( [ 'operation' => $operation, 'retryable' => true, 'run' => $identity['run'], 'attempt' => $identity['attempt'] ] ) ) > 256 || ! is_int( $attachment_id ) || $attachment_id < 1 || ! is_array( $request ) || ( $operation !== 'regenerate' && ( ! isset( $request['method'] ) || $request['method'] !== 'manual' ) ) || ( $operation === 'regenerate' && ! empty( $request['dry_run'] ) ) ) {
				throw new InvalidArgumentException( 'Unsupported operation or execution input.' );
			}
			$invocation = (object) [ 'operation' => $operation, 'attachment_id' => $attachment_id, 'site_id' => get_current_blog_id(), 'identity' => $identity, 'revalidate' => $execution['revalidate'], 'time_origin' => microtime( true ), 'operation_state' => null ];
			$invocation->runner_options =& $runner_options;
		}
		if ( $operation !== 'regenerate' && ! is_array( $request ) ) {
			throw new InvalidArgumentException( 'Invalid operation request.' );
		}
		$previous = $this->last_operation_outcome;
		$nested = $this->operation_dispatch_depth > 0;
		++$this->operation_dispatch_depth;
		$this->last_operation_outcome = [];
		$produced = [];
		$getter_outcome = [];
		try {
			if ( $invocation !== null ) {
				$token = $this->operation_token();
				$lock = false;
				$lease_read_error = false;
				for ( $attempt = 0; $attempt < 3 && $lock === false && ! $lease_read_error; ++$attempt ) {
					if ( $attempt > 0 ) { sleep( 2 ); }
					$lock = $this->acquire_operation_lock( $attachment_id, $token, $lease_read_error );
				}
				if ( $lock === false ) {
					$no_work = $lease_read_error ? 'lease_read_error' : 'busy';
					$produced = $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true );
					return [ 'legacy_result' => [ 'error' => $produced['message'], 'outcome' => $produced ], 'outcome' => $this->contract_operation_outcome( $operation, $produced, $invocation->identity ) ];
				}
				$invocation->operation_state = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock, 'journal' => null, 'invocation' => $invocation ];
				if ( ! $this->revalidate_contract_invocation( $invocation, 'before_predecessor' ) ) {
					$no_work = 'before_predecessor';
					$produced = $this->operation_result( 'failed', $token, 'iw_run_state_conflict', '', true );
					return [ 'legacy_result' => [ 'error' => $produced['message'], 'outcome' => $produced ], 'outcome' => $this->contract_operation_outcome( $operation, $produced, $invocation->identity ) ];
				}
			}
			if ( $operation === 'regenerate' ) {
				$result = $this->perform_regenerate_attachment( $attachment_id, $request, $invocation, $regenerate_context );
			} else {
				$data = array_key_exists( 'metadata', $request ) ? $request['metadata'] : wp_get_attachment_metadata( $attachment_id, false );
				$method = array_key_exists( 'method', $request ) ? $request['method'] : '';
				$result = $operation === 'apply'
					? $this->perform_apply_watermark( $data, $attachment_id, $method, $invocation )
					: $this->perform_remove_watermark( $data, $attachment_id, $method, array_key_exists( 'options', $request ) ? $request['options'] : null, $invocation );
			}
			$getter_outcome = $this->last_operation_outcome;
			$produced = is_array( $result ) && isset( $result['outcome'] ) && is_array( $result['outcome'] ) ? $result['outcome'] : $getter_outcome;
			$captured = $produced;
			return [ 'legacy_result' => $result, 'outcome' => $this->contract_operation_outcome( $operation, $produced, $invocation !== null ? $invocation->identity : [] ) ];
		} finally {
			if ( $invocation !== null && $invocation->operation_state !== null ) {
				$this->release_operation_lock( $invocation->operation_state );
			}
			--$this->operation_dispatch_depth;
			$this->last_operation_outcome = $nested || empty( $getter_outcome ) ? $previous : $getter_outcome;
		}
	}

	/** Revalidation is request-local and cannot grant authority on failure. */
	private function revalidate_contract_invocation( $invocation, $boundary ) {
		if ( get_current_blog_id() !== $invocation->site_id ) { return false; }
		try {
			return call_user_func( $invocation->revalidate, $boundary, $invocation->attachment_id, $invocation->identity ) === true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Make a working copy whose renewed lease and journal remain owned by dispatch. */
	private function borrow_contract_operation( $invocation ) {
		$operation = $invocation->operation_state;
		$operation['lock'] =& $invocation->operation_state['lock'];
		$operation['journal'] =& $invocation->operation_state['journal'];
		$operation['time_origin'] = $invocation->time_origin;
		return $operation;
	}

	/** @return array|false Validated run identity, or false. */
	private function normalize_operation_identity( $identity ) {
		if ( $identity === [] ) {
			return [];
		}
		if ( ! is_array( $identity ) || count( $identity ) !== 2 || ! isset( $identity['run'], $identity['attempt'] ) || ! is_string( $identity['run'] ) || $identity['run'] === '' || strlen( $identity['run'] ) > 64 || ! preg_match( '/^[\x00-\x7F]+$/D', $identity['run'] ) || ! is_int( $identity['attempt'] ) || $identity['attempt'] < 1 || strlen( serialize( $identity ) ) > 128 ) {
			return false;
		}
		return [ 'run' => $identity['run'], 'attempt' => $identity['attempt'] ];
	}

	/**
	 * Format a read-only scan result without publishing attachment state.
	 *
	 * @internal
	 * @return array
	 */
	public function read_only_operation_result( $operation, $outcome, $code, $message, $sizes = [], $identity = [] ) {
		$identity = $this->normalize_operation_identity( $identity );
		if ( $operation !== 'scan' || $identity === false || ! in_array( $outcome, [ 'complete', 'skipped', 'failed', 'partial', 'interrupted' ], true ) ) {
			throw new InvalidArgumentException( 'Invalid read-only operation result.' );
		}
		return $this->contract_operation_outcome( $operation, [ 'outcome' => $outcome, 'code' => $code, 'message' => $message, 'sizes' => $sizes ], $identity );
	}

	/**
	 * Inspect an attachment for a library scan without acquiring a lease.
	 *
	 * @internal
	 * @return array
	 */
	public function inspect_attachment_for_scan( $attachment_id, $request = [], $identity = [], $runner_options = null ) {
		if ( $runner_options !== null && ! is_array( $runner_options ) ) { throw new InvalidArgumentException( 'Invalid runner scan options.' ); }
		if ( ! is_int( $attachment_id ) || $attachment_id < 1 || ! is_array( $request ) || array_diff( array_keys( $request ), [ 'depth', 'deadline', 'authorization' ] ) || $this->normalize_operation_identity( $identity ) === false ) {
			throw new InvalidArgumentException( 'Invalid scan request.' );
		}
		$depth = array_key_exists( 'depth', $request ) ? $request['depth'] : 'header';
		$deadline = array_key_exists( 'deadline', $request ) ? $request['deadline'] : null;
		if ( ! in_array( $depth, [ 'header', 'full' ], true ) || ( $deadline !== null && ( ( ! is_int( $deadline ) && ! is_float( $deadline ) ) || ! is_finite( (float) $deadline ) ) ) ) {
			throw new InvalidArgumentException( 'Invalid scan request.' );
		}
		if ( array_key_exists( 'authorization', $request ) ) {
			$auth = $request['authorization'];
			if ( ! is_array( $auth ) || count( $auth ) !== 2 || ! isset( $auth['site_id'], $auth['user_id'] ) || ! is_int( $auth['site_id'] ) || $auth['site_id'] < 1 || ! is_int( $auth['user_id'] ) || $auth['user_id'] < 1 ) {
				throw new InvalidArgumentException( 'Invalid scan authorization context.' );
			}
		}
		$started = microtime( true );
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 ) {
			$origin = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $started;
			$available_until = $origin + $maximum - 2;
			$deadline = $deadline === null ? $available_until : min( $deadline, $available_until );
		}
		$unknown = $this->scan_fact( 'unknown', null, null, 'not_measured' );
		$unknown['observed_at'] = null;
		$source = array_fill_keys( [ 'availability', 'kind', 'authority', 'decode', 'dimensions' ], $unknown );
		$facts = [ 'version' => 1, 'attachment_id' => $attachment_id, 'depth' => $depth, 'started_at' => (int) $started, 'finished_at' => null, 'elapsed_ms' => 0, 'complete' => false, 'consistency' => 'unknown', 'eligibility' => $unknown, 'watermarked' => $unknown, 'backup' => array_fill_keys( [ 'availability', 'identity_matches_main', 'decode', 'bytes' ], $unknown ), 'clean_source' => array_fill_keys( [ 'availability', 'kind' ], $unknown ), 'sources' => [ 'main' => $source, 'generation' => $source ], 'provenance' => $unknown, 'manifest' => $unknown, 'sizes' => [], 'drift' => array_fill_keys( [ 'state', 'current', 'verifiable', 'last_verified', 'mismatch_count', 'mismatches' ], $unknown ), 'operation' => array_fill_keys( [ 'state', 'type', 'incomplete', 'main_recovery_retained' ], $unknown ), 'client_side' => array_fill_keys( [ 'state', 'code', 'unfinalized_replacement' ], $unknown ), 'limits' => [ 'registered_total' => null, 'registered_measured' => null, 'registered_unavailable' => null, 'registered_unmeasured' => null, 'files_observed' => 0, 'hash_bytes' => 0, 'full_backup_attempted' => false, 'deadline' => $deadline, 'stopped_reason' => '' ] ];
		$outcome = 'partial';
		$code = 'scan_unmeasurable';
		if ( ( isset( $auth ) && ( $auth['site_id'] !== get_current_blog_id() || $auth['user_id'] !== get_current_user_id() ) ) || ! current_user_can( 'upload_files' ) ) {
			$outcome = 'failed';
			$code = 'scan_forbidden';
		} else {
			global $wpdb;
			$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ), ARRAY_A );
			if ( $wpdb->last_error !== '' ) {
				$this->mark_unmeasured_scan_facts( $facts, 'query_failed' );
				$code = 'scan_incomplete';
			} elseif ( ! $post || $post['post_type'] !== 'attachment' ) {
				$outcome = 'skipped';
				$code = 'attachment_not_found';
			} elseif ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$outcome = 'failed';
				$code = 'scan_forbidden';
			} elseif ( ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
				$outcome = 'skipped';
				$code = 'scan_not_applicable';
				$na = $this->scan_fact( 'not_applicable' );
				foreach ( [ 'backup', 'clean_source', 'drift' ] as $key ) { foreach ( $facts[ $key ] as &$fact ) { $fact = $na; } unset( $fact ); }
				foreach ( $facts['sources'] as &$source_facts ) { foreach ( $source_facts as &$fact ) { $fact = $na; } unset( $fact ); } unset( $source_facts );
				$snapshot = $this->read_scan_snapshot( $attachment_id );
				if ( $snapshot !== false ) {
					$options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
					$flag = $this->scan_snapshot_value( $snapshot, 'iw-is-watermarked', $flag_valid );
					$journal = $this->scan_snapshot_value( $snapshot, '_iw_operation_journal', $journal_valid );
					$manifest = $this->scan_snapshot_value( $snapshot, self::MANIFEST_META_KEY, $manifest_valid );
					if ( $flag_valid && $journal_valid && $manifest_valid && ( $journal === '' || $this->is_valid_operation_journal( $journal ) ) ) {
						if ( $journal === '' ) { $journal = [ 'version' => 1, 'current' => null, 'recoverable_predecessor' => null, 'history' => [] ]; }
						$status = $this->manifest_status_from_values( $manifest, $journal, (int) $flag === 1, $options );
						$facts['manifest'] = $this->scan_fact( 'known', $status['status'], 'stored', $status['reason'] );
					}
					$stored = $this->scan_stored_facts( $attachment_id, $snapshot, $facts['manifest'] );
					foreach ( [ 'watermarked', 'operation', 'client_side' ] as $key ) { $facts[ $key ] = $stored[ $key ]; }
					if ( ! $journal_valid || ( $journal !== '' && ! $this->is_valid_operation_journal( $journal ) ) ) {
						$facts['manifest'] = $this->scan_fact( 'unknown', null, null, 'invalid_journal' );
					} elseif ( ! $flag_valid || ! $manifest_valid ) {
						$facts['manifest'] = $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
					}
					$closing_options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
					$closing = $this->read_scan_snapshot( $attachment_id );
					$lease = $this->scan_snapshot_lease_state( $snapshot );
					$reason = '';
					if ( $closing === false ) { $reason = 'query_failed'; }
					elseif ( $snapshot !== $closing || $options !== $closing_options || $lease === 'live' ) { $reason = 'concurrent_change'; $facts['consistency'] = 'changed'; }
					elseif ( $lease === 'unreadable' ) { $reason = 'lease_unreadable'; }
					else { $facts['consistency'] = 'not_observed'; }
					if ( $reason !== '' ) {
						// Preserve invalid stored evidence, but do not present a stale known
						// value as current when its protected inputs could have changed.
						$invalidate = function( &$value ) use ( &$invalidate, $reason ) {
							if ( ! is_array( $value ) ) { return; }
							if ( isset( $value['state'], $value['reason'] ) ) {
								if ( $value['state'] === 'known' ) { $value = $this->scan_fact( 'unknown', null, null, $reason, true ); }
								return;
							}
							foreach ( $value as &$child ) { $invalidate( $child ); } unset( $child );
						};
						foreach ( [ 'watermarked', 'manifest', 'operation', 'client_side' ] as $key ) { $invalidate( $facts[ $key ] ); }
						$outcome = 'partial';
						$code = $reason === 'concurrent_change' ? 'scan_concurrent_change' : 'scan_incomplete';
					}
				} else {
					$this->mark_unmeasured_scan_facts( $facts, 'query_failed' );
					$outcome = 'partial';
					$code = 'scan_incomplete';
				}
			} elseif ( $deadline !== null && microtime( true ) >= $deadline ) {
				$outcome = 'interrupted';
				$code = 'scan_interrupted';
				$facts['limits']['stopped_reason'] = 'deadline';
				$unmeasured = $this->scan_fact( 'unknown', null, null, 'deadline', true );
				$unmeasured['observed_at'] = null;
				$registered = wp_get_registered_image_subsizes();
				foreach ( $registered as $name => $definition ) { $facts['sizes'][ $name ] = array_fill_keys( [ 'exists', 'expected', 'recorded', 'state', 'shared_aliases' ], $unmeasured ); }
				$facts['limits']['registered_total'] = count( $registered );
				$facts['limits']['registered_measured'] = 0;
				$facts['limits']['registered_unavailable'] = 0;
				$facts['limits']['registered_unmeasured'] = count( $registered );
			} else {
				$previous_lookup = $this->preview_lookup_read_only;
				$this->preview_lookup_read_only = true;
				try {
					$opening = $this->read_scan_snapshot( $attachment_id );
					if ( $opening === false ) {
						$this->mark_unmeasured_scan_facts( $facts, 'query_failed' );
						$facts['backup'] = $this->scan_backup_unknown( 'query_failed', true );
						$code = 'scan_incomplete';
					} else {
						$source_files = [];
						$rows = array_values( array_filter( $opening['meta'], static function( $row ) { return $row['meta_key'] === '_wp_attached_file'; } ) );
						if ( count( $rows ) !== 1 || ! is_string( $rows[0]['meta_value'] ) || $rows[0]['meta_value'] === '' ) {
							$facts['backup'] = $this->scan_backup_unknown( 'invalid_metadata', false );
						} else {
							$facts['backup'] = $this->scan_backup_facts( $rows[0]['meta_value'], $depth, $deadline, $facts['limits'], $source_files );
							if ( $facts['backup']['availability']['retryable'] || $facts['backup']['decode']['retryable'] ) { $code = 'scan_incomplete'; }
						}
						$options = $runner_options !== null ? $runner_options : $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
						$generation_path = null;
						$projections = $this->scan_source_facts( $opening, $facts['backup'], $depth, $deadline, $facts['limits'], $source_files, $generation_path, $options );
						$facts = array_merge( $facts, $projections );
						$facts = array_merge( $facts, $this->scan_stored_facts( $attachment_id, $opening, $facts['manifest'] ) );
						$registered = wp_get_registered_image_subsizes();
						$dimensions = $this->scan_generation_dimensions( $generation_path, $facts['sources']['generation'], $depth, $deadline );
						$facts['sources']['generation']['dimensions'] = $facts['sources']['generation']['availability']['value'] === 'absent' ? $this->scan_fact( 'not_applicable' ) : $dimensions;
						$metadata = $this->scan_snapshot_value( $opening, '_wp_attachment_metadata', $valid_metadata );
						$facts['sizes'] = $this->scan_size_facts( $valid_metadata ? $metadata : null, $registered, $dimensions, $deadline, $source_files );
						$watermark_snapshot = null;
						$facts['eligibility'] = $this->scan_reapply_eligibility( $attachment_id, $opening, $facts['backup'], $options, $depth, $deadline, $source_files, $watermark_snapshot, $facts['sizes'] );
						if ( $facts['client_side']['unfinalized_replacement']['state'] === 'known' && $facts['client_side']['unfinalized_replacement']['value'] ) {
							$facts['eligibility'] = $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'client_side_upload_unfinalized' ], 'stored' );
						}
						if ( $runner_options !== null ) {
							$closing_options = null;
							$this->runner_fingerprints( $closing_options );
						} else {
							$closing_options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
						}
						$closing = $this->read_scan_snapshot( $attachment_id );
						$watermark_closing = $watermark_snapshot === null ? null : $this->read_scan_snapshot( (int) $watermark_snapshot['post']['ID'] );
						if ( $closing === false ) {
							$facts['backup'] = $this->scan_backup_unknown( 'query_failed', true );
							$this->invalidate_scan_sources( $facts, 'query_failed' );
							$code = 'scan_incomplete';
						} elseif ( $watermark_snapshot !== null && $watermark_closing === false ) {
							$this->invalidate_scan_sources( $facts, 'query_failed' );
							$code = 'scan_incomplete';
						} elseif ( $opening !== $closing || $watermark_snapshot !== $watermark_closing || $options !== $closing_options || $registered !== wp_get_registered_image_subsizes() || ! $this->scan_source_files_unchanged( $source_files ) || $this->scan_snapshot_lease_state( $opening ) === 'live' ) {
							$facts['backup'] = $this->scan_backup_unknown( 'concurrent_change', true );
							$this->invalidate_scan_sources( $facts, 'concurrent_change' );
							$facts['consistency'] = 'changed';
							$code = 'scan_concurrent_change';
						} elseif ( $this->scan_snapshot_lease_state( $opening ) === 'unreadable' ) {
							$facts['backup'] = $this->scan_backup_unknown( 'lease_unreadable', true );
							$this->invalidate_scan_sources( $facts, 'lease_unreadable' );
							$code = 'scan_incomplete';
						}
						if ( $facts['consistency'] === 'unknown' && $closing !== false && ( $watermark_snapshot === null || $watermark_closing !== false ) && $code !== 'scan_incomplete' ) { $facts['consistency'] = 'not_observed'; }
						// Unknown registered states are not zero-valued measurements.
						$facts['limits']['files_observed'] = count( $source_files );
						$facts['limits']['registered_total'] = count( $facts['sizes'] );
						$facts['limits']['registered_measured'] = 0;
						$facts['limits']['registered_unavailable'] = 0;
						$facts['limits']['registered_unmeasured'] = 0;
						foreach ( $facts['sizes'] as $size ) {
							$state = $size['state'];
							if ( $state['state'] === 'known' ) {
								++$facts['limits']['registered_measured'];
							} elseif ( $state['retryable'] ) {
								++$facts['limits']['registered_unmeasured'];
								if ( $code !== 'scan_concurrent_change' ) { $code = 'scan_incomplete'; }
							} else {
								++$facts['limits']['registered_unavailable'];
							}
						}
						// Only measured applicable facts can make a scan complete. The
						// not-requested recovery hash is a deliberate header-depth omission.
						$unknowns = [];
						$collect = static function ( $value ) use ( &$collect, &$unknowns ) {
							if ( ! is_array( $value ) ) { return; }
							if ( isset( $value['state'], $value['retryable'], $value['reason'] ) && $value['state'] === 'unknown' ) {
								if ( $value['reason'] !== 'not_requested' ) { $unknowns[] = $value; }
								return;
							}
							foreach ( $value as $child ) { $collect( $child ); }
						};
						foreach ( [ 'backup', 'clean_source', 'sources', 'manifest', 'recovery', 'sizes', 'eligibility', 'watermarked', 'drift', 'client_side', 'operation', 'edited' ] as $key ) { if ( isset( $facts[ $key ] ) ) { $collect( $facts[ $key ] ); } }
						$collect( $facts['provenance'] );
						if ( $code !== 'scan_concurrent_change' && $code !== 'scan_incomplete' ) {
							$code = 'scanned'; $outcome = 'complete';
							foreach ( $unknowns as $unknown_fact ) {
								$outcome = 'partial';
								if ( $unknown_fact['retryable'] ) { $code = 'scan_incomplete'; break; }
								$code = 'scan_unmeasurable';
							}
						}
						if ( $code !== 'scan_concurrent_change' && $deadline !== null && microtime( true ) >= $deadline ) {
							$outcome = 'interrupted';
							$code = 'scan_interrupted';
							$facts['limits']['stopped_reason'] = 'deadline';
						}
					}
				} finally {
					$this->preview_lookup_read_only = $previous_lookup;
				}
			}
		}
		if ( $code === 'scan_interrupted' ) {
			$this->mark_unmeasured_scan_facts( $facts, 'deadline' );
		}
		$facts['complete'] = $outcome === 'complete';
		$finished = microtime( true );
		$facts['finished_at'] = (int) $finished;
		$facts['elapsed_ms'] = max( 0, (int) round( ( $finished - $started ) * 1000 ) );
		return [ 'outcome' => $this->read_only_operation_result( 'scan', $outcome, $code, '', [], $identity ), 'facts' => $facts ];
	}

	/** Assign the stop cause only to facts for which no observation was made. */
	private function mark_unmeasured_scan_facts( &$value, $reason ) {
		if ( ! is_array( $value ) ) { return; }
		if ( isset( $value['state'], $value['reason'] ) && $value['state'] === 'unknown' && $value['reason'] === 'not_measured' ) {
			$value = $this->scan_fact( 'unknown', null, null, $reason, true );
			$value['observed_at'] = null;
			return;
		}
		foreach ( $value as &$child ) { $this->mark_unmeasured_scan_facts( $child, $reason ); }
		unset( $child );
	}

	/** Capture raw authoritative rows without collapsing duplicates or populating caches. */
	private function read_scan_snapshot( $attachment_id ) {
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $attachment_id ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) { return false; }
		$keys = [ '_wp_attached_file', '_wp_attachment_metadata', 'iw-is-watermarked', '_wp_attachment_backup_sizes', self::PROVENANCE_META_KEY, self::MANIFEST_META_KEY, self::DRIFT_META_KEY, '_iw_operation_journal', '_iw_last_operation', self::CLIENT_SIDE_CLAIM_META_KEY, self::CLIENT_SIDE_PAYLOAD_META_KEY, self::CLIENT_SIDE_PROVENANCE_META_KEY ];
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$query = "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders) ORDER BY meta_id";
		$meta = $wpdb->get_results( $wpdb->prepare( $query, array_merge( [ $attachment_id ], $keys ) ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) { return false; }
		$options = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s) ORDER BY option_id", $this->operation_lock_name( $attachment_id ), 'image_watermark_options' ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) { return false; }
		return [ 'post' => $post, 'meta' => $meta, 'options' => $options ];
	}

	/** A scan never reclaims even an expired lease. */
	private function scan_snapshot_lease_state( $snapshot ) {
		if ( ! is_array( $snapshot['post'] ) || ! isset( $snapshot['post']['ID'] ) ) { return 'unreadable'; }
		$name = $this->operation_lock_name( (int) $snapshot['post']['ID'] );
		$rows = array_values( array_filter( $snapshot['options'], static function( $row ) use ( $name ) { return $row['option_name'] === $name; } ) );
		if ( ! $rows ) { return 'absent'; }
		if ( count( $rows ) !== 1 ) { return 'unreadable'; }
		$lease = maybe_unserialize( $rows[0]['option_value'] );
		if ( ! is_array( $lease ) || empty( $lease['token'] ) || empty( $lease['heartbeat_at'] ) || ! is_numeric( $lease['heartbeat_at'] ) ) { return 'unreadable'; }
		return (int) $lease['heartbeat_at'] > time() - 900 ? 'live' : 'expired';
	}

	/** Return a singleton's decoded value, retaining duplicate-row uncertainty. */
	private function scan_snapshot_value( $snapshot, $key, &$valid ) {
		$rows = array_values( array_filter( $snapshot['meta'], static function( $row ) use ( $key ) { return $row['meta_key'] === $key; } ) );
		$valid = count( $rows ) <= 1;
		return $rows ? maybe_unserialize( $rows[0]['meta_value'] ) : '';
	}

	/**
	 * Read the scanner's stored-only facts for a runner membership recheck.
	 * No files, leases, settlement or diagnostic verification are touched.
	 *
	 * @internal
	 */
	public function runner_stored_facts( $id, $expected = null ) {
		if ( ! is_int( $id ) || $id < 1 || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) { return false; }
		$snapshot = $this->read_scan_snapshot( $id );
		if ( $snapshot === false || ! is_array( $snapshot['post'] ) ) { return false; }
		$options = null;
		if ( $expected !== null ) {
			$fingerprints = $this->runner_fingerprints( $options );
			if ( $fingerprints === false ) { return false; }
			if ( $fingerprints !== $expected ) { return 'settings_changed'; }
		} else { $options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) ); }
		$facts = $this->runner_project_stored_facts( $id, $snapshot, $options );
		$closing = $this->read_scan_snapshot( $id );
		$closing_options = null;
		if ( $expected !== null ) {
			$fingerprints = $this->runner_fingerprints( $closing_options );
			if ( $fingerprints === false ) { return false; }
			if ( $fingerprints !== $expected ) { return 'settings_changed'; }
		} else { $closing_options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) ); }
		if ( $closing === false || $closing !== $snapshot || $closing_options !== $options || ! in_array( $this->scan_snapshot_lease_state( $snapshot ), [ 'absent', 'expired' ], true ) ) { return false; }
		return $facts;
	}

	/**
	 * Capture two bounded authoritative page snapshots, refusing an unstable page.
	 * Excess metadata is unmeasured, never a truncated negative observation.
	 *
	 * @internal
	 */
	public function runner_stored_facts_batch( $ids, $deadline, $expected = null ) {
		global $wpdb;
		if ( ! is_array( $ids ) || count( $ids ) > 100 ) { return false; }
		if ( ! $ids ) { return []; }
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) { return false; }
		}
		$ids = array_values( array_unique( $ids ) );
		$keys = [ '_wp_attached_file', '_wp_attachment_metadata', 'iw-is-watermarked', '_wp_attachment_backup_sizes', self::PROVENANCE_META_KEY, self::MANIFEST_META_KEY, self::DRIFT_META_KEY, '_iw_operation_journal', '_iw_last_operation', self::CLIENT_SIDE_CLAIM_META_KEY, self::CLIENT_SIDE_PAYLOAD_META_KEY, self::CLIENT_SIDE_PROVENANCE_META_KEY ];
		$id_slots = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$key_slots = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$names = [ 'image_watermark_options' ];
		foreach ( $ids as $id ) { $names[] = $this->operation_lock_name( $id ); }
		$name_slots = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$read = function() use ( $wpdb, $ids, $keys, $id_slots, $key_slots, $names, $name_slots, $deadline ) {
			if ( microtime( true ) >= $deadline ) { return null; }
			$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_mime_type FROM {$wpdb->posts} WHERE ID IN ($id_slots)", $ids ), ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $posts ) ) { return false; }
			$limit = count( $ids ) * 64;
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_slots) AND meta_key IN ($key_slots) ORDER BY meta_id LIMIT %d", array_merge( $ids, $keys, [ $limit + 1 ] ) ), ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $meta ) || count( $meta ) > $limit ) { return false; }
			$options = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($name_slots) ORDER BY option_id", $names ), ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $options ) ) { return false; }
			$snapshots = [];
			foreach ( $ids as $id ) { $snapshots[ $id ] = [ 'post' => null, 'meta' => [], 'options' => [] ]; }
			foreach ( $posts as $post ) { $snapshots[ (int) $post['ID'] ]['post'] = $post; }
			foreach ( $meta as $row ) {
				if ( microtime( true ) >= $deadline ) { return null; }
				$id = (int) $row['post_id']; unset( $row['post_id'] );
				$snapshots[ $id ]['meta'][] = $row;
			}
			foreach ( $ids as $id ) {
				$lock = $this->operation_lock_name( $id );
				foreach ( $options as $row ) { if ( $row['option_name'] === $lock || $row['option_name'] === 'image_watermark_options' ) { $snapshots[ $id ]['options'][] = $row; } }
			}
			return $snapshots;
		};
		$opening = $read();
		if ( $opening === false || $opening === null ) { return $opening; }
		$options = null;
		if ( $expected !== null ) {
			$fingerprints = $this->runner_fingerprints( $options );
			if ( $fingerprints === false ) { return false; }
			if ( $fingerprints !== $expected ) { return 'settings_changed'; }
		} else { $options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) ); }
		$facts = [];
		foreach ( $ids as $id ) {
			if ( microtime( true ) >= $deadline ) { return null; }
			if ( ! is_array( $opening[ $id ]['post'] ) || ! in_array( $this->scan_snapshot_lease_state( $opening[ $id ] ), [ 'absent', 'expired' ], true ) ) { return false; }
			$facts[ $id ] = $this->runner_project_stored_facts( $id, $opening[ $id ], $options );
			if ( $facts[ $id ] === false ) { return false; }
		}
		$closing = $read();
		if ( $closing === null ) { return null; }
		$closing_options = null;
		if ( $expected !== null ) {
			$fingerprints = $this->runner_fingerprints( $closing_options );
			if ( $fingerprints === false ) { return false; }
			if ( $fingerprints !== $expected ) { return 'settings_changed'; }
		} else { $closing_options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) ); }
		if ( $closing === false || $closing !== $opening || $closing_options !== $options ) { return false; }
		return $facts;
	}

	/** Stored/settings gate observations, not a prediction of the first execution refusal. */
	public function runner_settings_gates( $id, $options ) {
		if ( ! is_array( $options ) || ! isset( $options['watermark_image'] ) || ! is_array( $options['watermark_image'] ) ) { return false; }
		$image = $options['watermark_image'];
		$type = isset( $image['type'] ) ? $image['type'] : 'image';
		return [
			'no_engine' => ! $this->plugin->get_extension(),
			'rotation_unavailable' => ! $this->describe_rotation_capability( $options )['available'],
			'is_watermark_source' => $id === ( isset( $image['url'] ) ? (int) $image['url'] : 0 ),
			'manual_disabled' => empty( $image['manual_watermarking'] ),
			'invalid_custom_dimensions' => ! $this->has_valid_custom_watermark_dimensions( $options ),
			'watermark_text_empty' => $type === 'text' && empty( trim( isset( $image['text_string'] ) ? $image['text_string'] : '' ) ),
			'no_selected_sizes' => ! isset( $options['watermark_on'] ) || ! is_array( $options['watermark_on'] ) || ! array_filter( $options['watermark_on'], static function( $selected ) { return (int) $selected === 1; } ),
		];
	}

	/** Site-wide settings failures only; attachment-specific vetoes remain per item. */
	public function runner_site_settings_refusal( $options, $operation ) {
		if ( $operation === 'scan' ) { return ''; }
		if ( ! $this->plugin->get_extension() ) { return 'no_engine'; }
		$gates = $this->runner_settings_gates( 0, $options );
		if ( $gates === false ) { return ''; }
		if ( $gates['manual_disabled'] ) { return 'manual_disabled'; }
		if ( $operation === 'remove' ) { return ''; }
		$image = $options['watermark_image'];
		$type = isset( $image['type'] ) ? $image['type'] : 'image';
		if ( $gates['rotation_unavailable'] || $gates['invalid_custom_dimensions'] || $gates['watermark_text_empty'] || $gates['no_selected_sizes'] ) { return 'validation_failed'; }
		if ( $type === 'image' && ! wp_attachment_is_image( isset( $image['url'] ) ? (int) $image['url'] : 0 ) ) { return 'validation_failed'; }
		if ( $type === 'text' ) {
			$font = isset( $image['text_font'] ) ? $image['text_font'] : 'Lato-Regular.ttf';
			$path = $this->plugin->get_font_path( $font );
			if ( ! $path || ! file_exists( $path ) ) { return 'validation_failed'; }
		}
		return '';
	}

	/** Project an authoritative snapshot using the scanner's existing validators. */
	private function runner_project_stored_facts( $id, $snapshot, $options ) {
		$flag = $this->scan_snapshot_value( $snapshot, 'iw-is-watermarked', $flag_valid );
		if ( ! $flag_valid || ! is_scalar( $flag ) ) { return false; }
		$journal = $this->scan_snapshot_value( $snapshot, '_iw_operation_journal', $journal_valid );
		$manifest = $this->scan_snapshot_value( $snapshot, self::MANIFEST_META_KEY, $manifest_valid );
		$status = $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
		if ( $flag_valid && is_scalar( $flag ) && $journal_valid && $manifest_valid && ( $journal === '' || $this->is_valid_operation_journal( $journal ) ) ) {
			if ( $journal === '' ) { $journal = [ 'version' => 1, 'current' => null, 'recoverable_predecessor' => null, 'history' => [] ]; }
			$description = $this->manifest_status_from_values( $manifest, $journal, (int) $flag === 1, $options );
			$status = $this->scan_fact( 'known', $description['status'], 'stored' );
		}
		$facts = $this->scan_stored_facts( $id, $snapshot, $status );
		$facts['manifest'] = $status;
		$metadata = $this->scan_snapshot_value( $snapshot, '_wp_attachment_metadata', $metadata_valid );
		$facts['runner_metadata'] = $metadata_valid ? $this->scan_fact( 'known', is_array( $metadata ) && ! empty( $metadata['file'] ) && is_string( $metadata['file'] ), 'stored' ) : $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
		$facts['runner_regenerate_metadata'] = $metadata_valid ? $this->scan_fact( 'known', is_array( $metadata ), 'stored' ) : $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
		$payload_rows = array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::CLIENT_SIDE_PAYLOAD_META_KEY; } );
		$facts['runner_deferred_payload'] = $this->scan_fact( 'known', (bool) $payload_rows, 'stored' );
		return $facts;
	}

	/** Project captured stored evidence without verification, settlement or cache reads. */
	private function scan_stored_facts( $id, $snapshot, $manifest ) {
		$unknown = $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
		$flag = $this->scan_snapshot_value( $snapshot, 'iw-is-watermarked', $flag_valid );
		$watermarked = $flag_valid ? $this->scan_fact( 'known', (int) $flag === 1, 'stored' ) : $unknown;
		$drift = array_fill_keys( [ 'state', 'current', 'verifiable', 'last_verified', 'mismatch_count', 'mismatches' ], $unknown );
		$rows = array_values( array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::DRIFT_META_KEY; } ) );
		if ( $flag_valid && (int) $flag !== 1 ) {
			$drift = array_fill_keys( array_keys( $drift ), $this->scan_fact( 'not_applicable' ) );
		} elseif ( $flag_valid ) {
			$markers = [];
			foreach ( $rows as $row ) {
				$marker = maybe_unserialize( $row['meta_value'] );
				if ( ! $this->is_valid_drift_marker( $marker ) ) { $markers = false; break; }
				$markers[] = $marker;
			}
			if ( $markers !== false ) {
				$values = [ 'state' => 'none', 'current' => false, 'verifiable' => false, 'last_verified' => null, 'mismatch_count' => 0, 'mismatches' => [] ];
				if ( $markers ) {
					$signal = $markers[0]; $verification = null; $latest = 0;
					foreach ( $markers as $marker ) {
						if ( $marker['last_seen'] > $signal['last_seen'] ) { $signal = $marker; }
						if ( $marker['last_verified'] !== null && ( $verification === null || $marker['last_verified'] > $verification['last_verified'] ) ) { $verification = $marker; }
						$latest = max( $latest, $marker['last_seen'], (int) $marker['last_verified'] );
					}
					$merged = $verification === null ? $signal : array_merge( $signal, array_intersect_key( $verification, array_flip( [ 'state', 'last_verified', 'mismatch_count', 'mismatches' ] ) ) );
					if ( count( $rows ) > 1 ) { $merged['last_seen'] = $latest + 1; }
					foreach ( [ 'state', 'last_verified', 'mismatch_count', 'mismatches' ] as $key ) { $values[ $key ] = $merged[ $key ]; }
					$values['current'] = $this->drift_result_is_current( $merged ) && ! isset( $this->drift_invalidation_pending[ get_current_blog_id() . ':' . $id ] );
					$values['verifiable'] = $manifest['state'] === 'known' && in_array( $manifest['value'], [ 'trusted', 'settings_changed' ], true );
				}
				foreach ( $values as $key => $value ) { $drift[ $key ] = $this->scan_fact( 'known', $value, 'stored' ); }
				if ( $markers && $manifest['state'] !== 'known' ) { $drift['verifiable'] = $manifest; }
			}
		}
		$claim = $this->scan_snapshot_value( $snapshot, self::CLIENT_SIDE_CLAIM_META_KEY, $claim_valid );
		$payload = $this->scan_snapshot_value( $snapshot, self::CLIENT_SIDE_PAYLOAD_META_KEY, $payload_valid );
		$claim_rows = array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::CLIENT_SIDE_CLAIM_META_KEY; } );
		$payload_rows = array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::CLIENT_SIDE_PAYLOAD_META_KEY; } );
		$provenance_rows = array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::CLIENT_SIDE_PROVENANCE_META_KEY; } );
		$client_unknown = $this->scan_fact( 'unknown', null, null, 'invalid_claim' );
		$client = array_fill_keys( [ 'state', 'code', 'unfinalized_replacement' ], $client_unknown );
		if ( $claim_valid && $payload_valid && ( ! $claim_rows || $this->is_valid_client_side_claim( $claim, $id ) ) && ( ! $payload_rows || $this->sanitize_client_side_payload( $payload ) !== false ) ) {
			$active = $claim_rows && ( $claim['state'] === 'pending' || $claim['updated_at'] + self::CLIENT_SIDE_CLAIM_TTL >= time() );
			$state = $active ? $claim['state'] : 'none'; $code = '';
			if ( $state === 'pending' ) {
				if ( $claim['code'] === 'busy' && $payload_rows ) { $code = 'client_side_finalize_deferred'; }
				elseif ( $claim['attempts'] > 0 || $claim['code'] !== '' ) { $code = 'client_side_watermark_retry_pending'; }
				elseif ( $claim['created_at'] + self::CLIENT_SIDE_CLAIM_STALE_AFTER < time() ) { $code = 'client_side_upload_incomplete'; }
				else { $code = 'client_side_upload_pending'; }
			}
			$client['state'] = $this->scan_fact( 'known', $state, 'stored' );
			$client['code'] = $this->scan_fact( 'known', $code, 'stored' );
			$attached = $this->scan_snapshot_value( $snapshot, '_wp_attached_file', $file_valid );
			$metadata = $this->scan_snapshot_value( $snapshot, '_wp_attachment_metadata', $metadata_valid );
			if ( $file_valid && $metadata_valid && is_string( $attached ) && $attached !== '' && is_array( $metadata ) && isset( $metadata['file'] ) && is_string( $metadata['file'] ) && $metadata['file'] !== '' ) {
				$client['unfinalized_replacement'] = $this->scan_fact( 'known', wp_normalize_path( $attached ) !== wp_normalize_path( $metadata['file'] ) && (bool) ( $claim_rows || $payload_rows || $provenance_rows ), 'stored' );
			}
		}
		$journal = $this->scan_snapshot_value( $snapshot, '_iw_operation_journal', $journal_valid );
		$operation = array_fill_keys( [ 'state', 'type', 'incomplete', 'main_recovery_retained' ], $this->scan_fact( 'unknown', null, null, 'invalid_journal' ) );
		if ( $journal_valid && ( $journal === '' || $this->is_valid_operation_journal( $journal ) ) ) {
			$current = $journal === '' ? null : $journal['current'];
			$prior = $journal === '' ? null : $this->select_operation_recovery_predecessor( $journal );
			foreach ( [ 'state' => $current === null ? 'none' : $current['state'], 'type' => $current === null ? null : $current['type'], 'incomplete' => $current !== null && ! $this->operation_is_terminal( $current ), 'main_recovery_retained' => $this->record_has_main_recovery( $prior ) ] as $key => $value ) { $operation[ $key ] = $this->scan_fact( 'known', $value, 'stored' ); }
		}
		$metadata = $this->scan_snapshot_value( $snapshot, '_wp_attachment_metadata', $metadata_valid );
		$backup_sizes = $this->scan_snapshot_value( $snapshot, '_wp_attachment_backup_sizes', $backup_valid );
		$attached = $this->scan_snapshot_value( $snapshot, '_wp_attached_file', $file_valid );
		$edited = $unknown;
		if ( $metadata_valid && $backup_valid && $file_valid && is_array( $metadata ) && is_string( $attached ) && $attached !== '' && ( $backup_sizes === '' || is_array( $backup_sizes ) ) ) {
			$edited = $this->scan_fact( 'known', $this->attachment_is_edited_from_values( [ 'file' => $attached, 'original_image' => isset( $metadata['original_image'] ) ? $metadata['original_image'] : '' ], $backup_sizes ), 'stored' );
		}
		return [ 'watermarked' => $watermarked, 'drift' => $drift, 'client_side' => $client, 'operation' => $operation, 'edited' => $edited ];
	}

	/**
	 * Project only the reapply gates. Other apply and predecessor gates remain
	 * unmeasured in this slice; a positive is not an execution promise.
	 */
	private function scan_reapply_eligibility( $id, $snapshot, &$backup, $options, $depth, $deadline, &$files, &$watermark_snapshot, $sizes ) {
		$unknown = $this->scan_fact( 'unknown', null, null, 'not_measured' );
		$flag = $this->scan_snapshot_value( $snapshot, 'iw-is-watermarked', $valid_flag );
		$relative = $this->scan_snapshot_value( $snapshot, '_wp_attached_file', $valid_file );
		$metadata = $this->scan_snapshot_value( $snapshot, '_wp_attachment_metadata', $valid_metadata );
		if ( ! $valid_flag || ! $valid_file || ! $valid_metadata ) { return $unknown; }
		if ( ! is_string( $relative ) || $this->normalize_operation_relative_path( $relative ) !== $relative || ! is_array( $metadata ) || empty( $metadata['file'] ) || $metadata['file'] !== $relative ) { return $unknown; }
		if ( get_post_meta( $id, '_wp_attached_file', true ) !== $relative || wp_get_attachment_metadata( $id, true ) !== $metadata || (int) get_post_meta( $id, 'iw-is-watermarked', true ) !== (int) $flag || get_post_mime_type( $id ) !== $snapshot['post']['post_mime_type'] ) {
			return $this->scan_fact( 'unknown', null, null, 'cache_disagreement', true );
		}
		$font_observation = null;
		$font_path = null;
		$font_checked = false;
		if ( isset( $options['watermark_image']['type'] ) && $options['watermark_image']['type'] === 'text' && ! empty( $options['watermark_image']['text_string'] ) ) {
			$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
			// Capture both inputs before get_font_path inspects either one.
			foreach ( [ $font, IMAGE_WATERMARK_PATH . 'fonts/' . $font ] as $candidate ) {
				if ( $this->scan_remember_file( $candidate, $files ) ) { continue; }
				$entries = @scandir( dirname( $candidate ) );
				$names = $entries === false ? [] : ( DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries );
				$name = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $candidate ) ) : basename( $candidate );
				if ( $entries !== false && ! in_array( $name, $names, true ) && ! is_link( $candidate ) ) { $files[ $candidate ] = null; }
				elseif ( $entries === false && $this->operation_directory_failure( dirname( $candidate ) ) === 'missing_directory' && $this->scan_remember_missing_path( $candidate, $files ) ) { /* Missing ancestor is observed. */ }
				else { $font_observation = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
			}
			$font_path = $this->plugin->get_font_path( $font );
			$font_checked = true;
			if ( is_string( $font_path ) && $font_path !== '' ) {
				if ( ! $this->scan_remember_file( $font_path, $files ) ) { $font_observation = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
			} else {
				// get_font_path checks both the selected path and the bundled path.
				// A denied parent must not masquerade as a missing font.
				foreach ( [ $font, IMAGE_WATERMARK_PATH . 'fonts/' . $font ] as $candidate ) {
					$parent = dirname( $candidate );
					$entries = @scandir( $parent );
					if ( $entries === false ) {
						if ( $this->operation_directory_failure( $parent ) !== 'missing_directory' ) { $font_observation = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
						continue;
					}
					$names = DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries;
					$name = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $candidate ) ) : basename( $candidate );
					if ( ! in_array( $name, $names, true ) && ! is_link( $candidate ) ) { $files[ $candidate ] = null; }
				}
			}
		}
		$validation = $this->describe_operation_eligibility( $id, 'manual-apply', $options );
		if ( $font_checked && $this->plugin->get_font_path( $font ) !== $font_path ) { $font_observation = $this->scan_fact( 'unknown', null, null, 'concurrent_change', true ); }
		if ( ! $validation['valid'] ) {
			if ( $validation['code'] === 'watermark_font_missing' && $font_observation !== null ) { return $font_observation; }
			return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => $validation['code'] ], 'stored' );
		}
		if ( $font_observation !== null ) { return $font_observation; }
		if ( apply_filters( 'iw_watermark_display', $id ) === false ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'display_veto' ], 'stored' ); }
		$failure = null;
		$main = $this->resolve_upload_artifact_path( $relative, false, '', 0, $failure, true );
		if ( $main === false ) {
			if ( $failure === 'missing_directory' && $this->scan_remember_missing_path( $this->operation_upload_dir( true )['basedir'] . '/' . $relative, $files ) ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'file_missing' ], 'header' ); }
			return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
		}
		if ( ! $this->scan_remember_file( $main['path'], $files ) ) {
			$entries = @scandir( dirname( $main['path'] ) );
			$names = $entries === false ? [] : ( DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries );
			$name = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $main['path'] ) ) : basename( $main['path'] );
			if ( $entries !== false && ! in_array( $name, $names, true ) && ! is_link( $main['path'] ) ) {
				$files[ $main['path'] ] = null;
				return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'file_missing' ], 'header' );
			}
			return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
		}
		$info = [];
		$main_header = @getimagesize( $main['path'], $info );
		if ( $main_header === false ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
		if ( $this->should_skip_small_image( $options, $main_header[0], $main_header[1] ) ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'small_image' ], 'header' ); }
		$has_target = isset( $options['watermark_on']['full'] ) && (int) $options['watermark_on']['full'] === 1;
		$target_unknown = null;
		foreach ( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [] as $name => $selected ) {
			if ( $name === 'full' || (int) $selected !== 1 || ! isset( $metadata['sizes'][ $name ] ) ) { continue; }
			if ( ! isset( $sizes[ $name ] ) ) {
				// Eligibility includes selected metadata-only sizes, but they are not
				// registered-size health signals.
				$entry = $metadata['sizes'][ $name ];
				if ( ! is_array( $entry ) || empty( $entry['file'] ) || ! is_string( $entry['file'] ) || wp_basename( $entry['file'] ) !== $entry['file'] || $this->normalize_operation_relative_path( $entry['file'] ) !== $entry['file'] ) { $target_unknown = $unknown; continue; }
				$directory = dirname( $relative );
				$target = $this->resolve_upload_artifact_path( ( $directory === '.' ? '' : $directory . '/' ) . $entry['file'], false, '', 0, $failure, true );
				if ( $target === false ) {
					if ( $failure !== 'missing_directory' || ! $this->scan_remember_missing_path( $this->operation_upload_dir( true )['basedir'] . '/' . ( $directory === '.' ? '' : $directory . '/' ) . $entry['file'], $files ) ) { $target_unknown = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
					continue;
				}
				if ( $this->scan_remember_file( $target['path'], $files ) ) { $has_target = true; continue; }
				$entries = @scandir( dirname( $target['path'] ) );
				$names = $entries === false ? [] : ( DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries );
				$needle = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $target['path'] ) ) : basename( $target['path'] );
				if ( $entries !== false && ! in_array( $needle, $names, true ) && ! is_link( $target['path'] ) ) { $files[ $target['path'] ] = null; }
				else { $target_unknown = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
				continue;
			}
			$exists = $sizes[ $name ]['exists'];
			if ( $exists['state'] === 'known' && $exists['value'] ) { $has_target = true; }
			elseif ( $exists['state'] === 'unknown' ) { $target_unknown = $exists; }
		}
		if ( ! $has_target && $target_unknown !== null ) { return $target_unknown; }
		if ( ! $has_target ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'no_target_sizes' ], 'header' ); }
		if ( $depth === 'full' ) {
			if ( $deadline !== null && microtime( true ) >= $deadline ) { return $this->scan_fact( 'unknown', null, null, 'deadline', true ); }
			$image_metadata = $this->get_image_metadata( $info, $main['path'] );
			if ( $deadline !== null && microtime( true ) >= $deadline ) { return $this->scan_fact( 'unknown', null, null, 'deadline', true ); }
			if ( ! empty( $image_metadata['error'] ) ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'metadata_read_failed' ], 'full' ); }
		}
		if ( isset( $options['watermark_image']['type'] ) && $options['watermark_image']['type'] === 'image' ) {
			$mark_id = (int) $options['watermark_image']['url'];
			$watermark_snapshot = $this->read_scan_snapshot( $mark_id );
			if ( $watermark_snapshot === false || ! is_array( $watermark_snapshot['post'] ) ) { $watermark_snapshot = null; return $this->scan_fact( 'unknown', null, null, 'query_failed', true ); }
			$mark_meta = $this->scan_snapshot_value( $watermark_snapshot, '_wp_attachment_metadata', $valid_mark );
			if ( ! $valid_mark || wp_get_attachment_metadata( $mark_id, true ) !== $mark_meta ) { return $this->scan_fact( 'unknown', null, null, 'cache_disagreement', true ); }
			if ( ! is_array( $mark_meta ) || empty( $mark_meta['file'] ) ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'watermark_source_missing' ], 'stored' ); }
			$mark = $this->resolve_upload_artifact_path( $mark_meta['file'], false, '', 0, $failure, true );
			if ( $mark === false ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
			if ( ! $this->watermark_source_is_available( $options, $this->operation_upload_dir( true ) ) ) {
				$entries = @scandir( dirname( $mark['path'] ) );
				$names = $entries === false ? [] : ( DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries );
				$name = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $mark['path'] ) ) : basename( $mark['path'] );
				if ( $entries === false || in_array( $name, $names, true ) ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
				$files[ $mark['path'] ] = null;
				return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'watermark_source_missing' ], 'header' );
			}
			if ( ! $this->scan_remember_file( $mark['path'], $files ) ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
		}
		if ( (int) $flag !== 1 ) {
			$predecessor = $this->scan_apply_predecessor_uncertainty( $id, $snapshot );
			if ( $predecessor !== null ) { return $predecessor; }
			return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => true, 'code' => '' ], $depth === 'full' ? 'full' : 'header', $depth === 'full' ? '' : 'full_checks_not_requested' );
		}
		if ( $backup['availability']['state'] === 'unknown' ) { return $backup['availability']; }
		if ( $backup['availability']['value'] === 'absent' || $backup['decode']['value'] === 'failed' ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'backup_missing' ], $depth === 'full' ? 'full' : 'header' ); }
		if ( $backup['decode']['state'] === 'unknown' ) { return $backup['decode']; }
		$backup_path = $this->resolve_operation_root_path( $this->get_backup_root_dir(), $relative, false, '', 0, $failure );
		if ( $backup_path === false || ! $this->scan_remember_file( $backup_path['path'], $files ) ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
		$backup_header = @getimagesize( $backup_path['path'] );
		if ( ! $backup_header ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
		$matches = $main_header[0] === $backup_header[0] && $main_header[1] === $backup_header[1] && $main_header['mime'] === $backup_header['mime'];
		$backup['identity_matches_main'] = $this->scan_fact( 'known', $matches, 'header' );
		if ( ! $matches ) { return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => false, 'code' => 'backup_missing' ], 'header' ); }
		$predecessor = $this->scan_apply_predecessor_uncertainty( $id, $snapshot );
		if ( $predecessor !== null ) { return $predecessor; }
		return $this->scan_fact( 'known', [ 'operation' => 'manual-apply', 'eligible' => true, 'code' => '' ], $depth === 'full' ? 'full' : 'header', $depth === 'full' ? '' : 'full_checks_not_requested' );
	}

	/** Classify unresolved apply gates without settling a claim or predecessor. */
	private function scan_apply_predecessor_uncertainty( $id, $snapshot ) {
		$journal = $this->scan_snapshot_value( $snapshot, '_iw_operation_journal', $valid_journal );
		if ( ! $valid_journal || ( $journal !== '' && ! $this->is_valid_operation_journal( $journal ) ) ) { return $this->scan_fact( 'unknown', null, null, 'invalid_journal' ); }
		if ( $journal !== '' ) {
			$prior = $this->select_operation_recovery_predecessor( $journal );
			if ( $this->is_pending_handoff( $journal['current'] ) || $this->is_pending_handoff( $prior ) ) { return $this->scan_fact( 'unknown', null, null, 'handoff_pending' ); }
			if ( ( $journal['current'] !== null && ! $this->operation_is_terminal( $journal['current'] ) ) || $this->record_has_main_recovery( $prior ) ) { return $this->scan_fact( 'unknown', null, null, 'predecessor_unmeasured' ); }
		}
		$claim = $this->scan_snapshot_value( $snapshot, self::CLIENT_SIDE_CLAIM_META_KEY, $valid_claim );
		$payload = $this->scan_snapshot_value( $snapshot, self::CLIENT_SIDE_PAYLOAD_META_KEY, $valid_payload );
		$claim_rows = array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::CLIENT_SIDE_CLAIM_META_KEY; } );
		$payload_rows = array_filter( $snapshot['meta'], static function( $row ) { return $row['meta_key'] === self::CLIENT_SIDE_PAYLOAD_META_KEY; } );
		if ( ! $valid_claim || ! $valid_payload || ( $claim_rows && ! $this->is_valid_client_side_claim( $claim, $id ) ) || ( $payload_rows && $this->sanitize_client_side_payload( $payload ) === false ) ) { return $this->scan_fact( 'unknown', null, null, 'invalid_claim' ); }
		if ( $claim_rows && $claim['state'] === 'rendered' ) { return $this->scan_fact( 'unknown', null, null, 'claim_unmeasured' ); }
		return null;
	}

	/** Source selection is provisional until the remaining boundary checks are implemented. */
	private function scan_source_facts( $snapshot, &$backup, $depth, $deadline, &$limits, &$files, &$generation_path, $options ) {
		$unknown = $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
		$empty = [ 'availability' => $unknown, 'kind' => $unknown, 'authority' => $unknown, 'decode' => $unknown, 'dimensions' => $unknown ];
		$unmeasured = $this->scan_fact( 'unknown', null, null, 'not_measured' );
		$result = [ 'manifest' => $unknown, 'provenance' => $unknown, 'clean_source' => [ 'availability' => $unknown, 'kind' => $unknown ], 'sources' => [ 'main' => $empty, 'generation' => $empty ], 'recovery' => [ 'retained' => $unmeasured, 'role' => $unmeasured, 'state' => $unmeasured, 'authority' => $unmeasured, 'hash_verified' => $unmeasured ] ];
		$journal = $this->scan_snapshot_value( $snapshot, '_iw_operation_journal', $valid_journal );
		$journal_invalid = ! $valid_journal || ( $journal !== '' && ! $this->is_valid_operation_journal( $journal ) );
		if ( $journal_invalid ) {
			$invalid_journal = $this->scan_fact( 'unknown', null, null, 'invalid_journal' );
			$result['manifest'] = $invalid_journal;
			$result['recovery'] = array_fill_keys( array_keys( $result['recovery'] ), $invalid_journal );
			$result['clean_source'] = array_fill_keys( array_keys( $result['clean_source'] ), $invalid_journal );
			$result['sources'] = [ 'main' => array_fill_keys( array_keys( $empty ), $invalid_journal ), 'generation' => array_fill_keys( array_keys( $empty ), $invalid_journal ) ];
		}
		// Provenance absence or malformed evidence is independent of metadata and journal validity.
		$provenance = $this->scan_snapshot_value( $snapshot, self::PROVENANCE_META_KEY, $valid_provenance );
		if ( ! $valid_provenance ) {
			$result['provenance'] = $this->scan_fact( 'unknown', null, null, 'invalid_provenance' );
		} elseif ( $provenance === '' ) {
			$result['provenance'] = $this->scan_fact( 'known', 'absent', 'stored' );
		} elseif ( ! is_array( $provenance ) || ! isset( $provenance['version'], $provenance['file'], $provenance['size'], $provenance['sha256'], $provenance['method'], $provenance['time'] ) || $provenance['version'] !== self::PROVENANCE_VERSION || ! in_array( $provenance['method'], [ 'server-upload', 'client-upload' ], true ) || ! is_int( $provenance['time'] ) || strlen( serialize( $provenance ) ) > self::PROVENANCE_MAX_BYTES || ! is_string( $provenance['file'] ) || wp_basename( $provenance['file'] ) !== $provenance['file'] || ! is_int( $provenance['size'] ) || $provenance['size'] < 1 || ! is_string( $provenance['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $provenance['sha256'] ) ) {
			$result['provenance'] = $this->scan_fact( 'unknown', null, null, 'invalid_provenance' );
		}
		$values = [];
		foreach ( [ '_wp_attached_file', '_wp_attachment_metadata', 'iw-is-watermarked', '_wp_attachment_backup_sizes', self::PROVENANCE_META_KEY, self::MANIFEST_META_KEY ] as $key ) {
			$values[ $key ] = $this->scan_snapshot_value( $snapshot, $key, $valid );
			if ( ! $valid ) { return $result; }
		}
		if ( $journal === '' || $journal_invalid ) { $journal = [ 'version' => 1, 'current' => null, 'recoverable_predecessor' => null, 'history' => [] ]; }
		$flagged = (int) $values['iw-is-watermarked'] === 1;
		$status = $this->manifest_status_from_values( $values[ self::MANIFEST_META_KEY ], $journal, $flagged, $options );
		$result['manifest'] = $journal_invalid ? $this->scan_fact( 'unknown', null, null, 'invalid_journal' ) : $this->scan_fact( 'known', $status['status'], 'stored', $status['reason'] );
		$relative = $values['_wp_attached_file'];
		$metadata = $values['_wp_attachment_metadata'];
		if ( ! is_string( $relative ) || $this->normalize_operation_relative_path( $relative ) !== $relative || ! is_array( $metadata ) || ! isset( $metadata['file'] ) || $metadata['file'] !== $relative ) { return $result; }
		$failure = null;
		$main = $this->resolve_upload_artifact_path( $relative, false, '', 0, $failure, true );
		if ( $main === false ) { return $result; }
		$absent = $this->scan_fact( 'known', 'absent', 'stored', 'no_clean_source' );
		$result['clean_source']['availability'] = $absent;
		$result['clean_source']['kind'] = $this->scan_fact( 'known', null, 'stored' );
		$result['sources']['main'] = array_fill_keys( array_keys( $empty ), $this->scan_fact( 'not_applicable' ) );
		$result['sources']['main']['availability'] = $absent;
		if ( $result['provenance']['reason'] === 'invalid_metadata' ) {
			$expected = ! empty( $metadata['original_image'] ) ? $metadata['original_image'] : wp_basename( $relative );
			$result['provenance'] = $provenance['file'] === $expected ? $this->scan_fact( 'unknown', null, null, 'not_measured' ) : $this->scan_fact( 'known', 'mismatched', 'stored' );
		}
		$main_source = null;
		$main_source_path = null;
		$backup_uncertain = $backup['availability']['state'] === 'unknown' ? $backup['availability'] : ( $backup['decode']['state'] === 'unknown' ? $backup['decode'] : null );
		// A backup must match the attached main's format and dimensions before it
		// can be selected; decoding it alone establishes only backup availability.
		if ( in_array( $backup['availability']['value'], [ 'present', 'validated' ], true ) ) {
			$backup_path = $this->resolve_operation_root_path( $this->get_backup_root_dir(), $relative, false, '', 0, $failure );
			if ( $backup_path !== false && $this->scan_remember_file( $main['path'], $files ) && $this->scan_remember_file( $backup_path['path'], $files ) ) {
				$a = @getimagesize( $main['path'] ); $b = @getimagesize( $backup_path['path'] );
				if ( ! $a || ! $b ) { $backup_uncertain = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
				if ( $a && $b ) { $backup['identity_matches_main'] = $this->scan_fact( 'known', $a[0] === $b[0] && $a[1] === $b[1] && $a['mime'] === $b['mime'], 'header' ); }
				if ( $a && $b && $backup['identity_matches_main']['value'] && $backup['decode']['value'] !== 'failed' ) {
					$main_source = [ 'availability' => $backup['availability'], 'kind' => $this->scan_fact( 'known', 'backup', 'header' ), 'authority' => $this->scan_fact( 'known', 'backup', 'header' ), 'decode' => $backup['decode'] ];
					$main_source_path = $backup_path['path'];
				}
			} else { $backup_uncertain = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
		}
		$manifest = $status['manifest'];
		$current = $journal['current'];
		$prior = $this->select_operation_recovery_predecessor( $journal );
		$recovery_pending = $this->record_has_main_recovery( $prior );
		$result['recovery']['retained'] = $this->scan_fact( 'known', $recovery_pending, 'stored' );
		if ( $recovery_pending ) {
			$result['recovery']['role'] = $this->scan_fact( 'known', 'main', 'stored' );
			$result['recovery']['state'] = $this->scan_fact( 'known', $prior['main_recovery']['state'], 'stored' );
			$result['recovery']['authority'] = $this->scan_fact( 'known', $prior['main_recovery']['authority'], 'stored' );
			$result['recovery']['hash_verified'] = $this->scan_fact( 'unknown', null, null, 'not_requested' );
			$result['recovery']['hash_verified']['observed_at'] = null;
		} else {
			$result['recovery']['role'] = $result['recovery']['state'] = $result['recovery']['authority'] = $result['recovery']['hash_verified'] = $this->scan_fact( 'not_applicable' );
		}
		if ( ! $recovery_pending && ( $main_source === null || $main_source['availability']['value'] !== 'validated' ) && in_array( $status['status'], [ 'trusted', 'settings_changed' ], true ) && is_array( $manifest ) && ! $manifest['main']['watermarked'] && $manifest['main']['path'] === $relative && is_array( $current ) && isset( $current['site_id'] ) && $current['site_id'] === get_current_blog_id() ) {
			$candidate = $this->scan_candidate_source( $main['path'], 'manifest', $manifest['main'], $depth, $deadline, $limits, $files );
			if ( in_array( $candidate['availability']['value'], [ 'present', 'validated' ], true ) && ( $main_source === null || ( $candidate['availability']['value'] === 'validated' && $main_source['availability']['value'] !== 'validated' ) ) ) { $main_source = $candidate; $main_source_path = $main['path']; }
			if ( $candidate['availability']['state'] === 'unknown' && ( $backup_uncertain === null || $candidate['availability']['retryable'] ) ) { $backup_uncertain = $candidate['availability']; }
		}
		if ( $main_source === null && $backup_uncertain !== null ) {
			$result['clean_source']['availability'] = $backup_uncertain;
			$result['clean_source']['kind'] = $backup_uncertain;
			$result['sources']['main'] = array_fill_keys( array_keys( $empty ), $backup_uncertain );
		} elseif ( $main_source === null && $recovery_pending ) {
			// Recovery authority is not a member of the clean-source filter.
			$result['sources']['main']['availability'] = $this->scan_fact( 'unknown', null, null, 'recovery_unmeasured' );
		}
		if ( $main_source !== null ) {
			$result['sources']['main'] = $main_source;
			$result['clean_source']['availability'] = $main_source['availability'];
			$result['clean_source']['kind'] = $main_source['kind'];
		}
		// Recovery is a generation source only; it never satisfies clean_source.
		if ( $recovery_pending && $main_source === null ) {
			$selected = $this->scan_recovery_source( $journal, $prior, $main, $depth, $deadline, $limits, $files, $result['recovery'] );
			$result['sources']['main'] = $selected['facts'];
			$main_source = $selected['facts'];
			$main_source_path = $selected['path'];
		}
		$generation = $main_source;
		$generation_path = $main_source_path;
		// A retained main recovery still needs its own authority check; a clean
		// flag-risk decision alone cannot establish the live layout it protects.
		$live_allowed = ! $journal_invalid && ! $flagged && ! $recovery_pending && $this->unwatermarked_predecessor_flag_risk_from_values( $journal, true ) === '';
		if ( $live_allowed ) {
			$live = $this->scan_candidate_source( $main['path'], 'live', null, $depth, $deadline, $limits, $files );
			$result['sources']['main'] = $live;
			$main_source_path = $main['path'];
			$generation = $live;
			$generation_path = $main['path'];
		}
		$original = isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ? $metadata['original_image'] : '';
		$pre = [ 'file' => $relative, 'original_image' => $original ];
		if ( ( $flagged || $live_allowed || $journal_invalid ) && $original !== '' && wp_basename( $original ) === $original && ! $this->attachment_is_edited_from_values( $pre, $values['_wp_attachment_backup_sizes'] ) ) {
			$provenance = $values[ self::PROVENANCE_META_KEY ];
			$requires_provenance = $flagged || $journal_invalid;
			$authority = $requires_provenance ? 'provenance' : 'live';
			if ( ! $requires_provenance || ( $result['provenance']['reason'] === 'not_measured' && $provenance['file'] === $original ) ) {
				$dir = dirname( $relative );
				$resolved = $this->resolve_upload_artifact_path( ( $dir === '.' ? '' : $dir . '/' ) . $original, false, '', 0, $failure, true );
				if ( $resolved !== false ) {
					$candidate = $this->scan_candidate_source( $resolved['path'], $authority, $requires_provenance ? $provenance : null, $depth, $deadline, $limits, $files );
					if ( ! $requires_provenance ) { $candidate['kind'] = $this->scan_fact( 'known', 'proven_original', 'stored' ); }
					if ( $requires_provenance && $result['provenance']['reason'] === 'not_measured' ) {
						$result['provenance'] = $candidate['availability']['state'] === 'unknown' ? $candidate['availability'] : $this->scan_fact( 'known', $candidate['authority']['certainty'] === 'hash' ? 'matching' : ( $candidate['availability']['value'] === 'absent' ? 'mismatched' : 'present' ), $candidate['authority']['certainty'] === 'hash' || $candidate['availability']['certainty'] === 'hash' ? 'hash' : 'header' );
					}
					if ( in_array( $candidate['availability']['value'], [ 'present', 'validated' ], true ) ) { $generation = $candidate; $generation_path = $resolved['path']; }
					elseif ( $candidate['availability']['state'] === 'unknown' || $candidate['decode']['state'] === 'unknown' ) { $generation = $candidate; $generation_path = null; }
				} else {
					$generation = $empty;
					$generation['availability'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
					$generation_path = null;
				}
			}
		}
		if ( $result['provenance']['reason'] === 'not_measured' ) {
			$dir = dirname( $relative );
			$observed = $this->resolve_upload_artifact_path( ( $dir === '.' ? '' : $dir . '/' ) . $provenance['file'], false, '', 0, $failure, true );
			if ( $observed === false || ! $this->scan_remember_file( $observed['path'], $files ) ) {
				$result['provenance'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
			} elseif ( $files[ $observed['path'] ][2] !== $provenance['size'] ) {
				$result['provenance'] = $this->scan_fact( 'known', 'mismatched', 'header' );
			} elseif ( @getimagesize( $observed['path'] ) === false ) {
				$result['provenance'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
			} elseif ( $flagged && $depth === 'full' ) {
				$hash = $this->scan_hash_file( $observed['path'], $provenance['size'], $deadline, $limits );
				$result['provenance'] = $hash === false ? $this->scan_fact( 'unknown', null, null, $deadline !== null && microtime( true ) >= $deadline ? 'deadline' : 'path_unavailable', true ) : $this->scan_fact( 'known', $hash === $provenance['sha256'] ? 'matching' : 'mismatched', 'hash' );
			} else {
				$result['provenance'] = $this->scan_fact( 'known', 'present', 'header', 'hash_not_requested' );
			}
		}
		if ( $journal_invalid ) {
			$invalid_journal = $this->scan_fact( 'unknown', null, null, 'invalid_journal' );
			$result['recovery'] = array_fill_keys( array_keys( $result['recovery'] ), $invalid_journal );
			if ( $main_source === null && $backup_uncertain === null ) {
				$result['clean_source'] = array_fill_keys( array_keys( $result['clean_source'] ), $invalid_journal );
				$result['sources']['main'] = array_fill_keys( array_keys( $empty ), $invalid_journal );
			}
			if ( $generation === null ) { $generation = $result['sources']['main']; }
		}
		// An existing unflagged live file is not proven clean after an operation
		// that may have changed it. This is uncertainty, not source absence.
		if ( ! $journal_invalid && ! $flagged && ! $live_allowed && $generation === null && $result['sources']['main']['availability']['value'] === 'absent' && is_file( $main['path'] ) ) {
			$unproven = $this->scan_fact( 'unknown', null, null, 'source_unproven' );
			$result['sources']['main'] = array_fill_keys( array_keys( $empty ), $unproven );
			$generation = $result['sources']['main'];
		}
		if ( $generation !== null ) { $result['sources']['generation'] = $generation; }
		else { $result['sources']['generation'] = $result['sources']['main']; }
		$result['sources']['main']['dimensions'] = $result['sources']['main']['availability']['value'] === 'absent' ? $this->scan_fact( 'not_applicable' ) : $this->scan_generation_dimensions( $main_source_path, $result['sources']['main'], $depth, $deadline );
		return $result;
	}

	/** Read orientation only after loading core's metadata helper. */
	private function scan_generation_dimensions( $path, $source, $depth, $deadline ) {
		$availability = $source['availability'];
		if ( $availability['state'] === 'unknown' ) { return $availability; }
		if ( ! in_array( $availability['value'], [ 'present', 'validated' ], true ) ) { return $this->scan_fact( 'unknown', null, null, 'no_clean_source' ); }
		if ( $depth === 'full' && $source['decode']['state'] === 'unknown' ) { return $source['decode']; }
		if ( $path === null ) { return $this->scan_fact( 'unknown', null, null, 'source_unavailable' ); }
		if ( $deadline !== null && microtime( true ) >= $deadline ) { return $this->scan_fact( 'unknown', null, null, 'deadline', true ); }
		$header = @getimagesize( $path );
		if ( ! is_array( $header ) || empty( $header[0] ) || empty( $header[1] ) ) { return $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
		if ( ! function_exists( 'wp_read_image_metadata' ) ) {
			$helper = ABSPATH . 'wp-admin/includes/image.php';
			if ( is_readable( $helper ) ) { require_once $helper; }
		}
		if ( ! function_exists( 'wp_read_image_metadata' ) || ( isset( $header['mime'] ) && $header['mime'] === 'image/jpeg' && ! is_callable( 'exif_read_data' ) ) ) { return $this->scan_fact( 'unknown', null, null, 'orientation_unavailable' ); }
		$metadata = wp_read_image_metadata( $path );
		if ( $deadline !== null && microtime( true ) >= $deadline ) { return $this->scan_fact( 'unknown', null, null, 'deadline', true ); }
		if ( ! is_array( $metadata ) ) { return $this->scan_fact( 'unknown', null, null, 'orientation_unavailable' ); }
		$orientation = isset( $metadata['orientation'] ) ? (int) $metadata['orientation'] : 0;
		$dimensions = $orientation >= 5 && $orientation <= 8 ? [ (int) $header[1], (int) $header[0] ] : [ (int) $header[0], (int) $header[1] ];
		return $this->scan_fact( 'known', $dimensions, $availability['certainty'], $availability['reason'] );
	}

	/** D03-10 compares recorded dimensions with core's result, not the requested box. */
	private function scan_size_facts( $metadata, $registered, $dimensions, $deadline, &$files ) {
		$result = [];
		$metadata_valid = is_array( $metadata ) && isset( $metadata['file'] ) && is_string( $metadata['file'] ) && $this->normalize_operation_relative_path( $metadata['file'] ) === $metadata['file'] && ( ! isset( $metadata['sizes'] ) || is_array( $metadata['sizes'] ) );
		$entries = $metadata_valid && isset( $metadata['sizes'] ) ? $metadata['sizes'] : [];
		$directory = $metadata_valid ? dirname( $metadata['file'] ) : '';
		foreach ( $registered as $name => $definition ) {
			$invalid = $this->scan_fact( 'unknown', null, null, 'invalid_metadata' );
			$size = [ 'exists' => $invalid, 'expected' => $dimensions, 'recorded' => $invalid, 'state' => $dimensions, 'shared_aliases' => $invalid ];
			if ( $deadline !== null && microtime( true ) >= $deadline ) {
				$unmeasured = $this->scan_fact( 'unknown', null, null, 'deadline', true );
				$unmeasured['observed_at'] = null;
				$size = array_fill_keys( array_keys( $size ), $unmeasured );
				$result[ $name ] = $size;
				continue;
			}
			if ( $dimensions['state'] === 'known' ) {
				$geometry = image_resize_dimensions( $dimensions['value'][0], $dimensions['value'][1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
				$expected = $geometry ? [ (int) $geometry[4], (int) $geometry[5] ] : null;
				$size['expected'] = $this->scan_fact( 'known', $expected, $dimensions['certainty'], $dimensions['reason'] );
				if ( $expected === null ) { $size['state'] = $this->scan_fact( 'known', 'not_producible', $dimensions['certainty'], $dimensions['reason'] ); }
			}
			if ( $metadata_valid && ! array_key_exists( $name, $entries ) ) {
				$size['exists'] = $this->scan_fact( 'known', false, 'stored' );
				$size['recorded'] = $this->scan_fact( 'known', null, 'stored' );
				$size['shared_aliases'] = $this->scan_fact( 'known', [], 'stored' );
			} elseif ( $metadata_valid && is_array( $entries[ $name ] ) ) {
				$entry = $entries[ $name ];
				if ( isset( $entry['width'], $entry['height'] ) && is_numeric( $entry['width'] ) && is_numeric( $entry['height'] ) && (int) $entry['width'] > 0 && (int) $entry['height'] > 0 ) {
					$size['recorded'] = $this->scan_fact( 'known', [ (int) $entry['width'], (int) $entry['height'] ], 'stored' );
				}
				if ( isset( $entry['file'] ) && is_string( $entry['file'] ) && wp_basename( $entry['file'] ) === $entry['file'] && $this->normalize_operation_relative_path( $entry['file'] ) === $entry['file'] ) {
					$relative = ( $directory === '.' ? '' : $directory . '/' ) . $entry['file'];
					$failure = null;
					$resolved = $this->resolve_upload_artifact_path( $relative, false, '', 0, $failure, true );
					if ( $resolved !== false ) {
						$path = $resolved['path'];
						clearstatcache( true, $path );
						if ( $this->scan_remember_file( $path, $files ) ) {
							$size['exists'] = $this->scan_fact( 'known', true, 'header' );
						} elseif ( ! file_exists( $path ) && ! is_link( $path ) ) {
							$listing = @scandir( dirname( $path ) );
							$names = $listing === false ? [] : ( DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $listing ) : $listing );
							$needle = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $path ) ) : basename( $path );
							if ( $listing !== false && ! in_array( $needle, $names, true ) ) { $size['exists'] = $this->scan_fact( 'known', false, 'header' ); $files[ $path ] = null; }
						}
						if ( $size['exists']['state'] === 'unknown' ) { $size['exists'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
						$aliases = [];
						foreach ( $entries as $other => $other_entry ) {
							if ( $other !== $name && is_array( $other_entry ) && isset( $other_entry['file'] ) && is_string( $other_entry['file'] ) && ( DIRECTORY_SEPARATOR === '\\' ? strcasecmp( $entry['file'], $other_entry['file'] ) === 0 : $entry['file'] === $other_entry['file'] ) ) { $aliases[] = $other; }
						}
						$size['shared_aliases'] = $this->scan_fact( 'known', $aliases, 'stored' );
					} elseif ( $failure === 'missing_directory' && $this->scan_remember_missing_path( $this->operation_upload_dir( true )['basedir'] . '/' . $relative, $files ) ) { $size['exists'] = $this->scan_fact( 'known', false, 'header' ); }
					else { $size['exists'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); }
				}
			}
			if ( $size['expected']['state'] === 'known' && $size['expected']['value'] !== null ) {
				if ( $size['exists']['state'] === 'known' && ! $size['exists']['value'] ) { $size['state'] = $this->scan_fact( 'known', 'missing', 'header' ); }
				elseif ( $size['exists']['state'] === 'known' && $size['recorded']['state'] === 'known' ) { $size['state'] = $this->scan_fact( 'known', $size['recorded']['value'] === $size['expected']['value'] ? 'present' : 'geometry_stale', $dimensions['certainty'], $dimensions['reason'] ); }
				else { $size['state'] = $size['exists']['state'] === 'unknown' ? $size['exists'] : $size['recorded']; }
			}
			$result[ $name ] = $size;
		}
		return $result;
	}

	/** Track the first missing component against its inspectable parent. */
	private function scan_remember_missing_path( $path, &$files ) {
		while ( ! is_dir( dirname( $path ) ) ) {
			$parent = dirname( $path );
			if ( $parent === $path || is_link( $parent ) || file_exists( $parent ) ) { return false; }
			$path = $parent;
		}
		$entries = @scandir( dirname( $path ) );
		if ( $entries === false ) { return false; }
		$names = DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries;
		$name = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $path ) ) : basename( $path );
		if ( in_array( $name, $names, true ) || is_link( $path ) ) { return false; }
		$files[ $path ] = null;
		return true;
	}

	/** Capture file stat before reading; later comparisons do not imply atomicity. */
	private function scan_remember_file( $path, &$files ) {
		clearstatcache( true, $path );
		$stat = @stat( $path );
		if ( $stat === false || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { return false; }
		if ( ! isset( $files[ $path ] ) ) { $files[ $path ] = [ $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'] ]; }
		return true;
	}

	private function scan_source_files_unchanged( $files ) {
		foreach ( $files as $path => $before ) {
			clearstatcache( true, $path ); $stat = @stat( $path );
			if ( $before === null ) {
				$entries = @scandir( dirname( $path ) );
				if ( $stat !== false || is_link( $path ) || $entries === false || in_array( DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $path ) ) : basename( $path ), DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $entries ) : $entries, true ) ) { return false; }
				continue;
			}
			if ( $stat === false || is_link( $path ) || [ $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'] ] !== $before ) { return false; }
		}
		return true;
	}

	private function invalidate_scan_sources( &$facts, $reason ) {
		$unknown = $this->scan_fact( 'unknown', null, null, $reason, true );
		$facts['manifest'] = $unknown;
		if ( isset( $facts['eligibility'] ) ) { $facts['eligibility'] = $unknown; }
		if ( isset( $facts['drift']['current'] ) && $facts['drift']['current']['state'] !== 'not_applicable' ) { $facts['drift']['current'] = $unknown; }
		$facts['provenance'] = $unknown;
		$facts['clean_source'] = [ 'availability' => $unknown, 'kind' => $unknown ];
		if ( isset( $facts['recovery'] ) ) { foreach ( $facts['recovery'] as &$fact ) { $fact = $unknown; } unset( $fact ); }
		$facts['sources'] = [ 'main' => [ 'availability' => $unknown, 'kind' => $unknown, 'authority' => $unknown, 'decode' => $unknown, 'dimensions' => $unknown ], 'generation' => [ 'availability' => $unknown, 'kind' => $unknown, 'authority' => $unknown, 'decode' => $unknown, 'dimensions' => $unknown ] ];
		if ( isset( $facts['sizes'] ) ) {
			foreach ( $facts['sizes'] as &$size ) { $size['expected'] = $unknown; $size['state'] = $unknown; $size['exists'] = $unknown; $size['recorded'] = $unknown; $size['shared_aliases'] = $unknown; }
			unset( $size );
		}
	}

	/** Select only authority already retained by the journal; never restore or transfer it. */
	private function scan_recovery_source( $journal, $prior, $main, $depth, $deadline, &$limits, &$files, &$facts ) {
		$unknown = $this->scan_fact( 'unknown', null, null, 'recovery_unmeasured' );
		$result = [ 'path' => null, 'facts' => [ 'availability' => $unknown, 'kind' => $this->scan_fact( 'known', 'recovery', 'stored' ), 'authority' => $unknown, 'decode' => $unknown ] ];
		$target = $this->operation_destination_relative_path( $main['path'], $this->operation_upload_dir( true ) );
		if ( $target === false || ! $this->scan_remember_file( $main['path'], $files ) ) { return $result; }
		// The live identity and promotion proof are hash-bound; header inspection
		// cannot establish either and must not select a recovery source.
		if ( $depth !== 'full' ) { return $result; }
		$live_size = $files[ $main['path'] ][2];
		$live_hash = $this->scan_hash_file( $main['path'], $live_size, $deadline, $limits );
		if ( $live_hash === false ) {
			$result['facts']['availability'] = $this->scan_fact( 'unknown', null, null, $deadline !== null && microtime( true ) >= $deadline ? 'deadline' : 'path_unavailable', true );
			return $result;
		}
		$matches = function( $record ) use ( $target, $live_size, $live_hash, &$files ) {
			$recovery = $record['main_recovery'];
			if ( $recovery['target'] !== $target ) { return false; }
			if ( $live_size === $recovery['size'] && $live_hash === $recovery['sha256'] ) {
				return $recovery['state'] === 'verified' || ! $this->main_recovery_has_target( $record );
			}
			if ( $recovery['state'] !== 'verified' ) { return false; }
			foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $entry ) {
				if ( ! isset( $entry['target'], $entry['state'], $entry['size'], $entry['hash'] ) || $entry['target'] !== $target || $entry['size'] !== $live_size || $entry['hash'] !== $live_hash ) { continue; }
				if ( $entry['state'] === 'promoted' ) { return true; }
				if ( $entry['state'] === 'staged' && isset( $entry['stage'] ) ) {
					$failure = null;
					$stage = $this->resolve_upload_artifact_path( $entry['stage'], false, '', 0, $failure, true );
					if ( $stage === false ) { return null; }
					$listing = @scandir( dirname( $stage['path'] ) );
					if ( $listing === false ) { return null; }
					$names = DIRECTORY_SEPARATOR === '\\' ? array_map( 'strtolower', $listing ) : $listing;
					$name = DIRECTORY_SEPARATOR === '\\' ? strtolower( basename( $stage['path'] ) ) : basename( $stage['path'] );
					if ( ! in_array( $name, $names, true ) && ! is_link( $stage['path'] ) ) { $files[ $stage['path'] ] = null; return true; }
				}
			}
			return false;
		};
		$match = $matches( $prior );
		if ( $match === false && ! $this->main_recovery_has_target( $prior ) ) {
			$parent = isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
			if ( $this->record_has_main_recovery( $parent, true ) && $this->same_main_recovery_authority( $parent['main_recovery'], $prior['main_recovery'] ) ) { $prior = $parent; $match = $matches( $prior ); }
		}
		$facts['state'] = $this->scan_fact( 'known', $prior['main_recovery']['state'], 'stored' );
		if ( $match !== true ) {
			$result['facts']['availability'] = $this->scan_fact( 'unknown', null, null, $match === null ? 'path_unavailable' : 'recovery_authority_mismatch', $match === null );
			$facts['hash_verified'] = $match === null ? $result['facts']['availability'] : $this->scan_fact( 'unknown', null, null, 'live_mismatch' );
			return $result;
		}
		$recovery = $prior['main_recovery'];
		$failure = null;
		$file = $recovery['state'] === 'allocated' ? $main : $this->resolve_upload_artifact_path( $recovery['path'], false, '', 0, $failure, true );
		if ( $file === false ) { $result['facts']['availability'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true ); return $result; }
		$candidate = $this->scan_candidate_source( $file['path'], 'recovery', $recovery, 'full', $deadline, $limits, $files );
		$candidate['kind'] = $this->scan_fact( 'known', 'recovery', 'stored' );
		if ( $candidate['authority']['certainty'] === 'hash' ) { $facts['hash_verified'] = $this->scan_fact( 'known', true, 'hash' ); }
		elseif ( $candidate['availability']['reason'] === 'authority_mismatch' ) { $facts['hash_verified'] = $this->scan_fact( 'known', false, 'hash', 'recovery_mismatch' ); }
		else { $facts['hash_verified'] = $candidate['availability']['state'] === 'unknown' ? $candidate['availability'] : $this->scan_fact( 'unknown', null, null, 'not_measured' ); }
		if ( $candidate['authority']['certainty'] !== 'hash' ) { $candidate['authority'] = $this->scan_fact( 'unknown', null, null, 'source_unproven' ); }
		$result['facts'] = $candidate;
		if ( $candidate['availability']['value'] === 'validated' ) { $result['path'] = $file['path']; }
		return $result;
	}

	/** Count full-depth reads without using the operation's mutating recovery path. */
	private function scan_hash_file( $path, $size, $deadline, &$limits ) {
		$stream = @fopen( $path, 'rb' );
		if ( $stream === false ) { return false; }
		$hash = hash_init( 'sha256' ); $read = 0;
		try {
			while ( $read < $size ) {
				if ( $deadline !== null && microtime( true ) >= $deadline ) { return false; }
				$chunk = @fread( $stream, min( 65536, $size - $read ) );
				if ( $chunk === false || $chunk === '' ) { return false; }
				$length = strlen( $chunk ); $read += $length; $limits['hash_bytes'] += $length;
				$this->should_inject_operation_fault( 'scan_hash_read', [ 'bytes' => $length ] );
				hash_update( $hash, $chunk );
			}
			return hash_final( $hash );
		} finally { fclose( $stream ); }
	}

	/** Observe a selected source; hashing checks only previously recorded authority. */
	private function scan_candidate_source( $path, $authority, $record, $depth, $deadline, &$limits, &$files ) {
		$absent = $this->scan_fact( 'known', 'absent', 'header', 'source_unavailable' );
		$kind = $authority === 'live' ? 'live_main' : ( $authority === 'manifest' ? 'manifest_main' : ( $authority === 'provenance' ? 'proven_original' : $authority ) );
		$result = [ 'availability' => $absent, 'kind' => $this->scan_fact( 'known', $kind, 'stored' ), 'authority' => $this->scan_fact( 'unknown', null, null, 'source_unavailable' ), 'decode' => $this->scan_fact( 'not_applicable' ) ];
		if ( ! $this->scan_remember_file( $path, $files ) ) {
			$result['availability'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
			return $result;
		}
		$size = $files[ $path ][2];
		if ( $size < 1 || ( $record !== null && $size !== $record['size'] ) ) { return $result; }
		if ( @getimagesize( $path ) === false ) {
			$result['availability'] = $this->scan_fact( 'unknown', null, null, 'path_unavailable', true );
			return $result;
		}
		$result['availability'] = $this->scan_fact( 'known', 'present', 'header', $record === null ? '' : 'hash_not_requested' );
		$result['authority'] = $this->scan_fact( 'known', $authority === 'live' ? 'unwatermarked' : $authority, $record === null ? 'stored' : 'header', $record === null ? '' : 'hash_not_requested' );
		$result['decode'] = $this->scan_fact( 'known', 'not_requested', 'stored' );
		if ( $depth !== 'full' ) { return $result; }
		if ( $record !== null ) {
			$hash = $this->scan_hash_file( $path, $size, $deadline, $limits );
			if ( $hash === false ) { $result['availability'] = $this->scan_fact( 'unknown', null, null, $deadline !== null && microtime( true ) >= $deadline ? 'deadline' : 'path_unavailable', true ); return $result; }
			if ( $hash !== $record['sha256'] ) { $result['availability'] = $this->scan_fact( 'known', 'absent', 'hash', 'authority_mismatch' ); return $result; }
			$result['authority'] = $this->scan_fact( 'known', $authority, 'hash' );
		}
		if ( $deadline !== null && microtime( true ) >= $deadline ) { $result['decode'] = $this->scan_fact( 'unknown', null, null, 'deadline', true ); return $result; }
		if ( ! $this->scan_decoder_available( $path ) ) { $result['decode'] = $this->scan_fact( 'unknown', null, null, 'decoder_unavailable' ); return $result; }
		$valid = $this->is_valid_backup_file( $path );
		if ( $deadline !== null && microtime( true ) >= $deadline ) { $result['decode'] = $this->scan_fact( 'unknown', null, null, 'deadline', true ); return $result; }
		$result['decode'] = $this->scan_fact( 'known', $valid ? 'passed' : 'failed', 'full' );
		$result['availability'] = $this->scan_fact( 'known', $valid ? 'validated' : 'absent', 'full', $valid ? '' : 'decode_failed' );
		return $result;
	}

	/** A path-free scan observation. */
	private function scan_fact( $state, $value = null, $certainty = null, $reason = '', $retryable = false ) {
		return [ 'state' => $state, 'value' => $value, 'certainty' => $certainty, 'reason' => $reason, 'observed_at' => time(), 'retryable' => $retryable ];
	}

	private function scan_backup_unknown( $reason, $retryable ) {
		$unknown = $this->scan_fact( 'unknown', null, null, $reason, $retryable );
		return [ 'availability' => $unknown, 'identity_matches_main' => $unknown, 'decode' => $unknown, 'bytes' => $unknown ];
	}

	/** Observe only the current attached file's backup; never create its parent. */
	private function scan_backup_facts( $relative, $depth, $deadline, &$limits, &$files ) {
		$unknown = $this->scan_fact( 'unknown', null, null, 'not_measured', false );
		$na = $this->scan_fact( 'not_applicable' );
		$facts = [ 'availability' => $unknown, 'identity_matches_main' => $unknown, 'decode' => $na, 'bytes' => $na ];
		$failure = '';
		$resolved = $this->resolve_operation_root_path( $this->get_backup_root_dir(), $relative, false, '', 0, $failure );
		if ( $resolved === false ) {
			if ( $failure !== 'missing_directory' || ! $this->scan_remember_missing_path( $this->get_backup_root_dir() . '/' . $relative, $files ) ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
			$facts['availability'] = $this->scan_fact( 'known', 'absent', 'header', 'backup_missing' );
			$facts['identity_matches_main'] = $na;
			return $facts;
		}
		$path = $resolved['path'];
		clearstatcache( true, $path );
		if ( ! file_exists( $path ) ) {
			$entries = @scandir( dirname( $path ) );
			if ( $entries === false ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
			foreach ( $entries as $entry ) {
				if ( DIRECTORY_SEPARATOR === '\\' ? strcasecmp( $entry, basename( $path ) ) === 0 : $entry === basename( $path ) ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
			}
			$facts['availability'] = $this->scan_fact( 'known', 'absent', 'header', 'backup_missing' );
			$files[ $path ] = null;
		} elseif ( ! is_file( $path ) ) {
			$facts['availability'] = $this->scan_fact( 'known', 'absent', 'header', 'not_a_file' );
		} else {
			if ( is_readable( $path ) && ! $this->scan_remember_file( $path, $files ) ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
			$size = @filesize( $path );
			if ( $size === false ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
			$facts['bytes'] = $this->scan_fact( 'known', $size, 'header' );
			if ( ! is_readable( $path ) ) {
				$facts['availability'] = $this->scan_fact( 'known', 'absent', 'header', 'file_unreadable' );
			} else {
				$stream = @fopen( $path, 'rb' );
				if ( $stream === false ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
				// A complete short input can be rejected from observed bytes. A failed
				// getimagesize() alone cannot distinguish malformed data from I/O failure.
				$invalid = false;
				if ( $size === 0 ) {
					$invalid = true;
				} elseif ( $size <= 64 ) {
					$bytes = @fread( $stream, $size );
					if ( $bytes === false || strlen( $bytes ) !== $size ) { fclose( $stream ); return $this->scan_backup_unknown( 'path_unavailable', true ); }
					$invalid = @getimagesizefromstring( $bytes ) === false;
				}
				fclose( $stream );
				$header = ! $invalid && ! $this->should_inject_operation_fault( 'scan_header_read', [] ) && $this->is_backup_available_for_display( $path );
				clearstatcache( true, $path );
				$size_after = @filesize( $path );
				if ( $size_after === false || $size_after !== $size || ! is_file( $path ) || ! is_readable( $path ) ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
				if ( $invalid ) {
					$facts['availability'] = $this->scan_fact( 'known', 'absent', 'header', 'image_unreadable' );
					$facts['identity_matches_main'] = $na;
					return $facts;
				}
				if ( ! $header ) { return $this->scan_backup_unknown( 'path_unavailable', true ); }
				$facts['availability'] = $this->scan_fact( 'known', 'present', 'header' );
				$facts['decode'] = $this->scan_fact( 'known', 'not_requested', 'stored' );
				if ( $depth === 'full' ) {
					if ( $deadline !== null && microtime( true ) >= $deadline ) {
						$facts['decode'] = $this->scan_fact( 'unknown', null, null, 'deadline', true );
					} elseif ( ! $this->scan_decoder_available( $path ) ) {
						$facts['decode'] = $this->scan_fact( 'unknown', null, null, 'decoder_unavailable', false );
					} else {
						$limits['full_backup_attempted'] = true;
						$valid = $this->is_valid_backup_file( $path );
						if ( $deadline !== null && microtime( true ) >= $deadline ) {
							$facts['decode'] = $this->scan_fact( 'unknown', null, null, 'deadline', true );
						} else {
							$facts['decode'] = $this->scan_fact( 'known', $valid ? 'passed' : 'failed', 'full' );
							if ( $valid ) { $facts['availability'] = $this->scan_fact( 'known', 'validated', 'full' ); }
						}
					}
				}
				return $facts;
			}
		}
		$facts['identity_matches_main'] = $na;
		return $facts;
	}

	/** Whether either existing decoder path can attempt this file type. */
	private function scan_decoder_available( $path ) {
		$type = wp_check_filetype( $path );
		$mime = isset( $type['type'] ) ? $type['type'] : '';
		$formats = [ 'image/jpeg' => 'JPEG', 'image/pjpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WEBP' ];
		$functions = [ 'image/jpeg' => 'imagecreatefromjpeg', 'image/pjpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp' ];
		if ( isset( $formats[ $mime ] ) && $this->plugin->check_imagick() ) {
			try {
				if ( Imagick::queryFormats( $formats[ $mime ] ) ) { return true; }
			} catch ( Exception $e ) {
				// The GD fallback may still be usable.
			}
		}
		return isset( $functions[ $mime ] ) && $this->gd_function_available( $functions[ $mime ] );
	}

	/**
	 * Classify only explicitly inventoried outcome/code pairs.
	 *
	 * @internal
	 * @return bool|null
	 */
	public function classify_operation_retryability( $outcome, $code ) {
		$codes = [
			'complete' => [ false => [ 'watermarked', 'watermarkremoved', 'regenerated', 'attachment_file_reconciled', 'manifest_unverified', 'preview', 'scanned' ] ],
			'skipped' => [ false => [ 'already_watermarked', 'display_veto', 'no_target_sizes', 'upload_type_changed_unsupported', 'upload_path_unresolved', 'client_side_claim_expired', 'client_side_claim_mismatch', 'client_side_finalize_withheld', 'automatic_disabled', 'attachment_not_found', 'scan_not_applicable', 'is_watermark_source' ] ],
			'failed' => [
				false => [ 'scan_forbidden', 'invalid_metadata', 'invalid_request', 'invalid_sizes', 'invalid_targets', 'validation_failed', 'no_engine', 'unsupported_mime', 'unsupported_type', 'is_watermark_source', 'manual_disabled', 'invalid_custom_dimensions', 'watermark_source_missing', 'watermark_text_empty', 'watermark_font_missing', 'rotation_unavailable', 'client_side_upload_unfinalized', 'attachment_unattached', 'post_type_excluded', 'file_missing', 'small_image', 'no_target_sizes', 'metadata_read_failed', 'image_unreadable', 'backup_missing', 'invalid_backup_path', 'invalid_source_path', 'attachment_file_diverged', 'regenerate_unsupported', 'regenerate_mode_unsupported', 'watermark_type_unsupported', 'no_clean_source', 'size_not_producible', 'shared_alias_conflict', 'display_veto', 'journal_budget_exceeded', 'manifest_budget_exceeded', 'legacy_limit_reached' ],
				true => [ 'iw_run_state_conflict', 'busy', 'journal_invalid', 'journal_write_failed', 'predecessor_uncertain', 'handoff_pending', 'client_side_upload_pending', 'client_side_finalize_deferred', 'recovery_source_invalid', 'recovery_source_missing', 'recovery_interrupted', 'backup_path_unavailable', 'source_path_unavailable', 'backup_failed', 'backup_metadata_commit_failed', 'backup_identity_mismatch', 'restore_failed', 'metadata_regeneration_failed', 'size_generation_failed', 'write_failed', 'rotation_failed', 'metadata_write_failed', 'ownership_uncertain', 'time_budget_exhausted', 'failed_after_mutation' ],
			],
			'partial' => [ false => [ 'scan_unmeasurable' ], true => [ 'scan_incomplete', 'scan_concurrent_change', 'journal_budget_exceeded', 'legacy_limit_reached', 'journal_write_failed', 'restore_failed', 'metadata_regeneration_failed', 'no_targets', 'write_failed', 'rotation_failed', 'metadata_write_failed', 'metadata_fence_lost', 'metadata_commit_interrupted', 'metadata_commit_failed', 'derivative_reconciliation_failed', 'main_identity_changed', 'size_generation_failed', 'directory_changed', 'remap_source_missing', 'remap_conflict', 'ownership_uncertain', 'retained_shared', 'predecessor_map_stale' ] ],
			'interrupted' => [ true => [ 'scan_interrupted', 'interrupted', 'write_failed', 'journal_write_failed', 'recovery_interrupted', 'metadata_fence_lost', 'metadata_write_failed', 'completion_fence_lost', 'promotion_failed', 'handoff_unverified' ] ],
		];
		if ( ! is_string( $outcome ) || ! is_string( $code ) || ! isset( $codes[ $outcome ] ) ) {
			return null;
		}
		foreach ( $codes[ $outcome ] as $retryable => $members ) {
			if ( in_array( $code, $members, true ) ) {
				return (bool) $retryable;
			}
		}
		return null;
	}

	/** Project a legacy result without changing its retryability. */
	private function contract_operation_outcome( $operation, $legacy_outcome, $identity = [] ) {
		$legacy_outcome = is_array( $legacy_outcome ) ? $legacy_outcome : [];
		$outcome = isset( $legacy_outcome['outcome'] ) ? $legacy_outcome['outcome'] : 'interrupted';
		$code = isset( $legacy_outcome['code'] ) ? sanitize_key( $legacy_outcome['code'] ) : '';
		return [
			'operation' => $operation,
			'outcome' => $outcome,
			'operation_id' => isset( $legacy_outcome['operation_id'] ) ? $legacy_outcome['operation_id'] : '',
			'code' => $code,
			'message' => $this->sanitize_operation_message( isset( $legacy_outcome['message'] ) ? $legacy_outcome['message'] : '', true ),
			'retryable' => $this->classify_operation_retryability( $outcome, $code ),
			'sizes' => $this->normalize_operation_sizes( isset( $legacy_outcome['sizes'] ) ? $legacy_outcome['sizes'] : [] ),
			'run' => isset( $identity['run'] ) ? $identity['run'] : null,
			'attempt' => isset( $identity['attempt'] ) ? $identity['attempt'] : null,
		];
	}

	/** @return array */
	private function normalize_operation_sizes( $sizes ) {
		$normalized = [ 'processed' => [], 'skipped' => [], 'failed' => [] ];
		foreach ( $normalized as $state => $empty ) {
			foreach ( isset( $sizes[ $state ] ) && is_array( $sizes[ $state ] ) ? $sizes[ $state ] : [] as $size ) {
				// Size identifiers come from the effective definitions. Rewriting
				// them would make distinct names indistinguishable in the result.
				$name = is_array( $size ) ? ( isset( $size['name'] ) ? $size['name'] : null ) : $size;
				if ( ! is_string( $name ) || $name === '' || strpos( $name, "\0" ) !== false ) {
					continue;
				}
				$code = is_array( $size ) && isset( $size['code'] ) ? sanitize_key( $size['code'] ) : '';
				$normalized[ $state ][] = $state === 'processed' ? $name : [ 'name' => $name, 'code' => $code ];
			}
		}
		return $normalized;
	}

	/** @return string */
	private function sanitize_operation_message( $message, $read_only = false ) {
		$message = sanitize_text_field( $message );
		if ( $message === '' ) {
			return '';
		}
		$upload  = $this->operation_upload_dir( $read_only );
		if ( is_array( $upload ) && ! empty( $upload['basedir'] ) ) {
			$message = str_replace( [ $upload['basedir'], wp_normalize_path( $upload['basedir'] ) ], '[upload]', $message );
		}
		return preg_replace( '#(?:[A-Za-z]:)?[\\\\/][^\s]+#', '[path]', $message );
	}

	/**
	 * Option name used by the atomic, per-site operation lease.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function operation_lock_name( $attachment_id ) {
		return '_iw_operation_lock_' . (int) $attachment_id;
	}

	/**
	 * Create a token suitable for private journal and stage ownership.
	 *
	 * @return string
	 */
	private function operation_token() {
		return wp_generate_password( 64, false, false );
	}

	/**
	 * Return a bounded, validated attachment operation journal.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|false
	 */
	private function read_operation_journal( $attachment_id ) {
		$journal = get_post_meta( $attachment_id, '_iw_operation_journal', true );

		if ( $journal === '' || $journal === [] || $journal === null ) {
			return [ 'version' => 1, 'current' => null, 'recoverable_predecessor' => null, 'history' => [] ];
		}

		return $this->is_valid_operation_journal( $journal ) ? $journal : false;
	}

	/** @return bool Whether read_operation_journal() accepts this stored value. */
	private function is_valid_operation_journal( $journal ) {
		if ( strlen( maybe_serialize( $journal ) ) > 131072 || ! is_array( $journal ) || ! isset( $journal['version'] ) || (int) $journal['version'] !== 1 || ! array_key_exists( 'current', $journal ) || ! isset( $journal['history'] ) || ! is_array( $journal['history'] ) || count( $journal['history'] ) > 2 || ! $this->is_valid_operation_record( $journal['current'] ) || ( ! empty( $journal['recoverable_predecessor'] ) && ! $this->is_valid_operation_record( $journal['recoverable_predecessor'] ) ) ) {
			return false;
		}
		foreach ( $journal['history'] as $record ) {
			if ( ! $this->is_valid_operation_record( $record ) ) {
				return false;
			}
		}

		return true;
	}

	/** @return bool */
	private function is_valid_operation_record( $record ) {
		if ( $record === null ) {
			return true;
		}
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! is_string( $record['token'] ) || empty( $record['state'] ) || ! in_array( $record['state'], [ 'preparing', 'backup-done', 'rendering', 'staging', 'promoting', 'regenerating', 'metadata-commit', 'complete', 'partial', 'interrupted', 'skipped', 'failed' ], true ) || ! isset( $record['targets'] ) || ! is_array( $record['targets'] ) || count( $record['targets'] ) > 50 ) {
			return false;
		}
		if ( array_key_exists( 'run_identity', $record ) && ( $this->normalize_operation_identity( $record['run_identity'] ) === false || $record['run_identity'] === [] ) ) {
			return false;
		}
		foreach ( $record['targets'] as $target ) {
			if ( ! is_array( $target ) || empty( $target['target'] ) || ! is_string( $target['target'] ) || strpos( $target['target'], '..' ) !== false || strpos( $target['target'], ':' ) !== false || ( isset( $target['stage'] ) && ( ! is_string( $target['stage'] ) || strpos( $target['stage'], '..' ) !== false || strpos( $target['stage'], ':' ) !== false || strpos( basename( $target['stage'] ), '.iw-stage-' ) !== 0 ) ) || ( isset( $target['size'] ) && ( ! is_int( $target['size'] ) || $target['size'] < 0 ) ) || ( isset( $target['hash'] ) && ( ! is_string( $target['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $target['hash'] ) ) ) ) {
				return false;
			}
			$target_relative = $this->normalize_operation_relative_path( $target['target'] );
			if ( $target_relative === false ) {
				return false;
			}
			if ( isset( $target['stage'] ) ) {
				$stage_relative = $this->normalize_operation_relative_path( $target['stage'] );
				$legacy_restoration = $this->target_is_legacy_restoration( $target, $record['token'] );
				if ( $stage_relative === false || dirname( $stage_relative ) !== dirname( $target_relative ) || strpos( basename( $stage_relative ), '.iw-stage-' . $record['token'] . '-' ) !== 0 || ( ( ! isset( $target['root'] ) || $target['root'] !== 'uploads' ) && ! $legacy_restoration ) ) {
					return false;
				}
			}
			if ( isset( $target['recovery'] ) ) {
				$recovery = $target['recovery'];
				if ( ! is_array( $recovery ) || ! isset( $recovery['state'], $recovery['root'], $recovery['path'] ) || ! in_array( $recovery['state'], [ 'allocated', 'verified' ], true ) || $recovery['root'] !== 'uploads' || $this->normalize_operation_relative_path( $recovery['path'] ) !== $this->operation_recovery_relative_path( $target_relative, $record['token'] ) ) {
					return false;
				}
				if ( $recovery['state'] === 'verified' && ( ! isset( $recovery['size'], $recovery['hash'] ) || ! is_int( $recovery['size'] ) || $recovery['size'] < 1 || ! is_string( $recovery['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $recovery['hash'] ) ) ) {
					return false;
				}
			}
		}
		if ( isset( $record['restoration'] ) && ( ! is_array( $record['restoration'] ) || empty( $record['restoration']['target'] ) || ! is_string( $record['restoration']['target'] ) || strpos( $record['restoration']['target'], '..' ) !== false || strpos( $record['restoration']['target'], ':' ) !== false || empty( $record['restoration']['stage'] ) || ! is_string( $record['restoration']['stage'] ) || strpos( $record['restoration']['stage'], '..' ) !== false || strpos( $record['restoration']['stage'], ':' ) !== false || strpos( basename( $record['restoration']['stage'] ), '.iw-stage-' ) !== 0 ) ) {
			return false;
		}
		if ( isset( $record['temporary_recovery_restore'] ) && ( ! is_array( $record['temporary_recovery_restore'] ) || empty( $record['temporary_recovery_restore']['target'] ) || ! is_string( $record['temporary_recovery_restore']['target'] ) || strpos( $record['temporary_recovery_restore']['target'], '..' ) !== false || strpos( $record['temporary_recovery_restore']['target'], ':' ) !== false || empty( $record['temporary_recovery_restore']['stage'] ) || ! is_string( $record['temporary_recovery_restore']['stage'] ) || strpos( $record['temporary_recovery_restore']['stage'], '..' ) !== false || strpos( $record['temporary_recovery_restore']['stage'], ':' ) !== false || strpos( basename( $record['temporary_recovery_restore']['stage'] ), '.iw-stage-' . $record['token'] . '-' ) !== 0 || ! isset( $record['temporary_recovery_restore']['root'] ) || $record['temporary_recovery_restore']['root'] !== 'uploads' ) ) {
			return false;
		}
		if ( isset( $record['temporary_recovery_restore'] ) ) {
			$temporary_restore = $record['temporary_recovery_restore'];
			$temporary_target = $this->normalize_operation_relative_path( $temporary_restore['target'] );
			$temporary_stage = $this->normalize_operation_relative_path( $temporary_restore['stage'] );
			$expected_stage = $temporary_target === false ? false : str_replace( '\\', '/', $this->operation_stage_path( $temporary_target, $record['token'], 'temporary-recovery-' . $temporary_target ) );
			if ( $temporary_target === false || $temporary_stage === false || $temporary_stage !== $expected_stage || dirname( $temporary_stage ) !== dirname( $temporary_target ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'backup', $record ) ) {
			$backup = $record['backup'];
			if ( ! is_array( $backup ) || ( array_key_exists( 'state', $backup ) && ! is_string( $backup['state'] ) ) || ( array_key_exists( 'root', $backup ) && ! in_array( $backup['root'], [ 'backup', 'uploads' ], true ) ) ) {
				return false;
			}
			foreach ( [ 'path', 'recovery', 'stage' ] as $key ) {
				if ( ! array_key_exists( $key, $backup ) || $backup[ $key ] === false ) {
					continue;
				}
				if ( ! is_string( $backup[ $key ] ) || strpos( $backup[ $key ], "\0" ) !== false || strpos( $backup[ $key ], '..' ) !== false || strpos( $backup[ $key ], ':' ) !== false || ( $key === 'stage' && strpos( basename( $backup[ $key ] ), '.iw-stage-' ) !== 0 ) ) {
					return false;
				}
			}
		}
		if ( isset( $record['derivative_recovery'] ) ) {
			if ( ! is_array( $record['derivative_recovery'] ) ) {
				return false;
			}
			foreach ( [ 'previous', 'regenerated' ] as $key ) {
				if ( isset( $record['derivative_recovery'][ $key ] ) && ( ! is_array( $record['derivative_recovery'][ $key ] ) ) ) {
					return false;
				}
				foreach ( isset( $record['derivative_recovery'][ $key ] ) ? $record['derivative_recovery'][ $key ] : [] as $name => $path ) {
					if ( ! is_string( $name ) || ! is_string( $path ) || $path === '' || strpos( $path, "\0" ) !== false || strpos( $path, '..' ) !== false || strpos( $path, ':' ) !== false ) {
						return false;
					}
				}
			}
			foreach ( [ 'retired', 'retiring' ] as $key ) {
				if ( isset( $record['derivative_recovery'][ $key ] ) && ! is_array( $record['derivative_recovery'][ $key ] ) ) {
					return false;
				}
				foreach ( isset( $record['derivative_recovery'][ $key ] ) ? $record['derivative_recovery'][ $key ] : [] as $path ) {
					if ( ! is_string( $path ) || $path === '' || strpos( $path, "\0" ) !== false || strpos( $path, '..' ) !== false || strpos( $path, ':' ) !== false ) {
						return false;
					}
				}
			}
			if ( isset( $record['derivative_recovery']['outcomes'] ) ) {
				if ( ! is_array( $record['derivative_recovery']['outcomes'] ) ) {
					return false;
				}
				foreach ( $record['derivative_recovery']['outcomes'] as $name => $outcome ) {
					if ( ! is_string( $name ) || ! is_string( $outcome ) ) {
						return false;
					}
				}
			}
			if ( isset( $record['derivative_recovery']['remap'] ) && ! $this->is_valid_name_stable_remap( $record['derivative_recovery']['remap'] ) ) {
				return false;
			}
		}
		if ( isset( $record['pre_operation'] ) && ! $this->is_valid_pre_operation( $record['pre_operation'] ) ) {
			return false;
		}
		if ( array_key_exists( 'manifest_evidence', $record ) && ! $this->is_valid_manifest_evidence( $record['manifest_evidence'], $record['token'] ) ) {
			return false;
		}
		if ( array_key_exists( 'metadata_handoff', $record ) && ( ! in_array( $record['metadata_handoff'], [ 'pending', 'verified' ], true ) || ! isset( $record['manifest_evidence'] ) ) ) {
			return false;
		}
		if ( array_key_exists( 'main_recovery', $record ) && ! $this->is_valid_main_recovery( $record['main_recovery'], $record['token'] ) ) {
			return false;
		}
		if ( array_key_exists( 'generation_stage', $record ) && ! $this->is_valid_generation_stage( $record['generation_stage'], $record['token'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validate a journaled manifest evidence value: the manifest the operation
	 * writes, without its aliases (they are derived from the record's targets),
	 * and the metadata references it must still match.
	 *
	 * @param mixed  $evidence Evidence value.
	 * @param string $token Owning record token.
	 * @return bool
	 */
	private function is_valid_manifest_evidence( $evidence, $token ) {
		if ( ! is_array( $evidence ) || ! isset( $evidence['version'], $evidence['manifest'], $evidence['references'] ) || $evidence['version'] !== 1 || ! is_array( $evidence['manifest'] ) || array_key_exists( 'aliases', $evidence['manifest'] ) || ! $this->is_valid_manifest( $this->manifest_with_aliases( $evidence['manifest'], [] ) ) || $evidence['manifest']['token'] !== $token ) {
			return false;
		}
		$references = $evidence['references'];
		if ( ! is_array( $references ) || ! isset( $references['file'], $references['sizes'] ) || ! is_string( $references['file'] ) || $this->normalize_operation_relative_path( $references['file'] ) === false || ! is_array( $references['sizes'] ) || count( $references['sizes'] ) > self::MANIFEST_NAME_LIMIT ) {
			return false;
		}
		foreach ( $references['sizes'] as $name => $file ) {
			if ( ! is_string( $name ) || $name === '' || ! is_string( $file ) || $file === '' || $file !== wp_basename( $file ) || strpos( $file, "\0" ) !== false ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate a watermark manifest value against its registered shape.
	 *
	 * @param mixed $manifest Manifest value.
	 * @return bool
	 */
	private function is_valid_manifest( $manifest ) {
		if ( ! is_array( $manifest ) || ! isset( $manifest['version'], $manifest['token'], $manifest['committed_at'], $manifest['main'], $manifest['aliases'], $manifest['legacy'], $manifest['settings'] ) || ! array_key_exists( 'provenance', $manifest ) ) {
			return false;
		}
		if ( $manifest['version'] !== self::MANIFEST_VERSION || ! is_string( $manifest['token'] ) || ! preg_match( '/^[A-Za-z0-9]{64}$/', $manifest['token'] ) || ! is_int( $manifest['committed_at'] ) || ! is_string( $manifest['settings'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $manifest['settings'] ) ) {
			return false;
		}
		$main = $manifest['main'];
		if ( ! $this->is_valid_manifest_file( $main ) || ! isset( $main['watermarked'] ) || ! is_bool( $main['watermarked'] ) ) {
			return false;
		}
		if ( ! is_array( $manifest['aliases'] ) || count( $manifest['aliases'] ) > self::MANIFEST_NAME_LIMIT || ! is_array( $manifest['legacy'] ) || count( $manifest['legacy'] ) > self::MANIFEST_NAME_LIMIT ) {
			return false;
		}
		$names = 0;
		foreach ( $manifest['aliases'] as $alias ) {
			if ( ! $this->is_valid_manifest_file( $alias ) || ! isset( $alias['names'] ) || ! is_array( $alias['names'] ) || empty( $alias['names'] ) ) {
				return false;
			}
			foreach ( $alias['names'] as $name ) {
				if ( ! is_string( $name ) || $name === '' ) {
					return false;
				}
			}
			$names += count( $alias['names'] );
		}
		foreach ( $manifest['legacy'] as $file ) {
			if ( ! $this->is_valid_manifest_file( $file ) ) {
				return false;
			}
		}
		$provenance = $manifest['provenance'];
		if ( $provenance !== null && ( ! is_array( $provenance ) || ! isset( $provenance['file'], $provenance['size'], $provenance['sha256'] ) || ! is_string( $provenance['file'] ) || $provenance['file'] !== wp_basename( $provenance['file'] ) || ! is_int( $provenance['size'] ) || $provenance['size'] < 1 || ! is_string( $provenance['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $provenance['sha256'] ) ) ) {
			return false;
		}

		return $names <= self::MANIFEST_NAME_LIMIT && strlen( serialize( $manifest ) ) <= self::MANIFEST_MAX_BYTES;
	}

	/** @return bool Whether a manifest file entry has a valid relative path, size, and hash. */
	private function is_valid_manifest_file( $file ) {
		return is_array( $file ) && isset( $file['path'], $file['size'], $file['sha256'] ) && is_string( $file['path'] ) && $this->normalize_operation_relative_path( $file['path'] ) === $file['path'] && is_int( $file['size'] ) && $file['size'] >= 1 && is_string( $file['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', $file['sha256'] );
	}

	/**
	 * The manifest with its aliases, in the registered key order.
	 *
	 * @param array $base Manifest without aliases.
	 * @param array $aliases Alias entries.
	 * @return array
	 */
	private function manifest_with_aliases( $base, $aliases ) {
		$manifest = [];
		foreach ( [ 'version', 'token', 'committed_at', 'main' ] as $key ) {
			if ( array_key_exists( $key, $base ) ) {
				$manifest[ $key ] = $base[ $key ];
			}
		}
		$manifest['aliases'] = $aliases;
		foreach ( $base as $key => $value ) {
			if ( ! array_key_exists( $key, $manifest ) ) {
				$manifest[ $key ] = $value;
			}
		}

		return $manifest;
	}

	/**
	 * Rebuild the manifest from its journaled evidence and the same record's
	 * targets: every promoted watermark output other than the main file's, in
	 * record order, is an alias file.
	 *
	 * @param array $evidence Manifest evidence.
	 * @param array $record Journal record that holds the evidence.
	 * @return array|false
	 */
	private function manifest_from_evidence( $evidence, $record ) {
		$aliases = [];
		$covered = [];
		foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
			if ( ! isset( $target['intent'], $target['state'] ) || $target['intent'] !== 'watermark-output' || $target['state'] !== 'promoted' || $target['target'] === $evidence['references']['file'] ) {
				continue;
			}
			if ( ! isset( $target['size'], $target['hash'], $target['aliases'] ) || ! is_array( $target['aliases'] ) ) {
				return false;
			}
			$aliases[] = [ 'names' => array_values( $target['aliases'] ), 'path' => $target['target'], 'size' => $target['size'], 'sha256' => $target['hash'] ];
			foreach ( $target['aliases'] as $name ) {
				$covered[ (string) $name ] = wp_basename( $target['target'] );
			}
		}
		// The derived aliases must cover exactly the journaled size references,
		// so a lost or altered target never yields a smaller manifest.
		unset( $covered['full'] );
		ksort( $covered );
		$references = $evidence['references']['sizes'];
		ksort( $references );
		if ( $covered !== $references ) {
			return false;
		}
		$manifest = $this->manifest_with_aliases( $evidence['manifest'], $aliases );

		return $this->is_valid_manifest( $manifest ) ? $manifest : false;
	}

	/** @return bool */
	private function should_inject_operation_fault( $boundary, $context = [] ) {
		return $this->operation_fault_injector && call_user_func( $this->operation_fault_injector, $boundary, $context );
	}

	/**
	 * Persist and reread a private journal before a destructive boundary.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $journal Journal value.
	 * @return bool
	 */
	private function write_operation_journal( $attachment_id, $journal ) {
		// A journal the reader would reject is never stored: it would block every
		// later operation on the attachment. The stored record stays as it was.
		if ( ! $this->is_valid_operation_journal( $journal ) ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'journal_write', [ 'attachment_id' => $attachment_id, 'state' => isset( $journal['current']['state'] ) ? $journal['current']['state'] : '' ] ) ) {
			return false;
		}

		update_post_meta( $attachment_id, '_iw_operation_journal', $journal );
		$reread = get_post_meta( $attachment_id, '_iw_operation_journal', true );

		return is_array( $reread ) && maybe_serialize( $reread ) === maybe_serialize( $journal );
	}

	/** Publish only a freshly confirmed candidate; an ambiguous write ends this owner. */
	private function publish_operation_journal( &$operation, $candidate ) {
		if ( ! empty( $operation['journal_uncertain'] ) ) {
			return false;
		}
		// Token-named regeneration can acquire valid late evidence after its
		// reservation. Reader headroom is not working space for a new value.
		// Refuse the whole candidate, including a local failure receipt, without
		// changing the last confirmed (possibly larger, but readable) record.
		if ( ! empty( $operation['name_stable_budget'] ) && ( ! $this->is_valid_operation_journal( $candidate ) || strlen( maybe_serialize( $candidate ) ) > self::NAME_STABILITY_JOURNAL_BUDGET ) ) {
			return false;
		}
		try {
			if ( ! $this->renew_operation_lock( $operation ) ) {
				$operation['journal_uncertain'] = true;
				return false;
			}
		} catch ( \Throwable $caught ) {
			$operation['journal_uncertain'] = true;
			throw $caught;
		}
		$previous = isset( $operation['journal'] ) ? $operation['journal'] : null;
		$error = null;
		$written = false;
		try {
			$written = $this->write_operation_journal( $operation['attachment_id'], $candidate );
		} catch ( \Throwable $caught ) {
			$error = $caught;
		}
		try {
			wp_cache_delete( $operation['attachment_id'], 'post_meta' );
			$actual = $this->read_operation_journal( $operation['attachment_id'] );
			if ( ! $this->renew_operation_lock( $operation ) || $actual === false ) {
				$operation['journal_uncertain'] = true;
			} elseif ( maybe_serialize( $actual ) === maybe_serialize( $candidate ) && ( $written || $previous === null || maybe_serialize( $actual ) !== maybe_serialize( $previous ) ) ) {
				$operation['journal'] = $candidate;
				$written = true;
			} elseif ( $previous === null || maybe_serialize( $actual ) !== maybe_serialize( $previous ) ) {
				$operation['journal_uncertain'] = true;
			} else {
				$written = false;
			}
		} catch ( \Throwable $caught ) {
			// No settlement may publish when confirmation or its fence is unknown.
			$operation['journal_uncertain'] = true;
			throw $caught;
		}
		if ( $error !== null ) {
			throw $error;
		}
		return empty( $operation['journal_uncertain'] ) && $written;
	}

	/** Read lease bytes from the database, not a potentially stale options cache. */
	private function read_operation_lock_row( $name ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		return $wpdb->last_error ? false : $row;
	}

	/** Invalidate cached copies without loading or rewriting the alloptions map. */
	private function invalidate_operation_lock_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$alloptions = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $alloptions ) && array_key_exists( $name, $alloptions ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/** Insert only if absent and verify the persisted bytes before claiming ownership. */
	private function insert_operation_lock( $name, $lock, &$read_error = null ) {
		global $wpdb;
		$raw = maybe_serialize( $lock );
		$previous = $wpdb->suppress_errors( true );
		try {
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $raw ) );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
		$this->invalidate_operation_lock_cache( $name );
		if ( $inserted !== 1 ) {
			return false;
		}
		$row = $this->read_operation_lock_row( $name );
		if ( $row === false ) { $read_error = true; }
		return is_array( $row ) && $row['option_value'] === $raw;
	}

	/**
	 * Atomically acquire a fenced attachment lease. A busy lease is never waited
	 * on; a stale lease is reclaimed only after a compare-and-delete fence.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token Operation token.
	 * @return array|false Lock record, or false when busy.
	 */
	private function acquire_operation_lock( $attachment_id, $token, &$read_error = null ) {
		$read_error = false;
		$name = $this->operation_lock_name( $attachment_id );
		$now = time();
		$lock = [ 'token' => $token, 'version' => 1, 'acquired_at' => $now, 'heartbeat_at' => $now ];

		$row = $this->read_operation_lock_row( $name );
		if ( $row === false ) {
			$read_error = true;
			return false;
		}
		if ( $row === null ) {
			if ( ! $this->insert_operation_lock( $name, $lock, $read_error ) ) {
				return false;
			}
			$this->held_operation_leases[ get_current_blog_id() . ':' . (int) $attachment_id ] = $token;
			return $lock;
		}

		$observed = @unserialize( $row['option_value'], [ 'allowed_classes' => false ] );
		if ( ! is_array( $observed ) || empty( $observed['token'] ) || empty( $observed['heartbeat_at'] ) || (int) $observed['heartbeat_at'] > $now - 900 ) {
			return false;
		}

		if ( func_num_args() >= 3 ) {
			$query_error = false;
			$raw_journal = $this->observe_runner_journal( $attachment_id, $query_error );
			if ( $query_error ) { $read_error = true; return false; }
			$journal = $raw_journal === false ? false : $raw_journal['value'];
		} else {
			$journal = $this->read_operation_journal( $attachment_id );
		}
		if ( $journal === false ) {
			return false;
		}
		if ( ! empty( $journal['current'] ) && ! empty( $journal['current']['token'] ) && $journal['current']['token'] === $observed['token'] ) {
			// A terminal record has no live canonical-file writer. This is explicit
			// terminal lease cleanup, not stale live-operation takeover.
			if ( $this->operation_is_terminal( $journal['current'] ) ) {
				// Continue to the exact-value CAD below solely to remove an orphaned
				// terminal lease. No predecessor recovery or live-write takeover occurs.
			} else {
				// Non-terminal stale reclaim follows the same CAD fence and the new
				// owner reconciles before any canonical mutation.
			}
		} else {
			// An expired lease without its journal token is an orphan. Reclaim it only
			// through the exact-value CAD fence before the new owner reconciles.
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-delete is the operation lock ownership fence; core APIs cannot provide this atomic operation.
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_id = %d AND option_name = %s AND BINARY option_value = %s",
			$row['option_id'],
			$name,
			$row['option_value']
		) );

		$this->invalidate_operation_lock_cache( $name );
		if ( $deleted !== 1 ) {
			return false;
		}

		if ( ! $this->insert_operation_lock( $name, $lock, $read_error ) ) {
			return false;
		}
		$this->held_operation_leases[ get_current_blog_id() . ':' . (int) $attachment_id ] = $token;

		return $lock;
	}

	/** @param array $operation Current private operation. @return bool */
	private function renew_operation_lock( &$operation ) {
		global $wpdb;
		$old_lock = $operation['lock'];
		$new_lock = $old_lock;
		// MySQL reports zero changed rows when a same-second heartbeat is written;
		// advance the persisted fence monotonically so a zero result always means
		// the exact prior lease was lost.
		$new_lock['heartbeat_at'] = max( time(), (int) $old_lock['heartbeat_at'] + 1 );
		$name = $this->operation_lock_name( $operation['attachment_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-swap is the operation lock ownership fence; core APIs cannot provide this atomic operation.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s",
			maybe_serialize( $new_lock ),
			$name,
			maybe_serialize( $old_lock )
		) );
		$this->invalidate_operation_lock_cache( $name );
		if ( $updated !== 1 ) {
			return false;
		}
		$operation['lock'] = $new_lock;
		if ( ! empty( $new_lock['deleting'] ) ) {
			$this->attachment_deletion_fences[ (int) $operation['attachment_id'] ] = $new_lock;
		}
		return true;
	}

	/** @param array $operation Current private operation. @return void */
	private function release_operation_lock( $operation ) {
		$held = get_current_blog_id() . ':' . (int) $operation['attachment_id'];
		if ( isset( $this->held_operation_leases[ $held ] ) && $this->held_operation_leases[ $held ] === $operation['token'] ) {
			unset( $this->held_operation_leases[ $held ] );
		}
		if ( $this->should_inject_operation_fault( 'lock_release', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			return;
		}
		global $wpdb;
		$name = $this->operation_lock_name( $operation['attachment_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-delete is the operation lock release fence; core APIs cannot provide this atomic operation.
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s",
			$name,
			maybe_serialize( $operation['lock'] )
		) );
		$this->invalidate_operation_lock_cache( $name );
	}

	/** @return array|false */
	private function acquire_attachment_deletion_fence( $attachment_id ) {
		$name = $this->operation_lock_name( $attachment_id );
		$row = $this->read_operation_lock_row( $name );
		if ( $row === false ) {
			return false;
		}
		$observed = is_array( $row ) ? @unserialize( $row['option_value'], [ 'allowed_classes' => false ] ) : false;
		if ( is_array( $row ) && ! is_array( $observed ) ) {
			return false;
		}
		$now = time();
		$fence = [
			'token' => $this->operation_token(),
			'version' => 1,
			'acquired_at' => $now,
			'heartbeat_at' => $now,
			'deleting' => true,
			'superseded_token' => is_array( $observed ) && ! empty( $observed['token'] ) ? $observed['token'] : '',
			'superseded_value' => is_array( $observed ) ? maybe_serialize( $observed ) : '',
		];
		$this->should_inject_operation_fault( 'deletion_fence_after_observe', [ 'attachment_id' => $attachment_id, 'observed' => $observed ] );
		if ( $observed === false ) {
			if ( ! $this->insert_operation_lock( $name, $fence ) ) {
				return false;
			}
		} else {
			if ( ! is_array( $observed ) ) {
				return false;
			}
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value replacement revokes only the observed attachment lease before deletion.
			$replaced = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_id = %d AND option_name = %s AND BINARY option_value = %s",
				maybe_serialize( $fence ),
				$row['option_id'],
				$name,
				$row['option_value']
			) );
			$this->invalidate_operation_lock_cache( $name );
			if ( $replaced !== 1 ) {
				return false;
			}
		}
		return $fence;
	}

	/**
	 * Acquire the deletion fence at WordPress's abortable boundary. A collision
	 * returns false so deletion cannot continue unfenced; the caller may retry.
	 *
	 * @return mixed
	 */
	public function pre_delete_attachment( $delete, $post, $force_delete ) {
		if ( null !== $delete || ! is_object( $post ) || empty( $post->ID ) ) {
			return $delete;
		}
		$attachment_id = (int) $post->ID;
		if ( ! empty( $this->attachment_deletion_fences[ $attachment_id ] ) ) {
			return null;
		}
		$existing_row = $this->read_operation_lock_row( $this->operation_lock_name( $attachment_id ) );
		if ( $existing_row === false ) {
			return false;
		}
		$existing_fence = is_array( $existing_row ) ? @unserialize( $existing_row['option_value'], [ 'allowed_classes' => false ] ) : false;
		if ( is_array( $existing_fence ) && ! empty( $existing_fence['deleting'] ) && ! empty( $existing_fence['token'] ) && isset( $existing_fence['version'] ) && (int) $existing_fence['version'] === 1 ) {
			return null;
		}
		$fence = $this->acquire_attachment_deletion_fence( $attachment_id );
		if ( $fence === false ) {
			return false;
		}
		$this->attachment_deletion_fences[ $attachment_id ] = $fence;
		return null;
	}

	/**
	 * Release a request-local deletion fence after WordPress removes attachment
	 * metadata. This receives all deleted posts but has data only for attachments
	 * fenced by this handler in the current request.
	 */
	public function after_delete_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( empty( $this->attachment_deletion_fences[ $attachment_id ] ) ) {
			return;
		}
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $this->attachment_deletion_fences[ $attachment_id ]['token'], 'lock' => $this->attachment_deletion_fences[ $attachment_id ] ];
		$this->release_operation_lock( $operation );
		unset( $this->attachment_deletion_fences[ $attachment_id ] );
	}

	/** @return void */
	public function release_pending_attachment_deletion_fences() {
		foreach ( array_keys( $this->attachment_deletion_fences ) as $attachment_id ) {
			$this->after_delete_attachment( $attachment_id );
		}
	}

	/** @param array $record Operation record. @return bool */
	private function operation_is_terminal( $record ) {
		return is_array( $record ) && isset( $record['state'] ) && in_array( $record['state'], [ 'complete', 'partial', 'interrupted', 'skipped', 'failed' ], true );
	}

	/** @param string $path Absolute upload path. @param array $upload_dir Upload paths. @return string|false */
	private function operation_relative_path( $path, $upload_dir ) {
		$identity = $this->operation_path_identity( $path, $upload_dir );
		return $identity === false ? false : $identity['relative'];
	}

	/** @return string|false */
	private function operation_destination_relative_path( $path, $upload_dir ) {
		if ( ! is_string( $path ) || strpos( $path, "\0" ) !== false || basename( $path ) === '' || basename( $path ) === '.' || basename( $path ) === '..' ) {
			return false;
		}
		$base = realpath( $upload_dir['basedir'] );
		$directory = realpath( dirname( $path ) );
		if ( $base === false || $directory === false ) {
			return false;
		}
		$base = rtrim( wp_normalize_path( $base ), '/' );
		$directory = wp_normalize_path( $directory );
		$compare_base = DIRECTORY_SEPARATOR === '\\' ? strtolower( $base ) : $base;
		$compare_directory = DIRECTORY_SEPARATOR === '\\' ? strtolower( $directory ) : $directory;
		if ( $compare_directory !== $compare_base && strpos( $compare_directory, $compare_base . '/' ) !== 0 ) {
			return false;
		}
		$relative_directory = $compare_directory === $compare_base ? '' : substr( $directory, strlen( $base ) + 1 );
		return ( $relative_directory === '' ? '' : $relative_directory . '/' ) . basename( $path );
	}

	/** @return array|false */
	private function operation_path_identity( $path, $upload_dir ) {
		if ( ! is_string( $path ) || strpos( $path, "\0" ) !== false || ! is_file( $path ) ) {
			return false;
		}
		$base = realpath( $upload_dir['basedir'] );
		$resolved = realpath( $path );
		if ( $base === false || $resolved === false ) {
			return false;
		}
		$base = rtrim( wp_normalize_path( $base ), '/' );
		$resolved = wp_normalize_path( $resolved );
		$compare_base = DIRECTORY_SEPARATOR === '\\' ? strtolower( $base ) : $base;
		$compare_path = DIRECTORY_SEPARATOR === '\\' ? strtolower( $resolved ) : $resolved;
		if ( strpos( $compare_path, $compare_base . '/' ) !== 0 ) {
			return false;
		}
		$relative = substr( $resolved, strlen( $base ) + 1 );
		if ( $relative === '' || strpos( $relative, '../' ) !== false || $relative === '..' ) {
			return false;
		}
		return [ 'path' => $resolved, 'relative' => $relative, 'identity' => $compare_path ];
	}

	/** @param array $targets Logical targets. @param array $upload_dir Upload paths. @return array|false */
	private function operation_target_groups( $targets, $upload_dir ) {
		$groups = [];
		foreach ( $targets as $size => $path ) {
			$identity = $this->operation_path_identity( $path, $upload_dir );
			if ( $identity === false ) {
				return false;
			}
			if ( ! isset( $groups[ $identity['identity'] ] ) ) {
				$groups[ $identity['identity'] ] = [ 'path' => $path, 'target' => $identity['relative'], 'aliases' => [] ];
			}
			$groups[ $identity['identity'] ]['aliases'][] = $size;
		}
		return count( $groups ) > 50 ? false : $groups;
	}

	/** @return array */
	private function persist_terminal_outcome( $attachment_id, $type, $context, $state, $code, $message, $last_status = 'skipped', $invocation = null ) {
		// A run settles the selected watermark image as an explained, non-retryable
		// skip; dispatches without run identity keep the failed refusal (A-P04-11).
		if ( $invocation !== null && $state === 'failed' && sanitize_key( $code ) === 'is_watermark_source' ) {
			$state = 'skipped';
			$last_status = 'skipped';
		}
		$token = $invocation !== null ? $invocation->operation_state['token'] : $this->operation_token();
		$operation = null;
		$result = null;
		try {
			$lock = $invocation !== null ? $invocation->operation_state['lock'] : $this->acquire_operation_lock( $attachment_id, $token );
			if ( $lock === false ) {
				$result = $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true );
				return $result;
			}
			$operation = $invocation !== null ? $this->borrow_contract_operation( $invocation ) : [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ];
			if ( $invocation !== null ) { $operation['name_stable_budget'] = true; }
			if ( ! $this->begin_operation( $operation, $type, $context, [] ) ) {
				$result = isset( $operation['begin_error_code'] ) ? $this->begin_failure_result( $operation, $token ) : $this->operation_result( 'interrupted', $token, 'journal_write_failed', $message, true );
				return $result;
			}
			if ( ! $this->set_operation_state( $operation, $state, [ 'code' => $code, 'counts' => [ 'processed' => 0, 'failed' => 0 ] ] ) ) {
				$result = $this->operation_result( 'interrupted', $token, 'journal_write_failed', $message, true );
				return $result;
			}
			$sizes = [ 'processed' => [], 'skipped' => $state === 'skipped' ? [ [ 'name' => 'all', 'code' => $code ] ] : [], 'failed' => $state === 'failed' ? [ [ 'name' => 'all', 'code' => $code ] ] : [] ];
			$published = $this->record_last_operation( $attachment_id, $last_status, $code, $message, $context, [], $sizes['failed'], $sizes['skipped'], $state, $operation );
			if ( $invocation !== null && $type !== 'regenerate' && ! $published ) {
				// The terminal remains evidence, but a missing R4 cannot settle this attempt.
				$result = $this->operation_result( 'interrupted', $token, 'journal_write_failed', $message, true );
				return $result;
			}
			$result = $this->operation_result( $state, $token, $code, $message, false, $sizes );
			return $result;
		} finally {
			if ( $operation !== null && $invocation === null ) {
				$this->release_operation_lock( $operation );
			}
			// Every caller gets the same notice guarantee, including callers that
			// intentionally ignore the result while continuing an automatic upload.
			if ( $context === 'auto-apply' && is_array( $result ) && ( in_array( $result['outcome'], [ 'failed', 'partial', 'interrupted' ], true ) || in_array( $result['code'], [ 'upload_type_changed_unsupported', 'upload_path_unresolved' ], true ) ) ) {
				$this->queue_auto_operation_notice( $attachment_id, $result );
			}
		}
	}

	/** @return array */
	private function early_operation_failure( $data, $attachment_id, $type, $context, $method, $code, $message, $last_status = 'error', $invocation = null ) {
		$result = $this->persist_terminal_outcome( $attachment_id, $type, $context, 'failed', $code, $message, $last_status, $invocation );
		// The persistence boundary already queued an automatic notice. A manual
		// caller must receive the actual persistence failure rather than the skip.
		if ( $result['code'] !== sanitize_key( $code ) ) {
			return $method === 'manual' ? [ 'error' => $result['message'], 'outcome' => $result ] : $data;
		}
		return $method === 'manual' ? [ 'error' => $message, 'outcome' => $result ] : $data;
	}

	/**
	 * Regenerate one attachment using the fenced, token-named generation path.
	 * Authorization belongs to the caller; the AJAX entry point checks the
	 * dedicated nonce, upload_files, and edit_post before calling this method.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $request Mode, exact size names, alias policy, and preview flag.
	 * @param string $context Manual or bounded list-table regeneration context.
	 * @return array Metadata and outcome, preview and outcome, or error and outcome.
	 */
	public function regenerate_attachment( $attachment_id, $request = [], $context = 'manual-regenerate' ) {
		$result = $this->execute_operation( 'regenerate', $attachment_id, $request, [], $context );
		return $result['legacy_result'];
	}

	/** @return array */
	private function perform_regenerate_attachment( $attachment_id, $request, $invocation = null, $context = 'manual-regenerate' ) {
		$attachment_id = (int) $attachment_id;
		$request = is_array( $request ) ? $request : [];
		$validated = $this->validate_regeneration_request( $request );
		if ( isset( $validated['error'], $validated['outcome'] ) ) {
			return $this->publish_regeneration_refusal( $attachment_id, $validated, $invocation );
		}
		$request = $validated;
		$options = $invocation !== null && is_array( $invocation->runner_options ) ? $invocation->runner_options : $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
		$input = $this->regeneration_input( $attachment_id, $request, $options );
		if ( isset( $input['error'] ) ) {
			return $this->publish_regeneration_refusal( $attachment_id, $input, $invocation );
		}
		// Vet a flagged attachment before the lease; a flag first observed under
		// the lease is vetted there before generation. Options are request-local.
		if ( $input['flagged'] && apply_filters( 'iw_watermark_display', $attachment_id ) === false ) {
			return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'display_veto', __( 'Watermarking skipped by filter.', 'image-watermark' ) ), $invocation );
		}
		$display_vetted = $input['flagged'];
		if ( $request['dry_run'] ) {
			return $this->preview_regeneration( $attachment_id, $input, $request, $options );
		}
		$token = $invocation !== null ? $invocation->operation_state['token'] : $this->operation_token();
		$lock = $invocation !== null ? $invocation->operation_state['lock'] : $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->regeneration_refusal( 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true );
		}
		$operation = $invocation !== null ? $this->borrow_contract_operation( $invocation ) : [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock, 'time_origin' => microtime( true ) ];
		$operation['regenerate_request'] = $request;
		try {
			// Repeat state-dependent checks under the lease; a preview is never
			// authority to write a later request's plan.
			$input = $this->regeneration_input( $attachment_id, $request, $options );
			if ( isset( $input['error'] ) ) {
				return $this->publish_regeneration_refusal( $attachment_id, $input, $invocation );
			}
			if ( $input['flagged'] && ! $display_vetted && apply_filters( 'iw_watermark_display', $attachment_id ) === false ) {
				return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'display_veto', __( 'Watermarking skipped by filter.', 'image-watermark' ) ), $invocation );
			}
			$display_vetted = $display_vetted || $input['flagged'];
			$early_plan = null;
			if ( ! $input['flagged'] ) {
				$early_plan = $this->unwatermarked_regeneration_preflight( $attachment_id, $input, $request, $options );
				if ( isset( $early_plan['error'] ) ) {
					return $this->publish_regeneration_refusal( $attachment_id, $early_plan, $invocation );
				}
			}
			// A pending handoff resolves the same rendered claim during reconciliation.
			// Ordinary calls preserve their manual decision before that settlement;
			// runners defer their choice until their own second check succeeds.
			$operation['name_stable_budget'] = true;
			$before_reconcile = $this->read_operation_journal( $attachment_id );
			if ( is_array( $before_reconcile ) && ( $this->is_pending_handoff( isset( $before_reconcile['current'] ) ? $before_reconcile['current'] : null ) || $this->is_pending_handoff( isset( $before_reconcile['recoverable_predecessor'] ) ? $before_reconcile['recoverable_predecessor'] : null ) ) ) {
				$operation['prepared_handoff'] = $this->prepare_pending_handoff( $operation, $before_reconcile );
				if ( $operation['prepared_handoff'] === false ) {
					$outcome = $this->begin_failure_result( $operation, $token );
					return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
				}
				$operation['journal'] = $before_reconcile;
				if ( ! $this->prepared_handoff_confirmed( $operation, $operation['prepared_handoff'] ) ) {
					$outcome = $this->handoff_pending_result( $token );
					return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
				}
				$row = $this->read_client_side_claim_row( $attachment_id );
				if ( ! $this->prepared_handoff_confirmed( $operation, $operation['prepared_handoff'] ) ) {
					$outcome = $this->handoff_pending_result( $token );
					return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
				}
				if ( $row === false || ( $invocation === null && is_array( $row ) && $row['claim']['state'] === 'rendered' && ! $this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'manual_regenerate' ] ) ) ) {
					return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'client_side_upload_pending', $this->client_side_unfinalized_message() ), $invocation );
				}
			}
			$recovery = $this->reconcile_predecessor( $operation, $input['metadata'], wp_upload_dir(), $context );
			if ( $recovery === 'handoff_pending' || $recovery === 'begin_refused' ) {
				$outcome = $recovery === 'begin_refused' ? $this->begin_failure_result( $operation, $token ) : $this->handoff_pending_result( $token );
				return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
			}
			if ( in_array( $recovery, [ 'uncertain', 'temporary-recovery', 'adoption_failed' ], true ) ) {
				return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'predecessor_uncertain', __( 'The interrupted image operation needs a verified clean source before it can be retried.', 'image-watermark' ) ), $invocation );
			}
			if ( $early_plan !== null ) {
				$journal_after = $this->read_operation_journal( $attachment_id );
				$inherited_after = $this->select_inheritable_pre_operation( $journal_after, $attachment_id );
				$original_after = $this->unwatermarked_original_source( $attachment_id, $early_plan['pre'] );
				if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1 || get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) !== $early_plan['stored_metadata'] || get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $early_plan['pre']['file'] || ( $journal_after === false ) !== $early_plan['journal_unreadable'] || $inherited_after !== $early_plan['inherited'] || $original_after !== $early_plan['original'] || ! is_file( $early_plan['input']['source'] ) || hash_file( 'sha256', $early_plan['input']['source'] ) !== $early_plan['source_hash'] ) {
					return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'predecessor_uncertain', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ), true ), $invocation );
				}
			}
			// Reconciliation can durably finish an older initial apply and set
			// its flag. Never treat that newly watermarked main as a clean source.
			$input = $this->regeneration_input( $attachment_id, $request, $options );
			if ( isset( $input['error'] ) ) {
				return $this->publish_regeneration_refusal( $attachment_id, $input, $invocation );
			}
			if ( $early_plan !== null ) {
				if ( $input !== $early_plan['input'] ) {
					return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'predecessor_uncertain', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ), true ), $invocation );
				}
				$operation['early_regeneration_plan'] = $early_plan;
			}
			if ( ! $display_vetted && $input['flagged'] && apply_filters( 'iw_watermark_display', $attachment_id ) === false ) {
				return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'display_veto', __( 'Watermarking skipped by filter.', 'image-watermark' ) ), $invocation );
			}
			$image_metadata = [];
			if ( $input['flagged'] ) {
				$image_info = [];
				if ( ! @getimagesize( $input['source'], $image_info ) ) {
					return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'metadata_read_failed', __( 'Image metadata could not be read. No image was changed.', 'image-watermark' ) ), $invocation );
				}
				$image_metadata = $this->get_image_metadata( $image_info, $input['source'] );
				if ( ! empty( $image_metadata['error'] ) ) {
					return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'metadata_read_failed', $image_metadata['error'] ), $invocation );
				}
			}
			$operation['intended_flag'] = $input['flagged'] ? 1 : 0;
			if ( is_array( $input['main_recovery'] ) ) {
				$seed = $input['main_recovery'];
				$seed['path'] = $this->operation_recovery_relative_path( $seed['target'], $token );
				$seed['state'] = 'allocated';
				$operation['main_recovery_seed'] = $seed;
				$operation['main_recovery_predecessor'] = $input['predecessor'];
				if ( is_array( $input['predecessor'] ) ) { $operation['selected_recovery_predecessor'] = $input['predecessor']; }
			}
			// Bind the real clean source and predecessor above before costing begin.
			// A rendered claim must not be settled for an unaffordable successor.
			$operation['name_stable_budget'] = true;
			$journal = $this->read_operation_journal( $attachment_id );
			if ( $journal === false ) {
				$outcome = $this->begin_failure_result( $operation, $token );
				return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
			}
			$operation['prepared_begin'] = $this->prepare_begin_operation( $operation, $journal, 'regenerate', $context, [] );
			if ( $operation['prepared_begin'] === false ) {
				$outcome = $this->begin_failure_result( $operation, $token );
				return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
			}
			$operation['journal'] = $journal;
			if ( ! $this->begin_prefix_confirmed( $operation ) ) {
				$outcome = $this->begin_failure_result( $operation, $token );
				return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
			}
			$row = $this->read_client_side_claim_row( $attachment_id );
			if ( $row === false || ( $invocation === null && is_array( $row ) && $row['claim']['state'] === 'rendered' && ! $this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'manual_regenerate' ] ) ) ) {
				return $this->publish_regeneration_refusal( $attachment_id, $this->regeneration_refusal( 'client_side_upload_pending', $this->client_side_unfinalized_message() ), $invocation );
			}
			if ( ! $this->begin_operation( $operation, 'regenerate', $context, [] ) ) {
				$outcome = $this->begin_failure_result( $operation, $token );
				return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
			}
			$begun = true;
			if ( $invocation !== null ) {
				if ( ! $this->begin_prefix_confirmed( $operation ) ) {
					$begun = false;
					$outcome = $this->operation_result( 'interrupted', $token, 'journal_write_failed', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true );
					return [ 'error' => $outcome['message'], 'outcome' => $outcome ];
				}
				$row = $this->read_client_side_claim_row( $attachment_id );
				if ( $row === false || ( is_array( $row ) && $row['claim']['state'] === 'rendered' && ! $this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'manual_regenerate' ] ) ) ) {
					return $this->operation_terminal_failure( $operation, $input['metadata'], 'manual', $context, 'failed', 'client_side_upload_pending', $this->client_side_unfinalized_message(), true );
				}
			}
			if ( isset( $operation['main_recovery_seed'] ) && $this->should_inject_operation_fault( 'after_main_recovery_allocated', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
				return $this->operation_terminal_failure( $operation, $input['metadata'], 'manual', $context, 'interrupted', 'recovery_interrupted', __( 'The interrupted image operation can be retried from its clean recovery source.', 'image-watermark' ), true );
			}
			$operation['regeneration_reconciled'] = $input['reconciled'];
			$result = $this->execute_staged_regeneration( $operation, $input['metadata'], $input['flagged'] ? 'reapply' : 'regenerate', $options, wp_upload_dir(), $input['source'], 'manual', $context, $image_metadata );
			$outcome = isset( $result['error'] ) ? $result['outcome'] : $this->get_last_operation_outcome();
			if ( $request['cleanup_stale'] ) {
				$recovery = isset( $operation['journal']['current']['derivative_recovery'] ) ? $operation['journal']['current']['derivative_recovery'] : [];
				$retired = isset( $recovery['retired'] ) && is_array( $recovery['retired'] ) ? $recovery['retired'] : [];
				$names = [];
				foreach ( isset( $recovery['previous'] ) && is_array( $recovery['previous'] ) ? $recovery['previous'] : [] as $name => $path ) {
					if ( in_array( $path, $retired, true ) ) { $names[] = $name; }
				}
				$outcome['retired'] = array_values( array_unique( $names ) );
			}
			if ( isset( $result['error'] ) ) {
				$result['outcome'] = $outcome;
				return $result;
			}
			return [ 'metadata' => $result, 'outcome' => $outcome ];
		} finally {
			if ( ! empty( $begun ) ) {
				$this->cleanup_owned_operation_stages( $operation, false );
			}
			if ( $invocation === null ) { $this->release_operation_lock( $operation ); }
		}
	}

	/** Validate the shared regeneration request without executing it. */
	private function validate_regeneration_request( $request, $read_only = false ) {
		$request = array_merge( [ 'mode' => 'all', 'sizes' => [], 'strict_aliases' => false, 'dry_run' => false, 'cleanup_stale' => false ], $request );
		if ( ! in_array( $request['mode'], [ 'all', 'missing', 'subset' ], true ) || ! is_array( $request['sizes'] ) || count( $request['sizes'] ) > 200 ) {
			return $this->regeneration_refusal( 'invalid_sizes', __( 'Choose a valid regeneration mode and image sizes.', 'image-watermark' ), false, $read_only );
		}
		foreach ( [ 'strict_aliases', 'dry_run', 'cleanup_stale' ] as $key ) {
			if ( ! in_array( $request[ $key ], [ true, false, 0, 1, '0', '1' ], true ) ) {
				return $this->regeneration_refusal( 'invalid_request', __( 'Invalid regeneration request.', 'image-watermark' ), false, $read_only );
			}
			$request[ $key ] = (bool) $request[ $key ];
		}
		foreach ( $request['sizes'] as $name ) {
			if ( ! is_string( $name ) || $name === '' ) {
				return $this->regeneration_refusal( 'invalid_sizes', __( 'Choose valid image size names.', 'image-watermark' ), false, $read_only );
			}
		}
		return $request;
	}

	/** Project the existing regeneration inspection without granting write authority. */
	private function preview_common_regeneration( $attachment_id, $request, $preview ) {
		$request = $this->validate_regeneration_request( $request, true );
		if ( ! isset( $request['error'], $request['outcome'] ) ) {
			$options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
			$input = $this->regeneration_input( $attachment_id, $request, $options, true );
			if ( isset( $input['error'] ) ) {
				$result = $input;
			} elseif ( $input['flagged'] && apply_filters( 'iw_watermark_display', $attachment_id ) === false ) {
				$result = $this->regeneration_refusal( 'display_veto', __( 'Watermarking skipped by filter.', 'image-watermark' ), false, true );
			} else {
				$result = $this->preview_regeneration( $attachment_id, $input, $request, $options, true );
			}
		} else {
			$result = $request;
		}
		$preview['code'] = $result['outcome']['code'];
		$preview['message'] = $result['outcome']['message'];
		if ( isset( $result['preview'] ) ) {
			$preview['eligible'] = true;
			$preview['sizes'] = $result['preview']['write'];
			$preview['details']['plan'] = $result['preview'];
		}
		return $preview;
	}

	/** Publish only classified pre-begin runner refusals through a fresh borrowed base. */
	private function publish_regeneration_refusal( $attachment_id, $refusal, $invocation ) {
		if ( $invocation === null || ! isset( $refusal['outcome']['outcome'], $refusal['outcome']['code'] ) || in_array( $refusal['outcome']['code'], [ 'handoff_pending', 'journal_invalid', 'journal_write_failed', 'journal_budget_exceeded' ], true ) || $this->classify_operation_retryability( $refusal['outcome']['outcome'], $refusal['outcome']['code'] ) === null ) {
			return $refusal;
		}
		$outcome = $refusal['outcome'];
		$result = $this->persist_terminal_outcome( $attachment_id, 'regenerate', 'manual-regenerate', $outcome['outcome'], $outcome['code'], $outcome['message'], 'error', $invocation );
		return [ 'error' => $result['message'], 'outcome' => $result ];
	}

	/** @return array A structured refusal without changing attachment state. */
	private function regeneration_refusal( $code, $message, $retryable = false, $read_only = false ) {
		return [ 'error' => $message, 'outcome' => $this->operation_result( 'failed', '', $code, $message, $retryable, [], $read_only ) ];
	}

	/**
	 * Freeze the unflagged regeneration plan before predecessor reconciliation.
	 * A selected changed old size that cannot be recreated must not cause even
	 * private stage cleanup, so this path reads files but never writes them.
	 *
	 * @return array Frozen plan, or a structured refusal.
	 */
	private function unwatermarked_regeneration_preflight( $attachment_id, $input, $request, $options, $read_only = false ) {
		$uploads = $this->operation_upload_dir( $read_only );
		$stored_metadata = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
		// The input may already have reconciled its main file. Old size paths
		// remain relative to the directory the stored metadata actually named.
		$snapshot = $this->snapshot_pre_operation( $attachment_id, $stored_metadata, get_attached_file( $attachment_id ), $uploads );
		if ( $snapshot === false ) {
			return $this->regeneration_refusal( 'invalid_metadata', __( 'Invalid attachment metadata.', 'image-watermark' ), false, $read_only );
		}
		$journal = $this->read_operation_journal( $attachment_id );
		$inherited = $this->select_inheritable_pre_operation( $journal, $attachment_id, $read_only );
		$pre = is_array( $inherited['pre'] ) ? $inherited['pre'] : $snapshot;
		$frozen = $this->freeze_effective_sizes( $attachment_id, $input['metadata'], $pre );
		if ( count( $frozen ) > 200 ) {
			return $this->regeneration_refusal( 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ), false, $read_only );
		}
		if ( $request['mode'] === 'subset' && ( empty( $request['sizes'] ) || array_diff( $request['sizes'], array_map( 'strval', array_keys( $frozen ) ) ) ) ) {
			return $this->regeneration_refusal( 'invalid_sizes', __( 'Choose registered image sizes for regeneration.', 'image-watermark' ), false, $read_only );
		}
		$preserved = $this->regeneration_preserved_sizes( $input['metadata'], $frozen, $request, $read_only );
		$registered = array_fill_keys( array_map( 'strval', array_keys( wp_get_registered_image_subsizes() ) ), true );
		$original = $this->unwatermarked_original_source( $attachment_id, $pre, $read_only );
		$main_dimensions = @getimagesize( $input['source'] );
		$source = $original === null ? ( $main_dimensions ? [ (int) $main_dimensions[0], (int) $main_dimensions[1] ] : null ) : $this->oriented_source_dimensions( $pre, $uploads );
		$affected = [];
		foreach ( $pre['sizes'] as $name => $old ) {
			if ( empty( $options['watermark_on'][ $name ] ) || (int) $options['watermark_on'][ $name ] !== 1 || ! isset( $frozen[ $name ] ) || isset( $preserved[ $name ] ) || ( $request['mode'] === 'subset' && ! in_array( (string) $name, $request['sizes'], true ) ) || $old['width'] < 1 || $old['height'] < 1 ) {
				continue;
			}
			if ( ! is_array( $source ) ) {
				return $this->regeneration_refusal( 'size_generation_failed', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ), true, $read_only );
			}
			$definition = $frozen[ $name ];
			$geometry = ( $definition['width'] !== null || $definition['height'] !== null ) ? image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ) : false;
			if ( $this->name_stable_definition_unchanged( $definition, $old, $geometry ? (int) $geometry[4] : 0, $geometry ? (int) $geometry[5] : 0, $source ) ) {
				continue;
			}
			$old_geometry = image_resize_dimensions( $source[0], $source[1], $old['width'], $old['height'], true );
			if ( ( $source[0] === $old['width'] && $source[1] === $old['height'] ) || ( $old_geometry && (int) $old_geometry[4] === $old['width'] && (int) $old_geometry[5] === $old['height'] ) ) {
				continue;
			}
			$affected[] = $name . ' (' . $old['width'] . ' x ' . $old['height'] . ')';
		}
		if ( $affected ) {
			$predecessor = $journal === false ? 'predecessor_uncertain' : $this->unwatermarked_predecessor_flag_risk( $journal, $read_only );
			if ( $predecessor !== '' ) {
				$message = __( 'The interrupted watermark operation cannot prove that every live target is clean. No image was overwritten.', 'image-watermark' ) . ' ' . __( 'Regenerate thumbnails or select different image sizes in Watermark settings.', 'image-watermark' );
				return $this->regeneration_refusal( $predecessor, $message, true, $read_only );
			}
			$message = __( 'Image sizes could not be regenerated from the clean source. No image was changed.', 'image-watermark' ) . ' ' . __( 'Original size', 'image-watermark' ) . ': ' . implode( ', ', $affected );
			return $this->regeneration_refusal( 'size_not_producible', $message, false, $read_only );
		}
		return [ 'input' => $input, 'snapshot' => $snapshot, 'pre' => $pre, 'inherited' => $inherited, 'frozen' => $frozen, 'registered' => $registered, 'preserved' => $preserved, 'original' => $original, 'source_dimensions' => $source, 'stored_metadata' => $stored_metadata, 'journal_unreadable' => $journal === false, 'source_hash' => hash_file( 'sha256', $input['source'] ) ];
	}

	/** @return string Pending handoff, uncertain predecessor, or no flag risk. */
	private function unwatermarked_predecessor_flag_risk( $journal, $read_only = false ) {
		return $this->unwatermarked_predecessor_flag_risk_from_values( $journal, false, $read_only );
	}

	/** Value-only scanning treats any file-dependent decision as unresolved. */
	private function unwatermarked_predecessor_flag_risk_from_values( $journal, $values_only, $read_only = false ) {
		$current = isset( $journal['current'] ) ? $journal['current'] : null;
		$retained = isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
		foreach ( [ $current, $retained ] as $record ) {
			if ( $this->is_pending_handoff( $record ) ) {
				return 'handoff_pending';
			}
		}
		if ( is_array( $current ) && isset( $current['state'] ) && $current['state'] === 'complete' && ! empty( $current['metadata_committed'] ) ) {
			return '';
		}
		foreach ( [ $current, $retained ] as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}
			if ( ! isset( $record['type'] ) || $record['type'] !== 'apply' ) {
				continue;
			}
			$stageable = false;
			foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
				if ( ! is_array( $target ) || ( isset( $target['intent'] ) && $target['intent'] !== 'watermark-output' ) ) {
					continue;
				}
				$stageable = $stageable || ! empty( $target['stage'] ) || ( isset( $target['state'] ) && $target['state'] !== 'pending' );
				if ( empty( $target['target'] ) || empty( $target['size'] ) || empty( $target['hash'] ) ) {
					continue;
				}
				if ( $values_only ) {
					return 'predecessor_uncertain';
				}
				$failure = null;
				$file = $this->resolve_upload_artifact_path( $target['target'], false, '', 0, $failure, $read_only );
				if ( $file !== false && is_file( $file['path'] ) && is_readable( $file['path'] ) && (string) filesize( $file['path'] ) === (string) $target['size'] && hash_file( 'sha256', $file['path'] ) === $target['hash'] ) {
					return 'predecessor_uncertain';
				}
			}
			if ( $stageable && isset( $record['state'] ) && in_array( $record['state'], [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging', 'rendering', 'backup-done', 'preparing' ], true ) ) {
				if ( $values_only ) {
					$no_promotion = isset( $record['generation_stage'], $record['targets'] ) && is_array( $record['targets'] );
					foreach ( $no_promotion ? $record['targets'] : [] as $target ) {
						$state = isset( $target['state'] ) ? $target['state'] : 'pending';
						if ( ! in_array( $state, [ 'pending', 'allocated' ], true ) ) { $no_promotion = false; break; }
					}
				} else {
					$no_promotion = $this->generation_record_promoted_nothing( $record, $read_only );
				}
				if ( ! $no_promotion ) { return 'predecessor_uncertain'; }
			}
		}
		return '';
	}

	/** @return array Request-local, revalidated source and attachment state. */
	private function regeneration_input( $attachment_id, $request, $options, $read_only = false ) {
		$data = wp_get_attachment_metadata( $attachment_id, true );
		if ( get_post_type( $attachment_id ) !== 'attachment' || ! is_array( $data ) ) {
			return $this->regeneration_refusal( 'invalid_metadata', __( 'Invalid attachment metadata.', 'image-watermark' ), false, $read_only );
		}
		if ( $this->is_unfinalized_client_side_replacement( $attachment_id, $data ) ) {
			return $this->regeneration_refusal( 'client_side_upload_unfinalized', $this->client_side_unfinalized_message(), false, $read_only );
		}
		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( $row === false || ( is_array( $row ) && $row['claim']['state'] === 'pending' ) ) {
			return $this->regeneration_refusal( 'client_side_upload_pending', $this->client_side_unfinalized_message(), false, $read_only );
		}
		if ( $this->client_side_payload_row_exists( $attachment_id ) ) {
			return $this->regeneration_refusal( 'client_side_finalize_deferred', $this->client_side_unfinalized_message(), false, $read_only );
		}
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$reconciled = ! isset( $data['file'] ) || ! is_string( $data['file'] ) || ! is_string( $attached ) || wp_normalize_path( $data['file'] ) !== wp_normalize_path( $attached );
		$failure = null;
		$main = is_string( $attached ) ? $this->resolve_upload_artifact_path( $attached, false, '', 0, $failure, $read_only ) : false;
		if ( $main === false || ! is_file( $main['path'] ) || ! is_readable( $main['path'] ) || is_link( $main['path'] ) ) {
			return $this->regeneration_refusal( $reconciled ? 'attachment_file_diverged' : 'file_missing', __( 'The original image file could not be found.', 'image-watermark' ), false, $read_only );
		}
		$dimensions = @getimagesize( $main['path'] );
		$mime = $dimensions ? $dimensions['mime'] : get_post_mime_type( $attachment_id );
		if ( ! $dimensions || ! wp_image_editor_supports( [ 'mime_type' => $mime ] ) || strpos( (string) $mime, 'image/' ) !== 0 || $mime === 'image/svg+xml' ) {
			return $this->regeneration_refusal( $reconciled ? 'attachment_file_diverged' : 'unsupported_type', __( 'This image type cannot be regenerated by the active image editor.', 'image-watermark' ), false, $read_only );
		}
		if ( ! function_exists( '_wp_make_subsizes' ) ) {
			return $this->regeneration_refusal( 'regenerate_unsupported', __( 'This WordPress version cannot regenerate image sizes safely. No image was changed.', 'image-watermark' ), false, $read_only );
		}
		$flagged = (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
		$source = $main['path'];
		if ( $flagged ) {
			if ( ! in_array( $mime, $this->plugin->get_allowed_mime_types(), true ) ) {
				return $this->regeneration_refusal( 'watermark_type_unsupported', __( 'This image type cannot be watermarked.', 'image-watermark' ), false, $read_only );
			}
			if ( $request['mode'] !== 'all' ) {
				return $this->regeneration_refusal( 'regenerate_mode_unsupported', __( 'Watermarked images must regenerate all image sizes.', 'image-watermark' ), false, $read_only );
			}
			$validation = $this->validate_watermark_eligibility( $options, $attachment_id, 'manual-apply' );
			if ( ! $validation['valid'] ) {
				return $this->regeneration_refusal( $validation['code'], $validation['error'], false, $read_only );
			}
			if ( $this->should_skip_small_image( $options, $dimensions[0], $dimensions[1] ) ) {
				return $this->regeneration_refusal( 'small_image', __( 'Image is smaller than the minimum dimensions required for watermarking.', 'image-watermark' ), false, $read_only );
			}
			$selection = isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? array_filter( $options['watermark_on'], static function( $active ) { return (int) $active === 1; } ) : [];
			if ( empty( $selection ) ) {
				return $this->regeneration_refusal( 'no_target_sizes', __( 'No selected image sizes are available for this attachment.', 'image-watermark' ), false, $read_only );
			}
			$backup = $this->describe_backup_path( $attached );
			$binding = false;
			if ( $backup['path'] === false || ! $this->is_valid_backup_file( $backup['path'] ) ) {
				$binding = ! $reconciled ? $this->bind_manifest_main_source( $attachment_id, $main, $read_only ) : false;
				if ( $binding === false ) {
					return $this->regeneration_refusal( $reconciled ? 'attachment_file_diverged' : 'no_clean_source', __( 'Regeneration needs a verified clean image source. Restore the image with the tool that changed it, or re-upload it.', 'image-watermark' ), false, $read_only );
				}
			}
			$source = $binding === false ? $backup['path'] : $binding['source'];
		}
		$data['file'] = $attached;
		$data['width'] = (int) $dimensions[0];
		$data['height'] = (int) $dimensions[1];
		return [ 'metadata' => $data, 'flagged' => $flagged, 'source' => $source, 'reconciled' => $reconciled, 'main_recovery' => isset( $binding ) && is_array( $binding ) ? $binding['recovery'] : null, 'predecessor' => isset( $binding ) && is_array( $binding ) ? $binding['predecessor'] : null ];
	}

	/**
	 * Read-only facts for attachment diagnostics. Only bounded stage basenames
	 * leave this method; unknown geometry is not classified as unproducible.
	 *
	 * @param int   $attachment_id Attachment on the current site.
	 * @param array $metadata Stored attachment metadata.
	 * @return array
	 */
	public function describe_derivative_diagnostics( $attachment_id, $metadata, $read_only = false ) {
		// The legacy diagnostic checks fully decode backups. A header-only scan
		// must not call this helper, even with the non-creating lookup policy.
		$upload_dir = $this->operation_upload_dir( $read_only );
		$result = [ 'geometry' => null, 'provenance' => 'absent', 'main_usable' => false, 'backup_usable' => false, 'interrupted' => false, 'operation_incomplete' => false, 'journal_unreadable' => false, 'main_recovery' => false, 'legacy_limit' => [], 'stages' => [], 'scan_incomplete' => false ];
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$attached = is_string( $attached ) ? $attached : '';
		$failure = null;
		$main = $this->resolve_upload_artifact_path( $attached, false, '', 0, $failure, $read_only );
		if ( $main === false ) {
			$result['scan_incomplete'] = true;
			$main = [ 'path' => '', 'parent' => '' ];
		}
		$main_dimensions = is_file( $main['path'] ) && is_readable( $main['path'] ) && ! is_link( $main['path'] ) ? @getimagesize( $main['path'] ) : false;
		$result['main_usable'] = (bool) ( $main_dimensions && wp_image_editor_supports( [ 'mime_type' => $main_dimensions['mime'] ] ) );
		$backup = $this->describe_backup_path( $attached );
		$backup_dimensions = $backup['path'] && $this->is_valid_backup_file( $backup['path'] ) ? @getimagesize( $backup['path'] ) : false;
		$result['backup_usable'] = $main_dimensions && $backup_dimensions && $main_dimensions[0] === $backup_dimensions[0] && $main_dimensions[1] === $backup_dimensions[1] && $main_dimensions['mime'] === $backup_dimensions['mime'];
		$pre = $this->snapshot_pre_operation( $attachment_id, $metadata, $main['path'], $upload_dir );
		if ( $pre !== false ) {
			$flagged = (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
			$original = $flagged ? $this->proven_original_source( $attachment_id, $pre, $read_only ) : $this->unwatermarked_original_source( $attachment_id, $pre, $read_only );
			if ( $original !== null ) {
				$result['geometry'] = $this->oriented_source_dimensions( $pre, $upload_dir );
			} else {
				$source = $main['path'];
				if ( $flagged ) {
					$source = $result['backup_usable'] ? $backup['path'] : false;
					if ( $source === false && is_file( $main['path'] ) ) {
						$binding = $this->bind_manifest_main_source( $attachment_id, $main, $read_only );
						$source = is_array( $binding ) ? $binding['source'] : false;
					}
				}
				$dimensions = $source && is_file( $source ) ? @getimagesize( $source ) : false;
				$result['geometry'] = $dimensions ? [ (int) $dimensions[0], (int) $dimensions[1] ] : null;
			}
		}
		$provenance = get_post_meta( $attachment_id, self::PROVENANCE_META_KEY, true );
		if ( $provenance !== '' && $provenance !== null && $provenance !== false ) {
			$result['provenance'] = 'mismatched';
			$expected_file = ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ? $metadata['original_image'] : wp_basename( $attached );
			if ( is_array( $provenance ) && isset( $provenance['version'], $provenance['file'], $provenance['size'], $provenance['sha256'], $provenance['method'], $provenance['time'] ) && $provenance['version'] === self::PROVENANCE_VERSION && in_array( $provenance['method'], [ 'server-upload', 'client-upload' ], true ) && is_int( $provenance['time'] ) && strlen( serialize( $provenance ) ) <= self::PROVENANCE_MAX_BYTES && is_string( $provenance['file'] ) && $provenance['file'] === $expected_file && $provenance['file'] === wp_basename( $provenance['file'] ) && is_int( $provenance['size'] ) && $provenance['size'] > 0 && is_string( $provenance['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', $provenance['sha256'] ) ) {
				$directory = dirname( $attached );
				$failure = null;
				$file = $this->resolve_upload_artifact_path( ( $directory === '.' ? '' : $directory . '/' ) . $provenance['file'], false, '', 0, $failure, $read_only );
				if ( $file !== false && is_file( $file['path'] ) && is_readable( $file['path'] ) && ! is_link( $file['path'] ) && filesize( $file['path'] ) === $provenance['size'] && hash_file( 'sha256', $file['path'] ) === $provenance['sha256'] ) {
					$result['provenance'] = 'matching';
				}
			}
		}
		$journal = $this->read_operation_journal( $attachment_id );
		$result['journal_unreadable'] = $journal === false;
		if ( is_array( $journal ) ) {
			$current_complete = ! empty( $journal['current']['state'] ) && $journal['current']['state'] === 'complete' && ! empty( $journal['current']['metadata_committed'] );
			foreach ( [ 'current', 'recoverable_predecessor' ] as $key ) {
				$record = isset( $journal[ $key ] ) && is_array( $journal[ $key ] ) ? $journal[ $key ] : null;
				if ( ! $record || ( isset( $record['site_id'] ) && (int) $record['site_id'] !== get_current_blog_id() ) ) { continue; }
				if ( ! $current_complete && isset( $record['type'], $record['state'] ) && ! in_array( $record['state'], [ 'complete', 'failed', 'skipped' ], true ) ) {
					if ( $record['type'] === 'regenerate' ) { $result['interrupted'] = true; } else { $result['operation_incomplete'] = true; }
				}
				if ( isset( $record['site_id'], $record['token'], $record['main_recovery'] ) && (int) $record['site_id'] === get_current_blog_id() && $this->is_valid_main_recovery( $record['main_recovery'], $record['token'] ) ) {
					$failure = null;
					$recovery = $this->resolve_upload_artifact_path( $record['main_recovery']['path'], false, '', 0, $failure, $read_only );
					if ( $recovery !== false && is_file( $recovery['path'] ) && ! is_link( $recovery['path'] ) ) { $result['main_recovery'] = true; }
				}
				$outcomes = ! $current_complete && isset( $record['derivative_recovery']['outcomes'] ) && is_array( $record['derivative_recovery']['outcomes'] ) ? $record['derivative_recovery']['outcomes'] : [];
				foreach ( $outcomes as $name => $outcome ) {
					if ( $outcome === 'legacy_limit_reached' && isset( $record['pre_operation']['sizes'][ $name ] ) ) { $result['legacy_limit'][] = (string) $name; }
				}
			}
		}
		// A bounded scan reports all leftovers, even those with cleanup authority.
		$directory = $main['parent'];
		$handle = $directory !== '' ? @opendir( $directory ) : false;
		if ( $handle === false ) {
			$result['scan_incomplete'] = true;
		} else {
			$count = 0;
			while ( ( $name = readdir( $handle ) ) !== false ) {
				if ( $name === '.' || $name === '..' ) { continue; }
				++$count;
				if ( preg_match( '/^\.iw-stage-[A-Za-z0-9]{64}-/', $name ) && is_file( $directory . DIRECTORY_SEPARATOR . $name ) && ! is_link( $directory . DIRECTORY_SEPARATOR . $name ) ) {
					if ( count( $result['stages'] ) < 20 ) { $result['stages'][] = $name; } else { $result['scan_incomplete'] = true; }
				}
				// Do not read an extra entry to distinguish exactly 10,000 from more.
				if ( $count === 10000 ) { $result['scan_incomplete'] = true; break; }
			}
			closedir( $handle );
		}
		$result['legacy_limit'] = array_values( array_unique( $result['legacy_limit'] ) );
		return $result;
	}

	/** @return bool Whether the record carries registered main-file recovery authority. */
	private function record_has_main_recovery( $record, $verified = false ) {
		return is_array( $record ) && isset( $record['token'], $record['main_recovery'], $record['site_id'] )
			&& is_int( $record['site_id'] ) && $record['site_id'] === get_current_blog_id()
			&& ! ( isset( $record['state'] ) && $record['state'] === 'complete' && ! empty( $record['metadata_committed'] ) )
			&& $this->is_valid_main_recovery( $record['main_recovery'], $record['token'] )
			&& ( ! $verified || $record['main_recovery']['state'] === 'verified' );
	}

	/** @return bool Structural validation; contents are checked when authority is used. */
	private function is_valid_main_recovery( $recovery, $token ) {
		if ( ! is_array( $recovery ) || ! isset( $recovery['target'], $recovery['root'], $recovery['path'], $recovery['size'], $recovery['sha256'], $recovery['state'], $recovery['authority'], $recovery['manifest_token'] ) ) {
			return false;
		}
		return is_string( $token ) && preg_match( '/^[A-Za-z0-9]{64}$/', $token )
			&& is_string( $recovery['target'] ) && $this->normalize_operation_relative_path( $recovery['target'] ) === $recovery['target']
			&& $recovery['root'] === 'uploads' && is_string( $recovery['path'] ) && $recovery['path'] === $this->operation_recovery_relative_path( $recovery['target'], $token )
			&& is_int( $recovery['size'] ) && $recovery['size'] > 0 && is_string( $recovery['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', $recovery['sha256'] )
			&& in_array( $recovery['state'], [ 'allocated', 'verified' ], true ) && $recovery['authority'] === 'manifest'
			&& is_string( $recovery['manifest_token'] ) && preg_match( '/^[A-Za-z0-9]{64}$/', $recovery['manifest_token'] );
	}

	/** @return array|null Newest complete proof, then retained proof, then allocated evidence. */
	private function select_operation_recovery_predecessor( $journal ) {
		$current = ! empty( $journal['current'] ) ? $journal['current'] : null;
		if ( is_array( $current ) && isset( $current['state'] ) && $current['state'] === 'complete' && ! empty( $current['metadata_committed'] ) ) {
			return null;
		}
		$retained = ! empty( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
		$current_state = is_array( $current ) && isset( $current['state'] ) && ( in_array( $current['state'], [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging', 'rendering', 'backup-done', 'preparing' ], true ) || $this->record_has_main_recovery( $current ) );
		if ( $current_state && ( $this->operation_record_has_complete_temporary_recovery( $current ) || $this->record_has_main_recovery( $current, true ) ) ) {
			return $current;
		}
		if ( $this->operation_record_has_complete_temporary_recovery( $retained ) || $this->record_has_main_recovery( $retained, true ) ) {
			return $retained;
		}
		if ( $current_state && ( $this->operation_record_has_temporary_recovery_evidence( $current ) || $this->record_has_main_recovery( $current ) ) ) {
			return $current;
		}
		return $this->operation_record_has_temporary_recovery_evidence( $retained ) || $this->record_has_main_recovery( $retained ) ? $retained : null;
	}

	/** @return bool Whether a main target may already have been promoted. */
	private function main_recovery_has_target( $record ) {
		foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
			if ( isset( $target['target'], $target['state'] ) && $target['target'] === $record['main_recovery']['target'] && in_array( $target['state'], [ 'staged', 'promoted' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return bool Clean bytes or a proven promotion of this record, never an arbitrary live file. */
	private function main_recovery_live_matches( $record, $path, $read_only = false ) {
		$recovery = $record['main_recovery'];
		$size = is_file( $path ) && is_readable( $path ) ? filesize( $path ) : false;
		$hash = $size ? hash_file( 'sha256', $path ) : false;
		if ( $size === $recovery['size'] && $hash === $recovery['sha256'] ) {
			return $recovery['state'] === 'verified' || ! $this->main_recovery_has_target( $record );
		}
		if ( $recovery['state'] !== 'verified' ) {
			return false;
		}
		foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
			if ( ! isset( $target['target'], $target['state'], $target['size'], $target['hash'] ) || $target['target'] !== $recovery['target'] || $target['size'] !== $size || $target['hash'] !== $hash ) {
				continue;
			}
			if ( $target['state'] === 'promoted' ) {
				return true;
			}
			if ( $target['state'] === 'staged' && isset( $target['stage'] ) ) {
				$failure = null;
				$stage = $this->resolve_upload_artifact_path( $target['stage'], false, '', 0, $failure, $read_only );
				if ( $stage !== false && ! file_exists( $stage['path'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** @return array|false No-backup authority, never inferred from decoding alone. */
	private function bind_manifest_main_source( $attachment_id, $main, $read_only = false ) {
		$uploads = $this->operation_upload_dir( $read_only );
		$target = $this->operation_destination_relative_path( $main['path'], $uploads );
		$journal = $this->read_operation_journal( $attachment_id );
		if ( $target === false || $journal === false ) {
			return false;
		}
		$prior = $this->select_operation_recovery_predecessor( is_array( $journal ) ? $journal : [] );
		if ( $this->record_has_main_recovery( $prior ) ) {
			// Before restoring the main after a verified transfer, the older
			// record still owns its live layout. Its copy remains until restore.
			if ( ! $this->main_recovery_live_matches( $prior, $main['path'], $read_only ) && ! $this->main_recovery_has_target( $prior ) ) {
				$parent = isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
				if ( $this->record_has_main_recovery( $parent, true ) && $this->same_main_recovery_authority( $parent['main_recovery'], $prior['main_recovery'] ) ) {
					$prior = $parent;
				}
			}
			$recovery = $prior['main_recovery'];
			if ( $recovery['target'] !== $target || ! $this->main_recovery_live_matches( $prior, $main['path'], $read_only ) ) {
				return false;
			}
			$failure = null;
			$file = $recovery['state'] === 'allocated' ? $main : $this->resolve_upload_artifact_path( $recovery['path'], false, '', 0, $failure, $read_only );
			if ( $file === false || ! is_file( $file['path'] ) || ! is_readable( $file['path'] ) || is_link( $file['path'] ) || filesize( $file['path'] ) !== $recovery['size'] || hash_file( 'sha256', $file['path'] ) !== $recovery['sha256'] || ! $this->verify_operation_file( $file['path'] ) ) {
				return false;
			}
			return [ 'source' => $file['path'], 'recovery' => $recovery, 'predecessor' => $prior ];
		}
		$current = isset( $journal['current'] ) ? $journal['current'] : null;
		if ( ! is_array( $current ) || ! isset( $current['site_id'] ) || ! is_int( $current['site_id'] ) || $current['site_id'] !== get_current_blog_id() ) {
			return false;
		}
		$status = $this->describe_manifest_status( $attachment_id );
		if ( ! in_array( $status['status'], [ 'trusted', 'settings_changed' ], true ) || ! is_array( $status['manifest'] ) || $status['manifest']['main']['watermarked'] ) {
			return false;
		}
		$manifest = $status['manifest'];
		$entry = $manifest['main'];
		if ( $entry['path'] !== get_post_meta( $attachment_id, '_wp_attached_file', true ) || filesize( $main['path'] ) !== $entry['size'] || hash_file( 'sha256', $main['path'] ) !== $entry['sha256'] ) {
			return false;
		}
		return [ 'source' => $main['path'], 'recovery' => [ 'target' => $target, 'root' => 'uploads', 'size' => $entry['size'], 'sha256' => $entry['sha256'], 'authority' => 'manifest', 'manifest_token' => $manifest['token'] ], 'predecessor' => null ];
	}

	/** @return bool Durably verify this attempt's copy before any live write. */
	private function prepare_main_recovery_copy( &$operation, $source, $options ) {
		$recovery = $operation['journal']['current']['main_recovery'];
		$file = $this->resolve_upload_artifact_path( $recovery['path'] );
		if ( $file === false || ! is_file( $source ) || ! is_readable( $source ) || filesize( $source ) !== $recovery['size'] || hash_file( 'sha256', $source ) !== $recovery['sha256'] || ! $this->renew_operation_lock( $operation ) || ! $this->copy_file( $source, $file['path'], 'stage', $operation['attachment_id'], $options ) ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'after_main_recovery_copy', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			$operation['recovery_error_code'] = 'recovery_interrupted';
			return false;
		}
		clearstatcache( true, $file['path'] );
		if ( ! $this->verify_operation_file( $file['path'] ) || filesize( $file['path'] ) !== $recovery['size'] || hash_file( 'sha256', $file['path'] ) !== $recovery['sha256'] ) {
			return false;
		}
		$recovery['state'] = 'verified';
		if ( ! $this->set_operation_state( $operation, $operation['journal']['current']['state'], [ 'main_recovery' => $recovery ] ) ) {
			return false;
		}
		$stored = $this->read_operation_journal( $operation['attachment_id'] );
		if ( ! is_array( $stored ) || $stored['current']['token'] !== $operation['token'] || $stored['current']['main_recovery'] !== $recovery ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'after_main_recovery_verified', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			$operation['recovery_error_code'] = 'recovery_interrupted';
			return false;
		}
		return true;
	}

	/**
	 * Read-only inspection of the journal's verified clean main source, taken
	 * before generation. The restore after the actual target checks repeats
	 * every check before it writes.
	 *
	 * @param array $operation Current operation.
	 * @return array Verified source path and whether the live main needs restoring.
	 */
	private function inspect_main_recovery( $operation ) {
		if ( ! $this->record_has_main_recovery( $operation['journal']['current'], true ) ) {
			return [ 'code' => 'recovery_source_invalid' ];
		}
		$recovery = $operation['journal']['current']['main_recovery'];
		$source = $this->resolve_upload_artifact_path( $recovery['path'] );
		if ( $source === false || ! is_file( $source['path'] ) || ! is_readable( $source['path'] ) || is_link( $source['path'] ) || filesize( $source['path'] ) !== $recovery['size'] || hash_file( 'sha256', $source['path'] ) !== $recovery['sha256'] ) {
			return [ 'code' => 'recovery_source_invalid' ];
		}
		$main = $this->resolve_upload_artifact_path( $recovery['target'] );
		if ( $main === false || ! is_file( $main['path'] ) || ! is_readable( $main['path'] ) ) {
			return [ 'code' => 'recovery_source_invalid' ];
		}
		if ( filesize( $main['path'] ) === $recovery['size'] && hash_file( 'sha256', $main['path'] ) === $recovery['sha256'] ) {
			return [ 'source' => $source['path'], 'restore' => false ];
		}
		$prior = isset( $operation['main_recovery_predecessor'] ) ? $operation['main_recovery_predecessor'] : null;
		if ( ! $this->record_has_main_recovery( $prior, true ) || ! $this->main_recovery_live_matches( $prior, $main['path'] ) ) {
			return [ 'code' => 'recovery_source_invalid' ];
		}
		$pre = $operation['pre_operation'];
		if ( ! isset( $operation['ownership'][ $pre['file'] ] ) || $operation['ownership'][ $pre['file'] ] !== 'exclusive' ) {
			return [ 'code' => 'ownership_uncertain' ];
		}
		return [ 'source' => $source['path'], 'restore' => true ];
	}

	/** @return bool Restore a known promotion only with exclusive ownership. */
	private function restore_main_recovery( &$operation, $options, $inspection ) {
		if ( ! $this->record_has_main_recovery( $operation['journal']['current'], true ) ) {
			$operation['recovery_error_code'] = 'recovery_source_invalid';
			return false;
		}
		$recovery = $operation['journal']['current']['main_recovery'];
		$source = isset( $inspection['source'] ) ? $inspection['source'] : '';
		if ( ! is_string( $source ) || ! is_file( $source ) || ! is_readable( $source ) || is_link( $source ) || filesize( $source ) !== $recovery['size'] || hash_file( 'sha256', $source ) !== $recovery['sha256'] ) {
			$operation['recovery_error_code'] = 'recovery_source_invalid';
			return false;
		}
		$main = $this->resolve_upload_artifact_path( $recovery['target'] );
		if ( $main === false || ! is_file( $main['path'] ) || ! is_readable( $main['path'] ) ) {
			return false;
		}
		if ( filesize( $main['path'] ) === $recovery['size'] && hash_file( 'sha256', $main['path'] ) === $recovery['sha256'] ) {
			return true;
		}
		$prior = isset( $operation['main_recovery_predecessor'] ) ? $operation['main_recovery_predecessor'] : null;
		if ( ! $this->record_has_main_recovery( $prior, true ) || ! $this->main_recovery_live_matches( $prior, $main['path'] ) ) {
			return false;
		}
		$pre = $operation['pre_operation'];
		if ( ! isset( $operation['ownership'][ $pre['file'] ] ) || $operation['ownership'][ $pre['file'] ] !== 'exclusive' ) {
			$operation['recovery_error_code'] = 'ownership_uncertain';
			return false;
		}
		return $this->stage_copy_and_promote( $operation, $source, $main['path'], 'restore-source', $options );
	}

	/** @return bool Both records derive the same clean main authority. */
	private function same_main_recovery_authority( $left, $right ) {
		foreach ( [ 'target', 'size', 'sha256', 'manifest_token' ] as $key ) {
			if ( $left[ $key ] !== $right[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool A displaced copy is redundant only with another proven source. */
	private function can_discard_main_recovery( $record, $authority ) {
		if ( ! $this->record_has_main_recovery( $record ) ) {
			return true;
		}
		if ( ! $this->record_has_main_recovery( $authority ) || $record['token'] === $authority['token'] || ! $this->same_main_recovery_authority( $record['main_recovery'], $authority['main_recovery'] ) ) {
			return false;
		}
		$recovery = $authority['main_recovery'];
		$file = $this->resolve_upload_artifact_path( $recovery['state'] === 'verified' ? $recovery['path'] : $recovery['target'] );
		if ( $file === false || ! is_file( $file['path'] ) || ! is_readable( $file['path'] ) || is_link( $file['path'] ) || ( $recovery['state'] === 'allocated' && ! $this->main_recovery_live_matches( $authority, $file['path'] ) ) ) {
			return false;
		}
		return filesize( $file['path'] ) === $recovery['size'] && hash_file( 'sha256', $file['path'] ) === $recovery['sha256'] && $this->verify_operation_file( $file['path'] );
	}

	/** @return bool Never compact away a record whose sole recovery copy is still needed. */
	private function cleanup_displaced_operation_record( &$operation, $record, $authority, $cleanup_recovery ) {
		$main = isset( $record['main_recovery'] );
		if ( $main && ! $this->can_discard_main_recovery( $record, $authority ) ) {
			$operation['begin_error_code'] = 'predecessor_uncertain';
			return false;
		}
		$clean = $this->cleanup_operation_record_artifacts( $operation, $record, $cleanup_recovery || $main );
		if ( $main && ! $this->cleanup_main_recovery( $operation, $record ) ) {
			$clean = false;
		}
		return $clean;
	}

	/** @return bool Exact journal-proven cleanup under the active fence. */
	private function cleanup_main_recovery( &$operation, $record ) {
		if ( ! isset( $record['main_recovery'] ) ) {
			return true;
		}
		if ( ! isset( $record['site_id'] ) || ! is_int( $record['site_id'] ) || $record['site_id'] !== get_current_blog_id() || ! $this->is_valid_main_recovery( $record['main_recovery'], $record['token'] ) ) {
			return false;
		}
		$recovery = $record['main_recovery'];
		$file = $this->resolve_upload_artifact_path( $recovery['path'] );
		$target = $this->resolve_upload_artifact_path( $recovery['target'] );
		if ( $file === false || $target === false || $file['relative'] !== $recovery['path'] || $file['parent'] !== $target['parent'] || is_link( $file['path'] ) ) {
			return false;
		}
		if ( ! file_exists( $file['path'] ) ) {
			return true;
		}
		// A journaled recovery name is not proof that its current occupant is ours.
		// Retain references and query failures; this check is not an unlink fence.
		return is_file( $file['path'] ) && ! $this->predecessor_stage_may_be_referenced( $recovery['path'] ) && ! $this->should_inject_operation_fault( 'recovery_cleanup', [ 'attachment_id' => $operation['attachment_id'], 'token' => $record['token'], 'recovery' => $recovery['path'] ] ) && $this->renew_operation_lock( $operation ) && @unlink( $file['path'] );
	}


	/** @return array Exact entries that missing/subset regeneration preserves. */
	private function regeneration_preserved_sizes( $data, $frozen, $request, $read_only = false ) {
		$preserved = [];
		foreach ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] as $name => $entry ) {
			$keep = $request['mode'] === 'subset' && ! in_array( (string) $name, $request['sizes'], true );
			if ( $request['mode'] === 'missing' ) {
				$relative = is_array( $entry ) && isset( $entry['file'] ) && is_string( $entry['file'] ) && $entry['file'] === wp_basename( $entry['file'] ) ? ( dirname( $data['file'] ) === '.' ? '' : dirname( $data['file'] ) . '/' ) . $entry['file'] : false;
				$failure = null;
				$file = $relative === false ? false : $this->resolve_upload_artifact_path( $relative, false, '', 0, $failure, $read_only );
				$keep = ! isset( $frozen[ $name ] ) || ( $file !== false && is_file( $file['path'] ) && is_readable( $file['path'] ) && ! is_link( $file['path'] ) );
			}
			if ( $keep ) {
				$preserved[ $name ] = $entry;
			}
		}
		return $preserved;
	}

	/** @return array Aliases affected through a selected physical target. */
	private function regeneration_shared_aliases( $groups, $metadata, $selected ) {
		$shared = [];
		foreach ( $groups as $group ) {
			$names = $group['names'];
			foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : [] as $name => $entry ) {
				if ( is_array( $entry ) && isset( $entry['file'] ) && $entry['file'] === wp_basename( $group['target'] ) ) {
					$names[] = (string) $name;
				}
			}
			$names = array_values( array_unique( $names ) );
			if ( array_intersect( $names, $selected ) && array_diff( $names, $selected ) ) {
				$shared[] = [ 'names' => $names, 'unselected' => array_values( array_diff( $names, $selected ) ) ];
			}
		}
		return $shared;
	}

	/** @return array Non-mutating prediction, using the same target planner. */
	private function preview_regeneration( $attachment_id, $input, $request, $options, $read_only = false ) {
		$uploads = $this->operation_upload_dir( $read_only );
		$early = ! $input['flagged'] ? $this->unwatermarked_regeneration_preflight( $attachment_id, $input, $request, $options, $read_only ) : null;
		if ( is_array( $early ) && isset( $early['error'] ) ) {
			return $early;
		}
		$pre = $early !== null ? $early['pre'] : $this->snapshot_pre_operation( $attachment_id, $input['metadata'], get_attached_file( $attachment_id ), $uploads );
		if ( $pre === false ) {
			return $this->regeneration_refusal( 'invalid_metadata', __( 'Invalid attachment metadata.', 'image-watermark' ), false, $read_only );
		}
		$frozen = $early !== null ? $early['frozen'] : $this->freeze_effective_sizes( $attachment_id, $input['metadata'], $pre );
		if ( count( $frozen ) > 200 || ( $request['mode'] === 'subset' && ( empty( $request['sizes'] ) || array_diff( $request['sizes'], array_map( 'strval', array_keys( $frozen ) ) ) ) ) ) {
			return $this->regeneration_refusal( 'invalid_sizes', __( 'Choose registered image sizes for regeneration.', 'image-watermark' ), false, $read_only );
		}
		$preserved = $early !== null ? $early['preserved'] : $this->regeneration_preserved_sizes( $input['metadata'], $frozen, $request, $read_only );
		$original = $early !== null ? $early['original'] : $this->proven_original_source( $attachment_id, $pre, $read_only );
		$source = $original === null ? [ $pre['width'], $pre['height'] ] : $this->oriented_source_dimensions( $pre, $uploads );
		$extension = pathinfo( $original === null ? $pre['file'] : $original['path'], PATHINFO_EXTENSION );
		$outputs = [];
		$unproducible = [];
		foreach ( $frozen as $name => $definition ) {
			if ( isset( $preserved[ $name ] ) || ( $request['mode'] === 'subset' && ! in_array( (string) $name, $request['sizes'], true ) ) ) {
				continue;
			}
			$geometry = $source && ( $definition['width'] !== null || $definition['height'] !== null ) ? image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ) : false;
			if ( $geometry ) {
				$file = 'preview-' . $geometry[4] . 'x' . $geometry[5] . '.' . $extension;
				if ( ! isset( $outputs[ $file ] ) ) {
					$outputs[ $file ] = [ 'names' => [], 'stage' => $file, 'width' => $geometry[4], 'height' => $geometry[5], 'mime' => $pre['mime'], 'size' => 1, 'sha256' => str_repeat( '0', 64 ) ];
				}
				$outputs[ $file ]['names'][] = (string) $name;
			} else {
				$unproducible[] = (string) $name;
			}
		}
		$journal = $this->read_operation_journal( $attachment_id );
		if ( $journal === false ) {
			return $this->regeneration_refusal( 'predecessor_uncertain', __( 'The interrupted image operation needs a verified clean source before it can be retried.', 'image-watermark' ), false, $read_only );
		}
		$journal = is_array( $journal ) ? $journal : [];
		$journal['current'] = isset( $journal['current'] ) && is_array( $journal['current'] ) ? $journal['current'] : [];
		$journal['current']['generation_stage'] = [ 'sizes' => $frozen, 'sources' => [ [ 'role' => $original === null ? 'main' : 'generation' ] ] ];
		$operation = [ 'attachment_id' => $attachment_id, 'pre_operation' => $pre, 'stored_metadata' => $input['metadata'], 'preserved_sizes' => $preserved, 'journal' => $journal, 'time_origin' => microtime( true ), 'regenerate_request' => $request ];
		if ( ! $input['flagged'] || $request['cleanup_stale'] ) {
			// Preview has no lease and grants no authority. Use the same read-only
			// ownership and clean-path prediction as execution for this request.
			$operation['frozen_sizes'] = $frozen;
			$operation['frozen_registered'] = $early !== null ? $early['registered'] : array_fill_keys( array_map( 'strval', array_keys( wp_get_registered_image_subsizes() ) ), true );
			if ( $early !== null ) { $operation['early_regeneration_plan'] = $early; }
			$operation['clean_legacy_regeneration'] = true;
			$operation['clean_legacy_flagged'] = $input['flagged'];
			$operation['clean_legacy_source'] = $input['source'];
			$operation['clean_legacy_options'] = isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [];
			$paths = [ $pre['file'] ];
			foreach ( $pre['sizes'] as $size ) { $paths[] = $size['path']; }
			foreach ( $pre['candidates'] as $candidates ) { $paths = array_merge( $paths, $candidates ); }
			$ownership = $this->derivative_paths_ownership( $attachment_id, array_values( array_unique( $paths ) ), $uploads, $this->operation_ownership_deadline( $operation ) );
			$operation['ownership'] = $ownership['paths'];
			$operation['ownership_uncertain'] = $ownership['uncertain'];
			$prediction = $this->predict_clean_legacy_entries( $operation, $pre, $input['metadata'], $uploads );
			$p = $this->predict_changed_watermarked_legacy( $operation, $pre, $input['metadata'], $uploads, $prediction );
			$prediction['limit'] = $this->legacy_size_preflight( $p['entries'] );
			$prediction['key_overflow'] = $prediction['key_overflow'] || $p['key_overflow'];
			if ( $prediction['limit']['exceeded'] || $prediction['key_overflow'] ) {
				return $this->regeneration_refusal( $prediction['limit']['exceeded'] ? 'legacy_limit_reached' : 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ), false, $read_only );
			}
			$operation['clean_legacy_predictions'] = $prediction['new'];
			$operation['changed_p_predictions'] = $p['new'];
		}
		$plan = $this->plan_generation_targets( $operation, [ 'outputs' => array_values( $outputs ), 'failed' => $unproducible ], $uploads, $input['flagged'] ? 'reapply' : 'regenerate', $options, $input['flagged'] );
		if ( isset( $plan['legacy_refusal'] ) ) {
			return $this->regeneration_refusal( $plan['legacy_refusal'], __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ), false, $read_only );
		}
		$selected = array_map( 'strval', array_keys( array_filter( $options['watermark_on'], static function( $active ) { return (int) $active === 1; } ) ) );
		$shared = $input['flagged'] ? $this->regeneration_shared_aliases( $plan['groups'], $plan['metadata'], $selected ) : [];
		if ( $request['strict_aliases'] && $shared ) {
			return $this->regeneration_refusal( 'shared_alias_conflict', __( 'A selected watermark size shares a file with a size that must stay clean.', 'image-watermark' ), false, $read_only );
		}
		$write = $plan['written'];
		if ( $input['flagged'] && ( in_array( 'full', $selected, true ) || hash_file( 'sha256', $input['source'] ) !== hash_file( 'sha256', get_attached_file( $attachment_id ) ) ) ) {
			$write = array_merge( [ 'full' ], $write );
		}
		$preview_retire = $plan['retire'];
		if ( $request['cleanup_stale'] ) {
			$references = $this->name_stable_references( $plan['metadata'], $uploads );
			foreach ( $preview_retire as $path => $item ) {
				if ( $this->name_stable_is_referenced( $references, $path, $uploads ) ) {
					unset( $preview_retire[ $path ] );
					$plan['skipped'][ $item['key'] ] = 'retained_referenced';
					continue;
				}
				$ownership = isset( $plan['ownership'][ $path ] ) ? $plan['ownership'][ $path ] : 'uncertain';
				if ( $ownership !== 'exclusive' ) {
					unset( $preview_retire[ $path ] );
					$plan['skipped'][ $item['key'] ] = $ownership === 'shared' ? 'retained_shared' : 'ownership_uncertain';
				}
			}
		}
		$keep = array_values( array_diff( array_keys( $pre['sizes'] ), $plan['written'], array_column( $preview_retire, 'key' ) ) );
		$keep_legacy = [];
		foreach ( isset( $operation['clean_legacy_predictions'] ) ? $operation['clean_legacy_predictions'] : [] as $old ) {
			foreach ( $plan['metadata']['sizes'] as $key => $entry ) {
				if ( $this->is_marked_legacy_size( $key, $entry ) && isset( $plan['previous'][ $key ] ) && $plan['previous'][ $key ] === $old['path'] ) {
					foreach ( $pre['sizes'] as $name => $size ) {
						if ( $size['path'] === $old['path'] ) {
							$keep[] = $name;
							$keep_legacy[] = [ 'name' => $name, 'path' => $old['path'] ];
						}
					}
					break;
				}
			}
		}
		// Preview does not stage P. Report only predicted paths that the actual
		// preview target groups did not claim, using the same R3-3 identity rule.
		$target_key = function( $relative ) use ( $uploads ) {
			$identity = $this->operation_path_identity( $uploads['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $uploads );
			return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
		};
		$targets = [];
		foreach ( $plan['groups'] as $group ) { $targets[ $target_key( $group['target'] ) ] = true; }
		foreach ( isset( $operation['changed_p_predictions'] ) ? $operation['changed_p_predictions'] : [] as $old ) {
			if ( empty( $plan['legacy_fallback'][ $old['path'] ] ) || isset( $targets[ $target_key( $old['path'] ) ] ) ) { continue; }
			foreach ( $pre['sizes'] as $name => $size ) {
				if ( $size['path'] === $old['path'] ) {
					$keep[] = $name;
					$keep_legacy[] = [ 'name' => $name, 'path' => $old['path'] ];
				}
			}
		}
		return [ 'preview' => [ 'cleanup_stale' => $request['cleanup_stale'], 'write' => $write, 'keep' => array_values( array_unique( $keep ) ), 'keep_legacy' => $keep_legacy, 'retire' => array_values( array_column( $preview_retire, 'key' ) ), 'unproducible' => $unproducible, 'shared' => $shared, 'skipped' => $this->name_stable_skipped_sizes( $plan ), 'watermarked' => $input['flagged'] ], 'outcome' => $this->operation_result( 'complete', '', 'preview', __( 'Regenerating a watermarked image re-renders it with the current watermark settings.', 'image-watermark' ), false, [], $read_only ) ];
	}

	/**
	 * Applies watermark to attachment sizes.
	 *
	 * @param array $data
	 * @param int|string $attachment_id
	 * @param string $method
	 *
	 * @return array
	 */
	public function apply_watermark( $data, $attachment_id, $method = '' ) {
		$result = $this->execute_operation( 'apply', $attachment_id, [ 'metadata' => $data, 'method' => $method ] );
		return $result['legacy_result'];
	}

	/** @return array */
	private function perform_apply_watermark( $data, $attachment_id, $method, $invocation = null ) {
		$attachment_id = (int) $attachment_id;
		$prepared = $this->prepare_apply_request( $data, $attachment_id, $method, false, $invocation !== null ? $invocation->runner_options : null );
		if ( isset( $prepared['noop'] ) ) {
			return $data;
		}
		$context = $method === 'manual' ? 'manual-apply' : 'auto-apply';
		if ( isset( $prepared['code'] ) ) {
			if ( ! empty( $prepared['notice'] ) ) {
				$this->add_admin_notice( $prepared['notice'], 'warning' );
			}
			if ( $prepared['state'] === 'skipped' ) {
				$result = $this->persist_terminal_outcome( $attachment_id, 'apply', $context, 'skipped', $prepared['code'], $prepared['message'], $prepared['last_status'], $invocation );
				if ( $method !== 'manual' ) { return $data; }
				return ! empty( $prepared['legacy_error_only'] ) && $invocation === null ? [ 'error' => $prepared['message'] ] : [ 'error' => $invocation === null ? $prepared['message'] : $result['message'], 'outcome' => $result ];
			}
			return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, $prepared['code'], $prepared['message'], $prepared['last_status'], $invocation );
		}
		return $this->execute_staged_apply( $data, $attachment_id, $method, $context, $prepared['options'], $prepared['upload_dir'], $prepared['target_files'], $prepared['metadata'], $invocation );
	}

	/** Inspect apply inputs without publication or staging. */
	private function prepare_apply_request( $data, $attachment_id, $method, $read_only = false, $runner_options = null ) {
		$attachment_id = (int) $attachment_id;
		// Only the matching request-local internal
		// operation bypasses the automatic metadata callback. A guard for another
		// attachment remains invisible here and cannot leak into this operation.
		if ( $this->get_internal_operation_guard( $attachment_id ) ) {
			return [ 'noop' => true ];
		}
		$post = get_post( $attachment_id );
		$post_id = ( ! empty( $post ) ? (int) $post->post_parent : 0 );
		$context = $method === 'manual' ? 'manual-apply' : 'auto-apply';

		// Bail early if metadata is not an array or missing file info
		if ( ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			$msg = __( 'Invalid attachment metadata.', 'image-watermark' );
			return [ 'state' => 'failed', 'code' => 'invalid_metadata', 'message' => $msg, 'last_status' => $method === 'manual' ? 'error' : 'skipped' ];
		}

		if ( $this->is_unfinalized_client_side_replacement( $attachment_id, $data ) ) {
			return [ 'state' => 'failed', 'code' => 'client_side_upload_unfinalized', 'message' => $this->client_side_unfinalized_message(), 'last_status' => 'error' ];
		}

		$options = is_array( $runner_options ) ? $runner_options : $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );

		// Use shared validation
		$validation = $this->validate_watermark_eligibility( $options, $attachment_id, $context );

		if ( ! $validation['valid'] ) {
			$code = ! empty( $validation['code'] ) ? $validation['code'] : 'validation_failed';
			$notice = '';
			if ( $method !== 'manual' && $validation['error'] ) {
				/* translators: 1: Watermark skip message. */
				$notice = sprintf( __( 'Image Watermark: Upload successful, but watermark skipped - %s', 'image-watermark' ), $validation['error'] );
			}
			return [ 'state' => 'failed', 'code' => $code, 'message' => $method === 'manual' ? $validation['error'] : ( $validation['error'] ?: __( 'Eligibility check failed.', 'image-watermark' ) ), 'last_status' => 'error', 'notice' => $notice ];
		}

		$apply_on = isset( $options['watermark_apply_on'] ) ? $options['watermark_apply_on'] : '';

		if ( ! in_array( $apply_on, [ 'everywhere', 'post_types' ], true ) ) {
			$legacy_cpt_on = isset( $options['watermark_cpt_on'] ) && is_array( $options['watermark_cpt_on'] ) ? $options['watermark_cpt_on'] : [];
			$is_list = array_values( $legacy_cpt_on ) === $legacy_cpt_on;
			$apply_on = $is_list
				? ( in_array( 'everywhere', $legacy_cpt_on, true ) ? 'everywhere' : 'post_types' )
				: ( array_key_exists( 'everywhere', $legacy_cpt_on ) ? 'everywhere' : 'post_types' );
		}

		if ( $method !== 'manual' && $this->is_admin === true && $apply_on === 'post_types' ) {
			$selected_post_types = [];

			if ( ! empty( $options['watermark_cpt_on'] ) && is_array( $options['watermark_cpt_on'] ) ) {
				$selected_post_types = $options['watermark_cpt_on'];
				if ( array_values( $selected_post_types ) !== $selected_post_types ) {
					$selected_post_types = array_keys( $selected_post_types );
				}
			}

			if ( $post_id <= 0 ) {
				return [ 'state' => 'failed', 'code' => 'attachment_unattached', 'message' => __( 'Automatic watermarking was skipped because the image was not attached to a post during upload.', 'image-watermark' ), 'last_status' => 'skipped' ];
			}

			$parent_post_type = get_post_type( $post_id );

			if ( ! in_array( $parent_post_type, $selected_post_types, true ) ) {
					/* translators: 1: Parent post type. */
					return [ 'state' => 'failed', 'code' => 'post_type_excluded', 'message' => sprintf( __( 'Automatic watermarking was skipped because the parent post type "%s" is not selected.', 'image-watermark' ), (string) $parent_post_type ), 'last_status' => 'skipped' ];
			}
		}

		if ( apply_filters( 'iw_watermark_display', $attachment_id ) === false ) {
			$message = __( 'Watermarking skipped by filter.', 'image-watermark' );
			return [ 'state' => 'skipped', 'code' => 'display_veto', 'message' => $message, 'last_status' => 'skipped' ];
		}

		$upload_dir = $this->operation_upload_dir( $read_only );
		$attached_file = get_attached_file( $attachment_id );
		$original_file = ( $attached_file && is_file( $attached_file ) )
			? $attached_file
			: $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $data['file'];

		// Ensure the target file exists and is a regular file before processing
		if ( ! is_file( $original_file ) ) {
			$msg = __( 'The original image file could not be found. It may have been moved, deleted, or offloaded to remote storage.', 'image-watermark' );
			return [ 'state' => 'failed', 'code' => 'file_missing', 'message' => $msg, 'last_status' => $method === 'manual' ? 'error' : 'skipped' ];
		}

		$image_size_result = getimagesize( $original_file, $original_image_info );
		if ( $image_size_result !== false ) {
			$original_width = $image_size_result[0];
			$original_height = $image_size_result[1];

			if ( $this->should_skip_small_image( $options, $original_width, $original_height ) ) {
				$msg = __( 'Image is smaller than the minimum dimensions required for watermarking.', 'image-watermark' );
				return [ 'state' => 'failed', 'code' => 'small_image', 'message' => $msg, 'last_status' => $method === 'manual' ? 'error' : 'skipped' ];
			}

			$target_files = $this->get_target_image_paths( $options, $data, $original_file, $upload_dir );

			if ( empty( $target_files ) ) {
				$msg = __( 'No selected image sizes are available for this attachment.', 'image-watermark' );
				return [ 'state' => 'skipped', 'code' => 'no_target_sizes', 'message' => $msg, 'last_status' => $method === 'manual' ? 'error' : 'skipped', 'legacy_error_only' => true ];
			}

				$metadata = $this->get_image_metadata( $original_image_info, $original_file );

				if ( ! empty( $metadata['error'] ) ) {
					/* translators: 1: Image metadata read error. */
					$message = sprintf( __( 'Watermark skipped because image metadata could not be read: %s', 'image-watermark' ), $metadata['error'] );

					return [ 'state' => 'failed', 'code' => 'metadata_read_failed', 'message' => $message, 'last_status' => 'error', 'notice' => $method === 'manual' ? '' : $message ];
				}

			return [ 'options' => $options, 'upload_dir' => $upload_dir, 'target_files' => $target_files, 'metadata' => $metadata ];
		}

		// getimagesize() failed: the file is corrupt or not a supported image. An
		// automatic upload records the skip too, so it is never silent.
		$read_error = __( 'The image file could not be read. It may be corrupt or in an unsupported format.', 'image-watermark' );
		return [ 'state' => 'failed', 'code' => 'image_unreadable', 'message' => $read_error, 'last_status' => $method === 'manual' ? 'error' : 'skipped' ];
	}

	/**
	 * Execute a complete apply/reapply under one fenced owner. Live files are only
	 * touched by promote_stage_file(), after their stage and journal entry exist.
	 *
	 * @return array
	 */
	private function execute_staged_apply( $data, $attachment_id, $method, $context, $options, $upload_dir, $target_files, $metadata, $invocation = null ) {
		// Path validation needs no lease. Rejecting before the lease is taken lets
		// the failure be recorded: without a journal, a terminal state cannot be.
		$groups = $this->operation_target_groups( $target_files, $upload_dir );
		if ( $groups === false ) {
			return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'invalid_targets', __( 'Selected image paths could not be safely prepared.', 'image-watermark' ), 'error', $invocation );
		}

		$token = $invocation !== null ? $invocation->operation_state['token'] : $this->operation_token();
		$lock = $invocation !== null ? $invocation->operation_state['lock'] : $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true ) );
		}

		$operation = $invocation !== null ? $this->borrow_contract_operation( $invocation ) : [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock, 'time_origin' => microtime( true ) ];
		try {
			if ( $this->initial_apply_only_attachment_id !== $attachment_id ) {
				$operation['reapply_begin_groups'] = $groups;
			}
			$recovery = $this->reconcile_predecessor( $operation, $data, $upload_dir, $context );
			if ( $recovery === 'complete' ) {
				if ( $invocation === null ) {
					return $data;
				}
				// Adoption completed the predecessor, not this runner's requested apply.
				// Re-read and prepare its own request under the same lease.
				$data = wp_get_attachment_metadata( $attachment_id, false );
				$prepared = $this->prepare_apply_request( $data, $attachment_id, $method, false, $invocation->runner_options );
				if ( isset( $prepared['noop'] ) ) {
					return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'interrupted', $token, 'recovery_interrupted', __( 'The interrupted watermark recovery was interrupted again and can be retried safely.', 'image-watermark' ), true ) );
				}
				if ( isset( $prepared['code'] ) ) {
					if ( $prepared['state'] === 'skipped' ) {
						$result = $this->persist_terminal_outcome( $attachment_id, 'apply', $context, 'skipped', $prepared['code'], $prepared['message'], $prepared['last_status'], $invocation );
						return [ 'error' => $result['message'], 'outcome' => $result ];
					}
					return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, $prepared['code'], $prepared['message'], $prepared['last_status'], $invocation );
				}
				$options = $prepared['options'];
				$upload_dir = $prepared['upload_dir'];
				$metadata = $prepared['metadata'];
				$groups = $this->operation_target_groups( $prepared['target_files'], $upload_dir );
				if ( $groups === false ) {
					return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'invalid_targets', __( 'Selected image paths could not be safely prepared.', 'image-watermark' ), 'error', $invocation );
				}
			}
			if ( $recovery === 'handoff_pending' ) {
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->handoff_pending_result( $token ) );
			}
			if ( $recovery === 'adoption_failed' ) {
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'interrupted', $token, 'journal_write_failed', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true ) );
			}
			if ( $recovery === 'uncertain' ) {
				$message = __( 'The interrupted watermark operation cannot prove that every live target is clean. No image was overwritten.', 'image-watermark' );
				if ( $invocation !== null ) {
					return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'predecessor_uncertain', $message, 'error', $invocation );
				}
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'failed', $token, 'predecessor_uncertain', $message, true ) );
			}
			if ( $recovery === 'begin_refused' ) {
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->begin_failure_result( $operation, $token ) );
			}
			if ( $recovery !== 'temporary-recovery' && $this->initial_apply_only_attachment_id !== $attachment_id && (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1 ) {
				$operation['name_stable_budget'] = true;
			}
			if ( ! $this->begin_operation( $operation, 'apply', $context, $groups ) ) {
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->begin_failure_result( $operation, $token ) );
			}
			// Before any live change, including a temporary-recovery restore.
			$selected = [];
			$paths = [];
			foreach ( $groups as $group ) {
				$selected = array_merge( $selected, $group['aliases'] );
				$paths[] = $group['target'];
			}
			if ( $this->manifest_preflight_exceeded( $attachment_id, $selected, $selected, $paths, $upload_dir ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'manifest_budget_exceeded', __( 'This image has too many selected sizes to process safely. No image was changed.', 'image-watermark' ) );
			}
			$recovered_temporary_sources = false;
			if ( $recovery === 'temporary-recovery' ) {
				if ( $this->should_inject_operation_fault( 'after_retry_begin', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
					return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'interrupted', $token, 'recovery_interrupted', __( 'The interrupted watermark recovery was interrupted again and can be retried safely.', 'image-watermark' ), true ) );
				}
				if ( ! $this->restore_predecessor_temporary_recovery( $operation ) ) {
					$code = ! empty( $operation['recovery_error_code'] ) ? $operation['recovery_error_code'] : 'recovery_source_invalid';
					if ( $code === 'recovery_interrupted' ) {
						return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'interrupted', $token, $code, __( 'The interrupted watermark recovery was interrupted again and can be retried safely.', 'image-watermark' ), true ) );
					}
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, __( 'The interrupted watermark operation needs a verified clean recovery source before it can be retried.', 'image-watermark' ), true );
				}
				$recovered_temporary_sources = true;
				if ( $this->should_inject_operation_fault( 'after_temporary_recovery_restore_complete', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
					return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'interrupted', $token, 'recovery_interrupted', __( 'The interrupted watermark recovery was interrupted again and can be retried safely.', 'image-watermark' ), true ) );
				}
			}

			$original_file = get_attached_file( $attachment_id );
			$reapply = ! $recovered_temporary_sources && (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
			if ( $reapply && $this->initial_apply_only_attachment_id === $attachment_id ) {
				// A deferred client-side upload is only ever watermarked by an initial
				// apply. Rechecked under the lease and after predecessor reconciliation,
				// so a concurrent or partially promoted finalize cannot restore the
				// backup and regenerate server-side sub-sizes.
				return $this->operation_terminal_skip( $operation, $data, $context, 'already_watermarked', __( 'Automatic watermarking was skipped because the image is already watermarked.', 'image-watermark' ) );
			}
			if ( $reapply ) {
				if ( ! $this->watermark_source_is_available( $options, $upload_dir ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'watermark_source_missing', __( 'Watermark not applied: the watermark source image file is missing from the server. Please re-select a watermark image in settings.', 'image-watermark' ) );
				}
				// S_main is the backup of the attached file, the identity authority.
				$attached_relative = get_post_meta( $attachment_id, '_wp_attached_file', true );
				$backup_lookup = $this->describe_backup_path( is_string( $attached_relative ) && $attached_relative !== '' ? $attached_relative : $data['file'] );
				$backup = $backup_lookup['path'];
				if ( $backup_lookup['code'] !== '' && $backup_lookup['code'] !== 'backup_missing' ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $backup_lookup['code'], $backup_lookup['message'], true );
				}
				if ( $backup === false || ! $this->is_valid_backup_file( $backup ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'backup_missing', __( 'Watermark not applied because the original backup is missing.', 'image-watermark' ) );
				}
				return $this->execute_staged_regeneration( $operation, $data, 'reapply', $options, $upload_dir, $backup, $method, $context, $metadata );
			}

			if ( ! empty( $options['backup']['backup_image'] ) && ! $this->create_staged_backup( $operation, $data, $upload_dir, $options ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', ! empty( $operation['backup_error_code'] ) ? $operation['backup_error_code'] : 'backup_failed', ! empty( $operation['backup_error'] ) ? $operation['backup_error'] : __( 'Failed to copy original file to backup location.', 'image-watermark' ), true );
			}
			if ( empty( $options['backup']['backup_image'] ) && ! $this->create_temporary_recovery_sources( $operation, $groups, $options ) ) {
				$code = ! empty( $operation['recovery_error_code'] ) ? $operation['recovery_error_code'] : 'recovery_source_invalid';
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, __( 'A verified temporary clean recovery source could not be created. Watermark not applied.', 'image-watermark' ), true );
			}
			if ( ! $this->set_operation_state( $operation, 'rendering' ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_write_failed', __( 'Watermark recovery state could not be updated.', 'image-watermark' ), true );
			}

			$processed = [];
			$failed = [];
			$staged_groups = [];
			$promotion_interrupted = false;
			$failure_code = 'write_failed';
			$failure_message = __( 'Watermark could not be applied to every selected image size.', 'image-watermark' );
			foreach ( $groups as $group ) {
				foreach ( $group['aliases'] as $alias ) {
					do_action( 'iw_before_apply_watermark', $attachment_id, $alias );
				}
				$stage = $this->operation_stage_path( $group['path'], $token, $group['target'] );
				$target = [
					'target'  => $group['target'],
					'root'    => 'uploads',
					'stage'   => $this->operation_planned_stage_relative_path( $stage, $upload_dir ),
					'aliases' => $group['aliases'],
					'intent'  => 'watermark-output',
					'state'   => 'allocated',
				];
				if ( $target['stage'] === false || ! $this->set_operation_target( $operation, $target ) ) {
					$failed = array_merge( $failed, $group['aliases'] );
					continue;
				}
				// A name-stable reapply renders a selected size from its fresh clean
				// output into the stage for its old path.
				$render_source = isset( $group['source'] ) ? $group['source'] : $group['path'];
				$decoder_probe = apply_filters( 'iw_watermark_image_decoder', null, $render_source, wp_check_filetype( $render_source )['type'] );
				$stage_failed = $this->should_inject_operation_fault( 'during_staging', [ 'attachment_id' => $attachment_id, 'token' => $token, 'target' => $group['target'] ] ) || ( null !== $decoder_probe && ! $this->is_gd_image( $decoder_probe ) ) || ! $this->copy_file( $render_source, $stage, 'stage', $attachment_id, $options ) || $this->should_inject_operation_fault( 'after_stage_copy', [ 'attachment_id' => $attachment_id, 'token' => $token, 'target' => $group['target'] ] );
				if ( ! $stage_failed && ! $this->do_watermark( $attachment_id, $stage, $group['aliases'][0], $upload_dir, $metadata, $options ) ) {
					$stage_failed = true;
					if ( ! empty( $this->last_renderer_failure['code'] ) ) {
						$failure_code = $this->last_renderer_failure['code'];
						$failure_message = $this->last_renderer_failure['message'];
					}
				}
				if ( $stage_failed ) {
					$this->cleanup_lost_upload_artifact( $stage, $token, '.iw-stage-' );
					$failed = array_merge( $failed, $group['aliases'] );
					continue;
				}
				try {
					$metadata_result = $this->save_image_metadata( $metadata, $stage );
				} catch ( Exception $e ) {
					$metadata_result = [ 'success' => false, 'error' => $e->getMessage() ];
				}
				if ( empty( $metadata_result['success'] ) || ! $this->verify_operation_file( $stage ) ) {
					$this->cleanup_lost_upload_artifact( $stage, $token, '.iw-stage-' );
					$failure_code = 'metadata_write_failed';
					/* translators: 1: Image metadata write error. */
					$failure_message = sprintf( __( 'Watermark applied, but image metadata could not be preserved: %s', 'image-watermark' ), ! empty( $metadata_result['error'] ) ? $metadata_result['error'] : __( 'The staged image could not be verified.', 'image-watermark' ) );
					$failed = array_merge( $failed, $group['aliases'] );
					continue;
				}
				$target = [
					'target'   => $group['target'],
					'root'     => 'uploads',
					'stage'    => $target['stage'],
					'size'     => filesize( $stage ),
					'hash'     => hash_file( 'sha256', $stage ),
					'aliases'  => $group['aliases'],
					'intent'   => 'watermark-output',
					'state'    => 'staged',
				];
				if ( ! $this->set_operation_target( $operation, $target ) ) {
					$this->cleanup_lost_upload_artifact( $stage, $token, '.iw-stage-' );
					$failed = array_merge( $failed, $group['aliases'] );
					continue;
				}
				$staged_groups[] = [ 'group' => $group, 'stage' => $stage, 'target' => $target ];
			}

			if ( ! empty( $failed ) || empty( $staged_groups ) ) {
				$state = empty( $processed ) ? 'failed' : 'partial';
				return $this->operation_terminal_failure( $operation, $data, $method, $context, $state, $failure_code, $failure_message, true, $processed, $failed );
			}
			if ( ! $this->set_operation_state( $operation, 'promoting' ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'interrupted', 'journal_write_failed', __( 'All watermark outputs were staged, but promotion could not be safely started.', 'image-watermark' ), true );
			}
			foreach ( $staged_groups as $staged ) {
				if ( ! $this->promote_stage_file( $operation, $staged['stage'], $staged['group']['path'], $staged['target'] ) ) {
					if ( $this->verify_operation_file( $staged['group']['path'] ) && (string) filesize( $staged['group']['path'] ) === (string) $staged['target']['size'] && hash_file( 'sha256', $staged['group']['path'] ) === $staged['target']['hash'] ) {
						$promotion_interrupted = true;
					}
					$failed = array_merge( $failed, $staged['group']['aliases'] );
					continue;
				}
				$processed = array_merge( $processed, $staged['group']['aliases'] );
				foreach ( $staged['group']['aliases'] as $alias ) {
					do_action( 'iw_after_apply_watermark', $attachment_id, $alias );
				}
			}
			if ( ! empty( $failed ) || empty( $processed ) ) {
				$state = $promotion_interrupted ? 'interrupted' : ( empty( $processed ) ? 'failed' : 'partial' );
				if ( empty( $options['backup']['backup_image'] ) && ( ! empty( $processed ) || $promotion_interrupted ) ) {
					$this->commit_watermarked_flag( $operation, 1 );
				}
				return $this->operation_terminal_failure( $operation, $data, $method, $context, $state, $failure_code, $failure_message, true, $processed, $failed );
			}
			if ( ! $this->renew_operation_lock( $operation ) || ! $this->set_operation_state( $operation, 'metadata-commit' ) || $this->should_inject_operation_fault( 'before_metadata_commit', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_fence_lost', __( 'Watermarked files were prepared, but their metadata could not be safely committed.', 'image-watermark' ), true, $processed );
			}
			// The intended manifest is journaled before the flag commit, so a lost
			// manifest is rebuilt only from this evidence, never from today's files.
			// An automatic initial apply hands its metadata to core, which writes it
			// after the filter chain; its manifest waits for that write to verify.
			$handoff = $method !== 'manual';
			$manifest_evidence = null;
			if ( $method === 'manual' || $handoff ) {
				$manifest_evidence = $this->build_manifest_evidence( $operation, $data, $staged_groups, $options, $upload_dir );
				$details = $handoff ? [ 'manifest_evidence' => $manifest_evidence, 'metadata_handoff' => 'pending' ] : [ 'manifest_evidence' => $manifest_evidence ];
				if ( $manifest_evidence === false || $this->should_inject_operation_fault( 'manifest_evidence', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) || ! $this->set_operation_state( $operation, 'metadata-commit', $details ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermarked files were prepared, but the operation could not be safely completed.', 'image-watermark' ), true, $processed );
				}
			}
			if ( ! $this->commit_watermarked_flag( $operation, 1 ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_failed', __( 'Watermarked files were prepared, but their watermark state could not be committed.', 'image-watermark' ), true, $processed );
			}
			if ( $handoff ) {
				return $this->operation_rendered( $operation, $data, $attachment_id, $processed );
			}
			// A manifest that is not written is rebuilt later from the journaled
			// evidence; until then it is reported untrusted.
			if ( is_array( $manifest_evidence ) ) {
				$manifest = $this->manifest_from_evidence( $manifest_evidence, $operation['journal']['current'] );
				if ( $manifest === false || ! $this->write_watermark_manifest( $operation, $manifest ) ) {
					// A value with this operation's token that did not verify is never
					// left to be read as current; an older or absent one stays as is.
					$this->discard_operation_manifest( $operation, $token );
				}
			}
			return $this->operation_complete( $operation, $data, $attachment_id, $method, $context, 'watermarked', __( 'Watermark applied.', 'image-watermark' ), $processed );
		} finally {
			$this->internal_operation_guard = null;
			if ( $invocation === null ) { $this->release_operation_lock( $operation ); }
		}
	}

	/**
	 * Removes a watermark from an image.
	 *
	 * @param array $data Attachment metadata.
	 * @param int|string $attachment_id Attachment post ID.
	 * @param string $method Operation method ('manual' or empty).
	 *
	 * @return array|false Updated metadata on success, array with 'error' key on failure.
	 */
	public function remove_watermark( $data, $attachment_id, $method = '', $options = null ) {
		$result = $this->execute_operation( 'remove', $attachment_id, [ 'metadata' => $data, 'method' => $method, 'options' => $options ] );
		return $result['legacy_result'];
	}

	/** @return array */
	private function perform_remove_watermark( $data, $attachment_id, $method, $options, $invocation = null ) {
		$prepared = $this->prepare_remove_request( $data, $attachment_id, $method, $invocation !== null && is_array( $invocation->runner_options ) ? $invocation->runner_options : $options );
		if ( isset( $prepared['noop'] ) ) {
			return $data;
		}
		if ( isset( $prepared['code'] ) ) {
			return $this->early_operation_failure( $data, (int) $attachment_id, 'remove', 'manual-remove', $method, $prepared['code'], $prepared['message'], 'error', $invocation );
		}
		return $this->execute_staged_remove( $data, (int) $attachment_id, $prepared['filepath'], $prepared['backup'], $prepared['options'], $invocation );
	}

	/** Inspect remove inputs without publishing or modifying files. */
	private function prepare_remove_request( $data, $attachment_id, $method, $options = null, $read_only = false ) {
		if ( $method !== 'manual' ) {
			return [ 'noop' => true ];
		}
		$attachment_id = (int) $attachment_id;
		if ( ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			return [ 'code' => 'invalid_metadata', 'message' => __( 'Invalid attachment metadata.', 'image-watermark' ) ];
		}
		if ( $this->is_unfinalized_client_side_replacement( $attachment_id, $data ) ) {
			return [ 'code' => 'client_side_upload_unfinalized', 'message' => $this->client_side_unfinalized_message() ];
		}
		if ( ! is_array( $options ) ) {
			$options = apply_filters( 'iw_watermark_options', $this->plugin->options );
		}
		$validation = $this->validate_watermark_eligibility( $options, $attachment_id, 'manual-remove' );
		if ( ! $validation['valid'] ) {
			return [ 'code' => ! empty( $validation['code'] ) ? $validation['code'] : 'validation_failed', 'message' => $validation['error'] ];
		}
		$upload_dir = $this->operation_upload_dir( $read_only );
		// A damaged destination is allowed: the clean backup is the source.
		$filepath = get_attached_file( $attachment_id );
		if ( ! is_string( $filepath ) || $filepath === '' ) {
			$filepath = $upload_dir['basedir'] . '/' . ltrim( wp_normalize_path( $data['file'] ), '/' );
		}
		$backup = $this->describe_backup_path( get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		if ( $backup['code'] !== '' && $backup['code'] !== 'backup_missing' ) {
			return [ 'code' => $backup['code'], 'message' => $backup['message'] ];
		}
		if ( $backup['path'] === false || ! $this->is_valid_backup_file( $backup['path'] ) ) {
			return [ 'code' => 'backup_missing', 'message' => __( 'No watermark backup found for this image.', 'image-watermark' ) ];
		}
		return [ 'filepath' => $filepath, 'backup' => $backup['path'], 'options' => $options ];
	}

	/**
	 * Returns image metadata.
	 *
	 * @param array $imageinfo
	 *
	 * @return array
	 */
	private function get_image_metadata( $imageinfo, $file = '' ) {
		$metadata = [
			'exif'     => null,
			'iptc'     => null,
			'segments' => [],
			'error'    => '',
		];

		if ( $file && wp_check_filetype( $file )['type'] === 'image/jpeg' ) {
			$source = $this->read_jpeg_metadata_file( $file );

			if ( ! $source['success'] ) {
				$metadata['error'] = $source['error'];
				return $metadata;
			}

			$segments = $this->get_jpeg_metadata_segments( $source['contents'] );

			if ( ! $segments['success'] ) {
				$metadata['error'] = $segments['error'];
				return $metadata;
			}

			foreach ( $segments['segments'] as $segment ) {
				$type = $this->get_jpeg_metadata_segment_type( $segment['marker'], $segment['raw'] );

				if ( $type === 'exif' ) {
					$metadata['exif'] = $segment['raw'];
				} elseif ( $type === 'iptc' ) {
					$metadata['iptc'] = $segment['raw'];
				}
			}

			$metadata['segments'] = $segments['segments'];
		}

		if ( is_array( $imageinfo ) ) {
			$exifdata = key_exists( 'APP1', $imageinfo ) ? $imageinfo['APP1'] : null;

			if ( ! $metadata['exif'] && $exifdata && strpos( $exifdata, "Exif\x00\x00" ) === 0 ) {
				$exiflength = strlen( $exifdata ) + 2;

				if ( $exiflength <= 0xFFFF ) {
					$metadata['exif'] = chr( 0xFF ) . chr( 0xE1 ) . chr( ( $exiflength >> 8 ) & 0xFF ) . chr( $exiflength & 0xFF ) . $exifdata;
				}
			}

			$iptcdata = key_exists( 'APP13', $imageinfo ) ? $imageinfo['APP13'] : null;

			if ( ! $metadata['iptc'] && $iptcdata && strpos( $iptcdata, "Photoshop 3.0\x00" ) === 0 ) {
				$iptclength = strlen( $iptcdata ) + 2;

				if ( $iptclength <= 0xFFFF ) {
					$metadata['iptc'] = chr( 0xFF ) . chr( 0xED ) . chr( ( $iptclength >> 8 ) & 0xFF ) . chr( $iptclength & 0xFF ) . $iptcdata;
				}
			}
		}

		return $metadata;
	}

	/**
	 * Saves EXIF/IPTC metadata into the destination file.
	 *
	 * @param array $metadata
	 * @param string $file
	 *
	 * @return array{success:bool,error:string}
	 */
	private function save_image_metadata( $metadata, $file ) {
		$mime = wp_check_filetype( $file );

		if ( $mime['type'] !== 'image/jpeg' ) {
			return $this->metadata_write_result( true );
		}

		if ( ! empty( $metadata['error'] ) ) {
			return $this->metadata_write_result( false, $metadata['error'] );
		}

		$exifdata = isset( $metadata['exif'] ) ? $metadata['exif'] : null;
		$iptcdata = isset( $metadata['iptc'] ) ? $metadata['iptc'] : null;
		$source_segments = isset( $metadata['segments'] ) && is_array( $metadata['segments'] ) ? $metadata['segments'] : [];

		if ( ! $exifdata && ! $iptcdata && empty( $source_segments ) ) {
			return $this->metadata_write_result( true );
		}

		if ( ! $this->is_valid_metadata_segment( $exifdata, 0xE1, "Exif\x00\x00" ) || ! $this->is_valid_metadata_segment( $iptcdata, 0xED, "Photoshop 3.0\x00" ) ) {
			return $this->metadata_write_result( false, __( 'The image metadata is invalid.', 'image-watermark' ) );
		}

		$source = $this->read_jpeg_metadata_file( $file );

		if ( ! $source['success'] ) {
			return $source;
		}

		$contents = $source['contents'];
		$length = strlen( $contents );

		if ( $length < 4 || substr( $contents, 0, 2 ) !== "\xFF\xD8" ) {
			return $this->metadata_write_result( false, __( 'The JPEG header is invalid.', 'image-watermark' ) );
		}

		$offset = 2;
		$output = "\xFF\xD8";
		$exif_added = ! $exifdata;
		$iptc_added = ! $iptcdata;
		$replacement_segments = $this->get_jpeg_metadata_replacement_segments( $source_segments, $exifdata, $iptcdata );
		$replace_source_segments = ! empty( $source_segments );
		$replacement_segments_added = ! $replace_source_segments;
		if ( $replace_source_segments ) {
			$exif_added = true;
			$iptc_added = true;
		}

		while ( $offset < $length ) {
			if ( substr( $contents, $offset, 1 ) !== "\xFF" || $offset + 1 >= $length ) {
				return $this->metadata_write_result( false, __( 'The JPEG marker is invalid.', 'image-watermark' ) );
			}

			$marker = $this->get_safe_chunk( substr( $contents, $offset + 1, 1 ) );

			if ( $marker === false ) {
				return $this->metadata_write_result( false, __( 'The JPEG marker could not be read.', 'image-watermark' ) );
			}

			if ( $marker === 0xDA || $marker === 0xD9 ) {
				if ( ! $replacement_segments_added ) {
					$output .= implode( '', $replacement_segments );
				}

				if ( ! $exif_added ) {
					$output .= $exifdata;
				}

				if ( ! $iptc_added ) {
					$output .= $iptcdata;
				}

				return $this->write_jpeg_metadata_file( $file, $output . substr( $contents, $offset ) );
			}

			if ( $marker === 0x01 || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) {
				$output .= substr( $contents, $offset, 2 );
				$offset += 2;
				continue;
			}

			if ( $offset + 4 > $length ) {
				return $this->metadata_write_result( false, __( 'The JPEG segment length is truncated.', 'image-watermark' ) );
			}

			$segment_length = $this->get_safe_chunk( substr( $contents, $offset + 2, 2 ) );

			if ( $segment_length === false || $segment_length < 2 || $segment_length + 2 > $length - $offset ) {
				return $this->metadata_write_result( false, __( 'The JPEG segment length is invalid.', 'image-watermark' ) );
			}

			$segment = substr( $contents, $offset, $segment_length + 2 );
			$type = $this->get_jpeg_metadata_segment_type( $marker, $segment );

			if ( $replace_source_segments && $this->is_jpeg_metadata_marker( $marker ) ) {
				$offset += $segment_length + 2;
				continue;
			}

			if ( ! $replacement_segments_added ) {
				$output .= implode( '', $replacement_segments );
				$replacement_segments_added = true;
			}

			if ( $type === 'exif' ) {
				if ( ! $exif_added ) {
					$output .= $exifdata;
					$exif_added = true;
				}
			} elseif ( $type === 'iptc' ) {
				if ( ! $iptc_added ) {
					$output .= $iptcdata;
					$iptc_added = true;
				}
			} else {
				$output .= $segment;
			}

			$offset += $segment_length + 2;
		}

		return $this->metadata_write_result( false, __( 'The JPEG ended before a scan or end marker.', 'image-watermark' ) );
	}

	/**
	 * Helper to interpret binary segments safely.
	 *
	 * @param string|int $value
	 *
	 * @return int|false
	 */
	private function get_safe_chunk( $value ) {
		if ( ! is_string( $value ) ) {
			return false;
		}

		if ( strlen( $value ) === 2 ) {
			$chunk = unpack( 'nvalue', $value );
			return $chunk['value'];
		}

		if ( strlen( $value ) === 1 ) {
			$chunk = unpack( 'Cvalue', $value );
			return $chunk['value'];
		}

		return false;
	}

	/**
	 * Identify replaceable metadata by its segment payload, not marker number alone.
	 *
	 * @param int    $marker JPEG marker ID.
	 * @param string $segment Complete JPEG segment.
	 * @return string
	 */
	private function get_jpeg_metadata_segment_type( $marker, $segment ) {
		$payload = substr( $segment, 4 );

		if ( $marker === 0xE1 && strpos( $payload, "Exif\x00\x00" ) === 0 ) {
			return 'exif';
		}

		if ( $marker === 0xED && strpos( $payload, "Photoshop 3.0\x00" ) === 0 ) {
			return 'iptc';
		}

		return '';
	}

	/**
	 * Collect APP and comment segments from a JPEG header without entering scan data.
	 *
	 * @param string $contents JPEG bytes.
	 * @return array{success:bool,error:string,segments?:array}
	 */
	private function get_jpeg_metadata_segments( $contents ) {
		$length = strlen( $contents );

		if ( $length < 4 || substr( $contents, 0, 2 ) !== "\xFF\xD8" ) {
			return $this->metadata_write_result( false, __( 'The JPEG header is invalid.', 'image-watermark' ) );
		}

		$segments = [];
		$offset = 2;

		while ( $offset < $length ) {
			if ( substr( $contents, $offset, 1 ) !== "\xFF" || $offset + 1 >= $length ) {
				return $this->metadata_write_result( false, __( 'The JPEG marker is invalid.', 'image-watermark' ) );
			}

			$marker = $this->get_safe_chunk( substr( $contents, $offset + 1, 1 ) );

			if ( $marker === false ) {
				return $this->metadata_write_result( false, __( 'The JPEG marker could not be read.', 'image-watermark' ) );
			}

			if ( $marker === 0xDA || $marker === 0xD9 ) {
				return [
					'success'  => true,
					'error'    => '',
					'segments' => $segments,
				];
			}

			if ( $marker === 0x01 || ( $marker >= 0xD0 && $marker <= 0xD7 ) || $offset + 4 > $length ) {
				return $this->metadata_write_result( false, __( 'The JPEG metadata segment is invalid.', 'image-watermark' ) );
			}

			$segment_length = $this->get_safe_chunk( substr( $contents, $offset + 2, 2 ) );

			if ( $segment_length === false || $segment_length < 2 || $segment_length + 2 > $length - $offset ) {
				return $this->metadata_write_result( false, __( 'The JPEG metadata segment is invalid.', 'image-watermark' ) );
			}

			if ( $this->is_jpeg_metadata_marker( $marker ) ) {
				$segments[] = [
					'marker' => $marker,
					'raw'    => substr( $contents, $offset, $segment_length + 2 ),
				];
			}

			$offset += $segment_length + 2;
		}

		return $this->metadata_write_result( false, __( 'The JPEG ended before a scan or end marker.', 'image-watermark' ) );
	}

	/**
	 * Determine whether a marker is metadata that image encoders may discard.
	 *
	 * @param int $marker JPEG marker ID.
	 * @return bool
	 */
	private function is_jpeg_metadata_marker( $marker ) {
		return ( $marker >= 0xE0 && $marker <= 0xEF ) || $marker === 0xFE;
	}

	/**
	 * Build the source metadata sequence with EXIF/IPTC replacement semantics.
	 *
	 * @param array       $segments Source metadata segments.
	 * @param string|null $exifdata EXIF replacement.
	 * @param string|null $iptcdata IPTC replacement.
	 * @return array
	 */
	private function get_jpeg_metadata_replacement_segments( $segments, $exifdata, $iptcdata ) {
		$replacement = [];
		$exif_added = ! $exifdata;
		$iptc_added = ! $iptcdata;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) || ! isset( $segment['marker'], $segment['raw'] ) || ! is_string( $segment['raw'] ) || ! $this->is_jpeg_metadata_marker( $segment['marker'] ) ) {
				continue;
			}

			$type = $this->get_jpeg_metadata_segment_type( $segment['marker'], $segment['raw'] );

			if ( $type === 'exif' ) {
				if ( ! $exif_added ) {
					$replacement[] = $exifdata;
					$exif_added = true;
				}
				continue;
			}

			if ( $type === 'iptc' ) {
				if ( ! $iptc_added ) {
					$replacement[] = $iptcdata;
					$iptc_added = true;
				}
				continue;
			}

			$replacement[] = $segment['raw'];
		}

		if ( ! $exif_added ) {
			$replacement[] = $exifdata;
		}

		if ( ! $iptc_added ) {
			$replacement[] = $iptcdata;
		}

		return $replacement;
	}

	/**
	 * Check a metadata segment before adding it to a JPEG.
	 *
	 * @param string|null $segment Complete JPEG segment.
	 * @param int         $marker Expected marker ID.
	 * @param string      $prefix Expected segment payload prefix.
	 * @return bool
	 */
	private function is_valid_metadata_segment( $segment, $marker, $prefix ) {
		if ( ! $segment ) {
			return true;
		}

		if ( ! is_string( $segment ) || strlen( $segment ) < 4 || substr( $segment, 0, 1 ) !== "\xFF" || $this->get_safe_chunk( substr( $segment, 1, 1 ) ) !== $marker ) {
			return false;
		}

		$segment_length = $this->get_safe_chunk( substr( $segment, 2, 2 ) );
		return $segment_length !== false && $segment_length >= 2 && $segment_length + 2 === strlen( $segment ) && strpos( substr( $segment, 4 ), $prefix ) === 0;
	}

	/**
	 * Read a JPEG source and reject short reads before it can be replaced.
	 *
	 * @param string $file JPEG path.
	 * @return array{success:bool,error:string,contents?:string}
	 */
	private function read_jpeg_metadata_file( $file ) {
		if ( ! file_exists( $file ) ) {
			return $this->metadata_write_result( false, __( 'The JPEG file could not be opened.', 'image-watermark' ) );
		}

		try {
			$allowed = apply_filters( 'iw_watermark_metadata_open', true, $file );
		} catch ( \Throwable $error ) {
			return $this->metadata_write_result( false, __( 'The JPEG file could not be opened.', 'image-watermark' ) );
		}

		if ( ! $allowed ) {
			return $this->metadata_write_result( false, __( 'The JPEG file could not be opened.', 'image-watermark' ) );
		}

		$stream = null;
		$result = $this->metadata_write_result( false, __( 'The JPEG file could not be read completely.', 'image-watermark' ) );

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Explicit stream handling verifies complete staged metadata reads.
			$stream = @fopen( $file, 'rb' );

			if ( ! $stream ) {
				$result = $this->metadata_write_result( false, __( 'The JPEG file could not be opened.', 'image-watermark' ) );
			} else {
				$stat = fstat( $stream );
				$contents = stream_get_contents( $stream );

				if ( is_array( $stat ) && isset( $stat['size'] ) && is_string( $contents ) && strlen( $contents ) === (int) $stat['size'] ) {
					$result = [
						'success'  => true,
						'error'    => '',
						'contents' => $contents,
					];
				}
			}
		} catch ( \Throwable $error ) {
			$result = $this->metadata_write_result( false, __( 'The JPEG file could not be read completely.', 'image-watermark' ) );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Explicit close is required to verify staged metadata reads.
			if ( is_resource( $stream ) && ! @fclose( $stream ) ) {
				$result = $this->metadata_write_result( false, __( 'The JPEG file could not be read completely.', 'image-watermark' ) );
			}
		}

		return $result;
	}

	/**
	 * Stage verified metadata output before promoting it over the live JPEG.
	 *
	 * This stages the JPEG metadata rewrite within the enclosing attachment operation.
	 *
	 * @param string $file Live JPEG path.
	 * @param string $contents Complete JPEG output.
	 * @return array{success:bool,error:string}
	 */
	private function write_jpeg_metadata_file( $file, $contents ) {
		$directory = dirname( $file );
		$temp_file = '';
		$stream = null;
		$promoted = false;

		try {
			$temp_file = apply_filters( 'iw_watermark_metadata_temp_file', null, $directory, $file );

			if ( $temp_file === null ) {
				$temp_file = @tempnam( $directory, '.iw-metadata-' );
			}

			if ( ! is_string( $temp_file ) || ! $temp_file ) {
				return $this->metadata_write_result( false, __( 'A temporary JPEG metadata file could not be created.', 'image-watermark' ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Explicit stream handling verifies staged metadata writes.
			$stream = @fopen( $temp_file, 'wb' );

			if ( ! $stream ) {
				return $this->metadata_write_result( false, __( 'The temporary JPEG metadata file could not be opened.', 'image-watermark' ) );
			}

			$written = apply_filters( 'iw_watermark_metadata_write', null, $stream, $contents, $temp_file );
			if ( $written === null ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Exact byte count is required before atomic promotion.
				$written = @fwrite( $stream, $contents );
			}

			$flushed = apply_filters( 'iw_watermark_metadata_flush', null, $stream, $temp_file );
			if ( $flushed === null ) {
				$flushed = @fflush( $stream );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Explicit close is required before atomic promotion.
			$closed = @fclose( $stream );
			$stream = null;
			$close_result = apply_filters( 'iw_watermark_metadata_close', null, $closed, $temp_file );
			if ( $close_result !== null ) {
				$closed = (bool) $close_result;
			}

			clearstatcache( true, $temp_file );
			$complete = is_int( $written ) && $written === strlen( $contents ) && $flushed && $closed && is_file( $temp_file ) && filesize( $temp_file ) === strlen( $contents );

			if ( ! $complete ) {
				return $this->metadata_write_result( false, __( 'The JPEG metadata output could not be verified.', 'image-watermark' ) );
			}

			clearstatcache( true, $file );
			$destination_stat = @stat( $file );
			if ( $destination_stat === false ) {
				return $this->metadata_write_result( false, __( 'The JPEG metadata output could not be given the image file permissions.', 'image-watermark' ) );
			}
			$required_mode = $destination_stat['mode'] & 0777;
			// Rename publishes the temporary file's access bits; special bits do not belong on a JPEG output.
			if ( ! $this->should_inject_operation_fault( 'jpeg_metadata_permission_set_failure', [ 'file' => $file, 'temp_file' => $temp_file ] ) ) {
				@chmod( $temp_file, $required_mode );
			}
			clearstatcache( true, $temp_file );
			$temp_stat = @stat( $temp_file );
			if ( $temp_stat === false || ( $temp_stat['mode'] & 0777 ) !== $required_mode ) {
				return $this->metadata_write_result( false, __( 'The JPEG metadata output could not be given the image file permissions.', 'image-watermark' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename preserves atomic promotion semantics.
			if ( ! @rename( $temp_file, $file ) ) {
				return $this->metadata_write_result( false, __( 'The verified JPEG metadata output could not replace the image.', 'image-watermark' ) );
			}

			$promoted = true;
			return $this->metadata_write_result( true );
		} catch ( \Throwable $error ) {
			return $this->metadata_write_result( false, __( 'The JPEG metadata output could not be written.', 'image-watermark' ) );
		} finally {
			if ( is_resource( $stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close failed staging stream during cleanup.
				@fclose( $stream );
			}

			if ( ! $promoted && is_string( $temp_file ) && $temp_file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only unpromoted staged metadata output.
				@unlink( $temp_file );
			}
		}
	}

	/** @param array $operation Current operation. @param array $groups Physical target groups. @return bool */
	private function begin_operation( &$operation, $type, $context, $groups ) {
		$journal = $this->read_operation_journal( $operation['attachment_id'] );
		if ( $journal === false ) {
			return false;
		}
		// Callers that do not reconcile first settle a pending handoff here.
		$handoff = $this->resolve_pending_handoff( $operation, $journal );
		if ( $handoff === 'handoff_pending' || $handoff === 'begin_refused' ) {
			if ( $handoff === 'handoff_pending' ) { $operation['begin_error_code'] = 'handoff_pending'; }
			return false;
		}
		if ( $handoff !== 'none' ) {
			$journal = $this->read_operation_journal( $operation['attachment_id'] );
			if ( $journal === false ) {
				return false;
			}
		}
		$prepared = isset( $operation['prepared_begin'] ) ? $operation['prepared_begin'] : $this->prepare_begin_operation( $operation, $journal, $type, $context, $groups );
		unset( $operation['prepared_begin'] );
		if ( $prepared === false || maybe_serialize( $prepared['original'] ) !== maybe_serialize( $journal ) ) {
			return false;
		}
		$operation['journal'] = $journal;
		$operation['pre_operation_stale'] = $prepared['inheritance']['stale'];
		if ( ! $this->begin_prefix_confirmed( $operation ) ) {
			return false;
		}
		if ( $prepared['collision'] !== null && ! $this->cleanup_generation_stage( $operation, $prepared['collision'] ) ) {
			return false;
		}
		if ( $prepared['displaced'] !== null ) {
			if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->cleanup_displaced_operation_record( $operation, $prepared['displaced'], $prepared['authority'], $prepared['cleanup_recovery'] ) ) {
				return false;
			}
		}
		if ( $prepared['intermediate'] !== null ) {
			if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->publish_operation_journal( $operation, $prepared['intermediate'] ) ) {
				return false;
			}
		}
		if ( $prepared['cleanup_pending'] ) {
			if ( ! $this->begin_prefix_confirmed( $operation ) ) {
				return false;
			}
			$cleaned = $prepared['cleanup_all'] ? $this->cleanup_owned_operation_stages( $operation, true ) : $this->cleanup_operation_record_artifacts( $operation, $operation['journal']['current'], true );
			if ( ! $cleaned || ! $this->begin_prefix_confirmed( $operation ) || ! $this->publish_operation_journal( $operation, $prepared['acknowledgement'] ) ) {
				return false;
			}
		}
		if ( $prepared['retired'] ) {
			unset( $operation['selected_recovery_predecessor'] );
		}
		if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->publish_operation_journal( $operation, $prepared['successor'] ) ) { return false; }
		if ( isset( $operation['invocation'] ) && ! $this->revalidate_contract_invocation( $operation['invocation'], 'after_begin' ) ) {
			$operation['begin_error_code'] = 'iw_run_state_conflict';
			$operation['begin_failure_result'] = $this->contract_begin_refusal( $operation, $context, 'iw_run_state_conflict' );
			return false;
		}
		return true;
	}

	/** Build the exact successful begin publications before authorizing cleanup. */
	private function prepare_begin_operation( &$operation, $journal, $type, $context, $groups ) {
		$inheritance = $this->select_inheritable_pre_operation( $journal, $operation['attachment_id'] );
		if ( $inheritance['overflow'] ) {
			$operation['begin_error_code'] = 'journal_budget_exceeded';
			return false;
		}
		$prepared = [ 'original' => $journal, 'inheritance' => $inheritance, 'collision' => null, 'displaced' => null, 'authority' => null, 'cleanup_recovery' => false, 'intermediate' => null, 'cleanup_pending' => false, 'cleanup_all' => false, 'acknowledgement' => null, 'retired' => false ];
		$current = isset( $journal['current'] ) ? $journal['current'] : null;
		if ( is_array( $current ) && ! $this->operation_is_terminal( $current ) && ! empty( $current['generation_stage']['source_collision'] ) ) {
			$prepared['collision'] = $current;
		}
		$selected = isset( $operation['selected_recovery_predecessor'] ) && is_array( $operation['selected_recovery_predecessor'] ) ? $operation['selected_recovery_predecessor'] : $this->select_operation_recovery_predecessor( $journal );
		$now = time();
		if ( ! empty( $journal['current'] ) && ( ! $this->operation_is_terminal( $journal['current'] ) || in_array( $journal['current']['state'], [ 'partial', 'interrupted' ], true ) || $this->record_has_main_recovery( $journal['current'] ) ) ) {
			$current = $journal['current'];
			$current_is_selected = is_array( $selected ) && isset( $selected['token'] ) && $selected['token'] === $current['token'];
			if ( is_array( $selected ) && ! $current_is_selected ) {
				$prepared['displaced'] = $current;
				$prepared['authority'] = $selected;
				$prepared['cleanup_recovery'] = true;
				$journal = $this->displaced_interrupted_journal( $journal, $selected, $now );
				$prepared['intermediate'] = $journal;
				$journal['history'][] = $this->compact_operation_record( $journal['current'], $operation['attachment_id'], $this->manifest_marker_outstanding( $journal, $operation['attachment_id'] ) );
				$journal['history'] = array_slice( $journal['history'], -2 );
			} else {
				if ( ! empty( $journal['recoverable_predecessor']['token'] ) && $journal['recoverable_predecessor']['token'] !== $current['token'] ) {
					$prepared['displaced'] = $journal['recoverable_predecessor'];
					$prepared['authority'] = $selected;
					$prepared['cleanup_recovery'] = $current_is_selected;
				}
				$current['state'] = 'interrupted';
				$current['ended_at'] = $now;
				$journal['current'] = $current;
				$journal['recoverable_predecessor'] = $current;
			}
		} elseif ( ! empty( $journal['current'] ) ) {
			if ( ! empty( $journal['current']['cleanup_pending'] ) ) {
				$prepared['cleanup_pending'] = true;
				$prepared['cleanup_all'] = $journal['current']['state'] === 'complete' && ! empty( $journal['current']['metadata_committed'] );
				$shadow = $operation;
				$shadow['journal'] = $journal;
				$journal = $this->operation_state_candidate( $shadow, $journal['current']['state'], [ 'cleanup_pending' => false ] );
				$prepared['acknowledgement'] = $journal;
			}
			if ( $journal['current']['state'] === 'complete' && ! empty( $journal['current']['metadata_committed'] ) ) {
				$journal = $this->retire_recoverable_predecessor( $journal );
				$selected = null;
				$prepared['retired'] = true;
				foreach ( $journal['current']['targets'] as $index => $target ) {
					unset( $target['stage'], $target['recovery'] );
					$journal['current']['targets'][ $index ] = $target;
				}
				unset( $journal['current']['temporary_recovery_restore'] );
			}
			$journal['history'][] = $this->compact_operation_record( $journal['current'], $operation['attachment_id'], $this->manifest_marker_outstanding( $journal, $operation['attachment_id'] ) );
			$journal['history'] = array_slice( $journal['history'], -2 );
		}
		if ( is_array( $selected ) && ( empty( $journal['recoverable_predecessor']['token'] ) || $journal['recoverable_predecessor']['token'] !== $selected['token'] ) ) {
			$journal['recoverable_predecessor'] = $selected;
		}
		$prepared['successor'] = $this->successor_operation_journal( $journal, $operation, $type, $context, $groups, $inheritance, $now, $now );
		if ( ! empty( $operation['name_stable_budget'] ) ) {
			foreach ( [ $prepared['intermediate'], $prepared['acknowledgement'], $prepared['successor'] ] as $candidate ) {
				if ( $candidate !== null && ( ! $this->is_valid_operation_journal( $candidate ) || strlen( maybe_serialize( $candidate ) ) > self::NAME_STABILITY_JOURNAL_BUDGET ) ) {
					$operation['begin_error_code'] = 'journal_budget_exceeded';
					return false;
				}
			}
		}
		return $prepared;
	}

	/** The lease alone does not authorize cleanup after a callback replaces the journal. */
	private function begin_prefix_confirmed( &$operation ) {
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		wp_cache_delete( $operation['attachment_id'], 'post_meta' );
		$actual = $this->read_operation_journal( $operation['attachment_id'] );
		return $actual !== false && maybe_serialize( $actual ) === maybe_serialize( $operation['journal'] );
	}

	/** Construct the publication allowed only after displaced-record cleanup succeeds. */
	private function displaced_interrupted_journal( $journal, $selected_predecessor, $ended_at ) {
		$journal['current']['state'] = 'interrupted';
		$journal['current']['ended_at'] = $ended_at;
		$journal['current']['cleanup_pending'] = false;
		$journal['recoverable_predecessor'] = $selected_predecessor;
		return $journal;
	}

	/** Construct the successor without selecting a predecessor or granting cleanup authority. */
	private function successor_operation_journal( $journal, $operation, $type, $context, $groups, $inheritance, $started_at, $heartbeat_at ) {
		$targets = [];
		foreach ( $groups as $group ) {
			$targets[] = [ 'target' => $group['target'], 'root' => 'uploads', 'aliases' => $group['aliases'], 'intent' => 'watermark-output', 'state' => 'pending' ];
		}
		$journal['current'] = [
			'token'       => $operation['token'],
			'type'        => $type,
			'context'     => $context,
			'state'       => 'preparing',
			'started_at'  => $started_at,
			'heartbeat_at' => $heartbeat_at,
			'site_id'     => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
			'intended_flag' => isset( $operation['intended_flag'] ) ? $operation['intended_flag'] : ( $type === 'apply' ? 1 : 0 ),
			'metadata_committed' => false,
			'targets'     => $targets,
		];
		if ( isset( $operation['invocation'] ) ) {
			$identity = $this->normalize_operation_identity( $operation['invocation']->identity );
			// An invalid invocation must fail journal validation, never lose its identity silently.
			$journal['current']['run_identity'] = $identity === [] ? false : $identity;
		}
		if ( is_array( $inheritance['pre'] ) ) {
			$journal['current']['pre_operation'] = $inheritance['pre'];
		}
		if ( isset( $operation['main_recovery_seed'] ) ) { $journal['current']['main_recovery'] = $operation['main_recovery_seed']; }
		return $journal;
	}

	/** Publish only this begun attempt's refusal, without retiring predecessor artifacts. */
	private function contract_begin_refusal( &$operation, $context, $code ) {
		$token = $operation['token'];
		if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->set_operation_state( $operation, 'failed', [ 'code' => $code, 'counts' => [ 'processed' => 0, 'failed' => 0 ] ] ) || ! $this->record_last_operation( $operation['attachment_id'], 'error', $code, '', $context, [], [], [], 'failed', $operation ) ) {
			return $this->operation_result( 'interrupted', $token, 'journal_write_failed', '', true );
		}
		return $this->operation_result( 'failed', $token, $code, '', true );
	}

	/** @return array Failure result for begin_operation(), including a published refusal. */
	private function begin_failure_result( $operation, $token ) {
		if ( isset( $operation['begin_failure_result'] ) ) {
			return $operation['begin_failure_result'];
		}
		if ( isset( $operation['begin_error_code'] ) && $operation['begin_error_code'] === 'iw_run_state_conflict' ) {
			return $this->operation_result( 'failed', $token, 'iw_run_state_conflict', '', true );
		}
		if ( isset( $operation['begin_error_code'] ) && $operation['begin_error_code'] === 'journal_budget_exceeded' ) {
			return $this->operation_result( 'failed', $token, 'journal_budget_exceeded', __( 'Earlier interrupted watermark work on this image is unresolved and its recovery record cannot be extended safely. Review the image before retrying. No image was changed.', 'image-watermark' ), false );
		}
		if ( isset( $operation['begin_error_code'] ) && $operation['begin_error_code'] === 'predecessor_uncertain' ) {
			return $this->operation_result( 'failed', $token, 'predecessor_uncertain', __( 'The interrupted image operation needs a verified clean source before it can be retried.', 'image-watermark' ), true );
		}
		if ( isset( $operation['begin_error_code'] ) && $operation['begin_error_code'] === 'handoff_pending' ) {
			return $this->handoff_pending_result( $token );
		}
		return $this->operation_result( 'failed', $token, 'journal_invalid', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true );
	}

	/** @return array Retryable refusal while an automatic apply's handoff cannot yet be settled. */
	private function handoff_pending_result( $token ) {
		return $this->operation_result( 'failed', $token, 'handoff_pending', __( 'The last automatic watermark is still being confirmed. No image was changed; please retry shortly.', 'image-watermark' ), true );
	}

	/** Prepare the exact pending-handoff publication before any associated effect. */
	private function prepare_pending_handoff( &$operation, $journal, $interrupt = false ) {
		$current = isset( $journal['current'] ) ? $journal['current'] : null;
		$retained = isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
		$key = $this->is_pending_handoff( $current ) ? 'current' : ( $this->is_pending_handoff( $retained ) ? 'recoverable_predecessor' : '' );
		if ( $key === '' ) { return null; }
		$record = $journal[ $key ];
		$manifest = ! $interrupt && $key === 'current' ? $this->verified_manifest_from_evidence( $operation['attachment_id'], $record ) : false;
		$processed = [];
		if ( $manifest !== false ) {
			foreach ( $record['targets'] as $target ) {
				if ( isset( $target['intent'], $target['state'], $target['aliases'] ) && $target['intent'] === 'watermark-output' && $target['state'] === 'promoted' && is_array( $target['aliases'] ) ) {
					$processed = array_merge( $processed, $target['aliases'] );
				}
			}
			$shadow = $operation;
			$shadow['journal'] = $journal;
			$details = [ 'metadata_handoff' => 'verified', 'metadata_committed' => true, 'code' => 'watermarked', 'counts' => [ 'processed' => count( $processed ), 'failed' => 0 ] ];
			// Completion must retain discoverable cleanup authority if its checked
			// acknowledgement cannot be published or cleanup cannot start.
			if ( ! empty( $operation['name_stable_budget'] ) ) { $details['cleanup_pending'] = true; }
			$candidate = $this->operation_state_candidate( $shadow, 'complete', $details );
		} else {
			$candidate = $journal;
			$candidate[ $key ]['state'] = 'interrupted';
			$candidate[ $key ]['code'] = 'handoff_unverified';
			$candidate[ $key ]['ended_at'] = time();
		}
		if ( ! $this->is_valid_operation_journal( $candidate ) || ( ! empty( $operation['name_stable_budget'] ) && strlen( maybe_serialize( $candidate ) ) > self::NAME_STABILITY_JOURNAL_BUDGET ) ) {
			$operation['begin_error_code'] = 'journal_budget_exceeded';
			return false;
		}
		return [ 'original' => $journal, 'key' => $key, 'manifest' => $manifest, 'processed' => $processed, 'candidate' => $candidate ];
	}

	/** Recheck the lease, exact prefix and evidence before consuming a preparation. */
	private function prepared_handoff_confirmed( &$operation, $prepared ) {
		if ( ! $this->begin_prefix_confirmed( $operation ) ) { return false; }
		if ( $prepared['key'] !== 'current' ) { return true; }
		return $this->verified_manifest_from_evidence( $operation['attachment_id'], $prepared['original']['current'] ) === $prepared['manifest'];
	}

	/**
	 * Settle an automatic apply whose metadata handoff is still pending, before
	 * anything else reads the journal. When the current record is that
	 * handoff, it is completed if the stored metadata and files verify. Any
	 * other pending handoff (unverified, or a pending predecessor) is marked
	 * interrupted with `handoff_unverified`, but only after a client-side claim
	 * that could still register files has been settled to terminal; when that
	 * cannot be done, nothing changes and the caller refuses with
	 * `handoff_pending`, as it does when the lease was lost. A settled claim
	 * stays terminal even when the journal
	 * write that follows fails: the record then stays pending, the caller
	 * refuses with `handoff_pending`, and the next touch interrupts it.
	 *
	 * @param array $operation Operation holding the lease.
	 * @param array $journal Journal read under the lease.
	 * @return string none, complete, interrupted, or handoff_pending.
	 */
	private function resolve_pending_handoff( &$operation, $journal ) {
		$current = ! empty( $journal['current'] ) ? $journal['current'] : null;
		$retained = ! empty( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
		$key = $this->is_pending_handoff( $current ) ? 'current' : ( $this->is_pending_handoff( $retained ) ? 'recoverable_predecessor' : '' );
		if ( $key === '' ) {
			return 'none';
		}
		$attachment_id = $operation['attachment_id'];
		$prepared = isset( $operation['prepared_handoff'] ) ? $operation['prepared_handoff'] : $this->prepare_pending_handoff( $operation, $journal );
		unset( $operation['prepared_handoff'] );
		if ( $prepared === false ) { return 'begin_refused'; }
		if ( ! is_array( $prepared ) || maybe_serialize( $prepared['original'] ) !== maybe_serialize( $journal ) ) { return 'handoff_pending'; }
		$operation['journal'] = $journal;
		if ( ! $this->prepared_handoff_confirmed( $operation, $prepared ) ) { return 'handoff_pending'; }
		if ( $key === 'current' && $prepared['manifest'] !== false ) {
			$completion = $this->complete_metadata_handoff( $operation, $prepared );
			if ( $completion === 'lease_lost' || $completion === 'pending' ) {
				return 'handoff_pending';
			}
			if ( $completion === 'verified' ) {
				$processed = [];
				foreach ( $current['targets'] as $target ) {
					if ( isset( $target['intent'], $target['state'], $target['aliases'] ) && $target['intent'] === 'watermark-output' && $target['state'] === 'promoted' && is_array( $target['aliases'] ) ) {
						$processed = array_merge( $processed, $target['aliases'] );
					}
				}
				$this->operation_result( 'complete', $current['token'], 'watermarked', __( 'Watermark applied.', 'image-watermark' ), false, [ 'processed' => $processed, 'skipped' => [], 'failed' => [] ] );
				return 'complete';
			}
			// A failed verified publication can only interrupt after separately
			// admitting that actual continuation on the confirmed pending prefix.
			$prepared = $this->prepare_pending_handoff( $operation, $journal, true );
			if ( $prepared === false ) { return 'handoff_pending'; }
		}
		// Do not settle a claim against an altered pending prefix.
		if ( ! $this->begin_prefix_confirmed( $operation ) ) {
			return 'handoff_pending';
		}
		$row = $this->read_client_side_claim_row( $attachment_id );
		if ( $row === false ) {
			return 'handoff_pending';
		}
		if ( is_array( $row ) && in_array( $row['claim']['state'], [ 'pending', 'rendered' ], true ) && $this->transition_client_side_claim( $attachment_id, $row, [ 'state' => 'terminal', 'code' => 'handoff_unverified' ] ) === false ) {
			return 'handoff_pending';
		}
		$record = $prepared['candidate'][ $key ];
		if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->publish_operation_journal( $operation, $prepared['candidate'] ) ) {
			return 'handoff_pending';
		}
		$this->record_last_operation( $attachment_id, 'error', 'handoff_unverified', __( 'The automatic watermark could not be confirmed against the saved image metadata. Reapply or remove the watermark.', 'image-watermark' ), isset( $record['context'] ) ? $record['context'] : 'auto-apply', [], [], [], 'interrupted', $operation, $record );

		return 'interrupted';
	}

	/**
	 * The outstanding manifest-loss marker, if any: the attachment is flagged,
	 * holds no valid manifest, and a record of the journal carries
	 * `handoff_unverified` or `manifest_unverified` (the first wins).
	 *
	 * @param array $journal Journal value.
	 * @param int   $attachment_id Attachment ID.
	 * @return string Marker code, or ''.
	 */
	private function manifest_marker_outstanding( $journal, $attachment_id ) {
		if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) !== 1 || $this->is_valid_manifest( get_post_meta( $attachment_id, self::MANIFEST_META_KEY, true ) ) ) {
			return '';
		}

		return $this->manifest_marker_in( $journal );
	}

	/** @return string The journal's manifest-loss marker code (`handoff_unverified` first), or ''. */
	private function manifest_marker_in( $journal ) {
		$records = is_array( $journal ) ? array_merge( [ isset( $journal['current'] ) ? $journal['current'] : null, isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ], isset( $journal['history'] ) && is_array( $journal['history'] ) ? $journal['history'] : [] ) : [];
		$found = '';
		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['code'] ) && in_array( $record['code'], [ 'handoff_unverified', 'manifest_unverified' ], true ) ) {
				if ( $record['code'] === 'handoff_unverified' ) {
					return 'handoff_unverified';
				}
				$found = 'manifest_unverified';
			}
		}

		return $found;
	}

	/**
	 * Compact a record for history. Compaction drops its manifest evidence, so
	 * a completed record whose manifest was never verifiably written keeps the
	 * code `manifest_unverified`: the attachment is then reported untrusted,
	 * never legacy. While that marker is outstanding, a later record that is
	 * not itself a completed apply or regenerate carries it on, so the
	 * chronological two-entry history never drops it.
	 *
	 * @param array $record Full record.
	 * @param int   $attachment_id Attachment ID, to check its stored manifest.
	 * @param string $inherit Outstanding marker code, or ''.
	 * @return array
	 */
	private function compact_operation_record( $record, $attachment_id = 0, $inherit = '' ) {
		$code = isset( $record['code'] ) ? $record['code'] : '';
		$completed = isset( $record['state'], $record['type'] ) && $record['state'] === 'complete' && in_array( $record['type'], [ 'apply', 'regenerate' ], true );
		if ( $attachment_id > 0 && isset( $record['manifest_evidence'], $record['state'] ) && $record['state'] === 'complete' ) {
			$stored = get_post_meta( $attachment_id, self::MANIFEST_META_KEY, true );
			if ( ! $this->is_valid_manifest( $stored ) || $stored['token'] !== $record['token'] ) {
				$code = 'manifest_unverified';
			}
		} elseif ( $inherit !== '' && ! $completed && ! in_array( $code, [ 'handoff_unverified', 'manifest_unverified' ], true ) ) {
			$code = $inherit;
		}
		return [
			'token'      => isset( $record['token'] ) ? $record['token'] : '',
			'type'       => isset( $record['type'] ) ? $record['type'] : '',
			'state'      => isset( $record['state'] ) ? $record['state'] : 'failed',
			'started_at' => isset( $record['started_at'] ) ? $record['started_at'] : 0,
			'ended_at'   => isset( $record['ended_at'] ) ? $record['ended_at'] : time(),
			'code'       => $code,
			'counts'     => isset( $record['counts'] ) ? $record['counts'] : [],
			'targets'    => isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [],
		];
	}

	/** Construct the exact journal value to publish, including terminal times. */
	private function operation_state_candidate( $operation, $state, $details = [] ) {
		if ( empty( $operation['journal']['current'] ) ) {
			return false;
		}
		if ( array_key_exists( 'run_identity', (array) $details ) && ( ! array_key_exists( 'run_identity', $operation['journal']['current'] ) || $details['run_identity'] !== $operation['journal']['current']['run_identity'] ) ) {
			return false;
		}
		$candidate = $operation['journal'];
		$candidate['current'] = array_merge( $candidate['current'], (array) $details );
		$candidate['current']['state'] = $state;
		$candidate['current']['heartbeat_at'] = time();
		if ( $this->operation_is_terminal( $candidate['current'] ) ) {
			$candidate['current']['ended_at'] = time();
		}
		return $candidate;
	}

	/** @param array $operation Current operation. @return bool */
	private function set_operation_state( &$operation, $state, $details = [] ) {
		$candidate = $this->operation_state_candidate( $operation, $state, $details );
		return $candidate !== false && $this->publish_operation_journal( $operation, $candidate );
	}

	/** @param array $operation Current operation. @param array $target Target record. @return bool */
	private function set_operation_target( &$operation, $target ) {
		if ( empty( $operation['journal']['current']['targets'] ) ) {
			return false;
		}
		$targets = $operation['journal']['current']['targets'];
		foreach ( $targets as $index => $current ) {
			if ( $current['target'] === $target['target'] ) {
				$targets[ $index ] = array_merge( $current, $target );
				return $this->set_operation_state( $operation, 'staging', [ 'targets' => $targets ] );
			}
		}
		return false;
	}

	/** @return string */
	private function operation_stage_path( $target, $token, $identity ) {
		$extension = pathinfo( $target, PATHINFO_EXTENSION );
		return dirname( $target ) . DIRECTORY_SEPARATOR . '.iw-stage-' . $token . '-' . substr( hash( 'sha256', $identity ), 0, 16 ) . ( $extension ? '.' . $extension : '.tmp' );
	}

	/** @return string|false */
	private function operation_planned_stage_relative_path( $stage, $upload_dir, $stage_only = true ) {
		if ( $stage_only && strpos( basename( $stage ), '.iw-stage-' ) !== 0 ) {
			return false;
		}
		return $this->operation_root_relative_path( $stage, $upload_dir['basedir'] );
	}

	/** @return string */
	protected function get_backup_root_dir() {
		return IMAGE_WATERMARK_BACKUP_DIR;
	}

	/**
	 * Resolve one WordPress-style uploads-relative path below the configured backup
	 * root. The returned path is canonical at every existing boundary; callers must
	 * re-resolve immediately before destructive work.
	 *
	 * @return array|false
	 */
	public function resolve_backup_path( $relative, $create_directories = false, $context = '', $attachment_id = 0 ) {
		return $this->resolve_operation_root_path( $this->get_backup_root_dir(), $relative, $create_directories, $context, $attachment_id );
	}

	/**
	 * Describe a read-only backup lookup without turning a failed resolution into
	 * a usable path. Failure details belong to this call, never shared handler state.
	 *
	 * @return array{path:string|false,code:string,message:string}
	 */
	public function describe_backup_path( $relative ) {
		$failure = '';
		$resolved = $this->resolve_operation_root_path( $this->get_backup_root_dir(), $relative, false, '', 0, $failure );
		$code = '';
		$message = '';
		if ( $resolved === false ) {
			if ( $failure === 'missing_directory' ) {
				$code = 'backup_missing';
			} elseif ( $failure === 'invalid_path' ) {
				$code = 'invalid_backup_path';
				$message = __( 'The attachment backup path is invalid.', 'image-watermark' );
			} else {
				$code = 'backup_path_unavailable';
				$message = __( 'The attachment backup path could not be verified. Check access to the configured backup location.', 'image-watermark' );
			}
		}
		return [ 'path' => $resolved === false ? false : $resolved['path'], 'code' => $code, 'message' => $message ];
	}

	/** Current-site upload information, without directory creation during preview. */
	private function operation_upload_dir( $read_only = false ) {
		return $read_only || $this->preview_lookup_read_only ? wp_upload_dir( null, false ) : wp_upload_dir();
	}

	/** @return array|false */
	private function resolve_upload_artifact_path( $relative, $create_directories = false, $context = '', $attachment_id = 0, &$failure = null, $read_only = false ) {
		$read_only = $read_only || $this->preview_lookup_read_only;
		$upload_dir = $this->operation_upload_dir( $read_only );
		return $this->resolve_operation_root_path( $upload_dir['basedir'], $relative, $read_only ? false : $create_directories, $context, $attachment_id, $failure );
	}

	/** @return string|false */
	private function normalize_operation_relative_path( $relative ) {
		if ( ! is_string( $relative ) || $relative === '' || strpos( $relative, "\0" ) !== false ) {
			return false;
		}
		$relative = str_replace( '\\', '/', $relative );
		if ( strpos( $relative, '/' ) === 0 || strpos( $relative, '//' ) === 0 || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $relative ) || preg_match( '/^[a-z]:/i', $relative ) ) {
			return false;
		}
		$segments = explode( '/', $relative );
		foreach ( $segments as $segment ) {
			if ( $segment === '' || $segment === '.' || $segment === '..' || strpos( $segment, ':' ) !== false || strpos( $segment, "\0" ) !== false ) {
				return false;
			}
		}
		return implode( '/', $segments );
	}

	/** @return bool */
	private function operation_path_is_within_root( $path, $root, $allow_root = false ) {
		$path = wp_normalize_path( $path );
		$root = rtrim( wp_normalize_path( $root ), '/' );
		$compare_path = DIRECTORY_SEPARATOR === '\\' ? strtolower( $path ) : $path;
		$compare_root = DIRECTORY_SEPARATOR === '\\' ? strtolower( $root ) : $root;
		return ( $allow_root && $compare_path === $compare_root ) || strpos( $compare_path, $compare_root . '/' ) === 0;
	}

	/** @return string|false */
	private function operation_root_relative_path( $path, $root ) {
		if ( ! is_string( $path ) || strpos( $path, "\0" ) !== false || strpos( str_replace( '\\', '/', $path ), '../' ) !== false || basename( $path ) === '' || basename( $path ) === '.' || basename( $path ) === '..' ) {
			return false;
		}
		$canonical_root = realpath( $root );
		$canonical_directory = realpath( dirname( $path ) );
		if ( $canonical_root === false || $canonical_directory === false || ! $this->operation_path_is_within_root( $canonical_directory, $canonical_root, true ) ) {
			return false;
		}
		$relative_directory = rtrim( wp_normalize_path( $canonical_directory ), '/' ) === rtrim( wp_normalize_path( $canonical_root ), '/' ) ? '' : substr( wp_normalize_path( $canonical_directory ), strlen( rtrim( wp_normalize_path( $canonical_root ), '/' ) ) + 1 );
		return $this->normalize_operation_relative_path( ( $relative_directory === '' ? '' : $relative_directory . '/' ) . basename( $path ) );
	}

	/** @return array|false */
	private function resolve_operation_root_path( $root, $relative, $create_directories = false, $context = '', $attachment_id = 0, &$failure = null ) {
		$failure = 'invalid_path';
		$relative = $this->normalize_operation_relative_path( $relative );
		if ( $relative === false || ! is_string( $root ) || $root === '' || strpos( $root, "\0" ) !== false ) {
			return false;
		}

		$failure = 'unavailable';
		$root = wp_normalize_path( $root );
		if ( ! is_dir( $root ) ) {
			if ( ! $create_directories ) {
				$failure = $this->operation_directory_failure( $root );
				return false;
			}
			$missing = [];
			$ancestor = $root;
			while ( ! is_dir( $ancestor ) ) {
				$parent = dirname( $ancestor );
				if ( $parent === $ancestor || basename( $ancestor ) === '' || basename( $ancestor ) === '.' || basename( $ancestor ) === '..' ) {
					return false;
				}
				array_unshift( $missing, basename( $ancestor ) );
				$ancestor = $parent;
			}
			$canonical_ancestor = realpath( $ancestor );
			if ( $canonical_ancestor === false ) {
				return false;
			}
			$current = $canonical_ancestor;
			foreach ( $missing as $segment ) {
				$current .= DIRECTORY_SEPARATOR . $segment;
				if ( ! $this->make_directory( $current, $context, $attachment_id ) ) {
					return false;
				}
				$resolved = realpath( $current );
				if ( $resolved === false || ! $this->operation_path_is_within_root( $resolved, $canonical_ancestor, true ) ) {
					$failure = $resolved === false ? 'unavailable' : 'invalid_path';
					return false;
				}
				$current = $resolved;
			}
		}

		$canonical_root = realpath( $root );
		if ( $canonical_root === false || ! is_dir( $canonical_root ) ) {
			return false;
		}
		$parts = explode( '/', $relative );
		$filename = array_pop( $parts );
		$directory = $canonical_root;
		foreach ( $parts as $segment ) {
			$candidate = $directory . DIRECTORY_SEPARATOR . $segment;
			if ( ! is_dir( $candidate ) ) {
				if ( ! $create_directories || ! $this->make_directory( $candidate, $context, $attachment_id ) ) {
					$failure = $this->operation_directory_failure( $candidate );
					return false;
				}
			}
			$resolved = realpath( $candidate );
			if ( $resolved === false || ! $this->operation_path_is_within_root( $resolved, $canonical_root, true ) ) {
				$failure = $resolved === false ? 'unavailable' : 'invalid_path';
				return false;
			}
			$directory = $resolved;
		}

		$path = $directory . DIRECTORY_SEPARATOR . $filename;
		if ( is_link( $path ) ) {
			$failure = 'invalid_path';
			return false;
		}
		if ( file_exists( $path ) ) {
			$resolved = realpath( $path );
			if ( $resolved === false || ! $this->operation_path_is_within_root( $resolved, $canonical_root ) ) {
				$failure = $resolved === false ? 'unavailable' : 'invalid_path';
				return false;
			}
			$path = $resolved;
		}
		$failure = '';
		return [ 'path' => $path, 'relative' => $relative, 'root' => wp_normalize_path( $canonical_root ), 'parent' => wp_normalize_path( $directory ) ];
	}

	/**
	 * A failed stat alone cannot distinguish absence from denied access. Only a
	 * readable parent listing can establish absence; links and non-directories
	 * are rejected rather than described as a missing backup folder.
	 *
	 * @return string
	 */
	private function operation_directory_failure( $directory ) {
		while ( ! is_dir( $directory ) ) {
			if ( is_link( $directory ) || file_exists( $directory ) ) {
				return 'invalid_path';
			}
			$parent = dirname( $directory );
			if ( $parent === $directory ) {
				return 'unavailable';
			}
			if ( is_dir( $parent ) ) {
				$entries = @scandir( $parent );
				if ( $entries === false ) {
					return 'unavailable';
				}
				$name = basename( $directory );
				foreach ( $entries as $entry ) {
					if ( DIRECTORY_SEPARATOR === '\\' ? strcasecmp( $entry, $name ) === 0 : $entry === $name ) {
						return 'unavailable';
					}
				}
				return 'missing_directory';
			}
			$directory = $parent;
		}
		return 'unavailable';
	}

	/** @return string|false */
	protected function operation_backup_relative_path( $path ) {
		$relative = $this->operation_root_relative_path( $path, $this->get_backup_root_dir() );
		$resolved = $relative === false ? false : $this->resolve_backup_path( $relative );
		return $resolved === false ? false : $resolved['relative'];
	}

	/** @return string|false */
	protected function operation_backup_stage_relative_path( $stage ) {
		if ( ! is_string( $stage ) || strpos( basename( $stage ), '.iw-stage-' ) !== 0 ) {
			return false;
		}
		return $this->operation_backup_relative_path( $stage );
	}

	/** @param string $file Absolute file path. @return bool */
	private function verify_operation_file( $file ) {
		clearstatcache( true, $file );
		return is_file( $file ) && filesize( $file ) > 0 && $this->is_valid_backup_file( $file );
	}

	/** @return bool */
	private function watermark_source_is_available( $options, $upload_dir ) {
		if ( ! isset( $options['watermark_image']['type'] ) || $options['watermark_image']['type'] !== 'image' ) {
			return true;
		}
		$id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
		$metadata = wp_get_attachment_metadata( $id, true );
		return is_array( $metadata ) && ! empty( $metadata['file'] ) && is_file( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $metadata['file'] );
	}

	/** @return bool */
	private function commit_attachment_metadata( $attachment_id, $metadata ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return wp_get_attachment_metadata( $attachment_id, false ) === $metadata;
	}

	/** Read the stored value without attachment or post-meta read filters. */
	private function read_stored_regenerated_metadata( $attachment_id, &$metadata ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1",
			$attachment_id,
			'_wp_attachment_metadata'
		) );
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) {
			return false;
		}
		$metadata = $rows ? maybe_unserialize( $rows[0]->meta_value ) : false;
		return true;
	}

	/** @return bool */
	private function commit_regenerated_metadata( $attachment_id, &$metadata ) {
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return false;
		}
		if ( ! $this->read_stored_regenerated_metadata( $attachment_id, $before ) ) {
			return false;
		}
		$updated = wp_update_attachment_metadata( $attachment_id, $metadata );
		if ( ! $this->read_stored_regenerated_metadata( $attachment_id, $committed ) ) {
			return false;
		}
		// A false update result is acceptable only when the prior durable value was
		// already the intended effective metadata. A stale reread never substitutes
		// for the refused write.
		if ( ( ! $updated && ! $this->regenerated_metadata_commit_is_effective( $before, $metadata, $before ) ) || ! $this->regenerated_metadata_commit_is_effective( $before, $metadata, $committed ) ) {
			return false;
		}
		$metadata = $committed;
		return true;
	}

	/**
	 * Verify the durable metadata boundary without rejecting benign metadata filters.
	 *
	 * The attachment file and each intended derivative filename are the semantic
	 * references owned by this operation. Additional descriptive fields may be
	 * filtered by WordPress or extensions, but a stale reference removed by the
	 * intended metadata must not survive the reread.
	 *
	 * @return bool
	 */
	private function regenerated_metadata_commit_is_effective( $before, $intended, $committed ) {
		if ( ! is_array( $intended ) || ! is_array( $committed ) || empty( $intended['file'] ) || empty( $committed['file'] ) || $intended['file'] !== $committed['file'] ) {
			return false;
		}
		$intended_sizes = isset( $intended['sizes'] ) && is_array( $intended['sizes'] ) ? $intended['sizes'] : [];
		$committed_sizes = isset( $committed['sizes'] ) && is_array( $committed['sizes'] ) ? $committed['sizes'] : [];
		foreach ( $intended_sizes as $name => $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) || ! isset( $committed_sizes[ $name ] ) || ! is_array( $committed_sizes[ $name ] ) || empty( $committed_sizes[ $name ]['file'] ) || $size['file'] !== $committed_sizes[ $name ]['file'] ) {
				return false;
			}
		}
		$before_sizes = is_array( $before ) && isset( $before['sizes'] ) && is_array( $before['sizes'] ) ? $before['sizes'] : [];
		foreach ( $before_sizes as $name => $size ) {
			if ( ! isset( $intended_sizes[ $name ] ) && isset( $committed_sizes[ $name ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool */
	private function set_regenerated_metadata_commit_state( &$operation, $metadata, $state ) {
		return $this->set_operation_state( $operation, 'metadata-commit', [
			'regenerated_metadata_commit' => [
				'state' => $state,
				'hash'  => is_array( $metadata ) ? hash( 'sha256', maybe_serialize( $metadata ) ) : '',
			],
		] );
	}

	/**
	 * Commit the watermark flag only while the caller still holds its exact
	 * fence, then reread it. This is intentionally separate from file promotion.
	 *
	 * @return bool
	 */
	private function commit_watermarked_flag( &$operation, $value ) {
		if ( ! $this->renew_operation_lock( $operation ) || $this->should_inject_operation_fault( 'metadata_commit', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) || ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		// This fences the boundary above, not arbitrary metadata hooks that run
		// inside update_post_meta() after this check.
		update_post_meta( $operation['attachment_id'], $this->plugin->get_watermarked_meta_key(), (int) $value );
		return (int) get_post_meta( $operation['attachment_id'], $this->plugin->get_watermarked_meta_key(), true ) === (int) $value;
	}

	/** Read current persisted settings and registered sizes without changing either. */
	public function runner_fingerprints( &$effective_options = null ) {
		global $wpdb;
		$effective_options = null;
		try {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 2", 'image_watermark_options' ), ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $rows ) || count( $rows ) > 1 ) { return false; }
			$stored = $rows ? maybe_unserialize( $rows[0]['option_value'] ) : [];
			if ( ! is_array( $stored ) ) { $stored = []; }
			$defaults = $this->plugin->defaults['options'];
			$options = array_merge( $defaults, $stored );
			foreach ( [ 'watermark_image', 'image_protection', 'backup' ] as $group ) {
				$options[ $group ] = array_merge( $defaults[ $group ], isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ? $stored[ $group ] : [] );
			}
			$options = apply_filters( 'iw_watermark_options', $options );
			if ( ! is_array( $options ) || ! isset( $options['watermark_image'], $options['watermark_on'], $options['backup'] ) || ! is_array( $options['watermark_image'] ) || ! is_array( $options['watermark_on'] ) || ! is_array( $options['backup'] ) ) { return false; }
			$options = $this->normalize_rotation_options( $options );
			$image = $options['watermark_image'];
			unset( $image['review_notice'], $image['review_delay_date'] );
			$settings = [ 'watermark_image' => $image, 'watermark_on' => $options['watermark_on'], 'backup' => $options['backup'] ];
			$sizes = wp_get_registered_image_subsizes();
			if ( ! is_array( $sizes ) ) { return false; }
			$sort = static function( &$value ) use ( &$sort ) {
				if ( is_array( $value ) ) {
					$is_list = $value === [] || array_keys( $value ) === range( 0, count( $value ) - 1 );
					if ( ! $is_list ) { ksort( $value, SORT_STRING ); }
					foreach ( $value as &$child ) { if ( ! $sort( $child ) ) { return false; } }
					return true;
				}
				return $value === null || is_scalar( $value );
			};
			if ( ! $sort( $settings ) || ! $sort( $sizes ) ) { return false; }
			$settings_json = json_encode( $settings, 0 );
			$sizes_json = json_encode( $sizes, 0 );
			if ( $settings_json === false || $sizes_json === false ) { return false; }
			$effective_options = $options;
			return [ 'settings_fingerprint' => hash( 'sha256', $settings_json ), 'sizes_fingerprint' => hash( 'sha256', $sizes_json ) ];
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Fingerprint of the effective watermark settings: watermark_image without
	 * the review-notice keys, and watermark_on, with sorted keys.
	 *
	 * @param array $options Effective options.
	 * @return string SHA-256 hex.
	 */
	private function manifest_settings_fingerprint( $options ) {
		$image = isset( $options['watermark_image'] ) && is_array( $options['watermark_image'] ) ? $options['watermark_image'] : [];
		unset( $image['review_notice'], $image['review_delay_date'] );
		$settings = [
			'watermark_image' => $image,
			'watermark_on'    => isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [],
		];
		$sort = static function( &$value ) use ( &$sort ) {
			if ( is_array( $value ) ) {
				ksort( $value );
				foreach ( $value as &$child ) {
					$sort( $child );
				}
			}
		};
		$sort( $settings );

		return hash( 'sha256', (string) wp_json_encode( $settings ) );
	}

	/**
	 * Whether a manifest naming these sizes could exceed its bounds: more than
	 * MANIFEST_NAME_LIMIT selected names, or a conservative serialized bound
	 * (every bounded name as its own file at the longest path) over
	 * MANIFEST_MAX_BYTES. Checked before any live change.
	 *
	 * @param string[] $selected Selected size names.
	 * @param string[] $names Every name the manifest could hold.
	 * @param int      $longest_path Longest relative path a manifest entry could have.
	 * @return bool
	 */
	private function manifest_bound_exceeded( $selected, $names, $longest_path, $legacy_paths = [] ) {
		if ( count( array_unique( $selected ) ) > self::MANIFEST_NAME_LIMIT ) {
			return true;
		}
		if ( count( $legacy_paths ) > self::MANIFEST_NAME_LIMIT ) {
			return true;
		}
		$path = str_repeat( 'p', max( 1, (int) $longest_path ) );
		$hash = str_repeat( 'f', 64 );
		$aliases = [];
		foreach ( array_slice( array_values( array_unique( array_map( 'strval', $names ) ) ), 0, self::MANIFEST_NAME_LIMIT ) as $name ) {
			$aliases[] = [ 'names' => [ $name ], 'path' => $path, 'size' => PHP_INT_MAX, 'sha256' => $hash ];
		}
		$legacy = [];
		foreach ( $legacy_paths as $legacy_path ) {
			$legacy[] = [ 'path' => $legacy_path, 'size' => PHP_INT_MAX, 'sha256' => $hash ];
		}
		$bound = [
			'version'      => self::MANIFEST_VERSION,
			'token'        => str_repeat( 't', 64 ),
			'committed_at' => PHP_INT_MAX,
			'main'         => [ 'path' => $path, 'size' => PHP_INT_MAX, 'sha256' => $hash, 'watermarked' => true ],
			'aliases'      => $aliases,
			'legacy'       => $legacy,
			'provenance'   => [ 'file' => $path, 'size' => PHP_INT_MAX, 'sha256' => $hash ],
			'settings'     => $hash,
		];

		return strlen( serialize( $bound ) ) > self::MANIFEST_MAX_BYTES;
	}

	/**
	 * manifest_bound_exceeded() at the widest path the manifest can hold: the
	 * given paths, the main file's path, and the provenance file name.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $selected Selected size names.
	 * @param string[] $names Every name the manifest could hold.
	 * @param string[] $paths Relative paths of its possible entries.
	 * @param array    $upload_dir Upload directory.
	 * @param string[] $additional_legacy_paths Predicted clean legacy outputs.
	 * @return bool
	 */
	private function manifest_preflight_exceeded( $attachment_id, $selected, $names, $paths, $upload_dir, $additional_legacy_paths = [] ) {
		$longest = 0;
		foreach ( $paths as $path ) {
			$longest = max( $longest, strlen( (string) $path ) );
		}
		$attached = get_attached_file( $attachment_id );
		$main = is_string( $attached ) && $attached !== '' ? $this->operation_relative_path( $attached, $upload_dir ) : false;
		if ( is_string( $main ) ) {
			$longest = max( $longest, strlen( $main ) );
		}
		$provenance = get_post_meta( $attachment_id, self::PROVENANCE_META_KEY, true );
		if ( is_array( $provenance ) && isset( $provenance['file'] ) && is_string( $provenance['file'] ) ) {
			$longest = max( $longest, strlen( $provenance['file'] ) );
		}

		$legacy = [];
		$marked = [];
		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : [] as $name => $entry ) {
			if ( ! $this->is_marked_legacy_size( $name, $entry ) ) {
				continue;
			}
			$marked[ $name ] = $entry;
			if ( ! is_string( $attached ) || empty( $entry['file'] ) || ! is_string( $entry['file'] ) || wp_basename( $entry['file'] ) !== $entry['file'] ) {
				return true;
			}
			$path = dirname( $attached ) . DIRECTORY_SEPARATOR . $entry['file'];
			$relative = is_file( $path ) ? $this->operation_relative_path( $path, $upload_dir ) : false;
			if ( $relative === false ) {
				return true;
			}
			$legacy[ $relative ] = $relative;
			$longest = max( $longest, strlen( $relative ) );
		}
		foreach ( $additional_legacy_paths as $relative ) {
			if ( ! is_string( $relative ) || $this->normalize_operation_relative_path( $relative ) !== $relative ) { return true; }
			$legacy[ $relative ] = $relative;
			$longest = max( $longest, strlen( $relative ) );
		}
		return $this->legacy_size_preflight( $marked )['exceeded'] || $this->manifest_bound_exceeded( $selected, $names, $longest, array_values( $legacy ) );
	}

	/**
	 * Build the evidence of a completed apply's manifest from the promoted
	 * targets and the committed metadata: the manifest without its aliases
	 * (they are derived from the record's targets) and the metadata references
	 * it must still match. The main file's own group maps to the references'
	 * file; every other group is an alias file. Hashes are those verified at
	 * staging; an unselected main file is hashed once here.
	 *
	 * @param array $operation Current operation, its targets promoted.
	 * @param array $data Committed attachment metadata.
	 * @param array $staged_groups Promoted target groups.
	 * @param array $options Effective options.
	 * @param array $upload_dir Upload directory.
	 * @return array|false Manifest evidence, or false.
	 */
	private function build_manifest_evidence( $operation, $data, $staged_groups, $options, $upload_dir ) {
		$attachment_id = $operation['attachment_id'];
		$attached = get_attached_file( $attachment_id );
		$main_relative = is_string( $attached ) && $attached !== '' ? $this->operation_relative_path( $attached, $upload_dir ) : false;
		if ( $main_relative === false ) {
			return false;
		}
		$main = null;
		$sizes = [];
		foreach ( $staged_groups as $staged ) {
			$target = $staged['target'];
			if ( empty( $target['target'] ) || ! isset( $target['size'], $target['hash'] ) ) {
				return false;
			}
			if ( $target['target'] === $main_relative ) {
				$main = [ 'path' => $main_relative, 'size' => (int) $target['size'], 'sha256' => $target['hash'], 'watermarked' => true ];
				continue;
			}
			$names = array_values( array_map( 'strval', $target['aliases'] ) );
			foreach ( $names as $name ) {
				if ( $name !== 'full' ) {
					$sizes[ $name ] = wp_basename( $target['target'] );
				}
			}
		}
		if ( $main === null ) {
			if ( ! is_file( $attached ) ) {
				return false;
			}
			$size = filesize( $attached );
			$hash = hash_file( 'sha256', $attached );
			if ( ! is_int( $size ) || ! is_string( $hash ) ) {
				return false;
			}
			$main = [ 'path' => $main_relative, 'size' => $size, 'sha256' => $hash, 'watermarked' => false ];
		}
		$provenance = null;
		$record = get_post_meta( $attachment_id, self::PROVENANCE_META_KEY, true );
		if ( is_array( $record ) && isset( $record['file'], $record['size'], $record['sha256'] ) && is_string( $record['file'] ) && $record['file'] === wp_basename( $record['file'] ) ) {
			// Referenced only while the recorded file still has the recorded bytes.
			$original = dirname( $attached ) . DIRECTORY_SEPARATOR . $record['file'];
			if ( is_file( $original ) && filesize( $original ) === (int) $record['size'] && hash_file( 'sha256', $original ) === $record['sha256'] ) {
				$provenance = [ 'file' => $record['file'], 'size' => (int) $record['size'], 'sha256' => $record['sha256'] ];
			}
		}
		$legacy = [];
		foreach ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] as $name => $entry ) {
			if ( ! $this->is_marked_legacy_size( $name, $entry ) ) {
				continue;
			}
			if ( empty( $entry['file'] ) || ! is_string( $entry['file'] ) || wp_basename( $entry['file'] ) !== $entry['file'] ) {
				return false;
			}
			$path = dirname( $attached ) . DIRECTORY_SEPARATOR . $entry['file'];
			$relative = $this->operation_relative_path( $path, $upload_dir );
			$size = is_file( $path ) ? filesize( $path ) : false;
			$hash = is_int( $size ) ? hash_file( 'sha256', $path ) : false;
			if ( $relative === false || ! is_int( $size ) || ! is_string( $hash ) ) {
				return false;
			}
			$legacy[ $relative ] = [ 'path' => $relative, 'size' => $size, 'sha256' => $hash ];
		}
		$manifest = [
			'version'      => self::MANIFEST_VERSION,
			'token'        => $operation['token'],
			'committed_at' => time(),
			'main'         => $main,
			'legacy'       => array_values( $legacy ),
			'provenance'   => $provenance,
			'settings'     => $this->manifest_settings_fingerprint( $options ),
		];
		$evidence = [ 'version' => 1, 'manifest' => $manifest, 'references' => [ 'file' => $main_relative, 'sizes' => $sizes ] ];
		// The written manifest is the evidence plus the aliases derived from this
		// record's targets; it must meet its bounds before the flag commit.
		if ( ! $this->is_valid_manifest_evidence( $evidence, $operation['token'] ) || $this->manifest_from_evidence( $evidence, $operation['journal']['current'] ) === false ) {
			return false;
		}

		return $evidence;
	}

	/**
	 * Write the manifest and verify it by a byte-equal reread.
	 *
	 * @param array $operation Current operation.
	 * @param array $manifest Manifest value.
	 * @return bool
	 */
	private function write_watermark_manifest( &$operation, $manifest ) {
		if ( ! $this->renew_operation_lock( $operation ) || $this->should_inject_operation_fault( 'manifest_write', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) || ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		// Fence the fault boundary, not metadata hooks inside update_post_meta().
		update_post_meta( $operation['attachment_id'], self::MANIFEST_META_KEY, $manifest );
		$stored = get_post_meta( $operation['attachment_id'], self::MANIFEST_META_KEY, true );

		return is_array( $stored ) && maybe_serialize( $stored ) === maybe_serialize( $manifest );
	}

	/**
	 * Describe the attachment's watermark manifest. A manifest is trusted only
	 * while the journal's current record is the completed apply or regenerate
	 * that wrote it; any later operation makes it untrusted. A flagged
	 * attachment with neither a manifest nor journal evidence is legacy.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status: string, reason: string, manifest: array|null}
	 */
	public function describe_manifest_status( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$stored = get_post_meta( $attachment_id, self::MANIFEST_META_KEY, true );
		$journal = $this->read_operation_journal( $attachment_id );
		$result = $this->manifest_status_from_values( $stored, $journal, null, null );
		if ( isset( $result['needs'] ) && $result['needs'] === 'options' ) {
			$options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
			$result = $this->manifest_status_from_values( $stored, $journal, null, $options );
		}
		if ( isset( $result['needs'] ) && $result['needs'] === 'flag' ) {
			$flagged = (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
			$result = $this->manifest_status_from_values( $stored, $journal, $flagged, null );
		}
		return $result;
	}

	/** Evaluate journal-backed manifest status from supplied values only. */
	private function manifest_status_from_values( $stored, $journal, $flagged, $options ) {
		$manifest = $this->is_valid_manifest( $stored ) ? $stored : null;
		$current = is_array( $journal ) && ! empty( $journal['current'] ) ? $journal['current'] : null;
		$completed = is_array( $current ) && $current['state'] === 'complete' && ! empty( $current['metadata_committed'] ) && isset( $current['type'] ) && in_array( $current['type'], [ 'apply', 'regenerate' ], true );
		if ( $this->is_pending_handoff( $current ) ) {
			// An automatic apply waits for its metadata to be saved and verified.
			return [ 'status' => 'pending', 'reason' => '', 'manifest' => null ];
		}
		if ( $manifest !== null && $completed && $current['token'] === $manifest['token'] ) {
			// The stored value must be exactly the manifest the record's evidence
			// derives, so an altered value with the right token is never trusted.
			$expected = isset( $current['manifest_evidence'] ) ? $this->manifest_from_evidence( $current['manifest_evidence'], $current ) : false;
			if ( $expected === false || maybe_serialize( $expected ) !== maybe_serialize( $manifest ) ) {
				return [ 'status' => 'untrusted', 'reason' => 'evidence_mismatch', 'manifest' => $manifest ];
			}
			if ( $options === null ) { return [ 'needs' => 'options' ]; }
			$status = $this->manifest_settings_fingerprint( $options ) === $manifest['settings'] ? 'trusted' : 'settings_changed';
			return [ 'status' => $status, 'reason' => '', 'manifest' => $manifest ];
		}
		if ( is_array( $current ) && $current['state'] === 'interrupted' && isset( $current['code'] ) && $current['code'] === 'handoff_unverified' ) {
			// A settled unverified handoff keeps its reason over its evidence or a
			// manifest left by a failed completion.
			return [ 'status' => 'untrusted', 'reason' => 'handoff_unverified', 'manifest' => $manifest ];
		}
		if ( $stored !== '' && $stored !== null && $stored !== false ) {
			$reason = $manifest === null ? 'manifest_invalid' : ( $journal === false ? 'journal_invalid' : 'superseded' );
			return [ 'status' => 'untrusted', 'reason' => $reason, 'manifest' => $manifest ];
		}
		if ( is_array( $current ) && isset( $current['manifest_evidence'] ) ) {
			return [ 'status' => 'untrusted', 'reason' => $completed ? 'manifest_missing' : 'incomplete', 'manifest' => null ];
		}
		if ( $flagged === null ) { return [ 'needs' => 'flag' ]; }
		if ( ! $flagged ) {
			// Nothing is watermarked, for example after a completed remove.
			return [ 'status' => 'none', 'reason' => '', 'manifest' => null ];
		}
		// Modern work whose manifest was never verifiably written: an automatic
		// handoff that did not verify, an adoption without evidence, or evidence
		// compacted away.
		$marker = $this->manifest_marker_in( $journal );
		if ( $marker !== '' ) {
			return [ 'status' => 'untrusted', 'reason' => $marker === 'handoff_unverified' ? 'handoff_unverified' : 'evidence_lost', 'manifest' => null ];
		}

		return [ 'status' => 'legacy', 'reason' => '', 'manifest' => null ];
	}

	/**
	 * Rebuild a missing or differing manifest from the evidence the completed
	 * record journaled before its flag commit, never by hashing today's files
	 * to invent their earlier state. Under a fresh lease, the current record
	 * must be the completed record with evidence, the stored metadata must
	 * still reference the evidence files, every evidence file must still have
	 * its recorded size and hash, and no client-side finalize claim may remain
	 * unconsumed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string rebuilt, current, not_applicable, busy, evidence_mismatch,
	 *                handoff_unverified (a claim is unconsumed or unreadable), or
	 *                write_failed.
	 */
	public function rebuild_manifest_from_evidence( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$token = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return 'busy';
		}
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock, 'time_origin' => microtime( true ) ];
		try {
			$journal = $this->read_operation_journal( $attachment_id );
			$current = is_array( $journal ) && ! empty( $journal['current'] ) ? $journal['current'] : null;
			if ( ! is_array( $current ) || $current['state'] !== 'complete' || empty( $current['metadata_committed'] ) || ! isset( $current['manifest_evidence'] ) || ( isset( $current['metadata_handoff'] ) && $current['metadata_handoff'] !== 'verified' ) ) {
				return 'not_applicable';
			}
			$manifest = $this->manifest_from_evidence( $current['manifest_evidence'], $current );
			if ( $manifest === false ) {
				return 'evidence_mismatch';
			}
			$stored = get_post_meta( $attachment_id, self::MANIFEST_META_KEY, true );
			if ( is_array( $stored ) && maybe_serialize( $stored ) === maybe_serialize( $manifest ) ) {
				return 'current';
			}
			// A client-side finalize claim that could still render or register files
			// (pending or rendered), or one that cannot be read, is not consumed.
			$row = $this->read_client_side_claim_row( $attachment_id );
			if ( $row === false || ( is_array( $row ) && in_array( $row['claim']['state'], [ 'pending', 'rendered' ], true ) ) ) {
				return 'handoff_unverified';
			}
			if ( $this->verified_manifest_from_evidence( $attachment_id, $current ) === false ) {
				return 'evidence_mismatch';
			}

			return $this->write_watermark_manifest( $operation, $manifest ) ? 'rebuilt' : 'write_failed';
		} finally {
			$this->release_operation_lock( $operation );
		}
	}

	/**
	 * The manifest a record's evidence derives, once the stored metadata still
	 * references the evidence files (attached file, metadata file, and every
	 * covered size) and every evidence file still has its recorded size and
	 * hash. Reads stored values only, never a write notification.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $record Journal record that holds the evidence.
	 * @return array|false
	 */
	private function verified_manifest_from_evidence( $attachment_id, $record ) {
		if ( ! isset( $record['manifest_evidence'] ) ) {
			return false;
		}
		$evidence = $record['manifest_evidence'];
		$manifest = $this->manifest_from_evidence( $evidence, $record );
		if ( $manifest === false ) {
			return false;
		}
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		// The evidence names the main file canonically; the stored values may
		// spell its directory differently and still name the same file.
		$upload_dir = wp_upload_dir();
		$expected = $this->canonical_upload_relative( $evidence['references']['file'], $upload_dir );
		if ( ! is_string( $attached ) || $expected === false || ! $this->paths_match( (string) $this->canonical_upload_relative( $attached, $upload_dir ), $expected ) || ! is_array( $metadata ) || ! isset( $metadata['file'] ) || ! is_string( $metadata['file'] ) || ! $this->paths_match( (string) $this->canonical_upload_relative( $metadata['file'], $upload_dir ), $expected ) ) {
			return false;
		}
		foreach ( $evidence['references']['sizes'] as $name => $file ) {
			if ( ! isset( $metadata['sizes'][ $name ]['file'] ) || $metadata['sizes'][ $name ]['file'] !== $file ) {
				return false;
			}
		}
		foreach ( array_merge( [ $manifest['main'] ], $manifest['aliases'], $manifest['legacy'] ) as $file ) {
			$resolved = $this->resolve_upload_artifact_path( $file['path'] );
			if ( $resolved === false || ! is_file( $resolved['path'] ) || filesize( $resolved['path'] ) !== $file['size'] || hash_file( 'sha256', $resolved['path'] ) !== $file['sha256'] ) {
				return false;
			}
		}

		return $manifest;
	}

	/**
	 * Commit the clean-backup association under the same fence as the file
	 * operation. A durable zero is distinct from an absent legacy association.
	 *
	 * @return bool
	 */
	private function commit_backup_watermark_association( &$operation, $watermark_id ) {
		$attachment_id = $operation['attachment_id'];
		$key = '_iw_backup_watermark_id';
		$intended = (int) $watermark_id;
		if ( $this->should_inject_operation_fault( 'backup_association_pending_journal', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'], 'intended' => $intended ] ) ) {
			return false;
		}
		if ( ! $this->set_operation_state( $operation, 'preparing', [
			'backup_association' => [
				'state' => 'pending',
				'intended' => $intended,
				'preserved' => false,
			],
		] ) ) {
			return false;
		}
		$rows = $this->read_backup_watermark_association_rows( $attachment_id, $key );
		if ( $rows === false ) {
			$this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'refused', 'intended' => $intended, 'preserved' => false ] ] );
			return false;
		}
		if ( count( $rows ) === 1 && $this->backup_watermark_association_row_is_valid( $rows[0] ) ) {
			return $this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'committed', 'intended' => $intended, 'preserved' => true, 'value' => (int) $rows[0]['value'], 'meta_id' => $rows[0]['meta_id'] ] ] );
		}
		if ( count( $rows ) !== 0 ) {
			$this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'refused', 'intended' => $intended, 'preserved' => false ] ] );
			return false;
		}
		$this->should_inject_operation_fault( 'backup_association_before_add', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'], 'intended' => $intended ] );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		$inserted = add_post_meta( $attachment_id, $key, $intended, true );
		$own_meta_id = is_numeric( $inserted ) && (int) $inserted > 0 ? (int) $inserted : 0;
		$rows = $this->read_backup_watermark_association_rows( $attachment_id, $key );
		if ( $rows === false ) {
			$this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'refused', 'intended' => $intended, 'preserved' => false ] ] );
			return false;
		}
		if ( count( $rows ) > 1 && $own_meta_id ) {
			$own_row = null;
			foreach ( $rows as $row ) {
				if ( $row['meta_id'] === $own_meta_id && $this->backup_watermark_association_row_is_valid( $row ) && (int) $row['value'] === $intended ) {
					$own_row = $row;
					break;
				}
			}
			if ( ! is_array( $own_row ) || ! $this->delete_exact_backup_watermark_association_row( $operation, $attachment_id, $key, $own_row ) ) {
				$this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'refused', 'intended' => $intended, 'preserved' => false ] ] );
				return false;
			}
			$rows = $this->read_backup_watermark_association_rows( $attachment_id, $key );
			if ( $rows === false ) {
				$this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'refused', 'intended' => $intended, 'preserved' => false ] ] );
				return false;
			}
			if ( $rows !== false && count( $rows ) === 1 && $this->backup_watermark_association_row_is_valid( $rows[0] ) ) {
				if ( $this->should_inject_operation_fault( 'backup_association_journal_commit', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'], 'intended' => $intended ] ) ) {
					return false;
				}
				return $this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'committed', 'intended' => $intended, 'preserved' => true, 'value' => (int) $rows[0]['value'], 'meta_id' => $rows[0]['meta_id'] ] ] );
			}
		}
		if ( count( $rows ) === 1 && $this->backup_watermark_association_row_is_valid( $rows[0] ) ) {
			$is_own_row = $own_meta_id && $rows[0]['meta_id'] === $own_meta_id && (int) $rows[0]['value'] === $intended;
			if ( $this->should_inject_operation_fault( 'backup_association_journal_commit', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'], 'intended' => $intended ] ) ) {
				return false;
			}
			return $this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'committed', 'intended' => $intended, 'preserved' => ! $is_own_row, 'value' => (int) $rows[0]['value'], 'meta_id' => $rows[0]['meta_id'] ] ] );
		}
		$this->set_operation_state( $operation, 'preparing', [ 'backup_association' => [ 'state' => 'refused', 'intended' => $intended, 'preserved' => false ] ] );
		return false;
	}

	/** @return array|false */
	private function read_backup_watermark_association_rows( $attachment_id, $key ) {
		if ( $this->should_inject_operation_fault( 'backup_association_query_error', [ 'attachment_id' => $attachment_id ] ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The complete row set and row identities are required for collision-safe association establishment.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
			(int) $attachment_id,
			$key
		), ARRAY_A );
		if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) {
			return false;
		}
		$normalized = [];
		foreach ( $rows as $row ) {
			if ( ! isset( $row['meta_id'], $row['meta_value'] ) || (int) $row['meta_id'] < 1 ) {
				return false;
			}
			$normalized[] = [ 'meta_id' => (int) $row['meta_id'], 'value' => maybe_unserialize( $row['meta_value'] ), 'raw' => (string) $row['meta_value'] ];
		}
		return $normalized;
	}

	/** @return bool */
	private function backup_watermark_association_row_is_valid( $row ) {
		return is_array( $row ) && isset( $row['meta_id'], $row['value'], $row['raw'] ) && (int) $row['meta_id'] > 0 && ( is_int( $row['value'] ) || is_string( $row['value'] ) ) && preg_match( '/^\d+$/', (string) $row['value'] );
	}

	/** @return bool */
	private function delete_exact_backup_watermark_association_row( &$operation, $attachment_id, $key, $row ) {
		$this->should_inject_operation_fault( 'backup_association_before_own_row_rollback', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'] ] );
		if ( ! $this->backup_watermark_association_row_is_valid( $row ) || ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact row/value deletion removes only this operation's identified insert after a collision.
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND meta_value = %s",
			$row['meta_id'],
			(int) $attachment_id,
			$key,
			$row['raw']
		) );
		if ( $deleted === 1 ) {
			wp_cache_delete( (int) $attachment_id, 'post_meta' );
			return true;
		}
		return false;
	}

	/** @return bool */
	private function commit_direct_backup_watermark_association( $attachment_id, $watermark_id ) {
		$token = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return false;
		}
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ];
		try {
			return $this->begin_operation( $operation, 'apply', 'manual-apply', [] ) && $this->commit_backup_watermark_association( $operation, $watermark_id ) && $this->set_operation_state( $operation, 'complete', [ 'code' => 'backup_association_committed', 'metadata_committed' => false ] );
		} finally {
			$this->release_operation_lock( $operation );
		}
	}

	/** @return string */
	private function operation_recovery_relative_path( $target, $token ) {
		$extension = pathinfo( $target, PATHINFO_EXTENSION );
		$filename = '.iw-recovery-' . $token . '-' . substr( hash( 'sha256', $target ), 0, 16 ) . ( $extension ? '.' . $extension : '.tmp' );
		$directory = dirname( str_replace( '\\', '/', $target ) );
		return $directory === '.' ? $filename : $directory . '/' . $filename;
	}

	/** @return bool */
	private function operation_record_has_complete_temporary_recovery( $record, $verify_files = false ) {
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! is_string( $record['token'] ) || empty( $record['targets'] ) || ! is_array( $record['targets'] ) ) {
			return false;
		}
		foreach ( $record['targets'] as $target ) {
			if ( ! is_array( $target ) || empty( $target['target'] ) || empty( $target['recovery'] ) || ! is_array( $target['recovery'] ) ) {
				return false;
			}
			$recovery = $target['recovery'];
			if ( ! isset( $recovery['state'], $recovery['root'], $recovery['path'], $recovery['size'], $recovery['hash'] ) || $recovery['state'] !== 'verified' || $recovery['root'] !== 'uploads' || ! is_string( $recovery['path'] ) || $recovery['path'] === '' || ! is_int( $recovery['size'] ) || $recovery['size'] < 1 || ! is_string( $recovery['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $recovery['hash'] ) ) {
				return false;
			}
			$target_relative = $this->normalize_operation_relative_path( $target['target'] );
			$recovery_relative = $this->normalize_operation_relative_path( $recovery['path'] );
			if ( $target_relative === false || $recovery_relative === false || $recovery_relative !== $this->operation_recovery_relative_path( $target_relative, $record['token'] ) || dirname( $recovery_relative ) !== dirname( $target_relative ) || strpos( basename( $recovery_relative ), '.iw-recovery-' . $record['token'] . '-' ) !== 0 ) {
				return false;
			}
			if ( $verify_files ) {
				$file = $this->resolve_upload_artifact_path( $recovery['path'] );
				if ( $file === false || $file['relative'] !== $recovery['path'] || ! $this->verify_operation_file( $file['path'] ) || (string) filesize( $file['path'] ) !== (string) $recovery['size'] || hash_file( 'sha256', $file['path'] ) !== $recovery['hash'] ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @return bool */
	private function operation_record_has_temporary_recovery_evidence( $record ) {
		if ( ! is_array( $record ) || empty( $record['targets'] ) || ! is_array( $record['targets'] ) ) {
			return false;
		}
		foreach ( $record['targets'] as $target ) {
			if ( isset( $target['recovery'] ) && is_array( $target['recovery'] ) && isset( $target['recovery']['root'] ) && $target['recovery']['root'] === 'uploads' ) {
				return true;
			}
		}
		return false;
	}

	/** @return bool */
	private function create_temporary_recovery_sources( &$operation, $groups, $options ) {
		foreach ( $groups as $group ) {
			$source = $this->resolve_upload_artifact_path( $group['target'] );
			$recovery_relative = $this->operation_recovery_relative_path( $group['target'], $operation['token'] );
			$recovery = $this->resolve_upload_artifact_path( $recovery_relative );
			if ( $source === false || $recovery === false || ! $this->verify_operation_file( $source['path'] ) ) {
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			$target = [
				'target' => $group['target'],
				'aliases' => $group['aliases'],
				'recovery' => [ 'state' => 'allocated', 'root' => 'uploads', 'path' => $recovery['relative'] ],
			];
			if ( ! $this->set_operation_target( $operation, $target ) || ! $this->copy_file( $source['path'], $recovery['path'], 'temporary-recovery', $operation['attachment_id'], $options ) || ! $this->verify_operation_file( $recovery['path'] ) || (string) filesize( $source['path'] ) !== (string) filesize( $recovery['path'] ) || hash_file( 'sha256', $source['path'] ) !== hash_file( 'sha256', $recovery['path'] ) ) {
				$this->cleanup_lost_upload_artifact( $recovery['path'], $operation['token'], '.iw-recovery-' );
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			$target['recovery'] = [
				'state' => 'verified',
				'root' => 'uploads',
				'path' => $recovery['relative'],
				'size' => filesize( $recovery['path'] ),
				'hash' => hash_file( 'sha256', $recovery['path'] ),
			];
			if ( ! $this->set_operation_target( $operation, $target ) ) {
				$this->cleanup_lost_upload_artifact( $recovery['path'], $operation['token'], '.iw-recovery-' );
				$operation['recovery_error_code'] = 'journal_write_failed';
				return false;
			}
		}
		return true;
	}

	/** @return bool */
	private function restore_predecessor_temporary_recovery( &$operation ) {
		$prior = ! empty( $operation['journal']['recoverable_predecessor'] ) ? $operation['journal']['recoverable_predecessor'] : null;
		if ( ! $this->operation_record_has_complete_temporary_recovery( $prior ) ) {
			$operation['recovery_error_code'] = 'recovery_source_invalid';
			return false;
		}
		foreach ( $prior['targets'] as $target ) {
			if ( empty( $target['target'] ) || empty( $target['recovery'] ) || ! is_array( $target['recovery'] ) || ! isset( $target['recovery']['path'], $target['recovery']['size'], $target['recovery']['hash'] ) || $target['recovery']['state'] !== 'verified' || $target['recovery']['root'] !== 'uploads' ) {
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			$live = $this->resolve_upload_artifact_path( $target['target'] );
			$recovery = $this->resolve_upload_artifact_path( $target['recovery']['path'] );
			if ( $live === false || $recovery === false || ! $this->verify_operation_file( $recovery['path'] ) || (string) filesize( $recovery['path'] ) !== (string) $target['recovery']['size'] || hash_file( 'sha256', $recovery['path'] ) !== $target['recovery']['hash'] ) {
				$operation['recovery_error_code'] = is_file( $recovery === false ? '' : $recovery['path'] ) ? 'recovery_source_invalid' : 'recovery_source_missing';
				return false;
			}
			if ( ! $this->verify_operation_file( $live['path'] ) ) {
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			$live_is_clean = (string) filesize( $live['path'] ) === (string) $target['recovery']['size'] && hash_file( 'sha256', $live['path'] ) === $target['recovery']['hash'];
			$live_is_promoted = ! empty( $target['hash'] ) && isset( $target['size'] ) && (string) filesize( $live['path'] ) === (string) $target['size'] && hash_file( 'sha256', $live['path'] ) === $target['hash'];
			if ( $live_is_clean ) {
				continue;
			}
			if ( ! $live_is_promoted ) {
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			$stage = $this->operation_stage_path( $live['path'], $operation['token'], 'temporary-recovery-' . $target['target'] );
			$stage_relative = $this->operation_planned_stage_relative_path( $stage, wp_upload_dir() );
			$stage_record = $stage_relative === false ? false : $this->resolve_upload_artifact_path( $stage_relative );
			if ( $stage_record === false || ! $this->set_operation_state( $operation, 'staging', [ 'temporary_recovery_restore' => [ 'target' => $live['relative'], 'root' => 'uploads', 'stage' => $stage_record['relative'], 'state' => 'allocated' ] ] ) || ! $this->copy_file( $recovery['path'], $stage_record['path'], 'temporary-recovery-restore', $operation['attachment_id'] ) || ! $this->verify_operation_file( $stage_record['path'] ) || (string) filesize( $stage_record['path'] ) !== (string) $target['recovery']['size'] || hash_file( 'sha256', $stage_record['path'] ) !== $target['recovery']['hash'] ) {
				$this->cleanup_lost_upload_artifact( $stage, $operation['token'], '.iw-stage-' );
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			$live = $this->resolve_upload_artifact_path( $live['relative'] );
			$stage_record = $this->resolve_upload_artifact_path( $stage_record['relative'] );
			if ( $live === false || $stage_record === false || ! $this->renew_operation_lock( $operation ) || ! @rename( $stage_record['path'], $live['path'] ) || ! $this->verify_operation_file( $live['path'] ) || (string) filesize( $live['path'] ) !== (string) $target['recovery']['size'] || hash_file( 'sha256', $live['path'] ) !== $target['recovery']['hash'] ) {
				$this->cleanup_lost_upload_artifact( $stage, $operation['token'], '.iw-stage-' );
				$operation['recovery_error_code'] = 'recovery_source_invalid';
				return false;
			}
			if ( $this->should_inject_operation_fault( 'after_temporary_recovery_target_restore', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $target['target'] ] ) ) {
				$operation['recovery_error_code'] = 'recovery_interrupted';
				return false;
			}
		}
		return true;
	}

	/**
	 * Reconcile a retained predecessor before a retry renders anything. A fully
	 * promoted apply with a missing flag is finalized from its verified hashes;
	 * a partly promoted predecessor is flagged for clean-source reapply instead
	 * of ever rendering over a watermarked live file.
	 *
	 * @return string complete|partial|temporary-recovery|uncertain|none
	 */
	private function reconcile_predecessor( &$operation, $data, $upload_dir, $context ) {
		$journal = $this->read_operation_journal( $operation['attachment_id'] );
		if ( $journal === false ) {
			return 'none';
		}
		// A pending automatic handoff is settled before any other selection.
		$handoff = $this->resolve_pending_handoff( $operation, $journal );
		if ( in_array( $handoff, [ 'complete', 'handoff_pending', 'begin_refused' ], true ) ) {
			return $handoff;
		}
		if ( $handoff === 'interrupted' ) {
			$journal = $operation['journal'];
		}
		$current = ! empty( $journal['current'] ) ? $journal['current'] : null;
		$retained = ! empty( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
		if ( is_array( $current ) && isset( $current['state'] ) && $current['state'] === 'complete' && ! empty( $current['metadata_committed'] ) ) {
			return 'none';
		}
		$current_has_recovery = $this->operation_record_has_complete_temporary_recovery( $current );
		$retained_has_recovery = $this->operation_record_has_complete_temporary_recovery( $retained );
		$current_recovery_state = is_array( $current ) && isset( $current['state'] ) && in_array( $current['state'], [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging', 'rendering', 'backup-done', 'preparing' ], true );
		if ( $current_recovery_state && $current_has_recovery ) {
			$prior = $current;
		} elseif ( $retained_has_recovery ) {
			$prior = $retained;
		} elseif ( $current_recovery_state && $this->operation_record_has_temporary_recovery_evidence( $current ) ) {
			$prior = $current;
		} elseif ( $retained_has_recovery || $this->operation_record_has_temporary_recovery_evidence( $retained ) ) {
			$prior = $retained;
		} else {
			$prior = is_array( $retained ) ? $retained : ( is_array( $current ) && in_array( $current['state'], [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging' ], true ) ? $current : null );
		}
		if ( ! is_array( $prior ) || empty( $prior['targets'] ) || ( isset( $prior['site_id'] ) && (int) $prior['site_id'] !== ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 ) ) ) {
			return 'none';
		}
		$operation['selected_recovery_predecessor'] = $prior;
		if ( $this->operation_record_has_complete_temporary_recovery( $prior ) || $this->operation_record_has_temporary_recovery_evidence( $prior ) ) {
			return 'temporary-recovery';
		}
		$matched = 0;
		$final_targets = 0;
		$stageable_targets = 0;
		foreach ( $prior['targets'] as $target ) {
			$is_final_output = isset( $target['intent'] ) ? $target['intent'] === 'watermark-output' : ! isset( $target['source'] ) && ! isset( $target['recovery'] ) && ! $this->target_is_legacy_restoration( $target, $prior['token'] );
			if ( ! $is_final_output ) {
				continue;
			}
			$final_targets++;
			// Live files are only touched by promote_stage_file(), which cannot run
			// before the target's stage is journalled. A target still pending without
			// a stage therefore proves its live file was never written by $prior.
			if ( ! empty( $target['stage'] ) || ( isset( $target['state'] ) && $target['state'] !== 'pending' ) ) {
				$stageable_targets++;
			}
			if ( empty( $target['target'] ) || empty( $target['hash'] ) || empty( $target['size'] ) ) {
				continue;
			}
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $target['target'] );
			if ( $this->verify_operation_file( $path ) && (string) filesize( $path ) === (string) $target['size'] && hash_file( 'sha256', $path ) === $target['hash'] ) {
				$matched++;
			}
		}
		$regenerated_metadata_state = isset( $prior['regenerated_metadata_commit']['state'] ) ? $prior['regenerated_metadata_commit']['state'] : '';
		$metadata_commit_is_provable = $regenerated_metadata_state === 'committed' || ( ! array_key_exists( 'regenerated_metadata_commit', $prior ) && ! array_key_exists( 'restoration', $prior ) );
		// Matching target hashes do not prove that a name-stable reapply resolved
		// its derivatives (uncertain ownership, failed or filtered sizes). Such a
		// record is never adopted as complete; the retry runs the reapply again.
		$name_stable = isset( $prior['derivative_recovery'] ) || isset( $prior['pre_operation'] );
		// Nor is an older retained record adopted while a newer current record
		// still carries name-stable work: that work restored and regenerated files
		// the older record never saw, so its matching hashes prove nothing.
		$newer_name_stable = is_array( $current ) && ( ! isset( $current['token'], $prior['token'] ) || $current['token'] !== $prior['token'] ) && ( isset( $current['derivative_recovery'] ) || isset( $current['pre_operation'] ) );
		// An automatic apply whose metadata handoff was never verified is never
		// adopted as complete: matching files do not prove its metadata.
		$unverified_handoff = isset( $prior['metadata_handoff'] ) && $prior['metadata_handoff'] === 'pending';
		if ( ! $unverified_handoff && ! $name_stable && ! $newer_name_stable && $final_targets > 0 && $matched === $final_targets && $final_targets === count( $prior['targets'] ) && $metadata_commit_is_provable && isset( $prior['type'] ) && $prior['type'] === 'apply' ) {
			$operation['journal'] = $journal;
			// Without journaled evidence no manifest can be written for the adopted
			// work, so the record says so: the attachment is untrusted, not legacy.
			$adopted = $journal;
			$adopted['current'] = array_merge( $prior, [ 'state' => 'complete', 'code' => isset( $prior['manifest_evidence'] ) ? 'watermarked' : 'manifest_unverified', 'metadata_committed' => true, 'heartbeat_at' => time(), 'ended_at' => time() ] );
			// Admit the exact value the publisher will use before the flag effect.
			if ( ! $this->is_valid_operation_journal( $adopted ) || ( ! empty( $operation['name_stable_budget'] ) && strlen( maybe_serialize( $adopted ) ) > self::NAME_STABILITY_JOURNAL_BUDGET ) ) {
				$operation['begin_error_code'] = 'journal_budget_exceeded';
				return 'begin_refused';
			}
			if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->commit_watermarked_flag( $operation, 1 ) ) {
				return 'uncertain';
			}
			if ( ! $this->publish_operation_journal( $operation, $adopted ) ) {
				return 'adoption_failed';
			}
			$this->operation_result( 'complete', $prior['token'], 'watermarked', __( 'Watermark applied.', 'image-watermark' ), false, [ 'processed' => [], 'skipped' => [], 'failed' => [] ] );
			$this->record_last_operation( $operation['attachment_id'], 'success', 'watermarked', __( 'Watermark applied.', 'image-watermark' ), $context, [], [], [], 'complete', $operation );
			if ( ! $this->cleanup_owned_operation_stages( $operation, true ) ) {
				if ( ! $this->set_operation_state( $operation, 'complete', [ 'cleanup_pending' => true ] ) ) {
					return 'partial';
				}
			} elseif ( ! $this->clear_completed_recovery_authority( $operation ) ) {
				return 'partial';
			}
			return 'complete';
		}
		if ( $matched > 0 && isset( $prior['type'] ) && $prior['type'] === 'apply' ) {
			// Prepare the actual reapply before repairing its protective flag.
			if ( isset( $operation['reapply_begin_groups'] ) ) {
				$operation['name_stable_budget'] = true;
				$operation['prepared_begin'] = $this->prepare_begin_operation( $operation, $journal, 'apply', $context, $operation['reapply_begin_groups'] );
				if ( $operation['prepared_begin'] === false ) {
					return 'begin_refused';
				}
				$operation['journal'] = $journal;
				if ( ! $this->begin_prefix_confirmed( $operation ) ) {
					return 'begin_refused';
				}
			}
			// A subsequent apply now takes the reapply branch and restores the
			// verified backup rather than stacking over this promoted target.
			if ( ! $this->commit_watermarked_flag( $operation, 1 ) ) {
				return 'uncertain';
			}
			return 'partial';
		}
		// The retained record still owns recovery, but a later committed partial
		// reapply can have replaced its outputs. Its own confirmed promotion can
		// establish the same flagged-reapply route without discarding that authority.
		if ( $newer_name_stable && isset( $current['site_id'], $current['type'], $current['state'], $current['targets'], $current['regenerated_metadata_commit']['state'] ) && is_array( $current['targets'] ) && (int) $current['site_id'] === get_current_blog_id() && $current['type'] === 'apply' && $current['state'] === 'partial' && $current['regenerated_metadata_commit']['state'] === 'committed' && (int) get_post_meta( $operation['attachment_id'], $this->plugin->get_watermarked_meta_key(), true ) === 1 ) {
			foreach ( $current['targets'] as $target ) {
				if ( ! isset( $target['intent'], $target['state'], $target['target'], $target['size'], $target['hash'] ) || $target['intent'] !== 'watermark-output' || $target['state'] !== 'promoted' ) { continue; }
				$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $target['target'] );
				if ( $this->verify_operation_file( $path ) && (string) filesize( $path ) === (string) $target['size'] && hash_file( 'sha256', $path ) === $target['hash'] ) {
					return 'partial';
				}
			}
		}
		if ( ! $this->generation_record_promoted_nothing( $prior ) && $final_targets > 0 && $stageable_targets > 0 && isset( $prior['type'] ) && $prior['type'] === 'apply' && in_array( isset( $prior['state'] ) ? $prior['state'] : '', [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging', 'rendering', 'backup-done', 'preparing' ], true ) ) {
			return 'uncertain';
		}
		return 'none';
	}

	/** @return bool */
	private function target_is_legacy_restoration( $target, $token ) {
		if ( ! is_array( $target ) || ! is_string( $token ) || $token === '' || isset( $target['intent'] ) || isset( $target['source'] ) || isset( $target['recovery'] ) || empty( $target['stage'] ) || ! is_string( $target['stage'] ) ) {
			return false;
		}
		$identities = [
			substr( hash( 'sha256', 'restore-source' ), 0, 16 ),
			substr( hash( 'sha256', 'remove-source' ), 0, 16 ),
		];
		return (bool) preg_match( '/^\.iw-stage-' . preg_quote( $token, '/' ) . '-(?:' . implode( '|', $identities ) . ')\.[^.]+$/', basename( $target['stage'] ) );
	}

	/**
	 * Exact bound of a token-named record before its first promotion: the
	 * record as journaled now plus everything the rest of the attempt can
	 * still write (each target promoted, the retirement and outcome entries of
	 * the plan, the metadata commit state, the closing fields, an identity
	 * rollback's `restoration`, and, for a manual reapply, its manifest
	 * evidence over the watermarked groups).
	 *
	 * @param array      $operation Operation holding the lease.
	 * @param array      $plan Promotion plan.
	 * @param array[]    $staged_groups Staged target groups.
	 * @param array|null $options Options when manifest evidence will be built.
	 * @param array      $upload_dir Upload directory.
	 * @return array{bytes:int,evidence:int} The record bound, and the part of
	 *                                       it its manifest evidence takes.
	 */
	private function staged_record_bytes( $operation, $plan, $staged_groups, $options, $upload_dir ) {
		$record = $operation['journal']['current'];
		if ( isset( $record['main_recovery'] ) ) {
			// A successor starts allocated (one byte longer than verified).
			$record['main_recovery']['state'] = 'allocated';
		}
		$hash = str_repeat( 'f', 64 );
		$code = str_repeat( 'c', 32 );
		$paths = array_keys( $plan['retire'] );
		$outcomes = isset( $record['derivative_recovery']['outcomes'] ) && is_array( $record['derivative_recovery']['outcomes'] ) ? $record['derivative_recovery']['outcomes'] : [];
		foreach ( $plan['outcomes'] as $name => $outcome ) {
			$outcomes[ (string) $name ] = $code;
		}
		foreach ( $plan['retire'] as $retire ) {
			$outcomes[ (string) $retire['key'] ] = $code;
		}
		$record['derivative_recovery'] = array_merge( isset( $record['derivative_recovery'] ) && is_array( $record['derivative_recovery'] ) ? $record['derivative_recovery'] : [], [ 'outcomes' => $outcomes, 'retiring' => $paths, 'retired' => $paths ] );
		foreach ( $record['targets'] as $index => $target ) {
			$record['targets'][ $index ]['state'] = 'promoted';
		}
		$record['regenerated_metadata_commit'] = [ 'state' => 'committed', 'hash' => $hash ];
		$record['code'] = $code;
		$record['counts'] = [ 'processed' => PHP_INT_MAX, 'failed' => PHP_INT_MAX ];
		$record['ended_at'] = PHP_INT_MAX;
		$record['heartbeat_at'] = PHP_INT_MAX;
		$record['cleanup_pending'] = false;
		$record['metadata_committed'] = false;
		$main = isset( $record['generation_stage']['sources'][0] ) ? $record['generation_stage']['sources'][0] : [];
		$wide = str_repeat( 'p', 256 + ( isset( $main['path'] ) ? strlen( $main['path'] ) : 64 ) );
		$record['restoration'] = [ 'target' => $wide, 'root' => 'uploads', 'stage' => $wide, 'size' => PHP_INT_MAX, 'hash' => $hash, 'aliases' => [ 'full' ], 'intent' => 'restoration', 'state' => 'promoted', 'source' => [ 'path' => $wide, 'size' => PHP_INT_MAX, 'hash' => $hash, 'recovery' => $wide ] ];
		$evidence = 0;
		if ( is_array( $options ) ) {
			$prospective = $operation;
			// A runner borrows its journal by reference; the synthetic bound must
			// never replace the live owner's record with its widest-case model.
			unset( $prospective['journal'] );
			$prospective['journal'] = $operation['journal'];
			$prospective['journal']['current'] = $record;
			$record['manifest_evidence'] = $this->staged_evidence_bound( $prospective, $plan, $staged_groups, $options, $upload_dir );
			$record['metadata_handoff'] = 'verified';
			$evidence = strlen( serialize( $record['manifest_evidence'] ) );
		}

		return [ 'bytes' => strlen( serialize( $record ) ) + 1024, 'evidence' => $evidence ];
	}

	/**
	 * The manifest evidence a manual reapply will journal, built against the
	 * record as it will be once its watermarked groups are promoted, and never
	 * smaller than its structural shape (every alias reference, the main path,
	 * and the provenance at their widest).
	 *
	 * @param array   $prospective Operation whose current record has its targets promoted.
	 * @param array   $plan Promotion plan.
	 * @param array[] $staged_groups Staged target groups.
	 * @param array   $options Effective options.
	 * @param array   $upload_dir Upload directory.
	 * @return array
	 */
	private function staged_evidence_bound( $prospective, $plan, $staged_groups, $options, $upload_dir ) {
		$hash = str_repeat( 'f', 64 );
		$watermarked = [];
		$references = [];
		foreach ( $staged_groups as $staged ) {
			if ( $staged['watermark'] ) {
				$staged['target']['state'] = 'promoted';
				$watermarked[] = $staged;
				foreach ( $staged['target']['aliases'] as $alias ) {
					$references[ (string) $alias ] = wp_basename( $staged['target']['target'] );
				}
			}
		}
		$evidence = $this->build_manifest_evidence( $prospective, $plan['metadata'], $watermarked, $options, $upload_dir );
		$provenance = get_post_meta( $prospective['attachment_id'], self::PROVENANCE_META_KEY, true );
		$main = str_repeat( 'm', 64 + ( isset( $prospective['pre_operation']['file'] ) ? strlen( $prospective['pre_operation']['file'] ) : 64 ) );
		$shape = [
			'version'    => 1,
			'manifest'   => [ 'version' => self::MANIFEST_VERSION, 'token' => str_repeat( 't', 64 ), 'committed_at' => PHP_INT_MAX, 'main' => [ 'path' => $main, 'size' => PHP_INT_MAX, 'sha256' => $hash, 'watermarked' => true ], 'legacy' => [], 'provenance' => is_array( $provenance ) && isset( $provenance['file'] ) && is_string( $provenance['file'] ) ? [ 'file' => $provenance['file'], 'size' => PHP_INT_MAX, 'sha256' => $hash ] : null, 'settings' => $hash ],
			'references' => [ 'file' => $main, 'sizes' => $references ],
		];
		if ( is_array( $evidence ) && ! empty( $evidence['manifest']['legacy'] ) ) {
			$shape['manifest']['legacy'] = $evidence['manifest']['legacy'];
			foreach ( $shape['manifest']['legacy'] as &$entry ) {
				$entry['size'] = PHP_INT_MAX;
			}
			unset( $entry );
		}

		return is_array( $evidence ) && strlen( serialize( $evidence ) ) > strlen( serialize( $shape ) ) ? $evidence : $shape;
	}

	/**
	 * Whether the final metadata still names every promoted watermarked
	 * target: `full` by its canonical identity, each other alias by the
	 * target's basename.
	 *
	 * @param array   $final Final metadata.
	 * @param array[] $watermarked Promoted watermarked groups.
	 * @param array   $upload_dir Upload directory.
	 * @return bool
	 */
	private function final_references_match( $final, $watermarked, $upload_dir ) {
		if ( ! is_array( $final ) || empty( $final['file'] ) || ! is_string( $final['file'] ) ) {
			return false;
		}
		foreach ( $watermarked as $staged ) {
			$target = $staged['target']['target'];
			foreach ( $staged['target']['aliases'] as $alias ) {
				if ( $alias === 'full' ) {
					if ( $this->canonical_upload_relative( $final['file'], $upload_dir ) !== $target ) {
						return false;
					}
				} elseif ( ! isset( $final['sizes'][ $alias ]['file'] ) || $final['sizes'][ $alias ]['file'] !== wp_basename( $target ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * The canonical uploads-relative spelling of an uploads-relative path, or
	 * false when it does not resolve inside uploads.
	 *
	 * @param string $relative Uploads-relative path.
	 * @param array  $upload_dir Upload directory.
	 * @return string|false
	 */
	private function canonical_upload_relative( $relative, $upload_dir ) {
		if ( ! is_string( $relative ) || $this->normalize_operation_relative_path( $relative ) === false ) {
			return false;
		}

		return $this->operation_destination_relative_path( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $this->normalize_operation_relative_path( $relative ) ), $upload_dir );
	}

	/**
	 * Whether a token-named generation record provably wrote no live file. It
	 * promotes a target only by renaming that target's stage onto it, and only
	 * after every output is staged, so a record with no promoted target whose
	 * staged targets all still have their verified stage file never did.
	 *
	 * @param array $record Operation record.
	 * @return bool
	 */
	private function generation_record_promoted_nothing( $record, $read_only = false ) {
		if ( ! isset( $record['generation_stage'], $record['targets'] ) || ! is_array( $record['targets'] ) ) {
			return false;
		}
		foreach ( $record['targets'] as $target ) {
			$state = isset( $target['state'] ) ? $target['state'] : 'pending';
			if ( $state === 'pending' || $state === 'allocated' ) {
				continue;
			}
			if ( $state !== 'staged' || empty( $target['stage'] ) || ! isset( $target['size'], $target['hash'] ) ) {
				return false;
			}
			$failure = null;
			$stage = $this->resolve_upload_artifact_path( $target['stage'], false, '', 0, $failure, $read_only );
			if ( $stage === false || ! $this->verify_operation_file( $stage['path'] ) || (string) filesize( $stage['path'] ) !== (string) $target['size'] || hash_file( 'sha256', $stage['path'] ) !== $target['hash'] ) {
				return false;
			}
		}

		return true;
	}

	/** @param array $operation Current operation. @param array $target Staged target record. @return bool */
	private function promote_stage_file( &$operation, $stage, $target_path, $target ) {
		$upload_dir = wp_upload_dir();
		$stage_relative = $this->operation_planned_stage_relative_path( $stage, $upload_dir );
		$target_relative = isset( $target['target'] ) ? $target['target'] : $this->operation_destination_relative_path( $target_path, $upload_dir );
		$stage_record = $stage_relative === false ? false : $this->resolve_upload_artifact_path( $stage_relative );
		$target_record = $target_relative === false ? false : $this->resolve_upload_artifact_path( $target_relative );
		if ( $stage_record === false || $target_record === false || $stage_record['relative'] !== $stage_relative || $target_record['relative'] !== $target_relative ) {
			return false;
		}
		$stage = $stage_record['path'];
		$target_path = $target_record['path'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the accepted atomic staged promotion.
		if ( ! $this->renew_operation_lock( $operation ) || ( $stage_record = $this->resolve_upload_artifact_path( $stage_relative ) ) === false || ( $target_record = $this->resolve_upload_artifact_path( $target_relative ) ) === false || ! @rename( $stage_record['path'], $target_record['path'] ) || ! $this->verify_operation_file( $target_record['path'] ) ) {
			return false;
		}
		$target_path = $target_record['path'];
		if ( (string) filesize( $target_path ) !== (string) $target['size'] || hash_file( 'sha256', $target_path ) !== $target['hash'] ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'after_promotion', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $target['target'] ] ) ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'journal_write_after_promotion', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $target['target'] ] ) ) {
			return false;
		}
		$target['state'] = 'promoted';
		return $this->set_operation_target( $operation, $target );
	}

	/** @return bool */
	private function stage_copy_and_promote( &$operation, $source, $destination, $identity, $options ) {
		$upload_dir = wp_upload_dir();
		$target_relative = $this->operation_destination_relative_path( $destination, $upload_dir );
		$target_record = $target_relative === false ? false : $this->resolve_upload_artifact_path( $target_relative );
		if ( $target_record === false ) {
			return false;
		}
		$destination = $target_record['path'];
		$stage = $this->operation_stage_path( $destination, $operation['token'], $identity );
		$stage_relative = $this->operation_planned_stage_relative_path( $stage, $upload_dir );
		$stage_record = $stage_relative === false ? false : $this->resolve_upload_artifact_path( $stage_relative );
		$source_relative = $this->operation_relative_path( $source, $upload_dir );
		if ( $source_relative === false ) {
			$source_relative = $this->operation_backup_relative_path( $source );
		}
		$target = [
			'target'  => $target_relative,
			'root'    => 'uploads',
			'stage'   => $stage_relative,
			'aliases' => [ 'full' ],
			'intent'  => 'restoration',
			'state'   => 'allocated',
		];
		if ( $stage_record === false || $target_relative === false || $target['stage'] === false || ! $this->set_operation_state( $operation, 'staging', [ 'restoration' => $target ] ) || ! $this->copy_file( $source, $stage_record['path'], 'stage', $operation['attachment_id'], $options ) || $this->should_inject_operation_fault( 'after_restore_stage_copy', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) || ! $this->verify_operation_file( $stage_record['path'] ) ) {
			// The journaled allocation is handled only by fenced cleanup.
			return false;
		}
		$target = [
			'target'  => $target_relative,
			'root'    => 'uploads',
			'stage'   => $stage_record['relative'],
			'size'    => filesize( $stage_record['path'] ),
			'hash'    => hash_file( 'sha256', $stage_record['path'] ),
			'aliases' => [ 'full' ],
			'intent'  => 'restoration',
			'state'   => 'staged',
			'source'  => [
				'path'     => $source_relative === false ? '' : $source_relative,
				'size'     => is_file( $source ) ? filesize( $source ) : 0,
				'hash'     => is_file( $source ) ? hash_file( 'sha256', $source ) : '',
				'recovery' => $target_relative,
			],
		];
		if ( ! $this->set_operation_state( $operation, 'staging', [ 'restoration' => $target ] ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the accepted atomic staged promotion.
		if ( ! $this->renew_operation_lock( $operation ) || ( $stage_record = $this->resolve_upload_artifact_path( $target['stage'] ) ) === false || ( $target_record = $this->resolve_upload_artifact_path( $target_relative ) ) === false || ! @rename( $stage_record['path'], $target_record['path'] ) || ! $this->verify_operation_file( $target_record['path'] ) ) {
			return false;
		}
		$destination = $target_record['path'];
		if ( (string) filesize( $destination ) !== (string) $target['size'] || hash_file( 'sha256', $destination ) !== $target['hash'] ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'after_promotion', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $target['target'] ] ) || $this->should_inject_operation_fault( 'journal_write_after_promotion', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $target['target'] ] ) ) {
			return false;
		}
		$target['state'] = 'promoted';
		return $this->set_operation_state( $operation, 'staging', [ 'restoration' => $target ] );
	}

	/**
	 * Keep the normal backup key while optionally copying a verified recovery
	 * source: a retry's live main can still hold its earlier watermark output.
	 *
	 * @param string|null $clean_source Uploads-relative verified source override.
	 * @return bool
	 */
	private function create_staged_backup( &$operation, $data, $upload_dir, $options, $clean_source = null ) {
		$backup_record_path = $this->resolve_backup_path( $data['file'], true, 'backup-root', $operation['attachment_id'] );
		$source_failure = '';
		$source_record = $this->resolve_upload_artifact_path( $clean_source === null ? $data['file'] : $clean_source, false, '', 0, $source_failure );
		if ( $backup_record_path === false || $source_record === false ) {
			if ( $source_record === false ) {
				$operation['backup_error_code'] = $source_failure === 'invalid_path' ? 'invalid_source_path' : 'source_path_unavailable';
				$operation['backup_error'] = $source_failure === 'invalid_path'
					? __( 'The attachment source path is invalid.', 'image-watermark' )
					: __( 'The attachment source path could not be verified. Check the local image file and attachment metadata.', 'image-watermark' );
			} else {
				$operation['backup_error_code'] = 'backup_failed';
				/* translators: 1: Backup root path. */
				$operation['backup_error'] = sprintf( __( 'Backup folder could not be created: %s', 'image-watermark' ), $this->get_backup_root_dir() );
			}
			return false;
		}
		$backup = $backup_record_path['path'];
		$association_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
		if ( $this->is_valid_backup_file( $backup ) ) {
			if ( ! $this->set_operation_state( $operation, 'backup-done', [ 'backup' => [ 'state' => 'existing', 'root' => 'backup', 'path' => $backup_record_path['relative'], 'size' => filesize( $backup ), 'hash' => hash_file( 'sha256', $backup ), 'recovery' => $backup_record_path['relative'] ] ] ) || ! $this->commit_backup_watermark_association( $operation, $association_id ) ) {
				$operation['backup_error_code'] = 'backup_metadata_commit_failed';
				$operation['backup_error'] = __( 'The clean backup was retained, but its watermark association could not be committed.', 'image-watermark' );
				return false;
			}
			return true;
		}
		$source = $source_record['path'];
		if ( ! $this->has_readable_image_bytes( $source ) ) {
			$operation['backup_error'] = __( 'Could not read original image file.', 'image-watermark' );
			return false;
		}
		if ( ! wp_is_writable( $backup_record_path['parent'] ) ) {
			/* translators: 1: Backup subfolder path. */
			$operation['backup_error'] = sprintf( __( 'Backup subfolder could not be created: %s', 'image-watermark' ), $backup_record_path['parent'] );
			return false;
		}
		$stage = $this->operation_stage_path( $backup, $operation['token'], 'backup' );
		$stage_relative = $this->operation_root_relative_path( $stage, $this->get_backup_root_dir() );
		$stage_record = $stage_relative === false ? false : $this->resolve_backup_path( $stage_relative );
		$backup_record = [ 'state' => 'allocated', 'root' => 'backup', 'path' => $backup_record_path['relative'], 'stage' => $stage_relative, 'recovery' => $backup_record_path['relative'] ];
		// Manifest-based regeneration/reapply carries main recovery authority; do
		// not extend its retention behavior to initial-apply recovery (D-002).
		if ( $stage_record === false || ! $this->set_operation_state( $operation, 'preparing', [ 'backup' => $backup_record ] ) || ! $this->copy_file( $source, $stage_record['path'], 'backup', $operation['attachment_id'], $options ) || $this->should_inject_operation_fault( 'after_backup_stage_copy', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) || ! $this->verify_operation_file( $stage_record['path'] ) ) {
			$this->cleanup_lost_backup_artifact( $stage, $operation['token'], ! empty( $operation['journal']['current']['main_recovery'] ) );
			$operation['backup_error'] = __( 'Failed to copy original file to backup location.', 'image-watermark' );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename atomically promotes the verified backup.
		if ( ! $this->set_operation_state( $operation, 'preparing', [ 'backup' => [ 'state' => 'staged', 'root' => 'backup', 'path' => $backup_record_path['relative'], 'stage' => $stage_record['relative'], 'source' => [ 'path' => $clean_source === null ? $data['file'] : $source_record['relative'], 'size' => filesize( $source ), 'hash' => hash_file( 'sha256', $source ) ], 'size' => filesize( $stage_record['path'] ), 'hash' => hash_file( 'sha256', $stage_record['path'] ), 'recovery' => $backup_record_path['relative'] ] ] ) || ! $this->renew_operation_lock( $operation ) || ( $stage_record = $this->resolve_backup_path( $stage_record['relative'] ) ) === false || ( $backup_record_path = $this->resolve_backup_path( $backup_record_path['relative'] ) ) === false || ! @rename( $stage_record['path'], $backup_record_path['path'] ) || ! $this->is_valid_backup_file( $backup_record_path['path'] ) || ! $this->renew_operation_lock( $operation ) ) {
			$this->cleanup_lost_backup_artifact( $stage, $operation['token'], ! empty( $operation['journal']['current']['main_recovery'] ) );
			$operation['backup_error'] = __( 'Failed to copy original file to backup location.', 'image-watermark' );
			return false;
		}
		if ( ! $this->set_operation_state( $operation, 'preparing', [ 'backup' => [ 'state' => 'promoted', 'root' => 'backup', 'path' => $backup_record_path['relative'], 'stage' => $stage_record['relative'], 'source' => [ 'path' => $clean_source === null ? $data['file'] : $source_record['relative'], 'size' => filesize( $source ), 'hash' => hash_file( 'sha256', $source ) ], 'size' => filesize( $backup_record_path['path'] ), 'hash' => hash_file( 'sha256', $backup_record_path['path'] ), 'recovery' => $backup_record_path['relative'] ] ] ) || ! $this->commit_backup_watermark_association( $operation, $association_id ) ) {
			$operation['backup_error_code'] = 'backup_metadata_commit_failed';
			$operation['backup_error'] = __( 'The clean backup was retained, but its watermark association could not be committed.', 'image-watermark' );
			return false;
		}
		return $this->set_operation_state( $operation, 'backup-done' );
	}

	/** @return array */
	private function operation_failure( $data, $method, $attachment_id, $context, $result ) {
		if ( $method === 'manual' ) {
			return [ 'error' => $result['message'], 'outcome' => $result ];
		}
		if ( $context === 'auto-apply' && in_array( $result['outcome'], [ 'failed', 'partial', 'interrupted' ], true ) ) {
			$this->queue_auto_operation_notice( $attachment_id, $result );
		}
		if ( $result['code'] === 'backup_failed' ) {
			/* translators: 1: Backup failure message. */
			$this->add_admin_notice( sprintf( __( 'Image Watermark: Backup failed - %s. Watermark not applied. Please check backup folder permissions.', 'image-watermark' ), $result['message'] ), 'error' );
		} elseif ( $result['message'] ) {
			/* translators: 1: Watermark skip message. */
			$this->add_admin_notice( sprintf( __( 'Image Watermark: Upload successful, but watermark skipped - %s', 'image-watermark' ), $result['message'] ), 'warning' );
		}
		return $data;
	}

	/** @return array */
	private function operation_terminal_failure( &$operation, $data, $method, $context, $state, $code, $message, $retryable = false, $processed = [], $failed = [], $skipped = [] ) {
		$sizes = [ 'processed' => $processed, 'failed' => array_map( function( $name ) use ( $code ) { return [ 'name' => $name, 'code' => $code ]; }, $failed ), 'skipped' => $skipped ];
		$details = [ 'code' => $code, 'counts' => [ 'processed' => count( $processed ), 'failed' => count( $failed ) ] ];
		$staged = ! empty( $operation['name_stable_budget'] );
		if ( $staged ) {
			// A failed cleanup must remain discoverable even if its next write is refused.
			$details['cleanup_pending'] = true;
		}
		// This observation is for reporting only, not publication or cleanup authority.
		$observed_mutation = $state === 'failed' && $this->operation_record_mutated_derivatives( $operation['journal']['current'] );
		$terminal = $this->set_operation_state( $operation, $state, $details );
		$mutated = $terminal && $state === 'failed' && $this->operation_record_mutated_derivatives( $operation['journal']['current'] );
		$cleanup_recovery = $state === 'failed' && empty( $processed ) && ( ! $staged || ! $mutated );
		if ( $terminal ) {
			$cleaned = $this->cleanup_operation_record_artifacts( $operation, $operation['journal']['current'], $cleanup_recovery );
			if ( $staged && $cleaned ) {
				$this->set_operation_state( $operation, $state, [ 'cleanup_pending' => false ] );
			} elseif ( ! $staged && ! $cleaned ) {
				$this->set_operation_state( $operation, $state, [ 'cleanup_pending' => true ] );
			}
			if ( $staged && ( ! empty( $operation['journal_uncertain'] ) || ! $this->renew_operation_lock( $operation ) ) ) {
				$terminal = false;
			}
		}
		$reported_code = $code;
		if ( $mutated || ( ! $terminal && $observed_mutation ) ) {
			// The journal keeps the failure code; the existing outcome
			// fields tell the user that live files changed and a retry finishes it.
			$reported_code = 'failed_after_mutation';
			/* translators: 1: Operation failure message. */
			$message = sprintf( __( 'Live image files were already changed when the operation failed: %s Retry the operation to finish it.', 'image-watermark' ), $message );
			$retryable = true;
		}
		$result = $this->operation_result( $terminal ? $state : 'interrupted', $operation['token'], $terminal ? $reported_code : 'journal_write_failed', $message, $retryable, $sizes );
		if ( $terminal ) {
			$this->record_last_operation( $operation['attachment_id'], 'error', $result['code'], $message, $context, $processed, $sizes['failed'], $skipped, $result['outcome'], $operation );
		}
		return $this->operation_failure( $data, $method, $operation['attachment_id'], $context, $result );
	}

	/** @return array */
	private function operation_terminal_skip( &$operation, $data, $context, $code, $message ) {
		$sizes = [ 'processed' => [], 'failed' => [], 'skipped' => [ [ 'name' => 'all', 'code' => $code ] ] ];
		if ( ! $this->set_operation_state( $operation, 'skipped', [ 'code' => $code, 'counts' => [ 'processed' => 0, 'failed' => 0 ] ] ) ) {
			return $this->operation_failure( $data, '', $operation['attachment_id'], $context, $this->operation_result( 'interrupted', $operation['token'], 'journal_write_failed', $message, true, $sizes ) );
		}
		if ( ! $this->cleanup_operation_record_artifacts( $operation, $operation['journal']['current'], false ) ) {
			$this->set_operation_state( $operation, 'skipped', [ 'cleanup_pending' => true ] );
		}
		$this->operation_result( 'skipped', $operation['token'], $code, $message, false, $sizes );
		$this->record_last_operation( $operation['attachment_id'], 'skipped', $code, $message, $context, [], [], $sizes['skipped'], 'skipped', $operation );

		return $data;
	}

	/**
	 * Rendered result of an automatic initial apply: the request-local outcome
	 * `complete`, without journal completion, the persisted last operation, or
	 * cleanup. Core writes the metadata only after the filter chain, so the
	 * handoff is verified later (at shutdown, at the client-side finalize
	 * commit check, or at the next touch); its stages and recovery sources are
	 * kept until then.
	 *
	 * @param array $operation Current operation.
	 * @param array $data Metadata handed to core.
	 * @param int   $attachment_id Attachment ID.
	 * @param array $processed Watermarked size names.
	 * @return array
	 */
	private function operation_rendered( &$operation, $data, $attachment_id, $processed ) {
		$this->operation_result( 'complete', $operation['token'], 'watermarked', __( 'Watermark applied.', 'image-watermark' ), false, [ 'processed' => $processed, 'skipped' => [], 'failed' => [] ] );
		// A network import can upload under switch_to_blog() and reach shutdown
		// on another site, so the entry keeps the site it belongs to.
		$site_id = get_current_blog_id();
		$this->pending_handoffs[ $site_id . ':' . $attachment_id ] = [ 'site_id' => $site_id, 'attachment_id' => (int) $attachment_id, 'token' => $operation['token'] ];
		if ( ! $this->handoff_shutdown_scheduled ) {
			$this->handoff_shutdown_scheduled = true;
			add_action( 'shutdown', [ $this, 'verify_pending_handoffs' ], 5 );
		}

		return $data;
	}

	/**
	 * Verify every automatic apply this request rendered, once core has
	 * written its metadata, each in the site it was rendered on. A busy entry
	 * stays queued; the durable pending record settles it at the next touch.
	 *
	 * @return void
	 */
	public function verify_pending_handoffs() {
		foreach ( $this->pending_handoffs as $entry ) {
			$switched = is_multisite() && $entry['site_id'] !== get_current_blog_id();
			if ( $switched ) {
				switch_to_blog( $entry['site_id'] );
			}
			try {
				$this->verify_metadata_handoff( $entry['attachment_id'], $entry['token'] );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Complete a pending metadata handoff under a fresh lease, only while the
	 * journal's current record is that pending handoff (and carries $token when
	 * one is given), on the current site. A later operation that replaced the
	 * record makes this a no-op. The request's queued entry for the attachment
	 * is consumed once the lease is held, so a busy attempt leaves it for
	 * shutdown.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token Expected operation token, or '' for the current one.
	 * @return string verified, unverified (the stored metadata or files do not
	 *                match the evidence), write_failed (they match, but the
	 *                manifest or completion could not be persisted),
	 *                not_applicable, or busy (also when the lease was taken
	 *                over during the attempt).
	 */
	public function verify_metadata_handoff( $attachment_id, $token = '' ) {
		$attachment_id = (int) $attachment_id;
		$lease = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $lease );
		if ( $lock === false ) {
			return 'busy';
		}
		unset( $this->pending_handoffs[ get_current_blog_id() . ':' . $attachment_id ] );
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $lease, 'lock' => $lock, 'time_origin' => microtime( true ) ];
		try {
			$journal = $this->read_operation_journal( $attachment_id );
			$current = is_array( $journal ) && ! empty( $journal['current'] ) ? $journal['current'] : null;
			if ( ! $this->is_pending_handoff( $current ) || ( $token !== '' && $current['token'] !== $token ) ) {
				return 'not_applicable';
			}
			$operation['journal'] = $journal;

			$result = $this->complete_metadata_handoff( $operation );

			// Another request took the lease over; it owns the handoff now.
			return in_array( $result, [ 'lease_lost', 'pending' ], true ) ? 'busy' : $result;
		} finally {
			$this->release_operation_lock( $operation );
		}
	}

	/** @return bool Whether the record is an automatic apply waiting for its metadata handoff. */
	private function is_pending_handoff( $record ) {
		return is_array( $record ) && isset( $record['state'], $record['metadata_handoff'] ) && $record['state'] === 'metadata-commit' && $record['metadata_handoff'] === 'pending';
	}

	/**
	 * Complete the pending handoff of $operation['journal']['current'] under
	 * the caller's lease: verify the stored metadata and files against the
	 * evidence, write and verify the manifest, journal `complete` with the
	 * handoff verified, persist the success, and clean the retained stages and
	 * recovery sources. A failed verification writes nothing. A persistence
	 * failure after it passed leaves the record pending and, while the lease is
	 * still held, removes the manifest it wrote; if that removal fails too, the
	 * stored manifest is never trusted, because its record is not completed. A
	 * lost lease changes nothing more.
	 *
	 * @param array $operation Operation holding the lease, its journal read.
	 * @return string verified, unverified, write_failed, or lease_lost.
	 */
	private function complete_metadata_handoff( &$operation, $prepared = null ) {
		$attachment_id = $operation['attachment_id'];
		$record = $operation['journal']['current'];
		if ( $prepared === null ) { $prepared = $this->prepare_pending_handoff( $operation, $operation['journal'] ); }
		if ( $prepared === false || ! is_array( $prepared ) ) { return 'pending'; }
		$manifest = $prepared['manifest'];
		if ( $manifest === false ) { return 'unverified'; }
		if ( ! $this->begin_prefix_confirmed( $operation ) || $this->verified_manifest_from_evidence( $attachment_id, $record ) !== $manifest ) { return 'pending'; }
		if ( ! $this->write_watermark_manifest( $operation, $manifest ) ) {
			return $this->begin_prefix_confirmed( $operation ) && $this->discard_operation_manifest( $operation, $record['token'] ) ? 'write_failed' : 'lease_lost';
		}
		if ( ! $this->begin_prefix_confirmed( $operation ) || $this->verified_manifest_from_evidence( $attachment_id, $record ) !== $manifest ) { return 'pending'; }
		if ( ! $this->publish_operation_journal( $operation, $prepared['candidate'] ) ) {
			return empty( $operation['journal_uncertain'] ) && $this->begin_prefix_confirmed( $operation ) && $this->discard_operation_manifest( $operation, $record['token'] ) ? 'write_failed' : 'lease_lost';
		}
		$this->record_last_operation( $attachment_id, 'success', 'watermarked', __( 'Watermark applied.', 'image-watermark' ), isset( $record['context'] ) ? $record['context'] : 'auto-apply', $prepared['processed'], [], [], 'complete', $operation );
		if ( ! empty( $operation['name_stable_budget'] ) ) {
			$ack = $this->completed_recovery_candidate( $operation['journal'] );
			$failure = $this->operation_state_candidate( $operation, 'complete', [ 'cleanup_pending' => true ] );
			foreach ( [ $ack, $failure ] as $candidate ) {
				if ( ! $this->is_valid_operation_journal( $candidate ) || strlen( maybe_serialize( $candidate ) ) > self::NAME_STABILITY_JOURNAL_BUDGET ) { return 'verified'; }
			}
			if ( ! $this->begin_prefix_confirmed( $operation ) ) { return 'verified'; }
			$clean = $this->cleanup_owned_operation_stages( $operation, true );
			if ( $this->begin_prefix_confirmed( $operation ) ) {
				$this->publish_operation_journal( $operation, $clean ? $ack : $failure );
			}
		} elseif ( ! $this->cleanup_owned_operation_stages( $operation, true ) || ! $this->clear_completed_recovery_authority( $operation ) ) {
			$this->set_operation_state( $operation, 'complete', [ 'cleanup_pending' => true ] );
		}
		return 'verified';
	}

	/**
	 * Delete the stored manifest when it carries $token, only while the
	 * caller still holds its lease: a token match alone does not prove that a
	 * request which took the lease over did not write it.
	 *
	 * @param array  $operation Operation holding the lease.
	 * @param string $token Token of the manifest to discard.
	 * @return bool False when the lease was lost and nothing was deleted.
	 */
	private function discard_operation_manifest( &$operation, $token ) {
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		$stored = get_post_meta( $operation['attachment_id'], self::MANIFEST_META_KEY, true );
		if ( is_array( $stored ) && isset( $stored['token'] ) && $stored['token'] === $token ) {
			delete_post_meta( $operation['attachment_id'], self::MANIFEST_META_KEY );
		}

		return true;
	}

	/**
	 * Drift observer at the end of every metadata generation. A generation of
	 * a watermarked attachment that Image Watermark did not make (Regenerate
	 * Thumbnails, Simple Image Sizes, core's missing-size retry, any other
	 * caller) records a suspicion; nothing is hashed or changed here.
	 *
	 * The upload's own pass is recognized by its dispatch: at the pass's depth,
	 * the generation hook's dispatch count must have advanced since the render
	 * by exactly the nested dispatches observed deeper. A later dispatch at
	 * that depth (after an aborted or unobserved pass) counts itself, so it is
	 * foreign. The pass's observer then exempts the attachment's next write
	 * once, when it saves exactly the value the pass returned.
	 *
	 * @param mixed  $data Attachment metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Generation context.
	 * @return mixed Unchanged metadata.
	 */
	public function observe_metadata_generation( $data, $attachment_id, $context = '' ) {
		$attachment_id = (int) $attachment_id;
		$key = get_current_blog_id() . ':' . $attachment_id;
		$depth = $this->current_filter_depth();
		$count = did_filter( 'wp_generate_attachment_metadata' );
		// Any generation of the attachment ends the exemption of an earlier save.
		unset( $this->owned_metadata_saves[ $key ] );
		$owned = false;
		foreach ( $this->owned_metadata_passes as $mark_key => $mark ) {
			if ( $depth > $mark['depth'] ) {
				// A dispatch nested inside the pass's own, if that one still runs.
				$this->owned_metadata_passes[ $mark_key ]['nested']++;
				continue;
			}
			// At its depth or above, the pass's dispatch is this one or has ended.
			$owned = $owned || ( $mark_key === $key && $depth === $mark['depth'] && $count === $mark['count'] + $mark['nested'] );
			unset( $this->owned_metadata_passes[ $mark_key ] );
		}
		if ( $owned ) {
			$this->owned_metadata_saves[ $key ] = hash( 'sha256', serialize( $data ) );
			return $data;
		}
		if ( $attachment_id > 0 && $this->is_foreign_metadata_work( $attachment_id ) ) {
			$this->record_drift_suspicion( $attachment_id, 'wp_generate_attachment_metadata' );
		}

		return $data;
	}

	/**
	 * Drift observer on every metadata write. The save of an owned pass, the
	 * attachment's next write after its observer with no generation of it in
	 * between, is exempt once when it saves exactly the value the pass
	 * returned; any other write of the attachment ends that exemption. A write that Image
	 * Watermark did not make records a suspicion when it changes a file
	 * reference or dimension the stored manifest covers, or when its main file
	 * differs from the attached file. The comparison is in memory against the
	 * stored value.
	 *
	 * @param mixed $data New attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return mixed Unchanged metadata.
	 */
	public function observe_metadata_update( $data, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$key = get_current_blog_id() . ':' . $attachment_id;
		if ( isset( $this->owned_metadata_saves[ $key ] ) ) {
			$save = $this->owned_metadata_saves[ $key ];
			unset( $this->owned_metadata_saves[ $key ] );
			if ( hash( 'sha256', serialize( $data ) ) === $save ) {
				return $data;
			}
		}
		if ( $attachment_id <= 0 || ! $this->is_foreign_metadata_work( $attachment_id ) ) {
			return $data;
		}
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$diverged = is_array( $data ) && isset( $data['file'] ) && is_string( $data['file'] ) && is_string( $attached ) && $attached !== '' && ! $this->paths_match( wp_normalize_path( $data['file'] ), wp_normalize_path( $attached ) );
		$manifest = get_post_meta( $attachment_id, self::MANIFEST_META_KEY, true );
		if ( $diverged || ( $this->is_valid_manifest( $manifest ) && $this->metadata_changes_manifest_fields( $data, get_post_meta( $attachment_id, '_wp_attachment_metadata', true ), $manifest ) ) ) {
			$this->record_drift_suspicion( $attachment_id, 'wp_update_attachment_metadata' );
		}

		return $data;
	}

	/** @return bool Whether the attachment's current journal record is the pending handoff with this token. */
	private function is_owned_handoff_pending( $attachment_id, $token ) {
		$journal = $this->read_operation_journal( $attachment_id );

		return is_array( $journal ) && ! empty( $journal['current'] ) && $this->is_pending_handoff( $journal['current'] ) && is_string( $token ) && $token !== '' && $journal['current']['token'] === $token;
	}

	/**
	 * Whether metadata work on a watermarked attachment comes from outside
	 * Image Watermark: no internal generation, no operation lease held by this
	 * request, and no client-side finalize of the attachment running.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function is_foreign_metadata_work( $attachment_id ) {
		if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) !== 1 || $this->get_internal_operation_guard( $attachment_id ) || isset( $this->held_operation_leases[ get_current_blog_id() . ':' . $attachment_id ] ) ) {
			return false;
		}
		$phase = $this->current_client_side_phase();

		return ! ( is_array( $phase ) && $phase['phase'] === 'finalize' && (int) $phase['attachment_id'] === $attachment_id );
	}

	/**
	 * Whether a metadata value changes any field the manifest covers: the main
	 * file, width, and height, and the file, width, and height of every alias
	 * name.
	 *
	 * @param mixed $new New metadata.
	 * @param mixed $old Stored metadata.
	 * @param array $manifest Valid manifest.
	 * @return bool
	 */
	private function metadata_changes_manifest_fields( $new, $old, $manifest ) {
		$read = static function( $metadata, $name, $field ) {
			$entry = $name === null ? $metadata : ( is_array( $metadata ) && isset( $metadata['sizes'][ $name ] ) ? $metadata['sizes'][ $name ] : null );
			return is_array( $entry ) && isset( $entry[ $field ] ) && is_scalar( $entry[ $field ] ) ? (string) $entry[ $field ] : null;
		};
		$names = [ null ];
		foreach ( $manifest['aliases'] as $alias ) {
			$names = array_merge( $names, $alias['names'] );
		}
		foreach ( $names as $name ) {
			foreach ( [ 'file', 'width', 'height' ] as $field ) {
				if ( $read( $new, $name, $field ) !== $read( $old, $name, $field ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Record a drift suspicion without hashing, and queue its verification at
	 * shutdown. A signal whose marker write keeps losing is tried again by the
	 * next verification of the attachment, which keeps it queued until the
	 * signal is stored.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hook Hook that raised the signal.
	 * @return void
	 */
	private function record_drift_suspicion( $attachment_id, $hook ) {
		$site_id = get_current_blog_id();
		$key = $site_id . ':' . $attachment_id;
		if ( $this->persist_drift_signal( $attachment_id, $hook ) ) {
			unset( $this->drift_invalidation_pending[ $key ] );
		} else {
			$this->drift_invalidation_pending[ $key ] = $hook;
		}
		$this->suspected_drift[ $key ] = [ 'site_id' => $site_id, 'attachment_id' => $attachment_id ];
		if ( ! $this->drift_shutdown_scheduled ) {
			$this->drift_shutdown_scheduled = true;
			add_action( 'shutdown', [ $this, 'verify_suspected_drift' ], 6 );
		}
	}

	/**
	 * Store a signal. It reads the marker rows directly from the database and
	 * advances `last_seen` by compare-and-swap, strictly past the stored
	 * `last_seen` and `last_verified`, so no stored or in-flight verification
	 * result is current after it. It never discards evidence and never writes
	 * without the swap. A lost swap is resolved by reading again: a marker
	 * whose `last_seen` advanced since the first read was written after this
	 * signal's change, by another signal or a merge, and already invalidates
	 * every result that could predate it; anything else (a verification
	 * result, a cleared marker) is swapped again.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $hook Hook that raised the signal.
	 * @return bool Whether the signal is stored or answered by a newer one.
	 */
	private function persist_drift_signal( $attachment_id, $hook ) {
		$seen = null;
		for ( $attempt = 0; $attempt < self::DRIFT_SIGNAL_ATTEMPTS; $attempt++ ) {
			$row = $this->read_drift_marker_row( $attachment_id, true );
			if ( $row === false ) {
				continue;
			}
			if ( $row !== null ) {
				if ( $seen !== null && $row['marker']['last_seen'] > $seen ) {
					return true;
				}
				if ( $seen === null ) {
					$seen = $row['marker']['last_seen'];
				}
			}
			$next = $this->signalled_drift_marker( $row === null ? null : $row['marker'], $hook );
			if ( $row === null ? $this->add_drift_marker( $attachment_id, $next ) : $this->replace_drift_marker( $attachment_id, $row, $next ) === 'written' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The marker after a signal: a new suspicion, or the stored marker with
	 * this hook and a `last_seen` strictly past its `last_seen` and
	 * `last_verified`, re-bounded.
	 *
	 * @param array|null $marker Stored marker, or null.
	 * @param string     $hook Hook that raised the signal.
	 * @return array
	 */
	private function signalled_drift_marker( $marker, $hook ) {
		$now = time();
		if ( $marker === null ) {
			return [
				'version'        => self::DRIFT_VERSION,
				'state'          => 'suspected',
				'hook'           => $hook,
				'first_seen'     => $now,
				'last_seen'      => $now,
				'last_verified'  => null,
				'mismatch_count' => 0,
				'mismatches'     => [],
			];
		}

		return $this->bound_drift_marker( array_merge( $marker, [ 'hook' => $hook, 'last_seen' => max( $now, $marker['last_seen'] + 1, $marker['last_verified'] === null ? 0 : $marker['last_verified'] + 1 ) ] ) );
	}

	/**
	 * Keep a marker within its 8 KB bound: whole mismatch entries are left out
	 * from the end, never truncated, and `mismatch_count` keeps the true number.
	 *
	 * @param array $marker Marker.
	 * @return array
	 */
	private function bound_drift_marker( $marker ) {
		while ( ! empty( $marker['mismatches'] ) && strlen( serialize( $marker ) ) > self::DRIFT_MAX_BYTES ) {
			array_pop( $marker['mismatches'] );
		}

		return $marker;
	}

	/** @return bool Whether a marker's stored verification result still describes the newest signal. */
	private function drift_result_is_current( $marker ) {
		return $marker['last_verified'] !== null && $marker['last_verified'] >= $marker['last_seen'];
	}

	/**
	 * Verify every attachment this request suspected, after the triggering
	 * work has finished, each on its own site.
	 *
	 * @return void
	 */
	public function verify_suspected_drift() {
		foreach ( $this->suspected_drift as $entry ) {
			$switched = is_multisite() && $entry['site_id'] !== get_current_blog_id();
			if ( $switched ) {
				switch_to_blog( $entry['site_id'] );
			}
			try {
				$this->verify_derivative_drift( $entry['attachment_id'] );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Verify a suspected attachment against its manifest under a fresh lease,
	 * reading files at most once per attachment per request: once a file was
	 * read, a later call returns `already_verified` and reads nothing. Only the
	 * manifest's main and alias entries are read, each at most to its recorded
	 * size, never by modification time, and the request time is
	 * checked before every chunk. Any reference, size, or byte mismatch makes
	 * the marker `verified`; a full match leaves it `suspected`. Either result
	 * is stamped no earlier than the signal it answers and is written only if
	 * the marker row is still exactly the one it read, so a signal recorded
	 * meanwhile wins. Without a trusted manifest nothing is read. It changes no
	 * image file, metadata value, or flag.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string verified, matched, incomplete (time ran out; the result is
	 *                not current), unverifiable (no trusted manifest), none (no
	 *                marker), busy (the lease was held, or a newer signal
	 *                arrived), already_verified, or write_failed.
	 */
	public function verify_derivative_drift( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$key = get_current_blog_id() . ':' . $attachment_id;
		// A signal of this request whose marker write kept losing is stored
		// first; while it is not, the attachment stays queued.
		if ( isset( $this->drift_invalidation_pending[ $key ] ) ) {
			if ( ! $this->persist_drift_signal( $attachment_id, $this->drift_invalidation_pending[ $key ] ) ) {
				return 'busy';
			}
			unset( $this->drift_invalidation_pending[ $key ] );
		}
		if ( isset( $this->verified_drift[ $key ] ) ) {
			unset( $this->suspected_drift[ $key ] );
			return 'already_verified';
		}
		if ( $this->drift_time_remaining( $attachment_id ) < self::DRIFT_VERIFY_RESERVE ) {
			return 'incomplete';
		}
		$lease = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $lease );
		if ( $lock === false ) {
			return 'busy';
		}
		unset( $this->suspected_drift[ $key ] );
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $lease, 'lock' => $lock, 'time_origin' => microtime( true ) ];
		try {
			$row = $this->read_drift_marker_row( $attachment_id, true );
			if ( $row === false ) {
				return 'busy';
			}
			if ( $row === null ) {
				return 'none';
			}
			$status = $this->describe_manifest_status( $attachment_id );
			if ( ! in_array( $status['status'], [ 'trusted', 'settings_changed' ], true ) ) {
				return 'unverifiable';
			}
			$manifest = $status['manifest'];
			$metadata = wp_get_attachment_metadata( $attachment_id, true );
			$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$mismatches = [];
			$main_file = is_array( $metadata ) && isset( $metadata['file'] ) && is_string( $metadata['file'] ) ? $metadata['file'] : '';
			if ( ! is_string( $attached ) || $main_file === '' || ! $this->paths_match( wp_normalize_path( $main_file ), wp_normalize_path( $attached ) ) ) {
				$mismatches[] = [ 'name' => 'full', 'code' => 'attachment_file_diverged' ];
			}
			$entries = [ [ 'names' => [ 'full' ], 'file' => $manifest['main'], 'referenced' => $main_file !== '' && $this->paths_match( wp_normalize_path( $main_file ), $manifest['main']['path'] ) ] ];
			foreach ( $manifest['aliases'] as $alias ) {
				$referenced = true;
				foreach ( $alias['names'] as $name ) {
					$referenced = $referenced && isset( $metadata['sizes'][ $name ]['file'] ) && $metadata['sizes'][ $name ]['file'] === wp_basename( $alias['path'] );
				}
				$entries[] = [ 'names' => $alias['names'], 'file' => $alias, 'referenced' => $referenced ];
			}
			$incomplete = false;
			foreach ( $entries as $entry ) {
				if ( $this->drift_time_remaining( $attachment_id ) < self::DRIFT_VERIFY_RESERVE ) {
					$incomplete = true;
					break;
				}
				$code = $entry['referenced'] ? $this->drift_file_mismatch( $attachment_id, $entry['file'] ) : 'reference_changed';
				if ( $code === 'incomplete' ) {
					$incomplete = true;
					break;
				}
				if ( $code !== '' ) {
					foreach ( $entry['names'] as $name ) {
						$mismatches[] = [ 'name' => (string) $name, 'code' => $code ];
					}
				}
			}
			if ( empty( $mismatches ) && $incomplete ) {
				return 'incomplete';
			}
			$stamp = max( time(), $row['marker']['last_seen'] );
			$marker = empty( $mismatches )
				? array_merge( $row['marker'], [ 'state' => 'suspected', 'last_verified' => $stamp, 'mismatch_count' => 0, 'mismatches' => [] ] )
				: $this->drift_marker_with_mismatches( array_merge( $row['marker'], [ 'state' => 'verified', 'last_verified' => $stamp ] ), $mismatches );
			if ( ! $this->renew_operation_lock( $operation ) ) {
				return 'busy';
			}
			$written = $this->replace_drift_marker( $attachment_id, $row, $marker );
			if ( $written !== 'written' ) {
				return $written;
			}

			return empty( $mismatches ) ? 'matched' : 'verified';
		} finally {
			$this->release_operation_lock( $operation );
		}
	}

	/**
	 * The attachment's stored drift marker, read from the database without
	 * reloading the attachment's other meta. Several rows (concurrent first
	 * signals) are merged: the newest signal's fields and the newest
	 * verification's result, with `last_seen` past both. With `$collapse`, the
	 * merged value is written to the newest row and the other rows, and any
	 * malformed row, are removed, each only if it is still exactly the row
	 * that was read.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $collapse Whether to store the merge.
	 * @return array|null|false meta_id, raw value, and marker; null without a
	 *                          valid row; false when a collapse lost a race.
	 */
	private function read_drift_marker_row( $attachment_id, $collapse = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The exact stored rows are the compare-and-swap expectations; a full meta reload would also read the journal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC", $attachment_id, self::DRIFT_META_KEY ) );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return null;
		}
		$valid = [];
		$invalid = [];
		foreach ( $rows as $candidate ) {
			$marker = maybe_unserialize( $candidate->meta_value );
			$row = [ 'meta_id' => (int) $candidate->meta_id, 'raw' => $candidate->meta_value, 'marker' => $marker ];
			if ( $this->is_valid_drift_marker( $marker ) ) {
				$valid[] = $row;
			} else {
				$invalid[] = $row;
			}
		}
		if ( count( $valid ) === 1 && empty( $invalid ) ) {
			return $valid[0];
		}
		// The newest signal and the newest verification are merged: a newer
		// verification (match or mismatch) supersedes an older one, and a
		// collapse of several rows counts as a newer signal, so no verifier that
		// read one of them can still store its result.
		$keep = null;
		$signal = null;
		$verification = null;
		foreach ( $valid as $row ) {
			if ( $keep === null || $row['meta_id'] > $keep['meta_id'] ) {
				$keep = $row;
			}
			if ( $signal === null || $row['marker']['last_seen'] > $signal['last_seen'] ) {
				$signal = $row['marker'];
			}
			if ( $row['marker']['last_verified'] !== null && ( $verification === null || $row['marker']['last_verified'] > $verification['last_verified'] ) ) {
				$verification = $row['marker'];
			}
		}
		$merged = null;
		if ( $keep !== null ) {
			$first_seen = $signal['first_seen'];
			$latest = $signal['last_seen'];
			foreach ( $valid as $row ) {
				$first_seen = min( $first_seen, $row['marker']['first_seen'] );
				$latest = max( $latest, $row['marker']['last_seen'], (int) $row['marker']['last_verified'] );
			}
			$result = $verification === null ? [ 'state' => 'suspected', 'last_verified' => null, 'mismatch_count' => 0, 'mismatches' => [] ] : [ 'state' => $verification['state'], 'last_verified' => $verification['last_verified'], 'mismatch_count' => $verification['mismatch_count'], 'mismatches' => $verification['mismatches'] ];
			$merged = $this->bound_drift_marker( array_merge( $signal, $result, [ 'first_seen' => $first_seen, 'last_seen' => count( $rows ) > 1 ? $latest + 1 : $signal['last_seen'] ] ) );
		}
		if ( ! $collapse ) {
			return $keep === null ? null : array_merge( $keep, [ 'marker' => $merged ] );
		}
		if ( $keep !== null && $merged !== $keep['marker'] ) {
			if ( $this->replace_drift_marker( $attachment_id, $keep, $merged ) !== 'written' ) {
				return false;
			}
			$keep = [ 'meta_id' => $keep['meta_id'], 'raw' => maybe_serialize( $merged ), 'marker' => $merged ];
		}
		foreach ( array_merge( $valid, $invalid ) as $row ) {
			if ( $keep !== null && $row['meta_id'] === $keep['meta_id'] ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Removes a duplicate or malformed marker row only if it is still the row that was read.
			if ( $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND meta_value = %s", $row['meta_id'], $attachment_id, self::DRIFT_META_KEY, $row['raw'] ) ) !== 1 ) {
				wp_cache_delete( $attachment_id, 'post_meta' );
				return false;
			}
		}
		wp_cache_delete( $attachment_id, 'post_meta' );

		return $keep;
	}

	/**
	 * Add the first drift marker row, then merge a duplicate a concurrent
	 * first signal may have added.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $marker Marker.
	 * @return bool Whether a valid marker that answers this signal is stored.
	 */
	private function add_drift_marker( $attachment_id, $marker ) {
		add_post_meta( $attachment_id, self::DRIFT_META_KEY, $marker, true );
		$row = $this->read_drift_marker_row( $attachment_id, true );

		return is_array( $row ) && $row['marker']['last_seen'] >= $marker['last_seen'] && ! $this->drift_result_is_current( $row['marker'] );
	}

	/**
	 * Replace the drift marker row read before, only if it is still exactly
	 * that row: a write recorded meanwhile wins.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $row Row from read_drift_marker_row().
	 * @param array $marker New marker.
	 * @return string written, busy (the row changed or was removed), or
	 *                write_failed.
	 */
	private function replace_drift_marker( $attachment_id, $row, $marker ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact meta_id and raw-value compare-and-swap; metadata APIs cannot provide it.
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_id = %d AND post_id = %d AND meta_key = %s AND meta_value = %s", maybe_serialize( $marker ), $row['meta_id'], $attachment_id, self::DRIFT_META_KEY, $row['raw'] ) );
		wp_cache_delete( $attachment_id, 'post_meta' );
		if ( $updated === false ) {
			return 'write_failed';
		}

		return $updated === 1 ? 'written' : 'busy';
	}

	/**
	 * Compare one manifest file entry with the live file. The bytes are read
	 * only when the size still matches, through one counted stream that reads
	 * at most the recorded size and checks the request time before every
	 * chunk; a size that changed while reading is a mismatch. The first chunk
	 * read uses up the request's verification of the attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $file Manifest file entry.
	 * @return string '', file_missing, file_changed, or incomplete.
	 */
	private function drift_file_mismatch( $attachment_id, $file ) {
		$resolved = $this->resolve_upload_artifact_path( $file['path'] );
		if ( $resolved === false || ! is_file( $resolved['path'] ) ) {
			return 'file_missing';
		}
		clearstatcache( true, $resolved['path'] );
		if ( filesize( $resolved['path'] ) !== $file['size'] ) {
			return 'file_changed';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A counted read of a local upload file.
		$handle = fopen( $resolved['path'], 'rb' );
		if ( $handle === false ) {
			return 'file_missing';
		}
		$context = hash_init( 'sha256' );
		$read = 0;
		$code = '';
		while ( $read < $file['size'] ) {
			if ( $this->drift_time_remaining( $attachment_id ) < self::DRIFT_VERIFY_RESERVE ) {
				$code = 'incomplete';
				break;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- A counted read of a local upload file.
			$chunk = fread( $handle, min( 65536, $file['size'] - $read ) );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			$this->verified_drift[ get_current_blog_id() . ':' . (int) $attachment_id ] = true;
			$read += strlen( $chunk );
			hash_update( $context, $chunk );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the counted read.
		fclose( $handle );
		if ( $this->operation_fault_injector ) {
			// Test seam: the bytes this verification actually read.
			call_user_func( $this->operation_fault_injector, 'drift_verify_read', [ 'attachment_id' => $attachment_id, 'path' => $file['path'], 'bytes' => $read ] );
		}
		if ( $code !== '' ) {
			return $code;
		}
		clearstatcache( true, $resolved['path'] );

		return $read === $file['size'] && filesize( $resolved['path'] ) === $file['size'] && hash_final( $context ) === $file['sha256'] ? '' : 'file_changed';
	}

	/**
	 * Seconds left in this request, or PHP_INT_MAX without an execution limit.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return float|int
	 */
	private function drift_time_remaining( $attachment_id ) {
		$maximum = (int) ini_get( 'max_execution_time' );
		$remaining = PHP_INT_MAX;
		if ( $maximum > 0 ) {
			$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
			$remaining = $maximum - ( microtime( true ) - $start );
		}
		if ( $this->operation_fault_injector ) {
			$injected = call_user_func( $this->operation_fault_injector, 'drift_time_remaining', [ 'attachment_id' => $attachment_id, 'remaining' => $remaining ] );
			$remaining = is_float( $injected ) ? $injected : $remaining;
		}

		return $remaining;
	}

	/**
	 * Store mismatch entries in the marker within its bounds: entries that do
	 * not fit are left out whole, never truncated, and the count keeps the
	 * true number of entries found (one per size name and code).
	 *
	 * @param array   $marker Marker without its mismatch entries.
	 * @param array[] $mismatches Every mismatch found.
	 * @return array
	 */
	private function drift_marker_with_mismatches( $marker, $mismatches ) {
		$marker['mismatch_count'] = count( $mismatches );
		$marker['mismatches'] = [];
		foreach ( $mismatches as $mismatch ) {
			if ( count( $marker['mismatches'] ) >= self::DRIFT_MISMATCH_LIMIT ) {
				break;
			}
			$candidate = $marker;
			$candidate['mismatches'][] = $mismatch;
			if ( strlen( serialize( $candidate ) ) <= self::DRIFT_MAX_BYTES ) {
				$marker = $candidate;
			}
		}

		return $marker;
	}

	/**
	 * Validate a derivative drift marker against its registered shape and its
	 * state rules: a suspicion holds no mismatch, and verified drift has a
	 * verification time and a positive mismatch count.
	 *
	 * @param mixed $marker Marker value.
	 * @return bool
	 */
	private function is_valid_drift_marker( $marker ) {
		if ( ! is_array( $marker ) || ! isset( $marker['version'], $marker['state'], $marker['hook'], $marker['first_seen'], $marker['last_seen'], $marker['mismatch_count'], $marker['mismatches'] ) || ! array_key_exists( 'last_verified', $marker ) ) {
			return false;
		}
		if ( $marker['version'] !== self::DRIFT_VERSION || ! in_array( $marker['state'], [ 'suspected', 'verified' ], true ) || ! is_string( $marker['hook'] ) || ! is_int( $marker['first_seen'] ) || ! is_int( $marker['last_seen'] ) || ( $marker['last_verified'] !== null && ! is_int( $marker['last_verified'] ) ) || ! is_int( $marker['mismatch_count'] ) || ! is_array( $marker['mismatches'] ) || array_values( $marker['mismatches'] ) !== $marker['mismatches'] || count( $marker['mismatches'] ) > self::DRIFT_MISMATCH_LIMIT || count( $marker['mismatches'] ) > $marker['mismatch_count'] ) {
			return false;
		}
		if ( $marker['state'] === 'suspected' ? $marker['mismatch_count'] !== 0 : ( $marker['mismatch_count'] < 1 || $marker['last_verified'] === null ) ) {
			return false;
		}
		foreach ( $marker['mismatches'] as $mismatch ) {
			if ( ! is_array( $mismatch ) || ! isset( $mismatch['name'], $mismatch['code'] ) || ! is_string( $mismatch['name'] ) || ! is_string( $mismatch['code'] ) ) {
				return false;
			}
		}

		return strlen( serialize( $marker ) ) <= self::DRIFT_MAX_BYTES;
	}

	/**
	 * Describe the attachment's derivative drift marker without changing it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array state (none, suspected, or verified), current (the stored
	 *               verification result answers the newest signal, and this
	 *               request has no signal it could not store), verifiable
	 *               (a trusted manifest exists), and the marker's details.
	 */
	public function describe_derivative_drift( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$row = $this->read_drift_marker_row( $attachment_id );
		if ( ! is_array( $row ) ) {
			return [ 'state' => 'none', 'current' => false, 'verifiable' => false, 'last_verified' => null, 'mismatch_count' => 0, 'mismatches' => [] ];
		}
		$marker = $row['marker'];
		$status = $this->describe_manifest_status( $attachment_id );

		return [
			'state'          => $marker['state'],
			// A signal of this request not yet stored makes the stored result provisional.
			'current'        => $this->drift_result_is_current( $marker ) && ! isset( $this->drift_invalidation_pending[ get_current_blog_id() . ':' . $attachment_id ] ),
			'verifiable'     => in_array( $status['status'], [ 'trusted', 'settings_changed' ], true ),
			'last_verified'  => $marker['last_verified'],
			'mismatch_count' => $marker['mismatch_count'],
			'mismatches'     => $marker['mismatches'],
		];
	}

	/**
	 * Clear the drift marker after an operation that wrote every derivative
	 * it covers completed under the caller's lease.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function clear_derivative_drift( $attachment_id ) {
		unset( $this->suspected_drift[ get_current_blog_id() . ':' . (int) $attachment_id ], $this->drift_invalidation_pending[ get_current_blog_id() . ':' . (int) $attachment_id ] );
		delete_post_meta( $attachment_id, self::DRIFT_META_KEY );
	}

	/**
	 * Delete only the captured manifest rows while this exact lease still exists.
	 * Core's ID-only delete cannot protect a successor installed by a pre-action.
	 *
	 * @return string handled, deleted, lost, or failed.
	 */
	private function delete_completed_remove_manifest( &$operation ) {
		global $wpdb;
		$id = (int) $operation['attachment_id'];
		$key = self::MANIFEST_META_KEY;
		$handled = apply_filters( 'delete_post_metadata', null, $id, $key, '', false );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->completion_lease_failure( $operation );
		}
		if ( $handled !== null ) {
			return 'handled';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, $key ), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return 'failed';
		}
		if ( ! $rows ) {
			return 'handled';
		}
		$ids = array_column( $rows, 'meta_id' );
		do_action( 'delete_post_meta', $ids, $id, $key, '' );
		do_action( 'delete_postmeta', $ids );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->completion_lease_failure( $operation );
		}
		$deleted = [];
		$status = 'deleted';
		foreach ( $rows as $row ) {
			// Both comparisons are byte-exact; the joined lease participates in
			// the destructive statement, not merely in the preceding heartbeat.
			$count = $wpdb->query( $wpdb->prepare(
				"DELETE m FROM {$wpdb->postmeta} AS m INNER JOIN {$wpdb->options} AS l ON l.option_name = %s AND BINARY l.option_value = BINARY %s WHERE m.post_id = %d AND m.meta_key = %s AND m.meta_id = %d AND BINARY m.meta_value = BINARY %s",
				$this->operation_lock_name( $id ), maybe_serialize( $operation['lock'] ), $id, $key, $row['meta_id'], $row['meta_value']
			) );
			if ( $count !== 1 ) {
				$status = $count === false ? 'failed' : $this->completion_lease_failure( $operation );
				break;
			}
			$deleted[] = $row['meta_id'];
		}
		if ( $deleted ) {
			wp_cache_delete_multiple( [ $id ], 'post_meta' );
			do_action( 'deleted_post_meta', $deleted, $id, $key, '' );
			do_action( 'deleted_postmeta', $deleted );
			if ( ! $this->renew_operation_lock( $operation ) ) {
				return $this->completion_lease_failure( $operation );
			}
		}
		return $status;
	}

	/** Distinguish a verified replacement from a failed or inconclusive query. */
	private function completion_lease_failure( $operation ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->operation_lock_name( $operation['attachment_id'] ) ) );
		if ( $wpdb->last_error ) {
			return 'failed';
		}
		return $value !== maybe_serialize( $operation['lock'] ) ? 'lost' : 'failed';
	}

	/** @return array */
	private function operation_complete( &$operation, $data, $attachment_id, $method, $context, $code, $message, $processed, $skipped = [] ) {
		try {
			$details = [ 'code' => $code, 'metadata_committed' => true, 'counts' => [ 'processed' => count( $processed ), 'failed' => 0 ] ];
			if ( ! empty( $operation['name_stable_budget'] ) ) {
				$details['cleanup_pending'] = true;
			}
			$published = isset( $operation['clean_remove_completion'] )
				? $this->publish_operation_journal( $operation, $operation['clean_remove_completion'] )
				: $this->set_operation_state( $operation, 'complete', $details );
		} catch ( \Throwable $error ) {
			if ( ! isset( $operation['clean_remove_completion'] ) ) {
				throw $error;
			}
			$published = false;
		}
		if ( ! $published ) {
			if ( isset( $operation['clean_remove_completion'] ) ) {
				$message = __( 'The clean image layout was restored, but completion could not be safely recorded. Retry the removal.', 'image-watermark' );
				$result = $this->operation_result( 'interrupted', $operation['token'], 'journal_write_failed', $message, true, [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => [] ] );
				return $this->operation_failure( $data, $method, $attachment_id, $context, $result );
			}
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermarked files were prepared, but completion could not be safely recorded.', 'image-watermark' ), true, $processed );
		}
		// A completed journal is historical fact, not authority to touch a later
		// owner's state after any callback-capable metadata operation returns.
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
		}
		$this->record_last_operation( $attachment_id, 'success', $code, $message, $context, $processed, [], $skipped, 'complete', $operation );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
		}
		// The completed operation wrote every derivative it covers itself.
		$this->clear_derivative_drift( $attachment_id );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
		}
		if ( isset( $operation['journal']['current']['type'] ) && $operation['journal']['current']['type'] === 'remove' ) {
			// A completed remove ends the manifest's life; any other operation
			// already made it untrusted by replacing the current record.
			$manifest_delete = $this->delete_completed_remove_manifest( $operation );
			if ( $manifest_delete === 'lost' ) {
				return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
			}
			if ( $manifest_delete === 'failed' ) {
				$message = __( 'The operation completed, but its current attachment state could not be confirmed. Check the image before retrying.', 'image-watermark' );
				$result = $this->operation_result( 'interrupted', $operation['token'], 'metadata_write_failed', $message, true, [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => [] ] );
				return $this->operation_failure( $data, $method, $attachment_id, $context, $result );
			}
			if ( ! $this->renew_operation_lock( $operation ) ) {
				return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
			}
		}
		if ( in_array( $context, [ 'manual-apply', 'manual-remove' ], true ) && ! $this->settle_client_side_claim_after_manual( $attachment_id, $context, $operation ) ) {
			return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
		}
		if ( ! $this->cleanup_owned_operation_stages( $operation, true ) ) {
			$this->set_operation_state( $operation, 'complete', [ 'cleanup_pending' => true ] );
		} elseif ( ! $this->clear_completed_recovery_authority( $operation ) ) {
			$this->set_operation_state( $operation, 'complete', [ 'cleanup_pending' => true ] );
		}
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped );
		}
		$this->operation_result( 'complete', $operation['token'], $code, $message, false, [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => [] ] );
		return $data;
	}

	/** Report loss without rewriting the completed journal or a successor's state. */
	private function completed_operation_lost_fence( $operation, $data, $method, $context, $processed, $skipped ) {
		$message = __( 'The operation completed, but its current attachment state could not be confirmed. Check the image before retrying.', 'image-watermark' );
		$result = $this->operation_result( 'interrupted', $operation['token'], 'completion_fence_lost', $message, true, [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => [] ] );
		return $this->operation_failure( $data, $method, $operation['attachment_id'], $context, $result );
	}

	/**
	 * Demote a spent recoverable predecessor into bounded history. Its temporary
	 * recovery artifacts are gone by this point, so it must stop advertising
	 * recovery authority while still leaving evidence that it ran.
	 *
	 * @param array $journal Journal value.
	 * @return array
	 */
	private function retire_recoverable_predecessor( $journal ) {
		$retired = ! empty( $journal['recoverable_predecessor'] ) && is_array( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
		$journal['recoverable_predecessor'] = null;
		if ( $retired === null || empty( $retired['token'] ) || ! is_string( $retired['token'] ) ) {
			return $journal;
		}
		if ( ! empty( $journal['current']['token'] ) && $journal['current']['token'] === $retired['token'] ) {
			return $journal;
		}
		$journal['history'] = ! empty( $journal['history'] ) && is_array( $journal['history'] ) ? $journal['history'] : [];
		foreach ( $journal['history'] as $record ) {
			if ( ! empty( $record['token'] ) && $record['token'] === $retired['token'] ) {
				return $journal;
			}
		}
		$compact = $this->compact_operation_record( $retired );
		foreach ( $compact['targets'] as $index => $target ) {
			unset( $target['stage'], $target['recovery'] );
			$compact['targets'][ $index ] = $target;
		}
		$journal['history'][] = $compact;
		$journal['history'] = array_slice( $journal['history'], -2 );

		return $journal;
	}

	/** Construct the exact acknowledgement after completed recovery cleanup. */
	private function completed_recovery_candidate( $journal ) {
		foreach ( $journal['current']['targets'] as $index => $target ) {
			unset( $target['stage'], $target['recovery'] );
			$journal['current']['targets'][ $index ] = $target;
		}
		unset( $journal['current']['temporary_recovery_restore'], $journal['current']['main_recovery'] );
		$journal['current']['cleanup_pending'] = false;
		return $this->retire_recoverable_predecessor( $journal );
	}

	/** @return bool */
	private function clear_completed_recovery_authority( &$operation ) {
		$journal = $this->read_operation_journal( $operation['attachment_id'] );
		// Reconciling an interrupted predecessor completes that predecessor's own
		// record under this owner's fence, so the completed token is either ours or
		// the adopted record we just wrote.
		$owned_tokens = [ $operation['token'] ];
		if ( ! empty( $operation['journal']['current']['token'] ) && is_string( $operation['journal']['current']['token'] ) ) {
			$owned_tokens[] = $operation['journal']['current']['token'];
		}
		if ( $journal === false || empty( $journal['current'] ) || ! in_array( $journal['current']['token'], $owned_tokens, true ) || $journal['current']['state'] !== 'complete' ) {
			return false;
		}
		$operation['journal'] = $journal;
		return $this->publish_operation_journal( $operation, $this->completed_recovery_candidate( $journal ) );
	}

	/** @return bool */
	private function cleanup_owned_operation_stages( &$operation, $cleanup_recovery = false ) {
		$journal = $this->read_operation_journal( $operation['attachment_id'] );
		if ( $journal === false || ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		$records = [ isset( $journal['current'] ) ? $journal['current'] : null, isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ];
		if ( ! empty( $journal['history'] ) && is_array( $journal['history'] ) ) {
			$records = array_merge( $records, $journal['history'] );
		}
		// A verified completion ends every older recovery obligation. A failed
		// or interrupted owner keeps all remaining main-file authority.
		$current = isset( $journal['current'] ) ? $journal['current'] : null;
		$completed = is_array( $current ) && isset( $current['token'], $current['state'] ) && $current['state'] === 'complete' && ! empty( $current['metadata_committed'] ) && ( $current['token'] === $operation['token'] || ( isset( $operation['journal']['current']['token'] ) && $current['token'] === $operation['journal']['current']['token'] ) );
		$clean = true;
		foreach ( $records as $record ) {
			if ( ! $this->cleanup_operation_record_artifacts( $operation, $record, $cleanup_recovery ) ) {
				$clean = false;
			}
			// A verified completion ends every older recovery obligation.
			if ( $cleanup_recovery && $completed && ! $this->cleanup_main_recovery( $operation, $record ) ) {
				$clean = false;
			}
		}
		return $clean;
	}

	/**
	 * A conservative point-in-time reference check, not an ownership or
	 * synchronization proof. An error retains the stage.
	 *
	 * @param string $relative Upload-relative stage name.
	 * @return bool Whether the stage must be retained.
	 */
	private function predecessor_stage_may_be_referenced( $relative ) {
		global $wpdb;
		// Include the operated attachment and sideload references. A basename
		// match in another directory is intentionally retained as well.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Conservative check before stage cleanup.
		$owner = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wp_attached_file', '_wp_attachment_metadata', '_wp_sideloaded_file' ) AND LOWER(meta_value) LIKE %s LIMIT 1",
			'%' . $wpdb->esc_like( strtolower( wp_basename( $relative ) ) ) . '%'
		) );
		return $owner !== null || $wpdb->last_error !== '';
	}

	/** @return bool */
	private function cleanup_operation_record_artifacts( &$operation, $record, $cleanup_recovery = false ) {
		if ( ! is_array( $record ) || empty( $record['token'] ) ) {
			return true;
		}
		$clean = true;
		$targets = ! empty( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [];
		if ( ! empty( $record['restoration'] ) && is_array( $record['restoration'] ) && ! empty( $record['restoration']['stage'] ) ) {
			$targets[] = $record['restoration'];
		}
		if ( ! empty( $record['temporary_recovery_restore'] ) && is_array( $record['temporary_recovery_restore'] ) && ! empty( $record['temporary_recovery_restore']['stage'] ) ) {
			$targets[] = $record['temporary_recovery_restore'];
		}
		$targets = array_merge( $targets, $this->name_stable_remap_artifacts( $record ) );
		$retained_stages = [];
		// Keep verified stages that prove this failed generation never promoted
		// its targets. Cleanup by the failed owner would make their absence look
		// like promotion to the next retry. A retry may clean them after reading
		// that proof, unless another reference still prevents cleanup.
		$keep_unpromoted = false;
		if ( isset( $record['state'], $record['generation_stage'] ) && $record['state'] === 'failed' && $this->is_valid_generation_stage( $record['generation_stage'], $record['token'] ) && $this->generation_record_promoted_nothing( $record ) && ! $this->operation_record_mutated_derivatives( $record ) ) {
			$keep_unpromoted = $record['token'] === $operation['token'];
			foreach ( $targets as $target ) {
				if ( ! empty( $target['stage'] ) && $this->predecessor_stage_may_be_referenced( $target['stage'] ) ) {
					$keep_unpromoted = true;
					break;
				}
			}
		}
		foreach ( $targets as $target ) {
			if ( empty( $target['stage'] ) || strpos( basename( $target['stage'] ), '.iw-stage-' . $record['token'] . '-' ) !== 0 ) {
				continue;
			}
			// Even a malformed marker must not grant target cleanup authority.
			if ( isset( $record['generation_stage'] ) && is_array( $record['generation_stage'] ) && array_key_exists( 'pre_generation_retention', $record['generation_stage'] ) ) {
				$clean = false;
				continue;
			}
			$legacy_restoration = $this->target_is_legacy_restoration( $target, $record['token'] );
			if ( empty( $target['target'] ) || ( ( ! isset( $target['root'] ) || $target['root'] !== 'uploads' ) && ! $legacy_restoration ) ) {
				$clean = false;
				continue;
			}
			$stage_record = $this->resolve_upload_artifact_path( $target['stage'] );
			$target_record = $this->resolve_upload_artifact_path( $target['target'] );
			if ( $stage_record === false || $target_record === false || $stage_record['relative'] !== $target['stage'] || $target_record['relative'] !== $target['target'] || $stage_record['parent'] !== $target_record['parent'] ) {
				$clean = false;
				continue;
			}
			// Current generation targets also reach this exact cleanup before the
			// guarded generation sweep. Initial-apply cleanup remains unchanged.
			$is_generation = isset( $record['generation_stage'] ) && $this->is_valid_generation_stage( $record['generation_stage'], $record['token'] );
			if ( is_file( $stage_record['path'] ) && ( ( $keep_unpromoted && isset( $target['state'] ) && $target['state'] === 'staged' ) || ( ( $record['token'] !== $operation['token'] || $is_generation ) && $this->predecessor_stage_may_be_referenced( $target['stage'] ) ) ) ) {
				// The generation exact-name and prefix cleanup below can reach this
				// same path. Preserve it there too, and retain journal authority.
				$retained_stages[] = $stage_record['path'];
				$clean = false;
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only operation-owned unpromoted stage files.
			if ( is_file( $stage_record['path'] ) && ( $this->should_inject_operation_fault( 'stage_cleanup', [ 'attachment_id' => $operation['attachment_id'], 'token' => $record['token'], 'stage' => $target['stage'] ] ) || ! $this->renew_operation_lock( $operation ) || ! @unlink( $stage_record['path'] ) ) ) {
				$clean = false;
			}
		}
		if ( ! empty( $record['backup']['stage'] ) && is_string( $record['backup']['stage'] ) && strpos( basename( $record['backup']['stage'] ), '.iw-stage-' . $record['token'] . '-' ) === 0 ) {
			$backup_root = isset( $record['backup']['root'] ) && $record['backup']['root'] === 'backup';
			$stage_record = $backup_root ? $this->resolve_backup_path( $record['backup']['stage'] ) : $this->resolve_upload_artifact_path( $record['backup']['stage'] );
			if ( $stage_record !== false && $stage_record['relative'] === $record['backup']['stage'] ) {
				// A record that names a backup file must prove the stage is its sibling.
				// An allocated record has no backup file yet, so the token-owned stage
				// resolved inside the backup root is the only ownership proof available.
				$has_backup_path = ! empty( $record['backup']['path'] ) && is_string( $record['backup']['path'] );
				$backup_record = $has_backup_path ? $this->resolve_backup_path( $record['backup']['path'] ) : false;
				if ( $backup_root && $has_backup_path && ( $backup_record === false || $backup_record['parent'] !== $stage_record['parent'] ) ) {
					$clean = false;
					$stage_record = false;
				}
				if ( $stage_record !== false && ! empty( $record['main_recovery'] ) && is_file( $stage_record['path'] ) && $this->predecessor_stage_may_be_referenced( $record['backup']['stage'] ) ) {
					$clean = false;
					$stage_record = false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only operation-owned backup-root or legacy uploads-root stage files.
				if ( $stage_record !== false && is_file( $stage_record['path'] ) && ( $this->should_inject_operation_fault( 'stage_cleanup', [ 'attachment_id' => $operation['attachment_id'], 'token' => $record['token'], 'stage' => $record['backup']['stage'] ] ) || ! $this->renew_operation_lock( $operation ) || ! @unlink( $stage_record['path'] ) ) ) {
					$clean = false;
				}
			}
		}
		if ( ! $this->cleanup_generation_stage( $operation, $record, $retained_stages ) ) {
			$clean = false;
		}
		if ( $cleanup_recovery ) {
			foreach ( $targets as $target ) {
				if ( empty( $target['recovery'] ) || ! is_array( $target['recovery'] ) || ! isset( $target['recovery']['root'], $target['recovery']['path'] ) || $target['recovery']['root'] !== 'uploads' || strpos( basename( $target['recovery']['path'] ), '.iw-recovery-' . $record['token'] . '-' ) !== 0 ) {
					continue;
				}
				$recovery = $this->resolve_upload_artifact_path( $target['recovery']['path'] );
				$target_record = ! empty( $target['target'] ) ? $this->resolve_upload_artifact_path( $target['target'] ) : false;
				if ( $recovery === false || $target_record === false || $recovery['relative'] !== $target['recovery']['path'] || $target_record['relative'] !== $target['target'] || $recovery['parent'] !== $target_record['parent'] ) {
					$clean = false;
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a verified token-owned temporary recovery file after terminal completion.
				if ( is_file( $recovery['path'] ) && ( $this->should_inject_operation_fault( 'recovery_cleanup', [ 'attachment_id' => $operation['attachment_id'], 'token' => $record['token'], 'recovery' => $target['recovery']['path'] ] ) || ! $this->renew_operation_lock( $operation ) || ! @unlink( $recovery['path'] ) ) ) {
					$clean = false;
				}
			}
		}
		if ( $cleanup_recovery && isset( $record['state'] ) && $record['state'] === 'complete' && ! empty( $record['metadata_committed'] ) && ! $this->cleanup_main_recovery( $operation, $record ) ) { $clean = false; }
		return $clean;
	}

	/**
	 * A worker that lost its fence may remove only its uniquely token-named,
	 * unpromoted upload artifact. This closes the deletion-after-allocation race
	 * without granting it authority over a new owner's files.
	 *
	 * @return void
	 */
	private function cleanup_lost_upload_artifact( $path, $token, $prefix ) {
		$upload_dir = wp_upload_dir();
		$relative = $this->operation_planned_stage_relative_path( $path, $upload_dir, false );
		if ( $relative === false || strpos( basename( $relative ), $prefix . $token . '-' ) !== 0 ) {
			return;
		}
		$artifact = $this->resolve_upload_artifact_path( $relative );
		if ( $artifact === false || $artifact['relative'] !== $relative || ! is_file( $artifact['path'] ) ) {
			return;
		}
		if ( $this->should_inject_operation_fault( 'eager_stage_cleanup', [ 'token' => $token, 'stage' => $relative ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A fence-losing worker removes only its own unpromoted token-named artifact.
		@unlink( $artifact['path'] );
	}

	/** @return void */
	private function cleanup_lost_backup_artifact( $path, $token, $retain_references = false ) {
		$relative = $this->operation_root_relative_path( $path, $this->get_backup_root_dir() );
		if ( $relative === false || strpos( basename( $relative ), '.iw-stage-' . $token . '-' ) !== 0 ) {
			return;
		}
		$artifact = $this->resolve_backup_path( $relative );
		if ( $artifact === false || $artifact['relative'] !== $relative || ! is_file( $artifact['path'] ) ) {
			return;
		}
		// This check is conservative and point-in-time, not an unlink fence.
		if ( $retain_references && $this->predecessor_stage_may_be_referenced( $relative ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A fence-losing worker removes only its own unpromoted backup-root stage.
		@unlink( $artifact['path'] );
	}

	/**
	 * Snapshot the attachment's existing derivative paths for recovery. WordPress
	 * owns derivative generation; the caller journals, fences, and commits the
	 * regenerated metadata before reporting completion.
	 *
	 * @return array|false
	 */
	private function snapshot_registered_derivatives( $metadata, $original_file, $upload_dir ) {
		$paths = [];
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
			return $paths;
		}

		$directory = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . dirname( $metadata['file'] );
		$original_image = ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ? $metadata['original_image'] : '';
		foreach ( $metadata['sizes'] as $name => $size ) {
			if ( ! is_string( $name ) || $name === '' || ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) || $size['file'] === $original_image ) {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $size['file'];
			// These names are WordPress full-image/edit recovery artifacts, not
			// independently owned derivatives that this operation may retire.
			if ( wp_normalize_path( $path ) === wp_normalize_path( $original_file ) || preg_match( '/-(?:scaled|e[0-9]{13,})(?:\.[^.]+)?$/', basename( $path ) ) ) {
				continue;
			}
			$identity = $this->operation_path_identity( $path, $upload_dir );
			if ( $identity !== false ) {
				$paths[ $name ] = $identity['relative'];
			}
		}

		return $paths;
	}

	/**
	 * Capture the attachment's pre-operation map before any mutation.
	 * Paths use the same exclusions as snapshot_registered_derivatives().
	 *
	 * @return array|false
	 */
	private function snapshot_pre_operation( $attachment_id, $metadata, $main_path, $upload_dir ) {
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_array( $metadata ) || ! is_string( $attached ) || $this->normalize_operation_relative_path( $attached ) !== $attached ) {
			return false;
		}
		$sizes = [];
		foreach ( $this->snapshot_registered_derivatives( $metadata, $main_path, $upload_dir ) as $name => $relative ) {
			$entry = $metadata['sizes'][ $name ];
			$sizes[ $name ] = [
				'path'   => $relative,
				'width'  => isset( $entry['width'] ) ? max( 0, (int) $entry['width'] ) : 0,
				'height' => isset( $entry['height'] ) ? max( 0, (int) $entry['height'] ) : 0,
				'mime'   => isset( $entry['mime-type'] ) && is_string( $entry['mime-type'] ) ? $entry['mime-type'] : (string) wp_check_filetype( $relative )['type'],
			];
		}
		$original = '';
		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && basename( $metadata['original_image'] ) === $metadata['original_image'] && is_file( dirname( $main_path ) . DIRECTORY_SEPARATOR . $metadata['original_image'] ) ) {
			$original = $metadata['original_image'];
		}
		// Identity is the actual file. Metadata dimensions can be stale, so
		// they are used only when the main file cannot be read.
		$dimensions = is_file( $main_path ) ? @getimagesize( $main_path ) : false;
		if ( $dimensions ) {
			$width = (int) $dimensions[0];
			$height = (int) $dimensions[1];
		} else {
			$width = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
			$height = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;
		}
		return [
			'file'           => $attached,
			'width'          => max( 0, $width ),
			'height'         => max( 0, $height ),
			'mime'           => (string) wp_check_filetype( $attached )['type'],
			'original_image' => $original,
			'sizes'          => $sizes,
			'candidates'     => [],
			'inherited'      => [],
		];
	}

	/** @return bool */
	private function is_valid_pre_operation( $pre ) {
		if ( ! is_array( $pre ) || ! isset( $pre['file'], $pre['sizes'] ) || ! is_string( $pre['file'] ) || $this->normalize_operation_relative_path( $pre['file'] ) !== $pre['file'] || ! is_array( $pre['sizes'] ) || count( $pre['sizes'] ) > 200 ) {
			return false;
		}
		foreach ( [ 'width', 'height' ] as $key ) {
			if ( ! isset( $pre[ $key ] ) || ! is_int( $pre[ $key ] ) || $pre[ $key ] < 0 ) {
				return false;
			}
		}
		if ( ! isset( $pre['mime'], $pre['original_image'] ) || ! is_string( $pre['mime'] ) || ! is_string( $pre['original_image'] ) || ( $pre['original_image'] !== '' && ( basename( $pre['original_image'] ) !== $pre['original_image'] || $this->normalize_operation_relative_path( $pre['original_image'] ) === false ) ) ) {
			return false;
		}
		foreach ( $pre['sizes'] as $name => $size ) {
			if ( ! is_string( $name ) || $name === '' || ! is_array( $size ) || ! isset( $size['path'], $size['width'], $size['height'], $size['mime'] ) || ! is_string( $size['path'] ) || $this->normalize_operation_relative_path( $size['path'] ) !== $size['path'] || ! is_int( $size['width'] ) || ! is_int( $size['height'] ) || $size['width'] < 0 || $size['height'] < 0 || ! is_string( $size['mime'] ) ) {
				return false;
			}
		}
		if ( ! isset( $pre['candidates'], $pre['inherited'] ) || ! is_array( $pre['candidates'] ) || ! is_array( $pre['inherited'] ) || count( $pre['inherited'] ) > self::PRE_OPERATION_TOKEN_LIMIT ) {
			return false;
		}
		foreach ( $pre['candidates'] as $name => $paths ) {
			if ( ! is_string( $name ) || ! is_array( $paths ) || count( $paths ) > self::PRE_OPERATION_CANDIDATE_LIMIT ) {
				return false;
			}
			foreach ( $paths as $path ) {
				if ( ! is_string( $path ) || $this->normalize_operation_relative_path( $path ) !== $path ) {
					return false;
				}
			}
		}
		foreach ( $pre['inherited'] as $token ) {
			if ( ! is_string( $token ) || $token === '' ) {
				return false;
			}
		}
		return ! isset( $pre['stale'] ) || is_bool( $pre['stale'] );
	}

	/** @return bool */
	private function is_valid_name_stable_remap( $remap ) {
		if ( ! is_array( $remap ) || count( $remap ) > 200 ) {
			return false;
		}
		foreach ( $remap as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['names'] ) || ! is_array( $entry['names'] ) || ! isset( $entry['from'], $entry['to'], $entry['state'] ) || ! is_string( $entry['from'] ) || ! is_string( $entry['to'] ) || ! in_array( $entry['state'], [ 'planned', 'allocated', 'staged', 'promoted' ], true ) ) {
				return false;
			}
			$from = $this->normalize_operation_relative_path( $entry['from'] );
			$to = $this->normalize_operation_relative_path( $entry['to'] );
			if ( $from !== $entry['from'] || $to !== $entry['to'] || ( isset( $entry['size'] ) && ( ! is_int( $entry['size'] ) || $entry['size'] < 0 ) ) || ( isset( $entry['hash'] ) && ( ! is_string( $entry['hash'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $entry['hash'] ) ) ) || ( isset( $entry['selected'] ) && ! is_bool( $entry['selected'] ) ) ) {
				return false;
			}
			foreach ( $entry['names'] as $name ) {
				if ( ! is_string( $name ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** @return bool */
	private function operation_record_committed_metadata( $record ) {
		return is_array( $record ) && ( ! empty( $record['metadata_committed'] ) || ( isset( $record['regenerated_metadata_commit']['state'] ) && $record['regenerated_metadata_commit']['state'] === 'committed' ) );
	}

	/**
	 * Evidence that a record changed live files beyond a refused preflight: a
	 * promoted restoration, or a reached regeneration or reconciliation state.
	 *
	 * @return bool
	 */
	private function operation_record_mutated_derivatives( $record, $read_only = false ) {
		// A retry may restore its main before token-named generation starts.
		if ( is_array( $record ) && isset( $record['restoration']['state'] ) && $record['restoration']['state'] === 'promoted' ) {
			return true;
		}
		$restoration = isset( $record['restoration'] ) && is_array( $record['restoration'] ) ? $record['restoration'] : null;
		if ( $this->record_has_main_recovery( $record, true ) && is_array( $restoration ) && isset( $restoration['state'], $restoration['stage'], $restoration['target'], $restoration['size'], $restoration['hash'] ) && $restoration['state'] === 'staged' ) {
			$recovery = $record['main_recovery'];
			$failure = null;
			$stage = $this->resolve_upload_artifact_path( $restoration['stage'], false, '', 0, $failure, $read_only );
			$main = $this->resolve_upload_artifact_path( $recovery['target'], false, '', 0, $failure, $read_only );
			// A discarded, unpromoted stage is not a mutation. A consumed stage
			// plus the known clean main proves the restore's rename happened.
			if ( $restoration['target'] === $recovery['target'] && $restoration['size'] === $recovery['size'] && $restoration['hash'] === $recovery['sha256'] && $stage !== false && ! file_exists( $stage['path'] ) && $main !== false && is_file( $main['path'] ) && is_readable( $main['path'] ) && filesize( $main['path'] ) === $recovery['size'] && hash_file( 'sha256', $main['path'] ) === $recovery['sha256'] ) {
				return true;
			}
		}
		if ( is_array( $record ) && isset( $record['generation_stage'] ) ) {
			// A token-named generation changes live files only by promotion, a
			// rename that moves the target's stage away. A crash between a rename
			// and its journal write leaves the target `staged` without its stage
			// file, which therefore counts (its promoted file may since have been
			// moved or changed); a staged target whose stage is still there was not
			// promoted, whatever the live file holds.
			foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
				if ( isset( $target['state'] ) && $target['state'] === 'promoted' ) {
					return true;
				}
				if ( isset( $target['state'] ) && $target['state'] === 'staged' ) {
					$failure = null;
					$stage = empty( $target['stage'] ) ? false : $this->resolve_upload_artifact_path( $target['stage'], false, '', 0, $failure, $read_only );
					if ( $stage === false || ! is_file( $stage['path'] ) ) {
						return true;
					}
				}
			}
			return isset( $record['derivative_recovery']['retired'] ) && ! empty( $record['derivative_recovery']['retired'] );
		}
		return is_array( $record ) && ( ( isset( $record['regeneration'] ) && is_array( $record['regeneration'] ) ) || ( isset( $record['derivative_recovery'] ) && is_array( $record['derivative_recovery'] ) ) || ( isset( $record['restoration']['state'] ) && $record['restoration']['state'] === 'promoted' ) );
	}

	/**
	 * Path-only map of a 2.0.16 or early 2.0.17 record without pre_operation.
	 * Dimensions come from the old file when it still exists; an unknown size is
	 * never remapped.
	 *
	 * @return array|null
	 */
	private function legacy_pre_operation( $record, $attachment_id, $read_only = false ) {
		$paths = [];
		if ( isset( $record['regeneration']['previous_derivatives'] ) && is_array( $record['regeneration']['previous_derivatives'] ) && ! empty( $record['regeneration']['previous_derivatives'] ) ) {
			$paths = $record['regeneration']['previous_derivatives'];
		} elseif ( isset( $record['derivative_recovery']['previous'] ) && is_array( $record['derivative_recovery']['previous'] ) ) {
			$paths = $record['derivative_recovery']['previous'];
		}
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$file = isset( $record['regeneration']['source'] ) && is_string( $record['regeneration']['source'] ) ? $record['regeneration']['source'] : $attached;
		if ( empty( $paths ) || ! is_string( $file ) || $this->normalize_operation_relative_path( $file ) !== $file ) {
			return null;
		}
		$upload_dir = $this->operation_upload_dir( $read_only );
		$sizes = [];
		foreach ( $paths as $name => $relative ) {
			if ( ! is_string( $name ) || ! is_string( $relative ) || $this->normalize_operation_relative_path( $relative ) !== $relative ) {
				continue;
			}
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$dimensions = is_file( $path ) ? @getimagesize( $path ) : false;
			$sizes[ $name ] = [
				'path'   => $relative,
				'width'  => $dimensions ? (int) $dimensions[0] : 0,
				'height' => $dimensions ? (int) $dimensions[1] : 0,
				'mime'   => (string) wp_check_filetype( $relative )['type'],
			];
		}
		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		// Preserve the current reference, not an assertion that its bytes are clean;
		// selecting it as a generation source still requires verified provenance.
		$original = '';
		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && basename( $metadata['original_image'] ) === $metadata['original_image'] && $this->normalize_operation_relative_path( $metadata['original_image'] ) === $metadata['original_image'] ) {
			$directory = dirname( $file );
			$relative = ( $directory === '.' ? '' : $directory . '/' ) . $metadata['original_image'];
			$failure = null;
			$resolved = $this->resolve_upload_artifact_path( $relative, false, '', 0, $failure, $read_only );
			if ( $resolved !== false && is_file( $resolved['path'] ) ) {
				$original = $metadata['original_image'];
			}
		}
		return [
			'file'           => $file,
			'width'          => is_array( $metadata ) && isset( $metadata['width'] ) ? max( 0, (int) $metadata['width'] ) : 0,
			'height'         => is_array( $metadata ) && isset( $metadata['height'] ) ? max( 0, (int) $metadata['height'] ) : 0,
			'mime'           => (string) wp_check_filetype( $file )['type'],
			'original_image' => $original,
			'sizes'          => $sizes,
			'candidates'     => [],
			'inherited'      => [],
		];
	}

	/**
	 * Select the map a new record inherits. Only an uncommitted
	 * record that mutated derivatives, one that already inherited a map, or a
	 * metadata-only partial with an unresolved old-size carrier passes it on; a
	 * newer completed record ends the chain. A map whose attached file no longer
	 * matches is stale and ignored.
	 *
	 * @return array{pre:array|null,stale:bool}
	 */
	private function select_inheritable_pre_operation( $journal, $attachment_id, $read_only = false ) {
		$result = [ 'pre' => null, 'stale' => false, 'overflow' => false ];
		if ( ! is_array( $journal ) ) {
			return $result;
		}
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		foreach ( [ isset( $journal['current'] ) ? $journal['current'] : null, isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ] as $record ) {
			if ( ! is_array( $record ) || empty( $record['token'] ) || ! is_string( $record['token'] ) ) {
				continue;
			}
			// A completed record ends the chain. A committed partial one does not:
			// its old paths may still be unresolved (for example uncertain ownership).
			if ( $this->operation_record_committed_metadata( $record ) && isset( $record['state'] ) && $record['state'] === 'complete' ) {
				return $result;
			}
			$mutated = $this->operation_record_mutated_derivatives( $record, $read_only );
			$pre = isset( $record['pre_operation'] ) && $this->is_valid_pre_operation( $record['pre_operation'] ) ? $record['pre_operation'] : null;
			if ( $pre === null && $mutated ) {
				$pre = $this->legacy_pre_operation( $record, $attachment_id, $read_only );
			}
			$partial_carrier = $pre !== null && isset( $record['state'] ) && $record['state'] === 'partial'
				&& $this->name_stable_legacy_carriers( $record );
			if ( $pre === null || ( ! $mutated && empty( $pre['inherited'] ) && ! $partial_carrier ) ) {
				continue;
			}
			if ( ! is_string( $attached ) || $pre['file'] !== $attached ) {
				$result['stale'] = true;
				return $result;
			}
			unset( $pre['stale'] );
			// Carry the predecessor's unresolved retirement obligations forward, so
			// a fresh output it could not retire is not forgotten when the retry's
			// generation names its files differently.
			$protected = [];
			foreach ( $pre['sizes'] as $size ) {
				$protected[ $this->ownership_reference_key( $size['path'] ) ] = true;
			}
			foreach ( $this->name_stable_unresolved_paths( $record ) as $name => $paths ) {
				foreach ( $paths as $path ) {
					if ( isset( $protected[ $this->ownership_reference_key( $path ) ] ) || $this->normalize_operation_relative_path( $path ) !== $path ) {
						continue;
					}
					$known = isset( $pre['candidates'][ $name ] ) ? $pre['candidates'][ $name ] : [];
					$pre['candidates'][ $name ] = array_values( array_unique( array_merge( $known, [ $path ] ) ) );
				}
			}
			// A merged map that would exceed the candidate bound is never built: the
			// caller refuses before writing, so the predecessor keeps its evidence.
			foreach ( $pre['candidates'] as $paths ) {
				if ( count( $paths ) > self::PRE_OPERATION_CANDIDATE_LIMIT ) {
					$result['overflow'] = true;
					return $result;
				}
			}
			$pre['inherited'] = array_slice( array_values( array_unique( array_merge( [ $record['token'] ], $pre['inherited'] ) ) ), 0, self::PRE_OPERATION_TOKEN_LIMIT );
			// Only a map the reader accepts is inherited. A converted 2.0.16 record
			// can exceed the size bound; it is refused the same way.
			if ( ! $this->is_valid_pre_operation( $pre ) ) {
				$result['overflow'] = true;
				return $result;
			}
			$result['pre'] = $pre;
			return $result;
		}
		return $result;
	}

	/**
	 * Paths a record planned to retire but did not prove retired, plus the old
	 * paths retained by OD03-1 overflow or a directory-representation mismatch.
	 * The latter stay in pre_operation.sizes, never intermediate candidates.
	 *
	 * @return array<string,string[]> Size name (or `_pending`) => paths.
	 */
	private function name_stable_unresolved_paths( $record ) {
		$recovery = isset( $record['derivative_recovery'] ) && is_array( $record['derivative_recovery'] ) ? $record['derivative_recovery'] : [];
		$retired = isset( $recovery['retired'] ) && is_array( $recovery['retired'] ) ? $recovery['retired'] : [];
		$paths = [];
		foreach ( $this->name_stable_legacy_carriers( $record ) as $name => $path ) {
			$paths[ $name ][] = $path;
		}
		foreach ( isset( $recovery['regenerated'] ) && is_array( $recovery['regenerated'] ) ? $recovery['regenerated'] : [] as $name => $path ) {
			if ( is_string( $name ) && is_string( $path ) && ! in_array( $path, $retired, true ) ) {
				$paths[ $name ][] = $path;
			}
		}
		foreach ( isset( $recovery['remap'] ) && is_array( $recovery['remap'] ) ? $recovery['remap'] : [] as $entry ) {
			if ( is_array( $entry ) && isset( $entry['from'], $entry['names'][0] ) && is_string( $entry['from'] ) && is_string( $entry['names'][0] ) && ! in_array( $entry['from'], $retired, true ) ) {
				$paths[ $entry['names'][0] ][] = $entry['from'];
			}
		}
		foreach ( isset( $recovery['retiring'] ) && is_array( $recovery['retiring'] ) ? $recovery['retiring'] : [] as $path ) {
			if ( is_string( $path ) && ! in_array( $path, $retired, true ) ) {
				$paths['_pending'][] = $path;
			}
		}
		return $paths;
	}

	/** @return array<string,string> Unresolved original-name paths, not retirement candidates. */
	private function name_stable_legacy_carriers( $record ) {
		$pre = isset( $record['pre_operation'] ) ? $record['pre_operation'] : null;
		$outcomes = isset( $record['derivative_recovery']['outcomes'] ) && is_array( $record['derivative_recovery']['outcomes'] ) ? $record['derivative_recovery']['outcomes'] : [];
		if ( ! $this->is_valid_pre_operation( $pre ) ) {
			return [];
		}
		$paths = [];
		foreach ( $outcomes as $name => $code ) {
			if ( is_string( $name ) && in_array( $code, [ 'legacy_limit_reached', 'directory_changed' ], true ) && isset( $pre['sizes'][ $name ] ) ) {
				$paths[ $name ] = $pre['sizes'][ $name ]['path'];
			}
		}
		return $paths;
	}

	/**
	 * Serialized bound of the journal while this operation and one retry run:
	 * this record at its largest beside the actual
	 * predecessor or, for a first attempt, its retry's record beside the larger
	 * of the two, plus the two largest possible history entries.
	 *
	 * Each record field is counted once per size name that can appear in it,
	 * with every value not yet known at its maximum width. A name with an old
	 * path can be remapped, retired, and reported; any other name (registered
	 * since upload, or inherited only as a candidate) gains a fresh output and
	 * at most one retirement. Each existing candidate has its own slot.
	 *
	 * A first attempt also reserves the room its retry needs to be admitted: the
	 * retry inherits up to one fresh output and one pending retirement per name,
	 * plus every pending candidate retirement, as new candidates, and reserves
	 * nothing further itself. A later retry is admitted only if it fits.
	 *
	 * The bound covers generation results within the known names (plus 8 that a
	 * size filter may add) and the widths measured here. A filter that adds more
	 * names, or longer names or paths, can still make a later journal write
	 * exceed the cap, which refuses that write; the record then stays at its
	 * last durable state.
	 *
	 * @param array    $operation Current operation; `target_limit` caps the
	 *                            watermark targets (50 when absent).
	 * @param array    $pre Pre-operation map this record will hold.
	 * @param int|null $record_bytes Exact bound of this record (the check
	 *                               before the first promotion), or null to
	 *                               bound it structurally.
	 * @return int
	 */
	private function estimate_name_stable_journal_bytes( $operation, $pre, $exact_record_bytes = null ) {
		$journal = $operation['journal'];
		$record = $journal['current'];
		$first_attempt = empty( $pre['inherited'] );
		// The inherited-token list counts at its full length. Candidates count in
		// their slots below, not in the serialized map.
		$pre['inherited'] = array_pad( $pre['inherited'], self::PRE_OPERATION_TOKEN_LIMIT, str_repeat( 't', 64 ) );
		$record['pre_operation'] = array_merge( $pre, [ 'candidates' => [] ] );
		$directory = dirname( $pre['file'] );
		$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
		// A fresh output is the main file's stem plus "-{W}x{H}"; a stage or
		// recovery file is a token-prefixed name in the same directory.
		$longest_path = max( 64, strlen( $pre['file'] ) + 16 );
		$old = [];
		$names = [];
		// Candidate list entries, and the distinct paths they name: a path
		// listed under two names (one of them `_pending`) retires once.
		$candidates = 0;
		$candidate_paths = [];
		foreach ( $pre['sizes'] as $name => $size ) {
			$old[ (string) $name ] = true;
			$names[ (string) $name ] = true;
			$longest_path = max( $longest_path, strlen( $size['path'] ) );
		}
		foreach ( $pre['candidates'] as $name => $paths ) {
			$names[ (string) $name ] = true;
			$candidates += count( $paths );
			foreach ( $paths as $path ) {
				$candidate_paths[ $path ] = true;
				$longest_path = max( $longest_path, strlen( $path ) );
			}
		}
		// A token-named generation works over its frozen size set; without one,
		// the registered sizes stand in for it.
		$frozen = isset( $operation['frozen_sizes'] ) && is_array( $operation['frozen_sizes'] ) ? $operation['frozen_sizes'] : null;
		foreach ( array_keys( $frozen !== null ? $frozen : wp_get_registered_image_subsizes() ) as $name ) {
			$names[ (string) $name ] = true;
		}
		$longest_name = 16;
		foreach ( array_keys( $names ) as $name ) {
			$longest_name = max( $longest_name, strlen( $name ) );
		}
		$path = str_repeat( 'p', $longest_path );
		// Restoration and backup paths can carry a backup directory prefix inside
		// uploads (the backup root is filterable).
		$wide = str_repeat( 'p', $longest_path + 256 );
		$owned = str_repeat( 's', strlen( $prefix ) + 112 );
		$hash = str_repeat( 'f', 64 );
		// Outcome and failure codes are at most 32 characters
		// (`derivative_reconciliation_failed`).
		$code = str_repeat( 'c', 32 );
		$name = str_repeat( 'n', $longest_name );
		// List entries: a record's lists stay far below 1,000 entries.
		$path_cost = strlen( serialize( [ 999 => $path ] ) );
		$name_cost = strlen( serialize( [ 999 => $name ] ) );
		$map_cost = strlen( serialize( [ $name => $path ] ) );
		$outcome_cost = strlen( serialize( [ $name . '#intermediate' => $code ] ) );
		// remap entry; outcomes for the name, its source, and its intermediates;
		// previous, regenerated, and regeneration maps; the generated-size list, a
		// target alias, and the candidate-map key; and one retirement of its old or
		// fresh path (a conflict or changed definition retires the old path, a remap
		// its fresh source, never both), journaled as retiring and retired.
		// Candidates retire in their own slots.
		$with_old_path = strlen( serialize( [ 'names' => [ $name ], 'from' => $path, 'to' => $path, 'state' => 'promoted', 'size' => PHP_INT_MAX, 'hash' => $hash, 'selected' => false ] ) )
			+ 3 * $outcome_cost + 3 * $map_cost + 3 * $name_cost + 2 * $path_cost;
		// No remap and no previous path: its fresh output, and at most one
		// retirement (an identity rollback retires the fresh output).
		$without_old_path = 2 * $outcome_cost + $map_cost + 3 * $name_cost + 2 * $path_cost;
		$stage_name = str_repeat( 's', 75 + max( strlen( basename( $pre['file'] ) ), strlen( $pre['original_image'] ) ) + 24 );
		if ( $frozen !== null ) {
			// A token-named generation journals no remap entries and no
			// regeneration maps. Per name: two outcomes (its own and one
			// retirement key), its previous and regenerated map entries, one
			// target alias, one planned stage name, one adopted output, and one
			// retirement of its old path, journaled as retiring and retired. The
			// frozen definitions are known and counted exactly with the stage.
			$with_old_path = 2 * $outcome_cost + 2 * $map_cost + $name_cost + 2 * $path_cost
				+ strlen( serialize( [ 999 => $stage_name ] ) )
				+ strlen( serialize( [ 999 => [ 'names' => [ $name ], 'stage' => $stage_name, 'width' => PHP_INT_MAX, 'height' => PHP_INT_MAX, 'mime' => str_repeat( 'm', 32 ), 'size' => PHP_INT_MAX, 'sha256' => $hash ] ] ) );
			$without_old_path = $with_old_path;
		}
		// Room for sizes a size filter adds during generation without registering
		// them; more than this would be refused by the journal cap, not truncated.
		// No size filter runs after a frozen set is taken.
		$unregistered = $frozen !== null ? 0 : 8;
		// A candidate path is journaled as retiring and retired once; each list
		// entry naming it costs one more path.
		$retirement = 2 * $path_cost;
		// Every promotion group of a token-named generation is journaled. A
		// caller with a legacy prediction supplies canonical staged P paths not
		// already owned by current groups; C paths do not add groups. The combined
		// term, not each part separately, is capped by the 50-target reader limit.
		// This only models the target term: the caller must also reserve the
		// legacy fields and retry growth.
		$legacy_paths = isset( $operation['legacy_staged_paths'] ) && is_array( $operation['legacy_staged_paths'] ) ? array_unique( $operation['legacy_staged_paths'] ) : [];
		$current = isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [];
		if ( $exact_record_bytes !== null ) {
			$current_paths = [];
			foreach ( $current as $entry ) {
				if ( isset( $entry['target'] ) && is_string( $entry['target'] ) ) {
					$current_paths[] = $entry['target'];
				}
			}
			$legacy_paths = array_diff( $legacy_paths, $current_paths );
		}
		$reference_target_bound = $frozen !== null ? min( 50, count( $names ) + 1 ) : ( isset( $operation['target_limit'] ) ? max( 1, min( 50, (int) $operation['target_limit'] ) ) : 50 );
		$targets = $frozen !== null ? min( 50, $reference_target_bound + count( $legacy_paths ) ) : $reference_target_bound;
		// A token-named generation's promotion targets carry no recovery entry.
		$target = $frozen !== null
			? strlen( serialize( [ 'target' => $path, 'root' => 'uploads', 'stage' => $owned, 'size' => PHP_INT_MAX, 'hash' => $hash, 'aliases' => [], 'intent' => 'watermark-output', 'state' => 'allocated' ] ) )
			: strlen( serialize( [ 'target' => $path, 'root' => 'uploads', 'stage' => $owned, 'size' => PHP_INT_MAX, 'hash' => $hash, 'aliases' => [], 'intent' => 'watermark-output', 'state' => 'allocated', 'recovery' => [ 'state' => 'verified', 'root' => 'uploads', 'path' => $owned, 'size' => PHP_INT_MAX, 'hash' => $hash ] ] ) );
		// Fields with no per-name part, all at maximum width. A token-named
		// generation never restores per-target temporary recovery or generates
		// in place. A manifest-source operation can create a permanent backup
		// when the existing setting requires it; its rollback can journal restoration.
		$fixed_fields = [
			'restoration'                 => [ 'target' => $path, 'root' => 'uploads', 'stage' => $owned, 'size' => PHP_INT_MAX, 'hash' => $hash, 'aliases' => [ 'full' ], 'intent' => 'restoration', 'state' => 'promoted', 'source' => [ 'path' => $wide, 'size' => PHP_INT_MAX, 'hash' => $hash, 'recovery' => $wide ] ],
			'temporary_recovery_restore'  => [ 'target' => $path, 'root' => 'uploads', 'stage' => $owned, 'state' => 'promoted' ],
			'backup'                      => [ 'state' => 'promoted', 'root' => 'backup', 'path' => $wide, 'stage' => $wide, 'source' => [ 'path' => $wide, 'size' => PHP_INT_MAX, 'hash' => $hash ], 'size' => PHP_INT_MAX, 'hash' => $hash, 'recovery' => $wide ],
			'backup_association'          => [ 'state' => 'committed', 'intended' => PHP_INT_MAX, 'preserved' => false, 'value' => PHP_INT_MAX, 'meta_id' => PHP_INT_MAX ],
			'regeneration'                => [ 'purpose' => 'reapply', 'source' => $wide, 'size' => PHP_INT_MAX, 'hash' => $hash, 'metadata_hash' => $hash, 'state' => 'generated', 'previous_derivatives' => [], 'sizes' => [] ],
			'regenerated_metadata_commit' => [ 'state' => 'committed', 'hash' => $hash ],
			'derivative_recovery'         => [ 'previous' => [], 'regenerated' => [], 'outcomes' => [ 'full#variant' => $code ], 'retired' => [ $path ], 'retiring' => [ $path ], 'remap' => [] ],
			'code'                        => $code,
			'counts'                      => [ 'processed' => PHP_INT_MAX, 'failed' => PHP_INT_MAX ],
			'cleanup_pending'             => false,
			'metadata_committed'          => false,
			'ended_at'                    => PHP_INT_MAX,
		];
		if ( $frozen !== null ) {
			unset( $fixed_fields['temporary_recovery_restore'], $fixed_fields['regeneration'] );
			if ( empty( $operation['regeneration_backup_required'] ) ) {
				unset( $fixed_fields['backup'], $fixed_fields['backup_association'] );
			}
		}
		if ( isset( $record['main_recovery'] ) ) {
			$fixed_fields['main_recovery'] = [ 'target' => $path, 'root' => 'uploads', 'path' => $owned, 'size' => PHP_INT_MAX, 'sha256' => $hash, 'state' => 'allocated', 'authority' => 'manifest', 'manifest_token' => str_repeat( 't', 64 ) ];
		}
		$fixed = strlen( serialize( $fixed_fields ) ) + 1024;
		// A completed apply or flagged regenerate journals its manifest evidence:
		// the manifest without its aliases (derived from the targets counted
		// above) and one size reference per covered sub-size name. Target groups
		// are built from selected names only, so the covered names are at most
		// the selected ones ($targets).
		$evidence = 0;
		if ( isset( $record['type'] ) && ( $record['type'] === 'apply' || ( $record['type'] === 'regenerate' && ! empty( $record['intended_flag'] ) ) ) ) {
			$entries = min( self::MANIFEST_NAME_LIMIT, $reference_target_bound );
			$references = [];
			// Size references are basenames. The main file keeps its path (the
			// name-stable rule), and the provenance record is written once, so
			// both are counted at their actual widths.
			$basename = str_repeat( 'b', max( 1, $longest_path - strlen( $prefix ) ) );
			for ( $i = 0; $i < $entries; $i++ ) {
				$references[ str_pad( (string) $i, strlen( $name ), 'n', STR_PAD_LEFT ) ] = $basename;
			}
			$main_path = str_repeat( 'm', strlen( $pre['file'] ) );
			$provenance = get_post_meta( $operation['attachment_id'], self::PROVENANCE_META_KEY, true );
			$provenance = is_array( $provenance ) && isset( $provenance['file'] ) && is_string( $provenance['file'] ) ? [ 'file' => $provenance['file'], 'size' => PHP_INT_MAX, 'sha256' => $hash ] : null;
			$evidence = strlen( serialize( [
				'manifest_evidence' => [
					'version'    => 1,
					'manifest'   => [ 'version' => self::MANIFEST_VERSION, 'token' => str_repeat( 't', 64 ), 'committed_at' => PHP_INT_MAX, 'main' => [ 'path' => $main_path, 'size' => PHP_INT_MAX, 'sha256' => $hash, 'watermarked' => true ], 'legacy' => [], 'provenance' => $provenance, 'settings' => $hash ],
					'references' => [ 'file' => $main_path, 'sizes' => $references ],
				],
			] ) );
			$manifest_legacy_paths = [];
			foreach ( isset( $operation['stored_metadata']['sizes'] ) && is_array( $operation['stored_metadata']['sizes'] ) ? $operation['stored_metadata']['sizes'] : [] as $legacy_name => $entry ) {
				if ( $this->is_marked_legacy_size( $legacy_name, $entry ) && isset( $pre['sizes'][ $legacy_name ] ) ) {
					$manifest_legacy_paths[ $pre['sizes'][ $legacy_name ]['path'] ] = true;
				}
			}
			foreach ( isset( $operation['changed_p_predictions'] ) ? $operation['changed_p_predictions'] : [] as $legacy_size ) {
				$manifest_legacy_paths[ $legacy_size['path'] ] = true;
			}
			foreach ( isset( $operation['clean_legacy_predictions'] ) ? $operation['clean_legacy_predictions'] : [] as $legacy_size ) {
				$manifest_legacy_paths[ $legacy_size['path'] ] = true;
			}
			foreach ( array_keys( $manifest_legacy_paths ) as $legacy_path ) {
				$evidence += strlen( serialize( [ 999 => [ 'path' => $legacy_path, 'size' => PHP_INT_MAX, 'sha256' => $hash ] ] ) );
			}
		}
		// The generation stage's fixed fields and two sources at maximum width;
		// its per-name entries are counted with each name above.
		$generation = 0;
		if ( $frozen !== null ) {
			$generation = strlen( serialize( [
				'generation_stage' => [
					'version'   => 1,
					'state'     => 'generated',
					'pre_generation_retention' => true,
					'source_collision' => 'generation',
					'directory' => $path,
					'prefix'    => str_repeat( 'p', 75 ),
					'sources'   => [
						[ 'role' => 'main', 'root' => 'backup', 'path' => $wide, 'size' => PHP_INT_MAX, 'sha256' => $hash, 'stage' => $stage_name, 'authority' => 'manifest' ],
						[ 'role' => 'generation', 'root' => 'uploads', 'path' => $wide, 'size' => PHP_INT_MAX, 'sha256' => $hash, 'stage' => $stage_name ],
					],
					'sizes'     => $frozen,
					'planned'   => [],
					'outputs'   => [],
					'legacy'    => [],
				],
			] ) );
		}
		$record_bytes = $exact_record_bytes !== null ? (int) $exact_record_bytes : strlen( serialize( $record ) ) + count( $old ) * $with_old_path + ( count( $names ) - count( $old ) + $unregistered ) * $without_old_path + count( $candidate_paths ) * $retirement + $candidates * $path_cost + $targets * $target + $fixed + $evidence + $generation;
		if ( $exact_record_bytes !== null && $frozen !== null && $legacy_paths ) {
			// Exact bytes already include journaled targets. Count only P paths
			// that are not among them, within the combined reader limit.
			$record_bytes += min( max( 0, 50 - count( $current ) ), count( $legacy_paths ) ) * $target;
		}
		// A first attempt reserves what its retry needs to be admitted. The retry's
		// record can add, per name, a fresh output as a new candidate (listed,
		// retiring, retired) and a pending retirement under `_pending` (listed
		// only: retirements are keyed by path), every existing candidate again
		// under `_pending`, and the filter-added names and `_pending` itself as
		// names. Its predecessor is this record, or the current predecessor when a
		// terminal failure of this record keeps it, so the retry's record is
		// paired with the larger of the two. A retry reserves nothing for a
		// further retry, so it is admitted whenever the first attempt was, within
		// the inheritance limits (candidates per name, sizes); a later retry is
		// admitted only if it fits, and is refused before any change otherwise.
		$retry_growth = ( count( $names ) + $unregistered ) * ( $retirement + 2 * $path_cost ) + count( $candidate_paths ) * $path_cost + ( $unregistered + 1 ) * $without_old_path;
		// The predecessor is counted at its actual size.
		$predecessor_bytes = empty( $journal['recoverable_predecessor'] ) ? 0 : strlen( serialize( $journal['recoverable_predecessor'] ) );
		// History keeps two entries, counted at their actual sizes. While this
		// record or its retry's is still a full record, at most one of them is
		// compacted into history: the retry begins either beside this record as
		// its predecessor, or beside the current predecessor with this record
		// compacted (its targets at most $targets).
		$compact = strlen( serialize( $this->compact_operation_record( $record ) ) ) + $targets * $target + ( count( $names ) + $unregistered ) * $name_cost;
		$history = [ 0, 0 ];
		foreach ( $journal['history'] as $entry ) {
			$history[] = strlen( serialize( $entry ) );
		}
		rsort( $history );
		if ( $first_attempt ) {
			// A runner reserves its next runner identity; a switch from an ordinary
			// attempt to a runner is independently admitted with its own identity.
			$retry_identity_growth = 0;
			if ( array_key_exists( 'run_identity', $record ) ) {
				$next_record = $record;
				$next_record['run_identity'] = [ 'run' => str_repeat( 'r', 64 ), 'attempt' => PHP_INT_MAX ];
				$retry_identity_growth = strlen( serialize( $next_record ) ) - strlen( serialize( $record ) );
			}
			$records = $record_bytes + $retry_growth + $retry_identity_growth + max( $record_bytes + $history[0] + $history[1], $predecessor_bytes + max( $compact, $history[1] ) + $history[0] );
		} else {
			$records = $record_bytes + $predecessor_bytes + $history[0] + $history[1];
		}
		$rest = $journal;
		$rest['current'] = null;
		$rest['recoverable_predecessor'] = null;
		$rest['history'] = [];
		foreach ( isset( $operation['clean_legacy_predictions'] ) ? $operation['clean_legacy_predictions'] : [] as $key => $size ) {
			// C adds its previous/outcome maps; a first retry can also carry the
			// new name as a full old-size slot and inherit cleanup paths. Reserve
			// that name separately, without widening every existing size name.
			$wide_key = $key . '-99';
			$extra = strlen( serialize( [ $wide_key => $size['path'] ] ) ) + strlen( serialize( [ $wide_key => $code ] ) );
			if ( $first_attempt ) {
				$extra = 2 * $extra + $with_old_path + 20 * max( 0, strlen( $wide_key ) - $longest_name ) + strlen( serialize( [ $wide_key => $size ] ) ) + 4 * $path_cost;
			}
			$records += $extra;
		}
		foreach ( isset( $operation['changed_p_predictions'] ) ? $operation['changed_p_predictions'] : [] as $key => $size ) {
			$wide_key = $key . '-99';
			$legacy_stage = [ 'key' => $wide_key, 'target' => $size['path'], 'width' => PHP_INT_MAX, 'height' => PHP_INT_MAX, 'stage' => $stage_name, 'size' => PHP_INT_MAX, 'sha256' => $hash ];
			$extra = $exact_record_bytes !== null ? 0 : strlen( serialize( [ 999 => $legacy_stage ] ) ) + strlen( serialize( [ $wide_key => $size['path'] ] ) ) + strlen( serialize( [ $wide_key => $code ] ) ) + strlen( serialize( [ 999 => $wide_key ] ) );
			if ( $first_attempt ) {
				$extra = 2 * $extra + $with_old_path + 20 * max( 0, strlen( $wide_key ) - $longest_name ) + strlen( serialize( [ $wide_key => $size ] ) ) + 4 * $path_cost;
			}
			$records += $extra;
		}
		return strlen( serialize( $rest ) ) + $records;
	}

	/**
	 * Deadline for both ownership passes: one budget per operation, measured from
	 * the moment it took the attachment lease, minus the restore/generation/commit
	 * reserve. Earlier images in the same CLI process or bulk request do not use
	 * it up.
	 *
	 * @return float
	 */
	private function operation_ownership_deadline( $operation ) {
		$start = isset( $operation['time_origin'] ) && is_float( $operation['time_origin'] ) ? $operation['time_origin'] : microtime( true );
		if ( $this->operation_fault_injector ) {
			// Test seam: a float moves the origin, so a budget edge can be reached
			// without waiting for it.
			$injected = call_user_func( $this->operation_fault_injector, 'operation_time_origin', [ 'attachment_id' => $operation['attachment_id'], 'origin' => $start ] );
			$start = is_float( $injected ) ? $injected : $start;
		}
		$limit = (float) self::OPERATION_TIME_LIMIT;
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 ) {
			$limit = min( $limit, 0.6 * $maximum );
		}
		return $start + $limit - self::OPERATION_TIME_RESERVE;
	}

	/** @return string */
	private function ownership_reference_key( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );
		return DIRECTORY_SEPARATOR === '\\' ? strtolower( $relative ) : $relative;
	}

	/**
	 * One exhaustive ownership pass for several uploads-relative paths.
	 * Every reference of every other attachment is canonicalized with the target
	 * grouping identity rule (so a differently named symlink still counts); a
	 * missing candidate matches by its recorded relative path. An incomplete,
	 * failed, over-ceiling, or over-budget scan makes every path uncertain.
	 *
	 * @return array{uncertain:bool,paths:array<string,string>}
	 */
	private function derivative_paths_ownership( $attachment_id, $relatives, $upload_dir, $deadline ) {
		global $wpdb;
		$results = [];
		$identities = [];
		$keys = [];
		foreach ( array_unique( $relatives ) as $relative ) {
			if ( ! is_string( $relative ) || $relative === '' ) {
				continue;
			}
			$results[ $relative ] = 'exclusive';
			$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
			if ( $identity !== false ) {
				$identities[ $identity['identity'] ][] = $relative;
			}
			$keys[ $this->ownership_reference_key( $relative ) ][] = $relative;
		}
		if ( empty( $results ) ) {
			return [ 'uncertain' => false, 'paths' => [] ];
		}
		$uncertain = [ 'uncertain' => true, 'paths' => array_fill_keys( array_keys( $results ), 'uncertain' ) ];
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->postmeta, $wpdb->posts ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return $uncertain;
		}
		$mark = function( $reference, $path ) use ( &$results, $identities, $keys, $upload_dir ) {
			$key = $this->ownership_reference_key( $reference );
			foreach ( isset( $keys[ $key ] ) ? $keys[ $key ] : [] as $relative ) {
				$results[ $relative ] = 'shared';
			}
			$identity = $this->operation_path_identity( $path, $upload_dir );
			if ( $identity !== false && isset( $identities[ $identity['identity'] ] ) ) {
				foreach ( $identities[ $identity['identity'] ] as $relative ) {
					$results[ $relative ] = 'shared';
				}
			}
		};
		$candidates = array_keys( $results );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The ownership pass must prove derivatives are exclusive before destructive work.
		$direct = $wpdb->get_col( $wpdb->prepare( "SELECT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key = '_wp_attached_file' AND pm.post_id != %d AND pm.meta_value IN (" . implode( ',', array_fill( 0, count( $candidates ), '%s' ) ) . ')', array_merge( [ (int) $attachment_id ], $candidates ) ) );
		if ( ! empty( $wpdb->last_error ) || ! is_array( $direct ) ) {
			return $uncertain;
		}
		foreach ( $direct as $value ) {
			$mark( $value, $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $value );
		}

		$last_id = 0;
		$scanned = 0;
		$batch_size = 100;
		while ( true ) {
			if ( $this->should_inject_operation_fault( 'ownership_scan_row_cap', [ 'attachment_id' => $attachment_id, 'scanned' => $scanned ] ) || microtime( true ) > $deadline ) {
				return $uncertain;
			}
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The bounded ownership pass must read current attachment metadata before destructive work.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, MAX(CASE WHEN pm.meta_key = '_wp_attached_file' THEN pm.meta_value ELSE NULL END) AS attached_file, MAX(CASE WHEN pm.meta_key = '_wp_attachment_metadata' THEN pm.meta_value ELSE NULL END) AS attachment_metadata, SUM(pm.meta_key = '_wp_attached_file') AS attached_count, SUM(pm.meta_key = '_wp_attachment_metadata') AS metadata_count FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_wp_attached_file', '_wp_attachment_metadata') WHERE p.post_type = 'attachment' AND p.ID > %d GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d", $last_id, $batch_size ) );
			// The budget is also checked after each batch, so a final batch that
			// finishes past the deadline cannot authorize a destructive step.
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) || microtime( true ) > $deadline ) {
				return $uncertain;
			}
			foreach ( $rows as $row ) {
				if ( ! isset( $row->ID ) || (int) $row->ID <= $last_id || (int) $row->attached_count > 1 || (int) $row->metadata_count > 1 ) {
					return $uncertain;
				}
				$last_id = (int) $row->ID;
				if ( ++$scanned > self::OWNERSHIP_ROW_CEILING ) {
					return $uncertain;
				}
				if ( $last_id === (int) $attachment_id ) {
					continue;
				}
				if ( ! empty( $row->attached_file ) ) {
					if ( ! is_string( $row->attached_file ) ) {
						return $uncertain;
					}
					$mark( $row->attached_file, $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $row->attached_file );
				}
				if ( empty( $row->attachment_metadata ) ) {
					continue;
				}
				$metadata = maybe_unserialize( $row->attachment_metadata );
				if ( ! is_array( $metadata ) ) {
					return $uncertain;
				}
				if ( array_key_exists( 'file', $metadata ) ) {
					if ( ! is_string( $metadata['file'] ) || $metadata['file'] === '' ) {
						return $uncertain;
					}
					$file = $metadata['file'];
				} else {
					// Audio and document metadata may contain only a file size. PDF
					// previews still carry image references anchored by the attached file.
					$file = is_string( $row->attached_file ) && $row->attached_file !== '' ? $row->attached_file : null;
					if ( $file !== null && $this->normalize_operation_relative_path( $file ) !== $file ) {
						return $uncertain;
					}
					if ( $file === null ) {
						if ( array_key_exists( 'sizes', $metadata ) || array_key_exists( 'original_image', $metadata ) ) {
							return $uncertain;
						}
						continue;
					}
				}
				$directory = dirname( $file );
				$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
				$references = [ $file ];
				if ( isset( $metadata['original_image'] ) ) {
					if ( ! is_string( $metadata['original_image'] ) ) {
						return $uncertain;
					}
					$references[] = $prefix . $metadata['original_image'];
				}
				if ( isset( $metadata['sizes'] ) ) {
					if ( ! is_array( $metadata['sizes'] ) ) {
						return $uncertain;
					}
					foreach ( $metadata['sizes'] as $size ) {
						if ( ! is_array( $size ) || ! isset( $size['file'] ) || ! is_string( $size['file'] ) ) {
							return $uncertain;
						}
						$references[] = $prefix . $size['file'];
					}
				}
				foreach ( $references as $reference ) {
					$mark( $reference, $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $reference ) );
				}
			}
			if ( count( $rows ) < $batch_size ) {
				if ( microtime( true ) > $deadline ) {
					return $uncertain;
				}
				return [ 'uncertain' => false, 'paths' => $results ];
			}
		}
	}

	/**
	 * Old paths whose name or presence is expected to change, plus inherited
	 * intermediate candidates. An unscaled attachment with unchanged sizes has
	 * none, and then no ownership pass runs.
	 *
	 * @return string[]
	 */
	private function name_stable_ownership_candidates( $pre ) {
		$registered = wp_get_registered_image_subsizes();
		$directory = dirname( $pre['file'] );
		$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
		$stem = pathinfo( $pre['file'], PATHINFO_FILENAME );
		$extension = pathinfo( $pre['file'], PATHINFO_EXTENSION );
		$candidates = [];
		foreach ( $pre['sizes'] as $name => $size ) {
			$dimensions = isset( $registered[ $name ] ) ? image_resize_dimensions( $pre['width'], $pre['height'], (int) $registered[ $name ]['width'], (int) $registered[ $name ]['height'], $registered[ $name ]['crop'] ) : false;
			if ( ! $dimensions || $size['path'] !== $prefix . $stem . '-' . (int) $dimensions[4] . 'x' . (int) $dimensions[5] . ( $extension === '' ? '' : '.' . $extension ) ) {
				$candidates[] = $size['path'];
			}
		}
		foreach ( $pre['candidates'] as $paths ) {
			$candidates = array_merge( $candidates, $paths );
		}
		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Merge the durable metadata snapshot into the inherited retry map. Marked
	 * legacy references are obligations, not intermediate cleanup candidates.
	 * A conflicting marked address cannot replace an inherited obligation.
	 *
	 * @param array $pre Inherited pre-operation map.
	 * @param array $snapshot Snapshot of the effective metadata.
	 * @param array $data Effective metadata used for that snapshot.
	 * @return array|false Merged map, or false on conflicting marked addresses.
	 */
	private function merge_name_stable_retry_snapshot( $pre, $snapshot, $data ) {
		foreach ( $snapshot['sizes'] as $name => $size ) {
			if ( isset( $data['sizes'][ $name ] ) && $this->is_marked_legacy_size( $name, $data['sizes'][ $name ] ) ) {
				if ( isset( $pre['sizes'][ $name ] ) && $pre['sizes'][ $name ]['path'] !== $size['path'] ) {
					return false;
				}
				$pre['sizes'][ $name ] = $size;
				continue;
			}
			if ( ! isset( $pre['sizes'][ $name ] ) || $pre['sizes'][ $name ]['path'] !== $size['path'] ) {
				$paths = isset( $pre['candidates'][ $name ] ) ? $pre['candidates'][ $name ] : [];
				$pre['candidates'][ $name ] = array_values( array_unique( array_merge( $paths, [ $size['path'] ] ) ) );
			}
		}
		return $pre;
	}

	/**
	 * Record the pre-operation map, check the journal byte budget, and run the
	 * pre-mutation ownership pass. Nothing has changed on disk when this fails.
	 *
	 * @return true|array{code:string,message:string}
	 */
	private function prepare_name_stable_operation( &$operation, $data, $main_path, $upload_dir, $clean_source ) {
		if ( $this->should_inject_operation_fault( 'before_pre_operation_record', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			return [ 'code' => 'journal_write_failed', 'message' => __( 'Watermark recovery state could not be updated.', 'image-watermark' ) ];
		}
		$snapshot = isset( $operation['early_regeneration_plan']['snapshot'] ) ? $operation['early_regeneration_plan']['snapshot'] : $this->snapshot_pre_operation( $operation['attachment_id'], $data, $main_path, $upload_dir );
		if ( $snapshot === false ) {
			return [ 'code' => 'invalid_metadata', 'message' => __( 'Invalid attachment metadata.', 'image-watermark' ) ];
		}
		$current = $operation['journal']['current'];
		if ( isset( $current['pre_operation'] ) && $this->is_valid_pre_operation( $current['pre_operation'] ) ) {
			$pre = $this->merge_name_stable_retry_snapshot( $current['pre_operation'], $snapshot, $data );
			if ( $pre === false ) {
				return [ 'code' => 'journal_budget_exceeded', 'message' => __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) ];
			}
		} else {
			$pre = $snapshot;
			if ( ! empty( $operation['pre_operation_stale'] ) ) {
				$pre['stale'] = true;
			}
		}
		// Restoring a source with another geometry or format would change
		// the main file's identity, so it is refused before anything changes.
		$source_size = is_string( $clean_source ) && is_file( $clean_source ) ? @getimagesize( $clean_source ) : false;
		if ( ! $source_size || ( $pre['width'] > 0 && (int) $source_size[0] !== $pre['width'] ) || ( $pre['height'] > 0 && (int) $source_size[1] !== $pre['height'] ) || ( $pre['mime'] !== '' && $source_size['mime'] !== $pre['mime'] ) ) {
			return [ 'code' => 'backup_identity_mismatch', 'message' => __( 'The backup does not have the image\'s current dimensions or format, for example because another tool resized or converted the image after the backup was made. No image was changed.', 'image-watermark' ) ];
		}
		$candidate_overflow = false;
		foreach ( $pre['candidates'] as $paths ) {
			$candidate_overflow = $candidate_overflow || count( $paths ) > self::PRE_OPERATION_CANDIDATE_LIMIT;
		}
		if ( $candidate_overflow || ! $this->is_valid_pre_operation( $pre ) ) {
			return [ 'code' => 'journal_budget_exceeded', 'message' => __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) ];
		}
		$reserved = $this->estimate_name_stable_journal_bytes( $operation, $pre );
		if ( $this->operation_fault_injector && empty( $operation['clean_legacy_regeneration'] ) && empty( $operation['changed_p_operation'] ) ) {
			// Test seam: reports the reservation, so a test can compare it with
			// what the operation and its retry actually write.
			call_user_func( $this->operation_fault_injector, 'journal_reservation', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'bytes' => $reserved ] );
		}
		if ( $reserved > self::NAME_STABILITY_JOURNAL_BUDGET ) {
			return [ 'code' => 'journal_budget_exceeded', 'message' => __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) ];
		}
		if ( ! $this->set_operation_state( $operation, $operation['journal']['current']['state'], [ 'pre_operation' => $pre ] ) ) {
			return [ 'code' => 'journal_write_failed', 'message' => __( 'Watermark recovery state could not be updated.', 'image-watermark' ) ];
		}
		$operation['pre_operation'] = $pre;
		$operation['ownership'] = [];
		$operation['ownership_uncertain'] = false;
		$candidates = $this->name_stable_ownership_candidates( $pre );
		if ( isset( $operation['frozen_sizes'] ) ) {
			// A token-named generation needs the proof for the main file and every
			// old size path it may replace; one pass covers them, so the
			// post-generation pass only checks new paths.
			$candidates = array_merge( $candidates, [ $pre['file'] ] );
			foreach ( $pre['sizes'] as $size ) {
				$candidates[] = $size['path'];
			}
			$candidates = array_values( array_unique( $candidates ) );
		}
		if ( ! empty( $candidates ) ) {
			$deadline = $this->operation_ownership_deadline( $operation );
			$pass = $this->derivative_paths_ownership( $operation['attachment_id'], $candidates, $upload_dir, $deadline );
			if ( microtime( true ) > $deadline ) {
				return [ 'code' => 'time_budget_exhausted', 'message' => __( 'Not enough request time remained to change this image safely. No image was changed; retry the operation.', 'image-watermark' ), 'retryable' => true ];
			}
			$operation['ownership'] = $pass['paths'];
			$operation['ownership_uncertain'] = $pass['uncertain'];
		}
		if ( ! empty( $operation['clean_legacy_regeneration'] ) || ! empty( $operation['changed_p_operation'] ) ) {
			if ( ! empty( $operation['clean_legacy_regeneration'] ) ) {
				$legacy = $this->predict_clean_legacy_entries( $operation, $pre, $data, $upload_dir );
			} else {
				$entries = [];
				foreach ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] as $name => $entry ) {
					if ( $this->is_marked_legacy_size( $name, $entry ) ) { $entries[ $name ] = $entry; }
				}
				$legacy = [ 'entries' => $entries, 'new' => [], 'key_overflow' => false, 'limit' => $this->legacy_size_preflight( $entries ) ];
			}
			if ( ! empty( $operation['changed_p_operation'] ) ) {
				$p = $this->predict_changed_watermarked_legacy( $operation, $pre, $data, $upload_dir, $legacy );
				$legacy['entries'] = $p['entries'];
				$legacy['limit'] = $this->legacy_size_preflight( $p['entries'] );
				$legacy['key_overflow'] = $legacy['key_overflow'] || $p['key_overflow'];
				$operation['changed_p_predictions'] = $p['new'];
				$operation['legacy_staged_paths'] = array_column( $p['new'], 'path' );
			}
			if ( $legacy['limit']['exceeded'] ) {
				return [ 'code' => 'legacy_limit_reached', 'message' => __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) ];
			}
			$operation['clean_legacy_predictions'] = $legacy['new'];
			$operation['legacy_key_overflow'] = $legacy['key_overflow'];
		}
		return true;
	}

	/** Check the retry's inherited size-name union, not its number of paths. */
	private function retry_size_key_limit_exceeded( $pre, $frozen, $reserved_names, $phase = 'admission' ) {
		$names = array_unique( array_merge( array_keys( $pre['sizes'] ), array_keys( $pre['candidates'] ), array_keys( $frozen ), $reserved_names ) );
		$count = count( $names );
		if ( $this->operation_fault_injector ) {
			// Unsupported test seam: only an exact-phase integer can increase the
			// real count, never authorize an otherwise over-limit union.
			$injected = call_user_func( $this->operation_fault_injector, 'retry_size_key_union', [ 'phase' => $phase, 'names' => array_values( $names ), 'reserved_names' => array_values( $reserved_names ), 'count' => $count, 'limit' => 200 ] );
			if ( $phase === 'exact' && is_int( $injected ) && $injected >= 0 ) {
				$count = max( $count, $injected );
			}
		}
		return $count > 200;
	}

	/**
	 * Predict distinct promotion targets with the execution planner, without
	 * creating a stage. An uncertain source or output format is not a proof;
	 * the actual-group gate still runs after generation in those cases.
	 */
	private function predicted_generation_group_overflow( $operation, $purpose, $options, $flagged, $request, $backup, $upload_dir ) {
		$pre = $operation['pre_operation'];
		$frozen = $operation['frozen_sizes'];
		$p = $operation['changed_p_predictions'];
		// This is only a cheap upper bound for avoiding work, never a refusal.
		if ( count( $frozen ) + count( $p ) + 1 <= 50 ) { return false; }
		$original = $flagged ? $this->proven_original_source( $operation['attachment_id'], $pre ) : $this->unwatermarked_original_source( $operation['attachment_id'], $pre );
		$source_file = $original === null ? $backup : $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $original['path'] );
		$info = is_file( $source_file ) ? @getimagesize( $source_file ) : false;
		// The guarded output mapping preserves the main MIME, not a different
		// original's MIME. Do not infer that different source's output format.
		if ( ! $info || empty( $info['mime'] ) || $info['mime'] !== $pre['mime'] ) { return false; }
		$source = $original === null ? [ 'role' => 'main' ] : [ 'role' => 'generation' ];
		$dimensions = $this->generation_source_dimensions( $source, $pre );
		$extension = pathinfo( $source_file, PATHINFO_EXTENSION );
		if ( ! is_array( $dimensions ) || $extension === '' ) { return false; }
		$outputs = [];
		foreach ( $frozen as $name => $definition ) {
			$geometry = image_resize_dimensions( $dimensions[0], $dimensions[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
			if ( ! is_array( $geometry ) || ! isset( $geometry[4], $geometry[5] ) ) { continue; }
			$key = (int) $geometry[4] . 'x' . (int) $geometry[5];
			if ( ! isset( $outputs[ $key ] ) ) {
				$outputs[ $key ] = [ 'names' => [], 'stage' => 'prediction-' . $key . '.' . $extension, 'width' => (int) $geometry[4], 'height' => (int) $geometry[5], 'mime' => $info['mime'], 'size' => 1 ];
			}
			$outputs[ $key ]['names'][] = (string) $name;
		}
		$prediction = $operation;
		$prediction['journal']['current']['generation_stage'] = [ 'sizes' => $frozen, 'sources' => [ $source ] ];
		$plan = $this->plan_generation_targets( $prediction, [ 'outputs' => array_values( $outputs ), 'failed' => [] ], $upload_dir, $purpose, $options, $flagged );
		if ( isset( $plan['legacy_refusal'] ) ) { return false; }
		$key_for = function( $relative ) use ( $upload_dir ) {
			$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
			return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
		};
		$targets = [];
		foreach ( $plan['groups'] as $group ) { $targets[ $key_for( $group['target'] ) ] = true; }
		foreach ( $p as $old ) {
			if ( ! empty( $plan['legacy_fallback'][ $old['path'] ] ) ) { $targets[ $key_for( $old['path'] ) ] = true; }
		}
		// Main is a group only when execution would promote or watermark it.
		// If a manifest recovery is pending, its eventual source/restore is not
		// necessarily the current backup: only full rendering proves the group.
		$live_source = $request !== null && ! $flagged;
		$full = $purpose === 'reapply' && isset( $options['watermark_on']['full'] ) && (int) $options['watermark_on']['full'] === 1;
		$main = get_attached_file( $operation['attachment_id'] );
		$main_hash = is_string( $main ) && is_file( $main ) ? hash_file( 'sha256', $main ) : false;
		$source_hash = is_file( $backup ) ? hash_file( 'sha256', $backup ) : false;
		$differs = empty( $operation['journal']['current']['main_recovery'] ) && is_string( $main_hash ) && is_string( $source_hash ) && $main_hash !== $source_hash;
		if ( ! $live_source && ( $full || $differs ) && isset( $plan['ownership'][ $pre['file'] ] ) && $plan['ownership'][ $pre['file'] ] === 'exclusive' ) {
			$targets[ $key_for( $pre['file'] ) ] = true;
		}
		return count( $targets ) > 50;
	}

	/** Whether aliases of an old path can generate distinct output groups. */
	private function legacy_alias_may_split( $name, $old, $pre, $operation, $source, $geometry ) {
		foreach ( $pre['sizes'] as $other_name => $other ) {
			if ( $other_name === $name || $other['path'] !== $old['path'] || ! isset( $operation['frozen_sizes'][ $other_name ] ) ) { continue; }
			$definition = $operation['frozen_sizes'][ $other_name ];
			$other_geometry = image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
			if ( $other_geometry && ( $geometry[4] !== $other_geometry[4] || $geometry[5] !== $other_geometry[5] ) ) { return true; }
		}
		return false;
	}

	/** Predict possibly watermarked fallback paths from their old dimensions. */
	private function predict_changed_watermarked_legacy( $operation, $pre, $data, $upload_dir, $clean, $actual_targets = null ) {
		if ( ! empty( $operation['regenerate_request']['cleanup_stale'] ) ) {
			return [ 'entries' => $clean['entries'], 'new' => [], 'key_overflow' => false ];
		}
		$selected = [];
		foreach ( $operation['clean_legacy_options'] as $name => $active ) {
			if ( (int) $active === 1 ) { $selected[] = (string) $name; }
		}
		$flagged = ! empty( $operation['clean_legacy_flagged'] );
		$possibly = $this->possibly_watermarked_sizes( $operation, $selected, $flagged );
		$original = $flagged ? $this->proven_original_source( $operation['attachment_id'], $pre ) : $this->unwatermarked_original_source( $operation['attachment_id'], $pre );
		$dimensions = @getimagesize( $operation['clean_legacy_source'] );
		$source = $original !== null ? $this->oriented_source_dimensions( $pre, $upload_dir ) : ( $dimensions ? [ (int) $dimensions[0], (int) $dimensions[1] ] : null );
		$entries = $clean['entries'];
		$new = [];
		$overflow = false;
		$occupied = ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] ) + $clean['new'];
		$directory = realpath( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $pre['file'] ) ) );
		if ( ! is_array( $source ) || $directory === false ) { return [ 'entries' => $entries, 'new' => [], 'key_overflow' => false ]; }
		$prefix = dirname( $pre['file'] ) === '.' ? '' : dirname( $pre['file'] ) . '/';
		$stem = $this->upload_time_stem( $pre );
		$extension = pathinfo( $original === null ? $pre['file'] : $original['path'], PATHINFO_EXTENSION );
		$target_key = function( $relative ) use ( $upload_dir ) {
			$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
			return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
		};
		$targets = [ $target_key( $pre['file'] ) => true ];
		foreach ( $operation['frozen_sizes'] as $name => $definition ) {
			$geometry = image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
			if ( ! $geometry ) { continue; }
			$target = $prefix . $stem . '-' . $geometry[4] . 'x' . $geometry[5] . ( $extension === '' ? '' : '.' . $extension );
			if ( isset( $pre['sizes'][ $name ] ) && $this->name_stable_definition_unchanged( $definition, $pre['sizes'][ $name ], (int) $geometry[4], (int) $geometry[5], $source ) && strtolower( pathinfo( $pre['sizes'][ $name ]['path'], PATHINFO_EXTENSION ) ) === strtolower( $extension ) && $pre['sizes'][ $name ]['mime'] === wp_check_filetype( 'source.' . $extension )['type'] ) {
				$target = $pre['sizes'][ $name ]['path'];
			}
			$targets[ $target_key( $target ) ] = true;
		}
		// A previously shared path may lose all of its claims after the
		// post-generation split; reserve it until the actual groups are known.
		foreach ( $pre['sizes'] as $name => $old ) {
			if ( ! isset( $operation['frozen_sizes'][ $name ] ) ) { continue; }
			$definition = $operation['frozen_sizes'][ $name ];
			$geometry = image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
			if ( $geometry && $this->legacy_alias_may_split( $name, $old, $pre, $operation, $source, $geometry ) ) { unset( $targets[ $target_key( $old['path'] ) ] ); }
		}
		if ( is_array( $actual_targets ) ) {
			$targets = [ $target_key( $pre['file'] ) => true ];
			foreach ( $actual_targets as $target ) { $targets[ $target_key( $target ) ] = true; }
		}
		foreach ( $pre['sizes'] as $name => $old ) {
			$watermarked = $possibly['all'] || in_array( $name, $possibly['names'], true );
			foreach ( $pre['sizes'] as $other_name => $other ) {
				if ( $other['path'] === $old['path'] && in_array( $other_name, $possibly['names'], true ) ) { $watermarked = true; }
				if ( $other['path'] === $old['path'] && isset( $operation['preserved_sizes'][ $other_name ] ) ) { continue 2; }
			}
			if ( isset( $targets[ $target_key( $old['path'] ) ] ) || ! $watermarked ) { continue; }
			foreach ( $pre['sizes'] as $other_name => $other ) {
				if ( $other['path'] === $old['path'] && isset( $data['sizes'][ $other_name ] ) && $this->is_marked_legacy_size( $other_name, $data['sizes'][ $other_name ] ) ) { continue 2; }
			}
			$old_geometry = image_resize_dimensions( $source[0], $source[1], $old['width'], $old['height'], true );
			if ( ! ( ( $source[0] === $old['width'] && $source[1] === $old['height'] ) || ( $old_geometry && (int) $old_geometry[4] === $old['width'] && (int) $old_geometry[5] === $old['height'] ) ) ) { continue; }
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $old['path'] );
			$parent = realpath( dirname( $path ) );
			if ( ! is_file( $path ) || $parent === false || $this->ownership_reference_key( wp_normalize_path( $parent ) ) !== $this->ownership_reference_key( wp_normalize_path( $directory ) ) || ! isset( $operation['ownership'][ $old['path'] ] ) || $operation['ownership'][ $old['path'] ] !== 'exclusive' ) { continue; }
			foreach ( $pre['candidates'] as $paths ) { if ( in_array( $old['path'], $paths, true ) ) { continue 2; } }
			$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
			$key = $this->next_legacy_size_key( $base, $occupied, $operation['frozen_sizes'] + $operation['frozen_registered'] );
			$overflow = $overflow || strlen( $key ) > strlen( $base ) + 3;
			$entry = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'iw_legacy' => true ];
			$occupied[ $key ] = $entry;
			$bound = $key . '-99';
			while ( isset( $entries[ $bound ] ) ) { $bound .= 'x'; }
			$entries[ $bound ] = $entry;
			$new[ $key ] = $old;
			$targets[ $target_key( $old['path'] ) ] = true;
		}
		return [ 'entries' => $entries, 'new' => $new, 'key_overflow' => $overflow ];
	}

	/** A marked entry may be omitted only when its file can be retired safely. */
	private function cleanup_stale_exclusive( $operation, $relative, $upload_dir ) {
		$pre = $operation['pre_operation'];
		if ( $relative === $pre['file'] || ! isset( $operation['ownership'][ $relative ] ) || $operation['ownership'][ $relative ] !== 'exclusive' ) { return false; }
		$original = $pre['original_image'] === '' ? '' : ( dirname( $pre['file'] ) === '.' ? '' : dirname( $pre['file'] ) . '/' ) . $pre['original_image'];
		if ( $relative === $original ) { return false; }
		// Multiple marked entries can be removed together, but an ordinary
		// current alias must not lose its file. The inherited map also contains
		// historical names whose current entries may now point elsewhere.
		$current = $operation['stored_metadata'];
		foreach ( isset( $current['sizes'] ) && is_array( $current['sizes'] ) ? $current['sizes'] : [] as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) ) { unset( $current['sizes'][ $name ] ); }
		}
		if ( $this->name_stable_is_referenced( $this->name_stable_references( $current, $upload_dir ), $relative, $upload_dir ) ) { return false; }
		foreach ( isset( $operation['preserved_sizes'] ) ? $operation['preserved_sizes'] : [] as $name => $entry ) {
			if ( isset( $pre['sizes'][ $name ] ) && $pre['sizes'][ $name ]['path'] === $relative ) { return false; }
		}
		$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$main = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $pre['file'] ) );
		$parent = realpath( dirname( $path ) );
		$main = realpath( $main );
		$identity = is_file( $path ) ? $this->operation_path_identity( $path, $upload_dir ) : false;
		return $parent !== false && $main !== false && $identity !== false && ! is_link( $path ) && $this->ownership_reference_key( basename( $identity['relative'] ) ) === $this->ownership_reference_key( basename( $relative ) ) && $this->ownership_reference_key( wp_normalize_path( $parent ) ) === $this->ownership_reference_key( wp_normalize_path( $main ) );
	}

	/**
	 * Predict clean changed registered or unregistered paths for remove or an
	 * unflagged regenerate.
	 * Other legacy cases remain for the later OD03-1 implementation.
	 *
	 * @return array
	 */
	private function predict_clean_legacy_entries( $operation, $pre, $data, $upload_dir, $actual_fallback_paths = [] ) {
		$entries = [];
		$occupied = isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [];
		foreach ( $occupied as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) && ( empty( $operation['regenerate_request']['cleanup_stale'] ) || ! isset( $pre['sizes'][ $name ] ) || ! $this->cleanup_stale_exclusive( $operation, $pre['sizes'][ $name ]['path'], $upload_dir ) ) ) {
				$entries[ $name ] = $entry;
			}
		}
		if ( ! empty( $operation['regenerate_request']['cleanup_stale'] ) ) {
			return [ 'entries' => $entries, 'new' => [], 'limit' => $this->legacy_size_preflight( $entries ), 'key_overflow' => false ];
		}
		$selected = [];
		foreach ( $operation['clean_legacy_options'] as $name => $active ) {
			if ( (int) $active === 1 ) {
				$selected[] = (string) $name;
			}
		}
		$flagged = ! empty( $operation['clean_legacy_flagged'] );
		$possibly = $this->possibly_watermarked_sizes( $operation, $selected, $flagged );
		$source = isset( $operation['early_regeneration_plan']['source_dimensions'] ) ? $operation['early_regeneration_plan']['source_dimensions'] : null;
		if ( $source === null && isset( $operation['clean_legacy_source'] ) ) {
			$original = $flagged ? $this->proven_original_source( $operation['attachment_id'], $pre ) : $this->unwatermarked_original_source( $operation['attachment_id'], $pre );
			$dimensions = @getimagesize( $operation['clean_legacy_source'] );
			$source = $original !== null ? $this->oriented_source_dimensions( $pre, $upload_dir ) : ( $dimensions ? [ (int) $dimensions[0], (int) $dimensions[1] ] : null );
		}
		$directory = realpath( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $pre['file'] ) ) );
		$seen = [];
		$new = [];
		$key_overflow = false;
		// Admission conservatively counts an old path even if a current output
		// may claim it; only the generated plan can prove that target survives.
		foreach ( $pre['sizes'] as $name => $old ) {
			$unregistered = ! isset( $operation['frozen_sizes'][ $name ] ) && ! isset( $operation['frozen_registered'][ $name ] );
			$registered = isset( $operation['frozen_sizes'][ $name ] );
			if ( ( ! $unregistered && ! $registered ) || isset( $operation['preserved_sizes'][ $name ] ) || $possibly['all'] || in_array( $name, $possibly['names'], true ) || isset( $seen[ $old['path'] ] ) ) {
				continue;
			}
			$unsafe = false;
			foreach ( $pre['sizes'] as $other_name => $other ) {
				if ( $other['path'] === $old['path'] && ( in_array( $other_name, $possibly['names'], true ) || isset( $operation['preserved_sizes'][ $other_name ] ) || ( isset( $occupied[ $other_name ] ) && $this->is_marked_legacy_size( $other_name, $occupied[ $other_name ] ) ) ) ) {
					$unsafe = true;
				}
			}
			foreach ( $pre['candidates'] as $paths ) {
				$unsafe = $unsafe || in_array( $old['path'], $paths, true );
			}
			if ( $registered && ! in_array( $old['path'], $actual_fallback_paths, true ) ) {
				if ( ! is_array( $source ) ) { continue; }
				$definition = $operation['frozen_sizes'][ $name ];
				$dimensions = image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
				$original = $flagged ? $this->proven_original_source( $operation['attachment_id'], $pre ) : $this->unwatermarked_original_source( $operation['attachment_id'], $pre );
				$extension = pathinfo( $original === null ? $pre['file'] : $original['path'], PATHINFO_EXTENSION );
				if ( ! $dimensions || ( $this->name_stable_definition_unchanged( $definition, $old, (int) $dimensions[4], (int) $dimensions[5], $source ) && strtolower( pathinfo( $old['path'], PATHINFO_EXTENSION ) ) === strtolower( $extension ) && $old['mime'] === wp_check_filetype( 'source.' . $extension )['type'] && ! $this->legacy_alias_may_split( $name, $old, $pre, $operation, $source, $dimensions ) ) ) { continue; }
			}
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $old['path'] );
			$parent = realpath( dirname( $path ) );
			if ( $unsafe || $old['path'] === $pre['file'] || ! is_file( $path ) || $directory === false || $parent === false || $this->ownership_reference_key( wp_normalize_path( $directory ) ) !== $this->ownership_reference_key( wp_normalize_path( $parent ) ) || ! isset( $operation['ownership'][ $old['path'] ] ) || $operation['ownership'][ $old['path'] ] !== 'exclusive' ) {
				continue;
			}
			$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
			$key = $this->next_legacy_size_key( $base, $occupied, $operation['frozen_sizes'] + $operation['frozen_registered'] );
			$key_overflow = $key_overflow || strlen( $key ) > strlen( $base ) + 3;
			$entry = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'iw_legacy' => true ];
			$occupied[ $key ] = $entry;
			$bound_key = $key . '-99';
			while ( isset( $entries[ $bound_key ] ) ) {
				$bound_key .= 'x';
			}
			$entries[ $bound_key ] = $entry;
			$new[ $key ] = $old;
			$seen[ $old['path'] ] = true;
		}
		return [ 'entries' => $entries, 'new' => $new, 'limit' => $this->legacy_size_preflight( $entries ), 'key_overflow' => $key_overflow ];
	}

	/**
	 * Dimensions of the oriented pre-scale source, as core used them for the
	 * upload-time sub-sizes. EXIF orientation is read from metadata only.
	 *
	 * @return int[]|null
	 */
	private function oriented_source_dimensions( $pre, $upload_dir ) {
		if ( $pre['original_image'] === '' ) {
			return $pre['width'] > 0 && $pre['height'] > 0 ? [ $pre['width'], $pre['height'] ] : null;
		}
		$directory = dirname( $pre['file'] );
		$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . ( $directory === '.' || $directory === '' ? '' : str_replace( '/', DIRECTORY_SEPARATOR, $directory ) . DIRECTORY_SEPARATOR ) . $pre['original_image'];
		$dimensions = is_file( $path ) ? @getimagesize( $path ) : false;
		if ( ! $dimensions ) {
			return null;
		}
		$orientation = 0;
		if ( function_exists( 'wp_read_image_metadata' ) ) {
			$meta = wp_read_image_metadata( $path );
			$orientation = is_array( $meta ) && isset( $meta['orientation'] ) ? (int) $meta['orientation'] : 0;
		}
		return $orientation >= 5 && $orientation <= 8 ? [ (int) $dimensions[1], (int) $dimensions[0] ] : [ (int) $dimensions[0], (int) $dimensions[1] ];
	}

	/**
	 * Check whether the current definition produced the old file. Primary test:
	 * expected geometry from the oriented pre-scale source. Fallback: equal fixed
	 * axes and at most 1 px on the free axis.
	 *
	 * @return bool
	 */
	private function name_stable_definition_unchanged( $definition, $old, $new_width, $new_height, $source ) {
		$bound_width = (int) $definition['width'];
		$bound_height = (int) $definition['height'];
		$crop = $definition['crop'];
		if ( $old['width'] < 1 || $old['height'] < 1 ) {
			return false;
		}
		if ( is_array( $source ) ) {
			$expected = image_resize_dimensions( $source[0], $source[1], $bound_width, $bound_height, $crop );
			if ( $expected && (int) $expected[4] === $old['width'] && (int) $expected[5] === $old['height'] ) {
				return true;
			}
		}
		if ( $crop ) {
			return $old['width'] === $new_width && $old['height'] === $new_height;
		}
		$width_fixed = $bound_width > 0 && $old['width'] === $bound_width && $new_width === $bound_width;
		$height_fixed = $bound_height > 0 && $old['height'] === $bound_height && $new_height === $bound_height;
		if ( $width_fixed && ! $height_fixed ) {
			return abs( $old['height'] - $new_height ) <= 1;
		}
		if ( $height_fixed && ! $width_fixed ) {
			return abs( $old['width'] - $new_width ) <= 1;
		}
		return $old['width'] === $new_width && $old['height'] === $new_height;
	}

	/**
	 * Check remap compatibility for one size; decide ownership and conflicts later.
	 *
	 * @return true|string
	 */
	private function name_stable_remap_check( $name, $old, $entry, $new_relative, $registered, $source, $upload_dir ) {
		if ( ! isset( $registered[ $name ] ) || ! $this->name_stable_definition_unchanged( $registered[ $name ], $old, isset( $entry['width'] ) ? (int) $entry['width'] : 0, isset( $entry['height'] ) ? (int) $entry['height'] : 0, $source ) ) {
			return 'definition_changed';
		}
		$mime = isset( $entry['mime-type'] ) && is_string( $entry['mime-type'] ) ? $entry['mime-type'] : (string) wp_check_filetype( $new_relative )['type'];
		if ( $mime !== $old['mime'] || strtolower( pathinfo( $new_relative, PATHINFO_EXTENSION ) ) !== strtolower( pathinfo( $old['path'], PATHINFO_EXTENSION ) ) ) {
			return 'mime_changed';
		}
		// Directory identity, not its spelling: a junction or link may name the
		// same physical directory differently.
		$old_directory = realpath( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $old['path'] ) ) );
		$new_directory = realpath( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $new_relative ) ) );
		if ( $old_directory === false || $new_directory === false || $this->ownership_reference_key( wp_normalize_path( $old_directory ) ) !== $this->ownership_reference_key( wp_normalize_path( $new_directory ) ) ) {
			return 'directory_changed';
		}
		if ( ! $this->verify_operation_file( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $new_relative ) ) ) {
			return 'remap_source_missing';
		}
		foreach ( $entry as $key => $value ) {
			if ( ! in_array( $key, [ 'file', 'width', 'height', 'mime-type', 'filesize' ], true ) && strpos( (string) maybe_serialize( $value ), basename( $new_relative ) ) !== false ) {
				return 'remap_skipped_extended_metadata';
			}
		}
		return true;
	}

	/**
	 * Find size names whose old files may carry a watermark.
	 *
	 * @return array{all:bool,names:string[]}
	 */
	private function possibly_watermarked_sizes( $operation, $selected, $flagged ) {
		$journal = $operation['journal'];
		$records = [ isset( $journal['current'] ) ? $journal['current'] : null, isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ];
		if ( ! empty( $journal['history'] ) && is_array( $journal['history'] ) ) {
			$records = array_merge( $records, $journal['history'] );
		}
		$names = $selected;
		$completed_apply = false;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['type'] ) || ! in_array( $record['type'], [ 'apply', 'regenerate' ], true ) ) {
				continue;
			}
			$has_watermark_output = false;
			foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
				if ( ! is_array( $target ) ) {
					continue;
				}
				// A completed record narrows the conservative fallback only when
				// it actually promoted a watermarked output. A clean regenerate
				// has clean-output targets only and must not narrow a later remove.
				if ( ! isset( $target['intent'] ) || $target['intent'] === 'watermark-output' ) {
					$has_watermark_output = true;
				}
				if ( empty( $target['aliases'] ) || ! is_array( $target['aliases'] ) || ( isset( $target['intent'] ) && $target['intent'] !== 'watermark-output' ) || ( isset( $target['state'] ) && in_array( $target['state'], [ 'pending', 'allocated' ], true ) ) ) {
					continue;
				}
				foreach ( $target['aliases'] as $alias ) {
					if ( is_string( $alias ) ) {
						$names[] = $alias;
					}
				}
			}
			if ( $has_watermark_output && isset( $record['state'] ) && $record['state'] === 'complete' ) {
				$completed_apply = true;
			}
		}
		return [ 'all' => $flagged && ! $completed_apply, 'names' => array_values( array_unique( $names ) ) ];
	}

	/**
	 * Build the name-stable plan for freshly generated metadata: remaps onto old
	 * paths, carried-forward entries, reported sizes, and retirement candidates.
	 * One bounded ownership pass covers every path the pre-mutation pass did not.
	 *
	 * @return array
	 */
	private function plan_name_stable_derivatives( &$operation, $generated, $upload_dir, $purpose, $options, $flagged ) {
		$pre = $operation['pre_operation'];
		$directory = dirname( $pre['file'] );
		$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
		$registered = wp_get_registered_image_subsizes();
		$requested = isset( $operation['requested_sizes'] ) && is_array( $operation['requested_sizes'] ) ? $operation['requested_sizes'] : null;
		$selected = [];
		foreach ( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [] as $size => $active ) {
			if ( (int) $active === 1 && (string) $size !== 'full' ) {
				$selected[] = (string) $size;
			}
		}
		$possibly = $this->possibly_watermarked_sizes( $operation, $selected, $flagged );
		$fresh = [];
		foreach ( isset( $generated['sizes'] ) && is_array( $generated['sizes'] ) ? $generated['sizes'] : [] as $name => $entry ) {
			if ( is_string( $name ) && is_array( $entry ) && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ) {
				$fresh[ $name ] = $prefix . $entry['file'];
			}
		}
		$plan = [
			'metadata'    => $generated,
			'previous'    => [],
			'regenerated' => $fresh,
			'remaps'      => [],
			'retire'      => [],
			'outcomes'    => [],
			'skipped'     => [],
			'failed'      => [],
			'written'     => [],
			'partial'     => [],
			'retired'     => [],
			'retiring'    => [],
			'ownership'   => isset( $operation['ownership'] ) && is_array( $operation['ownership'] ) ? $operation['ownership'] : [],
		];
		if ( ! empty( $pre['stale'] ) ) {
			$plan['partial'][] = 'predecessor_map_stale';
		}
		$source = $this->oriented_source_dimensions( $pre, $upload_dir );
		$pending = [];
		$fallback = [];
		$retained = [];
		foreach ( $pre['sizes'] as $name => $old ) {
			$plan['previous'][ $name ] = $old['path'];
			if ( isset( $fresh[ $name ] ) ) {
				$plan['written'][] = $name;
				if ( $fresh[ $name ] === $old['path'] ) {
					$plan['outcomes'][ $name ] = 'retained';
					continue;
				}
				$check = $this->name_stable_remap_check( $name, $old, $generated['sizes'][ $name ], $fresh[ $name ], $registered, $source, $upload_dir );
				if ( $check === true ) {
					$pending[ $name ] = [ $old['path'], $fresh[ $name ] ];
				} elseif ( $check === 'remap_source_missing' ) {
					// An offloader removed the fresh file: keep the old file and its entry,
					// so metadata never names the missing output, and report it.
					array_pop( $plan['written'] );
					$plan['skipped'][ $name ] = $check;
					$plan['partial'][] = $check;
					$retained[ $name ] = 'retained_remap_source_missing';
				} else {
					// Changed definitions keep the 2.0.16 handling of the old path.
					if ( $check === 'remap_skipped_extended_metadata' ) {
						$plan['skipped'][ $name ] = $check;
					}
					$fallback[ $name ] = $old['path'];
				}
				continue;
			}
			if ( ! isset( $registered[ $name ] ) ) {
				if ( $flagged ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_size_gone' ];
				} else {
					$plan['outcomes'][ $name ] = 'left_unreconciled';
				}
				continue;
			}
			$watermarked = $possibly['all'] || in_array( $name, $possibly['names'], true );
			$definition = $registered[ $name ];
			if ( ! image_resize_dimensions( $pre['width'], $pre['height'], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ) ) {
				$plan['skipped'][ $name ] = 'size_not_producible';
				if ( $watermarked ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_not_producible' ];
				} else {
					$retained[ $name ] = 'retained_not_producible';
				}
				continue;
			}
			if ( is_array( $requested ) && ! in_array( $name, $requested, true ) ) {
				$plan['skipped'][ $name ] = 'size_filtered';
				if ( $purpose === 'remove' && $watermarked ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_filtered' ];
				} else {
					$retained[ $name ] = 'retained_filtered';
				}
				continue;
			}
			$plan['failed'][] = $name;
			$plan['partial'][] = 'size_generation_failed';
			$retained[ $name ] = 'retained_generation_failed';
		}
		// Sizes registered since the pre-operation snapshot were written too.
		foreach ( array_keys( $fresh ) as $name ) {
			if ( ! isset( $pre['sizes'][ $name ] ) ) {
				$plan['written'][] = $name;
			}
		}

		$needed = array_keys( $plan['retire'] );
		foreach ( $pending as $paths ) {
			$needed[] = $paths[0];
			$needed[] = $paths[1];
		}
		foreach ( $fallback as $path ) {
			$needed[] = $path;
		}
		foreach ( $pre['candidates'] as $paths ) {
			$needed = array_merge( $needed, $paths );
		}
		$unknown = array_values( array_diff( array_unique( $needed ), array_keys( $plan['ownership'] ) ) );
		if ( ! empty( $unknown ) ) {
			$pass = ! empty( $operation['ownership_uncertain'] ) ? [ 'paths' => array_fill_keys( $unknown, 'uncertain' ) ] : $this->derivative_paths_ownership( $operation['attachment_id'], $unknown, $upload_dir, $this->operation_ownership_deadline( $operation ) );
			$plan['ownership'] = array_merge( $plan['ownership'], $pass['paths'] );
		}

		$by_target = [];
		foreach ( $pending as $name => $paths ) {
			$by_target[ $paths[0] ][ $paths[1] ][] = $name;
		}
		foreach ( $by_target as $target => $sources ) {
			$names = [];
			foreach ( $sources as $source_names ) {
				$names = array_merge( $names, $source_names );
			}
			$other_fresh = array_diff_key( $fresh, array_flip( $names ) );
			if ( count( $sources ) > 1 || in_array( $target, $other_fresh, true ) ) {
				foreach ( $names as $name ) {
					$plan['skipped'][ $name ] = 'remap_conflict';
					$fallback[ $name ] = $target;
				}
				continue;
			}
			$from = key( $sources );
			$ownership = isset( $plan['ownership'][ $target ] ) ? $plan['ownership'][ $target ] : 'uncertain';
			if ( $ownership !== 'exclusive' ) {
				foreach ( $names as $name ) {
					$plan['skipped'][ $name ] = $ownership === 'shared' ? 'remap_skipped_shared' : 'ownership_uncertain';
					$plan['outcomes'][ $name ] = $ownership === 'shared' ? 'retained_shared' : 'retained_ownership_uncertain';
				}
				if ( $ownership !== 'shared' ) {
					$plan['partial'][] = 'ownership_uncertain';
				}
				continue;
			}
			$plan['remaps'][ $target ] = [
				'names'    => $names,
				'from'     => $from,
				'to'       => $target,
				'state'    => 'planned',
				// Only a reapply renders selected sizes onto their old paths; remove
				// copies every fresh clean output.
				'selected' => $purpose === 'reapply' && count( array_intersect( $names, $selected ) ) > 0,
			];
			foreach ( $names as $name ) {
				$plan['metadata']['sizes'][ $name ]['file'] = basename( $target );
				$plan['outcomes'][ $name ] = 'remapped';
			}
			$plan['retire'][ $from ] = [ 'key' => $names[0] . '#source', 'reason' => 'retire_remapped_source' ];
		}
		foreach ( $fallback as $name => $path ) {
			if ( ! isset( $plan['retire'][ $path ] ) ) {
				$plan['retire'][ $path ] = [ 'key' => $name, 'reason' => 'retire_replaced' ];
			}
		}
		foreach ( $pre['candidates'] as $name => $paths ) {
			foreach ( $paths as $path ) {
				if ( ! isset( $plan['retire'][ $path ] ) ) {
					$plan['retire'][ $path ] = [ 'key' => $name . '#intermediate', 'reason' => 'retire_intermediate' ];
				}
			}
		}
		foreach ( $retained as $name => $outcome ) {
			$old = $pre['sizes'][ $name ];
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $old['path'] );
			$plan['outcomes'][ $name ] = $outcome;
			if ( is_file( $path ) ) {
				$plan['metadata']['sizes'][ $name ] = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'filesize' => (int) filesize( $path ) ];
			}
		}
		if ( $pre['original_image'] !== '' && is_file( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . ( $prefix === '' ? '' : str_replace( '/', DIRECTORY_SEPARATOR, $prefix ) ) . $pre['original_image'] ) ) {
			$plan['metadata']['original_image'] = $pre['original_image'];
		}
		return $plan;
	}

	/**
	 * Reserved size name for one OD03-1 output: `iw-legacy-{W}x{H}-{hash16}`,
	 * where `hash16` is the first 16 hex characters of the SHA-256 of the
	 * output's uploads-relative path.
	 *
	 * @param int    $width         Output width.
	 * @param int    $height        Output height.
	 * @param string $relative_path Uploads-relative target path.
	 * @return string
	 */
	private function legacy_size_key( $width, $height, $relative_path ) {
		return self::LEGACY_SIZE_PREFIX . (int) $width . 'x' . (int) $height . '-' . substr( hash( 'sha256', (string) $relative_path ), 0, 16 );
	}

	/**
	 * Choose an unused reserved key for a base key. An existing metadata entry
	 * or a registered size name is never overwritten or reused; the first unused
	 * `-2`, `-3`, ... suffix is chosen.
	 *
	 * @param string $base       Base reserved key.
	 * @param array  $occupied   Size map that must not be overwritten.
	 * @param array  $registered Registered sub-size definitions.
	 * @return string
	 */
	private function next_legacy_size_key( $base, $occupied, $registered ) {
		$key    = $base;
		$suffix = 1;
		while ( isset( $occupied[ $key ] ) || isset( $registered[ $key ] ) ) {
			$suffix++;
			$key = $base . '-' . $suffix;
		}

		return $key;
	}

	/**
	 * R12 preflight over the complete marked legacy-entry map. Both the entry
	 * count and the serialized byte size are measured; the byte bound is the
	 * contract's `strlen( serialize() )` of the map.
	 *
	 * @param array $entries Marked size entries keyed by reserved name.
	 * @return array{count:int,bytes:int,exceeded:bool,code:string}
	 */
	private function legacy_size_preflight( $entries ) {
		$entries  = is_array( $entries ) ? $entries : [];
		$count    = count( $entries );
		$bytes    = strlen( serialize( $entries ) );
		$exceeded = $count > self::LEGACY_SIZE_LIMIT || $bytes > self::LEGACY_SIZE_MAX_BYTES;

		return [
			'count'    => $count,
			'bytes'    => $bytes,
			'exceeded' => $exceeded,
			'code'     => $exceeded ? 'legacy_limit_reached' : '',
		];
	}

	/**
	 * Plan OD03-1 clean outputs and their reserved entries without changing a
	 * file, the metadata, or the journal. R3-3: a path a surviving current
	 * target group owns is never an OD03-1 target and is reported as a
	 * conflict; every path appears at most once in the promotion plan.
	 *
	 * Existing marked entries are carried forward only while their files exist
	 * and are exclusively owned, and only while no current target group owns
	 * their path. A marked key is reused only for its own path and only when it
	 * shadows no registered size; otherwise the first unused suffix is chosen.
	 * Writing the returned entries into metadata and promoting their outputs is
	 * the live OD03-1 work owned by P03-04c2b, which calls this after the
	 * surviving target groups are known and before any mutation. This record
	 * only builds and proves the model.
	 *
	 * @param array $pre             Pre-operation snapshot with `file`.
	 * @param array $stored_sizes    Stored metadata sizes before the operation.
	 * @param array $generated_sizes Freshly generated metadata sizes.
	 * @param array $candidates      Old sizes selected for OD03-1 (name => path, width, height, mime).
	 * @param array $current_targets Uploads-relative paths surviving target groups own.
	 * @param array $registered      Registered sub-size definitions.
	 * @param array $ownership       Path => exclusive|shared|uncertain.
	 * @param array $upload_dir      Upload directory.
	 * @return array{outputs:array<string,array>,entries:array<string,array>,conflicts:array<int,array>,skipped:array<string,string>,limit:array}
	 */
	private function plan_legacy_size_outputs( $pre, $stored_sizes, $generated_sizes, $candidates, $current_targets, $registered, $ownership, $upload_dir ) {
		$plan = [
			'outputs'   => [],
			'entries'   => [],
			'conflicts' => [],
			'skipped'   => [],
			'limit'     => $this->legacy_size_preflight( [] ),
		];
		if ( ! is_array( $pre ) || empty( $pre['file'] ) || ! is_string( $pre['file'] ) || ! is_array( $upload_dir ) || empty( $upload_dir['basedir'] ) ) {
			return $plan;
		}

		$stored_sizes    = is_array( $stored_sizes ) ? $stored_sizes : [];
		$generated_sizes = is_array( $generated_sizes ) ? $generated_sizes : [];
		$candidates      = is_array( $candidates ) ? $candidates : [];
		$registered      = is_array( $registered ) ? $registered : [];
		$ownership       = is_array( $ownership ) ? $ownership : [];
		$directory       = dirname( $pre['file'] );
		$prefix          = $directory === '.' || $directory === '' ? '' : $directory . '/';
		$current         = [];
		foreach ( is_array( $current_targets ) ? $current_targets : [] as $target ) {
			$relative = $this->normalize_operation_relative_path( $target );
			if ( $relative !== false ) {
				$current[ $relative ] = true;
			}
		}
		$occupied = $stored_sizes + $generated_sizes;
		$paths    = [];

		// Existing marked entries whose path no current target group owns are
		// carry-forward candidates; the file and ownership checks follow below.
		foreach ( $stored_sizes as $name => $entry ) {
			if ( ! $this->is_marked_legacy_size( $name, $entry ) || empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
				continue;
			}
			$target = $this->normalize_operation_relative_path( $prefix . $entry['file'] );
			if ( $target !== false && ! isset( $paths[ $target ] ) ) {
				$paths[ $target ] = [ 'name' => (string) $name, 'entry' => $entry, 'source' => 'carried-forward' ];
			}
		}

		// Old sizes the caller selected for an OD03-1 output. One path yields
		// one output even when several old names shared the file.
		foreach ( $candidates as $name => $old ) {
			if ( ! is_array( $old ) || empty( $old['path'] ) || ! is_string( $old['path'] ) ) {
				continue;
			}
			$target = $this->normalize_operation_relative_path( $old['path'] );
			if ( $target === false || isset( $paths[ $target ] ) ) {
				continue;
			}
			$paths[ $target ] = [ 'name' => (string) $name, 'old' => $old, 'source' => 'planned' ];
		}

		foreach ( $paths as $target => $item ) {
			if ( isset( $current[ $target ] ) ) {
				// R3-3: the current target group alone determines this path.
				$plan['conflicts'][] = [ 'target' => $target, 'size' => $item['name'], 'code' => 'legacy_target_conflict' ];
				$plan['skipped'][ $target ] = 'legacy_target_conflict';
				continue;
			}
			if ( $target === $pre['file'] ) {
				$plan['skipped'][ $target ] = 'main_file';
				continue;
			}
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $target );
			if ( ! is_file( $path ) ) {
				$plan['skipped'][ $target ] = 'file_missing';
				continue;
			}
			$ownership_state = isset( $ownership[ $target ] ) ? $ownership[ $target ] : 'uncertain';
			if ( $ownership_state !== 'exclusive' ) {
				$plan['skipped'][ $target ] = $ownership_state === 'shared' ? 'retained_shared' : 'ownership_uncertain';
				continue;
			}

			$key = '';
			$relative_file = $prefix !== '' && strpos( $target, $prefix ) === 0 ? substr( $target, strlen( $prefix ) ) : $target;
			if ( $item['source'] === 'carried-forward' ) {
				$entry  = $item['entry'];
				$width  = isset( $entry['width'] ) ? (int) $entry['width'] : 0;
				$height = isset( $entry['height'] ) ? (int) $entry['height'] : 0;
				$mime   = isset( $entry['mime-type'] ) ? (string) $entry['mime-type'] : (string) wp_check_filetype( $target )['type'];
				if ( ! isset( $registered[ $item['name'] ] ) && ! isset( $generated_sizes[ $item['name'] ] ) ) {
					$key = $item['name'];
				}
			} else {
				$old    = $item['old'];
				$width  = isset( $old['width'] ) ? (int) $old['width'] : 0;
				$height = isset( $old['height'] ) ? (int) $old['height'] : 0;
				$mime   = isset( $old['mime'] ) ? (string) $old['mime'] : (string) wp_check_filetype( $target )['type'];
				$entry  = [
					'file'      => $relative_file,
					'width'     => $width,
					'height'    => $height,
					'mime-type' => $mime,
					'iw_legacy' => true,
				];
			}
			if ( $key === '' ) {
				$key = $this->next_legacy_size_key( $this->legacy_size_key( $width, $height, $target ), $occupied, $registered );
			}
			$entry['iw_legacy']        = true;
			$occupied[ $key ]           = $entry;
			$plan['entries'][ $key ]    = $entry;
			$plan['outputs'][ $target ] = [
				'key'       => $key,
				'target'    => $target,
				'width'     => $width,
				'height'    => $height,
				'mime-type' => $mime,
				'file'      => $entry['file'],
				'source'    => $item['source'],
			];
		}

		$plan['limit'] = $this->legacy_size_preflight( $plan['entries'] );
		return $plan;
	}

	/** @return array */
	private function name_stable_remap_journal( $remaps ) {
		$entries = [];
		foreach ( $remaps as $remap ) {
			$entries[] = array_intersect_key( $remap, array_flip( [ 'names', 'from', 'to', 'state', 'size', 'hash', 'selected' ] ) );
		}
		return $entries;
	}

	/** @return bool */
	private function write_name_stable_recovery( &$operation, $plan ) {
		// derivative_recovery is rewritten whole, so remap is always carried forward.
		return $this->set_operation_state( $operation, 'metadata-commit', [
			'derivative_recovery' => [
				'previous'    => $plan['previous'],
				'regenerated' => $plan['regenerated'],
				'outcomes'    => $plan['outcomes'],
				'retired'     => $plan['retired'],
				'retiring'    => $plan['retiring'],
				'remap'       => $this->name_stable_remap_journal( $plan['remaps'] ),
			],
		] );
	}

	/** Stop before promotion while keeping discovered overflow paths as carriers. */
	private function discovered_legacy_overflow( &$operation, $data, $method, $context, $plan, $paths ) {
		foreach ( $operation['pre_operation']['sizes'] as $name => $old ) {
			if ( in_array( $old['path'], $paths, true ) ) { $plan['outcomes'][ $name ] = 'legacy_limit_reached'; }
		}
		// These generated names were never promoted and must not become
		// intermediate retirement obligations on retry.
		$plan['regenerated'] = [];
		if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermark recovery state could not be updated.', 'image-watermark' ), true );
		}
		return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'legacy_limit_reached', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ), true );
	}

	/** Bound carried entries and only the predicted or actual new paths supplied. */
	private function legacy_prediction_bound_entries( $data, $sets ) {
		$marked = [];
		foreach ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) ) { $marked[ $name ] = $entry; }
		}
		$paths = [];
		foreach ( $sets as $set ) {
			foreach ( $set as $key => $old ) {
				if ( isset( $paths[ $old['path'] ] ) ) { continue; }
				$paths[ $old['path'] ] = true;
				$bound_key = $key . '-99';
				while ( isset( $marked[ $bound_key ] ) ) { $bound_key .= 'x'; }
				$marked[ $bound_key ] = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'iw_legacy' => true ];
			}
		}
		return $marked;
	}

	/** @return string uploads-relative remap stage path derived from token and target. */
	private function name_stable_remap_stage_relative( $to, $token ) {
		$directory = dirname( $to );
		return ( $directory === '.' || $directory === '' ? '' : $directory . '/' ) . basename( $this->operation_stage_path( $to, $token, 'remap-' . $to ) );
	}

	/** @return array[] Journal-proven remap stage artifacts of one record. */
	private function name_stable_remap_artifacts( $record ) {
		$artifacts = [];
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! is_string( $record['token'] ) || empty( $record['derivative_recovery']['remap'] ) || ! $this->is_valid_name_stable_remap( $record['derivative_recovery']['remap'] ) ) {
			return $artifacts;
		}
		foreach ( $record['derivative_recovery']['remap'] as $entry ) {
			if ( ! empty( $entry['selected'] ) ) {
				continue;
			}
			$artifacts[] = [ 'target' => $entry['to'], 'stage' => $this->name_stable_remap_stage_relative( $entry['to'], $record['token'] ), 'root' => 'uploads' ];
		}
		return $artifacts;
	}

	/**
	 * Copy one fresh clean output to a token-owned stage beside its old path and
	 * promote it there for an unselected size.
	 *
	 * @return bool
	 */
	private function execute_name_stable_remap( &$operation, &$plan, $to, $upload_dir, $options ) {
		$remap = $plan['remaps'][ $to ];
		$context = [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $to ];
		$source = $this->resolve_upload_artifact_path( $remap['from'] );
		$target = $this->resolve_upload_artifact_path( $remap['to'] );
		$stage_relative = $this->name_stable_remap_stage_relative( $remap['to'], $operation['token'] );
		$stage = $this->resolve_upload_artifact_path( $stage_relative );
		if ( $source === false || $target === false || $stage === false || $stage['parent'] !== $target['parent'] || ! $this->verify_operation_file( $source['path'] ) ) {
			return false;
		}
		$stage_path = $stage['path'];
		$plan['remaps'][ $to ]['state'] = 'allocated';
		if ( ! $this->write_name_stable_recovery( $operation, $plan ) || ! $this->copy_file( $source['path'], $stage_path, 'stage', $operation['attachment_id'], $options ) || ! $this->verify_operation_file( $stage_path ) ) {
			return false;
		}
		$plan['remaps'][ $to ]['size'] = (int) filesize( $stage_path );
		$plan['remaps'][ $to ]['hash'] = hash_file( 'sha256', $stage_path );
		$plan['remaps'][ $to ]['state'] = 'staged';
		if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'after_remap_stage', $context ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the accepted atomic staged promotion.
		if ( ! $this->renew_operation_lock( $operation ) || ( $stage = $this->resolve_upload_artifact_path( $stage_relative ) ) === false || ( $target = $this->resolve_upload_artifact_path( $remap['to'] ) ) === false || ! @rename( $stage['path'], $target['path'] ) || ! $this->verify_operation_file( $target['path'] ) || (string) filesize( $target['path'] ) !== (string) $plan['remaps'][ $to ]['size'] || hash_file( 'sha256', $target['path'] ) !== $plan['remaps'][ $to ]['hash'] ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'after_remap_promote', $context ) ) {
			return false;
		}
		$plan['remaps'][ $to ]['state'] = 'promoted';
		return $this->write_name_stable_recovery( $operation, $plan );
	}

	/**
	 * Reference set of one attachment's metadata: textual uploads-relative keys
	 * plus the canonical identity of every existing referenced file, so a
	 * differently named link to a file counts as a reference to it.
	 *
	 * @return array{keys:array<string,bool>,identities:array<string,bool>}
	 */
	private function name_stable_references( $metadata, $upload_dir ) {
		$references = [ 'keys' => [], 'identities' => [] ];
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) ) {
			return $references;
		}
		$directory = dirname( $metadata['file'] );
		$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
		$paths = [ $metadata['file'] ];
		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$paths[] = $prefix . $metadata['original_image'];
		}
		foreach ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : [] as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
				$paths[] = $prefix . $size['file'];
			}
		}
		foreach ( $paths as $path ) {
			$references['keys'][ $this->ownership_reference_key( $path ) ] = true;
			$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path ), $upload_dir );
			if ( $identity !== false ) {
				$references['identities'][ $identity['identity'] ] = true;
			}
		}
		return $references;
	}

	/** @return bool */
	private function name_stable_is_referenced( $references, $relative, $upload_dir ) {
		if ( isset( $references['keys'][ $this->ownership_reference_key( $relative ) ] ) ) {
			return true;
		}
		$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
		return $identity !== false && isset( $references['identities'][ $identity['identity'] ] );
	}

	/**
	 * Retire planned paths that no reference of this attachment names, only under
	 * an exclusive ownership proof. Before the final metadata
	 * commit, a path the stored metadata still names (core's intermediate writes
	 * name the fresh outputs) is deferred until after that commit, so a refused or
	 * interrupted commit never leaves stored metadata naming a deleted file.
	 *
	 * @param array|null $stored Currently stored metadata, or null after the final commit.
	 * @return string[] Size names whose retirement failed.
	 */
	private function retire_name_stable_paths( &$operation, &$plan, $upload_dir, $stored = null ) {
		$references = $this->name_stable_references( $plan['metadata'], $upload_dir );
		$stored_references = is_array( $stored ) ? $this->name_stable_references( $stored, $upload_dir ) : null;
		if ( $stored === null ) {
			// After the final commit, the durable value is authoritative: a metadata
			// filter may have added references the plan does not know about.
			$durable = $this->name_stable_references( wp_get_attachment_metadata( $operation['attachment_id'], true ), $upload_dir );
			$references['keys'] = array_merge( $references['keys'], $durable['keys'] );
			$references['identities'] = array_merge( $references['identities'], $durable['identities'] );
		}
		$predecessor = isset( $operation['journal']['recoverable_predecessor']['derivative_recovery'] ) && is_array( $operation['journal']['recoverable_predecessor']['derivative_recovery'] ) ? $operation['journal']['recoverable_predecessor']['derivative_recovery'] : [];
		$previously = array_merge( isset( $predecessor['retired'] ) && is_array( $predecessor['retired'] ) ? $predecessor['retired'] : [], isset( $predecessor['retiring'] ) && is_array( $predecessor['retiring'] ) ? $predecessor['retiring'] : [] );
		$failed = [];
		if ( ! isset( $plan['deferred'] ) ) {
			$plan['deferred'] = [];
		}
		foreach ( $plan['retire'] as $relative => $item ) {
			$name = preg_replace( '/#[a-z]+$/', '', $item['key'] );
			// A final metadata sanitizer can revive an inherited path not named by
			// either the plan or the currently stored metadata.
			if ( $stored_references !== null && ! empty( $operation['inherited_suspect'] ) && $this->name_stable_is_referenced( $operation['inherited_suspect'], $relative, $upload_dir ) ) {
				$plan['deferred'][ $relative ] = $item;
				continue;
			}
			if ( $this->name_stable_is_referenced( $references, $relative, $upload_dir ) ) {
				$plan['outcomes'][ $item['key'] ] = 'retained_referenced';
				continue;
			}
			if ( $stored_references !== null && $this->name_stable_is_referenced( $stored_references, $relative, $upload_dir ) ) {
				$plan['deferred'][ $relative ] = $item;
				$plan['outcomes'][ $item['key'] ] = 'retire_after_commit';
				if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
					$failed[] = $name;
					break;
				}
				continue;
			}
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ! file_exists( $path ) ) {
				if ( in_array( $relative, $previously, true ) ) {
					$plan['retired'][] = $relative;
					$plan['outcomes'][ $item['key'] ] = 'retired_predecessor';
					if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
						$failed[] = $name;
						break;
					}
				} else {
					$plan['outcomes'][ $item['key'] ] = 'absent';
				}
				continue;
			}
			// The recorded path must still resolve under uploads before it can be
			// deleted. Never infer ownership from a matching filename.
			$identity = $this->operation_path_identity( $path, $upload_dir );
			// A path spelled through an equivalent directory (a junction or a
			// directory link inside uploads) is the same file; a leaf that is itself
			// a link, or that resolves to another name, is never followed.
			if ( $identity === false || ( $identity['relative'] !== $relative && ( is_link( $path ) || $this->ownership_reference_key( basename( $identity['relative'] ) ) !== $this->ownership_reference_key( basename( $relative ) ) ) ) ) {
				$failed[] = $name;
				continue;
			}
			$ownership = isset( $plan['ownership'][ $relative ] ) ? $plan['ownership'][ $relative ] : 'uncertain';
			if ( $ownership !== 'exclusive' ) {
				$plan['outcomes'][ $item['key'] ] = $ownership === 'shared' ? 'retained_shared' : 'retained_ownership_uncertain';
				if ( $ownership !== 'shared' ) {
					$plan['partial'][] = 'ownership_uncertain';
					if ( ! isset( $plan['skipped'][ $name ] ) ) {
						$plan['skipped'][ $name ] = 'ownership_uncertain';
					}
				}
				if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
					$failed[] = $name;
					break;
				}
				continue;
			}
			$plan['outcomes'][ $item['key'] ] = $item['reason'];
			$plan['retiring'][] = $relative;
			if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
				$failed[] = $name;
				break;
			}
			$context = [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $relative ];
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Retire only a lock-owned obsolete derivative proven exclusive and unreferenced.
			if ( ( $item['reason'] === 'retire_remapped_source' && $this->should_inject_operation_fault( 'before_remap_source_retire', $context ) ) || $this->should_inject_operation_fault( 'derivative_retire', $context ) || ! $this->renew_operation_lock( $operation ) || ! @unlink( $path ) ) {
				$failed[] = $name;
				continue;
			}
			if ( $this->should_inject_operation_fault( 'after_derivative_retire', $context ) ) {
				$failed[] = $name;
				continue;
			}
			$plan['retired'][] = $relative;
			if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
				$failed[] = $name;
				break;
			}
		}
		return array_values( array_unique( $failed ) );
	}

	/**
	 * Retire what the pre-commit pass deferred, once the final metadata is
	 * durable and no longer names those paths.
	 *
	 * @return string[] Size names whose retirement failed.
	 */
	private function retire_deferred_name_stable_paths( &$operation, &$plan, $upload_dir ) {
		if ( empty( $plan['deferred'] ) ) {
			return [];
		}
		$plan['retire'] = $plan['deferred'];
		$plan['deferred'] = [];
		return $this->retire_name_stable_paths( $operation, $plan, $upload_dir );
	}

	/**
	 * Promote the unselected remaps, then retire obsolete paths. Selected remaps
	 * were already rendered onto their old paths by the watermark staging.
	 *
	 * @return string[] Size names that failed.
	 */
	private function execute_name_stable_plan( &$operation, &$plan, $upload_dir, $options ) {
		if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
			return array_keys( $plan['previous'] ) ?: [ 'all' ];
		}
		$failed = [];
		foreach ( array_keys( $plan['remaps'] ) as $to ) {
			if ( ! empty( $plan['remaps'][ $to ]['selected'] ) ) {
				$plan['remaps'][ $to ]['state'] = 'promoted';
				continue;
			}
			if ( ! $this->execute_name_stable_remap( $operation, $plan, $to, $upload_dir, $options ) ) {
				$failed = array_merge( $failed, $plan['remaps'][ $to ]['names'] );
			}
		}
		if ( ! empty( $failed ) ) {
			return $failed;
		}
		$stored = wp_get_attachment_metadata( $operation['attachment_id'], true );
		return $this->retire_name_stable_paths( $operation, $plan, $upload_dir, is_array( $stored ) ? $stored : [] );
	}

	/** @return bool */
	private function name_stable_identity_changed( $attachment_id, $generated, $pre ) {
		$attached = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_array( $generated ) || empty( $generated['file'] ) || $generated['file'] !== $pre['file'] || $attached !== $pre['file'] ) {
			return true;
		}
		if ( $pre['width'] > 0 && $pre['height'] > 0 && ( ! isset( $generated['width'], $generated['height'] ) || (int) $generated['width'] !== $pre['width'] || (int) $generated['height'] !== $pre['height'] ) ) {
			return true;
		}
		if ( $pre['mime'] !== '' && (string) wp_check_filetype( $generated['file'] )['type'] !== $pre['mime'] ) {
			return true;
		}
		return ! $this->name_stable_main_file_intact( $pre, wp_upload_dir() );
	}

	/**
	 * The main file itself still has the pre-operation geometry and format. A
	 * callback may resize or convert it in place while returning metadata that
	 * says otherwise, or nothing at all. Bytes may differ (an optimizer may
	 * recompress it); identity is path, name, dimensions, and format.
	 *
	 * @return bool
	 */
	private function name_stable_main_file_intact( $pre, $upload_dir ) {
		$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $pre['file'] );
		clearstatcache( true, $path );
		$actual = is_file( $path ) ? @getimagesize( $path ) : false;
		if ( ! $actual ) {
			return false;
		}
		return ( $pre['width'] < 1 || (int) $actual[0] === $pre['width'] ) && ( $pre['height'] < 1 || (int) $actual[1] === $pre['height'] ) && ( $pre['mime'] === '' || $actual['mime'] === $pre['mime'] );
	}

	/**
	 * If another filter changed the main file's identity, restore
	 * metadata and the attached file to pre_operation, then retire the variant and
	 * its sub-sizes once nothing of this attachment references them. A main
	 * file changed in place is put back only with the exclusive ownership
	 * proof (`$main_restorable`); otherwise it is left as the callback left it.
	 *
	 * @return array{committed:bool,plan:array}
	 */
	private function rollback_name_stable_identity( &$operation, $generated, $upload_dir, $clean_source, $options, $main_restorable = true ) {
		$pre = $operation['pre_operation'];
		if ( ! $main_restorable && ! $this->name_stable_main_file_intact( $pre, $upload_dir ) ) {
			return [ 'committed' => false, 'plan' => [], 'kept' => true ];
		}
		// A callback changed the main file in place: put the clean source back first.
		if ( ! $this->name_stable_main_file_intact( $pre, $upload_dir ) && ( ! $this->stage_copy_and_promote( $operation, $clean_source, $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $pre['file'] ), 'identity-restore', $options ) || ! $this->name_stable_main_file_intact( $pre, $upload_dir ) ) ) {
			return [ 'committed' => false, 'plan' => [] ];
		}
		$directory = dirname( $pre['file'] );
		$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
		$restored = is_array( $generated ) ? $generated : [];
		$restored['file'] = $pre['file'];
		if ( $pre['width'] > 0 && $pre['height'] > 0 ) {
			$restored['width'] = $pre['width'];
			$restored['height'] = $pre['height'];
		}
		$restored['sizes'] = [];
		foreach ( $pre['sizes'] as $name => $size ) {
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $size['path'] );
			if ( is_file( $path ) ) {
				$restored['sizes'][ $name ] = [ 'file' => basename( $size['path'] ), 'width' => $size['width'], 'height' => $size['height'], 'mime-type' => $size['mime'], 'filesize' => (int) filesize( $path ) ];
			}
		}
		unset( $restored['original_image'] );
		if ( $pre['original_image'] !== '' && is_file( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $prefix ) . $pre['original_image'] ) ) {
			$restored['original_image'] = $pre['original_image'];
		}
		$plan = [ 'metadata' => $restored, 'previous' => [], 'regenerated' => [], 'remaps' => [], 'retire' => [], 'outcomes' => [], 'skipped' => [], 'failed' => [], 'written' => [], 'partial' => [], 'retired' => [], 'retiring' => [], 'ownership' => [] ];
		foreach ( $pre['sizes'] as $name => $size ) {
			$plan['previous'][ $name ] = $size['path'];
		}
		if ( get_post_meta( $operation['attachment_id'], '_wp_attached_file', true ) !== $pre['file'] ) {
			update_post_meta( $operation['attachment_id'], '_wp_attached_file', $pre['file'] );
		}
		$sanitized_limit = false;
		$committed = get_post_meta( $operation['attachment_id'], '_wp_attached_file', true ) === $pre['file'] && $this->commit_checked_regenerated_metadata( $operation, $restored, $sanitized_limit );
		$this->set_regenerated_metadata_commit_state( $operation, $restored, $committed ? 'committed' : 'refused' );
		if ( ! $committed ) {
			return [ 'committed' => false, 'plan' => $plan ];
		}
		$plan['metadata'] = $restored;
		if ( is_array( $generated ) && ! empty( $generated['file'] ) && is_string( $generated['file'] ) && $generated['file'] !== $pre['file'] && $this->normalize_operation_relative_path( $generated['file'] ) === $generated['file'] ) {
			$plan['retire'][ $generated['file'] ] = [ 'key' => 'full#variant', 'reason' => 'retire_identity_variant' ];
		}
		$generated_directory = is_array( $generated ) && ! empty( $generated['file'] ) && is_string( $generated['file'] ) ? dirname( $generated['file'] ) : $directory;
		$generated_prefix = $generated_directory === '.' || $generated_directory === '' ? '' : $generated_directory . '/';
		foreach ( is_array( $generated ) && isset( $generated['sizes'] ) && is_array( $generated['sizes'] ) ? $generated['sizes'] : [] as $name => $entry ) {
			$relative = is_array( $entry ) && ! empty( $entry['file'] ) && is_string( $entry['file'] ) ? $this->normalize_operation_relative_path( $generated_prefix . $entry['file'] ) : false;
			if ( $relative !== false && ! isset( $plan['retire'][ $relative ] ) ) {
				$plan['retire'][ $relative ] = [ 'key' => (string) $name . '#source', 'reason' => 'retire_identity_variant' ];
			}
		}
		$plan['ownership'] = $this->derivative_paths_ownership( $operation['attachment_id'], array_keys( $plan['retire'] ), $upload_dir, $this->operation_ownership_deadline( $operation ) )['paths'];
		$this->retire_name_stable_paths( $operation, $plan, $upload_dir );
		return [ 'committed' => true, 'plan' => $plan ];
	}

	/**
	 * Watermark target groups of a name-stable reapply. A remapped selected size
	 * targets its old path and renders from its fresh clean output, so the old
	 * path never holds clean bytes while the flag is set.
	 *
	 * @return array|false
	 */
	private function name_stable_target_groups( $options, $plan, $original_file, $upload_dir ) {
		$metadata = $plan['metadata'];
		$directory = dirname( $metadata['file'] );
		$base = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . ( $directory === '.' || $directory === '' ? '' : str_replace( '/', DIRECTORY_SEPARATOR, $directory ) . DIRECTORY_SEPARATOR );
		$groups = [];
		foreach ( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [] as $size => $active ) {
			if ( (int) $active !== 1 ) {
				continue;
			}
			$size = (string) $size;
			if ( $size === 'full' ) {
				$destination = $original_file;
				$source = $original_file;
			} elseif ( isset( $metadata['sizes'][ $size ]['file'], $plan['regenerated'][ $size ] ) && is_string( $metadata['sizes'][ $size ]['file'] ) ) {
				// Only a freshly generated clean output is ever rendered. A retained old
				// file (filtered, failed, or kept because its fresh output went missing)
				// is left exactly as it was, so its watermark is never stacked.
				$destination = $base . $metadata['sizes'][ $size ]['file'];
				$source = null;
				foreach ( $plan['remaps'] as $remap ) {
					if ( ! empty( $remap['selected'] ) && in_array( $size, $remap['names'], true ) ) {
						$source = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $remap['from'] );
					}
				}
				if ( $source === null ) {
					if ( $this->ownership_reference_key( ( $directory === '.' || $directory === '' ? '' : $directory . '/' ) . $metadata['sizes'][ $size ]['file'] ) !== $this->ownership_reference_key( $plan['regenerated'][ $size ] ) ) {
						continue;
					}
					$source = $destination;
				}
			} else {
				continue;
			}
			if ( ! is_file( $source ) ) {
				continue;
			}
			$identity = $this->operation_path_identity( $destination, $upload_dir );
			if ( $identity === false ) {
				$relative = $this->operation_destination_relative_path( $destination, $upload_dir );
				if ( $relative === false || $source === $destination ) {
					return false;
				}
				$identity = [ 'relative' => $relative, 'identity' => 'missing:' . $this->ownership_reference_key( $relative ) ];
			}
			if ( ! isset( $groups[ $identity['identity'] ] ) ) {
				$groups[ $identity['identity'] ] = [ 'path' => $destination, 'source' => $source, 'target' => $identity['relative'], 'aliases' => [] ];
			}
			$groups[ $identity['identity'] ]['aliases'][] = $size;
		}
		return count( $groups ) > 50 ? false : $groups;
	}

	/** @return array[] Last-operation skipped entries of a plan. */
	private function name_stable_skipped_sizes( $plan ) {
		$skipped = [];
		foreach ( $plan['skipped'] as $name => $code ) {
			$skipped[] = [ 'name' => $name, 'code' => $code ];
		}
		return $skipped;
	}

	/** @return string The most significant partial code of a plan, or ''. */
	private function name_stable_partial_code( $plan ) {
		foreach ( [ 'size_generation_failed', 'directory_changed', 'remap_source_missing', 'remap_conflict', 'ownership_uncertain', 'retained_shared', 'predecessor_map_stale' ] as $code ) {
			if ( in_array( $code, $plan['partial'], true ) ) {
				return $code;
			}
		}
		return '';
	}

	/** @return string */
	private function name_stable_partial_message( $code, $purpose ) {
		switch ( $code ) {
			case 'main_identity_changed':
				return $purpose === 'reapply'
					? __( 'Regeneration would have renamed or converted the main image file, so its previous file name and metadata were restored. Some selected image sizes may no longer be watermarked; apply the watermark again after resolving the conversion.', 'image-watermark' )
					: __( 'Regeneration would have renamed or converted the main image file, so its previous file name and metadata were restored. Previous image sizes were kept; retry the removal after resolving the conversion.', 'image-watermark' );
			case 'directory_changed':
			case 'size_generation_failed':
				return __( 'Some image sizes could not be regenerated, so their previous files were kept. Retry the operation.', 'image-watermark' );
			case 'remap_source_missing':
				return __( 'Some regenerated image sizes were missing locally before they could take their previous file names, so the previous files were kept.', 'image-watermark' );
			case 'predecessor_map_stale':
				return __( 'The interrupted operation no longer matched this image, so the previous size file names could not be restored.', 'image-watermark' );
			case 'remap_conflict':
				return __( 'Two regenerated image sizes would have used the same file name, so the previous files of the conflicting sizes were kept.', 'image-watermark' );
			case 'retained_shared':
				return __( 'Some previous image size files are also used by another image, so they were left in place and those sizes still name them.', 'image-watermark' );
			case 'ownership_uncertain':
			default:
				return __( 'Some previous image size files could not be proven to belong only to this image, so they were left in place.', 'image-watermark' );
		}
	}

	/**
	 * Internal: big-image threshold suppression scoped to one guarded generation
	 * of the operated attachment only. Not a public extension point.
	 *
	 * @return int|false
	 */
	public function filter_guarded_generation_threshold( $threshold, $imagesize = [], $file = '', $attachment_id = 0 ) {
		return is_array( $this->guarded_generation ) && (int) $attachment_id === (int) $this->guarded_generation['attachment_id'] ? false : $threshold;
	}

	/**
	 * Internal: removes the output mapping for the main file's own MIME type while
	 * a guarded generation runs. The filter has no attachment argument, so this is
	 * scoped to that call's time window in this request.
	 *
	 * @return array
	 */
	public function filter_guarded_generation_output_format( $formats, $filename = null, $mime_type = '' ) {
		if ( is_array( $this->guarded_generation ) && is_array( $formats ) && $this->guarded_generation['mime'] !== '' ) {
			unset( $formats[ $this->guarded_generation['mime'] ] );
		}
		return $formats;
	}

	/**
	 * Internal: observes, without changing, the size list core was finally asked
	 * to create, so a filtered size is distinguished from a failed one.
	 *
	 * @return array
	 */
	public function observe_guarded_generation_sizes( $new_sizes, $image_meta = [], $attachment_id = 0 ) {
		if ( is_array( $this->guarded_generation ) && (int) $attachment_id === (int) $this->guarded_generation['attachment_id'] && is_array( $new_sizes ) ) {
			$this->guarded_generation['requested'] = array_map( 'strval', array_keys( $new_sizes ) );
		}
		return $new_sizes;
	}

	/**
	 * Freeze the effective size set of one operation: the registered sizes
	 * passed once through `intermediate_image_sizes_advanced`, with the stored
	 * metadata (its `sizes` emptied and its dimensions taken from the actual
	 * main file) and the attachment ID, then normalized as core's editors read
	 * a definition. Every later step of the operation uses only this set.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $data Stored attachment metadata.
	 * @param array $pre Pre-operation map.
	 * @return array<string,array> Name => { width, height, crop }, in filter order.
	 */
	private function freeze_effective_sizes( $attachment_id, $data, $pre ) {
		$meta = is_array( $data ) ? $data : [];
		$meta['sizes'] = [];
		$meta['width'] = $pre['width'];
		$meta['height'] = $pre['height'];
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', wp_get_registered_image_subsizes(), $meta, $attachment_id );
		$frozen = [];
		foreach ( is_array( $sizes ) ? $sizes : [] as $name => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$crop = isset( $definition['crop'] ) ? $definition['crop'] : false;
			$frozen[ (string) $name ] = [
				'width'  => isset( $definition['width'] ) && is_numeric( $definition['width'] ) ? (int) $definition['width'] : null,
				'height' => isset( $definition['height'] ) && is_numeric( $definition['height'] ) ? (int) $definition['height'] : null,
				'crop'   => is_array( $crop ) ? array_values( array_map( 'strval', $crop ) ) : (bool) $crop,
			];
		}

		return $frozen;
	}

	/**
	 * The stem core gave the upload-time sub-sizes: the untouched original's
	 * stem when the main file is its scaled or rotated copy, otherwise the main
	 * file's stem.
	 *
	 * @param array $pre Pre-operation map.
	 * @return string
	 */
	private function upload_time_stem( $pre ) {
		$main_stem = pathinfo( $pre['file'], PATHINFO_FILENAME );
		if ( $pre['original_image'] === '' ) {
			return $main_stem;
		}
		$original_stem = pathinfo( $pre['original_image'], PATHINFO_FILENAME );

		return preg_match( '/^' . preg_quote( $original_stem, '/' ) . '-(?:scaled|rotated|scaled-rotated)$/i', $main_stem ) ? $original_stem : $main_stem;
	}

	/**
	 * Whether the attachment was edited in the image editor: it holds editor
	 * backup sizes, its main file is not the scaled or rotated copy of its
	 * `original_image`, or its main file carries the editor's suffix.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $pre Pre-operation map.
	 * @return bool
	 */
	private function attachment_is_edited( $attachment_id, $pre ) {
		return $this->attachment_is_edited_from_values( $pre, get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) );
	}

	/** Evaluate editor evidence without reading attachment metadata. */
	private function attachment_is_edited_from_values( $pre, $backup_sizes ) {
		if ( is_array( $backup_sizes ) && ! empty( $backup_sizes ) ) {
			return true;
		}
		$main_stem = pathinfo( $pre['file'], PATHINFO_FILENAME );
		if ( preg_match( '/-e[0-9]{13}/', $main_stem ) ) {
			return true;
		}

		return $pre['original_image'] !== '' && $this->upload_time_stem( $pre ) === $main_stem;
	}

	/**
	 * The proven clean sub-size source of a watermarked attachment: its
	 * `original_image`, only when an upload provenance record names exactly
	 * that file, the file still has the recorded size and SHA-256, and the
	 * attachment is unedited. Existence and decoding never count as proof.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $pre Pre-operation map.
	 * @return array|null { path (uploads-relative), size, sha256 }, or null.
	 */
	private function proven_original_source( $attachment_id, $pre, $read_only = false ) {
		if ( $pre['original_image'] === '' || $this->attachment_is_edited( $attachment_id, $pre ) ) {
			return null;
		}
		$record = get_post_meta( $attachment_id, self::PROVENANCE_META_KEY, true );
		if ( ! is_array( $record ) || ! isset( $record['file'], $record['size'], $record['sha256'] ) || ! is_string( $record['file'] ) || $record['file'] !== $pre['original_image'] ) {
			return null;
		}
		$directory = dirname( $pre['file'] );
		$relative = ( $directory === '.' || $directory === '' ? '' : $directory . '/' ) . $pre['original_image'];
		$failure = null;
		$resolved = $this->resolve_upload_artifact_path( $relative, false, '', 0, $failure, $read_only );
		if ( $resolved === false || ! is_file( $resolved['path'] ) || filesize( $resolved['path'] ) !== (int) $record['size'] || hash_file( 'sha256', $resolved['path'] ) !== $record['sha256'] ) {
			return null;
		}

		return [ 'path' => $resolved['relative'], 'size' => (int) $record['size'], 'sha256' => $record['sha256'] ];
	}

	/**
	 * Validate a journaled generation stage against its registered shape and
	 * the record's own token.
	 *
	 * @param mixed  $stage Value of `generation_stage`.
	 * @param string $token Owning record token.
	 * @return bool
	 */
	private function is_valid_generation_stage( $stage, $token ) {
		if ( ! is_array( $stage ) || ! isset( $stage['version'], $stage['state'], $stage['directory'], $stage['prefix'], $stage['sources'], $stage['sizes'], $stage['planned'], $stage['outputs'], $stage['legacy'] ) || $stage['version'] !== 1 || ! in_array( $stage['state'], [ 'intent', 'copied', 'generated' ], true ) ) {
			return false;
		}
		if ( array_key_exists( 'pre_generation_retention', $stage ) && ( $stage['pre_generation_retention'] !== true || $stage['state'] !== 'intent' || ! empty( $stage['outputs'] ) || ! empty( $stage['legacy'] ) ) ) {
			return false;
		}
		$prefix = '.iw-stage-' . $token . '-';
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{64}$/', $token ) || $stage['prefix'] !== $prefix || ! is_string( $stage['directory'] ) || ( $stage['directory'] !== '.' && $this->normalize_operation_relative_path( $stage['directory'] ) !== $stage['directory'] ) ) {
			return false;
		}
		$is_stage = static function( $name ) use ( $prefix ) {
			return is_string( $name ) && $name !== '' && $name === wp_basename( $name ) && strpos( $name, $prefix ) === 0 && strpos( $name, '..' ) === false && strpos( $name, ':' ) === false && strpos( $name, "\0" ) === false;
		};
		if ( ! is_array( $stage['sources'] ) || empty( $stage['sources'] ) || count( $stage['sources'] ) > 2 || ! is_array( $stage['sizes'] ) || count( $stage['sizes'] ) > 200 || ! is_array( $stage['planned'] ) || count( $stage['planned'] ) > count( $stage['sizes'] ) || ! is_array( $stage['outputs'] ) || count( $stage['outputs'] ) > count( $stage['sizes'] ) || ! is_array( $stage['legacy'] ) || count( $stage['legacy'] ) > 50 ) {
			return false;
		}
		foreach ( $stage['sources'] as $index => $source ) {
			if ( ! is_array( $source ) || ! isset( $source['role'], $source['root'], $source['path'], $source['size'], $source['sha256'], $source['stage'] ) || ! in_array( $source['role'], [ 'main', 'generation' ], true ) || ! in_array( $source['root'], [ 'backup', 'uploads' ], true ) || $this->normalize_operation_relative_path( $source['path'] ) !== $source['path'] || ! is_int( $source['size'] ) || $source['size'] < 1 || ! is_string( $source['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $source['sha256'] ) || ! $is_stage( $source['stage'] ) ) {
				return false;
			}
			if ( ( $source['role'] === 'main' ) !== ( $index === 0 ) || ( $source['role'] === 'main' ? ! isset( $source['authority'] ) || ! in_array( $source['authority'], [ 'backup', 'manifest', 'live' ], true ) : array_key_exists( 'authority', $source ) ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'source_collision', $stage ) ) {
			$roles = array_column( $stage['sources'], 'role' );
			if ( $stage['state'] !== 'intent' || empty( $stage['pre_generation_retention'] ) || ! is_string( $stage['source_collision'] ) || ! in_array( $stage['source_collision'], array_merge( $roles, [ 'all' ] ), true ) ) {
				return false;
			}
		}
		foreach ( $stage['sizes'] as $name => $definition ) {
			if ( ! is_array( $definition ) || ! array_key_exists( 'width', $definition ) || ! array_key_exists( 'height', $definition ) || ! array_key_exists( 'crop', $definition ) || ( $definition['width'] !== null && ! is_int( $definition['width'] ) ) || ( $definition['height'] !== null && ! is_int( $definition['height'] ) ) || ( ! is_bool( $definition['crop'] ) && ! is_array( $definition['crop'] ) ) ) {
				return false;
			}
		}
		foreach ( $stage['planned'] as $name ) {
			if ( ! $is_stage( $name ) ) {
				return false;
			}
		}
		foreach ( $stage['outputs'] as $output ) {
			if ( ! is_array( $output ) || ! isset( $output['names'], $output['stage'], $output['width'], $output['height'], $output['mime'], $output['size'], $output['sha256'] ) || ! is_array( $output['names'] ) || empty( $output['names'] ) || ! $is_stage( $output['stage'] ) || ! is_int( $output['width'] ) || ! is_int( $output['height'] ) || ! is_string( $output['mime'] ) || ! is_int( $output['size'] ) || $output['size'] < 1 || ! is_string( $output['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $output['sha256'] ) ) {
				return false;
			}
			foreach ( $output['names'] as $name ) {
				if ( ! is_string( $name ) || $name === '' ) {
					return false;
				}
			}
		}
		foreach ( $stage['legacy'] as $legacy ) {
			if ( ! is_array( $legacy ) || ! isset( $legacy['key'], $legacy['target'], $legacy['width'], $legacy['height'], $legacy['stage'] ) || ! $is_stage( $legacy['stage'] ) || $this->normalize_operation_relative_path( $legacy['target'] ) !== $legacy['target'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Uploads-relative stage names of a record's generation stage that its
	 * cleanup may remove by exact name: the source copies, the planned and
	 * adopted outputs, and the legacy outputs. Empty without a valid stage.
	 *
	 * @param array $record Journal record.
	 * @return string[]
	 */
	private function generation_stage_artifacts( $record ) {
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! isset( $record['generation_stage'] ) || ! $this->is_valid_generation_stage( $record['generation_stage'], $record['token'] ) ) {
			return [];
		}
		$stage = $record['generation_stage'];
		$names = ! empty( $stage['pre_generation_retention'] ) ? [] : $stage['planned'];
		foreach ( $stage['sources'] as $source ) {
			if ( ! isset( $stage['source_collision'] ) || ( $stage['source_collision'] !== 'all' && $stage['source_collision'] !== $source['role'] ) ) {
				$names[] = $source['stage'];
			}
		}
		foreach ( $stage['outputs'] as $output ) {
			$names[] = $output['stage'];
		}
		foreach ( $stage['legacy'] as $legacy ) {
			$names[] = $legacy['stage'];
		}
		$directory = $stage['directory'] === '.' ? '' : $stage['directory'] . '/';
		$artifacts = [];
		foreach ( array_unique( $names ) as $name ) {
			$artifacts[] = $directory . $name;
		}
		// The render stages of the record's targets, journaled beside them.
		foreach ( isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [] as $target ) {
			if ( empty( $stage['pre_generation_retention'] ) && ! empty( $target['stage'] ) && is_string( $target['stage'] ) && strpos( wp_basename( $target['stage'] ), $stage['prefix'] ) === 0 && $this->normalize_operation_relative_path( $target['stage'] ) === $target['stage'] ) {
				$artifacts[] = $target['stage'];
			}
		}

		return array_values( array_unique( $artifacts ) );
	}

	/**
	 * Remove a record's generation stage files under its authority: first its
	 * exact journaled names, then at most `2 * count( sizes ) + 8` further
	 * regular files carrying its prefix among at most 10,000 directory
	 * entries. Every removal is a regular, non-link file whose real path is
	 * inside the stage directory.
	 *
	 * @param array $operation Operation holding the lease.
	 * @param array $record Journal record with a valid generation stage.
	 * @param array $retained_stages Target-stage paths retained by the caller.
	 * @return bool False when a removal failed or the lease was lost.
	 */
	private function cleanup_generation_stage( &$operation, $record, $retained_stages = [] ) {
		$artifacts = $this->generation_stage_artifacts( $record );
		if ( empty( $artifacts ) && ( empty( $record['token'] ) || ! isset( $record['generation_stage'] ) || ! $this->is_valid_generation_stage( $record['generation_stage'], $record['token'] ) ) ) {
			return true;
		}
		$stage = $record['generation_stage'];
		$clean = true;
		$directory_record = $stage['directory'] === '.' ? [ 'path' => wp_upload_dir()['basedir'] ] : $this->resolve_upload_artifact_path( $stage['directory'] );
		if ( $directory_record === false ) {
			return false;
		}
		$directory = realpath( $directory_record['path'] );
		$remove = function( $path ) use ( &$operation, $directory, $record, $retained_stages ) {
			foreach ( $retained_stages as $retained ) {
				if ( $this->paths_match( wp_normalize_path( $path ), wp_normalize_path( $retained ) ) ) {
					return false;
				}
			}
			clearstatcache( true, $path );
			if ( ! is_file( $path ) ) {
				return true;
			}
			$real = realpath( $path );
			if ( is_link( $path ) || $real === false || $directory === false || ! $this->paths_match( wp_normalize_path( dirname( $real ) ), wp_normalize_path( $directory ) ) ) {
				return false;
			}
			// This check is not atomic with unlink; independent reference and
			// pathname changes remain outside this narrow retention increment.
			if ( $this->predecessor_stage_may_be_referenced( basename( $path ) ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only a generation stage file this record's authority covers.
			return ! $this->should_inject_operation_fault( 'stage_cleanup', [ 'attachment_id' => $operation['attachment_id'], 'token' => $record['token'], 'stage' => basename( $path ) ] ) && $this->renew_operation_lock( $operation ) && @unlink( $path );
		};
		foreach ( $artifacts as $relative ) {
			$resolved = $this->resolve_upload_artifact_path( $relative );
			if ( $resolved === false || ! $remove( $resolved['path'] ) ) {
				$clean = false;
			}
		}
		if ( $directory === false ) {
			return false;
		}
		// Until the copied transition is durable, unowned planned and prefix
		// occupants must not be removed. Source cleanup is a separate limitation.
		$retaining = ! empty( $stage['pre_generation_retention'] );
		$limit = 2 * count( $stage['sizes'] ) + 8;
		$read = 0;
		$handle = @opendir( $directory );
		if ( $handle === false ) {
			return false;
		}
		while ( $limit > 0 && $read < 10000 && ( $entry = readdir( $handle ) ) !== false ) {
			$read++;
			if ( strpos( $entry, $stage['prefix'] ) !== 0 ) {
				continue;
			}
			$limit--;
			if ( $retaining || ! $remove( $directory . DIRECTORY_SEPARATOR . $entry ) ) {
				$clean = false;
			}
		}
		closedir( $handle );
		if ( $retaining && ( $limit === 0 || $read >= 10000 ) ) {
			$clean = false;
		}

		return $clean;
	}

	/**
	 * Internal: while a token-named generation runs, short-circuit every
	 * mutation of the operated attachment's `_wp_attachment_metadata`, so the
	 * stored value stays the pre-operation metadata until the final commit.
	 * Not a public extension point.
	 *
	 * @param mixed  $check Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function withhold_operation_metadata_mutation( $check, $object_id, $meta_key, $meta_value = null ) {
		$key = get_current_blog_id() . ':' . (int) $object_id;
		if ( $meta_key !== '_wp_attachment_metadata' ) {
			return $check;
		}
		if ( ! empty( $this->withheld_metadata[ $key ] ) ) {
			if ( ! empty( $this->metadata_commit_guards[ $key ] ) ) {
				foreach ( $this->metadata_commit_guards[ $key ] as &$guard ) { $guard['refused'] = true; }
				unset( $guard );
			}
			return true;
		}
		if ( empty( $this->metadata_commit_guards[ $key ] ) ) {
			return $check;
		}
		if ( $check !== null ) {
			if ( ! $check ) {
				foreach ( $this->metadata_commit_guards[ $key ] as &$guard ) { $guard['refused'] = true; }
				unset( $guard );
			}
			return $check;
		}
		$delete = current_filter() === 'delete_post_metadata';
		$exceeded = ! $delete && $this->metadata_marked_limit_exceeded( $meta_value );
		$lost_fence = false;
		foreach ( $this->metadata_commit_guards[ $key ] as &$guard ) {
			// Sanitizers and nested writes run after the earlier fence check.
			// Renew each active operation before permitting this SQL boundary.
			if ( ! $delete && ! $exceeded && ! $this->renew_operation_lock( $guard['operation'] ) ) { $lost_fence = true; }
		}
		unset( $guard );
		foreach ( $this->metadata_commit_guards[ $key ] as &$guard ) {
			if ( $delete || $exceeded || $lost_fence ) {
				$guard['refused'] = true;
				$guard['limit'] = $guard['limit'] || $exceeded;
			} else {
				$guard['seen'] = true;
				$guard['value'] = $meta_value;
			}
		}
		unset( $guard );
		return $delete || $exceeded || $lost_fence ? false : $check;
	}

	/**
	 * Start withholding the attachment's metadata writes for one operation.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string The hold's key, for release_operation_metadata_withhold().
	 */
	private function hold_operation_metadata( $attachment_id ) {
		$key = get_current_blog_id() . ':' . (int) $attachment_id;
		if ( empty( $this->withheld_metadata ) && empty( $this->metadata_commit_guards ) ) {
			$this->add_operation_metadata_filters();
		}
		$this->withheld_metadata[ $key ] = ( isset( $this->withheld_metadata[ $key ] ) ? $this->withheld_metadata[ $key ] : 0 ) + 1;

		return $key;
	}

	/**
	 * End one operation's hold, once; other holds stay.
	 *
	 * @param array $operation Operation; its `metadata_hold` is consumed.
	 * @return void
	 */
	private function release_operation_metadata_withhold( &$operation ) {
		if ( empty( $operation['metadata_hold'] ) ) {
			return;
		}
		$key = $operation['metadata_hold'];
		unset( $operation['metadata_hold'] );
		if ( isset( $this->withheld_metadata[ $key ] ) && --$this->withheld_metadata[ $key ] <= 0 ) {
			unset( $this->withheld_metadata[ $key ] );
		}
		$this->remove_unused_operation_metadata_filters();
	}

	/** Check only the existing marked-map limit, not the whole journal budget. */
	private function metadata_marked_limit_exceeded( $metadata ) {
		$marked = [];
		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $entry ) {
				if ( $this->is_marked_legacy_size( $name, $entry ) ) { $marked[ $name ] = $entry; }
			}
		}
		return $this->legacy_size_preflight( $marked )['exceeded'];
	}

	/** Register the existing hold filters also for the final sanitized write. */
	private function add_operation_metadata_filters() {
		add_filter( 'update_post_metadata', [ $this, 'withhold_operation_metadata_mutation' ], PHP_INT_MAX, 4 );
		add_filter( 'add_post_metadata', [ $this, 'withhold_operation_metadata_mutation' ], PHP_INT_MAX, 4 );
		add_filter( 'delete_post_metadata', [ $this, 'withhold_operation_metadata_mutation' ], PHP_INT_MAX, 4 );
	}

	private function remove_unused_operation_metadata_filters() {
		if ( empty( $this->withheld_metadata ) && empty( $this->metadata_commit_guards ) ) {
			remove_filter( 'update_post_metadata', [ $this, 'withhold_operation_metadata_mutation' ], PHP_INT_MAX );
			remove_filter( 'add_post_metadata', [ $this, 'withhold_operation_metadata_mutation' ], PHP_INT_MAX );
			remove_filter( 'delete_post_metadata', [ $this, 'withhold_operation_metadata_mutation' ], PHP_INT_MAX );
		}
	}

	/** Guard the actual sanitized value, including core's update-to-add fallback. */
	private function commit_checked_regenerated_metadata( &$operation, &$metadata, &$limit ) {
		$key = get_current_blog_id() . ':' . (int) $operation['attachment_id'];
		if ( empty( $this->withheld_metadata ) && empty( $this->metadata_commit_guards ) ) {
			$this->add_operation_metadata_filters();
		}
		$this->metadata_commit_guards[ $key ][] = [ 'refused' => false, 'limit' => false, 'seen' => false, 'value' => null, 'operation' => &$operation ];
		$index = count( $this->metadata_commit_guards[ $key ] ) - 1;
		try {
			if ( ! $this->read_stored_regenerated_metadata( $operation['attachment_id'], $before ) ) {
				return false;
			}
			$committed = $this->commit_regenerated_metadata( $operation['attachment_id'], $metadata );
			if ( ! $this->read_stored_regenerated_metadata( $operation['attachment_id'], $durable ) ) {
				return false;
			}
			$guard = $this->metadata_commit_guards[ $key ][ $index ];
			$limit = $guard['limit'] || $this->metadata_marked_limit_exceeded( $durable );
			// A later filter or writer can override the veto: a conflicting reread
			// prevents completion, but does not claim atomic isolation or rollback.
			$matches = $guard['seen'] ? $guard['value'] === $durable : $before === $durable;
			return $committed && ! $guard['refused'] && ! $limit && $matches && $this->renew_operation_lock( $operation );
		} finally {
			array_pop( $this->metadata_commit_guards[ $key ] );
			if ( empty( $this->metadata_commit_guards[ $key ] ) ) { unset( $this->metadata_commit_guards[ $key ] ); }
			$this->remove_unused_operation_metadata_filters();
		}
	}

	/**
	 * Short-circuit (as if done) every mutation of the attachment's
	 * `_wp_attachment_metadata` while core generates sizes from the stage.
	 * Not a public extension point.
	 *
	 * @param mixed  $check Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public function withhold_generation_metadata_mutation( $check, $object_id, $meta_key ) {
		if ( $meta_key === '_wp_attachment_metadata' && is_array( $this->guarded_generation ) && ! empty( $this->guarded_generation['withhold'] ) && (int) $object_id === (int) $this->guarded_generation['attachment_id'] ) {
			return true;
		}

		return $check;
	}

	/**
	 * Journal the generation intent and reread it before any stage file
	 * exists: sources, the frozen size set, and the planned output names.
	 *
	 * @param array $operation Operation holding the lease.
	 * @param array $sources Main source and, when it differs, generation source.
	 * @param array $frozen Frozen effective size set.
	 * @param array $pre Pre-operation map.
	 * @return bool
	 */
	private function begin_generation_stage( &$operation, $sources, $frozen, $pre ) {
		$prefix = '.iw-stage-' . $operation['token'] . '-';
		// The stage directory is the main file's canonical directory, however the
		// stored metadata spells it.
		$upload_dir = wp_upload_dir();
		$main = $this->operation_destination_relative_path( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $pre['file'] ), $upload_dir );
		if ( $main === false ) {
			return false;
		}
		$directory = dirname( $main );
		$generation = end( $sources );
		$stem = pathinfo( $generation['stage'], PATHINFO_FILENAME );
		$extension = pathinfo( $generation['stage'], PATHINFO_EXTENSION );
		$dimensions = $this->generation_source_dimensions( $generation, $pre );
		$planned = [];
		foreach ( $frozen as $definition ) {
			$expected = is_array( $dimensions ) && ( $definition['width'] !== null || $definition['height'] !== null ) ? image_resize_dimensions( $dimensions[0], $dimensions[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ) : false;
			if ( $expected ) {
				$planned[] = $stem . '-' . (int) $expected[4] . 'x' . (int) $expected[5] . ( $extension !== '' ? '.' . $extension : '' );
			}
		}
		$stage = [
			'version'   => 1,
			'state'     => 'intent',
			'pre_generation_retention' => true,
			'source_collision' => 'all',
			'directory' => $directory === '' ? '.' : $directory,
			'prefix'    => $prefix,
			'sources'   => array_values( $sources ),
			'sizes'     => $frozen,
			'planned'   => array_values( array_unique( $planned ) ),
			'outputs'   => [],
			'legacy'    => [],
		];
		if ( ! $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) {
			return false;
		}
		$journal = $this->read_operation_journal( $operation['attachment_id'] );
		$journaled = is_array( $journal ) && isset( $journal['current']['token'], $journal['current']['generation_stage'] ) && $journal['current']['token'] === $operation['token'] && $journal['current']['generation_stage'] === $stage;
		if ( $journaled && $this->should_inject_operation_fault( 'after_generation_intent', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			return false;
		}

		return $journaled;
	}

	/**
	 * Oriented dimensions of a generation source, as core rotates it by its
	 * stored EXIF orientation before resizing.
	 *
	 * @param array $source Generation source.
	 * @param array $pre Pre-operation map.
	 * @return int[]|null
	 */
	private function generation_source_dimensions( $source, $pre ) {
		if ( $source['role'] === 'main' ) {
			return $pre['width'] > 0 && $pre['height'] > 0 ? [ $pre['width'], $pre['height'] ] : null;
		}

		return $this->oriented_source_dimensions( $pre, $this->operation_upload_dir() );
	}

	/**
	 * Copy each source to its journaled stage name in the attachment's
	 * directory and verify size, SHA-256, and a full decode, then journal the
	 * state `copied`.
	 *
	 * @param array $operation Operation holding the lease.
	 * @param array $options Effective options (timestamp preservation).
	 * @return bool
	 */
	private function copy_generation_sources( &$operation, $options ) {
		$stage = $operation['journal']['current']['generation_stage'];
		$directory = $stage['directory'] === '.' ? '' : $stage['directory'] . '/';
		foreach ( $stage['sources'] as $index => $source ) {
			$from = $source['root'] === 'backup' ? $this->resolve_backup_path( $source['path'] ) : $this->resolve_upload_artifact_path( $source['path'] );
			$to = $this->resolve_upload_artifact_path( $directory . $source['stage'] );
			if ( $from === false || $to === false || ! $this->renew_operation_lock( $operation ) ) {
				return false;
			}
			// The durable intent retains all not-yet-copied sources, including
			// occupants that arrived before this copy or its callbacks.
			if ( ! $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) {
				return false;
			}
			$journal = $this->read_operation_journal( $operation['attachment_id'] );
			if ( ! is_array( $journal ) || ! isset( $journal['current']['token'], $journal['current']['generation_stage'] ) || $journal['current']['token'] !== $operation['token'] || $journal['current']['generation_stage'] !== $stage ) {
				return false;
			}
			$collision = false;
			$created = false;
			if ( ! $this->copy_file( $from['path'], $to['path'], 'stage', $operation['attachment_id'], $options, true, $collision, $created ) ) {
				return false;
			}
			clearstatcache( true, $to['path'] );
			if ( filesize( $to['path'] ) !== $source['size'] || hash_file( 'sha256', $to['path'] ) !== $source['sha256'] || ! $this->verify_operation_file( $to['path'] ) ) {
				return false;
			}
			if ( isset( $stage['sources'][ $index + 1 ] ) ) {
				$stage['source_collision'] = $stage['sources'][ $index + 1 ]['role'];
			} else {
				unset( $stage['source_collision'] );
			}
			$confirmed = false;
			try {
				if ( $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) {
					try {
						$journal = $this->read_operation_journal( $operation['attachment_id'] );
						$confirmed = is_array( $journal ) && isset( $journal['current']['token'], $journal['current']['generation_stage'] ) && $journal['current']['token'] === $operation['token'] && $journal['current']['generation_stage'] === $stage;
						if ( ! $confirmed ) {
							$operation['journal_uncertain'] = true;
						}
					} catch ( \Throwable $error ) {
						$operation['journal_uncertain'] = true;
						throw $error;
					}
				}
			} finally {
				if ( ! $confirmed ) {
					// A completed native exclusive copy is independently known in this
					// request. Try once to persist that disposition before abandoning it;
					// a filter-supplied copy, lost lease or persistent failure stays uncertain.
					$settled = false;
					if ( $created ) {
						try {
							if ( $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) {
								try {
									$journal = $this->read_operation_journal( $operation['attachment_id'] );
									$settled = is_array( $journal ) && isset( $journal['current']['token'], $journal['current']['generation_stage'] ) && $journal['current']['token'] === $operation['token'] && $journal['current']['generation_stage'] === $stage;
									if ( ! $settled ) {
										$operation['journal_uncertain'] = true;
									}
								} catch ( \Throwable $error ) {
									$operation['journal_uncertain'] = true;
									throw $error;
								}
							}
						} catch ( \Throwable $error ) {
							$settled = false;
						}
					}
				}
			}
			if ( ! $confirmed || $this->should_inject_operation_fault( 'after_generation_source_copy', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'role' => $source['role'] ] ) ) {
				return false;
			}
		}
		// This is only a point-in-time check of predicted outputs, not a fence
		// against subsequent writes or unpredicted names.
		foreach ( $stage['planned'] as $name ) {
			$planned = $this->resolve_upload_artifact_path( $directory . $name );
			if ( $planned !== false ) {
				clearstatcache( true, $planned['path'] );
			}
			if ( $planned === false || file_exists( $planned['path'] ) || is_link( $planned['path'] ) ) {
				return false;
			}
		}
		$stage['state'] = 'copied';
		unset( $stage['pre_generation_retention'] );
		if ( ! $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) {
			return false;
		}
		$journal = $this->read_operation_journal( $operation['attachment_id'] );
		return is_array( $journal ) && isset( $journal['current']['token'], $journal['current']['generation_stage'] ) && $journal['current']['token'] === $operation['token'] && $journal['current']['generation_stage'] === $stage;
	}

	/**
	 * Generate every frozen size from the generation source's stage with core's
	 * own sub-size routine, while the operated attachment's metadata
	 * mutations are withheld and the main file's output-format mapping is
	 * suppressed, then adopt the outputs grouped by physical stage file and
	 * journal them in state `generated`. An entry naming a file without the
	 * stage prefix is not adopted.
	 *
	 * @param array $operation Operation holding the lease.
	 * @param array $data Stored attachment metadata.
	 * @return array|false { outputs, metadata (working sizes by name), failed (names) }
	 */
	private function generate_from_stage( &$operation, $data ) {
		$attachment_id = $operation['attachment_id'];
		$stage = $operation['journal']['current']['generation_stage'];
		$directory = $stage['directory'] === '.' ? '' : $stage['directory'] . '/';
		$source = end( $stage['sources'] );
		$source_record = $this->resolve_upload_artifact_path( $directory . $source['stage'] );
		if ( $source_record === false || ! $this->renew_operation_lock( $operation ) ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'metadata_regeneration', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'], 'purpose' => isset( $operation['journal']['current']['type'] ) ? $operation['journal']['current']['type'] : '' ] ) ) {
			return false;
		}
		$working = is_array( $data ) ? $data : [];
		$working['sizes'] = [];
		$new_sizes = [];
		foreach ( $stage['sizes'] as $name => $definition ) {
			if ( ! isset( $operation['preserved_sizes'][ $name ] ) && ( ! isset( $operation['regenerate_request'] ) || $operation['regenerate_request']['mode'] !== 'subset' || in_array( (string) $name, $operation['regenerate_request']['sizes'], true ) ) ) {
				$new_sizes[ $name ] = $definition;
			}
		}
		$main_mime = (string) wp_check_filetype( get_post_meta( $attachment_id, '_wp_attached_file', true ) )['type'];
		$this->internal_operation_guard = [ 'attachment_id' => $attachment_id, 'token' => $operation['token'] ];
		$this->guarded_generation = [ 'attachment_id' => $attachment_id, 'mime' => $main_mime, 'requested' => null, 'withhold' => true ];
		add_filter( 'image_editor_output_format', [ $this, 'filter_guarded_generation_output_format' ], PHP_INT_MAX, 3 );
		add_filter( 'update_post_metadata', [ $this, 'withhold_generation_metadata_mutation' ], PHP_INT_MAX, 3 );
		add_filter( 'add_post_metadata', [ $this, 'withhold_generation_metadata_mutation' ], PHP_INT_MAX, 3 );
		add_filter( 'delete_post_metadata', [ $this, 'withhold_generation_metadata_mutation' ], PHP_INT_MAX, 3 );
		try {
			$generated = _wp_make_subsizes( $new_sizes, $source_record['path'], $working, $attachment_id );
		} finally {
			remove_filter( 'image_editor_output_format', [ $this, 'filter_guarded_generation_output_format' ], PHP_INT_MAX );
			remove_filter( 'update_post_metadata', [ $this, 'withhold_generation_metadata_mutation' ], PHP_INT_MAX );
			remove_filter( 'add_post_metadata', [ $this, 'withhold_generation_metadata_mutation' ], PHP_INT_MAX );
			remove_filter( 'delete_post_metadata', [ $this, 'withhold_generation_metadata_mutation' ], PHP_INT_MAX );
			$this->guarded_generation = null;
			$this->internal_operation_guard = null;
		}
		if ( ! is_array( $generated ) || ! $this->renew_operation_lock( $operation ) || $this->should_inject_operation_fault( 'after_generation', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'] ] ) ) {
			return false;
		}
		// Group the entries by the physical stage file they name.
		$by_file = [];
		$failed = [];
		foreach ( $new_sizes as $name => $definition ) {
			$entry = isset( $generated['sizes'][ $name ] ) && is_array( $generated['sizes'][ $name ] ) ? $generated['sizes'][ $name ] : null;
			if ( $entry === null || empty( $entry['file'] ) || ! is_string( $entry['file'] ) || $entry['file'] !== wp_basename( $entry['file'] ) || strpos( $entry['file'], $stage['prefix'] ) !== 0 ) {
				// Not produced, or a callback renamed or moved it out of the stage.
				$failed[] = (string) $name;
				continue;
			}
			$by_file[ $entry['file'] ]['names'][] = (string) $name;
			$by_file[ $entry['file'] ]['entries'][] = $entry;
		}
		$outputs = [];
		foreach ( $by_file as $file => $group ) {
			$resolved = $this->resolve_upload_artifact_path( $directory . $file );
			// A generated pathname can have been replaced before adoption. A
			// reference or an unreadable query is not authority to consume it.
			// This point-in-time check cannot protect the earlier write or a
			// replacement after the check.
			if ( $this->predecessor_stage_may_be_referenced( $file ) ) {
				$failed = array_merge( $failed, $group['names'] );
				continue;
			}
			$dimensions = $resolved !== false && is_file( $resolved['path'] ) && ! is_link( $resolved['path'] ) ? @getimagesize( $resolved['path'] ) : false;
			$valid = $dimensions && $this->verify_operation_file( $resolved['path'] );
			foreach ( $group['entries'] as $entry ) {
				$valid = $valid && isset( $entry['width'], $entry['height'] ) && (int) $entry['width'] === (int) $dimensions[0] && (int) $entry['height'] === (int) $dimensions[1];
			}
			if ( ! $valid ) {
				$failed = array_merge( $failed, $group['names'] );
				continue;
			}
			$outputs[] = [
				'names'  => $group['names'],
				'stage'  => $file,
				'width'  => (int) $dimensions[0],
				'height' => (int) $dimensions[1],
				'mime'   => isset( $group['entries'][0]['mime-type'] ) && is_string( $group['entries'][0]['mime-type'] ) ? $group['entries'][0]['mime-type'] : (string) $dimensions['mime'],
				'size'   => (int) filesize( $resolved['path'] ),
				'sha256' => hash_file( 'sha256', $resolved['path'] ),
			];
		}
		$stage['state'] = 'generated';
		$stage['outputs'] = $outputs;
		if ( ! $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) {
			return false;
		}

		return [ 'outputs' => $outputs, 'failed' => $failed, 'entries' => isset( $generated['sizes'] ) && is_array( $generated['sizes'] ) ? $generated['sizes'] : [] ];
	}

	/**
	 * Plan the promotions of a token-named generation: every name of an
	 * adopted output gets its canonical target (its old path when its frozen
	 * definition still produced that file, otherwise the upload-time stem with
	 * the output's dimensions); targets claimed twice are resolved by the
	 * old-path holder rule, and losers are `remap_conflict`. Names that were not
	 * regenerated follow the name-stable retain and retire rules. A path a target
	 * group is assigned is never also retired.
	 *
	 * @param array  $operation Operation holding the lease.
	 * @param array  $generation Result of generate_from_stage().
	 * @param array  $upload_dir Upload directory.
	 * @param string $purpose remove or reapply.
	 * @param array  $options Effective options.
	 * @param bool   $flagged Whether the attachment is flagged watermarked.
	 * @return array Plan: groups, metadata, retire, outcomes, skipped, failed, partial, previous, regenerated, ownership.
	 */
	private function plan_generation_targets( &$operation, $generation, $upload_dir, $purpose, $options, $flagged ) {
		$pre = $operation['pre_operation'];
		$stage = $operation['journal']['current']['generation_stage'];
		$frozen = $stage['sizes'];
		$directory = dirname( $pre['file'] );
		$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
		$stem = $this->upload_time_stem( $pre );
		$registered = isset( $operation['frozen_registered'] ) && is_array( $operation['frozen_registered'] ) ? $operation['frozen_registered'] : wp_get_registered_image_subsizes();
		$selected = [];
		foreach ( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [] as $size => $active ) {
			if ( (int) $active === 1 && (string) $size !== 'full' ) {
				$selected[] = (string) $size;
			}
		}
		$possibly = $this->possibly_watermarked_sizes( $operation, $selected, $flagged );
		$source = $this->generation_source_dimensions( end( $stage['sources'] ), $pre );
		$plan = [
			'metadata'    => null,
			'groups'      => [],
			'previous'    => [],
			'regenerated' => [],
			'remaps'      => [],
			'retire'      => [],
			'outcomes'    => [],
			'skipped'     => [],
			'failed'      => [],
			'written'     => [],
			'partial'     => [],
			'retired'     => [],
			'retiring'    => [],
			'ownership'   => isset( $operation['ownership'] ) && is_array( $operation['ownership'] ) ? $operation['ownership'] : [],
		];
		if ( ! empty( $pre['stale'] ) ) {
			$plan['partial'][] = 'predecessor_map_stale';
		}
		foreach ( $pre['sizes'] as $name => $old ) {
			$plan['previous'][ $name ] = $old['path'];
		}
		// Canonical target per adopted name.
		$claims = [];
		$fallback = [];
		foreach ( $generation['outputs'] as $index => $output ) {
			$extension = pathinfo( $output['stage'], PATHINFO_EXTENSION );
			$canonical = $prefix . $stem . '-' . $output['width'] . 'x' . $output['height'] . ( $extension !== '' ? '.' . $extension : '' );
			foreach ( $output['names'] as $name ) {
				$target = $canonical;
				if ( isset( $pre['sizes'][ $name ] ) ) {
					$old = $pre['sizes'][ $name ];
					$unchanged = $this->name_stable_definition_unchanged( $frozen[ $name ], $old, $output['width'], $output['height'], $source )
						&& $this->name_stable_definition_unchanged( $frozen[ $name ], $old, $output['width'], $output['height'], null );
					$same_format = $output['mime'] === $old['mime'] && strtolower( pathinfo( $old['path'], PATHINFO_EXTENSION ) ) === strtolower( $extension ) && dirname( $old['path'] ) === dirname( $prefix . 'x' );
					if ( $unchanged && $same_format ) {
						$target = $old['path'];
					} else {
						$fallback[ $name ] = $old['path'];
					}
				}
				$claims[ $name ] = [ 'target' => $target, 'output' => $index, 'canonical' => $canonical ];
			}
		}
		// Resolve targets claimed by more than one output, then recheck after
		// every fallback until no target is claimed twice.
		$lost = [];
		for ( $round = 0; $round < 4; $round++ ) {
			$by_target = [];
			foreach ( $claims as $name => $claim ) {
				$by_target[ $claim['target'] ][ $claim['output'] ][] = $name;
			}
			$changed = false;
			foreach ( $by_target as $target => $by_output ) {
				if ( count( $by_output ) < 2 ) {
					continue;
				}
				$holders = [];
				foreach ( $by_output as $output => $names ) {
					foreach ( $names as $name ) {
						if ( isset( $pre['sizes'][ $name ] ) && $pre['sizes'][ $name ]['path'] === $target ) {
							$holders[ $output ] = true;
						}
					}
				}
				foreach ( $by_output as $output => $names ) {
					if ( count( $holders ) === 1 && isset( $holders[ $output ] ) ) {
						continue;
					}
					foreach ( $names as $name ) {
						$claim = $claims[ $name ];
						if ( count( $holders ) > 1 && $claim['target'] !== $claim['canonical'] && $round === 0 ) {
							// Names that shared this file at upload each take their own
							// canonical name; the old path follows the retire rules.
							$claims[ $name ]['target'] = $claim['canonical'];
							$fallback[ $name ] = $target;
						} else {
							$lost[ $name ] = true;
							unset( $claims[ $name ] );
						}
						$changed = true;
					}
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		foreach ( array_keys( $lost ) as $name ) {
			$plan['skipped'][ $name ] = 'remap_conflict';
			$plan['partial'][] = 'remap_conflict';
			unset( $fallback[ $name ] );
		}
		// Ownership of every path a promotion or retirement may touch.
		$needed = [];
		foreach ( $claims as $claim ) {
			$needed[] = $claim['target'];
		}
		foreach ( $fallback as $path ) {
			$needed[] = $path;
		}
		foreach ( $pre['candidates'] as $paths ) {
			$needed = array_merge( $needed, $paths );
		}
		foreach ( $pre['sizes'] as $old ) {
			$needed[] = $old['path'];
		}
		$needed[] = $pre['file'];
		$unknown = array_values( array_diff( array_unique( $needed ), array_keys( $plan['ownership'] ) ) );
		if ( ! empty( $unknown ) ) {
			$pass = ! empty( $operation['ownership_uncertain'] ) ? [ 'paths' => array_fill_keys( $unknown, 'uncertain' ) ] : $this->derivative_paths_ownership( $operation['attachment_id'], $unknown, $upload_dir, $this->operation_ownership_deadline( $operation ) );
			$plan['ownership'] = array_merge( $plan['ownership'], $pass['paths'] );
		}
		// Target groups: one per final target and output.
		$metadata = is_array( $operation['stored_metadata'] ) ? $operation['stored_metadata'] : [];
		$metadata['sizes'] = [];
		$groups = [];
		$kept = [];
		foreach ( $claims as $name => $claim ) {
			$ownership = isset( $plan['ownership'][ $claim['target'] ] ) ? $plan['ownership'][ $claim['target'] ] : 'uncertain';
			if ( $ownership !== 'exclusive' ) {
				$plan['skipped'][ $name ] = $ownership === 'shared' ? 'remap_skipped_shared' : 'ownership_uncertain';
				$plan['outcomes'][ $name ] = $ownership === 'shared' ? 'retained_shared' : 'retained_ownership_uncertain';
				$plan['partial'][] = $ownership === 'shared' ? 'retained_shared' : 'ownership_uncertain';
				unset( $fallback[ $name ] );
				$kept[] = $name;
				continue;
			}
			$key = $claim['target'] . '|' . $claim['output'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [ 'target' => $claim['target'], 'output' => $generation['outputs'][ $claim['output'] ], 'names' => [] ];
			}
			$groups[ $key ]['names'][] = $name;
			$output = $generation['outputs'][ $claim['output'] ];
			$metadata['sizes'][ $name ] = [ 'file' => wp_basename( $claim['target'] ), 'width' => $output['width'], 'height' => $output['height'], 'mime-type' => $output['mime'], 'filesize' => $output['size'] ];
			$plan['written'][] = $name;
			$plan['outcomes'][ $name ] = isset( $pre['sizes'][ $name ] ) && $pre['sizes'][ $name ]['path'] === $claim['target'] ? 'remapped' : 'regenerated';
			if ( ! isset( $pre['sizes'][ $name ] ) || $pre['sizes'][ $name ]['path'] !== $claim['target'] ) {
				// A new canonical file this attempt creates: a retry whose plan no
				// longer uses it retires it.
				$plan['regenerated'][ $name ] = $claim['target'];
			}
		}
		foreach ( array_keys( $lost ) as $name ) {
			$kept[] = $name;
		}
		$plan['groups'] = array_values( $groups );
		$targets = [];
		foreach ( $plan['groups'] as $group ) {
			$targets[ $group['target'] ] = true;
		}
		// Keep current output targets distinct from carried legacy aliases.
		$group_targets = $targets;
		$target_identity = function( $relative ) use ( $upload_dir ) {
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$identity = $this->operation_path_identity( $path, $upload_dir );
			return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
		};
		$group_identities = [];
		if ( ! empty( $operation['regenerate_request']['cleanup_stale'] ) ) {
			foreach ( array_keys( $group_targets ) as $target ) { $group_identities[ $target_identity( $target ) ] = true; }
		}
		// Names that were not regenerated keep the name-stable retain and retire rules.
		$retained = [];
		foreach ( $pre['sizes'] as $name => $old ) {
			if ( isset( $operation['stored_metadata']['sizes'][ $name ] ) && $this->is_marked_legacy_size( $name, $operation['stored_metadata']['sizes'][ $name ] ) ) {
				if ( ! empty( $operation['regenerate_request']['cleanup_stale'] ) && isset( $group_identities[ $target_identity( $old['path'] ) ] ) ) {
					// The surviving current group owns this path and its bytes. Drop
					// the redundant legacy entry, never its file or current entry.
					if ( ! isset( $metadata['sizes'][ $name ] ) ) { $plan['outcomes'][ $name ] = 'retained_current_target'; }
					continue;
				}
				if ( ! empty( $operation['regenerate_request']['cleanup_stale'] ) && ! isset( $targets[ $old['path'] ] ) && $this->cleanup_stale_exclusive( $operation, $old['path'], $upload_dir ) ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_stale' ];
					continue;
				}
				if ( ! empty( $operation['regenerate_request']['cleanup_stale'] ) && isset( $plan['ownership'][ $old['path'] ] ) && $plan['ownership'][ $old['path'] ] !== 'exclusive' ) {
					$state = $plan['ownership'][ $old['path'] ];
					$plan['skipped'][ $name ] = $state === 'shared' ? 'retained_shared' : 'ownership_uncertain';
					if ( $state !== 'shared' ) { $plan['partial'][] = 'ownership_uncertain'; }
				}
				if ( isset( $metadata['sizes'][ $name ] ) || isset( $group_targets[ $old['path'] ] ) || ( empty( $operation['regenerate_request']['cleanup_stale'] ) && isset( $targets[ $old['path'] ] ) ) ) {
					$plan['legacy_refusal'] = 'journal_budget_exceeded';
					continue;
				}
				$metadata['sizes'][ $name ] = $operation['stored_metadata']['sizes'][ $name ];
				$plan['outcomes'][ $name ] = isset( $plan['skipped'][ $name ] ) ? ( $plan['skipped'][ $name ] === 'retained_shared' ? 'retained_shared' : 'retained_ownership_uncertain' ) : 'retained';
				$targets[ $old['path'] ] = true;
				continue;
			}
			if ( isset( $operation['preserved_sizes'][ $name ] ) ) {
				$metadata['sizes'][ $name ] = $operation['preserved_sizes'][ $name ];
				$plan['outcomes'][ $name ] = 'retained';
				continue;
			}
			if ( isset( $metadata['sizes'][ $name ] ) ) {
				continue;
			}
			if ( in_array( $name, $kept, true ) ) {
				$retained[ $name ] = isset( $plan['outcomes'][ $name ] ) ? $plan['outcomes'][ $name ] : 'retained_conflict';
				continue;
			}
			if ( ! isset( $frozen[ $name ] ) && ! isset( $registered[ $name ] ) ) {
				// A clean unregistered path in a different physical directory cannot
				// be represented by a basename in the committed metadata. Retain its
				// original-name journal carrier instead of retiring or silently dropping it.
				$possibly_watermarked = $possibly['all'] || in_array( $name, $possibly['names'], true );
				foreach ( $pre['sizes'] as $other_name => $other ) {
					if ( $other['path'] === $old['path'] && in_array( $other_name, $possibly['names'], true ) ) { $possibly_watermarked = true; }
				}
				if ( ! empty( $operation['clean_legacy_regeneration'] ) && ( ! $possibly_watermarked || ! empty( $operation['regenerate_request']['cleanup_stale'] ) ) ) {
					$old_path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $old['path'] );
					$old_directory = realpath( dirname( $old_path ) );
					$main_directory = realpath( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $pre['file'] ) ) );
					if ( is_file( $old_path ) && $old_directory !== false && $main_directory !== false && $this->ownership_reference_key( wp_normalize_path( $old_directory ) ) !== $this->ownership_reference_key( wp_normalize_path( $main_directory ) ) ) {
						$plan['outcomes'][ $name ] = 'directory_changed';
						$plan['skipped'][ $name ] = 'directory_changed';
						$plan['partial'][] = 'directory_changed';
						continue;
					}
				}
				if ( $flagged || ! empty( $operation['regenerate_request']['cleanup_stale'] ) ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_size_gone' ];
				} else {
					$plan['outcomes'][ $name ] = 'left_unreconciled';
				}
				continue;
			}
			$watermarked = $possibly['all'] || in_array( $name, $possibly['names'], true );
			if ( ! isset( $frozen[ $name ] ) ) {
				$plan['skipped'][ $name ] = 'size_filtered';
				if ( $purpose === 'remove' && $watermarked ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_filtered' ];
				} else {
					$retained[ $name ] = 'retained_filtered';
				}
				continue;
			}
			$definition = $frozen[ $name ];
			if ( ! is_array( $source ) || ( $definition['width'] === null && $definition['height'] === null ) || ! image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ) ) {
				if ( $definition['width'] === null && $definition['height'] === null ) {
					$plan['failed'][] = $name;
					$plan['partial'][] = 'size_generation_failed';
					$retained[ $name ] = 'retained_generation_failed';
					continue;
				}
				$plan['skipped'][ $name ] = 'size_not_producible';
				if ( $watermarked ) {
					$plan['retire'][ $old['path'] ] = [ 'key' => $name, 'reason' => 'retire_not_producible' ];
				} else {
					$retained[ $name ] = 'retained_not_producible';
				}
				continue;
			}
			$plan['failed'][] = $name;
			$plan['partial'][] = 'size_generation_failed';
			$retained[ $name ] = 'retained_generation_failed';
		}
		// A new name without an output failed only when its definition could
		// produce it; core creates nothing for a size larger than its source.
		foreach ( $generation['failed'] as $name ) {
			if ( isset( $pre['sizes'][ $name ] ) || in_array( $name, $plan['failed'], true ) || ! isset( $frozen[ $name ] ) ) {
				continue;
			}
			$definition = $frozen[ $name ];
			$producible = is_array( $source ) && ( $definition['width'] !== null || $definition['height'] !== null ) && image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] );
			if ( $producible || ( $definition['width'] === null && $definition['height'] === null ) ) {
				$plan['failed'][] = $name;
				$plan['partial'][] = 'size_generation_failed';
			}
		}
		if ( ! empty( $operation['clean_legacy_regeneration'] ) ) {
			$marked = [];
			foreach ( $metadata['sizes'] as $name => $entry ) {
				if ( $this->is_marked_legacy_size( $name, $entry ) ) {
					$marked[ $name ] = $entry;
				}
			}
			$target_key = function( $relative ) use ( $upload_dir ) {
				$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
				return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
			};
			$canonical_targets = [];
			foreach ( array_keys( $targets ) as $target ) { $canonical_targets[ $target_key( $target ) ] = true; }
			foreach ( isset( $operation['preserved_sizes'] ) ? $operation['preserved_sizes'] : [] as $name => $entry ) {
				if ( isset( $pre['sizes'][ $name ] ) ) { $canonical_targets[ $target_key( $pre['sizes'][ $name ]['path'] ) ] = true; }
			}
			foreach ( $operation['clean_legacy_predictions'] as $old ) {
				$unregistered = false;
				foreach ( $pre['sizes'] as $name => $size ) {
					if ( $size['path'] === $old['path'] && ! isset( $frozen[ $name ] ) && ! isset( $registered[ $name ] ) ) { $unregistered = true; }
				}
				if ( isset( $canonical_targets[ $target_key( $old['path'] ) ] ) || ( ! $unregistered && ! in_array( $old['path'], $fallback, true ) ) ) {
					continue;
				}
				$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
				$key = $this->next_legacy_size_key( $base, $metadata['sizes'] + $operation['stored_metadata']['sizes'], $registered + $frozen );
				if ( strlen( $key ) > strlen( $base ) + 3 ) {
					$plan['legacy_refusal'] = 'journal_budget_exceeded';
					break;
				}
				$entry = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'iw_legacy' => true ];
				$metadata['sizes'][ $key ] = $entry;
				$marked[ $key ] = $entry;
				$plan['previous'][ $key ] = $old['path'];
				$plan['outcomes'][ $key ] = 'retained';
				if ( $unregistered ) {
					if ( isset( $plan['retire'][ $old['path'] ] ) && $plan['retire'][ $old['path'] ]['reason'] === 'retire_size_gone' ) { unset( $plan['retire'][ $old['path'] ] ); }
					foreach ( $pre['sizes'] as $name => $size ) {
						if ( $size['path'] === $old['path'] && ! isset( $frozen[ $name ] ) && ! isset( $registered[ $name ] ) ) { $plan['outcomes'][ $name ] = 'retained_as_legacy'; }
					}
				}
				foreach ( $fallback as $name => $path ) {
					if ( $path === $old['path'] ) {
						unset( $fallback[ $name ] );
					}
				}
			}
			if ( $this->legacy_size_preflight( $marked )['exceeded'] ) {
				$plan['legacy_refusal'] = 'legacy_limit_reached';
			}
		}
		// A basename in the committed metadata cannot represent an old file in
		// another physical directory. Keep its journal carrier, not a misleading
		// reserved entry or a retirement of the old URL.
		$main_directory = realpath( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, dirname( $pre['file'] ) ) );
		foreach ( $fallback as $name => $path ) {
			$old_path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path );
			$old_directory = realpath( dirname( $old_path ) );
			if ( ! isset( $targets[ $path ] ) && is_file( $old_path ) && $main_directory !== false && $old_directory !== false && $this->ownership_reference_key( wp_normalize_path( $main_directory ) ) !== $this->ownership_reference_key( wp_normalize_path( $old_directory ) ) ) {
				$plan['outcomes'][ $name ] = 'directory_changed';
				$plan['skipped'][ $name ] = 'directory_changed';
				$plan['partial'][] = 'directory_changed';
				continue;
			}
			$predicted_p = array_column( isset( $operation['changed_p_predictions'] ) ? $operation['changed_p_predictions'] : [], 'path' );
			if ( in_array( $path, $predicted_p, true ) ) {
				$plan['legacy_fallback'][ $path ] = true;
				continue;
			}
			if ( ! isset( $plan['retire'][ $path ] ) ) {
				$plan['retire'][ $path ] = [ 'key' => $name, 'reason' => 'retire_replaced' ];
			}
		}
		// P also covers a disappeared size and an unproducible current size:
		// neither has a current output to add it to the fallback list above.
		foreach ( isset( $operation['changed_p_predictions'] ) ? $operation['changed_p_predictions'] : [] as $old ) {
			$path = $old['path'];
			if ( isset( $canonical_targets[ $target_key( $path ) ] ) ) { continue; }
			$plan['legacy_fallback'][ $path ] = true;
			unset( $plan['retire'][ $path ] );
			foreach ( $pre['sizes'] as $name => $entry ) {
				if ( $entry['path'] === $path ) {
					unset( $retained[ $name ] );
					$plan['outcomes'][ $name ] = 'retained_as_legacy';
				}
			}
		}
		foreach ( $pre['candidates'] as $name => $paths ) {
			foreach ( $paths as $path ) {
				if ( ! isset( $plan['retire'][ $path ] ) ) {
					$plan['retire'][ $path ] = [ 'key' => $name . '#intermediate', 'reason' => 'retire_intermediate' ];
				}
			}
		}
		// A path a surviving target group is assigned is never retired.
		foreach ( array_keys( $plan['retire'] ) as $path ) {
			if ( isset( $targets[ $path ] ) ) {
				unset( $plan['retire'][ $path ] );
			}
		}
		foreach ( $retained as $name => $outcome ) {
			$old = $pre['sizes'][ $name ];
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $old['path'] );
			$plan['outcomes'][ $name ] = $outcome;
			if ( is_file( $path ) ) {
				$metadata['sizes'][ $name ] = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'filesize' => (int) filesize( $path ) ];
			}
		}
		$metadata['file'] = $pre['file'];
		if ( $pre['width'] > 0 && $pre['height'] > 0 ) {
			$metadata['width'] = $pre['width'];
			$metadata['height'] = $pre['height'];
		}
		unset( $metadata['original_image'] );
		if ( $pre['original_image'] !== '' && is_file( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . ( $prefix === '' ? '' : str_replace( '/', DIRECTORY_SEPARATOR, $prefix ) ) . $pre['original_image'] ) ) {
			$metadata['original_image'] = $pre['original_image'];
		}
		$plan['metadata'] = $metadata;

		return $plan;
	}

	/** Journal and render changed old dimensions only from the generation source stage. */
	private function stage_changed_p_outputs( &$operation, &$plan, $upload_dir ) {
		$predicted = isset( $operation['changed_p_predictions'] ) ? $operation['changed_p_predictions'] : [];
		if ( ! $predicted ) { return []; }
		$stage = $operation['journal']['current']['generation_stage'];
		$directory = $stage['directory'] === '.' ? '' : $stage['directory'] . '/';
		$source = end( $stage['sources'] );
		$source_file = $this->resolve_upload_artifact_path( $directory . $source['stage'] );
		if ( $source_file === false || ! is_file( $source_file['path'] ) || filesize( $source_file['path'] ) !== $source['size'] || hash_file( 'sha256', $source_file['path'] ) !== $source['sha256'] ) { return false; }
		$occupied = $plan['metadata']['sizes'] + ( isset( $operation['stored_metadata']['sizes'] ) && is_array( $operation['stored_metadata']['sizes'] ) ? $operation['stored_metadata']['sizes'] : [] );
		$registered = $operation['frozen_sizes'] + $operation['frozen_registered'];
		$target_key = function( $relative ) use ( $upload_dir ) {
			$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
			return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
		};
		$targets = [];
		foreach ( $plan['groups'] as $group ) { $targets[ $target_key( $group['target'] ) ] = true; }
		$legacy = [];
		foreach ( $predicted as $old ) {
			if ( isset( $targets[ $target_key( $old['path'] ) ] ) || empty( $plan['legacy_fallback'][ $old['path'] ] ) ) { continue; }
			$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
			$key = $this->next_legacy_size_key( $base, $occupied, $registered );
			if ( strlen( $key ) > strlen( $base ) + 3 ) { $operation['changed_p_error_code'] = 'journal_budget_exceeded'; return false; }
			$entry = [ 'file' => basename( $old['path'] ), 'width' => $old['width'], 'height' => $old['height'], 'mime-type' => $old['mime'], 'iw_legacy' => true ];
			$occupied[ $key ] = $entry;
			$name = $stage['prefix'] . 'legacy-' . substr( hash( 'sha256', $old['path'] ), 0, 16 ) . '.' . pathinfo( $old['path'], PATHINFO_EXTENSION );
			$legacy[] = [ 'key' => $key, 'target' => $old['path'], 'width' => $old['width'], 'height' => $old['height'], 'stage' => $name ];
			$plan['metadata']['sizes'][ $key ] = $entry;
			$plan['previous'][ $key ] = $old['path'];
			$plan['outcomes'][ $key ] = 'regenerated_at_old_dimensions';
			unset( $plan['retire'][ $old['path'] ] );
		}
		$marked = [];
		foreach ( $plan['metadata']['sizes'] as $key => $entry ) { if ( $this->is_marked_legacy_size( $key, $entry ) ) { $marked[ $key ] = $entry; } }
		if ( $this->legacy_size_preflight( $marked )['exceeded'] ) { $operation['changed_p_error_code'] = 'legacy_limit_reached'; return false; }
		$stage['legacy'] = $legacy;
		if ( ! $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) { return false; }
		$groups = [];
		foreach ( $legacy as $index => $item ) {
			$destination = $this->resolve_upload_artifact_path( $directory . $item['stage'] );
			if ( $destination === false || file_exists( $destination['path'] ) || is_link( $destination['path'] ) || ! $this->renew_operation_lock( $operation ) ) { return false; }
			$editor = wp_get_image_editor( $source_file['path'] );
			if ( is_wp_error( $editor ) ) { return false; }
			// Core rotates its editor in memory before making sub-sizes. The
			// journal-bound source file itself must remain byte-identical.
			$source_metadata = wp_read_image_metadata( $source_file['path'] );
			$orientation = is_array( $source_metadata ) && isset( $source_metadata['orientation'] ) ? (int) $source_metadata['orientation'] : 0;
			$rotated = false;
			if ( $orientation >= 2 && $orientation <= 8 ) {
				$rotated = $editor->maybe_exif_rotate();
				if ( is_wp_error( $rotated ) || ! $rotated ) { return false; }
			}
			$size = $editor->get_size();
			$source_info = @getimagesize( $source_file['path'] );
			if ( ! $rotated && $source_info && $source_info['mime'] === $plan['metadata']['sizes'][ $item['key'] ]['mime-type'] && (int) $size['width'] === $item['width'] && (int) $size['height'] === $item['height'] ) {
				$saved = $this->copy_file( $source_file['path'], $destination['path'], 'stage', $operation['attachment_id'], [] );
			} else {
				if ( ( (int) $size['width'] !== $item['width'] || (int) $size['height'] !== $item['height'] ) && is_wp_error( $editor->resize( $item['width'], $item['height'], true ) ) ) { return false; }
				$saved = $editor->save( $destination['path'], $plan['metadata']['sizes'][ $item['key'] ]['mime-type'] );
			}
			$actual = is_file( $destination['path'] ) ? @getimagesize( $destination['path'] ) : false;
			if ( $saved === false || is_wp_error( $saved ) || ( is_array( $saved ) && ( empty( $saved['path'] ) || ! $this->paths_match( wp_normalize_path( $saved['path'] ), wp_normalize_path( $destination['path'] ) ) ) ) || ! $actual || (int) $actual[0] !== $item['width'] || (int) $actual[1] !== $item['height'] || $actual['mime'] !== $plan['metadata']['sizes'][ $item['key'] ]['mime-type'] || ! $this->verify_operation_file( $destination['path'] ) ) { return false; }
			$stage['legacy'][ $index ]['size'] = filesize( $destination['path'] );
			$stage['legacy'][ $index ]['sha256'] = hash_file( 'sha256', $destination['path'] );
			if ( ! $this->set_operation_state( $operation, 'regenerating', [ 'generation_stage' => $stage ] ) ) { return false; }
			$groups[] = [ 'target' => $item['target'], 'source' => $directory . $item['stage'], 'names' => [ $item['key'] ], 'watermark' => false ];
		}
		return $groups;
	}

	/**
	 * Recognize only a surviving backup-sourced legacy promotion as clean.
	 * This grants retention recognition, never ownership or mutation authority.
	 * Other source and rotation routes deliberately remain unrecognized.
	 *
	 * @return array|false Known paths, or false if the fence/committed metadata changed.
	 */
	private function retained_clean_promotions( &$operation, $metadata, $main_path, $backup, $upload_dir ) {
		$id = $operation['attachment_id'];
		if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->read_stored_regenerated_metadata( $id, $durable ) || $durable !== $metadata ) { return false; }
		wp_cache_delete( $id, 'post_meta' );
		$attached = get_post_meta( $id, '_wp_attached_file', true );
		$main = $this->operation_path_identity( get_attached_file( $id ), $upload_dir );
		$expected = $this->operation_path_identity( $main_path, $upload_dir );
		if ( $main === false || $expected === false || $main['identity'] !== $expected['identity'] ) { return false; }
		$known = [ 'keys' => [], 'identities' => [] ];
		$prior = isset( $operation['journal']['recoverable_predecessor'] ) ? $operation['journal']['recoverable_predecessor'] : null;
		if ( ! is_array( $prior ) || ! isset( $prior['site_id'], $prior['pre_operation']['file'], $prior['generation_stage'], $prior['derivative_recovery']['previous'] ) || ! is_int( $prior['site_id'] ) || $prior['site_id'] !== (int) get_current_blog_id() ) { return $known; }
		$stage = $prior['generation_stage'];
		if ( $stage['state'] !== 'generated' || isset( $stage['source_collision'] ) || isset( $stage['pre_generation_retention'] ) || count( $stage['sources'] ) !== 1 ) { return $known; }
		$source = $stage['sources'][0];
		$backup_relative = $this->operation_root_relative_path( $backup, $this->get_backup_root_dir() );
		$prior_main = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $prior['pre_operation']['file'] ), $upload_dir );
		$attached_main = is_string( $attached ) ? $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $attached ), $upload_dir ) : false;
		if ( $prior_main === false || $attached_main === false || $prior_main['identity'] !== $main['identity'] || $attached_main['identity'] !== $main['identity'] || $source['role'] !== 'main' || $source['root'] !== 'backup' || $source['authority'] !== 'backup' || $backup_relative === false || $source['path'] !== $backup_relative || is_link( $backup ) || ! $this->verify_operation_file( $backup ) || filesize( $backup ) !== $source['size'] || hash_file( 'sha256', $backup ) !== $source['sha256'] ) { return $known; }
		$source_info = @getimagesize( $backup );
		$source_meta = wp_read_image_metadata( $backup );
		$orientation = is_array( $source_meta ) && isset( $source_meta['orientation'] ) ? (int) $source_meta['orientation'] : 0;
		if ( ! $source_info || $orientation > 1 || (int) $source_info[0] !== $prior['pre_operation']['width'] || (int) $source_info[1] !== $prior['pre_operation']['height'] || $source_info['mime'] !== $prior['pre_operation']['mime'] ) { return $known; }
		foreach ( $stage['legacy'] as $legacy ) {
			if ( ! isset( $legacy['key'], $legacy['size'], $legacy['sha256'] ) || ! is_string( $legacy['key'] ) || ! is_int( $legacy['width'] ) || $legacy['width'] < 1 || ! is_int( $legacy['height'] ) || $legacy['height'] < 1 || ! is_int( $legacy['size'] ) || $legacy['size'] < 1 || ! is_string( $legacy['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $legacy['sha256'] ) ) { continue; }
			$path = $legacy['target'];
			$key = $legacy['key'];
			if ( dirname( $path ) !== $stage['directory'] || $legacy['stage'] !== $stage['prefix'] . 'legacy-' . substr( hash( 'sha256', $path ), 0, 16 ) . '.' . pathinfo( $path, PATHINFO_EXTENSION ) || ! isset( $prior['derivative_recovery']['previous'][ $key ], $metadata['sizes'][ $key ] ) || $prior['derivative_recovery']['previous'][ $key ] !== $path ) { continue; }
			$entry = $metadata['sizes'][ $key ];
			if ( ! is_array( $entry ) || ! isset( $entry['file'], $entry['width'], $entry['height'], $entry['mime-type'] ) || $entry['file'] !== basename( $path ) || (int) $entry['width'] !== $legacy['width'] || (int) $entry['height'] !== $legacy['height'] || $entry['mime-type'] !== $source_info['mime'] ) { continue; }
			$old = array_values( array_filter( $prior['pre_operation']['sizes'], static function( $item ) use ( $path, $legacy, $entry ) { return $item['path'] === $path && $item['width'] === $legacy['width'] && $item['height'] === $legacy['height'] && $item['mime'] === $entry['mime-type']; } ) );
			$matches = array_values( array_filter( $prior['targets'], static function( $target ) use ( $path ) { return $target['target'] === $path; } ) );
			$legacies = array_values( array_filter( $stage['legacy'], static function( $item ) use ( $path, $key ) { return $item['target'] === $path || $item['key'] === $key; } ) );
			if ( count( $old ) !== 1 || count( $matches ) !== 1 || count( $legacies ) !== 1 ) { continue; }
			$target = $matches[0];
			if ( ! isset( $target['root'], $target['intent'], $target['state'], $target['aliases'], $target['size'], $target['hash'], $target['stage'] ) || $target['root'] !== 'uploads' || $target['intent'] !== 'clean-output' || $target['state'] !== 'promoted' || $target['aliases'] !== [ $key ] || $target['size'] !== $legacy['size'] || $target['hash'] !== $legacy['sha256'] ) { continue; }
			if ( $legacy['width'] > $source_info[0] || $legacy['height'] > $source_info[1] ) { continue; }
			if ( $legacy['width'] === $source_info[0] && $legacy['height'] === $source_info[1] && ( $legacy['size'] !== $source['size'] || $legacy['sha256'] !== $source['sha256'] ) ) { continue; }
			$live = $this->resolve_upload_artifact_path( $path );
			if ( $live === false || is_link( $live['path'] ) || ! $this->verify_operation_file( $live['path'] ) || filesize( $live['path'] ) !== $legacy['size'] || hash_file( 'sha256', $live['path'] ) !== $legacy['sha256'] ) { continue; }
			$info = @getimagesize( $live['path'] );
			$identity = $this->operation_path_identity( $live['path'], $upload_dir );
			if ( ! $info || (int) $info[0] !== $legacy['width'] || (int) $info[1] !== $legacy['height'] || $info['mime'] !== $entry['mime-type'] || $identity === false ) { continue; }
			$known['keys'][ $this->ownership_reference_key( $path ) ] = true;
			$known['identities'][ $identity['identity'] ] = true;
		}
		clearstatcache( true, $backup );
		if ( is_link( $backup ) || ! is_file( $backup ) || filesize( $backup ) !== $source['size'] || hash_file( 'sha256', $backup ) !== $source['sha256'] ) { $known = [ 'keys' => [], 'identities' => [] ]; }
		if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->read_stored_regenerated_metadata( $id, $durable ) || $durable !== $metadata ) { return false; }
		wp_cache_delete( $id, 'post_meta' );
		if ( get_post_meta( $id, '_wp_attached_file', true ) !== $attached ) { return false; }
		return $known;
	}

	/**
	 * Remove or reapply by token-named generation. The
	 * clean main source (`S_main`, the validated backup) and, for a
	 * watermarked scaled or rotated attachment, the provenance-proven
	 * `original_image` (`S_gen`) are copied into token-named stages; every
	 * frozen size is generated from the `S_gen` stage while metadata writes are
	 * withheld; each target group is rendered (selected, reapply) or copied
	 * (clean) into its own stage and promoted once under the fence and the
	 * ownership proof; one verified final commit follows. Core never writes a
	 * canonical derivative path, and no live file changes before the first
	 * promotion.
	 *
	 * @param array  $operation Operation holding the lease, journal begun.
	 * @param array  $data Stored attachment metadata.
	 * @param string $purpose remove or reapply.
	 * @param array  $options Effective options.
	 * @param array  $upload_dir Upload directory.
	 * @param string $backup Validated backup of the attached file.
	 * @param string $method Operation method.
	 * @param string $context Operation context.
	 * @param array  $image_metadata EXIF/IPTC of the live main (reapply).
	 * @return array
	 */
	private function execute_staged_regeneration( &$operation, $data, $purpose, $options, $upload_dir, $backup, $method, $context, $image_metadata = [] ) {
		$operation['name_stable_budget'] = true;
		// No write of the attachment's metadata, by core or by any callback, lands
		// before the final metadata filter has run; the final commit writes that
		// filter's result.
		$operation['metadata_hold'] = $this->hold_operation_metadata( $operation['attachment_id'] );
		try {
			return $this->run_staged_regeneration( $operation, $data, $purpose, $options, $upload_dir, $backup, $method, $context, $image_metadata );
		} finally {
			$this->release_operation_metadata_withhold( $operation );
		}
	}

	/** @see execute_staged_regeneration() */
	private function run_staged_regeneration( &$operation, $data, $purpose, $options, $upload_dir, $backup, $method, $context, $image_metadata = [] ) {
		$attachment_id = $operation['attachment_id'];
		$token = $operation['token'];
		$reapply = $purpose === 'reapply';
		$operation['regeneration_backup_required'] = isset( $operation['journal']['current']['main_recovery'] ) && ! empty( $options['backup']['backup_image'] );
		// A retry's protective flag must not change the source and size plan
		// already bound by its unwatermarked preflight.
		$flagged = ! empty( $operation['unwatermarked_retry_guard'] ) ? false : (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
		$main_path = get_attached_file( $attachment_id );
		$operation['stored_metadata'] = $data;
		if ( ! function_exists( '_wp_make_subsizes' ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'regenerate_unsupported', __( 'This WordPress version cannot regenerate image sizes safely. No image was changed.', 'image-watermark' ) );
		}
		// The size set is frozen once, before admission, so the reservation
		// counts exactly the names the operation can journal.
		$early = isset( $operation['early_regeneration_plan'] ) ? $operation['early_regeneration_plan'] : null;
		$snapshot = $early !== null ? $early['snapshot'] : $this->snapshot_pre_operation( $attachment_id, $data, $main_path, $upload_dir );
		if ( $snapshot === false ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'invalid_metadata', __( 'Invalid attachment metadata.', 'image-watermark' ) );
		}
		$frozen = $early !== null ? $early['frozen'] : $this->freeze_effective_sizes( $attachment_id, $data, $snapshot );
		if ( count( $frozen ) > 200 ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
		}
		$request = isset( $operation['regenerate_request'] ) ? $operation['regenerate_request'] : null;
		if ( $request !== null ) {
			if ( $request['mode'] === 'subset' && ( empty( $request['sizes'] ) || array_diff( $request['sizes'], array_map( 'strval', array_keys( $frozen ) ) ) ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'invalid_sizes', __( 'Choose registered image sizes for regeneration.', 'image-watermark' ) );
			}
			$operation['preserved_sizes'] = $early !== null ? $early['preserved'] : $this->regeneration_preserved_sizes( $data, $frozen, $request );
		}
		$operation['frozen_sizes'] = $frozen;
		if ( $purpose === 'remove' || $purpose === 'reapply' || $request !== null ) {
			$operation['clean_legacy_regeneration'] = true;
			$operation['changed_p_operation'] = true;
			$operation['clean_legacy_options'] = isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [];
			$operation['clean_legacy_flagged'] = $flagged;
			$operation['clean_legacy_source'] = $backup;
		}
		// Whether a size is registered is decided once with the frozen set; a
		// callback that changes registrations later changes neither.
		$operation['frozen_registered'] = $early !== null ? $early['registered'] : array_fill_keys( array_map( 'strval', array_keys( wp_get_registered_image_subsizes() ) ), true );
		$inherited_pre = isset( $operation['journal']['current']['pre_operation'] ) ? $operation['journal']['current']['pre_operation'] : null;
		$operation['inherited_suspect'] = [ 'keys' => [], 'identities' => [] ];
		$inherited_paths = [];
		if ( $flagged && $this->is_valid_pre_operation( $inherited_pre ) && ! empty( $inherited_pre['inherited'] ) ) {
			$inherited_paths = array_column( $inherited_pre['sizes'], 'path' );
			foreach ( $inherited_pre['candidates'] as $paths ) {
				$inherited_paths = array_merge( $inherited_paths, $paths );
			}
			foreach ( array_unique( $inherited_paths ) as $path ) {
				$operation['inherited_suspect']['keys'][ $this->ownership_reference_key( $path ) ] = true;
				$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path ), $upload_dir );
				if ( $identity !== false ) { $operation['inherited_suspect']['identities'][ $identity['identity'] ] = true; }
			}
		}
		$prepared = $this->prepare_name_stable_operation( $operation, $data, $main_path, $upload_dir, $backup );
		if ( $prepared !== true ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $prepared['code'], $prepared['message'], $prepared['code'] !== 'journal_budget_exceeded' );
		}
		$pre = $operation['pre_operation'];
		if ( $this->predicted_generation_group_overflow( $operation, $purpose, $options, $flagged, $request, $backup, $upload_dir ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
		}
		if ( $reapply && $method === 'manual' ) {
			// Before any stage exists: every name the regenerated layout could
			// select or keep, at the longest path it could have.
			$selected = array_merge( [ 'full' ], array_keys( array_filter( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [], static function( $active ) {
				return (int) $active === 1;
			} ) ) );
			$paths = [ str_repeat( 'p', strlen( $pre['file'] ) + 16 ) ];
			foreach ( $pre['sizes'] as $size ) {
				$paths[] = $size['path'];
			}
			$legacy_paths = array_merge( isset( $operation['legacy_staged_paths'] ) ? $operation['legacy_staged_paths'] : [], array_column( isset( $operation['clean_legacy_predictions'] ) ? $operation['clean_legacy_predictions'] : [], 'path' ) );
			if ( $this->manifest_preflight_exceeded( $attachment_id, $selected, array_merge( $selected, array_keys( wp_get_registered_image_subsizes() ), array_keys( $pre['sizes'] ) ), $paths, $upload_dir, $legacy_paths ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'manifest_budget_exceeded', __( 'This image has too many selected sizes to process safely. No image was changed.', 'image-watermark' ) );
			}
		}
		// Reserved-entry accounting follows the manifest bound, but still precedes
		// every file created by this attempt.
		$reserved_names = array_merge( array_keys( $operation['clean_legacy_predictions'] ), array_keys( $operation['changed_p_predictions'] ) );
		$reserved = $this->estimate_name_stable_journal_bytes( $operation, $pre );
		if ( $this->operation_fault_injector ) {
			call_user_func( $this->operation_fault_injector, 'journal_reservation', [ 'attachment_id' => $attachment_id, 'token' => $token, 'bytes' => $reserved ] );
		}
		if ( ! empty( $operation['legacy_key_overflow'] ) || $this->retry_size_key_limit_exceeded( $pre, $frozen, $reserved_names ) || $reserved > self::NAME_STABILITY_JOURNAL_BUDGET ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
		}
		$manifest_source = isset( $operation['journal']['current']['main_recovery'] );
		$inspection = null;
		if ( $manifest_source ) {
			if ( ! $this->prepare_main_recovery_copy( $operation, $backup, $options ) ) {
				$code = isset( $operation['recovery_error_code'] ) ? $operation['recovery_error_code'] : 'recovery_source_invalid';
				return $this->operation_terminal_failure( $operation, $data, $method, $context, $code === 'recovery_interrupted' ? 'interrupted' : 'failed', $code, $code === 'ownership_uncertain' ? __( 'The main image is shared or its ownership could not be verified. Regeneration was stopped.', 'image-watermark' ) : __( 'The clean recovery source could not be safely prepared. Retry the operation to finish recovery.', 'image-watermark' ), true );
			}
			if ( $operation['regeneration_backup_required'] ) {
				// The live main may still be a previous attempt's watermarked output.
				// Keep the backup's normal key, but copy only our verified clean source.
				$recovery = $operation['journal']['current']['main_recovery'];
				if ( ! $this->create_staged_backup( $operation, $data, $upload_dir, $options, $recovery['path'] ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', isset( $operation['backup_error_code'] ) ? $operation['backup_error_code'] : 'backup_failed', isset( $operation['backup_error'] ) ? $operation['backup_error'] : __( 'The requested clean backup could not be created.', 'image-watermark' ), true );
				}
				$created = $this->describe_backup_path( $data['file'] );
				if ( $created['path'] === false || filesize( $created['path'] ) !== $recovery['size'] || hash_file( 'sha256', $created['path'] ) !== $recovery['sha256'] ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'backup_identity_mismatch', __( 'The clean backup could not be verified. No image was changed.', 'image-watermark' ), true );
				}
			}
			$inspection = $this->inspect_main_recovery( $operation );
			if ( ! is_array( $inspection ) || isset( $inspection['code'] ) ) {
				$code = is_array( $inspection ) && isset( $inspection['code'] ) ? $inspection['code'] : 'recovery_source_invalid';
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, $code === 'ownership_uncertain' ? __( 'The main image is shared or its ownership could not be verified. Regeneration was stopped.', 'image-watermark' ) : __( 'The clean recovery source could not be safely prepared. Retry the operation to finish recovery.', 'image-watermark' ), true );
			}
			$backup = $inspection['source'];
		}
		$prefix = '.iw-stage-' . $token . '-';
		$live_source = $request !== null && ! $flagged;
		$uploads_source = $live_source || $manifest_source;
		$backup_relative = $uploads_source ? $this->operation_relative_path( $backup, $upload_dir ) : $this->operation_backup_relative_path( $backup );
		$backup_size = is_file( $backup ) ? filesize( $backup ) : 0;
		if ( $backup_relative === false || ! is_int( $backup_size ) || $backup_size < 1 ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $live_source ? 'file_missing' : 'backup_missing', $live_source ? __( 'The clean image source could not be read. No image was changed.', 'image-watermark' ) : __( 'No watermark backup found for this image.', 'image-watermark' ) );
		}
		// Short, role-distinct stems leave room for core's dimension suffix while
		// retaining each source's extension for editor and format selection.
		$main_extension = pathinfo( $pre['file'], PATHINFO_EXTENSION );
		$sources = [ [ 'role' => 'main', 'root' => $uploads_source ? 'uploads' : 'backup', 'path' => $backup_relative, 'size' => $backup_size, 'sha256' => hash_file( 'sha256', $backup ), 'stage' => $prefix . 'm' . ( $main_extension !== '' ? '.' . $main_extension : '' ), 'authority' => $manifest_source ? 'manifest' : ( $live_source ? 'live' : 'backup' ) ] ];
		$original = $flagged ? $this->proven_original_source( $attachment_id, $pre ) : $this->unwatermarked_original_source( $attachment_id, $pre );
		if ( $original !== null ) {
			$original_extension = pathinfo( $original['path'], PATHINFO_EXTENSION );
			$sources[] = [ 'role' => 'generation', 'root' => 'uploads', 'path' => $original['path'], 'size' => $original['size'], 'sha256' => $original['sha256'], 'stage' => $prefix . 'g' . ( $original_extension !== '' ? '.' . $original_extension : '' ) ];
		}
		if ( ! $this->begin_generation_stage( $operation, $sources, $frozen, $pre ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_write_failed', __( 'Watermark recovery state could not be updated.', 'image-watermark' ), true );
		}
		if ( ! $this->copy_generation_sources( $operation, $options ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'restore_failed', $live_source ? __( 'The clean image source could not be safely prepared. No image was changed.', 'image-watermark' ) : __( 'The clean backup could not be safely prepared. No image was changed.', 'image-watermark' ), true );
		}
		$generation = $this->generate_from_stage( $operation, $data );
		if ( $generation === false ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'metadata_regeneration_failed', __( 'Image sizes could not be regenerated from the clean source. No image was changed.', 'image-watermark' ), true );
		}
		if ( $early !== null && is_array( $early['source_dimensions'] ) ) {
			$inconsistent = [];
			$details = [];
			foreach ( $generation['outputs'] as $output ) {
				foreach ( $output['names'] as $name ) {
					if ( empty( $options['watermark_on'][ $name ] ) || (int) $options['watermark_on'][ $name ] !== 1 || ! isset( $early['pre']['sizes'][ $name ], $frozen[ $name ] ) ) {
						continue;
					}
					$definition = $frozen[ $name ];
					$source = $early['source_dimensions'];
					$expected = ( $definition['width'] !== null || $definition['height'] !== null ) ? image_resize_dimensions( $source[0], $source[1], (int) $definition['width'], (int) $definition['height'], $definition['crop'] ) : false;
					$width = $expected ? (int) $expected[4] : 0;
					$height = $expected ? (int) $expected[5] : 0;
					$old = $early['pre']['sizes'][ $name ];
					// Compare the predicted and actual geometry, rather than letting the
					// source-derived first branch mask an unexpected actual output.
					if ( $this->name_stable_definition_unchanged( $definition, $old, $width, $height, null ) !== $this->name_stable_definition_unchanged( $definition, $old, $output['width'], $output['height'], null ) ) {
						$inconsistent[] = $name;
						$details[] = $name . ' (' . $width . ' x ' . $height . ' -> ' . $output['width'] . ' x ' . $output['height'] . ')';
					}
				}
			}
			if ( $inconsistent ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'size_generation_failed', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ) . ' ' . implode( ', ', $details ), true, [], $inconsistent );
			}
		}
		$plan = $this->plan_generation_targets( $operation, $generation, $upload_dir, $purpose, $options, $flagged );
		if ( isset( $plan['legacy_refusal'] ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $plan['legacy_refusal'], __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
		}
		// A predicted current output can fail or lose its claim. Reclassify
		// against the actual groups before any live promotion, and reserve the
		// newly discovered P paths before creating their stages.
		$actual_fallback_paths = [];
		foreach ( $plan['retire'] as $path => $retire ) {
			if ( $retire['reason'] === 'retire_replaced' ) { $actual_fallback_paths[] = $path; }
		}
		$clean = $this->predict_clean_legacy_entries( $operation, $pre, $data, $upload_dir, $actual_fallback_paths );
		$predicted_c_paths = array_column( $operation['clean_legacy_predictions'], 'path' );
		$additional_c = array_filter( $clean['new'], static function( $old ) use ( $predicted_c_paths, $plan ) {
			return ! in_array( $old['path'], $predicted_c_paths, true ) && isset( $plan['retire'][ $old['path'] ] ) && $plan['retire'][ $old['path'] ]['reason'] === 'retire_replaced';
		} );
		if ( $additional_c ) {
			$occupied = ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] ) + $operation['clean_legacy_predictions'] + $operation['changed_p_predictions'];
			$resolved_c = [];
			foreach ( $additional_c as $old ) {
				$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
				$key = $this->next_legacy_size_key( $base, $occupied, $operation['frozen_sizes'] + $operation['frozen_registered'] );
				$resolved_c[ $key ] = $old;
				$occupied[ $key ] = $old;
			}
			$additional_c = $resolved_c;
			// Count only actual fallbacks in addition to the admitted entries;
			// a speculative later prediction is not itself an overflow.
			$marked_bound = $this->legacy_prediction_bound_entries( $data, [ $operation['clean_legacy_predictions'], $operation['changed_p_predictions'], $additional_c ] );
			if ( $this->legacy_size_preflight( $marked_bound )['exceeded'] ) {
				return $this->discovered_legacy_overflow( $operation, $data, $method, $context, $plan, array_column( $additional_c, 'path' ) );
			}
			$operation['clean_legacy_predictions'] += $additional_c;
			$names = array_merge( array_keys( $operation['clean_legacy_predictions'] ), array_keys( $operation['changed_p_predictions'] ) );
			if ( $reapply && $method === 'manual' ) {
				$selected_for_manifest = array_merge( [ 'full' ], array_keys( array_filter( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [], static function( $active ) { return (int) $active === 1; } ) ) );
				$manifest_paths = [ str_repeat( 'p', strlen( $pre['file'] ) + 16 ) ];
				foreach ( $pre['sizes'] as $size ) { $manifest_paths[] = $size['path']; }
				$legacy_paths = array_merge( $operation['legacy_staged_paths'], array_column( $operation['clean_legacy_predictions'], 'path' ) );
				if ( $this->manifest_preflight_exceeded( $attachment_id, $selected_for_manifest, array_merge( $selected_for_manifest, array_keys( wp_get_registered_image_subsizes() ), array_keys( $pre['sizes'] ) ), $manifest_paths, $upload_dir, $legacy_paths ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'manifest_budget_exceeded', __( 'This image has too many selected sizes to process safely. No image was changed.', 'image-watermark' ) );
				}
			}
			$key_overflow = false;
			foreach ( $additional_c as $key => $old ) {
				$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
				$key_overflow = $key_overflow || strlen( $key ) > strlen( $base ) + 3;
			}
			if ( $key_overflow || $this->retry_size_key_limit_exceeded( $pre, $frozen, $names ) || $this->estimate_name_stable_journal_bytes( $operation, $pre ) > self::NAME_STABILITY_JOURNAL_BUDGET ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
			}
			$plan = $this->plan_generation_targets( $operation, $generation, $upload_dir, $purpose, $options, $flagged );
			if ( isset( $plan['legacy_refusal'] ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $plan['legacy_refusal'], __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
			}
		}
		$actual_p = $this->predict_changed_watermarked_legacy( $operation, $pre, $data, $upload_dir, $clean, array_column( $plan['groups'], 'target' ) );
		$existing_p = array_column( $operation['changed_p_predictions'], 'path' );
		$additional_p = array_filter( $actual_p['new'], static function( $old ) use ( $existing_p ) { return ! in_array( $old['path'], $existing_p, true ); } );
		if ( $additional_p ) {
			$marked_bound = $this->legacy_prediction_bound_entries( $data, [ $operation['clean_legacy_predictions'], $operation['changed_p_predictions'], $additional_p ] );
			if ( $this->legacy_size_preflight( $marked_bound )['exceeded'] ) {
				return $this->discovered_legacy_overflow( $operation, $data, $method, $context, $plan, array_column( $additional_p, 'path' ) );
			}
			$occupied = ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] ) + $clean['new'] + $operation['changed_p_predictions'];
			foreach ( $additional_p as $old ) {
				$base = $this->legacy_size_key( $old['width'], $old['height'], $old['path'] );
				$key = $this->next_legacy_size_key( $base, $occupied, $operation['frozen_sizes'] + $operation['frozen_registered'] );
				$actual_p['key_overflow'] = $actual_p['key_overflow'] || strlen( $key ) > strlen( $base ) + 3;
				$operation['changed_p_predictions'][ $key ] = $old;
				$occupied[ $key ] = $old;
			}
			$operation['legacy_staged_paths'] = array_column( $operation['changed_p_predictions'], 'path' );
			$code = $actual_p['key_overflow'] ? 'journal_budget_exceeded' : '';
			if ( $code === '' && $this->estimate_name_stable_journal_bytes( $operation, $pre ) > self::NAME_STABILITY_JOURNAL_BUDGET ) { $code = 'journal_budget_exceeded'; }
			if ( $code === '' && $reapply && $method === 'manual' ) {
				$selected_for_manifest = array_merge( [ 'full' ], array_keys( array_filter( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [], static function( $active ) { return (int) $active === 1; } ) ) );
				$manifest_paths = [ str_repeat( 'p', strlen( $pre['file'] ) + 16 ) ];
				foreach ( $pre['sizes'] as $size ) { $manifest_paths[] = $size['path']; }
				$legacy_paths = array_merge( $operation['legacy_staged_paths'], array_column( $operation['clean_legacy_predictions'], 'path' ) );
				if ( $this->manifest_preflight_exceeded( $attachment_id, $selected_for_manifest, array_merge( $selected_for_manifest, array_keys( wp_get_registered_image_subsizes() ), array_keys( $pre['sizes'] ) ), $manifest_paths, $upload_dir, $legacy_paths ) ) { $code = 'manifest_budget_exceeded'; }
			}
			if ( $code !== '' ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
			}
			$plan = $this->plan_generation_targets( $operation, $generation, $upload_dir, $purpose, $options, $flagged );
			if ( isset( $plan['legacy_refusal'] ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $plan['legacy_refusal'], __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
			}
		}
		$p_groups = $this->stage_changed_p_outputs( $operation, $plan, $upload_dir );
		if ( $p_groups === false ) {
			$code = isset( $operation['changed_p_error_code'] ) ? $operation['changed_p_error_code'] : 'size_generation_failed';
			if ( $code === 'legacy_limit_reached' && $additional_p ) {
				$extra_files = array_map( 'basename', array_column( $additional_p, 'path' ) );
				$predicted_marked = [];
				foreach ( $plan['metadata']['sizes'] as $name => $entry ) {
					if ( $this->is_marked_legacy_size( $name, $entry ) && ! in_array( $entry['file'], $extra_files, true ) ) { $predicted_marked[ $name ] = $entry; }
				}
				if ( ! $this->legacy_size_preflight( $predicted_marked )['exceeded'] ) {
					return $this->discovered_legacy_overflow( $operation, $data, $method, $context, $plan, array_column( $additional_p, 'path' ) );
				}
			}
			$message = $code === 'size_generation_failed' ? __( 'Image sizes could not be regenerated from the clean source. No image was changed.', 'image-watermark' ) : __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' );
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, $message, $code === 'size_generation_failed' );
		}
		$selected = [];
		foreach ( isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [] as $size => $active ) {
			if ( (int) $active === 1 ) {
				$selected[] = (string) $size;
			}
		}
		$shared_aliases = $reapply ? $this->regeneration_shared_aliases( $plan['groups'], $plan['metadata'], $selected ) : [];
		if ( $request !== null && $request['strict_aliases'] && $shared_aliases ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'shared_alias_conflict', __( 'A selected watermark size shares a file with a size that must stay clean.', 'image-watermark' ) );
		}
		$stage = $operation['journal']['current']['generation_stage'];
		$stage_directory = $stage['directory'] === '.' ? '' : $stage['directory'] . '/';
		$main_stage = $stage_directory . $stage['sources'][0]['stage'];
		$main_relative = $pre['file'];
		// The main file's group: a watermarked render of S_main when a reapply
		// selects full, otherwise S_main's bytes, promoted only when they differ.
		$groups = $p_groups;
		$main_kept = false;
		$watermark_full = $reapply && in_array( 'full', $selected, true );
		$live_main = $this->resolve_upload_artifact_path( $main_relative );
		$live_hash = $live_main !== false && is_file( $live_main['path'] ) ? hash_file( 'sha256', $live_main['path'] ) : '';
		$restore_required = is_array( $inspection ) && ! empty( $inspection['restore'] );
		if ( ! $live_source && ( $watermark_full || ( $live_hash !== $stage['sources'][0]['sha256'] && ! $restore_required ) ) ) {
			// The main file is an existing target like any other: it is replaced
			// only with the exclusive proof, and a shared or uncertain main keeps
			// its bytes and makes the outcome partial on its own account.
			$main_ownership = isset( $plan['ownership'][ $main_relative ] ) ? $plan['ownership'][ $main_relative ] : 'uncertain';
			if ( $main_ownership === 'exclusive' ) {
				$groups[] = [ 'target' => $main_relative, 'source' => $main_stage, 'names' => [ 'full' ], 'watermark' => $watermark_full ];
			} else {
				$main_kept = true;
				$plan['skipped']['full'] = $main_ownership === 'shared' ? 'remap_skipped_shared' : 'ownership_uncertain';
				$plan['outcomes']['full'] = $main_ownership === 'shared' ? 'retained_shared' : 'retained_ownership_uncertain';
				$plan['partial'][] = $main_ownership === 'shared' ? 'retained_shared' : 'ownership_uncertain';
			}
		}
		foreach ( $plan['groups'] as $group ) {
			$groups[] = [ 'target' => $group['target'], 'source' => $stage_directory . $group['output']['stage'], 'names' => $group['names'], 'watermark' => $reapply && count( array_intersect( $group['names'], $selected ) ) > 0 ];
		}
		if ( $request !== null && $reapply && ! $p_groups && ! array_filter( $groups, static function( $group ) { return $group['watermark']; } ) ) {
			$partial = $this->name_stable_partial_code( $plan );
			if ( $partial !== '' ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', $partial, $this->name_stable_partial_message( $partial, $purpose ), true, [], $plan['failed'], $this->name_stable_skipped_sizes( $plan ) );
			}
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'no_target_sizes', __( 'No selected image sizes are available for this attachment.', 'image-watermark' ) );
		}
		if ( count( $groups ) > 50 ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
		}
		// Actual marked keys can differ from the admission prediction after
		// generation and collision resolution. The next retry must be readable.
		$actual_marked = [];
		foreach ( $plan['metadata']['sizes'] as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) ) { $actual_marked[] = $name; }
		}
		if ( $this->retry_size_key_limit_exceeded( $pre, $frozen, $actual_marked, 'exact' ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' ) );
		}
		$targets = [];
		foreach ( $groups as $index => $group ) {
			// Targets are journaled at their canonical location, beside their stage,
			// however the stored metadata spells the directory.
			$target_record = $this->resolve_upload_artifact_path( $group['target'] );
			$canonical = $target_record === false ? false : $this->operation_destination_relative_path( $target_record['path'], $upload_dir );
			$stage_path = $canonical === false ? false : $this->operation_stage_path( $target_record['path'], $token, 'promote-' . $canonical );
			$groups[ $index ]['target'] = $canonical;
			$groups[ $index ]['path'] = $target_record === false ? false : $target_record['path'];
			$groups[ $index ]['stage'] = $stage_path === false ? false : $this->operation_planned_stage_relative_path( $stage_path, $upload_dir );
			if ( $groups[ $index ]['stage'] === false ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'invalid_targets', __( 'Selected image paths could not be safely prepared.', 'image-watermark' ) );
			}
			$targets[] = [ 'target' => $canonical, 'root' => 'uploads', 'aliases' => $group['names'], 'intent' => $group['watermark'] ? 'watermark-output' : 'clean-output', 'state' => 'pending' ];
		}
		// Generation wrote only token-owned stages. Refusals above therefore
		// leave the live main unchanged, including an interrupted predecessor's
		// watermark. Restore only after judging the actual target groups.
		if ( $manifest_source ) {
			if ( ! $this->restore_main_recovery( $operation, $options, $inspection ) ) {
				$code = isset( $operation['recovery_error_code'] ) ? $operation['recovery_error_code'] : 'recovery_source_invalid';
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, $code === 'ownership_uncertain' ? __( 'The main image is shared or its ownership could not be verified. Regeneration was stopped.', 'image-watermark' ) : __( 'The clean recovery source could not be safely prepared. Retry the operation to finish recovery.', 'image-watermark' ), true );
			}
			if ( is_array( $operation['main_recovery_predecessor'] ) ) {
				$cleaned = $this->cleanup_operation_record_artifacts( $operation, $operation['main_recovery_predecessor'], true );
				if ( ! $this->cleanup_main_recovery( $operation, $operation['main_recovery_predecessor'] ) ) {
					$cleaned = false;
				}
				if ( ! $cleaned ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'interrupted', 'recovery_interrupted', __( 'The clean recovery source is safe, but predecessor cleanup needs another retry.', 'image-watermark' ), true );
				}
			}
		}
		// The new canonical paths are journaled before any promotion, so a retry
		// whose plan no longer uses one of them retires it.
		$recovery = [ 'previous' => $plan['previous'], 'regenerated' => $plan['regenerated'], 'outcomes' => [], 'retired' => [], 'retiring' => [], 'remap' => [] ];
		if ( ! $this->set_operation_state( $operation, 'rendering', [ 'targets' => $targets, 'derivative_recovery' => $recovery ] ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_write_failed', __( 'Watermark recovery state could not be updated.', 'image-watermark' ), true );
		}
		$failed = [];
		$staged_groups = [];
		$failure_code = 'write_failed';
		$failure_message = $reapply ? __( 'Watermark could not be applied to every selected image size.', 'image-watermark' ) : ( $live_source ? __( 'Image sizes could not be safely regenerated.', 'image-watermark' ) : __( 'Failed to restore from backup.', 'image-watermark' ) );
		foreach ( $groups as $group ) {
			$source = $this->resolve_upload_artifact_path( $group['source'] );
			$stage_record = $this->resolve_upload_artifact_path( $group['stage'] );
			$target = [ 'target' => $group['target'], 'root' => 'uploads', 'stage' => $group['stage'], 'aliases' => $group['names'], 'intent' => $group['watermark'] ? 'watermark-output' : 'clean-output', 'state' => 'allocated' ];
			if ( $source === false || $stage_record === false || ! $this->set_operation_target( $operation, $target ) ) {
				$failed = array_merge( $failed, $group['names'] );
				break;
			}
			if ( $group['watermark'] ) {
				foreach ( $group['names'] as $alias ) {
					do_action( 'iw_before_apply_watermark', $attachment_id, $alias );
				}
			}
			if ( ! $this->renew_operation_lock( $operation ) ) {
				$failed = array_merge( $failed, $group['names'] );
				break;
			}
			$stage_failed = $this->should_inject_operation_fault( 'during_staging', [ 'attachment_id' => $attachment_id, 'token' => $token, 'target' => $group['target'] ] ) || ! $this->copy_file( $source['path'], $stage_record['path'], 'stage', $attachment_id, $options ) || $this->should_inject_operation_fault( 'after_stage_copy', [ 'attachment_id' => $attachment_id, 'token' => $token, 'target' => $group['target'] ] );
			if ( ! $this->renew_operation_lock( $operation ) ) {
				$failed = array_merge( $failed, $group['names'] );
				break;
			}
			if ( ! $stage_failed && $group['watermark'] && ! $this->do_watermark( $attachment_id, $stage_record['path'], $group['names'][0], $upload_dir, $image_metadata, $options ) ) {
				$stage_failed = true;
				if ( ! empty( $this->last_renderer_failure['code'] ) ) {
					$failure_code = $this->last_renderer_failure['code'];
					$failure_message = $this->last_renderer_failure['message'];
				}
			}
			if ( ! $stage_failed && $group['watermark'] ) {
				try {
					$metadata_result = $this->save_image_metadata( $image_metadata, $stage_record['path'] );
				} catch ( Exception $e ) {
					$metadata_result = [ 'success' => false, 'error' => $e->getMessage() ];
				}
				if ( empty( $metadata_result['success'] ) ) {
					$stage_failed = true;
					$failure_code = 'metadata_write_failed';
					/* translators: 1: Image metadata write error. */
					$failure_message = sprintf( __( 'Watermark applied, but image metadata could not be preserved: %s', 'image-watermark' ), ! empty( $metadata_result['error'] ) ? $metadata_result['error'] : __( 'The staged image could not be verified.', 'image-watermark' ) );
				}
			}
			if ( $stage_failed || ! $this->verify_operation_file( $stage_record['path'] ) ) {
				// The allocated target is already journaled. Only fenced cleanup
				// may consider it; a token name alone cannot identify the file.
				$failed = array_merge( $failed, $group['names'] );
				continue;
			}
			$target['size'] = filesize( $stage_record['path'] );
			$target['hash'] = hash_file( 'sha256', $stage_record['path'] );
			$target['state'] = 'staged';
			if ( ! $this->set_operation_target( $operation, $target ) ) {
				$failed = array_merge( $failed, $group['names'] );
				break;
			}
			$staged_groups[] = [ 'group' => [ 'path' => $group['path'], 'target' => $group['target'], 'aliases' => $group['names'] ], 'stage' => $stage_record['path'], 'target' => $target, 'watermark' => $group['watermark'] ];
		}
		if ( ! empty( $failed ) ) {
			// Outputs are still staged, though recovery may already have restored the main.
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $failure_code, $failure_message, true, [], $failed );
		}
		// The exact check: the whole bound again, with this record as it now
		// stands plus what the rest of the attempt can still write.
		$record_bound = $this->staged_record_bytes( $operation, $plan, $staged_groups, $reapply && $method === 'manual' ? $options : null, $upload_dir );
		$reserved = $this->estimate_name_stable_journal_bytes( $operation, $pre, $record_bound['bytes'] );
		if ( $this->operation_fault_injector ) {
			// Test seam: reports the exact bound and its record part; an integer
			// return replaces the bound, so a test can reach the refusal without
			// an oversized record.
			$injected = call_user_func( $this->operation_fault_injector, 'journal_reservation', [ 'attachment_id' => $attachment_id, 'token' => $token, 'bytes' => $reserved, 'check' => 'exact', 'record_bytes' => $record_bound['bytes'], 'evidence_bytes' => $record_bound['evidence'] ] );
			$reserved = is_int( $injected ) ? $injected : $reserved;
		}
		if ( $reserved > self::NAME_STABILITY_JOURNAL_BUDGET ) {
			$message = $this->operation_record_mutated_derivatives( $operation['journal']['current'] )
				? __( 'This image has too many sizes for its recovery record to be kept safely.', 'image-watermark' )
				: __( 'This image has too many sizes for its recovery record to be kept safely. No image was changed.', 'image-watermark' );
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'journal_budget_exceeded', $message );
		}
		if ( ! $this->set_operation_state( $operation, 'promoting' ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'interrupted', 'journal_write_failed', __( 'All outputs were staged, but promotion could not be safely started.', 'image-watermark' ), true );
		}
		$processed = [];
		foreach ( $staged_groups as $staged ) {
			if ( ! $this->promote_stage_file( $operation, $staged['stage'], $staged['group']['path'], $staged['target'] ) ) {
				$failed = array_merge( $failed, $staged['group']['aliases'] );
				break;
			}
			if ( $staged['watermark'] ) {
				$processed = array_merge( $processed, $staged['group']['aliases'] );
				foreach ( $staged['group']['aliases'] as $alias ) {
					do_action( 'iw_after_apply_watermark', $attachment_id, $alias );
				}
			}
		}
		if ( ! empty( $failed ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'interrupted', 'promotion_failed', __( 'Some regenerated image files could not be promoted. Retry the operation to finish it.', 'image-watermark' ), true, $processed, $failed );
		}
		// Third-party metadata filters see the canonical, promoted files.
		$final = apply_filters( 'wp_generate_attachment_metadata', $plan['metadata'], $attachment_id, 'update' );
		$this->release_operation_metadata_withhold( $operation );
		if ( ! $this->renew_operation_lock( $operation ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'interrupted', 'journal_write_failed', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true, $processed );
		}
		if ( $this->name_stable_identity_changed( $attachment_id, $final, $pre ) ) {
			// Restoring the main is a replacement like any promotion: it needs the
			// same exclusive proof the plan used.
			$main_exclusive = isset( $plan['ownership'][ $main_relative ] ) && $plan['ownership'][ $main_relative ] === 'exclusive';
			$clean_main = $live_source ? $this->resolve_upload_artifact_path( $main_stage ) : null;
			$rollback_source = $live_source ? ( $clean_main === false ? '' : $clean_main['path'] ) : $backup;
			$rollback = $this->rollback_name_stable_identity( $operation, $final, $upload_dir, $rollback_source, $options, $main_exclusive );
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', $rollback['committed'] || ! empty( $rollback['kept'] ) ? 'main_identity_changed' : 'metadata_commit_failed', $this->name_stable_partial_message( 'main_identity_changed', $purpose ), true, $processed );
		}
		// A third-party metadata filter must not make a promoted clean P output
		// disappear from the reference map or from the manifest's legacy evidence.
		foreach ( $p_groups as $group ) {
			$key = $group['names'][0];
			$expected = $plan['metadata']['sizes'][ $key ];
			$actual = isset( $final['sizes'][ $key ] ) ? $final['sizes'][ $key ] : null;
			if ( ! $this->is_marked_legacy_size( $key, $actual ) || ! isset( $actual['file'], $actual['width'], $actual['height'], $actual['mime-type'] ) || $actual['file'] !== $expected['file'] || $actual['width'] !== $expected['width'] || $actual['height'] !== $expected['height'] || $actual['mime-type'] !== $expected['mime-type'] ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_failed', __( 'Regenerated files were promoted, but attachment metadata could not be committed.', 'image-watermark' ), true, $processed );
			}
		}
		// A metadata callback can enlarge or add marked entries after admission.
		// Never trim its data to fit, or commit an oversized marked map. Leave the
		// old addresses in the original-name journal carrier for a later retry.
		$final_marked = [];
		foreach ( isset( $final['sizes'] ) && is_array( $final['sizes'] ) ? $final['sizes'] : [] as $name => $entry ) {
			if ( $this->is_marked_legacy_size( $name, $entry ) ) { $final_marked[ $name ] = $entry; }
		}
		if ( $this->legacy_size_preflight( $final_marked )['exceeded'] ) {
			$path_key = function( $relative ) use ( $upload_dir ) {
				$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ), $upload_dir );
				return $this->ownership_reference_key( $identity === false ? $relative : $identity['relative'] );
			};
			$directory = dirname( $pre['file'] );
			$prefix = $directory === '.' || $directory === '' ? '' : $directory . '/';
			$planned_marked = [];
			foreach ( $plan['metadata']['sizes'] as $name => $entry ) {
				if ( $this->is_marked_legacy_size( $name, $entry ) && isset( $entry['file'] ) && is_string( $entry['file'] ) && basename( $entry['file'] ) === $entry['file'] ) { $planned_marked[ $path_key( $prefix . $entry['file'] ) ] = true; }
			}
			foreach ( $final_marked as $entry ) { if ( isset( $entry['file'] ) && is_string( $entry['file'] ) && basename( $entry['file'] ) === $entry['file'] ) { $planned_marked[ $path_key( $prefix . $entry['file'] ) ] = true; } }
			foreach ( $pre['sizes'] as $name => $old ) {
				if ( isset( $planned_marked[ $path_key( $old['path'] ) ] ) ) {
					$plan['outcomes'][ $name ] = 'legacy_limit_reached';
					unset( $plan['retire'][ $old['path'] ] );
				}
			}
			if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Regenerated files were promoted, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, $processed );
			}
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'legacy_limit_reached', __( 'Regenerated files were promoted, but attachment metadata could not be committed.', 'image-watermark' ), true, $processed );
		}
		$plan['metadata'] = $final;
		if ( ! $this->renew_operation_lock( $operation ) || ! $this->set_operation_state( $operation, 'metadata-commit' ) || $this->should_inject_operation_fault( 'before_metadata_commit', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) || ! $this->set_regenerated_metadata_commit_state( $operation, $final, 'pending' ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_fence_lost', __( 'Regenerated files were promoted, but their metadata could not be safely committed.', 'image-watermark' ), true, $processed );
		}
		if ( $this->should_inject_operation_fault( 'after_regenerated_metadata_pending', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_interrupted', __( 'Regenerated files were promoted, but the metadata commit was interrupted.', 'image-watermark' ), true, $processed );
		}
		$stored = wp_get_attachment_metadata( $attachment_id, true );
		if ( ! $this->write_name_stable_recovery( $operation, $plan ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Regenerated files were promoted, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, $processed );
		}
		$retire_failed = $this->retire_name_stable_paths( $operation, $plan, $upload_dir, is_array( $stored ) ? $stored : [] );
		if ( ! empty( $retire_failed ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'derivative_reconciliation_failed', __( 'Regenerated files were promoted, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, $processed, $retire_failed );
		}
		$sanitized_limit = false;
		if ( ! $this->commit_checked_regenerated_metadata( $operation, $final, $sanitized_limit ) ) {
			$this->set_regenerated_metadata_commit_state( $operation, $final, 'refused' );
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', $sanitized_limit ? 'legacy_limit_reached' : 'metadata_commit_failed', __( 'Regenerated files were promoted, but attachment metadata could not be committed.', 'image-watermark' ), true, $processed );
		}
		$plan['metadata'] = $final;
		// Check the actual whole receipt, including history, after the durable
		// metadata reread. Reader headroom is not an allowance for new writes.
		$receipt = $this->operation_state_candidate( $operation, 'metadata-commit', [
			'regenerated_metadata_commit' => [ 'state' => 'committed', 'hash' => hash( 'sha256', maybe_serialize( $final ) ) ],
		] );
		$receipt_published = false;
		if ( $receipt !== false && $this->is_valid_operation_journal( $receipt ) && strlen( maybe_serialize( $receipt ) ) <= self::NAME_STABILITY_JOURNAL_BUDGET ) {
			try {
				$receipt_published = $this->publish_operation_journal( $operation, $receipt );
			} catch ( \Throwable $error ) {
				// A write or its confirmation may have thrown after publication.
			}
		}
		if ( ! $receipt_published ) {
			// The pending prefix is the last confirmed authority, not necessarily
			// the stored value after an uncertain write. Publish no terminal guess
			// and do not clean recovery when this receipt cannot be confirmed.
			$message = __( 'Regenerated files were promoted, but the metadata commit could not be recorded.', 'image-watermark' );
			$result = $this->operation_result( 'interrupted', $operation['token'], 'journal_write_failed', $message, true, [ 'processed' => $processed, 'failed' => [], 'skipped' => [] ] );
			return $this->operation_failure( $data, $method, $attachment_id, $context, $result );
		}
		// A current promotion establishes the output's origin. Reapply may keep
		// its watermarked outputs; remove may only keep clean ones. A matching
		// hash, legacy marker or alias alone does not establish inherited origin.
		$committed_references = $this->name_stable_references( $final, $upload_dir );
		$known_promotions = [ 'keys' => [], 'identities' => [] ];
		foreach ( $staged_groups as $staged ) {
			if ( $staged['watermark'] && ! $reapply ) { continue; }
			$path = $staged['target']['target'];
			$known_promotions['keys'][ $this->ownership_reference_key( $path ) ] = true;
			$identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $path ), $upload_dir );
			if ( $identity !== false ) { $known_promotions['identities'][ $identity['identity'] ] = true; }
		}
		if ( ! empty( $inherited_paths ) ) {
			$retained = $this->retained_clean_promotions( $operation, $final, $main_path, $backup, $upload_dir );
			if ( $retained === false || ! $this->begin_prefix_confirmed( $operation ) || ! $this->read_stored_regenerated_metadata( $attachment_id, $durable_after_receipt ) || $durable_after_receipt !== $final || $this->name_stable_identity_changed( $attachment_id, $final, $pre ) ) {
				$message = __( 'Regenerated files were promoted, but their metadata could not be safely committed.', 'image-watermark' );
				$result = $this->operation_result( 'interrupted', $token, 'metadata_fence_lost', $message, true, [ 'processed' => $processed, 'failed' => [], 'skipped' => [] ] );
				return $this->operation_failure( $data, $method, $attachment_id, $context, $result );
			}
			$known_promotions['keys'] += $retained['keys'];
			$known_promotions['identities'] += $retained['identities'];
		}
		$unreconciled = [];
		if ( ! empty( $inherited_paths ) ) {
			$names = array_unique( array_merge( array_keys( $inherited_pre['sizes'] ), array_keys( $inherited_pre['candidates'] ) ) );
			foreach ( $names as $name ) {
				$paths = isset( $inherited_pre['sizes'][ $name ] ) ? [ $inherited_pre['sizes'][ $name ]['path'] ] : [];
				$paths = array_merge( $paths, isset( $inherited_pre['candidates'][ $name ] ) ? $inherited_pre['candidates'][ $name ] : [] );
				foreach ( array_unique( $paths ) as $path ) {
					if ( $this->name_stable_is_referenced( $committed_references, $path, $upload_dir ) && ! $this->name_stable_is_referenced( $known_promotions, $path, $upload_dir ) ) {
						$unreconciled[] = $name;
						break;
					}
				}
			}
		}
		if ( $unreconciled ) {
			$skipped = $this->name_stable_skipped_sizes( $plan );
			if ( $request !== null ) {
				foreach ( $shared_aliases as $shared ) {
					foreach ( $shared['unselected'] as $name ) {
						$skipped[] = [ 'name' => $name, 'code' => 'watermarked_via_shared_file' ];
					}
				}
			}
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'derivative_reconciliation_failed', __( 'Regenerated files were promoted, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, $processed, array_values( array_unique( array_merge( $plan['failed'], $unreconciled ) ) ), $skipped );
		}
		$manifest_evidence = null;
		$unverified_manifest = false;
		if ( $reapply ) {
			$watermarked = [];
			foreach ( $staged_groups as $staged ) {
				if ( $staged['watermark'] ) {
					$staged['target']['state'] = 'promoted';
					$watermarked[] = $staged;
				}
			}
			if ( $method === 'manual' && ! $this->final_references_match( $final, $watermarked, $upload_dir ) ) {
				// A metadata filter redirected a watermarked size: no manifest can
				// describe the committed layout, so the attachment is untrusted.
				$unverified_manifest = true;
			} elseif ( $method === 'manual' ) {
				$manifest_evidence = $this->build_manifest_evidence( $operation, $final, $watermarked, $options, $upload_dir );
				if ( $manifest_evidence === false || $this->should_inject_operation_fault( 'manifest_evidence', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) || ! $this->set_operation_state( $operation, 'metadata-commit', [ 'manifest_evidence' => $manifest_evidence ] ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermarked files were prepared, but the operation could not be safely completed.', 'image-watermark' ), true, $processed );
				}
			}
		}
		// Paths the committed metadata stopped naming are retired after the commit.
		// A remove clears the flag only once they are gone: a retry of an
		// unflagged attachment could not tell whether such a file is watermarked.
		if ( $reapply && ! $this->commit_watermarked_flag( $operation, 1 ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_failed', __( 'Watermarked files were prepared, but their watermark state could not be committed.', 'image-watermark' ), true, $processed );
		}
		$processed = $reapply ? $processed : array_merge( $main_kept ? [] : [ 'full' ], $plan['written'] );
		$deferred_failed = $this->retire_deferred_name_stable_paths( $operation, $plan, $upload_dir );
		$skipped = $this->name_stable_skipped_sizes( $plan );
		if ( $request !== null ) {
			foreach ( $shared_aliases as $shared ) {
				foreach ( $shared['unselected'] as $name ) {
					$skipped[] = [ 'name' => $name, 'code' => 'watermarked_via_shared_file' ];
				}
			}
		}
		if ( ! empty( $deferred_failed ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'derivative_reconciliation_failed', __( 'Regenerated files were promoted, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, $processed, $deferred_failed, $skipped );
		}
		$partial = $this->name_stable_partial_code( $plan );
		// A partial remove keeps files or entries that may still be watermarked
		// (shared, uncertain, conflicting, or not regenerated), so the attachment
		// stays flagged: a later apply then works from the clean source, never
		// over those bytes.
		if ( ! $reapply && ! $live_source && $partial === '' ) {
			$candidate = $request === null ? $this->operation_state_candidate( $operation, 'complete', [ 'code' => 'watermarkremoved', 'metadata_committed' => true, 'cleanup_pending' => true, 'counts' => [ 'processed' => count( $processed ), 'failed' => 0 ] ] ) : null;
			if ( $request === null && ( $candidate === false || ! $this->is_valid_operation_journal( $candidate ) || strlen( maybe_serialize( $candidate ) ) > self::NAME_STABILITY_JOURNAL_BUDGET ) ) {
				$message = __( 'The clean image layout was restored, but its recovery record cannot be extended safely. Retry the removal.', 'image-watermark' );
				$receipt = $this->operation_state_candidate( $operation, 'partial', [ 'code' => 'journal_budget_exceeded', 'counts' => [ 'processed' => count( $processed ), 'failed' => 0 ] ] );
				$recorded = $receipt !== false && $this->is_valid_operation_journal( $receipt ) && strlen( maybe_serialize( $receipt ) ) <= self::NAME_STABILITY_JOURNAL_BUDGET && $this->publish_operation_journal( $operation, $receipt );
				$result = $this->operation_result( $recorded ? 'partial' : 'interrupted', $operation['token'], $recorded ? 'journal_budget_exceeded' : 'journal_write_failed', $message, true, [ 'processed' => $processed, 'skipped' => $skipped, 'failed' => [] ] );
				if ( $recorded ) {
					$this->record_last_operation( $attachment_id, 'error', $result['code'], $message, $context, $processed, [], $skipped, 'partial', $operation );
				}
				return $this->operation_failure( $data, $method, $attachment_id, $context, $result );
			}
			if ( ! $this->commit_watermarked_flag( $operation, 0 ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_failed', __( 'The clean file was restored, but its watermark state could not be committed.', 'image-watermark' ), true, $processed );
			}
			if ( $request === null ) {
				$operation['clean_remove_completion'] = $candidate;
			}
		}
		if ( $partial !== '' ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', $partial, $this->name_stable_partial_message( $partial, $purpose ), true, $processed, $plan['failed'], $skipped );
		}
		if ( is_array( $manifest_evidence ) ) {
			$manifest = $this->manifest_from_evidence( $manifest_evidence, $operation['journal']['current'] );
			if ( $manifest === false || ! $this->write_watermark_manifest( $operation, $manifest ) ) {
				$this->discard_operation_manifest( $operation, $token );
			}
		}
		if ( $request !== null ) {
			return $this->operation_complete( $operation, $final, $attachment_id, 'manual', $context, $unverified_manifest ? 'manifest_unverified' : ( ! empty( $operation['regeneration_reconciled'] ) ? 'attachment_file_reconciled' : 'regenerated' ), __( 'Image sizes regenerated.', 'image-watermark' ), $reapply ? $processed : $plan['written'], $skipped );
		}
		if ( $reapply ) {
			return $this->operation_complete( $operation, $final, $attachment_id, $method, $context, $unverified_manifest ? 'manifest_unverified' : 'watermarked', __( 'Watermark applied.', 'image-watermark' ), $processed, $skipped );
		}

		return $this->operation_complete( $operation, $final, $attachment_id, 'manual', 'manual-remove', 'watermarkremoved', __( 'Watermark removed.', 'image-watermark' ), $processed, $skipped );
	}

	/**
	 * The `original_image` of an unwatermarked attachment as its sub-size
	 * source: core-equivalent, when the stem rule holds and the attachment is
	 * unedited. No provenance is needed, because nothing clean can be lost.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $pre Pre-operation map.
	 * @return array|null { path, size, sha256 }, or null.
	 */
	private function unwatermarked_original_source( $attachment_id, $pre, $read_only = false ) {
		if ( $pre['original_image'] === '' || $this->attachment_is_edited( $attachment_id, $pre ) ) {
			return null;
		}
		$directory = dirname( $pre['file'] );
		$failure = null;
		$resolved = $this->resolve_upload_artifact_path( ( $directory === '.' || $directory === '' ? '' : $directory . '/' ) . $pre['original_image'], false, '', 0, $failure, $read_only );
		if ( $resolved === false || ! is_file( $resolved['path'] ) ) {
			return null;
		}
		$size = filesize( $resolved['path'] );

		return is_int( $size ) && $size > 0 ? [ 'path' => $resolved['relative'], 'size' => $size, 'sha256' => hash_file( 'sha256', $resolved['path'] ) ] : null;
	}

	private function generate_metadata_under_operation( &$operation, $attachment_id, $filepath, $purpose, $previous_derivatives = [], $original_image = '' ) {
		$upload_dir = wp_upload_dir();
		$relative = $this->operation_relative_path( $filepath, $upload_dir );
		if ( $relative === false || ! $this->set_operation_state( $operation, 'regenerating', [
			'regeneration' => [
				'purpose' => $purpose,
				'source'  => $relative,
				'size'    => filesize( $filepath ),
				'hash'    => hash_file( 'sha256', $filepath ),
				'state'   => 'intent',
				'previous_derivatives' => $previous_derivatives,
			],
		] ) ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'metadata_regeneration', [ 'attachment_id' => $attachment_id, 'token' => $operation['token'], 'purpose' => $purpose ] ) ) {
			return false;
		}
		$this->internal_operation_guard = [ 'attachment_id' => $attachment_id, 'token' => $operation['token'] ];
		// This internal generation never re-scales or re-encodes the main
		// file. The suppression and the size observation exist only for this call.
		$this->guarded_generation = [ 'attachment_id' => $attachment_id, 'mime' => (string) wp_check_filetype( $filepath )['type'], 'requested' => null ];
		add_filter( 'big_image_size_threshold', [ $this, 'filter_guarded_generation_threshold' ], PHP_INT_MAX, 4 );
		add_filter( 'image_editor_output_format', [ $this, 'filter_guarded_generation_output_format' ], PHP_INT_MAX, 3 );
		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'observe_guarded_generation_sizes' ], PHP_INT_MAX, 3 );
		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
		} finally {
			remove_filter( 'big_image_size_threshold', [ $this, 'filter_guarded_generation_threshold' ], PHP_INT_MAX );
			remove_filter( 'image_editor_output_format', [ $this, 'filter_guarded_generation_output_format' ], PHP_INT_MAX );
			remove_filter( 'intermediate_image_sizes_advanced', [ $this, 'observe_guarded_generation_sizes' ], PHP_INT_MAX );
			$operation['requested_sizes'] = is_array( $this->guarded_generation ) ? $this->guarded_generation['requested'] : null;
			$this->guarded_generation = null;
			$this->internal_operation_guard = null;
		}
		if ( is_array( $metadata ) && is_string( $original_image ) && $original_image && basename( $original_image ) === $original_image && is_file( dirname( $filepath ) . DIRECTORY_SEPARATOR . $original_image ) ) {
			$metadata['original_image'] = $original_image;
		}
		if ( ! is_array( $metadata ) || ! $this->renew_operation_lock( $operation ) || ! $this->set_operation_state( $operation, 'metadata-commit', [
			'regeneration' => [
				'purpose'       => $purpose,
				'source'        => $relative,
				'size'          => filesize( $filepath ),
				'hash'          => hash_file( 'sha256', $filepath ),
				'metadata_hash' => hash( 'sha256', maybe_serialize( $metadata ) ),
				'sizes'         => isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : [],
				'state'         => 'generated',
				'previous_derivatives' => $previous_derivatives,
			],
		] ) ) {
			return false;
		}
		return $metadata;
	}

	/** @return array */
	private function execute_staged_remove( $data, $attachment_id, $filepath, $backup_filepath, $options, $invocation = null ) {
		$token = $invocation !== null ? $invocation->operation_state['token'] : $this->operation_token();
		$lock = $invocation !== null ? $invocation->operation_state['lock'] : $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true ) );
		}
		$operation = $invocation !== null ? $this->borrow_contract_operation( $invocation ) : [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock, 'time_origin' => microtime( true ) ];
		try {
			$upload_dir = wp_upload_dir();
			$early = null;
			if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) !== 1 ) {
				// Run D-009 before begin_operation can settle a handoff or clean up
				// predecessor stages. Remove regenerates from the backup, not the
				// potentially damaged live main.
				$request = [ 'mode' => 'all', 'sizes' => [], 'strict_aliases' => false, 'dry_run' => false ];
				$early = $this->unwatermarked_regeneration_preflight( $attachment_id, [ 'metadata' => $data, 'source' => $backup_filepath ], $request, $options );
				if ( isset( $early['error'] ) ) {
					if ( $invocation !== null && isset( $early['outcome']['outcome'], $early['outcome']['code'] ) && ! in_array( $early['outcome']['code'], [ 'handoff_pending', 'journal_invalid', 'journal_write_failed', 'journal_budget_exceeded' ], true ) && $this->classify_operation_retryability( $early['outcome']['outcome'], $early['outcome']['code'] ) !== null ) {
						$outcome = $early['outcome'];
						$result = $this->persist_terminal_outcome( $attachment_id, 'remove', 'manual-remove', $outcome['outcome'], $outcome['code'], $outcome['message'], 'error', $invocation );
						return [ 'error' => $result['message'], 'outcome' => $result ];
					}
					return $early;
				}
			}
			$relative = $this->operation_destination_relative_path( $filepath, $upload_dir );
			if ( $relative === false ) {
				return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
			}
			$groups = [ $relative => [ 'target' => $relative, 'aliases' => [ 'full' ] ] ];
			$operation['name_stable_budget'] = true;
			$journal = $this->read_operation_journal( $attachment_id );
			if ( $journal === false ) {
				return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
			}
			// A pending handoff must be admitted before any retry-flag effect.
			if ( $this->is_pending_handoff( isset( $journal['current'] ) ? $journal['current'] : null ) || $this->is_pending_handoff( isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ) ) {
				$operation['prepared_handoff'] = $this->prepare_pending_handoff( $operation, $journal );
				if ( $operation['prepared_handoff'] === false ) {
					return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
				}
				$operation['journal'] = $journal;
				if ( ! $this->prepared_handoff_confirmed( $operation, $operation['prepared_handoff'] ) ) {
					return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->handoff_pending_result( $token ) );
				}
				$settled = $this->resolve_pending_handoff( $operation, $journal );
				if ( $settled === 'handoff_pending' || $settled === 'begin_refused' ) {
					if ( $settled === 'handoff_pending' ) { $operation['begin_error_code'] = 'handoff_pending'; }
					return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
				}
				$journal = $this->read_operation_journal( $attachment_id );
				if ( $journal === false ) {
					return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
				}
			}
			$operation['prepared_begin'] = $this->prepare_begin_operation( $operation, $journal, 'remove', 'manual-remove', $groups );
			if ( $operation['prepared_begin'] === false ) {
				return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
			}
			$operation['journal'] = $journal;
			if ( ! $this->begin_prefix_confirmed( $operation ) ) {
				return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
			}
			// Decide from the predecessor, before begin replaces current with this remove.
			$guarded_retry = false;
			$guard_needed = false;
			if ( $early !== null ) {
				$journal = $this->read_operation_journal( $attachment_id );
				$current = is_array( $journal ) && isset( $journal['current'] ) ? $journal['current'] : null;
				$guard_needed = is_array( $current ) && isset( $current['type'], $current['state'] ) && $current['type'] === 'remove' && $current['state'] !== 'complete';
				if ( $guard_needed && $invocation === null ) {
					if ( ! $this->commit_watermarked_flag( $operation, 1 ) ) {
						return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->operation_result( 'interrupted', $token, 'metadata_fence_lost', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true ) );
					}
					$guarded_retry = true;
					$operation['unwatermarked_retry_guard'] = true;
				}
			}
			if ( ! $this->begin_operation( $operation, 'remove', 'manual-remove', $groups ) ) {
				return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->begin_failure_result( $operation, $token ) );
			}
			if ( $early !== null ) {
				$inherited = isset( $operation['journal']['current']['pre_operation'] ) ? $operation['journal']['current']['pre_operation'] : null;
				if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) !== ( $guarded_retry ? 1 : 0 ) || get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) !== $early['stored_metadata'] || get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $early['pre']['file'] || $inherited !== $early['inherited']['pre'] || $this->unwatermarked_original_source( $attachment_id, $early['pre'] ) !== $early['original'] || ! is_file( $backup_filepath ) || hash_file( 'sha256', $backup_filepath ) !== $early['source_hash'] ) {
					return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'failed', 'predecessor_uncertain', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ), true );
				}
				if ( $guard_needed && $invocation !== null ) {
					if ( ! $this->begin_prefix_confirmed( $operation ) || ! $this->commit_watermarked_flag( $operation, 1 ) ) {
						$result = $this->begin_prefix_confirmed( $operation ) ? $this->contract_begin_refusal( $operation, 'manual-remove', 'metadata_write_failed' ) : $this->operation_result( 'interrupted', $token, 'metadata_fence_lost', '', true );
						return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $result );
					}
					$guarded_retry = true;
					$operation['unwatermarked_retry_guard'] = true;
					if ( ! $this->begin_prefix_confirmed( $operation ) ) {
						return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->operation_result( 'interrupted', $token, 'metadata_fence_lost', '', true ) );
					}
					$inherited = isset( $operation['journal']['current']['pre_operation'] ) ? $operation['journal']['current']['pre_operation'] : null;
					if ( (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) !== 1 || get_post_meta( $attachment_id, '_wp_attachment_metadata', true ) !== $early['stored_metadata'] || get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $early['pre']['file'] || $inherited !== $early['inherited']['pre'] || $this->unwatermarked_original_source( $attachment_id, $early['pre'] ) !== $early['original'] || ! is_file( $backup_filepath ) || hash_file( 'sha256', $backup_filepath ) !== $early['source_hash'] ) {
						return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'failed', 'predecessor_uncertain', __( 'Image sizes could not be safely regenerated.', 'image-watermark' ), true );
					}
				}
				$operation['early_regeneration_plan'] = $early;
			}
			return $this->execute_staged_regeneration( $operation, $data, 'remove', $options, $upload_dir, $backup_filepath, 'manual', 'manual-remove' );
		} finally {
			$this->internal_operation_guard = null;
			if ( $invocation === null ) { $this->release_operation_lock( $operation ); }
		}
	}

	/**
	 * Build a consistent metadata preservation result.
	 *
	 * @param bool   $success Whether metadata was preserved.
	 * @param string $error Error message when preservation failed.
	 * @return array{success:bool,error:string}
	 */
	private function metadata_write_result( $success, $error = '' ) {
		return [
			'success' => (bool) $success,
			'error'   => (string) $error,
		];
	}

	/**
	 * Applies the watermark to a single image path.
	 *
	 * @param int $attachment_id
	 * @param string $image_path
	 * @param string $image_size
	 * @param array $upload_dir
	 * @param array $metadata
	 * @return bool True when the target file was written successfully, false otherwise.
	 */
	public function do_watermark( $attachment_id, $image_path, $image_size, $upload_dir, $metadata = [], $options = null ) {
		if ( ! is_array( $options ) ) {
			$options = apply_filters( 'iw_watermark_options', $this->plugin->options );
		}
		$options = $this->normalize_rotation_options( $options );
		$this->last_renderer_failure = [ 'code' => '', 'message' => '' ];
		$mime = wp_check_filetype( $image_path );

		$watermark_type = isset( $options['watermark_image']['type'] ) ? $options['watermark_image']['type'] : 'image';
		$rotation       = $this->get_watermark_rotation( $options );
		$capability     = $this->describe_rotation_capability( $options );
		if ( ! $capability['available'] ) {
			$this->set_renderer_failure( $capability['code'], $capability['message'] );
			return false;
		}

		if ( $watermark_type === 'image' ) {
			if ( ! wp_attachment_is_image( $options['watermark_image']['url'] ) ) {
				return false;
			}

			$watermark_file = wp_get_attachment_metadata( $options['watermark_image']['url'], true );

			if ( ! is_array( $watermark_file ) || empty( $watermark_file['file'] ) ) {
				return false;
			}

			$watermark_path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $watermark_file['file'];

			if ( ! is_file( $watermark_path ) ) {
				return false;
			}
		} elseif ( $watermark_type === 'text' ) {
			// For text watermark, we don't need a file path
			} else {
				return false; // Unknown type
			}

			if ( ! $this->has_valid_custom_watermark_dimensions( $options ) ) {
				return false;
			}

		if ( $this->plugin->get_extension() === 'imagick' ) {
			$image = null;
			$watermark = null;
			$write_success = false;

			try {
				$image = new Imagick( $image_path );

				if ( $watermark_type === 'image' ) {
					$watermark = new Imagick( $watermark_path );

					if ( $rotation === 0 && $watermark->getImageAlphaChannel() > 0 ) {
						$watermark->evaluateImage( Imagick::EVALUATE_MULTIPLY, round( (float) ( $options['watermark_image']['transparent'] / 100 ), 2 ), Imagick::CHANNEL_ALPHA );
					} elseif ( $rotation === 0 && $this->set_imagick_image_opacity( $watermark, round( (float) ( $options['watermark_image']['transparent'] / 100 ), 2 ) ) === false ) {
						return false;
					}
				}

				if ( $mime['type'] === 'image/jpeg' ) {
					$image->setImageCompressionQuality( $options['watermark_image']['quality'] );
					$image->setImageCompression( imagick::COMPRESSION_JPEG );
				} else {
					$image->setImageCompressionQuality( $options['watermark_image']['quality'] );
				}

				if ( $options['watermark_image']['jpeg_format'] === 'progressive' ) {
					$image->setImageInterlaceScheme( Imagick::INTERLACE_PLANE );
				}

				$image_dim = $image->getImageGeometry();

					if ( $watermark_type === 'image' && $rotation === 0 ) {
						$watermark_dim = $watermark->getImageGeometry();

						list( $width, $height ) = $this->calculate_watermark_dimensions( $image_dim['width'], $image_dim['height'], $watermark_dim['width'], $watermark_dim['height'], $options );
						if ( $width <= 0 || $height <= 0 ) {
							return false;
						}

						$watermark->resizeImage( $width, $height, imagick::FILTER_CATROM, 1 );

					list( $dest_x, $dest_y ) = $this->calculate_image_coordinates( $image_dim['width'], $image_dim['height'], $width, $height, $options );

						if ( ! $image->compositeImage( $watermark, Imagick::COMPOSITE_DEFAULT, $dest_x, $dest_y, Imagick::CHANNEL_ALL ) ) {
							return false;
						}
					} elseif ( $watermark_type === 'image' ) {
						if ( ! $this->apply_rotated_image_watermark_imagick( $image, $watermark, $options, $image_dim['width'], $image_dim['height'] ) ) {
							return false;
						}
					} elseif ( $watermark_type === 'text' && $rotation !== 0 ) {
						if ( ! $this->apply_rotated_text_watermark_imagick( $image, $options, $image_dim['width'], $image_dim['height'] ) ) {
							return false;
						}
					} elseif ( $watermark_type === 'text' ) {
						$this->apply_text_watermark_imagick( $image, $options, $image_dim['width'], $image_dim['height'] );
				}

				$write_success = (bool) $image->writeImage( $image_path );
			} catch ( ImagickException $e ) {
				return false;
			} finally {
				if ( $watermark instanceof Imagick ) {
					$watermark->clear();
					$watermark->destroy();
				}

				if ( $image instanceof Imagick ) {
					$image->clear();
					$image->destroy();
				}
			}

			return $write_success;
		} else {
			$image = $this->get_image_resource( $image_path, $mime['type'] );

			if ( $this->is_gd_image( $image ) ) {
				if ( $watermark_type === 'image' ) {
					$rendered = $this->add_watermark_image( $image, $options, $upload_dir );
				} elseif ( $watermark_type === 'text' ) {
					$rendered = $this->apply_text_watermark_gd( $image, $options );
				} else {
					$rendered = false;
				}

				if ( ! $this->is_gd_image( $rendered ) ) {
					$this->destroy_gd_image( $image );
					return false;
				}
				$image = $rendered;

				if ( $this->is_gd_image( $image ) ) {
					$written = $this->save_image_file( $image, $mime['type'], $image_path, $options['watermark_image']['quality'] );
					$this->destroy_gd_image( $image );
					$image = null;
					return $written;
				}
			}

			return false;
		}
	}

	/**
	 * Apply text watermark using Imagick.
	 *
	 * @param Imagick $image The image object.
	 * @param array $options The watermark options.
	 * @param int $image_width Image width.
	 * @param int $image_height Image height.
	 */
	private function apply_text_watermark_imagick( $image, $options, $image_width, $image_height ) {
		$text = isset( $options['watermark_image']['text_string'] ) ? $options['watermark_image']['text_string'] : '';
		if ( empty( $text ) ) {
			return;
		}

		$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
		$font_path = $this->plugin->get_font_path( $font );
		if ( ! $font_path || ! file_exists( $font_path ) ) {
			return;
		}

		$size = isset( $options['watermark_image']['text_size'] ) ? (int) $options['watermark_image']['text_size'] : 20;
		$color = isset( $options['watermark_image']['text_color'] ) ? $options['watermark_image']['text_color'] : '#ffffff';
		$opacity = isset( $options['watermark_image']['transparent'] ) ? (int) $options['watermark_image']['transparent'] : 50;

		$base_metrics = $this->measure_text_imagick( $font_path, $size, $text );
		$base_width = max( 1, (int) round( $base_metrics['width'] ) );
		$base_height = max( 1, (int) round( $base_metrics['height'] ) );

		list( $target_width, $target_height ) = $this->calculate_text_target_dimensions( $base_width, $base_height, $image_width, $image_height, $options['watermark_image'] );

		$scale = min( $target_width / $base_width, $target_height / $base_height, 1 );
		$render_size = max( 1, (int) round( $size * $scale ) );

		$scaled_metrics = $this->measure_text_imagick( $font_path, $render_size, $text );
		$render_width = max( 1, (int) round( $scaled_metrics['width'] ) );
		$render_height = max( 1, (int) round( $scaled_metrics['height'] ) );
		$render_ascent = max( 0, (int) round( $scaled_metrics['ascent'] ) );

		list( $x, $y ) = $this->calculate_image_coordinates( $image_width, $image_height, $render_width, $render_height, $options );

		// Convert hex color to RGB
		$color = ltrim( $color, '#' );
		$r = hexdec( substr( $color, 0, 2 ) );
		$g = hexdec( substr( $color, 2, 2 ) );
		$b = hexdec( substr( $color, 4, 2 ) );

		$draw = new ImagickDraw();
		$draw->setFont( $font_path );
		$draw->setFontSize( $render_size );
		$draw->setFillColor( "rgb($r, $g, $b)" );
		$draw->setFillOpacity( $opacity / 100 );

		// Imagick annotate uses baseline for Y; align baseline to top + ascent.
		$image->annotateImage( $draw, $x, $y + $render_ascent, 0, $text );

		$draw->clear();
		$draw->destroy();
	}

	/**
	 * Rotate and composite a prepared image watermark through a transparent
	 * Imagick layer. This is intentionally separate from the zero-angle path.
	 *
	 * @return bool
	 */
	private function apply_rotated_image_watermark_imagick( $image, $watermark, $options, $image_width, $image_height ) {
		$rotation = $this->get_watermark_rotation( $options );
		if ( ! $watermark instanceof Imagick || $rotation === 0 ) {
			return false;
		}
		$source_geometry = $watermark->getImageGeometry();
		if ( empty( $source_geometry['width'] ) || empty( $source_geometry['height'] ) ) {
			$this->set_renderer_failure( 'rotation_failed', __( 'The watermark image could not be prepared for rotation.', 'image-watermark' ) );
			return false;
		}
		list( $width, $height ) = $this->calculate_watermark_dimensions( $image_width, $image_height, $source_geometry['width'], $source_geometry['height'], $options );
		if ( $this->is_scaled_rotation( $options ) ) {
			list( $width, $height ) = $this->fit_scaled_rotation_dimensions( $width, $height, $image_width, $image_height, $rotation );
		}
		if ( $width <= 0 || $height <= 0 ) {
			$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark dimensions are invalid.', 'image-watermark' ) );
			return false;
		}

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			list( $predicted_width, $predicted_height ) = $this->calculate_rotated_layer_bounds( $width, $height, $rotation );
			if ( ! $this->has_valid_gd_dimensions( $width, $height ) || ! $this->has_valid_gd_dimensions( $predicted_width, $predicted_height ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The predicted rotated watermark allocation is unsafe.', 'image-watermark' ) );
				return false;
			}
			$source = null;
			$layer  = null;
			try {
				$source = clone $watermark;
				$source_alpha = $source->getImageAlphaChannel() > 0 ? Imagick::ALPHACHANNEL_ACTIVATE : Imagick::ALPHACHANNEL_OPAQUE;
				if ( ! $source->setImageAlphaChannel( $source_alpha ) || ! $source->resizeImage( $width, $height, Imagick::FILTER_CATROM, 1 ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The watermark image could not be prepared for rotation.', 'image-watermark' ) );
					return false;
				}
				$layer = new Imagick();
				if ( ! $layer->newImage( $width, $height, new ImagickPixel( 'transparent' ), 'png' ) || ! $layer->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE ) || ! $layer->compositeImage( $source, Imagick::COMPOSITE_DEFAULT, 0, 0, Imagick::CHANNEL_ALL ) || ! $layer->rotateImage( new ImagickPixel( 'transparent' ), $rotation ) || ! $layer->setImagePage( 0, 0, 0, 0 ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The watermark image could not be rotated.', 'image-watermark' ) );
					return false;
				}
				$geometry = $layer->getImageGeometry();
				$actual_width = isset( $geometry['width'] ) ? (int) $geometry['width'] : 0;
				$actual_height = isset( $geometry['height'] ) ? (int) $geometry['height'] : 0;
				if ( $actual_width <= 0 || $actual_height <= 0 ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark image has invalid dimensions.', 'image-watermark' ) );
					return false;
				}
				if ( $this->is_scaled_rotation( $options ) && ( $actual_width > $image_width || $actual_height > $image_height ) && $attempt === 0 ) {
					$factor = min( $image_width / $actual_width, $image_height / $actual_height, 1 );
					$next_width = max( 1, (int) floor( $width * $factor ) );
					$next_height = max( 1, (int) floor( $height * $factor ) );
					if ( $next_width === $width && $next_height === $height ) {
						$this->set_renderer_failure( 'rotation_failed', __( 'The scaled rotated watermark cannot fit the target image.', 'image-watermark' ) );
						return false;
					}
					$width = $next_width;
					$height = $next_height;
					continue;
				}
				if ( $this->is_scaled_rotation( $options ) && ( $actual_width > $image_width || $actual_height > $image_height ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The scaled rotated watermark cannot fit the target image.', 'image-watermark' ) );
					return false;
				}
				if ( ! $this->apply_imagick_layer_opacity( $layer, $options['watermark_image']['transparent'] ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark opacity could not be applied.', 'image-watermark' ) );
					return false;
				}
				list( $x, $y ) = $this->calculate_image_coordinates( $image_width, $image_height, $actual_width, $actual_height, $options );
				if ( ! $image->compositeImage( $layer, Imagick::COMPOSITE_DEFAULT, $x, $y, Imagick::CHANNEL_ALL ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark image could not be composed.', 'image-watermark' ) );
					return false;
				}
				return true;
			} catch ( Exception $exception ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark image could not be rotated.', 'image-watermark' ) );
				return false;
			} finally {
				if ( $source instanceof Imagick ) {
					$source->clear();
					$source->destroy();
				}
				if ( $layer instanceof Imagick ) {
					$layer->clear();
					$layer->destroy();
				}
			}
		}

		return false;
	}

	/**
	 * Draw unrotated text into a transparent layer sized from complete-line
	 * metrics. The layer must contain every glyph pixel before rotation, so the
	 * horizontal extent uses the complete text width widened by the leading
	 * glyph's negative bearing and the trailing glyph's ink overhang, and the
	 * vertical extent uses the ascender and descender plus any glyph overshoot.
	 *
	 * @param string $font_path Resolved font path.
	 * @param int $size Font size.
	 * @param string $text Watermark text.
	 * @param string $color Hex fill color.
	 * @return array{layer:Imagick,width:int,height:int}|false
	 */
	private function create_imagick_text_layer( $font_path, $size, $text, $color ) {
		$layer = null;
		$draw = null;
		try {
			$metrics = $this->measure_text_imagick( $font_path, $size, $text );
			$extents = $this->measure_text_layer_extents_imagick( $font_path, $size, $text, $metrics );
			// One whole pixel of slack on every side keeps the outermost antialiased
			// column or row inside the layer when a bearing or extent is fractional.
			$pad = 1;
			$origin_x = (int) ceil( max( 0.0, (float) $extents['left'] ) ) + $pad;
			$origin_y = (int) ceil( max( 0.0, (float) $extents['top'] ) ) + $pad;
			$width = max( 1, $origin_x + (int) ceil( (float) $metrics['width'] + max( 0.0, (float) $extents['right'] ) ) + $pad );
			$height = max( 1, $origin_y + (int) ceil( max( 0.0, (float) $extents['bottom'] ) ) + $pad );
			if ( ! $this->has_valid_gd_dimensions( $width, $height ) ) {
				return false;
			}
			$layer = new Imagick();
			$draw = new ImagickDraw();
			$hex = ltrim( $color, '#' );
			$draw->setFont( $font_path );
			$draw->setFontSize( $size );
			$draw->setFillColor( sprintf( 'rgb(%d,%d,%d)', hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ) );
			if ( ! $layer->newImage( $width, $height, new ImagickPixel( 'transparent' ), 'png' ) || ! $layer->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE ) || ! $layer->annotateImage( $draw, $origin_x, $origin_y, 0, $text ) || ! $layer->setImagePage( 0, 0, 0, 0 ) ) {
				throw new ImagickException();
			}
			return [ 'layer' => $layer, 'width' => $width, 'height' => $height ];
		} catch ( Exception $exception ) {
			if ( $layer instanceof Imagick ) {
				$layer->clear();
				$layer->destroy();
			}
			return false;
		} finally {
			if ( $draw instanceof ImagickDraw ) {
				$draw->clear();
				$draw->destroy();
			}
		}
	}

	/** @return bool */
	private function apply_rotated_text_watermark_imagick( $image, $options, $image_width, $image_height ) {
		$text = isset( $options['watermark_image']['text_string'] ) ? $options['watermark_image']['text_string'] : '';
		$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
		$font_path = $this->plugin->get_font_path( $font );
		$rotation = $this->get_watermark_rotation( $options );
		if ( $rotation === 0 || $text === '' || ! $font_path || ! is_file( $font_path ) ) {
			return false;
		}
		try {
			$base = $this->measure_text_imagick( $font_path, (int) $options['watermark_image']['text_size'], $text );
		} catch ( Exception $exception ) {
			$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be prepared for rotation.', 'image-watermark' ) );
			return false;
		}
		list( $target_width, $target_height ) = $this->calculate_text_target_dimensions( max( 1, (int) round( $base['width'] ) ), max( 1, (int) round( $base['height'] ) ), $image_width, $image_height, $options['watermark_image'] );
		$scale = min( $target_width / max( 1, $base['width'] ), $target_height / max( 1, $base['height'] ), 1 );
		$size = max( 1, (int) round( (int) $options['watermark_image']['text_size'] * $scale ) );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$created = $this->create_imagick_text_layer( $font_path, $size, $text, $options['watermark_image']['text_color'] );
			if ( ! is_array( $created ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be prepared for rotation.', 'image-watermark' ) );
				return false;
			}
			$layer = $created['layer'];
			try {
				if ( $this->is_scaled_rotation( $options ) ) {
					list( $fit_width, $fit_height ) = $this->fit_scaled_rotation_dimensions( $created['width'], $created['height'], $image_width, $image_height, $rotation );
					$factor = min( $fit_width / $created['width'], $fit_height / $created['height'], 1 );
					if ( $factor < 1 && $attempt === 0 ) {
						$size = max( 1, (int) floor( $size * $factor ) );
						continue;
					}
				}
				if ( ! $layer->rotateImage( new ImagickPixel( 'transparent' ), $rotation ) || ! $layer->setImagePage( 0, 0, 0, 0 ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be rotated.', 'image-watermark' ) );
					return false;
				}
				$geometry = $layer->getImageGeometry();
				$width = isset( $geometry['width'] ) ? (int) $geometry['width'] : 0;
				$height = isset( $geometry['height'] ) ? (int) $geometry['height'] : 0;
				if ( $width <= 0 || $height <= 0 || ( $this->is_scaled_rotation( $options ) && ( $width > $image_width || $height > $image_height ) ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The scaled rotated watermark text cannot fit the target image.', 'image-watermark' ) );
					return false;
				}
				if ( ! $this->apply_imagick_layer_opacity( $layer, $options['watermark_image']['transparent'] ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark opacity could not be applied.', 'image-watermark' ) );
					return false;
				}
				list( $x, $y ) = $this->calculate_image_coordinates( $image_width, $image_height, $width, $height, $options );
				if ( ! $image->compositeImage( $layer, Imagick::COMPOSITE_DEFAULT, $x, $y, Imagick::CHANNEL_ALL ) ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark text could not be composed.', 'image-watermark' ) );
					return false;
				}
				return true;
			} catch ( Exception $exception ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be rotated.', 'image-watermark' ) );
				return false;
			} finally {
				$layer->clear();
				$layer->destroy();
			}
		}
		return false;
	}

	/** @return bool */
	private function apply_imagick_layer_opacity( $layer, $opacity ) {
		if ( ! $layer instanceof Imagick ) {
			return false;
		}
		return (bool) $layer->evaluateImage( Imagick::EVALUATE_MULTIPLY, round( max( 0, min( 100, (int) $opacity ) ) / 100, 2 ), Imagick::CHANNEL_ALPHA );
	}

	/**
	 * Set whole-image opacity on an Imagick object using whichever API is available.
	 *
	 * Prefers setImageAlpha() (modern Imagick/ImageMagick 7+) and falls back to the
	 * deprecated setImageOpacity() on older builds.
	 *
	 * @param Imagick $image The image object.
	 * @param float $opacity Opacity value between 0 and 1.
	 * @return bool True on success, false when no compatible method exists.
	 */
	private function set_imagick_image_opacity( $image, $opacity ) {
		if ( method_exists( $image, 'setImageAlpha' ) ) {
			return (bool) $image->setImageAlpha( $opacity );
		}

		if ( method_exists( $image, 'setImageOpacity' ) ) {
			return (bool) $image->setImageOpacity( $opacity );
		}

		return false;
	}

	/**
	 * Apply text watermark using GD.
	 *
	 * @param resource $image The GD image resource.
	 * @param array $options The watermark options.
	 * @return resource|false The modified image resource or false on failure.
	 */
	private function apply_text_watermark_gd( $image, $options ) {
		if ( ! $this->is_gd_image( $image ) || ! $this->gd_functions_available( [ 'imagesx', 'imagesy', 'imagettfbbox', 'imagettftext', 'imagecolorallocatealpha', 'imagealphablending', 'imagesavealpha' ] ) ) {
			return false;
		}
		if ( $this->get_watermark_rotation( $options ) !== 0 ) {
			return $this->apply_rotated_text_watermark_gd( $image, $options );
		}

		$text = isset( $options['watermark_image']['text_string'] ) ? $options['watermark_image']['text_string'] : '';
		if ( empty( $text ) ) {
			return $image;
		}

		$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
		$font_path = $this->plugin->get_font_path( $font );
		if ( ! $font_path || ! file_exists( $font_path ) ) {
			return false;
		}

		$size = isset( $options['watermark_image']['text_size'] ) ? (int) $options['watermark_image']['text_size'] : 20;
		$color = isset( $options['watermark_image']['text_color'] ) ? $options['watermark_image']['text_color'] : '#ffffff';
		$opacity = isset( $options['watermark_image']['transparent'] ) ? (int) $options['watermark_image']['transparent'] : 50;

		$image_width = imagesx( $image );
		$image_height = imagesy( $image );
		if ( ! $this->has_valid_gd_dimensions( $image_width, $image_height ) ) {
			return false;
		}

		$base_bbox = imagettfbbox( $size, 0, $font_path, $text );
		if ( ! is_array( $base_bbox ) || ! isset( $base_bbox[0], $base_bbox[1], $base_bbox[2], $base_bbox[7] ) ) {
			return false;
		}
		$base_width = max( 1, $base_bbox[2] - $base_bbox[0] );
		$base_height = max( 1, $base_bbox[1] - $base_bbox[7] );

		list( $target_width, $target_height ) = $this->calculate_text_target_dimensions( $base_width, $base_height, $image_width, $image_height, $options['watermark_image'] );
		$scale = min( $target_width / $base_width, $target_height / $base_height, 1 );
		$render_size = max( 1, (int) round( $size * $scale ) );
		if ( ! $this->has_valid_gd_dimensions( $target_width, $target_height ) || ! $this->has_valid_gd_dimensions( $render_size, 1 ) ) {
			return false;
		}

		$render_bbox = imagettfbbox( $render_size, 0, $font_path, $text );
		if ( ! is_array( $render_bbox ) || ! isset( $render_bbox[0], $render_bbox[1], $render_bbox[2], $render_bbox[7] ) ) {
			return false;
		}
		$render_width = max( 1, $render_bbox[2] - $render_bbox[0] );
		$render_height = max( 1, $render_bbox[1] - $render_bbox[7] );
		$render_ascent = max( 0, abs( $render_bbox[7] ) );

		list( $x, $y ) = $this->calculate_image_coordinates( $image_width, $image_height, $render_width, $render_height, $options );

		// Convert hex color to RGB
		$color = ltrim( $color, '#' );
		$r = hexdec( substr( $color, 0, 2 ) );
		$g = hexdec( substr( $color, 2, 2 ) );
		$b = hexdec( substr( $color, 4, 2 ) );

		$alpha = (int) round( ( 127 * ( 100 - $opacity ) ) / 100 );
		// Draw with source-over compositing while retaining the PNG alpha channel.
		// This is required for both PHP 7 resources and PHP 8 GdImage objects.
		imagealphablending( $image, true );
		imagesavealpha( $image, true );
		$text_color = imagecolorallocatealpha( $image, $r, $g, $b, $alpha );
		if ( $text_color === false ) {
			return false;
		}

		// imagettftext expects baseline Y; align baseline to top + ascent.
		if ( imagettftext( $image, $render_size, 0, $x, $y + $render_ascent, $text_color, $font_path, $text ) === false ) {
			return false;
		}

		return $image;
	}

	/** @return resource|\GdImage|false */
	private function apply_rotated_text_watermark_gd( $image, $options ) {
		if ( ! $this->is_gd_image( $image ) || ! $this->gd_functions_available( [ 'imagesx', 'imagesy', 'imagettfbbox', 'imagettftext', 'imagecolorallocatealpha', 'imagerotate', 'imagealphablending', 'imagesavealpha' ] ) ) {
			$this->set_renderer_failure( 'rotation_unavailable', __( 'Watermark rotation is not available in GD on this server.', 'image-watermark' ) );
			return false;
		}
		$text = isset( $options['watermark_image']['text_string'] ) ? $options['watermark_image']['text_string'] : '';
		$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
		$font_path = $this->plugin->get_font_path( $font );
		$rotation = $this->get_watermark_rotation( $options );
		if ( $text === '' || ! $font_path || ! is_file( $font_path ) || $rotation === 0 ) {
			return false;
		}
		$image_width = imagesx( $image );
		$image_height = imagesy( $image );
		$base_size = isset( $options['watermark_image']['text_size'] ) ? (int) $options['watermark_image']['text_size'] : 20;
		$base_box = imagettfbbox( $base_size, 0, $font_path, $text );
		if ( ! is_array( $base_box ) || count( $base_box ) < 8 ) {
			$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be measured for rotation.', 'image-watermark' ) );
			return false;
		}
		$base_width = max( 1, (int) ceil( max( $base_box[0], $base_box[2], $base_box[4], $base_box[6] ) - min( $base_box[0], $base_box[2], $base_box[4], $base_box[6] ) ) );
		$base_height = max( 1, (int) ceil( max( $base_box[1], $base_box[3], $base_box[5], $base_box[7] ) - min( $base_box[1], $base_box[3], $base_box[5], $base_box[7] ) ) );
		list( $target_width, $target_height ) = $this->calculate_text_target_dimensions( $base_width, $base_height, $image_width, $image_height, $options['watermark_image'] );
		$scale = min( $target_width / $base_width, $target_height / $base_height, 1 );
		$size = max( 1, (int) round( $base_size * $scale ) );
		$hex = ltrim( isset( $options['watermark_image']['text_color'] ) ? $options['watermark_image']['text_color'] : '#ffffff', '#' );
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$box = imagettfbbox( $size, 0, $font_path, $text );
			if ( ! is_array( $box ) || count( $box ) < 8 ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be measured for rotation.', 'image-watermark' ) );
				return false;
			}
			$min_x = floor( min( $box[0], $box[2], $box[4], $box[6] ) );
			$max_x = ceil( max( $box[0], $box[2], $box[4], $box[6] ) );
			$min_y = floor( min( $box[1], $box[3], $box[5], $box[7] ) );
			$max_y = ceil( max( $box[1], $box[3], $box[5], $box[7] ) );
			$width = max( 1, (int) ( $max_x - $min_x ) );
			$height = max( 1, (int) ( $max_y - $min_y ) );
			if ( $this->is_scaled_rotation( $options ) ) {
				list( $fit_width, $fit_height ) = $this->fit_scaled_rotation_dimensions( $width, $height, $image_width, $image_height, $rotation );
				$factor = min( $fit_width / $width, $fit_height / $height, 1 );
				if ( $factor < 1 && $attempt === 0 ) {
					$size = max( 1, (int) floor( $size * $factor ) );
					continue;
				}
			}
			list( $predicted_width, $predicted_height ) = $this->calculate_rotated_layer_bounds( $width, $height, $rotation );
			if ( ! $this->has_valid_gd_dimensions( $width, $height ) || ! $this->has_valid_gd_dimensions( $predicted_width, $predicted_height ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The predicted rotated text allocation is unsafe.', 'image-watermark' ) );
				return false;
			}
			$layer = $this->create_transparent_gd_layer( $width, $height );
			if ( ! $this->is_gd_image( $layer ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text layer could not be allocated.', 'image-watermark' ) );
				return false;
			}
			$color = imagecolorallocatealpha( $layer, $r, $g, $b, 0 );
			if ( $color === false || imagettftext( $layer, $size, 0, -$min_x, -$min_y, $color, $font_path, $text ) === false ) {
				$this->destroy_gd_image( $layer );
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be prepared for rotation.', 'image-watermark' ) );
				return false;
			}
			$rotated = $this->rotate_gd_layer( $layer, $rotation );
			$this->destroy_gd_image( $layer );
			if ( ! $this->is_gd_image( $rotated ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark text could not be rotated.', 'image-watermark' ) );
				return false;
			}
			$actual_width = imagesx( $rotated );
			$actual_height = imagesy( $rotated );
			if ( $this->is_scaled_rotation( $options ) && ( $actual_width > $image_width || $actual_height > $image_height ) ) {
				$this->destroy_gd_image( $rotated );
				$this->set_renderer_failure( 'rotation_failed', __( 'The scaled rotated watermark text cannot fit the target image.', 'image-watermark' ) );
				return false;
			}
			list( $x, $y ) = $this->calculate_image_coordinates( $image_width, $image_height, $actual_width, $actual_height, $options );
			$merged = $this->imagecopymerge_alpha( $image, $rotated, $x, $y, 0, 0, $actual_width, $actual_height, $options['watermark_image']['transparent'] );
			$this->destroy_gd_image( $rotated );
			if ( ! $merged ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark text could not be composed.', 'image-watermark' ) );
				return false;
			}
			return $image;
		}
		return false;
	}

	/**
	 * Calculate text coordinates for Imagick.
	 *
	 * @param int $image_width Image width.
	 * @param int $image_height Image height.
	 * @param string $text The text.
	 * @param ImagickDraw $draw The draw object.
	 * @param string $position Position string.
	 * @return array [x, y]
	 */
	private function calculate_text_coordinates( $image_width, $image_height, $text, $draw, $position ) {
		$imagick = new Imagick();
		$metrics = $imagick->queryFontMetrics( $draw, $text );
		$imagick->clear();
		$imagick->destroy();

		$text_width = $metrics['textWidth'];
		$text_height = $metrics['textHeight'];

		return $this->calculate_text_coordinates_gd( $image_width, $image_height, $text_width, $text_height, $position );
	}

	/**
	 * Measure text dimensions using Imagick at a given font size.
	 *
	 * This is the shared measurement used by the unchanged zero-degree renderer,
	 * so it must stay at exactly one font-metrics query. The per-glyph ink box is
	 * returned because the same query already carries it; the extra query the
	 * rotated layer needs lives in measure_text_layer_extents_imagick().
	 *
	 * @param string $font_path
	 * @param int $size
	 * @param string $text
	 * @return array{width:float,height:float,ascent:float,descent:float,glyph:array}
	 */
	private function measure_text_imagick( $font_path, $size, $text ) {
		$metrics = $this->query_font_metrics_imagick( $font_path, $size, $text );

		return [
			'width'  => isset( $metrics['textWidth'] ) ? (float) $metrics['textWidth'] : 0.0,
			'height' => isset( $metrics['textHeight'] ) ? (float) $metrics['textHeight'] : 0.0,
			'ascent' => isset( $metrics['ascender'] ) ? (float) $metrics['ascender'] : 0.0,
			'descent' => isset( $metrics['descender'] ) ? abs( (float) $metrics['descender'] ) : 0.0,
			'glyph'  => $this->imagick_glyph_box( $metrics ),
		];
	}

	/**
	 * Resolve the transparent-layer extents a rotated text line needs.
	 *
	 * queryFontMetrics() reports boundingBox as the union of the per-glyph ink
	 * boxes, each measured from its own glyph origin, never as the extent of the
	 * complete line. Treating it as the line box clips every string longer than
	 * one glyph, so the horizontal extent always comes from textWidth and the
	 * glyph box only contributes bearing, overhang and vertical overshoot.
	 *
	 * This costs one extra font-metrics query for the trailing glyph and is
	 * therefore called only from the non-zero rotation path.
	 *
	 * @param string $font_path Resolved font path.
	 * @param int $size Font size.
	 * @param string $text Watermark text.
	 * @param array $metrics Result of measure_text_imagick() for the same input.
	 * @return array{left:float,right:float,top:float,bottom:float}
	 */
	private function measure_text_layer_extents_imagick( $font_path, $size, $text, $metrics ) {
		$glyph = isset( $metrics['glyph'] ) && is_array( $metrics['glyph'] ) ? $metrics['glyph'] : [ 'x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 0.0 ];
		$text_width = (float) $metrics['width'];

		// The trailing glyph is measured on its own so the right allowance is its
		// ink beyond its own advance rather than a whole-string approximation.
		$trailing = $this->trailing_character( $text );
		$trailing_overhang = 0.0;
		if ( $trailing !== '' && $trailing !== $text ) {
			$trailing_metrics = $this->query_font_metrics_imagick( $font_path, $size, $trailing );
			$trailing_glyph = $this->imagick_glyph_box( $trailing_metrics );
			$trailing_advance = isset( $trailing_metrics['textWidth'] ) ? (float) $trailing_metrics['textWidth'] : 0.0;
			$trailing_overhang = max( 0.0, $trailing_glyph['x2'] - $trailing_advance );
		} elseif ( $trailing !== '' ) {
			$trailing_overhang = max( 0.0, $glyph['x2'] - $text_width );
		}

		return [
			// Negative left bearing of the leading glyph.
			'left'   => max( 0.0, -$glyph['x1'] ),
			// Ink that overhangs its own advance on the trailing glyph.
			'right'  => $trailing_overhang,
			// Ascender plus any glyph overshoot above it.
			'top'    => max( (float) $metrics['ascent'], $glyph['y2'] ),
			// Descender plus any glyph overshoot below it.
			'bottom' => max( (float) $metrics['descent'], -$glyph['y1'] ),
		];
	}

	/**
	 * Query Imagick font metrics while destroying both temporary objects on every
	 * path, including a throwing query.
	 *
	 * @param string $font_path Resolved font path.
	 * @param int $size Font size.
	 * @param string $text Measured text.
	 * @return array
	 */
	private function query_font_metrics_imagick( $font_path, $size, $text ) {
		$draw = null;
		$imagick = null;

		try {
			$draw = new ImagickDraw();
			$draw->setFont( $font_path );
			$draw->setFontSize( $size );
			$imagick = new Imagick();

			return $imagick->queryFontMetrics( $draw, $text );
		} finally {
			if ( $imagick instanceof Imagick ) {
				$imagick->clear();
				$imagick->destroy();
			}

			if ( $draw instanceof ImagickDraw ) {
				$draw->clear();
				$draw->destroy();
			}
		}
	}

	/**
	 * Read the per-glyph ink box reported alongside Imagick font metrics.
	 *
	 * @param array $metrics queryFontMetrics() result.
	 * @return array{x1:float,y1:float,x2:float,y2:float}
	 */
	private function imagick_glyph_box( $metrics ) {
		$glyph = [ 'x1' => 0.0, 'y1' => 0.0, 'x2' => 0.0, 'y2' => 0.0 ];

		if ( isset( $metrics['boundingBox'] ) && is_array( $metrics['boundingBox'] ) ) {
			foreach ( [ 'x1', 'y1', 'x2', 'y2' ] as $key ) {
				if ( isset( $metrics['boundingBox'][$key] ) && is_numeric( $metrics['boundingBox'][$key] ) ) {
					$glyph[$key] = (float) $metrics['boundingBox'][$key];
				}
			}
		}

		return $glyph;
	}

	/**
	 * Return the final character of a string without requiring mbstring.
	 *
	 * @param string $text Watermark text.
	 * @return string
	 */
	private function trailing_character( $text ) {
		$text = (string) $text;

		if ( $text === '' ) {
			return '';
		}

		if ( preg_match( '/.$/us', $text, $matches ) ) {
			return $matches[0];
		}

		return substr( $text, -1 );
	}

	/**
	 * Calculate target text box dimensions according to size type settings.
	 *
	 * @param int $base_width
	 * @param int $base_height
	 * @param int $image_width
	 * @param int $image_height
	 * @param array $watermark_options
	 * @return array{0:int,1:int}
	 */
	private function calculate_text_target_dimensions( $base_width, $base_height, $image_width, $image_height, $watermark_options ) {
		$width = $base_width;
		$height = $base_height;
		$size_type = isset( $watermark_options['watermark_size_type'] ) ? (int) $watermark_options['watermark_size_type'] : 0;

		if ( $size_type === 1 ) {
			if ( ! empty( $watermark_options['absolute_width'] ) ) {
				$width = (int) $watermark_options['absolute_width'];
			}

			if ( ! empty( $watermark_options['absolute_height'] ) ) {
				$height = (int) $watermark_options['absolute_height'];
			}
		} elseif ( $size_type === 2 ) {
			$percent = isset( $watermark_options['width'] ) ? (int) $watermark_options['width'] : 100;
			$target_width = $image_width * ( $percent / 100 );
			$ratio = $base_width > 0 ? $target_width / $base_width : 1;

			$width = $target_width;
			$height = $base_height * $ratio;
		}

		$max_scale = min(
			( $width > 0 ) ? ( $image_width / $width ) : 1,
			( $height > 0 ) ? ( $image_height / $height ) : 1,
			1
		);

		$width = max( 1, (int) round( $width * $max_scale ) );
		$height = max( 1, (int) round( $height * $max_scale ) );

		return [ $width, $height ];
	}

	/**
	 * Calculate text coordinates for GD.
	 *
	 * @param int $image_width Image width.
	 * @param int $image_height Image height.
	 * @param int $text_width Text width.
	 * @param int $text_height Text height.
	 * @param string $position Position string.
	 * @return array [x, y]
	 */
	private function calculate_text_coordinates_gd( $image_width, $image_height, $text_width, $text_height, $position ) {
		$margin = 10; // Margin from edges

		switch ( $position ) {
			case 'top_left':
				$x = $margin;
				$y = $margin + $text_height;
				break;
			case 'top_center':
				$x = ( $image_width - $text_width ) / 2;
				$y = $margin + $text_height;
				break;
			case 'top_right':
				$x = $image_width - $text_width - $margin;
				$y = $margin + $text_height;
				break;
			case 'middle_left':
				$x = $margin;
				$y = ( $image_height + $text_height ) / 2;
				break;
			case 'middle_center':
				$x = ( $image_width - $text_width ) / 2;
				$y = ( $image_height + $text_height ) / 2;
				break;
			case 'middle_right':
				$x = $image_width - $text_width - $margin;
				$y = ( $image_height + $text_height ) / 2;
				break;
			case 'bottom_left':
				$x = $margin;
				$y = $image_height - $margin;
				break;
			case 'bottom_center':
				$x = ( $image_width - $text_width ) / 2;
				$y = $image_height - $margin;
				break;
			case 'bottom_right':
			default:
				$x = $image_width - $text_width - $margin;
				$y = $image_height - $margin;
				break;
		}

		return [ (int) $x, (int) $y ];
	}

	/**
	 * Creates a backup of the original image.
	 *
	 * @param array $data Attachment metadata.
	 * @param array $upload_dir Upload directory info from wp_upload_dir().
	 * @param int $attachment_id Attachment post ID.
	 * @return array Array with 'success' (bool), 'error' (string|null), 'path' (string|null).
	 */
	private function do_backup( $data, $upload_dir, $attachment_id, $options ) {
		if ( ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			return [
				'success' => false,
				'error'   => __( 'Invalid attachment metadata.', 'image-watermark' ),
				'path'    => null,
			];
		}

		$backup_record = $this->resolve_backup_path( $data['file'], true, 'backup-root', $attachment_id );
		$source_record = $this->resolve_upload_artifact_path( $data['file'] );
		if ( $backup_record === false || $source_record === false ) {
			return [ 'success' => false, 'error' => __( 'Invalid attachment backup path.', 'image-watermark' ), 'path' => null ];
		}
		$backup_filepath = $backup_record['path'];
		$filepath = $source_record['path'];

		if ( ! is_file( $filepath ) ) {
			return [
				'success' => false,
				'error'   => __( 'Original file not found.', 'image-watermark' ),
				'path'    => null,
			];
		}

		if ( $this->is_valid_backup_file( $backup_filepath ) ) {
			$current_watermark_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
			if ( ! $this->commit_direct_backup_watermark_association( $attachment_id, $current_watermark_id ) ) {
				return [ 'success' => false, 'error' => __( 'The clean backup was retained, but its watermark association could not be committed.', 'image-watermark' ), 'path' => $backup_filepath ];
			}
			return [
				'success' => true,
				'error'   => null,
				'path'    => $backup_filepath,
			];
		}

		if ( ! $this->has_readable_image_bytes( $filepath ) ) {
			return [
				'success' => false,
				'error'   => __( 'Could not read original image file.', 'image-watermark' ),
				'path'    => null,
			];
		}

		$current_watermark_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
		// Check if folder is writable.
		if ( ! wp_is_writable( $backup_record['parent'] ) ) {
				return [
					'success' => false,
					/* translators: 1: Backup folder path. */
					'error'   => sprintf( __( 'Backup folder is not writable: %s', 'image-watermark' ), $backup_record['parent'] ),
					'path'    => null,
				];
			}

			// Copy the original file bit-for-bit after validating it is a decodable image.
		if ( ! $this->copy_file( $filepath, $backup_filepath, 'backup', $attachment_id, $options ) || ! $this->has_readable_image_bytes( $backup_filepath ) ) {
				return [
					'success' => false,
					'error'   => __( 'Failed to copy original file to backup location.', 'image-watermark' ),
					'path'    => null,
				];
			}

		if ( ! $this->commit_direct_backup_watermark_association( $attachment_id, $current_watermark_id ) ) {
			return [
				'success' => false,
				'error'   => __( 'The clean backup was retained, but its watermark association could not be committed.', 'image-watermark' ),
				'path'    => $backup_filepath,
			];
		}

			return [
				'success' => true,
				'error'   => null,
				'path'    => $backup_filepath,
			];
	}

	/**
	 * Copy a file, optionally preserving timestamps.
	 *
	 * @param string $source Source file path.
	 * @param string $destination Destination file path.
	 * @return bool True on success, false on failure.
	 */
	private function copy_file( $source, $destination, $context = '', $attachment_id = 0, $options = null, $exclusive = false, &$collision = null, &$created = null ) {
		$injected_result = apply_filters( 'iw_watermark_filesystem_copy', null, $source, $destination, $context, $attachment_id );

		if ( null !== $injected_result ) {
			return (bool) $injected_result;
		}

		if ( ! is_array( $options ) ) {
			$options = $this->plugin->options;
		}

		$preserve = ! empty( $options['backup']['preserve_timestamps'] );
		$preserve = apply_filters( 'iw_preserve_backup_timestamps', $preserve, $source, $destination, $context, $attachment_id );

		if ( $exclusive ) {
			return $this->copy_generation_source_exclusively( $source, $destination, $preserve, $collision, $created );
		}

		if ( $preserve ) {
			return $this->copy_with_timestamps( $source, $destination );
		}

		return copy( $source, $destination );
	}

	/**
	 * Copy a generation source without replacing an occupied destination.
	 * This does not establish ownership for later pathname cleanup or touch.
	 *
	 * @return bool
	 */
	private function copy_generation_source_exclusively( $source, $destination, $preserve, &$collision = null, &$created = null ) {
		if ( ! is_file( $source ) ) {
			return false;
		}
		$mtime = $preserve ? filemtime( $source ) : false;
		$atime = $preserve ? fileatime( $source ) : false;
		$input = @fopen( $source, 'rb' );
		if ( $input === false ) {
			return false;
		}
		$output = @fopen( $destination, 'xb' );
		if ( $output === false ) {
			fclose( $input );
			clearstatcache( true, $destination );
			$collision = file_exists( $destination ) || is_link( $destination );
			return false;
		}
		try {
			$before = fstat( $input );
			$copied = stream_copy_to_stream( $input, $output );
			$after = fstat( $input );
			$flushed = fflush( $output );
		} finally {
			$input_closed = fclose( $input );
			$output_closed = fclose( $output );
		}
		if ( $before === false || $after === false || $copied === false || $copied !== $before['size'] || $copied !== $after['size'] || ! $flushed || ! $input_closed || ! $output_closed ) {
			// The name may have been replaced: never unlink it here.
			return false;
		}
		$created = true;
		if ( $preserve && $mtime !== false ) {
			$atime = $atime !== false ? $atime : $mtime;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Preserve the existing copy timestamp behavior.
			if ( @touch( $destination, $mtime, $atime ) === false ) {
				$this->maybe_add_timestamp_notice();
			}
		}
		return true;
	}

	/**
	 * Create a directory through a controllable filesystem boundary.
	 *
	 * A null filter value preserves normal production behavior. Tests can return
	 * false to exercise an otherwise host-dependent mkdir failure.
	 *
	 * @param string $directory Directory to create.
	 * @param string $context Operation context.
	 * @param int    $attachment_id Attachment ID.
	 * @return bool
	 */
	private function make_directory( $directory, $context = '', $attachment_id = 0 ) {
		$injected_result = apply_filters( 'iw_watermark_filesystem_mkdir', null, $directory, $context, $attachment_id );

		if ( null !== $injected_result ) {
			return (bool) $injected_result;
		}

		return wp_mkdir_p( $directory );
	}

	/**
	 * Copy file preserving original timestamps.
	 *
	 * @param string $source Source file path.
	 * @param string $destination Destination file path.
	 * @return bool True on success, false on failure.
	 */
	private function copy_with_timestamps( $source, $destination ) {
		if ( ! is_file( $source ) ) {
			return false;
		}

		$mtime = filemtime( $source );
		$atime = fileatime( $source );

		if ( ! copy( $source, $destination ) ) {
			return false;
		}

		if ( $mtime !== false ) {
			$atime = ( $atime !== false ) ? $atime : $mtime;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Preserve backup timestamps after a verified byte copy.
			if ( @touch( $destination, $mtime, $atime ) === false ) {
				$this->maybe_add_timestamp_notice();
			}
		}

		return true;
	}

	/**
	 * Adds an admin notice when timestamp preservation fails.
	 *
	 * @return void
	 */
	private function maybe_add_timestamp_notice() {
		if ( $this->timestamp_preserve_notice_added ) {
			return;
		}

		$this->timestamp_preserve_notice_added = true;
		$this->add_admin_notice(
			__( 'Image Watermark: File timestamps could not be preserved on this server. Copies were created, but dates may differ.', 'image-watermark' ),
			'warning'
		);
	}

	/**
	 * Returns image resource based on mime type.
	 *
	 * @param string $filepath
	 * @param string $mime_type
	 *
	 * @return resource|false
	 */
	private function get_image_resource( $filepath, $mime_type ) {
		$injected_image = apply_filters( 'iw_watermark_image_decoder', null, $filepath, $mime_type );

		if ( null !== $injected_image ) {
			$image = $injected_image;
		} else {
			switch ( $mime_type ) {
				case 'image/jpeg':
				case 'image/pjpeg':
					$image = $this->gd_function_available( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $filepath ) : false;
					break;

				case 'image/png':
					$image = $this->gd_function_available( 'imagecreatefrompng' ) ? @imagecreatefrompng( $filepath ) : false;
					break;

				case 'image/webp':
					$image = $this->gd_function_available( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $filepath ) : false;
					break;

				default:
					$image = false;
			}
		}

		if ( ! $this->is_gd_image( $image ) || ! $this->gd_functions_available( [ 'imagealphablending', 'imagesavealpha' ] ) ) {
			return false;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		return $image;
	}

	/**
	 * Determine whether a GD handle is valid on both supported runtime forms.
	 *
	 * @param mixed $image GD resource on PHP 7 or GdImage object on PHP 8+.
	 * @return bool
	 */
	private function is_gd_image( $image ) {
		return ( is_resource( $image ) && get_resource_type( $image ) === 'gd' ) || ( class_exists( 'GdImage', false ) && $image instanceof \GdImage );
	}

	/**
	 * Allow tests to model a server that lacks a specific optional GD function.
	 *
	 * @param string $function_name GD function name.
	 * @return bool
	 */
	private function gd_function_available( $function_name ) {
		return (bool) apply_filters( 'iw_watermark_gd_function_available', function_exists( $function_name ), $function_name );
	}

	/**
	 * Check a single Imagick method. Builds vary in which layer methods they
	 * expose. Unavailability is modelled through the existing test-only operation
	 * fault injector rather than a new public filter, so this adds no extension
	 * surface.
	 *
	 * @param string $method_name Imagick method name.
	 * @return bool
	 */
	private function imagick_method_available( $method_name ) {
		if ( $this->should_inject_operation_fault( 'imagick_method_unavailable', [ 'method' => $method_name ] ) ) {
			return false;
		}

		return class_exists( 'Imagick', false ) && method_exists( 'Imagick', $method_name );
	}

	/**
	 * Check all functions required by a GD operation.
	 *
	 * @param array $function_names GD function names.
	 * @return bool
	 */
	private function gd_functions_available( $function_names ) {
		foreach ( $function_names as $function_name ) {
			if ( ! $this->gd_function_available( $function_name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Destroy a valid GD handle without requiring a PHP 8-specific API.
	 *
	 * @param mixed $image GD image resource/object.
	 * @return void
	 */
	private function destroy_gd_image( $image ) {
		if ( $this->is_gd_image( $image ) && $this->gd_function_available( 'imagedestroy' ) ) {
			imagedestroy( $image );
		}
	}

	/**
	 * Reject impossible dimensions before an allocation or pixel loop.
	 *
	 * @param mixed $width Width.
	 * @param mixed $height Height.
	 * @return bool
	 */
	private function has_valid_gd_dimensions( $width, $height ) {
		if ( ! is_numeric( $width ) || ! is_numeric( $height ) ) {
			return false;
		}

		$width = (float) $width;
		$height = (float) $height;

		return $width > 0 && $height > 0 && floor( $width ) === $width && floor( $height ) === $height && $width <= 65535 && $height <= 65535 && ( $width * $height ) <= 100000000;
	}

	/**
	 * Returns filename without directory structure.
	 *
	 * @param string $filepath
	 *
	 * @return string
	 */
	private function get_image_filename( $filepath ) {
		return basename( $filepath );
	}

	/**
	 * Returns backup folder path for an attachment.
	 *
	 * @param string $filepath
	 *
	 * @return string|false
	 */
	private function get_image_backup_folder_location( $filepath ) {
		$resolved = $this->resolve_backup_path( $filepath, true, 'backup-root' );
		return $resolved === false ? false : $resolved['parent'];
	}

	/**
	 * Returns backup file path for an attachment.
	 *
	 * @param string $filepath
	 *
	 * @return string|false
	 */
	public function get_image_backup_filepath( $filepath ) {
		$resolved = $this->resolve_backup_path( $filepath );
		return $resolved === false ? false : $resolved['path'];
	}

	/**
	 * Check backup availability for UI controls without decoding pixel data.
	 *
	 * This is only a display check. Operations must use is_valid_backup_file()
	 * to verify that the backup can be decoded before using it.
	 *
	 * @param string|false $filepath Canonically resolved backup path.
	 * @return bool
	 */
	public function is_backup_available_for_display( $filepath ) {
		return is_string( $filepath ) && $this->has_readable_image_bytes( $filepath );
	}

	/**
	 * Determine whether a backup or source file has readable image bytes.
	 *
	 * @param string $filepath Image file path.
	 * @return bool
	 */
	public function is_valid_backup_file( $filepath ) {
		if ( ! $this->has_readable_image_bytes( $filepath ) ) {
			return false;
		}

		if ( $this->plugin->check_imagick() ) {
			$image = null;

			try {
				$image = new Imagick( $filepath );
				$image->getImageGeometry();
				return true;
			} catch ( Exception $e ) {
				// Fall through to GD when it is available.
			} finally {
				if ( $image instanceof Imagick ) {
					$image->clear();
					$image->destroy();
				}
			}
		}

		$mime = wp_check_filetype( $filepath );
		$image = false;

		switch ( $mime['type'] ) {
			case 'image/jpeg':
			case 'image/pjpeg':
				$image = $this->gd_function_available( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $filepath ) : false;
				break;

			case 'image/png':
				$image = $this->gd_function_available( 'imagecreatefrompng' ) ? @imagecreatefrompng( $filepath ) : false;
				break;

			case 'image/webp':
				$image = $this->gd_function_available( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $filepath ) : false;
				break;
		}

		if ( ! $this->is_gd_image( $image ) ) {
			return false;
		}

		$this->destroy_gd_image( $image );
		return true;
	}

	/**
	 * Determine whether a file contains non-empty image bytes without decoding it.
	 *
	 * @param string $filepath Image file path.
	 * @return bool
	 */
	private function has_readable_image_bytes( $filepath ) {
		return is_file( $filepath ) && is_readable( $filepath ) && filesize( $filepath ) > 0 && @getimagesize( $filepath ) !== false;
	}

	/**
	 * Adds watermark image using GD.
	 *
	 * @param resource $image
	 * @param array $options
	 * @param array $upload_dir
	 *
	 * @return bool|resource
	 */
	/** @return resource|\GdImage|false */
	private function create_transparent_gd_layer( $width, $height ) {
		if ( ! $this->has_valid_gd_dimensions( $width, $height ) || ! $this->gd_functions_available( [ 'imagecreatetruecolor', 'imagealphablending', 'imagesavealpha', 'imagecolorallocatealpha', 'imagefilledrectangle' ] ) ) {
			return false;
		}
		$layer = imagecreatetruecolor( $width, $height );
		if ( ! $this->is_gd_image( $layer ) ) {
			return false;
		}
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		$transparent = imagecolorallocatealpha( $layer, 255, 255, 255, 127 );
		if ( $transparent === false || ! imagefilledrectangle( $layer, 0, 0, $width, $height, $transparent ) ) {
			$this->destroy_gd_image( $layer );
			return false;
		}
		return $layer;
	}

	/** @return resource|\GdImage|false */
	private function rotate_gd_layer( $layer, $rotation ) {
		if ( ! $this->is_gd_image( $layer ) || ! $this->gd_functions_available( [ 'imagesx', 'imagesy', 'imagecolorallocatealpha', 'imagerotate', 'imagealphablending', 'imagesavealpha' ] ) ) {
			return false;
		}
		list( $predicted_width, $predicted_height ) = $this->calculate_rotated_layer_bounds( imagesx( $layer ), imagesy( $layer ), $rotation );
		if ( ! $this->has_valid_gd_dimensions( $predicted_width, $predicted_height ) ) {
			return false;
		}
		$transparent = imagecolorallocatealpha( $layer, 255, 255, 255, 127 );
		if ( $transparent === false ) {
			return false;
		}
		// GD is counter-clockwise positive; the public setting is clockwise.
		$rotated = imagerotate( $layer, -$rotation, $transparent );
		if ( ! $this->is_gd_image( $rotated ) ) {
			return false;
		}
		$actual_width = imagesx( $rotated );
		$actual_height = imagesy( $rotated );
		if ( ! $this->has_valid_gd_dimensions( $actual_width, $actual_height ) ) {
			$this->destroy_gd_image( $rotated );
			return false;
		}
		imagealphablending( $rotated, false );
		imagesavealpha( $rotated, true );
		return $rotated;
	}

	/** @return resource|\GdImage|false */
	private function apply_rotated_image_watermark_gd( $image, $watermark, $image_width, $image_height, $options ) {
		$rotation = $this->get_watermark_rotation( $options );
		$source_width = imagesx( $watermark );
		$source_height = imagesy( $watermark );
		list( $width, $height ) = $this->calculate_watermark_dimensions( $image_width, $image_height, $source_width, $source_height, $options );
		if ( $this->is_scaled_rotation( $options ) ) {
			list( $width, $height ) = $this->fit_scaled_rotation_dimensions( $width, $height, $image_width, $image_height, $rotation );
		}
		if ( ! $this->has_valid_gd_dimensions( $width, $height ) || ! $this->gd_functions_available( [ 'imagecopyresampled', 'imagesx', 'imagesy' ] ) ) {
			$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark dimensions are invalid.', 'image-watermark' ) );
			return false;
		}

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			list( $predicted_width, $predicted_height ) = $this->calculate_rotated_layer_bounds( $width, $height, $rotation );
			if ( ! $this->has_valid_gd_dimensions( $predicted_width, $predicted_height ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The predicted rotated watermark allocation is unsafe.', 'image-watermark' ) );
				return false;
			}
			$prepared = $this->create_transparent_gd_layer( $width, $height );
			if ( ! $this->is_gd_image( $prepared ) || ! imagecopyresampled( $prepared, $watermark, 0, 0, 0, 0, $width, $height, $source_width, $source_height ) ) {
				$this->destroy_gd_image( $prepared );
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark image could not be prepared for rotation.', 'image-watermark' ) );
				return false;
			}
			$rotated = $this->rotate_gd_layer( $prepared, $rotation );
			$this->destroy_gd_image( $prepared );
			if ( ! $this->is_gd_image( $rotated ) ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The watermark image could not be rotated.', 'image-watermark' ) );
				return false;
			}
			$actual_width = imagesx( $rotated );
			$actual_height = imagesy( $rotated );
			if ( $this->is_scaled_rotation( $options ) && ( $actual_width > $image_width || $actual_height > $image_height ) && $attempt === 0 ) {
				$factor = min( $image_width / $actual_width, $image_height / $actual_height, 1 );
				$next_width = max( 1, (int) floor( $width * $factor ) );
				$next_height = max( 1, (int) floor( $height * $factor ) );
				$this->destroy_gd_image( $rotated );
				if ( $next_width === $width && $next_height === $height ) {
					$this->set_renderer_failure( 'rotation_failed', __( 'The scaled rotated watermark cannot fit the target image.', 'image-watermark' ) );
					return false;
				}
				$width = $next_width;
				$height = $next_height;
				continue;
			}
			if ( $this->is_scaled_rotation( $options ) && ( $actual_width > $image_width || $actual_height > $image_height ) ) {
				$this->destroy_gd_image( $rotated );
				$this->set_renderer_failure( 'rotation_failed', __( 'The scaled rotated watermark cannot fit the target image.', 'image-watermark' ) );
				return false;
			}
			list( $x, $y ) = $this->calculate_image_coordinates( $image_width, $image_height, $actual_width, $actual_height, $options );
			$merged = $this->imagecopymerge_alpha( $image, $rotated, $x, $y, 0, 0, $actual_width, $actual_height, $options['watermark_image']['transparent'] );
			$this->destroy_gd_image( $rotated );
			if ( ! $merged ) {
				$this->set_renderer_failure( 'rotation_failed', __( 'The rotated watermark image could not be composed.', 'image-watermark' ) );
				return false;
			}
			return $image;
		}
		return false;
	}

	private function add_watermark_image( $image, $options, $upload_dir ) {
		if ( ! $this->is_gd_image( $image ) || ! $this->gd_functions_available( [ 'getimagesize', 'imagesx', 'imagesy', 'imageinterlace' ] ) || ! wp_attachment_is_image( $options['watermark_image']['url'] ) ) {
			return false;
		}

		$watermark_file = wp_get_attachment_metadata( $options['watermark_image']['url'], true );
		if ( ! is_array( $watermark_file ) || empty( $watermark_file['file'] ) ) {
			return false;
		}

		$url = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $watermark_file['file'];

		if ( ! is_file( $url ) ) {
			return false;
		}
		$watermark_file_info = @getimagesize( $url );
		if ( ! is_array( $watermark_file_info ) || empty( $watermark_file_info['mime'] ) || ! isset( $watermark_file_info[0], $watermark_file_info[1], $watermark_file_info[2] ) ) {
			return false;
		}

		switch ( $watermark_file_info['mime'] ) {
			case 'image/jpeg':
			case 'image/pjpeg':
				$watermark = $this->gd_function_available( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $url ) : false;
				break;

			case 'image/gif':
				$watermark = $this->gd_function_available( 'imagecreatefromgif' ) ? @imagecreatefromgif( $url ) : false;
				break;

			case 'image/png':
				$watermark = $this->gd_function_available( 'imagecreatefrompng' ) ? @imagecreatefrompng( $url ) : false;
				break;

			case 'image/webp':
				$watermark = $this->gd_function_available( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $url ) : false;
				break;

			default:
				return false;
		}

		if ( ! $this->is_gd_image( $watermark ) ) {
			return false;
		}

		$image_width = imagesx( $image );
		$image_height = imagesy( $image );
		$watermark_width = imagesx( $watermark );
		$watermark_height = imagesy( $watermark );
		if ( ! $this->has_valid_gd_dimensions( $image_width, $image_height ) || ! $this->has_valid_gd_dimensions( $watermark_width, $watermark_height ) ) {
			$this->destroy_gd_image( $watermark );
			return false;
		}

		if ( $this->get_watermark_rotation( $options ) !== 0 ) {
			$result = $this->apply_rotated_image_watermark_gd( $image, $watermark, $image_width, $image_height, $options );
			$this->destroy_gd_image( $watermark );
			if ( ! $this->is_gd_image( $result ) ) {
				return false;
			}
			if ( $options['watermark_image']['jpeg_format'] === 'progressive' ) {
				imageinterlace( $image, true );
			}
			return $result;
		}

			list( $w, $h ) = $this->calculate_watermark_dimensions( $image_width, $image_height, $watermark_width, $watermark_height, $options );
			if ( ! $this->has_valid_gd_dimensions( $w, $h ) ) {
				$this->destroy_gd_image( $watermark );
				return false;
			}
			$resized_watermark = $this->resize( $watermark, $w, $h, $watermark_file_info );
			$this->destroy_gd_image( $watermark );
			if ( $resized_watermark === false ) {
				return false;
			}

			list( $dest_x, $dest_y ) = $this->calculate_image_coordinates( $image_width, $image_height, $w, $h, $options );

			$merged = $this->imagecopymerge_alpha( $image, $resized_watermark, $dest_x, $dest_y, 0, 0, $w, $h, $options['watermark_image']['transparent'] );
			$this->destroy_gd_image( $resized_watermark );
			if ( ! $merged ) {
				return false;
			}

		if ( $options['watermark_image']['jpeg_format'] === 'progressive' ) {
			imageinterlace( $image, true );
		}

		return $image;
	}

	/**
	 * Copies image with transparency when merging.
	 *
	 * @param resource $dst_im Destination image resource.
	 * @param resource $src_im Source image resource to merge.
	 * @param int $dst_x Destination X coordinate.
	 * @param int $dst_y Destination Y coordinate.
	 * @param int $src_x Source X coordinate.
	 * @param int $src_y Source Y coordinate.
	 * @param int $src_w Source width.
	 * @param int $src_h Source height.
	 * @param int $pct Merge percentage (0-100).
	 * @return void
	 */
	private function imagecopymerge_alpha( $dst_im, $src_im, $dst_x, $dst_y, $src_x, $src_y, $src_w, $src_h, $pct ) {
		if ( ! $this->is_gd_image( $dst_im ) || ! $this->is_gd_image( $src_im ) || ! $this->has_valid_gd_dimensions( $src_w, $src_h ) || ! $this->gd_functions_available( [ 'imagecreatetruecolor', 'imagealphablending', 'imagesavealpha', 'imagecolorallocatealpha', 'imagefilledrectangle', 'imagecopy', 'imagesx', 'imagesy', 'imagecolorat', 'imagesetpixel', 'imagedestroy' ] ) ) {
			return false;
		}

		// Clamp percentage to 0-100
		$pct = max( 0, min( 100, (int) $pct ) );

		// Prepare an overlay copy with preserved alpha
		$overlay = imagecreatetruecolor( $src_w, $src_h );
		if ( ! $this->is_gd_image( $overlay ) ) {
			return false;
		}
		imagealphablending( $overlay, false );
		imagesavealpha( $overlay, true );
		$transparent = imagecolorallocatealpha( $overlay, 255, 255, 255, 127 );
		imagefilledrectangle( $overlay, 0, 0, $src_w, $src_h, $transparent );

		// Copy the watermark region into the overlay while keeping its alpha
		imagecopy( $overlay, $src_im, 0, 0, $src_x, $src_y, $src_w, $src_h );

		// If opacity is below 100%, adjust alpha per pixel to preserve transparency.
		if ( $pct < 100 ) {
			$width = imagesx( $overlay );
			$height = imagesy( $overlay );
			$color_cache = [];

			for ( $x = 0; $x < $width; $x++ ) {
				for ( $y = 0; $y < $height; $y++ ) {
					$rgba = imagecolorat( $overlay, $x, $y );
					$alpha = ( $rgba & 0x7F000000 ) >> 24;

					if ( $alpha === 127 ) {
						continue;
					}

					$r = ( $rgba >> 16 ) & 0xFF;
					$g = ( $rgba >> 8 ) & 0xFF;
					$b = $rgba & 0xFF;
					$new_alpha = (int) round( 127 - ( ( 127 - $alpha ) * $pct / 100 ) );
					$cache_key = $r . ',' . $g . ',' . $b . ',' . $new_alpha;

					if ( ! isset( $color_cache[ $cache_key ] ) && count( $color_cache ) < 512 ) {
						$color_cache[ $cache_key ] = imagecolorallocatealpha( $overlay, $r, $g, $b, $new_alpha );
					}

					$color = isset( $color_cache[ $cache_key ] ) ? $color_cache[ $cache_key ] : imagecolorallocatealpha( $overlay, $r, $g, $b, $new_alpha );
					imagesetpixel( $overlay, $x, $y, $color );
				}
			}
		}

		// Blend onto the destination using the overlay's alpha channel
		imagealphablending( $dst_im, true );
		imagecopy( $dst_im, $overlay, $dst_x, $dst_y, 0, 0, $src_w, $src_h );
		imagealphablending( $dst_im, false );
		imagesavealpha( $dst_im, true );
		$this->destroy_gd_image( $overlay );

		return true;
	}

	/**
	 * Resizes a watermark resource.
	 *
	 * @param resource $image Source image resource.
	 * @param int $width Target width.
	 * @param int $height Target height.
	 * @param array $info Array returned by getimagesize() for the source image.
	 * @return resource New image resource on success.
	 */
	private function resize( $image, $width, $height, $info ) {
		if ( ! $this->is_gd_image( $image ) || ! $this->has_valid_gd_dimensions( $width, $height ) || ! is_array( $info ) || ! isset( $info[0], $info[1], $info[2] ) || ! $this->has_valid_gd_dimensions( $info[0], $info[1] ) || ! $this->gd_functions_available( [ 'imagecreatetruecolor', 'imagealphablending', 'imagesavealpha', 'imagefilledrectangle', 'imagecolorallocatealpha', 'imagecopyresampled' ] ) ) {
			return false;
		}

		$new_image = imagecreatetruecolor( $width, $height );
		if ( ! $this->is_gd_image( $new_image ) ) {
			return false;
		}

		// PNG (3) and WebP (18) need transparent background
		if ( $info[2] === 3 || $info[2] === 18 ) {
			imagealphablending( $new_image, false );
			imagesavealpha( $new_image, true );
			imagefilledrectangle( $new_image, 0, 0, $width, $height, imagecolorallocatealpha( $new_image, 255, 255, 255, 127 ) );
		}

		if ( ! imagecopyresampled( $new_image, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1] ) ) {
			$this->destroy_gd_image( $new_image );
			return false;
		}

		return $new_image;
	}

	/**
	 * Writes an image resource to a file.
	 *
	 * @param resource $image Image resource to write.
	 * @param string $mime_type MIME type for the output image.
	 * @param string $filepath Destination filesystem path.
	 * @param int $quality Quality parameter (0-100) for lossy formats.
	 * @return bool True on success, false on failure or unsupported MIME type.
	 */
	private function save_image_file( $image, $mime_type, $filepath, $quality ) {
		if ( ! $this->is_gd_image( $image ) || ! is_string( $filepath ) || $filepath === '' ) {
			return false;
		}

		switch ( $mime_type ) {
			case 'image/jpeg':
			case 'image/pjpeg':
				return $this->gd_function_available( 'imagejpeg' ) && imagejpeg( $image, $filepath, $quality );

			case 'image/png':
				return $this->gd_function_available( 'imagepng' ) && imagepng( $image, $filepath, (int) round( 9 - ( 9 * $quality / 100 ), 0 ) );

			case 'image/webp':
				return $this->gd_function_available( 'imagewebp' ) && imagewebp( $image, $filepath, $quality );
		}

		return false;
	}

	/** @return int Normalized public clockwise angle for a safe runtime snapshot. */
	private function get_watermark_rotation( $options ) {
		$options = $this->normalize_rotation_options( $options );
		return $options['watermark_image']['rotation'];
	}

	/**
	 * Compute the conservative integer allocation footprint for a centered
	 * clockwise rotation. Final placement deliberately uses engine dimensions.
	 *
	 * @return array{0:int,1:int}
	 */
	private function calculate_rotated_layer_bounds( $width, $height, $rotation ) {
		if ( ! is_numeric( $width ) || ! is_numeric( $height ) || $width <= 0 || $height <= 0 ) {
			return [ 0, 0 ];
		}
		$rotation = (int) $rotation;
		if ( $rotation === 0 ) {
			return [ (int) $width, (int) $height ];
		}
		$radians = deg2rad( $rotation );
		$cosine  = abs( cos( $radians ) );
		$sine    = abs( sin( $radians ) );
		$cosine  = $cosine < 0.0000000001 ? 0 : $cosine;
		$sine    = $sine < 0.0000000001 ? 0 : $sine;
		return [
			(int) ceil( abs( $width ) * $cosine + abs( $height ) * $sine ),
			(int) ceil( abs( $width ) * $sine + abs( $height ) * $cosine ),
		];
	}

	/**
	 * Shrink a prepared Scaled-mode layer so its predicted rotated footprint is
	 * contained by the target. Original and Custom callers do not invoke this.
	 *
	 * @return array{0:int,1:int}
	 */
	private function fit_scaled_rotation_dimensions( $width, $height, $target_width, $target_height, $rotation ) {
		list( $rotated_width, $rotated_height ) = $this->calculate_rotated_layer_bounds( $width, $height, $rotation );
		if ( ! $this->has_valid_gd_dimensions( $width, $height ) || ! $this->has_valid_gd_dimensions( $target_width, $target_height ) || ! $this->has_valid_gd_dimensions( $rotated_width, $rotated_height ) ) {
			return [ 0, 0 ];
		}
		$factor = min( $target_width / $rotated_width, $target_height / $rotated_height, 1 );
		return [
			max( 1, (int) floor( $width * $factor ) ),
			max( 1, (int) floor( $height * $factor ) ),
		];
	}

	/** @return bool */
	private function is_scaled_rotation( $options ) {
		return isset( $options['watermark_image']['watermark_size_type'] ) && (int) $options['watermark_image']['watermark_size_type'] === 2 && $this->get_watermark_rotation( $options ) !== 0;
	}

	/** @return void */
	private function set_renderer_failure( $code, $message ) {
		$this->last_renderer_failure = [
			'code'    => sanitize_key( $code ),
			'message' => (string) $message,
		];
	}

	/**
	 * Calculates watermark dimensions based on settings.
	 *
	 * @param int $image_width Width of the target image.
	 * @param int $image_height Height of the target image.
	 * @param int $watermark_width Original watermark width.
	 * @param int $watermark_height Original watermark height.
	 * @param array $options Plugin options influencing size calculation.
	 * @return int[] Array containing [width, height] for the watermark.
	 */
	private function calculate_watermark_dimensions( $image_width, $image_height, $watermark_width, $watermark_height, $options ) {
		if ( ! $this->has_valid_gd_dimensions( $image_width, $image_height ) || ! $this->has_valid_gd_dimensions( $watermark_width, $watermark_height ) ) {
			return [ 0, 0 ];
		}

		if ( $options['watermark_image']['watermark_size_type'] === 1 ) {
			$width = (int) $options['watermark_image']['absolute_width'];
			$height = (int) $options['watermark_image']['absolute_height'];
		} elseif ( $options['watermark_image']['watermark_size_type'] === 2 ) {
			if ( $watermark_width <= 0 ) {
				return [ 0, 0 ];
			}
			$ratio = $image_width * $options['watermark_image']['width'] / 100 / $watermark_width;
			$width = (int) ( $watermark_width * $ratio );
			$height = (int) ( $watermark_height * $ratio );

			if ( $height > $image_height ) {
				$width = (int) ( $image_height * $width / $height );
				$height = $image_height;
			}
		} else {
			$width = $watermark_width;
			$height = $watermark_height;
		}

		return [ $width, $height ];
	}

	/**
	 * Calculates watermark coordinates based on alignment and offsets.
	 *
	 * @param int $image_width Width of the target image.
	 * @param int $image_height Height of the target image.
	 * @param int $watermark_width Calculated watermark width.
	 * @param int $watermark_height Calculated watermark height.
	 * @param array $options Plugin options influencing position and offsets.
	 * @return int[] Array containing [x, y] destination coordinates.
	 */
	private function calculate_image_coordinates( $image_width, $image_height, $watermark_width, $watermark_height, $options ) {
		$position = isset( $options['watermark_image']['position'] ) ? $options['watermark_image']['position'] : 'bottom_right';

		switch ( $position ) {
			case 'top_left':
				$dest_x = $dest_y = 0;
				break;

			case 'top_center':
				$dest_x = (int) round( ( $image_width / 2 ) - ( $watermark_width / 2 ), 0 );
				$dest_y = 0;
				break;

			case 'top_right':
				$dest_x = $image_width - $watermark_width;
				$dest_y = 0;
				break;

			case 'middle_left':
				$dest_x = 0;
				$dest_y = (int) round( ( $image_height / 2 ) - ( $watermark_height / 2 ), 0 );
				break;

			case 'middle_right':
				$dest_x = $image_width - $watermark_width;
				$dest_y = (int) round( ( $image_height / 2 ) - ( $watermark_height / 2 ), 0 );
				break;

			case 'bottom_left':
				$dest_x = 0;
				$dest_y = $image_height - $watermark_height;
				break;

			case 'bottom_center':
				$dest_x = (int) round( ( $image_width / 2 ) - ( $watermark_width / 2 ), 0 );
				$dest_y = $image_height - $watermark_height;
				break;

			case 'bottom_right':
				$dest_x = $image_width - $watermark_width;
				$dest_y = $image_height - $watermark_height;
				break;

			case 'middle_center':
			default:
				$dest_x = (int) round( ( $image_width / 2 ) - ( $watermark_width / 2 ), 0 );
				$dest_y = (int) round( ( $image_height / 2 ) - ( $watermark_height / 2 ), 0 );
		}

		$offset_x = isset( $options['watermark_image']['offset_width'] ) ? (int) $options['watermark_image']['offset_width'] : 0;
		$offset_y = isset( $options['watermark_image']['offset_height'] ) ? (int) $options['watermark_image']['offset_height'] : 0;

		if ( $options['watermark_image']['offset_unit'] === 'pixels' ) {
			$offset_x = (int) $offset_x;
			$offset_y = (int) $offset_y;
		} else {
			$offset_x = (int) round( $image_width * $offset_x / 100, 0 );
			$offset_y = (int) round( $image_height * $offset_y / 100, 0 );
		}

		// Apply offset directionally: right/bottom positions move inward by subtracting, left/top move outward by adding.
		if ( strpos( $position, 'right' ) !== false ) {
			$dest_x -= $offset_x;
		} elseif ( strpos( $position, 'left' ) !== false ) {
			$dest_x += $offset_x;
		} else { // center
			$dest_x += $offset_x;
		}

		if ( strpos( $position, 'bottom' ) !== false ) {
			$dest_y -= $offset_y;
		} elseif ( strpos( $position, 'top' ) !== false ) {
			$dest_y += $offset_y;
		} else { // middle
			$dest_y += $offset_y;
		}

		return [ (int) $dest_x, (int) $dest_y ];
	}

	/**
	 * Persist the result of the last watermark operation on an attachment.
	 *
	 * Stores a sanitized record under _iw_last_operation meta so diagnostics
	 * and the Status tab can surface it without reading the operation log.
	 * No absolute filesystem paths are stored.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $status        'success', 'error', 'skipped', or 'warning'.
	 * @param string $code          Machine-readable result code.
	 * @param string $message       Human-readable message.
	 * @param string $context       'auto-apply', 'manual-apply', or 'manual-remove'.
	 * @param array  $processed     Size names that were successfully processed.
	 * @param array  $skipped       Size names that were skipped.
	 * @return bool Whether the summary was confirmed when an identity is present.
	 */
	private function record_last_operation( $attachment_id, $status, $code, $message, $context, $processed = [], $failed = [], $skipped = [], $outcome = '', &$operation = null, $summary_record = null ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return false;
		}

		$allowed_statuses = [ 'success', 'error', 'skipped', 'warning' ];
		$allowed_contexts = [ 'auto-apply', 'manual-apply', 'manual-remove', 'manual-regenerate', 'bulk-apply', 'bulk-remove', 'bulk-regenerate' ];

		$summary = [
			'status'  => in_array( $status, $allowed_statuses, true ) ? $status : 'error',
			'code'    => sanitize_key( $code ),
			'message' => $this->sanitize_operation_message( $message ),
			'context' => in_array( $context, $allowed_contexts, true ) ? $context : '',
			'time'    => current_time( 'timestamp' ),
			'engine'  => $this->plugin->get_extension() ?: 'none',
			'outcome' => in_array( $outcome, [ 'complete', 'partial', 'skipped', 'failed', 'interrupted' ], true ) ? $outcome : ( $status === 'success' ? 'complete' : ( $status === 'skipped' ? 'skipped' : 'failed' ) ),
			'sizes'   => [
				'processed' => $this->normalize_operation_sizes( [ 'processed' => $processed ] )['processed'],
				'failed'    => $this->normalize_operation_sizes( [ 'failed' => $failed ] )['failed'],
				'skipped'   => $this->normalize_operation_sizes( [ 'skipped' => $skipped ] )['skipped'],
			],
		];
		$record = is_array( $summary_record ) ? $summary_record : ( is_array( $operation ) && isset( $operation['journal']['current'] ) && is_array( $operation['journal']['current'] ) ? $operation['journal']['current'] : null );
		if ( is_array( $summary_record ) && ( ! is_array( $operation ) || ! isset( $operation['journal'] ) || ( ( ! isset( $operation['journal']['current'] ) || $summary_record !== $operation['journal']['current'] ) && ( ! isset( $operation['journal']['recoverable_predecessor'] ) || $summary_record !== $operation['journal']['recoverable_predecessor'] ) ) ) ) { return false; }
		$type = $this->operation_from_context( $summary['context'] );
		if ( $record && isset( $record['type'] ) && in_array( $record['type'], [ 'apply', 'remove', 'regenerate' ], true ) ) { $type = $record['type']; }
		$summary['operation'] = $type;
		$retryable = $this->classify_operation_retryability( $summary['outcome'], $summary['code'] );
		if ( $retryable !== null ) { $summary['retryable'] = $retryable; }
		$identity = $record && isset( $record['run_identity'] ) ? $this->normalize_operation_identity( $record['run_identity'] ) : [];
		if ( $identity === false ) { return false; }
		if ( $identity ) {
			if ( ! $this->operation_is_terminal( $record ) || $record['state'] !== $summary['outcome'] || $retryable === null || ! isset( $record['code'] ) || ( $record['code'] !== $summary['code'] && ! ( $summary['code'] === 'failed_after_mutation' && $this->operation_record_mutated_derivatives( $record, true ) ) && ! ( $record['state'] === 'complete' && $record['code'] === 'manifest_unverified' && $summary['code'] === 'watermarked' ) ) ) { return false; }
			$summary += $identity;
		}
		$projection = [ 'operation' => $summary['operation'] ];
		foreach ( [ 'retryable', 'run', 'attempt' ] as $key ) { if ( array_key_exists( $key, $summary ) ) { $projection[ $key ] = $summary[ $key ]; } }
		if ( strlen( serialize( $projection ) ) > 256 || ( $identity && ! $this->begin_prefix_confirmed( $operation ) ) ) { return false; }
		update_post_meta( $attachment_id, '_iw_last_operation', $summary );
		if ( $identity ) {
			wp_cache_delete( $attachment_id, 'post_meta' );
			return $this->read_contract_summary_row( $attachment_id ) === $summary && $this->begin_prefix_confirmed( $operation );
		}
		return true;
	}

	/** @return bool */
	private function delete_attachment_journal_artifacts( &$operation, $journal ) {
		$records = [ isset( $journal['current'] ) ? $journal['current'] : null, isset( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null ];
		if ( ! empty( $journal['history'] ) && is_array( $journal['history'] ) ) {
			$records = array_merge( $records, $journal['history'] );
		}
		$clean = true;
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['token'] ) || ! is_string( $record['token'] ) ) {
				continue;
			}
			if ( ! $this->cleanup_main_recovery( $operation, $record ) ) { $clean = false; }
			$targets = ! empty( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [];
			if ( ! empty( $record['restoration'] ) && is_array( $record['restoration'] ) ) {
				$targets[] = $record['restoration'];
			}
			if ( ! empty( $record['temporary_recovery_restore'] ) && is_array( $record['temporary_recovery_restore'] ) ) {
				$targets[] = $record['temporary_recovery_restore'];
			}
			$targets = array_merge( $targets, $this->name_stable_remap_artifacts( $record ) );
			if ( ! $this->cleanup_generation_stage( $operation, $record ) ) {
				$clean = false;
			}
			foreach ( $targets as $target ) {
				if ( ! is_array( $target ) || empty( $target['stage'] ) ) {
					continue;
				}
				if ( isset( $record['generation_stage'] ) && is_array( $record['generation_stage'] ) && array_key_exists( 'pre_generation_retention', $record['generation_stage'] ) ) {
					$clean = false;
					continue;
				}
				if ( empty( $target['root'] ) || $target['root'] !== 'uploads' || empty( $target['target'] ) || ! is_string( $target['stage'] ) || strpos( basename( $target['stage'] ), '.iw-stage-' . $record['token'] . '-' ) !== 0 ) {
					$clean = false;
					continue;
				}
				$target_path = $this->resolve_upload_artifact_path( $target['target'] );
				$stage = $this->resolve_upload_artifact_path( $target['stage'] );
				if ( $target_path === false || $stage === false || $stage['relative'] !== $target['stage'] || $target_path['relative'] !== $target['target'] || $stage['parent'] !== $target_path['parent'] ) {
					$clean = false;
					continue;
				}
				if ( is_file( $stage['path'] ) && $this->predecessor_stage_may_be_referenced( $target['stage'] ) ) {
					$clean = false;
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletion removes only a token-owned, journal-proven stage after revoking the prior owner lease.
				if ( is_file( $stage['path'] ) && ( ! $this->renew_operation_lock( $operation ) || ( $stage = $this->resolve_upload_artifact_path( $target['stage'] ) ) === false || ! @unlink( $stage['path'] ) ) ) {
					$clean = false;
				}
			}
			foreach ( $targets as $target ) {
				if ( ! is_array( $target ) || empty( $target['recovery'] ) || ! is_array( $target['recovery'] ) ) {
					continue;
				}
				$recovery = $target['recovery'];
				if ( empty( $target['target'] ) || empty( $recovery['root'] ) || $recovery['root'] !== 'uploads' || empty( $recovery['path'] ) || ! is_string( $recovery['path'] ) || strpos( basename( $recovery['path'] ), '.iw-recovery-' . $record['token'] . '-' ) !== 0 ) {
					$clean = false;
					continue;
				}
				$target_path = $this->resolve_upload_artifact_path( $target['target'] );
				$recovery_path = $this->resolve_upload_artifact_path( $recovery['path'] );
				if ( $target_path === false || $recovery_path === false || $target_path['parent'] !== $recovery_path['parent'] ) {
					$clean = false;
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deletion removes only a journal-proven token-owned temporary recovery artifact.
				if ( is_file( $recovery_path['path'] ) && ( ! $this->renew_operation_lock( $operation ) || ( $recovery_path = $this->resolve_upload_artifact_path( $recovery['path'] ) ) === false || ! @unlink( $recovery_path['path'] ) ) ) {
					$clean = false;
				}
			}
			if ( ! empty( $record['backup'] ) && is_array( $record['backup'] ) && ! empty( $record['backup']['stage'] ) ) {
				$backup = $record['backup'];
				if ( empty( $backup['root'] ) || $backup['root'] !== 'backup' || empty( $backup['path'] ) || ! is_string( $backup['stage'] ) || strpos( basename( $backup['stage'] ), '.iw-stage-' . $record['token'] . '-' ) !== 0 ) {
					$clean = false;
				} else {
					$backup_path = $this->resolve_backup_path( $backup['path'] );
					$backup_stage = $this->resolve_backup_path( $backup['stage'] );
					if ( $backup_path === false || $backup_stage === false || $backup_path['parent'] !== $backup_stage['parent'] ) {
						$clean = false;
					} elseif ( is_file( $backup_stage['path'] ) && ( ( ! empty( $record['main_recovery'] ) && $this->predecessor_stage_may_be_referenced( $backup['stage'] ) ) || ! $this->renew_operation_lock( $operation ) || ( $backup_stage = $this->resolve_backup_path( $backup['stage'] ) ) === false || ! @unlink( $backup_stage['path'] ) ) ) {
						$clean = false;
					}
				}
			}
		}
		return $clean;
	}

	/**
	 * Fence an attachment deletion before WordPress removes attachment metadata.
	 * It never walks a backup tree and leaves malformed journal evidence alone.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function delete_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}
		$fence = ! empty( $this->attachment_deletion_fences[ $attachment_id ] ) ? $this->attachment_deletion_fences[ $attachment_id ] : $this->acquire_attachment_deletion_fence( $attachment_id );
		if ( $fence === false ) {
			return;
		}
		$this->attachment_deletion_fences[ $attachment_id ] = $fence;
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $fence['token'], 'lock' => $fence ];
		$journal = $this->read_operation_journal( $attachment_id );
		if ( $journal !== false ) {
			$this->delete_attachment_journal_artifacts( $operation, $journal );
		}
		$this->delete_client_side_orphans_with_attachment( $attachment_id );

		$filepath = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$backup = $this->resolve_backup_path( $filepath );
		if ( $backup === false || ! is_file( $backup['path'] ) ) {
			return;
		}
		if ( ! $this->renew_operation_lock( $operation ) || ( $backup = $this->resolve_backup_path( $backup['relative'] ) ) === false ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete exactly one canonical mapped backup while holding the attachment deletion fence.
		@unlink( $backup['path'] );
	}
}
