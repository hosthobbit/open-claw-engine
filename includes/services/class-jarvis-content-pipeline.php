<?php
/**
 * Content generation pipeline.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates content jobs, scoring, and publishing.
 *
 * This class coordinates with an external agent (Mode A) or an LLM endpoint (Mode B)
 * via hooks/filters so that the heavy AI work happens outside of WordPress.
 */
class Jarvis_Content_Pipeline {

	/**
	 * Jobs table.
	 *
	 * @var Jarvis_Jobs_Table
	 */
	protected $jobs_table;

	/**
	 * SEO scoring service.
	 *
	 * @var Jarvis_SEO_Scoring_Service
	 */
	protected $scoring;

	/**
	 * Image service.
	 *
	 * @var Jarvis_Image_Service
	 */
	protected $images;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->jobs_table = new Jarvis_Jobs_Table();
		$this->scoring    = new Jarvis_SEO_Scoring_Service();
		$this->images     = new Jarvis_Image_Service();
	}

	/**
	 * Run all scheduled campaigns (called from cron).
	 */
	public function run_scheduled_campaigns() {
		$settings = Jarvis_Settings::get_settings();
		$subject  = $settings['default_subject'];

		if ( ! $subject ) {
			return;
		}

		$params = array(
			'subject'           => $subject,
			'primary_keyword'   => $settings['keyword_primary'],
			'secondary_keywords'=> $settings['keyword_secondary'],
			'audience'          => '',
			'intent'            => '',
		);

		$this->generate_once( $params, (bool) $settings['auto_publish'] );
	}

	/**
	 * Run retry queue (placeholder).
	 */
	public function run_retry_queue() {
		// In a full implementation, jobs with status "error" would be re-run here.
	}

	/**
	 * Run a specific job ID.
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	public function run_job( $job_id ) {
		$job = $this->jobs_table->get_job( $job_id );

		if ( ! $job ) {
			return array(
				'status_code' => 404,
				'error'       => 'not_found',
				'message'     => __( 'Job not found.', 'jarvis-content-engine' ),
			);
		}

		$params = array(
			'subject'           => $job->subject,
			'primary_keyword'   => '',
			'secondary_keywords'=> array(),
			'audience'          => '',
			'intent'            => '',
		);

		return $this->generate_once( $params, false, (int) $job_id );
	}

	/**
	 * Approve job for publishing (when draft-only + manual approve).
	 *
	 * @param int $job_id Job ID.
	 * @return array
	 */
	public function approve_job( $job_id ) {
		$job = $this->jobs_table->get_job( $job_id );
		if ( ! $job || empty( $job->post_id ) ) {
			return array(
				'status_code' => 404,
				'error'       => 'not_found',
				'message'     => __( 'Job or associated post not found.', 'jarvis-content-engine' ),
			);
		}

		$post = get_post( (int) $job->post_id );
		if ( ! $post ) {
			return array(
				'status_code' => 404,
				'error'       => 'post_not_found',
				'message'     => __( 'Associated post not found.', 'jarvis-content-engine' ),
			);
		}

		if ( 'publish' === $post->post_status ) {
			return array(
				'status_code' => 200,
				'message'     => __( 'Post already published.', 'jarvis-content-engine' ),
			);
		}

		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => 'publish',
			)
		);

		$this->jobs_table->update_job(
			$job_id,
			array(
				'status'       => 'published',
				'published_at' => current_time( 'mysql' ),
			)
		);

		return array(
			'status_code' => 200,
			'message'     => __( 'Post published.', 'jarvis-content-engine' ),
			'post_id'     => (int) $post->ID,
		);
	}

	/**
	 * One-shot generate and optionally publish.
	 *
	 * @param array   $params Generation params.
	 * @param boolean $publish Whether to publish immediately.
	 * @param int     $existing_job_id Optional job id.
	 * @return array
	 */
	public function generate_once( array $params, $publish = false, $existing_job_id = 0 ) {
		$settings = Jarvis_Settings::get_settings();

		$subject = isset( $params['subject'] ) ? $params['subject'] : '';
		if ( ! $subject ) {
			return array(
				'status_code' => 400,
				'error'       => 'invalid_subject',
				'message'     => __( 'Subject is required.', 'jarvis-content-engine' ),
			);
		}

		$keywords = array_filter(
			array_merge(
				array( isset( $params['primary_keyword'] ) ? $params['primary_keyword'] : $settings['keyword_primary'] ),
				isset( $params['secondary_keywords'] ) ? (array) $params['secondary_keywords'] : (array) $settings['keyword_secondary']
			)
		);

		// Let an external agent or direct LLM implementation hook into content generation.
		$generation = apply_filters(
			'jarvis_content_engine_generate',
			null,
			array(
				'subject'    => $subject,
				'keywords'   => $keywords,
				'audience'   => isset( $params['audience'] ) ? $params['audience'] : '',
				'intent'     => isset( $params['intent'] ) ? $params['intent'] : '',
				'tone'       => $settings['tone'],
				'voice'      => $settings['voice'],
				'word_range' => array(
					'min' => $settings['word_count_min'],
					'max' => $settings['word_count_max'],
				),
			)
		);

		$provider_error = null;

		if ( is_wp_error( $generation ) ) {
			$data = $generation->get_error_data();
			$provider_error = array(
				'code'    => $generation->get_error_code(),
				'message' => $generation->get_error_message(),
				'meta'    => is_array( $data ) ? $data : array(),
			);
		} elseif ( empty( $generation ) || ! is_array( $generation ) || empty( $generation['content'] ) ) {
			$provider_error = array(
				'code'    => 'invalid_generation_payload',
				'message' => __( 'Generation returned an invalid payload.', 'jarvis-content-engine' ),
				'meta'    => array(),
			);
		}

		if ( null !== $provider_error ) {
			// Append to existing job logs if we have a job.
			if ( $existing_job_id ) {
				$job = $this->jobs_table->get_job( $existing_job_id );
				$logs = array();

				if ( $job && ! empty( $job->logs_json ) ) {
					$decoded = json_decode( $job->logs_json, true );
					if ( is_array( $decoded ) ) {
						// Normalize to list.
						$logs = array_values( isset( $decoded[0] ) ? $decoded : array( $decoded ) );
					}
				}

				$logs[] = array(
					'time'          => current_time( 'mysql' ),
					'source'        => 'llm_provider',
					'error_code'    => $provider_error['code'],
					'error_message' => $provider_error['message'],
					'meta'          => $provider_error['meta'],
				);

				$this->jobs_table->update_job(
					$existing_job_id,
					array(
						'status'    => 'error',
						'logs_json' => wp_json_encode( $logs ),
					)
				);
			}

			return array(
				'status_code'   => 502,
				'error'         => 'generation_failed',
				'message'       => __( 'Content generation failed.', 'jarvis-content-engine' ),
				'provider_error'=> $provider_error,
			);
		}

		$title   = isset( $generation['title'] ) ? $generation['title'] : wp_trim_words( $subject, 12 );
		$content = $generation['content'];
		$faq     = isset( $generation['faq'] ) ? $generation['faq'] : array();
		$cta     = isset( $generation['cta'] ) ? $generation['cta'] : '';

		if ( $cta ) {
			$content .= "\n\n" . $cta;
		}

		if ( $faq && is_array( $faq ) ) {
			$content .= "\n\n<h2>" . esc_html__( 'Frequently Asked Questions', 'jarvis-content-engine' ) . '</h2>';
			foreach ( $faq as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$content .= "\n<h3>" . esc_html( $item['q'] ) . '</h3>';
				$content .= "\n<p>" . wp_kses_post( $item['a'] ) . '</p>';
			}
		}

		// Normalize image payload (support legacy keys).
		$featured_image_url  = isset( $generation['featured_image_url'] ) ? trim( (string) $generation['featured_image_url'] ) : '';
		$featured_image_alt  = isset( $generation['featured_image_alt'] ) ? sanitize_text_field( (string) $generation['featured_image_alt'] ) : $title;
		$og_image_url        = isset( $generation['og_image_url'] ) ? trim( (string) $generation['og_image_url'] ) : '';
		$inline_images_raw   = isset( $generation['inline_images'] ) && is_array( $generation['inline_images'] ) ? $generation['inline_images'] : array();
		$inline_images       = array();
		foreach ( $inline_images_raw as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) ) {
				continue;
			}
			$inline_images[] = array(
				'url'            => trim( (string) $item['url'] ),
				'alt'            => isset( $item['alt'] ) ? (string) $item['alt'] : '',
				'caption'        => isset( $item['caption'] ) ? (string) $item['caption'] : '',
				'placement_hint' => isset( $item['placement_hint'] ) ? (string) $item['placement_hint'] : 'end',
			);
		}
		if ( empty( $featured_image_url ) && ! empty( $generation['featured_image'] ) ) {
			$featured_image_url = trim( (string) $generation['featured_image'] );
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => isset( $generation['excerpt'] ) ? $generation['excerpt'] : wp_trim_words( wp_strip_all_tags( $content ), 40 ),
			'post_status'  => 'draft',
			'post_type'    => 'post',
		);

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return array(
				'status_code' => 500,
				'error'       => 'post_insert_failed',
				'message'     => $post_id->get_error_message(),
			);
		}

		// Categories and tags.
		if ( ! empty( $settings['target_categories'] ) ) {
			wp_set_post_terms( $post_id, $settings['target_categories'], 'category' );
		}
		if ( ! empty( $settings['target_tags'] ) ) {
			wp_set_post_terms( $post_id, $settings['target_tags'], 'post_tag' );
		}

		// Internal & external links from generation payload.
		$internal_links  = isset( $generation['internal_links'] ) ? (array) $generation['internal_links'] : array();
		$external_links = isset( $generation['external_links'] ) ? (array) $generation['external_links'] : array();

		// Image diagnostics and log entries (do not kill post on image failure unless featured_required and featured fails).
		$image_diagnostics = array(
			'featured_set'     => false,
			'inline_imported'   => 0,
			'og_set'           => false,
			'errors'           => array(),
		);
		$image_log_entries = array();

		// Featured image (before SEO so OG can fallback to it).
		if ( ! empty( $featured_image_url ) ) {
			$feat_result = $this->images->set_featured_image_from_url( $post_id, $featured_image_url, $featured_image_alt );
			if ( is_wp_error( $feat_result ) ) {
				$diag = $this->images->get_error_diagnostics( $feat_result, $featured_image_url );
				$msg = sanitize_text_field( $feat_result->get_error_message() );
				$image_diagnostics['errors'][] = array(
					'stage'       => 'featured',
					'message'     => $msg,
					'error_class' => $diag['error_class'],
					'source_meta' => $diag['source_meta'],
				);
				$image_log_entries[] = array(
					'source'     => 'image_service',
					'stage'      => 'featured',
					'message'    => $msg,
					'source_meta'=> $diag['source_meta'],
					'error_class'=> $diag['error_class'],
					'time'       => current_time( 'mysql' ),
				);
			} else {
				$image_diagnostics['featured_set'] = true;
			}
		}
		if ( ! empty( $settings['featured_required'] ) && empty( $featured_image_url ) ) {
			$image_diagnostics['errors'][] = array(
				'stage'       => 'featured',
				'message'     => sanitize_text_field( __( 'Featured image required but not provided.', 'jarvis-content-engine' ) ),
				'error_class' => 'unknown',
				'source_meta' => array( 'scheme' => 'other', 'host_redacted' => '', 'is_https' => false, 'ext' => '' ),
			);
		}

		// Set SEO meta (Rank Math / Yoast / native), including OG image.
		$this->apply_seo_meta( $post_id, $title, $generation, $settings, array(
			'og_image_url'                   => $og_image_url,
			'use_featured_as_og_fallback'    => ! empty( $settings['use_featured_as_og_fallback'] ),
			'featured_set'                   => $image_diagnostics['featured_set'],
			'image_diagnostics'              => &$image_diagnostics,
			'image_log_entries'               => &$image_log_entries,
			'images'                         => $this->images,
		) );

		// Inline images: import and inject into content.
		if ( ! empty( $settings['enable_inline_image_injection'] ) && ! empty( $inline_images ) ) {
			$max_inline = (int) $settings['inline_image_count'];
			$inline_images = array_slice( $inline_images, 0, $max_inline );
			$imported = $this->images->import_inline_images( $post_id, $inline_images );
			foreach ( $imported['errors'] as $err ) {
				$msg = isset( $err['message'] ) ? sanitize_text_field( $err['message'] ) : '';
				$source_meta = isset( $err['source_meta'] ) && is_array( $err['source_meta'] ) ? $err['source_meta'] : array( 'scheme' => 'other', 'host_redacted' => '', 'is_https' => false, 'ext' => '' );
				$error_class = isset( $err['error_class'] ) ? $err['error_class'] : 'unknown';
				$image_diagnostics['errors'][] = array(
					'stage'       => 'inline',
					'message'     => $msg,
					'error_class' => $error_class,
					'source_meta' => $source_meta,
				);
				$image_log_entries[] = array(
					'source'     => 'image_service',
					'stage'      => 'inline',
					'message'    => $msg,
					'source_meta'=> $source_meta,
					'error_class'=> $error_class,
					'time'       => current_time( 'mysql' ),
				);
			}
			$image_diagnostics['inline_imported'] = count( $imported['items'] );
			$content = $this->inject_inline_images_into_content( $content, $imported['items'], $max_inline );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
		}

		// Score content (using content that may now include inline images).
		$content_for_score = get_post( $post_id ) ? get_post( $post_id )->post_content : $content;
		$score = $this->scoring->score(
			array(
				'title'   => $title,
				'content' => $content_for_score,
				'keywords'=> $keywords,
				'links'   => array(
					'internal' => count( $internal_links ),
					'external' => count( $external_links ),
				),
			)
		);

		// Schema markup (Article + FAQ) stored as JSON-LD meta for SEO plugins/themes to render.
		if ( ! empty( $generation['schema_jsonld'] ) ) {
			update_post_meta( $post_id, '_jarvis_schema', wp_json_encode( $generation['schema_jsonld'] ) );
		}

		// Decide publish vs draft based on thresholds and guardrails.
		$status = 'draft';
		$guardrail_reasons = array();

		$min_score       = (int) $settings['seo_score_min'];
		$min_readability = (int) $settings['readability_min'];
		$min_words       = (int) $settings['word_count_min'];

		if ( $score['total'] < $min_score ) {
			$guardrail_reasons[] = 'seo_score_below_min';
		}
		if ( $score['readability'] < $min_readability ) {
			$guardrail_reasons[] = 'readability_below_min';
		}
		if ( $score['word_count'] < $min_words ) {
			$guardrail_reasons[] = 'word_count_below_min';
		}
		if ( empty( $title ) || empty( $content ) ) {
			$guardrail_reasons[] = 'missing_title_or_content';
		}
		if ( ! empty( $settings['featured_required'] ) && ! $image_diagnostics['featured_set'] ) {
			$guardrail_reasons[] = 'featured_image_required_but_failed';
		}

		if ( $publish && ! $settings['draft_only'] && empty( $guardrail_reasons ) ) {
			$status = 'publish';
		}

		if ( 'publish' === $status ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		}

		// Merge image log entries into job logs.
		$base_logs = array( array( 'source' => 'generate_once', 'time' => current_time( 'mysql' ) ) );
		if ( $existing_job_id ) {
			$job = $this->jobs_table->get_job( $existing_job_id );
			if ( $job && ! empty( $job->logs_json ) ) {
				$decoded = json_decode( $job->logs_json, true );
				if ( is_array( $decoded ) ) {
					$base_logs = isset( $decoded[0] ) ? $decoded : array( $decoded );
				}
			}
		}
		$final_logs = array_merge( $base_logs, $image_log_entries );
		$logs_json  = wp_json_encode( $final_logs );

		// Insert or update job record.
		if ( $existing_job_id ) {
			$this->jobs_table->update_job(
				$existing_job_id,
				array(
					'status'       => 'publish' === $status ? 'published' : 'generated',
					'generated_at' => current_time( 'mysql' ),
					'published_at' => 'publish' === $status ? current_time( 'mysql' ) : null,
					'post_id'      => $post_id,
					'score_json'   => wp_json_encode( $score ),
					'logs_json'    => $logs_json,
				)
			);
			$job_id = $existing_job_id;
		} else {
			$job_id = $this->jobs_table->insert_job(
				array(
					'subject'      => $subject,
					'status'       => 'publish' === $status ? 'published' : 'generated',
					'scheduled_at' => current_time( 'mysql' ),
					'generated_at' => current_time( 'mysql' ),
					'published_at' => 'publish' === $status ? current_time( 'mysql' ) : null,
					'post_id'      => $post_id,
					'score_json'   => wp_json_encode( $score ),
					'logs_json'    => $logs_json,
				)
			);
		}

		return array(
			'status_code' => 201,
			'job_id'      => (int) $job_id,
			'post_id'     => (int) $post_id,
			'post_status' => $status,
			'score'       => $score,
			'images'      => $image_diagnostics,
		);
	}

	/**
	 * Inject inline images into post content by placement hint.
	 *
	 * @param string $content     Post content.
	 * @param array  $images      List of { attachment_id, url, alt, caption, placement_hint }.
	 * @param int    $max_count   Max number of images to insert (already enforced by caller).
	 * @return string Updated content.
	 */
	protected function inject_inline_images_into_content( $content, array $images, $max_count = 3 ) {
		if ( empty( $images ) ) {
			return $content;
		}
		$max_count = max( 0, (int) $max_count );
		$images    = array_slice( $images, 0, $max_count );

		$blocks = array();
		foreach ( $images as $img ) {
			$url     = isset( $img['url'] ) ? esc_url( $img['url'] ) : '';
			$alt     = isset( $img['alt'] ) ? esc_attr( sanitize_text_field( $img['alt'] ) ) : '';
			$caption = isset( $img['caption'] ) ? sanitize_text_field( $img['caption'] ) : '';
			if ( empty( $url ) ) {
				continue;
			}
			$html = '<figure>';
			$html .= '<img src="' . $url . '" alt="' . $alt . '" />';
			if ( $caption !== '' ) {
				$html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>';
			}
			$html .= '</figure>';
			$blocks[] = array(
				'hint'  => isset( $img['placement_hint'] ) ? $img['placement_hint'] : 'end',
				'block' => $html,
			);
		}

		$hints_order = array( 'after_intro', 'after_h2_1', 'after_h2_2', 'end' );
		$used = array();
		foreach ( $hints_order as $hint ) {
			foreach ( $blocks as $idx => $b ) {
				if ( $b['hint'] !== $hint || in_array( $idx, $used, true ) ) {
					continue;
				}
				$used[] = $idx;
				if ( $hint === 'after_intro' ) {
					$content = $this->insert_after_first_paragraph( $content, $b['block'] );
				} elseif ( $hint === 'after_h2_1' ) {
					$content = $this->insert_after_nth_h2( $content, $b['block'], 1 );
				} elseif ( $hint === 'after_h2_2' ) {
					$content = $this->insert_after_nth_h2( $content, $b['block'], 2 );
				} else {
					$content = $content . "\n\n" . $b['block'];
				}
			}
		}
		// Any remaining (e.g. same hint): append near end.
		foreach ( $blocks as $idx => $b ) {
			if ( in_array( $idx, $used, true ) ) {
				continue;
			}
			$content = $content . "\n\n" . $b['block'];
		}

		return $content;
	}

	/**
	 * Insert HTML after the first paragraph.
	 *
	 * @param string $content Post content.
	 * @param string $block   HTML block to insert.
	 * @return string
	 */
	protected function insert_after_first_paragraph( $content, $block ) {
		if ( preg_match( '#(</p>)#i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[1][1] + strlen( $m[1][0] );
			return substr_replace( $content, "\n\n" . $block . "\n\n", $pos, 0 );
		}
		return $content . "\n\n" . $block;
	}

	/**
	 * Insert HTML after the Nth h2.
	 *
	 * @param string $content Post content.
	 * @param string $block   HTML block to insert.
	 * @param int    $n       Number of the h2 (1-based).
	 * @return string
	 */
	protected function insert_after_nth_h2( $content, $block, $n = 1 ) {
		$n = max( 1, (int) $n );
		$count = 0;
		if ( preg_match_all( '#(</h2>)#i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			if ( isset( $matches[1][ $n - 1 ] ) ) {
				$pos = $matches[1][ $n - 1 ][1] + strlen( $matches[1][ $n - 1 ][0] );
				return substr_replace( $content, "\n\n" . $block . "\n\n", $pos, 0 );
			}
		}
		return $content . "\n\n" . $block;
	}

	/**
	 * Apply SEO meta with compatibility for Rank Math and Yoast, including OG image.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $title     Title.
	 * @param array  $generation Generation payload.
	 * @param array  $settings  Plugin settings.
	 * @param array  $extra     Optional. Keys: og_image_url, use_featured_as_og_fallback, featured_set, image_diagnostics, image_log_entries, images.
	 */
	protected function apply_seo_meta( $post_id, $title, array $generation, array $settings, array $extra = array() ) {
		$primary_keyword = isset( $generation['primary_keyword'] ) ? $generation['primary_keyword'] : $settings['keyword_primary'];
		$meta_title      = isset( $generation['meta_title'] ) ? $generation['meta_title'] : strtr(
			$settings['meta_title_template'],
			array(
				'{title}'          => $title,
				'{site_name}'      => get_bloginfo( 'name' ),
				'{primary_keyword}'=> $primary_keyword,
			)
		);

		$meta_description = isset( $generation['meta_description'] ) ? $generation['meta_description'] : wp_trim_words( wp_strip_all_tags( $generation['content'] ), 30 );

		// Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_title', $meta_title );
			update_post_meta( $post_id, 'rank_math_description', $meta_description );
			if ( $primary_keyword ) {
				update_post_meta( $post_id, 'rank_math_focus_keyword', $primary_keyword );
			}
		}

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $meta_title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_description );
			if ( $primary_keyword ) {
				update_post_meta( $post_id, '_yoast_wpseo_focuskw', $primary_keyword );
			}
		}

		// Native fallback.
		update_post_meta( $post_id, '_jarvis_meta_title', $meta_title );
		update_post_meta( $post_id, '_jarvis_meta_description', $meta_description );

		// OG text meta.
		if ( isset( $generation['og_title'] ) ) {
			update_post_meta( $post_id, '_jarvis_og_title', $generation['og_title'] );
		}
		if ( isset( $generation['og_description'] ) ) {
			update_post_meta( $post_id, '_jarvis_og_description', $generation['og_description'] );
		}

		// OG image: explicit og_image_url, or fallback to featured when use_featured_as_og_fallback is on.
		$og_image_url        = isset( $extra['og_image_url'] ) ? trim( (string) $extra['og_image_url'] ) : '';
		$use_featured_fallback = ! empty( $extra['use_featured_as_og_fallback'] );
		$featured_set        = ! empty( $extra['featured_set'] );
		$image_diagnostics   = isset( $extra['image_diagnostics'] ) ? $extra['image_diagnostics'] : null;
		$image_log_entries   = isset( $extra['image_log_entries'] ) ? $extra['image_log_entries'] : null;
		$images_service      = isset( $extra['images'] ) ? $extra['images'] : $this->images;

		if ( $og_image_url ) {
			$og_result = $images_service->set_og_image_from_url( $post_id, $og_image_url, isset( $generation['og_image_alt'] ) ? $generation['og_image_alt'] : $title );
			if ( is_wp_error( $og_result ) ) {
				$diag = $images_service->get_error_diagnostics( $og_result, $og_image_url );
				$msg = sanitize_text_field( $og_result->get_error_message() );
				if ( $image_diagnostics ) {
					$image_diagnostics['errors'][] = array(
						'stage'       => 'og',
						'message'     => $msg,
						'error_class' => $diag['error_class'],
						'source_meta' => $diag['source_meta'],
					);
				}
				if ( $image_log_entries !== null ) {
					$image_log_entries[] = array(
						'source'     => 'image_service',
						'stage'      => 'og',
						'message'    => $msg,
						'source_meta'=> $diag['source_meta'],
						'error_class'=> $diag['error_class'],
						'time'       => current_time( 'mysql' ),
					);
				}
			} else {
				if ( $image_diagnostics ) {
					$image_diagnostics['og_set'] = true;
				}
			}
		} elseif ( $use_featured_fallback && $featured_set ) {
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				$thumb_url = wp_get_attachment_image_url( $thumb_id, 'full' );
				if ( $thumb_url ) {
					update_post_meta( $post_id, '_jarvis_og_image_id', $thumb_id );
					update_post_meta( $post_id, '_jarvis_og_image_url', $thumb_url );
					if ( defined( 'WPSEO_VERSION' ) ) {
						update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $thumb_url );
						update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', (string) $thumb_id );
					}
					if ( defined( 'RANK_MATH_VERSION' ) ) {
						update_post_meta( $post_id, 'rank_math_facebook_image', $thumb_url );
						update_post_meta( $post_id, 'rank_math_facebook_image_id', (string) $thumb_id );
					}
					if ( $image_diagnostics ) {
						$image_diagnostics['og_set'] = true;
					}
				}
			}
		}
	}
}

