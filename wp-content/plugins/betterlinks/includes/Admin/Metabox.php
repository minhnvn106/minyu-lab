<?php

namespace BetterLinks\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Metabox {

    private $is_pro_enabled;
    public static function init() {
        $self = new self();
        $self->is_pro_enabled = \BetterLinks\Helper::is_pro_active();
        add_action('add_meta_boxes', [$self, 'add_auto_create_shortlink_teaser'], 10, 2);
        add_action('add_meta_boxes', [$self, 'add_affiliate_disclosure_teaser'], 10, 2);
        add_action('add_meta_boxes', [$self, 'add_ai_link_assistant_teaser'], 10, 2);
    }

    /**
     * The AI Link Assistant ships in BetterLinks Pro 2.8.0+. Free users and older
     * Pro builds get the locked teaser metabox instead of the real feature.
     */
    private function is_link_genius_available() {
        return $this->is_pro_enabled
            && defined('BETTERLINKS_PRO_VERSION')
            && version_compare(BETTERLINKS_PRO_VERSION, '2.8.0', '>=');
    }

    public function add_ai_link_assistant_teaser( $post_type, $post ) {
        if ( ! $this->is_link_genius_available() && ! $this->is_using_gutenberg_block() && in_array( $post_type, ['post', 'page'] ) ) {
            // Existing Pro users (on an older build) own Pro — no "Pro" badge for them.
            $title = $this->is_pro_enabled
                ? esc_html__( 'AI Link Assistant', 'betterlinks' )
                : esc_html__( 'AI Link Assistant', 'betterlinks' ) . '<span class="pro-badge">' . esc_html__( 'Pro', 'betterlinks' ) . '</span>';
            add_meta_box('betterlinks-ai-link-assistant-teaser', $title, [$this, 'ai_link_assistant_teaser'], $post_type, 'side', 'core');
        }
    }

