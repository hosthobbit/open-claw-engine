<?php
/**
 * Uninstall Jarvis Content Engine.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load WordPress environment basics if needed.

$settings = get_option( 'jarvis_content_engine_settings', array() );

// Only clean up if admin opted-in.
if ( empty( $settings['cleanup_on_uninstall'] ) ) {
	return;
}

// Drop jobs table.
$jobs_table_file = plugin_dir_path( __FILE__ ) . 'includes/db/class-jarvis-jobs-table.php';
if ( file_exists( $jobs_table_file ) ) {
	require_once $jobs_table_file;
	if ( class_exists( 'Jarvis_Jobs_Table' ) ) {
		$table = new Jarvis_Jobs_Table();
		$table->drop_table();
	}
}

// Delete options.
delete_option( 'jarvis_content_engine_settings' );

