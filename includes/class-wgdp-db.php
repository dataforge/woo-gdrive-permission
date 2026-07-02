<?php
defined( 'ABSPATH' ) || exit;

class WGDP_DB {

	const DB_VERSION = '3.4.8';

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
	 * Create or update the database schema.
	 */
	public static function install() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$backfill_table  = self::get_backfill_table_name();
		$charset_collate = $wpdb->get_charset_collate();

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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Pass each statement separately rather than concatenated — avoids relying
		// on dbDelta's internal statement splitting.
		dbDelta( array( $entitlements_sql, $backfill_sql ) );

		// Only record the new schema version once both tables actually exist, so a
		// partial/failed dbDelta is retried on the next maybe_upgrade() run instead
		// of being masked by a matching stored version.
		$entitlements_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) );
		$backfill_exists     = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $backfill_table ) ) );

		if ( $entitlements_exists && $backfill_exists ) {
			update_option( 'wgdp_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Check if a DB upgrade is needed and run it.
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'wgdp_db_version', '' );
		if ( $current !== self::DB_VERSION ) {
			self::install();
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
}
