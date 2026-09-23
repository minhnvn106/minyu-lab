<?php

namespace BetterLinks\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\WPDev\PluginUsageTracker;
use Exception;
use PriyoMukul\WPNotice\Notices;
use PriyoMukul\WPNotice\Utils\CacheBank;
use PriyoMukul\WPNotice\Utils\NoticeRemover;

class Notice {
	/**
	 * @var CacheBank
	 */
	private static $cache_bank;

	/**
	 * @var PluginUsageTracker
	 */
	private $opt_in_tracker;

	const ASSET_URL = BETTERLINKS_ASSETS_URI;

	/**
	 * Screens where review, opt-in and promotional notices may appear.
	 *
	 * Without a screens list the notice library renders on every admin page, while
	 * BetterLinks' own screens clear notices for the React app. Keep them to the
	 * Dashboard and the Plugins screen instead of every wp-admin page.
	 */
	const NOTICE_SCREENS = [ 'dashboard', 'plugins' ];

	/**
	 * Small additions to WordPress's own notice styles for the version notices.
	 * BetterLinks Pro ships the same rules; whichever plugin prints first wins.
	 */
	const UPDATE_NOTICE_CSS = '.betterlinks-update-notice{position:relative;padding-right:38px}.betterlinks-update-notice .notice-dismiss{text-decoration:none}.betterlinks-compat{padding:2px 0 12px}.betterlinks-compat-row .betterlinks-compat{padding:12px 0}.betterlinks-compat__separator{margin:10px -12px 12px;border:0;border-top:1px solid #f0c33c}.betterlinks-compat__head{display:flex;align-items:center;gap:6px;font-size:14px;font-weight:600;color:#1d2327}.betterlinks-compat__head .dashicons{color:#e26f2a}.betterlinks-compat__body{max-width:fit-content;margin:6px 0 0 26px}.betterlinks-compat__text{margin:0 0 8px;line-height:1.6}.betterlinks-compat__versions{margin:0 0 8px;line-height:1.8}.betterlinks-compat__versions b{font-weight:600;color:#1d2327}@media (max-width:600px){.betterlinks-compat__body{margin-left:0}}';

	public function __construct() {
		$this->usage_tracker();

		self::$cache_bank = CacheBank::get_instance();
		try {
			$this->notices();
		} catch ( Exception $e ) {
			unset( $e );
		}

		add_action( 'in_admin_header', [ $this, 'remove_admin_notice' ] );
		add_action( 'betterlinks_compatibility_notices', [ $this, 'btlpro_compatibility_notices' ] );
		add_action( 'betterlinks_admin_notices', [ $this, 'cle_legacy_key_notice' ] );
		add_action( 'betterlinks_compatibility_notices', [ $this, 'pro_update_required_notice' ] );
		add_action( 'admin_notices', [ $this, 'pro_update_required_notice' ] );
		add_action( 'admin_init', [ $this, 'dismiss_pro_update_required_notice' ] );
		add_action( 'admin_init', [ $this, 'dismiss_cle_legacy_key_notice' ] );
		add_action( 'load-plugins.php', [ $this, 'register_pro_plugin_row_alert' ] );
	}

