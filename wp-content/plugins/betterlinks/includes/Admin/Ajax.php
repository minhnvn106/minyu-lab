<?php

namespace BetterLinks\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\WPDev\PluginUsageTracker;
use BetterLinks\Cron;
use BetterLinks\Helper;
use BetterLinks\Link\Utils;
use BetterLinks\Admin\Cache;

class Ajax {

	use \BetterLinks\Traits\Links;
	use \BetterLinks\Traits\Terms;
	use \BetterLinks\Traits\Clicks;
	use \BetterLinks\Traits\ArgumentSchema;

// phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

	public function __construct() {
		// link & clicks.
		add_action( 'wp_ajax_betterlinks/admin/search_clicks_data', array( $this, 'search_clicks_data' ) );
		add_action( 'wp_ajax_betterlinks/admin/links_reorder', array( $this, 'links_reorder' ) );
		add_action( 'wp_ajax_betterlinks/admin/links_move_reorder', array( $this, 'links_move_reorder' ) );
		add_action( 'wp_ajax_betterlinks/admin/terms_reorder', array( $this, 'terms_reorder' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_links_by_short_url', array( $this, 'get_links_by_short_url' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_links_by_permalink', array( $this, 'get_links_by_permalink' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_cat_by_link_id', array( $this, 'get_category_by_link_id' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_betterlink_categories', array( $this, 'get_betterlink_categories' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_betterlink_tags', array( $this, 'get_betterlink_tags' ) );
		add_action( 'wp_ajax_betterlinks/admin/create_betterlink_category', array( $this, 'create_betterlink_category' ) );
		if ( \BetterLinks\Helper::pro_needs_update() ) { // Compatibility: BetterLinks Pro before 3.0.4 (newer Pro registers this).
			add_action( 'wp_ajax_betterlinks/admin/get_autolink_create_settings', array( $this, 'get_auto_link_create_settings' ) );
		}
		add_action( 'wp_ajax_betterlinks/admin/write_json_links', array( $this, 'write_json_links' ) );
		add_action( 'wp_ajax_betterlinks/admin/write_json_clicks', array( $this, 'write_json_clicks' ) );
		add_action( 'wp_ajax_betterlinks/admin/analytics', array( $this, 'analytics' ) );
		add_action( 'wp_ajax_betterlinks/admin/short_url_unique_checker', array( $this, 'short_url_unique_checker' ) );
		add_action( 'wp_ajax_betterlinks/admin/cat_slug_unique_checker', array( $this, 'cat_slug_unique_checker' ) );
		add_action( 'wp_ajax_betterlinks/admin/reset_analytics', array( $this, 'reset_analytics' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_clicks_count', array( $this, 'get_clicks_count' ) );
		// prettylinks.
		add_action( 'wp_ajax_betterlinks/admin/get_prettylinks_data', array( $this, 'get_prettylinks_data' ) );
		add_action( 'wp_ajax_betterlinks/admin/run_prettylinks_migration', array( $this, 'run_prettylinks_migration' ) );
		add_action( 'wp_ajax_betterlinks/admin/migration_prettylinks_notice_hide', array( $this, 'migration_prettylinks_notice_hide' ) );
		add_action( 'wp_ajax_betterlinks/admin/deactive_prettylinks', array( $this, 'deactive_prettylinks' ) );
		// simple 301.
		add_action( 'wp_ajax_betterlinks/admin/get_simple301redirects_data', array( $this, 'get_simple301redirects_data' ) );
		add_action( 'wp_ajax_betterlinks/admin/run_simple301redirects_migration', array( $this, 'run_simple301redirects_migration' ) );
		add_action( 'wp_ajax_betterlinks/admin/migration_simple301redirects_notice_hide', array( $this, 'migration_simple301redirects_notice_hide' ) );
		add_action( 'wp_ajax_betterlinks/admin/deactive_simple301redirects', array( $this, 'deactive_simple301redirects' ) );
		// Thirsty affiliates.
		add_action( 'wp_ajax_betterlinks/admin/get_thirstyaffiliates_data', array( $this, 'get_thirstyaffiliates_data' ) );
		add_action( 'wp_ajax_betterlinks/admin/run_thirstyaffiliates_migration', array( $this, 'run_thirstyaffiliates_migration' ) );
		add_action( 'wp_ajax_betterlinks/admin/deactive_thirstyaffiliates', array( $this, 'deactive_thirstyaffiliates' ) );
		// API Fallbck Ajax.
		add_action( 'wp_ajax_betterlinks/admin/get_all_links', array( $this, 'get_all_links' ) );
		add_action( 'wp_ajax_betterlinks/admin/create_link', array( $this, 'create_new_link' ) );
		add_action( 'wp_ajax_betterlinks/admin/update_link', array( $this, 'update_existing_link' ) );
		add_action( 'wp_ajax_betterlinks/admin/handle_favorite', array( $this, 'handle_links_favorite_option' ) );
		add_action( 'wp_ajax_betterlinks/admin/delete_link', array( $this, 'delete_existing_link' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_settings', array( $this, 'get_settings' ) );
		add_action( 'wp_ajax_betterlinks/admin/update_settings', array( $this, 'update_settings' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_terms', array( $this, 'get_terms' ) );
		add_action( 'wp_ajax_betterlinks/admin/create_new_term', array( $this, 'create_new_term' ) );
		add_action( 'wp_ajax_betterlinks/admin/update_term', array( $this, 'update_existing_term' ) );
		add_action( 'wp_ajax_betterlinks/admin/delete_term', array( $this, 'delete_existing_term' ) );
		add_action( 'wp_ajax_betterlinks/admin/fetch_analytics', array( $this, 'fetch_analytics' ) );

		// post type, tags, categories.
		add_action( 'wp_ajax_betterlinks/admin/get_post_types', array( $this, 'get_post_types' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_post_tags', array( $this, 'get_post_tags' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_post_categories', array( $this, 'get_post_categories' ) );

		// Affiliate Disclosure Text.
		add_action( 'wp_ajax_betterlinks/admin/set_affiliate_link_disclosure_post', array( $this, 'set_affiliate_link_disclosure_post' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_affiliate_link_disclosure_post', array( $this, 'get_affiliate_link_disclosure_post' ) );
		add_action( 'wp_ajax_betterlinks/admin/set_affiliate_link_disclosure_text', array( $this, 'set_affiliate_link_disclosure_text' ) );
		add_action( 'wp_ajax_betterlinks/admin/get_affiliate_link_disclosure_text', array( $this, 'get_affiliate_link_disclosure_text' ) );

		// Auto create links settings.
		if ( \BetterLinks\Helper::pro_needs_update() ) { // Compatibility: BetterLinks Pro before 3.0.4 (newer Pro registers this).
			add_action( 'wp_ajax_betterlinks/admin/get_auto_create_links_settings', array( $this, 'get_auto_create_links_settings' ) );
		}
		// External Analytics settings.
		if ( \BetterLinks\Helper::pro_needs_update() ) { // Compatibility: BetterLinks Pro before 3.0.4 (newer Pro registers this).
			add_action( 'wp_ajax_betterlinks/admin/get_external_analytics', array( $this, 'get_external_analytics' ) );
		}

		// Analytics
		add_action( 'wp_ajax_betterlinks__admin_fetch_analytics_graph', array( $this, 'fetch_analytics_graph' ) );

		// Notices
		add_action( 'wp_ajax_betterlinks__admin_menu_notice', array( $this, 'admin_menu_notice' ) );
		add_action( 'wp_ajax_betterlinks__admin_dashboard_notice', array( $this, 'admin_dashboard_notice' ) );

		add_action( 'wp_ajax_betterlinks__fetch_target_url', array( $this, 'fetch_target_url' ) );

		// Fluent Board Integration
		add_action( 'wp_ajax_betterlinks__check_fbs_link', array( $this, 'check_fbs_link' ) );
		add_action( 'wp_ajax_betterlinks__create_fbs_link', array( $this, 'create_fbs_link' ) );
		add_action( 'wp_ajax_betterlinks__update_fbs_link', array( $this, 'update_fbs_link' ) );

		// Quick Setu
		add_action( 'wp_ajax_betterlinks__client_consent', array( $this, 'client_consent' ) );
		add_action( 'wp_ajax_betterlinks__complete_setup', array( $this, 'complete_setup' ) );
		// js analytics tracking
		add_action( 'wp_ajax_nopriv_betterlinks__js_analytics_tracking', array( $this, 'js_analytics_tracking' ) );
		add_action( 'wp_ajax_betterlinks__js_analytics_tracking', array( $this, 'js_analytics_tracking' ) );

		// Update click country data (for backward compatibility)

	}

	/**
	 * Authorize a FluentBoards short-link request.
	 *
	 * `defined( 'FLUENT_BOARDS' )` proves the plugin is active, not that this
	 * user may use it, and a nonce proves session origin, not permission — so
	 * neither gate authorizes anything on its own. Board membership lives in
	 * FluentBoards' own relations table and is orthogonal to the WP role, which
	 * is why gating on `manage_options` would lock out the low-role board
	 * members this feature exists for. Defer to FluentBoards' PermissionManager
	 * instead, scoped to the board that owns the task in play.
	 *
	 * @param int|string $task_id FluentBoards task the request targets.
	 * @return bool
	 */
	private function current_user_can_manage_fbs_link( $task_id ) {
		$can = false;

		if ( is_user_logged_in() && defined( 'FLUENT_BOARDS' ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				$can = true;
			} elseif ( class_exists( 'FluentBoards\App\Services\PermissionManager' ) ) {
				$board_id = $this->get_fbs_board_id_by_task( $task_id );
				// No resolvable board means there is nothing to authorize
				// against, so fail closed instead of falling back to a check
				// that any logged-in user would pass.
				$can = $board_id > 0 && (bool) \FluentBoards\App\Services\PermissionManager::userHasPermission( $board_id );
			}
		}

		return (bool) apply_filters( 'betterlinks/fbs/current_user_can_manage_link', $can, absint( $task_id ) );
	}

	/**
	 * Resolve the board a FluentBoards task belongs to.
	 *
	 * @param int|string $task_id FluentBoards task id.
	 * @return int Board id, or 0 when the task does not exist.
	 */
	private function get_fbs_board_id_by_task( $task_id ) {
		$task_id = absint( $task_id );
		if ( ! $task_id ) {
			return 0;
		}

		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT `board_id` FROM {$wpdb->prefix}fbs_tasks WHERE `id` = %d", $task_id )
		);
	}

	/**
	 * Resolve the FluentBoards task a BetterLinks row was created for.
	 *
	 * Only links this integration created carry the `fbs-<task id>` slug, so a
	 * row without one is out of scope for the FluentBoards handlers entirely
	 * and must not be reachable through them.
	 *
	 * @param int|string $link_id BetterLinks link id.
	 * @return int Task id, or 0 when the link is not a FluentBoards link.
	 */
	private function get_fbs_task_id_by_link( $link_id ) {
		$link_id = absint( $link_id );
		if ( ! $link_id ) {
			return 0;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT `link_slug`, `target_url` FROM {$wpdb->prefix}betterlinks WHERE `ID` = %d", $link_id ),
			ARRAY_A
		);

		if ( empty( $row ) ) {
			return 0;
		}

		// Primary key: the `fbs-<task id>` slug this integration stamps on create.
		$link_slug = isset( $row['link_slug'] ) ? (string) $row['link_slug'] : '';
		if ( '' !== $link_slug && 0 === strpos( $link_slug, 'fbs-' ) ) {
			$task_id = absint( substr( $link_slug, 4 ) );
			if ( $task_id ) {
				return $task_id;
			}
		}

		// Fall back to the target URL, because `link_slug` is NOT immutable:
		// saving the same link from the main BetterLinks screen rewrites it (the
		// editor posts link_slug on every save and derives it from the title when
		// empty), so a genuine board link can drift out of the `fbs-` shape. On
		// the slug check alone that link would then be refused to its own board
		// members — the quiet half of an authorization change, a legitimate user
		// locked out rather than an attacker let in.
		return $this->get_fbs_task_id_by_target_url( isset( $row['target_url'] ) ? $row['target_url'] : '' );
	}

	/**
	 * Resolve a FluentBoards task id from a BetterLinks row's stored target URL.
	 *
	 * This widens nothing: the URL is a stored property of the row being edited,
	 * not caller input, and it must sit under THIS site's FluentBoards page URL —
	 * so an arbitrary external link that merely happens to contain `tasks/<n>`
	 * cannot mint a task id. Whatever id comes back is still handed to the board
	 * membership check, which is what actually authorizes the request.
	 *
	 * @param string $target_url Stored target URL of a BetterLinks row.
	 * @return int Task id, or 0 when this is not a FluentBoards task URL on this site.
	 */
	private function get_fbs_task_id_by_target_url( $target_url ) {
		$target_url = (string) $target_url;

		if ( '' === $target_url || ! function_exists( 'fluent_boards_page_url' ) ) {
			return 0;
		}

		$page_url = (string) fluent_boards_page_url();
		if ( '' === $page_url || 0 !== strpos( $target_url, $page_url ) ) {
			return 0;
		}

		return preg_match( '#/tasks/(\d+)#', $target_url, $matches ) ? absint( $matches[1] ) : 0;
	}

	/**
	 * Deny a FluentBoards short-link request and stop.
	 *
	 * @return void
	 */
	private function send_fbs_permission_error() {
		wp_send_json_error(
			array(
				'result'  => false,
				'message' => __( 'Insufficient permissions', 'betterlinks' ),
			),
			403
		);
	}

	public function update_fbs_link() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! defined( 'FLUENT_BOARDS' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$helper        = new Helper();
		$id            = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$short_url     = isset( $_POST['short_url'] ) ? sanitize_text_field( wp_unslash( $_POST['short_url'] ) ) : null;
		$old_short_url = isset( $_POST['old_short_url'] ) ? sanitize_text_field( wp_unslash( $_POST['old_short_url'] ) ) : null;

		// `id` was an unscoped pointer into the links table — any row, any
		// owner. Resolve it back to the FluentBoards task this integration
		// created it for, then authorize against that task's board; a link that
		// did not come from this integration is never editable here.
		$task_id = $this->get_fbs_task_id_by_link( $id );
		if ( ! $task_id || ! $this->current_user_can_manage_fbs_link( $task_id ) ) {
			$this->send_fbs_permission_error();
		}

		if ( $helper::is_exists_short_url( $short_url ) ) {
			wp_send_json_error(
				array(
					'result'  => false,
					'message' => __( 'Link already exists', 'betterlinks' ),
				)
			);
		}

		global $wpdb;
		$data  = array(
			'short_url' => $short_url,
		);
		$where = array(
			'id' => $id,
		);
		if ( empty( $wpdb->update( $wpdb->prefix . 'betterlinks', $data, $where ) ) ) {
			wp_send_json_error(
				array(
					'result'  => false,
					'message' => __( 'Something went wrong, please try again', 'betterlinks' ),
				)
			);
		}
		$helper::clear_query_cache();
		if ( BETTERLINKS_EXISTS_LINKS_JSON ) {
			// Fetch the complete link data from database to update JSON file
			$link_data = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}betterlinks WHERE ID = %d",
					$id
				),
				ARRAY_A
			);
			if ( $link_data ) {
				// Update short_url with the new value
				$link_data['short_url'] = $short_url;
				$helper::update_json_into_file( trailingslashit( BETTERLINKS_UPLOAD_DIR_PATH ) . 'links.json', $link_data, $old_short_url );
			}
		}

		// A successful update answered with `wp_send_json_error()`, i.e. an
		// error envelope carrying a success message. The popover happened to
		// survive it by reading `data.result` regardless of the envelope, but
		// anything checking `success` saw every update as a failure.
		wp_send_json_success(
			array(
				'result'  => array(
					'short_url' => $short_url,
				),
				'message' => __( 'Short Link updated successfully', 'betterlinks' ),
			)
		);
	}
	public function create_fbs_link() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! defined( 'FLUENT_BOARDS' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$helper = new Helper();

		$settings = Cache::get_json_settings();
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$taskId   = isset( $_POST['taskId'] ) ? sanitize_text_field( wp_unslash( $_POST['taskId'] ) ) : null;
		if ( empty( $taskId ) ) {
			wp_send_json_error(
				array(
					'result' => false,
				)
			);
		}

		// Authorize against the board that owns this task before minting a
		// redirect on the site's own domain from attacker-supplied input.
		if ( ! $this->current_user_can_manage_fbs_link( $taskId ) ) {
			$this->send_fbs_permission_error();
		}

		$slug             = "fbs-{$taskId}";
		$target_url       = isset( $_POST['target_url'] ) ? sanitize_url( wp_unslash( $_POST['target_url'] ) ) : null;
		$short_url        = isset( $_POST['short_url'] ) ? sanitize_text_field( wp_unslash( $_POST['short_url'] ) ) : null;
		$prefix           = isset( $settings['prefix'] ) ? $settings['prefix'] . '/' : '';
		$short_url        = ! empty( $short_url ) ? $short_url : $prefix . $slug;
		$nofollow         = ! empty( $settings['nofollow'] ) ? $settings['nofollow'] : null;
		$sponsored        = ! empty( $settings['sponsored'] ) ? $settings['sponsored'] : null;
		$track_me         = ! empty( $settings['track_me'] ) ? $settings['track_me'] : null;
		$param_forwarding = ! empty( $settings['param_forwarding'] ) ? $settings['param_forwarding'] : null;
		$date             = wp_date( 'Y-m-d H:i:s' );
		$redirect_type    = ! empty( $settings['redirect_type'] ) ? $settings['redirect_type'] : '307';
		$fbs_cat          = ! empty( $settings['fbs']['cat_id'] ) ? $settings['fbs']['cat_id'] : 1;

		if ( empty( $settings['fbs']['cat_id'] ) ) {
			delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
			$args                      = array(
				'ID'        => 0,
				'term_name' => 'Fluent Boards',
				'term_slug' => 'btl-fluent-boards',
				'term_type' => 'category',
			);
			$results                   = $this->create_term( $args );
			$fbs_cat                   = ! empty( $results['ID'] ) ? $results['ID'] : $fbs_cat;
			$settings['fbs']['cat_id'] = $fbs_cat;

			$response = json_encode( $settings );

			if ( $response ) {
				update_option( BETTERLINKS_LINKS_OPTION_NAME, $response );
				Cache::write_json_settings();
			}
			// regenerate links for wildcards option update
			Helper::write_links_inside_json();
		}

		$initial_values = array(
			'link_title'        => $title,
			'link_slug'         => $slug,
			'target_url'        => $target_url,
			'short_url'         => $short_url,
			'redirect_type'     => $redirect_type,
			'nofollow'          => $nofollow,
			'sponsored'         => $sponsored,
			'track_me'          => $track_me,
			'param_forwarding'  => $param_forwarding,
			'link_date'         => $date,
			'link_date_gmt'     => $date,
			'link_modified'     => $date,
			'link_modified_gmt' => $date,
			'cat_id'            => $fbs_cat,
		);

		$helper->clear_query_cache();
		$args    = $this->sanitize_links_data( $initial_values );
		$results = $this->insert_link( $args );

		if ( empty( $results ) ) {
			wp_send_json_error(
				array(
					'result'  => array(
						'short_url' => $short_url,
					),
					'status'  => false,
					// The client shows whatever message comes back, so name the
					// actual reason here instead of letting it assume this is
					// the only way creating a link can fail.
					'message' => __( 'Link already exists', 'betterlinks' ),
				)
			);
		}

		wp_send_json_success(
			array(
				'result' => $results,
				'status' => true,
			)
		);
	}

	public function check_fbs_link() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! defined( 'FLUENT_BOARDS' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$boardUrl = isset( $_POST['boardUrl'] ) ? sanitize_text_field( wp_unslash( $_POST['boardUrl'] ) ) : null;
		$taskId   = isset( $_POST['taskId'] ) ? (int) sanitize_text_field( wp_unslash( $_POST['taskId'] ) ) : null;

		// Reads a task's title/slug and its existing short URL by id, so it
		// needs the same board-scoped authorization as the write handlers.
		if ( ! $this->current_user_can_manage_fbs_link( $taskId ) ) {
			$this->send_fbs_permission_error();
		}

		$target_url = null;

		if ( ! empty( $boardUrl ) || ! empty( $taskId ) ) {
			global $wpdb;

			$target_url = $boardUrl . 'tasks/' . $taskId;
			$link       = Helper::get_link_by_permalink( $target_url, '`id`, `short_url`' );
			$task       = $wpdb->get_row( $wpdb->prepare( "SELECT `title`,`slug` FROM {$wpdb->prefix}fbs_tasks WHERE id=%d", $taskId ) );

			if ( ! empty( $link ) ) {
				wp_send_json_success(
					array(
						'result'    => array(
							'id'        => $link['id'],
							'short_url' => $link['short_url'],
							'task_slug' => $task->slug,
						),
						'is_exists' => true,
					)
				);
			}

			// if not exists any short url
			$task = $wpdb->get_row( $wpdb->prepare( "SELECT `title`,`slug` FROM {$wpdb->prefix}fbs_tasks WHERE id=%d", $taskId ) );

			if ( ! empty( $task ) ) {
				wp_send_json_success(
					array(
						'result'    => array(
							'title'      => $task->title,
							'slug'       => $task->slug,
							'target_url' => $target_url,
						),
						'is_exists' => false,
					)
				);
			}
		}

		wp_send_json_error(
			array(
				'result' => false,
			)
		);
	}

	public function fetch_target_url() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		
		// Check if user has permission - either manage_options or role-based permissions
		$can_fetch_target_url = current_user_can( 'manage_options' );
		
		// Allow role-based permissions from BetterLinks Pro
		if ( ! $can_fetch_target_url ) {
			$can_fetch_target_url = apply_filters( 'betterlinks_can_fetch_target_url', false );
		}
		
		if ( ! $can_fetch_target_url ) {
			wp_send_json_error(
				array(
					'result'  => false,
					'message' => __( 'You don\'t have permission to fetch target URL.', 'betterlinks' ),
				)
			);
		}

		$target_url = isset( $_POST['target_url'] ) ? sanitize_url( wp_unslash( $_POST['target_url'] ) ) : '';
		$title      = ( new Helper() )->fetch_target_url( $target_url );

		if ( empty( $title ) ) {
			wp_send_json_error(
				array(
					'result'  => false,
					'message' => 'Something wrong with target url or title',
				)
			);
		}

		wp_send_json(
			array(
				'result' => array(
					'title' => $title,
				),
			)
		);
	}

	public function admin_dashboard_notice() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$dashboard_notice = get_option( 'betterlinks_dashboard_notice', 0 );
		if ( BETTERLINKS_MENU_NOTICE !== $dashboard_notice ) {
			update_option( 'betterlinks_dashboard_notice', BETTERLINKS_MENU_NOTICE );
		}
		wp_send_json(
			array(
				'result' => BETTERLINKS_MENU_NOTICE,
			)
		);
	}
	public function admin_menu_notice() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		wp_send_json(
			array(
				'result' => get_option( 'betterlinks_menu_notice', 0 ),
			)
		);
	}
	public function fetch_analytics_graph() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';

		if( ! strtotime( $from ) || ! strtotime( $to ) ){
			wp_send_json_error( [
				'message' => __( "Invalid date range provided.", 'betterlinks' ),
			], 400 );
		}

		global $wpdb;
		$query   = $wpdb->prepare(
			"SELECT id,link_id,ip,created_at FROM {$wpdb->prefix}betterlinks_clicks WHERE created_at BETWEEN %s AND %s",
			 $from .  ' 00:00:00', $to . ' 23:59:59');

		$results = $wpdb->get_results( $query );
		wp_send_json(
			array(
				'results' => $results,
			)
		);
	}

	public function get_prettylinks_data() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		
		$pretty_links_data = Helper::get_prettylinks_data();

		wp_send_json_success($pretty_links_data);
	}

	public function run_prettylinks_migration() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		// give betterlinks a lot of time to properly set the migration work for background.
		set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- migration job needs extended runtime.
		$re_run = isset( $_POST['re_run'] ) ? sanitize_text_field( wp_unslash( $_POST['re_run'] ) ) : false;

		if ( empty($re_run) && Helper::btl_get_option( 'betterlinks_prettylink_migration_should_not_start_in_background' ) ) {
			// preventing multiple migration call to prevent duplicate datas from migrating.
			wp_send_json_error( array( 'duplicate_migration_detected__so_prevented_it_here' => true ) );
		}
		$pretty_links_data = null;
		if( !empty( $re_run ) ){
			$pretty_links_data = Helper::get_prettylinks_data();
			delete_option('betterlinks_prettylink_migration_should_not_start_in_background');
		}
		
		Helper::btl_update_option( 'betterlinks_prettylink_migration_should_not_start_in_background', true, true );
		global $wpdb;
		$query = "DELETE FROM {$wpdb->prefix}options WHERE option_name IN(
                'betterlinks_notice_ptl_migration_running_in_background',
                'betterlinks_failed_migration_prettylinks_links',
                'betterlinks_failed_migration_prettylinks_clicks',
                'betterlinks_migration_prettylinks_current_successful_links_count',
                'betterlinks_migration_prettylinks_current_successful_clicks_count',
                'btl_failed_migration_prettylinks_links',
                'btl_failed_migration_prettylinks_clicks',
                'btl_migration_prettylinks_current_successful_links_count',
                'btl_migration_prettylinks_current_successful_clicks_count'
        )";
		$wpdb->query( $query ); // phpcs:ignore.
		Helper::btl_update_option( 'betterlinks_failed_migration_prettylinks_links', array(), true );
		Helper::btl_update_option( 'betterlinks_failed_migration_prettylinks_clicks', array(), true );
		Helper::btl_update_option( 'betterlinks_migration_prettylinks_current_successful_links_count', 0, true );
		Helper::btl_update_option( 'betterlinks_migration_prettylinks_current_successful_clicks_count', 0, true );

		$type                  = isset( $_POST['type'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['type'] ) ) ) : '';
		$total_links_clicks    = !empty( $pretty_links_data ) ? $pretty_links_data : get_transient( 'betterlinks_migration_data_prettylinks' );
		$should_migrate_links  = ! ( strpos( $type, 'links' ) === false );
		$should_migrate_clicks = ! ( strpos( $type, 'clicks' ) === false );
		$installer = new \BetterLinks\Installer();
		if ( $should_migrate_links && ! empty( $total_links_clicks['links_count'] ) ) {
			$links_count = absint( $total_links_clicks['links_count'] );
			$installer   = Helper::run_migration_for_ptrl_links_in_background( $installer, $links_count );
		}

		if ( $should_migrate_clicks && ! empty( $total_links_clicks['clicks_count'] ) ) {
			$clicks_count = absint( $total_links_clicks['clicks_count'] );
			$installer    = Helper::run_migration_for_ptrl_clicks_in_background( $installer, $clicks_count );
		}

		$installer->data( array( 'betterlinks_notice_ptl_migrate' ) )->save();
		$installer->dispatch();
		Helper::btl_update_option( 'betterlinks_notice_ptl_migration_running_in_background', true, true );
		wp_send_json_success( array( 'btl_prettylinks_migration_running_in_background' => true ) );
	}

	public function migration_prettylinks_notice_hide() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		if ( 'deactive' === $type ) {
			update_option( 'betterlinks_hide_notice_ptl_deactive', true );
		} elseif ( 'migrate' === $type ) {
			update_option( 'betterlinks_hide_notice_ptl_migrate', true );
		}
		wp_die( "You don't have permission to do this." );
	}
	public function deactive_prettylinks() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$deactivate = deactivate_plugins( 'pretty-link/pretty-link.php' );
		wp_send_json_success( $deactivate );
	}
	public function write_json_links() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( apply_filters( 'betterlinks/admin/current_user_can_edit_settings', current_user_can( 'manage_options' ) ) ) { // phpcs:ignore.
			$Cron    = new Cron();
			$resutls = $Cron->write_json_links();
			wp_send_json_success( $resutls );
		}
		wp_die( "You don't have permission to do this." );
	}
	public function write_json_clicks() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( apply_filters( 'betterlinks/admin/current_user_can_edit_settings', current_user_can( 'manage_options' ) ) && ! BETTERLINKS_EXISTS_CLICKS_JSON ) {
			global $wp_filesystem;
			if ( empty( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$wp_filesystem->put_contents(
				trailingslashit( BETTERLINKS_UPLOAD_DIR_PATH ) . 'clicks.json',
				'{}',
				FS_CHMOD_FILE
			);
			wp_send_json_success( true );
		}
		wp_send_json_error( false );
	}
	public function analytics() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( apply_filters( 'betterlinks/admin/current_user_can_edit_settings', current_user_can( 'manage_options' ) ) ) {
			$Cron    = new Cron();
			$resutls = $Cron->analytics();
			wp_send_json_success( $resutls );
		}
		wp_die( "You don't have permission to do this." );
	}
	public function short_url_unique_checker() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( apply_filters( 'betterlinks/admin/current_user_can_edit_settings', current_user_can( 'manage_options' ) ) ) {
			$ID            = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
			$slug          = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
			$alreadyExists = false;
			$overridable   = false;
			$own_slug      = false;
			$message       = '';
			$resutls       = array();
			if ( ! empty( $slug ) ) {
				$resutls = Helper::get_link_by_short_url( $slug );
				if ( count( $resutls ) > 0 ) {
					$resutls       = current( $resutls );
					$alreadyExists = $resutls['ID'] != $ID;
					// The slug is this link's own and is not changing. The save
					// route skips validation for an unchanged short URL, so the
					// form must not refuse it either — otherwise a link that already
					// sits on a WordPress path (every Instant Redirect, and links
					// saved before the collision check existed) can never be edited.
					$own_slug = ! $alreadyExists && isset( $resutls['short_url'] ) && (string) $resutls['short_url'] === $slug;
				}
				if ( $alreadyExists ) {
					$message = __( 'Already Exists', 'betterlinks' );
				} elseif ( ! $own_slug ) {
					// Flag a slug that would shadow real WordPress content while
					// the form is still open, so the conflict is fixable in place
					// rather than being rejected after submit.
					$collision = Helper::check_wp_url_collision( $slug );
					if ( is_wp_error( $collision ) ) {
						$data          = $collision->get_error_data();
						$alreadyExists = true;
						$message       = isset( $data['short_message'] ) ? $data['short_message'] : $collision->get_error_message();
						$overridable   = $this->can_override_wp_url_collision( $collision );
					}
				}
			}
			wp_send_json_success(
				array(
					'exists'      => $alreadyExists,
					'message'     => $message,
					// True when the user may confirm the collision and save anyway.
					'overridable' => $overridable,
				)
			);
		}
		wp_die( "You don't have permission to do this." );
	}
	public function cat_slug_unique_checker() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$ID            = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$slug          = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$alreadyExists = false;
		$resutls       = array();
		if ( ! empty( $slug ) ) {
			$resutls = Helper::get_term_by_slug( $slug );
			if ( count( $resutls ) > 0 ) {
				$alreadyExists = true;
				$resutls       = current( $resutls );
				if ( $resutls['ID'] == $ID ) {
					$alreadyExists = false;
				}
			}
		}
		wp_send_json_success( $alreadyExists );
	}
	public function get_simple301redirects_data() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$links = get_option( '301_redirects' );
		wp_send_json_success( $links );
	}
	public function run_simple301redirects_migration() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		try {
			$simple_301_redirects = get_option( '301_redirects', [] );
			$migrator             = new \BetterLinks\Tools\Migration\S301ROneClick();
			$resutls              = $migrator->run_importer( array_reverse( $simple_301_redirects ) );
			do_action( 'betterlinks/admin/after_import_data' );
			update_option( 'betterlinks_notice_s301r_migrate', true );
			wp_send_json_success( $resutls );
		} catch ( \Throwable $th ) {
			wp_send_json_error( $th->getMessage() );
		}
	}
	public function migration_simple301redirects_notice_hide() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		if ( $type == 'deactive' ) {
			update_option( 'betterlinks_hide_notice_s301r_deactive', true );
		} elseif ( $type == 'migrate' ) {
			update_option( 'betterlinks_notice_s301r_migrate', true );
		}
		wp_die( "You don't have permission to do this." );
	}
	public function deactive_simple301redirects() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$deactivate = deactivate_plugins( 'simple-301-redirects/wp-simple-301-redirects.php' );
		wp_send_json_success( $deactivate );
	}
	public function search_clicks_data() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$title   = isset( $_GET['title'] ) ? sanitize_text_field( wp_unslash( $_GET['title'] ) ) : '';
		$results = Helper::search_clicks_data( $title );

		wp_send_json_success(
			array(
				'clicks' => $results,
			)
		);
	}
	public function links_reorder() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$links = ( isset( $_POST['links'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['links'] ) ) ) : array() );
		if ( count( $links ) > 0 ) {
			foreach ( $links as $key => $value ) {
				Helper::insert_link(
					array(
						'ID'         => $value,
						'link_order' => $key,
					),
					true
				);
			}
		}
		wp_send_json_success( array() );
	}
	public function links_move_reorder() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$source      = ( isset( $_POST['source'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['source'] ) ) ) : array() );
		$destination = ( isset( $_POST['destination'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['destination'] ) ) ) : array() );
		if ( count( $source ) > 0 ) {
			foreach ( $source as $key => $value ) {
				Helper::insert_link(
					array(
						'ID'         => $value,
						'link_order' => $key,
					),
					true
				);
			}
		}
		if ( count( $destination ) > 0 ) {
			foreach ( $destination as $key => $value ) {
				Helper::insert_link(
					array(
						'ID'         => $value,
						'link_order' => $key,
					),
					true
				);
			}
		}
		wp_send_json_success( array() );
	}

	/**
	 * Persist the Board view's category (column) order.
	 *
	 * Receives an ordered, comma-separated list of category term IDs and writes
	 * each one's position into the `term_order` column — which the terms query
	 * already sorts by (`ORDER BY term_order ASC`). Categories are stored in the
	 * BetterLinks terms table (no term JSON cache), so a direct update is safe.
	 */
	public function terms_reorder() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		global $wpdb;
		$terms = ( isset( $_POST['terms'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_POST['terms'] ) ) ) : array() );
		if ( count( $terms ) > 0 ) {
			foreach ( $terms as $order => $term_id ) {
				$term_id = absint( $term_id );
				if ( ! $term_id ) {
					continue;
				}
				$wpdb->update(
					"{$wpdb->prefix}betterlinks_terms",
					array( 'term_order' => (int) $order ),
					array( 'ID' => $term_id ),
					array( '%d' ),
					array( '%d' )
				);
			}
		}
		wp_send_json_success( array() );
	}

	public function get_thirstyaffiliates_data() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$response = Helper::get_thirstyaffiliates_links();
		wp_send_json_success( $response );
	}

	public function run_thirstyaffiliates_migration() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		try {
			$links    = Helper::get_thirstyaffiliates_links();
			$migrator = new \BetterLinks\Tools\Migration\TAOneClick();
			$resutls  = $migrator->run_importer( $links );
			do_action( 'betterlinks/admin/after_import_data' );
			update_option( 'betterlinks_notice_ta_migrate', true );
			wp_send_json_success( $resutls );
		} catch ( \Throwable $th ) {
			wp_send_json_error( $th->getMessage() );
		}
	}

	public function deactive_thirstyaffiliates() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$deactivate = deactivate_plugins( 'thirstyaffiliates/thirstyaffiliates.php' );
		wp_send_json_success( $deactivate );
	}

	public function get_links_by_short_url() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$short_url = ( isset( $_POST['short_url'] ) ? sanitize_text_field( wp_unslash( $_POST['short_url'] ) ) : '' );
		$results   = Helper::get_link_by_short_url( $short_url );
		wp_send_json_success( is_array( $results ) ? current( $results ) : false );
	}
	public function get_links_by_permalink() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$short_url = ( isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '' );
		$results   = Helper::get_link_by_permalink( $short_url );
		wp_send_json_success( is_array( $results ) ? $results : false );
	}

	public function get_category_by_link_id() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$ID      = ( isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '' );
		$results = Helper::get_terms_by_link_ID_and_term_type( $ID, 'category' );
		return wp_send_json( $results );
	}

	public function get_betterlink_categories() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$categories = $this->get_all_categories();
		$formatted_categories = array();

		foreach ($categories as $category) {
			$formatted_categories[] = array(
				'value' => $category['ID'],
				'label' => $category['term_name'],
				'slug' => $category['term_slug'],
				'link_count' => isset($category['link_count']) ? $category['link_count'] : 0
			);
		}

		wp_send_json_success($formatted_categories);
	}

	public function create_betterlink_category() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$category_name = isset($_POST['category_name']) ? sanitize_text_field(wp_unslash( $_POST['category_name'] )) : '';
		
		if (empty($category_name)) {
			wp_send_json_error(array('message' => __('Category name is required', 'betterlinks')));
			return;
		}

		// Create the category using the existing Helper method
		$term_data = array(
			'term_name' => $category_name,
			'term_slug' => sanitize_title($category_name),
			'term_type' => 'category'
		);

		$term_id = Helper::insert_term($term_data);
		
		if ($term_id) {
			// Return the created category data
			$created_category = array(
				'id' => $term_id,
				'term_name' => $category_name,
				'term_slug' => sanitize_title($category_name),
				'link_count' => 0
			);
			
			wp_send_json_success($created_category);
		} else {
			wp_send_json_error(array('message' => __('Failed to create category', 'betterlinks')));
		}
	}

	public function get_betterlink_tags() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$tags = $this->get_all_tags();
		$formatted_tags = array();

		foreach ($tags as $tag) {
			$formatted_tags[] = array(
				'value' => $tag['ID'],
				'label' => $tag['term_name'],
				'slug' => $tag['term_slug'],
				'link_count' => isset($tag['link_count']) ? $tag['link_count'] : 0
			);
		}

		wp_send_json_success($formatted_tags);
	}

	public function get_auto_link_create_settings() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$data = get_option( 'betterlinkspro_auto_link_create', array() );
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		wp_send_json_success( $data );
	}

	public function get_all_links() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/links_get_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$cache_data = get_transient( BETTERLINKS_CACHE_LINKS_NAME );
		if ( empty( $cache_data ) || ! json_decode( $cache_data, true ) ) {
			$results = Helper::get_prepare_all_links();
			set_transient( BETTERLINKS_CACHE_LINKS_NAME, json_encode( $results ) );
			wp_send_json_success(
				array(
					'success' => true,
					'cache'   => false,
					'data'    => $results,
				),
				200
			);
		}
		wp_send_json_success(
			array(
				'success' => true,
				'cache'   => true,
				'data'    => json_decode( $cache_data ),
			),
			200
		);
	}
	public function create_new_link() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/links_create_item_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		$args = $this->sanitize_links_data( $_POST );
		// The React app falls back to this handler whenever the REST call
		// throws, so it has to refuse the same payloads the REST route does —
		// including the Instant Redirect exemption the REST route honours.
		$invalid = $this->validate_link_payload( $args, false, $this->resolve_instant_redirect_post_id( $_POST ), $this->resolve_wp_url_override( $_POST ) );
		if ( is_wp_error( $invalid ) ) {
			wp_send_json_error(
				array(
					'code'    => $invalid->get_error_code(),
					'message' => $invalid->get_error_message(),
				),
				200
			);
		}
		$results = $this->insert_link( $args );
		if ( $results ) {
			wp_send_json_success(
				$results,
				200
			);
		}
		wp_send_json_error(
			$results,
			200
		);
	}
	public function update_existing_link() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/links_update_item_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		$args    = $this->sanitize_links_data( $_POST );
		$invalid = $this->validate_link_payload( $args, true, $this->resolve_instant_redirect_post_id( $_POST ), $this->resolve_wp_url_override( $_POST ) );
		if ( is_wp_error( $invalid ) ) {
			wp_send_json_error(
				array(
					'code'    => $invalid->get_error_code(),
					'message' => $invalid->get_error_message(),
				),
				200
			);
		}
		$results = $this->update_link( $args );
		if ( $results ) {
			wp_send_json_success(
				$results,
				200
			);
		}
		wp_send_json_error(
			$args,
			200
		);
	}
	public function handle_links_favorite_option() {
		if ( isset( $_POST['favForAll'] ) && isset( $_POST['ID'] ) ) {
			check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
			if ( ! apply_filters( 'betterlinks/api/links_update_favorite_permissions_check', current_user_can( 'manage_options' ) ) ) {
				wp_die( "You don't have permission to do this." );
			}
			delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
			$params   = array(
				'ID'   => absint( $_POST['ID'] ),
				'data' => array(
					'favForAll' => $_POST['favForAll'] === 'true' ? true : false,
				),
			);
			$result   = $this->update_link_favorite( $params );
			$response = array(
				'ID'        => $params['ID'],
				'favForAll' => $params['data']['favForAll'],
			);
			if ( $result ) {
				wp_send_json_success(
					$response,
					200
				);
			}
			wp_send_json_error(
				$response,
				200
			);
		}
	}
	public function delete_existing_link() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		$args = array(
			'ID'        => ( isset( $_REQUEST['ID'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ID'] ) ) : '' ),
			'short_url' => ( isset( $_REQUEST['short_url'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['short_url'] ) ) : '' ),
			'term_id'   => ( isset( $_REQUEST['term_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term_id'] ) ) : '' ),
		);
		$this->delete_link( $args );

		wp_send_json_success(
			$args,
			200
		);
	}
	public function get_settings() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/settings_get_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		// $results = get_option( BETTERLINKS_LINKS_OPTION_NAME, '[]' );
		$results = Cache::get_json_settings();
		if ( $results ) {
			wp_send_json_success(
				json_encode( $results ),
				200
			);
		}
		wp_send_json_success(
			array(
				'success' => false,
				'data'    => '{}',
			),
			200
		);
	}
	public function update_settings() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/settings_update_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$helper                           = new Helper();
		$response                         = $helper::fresh_ajax_request_data( $_POST );
		$response                         = $helper::sanitize_text_or_array_field( $response );
		$response['uncloaked_categories'] = isset( $response['uncloaked_categories'] ) && is_string( $response['uncloaked_categories'] ) ? json_decode( $response['uncloaked_categories'] ) : array();
		

		// Pro Logics
		$response = apply_filters( 'betterlinkspro/admin/update_settings', $response );

		// Compatibility: BetterLinks Pro before 3.0.4 relies on this to sanitize its
		// custom auto-link icon. Newer Pro sanitizes it in betterlinkspro/admin/update_settings.
		if ( ! empty( $response['autolink_custom_icon'] ) && \BetterLinks\Helper::pro_needs_update() ) {
			// Use the sanitize_custom_svg method if it exists, otherwise use custom wp_kses for SVG
			if ( class_exists( '\BetterLinksPro\Frontend\AutoLinks' ) && method_exists( '\BetterLinksPro\Frontend\AutoLinks', 'sanitize_custom_svg' ) ) {
				$response['autolink_custom_icon'] = \BetterLinksPro\Frontend\AutoLinks::sanitize_custom_svg( $response['autolink_custom_icon'] );
			} else {
				// Custom wp_kses with SVG allowed elements as fallback
				$allowed_svg = array(
					'svg' => array( 'class' => array(), 'width' => array(), 'height' => array(), 'viewbox' => array(), 'viewBox' => array(), 'fill' => array(), 'xmlns' => array() ),
					'path' => array( 'd' => array(), 'stroke' => array(), 'stroke-width' => array(), 'stroke-linecap' => array(), 'stroke-linejoin' => array(), 'fill' => array() ),
					'g' => array( 'fill' => array(), 'stroke' => array() ),
					'circle' => array( 'cx' => array(), 'cy' => array(), 'r' => array(), 'fill' => array(), 'stroke' => array() ),
					'rect' => array( 'x' => array(), 'y' => array(), 'width' => array(), 'height' => array(), 'fill' => array(), 'stroke' => array() ),
				);
				$response['autolink_custom_icon'] = wp_kses( $response['autolink_custom_icon'], $allowed_svg );
			}
		}

		if ( ! empty( $response['fbs']['enable_fbs'] ) ) {
			$category                  = ! empty( $response['fbs']['cat_id'] ) ? sanitize_text_field( $response['fbs']['cat_id'] ) : 1;
			$category                  = $helper::insert_new_category( $category );
			$response['fbs']['cat_id'] = $category;
		}

		update_option( BETTERLINKS_CUSTOM_DOMAIN_MENU, !empty( $response['enable_custom_domain_menu'] ) ? $response['enable_custom_domain_menu'] : false );
		$response = json_encode( $response );
		if ( $response ) {
			update_option( BETTERLINKS_LINKS_OPTION_NAME, $response );
			// The REST handler refreshes both of these on save; this fallback did
			// neither, so a save that landed here kept serving the old settings file
			// AND the pre-save links payload (visibility of the Fluent Boards / Link
			// in Bio categories is baked into that cached payload).
			\BetterLinks\Admin\Cache::write_json_settings();
			$helper::clear_query_cache();
		}
		// regenerate links for wildcards option update
		$helper::write_links_inside_json(); // it's better to write the links instantly here than scheduling/corning it

		// Same contract as the REST handler: return the hidden-category list under
		// the settings just saved, so the SPA can refresh its page-load copy.
		$hidden_term_ids = apply_filters( 'betterlinks/dashboard_hidden_term_ids', array(), (array) json_decode( (string) $response, true ) );
		$hidden_term_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $hidden_term_ids ) ) ) );

		wp_send_json_success(
			array(
				'data'            => $response,
				'hidden_term_ids' => $hidden_term_ids,
			),
			200
		);
	}
	public function get_terms() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/terms_get_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$args = array();
		if ( isset( $_REQUEST['ID'] ) ) {
			$args['ID'] = sanitize_text_field( wp_unslash( $_REQUEST['ID'] ) );
		}
		if ( isset( $_REQUEST['term_type'] ) ) {
			$args['term_type'] = sanitize_text_field( wp_unslash( $_REQUEST['term_type'] ) );
		}

		$results = $this->get_all_terms_data( $args );
		if ( $results ) {
			wp_send_json_success(
				$results,
				200
			);
		}
		wp_send_json_error(
			array(),
			200
		);
	}
	public function create_new_term() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		$args    = array(
			'ID'        => ( isset( $_REQUEST['ID'] ) ? absint( sanitize_text_field( wp_unslash( $_REQUEST['ID'] ) ) ) : 0 ),
			'term_name' => ( isset( $_REQUEST['term_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term_name'] ) ) : '' ),
			'term_slug' => ( isset( $_REQUEST['term_slug'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term_slug'] ) ) : '' ),
			'term_type' => ( isset( $_REQUEST['term_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term_type'] ) ) : '' ),
		);
		$results = $this->create_term( $args );
		wp_send_json_success(
			$results,
			200
		);
	}
	public function update_existing_term() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		$args = array(
			'cat_id'   => ( isset( $_REQUEST['ID'] ) ? absint( sanitize_text_field( wp_unslash( $_REQUEST['ID'] ) ) ) : 0 ),
			'cat_name' => ( isset( $_REQUEST['term_name'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term_name'] ) ) : '' ),
			'cat_slug' => ( isset( $_REQUEST['term_slug'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term_slug'] ) ) : '' ),
		);
		$this->update_term( $args );
		wp_send_json_success(
			array(
				'ID'        => $args['cat_id'],
				'term_name' => $args['cat_name'],
				'term_slug' => $args['cat_slug'],
			),
			200
		);
	}
	public function delete_existing_term() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		$args = array(
			'cat_id' => ( isset( $_REQUEST['cat_id'] ) ? absint( sanitize_text_field( wp_unslash( $_REQUEST['cat_id'] ) ) ) : 0 ),
			'tag_id' => ( isset( $_REQUEST['tag_id'] ) ? absint( sanitize_text_field( wp_unslash( $_REQUEST['tag_id'] ) ) ) : 0 ),
		);

		// Check if trying to delete the default 'Uncategorized' category (ID: 1)
		// This can come as either cat_id or tag_id parameter
		$term_id_to_delete = null;
		if ( isset( $args['cat_id'] ) && $args['cat_id'] > 0 ) {
			$term_id_to_delete = $args['cat_id'];
		} elseif ( isset( $args['tag_id'] ) && $args['tag_id'] > 0 ) {
			$term_id_to_delete = $args['tag_id'];
		}

		if ( $term_id_to_delete && ( $term_id_to_delete == 1 || $term_id_to_delete === '1' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Cannot delete the default "Uncategorized" category.', 'betterlinks' ),
					'term_id' => $term_id_to_delete,
				),
				403
			);
		}

		$this->delete_term( $args );
		wp_send_json_success(
			$args,
			200
		);
	}
	public function fetch_analytics() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/analytics_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$from = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to   = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : gmdate( 'Y-m-d' );
		$ID   = ( isset( $_REQUEST['ID'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ID'] ) ) : '' );
		$results = null;
		if ( ! empty( $ID ) ) {
			$args = array(
				'id'   => $ID,
				'from' => $from,
				'to'   => $to,
			);
			/**
			 * Filters per-link click analytics (null = not provided).
			 * BetterLinks Pro supplies them.
			 *
			 * @param array|null $results Rows.
			 * @param array      $args    { id, from, to }.
			 */
			$results = apply_filters( 'betterlinks/analytics/individual_link_clicks', null, $args );
			// Compatibility: BetterLinks Pro before 3.0.4.
			if ( null === $results && \BetterLinks\Helper::pro_needs_update() && is_callable( array( '\BetterLinksPro\Helper', 'get_individual_link_analytics' ) ) ) {
				$results = \BetterLinksPro\Helper::get_individual_link_analytics( $args );
			}
		}
		if ( null === $results ) {
			$results = $this->get_clicks_data( $from, $to );
		}
		wp_send_json_success(
			$results,
			200
		);
	}
	public function reset_analytics() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		// Deleting click history is a write. It used the analytics *read* permission, so
		// a role allowed only to view analytics could erase it. Administrators by default.
		if ( ! apply_filters( 'betterlinks/api/analytics_delete_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		global $wpdb;
		$prefix          = $wpdb->prefix;
		$days_older_than = isset( $_REQUEST['days_older_than'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['days_older_than'] ) ) : false;
		$from            = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to              = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : gmdate( 'Y-m-d' );
		$link_id         = isset( $_REQUEST['link_id'] ) ? intval( $_REQUEST['link_id'] ) : null;
		$query           = '';
		
		if ( $days_older_than !== false ) {
			// Legacy support for days_older_than parameter
			$range_days_in_seconds           = intval( $days_older_than ) * 24 * 60 * 60;
			$gmt_timestamp_of_the_range_time = time() - $range_days_in_seconds;
			if ( $link_id !== null ) {
				$query = "DELETE FROM {$prefix}betterlinks_clicks WHERE UNIX_TIMESTAMP(created_at_gmt) < %d AND link_id = %d";
				$query = $wpdb->prepare( $query, $gmt_timestamp_of_the_range_time, $link_id );
			} else {
				$query = "DELETE FROM {$prefix}betterlinks_clicks WHERE UNIX_TIMESTAMP(created_at_gmt) < %d";
				$query = $wpdb->prepare( $query, $gmt_timestamp_of_the_range_time );
			}
		} elseif ( !empty( $from ) && !empty( $to ) ) {
			// Use date range for deletion
			if ( $link_id !== null ) {
				$query = "DELETE FROM {$prefix}betterlinks_clicks WHERE DATE(created_at_gmt) >= %s AND DATE(created_at_gmt) <= %s AND link_id = %d";
				$query = $wpdb->prepare( $query, $from, $to, $link_id );
			} else {
				$query = "DELETE FROM {$prefix}betterlinks_clicks WHERE DATE(created_at_gmt) >= %s AND DATE(created_at_gmt) <= %s";
				$query = $wpdb->prepare( $query, $from, $to );
			}
		} else {
			// Delete all records as fallback
			if ( $link_id !== null ) {
				$query = "DELETE FROM {$prefix}betterlinks_clicks WHERE link_id = %d";
				$query = $wpdb->prepare( $query, $link_id );
			} else {
				$query = "DELETE FROM {$prefix}betterlinks_clicks";
			}
		}
		$count = $wpdb->query( $query );
		if ( $count === false ) {
			wp_send_json_error( $count );
		}
		Helper::clear_query_cache();
		Helper::clear_analytics_cache();
		Helper::update_links_analytics();
		$new_clicks_data = Helper::get_clicks_by_date( $from, $to );
		$new_links_data  = Helper::get_prepare_all_links();
		set_transient( BETTERLINKS_CACHE_LINKS_NAME, json_encode( $new_links_data ) );
		wp_send_json_success(
			array(
				'count'           => $count,
				'new_clicks_data' => $new_clicks_data,
				'new_links_data'  => $new_links_data,
			),
			200
		);
	}

	public function get_clicks_count() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/analytics_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_die( "You don't have permission to do this." );
		}
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$from    = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : gmdate( 'Y-m-d', strtotime( ' - 30 days' ) );
		$to      = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : gmdate( 'Y-m-d' );
		$link_id = isset( $_REQUEST['link_id'] ) ? intval( $_REQUEST['link_id'] ) : null;

		// Build count query similar to delete query
		if ( !empty( $from ) && !empty( $to ) ) {
			if ( $link_id !== null ) {
				$query = "SELECT COUNT(*) FROM {$prefix}betterlinks_clicks WHERE DATE(created_at_gmt) >= %s AND DATE(created_at_gmt) <= %s AND link_id = %d";
				$query = $wpdb->prepare( $query, $from, $to, $link_id );
			} else {
				$query = "SELECT COUNT(*) FROM {$prefix}betterlinks_clicks WHERE DATE(created_at_gmt) >= %s AND DATE(created_at_gmt) <= %s";
				$query = $wpdb->prepare( $query, $from, $to );
			}
		} else {
			// Count all records as fallback
			if ( $link_id !== null ) {
				$query = "SELECT COUNT(*) FROM {$prefix}betterlinks_clicks WHERE link_id = %d";
				$query = $wpdb->prepare( $query, $link_id );
			} else {
				$query = "SELECT COUNT(*) FROM {$prefix}betterlinks_clicks";
			}
		}

		$count = $wpdb->get_var( $query );
		if ( $count === false ) {
			wp_send_json_error( array( 'message' => 'Failed to get click count' ) );
		}

		wp_send_json_success(
			array(
				'count' => intval( $count ),
			),
			200
		);
	}

	/**
	 * These three feed the auto-link keyword UI and shipped with no nonce and
	 * no capability check at all — any logged-in user could enumerate them. The
	 * admin bundle already sends `betterlinks_admin_nonce` on every AJAX call,
	 * so adding the standard gate costs the caller nothing.
	 *
	 * @return void
	 */
	private function verify_settings_read_access() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! apply_filters( 'betterlinks/api/settings_get_items_permissions_check', current_user_can( 'manage_options' ) ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Insufficient permissions', 'betterlinks' ),
				),
				403
			);
		}
	}

	public function get_post_types() {
		$this->verify_settings_read_access();
		$post_types = get_post_types(['public' => true]);
		wp_send_json_success(
			$post_types,
			200
		);
	}
	public function get_post_tags() {
		$this->verify_settings_read_access();
		$tags = get_tags( array( 'get' => 'all' ) );
		$tags = wp_list_pluck( $tags, 'name', 'slug' );
		wp_send_json_success(
			$tags,
			200
		);
	}
	public function get_post_categories() {
		$this->verify_settings_read_access();
		$categories = get_categories(
			array(
				'orderby' => 'name',
			)
		);
		$categories = wp_list_pluck( $categories, 'name', 'slug' );
		wp_send_json_success(
			$categories,
			200
		);
	}

	public function set_affiliate_link_disclosure_post() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$ID    = ( isset( $_POST['ID'] ) ? intval( $_POST['ID'] ) : '' );
		$value = ( isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '' );

		update_post_meta( $ID, 'betterlinks_enable_affiliate_link_disclosure', $value );

		wp_send_json(
			array(
				'ID'    => $ID,
				'value' => $value,
			)
		);
	}

	public function get_affiliate_link_disclosure_post() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$ID        = ( isset( $_POST['ID'] ) ? intval( sanitize_text_field( wp_unslash( $_POST['ID'] ) ) ) : '' );
		$post_meta = get_post_meta( $ID, 'betterlinks_enable_affiliate_link_disclosure' );
		wp_send_json( $post_meta );
	}
	public function set_affiliate_link_disclosure_text() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$ID    = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$value = isset( $_POST['value'] ) ? wp_kses_post( wp_unslash( $_POST['value'] ) ) : '';

		$meta_key = 'betterlinks_enable_affiliate_link_disclosure_text';

		if ( ! empty( get_post_meta( $ID, $meta_key ) ) ) {
			update_post_meta( $ID, $meta_key, $value );
		} else {
			add_post_meta( $ID, $meta_key, $value );
		}

		// Register per-post disclosure text with WPML String Translation.
		$decoded_value = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( is_array( $decoded_value ) && ! empty( $decoded_value['affiliate_disclosure_text'] ) ) {
			do_action( 'wpml_register_single_string', 'BetterLinks', 'Affiliate Disclosure Text - Post ' . $ID, $decoded_value['affiliate_disclosure_text'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML public hook name; cannot be prefixed.
		}

		wp_send_json( $value );
	}

	public function get_affiliate_link_disclosure_text() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}

		$ID       = isset( $_POST['ID'] ) ? sanitize_text_field( wp_unslash( $_POST['ID'] ) ) : '';
		$meta_key = 'betterlinks_enable_affiliate_link_disclosure_text';

		$data           = array();
		$affiliate_text = get_post_meta( $ID, $meta_key );
		if ( count( $affiliate_text ) > 0 ) {
			$data = json_decode( html_entity_decode( $affiliate_text[0] ), true );
		}

		$settings                  = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );
		$affiliate_disclosure_text = ! empty( $settings['affiliate_disclosure_text'] ) ? $settings['affiliate_disclosure_text'] : '';
		$affiliate_link_position   = ! empty( $settings['affiliate_link_position'] ) ? sanitize_text_field( $settings['affiliate_link_position'] ) : '';

		wp_send_json(
			array(
				'affiliate_disclosure_text' => empty( $data['affiliate_disclosure_text'] ) ? $affiliate_disclosure_text : str_replace( ' rn ', '', $data['affiliate_disclosure_text'] ),
				'affiliate_link_position'   => empty( $data['affiliate_link_position'] ) ? $affiliate_link_position : $data['affiliate_link_position'],
			)
		);
	}

	public function get_auto_create_links_settings() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( apply_filters( 'betterlinkspro/admin/current_user_can_edit_settings', current_user_can( 'manage_options' ) ) ) {
			// The option name is defined by BetterLinks Pro; without Pro there is nothing to return.
			$data = defined( 'BETTERLINKS_PRO_AUTO_LINK_CREATE_OPTION_NAME' ) ? get_option( BETTERLINKS_PRO_AUTO_LINK_CREATE_OPTION_NAME, array() ) : array();
			if ( is_string( $data ) ) {
				$data = json_decode( $data, true );
			}
			wp_send_json_success( $data );
		}
		wp_die( "You don't have permission to do this." );
	}
	public function get_external_analytics() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( apply_filters( 'betterlinkspro/admin/current_user_can_edit_settings', current_user_can( 'manage_options' ) ) ) {
			$data = defined( 'BETTERLINKS_PRO_EXTERNAL_ANALYTICS_OPTION_NAME' ) ? get_option( BETTERLINKS_PRO_EXTERNAL_ANALYTICS_OPTION_NAME, array() ) : array();
			if ( is_string( $data ) ) {
				$data = json_decode( $data, true );
			}
			wp_send_json_success( $data );
		}
		wp_die( "You don't have permission to do this." );
	}

	public function client_consent() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$opt_in_value = isset( $_POST['opt_in_value'] ) ? sanitize_text_field( wp_unslash( $_POST['opt_in_value'] ) ) : 'no';
		$opt_in = PluginUsageTracker::get_instance( BETTERLINKS_PLUGIN_FILE, [
			'opt_in'       => true,
			'goodbye_form' => true,
			'item_id'      => '720bbe6537bffcb73f37',
		] );

		$opt_in_value = 'yes' === $opt_in_value ? 'yes' : 'no';
		$opt_in->opt_in( $opt_in_value, 'betterlinks' );

		// The Settings switch reuses this endpoint; only the setup wizard advances its step.
		$context = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : '';
		if ( 'settings' !== $context ) {
			update_option( 'betterlinks_quick_setup_step', 1 );
		}
		wp_send_json_success( [
			'result' => $opt_in_value,
		] );
	}

	public function complete_setup() {
		check_ajax_referer( 'betterlinks_admin_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( "You don't have permission to do this." );
		}
		$is_update = update_option('betterlinks_quick_setup_step', 'complete');
		wp_send_json_success([
			'result' => (bool) $is_update ? 'complete' : 'error' 
		]);
	}
	
	public function js_analytics_tracking() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public click-tracking beacon called from any frontend page; nonce is not feasible.
		global $wpdb;

		// Resolve the lookup column and its value independently. The previous
		// chained ternary made the target_url branch dead code: its own guard was
		// empty( $searchValue ), which is already false once target_url has been
		// assigned, so every target_url beacon fell through to '' and matched no
		// row. Keyword auto-links (which post target_url, not linkId) therefore
		// recorded no clicks at all.
		// is_scalar() before the sanitizers, not after: esc_url_raw()/sanitize_url()
		// pass their argument to ltrim(), which is a TypeError on an array, so a
		// POST of target_url[]=x or location[]=x was its own anonymous HTTP 500 —
		// a separate seam from the row-shape bug guarded below. absint() is milder
		// (intval( array ) is 1) but would silently bill the click to link 1.
		$target_url = ( isset( $_POST['target_url'] ) && is_scalar( $_POST['target_url'] ) ) ? sanitize_url( wp_unslash( $_POST['target_url'] ) ) : '';
		$link_id    = ( isset( $_POST['linkId'] ) && is_scalar( $_POST['linkId'] ) ) ? absint( wp_unslash( $_POST['linkId'] ) ) : 0;
		$location   = ( isset( $_POST['location'] ) && is_scalar( $_POST['location'] ) ) ? esc_url_raw( wp_unslash( $_POST['location'] ) ) : '';

		if ( '' !== $target_url ) {
			$query = $wpdb->prepare( "SELECT short_url FROM {$wpdb->prefix}betterlinks WHERE target_url = %s LIMIT 1", $target_url );
		} elseif ( $link_id > 0 ) {
			$query = $wpdb->prepare( "SELECT short_url FROM {$wpdb->prefix}betterlinks WHERE ID = %d LIMIT 1", $link_id );
		} else {
			wp_send_json( array( 'data' => false ) );
		}

		// This handler is reachable without a session (wp_ajax_nopriv_), so an
		// unresolvable link is an ordinary outcome — a deleted link, a stale
		// cached page, a hand-crafted request — not an error. Bail on the row
		// shape before reaching into it: current( null ) is a TypeError on PHP 8
		// and turned any anonymous POST with an unknown id into an HTTP 500.
		$row = $wpdb->get_row( $query, ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['short_url'] ) ) {
			wp_send_json( array( 'data' => false ) );
		}

		$utils = new Utils();
		$data  = $utils->get_slug_raw( $row['short_url'] );
		// get_slug_raw() returns null when neither an exact slug nor a wildcard
		// matches; without this the array writes below would build a bogus click.
		if ( ! is_array( $data ) ) {
			wp_send_json( array( 'data' => false ) );
		}
		$data['skip_password_protection'] = true;
		$data['location'] = $location;

		/**
		 * Filters link data for a front-end tracker click before it is recorded.
		 * BetterLinks Pro adds the visitor's country here.
		 *
		 * @param array $data Link data.
		 */
		$data = apply_filters( 'betterlinks/js_analytics_tracking/data', $data );

		Helper::init_tracking($data, $utils);

		wp_send_json([
			'data' => true
		]);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	


}
