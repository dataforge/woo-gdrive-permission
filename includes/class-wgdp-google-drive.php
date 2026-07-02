<?php
defined( 'ABSPATH' ) || exit;

class WGDP_Google_Drive {

	private static $instance = null;

	const API_BASE = 'https://www.googleapis.com/drive/v3';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Allowed endpoint patterns.
	 *
	 * This plugin only reads file metadata and manages permissions.
	 * All other Drive operations (file create, update, copy, delete, etc.)
	 * are blocked to prevent accidental or malicious file modification.
	 */
	private const ALLOWED_ENDPOINTS = array(
		'#^/files\?#'                                      => array( 'GET' ),
		'#^/files/[^/]+\?#'                                 => array( 'GET' ),
		'#^/files/[^/]+/permissions\?#'                     => array( 'GET', 'POST' ),
		'#^/files/[^/]+/permissions/[^/]+\?#'               => array( 'GET', 'DELETE' ),
		'#^/files/[^/]+/permissions/[^/]+$#'                => array( 'GET', 'DELETE' ),
	);

	/**
	 * Make an authenticated request to the Drive API.
	 */
	private function request( $endpoint, $args = array(), $account_id = '' ) {
		$method = $args['method'] ?? 'GET';

		// Guard: only allow whitelisted endpoint + method combinations.
		$allowed = false;
		foreach ( self::ALLOWED_ENDPOINTS as $pattern => $methods ) {
			if ( preg_match( $pattern, $endpoint ) && in_array( $method, $methods, true ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			return new WP_Error(
				'wgdp_blocked_request',
				sprintf( 'Blocked Drive API request: %s %s — this plugin only manages permissions.', $method, $endpoint )
			);
		}

		$auth  = WGDP_Google_Auth::instance();
		$token = $auth->get_access_token( $account_id );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$defaults = array(
			'headers' => array(),
			'timeout' => 15,
		);
		$args = wp_parse_args( $args, $defaults );
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		$url = self::API_BASE . $endpoint;

		unset( $args['method'] );

		$response = $this->dispatch( $method, $url, $args );

		// On 401, clear cached token and retry once with a fresh token.
		if ( ! is_wp_error( $response ) && 401 === wp_remote_retrieve_response_code( $response ) ) {
			delete_transient( 'wgdp_access_token_' . $account_id );
			$token = $auth->get_access_token( $account_id );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$args['headers']['Authorization'] = 'Bearer ' . $token;
			$response = $this->dispatch( $method, $url, $args );
		}

		return $response;
	}

	/**
	 * Dispatch an HTTP request by method.
	 */
	private function dispatch( $method, $url, $args ) {
		if ( 'GET' === $method ) {
			return wp_remote_get( $url, $args );
		}
		if ( 'POST' === $method ) {
			return wp_remote_post( $url, $args );
		}
		return wp_remote_request( $url, array_merge( $args, array( 'method' => $method ) ) );
	}

	/**
	 * Get a single file's metadata.
	 */
	public function get_file( $file_id, $account_id = '' ) {
		$params = array(
			'fields'            => 'id,name,mimeType,webViewLink',
			'supportsAllDrives' => 'true',
		);

		$response = $this->request( '/files/' . urlencode( $file_id ) . '?' . http_build_query( $params ), array(), $account_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? 'HTTP ' . $code;
			return new WP_Error( 'wgdp_get_error', $msg, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * List accessible Drive files for the product editor browser.
	 */
	public function list_files( $account_id = '', $search = '', $page_token = '', $folder_id = 'root' ) {
		$folder_id = '' === trim( $folder_id ) ? 'root' : trim( $folder_id );
		$escaped_folder_id = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $folder_id );
		$q = "trashed = false and '" . $escaped_folder_id . "' in parents";

		if ( '' !== trim( $search ) ) {
			$escaped = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), trim( $search ) );
			$q      .= " and name contains '" . $escaped . "'";
		}

		$params = array(
			'fields'                    => 'nextPageToken,files(id,name,mimeType,webViewLink)',
			'pageSize'                  => 25,
			'orderBy'                   => 'folder,name_natural',
			'q'                         => $q,
			'supportsAllDrives'         => 'true',
			'includeItemsFromAllDrives' => 'true',
		);
		if ( '' !== $page_token ) {
			$params['pageToken'] = $page_token;
		}

		$response = $this->request( '/files?' . http_build_query( $params ), array(), $account_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? 'HTTP ' . $code;
			return new WP_Error( 'wgdp_list_error', $msg, array( 'status' => $code ) );
		}

		return array(
			'files'          => $body['files'] ?? array(),
			'nextPageToken'  => $body['nextPageToken'] ?? '',
		);
	}

	/**
	 * Create a permission (share) on a file/folder.
	 */
	public function create_permission( $file_id, $email, $send_notification = null, $account_id = '' ) {
		if ( null === $send_notification ) {
			$send_notification = get_option( 'wgdp_send_notification', 'no' ) === 'yes';
		}

		$params = array(
			'supportsAllDrives'     => 'true',
			'sendNotificationEmail' => $send_notification ? 'true' : 'false',
		);

		$response = $this->request( '/files/' . urlencode( $file_id ) . '/permissions?' . http_build_query( $params ), array(
			'method'  => 'POST',
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'type'         => 'user',
				'role'         => 'reader',
				'emailAddress' => $email,
			) ),
		), $account_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? 'HTTP ' . $code;
			return new WP_Error( 'wgdp_permission_error', $msg, array( 'status' => $code ) );
		}

		return $body;
	}

