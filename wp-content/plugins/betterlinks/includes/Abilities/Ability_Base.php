<?php
/**
 * Ability base class.
 *
 * @package BetterLinks\Abilities
 */

declare(strict_types=1);

namespace BetterLinks\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Base ability implementation for BetterLinks.
 *
 * Each BetterLinks MCP ability extends this class, providing an input/output
 * JSON schema and an execute() body. Abilities are registered with the
 * WordPress Abilities API and exposed to AI clients through the MCP server.
 *
 * Most abilities reuse BetterLinks' existing REST controllers by dispatching an
 * internal WP_REST_Request (see {@see self::dispatch()}) rather than
 * re-implementing link/term/analytics logic. That keeps a single source of
 * truth for validation, sanitization and side effects, and means server-side
 * fixes to the REST layer flow through to MCP automatically.
 */
abstract class Ability_Base {

	/**
	 * Minimum capability allowed for BetterLinks abilities.
	 */
	private const MIN_CAPABILITY = 'manage_options';

	/**
	 * REST namespace the internal dispatcher targets.
	 */
	protected const NS = 'betterlinks/v1';

	/**
	 * Unique ability identifier (e.g. `betterlinks/create-link`).
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	protected $label = '';

	/**
	 * Ability description.
	 *
	 * @var string
	 */
	protected $description = '';

	/**
	 * Ability category.
	 *
	 * @var string
	 */
	protected $category = 'betterlinks';

	/**
	 * Required WordPress capability.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Get the JSON Schema for ability input.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_input_schema();

	/**
	 * Get the JSON Schema for ability output.
	 *
	 * @return array<string, mixed>
	 */
	abstract public function get_output_schema();

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract public function execute( $input );

	/**
	 * Check whether abilities are enabled.
	 *
	 * @return bool
	 */
	public static function abilities_enabled() {
		return (bool) apply_filters( 'betterlinks_abilities_api_enabled', true );
	}

	/**
	 * Check whether this ability can be registered and executed.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) apply_filters( 'betterlinks_ability_enabled', self::abilities_enabled(), $this->id, $this );
	}

	/**
	 * Permission callback for the abilities API.
	 *
	 * @return bool
	 */
	public function permission_callback() {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		return current_user_can( $this->capability );
	}

	/**
	 * Enforce BetterLinks' current admin capability policy.
	 *
	 * @return bool
	 */
	public function meets_capability_policy() {
		return self::MIN_CAPABILITY === $this->capability;
	}

	/**
	 * Sanitize a slug while preserving forward slashes (e.g. "go/deal").
	 *
	 * WordPress core's sanitize_title() strips slashes, which breaks the
	 * multi-segment short URLs BetterLinks supports elsewhere. This mirrors the
	 * admin JS sanitizer at dev_betterlinks/utils/helper.js so slugs authored
	 * through MCP round-trip identically to slugs authored in wp-admin.
	 *
	 * @param string $raw
	 * @return string
	 */
	protected static function sanitize_slug_preserving_slashes( $raw ) {
		$s = strtolower( trim( (string) $raw ) );
		$s = preg_replace( '/\s+/', '-', $s );
		// Allow lowercase alphanumerics, hyphens, underscores, dots and forward
		// slashes. Dots and underscores are legal in a path and the link form
		// accepts them, so stripping them here turned "v2.0" into "v20" and let
		// a slug like "wp-login.php" through the reserved-path check as
		// "wp-loginphp" — a link MCP created could not be created in wp-admin.
		$s = preg_replace( '#[^a-z0-9\-_./]#', '', $s );
		// Collapse consecutive separators and strip edges.
		$s = preg_replace( '#/+#', '/', (string) $s );
		$s = preg_replace( '#-+#', '-', (string) $s );
		$s = trim( (string) $s, '-/.' );
		return (string) $s;
	}

