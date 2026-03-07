<?php
defined( 'ABSPATH' ) || exit;

class WGDP_OTP {

	private static $instance = null;

	const OTP_EXPIRY_MINUTES       = 15;
	const CLAIM_TOKEN_EXPIRY_HOURS = 24;
	const MAX_OTP_ATTEMPTS         = 5;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Generate a 6-digit OTP.
	 */
	public function generate_otp() {
		return str_pad( random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Hash an OTP using WordPress password hashing (salted).
	 */
	public function hash_otp( $otp ) {
		return wp_hash_password( $otp );
	}

	/**
	 * Verify an OTP against a hash.
	 */
	public function verify_otp( $otp, $hash ) {
		return wp_check_password( $otp, $hash );
	}

	/**
	 * Generate a base64url-encoded claim token.
	 */
	public function generate_claim_token() {
		$bytes = random_bytes( 32 );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Hash a claim token with SHA-256 (deterministic, for DB lookup).
	 */
	public function hash_claim_token( $token ) {
		return hash( 'sha256', $token );
	}

	/**
	 * Issue a new OTP + claim token for an entitlement.
	 * Overwrites any existing OTP/token. Resets attempts.
	 *
	 * @return array ['otp' => string, 'claim_token' => string] (plaintext)
	 */
	public function issue_otp_for_entitlement( $entitlement_id ) {
		$otp         = $this->generate_otp();
		$claim_token = $this->generate_claim_token();

		$entitlements = WGDP_Entitlements::instance();
		$entitlements->update( $entitlement_id, array(
			'otp_hash'               => $this->hash_otp( $otp ),
			'otp_expires_at'         => gmdate( 'Y-m-d H:i:s', time() + ( self::OTP_EXPIRY_MINUTES * 60 ) ),
			'otp_attempts'           => 0,
			'claim_token_hash'       => $this->hash_claim_token( $claim_token ),
			'claim_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( self::CLAIM_TOKEN_EXPIRY_HOURS * 3600 ) ),
			'verification_status'    => 'pending',
		) );

		return array(
			'otp'         => $otp,
			'claim_token' => $claim_token,
		);
	}

	/**
	 * Attempt to verify a claim token + OTP.
	 *
	 * @return array ['success' => bool, 'error' => string|null, 'entitlement' => array|null]
	 */
	public function attempt_verification( $claim_token, $otp_input ) {
		$entitlements = WGDP_Entitlements::instance();
		$token_hash   = $this->hash_claim_token( $claim_token );

		$entitlement = $entitlements->get_by_claim_token_hash( $token_hash );
		if ( ! $entitlement ) {
			return array( 'success' => false, 'error' => 'Invalid or expired link.', 'entitlement' => null );
		}

		// Check if already verified.
		if ( 'verified' === $entitlement['verification_status'] ) {
			return array( 'success' => false, 'error' => 'This access has already been verified.', 'entitlement' => $entitlement );
		}

		// Check if entitlement was revoked/cancelled.
		if ( 'revoked' === $entitlement['grant_status'] ) {
			return array( 'success' => false, 'error' => 'This access has been revoked.', 'entitlement' => $entitlement );
		}

		// Check claim token expiry.
		if ( ! empty( $entitlement['claim_token_expires_at'] ) && strtotime( $entitlement['claim_token_expires_at'] . ' +0000' ) < time() ) {
			return array( 'success' => false, 'error' => 'This link has expired. Please contact the store for a new verification email.', 'entitlement' => $entitlement );
		}

		// Check OTP expiry.
		if ( ! empty( $entitlement['otp_expires_at'] ) && strtotime( $entitlement['otp_expires_at'] . ' +0000' ) < time() ) {
			return array( 'success' => false, 'error' => 'Your verification code has expired. Please contact the store for a new code.', 'entitlement' => $entitlement );
		}

		// Atomically increment attempts only if under the limit (prevents TOCTOU race).
		global $wpdb;
		$table = WGDP_DB::get_table_name();
		$rows_affected = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$table} SET otp_attempts = otp_attempts + 1 WHERE id = %d AND otp_attempts < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$entitlement['id'],
				self::MAX_OTP_ATTEMPTS
			)
		);

		if ( 0 === $rows_affected ) {
			return array( 'success' => false, 'error' => 'Too many attempts. Please contact the store for a new verification code.', 'entitlement' => $entitlement );
		}

		// Verify OTP.
		if ( empty( $entitlement['otp_hash'] ) || ! $this->verify_otp( $otp_input, $entitlement['otp_hash'] ) ) {
			$remaining = self::MAX_OTP_ATTEMPTS - (int) $entitlement['otp_attempts'] - 1;
			$msg = 'Invalid verification code.';
			if ( $remaining > 0 ) {
				$msg .= sprintf( ' %d attempt(s) remaining.', $remaining );
			}
			return array( 'success' => false, 'error' => $msg, 'entitlement' => $entitlement );
		}

		// Mark as verified.
		$entitlements->update( $entitlement['id'], array(
			'verification_status' => 'verified',
		) );

		// Also verify all sibling entitlements (same order_item_id + recipient_email).
		$siblings = $entitlements->get_siblings( $entitlement['order_item_id'], $entitlement['recipient_email'], $entitlement['id'] );
		foreach ( $siblings as $sibling ) {
			if ( 'pending' === $sibling['verification_status'] ) {
				$entitlements->update( $sibling['id'], array(
					'verification_status' => 'verified',
				) );
			}
		}

		// Refresh the entitlement data.
		$entitlement = $entitlements->get( $entitlement['id'] );

		return array(
			'success'     => true,
			'error'       => null,
			'entitlement' => $entitlement,
			'siblings'    => $siblings,
		);
	}
}
