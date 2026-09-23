<?php
/**
 * List link categories ability.
 *
 * @package BetterLinks\Abilities\Terms
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Terms;

use BetterLinks\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * List all link categories with their link counts.
 */
class List_Categories extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/list-categories';
		$this->label       = __( 'List link categories', 'betterlinks' );
		$this->description = __( 'List all link categories with their link counts.', 'betterlinks' );
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
		return $this->dispatch( 'GET', '/terms/categories' );
	}
}
