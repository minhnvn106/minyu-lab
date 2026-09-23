<?php
/**
 * Read a single short link.
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
 * Look up one link by ID, short URL or target URL.
 *
 * list-links returns every link on the site, which is a large payload to read
 * just to answer "what does /go/deal point at?" — and the REST route for a
 * single link is a stub that answers with an empty array, so there was no way
 * to read one link over MCP at all.
 */
class Get_Link extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/get-link';
		$this->label       = __( 'Get a short link', 'betterlinks' );
		$this->description = __( 'Look up a single short link by its ID, its short URL, or the target URL it points at.', 'betterlinks' );
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
				'ID'         => [ 'type' => 'integer', 'description' => __( 'The link ID.', 'betterlinks' ) ],
				'short_url'  => [ 'type' => 'string', 'description' => __( 'The link\'s path, e.g. "go/deal". Prefix included, no leading slash.', 'betterlinks' ) ],
				'target_url' => [ 'type' => 'string', 'description' => __( 'Find links pointing at this destination URL.', 'betterlinks' ) ],
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
		$id         = isset( $input['ID'] ) ? absint( $input['ID'] ) : 0;
		$short_url  = isset( $input['short_url'] ) ? trim( (string) $input['short_url'], '/ ' ) : '';
		$target_url = isset( $input['target_url'] ) ? esc_url_raw( (string) $input['target_url'] ) : '';

		if ( ! $id && '' === $short_url && '' === $target_url ) {
			return new \WP_Error(
				'betterlinks_missing_lookup',
				__( 'Pass an ID, a short_url or a target_url to look a link up.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}

		if ( $id ) {
			$rows = \BetterLinks\Helper::get_link_by_ID( $id );
		} elseif ( '' !== $short_url ) {
			$rows = \BetterLinks\Helper::get_link_by_short_url( $short_url );
		} else {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- direct lookup by target URL; there is no cache keyed this way.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks WHERE target_url = %s", $target_url ),
				ARRAY_A
			);
		}

		$rows = is_array( $rows ) ? array_values( $rows ) : [];
		if ( empty( $rows ) ) {
			return [
				'success' => true,
				'data'    => [
					'found'   => 0,
					'results' => [],
					'message' => __( 'No link matches that lookup.', 'betterlinks' ),
				],
			];
		}

		foreach ( $rows as $index => $row ) {
			$row = (array) $row;
			if ( ! empty( $row['ID'] ) ) {
				$categories = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type( $row['ID'], 'category' );
				$tags       = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type( $row['ID'], 'tags' );
				$row['cat_data']  = ! empty( $categories ) ? $categories : [];
				$row['tags_data'] = ! empty( $tags ) ? $tags : [];
			}
			// The path a visitor actually types, so the caller does not have to
			// work out whether the prefix is already part of short_url.
			$row['full_url'] = ! empty( $row['short_url'] ) ? trailingslashit( site_url() ) . ltrim( (string) $row['short_url'], '/' ) : '';
			if ( isset( $row['param_struct'] ) && is_string( $row['param_struct'] ) && '' !== $row['param_struct'] ) {
				$decoded             = maybe_unserialize( $row['param_struct'] );
				$row['param_struct'] = is_array( $decoded ) ? $decoded : $row['param_struct'];
			}
			// Match create/update: a plain boolean, not a JSON string.
			$favorite        = isset( $row['favorite'] ) ? $row['favorite'] : null;
			$favorite        = is_string( $favorite ) ? json_decode( $favorite, true ) : $favorite;
			$row['favorite'] = is_array( $favorite ) ? ! empty( $favorite['favForAll'] ) : (bool) $favorite;
			$rows[ $index ]  = $row;
		}

		return [
			'success' => true,
			'data'    => [
				'found'   => count( $rows ),
				'results' => $rows,
			],
		];
	}
}
