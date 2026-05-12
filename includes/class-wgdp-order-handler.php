<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Order_Handler {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Entitlement creation on payment.
		add_action( 'woocommerce_order_status_processing', array( $this, 'create_entitlements' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_entitlements' ) );

		// Full revocation.
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revoke_all_entitlements' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'revoke_all_entitlements' ) );

		// Partial refund.
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_partial_refund' ), 10, 2 );
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'revoke_entitlements_for_deleted_order_item' ) );

		// Sales counter.
		add_action( 'woocommerce_order_status_processing', array( $this, 'update_sales_counter' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'update_sales_counter' ), 20 );

		// Optional integration with Woo Conditional Preorders.
		add_action( 'wcpr_order_charge_succeeded', array( $this, 'handle_preorder_charge_succeeded' ), 10, 2 );
		add_action( 'wcpr_reservation_cancelled_campaign_failed', array( $this, 'handle_preorder_reservation_cancelled' ), 20, 2 );
		add_action( 'wcpr_reservation_cancelled_admin', array( $this, 'handle_preorder_reservation_cancelled' ), 20, 2 );
		add_action( 'wcpr_reservation_cancelled_customer', array( $this, 'handle_preorder_reservation_cancelled' ), 20, 2 );
		add_action( 'woocommerce_order_status_wcpr-cancelled', array( $this, 'handle_preorder_terminal_status' ), 20, 2 );
		add_action( 'woocommerce_order_status_wcpr-campaign-failed', array( $this, 'handle_preorder_terminal_status' ), 20, 2 );

		// Admin meta box.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wgdp_resend_otp', array( $this, 'ajax_resend_otp' ) );
		add_action( 'wp_ajax_wgdp_revoke_entitlement', array( $this, 'ajax_revoke_entitlement' ) );
		add_action( 'wp_ajax_wgdp_add_entitlement', array( $this, 'ajax_add_entitlement' ) );
		add_action( 'wp_ajax_wgdp_refresh_order_recipients', array( $this, 'ajax_refresh_order_recipients' ) );

		// Tell WooCommerce that digital-only items don't need processing.
		add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'item_needs_processing' ), 10, 3 );

		// Admin assets on order screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_assets' ) );

		// Orders list column (HPOS).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_orders_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_orders_column' ), 10, 2 );

		// Orders list column (legacy posts storage).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_orders_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_orders_column' ), 10, 2 );
	}

	/**
	 * Detect Conditional Preorder reservation orders without requiring that plugin.
	 */
	private function is_preorder_order( $order ) {
		return $order instanceof WC_Order && (bool) $order->get_meta( '_wcpr_campaign_product_id' );
	}

	/**
	 * True only after the preorder plugin has captured the campaign charge.
	 */
	private function preorder_charge_succeeded( WC_Order $order ) {
		return 'yes' === $order->get_meta( '_wcpr_charge_succeeded' );
	}

	/**
	 * Initial preorder reservation/authorization events are not paid delivery events.
	 */
	private function should_defer_preorder_order( WC_Order $order ) {
		return $this->is_preorder_order( $order ) && ! $this->preorder_charge_succeeded( $order );
	}

	private function order_has_active_entitlements_or_counted_items( WC_Order $order ) {
		$counted_json = $order->get_meta( '_wgdp_qty_counted_items' );
		$counted_ids  = ! empty( $counted_json ) ? json_decode( $counted_json, true ) : array();
		if ( is_array( $counted_ids ) && ! empty( $counted_ids ) ) {
			return true;
		}

		$rows = WGDP_Entitlements::instance()->get_by_order( $order->get_id() );
		foreach ( $rows as $row ) {
			if ( 'revoked' !== $row['grant_status'] ) {
				return true;
			}
		}

		return false;
	}

	private function decode_order_json_meta( WC_Order $order, $key ) {
		$json = $order->get_meta( $key );
		$data = ! empty( $json ) ? json_decode( $json, true ) : array();
		return is_array( $data ) ? $data : array();
	}

	private function get_counted_item_ids( WC_Order $order ) {
		return array_map( 'absint', $this->decode_order_json_meta( $order, '_wgdp_qty_counted_items' ) );
	}

	private function get_counted_quantities( WC_Order $order ) {
		return array_map( 'absint', $this->decode_order_json_meta( $order, '_wgdp_qty_counted_quantities' ) );
	}

	private function get_refund_decremented_quantities( WC_Order $order ) {
		return array_map( 'absint', $this->decode_order_json_meta( $order, '_wgdp_qty_refund_decremented_quantities' ) );
	}

	private function get_refund_baseline_quantities( WC_Order $order ) {
		return array_map( 'absint', $this->decode_order_json_meta( $order, '_wgdp_qty_refund_baseline_quantities' ) );
	}

	private function get_effective_item_quantity( WC_Order $order, $item ) {
		$quantity     = (int) $item->get_quantity();
		$qty_refunded = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
		return max( 0, $quantity - $qty_refunded );
	}

	private function get_counted_quantity_for_item( WC_Order $order, $item, $counted_quantities ) {
		$item_id = (int) $item->get_id();
		if ( isset( $counted_quantities[ $item_id ] ) ) {
			return (int) $counted_quantities[ $item_id ];
		}
		if ( isset( $counted_quantities[ (string) $item_id ] ) ) {
			return (int) $counted_quantities[ (string) $item_id ];
		}
		return (int) $item->get_quantity();
	}

	private function add_counter_delta( &$product_deltas, &$variation_deltas, $product_id, $variation_id, $qty ) {
		$qty = (int) $qty;
		if ( $qty <= 0 ) {
			return;
		}

		if ( WGDP_Release_Gate::variation_counts_toward_product_threshold( $product_id, $variation_id ?: 0 ) ) {
			if ( ! isset( $product_deltas[ $product_id ] ) ) {
				$product_deltas[ $product_id ] = 0;
			}
			$product_deltas[ $product_id ] += $qty;
		}

		if ( $variation_id ) {
			$var_mode = get_post_meta( $variation_id, '_wgdp_release_mode', true );
			if ( 'min_sales_qty' === $var_mode ) {
				if ( ! isset( $variation_deltas[ $variation_id ] ) ) {
					$variation_deltas[ $variation_id ] = array( 'product_id' => $product_id, 'qty' => 0 );
				}
				$variation_deltas[ $variation_id ]['qty'] += $qty;
			}
		}
	}

	/**
	 * Create entitlements and send OTP emails for an order.
	 *
	 * Trigger-aware: detects whether this is a processing or completed event
	 * and only creates entitlements for items whose trigger matches.
	 * On completed, also processes on_payment items as fallback for orders
	 * that skip processing.
	 */
	public function create_entitlements( $order_id, $forced_event = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( $this->should_defer_preorder_order( $order ) ) {
			return;
		}

		// Detect trigger event from current action.
		$current = $forced_event ?: current_action();
		if ( 'woocommerce_order_status_completed' === $current || 'on_completion' === $current ) {
			$current_event = 'on_completion';
		} else {
			$current_event = 'on_payment';
		}

		$ent  = WGDP_Entitlements::instance();
		$auth = WGDP_Google_Auth::instance();

		$has_drive_items = false;
		$created_any     = false;

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$order_item_id = $item->get_id();

				// Get recipients from item meta.
			$recipients_json = $item->get_meta( '_wgdp_recipients' );
			if ( empty( $recipients_json ) ) {
				continue;
			}
			$recipients = json_decode( $recipients_json, true );
			if ( ! is_array( $recipients ) || empty( $recipients ) ) {
				continue;
			}
			$effective_qty = $this->get_effective_item_quantity( $order, $item );
			if ( $effective_qty <= 0 ) {
				continue;
			}
			$recipients = array_slice( $recipients, 0, $effective_qty );

			// Check if this item qualifies for digital entitlement.
			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			// Per-item trigger check.
			$item_trigger = WGDP_Product_Meta::get_entitlement_trigger( $product_id );
			if ( 'on_payment' === $current_event && 'on_completion' === $item_trigger ) {
				// This item wants completion, but we're on processing — skip.
				continue;
			}
			if ( 'on_completion' === $current_event && 'on_payment' === $item_trigger ) {
				// Fallback: on_payment items that weren't created during processing
				// (e.g. order skipped processing) — allow creation on completed.
				// Already handled by the existing-for-item check above.
			}

			// Fetch active resources (multi-file, excludes retired).
			$resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
			if ( empty( $resources ) ) {
				continue;
			}

			// Resolve account.
			$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );
			if ( empty( $account_id ) || ! $auth->is_account_connected( $account_id ) ) {
				$order->add_order_note( sprintf(
					'WGDP: No connected account for item "%s". Entitlements not created.',
					$item->get_name()
				) );
				continue;
			}

			$has_drive_items = true;

			foreach ( $recipients as $index => $email ) {
					$email = WGDP_Entitlements::normalize_email( $email );
				if ( ! is_email( $email ) ) {
					continue;
				}

				$result = $ent->create_entitlements_for_recipient( array(
					'order_id'        => $order_id,
					'order_item_id'   => $order_item_id,
					'product_id'      => $product_id,
					'variation_id'    => $variation_id ?: 0,
					'email'           => $email,
					'account_id'      => $account_id,
					'resources'       => $resources,
					'recipient_index' => $index + 1,
					'reuse_revoked'   => false,
				) );

					if ( is_wp_error( $result ) || ! empty( $result['already_exists'] ) ) {
						continue;
					}

					$mail_result = WGDP_Notification_Email::send_otp( $email, $result['tokens']['otp'], $result['tokens']['claim_token'], $order, $item );

					if ( is_wp_error( $mail_result ) ) {
						$order->add_order_note( sprintf(
							'WGDP: Entitlements created for %s on "%s", but verification email failed — %s',
							$email,
							$item->get_name(),
							$mail_result->get_error_message()
						) );
					} else {
						$order->add_order_note( sprintf(
							'WGDP: Verification email sent to %s for "%s" (%d file(s), primary entitlement #%d)',
							$email,
							$item->get_name(),
							$result['file_count'],
							$result['primary_id']
						) );
					}
					$created_any = true;
				}
		}

		if ( $has_drive_items && ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
			$order->update_meta_data( '_wgdp_has_drive_items', '1' );
			$order->save();
		}

		if ( $created_any ) {
			delete_transient( 'wgdp_permission_counts' );
		}
	}

	/**
	 * Process GDrive delivery after a conditional preorder campaign charge succeeds.
	 *
	 * The preorder plugin sets _wcpr_charge_succeeded before changing the order to
	 * processing, so the normal WooCommerce status hooks may already have handled
	 * this order. Treat the custom hook as an on-payment fallback; on-completion
	 * products still wait for the WooCommerce completed status.
	 */
	public function handle_preorder_charge_succeeded( $order, $product_id = 0 ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( absint( $order ) );
		}

		if ( ! $order instanceof WC_Order || ! $this->is_preorder_order( $order ) ) {
			return;
		}

		if ( ! $this->preorder_charge_succeeded( $order ) ) {
			return;
		}

		$this->create_entitlements( $order->get_id(), 'on_payment' );
		$this->update_sales_counter( $order->get_id(), 'on_payment' );
	}

	/**
	 * Revoke GDrive access when a conditional preorder reservation is cancelled.
	 */
	public function handle_preorder_reservation_cancelled( $order, $product_id = 0 ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( absint( $order ) );
		}

		if ( ! $order instanceof WC_Order || ! $this->is_preorder_order( $order ) ) {
			return;
		}

		if ( ! $this->order_has_active_entitlements_or_counted_items( $order ) ) {
			return;
		}

		$this->revoke_all_entitlements( $order->get_id() );
	}

	/**
	 * Revoke GDrive access if a preorder is moved directly into a terminal custom status.
	 */
	public function handle_preorder_terminal_status( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( absint( $order_id ) );
		}

		if ( ! $order instanceof WC_Order || ! $this->is_preorder_order( $order ) ) {
			return;
		}

		if ( ! $this->order_has_active_entitlements_or_counted_items( $order ) ) {
			return;
		}

		$this->revoke_all_entitlements( $order->get_id() );
	}

	/**
	 * Revoke all entitlements for an order (full refund/cancel).
	 */
	public function revoke_all_entitlements( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$result = $this->with_sales_counter_lock( $order_id, function () use ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$ent  = WGDP_Entitlements::instance();
			$rows = $ent->get_by_order( $order_id );
			$counted_ids            = $this->get_counted_item_ids( $order );
			$counted_quantities     = $this->get_counted_quantities( $order );
			$refund_decremented_qty = $this->get_refund_decremented_quantities( $order );

			$has_active_rows = false;
			foreach ( $rows as $row ) {
				if ( 'revoked' !== $row['grant_status'] ) {
					$has_active_rows = true;
					break;
				}
			}

			if ( ! $has_active_rows && empty( $counted_ids ) ) {
				return;
			}

			$drive_failures  = 0;
			$notified_emails = array(); // Track recipients to send one email each.

			foreach ( $rows as $row ) {
				if ( 'revoked' === $row['grant_status'] ) {
					continue;
				}

				$result = $ent->revoke_with_drive_delete( $row, WGDP_Entitlements::REVOCATION_REASON_ORDER_INELIGIBLE );
				if ( is_wp_error( $result ) ) {
					$drive_failures++;
					$order->add_order_note( sprintf(
						'WGDP: Failed to revoke Drive access for %s — %s',
						$row['recipient_email'],
						$result->get_error_message()
					) );
					continue;
				}

				// Queue one notification per recipient.
				if ( ! isset( $notified_emails[ $row['recipient_email'] ] ) ) {
					$notified_emails[ $row['recipient_email'] ] = $row;
				}
			}

			// Send one revocation email per recipient.
			foreach ( $notified_emails as $email => $row ) {
				WGDP_Notification_Email::send_access_revoked( $email, WGDP_Entitlements::get_product_name( $row ), $row['order_id'] ?? 0 );
			}

			if ( $drive_failures > 0 ) {
				$order->add_order_note( sprintf( 'WGDP: All entitlements revoked (%d Drive permission removal(s) failed).', $drive_failures ) );
			} else {
				$order->add_order_note( 'WGDP: All entitlements revoked.' );
			}

			// Decrement sales counter for previously counted items.
			if ( ! empty( $counted_ids ) ) {
				$product_deltas   = array();
				$variation_deltas = array();
				foreach ( $order->get_items() as $item ) {
					$product_id    = $item->get_product_id();
					$variation_id  = $item->get_variation_id();
					$order_item_id = $item->get_id();

					if ( ! in_array( $order_item_id, $counted_ids, true ) ) {
						continue;
					}

					if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
						continue;
					}

					$counted_qty     = $this->get_counted_quantity_for_item( $order, $item, $counted_quantities );
					$already_removed = $refund_decremented_qty[ $order_item_id ] ?? ( $refund_decremented_qty[ (string) $order_item_id ] ?? 0 );
					$qty             = max( 0, $counted_qty - (int) $already_removed );

					$this->add_counter_delta( $product_deltas, $variation_deltas, $product_id, $variation_id, $qty );
				}
				foreach ( $product_deltas as $pid => $qty ) {
					WGDP_Release_Gate::increment_paid_qty( $pid, -$qty );
				}
				foreach ( $variation_deltas as $vid => $info ) {
					WGDP_Release_Gate::increment_variation_paid_qty( $info['product_id'], $vid, -$info['qty'] );
				}
				$order->delete_meta_data( '_wgdp_qty_counted_items' );
				$order->delete_meta_data( '_wgdp_qty_counted_quantities' );
				$order->delete_meta_data( '_wgdp_qty_refund_decremented_quantities' );
				$order->delete_meta_data( '_wgdp_qty_refund_baseline_quantities' );
				$order->save();
			}

			delete_transient( 'wgdp_permission_counts' );
		} );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'WGDP: Could not lock this order for entitlement revocation. Please retry the operation.' );
			return;
		}
	}

	/**
	 * Handle partial refund — revoke excess entitlements per item.
	 */
	public function handle_partial_refund( $order_id, $refund_id ) {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}

		// If order is fully refunded/cancelled, revoke_all_entitlements handles it via status hook.
		if ( in_array( $order->get_status(), array( 'refunded', 'cancelled' ), true ) ) {
			return;
		}

		// Detect full refund by amount — status hasn't changed yet at this point.
		$order_total    = (float) $order->get_total();
		$total_refunded = (float) $order->get_total_refunded();
		if ( $order_total > 0 && $total_refunded >= $order_total ) {
			$this->revoke_all_entitlements( $order_id );
			return;
		}

		$result = $this->with_sales_counter_lock( $order_id, function () use ( $order_id, $refund ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$ent   = WGDP_Entitlements::instance();

			$counted_ids            = $this->get_counted_item_ids( $order );
			$counted_quantities     = $this->get_counted_quantities( $order );
			$refund_decremented_qty = $this->get_refund_decremented_quantities( $order );
			$refund_baseline_qty    = $this->get_refund_baseline_quantities( $order );
			$product_deltas         = array();
			$variation_deltas       = array();

			foreach ( $refund->get_items() as $refund_item ) {
				$refunded_qty = abs( $refund_item->get_quantity() );
				if ( $refunded_qty <= 0 ) {
					continue;
				}

				// Find the matching original order item.
				$order_item_id = $refund_item->get_meta( '_refunded_item_id' );
				if ( ! $order_item_id ) {
					continue;
				}

				$order_item = $order->get_item( $order_item_id );
				if ( ! $order_item ) {
					continue;
				}

				// Decrement sales counter for qualifying refunded items.
				$product_id       = $order_item->get_product_id();
				$variation_id     = $order_item->get_variation_id();
				$item_was_counted = in_array( (int) $order_item_id, $counted_ids, true );
				if ( $item_was_counted && WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
					$counted_qty          = $this->get_counted_quantity_for_item( $order, $order_item, $counted_quantities );
					$total_refunded_qty   = abs( (int) $order->get_qty_refunded_for_item( $order_item_id ) );
					$already_removed      = (int) ( $refund_decremented_qty[ (int) $order_item_id ] ?? ( $refund_decremented_qty[ (string) $order_item_id ] ?? 0 ) );
					$baseline_refunded    = (int) ( $refund_baseline_qty[ (int) $order_item_id ] ?? ( $refund_baseline_qty[ (string) $order_item_id ] ?? 0 ) );
					$refunded_since_count = max( 0, $total_refunded_qty - $baseline_refunded );
					$newly_removed        = max( 0, min( $counted_qty, $refunded_since_count ) - $already_removed );
					if ( $newly_removed > 0 ) {
						$this->add_counter_delta( $product_deltas, $variation_deltas, $product_id, $variation_id, $newly_removed );
						$refund_decremented_qty[ (int) $order_item_id ] = $already_removed + $newly_removed;
					}
				}

				// Calculate: remaining = original qty - total refunded qty.
				$remaining    = $this->get_effective_item_quantity( $order, $order_item );
				$active_count = $ent->count_active_recipients_for_item( $order_item_id );

				if ( $active_count <= $remaining ) {
					continue;
				}

				$excess = $active_count - $remaining;
				// Get distinct recipient emails to revoke (limited to $excess recipients).
				$emails_to_revoke = $ent->get_revocation_candidates( $order_item_id, $excess );

				// Revoke all entitlements for each email.
				foreach ( $emails_to_revoke as $revoke_email ) {
					$sibling_rows = $ent->get_siblings( $order_item_id, $revoke_email );
					if ( empty( $sibling_rows ) ) {
						continue;
					}
					$failed_revoke = false;
					foreach ( $sibling_rows as $row ) {
						$result = $ent->revoke_with_drive_delete( $row, WGDP_Entitlements::REVOCATION_REASON_PARTIAL_REFUND );
						if ( is_wp_error( $result ) ) {
							$failed_revoke = true;
							$order->add_order_note( sprintf(
								'WGDP: Failed to revoke Drive access for %s — %s',
								$row['recipient_email'],
								$result->get_error_message()
							) );
						}
					}

					if ( $failed_revoke ) {
						$order->add_order_note( sprintf(
							'WGDP: Revocation for %s is pending retry because at least one Drive permission could not be removed.',
							$revoke_email
						) );
					} else {
						WGDP_Notification_Email::send_access_revoked( $revoke_email, WGDP_Entitlements::get_product_name( $sibling_rows[0] ), $sibling_rows[0]['order_id'] );
						$order->add_order_note( sprintf(
							'WGDP: Revoked all entitlements for %s (partial refund)',
							$revoke_email
						) );
					}
				}
			}

			// Apply sales counter decrements.
			foreach ( $product_deltas as $pid => $qty ) {
				WGDP_Release_Gate::increment_paid_qty( $pid, -$qty );
			}
			foreach ( $variation_deltas as $vid => $info ) {
				WGDP_Release_Gate::increment_variation_paid_qty( $info['product_id'], $vid, -$info['qty'] );
			}

			if ( ! empty( $refund_decremented_qty ) ) {
				$order->update_meta_data( '_wgdp_qty_refund_decremented_quantities', wp_json_encode( $refund_decremented_qty ) );
				$order->save();
			}

			delete_transient( 'wgdp_permission_counts' );
		} );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( 'WGDP: Could not lock this order for refund entitlement updates. Please retry the operation.' );
			return;
		}
	}

	/**
	 * Revoke Drive access when an order line item is deleted in admin.
	 *
	 * WooCommerce deletes the item row after this hook, so entitlement rows still
	 * have enough context to remove any granted Drive permission.
	 *
	 * @param int $order_item_id Order item ID being deleted.
	 */
	public function revoke_entitlements_for_deleted_order_item( $order_item_id ) {
		$order_item_id = absint( $order_item_id );
		if ( ! $order_item_id ) {
			return;
		}

		$item     = class_exists( 'WC_Order_Factory' ) ? WC_Order_Factory::get_order_item( $order_item_id ) : null;
		$order_id = $item && method_exists( $item, 'get_order_id' ) ? absint( $item->get_order_id() ) : 0;

		$ent = WGDP_Entitlements::instance();
		$ent->with_order_item_lock( $order_item_id, function () use ( $ent, $order_item_id, $order_id ) {
			$rows = $ent->get_by_order_item( $order_item_id );
			if ( empty( $rows ) ) {
				return;
			}

			$revoked_count = 0;
			$error_count   = 0;
			$order_id      = (int) ( $rows[0]['order_id'] ?? 0 );

			foreach ( $rows as $row ) {
				if ( 'revoked' === ( $row['grant_status'] ?? '' ) ) {
					continue;
				}

				$result = $ent->revoke_with_drive_delete( $row, WGDP_Entitlements::REVOCATION_REASON_ORDER_ITEM_REMOVED );
				if ( is_wp_error( $result ) ) {
					$error_count++;
					continue;
				}

				$revoked_count++;
			}

			delete_transient( 'wgdp_permission_counts' );

			$order = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order && ( $revoked_count || $error_count ) ) {
				$note = sprintf(
					'WGDP: Order item #%d was deleted. Revoked %d digital entitlement(s).',
					$order_item_id,
					$revoked_count
				);
				if ( $error_count ) {
					$note .= sprintf( ' %d entitlement(s) could not be fully removed from Drive and were marked for retry.', $error_count );
				}
				$order->add_order_note( $note );
			}
		} );

		$this->decrement_sales_counter_for_deleted_order_item( $order_id, $order_item_id, $item );
	}

	/**
	 * Remove a deleted line item from WGDP release-gate sales counters.
	 *
	 * @param int   $order_id      Order ID.
	 * @param int   $order_item_id Deleted order item ID.
	 * @param mixed $item          Order item before WooCommerce deletes it.
	 */
	private function decrement_sales_counter_for_deleted_order_item( $order_id, $order_item_id, $item ) {
		if ( ! $order_id || ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$result = $this->with_sales_counter_lock( $order_id, function () use ( $order_id, $order_item_id, $item ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$counted_ids = $this->get_counted_item_ids( $order );
			if ( ! in_array( $order_item_id, $counted_ids, true ) ) {
				return;
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				return;
			}

			$counted_quantities     = $this->get_counted_quantities( $order );
			$refund_decremented_qty = $this->get_refund_decremented_quantities( $order );
			$refund_baseline_qty    = $this->get_refund_baseline_quantities( $order );
			$counted_qty            = $this->get_counted_quantity_for_item( $order, $item, $counted_quantities );
			$product_deltas         = array();
			$variation_deltas       = array();

			$this->add_counter_delta( $product_deltas, $variation_deltas, $product_id, $variation_id, $counted_qty );

			foreach ( $product_deltas as $pid => $qty ) {
				WGDP_Release_Gate::increment_paid_qty( $pid, -$qty );
			}
			foreach ( $variation_deltas as $vid => $info ) {
				WGDP_Release_Gate::increment_variation_paid_qty( $info['product_id'], $vid, -$info['qty'] );
			}

			$counted_ids = array_values( array_filter( $counted_ids, function ( $id ) use ( $order_item_id ) {
				return (int) $id !== (int) $order_item_id;
			} ) );
			unset(
				$counted_quantities[ $order_item_id ],
				$counted_quantities[ (string) $order_item_id ],
				$refund_decremented_qty[ $order_item_id ],
				$refund_decremented_qty[ (string) $order_item_id ],
				$refund_baseline_qty[ $order_item_id ],
				$refund_baseline_qty[ (string) $order_item_id ]
			);

			$order->update_meta_data( '_wgdp_qty_counted_items', wp_json_encode( $counted_ids ) );
			$order->update_meta_data( '_wgdp_qty_counted_quantities', wp_json_encode( $counted_quantities ) );
			$order->update_meta_data( '_wgdp_qty_refund_decremented_quantities', wp_json_encode( $refund_decremented_qty ) );
			$order->update_meta_data( '_wgdp_qty_refund_baseline_quantities', wp_json_encode( $refund_baseline_qty ) );
			$order->add_order_note( sprintf(
				'WGDP: Removed order item #%d from digital release sales counters.',
				$order_item_id
			) );
			$order->save();
		} );

		if ( is_wp_error( $result ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'WGDP: Could not lock this order to update release sales counters after an item was deleted. Please recalculate counters.' );
			}
		}
	}

	/**
	 * Execute a callback while holding a per-order sales counter lock.
	 */
	private function with_sales_counter_lock( $order_id, $callback ) {
		global $wpdb;

		$lock_name = 'wgdp_sales_counter_' . absint( $order_id );
		$locked    = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock_name )
		);

		if ( '1' !== (string) $locked ) {
			return new WP_Error( 'wgdp_sales_counter_lock_failed', 'Could not lock this order for sales counter update.' );
		}

		try {
			return $callback();
		} finally {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
		}
	}

	/**
	 * Update the sales counter for qualifying items in an order.
	 *
	 * Uses per-item tracking via _wgdp_qty_counted_items (JSON array of item IDs)
	 * to prevent double-counting while supporting per-item trigger timing.
	 */
	public function update_sales_counter( $order_id, $forced_event = '' ) {
		// Detect trigger event from current action.
		$current = $forced_event ?: current_action();
		if ( 'woocommerce_order_status_completed' === $current || 'on_completion' === $current ) {
			$current_event = 'on_completion';
		} else {
			$current_event = 'on_payment';
		}

		$result = $this->with_sales_counter_lock( $order_id, function () use ( $order_id, $current_event ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			if ( $this->should_defer_preorder_order( $order ) ) {
				return;
			}

			// Load already-counted item IDs and net quantities.
			$counted_ids            = $this->get_counted_item_ids( $order );
			$counted_quantities     = $this->get_counted_quantities( $order );
			$refund_decremented_qty = $this->get_refund_decremented_quantities( $order );
			$refund_baseline_qty    = $this->get_refund_baseline_quantities( $order );

			$product_deltas   = array(); // product_id => qty (only counting variations).
			$variation_deltas = array(); // variation_id => ['product_id' => int, 'qty' => int].
			$new_counted      = array();

			foreach ( $order->get_items() as $item ) {
				$product_id    = $item->get_product_id();
				$variation_id  = $item->get_variation_id();
				$order_item_id = $item->get_id();

				// Skip if already counted.
				if ( in_array( $order_item_id, $counted_ids, true ) ) {
					continue;
				}

				if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
					continue;
				}

				// Per-item trigger check (same logic as create_entitlements).
				$item_trigger = WGDP_Product_Meta::get_entitlement_trigger( $product_id );
				if ( 'on_payment' === $current_event && 'on_completion' === $item_trigger ) {
					continue;
				}

				$qty = $this->get_effective_item_quantity( $order, $item );
				if ( $qty <= 0 ) {
					continue;
				}

				$this->add_counter_delta( $product_deltas, $variation_deltas, $product_id, $variation_id, $qty );

				$new_counted[] = $order_item_id;
				$counted_quantities[ (int) $order_item_id ] = $qty;
				$refund_decremented_qty[ (int) $order_item_id ] = 0;
				$refund_baseline_qty[ (int) $order_item_id ] = abs( (int) $order->get_qty_refunded_for_item( $order_item_id ) );
			}

			if ( empty( $product_deltas ) && empty( $variation_deltas ) && empty( $new_counted ) ) {
				return;
			}

			// Increment product-level counters.
			foreach ( $product_deltas as $pid => $qty ) {
				WGDP_Release_Gate::increment_paid_qty( $pid, $qty );
			}

			// Increment variation-level counters.
			foreach ( $variation_deltas as $vid => $info ) {
				WGDP_Release_Gate::increment_variation_paid_qty( $info['product_id'], $vid, $info['qty'] );
			}

			$counted_ids = array_merge( $counted_ids, $new_counted );
			$order->update_meta_data( '_wgdp_qty_counted_items', wp_json_encode( $counted_ids ) );
			$order->update_meta_data( '_wgdp_qty_counted_quantities', wp_json_encode( $counted_quantities ) );
			$order->update_meta_data( '_wgdp_qty_refund_decremented_quantities', wp_json_encode( $refund_decremented_qty ) );
			$order->update_meta_data( '_wgdp_qty_refund_baseline_quantities', wp_json_encode( $refund_baseline_qty ) );
			$order->save();
		} );

		if ( is_wp_error( $result ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->add_order_note( 'WGDP: Could not lock this order for sales counter update. Please retry the operation.' );
			}
		}
	}

	/**
	 * Tell WooCommerce that digital-only items (no shipping) don't need processing.
	 *
	 * When all items in an order return false, WooCommerce sets the order
	 * status to "completed" instead of "processing" after payment.
	 */
	public function item_needs_processing( $needs_processing, $product, $order_id ) {
		if ( ! $product ) {
			return $needs_processing;
		}

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order && $this->should_defer_preorder_order( $order ) ) {
				return true;
			}
		}

		$product_id   = $product->get_parent_id() ?: $product->get_id();
		$variation_id = $product->get_parent_id() ? $product->get_id() : 0;

		if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ) ) {
			return $needs_processing;
		}

		// If the item requires shipping, it still needs processing.
		if ( WGDP_Product_Meta::item_requires_shipping( $product_id, $variation_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Auto-complete a digital-only order if all entitlements are granted.
	 *
	 * Checks that every item in the order is digital-only (no shipping needed)
	 * and all entitlements have been granted before marking the order as completed.
	 */
	public function maybe_auto_complete_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only auto-complete orders that are currently processing.
		if ( 'processing' !== $order->get_status() ) {
			return;
		}

		$ent = WGDP_Entitlements::instance();

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			$qualifies = WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 );

			if ( $qualifies ) {
				// Item has digital access — check if it also needs shipping.
				if ( WGDP_Product_Meta::item_requires_shipping( $product_id, $variation_id ?: 0 ) ) {
					// Physical + digital item (e.g. DVD with digital) — order needs shipping.
					return;
				}

				// Digital-only — verify all entitlements for this item are granted.
				$item_entitlements = $ent->get_by_order_item( $item->get_id() );
				if ( empty( $item_entitlements ) ) {
					// No entitlements yet (might not be created) — not ready.
					return;
				}
				foreach ( $item_entitlements as $e ) {
					if ( 'revoked' === $e['grant_status'] ) {
						continue; // Revoked entitlements don't block completion.
					}
					if ( 'granted' !== $e['grant_status'] ) {
						return; // Not yet granted.
					}
				}
			} else {
				// Non-digital item — order needs shipping.
				return;
			}
		}

		// All items are digital-only and all entitlements granted.
		$order->update_status( 'completed', 'WGDP: Auto-completed — all digital entitlements granted.' );
	}

	/**
	 * Register the admin meta box on order edit screens.
	 */
	public function register_meta_box() {
		// HPOS screen.
		$screen = null;
		try {
			if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\Admin\Orders\PageController::class ) ) {
				$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\Admin\Orders\PageController::class );
				if ( method_exists( $controller, 'get_current_screen_id' ) ) {
					$screen = $controller->get_current_screen_id();
				}
			}
		} catch ( \Throwable $e ) {
			$screen = null;
		}

		if ( $screen ) {
			add_meta_box(
				'wgdp-recipients',
				'GDrive Digital Recipients',
				array( $this, 'render_meta_box' ),
				$screen,
				'normal',
				'default'
			);
		}

		// HPOS screen (generic).
		add_meta_box(
			'wgdp-recipients',
			'GDrive Digital Recipients',
			array( $this, 'render_meta_box' ),
			'woocommerce_page_wc-orders',
			'normal',
			'default'
		);

		// Legacy shop_order screen.
		add_meta_box(
			'wgdp-recipients',
			'GDrive Digital Recipients',
			array( $this, 'render_meta_box' ),
			'shop_order',
			'normal',
			'default'
		);
	}

	/**
	 * Render the meta box (HPOS).
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		echo '<div class="wgdp-order-recipients-content" data-order-id="' . esc_attr( $order->get_id() ) . '">';
		$this->render_meta_box_content( $order );
		echo '</div>';
	}

	/**
	 * Render the meta box content.
	 */
	private function render_meta_box_content( $order ) {
		if ( $this->should_defer_preorder_order( $order ) ) {
			echo '<p>Digital access is deferred until this preorder campaign is successfully charged.</p>';
			return;
		}

		$ent  = WGDP_Entitlements::instance();
		$rows = $ent->get_by_order( $order->get_id() );

		echo '<p style="margin:0 0 10px;"><button type="button" class="button button-small wgdp-refresh-order-recipients-btn" data-order-id="' . esc_attr( $order->get_id() ) . '">Refresh Digital Recipients</button></p>';

		// Group existing entitlements by order_item_id, then by recipient_email.
		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ $row['order_item_id'] ][ $row['recipient_email'] ][] = $row;
		}

		$has_qualifying_items = false;

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$order_item_id = $item->get_id();

			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				continue;
			}

			$has_qualifying_items = true;
			$by_email = isset( $grouped[ $order_item_id ] ) ? $grouped[ $order_item_id ] : array();

			echo '<h4 style="margin:12px 0 6px;">' . esc_html( $item->get_name() ) . '</h4>';

			if ( ! empty( $by_email ) ) {
				echo '<table class="widefat fixed striped wgdp-recipients-table" data-order-item-id="' . esc_attr( $order_item_id ) . '" style="margin-bottom:12px;">';
				echo '<thead><tr><th style="width:30px;">#</th><th>Email</th><th>Files</th><th>Verification</th><th>Grant</th><th>Actions</th></tr></thead>';
				echo '<tbody>';

				foreach ( $by_email as $email => $entitlements ) {
					$this->render_recipient_row( $entitlements );
				}

				echo '</tbody></table>';
			}

			// "Add Recipient" inline form.
			echo '<div class="wgdp-add-entitlement-form" style="margin-bottom:16px;">';
			echo '<input type="email" class="wgdp-add-email-input" placeholder="recipient@example.com" style="width:260px;margin-right:4px;" />';
			echo '<button type="button" class="button button-small wgdp-add-entitlement-btn" '
				. 'data-order-id="' . esc_attr( $order->get_id() ) . '" '
				. 'data-order-item-id="' . esc_attr( $order_item_id ) . '">'
				. 'Add Recipient</button>';
			echo '</div>';
		}

		if ( ! $has_qualifying_items ) {
			echo '<p>No saved items in this order qualify for digital access.</p>';
			echo '<p class="description">If you just added a SKU to a manual order, save/update the order items or refresh this box after WooCommerce finishes adding the item.</p>';
		}
	}

	/**
	 * AJAX: Re-render the order recipients meta box after WooCommerce order-item updates.
	 */
	public function ajax_refresh_order_recipients() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( 'Missing order ID.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		ob_start();
		$this->render_meta_box_content( $order );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'html' => $html,
		) );
	}

	/**
	 * Render a grouped recipient row in the meta box table.
	 *
	 * @param array $entitlements All entitlement rows for the same order_item_id + recipient_email.
	 */
	private function render_recipient_row( $entitlements ) {
		$active_entitlements = array_values( array_filter( $entitlements, function ( $row ) {
			return 'revoked' !== $row['grant_status'];
		} ) );
		$display_entitlements = ! empty( $active_entitlements ) ? $active_entitlements : $entitlements;
		$primary              = $display_entitlements[0];
		$file_count           = count( $display_entitlements );

		// Determine overall verification status (worst-case).
		$v_status = 'verified';
		foreach ( $display_entitlements as $e ) {
			if ( 'pending' === $e['verification_status'] || 'expired' === $e['verification_status'] ) {
				$v_status = $e['verification_status'];
				break;
			}
		}

		// Determine overall grant status.
		$g_statuses = array_unique( wp_list_pluck( $display_entitlements, 'grant_status' ) );
		if ( in_array( 'revocation_error', $g_statuses, true ) ) {
			$g_status = 'revocation_error';
		} elseif ( in_array( 'error', $g_statuses, true ) ) {
			$g_status = 'error';
		} elseif ( in_array( 'pending', $g_statuses, true ) ) {
			$g_status = 'pending';
		} elseif ( in_array( 'pending_release', $g_statuses, true ) ) {
			$g_status = 'pending_release';
		} elseif ( in_array( 'granted', $g_statuses, true ) ) {
			$g_status = 'granted';
		} elseif ( in_array( 'revoked', $g_statuses, true ) ) {
			$g_status = 'revoked';
		} else {
			$g_status = $g_statuses[0];
		}

		$v_class = 'wgdp-vstatus--' . esc_attr( $v_status );
		$g_class = 'wgdp-gstatus--' . esc_attr( $g_status );

		$grant_label = $g_status;
		if ( 'pending_release' === $grant_label ) {
			$grant_label = $this->are_pending_release_rows_released( $display_entitlements ) ? 'Queued for Grant' : 'Pending Release';
		} elseif ( 'revocation_error' === $grant_label ) {
			$grant_label = 'Revocation Error';
		} else {
			$grant_label = ucfirst( $grant_label );
		}

		echo '<tr>';
		echo '<td>' . esc_html( $primary['recipient_index'] ) . '</td>';
		echo '<td>' . esc_html( $primary['recipient_email'] ) . '</td>';
		echo '<td>' . esc_html( $file_count ) . ' file' . ( $file_count > 1 ? 's' : '' ) . '</td>';
		echo '<td><span class="wgdp-status-badge ' . $v_class . '">' . esc_html( ucfirst( $v_status ) ) . '</span></td>';
		echo '<td><span class="wgdp-status-badge ' . $g_class . '">' . esc_html( $grant_label ) . '</span>';

		// Show errors.
		foreach ( $entitlements as $e ) {
			if ( ! empty( $e['grant_error'] ) ) {
				echo '<br><small style="color:#d63638;">' . esc_html( $e['grant_error'] ) . '</small>';
				break; // Show first error only.
			}
			if ( ! empty( $e['revocation_error'] ) ) {
				echo '<br><small style="color:#d63638;">' . esc_html( $e['revocation_error'] ) . '</small>';
				break;
			}
		}
		echo '</td>';
		echo '<td>';

		if ( 'revoked' !== $g_status ) {
			// Use the primary entitlement (the one with OTP) for actions.
			$action_id = $primary['id'];
			// Find the entitlement with a claim_token_hash (the primary).
			foreach ( $entitlements as $e ) {
				if ( ! empty( $e['claim_token_hash'] ) ) {
					$action_id = $e['id'];
					break;
				}
			}

			if ( 'pending' === $v_status || 'expired' === $v_status ) {
				echo '<button type="button" class="button button-small wgdp-resend-otp-btn" '
					. 'data-entitlement-id="' . esc_attr( $action_id ) . '">Resend OTP</button> ';
			}
			echo '<button type="button" class="button button-small wgdp-am-request-new-email-btn" '
				. 'data-entitlement-id="' . esc_attr( $primary['id'] ) . '">Remove Account</button> ';
			if ( 'error' === $g_status ) {
				echo '<button type="button" class="button button-small wgdp-retry-grant-btn" '
					. 'data-entitlement-id="' . esc_attr( $primary['id'] ) . '">Retry Grant</button> ';
			}
			echo '<button type="button" class="button button-small wgdp-revoke-entitlement-btn" '
				. 'data-entitlement-id="' . esc_attr( $primary['id'] ) . '" '
				. 'style="color:#b32d2e;">Revoke</button>';
		}

		echo '</td>';
		echo '</tr>';
	}

	/**
	 * True when every pending_release row in a recipient group has an open release gate.
	 */
	private function are_pending_release_rows_released( $entitlements ) {
		$found_pending_release = false;

		foreach ( $entitlements as $row ) {
			if ( 'pending_release' !== ( $row['grant_status'] ?? '' ) ) {
				continue;
			}

			$found_pending_release = true;
			if ( ! WGDP_Release_Gate::is_item_released(
				(int) ( $row['product_id'] ?? 0 ),
				(int) ( $row['variation_id'] ?? 0 )
			) ) {
				return false;
			}
		}

		return $found_pending_release;
	}

	/**
	 * AJAX: Resend OTP for an entitlement.
	 */
	public function ajax_resend_otp() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$entitlement_id = absint( $_POST['entitlement_id'] ?? 0 );
		if ( ! $entitlement_id ) {
			wp_send_json_error( 'Missing entitlement ID.' );
		}

		$ent = WGDP_Entitlements::instance();
		$row = $ent->get( $entitlement_id );
		if ( ! $row ) {
			wp_send_json_error( 'Entitlement not found.' );
		}

		if ( 'revoked' === $row['grant_status'] ) {
			wp_send_json_error( 'Cannot resend OTP for a revoked entitlement.' );
		}

		if ( 'verified' === $row['verification_status'] ) {
			wp_send_json_error( 'Cannot resend OTP — this entitlement is already verified.' );
		}

		$tokens = $ent->issue_otp_for_recipient_group( $entitlement_id );
		if ( is_wp_error( $tokens ) ) {
			wp_send_json_error( $tokens->get_error_message() );
		}

		$order = wc_get_order( $row['order_id'] );
		$item  = $order ? $order->get_item( $row['order_item_id'] ) : null;

		if ( $order && $item ) {
			$mail_result = WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
			if ( is_wp_error( $mail_result ) ) {
				wp_send_json_error( 'Verification code was created, but email failed: ' . $mail_result->get_error_message() );
			}
		}

		wp_send_json_success( 'Verification email resent.' );
	}

	/**
	 * AJAX: Add a new entitlement to an order item.
	 */
	public function ajax_add_entitlement() {
		WGDP_Entitlements::ajax_create_entitlement( 'added by admin' );
	}

	/**
	 * AJAX: Revoke all entitlements for a recipient (all files in the same order item).
	 */
	public function ajax_revoke_entitlement() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$entitlement_id = absint( $_POST['entitlement_id'] ?? 0 );
		if ( ! $entitlement_id ) {
			wp_send_json_error( 'Missing entitlement ID.' );
		}

		$ent = WGDP_Entitlements::instance();
		$row = $ent->get( $entitlement_id );
		if ( ! $row ) {
			wp_send_json_error( 'Entitlement not found.' );
		}

		if ( 'revoked' === $row['grant_status'] ) {
			wp_send_json_error( 'Already revoked.' );
		}

		// Scope: 'single' revokes only this entitlement; default revokes all siblings.
		$scope = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'all';

		if ( 'single' === $scope ) {
			$all_rows = array( $row );
		} else {
			// Collect all sibling rows (same order item + recipient), including the primary.
			$all_rows = $ent->get_siblings( $row['order_item_id'], $row['recipient_email'] );
			if ( empty( $all_rows ) ) {
				$all_rows = array( $row );
			}
		}

		$drive_warning = '';
		$revoked_count = 0;

		foreach ( $all_rows as $sibling ) {
			if ( 'revoked' === $sibling['grant_status'] ) {
				continue;
			}

			$result = $ent->revoke_with_drive_delete( $sibling, WGDP_Entitlements::REVOCATION_REASON_MANUAL );
			if ( is_wp_error( $result ) ) {
				$drive_warning = $drive_warning ?: $result->get_error_message();
				continue;
			}
			$revoked_count++;
		}

		// Send one revocation email for the recipient.
		if ( ! $drive_warning ) {
			WGDP_Notification_Email::send_access_revoked( $row['recipient_email'], WGDP_Entitlements::get_product_name( $row ), $row['order_id'] ?? 0 );
		}
		delete_transient( 'wgdp_permission_counts' );

		if ( $drive_warning ) {
			wp_send_json_success( array(
				'status'        => 'revocation_error',
				'message'       => 'Could not remove one or more Drive permissions. The row is marked Revocation Error and will retry automatically. Error: ' . $drive_warning,
				'revoked_count' => $revoked_count,
			) );
		}

		wp_send_json_success( array(
			'status'        => 'revoked',
			'message'       => 'Entitlement revoked.',
			'revoked_count' => $revoked_count,
		) );
	}

	/**
	 * Add Drive column to orders list.
	 */
	public function add_orders_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns['wgdp_drive'] = 'Drive';
			}
		}
		return $new_columns;
	}

	/**
	 * Render Drive column (HPOS).
	 */
	public function render_orders_column( $column_name, $order ) {
		if ( 'wgdp_drive' !== $column_name ) {
			return;
		}
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order ) {
			echo '&mdash;';
			return;
		}
		$this->output_drive_column_icon( $order );
	}

	/**
	 * Render Drive column on legacy shop_order list table.
	 */
	public function render_legacy_orders_column( $column_name, $post_id ) {
		$this->render_orders_column( $column_name, $post_id );
	}

	/**
	 * Output the Drive column icon.
	 */
	private function output_drive_column_icon( $order ) {
		if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
			echo '<span class="wgdp-column-icon wgdp-column-icon--none">&mdash;</span>';
			return;
		}

		$ent  = WGDP_Entitlements::instance();
		$rows = $ent->get_by_order( $order->get_id() );

		$has_errors          = false;
		$has_pending         = false;
		$has_pending_release = false;
		$has_queued_grant    = false;
		$has_granted         = false;
		$all_revoked         = true;

		foreach ( $rows as $row ) {
			if ( 'revoked' === $row['grant_status'] ) {
				continue;
			}
			$all_revoked = false;
			if ( in_array( $row['grant_status'], array( 'error', 'revocation_error' ), true ) ) {
				$has_errors = true;
			} elseif ( 'pending_release' === $row['grant_status'] ) {
				if ( WGDP_Release_Gate::is_item_released( (int) $row['product_id'], (int) ( $row['variation_id'] ?? 0 ) ) ) {
					$has_queued_grant = true;
				} else {
					$has_pending_release = true;
				}
			} elseif ( 'granted' === $row['grant_status'] ) {
				$has_granted = true;
			} else {
				$has_pending = true;
			}
		}

		if ( $all_revoked ) {
			echo '<span class="wgdp-column-icon wgdp-column-icon--revoked" title="All revoked">&#10005;</span>';
		} elseif ( $has_errors ) {
			echo '<span class="wgdp-column-icon wgdp-column-icon--warning" title="Has errors">&#9888;</span>';
		} elseif ( $has_pending ) {
			echo '<span class="wgdp-column-icon wgdp-column-icon--pending" title="Pending verification">&#9679;</span>';
		} elseif ( $has_pending_release ) {
			echo '<span class="wgdp-column-icon wgdp-column-icon--pending" title="Pending release">&#9679;</span>';
		} elseif ( $has_queued_grant ) {
			echo '<span class="wgdp-column-icon wgdp-column-icon--pending" title="Queued for Grant">&#9679;</span>';
		} else {
			echo '<span class="wgdp-column-icon wgdp-column-icon--ok" title="All granted">&#10003;</span>';
		}
	}

	/**
	 * Enqueue admin assets on order screens.
	 */
	public function enqueue_order_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_order_edit = ( 'shop_order' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) )
			|| ( 'woocommerce_page_wc-orders' === $screen->id );
		$is_order_list = ( 'edit-shop_order' === $screen->id )
			|| ( 'edit.php' === $hook && 'shop_order' === $screen->post_type );

		if ( ! $is_order_edit && ! $is_order_list ) {
			return;
		}

		wp_enqueue_style(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/css/wgdp-admin.css',
			array(),
			WGDP_VERSION
		);

		wp_enqueue_script(
			'wgdp-order-admin',
			WGDP_PLUGIN_URL . 'admin/js/wgdp-admin.js',
			array( 'jquery' ),
			WGDP_VERSION,
			true
		);
		wp_localize_script( 'wgdp-order-admin', 'wgdp', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wgdp_admin_nonce' ),
		) );
	}

}
