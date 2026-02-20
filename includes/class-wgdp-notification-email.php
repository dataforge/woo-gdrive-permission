<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Notification_Email {

	/**
	 * Send OTP verification email to a recipient.
	 */
	public static function send_otp( $email, $otp, $claim_token, $order, $item ) {
		$product_name = $item->get_name();
		$order_id     = $order->get_id();
		$claim_url    = add_query_arg( 't', urlencode( $claim_token ), WGDP_Claim_Page::get_page_url() );
		$site_name    = get_bloginfo( 'name' );

		$subject = sprintf( 'Verify your access to %s', $product_name );

		$content = '<h2 style="color:#333;margin:0 0 16px;">Verify Your Access</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">You have been granted access to <strong>' . esc_html( $product_name ) . '</strong> from order #' . esc_html( $order_id ) . '.</p>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Use the following verification code to claim your access:</p>'
			. '<div style="text-align:center;margin:24px 0;">'
			. '<div style="display:inline-block;background:#f4f4f4;border:2px solid #ddd;border-radius:8px;padding:16px 32px;font-size:32px;font-weight:700;letter-spacing:8px;color:#333;">' . esc_html( $otp ) . '</div>'
			. '</div>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">This code expires in ' . WGDP_OTP::OTP_EXPIRY_MINUTES . ' minutes.</p>'
			. '<div style="text-align:center;margin:24px 0;">'
			. '<a href="' . esc_url( $claim_url ) . '" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:12px 32px;border-radius:4px;font-size:16px;font-weight:600;">Claim Your Access</a>'
			. '</div>'
			. '<p style="color:#888;font-size:13px;">Or copy this link into your browser: ' . esc_html( $claim_url ) . '</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		self::send( $email, $subject, $html );
	}

	/**
	 * Send access-granted confirmation email.
	 */
	public static function send_access_granted( $email, $drive_link, $product_name, $resource_type ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( 'Your access to %s is ready', $product_name );

		$type_label = 'folder' === $resource_type ? 'folder' : 'file';

		$content = '<h2 style="color:#333;margin:0 0 16px;">Access Granted!</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been granted.</p>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Click the button below to open the ' . esc_html( $type_label ) . ' in Google Drive. Make sure you are signed in to Google with this email address (<strong>' . esc_html( $email ) . '</strong>).</p>'
			. '<div style="text-align:center;margin:24px 0;">'
			. '<a href="' . esc_url( $drive_link ) . '" style="display:inline-block;background:#00a32a;color:#fff;text-decoration:none;padding:12px 32px;border-radius:4px;font-size:16px;font-weight:600;">Open in Google Drive</a>'
			. '</div>'
			. '<p style="color:#888;font-size:13px;">Direct link: ' . esc_html( $drive_link ) . '</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		self::send( $email, $subject, $html );
	}

	/**
	 * Send access-revoked notification email.
	 */
	public static function send_access_revoked( $email, $product_name ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( 'Access to %s has been revoked', $product_name );

		$content = '<h2 style="color:#333;margin:0 0 16px;">Access Revoked</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been revoked.</p>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">If you believe this is an error, please contact the store.</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		self::send( $email, $subject, $html );
	}

	/**
	 * Wrap content in a responsive HTML email template.
	 */
	public static function get_html_wrapper( $content, $site_name = '' ) {
		if ( empty( $site_name ) ) {
			$site_name = get_bloginfo( 'name' );
		}

		return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f4;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,sans-serif;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:32px 16px;">'
			. '<tr><td align="center">'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);">'
			. '<tr><td style="background:#2271b1;padding:20px 32px;">'
			. '<h1 style="color:#fff;margin:0;font-size:18px;font-weight:600;">' . esc_html( $site_name ) . '</h1>'
			. '</td></tr>'
			. '<tr><td style="padding:32px;">'
			. $content
			. '</td></tr>'
			. '<tr><td style="padding:16px 32px;background:#f9f9f9;border-top:1px solid #eee;">'
			. '<p style="color:#999;font-size:12px;margin:0;text-align:center;">This email was sent by ' . esc_html( $site_name ) . '</p>'
			. '</td></tr>'
			. '</table>'
			. '</td></tr>'
			. '</table>'
			. '</body></html>';
	}

	/**
	 * Send an HTML email via wp_mail.
	 */
	private static function send( $to, $subject, $html ) {
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		try {
			wp_mail( $to, $subject, $html );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		}
	}

	/**
	 * Return HTML content type for wp_mail.
	 */
	public static function html_content_type() {
		return 'text/html';
	}
}
