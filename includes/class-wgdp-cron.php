<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Cron {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wgdp_retry_failed_grants', array( $this, 'retry_failed_grants' ) );
		add_action( 'wgdp_expire_stale_entitlements', array( $this, 'expire_stale_entitlements' ) );
	}

	/**
	 * Retry Drive API grants for verified entitlements with errors,
	 * and process any pending_release overflow for already-released products.
	 */
	public function retry_failed_grants() {
		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();
		$auth  = WGDP_Google_Auth::instance();

		// Pick up pending_release overflow for already-released products.
		$pending_release_rows = $ent->get_stale_pending_release( 20 );
		foreach ( $pending_release_rows as $row ) {
			if ( ! WGDP_Release_Gate::is_product_released( $row['product_id'] ) ) {
				continue;
			}
			if ( empty( $row['account_id'] ) || ! $auth->is_account_connected( $row['account_id'] ) ) {
				continue;
			}
			$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row );
			if ( is_wp_error( $result ) ) {
				$ent->mark_error( $row['id'], $result->get_error_message() );
			}
		}

		$rows = $ent->get_failed_verified( 20 );

		if ( empty( $rows ) ) {
			if ( ! empty( $pending_release_rows ) ) {
				delete_transient( 'wgdp_permission_counts' );
			}
			return;
		}

		foreach ( $rows as $row ) {
			// Skip if product is not yet released.
			if ( ! WGDP_Release_Gate::is_product_released( $row['product_id'] ) ) {
				continue;
			}

			// Skip if account is not connected.
			if ( empty( $row['account_id'] ) || ! $auth->is_account_connected( $row['account_id'] ) ) {
				continue;
			}

			// Dedup check.
			$existing = $ent->get_by_email_and_asset( $row['recipient_email'], $row['cloud_asset_id'] );
			if ( $existing && ! empty( $existing['provider_permission_id'] ) && (int) $existing['id'] !== (int) $row['id'] ) {
				$ent->mark_granted( $row['id'], $existing['provider_permission_id'] );

				$resource_type = $this->get_resource_type( $row );
				$drive_link    = WGDP_Google_Drive::build_web_link( $row['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
				$product_name  = $this->get_product_name( $row );
				WGDP_Notification_Email::send_access_granted( $row['recipient_email'], $drive_link, $product_name, $resource_type );
				continue;
			}

			$result = $drive->create_permission(
				$row['cloud_asset_id'],
				$row['recipient_email'],
				null,
				$row['account_id']
			);

			if ( is_wp_error( $result ) ) {
				$ent->mark_error( $row['id'], $result->get_error_message() );
				continue;
			}

			$permission_id = $result['id'] ?? '';
			$ent->mark_granted( $row['id'], $permission_id );

			$resource_type = $this->get_resource_type( $row );
			$drive_link    = WGDP_Google_Drive::build_web_link( $row['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
			$product_name  = $this->get_product_name( $row );
			WGDP_Notification_Email::send_access_granted( $row['recipient_email'], $drive_link, $product_name, $resource_type );

			$order = wc_get_order( $row['order_id'] );
			if ( $order ) {
				$order->add_order_note( sprintf(
					'WGDP: Retry successful — granted Drive access to %s for "%s" (entitlement #%d)',
					$row['recipient_email'],
					$product_name,
					$row['id']
				) );
			}
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Expire stale entitlements (pending with expired claim tokens).
	 */
	public function expire_stale_entitlements() {
		WGDP_Entitlements::instance()->expire_stale();
		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Schedule cron jobs.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'wgdp_retry_failed_grants' ) ) {
			wp_schedule_event( time(), 'every_20_minutes', 'wgdp_retry_failed_grants' );
		}
		if ( ! wp_next_scheduled( 'wgdp_expire_stale_entitlements' ) ) {
			wp_schedule_event( time(), 'hourly', 'wgdp_expire_stale_entitlements' );
		}
	}

	/**
	 * Clear cron jobs.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'wgdp_retry_failed_grants' );
		wp_clear_scheduled_hook( 'wgdp_expire_stale_entitlements' );
	}

	private function get_resource_type( $row ) {
		$check_id = $row['variation_id'] ?: $row['product_id'];
		$type = get_post_meta( $check_id, '_wgdp_drive_resource_type', true );
		if ( empty( $type ) ) {
			$type = get_post_meta( $row['product_id'], '_wgdp_drive_resource_type', true );
		}
		return $type ?: 'file';
	}

	private function get_product_name( $row ) {
		$id = $row['variation_id'] ?: $row['product_id'];
		$product = wc_get_product( $id );
		return $product ? $product->get_name() : 'Product #' . $id;
	}
}
