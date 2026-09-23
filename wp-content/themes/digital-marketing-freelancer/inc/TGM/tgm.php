<?php
	
load_template( get_template_directory() . '/inc/TGM/class-tgm-plugin-activation.php' );

/**
 * Recommended plugins.
 */
function digital_marketing_freelancer_register_recommended_plugins() {
	$plugins = array(
		array(
			'name'             => __( 'Siteready Coming Soon Under Construction', 'digital-marketing-freelancer' ),
			'slug'             => 'siteready-coming-soon-under-construction',
			'required'         => false,
			'force_activation' => false,
		)
	);
	$config = array();
	digital_marketing_freelancer_tgmpa( $plugins, $config );
}
add_action( 'tgmpa_register', 'digital_marketing_freelancer_register_recommended_plugins' );