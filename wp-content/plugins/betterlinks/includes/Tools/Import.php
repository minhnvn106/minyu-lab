<?php
namespace BetterLinks\Tools;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Import
{
    public function __construct()
    {
        add_action('admin_init', [$this, 'import_data']);
        add_action('wp_ajax_betterlinks/tools/get_import_info', [$this, 'get_import_info']);
    }
    public function import_data()
    {
        $can_access_settings = apply_filters("betterlinks/admin/" . BETTERLINKS_PLUGIN_SLUG . "-settings_menu_capability", 'manage_options');
        $nonce = isset($_GET['nonce']) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if( !wp_verify_nonce($nonce, 'betterlinks_admin_nonce') || !is_user_logged_in() || !current_user_can($can_access_settings)){
            return false;
        }
        $page = isset($_GET['page']) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $import = isset($_GET['import']) ? sanitize_text_field( wp_unslash( $_GET['import'] ) ) : false;
        if ($page === 'betterlinks-settings' && $import == true) {
            // Importing creates and overwrites links (matched by short_url), so reaching
            // the Settings screen is not enough: require the link create and update
            // permissions too. Administrators pass both by default.
            $can_write_links = apply_filters('betterlinks/api/links_create_item_permissions_check', current_user_can('manage_options'))
                && apply_filters('betterlinks/api/links_update_item_permissions_check', current_user_can('manage_options'));
            if (! $can_write_links) {
                wp_die(esc_html__("You don't have permission to import links.", 'betterlinks'), '', array('response' => 403));
            }
            \BetterLinks\Helper::clear_query_cache();
            if (!empty($_FILES['upload_file']['tmp_name'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES sanitization handled per-key below.
                $file_raw = $_FILES['upload_file'];
                $file = array(
                    'name'     => isset( $file_raw['name'] ) ? sanitize_file_name( $file_raw['name'] ) : '',
                    'tmp_name' => isset( $file_raw['tmp_name'] ) ? $file_raw['tmp_name'] : '',
                );
                $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
                if ('csv' === pathinfo($file['name'])[ 'extension' ]) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading PHP-managed temp upload; WP_Filesystem does not cover $_FILES tmp_name.
                    $fileContent = fopen($file['tmp_name'], "r");
                    if (!empty($fileContent)) {
                        $this->run_csv_importer($fileContent, $mode);
                    }
                }
            }
            do_action('betterlinks/admin/after_import_data');
        }
    }
    public function run_csv_importer($fileContent, $type = 'default')
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- caller import_data() verifies the nonce before invoking this private CSV runner.
        $results = '';
        $mode    = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
        if ($type == 'default') {
            $BetterLinks = new  Migration\BLImportCSV();
            $results = $BetterLinks->start_importing($fileContent);
            /**
             * Filters the import result messages, with rows extensions claimed through
             * betterlinks/tools/import_row_bucket and the old => new link ID map.
             * BetterLinks Pro imports link rotations here.
             *
             * @param array $results  Messages by type.
             * @param array $rows     Claimed rows by bucket.
             * @param array $link_ids Old link ID => new link ID.
             */
            $results = apply_filters('betterlinks/tools/import_process_data', $results, $BetterLinks->get_extra_rows(), $BetterLinks->get_link_id_map());
        } elseif ( $mode == 'prettylinks' ) {
            $PrettyLinks = new Migration\PTLImportCSV();
            $results = $PrettyLinks->start_importing($fileContent);
        } elseif ($type == 'thirstyaffiliates') {
            $ta_link_prefix = isset($_POST["ta_prefix"]) ? sanitize_text_field(wp_unslash( $_POST["ta_prefix"] )) : "";
            $ThirstyAffiliates = new Migration\TAImportCSV();
            $results = $ThirstyAffiliates->start_importing($fileContent, $ta_link_prefix);
        } elseif ($type == 'simple301redirects') {
            $migrator = new Migration\S30RImportCSV();
            $results = $migrator->start_importing($fileContent);
        }
        set_transient('betterlinks_import_info', json_encode($results), 60 * 60 * 5);
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function get_import_info()
    {
        // This reads *and deletes* the admin's import-status transient, so it
        // needs the same capability as the importer that writes it. The generic
        // `wp_rest` nonce it shipped with is held by every logged-in user and
        // authorized nobody. The admin bundle still sends that nonce, so accept
        // either action rather than break the Tools screen on upgrade.
        $nonce = isset($_REQUEST['security']) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : '';
        if ( ! wp_verify_nonce($nonce, 'betterlinks_admin_nonce') && ! wp_verify_nonce($nonce, 'wp_rest') ) {
            wp_send_json_error(['message' => __('Invalid nonce', 'betterlinks')], 403);
        }

        $can_access_settings = apply_filters("betterlinks/admin/" . BETTERLINKS_PLUGIN_SLUG . "-settings_menu_capability", 'manage_options');
        if ( ! current_user_can($can_access_settings) ) {
            wp_send_json_error(['message' => __('Insufficient permissions', 'betterlinks')], 403);
        }

        $results = json_encode([]);
        if (get_transient('betterlinks_import_info')) {
            \BetterLinks\Helper::clear_query_cache();
            \BetterLinks\Helper::create_cron_jobs_for_json_links();
            $results = get_transient('betterlinks_import_info');
            delete_transient('betterlinks_import_info');
        }
        wp_send_json_success($results);
        wp_die();
    }
}
