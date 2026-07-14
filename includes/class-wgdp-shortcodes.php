<?php
/**
 * Shortcodes for Woo GDrive Permission.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WGDP_Shortcodes {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'wgdp_sold_count', array( $this, 'sold_count_shortcode' ) );
		add_shortcode( 'wgdp_min_sales_qty', array( $this, 'min_sales_qty_shortcode' ) );
	}

	/**
	 * [wgdp_sold_count id="OPTIONAL" additional="0" subtract="0"]
	 *
	 * Displays the plugin's own paid-qty sales counter for a product.
	 * Sums the product-level counter plus all variation-level counters.
	 */
	public function sold_count_shortcode( $atts ) {
		global $product;

		$atts = shortcode_atts(
			array(
				'id'         => 0,
				'additional' => 0,
				'subtract'   => 0,
			),
			$atts,
			'wgdp_sold_count'
		);

		$product_id = (int) $atts['id'];

		if ( ! $product_id && $product instanceof WC_Product ) {
			$product_id = $product->get_id();
		}

		if ( ! $product_id ) {
			$product_id = (int) get_the_ID();
		}

		if ( ! $product_id ) {
			return '0';
		}

		$product_obj = wc_get_product( $product_id );
		if ( ! $product_obj ) {
			return '0';
		}

		// Start with the product-level counter tracked by our plugin.
		$total = (int) get_post_meta( $product_id, '_wgdp_paid_qty_total', true );

		// For variable products, add each variation's own counter.
		// Variation counters track sales independently of the product-level counter
		// (a variation may or may not count toward the product threshold).
		if ( $product_obj->is_type( 'variable' ) ) {
			foreach ( $product_obj->get_children() as $variation_id ) {
				$var_qty = (int) get_post_meta( $variation_id, '_wgdp_variation_paid_qty_total', true );
				// Only add variation qty that is NOT already included in the product-level counter.
				if ( ! WGDP_Release_Gate::variation_counts_toward_product_threshold( $product_id, $variation_id ) ) {
					$total += $var_qty;
				}
			}
		}

		// Apply manual adjustments.
		$total += (int) $atts['additional'];
		$total -= (int) $atts['subtract'];

		return (string) max( 0, $total );
	}

	/**
	 * [wgdp_min_sales_qty id="OPTIONAL" variation_id="OPTIONAL"]
	 *
	 * Displays the sales threshold quantity for a product or variation.
	 * If variation_id is provided and that variation has its own min_sales_qty
	 * release mode, returns the variation's threshold; otherwise returns the
	 * product-level threshold.
	 */
	public function min_sales_qty_shortcode( $atts ) {
		global $product;

		$atts = shortcode_atts(
			array(
				'id'           => 0,
				'variation_id' => 0,
			),
			$atts,
			'wgdp_min_sales_qty'
		);

		$product_id   = (int) $atts['id'];
		$variation_id = (int) $atts['variation_id'];

		if ( ! $product_id && $product instanceof WC_Product ) {
			$product_id = $product->get_id();
		}

		if ( ! $product_id ) {
			$product_id = (int) get_the_ID();
		}

		// A variation ID alone is enough to resolve a min_sales_qty threshold
		// (see get_effective_threshold_qty()), so derive the parent product ID
		// from it before giving up — e.g. when the shortcode runs outside a
		// single-product context (widget, email template, etc).
		if ( ! $product_id && $variation_id ) {
			$variation_obj = wc_get_product( $variation_id );
			if ( $variation_obj ) {
				$product_id = $variation_obj->get_parent_id();
			}
		}

		if ( ! $product_id ) {
			return '0';
		}

		$threshold = WGDP_Release_Gate::get_effective_threshold_qty( $product_id, $variation_id );

		return (string) max( 0, $threshold );
	}
}