	/**
	 * Read the configured link prefix from the BetterLinks settings option.
	 * Returns a trimmed prefix (no surrounding slashes) or an empty string.
	 *
	 * @return string
	 */
	protected static function get_configured_prefix() {
		$raw = get_option( BETTERLINKS_LINKS_OPTION_NAME );
		$settings = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		return isset( $settings['prefix'] ) ? trim( (string) $settings['prefix'], '/' ) : '';
	}

	/**
	 * Produce a full short_url from a raw slug: prepend the configured prefix
	 * unless the slug already begins with it. Never double-prefixes.
	 *
	 * @param string $slug Sanitized slug (may include additional slashes).
	 * @return string
	 */
	protected static function build_short_url( $slug ) {
		$slug   = trim( (string) $slug, '/' );
		$prefix = self::get_configured_prefix();
		if ( '' === $prefix || '' === $slug ) {
			return $slug;
		}
		if ( $slug === $prefix || 0 === strpos( $slug, $prefix . '/' ) ) {
			return $slug;
		}
		return $prefix . '/' . $slug;
	}

	/**
	 * Longest short_url an ability will store.
	 */
	protected const MAX_SHORT_URL_LENGTH = 255;

	/**
	 * Longest link title an ability will store.
	 */
	protected const MAX_TITLE_LENGTH = 300;

	/**
	 * Longest link note an ability will store.
	 */
	protected const MAX_NOTE_LENGTH = 2000;

	/**
	 * Link fields create-link and update-link both accept, beyond the basics.
	 *
	 * These are all writable in the link form; they were simply missing from the
	 * tool schemas, so an assistant could read them back on every link and never
	 * set one. `short_url` matters most: the admin UI lets you type the path
	 * directly, while MCP could only send a slug that the configured prefix was
	 * then glued onto — there was no way to create a link at the site root.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function shared_link_properties() {
		return [
			'short_url'        => [
				'type'        => 'string',
				'description' => __( 'The exact path the link answers on, e.g. "summer-sale" or "go/deal". Set this to control the path yourself: the configured link prefix is NOT added. Leave it out to build the path from link_slug and the prefix.', 'betterlinks' ),
			],
			'link_note'        => [
				'type'        => 'string',
				'description' => __( 'Private note about the link, shown only in the admin.', 'betterlinks' ),
			],
			'track_me'         => [
				'type'        => 'boolean',
				'description' => __( 'Record clicks for this link. Defaults to true.', 'betterlinks' ),
			],
			'param_forwarding' => [
				'type'        => 'boolean',
				'description' => __( 'Pass query parameters from the short URL on to the target URL.', 'betterlinks' ),
			],
			'favorite'         => [
				'type'        => 'boolean',
				'description' => __( 'Mark the link as a favorite so it is pinned in the links list.', 'betterlinks' ),
			],
			'uncloaked'        => [
				'type'        => 'boolean',
				'description' => __( 'Show the destination URL in the browser instead of the short URL (uncloaked). Acted on by BetterLinks Pro.', 'betterlinks' ),
			],
			'param_struct'     => [
				'type'        => 'object',
				'description' => __( 'Query parameters to append to the target URL, as a map of name to value.', 'betterlinks' ),
			],
			'tags'             => [
				'type'        => 'array',
				'items'       => [ 'type' => 'string' ],
				'description' => __( 'Tag names to attach to the link. Tags that do not exist yet are created. Replaces the link\'s current tags.', 'betterlinks' ),
			],
		];
	}

	/**
	 * Turn the shared properties above into link-table params.
	 *
	 * Only keys the caller actually sent are returned, so an update never
	 * overwrites a field that was not mentioned.
	 *
	 * @param array $input Ability input.
	 * @return array<string, mixed>
	 */
	protected static function shared_link_params( $input ) {
		$params = [];
		if ( isset( $input['link_note'] ) ) {
			$params['link_note'] = mb_substr( sanitize_text_field( (string) $input['link_note'] ), 0, self::MAX_NOTE_LENGTH );
		}
		if ( array_key_exists( 'track_me', $input ) ) {
			$params['track_me'] = rest_sanitize_boolean( $input['track_me'] ) ? '1' : '';
		}
		if ( array_key_exists( 'param_forwarding', $input ) ) {
			$params['param_forwarding'] = rest_sanitize_boolean( $input['param_forwarding'] ) ? '1' : '';
		}
		if ( array_key_exists( 'uncloaked', $input ) ) {
			$params['uncloaked'] = rest_sanitize_boolean( $input['uncloaked'] ) ? '1' : '';
		}
		if ( isset( $input['param_struct'] ) && is_array( $input['param_struct'] ) ) {
			$params['param_struct'] = array_map( 'sanitize_text_field', $input['param_struct'] );
		}
		if ( isset( $input['tags'] ) && is_array( $input['tags'] ) ) {
			$params['tags_id'] = self::resolve_tag_ids( $input['tags'] );
		}
		return $params;
	}

