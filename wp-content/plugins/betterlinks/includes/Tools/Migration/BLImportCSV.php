<?php
namespace BetterLinks\Tools\Migration;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Helper;
use BetterLinks\Interfaces\ImportCsvInterface;

class BLImportCSV extends BaseCSV implements ImportCsvInterface {

	private $link_header = array();

	/**
	 * Old link ID => new link ID for links created by this import.
	 *
	 * @var array
	 */
	private $link_id_map = array();

	/**
	 * Rows claimed by extensions (betterlinks/tools/import_row_bucket), by bucket.
	 *
	 * @var array
	 */
	private $extra_rows = array();

	/**
	 * @return array
	 */
	public function get_link_id_map() {
		return $this->link_id_map;
	}

	/**
	 * @return array
	 */
	public function get_extra_rows() {
		return $this->extra_rows;
	}

	public function start_importing( $csv, $optional_param_1 = '' ) {
		$link_message  = array();
		$click_message = array();
		$count         = 0;
		while ( ( $item = fgetcsv( $csv, 0, ',', '"', '"' ) ) !== false ) {
			if ( $count === 0 ) {
				$this->link_header = $item;
				++$count;
				continue;
			}
			// Skip rows with mismatched column count
            if ( count( $item ) !== count( $this->link_header ) ) {
                ++$count;
                continue;
            }
			$item = array_combine( $this->link_header, $item );
			if ( isset( $item['short_url'] ) ) {
				$item['short_url'] = rtrim( $item['short_url'], '/' );
			}
			$item = \BetterLinks\Helper::sanitize_text_or_array_field( $item );

			if ( is_array( $item ) && ( ( isset( $item['click_count'] ) && count( $item ) === 12 ) || ( ! isset( $item['click_count'] ) && count( $item ) === 11 ) ) ) {
				$is_insert = $this->insert_click_data( $item );
				if ( $is_insert ) {
					$click_message[] = 'Imported Successfully "' . $item['short_url'] . '"';
				} else {
					$click_message[] = 'Skipped "' . $item['short_url'] . '" already exists';
				}
			} elseif ( is_array( $item ) && in_array( count( $item ), array( 24, 25, 26, 27 ) ) ) {
				$old_link_id = isset( $item['ID'] ) ? absint( $item['ID'] ) : 0;
				$is_insert   = $this->insert_link_data( $item );
				if ( $is_insert && $old_link_id && is_numeric( $is_insert ) ) {
					$this->link_id_map[ $old_link_id ] = (int) $is_insert;
				}
				if ( $is_insert ) {
					if ( $this->last_operation === 'updated' ) {
						$link_message[] = 'Updated existing "' . $item['short_url'] . '"';
					} else {
						$link_message[] = 'Imported Successfully "' . $item['short_url'] . '"';
					}
				} else {
					$link_message[] = 'Skipped "' . $item['short_url'] . '" already exists';
				}
			} elseif ( is_array( $item ) ) {
				/**
				 * Lets an extension claim CSV rows the core importer does not recognise
				 * (BetterLinks Pro: link rotations). Return a bucket name, or '' to skip.
				 *
				 * @param string $bucket Bucket name.
				 * @param array  $header CSV header row.
				 */
				$bucket = sanitize_key( (string) apply_filters( 'betterlinks/tools/import_row_bucket', '', $this->link_header ) );
				if ( '' !== $bucket ) {
					$this->extra_rows[ $bucket ][] = $item;
				}
			}
		}
		return array(
			'links'  => $link_message,
			'clicks' => $click_message,
		);
	}

	public function insert_link_data( $item ) {
		if ( ! empty( $item['link_title'] ) && ! empty( $item['short_url'] ) ) {
			$link_id = $this->insert_link( $item );
			if ( ! ( empty( $link_id ) || empty( $item['auto_link_keywords'] ) ) ) {
				$auto_link_keywords = unserialize( $item['auto_link_keywords'], array( 'allowed_classes' => false ) );

				foreach ( $auto_link_keywords as $keyword ) {
					['meta_key' => $meta_key, 'meta_value' => $meta_value] = $keyword;  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					if ( Helper::isJson( $meta_value ) ) {
						$meta_value = json_decode( $meta_value, true );
					}
					if ( 'keywords' === $meta_key ) {
						$this->insert_keywords( $link_id, null, $meta_value, $meta_key, true );
					}
				}
			}
			return $link_id;
		}
		return;
	}

	public function insert_click_data( $item ) {
		if ( ! empty( $item['short_url'] ) ) {
			$link_id = \BetterLinks\Helper::insert_click( $item );
			return $link_id;
		}
		return;
	}
}
