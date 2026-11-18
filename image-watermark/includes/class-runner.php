<?php
/** Internal run targets and lifecycle primitives. */

class Image_Watermark_Runner {
	/** Session packet limit, or null when it cannot be measured. */
	public static function packet_limit() {
		global $wpdb;
		$limit = $wpdb->get_var( 'SELECT @@SESSION.max_allowed_packet' );
		if ( $wpdb->last_error !== '' || ! ( ( is_string( $limit ) && ctype_digit( $limit ) ) || ( is_int( $limit ) && $limit >= 0 ) ) ) { return null; }
		return (float) $limit;
	}

	/** The settings acknowledged by this run, for each live projection. */
	private static function run_fingerprints( $run ) {
		return [ 'settings_fingerprint' => $run['settings_fingerprint'], 'sizes_fingerprint' => $run['sizes_fingerprint'] ];
	}

	/**
	 * Start a mutation or read-only scan run. Transport and handoff consumption are separate.
	 *
	 * @internal
	 */
	public static function start( $store, $operation, $target, $retry_of = '', $scan_depth = null ) {
		if ( ! current_user_can( 'manage_options' ) ) { return [ 'result' => 'forbidden' ]; }
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_string( $retry_of ) || ( $retry_of !== '' && ( strlen( $retry_of ) > 64 || preg_match( '/[^\x00-\x7f]/', $retry_of ) ) ) ) { return [ 'result' => 'invalid' ]; }
		$site = get_current_blog_id();
		$cursor = 0;
		$bound = null;
		if ( $retry_of !== '' ) {
			$history = $store->read_row( Image_Watermark_Run_Store::HISTORY );
			if ( $history['state'] !== 'valid' ) { return [ 'result' => $history['state'] === 'absent' ? 'not_found' : $history['state'] ]; }
			$original = null;
			foreach ( $history['value']['runs'] as $summary ) {
				if ( $summary['id'] === $retry_of ) { $original = $summary; break; }
			}
			if ( $original === null ) { return [ 'result' => 'not_found' ]; }
			if ( in_array( $original['operation'], [ 'scan', 'regenerate' ], true ) ) { return [ 'result' => 'invalid' ]; }
			$derived = [ 'type' => 'filters', 'filters' => [ 'problems_of_run' => $retry_of ] ];
			if ( ( $operation !== null && $operation !== $original['operation'] ) || ( $target !== null && $target !== $derived ) ) { return [ 'result' => 'invalid' ]; }
			$operation = $original['operation'];
			$target = $derived;
			$bound = $original['bound'];
			if ( $original['target']['type'] === 'ids' ) { $cursor = min( $bound, $original['target']['id_min'] - 1 ); }
		}
		if ( $operation === 'scan' && $scan_depth === null ) { $scan_depth = 'header'; }
		if ( ! in_array( $operation, [ 'apply', 'remove', 'scan' ], true ) || ! Image_Watermark_Run_Store::target( $target ) || ( $operation === 'scan' ? ! in_array( $scan_depth, [ 'header', 'full' ], true ) : $scan_depth !== null ) ) { return [ 'result' => 'invalid' ]; }
		$packet = self::packet_limit();
		if ( $packet === null ) { return [ 'result' => 'error' ]; }
		if ( $packet < 4194304 ) { return [ 'result' => 'packet_too_small' ]; }
		if ( ! $store->history_packet_ready() ) { return [ 'result' => 'error' ]; }
		$fingerprints = Image_Watermark()->get_upload_handler()->runner_fingerprints();
		if ( $fingerprints === false ) { return [ 'result' => 'error' ]; }
		if ( $bound === null ) { $bound = self::traversal_bound(); }
		if ( $bound === false || get_current_blog_id() !== $site ) { return [ 'result' => 'error' ]; }
		$estimate = null;
		$estimate_attempted = false;
		for ( $tries = 0; $tries < 5; ++$tries ) {
			if ( get_current_blog_id() !== $site ) { return [ 'result' => 'error' ]; }
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] === 'valid' ) {
				if ( ! in_array( $row['value']['status'], [ 'stopped', 'complete' ], true ) || $row['value']['claim'] !== null ) { return [ 'result' => 'active' ]; }
				$scope = self::scope_fingerprint( $row['value']['target'] );
				$retired = $store->archive_finished( $row, $scope );
				if ( $retired === 'lost' ) { continue; }
				if ( $retired !== 'deleted' ) { return [ 'result' => $retired ]; }
			} elseif ( $row['state'] !== 'absent' ) { return [ 'result' => $row['state'] ]; }
			try { $id = bin2hex( random_bytes( 16 ) ); $fence = bin2hex( random_bytes( 16 ) ); } catch ( Throwable $e ) { return [ 'result' => 'error' ]; }
			$history = $store->read_row( Image_Watermark_Run_Store::HISTORY );
			if ( ! in_array( $history['state'], [ 'absent', 'valid' ], true ) ) { return [ 'result' => $history['state'] ]; }
			$collision = false;
			if ( $history['state'] === 'valid' ) { foreach ( $history['value']['runs'] as $summary ) { if ( $summary['id'] === $id ) { $collision = true; break; } } }
			if ( $collision ) { continue; }
			if ( ! $estimate_attempted ) {
				$estimate = self::estimated_total( $target, $bound );
				$estimate_attempted = true;
				if ( get_current_blog_id() !== $site ) { return [ 'result' => 'error' ]; }
			}
			$now = time();
			$value = [ 'version' => 1, 'id' => $id, 'operation' => $operation, 'retry_of' => $retry_of, 'target' => $target, 'cursor' => $cursor, 'bound' => $bound, 'status' => 'running', 'pause_reason' => '', 'owner' => get_current_user_id(), 'fence' => $fence, 'heartbeat' => $now, 'created' => $now, 'updated' => $now, 'finished' => 0, 'next_attempt' => 1, 'claim' => null, 'settings_fingerprint' => $fingerprints['settings_fingerprint'], 'sizes_fingerprint' => $fingerprints['sizes_fingerprint'], 'counters' => array_fill_keys( [ 'complete', 'partial', 'skipped', 'failed', 'interrupted', 'unknown' ], 0 ), 'reasons' => [], 'problems' => [] ];
			if ( $estimate !== null ) { $value['estimated_total'] = $estimate; }
			if ( $operation === 'scan' ) {
				$value['scan_depth'] = $scan_depth;
				$value['scan'] = [ 'version' => 1, 'started' => $now, 'updated' => $now, 'signals' => array_fill_keys( self::scan_signal_names( $scan_depth ), 0 ) ];
			}
			$insert = $store->insert_absent( Image_Watermark_Run_Store::ACTIVE, $value );
			if ( $insert === 'exists' ) { continue; }
			if ( $insert !== 'inserted' ) { return [ 'result' => $insert ]; }
			$verified = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $verified['state'] !== 'valid' || $verified['value'] !== $value ) { return [ 'result' => 'error' ]; }
			return [ 'result' => 'started', 'run' => $id, 'fence' => $fence ];
		}
		return [ 'result' => 'error' ];
	}

	/**
	 * The claimed image's attached-file basename only. Plain reply data: never a
	 * directory, path or URL, and empty when no attached file is available.
	 *
	 * @internal
	 */
	private static function claim_file_name( $attachment ) {
		$file = get_post_meta( (int) $attachment, '_wp_attached_file', true );
		if ( ! is_string( $file ) || $file === '' ) { return ''; }
		$name = wp_basename( $file );
		if ( ! is_string( $name ) || $name === '' ) { return ''; }
		if ( strlen( $name ) > 255 ) { $name = function_exists( 'mb_strcut' ) ? mb_strcut( $name, 0, 255, 'UTF-8' ) : substr( $name, 0, 255 ); }
		// A cut must never leave half a UTF-8 sequence in JSON reply data.
		while ( $name !== '' && preg_match( '//u', $name ) !== 1 ) { $name = substr( $name, 0, -1 ); }
		return $name;
	}

	/**
	 * Drive one bounded traversal step. An existing executing claim is only
	 * reconciled; neither this method nor status resubmits an operation.
	 * Abandoned-claim recovery and leased summary repair remain separate work.
	 *
	 * @internal
	 */
	public static function claim( $store, $run, $fence ) {
		if ( ! current_user_can( 'manage_options' ) || ! $store instanceof Image_Watermark_Run_Store ) { return [ 'result' => 'forbidden' ]; }
		for ( $tries = 0; $tries < 5; ++$tries ) {
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] !== 'valid' ) { return [ 'result' => $row['state'] ]; }
			$v = $row['value'];
			if ( $v['id'] !== $run || $v['fence'] !== $fence ) { return [ 'result' => 'stale' ]; }
			if ( $v['status'] !== 'running' ) { return [ 'result' => $v['status'] ]; }
			$heartbeat = self::driver_control( $store, $row, $run, $fence, 'heartbeat' );
			if ( $heartbeat['result'] === 'lost' ) { continue; }
			if ( ! in_array( $heartbeat['result'], [ 'updated', 'unchanged' ], true ) ) { return $heartbeat; }
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] !== 'valid' ) { return [ 'result' => $row['state'] ]; }
			$v = $row['value'];
			if ( $v['id'] !== $run || $v['fence'] !== $fence ) { return [ 'result' => 'stale' ]; }
			if ( $v['status'] !== 'running' ) { return [ 'result' => $v['status'] ]; }
			if ( $v['claim'] !== null ) {
				if ( $v['claim']['state'] === 'executing' ) {
					if ( $v['operation'] === 'scan' ) { return [ 'result' => 'pending' ]; }
					$result = self::reconcile_terminal_claim( $store, $row, $run, $v['claim']['attempt'], true );
					if ( in_array( $result, [ 'updated', 'lost' ], true ) ) { continue; }
					return [ 'result' => $result ];
				}
				return [ 'result' => 'claimed', 'attachment' => $v['claim']['attachment'], 'attempt' => $v['claim']['attempt'], 'file_name' => self::claim_file_name( $v['claim']['attachment'] ) ];
			}
			// An inherited regenerate run may be observed and stopped, never given a new claim.
			if ( $v['operation'] === 'regenerate' ) { return [ 'result' => 'state' ]; }
			$gate = self::site_wide_refusal( $v );
			if ( $gate !== '' ) {
				$v['status'] = 'paused'; $v['pause_reason'] = $gate; $v['updated'] = time();
				$result = $store->swap_exact( $row, $v );
				if ( $result === 'lost' ) { continue; }
				return [ 'result' => $result === 'updated' ? 'paused' : $result ];
			}
			$result = $v['target']['type'] === 'ids' ? self::advance_missing_ids( $store, $row, $run, $fence ) : self::advance_filter_nonmembers( $store, $row, $run, $fence );
			if ( $result === 'updated' || $result === 'lost' ) { continue; }
			if ( $result !== 'member' ) { return [ 'result' => $result === 'incomplete' ? 'progress' : $result ]; }
			$page = self::traversal_page( $v['target'], $v['cursor'], $v['bound'] );
			if ( $page === false ) { return [ 'result' => 'error' ]; }
			if ( ! $page ) { continue; }
			$id = (int) key( $page );
			// The member observed by the skip pass may have disappeared; never
			// allocate its unevaluated successor as though it had matched.
			if ( $v['target']['type'] === 'filters' ) {
				$observed = self::recheck_claimed_member( $v['target'], $id, $v['bound'], true, $v['operation'], isset( $v['scan_depth'] ) ? $v['scan_depth'] : null, self::run_fingerprints( $v ) );
				if ( $observed['decision'] === 'unmeasured' ) {
					$v['status'] = 'paused'; $v['pause_reason'] = $observed['reason']; $v['updated'] = time();
					$result = $store->swap_exact( $row, $v );
					if ( $result === 'lost' ) { continue; }
					return [ 'result' => $result === 'updated' ? 'paused' : $result ];
				}
				if ( ! in_array( $observed['decision'], [ 'matches', 'forbidden' ], true ) ) { return [ 'result' => 'progress' ]; }
			}
			$gate = self::ownership_refusal( $v['operation'], $id );
			if ( $gate !== '' ) {
				$v['status'] = 'paused'; $v['pause_reason'] = $gate; $v['updated'] = time();
				$result = $store->swap_exact( $row, $v );
				if ( $result === 'lost' ) { continue; }
				return [ 'result' => $result === 'updated' ? 'paused' : $result ];
			}
			$result = self::allocate_claim( $store, $row, $run, $fence, $id );
			if ( in_array( $result, [ 'updated', 'lost', 'stale' ], true ) ) { continue; }
			return [ 'result' => $result ];
		}
		return [ 'result' => 'progress' ];
	}

	/** Bounded observation of the population visited by the ownership pass. */
	private static function ownership_ceiling() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 50001", 'attachment' ) );
		if ( $wpdb->last_error !== '' || ! is_array( $ids ) ) { return null; }
		return count( $ids ) > Image_Watermark_Upload_Handler::OWNERSHIP_ROW_CEILING;
	}

	/** Apply is affected only when the observed candidate is not a proven initial apply. */
	private static function ownership_refusal( $operation, $id ) {
		if ( $operation === 'scan' ) { return ''; }
		if ( $operation === 'apply' ) {
			global $wpdb;
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2", $id, 'iw-is-watermarked' ) );
			if ( $wpdb->last_error !== '' || ! is_array( $rows ) || count( $rows ) > 1 ) { return 'metadata_read_failed'; }
			$flag = $rows ? maybe_unserialize( $rows[0] ) : '';
			if ( ! is_scalar( $flag ) ) { return 'metadata_read_failed'; }
			if ( (int) $flag !== 1 ) { return ''; }
		}
		$exceeded = self::ownership_ceiling();
		return $exceeded === null ? 'metadata_read_failed' : ( $exceeded ? 'ownership_ceiling_exceeded' : '' );
	}

	/** Decide a site-wide refusal before traversal can consume even a missing ID. */
	private static function site_wide_refusal( $run ) {
		if ( $run['operation'] === 'scan' ) { return ''; }
		$handler = Image_Watermark()->get_upload_handler();
		$options = null;
		$fingerprints = $handler->runner_fingerprints( $options );
		if ( $fingerprints === false ) { return 'metadata_read_failed'; }
		if ( $fingerprints['settings_fingerprint'] !== $run['settings_fingerprint'] || $fingerprints['sizes_fingerprint'] !== $run['sizes_fingerprint'] ) { return 'settings_changed'; }
		try { $reason = $handler->runner_site_settings_refusal( $options, $run['operation'] ); }
		catch ( Throwable $e ) { return 'metadata_read_failed'; }
		if ( $reason === '' ) { return ''; }
		if ( $run['operation'] === 'regenerate' && $reason !== 'no_engine' && ! self::remaining_all_flagged( $run ) ) { return ''; }
		$after = $handler->runner_fingerprints();
		if ( $after === false ) { return 'metadata_read_failed'; }
		if ( $after !== $fingerprints ) { return 'settings_changed'; }
		return $reason;
	}

	/** A bounded, conservative proof; uncertain or mixed membership stays per-item. */
	private static function remaining_all_flagged( $run ) {
		$cursor = $run['cursor']; $found = false;
		$deadline = microtime( true ) + 2;
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) { $deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $maximum - 2 ); }
		while ( $cursor < $run['bound'] ) {
			if ( microtime( true ) >= $deadline ) { return false; }
			$page = self::traversal_page( $run['target'], $cursor, $run['bound'] );
			if ( $page === false ) { return false; }
			if ( ! $page ) { break; }
			$eligible = [];
			foreach ( $page as $id => $post ) {
				if ( ! $post || $post['post_type'] !== 'attachment' || ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
					if ( $run['target']['type'] === 'ids' ) { return false; }
					continue;
				}
				$eligible[ $id ] = $post;
			}
			$filters = $run['target']['type'] === 'filters' ? $run['target']['filters'] : [];
			$batch_filters = $filters; $batch_filters['watermarked'] = true;
			$batch = self::traversal_batch( $eligible, $batch_filters, $deadline, self::run_fingerprints( $run ) );
			if ( ! is_array( $batch ) ) { return false; }
			foreach ( $page as $id => $post ) {
				if ( microtime( true ) >= $deadline ) { return false; }
				$cursor = $id;
				if ( ! isset( $eligible[ $id ] ) ) { continue; }
				$match = self::traversal_batch_decision( $id, $post, $filters, $batch, 'regenerate', null, $deadline );
				if ( $match['decision'] === 'no_longer_matches' && $run['target']['type'] === 'filters' ) { continue; }
				if ( $match['decision'] !== 'matches' ) { return false; }
				$rows = isset( $batch['meta'][ $id ]['iw-is-watermarked'] ) ? $batch['meta'][ $id ]['iw-is-watermarked'] : [];
				if ( count( $rows ) !== 1 ) { return false; }
				$flag = maybe_unserialize( $rows[0] );
				if ( ! is_scalar( $flag ) || (int) $flag !== 1 ) { return false; }
				$found = true;
			}
		}
		return $found;
	}

	/** Release an abandoned scan without advancing or counting it. */
	private static function recover_abandoned_scan( $store, $row ) {
		$value = $row['value'];
		$claim = $value['claim'];
		if ( $value['operation'] !== 'scan' || ! is_array( $claim ) || $claim['state'] !== 'executing' || ( $claim['fence'] === $value['fence'] && ! in_array( $value['status'], [ 'paused', 'stopped' ], true ) ) || time() - $claim['admitted_at'] < 120 ) { return 'pending'; }
		$value['claim'] = null;
		$value['updated'] = time();
		return $store->swap_exact( $row, $value );
	}

	/** Ordinary terminal evidence comes first; only abandoned mutations can be released. */
	private static function reconcile_or_recover( $store, $row, &$disposition = null ) {
		$disposition = null;
		$value = $row['value'];
		$claim = $value['claim'];
		$result = self::reconcile_terminal_claim( $store, $row, $value['id'], $claim['attempt'], true, false );
		if ( ! in_array( $result, [ 'pending', 'summary_unconfirmed', 'unclassified_outcome' ], true ) ) { return $result; }
		$site = get_current_blog_id();
		$user = get_current_user_id();
		$authorized = static function() use ( $site, $user, $claim ) {
			return get_current_blog_id() === $site && get_current_user_id() === $user && Image_Watermark_Upload_Handler::can_recover_runner_item( $claim['attachment'] );
		};
		if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
		$allow_pause = $claim['fence'] !== $value['fence'] || in_array( $value['status'], [ 'paused', 'stopped' ], true );
		$result = self::reconcile_terminal_claim( $store, $row, $value['id'], $claim['attempt'], true, $allow_pause ? $authorized : false );
		if ( ! in_array( $result, [ 'pending', 'summary_unconfirmed', 'unclassified_outcome' ], true ) ) { return $result; }
		$latest = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
		if ( $latest['state'] === 'error' ) { return 'error'; }
		if ( $latest['state'] !== 'valid' || $latest['value']['id'] !== $value['id'] || $latest['value']['claim'] !== $claim ) { return 'lost'; }
		$row = $latest;
		$value = $row['value'];
		$abandoned = ( $claim['fence'] !== $value['fence'] || in_array( $value['status'], [ 'paused', 'stopped' ], true ) ) && time() - $claim['admitted_at'] >= 120;
		$read_failed = false;
		$current = static function() use ( $store, $row, &$read_failed ) {
			$now = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $now['state'] === 'error' ) { $read_failed = true; }
			return $now['state'] === 'valid' && $now['site'] === $row['site'] && $now['id'] === $row['id'] && $now['raw'] === $row['raw'];
		};
		$release = static function( $renew ) use ( $store, $row ) {
			$value = $row['value'];
			$value['claim'] = null;
			if ( $value['pause_reason'] === 'summary_unconfirmed' ) { $value['pause_reason'] = ''; }
			$value['updated'] = time();
			return $store->swap_exact_before_write( $row, $value, $renew );
		};
		if ( ! $authorized() ) { return 'iw_run_item_forbidden'; }
		$recovered = Image_Watermark()->get_upload_handler()->recover_runner_attempt( $claim['attachment'], [ 'run' => $value['id'], 'attempt' => $claim['attempt'] ], $value['operation'], $claim['summary_before'], $current, $release, $abandoned, $disposition );
		if ( $read_failed ) { $disposition = null; return 'error'; }
		if ( $recovered === 'interrupted' ) {
			return self::settle_claim_result( $store, $row, [ 'outcome' => 'interrupted', 'code' => 'interrupted', 'retryable' => true ] );
		}
		return $recovered === 'terminal' ? self::reconcile_terminal_claim( $store, $row, $value['id'], $claim['attempt'], true, $allow_pause ? $authorized : false ) : ( $recovered === 'pending' ? $result : $recovered );
	}

	/** Apply a driver transition and reconcile terminal evidence after takeover. */
	public static function control( $store, $run, $fence, $action ) {
		if ( ! current_user_can( 'manage_options' ) || ! $store instanceof Image_Watermark_Run_Store ) { return [ 'result' => 'forbidden' ]; }
		for ( $tries = 0; $tries < 5; ++$tries ) {
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] !== 'valid' ) { return [ 'result' => $row['state'] ]; }
			if ( in_array( $action, [ 'resume', 'continue_with_new_settings' ], true ) ) {
				if ( $row['value']['id'] !== $run || $row['value']['fence'] !== $fence ) { return [ 'result' => 'stale' ]; }
				// A regenerate run inherited from 3.0 is stop-only (A-P10-1 Scope 3).
				if ( $row['value']['operation'] === 'regenerate' ) { return [ 'result' => 'state' ]; }
				if ( $row['value']['operation'] === 'scan' && $action === 'continue_with_new_settings' ) { return [ 'result' => 'state' ]; }
				if ( $row['value']['claim'] !== null && $row['value']['claim']['state'] === 'executing' ) {
					if ( $row['value']['operation'] === 'scan' ) { return [ 'result' => 'state' ]; }
					$result = self::reconcile_terminal_claim( $store, $row, $run, $row['value']['claim']['attempt'], true );
					if ( in_array( $result, [ 'updated', 'lost' ], true ) ) { continue; }
					return [ 'result' => $result === 'pending' ? 'state' : $result ];
				}
				$result = [ 'result' => self::settings_transition( $store, $row, $run, $fence, $action ) ];
			} else { $result = self::driver_control( $store, $row, $run, $fence, $action ); }
			if ( $result['result'] === 'lost' ) { continue; }
			if ( $action === 'takeover' && $result['result'] === 'updated' ) {
				$current = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
				if ( $current['state'] === 'valid' && $current['value']['id'] === $run && $current['value']['fence'] === $result['fence'] && $current['value']['claim'] !== null && $current['value']['claim']['state'] === 'executing' ) {
					if ( $current['value']['operation'] === 'scan' ) { self::recover_abandoned_scan( $store, $current ); }
					else {
						$recovery = self::reconcile_or_recover( $store, $current );
						if ( $recovery === 'iw_run_item_forbidden' ) { $result['code'] = $recovery; }
					}
				}
			}
			return $result;
		}
		return [ 'result' => 'lost' ];
	}

	/** Settlement-only status with a path-free, fence-free bounded projection. */
	public static function status( $store, $run = '', $page = 1 ) {
		if ( ! current_user_can( 'manage_options' ) || ! $store instanceof Image_Watermark_Run_Store ) { return [ 'result' => 'forbidden' ]; }
		if ( ! is_string( $run ) || ! is_int( $page ) || $page < 1 ) { return [ 'result' => 'invalid' ]; }
		$active = null;
		$archiving_id = '';
		$recovery_forbidden = false;
		for ( $tries = 0; $tries < 5; ++$tries ) {
			$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $row['state'] === 'malformed' ) { return [ 'state' => 'unreadable', 'code' => 'iw_run_active_malformed' ]; }
			if ( $row['state'] === 'error' ) { return [ 'result' => 'error' ]; }
			if ( $row['state'] === 'absent' ) { $active = null; break; }
			$active = $row['value'];
			if ( $active['claim'] !== null && $active['claim']['state'] === 'executing' ) {
				$disposition = null;
				$result = $active['operation'] === 'scan' ? self::recover_abandoned_scan( $store, $row ) : self::reconcile_or_recover( $store, $row, $disposition );
				if ( in_array( $result, [ 'updated', 'lost' ], true ) ) { continue; }
				if ( $result === 'iw_run_item_forbidden' ) { $recovery_forbidden = true; }
				elseif ( ! in_array( $result, [ 'pending', 'summary_unconfirmed', 'unclassified_outcome' ], true ) ) { return [ 'result' => $result ]; }
				$original_claim = $active['claim'];
				$original_id = $active['id'];
				$original_status = $active['status'];
				$original_operation = $active['operation'];
				$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
				if ( $row['state'] === 'error' ) { return [ 'result' => 'error' ]; }
				if ( $row['state'] !== 'valid' ) { continue; }
				$active = $row['value'];
				$now = time();
				$eligible = $row['site'] === get_current_blog_id() && $active['id'] === $original_id && $active['claim'] === $original_claim && $original_status === 'stopped' && $active['status'] === 'stopped' && $active['operation'] === $original_operation && in_array( $active['operation'], [ 'apply', 'remove', 'regenerate' ], true ) && $now - max( $active['finished'], $original_claim['admitted_at'] ) >= 1020;
				if ( $eligible ) {
					$handler = Image_Watermark()->get_upload_handler();
					$identity = [ 'run' => $original_id, 'attempt' => $original_claim['attempt'] ];
					$observation = $handler->runner_release_observation( $original_claim['attachment'] );
					if ( $observation['state'] !== 'ok' ) { return [ 'result' => 'error' ]; }
					$final = self::reconcile_terminal_claim( $store, $row, $original_id, $original_claim['attempt'], true, false, $observation );
					if ( in_array( $final, [ 'updated', 'lost' ], true ) ) { continue; }
					if ( ! in_array( $final, [ 'pending', 'summary_unconfirmed', 'unclassified_outcome' ], true ) ) { return [ 'result' => $final ]; }
				}
				if ( $eligible && $handler->runner_unknown_evidence( $original_claim['attachment'], $identity, $original_operation, $original_claim['summary_before'], $observation, $disposition ) ) {
					$active['claim'] = null;
					$active['pause_reason'] = 'summary_unconfirmed';
					$active['updated'] = time();
					$cancelled = $store->swap_exact( $row, $active );
					if ( $cancelled === 'updated' || $cancelled === 'lost' ) { $recovery_forbidden = false; continue; }
					return [ 'result' => $cancelled ];
				}
			}
			if ( in_array( $active['status'], [ 'stopped', 'complete' ], true ) && $active['claim'] === null ) {
				$archiving_id = $active['id'];
				$result = $store->archive_finished( $row, self::scope_fingerprint( $active['target'] ) );
				if ( $result === 'lost' ) { continue; }
				if ( $result !== 'deleted' ) { return [ 'result' => $result === 'malformed' ? 'history_malformed' : 'error' ]; }
				$active = null;
			}
			break;
		}
		if ( $tries === 5 ) { return [ 'result' => 'error' ]; }
		if ( ! $recovery_forbidden ) {
			$pruned = $store->merge_history();
			if ( ! in_array( $pruned, [ 'updated', 'unchanged', 'inserted' ], true ) ) { return [ 'result' => $pruned ]; }
		}
		$history = $store->read_row( Image_Watermark_Run_Store::HISTORY );
		if ( $history['state'] === 'malformed' ) { return [ 'result' => 'malformed', 'code' => 'iw_run_history_malformed' ]; }
		if ( ! in_array( $history['state'], [ 'valid', 'absent' ], true ) ) { return [ 'result' => 'error' ]; }
		$runs = $history['state'] === 'valid' ? $history['value']['runs'] : [];
		$selected = $active !== null && ( $run === '' || $run === $active['id'] ) ? $active : null;
		if ( $selected === null && $run === '' && $active === null && $archiving_id !== '' ) { $run = $archiving_id; }
		if ( $selected === null && $run !== '' ) { foreach ( $runs as $item ) { if ( $item['id'] === $run ) { $selected = $item; break; } } }
		if ( $selected === null && $run !== '' ) { return [ 'result' => 'not_found' ]; }
		$headers = [];
		foreach ( $runs as $item ) { unset( $item['problems'], $item['scan'] ); $headers[] = $item; }
		$response = [ 'result' => 'ok', 'history' => $headers, 'selected' => null, 'problems' => [], 'retained_problems' => 0 ];
		if ( $recovery_forbidden ) { $response['code'] = 'iw_run_item_forbidden'; }
		if ( $selected !== null ) {
			$problems = $selected['problems'];
			$claim = isset( $selected['claim'] ) ? $selected['claim'] : null;
			unset( $selected['problems'], $selected['fence'], $selected['claim'], $selected['settings_fingerprint'], $selected['sizes_fingerprint'], $selected['next_attempt'] );
			if ( $active !== null && $selected['id'] === $active['id'] ) {
				$selected['claim'] = $claim === null ? null : array_intersect_key( $claim, array_flip( [ 'attachment', 'attempt', 'state', 'admitted_at' ] ) );
			}
			$response['selected'] = $selected;
			$response['retained_problems'] = count( $problems );
			$response['retryable_problems'] = 0;
			if ( $selected['operation'] !== 'scan' ) { foreach ( $problems as $problem ) { if ( isset( $problem['r'] ) && $problem['r'] === true ) { ++$response['retryable_problems']; } } }
			$response['problems'] = $page > 4 ? [] : array_slice( $problems, ( $page - 1 ) * 50, 50 );
		}
		return $response;
	}

	/** Prepare an R13 payload from an untrusted JSON list; never truncate it. */
	public static function normalize_handoff( $operation, $ids_json ) {
		if ( ! in_array( $operation, [ 'apply', 'remove' ], true ) || ! is_string( $ids_json ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		$ids = json_decode( $ids_json );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ids ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		if ( count( $ids ) > 2000 ) { return [ 'error' => 'iw_run_target_too_large' ]; }
		foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return [ 'error' => 'iw_run_invalid_input' ]; } }
		$normalized = self::normalize_target( wp_json_encode( [ 'type' => 'ids', 'ids' => $ids ] ) );
		if ( isset( $normalized['error'] ) ) { return $normalized; }
		$value = [ 'version' => 1, 'operation' => $operation, 'ids' => $normalized['target']['ids'] ];
		return self::valid_handoff( $value ) ? [ 'value' => $value ] : [ 'error' => 'iw_run_target_too_large' ];
	}

	/** Validate a stored R13 value without repairing or coercing it. */
	public static function valid_handoff( $value ) {
		if ( ! is_array( $value ) || count( $value ) !== 3 || ! array_key_exists( 'version', $value ) || ! array_key_exists( 'operation', $value ) || ! array_key_exists( 'ids', $value ) || $value['version'] !== 1 || ! in_array( $value['operation'], [ 'apply', 'remove', 'regenerate' ], true ) || strlen( serialize( $value ) ) > 65536 ) { return false; }
		return Image_Watermark_Run_Store::target( [ 'type' => 'ids', 'ids' => $value['ids'] ] );
	}

	/** Resolve this user's live R13 handoff only when 2.1 can start its operation. */
	public static function available_handoff( $token = null ) {
		$handoff = self::current_handoff( $token );
		return ! empty( $handoff ) && in_array( $handoff['value']['operation'], [ 'apply', 'remove' ], true ) ? $handoff : [];
	}

	/** Resolve this user's live R13 handoff for the current request, or an empty array. */
	public static function current_handoff( $token = null ) {
		if ( $token === null ) {
			$token = isset( $_GET['iw_handoff'] ) && is_string( $_GET['iw_handoff'] ) ? wp_unslash( $_GET['iw_handoff'] ) : '';
		}
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{20}$/D', $token ) ) { return []; }
		$value = get_transient( 'iw_run_handoff_' . get_current_user_id() . '_' . $token );
		if ( ! self::valid_handoff( $value ) ) { return []; }
		return [ 'token' => $token, 'value' => $value ];
	}

	/** Normalize an external JSON target or return its registered refusal code. */
	public static function normalize_target( $json ) {
		if ( ! is_string( $json ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		$decoded = json_decode( $json );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_object( $decoded ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		$outer = get_object_vars( $decoded );
		if ( ! isset( $outer['type'] ) || ! is_string( $outer['type'] ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		if ( $outer['type'] === 'ids' ) {
			if ( count( $outer ) !== 2 || ! isset( $outer['ids'] ) || ! is_array( $outer['ids'] ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
			if ( count( $outer['ids'] ) > 2000 || strlen( serialize( [ 'type' => 'ids', 'ids' => $outer['ids'] ] ) ) > 65536 ) { return [ 'error' => 'iw_run_target_too_large' ]; }
			foreach ( $outer['ids'] as $id ) {
				if ( ! is_int( $id ) || $id < 1 ) { return [ 'error' => 'iw_run_invalid_input' ]; }
			}
			$ids = array_values( array_unique( $outer['ids'], SORT_REGULAR ) );
			// SORT_NUMERIC converts large integers to floats and can misorder them.
			usort( $ids, static function( $a, $b ) { return $a <=> $b; } );
			$target = [ 'type' => 'ids', 'ids' => $ids ];
		} elseif ( $outer['type'] === 'filters' ) {
			if ( count( $outer ) !== 2 || ! isset( $outer['filters'] ) || ! is_object( $outer['filters'] ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
			$input = get_object_vars( $outer['filters'] );
			$order = [ 'date_from', 'date_to', 'parent', 'mime', 'watermarked', 'state', 'backup', 'clean_source', 'last_outcome', 'last_operation', 'journal', 'client_side', 'problems_of_run', 'condition' ];
			if ( array_diff( array_keys( $input ), $order ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
			$filters = [];
			foreach ( $order as $key ) {
				if ( ! array_key_exists( $key, $input ) ) { continue; }
				$value = $input[ $key ];
				if ( in_array( $key, [ 'mime', 'state', 'backup', 'clean_source', 'last_outcome', 'last_operation', 'client_side', 'condition' ], true ) ) {
					if ( ! is_array( $value ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
					foreach ( $value as $item ) { if ( ! is_string( $item ) ) { return [ 'error' => 'iw_run_invalid_input' ]; } }
					$value = array_values( array_unique( $value, SORT_STRING ) );
					sort( $value, SORT_STRING );
				}
				$filters[ $key ] = $value;
			}
			$target = [ 'type' => 'filters', 'filters' => $filters ];
			if ( isset( $filters['parent'] ) && $filters['parent'] !== 'unattached' && ( ! is_string( $filters['parent'] ) || ! post_type_exists( $filters['parent'] ) ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		} else { return [ 'error' => 'iw_run_invalid_input' ]; }
		if ( strlen( serialize( $target ) ) > 65536 ) { return [ 'error' => 'iw_run_target_too_large' ]; }
		if ( ! Image_Watermark_Run_Store::target( $target ) ) { return [ 'error' => 'iw_run_invalid_input' ]; }
		$fingerprint = self::scope_fingerprint( $target );
		return $fingerprint === false ? [ 'error' => 'iw_run_invalid_input' ] : [ 'target' => $target, 'scope_fingerprint' => $fingerprint ];
	}

	/** Hash full stored target identity, not the compact archived ID range. */
	public static function scope_fingerprint( $target ) {
		if ( ! Image_Watermark_Run_Store::target( $target ) ) { return false; }
		if ( $target['type'] === 'ids' ) {
			$canonical = [ 'type' => 'ids', 'ids' => $target['ids'] ];
		} else {
			$filters = [];
			foreach ( [ 'date_from', 'date_to', 'parent', 'mime', 'watermarked', 'state', 'backup', 'clean_source', 'last_outcome', 'last_operation', 'journal', 'client_side', 'problems_of_run', 'condition' ] as $key ) {
				if ( array_key_exists( $key, $target['filters'] ) ) { $filters[ $key ] = $target['filters'][ $key ]; }
			}
			$canonical = [ 'type' => 'filters', 'filters' => (object) $filters ];
		}
		$json = json_encode( $canonical, 0 );
		return $json === false ? false : hash( 'sha256', $json );
	}

	/** One server-time-limited count; uncertain metadata cannot yield an estimate. */
	private static function estimated_total( $target, $bound ) {
		global $wpdb;
		if ( $target['type'] !== 'filters' ) { return null; }
		$filters = $target['filters'];
		if ( array_diff( array_keys( $filters ), [ 'date_from', 'date_to', 'parent', 'mime', 'watermarked' ] ) ) { return null; }
		$server = $wpdb->db_server_info();
		if ( ! is_string( $server ) ) { return null; }
		$maria = stripos( $server, 'mariadb' ) !== false;
		$version = $maria ? preg_replace( '/^5\\.5\\.5-/', '', $server ) : $server;
		if ( ! preg_match( '/^(\\d+\\.\\d+\\.\\d+)/', $version, $match ) || version_compare( $match[1], $maria ? '10.1.2' : '5.7.8', '<' ) ) { return null; }
		$start = microtime( true );
		$deadline = $start + 2;
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) { $deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $maximum - 2 ); }
		$milliseconds = (int) floor( ( $deadline - $start ) * 1000 );
		if ( $milliseconds < 1 ) { return null; }
		$where = [ $wpdb->prepare( 'p.ID <= %d', $bound ), "BINARY p.post_type = 'attachment'", "BINARY p.post_mime_type IN ('image/jpeg','image/png','image/webp')" ];
		if ( isset( $filters['date_from'] ) ) { $where[] = $wpdb->prepare( 'LEFT(p.post_date, 10) >= %s', $filters['date_from'] ); }
		if ( isset( $filters['date_to'] ) ) { $where[] = $wpdb->prepare( 'LEFT(p.post_date, 10) <= %s', $filters['date_to'] ); }
		if ( isset( $filters['mime'] ) ) { $where[] = $wpdb->prepare( 'BINARY p.post_mime_type IN (' . implode( ',', array_fill( 0, count( $filters['mime'] ), '%s' ) ) . ')', $filters['mime'] ); }
		if ( isset( $filters['parent'] ) ) {
			if ( $filters['parent'] === 'unattached' ) { $where[] = 'p.post_parent = 0'; }
			elseif ( ! post_type_exists( $filters['parent'] ) ) { return null; }
			else { $where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->posts} parent WHERE parent.ID = p.post_parent AND BINARY parent.post_type = %s)", $filters['parent'] ); }
		}
		$join = '';
		$columns = 'COUNT(*) AS total, 0 AS uncertain';
		if ( array_key_exists( 'watermarked', $filters ) ) {
			// Only missing, canonical 0 and canonical 1 have universally exact SQL equivalents.
			$join = $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s", 'iw-is-watermarked' );
			$valid = "(COUNT(m.meta_id) = 0 OR (COUNT(m.meta_id) = 1 AND BINARY MAX(m.meta_value) IN ('0','1')))";
			$matches = $filters['watermarked'] ? "COUNT(m.meta_id) = 1 AND BINARY MAX(m.meta_value) = '1'" : "COUNT(m.meta_id) = 0 OR (COUNT(m.meta_id) = 1 AND BINARY MAX(m.meta_value) = '0')";
			$inner = "SELECT p.ID, COALESCE($valid, 0) AS valid_flag, ($matches) AS matches_flag FROM {$wpdb->posts} p $join WHERE " . implode( ' AND ', $where ) . ' GROUP BY p.ID';
			$from = "($inner) counted";
			$columns = 'COUNT(*) AS total, COALESCE(SUM(NOT valid_flag),0) AS uncertain, COALESCE(SUM(matches_flag),0) AS matching';
		} else { $from = "{$wpdb->posts} p WHERE " . implode( ' AND ', $where ); }
		$hint = $maria ? '' : '/*+ MAX_EXECUTION_TIME(' . $milliseconds . ') */ ';
		$sql = ( $maria ? 'SET STATEMENT max_statement_time=' . ( $milliseconds / 1000 ) . ' FOR ' : '' ) . "SELECT {$hint}{$columns} FROM {$from}";
		$previous = $wpdb->suppress_errors( true );
		try { $row = $wpdb->get_row( $sql, ARRAY_A ); }
		finally { $wpdb->suppress_errors( $previous ); }
		if ( microtime( true ) > $deadline || $wpdb->last_error !== '' || ! is_array( $row ) || ! isset( $row['total'], $row['uncertain'] ) || (string) $row['uncertain'] !== '0' ) { return null; }
		if ( array_key_exists( 'watermarked', $filters ) && ! isset( $row['matching'] ) ) { return null; }
		$count = array_key_exists( 'watermarked', $filters ) ? $row['matching'] : $row['total'];
		if ( ! is_scalar( $count ) || ! ctype_digit( (string) $count ) ) { return null; }
		$digits = ltrim( (string) $count, '0' );
		$digits = $digits === '' ? '0' : $digits;
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $maximum ) || ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) ) { return null; }
		return [ 'count' => (int) $digits, 'time' => time() ];
	}

	/** Read the site's current posts-table ceiling before beginning a traversal. */
	public static function traversal_bound() {
		global $wpdb;
		$bound = $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts}" );
		if ( $wpdb->last_error !== '' || $bound === null || ! ctype_digit( (string) $bound ) ) { return false; }
		$digits = ltrim( (string) $bound, '0' );
		$digits = $digits === '' ? '0' : $digits;
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $digits ) > strlen( $maximum ) || ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) ) { return false; }
		return (int) $digits;
	}

	/**
	 * Read one keyset page. This is an observation, not a claim or cursor write.
	 * Explicit missing IDs are returned with a null post; filters omit numeric holes.
	 */
	public static function traversal_page( $target, $cursor, $bound ) {
		global $wpdb;
		if ( ! Image_Watermark_Run_Store::target( $target ) || ! is_int( $cursor ) || ! is_int( $bound ) || $cursor < 0 || $bound < $cursor ) { return false; }
		if ( $target['type'] === 'ids' ) {
			$ids = array_slice( array_values( array_filter( $target['ids'], static function( $id ) use ( $cursor, $bound ) { return $id > $cursor && $id <= $bound; } ) ), 0, 100 );
			if ( ! $ids ) { return []; }
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = $wpdb->prepare( "SELECT ID, post_type, post_mime_type, post_parent, post_date FROM {$wpdb->posts} WHERE ID IN ($placeholders) ORDER BY ID ASC", $ids );
		} else {
			$sql = $wpdb->prepare( "SELECT ID, post_type, post_mime_type, post_parent, post_date FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type = %s ORDER BY ID ASC LIMIT 100", $cursor, $bound, 'attachment' );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return false; }
		$posts = [];
		foreach ( $rows as $row ) { $posts[ (int) $row['ID'] ] = $row; }
		if ( $target['type'] === 'filters' ) { return $posts; }
		$page = [];
		foreach ( $ids as $id ) { $page[ $id ] = isset( $posts[ $id ] ) ? $posts[ $id ] : null; }
		return $page;
	}

	/** Evaluate current R4 predicates from authoritative singleton rows, not cache. */
	private static function summary_matches( $filters, $rows ) {
		if ( count( $rows ) > 1 ) { return null; }
		if ( ! $rows ) { return false; }
		$summary = Image_Watermark()->get_upload_handler()->project_contract_operation_outcome( maybe_unserialize( $rows[0] ) );
		if ( ! $summary ) { return null; }
		if ( isset( $filters['last_outcome'] ) && ! in_array( $summary['outcome'], $filters['last_outcome'], true ) ) { return false; }
		if ( isset( $filters['last_operation'] ) && ! in_array( $summary['operation'], $filters['last_operation'], true ) ) { return false; }
		if ( isset( $filters['problems_of_run'] ) && ( $summary['run'] !== $filters['problems_of_run'] || $summary['retryable'] !== true ) ) { return false; }
		return true;
	}

	/** Evaluate stored-only scanner facts without touching files or mutating state. */
	private static function stored_matches( $filters, $id, &$failure_reason, $facts = null, $expected = null ) {
		if ( $facts === null ) { $facts = Image_Watermark()->get_upload_handler()->runner_stored_facts( $id, $expected ); }
		if ( $facts === false || $facts === 'settings_changed' ) { $failure_reason = $facts === false ? 'metadata_read_failed' : 'settings_changed'; return null; }
		$failure_reason = 'invalid_metadata';
		if ( isset( $filters['journal'] ) ) {
			$fact = $facts['operation']['incomplete'];
			if ( $fact['state'] !== 'known' ) { return null; }
			if ( ! $fact['value'] ) { return false; }
		}
		if ( isset( $filters['client_side'] ) ) {
			$state = $facts['client_side']['state']; $code = $facts['client_side']['code'];
			if ( $state['state'] !== 'known' || $code['state'] !== 'known' ) { return null; }
			$mapping = [ 'client_side_upload_pending' => 'pending', 'client_side_watermark_retry_pending' => 'pending', 'client_side_upload_incomplete' => 'incomplete', 'client_side_finalize_deferred' => 'deferred' ];
			if ( $state['value'] !== 'pending' || ! isset( $mapping[ $code['value'] ] ) || ! in_array( $mapping[ $code['value'] ], $filters['client_side'], true ) ) { return false; }
		}
		if ( isset( $filters['state'] ) ) {
			$watermarked = $facts['watermarked']; $manifest = $facts['manifest']; $drift = $facts['drift'];
			if ( $watermarked['state'] !== 'known' ) { return null; }
			if ( ! $watermarked['value'] ) { return false; }
			if ( $drift['state']['state'] !== 'known' || $drift['verifiable']['state'] !== 'known' || $manifest['state'] !== 'known' ) { return null; }
			$state = '';
			if ( $drift['state']['value'] === 'verified' && $drift['verifiable']['value'] ) { $state = 'drift_verified'; }
			elseif ( $drift['state']['value'] !== 'none' ) { $state = 'drift_suspected'; }
			elseif ( in_array( $manifest['value'], [ 'legacy', 'settings_changed' ], true ) ) { $state = $manifest['value']; }
			if ( ! in_array( $state, $filters['state'], true ) ) { return false; }
		}
		return true;
	}

	/** Evaluate authorized file predicates from one read-only scanner observation. */
	private static function file_matches( $filters, $id, $operation, $scan_depth, $deadline ) {
		if ( ! array_intersect( [ 'backup', 'clean_source', 'condition' ], array_keys( $filters ) ) ) { return [ 'decision' => 'matches' ]; }
		$validated = ( isset( $filters['backup'] ) && in_array( 'validated', $filters['backup'], true ) ) || ( isset( $filters['clean_source'] ) && in_array( 'validated', $filters['clean_source'], true ) );
		$depth = $operation === 'scan' ? $scan_depth : ( $validated ? 'full' : 'header' );
		if ( ! in_array( $depth, [ 'header', 'full' ], true ) ) { return [ 'decision' => 'unmeasured', 'reason' => 'scan_unmeasurable' ]; }
		if ( microtime( true ) >= $deadline ) { return [ 'decision' => 'unmeasured', 'reason' => 'scan_incomplete' ]; }
		try {
			$scan = Image_Watermark()->get_upload_handler()->inspect_attachment_for_scan( $id, [ 'depth' => $depth, 'deadline' => $deadline, 'authorization' => [ 'site_id' => get_current_blog_id(), 'user_id' => get_current_user_id() ] ] );
		} catch ( Throwable $e ) { return [ 'decision' => 'unmeasured', 'reason' => 'scan_incomplete' ]; }
		$unknown = static function( $fact ) { return [ 'decision' => 'unmeasured', 'reason' => ! empty( $fact['retryable'] ) ? 'scan_incomplete' : 'scan_unmeasurable' ]; };
		if ( $scan['outcome']['code'] === 'attachment_not_found' || $scan['outcome']['code'] === 'scan_not_applicable' ) { return [ 'decision' => 'no_longer_matches' ]; }
		if ( $scan['outcome']['code'] === 'scan_forbidden' ) { return [ 'decision' => 'forbidden' ]; }
		foreach ( [ 'backup', 'clean_source' ] as $key ) {
			if ( ! isset( $filters[ $key ] ) ) { continue; }
			$fact = $scan['facts'][ $key ]['availability'];
			if ( $fact['state'] !== 'known' ) { return $unknown( $fact ); }
			if ( ! in_array( $fact['value'], $filters[ $key ], true ) ) {
				// A readable file with an unavailable decoder is not a negative validation.
				if ( $fact['value'] === 'present' && in_array( 'validated', $filters[ $key ], true ) ) {
					if ( $depth === 'header' ) { return [ 'decision' => 'unmeasured', 'reason' => 'scan_unmeasurable' ]; }
					$decode = $key === 'backup' ? $scan['facts']['backup']['decode'] : $scan['facts']['sources']['main']['decode'];
					if ( $decode['state'] !== 'known' ) { return $unknown( $decode ); }
				}
				return [ 'decision' => 'no_longer_matches' ];
			}
		}
		if ( isset( $filters['condition'] ) ) {
			if ( $scan['facts']['limits']['registered_total'] === null ) { return [ 'decision' => 'unmeasured', 'reason' => 'scan_incomplete' ]; }
			$mapping = [ 'missing' => 'missing_sizes', 'geometry_stale' => 'geometry_stale_sizes', 'not_producible' => 'unproducible_sizes' ];
			$found = false; $uncertain = null;
			foreach ( $scan['facts']['sizes'] as $size ) {
				$fact = $size['state'];
				if ( $fact['state'] !== 'known' ) { $uncertain = $unknown( $fact ); continue; }
				if ( isset( $mapping[ $fact['value'] ] ) && in_array( $mapping[ $fact['value'] ], $filters['condition'], true ) ) { $found = true; break; }
			}
			if ( ! $found ) { return $uncertain !== null ? $uncertain : [ 'decision' => 'no_longer_matches' ]; }
		}
		return [ 'decision' => 'matches' ];
	}

	/**
	 * Recheck an already claimed member before any protected predicate or operation.
	 *
	 * @return array Decision only; settlement belongs to the later claim slice.
	 */
	public static function recheck_claimed_member( $target, $id, $bound, $with_reason = false, $operation = null, $scan_depth = null, $expected = null ) {
		global $wpdb;
		$unknown = static function( $reason ) use ( $with_reason ) {
			return $with_reason ? [ 'decision' => 'unmeasured', 'reason' => $reason ] : [ 'decision' => 'unmeasured' ];
		};
		if ( ! Image_Watermark_Run_Store::target( $target ) || ! is_int( $id ) || $id < 1 || ! is_int( $bound ) || $bound < 0 ) { return $unknown( 'invalid_metadata' ); }
		if ( $id > $bound || ( $target['type'] === 'ids' && ! in_array( $id, $target['ids'], true ) ) ) { return [ 'decision' => 'no_longer_matches' ]; }
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_type, post_mime_type, post_parent, post_date FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) { return $unknown( 'metadata_read_failed' ); }
		if ( ! $post || $post['post_type'] !== 'attachment' || ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) { return [ 'decision' => 'no_longer_matches' ]; }
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) { return [ 'decision' => 'forbidden' ]; }
		$filters = $target['type'] === 'filters' ? $target['filters'] : [];
		if ( array_diff( array_keys( $filters ), [ 'date_from', 'date_to', 'parent', 'mime', 'watermarked', 'state', 'backup', 'clean_source', 'condition', 'last_outcome', 'last_operation', 'journal', 'client_side', 'problems_of_run' ] ) ) { return $unknown( 'scan_unmeasurable' ); }
		$date = substr( $post['post_date'], 0, 10 );
		if ( ( isset( $filters['date_from'] ) && $date < $filters['date_from'] ) || ( isset( $filters['date_to'] ) && $date > $filters['date_to'] ) || ( isset( $filters['mime'] ) && ! in_array( $post['post_mime_type'], $filters['mime'], true ) ) ) { return [ 'decision' => 'no_longer_matches' ]; }
		if ( isset( $filters['parent'] ) ) {
			$parent = (int) $post['post_parent'];
			if ( $filters['parent'] === 'unattached' ) {
				if ( $parent !== 0 ) { return [ 'decision' => 'no_longer_matches' ]; }
			} else {
				if ( ! post_type_exists( $filters['parent'] ) || ! $parent ) { return [ 'decision' => 'no_longer_matches' ]; }
				$type = $wpdb->get_var( $wpdb->prepare( "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $parent ) );
				if ( $wpdb->last_error !== '' ) { return $unknown( 'metadata_read_failed' ); }
				if ( $type !== $filters['parent'] ) { return [ 'decision' => 'no_longer_matches' ]; }
			}
		}
		if ( array_key_exists( 'watermarked', $filters ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2", $id, 'iw-is-watermarked' ), ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return $unknown( 'metadata_read_failed' ); }
			if ( count( $rows ) > 1 ) { return $unknown( 'invalid_metadata' ); }
			$value = $rows ? maybe_unserialize( $rows[0]['meta_value'] ) : '';
			if ( ! is_scalar( $value ) ) { return $unknown( 'invalid_metadata' ); }
			if ( ( (int) $value === 1 ) !== $filters['watermarked'] ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		if ( isset( $filters['last_outcome'] ) || isset( $filters['last_operation'] ) || isset( $filters['problems_of_run'] ) ) {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 2", $id, '_iw_last_operation' ) );
			if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return $unknown( 'metadata_read_failed' ); }
			$matches = self::summary_matches( $filters, $rows );
			if ( $matches === null ) { return $unknown( 'invalid_metadata' ); }
			if ( ! $matches ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		if ( isset( $filters['state'] ) || isset( $filters['journal'] ) || isset( $filters['client_side'] ) ) {
			$reason = 'metadata_read_failed';
			$matches = self::stored_matches( $filters, $id, $reason, null, $expected );
			if ( $matches === null ) { return $unknown( $reason ); }
			if ( ! $matches ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		$decision = self::file_matches( $filters, $id, $operation, $scan_depth, microtime( true ) + 2 );
		return $decision['decision'] === 'unmeasured' ? $unknown( $decision['reason'] ) : $decision;
	}

	/** Commit an observed traversal batch, rebasing only over heartbeat-only writes. */
	private static function swap_traversal( $store, $row, $value ) {
		$original = $row['value'];
		for ( $tries = 0; $tries < 5; ++$tries ) {
			$result = $store->swap_exact( $row, $value );
			if ( $result !== 'lost' ) { return $result; }
			$fresh = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $fresh['state'] !== 'valid' || $fresh['site'] !== $row['site'] || $fresh['id'] !== $row['id'] ) { return 'lost'; }
			$comparison = $fresh['value'];
			$comparison['heartbeat'] = $original['heartbeat'];
			$comparison['updated'] = $original['updated'];
			if ( $comparison !== $original ) { return 'lost'; }
			$value['heartbeat'] = $fresh['value']['heartbeat'];
			$value['updated'] = $fresh['value']['updated'];
			$row = $fresh;
		}
		return 'lost';
	}

	/**
	 * Advance over a leading page of missing explicit IDs, or exhaust the target.
	 * Stops before every existing post; claiming and filter traversal are separate.
	 */
	public static function advance_missing_ids( $store, $row, $run, $fence ) {
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence ) { return 'stale'; }
		if ( $value['status'] !== 'running' || $value['claim'] !== null || $value['target']['type'] !== 'ids' ) { return 'state'; }
		$settings = self::settings_transition( $store, $row, $run, $fence, 'check' );
		if ( $settings !== 'unchanged' ) { return $settings === 'updated' ? 'paused' : $settings; }
		$deadline = microtime( true ) + 2;
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) { $deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $maximum - 2 ); }
		if ( microtime( true ) >= $deadline ) { return 'incomplete'; }
		$page = self::traversal_page( $value['target'], $value['cursor'], $value['bound'] );
		if ( $page === false ) {
			$value['status'] = 'paused';
			$value['pause_reason'] = 'metadata_read_failed';
			$value['updated'] = time();
			return self::swap_traversal( $store, $row, $value );
		}
		$missing = [];
		foreach ( $page as $id => $post ) {
			if ( microtime( true ) >= $deadline || $post !== null ) { break; }
			$missing[] = $id;
			$value['cursor'] = $id;
		}
		if ( ! $page ) {
			foreach ( $value['target']['ids'] as $id ) {
				if ( $id > $value['bound'] ) { $missing[] = $id; }
			}
			$value['cursor'] = $value['bound'];
			$value['status'] = 'complete';
			$value['finished'] = time();
		}
		if ( ! $missing && $page ) { return microtime( true ) >= $deadline ? 'incomplete' : 'member'; }
		$count = count( $missing );
		$reason = isset( $value['reasons']['no_longer_matches'] ) ? 'no_longer_matches' : ( count( array_diff( array_keys( $value['reasons'] ), [ 'other' ] ) ) < 50 ? 'no_longer_matches' : 'other' );
		$old_reason = isset( $value['reasons'][ $reason ] ) ? $value['reasons'][ $reason ] : 0;
		if ( $value['counters']['skipped'] > PHP_INT_MAX - $count || $old_reason > PHP_INT_MAX - $count ) { return 'error'; }
		if ( $count ) {
			$value['counters']['skipped'] += $count;
			$value['reasons'][ $reason ] = $old_reason + $count;
			foreach ( $missing as $id ) {
				if ( count( $value['problems'] ) >= 200 ) { break; }
				$with_problem = $value;
				$with_problem['problems'][] = [ 'a' => $id, 'o' => 'skipped', 'c' => 'no_longer_matches', 'r' => false, 't' => time() ];
				if ( ! Image_Watermark_Run_Store::active_problem_fits( $with_problem ) ) { break; }
				$value = $with_problem;
			}
		}
		$value['updated'] = time();
		return self::swap_traversal( $store, $row, $value );
	}

	/** Read the basic metadata predicates for an authorized page from the database. */
	private static function traversal_batch( $page, $filters, $deadline, $expected = null ) {
		global $wpdb;
		$ids = [];
		$parents = [];
		foreach ( $page as $id => $post ) {
			if ( microtime( true ) >= $deadline ) { return null; }
			if ( in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id ) ) { $ids[] = $id; }
			if ( isset( $filters['parent'] ) && $filters['parent'] !== 'unattached' && (int) $post['post_parent'] > 0 ) { $parents[] = (int) $post['post_parent']; }
		}
		if ( $ids && ( update_meta_cache( 'post', $ids ) === false || $wpdb->last_error !== '' ) ) {
			// Core may cache empty metadata after its query fails.
			foreach ( $ids as $id ) { wp_cache_delete( $id, 'post_meta' ); }
			return false;
		}
		$meta = [];
		$keys = [];
		if ( array_key_exists( 'watermarked', $filters ) ) { $keys[] = 'iw-is-watermarked'; }
		if ( isset( $filters['last_outcome'] ) || isset( $filters['last_operation'] ) || isset( $filters['problems_of_run'] ) ) { $keys[] = '_iw_last_operation'; }
		if ( $ids && $keys ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$limit = count( $ids ) * count( $keys ) * 2;
			$sql = $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($id_placeholders) AND meta_key IN ($key_placeholders) ORDER BY meta_id LIMIT %d", array_merge( $ids, $keys, [ $limit + 1 ] ) );
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return false; }
			if ( count( $rows ) > $limit ) { return 'invalid_metadata'; }
			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline ) { return null; }
				$meta[ (int) $row['post_id'] ][ $row['meta_key'] ][] = $row['meta_value'];
			}
		}
		$types = [];
		$parents = array_values( array_unique( $parents ) );
		if ( $parents ) {
			$placeholders = implode( ',', array_fill( 0, count( $parents ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $parents ), ARRAY_A );
			if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return false; }
			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline ) { return null; }
				$types[ (int) $row['ID'] ] = $row['post_type'];
			}
		}
		$stored = [];
		if ( isset( $filters['state'] ) || isset( $filters['journal'] ) || isset( $filters['client_side'] ) ) {
			$stored = Image_Watermark()->get_upload_handler()->runner_stored_facts_batch( $ids, $deadline, $expected );
			if ( $stored === null || $stored === false || $stored === 'settings_changed' ) { return $stored; }
		}
		return [ 'authorized' => array_fill_keys( $ids, true ), 'meta' => $meta, 'parents' => $types, 'stored' => $stored ];
	}

	/** Evaluate the page's authoritative observations without per-item reads. */
	private static function traversal_batch_decision( $id, $post, $filters, $batch, $operation, $scan_depth, $deadline ) {
		$unknown = static function( $reason ) { return [ 'decision' => 'unmeasured', 'reason' => $reason ]; };
		if ( ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) { return [ 'decision' => 'no_longer_matches' ]; }
		if ( ! isset( $batch['authorized'][ $id ] ) ) { return [ 'decision' => 'forbidden' ]; }
		if ( array_diff( array_keys( $filters ), [ 'date_from', 'date_to', 'parent', 'mime', 'watermarked', 'state', 'backup', 'clean_source', 'condition', 'last_outcome', 'last_operation', 'journal', 'client_side', 'problems_of_run' ] ) ) { return $unknown( 'scan_unmeasurable' ); }
		$date = substr( $post['post_date'], 0, 10 );
		if ( ( isset( $filters['date_from'] ) && $date < $filters['date_from'] ) || ( isset( $filters['date_to'] ) && $date > $filters['date_to'] ) || ( isset( $filters['mime'] ) && ! in_array( $post['post_mime_type'], $filters['mime'], true ) ) ) { return [ 'decision' => 'no_longer_matches' ]; }
		if ( isset( $filters['parent'] ) ) {
			$parent = (int) $post['post_parent'];
			if ( $filters['parent'] === 'unattached' ? $parent !== 0 : ( ! post_type_exists( $filters['parent'] ) || ! isset( $batch['parents'][ $parent ] ) || $batch['parents'][ $parent ] !== $filters['parent'] ) ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		$meta = isset( $batch['meta'][ $id ] ) ? $batch['meta'][ $id ] : [];
		if ( array_key_exists( 'watermarked', $filters ) ) {
			$rows = isset( $meta['iw-is-watermarked'] ) ? $meta['iw-is-watermarked'] : [];
			if ( count( $rows ) > 1 ) { return $unknown( 'invalid_metadata' ); }
			$flag = $rows ? maybe_unserialize( $rows[0] ) : '';
			if ( ! is_scalar( $flag ) ) { return $unknown( 'invalid_metadata' ); }
			if ( ( (int) $flag === 1 ) !== $filters['watermarked'] ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		if ( isset( $filters['last_outcome'] ) || isset( $filters['last_operation'] ) || isset( $filters['problems_of_run'] ) ) {
			$matches = self::summary_matches( $filters, isset( $meta['_iw_last_operation'] ) ? $meta['_iw_last_operation'] : [] );
			if ( $matches === null ) { return $unknown( 'invalid_metadata' ); }
			if ( ! $matches ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		if ( isset( $filters['state'] ) || isset( $filters['journal'] ) || isset( $filters['client_side'] ) ) {
			$reason = 'metadata_read_failed';
			$matches = self::stored_matches( $filters, $id, $reason, $batch['stored'][ $id ] );
			if ( $matches === null ) { return $unknown( $reason ); }
			if ( ! $matches ) { return [ 'decision' => 'no_longer_matches' ]; }
		}
		return self::file_matches( $filters, $id, $operation, $scan_depth, $deadline );
	}

	/**
	 * Skip a bounded page of unclaimed filter nonmembers without counting them.
	 * A member or forbidden item stops before that ID; an unmeasured predicate pauses.
	 */
	public static function advance_filter_nonmembers( $store, $row, $run, $fence ) {
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence ) { return 'stale'; }
		if ( $value['status'] !== 'running' || $value['claim'] !== null || $value['target']['type'] !== 'filters' ) { return 'state'; }
		$settings = self::settings_transition( $store, $row, $run, $fence, 'check' );
		if ( $settings !== 'unchanged' ) { return $settings === 'updated' ? 'paused' : $settings; }
		$deadline = microtime( true ) + 2;
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) { $deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $maximum - 2 ); }
		if ( microtime( true ) >= $deadline ) { return 'incomplete'; }
		$page = self::traversal_page( $value['target'], $value['cursor'], $value['bound'] );
		if ( $page === false ) {
			$value['status'] = 'paused';
			$value['pause_reason'] = 'metadata_read_failed';
			$value['updated'] = time();
			return self::swap_traversal( $store, $row, $value );
		}
		if ( ! $page ) {
			$value['cursor'] = $value['bound'];
			$value['status'] = 'complete';
			$value['finished'] = time();
		} else {
			$batch = self::traversal_batch( $page, $value['target']['filters'], $deadline, self::run_fingerprints( $value ) );
			if ( $batch === null ) { return 'incomplete'; }
			if ( $batch === false || $batch === 'invalid_metadata' || $batch === 'settings_changed' ) {
				$value['status'] = 'paused';
				$value['pause_reason'] = $batch === false ? 'metadata_read_failed' : $batch;
				$value['updated'] = time();
				return self::swap_traversal( $store, $row, $value );
			}
			$start = $value['cursor'];
			foreach ( $page as $id => $post ) {
				if ( microtime( true ) >= $deadline ) { break; }
				$observation = self::traversal_batch_decision( $id, $post, $value['target']['filters'], $batch, $value['operation'], isset( $value['scan_depth'] ) ? $value['scan_depth'] : null, $deadline );
				$decision = $observation['decision'];
				if ( $decision === 'unmeasured' ) {
					$value['status'] = 'paused';
					$value['pause_reason'] = $observation['reason'];
					break;
				}
				if ( $decision !== 'no_longer_matches' ) {
					if ( $value['cursor'] === $start ) { return 'member'; }
					break;
				}
				$value['cursor'] = $id;
			}
			if ( $value['cursor'] === $start && $value['status'] !== 'paused' ) { return 'incomplete'; }
		}
		$value['updated'] = time();
		return self::swap_traversal( $store, $row, $value );
	}

	/**
	 * Change the driver only; executing claims remain for later reconciliation.
	 * The caller is responsible for status/recovery and archive orchestration.
	 */
	public static function driver_control( $store, $row, $run, $fence, $action ) {
		if ( ! current_user_can( 'manage_options' ) ) { return [ 'result' => 'forbidden' ]; }
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return [ 'result' => 'invalid' ]; }
		$value = $row['value'];
		if ( $value['id'] !== $run ) { return [ 'result' => 'stale' ]; }
		if ( ! in_array( $action, [ 'heartbeat', 'pause', 'stop', 'takeover' ], true ) ) { return [ 'result' => 'invalid' ]; }
		if ( $action !== 'takeover' && $value['fence'] !== $fence ) { return [ 'result' => 'stale' ]; }
		if ( ! in_array( $value['status'], [ 'running', 'paused' ], true ) || ( $action === 'pause' && $value['status'] !== 'running' ) ) { return [ 'result' => 'state' ]; }
		$now = time();
		if ( $action === 'takeover' ) {
			if ( get_current_user_id() !== $value['owner'] && $now - $value['heartbeat'] <= 60 ) { return [ 'result' => 'takeover_too_early' ]; }
			try { $new_fence = bin2hex( random_bytes( 16 ) ); } catch ( Throwable $e ) { return [ 'result' => 'error' ]; }
			if ( $new_fence === $value['fence'] ) { return [ 'result' => 'error' ]; }
			$value['fence'] = $new_fence;
			$value['heartbeat'] = $now;
			if ( $value['claim'] !== null && $value['claim']['state'] === 'claimed' ) { $value['claim'] = null; }
		} elseif ( $action === 'heartbeat' ) {
			$value['heartbeat'] = $now;
		} else {
			$value['status'] = $action === 'stop' ? 'stopped' : 'paused';
			if ( $action === 'stop' ) { $value['finished'] = $now; }
			else { $value['pause_reason'] = ''; }
			if ( $value['claim'] !== null && $value['claim']['state'] === 'claimed' ) { $value['claim'] = null; }
		}
		$value['updated'] = $now;
		$result = $store->swap_exact( $row, $value );
		if ( $action === 'takeover' && $result === 'updated' ) { return [ 'result' => $result, 'fence' => $new_fence ]; }
		return [ 'result' => $result ];
	}

	/**
	 * Allocate only the first remaining traversal member. This primitive does not
	 * drive traversal or recover an existing claim; callers must do those first.
	 */
	public static function allocate_claim( $store, $row, $run, $fence, $attachment ) {
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence ) { return 'stale'; }
		if ( $value['operation'] === 'regenerate' ) { return 'state'; }
		if ( $value['status'] !== 'running' || $value['claim'] !== null ) { return 'state'; }
		if ( ! is_int( $attachment ) || $attachment <= $value['cursor'] || $attachment > $value['bound'] ) { return 'invalid'; }
		$page = self::traversal_page( $value['target'], $value['cursor'], $value['bound'] );
		if ( $page === false ) { return 'error'; }
		if ( ! $page || key( $page ) !== $attachment ) { return 'stale'; }
		$settings = self::settings_transition( $store, $row, $run, $fence, 'check' );
		if ( $settings !== 'unchanged' ) { return $settings === 'updated' ? 'paused' : $settings; }
		$now = time();
		$value['heartbeat'] = $now;
		$value['updated'] = $now;
		if ( $value['next_attempt'] === PHP_INT_MAX ) {
			$value['status'] = 'stopped';
			$value['pause_reason'] = 'iw_run_attempts_exhausted';
			$value['finished'] = $now;
			$result = $store->swap_exact( $row, $value );
			return $result === 'updated' ? 'attempts_exhausted' : $result;
		}
		$attempt = $value['next_attempt']++;
		$value['claim'] = [ 'attachment' => $attachment, 'attempt' => $attempt, 'state' => 'claimed', 'fence' => $fence, 'admitted_at' => 0, 'summary_before' => str_repeat( '0', 64 ) ];
		return $store->swap_exact( $row, $value );
	}

	/** Admit one already claimed mutation; an executing claim is never admitted again. */
	public static function admit_claim( $store, $row, $run, $fence, $attempt, &$settled_outcome = null, $settle_malformed = false ) {
		$settled_outcome = null;
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		$claim = $value['claim'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence || ! is_array( $claim ) || $claim['fence'] !== $fence || $claim['attempt'] !== $attempt ) { return 'stale'; }
		if ( $value['operation'] === 'regenerate' ) { return 'state'; }
		if ( $value['status'] !== 'running' || $claim['state'] !== 'claimed' ) { return 'state'; }
		if ( ! $store->history_packet_ready() ) { return 'error'; }
		// One shared budget for both pre-admission losses; never retry execution.
		for ( $observation = 0; $observation < 5; ++$observation ) {
			$value = $row['value'];
			$claim = $value['claim'];
			$settings = self::settings_transition( $store, $row, $run, $fence, 'check' );
			if ( $settings !== 'lost' && $settings !== 'unchanged' ) { return $settings === 'updated' ? 'paused' : ( $settings === 'invalid' ? 'error' : $settings ); }
			$result = $settings;
			if ( $settings === 'unchanged' ) {
				$baseline = str_repeat( '0', 64 );
				if ( $value['operation'] !== 'scan' ) {
					$summary = Image_Watermark()->get_upload_handler()->observe_runner_summary( $claim['attachment'] );
					if ( $summary['state'] === 'malformed' && $settle_malformed && current_user_can( 'manage_options' ) && current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $claim['attachment'] ) ) {
						$result = self::settle_claim_result( $store, $row, [ 'outcome' => 'failed', 'code' => 'summary_malformed', 'retryable' => true ] );
						if ( $result === 'updated' ) {
							$settled_outcome = [ 'operation' => $value['operation'], 'outcome' => 'failed', 'code' => 'summary_malformed', 'message' => '', 'retryable' => true, 'sizes' => [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ] ];
							return 'summary_settled';
						}
						return $result === 'lost' ? 'admission_conflict' : ( $result === 'invalid' ? 'error' : $result );
					}
					if ( ! in_array( $summary['state'], [ 'valid', 'absent' ], true ) ) { return $summary['state']; }
					$baseline = $summary['digest'];
				}
				$value['claim']['state'] = 'executing';
				$value['claim']['admitted_at'] = time();
				$value['claim']['summary_before'] = $baseline;
				$value['updated'] = time();
				$result = $store->swap_exact( $row, $value );
			}
			if ( $result !== 'lost' ) { return $result === 'invalid' ? 'error' : $result; }
			$current = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $current['state'] !== 'valid' ) { return $current['state']; }
			if ( get_current_blog_id() !== $row['site'] || $current['site'] !== $row['site'] || $current['id'] !== $row['id'] || $current['autoload'] !== $row['autoload'] ) { return 'state'; }
			$next = $current['value'];
			if ( $next['id'] !== $run || $next['fence'] !== $fence || ! is_array( $next['claim'] ) || $next['claim']['fence'] !== $fence || $next['claim']['attempt'] !== $attempt ) { return 'stale'; }
			if ( $next['status'] !== 'running' ) { return 'state'; }
			// Preserve the existing refusal for an already admitted stale observation.
			if ( $next['claim']['state'] !== 'claimed' ) { return 'lost'; }
			$previous = $row['value'];
			unset( $previous['heartbeat'], $previous['updated'], $next['heartbeat'], $next['updated'] );
			if ( $previous !== $next ) { return 'state'; }
			if ( $observation === 4 ) { return 'admission_conflict'; }
			$row = $current;
		}
		return 'admission_conflict';
	}

	/** Names of the scan-only counters; the two site-wide health signals are excluded. */
	private static function scan_signal_names( $depth ) {
		$names = [ 'no_validated_clean_source', 'unverified_clean_source', 'drift_suspected', 'drift_verified', 'legacy_watermarked', 'settings_changed', 'last_operation_problem', 'non_terminal_journal', 'client_side_pending', 'backup_storage_bytes', 'missing_sizes', 'geometry_stale_sizes', 'unproducible_sizes', 'backup_absent', 'backup_present' ];
		if ( $depth === 'full' ) { $names[] = 'backup_validated'; }
		return $names;
	}

	/** Convert individually measured scanner facts to per-member contributions. */
	private static function scan_contributions( $facts, $depth, $before, $after ) {
		$values = array_fill_keys( self::scan_signal_names( $depth ), null );
		// A failed/changed closing observation cannot establish that the earlier
		// stored facts still describe this member; never publish their subtotal.
		if ( ! isset( $facts['consistency'] ) || $facts['consistency'] !== 'not_observed' ) { return $values; }
		$known = static function( $fact ) { return is_array( $fact ) && isset( $fact['state'] ) && $fact['state'] === 'known'; };
		$watermarked = isset( $facts['watermarked'] ) ? $facts['watermarked'] : null;
		$source = isset( $facts['clean_source']['availability'] ) ? $facts['clean_source']['availability'] : null;
		if ( $known( $watermarked ) ) {
			if ( ! $watermarked['value'] ) { $values['no_validated_clean_source'] = 0; $values['unverified_clean_source'] = 0; }
			elseif ( $known( $source ) && in_array( $source['value'], [ 'absent', 'present', 'validated' ], true ) ) {
				$values['no_validated_clean_source'] = (int) ( $depth === 'header' || $source['value'] !== 'validated' );
				$values['unverified_clean_source'] = (int) ( $source['value'] === 'present' );
			}
			if ( ! $watermarked['value'] ) { foreach ( [ 'drift_suspected', 'drift_verified', 'legacy_watermarked', 'settings_changed' ] as $key ) { $values[ $key ] = 0; } }
			elseif ( isset( $facts['drift']['state'], $facts['drift']['verifiable'], $facts['manifest'] ) && $known( $facts['drift']['state'] ) && $known( $facts['drift']['verifiable'] ) && $known( $facts['manifest'] ) ) {
				$state = $facts['drift']['state']['value']; $verifiable = $facts['drift']['verifiable']['value']; $manifest = $facts['manifest']['value'];
				$selected = $state === 'verified' && $verifiable ? 'drift_verified' : ( $state !== 'none' ? 'drift_suspected' : ( $manifest === 'legacy' ? 'legacy_watermarked' : ( $manifest === 'settings_changed' ? 'settings_changed' : '' ) ) );
				foreach ( [ 'drift_suspected', 'drift_verified', 'legacy_watermarked', 'settings_changed' ] as $key ) { $values[ $key ] = (int) ( $selected === $key ); }
			}
		}
		if ( $before === $after && isset( $before['state'] ) ) {
			if ( $before['state'] === 'absent' ) { $values['last_operation_problem'] = 0; }
			elseif ( $before['state'] === 'valid' ) { $values['last_operation_problem'] = (int) in_array( $before['outcome']['outcome'], [ 'failed', 'interrupted', 'partial' ], true ); }
		}
		if ( isset( $facts['operation']['incomplete'] ) && $known( $facts['operation']['incomplete'] ) ) { $values['non_terminal_journal'] = (int) (bool) $facts['operation']['incomplete']['value']; }
		if ( isset( $facts['client_side']['code'] ) && $known( $facts['client_side']['code'] ) ) { $values['client_side_pending'] = (int) in_array( $facts['client_side']['code']['value'], [ 'client_side_upload_pending', 'client_side_upload_incomplete', 'client_side_watermark_retry_pending', 'client_side_finalize_deferred' ], true ); }
		$backup = isset( $facts['backup']['availability'] ) ? $facts['backup']['availability'] : null;
		if ( $known( $backup ) && in_array( $backup['value'], [ 'absent', 'present', 'validated' ], true ) ) {
			foreach ( [ 'absent', 'present', 'validated' ] as $category ) { if ( array_key_exists( 'backup_' . $category, $values ) ) { $values[ 'backup_' . $category ] = (int) ( $backup['value'] === $category ); } }
			$bytes = isset( $facts['backup']['bytes'] ) ? $facts['backup']['bytes'] : null;
			if ( $known( $bytes ) && is_int( $bytes['value'] ) && $bytes['value'] >= 0 ) { $values['backup_storage_bytes'] = $bytes['value']; }
			elseif ( $backup['value'] === 'absent' && is_array( $bytes ) && $bytes['state'] === 'not_applicable' ) { $values['backup_storage_bytes'] = 0; }
		}
		if ( isset( $facts['sizes'], $facts['limits']['registered_total'] ) && is_array( $facts['sizes'] ) && is_int( $facts['limits']['registered_total'] ) && count( $facts['sizes'] ) === $facts['limits']['registered_total'] ) {
			foreach ( [ 'missing' => 'missing_sizes', 'geometry_stale' => 'geometry_stale_sizes', 'not_producible' => 'unproducible_sizes' ] as $state => $key ) {
				$found = false; $unknown = false;
				foreach ( $facts['sizes'] as $size ) { $fact = isset( $size['state'] ) ? $size['state'] : null; if ( ! $known( $fact ) ) { $unknown = true; } elseif ( $fact['value'] === $state ) { $found = true; } }
				$values[ $key ] = $found ? 1 : ( $unknown ? null : 0 );
			}
		}
		return $values;
	}

	/** Admit one read-only scan and settle only its unchanged fenced claim. */
	private static function execute_scan_claim( $store, $row, $run, $fence, $attempt, &$outcome ) {
		$admitted = self::admit_claim( $store, $row, $run, $fence, $attempt );
		if ( $admitted !== 'updated' ) { return $admitted === 'lost' ? 'admission_lost' : $admitted; }
		$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
		if ( $row['state'] !== 'valid' ) { return $row['state']; }
		$value = $row['value']; $claim = $value['claim']; $site = get_current_blog_id(); $user = get_current_user_id();
		if ( $value['id'] !== $run || $value['fence'] !== $fence || ! is_array( $claim ) || $claim['attempt'] !== $attempt || $claim['state'] !== 'executing' ) { return 'stale'; }
		$decision = self::recheck_claimed_member( $value['target'], $claim['attachment'], $value['bound'], true, 'scan', $value['scan_depth'], self::run_fingerprints( $value ) );
		if ( $decision['decision'] === 'unmeasured' ) {
			$value['status'] = 'paused'; $value['pause_reason'] = $decision['reason']; $value['claim'] = null; $value['updated'] = time();
			return $store->swap_exact( $row, $value );
		}
		$contributions = [];
		if ( $decision['decision'] === 'no_longer_matches' ) { $scan = [ 'outcome' => [ 'outcome' => 'skipped', 'code' => 'no_longer_matches', 'retryable' => false ] ]; }
		elseif ( $decision['decision'] === 'forbidden' ) { $scan = [ 'outcome' => [ 'outcome' => 'failed', 'code' => 'scan_forbidden', 'retryable' => false ] ]; $contributions = array_fill_keys( self::scan_signal_names( $value['scan_depth'] ), null ); }
		elseif ( $decision['decision'] === 'matches' ) {
			$handler = Image_Watermark()->get_upload_handler();
			$options = null;
			$fingerprints = $handler->runner_fingerprints( $options );
			if ( $fingerprints === false || $fingerprints['settings_fingerprint'] !== $value['settings_fingerprint'] || $fingerprints['sizes_fingerprint'] !== $value['sizes_fingerprint'] ) {
				$value['status'] = 'paused'; $value['pause_reason'] = $fingerprints === false ? 'scan_unmeasurable' : 'settings_changed'; $value['claim'] = null; $value['updated'] = time();
				return $store->swap_exact( $row, $value );
			}
			$before = $handler->observe_runner_summary( $claim['attachment'] );
			try {
				$scan = $handler->inspect_attachment_for_scan( $claim['attachment'], [ 'depth' => $value['scan_depth'], 'authorization' => [ 'site_id' => $site, 'user_id' => $user ] ], [ 'run' => $run, 'attempt' => $attempt ], $options );
				$after = $handler->observe_runner_summary( $claim['attachment'] );
				$closing_fingerprints = $handler->runner_fingerprints();
				if ( ! isset( $scan['facts'], $scan['outcome']['outcome'], $scan['outcome']['code'], $scan['outcome']['retryable'] ) || ! is_array( $scan['facts'] ) ) { throw new UnexpectedValueException(); }
				if ( in_array( $scan['outcome']['code'], [ 'attachment_not_found', 'scan_not_applicable' ], true ) ) { $contributions = []; }
				elseif ( $scan['outcome']['code'] === 'scan_forbidden' ) { $contributions = array_fill_keys( self::scan_signal_names( $value['scan_depth'] ), null ); }
				else { $contributions = self::scan_contributions( $scan['facts'], $value['scan_depth'], $before, $after ); }
				// Do not publish measurements under the run fingerprint if settings changed during the scan.
				if ( $closing_fingerprints !== $fingerprints ) { $contributions = array_fill_keys( self::scan_signal_names( $value['scan_depth'] ), null ); }
			} catch ( Throwable $e ) { $scan = []; }
		} else { $scan = []; }
		$result = isset( $scan['outcome'] ) && is_array( $scan['outcome'] ) ? $scan['outcome'] : [];
		$valid = isset( $result['outcome'], $result['code'], $result['retryable'] ) && is_bool( $result['retryable'] ) && in_array( $result['outcome'], [ 'complete', 'partial', 'skipped', 'failed', 'interrupted' ], true ) && is_string( $result['code'] ) && preg_match( '/\A[a-z0-9_-]{1,64}\z/D', $result['code'] );
		if ( $valid && $result['code'] === 'no_longer_matches' ) { $valid = $result['outcome'] === 'skipped' && ! $result['retryable']; }
		elseif ( $valid ) {
			$valid = in_array( $result['code'], [ 'scanned', 'attachment_not_found', 'scan_not_applicable', 'scan_forbidden', 'scan_unmeasurable', 'scan_incomplete', 'scan_concurrent_change', 'scan_interrupted' ], true ) && Image_Watermark()->get_upload_handler()->classify_operation_retryability( $result['outcome'], $result['code'] ) === $result['retryable'];
		}
		if ( ! $valid ) {
			$result = [ 'outcome' => 'interrupted', 'code' => 'scan_interrupted', 'retryable' => true ];
			$contributions = array_fill_keys( self::scan_signal_names( $value['scan_depth'] ), null );
		}
		for ( $tries = 0; $tries < 5; ++$tries ) {
			$current = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $current['state'] !== 'valid' || get_current_blog_id() !== $site || get_current_user_id() !== $user ) { return 'error'; }
			$v = $current['value'];
			if ( $v['id'] !== $run || $v['fence'] !== $fence || $v['claim'] !== $claim || $v['operation'] !== 'scan' || ! in_array( $v['status'], [ 'running', 'paused', 'stopped' ], true ) ) { return 'stale'; }
			$original_signals = $v['scan']['signals'];
			foreach ( $contributions as $name => $count ) {
				if ( ! array_key_exists( $name, $v['scan']['signals'] ) ) { continue; }
				if ( $count === null || $v['scan']['signals'][ $name ] > PHP_INT_MAX - $count ) { unset( $v['scan']['signals'][ $name ] ); }
				else { $v['scan']['signals'][ $name ] += $count; }
			}
			if ( $v['scan']['signals'] !== $original_signals ) { $v['scan']['updated'] = max( $v['scan']['updated'], time() ); }
			$code = $result['code']; $kind = $result['outcome'];
			$reason = isset( $v['reasons'][ $code ] ) ? $code : ( count( array_diff( array_keys( $v['reasons'] ), [ 'other' ] ) ) < 50 ? $code : 'other' );
			$old = isset( $v['reasons'][ $reason ] ) ? $v['reasons'][ $reason ] : 0;
			if ( $v['counters'][ $kind ] === PHP_INT_MAX || $old === PHP_INT_MAX ) {
				$v = $current['value'];
				if ( $v['status'] === 'running' ) { $v['status'] = 'paused'; $v['pause_reason'] = 'scan_counter_overflow'; }
				$v['claim'] = null; $v['updated'] = time();
				$swapped = $store->swap_exact( $current, $v );
				if ( $swapped === 'lost' ) { continue; }
				return $swapped;
			}
			++$v['counters'][ $kind ]; $v['reasons'][ $reason ] = $old + 1;
			$v['cursor'] = $claim['attachment']; $v['claim'] = null; $v['updated'] = time();
			if ( $kind !== 'complete' && count( $v['problems'] ) < 200 ) {
				$with_problem = $v; $with_problem['problems'][] = [ 'a' => $claim['attachment'], 'o' => $kind, 'c' => $code, 'r' => $result['retryable'], 't' => time() ];
				if ( Image_Watermark_Run_Store::active_problem_fits( $with_problem ) ) { $v = $with_problem; }
			}
			$swapped = $store->swap_exact( $current, $v );
			if ( $swapped === 'lost' ) { continue; }
			if ( $swapped === 'updated' ) { $outcome = [ 'operation' => 'scan', 'outcome' => $kind, 'code' => $code, 'message' => '', 'retryable' => $result['retryable'], 'sizes' => [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ] ]; }
			return $swapped;
		}
		return 'lost';
	}

	/** Admit and execute one mutation or scan. Recovery/status orchestration remains separate. */
	public static function execute_claim( $store, $run, $fence, $attempt, &$outcome = null ) {
		$outcome = null;
		if ( ! current_user_can( 'manage_options' ) || ! $store instanceof Image_Watermark_Run_Store ) { return 'forbidden'; }
		$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
		if ( $row['state'] !== 'valid' ) { return $row['state']; }
		$value = $row['value'];
		$claim = $value['claim'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence || ! is_array( $claim ) || $claim['fence'] !== $fence || $claim['attempt'] !== $attempt ) { return 'stale'; }
		if ( $value['status'] !== 'running' || $claim['state'] !== 'claimed' ) { return 'state'; }
		// An inherited regenerate claim is never executed or replayed.
		if ( $value['operation'] === 'regenerate' ) { return 'state'; }
		if ( $value['operation'] === 'scan' ) { return self::execute_scan_claim( $store, $row, $run, $fence, $attempt, $outcome ); }
		// A run-only refusal does not need, and must not fabricate, an R4 baseline.
		global $wpdb;
		$post = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $claim['attachment'] ), ARRAY_A );
		if ( $wpdb->last_error !== '' ) { return 'error'; }
		if ( ! $post || $post['post_type'] !== 'attachment' || ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
			$result = self::settle_run_only_decision( $store, $row, 'no_longer_matches' );
			if ( $result === 'updated' ) { $outcome = self::run_only_outcome( $value['operation'], 'no_longer_matches' ); }
			return $result;
		}
		if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $claim['attachment'] ) ) {
			$result = self::settle_run_only_decision( $store, $row, 'forbidden' );
			if ( $result === 'updated' ) { $outcome = self::run_only_outcome( $value['operation'], 'forbidden' ); }
			return $result;
		}
		$gate = self::ownership_refusal( $value['operation'], $claim['attachment'] );
		if ( $gate !== '' ) {
			$value['status'] = 'paused'; $value['pause_reason'] = $gate; $value['updated'] = time();
			$result = $store->swap_exact( $row, $value );
			return $result === 'updated' ? 'paused' : $result;
		}
		$admitted = self::admit_claim( $store, $row, $run, $fence, $attempt, $outcome, true );
		if ( $admitted === 'summary_settled' ) { return 'updated'; }
		if ( $admitted !== 'updated' ) { return $admitted === 'lost' ? 'admission_lost' : $admitted; }
		$row = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
		if ( $row['state'] !== 'valid' ) { return $row['state']; }
		$decision = null;
		$checked = self::settle_rechecked_member( $store, $row, $run, $fence, $attempt, $decision );
		if ( $checked !== 'matches' ) {
			if ( $checked === 'updated' && in_array( $decision, [ 'forbidden', 'no_longer_matches' ], true ) ) { $outcome = self::run_only_outcome( $row['value']['operation'], $decision ); }
			return $checked;
		}
		$value = $row['value'];
		$claim = $value['claim'];
		$site = get_current_blog_id();
		$runner_options = null;
		$revalidate = static function( $boundary, $attachment, $identity ) use ( $store, $run, $fence, $attempt, $site, $claim, $value, &$runner_options ) {
			if ( get_current_blog_id() !== $site || $attachment !== $claim['attachment'] || $identity !== [ 'run' => $run, 'attempt' => $attempt ] ) { return false; }
			$current = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $current['state'] !== 'valid' ) { return false; }
			$v = $current['value'];
			if ( $v['id'] !== $run || $v['fence'] !== $fence || ! in_array( $v['status'], [ 'running', 'paused', 'stopped' ], true ) || $v['claim'] !== $claim ) { return false; }
			$effective_options = null;
			$fingerprints = Image_Watermark()->get_upload_handler()->runner_fingerprints( $effective_options );
			if ( $fingerprints === false || $fingerprints['settings_fingerprint'] !== $v['settings_fingerprint'] || $fingerprints['sizes_fingerprint'] !== $v['sizes_fingerprint'] || $fingerprints['settings_fingerprint'] !== $value['settings_fingerprint'] || $fingerprints['sizes_fingerprint'] !== $value['sizes_fingerprint'] ) {
				if ( $v['status'] === 'running' ) { self::settings_transition( $store, $current, $run, $fence, 'check' ); }
				return false;
			}
			$latest = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			$valid = $latest['state'] === 'valid' && $latest['value']['id'] === $run && $latest['value']['fence'] === $fence && in_array( $latest['value']['status'], [ 'running', 'paused', 'stopped' ], true ) && $latest['value']['claim'] === $claim && $latest['value']['settings_fingerprint'] === $fingerprints['settings_fingerprint'] && $latest['value']['sizes_fingerprint'] === $fingerprints['sizes_fingerprint'];
			// The operation must use the exact effective snapshot checked under its lease,
			// not the singleton options loaded before a concurrent settings save.
			if ( $valid && $boundary === 'before_predecessor' ) { $runner_options = $effective_options; }
			return $valid;
		};
		$request = $value['operation'] === 'regenerate' ? [] : [ 'method' => 'manual' ];
		$dispatch = Image_Watermark()->get_upload_handler()->execute_runner_operation( $value['operation'], $claim['attachment'], $request, [ 'run' => $run, 'attempt' => $attempt, 'revalidate' => $revalidate ], $runner_options );
		for ( $tries = 0; $tries < 5; ++$tries ) {
			$current = $store->read_row( Image_Watermark_Run_Store::ACTIVE );
			if ( $current['state'] !== 'valid' ) { return $current['state']; }
			$v = $current['value'];
			if ( $v['id'] !== $run || $v['fence'] !== $fence || $v['claim'] !== $claim ) { return 'stale'; }
			if ( $dispatch['no_work'] ) {
				$v['claim'] = null;
				if ( $v['status'] === 'running' && $dispatch['no_work'] !== 'before_predecessor' ) { $v['status'] = 'paused'; $v['pause_reason'] = $dispatch['no_work']; }
				$v['updated'] = time();
				$result = $store->swap_exact( $current, $v );
				if ( $result === 'updated' && $dispatch['no_work'] === 'lease_read_error' ) { return 'error'; }
			} else {
				$result = self::reconcile_terminal_claim( $store, $current, $run, $attempt );
			}
			if ( $result !== 'lost' ) {
				if ( $result === 'updated' && ! $dispatch['no_work'] && isset( $dispatch['result']['outcome'] ) && is_array( $dispatch['result']['outcome'] ) ) {
					$observed = $dispatch['result']['outcome'];
					$outcome = array_intersect_key( $observed, array_flip( [ 'operation', 'outcome', 'code', 'message', 'retryable' ] ) );
					$outcome['sizes'] = [];
					foreach ( [ 'processed', 'skipped', 'failed' ] as $kind ) { $outcome['sizes'][ $kind ] = isset( $observed['sizes'][ $kind ] ) && is_array( $observed['sizes'][ $kind ] ) ? count( $observed['sizes'][ $kind ] ) : 0; }
				}
				return $result;
			}
		}
		return 'lost';
	}

	/**
	 * Reconcile terminal evidence only; repair and abandoned recovery are separate.
	 * No attachment write or replay is authorized by this observation.
	 */
	public static function reconcile_terminal_claim( $store, $row, $run, $attempt, $pending_without_evidence = false, $allow_pause = true, $observation = null ) {
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		$claim = $value['claim'];
		if ( $value['id'] !== $run || ! is_array( $claim ) || $claim['attempt'] !== $attempt || $claim['state'] !== 'executing' ) { return 'stale'; }
		if ( $value['operation'] === 'scan' || $claim['attachment'] <= $value['cursor'] || $claim['attachment'] > $value['bound'] ) { return 'invalid'; }
		$handler = Image_Watermark()->get_upload_handler();
		if ( $observation !== null ) {
			if ( $observation['state'] !== 'ok' ) { return 'error'; }
			$journal = $observation['journal'] === false ? [] : $handler->runner_terminal_from_journal( $observation['journal']['value'], [ 'run' => $run, 'attempt' => $attempt ] );
			$summary = $observation['summary'];
		} else {
			$query_error = false;
			$journal = $handler->describe_terminal_operation_record( $claim['attachment'], $run, $attempt, $query_error );
			if ( $query_error ) { return 'error'; }
			$summary = $handler->observe_runner_summary( $claim['attachment'] );
			if ( $summary['state'] === 'error' ) { return 'error'; }
		}
		$s = $summary['state'] === 'valid' ? $summary['outcome'] : null;
		$raw_summary = $s ? @unserialize( $summary['raw'], [ 'allowed_classes' => false ] ) : null;
		$classification = $s ? $handler->classify_operation_retryability( $s['outcome'], $s['code'] ) : null;
		$classified_summary = $s && is_array( $raw_summary ) && isset( $raw_summary['retryable'] ) && is_bool( $raw_summary['retryable'] ) && $classification !== null && $s['retryable'] === $classification;
		$same_identity = $s && $s['run'] === $run && $s['attempt'] === $attempt && $s['operation'] === $value['operation'];
		$matching = $classified_summary && $same_identity;
		$result = null;
		$unclassified = $same_identity && $classification === null;
		if ( $journal && $journal['operation'] === $value['operation'] ) {
			$journal_code = $journal['code'];
			$summary_code = $s ? $s['code'] : '';
			if ( $journal['outcome'] === 'complete' && in_array( $journal_code, [ 'manifest_unverified', 'watermarked' ], true ) && in_array( $summary_code, [ 'manifest_unverified', 'watermarked' ], true ) ) { $summary_code = $journal_code; }
			$agrees = $matching && $s['retryable'] === $journal['retryable'] && $s['outcome'] === $journal['outcome'] && $summary_code === $journal_code;
			if ( $journal['retryable'] === null ) { $unclassified = true; }
			elseif ( $same_identity && ( $classification === null || ( isset( $raw_summary['retryable'] ) && ! $agrees ) ) ) { /* Conflicting evidence cannot settle. */ }
			elseif ( $journal['retryable'] === false ) { $result = $journal; }
			else {
				$later = $classified_summary && $summary['digest'] !== $claim['summary_before'] && $s['run'] === $run && $s['operation'] === $value['operation'] && is_int( $s['attempt'] ) && $s['attempt'] > $attempt && $s['attempt'] < $value['next_attempt'];
				if ( $agrees || $later ) { $result = $journal; }
			}
		} elseif ( ! $journal && $matching && $s['retryable'] === false ) { $result = $s; }
		if ( ! $result ) {
			// An in-flight attempt may not have published its own evidence yet.
			if ( $pending_without_evidence && ! $journal && ! $same_identity ) { return 'pending'; }
			if ( $allow_pause && $value['status'] === 'running' ) {
				if ( is_callable( $allow_pause ) && ! $allow_pause() ) { return 'iw_run_item_forbidden'; }
				$value['status'] = 'paused';
				$value['pause_reason'] = $unclassified ? 'unclassified_outcome' : 'summary_unconfirmed';
				$value['updated'] = time();
				$swapped = $store->swap_exact( $row, $value );
				if ( $swapped !== 'updated' ) { return $swapped; }
			}
			return $unclassified ? 'unclassified_outcome' : 'summary_unconfirmed';
		}
		return self::settle_claim_result( $store, $row, $result );
	}

	/** Count a confirmed result once, conditional on the exact observed claim. */
	private static function settle_claim_result( $store, $row, $result ) {
		$value = $row['value'];
		$claim = $value['claim'];
		$outcome = $result['outcome']; $code = $result['code'];
		$reason = isset( $value['reasons'][ $code ] ) ? $code : ( count( array_diff( array_keys( $value['reasons'] ), [ 'other' ] ) ) < 50 ? $code : 'other' );
		$old_reason = isset( $value['reasons'][ $reason ] ) ? $value['reasons'][ $reason ] : 0;
		if ( $value['counters'][ $outcome ] === PHP_INT_MAX || $old_reason === PHP_INT_MAX ) { return 'error'; }
		++$value['counters'][ $outcome ];
		$value['reasons'][ $reason ] = $old_reason + 1;
		$value['cursor'] = $claim['attachment'];
		$value['claim'] = null;
		if ( $value['pause_reason'] === 'summary_unconfirmed' ) { $value['pause_reason'] = ''; }
		$value['updated'] = time();
		if ( $outcome !== 'complete' && count( $value['problems'] ) < 200 ) {
			$with_problem = $value;
			$with_problem['problems'][] = [ 'a' => $claim['attachment'], 'o' => $outcome, 'c' => $code, 'r' => $result['retryable'], 't' => time() ];
			if ( Image_Watermark_Run_Store::active_problem_fits( $with_problem ) ) { $value = $with_problem; }
		}
		return $store->swap_exact( $row, $value );
	}

	/**
	 * Settle only a matching admitted member's known pre-operation refusal.
	 * No attachment outcome is written, and unknown membership stays unresolved.
	 */
	public static function settle_rechecked_member( $store, $row, $run, $fence, $attempt, &$decision = null ) {
		$decision = null;
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		$claim = $value['claim'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence || ! is_array( $claim ) || $claim['fence'] !== $fence || $claim['attempt'] !== $attempt || $claim['state'] !== 'executing' ) { return 'stale'; }
		if ( $claim['attachment'] <= $value['cursor'] || $claim['attachment'] > $value['bound'] ) { return 'invalid'; }
		$observation = self::recheck_claimed_member( $value['target'], $claim['attachment'], $value['bound'], true, $value['operation'], isset( $value['scan_depth'] ) ? $value['scan_depth'] : null, self::run_fingerprints( $value ) );
		$decision = $observation['decision'];
		if ( $decision === 'matches' ) { return $decision; }
		if ( $decision === 'unmeasured' ) {
			$value['status'] = 'paused';
			$value['pause_reason'] = $observation['reason'];
			$value['claim'] = null;
			$value['updated'] = time();
			return $store->swap_exact( $row, $value );
		}
		return self::settle_run_only_decision( $store, $row, $decision );
	}

	/** Path-free result for an established refusal that never invoked an operation. */
	private static function run_only_outcome( $operation, $decision ) {
		return [ 'operation' => $operation, 'outcome' => $decision === 'forbidden' ? 'failed' : 'skipped', 'code' => $decision === 'forbidden' ? 'iw_run_item_forbidden' : 'no_longer_matches', 'message' => '', 'retryable' => false, 'sizes' => [ 'processed' => 0, 'skipped' => 0, 'failed' => 0 ] ];
	}

	/** Settle a proved no-operation decision from its exact claim row. */
	private static function settle_run_only_decision( $store, $row, $decision ) {
		$value = $row['value'];
		$claim = $value['claim'];
		if ( ! is_array( $claim ) || ! in_array( $decision, [ 'forbidden', 'no_longer_matches' ], true ) ) { return 'invalid'; }
		$outcome = $decision === 'forbidden' ? 'failed' : 'skipped';
		$code = $decision === 'forbidden' ? 'iw_run_item_forbidden' : 'no_longer_matches';
		$reason = isset( $value['reasons'][ $code ] ) ? $code : ( count( array_diff( array_keys( $value['reasons'] ), [ 'other' ] ) ) < 50 ? $code : 'other' );
		$old_reason = isset( $value['reasons'][ $reason ] ) ? $value['reasons'][ $reason ] : 0;
		if ( $value['counters'][ $outcome ] === PHP_INT_MAX || $old_reason === PHP_INT_MAX ) { return 'error'; }
		++$value['counters'][ $outcome ];
		$value['reasons'][ $reason ] = $old_reason + 1;
		$value['cursor'] = $claim['attachment'];
		$value['claim'] = null;
		$value['updated'] = time();
		if ( count( $value['problems'] ) < 200 ) {
			$with_problem = $value;
			$with_problem['problems'][] = [ 'a' => $claim['attachment'], 'o' => $outcome, 'c' => $code, 'r' => false, 't' => time() ];
			if ( Image_Watermark_Run_Store::active_problem_fits( $with_problem ) ) { $value = $with_problem; }
		}
		return $store->swap_exact( $row, $value );
	}

	/**
	 * Compare or explicitly accept current settings against an observed active row.
	 * The caller must still gate claim/admission; this helper does not execute work.
	 */
	public static function settings_transition( $store, $row, $run, $fence, $action ) {
		if ( ! $store instanceof Image_Watermark_Run_Store || ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['value'], $row['site'], $row['raw'] ) || $row['state'] !== 'valid' || $row['name'] !== Image_Watermark_Run_Store::ACTIVE || $row['site'] !== get_current_blog_id() || ! Image_Watermark_Run_Store::valid_active( $row['value'] ) || @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) !== $row['value'] ) { return 'invalid'; }
		$value = $row['value'];
		if ( $value['id'] !== $run || $value['fence'] !== $fence ) { return 'stale'; }
		if ( ! in_array( $action, [ 'check', 'resume', 'continue_with_new_settings' ], true ) ) { return 'invalid'; }
		// Resume and Continue are refused for an inherited regenerate run.
		if ( $action !== 'check' && $value['operation'] === 'regenerate' ) { return 'state'; }
		if ( $action === 'check' && $value['status'] !== 'running' ) { return 'state'; }
		if ( $action !== 'check' && $value['status'] !== 'paused' ) { return 'state'; }
		if ( $action !== 'check' && is_array( $value['claim'] ) && $value['claim']['state'] === 'executing' ) { return 'state'; }
		if ( $action === 'resume' && $value['pause_reason'] === 'settings_changed' ) { return 'settings_changed'; }
		if ( $action === 'resume' && $value['pause_reason'] === 'ownership_ceiling_exceeded' ) {
			$exceeded = self::ownership_ceiling();
			if ( $exceeded === null ) { return 'metadata_read_failed'; }
			if ( $exceeded ) { return 'state'; }
		}
		if ( $action === 'continue_with_new_settings' && ( $value['operation'] === 'scan' || $value['pause_reason'] !== 'settings_changed' ) ) { return 'state'; }
		$fingerprints = Image_Watermark()->get_upload_handler()->runner_fingerprints();
		if ( $fingerprints === false ) {
			if ( $action !== 'check' ) { return 'metadata_read_failed'; }
			$value['status'] = 'paused';
			$value['pause_reason'] = 'metadata_read_failed';
			$value['updated'] = time();
			return $store->swap_exact( $row, $value );
		}
		$changed = $value['settings_fingerprint'] !== $fingerprints['settings_fingerprint'] || $value['sizes_fingerprint'] !== $fingerprints['sizes_fingerprint'];
		if ( $action === 'check' && ! $changed ) {
			// Even a no-op is conditional on the exact row still being authoritative.
			return $store->swap_exact( $row, $value );
		}
		if ( $action === 'check' || ( $action === 'resume' && $changed ) ) {
			$value['status'] = 'paused';
			$value['pause_reason'] = 'settings_changed';
		} else {
			$value['status'] = 'running';
			$value['pause_reason'] = '';
			if ( $action === 'continue_with_new_settings' ) {
				$value['settings_fingerprint'] = $fingerprints['settings_fingerprint'];
				$value['sizes_fingerprint'] = $fingerprints['sizes_fingerprint'];
			}
		}
		$value['updated'] = time();
		return $store->swap_exact( $row, $value );
	}

	/**
	 * Observe a bounded traversal. matching_count and all metadata observations
	 * describe only the examined prefix when certainty is partial_observation;
	 * exact_observation means traversal exhausted the frozen bound. Each nested
	 * estimate carries its own certainty, estimated_at and source. selected_count
	 * and selected_within_bound are exact input counts, independent of traversal.
	 * The reply describes only this estimate; it carries no historical scan
	 * figures (A-P04-11).
	 */
	public static function preview( $target, $bound, $operation = null, $scan_depth = null ) {
		$scope = self::scope_fingerprint( $target );
		if ( $scope === false || ! is_int( $bound ) || $bound < 0 || ( $operation !== null && ! in_array( $operation, [ 'apply', 'remove', 'scan' ], true ) ) ) { return false; }
		$result = [ 'estimated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'source' => 'query', 'bound' => $bound, 'scope_fingerprint' => $scope, 'certainty' => 'partial_observation', 'complete' => false, 'examined_count' => 0, 'cursor' => 0, 'unmeasured_estimates' => [ 'eligible', 'will_skip_by_reason', 'needs_attention' ] ];
		if ( $operation !== null ) { $result['operation'] = $operation; }
		if ( $target['type'] === 'ids' ) {
			$result['selected_count_certainty'] = 'exact_observation';
			$result['selected_count'] = count( $target['ids'] );
			$result['selected_within_bound'] = count( array_filter( $target['ids'], static function( $id ) use ( $bound ) { return $id <= $bound; } ) );
		}
		$filters = $target['type'] === 'filters' ? $target['filters'] : [];
		$unmeasured = array_values( array_diff( array_keys( $filters ), [ 'date_from', 'date_to', 'parent', 'mime', 'watermarked', 'state', 'backup', 'clean_source', 'condition', 'last_outcome', 'last_operation', 'journal', 'client_side', 'problems_of_run' ] ) );
		if ( $unmeasured ) { $result['unmeasured_predicates'] = $unmeasured; return $result; }
		$file_predicates = $operation === 'apply' ? array_values( array_intersect( [ 'backup', 'clean_source', 'condition' ], array_keys( $filters ) ) ) : [];
		if ( $file_predicates ) { $result['unmeasured_predicates'] = $file_predicates; }
		global $wpdb;
		$deadline = microtime( true ) + 2;
		$maximum = (int) ini_get( 'max_execution_time' );
		if ( $maximum > 0 && isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) { $deadline = min( $deadline, (float) $_SERVER['REQUEST_TIME_FLOAT'] + $maximum - 2 ); }
		$cursor = 0;
		$examined = 0;
		$complete = false;
		$count = 0;
		$missing = 0;
		$unsupported = 0;
		$flagged = 0;
		$unfinalized = 0;
		$pending = 0;
		$blocked = 0;
		$client_complete = true;
		$stored_attention = array_fill_keys( [ 'legacy', 'drift_suspected', 'drift_verified', 'settings_changed', 'non_terminal_journal' ], 0 );
		$attention_complete = array_fill_keys( array_keys( $stored_attention ), true );
		$gate_counts = [];
		$gate_complete = [];
		$gate_names = $operation === 'scan' || $operation === null ? [] : [ 'invalid_metadata', 'no_engine', 'is_watermark_source', 'manual_disabled' ];
		if ( $operation === 'apply' || $operation === 'regenerate' ) { $gate_names = array_merge( $gate_names, [ 'rotation_unavailable', 'invalid_custom_dimensions', 'watermark_text_empty', 'no_selected_sizes', 'display_veto' ] ); }
		if ( $operation === 'regenerate' ) { $gate_names[] = 'client_side_finalize_deferred'; }
		foreach ( $gate_names as $key ) { $gate_counts[ $key ] = 0; $gate_complete[ $key ] = true; }
		try { $gate_options = $gate_names ? apply_filters( 'iw_watermark_options', Image_Watermark()->options ) : null; }
		catch ( Throwable $e ) { $gate_options = false; }
		while ( $cursor < $bound ) {
			if ( microtime( true ) >= $deadline ) { goto preview_done; }
			$page = self::traversal_page( $target, $cursor, $bound );
			if ( $page === false ) { goto preview_done; }
			if ( ! $page ) { break; }
			$ids = array_keys( $page );
			$prime = [];
			foreach ( $page as $id => $post ) {
				if ( microtime( true ) >= $deadline ) { goto preview_done; }
				if ( ! $post ) { continue; }
				if ( $post['post_type'] !== 'attachment' || ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) { continue; }
				if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) { goto preview_done; }
				$prime[] = $id;
			}
			// Priming is bounded to this page; raw rows below remain authoritative.
			if ( $prime && ( update_meta_cache( 'post', $prime ) === false || $wpdb->last_error !== '' ) ) {
				foreach ( $prime as $id ) { wp_cache_delete( $id, 'post_meta' ); }
				goto preview_done;
			}
			$stored = [];
			if ( isset( $filters['state'] ) || isset( $filters['journal'] ) || isset( $filters['client_side'] ) || $operation !== null ) {
				$stored = Image_Watermark()->get_upload_handler()->runner_stored_facts_batch( $prime, $deadline );
				if ( $stored === null ) { goto preview_done; }
				if ( $stored === false ) { $result['unmeasured_reason'] = 'metadata_read_failed'; goto preview_done; }
			}
			$flags = [];
			if ( ( array_key_exists( 'watermarked', $filters ) || $operation !== null ) && $prime ) {
				$placeholders = implode( ',', array_fill( 0, count( $prime ), '%d' ) );
				$sql = $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = %s", array_merge( $prime, [ 'iw-is-watermarked' ] ) );
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { goto preview_done; }
				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) { goto preview_done; }
					$id = (int) $row['post_id'];
					if ( isset( $flags[ $id ] ) || ! is_scalar( maybe_unserialize( $row['meta_value'] ) ) ) { goto preview_done; }
					$flags[ $id ] = [ (int) maybe_unserialize( $row['meta_value'] ) === 1 ];
				}
			}
			$summaries = [];
			if ( $prime && ( isset( $filters['last_outcome'] ) || isset( $filters['last_operation'] ) || isset( $filters['problems_of_run'] ) ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $prime ), '%d' ) );
				$sql = $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = %s", array_merge( $prime, [ '_iw_last_operation' ] ) );
				$rows = $wpdb->get_results( $sql, ARRAY_A );
				if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { goto preview_done; }
				foreach ( $rows as $row ) {
					if ( microtime( true ) >= $deadline ) { goto preview_done; }
					$summaries[ (int) $row['post_id'] ][] = $row['meta_value'];
				}
			}
			$parents = [];
			if ( isset( $filters['parent'] ) && $filters['parent'] !== 'unattached' ) {
				if ( ! post_type_exists( $filters['parent'] ) ) { goto preview_done; }
				$parent_ids = [];
				foreach ( $page as $post ) { if ( $post && (int) $post['post_parent'] > 0 ) { $parent_ids[] = (int) $post['post_parent']; } }
				$parent_ids = array_values( array_unique( $parent_ids ) );
				if ( $parent_ids ) {
					$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders)", $parent_ids ), ARRAY_A );
					if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { goto preview_done; }
					foreach ( $rows as $row ) {
						if ( microtime( true ) >= $deadline ) { goto preview_done; }
						$parents[ (int) $row['ID'] ] = $row['post_type'];
					}
				}
			}
			foreach ( $page as $id => $post ) {
				if ( microtime( true ) >= $deadline ) { goto preview_done; }
				if ( ! $post ) { if ( $target['type'] === 'ids' ) { ++$missing; } goto preview_next; }
				if ( $post['post_type'] !== 'attachment' || ! in_array( $post['post_mime_type'], [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
					if ( $target['type'] === 'ids' ) { ++$unsupported; }
					goto preview_next;
				}
				$date = substr( $post['post_date'], 0, 10 );
				if ( isset( $filters['date_from'] ) && $date < $filters['date_from'] ) { goto preview_next; }
				if ( isset( $filters['date_to'] ) && $date > $filters['date_to'] ) { goto preview_next; }
				if ( isset( $filters['mime'] ) && ! in_array( $post['post_mime_type'], $filters['mime'], true ) ) { goto preview_next; }
				if ( array_key_exists( 'watermarked', $filters ) && ( isset( $flags[ $id ] ) ? $flags[ $id ][0] : false ) !== $filters['watermarked'] ) { goto preview_next; }
				if ( isset( $filters['parent'] ) && ( $filters['parent'] === 'unattached' ? (int) $post['post_parent'] !== 0 : ( ! isset( $parents[ (int) $post['post_parent'] ] ) || $parents[ (int) $post['post_parent'] ] !== $filters['parent'] ) ) ) { goto preview_next; }
				if ( isset( $filters['last_outcome'] ) || isset( $filters['last_operation'] ) || isset( $filters['problems_of_run'] ) ) {
					$matches = self::summary_matches( $filters, isset( $summaries[ $id ] ) ? $summaries[ $id ] : [] );
					if ( $matches === null ) { goto preview_done; }
					if ( ! $matches ) { goto preview_next; }
				}
				if ( isset( $filters['state'] ) || isset( $filters['journal'] ) || isset( $filters['client_side'] ) ) {
					$reason = 'metadata_read_failed';
					$matches = self::stored_matches( $filters, $id, $reason, $stored[ $id ] );
					if ( $matches === null ) { $result['unmeasured_reason'] = $reason; goto preview_done; }
					if ( ! $matches ) { goto preview_next; }
				}
				// Apply preview never inspects files; stop rather than count a broader target.
				if ( $file_predicates ) {
					$result['unmeasured_reason'] = 'scan_unmeasurable';
					goto preview_done;
				}
				$file_decision = self::file_matches( $filters, $id, $operation, $scan_depth, $deadline );
				if ( $file_decision['decision'] === 'unmeasured' ) { $result['unmeasured_reason'] = $file_decision['reason']; goto preview_done; }
				if ( $file_decision['decision'] === 'forbidden' ) { goto preview_done; }
				if ( $file_decision['decision'] !== 'matches' ) { goto preview_next; }
				$is_flagged = isset( $flags[ $id ] ) && $flags[ $id ][0];
				if ( $operation !== null ) {
					$facts = $stored[ $id ];
					// Do not combine a flag read with a different authoritative snapshot.
					if ( $facts['watermarked']['state'] !== 'known' || $facts['watermarked']['value'] !== $is_flagged ) { $result['unmeasured_reason'] = 'metadata_read_failed'; goto preview_done; }
					foreach ( $stored_attention as $key => $ignored ) {
						$reason = 'invalid_metadata';
						$filter = $key === 'non_terminal_journal' ? [ 'journal' => 'non_terminal' ] : [ 'state' => [ $key ] ];
						$match = self::stored_matches( $filter, $id, $reason, $facts );
						if ( $match === null ) { $attention_complete[ $key ] = false; }
						elseif ( $match ) { ++$stored_attention[ $key ]; }
					}
				}
				if ( in_array( $operation, [ 'apply', 'remove', 'regenerate' ], true ) ) {
					$metadata = $facts[ $operation === 'regenerate' ? 'runner_regenerate_metadata' : 'runner_metadata' ];
					if ( $metadata['state'] !== 'known' ) { $gate_complete['invalid_metadata'] = false; }
					elseif ( ! $metadata['value'] ) { ++$gate_counts['invalid_metadata']; }
					if ( $operation === 'regenerate' && $facts['runner_deferred_payload']['value'] ) { ++$gate_counts['client_side_finalize_deferred']; }
					// Regeneration applies watermark settings and the veto only to flagged items.
					$settings_apply = $operation !== 'regenerate' || $is_flagged;
					if ( $settings_apply ) {
						try { $gates = Image_Watermark()->get_upload_handler()->runner_settings_gates( $id, $gate_options ); }
						catch ( Throwable $e ) { $gates = false; }
						foreach ( $gate_names as $key ) {
							if ( ! in_array( $key, [ 'no_engine', 'rotation_unavailable', 'is_watermark_source', 'manual_disabled', 'invalid_custom_dimensions', 'watermark_text_empty', 'no_selected_sizes' ], true ) ) { continue; }
							if ( $gates === false ) { $gate_complete[ $key ] = false; }
							elseif ( $gates[ $key ] ) { ++$gate_counts[ $key ]; }
						}
						if ( isset( $gate_counts['display_veto'] ) ) {
							try { if ( apply_filters( 'iw_watermark_display', $id ) === false ) { ++$gate_counts['display_veto']; } }
							catch ( Throwable $e ) { $gate_complete['display_veto'] = false; }
						}
					}
					$client = $facts['client_side'];
					$replacement = $client['unfinalized_replacement'];
					$claim = $client['state'];
					$known_replacement = $replacement['state'] === 'known';
					$known_claim = $claim['state'] === 'known';
					if ( ! $known_replacement || ( $operation === 'regenerate' && ! $known_claim ) ) { $client_complete = false; }
					$is_unfinalized = $known_replacement && $replacement['value'];
					$is_pending = $operation === 'regenerate' && $known_claim && $claim['value'] === 'pending';
					if ( $is_unfinalized ) { ++$unfinalized; }
					if ( $is_pending ) { ++$pending; }
					if ( $is_unfinalized || $is_pending ) { ++$blocked; }
				}
				++$count;
				if ( $operation !== null && $is_flagged ) { ++$flagged; }
				preview_next:
				$cursor = $id;
				++$examined;
			}
		}
		$complete = true;
		$cursor = $bound;
		if ( $target['type'] === 'ids' ) { $missing += $result['selected_count'] - $result['selected_within_bound']; }
		preview_done:
		$result['complete'] = $complete;
		$result['examined_count'] = $examined;
		$result['cursor'] = $cursor;
		$result['certainty'] = $complete ? 'exact_observation' : 'partial_observation';
		$result['matching_count'] = $count;
		if ( $operation !== null && $complete ) {
			// Membership is only an upper bound: operation and file eligibility remain unmeasured.
			$result['eligible_upper_bound'] = $count;
		}
		if ( $operation === 'regenerate' ) {
			// Flagged images are re-rendered with current settings; source validity is not observed here.
			$result['attention_observations'] = [ 'watermarked_regenerate' => $flagged ];
		} elseif ( $operation === 'apply' ) {
			$result['attention_observations'] = [ 'watermarked_reapply' => $flagged ];
		}
		if ( $target['type'] === 'ids' ) {
			$result['missing_count'] = $missing;
			$result['will_skip_by_reason'] = [ 'no_longer_matches' => $missing + $unsupported ];
			// Other operation-specific skip reasons remain unmeasured.
		}
		if ( $operation !== null ) {
			// These observations describe the examined prefix, not predicted outcomes.
			$stamp = [ 'certainty' => $result['certainty'], 'estimated_at' => $result['estimated_at'], 'source' => 'query' ];
			$estimate = static function( $value ) use ( $stamp ) { return array_merge( $stamp, [ 'value' => $value ] ); };
			$upper = $operation === 'scan' ? $count : $count - $blocked;
			$result['metadata_estimates'] = [
				'will_skip_by_reason' => $target['type'] === 'ids' ? [ 'no_longer_matches' => $estimate( $missing + $unsupported ) ] : [],
				'needs_attention' => [],
				'unmeasured' => [ 'eligible', 'will_skip_by_reason', 'needs_attention', 'file_eligibility', 'shared_aliases' ],
			];
			if ( $operation !== 'scan' ) {
				$result['metadata_estimates']['unmeasured'][] = 'watermark_source_and_font_availability';
				$result['metadata_estimates']['unmeasured'][] = 'clean_source_availability';
			} else {
				$result['metadata_estimates']['unmeasured'][] = 'scan_classification';
			}
			if ( $operation === 'apply' || $operation === 'regenerate' ) {
				$result['metadata_estimates']['needs_attention'][ $operation === 'apply' ? 'watermarked_reapply' : 'watermarked_regenerate' ] = $estimate( $flagged );
			} elseif ( $operation === 'remove' ) {
				$result['metadata_estimates']['needs_attention']['watermarked_remove'] = $estimate( $flagged );
			}
			// Independent gate observations may overlap and do not predict refusal precedence.
			try { $settings_stable = ! $gate_names || apply_filters( 'iw_watermark_options', Image_Watermark()->options ) === $gate_options; }
			catch ( Throwable $e ) { $settings_stable = false; }
			if ( $complete && ( $operation === 'scan' || $client_complete ) ) {
				$result['metadata_estimates']['eligible_upper_bound'] = array_merge( $stamp, [ 'certainty' => 'upper_bound', 'value' => $upper ] );
			}
			foreach ( $gate_counts as $key => $value ) {
				if ( ! $settings_stable && ! in_array( $key, [ 'invalid_metadata', 'client_side_finalize_deferred' ], true ) ) { $gate_complete[ $key ] = false; }
				if ( $gate_complete[ $key ] ) { $result['metadata_estimates']['gate_observations'][ $key ] = $estimate( $value ); }
				else { $result['metadata_estimates']['unmeasured'][] = $key; }
			}
			foreach ( $stored_attention as $key => $value ) {
				if ( $attention_complete[ $key ] ) { $result['metadata_estimates']['needs_attention'][ $key ] = $estimate( $value ); }
				else { $result['metadata_estimates']['unmeasured'][] = $key; }
			}
			if ( $operation !== 'scan' ) {
				if ( $client_complete ) {
					$result['metadata_estimates']['needs_attention']['client_side_upload_unfinalized'] = $estimate( $unfinalized );
					if ( $operation === 'regenerate' ) { $result['metadata_estimates']['needs_attention']['client_side_upload_pending'] = $estimate( $pending ); }
				} else {
					$result['metadata_estimates']['unmeasured'][] = 'client_side_state';
				}
			}
		}
		return $result;
	}
}
