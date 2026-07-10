<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Classic_Checkout {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'woocommerce_after_order_notes', array( $this, 'render_recipient_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_recipient_fields' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_recipient_meta' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render recipient email fields on the checkout page.
	 */
	public function render_recipient_fields( $checkout ) {
		$items = WGDP_Product_Meta::get_qualifying_cart_items();

		if ( empty( $items ) ) {
			return;
		}

		// Determine the release mode description.
		$has_min_sales    = false;
		$has_manual       = false;
		foreach ( $items as $item ) {
			$mode = WGDP_Release_Gate::get_effective_release_mode( $item['product_id'], $item['variation_id'] ?: 0 );
			if ( 'min_sales_qty' === $mode ) {
				$has_min_sales = true;
			} elseif ( 'manual_release' === $mode ) {
				$has_manual = true;
			}
		}

		echo '<h3>' . esc_html__( 'Digital Access Recipients', 'woo-gdrive-permission' ) . '</h3>';
		if ( $has_min_sales ) {
			echo '<p>' . esc_html__( 'Enter your Google account email below to receive access once the product reaches its minimum sales goal. If you skip this now, you will receive an email after purchase with a link to provide it later.', 'woo-gdrive-permission' ) . '</p>';
		} elseif ( $has_manual ) {
			echo '<p>' . esc_html__( 'Enter your Google account email below to receive access once it becomes available. If you skip this now, you will receive an email after purchase with a link to provide it later.', 'woo-gdrive-permission' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Enter the Google account email for each recipient to grant access right away. If you skip this now, you will receive an email after purchase with a link to provide it later.', 'woo-gdrive-permission' ) . '</p>';
		}
		echo '<div id="wgdp-classic-recipients" class="woocommerce-additional-fields__field-wrapper">';

		foreach ( $items as $item ) {
			for ( $i = 0; $i < $item['quantity']; $i++ ) {
				$field_key = 'wgdp_recipients[' . $item['cart_key'] . '][' . $i . ']';
				$field_id  = 'wgdp-recipient-' . sanitize_html_class( $item['cart_key'] ) . '-' . $i;

				$label = $item['quantity'] > 1
					? esc_html( $item['product_name'] ) . ' &mdash; ' . sprintf(
						/* translators: %d: recipient number */
						__( 'Recipient %d', 'woo-gdrive-permission' ),
						$i + 1
					)
					: esc_html( $item['product_name'] );

				// woocommerce_form_field()'s default value lookup does a raw
				// $_POST[ $key ] read using the literal bracketed field name, which
				// never matches PHP's parsed nested array — so on a non-AJAX
				// checkout redisplay (JS disabled) the field would always render
				// blank even if the customer already filled it in correctly.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce; this only echoes back a previously submitted value for redisplay.
				$posted_value = isset( $_POST['wgdp_recipients'][ $item['cart_key'] ][ $i ] )
					? wp_unslash( $_POST['wgdp_recipients'][ $item['cart_key'] ][ $i ] )
					: '';

				woocommerce_form_field( $field_key, array(
					'type'        => 'email',
					'label'       => $label,
					'required'    => false,
					'id'          => $field_id,
					'placeholder' => __( 'Google account email (optional)', 'woo-gdrive-permission' ),
					'class'       => array( 'form-row-wide' ),
					'value'       => $posted_value,
				) );
			}
		}

		echo '</div>';
	}

	/**
	 * Validate recipient email fields during checkout processing.
	 */
	public function validate_recipient_fields() {
		$items = WGDP_Product_Meta::get_qualifying_cart_items();

		if ( empty( $items ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		$recipients = isset( $_POST['wgdp_recipients'] ) ? wp_unslash( $_POST['wgdp_recipients'] ) : array();

		if ( ! is_array( $recipients ) ) {
			$recipients = array();
		}

		foreach ( $items as $item ) {
			$key    = $item['cart_key'];
			$qty    = $item['quantity'];
			$emails = isset( $recipients[ $key ] ) && is_array( $recipients[ $key ] ) ? $recipients[ $key ] : array();

			// Reindex so positional validation matches save_recipient_meta(), which
			// uses array_slice() on the internal order. Without this, a sparse array
			// (e.g. wgdp_recipients[KEY][999]) would slip past the positional loops
			// below while still being saved as a recipient.
			$emails = array_values( $emails );

			$valid_emails = array();

			for ( $i = 0; $i < $qty; $i++ ) {
				$raw = isset( $emails[ $i ] ) ? trim( $emails[ $i ] ) : '';

				// Skip blank fields — email is optional.
				if ( '' === $raw ) {
					continue;
				}

				$sanitized = strtolower( sanitize_email( $raw ) );
				if ( ! is_email( $sanitized ) ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: recipient number, 2: product name */
							__( 'Please enter a valid email address for recipient %1$d of "%2$s", or leave it blank.', 'woo-gdrive-permission' ),
							$i + 1,
							$item['product_name']
						),
						'error'
					);
					// Surface at most one error per item, but keep validating the
					// remaining items so multi-item carts report every problem at once.
					continue 2;
				}

				$valid_emails[] = $sanitized;
			}

			for ( $i = $qty; $i < count( $emails ); $i++ ) {
				$raw = isset( $emails[ $i ] ) ? trim( $emails[ $i ] ) : '';
				if ( '' !== $raw ) {
					wc_add_notice(
						sprintf(
							/* translators: %s: product name */
							__( 'Too many recipient emails were submitted for "%s". Please refresh checkout and try again.', 'woo-gdrive-permission' ),
							$item['product_name']
						),
						'error'
					);
					continue 2;
				}
			}

			if ( count( $valid_emails ) !== count( array_unique( $valid_emails ) ) ) {
				wc_add_notice(
					sprintf(
						/* translators: %s: product name */
						__( 'Duplicate recipient emails found for "%s". Each recipient must be unique.', 'woo-gdrive-permission' ),
						$item['product_name']
					),
					'error'
				);
				continue;
			}
		}
	}

	/**
	 * Save recipient emails to order item meta during order creation.
	 */
	public function save_recipient_meta( $item, $cart_item_key, $values, $order ) {
		// Skip if no classic checkout POST data (block checkout handles this via Store API).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce.
		if ( ! isset( $_POST['wgdp_recipients'] ) ) {
			return;
		}

		$product_id   = $values['product_id'] ?? 0;
		$variation_id = $values['variation_id'] ?? 0;
		$key          = $cart_item_key;
		$quantity     = max( 0, (int) ( $values['quantity'] ?? 0 ) );

		if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$recipients = wp_unslash( $_POST['wgdp_recipients'] );
		$raw_emails = isset( $recipients[ $key ] ) && is_array( $recipients[ $key ] ) ? $recipients[ $key ] : array();

		// Reindex first so a sparse POST array (e.g. wgdp_recipients[KEY][999])
		// slices positionally, matching validate_recipient_fields().
		$raw_emails = array_values( $raw_emails );

		// Keep each email at its original slot position (rather than compacting
		// past skipped/blank slots) so recipient_index — assigned positionally
		// downstream in WGDP_Order_Handler::create_entitlements() — still matches
		// the "Recipient N" slot the customer actually filled in. This matters
		// for recipient_index_within_effective_quantity(), which decides which
		// recipient keeps access after a partial refund.
		$emails = array();
		foreach ( array_slice( $raw_emails, 0, $quantity ) as $position => $raw ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				continue;
			}
			$email = strtolower( sanitize_email( $raw ) );
			if ( is_email( $email ) && ! in_array( $email, $emails, true ) ) {
				$emails[ $position ] = $email;
			}
		}

		if ( ! empty( $emails ) ) {
			$positional = array_fill( 0, max( array_keys( $emails ) ) + 1, '' );
			foreach ( $emails as $position => $email ) {
				$positional[ $position ] = $email;
			}
			$item->update_meta_data( '_wgdp_recipients', wp_json_encode( $positional ) );
		}
	}

	/**
	 * Enqueue frontend JS on the checkout page.
	 */
	public function enqueue_assets() {
		if ( ! is_checkout() ) {
			return;
		}

		$items = WGDP_Product_Meta::get_qualifying_cart_items();
		if ( empty( $items ) ) {
			return;
		}

		wp_enqueue_script(
			'wgdp-classic-checkout',
			WGDP_PLUGIN_URL . 'assets/js/wgdp-classic-checkout.js',
			array( 'jquery' ),
			WGDP_VERSION,
			true
		);
	}
}
