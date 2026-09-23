<?php
/**
 * Update a short link ability.
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
 * Update an existing short link — its title, target URL, slug, redirect type, category or status.
 */
class Update_Link extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/update-link';
		$this->label       = __( 'Update a short link', 'betterlinks' );
		$this->description = __( 'Update an existing short link — its title, target URL, slug, redirect type, category or status.', 'betterlinks' );
	}

	public function get_annotations() {
		return [
			'readonly'      => false,
			// Overwrites an existing link in place, so a client should confirm first.
			'destructive'   => true,
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
					'ID'            => [ 'type' => 'integer', 'description' => __( 'The link ID to update. Required.', 'betterlinks' ) ],
					'link_title'    => [ 'type' => 'string' ],
					'target_url'    => [ 'type' => 'string' ],
					'link_slug'     => [ 'type' => 'string', 'description' => __( 'New slug. The configured link prefix is prepended to it; send short_url instead to set the path verbatim.', 'betterlinks' ) ],
					'redirect_type' => [ 'type' => 'string', 'enum' => [ '301', '302', '307' ] ],
					'cat_id'        => [ 'type' => 'integer' ],
					'nofollow'      => [ 'type' => 'boolean' ],
					'sponsored'     => [ 'type' => 'boolean' ],
					'link_status'   => [ 'type' => 'string', 'enum' => [ 'publish', 'draft' ] ],
				],
				self::shared_link_properties(),
				[ 'confirm' => self::confirm_property() ]
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
		$id = isset( $input['ID'] ) ? absint( $input['ID'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'betterlinks_missing_link_id', __( 'A link ID is required to update a link.', 'betterlinks' ), [ 'status' => 400 ] );
		}
		$existing_row = \BetterLinks\Helper::get_link_by_ID( $id );
		$existing     = is_array( $existing_row ) && ! empty( $existing_row ) ? (array) current( $existing_row ) : [];
		if ( empty( $existing ) ) {
			return new \WP_Error(
				'betterlinks_link_not_found',
				sprintf(
					/* translators: %d: link ID */
					__( 'No link with ID %d exists.', 'betterlinks' ),
					$id
				),
				[ 'status' => 404 ]
			);
		}

		$params = [ 'ID' => $id ];
		if ( isset( $input['link_title'] ) )    { $params['link_title'] = mb_substr( (string) $input['link_title'], 0, self::MAX_TITLE_LENGTH ); }
		if ( isset( $input['target_url'] ) ) {
			$target = self::validate_target_url( $input['target_url'] );
			if ( is_wp_error( $target ) ) {
				return $target;
			}
			$params['target_url'] = $target;
		}
		if ( isset( $input['link_slug'] ) ) {
			// Sanitize with slashes preserved (mirrors admin JS + Create_Link).
			$slug = self::sanitize_slug_preserving_slashes( (string) $input['link_slug'] );
			if ( '' === $slug ) {
				return new \WP_Error( 'betterlinks_invalid_slug', __( 'link_slug produced an empty value after sanitization.', 'betterlinks' ), [ 'status' => 400 ] );
			}
			$params['link_slug'] = $slug;
		}
		// An explicit short_url sets the path verbatim; a slug alone still gets
		// the configured prefix. Either way the path only changes when asked.
		if ( isset( $input['short_url'] ) || isset( $params['link_slug'] ) ) {
			$slug_for_path = isset( $params['link_slug'] ) ? $params['link_slug'] : (string) ( $existing['link_slug'] ?? '' );
			$short_url     = self::resolve_short_url( $input, $slug_for_path );
			if ( is_wp_error( $short_url ) ) {
				return $short_url;
			}

			// Checked here as well as in the REST controller this dispatches to,
			// so an MCP client gets a real WP_Error 409 rather than the
			// `success: false` envelope the admin app expects. Skipped when the
			// update keeps the same short_url (no-op edits).
			$current_url = isset( $existing['short_url'] ) ? (string) $existing['short_url'] : '';
			if ( $short_url !== $current_url ) {
				$collision = \BetterLinks\Helper::check_wp_url_collision( $short_url );
				if ( is_wp_error( $collision ) ) {
					return $collision;
				}

				// Availability, checked here rather than only on the write: the
				// preview call used to summarise a move onto a path another link
				// already owns as though it would succeed, and only the confirmed
				// write refused it.
				$owner = \BetterLinks\Helper::get_link_by_short_url( $short_url );
				$owner = is_array( $owner ) && ! empty( $owner ) ? (array) current( $owner ) : [];
				if ( isset( $owner['ID'] ) && absint( $owner['ID'] ) !== $id ) {
					return new \WP_Error(
						'betterlinks_duplicate_short_url',
						sprintf(
							/* translators: 1: the short URL that is taken, 2: ID of the link holding it */
							__( 'A link with the short URL "%1$s" already exists (ID %2$d). Short URLs have to be unique.', 'betterlinks' ),
							$short_url,
							absint( $owner['ID'] )
						),
						[ 'status' => 409, 'conflicting_link_id' => absint( $owner['ID'] ) ]
					);
				}
			}

			$params['short_url'] = $short_url;
		}
		if ( isset( $input['redirect_type'] ) )  { $params['redirect_type'] = (string) $input['redirect_type']; }
		if ( isset( $input['link_status'] ) )    { $params['link_status'] = (string) $input['link_status']; }
		if ( array_key_exists( 'nofollow', $input ) )  { $params['nofollow'] = ! empty( $input['nofollow'] ) ? '1' : ''; }
		if ( array_key_exists( 'sponsored', $input ) ) { $params['sponsored'] = ! empty( $input['sponsored'] ) ? '1' : ''; }
		if ( ! empty( $input['cat_id'] ) )       { $params['cat_id'] = absint( $input['cat_id'] ); }
		$params = array_merge( $params, self::shared_link_params( $input ) );

		// An update replaces values that are already live, and the reported
		// complaint was an assistant doing exactly that, repeatedly, without
		// asking. Show what changes first and only write once confirmed.
		$changes = $this->describe_changes( $existing, $params, $input );
		if ( ! empty( $changes ) ) {
			$gate = $this->confirmation_gate(
				$input,
				'update-link',
				sprintf(
					/* translators: 1: link ID, 2: the link's current short URL */
					__( 'Overwrite %2$d field(s) on link ID %1$d, which currently answers on "%3$s". The old values are not kept.', 'betterlinks' ),
					$id,
					count( $changes ),
					isset( $existing['short_url'] ) ? $existing['short_url'] : ''
				),
				$changes
			);
			if ( null !== $gate ) {
				return $gate;
			}
		}

		$result = $this->dispatch( 'PUT', '/links/' . $id, $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array_key_exists( 'favorite', $input ) ) {
			$this->set_favorite( $id, rest_sanitize_boolean( $input['favorite'] ) );
		}

		// Answer with the row as stored, not the payload that was sent.
		$stored = self::stored_link( $id );
		return null === $stored ? $result : [
			'success' => true,
			'data'    => $stored,
		];
	}

	/**
	 * "field: old → new" for every value this call would actually change.
	 *
	 * @param array $existing Stored link row.
	 * @param array $params   Params about to be written.
	 * @param array $input    Raw ability input (for fields stored elsewhere).
	 * @return array<string, string>
	 */
	private function describe_changes( $existing, $params, $input ) {
		$changes = [];
		foreach ( $params as $key => $value ) {
			if ( 'ID' === $key || 'tags_id' === $key ) {
				continue;
			}
			$old = isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
			if ( $old === (string) $value ) {
				continue;
			}
			$changes[ $key ] = sprintf(
				'%s → %s',
				'' === $old ? __( '(empty)', 'betterlinks' ) : $old,
				'' === (string) $value ? __( '(empty)', 'betterlinks' ) : (string) $value
			);
		}
		if ( isset( $params['tags_id'] ) ) {
			$changes['tags'] = sprintf(
				/* translators: %s: comma-separated tag names */
				__( 'replaced with: %s', 'betterlinks' ),
				$params['tags_id'] ? implode( ', ', $params['tags_id'] ) : __( '(none)', 'betterlinks' )
			);
		}
		if ( array_key_exists( 'favorite', $input ) ) {
			$changes['favorite'] = rest_sanitize_boolean( $input['favorite'] ) ? __( 'marked as favorite', 'betterlinks' ) : __( 'removed from favorites', 'betterlinks' );
		}
		return $changes;
	}
}
