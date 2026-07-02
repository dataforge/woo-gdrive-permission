<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WGDP_Access_Manager_Table extends WP_List_Table {

	/**
	 * Display mode: 'entitlements' or 'missing_email'.
	 */
	private $display_mode = 'entitlements';

	/**
	 * Cached drive name data keyed by cloud_asset_id.
	 */
	private $drive_names = array();

	/**
	 * Cached order item qty keyed by order_item_id.
	 */
	private $item_qty_cache = array();

	/**
	 * Cached active file count per order_item_id + recipient_email.
	 */
	private $file_count_cache = array();

	/**
	 * Cached total expected files per product/variation.
	 */
	private $expected_files_cache = array();

	/**
	 * Cached seat positions: order_item_id => [ email => seat_number ].
	 */
	private $seat_position_cache = array();

	/**
	 * Cached active asset IDs per product/variation: "product_id|variation_id" => [ asset_id, ... ].
	 */
	private $active_asset_ids_cache = array();

	/**
	 * Cached billing email by order ID.
	 */
	private $billing_email_cache = array();

	public function __construct( $display_mode = 'entitlements' ) {
		$this->display_mode = $display_mode;
		parent::__construct( array(
			'singular' => 'am-item',
			'plural'   => 'am-items',
			'ajax'     => false,
		) );
	}

	/**
	 * Define columns based on display mode.
	 */
	public function get_columns() {
		if ( 'missing_email' === $this->display_mode ) {
			return array(
				'order_id'    => 'Order',
				'product'     => 'Product / Variation',
				'qty'         => 'Qty',
				'assigned'    => 'Assigned',
				'unassigned'  => 'Unassigned',
				'drive_asset' => 'Drive Asset',
				'actions'     => 'Actions',
			);
		}

		return array(
			'cb'                  => '<input type="checkbox" />',
			'order_id'            => 'Order',
			'created_at'          => 'Date',
			'product'             => 'Product / Variation',
			'drive_asset'         => 'Drive Asset',
			'recipient_email'     => 'Recipient Email',
			'seat'                => 'Seat',
			'files'               => 'Files',
			'verification_status' => 'Verification',
			'grant_status'        => 'Grant Status',
			'actions'             => 'Actions',
		);
	}

	/**
	 * Define sortable columns.
	 */
	public function get_sortable_columns() {
		if ( 'missing_email' === $this->display_mode ) {
			return array();
		}

		return array(
			'order_id'   => array( 'order_id', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Define bulk actions.
	 */
	public function get_bulk_actions() {
		if ( 'missing_email' === $this->display_mode ) {
			return array();
		}

		return array(
			'resend_otp'  => 'Resend OTP',
			'retry_grant' => 'Retry Grant',
			'revoke'      => 'Revoke',
		);
	}

	/**
	 * Checkbox column.
	 */
	public function column_cb( $item ) {
		if ( ! empty( $item['_unassigned'] ) ) {
			return '';
		}
		return '<input type="checkbox" name="entitlement[]" value="' . esc_attr( $item['id'] ) . '" />';
	}

	/**
	 * Order column.
	 */
	public function column_order_id( $item ) {
		$order_id = $item['order_id'];
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return '#' . esc_html( $order_id );
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order_id ) . '</a>';
	}

	/**
	 * Date column.
	 */
	public function column_created_at( $item ) {
		if ( ! empty( $item['_unassigned'] ) ) {
			return '<span style="color:#999;">—</span>';
		}
		return esc_html( date_i18n( 'Y-m-d H:i', strtotime( $item['created_at'] ) ) );
	}

	/**
	 * Product column.
	 */
	public function column_product( $item ) {
		$id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
		$product = wc_get_product( $id );
		return $product ? esc_html( $product->get_name() ) : 'Product #' . esc_html( $id );
	}

	/**
	 * Drive Asset column — live name from API (cached).
	 */
	public function column_drive_asset( $item ) {
		$asset_id = $item['cloud_asset_id'] ?? '';
		if ( empty( $asset_id ) ) {
			return '<em>None</em>';
		}

		$label = '';
		if ( isset( $this->drive_names[ $asset_id ] ) ) {
			$info = $this->drive_names[ $asset_id ];
			$name = $info['name'] ?? substr( $asset_id, 0, 12 ) . '...';
			$link = $info['webViewLink'] ?? '';
			if ( $link ) {
				$label = '<a href="' . esc_url( $link ) . '" target="_blank" title="' . esc_attr( $name ) . '">' . esc_html( $name ) . '</a>';
			} else {
				$label = esc_html( $name );
			}
		} else {
			$label = '<code>' . esc_html( substr( $asset_id, 0, 12 ) ) . '...</code>';
		}

		// Check if this asset has been removed from the product/variation.
		$product_id   = $item['product_id'] ?? 0;
		$variation_id = $item['variation_id'] ?? 0;
		$prod_key     = $product_id . '|' . $variation_id;
		if ( isset( $this->active_asset_ids_cache[ $prod_key ] ) && ! in_array( $asset_id, $this->active_asset_ids_cache[ $prod_key ], true ) ) {
			$label .= ' <span class="wgdp-badge--removed" title="This file has been removed from the product/variation configuration">Removed</span>';
		}

		return $label;
	}

	/**
	 * Recipient Email column — with inline edit.
	 */
	public function column_recipient_email( $item ) {
		if ( ! empty( $item['_unassigned'] ) ) {
			return '<em style="color:#d63638;">Not assigned</em>';
		}

		$email = esc_attr( $item['recipient_email'] );
		$id    = esc_attr( $item['id'] );

		return '<span class="wgdp-am-email-display">' . esc_html( $item['recipient_email'] ) . '</span>'
			. '<span class="wgdp-am-email-edit" style="display:none;">'
			. '<input type="email" class="wgdp-am-email-input" value="' . $email . '" style="width:200px;" />'
			. '<div style="margin-top:4px;">'
			. '<button class="button button-small wgdp-am-email-save" data-entitlement-id="' . $id . '">Save</button> '
			. '<button class="button button-small wgdp-am-email-cancel">Cancel</button>'
			. '</div>'
			. '</span>';
	}

	/**
	 * Seat column — shows "X / Y" where X is the active seat position and Y is the order item qty.
	 */
	public function column_seat( $item ) {
		$order_item_id = $item['order_item_id'];

		if ( ! isset( $this->item_qty_cache[ $order_item_id ] ) ) {
			$order = wc_get_order( $item['order_id'] );
			$qty   = 1;
			if ( $order ) {
				$oi = $order->get_item( $order_item_id );
				if ( $oi ) {
					$qty = (int) $oi->get_quantity();
				}
			}
			$this->item_qty_cache[ $order_item_id ] = $qty;
		}

		$qty = $this->item_qty_cache[ $order_item_id ];

		// Unassigned rows have their seat number set directly during injection.
		if ( ! empty( $item['_unassigned'] ) ) {
			$seat  = $item['recipient_index'];
			$label = $seat . ' / ' . $qty;
			return '<span style="color:#d63638;">' . esc_html( $label ) . '</span>';
		}

		$email = $item['recipient_email'];
		$seat  = $this->seat_position_cache[ $order_item_id ][ $email ] ?? '—';

		if ( '—' === $seat ) {
			// Revoked with no active seat — show as inactive.
			return '<span style="color:#999;">— / ' . esc_html( $qty ) . '</span>';
		}

		$label = $seat . ' / ' . $qty;

		if ( $seat > $qty ) {
			// Over-assigned: more active recipients than qty ordered.
			return '<strong style="color:#dba617;" title="More recipients than qty ordered">' . esc_html( $label ) . '</strong>';
		}

		return esc_html( $label );
	}

	/**
	 * Files column — shows "X / Y" active files vs expected files for this recipient.
	 */
	public function column_files( $item ) {
		if ( ! empty( $item['_unassigned'] ) ) {
			$product_id   = (int) $item['product_id'];
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$prod_key     = $product_id . '|' . $variation_id;
			if ( ! isset( $this->expected_files_cache[ $prod_key ] ) ) {
				// Fully-unassigned rows are injected after preload_seat_and_file_data(),
				// so their product may not be cached yet — compute it on demand.
				$resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
				$this->expected_files_cache[ $prod_key ]   = count( $resources );
				$this->active_asset_ids_cache[ $prod_key ] = wp_list_pluck( $resources, 'id' );
			}
			$expected = $this->expected_files_cache[ $prod_key ];
			return '<span style="color:#999;">0 / ' . esc_html( $expected ) . '</span>';
		}

		$order_item_id = $item['order_item_id'];
		$email         = $item['recipient_email'];
		$product_id    = (int) $item['product_id'];
		$variation_id  = (int) ( $item['variation_id'] ?? 0 );

		// Count active (non-revoked) entitlements for this order item + email.
		$cache_key = $order_item_id . '|' . $email;
		if ( ! isset( $this->file_count_cache[ $cache_key ] ) ) {
			// Fallback: should be pre-populated by preload_seat_and_file_data().
			$ent = WGDP_Entitlements::instance();
			$this->file_count_cache[ $cache_key ] = count( $ent->get_siblings( $order_item_id, $email ) );
		}
		$active_files = $this->file_count_cache[ $cache_key ];

		// Count expected files from product config.
		$prod_key = $product_id . '|' . $variation_id;
		if ( ! isset( $this->expected_files_cache[ $prod_key ] ) ) {
			$resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
			$this->expected_files_cache[ $prod_key ] = count( $resources );
			$this->active_asset_ids_cache[ $prod_key ] = wp_list_pluck( $resources, 'id' );
		}
		$expected_files = $this->expected_files_cache[ $prod_key ];

		$label = $active_files . ' / ' . $expected_files;

		if ( $active_files < $expected_files ) {
			return '<strong style="color:#d63638;">' . esc_html( $label ) . '</strong>';
		}

		return esc_html( $label );
	}

	/**
	 * Verification status column.
	 */
	public function column_verification_status( $item ) {
		if ( ! empty( $item['_unassigned'] ) ) {
			return '<span style="color:#999;">—</span>';
		}
		$status = $item['verification_status'];
		$class  = 'wgdp-vstatus--' . esc_attr( $status );
		return '<span class="wgdp-status-badge ' . $class . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	/**
	 * Grant status column.
	 */
	public function column_grant_status( $item ) {
		if ( ! empty( $item['_unassigned'] ) ) {
			return '<span class="wgdp-status-badge" style="background:#fcf0f0;color:#d63638;">Awaiting Assignment</span>';
		}

		$status = $item['grant_status'];
		$class  = 'wgdp-gstatus--' . esc_attr( $status );
		if ( 'pending_release' === $status ) {
			$label = $this->is_pending_release_item_released( $item ) ? 'Queued for Grant' : 'Pending Release';
		} elseif ( 'revocation_error' === $status ) {
			$label = 'Revocation Error';
		} else {
			$label = ucfirst( $status );
		}
		$html   = '<span class="wgdp-status-badge ' . $class . '">' . esc_html( $label ) . '</span>';

		if ( 'error' === $status && ! empty( $item['grant_error'] ) ) {
			$html .= '<br><small style="color:#d63638;">' . esc_html( $item['grant_error'] ) . '</small>';
		} elseif ( 'revocation_error' === $status && ! empty( $item['revocation_error'] ) ) {
			$html .= '<br><small style="color:#d63638;">' . esc_html( $item['revocation_error'] ) . '</small>';
		}

		return $html;
	}

	/**
	 * Check if a pending_release row is waiting on cron rather than release.
	 */
	private function is_pending_release_item_released( $item ) {
		return WGDP_Release_Gate::is_item_released(
			(int) ( $item['product_id'] ?? 0 ),
			(int) ( $item['variation_id'] ?? 0 )
		);
	}

	/**
	 * Qty column (missing email mode).
	 */
	public function column_qty( $item ) {
		return esc_html( $item['qty'] );
	}

	/**
	 * Assigned column (missing email mode).
	 */
	public function column_assigned( $item ) {
		return esc_html( $item['assigned_count'] );
	}

	/**
	 * Unassigned column (missing email mode).
	 */
	public function column_unassigned( $item ) {
		$count = (int) $item['unassigned_count'];
		if ( $count > 0 ) {
			return '<strong style="color:#d63638;">' . esc_html( $count ) . '</strong>';
		}
		return esc_html( $count );
	}

	/**
	 * Actions column.
	 */
	public function column_actions( $item ) {
		if ( 'missing_email' === $this->display_mode || ! empty( $item['_unassigned'] ) ) {
			$billing_email = $this->get_billing_email_for_order( $item['order_id'] );
			$placeholder   = $billing_email ? 'Billing: ' . $billing_email : 'Enter Google email';

			return '<div class="wgdp-am-assign-form">'
				. '<input type="email" class="wgdp-am-assign-email-input" placeholder="' . esc_attr( $placeholder ) . '" style="width:190px;" />'
				. '<button class="button button-small wgdp-am-assign-btn"'
				. ' data-order-id="' . esc_attr( $item['order_id'] ) . '"'
				. ' data-order-item-id="' . esc_attr( $item['order_item_id'] ) . '">Assign</button> '
				. '<button type="button" class="button button-small wgdp-am-resend-order-email-btn"'
				. ' data-order-id="' . esc_attr( $item['order_id'] ) . '">Resend Order Email</button>'
				. '</div>';
		}

		$html = '';

		if ( 'revoked' !== $item['grant_status'] ) {
			// Change Email button.
			$html .= '<button type="button" class="button button-small wgdp-am-change-email-btn">Change Email</button> ';

				// Remove the current account so the purchaser can provide a replacement.
				$html .= '<button type="button" class="button button-small wgdp-am-request-new-email-btn"'
					. ' data-entitlement-id="' . esc_attr( $item['id'] ) . '">Remove Account</button> ';

			// Send Access Email button (only if verified and granted).
			if ( 'verified' === $item['verification_status'] && 'granted' === $item['grant_status'] ) {
				$html .= '<button type="button" class="button button-small wgdp-am-send-access-email-btn"'
					. ' data-entitlement-id="' . esc_attr( $item['id'] ) . '">Send Access Email</button> ';
			}

			// Verify on Drive button (only if granted with permission ID).
			if ( 'granted' === $item['grant_status'] && ! empty( $item['provider_permission_id'] ) ) {
				$html .= '<button type="button" class="button button-small wgdp-am-verify-btn"'
					. ' data-entitlement-id="' . esc_attr( $item['id'] ) . '">Verify on Drive</button> ';
			}

				// Retry Grant button (if error and verified).
				if ( 'error' === $item['grant_status'] && 'verified' === $item['verification_status'] ) {
					$html .= '<button type="button" class="button button-small wgdp-retry-grant-btn"'
						. ' data-entitlement-id="' . esc_attr( $item['id'] ) . '">Retry Grant</button> ';
				}

			// Resend OTP (if pending/expired verification).
			if ( 'pending' === $item['verification_status'] || 'expired' === $item['verification_status'] ) {
				$html .= '<button type="button" class="button button-small wgdp-resend-otp-btn"'
					. ' data-entitlement-id="' . esc_attr( $item['id'] ) . '">Resend OTP</button> ';
			}

			// Revoke button.
			$html .= '<button type="button" class="button button-small wgdp-revoke-entitlement-btn"'
				. ' data-entitlement-id="' . esc_attr( $item['id'] ) . '"'
				. ' data-scope="single"'
				. ' style="color:#b32d2e;">Revoke</button>';
		}

		// Resend Order Email — available for all statuses.
		$html .= '<button type="button" class="button button-small wgdp-am-resend-order-email-btn"'
			. ' data-order-id="' . esc_attr( $item['order_id'] ) . '">Resend Order Email</button>';

		// Verify result span.
		$html .= '<span class="wgdp-am-verify-result" style="margin-left:6px;"></span>';

		return $html;
	}

	/**
	 * Get billing email with an instance-level cache to avoid repeated order loads.
	 */
	private function get_billing_email_for_order( $order_id ) {
		$order_id = (int) $order_id;
		if ( isset( $this->billing_email_cache[ $order_id ] ) ) {
			return $this->billing_email_cache[ $order_id ];
		}

		$order = wc_get_order( $order_id );
		$this->billing_email_cache[ $order_id ] = $order ? $order->get_billing_email() : '';

		return $this->billing_email_cache[ $order_id ];
	}

	/**
	 * Extra table nav for filters.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_status  = isset( $_GET['am_status'] ) ? sanitize_text_field( wp_unslash( $_GET['am_status'] ) ) : '';
		$current_product = isset( $_GET['am_product'] ) ? absint( $_GET['am_product'] ) : 0;
		$current_order   = isset( $_GET['am_order'] ) ? absint( $_GET['am_order'] ) : 0;

		echo '<div class="alignleft actions">';

		// Status filter.
		$statuses = array(
			''                     => 'All Statuses',
			'missing_email'        => 'Missing Email',
			'pending_verification' => 'Pending Verification',
			'pending_release'      => 'Pending Release',
			'granted'              => 'Granted',
			'error'                => 'Error',
			'revoked'              => 'Revoked',
		);
		echo '<select name="am_status">';
		foreach ( $statuses as $value => $label ) {
			$sel = selected( $current_status, $value, false );
			echo '<option value="' . esc_attr( $value ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		// Order # filter.
		echo '<input type="text" name="am_order" placeholder="Order #" value="' . esc_attr( $current_order ? $current_order : '' ) . '" style="width:90px;" inputmode="numeric" />';

		// Product search (autocomplete or free-text).
		$product_label = '';
		$current_product_name = isset( $_GET['am_product_name'] ) ? sanitize_text_field( wp_unslash( $_GET['am_product_name'] ) ) : '';
		if ( $current_product ) {
			$product       = wc_get_product( $current_product );
			$product_label = $product ? $product->get_name() : 'Product #' . $current_product;
		} elseif ( $current_product_name ) {
			$product_label = $current_product_name;
		}
		echo '<input type="hidden" name="am_product" class="wgdp-product-filter-id" value="' . esc_attr( $current_product ) . '" />';
		echo '<input type="hidden" name="am_product_name" class="wgdp-product-filter-name" value="' . esc_attr( $current_product_name ) . '" />';
		echo '<input type="text" class="wgdp-product-filter" placeholder="Filter by product&hellip;" value="' . esc_attr( $product_label ) . '" style="width:250px;" autocomplete="off" />';

		submit_button( 'Filter', '', 'filter_action', false );

		$clear_url = admin_url( 'admin.php?page=wgdp&tab=access-manager' );
		echo ' <a href="' . esc_url( $clear_url ) . '" class="button" style="margin:1px 0 0 4px;">Clear Filters</a>';

		echo '</div>';
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();

		$status       = isset( $_GET['am_status'] ) ? sanitize_text_field( wp_unslash( $_GET['am_status'] ) ) : '';
		$product_id   = isset( $_GET['am_product'] ) ? absint( $_GET['am_product'] ) : 0;
		$product_name = isset( $_GET['am_product_name'] ) ? sanitize_text_field( wp_unslash( $_GET['am_product_name'] ) ) : '';
		$order_id     = isset( $_GET['am_order'] ) ? absint( $_GET['am_order'] ) : 0;
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$ent = WGDP_Entitlements::instance();

		if ( 'missing_email' === $status || 'missing_email' === $this->display_mode ) {
			$this->display_mode = 'missing_email';

			$result = $ent->get_unassigned_order_items( array(
				'per_page'     => $per_page,
				'page'         => $page,
				'product_id'   => $product_id,
				'product_name' => $product_name,
				'order_id'     => $order_id,
				'search'       => $search,
			) );
		} else {
			$this->display_mode = 'entitlements';

			// Map status filter to query args.
			$query_args = array(
				'per_page'     => $per_page,
				'page'         => $page,
				'orderby'      => isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'id',
				'order'        => isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC',
					'search'       => $search,
					'product_id'   => $product_id,
					'product_name' => $product_name,
					'order_id'     => $order_id,
				);
				if ( 'revoked' !== $status ) {
					$query_args['hide_shadow_revoked'] = true;
				}
				if ( empty( $status ) ) {
					$query_args['exclude_grant_status'] = 'revoked';
				}

				switch ( $status ) {
				case 'pending_verification':
					$query_args['verification_status'] = 'pending';
					$query_args['exclude_grant_status'] = 'revoked';
					break;
				case 'pending_release':
					$query_args['grant_status'] = 'pending_release';
					break;
				case 'granted':
					$query_args['grant_status'] = 'granted';
					break;
				case 'error':
					$query_args['grant_statuses'] = array( 'error', 'revocation_error' );
					break;
				case 'revoked':
					$query_args['grant_status'] = 'revoked';
					break;
			}

			$result = $ent->get_items_for_list_table( $query_args );
		}

		$this->items = $result['items'];

		$this->set_pagination_args( array(
			'total_items' => $result['total'],
			'per_page'    => $per_page,
			'total_pages' => ceil( $result['total'] / $per_page ),
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		// Resolve Drive names for all items.
		$this->resolve_all_drive_names();

		// Pre-populate seat and file caches and inject unassigned seat rows.
		if ( 'entitlements' === $this->display_mode ) {
			$this->preload_seat_and_file_data();
			$this->inject_unassigned_seats();

			// When no status filter is active, also include fully-unassigned
			// order items (no entitlements at all) so they appear in the default view.
			if ( empty( $status ) ) {
				$this->inject_missing_email_items( $product_id, $product_name, $order_id, $search );

				// Re-sort so injected rows appear in order ID descending (latest first).
				usort( $this->items, function ( $a, $b ) {
					return (int) $b['order_id'] - (int) $a['order_id'];
				} );
			}
		}
	}

	/**
	 * Pre-populate seat qty and file count caches for all visible items.
	 */
	private function preload_seat_and_file_data() {
		global $wpdb;

		$order_ids = array();
		$item_ids  = array();
		$prod_keys = array();

		foreach ( $this->items as $item ) {
			$order_ids[ $item['order_id'] ]    = true;
			$item_ids[ $item['order_item_id'] ] = $item['order_id'];
			$prod_keys[ $item['product_id'] . '|' . ( $item['variation_id'] ?? 0 ) ] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
			);
		}

		// Batch load order item quantities.
		foreach ( array_keys( $order_ids ) as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order ) {
				continue;
			}
			foreach ( $order->get_items() as $oi ) {
				if ( isset( $item_ids[ $oi->get_id() ] ) ) {
					$qty_refunded = abs( (int) $order->get_qty_refunded_for_item( $oi->get_id() ) );
					$this->item_qty_cache[ $oi->get_id() ] = max( 0, (int) $oi->get_quantity() - $qty_refunded );
				}
			}
		}

		// Batch load expected file counts.
		foreach ( $prod_keys as $key => $info ) {
			$resources = WGDP_Product_Meta::get_active_drive_resources( $info['product_id'], $info['variation_id'] ?: 0 );
			$this->expected_files_cache[ $key ] = count( $resources );
			$this->active_asset_ids_cache[ $key ] = wp_list_pluck( $resources, 'id' );
		}

		// Batch load active file counts and seat positions per order item.
		$table = $wpdb->prefix . 'wgdp_entitlements';

		$unique_item_ids = array_unique( array_keys( $item_ids ) );
		if ( ! empty( $unique_item_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $unique_item_ids ), '%d' ) );
			// Get distinct active recipients per order item with file counts, ordered by lowest recipient_index.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT order_item_id, recipient_email, MIN(recipient_index) AS min_index, COUNT(*) AS file_count
				 FROM {$table}
				 WHERE order_item_id IN ({$placeholders}) AND grant_status != 'revoked'
				 GROUP BY order_item_id, recipient_email
				 ORDER BY order_item_id, min_index ASC",
				$unique_item_ids
			), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// Build seat position map and file count cache from the single query.
			foreach ( $rows as $row ) {
				$oi_id = $row['order_item_id'];
				if ( ! isset( $this->seat_position_cache[ $oi_id ] ) ) {
					$this->seat_position_cache[ $oi_id ] = array();
				}
				// Label the seat with the recipient's actual recipient_index (1-based),
				// not a compacted active rank. Using the rank would renumber the
				// remaining seats after a middle seat is revoked (hiding the gap) and
				// make the over-allocation check ($seat > $qty) unreachable.
				$this->seat_position_cache[ $oi_id ][ $row['recipient_email'] ] = (int) $row['min_index'];

				$cache_key = $oi_id . '|' . $row['recipient_email'];
				$this->file_count_cache[ $cache_key ] = (int) $row['file_count'];
			}
		}
	}

	/**
	 * Inject synthetic rows for unassigned seats into the items list.
	 */
	private function inject_unassigned_seats() {
		// Collect order item IDs visible on this page.
		$seen_items = array();
		foreach ( $this->items as $item ) {
			$oi_id = $item['order_item_id'];
			if ( ! isset( $seen_items[ $oi_id ] ) ) {
				$seen_items[ $oi_id ] = $item; // Keep one row as a template.
			}
		}

		$new_rows = array();
		foreach ( $seen_items as $oi_id => $template ) {
			$qty          = $this->item_qty_cache[ $oi_id ] ?? 1;
			$taken        = isset( $this->seat_position_cache[ $oi_id ] ) ? array_values( $this->seat_position_cache[ $oi_id ] ) : array();
			$active_seats = count( $taken );

			if ( $active_seats >= $qty ) {
				continue;
			}

			// Seat numbers are now the recipients' real recipient_index values, so
			// they may be non-contiguous (e.g. seat 2 revoked leaves seats 1 and 3).
			// Fill the empty seat numbers within 1..qty rather than appending, so the
			// unassigned rows land in the actual gaps.
			$taken_lookup = array_flip( array_map( 'intval', $taken ) );
			$unassigned   = $qty - $active_seats;
			$filled       = 0;
			for ( $seat_num = 1; $seat_num <= $qty && $filled < $unassigned; $seat_num++ ) {
				if ( isset( $taken_lookup[ $seat_num ] ) ) {
					continue;
				}

				$new_rows[] = array(
					'_unassigned'         => true,
					'id'                  => 0,
					'order_id'            => $template['order_id'],
					'order_item_id'       => $oi_id,
					'product_id'          => $template['product_id'],
					'variation_id'        => $template['variation_id'] ?? 0,
					'cloud_asset_id'      => '',
					'recipient_email'     => '__unassigned_' . $filled,
					'recipient_index'     => $seat_num,
					'verification_status' => '',
					'grant_status'        => 'unassigned',
					'created_at'          => '',
					'grant_error'         => '',
					'provider_permission_id' => '',
					'account_id'          => '',
				);
				$filled++;
			}
		}

		if ( ! empty( $new_rows ) ) {
			$this->items = array_merge( $this->items, $new_rows );
		}
	}

	/**
	 * Inject fully-unassigned order items (no entitlements at all) into the
	 * default "show all" view so they are visible without clicking "Missing Email".
	 */
	private function inject_missing_email_items( $product_id, $product_name, $order_id, $search ) {
		$ent = WGDP_Entitlements::instance();

		// Collect order_item_ids already visible on this page.
		$seen_item_ids = array();
		foreach ( $this->items as $item ) {
			$seen_item_ids[ $item['order_item_id'] ] = true;
		}

		$result = $ent->get_unassigned_order_items( array(
			'per_page'     => 50,
			'page'         => 1,
			'product_id'   => $product_id,
			'product_name' => $product_name,
			'order_id'     => $order_id,
			'search'       => $search,
		) );

		foreach ( $result['items'] as $ua ) {
			// Skip if this order item already has rows on the page.
			if ( isset( $seen_item_ids[ $ua['order_item_id'] ] ) ) {
				continue;
			}
			$this->items[] = array(
				'_unassigned'         => true,
				'id'                  => 0,
				'order_id'            => $ua['order_id'],
				'order_item_id'       => $ua['order_item_id'],
				'product_id'          => $ua['product_id'],
				'variation_id'        => $ua['variation_id'] ?? 0,
				'cloud_asset_id'      => '',
				'recipient_email'     => '__unassigned_0',
				'recipient_index'     => 1,
				'verification_status' => '',
				'grant_status'        => 'unassigned',
				'created_at'          => $ua['created_at'] ?? '',
				'grant_error'         => '',
				'provider_permission_id' => '',
				'account_id'          => '',
			);
		}
	}

	/**
	 * Resolve Drive names for all items in the current result set.
	 */
	private function resolve_all_drive_names() {
		$pairs = array();
		foreach ( $this->items as $item ) {
			$asset_id   = $item['cloud_asset_id'] ?? '';
			$account_id = $item['account_id'] ?? '';
			if ( $asset_id && ! isset( $pairs[ $asset_id ] ) ) {
				$pairs[ $asset_id ] = $account_id;
			}
		}

		if ( empty( $pairs ) ) {
			return;
		}

		$this->drive_names = WGDP_Admin::resolve_drive_names( $pairs );
	}
}
