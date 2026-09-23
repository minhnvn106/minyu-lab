<?php
/**
 * Check whether a short URL is free to use.
 *
 * @package BetterLinks\Abilities\Links
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Links;

use BetterLinks\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Answer "can I create a link here?" before trying to.
 *
 * The link form runs this check as you type; over MCP the only way to find out
 * was to attempt the create and read the failure. It also reports the path the
 * link would actually get, which is the question behind every "why did my link
 * come out as go/<slug>?" report.
 */
class Check_Short_Url extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/check-short-url';
		$this->label       = __( 'Check a short URL', 'betterlinks' );
		$this->description = __( 'Check whether a short URL is free before creating a link, and see the exact path a slug would produce once the configured prefix is applied.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => true,
			'destructive'   => false,
			'idempotent'    => true,
			'priority'      => 1.0,
			'openWorldHint' => false,
		];
	}

	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'link_slug' => [ 'type' => 'string', 'description' => __( 'Slug to test. The configured prefix is applied to it, exactly as create-link would.', 'betterlinks' ) ],
				'short_url' => [ 'type' => 'string', 'description' => __( 'Full path to test verbatim, with no prefix applied.', 'betterlinks' ) ],
			],
		];
	}

	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'data'    => [ 'type' => [ 'object', 'array', 'null' ] ],
			],
		];
	}

	public function execute( $input ) {
		$has_slug      = isset( $input['link_slug'] ) && '' !== trim( (string) $input['link_slug'] );
		$has_short_url = isset( $input['short_url'] ) && '' !== trim( (string) $input['short_url'] );
		if ( ! $has_slug && ! $has_short_url ) {
			return new \WP_Error(
				'betterlinks_missing_slug',
				__( 'Pass a link_slug or a short_url to check.', 'betterlinks' ),
				[ 'status' => 400 ]
			);
		}

		$slug      = $has_slug ? self::sanitize_slug_preserving_slashes( (string) $input['link_slug'] ) : '';
		$short_url = self::resolve_short_url( $input, $slug );
		if ( is_wp_error( $short_url ) ) {
			return $short_url;
		}

		$prefix = self::get_configured_prefix();
		$taken  = \BetterLinks\Helper::get_link_by_short_url( $short_url );
		$taken  = is_array( $taken ) && ! empty( $taken ) ? (array) current( $taken ) : [];

		$data = [
			'short_url'       => $short_url,
			'full_url'        => trailingslashit( site_url() ) . $short_url,
			'prefix_applied'  => ( '' !== $prefix && ! $has_short_url ) ? $prefix : '',
			'available'       => empty( $taken ),
		];

		if ( ! empty( $taken ) ) {
			$data['reason']            = 'duplicate';
			$data['conflicting_link']  = [
				'ID'         => isset( $taken['ID'] ) ? absint( $taken['ID'] ) : 0,
				'link_title' => isset( $taken['link_title'] ) ? $taken['link_title'] : '',
				'target_url' => isset( $taken['target_url'] ) ? $taken['target_url'] : '',
			];
			$data['message'] = sprintf(
				/* translators: %s: the short URL */
				__( 'A link already uses "%s".', 'betterlinks' ),
				$short_url
			);
			return [
				'success' => true,
				'data'    => $data,
			];
		}

		// Reserved WordPress paths (wp-login.php, wp-admin, the REST API) are
		// refused on save, so report them here rather than at write time.
		$collision = \BetterLinks\Helper::check_wp_url_collision( $short_url );
		if ( is_wp_error( $collision ) ) {
			$error_data        = $collision->get_error_data();
			$data['available'] = false;
			$data['reason']    = is_array( $error_data ) && isset( $error_data['conflict_type'] ) ? $error_data['conflict_type'] : 'wp_url_collision';
			$data['message']   = $collision->get_error_message();
			return [
				'success' => true,
				'data'    => $data,
			];
		}

		$data['message'] = sprintf(
			/* translators: %s: the short URL */
			__( '"%s" is free to use.', 'betterlinks' ),
			$short_url
		);

		return [
			'success' => true,
			'data'    => $data,
		];
	}
}
