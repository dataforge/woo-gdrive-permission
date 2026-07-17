<?php
defined( 'ABSPATH' ) || exit;

class WGDP_DB {

	const DB_VERSION = '3.4.9';

	/**
	 * Get the entitlements table name.
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wgdp_entitlements';
	}

	/**
	 * Get the backfill jobs table name.
	 */
	public static function get_backfill_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wgdp_backfill_jobs';
	}

	/**
	 * Get the refund-totals cache table name.
	 *
	 * Caches, per order item, the cumulative refunded quantity so entitlement
	 * queries can join a single indexed row instead of scanning
	 * wc_order_itemmeta for '_refunded_item_id' rows on every page load.
	 */
	public static function get_refund_totals_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wgdp_refund_totals';
	}

	/**
	 * Create or update the database schema.
	 */
	public static function install() {
		global $wpdb;

		$table_name          = self::get_table_name();
		$backfill_table      = self::get_backfill_table_name();
		$refund_totals_table = self::get_refund_totals_table_name();
		$charset_collate     = $wpdb->get_charset_collate();

		$refund_totals_existed_before = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $refund_totals_table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

		$entitlements_sql = "CREATE TABLE {$table_name} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  order_item_id bigint(20) unsigned NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cloud_asset_id varchar(255) NOT NULL,
  account_id varchar(32) NOT NULL,
  recipient_email varchar(255) NOT NULL,
  recipient_index smallint unsigned NOT NULL DEFAULT 1,
  verification_status varchar(20) NOT NULL DEFAULT 'pending',
  otp_hash varchar(255) DEFAULT NULL,
  otp_expires_at datetime DEFAULT NULL,
  otp_attempts tinyint unsigned NOT NULL DEFAULT 0,
  claim_token_hash varchar(64) DEFAULT NULL,
  claim_token_expires_at datetime DEFAULT NULL,
  grant_status varchar(20) NOT NULL DEFAULT 'pending',
  provider_permission_id varchar(255) DEFAULT NULL,
  granted_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  revocation_reason varchar(40) DEFAULT NULL,
  revocation_error text DEFAULT NULL,
  revocation_retries smallint unsigned NOT NULL DEFAULT 0,
  grant_error text DEFAULT NULL,
  grant_retries smallint unsigned NOT NULL DEFAULT 0,
  origin varchar(20) NOT NULL DEFAULT 'order',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY order_item_asset_email (order_item_id, cloud_asset_id, recipient_email),
  KEY order_id (order_id),
  KEY recipient_email (recipient_email),
  KEY verification_status (verification_status),
  KEY grant_status (grant_status),
  KEY revocation_reason (revocation_reason),
  KEY claim_token_hash (claim_token_hash)
) {$charset_collate};";

		$backfill_sql = "CREATE TABLE {$backfill_table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  product_id bigint(20) unsigned NOT NULL,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  account_id varchar(32) NOT NULL,
  asset_ids text NOT NULL,
  cursor_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cursor_email varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  total_created int unsigned NOT NULL DEFAULT 0,
  attempts smallint unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at datetime DEFAULT NULL,
  processed_at datetime DEFAULT NULL,
  last_error text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) {$charset_collate};";

		$refund_totals_sql = "CREATE TABLE {$refund_totals_table} (
  order_item_id bigint(20) unsigned NOT NULL,
  refunded_qty int unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (order_item_id)
) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Pass each statement separately rather than concatenated — avoids relying
		// on dbDelta's internal statement splitting.
		dbDelta( array( $entitlements_sql, $backfill_sql, $refund_totals_sql ) );

		// Only record the new schema version once all tables actually exist, so a
		// partial/failed dbDelta is retried on the next maybe_upgrade() run instead
		// of being masked by a matching stored version.
		$entitlements_exists    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		$backfill_exists        = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $backfill_table ) ) );
		$refund_totals_exists   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $refund_totals_table ) ) );

		if ( $entitlements_exists && $backfill_exists && $refund_totals_exists ) {
			// Table is new on this run (didn't exist before dbDelta) — one-time backfill
			// from the authoritative source (wc_order_itemmeta) so existing refunds are
			// reflected immediately instead of only after their next refund event.
			if ( ! $refund_totals_existed_before ) {
				self::backfill_refund_totals();
			}
			update_option( 'wgdp_db_version', self::DB_VERSION );
		}
	}

	/**
	 * One-time population of the refund-totals cache from existing refund itemmeta.
	 *
	 * Runs once, when the cache table is first created, so pre-existing refunds are
	 * reflected without waiting for their next refund event to re-touch the cache.
	 */
	private static function backfill_refund_totals() {
		global $wpdb;

		$refund_totals_table = self::get_refund_totals_table_name();
		$order_itemmeta      = $wpdb->prefix . 'woocommerce_order_itemmeta';

		// INSERT IGNORE: if a concurrent request also observes the table as
		// newly-created and runs this same backfill, the loser's duplicate
		// rows (same PRIMARY KEY order_item_id) are silently skipped instead
		// of erroring out the whole statement.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			"INSERT IGNORE INTO {$refund_totals_table} (order_item_id, refunded_qty)
			SELECT CAST(refunded_meta.meta_value AS UNSIGNED) AS order_item_id,
			       SUM(ABS(CAST(refund_qty_meta.meta_value AS SIGNED))) AS refunded_qty
			FROM {$order_itemmeta} refunded_meta
			INNER JOIN {$order_itemmeta} refund_qty_meta
			  ON refund_qty_meta.order_item_id = refunded_meta.order_item_id
			 AND refund_qty_meta.meta_key = '_qty'
			WHERE refunded_meta.meta_key = '_refunded_item_id'
			GROUP BY CAST(refunded_meta.meta_value AS UNSIGNED)"
		);
	}

	/**
	 * Upsert the cumulative refunded quantity for one order item into the cache table.
	 *
	 * Called whenever a refund event touches an order item, keeping the cache in
	 * sync without ever re-scanning wc_order_itemmeta for entitlement queries.
	 *
	 * @param int $order_item_id   Original (non-refund) order item id.
	 * @param int $total_refunded_qty Cumulative refunded quantity for this item across all refunds.
	 */
	public static function set_refund_total( $order_item_id, $total_refunded_qty ) {
		global $wpdb;

		$refund_totals_table = self::get_refund_totals_table_name();

		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			"INSERT INTO {$refund_totals_table} (order_item_id, refunded_qty) VALUES (%d, %d)
			 ON DUPLICATE KEY UPDATE refunded_qty = VALUES(refunded_qty)",
			absint( $order_item_id ),
			absint( $total_refunded_qty )
		) );
	}

	/**
	 * Check if a DB upgrade is needed and run it.
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'wgdp_db_version', '' );
		if ( $current === self::DB_VERSION ) {
			return;
		}

		// install() only records wgdp_db_version once dbDelta() actually
		// produced all three tables. If that keeps failing (e.g. the DB user
		// lacks CREATE/ALTER privileges), this hook runs on every front-end,
		// admin, AJAX, and cron request via plugins_loaded — throttle retries
		// instead of re-running dbDelta() and three SHOW TABLES queries on
		// every single page load indefinitely.
		if ( false !== get_transient( 'wgdp_db_upgrade_attempted' ) ) {
			return;
		}
		set_transient( 'wgdp_db_upgrade_attempted', time(), 5 * MINUTE_IN_SECONDS );

		self::install();
	}

	/**
	 * Pin subsequent reads to the primary DB connection for this request.
	 *
	 * MySQL GET_LOCK()/RELEASE_LOCK() are session-scoped: both calls must land
	 * on the same connection, and that connection must be the primary or the
	 * lock provides no exclusion at all. On HyperDB-based setups (e.g. WP VIP)
	 * reads are otherwise routed to a replica, so the acquire and release can
	 * land on different sessions and the mutex silently no-ops.
	 */
	public static function pin_locks_to_primary() {
		global $wpdb;
		if ( method_exists( $wpdb, 'send_reads_to_masters' ) ) {
			$wpdb->send_reads_to_masters();
		}
	}

	/**
	 * Execute a callback while holding a MySQL named lock on the primary connection.
	 *
	 * Centralizes the GET_LOCK/RELEASE_LOCK pattern used across the plugin so
	 * every named lock is pinned to the primary connection and released in a
	 * finally block.
	 *
	 * @param string   $lock_name Lock name (already namespaced by the caller).
	 * @param int      $timeout   GET_LOCK timeout in seconds.
	 * @param callable $callback  Runs while the lock is held.
	 * @param mixed    $on_fail   Value returned when the lock cannot be acquired.
	 * @return mixed Callback return value, or $on_fail on lock failure.
	 */
	public static function with_named_lock( $lock_name, $timeout, $callback, $on_fail ) {
		global $wpdb;
		self::pin_locks_to_primary();

		$locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, max( 1, (int) $timeout ) )
		);

		if ( '1' !== (string) $locked ) {
			return $on_fail;
		}

		try {
			return $callback();
		} finally {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
		}
	}

	/**
	 * Atomically consume one fixed-window rate-limit token.
	 *
	 * Uses a short MySQL named lock around the transient read/write so parallel
	 * public requests cannot all pass the same counter before it is incremented.
	 *
	 * @param string $key    Logical rate-limit key.
	 * @param int    $limit  Maximum allowed requests during the window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the request is allowed.
	 */
	public static function consume_rate_limit( $key, $limit, $window ) {
		global $wpdb;

		// GET_LOCK()/RELEASE_LOCK() are session-scoped, so both calls must land on
		// the same connection — and that connection must be the primary, or the
		// lock provides no exclusion at all.
		self::pin_locks_to_primary();

		$limit = max( 1, (int) $limit );
		$window = max( 1, (int) $window );
		$cache_key = 'wgdp_rl_' . md5( $key );
		$lock_name = 'wgdp_rl_' . md5( $key );

		$locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name )
		);

		if ( '1' !== (string) $locked ) {
			return false;
		}

		try {
			// Fixed window: pin the reset time on first consume and preserve it on
			// subsequent consumes so the transient TTL is not extended each call.
			// Storing count + reset avoids the sliding-window behaviour where a
			// steady stream of requests could keep the window alive indefinitely
			// and roughly double the intended rate at window edges.
			$now    = time();
			$stored = get_transient( $cache_key );

			if ( is_array( $stored ) && isset( $stored['reset'], $stored['count'] ) && (int) $stored['reset'] > $now ) {
				$count = (int) $stored['count'];
				$reset = (int) $stored['reset'];
			} else {
				$count = 0;
				$reset = $now + $window;
			}

			if ( $count >= $limit ) {
				return false;
			}

			$ttl = max( 1, $reset - $now );
			set_transient( $cache_key, array( 'count' => $count + 1, 'reset' => $reset ), $ttl );
			return true;
		} finally {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
		}
	}

	/**
	 * Refund one previously consumed rate-limit token for the current window.
	 *
	 * Used when a request that passed consume_rate_limit() turned out to be
	 * wasted by an infrastructure failure (e.g. the email it was gating never
	 * sent), so a legitimate caller isn't locked out for a transient error
	 * that produced no side effect worth rate-limiting.
	 *
	 * @param string $key Logical rate-limit key, matching a prior consume_rate_limit() call.
	 */
	public static function release_rate_limit( $key ) {
		global $wpdb;

		self::pin_locks_to_primary();

		$cache_key = 'wgdp_rl_' . md5( $key );
		$lock_name = 'wgdp_rl_' . md5( $key );

		$locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $lock_name )
		);

		if ( '1' !== (string) $locked ) {
			return;
		}

		try {
			$now    = time();
			$stored = get_transient( $cache_key );

			if ( ! is_array( $stored ) || ! isset( $stored['reset'], $stored['count'] ) || (int) $stored['reset'] <= $now || (int) $stored['count'] <= 0 ) {
				return;
			}

			$ttl = max( 1, (int) $stored['reset'] - $now );
			set_transient( $cache_key, array( 'count' => (int) $stored['count'] - 1, 'reset' => (int) $stored['reset'] ), $ttl );
		} finally {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
		}
	}
}
