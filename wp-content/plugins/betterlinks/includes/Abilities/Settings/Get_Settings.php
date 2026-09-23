<?php
/**
 * Get BetterLinks settings ability.
 *
 * @package BetterLinks\Abilities\Settings
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Settings;

use BetterLinks\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get the current BetterLinks plugin settings.
 */
class Get_Settings extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/get-settings';
		$this->label       = __( 'Get BetterLinks settings', 'betterlinks' );
		$this->description = __( 'Get the current BetterLinks plugin settings.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
			'openWorldHint' => false,
		];
	}

	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [],
		];
	}

	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'data'    => [ 'type' => [ 'object', 'array', 'string', 'null' ] ],
			],
		];
	}

	public function execute( $input ) {
		$result = $this->dispatch( 'GET', '/settings' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The controller answers with the settings as a JSON-encoded STRING,
		// because that is what the admin app expects. A tool caller then has to
		// parse a string out of an already-parsed response, which reads as a bug
		// and trips clients that do not try. Hand back an object.
		if ( isset( $result['data'] ) && is_string( $result['data'] ) ) {
			$decoded = json_decode( $result['data'], true );
			if ( is_array( $decoded ) ) {
				$result['data'] = $decoded;
			}
		}

		return $result;
	}
}
