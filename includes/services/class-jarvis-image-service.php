<?php
/**
 * Image generation and media handling.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles AI image prompts and media uploads.
 *
 * This service assumes an external agent or LLM endpoint returns image URLs
 * that are copyright-safe; the plugin focuses on downloading, storing, and
 * attaching them with appropriate alt text.
 */
class Jarvis_Image_Service {

	/**
	 * Allowed image mime types (no SVG by default for security).
	 *
	 * @var string[]
	 */
	const ALLOWED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );

	/**
	 * Allowed URL schemes for image import.
	 *
	 * @var string[]
	 */
	const ALLOWED_SCHEMES = array( 'http', 'https' );

	/**
	 * Default allowed image hosts (HTTPS, direct image files). Use filter to add more.
	 *
	 * @var string[]
	 */
	const DEFAULT_ALLOWED_IMAGE_HOSTS = array(
		'images.unsplash.com',
		'cdn.pixabay.com',
		'upload.wikimedia.org',
	);

	/**
	 * Allowed direct image file extensions for reliability (no SVG). Lowercase.
	 *
	 * @var string[]
	 */
	const ALLOWED_IMAGE_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp' );

	/**
	 * Download image to media library.
	 *
	 * Validates URL scheme (http/https), mime type (image/*, no SVG unless allowed),
	 * uses retry/backoff. Attaches to post when $post_id > 0.
	 *
	 * @param string $image_url Remote image URL.
	 * @param string $alt       Alt text.
	 * @param int    $post_id   Optional. Post ID to attach the attachment to. 0 = unattached.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function import_image( $image_url, $alt = '', $post_id = 0 ) {
		if ( empty( $image_url ) || ! is_string( $image_url ) ) {
			return new WP_Error(
				'jarvis_image_empty',
				__( 'Empty image URL.', 'jarvis-content-engine' ),
				$this->error_data_with_diagnostics( '', 'jarvis_image_empty', __( 'Empty image URL.', 'jarvis-content-engine' ) )
			);
		}

		$image_url = esc_url_raw( $image_url );
		if ( ! $image_url ) {
			return new WP_Error(
				'jarvis_image_invalid_url',
				__( 'Invalid image URL.', 'jarvis-content-engine' ),
				$this->error_data_with_diagnostics( $image_url, 'jarvis_image_invalid_url', __( 'Invalid image URL.', 'jarvis-content-engine' ) )
			);
		}

		$parsed = wp_parse_url( $image_url );
		if ( empty( $parsed['scheme'] ) || ! in_array( strtolower( $parsed['scheme'] ), self::ALLOWED_SCHEMES, true ) ) {
			return new WP_Error(
				'jarvis_image_invalid_scheme',
				__( 'Image URL must be http or https.', 'jarvis-content-engine' ),
				$this->error_data_with_diagnostics( $image_url, 'jarvis_image_invalid_scheme', __( 'Image URL must be http or https.', 'jarvis-content-engine' ) )
			);
		}

		// Image host reliability rules: HTTPS only, allowlisted host, direct image extension.
		$reliability = $this->validate_image_url_reliability( $image_url );
		if ( is_wp_error( $reliability ) ) {
			return $reliability;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attempts = 3;
		$last_err = null;

		for ( $i = 1; $i <= $attempts; $i++ ) {
			$temp = download_url( $image_url );

			if ( is_wp_error( $temp ) ) {
				$last_err = $this->attach_diagnostics_to_wp_error( $temp, $image_url );
			} else {
				$detected = wp_check_filetype( $temp, null );
				$mime     = $detected['type'];
				if ( empty( $mime ) && function_exists( 'mime_content_type' ) ) {
					$mime = mime_content_type( $temp );
				}
				if ( empty( $mime ) || ! $this->is_allowed_mime_type( $mime ) ) {
					@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$last_err = new WP_Error(
						'jarvis_image_invalid_mime',
						__( 'Invalid image type. Allowed: JPEG, PNG, WebP, GIF.', 'jarvis-content-engine' ),
						$this->error_data_with_diagnostics( $image_url, 'jarvis_image_invalid_mime', __( 'Invalid image type. Allowed: JPEG, PNG, WebP, GIF.', 'jarvis-content-engine' ) )
					);
				} else {
					$path     = wp_parse_url( $image_url, PHP_URL_PATH );
					$basename = $path ? basename( $path ) : '';
					if ( empty( $basename ) || strpos( $basename, '.' ) === false ) {
						$ext      = $this->extension_for_mime( $mime );
						$basename = 'jarvis-' . wp_rand( 1000, 9999 ) . '.' . $ext;
					}
					$file = array(
						'name'     => sanitize_file_name( $basename ),
						'type'     => $mime,
						'tmp_name' => $temp,
						'size'     => filesize( $temp ),
				);

					$attach_to = (int) $post_id;
					$id        = media_handle_sideload( $file, $attach_to > 0 ? $attach_to : 0 );

					@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

					if ( is_wp_error( $id ) ) {
						$last_err = $this->attach_diagnostics_to_wp_error( $id, $image_url );
					} else {
						if ( ! empty( $alt ) ) {
							update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
						}
						return $id;
					}
				}
			}

			if ( $i < $attempts ) {
				sleep( $i );
			}
		}

		if ( $last_err ) {
			return $this->attach_diagnostics_to_wp_error( $last_err, $image_url );
		}
		return new WP_Error(
			'jarvis_image_failed',
			__( 'Image download failed after multiple attempts.', 'jarvis-content-engine' ),
			$this->error_data_with_diagnostics( $image_url, 'jarvis_image_failed', __( 'Image download failed after multiple attempts.', 'jarvis-content-engine' ) )
		);
	}

	/**
	 * Get list of allowed image hosts (lowercase). Filterable.
	 *
	 * @return string[]
	 */
	public function get_allowed_image_hosts() {
		$hosts = array_map( 'strtolower', self::DEFAULT_ALLOWED_IMAGE_HOSTS );
		return array_values( array_unique( (array) apply_filters( 'jarvis_content_engine_allowed_image_hosts', $hosts ) ) );
	}

	/**
	 * Validate URL against image host reliability rules: HTTPS only, allowlisted host, direct .jpg/.jpeg/.png/.webp.
	 * When enforcement is disabled via filter, returns true without checking host/extension.
	 *
	 * @param string $image_url Image URL.
	 * @return true|WP_Error True if compliant or not enforced; WP_Error if rejected.
	 */
	public function validate_image_url_reliability( $image_url ) {
		$enforce = (bool) apply_filters( 'jarvis_content_engine_enforce_image_host_allowlist', true );
		if ( ! $enforce ) {
			return true;
		}

		$parsed = wp_parse_url( $image_url );
		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';
		if ( $scheme !== 'https' ) {
			return new WP_Error(
				'jarvis_image_not_https',
				__( 'Image URL must be HTTPS only for reliability.', 'jarvis-content-engine' ),
				$this->error_data_with_diagnostics( $image_url, 'jarvis_image_not_https', __( 'Image URL must be HTTPS only for reliability.', 'jarvis-content-engine' ) )
			);
		}

		$host = $this->get_url_host( $image_url );
		$allowed = $this->get_allowed_image_hosts();
		$allowed = array_map( 'strtolower', $allowed );
		if ( empty( $host ) || ! in_array( $host, $allowed, true ) ) {
			return new WP_Error(
				'jarvis_image_host_not_allowed',
				__( 'Image host is not on the allowed list. Use HTTPS URLs from trusted CDNs only.', 'jarvis-content-engine' ),
				$this->error_data_with_diagnostics( $image_url, 'jarvis_image_host_not_allowed', __( 'Image host is not on the allowed list.', 'jarvis-content-engine' ) )
			);
		}

		$path = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
		if ( empty( $ext ) || ! in_array( $ext, self::ALLOWED_IMAGE_EXTENSIONS, true ) ) {
			return new WP_Error(
				'jarvis_image_invalid_extension',
				__( 'Image URL must be a direct file ending in .jpg, .jpeg, .png, or .webp.', 'jarvis-content-engine' ),
				$this->error_data_with_diagnostics( $image_url, 'jarvis_image_invalid_extension', __( 'Image URL must be a direct file ending in .jpg, .jpeg, .png, or .webp.', 'jarvis-content-engine' ) )
			);
		}

		return true;
	}

	/**
	 * Check if mime type is allowed (no SVG unless filter allows).
	 *
	 * @param string $mime Mime type.
	 * @return bool
	 */
	protected function is_allowed_mime_type( $mime ) {
		if ( in_array( strtolower( $mime ), self::ALLOWED_MIME_TYPES, true ) ) {
			return true;
		}
		if ( 'image/svg+xml' === strtolower( $mime ) ) {
			return (bool) apply_filters( 'jarvis_content_engine_allow_svg_import', false );
		}
		return false;
	}

	/**
	 * Get file extension for mime type.
	 *
	 * @param string $mime Mime type.
	 * @return string
	 */
	protected function extension_for_mime( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : 'jpg';
	}

	/**
	 * Set featured image on post from URL.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $image_url Image URL.
	 * @param string $alt       Alt text.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function set_featured_image_from_url( $post_id, $image_url, $alt = '' ) {
		$attachment_id = $this->import_image( $image_url, $alt, (int) $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( (int) $post_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Set OG image from URL: import image, save plugin fallback meta, and set Yoast/Rank Math when active.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $image_url Image URL.
	 * @param string $alt       Alt text.
	 * @return int|WP_Error Attachment ID or error.
	 */
	public function set_og_image_from_url( $post_id, $image_url, $alt = '' ) {
		$post_id = (int) $post_id;
		$result  = $this->import_image( $image_url, $alt, $post_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$attachment_id = $result;
		$attachment_url = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $attachment_url ) {
			$attachment_url = $image_url;
		}

		// Plugin fallback meta (always stored).
		update_post_meta( $post_id, '_jarvis_og_image_id', $attachment_id );
		update_post_meta( $post_id, '_jarvis_og_image_url', $attachment_url );

		// Yoast SEO: set social/OG image when plugin is active.
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $attachment_url );
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', (string) $attachment_id );
		}

		// Rank Math: set Facebook/OG image when plugin is active.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', $attachment_url );
			update_post_meta( $post_id, 'rank_math_facebook_image_id', (string) $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Import multiple inline images for a post. Returns structured list with attachment_id, url, alt, caption, placement_hint.
	 * Invalid entries are skipped with per-item errors in the returned 'errors' list.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $inline_images  Array of objects with url (required), alt (optional), caption (optional), placement_hint (optional).
	 * @return array{ items: array, errors: array } items: list of { attachment_id, url, alt, caption, placement_hint }, errors: list of { index, message, source_meta, error_class }.
	 */
	public function import_inline_images( $post_id, array $inline_images ) {
		$post_id = (int) $post_id;
		$items   = array();
		$errors  = array();

		foreach ( $inline_images as $index => $entry ) {
			if ( ! is_array( $entry ) ) {
				$errors[] = array(
					'index'       => $index,
					'message'      => __( 'Invalid inline image entry.', 'jarvis-content-engine' ),
					'source_meta'  => $this->build_source_meta( '' ),
					'error_class'  => 'unknown',
				);
				continue;
			}

			$url = isset( $entry['url'] ) ? trim( (string) $entry['url'] ) : '';
			if ( empty( $url ) ) {
				$errors[] = array(
					'index'       => $index,
					'message'      => __( 'Missing image URL.', 'jarvis-content-engine' ),
					'source_meta'  => $this->build_source_meta( '' ),
					'error_class'  => 'unknown',
				);
				continue;
			}

			$alt     = isset( $entry['alt'] ) ? sanitize_text_field( (string) $entry['alt'] ) : '';
			$caption = isset( $entry['caption'] ) ? sanitize_text_field( (string) $entry['caption'] ) : '';
			$hint    = isset( $entry['placement_hint'] ) ? sanitize_text_field( (string) $entry['placement_hint'] ) : 'end';
			$allowed_hints = array( 'after_intro', 'after_h2_1', 'after_h2_2', 'end' );
			if ( ! in_array( $hint, $allowed_hints, true ) ) {
				$hint = 'end';
			}

			$attachment_id = $this->import_image( $url, $alt, $post_id );
			if ( is_wp_error( $attachment_id ) ) {
				$diag = $this->get_error_diagnostics( $attachment_id, $url );
				$errors[] = array(
					'index'       => $index,
					'message'      => $attachment_id->get_error_message(),
					'source_meta'  => $diag['source_meta'],
					'error_class'  => $diag['error_class'],
				);
				continue;
			}

			$item_url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( ! $item_url ) {
				$item_url = $url;
			}

			$items[] = array(
				'attachment_id'  => (int) $attachment_id,
				'url'           => $item_url,
				'alt'           => $alt,
				'caption'       => $caption,
				'placement_hint' => $hint,
			);
		}

		return array(
			'items'  => $items,
			'errors' => $errors,
		);
	}

	/**
	 * Redact URL for logging (keep scheme + domain, mask path).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function redact_url( $url ) {
		if ( empty( $url ) ) {
			return '';
		}
		$parsed = wp_parse_url( $url );
		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
		$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
		return $scheme . $host . '/***';
	}

	/**
	 * Parse host from URL safely. No path/query. Lowercase.
	 *
	 * @param string $url URL.
	 * @return string Host or empty string.
	 */
	private function get_url_host( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}
		$parsed = wp_parse_url( $url );
		$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
		return $host !== '' ? strtolower( $host ) : '';
	}

	/**
	 * Redact host: keep TLD + last label visible, mask subdomain.
	 * Examples: images.example.com -> ***.example.com, cdn.a.b.hosthobbit.com -> ***.hosthobbit.com, localhost -> localhost.
	 *
	 * @param string $host Hostname.
	 * @return string Redacted host; no path/query.
	 */
	private function redact_host( $host ) {
		if ( empty( $host ) || ! is_string( $host ) ) {
			return '';
		}
		$host = strtolower( trim( $host ) );
		$labels = array_filter( explode( '.', $host ) );
		if ( count( $labels ) <= 1 ) {
			return $host;
		}
		$last_two = array_slice( $labels, -2 );
		return '***.' . implode( '.', $last_two );
	}

	/**
	 * Build safe source fingerprint for a URL. Never includes full URL, path, or query.
	 *
	 * @param string $url Image URL.
	 * @return array{ scheme: string, host_redacted: string, is_https: bool, ext: string }
	 */
	private function build_source_meta( $url ) {
		$host = $this->get_url_host( $url );
		$scheme = 'other';
		if ( ! empty( $url ) && is_string( $url ) ) {
			$parsed = wp_parse_url( $url );
			if ( ! empty( $parsed['scheme'] ) ) {
				$s = strtolower( $parsed['scheme'] );
				$scheme = in_array( $s, array( 'http', 'https' ), true ) ? $s : 'other';
			}
		}
		$ext = '';
		if ( ! empty( $url ) && is_string( $url ) ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			if ( $path ) {
				$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
				$allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );
				if ( ! in_array( $ext, $allowed_ext, true ) ) {
					$ext = '';
				}
			}
		}
		return array(
			'scheme'         => $scheme,
			'host_redacted'  => $this->redact_host( $host ),
			'is_https'       => $scheme === 'https',
			'ext'            => $ext,
		);
	}

	/**
	 * Classify WP_Error into error_class for triage: ssl, timeout, dns, http, mime, sideload, unknown.
	 *
	 * @param WP_Error $err Error.
	 * @return string
	 */
	private function classify_error( WP_Error $err ) {
		$code    = $err->get_error_code();
		$message = $err->get_error_message();

		if ( $code === 'jarvis_image_invalid_mime' ) {
			return 'mime';
		}
		if ( in_array( $code, array( 'jarvis_image_not_https', 'jarvis_image_host_not_allowed', 'jarvis_image_invalid_extension' ), true ) ) {
			return 'invalid_host';
		}
		if ( in_array( $code, array( 'jarvis_image_empty', 'jarvis_image_invalid_url', 'jarvis_image_invalid_scheme' ), true ) ) {
			return 'http';
		}

		$msg_lower = strtolower( $message );
		if ( strpos( $msg_lower, 'curl error 60' ) !== false || strpos( $msg_lower, 'ssl certificate' ) !== false || strpos( $msg_lower, 'ssl_' ) !== false ) {
			return 'ssl';
		}
		if ( strpos( $msg_lower, 'timed out' ) !== false || strpos( $msg_lower, 'timeout' ) !== false || strpos( $msg_lower, 'operation timed out' ) !== false ) {
			return 'timeout';
		}
		if ( strpos( $msg_lower, 'could not resolve host' ) !== false || strpos( $msg_lower, 'name or service not known' ) !== false || strpos( $msg_lower, 'getaddrinfo' ) !== false ) {
			return 'dns';
		}
		if ( strpos( $msg_lower, 'sideload' ) !== false || strpos( $msg_lower, 'media_handle_sideload' ) !== false || $code === 'attachment' ) {
			return 'sideload';
		}
		if ( strpos( $msg_lower, 'http' ) !== false || strpos( $message, '40' ) !== false || strpos( $message, '50' ) !== false ) {
			return 'http';
		}

		return 'unknown';
	}

	/**
	 * Build error_data array with source_meta and error_class (no full URL).
	 *
	 * @param string $url     Image URL (for fingerprint only).
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array
	 */
	private function error_data_with_diagnostics( $url, $code, $message ) {
		$err = new WP_Error( $code, $message );
		return array(
			'source_meta'  => $this->build_source_meta( $url ),
			'error_class'  => $this->classify_error( $err ),
		);
	}

	/**
	 * Attach source_meta and error_class to an existing WP_Error (from download_url or media_handle_sideload).
	 *
	 * @param WP_Error $err       Existing error.
	 * @param string   $image_url Image URL (for fingerprint).
	 * @return WP_Error Same error with data set.
	 */
	private function attach_diagnostics_to_wp_error( WP_Error $err, $image_url ) {
		$data = $err->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['source_meta'] = $this->build_source_meta( $image_url );
		$data['error_class'] = $this->classify_error( $err );
		$err->add_data( $data );
		return $err;
	}

	/**
	 * Get safe diagnostics for response/logs from a WP_Error and optional URL.
	 * Use when building image_diagnostics.errors or logs_json entries. Never includes full URL.
	 *
	 * @param WP_Error $err       Error (may have source_meta/error_class in error_data).
	 * @param string  $image_url Optional. URL used if error_data has no source_meta.
	 * @return array{ source_meta: array, error_class: string }
	 */
	public function get_error_diagnostics( WP_Error $err, $image_url = '' ) {
		$data = $err->get_error_data();
		$source_meta = null;
		$error_class = 'unknown';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['source_meta'] ) && is_array( $data['source_meta'] ) ) {
				$source_meta = $data['source_meta'];
			}
			if ( ! empty( $data['error_class'] ) && is_string( $data['error_class'] ) ) {
				$error_class = $data['error_class'];
			}
		}
		if ( $source_meta === null ) {
			$source_meta = $this->build_source_meta( $image_url );
		}
		return array(
			'source_meta' => $source_meta,
			'error_class' => $error_class,
		);
	}

	/**
	 * Check if URL is fetchable: HTTP 200 and Content-Type image/*. No full URL logging.
	 * Tries HEAD first (timeout 10s), then GET with small range if needed.
	 *
	 * @param string $url Image URL.
	 * @return bool True if fetchable and image content-type.
	 */
	public function is_fetchable_image_url( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}
		$url = esc_url_raw( trim( $url ) );
		if ( ! $url ) {
			return false;
		}

		$timeout = 10;
		$args    = array(
			'timeout'    => $timeout,
			'sslverify'  => true,
			'redirection' => 2,
			'user-agent' => 'Open-Claw-Engine/1.0',
		);

		// Try HEAD first.
		$response = wp_remote_head( $url, $args );
		$code     = wp_remote_retrieve_response_code( $response );
		$ctype    = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_string( $ctype ) ) {
			$ctype = strtolower( trim( explode( ';', $ctype )[0] ) );
		} else {
			$ctype = '';
		}

		// If HEAD not supported or returned redirect, try GET with range.
		if ( is_wp_error( $response ) || $code === 405 || $code === 501 || ( $code >= 300 && $code < 400 ) ) {
			$args['method'] = 'GET';
			$args['headers'] = array( 'Range' => 'bytes=0-0' );
			$response = wp_remote_request( $url, $args );
			$code     = wp_remote_retrieve_response_code( $response );
			$ctype    = wp_remote_retrieve_header( $response, 'content-type' );
			if ( is_string( $ctype ) ) {
				$ctype = strtolower( trim( explode( ';', $ctype )[0] ) );
			} else {
				$ctype = '';
			}
		}

		if ( is_wp_error( $response ) || $code < 200 || $code >= 300 ) {
			return false;
		}
		if ( strpos( $ctype, 'image/' ) !== 0 ) {
			return false;
		}
		return true;
	}
}
