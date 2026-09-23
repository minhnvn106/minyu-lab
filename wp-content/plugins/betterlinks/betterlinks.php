<?php
/*
 * Plugin Name:		BetterLinks
 * Plugin URI:		https://betterlinks.io/
 * Description:		Create, shorten, cloak, track and manage any URL. Gather click analytics, run marketing campaigns, and connect AI assistants over MCP.
 * Version:			3.1.4
 * Author:			WPDeveloper
 * Author URI:		https://wpdeveloper.com
 * License:			GPL-3.0-or-later
 * License URI:		https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least:	6.2
 * Requires PHP:	7.4
 * Text Domain:		betterlinks
 * Domain Path:		/languages
 */

use BetterLinks\Admin\Cache;

if (!defined('ABSPATH')) {
    exit();
}

if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

/**
 * Bundled MCP runtime (WordPress Abilities API).
 *
 * Loaded through the Jetpack Autoloader so that if the same library is also
 * shipped by another plugin — or lands in WordPress core — the newest copy
 * wins and loads once, with no fatal class collisions. This lets BetterLinks
 * serve its MCP connector out of the box, without the standalone Abilities API
 * plugin. See docs/mcp-server.md for the update procedure.
 */
$betterlinks_mcp_runtime = dirname(__FILE__) . '/dependencies/vendor/autoload_packages.php';
if (is_readable($betterlinks_mcp_runtime)) {
    require_once $betterlinks_mcp_runtime;
}
unset($betterlinks_mcp_runtime);

