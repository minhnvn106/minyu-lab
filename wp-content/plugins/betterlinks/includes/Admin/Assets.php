<?php

namespace BetterLinks\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Helper;

class Assets
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'plugin_scripts']);
        add_action('enqueue_block_editor_assets', [$this, 'block_editor_assets']);
        add_filter( 'fluent_boards/asset_listed_slugs', function($approvedSlugs) {
            return wp_parse_args( [ 'betterlinks-intflboards' ], $approvedSlugs );
        });
    }

    /**
     * Enqueue Files on Start Plugin
     *
     * @function plugin_script
     */
    public function plugin_scripts($hook)
    {
        if (\BetterLinks\Helper::plugin_page_hook_suffix($hook)) {
            add_action(
                'wp_print_scripts',
                function () {
                    $isSkip = apply_filters('BetterLinks/Admin/skip_no_conflict', false);

                    if ($isSkip) {
                        return;
                    }

                    global $wp_scripts;
                    if (!$wp_scripts) {
                        return;
                    }

                    $pluginUrl = plugins_url();
                    foreach ($wp_scripts->queue as $script) {
                        $src = $wp_scripts->registered[$script]->src;
                        if (strpos($src, $pluginUrl) !== false && !strpos($src, BETTERLINKS_PLUGIN_SLUG) !== false) {
                            wp_dequeue_script($wp_scripts->registered[$script]->handle);
                        }
                    }
                },
                1
            );
            $dependencies = include_once BETTERLINKS_ASSETS_DIR_PATH . 'js/betterlinks.core.min.asset.php';
            // Version the stylesheet from its own mtime, not from the JS bundle
            // hash: a build that only changes SCSS leaves that hash untouched,
            // so browsers kept serving stale CSS and style fixes looked like
            // they had no effect.
            $admin_style_path = BETTERLINKS_ASSETS_DIR_PATH . 'css/betterlinks.css';
            $admin_style_ver  = file_exists($admin_style_path) ? (string) filemtime($admin_style_path) : $dependencies['version'];
            wp_enqueue_style('betterlinks-admin-style', BETTERLINKS_ASSETS_URI . 'css/betterlinks.css', [], $admin_style_ver, 'all');
            wp_enqueue_script(
                'betterlinks-admin-core',
                BETTERLINKS_ASSETS_URI . 'js/betterlinks.core.min.js',
                array_merge($dependencies['dependencies'], ['regenerator-runtime']),
                $dependencies['version'],
                true
            );
            global $betterlinks_settings;
            $prefix = !empty($betterlinks_settings['prefix']) ? $betterlinks_settings['prefix'] : '';
            wp_localize_script('betterlinks-admin-core', 'betterLinksGlobal', [
                'betterlinks_nonce' => wp_create_nonce('betterlinks_admin_nonce'),
                'nonce' => wp_create_nonce('wp_rest'),
                'rest_url' => rest_url(),
                'ajaxurl' => admin_url('admin-ajax.php'),
                'namespace' => BETTERLINKS_PLUGIN_SLUG . '/v1/',
                'plugin_root_url' => BETTERLINKS_PLUGIN_ROOT_URI,
                'plugin_root_path' => BETTERLINKS_ROOT_DIR_PATH,
                'site_url' => apply_filters('betterlinks/site_url', site_url()),
                'route_path' => wp_parse_url(admin_url(), PHP_URL_PATH),
                'exists_links_json' => BETTERLINKS_EXISTS_LINKS_JSON,
                'exists_clicks_json' => BETTERLINKS_EXISTS_CLICKS_JSON,
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page slug used to bootstrap admin assets, no state mutation.
                'page' => isset($_GET['page']) ? sanitize_text_field(wp_unslash( $_GET['page'] )) : '',
                'is_pro_enabled' => \BetterLinks\Helper::is_pro_active(),
                'pro_needs_update' => \BetterLinks\Helper::pro_needs_update(),
                'min_pro_version' => BETTERLINKS_MIN_PRO_VERSION,
                'prefix' => $prefix,
                'betterlinkspro_version' => defined('BETTERLINKS_PRO_VERSION') ? BETTERLINKS_PRO_VERSION : null,
                'is_extra_data_tracking_compatible' => apply_filters('betterlinks/is_extra_data_tracking_compatible', false),
                'menu_notice' => defined('BETTERLINKS_MENU_NOTICE') ? BETTERLINKS_MENU_NOTICE : null,
                'betterlinks_custom_domain_menu' => get_option( BETTERLINKS_CUSTOM_DOMAIN_MENU, 0 ),
                'betterlinks_settings' => $betterlinks_settings,
                // Quick Link Creation credential. No longer md5(AUTH_KEY): that was a
                // site-wide secret with no user binding, no expiry and no way to
                // revoke it from the plugin. This is a per-user, expiring,
                // revocable token issued by \BetterLinks\CLEToken.
                'betterlinks_auth' => \BetterLinks\Helper::get_cle_token_for_display(),
                'betterlinks_cle_endpoint' => rest_url(BETTERLINKS_PLUGIN_SLUG . '/v1/quick-link'),
                'betterlinks_date_format' => get_option( 'date_format' ),
                'is_fbs_enabled' => defined('FLUENT_BOARDS'),
                'betterlinks_quick_setup_step' => get_option( 'betterlinks_quick_setup_step', false ),
                'migratable_plugins' => Helper::get_migratable_plugins(),
                // Whether the admin has opted in to usage data sharing (drives the Settings switch).
                'usage_tracking_allowed' => ( function () {
                    $allow_tracking = get_option( 'wpins_allow_tracking' );
                    return is_array( $allow_tracking ) && isset( $allow_tracking[ BETTERLINKS_PLUGIN_SLUG ] );
                } )(),
                // Add user permission information for free version
                'user_can_manage_options' => current_user_can('manage_options'),
                // Term IDs that cannot be edited/deleted in the UI (Uncategorized + extensions).
                'protected_term_ids' => array_values(array_map('intval', (array) apply_filters('betterlinks/protected_term_ids', array(1)))),
                // Categories a feature owns but keeps off Manage Links. The links query
                // already excludes them, but the board re-adds empty categories from the
                // /terms payload, so the SPA needs the same list to stay consistent.
                'dashboard_hidden_term_ids' => array_values(array_unique(array_filter(array_map('intval', (array) apply_filters('betterlinks/dashboard_hidden_term_ids', array(), (array) $betterlinks_settings))))),
                // MCP connector bootstrap. `abilities_api_available` is what tells
                // the MCP page whether the bundled runtime actually loaded — with
                // it missing, a client connects and is offered no tools at all.
                'mcp' => [
                    'abilities_api_available' => function_exists('wp_register_ability'),
                    'endpoint'                => home_url('/betterlinks/mcp'),
                ],
            ]);

            $menu_notice = get_option('betterlinks_menu_notice', 0);
            if( defined( 'BETTERLINKS_MENU_NOTICE' ) && BETTERLINKS_MENU_NOTICE !== $menu_notice ) {
                update_option('betterlinks_menu_notice', BETTERLINKS_MENU_NOTICE);
            }
        }
        wp_set_script_translations('betterlinks-admin-core', 'betterlinks', BETTERLINKS_ROOT_DIR_PATH . 'languages/');
        if ( ! in_array( $hook, ['post.php', 'post-new.php'] ) ) {
            // Version from the file's mtime (not BETTERLINKS_VERSION) so CSS-only
            // edits invalidate the browser cache — same reason as betterlinks.css above.
            $notice_style_path = BETTERLINKS_ASSETS_DIR_PATH . 'css/betterlinks-admin-notice.css';
            $notice_style_ver  = file_exists($notice_style_path) ? (string) filemtime($notice_style_path) : BETTERLINKS_VERSION;
            wp_enqueue_style('betterlinks-admin-notice', BETTERLINKS_ASSETS_URI . 'css/betterlinks-admin-notice.css', [], $notice_style_ver, 'all');
        }
        if( 'toplevel_page_fluent-boards' == $hook ){
            $dependencies = include_once BETTERLINKS_ASSETS_DIR_PATH . 'js/betterlinks-intflboards.core.min.asset.php';
            wp_enqueue_script(
                'betterlinks-intflboards',
                BETTERLINKS_ASSETS_URI . 'js/betterlinks-intflboards.core.min.js',
                array_merge($dependencies['dependencies'], ['regenerator-runtime']),
                $dependencies['version'],
                [
                    'in_footer' => true,
                ]
            );
            $settings = Cache::get_json_settings();
            wp_localize_script('betterlinks-intflboards', 'betterLinksFlbIntegration', [
                'plugin_root_url' => BETTERLINKS_PLUGIN_ROOT_URI,
                'TASKS' => 'tasks/',
                'betterlinks_nonce' => wp_create_nonce('betterlinks_admin_nonce'),
                'site_url' => apply_filters('betterlinks/site_url', site_url()),
                'admin_url' => admin_url('/admin.php'),
                'fbs_settings' => isset($settings['fbs']) ? $settings['fbs'] : null
            ]);
            wp_enqueue_style('betterlinks-intflboards', BETTERLINKS_ASSETS_URI . 'css/integrations/btl-fbs.css', [], $dependencies['version'], 'all');
        }
    }

    /**
     * Enqueue Guten Scripts
     */
    public function block_editor_assets()
    {
        global $pagenow;
        if( 'customize.php' === $pagenow ) return;
        global $betterlinks_settings;

        $is_allow_gutenberg = isset( $betterlinks_settings['is_allow_gutenberg'] ) ? $betterlinks_settings['is_allow_gutenberg'] : false;
        $affiliate_link_disclosure = isset( $betterlinks_settings['affiliate_link_disclosure'] ) ? $betterlinks_settings['affiliate_link_disclosure'] : false;
        $enable_auto_link = isset( $betterlinks_settings['enable_auto_link'] ) ? $betterlinks_settings['enable_auto_link'] : false;

        if( !($is_allow_gutenberg || $affiliate_link_disclosure || $enable_auto_link) ) return;

        $dependencies = include_once BETTERLINKS_ASSETS_DIR_PATH . 'js/betterlinks-gutenberg.core.min.asset.php';
        wp_enqueue_style(
            'betterlinks-gutenberg',
            BETTERLINKS_ASSETS_URI . 'css/betterlinks-gutenberg.css',
            [],
            $dependencies['version']
        );

        wp_enqueue_script(
            'betterlinks-gutenberg',
            BETTERLINKS_ASSETS_URI . 'js/betterlinks-gutenberg.core.min.js',
            array_merge($dependencies['dependencies'], ['regenerator-runtime']),
            filemtime(BETTERLINKS_ASSETS_DIR_PATH . 'js/betterlinks-gutenberg.core.min.js'),
            true
        );
        
        
        $prefix = isset($betterlinks_settings['prefix']) ? $betterlinks_settings['prefix'] : '';
        wp_localize_script('betterlinks-gutenberg', 'betterLinksGlobal', [
            'post_type' => get_post_type(),
            'betterlinks_nonce' => wp_create_nonce('betterlinks_admin_nonce'),
            'nonce' => wp_create_nonce('wp_rest'),
            'rest_url' => rest_url(),
            // Needed by AJAX helpers used in the block editor (e.g. shortURLUniqueCheckGutenberg
            // in the AutoLink sidebar); without it those calls logged "ajaxurl is not defined".
            'ajaxurl' => admin_url('admin-ajax.php'),
            'namespace' => BETTERLINKS_PLUGIN_SLUG . '/v1/',
            'plugin_root_url' => BETTERLINKS_PLUGIN_ROOT_URI,
            'plugin_root_path' => BETTERLINKS_ROOT_DIR_PATH,
            'site_url' => apply_filters('betterlinks/site_url', site_url()),
            'actual_site_url' => site_url(),
            'route_path' => wp_parse_url(admin_url(), PHP_URL_PATH),
            'is_pro_enabled' => \BetterLinks\Helper::is_pro_active(),
            // Needed by pro_version_check() in the editor (e.g. the AI Link Assistant
            // 2.8.0+ gate). Without it the check falls back to its "no Pro" early-return.
            'betterlinkspro_version' => defined('BETTERLINKS_PRO_VERSION') ? BETTERLINKS_PRO_VERSION : null,
            'min_pro_version' => BETTERLINKS_MIN_PRO_VERSION,
            'prefix' => $prefix,
            'betterlinks_settings' => $betterlinks_settings,
            // Add user permission information for free version
            'user_can_manage_options' => current_user_can('manage_options'),
            // Categories a feature owns but keeps off Manage Links (the bio pages'
            // "Link in Bio", Fluent Boards' task category). The editor sidebar needs
            // the same list the dashboard query uses: a link filed under one of these
            // saves correctly but never appears on Manage Links, so Instant Redirect
            // must neither offer them nor fall back to one.
            'dashboard_hidden_term_ids' => array_values(array_unique(array_filter(array_map('intval', (array) apply_filters('betterlinks/dashboard_hidden_term_ids', array(), (array) $betterlinks_settings))))),
        ]);
        wp_set_script_translations('betterlinks-gutenberg', 'betterlinks', BETTERLINKS_ROOT_DIR_PATH . 'languages/');
    }
}
