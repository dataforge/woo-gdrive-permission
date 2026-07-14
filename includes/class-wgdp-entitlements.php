<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Entitlements {

	private static $instance = null;

	const REVOCATION_REASON_ASSET_REMOVED       = 'asset_removed';
	const REVOCATION_REASON_MANUAL              = 'manual';
	const REVOCATION_REASON_ORDER_INELIGIBLE    = 'order_ineligible';
	const REVOCATION_REASON_ORDER_ITEM_REMOVED  = 'order_item_removed';
	const REVOCATION_REASON_PARTIAL_REFUND      = 'partial_refund';
	const REVOCATION_REASON_REASSIGNMENT        = 'reassignment_requested';
	const REVOCATION_REASON_REPROVISION         = 'reprovision';
	const REVOCATION_REASON_SELF_SERVICE_RETRY  = 'self_service_retry';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Get the table name.
	 */
	private function table() {
		return WGDP_DB::get_table_name();
	}

	/**
	 * Insert a new entitlement row.
	 */
	public function create( $data ) {
		global $wpdb;

		$defaults = array(
			'order_id'            => 0,
			'order_item_id'       => 0,
			'product_id'          => 0,
			'variation_id'        => 0,
			'cloud_asset_id'      => '',
			'account_id'          => '',
			'recipient_email'     => '',
			'recipient_index'     => 1,
			'verification_status' => 'pending',
			'grant_status'        => 'pending',
			'origin'              => 'order',
		);

		$data = wp_parse_args( $data, $defaults );

		$result = $wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Get a single entitlement by ID.
	 */
	public function get( $id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get all entitlements for an order.
	 */
	public function get_by_order( $order_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY order_item_id, recipient_index", $order_id ),
			ARRAY_A
		);
	}

	/**
	 * Get entitlements for a specific order item.
	 */
	public function get_by_order_item( $order_item_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_item_id = %d ORDER BY recipient_index", $order_item_id ),
			ARRAY_A
		);
	}

	/**
	 * Look up an entitlement by claim token hash.
	 */
	public function get_by_claim_token_hash( $hash ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE claim_token_hash = %s", $hash ),
			ARRAY_A
		);
	}

	/**
	 * Find an existing granted entitlement for the same account + email + asset.
	 */
	public function get_by_email_and_asset( $email, $cloud_asset_id, $account_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE recipient_email = %s AND cloud_asset_id = %s AND account_id = %s AND grant_status = 'granted' AND provider_permission_id IS NOT NULL LIMIT 1",
				$email,
				$cloud_asset_id,
				$account_id
			),
			ARRAY_A
		);
	}

	/**
	 * Count entitlements still depending on a Google account for revocation
	 * (i.e. not in a terminal 'revoked' state).
	 */
	public function count_active_by_account( $account_id ) {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE account_id = %s AND grant_status != 'revoked'",
				$account_id
			)
		);
	}

	/**
	 * Find a revoked entitlement matching the unique key (order_item_id, cloud_asset_id, recipient_email).
	 * Used to reactivate instead of inserting when the same combo was previously revoked.
	 */
	public function get_revoked_for_reuse( $order_item_id, $cloud_asset_id, $recipient_email ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_item_id = %d AND cloud_asset_id = %s AND recipient_email = %s AND grant_status = 'revoked' LIMIT 1",
				$order_item_id,
				$cloud_asset_id,
				$recipient_email
			),
			ARRAY_A
		);
	}

	/**
	 * Update an entitlement row.
	 */
	public function update( $id, $data ) {
		global $wpdb;
		return $wpdb->update( $this->table(), $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Delete an entitlement row created during a failed all-or-nothing operation.
	 */
	private function delete( $id ) {
		global $wpdb;
		return $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Normalize recipient emails before duplicate checks or storage.
	 */
	public static function normalize_email( $email ) {
		return strtolower( sanitize_email( $email ) );
	}

	/**
	 * Mark an entitlement as granted.
	 *
	 * Conditional on the row not already being revoked, so a grant that lost a
	 * race with a concurrent revoke cannot resurrect a row back to 'granted'.
	 *
	 * @return int|false Rows updated (0 if the row was already revoked), or false on failure.
	 */
	public function mark_granted( $id, $permission_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table}
				 SET grant_status = 'granted',
				     provider_permission_id = %s,
				     granted_at = %s,
				     revoked_at = NULL,
				     revocation_reason = NULL,
				     revocation_error = NULL,
				     revocation_retries = 0,
				     grant_error = NULL
				 WHERE id = %d AND grant_status != 'revoked'",
				$permission_id,
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Mark an entitlement as revoked.
	 */
	public function mark_revoked( $id, $reason = self::REVOCATION_REASON_MANUAL ) {
		$row = $this->get( $id );

		// When revoking a pending row that still holds a live claim token, we
		// opportunistically transfer that token to a pending sibling so the
		// recipient group keeps one claimable token. Do this under the
		// recipient-group lock so it cannot race a concurrent resend
		// (issue_otp_for_recipient_group), which would otherwise leave two active
		// claim tokens in the same group.
		if ( $row && 'pending' === $row['verification_status'] && ! empty( $row['claim_token_hash'] ) ) {
			$result = $this->with_recipient_group_lock(
				$row['order_item_id'],
				$row['recipient_email'],
				function () use ( $id, $reason ) {
					// Re-read under the lock; state may have changed while waiting.
					$row = $this->get( $id );
					if ( ! $row ) {
						// Row vanished. Honour the int|false contract (callers only
						// test is_wp_error) rather than returning null.
						return false;
					}

					$clear_claim = false;
					if ( 'pending' === $row['verification_status'] && ! empty( $row['claim_token_hash'] ) ) {
						$siblings = $this->get_siblings( $row['order_item_id'], $row['recipient_email'], $id );
						foreach ( $siblings as $sibling ) {
							if ( 'pending' !== $sibling['verification_status'] || 'revoked' === $sibling['grant_status'] ) {
								continue;
							}

							$updated = $this->update( $sibling['id'], array(
								'otp_hash'               => $row['otp_hash'],
								'otp_expires_at'         => $row['otp_expires_at'],
								'otp_attempts'           => $row['otp_attempts'],
								'claim_token_hash'       => $row['claim_token_hash'],
								'claim_token_expires_at' => $row['claim_token_expires_at'],
							) );

							if ( false !== $updated ) {
								$clear_claim = true;
								break;
							}
						}
					}

					return $this->write_revoked_row( $id, $reason, $clear_claim );
				}
			);

			// On lock success (including a null row that vanished), honour it.
			// Only fall through to an unlocked plain revoke if the lock could not
			// be acquired — revoking must never be blocked by lock contention.
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Lock could not be acquired. Revoking must not be blocked by contention,
		// but we must not leave a live claim token orphaned on the revoked row
		// (it would still validate while pointing at a revoked entitlement).
		// Clear the token here; the group can obtain a fresh one via resend.
		// Re-read the row rather than reusing the pre-lock-wait snapshot: up to
		// 5 seconds elapsed while waiting on the lock, during which a concurrent
		// resend could have rotated the claim token or changed the status.
		$row = $this->get( $id );
		$clear_orphaned_claim = $row
			&& 'pending' === $row['verification_status']
			&& ! empty( $row['claim_token_hash'] );

		return $this->write_revoked_row( $id, $reason, $clear_orphaned_claim );
	}

	/**
	 * Write the revoked-state columns for an entitlement row.
	 *
	 * @param int    $id          Entitlement ID.
	 * @param string $reason      Revocation reason.
	 * @param bool   $clear_claim Whether to clear OTP/claim-token columns (they
	 *                            were transferred to a sibling).
	 * @return int|false Rows updated, or false on failure.
	 */
	private function write_revoked_row( $id, $reason, $clear_claim ) {
		$data = array(
			'grant_status'       => 'revoked',
			'revoked_at'         => current_time( 'mysql', true ),
			'revocation_reason'  => $reason,
			'revocation_error'   => null,
		);

		if ( $clear_claim ) {
			$data['otp_hash']               = null;
			$data['otp_expires_at']         = null;
			$data['otp_attempts']           = 0;
			$data['claim_token_hash']       = null;
			$data['claim_token_expires_at'] = null;
		}

		return $this->update( $id, $data );
	}

	/**
	 * Mark an entitlement whose Drive permission could not be revoked.
	 */
	public function mark_revocation_error( $id, $reason, $error ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table}
				 SET grant_status = 'revocation_error',
				     revocation_reason = %s,
				     revocation_error = %s,
				     revocation_retries = revocation_retries + 1
				 WHERE id = %d",
				$reason,
				$error,
				$id
			)
		);
	}

	/**
	 * Revoke an entitlement only after the matching Drive permission is removed.
	 *
	 * Acquires the same per-entitlement lock as grant_drive_access_for_entitlement()
	 * and re-reads the row under it, so a grant that is mid-flight (e.g. waiting on
	 * Google) cannot have its outcome raced by a concurrent revoke acting on a stale
	 * snapshot, and vice versa.
	 *
	 * @param array  $row    Entitlement row (used only to obtain the ID; re-read under the lock).
	 * @param string $reason Revocation reason.
	 * @return true|WP_Error
	 */
	public function revoke_with_drive_delete( $row, $reason = self::REVOCATION_REASON_MANUAL ) {
		if ( ! $row ) {
			return true;
		}

		$id = (int) $row['id'];

		return $this->with_entitlement_lock( $id, function () use ( $id, $reason ) {
			$row = $this->get( $id );
			if ( ! $row || 'revoked' === $row['grant_status'] ) {
				return true;
			}

			if ( in_array( $row['grant_status'], array( 'granted', 'revocation_error' ), true ) && ! empty( $row['provider_permission_id'] ) ) {
				$result = $this->delete_drive_permission_for_row( $row );
				if ( is_wp_error( $result ) ) {
					$data   = $result->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
					if ( 404 !== $status ) {
						$this->mark_revocation_error( $row['id'], $reason, $result->get_error_message() );
						return $result;
					}
				}
			}

			$marked = $this->mark_revoked( $row['id'], $reason );
			if ( empty( $marked ) ) {
				// The Drive permission is gone but the row could not be committed
				// as revoked (vanished or already changed underneath us). Do not
				// report success for a row that can remain 'granted' in the DB.
				return new WP_Error( 'wgdp_revoke_not_recorded', 'Drive permission was removed but the entitlement could not be marked revoked.' );
			}
			return true;
		} );
	}

	/**
	 * Mark an entitlement with a grant error and increment retry count.
	 *
	 * Conditional on the row not already being revoked, so a failed grant whose
	 * error is recorded after the row was concurrently revoked cannot flip it
	 * back to 'error' (which would make cron eligible to retry-grant it).
	 */
	public function mark_error( $id, $error ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET grant_status = 'error', grant_error = %s, grant_retries = grant_retries + 1 WHERE id = %d AND grant_status != 'revoked'",
				$error,
				$id
			)
		);
	}

	/**
	 * Mark an entitlement as pending release (verified but product not yet released).
	 */
	public function mark_pending_release( $id ) {
		return $this->update( $id, array(
			'grant_status' => 'pending_release',
		) );
	}

	/**
	 * Get entitlements pending release for a product (verified + pending_release).
	 */
	public function get_pending_release_for_product( $product_id, $limit = 100, $after_id = 0 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND verification_status = 'verified' AND grant_status = 'pending_release' AND id > %d ORDER BY id ASC LIMIT %d",
				$product_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get entitlements pending release for a specific variation.
	 */
	public function get_pending_release_for_variation( $product_id, $variation_id, $limit = 100, $after_id = 0 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d AND verification_status = 'verified' AND grant_status = 'pending_release' AND id > %d ORDER BY id ASC LIMIT %d",
				$product_id,
				$variation_id,
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get sibling entitlements — all non-revoked rows for the same order_item_id + recipient_email.
	 *
	 * @param int    $order_item_id  The order item ID.
	 * @param string $recipient_email The recipient email.
	 * @param int    $exclude_id     Optional entitlement ID to exclude from results.
	 * @return array[] Array of entitlement rows.
	 */
	public function get_siblings( $order_item_id, $recipient_email, $exclude_id = 0 ) {
		global $wpdb;
		$table = $this->table();
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE order_item_id = %d AND recipient_email = %s AND grant_status != 'revoked'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order_item_id,
			$recipient_email
		);
		if ( $exclude_id ) {
			$sql .= $wpdb->prepare( ' AND id != %d', $exclude_id );
		}
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Issue one OTP/claim token for a recipient group and clear sibling tokens.
	 *
	 * A recipient may have multiple entitlement rows for multiple files. The claim
	 * page verifies the whole group, so only one row should carry the active token.
	 *
	 * @param int $entitlement_id Selected entitlement row.
	 * @return array|WP_Error Plain OTP/token data plus primary_id, or WP_Error.
	 */
	public function issue_otp_for_recipient_group( $entitlement_id ) {
		$row = $this->get( $entitlement_id );
		if ( ! $row ) {
			return new WP_Error( 'wgdp_entitlement_not_found', 'Entitlement not found.' );
		}
		if ( 'revoked' === $row['grant_status'] ) {
			return new WP_Error( 'wgdp_entitlement_revoked', 'Cannot issue OTP for a revoked entitlement.' );
		}
		if ( 'verified' === $row['verification_status'] ) {
			return new WP_Error( 'wgdp_entitlement_verified', 'Cannot issue OTP for an already verified entitlement.' );
		}

		return $this->with_recipient_group_lock(
			$row['order_item_id'],
			$row['recipient_email'],
			function () use ( $entitlement_id ) {
				$row = $this->get( $entitlement_id );
				if ( ! $row ) {
					return new WP_Error( 'wgdp_entitlement_not_found', 'Entitlement not found.' );
				}
				if ( 'revoked' === $row['grant_status'] ) {
					return new WP_Error( 'wgdp_entitlement_revoked', 'Cannot issue OTP for a revoked entitlement.' );
				}
				if ( 'verified' === $row['verification_status'] ) {
					return new WP_Error( 'wgdp_entitlement_verified', 'Cannot issue OTP for an already verified entitlement.' );
				}

				$group      = array_merge( array( $row ), $this->get_siblings( $row['order_item_id'], $row['recipient_email'], $row['id'] ) );
				$primary_id = (int) $row['id'];

				foreach ( $group as $candidate ) {
					if ( 'revoked' === $candidate['grant_status'] || 'verified' === $candidate['verification_status'] ) {
						continue;
					}
					if ( ! empty( $candidate['claim_token_hash'] ) ) {
						$primary_id = (int) $candidate['id'];
						break;
					}
				}

				$tokens = WGDP_OTP::instance()->issue_otp_for_entitlement( $primary_id );
				if ( is_wp_error( $tokens ) ) {
					return $tokens;
				}

				foreach ( $group as $candidate ) {
					if ( (int) $candidate['id'] === $primary_id ) {
						continue;
					}
					if ( 'revoked' === $candidate['grant_status'] || 'verified' === $candidate['verification_status'] ) {
						continue;
					}
					$this->update( $candidate['id'], array(
						'otp_hash'               => null,
						'otp_expires_at'         => null,
						'otp_attempts'           => 0,
						'claim_token_hash'       => null,
						// Give the sibling the same fresh expiry as the primary's just-issued
						// claim token rather than NULL — a NULL here falls back to the row's
						// original created_at in expire_stale()'s NULL-expiry fallback, which
						// for a reactivated row is its old creation time, not now. That made
						// reactivated siblings expire immediately, before the customer could
						// even open the OTP email, silently blocking that resource's grant.
						'claim_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( WGDP_OTP::CLAIM_TOKEN_EXPIRY_HOURS * 3600 ) ),
						'verification_status'    => 'pending',
					) );
				}

				$tokens['primary_id'] = $primary_id;
				return $tokens;
			}
		);
	}

	/**
	 * Execute a callback while holding a recipient/file-group OTP lock.
	 */
	private function with_recipient_group_lock( $order_item_id, $recipient_email, $callback ) {
		$lock_name = 'wgdp_otp_group_' . md5( absint( $order_item_id ) . '|' . self::normalize_email( $recipient_email ) );
		return WGDP_DB::with_named_lock(
			$lock_name,
			5,
			$callback,
			new WP_Error( 'wgdp_otp_group_lock_failed', 'Could not lock this recipient for OTP issuance. Please try again.' )
		);
	}

	/**
	 * Count active (non-revoked) distinct recipients for an order item.
	 *
	 * @param int $order_item_id The order item ID.
	 * @return int Count of distinct recipient emails.
	 */
	public function count_active_recipients_for_item( $order_item_id ) {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT recipient_email) FROM {$table} WHERE order_item_id = %d AND grant_status != 'revoked'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_item_id
			)
		);
	}

	/**
	 * Count confirmed (verified or granted) distinct recipients for an order item.
	 *
	 * @param int $order_item_id The order item ID.
	 * @return int Count of distinct recipient emails.
	 */
	public function count_confirmed_recipients_for_item( $order_item_id ) {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT recipient_email) FROM {$table} WHERE order_item_id = %d AND grant_status != 'revoked' AND (verification_status = 'verified' OR grant_status = 'granted')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_item_id
			)
		);
	}

	/**
	 * Get aggregate counts grouped by status.
	 */
	public function count_by_status() {
		global $wpdb;
		$table = $this->table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT verification_status, grant_status, COUNT(*) as cnt FROM {$table} GROUP BY verification_status, grant_status",
			ARRAY_A
		);

		$counts = array(
			'pending_verification' => 0,
			'verified'             => 0,
			'expired'              => 0,
			'pending_release'      => 0,
			'granted'              => 0,
			'error'                => 0,
			'revoked'              => 0,
			'total'                => 0,
		);

		foreach ( $rows as $row ) {
			$c = (int) $row['cnt'];
			$counts['total'] += $c;

			if ( 'revoked' === $row['grant_status'] ) {
				$counts['revoked'] += $c;
			} elseif ( 'granted' === $row['grant_status'] ) {
				$counts['granted'] += $c;
			} elseif ( 'error' === $row['grant_status'] ) {
				$counts['error'] += $c;
			} elseif ( 'revocation_error' === $row['grant_status'] ) {
				$counts['error'] += $c;
			} elseif ( 'pending_release' === $row['grant_status'] ) {
				$counts['pending_release'] += $c;
			} elseif ( 'expired' === $row['verification_status'] ) {
				$counts['expired'] += $c;
			} elseif ( 'verified' === $row['verification_status'] ) {
				$counts['verified'] += $c;
			} else {
				$counts['pending_verification'] += $c;
			}
		}

		return $counts;
	}

	/**
	 * Get paginated items for the admin list table.
	 */
	public function get_items_for_list_table( $args = array() ) {
		global $wpdb;
		$table = $this->table();

		$defaults = array(
			'per_page'            => 20,
			'page'                => 1,
			'orderby'             => 'id',
			'order'               => 'DESC',
			'search'              => '',
			'verification_status' => '',
			'grant_status'        => '',
			'grant_statuses'      => array(),
			'product_id'          => 0,
			'product_name'        => '',
			'order_id'            => 0,
			'exclude_grant_status' => '',
			'hide_shadow_revoked' => false,
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$values = array();

		if ( ! empty( $args['verification_status'] ) ) {
			$where[]  = 'verification_status = %s';
			$values[] = $args['verification_status'];
		}

		if ( ! empty( $args['grant_status'] ) ) {
			$where[]  = 'grant_status = %s';
			$values[] = $args['grant_status'];
		}

		if ( ! empty( $args['grant_statuses'] ) && is_array( $args['grant_statuses'] ) ) {
			$statuses = array_values( array_filter( array_map( 'sanitize_key', $args['grant_statuses'] ) ) );
			if ( ! empty( $statuses ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
				$where[]      = "grant_status IN ({$placeholders})";
				foreach ( $statuses as $status ) {
					$values[] = $status;
				}
			}
		}

		if ( ! empty( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$values[] = absint( $args['product_id'] );
		}

		if ( ! empty( $args['product_name'] ) ) {
			// Find product IDs matching the name, then filter by those.
			$like = '%' . $wpdb->esc_like( $args['product_name'] ) . '%';
			$matching_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_title LIKE %s",
				$like
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! empty( $matching_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $matching_ids ), '%d' ) );
				$where[]      = "(product_id IN ({$placeholders}) OR variation_id IN ({$placeholders}))";
				foreach ( $matching_ids as $mid ) {
					$values[] = absint( $mid );
				}
				foreach ( $matching_ids as $mid ) {
					$values[] = absint( $mid );
				}
			} else {
				$where[] = '1=0'; // No products match — return empty.
			}
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$values[] = absint( $args['order_id'] );
		}

			if ( ! empty( $args['exclude_grant_status'] ) ) {
				$where[]  = 'grant_status != %s';
				$values[] = $args['exclude_grant_status'];
			}

			if ( ! empty( $args['hide_shadow_revoked'] ) ) {
				$where[] = "(
					grant_status != 'revoked'
					OR (
						COALESCE(revocation_reason, '') != %s
						AND NOT EXISTS (
							SELECT 1 FROM {$table} active
							WHERE active.order_item_id = {$table}.order_item_id
							  AND active.recipient_email = {$table}.recipient_email
							  AND active.grant_status != 'revoked'
						)
					)
				)";
				$values[] = self::REVOCATION_REASON_REASSIGNMENT;
			}

		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			if ( is_numeric( $args['search'] ) ) {
				$where[]  = '(recipient_email LIKE %s OR order_id = %d)';
				$values[] = $like;
				$values[] = absint( $args['search'] );
			} else {
				$where[]  = 'recipient_email LIKE %s';
				$values[] = $like;
			}
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = array( 'id', 'order_id', 'created_at', 'recipient_email', 'grant_status', 'verification_status' );
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$offset   = ( $args['page'] - 1 ) * $args['per_page'];
		$per_page = absint( $args['per_page'] );

		// Count query.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		// Items query.
		$items_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$all_values = array_merge( $values, array( $per_page, $offset ) );
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, $all_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Get recipient emails to revoke when quantity decreases (partial refund).
	 * Prioritizes: unverified first, then highest recipient_index.
	 * Returns distinct emails limited to $excess recipients.
	 */
	public function get_revocation_candidates( $order_item_id, $excess ) {
		global $wpdb;
		$table = $this->table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT recipient_email FROM (
				   SELECT recipient_email,
				     MIN(CASE WHEN verification_status IN ('pending', 'expired') THEN 0 ELSE 1 END) AS priority,
				     MAX(recipient_index) AS max_index
				   FROM {$table}
				   WHERE order_item_id = %d AND grant_status != 'revoked'
				   GROUP BY recipient_email
				 ) sub
				 ORDER BY priority, max_index DESC
				 LIMIT %d",
				$order_item_id,
				$excess
			),
			ARRAY_A
		);

		return wp_list_pluck( $rows, 'recipient_email' );
	}

	/**
	 * Revoke unverified entitlements for an order item, freeing up slots.
	 *
	 * When $recipient_email is given, only that recipient's pending/expired
	 * row(s) are revoked, leaving other recipients' pending slots on the same
	 * order item untouched. When omitted, all unverified rows for the item
	 * are revoked (legacy/full-item behavior).
	 *
	 * @return array Snapshot rows (id, grant_status, revoked_at, revocation_reason)
	 *               as they existed *before* revocation, for use with
	 *               restore_revoked_rows() if a later step in the same
	 *               transaction fails.
	 */
	public function revoke_unverified_for_item( $order_item_id, $recipient_email = '' ) {
		global $wpdb;
		$table = $this->table();

		$snapshot_cols = 'id, grant_status, revoked_at, revocation_reason, otp_hash, otp_expires_at, otp_attempts, claim_token_hash, claim_token_expires_at';

		if ( '' !== $recipient_email ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT {$snapshot_cols} FROM {$table} WHERE order_item_id = %d AND recipient_email = %s AND verification_status IN ('pending', 'expired') AND grant_status != 'revoked'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$order_item_id,
					$recipient_email
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT {$snapshot_cols} FROM {$table} WHERE order_item_id = %d AND verification_status IN ('pending', 'expired') AND grant_status != 'revoked'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$order_item_id
				),
				ARRAY_A
			);
		}

		if ( empty( $rows ) ) {
			return array();
		}

		// Clear OTP/claim-token state along with the revoke, matching write_revoked_row()'s
		// behavior, so a revoked row doesn't keep carrying a live-looking claim token in the
		// DB. The snapshot above preserves the pre-revoke values for restore_revoked_rows().
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET grant_status = 'revoked', revoked_at = %s, revocation_reason = %s,
				 otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0, claim_token_hash = NULL, claim_token_expires_at = NULL
				 WHERE id IN (" . implode( ',', array_fill( 0, count( $rows ), '%d' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge(
					array( current_time( 'mysql', true ), self::REVOCATION_REASON_SELF_SERVICE_RETRY ),
					wp_list_pluck( $rows, 'id' )
				)
			)
		);

		return $rows;
	}

	/**
	 * Undo revoke_unverified_for_item() for a set of rows.
	 *
	 * Used when a later step of the same assignment transaction fails after
	 * unverified rows were already revoked to free up a slot, so the
	 * original recipient's slot isn't lost.
	 *
	 * @param array $rows Snapshot rows returned by revoke_unverified_for_item().
	 */
	private function restore_revoked_rows( $rows ) {
		if ( empty( $rows ) ) {
			return;
		}
		global $wpdb;
		$table = $this->table();
		foreach ( $rows as $row ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'grant_status'           => $row['grant_status'],
					'revoked_at'             => $row['revoked_at'],
					'revocation_reason'      => $row['revocation_reason'],
					'otp_hash'               => $row['otp_hash'],
					'otp_expires_at'         => $row['otp_expires_at'],
					'otp_attempts'           => $row['otp_attempts'],
					'claim_token_hash'       => $row['claim_token_hash'],
					'claim_token_expires_at' => $row['claim_token_expires_at'],
				),
				array( 'id' => $row['id'] )
			);
		}
	}

	/**
	 * Execute a callback while holding a per-order-item MySQL lock.
	 *
	 * This protects the slot-count check plus entitlement creation from
	 * concurrent admin/self-service submissions for the same order item.
	 */
	public function with_order_item_lock( $order_item_id, $callback ) {
		$lock_name = 'wgdp_order_item_' . absint( $order_item_id );
		return WGDP_DB::with_named_lock(
			$lock_name,
			5,
			$callback,
			new WP_Error( 'wgdp_assignment_lock_failed', 'Could not lock this order item for assignment. Please try again.' )
		);
	}

	/**
	 * Execute a callback while holding a per-entitlement MySQL lock.
	 *
	 * This prevents duplicate Drive permission creation when the same claim URL is
	 * submitted concurrently.
	 */
	public function with_entitlement_lock( $entitlement_id, $callback ) {
		$lock_name = 'wgdp_entitlement_' . absint( $entitlement_id );
		return WGDP_DB::with_named_lock(
			$lock_name,
			10,
			$callback,
			new WP_Error( 'wgdp_entitlement_lock_failed', 'Could not lock this entitlement. Please try again.' )
		);
	}

	/**
	 * Validate and create recipient entitlements under the order-item lock.
	 *
	 * @param array $args Assignment arguments.
	 * @return array|WP_Error Result from create_entitlements_for_recipient(), plus order/item objects.
	 */
	public function assign_recipient_to_order_item( $args ) {
		$order_id      = absint( $args['order_id'] ?? 0 );
		$order_item_id = absint( $args['order_item_id'] ?? 0 );
		$email         = self::normalize_email( $args['email'] ?? '' );

		if ( ! $order_id || ! $order_item_id ) {
			return new WP_Error( 'wgdp_missing_order_item', 'Missing order or item ID.' );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wgdp_invalid_email', 'Please enter a valid email address.' );
		}

		return $this->with_order_item_lock( $order_item_id, function () use ( $args, $order_id, $order_item_id, $email ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return new WP_Error( 'wgdp_order_not_found', 'Order not found.' );
			}

			$allowed_statuses = $args['allowed_order_statuses'] ?? array();
			if ( ! empty( $allowed_statuses ) && ! in_array( $order->get_status(), $allowed_statuses, true ) ) {
				return new WP_Error( 'wgdp_order_ineligible', 'This order is no longer eligible for digital access.' );
			}

			if ( ! empty( $args['reject_uncharged_preorder'] ) && self::is_uncharged_preorder_order( $order ) ) {
				return new WP_Error( 'wgdp_preorder_deferred', 'Digital access can be assigned after the preorder campaign charge succeeds.' );
			}

			$item = $order->get_item( $order_item_id );
			if ( ! $item ) {
				return new WP_Error( 'wgdp_order_item_not_found', 'Order item not found.' );
			}

			$product_id   = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				return new WP_Error( 'wgdp_item_not_digital', 'This item does not qualify for digital access.' );
			}

			$revoked_rows = array();
			if ( ! empty( $args['clear_unverified'] ) ) {
				$revoked_rows = $this->revoke_unverified_for_item( $order_item_id, $args['clear_unverified_email'] ?? '' );
			}

			$quantity      = (int) $item->get_quantity();
			$qty_refunded  = abs( (int) $order->get_qty_refunded_for_item( $order_item_id ) );
			$effective_qty = max( 0, $quantity - $qty_refunded );
			$already_has_slot = ! empty( $this->get_siblings( $order_item_id, $email ) );

			if ( $effective_qty <= 0 && ! $already_has_slot ) {
				$this->restore_revoked_rows( $revoked_rows );
				return new WP_Error( 'wgdp_no_slots', 'No assignable digital access slots remain for this item.' );
			}

			if ( ! $already_has_slot ) {
				$count_mode    = $args['count_mode'] ?? 'active';
				$current_count = 'confirmed' === $count_mode
					? $this->count_confirmed_recipients_for_item( $order_item_id )
					: $this->count_active_recipients_for_item( $order_item_id );

				if ( $current_count >= $effective_qty ) {
					$this->restore_revoked_rows( $revoked_rows );
					return new WP_Error( 'wgdp_no_slots', 'No assignable digital access slots remain for this item.' );
				}
			}

			$resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
			if ( empty( $resources ) ) {
				$this->restore_revoked_rows( $revoked_rows );
				return new WP_Error( 'wgdp_no_resources', 'No Drive resources configured for this item.' );
			}

			$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );
			if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
				$this->restore_revoked_rows( $revoked_rows );
				return new WP_Error( 'wgdp_no_connected_account', 'No connected Google account for this item.' );
			}

			$result = $this->create_entitlements_for_recipient( array(
				'order_id'       => $order_id,
				'order_item_id'  => $order_item_id,
				'product_id'     => $product_id,
				'variation_id'   => $variation_id ?: 0,
				'email'          => $email,
				'account_id'     => $account_id,
				'resources'      => $resources,
			) );

			if ( is_wp_error( $result ) ) {
				$this->restore_revoked_rows( $revoked_rows );
				return $result;
			}

			$result['order'] = $order;
			$result['item']  = $item;
			return $result;
		} );
	}

	/**
	 * Delete the Drive permission for a row unless another active entitlement
	 * references the same file/account/permission tuple.
	 *
	 * The shared-reference check and the delete are serialized under a lock keyed
	 * by the (account, asset, permission) tuple, not by entitlement ID. Without
	 * this, the last two entitlements referencing one Drive permission could be
	 * revoked concurrently, each see the other as still active via
	 * permission_is_shared(), and both skip the Google delete while the
	 * permission remains live.
	 *
	 * @param array $row Entitlement row.
	 * @return true|WP_Error
	 */
	public function delete_drive_permission_for_row( $row ) {
		if ( empty( $row['provider_permission_id'] ) ) {
			return true;
		}

		return $this->with_permission_lock( $row['account_id'], $row['cloud_asset_id'], $row['provider_permission_id'], function () use ( $row ) {
			if ( $this->permission_is_shared( $row ) ) {
				return true;
			}

			return WGDP_Google_Drive::instance()->delete_permission(
				$row['cloud_asset_id'],
				$row['provider_permission_id'],
				$row['account_id']
			);
		} );
	}

	/**
	 * Execute a callback while holding a lock scoped to one Drive permission tuple.
	 */
	private function with_permission_lock( $account_id, $cloud_asset_id, $provider_permission_id, $callback ) {
		$lock_name = 'wgdp_permission_' . md5( $account_id . '|' . $cloud_asset_id . '|' . $provider_permission_id );
		return WGDP_DB::with_named_lock(
			$lock_name,
			10,
			$callback,
			new WP_Error( 'wgdp_permission_lock_failed', 'Could not lock this Drive permission. Please try again.' )
		);
	}

	/**
	 * Check if another non-revoked entitlement references the same direct
	 * permission on the same Drive asset and connected account.
	 *
	 * Drive's permission ID identifies the grantee, so the same user may produce
	 * the same permission ID on multiple files. The file/account scope prevents
	 * unrelated assets from blocking each other's revocation.
	 */
	public function permission_is_shared( $row ) {
		global $wpdb;
		$table = $this->table();
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT 1 FROM {$table}
				 WHERE provider_permission_id = %s
				   AND cloud_asset_id = %s
				   AND account_id = %s
				   AND id != %d
				   AND grant_status != 'revoked'
				 LIMIT 1",
				$row['provider_permission_id'],
				$row['cloud_asset_id'],
				$row['account_id'],
				$row['id']
			)
		);
	}

	/**
	 * Get pending_release entitlements (safety net for batch overflow).
	 */
	public function get_stale_pending_release( $limit = 20, $after_id = 0 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE grant_status = 'pending_release' AND verification_status = 'verified' AND id > %d ORDER BY id ASC LIMIT %d",
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get entitlements with grant errors that are verified (for cron retry).
	 * Excludes entitlements that have exceeded the max retry count.
	 */
	public function get_failed_verified( $limit = 20, $max_retries = 50, $after_id = 0 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE grant_status = 'error' AND verification_status = 'verified' AND grant_retries < %d AND id > %d ORDER BY id ASC LIMIT %d",
				$max_retries,
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get entitlements whose Drive permission delete failed and should be retried.
	 */
	public function get_failed_revocations( $limit = 20, $max_retries = 50, $after_id = 0 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE grant_status = 'revocation_error' AND provider_permission_id IS NOT NULL AND revocation_retries < %d AND id > %d ORDER BY id ASC LIMIT %d",
				$max_retries,
				$after_id,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Resolve the active WooCommerce order storage table for direct reporting queries.
	 */
	private function get_order_storage_sql_parts() {
		global $wpdb;

		$hpos_enabled = false;
		try {
			if ( function_exists( 'wc_get_container' ) && class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class ) ) {
				$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
				if ( method_exists( $controller, 'custom_orders_table_usage_is_enabled' ) ) {
					$hpos_enabled = (bool) $controller->custom_orders_table_usage_is_enabled();
				}
			}
		} catch ( \Throwable $e ) {
			$hpos_enabled = false;
		}

		if ( $hpos_enabled ) {
			return array(
				'table'            => $wpdb->prefix . 'wc_orders',
				'id_column'        => 'id',
				'status_col'       => 'status',
				'billing_email_expr' => 'o.billing_email',
				'billing_email_join' => '',
			);
		}

		return array(
			'table'            => $wpdb->posts,
			'id_column'        => 'ID',
			'status_col'       => 'post_status',
			'billing_email_expr' => 'billing_email_meta.meta_value',
			'billing_email_join' => "LEFT JOIN {$wpdb->postmeta} billing_email_meta ON billing_email_meta.post_id = oi.order_id AND billing_email_meta.meta_key = '_billing_email'",
		);
	}

	/**
	 * Get order items where qty > active entitlement count (missing email / unassigned).
	 */
	public function get_unassigned_order_items( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'     => 20,
			'page'         => 1,
			'product_id'   => 0,
			'product_name' => '',
			'order_id'     => 0,
			'search'       => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$table               = $this->table();
		$order_items         = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta      = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$refund_totals_table = WGDP_DB::get_refund_totals_table_name();
		$order_storage      = $this->get_order_storage_sql_parts();
		$orders_table       = $order_storage['table'];
		$order_id_col       = $order_storage['id_column'];
		$order_status_col   = $order_storage['status_col'];
		$billing_email_expr = $order_storage['billing_email_expr'];
		$billing_email_join = $order_storage['billing_email_join'];

		$extra_where  = '';
		$extra_values = array();

		if ( ! empty( $args['product_id'] ) ) {
			$extra_where   .= ' AND CAST(prod_meta.meta_value AS UNSIGNED) = %d';
			$extra_values[] = absint( $args['product_id'] );
		}

		if ( ! empty( $args['product_name'] ) ) {
			$like = '%' . $wpdb->esc_like( $args['product_name'] ) . '%';
			$matching_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation') AND post_title LIKE %s",
				$like
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! empty( $matching_ids ) ) {
				$placeholders   = implode( ',', array_fill( 0, count( $matching_ids ), '%d' ) );
				$extra_where   .= " AND (CAST(prod_meta.meta_value AS UNSIGNED) IN ({$placeholders}) OR COALESCE(CAST(var_meta.meta_value AS UNSIGNED), 0) IN ({$placeholders}))";
				foreach ( $matching_ids as $mid ) {
					$extra_values[] = absint( $mid );
				}
				foreach ( $matching_ids as $mid ) {
					$extra_values[] = absint( $mid );
				}
			} else {
				$extra_where .= ' AND 1=0';
			}
		}

		if ( ! empty( $args['order_id'] ) ) {
			$extra_where   .= ' AND oi.order_id = %d';
			$extra_values[] = absint( $args['order_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			if ( is_numeric( $args['search'] ) ) {
				$extra_where   .= ' AND oi.order_id = %d';
				$extra_values[] = absint( $args['search'] );
			} else {
				$like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
				$extra_where   .= " AND {$billing_email_expr} LIKE %s";
				$extra_values[] = $like;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base_sql = "
			SELECT oi.order_item_id, oi.order_id,
				CAST(prod_meta.meta_value AS UNSIGNED) AS product_id,
				COALESCE(CAST(var_meta.meta_value AS UNSIGNED), 0) AS variation_id,
				GREATEST(0, CAST(qty_meta.meta_value AS UNSIGNED) - COALESCE(refund_totals.refunded_qty, 0)) AS qty,
				COALESCE(ent_counts.active_count, 0) AS assigned_count
			FROM {$order_items} oi
			INNER JOIN {$orders_table} o ON o.{$order_id_col} = oi.order_id
			INNER JOIN {$order_itemmeta} prod_meta ON prod_meta.order_item_id = oi.order_item_id AND prod_meta.meta_key = '_product_id'
			LEFT JOIN  {$order_itemmeta} var_meta  ON var_meta.order_item_id = oi.order_item_id AND var_meta.meta_key = '_variation_id'
			INNER JOIN {$order_itemmeta} qty_meta  ON qty_meta.order_item_id = oi.order_item_id AND qty_meta.meta_key = '_qty'
			LEFT JOIN {$wpdb->postmeta} digital_flag ON digital_flag.post_id = CAST(var_meta.meta_value AS UNSIGNED)
			  AND digital_flag.meta_key = '_wgdp_includes_digital'
			{$billing_email_join}
			LEFT JOIN (
				SELECT order_item_id, COUNT(DISTINCT recipient_email) AS active_count
				FROM {$table} WHERE grant_status != 'revoked'
				GROUP BY order_item_id
			) ent_counts ON ent_counts.order_item_id = oi.order_item_id
			LEFT JOIN {$refund_totals_table} refund_totals ON refund_totals.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
			  AND o.{$order_status_col} IN ('wc-processing', 'wc-completed')
			  AND (
			    EXISTS (
			      SELECT 1 FROM {$wpdb->postmeta}
			      WHERE post_id = COALESCE(NULLIF(CAST(var_meta.meta_value AS UNSIGNED), 0), CAST(prod_meta.meta_value AS UNSIGNED))
			        AND meta_key IN ('_wgdp_drive_resource_id', '_wgdp_drive_resources') AND meta_value != '' AND meta_value != '[]'
			    )
			    OR EXISTS (
			      SELECT 1 FROM {$wpdb->postmeta}
			      WHERE post_id = CAST(prod_meta.meta_value AS UNSIGNED)
			        AND meta_key IN ('_wgdp_drive_resource_id', '_wgdp_drive_resources') AND meta_value != '' AND meta_value != '[]'
			    )
			  )
			  AND (
			    COALESCE(CAST(var_meta.meta_value AS UNSIGNED), 0) = 0
			    OR digital_flag.meta_value IS NULL OR digital_flag.meta_value = '' OR digital_flag.meta_value = 'yes'
			  )
			  {$extra_where}
			  AND GREATEST(0, CAST(qty_meta.meta_value AS UNSIGNED) - COALESCE(refund_totals.refunded_qty, 0)) > COALESCE(ent_counts.active_count, 0)
		";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Count query.
		$count_sql = "SELECT COUNT(*) FROM ({$base_sql}) AS sub"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $extra_values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $extra_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		// Items query.
		$offset   = ( $args['page'] - 1 ) * $args['per_page'];
		$per_page = absint( $args['per_page'] );
		$items_sql = $base_sql . ' ORDER BY oi.order_id DESC LIMIT %d OFFSET %d';
		$all_values = array_merge( $extra_values, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $items_sql, $all_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		// Qualification and refund-adjusted qty are already applied in SQL above,
		// so $total from the count query matches this page's rows exactly. Just
		// resolve resources / account for display.
		$items = array();
		foreach ( $rows as $row ) {
			$product_id   = (int) $row['product_id'];
			$variation_id = (int) $row['variation_id'];

			$resources  = WGDP_Product_Meta::get_drive_resources( $product_id, $variation_id ?: 0 );
			$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );

			$row['resources']        = $resources;
			$row['cloud_asset_id']   = ! empty( $resources ) ? $resources[0]['id'] : '';
			$row['account_id']       = $account_id;
			$row['unassigned_count'] = (int) $row['qty'] - (int) $row['assigned_count'];

			$items[] = $row;
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Count unassigned order items (lightweight count for summary cards).
	 */
	public function count_unassigned_order_items() {
		global $wpdb;

		$table          = $this->table();
		$order_items    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$order_storage  = $this->get_order_storage_sql_parts();
		$orders_table   = $order_storage['table'];
		$order_id_col   = $order_storage['id_column'];
		$order_status_col = $order_storage['status_col'];

		// Only count items whose product (or variation) has a Drive resource configured
		// and the variation qualifies for digital access (_wgdp_includes_digital != 'no').
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "
			SELECT oi.order_item_id, oi.order_id,
				CAST(prod_meta.meta_value AS UNSIGNED) AS product_id,
				COALESCE(CAST(var_meta.meta_value AS UNSIGNED), 0) AS variation_id,
				CAST(qty_meta.meta_value AS UNSIGNED) AS qty,
				COALESCE(ent_counts.active_count, 0) AS assigned_count
			FROM {$order_items} oi
			INNER JOIN {$orders_table} o ON o.{$order_id_col} = oi.order_id
			INNER JOIN {$order_itemmeta} prod_meta ON prod_meta.order_item_id = oi.order_item_id AND prod_meta.meta_key = '_product_id'
			LEFT JOIN  {$order_itemmeta} var_meta  ON var_meta.order_item_id = oi.order_item_id AND var_meta.meta_key = '_variation_id'
			INNER JOIN {$order_itemmeta} qty_meta  ON qty_meta.order_item_id = oi.order_item_id AND qty_meta.meta_key = '_qty'
			LEFT JOIN (
				SELECT order_item_id, COUNT(DISTINCT recipient_email) AS active_count
				FROM {$table} WHERE grant_status != 'revoked'
				GROUP BY order_item_id
			) ent_counts ON ent_counts.order_item_id = oi.order_item_id
			LEFT JOIN {$wpdb->postmeta} dig_meta
				ON dig_meta.post_id = CAST(var_meta.meta_value AS UNSIGNED)
				AND dig_meta.meta_key = '_wgdp_includes_digital'
			WHERE oi.order_item_type = 'line_item'
			  AND o.{$order_status_col} IN ('wc-processing', 'wc-completed')
			  AND (
			    EXISTS (
			      SELECT 1 FROM {$wpdb->postmeta}
			      WHERE post_id = COALESCE(NULLIF(CAST(var_meta.meta_value AS UNSIGNED), 0), CAST(prod_meta.meta_value AS UNSIGNED))
			        AND meta_key IN ('_wgdp_drive_resource_id', '_wgdp_drive_resources') AND meta_value != '' AND meta_value != '[]'
			    )
			    OR EXISTS (
			      SELECT 1 FROM {$wpdb->postmeta}
			      WHERE post_id = CAST(prod_meta.meta_value AS UNSIGNED)
			        AND meta_key IN ('_wgdp_drive_resource_id', '_wgdp_drive_resources') AND meta_value != '' AND meta_value != '[]'
			    )
			  )
			  AND (
			    COALESCE(NULLIF(CAST(var_meta.meta_value AS UNSIGNED), 0), 0) = 0
			    OR COALESCE(dig_meta.meta_value, '') != 'no'
			  )
			  AND CAST(qty_meta.meta_value AS UNSIGNED) > COALESCE(ent_counts.active_count, 0)
		";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( (int) $row['product_id'], (int) $row['variation_id'] ) ) {
				continue;
			}

			$order = wc_get_order( (int) $row['order_id'] );
			if ( ! $order ) {
				continue;
			}

			$qty_refunded  = abs( (int) $order->get_qty_refunded_for_item( (int) $row['order_item_id'] ) );
			$effective_qty = max( 0, (int) $row['qty'] - $qty_refunded );
			if ( $effective_qty > (int) $row['assigned_count'] ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Get product name from an entitlement row.
	 *
	 * @param array       $row      Entitlement row with 'variation_id' and 'product_id'.
	 * @param string|null $fallback Fallback when product no longer exists. Default: "Product #ID".
	 */
	public static function get_product_name( $row, $fallback = null ) {
		$id      = $row['variation_id'] ?: $row['product_id'];
		$product = wc_get_product( $id );
		if ( $product ) {
			return $product->get_name();
		}
		return $fallback ?? 'Product #' . $id;
	}

	/**
	 * Get Drive resource type for an entitlement.
	 *
	 * With multi-file support, resources are always individual files.
	 * Falls back to legacy meta for older single-resource products.
	 *
	 * @param array $row Entitlement row with 'variation_id', 'product_id', and 'cloud_asset_id'.
	 * @return string 'file' or 'folder'.
	 */
	public static function get_resource_type( $row ) {
		// Check multi-file resources — look up type by cloud_asset_id.
		$resources = WGDP_Product_Meta::get_drive_resources( $row['product_id'], $row['variation_id'] ?: 0 );
		if ( ! empty( $resources ) ) {
			foreach ( $resources as $res ) {
				if ( $res['id'] === $row['cloud_asset_id'] ) {
					return $res['type'] ?? 'file';
				}
			}
			// Multi-file product but asset not found — default to file.
			return 'file';
		}

		// Legacy fallback.
		$check_id = $row['variation_id'] ?: $row['product_id'];
		$type     = get_post_meta( $check_id, '_wgdp_drive_resource_type', true );
		if ( empty( $type ) ) {
			$type = get_post_meta( $row['product_id'], '_wgdp_drive_resource_type', true );
		}
		return $type ?: 'file';
	}

	/**
	 * Create (or reactivate) entitlement rows for a recipient and issue OTP on the primary.
	 *
	 * Centralises the "loop resources, create/reactivate rows, OTP on primary" pattern
	 * used by order creation, admin AJAX, and self-service.
	 *
	 * @param array  $args {
	 *     @type int      $order_id
	 *     @type int      $order_item_id
	 *     @type int      $product_id
	 *     @type int      $variation_id
	 *     @type string   $email            Recipient email.
	 *     @type string   $account_id       Google account ID.
	 *     @type array[]  $resources        Drive resources array.
	 *     @type int      $recipient_index  Optional. Auto-calculated when 0.
	 *     @type bool     $reuse_revoked    Whether to reactivate matching revoked rows. Default true.
	 * }
	 * @return array|WP_Error { primary_id, recipient_index, file_count } or WP_Error.
	 */
	public function create_entitlements_for_recipient( $args ) {
		$order_item_id  = (int) $args['order_item_id'];
		$email          = self::normalize_email( $args['email'] );
		$resources      = array_values( array_filter( (array) $args['resources'], function ( $res ) {
			return is_array( $res ) && ! empty( $res['id'] );
		} ) );
		$account_id     = $args['account_id'];
		$reuse_revoked  = $args['reuse_revoked'] ?? true;

		if ( empty( $resources ) ) {
			return new WP_Error( 'create_failed', 'No Drive resources were provided.' );
		}

		// Resolve recipient_index.
		$recipient_index_provided = ! empty( $args['recipient_index'] );
		$recipient_index_locked   = $recipient_index_provided;
		$recipient_index          = (int) ( $args['recipient_index'] ?? 0 );
		if ( ! $recipient_index ) {
			// This recipient may already hold non-revoked rows for other resources on
			// this item (e.g. a new Drive file was added to the product after they were
			// assigned). Reuse their existing index so all of a recipient's rows share
			// one seat number — otherwise this new row gets a freshly-computed global
			// max+1, desyncing recipient_index across the same recipient's own files and
			// corrupting get_revocation_candidates()'s per-recipient MAX(recipient_index).
			$siblings = $this->get_siblings( $order_item_id, $email );
			if ( ! empty( $siblings ) && ! empty( $siblings[0]['recipient_index'] ) ) {
				$recipient_index        = (int) $siblings[0]['recipient_index'];
				$recipient_index_locked = true;
			}
		}
		if ( ! $recipient_index ) {
			$existing  = $this->get_by_order_item( $order_item_id );
			$max_index = 0;
			foreach ( $existing as $row ) {
				if ( (int) $row['recipient_index'] > $max_index ) {
					$max_index = (int) $row['recipient_index'];
				}
			}
			$recipient_index = $max_index + 1;
		}

		// Pre-fetch any reusable revoked rows once, up front, so the recipient_index for
		// ALL of this recipient's resources is resolved before any row is created or
		// reactivated — resolving it mid-loop let an earlier resource get created with a
		// freshly-computed index before a later resource's revoked row forced a different
		// one, producing inconsistent seat numbers across the same recipient's files.
		$revoked_by_resource = array();
		if ( $reuse_revoked ) {
			foreach ( $resources as $res ) {
				$revoked = $this->get_revoked_for_reuse( $order_item_id, $res['id'], $email );
				if ( $revoked ) {
					$revoked_by_resource[ $res['id'] ] = $revoked;
					if ( ! $recipient_index_locked && ! empty( $revoked['recipient_index'] ) ) {
						$recipient_index        = (int) $revoked['recipient_index'];
						$recipient_index_locked = true;
					}
				}
			}
		}

		$primary_entitlement_id = 0;
		$created_ids            = array();
		$reactivated_rows       = array();
		$entitlement_ids        = array();
		$new_or_reused_ids      = array();

		foreach ( $resources as $res ) {
			$resource_id    = $res['id'];
			$entitlement_id = 0;
			$existing       = $this->get_existing_entitlement( $order_item_id, $resource_id, $email );

			// A 'revocation_error' row is not a valid existing grant: its Drive permission
			// removal already committed but failed and is queued for cron retry, so reusing
			// it here would tell the caller the recipient is already assigned while the
			// retry cron may delete their access moments later. Block instead, matching the
			// same status's handling in WGDP_Admin's reassign-email guard.
			if ( $existing && 'revocation_error' === $existing['grant_status'] ) {
				foreach ( $created_ids as $created_id ) {
					$this->delete( $created_id );
				}
				foreach ( $reactivated_rows as $reactivated_id => $snapshot ) {
					unset( $snapshot['id'] );
					$this->update( $reactivated_id, $snapshot );
				}
				return new WP_Error( 'wgdp_revocation_pending', 'Cannot assign this recipient while Drive access removal is pending retry.' );
			}

			if ( $existing && 'revoked' !== $existing['grant_status'] ) {
				$entitlement_id = (int) $existing['id'];
			}

			if ( ! $entitlement_id && $reuse_revoked ) {
				$revoked = $revoked_by_resource[ $resource_id ] ?? null;
				if ( $revoked ) {
					$entitlement_id = (int) $revoked['id'];
					$updated        = $this->update( $entitlement_id, array(
						'verification_status'      => 'pending',
						'grant_status'             => 'pending',
						'provider_permission_id'   => null,
						'granted_at'               => null,
						'revoked_at'               => null,
						'revocation_reason'        => null,
						'revocation_error'         => null,
						'revocation_retries'       => 0,
						'grant_error'              => null,
						'grant_retries'            => 0,
						'account_id'               => $account_id,
						'recipient_index'          => $recipient_index,
						'claim_token_hash'         => null,
						// Reactivation time, not the row's original created_at, is what
						// expire_stale()'s NULL-expiry fallback should measure from — a
						// row reactivated weeks after its original creation must not be
						// treated as already stale before its OTP is even issued.
						'claim_token_expires_at'   => gmdate( 'Y-m-d H:i:s', time() + ( WGDP_OTP::CLAIM_TOKEN_EXPIRY_HOURS * 3600 ) ),
					) );
					if ( false === $updated ) {
						$entitlement_id = 0;
					} else {
						$reactivated_rows[ $entitlement_id ] = $revoked;
						$new_or_reused_ids[] = $entitlement_id;
					}
				}
			}

			if ( ! $entitlement_id ) {
				$entitlement_id = $this->create( array(
					'order_id'        => (int) $args['order_id'],
					'order_item_id'   => $order_item_id,
					'product_id'      => (int) $args['product_id'],
					'variation_id'    => (int) ( $args['variation_id'] ?? 0 ),
					'cloud_asset_id'  => $resource_id,
					'account_id'      => $account_id,
					'recipient_email' => $email,
					'recipient_index' => $recipient_index,
				) );
				if ( $entitlement_id ) {
					$created_ids[] = $entitlement_id;
					$new_or_reused_ids[] = $entitlement_id;
				}
			}

			if ( ! $entitlement_id ) {
				foreach ( $created_ids as $created_id ) {
					$this->delete( $created_id );
				}
				foreach ( $reactivated_rows as $reactivated_id => $snapshot ) {
					unset( $snapshot['id'] );
					$this->update( $reactivated_id, $snapshot );
				}
				return new WP_Error( 'create_failed', 'Failed to create entitlements for every Drive resource.' );
			}

			$entitlement_ids[] = $entitlement_id;
			if ( ! $primary_entitlement_id ) {
				$primary_entitlement_id = $entitlement_id;
			}
		}

		// issue_otp_for_recipient_group() rejects an already-verified row outright, so the
		// anchor passed to it must be a newly created/reactivated (pending) row, not simply
		// the first resource processed — otherwise a recipient who already holds a verified
		// entitlement for one resource can never be granted access to a resource added later,
		// since that earlier verified row would be picked as primary and OTP issuance would
		// fail immediately.
		if ( ! empty( $new_or_reused_ids ) ) {
			$primary_entitlement_id = $new_or_reused_ids[0];
		}

		if ( ! $primary_entitlement_id || count( $entitlement_ids ) !== count( $resources ) ) {
			foreach ( $created_ids as $created_id ) {
				$this->delete( $created_id );
			}
			foreach ( $reactivated_rows as $reactivated_id => $snapshot ) {
				unset( $snapshot['id'] );
				$this->update( $reactivated_id, $snapshot );
			}
			return new WP_Error( 'create_failed', 'Failed to create entitlements for every Drive resource.' );
		}

		$tokens = null;
		if ( ! empty( $new_or_reused_ids ) ) {
			// Issue OTP on one row in the recipient group. Verifying it verifies siblings.
			$tokens = $this->issue_otp_for_recipient_group( $primary_entitlement_id );
			if ( is_wp_error( $tokens ) ) {
				foreach ( $created_ids as $created_id ) {
					$this->delete( $created_id );
				}
				foreach ( $reactivated_rows as $reactivated_id => $snapshot ) {
					unset( $snapshot['id'] );
					$this->update( $reactivated_id, $snapshot );
				}
				return $tokens;
			}
		}

		return array(
			'primary_id'      => $primary_entitlement_id,
			'recipient_index' => $recipient_index,
			'file_count'      => count( $entitlement_ids ),
			'entitlement_ids' => $entitlement_ids,
			'tokens'          => $tokens,
			'created_count'   => count( $new_or_reused_ids ),
			'already_exists'  => empty( $new_or_reused_ids ),
		);
	}

	/**
	 * AJAX handler: validate inputs, create or reactivate an entitlement, issue OTP, send email.
	 *
	 * Shared by WGDP_Order_Handler::ajax_add_entitlement and WGDP_Admin::ajax_assign_email.
	 * Calls wp_send_json_error/success and does not return.
	 *
	 * @param string $note_context Label appended to the order note (e.g. "added by admin").
	 * @param bool   $clear_counts Whether to delete the wgdp_permission_counts transient.
	 */
	public static function ajax_create_entitlement( $note_context = 'added by admin', $clear_counts = false ) {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$order_id      = absint( $_POST['order_id'] ?? 0 );
		$order_item_id = absint( $_POST['order_item_id'] ?? 0 );
		$email         = self::normalize_email( $_POST['email'] ?? '' );

		if ( ! $order_id || ! $order_item_id ) {
			wp_send_json_error( 'Missing order or item ID.' );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( 'Please enter a valid email address.' );
		}

		$ent    = self::instance();
		$result = $ent->assign_recipient_to_order_item( array(
			'order_id'                 => $order_id,
			'order_item_id'            => $order_item_id,
			'email'                    => $email,
			'count_mode'               => 'active',
			'reject_uncharged_preorder' => true,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$order = $result['order'];
		$item  = $result['item'];

		if ( empty( $result['tokens'] ) ) {
			wp_send_json_error( 'This recipient is already assigned for all current Drive files.' );
		}

		$mail_result = WGDP_Notification_Email::send_otp( $email, $result['tokens']['otp'], $result['tokens']['claim_token'], $order, $item );

		// Set drive items flag if not already set.
		if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
			$order->update_meta_data( '_wgdp_has_drive_items', '1' );
			$order->save();
		}

		if ( is_wp_error( $mail_result ) ) {
			$order->add_order_note( sprintf(
				'WGDP: Entitlement #%d created for %s, but verification email failed for "%s" — %s',
				$result['primary_id'],
				$email,
				$item->get_name(),
				$mail_result->get_error_message()
			) );
		} else {
			$order->add_order_note( sprintf(
				'WGDP: Verification email sent to %s for "%s" (entitlement #%d) — %s',
				$email,
				$item->get_name(),
				$result['primary_id'],
				$note_context
			) );
		}

		// Creating/reactivating an entitlement moves a row into pending_verification
		// and shifts every count_by_status() bucket, so the cached counts are now
		// stale regardless of which admin path called us. Always clear.
		unset( $clear_counts );
		delete_transient( 'wgdp_permission_counts' );

		wp_send_json_success( array(
			'id'              => $result['primary_id'],
			'email'           => $email,
			'recipient_index' => $result['recipient_index'],
				'file_count'      => $result['file_count'],
				'mail_sent'       => ! is_wp_error( $mail_result ),
				'mail_error'      => is_wp_error( $mail_result ) ? $mail_result->get_error_message() : '',
			) );
	}

	private static function is_uncharged_preorder_order( WC_Order $order ) {
		return (bool) $order->get_meta( '_wcpr_campaign_product_id' )
			&& 'yes' !== $order->get_meta( '_wcpr_charge_succeeded' );
	}

	/**
	 * Get an existing entitlement by order_item_id, cloud_asset_id, and recipient_email.
	 */
	public function get_existing_entitlement( $order_item_id, $cloud_asset_id, $recipient_email ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE order_item_id = %d AND cloud_asset_id = %s AND recipient_email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$order_item_id,
				$cloud_asset_id,
				$recipient_email
			),
			ARRAY_A
		);
	}

	/**
	 * Get recipients for a product, using keyset pagination.
	 *
	 * Backfills normally target non-revoked recipients. When a product file is
	 * replaced, however, the old file may be revoked before the async backfill
	 * job runs. Include verified recipients from active orders so replacement
	 * files still reach customers who already verified access.
	 *
	 * @param int    $product_id    The product ID.
	 * @param int    $variation_id  The variation ID (0 for simple products).
	 * @param int    $limit         Max recipients per page.
	 * @param int    $after_item_id Keyset cursor: order_item_id.
	 * @param string $after_email   Keyset cursor: recipient_email.
	 * @return array[] Grouped recipients with order_item_id, order_id, recipient_email, etc.
	 */
	public function get_active_recipients_for_product( $product_id, $variation_id = 0, $limit = 200, $after_item_id = 0, $after_email = '' ) {
		global $wpdb;
		$table        = $this->table();
		$order_storage = $this->get_order_storage_sql_parts();
		$orders_table  = $order_storage['table'];
		$order_id_col  = $order_storage['id_column'];
		$order_status_col = $order_storage['status_col'];

		$where = "e.product_id = %d
			AND o.{$order_status_col} IN ('wc-processing', 'wc-completed')
			AND (
				e.grant_status != 'revoked'
				OR (
					e.verification_status = 'verified'
					AND e.revocation_reason = %s
				)
			)";
		$values = array( $product_id, self::REVOCATION_REASON_ASSET_REMOVED );

		if ( $variation_id ) {
			$where   .= ' AND e.variation_id = %d';
			$values[] = $variation_id;
		}

		// Keyset cursor.
		$where   .= ' AND (e.order_item_id > %d OR (e.order_item_id = %d AND e.recipient_email > %s))';
		$values[] = $after_item_id;
		$values[] = $after_item_id;
		$values[] = $after_email;

		$values[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT e.order_item_id, e.order_id, e.recipient_email, MIN(e.recipient_index) AS recipient_index, MAX(e.variation_id) AS recipient_variation_id, MAX(e.account_id) AS account_id,
				MAX(CASE WHEN e.verification_status = 'verified' THEN 1 ELSE 0 END) AS is_verified,
				MAX(CASE WHEN e.grant_status = 'granted' THEN 1 ELSE 0 END) AS has_granted,
				MAX(CASE WHEN e.grant_status != 'revoked' THEN 1 ELSE 0 END) AS has_active
			FROM {$table} e
			INNER JOIN {$orders_table} o ON o.{$order_id_col} = e.order_id
			WHERE {$where}
			GROUP BY e.order_item_id, e.recipient_email
			ORDER BY e.order_item_id, e.recipient_email
			LIMIT %d";

		return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Backfill new resources for existing recipients of a product.
	 *
	 * @param int    $product_id    The product ID.
	 * @param int    $variation_id  The variation ID (0 for simple products).
	 * @param array  $new_resources Array of new resource objects [{id, type, name}, ...].
	 * @param string $account_id    The Google account ID.
	 * @param int    $limit         Max recipients per batch.
	 * @param int    $after_item_id Keyset cursor: order_item_id.
	 * @param string $after_email   Keyset cursor: recipient_email.
	 * @return array { created: int, has_more: bool, last_item_id: int, last_email: string }
	 */
	public function backfill_new_resources( $product_id, $variation_id, $new_resources, $account_id, $limit = 200, $after_item_id = 0, $after_email = '' ) {
		$recipients = $this->get_active_recipients_for_product(
			$product_id, $variation_id, $limit, $after_item_id, $after_email
		);

		$created       = 0;
		$last_item_id  = $after_item_id;
		$last_email    = $after_email;

		foreach ( $recipients as $recipient ) {
			$last_item_id = (int) $recipient['order_item_id'];
			$last_email   = $recipient['recipient_email'];
			$row_variation_id = (int) ( $recipient['recipient_variation_id'] ?? 0 );

			if ( ! $variation_id && $row_variation_id && WGDP_Product_Meta::variation_has_own_resources( $row_variation_id ) ) {
				continue;
			}

			if (
				empty( $recipient['has_active'] )
				&& ! $this->recipient_index_within_effective_quantity(
					(int) $recipient['order_id'],
					$last_item_id,
					(int) $recipient['recipient_index']
				)
			) {
				continue;
			}

			foreach ( $new_resources as $res ) {
				// Skip if already exists (any status).
				$existing = $this->get_existing_entitlement( $last_item_id, $res['id'], $last_email );
				if ( $existing ) {
					continue;
				}

				$is_verified         = (bool) $recipient['is_verified'];
				$verification_status = $is_verified ? 'verified' : 'pending';
				$grant_status        = $is_verified ? 'pending_release' : 'pending';

				$new_id = $this->create( array(
					'order_id'            => (int) $recipient['order_id'],
					'order_item_id'       => $last_item_id,
					'product_id'          => $product_id,
					'variation_id'        => $variation_id ?: $row_variation_id,
					'cloud_asset_id'      => $res['id'],
					'account_id'          => $account_id,
					'recipient_email'     => $last_email,
					'recipient_index'     => (int) $recipient['recipient_index'],
					'verification_status' => $verification_status,
					'grant_status'        => $grant_status,
					'origin'              => 'backfill',
				) );
				if ( $new_id ) {
					$created++;
				}
			}
		}

		$has_more = count( $recipients ) === $limit;
		return array(
			'created'      => $created,
			'has_more'     => $has_more,
			'last_item_id' => $last_item_id,
			'last_email'   => $last_email,
		);
	}

	/**
	 * Check whether a revoked-only recipient still maps to a paid seat.
	 */
	private function recipient_index_within_effective_quantity( $order_id, $order_item_id, $recipient_index ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$item = $order->get_item( $order_item_id );
		if ( ! $item ) {
			return false;
		}

		$quantity      = (int) $item->get_quantity();
		$qty_refunded  = abs( (int) $order->get_qty_refunded_for_item( $order_item_id ) );
		$effective_qty = max( 0, $quantity - $qty_refunded );

		return $recipient_index > 0 && $recipient_index <= $effective_qty;
	}

	/**
	 * Expire stale entitlements (pending verification with expired claim tokens).
	 */
	public function expire_stale() {
		global $wpdb;
		$table = $this->table();
		// Rows can be created/reactivated as pending with a NULL claim_token_expires_at
		// (the OTP that sets the expiry is issued afterward). If that OTP is never issued,
		// such a row would otherwise never expire and would permanently hold a recipient
		// slot — so fall back to created_at plus the full claim-token window as the cutoff.
		$grace_hours = (int) WGDP_OTP::CLAIM_TOKEN_EXPIRY_HOURS;
		return $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET verification_status = 'expired'
			 WHERE verification_status = 'pending'
			   AND (
			     ( claim_token_expires_at IS NOT NULL AND claim_token_expires_at < UTC_TIMESTAMP() )
			     OR ( claim_token_expires_at IS NULL AND created_at < ( UTC_TIMESTAMP() - INTERVAL %d HOUR ) )
			   )",
			$grace_hours
		) );
	}
}
