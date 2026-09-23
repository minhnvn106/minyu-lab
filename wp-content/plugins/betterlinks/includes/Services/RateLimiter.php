<?php
namespace BetterLinks\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fixed-window rate limiter backed by transients.
 */
class RateLimiter {

    /**
     * Fixed-window counter shared by the lookup budget and the REST rate limiter.
     *
     * @param string $key    Transient key.
     * @param int    $limit  Allowed hits per window.
     * @param int    $window Window length in seconds.
     * @return bool True when the hit is within budget.
     */
    public static function consume_bucket( $key, $limit, $window ) {
        $bucket = get_transient( $key );
        $now    = time();

        if ( ! is_array( $bucket ) || ! isset( $bucket['start'], $bucket['count'] ) || ( $now - (int) $bucket['start'] ) >= $window ) {
            $bucket = array(
                'start' => $now,
                'count' => 0,
            );
        }

        ++$bucket['count'];

        // Keep the transient alive only for the remainder of the current window
        // so the counter cannot be held open indefinitely by continued traffic.
        $ttl = max( 1, $window - ( $now - (int) $bucket['start'] ) );
        set_transient( $key, $bucket, $ttl );

        return ( $bucket['count'] <= $limit );
    }
}
