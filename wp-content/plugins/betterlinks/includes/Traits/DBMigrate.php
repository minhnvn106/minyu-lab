<?php
namespace BetterLinks\Traits;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\Cache;
use BetterLinks\Helper;

// phpcs:disable WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB

trait DBMigrate {

	public function db_migration_1_1() {
		$table_name  = $this->wpdb->prefix . 'betterlinks';
		$betterlinks = $this->wpdb->get_row( "SELECT * FROM $table_name" );
		// Add column if not present.
		if ( ! isset( $betterlinks->wildcards ) ) {
			$this->wpdb->query( "ALTER TABLE $table_name ADD wildcards BOOLEAN NOT NULL DEFAULT 0" );
		}
	}
	public function db_migration_1_2() {
		$table_name  = $this->wpdb->prefix . 'betterlinks';
		$betterlinks = $this->wpdb->get_row( "SELECT * FROM $table_name" );
		// Add column if not present.
		if ( ! isset( $betterlinks->expire ) ) {
			$this->wpdb->query( "ALTER TABLE $table_name ADD expire text default NULL" );
		}
	}
	public function db_migration_1_4() {
		// links
		$betterlinks_table = $this->wpdb->prefix . 'betterlinks';
		$betterlinks       = $this->wpdb->get_row( "SELECT * FROM $betterlinks_table" );
		// Add column if not present.
		if ( ! isset( $betterlinks->dynamic_redirect ) ) {
			$this->wpdb->query( "ALTER TABLE $betterlinks_table ADD dynamic_redirect text default NULL" );
		}
		// clicks
		$betterlinks_clicks_table = $this->wpdb->prefix . 'betterlinks_clicks';
		$betterlinks_clicks       = $this->wpdb->get_row( "SELECT * FROM $betterlinks_clicks_table" );
		// Add column if not present.
		if ( ! isset( $betterlinks_clicks->rotation_target_url ) ) {
			$this->wpdb->query( "ALTER TABLE  $betterlinks_clicks_table ADD rotation_target_url varchar(255) NULL" );
		}
	}
	public function update_fluent_settings() {
		if ( ! defined( 'FLUENT_BOARDS' ) ) {
			return;
		}
		$settings = Cache::get_json_settings();
		
		// Ensure $settings is an array (fix for PHP 8+ scalar value error)
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		
		if ( empty( $settings['fbs']['enable_fbs'] ) ) {
			$args    = array(
				'ID'        => 0,
				'term_name' => 'Fluent Boards',
				'term_slug' => 'btl-fluent-boards',
				'term_type' => 'category',
			);
			$results = $this->create_term( $args );
			$fbs_cat = ! empty( $results['ID'] ) ? $results['ID'] : 0;

			$settings['fbs'] = array();
			$settings['fbs'] = array(
				'enable_fbs' => true,
				'cat_id'     => $fbs_cat,
				'delete_on'  => 'task_delete',
			);
		}
		if ( $settings ) {
			Helper::clear_query_cache();
			$settings = wp_json_encode( $settings );
			update_option( BETTERLINKS_LINKS_OPTION_NAME, $settings );
			Cache::write_json_settings();
			Helper::write_links_inside_json();
		}
	}

	public function update_fluent_task_delete_settings() {
		if ( ! defined( 'FLUENT_BOARDS' ) ) {
			return;
		}

		$settings = Cache::get_json_settings();
		
		// Ensure $settings is an array (fix for PHP 8+ scalar value error)
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		
		if ( ! empty( $settings['fbs']['delete_on'] ) ) {
			return;
		}
		$settings['fbs']['delete_on'] = 'task_delete';

		if ( $settings ) {
			Helper::clear_query_cache();
			$settings = wp_json_encode( $settings );
			update_option( BETTERLINKS_LINKS_OPTION_NAME, $settings );
			Cache::write_json_settings();
			Helper::write_links_inside_json();
		}
	}

