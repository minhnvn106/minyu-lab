<?php
/**
 * Abilities registrar.
 *
 * @package BetterLinks\Abilities
 */

declare(strict_types=1);

namespace BetterLinks\Abilities;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Registers BetterLinks abilities with the WordPress Abilities API.
 *
 * Abilities are **always** registered when the Abilities API is available —
 * registration is decoupled from the `enable_mcp` toggle (see #187). Each
 * ability is a permission-checked read/write surface (its own
 * `current_user_can()` callback runs on every call), so registration alone
 * exposes nothing; it only makes BetterLinks discoverable to generic WordPress
 * Abilities clients (e.g. an external connector) the same way WordPress core
 * abilities are. The `enable_mcp` toggle gates only BetterLinks's own MCP server
 * ({@see \BetterLinks\Mcp\Mcp_Manager}) — the endpoint, discovery documents, and
 * OAuth surface. The MCP server reads this abilities registry as its tool
 * catalog. The Abilities API itself ships in `dependencies/` and no-ops
 * gracefully when missing.
 *
 * A kill switch remains available via the `betterlinks_abilities_api_enabled`
 * filter (defaults to `true`; see {@see Ability_Base::abilities_enabled()}).
 */
class Abilities_Registrar {

	/**
	 * Ability-name prefixes that mark an ability as BetterLinks's. The free
	 * plugin owns `betterlinks/`; Pro registers under `betterlinks-pro/` via the
	 * `betterlinks_register_abilities` filter.
	 */
	public const ABILITY_PREFIXES = [ 'betterlinks/', 'betterlinks-pro/' ];

	/**
	 * Whether the registration replay (see {@see self::ensure_registered()})
	 * has already run this request. One attempt only — a replay that produced
	 * nothing will not produce anything on the second try either, and the MCP
	 * server asks for the tool list more than once per request.
	 *
	 * @var bool
	 */
	private static $replayed = false;

	/**
	 * Initialize the registrar (called by the plugin's component container).
	 *
	 * Deliberately does NOT bail on a missing `wp_register_ability`: the
	 * Abilities API is a set of GLOBAL functions loaded under
	 * `function_exists` guards, so which copy owns them — ours in
	 * `dependencies/`, another plugin's, or core's — is decided by load order,
	 * not by us. A copy that lands after `plugins_loaded` would have made this
	 * an early return and left BetterLinks permanently unregistered (see #241).
	 * The callbacks themselves are guarded instead, so hooking unconditionally
	 * is free when no Abilities API ever shows up.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	/**
	 * Guarantee BetterLinks's abilities are in the registry, replaying
	 * registration once if they are not.
	 *
	 * `wp_abilities_api_init` fires exactly once, from the lazy registry
	 * singleton of whichever Abilities API copy owns the globals. If a foreign
	 * copy owns them and fires its init under a different name or at a moment
	 * when our hook is not attached yet, our callback never runs: the registry
	 * is populated by everyone else and `tools/list` answers with an empty
	 * array while auth, discovery and `initialize` all report success (#241).
	 *
	 * Calling `wp_get_abilities()` here forces that lazy init, so by the time
	 * we decide to replay, `wp_abilities_api_init` has fired and
	 * `wp_register_ability()` will accept our registrations. Registration is
	 * idempotent (each ability is skipped when `wp_has_ability()` already
	 * knows it), so a replay after a *successful* hook run is a no-op anyway.
	 *
	 * @param callable|null $replay Optional replay routine, for tests. Default
	 *                              is this class's own category + abilities
	 *                              registration.
	 * @return int Number of BetterLinks abilities registered afterwards.
	 */
	public static function ensure_registered( ?callable $replay = null ): int {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		// Forces the registry's lazy init (and with it `wp_abilities_api_init`).
		$count = self::count_registered();
		if ( $count > 0 || self::$replayed ) {
			return $count;
		}

		// Before the registry has initialized, `wp_register_ability()` refuses
		// the registration and calls `_doing_it_wrong()`. Nothing to replay yet.
		if ( ! function_exists( 'did_action' ) || ! did_action( 'wp_abilities_api_init' ) ) {
			return $count;
		}

		self::$replayed = true;

		if ( ! Ability_Base::abilities_enabled() ) {
			return 0;
		}

		if ( null === $replay ) {
			$registrar = new self();
			$replay    = static function () use ( $registrar ) {
				$registrar->register_category();
				$registrar->register_abilities();
			};
		}

		$replay();

		$count = self::count_registered();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic.
				'[BTL-MCP] BetterLinks abilities were missing from the registry; replayed registration. ' . self::summary()
			);
		}

		return $count;
	}

	/**
	 * How many BetterLinks abilities the registry currently holds.
	 *
	 * @return int
	 */
	public static function count_registered(): int {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return 0;
		}

		$count = 0;
		foreach ( wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			foreach ( self::ABILITY_PREFIXES as $prefix ) {
				if ( 0 === strpos( (string) $ability->get_name(), $prefix ) ) {
					++$count;
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Which file defines the global Abilities API functions for this request.
	 * A path outside BetterLinks's `dependencies/` means a foreign copy owns the
	 * registry — the precondition for #241.
	 *
	 * @return string Absolute path, or '' when the API is absent/unresolvable.
	 */
	public static function owner_path(): string {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return '';
		}

		try {
			$reflection = new \ReflectionFunction( 'wp_get_abilities' );
			return (string) $reflection->getFileName();
		} catch ( \ReflectionException $e ) {
			return '';
		}
	}

	/**
	 * Diagnostic snapshot of the Abilities API as this request sees it. Feeds
	 * the MCP self-test and the debug log so "no tools registered" is
	 * distinguishable from "tools filtered out" without shell access.
	 *
	 * @return array{api_available:bool, owner:string, foreign:bool, hook_fired:bool, total:int, betterlinks:int, replayed:bool}
	 */
	public static function diagnostics(): array {
		$available = function_exists( 'wp_get_abilities' );
		$owner     = self::owner_path();
		$bundled   = defined( 'BETTERLINKS_ROOT_DIR_PATH' ) ? BETTERLINKS_ROOT_DIR_PATH : '';

		// Read the registry BEFORE `hook_fired`: `wp_get_abilities()` forces the
		// lazy singleton's init (which fires `wp_abilities_api_init`). Reading
		// `did_action()` first would report `hook_fired => false` in the same
		// snapshot that already counts registered abilities — an internally
		// inconsistent line for the exact support scenario this feeds.
		$total     = $available ? count( wp_get_abilities() ) : 0;
		$betterlinks = self::count_registered();

		return [
			'api_available' => $available,
			'owner'         => $owner,
			'foreign'       => ( '' !== $owner && '' !== $bundled && 0 !== strpos( $owner, $bundled ) ),
			'hook_fired'    => function_exists( 'did_action' ) ? (bool) did_action( 'wp_abilities_api_init' ) : false,
			'total'         => $total,
			'betterlinks'     => $betterlinks,
			'replayed'      => self::$replayed,
		];
	}

	/**
	 * One-line, human-readable form of {@see self::diagnostics()}.
	 *
	 * @return string
	 */
	public static function summary(): string {
		$d = self::diagnostics();

		return sprintf(
			'Abilities API: %s; owner: %s%s; abilities total: %d, betterlinks: %d; init fired: %s; replayed: %s',
			$d['api_available'] ? 'present' : 'missing',
			'' !== $d['owner'] ? $d['owner'] : 'unknown',
			$d['foreign'] ? ' (foreign copy — not BetterLinks\'s bundled runtime)' : '',
			$d['total'],
			$d['betterlinks'],
			$d['hook_fired'] ? 'yes' : 'no',
			$d['replayed'] ? 'yes' : 'no'
		);
	}

	/**
	 * Register the BetterLinks ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'betterlinks' ) ) {
			return;
		}

		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'betterlinks',
				[
					'label'       => __( 'BetterLinks', 'betterlinks' ),
					'description' => __( 'Create, manage and analyse short links, categories, tags and settings.', 'betterlinks' ),
				]
			);
		}
	}

	/**
	 * Register BetterLinks abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( ! Ability_Base::abilities_enabled() ) {
			return;
		}

		$abilities = [
			// Links.
			new \BetterLinks\Abilities\Links\List_Links(),
			new \BetterLinks\Abilities\Links\Get_Link(),
			new \BetterLinks\Abilities\Links\Check_Short_Url(),
			new \BetterLinks\Abilities\Links\Create_Link(),
			new \BetterLinks\Abilities\Links\Update_Link(),
			new \BetterLinks\Abilities\Links\Delete_Link(),
			// Categories & tags.
			new \BetterLinks\Abilities\Terms\List_Categories(),
			new \BetterLinks\Abilities\Terms\List_Tags(),
			new \BetterLinks\Abilities\Terms\Create_Term(),
			new \BetterLinks\Abilities\Terms\Update_Term(),
			new \BetterLinks\Abilities\Terms\Delete_Term(),
			// Click analytics.
			new \BetterLinks\Abilities\Analytics\Get_Analytics(),
			new \BetterLinks\Abilities\Analytics\Get_Link_Analytics(),
			new \BetterLinks\Abilities\Analytics\Get_Analytics_Graph(),
			new \BetterLinks\Abilities\Analytics\Get_Audience(),
			new \BetterLinks\Abilities\Analytics\Delete_Analytics(),
			// Settings.
			new \BetterLinks\Abilities\Settings\Get_Settings(),
			new \BetterLinks\Abilities\Settings\Update_Settings(),
		];

		$abilities = apply_filters( 'betterlinks_register_abilities', $abilities );

		foreach ( $abilities as $ability ) {
			if ( ! $ability instanceof Ability_Base ) {
				continue;
			}

			if ( ! $ability->meets_capability_policy() || ! $ability->is_enabled() ) {
				continue;
			}

			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $ability->get_id() ) ) {
				continue;
			}

			$ability->register();
		}
	}
}
