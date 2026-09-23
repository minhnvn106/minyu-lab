<?php
namespace BetterLinks\Admin;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Cache {
	public static function init() {
		self::write_json_settings();
	}
	public static function write_json_settings() {
		$betterlinks_links = get_option( BETTERLINKS_LINKS_OPTION_NAME, array() );
		if ( is_string( $betterlinks_links ) ) {
			$betterlinks_links = json_decode( $betterlinks_links, true );
		}

		// Security: Remove sensitive API keys from JSON cache
		// API keys are stored separately in BETTERLINKS_AI_API_KEYS_OPTION_NAME
		unset( $betterlinks_links['openai_api_key'] );
		unset( $betterlinks_links['gemini_api_key'] );

		if( !is_dir( BETTERLINKS_UPLOAD_DIR_PATH ) ){
			wp_mkdir_p(BETTERLINKS_UPLOAD_DIR_PATH);
		}
		file_put_contents( BETTERLINKS_UPLOAD_DIR_PATH . '/settings.json', json_encode( $betterlinks_links ) );
		return $betterlinks_links;
	}

	public static function get_json_settings() {
		if ( file_exists( BETTERLINKS_UPLOAD_DIR_PATH . '/settings.json' ) ) {
			$settings = json_decode( file_get_contents( BETTERLINKS_UPLOAD_DIR_PATH . '/settings.json' ), true );
			if ( ! empty( $settings ) ) {
				return $settings;
			}
		}
		return self::write_json_settings();
	}
}
