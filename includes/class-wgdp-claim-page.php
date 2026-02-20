<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Claim_Page {

	private static $instance = null;

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
			$product_id = (int) $result['entitlement']['product_id'];

			if ( ! WGDP_Release_Gate::is_product_released( $product_id ) ) {
				$ent->mark_pending_release( $result['entitlement']['id'] );
				$this->post_result = $this->wrap_content( $this->pending_release_content() );
				return;
			}

			$grant_result = self::grant_drive_access_for_entitlement( $result['entitlement'] );

			if ( is_wp_error( $grant_result ) ) {
				$ent->mark_error( $result['entitlement']['id'], $grant_result->get_error_message() );
				$this->post_result = $this->wrap_content( $this->error_content(
					'Your identity has been verified, but we encountered an error granting access. We will retry automatically. Please check back later.'
				) );
			} else {
				$refreshed = $ent->get( $result['entitlement']['id'] );
				$drive_link = $this->get_drive_link( $refreshed );
				$this->post_result = $this->wrap_content( $this->success_content( $drive_link, $refreshed ) );
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
	 */
	public static function grant_drive_access_for_entitlement( $entitlement ) {
		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();

		// Dedup check: see if another entitlement for the same email+asset already has a permission.
		$existing = $ent->get_by_email_and_asset( $entitlement['recipient_email'], $entitlement['cloud_asset_id'] );
		if ( $existing && ! empty( $existing['provider_permission_id'] ) && (int) $existing['id'] !== (int) $entitlement['id'] ) {
			$ent->mark_granted( $entitlement['id'], $existing['provider_permission_id'] );

			$resource_type = self::resolve_resource_type( $entitlement );
			$drive_link    = WGDP_Google_Drive::build_web_link( $entitlement['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
			$product_name  = self::resolve_product_name( $entitlement );
			WGDP_Notification_Email::send_access_granted( $entitlement['recipient_email'], $drive_link, $product_name, $resource_type );

			return true;
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
		$ent->mark_granted( $entitlement['id'], $permission_id );

		$resource_type = self::resolve_resource_type( $entitlement );
		$drive_link    = WGDP_Google_Drive::build_web_link( $entitlement['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
		$product_name  = self::resolve_product_name( $entitlement );
		WGDP_Notification_Email::send_access_granted( $entitlement['recipient_email'], $drive_link, $product_name, $resource_type );

		return true;
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

		$tokens = $otp_service->issue_otp_for_entitlement( $entitlement['id'] );

		$order = wc_get_order( $entitlement['order_id'] );
		$item  = $order ? $order->get_item( $entitlement['order_item_id'] ) : null;
		if ( $order && $item ) {
			WGDP_Notification_Email::send_otp( $entitlement['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
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
			. '<p style="color:#555;font-size:15px;">Digital access is not yet available. You\'ll receive an email when it\'s ready.</p>'
			. '</div>';
	}

	/**
	 * Render the OTP input form.
	 */
	private function form_content( $token, $error = '', $entitlement = null, $success_message = '' ) {
		$product_name = $entitlement ? $this->get_product_name( $entitlement ) : '';

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

		$html = '<h2 style="color:#333;margin:0 0 8px;text-align:center;">Verify Your Access</h2>';
		if ( $product_name ) {
			$html .= '<p style="color:#666;text-align:center;margin-bottom:24px;">for <strong>' . esc_html( $product_name ) . '</strong></p>';
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
				. '<p style="font-size:14px;color:#555;text-align:center;">'
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
			. '<p style="margin-bottom:8px;font-size:14px;color:#555;">Enter the 6-digit verification code from your email:</p>'
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
		$product_name = $this->get_product_name( $entitlement );
		$email        = $entitlement['recipient_email'];

		$html = '<div style="text-align:center;">'
			. '<div style="font-size:48px;margin-bottom:12px;">&#10003;</div>'
			. '<h2 style="color:#00a32a;margin:0 0 12px;">Access Granted!</h2>'
			. '<p style="color:#555;font-size:15px;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been granted.</p>'
			. '<p style="color:#555;font-size:14px;">Sign in to Google as <strong>' . esc_html( $email ) . '</strong> to access your content.</p>'
			. '<div style="margin:24px 0;">'
			. '<a href="' . esc_url( $drive_link ) . '" style="display:inline-block;background:#00a32a;color:#fff;text-decoration:none;padding:14px 40px;border-radius:4px;font-size:16px;font-weight:600;">Open in Google Drive</a>'
			. '</div>'
			. '</div>';

		return $html;
	}

	/**
	 * Render error content.
	 */
	private function error_content( $message ) {
		return '<div style="text-align:center;">'
			. '<div style="font-size:48px;margin-bottom:12px;">&#9888;</div>'
			. '<h2 style="color:#d63638;margin:0 0 12px;">Error</h2>'
			. '<p style="color:#555;font-size:15px;">' . esc_html( $message ) . '</p>'
			. '</div>';
	}

	/**
	 * Wrap content in a styled container.
	 */
	private function wrap_content( $content ) {
		return '<div class="wgdp-claim-wrap" style="max-width:480px;margin:0 auto;padding:32px 0;">' . $content . '</div>';
	}

	/**
	 * Get the Drive link for an entitlement.
	 */
	private function get_drive_link( $entitlement ) {
		$resource_type = $this->get_resource_type( $entitlement );
		$mime = $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '';
		return WGDP_Google_Drive::build_web_link( $entitlement['cloud_asset_id'], $mime );
	}

	/**
	 * Get resource type from product/variation meta.
	 */
	private function get_resource_type( $entitlement ) {
		return self::resolve_resource_type( $entitlement );
	}

	/**
	 * Get resource type (static version for use by grant_drive_access_for_entitlement).
	 */
	private static function resolve_resource_type( $entitlement ) {
		$check_id = $entitlement['variation_id'] ?: $entitlement['product_id'];
		$type = get_post_meta( $check_id, '_wgdp_drive_resource_type', true );
		if ( empty( $type ) ) {
			$type = get_post_meta( $entitlement['product_id'], '_wgdp_drive_resource_type', true );
		}
		return $type ?: 'file';
	}

	/**
	 * Get product name from entitlement data.
	 */
	private function get_product_name( $entitlement ) {
		return self::resolve_product_name( $entitlement );
	}

	/**
	 * Get product name (static version for use by grant_drive_access_for_entitlement).
	 */
	private static function resolve_product_name( $entitlement ) {
		$id = $entitlement['variation_id'] ?: $entitlement['product_id'];
		$product = wc_get_product( $id );
		return $product ? $product->get_name() : 'your purchase';
	}
}
