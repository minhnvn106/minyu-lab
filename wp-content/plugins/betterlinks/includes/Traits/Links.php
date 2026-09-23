<?php
namespace BetterLinks\Traits;
if ( ! defined( 'ABSPATH' ) ) { exit; }

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB

trait Links
{
    public function sanitize_links_data($POST)
    {
        $data = [];
        foreach ($this->get_links_schema() as $key => $schema) {
            if (isset($POST[$key])) {
                if (isset($schema['sanitize_callback'])) {
                    if( 'link_title' === $key ){
                        $data[$key] = $POST[$key]; // it could contain html element tags
                        continue;
                    }
                    $data[$key] = $schema['sanitize_callback']($POST[$key]);
                } elseif (isset($schema['format']) && $schema['format'] == 'date-time') {
                    $data[$key] = sanitize_text_field($POST[$key]);
                } elseif (isset($schema['type']) && $schema['type'] === 'object') {
                    $tempData = (is_array($POST[$key]) ? $POST[$key] : json_decode(html_entity_decode(stripslashes($POST[$key])), true));
                    $tempSanitizeData = [];
                    if (isset($schema['properties']) && is_array($tempData) && count($tempData) > 0) {
                        foreach ($schema['properties'] as $innerKey => $innerSchema) {
                            if ($innerSchema['type'] === 'integer' || $innerSchema['type'] === 'string') {
                                if (isset($tempData[$innerKey])) {
                                    if (isset($innerSchema['sanitize_callback'])) {
                                        $tempSanitizeData[$innerKey] = $innerSchema['sanitize_callback']($tempData[$innerKey]);
                                    } elseif (isset($innerSchema['format']) && $innerSchema['format'] == 'date-time') {
                                        $tempSanitizeData[$innerKey] = sanitize_text_field($tempData[$innerKey]);
                                    }
                                }
                            } elseif ($innerSchema['type'] === 'array') {
                                $tempTwoSanitizeData = [];
                                if (isset($tempData['value']) && is_array($tempData['value'])) {
                                    foreach ($tempData['value'] as $valueItem) {
                                        $value = [];
                                        if (is_array($valueItem)) {
                                            foreach ($valueItem as $childValueKey => $childValueItem) {
                                                $value[$childValueKey] = \BetterLinks\Helper::sanitize_text_or_array_field($childValueItem, $childValueKey);
                                            }
                                        }
                                        $tempTwoSanitizeData[] = $value;
                                    }
                                }
                                $tempSanitizeData[$innerKey] = $tempTwoSanitizeData;
                            } elseif ($innerSchema['type'] === 'object') {
                                $tempThreeSanitizeData = [];
                                if (isset($tempData['extra']) && is_array($tempData['extra'])) {
                                    foreach ($tempData['extra'] as $extraKey => $extraItem) {
                                        $tempThreeSanitizeData[$extraKey] = sanitize_text_field($extraItem);
                                    }
                                }
                                $tempSanitizeData[$innerKey] = $tempThreeSanitizeData;
                            }
                        }
                    }
                    if( 'param_struct' === $key){
                        $data[$key] = serialize($POST[$key]);
                        continue;
                    }
                    $data[$key] = $tempSanitizeData;
                } elseif ( in_array( $key, ['tags_id', 'favorite', 'analytic'] ) ) {
                    $result = (is_array($POST[$key]) ? $POST[$key] : json_decode(html_entity_decode(stripslashes($POST[$key])), true));
                    $data[$key] = \BetterLinks\Helper::sanitize_text_or_array_field($result);
                }elseif( in_array( $key, ['enable_password', 'password', 'enable_custom_scripts'] ) ) { // password protected parameters
                    $data[$key] = \BetterLinks\Helper::sanitize_text_or_array_field($POST[$key]);
                }elseif( 'custom_tracking_scripts' === $key){
                    $data[$key] = $POST[$key]; // it contains javascript code
                }
            }
        }
        return $data;
    }
    /**
     * Gate a create/update payload before it reaches the database.
     *
     * Both the REST controller and the admin-ajax fallback that the React app
     * falls back to when REST is unavailable go through here, so a short_url is
     * validated the same way whichever transport carried it.
     *
     * Returns a WP_Error describing the rejection, or null when the payload is
     * safe to write.
     *
     * @param array $args            Sanitized link payload.
     * @param bool  $is_update       Whether this is an update of an existing row.
     * @param int   $allowed_post_id Post whose own permalink this write is allowed
     *                               to shadow (Instant Redirect). 0 for none.
     * @param bool  $allow_override  Whether the user confirmed that this link may
     *                               take over WordPress content at its path (the
     *                               link form's "Redirect this path anyway").
     *                               Honoured only for users allowed to — see
     *                               can_override_wp_url_collision().
     * @return \WP_Error|null
     */
    public function validate_link_payload($args, $is_update = false, $allowed_post_id = 0, $allow_override = false)
    {
        if (!isset($args['short_url']) || '' === (string) $args['short_url']) {
            return null;
        }
        $short_url = (string) $args['short_url'];
        $id        = isset($args['ID']) ? absint($args['ID']) : 0;

        if ($is_update && $id > 0) {
            $current_row = \BetterLinks\Helper::get_link_by_ID($id);
            $current     = is_array($current_row) && !empty($current_row) ? current($current_row) : null;
            $current_url = is_array($current) && isset($current['short_url']) ? (string) $current['short_url'] : '';
            // A no-op edit (title, target, category…) resubmits the stored
            // short_url untouched. Nothing is changing, so nothing to validate —
            // and validating anyway would reject links that predate this check.
            if ($short_url === $current_url) {
                return null;
            }
            // insert_link() refuses a duplicate short_url on create, but the
            // update branch never did, so two rows could end up owning the same
            // path and one would silently win the links.json entry.
            $owner = \BetterLinks\Helper::get_link_by_short_url($short_url);
            foreach ((array) $owner as $row) {
                if (isset($row['ID']) && absint($row['ID']) !== $id) {
                    return new \WP_Error(
                        'betterlinks_duplicate_short_url',
                        sprintf(
                            /* translators: %s: the short URL that is already taken */
                            __('Another link already uses the short URL "%s". Short URLs have to be unique.', 'betterlinks'),
                            $short_url
                        ),
                        ['status' => 409]
                    );
                }
            }
        }

        if (!$is_update) {
            // insert_link() simply returns nothing when the short URL is taken,
            // and the REST controller turned that into `success: false, data:
            // false` with no reason — the caller could not tell a duplicate slug
            // from any other failure. Name the conflict here instead, so REST,
            // admin-ajax and MCP all answer the same way.
            $owner = \BetterLinks\Helper::get_link_by_short_url($short_url);
            $owner = is_array($owner) && !empty($owner) ? current($owner) : null;
            if (is_array($owner) && isset($owner['ID'])) {
                return new \WP_Error(
                    'betterlinks_duplicate_short_url',
                    sprintf(
                        /* translators: 1: the short URL that is already taken, 2: ID of the link that holds it */
                        __('A link with the short URL "%1$s" already exists (ID %2$d). Short URLs have to be unique.', 'betterlinks'),
                        $short_url,
                        absint($owner['ID'])
                    ),
                    ['status' => 409, 'conflicting_link_id' => absint($owner['ID'])]
                );
            }
        }

        // Short URLs end up inside href attributes (AutoLinks, the block editor,
        // exports). Quotes, angle brackets and whitespace have no place in a URL
        // path and would let a slug break out of the attribute. Unchanged slugs
        // returned early above, so existing links are not affected.
        if (preg_match('/[\s"\'<>`]/u', $short_url)) {
            return new \WP_Error(
                'betterlinks_invalid_short_url',
                __('Short URLs cannot contain spaces, quotes or angle brackets.', 'betterlinks'),
                ['status' => 400]
            );
        }

        $collision = \BetterLinks\Helper::check_wp_url_collision($short_url, $allowed_post_id);
        if (is_wp_error($collision)) {
            if ($allow_override && $this->can_override_wp_url_collision($collision)) {
                return null;
            }
            return $collision;
        }
        return null;
    }

