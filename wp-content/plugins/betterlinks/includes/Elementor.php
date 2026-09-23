<?php

namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use Elementor\Controls_Manager;
use Elementor\Plugin;

/**
 * Summery - BetterLinks widgets for Elementor Page Builder
 */
class Elementor {
	use \BetterLinks\Traits\Links;
	use \BetterLinks\Traits\Terms;
	use \BetterLinks\Traits\ArgumentSchema;

	/**
	 * Initializeing actions
	 */
	public function __construct() {
		add_action( 'betterlinks/pre_before_redirect', array( $this, 'disable_elementor_preview_redirect' ) );

		if ( $this->bl_get_link_options( 'is_allow_gutenberg' ) ) {
			add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'elementor_editor_assets' ) );
			add_action( 'elementor/documents/register_controls', array( $this, 'instant_redirect_controls' ) );
			add_action( 'elementor/editor/after_save', array( $this, 'handle_instant_redirect_data' ), 10 );
			add_filter( 'elementor/document/save/data', array( $this, 'handle_page_data' ) );
		}
	}

	/**
	 * Enqueue Elementor Editor Page's Assets
	 *
	 * @return void
	 */
	public function elementor_editor_assets() {
		wp_enqueue_style( 'betterlinks-elementor-editor', BETTERLINKS_ASSETS_URI . 'css/elementor.css', array(), BETTERLINKS_VERSION );
	}

	/**
	 * Get Link Options
	 *
	 * @param string $option_name - Option name.
	 * @return array
	 */
	public function bl_get_link_options( $option_name = null ) {
		$links_option = json_decode( get_option( BETTERLINKS_LINKS_OPTION_NAME ), true );

		if ( $option_name ) {
			if ( isset( $links_option[ $option_name ] ) ) {
				return $links_option[ $option_name ];
			}

			return '';
		}

		return $links_option;
	}

	/**
	 * Returns Category Options
	 *
	 * @param boolean $first_index - First Index.
	 * @return array - Options
	 */
	public function bl_get_category_options( $first_index = false ) {
		$terms   = $this->get_all_terms_data( null );
		$options = array();
		$index   = 0;
		foreach ( (array) $terms as $term ) {
			if ( 'tags' === $term['term_type'] ) {
				continue;
			}
			if ( $first_index && 0 === $index ) {
				++$index;
				return $term['ID'];
			}
			$options[ $term['ID'] ] = $term['term_name'];
		}

		return $options;
	}

	/**
	 * Returns the short links from permalink
	 *
	 * @param string|integer $page_id - the page id.
	 * @return string - the permalink.
	 */
	public function gen_short_link_from_permalink( $page_id ) {
		$permalink = get_permalink( $page_id );
		$permalink = str_replace( site_url( '/' ), '', $permalink );

		if ( substr( $permalink, - 1 ) === '/' ) {
			$permalink = substr_replace( $permalink, '', - 1 );
		}

		return $permalink;
	}

	/**
	 * Returns slug
	 *
	 * @param string $title - Title of the short link.
	 * @return string - hyphenized slug
	 */
	public function gen_slug_from_title( $title ) {
		$string = strtolower( $title );
		$string = preg_replace( '/-+/', '', $string );
		$string = preg_replace( '/\s+/', '-', $string );
		$string = preg_replace( '/[^a-z0-9-]/', '', $string );

		return $string;
	}

	/**
	 * Description
	 *
	 * @param Any $data - Data.
	 */
	public function disable_elementor_preview_redirect( $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detecting Elementor preview mode, no state mutation.
		$elementor_preview = isset( $_GET['elementor-preview'] );

		if ( $elementor_preview ) {
			return false;
		}

		return $data;
	}

	/**
	 * Instant redirect controls
	 *
	 * @param Object $controls - Controls.
	 */
	public function instant_redirect_controls( $controls ) {
		$controls->start_controls_section(
			'bl_instant_redirect_section',
			array(
				'label' => sprintf( '<i class="btl btl-logo"></i> %s', __( 'BetterLinks Instant Redirect', 'betterlinks' ) ),
				'tab'   => Controls_Manager::TAB_SETTINGS,
			)
		);

		$controls->add_control(
			'bl_ir_active',
			array(
				'label'        => esc_html__( 'Enable Instant Redirect', 'betterlinks' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'betterlinks' ),
				'label_off'    => esc_html__( 'Off', 'betterlinks' ),
				'return_value' => 'yes',
				'default'      => '',
			)
		);

		$controls->add_control(
			'bl_ir_target_url',
			array(
				'type'      => Controls_Manager::TEXT,
				'label'     => __( 'Target URL', 'betterlinks' ),
				'condition' => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		$controls->add_control(
			'bl_ir_redirect_type',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => __( 'Redirect Type', 'betterlinks' ),
				'default'   => $this->bl_get_link_options( 'redirect_type' ),
				'options'   => array(
					'307'   => esc_html__( '307 (Temporary)', 'betterlinks' ),
					'302'   => esc_html__( '302 (Temporary)', 'betterlinks' ),
					'301'   => esc_html__( '301 (Permanent)', 'betterlinks' ),
					'cloak' => esc_html__( 'Cloaked', 'betterlinks' ),
				),
				'condition' => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		$controls->add_control(
			'bl_ir_link_category',
			array(
				'type'      => Controls_Manager::SELECT,
				'label'     => __( 'Choose Category', 'betterlinks' ),
				'default'   => $this->bl_get_category_options( true ),
				'options'   => $this->bl_get_category_options(),
				'condition' => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		$controls->add_control(
			'bl_ir_link_options_heading',
			array(
				'type'      => Controls_Manager::HEADING,
				'label'     => __( 'Link Options', 'betterlinks' ),
				'condition' => array(
					'bl_ir_active' => 'yes',
				),
				'separator' => 'before',
			)
		);

		$controls->add_control(
			'bl_ir_link_options_nofollow',
			array(
				'label'        => esc_html__( 'No Follow', 'betterlinks' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'betterlinks' ),
				'label_off'    => esc_html__( 'Off', 'betterlinks' ),
				'return_value' => 'yes',
				'default'      => $this->bl_get_link_options( 'nofollow' ) === true ? 'yes' : '',
				'condition'    => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		$controls->add_control(
			'bl_ir_link_options_sponsored',
			array(
				'label'        => esc_html__( 'Sponsored', 'betterlinks' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'betterlinks' ),
				'label_off'    => esc_html__( 'Off', 'betterlinks' ),
				'return_value' => 'yes',
				'default'      => $this->bl_get_link_options( 'sponsored' ) === true ? 'yes' : '',
				'condition'    => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		$controls->add_control(
			'bl_ir_link_options_parameter_forwarding',
			array(
				'label'        => esc_html__( 'Parameter Forwarding', 'betterlinks' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'betterlinks' ),
				'label_off'    => esc_html__( 'Off', 'betterlinks' ),
				'return_value' => 'yes',
				'default'      => $this->bl_get_link_options( 'param_forwarding' ) === true ? 'yes' : '',
				'condition'    => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		$controls->add_control(
			'bl_ir_link_options_tracking',
			array(
				'label'        => esc_html__( 'Tracking', 'betterlinks' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => esc_html__( 'On', 'betterlinks' ),
				'label_off'    => esc_html__( 'Off', 'betterlinks' ),
				'return_value' => 'yes',
				'default'      => $this->bl_get_link_options( 'track_me' ) === true ? 'yes' : '',
				'condition'    => array(
					'bl_ir_active' => 'yes',
				),
			)
		);

		if ( ! class_exists( 'BetterLinksPro' ) ) {
			$controls->add_control(
				'bl_ir_adv_protext',
				array(
					'type'      => Controls_Manager::RAW_HTML,
					'condition' => array(
						'bl_ir_active' => 'yes',
					),
					/* translators: %s: URL to the BetterLinks Pro upgrade page. */
					'raw'       => sprintf( __( 'Get the <a href="%s" target="_blank" style="color: red;">PRO</a> version for more advanced link redirection features & many more!', 'betterlinks' ), 'https://wpdeveloper.com/in/upgrade-betterlinks' ),
				)
			);
		}

		do_action( 'betterlinks/elementor/controllers/before-end', $controls );

		$controls->end_controls_section();
	}

	/**
	 * Handle instant redirect data
	 *
	 * @param string|integer $post_id - ID of the post.
	 */
	public function handle_instant_redirect_data( $post_id ) {
		if ( wp_doing_cron() || get_post_type( $post_id ) === 'revision' ) {
			return;
		}

		$document              = Plugin::$instance->documents->get( $post_id, false );
		$current_time          = current_time( 'U' );
		$current_gmt_time      = time();
		$title                 = $document->get_settings( 'post_title' );
		$shortLink             = $this->gen_short_link_from_permalink( $post_id );
		$link                  = Helper::get_link_by_short_url( $shortLink );
		$link_id               = isset( $link[0]['ID'] ) ? $link[0]['ID'] : 'undefined';
		$is_active             = $document->get_settings( 'bl_ir_active' );
		$redirect_type         = $document->get_settings( 'bl_ir_redirect_type' );
		$instant_redirect_data = array(
			'ID'                => $link_id,
			'target_url'        => $document->get_settings( 'bl_ir_target_url' ),
			'cat_id'            => $document->get_settings( 'bl_ir_link_category' ),
			'redirect_type'     => ! empty( $redirect_type ) ? $redirect_type : '307',
			'nofollow'          => $document->get_settings( 'bl_ir_link_options_nofollow' ) === 'yes' ? 1 : '',
			'param_forwarding'  => $document->get_settings( 'bl_ir_link_options_parameter_forwarding' ) === 'yes' ? 1 : '',
			'sponsored'         => $document->get_settings( 'bl_ir_link_options_sponsored' ) === 'yes' ? 1 : '',
			'track_me'          => $document->get_settings( 'bl_ir_link_options_tracking' ) === 'yes' ? 1 : '',
			'link_slug'         => $this->gen_slug_from_title( $title ),
			'link_title'        => $title,
			'short_url'         => $shortLink,
			'link_date'         => gmdate( 'Y-m-d H:i:s', $current_time ),
			'link_date_gmt'     => gmdate( 'Y-m-d H:i:s', $current_gmt_time ),
			'link_modified'     => gmdate( 'Y-m-d H:i:s', $current_time ),
			'link_modified_gmt' => gmdate( 'Y-m-d H:i:s', $current_gmt_time ),
		);

		$instant_redirect_data = apply_filters( 'betterlinks/elementor/editor/after_save_data', $instant_redirect_data, $document );

		if ( empty( $instant_redirect_data['target_url'] ) ) {
			return;
		}

		if ( 'undefined' === $link_id && 'yes' === $is_active ) {
			$args = $this->sanitize_links_data( $instant_redirect_data );
			$this->insert_link( $args );
		} elseif ( 'undefined' !== $link_id && 'yes' === $is_active ) {
			$args = $this->sanitize_links_data( $instant_redirect_data );
			unset( $args['link_date'], $args['link_date_gmt'] );
			$this->update_link( $args );
		} elseif ( 'undefined' !== $link_id && 'yes' !== $is_active ) {
			$args = array(
				'ID'        => sanitize_text_field( $link_id ),
				'short_url' => sanitize_text_field( $shortLink ),
			);
			$this->delete_link( $args );
			Helper::delete_link_meta( $args['ID'], 'keywords' );
		}
	}

	/**
	 * Handle elementor page settings data
	 *
	 * @param array|mixed $data - page data.
	 */
	public function handle_page_data( $data ) {
		if ( empty( $data['settings']['bl_ir_target_url'] ) || filter_var( $data['settings']['bl_ir_target_url'], FILTER_VALIDATE_URL ) === false ) {
			$data['settings']['bl_ir_active'] = '';
		}
		return $data;
	}
}
