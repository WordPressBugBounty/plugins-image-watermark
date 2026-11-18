<?php
/**
 * Internal run-option storage.
 *
 * Loaded by the plugin; no run service writes state until a later slice.
 *
 * @package Image_Watermark
 */

class Image_Watermark_Run_Store {
	const ACTIVE = 'image_watermark_active_run';
	const HISTORY = 'image_watermark_run_history';
	/** Finished runs any history write keeps (A-P04-11); reads accept up to 20. */
	const HISTORY_RUNS = 10;

	/** Classify the R6b source tuple without a retained-run lookup. */
	public static function health_source_type( $source, $depth, $bound ) {
		if ( ! self::id( $source ) || ! is_string( $depth ) || ! is_int( $bound ) || $bound < 0 ) {
			return false;
		}
		if ( $source === 'query' && $depth === '' && $bound === 0 ) {
			return 'query';
		}
		return in_array( $depth, [ 'header', 'full' ], true ) ? 'scan' : false;
	}

	private static function id( $value ) {
		return is_string( $value ) && $value !== '' && strlen( $value ) <= 64 && ! preg_match( '/[^\x00-\x7f]/', $value );
	}

	private static function code( $value, $empty = false ) {
		return is_string( $value ) && ( ( $empty && $value === '' ) || ( strlen( $value ) <= 64 && preg_match( '/\A[a-z0-9_-]+\z/D', $value ) ) );
	}

	private static function number( $value, $minimum = 0 ) {
		return is_int( $value ) && $value >= $minimum;
	}

	private static function hex( $value, $length ) {
		return is_string( $value ) && strlen( $value ) === $length && preg_match( '/\A[0-9a-f]+\z/D', $value );
	}

	private static function keys( $value, $required, $optional = [] ) {
		return is_array( $value ) && ! array_diff( $required, array_keys( $value ) ) && ! array_diff( array_keys( $value ), array_merge( $required, $optional ) );
	}

	private static function is_list( $value ) {
		return is_array( $value ) && ( $value === [] || array_keys( $value ) === range( 0, count( $value ) - 1 ) );
	}

	private static function signal_names() {
		return [ 'no_validated_clean_source', 'unverified_clean_source', 'drift_suspected', 'drift_verified', 'legacy_watermarked', 'settings_changed', 'last_operation_problem', 'non_terminal_journal', 'client_side_pending', 'sizes_unselected', 'sizes_undecided', 'backup_storage_bytes', 'missing_sizes', 'geometry_stale_sizes', 'unproducible_sizes', 'backup_absent', 'backup_present', 'backup_validated' ];
	}

