<?php
defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

class WGDP_Blocks_Integration implements IntegrationInterface {

	public function get_name() {
		return 'wgdp-checkout';
	}

	public function initialize() {
		$this->register_block_script();
		$this->extend_store_api();
	}

	public function get_script_handles() {
		return array( 'wgdp-checkout-block' );
	}

	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * Provide qualifying items data to the checkout block script.
	 */
	public function get_script_data() {
		return array(
			'qualifyingItems' => $this->get_qualifying_items(),
		);
	}

	private function register_block_script() {
		wp_register_script(
			'wgdp-checkout-block',
			WGDP_PLUGIN_URL . 'assets/js/wgdp-checkout-block.js',
			array( 'wp-element', 'wp-plugins', 'wp-data', 'wc-blocks-checkout', 'wc-settings' ),
			WGDP_VERSION,
			true
		);
	}

	/**
	 * Register the Store API extension for recipient emails.
	 */
	private function extend_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => 'wgdp',
				'data_callback'   => function () {
					return array( 'recipients' => new \stdClass() );
				},
				'schema_callback' => function () {
					return array(
						'recipients' => array(
							'description' => 'Recipient emails keyed by productId_variationId',
							'type'        => 'object',
							'context'     => array( 'view', 'edit' ),
						),
					);
				},
				'schema_type'     => ARRAY_A,
			)
		);

		add_action(
			'woocommerce_store_api_checkout_update_order_from_request',
			array( $this, 'save_recipients_from_store_api' ),
			10,
			2
		);
	}

	/**
	 * Save recipient emails from checkout extension data to order item meta.
	 */
	public function save_recipients_from_store_api( $order, $request ) {
		$extensions = $request->get_param( 'extensions' );
		$recipients = $extensions['wgdp']['recipients'] ?? array();

		if ( ! is_array( $recipients ) ) {
			$recipients = array();
		}

		$cart_key_queue = $this->get_cart_key_queue_by_product();

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$legacy_key   = $product_id . '_' . ( $variation_id ?: 0 );
			$cart_key     = '';

			// Check if this item qualifies for digital entitlement.
			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			$qty        = $item->get_quantity();
			if ( ! empty( $cart_key_queue[ $legacy_key ] ) ) {
				$cart_key = array_shift( $cart_key_queue[ $legacy_key ] );
			}
			$raw_emails = ( $cart_key && isset( $recipients[ $cart_key ] ) && is_array( $recipients[ $cart_key ] ) )
				? $recipients[ $cart_key ]
				: array();

			if ( empty( $raw_emails ) && isset( $recipients[ $legacy_key ] ) && is_array( $recipients[ $legacy_key ] ) ) {
				$raw_emails = $recipients[ $legacy_key ];
			}

			// Skip items with no recipients — emails are optional (matches classic checkout behavior).
			if ( empty( $raw_emails ) ) {
				continue;
			}

			// Reindex first so a sparse/out-of-order payload (e.g. {0:'a', 5:'b'})
			// can't slip past the positional qty cap below. Mirrors the classic
			// checkout (WGDP_Classic_Checkout::validate_recipient_fields).
			$raw_emails = array_values( $raw_emails );

			// Validate each email format, skipping blanks. Enforce the qty cap by
			// position (count), not by the original array key.
			$emails = array();
			foreach ( $raw_emails as $position => $raw_email ) {
				$raw_email = trim( $raw_email );
				if ( '' === $raw_email ) {
					continue;
				}

				if ( (int) $position >= (int) $qty ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
						'wgdp_too_many_recipients',
						sprintf(
							'Too many recipient emails were submitted for "%s". Please refresh checkout and try again.',
							$item->get_name()
						),
						400
					);
				}

					$email = strtolower( sanitize_email( $raw_email ) );
				if ( ! is_email( $email ) ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
						'wgdp_invalid_email',
						sprintf(
							'Invalid email address "%s" for "%s".',
							esc_html( $raw_email ),
							$item->get_name()
						),
						400
					);
				}
				$emails[] = $email;
			}

			if ( empty( $emails ) ) {
				continue;
			}

			// Validate no duplicates within this item.
			if ( count( $emails ) !== count( array_unique( $emails ) ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'wgdp_duplicate_recipients',
					sprintf(
						'Duplicate recipient emails found for "%s". Each recipient must be unique.',
						$item->get_name()
					),
					400
				);
			}

			$item->update_meta_data( '_wgdp_recipients', wp_json_encode( $emails ) );
			$item->save();
		}
	}

	/**
	 * Build a FIFO queue of Woo cart item keys grouped by product/variation.
	 *
	 * Store API checkout creates order items from the current cart, and the order
	 * update hook runs while that cart is still available. The queue lets us map
	 * duplicate product/variation lines back to their distinct cart-line payloads.
	 */
	private function get_cart_key_queue_by_product() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$queue = array();
		foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
			$product_id   = (int) ( $cart_item['product_id'] ?? 0 );
			$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
			$key          = $product_id . '_' . ( $variation_id ?: 0 );
			if ( ! isset( $queue[ $key ] ) ) {
				$queue[ $key ] = array();
			}
			$queue[ $key ][] = $cart_key;
		}

		return $queue;
	}

	/**
	 * Build the list of qualifying cart items for the frontend (camelCase keys for JS).
	 */
	private function get_qualifying_items() {
		if ( ! did_action( 'woocommerce_blocks_loaded' ) ) {
			return array();
		}

		return array_map( function ( $item ) {
			return array(
				'itemKey'     => $item['cart_key'],
				'productName' => $item['product_name'],
				'quantity'    => $item['quantity'],
				'productId'   => $item['product_id'],
				'variationId' => $item['variation_id'],
			);
		}, WGDP_Product_Meta::get_qualifying_cart_items() );
	}
}
