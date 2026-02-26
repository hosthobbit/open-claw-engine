<?php
/**
 * Model discovery service: fetch available models from OpenAI-compatible API.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves and caches list of model identifiers from provider API.
 */
class Jarvis_Model_Discovery {

	const TRANSIENT_KEY = 'jarvis_content_engine_models_list';
	const TRANSIENT_TTL  = 900; // 15 minutes.

	/**
	 * Get list of model ids (from cache or API). Safe for admin display.
	 *
	 * @param string $api_base Optional. API base URL (uses settings if empty).
	 * @param string $api_key  Optional. API key (uses settings if empty); never logged.
	 * @return array List of sanitized model id strings.
	 */
	public static function get_models( $api_base = '', $api_key = '' ) {
		$settings = Jarvis_Settings::get_settings();
		if ( empty( $api_base ) ) {
			$api_base = isset( $settings['provider_api_base'] ) ? rtrim( $settings['provider_api_base'], '/' ) : '';
		}
		if ( empty( $api_key ) ) {
			$api_key = isset( $settings['provider_api_key'] ) ? $settings['provider_api_key'] : '';
		}

		$cache_key = self::TRANSIENT_KEY . '_' . md5( $api_base );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		if ( empty( $api_base ) || empty( $api_key ) ) {
			return self::get_fallback_models();
		}

		$models = self::fetch_models_from_api( $api_base, $api_key );
		if ( ! empty( $models ) ) {
			set_transient( $cache_key, $models, self::TRANSIENT_TTL );
			return $models;
		}

		// Use last cached or fallback.
		$cached = get_transient( $cache_key );
		return is_array( $cached ) ? $cached : self::get_fallback_models();
	}

	/**
	 * Force refresh: clear transient and optionally re-fetch (called after user clicks Refresh).
	 *
	 * @return array New list of models or fallback.
	 */
	public static function refresh_models() {
		$settings = Jarvis_Settings::get_settings();
		$api_base = isset( $settings['provider_api_base'] ) ? rtrim( $settings['provider_api_base'], '/' ) : '';
		$api_key  = isset( $settings['provider_api_key'] ) ? $settings['provider_api_key'] : '';

		$cache_key = self::TRANSIENT_KEY . '_' . md5( $api_base );
		delete_transient( $cache_key );

		return self::get_models( $api_base, $api_key );
	}

	/**
	 * Fetch models from GET {api_base}/models.
	 *
	 * @param string $api_base API base URL.
	 * @param string $api_key  API key (never logged).
	 * @return array Sanitized model ids.
	 */
	private static function fetch_models_from_api( $api_base, $api_key ) {
		$url = $api_base . '/models';
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$models = array();
		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $item ) {
				if ( isset( $item['id'] ) && is_string( $item['id'] ) ) {
					$id = sanitize_text_field( $item['id'] );
					if ( $id !== '' ) {
						$models[] = $id;
					}
				}
			}
		}

		$models = array_unique( $models );
		sort( $models );
		return array_values( $models );
	}

	/**
	 * Default fallback when API is not configured or request fails.
	 *
	 * @return string[]
	 */
	private static function get_fallback_models() {
		return array(
			'gpt-4o',
			'gpt-4o-mini',
			'gpt-4-turbo',
			'gpt-3.5-turbo',
		);
	}
}
