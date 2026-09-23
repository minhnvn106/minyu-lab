<?php
namespace BetterLinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Quick Link Creation (CLE) API tokens.
 *
 * Replaces the old `md5( AUTH_KEY )` shared secret. That value was:
 *  - derived from a WordPress core secret, so rotating it logged every user out;
 *  - not bound to any user, so it carried no accountability;
 *  - never expiring and impossible to revoke from within the plugin.
 *
 * Tokens issued here are plugin-specific, bound to the WordPress user that
 * created them, expire, and can be revoked individually or in bulk without
 * touching `wp-config.php`.
 *
 * Storage note: the token is kept in the option in clear text (rather than only
 * as a digest) because the Quick Link settings screen has to be able to re-render
 * the bookmarklet on every page load. Comparison still runs through
 * `hash_equals()` so verification is constant time. Anyone who can read this
 * option already has database access.
 *
 * @package BetterLinks
 */
class CLEToken {

	/**
	 * Option holding the issued tokens.
	 */
	const OPTION = 'betterlinks_cle_api_keys';

	/**
	 * Option flagging whether the deprecated md5( AUTH_KEY ) key is still accepted.
	 */
	const LEGACY_OPTION = 'betterlinks_cle_allow_legacy_key';

	/**
	 * Default token lifetime.
	 */
	const DEFAULT_TTL = YEAR_IN_SECONDS;

	/**
	 * Hard cap on stored tokens, oldest evicted first.
	 *
	 * One token per user who can manage BetterLinks, so this has to sit well above
	 * the administrator count of a large site — evicting a token silently breaks
	 * that person's bookmarklet.
	 */
	const MAX_TOKENS = 100;

	/**
	 * Token prefix, so a leaked value is recognisable in logs / secret scanners.
	 */
	const PREFIX = 'blk';

