<?php
namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\Cache;
use WP_Http;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\OperatingSystem;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use DeviceDetector\Parser\Client\Browser;

class Helper {

	use Traits\Query;
	use Traits\Clicks;

// phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

	public static function btl_menu_notice() {
		return BETTERLINKS_MENU_NOTICE !== get_option( 'betterlinks_menu_notice', 0 );
	}

	/**
	 * Whether the clicks table has a `bot_name` column.
	 *
	 * Part of the current schema and added by the migration, but an install that
	 * never ran the migration would fail the INSERT and silently lose the click,
	 * so the write is guarded by this. Lives on Helper rather than in a trait
	 * because both callers need it and they compose different traits: the insert
	 * path (Traits\Query, also used by LinkChecker) and the audience report
	 * (Traits\Clicks, also used by the REST controller).
	 *
	 * Cached like the user-agent check so the redirect path never hits
	 * information_schema.
	 *
	 * @return bool
	 */
	public static function has_bot_name_column() {
		global $wpdb;

		$transient_key = 'betterlinks_bot_name_column_exists';
		$column_exists = get_transient( $transient_key );

		if ( $column_exists === false ) {
			$column_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT `column_name` FROM information_schema.columns WHERE table_schema=%s AND table_name=%s AND column_name="bot_name"',
					DB_NAME,
					$wpdb->prefix . 'betterlinks_clicks'
				)
			);

