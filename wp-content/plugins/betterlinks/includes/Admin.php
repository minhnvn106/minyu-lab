<?php

namespace BetterLinks;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Admin {
	public function __construct() {
		$this->add_menu();
		$this->add_scripts();
		$this->dispatch_action();
	}

	public function add_menu() {
		new Admin\Menu();
	}

	public function add_scripts() {
		new Admin\Assets();
	}

	public function dispatch_action() {
		new Admin\Ajax();
		Admin\Metabox::init();
		// new Admin\Notice();
		add_action('admin_init', [$this, 'init_notices']);
		add_filter( 'BetterLinks/Admin/skip_no_conflict', [ $this, 'skip_no_conflict' ] );
		add_filter( 'plugin_action_links_' . BETTERLINKS_PLUGIN_BASENAME, array( $this, 'insert_plugin_links' ) );
		add_action( 'admin_head-toplevel_page_betterlinks', array( $this, 'append_no_cache_meta' ) );
		add_action( 'admin_head-toplevel_page_betterlinks-analytics', array( $this, 'append_no_cache_meta' ) );
		add_action( 'admin_head-toplevel_page_betterlinks-settings', array( $this, 'append_no_cache_meta' ) );
		add_action( 'betterlinks/admin/after_import_data', array( $this, 'after_import_data' ) );
	}

	public function init_notices() {
		new Admin\Notice();
	}

	public function skip_no_conflict() {
		$whitelist = [ '127.0.0.1', '::1' ];
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( in_array( $remote, $whitelist, true ) ) {
			return true;
		}

		return false;
	}

	public function insert_plugin_links( $links ) {
		if ( ! \BetterLinks\Helper::is_pro_active() ) {
			$links[] = '<a href="https://wpdeveloper.com/in/upgrade-betterlinks" target="_blank" style="color: #000000; font-weight: bold;">' . __( 'Upgrade to Pro', 'betterlinks' ) . '</a>';
		}

		return $links;
	}

	public function append_no_cache_meta() {
		echo '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
		<meta http-equiv="Pragma" content="no-cache">
		<meta http-equiv="Expires" content="0">';
	}

	public function after_import_data() {
		$Cron = new Cron();
		$Cron->write_json_links();
		$Cron->analytics();
		\BetterLinks\Helper::clear_query_cache();
	}
}
