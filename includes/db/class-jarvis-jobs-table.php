<?php
/**
 * Jobs table handler.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles creation and access to the Jarvis content jobs table.
 */
class Jarvis_Jobs_Table {

	/**
	 * Table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'jarvis_content_jobs';

	/**
	 * Get full table name with $wpdb prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Create or update the jobs table.
	 *
	 * Columns:
	 *  - id BIGINT unsigned PK.
	 *  - subject text.
	 *  - status varchar(20).
	 *  - scheduled_at datetime.
	 *  - generated_at datetime.
	 *  - published_at datetime.
	 *  - score_json longtext.
	 *  - logs_json longtext.
	 *  - post_id bigint.
	 */
	public function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			subject TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			scheduled_at DATETIME NULL,
			generated_at DATETIME NULL,
			published_at DATETIME NULL,
			score_json LONGTEXT NULL,
			logs_json LONGTEXT NULL,
			post_id BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY scheduled_at (scheduled_at),
			KEY post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Insert a job.
	 *
	 * @param array $data Job data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function insert_job( array $data ) {
		global $wpdb;

		$table_name = self::get_table_name();

		$defaults = array(
			'subject'      => '',
			'status'       => 'pending',
			'scheduled_at' => current_time( 'mysql' ),
			'generated_at' => null,
			'published_at' => null,
			'score_json'   => null,
			'logs_json'    => null,
			'post_id'      => null,
		);

		$data = wp_parse_args( $data, $defaults );

		$inserted = $wpdb->insert(
			$table_name,
			array(
				'subject'      => $data['subject'],
				'status'       => $data['status'],
				'scheduled_at' => $data['scheduled_at'],
				'generated_at' => $data['generated_at'],
				'published_at' => $data['published_at'],
				'score_json'   => $data['score_json'],
				'logs_json'    => $data['logs_json'],
				'post_id'      => $data['post_id'],
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
			)
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a job.
	 *
	 * @param int   $id Job ID.
	 * @param array $data Data to update.
	 * @return bool
	 */
	public function update_job( $id, array $data ) {
		global $wpdb;

		$id         = (int) $id;
		$table_name = self::get_table_name();

		if ( $id <= 0 ) {
			return false;
		}

		$formats = array();
		$values  = array();

		$allowed = array(
			'subject'      => '%s',
			'status'       => '%s',
			'scheduled_at' => '%s',
			'generated_at' => '%s',
			'published_at' => '%s',
			'score_json'   => '%s',
			'logs_json'    => '%s',
			'post_id'      => '%d',
		);

		foreach ( $allowed as $key => $format ) {
			if ( array_key_exists( $key, $data ) ) {
				$values[ $key ] = $data[ $key ];
				$formats[]      = $format;
			}
		}

		if ( empty( $values ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$table_name,
			$values,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Get a job by ID.
	 *
	 * @param int $id Job ID.
	 * @return object|null
	 */
	public function get_job( $id ) {
		global $wpdb;

		$id         = (int) $id;
		$table_name = self::get_table_name();

		if ( $id <= 0 ) {
			return null;
		}

		$query = $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", $id ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get recent jobs for admin logs.
	 *
	 * @param int $limit Number of jobs.
	 * @return array
	 */
	public function get_recent_jobs( $limit = 50 ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$limit      = (int) $limit;

		if ( $limit <= 0 ) {
			$limit = 50;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query = "SELECT * FROM {$table_name} ORDER BY id DESC LIMIT " . $limit;

		return $wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete table on uninstall if requested.
	 */
	public function drop_table() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
