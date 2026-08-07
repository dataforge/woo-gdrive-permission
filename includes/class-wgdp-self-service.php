<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Self_Service {

	private static $instance = null;

	const LINK_EXPIRY_DAYS   = 30;
	const TOKEN_META_KEY     = '_wgdp_self_service_tokens';
	// Kept short: this transient briefly holds the plaintext token (everywhere
	// else it exists only as a SHA-256 hash in order meta) so a burst of
	// overlapping status-transition emails for the same order can reuse one
	// token instead of minting a new one each time. Long enough to cover that
	// burst, short enough to keep the plaintext-at-rest window minimal.
	const TOKEN_REUSE_WINDOW = 2 * MINUTE_IN_SECONDS;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_filter( 'woocommerce_email_additional_content_customer_invoice', array( $this, 'append_recovery_link_to_email_content' ), 10, 3 );
		add_filter( 'woocommerce_email_additional_content_customer_processing_order', array( $this, 'append_recovery_link_to_email_content' ), 10, 3 );
		add_filter( 'woocommerce_email_additional_content_customer_completed_order', array( $this, 'append_recovery_link_to_email_content' ), 10, 3 );
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

			// The self-service form embeds a reusable bearer token/order key and
			// can display pending recipient emails; keep it out of page caches/CDNs.
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
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

		// Register our own handle rather than piggybacking on wp-block-library:
		// that handle isn't enqueued on themes/pages that don't use Gutenberg
		// blocks, which would silently drop this CSS.
		// The wrapper below carries its own dark background (see wrap_content()), so its
		// light text stays readable regardless of the surrounding theme's colors. We
		// deliberately don't touch .entry-title/.wp-block-post-title here — those render
		// on the theme's own background and already use whatever color the theme chose
		// for readability there.
		wp_register_style( 'wgdp-self-service-inline', false, array(), WGDP_VERSION );
		wp_enqueue_style( 'wgdp-self-service-inline' );
		wp_add_inline_style( 'wgdp-self-service-inline', '
			body.page-id-' . $page_id . ' .wgdp-provide-email-wrap input[type="email"] { background: #fff; color: #111827; }
		' );
	}

	/**
	 * Check whether the self-service link for an order has expired.
	 *
	 * @param WC_Order $order The order to check.
	 * @return bool True if expired.
	 */
	private function is_link_expired( $order ) {
		$link_resent_at = (int) $order->get_meta( '_wgdp_self_service_link_resent_at' );
		if ( $link_resent_at > 0 ) {
			return ( time() - $link_resent_at ) > self::LINK_EXPIRY_DAYS * DAY_IN_SECONDS;
		}

		$preorder_charged_at = (int) $order->get_meta( '_wcpr_charge_succeeded_at' );
		if ( $preorder_charged_at > 0 ) {
			return ( time() - $preorder_charged_at ) > self::LINK_EXPIRY_DAYS * DAY_IN_SECONDS;
		}

		$order_date = $order->get_date_created();
		if ( ! $order_date ) {
			return false;
		}
		return ( time() - $order_date->getTimestamp() ) > self::LINK_EXPIRY_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Get stored plugin-issued self-service token records for an order.
	 */
	private function get_token_records( $order ) {
		$records = $order->get_meta( self::TOKEN_META_KEY );
		if ( is_string( $records ) && '' !== $records ) {
			$records = json_decode( $records, true );
		}
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Save plugin-issued self-service token records for an order.
	 */
	private function save_token_records( $order, $records ) {
		$order->update_meta_data( self::TOKEN_META_KEY, wp_json_encode( array_values( $records ) ) );
		// Meta-only persist: issuing a token happens on the order-email render hot
		// path, and a full $order->save() would re-fire status-change hooks and
		// rewrite order data on every email send.
		$order->save_meta_data();
	}

	/**
	 * Filter out expired plugin-issued self-service token records.
	 *
	 * Read-only: does not persist. Callers that need the pruned result
	 * saved back to order meta must do so themselves (see
	 * issue_self_service_token(), which does this under a named lock).
	 */
	private function filter_active_token_records( $records ) {
		$now    = time();
		$active = array();

		foreach ( $records as $record ) {
			$expires = isset( $record['expires'] ) ? (int) $record['expires'] : 0;
			if ( $expires > 0 && $expires >= $now && ! empty( $record['hash'] ) ) {
				$active[] = $record;
			}
		}

		return $active;
	}

	/**
	 * Remove expired plugin-issued self-service token records and persist
	 * the result. Only safe to call while holding the per-order named lock
	 * (see issue_self_service_token()) — saving here outside that lock can
	 * race with a concurrent locked save and silently drop newly issued
	 * tokens.
	 */
	private function prune_token_records( $order, $records ) {
		$pruned = $this->filter_active_token_records( $records );

		if ( count( $pruned ) !== count( $records ) ) {
			$this->save_token_records( $order, $pruned );
		}

		return $pruned;
	}

	/**
	 * Issue a plugin-scoped self-service token without invalidating old tokens.
	 *
	 * Guarded by a named lock: this runs on the order-email hot path, where two
	 * concurrent issuances for the same order (e.g. overlapping status-change
	 * emails) could otherwise each read the same meta snapshot and the later
	 * save would silently drop the other's token.
	 *
	 * Repeated calls for the same order in quick succession (repeated "Resend
	 * Order Email" clicks, overlapping status-transition emails) reuse a
	 * short-lived cached token instead of minting a new one and paying the DB
	 * lock + meta write each time — the plaintext is kept only in a transient
	 * that expires well before the persisted token record does.
	 */
	private function issue_self_service_token( $order ) {
		$order_id    = $order->get_id();
		$reuse_key   = 'wgdp_sst_active_' . absint( $order_id );
		$reuse_token = get_transient( $reuse_key );
		if ( is_string( $reuse_token ) && '' !== $reuse_token && $this->validate_self_service_token( $order, $reuse_token ) ) {
			return $reuse_token;
		}

		$token    = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$hash     = hash( 'sha256', $token );

		$saved = WGDP_DB::with_named_lock(
			'wgdp_sst_' . absint( $order_id ),
			5,
			function() use ( $order_id, $hash ) {
				$fresh_order = wc_get_order( $order_id );
				if ( ! $fresh_order ) {
					return false;
				}

				$records   = $this->prune_token_records( $fresh_order, $this->get_token_records( $fresh_order ) );
				$records[] = array(
					'hash'    => $hash,
					'expires' => time() + self::LINK_EXPIRY_DAYS * DAY_IN_SECONDS,
					'created' => time(),
				);

				$this->save_token_records( $fresh_order, $records );
				return true;
			},
			false
		);

		// If the lock couldn't be acquired (or the fresh order vanished), the
		// hash was never persisted — returning $token here would hand the
		// customer a link that can never validate. Signal failure instead so
		// callers can fall back to the legacy order-key link.
		if ( ! $saved ) {
			return false;
		}

		set_transient( $reuse_key, $token, self::TOKEN_REUSE_WINDOW );

		return $token;
	}

	/**
	 * Check a plugin-scoped self-service token against an order.
	 */
	private function validate_self_service_token( $order, $token ) {
		if ( empty( $token ) ) {
			return false;
		}

		$hash    = hash( 'sha256', $token );
		$records = $this->filter_active_token_records( $this->get_token_records( $order ) );

		foreach ( $records as $record ) {
			if ( ! empty( $record['hash'] ) && hash_equals( $record['hash'], $hash ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the current preferred self-service URL.
	 */
	private function build_self_service_url( $order ) {
		$token = $this->issue_self_service_token( $order );

		if ( false === $token ) {
			// Token issuance failed (e.g. lock contention) — fall back to the
			// legacy order-key link so the customer still gets a working URL.
			return add_query_arg(
				array(
					'order_id' => $order->get_id(),
					'key'      => $order->get_order_key(),
				),
				self::get_page_url()
			);
		}

		return add_query_arg(
			array(
				'order_id' => $order->get_id(),
				'sst'      => $token,
			),
			self::get_page_url()
		);
	}

	/**
	 * Resolve a self-service request from either the new token or legacy order key.
	 *
	 * @param array $request Request source ($_GET or $_POST).
	 * @return array|WP_Error { order, auth_type, token, order_key } or WP_Error.
	 */
	private function resolve_request_order( $request ) {
		$token = '';
		if ( isset( $request['sst'] ) ) {
			$token = sanitize_text_field( wp_unslash( $request['sst'] ) );
		} elseif ( isset( $request['self_service_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $request['self_service_token'] ) );
		}

		if ( '' !== $token ) {
			$order_id = isset( $request['order_id'] ) ? absint( $request['order_id'] ) : 0;
			if ( ! $order_id ) {
				return new WP_Error( 'wgdp_missing_order', 'Invalid link. Missing order information.' );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || ! $this->validate_self_service_token( $order, $token ) ) {
				return new WP_Error( 'wgdp_invalid_token', 'Invalid or expired link.' );
			}
			return array(
				'order'     => $order,
				'auth_type' => 'token',
				'token'     => $token,
				'order_key' => '',
			);
		}

		$order_id  = isset( $request['order_id'] ) ? absint( $request['order_id'] ) : 0;
		$order_key = isset( $request['key'] ) ? sanitize_text_field( wp_unslash( $request['key'] ) ) : '';
		if ( '' === $order_key && isset( $request['order_key'] ) ) {
			$order_key = sanitize_text_field( wp_unslash( $request['order_key'] ) );
		}

		if ( ! $order_id || empty( $order_key ) ) {
			return new WP_Error( 'wgdp_missing_order', 'Invalid link. Missing order information.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
			return new WP_Error( 'wgdp_invalid_order_key', 'Invalid link. Order not found or key mismatch.' );
		}

		return array(
			'order'     => $order,
			'auth_type' => 'legacy',
			'token'     => '',
			'order_key' => $order_key,
		);
	}

	/**
	 * Detect Conditional Preorder reservation orders without requiring that plugin.
	 */
	private function should_defer_preorder_order( $order ) {
		return $order instanceof WC_Order
			&& (bool) $order->get_meta( '_wcpr_campaign_product_id' )
			&& 'yes' !== $order->get_meta( '_wcpr_charge_succeeded' );
	}

	/**
	 * Check whether customer self-service may collect an email for this item yet.
	 */
	private function item_trigger_allows_self_service( $order, $product_id ) {
		$trigger = WGDP_Product_Meta::get_entitlement_trigger( $product_id );
		if ( 'on_completion' === $trigger ) {
			return $order instanceof WC_Order && 'completed' === $order->get_status();
		}

		return $order instanceof WC_Order && in_array( $order->get_status(), array( 'processing', 'completed' ), true );
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
		if ( $this->should_defer_preorder_order( $order ) ) {
			return array();
		}

		$unassigned = array();
		$ent = WGDP_Entitlements::instance();

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			if ( ! $this->item_trigger_allows_self_service( $order, $product_id ) ) {
				continue;
			}

			$quantity        = $item->get_quantity();
			$qty_refunded    = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
			$effective_qty   = max( 0, $quantity - $qty_refunded );
			$confirmed_count = $ent->count_confirmed_recipients_for_item( $item->get_id() );

			if ( $effective_qty <= 0 || $confirmed_count >= $effective_qty ) {
				continue;
			}

			// Collect pending/expired (unverified) emails for this item. These rows still
			// hold a slot under count_active_recipients_for_item() (used to enforce slots
			// on submission), so they must be offered as replaceable via old_email here —
			// otherwise a slot that looks open (confirmed count excludes them) permanently
			// rejects new submissions once expire_stale() flips verification_status.
			$pending_emails = array();
			$entitlements   = $ent->get_by_order_item( $item->get_id() );
			foreach ( $entitlements as $row ) {
				if ( in_array( $row['verification_status'], array( 'pending', 'expired' ), true ) && 'revoked' !== $row['grant_status'] && ! empty( $row['recipient_email'] ) ) {
					$pending_emails[ $row['recipient_email'] ] = true;
				}
			}
			$pending_emails = array_keys( $pending_emails );

			// The submission handler enforces the slot cap with count_active_recipients_for_item()
			// (pending + verified + granted), not the confirmed-only count above. Replacing a
			// pending email via old_email doesn't consume additional capacity, so every pending
			// row can be offered as a replace slot; brand-new blank slots are capped at the
			// remaining additive capacity so the form never offers a slot the handler will reject.
			$active_count       = $ent->count_active_recipients_for_item( $item->get_id() );
			$additive_capacity  = max( 0, $effective_qty - $active_count );
			$slots_remaining    = count( $pending_emails ) + $additive_capacity;

			$unassigned[] = array(
				'item'            => $item,
				'product_name'    => $item->get_name(),
				'order_item_id'   => $item->get_id(),
				'product_id'      => $product_id,
				'variation_id'    => $variation_id,
				'quantity'        => $effective_qty,
				'active_count'    => $confirmed_count,
				'slots_remaining' => $slots_remaining,
				'pending_emails'  => $pending_emails,
			);
		}

		return $unassigned;
	}

	/**
	 * Build the customer-facing sentence explaining why we need a Google email,
	 * based on the release mode of the unassigned items.
	 *
	 * @param array $unassigned Unassigned item rows from get_unassigned_items().
	 * @return string Plain sentence, no markup.
	 */
	private function get_recovery_intro_text( $unassigned ) {
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

		if ( $has_min_sales ) {
			return 'Your order includes digital content. Provide your Google account email below; access will be granted once the product reaches its minimum sales goal.';
		}
		if ( $has_manual ) {
			return 'Your order includes digital content. Provide your Google account email below; access will be granted once it becomes available.';
		}
		return 'Your order includes digital content that requires a Google account email to receive access.';
	}

	/**
	 * Append the recovery link to WooCommerce's existing customer email.
	 *
	 * This runs on woocommerce_email_additional_content_{$email->id}, which is
	 * rendered by both the HTML and plain-text templates (and by the block-based
	 * email editor), unlike woocommerce_email_after_order_table. No separate
	 * message is ever sent.
	 *
	 * @param string   $content Existing additional content.
	 * @param WC_Order $order   Order the email is about.
	 * @param WC_Email $email   Email being rendered.
	 * @return string
	 */
	public function append_recovery_link_to_email_content( $content, $order, $email ) {
		if ( ! $order instanceof WC_Order ) {
			return $content;
		}

		$unassigned = $this->get_unassigned_items( $order );
		if ( empty( $unassigned ) ) {
			return $content;
		}

		$url   = $this->build_self_service_url( $order );
		$intro = $this->get_recovery_intro_text( $unassigned );

		// The plain-text templates render this same string through
		// wp_strip_all_tags(), so the URL is emitted as visible text rather than
		// relying only on the anchor href — otherwise the plain-text variant
		// would strip the link away entirely.
		return $content
			. '<p style="margin:24px 0 0;color:#555;font-size:15px;line-height:1.6;">' . esc_html( $intro ) . '</p>'
			. '<p style="margin:12px 0 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#7f54b3;color:#fff;text-decoration:none;padding:10px 24px;border-radius:4px;font-weight:600;">Provide Google Email</a></p>'
			. '<p style="margin:12px 0 0;color:#888;font-size:13px;line-height:1.6;">Provide your Google email for digital access: ' . esc_html( $url ) . '</p>';
	}

	/**
	 * Filter the_content to display the self-service form on the plugin page.
	 */
	public function filter_page_content( $content ) {
		$page_id = (int) get_option( 'wgdp_provide_email_page_id', 0 );
		if ( ! $page_id || ! is_page( $page_id ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$auth = $this->resolve_request_order( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_wp_error( $auth ) ) {
			return $this->wrap_content( $this->error_content( $auth->get_error_message() ) );
		}
		$order = $auth['order'];

		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			return $this->wrap_content( $this->error_content( 'This order is no longer eligible for digital access.' ) );
		}

		// Token-based links carry their own issuance-based expiry (checked in
		// validate_self_service_token()); the order-date-based check below only
		// applies to legacy order-key links, which have no per-link expiry of
		// their own.
		if ( 'legacy' === $auth['auth_type'] && $this->is_link_expired( $order ) ) {
			return $this->wrap_content( $this->error_content( 'This link has expired. Please contact the store for assistance.' ) );
		}

		if ( $this->should_defer_preorder_order( $order ) ) {
			return $this->wrap_content( $this->error_content( 'Digital access is available after the preorder campaign is successfully charged.' ) );
		}

		$unassigned = $this->get_unassigned_items( $order );
		if ( empty( $unassigned ) ) {
			return $this->wrap_content( $this->success_content( $order ) );
		}

		return $this->wrap_content( $this->form_content( $order, $unassigned, $auth ) );
	}

	/**
	 * AJAX handler: create entitlements for self-service email submissions.
	 */
	public function ajax_self_service_email() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wgdp_self_service' ) ) {
			wp_send_json_error( 'Security check failed.' );
		}

		// Throttle by IP before the order/token is even resolved, so guessing
		// order keys or tokens against this endpoint can't run unthrottled —
		// the per-order/per-IP limiters below only fire after auth succeeds.
		if ( ! $this->consume_rate_limit( 'wgdp_ss_auth_' . md5( $this->get_request_ip() ), 20, HOUR_IN_SECONDS ) ) {
			wp_send_json_error( 'Too many requests. Please wait before submitting again.' );
		}

		$auth = $this->resolve_request_order( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $auth ) ) {
			wp_send_json_error( $auth->get_error_message() );
		}
		$order    = $auth['order'];
		$order_id = $order->get_id();
		$items    = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual fields sanitized below.

		if ( $this->should_defer_preorder_order( $order ) ) {
			wp_send_json_error( 'Digital access is available after the preorder campaign is successfully charged.' );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			wp_send_json_error( 'This order is no longer eligible for digital access.' );
		}

		// See filter_page_content() for why the order-date-based expiry check
		// only applies to legacy order-key links, not plugin-issued tokens.
		if ( 'legacy' === $auth['auth_type'] && $this->is_link_expired( $order ) ) {
			wp_send_json_error( 'This link has expired. Please contact the store for assistance.' );
		}

		if ( ! is_array( $items ) || empty( $items ) ) {
			wp_send_json_error( 'No items submitted.' );
		}

		// Both limiters must be consumed unconditionally (not via || short-circuit):
		// each is an independent quota that should count every attempt, regardless
		// of whether the other check already failed.
		$order_rate_key = 'wgdp_ss_order_' . $order_id;
		$ip_rate_key    = 'wgdp_ss_ip_' . $order_id . '_' . md5( $this->get_request_ip() );
		$order_rate_ok = $this->consume_rate_limit( $order_rate_key, 10, HOUR_IN_SECONDS );
		$ip_rate_ok    = $this->consume_rate_limit( $ip_rate_key, 5, HOUR_IN_SECONDS );
		if ( ! $order_rate_ok || ! $ip_rate_ok ) {
			wp_send_json_error( 'Too many requests. Please wait before submitting again.' );
		}

		$ent = WGDP_Entitlements::instance();

		$created_count = 0;
		$mail_failures = 0;
		$cleared_items = array();

		foreach ( $items as $submission ) {
			if ( ! is_array( $submission ) ) {
				continue;
			}

			$order_item_id = absint( $submission['order_item_id'] ?? 0 );
			$email         = WGDP_Entitlements::normalize_email( $submission['email'] ?? '' );
			$old_email     = WGDP_Entitlements::normalize_email( $submission['old_email'] ?? '' );

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

			if ( ! $this->item_trigger_allows_self_service( $order, $product_id ) ) {
				continue;
			}

			// Only clear the specific pending recipient being replaced (identified by
			// their old email), never every pending slot on the order item — other
			// recipients on the same multi-slot item must keep their own pending rows.
			$clear_key        = $order_item_id . '|' . $old_email;
			$clear_unverified = '' !== $old_email && empty( $cleared_items[ $clear_key ] );
			$result = $ent->assign_recipient_to_order_item( array(
				'order_id'               => $order_id,
				'order_item_id'          => $order_item_id,
				'email'                  => $email,
				'count_mode'             => 'active',
				'clear_unverified'       => $clear_unverified,
				'clear_unverified_email' => $old_email,
				'allowed_order_statuses' => array( 'processing', 'completed' ),
			) );

			if ( is_wp_error( $result ) ) {
				continue;
			}
			$cleared_items[ $clear_key ] = true;
			$order = $result['order'];
			$item  = $result['item'];

			$tokens    = $result['tokens'];
			$anchor_id = $result['primary_id'];
			if ( empty( $tokens ) ) {
				// The recipient already had a non-revoked row for this item (e.g. the
				// customer resubmitted the same email after a failed verification
				// email). If it's still unverified, reissue a fresh OTP/claim token
				// and resend rather than silently no-op'ing — otherwise a retry after
				// a transient mail failure can never succeed.
				//
				// When the item has multiple Drive resources, $result['primary_id']
				// may resolve to a sibling row that's already verified (e.g. file A
				// verified, file B still pending from a previous failed send) — in
				// that case fall back to any still-pending, non-revoked sibling
				// instead of silently no-op'ing on the verified row.
				$existing_row = $ent->get( $result['primary_id'] );
				if ( ! $existing_row || 'revoked' === $existing_row['grant_status'] ) {
					continue;
				}
				if ( 'pending' !== $existing_row['verification_status'] ) {
					$pending_sibling = null;
					foreach ( $ent->get_siblings( $order_item_id, $email ) as $sibling ) {
						if ( 'revoked' !== $sibling['grant_status'] && 'pending' === $sibling['verification_status'] ) {
							$pending_sibling = $sibling;
							break;
						}
					}
					if ( ! $pending_sibling ) {
						continue;
					}
					$anchor_id = (int) $pending_sibling['id'];
				}
				$tokens = $ent->issue_otp_for_recipient_group( $anchor_id );
				if ( is_wp_error( $tokens ) ) {
					continue;
				}
			}

			$mail_result = WGDP_Notification_Email::send_otp( $email, $tokens['otp'], $tokens['claim_token'], $order, $item );

			// Set drive items flag if not already set.
			if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
				$order->update_meta_data( '_wgdp_has_drive_items', '1' );
				$order->save();
			}

			if ( is_wp_error( $mail_result ) ) {
				$mail_failures++;
				$order->add_order_note( sprintf(
					'WGDP: Entitlement #%d created for %s, but self-service verification email failed for "%s" — %s',
					$anchor_id,
					$email,
					$item->get_name(),
					$mail_result->get_error_message()
				) );
			} else {
				$order->add_order_note( sprintf(
					'WGDP: Verification email sent to %s for "%s" (entitlement #%d) — self-service',
					$email,
					$item->get_name(),
					$anchor_id
				) );
				$created_count++;
			}
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
		} elseif ( $mail_failures > 0 ) {
			delete_transient( 'wgdp_permission_counts' );
			wp_send_json_error( 'Digital access was reserved, but the verification email could not be sent. Please contact the store for assistance.' );
		} else {
			// No entitlements were created and no email was sent — nothing was
			// reserved or delivered, so refund both rate-limit tokens consumed
			// above. Otherwise a single failed submission (typo, no free slots,
			// item mismatch) burns a per-order and a per-IP attempt and can lock
			// the customer out of self-service for the rest of the hour.
			$this->release_rate_limit( $order_rate_key );
			$this->release_rate_limit( $ip_rate_key );
			wp_send_json_error( 'No entitlements were created. Slots may already be filled or emails were invalid.' );
		}
	}

	/**
	 * Wrap content in a styled container.
	 */
	private function wrap_content( $content ) {
		// The card carries its own dark background so the light text inside it stays
		// readable no matter what background color the surrounding theme uses — the
		// previous version set only text color and assumed a dark page background,
		// which rendered white-on-white on light themes.
		return '<div class="wgdp-provide-email-wrap" style="max-width:560px;margin:0 auto;padding:32px 24px;background:#1f2937;border-radius:8px;color:#f5f7fb;">' . $content . '</div>';
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
				. '<p style="color:#f5f7fb;font-size:15px;">All digital access slots are already assigned.</p>'
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
				$html .= '<div style="margin-top:24px;text-align:left;border-top:1px solid rgba(255,255,255,0.24);padding-top:20px;">'
					. '<p style="color:#cbd5e1;font-size:13px;font-weight:600;margin:0 0 12px;text-transform:uppercase;letter-spacing:0.5px;">Assigned Access</p>';
			foreach ( $assigned as $a ) {
				$status_label = '';
				if ( 'granted' === $a['status'] ) {
					$status_label = '<span style="color:#00a32a;font-size:12px;"> &#10003; Granted</span>';
				} elseif ( 'pending' === $a['status'] || 'pending_release' === $a['status'] ) {
					$status_label = '<span style="color:#dba617;font-size:12px;"> &#9679; Pending</span>';
				}
					$html .= '<div style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.16);">'
						. '<div style="font-size:14px;font-weight:600;color:#f5f7fb;">' . esc_html( $a['product'] ) . $status_label . '</div>'
						. '<div style="font-size:14px;color:#cbd5e1;font-family:monospace;">' . esc_html( self::mask_email( $a['email'] ) ) . '</div>'
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

		if ( '' === $local || '' === $domain ) {
			return '***';
		}

		// Mask local part: show first 2 and last 1, mask the rest.
		$local_len = strlen( $local );
		if ( $local_len <= 3 ) {
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
			if ( $dn_len <= 3 ) {
				$masked_domain = $domain_name[0] . str_repeat( '*', max( 1, $dn_len - 1 ) ) . $tld;
			} else {
				$masked_domain = substr( $domain_name, 0, 2 ) . str_repeat( '*', $dn_len - 3 ) . substr( $domain_name, -1 ) . $tld;
			}
		}

		return $masked_local . '@' . $masked_domain;
	}

	/**
	 * Fixed-window counter for public self-service actions.
	 */
	private function consume_rate_limit( $key, $limit, $window ) {
		return WGDP_DB::consume_rate_limit( $key, $limit, $window );
	}

	/**
	 * Refund a previously consumed self-service rate-limit token.
	 *
	 * Used when a submission that consumed the per-order/per-IP tokens turned
	 * out to be a wasted attempt with no side effect (no entitlement created,
	 * no email delivered), matching the OTP-resend refund policy on the claim
	 * page so a single typo can't lock the customer out for the hour.
	 *
	 * @param string $key Logical rate-limit key, matching a prior consume_rate_limit() call.
	 */
	private function release_rate_limit( $key ) {
		WGDP_DB::release_rate_limit( $key );
	}

	/**
	 * Best-effort client IP for coarse mail-abuse throttling.
	 *
	 * Trusts the direct connection's REMOTE_ADDR by default. When the site sits
	 * behind a proxy/CDN (so every visitor shares the server's REMOTE_ADDR), the
	 * operator can list trusted proxy IPs via the 'wgdp_trusted_proxies' filter;
	 * only then is the client IP read from the X-Forwarded-For chain, walking
	 * right-to-left past any configured proxies. The header is never honored for
	 * untrusted direct peers, since it is trivially client-spoofable.
	 */
	private function get_request_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$trusted_proxies = (array) apply_filters( 'wgdp_trusted_proxies', array() );
		if ( $ip && in_array( $ip, $trusted_proxies, true ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$chain = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
			$chain = array_reverse( array_values( array_filter( $chain ) ) );
			foreach ( $chain as $hop ) {
				if ( ! in_array( $hop, $trusted_proxies, true ) ) {
					$ip = $hop;
					break;
				}
			}
		}

		return $ip ?: 'unknown';
	}

	/**
	 * Render the self-service form.
	 *
	 * @param WC_Order $order      The order.
	 * @param array    $unassigned Unassigned item data from get_unassigned_items().
	 * @return string HTML content.
	 */
	private function form_content( $order, $unassigned, $auth = array() ) {
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

			$html = '<h2 style="color:#fff;margin:0 0 8px;text-align:center;">Provide Your Google Email</h2>'
				. '<p style="color:#e5e7eb;text-align:center;margin-bottom:4px;">Order #' . esc_html( $order->get_order_number() ) . '</p>';

			if ( $has_min_sales ) {
				$html .= '<p style="color:#e5e7eb;text-align:center;margin-bottom:24px;font-size:14px;">Access will be granted once the product reaches its minimum sales goal.</p>';
			} elseif ( $has_manual ) {
				$html .= '<p style="color:#e5e7eb;text-align:center;margin-bottom:24px;font-size:14px;">Access will be granted once it becomes available.</p>';
			} else {
				$html .= '<p style="color:#e5e7eb;text-align:center;margin-bottom:24px;font-size:14px;">Enter the Google account email for each item to receive digital access.</p>';
			}

		$html .= '<div id="wgdp-ss-message" style="display:none;border-radius:4px;padding:10px 16px;margin-bottom:16px;font-size:14px;"></div>';

		$html .= '<form id="wgdp-ss-form">';
		$html .= '<input type="hidden" name="order_id" value="' . esc_attr( $order->get_id() ) . '" />';
		if ( isset( $auth['auth_type'] ) && 'token' === $auth['auth_type'] && ! empty( $auth['token'] ) ) {
			$html .= '<input type="hidden" name="self_service_token" value="' . esc_attr( $auth['token'] ) . '" />';
		} else {
			$html .= '<input type="hidden" name="order_key" value="' . esc_attr( $order->get_order_key() ) . '" />';
		}
		$html .= '<input type="hidden" name="nonce" value="' . esc_attr( $nonce ) . '" />';

		$field_index = 0;
		foreach ( $unassigned as $ua ) {
			$pending = $ua['pending_emails'] ?? array();
			for ( $i = 0; $i < $ua['slots_remaining']; $i++ ) {
				$label = esc_html( $ua['product_name'] );
				if ( $ua['slots_remaining'] > 1 ) {
					$label .= ' &mdash; Recipient ' . ( $i + 1 );
				}

				$pending_email = isset( $pending[ $i ] ) ? $pending[ $i ] : '';

				$html .= '<div style="margin-bottom:16px;">';
					$html .= '<label style="display:block;font-weight:600;margin-bottom:4px;font-size:14px;color:#fff;">' . $label . '</label>';

				if ( $pending_email ) {
					// Show pending email with unverified status and option to change.
						$html .= '<div class="wgdp-ss-pending-wrap" style="border:1px solid #dba617;border-radius:4px;padding:10px 12px;background:rgba(219,166,23,0.14);">';
					$html .= '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">';
					$html .= '<div>';
						$html .= '<span style="font-size:15px;color:#f5f7fb;">' . esc_html( $pending_email ) . '</span> ';
					$html .= '<span style="display:inline-block;background:#dba617;color:#fff;font-size:11px;font-weight:600;padding:2px 8px;border-radius:3px;vertical-align:middle;">Unverified</span>';
					$html .= '</div>';
						$html .= '<a href="#" class="wgdp-ss-change-email" style="font-size:13px;color:#93c5fd;text-decoration:none;font-weight:600;white-space:nowrap;">Use a different email</a>';
					$html .= '</div>';
					$html .= '</div>';
					// Hidden email input pre-filled; revealed when "Use a different email" is clicked.
					$html .= '<input type="email" '
						. 'name="items[' . $field_index . '][email]" '
						. 'data-order-item-id="' . esc_attr( $ua['order_item_id'] ) . '" '
						. 'data-old-email="' . esc_attr( $pending_email ) . '" '
						. 'value="' . esc_attr( $pending_email ) . '" '
						. 'placeholder="Google account email" '
						. 'style="display:none;width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:15px;box-sizing:border-box;margin-top:8px;" />';
				} else {
					$html .= '<input type="email" '
						. 'name="items[' . $field_index . '][email]" '
						. 'data-order-item-id="' . esc_attr( $ua['order_item_id'] ) . '" '
						. 'placeholder="Google account email" '
						. 'style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:15px;box-sizing:border-box;" />';
				}

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

	// Handle "Use a different email" links.
	form.addEventListener("click", function(e) {
		var link = e.target.closest(".wgdp-ss-change-email");
		if (!link) return;
		e.preventDefault();
		var wrap = link.closest(".wgdp-ss-pending-wrap");
		var emailInput = wrap.nextElementSibling;
		wrap.style.display = "none";
		emailInput.style.display = "block";
		emailInput.value = "";
		emailInput.focus();
	});

	form.addEventListener("submit", function(e) {
		e.preventDefault();

		var items = [];
		var inputs = form.querySelectorAll("input[type=email]");
		var hasEmail = false;
		for (var i = 0; i < inputs.length; i++) {
			if (inputs[i].style.display === "none") {
				// Still-hidden pending-email field: user did not click
				// "Use a different email" for this slot, so leave it alone.
				continue;
			}
			var email = inputs[i].value.trim();
			if (email) {
				hasEmail = true;
				items.push({
					order_item_id: inputs[i].getAttribute("data-order-item-id"),
					email: email,
					old_email: inputs[i].getAttribute("data-old-email") || ""
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
			var orderKeyField = form.querySelector("input[name=order_key]");
			var tokenField = form.querySelector("input[name=self_service_token]");
			if (orderKeyField) {
				fd.append("order_key", orderKeyField.value);
			}
			if (tokenField) {
				fd.append("self_service_token", tokenField.value);
			}
			fd.append("nonce", form.querySelector("input[name=nonce]").value);
		for (var j = 0; j < items.length; j++) {
			fd.append("items[" + j + "][order_item_id]", items[j].order_item_id);
			fd.append("items[" + j + "][email]", items[j].email);
			fd.append("items[" + j + "][old_email]", items[j].old_email);
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
