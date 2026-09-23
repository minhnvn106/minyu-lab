<?php
namespace BetterLinks\Tools\Migration;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BaseCSV {

	// Tracks whether the most recent insert_link() call created a new link or
	// overwrote an existing one. Importers read this to decide between
	// "Imported Successfully" and "Updated existing" log messages.
	protected $last_operation = 'inserted';

	public function insert_link( $item ) {
		if ( ! isset( $item['short_url'] ) ) {
			return;
		}
		$link    = \BetterLinks\Helper::get_link_by_short_url( $item['short_url'] );
		$link_id = 0;
		if ( count( $link ) > 0 ) {
			$this->last_operation = 'updated';
			$item['ID']           = current( $link )['ID'];
			$link_id              = \BetterLinks\Helper::insert_link( $item, true );
			\BetterLinks\Helper::remove_terms_relationships_by_link_ID( $link_id );
		} else {
			$this->last_operation = 'inserted';
			$link_id              = \BetterLinks\Helper::insert_link( $item );
		}
		$tags      = \BetterLinks\Helper::insert_tags_terms( ( ! empty( $item['tags'] ) ? explode( ',', $item['tags'] ) : array() ) );
		$category  = \BetterLinks\Helper::insert_category_terms( ( ! empty( $item['category'] ) ? explode( ',', $item['category'] ) : array( 'uncategorized' ) ) );
		$all_terms = array_merge( $tags, $category );
		if ( count( $all_terms ) > 0 && $link_id > 0 ) {
			foreach ( $all_terms as $term ) {
				\BetterLinks\Helper::insert_terms_relationships( $term, $link_id );
			}
		}
		return $link_id;
	}

	public function insert_keywords( $link_id, $keywords, $arg = array(), $meta_key = 'keywords', $update = false ) {
		$results        = \BetterLinks\Helper::get_link_meta( $link_id, $meta_key );
		$arg['link_id'] = $link_id;
		if ( empty( $results ) || $update ) {
			$args = wp_parse_args(
				$arg,
				array(
					'keywords'       => $keywords,
					'link_id'        => $link_id,
					'post_type'      => array( 'post', 'page' ),
					'category'       => '',
					'tags'           => '',
					'open_new_tab'   => false,
					'use_no_follow'  => false,
					'case_sensitive' => false,
					'left_boundary'  => '',
					'right_boundary' => '',
					'keyword_before' => '',
					'keyword_after'  => '',
					'limit'          => 100,
					'priority'       => '',
				)
			);
			return \BetterLinks\Helper::add_link_meta( $link_id, $meta_key, wp_json_encode( $args ) );
		}
		return;
	}
}
