<?php
/**
 * Admin UI for Jarvis Content Engine.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page and settings UI.
 */
class Jarvis_Admin {

	/**
	 * Jobs table instance.
	 *
	 * @var Jarvis_Jobs_Table
	 */
	protected $jobs_table;

	/**
	 * Constructor.
	 *
	 * @param Jarvis_Jobs_Table $jobs_table Jobs table handler.
	 */
	public function __construct( Jarvis_Jobs_Table $jobs_table ) {
		$this->jobs_table = $jobs_table;
	}

	/**
	 * Init hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_jarvis_refresh_models', array( $this, 'handle_refresh_models' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_models_refreshed_notice' ) );
	}

	/**
	 * Show non-fatal notice after Refresh models.
	 */
	public function maybe_show_models_refreshed_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'toplevel_page_jarvis-content-engine' ) {
			return;
		}
		if ( ! isset( $_GET['jarvis_models_refreshed'] ) || $_GET['jarvis_models_refreshed'] !== '1' ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Model list refreshed.', 'jarvis-content-engine' ) . '</p></div>';
	}

	/**
	 * Register admin menu.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Open Claw Engine', 'jarvis-content-engine' ),
			__( 'Open Claw Engine', 'jarvis-content-engine' ),
			'manage_options',
			'jarvis-content-engine',
			array( $this, 'render_settings_page' ),
			'dashicons-analytics',
			81
		);
	}

	/**
	 * Enqueue admin CSS/JS.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_jarvis-content-engine' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'jarvis-content-engine-admin',
			JARVIS_CONTENT_ENGINE_PLUGIN_URL . 'assets/admin.css',
			array(),
			JARVIS_CONTENT_ENGINE_VERSION
		);
	}

	/**
	 * Render settings + logs page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'jarvis-content-engine' ) );
		}

		$settings = Jarvis_Settings::get_settings();
		$jobs     = $this->jobs_table->get_recent_jobs( 50 );

		?>
		<div class="wrap jarvis-content-engine">
			<h1><?php esc_html_e( 'Open Claw Engine', 'jarvis-content-engine' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<a href="#jarvis-settings" class="nav-tab nav-tab-active"><?php esc_html_e( 'Settings', 'jarvis-content-engine' ); ?></a>
				<a href="#jarvis-logs" class="nav-tab"><?php esc_html_e( 'Job Logs', 'jarvis-content-engine' ); ?></a>
			</h2>

			<div id="jarvis-settings" class="jarvis-tab-content is-active">
				<form method="post" action="options.php">
					<?php
					settings_fields( 'jarvis_content_engine' );
					$options = $settings;
					?>

					<h2><?php esc_html_e( 'Connection & Authentication', 'jarvis-content-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="jarvis_mode"><?php esc_html_e( 'Integration Mode', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<select name="jarvis_content_engine_settings[mode]" id="jarvis_mode">
									<option value="external" <?php selected( $options['mode'], 'external' ); ?>><?php esc_html_e( 'Mode A: External agent uses REST API', 'jarvis-content-engine' ); ?></option>
									<option value="direct" <?php selected( $options['mode'], 'direct' ); ?>><?php esc_html_e( 'Mode B: Plugin calls LLM directly', 'jarvis-content-engine' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Recommended: external agent integration via secure REST.', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_mode_fallback"><?php esc_html_e( 'Fallback Mode', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<select name="jarvis_content_engine_settings[mode_fallback]" id="jarvis_mode_fallback">
									<option value="direct" <?php selected( $options['mode_fallback'], 'direct' ); ?>><?php esc_html_e( 'Fallback to direct LLM', 'jarvis-content-engine' ); ?></option>
									<option value="none" <?php selected( $options['mode_fallback'], 'none' ); ?>><?php esc_html_e( 'No fallback', 'jarvis-content-engine' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_auth_mode"><?php esc_html_e( 'Auth Mode for REST', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<select name="jarvis_content_engine_settings[auth_mode]" id="jarvis_auth_mode">
									<option value="application_password" <?php selected( $options['auth_mode'], 'application_password' ); ?>><?php esc_html_e( 'Application Password (recommended)', 'jarvis-content-engine' ); ?></option>
									<option value="jwt" <?php selected( $options['auth_mode'], 'jwt' ); ?>><?php esc_html_e( 'JWT (requires JWT plugin)', 'jarvis-content-engine' ); ?></option>
									<option value="hmac" <?php selected( $options['auth_mode'], 'hmac' ); ?>><?php esc_html_e( 'Signed HMAC token', 'jarvis-content-engine' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Use HTTPS for all API calls. Secrets are stored masked in this UI.', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_hmac_secret"><?php esc_html_e( 'HMAC Shared Secret', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="password" id="jarvis_hmac_secret" name="jarvis_content_engine_settings[hmac_secret]" value="<?php echo esc_attr( $options['hmac_secret'] ? str_repeat( '*', 10 ) : '' ); ?>" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'Used only when HMAC auth mode is enabled. Value is never shown in clear text.', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'LLM Provider (Direct Mode B)', 'jarvis-content-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable built-in provider', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[provider_enabled]" value="1" <?php checked( $options['provider_enabled'], true ); ?> />
									<?php esc_html_e( 'Use built-in OpenAI-compatible provider for Mode B.', 'jarvis-content-engine' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, Open Claw Engine will call the configured Chat Completions endpoint directly if no external generator is attached.', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_provider_api_base"><?php esc_html_e( 'API Base URL', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="url" class="regular-text" id="jarvis_provider_api_base" name="jarvis_content_engine_settings[provider_api_base]" value="<?php echo esc_attr( $options['provider_api_base'] ); ?>" />
								<p class="description"><?php esc_html_e( 'OpenAI-compatible API base, e.g. https://api.openai.com/v1', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_provider_api_key"><?php esc_html_e( 'API Key', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="password" class="regular-text" id="jarvis_provider_api_key" name="jarvis_content_engine_settings[provider_api_key]" value="<?php echo esc_attr( $options['provider_api_key'] ? str_repeat( '*', 12 ) : '' ); ?>" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'Store your API key securely; it will not be shown in clear text.', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_provider_model"><?php esc_html_e( 'Model', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<?php
								$available_models = array();
								if ( ! empty( $options['provider_api_base'] ) && ! empty( $options['provider_api_key'] ) && class_exists( 'Jarvis_Model_Discovery' ) ) {
									$available_models = Jarvis_Model_Discovery::get_models();
									$current_model   = isset( $options['provider_model'] ) ? $options['provider_model'] : '';
									if ( $current_model !== '' && ! in_array( $current_model, $available_models, true ) ) {
										$available_models = array_merge( array( $current_model ), $available_models );
									}
								}
								if ( ! empty( $available_models ) ) :
									?>
									<select id="jarvis_provider_model_select" class="regular-text" aria-label="<?php esc_attr_e( 'Select model', 'jarvis-content-engine' ); ?>">
										<?php foreach ( $available_models as $mid ) : ?>
											<option value="<?php echo esc_attr( $mid ); ?>" <?php selected( isset( $options['provider_model'] ) ? $options['provider_model'] : '', $mid ); ?>><?php echo esc_html( $mid ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php
									$refresh_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=jarvis_refresh_models' ),
										'jarvis_refresh_models'
									);
									?>
									<a href="<?php echo esc_url( $refresh_url ); ?>" class="button button-secondary" style="margin-left: 8px;"><?php esc_html_e( 'Refresh models', 'jarvis-content-engine' ); ?></a>
									<p class="description"><?php esc_html_e( 'Select from list or type a custom model below.', 'jarvis-content-engine' ); ?></p>
									<input type="text" class="regular-text" id="jarvis_provider_model" name="jarvis_content_engine_settings[provider_model]" value="<?php echo esc_attr( isset( $options['provider_model'] ) ? $options['provider_model'] : '' ); ?>" style="margin-top: 6px;" />
									<script>
									(function() {
										var sel = document.getElementById('jarvis_provider_model_select');
										var inp = document.getElementById('jarvis_provider_model');
										if (sel && inp) {
											sel.addEventListener('change', function() { inp.value = this.value; });
										}
									})();
									</script>
								<?php else : ?>
									<input type="text" class="regular-text" id="jarvis_provider_model" name="jarvis_content_engine_settings[provider_model]" value="<?php echo esc_attr( isset( $options['provider_model'] ) ? $options['provider_model'] : '' ); ?>" />
									<?php if ( empty( $options['provider_api_base'] ) || empty( $options['provider_api_key'] ) ) : ?>
										<p class="description"><?php esc_html_e( 'Set API Base URL and API Key above, then save to load the model dropdown.', 'jarvis-content-engine' ); ?></p>
									<?php else : ?>
										<p class="description"><?php esc_html_e( 'Model identifier, e.g. gpt-4o. Save and reload to fetch model list from API.', 'jarvis-content-engine' ); ?></p>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_provider_timeout"><?php esc_html_e( 'Timeout (seconds)', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="number" id="jarvis_provider_timeout" name="jarvis_content_engine_settings[provider_timeout]" value="<?php echo esc_attr( $options['provider_timeout'] ); ?>" min="5" max="120" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_provider_max_tokens"><?php esc_html_e( 'Max tokens', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="number" id="jarvis_provider_max_tokens" name="jarvis_content_engine_settings[provider_max_tokens]" value="<?php echo esc_attr( $options['provider_max_tokens'] ); ?>" min="256" max="8192" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_provider_temperature"><?php esc_html_e( 'Temperature', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="number" step="0.05" min="0" max="1" id="jarvis_provider_temperature" name="jarvis_content_engine_settings[provider_temperature]" value="<?php echo esc_attr( $options['provider_temperature'] ); ?>" />
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Content Defaults', 'jarvis-content-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="jarvis_default_subject"><?php esc_html_e( 'Default Campaign Subject', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="text" class="regular-text" id="jarvis_default_subject" name="jarvis_content_engine_settings[default_subject]" value="<?php echo esc_attr( $options['default_subject'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Example: Managed WordPress security tips.', 'jarvis-content-engine' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Target Categories', 'jarvis-content-engine' ); ?></th>
							<td>
								<input type="text" class="regular-text" name="jarvis_content_engine_settings[target_categories][]" value="<?php echo esc_attr( implode( ',', $options['target_categories'] ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Comma-separated category slugs.', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Target Tags', 'jarvis-content-engine' ); ?></th>
							<td>
								<input type="text" class="regular-text" name="jarvis_content_engine_settings[target_tags][]" value="<?php echo esc_attr( implode( ',', $options['target_tags'] ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Comma-separated tag slugs.', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tone & Voice', 'jarvis-content-engine' ); ?></th>
							<td>
								<select name="jarvis_content_engine_settings[tone]">
									<option value="professional" <?php selected( $options['tone'], 'professional' ); ?>><?php esc_html_e( 'Professional', 'jarvis-content-engine' ); ?></option>
									<option value="conversational" <?php selected( $options['tone'], 'conversational' ); ?>><?php esc_html_e( 'Conversational', 'jarvis-content-engine' ); ?></option>
									<option value="technical" <?php selected( $options['tone'], 'technical' ); ?>><?php esc_html_e( 'Technical', 'jarvis-content-engine' ); ?></option>
								</select>
								<select name="jarvis_content_engine_settings[voice]">
									<option value="first_person" <?php selected( $options['voice'], 'first_person' ); ?>><?php esc_html_e( 'First person', 'jarvis-content-engine' ); ?></option>
									<option value="second_person" <?php selected( $options['voice'], 'second_person' ); ?>><?php esc_html_e( 'Second person', 'jarvis-content-engine' ); ?></option>
									<option value="third_person" <?php selected( $options['voice'], 'third_person' ); ?>><?php esc_html_e( 'Third person', 'jarvis-content-engine' ); ?></option>
								</select>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Word Count Range', 'jarvis-content-engine' ); ?></th>
							<td>
								<input type="number" name="jarvis_content_engine_settings[word_count_min]" value="<?php echo esc_attr( $options['word_count_min'] ); ?>" min="300" /> -
								<input type="number" name="jarvis_content_engine_settings[word_count_max]" value="<?php echo esc_attr( $options['word_count_max'] ); ?>" min="300" />
								<p class="description"><?php esc_html_e( 'Target long-form content suitable for SEO (e.g., 1200–2500 words).', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
					</table>

					<h2><?php esc_html_e( 'SEO & Quality', 'jarvis-content-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Meta Title Template', 'jarvis-content-engine' ); ?></th>
							<td>
								<input type="text" class="regular-text" name="jarvis_content_engine_settings[meta_title_template]" value="<?php echo esc_attr( $options['meta_title_template'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Placeholders: {title}, {site_name}, {primary_keyword}.', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
					<tr>
							<th scope="row"><?php esc_html_e( 'Slug Strategy', 'jarvis-content-engine' ); ?></th>
							<td>
								<select name="jarvis_content_engine_settings[slug_strategy]">
									<option value="kebab" <?php selected( $options['slug_strategy'], 'kebab' ); ?>><?php esc_html_e( 'Kebab case (recommended)', 'jarvis-content-engine' ); ?></option>
									<option value="simple" <?php selected( $options['slug_strategy'], 'simple' ); ?>><?php esc_html_e( 'Simple', 'jarvis-content-engine' ); ?></option>
								</select>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Keyword Strategy', 'jarvis-content-engine' ); ?></th>
							<td>
								<input type="text" class="regular-text" name="jarvis_content_engine_settings[keyword_primary]" value="<?php echo esc_attr( $options['keyword_primary'] ); ?>" placeholder="<?php esc_attr_e( 'Primary keyword', 'jarvis-content-engine' ); ?>" />
								<p class="description"><?php esc_html_e( 'Secondary keywords can be passed per job via API.', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Quality Thresholds', 'jarvis-content-engine' ); ?></th>
					<td>
							<label>
								<?php esc_html_e( 'Readability min', 'jarvis-content-engine' ); ?>
								<input type="number" name="jarvis_content_engine_settings[readability_min]" value="<?php echo esc_attr( $options['readability_min'] ); ?>" min="0" max="100" />
							</label>
							<br />
							<label>
								<?php esc_html_e( 'Uniqueness min (0–1)', 'jarvis-content-engine' ); ?>
								<input type="number" step="0.01" min="0" max="1" name="jarvis_content_engine_settings[uniqueness_min]" value="<?php echo esc_attr( $options['uniqueness_min'] ); ?>" />
							</label>
							<br />
							<label>
								<?php esc_html_e( 'SEO score min (0–100)', 'jarvis-content-engine' ); ?>
								<input type="number" name="jarvis_content_engine_settings[seo_score_min]" value="<?php echo esc_attr( $options['seo_score_min'] ); ?>" min="0" max="100" />
							</label>
							<p class="description"><?php esc_html_e( 'If below thresholds, content stays in draft with issue notes and optional automatic revisions.', 'jarvis-content-engine' ); ?></p>
						</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Workflow', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[draft_only]" value="1" <?php checked( $options['draft_only'], true ); ?> />
									<?php esc_html_e( 'Draft-only mode', 'jarvis-content-engine' ); ?>
								</label>
								<br />
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[auto_publish]" value="1" <?php checked( $options['auto_publish'], true ); ?> />
									<?php esc_html_e( 'Auto-publish when above thresholds', 'jarvis-content-engine' ); ?>
								</label>
							</td>
					</tr>
					</table>

					<h2><?php esc_html_e( 'Images', 'jarvis-content-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Featured image', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[featured_required]" value="1" <?php checked( ! empty( $options['featured_required'] ), true ); ?> />
									<?php esc_html_e( 'Require featured image (post stays draft if missing or import fails)', 'jarvis-content-engine' ); ?>
								</label>
							</td>
					</tr>
						<tr>
							<th scope="row">
								<label for="jarvis_inline_image_count"><?php esc_html_e( 'Max inline images', 'jarvis-content-engine' ); ?></label>
							</th>
							<td>
								<input type="number" id="jarvis_inline_image_count" name="jarvis_content_engine_settings[inline_image_count]" value="<?php echo esc_attr( $options['inline_image_count'] ); ?>" min="0" max="20" />
								<p class="description"><?php esc_html_e( 'Maximum number of inline images to inject into post content (0 to disable).', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Inline image injection', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[enable_inline_image_injection]" value="1" <?php checked( ! empty( $options['enable_inline_image_injection'] ), true ); ?> />
									<?php esc_html_e( 'Enable inline image injection into post content', 'jarvis-content-engine' ); ?>
								</label>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'OG image fallback', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[use_featured_as_og_fallback]" value="1" <?php checked( ! empty( $options['use_featured_as_og_fallback'] ), true ); ?> />
									<?php esc_html_e( 'Use featured image as og:image when no og_image_url provided', 'jarvis-content-engine' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Compatible with Yoast SEO and Rank Math.', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Verify remote image exists', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[verify_remote_image_exists]" value="1" <?php checked( ! empty( $options['verify_remote_image_exists'] ), true ); ?> />
									<?php esc_html_e( 'Check that image URLs return HTTP 200 and image content-type before using (recommended)', 'jarvis-content-engine' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When off, only allowlist and extension checks apply.', 'jarvis-content-engine' ); ?></p>
							</td>
					</tr>
					</table>

					<h2><?php esc_html_e( 'Uninstall', 'jarvis-content-engine' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Cleanup on uninstall', 'jarvis-content-engine' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="jarvis_content_engine_settings[cleanup_on_uninstall]" value="1" <?php checked( $options['cleanup_on_uninstall'], true ); ?> />
									<?php esc_html_e( 'Remove plugin options and job tables when the plugin is deleted.', 'jarvis-content-engine' ); ?>
								</label>
							</td>
					</tr>
					</table>

					<?php submit_button(); ?>
				</form>
			</div>

			<div id="jarvis-logs" class="jarvis-tab-content">
				<h2><?php esc_html_e( 'Recent Jobs', 'jarvis-content-engine' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'jarvis-content-engine' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'jarvis-content-engine' ); ?></th>
							<th><?php esc_html_e( 'Status', 'jarvis-content-engine' ); ?></th>
							<th><?php esc_html_e( 'Scheduled', 'jarvis-content-engine' ); ?></th>
							<th><?php esc_html_e( 'Generated', 'jarvis-content-engine' ); ?></th>
							<th><?php esc_html_e( 'Published', 'jarvis-content-engine' ); ?></th>
							<th><?php esc_html_e( 'Post', 'jarvis-content-engine' ); ?></th>
					</tr>
					</thead>
					<tbody>
						<?php if ( empty( $jobs ) ) : ?>
							<tr>
								<td colspan="7"><?php esc_html_e( 'No jobs yet.', 'jarvis-content-engine' ); ?></td>
						</tr>
						<?php else : ?>
							<?php foreach ( $jobs as $job ) : ?>
								<tr>
									<td><?php echo esc_html( $job->id ); ?></td>
									<td><?php echo esc_html( wp_trim_words( $job->subject, 12 ) ); ?></td>
									<td><?php echo esc_html( $job->status ); ?></td>
									<td><?php echo esc_html( $job->scheduled_at ); ?></td>
									<td><?php echo esc_html( $job->generated_at ); ?></td>
									<td><?php echo esc_html( $job->published_at ); ?></td>
									<td>
										<?php
										if ( ! empty( $job->post_id ) ) {
											$link = get_edit_post_link( (int) $job->post_id );
											if ( $link ) {
												printf(
													'<a href="%s">%s #%d</a>',
													esc_url( $link ),
													esc_html__( 'Post', 'jarvis-content-engine' ),
													(int) $job->post_id
												);
											} else {
												echo esc_html( $job->post_id );
										}
									}
									?>
								</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<p class="jarvis-admin-footer" style="margin-top: 2em; padding-top: 1em; border-top: 1px solid #ccc; color: #666; font-size: 12px;">
				<?php esc_html_e( 'Designed by Host Hobbit Ltd', 'jarvis-content-engine' ); ?> — <a href="https://hosthobbit.com" target="_blank" rel="noopener noreferrer">https://hosthobbit.com</a><br />
				<?php esc_html_e( 'Author', 'jarvis-content-engine' ); ?>: Mike Warburton
			</p>
		</div>
		<?php
	}

	/**
	 * Handle Refresh models action: clear transient and redirect back with notice.
	 */
	public function handle_refresh_models() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'jarvis-content-engine' ), 403 );
		}
		check_admin_referer( 'jarvis_refresh_models' );

		if ( class_exists( 'Jarvis_Model_Discovery' ) ) {
			Jarvis_Model_Discovery::refresh_models();
		}

		wp_safe_redirect( add_query_arg( 'jarvis_models_refreshed', '1', admin_url( 'admin.php?page=jarvis-content-engine' ) ) );
		exit;
	}
}
