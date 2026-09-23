<?php
/**
 * Delete click analytics ability.
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
 * Delete recorded clicks for one or more links over a date range.
 *
 * The links themselves are untouched — only their click history goes. Always
 * counts the rows first and asks for confirmation, because click history is not
 * recoverable once deleted.
 */
class Delete_Analytics extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/delete-analytics';
		$this->label       = __( 'Delete click analytics', 'betterlinks' );
		$this->description = __( 'Delete recorded clicks for one or more links within a date range. The links keep working; only their click history is removed.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => false,
			// Click history cannot be recovered once deleted.
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
				'link_ids' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => __( 'IDs of the links whose clicks should be deleted. Required.', 'betterlinks' ),
				],
				'from'     => [ 'type' => 'string', 'description' => __( 'Start date (Y-m-d). Omit for the last 30 days.', 'betterlinks' ) ],
				'to'       => [ 'type' => 'string', 'description' => __( 'End date (Y-m-d). Omit for today.', 'betterlinks' ) ],
				'confirm'  => self::confirm_property(),
			],
			'required'             => [ 'link_ids' ],
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
		$link_ids = isset( $input['link_ids'] ) && is_array( $input['link_ids'] ) ? array_filter( array_map( 'absint', $input['link_ids'] ) ) : [];
		if ( empty( $link_ids ) ) {
			return new \WP_Error(
				'betterlinks_missing_link_ids',
				__( 'Pass at least one link ID in link_ids.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}
		$from = ! empty( $input['from'] ) ? sanitize_text_field( (string) $input['from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = ! empty( $input['to'] ) ? sanitize_text_field( (string) $input['to'] ) : gmdate( 'Y-m-d' );

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $link_ids ), '%d' ) );
		$args         = array_merge( $link_ids, [ $from, $to ] );
		// The placeholder list is built from the count of already-absint'd IDs and
		// passed to prepare() as its single array argument, which wpdb supports;
		// PHPCS cannot count placeholders it did not see written literally.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}betterlinks_clicks WHERE link_id IN ({$placeholders}) AND DATE(created_at) >= %s AND DATE(created_at) <= %s",
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$gate = $this->confirmation_gate(
			$input,
			'delete-analytics',
			sprintf(
				/* translators: 1: number of clicks, 2: number of links, 3: start date, 4: end date */
				__( 'Delete %1$d recorded click(s) for %2$d link(s) between %3$s and %4$s. The links keep working, but this click history cannot be recovered.', 'betterlinks' ),
				$clicks,
				count( $link_ids ),
				$from,
				$to
			),
			[
				'link_ids'        => implode( ', ', $link_ids ),
				'date_range'      => $from . ' → ' . $to,
				'clicks_to_delete' => $clicks,
			]
		);
		if ( null !== $gate ) {
			return $gate;
		}

		if ( 0 === $clicks ) {
			return [
				'success' => true,
				'data'    => [
					'deleted' => 0,
					'message' => __( 'No clicks were recorded for those links in that range; nothing was deleted.', 'betterlinks' ),
				],
			];
		}

		$result = $this->dispatch(
			'DELETE',
			'/clicks/delete_by_links',
			[
				'link_ids' => implode( ',', $link_ids ),
				'from'     => $from,
				'to'       => $to,
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success' => true,
			'data'    => [
				'deleted'    => $clicks,
				'link_ids'   => $link_ids,
				'date_range' => $from . ' → ' . $to,
			],
		];
	}
}
