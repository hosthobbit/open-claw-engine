<?php
/**
 * Jobs REST controller.
 *
 * @package JarvisContentEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for content jobs and one-shot generation.
 */
class Jarvis_REST_Jobs_Controller extends Jarvis_REST_Base_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'jobs';

	/**
	 * Jobs table.
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
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/jobs',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_job' ),
					'permission_callback' => array( $this, 'permission_manage_jobs' ),
					'args'                => $this->get_job_args_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'permission_view_jobs' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/jobs/(?P<id>\d+)/run',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'run_job' ),
					'permission_callback' => array( $this, 'permission_manage_jobs' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/jobs/(?P<id>\d+)/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_job' ),
					'permission_callback' => array( $this, 'permission_manage_jobs' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/generate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_once' ),
					'permission_callback' => array( $this, 'permission_manage_jobs' ),
					'args'                => $this->get_job_args_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/publish',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate_and_publish' ),
					'permission_callback' => array( $this, 'permission_publish_jobs' ),
					'args'                => $this->get_job_args_schema(),
				),
			)
		);
	}

	/**
	 * Capability check for managing jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_manage_jobs( WP_REST_Request $request ) {
		return $this->authorize_request( $request, 'edit_posts' );
	}

	/**
	 * Capability check for viewing jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_view_jobs( WP_REST_Request $request ) {
		return $this->authorize_request( $request, 'edit_posts' );
	}

	/**
	 * Capability check for publishing jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission_publish_jobs( WP_REST_Request $request ) {
		return $this->authorize_request( $request, 'publish_posts' );
	}

	/**
	 * Create content job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_job( WP_REST_Request $request ) {
		$params = $this->prepare_job_params( $request );

		$job_id = $this->jobs_table->insert_job(
			array(
				'subject'      => $params['subject'],
				'status'       => 'scheduled',
				'scheduled_at' => $params['scheduled_at'],
				'logs_json'    => wp_json_encode( array( 'created_via' => 'api' ) ),
			)
		);

		if ( ! $job_id ) {
			return new WP_REST_Response(
				array(
					'error' => 'job_create_failed',
					'message' => __( 'Failed to create job.', 'jarvis-content-engine' ),
				),
				500
			);
		}

		return new WP_REST_Response(
			array(
				'id'      => $job_id,
				'status'  => 'scheduled',
				'subject' => $params['subject'],
			),
			201
		);
	}

	/**
	 * Get job status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_job( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$job = $this->jobs_table->get_job( $id );

		if ( ! $job ) {
			return new WP_REST_Response(
				array(
					'error'   => 'not_found',
					'message' => __( 'Job not found.', 'jarvis-content-engine' ),
				),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'id'           => (int) $job->id,
				'subject'      => $job->subject,
				'status'       => $job->status,
				'scheduled_at' => $job->scheduled_at,
				'generated_at' => $job->generated_at,
				'published_at' => $job->published_at,
				'post_id'      => $job->post_id ? (int) $job->post_id : null,
				'score'        => $job->score_json ? json_decode( $job->score_json, true ) : null,
				'logs'         => $job->logs_json ? json_decode( $job->logs_json, true ) : null,
			),
			200
		);
	}

	/**
	 * Force run a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run_job( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! class_exists( 'Jarvis_Content_Pipeline' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'pipeline_missing',
					'message' => __( 'Content pipeline is not available.', 'jarvis-content-engine' ),
				),
				500
			);
		}

		$pipeline = new Jarvis_Content_Pipeline();
		$result   = $pipeline->run_job( $id );

		return new WP_REST_Response( $result, isset( $result['status_code'] ) ? (int) $result['status_code'] : 200 );
	}

	/**
	 * Approve job for publish (draft workflow).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function approve_job( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! class_exists( 'Jarvis_Content_Pipeline' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'pipeline_missing',
					'message' => __( 'Content pipeline is not available.', 'jarvis-content-engine' ),
				),
				500
			);
		}

		$pipeline = new Jarvis_Content_Pipeline();
		$result   = $pipeline->approve_job( $id );

		return new WP_REST_Response( $result, isset( $result['status_code'] ) ? (int) $result['status_code'] : 200 );
	}

	/**
	 * One-shot generate + draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function generate_once( WP_REST_Request $request ) {
		if ( ! class_exists( 'Jarvis_Content_Pipeline' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'pipeline_missing',
					'message' => __( 'Content pipeline is not available.', 'jarvis-content-engine' ),
				),
				500
			);
		}

		$params   = $this->prepare_job_params( $request );
		$pipeline = new Jarvis_Content_Pipeline();
		$result   = $pipeline->generate_once( $params, false );

		return new WP_REST_Response( $result, isset( $result['status_code'] ) ? (int) $result['status_code'] : 201 );
	}

	/**
	 * One-shot generate + publish (restricted).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function generate_and_publish( WP_REST_Request $request ) {
		if ( ! class_exists( 'Jarvis_Content_Pipeline' ) ) {
			return new WP_REST_Response(
				array(
					'error'   => 'pipeline_missing',
					'message' => __( 'Content pipeline is not available.', 'jarvis-content-engine' ),
				),
				500
			);
		}

		$params   = $this->prepare_job_params( $request );
		$pipeline = new Jarvis_Content_Pipeline();
		$result   = $pipeline->generate_once( $params, true );

		return new WP_REST_Response( $result, isset( $result['status_code'] ) ? (int) $result['status_code'] : 201 );
	}

	/**
	 * Expected job params.
	 *
	 * @return array
	 */
	protected function get_job_args_schema() {
		return array(
			'subject'       => array(
				'type'        => 'string',
				'required'    => true,
				'description' => __( 'Subject or topic for the article.', 'jarvis-content-engine' ),
			),
			'primary_keyword' => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Primary SEO keyword.', 'jarvis-content-engine' ),
			),
			'secondary_keywords' => array(
				'type'        => 'array',
				'required'    => false,
				'items'       => array(
					'type' => 'string',
				),
				'description' => __( 'Secondary SEO keywords.', 'jarvis-content-engine' ),
			),
			'audience'      => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Target audience description.', 'jarvis-content-engine' ),
			),
			'intent'        => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Search intent (informational, transactional, etc.).', 'jarvis-content-engine' ),
			),
			'scheduled_at'  => array(
				'type'        => 'string',
				'required'    => false,
				'description' => __( 'Optional scheduled time (Y-m-d H:i:s).', 'jarvis-content-engine' ),
			),
		);
	}

	/**
	 * Normalize and validate job params.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected function prepare_job_params( WP_REST_Request $request ) {
		$subject  = sanitize_text_field( $request->get_param( 'subject' ) );
		$primary  = sanitize_text_field( (string) $request->get_param( 'primary_keyword' ) );
		$secondary = $request->get_param( 'secondary_keywords' );
		$audience = sanitize_text_field( (string) $request->get_param( 'audience' ) );
		$intent   = sanitize_text_field( (string) $request->get_param( 'intent' ) );
		$scheduled_at = $request->get_param( 'scheduled_at' );

		if ( empty( $subject ) ) {
			$subject = __( 'Untitled subject', 'jarvis-content-engine' );
		}

		if ( ! empty( $scheduled_at ) && strtotime( $scheduled_at ) ) {
			$scheduled_at = gmdate( 'Y-m-d H:i:s', strtotime( $scheduled_at ) );
		} else {
			$scheduled_at = current_time( 'mysql' );
		}

		return array(
			'subject'           => $subject,
			'primary_keyword'   => $primary,
			'secondary_keywords'=> is_array( $secondary ) ? array_map( 'sanitize_text_field', $secondary ) : array(),
			'audience'          => $audience,
			'intent'            => $intent,
			'scheduled_at'      => $scheduled_at,
		);
	}
}

