<?php
/**
 * Connection status reporter.
 *
 * @package BetterLinks\Diagnostics
 */

declare(strict_types=1);

namespace BetterLinks\Diagnostics;


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Builds a read-only integration health report for BetterLinks.
 *
 * A single source of truth consumed by the `betterlinks/get-connection-status`
 * ability and the `GET /betterlinks/v1/connection-status` REST route. It reports
 * whatever an external MCP/Abilities client needs to distinguish a certificate
 * problem from an authentication problem from a permission problem from an
 * ability-registration problem — without reading plugin source or DB tables by
 * hand (see #188).
 *
 * The report is side-effect free: it makes no outbound HTTP request and never
 * returns secret material (API keys, OAuth tokens, connection tokens). Actively
 * exercising the round trip (a live "Test Connection") belongs to the
 * integration screen tracked in #189.
 */
class Connection_Status {

	/**
	 * Build the full connection-status report.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		return [
			'plugin'       => self::plugin_info(),
			'abilities'    => self::abilities_info(),
			'mcp_server'   => self::mcp_info(),
			'user'         => self::user_info(),
			'urls'         => self::analyze_schemes( home_url(), site_url(), rest_url() ),
			'database'     => self::database_info(),
			'links'        => self::links_info(),
		];
	}

	/**
	 * BetterLinks + BetterLinks Pro versions and whether Pro is active.
	 *
	 * @return array<string, mixed>
	 */
	private static function plugin_info(): array {
		return [
			'betterlinks_version' => defined( 'BETTERLINKS_VERSION' ) ? BETTERLINKS_VERSION : null,
			'pro_active'        => defined( 'BETTERLINKS_PRO_VERSION' ),
			'pro_version'       => defined( 'BETTERLINKS_PRO_VERSION' ) ? BETTERLINKS_PRO_VERSION : null,
		];
	}

	/**
	 * Abilities API availability + which BetterLinks abilities are registered.
	 *
	 * @return array<string, mixed>
	 */
	private static function abilities_info(): array {
		$api_available = function_exists( 'wp_register_ability' ) && function_exists( 'wp_get_abilities' );
		$names         = [];

		if ( $api_available ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
					continue;
				}
				$name = (string) $ability->get_name();
				if ( 0 === strpos( $name, 'betterlinks/' ) || 0 === strpos( $name, 'betterlinks-pro/' ) ) {
					$names[] = $name;
				}
			}
			sort( $names );
		}

		return [
			'api_available'   => $api_available,
			'registered'      => count( $names ) > 0,
			'betterlinks_count' => count( $names ),
			'names'           => $names,
		];
	}

	/**
	 * MCP server toggle + the endpoints a client would connect to.
	 *
	 * @return array<string, mixed>
	 */
	private static function mcp_info(): array {
		return [
			'enabled'      => class_exists( \BetterLinks\Mcp\Mcp_Manager::class ) ? \BetterLinks\Mcp\Mcp_Manager::is_enabled() : false,
			'endpoint'     => home_url( '/betterlinks/mcp' ),
			'rest_fallback' => rest_url( 'betterlinks/v1/mcp' ),
		];
	}

	/**
	 * Current user + the BetterLinks capabilities they hold.
	 *
	 * @return array<string, mixed>
	 */
	private static function user_info(): array {
		$user = wp_get_current_user();

		return [
			'id'                     => get_current_user_id(),
			'login'                  => ( $user && $user->exists() ) ? $user->user_login : '',
			'is_admin'               => current_user_can( 'manage_options' ),
			'betterlinks_capabilities' => array_values( array_filter( [ 'manage_options' ], 'current_user_can' ) ),
		];
	}

	/**
	 * BetterLinks custom-table status: version, whether an update is pending,
	 * and per-table existence.
	 *
	 * @return array<string, mixed>
	 */
	private static function database_info(): array {
		global $wpdb;

		$installed = \BetterLinks\Helper::btl_get_option( 'betterlinks_db_version' );
		$expected  = defined( 'BETTERLINKS_DB_VERSION' ) ? BETTERLINKS_DB_VERSION : null;

		// The custom tables the redirect + analytics paths depend on. A missing
		// one is why a tool call can fail long after the endpoint itself answers.
		$tables = [];
		foreach ( [ 'betterlinks', 'betterlinks_terms', 'betterlinks_terms_relationships', 'betterlinks_clicks' ] as $table ) {
			$name            = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema probe on our own custom tables; no core API reports their existence, and a cached answer would hide the very drift this diagnostic exists to catch.
			$tables[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name );
		}

		return [
			'installed_version' => $installed ? (string) $installed : null,
			'expected_version'  => $expected,
			'up_to_date'        => ( $installed && $expected && (string) $installed === (string) $expected ),
			'tables'            => $tables,
		];
	}

	/**
	 * Link inventory — what an agent would actually find to work with. A
	 * connector that authenticates against an empty site looks broken, so
	 * surface the numbers.
	 *
	 * @return array<string, int>
	 */
	private static function links_info(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- inventory counts over our own custom tables; no core API counts them, and a cached count would misreport an empty site as populated (or vice versa) to the connector.
		return [
			'links'      => (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->prefix}betterlinks" ),
			'categories' => (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->prefix}betterlinks_terms WHERE term_type = 'category'" ),
			'tags'       => (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->prefix}betterlinks_terms WHERE term_type = 'tags'" ),
		];
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Compare the URL schemes WordPress uses. A `home`/`siteurl` scheme
	 * mismatch (or a REST URL whose scheme differs from `home`) is the classic
	 * cause of an MCP client's requests bouncing between HTTP and HTTPS.
	 *
	 * Pure function of its inputs — it reads no options and touches no state,
	 * so it can be exercised directly from a test.
	 *
	 * @param string $home_url The `home` URL.
	 * @param string $site_url The `siteurl` URL.
	 * @param string $rest_url The REST API base URL.
	 * @return array<string, mixed>
	 */
	public static function analyze_schemes( string $home_url, string $site_url, string $rest_url ): array {
		$home = strtolower( (string) wp_parse_url( $home_url, PHP_URL_SCHEME ) );
		$site = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );
		$rest = strtolower( (string) wp_parse_url( $rest_url, PHP_URL_SCHEME ) );

		return [
			'home'               => $home_url,
			'siteurl'            => $site_url,
			'rest_url'           => $rest_url,
			'home_scheme'        => $home,
			'siteurl_scheme'     => $site,
			'rest_scheme'        => $rest,
			'is_https'           => 'https' === $home,
			'home_siteurl_match' => '' !== $home && $home === $site,
			'rest_matches_home'  => '' !== $rest && $rest === $home,
		];
	}
}
