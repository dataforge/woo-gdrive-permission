<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Entitlements_List {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_wgdp_bulk_resend_otp', array( $this, 'ajax_bulk_resend_otp' ) );
		add_action( 'wp_ajax_wgdp_bulk_revoke', array( $this, 'ajax_bulk_revoke' ) );
	}

	/**
	 * Get permission counts (cached in transient).
	 */
	public function get_permission_counts() {
		$counts = get_transient( 'wgdp_permission_counts' );
		if ( false !== $counts ) {
			return $counts;
		}

		$counts = WGDP_Entitlements::instance()->count_by_status();
		set_transient( 'wgdp_permission_counts', $counts, 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/**
	 * Render the entitlements tab content (called by the parent page).
	 */
	public function render_tab_content() {
		// Process bulk actions first so counts reflect changes.
		$this->process_bulk_actions();

		$counts = $this->get_permission_counts();

		// Summary cards.
		echo '<div class="wgdp-summary-cards">';
		$cards = array(
			'pending_verification' => array( 'label' => 'Pending Verification', 'class' => 'pending' ),
			'verified'             => array( 'label' => 'Verified',             'class' => 'verified' ),
			'pending_release'      => array( 'label' => 'Pending Release',      'class' => 'pending-release' ),
			'granted'              => array( 'label' => 'Granted',              'class' => 'granted' ),
			'error'                => array( 'label' => 'Error',                'class' => 'failed' ),
			'revoked'              => array( 'label' => 'Revoked',              'class' => 'revoked' ),
		);
		foreach ( $cards as $key => $card ) {
			echo '<div class="wgdp-summary-card wgdp-summary-card--' . esc_attr( $card['class'] ) . '">';
			echo '<span class="wgdp-summary-count">' . esc_html( $counts[ $key ] ?? 0 ) . '</span>';
			echo '<span class="wgdp-summary-label">' . esc_html( $card['label'] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		// Render the list table.
		$table = new WGDP_Entitlements_Table();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wgdp" />';
		echo '<input type="hidden" name="tab" value="entitlements" />';
		$table->search_box( 'Search', 'wgdp-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Process bulk actions from the list table.
	 */
	private function process_bulk_actions() {
		if ( ! isset( $_GET['action'] ) || ! isset( $_GET['entitlement'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		$ids    = array_map( 'absint', (array) $_GET['entitlement'] );

		if ( empty( $ids ) ) {
			return;
		}

		check_admin_referer( 'bulk-entitlements' );

		$ent = WGDP_Entitlements::instance();
		$otp = WGDP_OTP::instance();

		if ( 'resend_otp' === $action ) {
			$count = 0;
			foreach ( $ids as $id ) {
				$row = $ent->get( $id );
				if ( $row && 'revoked' !== $row['grant_status'] && 'verified' !== $row['verification_status'] ) {
					$tokens = $otp->issue_otp_for_entitlement( $id );
					$order  = wc_get_order( $row['order_id'] );
					$item   = $order ? $order->get_item( $row['order_item_id'] ) : null;
					if ( $order && $item ) {
						WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
						$count++;
					}
				}
			}
			delete_transient( 'wgdp_permission_counts' );
			add_settings_error( 'wgdp', 'resent', sprintf( 'Resent OTP to %d entitlement(s).', $count ), 'success' );
		} elseif ( 'revoke' === $action ) {
			$count = 0;
			$drive = WGDP_Google_Drive::instance();
			foreach ( $ids as $id ) {
				$row = $ent->get( $id );
				if ( $row && 'revoked' !== $row['grant_status'] ) {
					if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
						if ( ! $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
							$drive->delete_permission( $row['cloud_asset_id'], $row['provider_permission_id'], $row['account_id'] );
						}
					}
					$ent->mark_revoked( $id );
					$count++;
				}
			}
			delete_transient( 'wgdp_permission_counts' );
			add_settings_error( 'wgdp', 'revoked', sprintf( 'Revoked %d entitlement(s).', $count ), 'success' );
		}

		settings_errors( 'wgdp' );
	}

	/**
	 * AJAX: Bulk resend OTP.
	 */
	public function ajax_bulk_resend_otp() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$ids = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
		$ent = WGDP_Entitlements::instance();
		$otp = WGDP_OTP::instance();
		$count = 0;

		foreach ( $ids as $id ) {
			$row = $ent->get( $id );
			if ( $row && 'revoked' !== $row['grant_status'] && 'verified' !== $row['verification_status'] ) {
				$tokens = $otp->issue_otp_for_entitlement( $id );
				$order  = wc_get_order( $row['order_id'] );
				$item   = $order ? $order->get_item( $row['order_item_id'] ) : null;
				if ( $order && $item ) {
					WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
					$count++;
				}
			}
		}

		wp_send_json_success( sprintf( 'Resent OTP to %d entitlement(s).', $count ) );
	}

	/**
	 * AJAX: Bulk revoke.
	 */
	public function ajax_bulk_revoke() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$ids   = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();
		$count = 0;

		foreach ( $ids as $id ) {
			$row = $ent->get( $id );
			if ( $row && 'revoked' !== $row['grant_status'] ) {
				if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
					if ( ! $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
						$drive->delete_permission( $row['cloud_asset_id'], $row['provider_permission_id'], $row['account_id'] );
					}
				}
				$ent->mark_revoked( $id );
				$count++;
			}
		}

		delete_transient( 'wgdp_permission_counts' );
		wp_send_json_success( sprintf( 'Revoked %d entitlement(s).', $count ) );
	}
}
