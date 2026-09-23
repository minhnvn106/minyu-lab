<?php
namespace BetterLinks\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Backward-compatibility facade.
 *
 * Country detection moved to BetterLinks Pro. Older Pro builds (before 3.0.4) call
 * these two generic helpers under this class name for proxy-aware IP resolution
 * and their lookup budget, so they keep working until Pro is updated. There is no
 * geolocation code here.
 *
 * @deprecated 3.1.4 Use ClientIp and RateLimiter. Remove once the minimum supported
 *             BetterLinks Pro is 3.0.4.
 */
class CountryDetectionService {

    /**
     * Hourly lookup budget older Pro builds read.
     */
    const MAX_LOOKUPS_PER_HOUR = 5000;

    /**
     * @return string|null
     */
    public static function get_current_client_ip() {
        return ClientIp::get_current_client_ip();
    }

    /**
     * @param string $key    Transient key.
     * @param int    $limit  Allowed hits per window.
     * @param int    $window Window length in seconds.
     * @return bool
     */
    public static function consume_bucket( $key, $limit, $window ) {
        return RateLimiter::consume_bucket( $key, $limit, $window );
    }
}
