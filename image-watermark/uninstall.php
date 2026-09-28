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
	] );
}

if ( ! function_exists( 'image_watermark_uninstall_site' ) ) {
	/**
	 * Remove the explicitly allowlisted database state for the current site.
	 *
	 * @return void
	 */
	function image_watermark_uninstall_site() {
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
			return;
		}

		image_watermark_uninstall_site();
	}
}

image_watermark_uninstall();
