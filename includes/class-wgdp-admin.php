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
		add_action( 'admin_notices', array( $this, 'maybe_show_token_error_notice' ) );
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
								$product_name = WGDP_Entitlements::get_product_name( $row );
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
					Step 1: Create a Google Cloud Project
				</summary>
				<ol style="margin-left:20px;">
					<li>Go to <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.</li>
					<li>Click the project dropdown at the top-left &rarr; <strong>New Project</strong>.</li>
					<li>Name it (e.g. <em>Woo GDrive Permission</em>) and click <strong>Create</strong>.</li>
					<li>Once created, select the project. You'll land on the Welcome page which displays both the <strong>Project ID</strong> and the <strong>Project Number</strong> (a pure numeric value) &mdash; note down the <strong>Project Number</strong>, you'll need it in Step 6.</li>
				</ol>
			</details>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 2: Enable Required APIs
				</summary>
				<ol style="margin-left:20px;">
					<li>From the Welcome page, click <strong>APIs &amp; Services</strong> in the Quick Access section (or use the left sidebar navigation).</li>
					<li>In the left sidebar, click <strong>Library</strong>.</li>
					<li>Search for <strong>"Google Drive API"</strong> &rarr; click it &rarr; click <strong>Enable</strong>.</li>
					<li>Go back to Library, search for <strong>"Google Picker API"</strong> &rarr; click it &rarr; click <strong>Enable</strong>.</li>
				</ol>
			</details>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 3: Configure OAuth Consent Screen
				</summary>
				<ol style="margin-left:20px;">
					<li>In the left sidebar under APIs &amp; Services, click <strong>OAuth consent screen</strong>. The new UI redirects you to Google Auth Platform.</li>
					<li>It will show "Google Auth Platform not configured yet" &mdash; click the blue <strong>"Get started"</strong> button.</li>
					<li>Fill in the required fields:
						<ul style="margin-top:6px;">
							<li><strong>App name:</strong> anything (e.g. <em>Woo GDrive Permission</em>)</li>
							<li><strong>User support email:</strong> your email</li>
						</ul>
					</li>
					<li>Click <strong>Next</strong>, then on the Audience step select <strong>External</strong>, then complete the remaining steps and click <strong>Create</strong>.</li>
					<li>You'll be taken to the OAuth Overview page. Now click <strong>"Data Access"</strong> in the left sidebar to add scopes.</li>
					<li>Click <strong>"Add or remove scopes"</strong>. In the panel that opens:
						<ul style="margin-top:6px;">
							<li>Check the box next to <code>.../auth/userinfo.email</code></li>
							<li>In the "Manually add scopes" text box, enter: <code>https://www.googleapis.com/auth/drive.file</code></li>
							<li>Click <strong>"Add to table"</strong></li>
							<li>Click <strong>"Update"</strong></li>
						</ul>
					</li>
					<li>Click <strong>"Save"</strong> on the Data Access page.</li>
					<li>Now click <strong>"Audience"</strong> in the left sidebar. You'll see the Publishing status is set to Testing. Click <strong>"Publish App"</strong> &rarr; confirm by clicking <strong>"Confirm"</strong> when prompted.<br>
						<strong style="color:#d63638;">This is critical</strong> &mdash; Testing-mode refresh tokens expire after 7 days, which will break automatic token renewal. Since you're only using non-sensitive scopes (<code>drive.file</code> and <code>email</code>), no app verification is required.</li>
				</ol>
			</details>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 4: Create OAuth Client ID
				</summary>
				<ol style="margin-left:20px;">
					<li>In the left sidebar, click <strong>"Clients"</strong> (under Google Auth Platform).</li>
					<li>Click <strong>"Create client"</strong>.</li>
					<li>Application type: <strong>Web application</strong>.</li>
					<li>Name: anything (e.g. <em>Woo GDrive Permission</em>).</li>
					<li>Leave <strong>Authorized JavaScript origins</strong> blank.</li>
					<li>Under <strong>Authorized redirect URIs</strong>, click <strong>"+ Add URI"</strong> and enter exactly:<br>
						<code style="display:inline-block;margin:6px 0;padding:4px 8px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:3px;user-select:all;"><?php echo esc_html( $auth->get_redirect_uri() ); ?></code>
					</li>
					<li>Click <strong>Create</strong>.</li>
					<li>A dialog will show your <strong>Client ID</strong> and <strong>Client Secret</strong> &mdash; copy both and store them securely.<br>
						<em style="color:#d63638;">The Client Secret cannot be viewed again after closing this dialog. If you lose it, you can generate a new one by opening the client and clicking "+ Add secret" &mdash; the old secret will still work until you delete it.</em></li>
				</ol>
			</details>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 5: Create a Picker API Key
				</summary>
				<ol style="margin-left:20px;">
					<li>Navigate to <strong>APIs &amp; Services &rarr; Credentials</strong> (use the main hamburger menu or go to <a href="https://console.cloud.google.com/apis/credentials" target="_blank">console.cloud.google.com/apis/credentials</a>).</li>
					<li>Click <strong>"+ Create credentials" &rarr; "API key"</strong>.</li>
					<li>A panel will open where you can configure the key before creating it:
						<ul style="margin-top:6px;">
							<li>Under <strong>Application restrictions</strong>, select <strong>"Websites"</strong></li>
							<li>Click <strong>"+ Add"</strong> and enter your admin domain: <code style="display:inline-block;padding:2px 6px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:3px;"><?php echo esc_html( wp_parse_url( admin_url(), PHP_URL_HOST ) ); ?>/*</code></li>
							<li>Under <strong>API restrictions</strong>, select <strong>"Restrict key"</strong> and choose <strong>Google Picker API</strong></li>
						</ul>
					</li>
					<li>Click <strong>"Create"</strong>. Copy the API key from the confirmation dialog.</li>
				</ol>
			</details>

			<details<?php echo $has_accounts ? '' : ' open'; ?>>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 6: Enter Credentials &amp; Connect
				</summary>
				<ol style="margin-left:20px;">
					<li>In the <strong>Google API Credentials</strong> section below, enter all four values:
						<ul style="margin-top:6px;">
							<li><strong>Client ID</strong> &mdash; from Step 4</li>
							<li><strong>Client Secret</strong> &mdash; from Step 4</li>
							<li><strong>Picker API Key</strong> &mdash; from Step 5</li>
							<li><strong>Cloud Project Number</strong> &mdash; the numeric value from Step 1</li>
						</ul>
					</li>
					<li>Click <strong>Save Changes</strong>.</li>
					<li>Click <strong>"Add Google Account"</strong> to authorize with Google. You'll be redirected to Google's consent screen, then back here.</li>
					<li>You can connect multiple Google accounts if needed.</li>
				</ol>
			</details>

			<details>
				<summary style="cursor:pointer;font-weight:600;margin-bottom:10px;">
					Step 7: Link Drive Files to Products
				</summary>
				<ol style="margin-left:20px;">
					<li>Edit any WooCommerce product and click the <strong>"GDrive"</strong> tab in the Product Data section.</li>
					<li>Select which <strong>Google account</strong> owns the file, then click <strong>"Browse GDrive"</strong> to open Google Picker and choose a file or folder. You can also paste a Google Drive URL directly.</li>
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
					<?php
					$secret_hint = '';
					if ( $has_creds ) {
						$dec = $auth->decrypt( get_option( 'wgdp_oauth_client_secret', '' ) );
						$secret_hint = $dec ? '••••••••' . substr( $dec, -4 ) : '';
					}
				?>
					<input type="text" id="wgdp_oauth_client_secret" name="wgdp_oauth_client_secret"
						value=""
						class="regular-text" style="min-width:400px;"
						placeholder="<?php echo $secret_hint ? esc_attr( $secret_hint ) : 'Enter Client Secret'; ?>" />
					<p class="description">Your Client Secret is stored encrypted. Leave blank to keep current secret.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wgdp_picker_api_key">Picker API Key</label></th>
				<td>
					<?php
					$api_key = get_option( 'wgdp_picker_api_key', '' );
					$api_key_hint = $api_key ? '••••••••' . substr( $api_key, -4 ) : '';
				?>
					<input type="text" id="wgdp_picker_api_key" name="wgdp_picker_api_key"
						value=""
						class="regular-text" style="min-width:400px;"
						placeholder="<?php echo $api_key_hint ? esc_attr( $api_key_hint ) : 'e.g. AIzaSy...'; ?>" />
					<p class="description">API key restricted to the Google Picker API. Used client-side to power the file browser.<?php echo $api_key_hint ? ' Leave blank to keep current key.' : ''; ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wgdp_cloud_project_number">Cloud Project Number</label></th>
				<td>
					<input type="text" id="wgdp_cloud_project_number" name="wgdp_cloud_project_number"
						value="<?php echo esc_attr( get_option( 'wgdp_cloud_project_number', '' ) ); ?>"
						class="regular-text" style="min-width:400px;"
						placeholder="e.g. 123456789012" />
					<p class="description">Found in Google Cloud Console &rarr; project settings. Required by Google Picker for the <code>drive.file</code> scope.</p>
				</td>
			</tr>
		</table>

		<h2>Connected Accounts</h2>
		<?php if ( ! empty( $accounts ) ) : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:750px;">
				<thead>
					<tr>
						<th style="width:30%;">Email</th>
						<th style="width:30%;">Label</th>
						<th style="width:40%;">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $accounts as $acct_id => $acct ) : ?>
						<tr id="wgdp-account-row-<?php echo esc_attr( $acct_id ); ?>">
							<td><?php echo esc_html( $acct['email'] ); ?></td>
							<td>
								<input type="text" class="wgdp-account-label"
									data-account-id="<?php echo esc_attr( $acct_id ); ?>"
									value="<?php echo esc_attr( $acct['label'] ); ?>"
									style="width:100%;" />
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
		<?php else : ?>
			<p>No accounts connected yet.</p>
		<?php endif; ?>

		<?php if ( $has_creds ) : ?>
			<p style="margin-top:12px;">
				<a href="<?php echo esc_url( $auth->get_auth_url() ); ?>" class="button button-primary">Add Google Account</a>
			</p>
		<?php endif; ?>

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
				if (!confirm('Disconnect this account? Products using it will not be able to grant permissions until reassigned.')) {
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
		});
		</script>

		<?php
		// General settings (notifications, etc.).
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
			if ( ! empty( $secret ) && strpos( $secret, '••••••••' ) !== 0 ) {
				update_option( 'wgdp_oauth_client_secret', $auth->encrypt( $secret ) );
			}
		}

		// Save Picker API Key (only if a new value was entered).
		if ( isset( $_POST['wgdp_picker_api_key'] ) ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['wgdp_picker_api_key'] ) );
			if ( ! empty( $api_key ) ) {
				update_option( 'wgdp_picker_api_key', $api_key );
			}
		}

		// Save Cloud Project Number.
		if ( isset( $_POST['wgdp_cloud_project_number'] ) ) {
			update_option( 'wgdp_cloud_project_number', sanitize_text_field( wp_unslash( $_POST['wgdp_cloud_project_number'] ) ) );
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
	 * Show an admin notice when a Google account's refresh token has expired or been revoked.
	 */
	public function maybe_show_token_error_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$auth     = WGDP_Google_Auth::instance();
		$accounts = $auth->get_accounts();
		$errors   = array();

		foreach ( array_keys( $accounts ) as $account_id ) {
			$error = get_transient( 'wgdp_token_error_' . $account_id );
			if ( $error ) {
				$errors[] = $error;
			}
		}

		if ( empty( $errors ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wgdp&tab=settings' );
		echo '<div class="notice notice-error">';
		echo '<p><strong>Woo GDrive Permission:</strong> A Google account authorization has expired or been revoked. ';
		echo 'Drive access grants will fail until you <a href="' . esc_url( $settings_url ) . '">disconnect and reconnect the account</a>.</p>';
		echo '<p class="description">If this keeps happening, make sure your Google Cloud OAuth app is <strong>published</strong> (not in "Testing" mode) &mdash; ';
		echo 'testing-mode refresh tokens expire after 7 days.</p>';
		echo '</div>';
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

		wp_enqueue_script(
			'wgdp-admin',
			WGDP_PLUGIN_URL . 'admin/js/wgdp-admin.js',
			array( 'jquery', 'jquery-ui-autocomplete' ),
			WGDP_VERSION,
			true
		);
		wp_localize_script( 'wgdp-admin', 'wgdp', array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'wgdp_admin_nonce' ),
			'product_search_nonce'  => wp_create_nonce( 'search-products' ),
			'picker_api_key'        => get_option( 'wgdp_picker_api_key', '' ),
			'cloud_project_number'  => get_option( 'wgdp_cloud_project_number', '' ),
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
		WGDP_Entitlements::ajax_create_entitlement( 'assigned by admin via Access Manager', true );
	}

}
