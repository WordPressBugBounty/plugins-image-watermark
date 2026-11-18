<?php
/** Private AJAX transport for browser-driven runs. */

class Image_Watermark_Runner_Controller {
	/** Register only the implemented lifecycle endpoints. */
	public function __construct() {
		foreach ( [ 'handoff_create', 'preview', 'start', 'claim', 'execute', 'control', 'status' ] as $action ) {
			add_action( 'wp_ajax_iw_run_' . $action, [ $this, 'dispatch' ] );
		}
	}

	/** Validate the wire form before any runner-state observation. */
	public function dispatch() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'code' => 'iw_runner_forbidden' ] ); }
		$input = wp_unslash( $_POST );
		if ( ! isset( $input['_iw_runner_nonce'] ) || ! is_string( $input['_iw_runner_nonce'] ) || ! wp_verify_nonce( $input['_iw_runner_nonce'], 'iw_runner' ) ) { wp_send_json_error( [ 'code' => 'iw_runner_bad_nonce' ] ); }
		if ( ! isset( $_SERVER['REQUEST_METHOD'], $_SERVER['CONTENT_TYPE'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' || ! preg_match( '/^application\/x-www-form-urlencoded(?:\s*;|$)/i', $_SERVER['CONTENT_TYPE'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		$action = substr( current_filter(), strlen( 'wp_ajax_iw_run_' ) );
		foreach ( [ 'operation', 'ids', 'target', 'handoff', 'scan_depth', 'retry_of', 'run', 'fence', 'attempt', 'command', 'page' ] as $key ) {
			if ( array_key_exists( $key, $input ) && ! is_string( $input[ $key ] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		}
		foreach ( [ 'run', 'retry_of' ] as $key ) {
			if ( isset( $input[ $key ] ) && ( $input[ $key ] === '' || strlen( $input[ $key ] ) > 64 || preg_match( '/[^\x00-\x7f]/', $input[ $key ] ) ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		}
		if ( isset( $input['fence'] ) && ! preg_match( '/^[a-f0-9]{32}$/D', $input['fence'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		foreach ( [ 'attempt', 'page' ] as $key ) {
			if ( isset( $input[ $key ] ) && ( ! preg_match( '/^[1-9][0-9]*$/D', $input[ $key ] ) || strlen( $input[ $key ] ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $input[ $key ] ) === strlen( (string) PHP_INT_MAX ) && strcmp( $input[ $key ], (string) PHP_INT_MAX ) > 0 ) ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		}
		if ( $action === 'handoff_create' ) { $this->create_handoff( $input ); }
		$store = new Image_Watermark_Run_Store();
		if ( $action === 'preview' || $action === 'start' ) {
			$this->start_or_preview( $store, $action, $input );
		}
		if ( $action === 'status' ) {
			$this->send_status( Image_Watermark_Runner::status( $store, isset( $input['run'] ) ? $input['run'] : '', isset( $input['page'] ) ? (int) $input['page'] : 1 ) );
		}
		if ( $action === 'control' && isset( $input['command'] ) && in_array( $input['command'], [ 'stop_malformed', 'reset_history' ], true ) ) {
			if ( isset( $input['run'] ) || isset( $input['fence'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
			$name = $input['command'] === 'stop_malformed' ? Image_Watermark_Run_Store::ACTIVE : Image_Watermark_Run_Store::HISTORY;
			$row = $store->read_row( $name );
			if ( $row['state'] === 'error' ) { wp_send_json_error( [ 'code' => 'iw_run_storage_failed' ] ); }
			if ( $row['state'] !== 'malformed' ) { wp_send_json_error( [ 'code' => 'iw_run_state_conflict' ] ); }
			$deleted = $store->delete_exact( $row );
			if ( $deleted === 'error' ) { wp_send_json_error( [ 'code' => 'iw_run_storage_failed' ] ); }
			if ( $deleted !== 'deleted' ) { wp_send_json_error( [ 'code' => $name === Image_Watermark_Run_Store::ACTIVE ? 'iw_run_active_malformed' : 'iw_run_history_malformed' ] ); }
			wp_send_json_success( [ 'code' => 'ok' ] );
		}
		if ( ! isset( $input['run'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		if ( $action === 'control' && isset( $input['command'] ) && $input['command'] === 'takeover' ) {
			if ( isset( $input['fence'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
			$input['fence'] = '';
		}
		elseif ( ! isset( $input['fence'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		if ( $action === 'claim' ) {
			$result = Image_Watermark_Runner::claim( $store, $input['run'], $input['fence'] );
		} elseif ( $action === 'execute' ) {
			if ( ! isset( $input['attempt'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
			$outcome = null;
			$result = [ 'result' => Image_Watermark_Runner::execute_claim( $store, $input['run'], $input['fence'], (int) $input['attempt'], $outcome ) ];
			if ( $result['result'] === 'admission_lost' ) { $result['result'] = 'attempt_not_claimed'; }
			if ( $result['result'] === 'stale' || $result['result'] === 'state' ) {
				$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
				if ( $row['state'] === 'valid' && $row['value']['id'] === $input['run'] && $row['value']['fence'] === $input['fence'] && ( ! is_array( $row['value']['claim'] ) || $row['value']['claim']['attempt'] !== (int) $input['attempt'] || $row['value']['claim']['state'] !== 'claimed' ) ) { $result['result'] = 'attempt_not_claimed'; }
			}
			if ( $result['result'] === 'malformed' ) {
				$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
				if ( $row['state'] === 'valid' ) { $result['result'] = 'summary_unconfirmed'; }
			}
			if ( $outcome !== null ) { $result['outcome'] = $outcome; }
		} elseif ( $action === 'control' ) {
			if ( ! isset( $input['command'] ) || ! in_array( $input['command'], [ 'heartbeat', 'pause', 'resume', 'continue_with_new_settings', 'stop', 'takeover' ], true ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
			$result = Image_Watermark_Runner::control( $store, $input['run'], $input['fence'], $input['command'] );
		} else { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		if ( isset( $result['result'] ) && in_array( $result['result'], [ 'claimed', 'updated', 'unchanged', 'running', 'paused', 'stopped', 'complete', 'progress', 'pending' ], true ) ) {
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] === 'valid' && $row['value']['id'] === $input['run'] ) { $result['run_state'] = $this->project_active( $row['value'] ); }
		}
		$this->send_result( $result );
	}

	private function create_handoff( $input ) {
		if ( ! isset( $input['operation'], $input['ids'] ) || isset( $input['target'] ) || isset( $input['handoff'] ) || isset( $input['retry_of'] ) || isset( $input['scan_depth'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		$normalized = Image_Watermark_Runner::normalize_handoff( $input['operation'], $input['ids'] );
		if ( isset( $normalized['error'] ) ) { wp_send_json_error( [ 'code' => $normalized['error'] ] ); }
		$token = wp_generate_password( 20, false, false );
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{20}$/D', $token ) ) { wp_send_json_error( [ 'code' => 'iw_run_storage_failed' ] ); }
		$key = 'iw_run_handoff_' . get_current_user_id() . '_' . $token;
		if ( get_transient( $key ) !== false || ! set_transient( $key, $normalized['value'], 900 ) || get_transient( $key ) !== $normalized['value'] ) { wp_send_json_error( [ 'code' => 'iw_run_storage_failed' ] ); }
		$url = add_query_arg( [ 'page' => 'image-watermark', 'tab' => 'bulk', 'iw_handoff' => $token ], admin_url( 'options-general.php' ) );
		wp_send_json_success( [ 'code' => 'ok', 'token' => $token, 'url' => $url ] );
	}

	private function start_or_preview( $store, $action, $input ) {
		$retry = isset( $input['retry_of'] ) ? $input['retry_of'] : '';
		$operation = isset( $input['operation'] ) ? $input['operation'] : null;
		if ( $operation !== null && ! in_array( $operation, [ 'apply', 'remove', 'scan' ], true ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		if ( isset( $input['scan_depth'] ) && ( $operation !== 'scan' || ! in_array( $input['scan_depth'], [ 'header', 'full' ], true ) ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		$has_handoff = isset( $input['handoff'] );
		if ( ( $has_handoff && ( isset( $input['target'] ) || $retry !== '' ) ) || ( $action === 'preview' && $retry !== '' ) || ( $retry === '' && ( $operation === null || ( ! isset( $input['target'] ) && ! $has_handoff ) ) ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
		$target = null;
		$handoff_key = '';
		if ( $has_handoff ) {
			if ( ! preg_match( '/^[A-Za-z0-9]{20}$/D', $input['handoff'] ) ) { wp_send_json_error( [ 'code' => 'iw_run_invalid_input' ] ); }
			$handoff_key = 'iw_run_handoff_' . get_current_user_id() . '_' . $input['handoff'];
			$handoff = get_transient( $handoff_key );
			if ( ! Image_Watermark_Runner::valid_handoff( $handoff ) ) { wp_send_json_error( [ 'code' => 'iw_run_handoff_missing' ] ); }
			if ( $handoff['operation'] !== $operation ) { wp_send_json_error( [ 'code' => 'iw_run_handoff_mismatch' ] ); }
			$target = [ 'type' => 'ids', 'ids' => $handoff['ids'] ];
		}
		if ( isset( $input['target'] ) ) {
			$normalized = Image_Watermark_Runner::normalize_target( $input['target'] );
			if ( isset( $normalized['error'] ) ) { wp_send_json_error( [ 'code' => $normalized['error'] ] ); }
			$target = $normalized['target'];
		}
		if ( $action === 'preview' ) {
			$bound = Image_Watermark_Runner::traversal_bound();
			$depth = $operation === 'scan' ? ( isset( $input['scan_depth'] ) ? $input['scan_depth'] : 'header' ) : null;
			$preview = $bound === false ? false : Image_Watermark_Runner::preview( $target, $bound, $operation, $depth );
			if ( $preview === false ) { wp_send_json_error( [ 'code' => 'iw_run_storage_failed' ] ); }
			// Estimate reports only the estimate taken now; it reads no run history (A-P04-11).
			wp_send_json_success( [ 'code' => 'ok', 'preview' => $preview ] );
		}
		$depth = $operation === 'scan' ? ( isset( $input['scan_depth'] ) ? $input['scan_depth'] : 'header' ) : null;
		$result = Image_Watermark_Runner::start( $store, $operation, $target, $retry, $depth );
		if ( isset( $result['result'] ) && $result['result'] === 'malformed' ) {
			$row = $store->read_row( $retry !== '' ? Image_Watermark_Run_Store::HISTORY : Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] !== 'malformed' ) { $row = $store->read_row( Image_Watermark_Run_Store::HISTORY ); }
			$result['code'] = isset( $row['code'] ) ? $row['code'] : 'iw_run_storage_failed';
		}
		if ( isset( $result['result'] ) && $result['result'] === 'started' ) {
			if ( $handoff_key !== '' ) { delete_transient( $handoff_key ); }
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] !== 'valid' || $row['value']['id'] !== $result['run'] ) { wp_send_json_error( [ 'code' => 'iw_run_storage_failed' ] ); }
			$result['run_state'] = $this->project_active( $row['value'] );
		}
		$this->send_result( $result );
	}

	private function project_active( $value ) {
		return array_intersect_key( $value, array_flip( [ 'id', 'operation', 'retry_of', 'target', 'cursor', 'bound', 'status', 'pause_reason', 'owner', 'heartbeat', 'created', 'updated', 'finished', 'counters', 'reasons', 'scan_depth', 'scan', 'estimated_total' ] ) );
	}

	private function send_status( $status ) {
		if ( isset( $status['state'], $status['code'] ) && $status['state'] === 'unreadable' ) { wp_send_json_error( $status ); }
		if ( isset( $status['result'] ) && $status['result'] === 'malformed' ) { wp_send_json_error( [ 'code' => 'iw_run_history_malformed' ] ); }
		if ( ! isset( $status['result'] ) || $status['result'] !== 'ok' ) { $this->send_result( $status ); }
		unset( $status['result'] );
		if ( ! isset( $status['code'] ) ) { $status['code'] = 'ok'; }
		if ( isset( $status['selected'] ) && is_array( $status['selected'] ) ) {
			$selected = $status['selected'];
			$owner = isset( $selected['owner'] ) && is_int( $selected['owner'] ) ? $selected['owner'] : 0;
			$user = $owner > 0 ? get_userdata( $owner ) : false;
			$name = $user && is_string( $user->display_name ) && $user->display_name !== '' ? $user->display_name : ( $user && is_string( $user->user_login ) ? $user->user_login : '' );
			$name = sanitize_text_field( $name );
			$status['owner_name'] = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 250 ) : substr( $name, 0, 250 );
			$status['viewer_is_owner'] = get_current_user_id() === $owner;
			// An archived R6b summary keeps no heartbeat, so the key stays absent there.
			if ( isset( $selected['heartbeat'] ) && is_int( $selected['heartbeat'] ) ) {
				$status['heartbeat_age'] = max( 0, time() - $selected['heartbeat'] );
			}
		}
		wp_send_json_success( $status );
	}

	private function send_result( $result ) {
		$state = isset( $result['result'] ) ? $result['result'] : 'error';
		$success = [ 'started', 'claimed', 'updated', 'unchanged', 'running', 'paused', 'stopped', 'complete', 'progress', 'pending' ];
		if ( in_array( $state, $success, true ) ) {
			$out = [ 'code' => isset( $result['code'] ) ? $result['code'] : 'ok', 'state' => $state ];
			foreach ( [ 'run', 'fence', 'attempt', 'attachment', 'outcome', 'run_state', 'file_name' ] as $key ) { if ( isset( $result[ $key ] ) ) { $out[ $key ] = $result[ $key ]; } }
			wp_send_json_success( $out );
		}
		$codes = [ 'packet_too_small' => 'iw_run_packet_too_small', 'error' => 'iw_run_storage_failed', 'forbidden' => 'iw_runner_forbidden', 'invalid' => 'iw_run_invalid_input', 'not_found' => 'iw_run_not_found', 'absent' => 'iw_run_not_found', 'active' => 'iw_run_active_exists', 'malformed' => 'iw_run_active_malformed', 'history_malformed' => 'iw_run_history_malformed', 'stale' => 'iw_run_fence_stale', 'state' => 'iw_run_state_conflict', 'admission_conflict' => 'iw_run_state_conflict', 'attempt_not_claimed' => 'iw_run_attempt_not_claimed', 'takeover_too_early' => 'iw_run_takeover_too_early', 'attempts_exhausted' => 'iw_run_attempts_exhausted', 'summary_unconfirmed' => 'iw_run_summary_unconfirmed', 'unclassified_outcome' => 'iw_run_unclassified_outcome', 'iw_run_item_forbidden' => 'iw_run_item_forbidden' ];
		wp_send_json_error( [ 'code' => isset( $result['code'] ) && in_array( $result['code'], $codes, true ) ? $result['code'] : ( isset( $codes[ $state ] ) ? $codes[ $state ] : 'iw_run_storage_failed' ) ] );
	}
}
