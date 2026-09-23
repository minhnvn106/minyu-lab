<?php
namespace BetterLinks\Traits;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Helper;

// phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

trait Clicks {
	private static $transient_timeout = MINUTE_IN_SECONDS * 30; // 30 MINUTES

	/**
	 * Get Clicks data within a certain time limit
	 *
	 * @param string $from The start time.
	 * @param string $to The end time.
	 *
	 * @return array
	 */
	public function get_clicks_data( $from, $to ) {
		$results = \BetterLinks\Helper::get_clicks_by_date( $from, $to );
		return $results;
	}

	/**
	 * Get transient key for cached analytics data
	 *
	 * @param string     $key The unique identifier for the transient key
	 * @param string     $from The start time.
	 * @param string     $to The end time.
	 * @param string|int $id Clicks id.
	 *
	 * @return string
	 */
	private static function get_transient_key( $key, $from, $to, $id = null ) {
		$transient_key = str_replace( '-', '_', $from ) . '_' . str_replace( '-', '_', $to );
		if ( $id ) {
			$transient_key .= '_' . $id;
		}
		/**
		 * Filters an analytics cache key. Extensions that change query results
		 * (BetterLinks Pro IP exclusion) vary the key with their settings.
		 *
		 * @param string $cache_key Transient key.
		 * @param array  $context   { prefix, from, to, id }.
		 */
		return (string) apply_filters( 'betterlinks/analytics/transient_key', $key . $transient_key, array( 'prefix' => $key, 'from' => $from, 'to' => $to, 'id' => $id ) );
	}

	/**
	 * Get Analytics Graph Data
	 *
	 * @param $from The start time.
	 * @param $to The end time.
	 *
	 * @return array Array of total unique clicks and total clicks.
	 */
	public function get_analytics_graph_data( $from, $to ) {
		$transient_key = self::get_transient_key( 'btl_analytics_graph_', $from, $to );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}

		global $wpdb;
		
		// Build a safe, prepared query
		
		$query_params = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		$where_conditions = array( 'created_at BETWEEN %s AND %s' );
		
		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'ip', array( 'report' => 'get_analytics_graph_data', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$query_params = array_merge( $query_params, $extra_where['params'] );
		}
		
