<?php
/**
 * Rename a category or tag ability.
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
 * Rename an existing link category or tag.
 */
class Update_Term extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/update-term';
		$this->label       = __( 'Rename a category or tag', 'betterlinks' );
		$this->description = __( 'Rename an existing link category or tag.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => false,
			// Renames a category or tag every link filed under it shares.
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
				'ID'        => [ 'type' => 'integer', 'description' => __( 'The term ID to rename.', 'betterlinks' ) ],
				'term_name' => [ 'type' => 'string', 'description' => __( 'New display name.', 'betterlinks' ) ],
				'term_slug' => [ 'type' => 'string', 'description' => __( 'New slug (optional).', 'betterlinks' ) ],
				'term_type' => [ 'type' => 'string', 'enum' => [ 'category', 'tags' ] ],
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
		$name = isset( $input['term_name'] ) ? (string) $input['term_name'] : '';
		if ( ! $id || '' === $name ) {
			return new \WP_Error( 'betterlinks_invalid_term', __( 'Both ID and term_name are required.', 'betterlinks' ), [ 'status' => 400 ] );
		}
		$slug = isset( $input['term_slug'] ) && '' !== $input['term_slug'] ? (string) $input['term_slug'] : sanitize_title( $name );
		$type = isset( $input['term_type'] ) ? (string) $input['term_type'] : 'category';

		// A rename shows up on every link filed under the term, and changing the
		// slug moves the category archive it is reachable at.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read for the confirmation summary, immediately before a write.
		$term = $wpdb->get_row( $wpdb->prepare( "SELECT term_name, term_slug FROM {$wpdb->prefix}betterlinks_terms WHERE ID = %d", $id ), ARRAY_A );
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
		$changes = [];
		if ( isset( $term['term_name'] ) && $term['term_name'] !== $name ) {
			$changes['term_name'] = $term['term_name'] . ' → ' . $name;
		}
		if ( isset( $term['term_slug'] ) && $term['term_slug'] !== $slug ) {
			$changes['term_slug'] = $term['term_slug'] . ' → ' . $slug;
		}
		if ( ! empty( $changes ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
			$link_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}betterlinks_terms_relationships WHERE term_id = %d", $id ) );
			$gate       = $this->confirmation_gate(
				$input,
				'update-term',
				sprintf(
					/* translators: 1: current term name, 2: number of links using it */
					__( 'Rename "%1$s", which %2$d link(s) are filed under. The old name is not kept.', 'betterlinks' ),
					isset( $term['term_name'] ) ? $term['term_name'] : '',
					$link_count
				),
				array_merge( $changes, [ 'links_affected' => $link_count ] )
			);
			if ( null !== $gate ) {
				return $gate;
			}
		}

		return $this->dispatch( 'PUT', '/terms', [ 'params' => [ 'ID' => $id, 'term_name' => $name, 'term_slug' => $slug, 'term_type' => $type ] ] );
	}
}
