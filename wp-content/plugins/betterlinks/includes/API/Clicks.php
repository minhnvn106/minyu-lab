<?php
namespace BetterLinks\API;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Traits\ArgumentSchema;

class Clicks extends Controller {

	use \BetterLinks\Traits\Clicks;
	use ArgumentSchema;

	/**
	 * Initialize hooks and option name
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		$endpoint = '/clicks/';
		register_rest_route(
			$this->namespace,
			$endpoint,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			$endpoint . 'get_graphs/',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_graphs' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);


		register_rest_route(
			$this->namespace,
			$endpoint . 'get_audience/',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_audience' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);


		register_rest_route(
			$this->namespace,
			$endpoint . '(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.' , 'betterlinks' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			$endpoint . 'tags/' . '(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.' , 'betterlinks' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tags_analytics' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$endpoint . 'tags/get_graphs/' . '(?P<id>[\d]+)',
			array(
				'args' => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.' , 'betterlinks' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_tags_graph' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$endpoint,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$endpoint,
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$endpoint,
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$endpoint . 'delete_by_links/',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_links_analytics' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => $this->get_clicks_schema(),
				),
			)
		);

		do_action( 'betterlinks_register_clicks_routes', $this );
	}

	/**
	 * Get Analytics Graph Data
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_graphs( $request ) {
		$request = $request->get_params();
		$from    = $this->sanitize_date( $request['from'] ) ? $request['from'] : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to      = $this->sanitize_date( $request['to'] ) ? $request['to'] : gmdate( 'Y-m-d' );

		$graph_data = $this->get_analytics_graph_data( $from, $to );
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'clicks' => $graph_data,
				),
			)
		);
	}

	/**
	 * Audience composition for the range — human vs bot and new vs returning.
	 *
	 * Backs the two Overview stat cards. Each half carries a `tracked` flag so
	 * the client can tell "no bots seen" from "this data predates bot tracking",
	 * and `bots_blocked` reports the Disable Bot Clicks setting, under which bot
	 * hits are never recorded and the split would read 100% human.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_audience( $request ) {
		$request = $request->get_params();
		$from    = isset( $request['from'] ) && $this->sanitize_date( $request['from'] ) ? $request['from'] : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to      = isset( $request['to'] ) && $this->sanitize_date( $request['to'] ) ? $request['to'] : gmdate( 'Y-m-d' );

		$audience = $this->get_analytics_audience_data( $from, $to );

		$options                 = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );
		$audience['bots_blocked'] = ! empty( $options['disablebotclicks'] );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'audience' => $audience,
				),
			)
		);
	}

	/**
	 * Get analytics graph data by Tag ID
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_tags_graph( $request ) {
		$request = $request->get_params();
		$from    = $this->sanitize_date( $request['from']) ? $request['from'] : '';
		$to      = $this->sanitize_date( $request['to']) ? $request['to'] : '';
		$id      = isset( $request['id'] ) ? $request['id'] : '';

		if( empty( $from ) || empty( $to ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( "Invalid date range provided.", 'betterlinks' ),
				),
				400
			);
		}

		$results = $this->get_analytics_graph_data_by_tag( $from, $to, $id );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $results,
			),
			200
		);
	}

	/**
	 * Get unique analytics list by Tag ID
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_tags_analytics( $request ) {
		$request = $request->get_params();
		
		$from = $this->sanitize_date( $request['from'] ) ? $request['from'] : '';
		$to   = $this->sanitize_date( $request['to'] ) ? $request['to'] : '';
		$id   = isset( $request['id'] ) ? $request['id'] : '';

		if( empty( $from ) || empty( $to ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( "Invalid date range provided.", 'betterlinks' ),
				),
				400
			);
		}

		$results = $this->get_analytics_unique_list_by_tag( $from, $to, $id );

		$analytic = get_option( 'betterlinks_analytics_data' );
		$analytic = $analytic ? json_decode( $analytic, true ) : array();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'list'     => $results,
					'analytic' => $analytic,
				),
			),
			200
		);
	}

	/**
	 * Get betterlinks
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function get_items( $request ) {
		$request = $request->get_params();

		$from = isset($request['from']) && $this->sanitize_date( $request['from'] ) ? $request['from'] : '';
		$to = isset($request['to']) && $this->sanitize_date( $request['to'] ) ? $request['to'] : '';

		if( empty( $from ) || empty( $to ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'data' => [],
					'message' => __( "Invalid date range provided.", 'betterlinks' ),
				),
				400
			);
		}

		$unique_list = $this->get_analytics_unique_list( $from, $to );
		$unique_click_count = $this->get_unique_clicks_count($from, $to);

		// $analytic = get_option( 'betterlinks_analytics_data' );
		$analytic = $this->get_analytics_data($from, $to);
		$analytic = $analytic ? json_decode( $analytic, true ) : array();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'unique_list' => $unique_list,
					'unique_count' => $unique_click_count,
					'analytic'    => $analytic,
				),
			),
			200
		);
	}


	/**
	 * Get Individual Clicks
	 *
	 * @param WP_Rest_Request $request
	 * @return WP_Error|WP_Rest_Response
	 */
	public function get_item( $request ) {
		$request = $request->get_params();

		$id   = ! empty( $request['id'] ) ? sanitize_text_field( $request['id'] ) : null;
		$from = $this->sanitize_date( $request['from'] ) ? sanitize_text_field( $request['from'] ) : '';
		$to   = $this->sanitize_date( $request['to'] ) ? sanitize_text_field( $request['to'] ) : '';
		
		if( empty( $from ) || empty( $to ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( "Invalid date range provided.", 'betterlinks' ),
				),
				400
			);
		}

