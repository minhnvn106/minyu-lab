<?php
/**
 * Get analytics for one link ability.
 *
 * @package BetterLinks\Abilities\Analytics
 */

declare(strict_types=1);

namespace BetterLinks\Abilities\Analytics;

use BetterLinks\Abilities\Ability_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Get click analytics for a single short link by its ID.
 */
class Get_Link_Analytics extends Ability_Base {

	public function __construct() {
		$this->id          = 'betterlinks/get-link-analytics';
		$this->label       = __( 'Get analytics for one link', 'betterlinks' );
		$this->description = __( 'Get click analytics for a single short link by its ID.', 'betterlinks' );
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
				'link_id' => [ 'type' => 'integer', 'description' => __( 'The link ID. Required.', 'betterlinks' ) ],
				'ID'      => [ 'type' => 'integer', 'description' => __( 'Deprecated alias for link_id.', 'betterlinks' ) ],
				'from' => [ 'type' => 'string', 'default' => '', 'description' => __( 'Start date (Y-m-d). Omit for the last 30 days.', 'betterlinks' ) ],
				'to'   => [ 'type' => 'string', 'default' => '', 'description' => __( 'End date (Y-m-d). Omit for today.', 'betterlinks' ) ],
				'include_ip' => [
					'type'        => 'boolean',
					'description' => __( 'Return visitor IP addresses in full instead of masked. Off by default; these are personal data, so only ask for them when the site owner needs them.', 'betterlinks' ),
				],
			],
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
		$id = isset( $input['link_id'] ) ? absint( $input['link_id'] ) : ( isset( $input['ID'] ) ? absint( $input['ID'] ) : 0 );
		if ( ! $id ) {
			return new \WP_Error( 'betterlinks_missing_link_id', __( 'A link ID is required.', 'betterlinks' ), [ 'status' => 400 ] );
		}
		// An unknown id used to answer success with link_details: null, which
		// reads as "this link has no clicks" rather than "no such link".
		$exists = \BetterLinks\Helper::get_link_by_ID( $id );
		if ( empty( $exists ) ) {
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
		// The clicks controller REQUIRES from/to and 400s without them — it does
		// not apply a default range. The tool documents "omit for the last 30
		// days", so the default is resolved here rather than promised and broken.
		$from = ! empty( $input['from'] ) ? sanitize_text_field( (string) $input['from'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$to   = ! empty( $input['to'] ) ? sanitize_text_field( (string) $input['to'] ) : gmdate( 'Y-m-d' );

		// A reversed range silently returned nothing at all. Swap it and say so,
		// rather than letting the caller read "no clicks" from a typo.
		$swapped = false;
		if ( strtotime( $from ) && strtotime( $to ) && strtotime( $from ) > strtotime( $to ) ) {
			[ $from, $to ] = [ $to, $from ];
			$swapped       = true;
		}

		$result = $this->dispatch(
			'GET',
			'/clicks/' . $id,
			[
				'from' => $from,
				'to'   => $to,
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$include_ip = ! empty( $input['include_ip'] ) && rest_sanitize_boolean( $input['include_ip'] );
		if ( ! $include_ip && isset( $result['data'] ) ) {
			$result['data'] = self::mask_click_ips( $result['data'] );
		}
		if ( $swapped ) {
			$result['notice'] = sprintf(
				/* translators: 1: start date, 2: end date */
				__( 'The dates were the wrong way round, so the range was read as %1$s to %2$s.', 'betterlinks' ),
				$from,
				$to
			);
		}

		return $result;
	}

	/**
	 * Blunt every visitor IP in a click payload.
	 *
	 * Click rows carry the visitor's IP, and this tool hands them to whichever
	 * AI client asked — often a third-party service. An IP is personal data
	 * under the GDPR, so it is masked (IPv4 last octet, IPv6 below the /48)
	 * unless the caller explicitly asks for it with include_ip.
	 *
	 * @param mixed $data Response payload.
	 * @return mixed
	 */
	private static function mask_click_ips( $data ) {
		if ( is_object( $data ) ) {
			$data = json_decode( wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$data[ $key ] = self::mask_click_ips( $value );
				continue;
			}
			if ( in_array( $key, [ 'ip', 'host' ], true ) && is_string( $value ) && '' !== $value ) {
				$data[ $key ] = self::mask_ip( $value );
			}
		}
		return $data;
	}

	/**
	 * @param string $ip
	 * @return string
	 */
	private static function mask_ip( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$blocks = explode( ':', $ip );
			$kept   = array_slice( $blocks, 0, 3 );
			return implode( ':', $kept ) . '::';
		}
		return $ip;
	}
}