    /**
     * The post an Instant Redirect write claims to belong to, or 0.
     *
     * The block editor sends `instant_redirect_post_id` alongside the link
     * payload when the Instant Redirect sidebar saves, because that panel
     * deliberately registers the post's own permalink as a short URL and would
     * otherwise be refused by the WP URL collision check.
     *
     * It is caller-supplied, and all it does is relax that check for one
     * specific path, so it only counts when the current user may actually edit
     * the post in question — otherwise it is a way to shadow someone else's
     * page.
     *
     * @param array $source Raw (unsanitized) request payload.
     * @return int
     */
    public function resolve_instant_redirect_post_id($source)
    {
        if (!is_array($source) || !isset($source['instant_redirect_post_id'])) {
            return 0;
        }
        $post_id = absint($source['instant_redirect_post_id']);
        if ($post_id < 1 || !get_post($post_id)) {
            return 0;
        }
        return current_user_can('edit_post', $post_id) ? $post_id : 0;
    }

    /**
     * Whether the request asks to keep a short URL that shadows WordPress content.
     *
     * The link form sends `allow_wp_url_override` alongside the link payload once
     * the user ticks "Redirect this path anyway" — for example to send a docs
     * archive to its welcome article, which is a redirect people set up on
     * purpose. Like `instant_redirect_post_id` it is request context, not link
     * data, so it never reaches the links table.
     *
     * @param array $source Raw (unsanitized) request payload.
     * @return bool
     */
    public function resolve_wp_url_override($source)
    {
        return is_array($source) && isset($source['allow_wp_url_override']) && wp_validate_boolean($source['allow_wp_url_override']);
    }

