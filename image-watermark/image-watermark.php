<?php
/*
Plugin Name: Image Watermark
Description: Secure and brand your images with automatic watermarks. Apply image or text overlays to new uploads and bulk process existing Media Library images with ease.
Version: 2.0.14
Requires at least: 6.3
Requires PHP: 7.3
Author: dFactory
Author URI: https://www.dfactory.co/
Plugin URI: https://www.dfactory.co/products/image-watermark/
License: MIT
License URI: https://opensource.org/licenses/MIT
Text Domain: image-watermark
Domain Path: /languages

Image Watermark
Copyright (C) 2013-2026, Digital Factory - info@digitalfactory.pl

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Image Watermark class.
 *
 * @class Image_Watermark
 * @version	2.0.14
 */
final class Image_Watermark {

	private static $instance;
	private $extension = false;
	private $upload_handler;
	private $watermark_controller;
	private $diagnostics;
	private $settings;
	private $allowed_mime_types = [
		'image/webp',
		'image/jpeg',
		'image/pjpeg',
		'image/png'
	];
	private $allowed_fonts = [
		'Caveat-Regular.ttf'            => 'Caveat',
		'Dosis-Regular.ttf'             => 'Dosis',
		'Lato-Regular.ttf'              => 'Lato',
		'LibreBaskerville-Regular.ttf'  => 'Libre Baskerville',
		'Merriweather-Regular.ttf'      => 'Merriweather',
		'OpenSans-Regular.ttf'          => 'Open Sans',
		'Roboto-Regular.ttf'            => 'Roboto',
		'Ubuntu-Regular.ttf'            => 'Ubuntu',
	];
	private $is_watermarked_metakey = 'iw-is-watermarked';
	public $is_backup_folder_writable = null;
	public $extensions;
	public $defaults = [
		'options'	 => [
			'watermark_on'		 => [],
			'watermark_apply_on' => 'everywhere',
			'watermark_cpt_on'	 => [],
			'watermark_image'	 => [
				'extension'				 => '',
				'url'					 => 0,
				'type'				 => 'image',
				'text_string'		 => '',
				'text_font'			 => 'Lato-Regular.ttf',
				'text_color'			 => '#000000',
				'text_size'				 => 24,
				'width'					 => 80,
				'plugin_off'			 => 0,
				'frontend_active'		 => false,
				'manual_watermarking'	 => 0,
				'skip_small_images'	 => 0,
				'min_image_width'		 => 0,
				'min_image_height'		 => 0,
				'position'				 => 'bottom_right',
				'rotation'				 => 0,
				'watermark_size_type'	 => 2,
				'offset_unit'			 => 'pixels',
				'offset_width'			 => 0,
				'offset_height'			 => 0,
				'absolute_width'		 => 0,
				'absolute_height'		 => 0,
				'transparent'			 => 50,
				'quality'				 => 90,
				'jpeg_format'			 => 'baseline',
				'deactivation_delete'	 => false,
				'review_notice'			 => true,
				'review_delay_date'		 => 0
			],
			'image_protection'	 => [
				'rightclick'		 => 1,
				'draganddrop'		 => 1,
				'devtools'			 => 1,
				'forlogged'			 => 1,
				'enable_toast'		 => 1,
				'toast_message'		 => 'This content is protected'
			],
			'backup'			 => [
				'backup_image'	 => true,
				'preserve_timestamps' => false
			]
		],
		'version'	 => '2.0.14'
	];
	public $options = [];

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		// define plugin constants
		$this->define_constants();

		// activation hooks
		register_activation_hook( __FILE__, [ $this, 'activate_watermark' ] );
		register_deactivation_hook( __FILE__, [ $this, 'deactivate_watermark' ] );

		// settings
		$options = get_option( 'image_watermark_options', $this->defaults['options'] );

		$this->set_options( $options );

		include_once( IMAGE_WATERMARK_PATH . 'includes/class-update.php' );
		include_once( IMAGE_WATERMARK_PATH . 'includes/class-settings-api.php' );
		include_once( IMAGE_WATERMARK_PATH . 'includes/class-settings.php' );
		include_once( IMAGE_WATERMARK_PATH . 'includes/class-upload-handler.php' );
		include_once( IMAGE_WATERMARK_PATH . 'includes/class-actions-controller.php' );
		include_once( IMAGE_WATERMARK_PATH . 'includes/class-diagnostics.php' );

		$this->settings = new Image_Watermark_Settings( $this );

		$this->upload_handler = new Image_Watermark_Upload_Handler( $this );
		$this->watermark_controller = new Image_Watermark_Actions_Controller( $this, $this->upload_handler );
		$this->diagnostics = new Image_Watermark_Diagnostics( $this );

		// actions
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_action( 'wp_enqueue_media', [ $this, 'wp_enqueue_media' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ] );
		add_action( 'load-upload.php', [ $this, 'watermark_bulk_action' ] );
		add_action( 'admin_init', [ $this, 'update_plugin' ] );
		add_action( 'admin_init', [ $this, 'check_extensions' ] );
		add_action( 'admin_init', [ $this, 'check_review_notice' ] );
		add_action( 'admin_init', [ $this, 'redirect_old_slug' ], 9 );
		add_action( 'admin_notices', [ $this, 'bulk_admin_notices' ] );
		add_action( 'delete_attachment', [ $this->upload_handler, 'delete_attachment' ] );
		add_action( 'wp_ajax_iw_watermark_bulk_action', [ $this->watermark_controller, 'watermark_action_ajax' ] );
		add_action( 'wp_ajax_iw_diagnose_attachment', [ $this->watermark_controller, 'diagnose_attachment_ajax' ] );
		add_action( 'wp_ajax_iw_text_preview', [ $this, 'text_preview_ajax' ] );
		add_action( 'wp_ajax_iw_dismiss_notice', [ $this, 'dismiss_review_notice' ] );
		add_action( 'attachment_submitbox_misc_actions', [ $this, 'render_attachment_editor_actions' ], 20 );

		// filters
		add_filter( 'plugin_action_links_' . IMAGE_WATERMARK_BASENAME, [ $this, 'plugin_settings_link' ] );
		add_filter( 'plugin_row_meta', [ $this, 'plugin_extend_links' ], 10, 2 );
		add_filter( 'wp_handle_upload', [ $this, 'handle_upload_files' ] );
		add_filter( 'attachment_fields_to_edit', [ $this, 'attachment_fields_to_edit' ], 10, 2 );

		// define our backup location
		$upload_dir = wp_upload_dir();