	/**
	 * Turn tag names into existing tag IDs where one already exists.
	 *
	 * insert_terms_and_terms_relationship() treats a non-numeric value as a NEW
	 * tag and stores the raw string as both name and slug, so "MCP Test Tag"
	 * created a second row beside an existing "mcp-test-tag". Match on the
	 * slugified name first, then on the name itself, and pass the ID through
	 * when either hits; only genuinely new names are left as strings to create.
	 *
	 * @param array $tags Tag names or IDs.
	 * @return array<int, int|string>
	 */
	protected static function resolve_tag_ids( array $tags ) {
		global $wpdb;
		$resolved = [];
		foreach ( $tags as $tag ) {
			if ( is_numeric( $tag ) ) {
				$resolved[] = absint( $tag );
				continue;
			}
			$name = sanitize_text_field( (string) $tag );
			if ( '' === $name ) {
				continue;
			}
			$slug = sanitize_title( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- term lookup by slug/name; no cache is keyed this way.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->prefix}betterlinks_terms WHERE term_type = 'tags' AND ( term_slug = %s OR term_name = %s OR term_slug = %s ) LIMIT 1",
					$slug,
					$name,
					$name
				)
			);
			if ( $existing ) {
				$resolved[] = absint( $existing );
				continue;
			}

			// Create it here rather than letting a bare name through:
			// insert_terms_and_terms_relationship() stores the raw string as BOTH
			// name and slug, so "QA Tag Reuse" landed with the slug "QA Tag
			// Reuse" and the next call asking for "qa-tag-reuse" matched nothing
			// and made a second row.
			$new_id = \BetterLinks\Helper::insert_term(
				[
					'term_name' => $name,
					'term_slug' => '' !== $slug ? $slug : $name,
					'term_type' => 'tags',
				]
			);
			$resolved[] = $new_id ? absint( $new_id ) : $name;
		}
		return $resolved;
	}

	/**
	 * Fill the on/off link fields a caller omitted from the site's own defaults,
	 * so a link made through a tool matches one made in the link form.
	 *
	 * @param array $input  Ability input.
	 * @param array $params Params being built.
	 * @return array
	 */
	protected static function apply_site_defaults( $input, $params ) {
		$raw      = get_option( BETTERLINKS_LINKS_OPTION_NAME );
		$settings = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
		if ( ! is_array( $settings ) ) {
			return $params;
		}
		foreach ( [ 'nofollow', 'sponsored', 'track_me', 'param_forwarding' ] as $key ) {
			if ( array_key_exists( $key, $input ) || ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$params[ $key ] = rest_sanitize_boolean( $settings[ $key ] ) ? '1' : '';
		}
		if ( ! isset( $input['redirect_type'] ) && ! empty( $settings['redirect_type'] ) ) {
			$params['redirect_type'] = (string) $settings['redirect_type'];
		}
		return $params;
	}

	/**
	 * Validate a caller-supplied destination URL.
	 *
	 * esc_url_raw() turns "not a url" into "http://not%20a%20url" and stores it
	 * happily, and blanks an unsupported scheme like javascript: — which then
	 * surfaced as "a target_url is required", pointing the caller at the wrong
	 * problem.
	 *
	 * @param string $raw Raw input.
	 * @return string|\WP_Error Sanitized URL.
	 */
	protected static function validate_target_url( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return new \WP_Error( 'betterlinks_missing_target', __( 'A target_url is required to create a link.', 'betterlinks' ), [ 'status' => 400 ] );
		}

		$url = esc_url_raw( $raw );
		if ( '' === $url ) {
			return new \WP_Error(
				'betterlinks_unsupported_scheme',
				__( 'Unsupported URL scheme in target_url. Use an http:// or https:// address.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}

		// A site-relative target ("/pricing/") is legitimate and has no host.
		if ( 0 === strpos( $url, '/' ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && ! empty( $parts['host'] ) ? $parts['host'] : '';
		if ( '' === $host || false === strpos( $host, '.' ) || false !== strpos( $url, '%20' ) ) {
			return new \WP_Error(
				'betterlinks_invalid_target',
				sprintf(
					/* translators: %s: the value that was passed */
					__( '"%s" is not a valid URL. Pass a full address such as https://example.com/page.', 'betterlinks' ),
					$raw
				),
				[ 'status' => 400 ]
			);
		}

		return $url;
	}

	/**
	 * Resolve the path a link should answer on.
	 *
	 * An explicit `short_url` wins and is used verbatim (minus surrounding
	 * slashes) — that is how a caller opts out of the configured prefix.
	 * Otherwise the prefix is applied to the slug as before.
	 *
	 * @param array  $input Ability input.
	 * @param string $slug  Sanitized slug.
	 * @return string|\WP_Error
	 */
	protected static function resolve_short_url( $input, $slug ) {
		if ( ! isset( $input['short_url'] ) || '' === trim( (string) $input['short_url'] ) ) {
			return self::build_short_url( $slug );
		}
		$short_url = self::sanitize_slug_preserving_slashes( (string) $input['short_url'] );
		// Every stored path is written into links.json, which the redirect
		// handler reads on every front-end request, so a nonsense path is not
		// just untidy — it is on the hot path of the whole site.
		if ( strlen( $short_url ) > self::MAX_SHORT_URL_LENGTH ) {
			return new \WP_Error(
				'betterlinks_short_url_too_long',
				sprintf(
					/* translators: %d: maximum number of characters */
					__( 'short_url is too long; keep it under %d characters.', 'betterlinks' ),
					self::MAX_SHORT_URL_LENGTH
				),
				[ 'status' => 400 ]
			);
		}
		if ( '' === $short_url ) {
			return new \WP_Error(
				'betterlinks_invalid_short_url',
				__( 'short_url produced an empty value after sanitization.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}
		return $short_url;
	}

	/**
	 * Store the favorite flag, which lives outside the links table write.
	 *
	 * @param int  $link_id  Link ID.
	 * @param bool $favorite Whether the link is a favorite.
	 * @return void
	 */
	protected function set_favorite( $link_id, $favorite ) {
		$link_id = absint( $link_id );
		if ( ! $link_id ) {
			return;
		}
		$this->dispatch(
			'PUT',
			'/links_favorite/' . $link_id,
			[
				'id'     => $link_id,
				'params' => [ 'favForAll' => (bool) $favorite ],
			]
		);
	}

	/**
	 * The stored link, in the shape get-link returns, for a create/update to
	 * answer with.
	 *
	 * The controller echoes back the payload it was handed, which is not the
	 * same thing: an update that touched only short_url came back with
	 * `tags_data: []` although the link kept its tags, and every response
	 * carried a stray `limit: 5` that is not part of any schema. Re-read the row
	 * instead, so what the caller sees is what the site holds.
	 *
	 * @param int $link_id Link ID.
	 * @return array<string, mixed>|null
	 */
	protected static function stored_link( $link_id ) {
		$link_id = absint( $link_id );
		if ( ! $link_id ) {
			return null;
		}
		$rows = \BetterLinks\Helper::get_link_by_ID( $link_id );
		$row  = is_array( $rows ) && ! empty( $rows ) ? (array) current( $rows ) : [];
		if ( empty( $row ) ) {
			return null;
		}

		$categories = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type( $link_id, 'category' );
		$tags       = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type( $link_id, 'tags' );

		$row['cat_data']  = ! empty( $categories ) ? $categories : [];
		$row['tags_data'] = ! empty( $tags ) ? $tags : [];
		$row['full_url']  = ! empty( $row['short_url'] ) ? trailingslashit( site_url() ) . ltrim( (string) $row['short_url'], '/' ) : '';
		// `favorite` is stored as a JSON string, absent, or an object depending on
		// the path that wrote it. Answer with a plain boolean.
		$favorite        = isset( $row['favorite'] ) ? $row['favorite'] : null;
		$favorite        = is_string( $favorite ) ? json_decode( $favorite, true ) : $favorite;
		$row['favorite'] = is_array( $favorite ) ? ! empty( $favorite['favForAll'] ) : (bool) $favorite;

		// Stored serialized; a caller should not have to know that.
		if ( isset( $row['param_struct'] ) && is_string( $row['param_struct'] ) && '' !== $row['param_struct'] ) {
			$decoded              = maybe_unserialize( $row['param_struct'] );
			$row['param_struct'] = is_array( $decoded ) ? $decoded : $row['param_struct'];
		}

		unset( $row['limit'] );

		return $row;
	}

	/**
	 * The `confirm` property every risky ability adds to its input schema.	/**
	 * The `confirm` property every risky ability adds to its input schema.
	 *
	 * @return array<string, mixed>
	 */
	protected static function confirm_property() {
		return [
			'type'        => 'boolean',
			'description' => __( 'Set true to carry out the action. Called without it, the tool changes nothing and returns a summary of what would happen, which must be shown to the user for them to accept or cancel.', 'betterlinks' ),
		];
	}

	/**
	 * Stop an irreversible or overwriting action until the caller has confirmed it.
	 *
	 * Tool annotations ask the *client* to prompt, which a client is free to
	 * ignore — and the reported case was exactly that: an assistant overwriting
	 * and deleting links without asking. This is the same gate on the server, so
	 * the action cannot run on the first call however the client behaves.
	 *
	 * Returns null once `confirm` is true (or when a site filters the gate off),
	 * otherwise the payload to hand straight back to the caller: what the action
	 * would do, and how to accept or cancel.
	 *
	 * @param array  $input   Ability input.
	 * @param string $action  Tool name, e.g. "delete-link".
	 * @param string $summary One line saying what will happen.
	 * @param array  $details Field => value rows shown to the user.
	 * @return array<string, mixed>|null
	 */
	protected function confirmation_gate( $input, $action, $summary, $details = [] ) {
		if ( ! empty( $input['confirm'] ) && true === rest_sanitize_boolean( $input['confirm'] ) ) {
			return null;
		}

		/**
		 * Filters whether MCP tools require explicit confirmation before a
		 * destructive or overwriting action. Return false to run them on the
		 * first call, as they did before.
		 *
		 * @param bool   $require Whether to require confirmation. Default true.
		 * @param string $action  Tool name.
		 * @param array  $input   Ability input.
		 */
		if ( ! apply_filters( 'betterlinks/mcp/require_confirmation', true, $action, $input ) ) {
			return null;
		}

		return [
			'success'               => false,
			'confirmation_required' => true,
			'action'                => $action,
			'summary'               => $summary,
			'details'               => $details,
			'accept'                => sprintf(
				/* translators: %s: tool name, e.g. "delete-link" */
				__( 'Show this summary to the user. If they accept, call %s again with the same arguments plus "confirm": true.', 'betterlinks' ),
				$action
			),
			'cancel'                => __( 'If they decline, stop here. Nothing has been changed.', 'betterlinks' ),
		];
	}

	/**
	 * MCP-compatible annotations for this ability. Override per ability.
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => false,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * Wrapper around execute() with action hooks.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute_wrapper( $input ) {
		do_action( 'betterlinks_before_ability_execute', $this->id, $input );

		$output = $this->execute( $input );

		do_action( 'betterlinks_after_ability_execute', $this->id, $input, $output );

		return $output;
	}

	/**
	 * Dispatch an internal WP_REST_Request against BetterLinks' own routes and
	 * return the response body, so abilities reuse the existing controllers.
	 *
	 * The request runs in-process: rest_do_request() still invokes the route's
	 * permission_callback (BetterLinks gates on `manage_options`), but skips the
	 * cookie-nonce check that only applies to real HTTP requests — which is
	 * correct here, because the MCP server has already set the current user to
	 * the admin who granted the credential.
	 *
	 * @param string               $method HTTP verb (GET/POST/PUT/DELETE).
	 * @param string               $route  Route beneath the namespace, e.g. `/links`.
	 * @param array<string, mixed> $params Query/body params (shape matches the controller).
	 * @return array<string, mixed>|\WP_Error Decoded `data` payload, or the WP_Error.
	 */
	protected function dispatch( string $method, string $route, array $params = [] ) {
		$request = new \WP_REST_Request( strtoupper( $method ), '/' . self::NS . $route );

		if ( 'GET' === strtoupper( $method ) ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body_params( $params );
		}

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			return $response->as_error();
		}

		$data = $response->get_data();

		// BetterLinks controllers answer `{ success, data }`; unwrap to the
		// meaningful payload, but tolerate controllers that return a bare array.
		if ( is_array( $data ) && array_key_exists( 'data', $data ) ) {
			$succeeded = isset( $data['success'] ) ? (bool) $data['success'] : true;

			// A refused write comes back as HTTP 200 with `success: false`, which
			// an MCP client reads as a successful call — the reported symptom was
			// a create that answered `{"success":false,"data":false}` and left the
			// caller guessing. Raise it as a real error instead, carrying the
			// controller's own reason when it gave one.
			if ( ! $succeeded ) {
				$reason  = is_array( $data['data'] ) ? $data['data'] : [];
				$code    = isset( $reason['code'] ) ? (string) $reason['code'] : 'betterlinks_request_failed';
				$message = isset( $reason['message'] ) && '' !== $reason['message']
					? (string) $reason['message']
					: __( 'BetterLinks refused the request and gave no reason.', 'betterlinks' );

				return new \WP_Error( $code, $message, [ 'status' => 400 ] );
			}

			return [
				'success' => $succeeded,
				'data'    => $data['data'],
			];
		}

		return [
			'success' => true,
			'data'    => $data,
		];
	}

	/**
	 * Register the ability with the WordPress Abilities API.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			$this->id,
			[
				'label'               => $this->label,
				'description'         => $this->description,
				'category'            => $this->category,
				'input_schema'        => $this->get_input_schema(),
				'output_schema'       => $this->get_output_schema(),
				'permission_callback' => [ $this, 'permission_callback' ],
				'execute_callback'    => [ $this, 'execute_wrapper' ],
				'meta'                => [
					// Tied to the MCP toggle. The Abilities API publishes every
					// registered ability at /wp-json/wp-abilities/v1/..., which
					// bypasses Mcp_Tools entirely — so with this hardcoded true,
					// switching MCP off left all the tools runnable by any
					// manage_options credential (an application password handed to
					// an AI agent, say), and the read-only connection scope, which
					// only exists on the MCP path, could be stepped around the same
					// way. Mcp_Tools reads the ability registry directly, so the
					// MCP server itself is unaffected by this flag.
					'show_in_rest' => \BetterLinks\Mcp\Mcp_Manager::is_enabled(),
					'annotations'  => $this->get_annotations(),
					'mcp'          => [
						'public' => false,
					],
				],
			]
		);
	}

	/**
	 * Get the ability ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}
}
