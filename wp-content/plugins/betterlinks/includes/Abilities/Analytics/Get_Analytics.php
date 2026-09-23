<?php
/**
 * Get click analytics ability.
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
 * Get overall click analytics — total and unique clicks across all links for a date range.
 */
class Get_Analytics extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/get-analytics';
		$this->label       = __( 'Get click analytics', 'betterlinks' );
		$this->description = __( 'Get overall click analytics — total and unique clicks across all links for a date range.', 'betterlinks' );
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
		// The clicks controller REQUIRES from/to and 400s without them — it does
		// not apply a default range. The tool documents "omit for the last 30
		// days", so the default is resolved here rather than promised and broken.
		$from = ! empty( $input['from'] ) ? sanitize_text_field( (string) $input['from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = ! empty( $input['to'] ) ? sanitize_text_field( (string) $input['to'] ) : gmdate( 'Y-m-d' );

		$params = [
			'from' => $from,
			'to'   => $to,
		];
		return $this->dispatch( 'GET', '/clicks', $params );
	}
}