    /**
     * Whether the current user may confirm a WP URL collision and save anyway.
     *
     * System paths are never overridable. Shadowing a post or page needs the
     * right to edit that post, the same rule Instant Redirect follows; anything
     * else (a taxonomy, post type, author or date archive) affects the whole
     * site, so it needs `manage_options`.
     *
     * @param \WP_Error $error Result of Helper::check_wp_url_collision().
     * @return bool
     */
    public function can_override_wp_url_collision($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }
        $data = $error->get_error_data();
        if (!is_array($data) || empty($data['overridable'])) {
            return false;
        }
        $post_id = isset($data['conflicting_post_id']) ? absint($data['conflicting_post_id']) : 0;
        $allowed = $post_id > 0 ? current_user_can('edit_post', $post_id) : current_user_can('manage_options');
        /**
         * Filters whether the current user may save a short URL over WordPress
         * content after confirming it. Return false to keep every collision a
         * hard block.
         *
         * @param bool      $allowed Whether the override is allowed.
         * @param \WP_Error $error   The collision being overridden.
         */
        return (bool) apply_filters('betterlinks/allow_wp_url_collision_override', $allowed, $error);
    }

    public function insert_link($arg)
    {
        if (isset($arg['short_url']) && ! \BetterLinks\Helper::is_exists_short_url($arg['short_url'])) {
            // Start Transaction
            global $wpdb;
            $wpdb->query("START TRANSACTION");
            $lookFor = array_combine(array_keys($this->links_schema()), array_keys($this->links_schema()));
            $params = array_intersect_key($arg, $lookFor);
            // insert link
            $id = \BetterLinks\Helper::insert_link(apply_filters('betterlinks/api/params', $params));
            $term_data = \BetterLinks\Helper::insert_terms_and_terms_relationship($id, $arg);
            $wpdb->query("COMMIT");

            // Initialize category data with default fallback
            $arg['cat_id'] = isset($arg['cat_id']) ? $arg['cat_id'] : 1; // Default to Uncategorized
            $arg['tags_data'] = isset($arg['tags_data']) ? $arg['tags_data'] : [];

            // for instant create category system
            foreach ($term_data as $key => $value) {
                if(empty($value["term_type"])){
                    continue;
                }
                if($value["term_type"] === "tags"){
                    $arg['tags_data'][] = $value;
                }
                if($value["term_type"] === "category"){
                    $arg['cat_id'] = $value["term_id"];
                    $arg['cat_data'] = $value;
                }
            }
            /**
             * Filters the target URL of a link right after it is created.
             * Return a different URL to rewrite it, or null to keep it
             * (BetterLinks Pro applies global UTM templates here).
             *
             * @param string|null $target_url Replacement target URL.
             * @param int         $id         New link ID.
             * @param array       $arg        Link data, with resolved cat_id.
             */
            $updated_target_url = apply_filters('betterlinks/link/auto_target_url', null, $id, $arg);
            if (is_string($updated_target_url) && '' !== $updated_target_url && isset($arg['target_url']) && $updated_target_url !== $arg['target_url']) {
                $updated_target_url = esc_url_raw($updated_target_url);
                $wpdb->update($wpdb->prefix . 'betterlinks', array('target_url' => $updated_target_url), array('ID' => $id), array('%s'), array('%d'));
            } else {
                $updated_target_url = null;
            }

            if (BETTERLINKS_EXISTS_LINKS_JSON) {
                $params['ID'] = $id;
                $params['cat_id'] = $arg['cat_id'];
                if ($updated_target_url) {
                    $params['target_url'] = $updated_target_url;
                }
                
                \BetterLinks\Helper::insert_json_into_file(trailingslashit(BETTERLINKS_UPLOAD_DIR_PATH) . 'links.json', $params);
                
                // Sync missing links when new link is created (including when duplicating)
                \BetterLinks\Helper::sync_all_missing_links_to_json();
            }
            
            do_action( 'betterlinkspro/admin/update_link', $id, $arg  );

            $response = array_merge($arg, [
                'ID' => strval($id),
            ]);
            
            // Update response with the UTM-enhanced URL if it was modified
            if (isset($updated_target_url) && $updated_target_url && $updated_target_url !== $arg['target_url']) {
                $response['target_url'] = $updated_target_url;
            }
            
            if( !empty( $response['param_struct'] ) ){
                $response['param_struct'] = unserialize($response['param_struct'], array('allowed_classes' => false));
            }
            // Invalidate the dashboard cache *after* the row exists. Callers also
            // clear it before writing, but that alone leaves a window: the
            // transient is stored without a TTL, so any read landing between the
            // pre-write clear and this insert would repopulate it from a table
            // that does not have the new link yet and keep serving that snapshot
            // forever — a link that saved fine but never appears in Manage Links.
            delete_transient(BETTERLINKS_CACHE_LINKS_NAME);
            return $response;
        }
        return false;
    }
    public function update_link($arg)
    {
        
        // Start Transaction
        global $wpdb;
        $wpdb->query("START TRANSACTION");
        $lookFor = array_combine(array_keys($this->links_schema()), array_keys($this->links_schema()));
        $params = array_intersect_key($arg, $lookFor);
        
        $old_short_url = isset($arg['old_short_url']) ? $arg['old_short_url'] : '';
        // update link
        $id = \BetterLinks\Helper::insert_link(apply_filters('betterlinks/api/params', $params), true);

        // Only rewrite term relationships when the caller actually sent term data.
        // insert_terms_and_terms_relationship() falls back to the default category
        // (Uncategorized) whenever cat_id is empty, so running it for a payload that
        // never mentioned terms — e.g. the bulk status change, which posts only
        // {ID, link_status} — silently moved the link out of its category.
        $has_term_payload = isset($arg['cat_id']) || isset($arg['tags_id']);
        if ($has_term_payload) {
            // That function rebuilds the link's term relationships from scratch:
            // it deletes every one of them, then inserts what the payload names.
            // So a payload mentioning only one side silently dropped the other —
            // changing a link's category wiped its tags, and setting tags moved
            // the link to the default category. Carry the unmentioned side over
            // from what is stored. The admin form always sends both, so this only
            // changes the outcome for partial writes (MCP, REST, bulk actions).
            if (!isset($arg['cat_id'])) {
                $existing_cat = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type($id, 'category');
                if (!empty($existing_cat) && isset($existing_cat[0]['term_id'])) {
                    $arg['cat_id'] = $existing_cat[0]['term_id'];
                }
            }
            if (!isset($arg['tags_id'])) {
                $existing_tags = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type($id, 'tags');
                if (!empty($existing_tags)) {
                    $arg['tags_id'] = array_values(array_filter(wp_list_pluck($existing_tags, 'term_id')));
                }
            }
        }
        $term_data = $has_term_payload
            ? \BetterLinks\Helper::insert_terms_and_terms_relationship($id, $arg)
            : array();

        $wpdb->query("COMMIT");

        if (!$has_term_payload) {
            // Nothing was rewritten; carry the link's existing category forward so the
            // JSON cache below is not rebuilt with the wrong (default) category.
            $existing_cat = \BetterLinks\Helper::get_terms_by_link_ID_and_term_type($id, 'category');
            if (!empty($existing_cat) && isset($existing_cat[0]['term_id'])) {
                $arg['cat_id']   = $existing_cat[0]['term_id'];
                $arg['cat_data'] = $existing_cat[0];
            }
        }

        // Initialize category data with default fallback
        $arg['cat_id'] = isset($arg['cat_id']) ? $arg['cat_id'] : 1; // Default to Uncategorized
        $arg['tags_data'] = isset($arg['tags_data']) ? $arg['tags_data'] : [];

        foreach ($term_data as $key => $value) {
            if(empty($value["term_type"])){
                continue;
            }
            if($value["term_type"] === "tags"){
                $arg['tags_data'][] = $value;
            }
            if($value["term_type"] === "category"){
                $arg['old_cat_id'] = isset($arg['cat_id']) ? $arg['cat_id'] : 1;
                $arg['cat_id'] = $value["term_id"];
                $arg['cat_data'] = $value;
            }
        }
        if (BETTERLINKS_EXISTS_LINKS_JSON) {
            // Cache the row as stored, not the payload as sent. Helper::insert_link()
            // merges a partial update over the existing row, so a client that sends
            // only what it changed (MCP update-link, the bulk status change, any
            // REST caller posting a diff) left this cache — which is what the
            // redirect actually reads — stale, or skipped it altogether because
            // update_json_into_file() bails when short_url is absent.
            $stored      = \BetterLinks\Helper::get_link_by_ID($id);
            $stored      = is_array($stored) && !empty($stored) ? (array) current($stored) : array();
            $json_params = !empty($stored) ? array_merge($stored, $params) : $params;

            $json_params['cat_id'] = $arg['cat_id'];
            \BetterLinks\Helper::update_json_into_file(trailingslashit(BETTERLINKS_UPLOAD_DIR_PATH) . 'links.json', $json_params, $old_short_url);
            
            // Sync missing links when link is updated
            \BetterLinks\Helper::sync_all_missing_links_to_json();
        }

        do_action( 'betterlinkspro/admin/update_link', $id, $arg );

        if( !empty( $arg['param_struct'] ) ){
            $arg['param_struct'] = unserialize($arg['param_struct'], array('allowed_classes' => false));
        }
        // See insert_link(): clear once more now the write is committed, so a
        // concurrent read cannot leave a permanent pre-write snapshot behind.
        delete_transient(BETTERLINKS_CACHE_LINKS_NAME);
        return $arg;
    }
    public function update_link_favorite($args)
    {
        if (isset($args["ID"], $args["data"])) {
            $id = absint($args["ID"]);
            $data = wp_json_encode($args["data"]);
            global $wpdb;
            $table = $wpdb->prefix . 'betterlinks';
            return $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table
                    SET favorite = %s
                    WHERE ID = %d LIMIT 1",
                    $data,
                    $id
                )
            );
        }
    }
    public function delete_link($args)
    {
        if ( ! isset( $args['ID'] ) ) {
            return false;
        }
        delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
        \BetterLinks\Helper::delete_link($args['ID']);
        if (BETTERLINKS_EXISTS_LINKS_JSON && isset($args['short_url'])) {
            \BetterLinks\Helper::delete_json_into_file(trailingslashit(BETTERLINKS_UPLOAD_DIR_PATH) . 'links.json', $args['short_url']);
        }
        // See insert_link(): clear again now the row is gone, so a read racing
        // the delete cannot pin a snapshot that still contains it.
        delete_transient( BETTERLINKS_CACHE_LINKS_NAME );
        return true;
    }

}
