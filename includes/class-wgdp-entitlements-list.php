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
	 * AJAX: Bulk revoke — expands each selected ID to include all sibling files for that recipient.
	 */
	public function ajax_bulk_revoke() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$ids   = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) );
		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();

		// Expand selected IDs to full recipient groups (all files per recipient per order item).
		$groups  = array(); // keyed by "order_item_id|email"
		$revoked = array(); // track already-processed IDs

		foreach ( $ids as $id ) {
			$row = $ent->get( $id );
			if ( ! $row || 'revoked' === $row['grant_status'] ) {
				continue;
			}
			$key = $row['order_item_id'] . '|' . $row['recipient_email'];
			if ( isset( $groups[ $key ] ) ) {
				continue; // Already queued this recipient group.
			}
			$siblings = $ent->get_siblings( $row['order_item_id'], $row['recipient_email'] );
			$groups[ $key ] = array(
				'rows'  => ! empty( $siblings ) ? $siblings : array( $row ),
				'email' => $row['recipient_email'],
				'row'   => $row,
			);
		}

		$count = 0;
		foreach ( $groups as $group ) {
			foreach ( $group['rows'] as $sibling ) {
				if ( 'revoked' === $sibling['grant_status'] || isset( $revoked[ $sibling['id'] ] ) ) {
					continue;
				}
				if ( 'granted' === $sibling['grant_status'] && ! empty( $sibling['provider_permission_id'] ) ) {
					if ( ! $ent->permission_is_shared( $sibling['provider_permission_id'], $sibling['id'] ) ) {
						$drive->delete_permission( $sibling['cloud_asset_id'], $sibling['provider_permission_id'], $sibling['account_id'] );
					}
				}
				$ent->mark_revoked( $sibling['id'] );
				$revoked[ $sibling['id'] ] = true;
				$count++;
			}
			WGDP_Notification_Email::send_access_revoked( $group['email'], WGDP_Entitlements::get_product_name( $group['row'] ) );
		}

		delete_transient( 'wgdp_permission_counts' );
		wp_send_json_success( sprintf( 'Revoked %d entitlement(s).', $count ) );
	}
}
