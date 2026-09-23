<?php

namespace BetterLinks\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

use BetterLinks\CLEToken;
use BetterLinks\Helper;
use BetterLinks\Link;

/**
 * Quick Link Creation REST endpoint.
 *
 * The preferred transport for Quick Link Creation. Unlike the legacy front-end
 * `?action=btl_cle&api_key=…` GET, the credential travels in an
 * `Authorization: Bearer` header on a POST, so it never lands in browser
 * history, referrers, proxy logs, server access logs or a shared screenshot.
 */
class QuickLink {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private $namespace = BETTERLINKS_PLUGIN_SLUG . '/v1';

	/**
	 * The token record authenticated for this request.
	 *
	 * @var array|false
	 */
	private $token_record = false;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/quick-link',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'target_url' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'title'      => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Authenticate the caller.
	 *
	 * Accepts a bearer token issued by {@see CLEToken}. A logged-in user with the
	 * usual capability is also accepted (nonce-authenticated admin UI calls), so
	 * the endpoint works from the dashboard without minting a token.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permissions_check( $request ) {
		if ( is_user_logged_in() && CLEToken::current_user_can_create() ) {
			return true;
		}

		$token = $this->get_bearer_token( $request );

		if ( '' === $token ) {
			return new \WP_Error(
				'betterlinks_cle_no_credentials',
				__( 'A Quick Link Creation token is required.', 'betterlinks' ),
				array( 'status' => 401 )
			);
		}

		// Throttle credential guessing per peer before touching the token store.
		if ( ! $this->within_rate_limit() ) {
			return new \WP_Error(
				'betterlinks_cle_rate_limited',
				__( 'Too many requests.', 'betterlinks' ),
				array( 'status' => 429 )
			);
		}

		$record = CLEToken::authenticate( $token );

		if ( ! $record ) {
			return new \WP_Error(
				'betterlinks_cle_invalid_token',
				__( 'Invalid or expired Quick Link Creation token.', 'betterlinks' ),
				array( 'status' => 401 )
			);
		}

		$this->token_record = $record;

		return true;
	}

	/**
	 * Create the short link.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( $request ) {
		global $betterlinks_settings;

		if ( empty( $betterlinks_settings['cle']['enable_cle'] ) ) {
			return new \WP_Error(
				'betterlinks_cle_disabled',
				__( 'Quick Link Creation is disabled.', 'betterlinks' ),
				array( 'status' => 403 )
			);
		}

		$target_url = (string) $request->get_param( 'target_url' );
		$title      = (string) $request->get_param( 'title' );

		// Shortening an intranet or odd-port URL is legitimate, so the link itself
		// only needs to be a well-formed http(s) URL.
		$scheme = $target_url ? wp_parse_url( $target_url, PHP_URL_SCHEME ) : '';

		if ( '' === $target_url || ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return new \WP_Error(
				'betterlinks_cle_invalid_url',
				__( 'A valid http(s) target URL is required.', 'betterlinks' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( $title ) ) {
			// Only the server-side title fetch needs the stricter SSRF check;
			// fetch_target_url() uses wp_safe_remote_get() and enforces it again.
			if ( ! wp_http_validate_url( $target_url ) ) {
				return new \WP_Error(
					'betterlinks_cle_no_title',
					__( 'A title is required for this URL.', 'betterlinks' ),
					array( 'status' => 422 )
				);
			}

			$title = ( new Helper() )->fetch_target_url( $target_url );
		}

		if ( '' === trim( (string) $title ) ) {
			return new \WP_Error(
				'betterlinks_cle_no_title',
				__( 'Could not determine a title for the target URL.', 'betterlinks' ),
				array( 'status' => 422 )
			);
		}

		$link = ( new Link() )->create_new_link( $title, $target_url, $betterlinks_settings, false );

		if ( empty( $link ) ) {
			return new \WP_Error(
				'betterlinks_cle_create_failed',
				__( 'Could not create the short link.', 'betterlinks' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'id'         => isset( $link['id'] ) ? (int) $link['id'] : 0,
					'title'      => $title,
					'target_url' => $target_url,
					'short_url'  => $link['permalink'],
				),
			),
			201
		);
	}

	/**
	 * Extract the bearer token from the request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private function get_bearer_token( $request ) {
		$header = (string) $request->get_header( 'authorization' );

		if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		// Some Apache/CGI setups strip Authorization; WordPress mirrors it here.
		if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( '' === $header || ! preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	/**
	 * Per-peer throttle for token presentation.
	 *
	 * @return bool
	 */
	private function within_rate_limit() {
		$limit = (int) apply_filters( 'betterlinks/cle/rate_limit', 20 );

		if ( $limit <= 0 ) {
			return true;
		}

		$peer = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: 'unknown';

		return \BetterLinks\Services\RateLimiter::consume_bucket(
			'btl_cle_rl_' . md5( $peer ),
			$limit,
			MINUTE_IN_SECONDS
		);
	}
}
