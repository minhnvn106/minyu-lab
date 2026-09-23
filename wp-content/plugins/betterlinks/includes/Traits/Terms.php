<?php
namespace BetterLinks\Traits;
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

trait Terms {

	public function tags_analytic( $force_refresh = false ) {

		$analytic = get_option( 'betterlinks_tags_analytics', array() );
		if ( !$force_refresh && count( $analytic ) > 0 ) {
			return $analytic;
		}
		global $wpdb;
		$prefix = $wpdb->prefix;

		$query        = "SELECT t.ID AS tag_id, tr.link_id,COUNT(tr.link_id) AS total_click FROM {$prefix}betterlinks_clicks c
        LEFT JOIN {$prefix}betterlinks_terms_relationships tr ON c.link_id=tr.link_id
        LEFT JOIN {$prefix}betterlinks_terms t ON t.ID=tr.term_id WHERE t.term_type='tags' GROUP BY tag_id,tr.link_id;";
		$total_clicks = $wpdb->get_results( $query, ARRAY_A );

		$prepare_total_clicks = array();
		foreach ( $total_clicks as $value ) {
			if ( isset( $prepare_total_clicks[ $value['tag_id'] ] ) ) {
				$prepare_total_clicks[ $value['tag_id'] ] += $value['total_click'];
				continue;
			}
			$prepare_total_clicks[ $value['tag_id'] ] = $value['total_click'];
		}

		$query         = "SELECT t.ID AS tag_id, COUNT(DISTINCT c.ip) AS unique_clicks FROM {$prefix}betterlinks_terms t LEFT JOIN {$prefix}betterlinks_terms_relationships tr ON t.ID=tr.term_id LEFT JOIN {$prefix}betterlinks_clicks c ON tr.link_id=c.link_id WHERE term_type='tags' GROUP BY t.ID;";
		$unique_clicks = $wpdb->get_results( $query, ARRAY_A );

		$prepare_unique_clicks = array();

		foreach ( $unique_clicks as $value ) {
			$prepare_unique_clicks[ $value['tag_id'] ] = intval( $value['unique_clicks'] );
		}

		$analytic = array(
			'total_clicks'  => $prepare_total_clicks,
			'unique_clicks' => $prepare_unique_clicks,
		);
		update_option( 'betterlinks_tags_analytics', $analytic );
		return $analytic;
	}

	public function get_all_tags() {
		global $wpdb;
		$query = "SELECT t.ID, t.term_name, t.term_slug, COALESCE(tr.link_count, 0) as link_count FROM {$wpdb->prefix}betterlinks_terms AS t LEFT JOIN (SELECT term_id, COUNT(term_id) AS link_count FROM {$wpdb->prefix}betterlinks_terms_relationships GROUP BY term_id) AS tr ON t.ID=tr.term_id WHERE t.term_type='tags'";
		return $wpdb->get_results( $query, ARRAY_A );
	}

	public function categories_analytic( $force_refresh = false ) {
		$analytic = get_option( 'betterlinks_categories_analytics', array() );
		if ( !$force_refresh && count( $analytic ) > 0 ) {
			return $analytic;
		}
		global $wpdb;
		$prefix = $wpdb->prefix;

		$query        = "SELECT t.ID AS category_id, tr.link_id,COUNT(tr.link_id) AS total_click FROM {$prefix}betterlinks_clicks c
        LEFT JOIN {$prefix}betterlinks_terms_relationships tr ON c.link_id=tr.link_id
        LEFT JOIN {$prefix}betterlinks_terms t ON t.ID=tr.term_id WHERE t.term_type='category' GROUP BY category_id,tr.link_id;";
		$total_clicks = $wpdb->get_results( $query, ARRAY_A );

		$prepare_total_clicks = array();
		foreach ( $total_clicks as $value ) {
			if ( isset( $prepare_total_clicks[ $value['category_id'] ] ) ) {
				$prepare_total_clicks[ $value['category_id'] ] += $value['total_click'];
				continue;
			}
			$prepare_total_clicks[ $value['category_id'] ] = $value['total_click'];
		}

		$query         = "SELECT t.ID AS category_id, COUNT(DISTINCT c.ip) AS unique_clicks FROM {$prefix}betterlinks_terms t LEFT JOIN {$prefix}betterlinks_terms_relationships tr ON t.ID=tr.term_id LEFT JOIN {$prefix}betterlinks_clicks c ON tr.link_id=c.link_id WHERE term_type='category' GROUP BY t.ID;";
		$unique_clicks = $wpdb->get_results( $query, ARRAY_A );

		$prepare_unique_clicks = array();

		foreach ( $unique_clicks as $value ) {
			$prepare_unique_clicks[ $value['category_id'] ] = intval( $value['unique_clicks'] );
		}

		$analytic = array(
			'total_clicks'  => $prepare_total_clicks,
			'unique_clicks' => $prepare_unique_clicks,
		);
		update_option( 'betterlinks_categories_analytics', $analytic );
		return $analytic;
	}

