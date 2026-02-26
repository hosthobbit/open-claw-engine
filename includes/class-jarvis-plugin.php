<?php
/**
 * Core plugin orchestrator.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class wiring all components together.
 */
class Jarvis_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Jarvis_Plugin
	 */
	protected static $instance;

	/**
	 * Cron manager.
	 *
	 * @var Jarvis_Cron
	 */
	protected $cron;

	/**
	 * Jobs table.
	 *
	 * @var Jarvis_Jobs_Table
	 */
	protected $jobs_table;

	/**
	 * Settings handler.
	 *
	 * @var Jarvis_Settings
	 */
	protected $settings;

	/**
	 * Content pipeline.
	 *
	 * @var Jarvis_Content_Pipeline
	 */
	protected $pipeline;

	/**
	 * LLM provider.
	 *
	 * @var Jarvis_Llm_Provider
	 */
	protected $llm_provider;

	/**
	 * Get singleton instance.
	 *
	 * @return Jarvis_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin components.
	 */
	public function init() {
		$this->jobs_table = new Jarvis_Jobs_Table();

		if ( class_exists( 'Jarvis_Settings' ) ) {
			$this->settings = new Jarvis_Settings();
		}

		if ( class_exists( 'Jarvis_Content_Pipeline' ) ) {
			$this->pipeline = new Jarvis_Content_Pipeline();
		}

		$this->cron = new Jarvis_Cron();
		$this->cron->init();

		if ( is_admin() && class_exists( 'Jarvis_Admin' ) ) {
			$admin = new Jarvis_Admin( $this->jobs_table );
			$admin->init();
		}

		// Register built-in LLM provider for Mode B (can be overridden by custom filters).
		if ( class_exists( 'Jarvis_Llm_Provider' ) ) {
			$this->llm_provider = new Jarvis_Llm_Provider();
			add_filter( 'jarvis_content_engine_generate', array( $this->llm_provider, 'generate' ), 10, 2 );
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes() {
		if ( class_exists( 'Jarvis_REST_Jobs_Controller' ) ) {
			$jobs_controller = new Jarvis_REST_Jobs_Controller( $this->jobs_table );
			$jobs_controller->register_routes();
		}

		if ( class_exists( 'Jarvis_REST_Health_Controller' ) ) {
			$health_controller = new Jarvis_REST_Health_Controller();
			$health_controller->register_routes();
		}
	}
}