			// Normalise before the comparison below: the lookup returns the column
			// NAME, so returning `$column_exists === 'yes'` directly would report
			// false on the very first call after the cache expires and only start
			// working once the cached value is read back.
			$column_exists = $column_exists ? 'yes' : 'no';
			set_transient( $transient_key, $column_exists, HOUR_IN_SECONDS );
		}

		return $column_exists === 'yes';
	}
	public static function get_links() {
		if ( BETTERLINKS_EXISTS_LINKS_JSON ) {
			$data = json_decode( file_get_contents( BETTERLINKS_UPLOAD_DIR_PATH . '/links.json' ), true );
			if ( empty( $data ) ) {
				$cron = new Cron();
				$cron->write_json_links();
				return json_decode( file_get_contents( BETTERLINKS_UPLOAD_DIR_PATH . '/links.json' ), true );
			}
			return $data;
		}
		$options = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );
		return is_array( $options )
				? array(
					'wildcards_is_active'         => isset( $options['wildcards'] ) ? $options['wildcards'] : false,
					'disablebotclicks'            => isset( $options['disablebotclicks'] ) ? $options['disablebotclicks'] : false,
					'force_https'                 => isset( $options['force_https'] ) ? $options['force_https'] : false,
					'autolink_disable_post_types' => isset( $options['autolink_disable_post_types'] ) ? $options['autolink_disable_post_types'] : array(),
					'is_autolink_icon'            => isset( $options['is_autolink_icon'] ) ? $options['is_autolink_icon'] : false,
					'is_autolink_headings'        => isset( $options['is_autolink_headings'] ) ? $options['is_autolink_headings'] : false,
					'uncloaked_categories'        => isset( $options['uncloaked_categories'] ) ? $options['uncloaked_categories'] : array(),
					'is_disable_analytics_ip'     => isset( $options['is_disable_analytics_ip'] ) ? $options['is_disable_analytics_ip'] : false,
				)
				: array(
					'wildcards_is_active' => false,
					'disablebotclicks'    => false,
					'force_https'         => false,
				);
	}

	public static function get_link_from_json_file( $short_url ) {
		if ( empty( $short_url ) ) {
			return;
		}
		global $betterlinks;
		if ( ! ( isset( $betterlinks['is_case_sensitive'] ) && $betterlinks['is_case_sensitive'] ) ) {
			$short_url = strtolower( $short_url );
		}
		if ( isset( $betterlinks['links'][ $short_url ] ) ) {
			return $betterlinks['links'][ $short_url ];
		}
		if ( isset( $betterlinks['wildcards_is_active'] ) && $betterlinks['wildcards_is_active'] ) {
			if ( isset( $betterlinks['wildcards'] ) && count( $betterlinks['wildcards'] ) > 0 ) {
				foreach ( $betterlinks['wildcards'] as $key => $item ) {
					$postion = strpos( $key, '/*' );
					if ( false !== $postion ) {
						if ( substr( $key, 0, $postion ) === substr( $short_url, 0, $postion ) ) {
							$target_postion = strpos( $item['target_url'], '/*' );
							if ( false !== $target_postion ) {
								$target_url         = str_replace( '/*', substr( $short_url, $postion ), $item['target_url'] );
								$item['target_url'] = $target_url;
								return $item;
							}
							return $item;
						}
					}
				}
			}
		}
	}

	/**
	 * Whether the Promo Cards screen should be reachable.
	 *
	 * Free users always get it — the page is an upgrade teaser, so hiding it
	 * would defeat the point. Only Pro users can switch it off, via the
	 * "Feature Modules" settings card. The key is missing on installs that
	 * predate it, which counts as enabled.
	 *
	 * @return bool
	 */
	public static function is_promo_cards_enabled() {
		if ( ! self::is_pro_active() ) {
			return true;
		}

		$settings = Cache::get_json_settings();

		return ! isset( $settings['enable_promo_cards'] ) || ! empty( $settings['enable_promo_cards'] );
	}

	/**
	 * Whether the "Bio Links" submenu is enabled.
	 *
	 * Pro-only opt-out, same contract as is_promo_cards_enabled(): in free the
	 * screen is the upgrade teaser, so it always stays reachable and only Pro
	 * can hide it from the "Feature Modules" settings card. The key is missing
	 * on installs that predate it, which counts as enabled.
	 *
	 * @return bool
	 */
	public static function is_bio_links_enabled() {
		if ( ! self::is_pro_active() ) {
			return true;
		}

		$settings = Cache::get_json_settings();

		return ! isset( $settings['enable_bio_links'] ) || ! empty( $settings['enable_bio_links'] );
	}

	public static function get_menu_items() {
		// $enable_custom_domain_menu = get_option(BETTERLINKS_CUSTOM_DOMAIN_MENU, 0);
		$enable_custom_domain_menu = Cache::get_json_settings();
		$enable_custom_domain_menu = !empty( $enable_custom_domain_menu['enable_custom_domain_menu'] ) ?  $enable_custom_domain_menu['enable_custom_domain_menu'] : false;
		
		// Built in display order — do NOT go back to splicing conditional entries
		// into an existing array. That is what previously put Bio Links and Promo
		// Cards above Tags & Categories: every insert used index 1, so each new
		// one landed directly after "Manage Links" and they stacked in reverse.
		$menu_items = array(
			BETTERLINKS_PLUGIN_SLUG                  => array(
				'title'      => __( 'Manage Links', 'betterlinks' ),
				'capability' => 'manage_options',
			),
			BETTERLINKS_PLUGIN_SLUG . '-manage-tags-and-categories' => array(
				'title'      => __( 'Tags & Categories', 'betterlinks' ),
				'capability' => 'manage_options',
			),
		);

		if ( ! empty( $enable_custom_domain_menu ) ) {
			$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-custom-domain' ] = array(
				'title'      => __( 'Custom Domain', 'betterlinks' ),
				'capability' => 'manage_options',
			);
		}

		// Free users reach the teaser through this same slug, so the submenu has
		// to exist here too — otherwise WordPress rejects the page load before
		// the React router ever sees it.
		if ( self::is_promo_cards_enabled() ) {
			$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-promo-cards' ] = array(
				'title'      => __( 'Promo Cards', 'betterlinks' ),
				'capability' => 'manage_options',
			);
		}

		// Same reasoning as Promo Cards above — free users reach the Bio Links
		// teaser through this slug, so it must be registered here as well.
		if ( self::is_bio_links_enabled() ) {
			$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-bio-links' ] = array(
				'title'      => __( 'Bio Links', 'betterlinks' ),
				'capability' => 'manage_options',
			);
		}

		$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-analytics' ]    = array(
			'title'      => __( 'Analytics', 'betterlinks' ),
			'capability' => 'manage_options',
		);
		$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-link-scanner' ] = array(
			'title'      => __( 'Link Scanner', 'betterlinks' ),
			'capability' => 'manage_options',
		);
		$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-mcp' ]          = array(
			'title'      => __( 'MCP', 'betterlinks' ),
			'capability' => 'manage_options',
		);
		$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-settings' ]     = array(
			'title'      => __( 'Settings', 'betterlinks' ),
			'capability' => 'manage_options',
		);

		if ( get_option( 'betterlinks_quick_setup_step' ) !== 'complete' ) {
			$menu_items[ BETTERLINKS_PLUGIN_SLUG . '-quick-setup' ] = array(
				'title'      => __( 'Quick Setup', 'betterlinks' ),
				'capability' => 'manage_options',
			);
		}

		$menu_items = apply_filters( 'betterlinks/helper/menu_items', $menu_items );

		// Pro registers the same slug through the filter above, so the opt-out
		// has to be re-applied afterwards to actually take effect.
		if ( ! self::is_promo_cards_enabled() ) {
			unset( $menu_items[ BETTERLINKS_PLUGIN_SLUG . '-promo-cards' ] );
		}
		if ( ! self::is_bio_links_enabled() ) {
			unset( $menu_items[ BETTERLINKS_PLUGIN_SLUG . '-bio-links' ] );
		}

		return $menu_items;
	}

	/**
	 * Check Supported Post type for admin page and plugin main settings page
	 *
	 * @return bool
	 */
	public static function plugin_page_hook_suffix( $hook ) {
		if ( 'toplevel_page_' . BETTERLINKS_PLUGIN_SLUG === $hook ) {
			return true;
		} else {
			foreach ( self::get_menu_items() as $key => $value ) {
				if ( BETTERLINKS_PLUGIN_SLUG . '_page_' . $key === $hook || strpos( $hook, BETTERLINKS_PLUGIN_SLUG . '_page_' . $key ) || strpos( '_' . $hook, 'betterlinks' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function make_slug( $str ) {
		if ( empty( $str ) ) {
			return;
		}
		if ( $str !== mb_convert_encoding( mb_convert_encoding( $str, 'UTF-32', 'UTF-8' ), 'UTF-8', 'UTF-32' ) ) {
			$str = mb_convert_encoding( $str, 'UTF-8', mb_detect_encoding( $str ) );
		}
		$str = htmlentities( $str, ENT_NOQUOTES, 'UTF-8' );
		$str = preg_replace( '`&([a-z]{1,2})(acute|uml|circ|grave|ring|cedil|slash|tilde|caron|lig);`i', '\\1', $str );
		$str = html_entity_decode( $str, ENT_NOQUOTES, 'UTF-8' );
		$str = preg_replace( array( '`[^a-z0-9]`i', '`[-]+`' ), '-', $str );
		$str = strtolower( trim( $str, '-' ) );
		$str = substr( $str, 0, 100 );
		return $str;
	}

	public static function link_exists( $title, $slug = '' ) {
		global $wpdb;

		$link_title  = wp_unslash( sanitize_post_field( 'link_title', $title, 0, 'db' ) );
		$short_url   = wp_unslash( sanitize_post_field( 'short_url', $slug, 0, 'db' ) );
		$betterlinks = $wpdb->prefix . 'betterlinks';
		$query       = "SELECT link_title, short_url FROM  $betterlinks WHERE ";
		$args        = array();

		if ( ! empty( $title ) ) {
			$query .= ' link_title = %s';
			$args[] = $link_title;
		}

		if ( ! empty( $slug ) ) {
			$query .= ' AND short_url = %s';
			$args[] = $short_url;
		}

		if ( ! empty( $args ) ) {
			$results = $wpdb->get_var( $wpdb->prepare( $query, $args ) );
			if ( ! empty( $results ) ) {
				return true;
			}
			return;
		}
	}
	public static function term_exists( $slug ) {
		global $wpdb;

		$term_slug   = wp_unslash( sanitize_post_field( 'term_slug', $slug, 0, 'db' ) );
		$betterlinks = $wpdb->prefix . 'betterlinks_terms';
		$query       = "SELECT term_slug FROM  $betterlinks WHERE ";
		$args        = array();

		if ( ! empty( $slug ) ) {
			$query .= ' term_slug = %s';
			$args[] = $term_slug;
		}

		if ( ! empty( $args ) ) {
			$results = $wpdb->get_var( $wpdb->prepare( $query, $args ) );
			if ( ! empty( $results ) ) {
				return true;
			}
			return;
		}
	}
	public static function click_exists( $ID ) {
		global $wpdb;
		$click_ID    = wp_unslash( sanitize_post_field( 'ID', $ID, 0, 'db' ) );
		$betterlinks = $wpdb->prefix . 'betterlinks_clicks';
		$query       = "SELECT ID FROM  $betterlinks WHERE ";
		$args        = array();

		if ( ! empty( $click_ID ) ) {
			$query .= ' ID = %d';
			$args[] = $click_ID;
		}

		if ( ! empty( $args ) ) {
			$results = $wpdb->get_var( $wpdb->prepare( $query, $args ) );
			if ( ! empty( $results ) ) {
				return true;
			}
			return;
		}
	}

	public static function create_cron_jobs_for_json_links() {
		wp_clear_scheduled_hook( 'betterlinks/write_json_links' );
		wp_schedule_single_event( time() + 5, 'betterlinks/write_json_links' );
	}

	public static function write_links_inside_json() {
		$cron = new Cron();
		$cron->write_json_links();
	}

	public static function create_cron_jobs_for_analytics() {
		wp_clear_scheduled_hook( 'betterlinks/analytics' );
		wp_schedule_single_event( time() + 5, 'betterlinks/analytics' );
	}

	public static function clear_query_cache() {
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
	}

	public static function create_links_cache() {
		$results = self::get_prepare_all_links();
		set_transient( BETTERLINKS_CACHE_LINKS_NAME, json_encode( $results ) );
	}

	public static function parse_link_response( $items, $analytic, $broken_links ) {
		$results                  = array();
		$broken_link_status_codes = array( 401, 403, 404 );

		$tags_list = array();

		foreach ( $items as $item ) {
			if ( null !== $item->ID && 'tags' === $item->term_type ) {
				array_push( $tags_list, $item );
			}
		}

		foreach ( $items as $item ) {
			if ( 'category' === $item->term_type ) {
				if ( null === $item->ID ) {
					continue;
				}
				// insert analytic data.
				if ( isset( $analytic[ $item->ID ] ) ) {
					$item->analytic = $analytic[ $item->ID ];
				}
				if ( ! empty( $item->param_struct ) ) {
					$item->param_struct = unserialize( $item->param_struct, array( 'allowed_classes' => false ) );
				}
				// Compatibility: BetterLinks Pro before 3.0.4 needs custom tracking scripts
				// and broken-link status filled in here, or saving a link from the
				// dashboard would clear its stored scripts. Newer Pro uses the
				// betterlinks/admin/link_item filter below.
				if ( self::pro_needs_update() ) {
					$custom_tracking_scripts = self::get_link_meta( $item->ID, 'btl_custom_tracking_scripts' );
					if ( ! empty( $custom_tracking_scripts ) ) {
						$custom_tracking_scripts       = unserialize( $custom_tracking_scripts, array( 'allowed_classes' => false ) );
						$item->enable_custom_scripts   = isset( $custom_tracking_scripts['enable'] ) ? $custom_tracking_scripts['enable'] : false;
						$item->custom_tracking_scripts = isset( $custom_tracking_scripts['script'] ) ? $custom_tracking_scripts['script'] : '';
					}
				}

				if ( ! empty( $broken_links ) && isset( $broken_links[ $item->ID ] ) && is_array( $broken_links[ $item->ID ] ) && isset( $broken_links[ $item->ID ]['status']['status_code'] ) && in_array( $broken_links[ $item->ID ]['status']['status_code'], $broken_link_status_codes ) && empty( $broken_links[ $item->ID ]['is_log_removed'] ) ) {
					$item->link_status = 'broken';
				} elseif ( 'broken' === $item->link_status && isset( $broken_links[ $item->ID ] ) && is_array( $broken_links[ $item->ID ] ) && isset( $broken_links[ $item->ID ]['old_link_status'] ) && 'broken' !== $broken_links[ $item->ID ]['old_link_status'] ) {
					// if the link is fixed, but if db is not updated it to fixed link immediately then it will be marked as old status code.
					$item->link_status = $broken_links[ $item->ID ]['old_link_status'];
				}
				/**
				 * Filters a link item returned to the admin dashboard. BetterLinks Pro
				 * adds custom tracking scripts and broken-link status.
				 *
				 * @param object $item Link item.
				 */
				$item = apply_filters( 'betterlinks/admin/link_item', $item );
				$item->tags_data = array();
				$item->tags_id = array(); // Initialize tags_id array for form submission

				foreach ( $tags_list as $tag ) {
					if ( $tag->ID === $item->ID ) {
						array_push(
							$item->tags_data,
							array(
								'term_id'   => $tag->cat_id,
								'term_name' => $tag->term_name,
								'term_slug' => $tag->term_slug,
							)
						);
						// Also add to tags_id array for form submission
						array_push( $item->tags_id, $tag->cat_id );
					}
				}

				// formatting response.
				if ( ! isset( $results[ $item->cat_id ] ) ) {
					$results[ $item->cat_id ] = array(
						'term_name' => $item->term_name,
						'term_slug' => $item->term_slug,
						'term_type' => $item->term_type,
					);
					if ( null !== $item->ID ) {
						$results[ $item->cat_id ]['lists'][] = $item;
					} else {
						$results[ $item->cat_id ]['lists'] = array();
					}
				} else {
					$results[ $item->cat_id ]['lists'][] = $item;
				}
			}
		}
		return $results;
	}
	public static function json_link_formatter( $data ) {
		$res = array(
			'ID'               => $data['ID'] ?? null,
			'link_slug'        => $data['link_slug'] ?? '',
			'link_status'      => ( isset( $data['link_status'] ) ? $data['link_status'] : 'publish' ),
			'short_url'        => $data['short_url'] ?? '',
			'redirect_type'    => ( isset( $data['redirect_type'] ) ? $data['redirect_type'] : '307' ),
			'target_url'       => $data['target_url'] ?? '',
			'nofollow'         => ( isset( $data['nofollow'] ) ? $data['nofollow'] : false ),
			'sponsored'        => ( isset( $data['sponsored'] ) ? $data['sponsored'] : false ),
			'param_forwarding' => ( isset( $data['param_forwarding'] ) ? $data['param_forwarding'] : false ),
			'track_me'         => ( isset( $data['track_me'] ) ? $data['track_me'] : false ),
			'wildcards'        => ( isset( $data['wildcards'] ) ? $data['wildcards'] : false ),
			'expire'           => ( isset( $data['expire'] ) ? $data['expire'] : null ),
			'dynamic_redirect' => ( isset( $data['dynamic_redirect'] ) ? $data['dynamic_redirect'] : null ),
			'cat_id'           => isset( $data['cat_id'] ) ? $data['cat_id'] : null,
		);
		if ( isset( $data['uncloaked'] ) && $data['uncloaked'] ) {
			$res['uncloaked'] = $data['uncloaked'];
		}
		return $res;
	}
	public static function insert_json_into_file( $file, $data ) {
		$existingData              = file_get_contents( $file );
		$existingData              = json_decode( $existingData, true );
		// An unreadable or empty links.json decodes to null, and a site that has
		// never saved a wildcard has no 'wildcards' key at all — reading either
		// one raised PHP warnings on the first wildcard link saved.
		if ( ! is_array( $existingData ) ) {
			$existingData = array();
		}
		$case_sensitive_is_enabled = isset( $existingData['is_case_sensitive'] ) ? $existingData['is_case_sensitive'] : false;
		$short_url                 = $case_sensitive_is_enabled ? $data['short_url'] : strtolower( $data['short_url'] );
		if ( isset( $data['wildcards'] ) && $data['wildcards'] ) {
			$tempArray = ( isset( $existingData['wildcards'] ) && is_array( $existingData['wildcards'] ) ) ? $existingData['wildcards'] : array();
			// Remove any existing entry with the same ID to prevent duplicates
			if ( isset( $data['ID'] ) ) {
				foreach ( $tempArray as $key => $entry ) {
					if ( isset( $entry['ID'] ) && $entry['ID'] == $data['ID'] ) {
						unset( $tempArray[ $key ] );
						break;
					}
				}
			}
			$tempArray[ $short_url ]   = self::json_link_formatter( $data );
			$existingData['wildcards'] = $tempArray;
		} else {
			$tempArray = ( isset( $existingData['links'] ) && is_array( $existingData['links'] ) ) ? $existingData['links'] : array();
			// Remove any existing entry with the same ID to prevent duplicates
			if ( isset( $data['ID'] ) ) {
				foreach ( $tempArray as $key => $entry ) {
					if ( isset( $entry['ID'] ) && $entry['ID'] == $data['ID'] ) {
						unset( $tempArray[ $key ] );
						break;
					}
				}
			}
			$tempArray[ $short_url ] = self::json_link_formatter( $data );
			$existingData['links']   = $tempArray;
		}
		return file_put_contents( $file, wp_json_encode( $existingData ) );
	}
	public static function update_json_into_file( $file, $data, $old_short_url = '' ) {
		if ( ! isset( $data['short_url'] ) ) {
			return false;
		}
		$existingData              = file_get_contents( $file );
		$existingData              = json_decode( $existingData, true );
		// make sure we always have an array to work with
		if ( ! is_array( $existingData ) ) {
			$existingData = array();
		}
        $case_sensitive_is_enabled = isset( $existingData['is_case_sensitive'] ) ? $existingData['is_case_sensitive'] : false;
        $short_url                 = $case_sensitive_is_enabled ? $data['short_url'] : strtolower( $data['short_url'] );

        if ( isset( $data['wildcards'] ) && ! empty( $data['wildcards'] ) ) {
            $tempArray = isset( $existingData['wildcards'] ) && is_array( $existingData['wildcards'] ) ? $existingData['wildcards'] : array();
             if ( is_array( $tempArray ) ) {
                 $found_old_entry = false;

                // First, try to find and remove by old_short_url if provided
                if ( ! empty( $old_short_url ) ) {
                    $old_short_url_lower = strtolower( $old_short_url );
                    if ( isset( $tempArray[ $old_short_url ] ) ) {
                        unset( $tempArray[ $old_short_url ] );
                        $found_old_entry = true;
                    } elseif ( isset( $tempArray[ $old_short_url_lower ] ) ) {
                        unset( $tempArray[ $old_short_url_lower ] );
                        $found_old_entry = true;
                    }
                }

                // If old entry not found by short_url, search by ID to remove all duplicates
                if ( ! $found_old_entry && isset( $data['ID'] ) ) {
                    foreach ( $tempArray as $key => $entry ) {
                        if ( isset( $entry['ID'] ) && $entry['ID'] == $data['ID'] ) {
                            unset( $tempArray[ $key ] );
                        }
                    }
                }

                $tempArray[ $short_url ]   = self::json_link_formatter( $data );
                $existingData['wildcards'] = $tempArray;
                return file_put_contents( $file, wp_json_encode( $existingData ) );
            }
        } else {
            $tempArray     = isset( $existingData['links'] ) && is_array( $existingData['links'] ) ? $existingData['links'] : array();
             $previous_data = array();
             if ( is_array( $tempArray ) ) {
                 $found_old_entry = false;

                // First, try to find and remove by old_short_url if provided
                if ( ! empty( $old_short_url ) ) {
                    $old_short_url_lower = strtolower( $old_short_url );
                    if ( isset( $tempArray[ $old_short_url ] ) ) {
                        $previous_data = $tempArray[ $old_short_url ];
                        unset( $tempArray[ $old_short_url ] );
                        $found_old_entry = true;
                    } elseif ( isset( $tempArray[ $old_short_url_lower ] ) ) {
                        $previous_data = $tempArray[ $old_short_url_lower ];
                        unset( $tempArray[ $old_short_url_lower ] );
                        $found_old_entry = true;
                    }
                }

                // If old entry not found by short_url, search by ID to remove all duplicates
                if ( ! $found_old_entry && isset( $data['ID'] ) ) {
                    foreach ( $tempArray as $key => $entry ) {
                        if ( isset( $entry['ID'] ) && $entry['ID'] == $data['ID'] ) {
                            $previous_data = $entry;
                            unset( $tempArray[ $key ] );
                        }
                    }
                }

                $data                    = wp_parse_args( $data, $previous_data );
                $tempArray[ $short_url ] = self::json_link_formatter( $data );
                $existingData['links']   = $tempArray;
                return file_put_contents( $file, wp_json_encode( $existingData ) );
            }
        }
	}
	public static function delete_json_into_file( $file, $short_url ) {
		if ( ! is_string( $short_url ) || '' === $short_url ) {
			return;
		}
		$existingData = file_get_contents( $file );
		$existingData = json_decode( $existingData, true );
		if ( ! is_array( $existingData ) ) {
			$existingData = array();
		}
		if ( isset( $existingData['wildcards'][ $short_url ] ) || isset( $existingData['wildcards'][ strToLower( $short_url ) ] ) ) {
			$tempArray = $existingData['wildcards'];
			if ( is_array( $tempArray ) ) {
				unset( $tempArray[ $short_url ] );
				unset( $tempArray[ strToLower( $short_url ) ] );
				$existingData['wildcards'] = $tempArray;
				return file_put_contents( $file, wp_json_encode( $existingData ) );
			}
		} elseif ( isset( $existingData['links'][ $short_url ] ) || isset( $existingData['links'][ strtolower( $short_url ) ] ) ) {
			$tempArray = $existingData['links'];
			if ( is_array( $tempArray ) ) {
				unset( $tempArray[ $short_url ] );
				unset( $tempArray[ strtolower( $short_url ) ] );
				$existingData['links'] = $tempArray;
				return file_put_contents( $file, wp_json_encode( $existingData ) );
			}
		}
		return;
	}

	public static function is_exists_short_url( $short_url ) {
		$resutls = self::get_link_by_short_url( $short_url );
		if ( count( $resutls ) > 0 ) {
			return true;
		}
		return false;
	}

	/**
	 * Return a WP_Error when a proposed BetterLinks short_url would shadow a URL
	 * WordPress already serves. Returns null when the path is free.
	 *
	 * BetterLinks' redirect handler runs at `init` priority 0 — before WP
	 * resolves the request — so a short_url that matches a real URL silently
	 * hijacks it. This check can surface the conflict at write time.
	 *
	 * Disabled by default for WordPress *content*, so links behave as they
	 * always have (any path can be redirected); sites wanting the stricter
	 * behaviour opt in via `betterlinks/enable_wp_url_collision_check`.
	 * Reserved WordPress system paths are always refused.
	 *
	 * @param string $short_url       Proposed short_url (prefix included).
	 * @param int    $allowed_post_id Post whose own permalink may be shadowed on
	 *                                purpose — see the exemption below. 0 for none.
	 * @return \WP_Error|null
	 */
	public static function check_wp_url_collision( $short_url, $allowed_post_id = 0 ) {
		$short = trim( (string) $short_url, "/ \t\n\r\0\x0B" );
		if ( '' === $short ) {
			return null;
		}
		// WordPress system paths are never available, even with the escape hatch
		// below: a short link there would take over login, admin or the REST API.
		if ( self::is_reserved_wp_path( $short ) ) {
			return new \WP_Error(
				'betterlinks_wp_url_collision',
				sprintf(
					/* translators: %s: proposed short URL */
					__( 'Cannot save short URL "%s" because it points at a WordPress system path (such as wp-login.php, wp-admin or the REST API). Pick a different slug.', 'betterlinks' ),
					$short
				),
				array(
					'status'              => 409,
					'conflict_type'       => 'reserved',
					'conflict_label'      => __( 'system path', 'betterlinks' ),
					'conflicting_post_id' => 0,
					'overridable'         => false,
					'short_message'       => __( 'This path is reserved by WordPress', 'betterlinks' ),
				)
			);
		}
		/**
		 * Filter — return true to turn the WP-content collision check on.
		 *
		 * Off by default. Redirecting a path WordPress already serves is a core
		 * BetterLinks use case (a retired page to its replacement, a docs or
		 * knowledge-base archive to its welcome article…), and BetterLinks has
		 * always accepted those links, so blocking them broke existing sites.
		 * The reserved-system-path check above still applies either way.
		 *
		 * @param bool   $enable Whether to run the check. Default false.
		 * @param string $short  Proposed short URL.
		 */
		if ( ! apply_filters( 'betterlinks/enable_wp_url_collision_check', false, $short ) ) {
			return null;
		}
		/**
		 * Filter — return true to skip the WP URL collision check entirely.
		 * Kept for sites that already use it; only matters once the check has
		 * been enabled via `betterlinks/enable_wp_url_collision_check`.
		 *
		 * @param bool   $skip  Whether to skip the check. Default false.
		 * @param string $short Proposed short URL.
		 */
		if ( apply_filters( 'betterlinks/skip_wp_url_collision_check', false, $short ) ) {
			return null;
		}
		$allowed_post_id = absint( $allowed_post_id );
		$conflict        = null;
		foreach ( self::short_url_match_candidates( $short ) as $candidate ) {
			// Instant Redirect binds a post to its *own* permalink on purpose —
			// "make this page redirect somewhere else" is the entire feature. The
			// content being shadowed is the post the author is editing, so this is
			// a deliberate override, not the silent hijack this check exists to
			// catch. Only that one post's canonical path is exempted; a slug
			// aimed at any other page or archive is still rejected.
			if ( $allowed_post_id > 0 && self::is_canonical_post_path( $allowed_post_id, $candidate ) ) {
				return null;
			}
			$conflict = self::resolve_wp_url_conflict( $candidate );
			if ( null !== $conflict ) {
				break;
			}
		}
		if ( null === $conflict ) {
			return null;
		}
		return new \WP_Error(
			'betterlinks_wp_url_collision',
			sprintf(
				/* translators: 1: proposed short URL, 2: what WordPress already serves there, e.g. "page" or "category archive" */
				__( 'Cannot save short URL "%1$s" because WordPress already serves a %2$s at that path, and the link would make it unreachable. Pick a different slug, or remove the conflicting content.', 'betterlinks' ),
				$short,
				$conflict['label']
			),
			array(
				'status'              => 409,
				'conflict_type'       => $conflict['type'],
				'conflict_label'      => $conflict['label'],
				'conflicting_post_id' => $conflict['post_id'],
				// Unlike a system path, this can be a deliberate redirect (a docs
				// archive sent to its welcome article, say), so the link form lets
				// the user confirm it — see Traits\Links::can_override_wp_url_collision().
				'overridable'         => true,
				// Short enough to sit under the slug field in the link form; the
				// full message above is for toasts and API consumers.
				'short_message'       => sprintf(
					/* translators: %s: what WordPress already serves there, e.g. "page" or "Category archive" */
					__( 'A WordPress %s already lives at this path', 'betterlinks' ),
					$conflict['label']
				),
			)
		);
	}

	/**
	 * Every path a stored short_url will actually answer on.
	 *
	 * Unless an install opts into case-sensitive matching, the redirect handler
	 * lowercases the incoming request before looking it up, so a link saved as
	 * "Pricing" captures "/pricing" as well — and checking only the literal
	 * spelling would let that straight past the collision check.
	 *
	 * @param string $short Proposed short_url, already trimmed of slashes.
	 * @return string[]
	 */
	protected static function short_url_match_candidates( $short ) {
		$candidates = array( $short );
		$options    = json_decode( (string) get_option( BETTERLINKS_LINKS_OPTION_NAME, '{}' ), true );
		$sensitive  = is_array( $options ) && ! empty( $options['is_case_sensitive'] );
		if ( ! $sensitive ) {
			$lowered = strtolower( $short );
			if ( $lowered !== $short ) {
				$candidates[] = $lowered;
			}
		}
		return $candidates;
	}

	/**
	 * Whether a path belongs to WordPress itself: admin, login, REST API, cron,
	 * XML-RPC or the core file directories. A short link at such a path would take
	 * over a core entry point for every visitor.
	 *
	 * @param string $path Path relative to the site root.
	 * @return bool
	 */
	public static function is_reserved_wp_path( $path ) {
		$path = strtolower( trim( (string) $path, "/ \t\n\r\0\x0B" ) );
		if ( '' === $path ) {
			return false;
		}
		$first    = (string) strtok( $path, '/?#' );
		$reserved = array(
			'wp-admin',
			'wp-content',
			'wp-includes',
			'wp-login.php',
			'wp-signup.php',
			'wp-activate.php',
			'wp-cron.php',
			'wp-comments-post.php',
			'wp-trackback.php',
			'wp-mail.php',
			'wp-links-opml.php',
			'wp-load.php',
			'xmlrpc.php',
			function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json',
		);
		/**
		 * Filters the first path segments that short links may never use.
		 *
		 * @param string[] $reserved Lower-case first path segments.
		 */
		$reserved = array_map( 'strtolower', (array) apply_filters( 'betterlinks/reserved_wp_paths', $reserved ) );

		return in_array( $first, $reserved, true );
	}

	/**
	 * Describe whatever WordPress would serve at `$path`, or null when nothing
	 * is there.
	 *
	 * `url_to_postid()` only ever answers for singular content, so it misses the
	 * archives the original report explicitly called out — a category-based
	 * permalink whose base collides with the link prefix, a custom post type
	 * archive, an author or date archive. Those are resolved below by running
	 * the path through the same rewrite rules WordPress itself routes with.
	 *
	 * @param string $path Path relative to the site root, no leading slash.
	 * @return array{type:string,label:string,post_id:int}|null
	 */
	protected static function resolve_wp_url_conflict( $path ) {
		$post_id = url_to_postid( trailingslashit( home_url() ) . $path );
		if ( $post_id > 0 && self::is_canonical_post_path( $post_id, $path ) ) {
			$post = get_post( $post_id );
			return array(
				'type'    => 'post',
				'label'   => $post instanceof \WP_Post ? $post->post_type : 'post',
				'post_id' => $post_id,
			);
		}

		global $wp_rewrite;
		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return null;
		}
		$rules = $wp_rewrite->wp_rewrite_rules();
		if ( empty( $rules ) ) {
			// Plain permalinks: every URL but the front page is a query string,
			// so no path can be shadowed.
			return null;
		}

		$path = ltrim( $path, '/' );
		foreach ( $rules as $match => $query ) {
			if ( ! preg_match( "#^$match#", $path, $matches ) ) {
				continue;
			}
			$query = preg_replace( '!^.+\?!', '', $query );
			$query = addslashes( \WP_MatchesMapRegex::apply( $query, $matches ) );
			$vars  = array();
			parse_str( $query, $vars );
			$conflict = self::describe_query_var_conflict( $vars );
			if ( null !== $conflict ) {
				return $conflict;
			}
			// The rule matched but resolves to nothing a visitor can reach (a
			// verbose page rule for a page that is gone, an empty date archive).
			// WordPress keeps walking the rule table in that case, so do the same.
		}
		return null;
	}

	/**
	 * Whether `$path` is a post's own permalink rather than a variant of it.
	 *
	 * With `%postname%` permalinks `url_to_postid()` answers for paginated forms
	 * too — `/go/2019/` resolves to the page `/go/` as "page 2019 of it", and
	 * WordPress serves it as a 301 back to `/go/`. Nothing becomes unreachable
	 * if a link takes that path over, so treating it as a collision would only
	 * block slugs that are in practice free (every `<page>/<digits>` under a
	 * link prefix that happens to also be a page).
	 *
	 * @param int    $post_id
	 * @param string $path Path relative to the site root, no leading slash.
	 * @return bool
	 */
	protected static function is_canonical_post_path( $post_id, $path ) {
		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return false;
		}
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$post_path = trim( (string) wp_parse_url( $permalink, PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && 0 === strpos( $post_path, $home_path . '/' ) ) {
			$post_path = substr( $post_path, strlen( $home_path ) + 1 );
		}
		return $post_path === trim( (string) $path, '/' );
	}

	/**
	 * Turn a set of resolved query vars into a conflict description, but only
	 * when the thing they point at genuinely exists. A rule that matches and
	 * then 404s is not a collision, and rejecting it would block slugs that are
	 * in fact free.
	 *
	 * Singular content (`name`, `pagename`, `p`) is deliberately ignored here:
	 * `resolve_wp_url_conflict()` has already asked `url_to_postid()` about it.
	 *
	 * @param array $vars Query vars produced by a matched rewrite rule.
	 * @return array{type:string,label:string,post_id:int}|null
	 */
	protected static function describe_query_var_conflict( $vars ) {
		$found = static function ( $type, $label ) {
			return array(
				'type'    => $type,
				'label'   => $label,
				'post_id' => 0,
			);
		};

		// Taxonomy archives — category and tag first, then anything else public.
		$taxonomy_vars = array(
			'category_name' => 'category',
			'tag'           => 'post_tag',
		);
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->query_var ) ) {
				$taxonomy_vars[ $taxonomy->query_var ] = $taxonomy->name;
			}
		}
		foreach ( $taxonomy_vars as $var => $taxonomy ) {
			if ( empty( $vars[ $var ] ) || ! is_string( $vars[ $var ] ) ) {
				continue;
			}
			// Hierarchical taxonomies arrive as "parent/child"; the term itself
			// is the last segment.
			$slug = (string) $vars[ $var ];
			$slug = false === strpos( $slug, '/' ) ? $slug : substr( strrchr( $slug, '/' ), 1 );
			if ( '' === $slug ) {
				continue;
			}
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$object = get_taxonomy( $taxonomy );
				return $found(
					'term_archive',
					sprintf(
						/* translators: %s: taxonomy singular name, e.g. "Category" */
						__( '%s archive', 'betterlinks' ),
						$object && isset( $object->labels->singular_name ) ? $object->labels->singular_name : $taxonomy
					)
				);
			}
		}

		// Post type archives.
		if ( ! empty( $vars['post_type'] ) && empty( $vars['name'] ) && empty( $vars['pagename'] ) && empty( $vars['p'] ) ) {
			$post_type = is_array( $vars['post_type'] ) ? reset( $vars['post_type'] ) : $vars['post_type'];
			$object    = get_post_type_object( (string) $post_type );
			if ( $object && ! empty( $object->has_archive ) ) {
				return $found(
					'post_type_archive',
					sprintf(
						/* translators: %s: post type singular name, e.g. "Product" */
						__( '%s archive', 'betterlinks' ),
						isset( $object->labels->singular_name ) ? $object->labels->singular_name : $post_type
					)
				);
			}
		}

		// Author archives.
		if ( ! empty( $vars['author_name'] ) && is_string( $vars['author_name'] ) ) {
			if ( get_user_by( 'slug', $vars['author_name'] ) ) {
				return $found( 'author_archive', __( 'author archive', 'betterlinks' ) );
			}
		}

		// Date archives, but only when they actually hold a published post.
		if ( ! empty( $vars['year'] ) ) {
			$date = array( 'year' => (int) $vars['year'] );
			if ( ! empty( $vars['monthnum'] ) ) {
				$date['month'] = (int) $vars['monthnum'];
			}
			if ( ! empty( $vars['day'] ) ) {
				$date['day'] = (int) $vars['day'];
			}
			$dated = new \WP_Query(
				array(
					'post_type'              => 'post',
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'date_query'             => array( $date ),
				)
			);
			if ( ! empty( $dated->posts ) ) {
				return $found( 'date_archive', __( 'date archive', 'betterlinks' ) );
			}
		}

		// Reserved endpoints WordPress answers on every install.
		if ( ! empty( $vars['feed'] ) ) {
			return $found( 'feed', __( 'feed', 'betterlinks' ) );
		}
		if ( ! empty( $vars['robots'] ) ) {
			return $found( 'robots', __( 'robots.txt', 'betterlinks' ) );
		}
		if ( ! empty( $vars['sitemap'] ) ) {
			return $found( 'sitemap', __( 'sitemap', 'betterlinks' ) );
		}
		if ( isset( $vars['s'] ) ) {
			return $found( 'search', __( 'search results page', 'betterlinks' ) );
		}

		return null;
	}

	/**
	 * Whether BetterLinks Pro is active.
	 *
	 * The single detection point for the free plugin. Pro sets the
	 * `betterlinks/pro_enabled` filter at bootstrap; the constant is only the
	 * default so older Pro builds, which set the filter inside wp-admin only, are
	 * still detected on the front end, in REST and in cron.
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		return (bool) apply_filters( 'betterlinks/pro_enabled', defined( 'BETTERLINKS_PRO_VERSION' ) );
	}

	/**
	 * Whether an active BetterLinks Pro predates the free plugin's extension API.
	 *
	 * Such Pro builds expect Pro features to still ship inside the free plugin,
	 * so those features are unavailable until Pro is updated.
	 *
	 * @return bool
	 */
	public static function pro_needs_update() {
		return self::is_pro_active() && ! defined( 'BETTERLINKS_PRO_EXTENSION_API_VERSION' );
	}

	/**
	 * Whether BetterLinks Pro is active and at least the given version.
	 *
	 * @param string $version Minimum Pro version.
	 * @return bool
	 */
	public static function pro_version_at_least( $version ) {
		return self::is_pro_active() && defined( 'BETTERLINKS_PRO_VERSION' ) && version_compare( BETTERLINKS_PRO_VERSION, $version, '>=' );
	}

	public static function sanitize_text_or_array_field( $array_or_string, $key = '' ) {

		$boolean   = array( 'true', 'false', '1', '0' );
		/**
		 * Filters setting keys whose values the generic sanitizer leaves untouched,
		 * because their owner sanitizes them (BetterLinks Pro: autolink_custom_icon).
		 *
		 * @param string[] $skip Keys.
		 */
		$skip      = (array) apply_filters( 'betterlinks/sanitize/skip_keys', array( 'customFields' ) );
		if ( self::pro_needs_update() ) {
			$skip[] = 'autolink_custom_icon'; // Compatibility: BetterLinks Pro before 3.0.4.
		}
		// Rich-text settings rendered on public pages: allow post-safe HTML only,
		// unless the user may already publish unfiltered HTML.
		$rich_text = array( 'affiliate_disclosure_text', 'allow_contact_text', 'form_title' );
		$url_keys  = array( 'link', 'target_url' );
		if ( is_string( $array_or_string ) ) {
			if ( in_array( $key, $url_keys, true ) ) {
				return esc_url_raw( $array_or_string );
			}
			$array_or_string = in_array( $array_or_string, $boolean ) || is_bool( $array_or_string ) ? rest_sanitize_boolean( $array_or_string ) : sanitize_text_field( $array_or_string );
		} elseif ( is_array( $array_or_string ) ) {
			foreach ( $array_or_string as $field_key => &$value ) {
				if ( in_array( $field_key, $skip, true ) ) {
					continue;
				}
				if ( in_array( $field_key, $rich_text, true ) ) {
					if ( is_string( $value ) && ! current_user_can( 'unfiltered_html' ) ) {
						$value = wp_kses_post( $value );
					}
					continue;
				}
				if ( is_array( $value ) ) {
					$value = self::sanitize_text_or_array_field( $value, $field_key );
				} elseif ( in_array( $field_key, $url_keys, true ) ) {
					$value = esc_url_raw( $value );
				} else {
					$value = in_array( $value, $boolean ) || is_bool( $value ) ? rest_sanitize_boolean( $value ) : sanitize_text_field( $value );
				}
			}
		}
		return $array_or_string;
	}
	public static function fresh_ajax_request_data( $data ) {
		$remove = array( 'action', 'security' );
		return array_diff_key( $data, array_flip( $remove ) );
	}

	public static function force_relative_url( $url ) {
		return preg_replace( '/^(http)?s?:?\/\/[^\/]*(\/?.*)$/i', '$2', '' . $url );
	}

	/**
	 * Normalizing Clicks Data
	 *
	 * This function is responsible for manualy filter the duplicates IPs and link_id's from the data.
	 *
	 * @internal this is used in update_links_analytics for clicks on cron hook called 'betterlinks/analytics'
	 *
	 * @since 1.3.1
	 *
	 * @param array $data This should be the clicks data for IP's and links.
	 * @return array
	 */
	public static function normalize_ips_data( &$data ) {
		$_results = array();
		if ( ! empty( $data ) ) {
			foreach ( $data as &$analytic ) {
				$_link_id    = $analytic['link_id'];
				$_link_count = $analytic['lidc'];
				$_ip         = isset( $analytic['ip'] ) ? trim( $analytic['ip'] ) : '';
				$_ip_count   = $analytic['ipc'];

				if ( ! isset( $_results[ $_link_id ] ) ) {
					$_results[ $_link_id ] = array(
						'link_count' => $_link_count,
						'ip'         => array(),
					);
				}

				if ( $_ip && ! isset( $_results[ $_link_id ]['ip'][ $_ip ] ) ) {
					$_results[ $_link_id ]['ip'][ $_ip ] = $_ip_count;
				}
			}
		}

		return $_results;
	}

	/**
	 * Merges the two result sets returned by get_clicks_count() into a single
	 * map keyed by link_id.
	 *
	 * The totals and the uniques come from two independent GROUP BY queries, so
	 * their row order is not guaranteed to line up. Pairing them positionally
	 * attaches one link's unique count to a different link — which is how a link
	 * with hundreds of clicks from distinct IPs ends up reporting "1 unique".
	 * Both sides are looked up by link_id instead.
	 *
	 * @since 3.0.1
	 *
	 * @param array $clicks_count Return value of get_clicks_count().
	 * @return array Map of link_id => array( 'link_count' => int, 'ip' => int ).
	 */
	public static function merge_clicks_count( $clicks_count ) {
		$results       = array();
		$total_clicks  = isset( $clicks_count['total_clicks'] ) && is_array( $clicks_count['total_clicks'] ) ? $clicks_count['total_clicks'] : array();
		$unique_clicks = isset( $clicks_count['unique_clicks'] ) && is_array( $clicks_count['unique_clicks'] ) ? $clicks_count['unique_clicks'] : array();

		$unique_by_link = array();
		foreach ( $unique_clicks as $unique ) {
			if ( isset( $unique['link_id'] ) ) {
				$unique_by_link[ $unique['link_id'] ] = isset( $unique['unique_clicks'] ) ? (int) $unique['unique_clicks'] : 0;
			}
		}

		foreach ( $total_clicks as $total ) {
			if ( ! isset( $total['link_id'] ) ) {
				continue;
			}
			$link_id             = $total['link_id'];
			$results[ $link_id ] = array(
				'link_count' => isset( $total['total_clicks'] ) ? (int) $total['total_clicks'] : 0,
				'ip'         => isset( $unique_by_link[ $link_id ] ) ? $unique_by_link[ $link_id ] : 0,
			);
		}

		return $results;
	}

	public static function update_links_analytics() {
		$results = self::merge_clicks_count( self::get_clicks_count() );

		return update_option( 'betterlinks_analytics_data', wp_json_encode( $results ), false );
	}

	public static function maybe_json( $data, $sanitize_text = true ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return wp_json_encode( $data );
		}

		if ( is_string( $data ) && $sanitize_text ) {
			return sanitize_text_field( $data );
		}

		return $data;
	}
	public static function generate_short_url( $short_url ) {
		return site_url( '/' ) . trim( $short_url, '/' );
	}

	/**
	 * Move options stored under the old three-letter `btl_` prefix to `betterlinks_`.
	 *
	 * Runs once per plugin update. Existing values are kept; an option that
	 * already exists under the new name is not overwritten.
	 *
	 * @return void
	 */
	public static function migrate_legacy_option_names() {
		$map = array(
			'btl_failed_migration_prettylinks_links'                   => 'betterlinks_failed_migration_prettylinks_links',
			'btl_failed_migration_prettylinks_clicks'                  => 'betterlinks_failed_migration_prettylinks_clicks',
			'btl_migration_prettylinks_current_successful_links_count' => 'betterlinks_migration_prettylinks_current_successful_links_count',
			'btl_migration_prettylinks_current_successful_clicks_count' => 'betterlinks_migration_prettylinks_current_successful_clicks_count',
			'btl_prettylink_migration_should_not_start_in_background'  => 'betterlinks_prettylink_migration_should_not_start_in_background',
			'btl_tags_analytics'                                       => 'betterlinks_tags_analytics',
			'btl_categories_analytics'                                 => 'betterlinks_categories_analytics',
		);
		foreach ( $map as $old => $new ) {
			$value = self::btl_get_option( $old );
			if ( false === $value ) {
				continue;
			}
			if ( false === self::btl_get_option( $new ) ) {
				self::btl_update_option( $new, $value );
			}
			delete_option( $old );
		}
	}

	public static function btl_get_option( $option_name ) {
		global $wpdb;
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}options WHERE option_name=%s", $option_name ),
			ARRAY_A
		);
		$value  = false;
		if ( ! empty( $result['option_id'] ) ) {
			$value = maybe_unserialize( $result['option_value'] );
		}
		return $value;
	}
	public static function btl_update_option( $option_name, $option_value, $careless_insert = false, $careless_update = false ) {
		global $wpdb;
		$option_value = maybe_serialize( $option_value );
		$result       = false;
		if ( ! $careless_insert && ! $careless_update ) {
			$result = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}options WHERE option_name=%s", $option_name ),
				ARRAY_A
			);
		}
		if ( $careless_insert || ( ! $careless_update && empty( $result['option_id'] ) ) ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}options ( option_name, option_value, autoload ) VALUES ( %s, %s, %s )",
					array(
						$option_name,
						$option_value,
						'no',
					)
				)
			);
			return $result;
		}
		if ( $careless_update || ! empty( $result['option_id'] ) ) {
			$result = $wpdb->update(
				"{$wpdb->prefix}options",
				array(
					'option_value' => $option_value,
					'autoload'     => 'no',
				),
				array( 'option_name' => $option_name )
			);
			return $result !== false;
		}
	}
	public static function btl_update_autoload_option( $option_name, $autoload = false ) {
		global $wpdb;
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}options WHERE option_name=%s", $option_name ),
			ARRAY_A
		);

		if ( ! empty( $result['option_id'] ) && ! empty( $result['option_value'] ) ) {
			if ( $autoload === false ) {
				$result = $wpdb->update(
					"{$wpdb->prefix}options",
					array(
						'option_value' => $result['option_value'],
						'autoload'     => 'no',
					),
					array( 'option_name' => $option_name )
				);
			} elseif ( $autoload === true ) {
				$result = $wpdb->update(
					"{$wpdb->prefix}options",
					array(
						'option_value' => $result['option_value'],
						'autoload'     => 'yes',
					),
					array( 'option_name' => $option_name )
				);
			}
			return $result !== false;
		}
	}
	public static function run_migration_for_ptrl_links_in_background( $installer, $links_count ) {
		global $wpdb;
		$per_page   = 10000;
		$total_page = ceil( $links_count / $per_page );
		for ( $page = 1; $page <= $total_page; $page++ ) {
			$offset = ( $page - 1 ) * $per_page;
			$links  = $wpdb->get_col(
				"SELECT concat('prli_links-', ID) AS ID FROM {$wpdb->prefix}prli_links LIMIT $per_page OFFSET {$offset}",
				0
			);
			$installer->data( $links )->save();
		}
		$installer->data( array( 'betterlinks_ptl_links_migrated' ) )->save();
		return $installer;
	}
	public static function run_migration_for_ptrl_clicks_in_background( $installer, $clicks_count ) {
		global $wpdb;
		$per_page   = 10000;
		$total_page = ceil( $clicks_count / $per_page );
		for ( $page = 1; $page <= $total_page; $page++ ) {
			$offset = ( $page - 1 ) * $per_page;
			$clicks = $wpdb->get_col(
				"SELECT concat('prli_clicks-', ID) AS ID FROM {$wpdb->prefix}prli_clicks LIMIT $per_page OFFSET {$offset}",
				0
			);
			$installer->data( $clicks )->save();
		}
		$installer->data( array( 'betterlinks_ptl_clicks_migrated' ) )->save();
		return $installer;
	}

	public function generate_random_slug( $length = 3 ) {
		$characters        = '0123456789abcdefghijklmnopqrstuvwxyz';
		$random_string     = '';
		$characters_length = strlen( $characters );

		for ( $i = 0; $i < $length; $i++ ) {
			$random_string .= $characters[ wp_rand( 0, $characters_length - 1 ) ];
		}
		$random_num = wp_rand( 0, 10 ) . wp_rand( 0, 10 ) . wp_rand( 0, 10 );
		return $random_string . $random_num;
	}
	public function get_betterlinks_prefix() {
		if ( BETTERLINKS_EXISTS_SETTINGS_JSON ) {
			$data = Cache::get_json_settings();

			if ( empty( $data ) ) {
				$data = Cache::write_json_settings();
			}
			$prefix = ! empty( $data['prefix'] ) ? $data['prefix'] . '/' : '';
			return $prefix;
		}

		$betterlinks_links = get_option( 'betterlinks_links', array() );
		if ( is_string( $betterlinks_links ) ) {
			$betterlinks_links = json_decode( $betterlinks_links, true );
		}
		$prefix = ! empty( $betterlinks_links['prefix'] ) ? $betterlinks_links['prefix'] . '/' : '';
		return $prefix;
	}

	/**
	 * The current user's Quick Link Creation token, for rendering the bookmarklet.
	 *
	 * Issued lazily and only for users who are actually allowed to create links,
	 * so a delegated role browsing the settings screen never receives one.
	 *
	 * @return string|null
	 */
	public static function get_cle_token_for_display() {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! CLEToken::current_user_can_create() ) {
			return null;
		}

		$record = CLEToken::get_or_issue_for_user( $user_id );

		return ( is_array( $record ) && ! empty( $record['token'] ) ) ? $record['token'] : null;
	}

	/**
	 * Sanitize callback for the `custom_tracking_scripts` REST field.
	 *
	 * The field holds raw JavaScript that is echoed verbatim on the cloaked
	 * redirect page, so storing it is an `unfiltered_html` action. The write path
	 * (`BetterLinksPro\Helper::update_custom_script_data`) already refuses the
	 * field for callers without that capability; this drops it one layer earlier
	 * so a delegated `writelinks` / `editlinks` role can never get raw markup as
	 * far as the storage layer.
	 *
	 * @param mixed $value Incoming value.
	 * @return string
	 */
	public static function sanitize_custom_tracking_scripts( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return '';
		}

		return $value;
	}

	public function fetch_target_url( $target_url ) {
		if ( empty( $target_url ) ) {
			return false;
		}

		// SSRF guard: this fetches a caller-supplied URL server-side, so it must
		// not be able to reach internal hosts. wp_safe_remote_get() runs
		// wp_http_validate_url() (rejects loopback, RFC1918, link-local
		// 169.254/16, CGNAT, reserved ranges and non-http(s) schemes) and keeps
		// TLS verification on. Replaces WP_Http::get() with sslverify => false.
		$result = wp_safe_remote_get(
			$target_url,
			array(
				'timeout'     => 5,
				'redirection' => 3,
				// Preserve the original WP_Http::get() behavior (no cert enforcement)
				// so this title fetch still works for targets with invalid certs;
				// wp_safe_remote_get only adds the SSRF host/redirect validation.
				'sslverify'   => false,
				'limit_response_size' => 512 * 1024,
			)
		);
		$title  = '';
		$body   = is_wp_error( $result ) ? '' : wp_remote_retrieve_body( $result );
		if ( ! empty( $body ) && preg_match( '/<title>(.*)<\/title>/siU', $body, $title_matches ) ) {
			$title = html_entity_decode( $title_matches[1] );
		}
		return $title;
	}
	public static function insert_new_category( $slug ) {
		if ( ! ! intval( $slug ) ) {
			return $slug;
		}

		// Check if category exists and get its ID for AI
		$existing_term = self::get_term_by_slug( self::make_slug( $slug ), 'category' );
		if ( ! empty( $existing_term ) && is_array( $existing_term ) && count( $existing_term ) > 0 ) {
			// Category exists, return its ID
			return $existing_term[0]['ID'];
		}

		// Category doesn't exist, create it
		$insert_id = self::insert_term(
			array(
				'term_name' => $slug,
				'term_slug' => self::make_slug( $slug ),
				'term_type' => 'category',
			)
		);
		if ( $insert_id ) {
			self::clear_query_cache();
			return $insert_id;
		}

		return $slug;
	}

	public static function isJson( $string ) {
		json_decode( $string );
		return json_last_error() === JSON_ERROR_NONE;
	}

	public static function get_migratable_plugins() {
		return [
			'simple301redirects' => defined('SIMPLE301REDIRECTS_VERSION'),
			'thirstyaffiliates' => class_exists('ThirstyAffiliates'),
			'prettylinks' => defined('PRLI_VERSION'),
		];
	}
	
	public static function init_tracking( $data, $utils ) {
		global $betterlinks;
		$user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore
		$dd         = new DeviceDetector( $user_agent );
		$dd->parse();

		$data['is_bot'] = $dd->isBot();
		if ( empty( $data['target_url'] ) || ! apply_filters( 'betterlinks/pre_before_redirect', $data ) ) {
			// password protection logics
			if( empty( $data['skip_password_protection'] ) ){
				do_action( 'betterlinkspro/admin/check_password_protection', $data['short_url'], $data );
			}

			if ( empty( $data['target_url'] ) || ! apply_filters( 'betterlinks/pre_before_redirect', $data ) ) { // phpcs:ignore
				return false;
			}
		}
		$data = apply_filters( 'betterlinks/link/before_dispatch_redirect', $data ); // phpcs:ignore.
		if ( empty( $data ) ) {
			return false;
		}
		do_action( 'betterlinks/before_redirect', $data ); // phpcs:ignore.

		$comparable_url  = rtrim( preg_replace( '/https?\:\/\//', '', site_url( '/' ) ), '/' ) . '/' . $data['short_url'];
		$destination_url = rtrim( preg_replace( '/https?\:\/\//', '', $data['target_url'] ), '/' );
		$comparable_url  = rtrim( preg_replace( '/^www\.?/', '', $comparable_url ), '/' );
		$destination_url = rtrim( preg_replace( '/^www\.?/', '', $destination_url ), '/' );
		if ( ! $data || $comparable_url === $destination_url ) {
			return;
		}

		if ( filter_var( $data['track_me'], FILTER_VALIDATE_BOOLEAN ) ) {
			$data      = apply_filters( 'betterlinks/extra_tracking_data', $data, $dd );
	
			$data['os']      = OperatingSystem::getOsFamily( $dd->getOs( 'name' ) );
			$data['browser'] = Browser::getBrowserFamily( $dd->getClient( 'name' ) );
			$data['device']  = $dd->getDeviceName();
	
			if ( isset( $betterlinks['disablebotclicks'] ) && $betterlinks['disablebotclicks'] ) {
				if ( ! $dd->isBot() ) {
					$utils->start_trakcing( $data );
				}
			} else {
				$utils->start_trakcing( $data );
			}
		}
	}

	/**
	 * Rebuild links JSON from database
	 * Useful when JSON file is corrupted or completely out of sync
	 * 
	 * @since 2.6.0
	 * @return bool True if rebuild successful
	 */
	public static function rebuild_links_json() {
		if ( ! BETTERLINKS_EXISTS_LINKS_JSON ) {
			return false;
		}

		$formattedArray = self::get_links_for_json();
		$json_file = trailingslashit( BETTERLINKS_UPLOAD_DIR_PATH ) . 'links.json';
		
		return (bool) file_put_contents( $json_file, wp_json_encode( $formattedArray ) );
	}

	/**
	 * Sync all missing links from database to JSON file
	 * Checks if all database links exist in JSON and adds missing ones
	 * Called when admin page loads to ensure complete synchronization
	 * 
	 * @since 2.6.2
	 * @return array Results: ['total' => count, 'synced' => count]
	 */
	public static function sync_all_missing_links_to_json() {
		if ( ! BETTERLINKS_EXISTS_LINKS_JSON ) {
			return array( 'total' => 0, 'synced' => 0 );
		}

		if ( ! function_exists( 'file_get_contents' ) || ! function_exists( 'file_put_contents' ) ) {
			return array( 'total' => 0, 'synced' => 0 );
		}

		$json_file = trailingslashit( BETTERLINKS_UPLOAD_DIR_PATH ) . 'links.json';

		// Read JSON file
		if ( ! file_exists( $json_file ) ) {
			// JSON doesn't exist, rebuild from scratch
			self::rebuild_links_json();
			return array( 'total' => 0, 'synced' => 0 );
		}

		$json_content = file_get_contents( $json_file );
		$json_data = json_decode( $json_content, true );

		// If JSON is corrupted, rebuild
		if ( ! is_array( $json_data ) ) {
			self::rebuild_links_json();
			return array( 'total' => 0, 'synced' => 0 );
		}

		// Get all published links from database
		global $wpdb;
		$all_db_links = $wpdb->get_results(
			"SELECT ID, short_url, wildcards FROM {$wpdb->prefix}betterlinks WHERE link_status = 'publish'",
			ARRAY_A
		);

		$synced_count = 0;
		$total_links = count( $all_db_links );

		// Check each database link and add if missing from JSON
		foreach ( $all_db_links as $db_link ) {
			$short_url = $db_link['short_url'];
			$link_id = $db_link['ID'];
			$is_wildcard = isset( $db_link['wildcards'] ) && $db_link['wildcards'];

			// Determine where to look
			$target_section = $is_wildcard ? 'wildcards' : 'links';

			// Check if link exists in JSON
			$link_exists = false;
			if ( isset( $json_data[ $target_section ] ) && is_array( $json_data[ $target_section ] ) ) {
				if ( isset( $json_data[ $target_section ][ $short_url ] ) ) {
					$link_exists = true;
				}
			}

			// If missing, add it
			if ( ! $link_exists ) {
				$full_link_data = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}betterlinks WHERE ID = %d",
						$link_id
					),
					ARRAY_A
				);

				if ( $full_link_data ) {
					$formatted_link = self::json_link_formatter( $full_link_data );

					if ( $formatted_link ) {
						// Ensure target section exists
						if ( ! isset( $json_data[ $target_section ] ) ) {
							$json_data[ $target_section ] = array();
						}

						// Add missing link to JSON
						$json_data[ $target_section ][ $short_url ] = $formatted_link;
						$synced_count++;
					}
				}
			}
		}

		// Write back to file if any links were synced
		if ( $synced_count > 0 ) {
			file_put_contents( $json_file, wp_json_encode( $json_data ) );
		}

		return array(
			'total' => $total_links,
			'synced' => $synced_count,
		);
	}
}
