<?php
/**
 * Update settings ability.
 *
 * @package BetterLinks\Abilities\Settings
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Settings;

use BetterLinks\Abilities\Ability_Base;
use BetterLinks\Admin\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Update selected BetterLinks settings.
 *
 * The BetterLinks settings endpoint stores the entire settings object as one
 * blob, so posting only the changed keys would wipe everything else. This
 * ability reads the current settings, merges the requested keys on top, and
 * saves the full object — the same "never reset what the caller did not
 * mention" contract used elsewhere in the plugin.
 */
class Update_Settings extends Ability_Base {

	// Supplies get_settings_schema(), used below to know which setting names are real.
	use \BetterLinks\Traits\ArgumentSchema;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'betterlinks/update-settings';
		$this->label       = __( 'Update BetterLinks settings', 'betterlinks' );
		$this->description = __( 'Update one or more BetterLinks settings. Only the keys you pass are changed; everything else is preserved.', 'betterlinks' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => false,
			// Replaces the whole settings blob, including keys it was not asked to change.
			'destructive'   => true,
			'idempotent'    => true,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'settings' => [
					'type'                 => 'object',
					'description'          => __( 'A map of setting keys to new values (e.g. { "enable_mcp": true, "is_case_sensitive": false }). Merged over the existing settings.', 'betterlinks' ),
					'additionalProperties' => true,
				],
				'confirm'  => self::confirm_property(),
			],
			'required'             => [ 'settings' ],
		];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'data'    => [ 'type' => [ 'object', 'array', 'null' ] ],
			],
		];
	}

	/**
	 * Execute ability.
	 *
	 * @param array<string, mixed> $input Ability input payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( $input ) {
		$patch = isset( $input['settings'] ) && is_array( $input['settings'] ) ? $input['settings'] : [];
		if ( empty( $patch ) ) {
			return new \WP_Error( 'betterlinks_no_settings', __( 'Provide a "settings" object with at least one key to change.', 'betterlinks' ), [ 'status' => 400 ] );
		}

		$current = Cache::get_json_settings();
		$current = is_array( $current ) ? $current : [];

		// Only keys the site actually stores. The schema takes any object, so an
		// assistant that invented a plausible-sounding name ("quick_link_create")
		// wrote junk into the option row that nothing ever reads and no screen
		// can clear. Anything the installer or this site has on record is
		// allowed; anything else is refused by name.
		$known = array_keys( $current );
		// Plus the fields the REST layer declares, so a key this site has never
		// saved is still writable.
		if ( method_exists( $this, 'get_settings_schema' ) ) {
			$schema = $this->get_settings_schema();
			if ( is_array( $schema ) ) {
				$known = array_merge( $known, array_keys( $schema ) );
			}
		}
		$known = array_values( array_unique( array_filter( $known, 'is_string' ) ) );
		/**
		 * Filters the setting keys the update-settings tool may write.
		 *
		 * @param string[] $known Allowed keys.
		 */
		$known   = (array) apply_filters( 'betterlinks/mcp/writable_settings', $known );
		$unknown = array_values( array_diff( array_keys( $patch ), $known ) );
		if ( ! empty( $unknown ) ) {
			sort( $known );
			return new \WP_Error(
				'betterlinks_unknown_setting',
				sprintf(
					/* translators: 1: comma-separated unknown keys, 2: comma-separated valid keys */
					__( 'Not a BetterLinks setting: %1$s. Valid keys are: %2$s.', 'betterlinks' ),
					implode( ', ', $unknown ),
					implode( ', ', $known )
				),
				[ 'status' => 400, 'unknown_keys' => $unknown ]
			);
		}

		// Enumerated values, checked before they reach the option.
		if ( isset( $patch['redirect_type'] ) && ! in_array( (string) $patch['redirect_type'], [ '301', '302', '307' ], true ) ) {
			return new \WP_Error(
				'betterlinks_invalid_setting',
				__( 'redirect_type must be one of 301, 302 or 307.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}

		// Merge the requested keys over the stored settings, then push the full
		// object through the existing REST controller so its sanitization,
		// caching and side effects (JSON settings file, custom-domain option)
		// all run exactly as they do for the admin UI.
		$merged = array_merge( $current, $patch );

		// Settings are site-wide: changing the link prefix, say, moves every
		// short URL on the site at once. Show what changes before writing.
		$changes = [];
		foreach ( $patch as $key => $value ) {
			$old = array_key_exists( $key, $current ) ? $current[ $key ] : null;
			if ( $old === $value ) {
				continue;
			}
			$changes[ (string) $key ] = sprintf(
				'%s → %s',
				null === $old ? __( '(not set)', 'betterlinks' ) : wp_json_encode( $old ),
				wp_json_encode( $value )
			);
		}
		if ( empty( $changes ) ) {
			return [
				'success' => true,
				'data'    => [ 'message' => __( 'Those settings already hold those values; nothing was changed.', 'betterlinks' ) ],
			];
		}
		$gate = $this->confirmation_gate(
			$input,
			'update-settings',
			sprintf(
				/* translators: %d: number of settings being changed */
				__( 'Change %d site-wide BetterLinks setting(s). These apply to every link on the site, not just one.', 'betterlinks' ),
				count( $changes )
			),
			$changes
		);
		if ( null !== $gate ) {
			return $gate;
		}

		return $this->dispatch( 'PUT', '/settings', $merged );
	}
}
