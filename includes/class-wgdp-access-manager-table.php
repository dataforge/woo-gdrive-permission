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
			'recipient_index'     => '#',
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

		if ( isset( $this->drive_names[ $asset_id ] ) ) {
			$info = $this->drive_names[ $asset_id ];
			$name = $info['name'] ?? substr( $asset_id, 0, 12 ) . '...';
			$link = $info['webViewLink'] ?? '';
			if ( $link ) {
				return '<a href="' . esc_url( $link ) . '" target="_blank" title="' . esc_attr( $name ) . '">' . esc_html( $name ) . '</a>';
			}
			return esc_html( $name );
		}

		return '<code>' . esc_html( substr( $asset_id, 0, 12 ) ) . '...</code>';
	}

	/**
	 * Recipient Email column — with inline edit.
	 */
	public function column_recipient_email( $item ) {
		$email = esc_attr( $item['recipient_email'] );
		$id    = esc_attr( $item['id'] );

		return '<span class="wgdp-am-email-display">' . esc_html( $item['recipient_email'] ) . '</span>'
			. '<span class="wgdp-am-email-edit" style="display:none;">'
			. '<input type="email" class="wgdp-am-email-input" value="' . $email . '" style="width:200px;" />'
			. '<button class="button button-small wgdp-am-email-save" data-entitlement-id="' . $id . '">Save</button> '
			. '<button class="button button-small wgdp-am-email-cancel">Cancel</button>'
			. '</span>';
	}

	/**
	 * Recipient index column.
	 */
	public function column_recipient_index( $item ) {
		return esc_html( $item['recipient_index'] );
	}

	/**
	 * Verification status column.
	 */
	public function column_verification_status( $item ) {
		$status = $item['verification_status'];
		$class  = 'wgdp-vstatus--' . esc_attr( $status );
		return '<span class="wgdp-status-badge ' . $class . '">' . esc_html( ucfirst( $status ) ) . '</span>';
	}

	/**
	 * Grant status column.
	 */
	public function column_grant_status( $item ) {
		$status = $item['grant_status'];
		$class  = 'wgdp-gstatus--' . esc_attr( $status );
		$label  = 'pending_release' === $status ? 'Pending Release' : ucfirst( $status );
		$html   = '<span class="wgdp-status-badge ' . $class . '">' . esc_html( $label ) . '</span>';

		if ( 'error' === $status && ! empty( $item['grant_error'] ) ) {
			$html .= '<br><small style="color:#d63638;">' . esc_html( $item['grant_error'] ) . '</small>';
		}

		return $html;
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
		if ( 'missing_email' === $this->display_mode ) {
			return '<div class="wgdp-am-assign-form">'
				. '<input type="email" class="wgdp-am-assign-email-input" placeholder="recipient@gmail.com" style="width:200px;" />'
				. ' <button class="button button-small wgdp-am-assign-btn"'
				. ' data-order-id="' . esc_attr( $item['order_id'] ) . '"'
				. ' data-order-item-id="' . esc_attr( $item['order_item_id'] ) . '">Assign</button>'
				. '</div>';
		}

		$html = '';

		if ( 'revoked' !== $item['grant_status'] ) {
			// Change Email button.
			$html .= '<button type="button" class="button button-small wgdp-am-change-email-btn">Change Email</button> ';

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

		// Verify result span.
		$html .= '<span class="wgdp-am-verify-result" style="margin-left:6px;"></span>';

		return $html;
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

		// Product search (autocomplete).
		$product_label = '';
		if ( $current_product ) {
			$product       = wc_get_product( $current_product );
			$product_label = $product ? $product->get_name() : 'Product #' . $current_product;
		}
		echo '<input type="hidden" name="am_product" class="wgdp-product-filter-id" value="' . esc_attr( $current_product ) . '" />';
		echo '<input type="text" class="wgdp-product-filter" placeholder="Filter by product&hellip;" value="' . esc_attr( $product_label ) . '" style="width:250px;" autocomplete="off" />';

		submit_button( 'Filter', '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();

		$status     = isset( $_GET['am_status'] ) ? sanitize_text_field( wp_unslash( $_GET['am_status'] ) ) : '';
		$product_id = isset( $_GET['am_product'] ) ? absint( $_GET['am_product'] ) : 0;
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$ent = WGDP_Entitlements::instance();

		if ( 'missing_email' === $status || 'missing_email' === $this->display_mode ) {
			$this->display_mode = 'missing_email';

			$result = $ent->get_unassigned_order_items( array(
				'per_page'   => $per_page,
				'page'       => $page,
				'product_id' => $product_id,
				'search'     => $search,
			) );
		} else {
			$this->display_mode = 'entitlements';

			// Map status filter to query args.
			$query_args = array(
				'per_page'   => $per_page,
				'page'       => $page,
				'orderby'    => isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'id',
				'order'      => isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC',
				'search'     => $search,
				'product_id' => $product_id,
			);

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
					$query_args['grant_status'] = 'error';
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