	/**
	 * All stored token records.
	 *
	 * @return array
	 */
	public static function all() {
		$tokens = get_option( self::OPTION, array() );

		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Persist the token set.
	 *
	 * @param array $tokens Token records keyed by id.
	 * @return void
	 */
	private static function save( $tokens ) {
		update_option( self::OPTION, $tokens, false );
	}

	/**
	 * Drop expired records and enforce the storage cap.
	 *
	 * @param array|null $tokens Optional pre-loaded set.
	 * @return array The pruned set.
	 */
	public static function prune( $tokens = null ) {
		$tokens = ( null === $tokens ) ? self::all() : $tokens;
		$now    = time();
		$kept   = array();

		foreach ( $tokens as $id => $record ) {
			if ( ! is_array( $record ) || empty( $record['token'] ) ) {
				continue;
			}

			if ( ! empty( $record['expires'] ) && (int) $record['expires'] <= $now ) {
				continue;
			}

			$kept[ $id ] = $record;
		}

		if ( count( $kept ) > self::MAX_TOKENS ) {
			uasort(
				$kept,
				function ( $a, $b ) {
					return (int) $a['created'] - (int) $b['created'];
				}
			);
			$kept = array_slice( $kept, -self::MAX_TOKENS, null, true );
		}

		return $kept;
	}

	/**
	 * Issue a fresh token.
	 *
	 * @param int    $user_id Owner. Defaults to the current user.
	 * @param int    $ttl     Lifetime in seconds. 0 for no expiry.
	 * @param string $label   Human readable note.
	 * @return array|false The token record (including the plaintext `token`), or false.
	 */
	public static function issue( $user_id = 0, $ttl = null, $label = '' ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$ttl = ( null === $ttl ) ? self::DEFAULT_TTL : (int) $ttl;

		$id     = bin2hex( self::random_bytes( 6 ) );
		$secret = bin2hex( self::random_bytes( 24 ) );

		$record = array(
			'id'        => $id,
			'token'     => self::PREFIX . '_' . $id . '_' . $secret,
			'user_id'   => $user_id,
			'label'     => sanitize_text_field( $label ),
			'created'   => time(),
			'expires'   => $ttl > 0 ? time() + $ttl : 0,
			'last_used' => 0,
		);

		$tokens        = self::prune();
		$tokens[ $id ] = $record;
		self::save( $tokens );

		return $record;
	}

	/**
	 * Return the caller's current token, issuing one on first use.
	 *
	 * @param int $user_id Owner. Defaults to the current user.
	 * @return array|false Token record, or false when there is no user.
	 */
	public static function get_or_issue_for_user( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$tokens  = self::prune();
		$changed = ( count( $tokens ) !== count( self::all() ) );

		foreach ( $tokens as $record ) {
			if ( (int) $record['user_id'] === $user_id ) {
				if ( $changed ) {
					self::save( $tokens );
				}

				return $record;
			}
		}

		if ( $changed ) {
			self::save( $tokens );
		}

		return self::issue( $user_id );
	}

	/**
	 * Rotate: revoke every token owned by a user and issue a replacement.
	 *
	 * @param int $user_id Owner. Defaults to the current user.
	 * @return array|false New token record.
	 */
	public static function rotate( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		$tokens = self::prune();

		foreach ( $tokens as $id => $record ) {
			if ( (int) $record['user_id'] === $user_id ) {
				unset( $tokens[ $id ] );
			}
		}

		self::save( $tokens );

		return self::issue( $user_id );
	}

	/**
	 * Revoke one token.
	 *
	 * @param string $id Token id.
	 * @return bool
	 */
	public static function revoke( $id ) {
		$tokens = self::all();
		$id     = (string) $id;

		if ( ! isset( $tokens[ $id ] ) ) {
			return false;
		}

		unset( $tokens[ $id ] );
		self::save( $tokens );

		return true;
	}

	/**
	 * Revoke every issued token.
	 *
	 * @return void
	 */
	public static function revoke_all() {
		self::save( array() );
	}

	/**
	 * Validate a presented token.
	 *
	 * @param string $token Presented token.
	 * @return array|false The matching record, or false.
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || '' === $token ) {
			return false;
		}

		$parts = explode( '_', $token );

		if ( count( $parts ) !== 3 || self::PREFIX !== $parts[0] ) {
			return false;
		}

		$tokens = self::all();
		$id     = $parts[1];

		if ( ! isset( $tokens[ $id ] ) || empty( $tokens[ $id ]['token'] ) ) {
			return false;
		}

		$record = $tokens[ $id ];

		if ( ! hash_equals( (string) $record['token'], $token ) ) {
			return false;
		}

		if ( ! empty( $record['expires'] ) && (int) $record['expires'] <= time() ) {
			self::revoke( $id );

			return false;
		}

		if ( ! get_userdata( (int) $record['user_id'] ) ) {
			// Owner was deleted — the token dies with them.
			self::revoke( $id );

			return false;
		}

		return $record;
	}

	/**
	 * Verify a token, switch the request to its owner, and confirm that owner may
	 * still use Quick Link Creation.
	 *
	 * The capability check runs *after* the switch because Pro answers
	 * `betterlinks/admin/current_user_can_edit_settings` from the current user's
	 * role, and there is no reliable way to evaluate a delegated role matrix for
	 * an arbitrary user id. The original user is restored if the check fails, so a
	 * rejected token never leaves the request running as someone else.
	 *
	 * @param string $token Presented token.
	 * @return array|false The record on success.
	 */
	public static function authenticate( $token ) {
		$record = self::verify( $token );

		if ( ! $record ) {
			return false;
		}

		$previous_user = get_current_user_id();

		wp_set_current_user( (int) $record['user_id'] );

		if ( ! self::current_user_can_create() ) {
			wp_set_current_user( $previous_user );

			return false;
		}

		self::touch( $record['id'] );

		return $record;
	}

	/**
	 * May the *current* user use Quick Link Creation?
	 *
	 * Deliberately the same audience that could previously read `md5( AUTH_KEY )`
	 * out of the localized admin data and use the bookmarklet — administrators
	 * plus any role Pro has delegated settings access to. Narrowing it to
	 * `manage_options` would silently break the Quick Link settings tab for those
	 * delegated roles, and it would not close anything: the old shared key was
	 * usable by anyone who ever saw it, with no user attached at all.
	 *
	 * @return bool
	 */
	public static function current_user_can_create() {
		$can = current_user_can( 'manage_options' );

		if ( ! $can ) {
			$can = (bool) apply_filters( 'betterlinks/admin/current_user_can_edit_settings', $can );
		}

		return (bool) apply_filters( 'betterlinks/cle/user_can_create', $can, wp_get_current_user() );
	}


	/**
	 * Record last-used time (throttled to one write per hour per token).
	 *
	 * @param string $id Token id.
	 * @return void
	 */
	private static function touch( $id ) {
		$tokens = self::all();

		if ( ! isset( $tokens[ $id ] ) ) {
			return;
		}

		$now = time();

		if ( $now - (int) $tokens[ $id ]['last_used'] < HOUR_IN_SECONDS ) {
			return;
		}

		$tokens[ $id ]['last_used'] = $now;
		self::save( $tokens );
	}

	/**
	 * Is the deprecated md5( AUTH_KEY ) key still accepted?
	 *
	 * Existing sites that already have Quick Link Creation switched on keep it for
	 * one release so bookmarklets and the Chrome extension do not break on update;
	 * every other site (including all fresh installs) starts with it off. Site
	 * owners can force either answer with the
	 * `betterlinks/cle/allow_legacy_api_key` filter, and the plugin will drop the
	 * path entirely in a future release.
	 *
	 * @return bool
	 */
	public static function legacy_key_allowed() {
		$stored = get_option( self::LEGACY_OPTION, null );

		if ( null === $stored ) {
			global $betterlinks_settings;

			$in_use = ! empty( $betterlinks_settings['cle']['enable_cle'] );
			$stored = $in_use ? '1' : '0';

			add_option( self::LEGACY_OPTION, $stored, '', false );
		}

		$allowed = '1' === (string) $stored;

		// Retire the shared legacy key after a grace period, so copies of it that
		// were handed out in the past eventually stop working.
		if ( $allowed ) {
			$expires_at = self::legacy_key_expires_at();
			if ( $expires_at && time() > $expires_at ) {
				$allowed = false;
			}
		}

		return (bool) apply_filters( 'betterlinks/cle/allow_legacy_api_key', $allowed );
	}

	/**
	 * When the legacy md5( AUTH_KEY ) key stops being accepted.
	 *
	 * The grace period starts the first time this is checked on a site that still
	 * accepts the key, and defaults to 90 days
	 * (`betterlinks/cle/legacy_api_key_grace_period`).
	 *
	 * @return int Unix timestamp, or 0 when the legacy key is not in use.
	 */
	public static function legacy_key_expires_at() {
		if ( '1' !== (string) get_option( self::LEGACY_OPTION, '0' ) ) {
			return 0;
		}

		$since = (int) get_option( self::LEGACY_OPTION . '_since', 0 );
		if ( ! $since ) {
			$since = time();
			add_option( self::LEGACY_OPTION . '_since', $since, '', false );
		}

		$grace = (int) apply_filters( 'betterlinks/cle/legacy_api_key_grace_period', 90 * DAY_IN_SECONDS );

		return $since + max( 0, $grace );
	}

	/**
	 * Cryptographically secure random bytes.
	 *
	 * @param int $length Byte count.
	 * @return string
	 */
	private static function random_bytes( $length ) {
		if ( function_exists( 'random_bytes' ) ) {
			try {
				return random_bytes( $length );
			} catch ( \Exception $e ) {
				// Fall through to the WordPress generator below.
				unset( $e );
			}
		}

		return substr( wp_generate_password( $length * 2, false, false ), 0, $length );
	}
}
