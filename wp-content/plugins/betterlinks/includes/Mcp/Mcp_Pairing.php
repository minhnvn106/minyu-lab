<?php
/**
 * MCP pairing lifecycle — mint / rotate / revoke the per-site connection token.
 *
 * The primary way an AI assistant connects to BetterLinks: an admin clicks
 * Connect, the plugin mints a 32-byte secret, and the user pastes either the
 * single connect URL (token embedded in the path) or the endpoint + Bearer
 * token into their AI client. The token is validated directly by Mcp_Server —
 * no hosted infrastructure is involved.
 *
 * State is stored in the `betterlinks_mcp_pairing` option:
 *   {
 *     site_token:   string (the secret the client presents),
 *     connected:    bool,
 *     connected_at: int (unix ts),
 *     scopes:       string[] (e.g. ['read','write']),
 *     user_id:      int (admin who minted the token; MCP calls run as them)
 *   }
 *
 * @package BetterLinks\Mcp
 */

declare(strict_types=1);

namespace BetterLinks\Mcp;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Connection-token lifecycle for the BetterLinks MCP server.
 */
final class Mcp_Pairing {

	/**
	 * Option key holding all MCP pairing state.
	 */
	public const OPTION = 'betterlinks_mcp_pairing';

	/**
	 * Path segment of the pretty per-site endpoint.
	 */
	public const SITE_ENDPOINT_PATH = 'betterlinks/mcp';

	/**
	 * Default scopes granted on connect.
	 */
	private const DEFAULT_SCOPES = [ 'read', 'write' ];

	/**
	 * Throttle window (seconds) for last-used writes — one option write per
	 * minute at most, so a busy client can't hammer the option on every call.
	 */
	private const LAST_USED_THROTTLE = 60;

	/**
	 * The PRIMARY endpoint the user pastes into their AI client — this
	 * site's own MCP URL.
	 *
	 * @return string
	 */
	public static function site_endpoint(): string {
		return home_url( '/' . self::SITE_ENDPOINT_PATH );
	}

	/**
	 * Always-on fallback endpoint via the REST namespace, for hosts where
	 * the pretty rewrite can't be served (e.g. plain permalinks).
	 *
	 * @return string
	 */
	public static function site_endpoint_fallback(): string {
		return rest_url( 'betterlinks/v1/mcp' );
	}

	/**
	 * The SINGLE URL the user pastes into their AI client — the pretty
	 * endpoint with the connection token embedded as a path segment.
	 * Empty string when not connected.
	 *
	 * @return string
	 */
	public static function connect_url(): string {
		// Intentionally empty. This used to return <endpoint>/<token>, i.e. an
		// administrator-equivalent credential inside a URL path, which every
		// access log, proxy and CDN along the way records in the clear. The
		// endpoint is served header-only; nothing in the UI used this form.
		return '';
	}