		$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );
		
		// Total counts query
		$total_query = "SELECT count(id) as click_count, DATE(created_at) as c_date FROM {$wpdb->prefix}betterlinks_clicks 
            {$where_clause} GROUP BY c_date ORDER BY c_date DESC";
		$total_counts = $wpdb->get_results( $wpdb->prepare( $total_query, $query_params ), ARRAY_A );

		// Unique counts query - use same params twice for subquery
		$unique_query_params = array_merge( $query_params, $query_params );
		$unique_query = "SELECT count(ip) as uniq_count, T1.c_date from ( SELECT ip, DATE( created_at ) as c_date FROM {$wpdb->prefix}betterlinks_clicks 
            {$where_clause} GROUP BY `ip`, `c_date` ) as T1 GROUP BY T1.c_date ORDER BY T1.c_date DESC";
		$unique_counts = $wpdb->get_results( $wpdb->prepare( $unique_query, $unique_query_params ), ARRAY_A );

		$results = array(
			'total_count'  => $total_counts,
			'unique_count' => $unique_counts,
		);
		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Audience composition for the range: human vs bot, and new vs returning.
	 *
	 * Both splits read columns that were only filled in from the release that
	 * added this report, so each half reports how many rows it could actually
	 * classify (`tracked`) alongside the counts. Clicks older than that have no
	 * bot flag and no visitor id; they are counted in `untracked` and the client
	 * shows the split as unavailable when nothing is classifiable.
	 *
	 * - bot: `bot_name` is non-empty only for detected bots.
	 * - visitors: `click_order` is 1 on a visitor's first tracked click.
	 *   Distinct visitors are counted, not clicks, so one person browsing ten
	 *   links is one returning visitor rather than ten.
	 *
	 * @param string $from The start date (Y-m-d).
	 * @param string $to   The end date (Y-m-d).
	 *
	 * @return array {
	 *     @type array $bot      { @type bool $tracked, @type int $human, @type int $bot, @type int $untracked }
	 *     @type array $visitors { @type bool $tracked, @type int $new, @type int $returning, @type int $untracked }
	 * }
	 */
	public function get_analytics_audience_data( $from, $to ) {
		$transient_key = self::get_transient_key( 'btl_analytics_audience_', $from, $to );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}

		global $wpdb;


		$query_params     = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		$where_conditions = array( 'created_at BETWEEN %s AND %s' );

		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'ip', array( 'report' => 'get_analytics_audience_data', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$query_params = array_merge( $query_params, $extra_where['params'] );
		}

		$where_clause  = 'WHERE ' . implode( ' AND ', $where_conditions );
		$clicks_table  = $wpdb->prefix . 'betterlinks_clicks';
		$bot_supported = \BetterLinks\Helper::has_bot_name_column();

		// Human vs bot, counted in clicks. Rows predating bot tracking have a
		// NULL bot_name and cannot be attributed either way.
		$bot = array(
			'tracked'   => false,
			'human'     => 0,
			'bot'       => 0,
			'untracked' => 0,
		);

		if ( $bot_supported ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$bot_query = "SELECT
					SUM( CASE WHEN bot_name IS NOT NULL AND bot_name <> '' THEN 1 ELSE 0 END ) AS bots,
					SUM( CASE WHEN bot_name = '' THEN 1 ELSE 0 END ) AS humans,
					SUM( CASE WHEN bot_name IS NULL THEN 1 ELSE 0 END ) AS untracked
				FROM {$clicks_table} {$where_clause}";
			$bot_row   = $wpdb->get_row( $wpdb->prepare( $bot_query, $query_params ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( $bot_row ) {
				$bot['bot']       = (int) $bot_row['bots'];
				$bot['human']     = (int) $bot_row['humans'];
				$bot['untracked'] = (int) $bot_row['untracked'];
				$bot['tracked']   = ( $bot['bot'] + $bot['human'] ) > 0;
			}
		}

		// New vs returning, counted in distinct visitors. click_order is 1 on a
		// visitor's first click and 2 on later ones; 0 means the click predates
		// visitor tracking and cannot be classified either way.
		//
		// Each visitor is bucketed by their EARLIEST click in the range, so
		// someone who arrives and comes back inside the same range counts once,
		// as new — taking the rows at face value would count them in both halves.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visitor_query = "SELECT
				SUM( CASE WHEN first_order = 1 THEN 1 ELSE 0 END ) AS new_visitors,
				SUM( CASE WHEN first_order = 2 THEN 1 ELSE 0 END ) AS returning_visitors
			FROM (
				SELECT visitor_id, MIN( click_order ) AS first_order
				FROM {$clicks_table} {$where_clause} AND visitor_id <> '' AND click_order > 0
				GROUP BY visitor_id
			) AS v";
		$visitor_row   = $wpdb->get_row( $wpdb->prepare( $visitor_query, $query_params ), ARRAY_A );

		$untracked_query = "SELECT COUNT(*) FROM {$clicks_table} {$where_clause}
			AND ( visitor_id IS NULL OR visitor_id = '' OR click_order = 0 )";
		$untracked_count = (int) $wpdb->get_var( $wpdb->prepare( $untracked_query, $query_params ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$visitors = array(
			'tracked'   => false,
			'new'       => 0,
			'returning' => 0,
			'untracked' => 0,
		);

		$visitors['untracked'] = $untracked_count;

		if ( $visitor_row ) {
			$visitors['new']       = (int) $visitor_row['new_visitors'];
			$visitors['returning'] = (int) $visitor_row['returning_visitors'];
			$visitors['tracked']   = ( $visitors['new'] + $visitors['returning'] ) > 0;
		}

		$results = array(
			'bot'      => $bot,
			'visitors' => $visitors,
		);

		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Get Analytics Graph Data by Tag ID
	 *
	 * @param $from The start time.
	 * @param $to The end time.
	 * @param $tag_id The Tag ID.
	 *
	 * @return array Array of total unique clicks and total clicks.
	 */
	public function get_analytics_graph_data_by_tag( $from, $to, $tag_id ) {
		$transient_key = self::get_transient_key( 'btl_analytics_graph_by_tag_', $from, $to, $tag_id );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}

		global $wpdb;
		
		// Build a safe, prepared query parameters
		
		$base_params = array( $tag_id, "{$from} 00:00:00", "{$to} 23:59:59" );
		$where_conditions = array( "t.term_type='tags'", "t.id=%d", "c.created_at BETWEEN %s AND %s" );
		
		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'c.ip', array( 'report' => 'get_analytics_graph_data_by_tag', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$base_params = array_merge( $base_params, $extra_where['params'] );
		}
		
		$where_clause = implode( ' AND ', $where_conditions );
		
		// Total counts query
		$total_query = "SELECT COUNT(c.id) as click_count, DATE(c.created_at) AS c_date FROM {$wpdb->prefix}betterlinks_clicks c 
							LEFT JOIN {$wpdb->prefix}betterlinks_terms_relationships tr ON tr.link_id=c.link_id 
							LEFT JOIN {$wpdb->prefix}betterlinks_terms t ON tr.term_id=t.id 
						WHERE {$where_clause}
						GROUP BY c_date ORDER BY c_date DESC";
		$total_counts = $wpdb->get_results( $wpdb->prepare( $total_query, $base_params ), ARRAY_A );

		// Unique counts query - duplicate params for subquery  
		$unique_params = array_merge( $base_params, $base_params );
		$unique_query = "SELECT COUNT(ip) as uniq_count, T1.c_date FROM 
							( SELECT ip, DATE( created_at ) AS c_date FROM {$wpdb->prefix}betterlinks_clicks c
								LEFT JOIN {$wpdb->prefix}betterlinks_terms_relationships tr ON c.link_id=tr.link_id 
								LEFT JOIN {$wpdb->prefix}betterlinks_terms t ON tr.term_id=t.id 
									WHERE {$where_clause}
								GROUP BY `ip`, `c_date` ) AS T1  
							GROUP BY T1.c_date ORDER BY T1.c_date DESC";
		$unique_counts = $wpdb->get_results( $wpdb->prepare( $unique_query, $unique_params ), ARRAY_A );

		$results = array(
			'total_count'  => $total_counts,
			'unique_count' => $unique_counts,
		);
		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Returns the unique analytics data by tag
	 *
	 * @return array Array of unique analytics by tag
	 */
	public function get_analytics_unique_list_by_tag( $from, $to, $id ) {
		$transient_key = self::get_transient_key( 'btl_analytics_unique_list_by_tag_', $from, $to, $id );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}

		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT id as link_id, link_title, short_url, target_url from {$wpdb->prefix}betterlinks as links right join (select distinct link_id from {$wpdb->prefix}betterlinks_clicks where created_at between %s and %s) as clicks on clicks.link_id=links.id right join (select tr.link_id from {$wpdb->prefix}betterlinks_terms t left join {$wpdb->prefix}betterlinks_terms_relationships tr on t.ID=tr.term_id where t.term_type='tags' and t.ID=%s) tl on links.id=tl.link_id where id!=''",
			$from . ' 00:00:00',
			$to . ' 23:59:59',
			$id
		);
		$results = $wpdb->get_results( $query, ARRAY_A );

		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Returns the unique analytics clicks
	 *
	 * @return array Array of unique analytics
	 */
	public function get_analytics_unique_list( $from, $to ) {
		$transient_key = self::get_transient_key( 'btl_analytics_unique_list_', $from, $to );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT id as link_id, link_title, short_url, target_url from {$wpdb->prefix}betterlinks as links right join (select distinct link_id from {$wpdb->prefix}betterlinks_clicks where created_at between %s and %s) as clicks on clicks.link_id=links.id order by links.id desc",
			$from . ' 00:00:00',
			$to . ' 23:59:59',
		 );

		$results = $wpdb->get_results( $query, ARRAY_A );

		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Returns individual analytics clicks within a time limit
	 *
	 * @param int|string $id Clicks id.
	 * @param string     $from The start time.
	 * @param string     $to The end time.
	 *
	 * @return array Array of individual analytics clicks.
	 */
	public function get_individual_analytics_clicks( $id, $from, $to ) {
		$transient_key = self::get_transient_key( 'btl_individual_analytics_clicks_', $from, $to, $id );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}
		global $wpdb;

		$clicks_table = $wpdb->prefix . 'betterlinks_clicks';
		
		// Build a safe, prepared query parameters
		
		$base_params = array( $id, $from . ' 00:00:00', $to . ' 23:59:59' );
		$where_conditions = array( 'c.link_id=%d', 'c.created_at BETWEEN %s AND %s' );
		
		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'c.ip', array( 'report' => 'get_individual_analytics_clicks', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$base_params = array_merge( $base_params, $extra_where['params'] );
		}
		
		$where_clause = implode( ' AND ', $where_conditions );
		
		$is_extra_data_tracking_compatible = apply_filters( 'betterlinks/is_extra_data_tracking_compatible', false );
		$select = array(
			'columns' => $is_extra_data_tracking_compatible
				? array( 'c.ID', 'c.link_id', 'c.ip', 'c.browser', 'c.referer', 'c.os', 'c.device', 'c.query_params', 'c.created_at' )
				: array( 'c.ID', 'c.link_id', 'c.ip', 'c.browser', 'c.referer', 'c.created_at' ),
			'joins'   => array(),
		);
		/**
		 * Filters the columns and joins of the per-link click log query.
		 * BetterLinks Pro adds country and user-agent data. Only simple
		 * `alias.column` columns and `LEFT JOIN {prefix}betterlinks_* alias ON a.col = b.col`
		 * joins are accepted.
		 *
		 * @param array $select  { columns: string[], joins: string[] }.
		 * @param array $context { link_id, from, to }.
		 */
		$select  = apply_filters( 'betterlinks/analytics/individual_clicks_select', $select, array( 'link_id' => $id, 'from' => $from, 'to' => $to ) );
		$columns = array_values( array_filter( isset( $select['columns'] ) ? (array) $select['columns'] : array(), function ( $column ) {
			return is_string( $column ) && preg_match( '/^(?:[a-z]{1,3}\.[A-Za-z_]+|NULL)(?: AS [A-Za-z_]+)?$/', $column );
		} ) );
		$joins   = array_values( array_filter( isset( $select['joins'] ) ? (array) $select['joins'] : array(), function ( $join ) use ( $wpdb ) {
			return is_string( $join ) && preg_match( '/^LEFT JOIN ' . preg_quote( $wpdb->prefix, '/' ) . 'betterlinks_[a-z_]+ [a-z]{1,3} ON [a-z]{1,3}\.[a-z_]+ = [a-z]{1,3}\.[a-z_]+$/i', $join );
		} ) );
		if ( empty( $columns ) ) {
			$columns = array( 'c.ID', 'c.link_id', 'c.ip', 'c.browser', 'c.referer', 'c.created_at' );
		}

		$query_sql = 'SELECT ' . implode( ', ', $columns ) . " FROM {$clicks_table} c " . implode( ' ', $joins ) . " WHERE {$where_clause} ORDER BY c.created_at DESC";
		$query     = $wpdb->prepare( $query_sql, $base_params );
		$results = $wpdb->get_results( $query, ARRAY_A );

		// Ensure we always return an array, even if empty
		if ( ! is_array( $results ) ) {
			$results = array();
		}

		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Daily clicks series for ONE link — the same aggregate as
	 * `get_analytics_graph_data()`, scoped to a single `link_id`.
	 *
	 * This is a plain COUNT/GROUP BY over the clicks table, so it needs no
	 * extra-data tracking and belongs in free: the single-link overview reads its
	 * "Total clicks" tile and its clicks-over-time chart from this, and without it
	 * both read zero while the click log right below them lists the very rows the
	 * count is missing.
	 *
	 * @param int|string $id   Link id.
	 * @param string     $from The start time.
	 * @param string     $to   The end time.
	 *
	 * @return array { total_count: rows of { click_count, c_date }, unique_count: rows of { uniq_count, c_date } }
	 */
	public function get_individual_graph_data( $id, $from, $to ) {
		$transient_key = self::get_transient_key( 'btl_individual_graph_data_', $from, $to, $id );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}

		global $wpdb;

		// Build a safe, prepared query parameters

		$query_params     = array( $id, $from . ' 00:00:00', $to . ' 23:59:59' );
		$where_conditions = array( 'link_id=%d', 'created_at BETWEEN %s AND %s' );

		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'ip', array( 'report' => 'get_individual_graph_data', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$query_params = array_merge( $query_params, $extra_where['params'] );
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );

		$total_query  = "SELECT count(id) as click_count, DATE(created_at) as c_date FROM {$wpdb->prefix}betterlinks_clicks
            {$where_clause} GROUP BY c_date ORDER BY c_date DESC";
		$total_counts = $wpdb->get_results( $wpdb->prepare( $total_query, $query_params ), ARRAY_A );

		// Unique counts query - the where clause sits in the subselect, so the same
		// params are passed once, not twice.
		$unique_query  = "SELECT count(ip) as uniq_count, T1.c_date from ( SELECT ip, DATE( created_at ) as c_date FROM {$wpdb->prefix}betterlinks_clicks
            {$where_clause} GROUP BY `ip`, `c_date` ) as T1 GROUP BY T1.c_date ORDER BY T1.c_date DESC";
		$unique_counts = $wpdb->get_results( $wpdb->prepare( $unique_query, $query_params ), ARRAY_A );

		$results = array(
			'total_count'  => is_array( $total_counts ) ? $total_counts : array(),
			'unique_count' => is_array( $unique_counts ) ? $unique_counts : array(),
		);
		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	/**
	 * Returns individual link details
	 *
	 * @param int|string $id link id.
	 *
	 * @return Object Object of individual link details.
	 */
	public function get_individual_link_details( $id ) {
		global $wpdb;
		$query = $wpdb->prepare( 
			"SELECT link_title, short_url, target_url FROM {$wpdb->prefix}betterlinks where id=%s",
			$id
		 );
		return $wpdb->get_row( $query );
	}

	private function sanitize_date( $date ){
		if( empty( $date ) ){
			return false;
		}
		$date = sanitize_text_field( $date );
		return strtotime( $date );
	}

	/**
	 * Returns individual link details
	 *
	 * @param int|string $id link id.
	 *
	 * @return Object Object of individual link details.
	 */
	public function get_unique_clicks_count($from, $to) {
		$transient_key = self::get_transient_key( 'btl_unique_clicks_count_', $from, $to );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}
		global $wpdb;
		
		// Build a safe, prepared query
		
		$query_params = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		$where_conditions = array( 'created_at BETWEEN %s AND %s' );
		
		// Extra analytics conditions from extensions (BetterLinks Pro adds IP exclusion).
		$extra_where = \BetterLinks\Helper::analytics_extra_where( 'ip', array( 'report' => 'get_unique_clicks_count', 'from' => $from, 'to' => $to ) );
		if ( '' !== $extra_where['sql'] ) {
			$where_conditions[] = $extra_where['sql'];
			$query_params = array_merge( $query_params, $extra_where['params'] );
		}
		
		$where_clause = 'WHERE ' . implode( ' AND ', $where_conditions );
		
		$query_sql = "SELECT COUNT( DISTINCT ip ) AS count FROM {$wpdb->prefix}betterlinks_clicks {$where_clause}";
		$query = $wpdb->prepare( $query_sql, $query_params );
		$results = $wpdb->get_row( $query, ARRAY_A );
		// COUNT() normally always yields a row, but get_row() returns null on a
		// query error (and on an empty result set), and current( null ) is a
		// TypeError on PHP 8 — fatal behind the unique-clicks analytics card.
		$results = is_array( $results ) ? current( $results ) : 0;
		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}

	public function get_analytics_data($from, $to) {
		$transient_key = self::get_transient_key( 'btl_analytics_data_', $from, $to );
		if ( $results = get_transient( $transient_key ) ) {
			return $results;
		}
		
		$results = Helper::merge_clicks_count( Helper::get_clicks_count( $from, $to ) );
		$results = wp_json_encode( $results );
		set_transient( $transient_key, $results, self::$transient_timeout );
		return $results;
	}
}
