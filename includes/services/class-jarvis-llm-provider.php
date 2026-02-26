<?php
/**
 * OpenAI-compatible LLM provider for direct generation (Mode B).
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls an OpenAI-compatible Chat Completions endpoint and normalizes the response.
 */
class Jarvis_Llm_Provider {

	/**
	 * Redact secret values for logs/metadata.
	 *
	 * @param string $value Raw secret.
	 * @return string
	 */
	private function redact_secret( $value ) {
		if ( empty( $value ) || ! is_string( $value ) ) {
			return '';
		}

		$len = strlen( $value );
		if ( $len <= 4 ) {
			return str_repeat( '*', $len );
		}

		return substr( $value, 0, 2 ) . str_repeat( '*', max( 4, $len - 4 ) );
	}

	/**
	 * Sanitize error text from upstream.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function sanitize_error_text( $text ) {
		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\\s+/', ' ', $text );

		return mb_substr( $text, 0, 400 );
	}

	/**
	 * Build safe debug metadata for diagnostics.
	 *
	 * @param array  $settings Provider-related settings.
	 * @param string $endpoint Endpoint URL.
	 * @param int    $attempt Attempt number.
	 * @param int    $http_status HTTP status code.
	 * @param string $raw_body Raw response body.
	 * @return array
	 */
	private function make_debug_meta( array $settings, $endpoint, $attempt, $http_status = 0, $raw_body = '' ) {
		return array(
			'provider_enabled' => ! empty( $settings['provider_enabled'] ),
			'api_base'         => isset( $settings['provider_api_base'] ) ? $settings['provider_api_base'] : '',
			'endpoint'         => $endpoint,
			'model'            => isset( $settings['provider_model'] ) ? $settings['provider_model'] : '',
			'timeout'          => isset( $settings['provider_timeout'] ) ? (int) $settings['provider_timeout'] : 0,
			'max_tokens'       => isset( $settings['provider_max_tokens'] ) ? (int) $settings['provider_max_tokens'] : 0,
			'temperature'      => isset( $settings['provider_temperature'] ) ? (float) $settings['provider_temperature'] : 0,
			'attempt'          => (int) $attempt,
			'http_status'      => (int) $http_status,
			'response_snippet' => $this->sanitize_error_text( $raw_body ),
			'key_present'      => ! empty( $settings['provider_api_key'] ),
		);
	}

