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

	private function __construct() {}

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
}