	private static function signals( $signals, $allowed ) {
		if ( ! is_array( $signals ) || count( $signals ) > count( $allowed ) ) {
			return false;
		}
		foreach ( $signals as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) || ! self::number( $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function scan( $value, $depth, $created ) {
		$allowed = array_diff( self::signal_names(), [ 'sizes_unselected', 'sizes_undecided' ] );
		if ( $depth === 'header' ) {
			$allowed = array_diff( $allowed, [ 'backup_validated' ] );
		}
		return self::keys( $value, [ 'version', 'started', 'updated', 'signals' ] ) && $value['version'] === 1
			&& self::number( $value['started'] ) && $value['started'] === $created
			&& self::number( $value['updated'], $value['started'] ) && self::signals( $value['signals'], $allowed )
			&& strlen( serialize( $value ) ) <= 4096;
	}

	private static function string_list( $value, $allowed ) {
		if ( ! self::is_list( $value ) || ! $value || count( $value ) > count( $allowed ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) { return false; }
		}
		$sorted = $value;
		sort( $sorted, SORT_STRING );
		return $sorted === $value && count( array_unique( $value, SORT_STRING ) ) === count( $value ) && ! array_diff( $value, $allowed );
	}

	private static function filters( $filters ) {
		$lists = [
			'mime' => [ 'image/jpeg', 'image/png', 'image/webp' ],
			'state' => [ 'legacy', 'drift_suspected', 'drift_verified', 'settings_changed' ],
			'backup' => [ 'absent', 'present', 'validated' ], 'clean_source' => [ 'absent', 'present', 'validated' ],
			'last_outcome' => [ 'partial', 'skipped', 'failed', 'interrupted' ], 'last_operation' => [ 'apply', 'remove', 'regenerate' ],
			'client_side' => [ 'pending', 'incomplete', 'deferred' ], 'condition' => [ 'missing_sizes', 'geometry_stale_sizes', 'unproducible_sizes' ],
		];
		if ( ! self::keys( $filters, [], array_merge( array_keys( $lists ), [ 'date_from', 'date_to', 'parent', 'watermarked', 'journal', 'problems_of_run' ] ) ) ) {
			return false;
		}
		foreach ( $filters as $key => $value ) {
			if ( isset( $lists[ $key ] ) ) {
				if ( ! self::string_list( $value, $lists[ $key ] ) ) { return false; }
			} elseif ( $key === 'date_from' || $key === 'date_to' ) {
				if ( ! is_string( $value ) || ! preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/D', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) { return false; }
			} elseif ( $key === 'parent' ) {
				// Registration can change after a valid run was stored.
				if ( ! is_string( $value ) || strlen( $value ) > 20 || ! preg_match( '/\A[a-z0-9_-]+\z/D', $value ) ) { return false; }
			} elseif ( $key === 'watermarked' ) {
				if ( ! is_bool( $value ) ) { return false; }
			} elseif ( $key === 'journal' ) {
				if ( $value !== 'non_terminal' ) { return false; }
			} elseif ( ! self::id( $value ) ) { return false; }
		}
		return ! ( isset( $filters['date_from'], $filters['date_to'] ) && $filters['date_from'] > $filters['date_to'] );
	}

	/** Validate a stored target; registration may have changed since submission. */
	public static function target( $value, $summary = false ) {
		if ( ! is_array( $value ) || ! isset( $value['type'] ) || strlen( serialize( $value ) ) > 65536 ) { return false; }
		if ( $value['type'] === 'filters' ) {
			return self::keys( $value, [ 'type', 'filters' ] ) && self::filters( $value['filters'] );
		}
		if ( $value['type'] !== 'ids' ) { return false; }
		if ( $summary ) {
			return self::keys( $value, [ 'type', 'count', 'id_min', 'id_max' ] ) && self::number( $value['count'], 1 ) && $value['count'] <= 2000 && self::number( $value['id_min'], 1 ) && self::number( $value['id_max'], $value['id_min'] ) && ( $value['count'] === 1 ? $value['id_min'] === $value['id_max'] : $value['id_min'] < $value['id_max'] );
		}
		if ( ! self::keys( $value, [ 'type', 'ids' ] ) || ! self::is_list( $value['ids'] ) || count( $value['ids'] ) < 1 || count( $value['ids'] ) > 2000 ) { return false; }
		$previous = 0;
		foreach ( $value['ids'] as $id ) {
			if ( ! self::number( $id, 1 ) || $id <= $previous ) { return false; }
			$previous = $id;
		}
		return true;
	}

	private static function common( $value ) {
		if ( ! self::id( $value['id'] ) || ! in_array( $value['operation'], [ 'apply', 'remove', 'regenerate', 'scan' ], true ) || ( $value['retry_of'] !== '' && ! self::id( $value['retry_of'] ) ) || ! self::code( $value['pause_reason'], true ) || ! self::number( $value['owner'], 1 ) || ! self::number( $value['created'] ) || ! self::number( $value['finished'] ) || ! self::number( $value['bound'] ) ) { return false; }
		$counters = [ 'complete', 'partial', 'skipped', 'failed', 'interrupted', 'unknown' ];
		if ( ! self::keys( $value['counters'], $counters ) ) { return false; }
		foreach ( $value['counters'] as $count ) { if ( ! self::number( $count ) ) { return false; } }
		if ( ! is_array( $value['reasons'] ) || count( $value['reasons'] ) > 51 || ( count( $value['reasons'] ) === 51 && ! array_key_exists( 'other', $value['reasons'] ) ) ) { return false; }
		foreach ( $value['reasons'] as $key => $count ) { if ( ! self::code( $key ) || ! self::number( $count ) ) { return false; } }
		if ( ! self::is_list( $value['problems'] ) || count( $value['problems'] ) > 200 ) { return false; }
		foreach ( $value['problems'] as $problem ) {
			if ( ! self::keys( $problem, [ 'a', 'o', 'c', 'r', 't' ] ) || ! self::number( $problem['a'], 1 ) || ! in_array( $problem['o'], [ 'partial', 'skipped', 'failed', 'interrupted', 'unknown' ], true ) || ! self::code( $problem['c'] ) || ! is_bool( $problem['r'] ) || ! self::number( $problem['t'] ) ) { return false; }
		}
		if ( $value['operation'] === 'scan' ) {
			return isset( $value['scan_depth'], $value['scan'] ) && in_array( $value['scan_depth'], [ 'header', 'full' ], true ) && self::scan( $value['scan'], $value['scan_depth'], $value['created'] );
		}
		return ! array_key_exists( 'scan_depth', $value ) && ! array_key_exists( 'scan', $value );
	}

	public static function valid_active( $value ) {
		$required = [ 'version', 'id', 'operation', 'retry_of', 'target', 'cursor', 'bound', 'status', 'pause_reason', 'owner', 'fence', 'heartbeat', 'created', 'updated', 'finished', 'next_attempt', 'claim', 'settings_fingerprint', 'sizes_fingerprint', 'counters', 'reasons', 'problems' ];
		if ( ! self::keys( $value, $required, [ 'scan_depth', 'scan', 'estimated_total' ] ) || ( array_key_exists( 'estimated_total', $value ) && ( ! self::keys( $value['estimated_total'], [ 'count', 'time' ] ) || ! self::number( $value['estimated_total']['count'] ) || ! self::number( $value['estimated_total']['time'] ) ) ) || $value['version'] !== 1 || ! self::common( $value ) || ! self::target( $value['target'] ) || ! self::number( $value['cursor'] ) || $value['cursor'] > $value['bound'] || ! in_array( $value['status'], [ 'running', 'paused', 'stopped', 'complete' ], true ) || ! self::hex( $value['fence'], 32 ) || ! self::number( $value['heartbeat'] ) || ! self::number( $value['updated'] ) || ! self::number( $value['next_attempt'], 1 ) || ! self::hex( $value['settings_fingerprint'], 64 ) || ! self::hex( $value['sizes_fingerprint'], 64 ) || strlen( serialize( $value ) ) > 131072 ) { return false; }
		if ( in_array( $value['status'], [ 'running', 'paused' ], true ) ? $value['finished'] !== 0 : $value['finished'] === 0 ) { return false; }
		$claim = $value['claim'];
		if ( $claim === null ) { return true; }
		return self::keys( $claim, [ 'attachment', 'attempt', 'state', 'fence', 'admitted_at', 'summary_before' ] ) && self::number( $claim['attachment'], 1 ) && $claim['attachment'] > $value['cursor'] && $claim['attachment'] <= $value['bound'] && self::number( $claim['attempt'], 1 ) && $claim['attempt'] < $value['next_attempt'] && in_array( $claim['state'], [ 'claimed', 'executing' ], true ) && self::hex( $claim['fence'], 32 ) && self::number( $claim['admitted_at'] ) && self::hex( $claim['summary_before'], 64 ) && ( $claim['state'] !== 'claimed' || ( $claim['admitted_at'] === 0 && $claim['summary_before'] === str_repeat( '0', 64 ) ) );
	}

	private static function summary( $value ) {
		$required = [ 'id', 'operation', 'retry_of', 'status', 'pause_reason', 'owner', 'created', 'finished', 'bound', 'counters', 'reasons', 'problems', 'target', 'scope_fingerprint' ];
		if ( ! self::keys( $value, $required, [ 'scan_depth', 'scan', 'complete', 'settings_fingerprint', 'sizes_fingerprint' ] ) || ! self::common( $value ) || ! in_array( $value['status'], [ 'stopped', 'complete' ], true ) || $value['finished'] === 0 || ! self::target( $value['target'], true ) || ! self::hex( $value['scope_fingerprint'], 64 ) || strlen( serialize( $value ) ) > 49152 ) { return false; }
		$settings = array_key_exists( 'settings_fingerprint', $value );
		$sizes = array_key_exists( 'sizes_fingerprint', $value );
		if ( $settings !== $sizes || ( $settings && ( $value['operation'] !== 'scan' || ! self::hex( $value['settings_fingerprint'], 64 ) || ! self::hex( $value['sizes_fingerprint'], 64 ) ) ) ) { return false; }
		return $value['operation'] === 'scan' ? array_key_exists( 'complete', $value ) && is_bool( $value['complete'] ) : ! array_key_exists( 'complete', $value );
	}

	private static function health( $value ) {
		return self::keys( $value, [ 'updated', 'source', 'scope', 'scope_fingerprint', 'depth', 'bound', 'complete', 'signals' ] ) && self::number( $value['updated'] ) && self::health_source_type( $value['source'], $value['depth'], $value['bound'] ) !== false && self::target( $value['scope'], true ) && self::hex( $value['scope_fingerprint'], 64 ) && is_bool( $value['complete'] ) && self::signals( $value['signals'], self::signal_names() ) && strlen( serialize( $value ) ) <= 8192;
	}

	public static function valid_history( $value ) {
		if ( ! self::keys( $value, [ 'version', 'runs' ], [ 'health' ] ) || $value['version'] !== 1 || ! self::is_list( $value['runs'] ) || count( $value['runs'] ) > 20 || strlen( serialize( $value ) ) > 524288 || ( array_key_exists( 'health', $value ) && ! self::health( $value['health'] ) ) ) { return false; }
		$ids = [];
		foreach ( $value['runs'] as $summary ) {
			if ( ! self::summary( $summary ) || in_array( $summary['id'], $ids, true ) ) { return false; }
			$ids[] = $summary['id'];
		}
		return true;
	}

	/** Reserve settlement space and projected summary space at active creation. */
	private static function creation_fits( $value ) {
		$accounted = $value;
		// Reserve the widest tuple even when this particular run has none.
		$accounted['estimated_total'] = [ 'count' => PHP_INT_MAX, 'time' => PHP_INT_MAX ];
		if ( $accounted['operation'] === 'scan' ) {
			$names = array_diff( self::signal_names(), [ 'sizes_unselected', 'sizes_undecided' ] );
			if ( $accounted['scan_depth'] === 'header' ) { $names = array_diff( $names, [ 'backup_validated' ] ); }
			$accounted['scan'] = [ 'version' => 1, 'started' => PHP_INT_MAX, 'updated' => PHP_INT_MAX, 'signals' => array_fill_keys( $names, PHP_INT_MAX ) ];
		}
		if ( strlen( serialize( $accounted ) ) + 8192 > 131072 ) { return false; }
		$summary = [];
		foreach ( [ 'id', 'operation', 'retry_of', 'status', 'pause_reason', 'owner', 'created', 'finished', 'bound', 'counters', 'reasons', 'problems', 'target' ] as $key ) { $summary[ $key ] = $accounted[ $key ]; }
		$summary['status'] = 'complete';
		// A later pause or stopped-slot cancellation can replace today's reason.
		$summary['pause_reason'] = str_repeat( 'x', 64 );
		$summary['finished'] = PHP_INT_MAX;
		if ( $summary['target']['type'] === 'ids' ) {
			$ids = $summary['target']['ids'];
			$summary['target'] = [ 'type' => 'ids', 'count' => count( $ids ), 'id_min' => $ids[0], 'id_max' => $ids[ count( $ids ) - 1 ] ];
		}
		$summary['scope_fingerprint'] = str_repeat( 'f', 64 );
		if ( $accounted['operation'] === 'scan' ) {
			$summary['scan_depth'] = $accounted['scan_depth'];
			$summary['complete'] = true;
			$summary['scan'] = $accounted['scan'];
			$summary['settings_fingerprint'] = $accounted['settings_fingerprint'];
			$summary['sizes_fingerprint'] = $accounted['sizes_fingerprint'];
		}
		return strlen( serialize( $summary ) ) <= 49152;
	}

	/** Check the active and archived reservation before retaining another problem. */
	public static function active_problem_fits( $value ) {
		return self::valid_active( $value ) && self::creation_fits( $value );
	}

	private static function valid( $name, $value ) {
		return $name === self::ACTIVE ? self::valid_active( $value ) : ( $name === self::HISTORY && self::valid_history( $value ) );
	}

	private static function invalidate( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$all = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $all ) && array_key_exists( $name, $all ) ) { wp_cache_delete( 'alloptions', 'options' ); }
	}

	/** Return absent, malformed, error, or a valid row including exact bytes. */
	public function read_row( $name ) {
		global $wpdb;
		if ( ! in_array( $name, [ self::ACTIVE, self::HISTORY ], true ) ) { return [ 'state' => 'error' ]; }
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
		if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) { return [ 'state' => 'error' ]; }
		$rows = array_values( array_filter( $rows, static function ( $row ) use ( $name ) { return $row['option_name'] === $name; } ) );
		if ( ! $rows ) { return [ 'state' => 'absent' ]; }
		if ( count( $rows ) !== 1 ) { return [ 'state' => 'malformed', 'code' => $name === self::HISTORY ? 'iw_run_history_malformed' : 'iw_run_active_malformed' ]; }
		$row = [ 'id' => (int) $rows[0]['option_id'], 'name' => $name, 'raw' => $rows[0]['option_value'], 'autoload' => $rows[0]['autoload'], 'site' => get_current_blog_id() ];
		$limit = $name === self::ACTIVE ? 131072 : 524288;
		$value = strlen( $row['raw'] ) <= $limit && is_serialized( $row['raw'] ) ? @unserialize( $row['raw'], [ 'allowed_classes' => false ] ) : false;
		$row['state'] = self::valid( $name, $value ) ? 'valid' : 'malformed';
		if ( $row['state'] === 'valid' ) {
			$row['value'] = $value;
		} else {
			$row['code'] = $name === self::HISTORY ? 'iw_run_history_malformed' : 'iw_run_active_malformed';
		}
		return $row;
	}

