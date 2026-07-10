<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Claim_Page {

	private static $instance = null;
	private $post_result     = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_create_page' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_filter( 'the_content', array( $this, 'filter_page_content' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_page_styles' ) );
	}

	/**
	 * Enqueue inline styles on the claim page so text is readable on dark themes.
	 */
	public function enqueue_page_styles() {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}

		wp_add_inline_style( 'wp-block-library', '
			body.page-id-' . $page_id . ' .entry-title,
			body.page-id-' . $page_id . ' .wp-block-post-title { color: #fff !important; }
			body.page-id-' . $page_id . ' .wgdp-claim-wrap { color: #f5f7fb; }
			body.page-id-' . $page_id . ' .wgdp-claim-wrap input[name="otp"] { background: #fff; color: #111827; }
		' );
	}

	/**
	 * Ensure the claim page exists (lightweight init-time check).
	 */
	public function maybe_create_page() {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return;
		}
		self::ensure_page_exists();
	}

	/**
	 * Create the claim page if it does not exist.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_page_exists() {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return $page_id;
		}

		$slug = get_option( 'wgdp_claim_page_slug', 'wgdp-claim-access' );

		$page_id = wp_insert_post( array(
			'post_title'     => 'Claim Access',
			'post_name'      => sanitize_title( $slug ),
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_content'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'wgdp_claim_page_id', $page_id, false );

			$actual_slug = get_post_field( 'post_name', $page_id );
			if ( $actual_slug && $actual_slug !== sanitize_title( $slug ) ) {
				update_option( 'wgdp_claim_page_slug', $actual_slug );
			}
		}

		return $page_id;
	}

	/**
	 * Get the configured claim page slug.
	 */
	public static function get_slug() {
		return get_option( 'wgdp_claim_page_slug', 'wgdp-claim-access' );
	}

	/**
	 * Get the URL for the claim page.
	 *
	 * @return string Page URL.
	 */
	public static function get_page_url() {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		$slug = self::get_slug();
		return home_url( $slug );
	}

	/**
	 * Update the claim page slug when the setting changes.
	 *
	 * @param string $new_slug The new slug.
	 */
	public static function update_page_slug( $new_slug ) {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( $page_id ) {
			wp_update_post( array(
				'ID'        => $page_id,
				'post_name' => sanitize_title( $new_slug ),
			) );
		}
	}

	/**
	 * Handle POST requests on the claim page via template_redirect.
	 *
	 * Processing POST here (before template rendering) ensures side effects
	 * happen once and the result content is ready for the_content filter.
	 */
	public function handle_post() {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}

		// Prevent claim token leakage via Referer headers.
		header( 'Referrer-Policy: no-referrer' );

		// The claim page carries a bearer claim token and can render recipient
		// PII; never let a page cache/CDN store and re-serve it to another visitor.
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$token = isset( $_POST['t'] ) ? sanitize_text_field( wp_unslash( $_POST['t'] ) ) : '';
		if ( empty( $token ) ) {
			return;
		}

		// Look up entitlement.
		$otp_service = WGDP_OTP::instance();
		$ent         = WGDP_Entitlements::instance();
		$token_hash  = $otp_service->hash_claim_token( $token );
		$entitlement = $ent->get_by_claim_token_hash( $token_hash );

		if ( ! $entitlement ) {
			$this->post_result = $this->wrap_content( $this->error_content( 'This link is invalid or has expired.' ) );
			return;
		}

		if ( ! empty( $entitlement['claim_token_expires_at'] ) && strtotime( $entitlement['claim_token_expires_at'] . ' +0000' ) < time() ) {
			$this->post_result = $this->wrap_content( $this->error_content( 'This link has expired. Please contact the store for a new verification email.' ) );
			return;
		}

		if ( 'granted' === $entitlement['grant_status'] ) {
			// Check for siblings to show multi-file success.
			$siblings = $ent->get_siblings( $entitlement['order_item_id'], $entitlement['recipient_email'], $entitlement['id'] );
			if ( ! empty( $siblings ) ) {
				$all = array_merge( array( $entitlement ), $siblings );
				$granted_links = array();
				$resources = WGDP_Product_Meta::get_drive_resources( $entitlement['product_id'], $entitlement['variation_id'] ?: 0 );
				$res_map = array();
				foreach ( $resources as $r ) {
					$res_map[ $r['id'] ] = $r['name'] ?: $r['id'];
				}
				foreach ( $all as $eg ) {
					if ( 'granted' === $eg['grant_status'] ) {
						$granted_links[] = array(
							'name' => $res_map[ $eg['cloud_asset_id'] ] ?? $eg['cloud_asset_id'],
							'link' => $this->get_drive_link( $eg ),
						);
					}
				}
				if ( count( $granted_links ) > 1 ) {
					$this->post_result = $this->wrap_content( $this->success_content_multi( $granted_links, $entitlement, false ) );
					return;
				}
			}
			$drive_link = $this->get_drive_link( $entitlement );
			$this->post_result = $this->wrap_content( $this->success_content( $drive_link, $entitlement ) );
			return;
		}

		if ( 'revoked' === $entitlement['grant_status'] ) {
			$this->post_result = $this->wrap_content( $this->error_content( 'This access has been revoked.' ) );
			return;
		}

		// Verify nonce.
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wgdp_claim_verify' ) ) {
			$this->post_result = $this->wrap_content( $this->form_content( $token, 'Security check failed. Please try again.', $entitlement ) );
			return;
		}

		// Handle "Resend Code" action.
		if ( ! empty( $_POST['wgdp_resend'] ) ) {
			$this->handle_resend_code( $token, $entitlement, $otp_service, $ent );
			return;
		}

		$otp_input = sanitize_text_field( wp_unslash( $_POST['otp'] ?? '' ) );
		$result    = $otp_service->attempt_verification( $token, $otp_input );

		if ( $result['success'] ) {
			$product_id   = (int) $result['entitlement']['product_id'];
			$variation_id = (int) ( $result['entitlement']['variation_id'] ?? 0 );
			$siblings     = $result['siblings'] ?? array();

			// Collect all entitlements to grant (primary + siblings).
			$all_to_grant = array_merge( array( $result['entitlement'] ), $siblings );

			if ( ! WGDP_Release_Gate::is_item_released( $product_id, $variation_id ) ) {
				foreach ( $all_to_grant as $eg ) {
					$ent->mark_pending_release( $eg['id'] );
				}
				$this->post_result = $this->wrap_content( $this->pending_release_content() );
				return;
			}

			$granted_links = array();
			$had_errors    = false;
			$had_retired   = false;

			foreach ( $all_to_grant as $eg ) {
				// Skip retired resources.
				if ( self::is_resource_retired( (int) $eg['product_id'], (int) ( $eg['variation_id'] ?? 0 ), $eg['cloud_asset_id'] ) ) {
					$ent->mark_revoked( $eg['id'], WGDP_Entitlements::REVOCATION_REASON_ASSET_REMOVED );
					$had_retired = true;
					continue;
				}

				$grant_result = self::grant_drive_access_for_entitlement( $eg, count( $all_to_grant ) > 1 );

				if ( is_wp_error( $grant_result ) ) {
					$ent->mark_error( $eg['id'], $grant_result->get_error_message() );
					$had_errors = true;
				} else {
					// $refreshed can be false if the entitlement was deleted between
					// mark_granted and this re-read; fall back to the pre-grant row.
					$refreshed = $ent->get( $eg['id'] );
					if ( ! $refreshed ) {
						$refreshed = $eg;
					}
					$granted_links[] = array(
						'name' => $refreshed['cloud_asset_id'],
						'link' => $this->get_drive_link( $refreshed ),
					);
				}
			}

			// Send consolidated email if multiple files.
			if ( count( $all_to_grant ) > 1 && ! empty( $granted_links ) ) {
				$product_name = WGDP_Entitlements::get_product_name( $result['entitlement'], 'your purchase' );
				// Resolve friendly names from resources.
				$resources = WGDP_Product_Meta::get_drive_resources(
					$result['entitlement']['product_id'],
					$result['entitlement']['variation_id'] ?: 0
				);
				$res_map = array();
				foreach ( $resources as $r ) {
					$res_map[ $r['id'] ] = $r['name'] ?: $r['id'];
				}
				foreach ( $granted_links as &$gl ) {
					if ( isset( $res_map[ $gl['name'] ] ) ) {
						$gl['name'] = $res_map[ $gl['name'] ];
					}
				}
				unset( $gl );
				WGDP_Notification_Email::send_access_granted_batch(
					$result['entitlement']['recipient_email'],
					$granted_links,
					$product_name
				);
				// Also notify the billing email if different.
				$billing = WGDP_Notification_Email::get_billing_email_if_different(
					$result['entitlement']['order_id'],
					$result['entitlement']['recipient_email']
				);
				if ( $billing ) {
					WGDP_Notification_Email::send_access_granted_batch( $billing, $granted_links, $product_name );
				}
			}

			if ( ! empty( $granted_links ) ) {
				if ( count( $granted_links ) > 1 ) {
					$this->post_result = $this->wrap_content( $this->success_content_multi( $granted_links, $result['entitlement'], $had_errors ) );
				} else {
					$this->post_result = $this->wrap_content( $this->success_content( $granted_links[0]['link'], $result['entitlement'] ) );
				}

				WGDP_Order_Handler::instance()->maybe_auto_complete_order( $result['entitlement']['order_id'] );
			} else {
				if ( $had_retired && ! $had_errors ) {
					$this->post_result = $this->wrap_content( $this->error_content(
						'Your identity has been verified, but this Drive file is no longer available. Please contact the store for assistance.'
					) );
				} else {
					$this->post_result = $this->wrap_content( $this->error_content(
						'Your identity has been verified, but we encountered an error granting access. We will retry automatically. Please check back later.'
					) );
				}
			}
		} else {
			$this->post_result = $this->wrap_content( $this->form_content( $token, $result['error'], $result['entitlement'] ) );
		}
	}

	/**
	 * Filter the_content to display the claim page content.
	 */
	public function filter_page_content( $content ) {
		$page_id = (int) get_option( 'wgdp_claim_page_id', 0 );
		if ( ! $page_id || ! is_page( $page_id ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		// If POST was already processed in handle_post(), return its result.
		if ( isset( $this->post_result ) ) {
			return $this->post_result;
		}

		// GET request handling.
		$token = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $token ) ) {
			return $this->wrap_content( $this->error_content( 'Invalid link. No verification token found.' ) );
		}

		$otp_service = WGDP_OTP::instance();
		$ent         = WGDP_Entitlements::instance();
		$token_hash  = $otp_service->hash_claim_token( $token );
		$entitlement = $ent->get_by_claim_token_hash( $token_hash );

		if ( ! $entitlement ) {
			return $this->wrap_content( $this->error_content( 'This link is invalid or has expired.' ) );
		}

		if ( ! empty( $entitlement['claim_token_expires_at'] ) && strtotime( $entitlement['claim_token_expires_at'] . ' +0000' ) < time() ) {
			return $this->wrap_content( $this->error_content( 'This link has expired. Please contact the store for a new verification email.' ) );
		}

		if ( 'granted' === $entitlement['grant_status'] ) {
			// Check for siblings to show multi-file success.
			$siblings = $ent->get_siblings( $entitlement['order_item_id'], $entitlement['recipient_email'], $entitlement['id'] );
			if ( ! empty( $siblings ) ) {
				$all = array_merge( array( $entitlement ), $siblings );
				$granted_links = array();
				$resources = WGDP_Product_Meta::get_drive_resources( $entitlement['product_id'], $entitlement['variation_id'] ?: 0 );
				$res_map = array();
				foreach ( $resources as $r ) {
					$res_map[ $r['id'] ] = $r['name'] ?: $r['id'];
				}
				foreach ( $all as $eg ) {
					if ( 'granted' === $eg['grant_status'] ) {
						$granted_links[] = array(
							'name' => $res_map[ $eg['cloud_asset_id'] ] ?? $eg['cloud_asset_id'],
							'link' => $this->get_drive_link( $eg ),
						);
					}
				}
				if ( count( $granted_links ) > 1 ) {
					return $this->wrap_content( $this->success_content_multi( $granted_links, $entitlement, false ) );
				}
			}
			$drive_link = $this->get_drive_link( $entitlement );
			return $this->wrap_content( $this->success_content( $drive_link, $entitlement ) );
		}

		if ( 'revoked' === $entitlement['grant_status'] ) {
			return $this->wrap_content( $this->error_content( 'This access has been revoked.' ) );
		}

		// Already verified but grant pending or failed.
		if ( 'verified' === $entitlement['verification_status'] && 'granted' !== $entitlement['grant_status'] ) {
			if ( 'pending_release' === $entitlement['grant_status'] ) {
				return $this->wrap_content( $this->pending_release_content() );
			} elseif ( 'error' === $entitlement['grant_status'] ) {
				return $this->wrap_content( $this->error_content(
					'Your identity has been verified, but we encountered an error granting access. We will retry automatically. Please check back later.'
				) );
			} else {
				return $this->wrap_content( $this->error_content(
					'Your identity has been verified. Access is being provisioned and you will receive an email once it is ready.'
				) );
			}
		}

		return $this->wrap_content( $this->form_content( $token, '', $entitlement ) );
	}

	/**
	 * Grant Drive access for a verified entitlement (public static for batch grant).
	 *
	 * @param array $entitlement    The entitlement row.
	 * @param bool  $suppress_email When true, skip sending individual access-granted email (caller will send batch).
	 * @return true|null|WP_Error True if granted just now by this call, null if the entitlement
	 *                            was already granted (no-op — callers that batch a summary email
	 *                            should not count this as a fresh grant), or WP_Error on failure.
	 */
	public static function grant_drive_access_for_entitlement( $entitlement, $suppress_email = false ) {
		$ent   = WGDP_Entitlements::instance();
		return $ent->with_entitlement_lock( (int) $entitlement['id'], function () use ( $ent, $entitlement, $suppress_email ) {
			$entitlement = $ent->get( (int) $entitlement['id'] );
			if ( ! $entitlement ) {
				return new WP_Error( 'wgdp_entitlement_not_found', 'Entitlement not found.' );
			}
			if ( 'revoked' === $entitlement['grant_status'] ) {
				return new WP_Error( 'wgdp_entitlement_revoked', 'This access has been revoked.' );
			}
			if ( 'granted' === $entitlement['grant_status'] && ! empty( $entitlement['provider_permission_id'] ) ) {
				// Already granted by a concurrent caller (or a prior run) — distinct
				// from `true` (freshly granted just now) so batch/cron callers that
				// collect grants for a summary email don't send a duplicate one.
				return null;
			}

			$drive = WGDP_Google_Drive::instance();

			// Dedup check: see if another entitlement for the same email+asset+account already has a permission.
			$existing = $ent->get_by_email_and_asset(
				$entitlement['recipient_email'],
				$entitlement['cloud_asset_id'],
				$entitlement['account_id']
			);
			if ( $existing && ! empty( $existing['provider_permission_id'] ) && (int) $existing['id'] !== (int) $entitlement['id'] ) {
				// Hold the sibling's own lock across the read-check-write so a concurrent
				// revoke_with_drive_delete() on that sibling can't remove the permission
				// between get_permission() and mark_granted() below. Returns null when the
				// dedup didn't apply, meaning we should fall through and create a fresh
				// permission below.
				$dedup_result = $ent->with_entitlement_lock( (int) $existing['id'], function () use ( $ent, $drive, $entitlement, $existing, $suppress_email ) {
					$existing_permission = $drive->get_permission(
						$entitlement['cloud_asset_id'],
						$existing['provider_permission_id'],
						$entitlement['account_id']
					);

					if ( is_wp_error( $existing_permission ) ) {
						if ( 'wgdp_permission_not_found' !== $existing_permission->get_error_code() ) {
							return $existing_permission;
						}
						$ent->mark_error( $existing['id'], 'Permission no longer exists on Google Drive.' );
						return null;
					}

					$permission_email = strtolower( trim( $existing_permission['emailAddress'] ?? '' ) );
					$recipient_email  = strtolower( trim( $entitlement['recipient_email'] ) );
					if ( '' === $permission_email || $permission_email !== $recipient_email ) {
						return null;
					}

					$marked = $ent->mark_granted( $entitlement['id'], $existing['provider_permission_id'] );
					if ( empty( $marked ) ) {
						// Row vanished or was concurrently revoked; do not report
						// success for a permission we did not durably record.
						return new WP_Error( 'wgdp_grant_not_recorded', 'Could not record the granted permission.' );
					}

					if ( ! $suppress_email ) {
						$resource_type = WGDP_Entitlements::get_resource_type( $entitlement );
						$drive_link    = WGDP_Google_Drive::build_web_link( $entitlement['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
						$product_name  = WGDP_Entitlements::get_product_name( $entitlement, 'your purchase' );
						WGDP_Notification_Email::send_access_granted( $entitlement['recipient_email'], $drive_link, $product_name, $resource_type );
						$billing = WGDP_Notification_Email::get_billing_email_if_different( $entitlement['order_id'], $entitlement['recipient_email'] );
						if ( $billing ) {
							WGDP_Notification_Email::send_access_granted( $billing, $drive_link, $product_name, $resource_type );
						}
					}

					return true;
				} );

				if ( null !== $dedup_result ) {
					return $dedup_result;
				}
			}

			// Create permission on Drive.
			$result = $drive->create_permission(
				$entitlement['cloud_asset_id'],
				$entitlement['recipient_email'],
				null,
				$entitlement['account_id']
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$permission_id = $result['id'] ?? '';
			$marked        = $ent->mark_granted( $entitlement['id'], $permission_id );
			if ( empty( $marked ) ) {
				// The Drive permission was created but the row could not be marked
				// granted (vanished or concurrently revoked). Surface an error
				// rather than reporting success for an untracked live permission.
				return new WP_Error( 'wgdp_grant_not_recorded', 'Could not record the granted permission.' );
			}

			if ( ! $suppress_email ) {
				$resource_type = WGDP_Entitlements::get_resource_type( $entitlement );
				$drive_link    = WGDP_Google_Drive::build_web_link( $entitlement['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
				$product_name  = WGDP_Entitlements::get_product_name( $entitlement, 'your purchase' );
				WGDP_Notification_Email::send_access_granted( $entitlement['recipient_email'], $drive_link, $product_name, $resource_type );
				$billing = WGDP_Notification_Email::get_billing_email_if_different( $entitlement['order_id'], $entitlement['recipient_email'] );
				if ( $billing ) {
					WGDP_Notification_Email::send_access_granted( $billing, $drive_link, $product_name, $resource_type );
				}
			}

			return true;
		} );
	}

	/**
	 * Handle "Resend Code" POST action on the claim page.
	 */
	private function handle_resend_code( $token, $entitlement, $otp_service, $ent ) {
		if ( 'verified' === $entitlement['verification_status'] ) {
			$this->post_result = $this->wrap_content( $this->error_content( 'This access has already been verified.' ) );
			return;
		}
		if ( 'revoked' === $entitlement['grant_status'] ) {
			$this->post_result = $this->wrap_content( $this->error_content( 'This access has been revoked.' ) );
			return;
		}

		// Key the bucket on the recipient group (order_item_id + recipient_email), not the
		// per-entitlement id: issue_otp_for_recipient_group() re-mails every sibling token, so
		// keying per-entitlement would grant a holder of N siblings N independent 3/hr buckets.
		$rate_key = 'wgdp_claim_resend_' . (int) $entitlement['order_item_id'] . '_' . strtolower( (string) $entitlement['recipient_email'] );
		if ( ! $this->consume_rate_limit( $rate_key, 3, HOUR_IN_SECONDS ) ) {
			$this->post_result = $this->wrap_content( $this->form_content(
				$token,
				'Too many code requests. Please wait before requesting another code.',
				$entitlement
			) );
			return;
		}

		$tokens = $ent->issue_otp_for_recipient_group( $entitlement['id'] );
		if ( is_wp_error( $tokens ) ) {
			$this->post_result = $this->wrap_content( $this->form_content(
				$token,
				$tokens->get_error_message(),
				$entitlement
			) );
			return;
		}

			$order = wc_get_order( $entitlement['order_id'] );
			$item  = $order ? $order->get_item( $entitlement['order_item_id'] ) : null;
			if ( $order && $item ) {
				$mail_result = WGDP_Notification_Email::send_otp( $entitlement['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
				if ( is_wp_error( $mail_result ) ) {
					$this->post_result = $this->wrap_content( $this->form_content(
						$token,
						'Could not send a new verification code. Please contact the store for assistance.',
						$entitlement
					) );
					return;
				}
			}

		$refreshed = $ent->get( $entitlement['id'] );
		$this->post_result = $this->wrap_content( $this->form_content(
			$tokens['claim_token'],
			'',
			$refreshed,
			'A new verification code has been sent to your email.'
		) );
	}

	/**
	 * Render pending release content for the claim page.
	 */
	private function pending_release_content() {
		return '<div style="text-align:center;">'
			. '<div style="font-size:48px;margin-bottom:12px;">&#9989;</div>'
			. '<h2 style="color:#2271b1;margin:0 0 12px;">Verified!</h2>'
			. '<p style="color:#f5f7fb;font-size:15px;">Digital access is not yet available. You\'ll receive an email when it\'s ready.</p>'
			. '</div>';
	}

	/**
	 * Render the OTP input form.
	 */
	private function form_content( $token, $error = '', $entitlement = null, $success_message = '' ) {
		$product_name = $entitlement ? WGDP_Entitlements::get_product_name( $entitlement, 'your purchase' ) : '';

		// Detect if OTP is expired (but claim token still valid).
		$otp_expired = false;
		if ( $entitlement && ! empty( $entitlement['otp_expires_at'] ) && strtotime( $entitlement['otp_expires_at'] . ' +0000' ) < time() ) {
			$otp_expired = true;
		}

		// Detect if max attempts exceeded.
		$max_attempts = false;
		if ( $entitlement && (int) ( $entitlement['otp_attempts'] ?? 0 ) >= WGDP_OTP::MAX_OTP_ATTEMPTS ) {
			$max_attempts = true;
		}

			$html = '<h2 style="color:#fff;margin:0 0 8px;text-align:center;">Verify Your Access</h2>';

		// Show order and product details.
		if ( $entitlement ) {
			$order_id = $entitlement['order_id'];

			// Get the base product name (without variation suffixes).
			$base_product_name = $entitlement['product_id'] ? get_the_title( $entitlement['product_id'] ) : $product_name;

			// Build variation label from the order item meta.
			$variation_label = '';
			if ( ! empty( $entitlement['variation_id'] ) ) {
				$order = wc_get_order( $order_id );
				$item  = $order ? $order->get_item( $entitlement['order_item_id'] ) : null;
				if ( $item instanceof WC_Order_Item_Product ) {
					$attrs = $item->get_formatted_meta_data( '_', true );
					$parts = array();
					foreach ( $attrs as $attr ) {
						$parts[] = wp_strip_all_tags( $attr->display_key . ': ' . $attr->display_value );
					}
					if ( ! empty( $parts ) ) {
						$variation_label = implode( ', ', $parts );
					}
				}
			}

			$html .= '<div style="background:#f8f9fa;border:1px solid #e2e4e7;border-radius:6px;padding:12px 16px;margin:16px 0 24px;font-size:14px;">'
				. '<div style="margin-bottom:6px;"><span style="color:#888;">Order:</span> <strong style="color:#333;">#' . esc_html( $order_id ) . '</strong></div>'
				. '<div' . ( $variation_label ? ' style="margin-bottom:6px;"' : '' ) . '><span style="color:#888;">Product:</span> <strong style="color:#333;">' . esc_html( $base_product_name ) . '</strong></div>'
				. ( $variation_label ? '<div><span style="color:#888;">Variant:</span> <span style="color:#333;">' . esc_html( $variation_label ) . '</span></div>' : '' )
				. '</div>';
		}

		if ( $success_message ) {
			$html .= '<div style="background:#edfaef;border:1px solid #a7d7a9;border-radius:4px;padding:10px 16px;margin-bottom:16px;color:#00a32a;font-size:14px;">'
				. esc_html( $success_message ) . '</div>';
		}

		if ( $error ) {
			$html .= '<div style="background:#fcecec;border:1px solid #f5c6cb;border-radius:4px;padding:10px 16px;margin-bottom:16px;color:#d63638;font-size:14px;">'
				. esc_html( $error ) . '</div>';
		}

		// If OTP expired or max attempts reached, show resend form instead of OTP input.
		if ( $otp_expired || $max_attempts ) {
			$html .= '<form method="POST" action="">'
				. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '" />'
				. '<input type="hidden" name="wgdp_resend" value="1" />'
				. wp_nonce_field( 'wgdp_claim_verify', '_wpnonce', true, false )
					. '<p style="font-size:14px;color:#e5e7eb;text-align:center;">'
				. esc_html( $otp_expired ? 'Your verification code has expired.' : 'Too many attempts.' )
				. ' Click below to receive a new code.</p>'
				. '<div style="text-align:center;margin-top:20px;">'
				. '<button type="submit" style="background:#2271b1;color:#fff;border:none;padding:12px 40px;border-radius:4px;font-size:16px;font-weight:600;cursor:pointer;">Send New Code</button>'
				. '</div>'
				. '</form>';
			return $html;
		}

		$html .= '<form method="POST" action="">'
			. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '" />'
			. wp_nonce_field( 'wgdp_claim_verify', '_wpnonce', true, false )
				. '<p style="margin-bottom:8px;font-size:14px;color:#f5f7fb;">Enter the 6-digit verification code from your email:</p>'
			. '<div style="text-align:center;margin:16px 0;">'
			. '<input type="text" name="otp" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required '
			. 'style="font-size:28px;letter-spacing:8px;text-align:center;width:200px;padding:12px;border:2px solid #ddd;border-radius:8px;" '
			. 'placeholder="000000" />'
			. '</div>'
			. '<div style="text-align:center;margin-top:20px;">'
			. '<button type="submit" style="background:#2271b1;color:#fff;border:none;padding:12px 40px;border-radius:4px;font-size:16px;font-weight:600;cursor:pointer;">Verify</button>'
			. '</div>'
			. '</form>';

		return $html;
	}

	/**
	 * Render the success content.
	 */
	private function success_content( $drive_link, $entitlement ) {
		$product_name = WGDP_Entitlements::get_product_name( $entitlement, 'your purchase' );
		$email        = $entitlement['recipient_email'];

		$html = '<div style="text-align:center;">'
			. '<div style="font-size:48px;margin-bottom:12px;">&#10003;</div>'
			. '<h2 style="color:#00a32a;margin:0 0 12px;">Access Granted!</h2>'
				. '<p style="color:#f5f7fb;font-size:15px;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been granted.</p>'
				. '<p style="color:#e5e7eb;font-size:14px;">Sign in to Google as <strong>' . esc_html( $email ) . '</strong> to access your content.</p>'
			. '<div style="margin:24px 0;">'
			. '<a href="' . esc_url( $drive_link ) . '" target="_blank" rel="noopener" style="display:inline-block;background:#00a32a;color:#fff;text-decoration:none;padding:14px 40px;border-radius:4px;font-size:16px;font-weight:600;">Open in Google Drive</a>'
				. '<br><a href="' . esc_url( $drive_link ) . '" target="_blank" rel="noopener" style="color:#93c5fd;font-size:13px;word-break:break-all;">' . esc_html( $drive_link ) . '</a>'
			. '</div>'
			. '</div>';

		return $html;
	}

	/**
	 * Render success content for multiple files.
	 *
	 * @param array $granted_links Array of ['name' => string, 'link' => string].
	 * @param array $entitlement   The primary entitlement row.
	 * @param bool  $had_errors    Whether some files had errors.
	 */
	private function success_content_multi( $granted_links, $entitlement, $had_errors ) {
		$product_name = WGDP_Entitlements::get_product_name( $entitlement, 'your purchase' );
		$email        = $entitlement['recipient_email'];

		$html = '<div style="text-align:center;">'
			. '<div style="font-size:48px;margin-bottom:12px;">&#10003;</div>'
			. '<h2 style="color:#00a32a;margin:0 0 12px;">Access Granted!</h2>'
				. '<p style="color:#f5f7fb;font-size:15px;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been granted.</p>'
				. '<p style="color:#e5e7eb;font-size:14px;">Sign in to Google as <strong>' . esc_html( $email ) . '</strong> to access your content.</p>'
			. '</div>';

		if ( $had_errors ) {
			$html .= '<p style="color:#d63638;font-size:14px;text-align:center;">Some files encountered errors and will be retried automatically.</p>';
		}

		$html .= '<div style="margin:24px 0;">';
		foreach ( $granted_links as $gl ) {
			$html .= '<div style="margin-bottom:12px;">'
				. '<a href="' . esc_url( $gl['link'] ) . '" target="_blank" rel="noopener" style="display:inline-block;background:#00a32a;color:#fff;text-decoration:none;padding:10px 24px;border-radius:4px;font-size:14px;font-weight:600;">'
				. esc_html( $gl['name'] ) . '</a>'
				. '<br><a href="' . esc_url( $gl['link'] ) . '" target="_blank" rel="noopener" style="color:#93c5fd;font-size:13px;word-break:break-all;">' . esc_html( $gl['link'] ) . '</a>'
				. '</div>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render error content.
	 */
	private function error_content( $message ) {
		return '<div style="text-align:center;">'
				. '<div style="font-size:48px;margin-bottom:12px;">&#9888;</div>'
				. '<h2 style="color:#d63638;margin:0 0 12px;">Error</h2>'
				. '<p style="color:#f5f7fb;font-size:15px;">' . esc_html( $message ) . '</p>'
				. '</div>';
	}

	/**
	 * Wrap content in a styled container.
	 */
	private function wrap_content( $content ) {
		return '<div class="wgdp-claim-wrap" style="max-width:480px;margin:0 auto;padding:32px 0;color:#f5f7fb;">' . $content . '</div>';
	}

	/**
	 * Check if a resource is retired in product meta.
	 */
	private static function is_resource_retired( $product_id, $variation_id, $asset_id ) {
		$resources = WGDP_Product_Meta::get_drive_resources( $product_id, $variation_id );
		foreach ( $resources as $r ) {
			if ( $r['id'] === $asset_id ) {
				return ! empty( $r['status'] ) && 'active' !== $r['status'];
			}
		}
		// Not present in the product's resource set at all — treat as removed,
		// not as "still active", so a detached file is never (re-)granted.
		return true;
	}

	/**
	 * Get the Drive link for an entitlement.
	 */
	private function get_drive_link( $entitlement ) {
		$resource_type = WGDP_Entitlements::get_resource_type( $entitlement );
		$mime = $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '';
		return WGDP_Google_Drive::build_web_link( $entitlement['cloud_asset_id'], $mime );
	}

	/**
	 * Fixed-window counter for public claim-page actions.
	 */
	private function consume_rate_limit( $key, $limit, $window ) {
		return WGDP_DB::consume_rate_limit( $key, $limit, $window );
	}

}
