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
		add_action( 'wgdp_process_backfill', array( $this, 'process_backfill' ) );
	}

	/**
	 * Check if a resource is retired in product meta.
	 */
	private function is_resource_retired( $product_id, $variation_id, $asset_id ) {
		$resources = WGDP_Product_Meta::get_drive_resources( $product_id, $variation_id );
		foreach ( $resources as $r ) {
			if ( $r['id'] === $asset_id ) {
				return ! empty( $r['status'] ) && 'active' !== $r['status'];
			}
		}
		return false;
	}

	/**
	 * Resolve resource name from product meta.
	 */
	private function get_resource_name( $row ) {
		$resources = WGDP_Product_Meta::get_drive_resources( $row['product_id'], $row['variation_id'] ?: 0 );
		foreach ( $resources as $r ) {
			if ( $r['id'] === $row['cloud_asset_id'] ) {
				return $r['name'] ?: $r['id'];
			}
		}
		return $row['cloud_asset_id'];
	}

	/**
	 * Collect granted entitlement info for batch notification.
	 */
	private function collect_granted( &$granted_by_recipient, $row ) {
		$resource_type = WGDP_Entitlements::get_resource_type( $row );
		$drive_link    = WGDP_Google_Drive::build_web_link( $row['cloud_asset_id'], $resource_type === 'folder' ? 'application/vnd.google-apps.folder' : '' );
		$origin        = $row['origin'] ?? 'order';
		$key           = $row['order_item_id'] . '|' . $row['recipient_email'] . '|' . $origin;

		if ( ! isset( $granted_by_recipient[ $key ] ) ) {
			$granted_by_recipient[ $key ] = array(
				'email'        => $row['recipient_email'],
				'product_name' => WGDP_Entitlements::get_product_name( $row ),
				'order_id'     => $row['order_id'],
				'origin'       => $origin,
				'links'        => array(),
			);
		}

		$granted_by_recipient[ $key ]['links'][] = array(
			'name' => $this->get_resource_name( $row ),
			'link' => $drive_link,
		);
	}

	/**
	 * Send emails grouped by origin.
	 *
	 * Sends to both the Google account recipient and the order's billing email
	 * (if they differ) so the purchaser is always informed.
	 */
	private function send_grouped_emails( $granted_by_recipient ) {
		foreach ( $granted_by_recipient as $group ) {
			$recipients = array( $group['email'] );

			// Also notify the billing email if it differs from the Google account.
			$order = wc_get_order( $group['order_id'] );
			if ( $order ) {
				$billing_email = strtolower( trim( $order->get_billing_email() ) );
				if ( $billing_email && $billing_email !== strtolower( trim( $group['email'] ) ) ) {
					$recipients[] = $billing_email;
				}
			}

			foreach ( $recipients as $to ) {
				if ( 'backfill' === $group['origin'] ) {
					WGDP_Notification_Email::send_new_files_added( $to, $group['links'], $group['product_name'] );
				} else {
					if ( count( $group['links'] ) > 1 ) {
						WGDP_Notification_Email::send_access_granted_batch( $to, $group['links'], $group['product_name'] );
					} else {
						$fl = $group['links'][0];
						WGDP_Notification_Email::send_access_granted( $to, $fl['link'], $group['product_name'] );
					}
				}
			}
		}
	}

	/**
	 * Retry Drive API grants for verified entitlements with errors,
	 * and process any pending_release overflow for already-released products.
	 */
	public function retry_failed_grants() {
		$ent   = WGDP_Entitlements::instance();
		$drive = WGDP_Google_Drive::instance();
		$auth  = WGDP_Google_Auth::instance();

		// Collect granted files per recipient to send batch emails.
		$granted_by_recipient = array();

		// Pick up pending_release overflow for already-released items.
		$pending_release_rows = $ent->get_stale_pending_release( 20 );
		foreach ( $pending_release_rows as $row ) {
			if ( ! WGDP_Release_Gate::is_item_released( (int) $row['product_id'], (int) ( $row['variation_id'] ?? 0 ) ) ) {
				continue;
			}
			if ( empty( $row['account_id'] ) || ! $auth->is_account_connected( $row['account_id'] ) ) {
				continue;
			}
			// Skip retired resources.
			if ( $this->is_resource_retired( (int) $row['product_id'], (int) $row['variation_id'], $row['cloud_asset_id'] ) ) {
				continue;
			}
			$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row, true );
			if ( is_wp_error( $result ) ) {
				$ent->mark_error( $row['id'], $result->get_error_message() );
			} else {
				$this->collect_granted( $granted_by_recipient, $row );
				WGDP_Order_Handler::instance()->maybe_auto_complete_order( $row['order_id'] );
			}
		}

		$rows = $ent->get_failed_verified( 20 );

		if ( empty( $rows ) && empty( $pending_release_rows ) ) {
			return;
		}

		// Track retired asset IDs already noted per order to avoid duplicate notes.
		$retired_noted = array();

		foreach ( $rows as $row ) {
			// Skip if item is not yet released.
			if ( ! WGDP_Release_Gate::is_item_released( (int) $row['product_id'], (int) ( $row['variation_id'] ?? 0 ) ) ) {
				continue;
			}

			// Skip if account is not connected.
			if ( empty( $row['account_id'] ) || ! $auth->is_account_connected( $row['account_id'] ) ) {
				continue;
			}

			// Skip retired resources (don't increment retry count).
			if ( $this->is_resource_retired( (int) $row['product_id'], (int) $row['variation_id'], $row['cloud_asset_id'] ) ) {
				continue;
			}

			// Dedup check.
			$existing = $ent->get_by_email_and_asset( $row['recipient_email'], $row['cloud_asset_id'] );
			if ( $existing && ! empty( $existing['provider_permission_id'] ) && (int) $existing['id'] !== (int) $row['id'] ) {
				$ent->mark_granted( $row['id'], $existing['provider_permission_id'] );
			} else {
				$result = $drive->create_permission(
					$row['cloud_asset_id'],
					$row['recipient_email'],
					null,
					$row['account_id']
				);

				if ( is_wp_error( $result ) ) {
					// Auto-retire on confirmed 404.
					if ( WGDP_Google_Drive::is_file_not_found( $result ) ) {
						$verify = $drive->get_file( $row['cloud_asset_id'], $row['account_id'] );
						if ( WGDP_Google_Drive::is_file_not_found( $verify ) ) {
							WGDP_Product_Meta::maybe_retire_resource(
								(int) $row['product_id'], (int) $row['variation_id'],
								$row['cloud_asset_id'], 'retired_missing'
							);
							$ent->mark_error( $row['id'], 'File removed from Google Drive (auto-retired)' );

							// One order note per asset retirement.
							$note_key = $row['order_id'] . '|' . $row['cloud_asset_id'];
							if ( ! isset( $retired_noted[ $note_key ] ) ) {
								$retired_noted[ $note_key ] = true;
								$order = wc_get_order( $row['order_id'] );
								if ( $order ) {
									$res_name = $this->get_resource_name( $row );
									$order->add_order_note( sprintf(
										'WGDP: File %s auto-retired — removed from Google Drive',
										$res_name
									) );
								}
							}
							error_log( sprintf(
								'WGDP: Auto-retired resource %s on product %d (variation %d) — file not found on Drive',
								$row['cloud_asset_id'], $row['product_id'], $row['variation_id']
							) );
							continue;
						}
					}

					$ent->mark_error( $row['id'], $result->get_error_message() );
					continue;
				}

				$permission_id = $result['id'] ?? '';
				$ent->mark_granted( $row['id'], $permission_id );

				$order = wc_get_order( $row['order_id'] );
				if ( $order ) {
					$product_name = WGDP_Entitlements::get_product_name( $row );
					$order->add_order_note( sprintf(
						'WGDP: Retry successful — granted Drive access to %s for "%s" (entitlement #%d)',
						$row['recipient_email'],
						$product_name,
						$row['id']
					) );
				}
			}

			$this->collect_granted( $granted_by_recipient, $row );
			WGDP_Order_Handler::instance()->maybe_auto_complete_order( $row['order_id'] );
		}

		// Send emails grouped by origin.
		$this->send_grouped_emails( $granted_by_recipient );

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Process backfill jobs — create entitlements for new resources on existing orders.
	 */
	public function process_backfill() {
		global $wpdb;
		$table = WGDP_DB::get_backfill_table_name();
		$now   = current_time( 'mysql', true );

		// Reclaim stale processing jobs (worker died).
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', started_at = NULL, attempts = attempts + 1 WHERE status = 'processing' AND started_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			gmdate( 'Y-m-d H:i:s', time() - 600 )
		) );

		// Mark permanently failed.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE {$table} SET status = 'failed', last_error = 'Exceeded max attempts (stale recovery)' WHERE status = 'pending' AND attempts >= 5" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Atomic claim.
		$job_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT id FROM {$table} WHERE status = 'pending' ORDER BY id LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( ! $job_id ) {
			return;
		}

		$claimed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'processing', started_at = %s WHERE id = %d AND status = 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now, $job_id
		) );
		if ( ! $claimed ) {
			return;
		}

		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$job_id
		), ARRAY_A );

		// Re-read account_id from product meta (may have changed since job creation).
		$account_id = WGDP_Product_Meta::get_account_for_item(
			(int) $job['product_id'], (int) $job['variation_id']
		);

		$asset_ids     = json_decode( $job['asset_ids'], true );
		$resources     = array();
		$all_resources = WGDP_Product_Meta::get_active_drive_resources(
			(int) $job['product_id'], (int) $job['variation_id']
		);
		foreach ( $all_resources as $r ) {
			if ( in_array( $r['id'], $asset_ids, true ) ) {
				$resources[] = $r;
			}
		}

		if ( empty( $resources ) ) {
			$wpdb->update( $table, array(
				'status' => 'completed', 'processed_at' => $now,
			), array( 'id' => $job['id'] ) );
			return;
		}

		$ent    = WGDP_Entitlements::instance();
		$result = $ent->backfill_new_resources(
			(int) $job['product_id'], (int) $job['variation_id'],
			$resources, $account_id,
			200,
			(int) $job['cursor_item_id'], $job['cursor_email']
		);

		$new_total = (int) $job['total_created'] + $result['created'];

		// Conditional update: only advance cursor / complete if the job is still
		// 'processing'. If queue_backfill() merged new assets and reset the job
		// to 'pending' while this batch was running, we must not overwrite that
		// cursor reset — the next tick will re-claim and re-scan from the start.
		if ( $result['has_more'] ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET cursor_item_id = %d, cursor_email = %s, total_created = %d, status = 'pending', started_at = NULL WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$result['last_item_id'], $result['last_email'], $new_total, $job['id']
			) );
			wp_schedule_single_event( time() + 5, 'wgdp_process_backfill' );
		} else {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status = 'completed', total_created = %d, processed_at = %s WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$new_total, $now, $job['id']
			) );
		}

		// Increment attempts on every batch (unconditional — tracks total work done).
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$job['id']
		) );

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

}
