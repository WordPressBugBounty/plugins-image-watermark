<?php
/**
 * Image Watermark uninstall cleanup.
 *
 * WordPress loads this file only after the plugin has been selected for uninstall.
 * Filesystem backups and live media are deliberately outside this allowlist.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'IMAGE_WATERMARK_UNINSTALL_OPTION' ) ) {
	define( 'IMAGE_WATERMARK_UNINSTALL_OPTION', 'image_watermark_options' );
}

if ( ! defined( 'IMAGE_WATERMARK_UNINSTALL_META_KEYS' ) ) {
	define( 'IMAGE_WATERMARK_UNINSTALL_META_KEYS', [
		'iw-is-watermarked',
		'_iw_backup_watermark_id',
		'_iw_last_operation',
		'_iw_operation_journal',
		'_iw_client_side_upload',
		'_iw_client_side_finalize_payload',
	] );
}

if ( ! defined( 'IMAGE_WATERMARK_UNINSTALL_USER_META_KEY' ) ) {
	define( 'IMAGE_WATERMARK_UNINSTALL_USER_META_KEY', '_iw_auto_operation_notices' );
}

if ( ! defined( 'IMAGE_WATERMARK_UNINSTALL_LEASE_PREFIX' ) ) {
	define( 'IMAGE_WATERMARK_UNINSTALL_LEASE_PREFIX', '_iw_operation_lock_' );
}

if ( ! function_exists( 'image_watermark_uninstall_lease_is_idle' ) ) {
	/**
	 * Classify one operation lease from its raw option row.
	 *
	 * Only a lease proven idle by the same 900-second takeover boundary as
	 * acquire_operation_lock() qualifies. Deletion fences are judged by the same
	 * heartbeat. Live, malformed, and non-canonical rows are never idle.
	 *
	 * @param string $option_name Raw option name.
	 * @param string $raw_value Raw option value.
	 * @param int $now Current timestamp.
	 * @return bool
	 */
	function image_watermark_uninstall_lease_is_idle( $option_name, $raw_value, $now ) {
		if ( strpos( $option_name, IMAGE_WATERMARK_UNINSTALL_LEASE_PREFIX ) !== 0 ) {
			return false;
		}
		// The suffix must be exactly what the integer cast in operation_lock_name() produces.
		$suffix = substr( $option_name, strlen( IMAGE_WATERMARK_UNINSTALL_LEASE_PREFIX ) );
		if ( ! preg_match( '/^[1-9][0-9]*$/', $suffix ) || (string) (int) $suffix !== $suffix ) {
			return false;
		}
		if ( ! is_string( $raw_value ) || ! is_serialized( $raw_value ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Lease rows are plain arrays; objects are never instantiated.
		$lease = @unserialize( $raw_value, [ 'allowed_classes' => false ] );

		// acquire_operation_lock() treats an empty token or heartbeat as busy, so
		// neither ever counts as idle here.
		return is_array( $lease )
			&& isset( $lease['token'] ) && is_string( $lease['token'] ) && ! empty( $lease['token'] )
			&& isset( $lease['heartbeat_at'] ) && is_int( $lease['heartbeat_at'] ) && $lease['heartbeat_at'] > 0
			&& $lease['heartbeat_at'] <= $now - 900;
	}
}

if ( ! function_exists( 'image_watermark_uninstall_idle_leases' ) ) {
	/**
	 * Remove idle operation leases for the current site in bounded keyset pages.
	 *
	 * Each idle lease is removed only by compare-and-delete against the exact raw
	 * value read in its page, so a lease renewed or replaced after inspection is
	 * kept. The cursor advances past every returned row, including rejected names.
	 *
	 * @param int $page_size Rows per page.
	 * @param int|null $now Current timestamp, or null for time().
	 * @return void
	 */
	function image_watermark_uninstall_idle_leases( $page_size = 100, $now = null ) {
		global $wpdb;

		$page_size = max( 1, (int) $page_size );
		$now = $now === null ? time() : (int) $now;
		$like = $wpdb->esc_like( IMAGE_WATERMARK_UNINSTALL_LEASE_PREFIX ) . '%';
		$cursor = '';

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Raw lease rows are required for the exact-value compare-and-delete below.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
				$like,
				$cursor,
				$page_size
			), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				return;
			}

			foreach ( $rows as $row ) {
				$cursor = $row['option_name'];
				if ( ! image_watermark_uninstall_lease_is_idle( $row['option_name'], $row['option_value'], $now ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-delete is the operation lock ownership fence; core APIs cannot provide this atomic operation.
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$row['option_name'],
					$row['option_value']
				) );
				if ( $deleted === 1 ) {
					wp_cache_delete( $row['option_name'], 'options' );
					wp_cache_delete( 'alloptions', 'options' );
				}
			}
		} while ( count( $rows ) === $page_size );
	}
}

if ( ! function_exists( 'image_watermark_uninstall_site' ) ) {
	/**
	 * Remove the explicitly allowlisted database state for the current site.
	 *
	 * @return void
	 */
	function image_watermark_uninstall_site() {
		image_watermark_uninstall_idle_leases();

		delete_option( IMAGE_WATERMARK_UNINSTALL_OPTION );

		foreach ( IMAGE_WATERMARK_UNINSTALL_META_KEYS as $meta_key ) {
			delete_post_meta_by_key( $meta_key );
		}
	}
}


if ( ! function_exists( 'image_watermark_uninstall_network' ) ) {
	/**
	 * Remove the explicit allowlist from every site in bounded pages.
	 *
	 * @return void
	 */
	function image_watermark_uninstall_network() {
		$page_size = 100;
		$offset = 0;

		do {
			$site_ids = get_sites( [
				'fields' => 'ids',
				'number' => $page_size,
				'offset' => $offset,
			] );
			$site_count = count( $site_ids );

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				try {
					image_watermark_uninstall_site();
				} finally {
					restore_current_blog();
				}
			}

			$offset += $site_count;
		} while ( $site_count === $page_size );
	}
}

if ( ! function_exists( 'image_watermark_uninstall' ) ) {
	/**
	 * Run the scoped uninstall cleanup in the current WordPress context.
	 *
	 * @return void
	 */
	function image_watermark_uninstall() {
		if ( is_multisite() ) {
			image_watermark_uninstall_network();
		} else {
			image_watermark_uninstall_site();
		}

		// The users table is network-wide, so its notice meta is removed once, not per site.
		delete_metadata( 'user', 0, IMAGE_WATERMARK_UNINSTALL_USER_META_KEY, '', true );
	}
}

image_watermark_uninstall();
