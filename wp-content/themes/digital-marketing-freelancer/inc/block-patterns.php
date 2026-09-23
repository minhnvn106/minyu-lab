<?php   
/**
 * Block Patterns
 *
 * @package Digital Marketing Freelancer
 * @since 1.0
 */

/**
 * Registers block patterns and categories.
 *
 * @since 1.0
 *
 * @return void
 */
function digital_marketing_freelancer_register_block_patterns() {
	$block_pattern_categories = array(
		'digital-marketing-freelancer' => array( 'label' => esc_html__( 'Digital Marketing Freelancer Patterns', 'digital-marketing-freelancer' ) ),
		'pages'    => array( 'label' => esc_html__( 'Pages', 'digital-marketing-freelancer' ) ),
	);

	$block_pattern_categories = apply_filters( 'digital_marketing_freelancer_block_pattern_categories', $block_pattern_categories );

	foreach ( $block_pattern_categories as $name => $properties ) {
		if ( ! WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $name ) ) {
			register_block_pattern_category( $name, $properties );
		}
	}

	$block_patterns = array(
		'archive-inner-banner',
		'header-default',
		'header-banner',
		'process-section',
		'inner-banner',
		'post-one-column',
		'post-two-column',
		'latest-blog',
		'hidden-404',
		'sidebar',
		'footer-default',	
	);

	$block_patterns = apply_filters( 'digital_marketing_freelancer_block_patterns', $block_patterns );

	foreach ( $block_patterns as $block_pattern ) {
		$pattern_file = get_parent_theme_file_path( '/inc/patterns/' . $block_pattern . '.php' );

		register_block_pattern(
			'digital-marketing-freelancer/' . $block_pattern,
			require $pattern_file
		);
	}
}
add_action( 'init', 'digital_marketing_freelancer_register_block_patterns', 9 );