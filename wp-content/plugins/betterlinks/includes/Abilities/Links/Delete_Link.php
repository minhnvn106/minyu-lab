<?php
/**
 * Delete a short link ability.
 *
 * @package BetterLinks\Abilities\Links
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Links;

use BetterLinks\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Permanently delete a short link and its click data.
 */
class Delete_Link extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/delete-link';
		$this->label       = __( 'Delete a short link', 'betterlinks' );
		$this->description = __( 'Permanently delete a short link and its click data.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => false,
			// Removes the link, its clicks and its term relations for good.
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
				'ID'      => [ 'type' => 'integer', 'description' => __( 'The link ID to delete. Required.', 'betterlinks' ) ],
				'confirm' => self::confirm_property(),
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
			return new \WP_Error( 'betterlinks_missing_link_id', __( 'A link ID is required to delete a link.', 'betterlinks' ), [ 'status' => 400 ] );
		}
		$row = \BetterLinks\Helper::get_link_by_ID( $id );
		$row = is_array( $row ) && ! empty( $row ) ? (array) current( $row ) : [];
		if ( empty( $row ) ) {
			return new \WP_Error(
				'betterlinks_link_not_found',
				sprintf(
					/* translators: %d: link ID */
					__( 'No link with ID %d exists.', 'betterlinks' ),
					$id
				),
				[ 'status' => 404 ]
			);
		}
		$short_url = ! empty( $row['short_url'] ) ? $row['short_url'] : '';

		// Deleting takes the redirect down and drops its click history with it;
		// neither comes back. Say exactly what goes before doing it.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off count for the confirmation summary; caching it would show a stale number.
		$clicks = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}betterlinks_clicks WHERE link_id = %d", $id ) );
		$gate   = $this->confirmation_gate(
			$input,
			'delete-link',
			sprintf(
				/* translators: 1: short URL, 2: link ID */
				__( 'Permanently delete the link "%1$s" (ID %2$d). The short URL stops working and its click history is deleted. This cannot be undone.', 'betterlinks' ),
				$short_url,
				$id
			),
			[
				'short_url'   => $short_url,
				'link_title'  => isset( $row['link_title'] ) ? $row['link_title'] : '',
				'target_url'  => isset( $row['target_url'] ) ? $row['target_url'] : '',
				'clicks_lost' => $clicks,
			]
		);
		if ( null !== $gate ) {
			return $gate;
		}

		return $this->dispatch( 'DELETE', '/links/' . $id, [ 'id' => $id, 'ID' => $id, 'short_url' => $short_url ] );
	}
}
