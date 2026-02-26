<?php
/**
 * Tests for Jarvis_SEO_Scoring_Service.
 */

use PHPUnit\Framework\TestCase;

class Jarvis_SEO_Scoring_Service_Test extends TestCase {

	public function test_scores_basic_content() {
		$service = new Jarvis_SEO_Scoring_Service();

		$content = '<h2>Introduction</h2><p>Managed WordPress security is important.</p>'
			. '<h2>Best Practices</h2><p>More content about managed WordPress security.</p>'
			. '<h2>Conclusion</h2><p>Summary of managed WordPress security.</p>';

		$score = $service->score(
			array(
				'title'   => 'Managed WordPress security best practices',
				'content' => $content,
				'keywords'=> array( 'managed WordPress security' ),
				'links'   => array(
					'internal' => 3,
					'external' => 2,
				),
			)
		);

		$this->assertIsArray( $score );
		$this->assertArrayHasKey( 'total', $score );
		$this->assertGreaterThan( 0, $score['total'] );
		$this->assertLessThanOrEqual( 100, $score['total'] );
	}
}

