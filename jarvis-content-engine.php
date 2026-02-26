<?php
/**
 * Plugin Name:       Open Claw Engine
 * Plugin URI:        https://hosthobbit.com
 * Description:       Automated AI-assisted content engine for scheduled, SEO-optimized WordPress publishing. Designed by Host Hobbit Ltd.
 * Version:           1.0.0
 * Author:            Mike Warburton
 * Author URI:        https://hosthobbit.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jarvis-content-engine
 * Domain Path:       /languages
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JARVIS_CONTENT_ENGINE_VERSION', '1.0.0' );
define( 'JARVIS_CONTENT_ENGINE_PLUGIN_FILE', __FILE__ );
define( 'JARVIS_CONTENT_ENGINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JARVIS_CONTENT_ENGINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Simple autoloader for plugin classes.
 *
 * Class names use the prefix Jarvis_ and map to files like:
 *   Jarvis_Plugin        -> includes/class-jarvis-plugin.php
 *   Jarvis_Admin         -> includes/admin/class-jarvis-admin.php
 *   Jarvis_Jobs_Table    -> includes/db/class-jarvis-jobs-table.php
 *
 * @param string $class Class name.
 */
function jarvis_content_engine_autoload( $class ) {
	if ( 0 !== strpos( $class, 'Jarvis_' ) ) {
		return;
	}

	// Convert Jarvis_Plugin to jarvis-plugin, Jarvis_Jobs_Table to jarvis-jobs-table, etc.
	$relative = strtolower( str_replace( '_', '-', $class ) );

	$paths = array(
		'includes/class-' . $relative . '.php',
		'includes/admin/class-' . $relative . '.php',
		'includes/rest/class-' . $relative . '.php',
		'includes/cron/class-' . $relative . '.php',
		'includes/services/class-' . $relative . '.php',
		'includes/db/class-' . $relative . '.php',
		'includes/auth/class-' . $relative . '.php',
	);

	foreach ( $paths as $path ) {
		$full = JARVIS_CONTENT_ENGINE_PLUGIN_DIR . $path;
		if ( file_exists( $full ) ) {
			require_once $full;
			return;
		}
	}
}

spl_autoload_register( 'jarvis_content_engine_autoload' );

/**
 * Plugin activation callback.
 */
function jarvis_content_engine_activate() {
	if ( ! class_exists( 'Jarvis_Jobs_Table' ) ) {
		// Ensure autoloader is available.
		jarvis_content_engine_autoload( 'Jarvis_Jobs_Table' );
	}

	if ( class_exists( 'Jarvis_Jobs_Table' ) ) {
		$table = new Jarvis_Jobs_Table();
		$table->create_table();
	}

	if ( ! class_exists( 'Jarvis_Cron' ) ) {
		jarvis_content_engine_autoload( 'Jarvis_Cron' );
	}

	if ( class_exists( 'Jarvis_Cron' ) ) {
		Jarvis_Cron::activate();
	}
}

/**
 * Plugin deactivation callback.
 */
function jarvis_content_engine_deactivate() {
	if ( class_exists( 'Jarvis_Cron' ) ) {
		Jarvis_Cron::deactivate();
	}
}

register_activation_hook( __FILE__, 'jarvis_content_engine_activate' );
register_deactivation_hook( __FILE__, 'jarvis_content_engine_deactivate' );

/**
 * Initialize the plugin.
 */
function jarvis_content_engine_init() {
	if ( ! class_exists( 'Jarvis_Plugin' ) ) {
		jarvis_content_engine_autoload( 'Jarvis_Plugin' );
	}

	if ( class_exists( 'Jarvis_Plugin' ) ) {
		Jarvis_Plugin::get_instance()->init();
	}
}

add_action( 'plugins_loaded', 'jarvis_content_engine_init' );