	/**
	 * Tell admins when BetterLinks Pro is too old for this version of BetterLinks.
	 *
	 * Pro features used to ship inside the free plugin; they now live in Pro and
	 * connect through hooks, so an older Pro cannot provide them. Shown on the
	 * Plugins screen and BetterLinks screens, to users who can update plugins,
	 * and dismissible per Pro version.
	 *
	 * @return void
	 */
	public function pro_update_required_notice() {
		if ( ! \BetterLinks\Helper::pro_needs_update() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( 'admin_notices' === current_action() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard', 'plugins-network', 'dashboard-network' ), true ) ) {
				return;
			}
		}
		$pro_version = defined( 'BETTERLINKS_PRO_VERSION' ) ? BETTERLINKS_PRO_VERSION : '';
		if ( get_user_meta( get_current_user_id(), 'betterlinks_dismissed_pro_update_notice', true ) === $pro_version ) {
			return;
		}
		$dismiss_url = wp_nonce_url( add_query_arg( 'betterlinks_dismiss_pro_update', '1' ), 'betterlinks_dismiss_pro_update' );
		self::print_update_notice_styles();
		printf(
			'<div class="notice notice-warning betterlinks-update-notice"><p class="btl-white"><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p><a class="notice-dismiss" href="%5$s"><span class="screen-reader-text">%6$s</span></a></div>',
			esc_html__( 'BetterLinks Pro needs an update.', 'betterlinks' ),
			esc_html(
				sprintf(
					/* translators: %s: required BetterLinks Pro version, e.g. "3.0.4" */
					__( 'Please update BetterLinks Pro to v%s or later to ensure compatibility with the current version of BetterLinks.', 'betterlinks' ),
					BETTERLINKS_MIN_PRO_VERSION
				)
			),
			esc_url( self::plugins_screen_url( 'betterlinks-pro' ) ),
			esc_html__( 'Update now', 'betterlinks' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice', 'betterlinks' )
		);
	}

	/**
	 * Installed plugin data looked up by text domain, so renamed plugin folders still match.
	 *
	 * @param string $text_domain Plugin text domain.
	 * @return array|null Plugin header data plus `file`, or null when it is not installed.
	 */
	private static function find_plugin( $text_domain ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$match = null;
		foreach ( get_plugins() as $file => $data ) {
			if ( isset( $data['TextDomain'] ) && $text_domain === $data['TextDomain'] ) {
				$data['file'] = $file;
				// A site can keep a second, inactive copy (for example a backup folder); the active one is what runs.
				if ( is_plugin_active( $file ) ) {
					return $data;
				}
				if ( null === $match ) {
					$match = $data;
				}
			}
		}
		return $match;
	}

	/**
	 * Whether WordPress has an update available for a plugin.
	 *
	 * @param string $file Plugin file.
	 * @return bool
	 */
	private static function has_update( $file ) {
		$updates = get_site_transient( 'update_plugins' );
		return is_object( $updates ) && isset( $updates->response[ $file ] );
	}

	/**
	 * Plugins screen URL, filtered to available updates when the plugin has one.
	 *
	 * @param string $text_domain Plugin text domain.
	 * @return string
	 */
	private static function plugins_screen_url( $text_domain ) {
		$plugin = self::find_plugin( $text_domain );
		$args   = $plugin && self::has_update( $plugin['file'] ) ? array( 'plugin_status' => 'upgrade' ) : array();
		return add_query_arg( $args, self_admin_url( 'plugins.php' ) );
	}

	/**
	 * Print the version notice styles once per page.
	 *
	 * @param string $row_plugin_file Plugin whose row gets a notice row below it.
	 * @return void
	 */
	public static function print_update_notice_styles( $row_plugin_file = '' ) {
		if ( ! did_action( 'betterlinks_update_notice_styles' ) ) {
			do_action( 'betterlinks_update_notice_styles' );
			echo '<style id="betterlinks-update-notice-styles">' . self::UPDATE_NOTICE_CSS . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS.
		}
		if ( '' !== $row_plugin_file ) {
			printf(
				'<style>.plugins tr[data-plugin="%1$s"]:not(.plugin-update-tr) th,.plugins tr[data-plugin="%1$s"]:not(.plugin-update-tr) td{box-shadow:none}</style>',
				esc_attr( $row_plugin_file )
			);
		}
	}

	/**
	 * On the Plugins screen, flag a BetterLinks Pro older than this version of BetterLinks supports.
	 *
	 * The alert sits below WordPress's "new version available" message when Pro
	 * has an update, or in its own row under Pro otherwise (for example while the
	 * licence is inactive).
	 *
	 * @return void
	 */
	public function register_pro_plugin_row_alert() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$pro = self::find_plugin( 'betterlinks-pro' );
		if ( ! $pro || empty( $pro['Version'] ) || version_compare( $pro['Version'], BETTERLINKS_MIN_PRO_VERSION, '>=' ) ) {
			return;
		}
		$file = $pro['file'];
		add_action(
			'admin_head',
			function () use ( $file ) {
				self::print_update_notice_styles( $file );
			}
		);
		add_action(
			"in_plugin_update_message-{$file}",
			function () use ( $pro ) {
				// Core prints this inside an open <p>; close it so the alert can use block markup.
				echo '</p>';
				self::render_pro_compat_alert( $pro, false );
				echo '<p class="hidden">';
			},
			20
		);
		add_action(
			"after_plugin_row_{$file}",
			function ( $plugin_file ) use ( $pro ) {
				if ( self::has_update( $plugin_file ) ) {
					return;
				}
				global $wp_list_table;
				$colspan = $wp_list_table instanceof \WP_List_Table ? $wp_list_table->get_column_count() : 4;
				$active  = is_network_admin() ? is_plugin_active_for_network( $plugin_file ) : is_plugin_active( $plugin_file );
				printf(
					'<tr class="plugin-update-tr%1$s betterlinks-compat-row"><td colspan="%2$d" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt">',
					$active ? ' active' : '',
					(int) $colspan
				);
				self::render_pro_compat_alert( $pro, true );
				echo '</div></td></tr>';
			},
			20
		);
	}

