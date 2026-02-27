<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
		add_action( 'wp_ajax_wgdp_dismiss_setup_notice', array( $this, 'dismiss_setup_notice' ) );

		// Access Manager AJAX handlers.
		add_action( 'wp_ajax_wgdp_am_change_email', array( $this, 'ajax_change_email' ) );
		add_action( 'wp_ajax_wgdp_am_verify_permission', array( $this, 'ajax_verify_permission' ) );
		add_action( 'wp_ajax_wgdp_am_assign_email', array( $this, 'ajax_assign_email' ) );
	}

	/**
	 * Add unified submenu page under WooCommerce.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			'Woo Gdrive Permission',
			'Woo Gdrive Permission',
			'manage_woocommerce',
			'wgdp',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the unified page with tab routing.
	 */
	public function render_page() {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$tabs = array(
			'settings'       => 'Settings',
			'access-manager' => 'Access Manager',
		);

		// Handle settings save before rendering.
		if ( 'settings' === $current_tab && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wgdp_save_settings_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgdp_save_settings_nonce'] ) ), 'wgdp_save_settings' ) && current_user_can( 'manage_woocommerce' ) ) {
				$this->save_settings();
				echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
			}
		}

		echo '<div class="wrap">';
		echo '<h1>Woo Gdrive Permission</h1>';

		// Tab nav.
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url    = admin_url( 'admin.php?page=wgdp&tab=' . $slug );
			$active = ( $slug === $current_tab ) ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $active . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<div class="wgdp-tab-content" style="margin-top:20px;">';
		switch ( $current_tab ) {
			case 'access-manager':
				$this->render_access_manager_tab();
				break;
			default:
				$this->render_settings_tab();
				break;
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Process bulk actions from the Access Manager table.
	 */
	private function process_am_bulk_actions() {
		if ( ! isset( $_GET['action'] ) || ! isset( $_GET['entitlement'] ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		if ( '-1' === $action && isset( $_GET['action2'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_GET['action2'] ) );
		}
		$ids = array_map( 'absint', (array) $_GET['entitlement'] );

		if ( empty( $ids ) ) {
			return;
		}

		check_admin_referer( 'bulk-am-items' );

		$ent = WGDP_Entitlements::instance();
		$otp = WGDP_OTP::instance();

		if ( 'resend_otp' === $action ) {
			$count = 0;
			foreach ( $ids as $id ) {
				$row = $ent->get( $id );
				if ( $row && 'revoked' !== $row['grant_status'] && 'verified' !== $row['verification_status'] ) {
					$tokens = $otp->issue_otp_for_entitlement( $id );
					$order  = wc_get_order( $row['order_id'] );
					$item   = $order ? $order->get_item( $row['order_item_id'] ) : null;
					if ( $order && $item ) {
						WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
						$count++;
					}
				}
			}
			delete_transient( 'wgdp_permission_counts' );
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Resent OTP to %d entitlement(s).', $count ) ) . '</p></div>';
		} elseif ( 'revoke' === $action ) {
			$count = 0;
			$drive = WGDP_Google_Drive::instance();
			foreach ( $ids as $id ) {
				$row = $ent->get( $id );
				if ( $row && 'revoked' !== $row['grant_status'] ) {
					if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
						if ( ! $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
							$result = $drive->delete_permission( $row['cloud_asset_id'], $row['provider_permission_id'], $row['account_id'] );
							if ( ! is_wp_error( $result ) ) {
								$product_name = $this->get_product_name_from_row( $row );
								WGDP_Notification_Email::send_access_revoked( $row['recipient_email'], $product_name );
							}
						}
					}
					$ent->mark_revoked( $id );
					$count++;
				}
			}
			delete_transient( 'wgdp_permission_counts' );
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Revoked %d entitlement(s).', $count ) ) . '</p></div>';
		}
	}

	/**
	 * Render the settings tab.
	 */
	private function render_settings_tab() {
		$auth       = WGDP_Google_Auth::instance();
		$accounts   = $auth->get_accounts();
		$has_accounts = $auth->has_accounts();
		$client_id  = get_option( 'wgdp_oauth_client_id', '' );
		$has_creds  = ! empty( $client_id );

		// Handle OAuth callback.
		if ( isset( $_GET['code'] ) && ! empty( $_GET['code'] ) ) {
			if ( isset( $_GET['state'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'wgdp_oauth_state' ) ) {
				$result = $auth->handle_callback( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
				if ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>OAuth Error: ' . esc_html( $result->get_error_message() ) . '</p></div>';
				} else {
					// $result is the new account_id.
					$new_email = $auth->get_account_email( $result );
					$accounts   = $auth->get_accounts();
					$has_accounts = true;
					echo '<div class="notice notice-success"><p>Google account connected successfully! (' . esc_html( $new_email ) . ')</p></div>';
				}
				// Redirect to clean URL.
				echo '<script>if (window.history.replaceState) { window.history.replaceState(null, "", "' . esc_js( admin_url( 'admin.php?page=wgdp&tab=settings' ) ) . '"); }</script>';
			} else {
				echo '<div class="notice notice-error"><p>OAuth Error: Invalid state parameter. Please try again.</p></div>';
			}
		}

		// Handle OAuth error from Google.
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>Google returned an error: ' . esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) . '</p></div>';
		}

		echo '<form method="post" action="">';
		wp_nonce_field( 'wgdp_save_settings', 'wgdp_save_settings_nonce' );

		// Setup guide.
		?>
		<div class="wgdp-setup-guide" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid <?php echo $has_accounts ? '#00a32a' : '#2271b1'; ?>;padding:15px 20px;margin:20px 0;">
			<h2 style="margin-top:0;">
				<?php echo $has_accounts ? '&#9989; ' : '&#128218; '; ?>
				Getting Started with GDrive Permissions
			</h2>

			<?php if ( $has_accounts ) : ?>
				<p>Your Google account(s) are connected. Here's a refresher on setup and usage:</p>
			<?php else : ?>
				<p>Follow these steps to connect your GDrive and start selling digital access:</p>
			<?php endif; ?>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 1: Create OAuth Credentials
				</summary>
				<ol style="margin-left:20px;">
					<li>Go to <a href="https://console.cloud.google.com/apis/dashboard" target="_blank">Google Cloud Console</a> and create a project (or use an existing one).</li>
					<li>In the left sidebar go to <strong>APIs &amp; Services &rarr; Library</strong>, search for <strong>"Google Drive API"</strong>, and click <strong>Enable</strong>.</li>
					<li>Go to <strong>APIs &amp; Services &rarr; Credentials</strong>, click <strong>Create Credentials &rarr; OAuth client ID</strong>.</li>
					<li>Select <strong>Web application</strong> as the application type.</li>
					<li>Under <strong>Authorized redirect URIs</strong>, add: <code><?php echo esc_html( $auth->get_redirect_uri() ); ?></code></li>
					<li>Click <strong>Create</strong>. Copy the <strong>Client ID</strong> and <strong>Client Secret</strong>.</li>
				</ol>
			</details>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 2: Enter Credentials &amp; Connect
				</summary>
				<ol style="margin-left:20px;">
					<li>Enter the <strong>Client ID</strong> and <strong>Client Secret</strong> in the Google API Credentials section below and click <strong>Save changes</strong>.</li>
					<li>Click <strong>"Add Google Account"</strong> to authorize with Google.</li>
					<li>After authorizing, you'll be redirected back here. You can add multiple accounts.</li>
				</ol>
			</details>

			<details>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 3: Link Drive Files to Products
				</summary>
				<ol style="margin-left:20px;">
					<li>Edit any Woo product and click the <strong>"GDrive"</strong> tab in the Product Data section.</li>
					<li>Select which <strong>Google account</strong> owns the file, then paste a GDrive URL or click <strong>"Browse GDrive"</strong> to select a file or folder.</li>
					<li>For <strong>variable products</strong> (e.g. Digital vs DVD vs Blu-ray), you can set a different Drive resource on each variation.</li>
					<li>Save the product. That's it!</li>
				</ol>
			</details>

			<details>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					How It Works at Checkout
				</summary>
				<ul style="margin-left:20px;">
					<li>When a customer's cart contains a Drive-linked product, <strong>recipient email fields</strong> appear at checkout &mdash; one per quantity.</li>
					<li>On <strong>payment</strong> (order status &rarr; Processing), the plugin creates entitlements and sends <strong>verification emails</strong> with a 6-digit OTP code to each recipient.</li>
					<li>Recipients click the <strong>claim link</strong> in their email, enter the OTP code, and receive <strong>viewer access</strong> to the Drive resource.</li>
					<li>If an order is <strong>refunded or cancelled</strong>, access is automatically revoked. Partial refunds revoke excess entitlements (unverified first).</li>
					<li>If a Drive API grant fails, the plugin <strong>retries automatically</strong> every 20 minutes.</li>
				</ul>
			</details>
		</div>

		<h2>Google API Credentials</h2>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="wgdp_oauth_client_id">Client ID</label></th>
				<td>
					<input type="text" id="wgdp_oauth_client_id" name="wgdp_oauth_client_id"
						value="<?php echo esc_attr( $client_id ); ?>"
						class="regular-text" style="min-width:400px;"
						placeholder="e.g. 123456789-abc.apps.googleusercontent.com" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wgdp_oauth_client_secret">Client Secret</label></th>
				<td>
					<input type="password" id="wgdp_oauth_client_secret" name="wgdp_oauth_client_secret"
						value="<?php echo $has_creds ? '••••••••' : ''; ?>"
						class="regular-text" style="min-width:400px;"
						placeholder="Enter Client Secret" />
					<p class="description">Your Client Secret is stored encrypted.</p>
				</td>
			</tr>
		</table>

		<?php if ( $has_creds ) : ?>
			<h2>Connected Accounts</h2>
			<?php if ( ! empty( $accounts ) ) : ?>
				<table class="wp-list-table widefat fixed striped" style="max-width:950px;">
					<thead>
						<tr>
							<th style="width:22%;">Email</th>
							<th style="width:18%;">Label</th>
							<th style="width:28%;">Folder Permission</th>
							<th style="width:32%;">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $accounts as $acct_id => $acct ) :
							$root_id   = $acct['root_folder_id'] ?? '';
							$root_name = $acct['root_folder_name'] ?? '';
						?>
							<tr id="wgdp-account-row-<?php echo esc_attr( $acct_id ); ?>">
								<td><?php echo esc_html( $acct['email'] ); ?></td>
								<td>
									<input type="text" class="wgdp-account-label"
										data-account-id="<?php echo esc_attr( $acct_id ); ?>"
										value="<?php echo esc_attr( $acct['label'] ); ?>"
										style="width:100%;" />
								</td>
								<td>
									<span class="wgdp-root-folder-display" data-account-id="<?php echo esc_attr( $acct_id ); ?>">
										<?php if ( $root_id && $root_name ) : ?>
											<strong><?php echo esc_html( $root_name ); ?></strong>
										<?php else : ?>
											<em>Entire Drive</em>
										<?php endif; ?>
									</span>
									<br>
									<button type="button" class="button button-small wgdp-pick-root-folder"
										data-account-id="<?php echo esc_attr( $acct_id ); ?>"
										style="margin-top:4px;">Browse</button>
									<?php if ( $root_id ) : ?>
									<a href="#" class="wgdp-reset-root-folder"
										data-account-id="<?php echo esc_attr( $acct_id ); ?>"
										style="margin-left:6px;font-size:12px;">Reset</a>
									<?php endif; ?>
								</td>
								<td>
									<button type="button" class="button button-small wgdp-test-account"
										data-account-id="<?php echo esc_attr( $acct_id ); ?>">Test</button>
									<button type="button" class="button button-small wgdp-disconnect-account"
										data-account-id="<?php echo esc_attr( $acct_id ); ?>"
										style="color:#b32d2e;">Disconnect</button>
									<span class="wgdp-account-result" data-account-id="<?php echo esc_attr( $acct_id ); ?>" style="margin-left:8px;"></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="max-width:950px;">Folder Permission limits where the plugin browses when selecting files for products. Click Browse to pick a specific folder, or leave as "Entire Drive" for full access. This does not restrict the Google API token — it scopes the plugin's browsing behavior.</p>
			<?php else : ?>
				<p>No accounts connected yet.</p>
			<?php endif; ?>

			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( $auth->get_auth_url() ); ?>" class="button button-primary">Add Google Account</a>
			</p>

			<script>
			jQuery(function($) {
				var nonce = '<?php echo esc_js( wp_create_nonce( 'wgdp_admin_nonce' ) ); ?>';

				// Test connection for a specific account.
				$(document).on('click', '.wgdp-test-account', function() {
					var $btn = $(this);
					var accountId = $btn.data('account-id');
					var $result = $('.wgdp-account-result[data-account-id="' + accountId + '"]');
					$btn.prop('disabled', true);
					$result.text('Testing...').css('color', '');
					$.post(ajaxurl, {
						action: 'wgdp_test_connection',
						nonce: nonce,
						account_id: accountId
					}, function(r) {
						$btn.prop('disabled', false);
						$result.text(r.success ? r.data : 'Error: ' + r.data).css('color', r.success ? 'green' : 'red');
					}).fail(function() {
						$btn.prop('disabled', false);
						$result.text('Request failed.').css('color', 'red');
					});
				});

				// Disconnect a specific account.
				$(document).on('click', '.wgdp-disconnect-account', function() {
					if (!confirm('Disconnect this Google account? Products using it will not be able to grant permissions until reassigned.')) {
						return;
					}
					var $btn = $(this);
					var accountId = $btn.data('account-id');
					$btn.prop('disabled', true);
					$.post(ajaxurl, {
						action: 'wgdp_disconnect',
						nonce: nonce,
						account_id: accountId
					}, function(r) {
						if (r.success) {
							$('#wgdp-account-row-' + accountId).fadeOut(300, function() { $(this).remove(); });
						} else {
							$btn.prop('disabled', false);
							alert('Error: ' + r.data);
						}
					}).fail(function() {
						$btn.prop('disabled', false);
						alert('Request failed.');
					});
				});

				// Save label on blur/change.
				$(document).on('change', '.wgdp-account-label', function() {
					var $input = $(this);
					var accountId = $input.data('account-id');
					var label = $input.val().trim();
					var $result = $('.wgdp-account-result[data-account-id="' + accountId + '"]');
					$.post(ajaxurl, {
						action: 'wgdp_update_account_label',
						nonce: nonce,
						account_id: accountId,
						label: label
					}, function(r) {
						if (r.success) {
							$result.text('Saved').css('color', 'green');
							setTimeout(function() { $result.text(''); }, 2000);
						} else {
							$result.text('Error: ' + r.data).css('color', 'red');
						}
					});
				});

				// Pick root folder — open browse modal for the account.
				$(document).on('click', '.wgdp-pick-root-folder', function() {
					var accountId = $(this).data('account-id');
					// Use the existing browse modal but for picking a root folder.
					if (typeof window.wgdpOpenRootFolderPicker === 'function') {
						window.wgdpOpenRootFolderPicker(accountId, nonce);
					}
				});

				// Reset root folder to entire drive.
				$(document).on('click', '.wgdp-reset-root-folder', function(e) {
					e.preventDefault();
					var accountId = $(this).data('account-id');
					var $display = $('.wgdp-root-folder-display[data-account-id="' + accountId + '"]');
					var $result = $('.wgdp-account-result[data-account-id="' + accountId + '"]');
					$.post(ajaxurl, {
						action: 'wgdp_update_account_root_folder',
						nonce: nonce,
						account_id: accountId,
						folder_id: '',
						folder_name: ''
					}, function(r) {
						if (r.success) {
							$display.html('<em>Entire Drive</em>');
							$display.closest('td').find('.wgdp-reset-root-folder').remove();
							$result.text('Saved').css('color', 'green');
							setTimeout(function() { $result.text(''); }, 2000);
						} else {
							$result.text('Error: ' + r.data).css('color', 'red');
						}
					});
				});
			});
			</script>
		<?php endif; ?>

		<?php
		// General settings (Shared Drive ID, notifications).
		woocommerce_admin_fields( $this->get_settings() );
		?>

		<p class="submit">
			<button type="submit" class="button button-primary" name="wgdp_save" value="1">Save changes</button>
		</p>

		</form>
		<?php
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		$auth = WGDP_Google_Auth::instance();

		// Save Client ID.
		if ( isset( $_POST['wgdp_oauth_client_id'] ) ) {
			update_option( 'wgdp_oauth_client_id', sanitize_text_field( wp_unslash( $_POST['wgdp_oauth_client_id'] ) ) );
		}

		// Save Client Secret (only if changed from placeholder).
		if ( isset( $_POST['wgdp_oauth_client_secret'] ) ) {
			$secret = sanitize_text_field( wp_unslash( $_POST['wgdp_oauth_client_secret'] ) );
			if ( ! empty( $secret ) && $secret !== '••••••••' ) {
				update_option( 'wgdp_oauth_client_secret', $auth->encrypt( $secret ) );
			}
		}

		// Check if claim page slug changed (before saving, so we can compare).
		$old_slug = get_option( 'wgdp_claim_page_slug', 'wgdp-claim-access' );

		// Save WC settings fields.
		woocommerce_update_options( $this->get_settings() );

		// Enforce URL-safe slug.
		$new_slug = get_option( 'wgdp_claim_page_slug', 'wgdp-claim-access' );
		$sanitized_slug = sanitize_title( $new_slug );
		if ( empty( $sanitized_slug ) ) {
			$sanitized_slug = 'wgdp-claim-access';
		}
		if ( $sanitized_slug !== $new_slug ) {
			update_option( 'wgdp_claim_page_slug', $sanitized_slug );
		}

		// Update the claim page slug if it changed.
		if ( $old_slug !== $sanitized_slug ) {
			WGDP_Claim_Page::update_page_slug( $sanitized_slug );
		}

		// Clear token cache.
		$auth->clear_token_cache();
	}

	/**
	 * Define the general settings fields.
	 */
	private function get_settings() {
		return array(
			array(
				'title' => __( 'General Settings', 'woo-gdrive-permission' ),
				'type'  => 'title',
				'desc'  => '',
				'id'    => 'wgdp_general_section',
			),
			array(
				'title'   => __( 'Google Drive Share Notification', 'woo-gdrive-permission' ),
				'desc'    => __( 'When enabled, Google will send the recipient a "shared with you" email from Google Drive each time access is granted. This is in addition to the plugin\'s own verification and access-granted emails.', 'woo-gdrive-permission' ),
				'id'      => 'wgdp_send_notification',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => __( 'Entitlement Trigger', 'woo-gdrive-permission' ),
				'desc'     => __( 'When entitlements are created for new orders. Per-product overrides can be set in the product GDrive tab.', 'woo-gdrive-permission' ),
				'id'       => 'wgdp_entitlement_trigger',
				'type'     => 'select',
				'default'  => 'on_payment',
				'options'  => array(
					'on_payment'    => __( 'On Payment (Processing)', 'woo-gdrive-permission' ),
					'on_completion' => __( 'On Completion', 'woo-gdrive-permission' ),
				),
			),
			array(
				'title'       => __( 'Claim Page Slug', 'woo-gdrive-permission' ),
				'desc'        => __( 'The URL slug for the recipient verification page. Change requires saving and flushing permalinks.', 'woo-gdrive-permission' ),
				'id'          => 'wgdp_claim_page_slug',
				'type'        => 'text',
				'default'     => 'wgdp-claim-access',
				'placeholder' => 'wgdp-claim-access',
				'css'         => 'min-width: 300px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wgdp_general_section',
			),
		);
	}

	/**
	 * Render the Access Manager tab.
	 */
	private function render_access_manager_tab() {
		$this->process_am_bulk_actions();

		$counts        = WGDP_Entitlements_List::instance()->get_permission_counts();
		$missing_count = WGDP_Entitlements::instance()->count_unassigned_order_items();

		$current_status = isset( $_GET['am_status'] ) ? sanitize_text_field( wp_unslash( $_GET['am_status'] ) ) : '';

		// Summary cards (clickable).
		echo '<div class="wgdp-summary-cards">';
		$cards = array(
			'missing_email'        => array( 'label' => 'Missing Email',        'class' => 'missing-email', 'count' => $missing_count ),
			'pending_verification' => array( 'label' => 'Pending Verification', 'class' => 'pending',       'count' => $counts['pending_verification'] ?? 0 ),
			'pending_release'      => array( 'label' => 'Pending Release',      'class' => 'pending-release', 'count' => $counts['pending_release'] ?? 0 ),
			'granted'              => array( 'label' => 'Granted',              'class' => 'granted',       'count' => $counts['granted'] ?? 0 ),
			'error'                => array( 'label' => 'Error',                'class' => 'failed',        'count' => $counts['error'] ?? 0 ),
			'revoked'              => array( 'label' => 'Revoked',              'class' => 'revoked',       'count' => $counts['revoked'] ?? 0 ),
		);
		foreach ( $cards as $status_key => $card ) {
			$url    = admin_url( 'admin.php?page=wgdp&tab=access-manager&am_status=' . $status_key );
			$active = ( $current_status === $status_key ) ? ' wgdp-summary-card--active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="wgdp-summary-card wgdp-summary-card--' . esc_attr( $card['class'] ) . $active . '">';
			echo '<span class="wgdp-summary-count">' . esc_html( $card['count'] ) . '</span>';
			echo '<span class="wgdp-summary-label">' . esc_html( $card['label'] ) . '</span>';
			echo '</a>';
		}
		echo '</div>';

		// Determine display mode.
		$display_mode = ( 'missing_email' === $current_status ) ? 'missing_email' : 'entitlements';

		// Access Manager table.
		$table = new WGDP_Access_Manager_Table( $display_mode );
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wgdp" />';
		echo '<input type="hidden" name="tab" value="access-manager" />';
		$table->search_box( 'Search', 'wgdp-am-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Show a dismissible admin notice until the plugin is configured.
	 */
	public function maybe_show_setup_notice() {
		if ( get_option( 'wgdp_setup_notice_dismissed' ) ) {
			return;
		}

		if ( WGDP_Google_Auth::instance()->has_accounts() ) {
			update_option( 'wgdp_setup_notice_dismissed', true );
			return;
		}

		if ( isset( $_GET['page'] ) && 'wgdp' === $_GET['page'] ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wgdp&tab=settings' );
		?>
		<div class="notice notice-info is-dismissible" id="wgdp-setup-notice">
			<p>
				<strong>Woo GDrive Permission</strong> is active but not configured yet.
				<a href="<?php echo esc_url( $settings_url ); ?>">Go to Settings</a> to connect your Google account and start selling digital GDrive access.
			</p>
		</div>
		<script>
			jQuery(function($) {
				$(document).on('click', '#wgdp-setup-notice .notice-dismiss', function() {
					$.post(ajaxurl, { action: 'wgdp_dismiss_setup_notice', _wpnonce: '<?php echo esc_js( wp_create_nonce( 'wgdp_dismiss_notice' ) ); ?>' });
				});
			});
		</script>
		<?php
	}

	/**
	 * AJAX handler to dismiss the setup notice.
	 */
	public function dismiss_setup_notice() {
		check_ajax_referer( 'wgdp_dismiss_notice' );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			update_option( 'wgdp_setup_notice_dismissed', true );
		}
		wp_die();
	}

	/**
	 * Enqueue admin assets on our unified page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_wgdp' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/css/wgdp-admin.css',
			array(),
			WGDP_VERSION
		);

		wp_enqueue_script( 'jquery-ui-dialog' );
		wp_enqueue_style( 'wp-jquery-ui-dialog' );

		wp_enqueue_script(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/js/wgdp-admin.js',
			array( 'jquery', 'jquery-ui-dialog', 'jquery-ui-autocomplete' ),
			WGDP_VERSION,
			true
		);
		wp_localize_script( 'wgdp-admin', 'wgdp', array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'wgdp_admin_nonce' ),
			'product_search_nonce'  => wp_create_nonce( 'search-products' ),
		) );
	}

	/**
	 * Resolve Drive file names for a set of asset_id => account_id pairs.
	 * Uses transient caching (10 min TTL).
	 */
	public static function resolve_drive_names( $asset_pairs ) {
		$results = array();
		$to_fetch = array();

		foreach ( $asset_pairs as $asset_id => $account_id ) {
			$cached = get_transient( 'wgdp_drive_name_' . $asset_id );
			if ( false !== $cached ) {
				$results[ $asset_id ] = $cached;
			} else {
				$to_fetch[ $asset_id ] = $account_id;
			}
		}

		$drive = WGDP_Google_Drive::instance();
		foreach ( $to_fetch as $asset_id => $account_id ) {
			if ( empty( $account_id ) ) {
				$results[ $asset_id ] = array( 'name' => substr( $asset_id, 0, 12 ) . '...', 'webViewLink' => '' );
				continue;
			}

			$file = $drive->get_file( $asset_id, $account_id );
			if ( is_wp_error( $file ) ) {
				$results[ $asset_id ] = array( 'name' => substr( $asset_id, 0, 12 ) . '...', 'webViewLink' => '' );
			} else {
				$info = array(
					'name'        => $file['name'] ?? substr( $asset_id, 0, 12 ) . '...',
					'webViewLink' => $file['webViewLink'] ?? '',
				);
				$results[ $asset_id ] = $info;
				set_transient( 'wgdp_drive_name_' . $asset_id, $info, 10 * MINUTE_IN_SECONDS );
			}
		}

		return $results;
	}

	/**
	 * AJAX: Change recipient email on an entitlement.
	 */
	public function ajax_change_email() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$entitlement_id = absint( $_POST['entitlement_id'] ?? 0 );
		$new_email      = sanitize_email( $_POST['new_email'] ?? '' );

		if ( ! $entitlement_id ) {
			wp_send_json_error( 'Missing entitlement ID.' );
		}
		if ( ! is_email( $new_email ) ) {
			wp_send_json_error( 'Please enter a valid email address.' );
		}

		$ent = WGDP_Entitlements::instance();
		$row = $ent->get( $entitlement_id );
		if ( ! $row ) {
			wp_send_json_error( 'Entitlement not found.' );
		}
		if ( 'revoked' === $row['grant_status'] ) {
			wp_send_json_error( 'Cannot change email on a revoked entitlement.' );
		}

		$old_email    = $row['recipient_email'];
		$drive_warning = '';

		// If was granted with a Drive permission, revoke it (unless shared).
		if ( 'granted' === $row['grant_status'] && ! empty( $row['provider_permission_id'] ) ) {
			if ( ! $ent->permission_is_shared( $row['provider_permission_id'], $row['id'] ) ) {
				$delete_result = WGDP_Google_Drive::instance()->delete_permission(
					$row['cloud_asset_id'],
					$row['provider_permission_id'],
					$row['account_id']
				);
				if ( is_wp_error( $delete_result ) ) {
					$drive_warning = 'Warning: Could not remove Drive access for the old email (' . $old_email . '). Error: ' . $delete_result->get_error_message() . '. You may need to remove it manually in Google Drive.';
				}
			}
		}

		// Update entitlement: reset statuses.
		$ent->update( $entitlement_id, array(
			'recipient_email'       => $new_email,
			'verification_status'   => 'pending',
			'grant_status'          => 'pending',
			'provider_permission_id' => null,
			'granted_at'            => null,
			'grant_error'           => null,
			'grant_retries'         => 0,
		) );

		// Issue new OTP and send verification email.
		$otp    = WGDP_OTP::instance();
		$tokens = $otp->issue_otp_for_entitlement( $entitlement_id );
		$order  = wc_get_order( $row['order_id'] );
		$item   = $order ? $order->get_item( $row['order_item_id'] ) : null;
		if ( $order && $item ) {
			WGDP_Notification_Email::send_otp( $new_email, $tokens['otp'], $tokens['claim_token'], $order, $item );
			$order->add_order_note( sprintf(
				'WGDP: Recipient email changed from %s to %s for "%s" — changed by admin',
				$old_email,
				$new_email,
				$item->get_name()
			) );
		}

		delete_transient( 'wgdp_permission_counts' );

		$response = array(
			'new_email'           => $new_email,
			'verification_status' => 'pending',
			'grant_status'        => 'pending',
		);
		if ( $drive_warning ) {
			$response['warning'] = $drive_warning;
		}
		wp_send_json_success( $response );
	}

	/**
	 * AJAX: Verify a Drive permission still exists.
	 */
	public function ajax_verify_permission() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$entitlement_id = absint( $_POST['entitlement_id'] ?? 0 );
		if ( ! $entitlement_id ) {
			wp_send_json_error( 'Missing entitlement ID.' );
		}

		$ent = WGDP_Entitlements::instance();
		$row = $ent->get( $entitlement_id );
		if ( ! $row ) {
			wp_send_json_error( 'Entitlement not found.' );
		}

		if ( empty( $row['provider_permission_id'] ) ) {
			wp_send_json_success( array(
				'status'  => 'no_permission',
				'message' => 'No Drive permission ID recorded for this entitlement.',
			) );
		}

		$result = WGDP_Google_Drive::instance()->get_permission(
			$row['cloud_asset_id'],
			$row['provider_permission_id'],
			$row['account_id']
		);

		if ( is_wp_error( $result ) ) {
			if ( 'wgdp_permission_not_found' === $result->get_error_code() ) {
				wp_send_json_success( array(
					'status'  => 'missing',
					'message' => 'Permission no longer exists on Google Drive.',
				) );
			}
			wp_send_json_success( array(
				'status'  => 'error',
				'message' => $result->get_error_message(),
			) );
		}

		wp_send_json_success( array(
			'status'  => 'confirmed',
			'message' => 'Permission exists. Role: ' . ( $result['role'] ?? 'unknown' ) . ', Email: ' . ( $result['emailAddress'] ?? 'unknown' ),
		) );
	}

	/**
	 * AJAX: Assign an email to an unassigned order item (create entitlement).
	 */
	public function ajax_assign_email() {
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

		// Resolve cloud_asset_id.
		$resource_id = '';
		if ( $variation_id ) {
			$resource_id = get_post_meta( $variation_id, '_wgdp_drive_resource_id', true );
		}
		if ( empty( $resource_id ) ) {
			$resource_id = get_post_meta( $product_id, '_wgdp_drive_resource_id', true );
		}

		// Resolve account.
		$account_id = WGDP_Product_Meta::get_account_for_item( $product_id, $variation_id );
		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			wp_send_json_error( 'No connected Google account for this item.' );
		}

		$ent = WGDP_Entitlements::instance();

		// Check if a revoked entitlement exists for the same unique key — reactivate it instead of inserting.
		$revoked = $ent->get_revoked_for_reuse( $order_item_id, $resource_id, $email );
		if ( $revoked ) {
			$entitlement_id  = (int) $revoked['id'];
			$recipient_index = (int) $revoked['recipient_index'];
			$ent->update( $entitlement_id, array(
				'verification_status'    => 'pending',
				'grant_status'           => 'pending',
				'provider_permission_id' => null,
				'granted_at'             => null,
				'revoked_at'             => null,
				'grant_error'            => null,
				'grant_retries'          => 0,
				'account_id'             => $account_id,
			) );
		} else {
			// Calculate recipient_index as max existing + 1.
			$existing = $ent->get_by_order_item( $order_item_id );
			$max_index = 0;
			foreach ( $existing as $row ) {
				if ( (int) $row['recipient_index'] > $max_index ) {
					$max_index = (int) $row['recipient_index'];
				}
			}
			$recipient_index = $max_index + 1;

			$entitlement_id = $ent->create( array(
				'order_id'        => $order_id,
				'order_item_id'   => $order_item_id,
				'product_id'      => $product_id,
				'variation_id'    => $variation_id ?: 0,
				'cloud_asset_id'  => $resource_id,
				'account_id'      => $account_id,
				'recipient_email' => $email,
				'recipient_index' => $recipient_index,
			) );

			if ( ! $entitlement_id ) {
				wp_send_json_error( 'Failed to create entitlement.' );
			}
		}

		// Issue OTP and send verification email.
		$otp    = WGDP_OTP::instance();
		$tokens = $otp->issue_otp_for_entitlement( $entitlement_id );
		WGDP_Notification_Email::send_otp( $email, $tokens['otp'], $tokens['claim_token'], $order, $item );

		// Set drive items flag if not already set.
		if ( ! $order->get_meta( '_wgdp_has_drive_items' ) ) {
			$order->update_meta_data( '_wgdp_has_drive_items', '1' );
			$order->save();
		}

		$order->add_order_note( sprintf(
			'WGDP: Verification email sent to %s for "%s" (entitlement #%d) — assigned by admin via Access Manager',
			$email,
			$item->get_name(),
			$entitlement_id
		) );

		delete_transient( 'wgdp_permission_counts' );

		wp_send_json_success( array(
			'id'              => $entitlement_id,
			'email'           => $email,
			'recipient_index' => $recipient_index,
		) );
	}

	/**
	 * Get product name from an entitlement row.
	 */
	private function get_product_name_from_row( $row ) {
		$id = $row['variation_id'] ?: $row['product_id'];
		$product = wc_get_product( $id );
		return $product ? $product->get_name() : 'Product #' . $id;
	}
}
