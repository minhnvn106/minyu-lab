<?php

namespace BetterLinks\Traits;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\Cache;

// phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.DB.SlowDBQuery

trait Query {


	public static function insert_link( $item, $is_update = false ) {
		global $wpdb;
		if ( $is_update ) {
			// An update without an ID cannot do anything — bail before we
			// generate PHP warnings by reading a missing 'ID' key four times.
			$id = isset( $item['ID'] ) ? $item['ID'] : null;
			if ( null === $id || '' === $id ) {
				return;
			}
			$item['ID'] = $id;
			// get_link_by_ID() returns an empty array for an ID that is no longer
			// in the table (migrations, stale caches), and current( array() ) is
			// false — wp_parse_args( $item, false ) is deprecated on PHP 8.1+ and
			// becomes a TypeError later. Fall back to the incoming item instead.
			$defaults              = self::get_link_by_ID( $id );
			$defaults              = is_array( $defaults ) && ! empty( $defaults ) ? current( $defaults ) : array();
			$item                  = is_array( $defaults ) ? wp_parse_args( $item, $defaults ) : $item;
			$link_data_array       = array(
				'link_author'       => $item['link_author'] ?? '',
				'link_date'         => $item['link_date'] ?? '',
				'link_date_gmt'     => $item['link_date_gmt'] ?? '',
				'link_title'        => $item['link_title'] ?? '',
				'link_slug'         => $item['link_slug'] ?? '',
				'link_note'         => $item['link_note'] ?? '',
				'link_status'       => $item['link_status'] ?? '',
				'nofollow'          => $item['nofollow'] ?? '',
				'sponsored'         => $item['sponsored'] ?? '',
				'track_me'          => $item['track_me'] ?? '',
				'param_forwarding'  => $item['param_forwarding'] ?? '',
				'param_struct'      => $item['param_struct'] ?? '',
				'redirect_type'     => $item['redirect_type'] ?? '',
				'target_url'        => $item['target_url'] ?? '',
				'short_url'         => $item['short_url'] ?? '',
				'link_order'        => $item['link_order'] ?? '',
				'link_modified'     => $item['link_modified'] ?? '',
				'link_modified_gmt' => $item['link_modified_gmt'] ?? '',
				'wildcards'         => $item['wildcards'] ?? '',
				'expire'            => $item['expire'] ?? '',
				'dynamic_redirect'  => $item['dynamic_redirect'] ?? '',
			);
			$link_data_place_array = array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			);
			if ( isset( $item['favorite'] ) ) {
				$link_data_array['favorite'] = $item['favorite'];
				$link_data_place_array[]     = '%s';
			}
			if ( isset( $item['uncloaked'] ) ) {
				$link_data_array['uncloaked'] = $item['uncloaked'];
				$link_data_place_array[]      = '%s';
			}
			$wpdb->update(
				"{$wpdb->prefix}betterlinks",
				$link_data_array,
				array( 'ID' => $item['ID'] ),
				$link_data_place_array,
				array( '%d' )
			);
			do_action( 'betterlinks/after_update_link', $item['ID'], $item );
			return $item['ID'];
		} else {
			$betterlinks = self::get_link_by_short_url( $item['short_url'] );
			if ( count( $betterlinks ) === 0 ) {
				$initial_defaults_arr = array(
					'link_author'       => get_current_user_id(),
					'link_date'         => current_time( 'mysql' ),
					'link_date_gmt'     => current_time( 'mysql', 1 ),
					'link_title'        => '',
					'link_slug'         => '',
					'link_note'         => '',
					'link_status'       => 'publish',
					'nofollow'          => '',
					'sponsored'         => '',
					'track_me'          => '',
					'param_forwarding'  => '',
					'param_struct'      => '',
					'redirect_type'     => '',
					'target_url'        => '',
					'short_url'         => '',
					'link_order'        => '',
					'link_modified'     => current_time( 'mysql' ),
					'link_modified_gmt' => current_time( 'mysql', 1 ),
					'wildcards'         => '',
					'expire'            => '',
					'dynamic_redirect'  => '',
				);
				if ( isset( $item['favorite'] ) ) {
					$initial_defaults_arr['favorite'] = '';
				}
				$defaults            = apply_filters( 'betterlinks/insert_link_default_args', $initial_defaults_arr );
				$item                = wp_parse_args( $item, $defaults );
				$column_names        = 'link_author,link_date,link_date_gmt,link_title,link_slug,link_note,link_status,nofollow,sponsored,track_me,param_forwarding,param_struct,redirect_type,target_url,short_url,link_order,link_modified,link_modified_gmt,wildcards,expire,dynamic_redirect';
				$column_placeholders = '%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s';
				$query_value_array   = array(
					$item['link_author'],
					$item['link_date'],
					$item['link_date_gmt'],
					$item['link_title'],
					$item['link_slug'],
					$item['link_note'],
					$item['link_status'],
					$item['nofollow'],
					$item['sponsored'],
					$item['track_me'],
					$item['param_forwarding'],
					$item['param_struct'],
					$item['redirect_type'],
					$item['target_url'],
					$item['short_url'],
					$item['link_order'],
					$item['link_modified'],
					$item['link_modified_gmt'],
					$item['wildcards'],
					$item['expire'],
					$item['dynamic_redirect'],
				);
				if ( isset( $item['favorite'] ) ) {
					$column_names        .= ',favorite';
					$column_placeholders .= ', %s';
					$query_value_array[]  = $item['favorite'];
				}
				if ( isset( $item['uncloaked'] ) ) {
					$column_names        .= ',uncloaked';
					$column_placeholders .= ', %s';
					$query_value_array[]  = $item['uncloaked'];
				}
				$query_string = "INSERT INTO {$wpdb->prefix}betterlinks ( {$column_names} ) VALUES ( {$column_placeholders} )";
				$wpdb->query( $wpdb->prepare( $query_string, $query_value_array ) );
				do_action( 'betterlinks/after_insert_link', $wpdb->insert_id, $item );
				return $wpdb->insert_id;
			}
		}
		return;
	}
	public static function delete_link( $ID ) {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}betterlinks", array( 'ID' => $ID ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}betterlinks_clicks", array( 'link_id' => $ID ), array( '%d' ) );
		$wpdb->delete( "{$wpdb->prefix}betterlinks_terms_relationships", array( 'link_id' => $ID ), array( '%d' ) );

		/**
		 * Fires after a link and its owned rows are removed.
		 *
		 * Extensions that store their own references to a link id clean them up here —
		 * Pro's Promo Cards use it in place of the FOREIGN KEY constraints its tables
		 * used to declare.
		 *
		 * @param int $ID Deleted link ID.
		 */
		do_action( 'betterlinks/link/after_delete', $ID );
	}
	public static function remove_terms_relationships_by_link_ID( $ID ) {
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}betterlinks_terms_relationships", array( 'link_id' => $ID ), array( '%d' ) );
	}
	public static function get_prepare_all_links() {
		global $wpdb;
		$prefix   = $wpdb->prefix;
		$analytic = get_option( 'betterlinks_analytics_data' );
		$analytic = $analytic ? json_decode( $analytic, true ) : array();

		// Compatibility: BetterLinks Pro before 3.0.4 relies on this to show broken
		// link status; newer Pro sets it through betterlinks/admin/link_item.
		$broken_links = \BetterLinks\Helper::pro_needs_update() ? get_option( 'betterlinkspro_broken_links_logs' ) : false;
		$broken_links = $broken_links ? json_decode( $broken_links, true ) : array();

		$settings = Cache::get_json_settings();

		// Categories a feature owns but does not want on the dashboard (Fluent
		// Boards' task category, the bio pages' "Link in Bio" category). Each
		// feature contributes its own term IDs and they are combined into one
		// exclusion, so adding a second one no longer overwrites the first.
		$hidden_term_ids = apply_filters( 'betterlinks/dashboard_hidden_term_ids', array(), $settings );
		$hidden_term_ids = array_unique( array_filter( array_map( 'intval', (array) $hidden_term_ids ) ) );

		// Back-compat: the original Fluent Boards filter returns a whole WHERE
		// clause rather than IDs. Keep honouring it and AND the ID list onto it.
		$fbs_category_query = apply_filters( 'betterlinks__intlfbs_filter_category_from_dashboard', '', $settings );

		if ( ! empty( $hidden_term_ids ) ) {
			$hidden_clause      = sprintf( 'bt.ID NOT IN (%s)', implode( ',', $hidden_term_ids ) );
			$fbs_category_query = empty( $fbs_category_query )
				? 'WHERE ' . $hidden_clause
				: $fbs_category_query . ' AND ' . $hidden_clause;
		}

		$query = "SELECT
            bt.ID as cat_id,
            bt.term_name,
            bt.term_slug,
            bt.term_type,
            bl.ID,
            bl.link_title,
            bl.link_slug,
            bl.link_note,
            bl.link_status,
            bl.nofollow,
            bl.sponsored,
            bl.track_me,
            bl.param_forwarding,
            bl.param_struct,
            bl.redirect_type,
            bl.target_url,
            bl.short_url,
            bl.link_date,
            bl.wildcards,
            bl.expire,
            bl.favorite,
            bl.dynamic_redirect,
            bl.uncloaked
            FROM {$prefix}betterlinks_terms as bt
            LEFT JOIN  {$prefix}betterlinks_terms_relationships as btr ON bt.ID = btr.term_id
            LEFT JOIN  {$prefix}betterlinks as bl ON bl.ID = btr.link_id
            -- WHERE bt.term_type = 'category'
			{$fbs_category_query}
            ORDER BY bl.link_order ASC;";

		$results = $wpdb->get_results(
			$query,
			OBJECT
		);
		$results = \BetterLinks\Helper::parse_link_response( $results, $analytic, $broken_links );
		return $results;
	}
	public static function get_link_by_short_url( $short_url, $is_case_sensitive = false ) {
		global $wpdb;
		$link = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks WHERE short_url=%s", $short_url ),
			ARRAY_A
		);
		if ( isset( $link[0]['short_url'] ) && $is_case_sensitive && $link[0]['short_url'] != $short_url ) {
			return array();
		}
		return $link;
	}
	public static function get_link_by_permalink( $target_url, $fields = '*' ) {
		global $wpdb;
		$link = $wpdb->get_row(
			$wpdb->prepare( "SELECT {$fields} FROM {$wpdb->prefix}betterlinks WHERE target_url=%s", $target_url ),
			ARRAY_A
		);
		return ! empty( $link ) ? $link : array();
	}
	public static function get_link_by_wildcards( $wildcards ) {
		global $wpdb;
		$link = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks WHERE wildcards=%d", $wildcards ),
			ARRAY_A
		);
		return $link;
	}
	public static function get_link_by_ID( $ID ) {
		global $wpdb;
		$link = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks WHERE ID=%d", $ID ),
			ARRAY_A
		);
		return $link;
	}
	public static function get_link_data_with_cat_id_by_link_id( $ID ) {
		global $wpdb;
		$link = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT 
            bt.ID as cat_id,
            bl.ID,
            bl.target_url,
            bl.short_url,
            bl.uncloaked
            FROM {$wpdb->prefix}betterlinks as bl
            INNER JOIN {$wpdb->prefix}betterlinks_terms_relationships as btr ON bl.ID = btr.link_id AND bl.ID=%d
            INNER JOIN {$wpdb->prefix}betterlinks_terms as bt ON bt.ID = btr.term_id AND bt.term_type = 'category'
            ",
				$ID
			),
			ARRAY_A
		);
		return $link;
	}

	/**
	 * Get All BetterLinks Uploads Links JSON File
	 *
	 * @return array
	 */
	public static function get_links_for_json() {
		global $wpdb;
		$prefix                                    = $wpdb->prefix;
		$formattedArray                            = array();
		// Changed from INNER JOIN to LEFT JOIN to include links without category assignments
		// This prevents links from disappearing when category relationships are delayed
		$items                                     = $wpdb->get_results(
			"SELECT
            bl.ID,
            bl.redirect_type,
            bl.short_url,
            bl.link_slug,
            bl.link_status,
            bl.target_url,
            bl.nofollow,
            bl.sponsored,
            bl.param_forwarding,
            bl.track_me,
            bl.wildcards,
            bl.expire,
            bl.dynamic_redirect,
            bl.uncloaked,
            br.term_id as cat_id
            FROM {$prefix}betterlinks as bl
            LEFT JOIN {$prefix}betterlinks_terms_relationships as br ON bl.ID = br.link_id
            LEFT JOIN {$prefix}betterlinks_terms as bt ON br.term_id = bt.ID AND bt.term_type = 'category'
            GROUP BY bl.ID
            ORDER BY bl.ID DESC
        "
		);
		$options                                   = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );
		$formattedArray['is_case_sensitive']       = isset( $options['is_case_sensitive'] ) ? $options['is_case_sensitive'] : false;
		$formattedArray['is_disable_analytics_ip'] = isset( $options['is_disable_analytics_ip'] ) ? $options['is_disable_analytics_ip'] : false;
		$is_links_case_sensitive                   = $formattedArray['is_case_sensitive'];
		if ( ! empty( $options ) ) {
			$formattedArray['wildcards_is_active']         = isset( $options['wildcards'] ) ? $options['wildcards'] : false;
			$formattedArray['disablebotclicks']            = isset( $options['disablebotclicks'] ) ? $options['disablebotclicks'] : false;
			$formattedArray['force_https']                 = isset( $options['force_https'] ) ? $options['force_https'] : false;
			$formattedArray['autolink_disable_post_types'] = isset( $options['autolink_disable_post_types'] ) ? $options['autolink_disable_post_types'] : array();
			$formattedArray['is_autolink_icon']            = isset( $options['is_autolink_icon'] ) ? $options['is_autolink_icon'] : false;
			$formattedArray['is_autolink_headings']        = isset( $options['is_autolink_headings'] ) ? $options['is_autolink_headings'] : false;
			$formattedArray['uncloaked_categories']        = isset( $options['uncloaked_categories'] ) ? $options['uncloaked_categories'] : array();
		}
		if ( is_array( $items ) && count( $items ) > 0 ) {
			foreach ( $items as $item ) {
				$short_url = $is_links_case_sensitive ? $item->short_url : strtolower( $item->short_url );
				if ( $item->wildcards == true ) {
					$formattedArray['wildcards'][ $short_url ] = $item;
				} else {
					$formattedArray['links'][ $short_url ] = $item;
				}
			}
		}
		/**
		 * Filters the redirect payload written to links.json and the links cache.
		 *
		 * @param array $payload Payload.
		 */
		$formattedArray = apply_filters( 'betterlinks/links_json_payload', $formattedArray );
		// Compatibility: BetterLinks Pro before 3.0.4 reads its Google Analytics and
		// Pixel settings from this payload. Newer Pro reads its own option, so the
		// credentials are no longer written into the public links.json file.
		if ( \BetterLinks\Helper::pro_needs_update() && defined( 'BETTERLINKS_PRO_EXTERNAL_ANALYTICS_OPTION_NAME' ) && BETTERLINKS_PRO_EXTERNAL_ANALYTICS_OPTION_NAME ) {
			$analytic_data = get_option( BETTERLINKS_PRO_EXTERNAL_ANALYTICS_OPTION_NAME, array() );
			if ( is_array( $analytic_data ) ) {
				$formattedArray = array_merge( $analytic_data, $formattedArray );
			} else {
				$analytic_data  = is_string( $analytic_data ) ? json_decode( $analytic_data, true ) : array();
				$formattedArray = array_merge( $analytic_data, $formattedArray );
			}
		}
		return $formattedArray;
	}

	public static function insert_term( $item, $is_update = false ) {
		global $wpdb;
		if ( $is_update ) {
			$wpdb->update(
				"{$wpdb->prefix}betterlinks_terms",
				array(
					'term_name' => $item['term_name'],
					'term_slug' => $item['term_slug'],
					'term_type' => $item['term_type'],
				),
				array( 'ID' => $item['ID'] ),
				array(
					'%s',
					'%s',
					'%s',
				),
				array( '%d' )
			);
			return $item['ID'];
		} else {
			$terms = self::get_term_by_slug( $item['term_slug'], $item['term_type'] );
			if ( count( $terms ) === 0 ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}betterlinks_terms ( term_name, term_slug, term_type ) VALUES ( %s, %s, %s )",
						array( $item['term_name'], $item['term_slug'], $item['term_type'] )
					)
				);
				return $wpdb->insert_id;
			} elseif ( isset( current( $terms )['ID'] ) ) {
				return current( $terms )['ID'];
			}
		}
		return;
	}
	public static function insert_tags_terms( $tags ) {
		$terms_ids = array();
		if ( is_array( $tags ) && count( $tags ) > 0 ) {
			foreach ( $tags as $tag ) {
				$insert_id = self::insert_term(
					array(
						'term_name' => $tag,
						'term_slug' => \BetterLinks\Helper::make_slug( $tag ),
						'term_type' => 'tags',
					)
				);
				if ( $insert_id ) {
					$terms_ids[] = $insert_id;
				}
			}
		}
		return $terms_ids;
	}

	public static function insert_category_terms( $categories ) {
		$terms_ids = array();
		if ( is_array( $categories ) && count( $categories ) > 0 ) {
			foreach ( $categories as $category ) {
				$insert_id = self::insert_term(
					array(
						'term_name' => $category,
						'term_slug' => \BetterLinks\Helper::make_slug( $category ),
						'term_type' => 'category',
					)
				);
				if ( $insert_id ) {
					$terms_ids[] = $insert_id;
				}
			}
		}
		return $terms_ids;
	}
	public static function insert_terms_relationships( $term_id, $link_id ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}betterlinks_terms_relationships ( term_id, link_id ) VALUES ( %d, %d )",
				array( $term_id, $link_id )
			)
		);
		return $wpdb->insert_id;
	}

	/**
	 * Extra WHERE condition for analytics queries, supplied by extensions.
	 *
	 * Listeners on `betterlinks/analytics/where_clause` return
	 * `array( 'sql' => 'col NOT IN (%s, %s)', 'params' => array( ... ) )`, using only
	 * %s/%d/%f placeholders; values are bound by the caller's $wpdb->prepare().
	 * A condition whose placeholders and values do not line up is ignored.
	 *
	 * @param string $column  Qualified IP column in the query ('ip', 'c.ip', 'CLICKS.ip').
	 * @param array  $context Report context (report, from, to, ...).
	 * @return array{sql:string,params:array}
	 */
	public static function analytics_extra_where( $column, array $context = array() ) {
		$context['column'] = $column;
		$extra  = apply_filters( 'betterlinks/analytics/where_clause', array( 'sql' => '', 'params' => array() ), $context );
		$sql    = is_array( $extra ) && isset( $extra['sql'] ) && is_string( $extra['sql'] ) ? trim( $extra['sql'] ) : '';
		$params = is_array( $extra ) && isset( $extra['params'] ) && is_array( $extra['params'] ) ? array_values( $extra['params'] ) : array();
		if ( '' === $sql
			|| preg_match_all( '/(?<!%)%[sdf]/', $sql ) !== count( $params )
			|| count( array_filter( $params, 'is_scalar' ) ) !== count( $params ) ) {
			return array( 'sql' => '', 'params' => array() );
		}
		return array( 'sql' => $sql, 'params' => $params );
	}

	/**
	 * Delete term and update Term relationship to uncategorized
	 *
	 * @param term_id
	 * @return boolean
	 */
	public static function delete_term_and_update_term_relationships( $term_id ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		$is_delete = $wpdb->delete( $wpdb->prefix . 'betterlinks_terms', array( 'ID' => $term_id ), array( '%d' ) );
		if ( $is_delete ) {
			$term = self::get_term_by_slug( 'uncategorized' );
			if ( count( $term ) > 0 ) {
				$wpdb->update(
					"{$wpdb->prefix}betterlinks_terms_relationships",
					array(
						'term_id' => current( $term )['ID'],
					),
					array( 'term_id' => $term_id ),
					array(
						'%d',
					),
					array( '%d' )
				);
			}
		}
		$wpdb->query( 'COMMIT' );
		return $is_delete;
	}

	public static function insert_terms_and_terms_relationship( $link_id, $request ) {
		global $wpdb;
		$term_data   = array();
		$newTermList = array();
		
		// If no category is provided, check for default category setting.
		//
		// The stored value must be a real term ID. A non-numeric value falls
		// through to the "new category" branch below, which treats cat_id as a
		// NAME and creates a term called after it — so a boolean `true` in
		// settings (which is what some saves write) minted a junk category
		// literally named "1" on every link created without a category.
		if ( empty( $request['cat_id'] ) ) {
			$settings = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );
			$default  = isset( $settings['default_category'] ) ? $settings['default_category'] : null;

			if ( is_numeric( $default ) && (int) $default > 0 ) {
				$request['cat_id'] = (int) $default;
			} else {
				// Fallback to Uncategorized category (ID 1)
				$request['cat_id'] = 1;
			}
		}
		
		// store tags relation data
		if ( ! empty( $request['cat_id'] ) ) {
			$is_new_cat = true;
			if ( is_numeric( $request['cat_id'] ) ) {
				$query  = $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}betterlinks_terms WHERE id = %d ",
					$request['cat_id']
				);
				$result = $wpdb->get_row( $query, 'ARRAY_A' );
				if ( isset( $result['term_slug'] ) ) {
					$is_new_cat  = false;
					$term_data[] = array(
						'term_id'   => $request['cat_id'],
						'link_id'   => $link_id,
						'term_slug' => $result['term_slug'],
						'term_name' => $result['term_name'],
						'term_type' => 'category',
					);
				}
			}
			// A NUMERIC cat_id is an ID, never a name. If it did not resolve above
			// the term is gone (a stale default_category, say) — fall back to
			// Uncategorized rather than minting a category literally named "42".
			// Non-numeric values are genuine "user typed a new category" input and
			// still create a term.
			if ( $is_new_cat && is_numeric( $request['cat_id'] ) ) {
				$is_new_cat = false;
				$fallback   = self::get_term_by_slug( 'uncategorized' );
				if ( count( $fallback ) > 0 ) {
					$fallback    = current( $fallback );
					$term_data[] = array(
						'term_id'   => $fallback['ID'],
						'link_id'   => $link_id,
						'term_slug' => $fallback['term_slug'],
						'term_name' => $fallback['term_name'],
						'term_type' => 'category',
					);
				}
			}
			if ( $is_new_cat ) {
				$newTermList[] = array(
					'term_name' => $request['cat_id'],
					'term_slug' => isset( $request['cat_slug'] ) ? $request['cat_slug'] : $request['cat_id'],
					'term_type' => 'category',
				);
			}
		}
		if ( isset( $request['tags_id'] ) && is_array( $request['tags_id'] ) ) {
			foreach ( $request['tags_id'] as $key => $value ) {
				$is_new_tag = true;
				if ( is_numeric( $value ) ) {
					$query  = $wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}betterlinks_terms WHERE id = %d ",
						$value
					);
					$result = $wpdb->get_row( $query, 'ARRAY_A' );
					if ( isset( $result['term_slug'] ) ) {
						$term_data[] = array(
							'link_id'   => $link_id,
							'term_id'   => $value,
							'term_slug' => $result['term_slug'],
							'term_name' => $result['term_name'],
							'term_type' => 'tags',
						);
						$is_new_tag  = false;
					}
				}
				if ( $is_new_tag ) {
					$newTermList[] = array(
						'term_name' => $value,
						'term_slug' => $value,
						'term_type' => 'tags',
					);
				}
			}
		}

		// insert new tags or category
		if ( count( $newTermList ) > 0 ) {
			foreach ( $newTermList as $item ) {
				$term_id     = \BetterLinks\Helper::insert_term( $item );
				$term_data[] = array(
					'link_id'          => $link_id,
					'term_id'          => $term_id,
					'term_type'        => $item['term_type'],
					'term_name'        => $item['term_name'],
					'term_slug'        => $item['term_slug'],
					'is_newly_created' => true,
				);
			}
		}
		// make term and link relation
		if ( count( $term_data ) > 0 ) {
			$is_delete = $wpdb->delete( $wpdb->prefix . 'betterlinks_terms_relationships', array( 'link_id' => $link_id ), array( '%d' ) );
			if ( $is_delete || $is_delete === 0 ) {
				foreach ( $term_data as $term ) {
					\BetterLinks\Helper::insert_terms_relationships( $term['term_id'], $term['link_id'] );
				}
			}
		}
		return $term_data;
	}

	public static function is_term_exists( $term_id, $type = 'category' ) {
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks_terms WHERE ID=%s AND term_type=%s", $term_id, $type ),
			ARRAY_A
		);
		return count( $result ) === 1;
	}

	public static function get_term_by_slug( $slug, $type = 'category' ) {
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks_terms WHERE term_slug=%s AND term_type=%s", $slug, $type ),
			ARRAY_A
		);
		return $result;
	}

	// Get term by ID for AI
	public static function get_term_by_id( $term_id, $type = 'category' ) {
		global $wpdb;
		$result = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}betterlinks_terms WHERE ID=%d AND term_type=%s", $term_id, $type ),
			ARRAY_A
		);
		return $result;
	}

	public static function get_terms_by_link_ID_and_term_type( $link_ID, $term_type = 'categroy' ) {
		global $wpdb;
		$prefix = $wpdb->prefix;
		$link   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
            {$prefix}betterlinks_terms.ID as term_id,
            {$prefix}betterlinks_terms.term_name,
            {$prefix}betterlinks_terms.term_slug,
            {$prefix}betterlinks_terms.term_type
            FROM {$prefix}betterlinks_terms
            LEFT JOIN  {$prefix}betterlinks_terms_relationships ON {$prefix}betterlinks_terms.ID = {$prefix}betterlinks_terms_relationships.term_id
            LEFT JOIN  {$prefix}betterlinks ON {$prefix}betterlinks.ID = {$prefix}betterlinks_terms_relationships.link_id
            WHERE {$prefix}betterlinks_terms_relationships.link_id = %d
            AND {$prefix}betterlinks_terms.term_type = %s",
				$link_ID,
				$term_type
			),
			ARRAY_A
		);
		return $link;
	}

	public static function get_terms_all_data() {
		global $wpdb;
		$query = "SELECT t.ID, t.term_name, t.term_slug, t.term_type, t.term_order, COALESCE(tr.link_count, 0) as link_count FROM {$wpdb->prefix}betterlinks_terms AS t LEFT JOIN (SELECT term_id, COUNT(term_id) AS link_count FROM {$wpdb->prefix}betterlinks_terms_relationships GROUP BY term_id) AS tr ON t.ID=tr.term_id ORDER BY t.term_order ASC, t.term_name ASC";
		$link = $wpdb->get_results( $query, ARRAY_A );
		return $link;
	}

	public static function insert_click( $item ) {
		global $wpdb;
		$betterlinks                       = array();
		$is_extra_data_tracking_compatible = apply_filters( 'betterlinks/is_extra_data_tracking_compatible', false );
		if ( isset( $item['short_url'] ) ) {
			$betterlinks = self::get_link_by_short_url( $item['short_url'] );
		} elseif ( isset( $item['link_id'] ) ) {
			$betterlinks = self::get_link_by_ID( $item['link_id'] );
		}
		if( empty( $betterlinks ) ){
			return;
		}
		$is_analytics_ip_enabled = isset( $item['ip'] ) && isset( $item['host'] );

		$addedPlaceholderString  = $is_analytics_ip_enabled ? ' created_at_gmt, rotation_target_url, ip, host ' : ' created_at_gmt, rotation_target_url ';
		$addedDbColumnsString    = $is_analytics_ip_enabled ? ' %s, %s, %s, %s ' : ' %s, %s ';

		/**
		 * Filters the country row ID stored with a click (0 = none).
		 * BetterLinks Pro resolves it from the click's country code and name.
		 *
		 * @param int   $country_id Country row ID.
		 * @param array $item       Click row data.
		 */
		$country_id = (int) apply_filters( 'betterlinks/click/country_id', isset( $item['country_id'] ) ? absint( $item['country_id'] ) : 0, $item );
		if ( $country_id > 0 ) {
			$addedPlaceholderString .= ', country_id';
			$addedDbColumnsString   .= ', %d';
		}
		// Pro's extra-data tracking already carries bot_name in its column block;
		// on free, write it on its own so the human-vs-bot split has data there too.
		$should_include_bot_name = ! $is_extra_data_tracking_compatible && \BetterLinks\Helper::has_bot_name_column();

		if ( $is_extra_data_tracking_compatible ) {
			$addedPlaceholderString .= ', brand_name, model, bot_name, browser_type, os_version, browser_version, language, query_params';
			$addedDbColumnsString   .= ', %s, %s, %s, %s, %s, %s, %s, %s';
		} elseif ( $should_include_bot_name ) {
			$addedPlaceholderString .= ', bot_name';
			$addedDbColumnsString   .= ', %s';
		}

		
		if( empty($betterlinks) || empty( current( $betterlinks )['ID'] ) ) return;
		$query         = "INSERT INTO {$wpdb->prefix}betterlinks_clicks ( link_id, browser, os,device, referer, uri, click_count, visitor_id, click_order, created_at,  $addedPlaceholderString ) VALUES ( %d, %s, %s, %s, %s, %s, %d, %s, %d, %s,  $addedDbColumnsString )";
		$db_data_array = array(
			current( $betterlinks )['ID'],
			isset( $item['browser'] ) ? $item['browser'] : '',
			isset( $item['os'] ) ? $item['os'] : '',
			isset( $item['device'] ) ? $item['device'] : '',
			isset( $item['referer'] ) ? $item['referer'] : '',
			isset( $item['uri'] ) ? $item['uri'] : '',
			isset( $item['click_count'] ) ? $item['click_count'] : 0,
			isset( $item['visitor_id'] ) ? $item['visitor_id'] : '',
			isset( $item['click_order'] ) ? $item['click_order'] : '',
			isset( $item['created_at']) ? $item['created_at'] : '',
			isset( $item['created_at_gmt']) ? $item['created_at_gmt'] : '',
			isset( $item['rotation_target_url']) ? $item['rotation_target_url'] : '',
		);
		if ( $is_analytics_ip_enabled ) {
			$db_data_array[] = isset( $item['ip'] ) ? $item['ip'] : '';
			$db_data_array[] = isset( $item['host'] ) ? $item['host'] : '';
		}

		if ( $country_id > 0 ) {
			$db_data_array[] = $country_id;
		}
		// $db_data_array[] = isset($item['device']) ? $item['device'] : '';
		if ( $is_extra_data_tracking_compatible ) {
			$db_data_array[] = isset( $item['brand_name'] ) ? $item['brand_name'] : '';
			$db_data_array[] = isset( $item['model'] ) ? $item['model'] : '';
			$db_data_array[] = isset( $item['bot_name'] ) ? $item['bot_name'] : '';
			$db_data_array[] = isset( $item['browser_type'] ) ? $item['browser_type'] : '';
			$db_data_array[] = isset( $item['os_version'] ) ? $item['os_version'] : '';
			$db_data_array[] = isset( $item['browser_version'] ) ? $item['browser_version'] : '';
			$db_data_array[] = isset( $item['language'] ) ? $item['language'] : '';
			$db_data_array[] = isset( $item['query_params'] ) ? $item['query_params'] : '';
		} elseif ( $should_include_bot_name ) {
			$db_data_array[] = isset( $item['bot_name'] ) ? $item['bot_name'] : '';
		}

		
		if ( isset( current( $betterlinks )['ID'] ) ) {
			$wpdb->query(
				$wpdb->prepare( $query, $db_data_array )
			);
			$click_id = (int) $wpdb->insert_id;
			if ( $click_id ) {
				/**
				 * Fires after a click row is stored (redirects, the front-end tracker
				 * and clicks replayed from clicks.json). BetterLinks Pro stores the
				 * user agent here.
				 *
				 * @param int   $click_id Click row ID.
				 * @param array $item     Click data that was stored.
				 * @param int   $link_id  Link ID.
				 */
				do_action( 'betterlinks/click/inserted', $click_id, $item, (int) current( $betterlinks )['ID'] );
			}
			return $click_id;
		}
		return;
	}

	public static function get_linksNips_count() {
		global $wpdb;

		$query = "select link_id, ip, ipc, t2.lidc from ( select ip, link_id, count(ip) as ipc from {$wpdb->prefix}betterlinks_clicks group by ip, link_id ) as t1
        left join ( select link_id as lid, sum(ipc) as lidc from ( select ip, link_id, count(uri) as ipc from {$wpdb->prefix}betterlinks_clicks group by ip,uri, link_id ) as t3 group by link_id ) as t2
        on t1.link_id = t2.lid";

		$results = $wpdb->get_results( $query, ARRAY_A );
		return $results;
	}

	public static function get_clicks_count($from = '', $to = '') {
		global $wpdb;
		
		$where_conditions = array();
		$query_params = array();
		
		// Add date range condition
		if ( '' !== $from && '' !== $to ) {
			$where_conditions[] = 'created_at BETWEEN %s AND %s';
			$query_params[] = $from . ' 00:00:00';
			$query_params[] = $to . ' 23:59:59';
		}
		
		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'ip', array( 'report' => 'get_clicks_count', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$query_params = array_merge( $query_params, $extra_where['params'] );
		}
		
		$where_clause = ! empty( $where_conditions ) ? 'WHERE ' . implode( ' AND ', $where_conditions ) : '';

		// Total clicks query. ORDER BY keeps this aligned with the unique query below;
		// callers must still merge the two sets on link_id, never on row position.
		$total_query = "SELECT link_id, count(id) as total_clicks from {$wpdb->prefix}betterlinks_clicks {$where_clause} group by link_id ORDER BY link_id";
		$total_clicks = ! empty( $query_params ) ? $wpdb->get_results( $wpdb->prepare( $total_query, $query_params ), ARRAY_A ) : $wpdb->get_results( $total_query, ARRAY_A );

		// Unique clicks query  
		$unique_query = "SELECT T1.link_id, count(ip) as unique_clicks from ( SELECT ip, link_id FROM {$wpdb->prefix}betterlinks_clicks {$where_clause} GROUP BY `ip`, `link_id` ) as T1 GROUP BY T1.link_id ORDER BY T1.link_id";
		$unique_clicks = ! empty( $query_params ) ? $wpdb->get_results( $wpdb->prepare( $unique_query, $query_params ), ARRAY_A ) : $wpdb->get_results( $unique_query, ARRAY_A );

		return array(
			'total_clicks'  => $total_clicks,
			'unique_clicks' => $unique_clicks,
		);
	}

	public static function get_links_analytics() {
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$results = $wpdb->get_results(
			"SELECT DISTINCT link_id, ip,
			(select count(ip) from {$prefix}betterlinks_clicks WHERE CLICKS.ip = {$prefix}betterlinks_clicks.ip  group by ip) as IPCOUNT,
			(select count(link_id) from {$prefix}betterlinks_clicks WHERE CLICKS.link_id = {$prefix}betterlinks_clicks.link_id group by link_id) as LINKCOUNT
			from {$prefix}betterlinks_clicks as CLICKS group by CLICKS.id",
			ARRAY_A
		);
		return $results;
	}

	public static function clear_analytics_cache() {
		global $wpdb;
		$prefix                          = $wpdb->prefix;
		$individual_analytics_cache_keys = 'btl_individual_analytics_clicks_|btl_individual_graph_data_';
		// Every btl_analytics_* transient belongs here — one left out keeps serving
		// figures from before the clicks changed until its own 30-minute TTL runs
		// out, which reads as "the report is broken".
		$all_analytics_cache_keys        = 'betterlinks_analytics_data|btl_analytics_unique_list_|btl_analytics_unique_list_by_tag_|btl_analytics_graph_|btl_analytics_graph_by_tag_|btl_analytics_audience_|btl_analytics_timing_|btl_top_referer_|btl_click_stats_|btl_top_os_|btl_top_browser_|btl_all_referer_|btl_tags_analytics|btl_categories_analytics|betterlinks_tags_analytics|betterlinks_categories_analytics|btl_analytics_data_|btl_unique_clicks_count_';
		$query                           = "DELETE FROM {$prefix}options WHERE option_name regexp '{$individual_analytics_cache_keys}|{$all_analytics_cache_keys}'";

		$result = $wpdb->query( $query );
		return $result;
	}

	public static function search_clicks_data( $keyword ) {
		global $wpdb;
		$prefix                            = $wpdb->prefix;
		$is_extra_data_tracking_compatible = apply_filters( 'betterlinks/is_extra_data_tracking_compatible', false );
		$extra_data_tracking_columns       = $is_extra_data_tracking_compatible ? 'os, device, brand_name, ' : '';
		$results                           = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CLICKS.ID as
            click_ID, link_id, browser, {$extra_data_tracking_columns} created_at, referer, SUBSTRING_INDEX(SUBSTRING_INDEX(referer, '/', 3), '/', -1) AS domain, short_url, target_url, ip, {$prefix}betterlinks.link_title,
            (select count(id) from {$prefix}betterlinks_clicks where CLICKS.ip = {$prefix}betterlinks_clicks.ip group by ip) as IPCOUNT
            from {$prefix}betterlinks_clicks as CLICKS left join {$prefix}betterlinks on {$prefix}betterlinks.id = CLICKS.link_id WHERE {$prefix}betterlinks.link_title LIKE %s
            or {$prefix}betterlinks.short_url like %s
            or {$prefix}betterlinks.target_url like %s
            or CLICKS.browser like %s
            or CLICKS.ip like %s
            or CLICKS.referer like %s
            group by CLICKS.id ORDER BY CLICKS.created_at DESC",
				'%' . $keyword . '%',
				'%' . $keyword . '%',
				'%' . $keyword . '%',
				'%' . $keyword . '%',
				'%' . $keyword . '%',
				'%' . $keyword . '%'
			),
			ARRAY_A
		);
		return $results;
	}

	public static function get_clicks_by_date( $from, $to ) {
		global $wpdb;
		$prefix                            = $wpdb->prefix;
		$is_extra_data_tracking_compatible = apply_filters( 'betterlinks/is_extra_data_tracking_compatible', false );
		$extra_data_tracking_columns       = $is_extra_data_tracking_compatible ? 'CLICKS.os, CLICKS.device, CLICKS.brand_name, ' : '';
		
		$query_params = array( $from . ' 00:00:00', $to . ' 23:59:00' );
		$where_conditions = array( 'CLICKS.created_at BETWEEN %s AND %s' );
		
		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'CLICKS.ip', array( 'report' => 'get_clicks_by_date', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$query_params = array_merge( $query_params, $extra_where['params'] );
		}
		
		$where_clause = implode( ' AND ', $where_conditions );
		
		$query = "SELECT 
                CLICKS.ID AS click_ID, 
                CLICKS.link_id, 
                CLICKS.browser, 
                {$extra_data_tracking_columns}
                CLICKS.created_at, 
                CLICKS.referer,
                SUBSTRING_INDEX(SUBSTRING_INDEX(CLICKS.referer, '/', 3), '/', -1) AS domain,
                {$prefix}betterlinks.short_url, 
                {$prefix}betterlinks.target_url, 
                CLICKS.ip, 
                {$prefix}betterlinks.link_title
            FROM 
                {$prefix}betterlinks_clicks AS CLICKS 
                LEFT JOIN {$prefix}betterlinks ON {$prefix}betterlinks.id = CLICKS.link_id 
            WHERE 
                {$where_clause}
            GROUP BY 
                CLICKS.id 
            ORDER BY 
                CLICKS.created_at DESC limit 100000";
		
		$results = $wpdb->get_results( $wpdb->prepare( $query, $query_params ), ARRAY_A );
		return $results;
	}


	public static function get_thirstyaffiliates_links() {
		$thirstylinks      = get_posts(
			array(
				'posts_per_page' => -1,
				'post_type'      => 'thirstylink',
				'post_status'    => 'publish',
			)
		);
		$response          = array();
		$betterlinks_links = json_decode( get_option( 'betterlinks_links', '{}' ), true );
		foreach ( $thirstylinks as $thirstylink ) {
			$term              = wp_get_post_terms( $thirstylink->ID, 'thirstylink-category', array( 'fields' => 'names' ) );
			$nofollow          = get_post_meta( $thirstylink->ID, '_ta_no_follow', true );
			$nofollow          = ( $nofollow == 'global' ? get_option( 'ta_no_follow', true ) : $nofollow );
			$redirect_type     = get_post_meta( $thirstylink->ID, '_ta_redirect_type', true );
			$redirect_type     = ( $redirect_type == 'global' ? get_option( 'ta_link_redirect_type', true ) : $redirect_type );
			$param_forwarding  = get_post_meta( $thirstylink->ID, '_ta_pass_query_str', true );
			$param_forwarding  = ( $param_forwarding == 'global' ? get_option( 'ta_pass_query_str', true ) : $param_forwarding );
			$dynamic_redirect  = array();
			$geolocation_links = get_post_meta( $thirstylink->ID, '_ta_geolocation_links', true );
			if ( $geolocation_links && is_array( $geolocation_links ) ) {
				$dynamic_redirect_value = array();
				foreach ( $geolocation_links as $key => $geolocation_link ) {
					$dynamic_redirect_value[] = array(
						'link'    => $geolocation_link,
						'country' => explode( ',', $key ),
					);
				}
				$dynamic_redirect = array(
					'type'  => 'geographic',
					'value' => $dynamic_redirect_value,
					'extra' => array(),
				);
			}
			$link_date = get_post_meta( $thirstylink->ID, '_ta_link_start_date', true );
			// expire
			$expire              = array();
			$expire_date         = get_post_meta( $thirstylink->ID, '_ta_link_expire_date', true );
			$expire_redirect_url = get_post_meta( $thirstylink->ID, '_ta_after_expire_redirect', true );
			if ( ! empty( $expire_date ) ) {
				$expire = array(
					'status' => 1,
					'type'   => 'date',
					'date'   => $expire_date,
				);
			}
			if ( ! empty( $expire_redirect_url ) ) {
				$expire['redirect_status'] = 1;
				$expire['redirect_url']    = $expire_redirect_url;
			}
			// link status
			$link_status = 'publish';
			$now         = time();
			if ( ! empty( $link_date ) && $now < strtotime( $link_date ) ) {
				$link_status = 'scheduled';
			}
			if ( ! empty( $expire_date ) && $now > strtotime( $expire_date ) ) {
				$link_status = 'draft';
			}
			// keywords
			$keywords   = get_post_meta( $thirstylink->ID, '_ta_autolink_keyword_list', true );
			$limit      = get_post_meta( $thirstylink->ID, '_ta_autolink_keyword_limit', true );
			$response[] = array(
				'link_title'        => $thirstylink->post_title,
				'link_slug'         => $thirstylink->post_name,
				'link_date'         => $link_date ? $link_date : '',
				'link_date_gmt'     => $link_date ? $link_date : '',
				'link_status'       => $link_status,
				'short_url'         => trim( \BetterLinks\Helper::force_relative_url( get_the_permalink( $thirstylink->ID ) ), '/' ),
				'link_author'       => $thirstylink->post_author,
				'link_date'         => $thirstylink->post_date,
				'link_date_gmt'     => $thirstylink->post_date_gmt,
				'nofollow'          => ( $nofollow == 'yes' ? 1 : 0 ),
				'sponsored'         => $betterlinks_links['sponsored'],
				'track_me'          => $betterlinks_links['track_me'],
				'redirect_type'     => $redirect_type,
				'param_forwarding'  => ( $param_forwarding == 'yes' ? 1 : 0 ),
				'target_url'        => get_post_meta( $thirstylink->ID, '_ta_destination_url', true ),
				'link_modified'     => $thirstylink->post_modified,
				'link_modified_gmt' => $thirstylink->post_modified_gmt,
				'terms'             => $term,
				'expire'            => json_encode( $expire ),
				'dynamic_redirect'  => json_encode( $dynamic_redirect ),
				'keywords'          => $keywords,
				'limit'             => $limit,
			);
		}
		return $response;
	}

	public static function get_prettylinks_links_count() {
		global $wpdb;
		$links = $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}prli_links" );
		return $links;
	}
	public static function get_prettylinks_clicks_count() {
		global $wpdb;
		$clicks = $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}prli_clicks" );
		return $clicks;
	}

	public static function get_link_meta( $link_id, $meta_key ) {
		global $wpdb;
		$table = $wpdb->prefix . 'betterlinkmeta';
		if ( empty( $link_id ) || empty( $meta_key ) ) {
			return false;
		}
		$query   = $wpdb->prepare( "SELECT meta_value FROM $table WHERE meta_key=%s AND link_id=%d", $meta_key, $link_id );
		$results = $wpdb->get_results( $query );
		if ( ! empty( $results ) ) {
			if ( is_serialized( current( $results )->meta_value, true ) ) {
				return current( $results )->meta_value;
			}
			if ( is_string( current( $results )->meta_value ) ) {
				return json_decode( current( $results )->meta_value );
			}

			return json_decode( current( $results )->meta_value );
		}
		return false;
	}

	public static function add_link_meta( $link_id, $meta_key, $meta_value ) {
		global $wpdb;
		$meta_key   = wp_unslash( $meta_key );
		$meta_value = wp_unslash( $meta_value );

		if ( isset( $meta_value['keywords'] ) ) {
			$meta_value['keywords'] = preg_replace( '/\’|\'|\‘/', "'", $meta_value['keywords'] );
		}
		$meta_value = \BetterLinks\Helper::maybe_json( $meta_value, false );
		if ( empty( $link_id ) || empty( $meta_key ) ) {
			return false;
		}
		$result = $wpdb->insert(
			$wpdb->prefix . 'betterlinkmeta',
			array(
				'link_id'    => $link_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		if ( ! $result ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}
	public static function update_link_meta( $link_id, $meta_key, $meta_value, $old_keywords = false, $old_link_id = false ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'betterlinkmeta';
		$link_id    = absint( $link_id );
		$meta_key   = wp_unslash( $meta_key );
		$meta_value = wp_unslash( $meta_value );
		if ( isset( $meta_value['keywords'] ) ) {
			$meta_value['keywords'] = preg_replace( '/\’|\'|\‘/', "'", $meta_value['keywords'] );
		}
		$meta_value = \BetterLinks\Helper::maybe_json( $meta_value, false );
		if ( empty( $link_id ) || empty( $meta_key ) ) {
			return false;
		}
		$result = false;
		if ( $old_keywords && $old_link_id ) {
			$keywordPattern = wp_slash( '%"keywords":' . wp_json_encode( wp_unslash( $old_keywords ) ) . ',"link_id":%' );
			$result         = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table
                SET meta_value = %s, link_id = %d
                WHERE link_id = %d AND meta_key=%s AND meta_value LIKE %s LIMIT 1",
					$meta_value,
					$link_id,
					$old_link_id,
					$meta_key,
					$keywordPattern
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table
                SET meta_value = %s
                WHERE link_id = %d AND meta_key=%s",
					$meta_value,
					$link_id,
					$meta_key
				)
			);
		}
		return ! ! $result;
	}

	public static function delete_link_meta( $link_id, $meta_key, $meta_value = '', $keywords = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'betterlinkmeta';
		if ( empty( $link_id ) || empty( $meta_key ) ) {
			return false;
		}
		$query = $wpdb->prepare( "SELECT link_id FROM $table WHERE meta_key = %s AND link_id = %d", $meta_key, $link_id );
		if ( ! empty( $keywords ) ) {
			$keywordPattern = wp_slash( '%"keywords":' . wp_json_encode( wp_unslash( $keywords ) ) . ',"link_id":%' );
			$query          = $wpdb->prepare(
				"SELECT meta_id FROM $table WHERE meta_key = %s AND link_id = %d AND meta_value LIKE %s LIMIT 1",
				$meta_key,
				$link_id,
				$keywordPattern
			);
		}
		if ( ! empty( $meta_value ) ) {
			$query .= $wpdb->prepare( ' AND meta_value = %s', $meta_value );
		}
		$meta_ids = $wpdb->get_col( $query );
		if ( ! count( $meta_ids ) ) {
			return false;
		}
		$query = "DELETE FROM $table WHERE meta_id IN( " . implode( ',', $meta_ids ) . ' )';
		$count = $wpdb->query( $query );
		return ! ! $count;
	}

	public static function get_keywords() {
		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}betterlinkmeta WHERE meta_key=%s ORDER BY meta_id DESC", 'keywords' ),
			ARRAY_A
		);
		$results = array_column( $results, 'meta_value' );
		return $results;
	}

	public static function get_keywords_for_export() {
		global $wpdb;
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->prefix}betterlinkmeta WHERE meta_key=%s ORDER BY meta_id DESC", 'keywords' ),
			ARRAY_A
		);
		return $results;
	}

	public static function update_link_meta_by_meta_id( $meta_id, $link_id, $meta_key, $meta_value ) {
		global $wpdb;
		$table      = $wpdb->prefix . 'betterlinkmeta';
		$meta_id    = absint( $meta_id );
		$link_id    = absint( $link_id );
		$meta_key   = wp_unslash( $meta_key );
		$meta_value = wp_unslash( $meta_value );
		if ( isset( $meta_value['keywords'] ) ) {
			$meta_value['keywords'] = preg_replace( '/\'|\'|\'/', "'", $meta_value['keywords'] );
		}
		$meta_value = \BetterLinks\Helper::maybe_json( $meta_value, false );
		if ( empty( $meta_id ) || empty( $link_id ) || empty( $meta_key ) ) {
			return false;
		}
		$result = $wpdb->update(
			$table,
			array(
				'meta_value' => $meta_value,
				'link_id'    => $link_id,
			),  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			array(
				'meta_id'  => $meta_id,
				'meta_key' => $meta_key,
			)
		);  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		return $result !== false;
	}

	public static function get_link_data_by_id( $id, $fields ) {
		global $wpdb;
		$query  = $wpdb->prepare( "SELECT `{$fields}` from {$wpdb->prefix}betterlinks WHERE id=%d", array( $id ) );
		$result = $wpdb->get_var( $query );
		return $result;
	}

	public static function get_link_count(){
		global $wpdb;
		$query = "SELECT COUNT(*) AS total_link, 
				SUM(wildcards) AS wildcards,
				SUM(expire != '' and expire != '{}') AS link_expire,
				SUM(dynamic_redirect != '' and dynamic_redirect != '{}') AS dynamic_redirect,
				SUM(id=link_id and meta_key='keywords') as auto_link_keyword
			FROM {$wpdb->prefix}betterlinks as links left join {$wpdb->prefix}betterlinkmeta as meta on links.id=meta.link_id;";

		$count = $wpdb->get_row( $query, ARRAY_A );
		return is_array( $count ) ? $count : [];
	}

	public static function get_prettylinks_data() {
		$links_count  = self::get_prettylinks_links_count();
		$clicks_count = self::get_prettylinks_clicks_count();
		set_transient(
			'betterlinks_migration_data_prettylinks',
			array(
				'links_count'  => $links_count,
				'clicks_count' => $clicks_count,
			),
			60 * 5
		);
		return array(
			'links_count'  => $links_count,
			'clicks_count' => $clicks_count,
		);
	}

	public static function used_features_by_client() {
		// Pull free settings (betterlinks_links holds force_https, affiliate_link_disclosure,
		// excluded_ips, custom_domain.*) and Pro option blobs in one batch — all reads are
		// cheap option lookups. Pro options return defaults on free-only installs.
		$links_options       = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME, '{}' ), true );
		$links_options       = is_array( $links_options ) ? $links_options : array();
		$pro_external_anal   = get_option( 'betterlinkspro_ga', array() );
		$pro_external_anal   = is_array( $pro_external_anal ) ? $pro_external_anal : array();
		$pro_auto_link_raw   = get_option( 'betterlinkspro_auto_link_create', '' );
		$pro_auto_link       = is_string( $pro_auto_link_raw ) && '' !== $pro_auto_link_raw ? json_decode( $pro_auto_link_raw, true ) : array();
		$pro_auto_link       = is_array( $pro_auto_link ) ? $pro_auto_link : array();
		$pro_reporting_raw   = get_option( 'betterlinkspro_reporting', '' );
		$pro_reporting       = is_string( $pro_reporting_raw ) && '' !== $pro_reporting_raw ? json_decode( $pro_reporting_raw, true ) : array();
		$pro_reporting       = is_array( $pro_reporting ) ? $pro_reporting : array();
		$pro_broken_cfg_raw  = get_option( 'betterlinkspro_broken_link', '' );
		$pro_broken_cfg      = is_string( $pro_broken_cfg_raw ) && '' !== $pro_broken_cfg_raw ? json_decode( $pro_broken_cfg_raw, true ) : array();
		$pro_broken_cfg      = is_array( $pro_broken_cfg ) ? $pro_broken_cfg : array();

		// AI Link Assistant ships in Pro 2.8.0+ and its toggles (in betterlinks_links)
		// default ON, so a missing key looks "enabled" on every install. Gate on the
		// stored Pro version to avoid over-reporting on free / older-Pro sites.
		$pro_version            = get_option( 'betterlinks_pro_version', '' );
		$is_link_assistant_live = ! empty( $pro_version ) && version_compare( $pro_version, '2.8.0', '>=' );

		return array(
			// existing — kept for backward compatibility with WPInsights dashboards
			'betterlinks_broken_link_scanner' => !empty( get_option( 'betterlinkspro_broken_links_logs', [] ) ),
			'fullsite_link_scanner'           => !empty( get_option( 'betterlinkspro_fullsite_broken_links_logs_cleared', 0 ) ) || !empty( get_option( 'betterlinkspro_fullsite_broken_links_logs', [] ) ),
			'ai_link_generator'               => !empty( get_option( 'betterlinks_ai_generator_used', false ) ),
			'utm_builder'                     => !empty( get_option( 'betterlinks_utm_builder_used', false ) ),
			// new — settings toggles (Pro feature adoption)
			'is_ga_enabled'                   => ! empty( $pro_external_anal['is_enable_ga'] ),
			'is_pixel_enabled'                => ! empty( $pro_external_anal['is_enable_pixel'] ),
			'is_custom_scripts_enabled'       => ! empty( $pro_external_anal['is_enable_custom_scripts'] ),
			'is_custom_domain_configured'     => ! empty( $links_options['custom_domain']['enable_shortlink_custom_domain'] ) && ! empty( $links_options['custom_domain']['shortlink_custom_domain'] ),
			'is_force_https_enabled'          => ! empty( $links_options['force_https'] ),
			'is_affiliate_disclosure_enabled' => ! empty( $links_options['affiliate_link_disclosure'] ),
			'is_exclude_ips_configured'       => isset( $links_options['excluded_ips'] ) && is_array( $links_options['excluded_ips'] ) && ! empty( $links_options['excluded_ips'] ),
			'is_auto_create_links_enabled'    => ! empty( $pro_auto_link['post_shortlinks'] ) || ! empty( $pro_auto_link['page_shortlinks'] ),
			'is_email_reports_enabled'        => ! empty( $pro_reporting['enable_reporting'] ),
			'is_broken_link_scan_enabled'     => ! empty( $pro_broken_cfg['enable_scan'] ),
			// AI Link Assistant — actual usage markers (set by Pro when the feature runs),
			// the reliable signal for adoption since the settings toggles default ON.
			'ai_link_assistant_used'                => !empty( get_option( 'betterlinks_link_genius_used', false ) ),
			'link_suggestion_used'            => !empty( get_option( 'betterlinks_raw_link_rescue_used', false ) ),
		);
	}

	/**
	 * Per-redirect-type adoption rollup, sent to WPInsights so product can see
	 * which redirect modes users actually create.
	 *
	 * Counts:
	 *   - `cloak_redirect_count`               rows where redirect_type='cloak' (Pro)
	 *   - `dynamic_redirect_rotation_count`    dynamic_redirect.type='rotation' (split test)
	 *   - `dynamic_redirect_geographic_count`  dynamic_redirect.type='geographic'
	 *   - `dynamic_redirect_device_count`      dynamic_redirect.type='device'
	 *
	 * LIKE patterns are used instead of MySQL JSON functions for compatibility
	 * with MySQL 5.6 / MariaDB 10.1.
	 */
	public static function get_redirect_type_breakdown() {
		global $wpdb;
		// COALESCE wraps each SUM so a links table with zero matching rows
		// reports 0 instead of NULL in the WPInsights payload.
		$query = "SELECT
				COALESCE(SUM(redirect_type='cloak'), 0) AS cloak_redirect_count,
				COALESCE(SUM(dynamic_redirect LIKE '%\"type\":\"rotation\"%'), 0) AS dynamic_redirect_rotation_count,
				COALESCE(SUM(dynamic_redirect LIKE '%\"type\":\"geographic\"%'), 0) AS dynamic_redirect_geographic_count,
				COALESCE(SUM(dynamic_redirect LIKE '%\"type\":\"device\"%'), 0) AS dynamic_redirect_device_count
			FROM {$wpdb->prefix}betterlinks;";

		$count = $wpdb->get_row( $query, ARRAY_A );
		return is_array( $count ) ? $count : array();
	}

}
