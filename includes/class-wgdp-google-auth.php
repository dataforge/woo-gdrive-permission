<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Google_Auth {

	private static $instance = null;

	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const SCOPE          = 'https://www.googleapis.com/auth/drive.file email';
	const CIPHER         = 'aes-256-cbc';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_wgdp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wgdp_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_wgdp_update_account_label', array( $this, 'ajax_update_account_label' ) );
		add_action( 'wp_ajax_wgdp_get_picker_token', array( $this, 'ajax_get_picker_token' ) );
	}

	/**
	 * Credential management is intentionally narrower than day-to-day Woo actions.
	 */
	public static function current_user_can_manage_credentials() {
		return current_user_can( 'manage_options' ) || current_user_can( 'manage_wgdp_settings' );
	}

	/**
	 * Check if at least one account is connected.
	 */
	public function has_accounts() {
		$accounts = $this->get_all_accounts();
		return ! empty( $accounts );
	}

	/**
	 * Check if a specific account is connected.
	 */
	public function is_account_connected( $account_id ) {
		$accounts = $this->get_all_accounts();
		if ( ! isset( $accounts[ $account_id ] ) ) {
			return false;
		}
		return ! empty( $accounts[ $account_id ]['refresh_token'] );
	}

	/**
	 * Get a valid access token for a specific account, auto-refreshing if expired.
	 */
	public function get_access_token( $account_id ) {
		$cache_key = 'wgdp_access_token_' . $account_id;
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}

		$accounts = $this->get_all_accounts();
		if ( ! isset( $accounts[ $account_id ] ) ) {
			return new WP_Error( 'wgdp_not_connected', 'Google account not connected.' );
		}

		$account = $accounts[ $account_id ];

		if ( empty( $account['refresh_token'] ) ) {
			return new WP_Error( 'wgdp_not_connected', 'Google account not connected.' );
		}

		// If we have an access token that hasn't expired, use it.
		if ( ! empty( $account['access_token'] ) && ! empty( $account['expires_at'] ) && $account['expires_at'] > time() + 60 ) {
			set_transient( $cache_key, $account['access_token'], $account['expires_at'] - time() - 60 );
			return $account['access_token'];
		}

		return $this->refresh_access_token( $account_id );
	}

	/**
	 * Force a genuine token refresh with Google, bypassing the locally cached
	 * expires_at bookkeeping. Used when a token that still looks unexpired
	 * locally has already been rejected by Google (e.g. a 401 response),
	 * since get_access_token() would otherwise just re-serve the same dead
	 * token until expires_at naturally elapses.
	 */
	public function force_refresh_access_token( $account_id ) {
		delete_transient( 'wgdp_access_token_' . $account_id );
		return $this->refresh_access_token( $account_id );
	}

	/**
	 * Refresh the access token for a specific account.
	 */
	private function refresh_access_token( $account_id ) {
		$accounts = $this->get_all_accounts();
		if ( ! isset( $accounts[ $account_id ] ) || empty( $accounts[ $account_id ]['refresh_token'] ) ) {
			return new WP_Error( 'wgdp_not_connected', 'No refresh token available.' );
		}

		$client_id     = get_option( 'wgdp_oauth_client_id', '' );
		$client_secret = $this->get_client_secret();

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'wgdp_no_credentials', 'OAuth client credentials not configured.' );
		}

		// HTTP refresh outside the lock.
		$account  = $accounts[ $account_id ];
		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $account['refresh_token'],
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( $code !== 200 || empty( $body['access_token'] ) ) {
			$error_msg = $body['error_description'] ?? ( $body['error'] ?? 'Unknown error refreshing token.' );

			// Flag the account for an admin notice when the refresh token is permanently dead.
			$error_lower = strtolower( $error_msg );
			if ( false !== strpos( $error_lower, 'expired' ) || false !== strpos( $error_lower, 'revoked' ) ) {
				$email = $accounts[ $account_id ]['email'] ?? $account_id;
				set_transient( 'wgdp_token_error_' . $account_id, $email . ': ' . $error_msg, DAY_IN_SECONDS );
			}

			return new WP_Error( 'wgdp_token_error', $error_msg );
		}

		// Inside lock: update tokens, save.
		$lock_result = $this->with_accounts_lock( function ( $accounts ) use ( $account_id, $body ) {
			if ( ! isset( $accounts[ $account_id ] ) ) {
				return null;
			}
			$accounts[ $account_id ]['access_token'] = $body['access_token'];
			$accounts[ $account_id ]['expires_at']   = time() + ( $body['expires_in'] ?? 3600 );
			if ( ! empty( $body['refresh_token'] ) ) {
				$accounts[ $account_id ]['refresh_token'] = $body['refresh_token'];
			}
			return $accounts;
		} );

		if ( is_wp_error( $lock_result ) ) {
			return $lock_result;
		}

		if ( null === $lock_result ) {
			// Account was disconnected/deleted while the refresh was in flight —
			// don't resurrect a token cache entry for an account we no longer track.
			return new WP_Error( 'wgdp_not_connected', 'Google account not connected.' );
		}

		// Cache for the shorter of 55 min or the token's actual lifetime (minus a
		// 60s safety margin) so short-lived tokens are never served stale.
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		$cache_ttl  = min( 55 * MINUTE_IN_SECONDS, $expires_in - 60 );
		if ( $cache_ttl > 0 ) {
			set_transient( 'wgdp_access_token_' . $account_id, $body['access_token'], $cache_ttl );
		}
		return $body['access_token'];
	}

	/**
	 * Build the Google OAuth authorization URL.
	 */
	public function get_auth_url() {
		$client_id = get_option( 'wgdp_oauth_client_id', '' );
		if ( empty( $client_id ) ) {
			return '';
		}

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'wgdp_oauth_state' ),
		);

		return self::AUTH_ENDPOINT . '?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for tokens — creates a new account entry.
	 */
	public function handle_callback( $code ) {
		$client_id     = get_option( 'wgdp_oauth_client_id', '' );
		$encrypted_raw = get_option( 'wgdp_oauth_client_secret', '' );
		$client_secret = $this->get_client_secret();

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			$detail = '';
			if ( empty( $client_id ) ) {
				$detail .= ' Client ID is empty.';
			}
			if ( empty( $encrypted_raw ) ) {
				$detail .= ' Client Secret was never saved.';
			} elseif ( empty( $client_secret ) ) {
				$detail .= ' Client Secret decryption failed — please re-enter and save your secret.';
			}
			return new WP_Error( 'wgdp_no_credentials', 'OAuth client credentials not configured.' . $detail );
		}

		$redirect_uri = $this->get_redirect_uri();

		// HTTP exchange outside the lock.
		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'body'    => array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body      = json_decode( wp_remote_retrieve_body( $response ), true );
		$code_resp = wp_remote_retrieve_response_code( $response );

		if ( $code_resp !== 200 || empty( $body['access_token'] ) ) {
			$error_msg = $body['error_description'] ?? ( $body['error'] ?? 'Unknown error exchanging code.' );
			error_log( sprintf(
				'WGDP OAuth token exchange failed: HTTP %d — %s (redirect_uri: %s, client_id: %s…)',
				$code_resp,
				$error_msg,
				$redirect_uri,
				substr( $client_id, 0, 12 )
			) );
			return new WP_Error( 'wgdp_token_error', $error_msg );
		}

		// Google only returns a refresh_token on the first consent grant. If consent
		// was cached (no refresh_token returned), we cannot persist a usable account —
		// tied entitlements would silently never grant. Force the user to re-authorize.
		if ( empty( $body['refresh_token'] ) ) {
			return new WP_Error(
				'wgdp_no_refresh_token',
				'Google did not return a refresh token, so this account cannot be used for automated access. This usually happens when the account was already authorized. Please remove the app\'s access at https://myaccount.google.com/permissions and then connect again.'
			);
		}

		$user_email = $this->fetch_user_email( $body['access_token'] );

		// Inside lock: generate collision-safe ID, add account, save.
		$account_id = null;
		$result = $this->with_accounts_lock( function ( $accounts ) use ( $body, $user_email, &$account_id ) {
			do {
				$account_id = wp_generate_password( 8, false );
			} while ( isset( $accounts[ $account_id ] ) );

			$accounts[ $account_id ] = array(
				'type'          => 'google_drive',
				'label'         => $user_email ? $user_email : 'Google Account',
				'email'         => $user_email,
				'refresh_token' => $body['refresh_token'] ?? '',
				'access_token'  => $body['access_token'],
				'expires_at'    => time() + ( $body['expires_in'] ?? 3600 ),
			);

			return $accounts;
		} );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Cache for the shorter of 55 min or the token's actual lifetime (minus a
		// 60s safety margin) so a short-lived token is never served stale, matching
		// refresh_access_token().
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		$cache_ttl  = min( 55 * MINUTE_IN_SECONDS, $expires_in - 60 );
		if ( $cache_ttl > 0 ) {
			set_transient( 'wgdp_access_token_' . $account_id, $body['access_token'], $cache_ttl );
		}

		return $account_id;
	}

	/**
	 * Disconnect a specific account.
	 */
	public function disconnect( $account_id ) {
		$this->with_accounts_lock( function ( $accounts ) use ( $account_id ) {
			if ( isset( $accounts[ $account_id ] ) ) {
				unset( $accounts[ $account_id ] );
				return $accounts;
			}
			return null;
		} );
		delete_transient( 'wgdp_access_token_' . $account_id );
		delete_transient( 'wgdp_token_error_' . $account_id );
	}

	/**
	 * Get the email of a specific account.
	 */
	public function get_account_email( $account_id ) {
		$accounts = $this->get_all_accounts();
		return $accounts[ $account_id ]['email'] ?? '';
	}

	/**
	 * Test the connection for a specific account.
	 */
	public function test_connection( $account_id ) {
		$token = $this->get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get( 'https://www.googleapis.com/drive/v3/files?' . http_build_query( array(
			'pageSize' => 1,
			'fields'   => 'files(id,name)',
		) ), array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = $body['error']['message'] ?? 'HTTP ' . $code;
			return new WP_Error( 'wgdp_drive_error', 'Drive API error: ' . $msg );
		}

		return true;
	}

	/**
	 * Clear all per-account transient access token caches.
	 */
	public function clear_token_cache() {
		$accounts = $this->get_all_accounts();
		foreach ( array_keys( $accounts ) as $account_id ) {
			delete_transient( 'wgdp_access_token_' . $account_id );
		}
	}

	/**
	 * Get all accounts for UI display (without sensitive token data).
	 */
	public function get_accounts() {
		$accounts = $this->get_all_accounts();
		$safe     = array();
		foreach ( $accounts as $id => $account ) {
			$safe[ $id ] = array(
				'type'  => $account['type'] ?? 'google_drive',
				'label' => $account['label'] ?? '',
				'email' => $account['email'] ?? '',
			);
		}
		return $safe;
	}

	/**
	 * Update the display label for an account.
	 */
	public function update_account_label( $account_id, $label ) {
		$result = $this->with_accounts_lock( function ( $accounts ) use ( $account_id, $label ) {
			if ( ! isset( $accounts[ $account_id ] ) ) {
				return null;
			}
			$accounts[ $account_id ]['label'] = $label;
			return $accounts;
		} );
		return ! is_wp_error( $result ) && null !== $result;
	}

	/**
	 * AJAX: Return the access token for a given account (for Google Picker).
	 */
	public function ajax_get_picker_token() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! self::current_user_can_manage_credentials() ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
		if ( empty( $account_id ) ) {
			wp_send_json_error( 'No account specified.' );
		}

		$accounts = $this->get_accounts();
		if ( ! isset( $accounts[ $account_id ] ) ) {
			wp_send_json_error( 'Account not found.' );
		}

		$token = $this->get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			wp_send_json_error( $token->get_error_message() );
		}

		wp_send_json_success( array( 'token' => $token ) );
	}

	/**
	 * AJAX: Test connection for a specific account.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! self::current_user_can_manage_credentials() ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
		if ( empty( $account_id ) ) {
			wp_send_json_error( 'No account specified.' );
		}

		delete_transient( 'wgdp_access_token_' . $account_id );
		$result = $this->test_connection( $account_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$email = $this->get_account_email( $account_id );
		wp_send_json_success( 'Connected! Google account: ' . $email );
	}

	/**
	 * AJAX: Disconnect a specific account.
	 */
	public function ajax_disconnect() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! self::current_user_can_manage_credentials() ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
		if ( empty( $account_id ) ) {
			wp_send_json_error( 'No account specified.' );
		}

		$this->disconnect( $account_id );
		wp_send_json_success( 'Disconnected.' );
	}

	/**
	 * AJAX: Update account label.
	 */
	public function ajax_update_account_label() {
		check_ajax_referer( 'wgdp_admin_nonce', 'nonce' );

		if ( ! self::current_user_can_manage_credentials() ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$account_id = isset( $_POST['account_id'] ) ? sanitize_text_field( wp_unslash( $_POST['account_id'] ) ) : '';
		$label      = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		if ( empty( $account_id ) ) {
			wp_send_json_error( 'No account specified.' );
		}

		if ( $this->update_account_label( $account_id, $label ) ) {
			wp_send_json_success( 'Label updated.' );
		} else {
			wp_send_json_error( 'Account not found.' );
		}
	}

	/**
	 * Get the OAuth redirect URI.
	 */
	public function get_redirect_uri() {
		return admin_url( 'admin.php?page=wgdp&tab=settings' );
	}

	/**
	 * Fetch the Google account email using an access token.
	 */
	private function fetch_user_email( $access_token ) {
		$response = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['email'] ?? '';
	}

	/**
	 * Execute a callback within a MySQL named lock for safe read-modify-write.
	 *
	 * The callback receives the current accounts array. If it returns an array,
	 * that array is saved as the new accounts data. If it returns null, the
	 * save is skipped. Use &$ref variables to extract extra data from the callback.
	 *
	 * @param callable $callback Receives $accounts, returns $accounts array or null.
	 * @return array|null|WP_Error Callback return value, or WP_Error on lock failure.
	 */
	private function with_accounts_lock( $callback ) {
		if ( ! $this->acquire_lock() ) {
			return new WP_Error( 'wgdp_lock_failed', 'Could not acquire accounts lock.' );
		}

		try {
			$accounts = $this->get_all_accounts();
			$result   = $callback( $accounts );

			if ( null !== $result ) {
				$this->save_all_accounts( $result );
			}

			return $result;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Acquire a MySQL named lock.
	 */
	private function acquire_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->get_var( "SELECT GET_LOCK('wgdp_accounts', 5)" );
		return '1' === (string) $result;
	}

	/**
	 * Release the MySQL named lock.
	 */
	private function release_lock() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->get_var( "SELECT RELEASE_LOCK('wgdp_accounts')" );
	}

	/**
	 * Get all accounts (decrypted, with tokens). Private.
	 */
	private function get_all_accounts() {
		$encrypted = get_option( 'wgdp_accounts', '' );
		if ( empty( $encrypted ) ) {
			return array();
		}

		$decrypted = $this->decrypt( $encrypted );
		if ( empty( $decrypted ) ) {
			return array();
		}

		$accounts = json_decode( $decrypted, true );
		return is_array( $accounts ) ? $accounts : array();
	}

	/**
	 * Read and decrypt the OAuth client secret.
	 *
	 * @return string Decrypted client secret, or '' when unset/undecryptable.
	 */
	public function get_client_secret() {
		return $this->decrypt( get_option( 'wgdp_oauth_client_secret', '' ) );
	}

	/**
	 * Save all accounts (encrypted). Private.
	 */
	private function save_all_accounts( $accounts ) {
		$encrypted = $this->encrypt( wp_json_encode( $accounts ) );
		update_option( 'wgdp_accounts', $encrypted, false );
	}

	/**
	 * Encrypt a value using AES-256-CBC.
	 */
	public function encrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$key = $this->get_encryption_key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$encrypted = sodium_crypto_secretbox( $value, $nonce, $key );
			return 'v2s::' . base64_encode( $nonce . $encrypted );
		}

		if ( in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv        = random_bytes( 12 );
			$tag       = '';
			$encrypted = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $encrypted ) {
				return 'v2g::' . base64_encode( $iv . $tag . $encrypted );
			}
		}

		// Authenticated CBC fallback (encrypt-then-MAC) for hosts lacking both
		// libsodium and AES-GCM. The HMAC closes the defense-in-depth gap where
		// the legacy unauthenticated CBC format could be tampered with undetected.
		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$encrypted = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return '';
		}

		$mac_key = hash_hmac( 'sha256', 'wgdp-cbc-mac', $key, true );
		$hmac    = hash_hmac( 'sha256', $iv . $encrypted, $mac_key, true );

		return 'v1c::' . base64_encode( $iv . $hmac . $encrypted );
	}

	/**
	 * Decrypt a value using AES-256-CBC.
	 */
	public function decrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$key = $this->get_encryption_key();

		if ( 0 === strpos( $value, 'v2s::' ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $value, 5 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$decrypted  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
			return ( false === $decrypted ) ? '' : $decrypted;
		}

		if ( 0 === strpos( $value, 'v2g::' ) ) {
			$raw = base64_decode( substr( $value, 5 ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv         = substr( $raw, 0, 12 );
			$tag        = substr( $raw, 12, 16 );
			$ciphertext = substr( $raw, 28 );
			$decrypted  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return ( false === $decrypted ) ? '' : $decrypted;
		}

		if ( 0 === strpos( $value, 'v1c::' ) ) {
			$raw    = base64_decode( substr( $value, 5 ), true );
			$iv_len = openssl_cipher_iv_length( self::CIPHER );
			if ( false === $raw || strlen( $raw ) <= $iv_len + 32 ) {
				return '';
			}
			$iv         = substr( $raw, 0, $iv_len );
			$hmac       = substr( $raw, $iv_len, 32 );
			$ciphertext = substr( $raw, $iv_len + 32 );
			$mac_key    = hash_hmac( 'sha256', 'wgdp-cbc-mac', $key, true );
			$expected   = hash_hmac( 'sha256', $iv . $ciphertext, $mac_key, true );
			if ( ! hash_equals( $expected, $hmac ) ) {
				return '';
			}
			$decrypted = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
			return ( false === $decrypted ) ? '' : $decrypted;
		}

		// Unknown/unrecognized format (e.g. the legacy unauthenticated
		// base64(iv)::ciphertext scheme, dropped once all stored values were
		// confirmed migrated to the authenticated v2s/v2g/v1c formats).
		return '';
	}

	/**
	 * Derive encryption key from WordPress AUTH_KEY.
	 */
	private function get_encryption_key() {
		if ( ! defined( 'AUTH_KEY' ) || '' === AUTH_KEY ) {
			error_log( 'WGDP: AUTH_KEY is not defined or empty; credential encryption is insecure.' );
		}
		$salt = defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', $salt, true );
	}
}
