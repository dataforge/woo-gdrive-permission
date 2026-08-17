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
		add_action( 'wgdp_recalculate_sales_counters', array( $this, 'recalculate_sales_counters' ), 10, 2 );
	}

	/**
	 * Background job: recalculate the product- and variation-level sales
	 * counters. Queued (rather than run inline) because it scans the full
	 * order history for the product, which is too slow for a synchronous
	 * AJAX request.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID.
	 */
	public function recalculate_sales_counters( $product_id, $variation_id ) {
		WGDP_Release_Gate::recalculate_sales_counter( $product_id );
		if ( $variation_id ) {
			WGDP_Release_Gate::recalculate_variation_sales_counter( $product_id, $variation_id );
		}
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
	 * Build the recipient list for a notification: the Google account recipient
	 * plus the order's billing email, if it differs, so the purchaser is always
	 * informed (e.g. on a gift order where the recipient isn't the purchaser).
	 */
	private function recipients_with_billing_fallback( $email, $order_id ) {
		$recipients = array( $email );

		$order = wc_get_order( $order_id );
		if ( $order ) {
			$billing_email = strtolower( trim( $order->get_billing_email() ) );
			if ( $billing_email && $billing_email !== strtolower( trim( $email ) ) ) {
				$recipients[] = $billing_email;
			}
		}

		return $recipients;
	}

	/**
	 * Send emails grouped by origin.
	 *
	 * Sends to both the Google account recipient and the order's billing email
	 * (if they differ) so the purchaser is always informed.
	 */
	private function send_grouped_emails( $granted_by_recipient ) {
		foreach ( $granted_by_recipient as $group ) {
			$recipients = $this->recipients_with_billing_fallback( $group['email'], $group['order_id'] );

			foreach ( $recipients as $to ) {
				if ( 'backfill' === $group['origin'] ) {
					WGDP_Notification_Email::send_new_files_added( $to, $group['links'], $group['product_name'], $group['order_id'] );
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
	 * Alert the admin that this retry cron is stuck skipping rows for a
	 * disconnected account — throttled per account_id via a persistent
	 * transient, not per-row/per-request.
	 *
	 * send_admin_google_alert()'s own $dedupe_key only dedupes within a single
	 * PHP request (it's an in-memory static array), which is meaningless for
	 * a cron that runs as a fresh request every 20 minutes — without this
	 * transient guard, a persistently dead account would re-alert every
	 * single run forever. Six hours caps that at ~4 emails/day while still
	 * keeping the admin aware the retry queue is stuck.
	 *
	 * @param string $account_id Account ID the skipped row(s) needed.
	 * @param string $context    Human-readable action being retried.
	 */
	private function maybe_alert_disconnected_account( $account_id, $context ) {
		if ( empty( $account_id ) ) {
			return;
		}

		// get_transient() then set_transient() is two round-trips, not a
		// compare-and-swap — without a lock, overlapping WP-Cron dispatches
		// (a known possibility without a real system cron driving it) could
		// both pass the check before either write lands, sending two alerts.
		// 5s is plenty; this only needs to serialize two near-simultaneous
		// dispatches, not hold for the duration of the alert email itself.
		WGDP_DB::with_named_lock( 'wgdp_cron_account_alert_' . $account_id, 5, function () use ( $account_id, $context ) {
			$transient_key = 'wgdp_cron_account_alert_' . $account_id;
			if ( get_transient( $transient_key ) ) {
				return;
			}
			set_transient( $transient_key, 1, 6 * HOUR_IN_SECONDS );
			WGDP_Notification_Email::send_admin_google_alert( $account_id, $context, 'cron_account_' . $account_id );
		}, false );
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
		$revoked_by_recipient = array();

		$had_candidates = false;

		// Pick up pending_release overflow for already-released items. Use cursor
		// pagination so blocked rows do not permanently hide later grantable rows
		// within this run. The cursor is also persisted across runs (wrapping to
		// zero once a full pass drains cleanly) so rows stuck at the front behind
		// a still-closed release gate cannot starve rows further back forever.
		$cursor_option = 'wgdp_cron_cursor_pending_release';
		$after_id      = (int) get_option( $cursor_option, 0 );
		$drained       = false;
		for ( $page = 0; $page < 10; $page++ ) {
			$pending_release_rows = $ent->get_stale_pending_release( 20, $after_id );
			if ( empty( $pending_release_rows ) ) {
				$drained = true;
				break;
			}
			$had_candidates = true;
			foreach ( $pending_release_rows as $row ) {
				$after_id = max( $after_id, (int) $row['id'] );
				if ( ! WGDP_Release_Gate::is_item_released( (int) $row['product_id'], (int) ( $row['variation_id'] ?? 0 ) ) ) {
					continue;
				}
				if ( empty( $row['account_id'] ) || ! $auth->is_account_connected( $row['account_id'] ) ) {
					$this->maybe_alert_disconnected_account( $row['account_id'], 'grant a pending Drive release' );
					continue;
				}
				if ( WGDP_Product_Meta::is_resource_retired( (int) $row['product_id'], (int) $row['variation_id'], $row['cloud_asset_id'] ) ) {
					$ent->mark_revoked( $row['id'], WGDP_Entitlements::REVOCATION_REASON_ASSET_REMOVED );
					continue;
				}
				$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row, true );
				if ( is_wp_error( $result ) ) {
					$ent->mark_error( $row['id'], $result->get_error_message() );
				} else {
					// null means the entitlement was already granted (e.g. by an
					// overlapping release-gate batch) — not a fresh grant, so don't
					// queue a duplicate access-granted email for it.
					if ( null !== $result ) {
						$this->collect_granted( $granted_by_recipient, $row );
					}
					WGDP_Order_Handler::instance()->maybe_auto_complete_order( $row['order_id'] );
				}
			}
		}
		update_option( $cursor_option, $drained ? 0 : $after_id, false );

		// Track retired asset IDs already noted per order to avoid duplicate notes.
		$retired_noted = array();

		$cursor_option = 'wgdp_cron_cursor_failed_verified';
		$after_id      = (int) get_option( $cursor_option, 0 );
		$drained       = false;
		for ( $page = 0; $page < 10; $page++ ) {
			$rows = $ent->get_failed_verified( 20, 50, $after_id );
			if ( empty( $rows ) ) {
				$drained = true;
				break;
			}
			$had_candidates = true;
			foreach ( $rows as $row ) {
				$after_id = max( $after_id, (int) $row['id'] );
				if ( ! WGDP_Release_Gate::is_item_released( (int) $row['product_id'], (int) ( $row['variation_id'] ?? 0 ) ) ) {
					continue;
				}

				if ( empty( $row['account_id'] ) || ! $auth->is_account_connected( $row['account_id'] ) ) {
					$this->maybe_alert_disconnected_account( $row['account_id'], 'retry a failed Drive grant' );
					continue;
				}

				if ( WGDP_Product_Meta::is_resource_retired( (int) $row['product_id'], (int) $row['variation_id'], $row['cloud_asset_id'] ) ) {
					$ent->mark_revoked( $row['id'], WGDP_Entitlements::REVOCATION_REASON_ASSET_REMOVED );
					continue;
				}

				$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row, true );
				if ( is_wp_error( $result ) ) {
					if ( WGDP_Google_Drive::is_file_not_found( $result ) ) {
						$verify = $drive->get_file( $row['cloud_asset_id'], $row['account_id'] );
						if ( WGDP_Google_Drive::is_file_not_found( $verify ) ) {
							WGDP_Product_Meta::maybe_retire_resource(
								(int) $row['product_id'], (int) $row['variation_id'],
								$row['cloud_asset_id'], 'retired_missing'
							);
							$ent->mark_error( $row['id'], 'File removed from Google Drive (auto-retired)' );

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

				// null means the entitlement was already granted (e.g. by an
				// overlapping release-gate batch) — not a fresh grant, so don't
				// log a duplicate "retry successful" note or queue a duplicate
				// access-granted email for it.
				if ( null !== $result ) {
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

					$this->collect_granted( $granted_by_recipient, $row );
				}
				WGDP_Order_Handler::instance()->maybe_auto_complete_order( $row['order_id'] );
			}
		}
		update_option( $cursor_option, $drained ? 0 : $after_id, false );

		$revocation_cursor_option = 'wgdp_cron_cursor_failed_revocation';
		$after_id                 = (int) get_option( $revocation_cursor_option, 0 );
		$revocation_drained       = false;
		for ( $page = 0; $page < 10; $page++ ) {
			$revocation_rows = $ent->get_failed_revocations( 20, 50, $after_id );
			if ( empty( $revocation_rows ) ) {
				$revocation_drained = true;
				break;
			}
			$had_candidates = true;
			foreach ( $revocation_rows as $row ) {
				$after_id = max( $after_id, (int) $row['id'] );
				$reason = ! empty( $row['revocation_reason'] ) ? $row['revocation_reason'] : WGDP_Entitlements::REVOCATION_REASON_MANUAL;
				$result = $ent->revoke_with_drive_delete( $row, $reason );
				if ( is_wp_error( $result ) ) {
					continue;
				}

				$key = $row['order_item_id'] . '|' . $row['recipient_email'] . '|' . $reason;
				if ( ! isset( $revoked_by_recipient[ $key ] ) ) {
					$revoked_by_recipient[ $key ] = array(
						'email'        => $row['recipient_email'],
						'product_name' => WGDP_Entitlements::get_product_name( $row ),
						'order_id'     => $row['order_id'],
					);
				}

				$order = wc_get_order( $row['order_id'] );
				if ( $order ) {
					$order->add_order_note( sprintf(
						'WGDP: Revocation retry successful — removed Drive access for %s (entitlement #%d)',
						$row['recipient_email'],
						$row['id']
					) );
				}
			}
		}
		update_option( $revocation_cursor_option, $revocation_drained ? 0 : $after_id, false );

		if ( ! $had_candidates ) {
			return;
		}

		// Send emails grouped by origin.
		$this->send_grouped_emails( $granted_by_recipient );
		foreach ( $revoked_by_recipient as $group ) {
			$recipients = $this->recipients_with_billing_fallback( $group['email'], $group['order_id'] );
			foreach ( $recipients as $to ) {
				WGDP_Notification_Email::send_access_revoked( $to, $group['product_name'], $group['order_id'] );
			}
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Process backfill jobs — create entitlements for new resources on existing orders.
	 */
	public function process_backfill() {
		global $wpdb;
		$table = WGDP_DB::get_backfill_table_name();
		$now   = current_time( 'mysql', true );

		// Reclaim stale processing jobs (worker died). The threshold must exceed the
		// worst-case time for a single 200-recipient batch, otherwise a legitimately
		// slow-but-alive batch is reclaimed before it can commit and never progresses.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET status = 'pending', started_at = NULL, attempts = attempts + 1 WHERE status = 'processing' AND started_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			gmdate( 'Y-m-d H:i:s', time() - 1800 )
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
		if ( false === $claimed ) {
			// DB error (not a lost race) — surface it so a stalled queue is visible.
			error_log( 'WGDP: backfill failed to claim job ' . (int) $job_id . ' — ' . $wpdb->last_error );
			return;
		}
		if ( 0 === $claimed ) {
			// Another worker claimed this job first (lost race) — nothing to do.
			return;
		}

		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$job_id
		), ARRAY_A );

		// Use the account_id stored on the job, not a fresh read from product meta:
		// queue_backfill() already re-stamps it (and resets the cursor) whenever new
		// resources are added under a different account, so this stays consistent for
		// the whole job. Re-reading here would let an account change made mid-job
		// (without adding resources) split entitlements across two Google accounts.
		$account_id = $job['account_id'];

		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			// A disconnected account is typically transient (expired/revoked OAuth
			// token, brief outage) and self-recovers once reconnected. Retry via the
			// same attempts/stale-reclaim mechanism used above rather than failing
			// the job outright — otherwise a disconnection at the wrong moment
			// permanently strands the backfill with no path to resurrect it, since
			// queue_backfill() only reuses jobs with status 'pending'/'processing'.
			// Conditional: don't clobber a 'pending' reset written by queue_backfill()
			// while this batch was running (see cursor-advance comment below).
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status = 'pending', started_at = NULL, attempts = attempts + 1, last_error = %s WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'No connected Google account is available for this product.', $job['id']
			) );
			// The job row's own last_error records this, but nothing surfaces it to
			// an admin who isn't actively watching the backfill queue — same gap as
			// the two retry_failed_grants() skip points above, closed the same way.
			$this->maybe_alert_disconnected_account( $account_id, 'process a backfill job for product #' . (int) $job['product_id'] );
			$this->maybe_reschedule_backfill();
			return;
		}

		$asset_ids     = json_decode( $job['asset_ids'], true );
		$asset_ids     = is_array( $asset_ids ) ? $asset_ids : array();
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
			// Conditional for the same reason as the account-check failure path above.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status = 'completed', processed_at = %s WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now, $job['id']
			) );
			$this->maybe_reschedule_backfill();
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
			// Progress was made this batch, so reset the stale-reclaim counter — only a
			// job that repeatedly stalls without advancing should reach max attempts.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET cursor_item_id = %d, cursor_email = %s, total_created = %d, status = 'pending', started_at = NULL, attempts = 0 WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$result['last_item_id'], $result['last_email'], $new_total, $job['id']
			) );
			wp_schedule_single_event( time() + 5, 'wgdp_process_backfill' );
		} else {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET status = 'completed', total_created = %d, processed_at = %s WHERE id = %d AND status = 'processing'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$new_total, $now, $job['id']
			) );
			$this->maybe_reschedule_backfill();
		}

		delete_transient( 'wgdp_permission_counts' );
	}

	/**
	 * Schedule a follow-up backfill run if pending jobs remain.
	 *
	 * queue_backfill() fires one wp_schedule_single_event() per job it inserts,
	 * but WordPress silently de-duplicates same-second single events sharing the
	 * same hook and args — e.g. saving a variable product where several
	 * variations each queue a new job in the same request. Only one of those
	 * events survives, so process_backfill() must independently verify no other
	 * pending job was left without a surviving event once it finishes the one
	 * it claimed.
	 */
	private function maybe_reschedule_backfill() {
		global $wpdb;
		$table = WGDP_DB::get_backfill_table_name();

		$has_pending = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT 1 FROM {$table} WHERE status = 'pending' LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $has_pending ) {
			wp_schedule_single_event( time() + 5, 'wgdp_process_backfill' );
		}
	}

	/**
	 * Expire stale entitlements (pending with expired claim tokens).
	 */
	public function expire_stale_entitlements() {
		WGDP_Entitlements::instance()->expire_stale();
		delete_transient( 'wgdp_permission_counts' );

		$this->prune_backfill_history();
	}

	/**
	 * Prune completed/failed backfill jobs older than the retention window.
	 *
	 * Backfill jobs transition to 'completed'/'failed' but were never deleted,
	 * so long-lived stores accumulate history forever. Runs under a named lock
	 * so concurrent runs (e.g. overlapping hourly ticks on a busy store) can't
	 * double-delete or race the sweep.
	 */
	private function prune_backfill_history() {
		global $wpdb;
		$table = WGDP_DB::get_backfill_table_name();

		WGDP_DB::with_named_lock( 'wgdp_backfill_prune', 5, function () use ( $wpdb, $table ) {
			$retention_days = max( 1, (int) apply_filters( 'wgdp_backfill_retention_days', 30 ) );
			$cutoff         = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );

			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN ('completed', 'failed') AND processed_at IS NOT NULL AND processed_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			) );
		}, false );
	}

	/**
	 * Schedule cron jobs.
	 */
	public static function schedule() {
		// One-time cleanup of the legacy retry hook (renamed to wgdp_retry_failed_grants).
		// Gated so it doesn't touch the cron option on every page load. unschedule()
		// still clears it defensively on deactivation.
		if ( ! get_option( 'wgdp_legacy_retry_hook_cleared' ) ) {
			wp_clear_scheduled_hook( 'wgdp_retry_failed_permissions' );
			update_option( 'wgdp_legacy_retry_hook_cleared', 1, true );
		}

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
		wp_clear_scheduled_hook( 'wgdp_retry_failed_permissions' );
		wp_clear_scheduled_hook( 'wgdp_retry_failed_grants' );
		wp_clear_scheduled_hook( 'wgdp_expire_stale_entitlements' );
		// Also clear any pending single-event backfill jobs so they don't fire after deactivation.
		wp_clear_scheduled_hook( 'wgdp_process_backfill' );
		wp_clear_scheduled_hook( 'wgdp_recalculate_sales_counters' );
	}

}
