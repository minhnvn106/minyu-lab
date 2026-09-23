<?php
/**
 * Theme Info Page
 *
 * @package Digital Marketing Freelancer
 */

function digital_marketing_freelancer_theme_details() {
	add_theme_page( 'Themes', 'Digital Marketing Freelancer Theme', 'edit_theme_options', 'digital-marketing-freelancer-theme-info-page', 'theme_details_display', null );
}
add_action( 'admin_menu', 'digital_marketing_freelancer_theme_details' );

function theme_details_display() {

	include_once 'templates/theme-details.php';

}

add_action( 'admin_enqueue_scripts', 'digital_marketing_freelancer_theme_details_style' );

function digital_marketing_freelancer_theme_details_style() {
    wp_register_style( 'digital_marketing_freelancer_theme_details_css', get_template_directory_uri() . '/inc/digital-marketing-freelancer-theme-info-page/css/theme-details.css', false, '1.0.0' );
    wp_enqueue_style( 'digital_marketing_freelancer_theme_details_css' );

    wp_register_script( 'digital_marketing_freelancer_copy_coupon_js', get_template_directory_uri() . '/inc/digital-marketing-freelancer-theme-info-page/js/digital-marketing-freelancer-copy-coupon.js', array(), '1.0.0', true );
    wp_enqueue_script( 'digital_marketing_freelancer_copy_coupon_js' );
}