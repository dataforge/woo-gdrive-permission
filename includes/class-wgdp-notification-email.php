<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Notification_Email {

	/**
	 * Send OTP verification email to a recipient.
	 */
	public static function send_otp( $email, $otp, $claim_token, $order, $item ) {
		$product_name = $item->get_name();
		$order_id     = $order->get_id();
		$claim_url    = add_query_arg( 't', $claim_token, WGDP_Claim_Page::get_page_url() );
		$site_name    = get_bloginfo( 'name' );

		// Build product label with variation attributes if applicable.
		$product_label = $item->get_product_id() ? get_the_title( $item->get_product_id() ) : $product_name;
		$variation_label = '';
		if ( $item->get_variation_id() ) {
			$attrs = $item instanceof WC_Order_Item_Product ? $item->get_formatted_meta_data( '_', true ) : array();
			$parts = array();
			foreach ( $attrs as $attr ) {
				$parts[] = wp_strip_all_tags( $attr->display_key . ': ' . $attr->display_value );
			}
			if ( ! empty( $parts ) ) {
				$variation_label = implode( ', ', $parts );
			}
		}

		$subject = sprintf( 'Verify your access to %s', $product_name );

		$content = '<h2 style="color:#333;margin:0 0 16px;">Verify Your Access</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">You have been granted digital access as part of your order. Please verify your identity to claim it.</p>'
			. '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
			. '<tr><td style="padding:8px 12px;color:#888;white-space:nowrap;vertical-align:top;">Order</td>'
			. '<td style="padding:8px 12px;color:#333;font-weight:600;">#' . esc_html( $order_id ) . '</td></tr>'
			. '<tr><td style="padding:8px 12px;color:#888;white-space:nowrap;vertical-align:top;">Product</td>'
			. '<td style="padding:8px 12px;color:#333;font-weight:600;">' . esc_html( $product_label ) . '</td></tr>'
			. ( $variation_label ? '<tr><td style="padding:8px 12px;color:#888;white-space:nowrap;vertical-align:top;">Variant</td>'
			. '<td style="padding:8px 12px;color:#333;">' . esc_html( $variation_label ) . '</td></tr>' : '' )
			. '</table>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Use the following verification code to claim your access:</p>'
			. '<div style="text-align:center;margin:24px 0;">'
			. '<div style="display:inline-block;background:#f4f4f4;border:2px solid #ddd;border-radius:8px;padding:16px 32px;font-size:32px;font-weight:700;letter-spacing:8px;color:#333;">' . esc_html( $otp ) . '</div>'
			. '</div>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">This code expires in ' . WGDP_OTP::OTP_EXPIRY_MINUTES . ' minutes.</p>'
			. '<div style="text-align:center;margin:24px 0;">'
			. '<a href="' . esc_url( $claim_url ) . '" style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:12px 32px;border-radius:4px;font-size:16px;font-weight:600;">Claim Your Access</a>'
			. '</div>'
			. '<p style="color:#888;font-size:14px;">Or copy this link into your browser: ' . esc_html( $claim_url ) . '</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		return self::send( $email, $subject, $html );
	}

	/**
	 * Send access-granted confirmation email.
	 */
	public static function send_access_granted( $email, $drive_link, $product_name, $resource_type = 'file' ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( 'Your access to %s is ready', $product_name );

		$type_label = 'folder' === $resource_type ? 'folder' : 'file';

		$content = '<h2 style="color:#333;margin:0 0 16px;">Access Granted!</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been granted.</p>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Click the button below to open the ' . esc_html( $type_label ) . ' in Google Drive. Make sure you are signed in to Google with this email address (<strong>' . esc_html( $email ) . '</strong>).</p>'
			. '<div style="text-align:center;margin:24px 0;">'
			. '<a href="' . esc_url( $drive_link ) . '" style="display:inline-block;background:#00a32a;color:#fff;text-decoration:none;padding:12px 32px;border-radius:4px;font-size:16px;font-weight:600;">Open in Google Drive</a>'
			. '</div>'
			. '<p style="color:#888;font-size:14px;">Direct link: ' . esc_html( $drive_link ) . '</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		return self::send( $email, $subject, $html );
	}

	/**
	 * Send a single access-granted email listing multiple files.
	 *
	 * @param string $email        Recipient email.
	 * @param array  $file_links   Array of ['name' => string, 'link' => string].
	 * @param string $product_name Product name.
	 */
	public static function send_access_granted_batch( $email, $file_links, $product_name ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( 'Your access to %s is ready', $product_name );

		$content = '<h2 style="color:#333;margin:0 0 16px;">Access Granted!</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Your access to <strong>' . esc_html( $product_name ) . '</strong> has been granted.</p>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Make sure you are signed in to Google with this email address (<strong>' . esc_html( $email ) . '</strong>). Your files:</p>'
			. '<ul style="margin:16px 0;padding:0 0 0 20px;">';

		foreach ( $file_links as $fl ) {
			$name    = ( isset( $fl['name'] ) && '' !== $fl['name'] ) ? $fl['name'] : $fl['link'];
			$content .= '<li style="margin-bottom:8px;font-size:15px;">'
				. '<a href="' . esc_url( $fl['link'] ) . '" style="color:#2271b1;">' . esc_html( $name ) . '</a>'
				. '<br><span style="color:#888;font-size:14px;">' . esc_html( $fl['link'] ) . '</span>'
				. '</li>';
		}

		$content .= '</ul>';

		$html = self::get_html_wrapper( $content, $site_name );
		return self::send( $email, $subject, $html );
	}

	/**
	 * Send a "new files added" email for backfilled entitlements.
	 *
	 * @param string $email        Recipient email.
	 * @param array  $file_links   Array of ['name' => string, 'link' => string].
	 * @param string $product_name Product name.
	 * @param int    $order_id     Order ID.
	 */
	public static function send_new_files_added( $email, $file_links, $product_name, $order_id = 0 ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = $order_id
			? sprintf( 'New files added to %s for order #%d', $product_name, $order_id )
			: sprintf( 'New files added to %s', $product_name );

		$content = '<h2 style="color:#333;margin:0 0 16px;">New Files Added</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">New files have been added to <strong>' . esc_html( $product_name ) . '</strong> and shared with your account (<strong>' . esc_html( $email ) . '</strong>).</p>'
			. ( $order_id ? '<p style="color:#555;font-size:15px;line-height:1.6;">Order: <strong>#' . esc_html( $order_id ) . '</strong></p>' : '' )
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Make sure you are signed in to Google with this email address. Your new files:</p>'
			. '<ul style="margin:16px 0;padding:0 0 0 20px;">';

		foreach ( $file_links as $fl ) {
			$name    = ( isset( $fl['name'] ) && '' !== $fl['name'] ) ? $fl['name'] : $fl['link'];
			$content .= '<li style="margin-bottom:8px;font-size:15px;">'
				. '<a href="' . esc_url( $fl['link'] ) . '" style="color:#2271b1;">' . esc_html( $name ) . '</a>'
				. '<br><span style="color:#888;font-size:14px;">' . esc_html( $fl['link'] ) . '</span>'
				. '</li>';
		}

		$content .= '</ul>';

		$html = self::get_html_wrapper( $content, $site_name );
		return self::send( $email, $subject, $html );
	}

	/**
	 * Send access-revoked notification email.
	 */
	public static function send_access_revoked( $email, $product_name, $order_id = 0 ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( 'Access to %s has been revoked', $product_name );

		$content = '<h2 style="color:#333;margin:0 0 16px;">Access Revoked</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">Your access to <strong>' . esc_html( $product_name ) . '</strong>'
			. ( $order_id ? ' (Order #' . esc_html( $order_id ) . ')' : '' )
			. ' has been revoked.</p>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">If you believe this is an error, please contact the store.</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		return self::send( $email, $subject, $html );
	}

	/**
	 * Send notification that specific files have been revoked (e.g. file removed from product config).
	 */
	public static function send_file_access_revoked( $email, $product_name, $file_names, $order_id = 0 ) {
		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( 'File access update for %s', $product_name );

		$files_list = '<ul style="margin:12px 0;padding:0 0 0 20px;">';
		foreach ( $file_names as $name ) {
			$files_list .= '<li style="margin-bottom:4px;font-size:15px;">' . esc_html( $name ) . '</li>';
		}
		$files_list .= '</ul>';

		$content = '<h2 style="color:#333;margin:0 0 16px;">File Access Revoked</h2>'
			. '<p style="color:#555;font-size:15px;line-height:1.6;">The following file(s) from <strong>' . esc_html( $product_name ) . '</strong>'
			. ( $order_id ? ' (Order #' . esc_html( $order_id ) . ')' : '' )
			. ' are no longer available:</p>'
			. $files_list
			. '<p style="color:#555;font-size:15px;line-height:1.6;">If updated files are available, you will receive a new access notification. If you believe this is an error, please contact the store.</p>';

		$html = self::get_html_wrapper( $content, $site_name );

		return self::send( $email, $subject, $html );
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
	 * Get the billing email for an order if it differs from the recipient.
	 *
	 * @param int    $order_id       The order ID.
	 * @param string $recipient_email The Google account email.
	 * @return string|null The billing email if different, or null.
	 */
	public static function get_billing_email_if_different( $order_id, $recipient_email ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$billing = strtolower( trim( $order->get_billing_email() ) );
		if ( $billing && $billing !== strtolower( trim( $recipient_email ) ) ) {
			return $billing;
		}
		return null;
	}

	/**
	 * Send an HTML email via wp_mail.
	 */
	private static function send( $to, $subject, $html ) {
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		try {
			$sent = wp_mail( $to, $subject, $html );
		} finally {
			remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		}

		if ( ! $sent ) {
			return new WP_Error( 'wgdp_mail_failed', 'WordPress could not send the email.' );
		}

		return true;
	}

	/**
	 * Return HTML content type for wp_mail.
	 */
	public static function html_content_type() {
		return 'text/html';
	}
}
