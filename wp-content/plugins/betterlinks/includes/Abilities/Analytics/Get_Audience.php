<?php
/**
 * Audience breakdown ability.
 *
 * @package BetterLinks\Abilities\Analytics
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Analytics;

use BetterLinks\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Human vs bot and new vs returning clicks — the Audience card in Analytics.
 */
class Get_Audience extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/get-audience';
		$this->label       = __( 'Get audience breakdown', 'betterlinks' );
		$this->description = __( 'Get the audience breakdown for a date range: human vs bot clicks, new vs returning visitors, and how many bot clicks were blocked.', 'betterlinks' );
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
			'properties'           => [
				'from' => [ 'type' => 'string', 'default' => '', 'description' => __( 'Start date (Y-m-d). Omit for the last 30 days.', 'betterlinks' ) ],
				'to'   => [ 'type' => 'string', 'default' => '', 'description' => __( 'End date (Y-m-d). Omit for today.', 'betterlinks' ) ],
			],
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
		// Same default range as the other analytics tools; the controller itself
		// requires from/to and 400s without them.
		$from = ! empty( $input['from'] ) ? sanitize_text_field( (string) $input['from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = ! empty( $input['to'] ) ? sanitize_text_field( (string) $input['to'] ) : gmdate( 'Y-m-d' );

		return $this->dispatch(
			'GET',
			'/clicks/get_audience',
			[
				'from' => $from,
				'to'   => $to,
			]
		);
	}
}
