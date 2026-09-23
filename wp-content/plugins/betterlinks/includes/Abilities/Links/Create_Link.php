<?php
/**
 * Create a short link ability.
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
 * Create a new short link with a slug, target URL and options.
 */
class Create_Link extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/create-link';
		$this->label       = __( 'Create a short link', 'betterlinks' );
		$this->description = __( 'Create a new short link with a slug, target URL and options.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => false,
			'destructive'   => false,
			'idempotent'    => false,
			'priority'      => 2.0,
			'openWorldHint' => false,
		];
	}

	public function get_input_schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array_merge(
				[
					'link_title'    => [ 'type' => 'string', 'description' => __( 'Internal title for the link.', 'betterlinks' ) ],
					'target_url'    => [ 'type' => 'string', 'description' => __( 'Destination URL the short link redirects to. Required.', 'betterlinks' ) ],
					'link_slug'     => [ 'type' => 'string', 'description' => __( 'The short URL slug (e.g. "deal"). The configured link prefix is prepended to it. Auto-generated from the title when omitted.', 'betterlinks' ) ],
					'redirect_type' => [ 'type' => 'string', 'enum' => [ '301', '302', '307' ], 'description' => __( 'HTTP redirect type. Defaults to 307.', 'betterlinks' ) ],
					'cat_id'        => [ 'type' => 'integer', 'description' => __( 'Category ID to file the link under.', 'betterlinks' ) ],
					'nofollow'      => [ 'type' => 'boolean' ],
					'sponsored'     => [ 'type' => 'boolean' ],
					'link_status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ],
				],
				self::shared_link_properties()
			),
		];
	}

	public function get_output_schema() {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [ 'type' => 'boolean' ],
				'data'    => [ 'type' => [ 'object', 'array', 'string', 'null' ] ],
			],
		];
	}

	public function execute( $input ) {
		$target = self::validate_target_url( isset( $input['target_url'] ) ? $input['target_url'] : '' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		// Capped: the title is stored on the link row and copied into the
		// links.json cache the redirect handler reads on every request.
		$title = isset( $input['link_title'] ) ? mb_substr( (string) $input['link_title'], 0, self::MAX_TITLE_LENGTH ) : '';
		// Use the slash-preserving sanitizer so multi-segment slugs like "go/deal"
		// survive intact (WP core's sanitize_title() would convert '/' to '-').
		$raw   = isset( $input['link_slug'] ) && '' !== $input['link_slug']
			? (string) $input['link_slug']
			: ( '' !== $title ? $title : 'link-' . substr( md5( uniqid( '', true ) ), 0, 8 ) );
		$slug  = self::sanitize_slug_preserving_slashes( $raw );
		if ( '' === $slug ) {
			return new \WP_Error( 'betterlinks_invalid_slug', __( 'link_slug produced an empty value after sanitization.', 'betterlinks' ), [ 'status' => 400 ] );
		}
		// An explicit short_url is taken as the final path; otherwise the
		// configured prefix is applied to the slug (matching the admin UI).
		// build_short_url() is idempotent — if the caller already included the
		// prefix, no double-prepending happens.
		$short_url = self::resolve_short_url( $input, $slug );
		if ( is_wp_error( $short_url ) ) {
			return $short_url;
		}

		// Reject collisions with existing WordPress URLs before creation,
		// otherwise BetterLinks' init:0 redirect handler would silently shadow
		// the WP content. Sites that want the old behaviour can filter
		// betterlinks/skip_wp_url_collision_check to true.
		$collision = \BetterLinks\Helper::check_wp_url_collision( $short_url );
		if ( is_wp_error( $collision ) ) {
			return $collision;
		}

		$params = [
			'link_title'    => '' !== $title ? $title : $slug,
			'link_slug'     => $slug,
			'short_url'     => $short_url,
			'target_url'    => $target,
			'redirect_type' => isset( $input['redirect_type'] ) ? (string) $input['redirect_type'] : '307',
			'link_status'   => isset( $input['link_status'] ) ? (string) $input['link_status'] : 'publish',
			'nofollow'      => ! empty( $input['nofollow'] ) ? '1' : '',
			'sponsored'     => ! empty( $input['sponsored'] ) ? '1' : '',
			'track_me'      => '1',
		];
		if ( ! empty( $input['cat_id'] ) ) {
			$params['cat_id'] = absint( $input['cat_id'] );
		}
		$params = array_merge( $params, self::shared_link_params( $input ) );
		// A link made here should match one made in the link form, so anything
		// the caller left out falls back to the site's own defaults.
		$params = self::apply_site_defaults( $input, $params );

		$result = $this->dispatch( 'POST', '/links', $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$link_id = ! empty( $result['data']['ID'] ) ? absint( $result['data']['ID'] ) : 0;
		if ( $link_id && array_key_exists( 'favorite', $input ) ) {
			$this->set_favorite( $link_id, rest_sanitize_boolean( $input['favorite'] ) );
		}

		$stored = self::stored_link( $link_id );
		return null === $stored ? $result : [
			'success' => true,
			'data'    => $stored,
		];
	}
}
