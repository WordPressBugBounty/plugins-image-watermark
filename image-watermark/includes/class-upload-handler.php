<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Watermark_Upload_Handler {

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

	/**
	 * Request-local uploads that are allowed to enter the automatic metadata
	 * callback. Each item is consumed by its matching attachment only.
	 *
	 * @var array[]
	 */
	private $pending_auto_uploads = [];

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
	 * Request-local operation carrier. P00-10 consumes this primitive; it is not
	 * persisted and cannot suppress a later request's ordinary upload callback.
	 *
	 * @var array|null
	 */
	private $internal_operation_guard = null;

	/**
	 * The most recent private operation result for native consumers to adopt in
	 * P00-11 without changing current success payloads.
	 *
	 * @var array
	 */
	private $last_operation_outcome = [];

	/**
	 * Test-only P00-09 fault seam. Production leaves it null, so it adds no
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
	 * Upload handler constructor.
	 *
	 * @param Image_Watermark $plugin
	 */
	public function __construct( Image_Watermark $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_notices', [ $this, 'render_persisted_auto_notices' ] );
		add_filter( 'pre_delete_attachment', [ $this, 'pre_delete_attachment' ], 10, 3 );
		add_action( 'deleted_post', [ $this, 'after_delete_attachment' ], 10, 2 );
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
	 * P00-11 coordination seam: return the last structured operation result.
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
			'retryable'    => ! empty( $last['retryable'] ),
			'context'      => isset( $last['context'] ) ? sanitize_key( $last['context'] ) : '',
			'time'         => isset( $last['time'] ) ? (int) $last['time'] : 0,
			'sizes'        => $this->normalize_operation_sizes( isset( $last['sizes'] ) ? $last['sizes'] : [] ),
		];
	}

	/**
	 * Return the execution service's current eligibility decision without mutation.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $context Operation context.
	 * @return array
	 */
	public function describe_operation_eligibility( $attachment_id, $context = 'manual-apply' ) {
		$options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );
		return $this->validate_watermark_eligibility( $options, (int) $attachment_id, $context );
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
		$keep    = [];
		foreach ( $records as $record ) {
			if ( ! is_array( $record ) || empty( $record['expires_at'] ) || (int) $record['expires_at'] < $now ) {
				continue;
			}
			$attachment_id = isset( $record['attachment_id'] ) ? (int) $record['attachment_id'] : 0;
			if ( $attachment_id > 0 && current_user_can( 'edit_post', $attachment_id ) && ! empty( $record['message'] ) ) {
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
	 * @internal P00-01/P00-09 test seam.
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
	public function handle_upload_files( $file ) {
		if ( ! $this->plugin->get_extension() ) {
			$this->plugin->check_extensions();

			if ( ! $this->plugin->get_extension() ) {
				return $file;
			}
		}

		$is_admin = $this->is_admin_upload_request();

		$upload_context = isset( $_REQUEST['iw_watermark_upload'] ) && is_string( $_REQUEST['iw_watermark_upload'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['iw_watermark_upload'] ) ) : '';

		if ( $upload_context === '1' ) {
			return $file;
		}

		$options = $this->plugin->options;
		$allowed_mime = $this->plugin->get_allowed_mime_types();
		$watermark_type = isset( $options['watermark_image']['type'] ) ? $options['watermark_image']['type'] : 'image';

		if ( $is_admin === true ) {
			if ( $options['watermark_image']['plugin_off'] == 1 && in_array( $file['type'], $allowed_mime, true ) ) {
				$should_apply = false;

				if ( $watermark_type === 'image' ) {
					$should_apply = wp_attachment_is_image( $options['watermark_image']['url'] );
				} elseif ( $watermark_type === 'text' ) {
					$text_string = isset( $options['watermark_image']['text_string'] ) ? trim( $options['watermark_image']['text_string'] ) : '';
					if ( ! empty( $text_string ) ) {
						// Validate font availability
						$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
						$font_path = $this->plugin->get_font_path( $font );
						$should_apply = $font_path && file_exists( $font_path );
						if ( ! $should_apply ) {
							$this->maybe_add_missing_font_notice( $font );
						}
					}
				}

					if ( $should_apply ) {
						$this->register_pending_auto_upload( $file, $is_admin );
					}
			}
		} else {
			if ( $options['watermark_image']['frontend_active'] == 1 && in_array( $file['type'], $allowed_mime, true ) ) {
				$should_apply = false;

				if ( $watermark_type === 'image' ) {
					$should_apply = wp_attachment_is_image( $options['watermark_image']['url'] );
				} elseif ( $watermark_type === 'text' ) {
					$text_string = isset( $options['watermark_image']['text_string'] ) ? trim( $options['watermark_image']['text_string'] ) : '';
					if ( ! empty( $text_string ) ) {
						// Validate font availability
						$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
						$font_path = $this->plugin->get_font_path( $font );
						$should_apply = $font_path && file_exists( $font_path );
					}
				}

					if ( $should_apply ) {
						$this->register_pending_auto_upload( $file, $is_admin );
					}
			}
		}

		return $file;
	}

	/**
	 * Register a single upload file as the owner of one automatic metadata call.
	 *
	 * wp_handle_upload runs before WordPress creates the attachment. The absolute
	 * uploaded file is therefore the only stable request-local identity available
	 * until wp_generate_attachment_metadata supplies the attachment ID.
	 *
	 * @param array $file Upload result.
	 * @param bool  $is_admin Whether this upload uses admin rules.
	 * @return void
	 */
	private function register_pending_auto_upload( $file, $is_admin ) {
		if ( empty( $file['file'] ) || ! is_string( $file['file'] ) ) {
			return;
		}

		$this->pending_auto_uploads[] = [
			'file'     => wp_normalize_path( $file['file'] ),
			'is_admin' => (bool) $is_admin,
		];
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10, 2 );
	}

	/**
	 * Apply an automatic watermark only for the upload that registered this
	 * callback. The claim is removed before processing so a failure, nested call,
	 * or later regeneration cannot apply it again.
	 *
	 * @param array $data Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function apply_upload_watermark( $data, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $this->get_internal_operation_guard( $attachment_id ) ) {
			return $data;
		}

		$pending_index = $this->find_pending_auto_upload( $data, $attachment_id );
		if ( $pending_index === false ) {
			return $data;
		}

		$pending = $this->pending_auto_uploads[ $pending_index ];
		unset( $this->pending_auto_uploads[ $pending_index ] );
		$this->pending_auto_uploads = array_values( $this->pending_auto_uploads );
		$previous_is_admin = $this->is_admin;
		$this->is_admin = $pending['is_admin'];

		try {
			return $this->apply_watermark( $data, $attachment_id );
		} finally {
			$this->is_admin = $previous_is_admin;
			if ( empty( $this->pending_auto_uploads ) ) {
				remove_filter( 'wp_generate_attachment_metadata', [ $this, 'apply_upload_watermark' ], 10 );
			}
		}
	}

	/**
	 * Find the pending upload record belonging to an attachment metadata callback.
	 *
	 * @param array $data Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return int|false
	 */
	private function find_pending_auto_upload( $data, $attachment_id ) {
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

		if ( empty( $paths ) ) {
			return false;
		}
		foreach ( $this->pending_auto_uploads as $index => $pending ) {
			if ( ! isset( $pending['file'] ) || ! is_string( $pending['file'] ) ) {
				continue;
			}

			foreach ( $paths as $candidate ) {
				if ( $this->paths_match( $pending['file'], $candidate ) ) {
					return $index;
				}
			}
		}

		return false;
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
	 *
	 * @param string $ref Request referer.
	 * @return bool
	 */
	private function is_rest_admin_media_upload( $ref ) {
		if ( ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$rest_media_route = '/' . rest_get_url_prefix() . '/wp/v2/media';

		if ( strpos( $request_uri, $rest_media_route ) === false ) {
			return false;
		}

		return ( $ref !== '' && strpos( $ref, admin_url() ) !== false );
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

			$backup_filepath = $this->get_image_backup_filepath( $data['file'] );
			if ( $backup_filepath === false ) {
				$result['valid'] = false;
				$result['code']  = 'invalid_backup_path';
				$result['error'] = __( 'The attachment backup path is invalid.', 'image-watermark' );
				return $result;
			}
			if ( ! $this->is_valid_backup_file( $backup_filepath ) ) {
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
	 * of existing successful AJAX payloads until P00-11 coordinates consumers.
	 *
	 * @param string $outcome Terminal outcome.
	 * @param string $token Operation token.
	 * @param string $code Stable code.
	 * @param string $message User-safe message.
	 * @param bool   $retryable Whether a retry may be useful.
	 * @param array  $sizes Logical size accounting.
	 * @return array
	 */
	private function operation_result( $outcome, $token, $code, $message, $retryable = false, $sizes = [] ) {
		$result = [
			'outcome'      => $outcome,
			'operation_id' => $token,
			'code'         => sanitize_key( $code ),
			'message'      => $this->sanitize_operation_message( $message ),
			'retryable'    => (bool) $retryable,
			'sizes'        => $this->normalize_operation_sizes( $sizes ),
		];
		$this->last_operation_outcome = $result;

		return $result;
	}

	/** @return array */
	private function normalize_operation_sizes( $sizes ) {
		$normalized = [ 'processed' => [], 'skipped' => [], 'failed' => [] ];
		foreach ( $normalized as $state => $empty ) {
			foreach ( isset( $sizes[ $state ] ) && is_array( $sizes[ $state ] ) ? $sizes[ $state ] : [] as $size ) {
				if ( is_array( $size ) ) {
					$name = isset( $size['name'] ) ? sanitize_key( $size['name'] ) : '';
					$code = isset( $size['code'] ) ? sanitize_key( $size['code'] ) : '';
					if ( $name !== '' ) {
						$normalized[ $state ][] = $state === 'processed' ? $name : [ 'name' => $name, 'code' => $code ];
					}
				} elseif ( is_string( $size ) && sanitize_key( $size ) !== '' ) {
					$normalized[ $state ][] = $state === 'processed' ? sanitize_key( $size ) : [ 'name' => sanitize_key( $size ), 'code' => '' ];
				}
			}
		}
		return $normalized;
	}

	/** @return string */
	private function sanitize_operation_message( $message ) {
		$message = sanitize_text_field( $message );
		$upload  = wp_upload_dir();
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

		if ( strlen( maybe_serialize( $journal ) ) > 131072 || ! is_array( $journal ) || ! isset( $journal['version'] ) || (int) $journal['version'] !== 1 || ! array_key_exists( 'current', $journal ) || ! isset( $journal['history'] ) || ! is_array( $journal['history'] ) || count( $journal['history'] ) > 2 || ! $this->is_valid_operation_record( $journal['current'] ) || ( ! empty( $journal['recoverable_predecessor'] ) && ! $this->is_valid_operation_record( $journal['recoverable_predecessor'] ) ) ) {
			return false;
		}
		foreach ( $journal['history'] as $record ) {
			if ( ! $this->is_valid_operation_record( $record ) ) {
				return false;
			}
		}

		return $journal;
	}

	/** @return bool */
	private function is_valid_operation_record( $record ) {
		if ( $record === null ) {
			return true;
		}
		if ( ! is_array( $record ) || empty( $record['token'] ) || ! is_string( $record['token'] ) || empty( $record['state'] ) || ! in_array( $record['state'], [ 'preparing', 'backup-done', 'rendering', 'staging', 'promoting', 'regenerating', 'metadata-commit', 'complete', 'partial', 'interrupted', 'skipped', 'failed' ], true ) || ! isset( $record['targets'] ) || ! is_array( $record['targets'] ) || count( $record['targets'] ) > 50 ) {
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
		}
		return true;
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
		if ( count( (array) $journal['history'] ) > 2 || strlen( maybe_serialize( $journal ) ) > 131072 ) {
			return false;
		}
		if ( $this->should_inject_operation_fault( 'journal_write', [ 'attachment_id' => $attachment_id, 'state' => isset( $journal['current']['state'] ) ? $journal['current']['state'] : '' ] ) ) {
			return false;
		}

		update_post_meta( $attachment_id, '_iw_operation_journal', $journal );
		$reread = get_post_meta( $attachment_id, '_iw_operation_journal', true );

		return is_array( $reread ) && maybe_serialize( $reread ) === maybe_serialize( $journal );
	}

	/**
	 * Atomically acquire a fenced attachment lease. A busy lease is never waited
	 * on; a stale lease is reclaimed only after a compare-and-delete fence.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token Operation token.
	 * @return array|false Lock record, or false when busy.
	 */
	private function acquire_operation_lock( $attachment_id, $token ) {
		$name = $this->operation_lock_name( $attachment_id );
		$now = time();
		$lock = [ 'token' => $token, 'version' => 1, 'acquired_at' => $now, 'heartbeat_at' => $now ];

		if ( add_option( $name, $lock, '', 'no' ) ) {
			return $lock;
		}

		$observed = get_option( $name, false );
		if ( ! is_array( $observed ) || empty( $observed['token'] ) || empty( $observed['heartbeat_at'] ) || (int) $observed['heartbeat_at'] > $now - 900 ) {
			return false;
		}

		$journal = $this->read_operation_journal( $attachment_id );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-delete is the P00-08 lock ownership fence; core APIs cannot provide this atomic operation.
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$name,
			maybe_serialize( $observed )
		) );

		if ( $deleted !== 1 ) {
			return false;
		}

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return add_option( $name, $lock, '', 'no' ) ? $lock : false;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-swap is the P00-08 lock ownership fence; core APIs cannot provide this atomic operation.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			maybe_serialize( $new_lock ),
			$name,
			maybe_serialize( $old_lock )
		) );
		if ( $updated !== 1 ) {
			return false;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$operation['lock'] = $new_lock;
		if ( ! empty( $new_lock['deleting'] ) ) {
			$this->attachment_deletion_fences[ (int) $operation['attachment_id'] ] = $new_lock;
		}
		return true;
	}

	/** @param array $operation Current private operation. @return void */
	private function release_operation_lock( $operation ) {
		if ( $this->should_inject_operation_fault( 'lock_release', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			return;
		}
		global $wpdb;
		$name = $this->operation_lock_name( $operation['attachment_id'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value compare-and-delete is the P00-08 lock release fence; core APIs cannot provide this atomic operation.
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$name,
			maybe_serialize( $operation['lock'] )
		) );
		if ( $deleted === 1 ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/** @return array|false */
	private function acquire_attachment_deletion_fence( $attachment_id ) {
		$name = $this->operation_lock_name( $attachment_id );
		$observed = get_option( $name, false );
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
			if ( ! add_option( $name, $fence, '', 'no' ) ) {
				return false;
			}
		} else {
			if ( ! is_array( $observed ) ) {
				return false;
			}
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact-value replacement revokes only the observed attachment lease before deletion.
			$replaced = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				maybe_serialize( $fence ),
				$name,
				maybe_serialize( $observed )
			) );
			if ( $replaced !== 1 ) {
				return false;
			}
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
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
		$existing_fence = get_option( $this->operation_lock_name( $attachment_id ), false );
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
	private function persist_terminal_outcome( $attachment_id, $type, $context, $state, $code, $message, $last_status = 'skipped' ) {
		$token = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true );
		}
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ];
		try {
			if ( ! $this->begin_operation( $operation, $type, $context, [] ) || ! $this->set_operation_state( $operation, $state, [ 'code' => $code, 'counts' => [ 'processed' => 0, 'failed' => 0 ] ] ) ) {
				return $this->operation_result( 'interrupted', $token, 'journal_write_failed', $message, true );
			}
			$sizes = [ 'processed' => [], 'skipped' => $state === 'skipped' ? [ [ 'name' => 'all', 'code' => $code ] ] : [], 'failed' => $state === 'failed' ? [ [ 'name' => 'all', 'code' => $code ] ] : [] ];
			$this->record_last_operation( $attachment_id, $last_status, $code, $message, $context, [], $sizes['failed'], $sizes['skipped'], $state );
			$result = $this->operation_result( $state, $token, $code, $message, false, $sizes );
			if ( $context === 'auto-apply' && in_array( $state, [ 'failed', 'partial', 'interrupted' ], true ) ) {
				$this->queue_auto_operation_notice( $attachment_id, $result );
			}
			return $result;
		} finally {
			$this->release_operation_lock( $operation );
		}
	}

	/** @return array */
	private function early_operation_failure( $data, $attachment_id, $type, $context, $method, $code, $message, $last_status = 'error' ) {
		$result = $this->persist_terminal_outcome( $attachment_id, $type, $context, 'failed', $code, $message, $last_status );
		return $method === 'manual' ? [ 'error' => $message, 'outcome' => $result ] : $data;
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
		$attachment_id = (int) $attachment_id;
		// P00-10 consumer seam: only the matching request-local internal
		// operation bypasses the automatic metadata callback. A guard for another
		// attachment remains invisible here and cannot leak into this operation.
		if ( $this->get_internal_operation_guard( $attachment_id ) ) {
			return $data;
		}
		$post = get_post( $attachment_id );
		$post_id = ( ! empty( $post ) ? (int) $post->post_parent : 0 );
		$context = $method === 'manual' ? 'manual-apply' : 'auto-apply';

		// Bail early if metadata is not an array or missing file info
		if ( ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			$msg = __( 'Invalid attachment metadata.', 'image-watermark' );
			return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'invalid_metadata', $msg, $method === 'manual' ? 'error' : 'skipped' );
		}

		$options = $this->normalize_rotation_options( apply_filters( 'iw_watermark_options', $this->plugin->options ) );

		// Use shared validation
		$validation = $this->validate_watermark_eligibility( $options, $attachment_id, $context );

		if ( ! $validation['valid'] ) {
			$code = ! empty( $validation['code'] ) ? $validation['code'] : 'validation_failed';
			if ( $method === 'manual' ) {
				return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, $code, $validation['error'] );
			} else {
				// Auto operations show admin notice and skip
				if ( $validation['error'] ) {
					$this->add_admin_notice(
						sprintf(
							/* translators: 1: Watermark skip message. */
							__( 'Image Watermark: Upload successful, but watermark skipped - %s', 'image-watermark' ),
							$validation['error']
						),
						'warning'
					);
				}
				return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, $code, $validation['error'] ?: __( 'Eligibility check failed.', 'image-watermark' ) );
			}
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
				return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'attachment_unattached', __( 'Automatic watermarking was skipped because the image was not attached to a post during upload.', 'image-watermark' ), 'skipped' );
			}

			$parent_post_type = get_post_type( $post_id );

			if ( ! in_array( $parent_post_type, $selected_post_types, true ) ) {
					/* translators: 1: Parent post type. */
					return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'post_type_excluded', sprintf( __( 'Automatic watermarking was skipped because the parent post type "%s" is not selected.', 'image-watermark' ), (string) $parent_post_type ), 'skipped' );
			}
		}

		if ( apply_filters( 'iw_watermark_display', $attachment_id ) === false ) {
			$message = __( 'Watermarking skipped by filter.', 'image-watermark' );
			$result = $this->persist_terminal_outcome( $attachment_id, 'apply', $context, 'skipped', 'display_veto', $message );
			return $method === 'manual' ? [ 'error' => $message, 'outcome' => $result ] : $data;
		}

		$upload_dir = wp_upload_dir();
		$attached_file = get_attached_file( $attachment_id );
		$original_file = ( $attached_file && is_file( $attached_file ) )
			? $attached_file
			: $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $data['file'];

		// Ensure the target file exists and is a regular file before processing
		if ( ! is_file( $original_file ) ) {
			$msg = __( 'The original image file could not be found. It may have been moved, deleted, or offloaded to remote storage.', 'image-watermark' );
			return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'file_missing', $msg, $method === 'manual' ? 'error' : 'skipped' );
		}

		$image_size_result = getimagesize( $original_file, $original_image_info );
		if ( $image_size_result !== false ) {
			$original_width = $image_size_result[0];
			$original_height = $image_size_result[1];

			if ( $this->should_skip_small_image( $options, $original_width, $original_height ) ) {
				$msg = __( 'Image is smaller than the minimum dimensions required for watermarking.', 'image-watermark' );
				return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'small_image', $msg, $method === 'manual' ? 'error' : 'skipped' );
			}

			$target_files = $this->get_target_image_paths( $options, $data, $original_file, $upload_dir );

			if ( empty( $target_files ) ) {
				$msg = __( 'No selected image sizes are available for this attachment.', 'image-watermark' );
				$this->persist_terminal_outcome( $attachment_id, 'apply', $context, 'skipped', 'no_target_sizes', $msg, $method === 'manual' ? 'error' : 'skipped' );
				if ( $method === 'manual' ) {
					return [ 'error' => $msg ];
				}

				return $data;
			}

				$metadata = $this->get_image_metadata( $original_image_info, $original_file );

				if ( ! empty( $metadata['error'] ) ) {
					/* translators: 1: Image metadata read error. */
					$message = sprintf( __( 'Watermark skipped because image metadata could not be read: %s', 'image-watermark' ), $metadata['error'] );

					if ( $method === 'manual' ) {
						return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'metadata_read_failed', $message );
					}

					$this->add_admin_notice( $message, 'warning' );
					return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'metadata_read_failed', $message );
				}

			return $this->execute_staged_apply( $data, $attachment_id, $method, $context, $options, $upload_dir, $target_files, $metadata );
		} elseif ( $method === 'manual' ) {
			// getimagesize() failed: the file is missing, corrupt, or not a supported image.
			$read_error = __( 'The image file could not be read. It may be corrupt or in an unsupported format.', 'image-watermark' );
			return $this->early_operation_failure( $data, $attachment_id, 'apply', $context, $method, 'image_unreadable', $read_error );
		}

		return $data;
	}

	/**
	 * Execute a complete apply/reapply under one fenced owner. Live files are only
	 * touched by promote_stage_file(), after their stage and journal entry exist.
	 *
	 * @return array
	 */
	private function execute_staged_apply( $data, $attachment_id, $method, $context, $options, $upload_dir, $target_files, $metadata ) {
		$token = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true ) );
		}

		$operation = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ];
		try {
			$groups = $this->operation_target_groups( $target_files, $upload_dir );
			if ( $groups === false ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'invalid_targets', __( 'Selected image paths could not be safely prepared.', 'image-watermark' ) );
			}
			$recovery = $this->reconcile_predecessor( $operation, $data, $upload_dir, $context );
			if ( $recovery === 'complete' ) {
				return $data;
			}
			if ( $recovery === 'uncertain' ) {
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'failed', $token, 'predecessor_uncertain', __( 'The interrupted watermark operation cannot prove that every live target is clean. No image was overwritten.', 'image-watermark' ), true ) );
			}
			if ( ! $this->begin_operation( $operation, 'apply', $context, $groups ) ) {
				return $this->operation_failure( $data, $method, $attachment_id, $context, $this->operation_result( 'failed', $token, 'journal_invalid', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true ) );
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
			$previous_derivatives = [];
			if ( $reapply ) {
				$previous_derivatives = array_merge( $this->recoverable_derivative_paths( $operation, $upload_dir ), $this->snapshot_registered_derivatives( $data, $original_file, $upload_dir ) );
				if ( ! $this->watermark_source_is_available( $options, $upload_dir ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'watermark_source_missing', __( 'Watermark not applied: the watermark source image file is missing from the server. Please re-select a watermark image in settings.', 'image-watermark' ) );
				}
				$backup = $this->get_image_backup_filepath( $data['file'] );
				if ( $backup === false ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'invalid_backup_path', __( 'The attachment backup path is invalid.', 'image-watermark' ), true );
				}
				if ( ! $this->is_valid_backup_file( $backup ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', 'backup_missing', __( 'Watermark not applied because the original backup is missing.', 'image-watermark' ) );
				}
				if ( ! $this->stage_copy_and_promote( $operation, $backup, $original_file, 'restore-source', [] ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'restore_failed', __( 'The clean backup could not be safely restored for reapplication.', 'image-watermark' ), true );
				}
				$data = $this->generate_metadata_under_operation( $operation, $attachment_id, $original_file, 'reapply', $previous_derivatives, isset( $data['original_image'] ) ? $data['original_image'] : '' );
				if ( ! is_array( $data ) || empty( $data['file'] ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_regeneration_failed', __( 'Image metadata could not be regenerated after restoring the clean source.', 'image-watermark' ), true, [], array_merge( [ 'full' ], array_keys( $previous_derivatives ) ) );
				}
				$target_files = $this->get_target_image_paths( $options, $data, $original_file, $upload_dir );
				$groups = $this->operation_target_groups( $target_files, $upload_dir );
				if ( $groups === false || empty( $groups ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'no_targets', __( 'No regenerated image sizes are available for watermarking.', 'image-watermark' ), true );
				}
				$reconciled_targets = [];
				foreach ( $groups as $group ) {
				$reconciled_targets[] = [ 'target' => $group['target'], 'root' => 'uploads', 'aliases' => $group['aliases'], 'intent' => 'watermark-output', 'state' => 'pending' ];
				}
			}

			if ( ! empty( $options['backup']['backup_image'] ) && ! $this->create_staged_backup( $operation, $data, $upload_dir, $options ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', ! empty( $operation['backup_error_code'] ) ? $operation['backup_error_code'] : 'backup_failed', ! empty( $operation['backup_error'] ) ? $operation['backup_error'] : __( 'Failed to copy original file to backup location.', 'image-watermark' ), true );
			}
			if ( ! $reapply && empty( $options['backup']['backup_image'] ) && ! $this->create_temporary_recovery_sources( $operation, $groups, $options ) ) {
				$code = ! empty( $operation['recovery_error_code'] ) ? $operation['recovery_error_code'] : 'recovery_source_invalid';
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'failed', $code, __( 'A verified temporary clean recovery source could not be created. Watermark not applied.', 'image-watermark' ), true );
			}
			if ( ! $this->set_operation_state( $operation, 'rendering', $reapply ? [ 'targets' => $reconciled_targets ] : [] ) ) {
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
				$decoder_probe = apply_filters( 'iw_watermark_image_decoder', null, $group['path'], wp_check_filetype( $group['path'] )['type'] );
				$stage_failed = $this->should_inject_operation_fault( 'during_staging', [ 'attachment_id' => $attachment_id, 'token' => $token, 'target' => $group['target'] ] ) || ( null !== $decoder_probe && ! $this->is_gd_image( $decoder_probe ) ) || ! $this->copy_file( $group['path'], $stage, 'stage', $attachment_id, $options ) || $this->should_inject_operation_fault( 'after_stage_copy', [ 'attachment_id' => $attachment_id, 'token' => $token, 'target' => $group['target'] ] );
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
				if ( ! $reapply && empty( $options['backup']['backup_image'] ) && ( ! empty( $processed ) || $promotion_interrupted ) ) {
					$this->commit_watermarked_flag( $operation, 1 );
				}
				return $this->operation_terminal_failure( $operation, $data, $method, $context, $state, $failure_code, $failure_message, true, $processed, $failed );
			}
			if ( ! $this->renew_operation_lock( $operation ) || ! $this->set_operation_state( $operation, 'metadata-commit' ) || $this->should_inject_operation_fault( 'before_metadata_commit', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_fence_lost', __( 'Watermarked files were prepared, but their metadata could not be safely committed.', 'image-watermark' ), true, $processed );
			}
			if ( $reapply && ! $this->set_regenerated_metadata_commit_state( $operation, $data, 'pending' ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermarked files were prepared, but regenerated metadata could not be safely prepared for commit.', 'image-watermark' ), true, $processed );
			}
			if ( $reapply && $this->should_inject_operation_fault( 'after_regenerated_metadata_pending', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_interrupted', __( 'Watermarked files were prepared, but regenerated metadata commit was interrupted.', 'image-watermark' ), true, $processed );
			}
			if ( $reapply ) {
				$derivatives = $this->reconcile_regenerated_derivatives( $operation, $previous_derivatives, $data, $original_file, $upload_dir, true );
				if ( ! empty( $derivatives['failed'] ) ) {
					return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'derivative_reconciliation_failed', __( 'Watermarked files were prepared, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, $processed, $derivatives['failed'] );
				}
			}
			if ( $reapply && ! $this->commit_regenerated_metadata( $attachment_id, $data ) ) {
				$this->set_regenerated_metadata_commit_state( $operation, $data, 'refused' );
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_failed', __( 'Watermarked files were prepared, but attachment metadata could not be committed.', 'image-watermark' ), true, $processed );
			}
			if ( $reapply && ! $this->set_regenerated_metadata_commit_state( $operation, $data, 'committed' ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermarked files were prepared, but regenerated metadata commit could not be recorded.', 'image-watermark' ), true, $processed );
			}
			if ( ! $this->commit_watermarked_flag( $operation, 1 ) ) {
				return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'metadata_commit_failed', __( 'Watermarked files were prepared, but their watermark state could not be committed.', 'image-watermark' ), true, $processed );
			}
			return $this->operation_complete( $operation, $data, $attachment_id, $method, $context, 'watermarked', __( 'Watermark applied.', 'image-watermark' ), $processed );
		} finally {
			$this->internal_operation_guard = null;
			$this->release_operation_lock( $operation );
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
		if ( $method !== 'manual' ) {
			return $data;
		}

		$attachment_id = (int) $attachment_id;

		if ( ! is_array( $data ) || empty( $data['file'] ) || ! is_string( $data['file'] ) ) {
			$err = __( 'Invalid attachment metadata.', 'image-watermark' );
			return $this->early_operation_failure( $data, $attachment_id, 'remove', 'manual-remove', $method, 'invalid_metadata', $err );
		}

		if ( ! is_array( $options ) ) {
			$options = apply_filters( 'iw_watermark_options', $this->plugin->options );
		}

		// Use shared validation for remove operations
		$validation = $this->validate_watermark_eligibility( $options, $attachment_id, 'manual-remove' );

		if ( ! $validation['valid'] ) {
			$code = ! empty( $validation['code'] ) ? $validation['code'] : 'validation_failed';
			return $this->early_operation_failure( $data, $attachment_id, 'remove', 'manual-remove', $method, $code, $validation['error'] );
		}

		$upload_dir = wp_upload_dir();

		// A damaged destination is the reason to restore. The clean backup is the
		// source that must decode successfully; do not reject a missing or corrupt
		// current file before the staged restore has a chance to replace it.
		$filepath = get_attached_file( $attachment_id );
		if ( ! is_string( $filepath ) || $filepath === '' ) {
			$filepath = $upload_dir['basedir'] . '/' . ltrim( wp_normalize_path( $data['file'] ), '/' );
		}
		$backup_filepath = $this->get_image_backup_filepath( get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		if ( $backup_filepath === false ) {
			$err = __( 'The attachment backup path is invalid.', 'image-watermark' );
			return $this->early_operation_failure( $data, $attachment_id, 'remove', 'manual-remove', $method, 'invalid_backup_path', $err );
		}

		// Backup must exist (already checked by validation, but double-check for safety)
		if ( ! $this->is_valid_backup_file( $backup_filepath ) ) {
			$err = __( 'No watermark backup found for this image.', 'image-watermark' );
			return $this->early_operation_failure( $data, $attachment_id, 'remove', 'manual-remove', $method, 'backup_missing', $err );
		}

		return $this->execute_staged_remove( $data, $attachment_id, $filepath, $backup_filepath, $options );
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
	 * This is the local P00-09 seam; attachment operation staging remains P00-09 work.
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

			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename preserves P00-09 atomic promotion semantics.
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
		$selected_predecessor = isset( $operation['selected_recovery_predecessor'] ) && is_array( $operation['selected_recovery_predecessor'] ) ? $operation['selected_recovery_predecessor'] : null;
		if ( ! is_array( $selected_predecessor ) ) {
			$current_candidate = ! empty( $journal['current'] ) ? $journal['current'] : null;
			$retained_candidate = ! empty( $journal['recoverable_predecessor'] ) ? $journal['recoverable_predecessor'] : null;
			$current_recovery_state = is_array( $current_candidate ) && isset( $current_candidate['state'] ) && in_array( $current_candidate['state'], [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging', 'rendering', 'backup-done', 'preparing' ], true );
			if ( $current_recovery_state && $this->operation_record_has_complete_temporary_recovery( $current_candidate ) ) {
				$selected_predecessor = $current_candidate;
			} elseif ( $this->operation_record_has_complete_temporary_recovery( $retained_candidate ) ) {
				$selected_predecessor = $retained_candidate;
			} elseif ( $current_recovery_state && $this->operation_record_has_temporary_recovery_evidence( $current_candidate ) ) {
				$selected_predecessor = $current_candidate;
			} elseif ( $this->operation_record_has_temporary_recovery_evidence( $retained_candidate ) ) {
				$selected_predecessor = $retained_candidate;
			}
		}
		if ( ! empty( $journal['current'] ) && ( ! $this->operation_is_terminal( $journal['current'] ) || in_array( $journal['current']['state'], [ 'partial', 'interrupted' ], true ) ) ) {
			$current = $journal['current'];
			$current_is_selected = is_array( $selected_predecessor ) && isset( $selected_predecessor['token'] ) && $selected_predecessor['token'] === $current['token'];
			if ( is_array( $selected_predecessor ) && ! $current_is_selected ) {
				$operation['journal'] = $journal;
				if ( ! $this->cleanup_operation_record_artifacts( $operation, $current, true ) ) {
					return false;
				}
				$current['state'] = 'interrupted';
				$current['ended_at'] = time();
				$current['cleanup_pending'] = false;
				$journal['current'] = $current;
				$journal['recoverable_predecessor'] = $selected_predecessor;
				if ( ! $this->renew_operation_lock( $operation ) || ! $this->write_operation_journal( $operation['attachment_id'], $journal ) ) {
					return false;
				}
				$journal['history'][] = $this->compact_operation_record( $current );
				$journal['history'] = array_slice( $journal['history'], -2 );
			} else {
				if ( $current_is_selected && ! empty( $journal['recoverable_predecessor'] ) && isset( $journal['recoverable_predecessor']['token'] ) && $journal['recoverable_predecessor']['token'] !== $current['token'] && ! $this->cleanup_operation_record_artifacts( $operation, $journal['recoverable_predecessor'], true ) ) {
					return false;
				}
				$current['state'] = 'interrupted';
				$current['ended_at'] = time();
				$journal['current'] = $current;
				$journal['recoverable_predecessor'] = $current;
			}
		} elseif ( ! empty( $journal['current'] ) ) {
			if ( ! empty( $journal['current']['cleanup_pending'] ) ) {
				$operation['journal'] = $journal;
				$cleanup_all = $journal['current']['state'] === 'complete' && ! empty( $journal['current']['metadata_committed'] );
				$cleaned = $cleanup_all ? $this->cleanup_owned_operation_stages( $operation, true ) : $this->cleanup_operation_record_artifacts( $operation, $journal['current'], true );
				if ( ! $cleaned || ! $this->set_operation_state( $operation, $journal['current']['state'], [ 'cleanup_pending' => false ] ) ) {
					return false;
				}
				$journal = $operation['journal'];
			}
			if ( $journal['current']['state'] === 'complete' && ! empty( $journal['current']['metadata_committed'] ) ) {
				$journal = $this->retire_recoverable_predecessor( $journal );
				$selected_predecessor = null;
				unset( $operation['selected_recovery_predecessor'] );
				foreach ( $journal['current']['targets'] as $index => $target ) {
					unset( $target['stage'], $target['recovery'] );
					$journal['current']['targets'][ $index ] = $target;
				}
				unset( $journal['current']['temporary_recovery_restore'] );
			}
			$journal['history'][] = $this->compact_operation_record( $journal['current'] );
			$journal['history'] = array_slice( $journal['history'], -2 );
		}
		if ( is_array( $selected_predecessor ) && ( empty( $journal['recoverable_predecessor'] ) || empty( $journal['recoverable_predecessor']['token'] ) || $journal['recoverable_predecessor']['token'] !== $selected_predecessor['token'] ) ) {
			$journal['recoverable_predecessor'] = $selected_predecessor;
		}
		$targets = [];
		foreach ( $groups as $group ) {
			$targets[] = [ 'target' => $group['target'], 'root' => 'uploads', 'aliases' => $group['aliases'], 'intent' => 'watermark-output', 'state' => 'pending' ];
		}
		$journal['current'] = [
			'token'       => $operation['token'],
			'type'        => $type,
			'context'     => $context,
			'state'       => 'preparing',
			'started_at'  => time(),
			'heartbeat_at' => time(),
			'site_id'     => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
			'intended_flag' => $type === 'apply' ? 1 : 0,
			'metadata_committed' => false,
			'targets'     => $targets,
		];
		$operation['journal'] = $journal;
		return $this->write_operation_journal( $operation['attachment_id'], $journal );
	}

	/** @param array $record Full record. @return array */
	private function compact_operation_record( $record ) {
		return [
			'token'      => isset( $record['token'] ) ? $record['token'] : '',
			'type'       => isset( $record['type'] ) ? $record['type'] : '',
			'state'      => isset( $record['state'] ) ? $record['state'] : 'failed',
			'started_at' => isset( $record['started_at'] ) ? $record['started_at'] : 0,
			'ended_at'   => isset( $record['ended_at'] ) ? $record['ended_at'] : time(),
			'code'       => isset( $record['code'] ) ? $record['code'] : '',
			'counts'     => isset( $record['counts'] ) ? $record['counts'] : [],
			'targets'    => isset( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [],
		];
	}

	/** @param array $operation Current operation. @return bool */
	private function set_operation_state( &$operation, $state, $details = [] ) {
		if ( ! $this->renew_operation_lock( $operation ) || empty( $operation['journal']['current'] ) ) {
			return false;
		}
		$operation['journal']['current'] = array_merge( $operation['journal']['current'], (array) $details );
		$operation['journal']['current']['state'] = $state;
		$operation['journal']['current']['heartbeat_at'] = time();
		if ( $this->operation_is_terminal( $operation['journal']['current'] ) ) {
			$operation['journal']['current']['ended_at'] = time();
		}
		return $this->write_operation_journal( $operation['attachment_id'], $operation['journal'] );
	}

	/** @param array $operation Current operation. @param array $target Target record. @return bool */
	private function set_operation_target( &$operation, $target ) {
		if ( empty( $operation['journal']['current']['targets'] ) ) {
			return false;
		}
		foreach ( $operation['journal']['current']['targets'] as $index => $current ) {
			if ( $current['target'] === $target['target'] ) {
				$operation['journal']['current']['targets'][ $index ] = array_merge( $current, $target );
				return $this->set_operation_state( $operation, 'staging' );
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

	/** @return array|false */
	private function resolve_upload_artifact_path( $relative, $create_directories = false, $context = '', $attachment_id = 0 ) {
		$upload_dir = wp_upload_dir();
		return $this->resolve_operation_root_path( $upload_dir['basedir'], $relative, $create_directories, $context, $attachment_id );
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
	private function resolve_operation_root_path( $root, $relative, $create_directories = false, $context = '', $attachment_id = 0 ) {
		$relative = $this->normalize_operation_relative_path( $relative );
		if ( $relative === false || ! is_string( $root ) || $root === '' || strpos( $root, "\0" ) !== false ) {
			return false;
		}

		$root = wp_normalize_path( $root );
		if ( ! is_dir( $root ) ) {
			if ( ! $create_directories ) {
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
					return false;
				}
			}
			$resolved = realpath( $candidate );
			if ( $resolved === false || ! $this->operation_path_is_within_root( $resolved, $canonical_root, true ) ) {
				return false;
			}
			$directory = $resolved;
		}

		$path = $directory . DIRECTORY_SEPARATOR . $filename;
		if ( is_link( $path ) ) {
			return false;
		}
		if ( file_exists( $path ) ) {
			$resolved = realpath( $path );
			if ( $resolved === false || ! $this->operation_path_is_within_root( $resolved, $canonical_root ) ) {
				return false;
			}
			$path = $resolved;
		}
		return [ 'path' => $path, 'relative' => $relative, 'root' => wp_normalize_path( $canonical_root ), 'parent' => wp_normalize_path( $directory ) ];
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

	/** @return bool */
	private function commit_regenerated_metadata( $attachment_id, &$metadata ) {
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
			return false;
		}
		$before = wp_get_attachment_metadata( $attachment_id, false );
		$updated = wp_update_attachment_metadata( $attachment_id, $metadata );
		$committed = wp_get_attachment_metadata( $attachment_id, false );
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
		if ( ! $this->renew_operation_lock( $operation ) || $this->should_inject_operation_fault( 'metadata_commit', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) ) {
			return false;
		}
		update_post_meta( $operation['attachment_id'], $this->plugin->get_watermarked_meta_key(), (int) $value );
		return (int) get_post_meta( $operation['attachment_id'], $this->plugin->get_watermarked_meta_key(), true ) === (int) $value;
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
		if ( $final_targets > 0 && $matched === $final_targets && $final_targets === count( $prior['targets'] ) && $metadata_commit_is_provable && isset( $prior['type'] ) && $prior['type'] === 'apply' ) {
			$operation['journal'] = $journal;
			$operation['journal']['current'] = $prior;
			if ( ! $this->commit_watermarked_flag( $operation, 1 ) ) {
				return 'uncertain';
			}
			if ( ! $this->set_operation_state( $operation, 'complete', [ 'code' => 'watermarked', 'metadata_committed' => true ] ) ) {
				return 'partial';
			}
			$this->operation_result( 'complete', $prior['token'], 'watermarked', __( 'Watermark applied.', 'image-watermark' ), false, [ 'processed' => [], 'skipped' => [], 'failed' => [] ] );
			$this->record_last_operation( $operation['attachment_id'], 'success', 'watermarked', __( 'Watermark applied.', 'image-watermark' ), $context );
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
			// A subsequent apply now takes the reapply branch and restores the
			// verified backup rather than stacking over this promoted target.
			if ( ! $this->commit_watermarked_flag( $operation, 1 ) ) {
				return 'uncertain';
			}
			return 'partial';
		}
		if ( $final_targets > 0 && $stageable_targets > 0 && isset( $prior['type'] ) && $prior['type'] === 'apply' && in_array( isset( $prior['state'] ) ? $prior['state'] : '', [ 'partial', 'interrupted', 'metadata-commit', 'promoting', 'staging', 'rendering', 'backup-done', 'preparing' ], true ) ) {
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
			$this->cleanup_lost_upload_artifact( $stage, $operation['token'], '.iw-stage-' );
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
			$this->cleanup_lost_upload_artifact( $stage, $operation['token'], '.iw-stage-' );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename is the accepted atomic staged promotion.
		if ( ! $this->renew_operation_lock( $operation ) || ( $stage_record = $this->resolve_upload_artifact_path( $target['stage'] ) ) === false || ( $target_record = $this->resolve_upload_artifact_path( $target_relative ) ) === false || ! @rename( $stage_record['path'], $target_record['path'] ) || ! $this->verify_operation_file( $target_record['path'] ) ) {
			$this->cleanup_lost_upload_artifact( $stage, $operation['token'], '.iw-stage-' );
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

	/** @return bool */
	private function create_staged_backup( &$operation, $data, $upload_dir, $options ) {
		$backup_record_path = $this->resolve_backup_path( $data['file'], true, 'backup-root', $operation['attachment_id'] );
		$source_record = $this->resolve_upload_artifact_path( $data['file'] );
		if ( $backup_record_path === false || $source_record === false ) {
			if ( $this->normalize_operation_relative_path( $data['file'] ) === false || $source_record === false ) {
				$operation['backup_error_code'] = 'invalid_backup_path';
				$operation['backup_error'] = __( 'The attachment backup path is invalid.', 'image-watermark' );
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
		if ( $stage_record === false || ! $this->set_operation_state( $operation, 'preparing', [ 'backup' => $backup_record ] ) || ! $this->copy_file( $source, $stage_record['path'], 'backup', $operation['attachment_id'], $options ) || $this->should_inject_operation_fault( 'after_backup_stage_copy', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'] ] ) || ! $this->verify_operation_file( $stage_record['path'] ) ) {
			$this->cleanup_lost_backup_artifact( $stage, $operation['token'] );
			$operation['backup_error'] = __( 'Failed to copy original file to backup location.', 'image-watermark' );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-filesystem rename atomically promotes the verified backup.
		if ( ! $this->set_operation_state( $operation, 'preparing', [ 'backup' => [ 'state' => 'staged', 'root' => 'backup', 'path' => $backup_record_path['relative'], 'stage' => $stage_record['relative'], 'source' => [ 'path' => $data['file'], 'size' => filesize( $source ), 'hash' => hash_file( 'sha256', $source ) ], 'size' => filesize( $stage_record['path'] ), 'hash' => hash_file( 'sha256', $stage_record['path'] ), 'recovery' => $backup_record_path['relative'] ] ] ) || ! $this->renew_operation_lock( $operation ) || ( $stage_record = $this->resolve_backup_path( $stage_record['relative'] ) ) === false || ( $backup_record_path = $this->resolve_backup_path( $backup_record_path['relative'] ) ) === false || ! @rename( $stage_record['path'], $backup_record_path['path'] ) || ! $this->is_valid_backup_file( $backup_record_path['path'] ) || ! $this->renew_operation_lock( $operation ) ) {
			$this->cleanup_lost_backup_artifact( $stage, $operation['token'] );
			$operation['backup_error'] = __( 'Failed to copy original file to backup location.', 'image-watermark' );
			return false;
		}
		if ( ! $this->set_operation_state( $operation, 'preparing', [ 'backup' => [ 'state' => 'promoted', 'root' => 'backup', 'path' => $backup_record_path['relative'], 'stage' => $stage_record['relative'], 'source' => [ 'path' => $data['file'], 'size' => filesize( $source ), 'hash' => hash_file( 'sha256', $source ) ], 'size' => filesize( $backup_record_path['path'] ), 'hash' => hash_file( 'sha256', $backup_record_path['path'] ), 'recovery' => $backup_record_path['relative'] ] ] ) || ! $this->commit_backup_watermark_association( $operation, $association_id ) ) {
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
	private function operation_terminal_failure( &$operation, $data, $method, $context, $state, $code, $message, $retryable = false, $processed = [], $failed = [] ) {
		$sizes = [ 'processed' => $processed, 'failed' => array_map( function( $name ) use ( $code ) { return [ 'name' => $name, 'code' => $code ]; }, $failed ), 'skipped' => [] ];
		$terminal = $this->set_operation_state( $operation, $state, [ 'code' => $code, 'counts' => [ 'processed' => count( $processed ), 'failed' => count( $failed ) ] ] );
		$cleanup_recovery = $state === 'failed' && empty( $processed );
		if ( $terminal && ! $this->cleanup_operation_record_artifacts( $operation, $operation['journal']['current'], $cleanup_recovery ) ) {
			$this->set_operation_state( $operation, $state, [ 'cleanup_pending' => true ] );
		}
		$result = $this->operation_result( $terminal ? $state : 'interrupted', $operation['token'], $terminal ? $code : 'journal_write_failed', $message, $retryable, $sizes );
		if ( $terminal ) {
			$this->record_last_operation( $operation['attachment_id'], 'error', $result['code'], $message, $context, $processed, $failed, [], $result['outcome'] );
		}
		return $this->operation_failure( $data, $method, $operation['attachment_id'], $context, $result );
	}

	/** @return array */
	private function operation_complete( &$operation, $data, $attachment_id, $method, $context, $code, $message, $processed ) {
		if ( ! $this->set_operation_state( $operation, 'complete', [ 'code' => $code, 'metadata_committed' => true, 'counts' => [ 'processed' => count( $processed ), 'failed' => 0 ] ] ) ) {
			return $this->operation_terminal_failure( $operation, $data, $method, $context, 'partial', 'journal_write_failed', __( 'Watermarked files were prepared, but completion could not be safely recorded.', 'image-watermark' ), true, $processed );
		}
		$this->operation_result( 'complete', $operation['token'], $code, $message, false, [ 'processed' => $processed, 'skipped' => [], 'failed' => [] ] );
		$this->record_last_operation( $attachment_id, 'success', $code, $message, $context, $processed, [], [], 'complete' );
		if ( ! $this->cleanup_owned_operation_stages( $operation, true ) ) {
			$this->set_operation_state( $operation, 'complete', [ 'cleanup_pending' => true ] );
		} elseif ( ! $this->clear_completed_recovery_authority( $operation ) ) {
			$this->set_operation_state( $operation, 'complete', [ 'cleanup_pending' => true ] );
		}
		return $data;
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
		foreach ( $journal['current']['targets'] as $index => $target ) {
			unset( $target['stage'], $target['recovery'] );
			$journal['current']['targets'][ $index ] = $target;
		}
		unset( $journal['current']['temporary_recovery_restore'] );
		$journal['current']['cleanup_pending'] = false;
		$journal = $this->retire_recoverable_predecessor( $journal );
		$operation['journal'] = $journal;
		return $this->renew_operation_lock( $operation ) && $this->write_operation_journal( $operation['attachment_id'], $journal );
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
		$clean = true;
		foreach ( $records as $record ) {
			if ( ! $this->cleanup_operation_record_artifacts( $operation, $record, $cleanup_recovery ) ) {
				$clean = false;
			}
		}
		return $clean;
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
		foreach ( $targets as $target ) {
			if ( empty( $target['stage'] ) || strpos( basename( $target['stage'] ), '.iw-stage-' . $record['token'] . '-' ) !== 0 ) {
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only operation-owned backup-root or legacy uploads-root stage files.
				if ( $stage_record !== false && is_file( $stage_record['path'] ) && ( $this->should_inject_operation_fault( 'stage_cleanup', [ 'attachment_id' => $operation['attachment_id'], 'token' => $record['token'], 'stage' => $record['backup']['stage'] ] ) || ! $this->renew_operation_lock( $operation ) || ! @unlink( $stage_record['path'] ) ) ) {
					$clean = false;
				}
			}
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
	private function cleanup_lost_backup_artifact( $path, $token ) {
		$relative = $this->operation_root_relative_path( $path, $this->get_backup_root_dir() );
		if ( $relative === false || strpos( basename( $relative ), '.iw-stage-' . $token . '-' ) !== 0 ) {
			return;
		}
		$artifact = $this->resolve_backup_path( $relative );
		if ( $artifact === false || $artifact['relative'] !== $relative || ! is_file( $artifact['path'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- A fence-losing worker removes only its own unpromoted backup-root stage.
		@unlink( $artifact['path'] );
	}

	/**
	 * Run WordPress derivative generation under a durable journal phase. WordPress
	 * itself owns derivative writes (P00-08 will provide its full staged contract),
	 * so this coordinator never reports complete until the generated metadata is
	 * journaled, fenced, and committed by the caller.
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
			if ( ! is_string( $name ) || ! is_array( $size ) || empty( $size['file'] ) || ! is_string( $size['file'] ) || $size['file'] === $original_image ) {
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

	/** @return array */
	private function recoverable_derivative_paths( $operation, $upload_dir ) {
		$paths = [];
		$prior = ! empty( $operation['journal']['recoverable_predecessor']['derivative_recovery']['previous'] ) ? $operation['journal']['recoverable_predecessor']['derivative_recovery']['previous'] : [];
		if ( ! is_array( $prior ) ) {
			return $paths;
		}
		foreach ( $prior as $name => $relative ) {
			if ( ! is_string( $name ) || ! is_string( $relative ) || strpos( $relative, '..' ) !== false || strpos( $relative, ':' ) !== false ) {
				continue;
			}
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$identity = $this->operation_path_identity( $path, $upload_dir );
			if ( $identity !== false && $identity['relative'] === $relative ) {
				$paths[ $name ] = $relative;
			}
		}
		return $paths;
	}

	/**
	 * Determine whether another attachment resolves to a candidate derivative.
	 * Direct attachment-file matches are checked first. Metadata candidates are
	 * followed by an exhaustive, paged attachment scan. Every reference is
	 * canonicalized with the same identity rule used by target grouping. The scan
	 * has a fixed 10,000-row ceiling; an incomplete or failed scan is uncertain,
	 * never permission to delete.
	 *
	 * @return string exclusive|shared|uncertain
	 */
	private function derivative_path_ownership( $attachment_id, $path, $identity, $upload_dir ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! isset( $wpdb->postmeta, $wpdb->posts ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return 'uncertain';
		}
		$relative = $identity['relative'];
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- P00-08 one-shot ownership scan must prove a derivative is exclusive before destructive cleanup.
		$direct = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id != %d LIMIT 1", '_wp_attached_file', $relative, $attachment_id ) );
		if ( ! empty( $wpdb->last_error ) ) {
			return 'uncertain';
		}
		if ( ! empty( $direct ) ) {
			return 'shared';
		}

		$last_id = 0;
		$scanned = 0;
		$batch_size = 100;
		$maximum_rows = 10000;
		while ( $scanned < $maximum_rows ) {
			if ( $this->should_inject_operation_fault( 'ownership_scan_row_cap', [ 'attachment_id' => $attachment_id, 'scanned' => $scanned ] ) ) {
				return 'uncertain';
			}
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- P00-08 bounded one-shot ownership scan must read current attachment metadata before destructive cleanup.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT p.ID, MAX(CASE WHEN pm.meta_key = '_wp_attached_file' THEN pm.meta_value ELSE NULL END) AS attached_file, MAX(CASE WHEN pm.meta_key = '_wp_attachment_metadata' THEN pm.meta_value ELSE NULL END) AS attachment_metadata, SUM(pm.meta_key = '_wp_attached_file') AS attached_count, SUM(pm.meta_key = '_wp_attachment_metadata') AS metadata_count FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key IN ('_wp_attached_file', '_wp_attachment_metadata') WHERE p.post_type = 'attachment' AND p.ID > %d GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d", $last_id, $batch_size ) );
			if ( ! empty( $wpdb->last_error ) || ! is_array( $rows ) ) {
				return 'uncertain';
			}
			if ( empty( $rows ) ) {
				return 'exclusive';
			}
			foreach ( $rows as $row ) {
				if ( ! isset( $row->ID ) || (int) $row->ID <= $last_id || (int) $row->attached_count > 1 || (int) $row->metadata_count > 1 ) {
					return 'uncertain';
				}
				$last_id = (int) $row->ID;
				$scanned++;
				if ( $scanned > $maximum_rows ) {
					return 'uncertain';
				}
				if ( $last_id === (int) $attachment_id ) {
					continue;
				}
				if ( ! empty( $row->attached_file ) ) {
					if ( ! is_string( $row->attached_file ) ) {
						return 'uncertain';
					}
					$candidate_identity = $this->operation_path_identity( $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $row->attached_file, $upload_dir );
					if ( $candidate_identity !== false && $candidate_identity['identity'] === $identity['identity'] ) {
						return 'shared';
					}
				}
				if ( empty( $row->attachment_metadata ) ) {
					continue;
				}
				$metadata = maybe_unserialize( $row->attachment_metadata );
				if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || ! is_string( $metadata['file'] ) ) {
					return 'uncertain';
				}
				$directory = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . dirname( $metadata['file'] );
				$references = [ $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $metadata['file'] ];
				if ( isset( $metadata['original_image'] ) ) {
					if ( ! is_string( $metadata['original_image'] ) ) {
						return 'uncertain';
					}
					$references[] = $directory . DIRECTORY_SEPARATOR . $metadata['original_image'];
				}
				if ( isset( $metadata['sizes'] ) ) {
					if ( ! is_array( $metadata['sizes'] ) ) {
						return 'uncertain';
					}
					foreach ( $metadata['sizes'] as $size ) {
						if ( ! is_array( $size ) || ! isset( $size['file'] ) || ! is_string( $size['file'] ) ) {
							return 'uncertain';
						}
						$references[] = $directory . DIRECTORY_SEPARATOR . $size['file'];
					}
				}
				foreach ( $references as $reference ) {
					$candidate_identity = $this->operation_path_identity( $reference, $upload_dir );
					if ( $candidate_identity !== false && $candidate_identity['identity'] === $identity['identity'] ) {
						return 'shared';
					}
				}
			}
			if ( count( $rows ) < $batch_size ) {
				return 'exclusive';
			}
		}
		return 'uncertain';
	}

	/** @return array{retired:array,failed:array,outcomes:array} */
	private function reconcile_regenerated_derivatives( &$operation, $previous, $metadata, $original_file, $upload_dir, $watermarked ) {
		if ( empty( $previous ) ) {
			return [ 'retired' => [], 'failed' => [], 'outcomes' => [] ];
		}
		$fresh = $this->snapshot_registered_derivatives( $metadata, $original_file, $upload_dir );
		$outcomes = [];
		$candidates = [];
		foreach ( $previous as $name => $old_path ) {
			if ( in_array( $old_path, $fresh, true ) ) {
				$outcomes[ $name ] = 'retained';
			} elseif ( isset( $fresh[ $name ] ) ) {
				$outcomes[ $name ] = 'retire_replaced';
				$candidates[ $name ] = $old_path;
			} elseif ( $watermarked ) {
				// Core drops unregistered custom sizes on generation. Do not revive
				// them; the attachment's watermarked state establishes ownership of
				// this formerly registered derivative for safe retirement.
				$outcomes[ $name ] = 'retire_size_gone';
				$candidates[ $name ] = $old_path;
			} else {
				$outcomes[ $name ] = 'left_unreconciled';
			}
		}
		$details = [
			'derivative_recovery' => [
				'previous' => $previous,
				'regenerated' => $fresh,
				'outcomes' => $outcomes,
				'retired' => [],
				'retiring' => [],
			],
		];
		if ( ! $this->set_operation_state( $operation, 'metadata-commit', $details ) ) {
			return [ 'retired' => [], 'failed' => array_keys( $candidates ), 'outcomes' => $outcomes ];
		}

		$previously_retired = ! empty( $operation['journal']['recoverable_predecessor']['derivative_recovery']['retired'] ) && is_array( $operation['journal']['recoverable_predecessor']['derivative_recovery']['retired'] ) ? $operation['journal']['recoverable_predecessor']['derivative_recovery']['retired'] : [];
		$previously_retiring = ! empty( $operation['journal']['recoverable_predecessor']['derivative_recovery']['retiring'] ) && is_array( $operation['journal']['recoverable_predecessor']['derivative_recovery']['retiring'] ) ? $operation['journal']['recoverable_predecessor']['derivative_recovery']['retiring'] : [];
		$retired = [];
		$retiring = [];
		$failed = [];
		foreach ( $candidates as $name => $relative ) {
			$path = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			if ( ( in_array( $relative, $previously_retired, true ) || in_array( $relative, $previously_retiring, true ) ) && ! file_exists( $path ) ) {
				$retired[] = $relative;
				$details['derivative_recovery']['outcomes'][ $name ] = 'retired_predecessor';
				$details['derivative_recovery']['retired'] = $retired;
				if ( ! $this->set_operation_state( $operation, 'metadata-commit', $details ) ) {
					$failed[] = $name;
				}
				continue;
			}
			// The recorded old metadata path must still resolve under uploads before
			// it can be deleted. Never infer ownership from a matching filename.
			$identity = $this->operation_path_identity( $path, $upload_dir );
			if ( $identity === false || $identity['relative'] !== $relative ) {
				$failed[] = $name;
				continue;
			}
			$ownership = $this->derivative_path_ownership( $operation['attachment_id'], $path, $identity, $upload_dir );
			if ( $ownership !== 'exclusive' ) {
				$details['derivative_recovery']['outcomes'][ $name ] = $ownership === 'shared' ? 'retained_shared' : 'retained_ownership_uncertain';
				if ( ! $this->set_operation_state( $operation, 'metadata-commit', $details ) ) {
					$failed[] = $name;
				}
				continue;
			}
			$retiring[] = $relative;
			$details['derivative_recovery']['retiring'] = $retiring;
			if ( ! $this->set_operation_state( $operation, 'metadata-commit', $details ) ) {
				$failed[] = $name;
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Retire only a lock-owned obsolete derivative after promotion.
			if ( $this->should_inject_operation_fault( 'derivative_retire', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $relative ] ) || ! $this->renew_operation_lock( $operation ) || ! @unlink( $path ) ) {
				$failed[] = $name;
				continue;
			}
			if ( $this->should_inject_operation_fault( 'after_derivative_retire', [ 'attachment_id' => $operation['attachment_id'], 'token' => $operation['token'], 'target' => $relative ] ) ) {
				$failed[] = $name;
				continue;
			}
			$retired[] = $relative;
			$details['derivative_recovery']['retired'] = $retired;
			if ( ! $this->set_operation_state( $operation, 'metadata-commit', $details ) ) {
				$failed[] = $name;
				break;
			}
		}

		return [ 'retired' => $retired, 'failed' => $failed, 'outcomes' => $outcomes ];
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
		try {
			$metadata = wp_generate_attachment_metadata( $attachment_id, $filepath );
		} finally {
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
	private function execute_staged_remove( $data, $attachment_id, $filepath, $backup_filepath, $options ) {
		$token = $this->operation_token();
		$lock = $this->acquire_operation_lock( $attachment_id, $token );
		if ( $lock === false ) {
			return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->operation_result( 'failed', '', 'busy', __( 'Another watermark operation is already in progress. Please retry shortly.', 'image-watermark' ), true ) );
		}
		$operation = [ 'attachment_id' => $attachment_id, 'token' => $token, 'lock' => $lock ];
		try {
			$upload_dir = wp_upload_dir();
			$relative = $this->operation_destination_relative_path( $filepath, $upload_dir );
			$groups = [ $relative => [ 'target' => $relative, 'aliases' => [ 'full' ] ] ];
			if ( $relative === false || ! $this->begin_operation( $operation, 'remove', 'manual-remove', $groups ) ) {
				return $this->operation_failure( $data, 'manual', $attachment_id, 'manual-remove', $this->operation_result( 'failed', $token, 'journal_invalid', __( 'Watermark recovery state could not be safely recorded.', 'image-watermark' ), true ) );
			}
			$previous_derivatives = array_merge( $this->recoverable_derivative_paths( $operation, $upload_dir ), $this->snapshot_registered_derivatives( $data, $filepath, $upload_dir ) );
			if ( ! $this->stage_copy_and_promote( $operation, $backup_filepath, $filepath, 'remove-source', $options ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'failed', 'restore_failed', __( 'Failed to restore from backup.', 'image-watermark' ), true );
			}
			if ( ! $this->set_operation_state( $operation, 'metadata-commit' ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'journal_write_failed', __( 'The clean file was restored, but its metadata could not be safely committed.', 'image-watermark' ), true );
			}
			$metadata = $this->generate_metadata_under_operation( $operation, $attachment_id, $filepath, 'remove', $previous_derivatives, isset( $data['original_image'] ) ? $data['original_image'] : '' );
			if ( ! is_array( $metadata ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'metadata_commit_failed', __( 'The clean file was restored, but attachment metadata could not be committed.', 'image-watermark' ), true, [], array_merge( [ 'full' ], array_keys( $previous_derivatives ) ) );
			}
			if ( ! $this->set_regenerated_metadata_commit_state( $operation, $metadata, 'pending' ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'journal_write_failed', __( 'The clean file was restored, but regenerated metadata could not be safely prepared for commit.', 'image-watermark' ), true, [ 'full' ] );
			}
			if ( $this->should_inject_operation_fault( 'after_regenerated_metadata_pending', [ 'attachment_id' => $attachment_id, 'token' => $token ] ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'metadata_commit_interrupted', __( 'The clean file was restored, but regenerated metadata commit was interrupted.', 'image-watermark' ), true, [ 'full' ] );
			}
			$derivatives = $this->reconcile_regenerated_derivatives( $operation, $previous_derivatives, $metadata, $filepath, $upload_dir, (int) get_post_meta( $attachment_id, $this->plugin->get_watermarked_meta_key(), true ) === 1 );
			if ( ! empty( $derivatives['failed'] ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'derivative_reconciliation_failed', __( 'The clean file was restored, but obsolete derivatives could not be safely reconciled.', 'image-watermark' ), true, [ 'full' ], $derivatives['failed'] );
			}
			// derivative reconciliation has just fence-checked and journaled the
			// intended metadata boundary; commit that fresh metadata without asking
			// a same-second third renewal to prove a new fact.
			if ( ! $this->commit_regenerated_metadata( $attachment_id, $metadata ) ) {
				$this->set_regenerated_metadata_commit_state( $operation, $metadata, 'refused' );
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'metadata_commit_failed', __( 'The clean file was restored, but attachment metadata could not be committed.', 'image-watermark' ), true, [ 'full' ], array_keys( $previous_derivatives ) );
			}
			if ( ! $this->set_regenerated_metadata_commit_state( $operation, $metadata, 'committed' ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'journal_write_failed', __( 'The clean file was restored, but regenerated metadata commit could not be recorded.', 'image-watermark' ), true, [ 'full' ] );
			}
			if ( ! $this->commit_watermarked_flag( $operation, 0 ) ) {
				return $this->operation_terminal_failure( $operation, $data, 'manual', 'manual-remove', 'partial', 'metadata_commit_failed', __( 'The clean file was restored, but its watermark state could not be committed.', 'image-watermark' ), true );
			}
			return $this->operation_complete( $operation, wp_get_attachment_metadata( $attachment_id ), $attachment_id, 'manual', 'manual-remove', 'watermarkremoved', __( 'Watermark removed.', 'image-watermark' ), array_merge( [ 'full' ], array_keys( $previous_derivatives ) ) );
		} finally {
			$this->internal_operation_guard = null;
			$this->release_operation_lock( $operation );
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
	private function copy_file( $source, $destination, $context = '', $attachment_id = 0, $options = null ) {
		$injected_result = apply_filters( 'iw_watermark_filesystem_copy', null, $source, $destination, $context, $attachment_id );

		if ( null !== $injected_result ) {
			return (bool) $injected_result;
		}

		if ( ! is_array( $options ) ) {
			$options = $this->plugin->options;
		}

		$preserve = ! empty( $options['backup']['preserve_timestamps'] );
		$preserve = apply_filters( 'iw_preserve_backup_timestamps', $preserve, $source, $destination, $context, $attachment_id );

		if ( $preserve ) {
			return $this->copy_with_timestamps( $source, $destination );
		}

		return copy( $source, $destination );
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
		return is_file( $filepath ) && is_readable( $filepath ) && filesize( $filepath ) > 0 && getimagesize( $filepath ) !== false;
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
	 * @return void
	 */
	private function record_last_operation( $attachment_id, $status, $code, $message, $context, $processed = [], $failed = [], $skipped = [], $outcome = '' ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return;
		}

		$allowed_statuses = [ 'success', 'error', 'skipped', 'warning' ];
		$allowed_contexts = [ 'auto-apply', 'manual-apply', 'manual-remove', 'bulk-apply', 'bulk-remove' ];

		update_post_meta( $attachment_id, '_iw_last_operation', [
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
		] );
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
			$targets = ! empty( $record['targets'] ) && is_array( $record['targets'] ) ? $record['targets'] : [];
			if ( ! empty( $record['restoration'] ) && is_array( $record['restoration'] ) ) {
				$targets[] = $record['restoration'];
			}
			if ( ! empty( $record['temporary_recovery_restore'] ) && is_array( $record['temporary_recovery_restore'] ) ) {
				$targets[] = $record['temporary_recovery_restore'];
			}
			foreach ( $targets as $target ) {
				if ( ! is_array( $target ) || empty( $target['stage'] ) ) {
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
					} elseif ( is_file( $backup_stage['path'] ) && ( ! $this->renew_operation_lock( $operation ) || ( $backup_stage = $this->resolve_backup_path( $backup['stage'] ) ) === false || ! @unlink( $backup_stage['path'] ) ) ) {
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
