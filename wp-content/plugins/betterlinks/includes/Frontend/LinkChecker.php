<?php

namespace BetterLinks\Frontend;
if ( ! defined( 'ABSPATH' ) ) { exit; }

use BetterLinks\Admin\Cache;
use BetterLinks\Traits\Query;

class LinkChecker {
        use Query;

        private $link;
        private $settings;


        public function __construct() {
            $this->settings = (object) Cache::get_json_settings();
            add_filter( 'the_content', array( $this, 'check_links' ), 100 );
        }

        public function check_links( $content) {
            preg_match_all('/<a[^>]*\bclass\s*=\s*["\'][^"\']*\bbetterlinks-linked-text\b[^"\']*["\'][^>]*>(.*?)<\/a>/i', $content, $matches, PREG_OFFSET_CAPTURE);

            $next = 0;
            foreach ($matches[0] as $match) {
                $linkId = $this->get_string_text( $match[0], 'data-link-id' );
                if( empty( $linkId ) ) {
                    continue;
                }

                $link = self::get_link_by_ID(intval($linkId));
                $this->link = is_array( $link ) ? current( $link ) : false;
                if( empty( $this->link ) ) continue;

                $href = $this->get_string_text( $match[0], 'href' );
                if( empty( $href ) ) {
                    continue;
                }
                $href = $this->check_hrefs($href);

                $pattern = '/(<a\s+[^>]*href\s*=\s*["\'])([^"\']*)(["\'][^>]*>)/i';
                $replacement = '${1}' . $href . '${3}';
                $replace = preg_replace($pattern, $replacement, $match[0]);

                // if( empty( $this->link['uncloaked'] ) ){
                //     $pattern = '/\s*data-link-id=["\'][^"\']*["\']/i';
                //     $replace = preg_replace($pattern,  '', $replace);
                // }

                $match[1] = $match[1] + $next; 
                $content  = substr_replace( $content , $replace , $match[1] , strlen( $match[0] ) );

                $next = $next + strlen( $replace ) - strlen( $match[0] );
            }
            return $content;
        }

        private function get_string_text($string, $attr){
            preg_match('/'. $attr .'="([^"]+)"/', $string, $matches);
            if( empty( $matches ) ) return '';
            $value = explode('=', $matches[0]);
            if( empty( $value[1] ) ) return '';
            $value = trim($value[1], '"');
            return $value;
        }

        private function check_hrefs($href) {
            $short_url = site_url('/') . $this->link['short_url'];
            /**
             * Filters the href used for a BetterLinks-linked text link in post content.
             * Defaults to the link's short URL; BetterLinks Pro returns the target URL
             * for uncloaked links.
             *
             * @param string $short_url Short URL.
             * @param array  $link      Link row.
             * @param string $href      Href currently in the content.
             */
            return (string) apply_filters( 'betterlinks/frontend/linked_text_href', $short_url, $this->link, $href );
        }

}
