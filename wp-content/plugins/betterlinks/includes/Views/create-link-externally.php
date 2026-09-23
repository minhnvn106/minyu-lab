<?php
/**
 * The "Quick Link Creation" feature enables users to generate BetterLinks instantly while browsing online content, facilitating seamless sharing of branded URLs for blog posts or important links.
 *
 * @package BetterLinks/Create-Link-Externally
 * @since 1.9.2
 */

// @-collapse
if ( ! defined( 'ABSPATH' ) ) {

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	die( 'You are not allowed here.' );
}
if ( empty( $prevent_unwanted_click ) ) {
	$link_title        = ! empty( $results['link_title'] ) ? $results['link_title'] : '[No Title]';
	$target_url        = ! empty( $results['target_url'] ) ? $results['target_url'] : '';
	$short_url         = ! empty( $results['short_url'] ) ? $results['short_url'] : '';
	$short_url         = site_url( $short_url );
	$encoded_short_url = rawurlencode( $short_url );

	$nofollow         = ! empty( $results['nofollow'] ) ? 'checked' : '';
	$sponsored        = ! empty( $results['sponsored'] ) ? 'checked' : '';
	$param_forwarding = ! empty( $results['param_forwarding'] ) ? 'checked' : '';
	$track_me         = ! empty( $results['track_me'] ) ? 'checked' : '';
	$redirect_type    = ! empty( $results['redirect_type'] ) ? $results['redirect_type'] : '307';
	$link_date        = ! empty( $results['link_date'] ) ? gmdate( 'd F Y', strtotime( $results['link_date'] ) ) : '';
	$category         = ! empty( $results['cat_data'] ) ? sanitize_text_field( $results['cat_data']['term_name'] ) : '';

	$social_share   = ! empty( $initial_values['social_share'] ) ? $initial_values['social_share'] : false;
	$powered_by     = ! empty( $initial_values['powered_by'] ) ? $initial_values['powered_by'] : false;
	$telegram       = BETTERLINKS_PLUGIN_ROOT_URI . 'assets/images/telegram.png';
	$copy_icon      = BETTERLINKS_PLUGIN_ROOT_URI . 'assets/images/copy-icon-1.svg';
	$redirect_types = array(
		'307'   => '307',
		'301'   => '301',
		'302'   => '302',
		'cloak' => 'Cloaked',
	);

	// Toggle rows for the "Link Options" card. Rendering these from an array
	// keeps the markup flat and gives every row the same labelled state pill,
	// so on/off reads at a glance instead of as a run of coloured text.
	$link_options = array(
		array(
			'label' => __( 'No Follow', 'betterlinks' ),
			'state' => $nofollow,
		),
		array(
			'label' => __( 'Parameter Forwarding', 'betterlinks' ),
			'state' => $param_forwarding,
		),
		array(
			'label' => __( 'Tracking', 'betterlinks' ),
			'state' => $track_me,
		),
		array(
			'label' => __( 'Sponsored', 'betterlinks' ),
			'state' => $sponsored,
		),
	);
}
	$betterlinks_logo = BETTERLINKS_PLUGIN_ROOT_URI . 'assets/images/full-logo.svg';
	$betterlinks_mark = BETTERLINKS_PLUGIN_ROOT_URI . 'assets/images/logo-large.svg';
	wp_register_style( 'betterlinks-cle', BETTERLINKS_ASSETS_URI . 'css/betterlinks-cle.css', array(), BETTERLINKS_VERSION );
	wp_register_script( 'betterlinks-cle', BETTERLINKS_ASSETS_URI . 'js/betterlinks-cle.core.min.js', array( 'jquery', 'clipboard' ), BETTERLINKS_VERSION, true );
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( '🎉 Here is your Quick Link', 'betterlinks' ); ?></title>
	<?php wp_print_styles( 'betterlinks-cle' ); ?>
	<?php wp_site_icon(); ?>
