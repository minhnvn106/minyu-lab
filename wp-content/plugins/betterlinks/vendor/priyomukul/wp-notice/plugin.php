<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Plugin Name:     WP Notice
 * Plugin URI:      https://mukul.me/wp-notice
 * Description:     WP Notice Test Plugin
 * Author:          Mukul
 * Author URI:      https://mukul.me
 * Text Domain:     wp-notice
 * Domain Path:     /languages
 * Version:         2.0.0
 */

use PriyoMukul\WPNotice\Notices;
use PriyoMukul\WPNotice\Utils\CacheBank;

require_once __DIR__ . '/vendor/autoload.php';

function wp_notice( $notice = '', $notice_id = 'wp_notices', $priority = 1 ): Notices {
	$notices = new Notices([
		'id'             => $notice_id,
		'storage_key'    => 'notices',
		'lifetime'       => 3,
		'priority'       => $priority,
		'stylesheet_url' => '',
		// 'dev_mode' => true,
	]);

	$message = ! empty( $notice ) ? $notice : __( 'We hope you\'re enjoying BetterDocs! Could you please do us a BIG favor and give it a 5-star rating on WordPress to help us spread the word and boost our motivation?', 'betterdocs' );

	$_review_notice = [
		'html'      => '<p>' . $message . '</p>',
	];

	$notices->add( 'review', $_review_notice, [
		'start'       => $notices->time(),
		'recurrence'  => 30,
		'dismissible' => true,
	] );


	$_review_notice = [
		'html'      => '<p>Hello World Review Notice 2</p>',
	];

	$notices->add( 'review2', $_review_notice, [
		'start'       => $notices->strtotime(),
		'recurrence'  => 30,
		'dismissible' => true,
	] );

	return $notices;
}

$cacheBank = CacheBank::get_instance();

add_action( 'admin_notices', function () {
	echo "<div class='notice notice-success'><p>Hello From WPNotice Plugin Tests</p></div>";
});

/**
 * Notice From a Plugin
 */
$notice1 = wp_notice();
$cacheBank->create_account( $notice1 );
$cacheBank->calculate_deposits( $notice1 );
$cacheBank->clear_notices_in_( [ 'plugins' ], $notice1, true );

/**
 * Notice From Another Plugin
 */
$notice2 = wp_notice( 'Notice 2', 'wp_notices_2', 2 );
$cacheBank->create_account( $notice2 );
$cacheBank->calculate_deposits( $notice2 );
$cacheBank->clear_notices_in_( [ 'users' ], $notice2, true );