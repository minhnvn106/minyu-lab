<?php
namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\Cache;

class Installer extends \WP_Background_Process
{
    use Traits\DBTables;
    use Traits\DBMigrate;
    use Traits\Terms;

// phpcs:disable PluginCheck.Security.DirectDB, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL

    protected $wpdb;
    protected $charset_collate;
    protected $action = 'betterlinks_background_task';
    public $activation;
    public $migration;
    public $db_version;

    public function __construct()
    {
        parent::__construct();
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
        $this->activation = ['set_activation_flag','create_db_tables', 'db_migration', 'fix_betterlinks_db', 'insert_terms_data', 'create_json_files', 'save_settings', 'update_json_links', 'clear_cache'];
        $this->migration = ['set_activation_flag', 'db_migration', 'fix_betterlinks_db', 'update_json_links', 'sync_missing_links_to_json', 'clear_cache', 'fix_json_files'];
        $this->db_version = Helper::btl_get_option('betterlinks_db_version');
    }

    /**
     * Task
     *
     * Override this method to perform any actions required on each
     * queue item. Return the modified item for further processing
     * in the next pass through. Or, return false to remove the
     * item from the queue.
     *
     * @param mixed $item Queue item to iterate over
     *
     * @return mixed
     */
    protected function task($item)
    {
        if (method_exists($this, $item)) {
            try {
                $this->$item();
            } catch (\Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    trigger_error('BetterLinks background task triggered fatal error for callback ' . esc_html($item), E_USER_WARNING); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                }
            }
        } elseif(!(strpos($item, "prli_links-") === false)) {
            $item = absint(substr($item, 11)); // getting the ID(number) by deleting 'prli_links-' (used 11 because the length of 'prli_links-' is 11)
            $migrator = new \BetterLinks\Tools\Migration\PTLOneClick();
            if( ! $migrator->insert_link( $item ) ) {
                return true;
            }
        } elseif(!(strpos($item, "prli_clicks-") === false)) {
            $item = absint(substr($item, 12)); // getting the ID(number) by deleting 'prli_clicks-' (used 12 because the length of 'prli_clicks-' is 12)
            $migrator = new \BetterLinks\Tools\Migration\PTLOneClick();
            if( ! $migrator->insert_click( $item ) ) {
                return true;
            }
        } elseif(
            $item === "betterlinks_notice_ptl_migrate" || 
            $item === "betterlinks_ptl_links_migrated" || 
            $item === "betterlinks_ptl_clicks_migrated"
        ){
            $this->after_migration_done();
            Helper::btl_update_option($item, true);
        }
        return false;
    }

    /**
     * Complete
     *
     * Override if applicable, but ensure that the below actions are
     * performed, or, call parent::complete().
     */
    protected function complete()
    {
        parent::complete();
        // Show notice to user or perform some other arbitrary task...
    }

    public function after_migration_done(){
        // 'betterlinks/admin/after_import_data' hook's work done here
        $Cron = new \BetterLinks\Cron();
        $Cron->write_json_links();
        $Cron->analytics();
        Helper::clear_query_cache();
    }

    public function set_activation_flag()
    {
        $activation_flag = Helper::btl_get_option('betterlinks_activation_flag');
        $new_flag_data = array_merge(
            (is_array($activation_flag) ? $activation_flag : []),
            ["last_activation_background_processes_firing_timestamp" => time()]
        );
        Helper::btl_update_option('betterlinks_activation_flag', $new_flag_data, !$activation_flag, !!$activation_flag);
    }
    public function create_db_tables()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $this->createBetterLinksTable();
        $this->createBetterTermsTable();
        $this->createBetterTermsRelationshipsTable();
        $this->createBetterLinksCountriesTable(); // Create countries table first
        $this->createBetterClicksTable(); // Create clicks table after countries table
        $this->createBetterLinkMetaTable();
        $this->createBetterLinkPasswordTable();
        $this->createBetterUserAgentsTable();
        $this->modifyBetterLinksClicksTableAddUserAgent();
        // set plugin version in 'option table' if not already setted 
        // (i.e. when this plugin gets installed on a site for the very first time)
        if (!Helper::btl_get_option('betterlinks_version')) {
            Helper::btl_update_option('betterlinks_version', BETTERLINKS_VERSION, true);
        }
        // set db version in 'option table' if not already setted 
        // (i.e. when this plugin gets installed on a site for the very first time)
        if (!Helper::btl_get_option('betterlinks_db_version')) {
            Helper::btl_update_option('betterlinks_db_version', BETTERLINKS_DB_VERSION, true);
        }
    }

    /**
     * Recreate any of our tables that are missing from the database.
     *
     * create_db_tables() only runs on activation and on a version bump, so an
     * install where a CREATE TABLE failed - for instance the invalid
     * `longtext NOT NULL default ''`, which MySQL 8 rejects outright under
     * STRICT_TRANS_TABLES - stays broken until the next upgrade, with every read
     * of the absent table raising a DB error. Check once per install and only
     * record the repair as done when the tables are actually present afterwards.
     *
     * @return void
     */
    public function heal_missing_tables()
    {
        if (Helper::btl_get_option('betterlinks_tables_healed')) {
            return;
        }

        $tables = [
            'betterlinks'                   => 'createBetterLinksTable',
            'betterlinks_terms'             => 'createBetterTermsTable',
            'betterlinks_terms_relationships' => 'createBetterTermsRelationshipsTable',
            'betterlinks_countries'         => 'createBetterLinksCountriesTable',
            'betterlinks_clicks'            => 'createBetterClicksTable',
            'betterlinkmeta'                => 'createBetterLinkMetaTable',
            'betterlinks_password'          => 'createBetterLinkPasswordTable',
            'betterlinks_user_agents'       => 'createBetterUserAgentsTable',
        ];

        $missing = [];
        foreach ($tables as $suffix => $creator) {
            if (!$this->table_exists($this->wpdb->prefix . $suffix)) {
                $missing[$suffix] = $creator;
            }
        }

        if (empty($missing)) {
            Helper::btl_update_option('betterlinks_tables_healed', BETTERLINKS_VERSION, true);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $healed = true;
        foreach ($missing as $suffix => $creator) {
            $this->$creator();
            if (!$this->table_exists($this->wpdb->prefix . $suffix)) {
                $healed = false;
            }
        }

        // Leave the flag unset when a table still could not be created, so the
        // next request retries instead of locking in a broken schema.
        if ($healed) {
            Helper::btl_update_option('betterlinks_tables_healed', BETTERLINKS_VERSION, true);
        }
    }

    /**
     * @param string $table Fully prefixed table name.
     * @return bool
     */
    protected function table_exists($table)
    {
        return $this->wpdb->get_var($this->wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function insert_terms_data()
    {
        try {
            Helper::insert_term([
                'term_name' => 'Uncategorized',
                'term_slug' => 'uncategorized',
                'term_type' => 'category',
            ]);
        } catch (\Throwable $th) {
            echo esc_html( $th->getMessage() );
        }
    }

    public function save_settings()
    {
        if (!Helper::btl_get_option(BETTERLINKS_LINKS_OPTION_NAME)) {
            $fbs_cat = 0;
            if( defined( 'FLUENT_BOARDS' ) ){
                $args    = array(
                    'ID'        => 0,
                    'term_name' => 'Fluent Boards',
                    'term_slug' => 'btl-fluent-boards',
                    'term_type' => 'category',
                );
                $results = $this->create_term( $args );
                $fbs_cat = !empty( $results['ID'] ) ? $results['ID'] : 0;
            }
            $value = [
                'redirect_type'         => '307',
                'nofollow'   		    => true,
                'sponsored'  	        => '',
                'track_me'   		    => true,
                'param_forwarding'      => '',
                'wildcards'  	        => false,
                'disablebotclicks'      => false,
                'is_allow_gutenberg'    => true,
                'force_https'   	    => false,
                'prefix'                => 'go',
                'is_allow_qr'           => false,
                'is_random_string'      => false, // Legacy setting - keep for backward compatibility
                'url_slug_generation_type' => 'random_mixed', // New setting
                'is_autolink_icon'      => false,
                'is_autolink_headings'  => true,
                'is_case_sensitive'     => false,
                'enable_custom_domain_menu' => true,
                'enable_promo_cards'    => true,
                'enable_bio_links'      => true,
                // MCP connector master switch. Off by default: turning it on
                // exposes the OAuth-capable MCP endpoint and is an explicit
                // admin decision.
                'enable_mcp'            => false,
                // The bio pages' own link category is machinery, so it stays off
                // Manage Links until the admin opts in.
                'show_bio_links_category' => false,
                // Site-wide "Made with BetterLinks" credit on bio pages. On by
                // default; turning it off white-labels every page at once.
                'show_bio_links_branding' => true,
                'enable_auto_title_suggestion' => true,
                'enable_user_agent_tracking' => false,
                'fbs'        => [
                    'enable_fbs' => true,
                    'cat_id'    => $fbs_cat,
                    'delete_on' => 'task_delete'
                ]
            ];
            Helper::btl_update_option(BETTERLINKS_LINKS_OPTION_NAME, json_encode($value));
        }
        Cache::init();
    }

    /**
     * Create files/directories.
     */
    public function create_json_files()
    {
        $emptyContent = '{}';
        $files = [
            [
                'base' => BETTERLINKS_UPLOAD_DIR_PATH,
                'file' => 'index.html',
                'content' => '',
            ],
            [
                'base' => BETTERLINKS_UPLOAD_DIR_PATH,
                'file' => 'links.json',
                'content' => $emptyContent,
            ],
            [
                'base' => BETTERLINKS_UPLOAD_DIR_PATH,
                'file' => 'clicks.json',
                'content' => $emptyContent,
            ],
            [
                'base' => BETTERLINKS_UPLOAD_DIR_PATH,
                'file' => 'settings.json',
                'content' => $emptyContent,
            ],
        ];

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        foreach ($files as $file) {
            $target = trailingslashit($file['base']) . $file['file'];
            if (wp_mkdir_p($file['base']) && ! file_exists( $target )) {
                $wp_filesystem->put_contents( $target, $file['content'], FS_CHMOD_FILE );
            }
        }

        self::ensure_uploads_protected();
    }

    /**
     * Drop a deny rule into the BetterLinks uploads directory so the generated
     * links.json / clicks.json / settings.json files cannot be fetched over HTTP.
     * Every consumer of those files reads them from disk, so denying web access
     * costs nothing. Called on install and, for installs that predate the rule,
     * from Cron::write_json_links() as a self-heal.
     *
     * @return void
     */
    public static function ensure_uploads_protected()
    {
        if ( ! defined( 'BETTERLINKS_UPLOAD_DIR_PATH' ) ) {
            return;
        }

        $base     = trailingslashit( BETTERLINKS_UPLOAD_DIR_PATH );
        $htaccess = $base . '.htaccess';
        $index    = $base . 'index.html';

        // Already hardened - keep this cheap, it runs on every JSON rewrite.
        if ( file_exists( $htaccess ) && file_exists( $index ) ) {
            return;
        }

        if ( ! wp_mkdir_p( BETTERLINKS_UPLOAD_DIR_PATH ) ) {
            return;
        }

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ( empty( $wp_filesystem ) ) {
            return;
        }

        if ( ! file_exists( $htaccess ) ) {
            $rules = "# BetterLinks - deny direct web access to generated data files.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "\tRequire all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "\tOrder allow,deny\n"
                . "\tDeny from all\n"
                . "</IfModule>\n";
            $wp_filesystem->put_contents( $htaccess, $rules, FS_CHMOD_FILE );
        }

        if ( ! file_exists( $index ) ) {
            $wp_filesystem->put_contents( $index, '', FS_CHMOD_FILE );
        }
    }

    public function update_json_links()
    {
        $Cron = new Cron();
        $Cron->write_json_links();
    }

    /**
     * Sync all missing links from database to JSON file during migration/update
     * Ensures complete synchronization when plugin is updated to v2.4.8+
     * 
     * @since 2.4.8
     * @return void
     */
    public function sync_missing_links_to_json()
    {
        $result = Helper::sync_all_missing_links_to_json();
        if ( !empty($result['synced']) && $result['synced'] > 0 ) {
        }
    }

    public function db_migration()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        if ($this->db_version && $this->db_version != BETTERLINKS_DB_VERSION) {
            if (BETTERLINKS_DB_VERSION == '1.1') {
                $this->db_migration_1_1();
            } elseif (BETTERLINKS_DB_VERSION == '1.2') {
                $this->db_migration_1_2();
            } elseif (BETTERLINKS_DB_VERSION == '1.4') {
                $this->db_migration_1_4();
            } elseif (BETTERLINKS_DB_VERSION == '1.5') {
                $this->createBetterLinkMetaTable();
            } elseif (BETTERLINKS_DB_VERSION == '1.6') {
                $this->createBetterLinkPasswordTable();
            }
            if (version_compare($this->db_version, '1.3', '<')) {
                $this->db_migration_1_1();
                $this->db_migration_1_2();
            }

            if( version_compare(BETTERLINKS_DB_VERSION, '1.6', '>=') ) {
                $this->createBetterLinkPasswordTable();
            }

            if( version_compare(BETTERLINKS_DB_VERSION, '1.6', '>') ) {
                $this->modifyBetterLinksTable();
            }

            if( version_compare( BETTERLINKS_DB_VERSION, '1.6.1', '>' ) ) {
                // run analytics total clicks & unique clicks data migration
                \BetterLinks\Helper::update_links_analytics();
            }
            if( version_compare( BETTERLINKS_DB_VERSION, '1.6.3', '>=' ) ) {
                $this->modifyBetterLinksClicksTable();
            }
            
            if( version_compare( BETTERLINKS_DB_VERSION, '1.6.3', '>' ) ) {
                $this->update_settings();
                $this->update_fluent_settings();
            }
            if( version_compare( BETTERLINKS_DB_VERSION, '1.6.4', '>' ) ) {
                $this->update_fluent_task_delete_settings();
                $this->update_cle_category();
            }

            if( version_compare( BETTERLINKS_DB_VERSION, '1.6.6', '>' ) ){
                $this->modifyBetterLinksClicksTable2();
            }

            // Ensure countries table exists for all versions >= 1.6.7
            if( version_compare( BETTERLINKS_DB_VERSION, '1.6.7', '>=' ) ){
                $this->createBetterLinksCountriesTable();
                $this->modifyBetterLinksClicksTable4();
            }
            // Ensure User Agent table exists for all versions >= 1.6.7
            if( version_compare( BETTERLINKS_DB_VERSION, '2.0.0', '>=' ) ){
                $this->createBetterUserAgentsTable();
                $this->modifyBetterLinksClicksTableAddUserAgent();
            }
            
            // Migrate default settings for backward compatibility (runs for all versions)
            // This ensures older users get new default settings that were added over time
            $this->migrate_default_settings();
           
        }
        Helper::btl_update_option('betterlinks_db_version', BETTERLINKS_DB_VERSION);
    }

    public function clear_cache()
    {
        Helper::clear_query_cache();
        // Analytics caches too, and a rebuild of `betterlinks_analytics_data`.
        // Both can hold click totals computed by an older build — the per-link
        // unique counts used to be paired to the wrong link — and neither is
        // rewritten until the `betterlinks/analytics` cron next runs, which on a
        // site with unreliable cron could be a long time. Rebuilding here means
        // an update fixes the numbers straight away.
        Helper::clear_analytics_cache();
        Helper::update_links_analytics();
    }

    public function fix_betterlinks_db()
    {
        $btl_db_alter_options = Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS);
        $is_favorite_column_exist = isset($btl_db_alter_options["added_favorite_column"]) ? $btl_db_alter_options["added_favorite_column"] : false;
        $is_fixed_missing_terms_relation_for_links = isset($btl_db_alter_options["fixed_missing_terms_relation_after_ta_one_click_migration"]) ? $btl_db_alter_options["fixed_missing_terms_relation_after_ta_one_click_migration"] : false;
        $is_uncloaked_column_exist = isset($btl_db_alter_options["added_uncloaked_column"]) ? $btl_db_alter_options["added_uncloaked_column"] : false;
        $added_index_to_created_at_column_in_clicks = isset($btl_db_alter_options["added_index_to_created_at_column"]) ? $btl_db_alter_options["added_index_to_created_at_column"] : false;
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}options WHERE option_name IN( 'betterlinks_autolink_options' )");
        \BetterLinks\Helper::btl_update_autoload_option('betterlinks_analytics_data');
        
        // Get actual table structure to verify columns exist
        $is_db_alter_option_exist_array = is_array($btl_db_alter_options);
        $betterlinks_table          = $wpdb->prefix . 'betterlinks';
        $betterlinks_clicks_table   = $wpdb->prefix . 'betterlinks_clicks';
        $betterlinks_columns        = $wpdb->get_col("DESC $betterlinks_table", 0);
        
        // Check actual table structure, not just cached options
        $uncloaked_column_exists_in_table = in_array("uncloaked", $betterlinks_columns);
        $favorite_column_exists_in_table = in_array("favorite", $betterlinks_columns);
        
        if( $favorite_column_exists_in_table && $is_fixed_missing_terms_relation_for_links && $uncloaked_column_exists_in_table && $added_index_to_created_at_column_in_clicks){
            return false;
        }
        $created_at_column          = 'created_at';
        if (!$added_index_to_created_at_column_in_clicks) {
            $sql = "SHOW INDEX FROM $betterlinks_clicks_table WHERE Column_name = '$created_at_column'";
            $query = $wpdb->get_results($sql);
            if(empty($query)){
                $query_result = $wpdb->query("ALTER TABLE $betterlinks_clicks_table ADD KEY created_at_idx (created_at)");
            }else{
                $query_result = true;
            }
            $new_data = array_merge(
                ($is_db_alter_option_exist_array ? Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS) : []),
                [ "added_index_to_created_at_column" => $query_result ]
            );
            Helper::btl_update_option(BETTERLINKS_DB_ALTER_OPTIONS, $new_data, !$is_db_alter_option_exist_array, $is_db_alter_option_exist_array);
        }
        
        // Handle favorite column first - should be positioned after dynamic_redirect
        if (!$favorite_column_exists_in_table) {
            delete_transient(BETTERLINKS_CACHE_LINKS_NAME);
            $query_result = $wpdb->query("ALTER TABLE $betterlinks_table ADD favorite varchar(255) NOT NULL AFTER dynamic_redirect");
            $new_data = array_merge(
                ($is_db_alter_option_exist_array ? Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS) : []),
                [ "added_favorite_column" => $query_result ]
            );
            Helper::btl_update_option(BETTERLINKS_DB_ALTER_OPTIONS, $new_data, !$is_db_alter_option_exist_array, $is_db_alter_option_exist_array);
        } elseif (!$is_favorite_column_exist) {
            // Column exists in table but not marked in options, update the options
            $new_data = array_merge(
                ($is_db_alter_option_exist_array ? Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS) : []),
                [ "added_favorite_column" => true ]
            );
            Helper::btl_update_option(BETTERLINKS_DB_ALTER_OPTIONS, $new_data, !$is_db_alter_option_exist_array, $is_db_alter_option_exist_array);
        }
        
        // Handle uncloaked column - should be positioned after favorite
        if (!$uncloaked_column_exists_in_table) {
            delete_transient(BETTERLINKS_CACHE_LINKS_NAME);
            $query_result = $wpdb->query("ALTER TABLE $betterlinks_table ADD uncloaked varchar(10) default '' AFTER favorite");
            $new_data = array_merge(
                ($is_db_alter_option_exist_array ? Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS) : []),
                [ "added_uncloaked_column" => $query_result ]
            );
            Helper::btl_update_option(BETTERLINKS_DB_ALTER_OPTIONS, $new_data, !$is_db_alter_option_exist_array, $is_db_alter_option_exist_array);
        } elseif (!$is_uncloaked_column_exist) {
            // Column exists in table but not marked in options, update the options
            $new_data = array_merge(
                ($is_db_alter_option_exist_array ? Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS) : []),
                [ "added_uncloaked_column" => true ]
            );
            Helper::btl_update_option(BETTERLINKS_DB_ALTER_OPTIONS, $new_data, !$is_db_alter_option_exist_array, $is_db_alter_option_exist_array);
        }
        if(!$is_fixed_missing_terms_relation_for_links){
            delete_transient(BETTERLINKS_CACHE_LINKS_NAME);
            $betterlinks_table = $wpdb->prefix . 'betterlinks';
            $betterlinks_terms_table = $wpdb->prefix . 'betterlinks_terms';
            $betterlinks_terms_relations_table = $wpdb->prefix . 'betterlinks_terms_relationships';
            $link_ids = $wpdb->get_col(
                "SELECT ID FROM {$betterlinks_table}",
                0
            );
            $categories = $wpdb->get_results( 
                $wpdb->prepare( "SELECT ID,term_slug FROM {$betterlinks_terms_table} WHERE term_type = %s", "category" ) ,
                'ARRAY_A'
            );
            $uncategorized_id = false;
            $cat_ids = [];
            foreach ($categories as $key => $value) {
                $cat_ids[] = $value["ID"];
                if ($value["term_slug"] === "uncategorized") {
                    $uncategorized_id = $value["ID"];
                }
            }
            if(!$uncategorized_id){
                return false;
            }
            foreach ($link_ids as $key => $link_id) {
                $cat_relation_exist_for_link = false;
                $matched_terms_for_link = $wpdb->get_col(
                    $wpdb->prepare("SELECT term_id FROM {$betterlinks_terms_relations_table} WHERE link_id = %s", $link_id),
                    0
                );
                foreach ($matched_terms_for_link as $key => $term_id) {
                    if (in_array($term_id, $cat_ids)) {
                        $cat_relation_exist_for_link = true;
                    }
                }
                if(!$cat_relation_exist_for_link){
                    $result = Helper::insert_terms_relationships($uncategorized_id, $link_id);
                }
            }
            $new_data = array_merge(
                ($is_db_alter_option_exist_array ? Helper::btl_get_option(BETTERLINKS_DB_ALTER_OPTIONS) : []),
                [ "fixed_missing_terms_relation_after_ta_one_click_migration" => true ]
            );
            Helper::btl_update_option(BETTERLINKS_DB_ALTER_OPTIONS, $new_data, !$is_db_alter_option_exist_array, $is_db_alter_option_exist_array);
        }
    }

    public function fix_json_files() {
        $file = [
            'base' => BETTERLINKS_UPLOAD_DIR_PATH,
            'file' => 'settings.json',
            'content' => '{}',
        ];

        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $target = trailingslashit($file['base']) . $file['file'];
        if (wp_mkdir_p($file['base']) && ! file_exists( $target )) {
            $wp_filesystem->put_contents( $target, $file['content'], FS_CHMOD_FILE );
        }
    }
}