	/**
	 * Delete (revoke) a permission on a file/folder.
	 */
	public function delete_permission( $file_id, $permission_id, $account_id = '' ) {
		$params = array( 'supportsAllDrives' => 'true' );

		$response = $this->request(
			'/files/' . urlencode( $file_id ) . '/permissions/' . urlencode( $permission_id ) . '?' . http_build_query( $params ),
			array( 'method' => 'DELETE' ),
			$account_id
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code !== 204 && $code !== 200 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = $body['error']['message'] ?? 'HTTP ' . $code;
			return new WP_Error( 'wgdp_delete_perm_error', $msg, array( 'status' => $code ) );
		}

		return true;
	}

	/**
	 * Get a single permission on a file/folder.
	 */
	public function get_permission( $file_id, $permission_id, $account_id = '' ) {
		$params = array(
			'supportsAllDrives' => 'true',
			'fields'            => 'id,type,role,emailAddress',
		);

		$response = $this->request(
			'/files/' . urlencode( $file_id ) . '/permissions/' . urlencode( $permission_id ) . '?' . http_build_query( $params ),
			array(),
			$account_id
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 404 ) {
			return new WP_Error( 'wgdp_permission_not_found', 'Permission no longer exists on Google Drive.' );
		}

		if ( $code !== 200 ) {
			$msg = $body['error']['message'] ?? 'HTTP ' . $code;
			return new WP_Error( 'wgdp_get_perm_error', $msg );
		}

		return $body;
	}

	/**
	 * Check if a WP_Error represents a 404 file-not-found from the Drive API.
	 */
	public static function is_file_not_found( $wp_error ) {
		if ( ! is_wp_error( $wp_error ) ) {
			return false;
		}
		$data = $wp_error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'];
	}

	/**
	 * Extract a Drive file/folder ID from a URL or return the string as-is if it looks like an ID.
	 */
	public static function extract_id_from_url( $url_or_id ) {
		$url_or_id = trim( $url_or_id );

		if ( preg_match( '#/folders/([a-zA-Z0-9_-]+)#', $url_or_id, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#/file/d/([a-zA-Z0-9_-]+)#', $url_or_id, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#/d/([a-zA-Z0-9_-]+)#', $url_or_id, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $url_or_id, $m ) ) {
			return $m[1];
		}

		if ( preg_match( '#^[a-zA-Z0-9_-]{10,}$#', $url_or_id ) ) {
			return $url_or_id;
		}

		// Not a recognized URL and not a valid Drive ID — reject rather than
		// returning arbitrary input that would later flow into API paths / `q` queries.
		return '';
	}

	/**
	 * Check if a MIME type is a folder.
	 */
	public static function is_folder( $mime_type ) {
		return 'application/vnd.google-apps.folder' === $mime_type;
	}

	/**
	 * Build a web link for a Drive resource.
	 */
	public static function build_web_link( $file_id, $type = '' ) {
		if ( 'folder' === $type || self::is_folder( $type ) ) {
			return 'https://drive.google.com/drive/folders/' . $file_id;
		}
		return 'https://drive.google.com/file/d/' . $file_id . '/view';
	}
}
