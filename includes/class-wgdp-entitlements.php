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
			'origin'              => 'order',
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
	 * Get entitlements pending release for a specific variation.
	 */
	public function get_pending_release_for_variation( $product_id, $variation_id, $limit = 100 ) {
		global $wpdb;
		$table = $this->table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND variation_id = %d AND verification_status = 'verified' AND grant_status = 'pending_release' LIMIT %d",
				$product_id,
				$variation_id,
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
				"SELECT COUNT(DISTINCT recipient_email) FROM {$table} WHERE order_item_id = %d AND grant_status != 'revoked' AND verification_status != 'pending'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			'product_name'        => '',
			'order_id'            => 0,
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
				     MIN(CASE WHEN verification_status = 'pending' THEN 0 ELSE 1 END) AS priority,
				     MAX(recipient_index) AS max_index
				   FROM {$table}
				   WHERE order_item_id = %d AND grant_status != 'revoked'
				   GROUP BY recipient_email
				   ORDER BY priority, max_index DESC
				   LIMIT %d
				 ) sub",
				$order_item_id,
				$excess
			),
			ARRAY_A
		);

		return wp_list_pluck( $rows, 'recipient_email' );
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
			'per_page'     => 20,
			'page'         => 1,
			'product_id'   => 0,
			'product_name' => '',
			'order_id'     => 0,
			'search'       => '',
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
				SELECT order_item_id, COUNT(DISTINCT recipient_email) AS active_count
				FROM {$table} WHERE grant_status != 'revoked'
				GROUP BY order_item_id
			) ent_counts ON ent_counts.order_item_id = oi.order_item_id
			WHERE oi.order_item_type = 'line_item'
			  AND o.status IN ('wc-processing', 'wc-completed')
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
			  {$extra_where}
			  AND CAST(qty_meta.meta_value AS UNSIGNED) > COALESCE(ent_counts.active_count, 0)
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

			// Resolve resources (multi-file).
			$resources = WGDP_Product_Meta::get_drive_resources( $product_id, $variation_id ?: 0 );
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
		$orders_table   = $wpdb->prefix . 'wc_orders';

		// Only count items whose product (or variation) has a Drive resource configured
		// and the variation qualifies for digital access (_wgdp_includes_digital != 'no').
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
					SELECT order_item_id, COUNT(DISTINCT recipient_email) AS active_count
					FROM {$table} WHERE grant_status != 'revoked'
					GROUP BY order_item_id
				) ent_counts ON ent_counts.order_item_id = oi.order_item_id
				LEFT JOIN {$wpdb->postmeta} dig_meta
					ON dig_meta.post_id = CAST(var_meta.meta_value AS UNSIGNED)
					AND dig_meta.meta_key = '_wgdp_includes_digital'
				WHERE oi.order_item_type = 'line_item'
				  AND o.status IN ('wc-processing', 'wc-completed')
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
			) AS sub
		";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
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
		$email          = $args['email'];
		$resources      = $args['resources'];
		$account_id     = $args['account_id'];
		$reuse_revoked  = $args['reuse_revoked'] ?? true;

		// Resolve recipient_index.
		$recipient_index = (int) ( $args['recipient_index'] ?? 0 );
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

		$primary_entitlement_id = 0;

		foreach ( $resources as $res ) {
			$resource_id    = $res['id'];
			$entitlement_id = 0;

			if ( $reuse_revoked ) {
				$revoked = $this->get_revoked_for_reuse( $order_item_id, $resource_id, $email );
				if ( $revoked ) {
					$entitlement_id = (int) $revoked['id'];
					$this->update( $entitlement_id, array(
						'verification_status'      => 'pending',
						'grant_status'             => 'pending',
						'provider_permission_id'   => null,
						'granted_at'               => null,
						'revoked_at'               => null,
						'grant_error'              => null,
						'grant_retries'            => 0,
						'account_id'               => $account_id,
						'recipient_index'          => $recipient_index,
						'claim_token_hash'         => null,
						'claim_token_expires_at'   => null,
					) );
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
			}

			if ( $entitlement_id && ! $primary_entitlement_id ) {
				$primary_entitlement_id = $entitlement_id;
			}
		}

		if ( ! $primary_entitlement_id ) {
			return new WP_Error( 'create_failed', 'Failed to create entitlements.' );
		}

		// Issue OTP on primary only.
		$otp    = WGDP_OTP::instance();
		$tokens = $otp->issue_otp_for_entitlement( $primary_entitlement_id );

		return array(
			'primary_id'      => $primary_entitlement_id,
			'recipient_index' => $recipient_index,
			'file_count'      => count( $resources ),
			'tokens'          => $tokens,
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
		$email         = sanitize_email( $_POST['email'] ?? '' );

		if ( ! $order_id || ! $order_item_id ) {
			wp_send_json_error( 'Missing order or item ID.' );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( 'Please enter a valid email address.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		$item = $order->get_item( $order_item_id );
		if ( ! $item ) {
			wp_send_json_error( 'Order item not found.' );
		}

		$product_id   = $item->get_product_id();
		$variation_id = $item->get_variation_id();

		if ( ! WGDP_Product_Meta::variation_qualifies_for_digital( $product_id, $variation_id ?: 0 ) ) {
			wp_send_json_error( 'This item does not qualify for digital access.' );
		}

		// Resolve active resources (multi-file, excludes retired).
		$resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
		if ( empty( $resources ) ) {
			wp_send_json_error( 'No Drive resources configured for this item.' );
		}

		// Resolve account.
		$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );
		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			wp_send_json_error( 'No connected Google account for this item.' );
		}

		$ent    = self::instance();
		$result = $ent->create_entitlements_for_recipient( array(
			'order_id'       => $order_id,
			'order_item_id'  => $order_item_id,
			'product_id'     => $product_id,
			'variation_id'   => $variation_id ?: 0,
			'email'          => $email,
			'account_id'     => $account_id,
			'resources'      => $resources,
		) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		WGDP_Notification_Email::send_otp( $email, $result['tokens']['otp'], $result['tokens']['claim_token'], $order, $item );

		// Set drive items flag if not already set.
		if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
			$order->update_meta_data( '_wgdp_has_drive_items', '1' );
			$order->save();
		}

		$order->add_order_note( sprintf(
			'WGDP: Verification email sent to %s for "%s" (entitlement #%d) — %s',
			$email,
			$item->get_name(),
			$result['primary_id'],
			$note_context
		) );

		if ( $clear_counts ) {
			delete_transient( 'wgdp_permission_counts' );
		}

		wp_send_json_success( array(
			'id'              => $result['primary_id'],
			'email'           => $email,
			'recipient_index' => $result['recipient_index'],
			'file_count'      => $result['file_count'],
		) );
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
	 * Get active (non-revoked) recipients for a product, using keyset pagination.
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
		$table = $this->table();

		$where = "product_id = %d AND grant_status != 'revoked'";
		$values = array( $product_id );

		if ( $variation_id ) {
			$where   .= ' AND variation_id = %d';
			$values[] = $variation_id;
		}

		// Keyset cursor.
		$where   .= ' AND (order_item_id > %d OR (order_item_id = %d AND recipient_email > %s))';
		$values[] = $after_item_id;
		$values[] = $after_item_id;
		$values[] = $after_email;

		$values[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT order_item_id, order_id, recipient_email, recipient_index, account_id,
				MAX(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) AS is_verified,
				MAX(CASE WHEN grant_status = 'granted' THEN 1 ELSE 0 END) AS has_granted
			FROM {$table}
			WHERE {$where}
			GROUP BY order_item_id, recipient_email
			ORDER BY order_item_id, recipient_email
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

			foreach ( $new_resources as $res ) {
				// Skip if already exists (any status).
				$existing = $this->get_existing_entitlement( $last_item_id, $res['id'], $last_email );
				if ( $existing ) {
					continue;
				}

				$is_verified         = (bool) $recipient['is_verified'];
				$verification_status = $is_verified ? 'verified' : 'pending';
				$grant_status        = $is_verified ? 'pending_release' : 'pending';

				$this->create( array(
					'order_id'            => (int) $recipient['order_id'],
					'order_item_id'       => $last_item_id,
					'product_id'          => $product_id,
					'variation_id'        => $variation_id,
					'cloud_asset_id'      => $res['id'],
					'account_id'          => $recipient['account_id'] ?: $account_id,
					'recipient_email'     => $last_email,
					'recipient_index'     => (int) $recipient['recipient_index'],
					'verification_status' => $verification_status,
					'grant_status'        => $grant_status,
					'origin'              => 'backfill',
				) );
				$created++;
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
