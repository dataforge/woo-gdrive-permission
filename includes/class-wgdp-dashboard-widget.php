<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Dashboard_Widget {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_dashboard_setup', array( $this, 'register_widget' ) );
	}

	public function register_widget() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wgdp_drive_permissions',
			'GDrive Entitlements',
			array( $this, 'render_widget' )
		);
	}

	public function render_widget() {
		// Guard against a stale/partial cached transient shape (from an older
		// plugin version) that may omit keys, which would emit PHP notices below.
		$counts = wp_parse_args(
			(array) WGDP_Entitlements_List::instance()->get_permission_counts(),
			array(
				'granted'              => 0,
				'pending_release'      => 0,
				'error'                => 0,
				'pending_verification' => 0,
				'revoked'              => 0,
			)
		);

		?>
		<div class="wgdp-widget-counts">
			<span class="wgdp-widget-count wgdp-widget-count--granted">
				<strong><?php echo esc_html( $counts['granted'] ); ?></strong> Granted
			</span>
			<span class="wgdp-widget-count wgdp-widget-count--pending-release">
				<strong><?php echo esc_html( $counts['pending_release'] ); ?></strong> Pending Release
			</span>
			<span class="wgdp-widget-count wgdp-widget-count--failed">
				<strong><?php echo esc_html( $counts['error'] ); ?></strong> Error
			</span>
			<span class="wgdp-widget-count wgdp-widget-count--pending">
				<strong><?php echo esc_html( $counts['pending_verification'] ); ?></strong> Pending
			</span>
			<span class="wgdp-widget-count wgdp-widget-count--revoked">
				<strong><?php echo esc_html( $counts['revoked'] ); ?></strong> Revoked
			</span>
		</div>
		<?php

		// Show recent failures from entitlements table.
		$failures = $this->get_recent_failures( 5 );
		if ( ! empty( $failures ) ) :
			?>
			<div class="wgdp-widget-failures">
				<h4>Recent Failures</h4>
				<ul>
					<?php foreach ( $failures as $failure ) : ?>
						<li>
							<a href="<?php echo esc_url( $failure['edit_url'] ); ?>">
								#<?php echo esc_html( $failure['order_id'] ); ?>
							</a>
							&mdash; <?php echo esc_html( $failure['recipient_email'] ); ?>
							<br>
							<small><?php echo esc_html( $failure['error'] ); ?></small>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php
		endif;

		?>
		<p class="wgdp-widget-footer">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wgdp&tab=access-manager' ) ); ?>">View all entitlements</a>
		</p>
		<?php
	}

	private function get_recent_failures( $limit = 5 ) {
		global $wpdb;
		$table = WGDP_DB::get_table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, order_id, recipient_email, grant_error, revocation_error, grant_status, created_at FROM {$table}
				 WHERE grant_status IN ( 'error', 'revocation_error' )
				 ORDER BY updated_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		$failures = array();
		foreach ( $rows as $row ) {
			$order   = wc_get_order( $row['order_id'] );
			$message = 'revocation_error' === $row['grant_status'] ? $row['revocation_error'] : $row['grant_error'];
			$failures[] = array(
				'order_id'        => $row['order_id'],
				'edit_url'        => $order ? $order->get_edit_order_url() : '#',
				'recipient_email' => $row['recipient_email'],
				'error'           => ( null === $message || '' === $message ) ? 'Unknown error' : $message,
			);
		}

		return $failures;
	}
}