	/**
	 * Current pairing state, defaults merged.
	 *
	 * @return array{site_token:string,connected:bool,connected_at:int,scopes:string[],user_id:int,last_used:int}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return [
			'site_token'   => isset( $stored['site_token'] ) ? self::decrypt( (string) $stored['site_token'] ) : '',
			'connected'    => ! empty( $stored['connected'] ),
			'connected_at' => isset( $stored['connected_at'] ) ? (int) $stored['connected_at'] : 0,
			'scopes'       => isset( $stored['scopes'] ) && is_array( $stored['scopes'] )
				? array_values( array_map( 'strval', $stored['scopes'] ) )
				: [],
			'user_id'      => isset( $stored['user_id'] ) ? (int) $stored['user_id'] : 0,
			'last_used'    => isset( $stored['last_used'] ) ? (int) $stored['last_used'] : 0,
		];
	}

	/**
	 * Record that the static token was just used to authenticate an MCP call.
	 * Throttled to at most one option write per minute so a busy client can't
	 * turn every request into a database write. No-op when not connected.
	 *
	 * @return void
	 */
	public static function touch_last_used(): void {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored['site_token'] ) ) {
			return;
		}
		$now  = time();
		$last = isset( $stored['last_used'] ) ? (int) $stored['last_used'] : 0;
		if ( $now - $last < self::LAST_USED_THROTTLE ) {
			return;
		}
		$stored['last_used'] = $now;
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * The stored site token (secret). Empty string when not connected.
	 *
	 * @return string
	 */
	public static function site_token(): string {
		return self::state()['site_token'];
	}

	/**
	 * The admin user the connection runs as (the token's minter).
	 *
	 * @return int
	 */
	public static function user_id(): int {
		return self::state()['user_id'];
	}

	/**
	 * Whether an MCP connection token is currently active for this site.
	 *
	 * @return bool
	 */
	public static function is_connected(): bool {
		$state = self::state();
		return $state['connected'] && '' !== $state['site_token'];
	}

	/**
	 * Whether the active connection is limited to read-only tools.
	 *
	 * @return bool
	 */
	public static function is_read_only(): bool {
		$scopes = self::state()['scopes'];
		return ! in_array( 'write', $scopes, true );
	}

	/**
	 * Sanitized snapshot for the MCP admin page.
	 *
	 * @return array<string,mixed>
	 */
	public static function public_status(): array {
		// Admin-side read: a convenient moment to re-save a token stored before
		// this version encrypted them.
		self::maybe_upgrade_storage();
		$state = self::state();
		return [
			'connected'         => self::is_connected(),
			'connection_token'  => $state['site_token'],
			'connect_url'       => self::connect_url(),
			'mcp_endpoint'      => self::site_endpoint(),
			'mcp_endpoint_rest' => self::site_endpoint_fallback(),
			'connected_at'      => $state['connected_at'],
			'last_used'         => $state['last_used'],
			'scopes'            => $state['scopes'],
			'read_only'         => self::is_read_only(),
			// Ready-to-paste connection recipes (header-based — token stays out
			// of the URL, so it can't leak into server/proxy logs).
			'config'            => self::config_snippets(),
			// A drop-in instruction the user can paste into their AI client so
			// it sets the connection up itself.
			'ai_prompt'         => self::ai_prompt(),
		];
	}

	/**
	 * Ready-to-paste connection recipes for the dashboard. All header-based
	 * (Authorization: Bearer) so the secret stays out of URLs and logs.
	 * Empty strings when not connected.
	 *
	 * @return array{cli:string,json:string}
	 */
	public static function config_snippets(): array {
		$token = self::site_token();
		if ( '' === $token ) {
			return [
				'cli'  => '',
				'json' => '',
			];
		}
		$endpoint = self::site_endpoint();

		// Claude Code one-liner. The CLI requires the positional NAME and URL
		// BEFORE any flags (`claude mcp add <name> <url> --flags`).
		$cli = sprintf(
			'claude mcp add betterlinks %s --transport http --header "Authorization: Bearer %s"',
			$endpoint,
			$token
		);

		// Portable mcpServers JSON block (Claude Desktop / other clients).
		$json = wp_json_encode(
			[
				'mcpServers' => [
					'betterlinks' => [
						'url'     => $endpoint,
						'headers' => [
							'Authorization' => 'Bearer ' . $token,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return [
			'cli'  => $cli,
			'json' => is_string( $json ) ? $json : '',
		];
	}

	/**
	 * A copy-paste instruction the user hands to their AI assistant so IT
	 * sets up the BetterLinks MCP connection for them. Empty when not connected.
	 *
	 * @return string
	 */
	public static function ai_prompt(): string {
		if ( ! self::is_connected() ) {
			return '';
		}
		$endpoint = self::site_endpoint();
		$access   = self::is_read_only()
			? 'read-only (inspect links, analytics and settings only)'
			: 'read-write (can create and manage links, terms and settings)';

		$lines = [
			'Add the following remote MCP server to your connections so you can manage links on my WordPress site, then use it.',
			'',
			'Server name: BetterLinks',
			'Server URL: ' . $endpoint,
			'Transport: streamable HTTP',
			'Authentication: Bearer token (in the Authorization header)',
			// Deliberately a placeholder, not the live token: this text is written
			// to be pasted into a hosted AI chat, and anything pasted there is
			// retained by that vendor. The token belongs in the client's own
			// credential field, which config_snippets() below fills in.
			'API key: <paste the connection token from BetterLinks → MCP>',
			'Access level: ' . $access,
			'',
			'If you use the Claude Code CLI, this is the command (name and URL come BEFORE the flags); put the real token in place of the placeholder:',
			'  claude mcp add betterlinks ' . $endpoint . ' --transport http --header "Authorization: Bearer <connection token>"',
			'',
			'Add it now, confirm it is connected by calling its "list-links" tool, and tell me which short links you can see.',
		];
		$prompt = implode( "\n", $lines );

		/**
		 * Filter the copy-paste AI setup prompt shown in the MCP page.
		 *
		 * @param string $prompt    The default prompt text.
		 * @param bool   $read_only Whether the connection is read-only.
		 */
		return (string) apply_filters( 'betterlinks_mcp_ai_prompt', $prompt, self::is_read_only() );
	}

	/**
	 * Connect — mint a connection token for this site's MCP endpoint.
	 *
	 * Idempotent: re-connecting keeps the existing token (and its scopes) so
	 * a paired client isn't silently broken. Use rotate() to change either.
	 *
	 * @param bool $read_only Grant only the `read` scope on a NEW token.
	 * @return array<string,mixed> Public status.
	 */
	public static function connect( bool $read_only = false ): array {
		$state    = self::state();
		$existing = '' !== $state['site_token'];
		$token    = $existing ? $state['site_token'] : self::mint_token();
		$scopes   = $existing && ! empty( $state['scopes'] )
			? $state['scopes']
			: self::scopes_for( $read_only );

		update_option(
			self::OPTION,
			[
				'site_token'   => self::encrypt( $token ),
				'connected'    => true,
				'connected_at' => $existing ? $state['connected_at'] : time(),
				'scopes'       => $scopes,
				'user_id'      => $existing && $state['user_id'] ? $state['user_id'] : get_current_user_id(),
			],
			false
		);

		return self::public_status();
	}

	/**
	 * Rotate — mint a BRAND-NEW token, invalidating the previous one
	 * immediately. The leaked-token remedy. Optionally flips read-only.
	 *
	 * @param bool|null $read_only null = keep current scopes; true/false = set.
	 * @return array<string,mixed> Public status with the fresh token.
	 */
	public static function rotate( ?bool $read_only = null ): array {
		$state  = self::state();
		$scopes = null === $read_only
			? ( ! empty( $state['scopes'] ) ? $state['scopes'] : self::DEFAULT_SCOPES )
			: self::scopes_for( $read_only );

		update_option(
			self::OPTION,
			[
				'site_token'   => self::encrypt( self::mint_token() ),
				'connected'    => true,
				'connected_at' => time(),
				'scopes'       => $scopes,
				'user_id'      => get_current_user_id() ? get_current_user_id() : $state['user_id'],
			],
			false
		);

		return self::public_status();
	}

	/**
	 * Disconnect — revoke the connection token AND every OAuth grant, so
	 * Disconnect is a single kill switch for ALL MCP access.
	 *
	 * @return array<string,mixed> Public status after disconnect.
	 */
	public static function disconnect(): array {
		delete_option( self::OPTION );
		Mcp_OAuth::revoke_all();

		return self::public_status();
	}

	/**
	 * Map a read-only flag to the granted scope list.
	 *
	 * @param bool $read_only Whether to grant read-only access.
	 * @return string[]
	 */
	private static function scopes_for( bool $read_only ): array {
		return $read_only ? [ 'read' ] : self::DEFAULT_SCOPES;
	}

	/**
	 * Mint a 32-byte random token (64 hex chars).
	 *
	 * @return string
	 */
	/**
	 * Marker prefixing an encrypted token, with its format version.
	 */
	private const CIPHER_PREFIX = 'blenc1:';

	/**
	 * Encrypt the pairing token for storage.
	 *
	 * The token authenticates as an administrator across every ability, never
	 * expires, and sat in wp_options in the clear — so any second-hand read of
	 * the database (a backup, another plugin's SQL injection, a support session
	 * with a DB browser) handed over the site. It cannot be hashed like the
	 * OAuth tokens are, because the MCP screen has to show it again for the
	 * admin to copy, so it is encrypted with a key derived from the site's
	 * salts: the database alone is no longer enough.
	 *
	 * Falls back to storing the raw value where OpenSSL or AES-256-GCM is
	 * unavailable, rather than locking those sites out of the feature.
	 *
	 * @param string $plain Raw token.
	 * @return string Stored representation.
	 */
	private static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::can_encrypt() ) {
			return $plain;
		}
		$key = self::cipher_key();
		$iv  = random_bytes( 12 );
		$tag = '';
		$out = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $out ) {
			return $plain;
		}
		return self::CIPHER_PREFIX . base64_encode( $iv . $tag . $out ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext, not obfuscation.
	}

	/**
	 * Decrypt a stored token. A value without the marker is returned as-is:
	 * that is a token stored before this change, or one on a site with no
	 * OpenSSL. An undecryptable value (the site's salts were rotated) returns
	 * empty, which reads as "not connected" and prompts a reconnect rather than
	 * throwing.
	 *
	 * @param string $stored Stored representation.
	 * @return string Raw token.
	 */
	private static function decrypt( string $stored ): string {
		if ( '' === $stored || 0 !== strpos( $stored, self::CIPHER_PREFIX ) ) {
			return $stored;
		}
		if ( ! self::can_encrypt() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, strlen( self::CIPHER_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.
		if ( ! is_string( $raw ) || strlen( $raw ) <= 28 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, $iv, $tag );

		return is_string( $out ) ? $out : '';
	}

	/**
	 * @return bool
	 */
	private static function can_encrypt(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Key derived from the site's own salts, so it lives in wp-config.php
	 * rather than in the database beside the ciphertext.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function cipher_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . self::OPTION, true );
	}

	/**
	 * Re-save a token that predates encryption. Called from the admin status
	 * read, so it happens the next time someone opens the MCP screen rather
	 * than on the redirect path.
	 *
	 * @return void
	 */
	private static function maybe_upgrade_storage(): void {
		if ( ! self::can_encrypt() ) {
			return;
		}
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) || empty( $stored['site_token'] ) ) {
			return;
		}
		if ( 0 === strpos( (string) $stored['site_token'], self::CIPHER_PREFIX ) ) {
			return;
		}
		$stored['site_token'] = self::encrypt( (string) $stored['site_token'] );
		update_option( self::OPTION, $stored, false );
	}

	private static function mint_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}
