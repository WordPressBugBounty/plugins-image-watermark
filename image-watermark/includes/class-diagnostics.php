<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only diagnostics service for system and per-attachment watermark readiness.
 * Does not mutate options, files, or post meta.
 *
 * @class Image_Watermark_Diagnostics
 */
class Image_Watermark_Diagnostics {

	/** @var Image_Watermark */
	private $plugin;

	const STATUS_OK      = 'ok';
	const STATUS_WARNING = 'warning';
	const STATUS_ERROR   = 'error';
	const STATUS_INFO    = 'info';

	/**
	 * @param Image_Watermark $plugin
	 */
	public function __construct( Image_Watermark $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Build the full system diagnostic report, grouped by section.
	 *
	 * Sections: engine, watermark, sizes, backup, modes, support.
	 * Each item: [ code, status, label, message, hint ].
	 *
	 * @return array<string,array<int,array>>
	 */
	public function system_report() {
		$options = $this->plugin->options;

		return [
			'engine'   => $this->build_engine_section(),
			'watermark' => $this->build_watermark_section( $options ),
			'sizes'    => $this->build_sizes_section( $options ),
			'backup'   => $this->build_backup_section( $options ),
			'modes'    => $this->build_modes_section( $options ),
			'support'  => $this->build_support_section(),
		];
	}

	/**
	 * Compute overall readiness from a system report.
	 *
	 * @param array $report Output of system_report().
	 * @return string 'ready', 'needs_setup', or 'degraded'.
	 */
	public function readiness( array $report ) {
		foreach ( $report as $items ) {
			foreach ( $items as $item ) {
				if ( $item['status'] === self::STATUS_ERROR ) {
					return 'degraded';
				}
			}
		}

		foreach ( $report as $items ) {
			foreach ( $items as $item ) {
				if ( $item['status'] === self::STATUS_WARNING ) {
					return 'needs_setup';
				}
			}
		}

		return 'ready';
	}

	/**
	 * Build per-attachment diagnostic report.
	 *
	 * Each item: [ code, status, label, message, hint ].
	 *
	 * @param int $attachment_id
	 * @return array[]
	 */
	public function attachment_report( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$items         = [];
		$options       = $this->plugin->options;

		$post = get_post( $attachment_id );

		if ( ! $post || $post->post_type !== 'attachment' ) {
			$items[] = $this->item(
				'attachment_not_found',
				self::STATUS_ERROR,
				__( 'Attachment', 'image-watermark' ),
				__( 'Attachment not found.', 'image-watermark' ),
				__( 'Check that the attachment post exists in the database.', 'image-watermark' )
			);
			return $items;
		}

		// MIME type
		$mime          = get_post_mime_type( $attachment_id );
		$allowed_mimes = $this->plugin->get_allowed_mime_types();
		$mime_ok       = in_array( $mime, $allowed_mimes, true );

		$items[] = $this->item(
			$mime_ok ? 'mime_supported' : 'mime_unsupported',
			$mime_ok ? self::STATUS_OK : self::STATUS_ERROR,
			__( 'File type', 'image-watermark' ),
			$mime ?: __( 'Unknown', 'image-watermark' ),
			$mime_ok ? '' : __( 'Only JPEG, PNG, and WebP files can be watermarked.', 'image-watermark' )
		);

		if ( ! $mime_ok ) {
			return $items;
		}

		// Is this the selected watermark image?
		$watermark_source_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;

		if ( $watermark_source_id > 0 && $attachment_id === $watermark_source_id ) {
			$items[] = $this->item(
				'is_watermark_source',
				self::STATUS_ERROR,
				__( 'Watermark source', 'image-watermark' ),
				__( 'This attachment is the selected watermark image.', 'image-watermark' ),
				__( 'The watermark source cannot be watermarked. Choose a different image or change the watermark source.', 'image-watermark' )
			);
			return $items;
		}

		// Metadata
		$data        = wp_get_attachment_metadata( $attachment_id, false );
		$metadata_ok = is_array( $data ) && ! empty( $data['file'] ) && is_string( $data['file'] );

		$items[] = $this->item(
			$metadata_ok ? 'metadata_ok' : 'metadata_missing',
			$metadata_ok ? self::STATUS_OK : self::STATUS_ERROR,
			__( 'Attachment metadata', 'image-watermark' ),
			$metadata_ok ? __( 'Present.', 'image-watermark' ) : __( 'Missing or incomplete.', 'image-watermark' ),
			$metadata_ok ? '' : __( 'Try regenerating thumbnails to rebuild attachment metadata.', 'image-watermark' )
		);

		if ( ! $metadata_ok ) {
			return $items;
		}

		// Source file
		$upload_dir    = wp_upload_dir();
		$attached_file = get_attached_file( $attachment_id );
		$source_file   = ( $attached_file && is_file( $attached_file ) )
			? $attached_file
			: $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $data['file'];
		$file_exists   = is_file( $source_file );

		$items[] = $this->item(
			$file_exists ? 'file_exists' : 'file_missing',
			$file_exists ? self::STATUS_OK : self::STATUS_ERROR,
			__( 'Source file', 'image-watermark' ),
			$file_exists ? __( 'Found on disk.', 'image-watermark' ) : __( 'Not found on disk.', 'image-watermark' ),
			$file_exists ? '' : __( 'The file may have been moved, deleted, or offloaded to remote storage. Watermarking requires the file to be present locally.', 'image-watermark' )
		);

		// Dimensions and small-image threshold
		$too_small = false;
		if ( $file_exists ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$image_size = @getimagesize( $source_file );

			if ( $image_size !== false ) {
				$w = (int) $image_size[0];
				$h = (int) $image_size[1];

				if ( $this->is_too_small( $options, $w, $h ) ) {
					$too_small = true;
					$min_w = isset( $options['watermark_image']['min_image_width'] ) ? (int) $options['watermark_image']['min_image_width'] : 0;
					$min_h = isset( $options['watermark_image']['min_image_height'] ) ? (int) $options['watermark_image']['min_image_height'] : 0;
					$items[] = $this->item(
						'image_too_small',
						self::STATUS_WARNING,
						__( 'Image size', 'image-watermark' ),
						/* translators: 1: Image width in pixels. 2: Image height in pixels. */
						sprintf( __( '%1$d x %2$d px', 'image-watermark' ), $w, $h ),
						/* translators: 1: Minimum image width in pixels. 2: Minimum image height in pixels. */
						sprintf( __( 'Image is smaller than the minimum threshold (%1$d x %2$d px). Update the Skip Small Images setting or choose a larger image.', 'image-watermark' ), $min_w, $min_h )
					);
				} else {
					$items[] = $this->item(
						'image_size_ok',
						self::STATUS_OK,
						__( 'Image size', 'image-watermark' ),
						/* translators: 1: Image width in pixels. 2: Image height in pixels. */
						sprintf( __( '%1$d x %2$d px', 'image-watermark' ), $w, $h ),
						''
					);
				}
			} else {
				$items[] = $this->item(
					'image_unreadable',
					self::STATUS_ERROR,
					__( 'Image file', 'image-watermark' ),
					__( 'Cannot read image dimensions. File may be corrupt.', 'image-watermark' ),
					__( 'Try re-uploading the image.', 'image-watermark' )
				);
			}
		}

		// Watermark configuration (shared with system report)
		foreach ( $this->build_watermark_config_items( $options ) as $config_item ) {
			$items[] = $config_item;
		}

		$eligibility = $this->plugin->get_upload_handler()->describe_operation_eligibility( $attachment_id, 'manual-apply' );
		$items[] = $this->item(
			! empty( $eligibility['valid'] ) ? 'operation_eligible' : sanitize_key( $eligibility['code'] ),
			! empty( $eligibility['valid'] ) ? self::STATUS_OK : self::STATUS_ERROR,
			__( 'Current operation eligibility', 'image-watermark' ),
			! empty( $eligibility['valid'] ) ? __( 'Current settings can be applied.', 'image-watermark' ) : $eligibility['error'],
			''
		);

		// Target sizes
		$watermark_on   = isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [];
		$selected_sizes = array_keys( array_filter( $watermark_on, function( $v ) { return (int) $v === 1; } ) );
		$present_sizes  = [];
		$missing_sizes  = [];
		$base_dir       = $upload_dir['basedir'];
		$rel_dir        = dirname( $data['file'] );

		foreach ( $selected_sizes as $size ) {
			if ( $size === 'full' ) {
				$size_path = $source_file;
			} else {
				if ( empty( $data['sizes'][ $size ]['file'] ) ) {
					$missing_sizes[] = $size;
					continue;
				}
				$size_path = $base_dir . DIRECTORY_SEPARATOR . $rel_dir . DIRECTORY_SEPARATOR . $data['sizes'][ $size ]['file'];
			}

			if ( is_file( $size_path ) ) {
				$present_sizes[] = $size;
			} else {
				$missing_sizes[] = $size;
			}
		}

		if ( empty( $selected_sizes ) ) {
			$items[] = $this->item(
				'no_sizes_selected',
				self::STATUS_ERROR,
				__( 'Target sizes', 'image-watermark' ),
				__( 'No image sizes selected for watermarking.', 'image-watermark' ),
				__( 'Go to Watermark settings and select at least one image size.', 'image-watermark' )
			);
		} elseif ( ! empty( $present_sizes ) ) {
			$msg = implode( ', ', $present_sizes );
			if ( ! empty( $missing_sizes ) ) {
				/* translators: Comma-separated unavailable image-size names. */
				$msg .= ' ' . sprintf( __( '(missing: %s)', 'image-watermark' ), implode( ', ', $missing_sizes ) );
				$items[] = $this->item(
					'some_sizes_missing',
					self::STATUS_WARNING,
					__( 'Target sizes', 'image-watermark' ),
					$msg,
					__( 'Some selected sizes are unavailable for this attachment. Regenerate thumbnails if they should exist.', 'image-watermark' )
				);
			} else {
				$items[] = $this->item(
					'all_sizes_available',
					self::STATUS_OK,
					__( 'Target sizes', 'image-watermark' ),
					$msg,
					''
				);
			}
		} else {
			$items[] = $this->item(
				'no_sizes_available',
				self::STATUS_ERROR,
				__( 'Target sizes', 'image-watermark' ),
				/* translators: Comma-separated selected image-size names. */
				sprintf( __( 'Selected sizes (%s) are not available for this attachment.', 'image-watermark' ), implode( ', ', $selected_sizes ) ),
				__( 'Regenerate thumbnails or select different image sizes in Watermark settings.', 'image-watermark' )
			);
		}

		// Watermark state
		$is_watermarked = (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1;
		$items[] = $this->item(
			$is_watermarked ? 'is_watermarked' : 'not_watermarked',
			self::STATUS_INFO,
			__( 'Watermark status', 'image-watermark' ),
			$is_watermarked ? __( 'Watermarked.', 'image-watermark' ) : __( 'Not watermarked.', 'image-watermark' ),
			''
		);

		// Backup
		$backup_enabled = ! empty( $options['backup']['backup_image'] );
		$relative_path  = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$backup_path    = $relative_path ? $this->plugin->get_upload_handler()->get_image_backup_filepath( $relative_path ) : false;
		$backup_exists  = $backup_path && $this->plugin->get_upload_handler()->is_valid_backup_file( $backup_path );

		$inventory_item = $this->item(
			'attachment_file_inventory',
			self::STATUS_INFO,
			__( 'File inventory', 'image-watermark' ),
			__( 'The full attachment file and selected generated sizes are watermark targets. A WordPress -scaled attachment file is the full target when selected. A separately retained original_image file is not a current watermark target. File URLs may be derivable, but this diagnostic does not test HTTP access. Backup locations are not disclosed.', 'image-watermark' ),
			__( 'Verify public-file and backup access with unauthenticated requests on the deployed server. Server access rules, not browser copy deterrents, control file access.', 'image-watermark' )
		);
		$inventory_item['inventory'] = $this->build_attachment_file_inventory( $data, $source_file, $upload_dir, $selected_sizes, $is_watermarked, $backup_exists, $attachment_id );
		$items[] = $inventory_item;

		if ( $relative_path && $backup_path === false ) {
			$items[] = $this->item(
				'invalid_backup_path',
				self::STATUS_ERROR,
				__( 'Backup', 'image-watermark' ),
				__( 'The attachment backup path is invalid. No backup file was used.', 'image-watermark' ),
				__( 'Correct the attachment file record or retry after restoring valid media metadata.', 'image-watermark' )
			);
		} elseif ( $backup_exists || $backup_enabled ) {
			$items[] = $this->item(
				$backup_exists ? 'backup_exists' : 'backup_missing',
				$backup_exists ? self::STATUS_OK : ( $is_watermarked ? self::STATUS_WARNING : self::STATUS_INFO ),
				__( 'Backup', 'image-watermark' ),
				$backup_exists ? __( 'Backup exists.', 'image-watermark' ) : __( 'No backup found.', 'image-watermark' ),
				$backup_exists ? '' : (
					$is_watermarked
						? __( 'Image is watermarked but no valid clean backup was found. Remove watermark is not possible.', 'image-watermark' )
						: __( 'No backup yet. A backup will be created when watermark is applied.', 'image-watermark' )
				)
			);
		} else {
			$items[] = $this->item(
				'backup_disabled',
				self::STATUS_INFO,
				__( 'Backup', 'image-watermark' ),
				__( 'Backup is disabled.', 'image-watermark' ),
				__( 'Enable "Backup Images" in the Status tab to allow watermark removal.', 'image-watermark' )
			);
		}

		// Remove feasibility
		$can_remove = $backup_exists && $is_watermarked;
		$items[] = $this->item(
			$can_remove ? 'can_remove' : ( $is_watermarked ? 'cannot_remove' : 'remove_not_applicable' ),
			$can_remove ? self::STATUS_OK : ( $is_watermarked ? self::STATUS_WARNING : self::STATUS_INFO ),
			__( 'Remove watermark', 'image-watermark' ),
			$can_remove
				? __( 'Possible.', 'image-watermark' )
				: ( $is_watermarked ? __( 'Not possible - no backup.', 'image-watermark' ) : __( 'Not applicable - image is not watermarked.', 'image-watermark' ) ),
				$can_remove ? '' : ( $is_watermarked ? __( 'A verified clean backup is required before the watermark can be removed.', 'image-watermark' ) : '' )
		);

		// Last operation
		$last_op = $this->plugin->get_upload_handler()->get_attachment_operation_outcome( $attachment_id );

		if ( is_array( $last_op ) && ! empty( $last_op['outcome'] ) ) {
			$status_map = [
				'complete'    => self::STATUS_OK,
				'failed'      => self::STATUS_ERROR,
				'interrupted' => self::STATUS_ERROR,
				'partial'     => self::STATUS_WARNING,
				'skipped'     => self::STATUS_WARNING,
			];
			$op_status = isset( $status_map[ $last_op['outcome'] ] ) ? $status_map[ $last_op['outcome'] ] : self::STATUS_INFO;
			$op_msg    = ! empty( $last_op['message'] ) ? $last_op['message'] : $last_op['outcome'];

			if ( ! empty( $last_op['context'] ) ) {
				$op_msg = '[' . $last_op['context'] . '] ' . $op_msg;
			}

			if ( ! empty( $last_op['time'] ) ) {
				$op_msg .= ' (' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_op['time'] ) . ')';
			}
			$sizes = isset( $last_op['sizes'] ) && is_array( $last_op['sizes'] ) ? $last_op['sizes'] : [];
			/* translators: 1: Processed size count. 2: Failed size count. 3: Skipped size count. */
			$op_msg .= sprintf( __( ' Processed: %1$d; failed: %2$d; skipped: %3$d.', 'image-watermark' ), count( isset( $sizes['processed'] ) ? $sizes['processed'] : [] ), count( isset( $sizes['failed'] ) ? $sizes['failed'] : [] ), count( isset( $sizes['skipped'] ) ? $sizes['skipped'] : [] ) );
			$operation_sizes = [];
			$remaining_size_details = 20;
			foreach ( [ 'processed', 'failed', 'skipped' ] as $size_status ) {
				$values = isset( $sizes[ $size_status ] ) && is_array( $sizes[ $size_status ] ) ? $sizes[ $size_status ] : [];
				$operation_sizes[ $size_status ] = array_slice( $values, 0, $remaining_size_details );
				$remaining_size_details -= count( $operation_sizes[ $size_status ] );
			}

			$item = $this->item(
				'last_op_' . ( ! empty( $last_op['code'] ) ? sanitize_key( $last_op['code'] ) : 'unknown' ),
				$op_status,
				__( 'Last operation', 'image-watermark' ),
				$op_msg,
				''
			);
			$item['operation'] = [
				'outcome' => sanitize_key( $last_op['outcome'] ),
				'code'    => ! empty( $last_op['code'] ) ? sanitize_key( $last_op['code'] ) : '',
				'sizes'   => $operation_sizes,
			];
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Compute a compact apply/remove summary from an attachment report.
	 *
	 * Stable public contract:
	 *   apply:  ok|blocked|already|warning
	 *   remove: ok|blocked|no_backup|not_applicable
	 *
	 * @param array $items   Output of attachment_report().
	 * @return array { apply: string, remove: string, apply_hint: string, remove_hint: string }
	 */
	public function attachment_action_summary( array $items ) {
		$codes = [];

		foreach ( $items as $item ) {
			$codes[ $item['code'] ] = $item;
		}

		// Apply feasibility
		$apply = 'ok';
		$hint  = '';

		$blocking_codes = [
			'attachment_not_found',
			'mime_unsupported',
			'is_watermark_source',
			'metadata_missing',
			'file_missing',
			'image_unreadable',
			'no_sizes_selected',
			'no_sizes_available',
			'watermark_source_missing',
			'watermark_source_file_missing',
			'watermark_text_empty',
			'watermark_font_missing',
			'no_engine',
			'manual_disabled',
			'invalid_custom_dimensions',
		];

		foreach ( $blocking_codes as $code ) {
			if ( isset( $codes[ $code ] ) ) {
				$apply = 'blocked';
				$hint  = $codes[ $code ]['hint'];
				break;
			}
		}

		if ( $apply === 'ok' && isset( $codes['image_too_small'] ) ) {
			$apply = 'warning';
			$hint  = $codes['image_too_small']['hint'];
		}

		if ( isset( $codes['is_watermarked'] ) && ( isset( $codes['backup_missing'] ) || isset( $codes['backup_disabled'] ) || isset( $codes['cannot_remove'] ) ) ) {
			$apply = 'blocked';
			$hint  = __( 'The image is watermarked and no valid clean backup is available for safe reapplication.', 'image-watermark' );
		}

		if ( $apply === 'ok' && isset( $codes['is_watermarked'] ) ) {
			$apply = 'already';
			$hint  = __( 'The image is already watermarked. Applying again will replace the current watermark.', 'image-watermark' );
		}

		// Remove feasibility
		if ( isset( $codes['cannot_remove'] ) ) {
			$remove = 'no_backup';
			$remove_hint = $codes['cannot_remove']['hint'];
		} elseif ( isset( $codes['can_remove'] ) ) {
			$remove = 'ok';
			$remove_hint = '';
		} else {
			$remove = 'not_applicable';
			$remove_hint = '';
		}

		return [
			'apply'       => $apply,
			'remove'      => $remove,
			'apply_hint'  => $hint,
			'remove_hint' => $remove_hint,
		];
	}

	/**
	 * Generate a plain-text diagnostic report for copy-to-clipboard support.
	 *
	 * @param array $report Output of system_report().
	 * @param string $readiness Output of readiness().
	 * @return string
	 */
	public function plain_text_report( array $report, $readiness ) {
		$section_labels = $this->section_labels();
		$lines          = [];

		$lines[] = '=== Image Watermark Diagnostic Report ===';
		$lines[] = sprintf( 'Readiness: %s', $readiness );
		$lines[] = sprintf( 'Date: %s', date_i18n( 'Y-m-d H:i:s' ) );
		$lines[] = '';

		foreach ( $report as $section_key => $items ) {
			$section_label = isset( $section_labels[ $section_key ] ) ? $section_labels[ $section_key ] : $section_key;
			$lines[] = '--- ' . $section_label . ' ---';

			foreach ( $items as $item ) {
				$line = sprintf( '[%s] %s: %s', strtoupper( $item['status'] ), $item['label'], $item['message'] );

				if ( ! empty( $item['hint'] ) ) {
					$line .= ' => ' . $item['hint'];
				}

				$lines[] = $line;
			}

			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Human-readable section labels for the system report.
	 *
	 * @return array<string,string>
	 */
	public function section_labels() {
		return [
			'engine'   => __( 'Processing Engine', 'image-watermark' ),
			'watermark' => __( 'Watermark Configuration', 'image-watermark' ),
			'sizes'    => __( 'Image Sizes', 'image-watermark' ),
			'backup'   => __( 'Backup and Restore', 'image-watermark' ),
			'modes'    => __( 'Operating Modes', 'image-watermark' ),
			'support'  => __( 'Support Notes', 'image-watermark' ),
		];
	}

	// -------------------------------------------------------------------------
	// Private section builders
	// -------------------------------------------------------------------------

	/**
	 * @return array
	 */
	private function build_engine_section() {
		$items         = [];
		$gd_ok         = $this->plugin->check_gd();
		$imagick_ok    = $this->plugin->check_imagick();
		$active_engine = $this->plugin->get_extension();
		$extensions    = $this->plugin->extensions ?: [];

		$gd_label      = ! empty( $extensions['gd'] ) ? $extensions['gd'] : 'GD Library';
		$imagick_label = ! empty( $extensions['imagick'] ) ? $extensions['imagick'] : 'ImageMagick';

		$items[] = $this->item(
			$imagick_ok ? 'imagick_ok' : 'imagick_unavailable',
			$imagick_ok ? self::STATUS_OK : self::STATUS_INFO,
			$imagick_label,
			$imagick_ok ? __( 'Available.', 'image-watermark' ) : __( 'Not available.', 'image-watermark' ),
			$imagick_ok ? '' : __( 'Install or enable the PHP Imagick extension for best quality.', 'image-watermark' )
		);

		$items[] = $this->item(
			$gd_ok ? 'gd_ok' : 'gd_unavailable',
			$gd_ok ? self::STATUS_OK : self::STATUS_INFO,
			$gd_label,
			$gd_ok ? __( 'Available.', 'image-watermark' ) : __( 'Not available.', 'image-watermark' ),
			$gd_ok ? '' : __( 'Install or enable the PHP GD extension on your server.', 'image-watermark' )
		);

		if ( $active_engine ) {
			$engine_labels = [ 'gd' => $gd_label, 'imagick' => $imagick_label ];
			$active_label  = isset( $engine_labels[ $active_engine ] ) ? $engine_labels[ $active_engine ] : $active_engine;

			$items[] = $this->item(
				'active_engine',
				self::STATUS_OK,
				__( 'Active engine', 'image-watermark' ),
				$active_label,
				''
			);
		} else {
			$items[] = $this->item(
				'no_engine',
				self::STATUS_ERROR,
				__( 'Active engine', 'image-watermark' ),
				__( 'No image processing engine available.', 'image-watermark' ),
				__( 'Install GD or Imagick on your server to enable watermarking.', 'image-watermark' )
			);
		}

		$php_version = phpversion();
		$php_ok      = version_compare( $php_version, '7.3', '>=' );
		$items[] = $this->item(
			$php_ok ? 'php_ok' : 'php_old',
			$php_ok ? self::STATUS_OK : self::STATUS_WARNING,
			__( 'PHP', 'image-watermark' ),
			/* translators: 1: Installed PHP version. */
			sprintf( __( 'PHP %s', 'image-watermark' ), $php_version ),
			$php_ok ? '' : __( 'PHP 7.3+ is recommended. Please upgrade your PHP version.', 'image-watermark' )
		);

		return $items;
	}

	/**
	 * @param array $options
	 * @return array
	 */
	private function build_watermark_section( $options ) {
		$items          = [];
		$watermark_type = isset( $options['watermark_image']['type'] ) ? $options['watermark_image']['type'] : 'image';

		$items[] = $this->item(
			'watermark_type',
			self::STATUS_INFO,
			__( 'Watermark type', 'image-watermark' ),
			$watermark_type === 'image' ? __( 'Image', 'image-watermark' ) : __( 'Text', 'image-watermark' ),
			''
		);

		$items = array_merge( $items, $this->build_watermark_config_items( $options ) );

		return $items;
	}

	/**
	 * Build watermark configuration diagnostic items.
	 *
	 * Shared between the system report and per-attachment reports so the UI
	 * shows the same blocking reasons in both places.
	 *
	 * @param array $options
	 * @return array
	 */
	private function build_watermark_config_items( $options ) {
		$items          = [];
		$watermark_type = isset( $options['watermark_image']['type'] ) ? $options['watermark_image']['type'] : 'image';
		$rotation       = $this->plugin->get_upload_handler()->describe_rotation_capability( $options );

		$items[] = $this->item(
			'watermark_rotation',
			self::STATUS_INFO,
			__( 'Watermark rotation', 'image-watermark' ),
			$rotation['rotation'] === 0
				? __( '0 degrees (no rotation).', 'image-watermark' )
				: sprintf( /* translators: %d: Clockwise rotation degrees. */ __( '%d degrees clockwise.', 'image-watermark' ), $rotation['rotation'] ),
			''
		);

		if ( ! $rotation['available'] ) {
			$items[] = $this->item(
				$rotation['code'],
				self::STATUS_ERROR,
				__( 'Watermark rotation', 'image-watermark' ),
				$rotation['message'],
				__( 'Set rotation to 0 degrees or enable the required image-library rotation support.', 'image-watermark' )
			);
		}

		if ( $watermark_type === 'image' ) {
			$watermark_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
			$source_ok    = $watermark_id > 0 && wp_attachment_is_image( $watermark_id );

			if ( $source_ok ) {
				$upload_dir  = wp_upload_dir();
				$meta        = wp_get_attachment_metadata( $watermark_id, true );
				$source_file = ( is_array( $meta ) && ! empty( $meta['file'] ) )
					? $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $meta['file']
					: '';
				$file_exists = $source_file && is_file( $source_file );

				$items[] = $this->item(
					$file_exists ? 'watermark_source_ok' : 'watermark_source_file_missing',
					$file_exists ? self::STATUS_OK : self::STATUS_ERROR,
					__( 'Watermark image', 'image-watermark' ),
					$file_exists ? __( 'Selected and available.', 'image-watermark' ) : __( 'Attachment exists but file is missing from disk.', 'image-watermark' ),
					$file_exists ? '' : __( 'Re-upload the watermark image or select a different one in Watermark settings.', 'image-watermark' )
				);
			} else {
				$items[] = $this->item(
					'watermark_source_missing',
					self::STATUS_ERROR,
					__( 'Watermark image', 'image-watermark' ),
					$watermark_id > 0
						? __( 'Selected attachment is not a valid image.', 'image-watermark' )
						: __( 'No watermark image selected.', 'image-watermark' ),
					__( 'Go to Watermark settings and select a valid watermark image.', 'image-watermark' )
				);
			}
		} else {
			$text_string = isset( $options['watermark_image']['text_string'] ) ? trim( $options['watermark_image']['text_string'] ) : '';
			$font        = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
			$font_path   = $this->plugin->get_font_path( $font );
			$font_ok     = $font_path && file_exists( $font_path );
			$text_ok     = ! empty( $text_string );

			$items[] = $this->item(
				$text_ok ? 'watermark_text_ok' : 'watermark_text_empty',
				$text_ok ? self::STATUS_OK : self::STATUS_ERROR,
				__( 'Watermark text', 'image-watermark' ),
				$text_ok ? esc_html( $this->safe_truncate( $text_string, 60, '...' ) ) : __( 'No watermark text entered.', 'image-watermark' ),
				$text_ok ? '' : __( 'Enter watermark text in the Watermark settings.', 'image-watermark' )
			);

			$items[] = $this->item(
				$font_ok ? 'watermark_font_ok' : 'watermark_font_missing',
				$font_ok ? self::STATUS_OK : self::STATUS_ERROR,
				__( 'Watermark font', 'image-watermark' ),
				$font_ok ? $font : sprintf( /* translators: Font file name. */ __( 'Font "%s" not found.', 'image-watermark' ), $font ),
				$font_ok ? '' : __( 'Select a different font in Watermark settings.', 'image-watermark' )
			);
		}

		return $items;
	}

	/**
	 * @param array $options
	 * @return array
	 */
	private function build_sizes_section( $options ) {
		$items        = [];
		$watermark_on = isset( $options['watermark_on'] ) && is_array( $options['watermark_on'] ) ? $options['watermark_on'] : [];
		$selected     = array_keys( array_filter( $watermark_on, function( $v ) { return (int) $v === 1; } ) );

		if ( empty( $selected ) ) {
			$items[] = $this->item(
				'no_sizes',
				self::STATUS_ERROR,
				__( 'Image sizes', 'image-watermark' ),
				__( 'No image sizes selected for watermarking.', 'image-watermark' ),
				__( 'Go to Watermark settings and select at least one image size.', 'image-watermark' )
			);
		} else {
			$items[] = $this->item(
				'sizes_selected',
				self::STATUS_OK,
				__( 'Image sizes', 'image-watermark' ),
				implode( ', ', $selected ),
				''
			);
		}

		return $items;
	}

	/**
	 * @param array $options
	 * @return array
	 */
	private function build_backup_section( $options ) {
		$items          = [];
		$backup_enabled = ! empty( $options['backup']['backup_image'] );
		$backup_dir     = defined( 'IMAGE_WATERMARK_BACKUP_DIR' ) ? IMAGE_WATERMARK_BACKUP_DIR : '';
		$dir_exists     = $backup_dir && is_dir( $backup_dir );
		$dir_writable   = $dir_exists && wp_is_writable( $backup_dir );

		$items[] = $this->item(
			$backup_enabled ? 'backup_enabled' : 'backup_disabled',
			$backup_enabled ? self::STATUS_OK : self::STATUS_INFO,
			__( 'Backup images', 'image-watermark' ),
			$backup_enabled ? __( 'Enabled.', 'image-watermark' ) : __( 'Disabled.', 'image-watermark' ),
			$backup_enabled ? '' : __( 'Enable backups if you want to remove future watermarks and restore originals.', 'image-watermark' )
		);

		if ( $backup_enabled ) {
			if ( $dir_exists && $dir_writable ) {
				$items[] = $this->item(
					'backup_folder_ok',
					self::STATUS_OK,
					__( 'Backup folder', 'image-watermark' ),
					__( 'Exists and writable.', 'image-watermark' ),
					''
				);
			} elseif ( $dir_exists ) {
				$items[] = $this->item(
					'backup_folder_not_writable',
					self::STATUS_ERROR,
					__( 'Backup folder', 'image-watermark' ),
					__( 'Exists but not writable.', 'image-watermark' ),
					__( 'Set write permissions on the uploads/iw-backup directory.', 'image-watermark' )
				);
			} else {
				$items[] = $this->item(
					'backup_folder_missing',
					self::STATUS_INFO,
					__( 'Backup folder', 'image-watermark' ),
					__( 'Not yet created (will be created on first watermark apply).', 'image-watermark' ),
					''
				);
			}

			$preserve = ! empty( $options['backup']['preserve_timestamps'] );
			$items[]  = $this->item(
				$preserve ? 'timestamps_preserved' : 'timestamps_not_preserved',
				self::STATUS_INFO,
				__( 'Preserve timestamps', 'image-watermark' ),
				$preserve ? __( 'Enabled.', 'image-watermark' ) : __( 'Disabled.', 'image-watermark' ),
				''
			);
		}

		$items[] = $this->item(
			'backup_http_access_unverified',
			self::STATUS_INFO,
			__( 'Backup web access', 'image-watermark' ),
			__( 'Web access has not been tested. This does not affect watermark processing.', 'image-watermark' ),
			__( 'Configure server-level protection for the backup directory and verify it with an unauthenticated request.', 'image-watermark' )
		);

		return $items;
	}

	/**
	 * Return a path-free attachment inventory for the authorized diagnostics response.
	 *
	 * The watermarked flag is attachment-level state, so each selected target is
	 * reported as watermarked only while that flag is set. A completed recorded
	 * manual removal is the only retained evidence that the current operation
	 * restored the full file and regenerated selected sizes.
	 *
	 * @param array  $data           Attachment metadata.
	 * @param string $source_file    Attached full-size file path.
	 * @param array  $upload_dir     WordPress upload directory data.
	 * @param array  $selected_sizes Selected watermark size names.
	 * @param bool   $is_watermarked Attachment-level watermark state.
	 * @param bool   $backup_exists  Valid backup for the attached full file.
	 * @param int    $attachment_id  Attachment ID.
	 * @return array
	 */
	private function build_attachment_file_inventory( $data, $source_file, $upload_dir, $selected_sizes, $is_watermarked, $backup_exists, $attachment_id ) {
		$relative_file = isset( $data['file'] ) && is_string( $data['file'] ) ? $data['file'] : '';
		$base_dir      = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
		$base_url      = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';
		$relative_dir  = $relative_file ? dirname( $relative_file ) : '';
		$last_op       = $this->plugin->get_upload_handler()->get_attachment_operation_outcome( $attachment_id );
		$restored      = is_array( $last_op ) && isset( $last_op['outcome'], $last_op['context'] ) && $last_op['outcome'] === 'complete' && $last_op['context'] === 'manual-remove';
		$full_target   = in_array( 'full', $selected_sizes, true );

		$entry = function( $path, $relative, $target, $backup, $restore ) use ( $base_url, $is_watermarked ) {
			return [
				'exists'                       => is_string( $path ) && is_file( $path ),
				'watermark_target'             => (bool) $target,
				'watermarked'                  => (bool) ( $is_watermarked && $target ),
				'backup_exists'                => (bool) $backup,
				'restored_by_last_operation'   => (bool) $restore,
				'public_url_derivable'         => is_string( $base_url ) && $base_url !== '' && is_string( $relative ) && $relative !== '' && strpos( $relative, "\0" ) === false && strpos( str_replace( '\\', '/', $relative ), '../' ) === false,
			];
		};

		$inventory = [
			'full'  => $entry( $source_file, $relative_file, $full_target, $backup_exists, $restored && $full_target ),
			'sizes' => [],
		];

		if ( preg_match( '/-scaled\.[^.]+$/i', basename( $relative_file ) ) ) {
			$inventory['scaled_original'] = $entry( $source_file, $relative_file, $full_target, $backup_exists, $restored && $full_target );
		}

		if ( ! empty( $data['original_image'] ) && is_string( $data['original_image'] ) ) {
			$original_relative = ( $relative_dir && $relative_dir !== '.' ? $relative_dir . '/' : '' ) . $data['original_image'];
			$original_path     = $base_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $original_relative );
			$inventory['original_image'] = $entry( $original_path, $original_relative, false, false, false );
		}

		foreach ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? $data['sizes'] : [] as $name => $size ) {
			if ( empty( $size['file'] ) || ! is_string( $size['file'] ) ) {
				continue;
			}
			$size_relative = ( $relative_dir && $relative_dir !== '.' ? $relative_dir . '/' : '' ) . $size['file'];
			$size_path     = $base_dir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $size_relative );
			$selected      = in_array( $name, $selected_sizes, true );
			$inventory['sizes'][ sanitize_key( $name ) ] = $entry( $size_path, $size_relative, $selected, false, $restored && $selected );
		}

		return $inventory;
	}

	/**
	 * @param array $options
	 * @return array
	 */
	private function build_modes_section( $options ) {
		$items       = [];
		$auto_on     = ! empty( $options['watermark_image']['plugin_off'] );
		$manual_on   = ! empty( $options['watermark_image']['manual_watermarking'] );
		$frontend_on = ! empty( $options['watermark_image']['frontend_active'] );

		$items[] = $this->item(
			$auto_on ? 'auto_on' : 'auto_off',
			$auto_on ? self::STATUS_OK : self::STATUS_WARNING,
			__( 'Automatic watermarking', 'image-watermark' ),
			$auto_on ? __( 'Enabled.', 'image-watermark' ) : __( 'Disabled.', 'image-watermark' ),
			$auto_on ? '' : __( 'Enable "Automatic Watermarking" in Watermark settings to watermark new uploads automatically.', 'image-watermark' )
		);

		$items[] = $this->item(
			$manual_on ? 'manual_on' : 'manual_off',
			$manual_on ? self::STATUS_OK : self::STATUS_INFO,
			__( 'Manual watermarking', 'image-watermark' ),
			$manual_on ? __( 'Enabled.', 'image-watermark' ) : __( 'Disabled.', 'image-watermark' ),
			$manual_on ? '' : __( 'Enable "Manual Watermarking" in Watermark settings to allow applying and removing watermarks from existing images.', 'image-watermark' )
		);

		$items[] = $this->item(
			$frontend_on ? 'frontend_on' : 'frontend_off',
			self::STATUS_INFO,
			__( 'Front-end watermarking', 'image-watermark' ),
			$frontend_on ? __( 'Enabled.', 'image-watermark' ) : __( 'Disabled.', 'image-watermark' ),
			''
		);

		return $items;
	}

	/**
	 * @return array
	 */
	private function build_support_section() {
		$version = isset( $this->plugin->defaults['version'] ) ? $this->plugin->defaults['version'] : '';

		return [
			$this->item(
				'plugin_version',
				self::STATUS_INFO,
				__( 'Plugin version', 'image-watermark' ),
				'Image Watermark ' . $version,
				''
			),
			$this->item(
				'cdn_note',
				self::STATUS_INFO,
				__( 'CDN / caching note', 'image-watermark' ),
				__( 'If you use a CDN or aggressive caching, watermarked images may not appear updated immediately.', 'image-watermark' ),
				__( 'Purge your CDN or object cache after applying watermarks if thumbnails do not update.', 'image-watermark' )
			),
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a standardized diagnostic item.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $status  ok|warning|error|info.
	 * @param string $label   Human-readable label.
	 * @param string $message Human-readable message.
	 * @param string $hint    Remediation hint.
	 * @return array
	 */
	private function item( $code, $status, $label, $message, $hint = '' ) {
		return [
			'code'    => $code,
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
			'hint'    => $hint,
		];
	}

	/**
	 * Returns true when the image dimensions fall below the configured minimum.
	 * Mirrors Image_Watermark_Upload_Handler::should_skip_small_image() without coupling.
	 *
	 * @param array $options Plugin options.
	 * @param int   $width   Image width.
	 * @param int   $height  Image height.
	 * @return bool
	 */
	private function is_too_small( $options, $width, $height ) {
		if ( empty( $options['watermark_image']['skip_small_images'] ) ) {
			return false;
		}

		$min_w = isset( $options['watermark_image']['min_image_width'] ) ? max( 0, (int) $options['watermark_image']['min_image_width'] ) : 0;
		$min_h = isset( $options['watermark_image']['min_image_height'] ) ? max( 0, (int) $options['watermark_image']['min_image_height'] ) : 0;

		if ( $min_w <= 0 && $min_h <= 0 ) {
			return false;
		}

		if ( $min_w > 0 && $width < $min_w ) {
			return true;
		}

		if ( $min_h > 0 && $height < $min_h ) {
			return true;
		}

		return false;
	}

	/**
	 * Safely truncate a string without requiring the mbstring extension.
	 *
	 * @param string $string Input string.
	 * @param int    $length Maximum length in characters.
	 * @param string $suffix Suffix appended when truncated.
	 * @return string
	 */
	private function safe_truncate( $string, $length, $suffix = '...' ) {
		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $string, 0, $length, $suffix );
		}

		if ( function_exists( 'mb_substr' ) ) {
			$truncated = mb_substr( $string, 0, $length );
			return $truncated === $string ? $string : $truncated . $suffix;
		}

		$truncated = substr( $string, 0, $length );
		return $truncated === $string ? $string : $truncated . $suffix;
	}
}
