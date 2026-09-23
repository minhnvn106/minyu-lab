<?php
namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Link\Utils;
use DeviceDetector\DeviceDetector;

class Link extends Utils {
	public function __construct() {
		// HEAD must resolve the same as GET: uptime monitors, link-preview crawlers
		// (Slack/WhatsApp/iMessage) and CDN health probes issue HEAD first, and if
		// it 404s they report the link as broken. The redirect path below sends the
		// status + Location via wp_redirect() and exits, so HEAD naturally gets the
		// headers with no body; dispatch_redirect() skips click tracking for HEAD.
		if ( ! is_admin() && isset( $_SERVER['REQUEST_METHOD'] ) && in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ), array( 'GET', 'HEAD' ), true ) ) {
			add_action( 'init', array( $this, 'run_redirect' ), 0 );
			add_action( 'betterlinks_quick_link_creation', array( $this, 'quick_link_creation' ) );
			add_action( 'betterlinks_prevent_unwanted_cle', array( $this, 'prevent_unwanted_cle' ) );
			// $this->run_redirect();
		}
	}

	/**
	 * Redirects short links to the destination url
	 */
	public function run_redirect() {
		// Quick Link Creation Functionality
		do_action( 'betterlinks_quick_link_creation' );

		// Note: Using sanitize_text_field for $_SERVER['REQUEST_URI'] may not handle redirects properly when short URLs contain non-ASCII characters (e.g., Chinese).
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$request_uri = stripslashes( rawurldecode( $request_uri ) );
		$request_uri = substr( $request_uri, strlen( wp_parse_url( site_url( '/' ), PHP_URL_PATH ) ) );
		$param       = explode( '?', $request_uri, 2 );

		// Never let a short link take over a WordPress system path (login, admin,
		// REST API, cron…), including links saved before slugs were validated.
		$pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';
		if ( 'wp-login.php' === $pagenow || Helper::is_reserved_wp_path( current( $param ) ) ) {
			return false;
		}

		$request_path = rtrim( current( $param ), '/' );
		$data         = $this->get_slug_raw( $request_path );

		// The previous request broke a redirect loop by sending the visitor here;
		// let WordPress serve this page instead of redirecting again.
		if ( ! empty( $data['target_url'] ) && $this->consume_loop_guard( $request_path ) ) {
			return false;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
		$dd         = new DeviceDetector( $user_agent );
		$dd->parse();

		$data['is_bot'] = $dd->isBot();
		if ( empty( $data['target_url'] ) || ! apply_filters( 'betterlinks/pre_before_redirect', $data ) ) {
			// password protection logics
			do_action( 'betterlinkspro/admin/check_password_protection', $request_uri, $data );

			if ( empty( $data['target_url'] ) || ! apply_filters( 'betterlinks/pre_before_redirect', $data ) ) { // phpcs:ignore
				return false;
			}
		}
		$data = apply_filters( 'betterlinks/link/before_dispatch_redirect', $data ); // phpcs:ignore.
		if ( empty( $data ) ) {
			return false;
		}

		// If this redirect lands on another short link that leads back here, mark
		// the landing page so it is served rather than redirected again.
		if ( ! empty( $data['target_url'] ) ) {
			$this->maybe_arm_loop_guard( $request_path, $data['target_url'] );
		}

		do_action( 'betterlinks/before_redirect', $data ); // phpcs:ignore.
		$this->dispatch_redirect( $data, next( $param ) );
	}

	/**
	 * Legacy Quick Link Creation transport: `?action=btl_cle&api_key=…` on any
	 * front-end URL.
	 *
	 * Kept for the Chrome extension and bookmarklets that predate the REST
	 * endpoint, but the credential itself has been replaced. The old key was
	 * `md5( AUTH_KEY )`: not bound to a user, never expiring, and only revocable
	 * by rotating a wp-config secret (which logs everyone out). It is now a
	 * plugin-issued token — see {@see \BetterLinks\CLEToken}.
	 *
	 * Prefer `POST /wp-json/betterlinks/v1/quick-link` with an
	 * `Authorization: Bearer` header, which keeps the credential out of the URL
	 * entirely (browser history, referrers, proxy and access logs).
	 *
	 * @return void
	 */
	public function quick_link_creation() {
		global $betterlinks_settings;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authenticated via the API token check below.
		if ( ! isset( $_GET['action'], $_GET['api_key'] ) ) {
			return;
		}

		if ( sanitize_text_field( wp_unslash( $_GET['action'] ) ) !== 'btl_cle' ) {
			return;
		}

		$presented = sanitize_text_field( wp_unslash( $_GET['api_key'] ) );
		$acting_user = $this->resolve_cle_user( $presented );

		if ( ! $acting_user ) {
			return;
		}

		$target_url = isset( $_GET['target_url'] ) ? sanitize_url( wp_unslash( $_GET['target_url'] ) ) : '';

		do_action( 'betterlinks_prevent_unwanted_cle' );
		$title = isset( $_GET['title'] ) ?  sanitize_text_field( wp_unslash( $_GET['title'] ) ) : ''; // geting title from document obj, instead of fetching
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( empty( $betterlinks_settings['cle']['enable_cle'] ) ) {
			return;
		}

		if ( empty( $title ) ) {
			$title = ( new Helper() )->fetch_target_url( $target_url );
		}

		if ( ! empty( $title ) ) {
			// Only assume the token owner's identity at the point of the write, and
			// only on a path that exits straight afterwards — never leave the rest
			// of an ordinary front-end request running as that user.
			$previous_user = get_current_user_id();
			wp_set_current_user( $acting_user );

			// Capability is resolved after the switch: Pro answers its delegated
			// role filter from the current user, so it cannot be evaluated for an
			// arbitrary id beforehand.
			if ( ! CLEToken::current_user_can_create() ) {
				wp_set_current_user( $previous_user );

				return;
			}

			$this->create_new_link( $title, $target_url, $betterlinks_settings );
		}
	}

	/**
	 * Resolve the user a legacy CLE request acts as.
	 *
	 * Accepts a plugin-issued token first. The deprecated `md5( AUTH_KEY )` value
	 * is only honoured while {@see CLEToken::legacy_key_allowed()} is true, which
	 * covers existing sites for one release and is off for everyone else.
	 *
	 * Returns the identity only — the capability check happens after the user
	 * switch, immediately before the write.
	 *
	 * @param string $presented Key from the query string.
	 * @return int User id, or 0 when the request is not authenticated.
	 */
	private function resolve_cle_user( $presented ) {
		if ( '' === $presented ) {
			return 0;
		}

		$record = CLEToken::verify( $presented );

		if ( $record ) {
			return (int) $record['user_id'];
		}

		if ( ! CLEToken::legacy_key_allowed() || ! defined( 'AUTH_KEY' ) ) {
			return 0;
		}

		if ( ! hash_equals( md5( AUTH_KEY ), $presented ) ) {
			return 0;
		}

		// The legacy key carries no user identity. Attribute the insert to the
		// site's oldest administrator rather than running with no user at all.
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}
}