		$results      = $this->get_individual_analytics_clicks( $id, $from, $to );
		$link_details = $this->get_individual_link_details( $id );
		// The daily series is a plain count of this link's clicks, so free computes
		// it too; Pro may still replace it through the filter below.
		$graph_data = $this->get_individual_graph_data( $id, $from, $to );
		$graph_data = apply_filters( 'betterlinkspro/get_individual_graph_data', $graph_data, $id, $from, $to );

		return new \WP_REST_Response(
			array(
				'data' => array(
					'analytics'    => $results,
					'graph_data'   => $graph_data,
					'link_details' => $link_details,
				),
				'id'   => $id,
			),
			200
		);
	}

	/**
	 * Create OR Update betterlinks
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Request
	 */
	public function create_item( $request ) {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'data'    => array(),
			),
			200
		);
	}

	/**
	 * Create OR Update betterlinks
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Request
	 */
	public function update_item( $request ) {
		return new \WP_REST_Response(
			array(
				'success' => false,
				'data'    => array(),
			),
			200
		);
	}

	/**
	 * Delete betterlinks clicks
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Request
	 */
	public function delete_item( $request ) {
		$params = $request->get_params();

		$click_ids_raw = isset( $params['click_ids'] ) ? $params['click_ids'] : '';
		$link_id = isset( $params['link_id'] ) ? intval( $params['link_id'] ) : 0;

		// Sanitize and parse click IDs
		$click_ids = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( $click_ids_raw ) ) ) );

		if ( empty( $click_ids ) || empty( $link_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid click IDs or link ID provided.', 'betterlinks' ),
				),
				400
			);
		}

		// Get date range from params
		$from = isset( $params['from'] ) ? sanitize_text_field( $params['from'] ) : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to = isset( $params['to'] ) ? sanitize_text_field( $params['to'] ) : gmdate( 'Y-m-d' );

		// Delete the clicks from the database - only within the date range
		global $wpdb;
		$table_name = $wpdb->prefix . 'betterlinks_clicks';

		foreach ( $click_ids as $click_id ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table_name,
				array(
					'ID'      => intval( $click_id ),
					'link_id' => $link_id,
				),
				array( '%d', '%d' )
			);
		}

		// Clear all related transient caches for this link's analytics
		$transient_key = 'btl_individual_analytics_clicks_' . md5( $from . $to . $link_id );
		delete_transient( $transient_key );

		// Also clear graph data cache
		$graph_transient_key = 'btl_individual_graph_data_' . md5( $from . $to . $link_id );
		delete_transient( $graph_transient_key );

		// Clear all analytics caches to be safe
		\BetterLinks\Helper::clear_analytics_cache();

		// Update the betterlinks_analytics_data option with fresh data from database
		\BetterLinks\Helper::update_links_analytics();

		// Clear links cache so that page refresh shows updated analytics
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );

		$results = $this->get_individual_analytics_clicks( $link_id, $from, $to );
		$link_details = $this->get_individual_link_details( $link_id );
		$graph_data   = $this->get_individual_graph_data( $link_id, $from, $to );
		$graph_data = apply_filters( 'betterlinkspro/get_individual_graph_data', $graph_data, $link_id, $from, $to );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'analytics'    => $results,
					'graph_data'   => $graph_data,
					'link_details' => $link_details,
				),
				'id'      => $link_id,
			),
			200
		);
	}

	/**
	 * Check if a given request has access to update a setting
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function get_items_permissions_check( $request ) {
		return apply_filters( 'betterlinks/api/analytics_items_permissions_check', current_user_can( 'manage_options' ) );
	}
	/**
	 * Delete analytics for multiple links
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Response
	 */
	public function delete_links_analytics( $request ) {
		$params = $request->get_params();

		$link_ids_raw = isset( $params['link_ids'] ) ? $params['link_ids'] : '';

		// Sanitize and parse link IDs
		$link_ids = array_filter( array_map( 'intval', explode( ',', sanitize_text_field( $link_ids_raw ) ) ) );

		if ( empty( $link_ids ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid link IDs provided.', 'betterlinks' ),
				),
				400
			);
		}

		// Get date range from params
		$from = isset( $params['from'] ) ? sanitize_text_field( $params['from'] ) : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to = isset( $params['to'] ) ? sanitize_text_field( $params['to'] ) : gmdate( 'Y-m-d' );

		// Delete clicks for the specified links ONLY within the date range
		global $wpdb;
		$table_name = $wpdb->prefix . 'betterlinks_clicks';

		// $table_name is {$wpdb->prefix}betterlinks_clicks (wpdb-controlled); values are placeholdered.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		foreach ( $link_ids as $link_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table_name} WHERE link_id = %d AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
					intval( $link_id ),
					$from,
					$to
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Clear all related transient caches for all deleted links
		foreach ( $link_ids as $link_id ) {
			$transient_key = 'btl_individual_analytics_clicks_' . md5( $from . $to . $link_id );
			delete_transient( $transient_key );

			$graph_transient_key = 'btl_individual_graph_data_' . md5( $from . $to . $link_id );
			delete_transient( $graph_transient_key );
		}

		// Clear all analytics caches
		\BetterLinks\Helper::clear_analytics_cache();

		// Update the betterlinks_analytics_data option with fresh data from database
		\BetterLinks\Helper::update_links_analytics();

		// Clear links cache so that page refresh shows updated analytics
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );

		// Fetch updated analytics data for the link list
		$unique_list = $this->get_analytics_unique_list( $from, $to );
		$unique_click_count = $this->get_unique_clicks_count( $from, $to );
		$analytic = $this->get_analytics_data( $from, $to );
		$analytic = $analytic ? json_decode( $analytic, true ) : array();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'unique_list' => $unique_list,
					'unique_count' => $unique_click_count,
					'analytic'    => $analytic,
				),
			),
			200
		);
	}

	/**
	 * Check if a given request has access to update a setting
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}
}