	/**
	 * Version mismatch alert for an outdated BetterLinks Pro on the Plugins screen.
	 *
	 * @param array $pro        BetterLinks Pro plugin data.
	 * @param bool  $standalone True when rendered in its own row (no update offered).
	 * @return void
	 */
	private static function render_pro_compat_alert( $pro, $standalone ) {
		?>
		<div class="betterlinks-compat">
			<?php if ( ! $standalone ) : ?>
				<hr class="betterlinks-compat__separator" />
			<?php endif; ?>
			<div class="betterlinks-compat__head">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'BetterLinks Pro Compatibility Notice', 'betterlinks' ); ?>
			</div>
			<div class="betterlinks-compat__body">
				<div class="betterlinks-compat__text">
					<?php
					printf(
						/* translators: 1: installed BetterLinks version, 2: required BetterLinks Pro version */
						esc_html__( 'BetterLinks %1$s requires BetterLinks Pro %2$s or later to ensure all features work seamlessly. We recommend updating BetterLinks Pro to the latest version.', 'betterlinks' ),
						esc_html( BETTERLINKS_VERSION ),
						esc_html( BETTERLINKS_MIN_PRO_VERSION )
					);
					?>
				</div>
				<div class="betterlinks-compat__versions">
					<div><?php esc_html_e( 'Installed version:', 'betterlinks' ); ?> <b><?php echo esc_html( $pro['Version'] ); ?></b></div>
					<div>
						<?php esc_html_e( 'Required version:', 'betterlinks' ); ?> <b>
						<?php
						/* translators: %s: plugin version */
						echo esc_html( sprintf( __( '%s or later', 'betterlinks' ), BETTERLINKS_MIN_PRO_VERSION ) );
						?>
						</b>
					</div>
				</div>
				<div class="betterlinks-compat__text"><?php esc_html_e( 'Your existing links, analytics, and settings will remain intact.', 'betterlinks' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Remember a dismissal of the Pro update notice for the current Pro version.
	 *
	 * @return void
	 */
	public function dismiss_pro_update_required_notice() {
		if ( empty( $_GET['betterlinks_dismiss_pro_update'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'betterlinks_dismiss_pro_update' );
		update_user_meta( get_current_user_id(), 'betterlinks_dismissed_pro_update_notice', defined( 'BETTERLINKS_PRO_VERSION' ) ? BETTERLINKS_PRO_VERSION : '' );
		wp_safe_redirect( remove_query_arg( array( 'betterlinks_dismiss_pro_update', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Warn admins before the legacy site-wide Quick Link API key stops working.
	 *
	 * Rendered only on BetterLinks screens (betterlinks_admin_notices) and only while the
	 * legacy key is still accepted.
	 *
	 * @return void
	 */
	public function cle_legacy_key_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( '\BetterLinks\CLEToken' ) ) {
			return;
		}
		if ( ! \BetterLinks\CLEToken::legacy_key_allowed() ) {
			return;
		}
		$expires_at = \BetterLinks\CLEToken::legacy_key_expires_at();
		if ( ! $expires_at ) {
			return;
		}
		// Nothing left to warn about once the site has moved on: a personal
		// token existing means someone has already set Quick Link Creation up
		// the new way. The notice kept appearing after that, telling people to
		// do something they had done.
		if ( ! empty( \BetterLinks\CLEToken::all() ) ) {
			return;
		}
		// Dismissible per user, keyed to the expiry date, so it comes back if
		// the deadline is ever extended but stays gone for this one.
		if ( get_user_meta( get_current_user_id(), 'betterlinks_dismissed_cle_notice', true ) === (string) $expires_at ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( 'betterlinks_dismiss_cle_notice', '1' ), 'betterlinks_dismiss_cle_notice' );
		// Styles first: they position the dismiss button inside the notice.
		self::print_update_notice_styles();
		printf(
			'<div class="notice notice-warning betterlinks-update-notice"><p>%1$s</p><a class="notice-dismiss" href="%2$s"><span class="screen-reader-text">%3$s</span></a></div>',
			esc_html(
				sprintf(
					/* translators: %s: date the legacy key stops working, e.g. "December 19, 2026" */
					__( 'Quick Link Creation: The old API key expires on %s. Update your Quick Link Creation setup from Settings → Feature Module → Quick Link Creation.', 'betterlinks' ),
					wp_date( get_option( 'date_format' ), $expires_at )
				)
			),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss this notice', 'betterlinks' )
		);
	}

	/**
	 * Remember that this admin closed the Quick Link Creation notice.
	 *
	 * @return void
	 */
	public function dismiss_cle_legacy_key_notice() {
		if ( empty( $_GET['betterlinks_dismiss_cle_notice'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'betterlinks_dismiss_cle_notice' );
		$expires_at = class_exists( '\BetterLinks\CLEToken' ) ? \BetterLinks\CLEToken::legacy_key_expires_at() : 0;
		update_user_meta( get_current_user_id(), 'betterlinks_dismissed_cle_notice', (string) $expires_at );
		wp_safe_redirect( remove_query_arg( array( 'betterlinks_dismiss_cle_notice', '_wpnonce' ) ) );
		exit;
	}

	public function btlpro_compatibility_notices() {
		global $wp_version;

		if ( ! defined( 'BETTERLINKS_PRO_VERSION' ) ) {
			return;
		}

		if ( version_compare( $wp_version, '6.6', '>=' ) && version_compare( BETTERLINKS_PRO_VERSION, '2.0.0', '<=' ) ) {
			$message = sprintf( '
			<strong>%1$s</strong>: %2$s <strong>v2.0.1</strong> %3$s <strong>6.6 or later</strong>',
				__( 'Warning', 'betterlinks' ),
				__( 'Please update your BetterLinks Pro plugin to atleast', 'betterlinks' ),
				__( 'to ensure compatibility with WordPress', 'betterlinks' )
			);

			$notice = sprintf( '<div style="padding: 10px;" class="notice notice-warning">%2$s</div>', 'betterlinks', $message );

			echo wp_kses_post( $notice );
		}
	}

	public function remove_admin_notice() {
		$current_screen   = get_current_screen();
		$dashboard_notice = get_option( 'betterlinks_dashboard_notice' );

		if ( ! empty( strpos( $current_screen->id, 'betterlinks-quick-setup' ) ) ) {
			remove_all_actions( 'admin_notices' );

			return;
		}

		if ( 0 === strpos( $current_screen->id, "toplevel_page_betterlinks" ) || 0 === strpos( $current_screen->id, "betterlinks_page_" ) ) {
			remove_all_actions( 'admin_notices' );
			// One notice at a time: while BetterLinks Pro needs an update, that notice replaces the new-feature one.
			$pro_update_notice_shown = \BetterLinks\Helper::pro_needs_update() && current_user_can( 'update_plugins' );
			if ( BETTERLINKS_MENU_NOTICE !== $dashboard_notice && ! $pro_update_notice_shown ) {
				add_action( 'admin_notices', array( $this, 'new_feature_notice' ), - 1 );
			}
			// To showing notice in BetterLinks page
			add_action( 'admin_notices', function () {
				do_action( 'betterlinks_admin_notices' );
				do_action( 'betterlinks_compatibility_notices' );
				// Deprecated aliases, still fired for extensions that listen to the old names.
				do_action_deprecated( 'btl_admin_notices', array(), '3.1.4', 'betterlinks_admin_notices' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				do_action_deprecated( 'btl_compatibity_notices', array(), '3.1.4', 'betterlinks_compatibility_notices' );  // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				Notice\PrettyLinks::init();
				Notice\Simple301::init();
				Notice\ThirstyAffiliates::init();
				// Remove OLD notice from 1.0.0 (if other WPDeveloper plugin has notice)
				NoticeRemover::get_instance( '1.0.0' );
			} );
		}
	}

	/**
	 * The "what's new in Pro" bar above the BetterLinks navbar.
	 *
	 * Keeps `notice is-dismissible` and the `btl-dashboard-notice` id: WordPress
	 * injects the dismiss button into `.notice.is-dismissible`, and `App.js` binds
	 * the persistence AJAX call to that injected `.notice-dismiss` element. The
	 * `btl-notice` classes are what `_admin-notices.scss` styles; without that
	 * stylesheet this still degrades to an ordinary WordPress success notice.
	 */
	public function new_feature_notice() {
		printf(
			'<div class="notice notice-success is-dismissible btl-dashboard-notice btl-notice btl-notice--new" id="btl-dashboard-notice">
				<p class="btl-notice__body">
					<span class="btl-notice__pill">%1$s</span>
					<span class="btl-notice__text"><strong>%2$s</strong>%3$s<a class="btl-notice__link" target="_blank" rel="noopener noreferrer" href="%4$s">%5$s</a>%6$s<a class="btl-notice__link" target="_blank" rel="noopener noreferrer" href="%7$s">%8$s</a>%9$s<a class="btl-notice__link" target="_blank" rel="noopener noreferrer" href="%10$s">%11$s</a>%12$s</span>
				</p>
			</div>',
			esc_html__( 'NEW', 'betterlinks' ),
			esc_html__( 'BetterLinks Pro 3.0 New UI is here!', 'betterlinks' ),
			esc_html__( ' Explore ', 'betterlinks' ),
			esc_url( 'https://betterlinks.io/docs/create-promo-cards-with-betterlinks' ),
			esc_html__( 'Promo Cards', 'betterlinks' ),
			esc_html__( ', ', 'betterlinks' ),
			esc_url( 'https://betterlinks.io/docs/create-link-in-bio-betterlinks' ),
			esc_html__( 'Bio Links', 'betterlinks' ),
			esc_html__( ', and a refreshed experience. See the ', 'betterlinks' ),
			esc_url( 'https://betterlinks.io/changelog/' ),
			esc_html__( 'changelog', 'betterlinks' ),
			esc_html__( '.', 'betterlinks' )
		);
	}

	public function usage_tracker() {
		$this->opt_in_tracker = PluginUsageTracker::get_instance( BETTERLINKS_PLUGIN_FILE, [
			'opt_in'       => true,
			'goodbye_form' => true,
			'item_id'      => '720bbe6537bffcb73f37',
		] );
		$this->opt_in_tracker->set_notice_options( array(
			'notice'       => __( 'Want to help make <strong>BetterLinks</strong> even more awesome? Be the first to get access to <strong>BetterLinks PRO</strong> with a huge <strong>50% Early Bird Discount</strong> if you allow us to track the non-sensitive usage data.', 'betterlinks' ),
			'extra_notice' => __( 'We collect non-sensitive diagnostic data and plugin usage information. Your site URL, WordPress & PHP version, plugins & themes and email address to send you the discount coupon. This data lets us make sure this plugin always stays compatible with the most popular plugins and themes. No spam, I promise.', 'betterlinks' ),
		) );
		$this->opt_in_tracker->init();
	}

	/**
	 * @throws Exception
	 */
	public function notices() {
		$notices = new Notices( [
			'id'             => 'betterlinks',
			'storage_key'    => 'notices',
			'lifetime'       => 3,
			'stylesheet_url' => self::ASSET_URL . 'css/betterlinks-admin-notice.css',
			'styles'         => self::ASSET_URL . 'css/betterlinks-admin-notice.css',
			'priority'       => 7
		] );

		global $betterlinks;
		$current_user = wp_get_current_user();
		$total_links  = ( is_array( $betterlinks ) && isset( $betterlinks['links'] ) ? count( $betterlinks['links'] ) : 0 );

		$review_notice = sprintf(
			'%s, %s! %s',
			__( 'Howdy', 'betterlinks' ),
			esc_html( $current_user->user_login ),
			sprintf(
				/* translators: %d: number of short links created */
				__( '👋 You have created %d Shortened URLs so far 🎉 If you are enjoying using BetterLinks, feel free to leave a 5* Review on the WordPress Forum.', 'betterlinks' ),
				$total_links
			)
		);

		$_review_notice = [
			'thumbnail' => self::ASSET_URL . 'images/logo-large.svg',
			'html'      => '<p>' . $review_notice . '</p>',
			'links'     => [
				'later'            => array(
					'link'       => 'https://wordpress.org/plugins/betterlinks/#reviews',
					'target'     => '_blank',
					'label'      => __( 'Ok, you deserve it!', 'betterlinks' ),
					'icon_class' => 'dashicons dashicons-external',
				),
				'allready'         => array(
					'label'      => __( 'I already did', 'betterlinks' ),
					'icon_class' => 'dashicons dashicons-smiley',
					'attributes' => [
						'data-dismiss' => true
					],
				),
				'maybe_later'      => array(
					'label'      => __( 'Maybe Later', 'betterlinks' ),
					'icon_class' => 'dashicons dashicons-calendar-alt',
					'attributes' => [
						'data-later' => true
					],
				),
				'support'          => array(
					'link'       => 'https://wpdeveloper.com/support',
					'label'      => __( 'I need help', 'betterlinks' ),
					'icon_class' => 'dashicons dashicons-sos',
				),
				'never_show_again' => array(
					'label'      => __( 'Never show again', 'betterlinks' ),
					'icon_class' => 'dashicons dashicons-dismiss',
					'attributes' => [
						'data-dismiss' => true
					],
				)
			]
		];

		$notices->add(
			'review',
			$_review_notice,
			[
				'start'       => $notices->strtotime( '+20 day' ),
				'recurrence'  => 30,
				'refresh'     => BETTERLINKS_VERSION,
				'dismissible' => true,
				'screens'     => self::NOTICE_SCREENS,
				'capability'  => 'manage_options',
			]
		);

		$notices->add(
			'opt_in',
			[ $this->opt_in_tracker, 'notice' ],
			[
				'classes'     => 'updated put-dismiss-notice',
				'start'       => $notices->strtotime( '+25 day' ),
//				'start'       => $notices->time(),
				'refresh'     => BETTERLINKS_VERSION,
				'dismissible' => true,
				'do_action'   => 'wpdeveloper_notice_clicked_for_betterlinks',
				'display_if'  => ! \BetterLinks\Helper::is_pro_active(),
				'screens'     => self::NOTICE_SCREENS,
				'capability'  => 'manage_options',
			]
		);

		// Holiday Notice 2024
		$crown_icon       = self::ASSET_URL . 'images/crown.svg';
		$b_message        = "<p style='margin-top: 0; margin-bottom: 0;'>🎁 <strong>Holiday Gifts:</strong> Get Flat 25% OFF on every <strong>BetterLinks PRO</strong> plans & upgrade your WordPress links.</p><a style='display: inline-flex;align-items:center;column-gap:5px;' class='button button-primary' href='https://betterlinks.io/holiday24-admin-notice' target='_blank'><img style='width:15px;' src='{$crown_icon}'/>Upgrade To PRO</a>";
		$_holiday_notices = [
			'thumbnail' => self::ASSET_URL . 'images/full-logo.svg',
			'html'      => $b_message,
		];

		$notices->add(
			'betterlinks_holiday_24_25',
			$_holiday_notices,
			[
				'start'       => $notices->time(),
				'recurrence'  => false,
				'dismissible' => true,
				'refresh'     => BETTERLINKS_VERSION,
				"expire"      => strtotime( '11:59:59pm 10th January, 2025' ),
				'display_if'  => ! \BetterLinks\Helper::is_pro_active(),
				'screens'     => self::NOTICE_SCREENS,
				'capability'  => 'manage_options',
			]
		);

		// Black Friday Mega Sale Notice
        $black_friday_icon = self::ASSET_URL . 'images/full-logo.svg';
		$black_friday_message = "<style>#wpnotice-betterlinks-betterlinks_summer_camp_2026_deal { border-left: 3px solid #5252DC !important; } .notice-betterlinks-betterlinks_summer_camp_2026_deal { border-left: 4px solid #FF6B6B !important; }</style><div> <p style='margin-top: 0; margin-bottom: 10px; font-size: 14px;'><strong>🏖️ Summer Savings: </strong>Get AI-powered features to manage, shorten & track every click – now <strong> up to $150 OFF! </strong></p><a style='display: inline-flex;align-items:center;column-gap:5px; background: #5252DC; color: #FFFFFF; font-size: 14px; border-radius: 6px; border-color: unset; font-weight: 500;' class='button button-primary' href='https://betterlinks.io/summer2026-admin-notice' target='_blank'>Upgrade To Pro Now</a><a style='display: inline-flex;align-items:center;column-gap:5px;margin-left:10px; background: unset; box-shadow: unset; border-style: unset; color: #424242; font-size: 14px; text-decoration: underline;' class='button dismiss-btn' href='#' data-dismiss='true'>I Don’t Want Any Discount</a> </div>";
        $_black_friday_notices = [
            'thumbnail' => $black_friday_icon,
            'html'      => $black_friday_message,
        ];
		// 'betterlinks_black_friday_2025',
		// 'betterlinks_feb_camp_2026',
		// 'betterlinks_spring_camp_2026_deal',
        $notices->add(
            'betterlinks_summer_camp_2026_deal',
            $_black_friday_notices,
            [
                'start'       => strtotime( '12:00:00am 20th May, 2026' ),
                'recurrence'  => false,
                'dismissible' => true,
                'refresh'     => BETTERLINKS_VERSION,
                "expire"      => strtotime( '12:00:00am 25th June, 2026' ),
    			'display_if'  => ! \BetterLinks\Helper::is_pro_active(),
				'priority'    => 7,
				'screens'     => self::NOTICE_SCREENS,
				'capability'  => 'manage_options',
            ]
        );
		self::$cache_bank->create_account( $notices );
		self::$cache_bank->calculate_deposits( $notices );

		if ( method_exists( self::$cache_bank, 'clear_notices_in_' ) ) {
			self::$cache_bank->clear_notices_in_( [
				'toplevel_page_betterlinks',
				'betterlinks_page_betterlinks-keywords-linking',
				'betterlinks_page_betterlinks-manage-tags-and-categories',
				'betterlinks_page_betterlinks-custom-domain',
				'betterlinks_page_betterlinks-analytics',
				'betterlinks_page_betterlinks-settings',
			], $notices, true );
		}
	}

}