    public function ai_link_assistant_teaser() {
        // Existing Pro user on an older build → prompt to update; free user → upsell.
        $is_old_pro = $this->is_pro_enabled;
        $badge      = $is_old_pro ? '' : '<span class="pro-badge">' . esc_html__( 'Pro', 'betterlinks' ) . '</span>';
        $cta_url    = $is_old_pro ? self_admin_url( 'update-core.php' ) : 'https://wpdeveloper.com/in/upgrade-betterlinks';
        $cta_label  = $is_old_pro ? __( 'Update BetterLinks Pro', 'betterlinks' ) : __( 'Upgrade to PRO', 'betterlinks' );
        $cta_target = $is_old_pro ? '_self' : '_blank';
        ?>
        <div class="betterlinks-ai-link-assistant">
            <p><?php esc_html_e( 'Get smart, AI-powered link suggestions ranked by your own click data while you write.', 'betterlinks' ); ?></p>
            <?php if ( $is_old_pro ) : ?>
                <p class="betterlinks-ai-link-assistant__note">
                    <?php
                    printf(
                        /* translators: %s: minimum required BetterLinks Pro version. */
                        esc_html__( 'Requires BetterLinks Pro v%s or greater. Please update to use it.', 'betterlinks' ),
                        '2.8.0'
                    );
                    ?>
                </p>
            <?php endif; ?>
            <div class="betterlinks-form-group betterlinks-form-flex">
                <label>
                    <?php esc_html_e( 'Smart link suggestions', 'betterlinks' ); ?>
                    <?php echo wp_kses_post( $badge ); ?>
                </label>
                <input type="text" placeholder="<?php esc_attr_e( 'e.g. “link management” → /go/link-management', 'betterlinks' ); ?>" disabled />
            </div>
            <div class="betterlinks-form-group betterlinks-form-flex">
                <label>
                    <?php esc_html_e( 'Auto-detect &amp; cloak raw URLs', 'betterlinks' ); ?>
                    <?php echo wp_kses_post( $badge ); ?>
                </label>
                <input type="text" placeholder="www.example.com → /go/example" disabled />
            </div>
            <a class="betterlinks-ai-link-assistant__cta" href="<?php echo esc_url( $cta_url ); ?>" target="<?php echo esc_attr( $cta_target ); ?>" rel="noopener noreferrer">
                <?php echo esc_html( $cta_label ); ?>
            </a>
        </div>
        <style>
            .betterlinks-ai-link-assistant .betterlinks-form-group {
                margin-bottom: 1rem;
            }
            .betterlinks-ai-link-assistant .betterlinks-form-flex {
                display: flex;
                flex-direction: column;
            }
            .betterlinks-ai-link-assistant input {
                width: 100%;
                cursor: not-allowed;
                box-sizing: border-box;
            }
            .betterlinks-ai-link-assistant__note {
                margin: 0 0 1rem;
                padding: 8px 10px;
                border-radius: 4px;
                background: #FFF8F1;
                border: 1px solid #FFE7CC;
                color: #92400e;
                font-size: 12px;
                line-height: 1.5;
            }
            .betterlinks-ai-link-assistant__cta {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                box-sizing: border-box;
                padding: 9px 18px;
                margin-top: 4px;
                border-radius: 6px;
                background: linear-gradient(202deg, #2961ff 0%, #003be2 100%);
                color: #fff;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
            }
            .betterlinks-ai-link-assistant__cta:hover {
                color: #fff;
                opacity: 0.92;
            }
            .betterlinks-ai-link-assistant span.pro-badge {
                background: linear-gradient(202deg, #2961ff 0%, #003be2 100%);
                color: #fff;
                border-radius: 2px;
                padding: 3px 6px;
                font-size: 10px;
                margin-left: 3px;
                line-height: 1;
                text-transform: uppercase;
                display: inline-flex;
                align-items: center;
                transform: translateY(-10px);
            }
        </style>
        <?php
    }

    public function add_affiliate_disclosure_teaser( $post_type, $post ) {
        if( !$this->is_pro_enabled && !$this->is_using_gutenberg_block() && in_array( $post_type, ['post', 'page'])) {
            add_meta_box('betterlinks-affiliate-disclosure-teaser', __('BetterLinks Affiliate Disclosure<span class="pro-badge">Pro</span>', 'betterlinks'), [$this, 'affiliate_disclosure_teaser'], $post_type, 'side', 'core');
        }
    }

    public function affiliate_disclosure_teaser($post) {
        ?>
         <div>
            <p>
                <?php
                /* translators: %s: post type slug (post, page, etc.) */
                printf( esc_html__( 'This will allow you to add an Affiliate Link Disclosure in this %s', 'betterlinks' ), esc_html( $post->post_type ) );
                ?>
            </p>
            <div class="betterlinks-affiliate-link-disclosure">
            <div class="betterlinks-form-group betterlinks-form-flex">
                <label>
                    <?php esc_html_e('Affiliate Disclosure Position', 'betterlinks') ?>
                    <span class="pro-badge">
                        <?php esc_html_e('Pro', 'betterlinks') ?>
                    </span>
                </label>
                <select name="betterlinks_affiliate_disclosure_position" style="width: 100%;cursor: not-allowed;" disabled>
                    <option value="">Select Position</option>
                </select>
            </div>
            <div class="betterlinks-form-group betterlinks-form-flex">
                <label>
                    <?php esc_html_e('Affiliate Disclosure Text', 'betterlinks') ?>
                    <span class="pro-badge">
                        <?php esc_html_e('Pro', 'betterlinks') ?>
                    </span>
                </label>
                <textarea name="affiliate_disclosure_text" style="width: 100%;" disabled></textarea>
            </div>
            </div>
            <div>
            </div>
        </div>
        <style>
            .betterlinks-form-group {
                margin-bottom:1rem;
            }
            .betterlinks-form-group input,
            .betterlinks-form-group textarea {
                cursor: not-allowed;
            }
            .betterlinks-form-flex {
                display: flex;
                flex-direction: column;
            }
            span.pro-badge {
                background: linear-gradient(202deg,#2961ff 0%,#003be2 100%);
                color: #fff;
                border-radius: 2px;
                padding: 3px 6px;
                font-size: 10px;
                margin-left: 3px;
                line-height: 1;
                text-transform: uppercase;
                display: inline-flex;
                align-items: center;
                border-radius: 2px;
                transform: translateY(-10px);
            }
        </style>
        <?php
    }

    public function add_auto_create_shortlink_teaser( $post_type, $post ) {
        if( !$this->is_pro_enabled && !$this->is_using_gutenberg_block() && in_array( $post_type, ['post', 'page', 'product'])) {
            add_meta_box('betterlinks-auto-create-shortlink-teaser', __('BetterLinks Auto-Create Links<span class="pro-badge">Pro</span>', 'betterlinks'), [$this, 'auto_create_shortlink_teaser'], $post_type, 'side', 'core');
        }
    }

    public function auto_create_shortlink_teaser() {
        ?>  
        <div>
            <p><?php esc_html_e( 'A BetterLink for this post will be generated on publish', 'betterlinks' ) ?></p>
            <div class="betterlinks_auto_create_link_form">
                <div class="betterlinks-form-group">
                    <label>
                        <?php echo esc_html( site_url() . '/' ); ?>
                        <span class="pro-badge">
                            <?php esc_html_e('Pro', 'betterlinks') ?>
                        </span>
                    </label>
                    <div style="display: flex; align-items: center;justify-content: space-between;">
                        <input 
                            type="text" 
                            name="betterlinks_auto_create_shortlinks" 
                            id="betterlinks_auto_create_shortlinks"
                            disabled
                        />
                    </div>
                </div>
                <div class="betterlinks-form-group betterlinks-form-flex">
                    <label>
                        <?php esc_html_e('BetterLinks Category', 'betterlinks') ?>
                        <span class="pro-badge">
                            <?php esc_html_e('Pro', 'betterlinks') ?>
                        </span>
                    </label>
                    <select name="betterlinks_auto_link_category" disabled style="cursor: not-allowed;">
                        <option value="">Select Category</option>
                    </select>
                </div>
                <div class="betterlinks-form-group betterlinks-form-flex">
                    <label>
                        <?php esc_html_e('Redirect Type', 'betterlinks') ?>
                        <span class="pro-badge">
                            <?php esc_html_e('Pro', 'betterlinks') ?>
                        </span>
                    </label>
                    <select name="betterlinks_auto_link_redirect_type" disabled style="cursor: not-allowed;">
                        <option value="">Select Type</option>
                    </select>
                </div>
            </div>
        </div>
        <style>
            .betterlinks-form-group {
                margin-bottom:1rem;
            }
            .betterlinks-form-group input {
                cursor: not-allowed;
            }
            .betterlinks-form-flex {
                display: flex;
                flex-direction: column;
            }
            #betterlinks_auto_create_shortlinks{
                flex-grow: 1;
            }
            span.pro-badge {
                background: linear-gradient(202deg,#2961ff 0%,#003be2 100%);
                color: #fff;
                border-radius: 2px;
                padding: 3px 6px;
                font-size: 10px;
                margin-left: 3px;
                line-height: 1;
                text-transform: uppercase;
                display: inline-flex;
                align-items: center;
                border-radius: 2px;
                transform: translateY(-10px);
            }
        </style>
        <?php
    }

    public function is_using_gutenberg_block() {
        $current_screen = get_current_screen();
        $is_using_block_editor = $current_screen->is_block_editor || (function_exists( 'is_gutenberg_page' ) && is_gutenberg_page());
        return $is_using_block_editor;
    }
}
