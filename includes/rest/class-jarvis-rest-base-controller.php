<?php
/**
 * Base REST controller.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Common helpers for REST controllers.
 */
abstract class Jarvis_REST_Base_Controller extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'jarvis/v1';

	/**
	 * Auth and rate-limit a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $capability Required capability.
	 * @return true|WP_Error
	 */
	protected function authorize_request( WP_REST_Request $request, $capability = 'edit_posts' ) {
		if ( ! current_user_can( $capability ) ) {
			// For external agents, fall back to token-based auth.
			if ( class_exists( 'Jarvis_Auth_Service' ) ) {
				$auth = new Jarvis_Auth_Service();
				$ok   = $auth->validate_request( $request );
				if ( ! $ok ) {
					return new WP_Error(
						'jarvis_unauthorized',
						__( 'Unauthorized request.', 'jarvis-content-engine' ),
						array( 'status' => rest_authorization_required_code() )
					);
				}
			} else {
				return new WP_Error(
					'jarvis_forbidden',
					__( 'Insufficient permissions.', 'jarvis-content-engine' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		// Simple per-IP rate limit.
		$ip        = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$transient = 'jarvis_rl_' . md5( $ip . $this->get_rate_limit_key( $request ) );
		$count     = (int) get_transient( $transient );

		$max_per_minute = apply_filters( 'jarvis_content_engine_rate_limit', 30, $request );

		if ( $count >= $max_per_minute ) {
			return new WP_Error(
				'jarvis_rate_limited',
				__( 'Too many requests. Please slow down.', 'jarvis-content-engine' ),
				array( 'status' => 429 )
			);
		}

		if ( 0 === $count ) {
			set_transient( $transient, 1, MINUTE_IN_SECONDS );
		} else {
			set_transient( $transient, $count + 1, MINUTE_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Get rate limit key for request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	protected function get_rate_limit_key( WP_REST_Request $request ) {
		return $request->get_route();
	}
}

