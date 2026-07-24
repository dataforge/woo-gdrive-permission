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
		add_action( 'admin_notices', array( $this, 'maybe_show_decrypt_failure_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_backfill_error_notice' ) );
		add_action( 'wp_ajax_wgdp_dismiss_setup_notice', array( $this, 'dismiss_setup_notice' ) );

		// Access Manager AJAX handlers.
		add_action( 'wp_ajax_wgdp_am_change_email', array( $this, 'ajax_change_email' ) );
		add_action( 'wp_ajax_wgdp_am_verify_permission', array( $this, 'ajax_verify_permission' ) );
		add_action( 'wp_ajax_wgdp_am_assign_email', array( $this, 'ajax_assign_email' ) );
		add_action( 'wp_ajax_wgdp_retry_grant', array( $this, 'ajax_retry_grant' ) );
		add_action( 'wp_ajax_wgdp_am_send_access_email', array( $this, 'ajax_send_access_email' ) );
		add_action( 'wp_ajax_wgdp_am_resend_order_email', array( $this, 'ajax_resend_order_email' ) );
		add_action( 'wp_ajax_wgdp_am_request_new_email', array( $this, 'ajax_request_new_email' ) );
	}

	/**
	 * Settings and OAuth credentials require a narrower capability than operations.
	 */
	private static function current_user_can_manage_settings() {
		return WGDP_Google_Auth::current_user_can_manage_credentials();
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
		if ( 'settings' === $current_tab && ! self::current_user_can_manage_settings() ) {
			$current_tab = 'access-manager';
		}
		$tabs = array(
			'settings'       => 'Settings',
			'access-manager' => 'Access Manager',
		);

		// Handle settings save before rendering.
		if ( 'settings' === $current_tab && isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['wgdp_save_settings_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wgdp_save_settings_nonce'] ) ), 'wgdp_save_settings' ) && self::current_user_can_manage_settings() ) {
				$this->save_settings();
				echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>Settings were not saved: your session expired. Please try again.</p></div>';
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
				if ( ! self::current_user_can_manage_settings() ) {
					echo '<div class="notice notice-error"><p>You do not have permission to manage Google Drive credentials.</p></div>';
					break;
				}
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

		if ( 'resend_otp' === $action ) {
			$count       = 0;
			$seen_groups = array();
			foreach ( $ids as $id ) {
				$peek = $ent->get( $id );
				if ( ! $peek ) {
					continue;
				}
				// Wrap the read-check-act per row in the same order-item lock used by
				// the single-item paths, so a claim-page submission for this order item
				// can't interleave with issue_otp_for_recipient_group() mid-bulk-run.
				$ent->with_order_item_lock( (int) $peek['order_item_id'], function () use ( $ent, $id, &$count, &$seen_groups ) {
					$row = $ent->get( $id );
					if ( ! $row || 'revoked' === $row['grant_status'] || 'verified' === $row['verification_status'] ) {
						return;
					}
					// issue_otp_for_recipient_group() reissues one shared token for the
					// whole order_item_id + recipient_email group, so only process each
					// group once per bulk run — otherwise a later row in the same group
					// invalidates the token/email just sent for an earlier row.
					$group_key = $row['order_item_id'] . '|' . $row['recipient_email'];
					if ( isset( $seen_groups[ $group_key ] ) ) {
						return;
					}
					$seen_groups[ $group_key ] = true;
					$tokens = $ent->issue_otp_for_recipient_group( $id );
					if ( is_wp_error( $tokens ) ) {
						return;
					}
					$order = wc_get_order( $row['order_id'] );
					$item  = $order ? $order->get_item( $row['order_item_id'] ) : null;
					if ( $order && $item ) {
						$mail_result = WGDP_Notification_Email::send_otp( $row['recipient_email'], $tokens['otp'], $tokens['claim_token'], $order, $item );
						if ( ! is_wp_error( $mail_result ) ) {
							$count++;
						}
					}
				} );
			}
			delete_transient( 'wgdp_permission_counts' );
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Resent OTP to %d entitlement(s).', $count ) ) . '</p></div>';
		} elseif ( 'retry_grant' === $action ) {
			$count         = 0;
			$errors        = 0;
			$skipped_stale = 0;
			foreach ( $ids as $id ) {
				$peek = $ent->get( $id );
				if ( ! $peek ) {
					continue;
				}
				// grant_drive_access_for_entitlement() already locks the entitlement
				// itself, but mark_error() below did not — wrap the whole row in the
				// order-item lock so a concurrent claim-page submission for this order
				// item can't race the error write.
				$ent->with_order_item_lock( (int) $peek['order_item_id'], function () use ( $ent, $id, &$count, &$errors, &$skipped_stale ) {
					$row = $ent->get( $id );
					if ( ! $row || 'error' !== $row['grant_status'] || 'verified' !== $row['verification_status'] ) {
						return;
					}
					// Unlike the single-item AJAX handler, bulk retry can't safely
					// reprovision, so it must not blindly retry against a
					// cloud_asset_id that's no longer part of the product's current
					// Drive resources — that would grant access to a detached file.
					$current_asset_ids = wp_list_pluck(
						WGDP_Product_Meta::get_active_drive_resources( (int) $row['product_id'], (int) $row['variation_id'] ?: 0 ),
						'id'
					);
					if ( ! in_array( $row['cloud_asset_id'], $current_asset_ids, true ) ) {
						$skipped_stale++;
						return;
					}
					$result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $row, false );
					if ( is_wp_error( $result ) ) {
						$ent->mark_error( $id, $result->get_error_message() );
						$errors++;
					} elseif ( null !== $result ) {
						$count++;
						WGDP_Order_Handler::instance()->maybe_auto_complete_order( $row['order_id'] );
					}
				} );
			}
			delete_transient( 'wgdp_permission_counts' );
			$msg = sprintf( 'Retried %d entitlement(s): %d granted', count( $ids ), $count );
			if ( $errors ) {
				$msg .= sprintf( ', %d still failing', $errors );
			}
			if ( $skipped_stale ) {
				$msg .= sprintf( ', %d skipped (Drive file changed — use the single-item Retry Grant button to reprovision)', $skipped_stale );
			}
			echo '<div class="notice notice-' . ( $errors || $skipped_stale ? 'warning' : 'success' ) . '"><p>' . esc_html( $msg ) . '.</p></div>';
		} elseif ( 'revoke' === $action ) {
			$count = 0;
			$errors = 0;
			$notified_emails = array();
			foreach ( $ids as $id ) {
				$row = $ent->get( $id );
				if ( $row && 'revoked' !== $row['grant_status'] ) {
					$result = $ent->revoke_with_drive_delete( $row, WGDP_Entitlements::REVOCATION_REASON_MANUAL );
					if ( is_wp_error( $result ) ) {
						$errors++;
						continue;
					}
					$count++;

					// Collect product names per recipient+order for notification, so a bulk
					// revoke spanning multiple orders for the same recipient doesn't merge
					// products from different orders under one order number.
					$notify_key = $row['recipient_email'] . '|' . ( $row['order_id'] ?? 0 );
					$notified_emails[ $notify_key ]['row']                                                     = $notified_emails[ $notify_key ]['row'] ?? $row;
					$notified_emails[ $notify_key ]['products'][ WGDP_Entitlements::get_product_name( $row ) ] = true;
				}
			}
			foreach ( $notified_emails as $data ) {
				$product_name = implode( ', ', array_keys( $data['products'] ) );
				WGDP_Notification_Email::send_access_revoked( $data['row']['recipient_email'], $product_name, $data['row']['order_id'] ?? 0 );
			}
			delete_transient( 'wgdp_permission_counts' );
			$msg = sprintf( 'Revoked %d entitlement(s).', $count );
			if ( $errors ) {
				$msg .= sprintf( ' %d entitlement(s) could not be removed from Drive and will be retried.', $errors );
			}
			echo '<div class="notice notice-' . ( $errors ? 'warning' : 'success' ) . '"><p>' . esc_html( $msg ) . '</p></div>';
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
		$admin_origin = untrailingslashit( home_url() );

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
					<li>Under <strong>Authorized JavaScript origins</strong>, click <strong>"+ Add URI"</strong> and enter exactly:<br>
						<code style="display:inline-block;margin:6px 0;padding:4px 8px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:3px;user-select:all;"><?php echo esc_html( $admin_origin ); ?></code>
					</li>
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
							<li>Click <strong>"+ Add"</strong> and enter your site referrer: <code style="display:inline-block;padding:2px 6px;background:#f0f6fc;border:1px solid #c3c4c7;border-radius:3px;"><?php echo esc_html( $admin_origin ); ?>/*</code></li>
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
					<li>Select which <strong>Google account</strong> owns the file, then click <strong>"Google Picker"</strong> to use Google's file picker or <strong>"Browse GDrive"</strong> to use the plugin fallback browser. You can also paste a Google Drive URL directly.</li>
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
						$dec = $auth->get_client_secret();
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

		<!-- Shortcode Reference -->
		<div class="wgdp-shortcode-ref" style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:15px 20px;margin:20px 0;">
			<h2 style="margin-top:0;">Shortcode Reference</h2>
			<table class="widefat striped" style="max-width:800px;">
				<thead>
					<tr>
						<th style="width:40%;">Shortcode</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><code>[wgdp_sold_count]</code></td>
						<td>Displays the total number of paid units for the current product, using the plugin's own sales counter (not WooCommerce's <code>total_sales</code>). Refund-aware and respects per-variation threshold settings.</td>
					</tr>
					<tr>
						<td><code>[wgdp_sold_count id="123"]</code></td>
						<td>Same as above but for a specific product ID. Useful when embedding the count outside of a product page.</td>
					</tr>
					<tr>
						<td><code>[wgdp_sold_count additional="10"]</code></td>
						<td>Adds a manual offset to the displayed count (e.g. to include sales from before the plugin was installed).</td>
					</tr>
					<tr>
						<td><code>[wgdp_sold_count subtract="5"]</code></td>
						<td>Subtracts a manual offset from the displayed count.</td>
					</tr>
					<tr>
						<td><code>[wgdp_min_sales_qty]</code></td>
						<td>Displays the sales threshold quantity for the current product (the number set in the "Sales Threshold" field under Min Sales Qty release mode).</td>
					</tr>
					<tr>
						<td><code>[wgdp_min_sales_qty variation_id="456"]</code></td>
						<td>Displays the threshold for a specific variation. If that variation has its own Min Sales Qty release mode, its threshold is returned; otherwise falls back to the product-level threshold.</td>
					</tr>
				</tbody>
			</table>
			<p class="description" style="margin-top:10px;">All <code>wgdp_sold_count</code> parameters can be combined: <code>[wgdp_sold_count id="123" additional="10" subtract="2"]</code></p>
		</div>

		<div class="card" style="margin-top:2em;">
			<h2>Plugin Updates</h2>
			<p>
				Current version: <strong>v<?php echo esc_html( WGDP_VERSION ); ?></strong>
				<?php if ( isset( $_GET['update_check'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<?php if ( WGDP_Updater::is_update_available() ) : ?>
						&mdash; <span style="color:#b32d2e;">Update available!</span>
						<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">Go to Updates</a>
					<?php else : ?>
						&mdash; <span style="color:#00a32a;">Up to date</span>
					<?php endif; ?>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="wgdp_check_updates" />
				<?php wp_nonce_field( 'wgdp_check_updates' ); ?>
				<button type="submit" class="button">Check for Updates</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		$auth = WGDP_Google_Auth::instance();

		// Save Client ID.
		if ( isset( $_POST['wgdp_oauth_client_id'] ) ) {
			update_option( 'wgdp_oauth_client_id', sanitize_text_field( wp_unslash( $_POST['wgdp_oauth_client_id'] ) ), false );
		}

		// Save Client Secret (only if changed from placeholder).
		if ( isset( $_POST['wgdp_oauth_client_secret'] ) ) {
			$secret = sanitize_text_field( wp_unslash( $_POST['wgdp_oauth_client_secret'] ) );
			if ( ! empty( $secret ) && strpos( $secret, '••••••••' ) !== 0 ) {
				update_option( 'wgdp_oauth_client_secret', $auth->encrypt( $secret ), false );
			}
		}

		// Save Picker API Key (only if a new value was entered).
		if ( isset( $_POST['wgdp_picker_api_key'] ) ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['wgdp_picker_api_key'] ) );
			if ( ! empty( $api_key ) ) {
				update_option( 'wgdp_picker_api_key', $api_key, false );
			}
		}

		// Save Cloud Project Number.
		if ( isset( $_POST['wgdp_cloud_project_number'] ) ) {
			update_option( 'wgdp_cloud_project_number', sanitize_text_field( wp_unslash( $_POST['wgdp_cloud_project_number'] ) ), false );
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

		// Collapsible action reference.
		echo '<details class="wgdp-action-help" style="margin:12px 0 16px;border:1px solid #e2e4e7;border-radius:4px;background:#f8f9fa;">';
		echo '<summary style="padding:10px 14px;cursor:pointer;font-weight:600;font-size:13px;color:#555;">Action Reference</summary>';
		echo '<div style="padding:4px 14px 14px;font-size:13px;line-height:1.6;color:#555;">';
		echo '<table class="widefat fixed" style="border:none;background:transparent;box-shadow:none;">';
		echo '<tbody>';
		echo '<tr><td style="width:130px;font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Assign Email</td>'
			. '<td style="padding:6px 8px;">Set the recipient Google account for an order item that is missing an email address. Sends a verification email with an OTP code.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Resend OTP</td>'
			. '<td style="padding:6px 8px;">Send a new verification code to the recipient. Use when the original code expired or the email was lost. Resets the OTP expiry timer.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Change Email</td>'
			. '<td style="padding:6px 8px;">Switch the recipient to a different Google account. If access was already granted on Drive, the old permission is removed first. A new verification email is sent to the new address.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Remove Account</td>'
			. '<td style="padding:6px 8px;">Remove the current Google Drive permission and free the order slot. The row becomes Awaiting Assignment; use Resend Order Email to send the purchaser the Provide Google Email link.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Verify on Drive</td>'
			. '<td style="padding:6px 8px;">Check that the Google Drive permission still exists and is valid. Useful for confirming access wasn\'t removed outside of the plugin (e.g. manually in Drive).</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Send Access Email</td>'
			. '<td style="padding:6px 8px;">Re-send the access-granted email with Google Drive link(s) to the recipient. Only available for verified and granted entitlements.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Resend Order Email</td>'
			. '<td style="padding:6px 8px;">Re-send the WooCommerce order email to the purchaser. This includes the "Provide Google Email" link for any unassigned digital access slots.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;">Retry Grant</td>'
			. '<td style="padding:6px 8px;">Re-attempt granting Google Drive access for an entitlement that previously failed. If the product\'s Drive files have changed since the error, new entitlements are created for the current files.</td></tr>';
		echo '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;padding:6px 8px;color:#b32d2e;">Revoke</td>'
			. '<td style="padding:6px 8px;">Remove the recipient\'s Google Drive permission and mark the entitlement as revoked. The recipient is notified by email. This action cannot be undone — to restore access, assign a new email.</td></tr>';
		echo '</tbody></table>';

		echo '<p style="margin:10px 0 0;font-size:12px;color:#888;"><strong>Bulk actions:</strong> Select multiple rows and use the Bulk Actions dropdown to Resend OTP, Retry Grant, or Revoke in batch.</p>';
		echo '</div>';
		echo '</details>';

		// Determine display mode.
		$display_mode = ( 'missing_email' === $current_status ) ? 'missing_email' : 'entitlements';

		// Access Manager table.
		$table = new WGDP_Access_Manager_Table( $display_mode );
		$table->prepare_items();

		// Buffer the form output so we can strip _wp_http_referer fields
		// that WordPress injects via wp_nonce_field(). On GET forms these
		// cause URL parameter snowballing with each submission.
		ob_start();
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="wgdp" />';
		echo '<input type="hidden" name="tab" value="access-manager" />';
		$table->search_box( 'Search', 'wgdp-am-search' );
		$table->display();
		echo '</form>';
		$form_html = ob_get_clean();
		echo preg_replace( '/<input[^>]*name=["\']_wp_http_referer["\'][^>]*>/i', '', $form_html );
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
	 * Show an admin notice when the stored Google account data exists but
	 * can't be decrypted (AUTH_KEY changed, or the option is corrupted).
	 * Without this, every read path just sees an empty accounts array,
	 * indistinguishable from "no account ever connected", so grants/revokes
	 * fail silently forever with no indication of the real cause.
	 */
	public function maybe_show_decrypt_failure_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! WGDP_Google_Auth::instance()->has_decrypt_failure() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wgdp&tab=settings' );
		echo '<div class="notice notice-error">';
		echo '<p><strong>Woo GDrive Permission:</strong> Stored Google account data could not be decrypted. ';
		echo 'This usually happens when the site\'s AUTH_KEY secret changed (e.g. after a migration or restore) or the stored option was corrupted. ';
		echo 'All Drive access grants and revokes will silently fail until you reconnect your Google account(s) on the <a href="' . esc_url( $settings_url ) . '">Settings tab</a>.</p>';
		echo '</div>';
	}

	/**
	 * Show an admin notice when async backfill jobs fail.
	 */
	public function maybe_show_backfill_error_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;
		$table = WGDP_DB::get_backfill_table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) AS cnt, MAX(last_error) AS last_error FROM {$table} WHERE status = 'failed'",
			ARRAY_A
		);

		if ( empty( $row ) || empty( $row['cnt'] ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=wgdp&tab=settings' );
		echo '<div class="notice notice-warning"><p><strong>Woo GDrive Permission:</strong> ';
		echo esc_html( sprintf( '%d Drive file backfill job(s) failed.', (int) $row['cnt'] ) ) . ' ';
		if ( ! empty( $row['last_error'] ) ) {
			echo esc_html( 'Error: ' . $row['last_error'] ) . ' ';
		}
		echo '<a href="' . esc_url( $settings_url ) . '">Check Google account settings</a>.</p></div>';
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

		if ( isset( $_GET['page'] ) && 'wgdp' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
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
		$can_manage_settings = self::current_user_can_manage_settings();
		wp_localize_script( 'wgdp-admin', 'wgdp', array(
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'nonce'                 => wp_create_nonce( 'wgdp_admin_nonce' ),
			'product_search_nonce'  => wp_create_nonce( 'search-products' ),
			'oauth_client_id'       => $can_manage_settings ? get_option( 'wgdp_oauth_client_id', '' ) : '',
			'picker_api_key'        => $can_manage_settings ? get_option( 'wgdp_picker_api_key', '' ) : '',
			'cloud_project_number'  => $can_manage_settings ? get_option( 'wgdp_cloud_project_number', '' ) : '',
			'can_manage_settings'   => $can_manage_settings,
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
				$info = array( 'name' => substr( $asset_id, 0, 12 ) . '...', 'webViewLink' => '' );
				$results[ $asset_id ] = $info;
				set_transient( 'wgdp_drive_name_' . $asset_id, $info, 3 * MINUTE_IN_SECONDS );
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
		$new_email      = WGDP_Entitlements::normalize_email( $_POST['new_email'] ?? '' );

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

		$result = $ent->with_order_item_lock( (int) $row['order_item_id'], function () use ( $ent, $entitlement_id, $new_email ) {
			$row = $ent->get( $entitlement_id );
			if ( ! $row ) {
				return new WP_Error( 'wgdp_entitlement_not_found', 'Entitlement not found.' );
			}
			if ( 'revoked' === $row['grant_status'] ) {
				return new WP_Error( 'wgdp_entitlement_revoked', 'Cannot change email on a revoked entitlement.' );
			}
			if ( 'revocation_error' === $row['grant_status'] ) {
				return new WP_Error( 'wgdp_revocation_pending', 'Cannot change email while Drive access removal is pending retry.' );
			}

			$old_email = $row['recipient_email'];
			if ( strtolower( trim( $old_email ) ) === strtolower( trim( $new_email ) ) ) {
				return new WP_Error( 'wgdp_same_email', 'The new email is the same as the current email.' );
			}

			// Collect all siblings (same order_item_id + same old email) to update together.
			$all_rows = $ent->get_siblings( $row['order_item_id'], $old_email );
			if ( empty( $all_rows ) ) {
				$all_rows = array( $row );
			}

			$group_ids = array_map( 'intval', wp_list_pluck( $all_rows, 'id' ) );

			foreach ( $all_rows as $sibling ) {
				if ( 'revoked' === $sibling['grant_status'] ) {
					continue;
				}
				if ( 'revocation_error' === $sibling['grant_status'] ) {
					return new WP_Error( 'wgdp_revocation_pending', 'Cannot change email while Drive access removal is pending retry.' );
				}

				$conflict = $ent->get_existing_entitlement(
					(int) $sibling['order_item_id'],
					$sibling['cloud_asset_id'],
					$new_email
				);
				if ( $conflict && 'revoked' !== $conflict['grant_status'] && ! in_array( (int) $conflict['id'], $group_ids, true ) ) {
					return new WP_Error(
						'wgdp_duplicate_recipient',
						'That email is already assigned to this order item and Drive file.'
					);
				}
			}

			$current_resources = WGDP_Product_Meta::get_drive_resources( $row['product_id'], $row['variation_id'] ?: 0 );
			$resource_map      = array();
			foreach ( $current_resources as $resource ) {
				if ( ! empty( $resource['id'] ) ) {
					$resource_map[ $resource['id'] ] = $resource;
				}
			}

			$replacement_resources = array();
			$revoke_ids            = array();
			foreach ( $all_rows as $sibling ) {
				if ( 'revoked' === $sibling['grant_status'] ) {
					continue;
				}
				$replacement_resources[] = array(
					'id'   => $sibling['cloud_asset_id'],
					'type' => $resource_map[ $sibling['cloud_asset_id'] ]['type'] ?? WGDP_Entitlements::get_resource_type( $sibling ),
					'name' => $resource_map[ $sibling['cloud_asset_id'] ]['name'] ?? $sibling['cloud_asset_id'],
				);
				$revoke_ids[] = (int) $sibling['id'];
			}

			if ( empty( $replacement_resources ) ) {
				return new WP_Error( 'wgdp_no_replacement_resources', 'No active entitlement rows were available to replace.' );
			}

			// Use revoke_with_drive_delete() (not a snapshot-based delete+mark_revoked)
			// so each row is re-read under its own per-entitlement lock immediately
			// before revoking. A sibling snapshotted here as "not yet granted" may be
			// granted for real by a concurrent cron retry (e.g. a pending_release row
			// whose release gate opens mid-request) between the snapshot above and
			// this point; re-reading under the lock catches that and deletes the live
			// Drive permission instead of leaving it orphaned.
			foreach ( $revoke_ids as $revoke_id ) {
				$revoke_result = $ent->revoke_with_drive_delete(
					array( 'id' => $revoke_id ),
					WGDP_Entitlements::REVOCATION_REASON_REASSIGNMENT
				);
				if ( is_wp_error( $revoke_result ) ) {
					return new WP_Error(
						'wgdp_old_permission_delete_failed',
						'Could not remove Drive access for the old email (' . $old_email . '): ' . $revoke_result->get_error_message() . '. Replacement was not completed; any removed permissions were marked revoked and this revocation will be retried automatically.'
					);
				}
			}

			$replacement = $ent->create_entitlements_for_recipient( array(
				'order_id'        => (int) $row['order_id'],
				'order_item_id'   => (int) $row['order_item_id'],
				'product_id'      => (int) $row['product_id'],
				'variation_id'    => (int) ( $row['variation_id'] ?? 0 ),
				'email'           => $new_email,
				'account_id'      => $row['account_id'],
				'resources'       => $replacement_resources,
				'recipient_index' => (int) $row['recipient_index'],
				'reuse_revoked'   => true,
			) );

			if ( is_wp_error( $replacement ) ) {
				return $replacement;
			}

			if ( empty( $replacement['tokens'] ) ) {
				return new WP_Error( 'wgdp_replacement_exists', 'The new email is already assigned for these Drive files.' );
			}

			return array(
				'row'         => $row,
				'all_rows'    => $all_rows,
				'old_email'   => $old_email,
				'tokens'      => $replacement['tokens'],
				'file_count'  => $replacement['file_count'],
				'primary_id'  => $replacement['primary_id'],
			);
		} );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$row       = $result['row'];
		$old_email = $result['old_email'];
		$tokens    = $result['tokens'];
		$file_count = (int) ( $result['file_count'] ?? count( $result['all_rows'] ) );
		$order     = wc_get_order( $row['order_id'] );
		$item      = $order ? $order->get_item( $row['order_item_id'] ) : null;
			if ( $order && $item ) {
				$mail_result = WGDP_Notification_Email::send_otp( $new_email, $tokens['otp'], $tokens['claim_token'], $order, $item );
				if ( is_wp_error( $mail_result ) ) {
					$order->add_order_note( sprintf(
						'WGDP: Recipient email changed from %s to %s for "%s" (%d file(s)), but verification email failed — %s',
							$old_email,
							$new_email,
							$item->get_name(),
							$file_count,
							$mail_result->get_error_message()
						) );
					} else {
						$order->add_order_note( sprintf(
							'WGDP: Recipient email changed from %s to %s for "%s" (%d file(s)) — changed by admin',
							$old_email,
							$new_email,
							$item->get_name(),
							$file_count
						) );
					}
				}

		delete_transient( 'wgdp_permission_counts' );

		wp_send_json_success( array(
			'new_email'           => $new_email,
			'verification_status' => 'pending',
			'grant_status'        => 'pending',
		) );
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

	/**
	 * AJAX: Retry a failed Drive grant for an entitlement.
	 *
	 * If the entitlement's asset is stale (no longer in the product's current
	 * active resources), the old entitlement(s) are revoked and new ones are
	 * created using the current product files — effectively re-provisioning.
	 */
	public function ajax_retry_grant() {
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

		if ( 'error' !== $row['grant_status'] ) {
			wp_send_json_error( 'Only error-state entitlements can be retried.' );
		}

		if ( 'verified' !== $row['verification_status'] ) {
			wp_send_json_error( 'Entitlement is not yet verified.' );
		}

		$order_item_id = (int) $row['order_item_id'];

		$result = $ent->with_order_item_lock( $order_item_id, function () use ( $ent, $entitlement_id, $order_item_id ) {
			// Re-read inside the lock — another request may have retried this entitlement already.
			$row = $ent->get( $entitlement_id );
			if ( ! $row ) {
				return new WP_Error( 'wgdp_entitlement_not_found', 'Entitlement not found.' );
			}
			if ( 'error' !== $row['grant_status'] ) {
				return new WP_Error( 'wgdp_not_error_state', 'Only error-state entitlements can be retried.' );
			}
			if ( 'verified' !== $row['verification_status'] ) {
				return new WP_Error( 'wgdp_not_verified', 'Entitlement is not yet verified.' );
			}

			$product_id   = (int) $row['product_id'];
			$variation_id = (int) $row['variation_id'];

			// Get current product resources to detect stale assets.
			$current_resources = WGDP_Product_Meta::get_active_drive_resources( $product_id, $variation_id ?: 0 );
			$current_asset_ids = wp_list_pluck( $current_resources, 'id' );

			// Collect all sibling error entitlements (same order_item + email).
			$siblings = $ent->get_siblings( $order_item_id, $row['recipient_email'] );
			$to_retry = array();
			foreach ( $siblings as $s ) {
				if ( 'error' === $s['grant_status'] && 'verified' === $s['verification_status'] ) {
					$to_retry[] = $s;
				}
			}
			if ( empty( $to_retry ) ) {
				$to_retry = array( $row );
			}

			// Check if any entitlement references an asset not in the current product config.
			$has_stale = false;
			foreach ( $to_retry as $r ) {
				if ( ! in_array( $r['cloud_asset_id'], $current_asset_ids, true ) ) {
					$has_stale = true;
					break;
				}
			}

			// Stale asset detected — re-provision with current product files.
			if ( $has_stale ) {
				return $this->retry_grant_reprovision( $row, $to_retry, $current_resources );
			}

			// Normal retry — same assets, just re-attempt the Drive API call.
			$granted_count = 0;
			$last_error    = '';

			foreach ( $to_retry as $r ) {
				$grant_result = WGDP_Claim_Page::grant_drive_access_for_entitlement( $r, true );
				if ( is_wp_error( $grant_result ) ) {
					$ent->mark_error( $r['id'], $grant_result->get_error_message() );
					$last_error = $grant_result->get_error_message();
				} elseif ( null !== $grant_result ) {
					$granted_count++;
				}
			}

			delete_transient( 'wgdp_permission_counts' );

			if ( $granted_count > 0 ) {
				$this->send_retry_grant_emails( $row, $to_retry );

				$order = wc_get_order( $row['order_id'] );
				if ( $order ) {
					$order->add_order_note( sprintf(
						'WGDP: Manual retry successful — granted Drive access to %s (%d file(s))',
						$row['recipient_email'],
						$granted_count
					) );
				}

				WGDP_Order_Handler::instance()->maybe_auto_complete_order( $row['order_id'] );

				if ( $last_error ) {
					return array(
						'status'  => 'partial',
						'message' => sprintf( '%d file(s) granted, but some failed: %s', $granted_count, $last_error ),
					);
				}

				return array(
					'status'  => 'granted',
					'message' => sprintf( '%d file(s) granted successfully.', $granted_count ),
				);
			}

			return new WP_Error( 'wgdp_retry_failed', 'Retry failed: ' . $last_error );
		} );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Re-provision entitlements when the product's Drive resources have changed.
	 *
	 * Revokes old error entitlements, creates new ones for the current product files,
	 * and grants Drive access immediately (already verified).
	 */
	private function retry_grant_reprovision( $primary_row, $stale_rows, $current_resources ) {
		$ent = WGDP_Entitlements::instance();

		if ( empty( $current_resources ) ) {
			return new WP_Error( 'wgdp_no_resources', 'Product has no active Drive resources configured. Please update the product first.' );
		}

		$account_id = WGDP_Product_Meta::get_account_for_item( (int) $primary_row['product_id'], (int) $primary_row['variation_id'] );
		if ( empty( $account_id ) || ! WGDP_Google_Auth::instance()->is_account_connected( $account_id ) ) {
			return new WP_Error( 'wgdp_no_account', 'No connected Google account for this product.' );
		}

		// Revoke the stale error entitlements.
		foreach ( $stale_rows as $r ) {
			$ent->mark_revoked( $r['id'], WGDP_Entitlements::REVOCATION_REASON_REPROVISION );
		}

		// Create new entitlements for current resources, pre-verified.
		$granted_count = 0;
		$last_error    = '';
		$new_ids       = array();

		foreach ( $current_resources as $res ) {
			// Skip if a non-revoked entitlement already exists for this email + asset.
			$existing = $ent->get_existing_entitlement( $primary_row['order_item_id'], $res['id'], $primary_row['recipient_email'] );
			if ( $existing && 'revoked' !== $existing['grant_status'] ) {
				continue;
			}

			// Create or reactivate.
			if ( $existing && 'revoked' === $existing['grant_status'] ) {
				$new_id = (int) $existing['id'];
				$ent->update( $new_id, array(
					'verification_status'    => 'verified',
					'grant_status'           => 'pending',
					'provider_permission_id' => null,
					'granted_at'             => null,
					'revoked_at'             => null,
					'revocation_reason'      => null,
					'revocation_error'       => null,
					'revocation_retries'     => 0,
					'grant_error'            => null,
					'grant_retries'          => 0,
					'account_id'             => $account_id,
				) );
			} else {
				$new_id = $ent->create( array(
					'order_id'            => (int) $primary_row['order_id'],
					'order_item_id'       => (int) $primary_row['order_item_id'],
					'product_id'          => (int) $primary_row['product_id'],
					'variation_id'        => (int) $primary_row['variation_id'],
					'cloud_asset_id'      => $res['id'],
					'account_id'          => $account_id,
					'recipient_email'     => $primary_row['recipient_email'],
					'recipient_index'     => (int) $primary_row['recipient_index'],
					'verification_status' => 'verified',
					'grant_status'        => 'pending',
					'origin'              => 'admin_reprovision',
				) );
			}

			if ( ! $new_id ) {
				continue;
			}

			$new_ids[] = $new_id;

			// Grant immediately. $new_row can be false if the row vanished between the
			// write above and this re-read; skip it rather than passing false into a
			// function that dereferences $entitlement['id'].
			$new_row = $ent->get( $new_id );
			if ( ! $new_row ) {
				continue;
			}
			$result  = WGDP_Claim_Page::grant_drive_access_for_entitlement( $new_row, true );
			if ( is_wp_error( $result ) ) {
				$ent->mark_error( $new_id, $result->get_error_message() );
				$last_error = $result->get_error_message();
			} elseif ( null !== $result ) {
				$granted_count++;
			}
		}

		delete_transient( 'wgdp_permission_counts' );

		if ( $granted_count > 0 ) {
			// Build granted links for email.
			$granted_links = array();
			foreach ( $new_ids as $nid ) {
				$refreshed = $ent->get( $nid );
				if ( $refreshed && 'granted' === $refreshed['grant_status'] ) {
					$resource_type = WGDP_Entitlements::get_resource_type( $refreshed );
					$mime          = 'folder' === $resource_type ? 'application/vnd.google-apps.folder' : '';
					$res_name      = $refreshed['cloud_asset_id'];
					foreach ( $current_resources as $cr ) {
						if ( $cr['id'] === $refreshed['cloud_asset_id'] ) {
							$res_name = $cr['name'] ?: $cr['id'];
							break;
						}
					}
					$granted_links[] = array(
						'name' => $res_name,
						'link' => WGDP_Google_Drive::build_web_link( $refreshed['cloud_asset_id'], $mime ),
					);
				}
			}

			$product_name = WGDP_Entitlements::get_product_name( $primary_row, 'your purchase' );
			if ( count( $granted_links ) > 1 ) {
				WGDP_Notification_Email::send_access_granted_batch( $primary_row['recipient_email'], $granted_links, $product_name );
			} elseif ( count( $granted_links ) === 1 ) {
				WGDP_Notification_Email::send_access_granted( $primary_row['recipient_email'], $granted_links[0]['link'], $product_name );
			}

			$order = wc_get_order( $primary_row['order_id'] );
			if ( $order ) {
				$order->add_order_note( sprintf(
					'WGDP: Re-provisioned %s with updated product files — %d file(s) granted (admin retry)',
					$primary_row['recipient_email'],
					$granted_count
				) );
			}

			WGDP_Order_Handler::instance()->maybe_auto_complete_order( $primary_row['order_id'] );
		}

		if ( $granted_count > 0 && $last_error ) {
			return array(
				'status'  => 'partial',
				'message' => sprintf( 'Product files changed — re-provisioned %d file(s), but some failed: %s', $granted_count, $last_error ),
			);
		}

		if ( $granted_count > 0 ) {
			return array(
				'status'  => 'granted',
				'message' => sprintf( 'Product files changed — re-provisioned and granted %d file(s).', $granted_count ),
			);
		}

		return new WP_Error( 'wgdp_reprovision_failed', 'Re-provisioning failed: ' . ( $last_error ?: 'No files could be granted.' ) );
	}

	/**
	 * AJAX: Re-send the access-granted email to a verified+granted recipient.
	 */
	public function ajax_send_access_email() {
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

		if ( 'verified' !== $row['verification_status'] || 'granted' !== $row['grant_status'] ) {
			wp_send_json_error( 'Entitlement must be verified and granted to send access email.' );
		}

		// Gather all granted siblings (same order_item + email) to include all files.
		$siblings      = $ent->get_siblings( $row['order_item_id'], $row['recipient_email'] );
		$resources     = WGDP_Product_Meta::get_drive_resources( $row['product_id'], $row['variation_id'] ?: 0 );
		$res_map       = array();
		foreach ( $resources as $r ) {
			$res_map[ $r['id'] ] = $r['name'] ?: $r['id'];
		}

		$granted_links = array();
		foreach ( $siblings as $s ) {
			if ( 'granted' !== $s['grant_status'] ) {
				continue;
			}
			$resource_type = WGDP_Entitlements::get_resource_type( $s );
			$mime          = 'folder' === $resource_type ? 'application/vnd.google-apps.folder' : '';
			$granted_links[] = array(
				'name' => $res_map[ $s['cloud_asset_id'] ] ?? $s['cloud_asset_id'],
				'link' => WGDP_Google_Drive::build_web_link( $s['cloud_asset_id'], $mime ),
			);
		}

		if ( empty( $granted_links ) ) {
			wp_send_json_error( 'No granted files found for this recipient.' );
		}

			$product_name = WGDP_Entitlements::get_product_name( $row, 'your purchase' );
			if ( count( $granted_links ) > 1 ) {
				$mail_result = WGDP_Notification_Email::send_access_granted_batch( $row['recipient_email'], $granted_links, $product_name );
			} else {
				$mail_result = WGDP_Notification_Email::send_access_granted( $row['recipient_email'], $granted_links[0]['link'], $product_name );
			}

			if ( is_wp_error( $mail_result ) ) {
				wp_send_json_error( 'Access email failed: ' . $mail_result->get_error_message() );
			}

			wp_send_json_success( array(
			'message' => sprintf( 'Access email sent to %s.', $row['recipient_email'] ),
		) );
	}

	/**
	 * AJAX: Resend the WooCommerce order email (customer invoice) to the purchaser.
	 */
	public function ajax_resend_order_email() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( ! $order_id ) {
			wp_send_json_error( 'Missing order ID.' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();

		if ( ! isset( $emails['WC_Email_Customer_Invoice'] ) ) {
			wp_send_json_error( 'Customer invoice email not available.' );
		}

		$order->update_meta_data( '_wgdp_self_service_link_resent_at', time() );
		$order->save();

		$emails['WC_Email_Customer_Invoice']->trigger( $order_id );

		wp_send_json_success( array(
			'message' => sprintf( 'Order email resent for order #%d.', $order_id ),
		) );
	}

	/**
	 * AJAX: Remove the current recipient's Drive access and request a replacement Google email.
	 */
	public function ajax_request_new_email() {
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

		if ( 'revoked' === $row['grant_status'] ) {
			wp_send_json_error( 'This entitlement is already revoked. Use Resend Order Email if the order still has an unassigned slot.' );
		}

		$order = wc_get_order( $row['order_id'] );
		if ( ! $order ) {
			wp_send_json_error( 'Order not found.' );
		}

		if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) {
			wp_send_json_error( 'This order is not eligible for self-service reassignment.' );
		}

		$all_rows = $ent->get_siblings( $row['order_item_id'], $row['recipient_email'] );
		if ( empty( $all_rows ) ) {
			$all_rows = array( $row );
		}

		$drive_warning = '';
		$revoked_count = 0;

		foreach ( $all_rows as $sibling ) {
			if ( 'revoked' === $sibling['grant_status'] ) {
				continue;
			}

			$result = $ent->revoke_with_drive_delete( $sibling, WGDP_Entitlements::REVOCATION_REASON_REASSIGNMENT );
			if ( is_wp_error( $result ) ) {
				$drive_warning = $drive_warning ?: $result->get_error_message();
				continue;
			}
			$revoked_count++;
		}

		delete_transient( 'wgdp_permission_counts' );

		if ( $drive_warning ) {
			$order->add_order_note( sprintf(
				'WGDP: Could not fully remove access for %s; %d file(s) removed, at least one Drive permission is pending retry.',
				$row['recipient_email'],
				$revoked_count
			) );

			wp_send_json_success( array(
				'status'        => 'revocation_error',
				'message'       => 'Could not remove one Drive permission (' . $drive_warning . '). The order item is not ready for reassignment until revocation retry succeeds or the permission is removed manually.',
				'revoked_count' => $revoked_count,
			) );
		}

		$order->add_order_note( sprintf(
			'WGDP: Removed access for %s (%d file(s)); order item is awaiting replacement Google email assignment.',
			$row['recipient_email'],
			$revoked_count
		) );

		wp_send_json_success( array(
			'status'        => 'removed',
			'message'       => sprintf( 'Removed access for %s. Reloading will show this order item as Awaiting Assignment; use Resend Order Email to send the Provide Google Email link.', $row['recipient_email'] ),
			'revoked_count' => $revoked_count,
		) );
	}

	private function send_retry_grant_emails( $primary_row, $retried_rows ) {
		$ent       = WGDP_Entitlements::instance();
		$resources = WGDP_Product_Meta::get_drive_resources( $primary_row['product_id'], $primary_row['variation_id'] ?: 0 );
		$res_map   = array();
		foreach ( $resources as $r ) {
			$res_map[ $r['id'] ] = $r['name'] ?: $r['id'];
		}

		$granted_links = array();
		foreach ( $retried_rows as $r ) {
			$refreshed = $ent->get( $r['id'] );
			if ( $refreshed && 'granted' === $refreshed['grant_status'] ) {
				$resource_type = WGDP_Entitlements::get_resource_type( $refreshed );
				$mime          = 'folder' === $resource_type ? 'application/vnd.google-apps.folder' : '';
				$granted_links[] = array(
					'name' => $res_map[ $refreshed['cloud_asset_id'] ] ?? $refreshed['cloud_asset_id'],
					'link' => WGDP_Google_Drive::build_web_link( $refreshed['cloud_asset_id'], $mime ),
				);
			}
		}

		if ( empty( $granted_links ) ) {
			return;
		}

		$product_name = WGDP_Entitlements::get_product_name( $primary_row, 'your purchase' );
		if ( count( $granted_links ) > 1 ) {
			WGDP_Notification_Email::send_access_granted_batch( $primary_row['recipient_email'], $granted_links, $product_name );
		} else {
			WGDP_Notification_Email::send_access_granted( $primary_row['recipient_email'], $granted_links[0]['link'], $product_name );
		}
	}

}
