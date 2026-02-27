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

		// Sales counter.
		add_action( 'woocommerce_order_status_processing', array( $this, 'update_sales_counter' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'update_sales_counter' ), 20 );

		// Admin meta box.
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_wgdp_resend_otp', array( $this, 'ajax_resend_otp' ) );
		add_action( 'wp_ajax_wgdp_revoke_entitlement', array( $this, 'ajax_revoke_entitlement' ) );
		add_action( 'wp_ajax_wgdp_add_entitlement', array( $this, 'ajax_add_entitlement' ) );

		// Admin assets on order screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_order_assets' ) );

		// Orders list column (HPOS).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_orders_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_orders_column' ), 10, 2 );
	}

	/**
	 * Create entitlements and send OTP emails for an order.
	 *
	 * Trigger-aware: detects whether this is a processing or completed event
	 * and only creates entitlements for items whose trigger matches.
	 * On completed, also processes on_payment items as fallback for orders
	 * that skip processing.
	 */
	public function create_entitlements( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Detect trigger event from current action.
		$current = current_action();
		if ( 'woocommerce_order_status_completed' === $current ) {
			$current_event = 'on_completion';
		} else {
			$current_event = 'on_payment';
		}

		$ent  = WGDP_Entitlements::instance();
		$otp  = WGDP_OTP::instance();
		$auth = WGDP_Google_Auth::instance();

		$has_drive_items = false;
		$created_any     = false;

		foreach ( $order->get_items() as $item ) {
			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();
			$order_item_id = $item->get_id();

			// Skip if entitlements already exist for this item.
			$existing_for_item = $ent->get_by_order_item( $order_item_id );
			if ( ! empty( $existing_for_item ) ) {
				continue;
			}

			// Get recipients from item meta.
			$recipients_json = $item->get_meta( '_wgdp_recipients' );
			if ( empty( $recipients_json ) ) {
				continue;
			}
			$recipients = json_decode( $recipients_json, true );
			if ( ! is_array( $recipients ) || empty( $recipients ) ) {
				continue;
			}

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

			// Fetch resource ID for the entitlement row.
			$resource_id = '';
			if ( $variation_id ) {
				$resource_id = get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
			}
			if ( empty( $resource_id ) ) {
				$resource_id = get_post_meta( $product_id, '_wgdp_drive_resource_id', true );
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
				$email = sanitize_email( $email );
				if ( ! is_email( $email ) ) {
					continue;
				}

				$entitlement_id = $ent->create( array(
					'order_id'       => $order_id,
					'order_item_id'  => $order_item_id,
					'product_id'     => $product_id,
					'variation_id'   => $variation_id ?: 0,
					'cloud_asset_id' => $resource_id,
					'account_id'     => $account_id,
					'recipient_email' => $email,
					'recipient_index' => $index + 1,
				) );

				if ( $entitlement_id ) {
					$tokens = $otp->issue_otp_for_entitlement( $entitlement_id );
					WGDP_Notification_Email::send_otp( $email, $tokens['otp'], $tokens['claim_token'], $order, $item );

					$order->add_order_note( sprintf(
						'WGDP: Verification email sent to %s for "%s" (entitlement #%d)',
						$email,
						$item->get_name(),
						$entitlement_id
					) );
					$created_any = true;
				}
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
	 * Revoke all entitlements for an order (full refund/cancel).
	 */
	public function revoke_all_entitlements( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();
		$rows  = $ent->get_by_order( $order_id );

		$drive_failures = 0;

		foreach ( $rows as $row ) {
			if ( 'revoked' === $row['grant_status'] ) {
				continue;
			}

			// If granted, revoke on Drive — but only if no other active entitlement shares this permission.
			if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
				if ( $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
					$order->add_order_note( sprintf(
						'WGDP: Skipped Drive permission delete for %s — permission shared with another active entitlement.',
						$row['recipient_email']
					) );
				} else {
					$result = $drive->delete_permission( $row['cloud_asset_id'], $row['provider_permission_id'], $row['account_id'] );
					if ( is_wp_error( $result ) ) {
						$drive_failures++;
						$order->add_order_note( sprintf(
							'WGDP: Failed to revoke Drive access for %s — %s',
							$row['recipient_email'],
							$result->get_error_message()
						) );
					} else {
						WGDP_Notification_Email::send_access_revoked( $row['recipient_email'], $this->get_product_name( $row ) );
					}
				}
			}

			$ent->mark_revoked( $row['id'] );
		}

		if ( $drive_failures > 0 ) {
			$order->add_order_note( sprintf( 'WGDP: All entitlements revoked (%d Drive permission removal(s) failed).', $drive_failures ) );
		} else {
			$order->add_order_note( 'WGDP: All entitlements revoked.' );
		}

		// Decrement sales counter for previously counted items.
		$counted_json = $order->get_meta( '_wgdp_qty_counted_items' );
		$counted_ids  = ! empty( $counted_json ) ? json_decode( $counted_json, true ) : array();
		if ( ! is_array( $counted_ids ) ) {
			$counted_ids = array();
		}

		if ( ! empty( $counted_ids ) ) {
			$deltas = array();
			foreach ( $order->get_items() as $item ) {
				$product_id    = $item->get_product_id();
				$variation_id  = $item->get_variation_id();
				$order_item_id = $item->get_id();

				if ( ! in_array( $order_item_id, $counted_ids, true ) ) {
					continue;
				}

				if ( WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
					if ( ! isset( $deltas[ $product_id ] ) ) {
						$deltas[ $product_id ] = 0;
					}
					$deltas[ $product_id ] += $item->get_quantity();
				}
			}
			foreach ( $deltas as $pid => $qty ) {
				WGDP_Release_Gate::increment_paid_qty( $pid, -$qty );
			}
			$order->delete_meta_data( '_wgdp_qty_counted_items' );
			$order->save();
		}

		delete_transient( 'wgdp_permission_counts' );
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
			return;
		}

		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();

		$counter_deltas = array();

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
			$product_id   = $order_item->get_product_id();
			$variation_id = $order_item->get_variation_id();
			$cj  = $order->get_meta( '_wgdp_qty_counted_items' );
			$cis = ! empty( $cj ) ? json_decode( $cj, true ) : array();
			$item_was_counted = is_array( $cis ) && in_array( (int) $order_item_id, $cis, true );
			if ( $item_was_counted && WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				if ( ! isset( $counter_deltas[ $product_id ] ) ) {
					$counter_deltas[ $product_id ] = 0;
				}
				$counter_deltas[ $product_id ] += $refunded_qty;
			}

			// Calculate: remaining = original qty - total refunded qty.
			$original_qty        = $order_item->get_quantity();
			$qty_refunded_total  = $order->get_qty_refunded_for_item( $order_item_id );
			$remaining           = $original_qty - abs( $qty_refunded_total );
			$active_count     = $ent->count_active_for_item( $order_item_id );

			if ( $active_count <= $remaining ) {
				continue;
			}

			$excess = $active_count - $remaining;
			$candidate_ids = $ent->get_revocation_candidates( $order_item_id, $excess );

			foreach ( $candidate_ids as $eid ) {
				$row = $ent->get( $eid );
				if ( ! $row ) {
					continue;
				}

				// If granted, revoke on Drive — but only if no other active entitlement shares this permission.
				if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
					if ( $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
						$order->add_order_note( sprintf(
							'WGDP: Skipped Drive permission delete for %s — permission shared with another active entitlement.',
							$row['recipient_email']
						) );
					} else {
						$delete_result = $drive->delete_permission( $row['cloud_asset_id'], $row['provider_permission_id'], $row['account_id'] );
						if ( is_wp_error( $delete_result ) ) {
							$order->add_order_note( sprintf(
								'WGDP: Failed to revoke Drive access for %s — %s',
								$row['recipient_email'],
								$delete_result->get_error_message()
							) );
						} else {
							WGDP_Notification_Email::send_access_revoked( $row['recipient_email'], $this->get_product_name( $row ) );
						}
					}
				}

				$ent->mark_revoked( $eid );

				$order->add_order_note( sprintf(
					'WGDP: Revoked entitlement #%d for %s (partial refund)',
					$eid,
					$row['recipient_email']
				) );
			}
		}

		// Apply sales counter decrements.
		foreach ( $counter_deltas as $pid => $qty ) {
			WGDP_Release_Gate::increment_paid_qty( $pid, -$qty );
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Update the sales counter for qualifying items in an order.
	 *
	 * Uses per-item tracking via _wgdp_qty_counted_items (JSON array of item IDs)
	 * to prevent double-counting while supporting per-item trigger timing.
	 */
	public function update_sales_counter( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Detect trigger event from current action.
		$current = current_action();
		if ( 'woocommerce_order_status_completed' === $current ) {
			$current_event = 'on_completion';
		} else {
			$current_event = 'on_payment';
		}

		// Load already-counted item IDs.
		$counted_json = $order->get_meta( '_wgdp_qty_counted_items' );
		$counted_ids  = ! empty( $counted_json ) ? json_decode( $counted_json, true ) : array();
		if ( ! is_array( $counted_ids ) ) {
			$counted_ids = array();
		}

		$deltas      = array();
		$new_counted = array();

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

			if ( ! isset( $deltas[ $product_id ] ) ) {
				$deltas[ $product_id ] = 0;
			}
			$deltas[ $product_id ] += $item->get_quantity();
			$new_counted[]          = $order_item_id;
		}

		if ( empty( $deltas ) ) {
			return;
		}

		foreach ( $deltas as $pid => $qty ) {
			WGDP_Release_Gate::increment_paid_qty( $pid, $qty );
		}

		$counted_ids = array_merge( $counted_ids, $new_counted );
		$order->update_meta_data( '_wgdp_qty_counted_items', wp_json_encode( $counted_ids ) );
		$order->save();
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

	}

	/**
	 * Render the meta box (HPOS).
	 */
	public function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$this->render_meta_box_content( $order );
	}

	/**
	 * Render the meta box content.
	 */
	private function render_meta_box_content( $order ) {
		$ent  = WGDP_Entitlements::instance();
		$rows = $ent->get_by_order( $order->get_id() );

		// Group existing entitlements by order_item_id.
		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ $row['order_item_id'] ][] = $row;
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
			$entitlements = isset( $grouped[ $order_item_id ] ) ? $grouped[ $order_item_id ] : array();

			echo '<h4 style="margin:12px 0 6px;">' . esc_html( $item->get_name() ) . '</h4>';

			if ( ! empty( $entitlements ) ) {
				echo '<table class="widefat fixed striped wgdp-recipients-table" data-order-item-id="' . esc_attr( $order_item_id ) . '" style="margin-bottom:12px;">';
				echo '<thead><tr><th style="width:30px;">#</th><th>Email</th><th>Verification</th><th>Grant</th><th>Actions</th></tr></thead>';
				echo '<tbody>';

				foreach ( $entitlements as $e ) {
					$this->render_entitlement_row( $e );
				}

				echo '</tbody></table>';
			}

			// "Add Recipient" inline form.
			echo '<div class="wgdp-add-entitlement-form" style="margin-bottom:16px;">';
			echo '<input type="email" class="wgdp-add-email-input" placeholder="recipient@gmail.com" style="width:260px;margin-right:4px;" />';
			echo '<button type="button" class="button button-small wgdp-add-entitlement-btn" '
				. 'data-order-id="' . esc_attr( $order->get_id() ) . '" '
				. 'data-order-item-id="' . esc_attr( $order_item_id ) . '">'
				. 'Add Recipient</button>';
			echo '</div>';
		}

		if ( ! $has_qualifying_items ) {
			echo '<p>No items in this order qualify for digital access.</p>';
		}
	}

	/**
	 * Render a single entitlement row in the meta box table.
	 */
	private function render_entitlement_row( $e ) {
		$v_class = 'wgdp-vstatus--' . esc_attr( $e['verification_status'] );
		$g_class = 'wgdp-gstatus--' . esc_attr( $e['grant_status'] );

		echo '<tr>';
		echo '<td>' . esc_html( $e['recipient_index'] ) . '</td>';
		echo '<td>' . esc_html( $e['recipient_email'] ) . '</td>';
		echo '<td><span class="wgdp-status-badge ' . $v_class . '">' . esc_html( ucfirst( $e['verification_status'] ) ) . '</span></td>';
		$grant_label = $e['grant_status'];
		if ( 'pending_release' === $grant_label ) {
			$grant_label = 'Pending Release';
		} else {
			$grant_label = ucfirst( $grant_label );
		}
		echo '<td><span class="wgdp-status-badge ' . $g_class . '">' . esc_html( $grant_label ) . '</span>';
		if ( ! empty( $e['grant_error'] ) ) {
			echo '<br><small style="color:#d63638;">' . esc_html( $e['grant_error'] ) . '</small>';
		}
		echo '</td>';
		echo '<td>';

		if ( 'revoked' !== $e['grant_status'] ) {
			if ( 'pending' === $e['verification_status'] || 'expired' === $e['verification_status'] ) {
				echo '<button type="button" class="button button-small wgdp-resend-otp-btn" '
					. 'data-entitlement-id="' . esc_attr( $e['id'] ) . '">Resend OTP</button> ';
			}
			echo '<button type="button" class="button button-small wgdp-revoke-entitlement-btn" '
				. 'data-entitlement-id="' . esc_attr( $e['id'] ) . '" '
				. 'style="color:#b32d2e;">Revoke</button>';
		}

		echo '</td>';
		echo '</tr>';
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

		$otp    = WGDP_OTP::instance();
		$tokens = $otp->issue_otp_for_entitlement( $entitlement_id );

		$order = wc_get_order( $row['order_id'] );
		$item  = $order ? $order->get_item( $row['order_item_id'] ) : null;

		if ( $order && $item ) {
			WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
		}

		wp_send_json_success( 'Verification email resent.' );
	}

	/**
	 * AJAX: Add a new entitlement to an order item.
	 */
	public function ajax_add_entitlement() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$order_id      = absint( $_POST['order_id'] ?? 0 );
		$order_item_id = absint( $_POST['order_item_id'] ?? 0 );
		$email         = sanitize_email( $_POST['email'] ?? '' );

		if ( ! $order_id || ! $order_item_id ) {
			wp_send_json_error( 'Missing order or item ID.' );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( 'Please enter a valid email address.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		$item = $order->get_item( $order_item_id );
		if ( ! $item ) {
			wp_send_json_error( 'Order item not found.' );
		}

		$product_id   = $item->get_product_id();
		$variation_id = $item->get_variation_id();

		if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
			wp_send_json_error( 'This item does not qualify for digital access.' );
		}

		// Resolve cloud_asset_id.
		$resource_id = '';
		if ( $variation_id ) {
			$resource_id = get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
		}
		if ( empty( $resource_id ) ) {
			$resource_id = get_post_meta( $product_id, '_wgdp_drive_resource_id', true );
		}

		// Resolve account.
		$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );
		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			wp_send_json_error( 'No connected Google account for this item.' );
		}

		$ent = WGDP_Entitlements::instance();

		// Check if a revoked entitlement exists for the same unique key — reactivate it instead of inserting.
		$revoked = $ent->get_revoked_for_reuse( $order_item_id, $resource_id, $email );
		if ( $revoked ) {
			$entitlement_id  = (int) $revoked['id'];
			$recipient_index = (int) $revoked['recipient_index'];
			$ent->update( $entitlement_id, array(
				'verification_status'    => 'pending',
				'grant_status'           => 'pending',
				'provider_permission_id' => null,
				'granted_at'             => null,
				'revoked_at'             => null,
				'grant_error'            => null,
				'grant_retries'          => 0,
				'account_id'             => $account_id,
			) );
		} else {
			// Calculate recipient_index as max existing + 1.
			$existing = $ent->get_by_order_item( $order_item_id );
			$max_index = 0;
			foreach ( $existing as $row ) {
				if ( (int) $row['recipient_index'] > $max_index ) {
					$max_index = (int) $row['recipient_index'];
				}
			}
			$recipient_index = $max_index + 1;

			$entitlement_id = $ent->create( array(
				'order_id'        => $order_id,
				'order_item_id'   => $order_item_id,
				'product_id'      => $product_id,
				'variation_id'    => $variation_id ?: 0,
				'cloud_asset_id'  => $resource_id,
				'account_id'      => $account_id,
				'recipient_email' => $email,
				'recipient_index' => $recipient_index,
			) );

			if ( ! $entitlement_id ) {
				wp_send_json_error( 'Failed to create entitlement.' );
			}
		}

		// Issue OTP and send verification email.
		$otp    = WGDP_OTP::instance();
		$tokens = $otp->issue_otp_for_entitlement( $entitlement_id );
		WGDP_Notification_Email::send_otp( $email, $tokens['otp'], $tokens['claim_token'], $order, $item );

		// Set drive items flag if not already set.
		if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
			$order->update_meta_data( '_wgdp_has_drive_items', '1' );
			$order->save();
		}

		$order->add_order_note( sprintf(
			'WGDP: Verification email sent to %s for "%s" (entitlement #%d) — added by admin',
			$email,
			$item->get_name(),
			$entitlement_id
		) );

		wp_send_json_success( array(
			'id'              => $entitlement_id,
			'email'           => $email,
			'recipient_index' => $recipient_index,
		) );
	}

	/**
	 * AJAX: Revoke a single entitlement.
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

		// If granted, revoke on Drive — but only if no other active entitlement shares this permission.
		if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
			if ( $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
				// Skip Drive delete and notification — another entitlement still needs this permission.
			} else {
				$result = WGDP_Google_Drive::instance()->delete_permission(
					$row['cloud_asset_id'],
					$row['provider_permission_id'],
					$row['account_id']
				);
				if ( is_wp_error( $result ) ) {
					wp_send_json_error( 'Drive API error: ' . $result->get_error_message() );
				}
				WGDP_Notification_Email::send_access_revoked( $row['recipient_email'], $this->get_product_name( $row ) );
			}
		}

		$ent->mark_revoked( $entitlement_id );
		delete_transient( 'wgdp_permission_counts' );

		wp_send_json_success( 'Entitlement revoked.' );
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
		$has_granted         = false;
		$all_revoked         = true;

		foreach ( $rows as $row ) {
			if ( 'revoked' === $row['grant_status'] ) {
				continue;
			}
			$all_revoked = false;
			if ( 'error' === $row['grant_status'] ) {
				$has_errors = true;
			} elseif ( 'pending_release' === $row['grant_status'] ) {
				$has_pending_release = true;
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

	/**
	 * Get product name from entitlement row data.
	 */
	private function get_product_name( $row ) {
		$id = $row['variation_id'] ?: $row['product_id'];
		$product = wc_get_product( $id );
		return $product ? $product->get_name() : 'Product #' . $id;
	}
}
