<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Image_Watermark_Actions_Controller {

	/**
	 * Plugin instance.
	 *
	 * @var Image_Watermark
	 */
	private $plugin;

	/**
	 * Upload handler service.
	 *
	 * @var Image_Watermark_Upload_Handler
	 */
	private $upload_handler;

	/** @var int Maximum fallback operations per list-table request. */
	private $bulk_batch_size = 20;

	/** @var int Seconds that a user-scoped bulk result handoff is retained. */
	private $bulk_result_ttl = 900;

	/**
	 * Controller constructor.
	 *
	 * @param Image_Watermark $plugin
	 * @param Image_Watermark_Upload_Handler $upload_handler
	 */
	public function __construct( Image_Watermark $plugin, Image_Watermark_Upload_Handler $upload_handler ) {
		$this->plugin = $plugin;
		$this->upload_handler = $upload_handler;
	}

	/**
	 * Build a structured error array for AJAX error responses.
	 *
	 * JS callers check `typeof data === 'object' && data.message` to distinguish
	 * these from legacy plain-string errors.
	 *
	 * @param string $message Human-readable error.
	 * @param string $hint    Optional remediation hint.
	 * @param string $code    Optional machine-readable code.
	 * @return array{ message: string, hint: string, code: string }
	 */
	private function error_response( $message, $hint = '', $code = '' ) {
		return [
			'message' => $message,
			'hint'    => $hint,
			'code'    => sanitize_key( $code ),
		];
	}

	/**
	 * Store a bounded fallback result outside the redirect URL.
	 *
	 * @param array $messages Bounded display messages.
	 * @param array $outcomes Bounded attachment-keyed outcomes.
	 * @param int   $remaining Selected items not submitted in this request.
	 * @return string|false
	 */
	private function store_bulk_result_handoff( $messages, $outcomes, $remaining ) {
		$user_id = get_current_user_id();
		$token = wp_generate_password( 20, false, false );
		$result = [
			'user_id'   => $user_id,
			'messages'  => array_slice( (array) $messages, 0, $this->bulk_batch_size ),
			'outcomes'  => array_slice( (array) $outcomes, 0, $this->bulk_batch_size, true ),
			'remaining' => max( 0, (int) $remaining ),
		];

		if ( ! set_transient( 'iw_bulk_result_' . $user_id . '_' . $token, $result, $this->bulk_result_ttl ) ) {
			return false;
		}

		return $token;
	}

	/**
	 * Return the current user's bounded fallback result handoff.
	 *
	 * The token only identifies the result; the transient key is additionally scoped
	 * to the current user and expires quickly. It is display data, not a job queue.
	 *
	 * @param string $token Redirect result token.
	 * @return array
	 */
	public function get_bulk_result_handoff( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[A-Za-z0-9]{20}$/', $token ) ) {
			return [];
		}

		$user_id = get_current_user_id();
		$result = get_transient( 'iw_bulk_result_' . $user_id . '_' . $token );

		if ( ! is_array( $result ) || ! isset( $result['user_id'] ) || (int) $result['user_id'] !== $user_id ) {
			return [];
		}

		return [
			'messages'  => array_slice( isset( $result['messages'] ) ? (array) $result['messages'] : [], 0, $this->bulk_batch_size ),
			'outcomes'  => array_slice( isset( $result['outcomes'] ) ? (array) $result['outcomes'] : [], 0, $this->bulk_batch_size, true ),
			'remaining' => max( 0, isset( $result['remaining'] ) ? (int) $result['remaining'] : 0 ),
		];
	}

	/**
	 * Preserve legacy error fields while carrying the private P00-09 outcome for
	 * native consumers. Complete success strings remain unchanged.
	 *
	 * @param string $message User-safe message.
	 * @param string $code Stable error code.
	 * @param array  $result Upload-handler result.
	 * @return array
	 */
	private function operation_error_response( $message, $code, $result ) {
		$response = $this->error_response( $message, '', $code );
		if ( is_array( $result ) && isset( $result['outcome'] ) && is_array( $result['outcome'] ) ) {
			$response['outcome'] = $result['outcome'];
		}
		return $response;
	}

	/**
	 * Validate and authorize an attachment request without reading its files or metadata.
	 *
	 * @param mixed  $attachment_id Raw request value.
	 * @param mixed  $nonce Raw nonce value.
	 * @param string $nonce_action Nonce action.
	 * @return array{authorized:bool,attachment_id:int,error:array}
	 */
	private function authorize_attachment_request( $attachment_id, $nonce, $nonce_action ) {
		$normalized_id = $this->normalize_attachment_id( $attachment_id );

		if ( ! $normalized_id ) {
			return [
				'authorized'    => false,
				'attachment_id' => 0,
				'error'         => $this->error_response( __( 'Invalid attachment ID.', 'image-watermark' ), '', 'invalid_id' ),
			];
		}

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			return [
				'authorized'    => false,
				'attachment_id' => $normalized_id,
				'error'         => $this->error_response(
					__( 'Security check failed. Please refresh the page and try again.', 'image-watermark' ),
					__( 'Refresh the page and try again.', 'image-watermark' ),
					'nonce_failed'
				),
			];
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return [
				'authorized'    => false,
				'attachment_id' => $normalized_id,
				'error'         => $this->error_response(
					__( 'You do not have permission to manage images.', 'image-watermark' ),
					__( 'You need the "upload_files" capability to manage images.', 'image-watermark' ),
					'no_permission'
				),
			];
		}

		if ( ! current_user_can( 'edit_post', $normalized_id ) ) {
			return [
				'authorized'    => false,
				'attachment_id' => $normalized_id,
				'error'         => $this->error_response( __( 'You do not have permission to manage this attachment.', 'image-watermark' ), '', 'attachment_forbidden' ),
			];
		}

		$post = get_post( $normalized_id );

		if ( ! $post || $post->post_type !== 'attachment' ) {
			return [
				'authorized'    => false,
				'attachment_id' => $normalized_id,
				'error'         => $this->error_response( __( 'Invalid attachment ID.', 'image-watermark' ), '', 'invalid_attachment' ),
			];
		}

		return [
			'authorized'    => true,
			'attachment_id' => $normalized_id,
			'error'         => [],
		];
	}

	/**
	 * Normalize a positive integer request value without accepting numeric coercions.
	 *
	 * @param mixed $attachment_id Raw request value.
	 * @return int
	 */
	private function normalize_attachment_id( $attachment_id ) {
		if ( is_int( $attachment_id ) ) {
			return $attachment_id > 0 ? $attachment_id : 0;
		}

		if ( ! is_string( $attachment_id ) || ! preg_match( '/^[0-9]+$/D', $attachment_id ) ) {
			return 0;
		}

		$digits = ltrim( $attachment_id, '0' );

		if ( $digits === '' || (string) (int) $digits !== $digits ) {
			return 0;
		}

		return (int) $digits;
	}

	/**
	 * Handles manual AJAX watermark requests.
	 *
	 * Validates request parameters, user permissions, and performs watermark
	 * apply/remove actions on individual attachments. Returns JSON responses
	 * with specific error messages for better debugging.
	 *
	 * Expected POST parameters:
	 * - _iw_nonce: Security nonce
	 * - iw-action: 'applywatermark' or 'removewatermark'
	 * - attachment_id: Image attachment post ID
	 *
	 * Success responses (plain strings, unchanged):
	 * - 'watermarked': Watermark successfully applied
	 * - 'watermarkremoved': Watermark successfully removed
	 *
	 * Error responses are structured objects: { message, hint, code }.
	 *
	 * @since 2.0.0
	 * @return void Outputs JSON response and exits
	 */
	public function watermark_action_ajax() {
		// Check if this is an AJAX request
		if ( ! wp_doing_ajax() ) {
			wp_send_json_error( $this->error_response(
				__( 'You are not allowed to perform this action.', 'image-watermark' ),
				'',
				'not_ajax'
			) );
		}

		// Check required parameters
		if ( ! isset( $_POST['_iw_nonce'], $_POST['iw-action'], $_POST['attachment_id'] ) ) {
			wp_send_json_error( $this->error_response(
				__( 'Missing required parameters.', 'image-watermark' ),
				__( 'Try refreshing the page and repeating the action.', 'image-watermark' ),
				'missing_params'
			) );
		}

		$action  = is_string( $_POST['iw-action'] ) ? sanitize_key( wp_unslash( $_POST['iw-action'] ) ) : '';
		$action  = in_array( $action, [ 'applywatermark', 'removewatermark' ], true ) ? $action : false;

		if ( ! $action ) {
			wp_send_json_error( $this->error_response(
				__( 'Invalid action.', 'image-watermark' ),
				'',
				'invalid_action'
			) );
		}

		$attachment_request = is_string( $_POST['attachment_id'] ) ? wp_unslash( $_POST['attachment_id'] ) : $_POST['attachment_id'];
		$nonce_request      = is_string( $_POST['_iw_nonce'] ) ? wp_unslash( $_POST['_iw_nonce'] ) : '';
		$authorization = $this->authorize_attachment_request( $attachment_request, $nonce_request, 'image-watermark' );

		if ( ! $authorization['authorized'] ) {
			wp_send_json_error( $authorization['error'] );
		}

		$post_id = $authorization['attachment_id'];

		$eligibility = $this->upload_handler->describe_operation_eligibility( $post_id, $action === 'removewatermark' ? 'manual-remove' : 'manual-apply' );
		if ( empty( $eligibility['valid'] ) && $eligibility['code'] === 'manual_disabled' ) {
			$message = __( 'Manual watermarking is disabled.', 'image-watermark' );
			$response = $this->error_response(
				$message,
				__( 'Enable Manual Watermarking under Image Watermark > Settings.', 'image-watermark' ),
				'manual_disabled'
			);
			$response['outcome'] = $this->upload_handler->record_manual_precondition_failure( $post_id, $action === 'removewatermark' ? 'remove' : 'apply', 'manual_disabled', $message );
			wp_send_json_error( $response );
		}

		if ( $post_id > 0 ) {
			$data = wp_get_attachment_metadata( $post_id, false );

			if ( in_array( get_post_mime_type( $post_id ), $this->plugin->get_allowed_mime_types(), true ) && is_array( $data ) ) {
				if ( $action === 'applywatermark' ) {
					$success = $this->upload_handler->apply_watermark( $data, $post_id, 'manual' );

					if ( ! empty( $success['error'] ) ) {
							wp_send_json_error( $this->operation_error_response( $success['error'], 'apply_failed', $success ) );
					}

					$outcome = $this->upload_handler->get_last_operation_outcome();
					if ( ! empty( $outcome ) && $outcome['outcome'] !== 'complete' ) {
						wp_send_json_error( $this->operation_error_response( $outcome['message'], $outcome['code'], [ 'outcome' => $outcome ] ) );
					}

					wp_send_json_success( 'watermarked' );
				} elseif ( $action === 'removewatermark' ) {
					$success = $this->upload_handler->remove_watermark( $data, $post_id, 'manual' );

					if ( is_array( $success ) && ! empty( $success['error'] ) ) {
							wp_send_json_error( $this->operation_error_response( $success['error'], 'remove_failed', $success ) );
					}

					if ( $success ) {
						$outcome = $this->upload_handler->get_last_operation_outcome();
						if ( ! empty( $outcome ) && $outcome['outcome'] !== 'complete' ) {
							wp_send_json_error( $this->operation_error_response( $outcome['message'], $outcome['code'], [ 'outcome' => $outcome ] ) );
						}
						wp_send_json_success( 'watermarkremoved' );
					} else {
						$msg = __( 'Failed to remove watermark.', 'image-watermark' );
						wp_send_json_error( $this->error_response( $msg, '', 'remove_failed' ) );
					}
				}
				} else {
					$mime = get_post_mime_type( $post_id );
					/* translators: 1: Unsupported MIME type. */
					$message = sprintf( __( 'Unsupported file type (%s). Only JPEG, PNG, and WebP are supported.', 'image-watermark' ), $mime ?: 'unknown' );
					$response = $this->error_response(
						$message,
						__( 'Only JPEG, PNG, and WebP images can be watermarked.', 'image-watermark' ),
						'unsupported_mime'
					);
					$response['outcome'] = $this->upload_handler->record_manual_precondition_failure( $post_id, $action === 'removewatermark' ? 'remove' : 'apply', 'unsupported_mime', $message );
					wp_send_json_error( $response );
			}
		}

		// Fallback: should not reach here if all checks above are correct
		wp_send_json_error( $this->error_response(
			__( 'Unable to perform action. Invalid attachment or request.', 'image-watermark' ),
			__( 'Refresh the page and try again.', 'image-watermark' ),
			'unknown_error'
		) );
	}

	/**
	 * AJAX handler: returns per-attachment diagnostics.
	 *
	 * Nonce action: iw_diagnose_attachment
	 * Capability:   upload_files
	 * POST params:  attachment_id (int), _iw_diag_nonce (string)
	 *
	 * @return void Outputs JSON and exits.
	 */
	public function diagnose_attachment_ajax() {
		if ( ! isset( $_POST['_iw_diag_nonce'], $_POST['attachment_id'] ) ) {
			wp_send_json_error( $this->error_response(
				__( 'Missing required parameters.', 'image-watermark' ),
				'',
				'missing_params'
			) );
		}

		$attachment_request = is_string( $_POST['attachment_id'] ) ? wp_unslash( $_POST['attachment_id'] ) : $_POST['attachment_id'];
		$nonce_request      = is_string( $_POST['_iw_diag_nonce'] ) ? wp_unslash( $_POST['_iw_diag_nonce'] ) : '';
		$authorization = $this->authorize_attachment_request( $attachment_request, $nonce_request, 'iw_diagnose_attachment' );

		if ( ! $authorization['authorized'] ) {
			wp_send_json_error( $authorization['error'] );
		}

		$attachment_id = $authorization['attachment_id'];
		$diagnostics   = $this->plugin->get_diagnostics();

		if ( ! $diagnostics ) {
			wp_send_json_error( $this->error_response(
				__( 'Diagnostics service unavailable.', 'image-watermark' ),
				'',
				'service_unavailable'
			) );
		}

		$report  = $diagnostics->attachment_report( $attachment_id );
		$summary = $diagnostics->attachment_action_summary( $report );

		wp_send_json_success( [
			'items'   => $report,
			'summary' => $summary,
		] );
	}

	/**
	 * Handles bulk actions from the media list table.
	 */
	public function watermark_bulk_action() {
		global $pagenow;

		if ( $pagenow !== 'upload.php' || ! $this->plugin->get_extension() ) {
			return;
		}

		$wp_list_table = _get_list_table( 'WP_Media_List_Table' );
		$action = $wp_list_table->current_action();
		$action = in_array( $action, [ 'applywatermark', 'removewatermark' ], true ) ? $action : false;

		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-media' );

		$location = esc_url( remove_query_arg( [ 'watermarked', 'watermarkremoved', 'skipped', 'iw_partial_apply', 'iw_partial_remove', 'iw_bulk_result', 'iw_bulk_remaining', 'iw_outcomes', 'messages', 'iw_error', 'trashed', 'untrashed', 'deleted', 'message', 'ids', 'posted' ], wp_get_referer() ) );

		if ( ! $location ) {
			$location = 'upload.php';
		}

		$location = esc_url( add_query_arg( 'paged', $wp_list_table->get_pagenum(), $location ) );
		$location = wp_validate_redirect( $location, admin_url( 'upload.php' ) );

		$post_ids = isset( $_REQUEST['media'] ) && is_array( $_REQUEST['media'] ) ? wp_unslash( $_REQUEST['media'] ) : [];
		$remaining = max( 0, count( $post_ids ) - $this->bulk_batch_size );
		$post_ids = array_slice( $post_ids, 0, $this->bulk_batch_size );
		$filter_post_ids = [];

		if ( $post_ids ) {
			$watermarked = $watermarkremoved = $skipped = $partial_apply = $partial_remove = 0;
			$messages = [];
			$outcomes = [];

			foreach ( $post_ids as $post_id ) {
				$authorization = $this->authorize_attachment_request(
					$post_id,
					isset( $_REQUEST['_wpnonce'] ) && is_string( $_REQUEST['_wpnonce'] ) ? wp_unslash( $_REQUEST['_wpnonce'] ) : null,
					'bulk-media'
				);
				if ( $authorization['attachment_id'] ) {
					$filter_post_ids[] = $authorization['attachment_id'];
				}

				if ( ! $authorization['authorized'] ) {
					$messages[] = $authorization['attachment_id']
						/* translators: 1: Attachment ID. 2: Operation error message. */
						? sprintf( __( 'ID %1$d: %2$s', 'image-watermark' ), $authorization['attachment_id'], $authorization['error']['message'] )
						: $authorization['error']['message'];
					$skipped++;
					if ( count( $outcomes ) < $this->bulk_batch_size ) {
						$outcomes[ $authorization['attachment_id'] ?: 0 ] = [ 'outcome' => 'failed', 'code' => $authorization['error']['code'], 'retryable' => false ];
					}
					continue;
				}

				$post_id = $authorization['attachment_id'];
				$data = wp_get_attachment_metadata( $post_id, false );

				if ( in_array( get_post_mime_type( $post_id ), $this->plugin->get_allowed_mime_types(), true ) && is_array( $data ) ) {
					if ( $action === 'applywatermark' ) {
						$success = $this->upload_handler->apply_watermark( $data, $post_id, 'manual' );

						if ( ! empty( $success['error'] ) ) {
							$messages[] = sprintf(
								/* translators: 1: Attachment ID. 2: Operation error message. */
								__( 'ID %1$d: %2$s', 'image-watermark' ),
								$post_id,
								$success['error']
							);
							if ( isset( $success['outcome']['outcome'] ) && $success['outcome']['outcome'] === 'partial' ) {
								$partial_apply++;
								$watermarkremoved = -1;
							} else {
								$skipped++;
							}
							if ( count( $outcomes ) < $this->bulk_batch_size ) {
								$outcome = isset( $success['outcome'] ) ? $success['outcome'] : [ 'outcome' => 'failed', 'code' => 'apply_failed', 'retryable' => false ];
								$outcome['operation'] = 'apply';
								$outcomes[ $post_id ] = $outcome;
							}
						} else {
							$outcome = $this->upload_handler->get_last_operation_outcome();
							if ( ! empty( $outcome ) && $outcome['outcome'] !== 'complete' ) {
								/* translators: 1: Attachment ID. 2: Operation error message. */
								$messages[] = sprintf( __( 'ID %1$d: %2$s', 'image-watermark' ), $post_id, $outcome['message'] );
								if ( $outcome['outcome'] === 'partial' ) {
									$partial_apply++;
									$watermarkremoved = -1;
								} else {
									$skipped++;
								}
							} else {
								$watermarked++;
								$watermarkremoved = -1;
							}
							if ( count( $outcomes ) < $this->bulk_batch_size ) {
								$outcome['operation'] = 'apply';
								$outcomes[ $post_id ] = $outcome;
							}
						}
					} elseif ( $action === 'removewatermark' ) {
						$success = $this->upload_handler->remove_watermark( $data, $post_id, 'manual' );

						if ( is_array( $success ) && ! empty( $success['error'] ) ) {
							$messages[] = sprintf(
								/* translators: 1: Attachment ID. 2: Operation error message. */
								__( 'ID %1$d: %2$s', 'image-watermark' ),
								$post_id,
								$success['error']
							);
							if ( isset( $success['outcome']['outcome'] ) && $success['outcome']['outcome'] === 'partial' ) {
								$partial_remove++;
							} else {
								$skipped++;
							}
							if ( count( $outcomes ) < $this->bulk_batch_size ) {
								$outcome = isset( $success['outcome'] ) ? $success['outcome'] : [ 'outcome' => 'failed', 'code' => 'remove_failed', 'retryable' => false ];
								$outcome['operation'] = 'remove';
								$outcomes[ $post_id ] = $outcome;
							}
						} elseif ( $success ) {
							$outcome = $this->upload_handler->get_last_operation_outcome();
							if ( ! empty( $outcome ) && $outcome['outcome'] !== 'complete' ) {
								/* translators: 1: Attachment ID. 2: Operation error message. */
								$messages[] = sprintf( __( 'ID %1$d: %2$s', 'image-watermark' ), $post_id, $outcome['message'] );
								if ( $outcome['outcome'] === 'partial' ) {
									$partial_remove++;
								} else {
									$skipped++;
								}
							} else {
								$watermarkremoved++;
							}
							if ( count( $outcomes ) < $this->bulk_batch_size ) {
								$outcome['operation'] = 'remove';
								$outcomes[ $post_id ] = $outcome;
							}
						} else {
							$skipped++;
							if ( count( $outcomes ) < $this->bulk_batch_size ) {
								$outcomes[ $post_id ] = [ 'outcome' => 'failed', 'code' => 'remove_failed', 'operation' => 'remove', 'retryable' => false ];
							}
						}

						$watermarked = -1;
					}
				} else {
					$skipped++;
					if ( count( $outcomes ) < $this->bulk_batch_size ) {
						$outcomes[ $post_id ] = [ 'outcome' => 'skipped', 'code' => 'unsupported_attachment', 'operation' => $action === 'applywatermark' ? 'apply' : 'remove', 'retryable' => false ];
					}
				}
			}

			$messages = array_slice( $messages, 0, $this->bulk_batch_size );
			$args = [
				'watermarked'       => $watermarked,
				'watermarkremoved'  => $watermarkremoved,
				'skipped'           => $skipped,
				'iw_partial_apply'  => $partial_apply,
				'iw_partial_remove' => $partial_remove,
			];

			$handoff_token = $this->store_bulk_result_handoff( $messages, $outcomes, $remaining );
			if ( $handoff_token ) {
				$args['iw_bulk_result'] = $handoff_token;
			} else {
				$args['iw_error'] = __( 'The bulk result could not be stored. Verify whether the selected images are watermarked before retrying.', 'image-watermark' );
			}
			if ( $remaining > 0 ) {
				$args['iw_bulk_remaining'] = $remaining;
			}

			$location = esc_url( add_query_arg( $args, $location ), null, '' );
		}

		$should_exit = apply_filters( 'iw_bulk_action_should_exit', true, $location, $action, $filter_post_ids );

		if ( ! $should_exit ) {
			return $location;
		}

		wp_safe_redirect( $location );
		exit;
	}

}

