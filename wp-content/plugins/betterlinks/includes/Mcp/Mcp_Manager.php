<?php
/**
 * MCP manager — the site side of BetterLinks's MCP integration.
 *
 * The plugin speaks the MCP protocol DIRECTLY at this site's own URL. The
 * user pastes their own site's MCP endpoint + connection token into their AI
 * client — or, for OAuth-capable clients (claude.ai remote connectors), just
 * the URL:
 *
 *     https://thissite.com/betterlinks/mcp              (pretty, via rewrite)
 *     https://thissite.com/wp-json/betterlinks/v1/mcp   (always-on fallback)
 *
 * The MCP JSON-RPC handling lives in Mcp_Server; the tool surface is the
 * abilities registry (Mcp_Tools). Auth is the per-site connection token
 * (Mcp_Pairing) or an OAuth 2.1 access token (Mcp_OAuth).
 *
 * Admin-only management routes (manage_options) drive the MCP page:
 * /mcp/connection, /mcp/connect, /mcp/rotate, /mcp/disconnect.
 *
 * The token-only MCP + OAuth routes authenticate inside the handler (a
 * JSON-RPC 401 challenge, not a WP permission failure), so their
 * permission_callback is __return_true.
 *
 * A single admin toggle (`enable_mcp`) is the master switch: when off, the
 * MCP endpoint, discovery documents, and OAuth endpoints all refuse to serve.
 *
 * @package BetterLinks\Mcp
 */

declare(strict_types=1);

namespace BetterLinks\Mcp;

use BetterLinks\Admin\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers the MCP endpoint, OAuth discovery/authorize/token surface, and
 * the admin management routes.
 */
final class Mcp_Manager {

	/**
	 * REST namespace shared with the rest of the plugin.
	 */
	private const NS = 'betterlinks/v1';

	/**
	 * Query var flagging a pretty /betterlinks/mcp request.
	 */
	private const QUERY_VAR = 'betterlinks_mcp';

	/**
	 * Query var carrying the token when embedded in the URL path.
	 */
	private const TOKEN_QUERY_VAR = 'betterlinks_mcp_token';

	/**
	 * Query var flagging a /.well-known/ OAuth discovery request.
	 */
	private const WELLKNOWN_QUERY_VAR = 'betterlinks_mcp_wellknown';

	/**
	 * Query var flagging the browser-facing OAuth authorize page. This is
	 * served OUTSIDE the REST API on purpose: a REST route only honors cookie
	 * auth when a REST nonce accompanies it, but a browser arriving from
	 * wp-login carries the cookie with NO nonce — so is_user_logged_in()
	 * would be false there and the consent screen would loop back to login
	 * forever. A normal front-end URL (rewrite + parse_request) sees standard
	 * cookie auth, so the logged-in admin check works.
	 */
	private const AUTHORIZE_QUERY_VAR = 'betterlinks_mcp_authorize';

