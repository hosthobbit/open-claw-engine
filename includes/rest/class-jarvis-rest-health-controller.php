<?php
/**
 * Health REST controller.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple health and diagnostics endpoint.
 */
class Jarvis_REST_Health_Controller extends Jarvis_REST_Base_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'health';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/health',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_health' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/debug/provider',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_provider_debug' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);
	}

	/**
	 * Return plugin health data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_health( WP_REST_Request $request ) {
		$settings = Jarvis_Settings::get_settings();

		$data = array(
			'plugin'        => 'open-claw-engine',
			'version'       => JARVIS_CONTENT_ENGINE_VERSION,
			'mode'          => $settings['mode'],
			'auth_mode'     => $settings['auth_mode'],
			'cron_scheduled'=> (bool) wp_next_scheduled( Jarvis_Cron::HOOK_DAILY ),
			'time'          => current_time( 'mysql' ),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Return provider debug configuration (admin-only, secrets redacted).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_provider_debug( WP_REST_Request $request ) {
		$settings = Jarvis_Settings::get_settings();

		$last_error = get_transient( 'jarvis_content_engine_last_provider_error' );
		if ( ! is_array( $last_error ) ) {
			$last_error = null;
		}

		$data = array(
			'provider_enabled' => ! empty( $settings['provider_enabled'] ),
			'api_base'         => isset( $settings['provider_api_base'] ) ? $settings['provider_api_base'] : '',
			'endpoint'         => ( isset( $settings['provider_api_base'] ) ? rtrim( $settings['provider_api_base'], '/' ) : '' ) . '/chat/completions',
			'model'            => isset( $settings['provider_model'] ) ? $settings['provider_model'] : '',
			'key_present'      => ! empty( $settings['provider_api_key'] ),
			'last_error'       => $last_error,
		);

		return new WP_REST_Response( $data, 200 );
	}
}