	public function get_all_categories() {
		global $wpdb;
		$query = "SELECT t.ID, t.term_name, t.term_slug, COALESCE(tr.link_count, 0) as link_count FROM {$wpdb->prefix}betterlinks_terms AS t LEFT JOIN (SELECT term_id, COUNT(term_id) AS link_count FROM {$wpdb->prefix}betterlinks_terms_relationships GROUP BY term_id) AS tr ON t.ID=tr.term_id WHERE t.term_type='category'";
		return $wpdb->get_results( $query, ARRAY_A );
	}

	public function get_all_terms_data( $args ) {
		if ( isset( $args['ID'] ) ) {
			$results = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type( $args['ID'], $args['term_type'] );
		} else {
			$results = \BetterLinks\Helper::get_terms_all_data();
		}
		return $results;
	}
	public function create_term( $args ) {
		$term_id = \BetterLinks\Helper::insert_term( $args );
		if ( $term_id ) {
			$args['ID']    = $term_id;
			$args['lists'] = array();
			return $args;
		}
		return array();
	}
	public function update_term( $args ) {
		\BetterLinks\Helper::insert_term(
			array(
				'ID'        => $args['cat_id'],
				'term_name' => $args['cat_name'],
				'term_slug' => $args['cat_slug'],
				'term_type' => 'category',
			),
			true
		);
		
		// Return the updated term data in the format expected by the frontend
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT t.ID, t.term_name, t.term_slug, COALESCE(tr.link_count, 0) as link_count FROM {$wpdb->prefix}betterlinks_terms AS t LEFT JOIN (SELECT term_id, COUNT(term_id) AS link_count FROM {$wpdb->prefix}betterlinks_terms_relationships GROUP BY term_id) AS tr ON t.ID=tr.term_id WHERE t.ID=%d AND t.term_type='category'",
			$args['cat_id']
		);
		$updated_term = $wpdb->get_row( $query, ARRAY_A );
		
		return $updated_term ?: $args;
	}
	public function update_tag( $args ) {
		\BetterLinks\Helper::insert_term(
			array(
				'ID'        => $args['ID'],
				'term_name' => $args['term_name'],
				'term_slug' => $args['term_slug'],
				'term_type' => 'tags',
			),
			true
		);
		
		// Return the updated tag data in the format expected by the frontend
		global $wpdb;
		$query = $wpdb->prepare(
			"SELECT t.ID, t.term_name, t.term_slug, COALESCE(tr.link_count, 0) as link_count FROM {$wpdb->prefix}betterlinks_terms AS t LEFT JOIN (SELECT term_id, COUNT(term_id) AS link_count FROM {$wpdb->prefix}betterlinks_terms_relationships GROUP BY term_id) AS tr ON t.ID=tr.term_id WHERE t.ID=%d AND t.term_type='tags'",
			$args['ID']
		);
		$updated_tag = $wpdb->get_row( $query, ARRAY_A );
		
		return $updated_tag ?: $args;
	}
	public function delete_term( $args ) {
		/**
		 * Term IDs that can never be deleted/renamed. Defaults to the built-in
		 * "Uncategorized" category (ID 1); extensions (e.g. the Pro "Link in Bio"
		 * category) add their own protected IDs here.
		 *
		 * Checked against BOTH `cat_id` and `tag_id` on purpose. Categories and tags
		 * share one table so an ID identifies a term regardless of which parameter
		 * carried it, and the admin UI posts `tag_id` from the Categories tab too —
		 * dropping the check on that branch would make protected categories deletable.
		 *
		 * @param int[] $ids
		 */
		$protected = (array) apply_filters( 'betterlinks/protected_term_ids', array( 1 ) );

		if ( isset( $args['cat_id'] ) && '' !== $args['cat_id'] && ! in_array( (int) $args['cat_id'], array_map( 'intval', $protected ), true ) ) {
			\BetterLinks\Helper::delete_term_and_update_term_relationships( $args['cat_id'] );
		}
		if ( isset( $args['tag_id'] ) && '' !== $args['tag_id'] && ! in_array( (int) $args['tag_id'], array_map( 'intval', $protected ), true ) ) {
			\BetterLinks\Helper::delete_term_and_update_term_relationships( $args['tag_id'] );
		}
	}
}