	/** The history CAS transports two capped, possibly escaped values. */
	public function history_packet_ready() {
		global $wpdb;
		$limit = $wpdb->get_var( 'SELECT @@SESSION.max_allowed_packet' );
		return $wpdb->last_error === '' && ( ( is_string( $limit ) && ctype_digit( $limit ) ) || ( is_int( $limit ) && $limit >= 0 ) ) && (float) $limit >= 4194304;
	}

	/** Insert only; an existing row after a failed insert is not write proof. */
	public function insert_absent( $name, $value ) {
		global $wpdb;
		if ( ! self::valid( $name, $value ) || ( $name === self::ACTIVE && ! self::creation_fits( $value ) ) ) { return 'invalid'; }
		if ( ! $this->history_packet_ready() ) { return 'error'; }
		$previous = $wpdb->suppress_errors( true );
		try { $result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, serialize( $value ) ) ); }
		finally { $wpdb->suppress_errors( $previous ); }
		// Read diagnostics before cache operations, which may themselves use the database.
		$duplicate = false;
		if ( $result === false ) {
			$diagnostics = $wpdb->get_results( 'SHOW WARNINGS', ARRAY_A );
			$duplicate = $wpdb->last_error === '' && is_array( $diagnostics ) && isset( $diagnostics[0]['Code'] ) && (int) $diagnostics[0]['Code'] === 1062;
		}
		self::invalidate( $name );
		if ( $result === 1 ) { return 'inserted'; }
		if ( ! $duplicate ) { return 'error'; }
		$row = $this->read_row( $name );
		return $row['state'] === 'valid' || $row['state'] === 'malformed' ? 'exists' : 'error';
	}

	/** Compare exact row and bytes; a verified no-op is not a winning transition. */
	public function swap_exact( $row, $value ) {
		return $this->swap_exact_before_write( $row, $value, null );
	}

	/** Recovery renews its lease only after validation, immediately before the CAS. */
	public function swap_exact_before_write( $row, $value, $before_write ) {
		global $wpdb;
		if ( ! is_array( $row ) || ! isset( $row['name'], $row['id'], $row['raw'], $row['site'], $row['state'] ) || $row['state'] !== 'valid' || $row['site'] !== get_current_blog_id() || ! self::valid( $row['name'], $value ) ) { return 'invalid'; }
		if ( $row['name'] === self::ACTIVE ) {
			$before = @unserialize( $row['raw'], [ 'allowed_classes' => false ] );
			if ( ! self::valid_active( $before ) || ( count( $value['problems'] ) > count( $before['problems'] ) && ! self::creation_fits( $value ) ) ) { return 'invalid'; }
		}
		$raw = serialize( $value );
		if ( $raw === $row['raw'] ) {
			$current = $this->read_row( $row['name'] );
			if ( $current['state'] === 'error' ) { return 'error'; }
			return $current['state'] === 'valid' && $current['id'] === $row['id'] && $current['raw'] === $row['raw'] ? 'unchanged' : 'lost';
		}
		if ( $row['name'] === self::HISTORY && ! $this->history_packet_ready() ) { return 'error'; }
		$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_id = %d AND BINARY option_name = BINARY %s AND BINARY option_value = %s", $raw, $row['id'], $row['name'], $row['raw'] );
		if ( $before_write !== null && ( ! is_callable( $before_write ) || ! $before_write() ) ) { return 'pending'; }
		$result = $wpdb->query( $sql );
		self::invalidate( $row['name'] );
		return $result === 1 ? 'updated' : ( $result === 0 ? 'lost' : 'error' );
	}

	/** Delete only the observed row, including malformed values for explicit reset. */
	public function delete_exact( $row ) {
		global $wpdb;
		if ( ! is_array( $row ) || ! isset( $row['name'], $row['id'], $row['raw'], $row['site'], $row['state'] ) || ! in_array( $row['name'], [ self::ACTIVE, self::HISTORY ], true ) || ! in_array( $row['state'], [ 'valid', 'malformed' ], true ) || $row['site'] !== get_current_blog_id() ) { return 'invalid'; }
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_id = %d AND BINARY option_name = BINARY %s AND BINARY option_value = %s", $row['id'], $row['name'], $row['raw'] ) );
		self::invalidate( $row['name'] );
		return $result === 1 ? 'deleted' : ( $result === 0 ? 'lost' : 'error' );
	}

	/** Project a finished, unclaimed run; the caller supplies its full-target hash. */
	public function summary_for_archive( $active, $scope_fingerprint ) {
		if ( ! self::valid_active( $active ) || ! in_array( $active['status'], [ 'stopped', 'complete' ], true ) || $active['claim'] !== null || ! self::hex( $scope_fingerprint, 64 ) ) { return false; }
		$summary = [];
		foreach ( [ 'id', 'operation', 'retry_of', 'status', 'pause_reason', 'owner', 'created', 'finished', 'bound', 'counters', 'reasons', 'problems', 'target' ] as $key ) { $summary[ $key ] = $active[ $key ]; }
		if ( $summary['target']['type'] === 'ids' ) {
			$ids = $summary['target']['ids'];
			$summary['target'] = [ 'type' => 'ids', 'count' => count( $ids ), 'id_min' => $ids[0], 'id_max' => $ids[ count( $ids ) - 1 ] ];
		}
		$summary['scope_fingerprint'] = $scope_fingerprint;
		if ( $active['operation'] === 'scan' ) {
			$summary['scan_depth'] = $active['scan_depth'];
			$summary['complete'] = $active['cursor'] === $active['bound'];
			$summary['scan'] = $active['scan'];
			$summary['settings_fingerprint'] = $active['settings_fingerprint'];
			$summary['sizes_fingerprint'] = $active['sizes_fingerprint'];
		}
		return self::summary( $summary ) ? $summary : false;
	}

	/**
	 * Merge one archive or prune on Status, preserving any legacy health.
	 * Writes keep the ten newest runs with no age pruning; reads still accept
	 * a legacy value of up to twenty (A-P04-11). `$now` is validated for
	 * compatibility with existing callers but no longer selects runs.
	 */
	public function merge_history( $archive = null, $now = null ) {
		if ( ( $archive !== null && ! self::summary( $archive ) ) || ( $now !== null && ! self::number( $now ) ) ) { return 'invalid'; }
		$site = get_current_blog_id();
		for ( $attempt = 0; $attempt < 5; ++$attempt ) {
			if ( get_current_blog_id() !== $site ) { return 'error'; }
			$row = $this->read_row( self::HISTORY );
			if ( $row['state'] === 'error' ) { return 'error'; }
			if ( $row['state'] === 'malformed' ) { return 'malformed'; }
			if ( $row['state'] === 'absent' && $archive === null ) { return 'unchanged'; }
			$value = $row['state'] === 'absent' ? [ 'version' => 1, 'runs' => [] ] : $row['value'];
			if ( $archive !== null ) {
				$found = false;
				foreach ( $value['runs'] as $existing ) {
					if ( $existing['id'] === $archive['id'] ) {
						if ( $existing !== $archive ) { return 'error'; }
						$found = true;
						break;
					}
				}
				if ( ! $found ) { array_unshift( $value['runs'], $archive ); }
			}
			// At most 21 runs can be present: a legacy history of up to twenty plus
			// one archive. The newest is first, so pruning drops the oldest whole.
			// Ten capped summaries plus legacy health always fit; the byte check
			// remains a guard.
			for ( $pruned = 0; $pruned < 21 && ( count( $value['runs'] ) > self::HISTORY_RUNS || strlen( serialize( $value ) ) > 524288 ); ++$pruned ) {
				if ( count( $value['runs'] ) <= 1 ) { return 'error'; }
				array_pop( $value['runs'] );
			}
			if ( count( $value['runs'] ) > self::HISTORY_RUNS || strlen( serialize( $value ) ) > 524288 ) { return 'error'; }
			$result = $row['state'] === 'absent' ? $this->insert_absent( self::HISTORY, $value ) : $this->swap_exact( $row, $value );
			if ( in_array( $result, [ 'inserted', 'updated', 'unchanged' ], true ) ) {
				if ( $archive === null ) { return $result; }
				$verified = $this->read_row( self::HISTORY );
				if ( $verified['state'] !== 'valid' ) { return $verified['state'] === 'malformed' ? 'malformed' : 'error'; }
				foreach ( $verified['value']['runs'] as $existing ) { if ( $existing === $archive ) { return 'verified'; } }
				return 'error';
			}
			if ( ! in_array( $result, [ 'lost', 'exists' ], true ) ) { return 'error'; }
		}
		return 'error';
	}

	/** Retire only the exact terminal row after its summary is verified. */
	public function archive_finished( $row, $scope_fingerprint, $now = null ) {
		if ( ! is_array( $row ) || ! isset( $row['state'], $row['name'], $row['site'], $row['value'] ) || $row['state'] !== 'valid' || $row['name'] !== self::ACTIVE || $row['site'] !== get_current_blog_id() ) { return 'invalid'; }
		$summary = $this->summary_for_archive( $row['value'], $scope_fingerprint );
		if ( $summary === false ) { return 'invalid'; }
		$result = $this->merge_history( $summary, $now );
		if ( $result !== 'verified' ) { return $result; }
		return $this->delete_exact( $row );
	}
}
