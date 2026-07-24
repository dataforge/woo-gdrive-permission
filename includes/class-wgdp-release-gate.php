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
				$scope = ! empty( $scope ) ? $scope : 'entire_product';

				// A variation excluded from the product threshold can never satisfy
				// an 'entire_product' scoped gate — the product-level counter never
				// includes its own sales (see variation_counts_toward_product_threshold()).
				// Fall back to the variation's own counter so it can still release.
				if ( 'entire_product' === $scope && ! self::variation_counts_toward_product_threshold( $product_id, $variation_id ) ) {
					return 'this_variation_only';
				}

				return $scope;
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
				// Misconfiguration: min_sales_qty mode selected but no positive
				// threshold set. Keep the gate closed rather than silently
				// auto-releasing as if the mode were 'immediate'. An admin who
				// wants immediate release should choose the 'immediate' mode.
				return false;
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
		self::with_paid_qty_lock( $product_id, function () use ( $product_id, $delta ) {
			self::atomic_increment_meta( $product_id, '_wgdp_paid_qty_total', $delta );
		} );

		if ( $delta > 0 ) {
			self::maybe_trigger_release( $product_id );
		}
	}

	/**
	 * Execute a callback while holding a per-product paid-qty counter lock.
	 *
	 * Serializes recalculate_sales_counter()'s scan-then-overwrite against
	 * concurrent atomic increments so a recalc can't clobber an in-flight
	 * increment (or vice versa). Falls back to running unlocked if the lock
	 * can't be acquired, matching prior (unlocked) behavior.
	 */
	private static function with_paid_qty_lock( $product_id, $callback ) {
		$lock_name = 'wgdp_paid_qty_' . absint( $product_id );
		$failed    = new stdClass();
		$result    = WGDP_DB::with_named_lock( $lock_name, 10, $callback, $failed );

		if ( $failed === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'WGDP: could not acquire paid-qty lock for product %d; proceeding without lock.', $product_id ) );
			return $callback();
		}

		return $result;
	}

	/**
	 * Execute a callback while holding a per-variation paid-qty counter lock.
	 */
	private static function with_variation_paid_qty_lock( $variation_id, $callback ) {
		$lock_name = 'wgdp_variation_paid_qty_' . absint( $variation_id );
		$failed    = new stdClass();
		$result    = WGDP_DB::with_named_lock( $lock_name, 10, $callback, $failed );

		if ( $failed === $result ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'WGDP: could not acquire paid-qty lock for variation %d; proceeding without lock.', $variation_id ) );
			return $callback();
		}

		return $result;
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
		self::with_paid_qty_lock( $product_id, function () use ( $product_id ) {
			$total = 0;

			self::each_order_with_counted_items( function ( $order ) use ( $product_id, &$total ) {
				$counted_ids = self::get_counted_item_ids( $order );
				if ( empty( $counted_ids ) ) {
					return;
				}
				foreach ( $order->get_items() as $item ) {
					if ( ! in_array( (int) $item->get_id(), $counted_ids, true ) ) {
						continue;
					}
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
			} );

			update_post_meta( $product_id, '_wgdp_paid_qty_total', max( 0, $total ) );
		} );

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
		self::with_variation_paid_qty_lock( $variation_id, function () use ( $variation_id, $delta ) {
			self::atomic_increment_meta( $variation_id, '_wgdp_variation_paid_qty_total', $delta );
		} );

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
	 *
	 * @return bool True if this call actually released the variation, false if it was a no-op.
	 */
	public static function release_variation( $product_id, $variation_id ) {
		// Only variations with their own gate (manual_release or min_sales_qty)
		// have a latch that is_item_released() actually reads via
		// get_release_latch_id(). A variation set to inherit_from_product is
		// gated by the product's own mode/latch, so releasing it here would
		// write to a meta key nothing checks while still force-granting its
		// pending entitlements below — bypassing the product-level gate.
		$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
		if ( empty( $var_mode ) || 'inherit_from_product' === $var_mode ) {
			return false;
		}

		if ( '1' === get_post_meta( $variation_id, '_wgdp_is_released', true ) ) {
			return false;
		}

		update_post_meta( $variation_id, '_wgdp_is_released', '1' );
		update_post_meta( $variation_id, '_wgdp_released_at', gmdate( 'Y-m-d H:i:s' ) );

		self::batch_grant_pending_release_for_variation( $product_id, $variation_id );

		do_action( 'wgdp_variation_released', $product_id, $variation_id );

		return true;
	}

	/**
	 * Recalculate the variation-level sales counter from order data.
	 */
	public static function recalculate_variation_sales_counter( $product_id, $variation_id ) {
		if ( ! $variation_id ) {
			return;
		}

		self::with_variation_paid_qty_lock( $variation_id, function () use ( $product_id, $variation_id ) {
			$total = 0;

			self::each_order_with_counted_items( function ( $order ) use ( $product_id, $variation_id, &$total ) {
				$counted_ids = self::get_counted_item_ids( $order );
				if ( empty( $counted_ids ) ) {
					return;
				}
				foreach ( $order->get_items() as $item ) {
					if ( ! in_array( (int) $item->get_id(), $counted_ids, true ) ) {
						continue;
					}
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
			} );

			update_post_meta( $variation_id, '_wgdp_variation_paid_qty_total', max( 0, $total ) );
		} );

		self::maybe_trigger_variation_release( $product_id, $variation_id );
	}

	/**
	 * Walk paid orders that have WGDP per-item sales counter markers, in batches.
	 *
	 * Using WooCommerce's order API keeps recalculation compatible with both HPOS
	 * and legacy post-backed order storage. Orders are paged rather than loaded
	 * with limit => -1 so a store with tens of thousands of counted orders never
	 * holds them all as hydrated WC_Order objects at once.
	 *
	 * @param callable $callback Invoked once per WC_Order.
	 */
	private static function each_order_with_counted_items( $callback ) {
		$batch_size = 200;
		$max_pages  = 500;
		$paged      = 1;

		do {
			$orders = wc_get_orders( array(
				'status'     => array( 'processing', 'completed' ),
				'limit'      => $batch_size,
				'paged'      => $paged,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'objects',
				'meta_query' => array(
					array(
						'key'     => '_wgdp_qty_counted_items',
						'compare' => 'EXISTS',
					),
				),
			) );

			foreach ( $orders as $order ) {
				$callback( $order );
			}

			$paged++;

			// Backstop: if the datastore ever silently ignores paged/orderby (e.g. an
			// HPOS/legacy-storage mismatch), every iteration would return the same
			// batch forever. Bail out rather than spinning until the PHP time limit.
			if ( $paged > $max_pages ) {
				break;
			}
		} while ( count( $orders ) === $batch_size );
	}

	/**
	 * Decode the order item IDs previously counted for WGDP release thresholds.
	 *
	 * @param WC_Order $order Order being recalculated.
	 * @return int[]
	 */
	private static function get_counted_item_ids( $order ) {
		$counted_json = $order->get_meta( '_wgdp_qty_counted_items' );
		$counted_ids  = ! empty( $counted_json ) ? json_decode( $counted_json, true ) : array();
		if ( ! is_array( $counted_ids ) ) {
			return array();
		}
		return array_map( 'absint', $counted_ids );
	}

	/* =====================================================================
	 * Batch grant helpers
	 * =================================================================== */

	/**
	 * Resolve a display name for a Drive resource from product metadata.
	 */
	private static function get_resource_name_for_row( $row ) {
		$resources = WGDP_Product_Meta::get_drive_resources( $row['product_id'], $row['variation_id'] ?: 0 );
		foreach ( $resources as $resource ) {
			if ( $resource['id'] === $row['cloud_asset_id'] ) {
				return ! empty( $resource['name'] ) ? $resource['name'] : $resource['id'];
			}
		}
		return $row['cloud_asset_id'];
	}

	/**
	 * Check if a pending entitlement targets a resource retired in product meta.
	 */
	private static function is_resource_retired_for_row( $row ) {
		$resources = WGDP_Product_Meta::get_drive_resources( $row['product_id'], $row['variation_id'] ?: 0 );
		foreach ( $resources as $resource ) {
			if ( $resource['id'] === $row['cloud_asset_id'] ) {
				return ! empty( $resource['status'] ) && 'active' !== $resource['status'];
			}
		}
		// Not present in the product's resource set at all — treat as removed,
		// not as "still active", so a detached file is never (re-)granted.
		return true;
	}

	/**
	 * Collect a granted entitlement into a recipient/order-item batch email group.
	 */
	private static function collect_granted_for_email( &$granted_by_recipient, $row ) {
		$resource_type = WGDP_Entitlements::get_resource_type( $row );
		$drive_link    = WGDP_Google_Drive::build_web_link( $row['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
		$key           = $row['order_item_id'] . '|' . $row['recipient_email'];

		if ( ! isset( $granted_by_recipient[ $key ] ) ) {
			$granted_by_recipient[ $key ] = array(
				'email'        => $row['recipient_email'],
				'product_name' => WGDP_Entitlements::get_product_name( $row ),
				'order_id'     => $row['order_id'],
				'links'        => array(),
			);
		}

		$granted_by_recipient[ $key ]['links'][] = array(
			'name' => self::get_resource_name_for_row( $row ),
			'link' => $drive_link,
		);
	}

	/**
	 * Send grouped access emails after a release batch grants one or more files.
	 */
	private static function send_grouped_access_emails( $granted_by_recipient ) {
		foreach ( $granted_by_recipient as $group ) {
			$recipients = array( $group['email'] );
			$billing    = WGDP_Notification_Email::get_billing_email_if_different( $group['order_id'], $group['email'] );
			if ( $billing ) {
				$recipients[] = $billing;
			}

			foreach ( $recipients as $to ) {
				if ( count( $group['links'] ) > 1 ) {
					WGDP_Notification_Email::send_access_granted_batch( $to, $group['links'], $group['product_name'] );
				} else {
					WGDP_Notification_Email::send_access_granted( $to, $group['links'][0]['link'], $group['product_name'] );
				}
			}
		}
	}

	/**
	 * Batch grant all verified + pending_release entitlements for a product.
	 *
	 * Checks per-variation release status before granting.
	 */
	public static function batch_grant_pending_release( $product_id ) {
		$ent        = WGDP_Entitlements::instance();
		$iterations = 0;
		$max_iterations = 50;
		$order_ids_to_check    = array();
		$granted_by_recipient = array();
		$after_id             = 0;

		do {
			$rows = $ent->get_pending_release_for_product( $product_id, 100, $after_id );

			foreach ( $rows as $row ) {
				$after_id = max( $after_id, (int) $row['id'] );

				// Check per-variation release status.
				$vid = (int) ( $row['variation_id'] ?? 0 );
				if ( ! self::is_item_released( $product_id, $vid ) ) {
					// Not yet released at variation level — skip but don't error.
					continue;
				}
				if ( self::is_resource_retired_for_row( $row ) ) {
					$ent->mark_revoked( $row['id'], WGDP_Entitlements::REVOCATION_REASON_ASSET_REMOVED );
					continue;
				}

				$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row, true );
				if ( is_wp_error( $result ) ) {
					$ent->mark_error( $row['id'], $result->get_error_message() );
				} else {
					$order_ids_to_check[ $row['order_id'] ] = true;
					// null means the entitlement was already granted (e.g. by an
					// overlapping cron retry pass) — not a fresh grant, so don't
					// queue a duplicate access-granted email for it.
					if ( null !== $result ) {
						self::collect_granted_for_email( $granted_by_recipient, $row );
					}
				}
			}

			if ( empty( $rows ) ) {
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

		self::send_grouped_access_emails( $granted_by_recipient );
		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Batch grant pending_release entitlements for a specific variation.
	 */
	public static function batch_grant_pending_release_for_variation( $product_id, $variation_id ) {
		$ent        = WGDP_Entitlements::instance();
		$iterations = 0;
		$max_iterations = 50;
		$order_ids_to_check    = array();
		$granted_by_recipient = array();
		$after_id             = 0;

		do {
			$rows = $ent->get_pending_release_for_variation( $product_id, $variation_id, 100, $after_id );

			foreach ( $rows as $row ) {
				$after_id = max( $after_id, (int) $row['id'] );

				if ( ! self::is_item_released( $product_id, $variation_id ) ) {
					// Not actually released — skip but don't error.
					continue;
				}
				if ( self::is_resource_retired_for_row( $row ) ) {
					$ent->mark_revoked( $row['id'], WGDP_Entitlements::REVOCATION_REASON_ASSET_REMOVED );
					continue;
				}
				$result   = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row, true );
				if ( is_wp_error( $result ) ) {
					$ent->mark_error( $row['id'], $result->get_error_message() );
				} else {
					$order_ids_to_check[ $row['order_id'] ] = true;
					// null means the entitlement was already granted (e.g. by an
					// overlapping cron retry pass) — not a fresh grant, so don't
					// queue a duplicate access-granted email for it.
					if ( null !== $result ) {
						self::collect_granted_for_email( $granted_by_recipient, $row );
					}
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

		self::send_grouped_access_emails( $granted_by_recipient );
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
				"UPDATE {$wpdb->postmeta} SET meta_value = GREATEST(0, COALESCE(CAST(meta_value AS SIGNED), 0) + %d) WHERE post_id = %d AND meta_key = %s",
				$delta,
				$post_id,
				$meta_key
			)
		);

		if ( false === $updated ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'WGDP: atomic_increment_meta UPDATE failed for post %d, meta %s: %s', $post_id, $meta_key, $wpdb->last_error ) );
		}

		if ( ! $updated ) {
			// $updated is 0 (no rows changed) either because the meta row doesn't exist
			// yet, or because it already matched the GREATEST(0, ...) floor/ceiling — the
			// value is correct either way, so only add_post_meta's own failure is worth logging.
			$added = add_post_meta( $post_id, $meta_key, max( 0, $delta ), true );
			if ( ! $added && ! metadata_exists( 'post', $post_id, $meta_key ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'WGDP: atomic_increment_meta could not create meta %s for post %d.', $meta_key, $post_id ) );
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

		if ( ! self::release_variation( $product_id, $variation_id ) ) {
			wp_send_json_error( 'This variation inherits its release from the parent product (or is already released); release the product instead.' );
		}
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
