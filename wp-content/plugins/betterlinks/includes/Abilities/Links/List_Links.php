<?php
/**
 * List links ability.
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
 * Lists every short link, grouped by category, with click counts.
 */
class List_Links extends Ability_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'betterlinks/list-links';
		$this->label       = __( 'List BetterLinks short links', 'betterlinks' );
		$this->description = __( 'List short links on this site with their path, target URL, status, category, tags and click count. Supports search, filtering by category, tag or status, and paging.', 'betterlinks' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, bool|float|string>
	 */
	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
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
				'search'   => [ 'type' => 'string', 'description' => __( 'Only links whose title, path or target URL contain this text.', 'betterlinks' ) ],
				'category' => [ 'type' => 'string', 'description' => __( 'Only links in this category, by name or ID.', 'betterlinks' ) ],
				'tag'      => [ 'type' => 'string', 'description' => __( 'Only links carrying this tag, by name or ID.', 'betterlinks' ) ],
				'status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ], 'description' => __( 'Only links with this status.', 'betterlinks' ) ],
				'limit'    => [ 'type' => 'integer', 'description' => __( 'How many links to return. Defaults to 50, maximum 200.', 'betterlinks' ) ],
				'offset'   => [ 'type' => 'integer', 'description' => __( 'Skip this many links, for paging through a long list.', 'betterlinks' ) ],
			],
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
				'data'    => [ 'type' => 'object' ],
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
		$response = $this->dispatch( 'GET', '/links' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// The controller answers with links grouped under their category —
		// convenient for the admin screen, hard work for a caller that just
		// wants "the link on /go/deal". Flatten it, and keep the grouping under
		// by_category for anyone who was reading that shape.
		// The cached payload decodes to stdClass, so normalise to arrays before
		// walking it — otherwise every is_array() check below silently fails and
		// the list comes back empty.
		$grouped = isset( $response['data'] ) ? json_decode( wp_json_encode( $response['data'] ), true ) : [];
		$grouped = is_array( $grouped ) ? $grouped : [];
		$links      = [];
		$categories = [];
		foreach ( $grouped as $term_id => $group ) {
			if ( ! is_array( $group ) || empty( $group['lists'] ) || ! is_array( $group['lists'] ) ) {
				continue;
			}
			foreach ( $group['lists'] as $link ) {
				if ( ! is_array( $link ) || ! isset( $link['ID'] ) ) {
					continue;
				}
				$id = (int) $link['ID'];
				// A link filed under several categories appears once per group;
				// keep one row and collect the categories onto it, so the caller
				// can see them without reading the grouping.
				$categories[ $id ][] = [
					'term_id'   => (string) $term_id,
					'term_name' => isset( $group['term_name'] ) ? $group['term_name'] : '',
					'term_slug' => isset( $group['term_slug'] ) ? $group['term_slug'] : '',
				];
				$link['full_url'] = ! empty( $link['short_url'] ) ? trailingslashit( site_url() ) . ltrim( (string) $link['short_url'], '/' ) : '';
				$links[ $id ]     = $link;
			}
		}
		foreach ( $links as $id => $link ) {
			$links[ $id ]['categories'] = isset( $categories[ $id ] ) ? $categories[ $id ] : [];
		}
		$links = array_values( $links );
		$total = count( $links );

		$links     = $this->filter_links( $links, $input );
		$matched   = count( $links );
		$limit     = isset( $input['limit'] ) ? max( 1, min( 200, absint( $input['limit'] ) ) ) : 50;
		$offset    = isset( $input['offset'] ) ? max( 0, absint( $input['offset'] ) ) : 0;
		$page      = array_slice( $links, $offset, $limit );

		return [
			'success' => true,
			'data'    => [
				'total'       => $total,
				'matched'     => $matched,
				'returned'    => count( $page ),
				'offset'      => $offset,
				'has_more'    => ( $offset + count( $page ) ) < $matched,
				'results'     => $page,
				'by_category' => $grouped,
			],
		];
	}

	/**
	 * Apply the search and filter arguments to the flattened list.
	 *
	 * @param array $links Flattened links.
	 * @param array $input Ability input.
	 * @return array
	 */
	private function filter_links( $links, $input ) {
		$search   = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$category = isset( $input['category'] ) ? strtolower( trim( (string) $input['category'] ) ) : '';
		$tag      = isset( $input['tag'] ) ? strtolower( trim( (string) $input['tag'] ) ) : '';
		$status   = isset( $input['status'] ) ? (string) $input['status'] : '';

		if ( '' === $search && '' === $category && '' === $tag && '' === $status ) {
			return $links;
		}

		$matches_term = static function ( $terms, $needle ) {
			foreach ( (array) $terms as $term ) {
				if ( ! is_array( $term ) ) {
					continue;
				}
				$name = isset( $term['term_name'] ) ? strtolower( (string) $term['term_name'] ) : '';
				$slug = isset( $term['term_slug'] ) ? strtolower( (string) $term['term_slug'] ) : '';
				$id   = isset( $term['term_id'] ) ? (string) $term['term_id'] : '';
				if ( $needle === $name || $needle === $slug || $needle === $id ) {
					return true;
				}
			}
			return false;
		};

		return array_values(
			array_filter(
				$links,
				static function ( $link ) use ( $search, $category, $tag, $status, $matches_term ) {
					if ( '' !== $status && ( ! isset( $link['link_status'] ) || $status !== $link['link_status'] ) ) {
						return false;
					}
					if ( '' !== $search ) {
						$haystack = strtolower(
							(string) ( $link['link_title'] ?? '' ) . ' ' .
							(string) ( $link['short_url'] ?? '' ) . ' ' .
							(string) ( $link['target_url'] ?? '' )
						);
						if ( false === strpos( $haystack, $search ) ) {
							return false;
						}
					}
					if ( '' !== $category ) {
						$cats = array_merge(
							isset( $link['categories'] ) ? (array) $link['categories'] : [],
							isset( $link['cat_data'] ) ? (array) $link['cat_data'] : []
						);
						$own_id = isset( $link['cat_id'] ) ? (string) $link['cat_id'] : '';
						if ( $own_id !== $category && ! $matches_term( $cats, $category ) ) {
							return false;
						}
					}
					if ( '' !== $tag && ! $matches_term( isset( $link['tags_data'] ) ? $link['tags_data'] : [], $tag ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}
}