	/**
	 * Initialize (called by the plugin's component container).
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_rest' ] );

		// Pretty per-site endpoint: /betterlinks/mcp → MCP JSON-RPC handler.
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		add_action( 'parse_request', [ $this, 'maybe_handle_pretty_endpoint' ] );

		// The one broken state the server can't see from inside a request:
		// MCP enabled but the bundled Abilities runtime absent. Everything
		// else still works — OAuth discovers, tokens mint, clients connect —
		// and tools/list is an empty array served as success. Three layers
		// each "no-op gracefully" (the is_readable() require, the registrar,
		// the tool registry) and composed they manufacture a connector that
		// connects and offers nothing, with no signal anywhere. Say it loudly
		// where an admin will look.
		add_action( 'admin_notices', [ $this, 'warn_when_runtime_missing' ] );
	}

	/**
	 * Admin notice when MCP is enabled but the Abilities runtime is missing.
	 *
	 * That combination almost always means an incomplete package — a source
	 * archive or a zip built without `dependencies/vendor/` (the bundled
	 * Abilities API + MCP adapter). Shown to admins on every screen: the fix
	 * is reinstalling the plugin, and a user mid-support-ticket needs to see
	 * it without knowing which screen to visit.
	 *
	 * @return void
	 */
	public function warn_when_runtime_missing(): void {
		if ( ! self::is_enabled() || function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'BetterLinks MCP: AI assistants will connect but see no tools.', 'betterlinks' ),
			esc_html__( 'MCP access is enabled, but the bundled Abilities runtime (dependencies/vendor) is missing from this installation — usually a plugin package built without it. Reinstall BetterLinks from wordpress.org or an official build; until then, connected AI clients get an empty tool list.', 'betterlinks' )
		);
	}

	/**
	 * Whether the MCP integration is enabled via the admin setting.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$settings = Cache::get_json_settings();
		return is_array( $settings ) && ! empty( $settings['enable_mcp'] );
	}

	// -- Pretty endpoint: /betterlinks/mcp --

	/**
	 * Register rewrite rules for the MCP endpoint, OAuth discovery documents,
	 * and the browser-facing authorize page.
	 *
	 * @return void
	 */
	public function add_rewrite(): void {
		// Header-only. There used to be a /betterlinks/mcp/<token> form that
		// carried the credential in the URL path; a token there is written to
		// every access log, proxy and CDN it passes through, and nothing in the
		// UI offered it. Clients send `Authorization: Bearer <token>`.
		add_rewrite_rule( '^betterlinks/mcp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );

		// OAuth discovery documents. RFC 9728 §3.1 / RFC 8414 §3.1 place the
		// `.well-known` segment BEFORE the resource path, so our resource at
		// /betterlinks/mcp is discovered at the path-suffixed form:
		//   /.well-known/oauth-protected-resource/betterlinks/mcp
		//   /.well-known/oauth-authorization-server/betterlinks/mcp
		// The OAuth issuer is the path-based identifier home_url('/betterlinks/mcp')
		// (see Mcp_OAuth::issuer), so spec-compliant clients derive exactly
		// these URLs — and the rule stays specific to OUR path. That matters
		// for coexistence: another plugin serving its own MCP OAuth surface
		// (e.g. xSpeed) claims the generic `(?:/.*)?` root rule, and rewrite
		// rules are keyed by regex, so a shared broad rule would be silently
		// overwritten by whichever plugin registers last.
		add_rewrite_rule(
			'^\.well-known/oauth-(protected-resource|authorization-server)/betterlinks/mcp/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		// Root-form fallback for clients that only try the bare well-known
		// URL. Harmless when another plugin also registers this exact regex —
		// last registrant wins, and our clients use the path-suffixed form.
		add_rewrite_rule(
			'^\.well-known/oauth-(protected-resource|authorization-server)(?:/.*)?/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		// Suffix form: <issuer>/.well-known/... . RFC 8414 specifies the
		// path-INSERT form above, but the older OpenID Connect Discovery
		// convention appends instead, and clients built on an OIDC library
		// try that shape first (sometimes only that shape). Serving both
		// costs two rules and removes a whole class of "server does not
		// implement OAuth" failures from clients that never fall back.
		add_rewrite_rule(
			'^betterlinks/mcp/\.well-known/oauth-(protected-resource|authorization-server)/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^betterlinks/mcp/\.well-known/openid-configuration/?$',
			'index.php?' . self::WELLKNOWN_QUERY_VAR . '=authorization-server',
			'top'
		);

		// Browser-facing OAuth consent page — served OUTSIDE REST so cookie
		// auth (is_user_logged_in) works after the wp-login round-trip.
		add_rewrite_rule( '^betterlinks/authorize/?$', 'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1', 'top' );

		// Self-heal: flush once if ANY of our rules is missing from the stored
		// rewrite table, so the endpoints work without a manual permalink
		// re-save (and newly added rules trigger a re-flush on upgrade).
		$expected = [
			'^betterlinks/mcp/([a-f0-9]{64})/?$',
			'^betterlinks/mcp/?$',
			'^\.well-known/oauth-(protected-resource|authorization-server)/betterlinks/mcp/?$',
			'^betterlinks/mcp/\.well-known/oauth-(protected-resource|authorization-server)/?$',
			'^betterlinks/mcp/\.well-known/openid-configuration/?$',
			'^betterlinks/authorize/?$',
		];
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) ) {
			foreach ( $expected as $rule ) {
				if ( ! isset( $rules[ $rule ] ) ) {
					flush_rewrite_rules( false );
					break;
				}
			}
		}
	}

	/**
	 * Register our query vars.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::TOKEN_QUERY_VAR;
		$vars[] = self::WELLKNOWN_QUERY_VAR;
		$vars[] = self::AUTHORIZE_QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the MCP endpoint on the pretty path. Runs on parse_request so it
	 * fires before the main query, and short-circuits WP entirely.
	 *
	 * @param \WP $wp The WP request object.
	 * @return void
	 */
	public function maybe_handle_pretty_endpoint( $wp ): void {
		// OAuth discovery documents (served at the site root).
		if ( ! empty( $wp->query_vars[ self::WELLKNOWN_QUERY_VAR ] ) ) {
			// The root-form rule matches any /.well-known/oauth-* URL, including
			// one that belongs to another plugin or to a future core feature.
			// Answering 404 + exit there made BetterLinks break their discovery
			// even with MCP switched off, and with it on it handed a client
			// looking for someone else's authorization server our metadata.
			// Claim only requests for this site's own MCP resource; otherwise
			// fall through and let WordPress serve the URL as it normally would.
			if ( ! self::is_enabled() || ! self::wellknown_request_is_ours() ) {
				return;
			}
			$doc  = (string) $wp->query_vars[ self::WELLKNOWN_QUERY_VAR ];
			$data = 'authorization-server' === $doc
				? Mcp_OAuth::authorization_server_metadata()
				: Mcp_OAuth::protected_resource_metadata();
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
			// Discovery metadata is public + cacheable.
			header( 'Cache-Control: public, max-age=3600' );
			echo wp_json_encode( $data );
			exit;
		}

		// Browser-facing OAuth consent page (cookie auth applies here).
		if ( ! empty( $wp->query_vars[ self::AUTHORIZE_QUERY_VAR ] ) ) {
			if ( ! self::is_enabled() ) {
				status_header( 404 );
				exit;
			}
			$this->handle_authorize_page();
			return;
		}

		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$request = new \WP_REST_Request( 'POST', '/' . self::NS . '/mcp' );
		$request->set_header( 'content-type', 'application/json' );
		// Carry the auth header + raw body from the live PHP request.
		$auth = self::server_header( 'authorization' );
		if ( null !== $auth ) {
			$request->set_header( 'authorization', $auth );
		}
		$request->set_body( (string) file_get_contents( 'php://input' ) );

		$response = Mcp_Server::handle( $request );
		$this->emit_json( $response );
	}

	// -- REST registration --

	/**
	 * Register the REST routes: the MCP JSON-RPC fallback, the admin
	 * management routes, and the OAuth registration/token endpoints.
	 *
	 * @return void
	 */
	public function register_rest(): void {
		// --- MCP JSON-RPC endpoint (fallback path via wp-json) -----------
		// permission_callback is __return_true because Mcp_Server does its own
		// token auth and must reply with a JSON-RPC 401 + WWW-Authenticate,
		// not a bare WP permission failure.
		register_rest_route(
			self::NS,
			'/mcp',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_mcp' ],
				'permission_callback' => '__return_true',
			]
		);

		// Read-only integration health report backing the Connection health card.
		register_rest_route(
			self::NS,
			'/connection-status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_connection_status' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);

		// --- Admin-only management routes (the MCP page) ------------------
		register_rest_route(
			self::NS,
			'/mcp/connection',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_connection' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/connect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_connect' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'read_only' => [
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
						'description' => 'Grant read-only access (no link, term or settings changes).',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/rotate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_rotate' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'read_only' => [
						'type'        => 'boolean',
						'required'    => false,
						'description' => 'Optionally set read-only on the new token; omit to keep current scopes.',
					],
				],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_disconnect' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);

		// Live round-trip diagnostic for the MCP page (see #189). Admin-only;
		// exercises the endpoint the way an external client would.
		register_rest_route(
			self::NS,
			'/mcp/self-test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_self_test' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);

		// Connected AI apps (see #244): list the OAuth-connected clients plus a
		// single combined row for the shared static token, and revoke either.
		register_rest_route(
			self::NS,
			'/mcp/apps',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_apps' ],
				'permission_callback' => [ $this, 'admin_permission' ],
			]
		);
		register_rest_route(
			self::NS,
			'/mcp/apps/revoke',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_revoke_app' ],
				'permission_callback' => [ $this, 'admin_permission' ],
				'args'                => [
					'client_id' => [
						'type'        => 'string',
						'required'    => true,
						'description' => 'The OAuth client_id to revoke.',
					],
				],
			]
		);

		// --- OAuth 2.1 authorization server (the "paste a URL only" path) -
		// Discovery, dynamic client registration, and the token endpoint are
		// all public (permission enforced inside): a client must reach them
		// BEFORE it holds any credential.
		register_rest_route(
			self::NS,
			'/mcp/oauth/register',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_oauth_register' ],
				'permission_callback' => '__return_true',
			]
		);
		// NOTE: /authorize is deliberately NOT a REST route — it is served as
		// a normal front-end page at /betterlinks/authorize (see
		// handle_authorize_page) so cookie auth works after wp-login.
		register_rest_route(
			self::NS,
			'/mcp/oauth/token',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rest_oauth_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Capability gate for the admin-only management routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -- Handlers ----------------------------------------------------------

	/**
	 * MCP JSON-RPC over the wp-json fallback path.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function rest_mcp( \WP_REST_Request $request ): \WP_REST_Response {
		$response = Mcp_Server::handle( $request );
		// Advertise the MCP protocol version on the wp-json transport too, so
		// both endpoints behave identically to a strict Streamable-HTTP client.
		$response->header( 'MCP-Protocol-Version', Mcp_Server::PROTOCOL_VERSION );
		return $response;
	}

	/**
	 * GET /connection-status — the read-only health report.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_connection_status(): \WP_REST_Response {
		return rest_ensure_response( \BetterLinks\Diagnostics\Connection_Status::report() );
	}

	/**
	 * GET /mcp/connection — pairing status for the MCP page.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_connection(): \WP_REST_Response {
		$this->ensure_connected();
		return rest_ensure_response( Mcp_Pairing::public_status() );
	}

	/**
	 * Self-heal: whenever the admin views the MCP page with MCP enabled, make
	 * sure a connection token exists. New sites mint on the enable toggle (see
	 * #244), but a site that had MCP on before that behavior shipped would have
	 * no token; minting here — idempotent, admin-gated — keeps the connect
	 * recipes populated without a separate "Generate token" click.
	 *
	 * @return void
	 */
	private function ensure_connected(): void {
		if ( self::is_enabled() && ! Mcp_Pairing::is_connected() ) {
			Mcp_Pairing::connect();
		}
	}

	/**
	 * POST /mcp/connect — mint a connection token.
	 *
	 * @param \WP_REST_Request $request Carries optional read_only.
	 * @return \WP_REST_Response
	 */
	public function rest_connect( \WP_REST_Request $request ): \WP_REST_Response {
		$read_only = (bool) $request->get_param( 'read_only' );
		return rest_ensure_response( Mcp_Pairing::connect( $read_only ) );
	}

	/**
	 * POST /mcp/rotate — mint a fresh token, invalidating the old one.
	 *
	 * @param \WP_REST_Request $request Carries optional read_only.
	 * @return \WP_REST_Response
	 */
	public function rest_rotate( \WP_REST_Request $request ): \WP_REST_Response {
		$read_only = null;
		if ( null !== $request->get_param( 'read_only' ) ) {
			$read_only = (bool) $request->get_param( 'read_only' );
		}
		return rest_ensure_response( Mcp_Pairing::rotate( $read_only ) );
	}

	/**
	 * POST /mcp/disconnect — revoke the connection token + all OAuth grants.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_disconnect(): \WP_REST_Response {
		return rest_ensure_response( Mcp_Pairing::disconnect() );
	}

	/**
	 * POST /mcp/self-test — run the live round-trip diagnostic (see #189).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_self_test(): \WP_REST_Response {
		return rest_ensure_response( Mcp_Self_Test::run() );
	}

	/**
	 * GET /mcp/apps — the "Connected AI apps" list (see #244).
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_apps(): \WP_REST_Response {
		$this->ensure_connected();
		return rest_ensure_response( $this->apps_payload() );
	}

	/**
	 * POST /mcp/apps/revoke — cut off a single OAuth-connected app. Returns the
	 * refreshed app list so the UI updates in one round trip. (The shared static
	 * token has no per-client identity, so it is not listed or revoked here — it
	 * is rotated from the connect card via /mcp/rotate.)
	 *
	 * @param \WP_REST_Request $request Carries target + client_id.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_revoke_app( \WP_REST_Request $request ) {
		$client_id = (string) $request->get_param( 'client_id' );
		if ( '' === $client_id ) {
			return new \WP_Error(
				'betterlinks_missing_client_id',
				__( 'A client_id is required to revoke an OAuth app.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}
		Mcp_OAuth::revoke_client( $client_id );

		return rest_ensure_response( $this->apps_payload() );
	}

	/**
	 * Build the "Connected AI apps" payload: the OAuth-connected clients, with
	 * the approving admin's display name resolved. Header-based (static-token)
	 * clients share one anonymous secret and so are not represented here.
	 *
	 * @return array<string,mixed>
	 */
	private function apps_payload(): array {
		$oauth_apps = [];
		foreach ( Mcp_OAuth::connected_apps() as $app ) {
			$user         = $app['user_id'] > 0 ? get_userdata( $app['user_id'] ) : false;
			$oauth_apps[] = [
				'client_id'    => $app['client_id'],
				'name'         => $app['name'],
				'read_only'    => $app['read_only'],
				'approved_by'  => $user ? $user->display_name : __( 'Unknown user', 'betterlinks' ),
				'connected_at' => $app['connected_at'],
				'last_used'    => $app['last_used'],
			];
		}

		return [
			'oauth_apps' => $oauth_apps,
		];
	}

	// -- OAuth 2.1 handlers ------------------------------------------------

	/**
	 * POST /mcp/oauth/register — RFC 7591 dynamic client registration.
	 *
	 * @param \WP_REST_Request $request JSON body with redirect_uris.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_oauth_register( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return new \WP_Error( 'betterlinks_mcp_disabled', __( 'MCP is disabled on this site.', 'betterlinks' ), [ 'status' => 403 ] );
		}
		// Unauthenticated by design (a client registers before it has any
		// credential), and every call rewrites the whole client option. Without a
		// ceiling that is an anonymous write loop, and a flood past the client cap
		// evicts registrations that admins are part-way through approving.
		if ( Mcp_Rate_Limiter::is_locked() ) {
			return new \WP_Error(
				'betterlinks_mcp_rate_limited',
				__( 'Too many registration attempts. Try again later.', 'betterlinks' ),
				[ 'status' => 429 ]
			);
		}
		Mcp_Rate_Limiter::record_failure();
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = [];
		}
		$result = Mcp_OAuth::register_client( $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new \WP_REST_Response( $result, 201 );
	}

	/**
	 * POST /mcp/oauth/token — exchange a code (or refresh token) for tokens.
	 *
	 * @param \WP_REST_Request $request Form-encoded or JSON token request.
	 * @return \WP_REST_Response
	 */
	public function rest_oauth_token( \WP_REST_Request $request ): \WP_REST_Response {
		// Same reasoning as registration: unauthenticated, and each call reads and
		// rewrites the shared OAuth option.
		if ( self::is_enabled() && Mcp_Rate_Limiter::is_locked() ) {
			$response = new \WP_REST_Response(
				[
					'error'             => 'invalid_request',
					'error_description' => 'Too many attempts. Try again later.',
				],
				429
			);
			$response->header( 'Retry-After', (string) Mcp_Rate_Limiter::retry_after() );
			return $response;
		}
		if ( ! self::is_enabled() ) {
			$response = new \WP_REST_Response(
				[
					'error'             => 'invalid_request',
					'error_description' => 'MCP is disabled on this site.',
				],
				403
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}

		// Token requests are application/x-www-form-urlencoded per OAuth, but
		// accept JSON too. get_body_params() covers the form case.
		$body = $request->get_body_params();
		if ( empty( $body ) ) {
			$json = $request->get_json_params();
			$body = is_array( $json ) ? $json : [];
		}
		$body = array_map( 'strval', $body );

		$result = Mcp_OAuth::exchange_token( $body );
		if ( is_wp_error( $result ) ) {
			$data     = $result->get_error_data();
			$response = new \WP_REST_Response(
				[
					'error'             => isset( $data['error'] ) ? $data['error'] : 'invalid_request',
					'error_description' => isset( $data['error_description'] ) ? $data['error_description'] : $result->get_error_message(),
				],
				isset( $data['status'] ) ? (int) $data['status'] : 400
			);
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$response = new \WP_REST_Response( $result, 200 );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	// -- OAuth authorize page ------------------------------------------------

	/**
	 * The browser-facing OAuth authorize page (served at /betterlinks/authorize
	 * via a rewrite, NOT the REST API). Reads request params from the
	 * superglobals because this is a normal front-end request where cookie
	 * auth populates is_user_logged_in().
	 *
	 * GET renders the consent screen (requires a logged-in admin; anonymous
	 * users go to wp-login and return here). POST is the nonce-checked consent
	 * submission: Approve issues a code and 302s to the client's redirect_uri;
	 * Deny 302s back with error=access_denied. Always emits its own response
	 * (HTML page or redirect) and exits.
	 *
	 * @return void
	 */
	public function handle_authorize_page(): void {
		// The consent and error pages must never render inside a third-party frame
		// (clickjacking on the Approve button).
		if ( ! headers_sent() ) {
			send_frame_options_header();
			header( "Content-Security-Policy: frame-ancestors 'none'" );
		}

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
		// Params come from GET on the consent link and POST on the form submit.
		// Nonce is verified below before any POST value is acted on.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$source = $is_post ? $_POST : $_GET;
		// phpcs:enable
		$params = [];
		foreach ( [ 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'approve', 'deny', '_betterlinks_oauth_nonce' ] as $k ) {
			$params[ $k ] = isset( $source[ $k ] ) ? sanitize_text_field( wp_unslash( $source[ $k ] ) ) : '';
		}
		// OAuth requires `state` to round-trip byte-for-byte and sanitize_text_field()
		// can alter it. Treat it as opaque: strip control characters and cap the
		// length. It is only ever output through esc_attr() or rawurlencode().
		$params['state'] = isset( $source['state'] ) && is_string( $source['state'] )
			? substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', wp_unslash( $source['state'] ) ), 0, 1024 )
			: '';

		// Validate the OAuth params before touching the session.
		$req = Mcp_OAuth::validate_authorize_request( $params );
		if ( is_wp_error( $req ) ) {
			$data         = $req->get_error_data();
			$redirectable = is_array( $data ) && ! empty( $data['redirectable'] );
			// Only redirect the error back when redirect_uri is verified valid and
			// an administrator is present; otherwise show a page. Client
			// registration is public, so bouncing anonymous visitors to a
			// registered redirect_uri would be an open redirect.
			if ( $redirectable && '' !== $params['redirect_uri'] && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				$this->redirect_error( $params['redirect_uri'], $req->get_error_code(), $req->get_error_message(), $params['state'] );
			}
			$this->emit_oauth_error_page( $req->get_error_message() );
		}

		// Require a logged-in admin. Anonymous → wp-login, back to this URL.
		if ( ! is_user_logged_in() ) {
			$this->redirect_to_login();
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->emit_oauth_error_page(
				__( 'You must be an administrator to authorize an AI assistant to manage links on this site.', 'betterlinks' )
			);
		}

		// POST = consent form submitted.
		if ( $is_post ) {
			if ( ! wp_verify_nonce( $params['_betterlinks_oauth_nonce'], self::consent_nonce_action( $req ) ) ) {
				$this->emit_oauth_error_page( __( 'Security check failed. Please try connecting again.', 'betterlinks' ) );
			}
			if ( '' === $params['approve'] ) {
				$this->redirect_error( $req['redirect_uri'], 'access_denied', 'The user denied the request.', $req['state'] );
			}
			$code = Mcp_OAuth::issue_code( $req, get_current_user_id() );
			$this->redirect_success( $req['redirect_uri'], $code, $req['state'] );
		}

		// GET = render the consent screen.
		$this->emit_consent_screen( $req );
	}

	// -- OAuth browser-response helpers ------------------------------------

	/**
	 * The absolute URL of the current authorize request (for login return).
	 *
	 * @return string
	 */
	private function current_authorize_url(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reconstructing the current URL for a login round-trip; escaped at use.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return home_url( $uri );
	}

	/**
	 * Send an anonymous visitor to wp-login, returning to this authorize URL.
	 *
	 * @return void
	 */
	private function redirect_to_login(): void {
		wp_safe_redirect( wp_login_url( $this->current_authorize_url() ) );
		exit;
	}

	/**
	 * 302 back to the client with the authorization code (+ state).
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 * @param string $code         Authorization code.
	 * @param string $state        Client state.
	 * @return void
	 */
	private function redirect_success( string $redirect_uri, string $code, string $state ): void {
		$args = [ 'code' => $code ];
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		// Not wp_safe_redirect: redirect_uri is a client-registered off-site
		// callback, already validated against the client's registered set.
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/**
	 * 302 back to the client with an OAuth error (+ state).
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 * @param string $error        OAuth error code.
	 * @param string $description  Human-readable description.
	 * @param string $state        Client state.
	 * @return void
	 */
	private function redirect_error( string $redirect_uri, string $error, string $description, string $state ): void {
		$args = [
			'error'             => $error,
			'error_description' => $description,
		];
		if ( '' !== $state ) {
			$args['state'] = $state;
		}
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- validated OAuth redirect_uri.
		exit;
	}

	/**
	 * Render the consent screen. Minimal self-contained HTML (no admin
	 * chrome — this is a client-facing OAuth page). Approve/Deny post back
	 * to the same authorize URL with a nonce.
	 *
	 * @param array<string,string> $req Validated authorize params.
	 * @return void
	 */
	private function emit_consent_screen( array $req ): void {
		$read_only    = Mcp_OAuth::scope_is_read_only( $req['scope'] );
		$access_label = $read_only ? __( 'Read-only', 'betterlinks' ) : __( 'Read & write', 'betterlinks' );
		$access_desc  = $read_only
			? __( 'Review your links, categories, click analytics and settings. No changes are made.', 'betterlinks' )
			: __( 'Create and manage short links, categories, tags and settings, and read click analytics.', 'betterlinks' );
		$client     = '' !== $req['client_name'] ? $req['client_name'] : __( 'An AI assistant', 'betterlinks' );
		$action_url = Mcp_OAuth::authorize_url();
		$nonce      = wp_create_nonce( self::consent_nonce_action( $req ) );
		$user       = wp_get_current_user();

		// Preserve every OAuth param so the POST re-validates identically.
		$hidden = '';
		foreach ( [ 'client_id', 'redirect_uri', 'code_challenge', 'scope', 'state' ] as $k ) {
			$val     = 'scope' === $k ? $req['scope'] : ( $req[ $k ] ?? '' );
			$hidden .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( (string) $val ) );
		}
		// code_challenge_method + response_type are re-asserted for validation.
		$hidden .= '<input type="hidden" name="code_challenge_method" value="S256" />';
		$hidden .= '<input type="hidden" name="response_type" value="code" />';

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$logo = '<svg width="36" height="36" viewBox="1.14 4.62 38.75 38.75" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
			. '<path d="M28.7612 25.9606C28.7353 25.9606 28.6833 25.9606 28.6573 25.9606C27.8782 25.9346 27.125 25.4931 26.7354 24.7139C26.2679 23.7789 26.5276 22.6621 27.2808 22.0388C25.5926 22.8958 18.2684 26.506 16.6321 27.2852C16.5282 27.3371 16.4503 27.3631 16.3984 27.3891C15.3854 27.8566 14.866 29.0513 15.3075 30.1681C15.4114 30.4019 15.5413 30.6097 15.7231 30.8175C16.3204 31.4408 17.2035 31.6745 17.9827 31.4148C18.0346 31.3888 18.0866 31.3629 18.1645 31.3629L18.4762 31.233L19.1255 30.9733C19.2553 30.9213 29.4105 25.9346 29.4105 25.9346C29.5144 25.8827 29.5924 25.8567 29.6962 25.8048C29.4105 25.8827 29.0729 25.9606 28.7612 25.9606Z" fill="#FF1F1F"/>'
			. '<path d="M35.0715 16.2729C34.9936 14.8703 34.7339 13.5717 34.2923 12.377C34.0586 11.7537 33.7469 11.1563 33.3573 10.6109C32.9937 10.0914 32.6041 9.62391 32.1626 9.1564C30.8899 7.8318 29.3576 6.87082 27.5914 6.29943C25.4877 5.62414 23.332 5.62415 21.1763 6.27346C21.0983 6.29943 21.0204 6.3254 20.9165 6.35138C19.8776 6.68902 18.8907 7.23444 17.9297 7.9357C16.735 8.84473 15.7221 9.98752 14.9688 11.3381C14.4754 12.2212 14.1637 13.2081 14.0598 14.299C14.2156 13.7016 14.6312 13.1562 15.2286 12.8445C16.3454 12.2991 17.6959 12.7406 18.2414 13.8574C18.3712 14.1171 18.4492 14.3769 18.4751 14.6366C18.4751 14.5587 18.5011 14.4808 18.5011 14.4028C18.6829 13.1562 19.4361 12.1173 20.7867 11.2342C21.3061 10.8966 21.8515 10.6368 22.397 10.455C23.8254 9.98752 25.3318 10.0654 26.8382 10.6888C29.2277 11.6757 30.838 14.247 30.6822 16.8183C30.6043 17.987 30.3445 18.8961 29.8511 19.6753C29.3576 20.4804 28.7342 21.1297 28.007 21.5972C29.0459 21.2596 30.1887 21.7271 30.6822 22.74C31.2276 23.8309 30.7861 25.1554 29.6952 25.7268C31.9029 24.61 33.5911 22.6621 34.3703 20.2986C34.5001 19.9609 34.604 19.6493 34.6819 19.3116C35.0196 18.2987 35.1235 17.2858 35.0715 16.2729Z" fill="#AA02D3"/>'
			. '<path d="M30.7341 22.7398C30.2406 21.7269 29.0978 21.2854 28.0589 21.5971C27.955 21.623 27.8511 21.675 27.7472 21.7269C27.5914 21.8048 27.4356 21.9087 27.2797 22.0386C26.5265 22.6879 26.2668 23.7787 26.7343 24.7138C27.0979 25.467 27.8511 25.9345 28.6563 25.9604C28.6823 25.9604 28.7342 25.9604 28.7602 25.9604C29.0718 25.9604 29.4095 25.8825 29.7212 25.7267C29.7212 25.7267 29.7471 25.7267 29.7471 25.7007C30.838 25.1553 31.2795 23.8307 30.7341 22.7398Z" fill="#EF726C"/>'
			. '<path d="M18.4774 14.6626C18.4515 14.3249 18.3216 13.9873 18.1138 13.6756C17.5424 12.7926 16.3996 12.4289 15.4387 12.7926C14.7114 13.0783 14.2439 13.6497 14.0621 14.3249C13.9842 14.6366 13.9582 14.9743 14.0102 15.2859C14.0362 15.4937 14.1141 15.7015 14.218 15.9093C14.4517 16.3508 14.7894 16.6884 15.2049 16.9222C15.8283 17.2339 16.5814 17.2598 17.2308 16.9482C18.1138 16.4807 18.5813 15.5716 18.4774 14.6626Z" fill="#FF7878"/>'
			. '<path d="M25.725 17.6755C25.1796 16.5847 23.8809 16.1432 22.7901 16.6886C22.7122 16.7405 22.6083 16.7925 22.5304 16.8444L21.8811 17.1041C21.7512 17.1561 11.596 22.1428 11.596 22.1428C11.4921 22.1947 11.4142 22.2207 11.3103 22.2727C11.622 22.1168 11.9336 22.0389 12.2713 22.0389C12.2972 22.0389 12.3492 22.0389 12.3752 22.0389C13.1543 22.0649 13.9075 22.5064 14.2971 23.2856C14.7646 24.2206 14.5049 25.3374 13.7517 25.9608C15.4399 25.1037 22.7641 21.4935 24.3744 20.7143C24.5043 20.6883 24.6082 20.6364 24.7121 20.5844C25.8029 20.065 26.2444 18.7404 25.725 17.6755Z" fill="#2D2F54"/>'
			. '<path d="M5.95967 31.7266C6.03759 33.1291 6.29731 34.4277 6.73884 35.6224C6.97259 36.2458 7.28426 36.8431 7.67385 37.3886C8.03746 37.908 8.42705 38.3755 8.86858 38.843C10.1412 40.1676 11.6736 41.1286 13.4397 41.7C15.5435 42.3753 17.6992 42.3753 19.8549 41.726C19.9328 41.7 20.0108 41.674 20.1146 41.648C21.1535 41.3104 22.1405 40.765 23.1015 40.0637C24.2962 39.1547 25.3091 38.0119 26.0623 36.6613C26.5558 35.7783 26.8675 34.7913 26.9714 33.7005C26.8155 34.2978 26.4 34.8433 25.8026 35.1549C24.6858 35.7004 23.3352 35.2588 22.7898 34.142C22.6599 33.8823 22.582 33.6226 22.5561 33.3628C22.5561 33.4407 22.5301 33.5187 22.5301 33.5966C22.3483 34.8433 21.5951 35.8822 20.2445 36.7652C19.7251 37.1029 19.1796 37.3626 18.6342 37.5444C17.2057 38.0119 15.6993 37.934 14.1929 37.3106C11.8035 36.3237 10.1932 33.7524 10.349 31.1811C10.4269 30.0124 10.6867 29.1033 11.1801 28.3242C11.6736 27.519 12.2969 26.8697 13.0242 26.4022C11.9853 26.7398 10.8425 26.2723 10.349 25.2594C9.80359 24.1686 10.2451 22.844 11.336 22.2726C9.12831 23.3894 7.4401 25.3373 6.66093 27.7008C6.53106 28.0385 6.42717 28.3501 6.34925 28.6878C6.01161 29.7007 5.90772 30.7136 5.95967 31.7266Z" fill="#0847F9"/>'
			. '<path d="M14.2992 23.2856C13.9356 22.5324 13.1824 22.0649 12.3772 22.0389C12.3512 22.0389 12.2993 22.0389 12.2733 22.0389C11.9616 22.0389 11.624 22.1169 11.3123 22.2727C11.3123 22.2727 11.2864 22.2727 11.2864 22.2987C10.1955 22.8441 9.754 24.1687 10.2994 25.2855C10.7929 26.2984 11.9357 26.74 12.9746 26.4283C13.0785 26.4023 13.1824 26.3504 13.2862 26.2984C13.4421 26.2205 13.5979 26.1166 13.7537 25.9868C14.5069 25.3374 14.7667 24.2206 14.2992 23.2856Z" fill="#8761FF"/>'
			. '<path d="M27.0252 32.7395C26.9993 32.5317 26.9213 32.3239 26.8174 32.1161C26.5837 31.6746 26.2461 31.337 25.8305 31.1032C25.2072 30.7916 24.454 30.7656 23.8046 31.0772C22.9476 31.5188 22.48 32.4278 22.558 33.3369C22.5839 33.6745 22.7138 34.0121 22.9216 34.3238C23.493 35.2069 24.6358 35.5705 25.5967 35.2069C26.324 34.9212 26.7915 34.3498 26.9733 33.6745C27.0512 33.3888 27.0772 33.0512 27.0252 32.7395Z" fill="#25BF88"/>'
			. '<path opacity="0.12" d="M27.0252 32.7395C26.9993 32.5317 26.9213 32.3239 26.8174 32.1161C26.5837 31.6746 26.2461 31.337 25.8305 31.1032C25.2072 30.7916 24.454 30.7656 23.8046 31.0772C22.9476 31.5188 22.48 32.4278 22.558 33.3369C22.5839 33.6745 22.7138 34.0121 22.9216 34.3238C23.493 35.2069 24.6358 35.5705 25.5967 35.2069C26.324 34.9212 26.7915 34.3498 26.9733 33.6745C27.0512 33.3888 27.0772 33.0512 27.0252 32.7395Z" fill="#0847F9"/>'
			. '</svg>';
		$lock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';

		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Authorize AI access', 'betterlinks' ) . '</title>';
		echo '<style>'
			. ':root{color-scheme:dark}*{box-sizing:border-box}'
			. 'body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:radial-gradient(1100px 600px at 50% -15%,#20284a,#0b1020 62%);color:#e7ecf3;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}'
			. '.card{width:100%;max-width:460px;background:#141a2e;border:1px solid #263149;border-radius:20px;padding:28px;box-shadow:0 24px 60px rgba(0,0,0,.5)}'
			. '.brand{display:flex;align-items:center;gap:10px;margin-bottom:22px}'
			. '.logo{width:36px;height:36px;border-radius:11px;display:flex;align-items:center;justify-content:center;background:#fff;padding:5px;box-shadow:0 6px 16px rgba(0,0,0,.35)}'
			. '.brand b{font-size:14px;font-weight:700;letter-spacing:.02em}'
			. 'h1{font-size:20px;font-weight:700;margin:0 0 6px}'
			. '.sub{color:#9aa6be;font-size:13.5px;margin:0 0 22px}.sub strong{color:#e7ecf3;font-weight:600}'
			. '.rows{border:1px solid #263149;border-radius:14px;overflow:hidden;margin-bottom:16px}'
			. '.row{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:13px 16px;font-size:13.5px}'
			. '.row+.row,.access{border-top:1px solid #263149}'
			. '.row .k{color:#9aa6be}.row .v{font-weight:600;text-align:right;word-break:break-word}.row .v.warn{color:#fcd9a1}'
			. '.access{padding:14px 16px;background:rgba(99,102,241,.07)}'
			. '.access .k{color:#9aa6be;font-size:13px;margin-bottom:8px}'
			. '.badge{display:inline-flex;align-items:center;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;background:rgba(99,102,241,.18);color:#c7cbff;border:1px solid rgba(99,102,241,.4)}'
			. '.badge.ro{background:rgba(245,158,11,.15);color:#fcd9a1;border-color:rgba(245,158,11,.4)}'
			. '.access .d{color:#c3ccdd;font-size:13px;margin-top:8px}'
			. '.note{display:flex;align-items:center;gap:7px;color:#7f8aa3;font-size:12px;margin:0 0 20px}'
			. '.actions{display:flex;gap:12px}'
			. 'button{flex:1;padding:13px;border-radius:12px;border:0;font-size:14px;font-weight:600;cursor:pointer;transition:filter .15s,transform .05s}button:active{transform:translateY(1px)}'
			. '.approve{background:linear-gradient(135deg,#6366f1,#7c73ff);color:#fff;box-shadow:0 8px 20px rgba(99,102,241,.38)}.approve:hover{filter:brightness(1.07)}'
			. '.deny{background:transparent;color:#aeb8cc;border:1px solid #33405c}.deny:hover{background:rgba(255,255,255,.04)}'
			. '</style></head><body><div class="card">';

		echo '<div class="brand"><span class="logo">' . $logo . '</span><b>BetterLinks</b></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		echo '<h1>' . esc_html__( 'Connect to BetterLinks', 'betterlinks' ) . '</h1>';
		$sub = sprintf(
			/* translators: %s: AI client name, already escaped and wrapped in <strong>. */
			esc_html__( '%s wants to manage links on this site.', 'betterlinks' ),
			'<strong>' . esc_html( $client ) . '</strong>'
		);
		echo '<p class="sub">' . $sub . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static translation; client name esc_html'd.

		// Where the authorization code is about to be sent. Client registration is
		// public (RFC 7591) and `client_name` is whatever the registrant typed, so
		// the name alone tells the admin nothing — anyone can register "Claude"
		// pointing at their own callback and phish an approval. The callback host
		// is the one field an attacker cannot fake, so it belongs on the screen the
		// admin is being asked to approve.
		$callback_host = (string) wp_parse_url( $req['redirect_uri'], PHP_URL_HOST );
		if ( '' === $callback_host ) {
			// Native clients register a custom scheme with no host (myapp://cb).
			$callback_host = $req['redirect_uri'];
		}
		$offsite = '' !== $callback_host && strtolower( $callback_host ) !== strtolower( $host );

		echo '<div class="rows">';
		echo '<div class="row"><span class="k">' . esc_html__( 'Site', 'betterlinks' ) . '</span><span class="v">' . esc_html( $host ) . '</span></div>';
		echo '<div class="row"><span class="k">' . esc_html__( 'Signed in as', 'betterlinks' ) . '</span><span class="v">' . esc_html( $user->user_login ) . '</span></div>';
		echo '<div class="row"><span class="k">' . esc_html__( 'Sends access to', 'betterlinks' ) . '</span><span class="v' . ( $offsite ? ' warn' : '' ) . '">' . esc_html( $callback_host ) . '</span></div>';
		echo '<div class="access"><div class="k">' . esc_html__( 'Access', 'betterlinks' ) . '</div>';
		echo '<span class="badge ' . ( $read_only ? 'ro' : '' ) . '">' . esc_html( $access_label ) . '</span>';
		echo '<div class="d">' . esc_html( $access_desc ) . '</div></div>';
		echo '</div>';

		echo '<p class="note">' . $lock . '<span>' . esc_html__( 'Secured with OAuth. Revoke anytime in BetterLinks → MCP.', 'betterlinks' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static icon; text esc_html'd.

		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr() above.
		echo '<input type="hidden" name="_betterlinks_oauth_nonce" value="' . esc_attr( $nonce ) . '" />';
		echo '<div class="actions">';
		echo '<button class="deny" name="deny" value="1">' . esc_html__( 'Deny', 'betterlinks' ) . '</button>';
		echo '<button class="approve" name="approve" value="1">' . esc_html__( 'Approve', 'betterlinks' ) . '</button>';
		echo '</div></form></div></body></html>';
		exit;
	}

	/**
	 * Render a standalone OAuth error page (no redirect).
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	/**
	 * Whether the current /.well-known/oauth-* request is asking about this
	 * site's MCP resource, rather than some other plugin's.
	 *
	 * The bare form carries no resource path, so it stays ours: it is the
	 * fallback clients try when they cannot build the path-suffixed URL.
	 *
	 * @return bool
	 */
	private static function wellknown_request_is_ours(): bool {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never output.
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );

		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' === $home ? '' : '/' . trim( $home, '/' );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
		}

		if ( preg_match( '#^/\.well-known/oauth-(protected-resource|authorization-server)$#', $path ) ) {
			return true;
		}

		return (bool) preg_match(
			'#^/\.well-known/oauth-(protected-resource|authorization-server)/betterlinks/mcp$#',
			$path
		);
	}

	/**
	 * Nonce action bound to the specific OAuth request being approved.
	 *
	 * A single per-user action meant one nonce approved any client: whoever
	 * obtained it could swap in their own client_id and PKCE challenge and have
	 * the admin's approval apply to their registration instead. Binding the
	 * action to the client and challenge makes a nonce usable only for the
	 * request it was rendered for.
	 *
	 * @param array<string,string> $req Validated authorize request.
	 * @return string
	 */
	private static function consent_nonce_action( array $req ): string {
		return 'betterlinks_oauth_consent|' . ( $req['client_id'] ?? '' ) . '|' . ( $req['code_challenge'] ?? '' );
	}

	private function emit_oauth_error_page( string $message ): void {
		status_header( 400 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Authorization error', 'betterlinks' ) . '</title>';
		echo '<style>body{font:15px/1.5 -apple-system,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
			. '.card{background:#1e293b;border:1px solid #334155;border-radius:16px;max-width:440px;padding:32px;text-align:center}</style></head><body>';
		echo '<div class="card"><h1>' . esc_html__( 'Could not authorize', 'betterlinks' ) . '</h1><p>' . esc_html( $message ) . '</p></div></body></html>';
		exit;
	}

	// -- Helpers --

	/**
	 * Read an inbound HTTP header from $_SERVER (for the pretty path).
	 *
	 * @param string $name Header name.
	 * @return string|null
	 */
	private static function server_header( string $name ): ?string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- token compared constant-time downstream; raw header needed verbatim.
		return isset( $_SERVER[ $key ] ) ? wp_unslash( $_SERVER[ $key ] ) : null;
	}

	/**
	 * Emit a WP_REST_Response as a JSON HTTP response and stop.
	 *
	 * @param \WP_REST_Response $response Response to emit.
	 * @return void
	 */
	private function emit_json( \WP_REST_Response $response ): void {
		status_header( $response->get_status() );
		// MCP Streamable HTTP: advertise the protocol version we speak so a
		// strict client can pin it. We answer JSON (a spec-permitted response
		// type); we never open an SSE stream, so no session header is needed.
		header( 'MCP-Protocol-Version: ' . Mcp_Server::PROTOCOL_VERSION );
		// Forward any headers the handler set (notably WWW-Authenticate on a
		// 401, which drives the OAuth discovery flow).
		foreach ( $response->get_headers() as $name => $value ) {
			// Re-assert the status on every header: PHP special-cases
			// WWW-Authenticate and forces a 401 when no status is given,
			// which would silently mask the 429 lockout response.
			header( $name . ': ' . $value, true, $response->get_status() );
		}
		$data = $response->get_data();
		if ( null !== $data ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $data );
		}
		exit;
	}
}