	public function update_cle_category() {
		$settings = Cache::get_json_settings();
		
		// Ensure $settings is an array (fix for PHP 8+ scalar value error)
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		
		if ( isset( $settings['cle'] ) && empty( $settings['cle']['category'] ) ) {
			$settings['cle']['category'] = '1';

			if ( $settings ) {
				Helper::clear_query_cache();
				$settings = wp_json_encode( $settings );
				update_option( BETTERLINKS_LINKS_OPTION_NAME, $settings );
				Cache::write_json_settings();
				Helper::write_links_inside_json();
			}
		}
	}


	public function update_settings() {
		$settings = Cache::get_json_settings();

		// Ensure $settings is an array (fix for PHP 8+ scalar value error)
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		if ( empty( $settings['enable_custom_domain_menu'] ) ) {
			$settings['enable_custom_domain_menu'] = true;
		}
		$settings = json_encode( $settings );
		delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
		if ( $settings ) {
			update_option( BETTERLINKS_LINKS_OPTION_NAME, $settings );
			Cache::write_json_settings();
		}
		if ( empty( get_option( BETTERLINKS_CUSTOM_DOMAIN_MENU, false ) ) ) {
			update_option( BETTERLINKS_CUSTOM_DOMAIN_MENU, true );
		}
		// regenerate links for wildcards option update
		Helper::write_links_inside_json();
	}

	/**
	 * Migrate settings to ensure all default settings exist
	 * This is crucial for backward compatibility with older plugin versions
	 * 
	 * @since 2.6.1
	 */
	public function migrate_default_settings() {
		// Get current settings
		$current_settings = get_option( BETTERLINKS_LINKS_OPTION_NAME, '{}' );
		
		// If it's a JSON string, decode it
		if ( is_string( $current_settings ) ) {
			$current_settings = json_decode( $current_settings, true );
		}
		
		// If decoding failed or empty, initialize as array
		if ( ! is_array( $current_settings ) ) {
			$current_settings = array();
		}

		// Define default settings - same as in save_settings() method
		$default_settings = array(
			'redirect_type'                => '307',
			'nofollow'                     => true,
			'sponsored'                    => '',
			'track_me'                     => true,
			'param_forwarding'             => '',
			'wildcards'                    => false,
			'disablebotclicks'             => false,
			'is_allow_gutenberg'           => true,
			'force_https'                  => false,
			'prefix'                       => 'go',
			'is_allow_qr'                  => false,
			'is_random_string'             => false,
			'is_autolink_icon'             => false,
			'is_autolink_headings'         => true,
			'is_case_sensitive'            => false,
			'enable_custom_domain_menu'    => true,
			'enable_auto_title_suggestion' => true,
			'fbs'                          => array(
				'enable_fbs' => true,
				'cat_id'     => 0,
				'delete_on'  => 'task_delete',
			),
		);

		// Track if any changes were made
		$settings_updated = false;

		// Recheck each setting - only add if missing, never overwrite
		foreach ( $default_settings as $key => $default_value ) {
			if ( ! isset( $current_settings[ $key ] ) ) {
				// Key doesn't exist, add it with default value
				$current_settings[ $key ] = $default_value;
				$settings_updated         = true;
			} elseif ( 'fbs' === $key && is_array( $default_value ) ) {
				// Special handling for nested 'fbs' array
				if ( ! is_array( $current_settings[ $key ] ) ) {
					$current_settings[ $key ] = $default_value;
					$settings_updated         = true;
				} else {
					// Check each sub-key in fbs
					foreach ( $default_value as $sub_key => $sub_default_value ) {
						if ( ! isset( $current_settings[ $key ][ $sub_key ] ) ) {
							$current_settings[ $key ][ $sub_key ] = $sub_default_value;
							$settings_updated                     = true;
						}
					}
				}
			}
		}

		// Only update if changes were made
		if ( $settings_updated ) {
			// Encode and save
			$settings_json = wp_json_encode( $current_settings );
			update_option( BETTERLINKS_LINKS_OPTION_NAME, $settings_json );
			
			// Update cache and regenerate links
			Cache::write_json_settings();
			Helper::clear_query_cache();
			Helper::write_links_inside_json();
		}
	}
}