		define( 'IMAGE_WATERMARK_BACKUP_DIR', apply_filters( 'image_watermark_backup_dir', $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'iw-backup' ) );

	}

	/**
	 * Disable object cloning.
	 *
	 * @return void
	 */
	public function __clone() {}

	/**
	 * Disable unserializing of the class.
	 *
	 * @return void
	 */
	public function __wakeup() {}

	/**
	 * Main plugin instance, insures that only one instance of the plugin exists in memory at one time.
	 *
	 * @return object
	 */
	public static function instance() {
		if ( self::$instance === null )
			self::$instance = new self();

		return self::$instance;
	}

	/**
	 * Setup plugin constants.
	 *
	 * @return void
	 */
	private function define_constants() {
		define( 'IMAGE_WATERMARK_URL', plugins_url( '', __FILE__ ) );
		define( 'IMAGE_WATERMARK_PATH', plugin_dir_path( __FILE__ ) );
		define( 'IMAGE_WATERMARK_BASENAME', plugin_basename( __FILE__ ) );
		define( 'IMAGE_WATERMARK_REL_PATH', dirname( IMAGE_WATERMARK_BASENAME ) );
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public function activate_watermark() {
		// add default options
		add_option( 'image_watermark_options', $this->defaults['options'], '', false );
		add_option( 'image_watermark_version', $this->defaults['version'], '', false );
		
		// set activation date if not exists
		$activation_date = get_option( 'image_watermark_activation_date' );
		if ( $activation_date === false ) {
			add_option( 'image_watermark_activation_date', time(), '', false );
		}
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivate_watermark() {
		// remove options from database?
		if ( $this->options['watermark_image']['deactivation_delete'] )
			delete_option( 'image_watermark_options' );
	}

	/**
	 * Compatible admin callback for the ordered migration coordinator.
	 *
	 * @return void
	 */
	public function update_plugin() {
		if ( current_user_can( 'install_plugins' ) ) {
			$this->migrate_options();
		}
	}

	/**
	 * Run every applicable option migration before recording the installed version.
	 * Legacy shape checks deliberately remain active after a version was advanced so
	 * interrupted upgrades can be repaired without rewriting attachments or deleting
	 * their source options.
	 *
	 * @return bool Whether all required writes were verified.
	 */
	public function migrate_options() {
		$stored_version = get_option( 'image_watermark_version', false );
		$legacy_version = get_option( 'version', false );
		$version = $this->get_migration_version( $stored_version );
		if ( $version === null && get_option( 'df_watermark_installed', false ) !== false ) {
			$version = $this->get_migration_version( $legacy_version );
		}
		if ( $version === null ) {
			$version = '0.0.0';
		}

		$stored_options = get_option( 'image_watermark_options', [] );
		$options = is_array( $stored_options ) ? $stored_options : [];
		$changed = ! is_array( $stored_options );
		$legacy_map = [
			'df_watermark_on' => 'watermark_on',
			'df_watermark_cpt_on' => 'watermark_cpt_on',
			'df_watermark_image' => 'watermark_image',
			'df_image_protection' => 'image_protection',
		];
		foreach ( $legacy_map as $old => $new ) {
			$legacy_value = get_option( $old, false );
			if ( $legacy_value !== false ) {
				if ( ! array_key_exists( $new, $options ) ) {
					$options[$new] = $legacy_value;
					$changed = true;
				}
			}
		}

		if ( ( version_compare( $version, '2.0.2', '<' ) || ( isset( $options['watermark_image'] ) && ! is_array( $options['watermark_image'] ) ) ) && isset( $options['watermark_image'] ) && ! is_array( $options['watermark_image'] ) ) {
			$options['watermark_image'] = array_merge( $this->defaults['options']['watermark_image'], [ 'url' => (int) $options['watermark_image'] ] );
			$changed = true;
		}

		$cpt_on = isset( $options['watermark_cpt_on'] ) && is_array( $options['watermark_cpt_on'] ) ? $options['watermark_cpt_on'] : null;
		$needs_apply_on = version_compare( $version, '2.0.4', '<' ) || ! array_key_exists( 'watermark_apply_on', $options );
		if ( $cpt_on !== null && array_values( $cpt_on ) === $cpt_on && in_array( 'everywhere', $cpt_on, true ) ) {
			$needs_apply_on = true;
		}
		if ( $needs_apply_on ) {
			$apply_on = 'everywhere';
			if ( $cpt_on !== null ) {
				$is_list = array_values( $cpt_on ) === $cpt_on;
				$has_everywhere = $is_list ? in_array( 'everywhere', $cpt_on, true ) : array_key_exists( 'everywhere', $cpt_on );
				$apply_on = $has_everywhere ? 'everywhere' : 'post_types';
				if ( $is_list ) {
					$cpt_on = array_values( array_diff( $cpt_on, [ 'everywhere' ] ) );
				} elseif ( array_key_exists( 'everywhere', $cpt_on ) ) {
					unset( $cpt_on['everywhere'] );
				}
				$options['watermark_cpt_on'] = $cpt_on;
			}
			$options['watermark_apply_on'] = $apply_on;
			$changed = true;
		}

		if ( $changed ) {
			update_option( 'image_watermark_options', $options );
			if ( get_option( 'image_watermark_options', null ) !== $options ) {
				return false;
			}
		}

		// A version is the final commit point: never advertise success before options
		// have been durably updated and reread.
		if ( $stored_version !== $this->defaults['version'] ) {
			update_option( 'image_watermark_version', $this->defaults['version'], false );
			if ( get_option( 'image_watermark_version', null ) !== $this->defaults['version'] ) {
				return false;
			}
		}

		$this->set_options( get_option( 'image_watermark_options', [] ) );
		return true;
	}

	/**
	 * Normalize a stored semantic version or return null for absent/corrupt data.
	 *
	 * @param mixed $version Version option value.
	 * @return string|null
	 */
	private function get_migration_version( $version ) {
		return is_string( $version ) && preg_match( '/^\d+(?:\.\d+){0,2}$/', $version ) ? $version : null;
	}

	/**
	 * Merge persisted options with defaults without accepting corrupt nested shapes.
	 *
	 * @param mixed $options Stored option value.
	 * @return void
	 */
	private function set_options( $options ) {
		if ( ! is_array( $options ) ) {
			$options = [];
		}
		if ( ! isset( $options['watermark_apply_on'] ) ) {
			$options['watermark_apply_on'] = 'everywhere';
		}
		$this->options = array_merge( $this->defaults['options'], $options );
		foreach ( [ 'watermark_image', 'image_protection', 'backup' ] as $group ) {
			$this->options[$group] = array_merge( $this->defaults['options'][$group], isset( $options[$group] ) && is_array( $options[$group] ) ? $options[$group] : [] );
		}
	}

	/**
	 * Load textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'image-watermark', false, IMAGE_WATERMARK_REL_PATH . '/languages/' );
	}

	public function wp_enqueue_media( $page ) {
		global $pagenow;
		if ( $pagenow !== 'options-general.php' || ! isset( $_GET['page'] ) || $_GET['page'] !== 'image-watermark' ) {
			wp_enqueue_style( 'watermark-admin', IMAGE_WATERMARK_URL . '/css/admin.css', [], $this->defaults['version'] );
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @global $pagenow
	 * @return void
	 */
	public function admin_enqueue_scripts( $page ) {
		global $pagenow;

		wp_register_style( 'watermark-admin-settings', IMAGE_WATERMARK_URL . '/css/admin-settings.css', [], $this->defaults['version'] );
		wp_register_style( 'watermark-admin', IMAGE_WATERMARK_URL . '/css/admin.css', [], $this->defaults['version'] );

		$media_script_data = null;
		if ( $this->options['watermark_image']['manual_watermarking'] == 1 && current_user_can( 'upload_files' ) ) {
			$media_script_data = [
				'backupImage'		=> (bool) $this->options['backup']['backup_image'],
				// Creation consent does not determine removal: the controller verifies
				// the selected attachment's existing backup with is_valid_backup_file().
				'removeWatermarkAvailable' => true,
				'applyWatermark'	=> __( 'Apply watermark', 'image-watermark' ),
				'removeWatermark'	=> __( 'Remove watermark', 'image-watermark' )
			];
		}

		if ( $page === 'settings_page_image-watermark' ) {
			wp_enqueue_media();

			wp_enqueue_script( 'image-watermark-upload-manager', IMAGE_WATERMARK_URL . '/js/admin-upload.js', [], $this->defaults['version'], true );

			// prepare script data
			$script_data = [
				'title'			=> __( 'Select watermark image', 'image-watermark' ),
				'originalSize'	=> __( 'Original size', 'image-watermark' ),
				'noSelectedImg'	=> __( 'No watermark image has been selected yet.', 'image-watermark' ),
				'notAllowedImg'	=> __( 'This image cannot be used as a watermark. Use a JPEG, PNG, WebP, or GIF image.', 'image-watermark' ),
				'px'			=> __( 'px', 'image-watermark' ),
				'frame'			=> 'select',
				'button'		=> [ 'text' => __( 'Add watermark image', 'image-watermark' ) ],
				'multiple'		=> false
			];

			wp_add_inline_script( 'image-watermark-upload-manager', 'var iwArgsUpload = ' . wp_json_encode( $script_data ) . ";\n", 'before' );

			wp_enqueue_script( 'image-watermark-admin-settings', IMAGE_WATERMARK_URL . '/js/admin-settings.js', [], $this->defaults['version'], true );

			// prepare script data
			$script_data = [
				'resetToDefaults'    => __( 'Are you sure you want to reset all settings to their default values?', 'image-watermark' ),
				'generatePreview'    => __( 'Generate Preview', 'image-watermark' ),
				'generatingPreview'  => __( 'Generating...', 'image-watermark' ),
				'previewNonce'       => wp_create_nonce( 'iw_text_preview' ),
				'originImageLabel'   => __( 'Original watermark image:', 'image-watermark' ),
				'originImageMissing' => __( 'No watermark image selected.', 'image-watermark' ),
				'originImageLoading' => __( 'Loading…', 'image-watermark' ),
				'originTextLabel'    => __( 'Original text size:', 'image-watermark' ),
				'originTextEmpty'    => __( 'Enter text to preview.', 'image-watermark' ),
				'copyReportSuccess'  => __( 'Copied!', 'image-watermark' ),
				'copyReportFailed'   => __( 'Copy failed — please select and copy manually.', 'image-watermark' ),
			];

			wp_add_inline_script( 'image-watermark-admin-settings', 'var iwArgsSettings = ' . wp_json_encode( $script_data ) . ";\n", 'before' );

			wp_enqueue_style( 'watermark-admin-settings' );

			wp_enqueue_script( 'postbox' );
		}

		if ( $pagenow === 'upload.php' ) {
			if ( $media_script_data ) {
				wp_enqueue_script( 'image-watermark-admin-media', IMAGE_WATERMARK_URL . '/js/admin-media.js', [], $this->defaults['version'], false );

				wp_add_inline_script( 'image-watermark-admin-media', 'var iwArgsMedia = ' . wp_json_encode( $media_script_data ) . ";\n", 'before' );
			}

			wp_enqueue_style( 'watermark-admin' );
		}

		// image modal could be loaded in various places
		if ( $this->options['watermark_image']['manual_watermarking'] == 1 ) {
			wp_enqueue_script( 'image-watermark-admin-image-actions', IMAGE_WATERMARK_URL . '/js/admin-image-actions.js', [], $this->defaults['version'], true );

			if ( $media_script_data ) {
				wp_add_inline_script( 'image-watermark-admin-image-actions', 'var iwArgsMedia = ' . wp_json_encode( $media_script_data ) . ";\n", 'before' );
			}

			// prepare script data
			$script_data = [
				'_nonce'			=> wp_create_nonce( 'image-watermark' ),
				'allowed_mimes'		=> $this->get_allowed_mime_types(),
				'apply_label'		=> __( 'Apply watermark', 'image-watermark' ),
				'remove_label'		=> __( 'Remove watermark', 'image-watermark' ),
				'setting_label'		=> __( 'Watermark', 'image-watermark' ),
				'single_running'	=> __( 'Working…', 'image-watermark' ),
				'single_applied'	=> __( 'Watermark applied.', 'image-watermark' ),
				'single_removed'	=> __( 'Watermark removed.', 'image-watermark' ),
				'single_error'		=> __( 'Action failed.', 'image-watermark' ),
				'single_skipped'	=> __( 'Action skipped.', 'image-watermark' ),
				'__applied_none'	=> __( 'The watermark could not be applied to the selected files because no valid images (JPEG, PNG, WebP) were selected.', 'image-watermark' ),
				'__applied_one'		=> __( 'Watermark was successfully applied to 1 image.', 'image-watermark' ),
				/* translators: Number of images successfully watermarked. */
				'__applied_multi'	=> __( 'Watermark was successfully applied to %s images.', 'image-watermark' ),
				'__removed_none'	=> __( 'The watermark could not be removed from the selected files because no valid images (JPEG, PNG, WebP) were selected.', 'image-watermark' ),
				'__removed_one'		=> __( 'Watermark was successfully removed from 1 image.', 'image-watermark' ),
				/* translators: Number of images successfully restored from backups. */
				'__removed_multi'	=> __( 'Watermark was successfully removed from %s images.', 'image-watermark' ),
				'__skipped'			=> __( 'Skipped images', 'image-watermark' ),
				'__running'			=> __( 'A bulk action is currently running. Please wait…', 'image-watermark' ),
				'bulk_unknown'		=> __( 'Unknown - possibly completed; verify whether the image is watermarked before retrying. The request was not resent.', 'image-watermark' ),
				'bulk_not_submitted'	=> __( 'Unknown - not submitted after a request error; verify whether the image is watermarked before retrying.', 'image-watermark' ),
				'__dismiss'			=> __( 'Dismiss this notice.', 'image-watermark' ),
			];

			wp_add_inline_script( 'image-watermark-admin-image-actions', 'var iwArgsImageActions = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
		}

		if ( $pagenow === 'post.php' && $this->options['watermark_image']['manual_watermarking'] == 1 && current_user_can( 'upload_files' ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if ( $screen && $screen->post_type === 'attachment' && $post_id && current_user_can( 'edit_post', $post_id ) ) {
				$mime = get_post_mime_type( $post_id );

				if ( in_array( $mime, $this->get_allowed_mime_types(), true ) ) {
					wp_enqueue_script( 'image-watermark-admin-classic', IMAGE_WATERMARK_URL . '/js/admin-classic-editor.js', [], $this->defaults['version'], true );

					$script_data = [
						'postId'        => $post_id,
						'attachmentId'  => $post_id,
						'nonce'         => wp_create_nonce( 'image-watermark' ),
						'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
						'strings'       => [
							'apply'        => __( 'Apply watermark', 'image-watermark' ),
							'remove'       => __( 'Remove watermark', 'image-watermark' ),
							'applied'      => __( 'Watermark applied.', 'image-watermark' ),
							'removed'      => __( 'Watermark removed.', 'image-watermark' ),
							'error'        => __( 'Action failed.', 'image-watermark' ),
							'running'      => __( 'Working...', 'image-watermark' ),
						],
					];

					wp_add_inline_script( 'image-watermark-admin-classic', 'var iwArgsClassic = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
				}
			}
		}
	}

	/**
	 * Enqueue frontend script with 'no right click' and 'drag and drop' functions.
	 *
	 * @return void
	 */
	public function wp_enqueue_scripts() {
		$right_click = true;

		if ( ( $this->options['image_protection']['forlogged'] == 0 && is_user_logged_in() ) || ( $this->options['image_protection']['draganddrop'] == 0 && $this->options['image_protection']['rightclick'] == 0 && $this->options['image_protection']['devtools'] == 0 ) )
			$right_click = false;

		if ( apply_filters( 'iw_block_right_click', (bool) $right_click ) === true ) {
			wp_enqueue_script( 'image-watermark-no-right-click', IMAGE_WATERMARK_URL . '/js/no-right-click.js', [], $this->defaults['version'], true );

			// prepare script data
			$script_data = [
				'rightclick'		=> ( $this->options['image_protection']['rightclick'] == 1 ? 'Y' : 'N' ),
				'draganddrop'		=> ( $this->options['image_protection']['draganddrop'] == 1 ? 'Y' : 'N' ),
				'devtools'			=> ( $this->options['image_protection']['devtools'] == 1 ? 'Y' : 'N' ),
				'enableToast'		=> ( $this->options['image_protection']['enable_toast'] == 1 ? 'Y' : 'N' ),
					'toastMessage'		=> ! empty( $this->options['image_protection']['toast_message'] ) ? $this->options['image_protection']['toast_message'] : __( 'This content is protected', 'image-watermark' )
			];

			wp_add_inline_script( 'image-watermark-no-right-click', 'var iwArgsNoRightClick = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
		}
	}

	/**
	 * Check which extension is available and set it.
	 *
	 * @return void
	 */
	public function check_extensions() {
		$ext = null;

		if ( $this->check_imagick() ) {
			$this->extensions['imagick'] = 'ImageMagick';
			$ext = 'imagick';
		}

		if ( $this->check_gd() ) {
			$this->extensions['gd'] = 'GD Library';

			if ( is_null( $ext ) )
				$ext = 'gd';
		}

		if ( isset( $this->options['watermark_image']['extension'] ) ) {
			if ( $this->options['watermark_image']['extension'] === 'imagick' && isset( $this->extensions['imagick'] ) )
				$this->extension = 'imagick';
			elseif ( $this->options['watermark_image']['extension'] === 'gd' && isset( $this->extensions['gd'] ) )
				$this->extension = 'gd';
			else
				$this->extension = $ext;
		} else
			$this->extension = $ext;
	}

	/**
	 * Check and display review notice.
	 *
	 * @return void
	 */
	public function check_review_notice() {
		// Only for admins who can install plugins
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		
		// Get activation date
		$activation_date = get_option( 'image_watermark_activation_date' );
		
		// If no activation date, set it now (for existing installations)
		if ( $activation_date === false ) {
			$activation_date = time();
			update_option( 'image_watermark_activation_date', $activation_date );
		}
		
		// Current time
		$current_time = time();
		
		// Check if notice is enabled
		if ( $this->options['watermark_image']['review_notice'] !== true ) {
			return;
		}
		
		// Initialize delay date if needed
		if ( (int) $this->options['watermark_image']['review_delay_date'] === 0 ) {
			// Set delay to 2 weeks from activation, or now if already past
			if ( $activation_date + 2 * WEEK_IN_SECONDS > $current_time ) {
				$this->options['watermark_image']['review_delay_date'] = $activation_date + 2 * WEEK_IN_SECONDS;
			} else {
				$this->options['watermark_image']['review_delay_date'] = $current_time;
			}
			
			update_option( 'image_watermark_options', $this->options );
		}
		
		// Check if it's time to show the notice
		$delay_date = (int) $this->options['watermark_image']['review_delay_date'];
		if ( $delay_date <= $current_time ) {
			// Add inline script for notice dismissal
			add_action( 'admin_print_scripts', [ $this, 'review_notice_inline_js' ], 999 );
			
			// Display the notice
			add_action( 'admin_notices', [ $this, 'display_review_notice' ] );
		}
	}

	/**
	 * Display review notice.
	 *
	 * @return void
	 */
	public function display_review_notice() {
		// Get activation date
		$activation_date = get_option( 'image_watermark_activation_date', time() );
		
		// Build notice message
		/* translators: Human-readable elapsed time since plugin activation. */
		$message = sprintf( 
			/* translators: 1: Human-readable elapsed time since activation. */
			__( "Hey, you've been using <strong>Image Watermark</strong> for more than %s.", 'image-watermark' ),
			human_time_diff( $activation_date, time() )
		);
		
		$message .= '<br /><br />' . __( 'Could you please do me a BIG favor and give it a 5-star rating on WordPress to help us spread the word and boost our motivation.', 'image-watermark' ) . ' ' . __( 'Your help is much appreciated. Thank you!', 'image-watermark' );
		$message .= '<br /><br />';
		
		// Action links
		$message .= '<a href="' . esc_url( 'https://wordpress.org/support/plugin/image-watermark/reviews/' ) . '" class="iw-dismissible-notice" target="_blank" rel="noopener">' . __( 'Ok, you deserve it', 'image-watermark' ) . '</a>';
		
		$message .= '<br /><a href="#" class="iw-dismissible-notice iw-delay-notice" rel="noopener">' . __( 'Nope, maybe later', 'image-watermark' ) . '</a>';
		
		$message .= '<br /><a href="#" class="iw-dismissible-notice" rel="noopener">' . __( 'I already did', 'image-watermark' ) . '</a>';
		
		?>
		<div class="notice notice-info is-dismissible iw-notice iw-review-notice">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Enqueue review notice script.
	 *
	 * @return void
	 */
	public function review_notice_inline_js() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}
		
		// Enqueue the built JavaScript file
		wp_enqueue_script( 
			'iw-review-notice', 
			IMAGE_WATERMARK_URL . '/js/admin-notice.js', 
			[], 
			$this->defaults['version'],
			true
		);
		
		// Pass data to JavaScript
		$script_data = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'iw_dismiss_notice' )
		];
		
		wp_add_inline_script( 'iw-review-notice', 'var iwArgsReviewNotice = ' . wp_json_encode( $script_data ) . ";\n", 'before' );
	}

	/**
	 * Handle review notice dismissal via AJAX.
	 *
	 * @return void
	 */
	public function dismiss_review_notice() {
		// Permission check
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die();
		}
		
		// Verify nonce
		$nonce = isset( $_REQUEST['nonce'] ) && is_string( $_REQUEST['nonce'] ) ? wp_unslash( $_REQUEST['nonce'] ) : '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'iw_dismiss_notice' ) ) {
			wp_die();
		}
		
		// Sanitize action
		$notice_action = isset( $_REQUEST['notice_action'] ) && is_string( $_REQUEST['notice_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['notice_action'] ) ) : 'hide';
		
		// Update options based on action
		switch ( $notice_action ) {
			case 'delay':
				// Delay notice for 2 weeks
				$this->options['watermark_image']['review_delay_date'] = time() + 2 * WEEK_IN_SECONDS;
				break;
				
			default:
				// Hide notice permanently
				$this->options['watermark_image']['review_notice'] = false;
				$this->options['watermark_image']['review_delay_date'] = 0;
				break;
		}
		
		// Save options
		update_option( 'image_watermark_options', $this->options );
		
		// Exit
		wp_die();
	}

	/**
	 * Redirect old slug to new slug for backward compatibility.
	 *
	 * @return void
	 */
	public function redirect_old_slug() {
		if ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) && wp_unslash( $_GET['page'] ) === 'watermark-options' ) {
			$url = admin_url( 'options-general.php?page=image-watermark' );
			if ( isset( $_GET['tab'] ) ) {
				$url = add_query_arg( 'tab', sanitize_key( wp_unslash( $_GET['tab'] ) ), $url );
			}
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Apply watermark everywhere or for specific post types.
	 *
	 * @param resource $file
	 * @return resource
	 */
	public function handle_upload_files( $file ) {
		return $this->get_upload_handler()->handle_upload_files( $file );
	}

	/**
	 * Handle manual watermark AJAX requests.
	 *
	 * @return void
	 */
	public function text_preview_ajax() {
		if ( ! check_ajax_referer( 'iw_text_preview', 'nonce', false ) ) {
			wp_send_json_error( __( 'Invalid security token.', 'image-watermark' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to perform this action.', 'image-watermark' ) );
		}

		$preview = $this->settings->validate_text_preview_options( wp_unslash( $_POST ), $this->options );
		if ( ! $preview['success'] ) {
			wp_send_json_error( $preview['error'] );
		}
		$options = $preview['options'];

		// Choose engine for preview (prefer active one).
		$engine = $this->get_extension();

		if ( ! $engine ) {
			$this->check_extensions();
			$engine = $this->get_extension();
		}

		if ( ! $engine ) {
			wp_send_json_error( __( 'No image library available to generate a preview.', 'image-watermark' ) );
		}

		if ( $engine === 'imagick' && ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick', false ) ) ) {
			wp_send_json_error( __( 'Imagick is selected but not available on the server.', 'image-watermark' ) );
		}

		if ( $engine === 'gd' && ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagejpeg' ) ) ) {
			wp_send_json_error( __( 'GD with JPEG support is required to generate a preview.', 'image-watermark' ) );
		}

		$sample_image_path = false;
		$image_data = false;
		$error = false;
		try {
			$sample_image_path = $this->create_sample_image( $engine );
			if ( ! $sample_image_path ) {
				$error = __( 'Failed to create sample image.', 'image-watermark' );
			} elseif ( ! $this->upload_handler->do_watermark( 0, $sample_image_path, 'full', wp_upload_dir(), [], $options ) ) {
				$error = __( 'Failed to render text preview.', 'image-watermark' );
			} else {
				$image_data = file_get_contents( $sample_image_path );
				if ( ! is_string( $image_data ) || $image_data === '' ) {
					$error = __( 'Failed to render text preview.', 'image-watermark' );
				}
			}
		} catch ( Throwable $exception ) {
			$error = __( 'Failed to render text preview.', 'image-watermark' );
		} finally {
			if ( $sample_image_path && is_file( $sample_image_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Deliberate temporary preview-file cleanup; WP_Filesystem lacks the required local-file semantics.
				@unlink( $sample_image_path );
			}
		}

		if ( $error ) {
			wp_send_json_error( $error );
		}
		$base64 = base64_encode( $image_data );

		wp_send_json_success( [
			'image' => 'data:image/jpeg;base64,' . $base64,
		] );
	}

	/**
	 * Create a sample image for preview using the active engine.
	 *
	 * @param string $engine Active engine key ('imagick' or 'gd').
	 * @return string|false Path to sample image or false on failure.
	 */
	private function create_sample_image( $engine ) {
		$upload_dir = wp_upload_dir();
		$base_dir = trailingslashit( $upload_dir['basedir'] );
		$sample_path = $base_dir . 'iw-sample-preview-' . uniqid( '', true ) . '.jpg';

		if ( $engine === 'imagick' ) {
			return $this->create_sample_image_imagick( $sample_path );
		}

		return $this->create_sample_image_gd( $sample_path );
	}

	/**
	 * Create sample image via GD.
	 *
	 * @param string $sample_path
	 * @return string|false
	 */
	private function create_sample_image_gd( $sample_path ) {
		$required_functions = [ 'imagecreatetruecolor', 'imagecolorallocate', 'imagefill', 'imagerectangle', 'imagestring', 'imagejpeg', 'imagedestroy' ];
		foreach ( $required_functions as $function_name ) {
			if ( ! $this->gd_preview_function_available( $function_name ) ) {
				return false;
			}
		}

		$image = imagecreatetruecolor( 400, 300 );
		if ( ! $this->is_gd_image_handle( $image ) ) {
			return false;
		}

		$white = imagecolorallocate( $image, 255, 255, 255 );
		$gray = imagecolorallocate( $image, 200, 200, 200 );
		if ( $white === false || $gray === false || ! imagefill( $image, 0, 0, $white ) || ! imagerectangle( $image, 50, 50, 350, 250, $gray ) || ! imagestring( $image, 5, 150, 120, 'Sample Image', $gray ) ) {
			imagedestroy( $image );
			return false;
		}

		$written = imagejpeg( $image, $sample_path, 90 );
		imagedestroy( $image );

		return ( $written && is_file( $sample_path ) ? $sample_path : false );
	}

	/**
	 * Check a GD preview capability through the rendering capability seam.
	 *
	 * @param string $function_name GD function name.
	 * @return bool
	 */
	private function gd_preview_function_available( $function_name ) {
		return (bool) apply_filters( 'iw_watermark_gd_function_available', function_exists( $function_name ), $function_name );
	}

	/**
	 * Accept PHP 7 GD resources and PHP 8 GdImage objects, but not arbitrary resources.
	 *
	 * @param mixed $image GD image handle.
	 * @return bool
	 */
	private function is_gd_image_handle( $image ) {
		return ( is_resource( $image ) && get_resource_type( $image ) === 'gd' ) || ( class_exists( 'GdImage', false ) && $image instanceof \GdImage );
	}

	/**
	 * Create sample image via Imagick.
	 *
	 * @param string $sample_path
	 * @return string|false
	 */
	private function create_sample_image_imagick( $sample_path ) {
		if ( ! class_exists( 'Imagick', false ) || ! class_exists( 'ImagickPixel', false ) || ! class_exists( 'ImagickDraw', false ) ) {
			return false;
		}

		try {
			$image = new Imagick();
			$image->newImage( 400, 300, new ImagickPixel( 'white' ) );
			$image->setImageFormat( 'jpeg' );
			$image->setImageCompressionQuality( 90 );

			$draw = new ImagickDraw();
			$draw->setStrokeColor( new ImagickPixel( '#c8c8c8' ) );
			$draw->setFillColor( 'none' );
			$draw->setStrokeWidth( 1 );
			$draw->rectangle( 50, 50, 350, 250 );

			$draw_text = new ImagickDraw();
			$draw_text->setFillColor( new ImagickPixel( '#c8c8c8' ) );
			$draw_text->setFontSize( 14 );

			$image->annotateImage( $draw_text, 150, 170, 0, 'Sample Image' );
			$image->drawImage( $draw );
			$image->writeImage( $sample_path );

			$draw->clear();
			$draw->destroy();
			$draw_text->clear();
			$draw_text->destroy();
			$image->clear();
			$image->destroy();
		} catch ( Exception $e ) {
			return false;
		}

		return ( is_file( $sample_path ) ? $sample_path : false );
	}

	/**
	 * Handle media library bulk watermark actions.
	 *
	 * @return void
	 */
	public function watermark_bulk_action() {
		$this->get_watermark_controller()->watermark_bulk_action();
	}

	/**
	 * Add watermark buttons on attachment image locations.
	 *
	 * @param array $form_fields
	 * @param object $post
	 * @return array
	 */
	public function attachment_fields_to_edit( $form_fields, $post ) {
		if ( $this->options['watermark_image']['manual_watermarking'] == 1 && current_user_can( 'upload_files' ) && $post->post_type === 'attachment' && current_user_can( 'edit_post', $post->ID ) ) {
			$data = wp_get_attachment_metadata( $post->ID, false );

			// is this really an image?
			if ( in_array( get_post_mime_type( $post->ID ), $this->allowed_mime_types ) && is_array( $data ) ) {
				$backup_path = $this->get_upload_handler()->get_image_backup_filepath( get_post_meta( $post->ID, '_wp_attached_file', true ) );
				$remove_action = $this->get_upload_handler()->is_valid_backup_file( $backup_path )
					? ' | <a href="#" class="iw-watermark-action delete-watermark" data-action="removewatermark" data-id="' . $post->ID . '">' . __( 'Remove watermark', 'image-watermark' ) . '</a>'
					: '';
				$form_fields['image_watermark'] = [
					'show_in_edit'	=> false,
					'tr'			=> '
					<div id="image_watermark_buttons"' . ( get_post_meta( $post->ID, $this->is_watermarked_metakey, true ) ? ' class="watermarked"' : '' ) . ' data-id="' . $post->ID . '" style="display: none;">
						<label class="setting">
							<span class="name">' . __( 'Image Watermark', 'image-watermark' ) . '</span>
							<span class="value" style="width: 63%"><a href="#" class="iw-watermark-action" data-action="applywatermark" data-id="' . $post->ID . '">' . __( 'Apply watermark', 'image-watermark' ) . '</a>' . $remove_action . '</span>
						</label>
						<div class="clear"></div>
					</div>'
				];
			}
		}

		return $form_fields;
	}

	/**
	 * Format one bounded structured bulk outcome without exposing paths.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $outcome Operation outcome.
	 * @return string
	 */
	private function format_bulk_outcome( $attachment_id, $outcome ) {
		$operation = isset( $outcome['operation'] ) && $outcome['operation'] === 'remove' ? __( 'remove', 'image-watermark' ) : __( 'apply', 'image-watermark' );
		$state     = isset( $outcome['outcome'] ) ? sanitize_key( $outcome['outcome'] ) : 'failed';
		$sizes     = isset( $outcome['sizes'] ) && is_array( $outcome['sizes'] ) ? $outcome['sizes'] : [];
		$processed = isset( $sizes['processed'] ) && is_array( $sizes['processed'] ) ? $sizes['processed'] : [];
		$failed    = isset( $sizes['failed'] ) && is_array( $sizes['failed'] ) ? $sizes['failed'] : [];
		$skipped   = isset( $sizes['skipped'] ) && is_array( $sizes['skipped'] ) ? $sizes['skipped'] : [];
		$names     = [];
		foreach ( array_merge( $failed, $skipped ) as $size ) {
			if ( is_array( $size ) && ! empty( $size['name'] ) ) {
				$names[] = sanitize_key( $size['name'] );
			}
		}
		return sprintf(
			/* translators: 1: Attachment ID. 2: Operation state. 3: Operation. 4: Processed count. 5: Failed count. 6: Skipped count. 7: Size names. */
			__( 'ID %1$d: %2$s %3$s. Processed: %4$d; failed: %5$d; skipped: %6$d%7$s', 'image-watermark' ),
			(int) $attachment_id,
			ucfirst( $state ),
			$operation,
			count( $processed ),
			count( $failed ),
			count( $skipped ),
			empty( $names ) ? '' : ' (' . implode( ', ', array_unique( $names ) ) . ')'
		);
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function bulk_admin_notices() {
		global $post_type, $pagenow;

		if ( $pagenow === 'upload.php' ) {
			if ( ! current_user_can( 'upload_files' ) )
				return;

			// Display validation error from bulk action
			if ( isset( $_REQUEST['iw_error'] ) && $post_type === 'attachment' ) {
				$error_message = sanitize_text_field( wp_unslash( $_REQUEST['iw_error'] ) );
				echo '<div class="error"><p>' . esc_html( $error_message ) . '</p></div>';
				$_SERVER['REQUEST_URI'] = esc_url( remove_query_arg( 'iw_error', $_SERVER['REQUEST_URI'] ) );
			}

			if ( isset( $_REQUEST['watermarked'], $_REQUEST['watermarkremoved'], $_REQUEST['skipped'] ) && $post_type === 'attachment' ) {
				$watermarked = (int) $_REQUEST['watermarked'];
				$watermarkremoved = (int) $_REQUEST['watermarkremoved'];
				$skipped = (int) $_REQUEST['skipped'];
				$partial_apply = isset( $_REQUEST['iw_partial_apply'] ) ? (int) $_REQUEST['iw_partial_apply'] : 0;
				$partial_remove = isset( $_REQUEST['iw_partial_remove'] ) ? (int) $_REQUEST['iw_partial_remove'] : 0;
				$messages = [];
				$raw_remaining = isset( $_REQUEST['iw_bulk_remaining'] ) ? wp_unslash( $_REQUEST['iw_bulk_remaining'] ) : '';
				// This scalar is a bounded continuation count, never a result payload.
				$remaining = is_scalar( $raw_remaining ) && preg_match( '/^\d+$/', (string) $raw_remaining ) ? min( 20, absint( $raw_remaining ) ) : 0;
				$handoff = isset( $_REQUEST['iw_bulk_result'] )
					? $this->get_watermark_controller()->get_bulk_result_handoff( sanitize_text_field( wp_unslash( $_REQUEST['iw_bulk_result'] ) ) )
					: [];

				if ( ! empty( $handoff ) ) {
					$messages = isset( $handoff['messages'] ) ? (array) $handoff['messages'] : [];
					$outcomes = isset( $handoff['outcomes'] ) ? (array) $handoff['outcomes'] : [];
					$remaining = isset( $handoff['remaining'] ) ? (int) $handoff['remaining'] : 0;
					foreach ( array_slice( $outcomes, 0, 20, true ) as $attachment_id => $outcome ) {
						if ( ! is_array( $outcome ) || empty( $outcome['code'] ) || ( isset( $outcome['outcome'] ) && $outcome['outcome'] === 'complete' ) )
							continue;
						$messages[] = $this->format_bulk_outcome( $attachment_id, $outcome );
					}
				} elseif ( isset( $_REQUEST['messages'] ) ) {
						$raw_messages = wp_unslash( $_REQUEST['messages'] );
						$raw_messages = is_array( $raw_messages ) ? $raw_messages : [ $raw_messages ];
						$messages = array_filter( array_map( 'sanitize_text_field', $raw_messages ) );
				}

				if ( empty( $handoff ) && isset( $_REQUEST['iw_outcomes'] ) && is_string( $_REQUEST['iw_outcomes'] ) ) {
						$outcomes = json_decode( rawurldecode( wp_unslash( $_REQUEST['iw_outcomes'] ) ), true );
						if ( is_array( $outcomes ) ) {
							foreach ( array_slice( $outcomes, 0, 20, true ) as $attachment_id => $outcome ) {
								if ( ! is_array( $outcome ) || empty( $outcome['code'] ) || ( isset( $outcome['outcome'] ) && $outcome['outcome'] === 'complete' ) )
									continue;
								$messages[] = $this->format_bulk_outcome( $attachment_id, $outcome );
							}
						}
					}
					$messages = array_slice( array_unique( $messages ), 0, 20 );

				$skipped_summary = $skipped > 0 ? sprintf( ' %s: %d.', esc_html__( 'Skipped images', 'image-watermark' ), $skipped ) : '';
				if ( $watermarked === 0 && $partial_apply === 0 )
					echo '<div class="error"><p>' . esc_html__( 'The watermark could not be applied to the selected files because no valid images (JPEG, PNG, WebP) were selected.', 'image-watermark' ) . esc_html( $skipped_summary ) . '</p></div>';
				elseif ( $watermarked > 0 ) {
					/* translators: Number of images successfully watermarked. */
					$applied_message = sprintf( _n( '%s image was successfully watermarked.', '%s images were successfully watermarked.', $watermarked, 'image-watermark' ), number_format_i18n( $watermarked ) );
					echo '<div class="updated"><p>' . esc_html( $applied_message . $skipped_summary ) . '</p></div>';
				}

				if ( $partial_apply > 0 ) {
					/* translators: Number of images partially watermarked. */
					$partial_message = sprintf( _n( 'Watermark was partially applied to %s image.', 'Watermark was partially applied to %s images.', $partial_apply, 'image-watermark' ), number_format_i18n( $partial_apply ) );
					echo '<div class="notice notice-warning"><p>' . esc_html( $partial_message ) . '</p></div>';
				}

				if ( $watermarkremoved === 0 && $partial_remove === 0 )
					echo '<div class="error"><p>' . esc_html__( 'The watermark could not be removed from the selected files because no valid images (JPEG, PNG, WebP) were selected.', 'image-watermark' ) . esc_html( $skipped_summary ) . '</p></div>';
				elseif ( $watermarkremoved > 0 ) {
					/* translators: Number of images successfully restored from backups. */
					$removed_message = sprintf( _n( '%s image was successfully restored.', '%s images were successfully restored.', $watermarkremoved, 'image-watermark' ), number_format_i18n( $watermarkremoved ) );
					echo '<div class="updated"><p>' . esc_html( $removed_message . $skipped_summary ) . '</p></div>';
				}

				if ( $partial_remove > 0 ) {
					/* translators: Number of images partially restored from backups. */
					$partial_message = sprintf( _n( 'Watermark was partially removed from %s image.', 'Watermark was partially removed from %s images.', $partial_remove, 'image-watermark' ), number_format_i18n( $partial_remove ) );
					echo '<div class="notice notice-warning"><p>' . esc_html( $partial_message ) . '</p></div>';
				}

				if ( $remaining > 0 ) {
					/* translators: Number of selected images not submitted in the bounded request. */
					$remaining_message = sprintf( _n( '%s selected image was not submitted. Reselect the remaining images and run the action again.', '%s selected images were not submitted. Reselect the remaining images and run the action again.', $remaining, 'image-watermark' ), number_format_i18n( $remaining ) );
					echo '<div class="notice notice-warning"><p>' . esc_html( $remaining_message ) . '</p></div>';
				}

				if ( ! empty( $messages ) ) {
					echo '<div class="error"><p>' . implode( '<br />', array_map( 'esc_html', $messages ) ) . '</p></div>';
				}

					$_SERVER['REQUEST_URI'] = esc_url( remove_query_arg( [ 'watermarked', 'watermarkremoved', 'skipped', 'iw_partial_apply', 'iw_partial_remove', 'messages', 'iw_outcomes', 'iw_bulk_result', 'iw_bulk_remaining' ], $_SERVER['REQUEST_URI'] ) );
			}
		}
	}

	/**
	 * Check whether ImageMagick extension is available.
	 *
	 * @return bool
	 */
	public function check_imagick() {
		// check Imagick's extension and classes
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick', false ) || ! class_exists( 'ImagickPixel', false ) || ! class_exists( 'ImagickDraw', false ) )
			return false;

		// check version
		if ( version_compare( phpversion( 'imagick' ), '2.2.0', '<' ) )
			return false;

		// check for deep requirements within Imagick
		if ( ! defined( 'Imagick::COMPRESSION_JPEG' ) || ! defined( 'Imagick::COMPOSITE_DEFAULT' ) || ! defined( 'Imagick::INTERLACE_PLANE' ) || ! defined( 'Imagick::FILTER_CATROM' ) || ! defined( 'Imagick::CHANNEL_ALL' ) || ! defined( 'Imagick::CHANNEL_ALPHA' ) || ! defined( 'Imagick::EVALUATE_MULTIPLY' ) )
			return false;

		// check methods
		if ( ! $this->has_required_methods(
			'Imagick',
			[
				'clear',
				'destroy',
				'newImage',
				'writeImage',
				'getImageGeometry',
				'setImageFormat',
				'setImageCompression',
				'setImageCompressionQuality',
				'setImageInterlaceScheme',
				'getImageAlphaChannel',
				'evaluateImage',
				'queryFontMetrics',
				'resizeImage',
				'compositeImage',
				'annotateImage',
				'drawImage',
			]
		) )
			return false;

		// require at least one usable whole-image alpha method (setImageAlpha replaces the deprecated setImageOpacity)
		if ( ! method_exists( 'Imagick', 'setImageAlpha' ) && ! method_exists( 'Imagick', 'setImageOpacity' ) )
			return false;

		if ( ! $this->has_required_methods(
			'ImagickDraw',
			[
				'clear',
				'destroy',
				'setFont',
				'setFontSize',
				'setFillColor',
				'setFillOpacity',
				'setStrokeColor',
				'setStrokeWidth',
				'rectangle',
			]
		) )
			return false;

		return true;
	}

	/**
	 * Check whether a class provides all required methods.
	 *
	 * @param string $class_name Class name.
	 * @param array  $methods Required method names.
	 * @return bool
	 */
	private function has_required_methods( $class_name, $methods ) {
		foreach ( $methods as $method ) {
			if ( ! method_exists( $class_name, $method ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether GD extension is available.
	 *
	 * @return bool
	 */
	public function check_gd( $args = [] ) {
		// check extension
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) )
			return false;

		// ensure required formats are supported to avoid fatal errors
		$required_functions = [ 'imagecreatefromjpeg', 'imagecreatefrompng', 'imagecreatefromwebp' ];

		foreach ( $required_functions as $func ) {
			if ( ! function_exists( $func ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get active image extension.
	 *
	 * @return string|false
	 */
	public function get_extension() {
		return $this->extension;
	}

	/**
	 * Get allowed mime types.
	 *
	 * @return array
	 */
	public function get_allowed_mime_types() {
		return $this->allowed_mime_types;
	}

	/**
	 * Get allowed fonts list.
	 *
	 * @return array
	 */
	public function get_allowed_fonts() {
		return apply_filters( 'iw_allowed_fonts', $this->allowed_fonts );
	}

	/**
	 * Get meta key for watermark flag.
	 *
	 * @return string
	 */
	public function get_watermarked_meta_key() {
		return $this->is_watermarked_metakey;
	}

	/**
	 * Get upload handler.
	 *
	 * @return Image_Watermark_Upload_Handler
	 */
	public function get_upload_handler() {
		return $this->upload_handler;
	}

	/**
	 * Get watermark controller.
	 *
	 * @return Image_Watermark_Actions_Controller
	 */
	public function get_watermark_controller() {
		return $this->watermark_controller;
	}

	/**
	 * Get diagnostics service.
	 *
	 * @return Image_Watermark_Diagnostics
	 */
	public function get_diagnostics() {
		return $this->diagnostics;
	}

	/**
	 * Apply watermark to selected image sizes.
	 *
	 * @param array	$data
	 * @param int|string $attachment_id	Attachment ID
	 * @param string $method
	 * @return array
	 */
	public function apply_watermark( $data, $attachment_id, $method = '' ) {
		return $this->get_upload_handler()->apply_watermark( $data, $attachment_id, $method );
	}

	/**
	 * Create admin notice when we can't create the backup folder.
	 *
	 * @return void
	 */
	function folder_writable_admin_notice() {
		if ( current_user_can( 'manage_options' ) && $this->is_backup_folder_writable !== true ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Image Watermark', 'image-watermark' ); ?> - <?php esc_html_e( 'Image backup', 'image-watermark' ); ?>: <?php esc_html_e( "Your uploads folder is not writable, so we can't create backups of your images. This feature has been disabled for now.", 'image-watermark' ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Add link to Settings page.
	 *
	 * @param array $links
	 * @return array
	 */
	public function plugin_settings_link( $links ) {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) )
			return $links;

		array_unshift( $links, sprintf( '<a href="%s">%s</a>', admin_url( 'options-general.php' ) . '?page=image-watermark', __( 'Settings', 'image-watermark' ) ) );

		return $links;
	}

	/**
	 * Add link to Support Forum.
	 *
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function plugin_extend_links( $links, $file ) {
		if ( ! current_user_can( 'install_plugins' ) )
			return $links;

		if ( $file === IMAGE_WATERMARK_BASENAME )
			return array_merge( $links, [ sprintf( '<a href="https://www.dfactory.co/support/forum/image-watermark/" target="_blank">%s</a>', __( 'Support', 'image-watermark' ) ) ] );

		return $links;
	}

	/**
	 * Get font file path.
	 *
	 * @param string $font Font filename.
	 * @return string|null
	 */
	public function get_font_path( $font ) {
		if ( file_exists( $font ) && is_file( $font ) ) {
			$path = $font;
		} else {
			$path = IMAGE_WATERMARK_PATH . 'fonts/' . $font;
			if ( ! file_exists( $path ) || ! is_file( $path ) ) {
				$path = null;
			}
		}
		return apply_filters( 'iw_font_path', $path, $font );
	}

	/**
	 * Attachment editor sidebar actions.
	 *
	 * @return void
	 */
	public function render_attachment_editor_actions() {
		global $post;

		if ( ! $post || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( $this->options['watermark_image']['manual_watermarking'] != 1 ) {
			return;
		}

		if ( $post->post_type !== 'attachment' ) {
			return;
		}

		$mime = get_post_mime_type( $post->ID );

		if ( ! in_array( $mime, $this->get_allowed_mime_types(), true ) ) {
			return;
		}

		$backup_path = $this->get_upload_handler()->get_image_backup_filepath( get_post_meta( $post->ID, '_wp_attached_file', true ) );
		$remove_allowed = $this->get_upload_handler()->is_valid_backup_file( $backup_path );
		?>
		<div class="misc-pub-section iw-classic-actions">
			<button type="button" class="button-link iw-classic-apply"><?php esc_html_e( 'Apply watermark', 'image-watermark' ); ?></button>
			<?php if ( $remove_allowed ) : ?>
				| <button type="button" class="button-link iw-classic-remove"><?php esc_html_e( 'Remove watermark', 'image-watermark' ); ?></button>
			<?php endif; ?>
			<div class="iw-classic-status" aria-live="polite"></div>
		</div>
		<?php
	}
}

/**
 * Get instance of main class.
 *
 * @return object Instance
 */
function Image_Watermark() {
	static $instance;

	// first call to instance() initializes the plugin
	if ( $instance === null || ! ( $instance instanceof Image_Watermark ) )
		$instance = Image_Watermark::instance();

	return $instance;
}

$image_watermark = Image_Watermark();
