<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WGDP_Entitlements_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'entitlement',
			'plural'   => 'entitlements',
			'ajax'     => false,
		) );
	}

	/**
	 * Define columns.
	 */
	public function get_columns() {
		return array(
			'cb'                  => '<input type="checkbox" />',
			'order_id'            => 'Order',
			'created_at'          => 'Date',
			'product'             => 'Product',
			'recipient_email'     => 'Recipient',
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
		return array(
			'order_id'   => array( 'order_id', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Define bulk actions.
	 */
	public function get_bulk_actions() {
		return array(
			'resend_otp' => 'Resend OTP',
			'revoke'     => 'Revoke',
		);
	}

	/**
	 * Checkbox column.
	 */
	public function column_cb( $item ) {
		return '<input type="checkbox" name="entitlement[]" value="' . esc_attr( $item['id'] ) . '" />';
	}

	/**
	 * Order column with link.
	 */
	public function column_order_id( $item ) {
		$order = wc_get_order( $item['order_id'] );
		if ( ! $order ) {
			return '#' . esc_html( $item['order_id'] );
		}
		return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $item['order_id'] ) . '</a>';
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
		$id = $item['variation_id'] ?: $item['product_id'];
		$product = wc_get_product( $id );
		return $product ? esc_html( $product->get_name() ) : 'Product #' . esc_html( $id );
	}

	/**
	 * Recipient email column.
	 */
	public function column_recipient_email( $item ) {
		return esc_html( $item['recipient_email'] );
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
	 * Actions column.
	 */
	public function column_actions( $item ) {
		$html = '';

		if ( 'revoked' !== $item['grant_status'] ) {
			if ( 'pending' === $item['verification_status'] || 'expired' === $item['verification_status'] ) {
				$html .= '<button type="button" class="button button-small wgdp-resend-otp-btn" '
					. 'data-entitlement-id="' . esc_attr( $item['id'] ) . '">Resend OTP</button> ';
			}
			$html .= '<button type="button" class="button button-small wgdp-revoke-entitlement-btn" '
				. 'data-entitlement-id="' . esc_attr( $item['id'] ) . '" '
				. 'style="color:#b32d2e;">Revoke</button>';
		}

		return $html;
	}

	/**
	 * Extra table nav for filters.
	 */
	public function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_vs = isset( $_GET['verification_status'] ) ? sanitize_text_field( wp_unslash( $_GET['verification_status'] ) ) : '';
		$current_gs = isset( $_GET['grant_status'] ) ? sanitize_text_field( wp_unslash( $_GET['grant_status'] ) ) : '';

		echo '<div class="alignleft actions">';

		echo '<select name="verification_status">';
		echo '<option value="">All Verification</option>';
		foreach ( array( 'pending', 'verified', 'expired' ) as $vs ) {
			$selected = selected( $current_vs, $vs, false );
			echo '<option value="' . esc_attr( $vs ) . '"' . $selected . '>' . esc_html( ucfirst( $vs ) ) . '</option>';
		}
		echo '</select>';

		echo '<select name="grant_status">';
		echo '<option value="">All Grant Status</option>';
		$grant_statuses = array(
			'pending'         => 'Pending',
			'pending_release' => 'Pending Release',
			'granted'         => 'Granted',
			'error'           => 'Error',
			'revoked'         => 'Revoked',
		);
		foreach ( $grant_statuses as $gs => $label ) {
			$sel = selected( $current_gs, $gs, false );
			echo '<option value="' . esc_attr( $gs ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		submit_button( 'Filter', '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();

		$args = array(
			'per_page'            => $per_page,
			'page'                => $page,
			'orderby'             => isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'id',
			'order'               => isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : 'DESC',
			'search'              => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'verification_status' => isset( $_GET['verification_status'] ) ? sanitize_text_field( wp_unslash( $_GET['verification_status'] ) ) : '',
			'grant_status'        => isset( $_GET['grant_status'] ) ? sanitize_text_field( wp_unslash( $_GET['grant_status'] ) ) : '',
		);

		$result = WGDP_Entitlements::instance()->get_items_for_list_table( $args );

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
	}
}
