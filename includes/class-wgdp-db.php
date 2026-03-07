<?php
defined( 'ABSPATH' ) || exit;

class WGDP_DB {

	const DB_VERSION = '3.4.0';

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

		$sql = "CREATE TABLE {$table_name} (
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
  KEY claim_token_hash (claim_token_hash)
) {$charset_collate};";

		$sql .= "CREATE TABLE {$backfill_table} (
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
		dbDelta( $sql );

		update_option( 'wgdp_db_version', self::DB_VERSION );
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
}
