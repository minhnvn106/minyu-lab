<?php
namespace BetterLinks\Link;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Helper;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use BetterLinks\Traits\Links;
use BetterLinks\Traits\ArgumentSchema;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\OperatingSystem;
use DeviceDetector\Parser\Client\Browser;

class Utils {
	use Links;
	use ArgumentSchema;

	public function __construct() {
		AbstractDeviceParser::setVersionTruncation( AbstractDeviceParser::VERSION_TRUNCATION_NONE );
	}
	public function get_slug_raw( $slug ) {
		if ( BETTERLINKS_EXISTS_LINKS_JSON ) {
			return apply_filters( 'betterlinks/link/get_link_by_slug', Helper::get_link_from_json_file( $slug ) );
		}
		$link_options      = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME, '{}' ), true );
		$is_case_sensitive = isset( $link_options['is_case_sensitive'] ) ? $link_options['is_case_sensitive'] : false;
		$results           = current( Helper::get_link_by_short_url( $slug, $is_case_sensitive ) );
		if ( ! empty( $results ) ) {
			return apply_filters( 'betterlinks/link/get_link_by_slug', json_decode( wp_json_encode( $results ), true ) );
		}
		// wildcards.
		$links_option = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );
		if ( isset( $links_option['wildcards'] ) && $links_option['wildcards'] ) {
			$results = Helper::get_link_by_wildcards( 1 );
			if ( is_array( $results ) && count( $results ) > 0 ) {
				foreach ( $results as $key => $item ) {
					$postion = strpos( $item['short_url'], '/*' );
					if ( false !== $postion ) {
						$item_short_url_substr = substr( $item['short_url'], 0, $postion );
						$slug_substr           = substr( $slug, 0, $postion );
						if ( ! $is_case_sensitive ) {
							$item_short_url_substr = strtolower( $item_short_url_substr );
							$slug_substr           = strtolower( $slug_substr );
						}
						if ( $item_short_url_substr === $slug_substr ) {
							$target_postion = strpos( $item['target_url'], '/*' );
							if ( false !== $target_postion ) {
								$target_url         = str_replace( '/*', substr( $slug, $postion ), $item['target_url'] );
								$item['target_url'] = $target_url;
								return apply_filters( 'betterlinks/link/get_link_by_slug', json_decode( wp_json_encode( $item ), true ) );
							}
							return apply_filters( 'betterlinks/link/get_link_by_slug', json_decode( wp_json_encode( $item ), true ) );
						}
					}
				}
			}
		}
	}
	/**
	 * Cookie that tells the next request "BetterLinks sent you here to break a
	 * redirect loop — let WordPress serve this page".
	 */
	const LOOP_GUARD_COOKIE = 'betterlinks_loop_guard';

	/**
	 * Most short-link hops followed when looking for a loop.
	 */
	const LOOP_GUARD_MAX_HOPS = 5;

	/**
	 * Whether this request is the landing hop of a redirect loop that was broken
	 * on the previous request.
	 *
	 * The guard is cleared once WordPress actually renders the page, not here:
	 * WordPress may first canonical-redirect the landing URL (adding the
	 * trailing slash, say), and that follow-up request still has to be let
	 * through.
	 *
	 * Two links can point at each other's paths — `/category/x` → `/docs/y/` and
	 * `/docs/y` → `/category/x` — and each redirect then sends the visitor
	 * straight into the other one until the browser gives up with "too many
	 * redirects", so *both* URLs break. With the guard, whichever link the
	 * visitor starts from still redirects, and the page it lands on is served
	 * by WordPress instead of bouncing back.
	 *
	 * @param string $request_path Requested path, relative to the site root.
	 * @return bool
	 */
	public function consume_loop_guard( $request_path ) {
		if ( empty( $_COOKIE[ self::LOOP_GUARD_COOKIE ] ) ) {
			return false;
		}
		$guard = sanitize_text_field( wp_unslash( $_COOKIE[ self::LOOP_GUARD_COOKIE ] ) );
		if ( ! hash_equals( $guard, $this->loop_guard_hash( $request_path ) ) ) {
			return false;
		}
		// redirect_canonical() runs at template_redirect:10 and exits when it
		// redirects, so this only fires on the request that renders the page.
		add_action(
			'template_redirect',
			function () {
				$this->set_loop_guard_cookie( '', time() - HOUR_IN_SECONDS );
			},
			PHP_INT_MAX
		);
		return true;
	}

	/**
	 * Arm the loop guard when redirecting `$request_path` to `$target_url` would
	 * lead through other short links back to `$request_path`.
	 *
	 * Only a real loop arms it — a chain that ends on a normal page or an
	 * external URL is left alone.
	 *
	 * @param string $request_path Requested path, relative to the site root.
	 * @param string $target_url   Where this request is about to be redirected.
	 * @return void
	 */
	public function maybe_arm_loop_guard( $request_path, $target_url ) {
		$first_hop = $this->get_internal_path( $target_url );
		if ( null === $first_hop || ! $this->redirect_loops_back( $request_path, $first_hop ) ) {
			return;
		}
		$this->set_loop_guard_cookie( $this->loop_guard_hash( $first_hop ), time() + MINUTE_IN_SECONDS );
	}

	/**
	 * Follow short links from `$path` and report whether they come back to
	 * `$request_path`.
	 *
	 * @param string $request_path Path the visitor requested.
	 * @param string $path         First hop, relative to the site root.
	 * @return bool
	 */
	protected function redirect_loops_back( $request_path, $path ) {
		$origin = $this->normalize_loop_path( $request_path );
		// A link pointing at its own URL is already refused by dispatch_redirect().
		if ( $this->normalize_loop_path( $path ) === $origin ) {
			return false;
		}
		$seen = array( $origin );
		for ( $hop = 0; $hop < self::LOOP_GUARD_MAX_HOPS; $hop++ ) {
			$key = $this->normalize_loop_path( $path );
			if ( in_array( $key, $seen, true ) ) {
				return $key === $origin;
			}
			$seen[] = $key;
			$next   = $this->get_slug_raw( $path );
			if ( empty( $next['target_url'] ) || ! apply_filters( 'betterlinks/pre_before_redirect', $next ) ) { // phpcs:ignore
				return false;
			}
			$path = $this->get_internal_path( $next['target_url'] );
			if ( null === $path ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * The site-relative path of `$url` when it points at this site, else null.
	 *
	 * @param string $url
	 * @return string|null
	 */
	protected function get_internal_path( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return null;
		}
		if ( ! empty( $parts['host'] ) ) {
			$site_host = (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST );
			$strip_www = static function ( $host ) {
				return preg_replace( '/^www\./', '', strtolower( $host ) );
			};
			if ( $strip_www( $parts['host'] ) !== $strip_www( $site_host ) ) {
				return null;
			}
		}
		$path      = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '';
		$site_path = rtrim( (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ), '/' ) . '/';
		if ( '/' !== $site_path && 0 === strpos( $path, $site_path ) ) {
			$path = substr( $path, strlen( $site_path ) );
		}
		return trim( $path, '/' );
	}

	/**
	 * Normalise a path the way the redirect lookup matches it.
	 *
	 * @param string $path
	 * @return string
	 */
	protected function normalize_loop_path( $path ) {
		global $betterlinks;
		$path = trim( (string) $path, '/' );
		if ( is_array( $betterlinks ) && isset( $betterlinks['is_case_sensitive'] ) ) {
			$case_sensitive = ! empty( $betterlinks['is_case_sensitive'] );
		} else {
			$options        = json_decode( (string) get_option( BETTERLINKS_LINKS_OPTION_NAME, '{}' ), true );
			$case_sensitive = is_array( $options ) && ! empty( $options['is_case_sensitive'] );
		}
		return $case_sensitive ? $path : strtolower( $path );
	}

	/**
	 * @param string $path
	 * @return string
	 */
	protected function loop_guard_hash( $path ) {
		return md5( $this->normalize_loop_path( $path ) );
	}

	/**
	 * @param string $value
	 * @param int    $expires
	 * @return void
	 */
	protected function set_loop_guard_cookie( $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::LOOP_GUARD_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	public function dispatch_redirect( $data, $param ) {
		global $betterlinks;

		$comparable_url  = rtrim( preg_replace( '/https?\:\/\//', '', site_url( '/' ) ), '/' ) . '/' . $data['short_url'];
		$destination_url = rtrim( preg_replace( '/https?\:\/\//', '', $data['target_url'] ), '/' );
		$comparable_url  = rtrim( preg_replace( '/^www\.?/', '', $comparable_url ), '/' );
		$destination_url = rtrim( preg_replace( '/^www\.?/', '', $destination_url ), '/' );
		if ( ! $data || $comparable_url === $destination_url ) {
			return;
		}

		$target_url    = $this->addScheme( $data['target_url'] );
		$_query_params = array();
		wp_parse_str( $param, $_query_params );
		$data['pf'] = build_query( $_query_params );
		if ( filter_var( $data['param_forwarding'], FILTER_VALIDATE_BOOLEAN ) && ! empty( $param ) && $param !== $data['link_slug'] ) {
			$_target_url = wp_parse_url( $target_url );
			$target_url .= ( isset( $_target_url['query'] ) ? '&' : '?' ) . $data['pf'];
		}

		// A HEAD request is a metadata probe (uptime monitors, preview crawlers, CDN
		// health checks), not a real visit — resolve the redirect but never record a
		// click for it, otherwise those automated hits would inflate analytics.
		$is_head = isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore
		if ( ! $is_head && filter_var( $data['track_me'], FILTER_VALIDATE_BOOLEAN ) ) {
			$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
			$dd         = new DeviceDetector( $user_agent );
			$dd->parse();
	
			$data      = apply_filters( 'betterlinks/extra_tracking_data', $data, $dd );
	
			$data['os']      = OperatingSystem::getOsFamily( $dd->getOs( 'name' ) );
			$data['browser'] = Browser::getBrowserFamily( $dd->getClient( 'name' ) );
			$data['device']  = $dd->getDeviceName();

			// Record which hits were bots so Analytics can split human vs bot
			// traffic. Pro's extra-data tracking sets a richer bot_name via the
			// filter above; this fills it in on free, where the detector already
			// ran for the disablebotclicks check, so it costs nothing extra.
			if ( empty( $data['bot_name'] ) && $dd->isBot() ) {
				$bot              = $dd->getBot();
				$bot_name         = isset( $bot['name'] ) ? $bot['name'] : 'Unknown';
				$data['bot_name'] = substr( $bot_name, 0, 20 ); // column is VARCHAR(20)
			}

			if ( isset( $betterlinks['disablebotclicks'] ) && $betterlinks['disablebotclicks'] ) {
				if ( ! $dd->isBot() ) {
					$this->start_trakcing( $data );
				}
			} else {
				$this->start_trakcing( $data );
			}
		}
		

		$robots_tags = array();
		if ( filter_var( $data['sponsored'], FILTER_VALIDATE_BOOLEAN ) ) {
			$robots_tags[] = 'sponsored';
		}
		if ( filter_var( $data['nofollow'], FILTER_VALIDATE_BOOLEAN ) ) {
			$robots_tags[] = 'noindex';
			$robots_tags[] = 'nofollow';
		}
		if ( ! empty( $robots_tags ) ) {
			header( 'X-Robots-Tag: ' . implode( ', ', $robots_tags ), true );
		}

		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Cache-Control: post-check=0, pre-check=0', false );
		header( 'Expires: Mon, 26 Jul 1997 05:00:00 GMT' );
		header( 'Cache-Control: no-cache' );
		header( 'Pragma: no-cache' );
		/**
		 * Filters whether redirects send the X-Redirect-Powered-By header.
		 *
		 * @param bool  $send Default true.
		 * @param array $data Link data being redirected.
		 */
		if ( apply_filters( 'betterlinks/link/send_powered_by_header', true, $data ) ) {
			header( 'X-Redirect-Powered-By: https://www.betterlinks.io/' );
		}

		// phpcs:disable WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- BetterLinks redirects to the user-configured external target URL by design; wp_safe_redirect would block off-site URLs and break the plugin's core feature.
		switch ( $data['redirect_type'] ) {
			case '301':
				wp_redirect( esc_url_raw( $target_url ), 301 );
				exit;
			case '302':
				wp_redirect( esc_url_raw( $target_url ), 302 );
				exit;
			case '307':
				wp_redirect( esc_url_raw( $target_url ), 307 );
				exit;
			case 'cloak':
				do_action( 'betterlinks/make_cloaked_redirect', $target_url, $data );
				exit;
			default:
				wp_redirect( esc_url_raw( $target_url ) );
				exit;
		}
		// phpcs:enable WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	}

	public function start_trakcing( $data ) {
		global $betterlinks;
		$is_disable_analytics_ip = isset( $betterlinks['is_disable_analytics_ip'] ) ? $betterlinks['is_disable_analytics_ip'] : false;
		do_action( 'betterlinks/link/before_start_tracking', $data );
		$now            = current_time( 'mysql' );
		$now_gmt        = current_time( 'mysql', 1 );
		$visitor_cookie = 'betterlinks_visitor';
		// A visitor seen before sends the cookie back; anyone else is new and gets
		// one issued now. PHP does not add a cookie set during this request to
		// $_COOKIE, so keep the generated id in $visitor_id — reading the cookie
		// back here would store an empty visitor_id for every first-time visitor
		// and make them invisible to the new-vs-returning report.
		$is_new_visitor = ! isset( $_COOKIE[ $visitor_cookie ] );
		if ( $is_new_visitor ) {
			$visitor_cookie_expire_time = time() + 60 * 60 * 24 * 365; // 1 year
			$visitor_id                 = uniqid( 'bl' );
			setcookie( $visitor_cookie, $visitor_id, $visitor_cookie_expire_time, '/' );
		} else {
			$visitor_id = sanitize_text_field( wp_unslash( $_COOKIE[ $visitor_cookie ] ) );
		}
		// checking if split tes enabled.
		$is_split_enabled = apply_filters( 'betterlinkspro/admin/split_test_tracking', false, $data );

		$click_data = array(
			'link_id'             => $data['ID'],
			'browser'             => isset( $data['browser'] ) ? $data['browser'] : '',
			'os'                  => isset( $data['os'] ) ? $data['os'] : '',
			'device'              => isset( $data['device'] ) ? $data['device'] : '',
			'referer'             => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '', // phpcs:ignore
			'uri'                 => $data['link_slug'],
			'click_count'         => 0,
			'visitor_id'          => $visitor_id,
			// 1 = visitor's first click, 2 = a later one. Deliberately not 0:
			// every click written before this existed has 0, and those rows carry
			// a visitor_id too, so 0 has to keep meaning "unknown" or the report
			// would count all of that history as returning visitors.
			'click_order'         => $is_new_visitor ? 1 : 2,
			'created_at'          => $now,
			'created_at_gmt'      => $now_gmt,
			'rotation_target_url' => $data['target_url'],
			'target_url'          => $data['target_url'],
			'is_split_enabled'    => $is_split_enabled,
		);
		if ( ! $is_disable_analytics_ip ) {
			$IP                 = $this->get_current_client_IP();
			$click_data['ip']   = $IP;
			$click_data['host'] = $IP;
		}

		if ( apply_filters( 'betterlinks/is_extra_data_tracking_compatible', false ) ) {
			$query_params = apply_filters( 'betterlinkspro/admin/parameter_tracking_values', array(), $data );

			$click_data['brand_name']      = isset( $data['brand_name'] ) ? $data['brand_name'] : '';
			$click_data['model']           = isset( $data['model'] ) ? $data['model'] : '';
			$click_data['bot_name']        = isset( $data['bot_name'] ) ? $data['bot_name'] : '';
			$click_data['browser_type']    = isset( $data['browser_type'] ) ? $data['browser_type'] : '';
			$click_data['browser_version'] = isset( $data['browser_version'] ) ? $data['browser_version'] : '';
			$click_data['os_version']      = isset( $data['os_version'] ) ? $data['os_version'] : '';
			$click_data['language']        = isset( $data['language'] ) ? $data['language'] : '';
			$click_data['query_params']    = wp_json_encode( $query_params );
		}

		/**
		 * Filters a click row before it is stored. BetterLinks Pro adds country and user-agent data.
		 *
		 * @param array $click_data Click row.
		 * @param array $data       Link data used for tracking.
		 */
		$arg = apply_filters( 'betterlinks/link/insert_click_arg', $click_data, $data );

		if ( BETTERLINKS_EXISTS_CLICKS_JSON ) {
			$this->insert_json_into_file( BETTERLINKS_UPLOAD_DIR_PATH . '/clicks.json', $arg );
		} else {
			try {
				$click_id = Helper::insert_click( $arg );
				if ( ! empty( $click_id ) && $is_split_enabled ) {
					do_action( 'betterlinks/link/after_insert_click', $arg['link_id'], $click_id, $arg['target_url'] );
				}
			} catch ( \Throwable $th ) {
				// Never print internal errors on the public redirect path.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( 'BetterLinks: failed to record click: ' . $th->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}

	/**
	 * Resolve the visitor IP recorded against a click.
	 *
	 * This used to walk HTTP_CLIENT_IP / X-Forwarded / X-Forwarded-For /
	 * Forwarded ahead of REMOTE_ADDR and take whichever was set. Every one of
	 * those is a request header, so on a site that is not actually behind a
	 * reverse proxy any visitor could name their own IP on the public redirect
	 * path — the busiest unauthenticated entry point the plugin has. That let a
	 * caller inflate COUNT(DISTINCT ip) unique-click figures at will, walk
	 * straight past the `excluded_ips` analytics filter, and hand a fresh value
	 * to the per-IP country lookup on every single hit.
	 *
	 * The same walk was already replaced in
	 * ClientIp::get_current_client_ip() and in
	 * BetterLinksPro\Helper::get_current_client_ip(); this path was missed.
	 * Delegate to the same resolver so all three agree: REMOTE_ADDR by default,
	 * a forwarding header only when the peer is inside an operator-configured
	 * trusted-proxy range.
	 *
	 * The service returns null for private/reserved space because it will not
	 * geolocate it. Click tracking still wants that value — a LAN visitor is a
	 * real visitor — so fall back to REMOTE_ADDR rather than storing nothing.
	 *
	 * @return string Client IP, or '' when none can be established.
	 */
	public function get_current_client_IP() {
		if ( class_exists( '\\BetterLinks\\Services\\ClientIp' ) ) {
			$resolved = \BetterLinks\Services\ClientIp::get_current_client_ip();

			if ( ! empty( $resolved ) ) {
				return $resolved;
			}
		}

		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
			: '';

		// Never store something that is not an address: the column is read back
		// as an identity for unique-visitor counting and IP exclusion.
		return filter_var( $address, FILTER_VALIDATE_IP ) ? $address : '';
	}
	public function addScheme( $url, $scheme = 'http://' ) {
		// Protocol-relative ("//example.com/x") already names a host — only the
		// scheme is missing. Treating it as site-relative would send the visitor
		// to the wrong place entirely.
		if ( strpos( $url, '//' ) === 0 ) {
			return apply_filters( 'betterlinks/link/target_url', ( is_ssl() ? 'https:' : 'http:' ) . $url );
		}
		if ( strpos( $url, '/' ) === 0 ) {
			// site_url() already supplies the separating slash; concatenating it
			// with a leading-slash path produced "http://example.com//page/".
			return site_url( $url );
		}
		return apply_filters( 'betterlinks/link/target_url', wp_parse_url( $url, PHP_URL_SCHEME ) === null ? $scheme . $url : $url );
	}

	protected function insert_json_into_file( $file, $data ) {
		$existing_data = file_get_contents( $file );
		$temp_array    = (array) json_decode( $existing_data, true );
		array_push( $temp_array, $data );
		return file_put_contents( $file, wp_json_encode( $temp_array ) );
	}


	/**
	 * Create a Quick Link.
	 *
	 * @param string $title      Link title.
	 * @param string $target_url Destination.
	 * @param array  $settings   BetterLinks settings.
	 * @param bool   $render     When true (default) render the confirmation page
	 *                           and exit, preserving the legacy front-end
	 *                           behaviour. When false, return the created row so
	 *                           the REST endpoint can answer with JSON.
	 * @return array|false|void
	 */
	public function create_new_link( $title, $target_url, $settings, $render = true ) {
		$date             = wp_date( 'Y-m-d H:i:s' );
		$helper           = new Helper();
		$slug             = $helper->generate_random_slug();
		$prefix           = ! empty( $settings['prefix'] ) ? $settings['prefix'] . '/' : '';
		$nofollow         = ! empty( $settings['nofollow'] ) ? $settings['nofollow'] : null;
		$sponsored        = ! empty( $settings['sponsored'] ) ? $settings['sponsored'] : null;
		$track_me         = ! empty( $settings['track_me'] ) ? $settings['track_me'] : null;
		$param_forwarding = ! empty( $settings['param_forwarding'] ) ? $settings['param_forwarding'] : null;
		$powered_by       = ! empty( $settings['cle']['powered_by'] ) ? sanitize_text_field( $settings['cle']['powered_by'] ) : '';
		$short_url        = $prefix . $slug;

		$initial_values = array(
			'link_title'        => $title,
			'link_slug'         => $slug,
			'target_url'        => $target_url,
			'short_url'         => $short_url,
			'redirect_type'     => '307',
			'nofollow'          => $nofollow,
			'sponsored'         => $sponsored,
			'track_me'          => $track_me,
			'param_forwarding'  => $param_forwarding,
			'link_date'         => $date,
			'link_date_gmt'     => $date,
			'link_modified'     => $date,
			'link_modified_gmt' => $date,
			'cat_id'            => 1,
			'powered_by'        => $powered_by,
		);
		$initial_values = apply_filters( 'betterlinks_before_cle', $initial_values, $settings );

		$helper->clear_query_cache();
		$args    = $this->sanitize_links_data( $initial_values );
		$results = $this->insert_link( $args );

		if ( ! $render ) {
			if ( empty( $results ) ) {
				return false;
			}

			$created_short_url = ! empty( $results['short_url'] ) ? $results['short_url'] : $short_url;

			return array(
				'id'        => isset( $results['ID'] ) ? (int) $results['ID'] : 0,
				'short_url' => $created_short_url,
				'permalink' => site_url( $created_short_url ),
				'results'   => $results,
			);
		}

		if ( ! empty( $results ) ) {
			require_once BETTERLINKS_ROOT_DIR_PATH . '/includes/Views/create-link-externally.php';
			exit;
		}
		wp_safe_redirect( home_url() );
		exit;
	}

	public static function prevent_unwanted_cle() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$params = strpos($request_uri, 'action%3Dbtl_cle%26api_key');
		if ( !empty( $params ) ) { // to prevent short link creation of the 'Here is your BetterLinks' page
			$prevent_unwanted_click = true; // phpcs:ignore
			require_once BETTERLINKS_ROOT_DIR_PATH . '/includes/Views/create-link-externally.php';
			exit;
		}
	}
}
