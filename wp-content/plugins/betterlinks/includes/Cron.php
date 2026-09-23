<?php
namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Helper;

class Cron
{
    public static function init()
    {
        $self = new self();
        add_filter('cron_schedules', [$self, 'add_cron_schedule']);
        add_action('betterlinks/write_json_links', [$self, 'write_json_links']);
        
        // Analytics job runs hourly
        if (!wp_next_scheduled('betterlinks/analytics')) {
            $timestamp = time() + (60 * 60);
            wp_schedule_event($timestamp, 'hourly', 'betterlinks/analytics');
        }
        add_action('betterlinks/analytics', [$self, 'analytics']);
    }

    public function add_cron_schedule($schedules)
    {
        $schedules['every_one_and_half_hours'] = [
            'interval' => 5400, // Every 90 Minutes
            'display' => __('Every 90 Minutes', 'betterlinks' ),
        ];
        return $schedules;
    }

    /**
     * Sync all missing links from database to JSON
     * Called hourly by CRON job and on-demand via Ajax "Refresh Stats" button
     * 
     * @since 2.4.9
     */
    public function sync_missing_links_to_json()
    {
        try {
            $result = Helper::sync_all_missing_links_to_json();
            
            // Log if links were synced (for debugging)
            if ( !empty($result['synced']) && $result['synced'] > 0 ) {
            }
            
            return $result;
        } catch (\Throwable $th) {
            return array( 'total' => 0, 'synced' => 0, 'error' => $th->getMessage() );
        }
    }

    public function write_json_links()
    {
        // Self-heal the uploads deny rule for installs that predate it.
        \BetterLinks\Installer::ensure_uploads_protected();
        $formattedArray = \BetterLinks\Helper::get_links_for_json();
        return file_put_contents(BETTERLINKS_UPLOAD_DIR_PATH . '/links.json', json_encode($formattedArray));
    }

    public function analytics()
    {
        // Sync missing links to JSON first
        $this->sync_missing_links_to_json();
        
        Helper::clear_query_cache();
        Helper::clear_analytics_cache();    
        try {
            // insert clicks json data into db
            if (BETTERLINKS_EXISTS_CLICKS_JSON) {
                $Clicks = json_decode(file_get_contents(BETTERLINKS_UPLOAD_DIR_PATH . '/clicks.json'), true);
                // link id already exists or not in links table
                if (is_array($Clicks)) {
                    foreach ($Clicks as $key => $item) {
                        $click_id = Helper::insert_click($item);
                        if (!empty($click_id) && $item['is_split_enabled']) {
                            do_action('betterlinks/link/after_insert_click', $item['link_id'], $click_id, $item['target_url']);
                        }
                    }
                    file_put_contents(BETTERLINKS_UPLOAD_DIR_PATH . '/clicks.json', '{}');
                }
            }

            $is_update = Helper::update_links_analytics();
            return $is_update;
        } catch (\Throwable $th) {
            return $th->getMessage();
        }
        return;
    }
}
