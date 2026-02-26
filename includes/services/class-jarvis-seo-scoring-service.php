<?php
/**
 * SEO and quality scoring service.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight heuristic scoring for SEO and quality.
 */
class Jarvis_SEO_Scoring_Service {

	/**
	 * Score content.
	 *
	 * @param array $context Context with title, content, keywords, links, etc.
	 * @return array Score breakdown: total, readability, seo, uniqueness_warning, notes[]
	 */
	public function score( array $context ) {
		$title      = isset( $context['title'] ) ? (string) $context['title'] : '';
		$content    = isset( $context['content'] ) ? (string) $context['content'] : '';
		$keywords   = isset( $context['keywords'] ) ? (array) $context['keywords'] : array();
		$links      = isset( $context['links'] ) ? (array) $context['links'] : array();
		$word_count = str_word_count( wp_strip_all_tags( $content ) );

		$notes = array();

		// Keyword placement.
		$primary   = $keywords ? reset( $keywords ) : '';
		$seo_score = 0;

		if ( $primary ) {
			if ( stripos( $title, $primary ) !== false ) {
				$seo_score += 20;
			} else {
				$notes[] = __( 'Primary keyword not found in title.', 'jarvis-content-engine' );
			}

			$intro = substr( $content, 0, 500 );
			if ( stripos( $intro, $primary ) !== false ) {
				$seo_score += 15;
			} else {
				$notes[] = __( 'Primary keyword not found in introduction.', 'jarvis-content-engine' );
			}

			$conclusion = substr( $content, -500 );
			if ( stripos( $conclusion, $primary ) !== false ) {
				$seo_score += 15;
			} else {
				$notes[] = __( 'Primary keyword not found in conclusion.', 'jarvis-content-engine' );
			}
		}

		// Simple heading structure bonus.
		if ( preg_match_all( '/<h2[^>]*>.*?<\/h2>/i', $content, $matches ) && count( $matches[0] ) >= 3 ) {
			$seo_score += 15;
		} else {
			$notes[] = __( 'Consider adding more H2 sections for structure.', 'jarvis-content-engine' );
		}

		// Link distribution.
		$internal_links = isset( $links['internal'] ) ? (int) $links['internal'] : 0;
		$external_links = isset( $links['external'] ) ? (int) $links['external'] : 0;

		if ( $internal_links >= 3 ) {
			$seo_score += 10;
		} else {
			$notes[] = __( 'Add more internal links to relevant content.', 'jarvis-content-engine' );
		}

		if ( $external_links >= 2 ) {
			$seo_score += 10;
		} else {
			$notes[] = __( 'Add more external authority links where relevant.', 'jarvis-content-engine' );
		}

		// Content length.
		if ( $word_count >= 1200 ) {
			$seo_score += 15;
		} elseif ( $word_count >= 800 ) {
			$seo_score += 8;
			$notes[] = __( 'Consider expanding the article for deeper coverage.', 'jarvis-content-engine' );
		} else {
			$notes[] = __( 'Content is relatively short; long-form tends to perform better.', 'jarvis-content-engine' );
		}

		// Basic readability proxy: average sentence length.
		$sentences = preg_split( '/[.!?]+/', wp_strip_all_tags( $content ) );
		$sentences = array_filter( array_map( 'trim', $sentences ) );

		$avg_sentence_len = 0;
		if ( $sentences ) {
			$total_words = 0;
			foreach ( $sentences as $sentence ) {
				$total_words += str_word_count( $sentence );
			}
			$avg_sentence_len = $total_words / max( 1, count( $sentences ) );
		}

		$readability_score = 100;
		if ( $avg_sentence_len > 25 ) {
			$readability_score -= 30;
			$notes[] = __( 'Sentences are long; consider breaking them up.', 'jarvis-content-engine' );
		} elseif ( $avg_sentence_len > 20 ) {
			$readability_score -= 15;
		}

		// Uniqueness proxy: warn if content is extremely short or repeated phrases.
		$uniqueness_warning = false;
		if ( $word_count < 500 ) {
			$uniqueness_warning = true;
			$notes[]            = __( 'Short content may have trouble standing out; ensure the topic coverage is unique.', 'jarvis-content-engine' );
		}

		$total_score = min( 100, max( 0, (int) round( ( $seo_score * 0.6 ) + ( $readability_score * 0.4 ) ) ) );

		return array(
			'total'               => $total_score,
			'seo'                 => (int) $seo_score,
			'readability'         => (int) $readability_score,
			'uniqueness_warning'  => $uniqueness_warning,
			'word_count'          => $word_count,
			'avg_sentence_length' => $avg_sentence_len,
			'notes'               => $notes,
		);
	}
}

