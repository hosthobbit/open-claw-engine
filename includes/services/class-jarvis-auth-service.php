<?php
/**
 * Auth service for REST and webhooks.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates HMAC-signed requests and integrates with JWT/application passwords.
 */
class Jarvis_Auth_Service {

	/**
	 * Validate a REST request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function validate_request( WP_REST_Request $request ) {
		$settings = Jarvis_Settings::get_settings();

		switch ( $settings['auth_mode'] ) {
			case 'hmac':
				return $this->validate_hmac( $request, $settings );
			case 'jwt':
				// Defer to JWT plugin if available; otherwise require normal auth.
				if ( is_user_logged_in() ) {
					return true;
				}
				return false;
			case 'application_password':
			default:
				// Application passwords and cookies are validated by WordPress core.
				return is_user_logged_in();
		}
	}

	/**
	 * Validate HMAC-signed request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param array           $settings Settings.
	 * @return bool
	 */
	protected function validate_hmac( WP_REST_Request $request, array $settings ) {
		$secret = isset( $settings['hmac_secret'] ) ? $settings['hmac_secret'] : '';

		if ( empty( $secret ) ) {
			return false;
		}

		$headers = $request->get_headers();
		$nonce   = isset( $headers['x-jarvis-nonce'][0] ) ? sanitize_text_field( $headers['x-jarvis-nonce'][0] ) : '';
		$ts      = isset( $headers['x-jarvis-timestamp'][0] ) ? (int) $headers['x-jarvis-timestamp'][0] : 0;
		$sig     = isset( $headers['x-jarvis-signature'][0] ) ? sanitize_text_field( $headers['x-jarvis-signature'][0] ) : '';

		if ( empty( $nonce ) || empty( $ts ) || empty( $sig ) ) {
			return false;
		}

		// 5 minute clock skew.
		if ( abs( time() - $ts ) > 300 ) {
			return false;
		}

		$body    = $request->get_body();
		$message = $nonce . '|' . $ts . '|' . $request->get_method() . '|' . $request->get_route() . '|' . $body;
		$hash    = hash_hmac( 'sha256', $message, $secret );

		return hash_equals( $hash, $sig );
	}
}