</head>
<body>
<?php
if ( ! empty( $prevent_unwanted_click ) ) {
	?>
	<div class="bl-cle">
		<main class="bl-cle__main bl-cle__main--blocked">
			<div class="bl-cle__brand">
				<img src="<?php echo esc_url( $betterlinks_mark ); ?>" alt="BetterLinks" width="36" height="36" />
			</div>
			<h1 class="bl-cle__title"><?php esc_html_e( 'Quick Link creation is unavailable for this URL', 'betterlinks' ); ?></h1>
			<p class="bl-cle__subtitle"><?php esc_html_e( 'This page can’t be shortened. Head back and try a different link.', 'betterlinks' ); ?></p>
			<div class="bl-cle__actions bl-cle__actions--center">
				<a class="bl-cle__back" href="#" onclick="history.back(); return false;" title="<?php esc_attr_e( 'Go Back', 'betterlinks' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6" /></svg>
					<?php esc_html_e( 'Go Back', 'betterlinks' ); ?>
				</a>
			</div>
		</main>
		<?php if ( ! empty( $powered_by ) ) { ?>
			<footer class="bl-cle__credit">
				<span>
					<?php esc_html_e( 'Powered By', 'betterlinks' ); ?>
					<img src="<?php echo esc_url( $betterlinks_logo ); ?>" alt="BetterLinks" title="BetterLinks" />
				</span>
			</footer>
		<?php } ?>
	</div>
	</body>
	</html>
	<?php
	exit;
}
?>
	<div id="confetti"></div>
	<div class="bl-cle">
		<main class="bl-cle__main">
			<header class="bl-cle__head">
				<div class="bl-cle__brand">
					<img src="<?php echo esc_url( $betterlinks_mark ); ?>" alt="BetterLinks" width="36" height="36" />
				</div>
				<h1 class="bl-cle__title"><?php esc_html_e( '🎉 Here is your Quick Link', 'betterlinks' ); ?></h1>
				<p class="bl-cle__subtitle"><?php esc_html_e( 'Your branded short link is ready to copy and share.', 'betterlinks' ); ?></p>
			</header>

			<div class="bl-cle__urlbar">
				<a class="bl-cle__url" href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $short_url ); ?>">
					<?php echo esc_html( $short_url ); ?>
				</a>
				<div class="bl-cle__urlactions">
					<button type="button" class="bl-cle__iconbtn btl-short-url-copy-button" data-clipboard-text="<?php echo esc_attr( $short_url ); ?>" title="<?php esc_attr_e( 'Copy link', 'betterlinks' ); ?>" aria-label="<?php esc_attr_e( 'Copy link', 'betterlinks' ); ?>">
						<span class="icon">
							<img width="16" height="16" src="<?php echo esc_url( $copy_icon ); ?>" alt="" />
							<span class="bl-cle__copied" style="display: none;">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5" /></svg>
							</span>
						</span>
					</button>
					<a class="bl-cle__iconbtn" href="<?php echo esc_url( $short_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Open link', 'betterlinks' ); ?>" aria-label="<?php esc_attr_e( 'Open link', 'betterlinks' ); ?>">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" /></svg>
					</a>
				</div>
			</div>

			<?php
			if ( ! empty( $initial_values['pro_enabled'] ) && ! empty( $social_share ) ) {
				?>
				<div class="bl-cle__share">
					<span class="bl-cle__share-label"><?php esc_html_e( 'Share', 'betterlinks' ); ?></span>
					<div class="bl-cle__share-list">
						<a class="bl-cle__share-btn" href="https://www.facebook.com/sharer/sharer.php?u=<?php echo esc_attr( $encoded_short_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Share to Facebook', 'betterlinks' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">
								<linearGradient id="awSgIinfw5_FS5MLHI~A9a_yGcWL8copNNQ_gr1" x1="6.228" x2="42.077" y1="4.896" y2="43.432" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#0d61a9"></stop><stop offset="1" stop-color="#16528c"></stop></linearGradient><path fill="url(#awSgIinfw5_FS5MLHI~A9a_yGcWL8copNNQ_gr1)" d="M42,40c0,1.105-0.895,2-2,2H8c-1.105,0-2-0.895-2-2V8c0-1.105,0.895-2,2-2h32	c1.105,0,2,0.895,2,2V40z"></path><path d="M25,38V27h-4v-6h4v-2.138c0-5.042,2.666-7.818,7.505-7.818c1.995,0,3.077,0.14,3.598,0.208	l0.858,0.111L37,12.224L37,17h-3.635C32.237,17,32,18.378,32,19.535V21h4.723l-0.928,6H32v11H25z" opacity=".05"></path><path d="M25.5,37.5v-11h-4v-5h4v-2.638c0-4.788,2.422-7.318,7.005-7.318c1.971,0,3.03,0.138,3.54,0.204	l0.436,0.057l0.02,0.442V16.5h-3.135c-1.623,0-1.865,1.901-1.865,3.035V21.5h4.64l-0.773,5H31.5v11H25.5z" opacity=".07"></path><path fill="#fff" d="M33.365,16H36v-3.754c-0.492-0.064-1.531-0.203-3.495-0.203c-4.101,0-6.505,2.08-6.505,6.819V22h-4v4	h4v11h5V26h3.938l0.618-4H31v-2.465C31,17.661,31.612,16,33.365,16z"></path>
							</svg>
						</a>
						<a class="bl-cle__share-btn" href="https://twitter.com/intent/tweet?url=<?php echo esc_attr( $encoded_short_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Share to X(Formerly Twitter)', 'betterlinks' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">
								<path fill="#212121" fill-rule="evenodd" d="M38,42H10c-2.209,0-4-1.791-4-4V10c0-2.209,1.791-4,4-4h28	c2.209,0,4,1.791,4,4v28C42,40.209,40.209,42,38,42z" clip-rule="evenodd"></path><path fill="#fff" d="M34.257,34h-6.437L13.829,14h6.437L34.257,34z M28.587,32.304h2.563L19.499,15.696h-2.563 L28.587,32.304z"></path><polygon fill="#fff" points="15.866,34 23.069,25.656 22.127,24.407 13.823,34"></polygon><polygon fill="#fff" points="24.45,21.721 25.355,23.01 33.136,14 31.136,14"></polygon>
							</svg>
						</a>
						<a class="bl-cle__share-btn" href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo esc_attr( $encoded_short_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Share to Linkedin', 'betterlinks' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true">
								<path fill="#0288D1" d="M42,37c0,2.762-2.238,5-5,5H11c-2.761,0-5-2.238-5-5V11c0-2.762,2.239-5,5-5h26c2.762,0,5,2.238,5,5V37z"></path><path fill="#FFF" d="M12 19H17V36H12zM14.485 17h-.028C12.965 17 12 15.888 12 14.499 12 13.08 12.995 12 14.514 12c1.521 0 2.458 1.08 2.486 2.499C17 15.887 16.035 17 14.485 17zM36 36h-5v-9.099c0-2.198-1.225-3.698-3.192-3.698-1.501 0-2.313 1.012-2.707 1.99C24.957 25.543 25 26.511 25 27v9h-5V19h5v2.616C25.721 20.5 26.85 19 29.738 19c3.578 0 6.261 2.25 6.261 7.274L36 36 36 36z"></path>
							</svg>
						</a>
						<a class="bl-cle__share-btn" href="https://www.tumblr.com/widgets/share/tool/preview?url=<?php echo esc_attr( $encoded_short_url ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Share to Tumblr', 'betterlinks' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" aria-hidden="true">
								<path d="M48,8H16c-4.418,0-8,3.582-8,8v32c0,4.418,3.582,8,8,8h32c4.418,0,8-3.582,8-8V16C56,11.582,52.418,8,48,8z M41,44 c0,0-2,3-8,3c-5,0-6-4-6-8V29h-4v-4c4,0,6-4,6-8h4v7h7v5h-7v10c0,2,1,3,3.315,3C39,42,41,40,41,40V44z"></path>
							</svg>
						</a>
						<a class="bl-cle__share-btn" href="https://t.me/share/url?url=<?php echo esc_attr( $encoded_short_url ); ?>&text=<?php echo esc_attr( $link_title ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Share to Telegram', 'betterlinks' ); ?>">
							<img src="<?php echo esc_url( $telegram ); ?>" alt="<?php esc_attr_e( 'Share to Telegram', 'betterlinks' ); ?>" />
						</a>
					</div>
				</div>
				<?php
			}
			?>

			<section class="bl-cle__card">
				<dl class="bl-cle__meta">
					<dt><?php esc_html_e( 'Title', 'betterlinks' ); ?></dt>
					<dd class="bl-cle__meta-title" title="<?php echo esc_attr( $link_title ); ?>"><?php echo esc_html( $link_title ); ?></dd>
					<dt><?php esc_html_e( 'Main URL', 'betterlinks' ); ?></dt>
					<dd>
						<a class="bl-cle__meta-url" href="<?php echo esc_url( $target_url ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $target_url ); ?>"><?php echo esc_html( $target_url ); ?></a>
					</dd>
				</dl>
			</section>

			<section class="bl-cle__card">
				<h2 class="bl-cle__card-title"><?php esc_html_e( 'Link Options', 'betterlinks' ); ?></h2>
				<ul class="bl-cle__options">
					<?php foreach ( $link_options as $option ) { ?>
						<li class="bl-cle__option">
							<span class="bl-cle__option-label"><?php echo esc_html( $option['label'] ); ?></span>
							<?php if ( 'checked' === $option['state'] ) { ?>
								<span class="bl-cle__pill bl-cle__pill--on"><?php esc_html_e( 'Active', 'betterlinks' ); ?></span>
							<?php } else { ?>
								<span class="bl-cle__pill bl-cle__pill--off"><?php esc_html_e( 'Disabled', 'betterlinks' ); ?></span>
							<?php } ?>
						</li>
					<?php } ?>
					<li class="bl-cle__option">
						<span class="bl-cle__option-label"><?php esc_html_e( 'Redirect Type', 'betterlinks' ); ?></span>
						<span class="bl-cle__pill bl-cle__pill--info"><?php echo esc_html( isset( $redirect_types[ $redirect_type ] ) ? $redirect_types[ $redirect_type ] : $redirect_type ); ?></span>
					</li>
					<?php if ( ! empty( $category ) ) { ?>
						<li class="bl-cle__option">
							<span class="bl-cle__option-label"><?php esc_html_e( 'Category', 'betterlinks' ); ?></span>
							<span class="bl-cle__pill bl-cle__pill--info"><?php echo esc_html( $category ); ?></span>
						</li>
					<?php } ?>
				</ul>
			</section>

			<div class="bl-cle__actions">
				<a class="bl-cle__back" href="#" onclick="history.back(); return false;" title="<?php esc_attr_e( 'Go Back', 'betterlinks' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 18l-6-6 6-6" /></svg>
					<?php esc_html_e( 'Go Back', 'betterlinks' ); ?>
				</a>
				<?php if ( ! empty( $link_date ) ) { ?>
					<span class="bl-cle__date"><?php esc_html_e( 'Created at', 'betterlinks' ); ?> <?php echo esc_html( $link_date ); ?></span>
				<?php } ?>
			</div>
		</main>

		<?php if ( ! empty( $powered_by ) ) { ?>
			<footer class="bl-cle__credit">
				<span>
					<?php esc_html_e( 'Powered By', 'betterlinks' ); ?>
					<img src="<?php echo esc_url( $betterlinks_logo ); ?>" alt="BetterLinks" title="BetterLinks" />
				</span>
			</footer>
		<?php } ?>
	</div>

	<?php wp_print_scripts( 'betterlinks-cle' ); ?>
</body>
</html>
