<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Self_Service {

	private static $instance = null;

	const LINK_EXPIRY_DAYS = 30;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_link' ), 20, 4 );
		add_action( 'init', array( $this, 'maybe_create_page' ) );
		add_action( 'template_redirect', array( $this, 'send_security_headers' ) );
		add_filter( 'the_content', array( $this, 'filter_page_content' ) );
		add_action( 'wp_ajax_wgdp_self_service_email', array( $this, 'ajax_self_service_email' ) );
		add_action( 'wp_ajax_nopriv_wgdp_self_service_email', array( $this, 'ajax_self_service_email' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_page_styles' ) );
	}

	/**
	 * Send Referrer-Policy header on the self-service page to prevent order key leakage.
	 */
	public function send_security_headers() {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( $page_id && is_page( $page_id ) ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Enqueue inline styles on the self-service page so text is readable
	 * regardless of theme heading/body color choices.
	 */
	public function enqueue_page_styles() {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}
		wp_add_inline_style( 'wp-block-library', '
			body.page-id-' . $page_id . ' .entry-title,
			body.page-id-' . $page_id . ' .wp-block-post-title { color: #333; }
		' );
	}

	/**
	 * Check whether the self-service link for an order has expired.
	 *
	 * @param WC_Order $order The order to check.
	 * @return bool True if expired.
	 */
	private function is_link_expired( $order ) {
		$order_date = $order->get_date_created();
		if ( ! $order_date ) {
			return false;
		}
		return ( time() - $order_date->getTimestamp() ) > self::LINK_EXPIRY_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Ensure the provide-email page exists (lightweight init-time check).
	 */
	public function maybe_create_page() {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return;
		}
		self::ensure_page_exists();
	}

	/**
	 * Create the provide-email page if it does not exist.
	 *
	 * @return int Page ID.
	 */
	public static function ensure_page_exists() {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			return $page_id;
		}

		$page_id = wp_insert_post( array(
			'post_title'     => 'Provide Google Email',
			'post_name'      => 'wgdp-provide-email',
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_content'   => '',
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'wgdp_provide_email_page_id', $page_id, false );
		}

		return $page_id;
	}

	/**
	 * Get the URL for the provide-email page.
	 *
	 * @return string Page URL.
	 */
	public static function get_page_url() {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( 'wgdp-provide-email' );
	}

	/**
	 * Get order items that have unassigned digital access slots.
	 *
	 * @param WC_Order $order The order to check.
	 * @return array Items with unassigned slots. Each entry has 'item', 'product_name', 'slots_remaining'.
	 */
	private function get_unassigned_items( $order ) {
		$unassigned = array();
		$ent = WGDP_Entitlements::instance();

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			$quantity        = $item->get_quantity();
			$qty_refunded    = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
			$effective_qty   = max( 0, $quantity - $qty_refunded );
			$active_count    = $ent->count_confirmed_recipients_for_item( $item->get_id() );

			if ( $effective_qty > 0 && $active_count < $effective_qty ) {
				$unassigned[] = array(
					'item'            => $item,
					'product_name'    => $item->get_name(),
					'order_item_id'   => $item->get_id(),
					'product_id'      => $product_id,
					'variation_id'    => $variation_id,
					'quantity'        => $effective_qty,
					'active_count'    => $active_count,
					'slots_remaining' => $effective_qty - $active_count,
				);
			}
		}

		return $unassigned;
	}

	/**
	 * Render a link in order emails for customers to provide their Google email.
	 *
	 * @param WC_Order    $order         The order object.
	 * @param bool        $sent_to_admin Whether the email is sent to an admin.
	 * @param bool        $plain_text    Whether the email is plain text.
	 * @param WC_Email    $email         The email object.
	 */
	public function render_email_link( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin ) {
			return;
		}

		$allowed_emails = array( 'customer_processing_order', 'customer_completed_order', 'customer_invoice' );
		if ( ! in_array( $email->id, $allowed_emails, true ) ) {
			return;
		}

		$unassigned = $this->get_unassigned_items( $order );
		if ( empty( $unassigned ) ) {
			return;
		}

		$url = add_query_arg( array(
			'order_id' => $order->get_id(),
			'key'      => $order->get_order_key(),
		), self::get_page_url() );

		// Determine release mode messaging.
		$has_min_sales = false;
		$has_manual    = false;
		foreach ( $unassigned as $ua ) {
			$mode = WGDP_Release_Gate::get_effective_release_mode( $ua['product_id'], $ua['variation_id'] ?? 0 );
			if ( 'min_sales_qty' === $mode ) {
				$has_min_sales = true;
			} elseif ( 'manual_release' === $mode ) {
				$has_manual = true;
			}
		}

		if ( $plain_text ) {
			echo "\n\n";
			echo "------------------------------------------------------------\n";
			echo "PROVIDE YOUR GOOGLE EMAIL FOR DIGITAL ACCESS\n";
			echo "------------------------------------------------------------\n\n";
			if ( $has_min_sales ) {
				echo "Your order includes digital content. Access will be granted once the product reaches its minimum sales goal.\n";
			} elseif ( $has_manual ) {
				echo "Your order includes digital content. Access will be granted once it becomes available.\n";
			} else {
				echo "Your order includes digital content that requires a Google account email to receive access.\n";
			}
			echo "Click the link below to provide your Google email:\n\n";
			echo esc_url( $url ) . "\n\n";
			return;
		}

		echo '<h2 style="color:#7f54b3;margin:18px 0 12px;">Provide Your Google Email for Digital Access</h2>';
		if ( $has_min_sales ) {
			echo '<p style="margin:0 0 12px;">If you would like digital access, provide your Google account email below. Access will be granted once the product reaches its minimum sales goal.</p>';
		} elseif ( $has_manual ) {
			echo '<p style="margin:0 0 12px;">If you would like digital access, provide your Google account email below. Access will be granted once it becomes available.</p>';
		} else {
			echo '<p style="margin:0 0 12px;">Your order includes digital content that requires a Google account email to receive access.</p>';
		}
		echo '<p style="margin:0 0 18px;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#7f54b3;color:#fff;text-decoration:none;padding:10px 24px;border-radius:4px;font-weight:600;">Provide Google Email</a></p>';
	}

	/**
	 * Filter the_content to display the self-service form on the plugin page.
	 */
	public function filter_page_content( $content ) {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( ! $page_id || ! is_page( $page_id ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id || empty( $order_key ) ) {
			return $this->wrap_content( $this->error_content( 'Invalid link. Missing order information.' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_order_key() !== $order_key ) {
			return $this->wrap_content( $this->error_content( 'Invalid link. Order not found or key mismatch.' ) );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return $this->wrap_content( $this->error_content( 'This order is no longer eligible for digital access.' ) );
		}

		if ( $this->is_link_expired( $order ) ) {
			return $this->wrap_content( $this->error_content( 'This link has expired. Please contact the store for assistance.' ) );
		}

		$unassigned = $this->get_unassigned_items( $order );
		if ( empty( $unassigned ) ) {
			return $this->wrap_content( $this->success_content( $order ) );
		}

		return $this->wrap_content( $this->form_content( $order, $unassigned ) );
	}

	/**
	 * AJAX handler: create entitlements for self-service email submissions.
	 */
	public function ajax_self_service_email() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wgdp_self_service' ) ) {
			wp_send_json_error( 'Security check failed.' );
		}

		$order_id  = absint( $_POST['order_id'] ?? 0 );
		$order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
		$items     = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual fields sanitized below.

		if ( ! $order_id || empty( $order_key ) ) {
			wp_send_json_error( 'Missing order information.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_order_key() !== $order_key ) {
			wp_send_json_error( 'Invalid order or key.' );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			wp_send_json_error( 'This order is no longer eligible for digital access.' );
		}

		if ( $this->is_link_expired( $order ) ) {
			wp_send_json_error( 'This link has expired. Please contact the store for assistance.' );
		}

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( 'No items submitted.' );
		}

		$ent  = WGDP_Entitlements::instance();
		$auth = WGDP_Google_Auth::instance();

		$created_count = 0;

		foreach ( $items as $submission ) {
			$order_item_id = absint( $submission['order_item_id'] ?? 0 );
			$email         = sanitize_email( $submission['email'] ?? '' );

			if ( ! $order_item_id || ! is_email( $email ) ) {
				continue;
			}

			$item = $order->get_item( $order_item_id );
			if ( ! $item ) {
				continue;
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			// Verify item qualifies.
			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			// Revoke any previous unverified entitlements so the customer can retry with a new email.
			$ent->revoke_unverified_for_item( $order_item_id );

			// Check slot availability (account for refunded qty); count distinct confirmed recipients.
			$quantity      = $item->get_quantity();
			$qty_refunded  = abs( (int) $order->get_qty_refunded_for_item( $order_item_id ) );
			$effective_qty = max( 0, $quantity - $qty_refunded );
			$active_count  = $ent->count_confirmed_recipients_for_item( $order_item_id );
			if ( $effective_qty <= 0 || $active_count >= $effective_qty ) {
				continue;
			}

			// Resolve active resources (multi-file, excludes retired).
			$resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
			if ( empty( $resources ) ) {
				continue;
			}

			// Resolve account.
			$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );
			if ( empty( $account_id ) || ! $auth->is_account_connected( $account_id ) ) {
				continue;
			}

			$result = $ent->create_entitlements_for_recipient( array(
				'order_id'       => $order_id,
				'order_item_id'  => $order_item_id,
				'product_id'     => $product_id,
				'variation_id'   => $variation_id ?: 0,
				'email'          => $email,
				'account_id'     => $account_id,
				'resources'      => $resources,
			) );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			WGDP_Notification_Email::send_otp( $email, $result['tokens']['otp'], $result['tokens']['claim_token'], $order, $item );

			// Set drive items flag if not already set.
			if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
				$order->update_meta_data( '_wgdp_has_drive_items', '1' );
				$order->save();
			}

			$order->add_order_note( sprintf(
				'WGDP: Verification email sent to %s for "%s" (entitlement #%d) — self-service',
				$email,
				$item->get_name(),
				$result['primary_id']
			) );

			$created_count++;
		}

		if ( $created_count > 0 ) {
			delete_transient( 'wgdp_permission_counts' );
			wp_send_json_success( array(
				'message' => sprintf(
					'%d verification email(s) sent.',
					$created_count
				),
				'detail'  => 'Check your inbox and click the verification link in the email to complete your access.',
				'count'   => $created_count,
			) );
		} else {
			wp_send_json_error( 'No entitlements were created. Slots may already be filled or emails were invalid.' );
		}
	}

	/**
	 * Wrap content in a styled container.
	 */
	private function wrap_content( $content ) {
		return '<div class="wgdp-provide-email-wrap" style="max-width:560px;margin:0 auto;padding:32px 0;">' . $content . '</div>';
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
	 * Render success/info content showing assigned emails.
	 *
	 * @param WC_Order $order The order.
	 */
	private function success_content( $order ) {
		$ent  = WGDP_Entitlements::instance();
		$rows = $ent->get_by_order( $order->get_id() );

		$html = '<div style="text-align:center;">'
			. '<div style="font-size:48px;margin-bottom:12px;">&#10003;</div>'
			. '<h2 style="color:#00a32a;margin:0 0 12px;">All Set</h2>'
			. '<p style="color:#555;font-size:15px;">All digital access slots are already assigned.</p>'
			. '</div>';

		// Build list of assigned emails grouped by product.
		$assigned = array();
		foreach ( $rows as $row ) {
			if ( 'revoked' === $row['grant_status'] || empty( $row['recipient_email'] ) ) {
				continue;
			}
			$item = $order->get_item( $row['order_item_id'] );
			$name = $item ? $item->get_name() : 'Unknown product';
			$assigned[] = array(
				'product' => $name,
				'email'   => $row['recipient_email'],
				'status'  => $row['grant_status'],
			);
		}

		if ( ! empty( $assigned ) ) {
			$html .= '<div style="margin-top:24px;text-align:left;border-top:1px solid #eee;padding-top:20px;">'
				. '<p style="color:#666;font-size:13px;font-weight:600;margin:0 0 12px;text-transform:uppercase;letter-spacing:0.5px;">Assigned Access</p>';
			foreach ( $assigned as $a ) {
				$status_label = '';
				if ( 'granted' === $a['status'] ) {
					$status_label = '<span style="color:#00a32a;font-size:12px;"> &#10003; Granted</span>';
				} elseif ( 'pending' === $a['status'] || 'pending_release' === $a['status'] ) {
					$status_label = '<span style="color:#dba617;font-size:12px;"> &#9679; Pending</span>';
				}
				$html .= '<div style="padding:8px 0;border-bottom:1px solid #f4f4f4;">'
					. '<div style="font-size:14px;font-weight:600;color:#333;">' . esc_html( $a['product'] ) . $status_label . '</div>'
					. '<div style="font-size:14px;color:#666;font-family:monospace;">' . esc_html( self::mask_email( $a['email'] ) ) . '</div>'
					. '</div>';
			}
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * Mask an email address for display.
	 *
	 * Example: radial@gmail.com → ra***l@gm**l.com
	 *
	 * @param string $email The email to mask.
	 * @return string Masked email.
	 */
	private static function mask_email( $email ) {
		$parts = explode( '@', $email, 2 );
		if ( count( $parts ) !== 2 ) {
			return '***';
		}

		$local  = $parts[0];
		$domain = $parts[1];

		// Mask local part: show first 2 and last 1, mask the rest.
		$local_len = strlen( $local );
		if ( $local_len <= 2 ) {
			$masked_local = $local[0] . str_repeat( '*', max( 1, $local_len - 1 ) );
		} else {
			$masked_local = substr( $local, 0, 2 ) . str_repeat( '*', $local_len - 3 ) . substr( $local, -1 );
		}

		// Mask domain: show first 2 and last part after last dot.
		$dot_pos = strrpos( $domain, '.' );
		if ( false === $dot_pos || $dot_pos < 2 ) {
			$masked_domain = $domain[0] . str_repeat( '*', max( 1, strlen( $domain ) - 1 ) );
		} else {
			$domain_name = substr( $domain, 0, $dot_pos );
			$tld         = substr( $domain, $dot_pos ); // includes the dot.
			$dn_len      = strlen( $domain_name );
			if ( $dn_len <= 2 ) {
				$masked_domain = $domain_name[0] . str_repeat( '*', max( 1, $dn_len - 1 ) ) . $tld;
			} else {
				$masked_domain = substr( $domain_name, 0, 2 ) . str_repeat( '*', $dn_len - 3 ) . substr( $domain_name, -1 ) . $tld;
			}
		}

		return $masked_local . '@' . $masked_domain;
	}

	/**
	 * Render the self-service form.
	 *
	 * @param WC_Order $order      The order.
	 * @param array    $unassigned Unassigned item data from get_unassigned_items().
	 * @return string HTML content.
	 */
	private function form_content( $order, $unassigned ) {
		$nonce = wp_create_nonce( 'wgdp_self_service' );

		// Determine release mode messaging.
		$has_min_sales = false;
		$has_manual    = false;
		foreach ( $unassigned as $ua ) {
			$mode = WGDP_Release_Gate::get_effective_release_mode( $ua['product_id'], $ua['variation_id'] ?? 0 );
			if ( 'min_sales_qty' === $mode ) {
				$has_min_sales = true;
			} elseif ( 'manual_release' === $mode ) {
				$has_manual = true;
			}
		}

		$html = '<h2 style="color:#333;margin:0 0 8px;text-align:center;">Provide Your Google Email</h2>'
			. '<p style="color:#666;text-align:center;margin-bottom:4px;">Order #' . esc_html( $order->get_order_number() ) . '</p>';

		if ( $has_min_sales ) {
			$html .= '<p style="color:#666;text-align:center;margin-bottom:24px;font-size:14px;">Access will be granted once the product reaches its minimum sales goal.</p>';
		} elseif ( $has_manual ) {
			$html .= '<p style="color:#666;text-align:center;margin-bottom:24px;font-size:14px;">Access will be granted once it becomes available.</p>';
		} else {
			$html .= '<p style="color:#666;text-align:center;margin-bottom:24px;font-size:14px;">Enter the Google account email for each item to receive digital access.</p>';
		}

		$html .= '<div id="wgdp-ss-message" style="display:none;border-radius:4px;padding:10px 16px;margin-bottom:16px;font-size:14px;"></div>';

		$html .= '<form id="wgdp-ss-form">';
		$html .= '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
		$html .= '<input type="hidden" name="order_key" value="' . esc_attr( $order->get_order_key() ) . '" />';
		$html .= '<input type="hidden" name="nonce" value="' . esc_attr( $nonce ) . '" />';

		$field_index = 0;
		foreach ( $unassigned as $ua ) {
			for ( $i = 0; $i < $ua['slots_remaining']; $i++ ) {
				$label = esc_html( $ua['product_name'] );
				if ( $ua['slots_remaining'] > 1 ) {
					$label .= ' &mdash; Recipient ' . ( $i + 1 );
				}

				$html .= '<div style="margin-bottom:16px;">';
				$html .= '<label style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;color:#333;">' . $label . '</label>';
				$html .= '<input type="email" '
					. 'name="items[' . $field_index . '][email]" '
					. 'data-order-item-id="' . esc_attr( $ua['order_item_id'] ) . '" '
					. 'placeholder="Google account email" '
					. 'style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:15px;box-sizing:border-box;" />';
				$html .= '<input type="hidden" name="items[' . $field_index . '][order_item_id]" value="' . esc_attr( $ua['order_item_id'] ) . '" />';
				$html .= '</div>';
				$field_index++;
			}
		}

		$html .= '<div style="text-align:center;margin-top:24px;">';
		$html .= '<button type="submit" id="wgdp-ss-submit" style="background:#2271b1;color:#fff;border:none;padding:12px 40px;border-radius:4px;font-size:16px;font-weight:600;cursor:pointer;">Submit</button>';
		$html .= '</div>';
		$html .= '</form>';

		$ajax_url = admin_url( 'admin-ajax.php' );

		$html .= '<script>
(function() {
	var form = document.getElementById("wgdp-ss-form");
	var btn = document.getElementById("wgdp-ss-submit");
	var msg = document.getElementById("wgdp-ss-message");

	form.addEventListener("submit", function(e) {
		e.preventDefault();

		var items = [];
		var inputs = form.querySelectorAll("input[type=email]");
		var hasEmail = false;
		for (var i = 0; i < inputs.length; i++) {
			var email = inputs[i].value.trim();
			if (email) {
				hasEmail = true;
				items.push({
					order_item_id: inputs[i].getAttribute("data-order-item-id"),
					email: email
				});
			}
		}

		if (!hasEmail) {
			msg.style.display = "block";
			msg.style.background = "#fcecec";
			msg.style.border = "1px solid #f5c6cb";
			msg.style.color = "#d63638";
			msg.textContent = "Please enter at least one email address.";
			return;
		}

		btn.disabled = true;
		btn.textContent = "Submitting...";
		msg.style.display = "none";

		var fd = new FormData();
		fd.append("action", "wgdp_self_service_email");
		fd.append("order_id", form.querySelector("input[name=order_id]").value);
		fd.append("order_key", form.querySelector("input[name=order_key]").value);
		fd.append("nonce", form.querySelector("input[name=nonce]").value);
		for (var j = 0; j < items.length; j++) {
			fd.append("items[" + j + "][order_item_id]", items[j].order_item_id);
			fd.append("items[" + j + "][email]", items[j].email);
		}

		fetch(' . wp_json_encode( $ajax_url ) . ', {
			method: "POST",
			body: fd
		})
		.then(function(r) { return r.json(); })
		.then(function(resp) {
			msg.style.display = "block";
			if (resp.success) {
				msg.style.background = "#edfaef";
				msg.style.border = "1px solid #a7d7a9";
				msg.style.color = "#00a32a";
				msg.textContent = "";
				var strong = document.createElement("strong");
				strong.textContent = resp.data.message;
				msg.appendChild(strong);
				if (resp.data.detail) {
					msg.appendChild(document.createElement("br"));
					msg.appendChild(document.createTextNode(resp.data.detail));
				}
				form.style.display = "none";
			} else {
				msg.style.background = "#fcecec";
				msg.style.border = "1px solid #f5c6cb";
				msg.style.color = "#d63638";
				msg.textContent = resp.data || "An error occurred.";
				btn.disabled = false;
				btn.textContent = "Submit";
			}
		})
		.catch(function() {
			msg.style.display = "block";
			msg.style.background = "#fcecec";
			msg.style.border = "1px solid #f5c6cb";
			msg.style.color = "#d63638";
			msg.textContent = "Network error. Please try again.";
			btn.disabled = false;
			btn.textContent = "Submit";
		});
	});
})();
</script>';

		return $html;
	}
}
