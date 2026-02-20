<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Release_Gate {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_wgdp_release_digital_now', array( $this, 'ajax_release_now' ) );
		add_action( 'wp_ajax_wgdp_recalculate_sales', array( $this, 'ajax_recalculate_sales' ) );
	}

	/**
	 * Check if a product's digital content is released.
	 */
	public static function is_product_released( $product_id ) {
		$mode = get_post_meta( $product_id, '_wgdp_release_mode', true );
		if ( empty( $mode ) || 'immediate' === $mode ) {
			return true;
		}
		return '1' === get_post_meta( $product_id, '_wgdp_is_released', true );
	}

	/**
	 * Atomically increment the paid qty counter for a product.
	 */
	public static function increment_paid_qty( $product_id, $delta ) {
		global $wpdb;

		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return;
		}

		// Atomic UPDATE — avoids read-then-write race under concurrent requests.
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d) WHERE post_id = %d AND meta_key = %s",
				$delta,
				$product_id,
				'_wgdp_paid_qty_total'
			)
		);

		if ( ! $updated ) {
			// Meta row doesn't exist yet — create it.
			$added = add_post_meta( $product_id, '_wgdp_paid_qty_total', max( 0, $delta ), true );

			if ( ! $added ) {
				// Another request created the row first (race) — retry the atomic UPDATE.
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d) WHERE post_id = %d AND meta_key = %s",
						$delta,
						$product_id,
						'_wgdp_paid_qty_total'
					)
				);
			}
		}

		// Invalidate WP object cache since we bypassed update_post_meta.
		wp_cache_delete( $product_id, 'post_meta' );

		if ( $delta > 0 ) {
			self::maybe_trigger_release( $product_id );
		}
	}

	/**
	 * Check if the sales threshold has been met and trigger release if so.
	 */
	public static function maybe_trigger_release( $product_id ) {
		$mode = get_post_meta( $product_id, '_wgdp_release_mode', true );
		if ( 'min_sales_qty' !== $mode ) {
			return;
		}
		if ( '1' === get_post_meta( $product_id, '_wgdp_is_released', true ) ) {
			return;
		}

		$threshold = (int) get_post_meta( $product_id, '_wgdp_threshold_qty', true );
		$total     = (int) get_post_meta( $product_id, '_wgdp_paid_qty_total', true );

		if ( $threshold > 0 && $total >= $threshold ) {
			self::release_product( $product_id );
		}
	}

	/**
	 * Release a product's digital content (one-way latch).
	 */
	public static function release_product( $product_id ) {
		if ( '1' === get_post_meta( $product_id, '_wgdp_is_released', true ) ) {
			return;
		}

		update_post_meta( $product_id, '_wgdp_is_released', '1' );
		update_post_meta( $product_id, '_wgdp_released_at', gmdate( 'Y-m-d H:i:s' ) );

		self::batch_grant_pending_release( $product_id );

		do_action( 'wgdp_product_released', $product_id );
	}

	/**
	 * Batch grant all verified + pending_release entitlements for a product.
	 */
	public static function batch_grant_pending_release( $product_id ) {
		$ent        = WGDP_Entitlements::instance();
		$iterations = 0;
		$max_iterations = 50; // Safety limit: 50 batches × 100 = 5,000 entitlements max per call.

		// Process in batches of 100 until none remain.
		do {
			$rows = $ent->get_pending_release_for_product( $product_id, 100 );

			foreach ( $rows as $row ) {
				$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row );
				if ( is_wp_error( $result ) ) {
					$ent->mark_error( $row['id'], $result->get_error_message() );
				}
			}

			$iterations++;
			if ( $iterations >= $max_iterations ) {
				break;
			}
		} while ( count( $rows ) >= 100 );

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Recalculate the sales counter from order data.
	 */
	public static function recalculate_sales_counter( $product_id ) {
		global $wpdb;

		$total = 0;

		// Query all orders that have _wgdp_qty_counted flag.
		$order_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT om.order_id
			 FROM {$wpdb->prefix}wc_orders_meta om
			 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = om.order_id
			 WHERE om.meta_key = '_wgdp_qty_counted'
			   AND o.status IN ('wc-processing', 'wc-completed')"
		);

		if ( empty( $order_ids ) ) {
			// Try legacy postmeta approach.
			$order_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT DISTINCT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wgdp_qty_counted'
				   AND p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-processing', 'wc-completed')"
			);
		}

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() !== (int) $product_id ) {
					continue;
				}

				$variation_id = $item->get_variation_id();
				if ( WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
					$qty_ordered  = $item->get_quantity();
					$qty_refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
					$total       += max( 0, $qty_ordered - $qty_refunded );
				}
			}
		}

		update_post_meta( $product_id, '_wgdp_paid_qty_total', max( 0, $total ) );
		self::maybe_trigger_release( $product_id );
	}

	/**
	 * AJAX: Release a product's digital content immediately.
	 */
	public function ajax_release_now() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( 'Missing product ID.' );
		}

		self::release_product( $product_id );
		wp_send_json_success( 'Digital content released.' );
	}

	/**
	 * AJAX: Recalculate the sales counter for a product.
	 */
	public function ajax_recalculate_sales() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$product_id = absint( $_POST['product_id'] ?? 0 );
		if ( ! $product_id ) {
			wp_send_json_error( 'Missing product ID.' );
		}

		self::recalculate_sales_counter( $product_id );

		$total = (int) get_post_meta( $product_id, '_wgdp_paid_qty_total', true );
		$is_released = '1' === get_post_meta( $product_id, '_wgdp_is_released', true );

		wp_send_json_success( array(
			'total'       => $total,
			'is_released' => $is_released,
		) );
	}
}
