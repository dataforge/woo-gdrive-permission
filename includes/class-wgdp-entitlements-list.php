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
		$count = 0;
		// issue_otp_for_recipient_group() resets the OTP/claim token for the whole
		// recipient group (order_item_id + recipient_email) and invalidates any
		// prior token. Selecting several file rows for one recipient must therefore
		// resend only once — otherwise each call invalidates the email the previous
		// call just sent, so only the last message would work.
		$seen_groups = array();

		foreach ( $ids as $id ) {
			$row = $ent->get( $id );
			if ( ! $row || 'revoked' === $row['grant_status'] || 'verified' === $row['verification_status'] ) {
				continue;
			}
			$group_key = $row['order_item_id'] . '|' . $row['recipient_email'];
			if ( isset( $seen_groups[ $group_key ] ) ) {
				continue;
			}
			$seen_groups[ $group_key ] = true;

			$tokens = $ent->issue_otp_for_recipient_group( $id );
			if ( is_wp_error( $tokens ) ) {
				continue;
			}
			$order = wc_get_order( $row['order_id'] );
			$item  = $order ? $order->get_item( $row['order_item_id'] ) : null;
			if ( $order && $item ) {
				$mail_result = WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
				if ( ! is_wp_error( $mail_result ) ) {
					$count++;
				}
			}
		}

		if ( $count > 0 ) {
			delete_transient( 'wgdp_permission_counts' );
		}

		wp_send_json_success( sprintf( 'Resent verification email to %d recipient(s).', $count ) );
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

		$count  = 0;
		$errors = 0;
		// Keyed by recipient email only (not order_item_id|email), so a recipient with
		// entitlements across multiple order items in this bulk run is notified once —
		// matching the admin-tab bulk revoke's per-recipient dedup instead of emailing
		// once per order item.
		$notified_emails = array();
		foreach ( $groups as $group ) {
			$group_revoked_any = false;
			foreach ( $group['rows'] as $sibling ) {
				if ( 'revoked' === $sibling['grant_status'] || isset( $revoked[ $sibling['id'] ] ) ) {
					continue;
				}
				$result = $ent->revoke_with_drive_delete( $sibling, WGDP_Entitlements::REVOCATION_REASON_MANUAL );
				if ( is_wp_error( $result ) ) {
					$errors++;
					continue;
				}
				$revoked[ $sibling['id'] ] = true;
				$group_revoked_any = true;
				$count++;
			}
			// Notify the customer whenever at least one sibling was actually revoked,
			// even if others failed — the customer has genuinely lost access.
			if ( $group_revoked_any ) {
				$notified_emails[ $group['email'] ]['row']                                                             = $notified_emails[ $group['email'] ]['row'] ?? $group['row'];
				$notified_emails[ $group['email'] ]['products'][ WGDP_Entitlements::get_product_name( $group['row'] ) ] = true;
			}
		}
		foreach ( $notified_emails as $email => $data ) {
			$product_name = implode( ', ', array_keys( $data['products'] ) );
			WGDP_Notification_Email::send_access_revoked( $email, $product_name, $data['row']['order_id'] ?? 0 );
		}

		delete_transient( 'wgdp_permission_counts' );
		$msg = sprintf( 'Revoked %d entitlement(s).', $count );
		if ( $errors ) {
			$msg .= sprintf( ' %d entitlement(s) could not be removed from Drive and will be retried.', $errors );
		}
		wp_send_json_success( $msg );
	}
}
