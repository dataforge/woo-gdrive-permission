<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Entitlements {

	private static $instance = null;

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
		);

		$data = wp_parse_args( $data, $defaults );

		$wpdb->insert( $this->table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->insert_id;
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
	 * Find an existing granted entitlement for the same email + asset (for grant dedup).
	 */
	public function get_by_email_and_asset( $email, $cloud_asset_id ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE recipient_email = %s AND cloud_asset_id = %s AND grant_status = 'granted' AND provider_permission_id IS NOT NULL LIMIT 1",
				$email,
				$cloud_asset_id
			),
			ARRAY_A
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
	 * Mark an entitlement as granted.
	 */
	public function mark_granted( $id, $permission_id ) {
		return $this->update( $id, array(
			'grant_status'          => 'granted',
			'provider_permission_id' => $permission_id,
			'granted_at'            => current_time( 'mysql', true ),
			'grant_error'           => null,
		) );
	}

	/**
	 * Mark an entitlement as revoked.
	 */
	public function mark_revoked( $id ) {
		return $this->update( $id, array(
			'grant_status' => 'revoked',
			'revoked_at'   => current_time( 'mysql', true ),
		) );
	}

	/**
	 * Mark an entitlement with a grant error and increment retry count.
	 */
	public function mark_error( $id, $error ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET grant_status = 'error', grant_error = %s, grant_retries = grant_retries + 1 WHERE id = %d",
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
	public function get_pending_release_for_product( $product_id, $limit = 100 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND verification_status = 'verified' AND grant_status = 'pending_release' LIMIT %d",
				$product_id,
				$limit
			),
			ARRAY_A
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
			'product_id'          => 0,
			'exclude_grant_status' => '',
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

		if ( ! empty( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$values[] = absint( $args['product_id'] );
		}

		if ( ! empty( $args['exclude_grant_status'] ) ) {
			$where[]  = 'grant_status != %s';
			$values[] = $args['exclude_grant_status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(recipient_email LIKE %s OR order_id = %d)';
			$values[] = $like;
			$values[] = absint( $args['search'] );
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
	 * Get entitlement IDs to revoke when quantity decreases (partial refund).
	 * Prioritizes: unverified first, then highest recipient_index.
	 */
	public function get_revocation_candidates( $order_item_id, $excess ) {
		global $wpdb;
		$table = $this->table();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, verification_status, grant_status, recipient_index FROM {$table}
				 WHERE order_item_id = %d AND grant_status != 'revoked'
				 ORDER BY
				   CASE WHEN verification_status = 'pending' THEN 0 ELSE 1 END,
				   recipient_index DESC
				 LIMIT %d",
				$order_item_id,
				$excess
			),
			ARRAY_A
		);

		return wp_list_pluck( $rows, 'id' );
	}

	/**
	 * Count entitlements that are verified or granted (not counting unverified pending) for an order item.
	 */
	public function count_confirmed_for_item( $order_item_id ) {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_item_id = %d AND grant_status != 'revoked' AND verification_status != 'pending'",
				$order_item_id
			)
		);
	}

	/**
	 * Revoke all unverified entitlements for an order item, freeing up slots.
	 *
	 * @return int Number of rows revoked.
	 */
	public function revoke_unverified_for_item( $order_item_id ) {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET grant_status = 'revoked', revoked_at = %s WHERE order_item_id = %d AND verification_status = 'pending' AND grant_status != 'revoked'",
				current_time( 'mysql', true ),
				$order_item_id
			)
		);
	}

	/**
	 * Count active (non-revoked) entitlements for an order item.
	 */
	public function count_active_for_item( $order_item_id ) {
		global $wpdb;
		$table = $this->table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_item_id = %d AND grant_status != 'revoked'",
				$order_item_id
			)
		);
	}

	/**
	 * Check if another non-revoked entitlement (excluding $exclude_id) references the same permission.
	 * Used to avoid deleting a Drive permission that is shared across multiple entitlements.
	 */
	public function permission_is_shared( $provider_permission_id, $exclude_id ) {
		global $wpdb;
		$table = $this->table();
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE provider_permission_id = %s AND id != %d AND grant_status != 'revoked' LIMIT 1",
				$provider_permission_id,
				$exclude_id
			)
		);
	}

	/**
	 * Get pending_release entitlements (safety net for batch overflow).
	 */
	public function get_stale_pending_release( $limit = 20 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE grant_status = 'pending_release' AND verification_status = 'verified' LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get entitlements with grant errors that are verified (for cron retry).
	 * Excludes entitlements that have exceeded the max retry count.
	 */
	public function get_failed_verified( $limit = 20, $max_retries = 50 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE grant_status = 'error' AND verification_status = 'verified' AND grant_retries < %d LIMIT %d",
				$max_retries,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Get order items where qty > active entitlement count (missing email / unassigned).
	 */
	public function get_unassigned_order_items( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'   => 20,
			'page'       => 1,
			'product_id' => 0,
			'search'     => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$table          = $this->table();
		$order_items    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$orders_table   = $wpdb->prefix . 'wc_orders';

		$extra_where  = '';
		$extra_values = array();

		if ( ! empty( $args['product_id'] ) ) {
			$extra_where   .= ' AND CAST(prod_meta.meta_value AS UNSIGNED) = %d';
			$extra_values[] = absint( $args['product_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$extra_where   .= ' AND oi.order_id = %d';
			$extra_values[] = absint( $args['search'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base_sql = "
			SELECT oi.order_item_id, oi.order_id,
				CAST(prod_meta.meta_value AS UNSIGNED) AS product_id,
				COALESCE(CAST(var_meta.meta_value AS UNSIGNED), 0) AS variation_id,
				CAST(qty_meta.meta_value AS UNSIGNED) AS qty,
				COALESCE(ent_counts.active_count, 0) AS assigned_count
			FROM {$order_items} oi
			INNER JOIN {$orders_table} o ON o.id = oi.order_id
			INNER JOIN {$order_itemmeta} prod_meta ON prod_meta.order_item_id = oi.order_item_id AND prod_meta.meta_key = '_product_id'
			LEFT JOIN  {$order_itemmeta} var_meta  ON var_meta.order_item_id = oi.order_item_id AND var_meta.meta_key = '_variation_id'
			INNER JOIN {$order_itemmeta} qty_meta  ON qty_meta.order_item_id = oi.order_item_id AND qty_meta.meta_key = '_qty'
			LEFT JOIN (
				SELECT order_item_id, COUNT(*) AS active_count
				FROM {$table} WHERE grant_status != 'revoked'
				GROUP BY order_item_id
			) ent_counts ON ent_counts.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
			  AND o.status IN ('wc-processing', 'wc-completed')
			  AND (
			    EXISTS (
			      SELECT 1 FROM {$wpdb->postmeta}
			      WHERE post_id = COALESCE(NULLIF(CAST(var_meta.meta_value AS UNSIGNED), 0), CAST(prod_meta.meta_value AS UNSIGNED))
			        AND meta_key = '_wgdp_drive_resource_id' AND meta_value != ''
			    )
			    OR EXISTS (
			      SELECT 1 FROM {$wpdb->postmeta}
			      WHERE post_id = CAST(prod_meta.meta_value AS UNSIGNED)
			        AND meta_key = '_wgdp_drive_resource_id' AND meta_value != ''
			    )
			  )
			  {$extra_where}
			HAVING qty > assigned_count
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

		// Post-process: filter by qualification and resolve cloud asset / account.
		$items = array();
		foreach ( $rows as $row ) {
			$product_id   = (int) $row['product_id'];
			$variation_id = (int) $row['variation_id'];

			if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
				$total--;
				continue;
			}

			// Resolve cloud_asset_id.
			$resource_id = '';
			if ( $variation_id ) {
				$resource_id = get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
			}
			if ( empty( $resource_id ) ) {
				$resource_id = get_post_meta( $product_id, '_wgdp_drive_resource_id', true );
			}

			$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );

			$row['cloud_asset_id']  = $resource_id;
			$row['account_id']      = $account_id;
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
		$orders_table   = $wpdb->prefix . 'wc_orders';

		// Only count items whose product (or variation) has a Drive resource configured.
		// This mirrors the PHP-side qualification filter in get_unassigned_order_items().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "
			SELECT COUNT(*) FROM (
				SELECT oi.order_item_id
				FROM {$order_items} oi
				INNER JOIN {$orders_table} o ON o.id = oi.order_id
				INNER JOIN {$order_itemmeta} prod_meta ON prod_meta.order_item_id = oi.order_item_id AND prod_meta.meta_key = '_product_id'
				LEFT JOIN  {$order_itemmeta} var_meta  ON var_meta.order_item_id = oi.order_item_id AND var_meta.meta_key = '_variation_id'
				INNER JOIN {$order_itemmeta} qty_meta  ON qty_meta.order_item_id = oi.order_item_id AND qty_meta.meta_key = '_qty'
				LEFT JOIN (
					SELECT order_item_id, COUNT(*) AS active_count
					FROM {$table} WHERE grant_status != 'revoked'
					GROUP BY order_item_id
				) ent_counts ON ent_counts.order_item_id = oi.order_item_id
				WHERE oi.order_item_type = 'line_item'
				  AND o.status IN ('wc-processing', 'wc-completed')
				  AND (
				    EXISTS (
				      SELECT 1 FROM {$wpdb->postmeta}
				      WHERE post_id = COALESCE(NULLIF(CAST(var_meta.meta_value AS UNSIGNED), 0), CAST(prod_meta.meta_value AS UNSIGNED))
				        AND meta_key = '_wgdp_drive_resource_id' AND meta_value != ''
				    )
				    OR EXISTS (
				      SELECT 1 FROM {$wpdb->postmeta}
				      WHERE post_id = CAST(prod_meta.meta_value AS UNSIGNED)
				        AND meta_key = '_wgdp_drive_resource_id' AND meta_value != ''
				    )
				  )
				HAVING CAST(qty_meta.meta_value AS UNSIGNED) > COALESCE(ent_counts.active_count, 0)
			) AS sub
		";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Expire stale entitlements (pending verification with expired claim tokens).
	 */
	public function expire_stale() {
		global $wpdb;
		$table = $this->table();
		return $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"UPDATE {$table} SET verification_status = 'expired'
			 WHERE verification_status = 'pending'
			   AND claim_token_expires_at IS NOT NULL
			   AND claim_token_expires_at < UTC_TIMESTAMP()"
		);
	}
}