	/**
	 * Generate content payload.
	 *
	 * @param mixed $current Existing generation (from other filters).
	 * @param array $context Generation context (subject, keywords, etc.).
	 * @return array|WP_Error
	 */
	public function generate( $current, array $context ) {
		$settings = Jarvis_Settings::get_settings();

		// If another provider already generated content, respect it.
		if ( ! empty( $current ) ) {
			return $current;
		}

		$api_base = rtrim( $settings['provider_api_base'], '/' );
		$endpoint = $api_base . '/chat/completions';

		$model       = $settings['provider_model'];
		$timeout     = (int) $settings['provider_timeout'];
		$max_tokens  = (int) $settings['provider_max_tokens'];
		$temperature = (float) $settings['provider_temperature'];

		if ( empty( $settings['provider_enabled'] ) ) {
			$meta = $this->make_debug_meta( $settings, $endpoint, 0 );
			$error = new WP_Error(
				'jarvis_provider_disabled',
				__( 'LLM provider is disabled.', 'jarvis-content-engine' ),
				$meta
			);

			do_action(
				'jarvis_content_engine_debug_log',
				array(
					'component'    => 'llm_provider',
					'event'        => 'provider_disabled',
					'error_code'   => $error->get_error_code(),
					'error_message'=> $error->get_error_message(),
					'meta'         => $meta,
				)
			);

			return $error;
		}

		if ( empty( $settings['provider_api_key'] ) ) {
			$meta = $this->make_debug_meta( $settings, $endpoint, 0 );
			$error = new WP_Error(
				'jarvis_provider_not_configured',
				__( 'LLM provider API key is not configured.', 'jarvis-content-engine' ),
				$meta
			);

			do_action(
				'jarvis_content_engine_debug_log',
				array(
					'component'    => 'llm_provider',
					'event'        => 'provider_not_configured',
					'error_code'   => $error->get_error_code(),
					'error_message'=> $error->get_error_message(),
					'meta'         => $meta,
				)
			);

			return $error;
		}

		$subject  = isset( $context['subject'] ) ? $context['subject'] : '';
		$keywords = isset( $context['keywords'] ) ? (array) $context['keywords'] : array();
		$audi     = isset( $context['audience'] ) ? $context['audience'] : '';
		$intent   = isset( $context['intent'] ) ? $context['intent'] : '';
		$tone     = isset( $context['tone'] ) ? $context['tone'] : 'professional';
		$voice    = isset( $context['voice'] ) ? $context['voice'] : 'third_person';
		$range    = isset( $context['word_range'] ) ? (array) $context['word_range'] : array();

		$word_min = isset( $range['min'] ) ? (int) $range['min'] : 800;
		$word_max = isset( $range['max'] ) ? (int) $range['max'] : 2000;

		$system_prompt = 'You are a senior SEO copywriter and WordPress content strategist. '
			. 'Write accurate, trustworthy, long-form content optimized for discoverability, not virality.';

		$user_prompt = wp_json_encode(
			array(
				'subject'          => $subject,
				'keywords'         => $keywords,
				'audience'         => $audi,
				'intent'           => $intent,
				'tone'             => $tone,
				'voice'            => $voice,
				'word_count_range' => array(
					'min' => $word_min,
					'max' => $word_max,
				),
				'output_contract'  => array(
					'title'            => 'string',
					'title_options'    => 'string[]',
					'content'          => 'html string with H2/H3 headings',
					'excerpt'          => 'short plain text summary',
					'faq'              => 'array of { q, a }',
					'cta'              => 'html string',
					'internal_links'   => 'array of { anchor, target_hint }',
					'external_links'   => 'array of { anchor, url }',
					'featured_image_url' => 'string (url or empty)',
					'featured_image_alt' => 'string',
					'og_image_url'     => 'string (url or empty)',
					'inline_images'    => 'array of { url, alt, caption?, placement_hint? }',
					'meta_title'       => 'string',
					'meta_description' => 'string',
					'og_title'         => 'string',
					'og_description'   => 'string',
					'schema_jsonld'    => 'Article + FAQPage JSON-LD',
				),
				'image_output_rules_mandatory' => array(
					'return_fields' => array( 'featured_image_url', 'featured_image_alt', 'og_image_url', 'inline_images' ),
					'hard_constraints' => array(
						'1) URLs must be HTTPS.',
						'2) Host must be one of: images.unsplash.com, cdn.pixabay.com, upload.wikimedia.org',
						'3) URL path must end with: .jpg, .jpeg, .png, or .webp',
						'4) No placeholder or example domains.',
						'5) No HTML pages or preview links.',
						'6) If no compliant image exists, return empty strings for featured_image_url and og_image_url, and empty array for inline_images.',
					),
					'validation_before_returning' => array(
						'If featured_image_url fails any rule, set it to "".',
						'If og_image_url fails any rule, set it to "".',
						'Remove any inline_images entries whose url fails rules.',
					),
				),
				'instructions'     => 'Return only valid JSON that conforms to this contract. '
					. 'Do not include markdown fences or commentary. Write helpful, accurate content. '
					. 'Use natural anchors for internal/external links. '
					. 'IMAGE OUTPUT RULES (MANDATORY): Return featured_image_url, featured_image_alt, og_image_url, inline_images. URLs must be HTTPS; host must be images.unsplash.com, cdn.pixabay.com, or upload.wikimedia.org; path must end with .jpg, .jpeg, .png, or .webp. No placeholder domains or HTML/preview links. If no compliant image exists, return empty strings and empty array. If featured_image_url or og_image_url fails any rule set to ""; remove invalid inline_images entries.',
			)
		);

		$body = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => 'Generate a long-form article according to this JSON contract and context: ' . $user_prompt,
				),
			),
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $settings['provider_api_key'],
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		);

		$attempts = 3;
		$last_err = null;

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$response = wp_remote_post( $endpoint, $args );

			if ( is_wp_error( $response ) ) {
				$meta = $this->make_debug_meta( $settings, $endpoint, $i );
				$last_err = new WP_Error(
					'jarvis_provider_http_error',
					$response->get_error_message(),
					$meta
				);
			} else {
				$code     = wp_remote_retrieve_response_code( $response );
				$body_raw = wp_remote_retrieve_body( $response );

				if ( $code >= 200 && $code < 300 ) {
					$data = json_decode( $body_raw, true );
					if ( ! is_array( $data ) || empty( $data['choices'][0]['message']['content'] ) ) {
						$meta = $this->make_debug_meta( $settings, $endpoint, $i, $code, $body_raw );
						$last_err = new WP_Error(
							'jarvis_provider_bad_payload',
							__( 'Provider returned an unexpected response shape.', 'jarvis-content-engine' ),
							$meta
						);
					} else {
						$content = $data['choices'][0]['message']['content'];

						// Strip markdown fences if present.
						$content_stripped = trim( $content );
						if ( preg_match( '/^```/m', $content_stripped ) ) {
							$content_stripped = preg_replace( '/^```[a-zA-Z0-9]*\\s*/', '', $content_stripped );
							$content_stripped = preg_replace( '/```$/', '', trim( $content_stripped ) );
						}

						$json = json_decode( $content_stripped, true );
						if ( ! is_array( $json ) || empty( $json['content'] ) ) {
							$meta = $this->make_debug_meta( $settings, $endpoint, $i, $code, $content_stripped );
							$last_err = new WP_Error(
								'jarvis_provider_invalid_json',
								__( 'Provider did not return valid JSON content.', 'jarvis-content-engine' ),
								$meta
							);
						} else {
							// Enforce image URL allowlist and optional fetchability; strip invalid URLs without failing generation.
							$sanitized = $this->sanitize_generation_images( $json, $settings );
							return $sanitized;
						}
					}
				} else {
					// Upstream error with redacted body.
					$error_body = '';
					if ( ! empty( $body_raw ) ) {
						$decoded = json_decode( $body_raw, true );
						if ( isset( $decoded['error']['message'] ) ) {
							$error_body = $decoded['error']['message'];
						} else {
							$error_body = $body_raw;
						}
					}

					$meta = $this->make_debug_meta( $settings, $endpoint, $i, $code, $error_body );

					$last_err = new WP_Error(
						'jarvis_provider_http_status_' . $code,
						__( 'LLM provider returned an error.', 'jarvis-content-engine' ),
						$meta
					);
				}
			}

			if ( $last_err instanceof WP_Error ) {
				do_action(
					'jarvis_content_engine_debug_log',
					array(
						'component'    => 'llm_provider',
						'event'        => 'attempt_failed',
						'error_code'   => $last_err->get_error_code(),
						'error_message'=> $last_err->get_error_message(),
						'meta'         => $last_err->get_error_data(),
					)
				);

				// Cache last provider error summary for debug endpoint.
				set_transient(
					'jarvis_content_engine_last_provider_error',
					array(
						'time'    => current_time( 'mysql' ),
						'code'    => $last_err->get_error_code(),
						'message' => $last_err->get_error_message(),
						'meta'    => $last_err->get_error_data(),
					),
					HOUR_IN_SECONDS
				);
			}

			// Backoff: 1s, 2s, 4s.
			if ( $i < $attempts ) {
				sleep( (int) pow( 2, $i - 1 ) );
			}
		}

		$fallback = $last_err ? $last_err : new WP_Error(
			'jarvis_provider_failed',
			__( 'LLM provider failed after multiple attempts.', 'jarvis-content-engine' ),
			$this->make_debug_meta( $settings, $endpoint, $attempts )
		);

		return $fallback;
	}

	/**
	 * Allowed image hosts (must match runtime allowlist). Filterable via jarvis_content_engine_allowed_image_hosts.
	 *
	 * @return string[]
	 */
	private function allowed_image_hosts() {
		$default = array(
			'images.unsplash.com',
			'cdn.pixabay.com',
			'upload.wikimedia.org',
		);
		$hosts = (array) apply_filters( 'jarvis_content_engine_allowed_image_hosts', $default );
		return array_values( array_map( 'strtolower', array_filter( $hosts, 'is_string' ) ) );
	}

	/**
	 * Allowed image file extensions (lowercase).
	 *
	 * @return string[]
	 */
	private function allowed_image_extensions() {
		return array( 'jpg', 'jpeg', 'png', 'webp' );
	}

	/**
	 * Check if URL is allowed: https, host in allowlist, path ends with allowed extension.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private function is_allowed_image_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || strtolower( $parsed['scheme'] ) !== 'https' ) {
			return false;
		}
		$host = isset( $parsed['host'] ) ? strtolower( trim( $parsed['host'] ) ) : '';
		if ( ! in_array( $host, $this->allowed_image_hosts(), true ) ) {
			return false;
		}
		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
		if ( empty( $ext ) || ! in_array( $ext, $this->allowed_image_extensions(), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Keep only inline image entries with valid URLs; sanitize text fields and placement_hint.
	 *
	 * @param array $inline_images Raw inline_images array from payload.
	 * @return array Normalized list; invalid entries removed.
	 */
	private function normalize_inline_images( $inline_images ) {
		if ( ! is_array( $inline_images ) ) {
			return array();
		}
		$allowed_hints = array( 'after_intro', 'after_h2_1', 'after_h2_2', 'end' );
		$out = array();
		foreach ( $inline_images as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$url = isset( $entry['url'] ) ? trim( (string) $entry['url'] ) : '';
			if ( empty( $url ) || ! $this->is_allowed_image_url( $url ) ) {
				continue;
			}
			$hint = isset( $entry['placement_hint'] ) ? sanitize_text_field( (string) $entry['placement_hint'] ) : 'end';
			if ( ! in_array( $hint, $allowed_hints, true ) ) {
				$hint = 'end';
			}
			$out[] = array(
				'url'            => $url,
				'alt'            => isset( $entry['alt'] ) ? sanitize_text_field( (string) $entry['alt'] ) : '',
				'caption'        => isset( $entry['caption'] ) ? sanitize_text_field( (string) $entry['caption'] ) : '',
				'placement_hint' => $hint,
			);
		}
		return $out;
	}

	/**
	 * Sanitize image URLs in generation payload: enforce allowlist, extensions, and optional fetchability.
	 * Ensures payload has featured_image_url, featured_image_alt, og_image_url, inline_images. Non-fatal.
	 *
	 * @param array $payload  Decoded generation JSON.
	 * @param array $settings Plugin settings (for verify_remote_image_exists).
	 * @return array Payload with validated/emptied image fields and optional _image_sanitizer_warnings.
	 */
	private function sanitize_generation_images( array $payload, array $settings = array() ) {
		$warnings = array();
		if ( empty( $settings ) ) {
			$settings = Jarvis_Settings::get_settings();
		}

		// Ensure image keys exist for output contract.
		if ( ! array_key_exists( 'featured_image_url', $payload ) ) {
			$payload['featured_image_url'] = '';
		}
		if ( ! array_key_exists( 'featured_image_alt', $payload ) ) {
			$payload['featured_image_alt'] = '';
		}
		if ( ! array_key_exists( 'og_image_url', $payload ) ) {
			$payload['og_image_url'] = '';
		}
		if ( ! array_key_exists( 'inline_images', $payload ) ) {
			$payload['inline_images'] = array();
		}

		$verify_fetchable = ! empty( $settings['verify_remote_image_exists'] );
		$image_service   = $verify_fetchable ? new Jarvis_Image_Service() : null;

		// Featured image URL: allowlist + optional fetchability.
		$url = trim( (string) $payload['featured_image_url'] );
		if ( $url !== '' ) {
			if ( ! $this->is_allowed_image_url( $url ) ) {
				$payload['featured_image_url'] = '';
				$warnings[] = 'featured_removed_disallowed_host';
			} elseif ( $image_service && ! $image_service->is_fetchable_image_url( $url ) ) {
				$payload['featured_image_url'] = '';
				$warnings[] = 'featured_not_fetchable';
			}
		}

		// OG image URL: allowlist + optional fetchability.
		$url = trim( (string) $payload['og_image_url'] );
		if ( $url !== '' ) {
			if ( ! $this->is_allowed_image_url( $url ) ) {
				$payload['og_image_url'] = '';
				$warnings[] = 'og_removed_invalid_extension';
			} elseif ( $image_service && ! $image_service->is_fetchable_image_url( $url ) ) {
				$payload['og_image_url'] = '';
				$warnings[] = 'og_not_fetchable';
			}
		}

		// Inline images: keep only allowlisted entries, then drop non-fetchable if verify on.
		$inline_raw = is_array( $payload['inline_images'] ) ? $payload['inline_images'] : array();
		$normalized = $this->normalize_inline_images( $inline_raw );
		if ( $image_service ) {
			$normalized = array_values( array_filter( $normalized, function ( $entry ) use ( $image_service ) {
				return ! empty( $entry['url'] ) && $image_service->is_fetchable_image_url( $entry['url'] );
			} ) );
		}
		$removed_inline = count( $inline_raw ) - count( $normalized );
		if ( $removed_inline > 0 ) {
			$warnings[] = 'inline_removed_count:' . $removed_inline;
		}
		$payload['inline_images'] = $normalized;

		if ( ! empty( $warnings ) ) {
			$payload['_image_sanitizer_warnings'] = $warnings;
		}

		return $payload;
	}
}

