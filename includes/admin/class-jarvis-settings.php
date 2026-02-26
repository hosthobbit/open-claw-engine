<?php
/**
 * Settings storage and helpers.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin settings registration and retrieval.
 */
class Jarvis_Settings {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'jarvis_content_engine_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'jarvis_content_engine',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_default_settings(),
			)
		);
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = self::get_default_settings();
		$current  = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $current ) ) {
			$current = array();
		}

		return wp_parse_args( $current, $defaults );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			// Integration mode.
			'mode'                 => 'external', // external | direct.
			'mode_fallback'        => 'direct', // direct | none.

			// API endpoint config.
			'api_base_url'         => '',
			'api_key'              => '',
			'api_timeout'          => 30,

			// LLM provider (Mode B) config.
			'provider_enabled'     => false,
			'provider_api_base'    => 'https://api.openai.com/v1',
			'provider_api_key'     => '',
			'provider_model'       => 'openai-codex/gpt-5.3-codex',
			'provider_timeout'     => 30,
			'provider_max_tokens'  => 3000,
			'provider_temperature' => 0.4,

			// Auth modes.
			'auth_mode'            => 'application_password', // application_password | jwt | hmac.
			'hmac_secret'          => '',
			'hmac_rotation_days'   => 30,

			// Content defaults.
			'default_subject'      => '',
			'target_categories'    => array(),
			'target_tags'          => array(),
			'tone'                 => 'professional',
			'voice'                => 'third_person',
			'word_count_min'       => 1200,
			'word_count_max'       => 2500,
			'publish_cadence'      => 'daily', // daily | weekdays | custom.
			'daily_time'           => '03:00',

			// SEO defaults.
			'meta_title_template'  => '{title} | {site_name}',
			'meta_description_rule'=> 'truncate_155',
			'slug_strategy'        => 'kebab',
			'keyword_primary'      => '',
			'keyword_secondary'    => array(),

			// Link rules.
			'min_internal_links'   => 3,
			'max_internal_links'   => 8,
			'min_external_links'   => 2,
			'max_external_links'   => 4,
			'external_allowlist'   => array(),
			'external_blocklist'   => array(),

			// Image rules.
			'featured_required'    => true,
			'inline_image_count'   => 2,
			'image_style_preset'   => 'photorealistic',
			'alt_text_required'    => true,
			'use_featured_as_og_fallback' => true,
			'enable_inline_image_injection' => true,
			'verify_remote_image_exists' => true,

			// Quality thresholds.
			'readability_min'      => 60,
			'uniqueness_min'       => 0.8,
			'seo_score_min'        => 70,
			'draft_only'           => true,
			'auto_publish'         => false,

			// Uninstall behavior.
			'cleanup_on_uninstall' => false,
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = self::get_default_settings();
		$output   = array();

		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$output[ $key ] = $default;
				continue;
			}

			$value = $input[ $key ];

			switch ( $key ) {
				case 'mode':
					$output[ $key ] = in_array( $value, array( 'external', 'direct' ), true ) ? $value : $default;
					break;
				case 'mode_fallback':
					$output[ $key ] = in_array( $value, array( 'direct', 'none' ), true ) ? $value : $default;
					break;
				case 'api_base_url':
					$output[ $key ] = esc_url_raw( $value );
					break;
				case 'api_key':
					// If value is empty or mask-only, keep existing.
					$existing_val   = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
					if ( '' === $value || ( is_string( $value ) && preg_match( '/^\*+$/', $value ) ) ) {
						$output[ $key ] = $existing_val;
					} else {
						$output[ $key ] = is_string( $value ) ? trim( $value ) : '';
					}
					break;
				case 'auth_mode':
					$output[ $key ] = in_array( $value, array( 'application_password', 'jwt', 'hmac' ), true ) ? $value : $default;
					break;
				case 'provider_enabled':
					$output[ $key ] = (bool) $value;
					break;
				case 'provider_api_base':
					$output[ $key ] = esc_url_raw( $value );
					break;
				case 'provider_api_key':
					$existing_val   = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
					if ( '' === $value || ( is_string( $value ) && preg_match( '/^\*+$/', $value ) ) ) {
						$output[ $key ] = $existing_val;
					} else {
						$output[ $key ] = is_string( $value ) ? trim( $value ) : '';
					}
					break;
				case 'provider_model':
					$output[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $default;
					break;
				case 'provider_timeout':
				case 'provider_max_tokens':
					$output[ $key ] = max( 1, (int) $value );
					break;
				case 'provider_temperature':
					$output[ $key ] = max( 0, min( 1, (float) $value ) );
					break;
				case 'hmac_secret':
					$existing_val   = isset( $existing[ $key ] ) ? $existing[ $key ] : '';
					if ( '' === $value || ( is_string( $value ) && preg_match( '/^\*+$/', $value ) ) ) {
						$output[ $key ] = $existing_val;
					} else {
						$output[ $key ] = is_string( $value ) ? trim( $value ) : '';
					}
					break;
				case 'hmac_rotation_days':
				case 'word_count_min':
				case 'word_count_max':
				case 'min_internal_links':
				case 'max_internal_links':
				case 'min_external_links':
				case 'max_external_links':
				case 'inline_image_count':
				case 'readability_min':
				case 'seo_score_min':
					$output[ $key ] = max( 0, (int) $value );
					break;
				case 'uniqueness_min':
					$output[ $key ] = max( 0, min( 1, (float) $value ) );
					break;
				case 'featured_required':
				case 'alt_text_required':
				case 'use_featured_as_og_fallback':
				case 'enable_inline_image_injection':
				case 'verify_remote_image_exists':
				case 'draft_only':
				case 'auto_publish':
				case 'cleanup_on_uninstall':
					$output[ $key ] = (bool) $value;
					break;
				case 'target_categories':
				case 'target_tags':
				case 'keyword_secondary':
				case 'external_allowlist':
				case 'external_blocklist':
					$output[ $key ] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ) ) );
					break;
				case 'daily_time':
					// Basic HH:MM validation.
					if ( preg_match( '/^\d{2}:\d{2}$/', $value ) ) {
						$output[ $key ] = $value;
					} else {
						$output[ $key ] = $default;
					}
					break;
				default:
					if ( is_string( $value ) ) {
						$output[ $key ] = sanitize_text_field( $value );
					} else {
						$output[ $key ] = $default;
					}
					break;
			}
		}

		return $output;
	}
}
