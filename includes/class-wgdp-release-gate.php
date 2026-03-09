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
		add_action( 'wp_ajax_wgdp_release_variation_now', array( $this, 'ajax_release_variation_now' ) );
		add_action( 'wp_ajax_wgdp_recalculate_variation_sales', array( $this, 'ajax_recalculate_variation_sales' ) );
	}

	/* =====================================================================
	 * Centralized release resolution helpers
	 * =================================================================== */

	/**
	 * Get the effective release mode for a product/variation.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @return string 'immediate', 'manual_release', or 'min_sales_qty'.
	 */
	public static function get_effective_release_mode( $product_id, $variation_id = 0 ) {
		if ( $variation_id ) {
			$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
			if ( ! empty( $var_mode ) && 'inherit_from_product' !== $var_mode ) {
				return $var_mode;
			}
		}
		$mode = get_post_meta( $product_id, '_wgdp_release_mode', true );
		return ! empty( $mode ) ? $mode : 'immediate';
	}

	/**
	 * Get the effective threshold quantity for a product/variation.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @return int Threshold quantity.
	 */
	public static function get_effective_threshold_qty( $product_id, $variation_id = 0 ) {
		if ( $variation_id ) {
			$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
			if ( 'min_sales_qty' === $var_mode ) {
				return (int) get_post_meta( $variation_id, '_wgdp_threshold_qty', true );
			}
		}
		return (int) get_post_meta( $product_id, '_wgdp_threshold_qty', true );
	}

	/**
	 * Get the effective threshold scope for a product/variation.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @return string 'entire_product' or 'this_variation_only'.
	 */
	public static function get_effective_threshold_scope( $product_id, $variation_id = 0 ) {
		if ( $variation_id ) {
			$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
			if ( 'min_sales_qty' === $var_mode ) {
				$scope = get_post_meta( $variation_id, '_wgdp_threshold_scope', true );
				return ! empty( $scope ) ? $scope : 'entire_product';
			}
		}
		return 'entire_product';
	}

	/**
	 * Check whether a variation's sales count toward the product-level threshold.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @return bool True if this variation's sales should be counted in the product total.
	 */
	public static function variation_counts_toward_product_threshold( $product_id, $variation_id = 0 ) {
		if ( ! $variation_id ) {
			return true;
		}
		$val = get_post_meta( $variation_id, '_wgdp_counts_toward_product_threshold', true );
		// Default to yes for backward compatibility.
		return '' === $val || 'yes' === $val;
	}

	/**
	 * Get the current qualifying sales quantity for release gating.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @return int Current qualifying quantity.
	 */
	public static function get_current_qualifying_qty( $product_id, $variation_id = 0 ) {
		$scope = self::get_effective_threshold_scope( $product_id, $variation_id );

		if ( 'this_variation_only' === $scope && $variation_id ) {
			return (int) get_post_meta( $variation_id, '_wgdp_variation_paid_qty_total', true );
		}

		// entire_product — use the product-level counter (which already excludes
		// variations marked counts_toward_product_threshold = no).
		return (int) get_post_meta( $product_id, '_wgdp_paid_qty_total', true );
	}

	/**
	 * Single source of truth: is this item released?
	 *
	 * All release checks should route through this method.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 for simple products).
	 * @return bool True if released.
	 */
	public static function is_item_released( $product_id, $variation_id = 0 ) {
		$mode = self::get_effective_release_mode( $product_id, $variation_id );

		if ( 'immediate' === $mode ) {
			return true;
		}

		// Determine which post holds the release latch.
		$release_post_id = self::get_release_latch_id( $product_id, $variation_id );

		if ( 'manual_release' === $mode ) {
			return '1' === get_post_meta( $release_post_id, '_wgdp_is_released', true );
		}

		if ( 'min_sales_qty' === $mode ) {
			// Already explicitly released (latch set).
			if ( '1' === get_post_meta( $release_post_id, '_wgdp_is_released', true ) ) {
				return true;
			}
			// Compare counter vs threshold.
			$threshold = self::get_effective_threshold_qty( $product_id, $variation_id );
			if ( $threshold <= 0 ) {
				return true;
			}
			$current = self::get_current_qualifying_qty( $product_id, $variation_id );
			return $current >= $threshold;
		}

		return false;
	}

	/**
	 * Get the post ID that holds the release latch for an item.
	 *
	 * If the variation has its own release mode override, the latch lives on
	 * the variation. Otherwise it lives on the product.
	 */
	private static function get_release_latch_id( $product_id, $variation_id = 0 ) {
		if ( $variation_id ) {
			$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
			if ( ! empty( $var_mode ) && 'inherit_from_product' !== $var_mode ) {
				return $variation_id;
			}
		}
		return $product_id;
	}

	/* =====================================================================
	 * Sales counter — product level
	 * =================================================================== */

	/**
	 * Atomically increment the paid qty counter for a product.
	 */
	public static function increment_paid_qty( $product_id, $delta ) {
		self::atomic_increment_meta( $product_id, '_wgdp_paid_qty_total', $delta );

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
	 * Recalculate the product-level sales counter from order data.
	 *
	 * Respects per-variation counts_toward_product_threshold setting.
	 */
	public static function recalculate_sales_counter( $product_id ) {
		global $wpdb;

		$total = 0;

		$order_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT om.order_id
			 FROM {$wpdb->prefix}wc_orders_meta om
			 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = om.order_id
			 WHERE om.meta_key = '_wgdp_qty_counted_items'
			   AND o.status IN ('wc-processing', 'wc-completed')"
		);

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
				if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
					continue;
				}

				// Skip variations that don't count toward product threshold.
				if ( ! self::variation_counts_toward_product_threshold( $product_id, $variation_id ?: 0 ) ) {
					continue;
				}

				$qty_ordered  = $item->get_quantity();
				$qty_refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				$total       += max( 0, $qty_ordered - $qty_refunded );
			}
		}

		update_post_meta( $product_id, '_wgdp_paid_qty_total', max( 0, $total ) );
		self::maybe_trigger_release( $product_id );
	}

	/* =====================================================================
	 * Sales counter — variation level
	 * =================================================================== */

	/**
	 * Atomically increment the paid qty counter for a variation.
	 */
	public static function increment_variation_paid_qty( $product_id, $variation_id, $delta ) {
		if ( ! $variation_id ) {
			return;
		}
		self::atomic_increment_meta( $variation_id, '_wgdp_variation_paid_qty_total', $delta );

		if ( $delta > 0 ) {
			self::maybe_trigger_variation_release( $product_id, $variation_id );
		}
	}

	/**
	 * Check if a variation's own sales threshold has been met and trigger release.
	 */
	public static function maybe_trigger_variation_release( $product_id, $variation_id ) {
		if ( ! $variation_id ) {
			return;
		}
		$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
		if ( 'min_sales_qty' !== $var_mode ) {
			return;
		}
		if ( '1' === get_post_meta( $variation_id, '_wgdp_is_released', true ) ) {
			return;
		}

		$threshold = (int) get_post_meta( $variation_id, '_wgdp_threshold_qty', true );
		$current   = self::get_current_qualifying_qty( $product_id, $variation_id );

		if ( $threshold > 0 && $current >= $threshold ) {
			self::release_variation( $product_id, $variation_id );
		}
	}

	/**
	 * Release a variation's digital content (one-way latch).
	 */
	public static function release_variation( $product_id, $variation_id ) {
		if ( '1' === get_post_meta( $variation_id, '_wgdp_is_released', true ) ) {
			return;
		}

		update_post_meta( $variation_id, '_wgdp_is_released', '1' );
		update_post_meta( $variation_id, '_wgdp_released_at', gmdate( 'Y-m-d H:i:s' ) );

		self::batch_grant_pending_release_for_variation( $product_id, $variation_id );

		do_action( 'wgdp_variation_released', $product_id, $variation_id );
	}

	/**
	 * Recalculate the variation-level sales counter from order data.
	 */
	public static function recalculate_variation_sales_counter( $product_id, $variation_id ) {
		global $wpdb;

		if ( ! $variation_id ) {
			return;
		}

		$total = 0;

		$order_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT om.order_id
			 FROM {$wpdb->prefix}wc_orders_meta om
			 INNER JOIN {$wpdb->prefix}wc_orders o ON o.id = om.order_id
			 WHERE om.meta_key = '_wgdp_qty_counted_items'
			   AND o.status IN ('wc-processing', 'wc-completed')"
		);

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() !== (int) $product_id ) {
					continue;
				}
				if ( (int) $item->get_variation_id() !== (int) $variation_id ) {
					continue;
				}
				if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ) ) {
					continue;
				}

				$qty_ordered  = $item->get_quantity();
				$qty_refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
				$total       += max( 0, $qty_ordered - $qty_refunded );
			}
		}

		update_post_meta( $variation_id, '_wgdp_variation_paid_qty_total', max( 0, $total ) );
		self::maybe_trigger_variation_release( $product_id, $variation_id );
	}

	/* =====================================================================
	 * Batch grant helpers
	 * =================================================================== */

	/**
	 * Batch grant all verified + pending_release entitlements for a product.
	 *
	 * Checks per-variation release status before granting.
	 */
	public static function batch_grant_pending_release( $product_id ) {
		$ent        = WGDP_Entitlements::instance();
		$iterations = 0;
		$max_iterations = 50;
		$order_ids_to_check = array();

		do {
			$rows = $ent->get_pending_release_for_product( $product_id, 100 );

			$processed_any = false;
			foreach ( $rows as $row ) {
				// Check per-variation release status.
				$vid = (int) ( $row['variation_id'] ?? 0 );
				if ( ! self::is_item_released( $product_id, $vid ) ) {
					// Not yet released at variation level — skip but don't error.
					continue;
				}

				$processed_any = true;
				$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row );
				if ( is_wp_error( $result ) ) {
					$ent->mark_error( $row['id'], $result->get_error_message() );
				} else {
					$order_ids_to_check[ $row['order_id'] ] = true;
				}
			}

			// If no rows could be processed (all belong to unreleased variations),
			// stop looping to avoid re-fetching the same rows.
			if ( ! $processed_any ) {
				break;
			}

			$iterations++;
			if ( $iterations >= $max_iterations ) {
				break;
			}
		} while ( count( $rows ) >= 100 );

		$handler = WGDP_Order_Handler::instance();
		foreach ( array_keys( $order_ids_to_check ) as $oid ) {
			$handler->maybe_auto_complete_order( $oid );
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Batch grant pending_release entitlements for a specific variation.
	 */
	public static function batch_grant_pending_release_for_variation( $product_id, $variation_id ) {
		$ent        = WGDP_Entitlements::instance();
		$iterations = 0;
		$max_iterations = 50;
		$order_ids_to_check = array();

		do {
			$rows = $ent->get_pending_release_for_variation( $product_id, $variation_id, 100 );

			foreach ( $rows as $row ) {
				$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row );
				if ( is_wp_error( $result ) ) {
					$ent->mark_error( $row['id'], $result->get_error_message() );
				} else {
					$order_ids_to_check[ $row['order_id'] ] = true;
				}
			}

			$iterations++;
			if ( $iterations >= $max_iterations ) {
				break;
			}
		} while ( count( $rows ) >= 100 );

		$handler = WGDP_Order_Handler::instance();
		foreach ( array_keys( $order_ids_to_check ) as $oid ) {
			$handler->maybe_auto_complete_order( $oid );
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/* =====================================================================
	 * Atomic meta increment helper
	 * =================================================================== */

	/**
	 * Atomically increment a numeric post meta value.
	 */
	private static function atomic_increment_meta( $post_id, $meta_key, $delta ) {
		global $wpdb;

		$delta = (int) $delta;
		if ( 0 === $delta ) {
			return;
		}

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d) WHERE post_id = %d AND meta_key = %s",
				$delta,
				$post_id,
				$meta_key
			)
		);

		if ( ! $updated ) {
			$added = add_post_meta( $post_id, $meta_key, max( 0, $delta ), true );
			if ( ! $added ) {
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, CAST(meta_value AS SIGNED) + %d) WHERE post_id = %d AND meta_key = %s",
						$delta,
						$post_id,
						$meta_key
					)
				);
			}
		}

		wp_cache_delete( $post_id, 'post_meta' );
	}

	/* =====================================================================
	 * AJAX handlers — product level
	 * =================================================================== */

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

	/* =====================================================================
	 * AJAX handlers — variation level
	 * =================================================================== */

	/**
	 * AJAX: Release a variation's digital content immediately.
	 */
	public function ajax_release_variation_now() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		if ( ! $variation_id ) {
			wp_send_json_error( 'Missing variation ID.' );
		}

		$product_id = wp_get_post_parent_id( $variation_id );
		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid variation.' );
		}

		self::release_variation( $product_id, $variation_id );
		wp_send_json_success( 'Variation digital content released.' );
	}

	/**
	 * AJAX: Recalculate the sales counter for a variation.
	 */
	public function ajax_recalculate_variation_sales() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$variation_id = absint( $_POST['variation_id'] ?? 0 );
		if ( ! $variation_id ) {
			wp_send_json_error( 'Missing variation ID.' );
		}

		$product_id = wp_get_post_parent_id( $variation_id );
		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid variation.' );
		}

		self::recalculate_variation_sales_counter( $product_id, $variation_id );

		$total = (int) get_post_meta( $variation_id, '_wgdp_variation_paid_qty_total', true );
		$is_released = '1' === get_post_meta( $variation_id, '_wgdp_is_released', true );

		wp_send_json_success( array(
			'total'       => $total,
			'is_released' => $is_released,
		) );
	}
}
