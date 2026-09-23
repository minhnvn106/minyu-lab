<?php
/**
 * Delete a category or tag ability.
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
 * Delete a link category or tag by ID. Protected terms such as Uncategorized cannot be deleted.
 */
class Delete_Term extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/delete-term';
		$this->label       = __( 'Delete a category or tag', 'betterlinks' );
		$this->description = __( 'Delete a link category or tag by ID. Protected terms such as Uncategorized cannot be deleted.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => false,
			// Removes the term and unfiles every link under it.
			'destructive'   => true,
			'idempotent'    => false,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'ID'        => [ 'type' => 'integer', 'description' => __( 'The term ID to delete.', 'betterlinks' ) ],
				'term_type' => [ 'type' => 'string', 'enum' => [ 'category', 'tags' ], 'description' => __( 'Whether the ID is a category or a tag.', 'betterlinks' ) ],
				'confirm'   => self::confirm_property(),
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
		$id = isset( $input['ID'] ) ? absint( $input['ID'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'betterlinks_missing_term_id', __( 'A term ID is required.', 'betterlinks' ), [ 'status' => 400 ] );
		}
		$type = isset( $input['term_type'] ) ? (string) $input['term_type'] : 'category';
		$key  = ( 'tags' === $type ) ? 'tag_id' : 'cat_id';

		// Every link filed under the term loses it, so name the term and say how
		// many links are affected before removing anything.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read for the confirmation summary, immediately before a delete.
		$term = $wpdb->get_row( $wpdb->prepare( "SELECT term_name, term_type FROM {$wpdb->prefix}betterlinks_terms WHERE ID = %d", $id ), ARRAY_A );
		if ( empty( $term ) ) {
			return new \WP_Error(
				'betterlinks_term_not_found',
				sprintf(
					/* translators: %d: term ID */
					__( 'No term with ID %d exists.', 'betterlinks' ),
					$id
				),
				[ 'status' => 404 ]
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
		$link_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}betterlinks_terms_relationships WHERE term_id = %d", $id ) );
		$gate       = $this->confirmation_gate(
			$input,
			'delete-term',
			sprintf(
				/* translators: 1: term type (category or tag), 2: term name, 3: number of links */
				__( 'Delete the %1$s "%2$s". %3$d link(s) filed under it lose it. The links themselves are kept.', 'betterlinks' ),
				( 'tags' === $type ) ? __( 'tag', 'betterlinks' ) : __( 'category', 'betterlinks' ),
				isset( $term['term_name'] ) ? $term['term_name'] : '',
				$link_count
			),
			[
				'term_name'       => isset( $term['term_name'] ) ? $term['term_name'] : '',
				'term_type'       => $type,
				'links_affected'  => $link_count,
			]
		);
		if ( null !== $gate ) {
			return $gate;
		}

		return $this->dispatch( 'DELETE', '/terms', [ $key => $id ] );
	}
}