if (!class_exists('BetterLinks')) {
    final class BetterLinks
    {
        private $Installer;
        /** Whether rendered output on this request contains BetterLinks linked text. */
        private $frontend_app_needed = false;
        private $upload_dir;
        private function __construct()
        {
            $this->upload_dir_path();
            $this->define_constants();
            $this->set_global_settings();
            $this->Installer = new BetterLinks\Installer();
            register_activation_hook(__FILE__, [$this, 'activate']);
            register_deactivation_hook(__FILE__, [$this, 'deactivate']);
            add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
            add_action('betterlinks_loaded', [$this, 'init_plugin']);
            add_action('admin_init', [$this, 'run_migrator']);
            add_action('admin_init', [$this->Installer, 'heal_missing_tables'], 5);
            add_action('admin_init', [$this, 'do_the_works_if_failed_during_activation'], 100);
            add_action('admin_init', [$this, 'maybe_complete_legacy_quick_setup'], 9);
            add_action('admin_init', [$this, 'quick_setup']);
            $this->dispatch_hook();
            add_action( 'wp_enqueue_scripts', [$this, 'frontend_scripts'] );
            // Watch rendered output for linked text from the start of the request: block
            // themes render the whole template before `wp_enqueue_scripts` runs.
            if ( ! is_admin() ) {
                add_filter( 'the_content', [ $this, 'maybe_enqueue_frontend_app' ], 999 );
                add_filter( 'render_block', [ $this, 'maybe_enqueue_frontend_app' ], 999 );
                add_filter( 'widget_text', [ $this, 'maybe_enqueue_frontend_app' ], 999 );
            }
        }

        public function do_the_works_if_failed_during_activation()
        {
            $betterlinks_activation_flag = BetterLinks\Helper::btl_get_option("betterlinks_activation_flag");
            if(isset($betterlinks_activation_flag["last_activation_background_processes_firing_timestamp"]) && isset($betterlinks_activation_flag["last_activation_timestamp"])) {
                if($betterlinks_activation_flag["last_activation_background_processes_firing_timestamp"]){
                    return false;
                }
                $waiting_time_in_seconds = 5;
                if((absInt($betterlinks_activation_flag["last_activation_timestamp"]) + $waiting_time_in_seconds) > time()){
                    // don't go any further and return false here if,
                    // $waiting_time_in_seconds (in this case 5 seconds) haven't passed yet since the activation flag was setted
                    return false;
                }
                $all_tasks = array_merge(
                    $this->Installer->activation,
                    $this->Installer->migration
                );
                foreach ($all_tasks as $task) {
                    $this->Installer->$task();
                }
            }
        }

        public static function init()
        {
            static $instance = false;

            if (!$instance) {
                $instance = new self();
            }

            return $instance;
        }
        public function define_constants()
        {
            /**
             * Defines CONSTANTS for Whole plugins.
             */
            define('BETTERLINKS_VERSION', '3.1.4');
            define('BETTERLINKS_DB_VERSION', '1.6.11');
            define('BETTERLINKS_MENU_NOTICE', '10');
            define('BETTERLINKS_SETTINGS_NAME', 'betterlinks_settings');
            define('BETTERLINKS_PLUGIN_FILE', __FILE__);
            define('BETTERLINKS_PLUGIN_BASENAME', plugin_basename(__FILE__));
            define('BETTERLINKS_PLUGIN_SLUG', 'betterlinks');
            define('BETTERLINKS_PLUGIN_ROOT_URI', plugins_url('/', __FILE__));
            define('BETTERLINKS_ROOT_DIR_PATH', plugin_dir_path(__FILE__));
            define('BETTERLINKS_ASSETS_DIR_PATH', BETTERLINKS_ROOT_DIR_PATH . 'assets/');
            define('BETTERLINKS_ASSETS_URI', BETTERLINKS_PLUGIN_ROOT_URI . 'assets/');
            define('BETTERLINKS_UPLOAD_DIR_PATH', $this->upload_dir['basedir'] . '/betterlinks_uploads');
            define('BETTERLINKS_EXISTS_LINKS_JSON', defined('BETTERLINKS_ALLOW_JSON_REDIRECT') ? file_exists(BETTERLINKS_UPLOAD_DIR_PATH . '/links.json') && BETTERLINKS_ALLOW_JSON_REDIRECT : file_exists(BETTERLINKS_UPLOAD_DIR_PATH . '/links.json'));
            define('BETTERLINKS_EXISTS_CLICKS_JSON', file_exists(BETTERLINKS_UPLOAD_DIR_PATH . '/clicks.json'));
            define('BETTERLINKS_EXISTS_SETTINGS_JSON', defined('BETTERLINKS_ALLOW_JSON_REDIRECT') ? file_exists(BETTERLINKS_UPLOAD_DIR_PATH . '/settings.json') && BETTERLINKS_ALLOW_JSON_REDIRECT : file_exists(BETTERLINKS_UPLOAD_DIR_PATH . '/settings.json'));
            define('BETTERLINKS_LINKS_OPTION_NAME', 'betterlinks_links');
            define('BETTERLINKS_CACHE_LINKS_NAME', 'betterlinks_cache_links_data');
            define('BETTERLINKS_DB_ALTER_OPTIONS', 'betterlinks_db_alter_options');
            define('BETTERLINKS_CUSTOM_DOMAIN_MENU', 'betterlinks_custom_domain_menu');
            // Option name only: BetterLinks Pro stores AI provider keys under it, and the
            // settings cache must keep excluding it.
            define('BETTERLINKS_AI_API_KEYS_OPTION_NAME', 'betterlinks_ai_api_keys');
            // Version of the extension points BetterLinks Pro builds on (hooks that
            // replaced Pro implementations formerly bundled in this plugin). Pro
            // checks it to decide whether it must provide those features itself.
            define('BETTERLINKS_EXTENSION_API_VERSION', 1);
            // Oldest BetterLinks Pro that supports this extension API.
            define('BETTERLINKS_MIN_PRO_VERSION', '3.0.4');
        }

        public function upload_dir_path()
        {
            $this->upload_dir = wp_get_upload_dir();
        }


        public function on_plugins_loaded()
        {
            do_action('betterlinks_loaded');
        }

        /**
         * Initialize the plugin
         *
         * @return void
         */
        public function init_plugin()
        {
            BetterLinks\API::init();
            if (is_admin()) {
                new BetterLinks\Admin();
            }
            BetterLinks\Integration::init();
            new BetterLinks\Link();
            new BetterLinks\Tools();
            new BetterLinks\Frontend;
            new BetterLinks\Elementor();

            // MCP connector: register abilities (always, so generic Abilities
            // clients can discover BetterLinks) and the MCP server surface (which
            // gates serving on the enable_mcp setting). Guarded so a build without
            // the bundled Abilities runtime still boots.
            ( new BetterLinks\Abilities\Abilities_Registrar() )->init();
            ( new BetterLinks\Mcp\Mcp_Manager() )->init();
        }

        public function dispatch_hook()
        {
            BetterLinks\API::dispatch_hook();
            BetterLinks\Cron::init();
        }

        public function set_global_settings()
        {
            $GLOBALS['betterlinks'] = BetterLinks\Helper::get_links();
            $settings = Cache::get_json_settings();
            $settings = is_array($settings) ? $settings : array();
            // Compatibility: BetterLinks Pro before 3.0.4 expects its auto-create link
            // settings merged in here. Newer Pro adds them through the filter below.
            if ( BetterLinks\Helper::pro_needs_update() && defined('BETTERLINKS_PRO_AUTO_LINK_CREATE_OPTION_NAME') ) {
                $auto_create_link_settings = get_option( BETTERLINKS_PRO_AUTO_LINK_CREATE_OPTION_NAME, array() );
                if ( is_string( $auto_create_link_settings ) ) {
                    $auto_create_link_settings = json_decode( $auto_create_link_settings, true );
                }
                $settings = array_merge( $settings, is_array( $auto_create_link_settings ) ? $auto_create_link_settings : array() );
            }
            /**
             * Filters the global BetterLinks settings array. Runs while the plugin file
             * loads, so listeners must be added before that (BetterLinks Pro adds its
             * auto-create link settings from its own constructor).
             *
             * @param array $settings Settings.
             */
            $GLOBALS['betterlinks_settings'] = apply_filters( 'betterlinks/global_settings', $settings );
        }

        public function run_migrator()
        {
            $btl_version = BetterLinks\Helper::btl_get_option('betterlinks_version');
            $should_insert = $btl_version===false;
            if ($btl_version != BETTERLINKS_VERSION && BetterLinks\Helper::btl_update_option('betterlinks_version', BETTERLINKS_VERSION, $should_insert, !$should_insert)) {
                // The admin links payload is cached in a transient with no expiry, and
                // which categories it contains depends on the running code (e.g. the
                // Link in Bio category exclusion). A payload written by the previous
                // version can therefore describe categories wrongly forever — drop it
                // once per upgrade so the first dashboard load rebuilds it fresh.
                BetterLinks\Helper::clear_query_cache();
                // Rewrite links.json so it no longer carries settings that older
                // versions merged into it (BetterLinks Pro analytics credentials).
                BetterLinks\Helper::rebuild_links_json();
                BetterLinks\Helper::migrate_legacy_option_names();
                $this->Installer->data($this->Installer->migration)->save()->dispatch();
                BetterLinks\Helper::btl_update_option('betterlinks_activation_flag', [
                    "last_activation_timestamp" => time(),
                    "last_activation_background_processes_firing_timestamp" => false,
                ]);
            }
        }

        public function activate()
        {
            $this->Installer->data($this->Installer->activation)->save()->dispatch();
            BetterLinks\Helper::btl_update_option('betterlinks_activation_flag', [
                "last_activation_timestamp" => time(),
                "last_activation_background_processes_firing_timestamp" => false,
            ]);
            add_option('betterlinks_quick_setup', true);
        }

        /**
         * Retire the Quick Setup entry on sites that were already set up.
         *
         * Only the wizard's final step ever writes 'complete', so any site configured
         * before the wizard existed — or where an admin skipped it — kept showing
         * "Quick Setup" forever no matter how configured it was. Runs once per install:
         * if links already exist, the site is demonstrably past onboarding.
         */
        public function maybe_complete_legacy_quick_setup() {
            if ( get_option( 'betterlinks_quick_setup_backfilled' ) ) return;
            update_option( 'betterlinks_quick_setup_backfilled', 1, false );

            if ( 'complete' === get_option( 'betterlinks_quick_setup_step' ) ) return;

            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $has_links = (int) $wpdb->get_var( "SELECT COUNT(ID) FROM {$wpdb->prefix}betterlinks" );
            if ( $has_links > 0 ) {
                update_option( 'betterlinks_quick_setup_step', 'complete' );
                delete_option( 'betterlinks_quick_setup' );
            }
        }

        public function quick_setup() {
            if( 'complete' === get_option('betterlinks_quick_setup_step') ) return;
            if( get_option( 'betterlinks_quick_setup' ) && is_admin() ) {
                delete_option( 'betterlinks_quick_setup' );

                $redirect_url = admin_url('admin.php?page=betterlinks-quick-setup');
                wp_safe_redirect($redirect_url);
                exit;
            }
        }

        public function deactivate()
        {
            new BetterLinks\Uninstall();
        }

        public function frontend_scripts() {
			$dependencies = include BETTERLINKS_ASSETS_DIR_PATH . 'js/betterlinks.app.core.min.asset.php';

			// Click-tracking beacon. Extensions can add fields through the `betterlinks:beforeBeacon` event.
			// Registered here, enqueued only on pages that print BetterLinks-linked text.
			wp_register_script( 'betterlinks-app', BETTERLINKS_ASSETS_URI . 'js/betterlinks.app.core.min.js', [ 'jquery' ], $dependencies['version'], true );

            // Deliberately no `betterlinks_admin_nonce` here. This runs on every
            // public page, and a nonce is bound to the session rather than to a
            // capability — localizing it handed any logged-in visitor, down to a
            // Subscriber, a valid admin-AJAX nonce. The only consumer of this
            // bundle is the click-tracking beacon, which is a nopriv handler and
            // verifies no nonce at all, so nothing needs it.
            wp_localize_script('betterlinks-app', 'betterLinksApp', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'site_url' => apply_filters('betterlinks/site_url', site_url()),
                'rest_url' => rest_url(),
                'nonce' => wp_create_nonce('wp_rest'),
            ]);

            /**
             * Filters whether the click-tracking script loads on every public page.
             * Use it when linked text is printed outside post content, blocks and widgets.
             *
             * @param bool $force Default false.
             */
            if ( $this->frontend_app_needed || apply_filters( 'betterlinks/frontend/force_load_app_script', false ) ) {
                $this->enqueue_frontend_app();
            }
        }

        /**
         * Enqueue the click-tracking script once rendered output contains linked text.
         *
         * @param string $content Rendered content.
         * @return string Unchanged content.
         */
        public function maybe_enqueue_frontend_app( $content ) {
            if ( ! $this->frontend_app_needed && is_string( $content ) && false !== strpos( $content, 'betterlinks-linked-text' ) ) {
                $this->frontend_app_needed = true;
                // Content rendered after `wp_enqueue_scripts` (classic themes): enqueue now,
                // the script prints in the footer. Otherwise frontend_scripts() enqueues it.
                if ( did_action( 'wp_enqueue_scripts' ) ) {
                    $this->enqueue_frontend_app();
                }
            }
            return $content;
        }

        public function enqueue_frontend_app() {
            if ( did_action( 'betterlinks/frontend/app_script_enqueued' ) || ! wp_script_is( 'betterlinks-app', 'registered' ) ) {
                return;
            }
            remove_filter( 'the_content', [ $this, 'maybe_enqueue_frontend_app' ], 999 );
            remove_filter( 'render_block', [ $this, 'maybe_enqueue_frontend_app' ], 999 );
            remove_filter( 'widget_text', [ $this, 'maybe_enqueue_frontend_app' ], 999 );
            wp_enqueue_script( 'betterlinks-app' );
            /**
             * Fires once the click-tracking script is enqueued, so extensions can enqueue theirs next to it.
             */
            do_action( 'betterlinks/frontend/app_script_enqueued' );
        }
    }
}

/**
 * Initializes the main plugin
 *
 * @return \BetterLinks
 */
if (!function_exists('BetterLinks_Start')) {
    function BetterLinks_Start()
    {
        return BetterLinks::init();

    }
}

// Plugin Start
BetterLinks_Start();
