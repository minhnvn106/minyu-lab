<?php

namespace BetterLinks\API;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\Cache;
use BetterLinks\Traits\ArgumentSchema;

class Settings extends Controller {

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
		$endpoint = '/settings/';
		register_rest_route(
			$this->namespace,
			$endpoint,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_settings_schema(),
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
					'args'                => $this->get_settings_schema(),
				),
			)
		);
	}

	/**
	 * Get betterlinks
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Request
	 */
	public function get_items( $request ) {
		$response = Cache::get_json_settings();
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => json_encode( $response ),
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
				'success' => true,
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
		$helper   = new \BetterLinks\Helper();
		$response = $request->get_params();
		$response = $helper::sanitize_text_or_array_field( $response );

		$response['uncloaked_categories']      = isset( $response['uncloaked_categories'] ) && is_string( $response['uncloaked_categories'] ) ? json_decode( $response['uncloaked_categories'] ) : array();
		$response['affiliate_disclosure_text'] = isset( $response['affiliate_disclosure_text'] ) && is_string( $response['affiliate_disclosure_text'] ) ? $response['affiliate_disclosure_text'] : '';
		

		// Pro Logics
		$response = apply_filters( 'betterlinkspro/admin/update_settings', $response );

		// Compatibility: BetterLinks Pro before 3.0.4 relies on this to sanitize its
		// custom auto-link icon. Newer Pro sanitizes it in betterlinkspro/admin/update_settings.
		if ( ! empty( $response['autolink_custom_icon'] ) && \BetterLinks\Helper::pro_needs_update() ) {
			// Use the sanitize_custom_svg method if it exists, otherwise use custom wp_kses for SVG
			if ( class_exists( '\BetterLinksPro\Frontend\AutoLinks' ) && method_exists( '\BetterLinksPro\Frontend\AutoLinks', 'sanitize_custom_svg' ) ) {
				$response['autolink_custom_icon'] = \BetterLinksPro\Frontend\AutoLinks::sanitize_custom_svg( $response['autolink_custom_icon'] );
			} else {
				// Custom wp_kses with SVG allowed elements as fallback
				$allowed_svg = array(
					'svg' => array( 'class' => array(), 'width' => array(), 'height' => array(), 'viewbox' => array(), 'viewBox' => array(), 'fill' => array(), 'xmlns' => array() ),
					'path' => array( 'd' => array(), 'stroke' => array(), 'stroke-width' => array(), 'stroke-linecap' => array(), 'stroke-linejoin' => array(), 'fill' => array() ),
					'g' => array( 'fill' => array(), 'stroke' => array() ),
					'circle' => array( 'cx' => array(), 'cy' => array(), 'r' => array(), 'fill' => array(), 'stroke' => array() ),
					'rect' => array( 'x' => array(), 'y' => array(), 'width' => array(), 'height' => array(), 'fill' => array(), 'stroke' => array() ),
				);
				$response['autolink_custom_icon'] = wp_kses( $response['autolink_custom_icon'], $allowed_svg );
			}
		}

		if ( ! empty( $response['fbs']['enable_fbs'] ) ) {
			$category                  = ! empty( $response['fbs']['cat_id'] ) ? sanitize_text_field( $response['fbs']['cat_id'] ) : 1;
			$category                  = $helper::insert_new_category( $category );
			$response['fbs']['cat_id'] = $category;
		}

		update_option( BETTERLINKS_CUSTOM_DOMAIN_MENU, !empty( $response['enable_custom_domain_menu'] ) ? $response['enable_custom_domain_menu'] : false );

		$response = json_encode( $response );
		if ( $response ) {
			update_option( BETTERLINKS_LINKS_OPTION_NAME, $response );
			Cache::write_json_settings();
		}
		// regenerate links for wildcards option update
		$helper::clear_query_cache();
		$helper::write_links_inside_json();

		// Recompute which categories the dashboard hides under the settings that
		// were JUST saved. The SPA received this list at page load (via
		// wp_localize_script) and toggling "show the Link in Bio category" changes
		// it — without the fresh list in the response, the app keeps filtering the
		// category out until a hard reload, which read as "bio links never show".
		$hidden_term_ids = apply_filters( 'betterlinks/dashboard_hidden_term_ids', array(), (array) json_decode( $response, true ) );
		$hidden_term_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $hidden_term_ids ) ) ) );

		return new \WP_REST_Response(
			array(
				'success'         => true,
				'data'            => $response ? $response : array(),
				'hidden_term_ids' => $hidden_term_ids,
			),
			200
		);
	}

	/**
	 * Delete betterlinks
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|WP_REST_Request
	 */
	public function delete_item( $request ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(),
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
		return apply_filters( 'betterlinks/api/settings_get_items_permissions_check', current_user_can( 'manage_options' ) );
	}

	/**
	 * Check if a given request has access to update a setting
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 * @return WP_Error|bool
	 */
	public function permissions_check( $request ) {
		return apply_filters( 'betterlinks/api/settings_update_items_permissions_check', current_user_can( 'manage_options' ) );
	}
}
