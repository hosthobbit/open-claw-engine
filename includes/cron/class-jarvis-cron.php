<?php
/**
 * Cron scheduler for Jarvis Content Engine.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages WP-Cron events for scheduled content generation.
 */
class Jarvis_Cron {

	/**
	 * Daily event hook name.
	 *
	 * @var string
	 */
	const HOOK_DAILY = 'jarvis_content_engine_daily_event';

	/**
	 * Retry event hook name.
	 *
	 * @var string
	 */
	const HOOK_RETRY = 'jarvis_content_engine_retry_event';

	/**
	 * Activate cron schedules.
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			$time = self::get_next_run_time();
			wp_schedule_event( $time, 'daily', self::HOOK_DAILY );
		}
	}

	/**
	 * Deactivate cron schedules.
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK_DAILY );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_DAILY );
		}

		$retry_timestamp = wp_next_scheduled( self::HOOK_RETRY );
		if ( $retry_timestamp ) {
			wp_unschedule_event( $retry_timestamp, self::HOOK_RETRY );
		}
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( self::HOOK_DAILY, array( $this, 'handle_daily_event' ) );
		add_action( self::HOOK_RETRY, array( $this, 'handle_retry_event' ) );
	}

	/**
	 * Calculate next run time based on settings timezone-aware.
	 *
	 * @return int Unix timestamp.
	 */
	public static function get_next_run_time() {
		$settings = get_option( 'jarvis_content_engine_settings', array() );
		$time_str = isset( $settings['daily_time'] ) ? $settings['daily_time'] : '03:00';

		$datetime = date_i18n( 'Y-m-d ' . $time_str . ':00', current_time( 'timestamp' ) );

		$timestamp = strtotime( $datetime );
		if ( $timestamp <= current_time( 'timestamp' ) ) {
			$timestamp = strtotime( '+1 day', $timestamp );
		}

		return $timestamp;
	}

	/**
	 * Handle daily scheduled generation.
	 */
	public function handle_daily_event() {
		if ( ! class_exists( 'Jarvis_Content_Pipeline' ) ) {
			return;
		}

		$pipeline = new Jarvis_Content_Pipeline();
		$pipeline->run_scheduled_campaigns();
	}

	/**
	 * Handle retry event with exponential backoff.
	 */
	public function handle_retry_event() {
		if ( ! class_exists( 'Jarvis_Content_Pipeline' ) ) {
			return;
		}

		$pipeline = new Jarvis_Content_Pipeline();
		$pipeline->run_retry_queue();
	}

	/**
	 * Schedule a retry with exponential backoff.
	 *
	 * @param int $attempt Attempt number.
	 */
	public static function schedule_retry( $attempt ) {
		$attempt = max( 1, (int) $attempt );

		// Exponential backoff in minutes: 5, 15, 30, 60...
		$minutes   = min( 60, 5 * pow( 2, $attempt - 1 ) );
		$timestamp = current_time( 'timestamp' ) + ( $minutes * MINUTE_IN_SECONDS );

		wp_schedule_single_event( $timestamp, self::HOOK_RETRY, array() );
	}
}
