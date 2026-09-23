<?php
namespace BetterLinks\Services;
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Client IP resolution.
 *
 * REMOTE_ADDR by default; a forwarding header is honoured only when the peer is an
 * operator-configured trusted proxy (option `betterlinks_trusted_proxies`, filters
 * `betterlinks/geolocation/trusted_proxies`, `/forwarded_header`,
 * `/cloudflare_ranges`). Used for click logging and rate limiting.
 */
class ClientIp {

    /**
     * Resolved public client IP, or null.
     *
     * @return string|null
     */
    public static function resolve() {
        return self::get_current_client_ip();
    }

    /**
     * Get current client IP address
     *
     * Only REMOTE_ADDR is trusted by default. Forwarding headers
     * (X-Forwarded-For and friends) are attacker-controlled on any request that
     * does not physically come through a reverse proxy: previously an anonymous
     * caller could send an arbitrary public IP per request, which defeated the
     * per-IP transient cache and forced one fresh outbound geolocation lookup
     * (up to four providers, 10s timeout each) for every request — burning the
     * site owner's upstream quota and tying up PHP workers.
     *
     * A forwarding header is honored only when the immediate peer (REMOTE_ADDR)
     * is inside an operator-configured trusted-proxy range, and then only for a
     * single named header whose chain is parsed from the trusted (right) end.
     *
     * Configure with either:
     *   - option `betterlinks_trusted_proxies` (array or newline/comma separated
     *     list of IPs / CIDRs), or
     *   - filter `betterlinks/geolocation/trusted_proxies`.
     * The header can be swapped with `betterlinks/geolocation/forwarded_header`
     * (e.g. `HTTP_CF_CONNECTING_IP` behind Cloudflare).
     *
     * @return string|null The client IP address or null
     */
    public static function get_current_client_ip() {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
            : '';

        $client_ip = self::get_forwarded_client_ip( $remote_addr );

        if ( null === $client_ip ) {
            $client_ip = $remote_addr;
        }

        // Compatibility fallback. If REMOTE_ADDR is private/reserved, the request
        // definitively arrived through a local load balancer or reverse proxy and
        // REMOTE_ADDR carries no visitor information at all — returning null here
        // would silently switch country detection off for every site on that kind
        // of hosting. Those setups cannot be attacked by varying a header either:
        // the peer is the operator's own proxy. Fall back to the legacy header
        // walk, which is no worse than the previous behaviour for these sites.
        if ( ! filter_var( $client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
            && filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
            $legacy = self::get_legacy_forwarded_ip();

            if ( null !== $legacy ) {
                $client_ip = $legacy;
            }
        }

        // Reject private/reserved space: those are never resolvable to a country
        // and must not be handed to an outbound provider lookup.
        if ( filter_var( $client_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            return $client_ip;
        }

        return null;
    }

    /**
     * Resolve the client IP from a forwarding header, if and only if the request
     * actually arrived through a trusted proxy.
     *
     * @param string $remote_addr The immediate peer address.
     * @return string|null Forwarded client IP, or null to fall back to REMOTE_ADDR.
     */
    private static function get_forwarded_client_ip( $remote_addr ) {
        $trusted = self::get_trusted_proxies();

        if ( empty( $trusted ) || ! self::ip_matches_any( $remote_addr, $trusted ) ) {
            return null;
        }

        // Behind Cloudflare the canonical header is CF-Connecting-IP, and it is a
        // single address rather than a chain. Only reachable when REMOTE_ADDR is a
        // Cloudflare edge, which the trusted-proxy check above has established.
        $default_header = ( self::ip_matches_any( $remote_addr, self::cloudflare_ranges() ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) )
            ? 'HTTP_CF_CONNECTING_IP'
            : 'HTTP_X_FORWARDED_FOR';

        $header = apply_filters( 'betterlinks/geolocation/forwarded_header', $default_header );
        $header = is_string( $header ) ? strtoupper( str_replace( '-', '_', $header ) ) : '';

        if ( '' === $header || empty( $_SERVER[ $header ] ) ) {
            return null;
        }

        $raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );

        // Single-value headers (CF-Connecting-IP, True-Client-IP) carry one address.
        if ( strpos( $raw, ',' ) === false ) {
            $candidate = trim( $raw );
            return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : null;
        }

        // X-Forwarded-For style chain: the rightmost entries were appended by our
        // own proxies, so walk from the trusted end inward and take the first hop
        // that is not itself a trusted proxy. Anything an external client
        // prepended stays to the left of that and is never reached.
        $chain = array_map( 'trim', explode( ',', $raw ) );

        for ( $i = count( $chain ) - 1; $i >= 0; $i-- ) {
            $candidate = $chain[ $i ];

            if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
                // Ambiguous / malformed chain — refuse to guess.
                return null;
            }

            if ( ! self::ip_matches_any( $candidate, $trusted ) ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Operator-configured trusted proxy IPs / CIDRs.
     *
     * @return array
     */
    private static function get_trusted_proxies() {
        $configured = get_option( 'betterlinks_trusted_proxies', array() );

        if ( is_string( $configured ) ) {
            $configured = preg_split( '/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY );
        }

        // Cloudflare is trusted out of the box: it is by far the most common proxy
        // in front of WordPress sites, and without it every Cloudflare-fronted site
        // would suddenly resolve all visitors to a Cloudflare edge IP. Override the
        // whole list — including this default — with the filter below.
        $configured = array_merge( self::cloudflare_ranges(), (array) $configured );
        $configured = apply_filters( 'betterlinks/geolocation/trusted_proxies', $configured );

        return array_values( array_filter( array_map( 'trim', array_map( 'strval', $configured ) ) ) );
    }

    /**
     * Cloudflare's published edge ranges.
     *
     * Source: https://www.cloudflare.com/ips/ — refresh with the
     * `betterlinks/geolocation/cloudflare_ranges` filter if Cloudflare adds a
     * block before the next plugin release.
     *
     * @return array
     */
    private static function cloudflare_ranges() {
        return (array) apply_filters(
            'betterlinks/geolocation/cloudflare_ranges',
            array(
                '173.245.48.0/20',
                '103.21.244.0/22',
                '103.22.200.0/22',
                '103.31.4.0/22',
                '141.101.64.0/18',
                '108.162.192.0/18',
                '190.93.240.0/20',
                '188.114.96.0/20',
                '197.234.240.0/22',
                '198.41.128.0/17',
                '162.158.0.0/15',
                '104.16.0.0/13',
                '104.24.0.0/14',
                '172.64.0.0/13',
                '131.0.72.0/22',
                '2400:cb00::/32',
                '2606:4700::/32',
                '2803:f800::/32',
                '2405:b500::/32',
                '2405:8100::/32',
                '2a06:98c0::/29',
                '2c0f:f248::/32',
            )
        );
    }

    /**
     * Legacy forwarding-header walk.
     *
     * Only used as a fallback when REMOTE_ADDR is private/reserved, i.e. the site
     * sits behind a proxy we could not identify and REMOTE_ADDR is useless. Not
     * reachable on directly-connected sites, where header spoofing is the actual
     * attack.
     *
     * @return string|null
     */
    private static function get_legacy_forwarded_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
        );

        foreach ( $ip_keys as $key ) {
            if ( empty( $_SERVER[ $key ] ) ) {
                continue;
            }

            $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

            if ( strpos( $ip, ',' ) !== false ) {
                $ip = explode( ',', $ip )[0];
            }

            $ip = trim( $ip );

            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $ip;
            }
        }

        return null;
    }

    /**
     * Does $ip fall inside any of the given IPs / CIDR ranges?
     *
     * @param string $ip     Address to test.
     * @param array  $ranges IPs or CIDR blocks.
     * @return bool
     */
    private static function ip_matches_any( $ip, $ranges ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        foreach ( $ranges as $range ) {
            if ( self::ip_in_range( $ip, $range ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * CIDR / exact-address match for both IPv4 and IPv6.
     *
     * @param string $ip    Address to test.
     * @param string $range IP or CIDR block.
     * @return bool
     */
    private static function ip_in_range( $ip, $range ) {
        if ( strpos( $range, '/' ) === false ) {
            $packed_ip    = @inet_pton( $ip );    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $packed_range = @inet_pton( $range ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            return ( false !== $packed_ip && false !== $packed_range && $packed_ip === $packed_range );
        }

        list( $subnet, $bits ) = explode( '/', $range, 2 );

        if ( ! is_numeric( $bits ) ) {
            return false;
        }

        $bits         = (int) $bits;
        $packed_ip    = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $packed_range = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( false === $packed_ip || false === $packed_range || strlen( $packed_ip ) !== strlen( $packed_range ) ) {
            return false;
        }

        $max_bits = strlen( $packed_ip ) * 8;

        if ( $bits < 0 || $bits > $max_bits ) {
            return false;
        }

        $whole_bytes     = intdiv( $bits, 8 );
        $remaining_bits  = $bits % 8;

        if ( $whole_bytes > 0 && strncmp( $packed_ip, $packed_range, $whole_bytes ) !== 0 ) {
            return false;
        }

        if ( 0 === $remaining_bits ) {
            return true;
        }

        $mask = chr( ( 0xff << ( 8 - $remaining_bits ) ) & 0xff );

        return ( ( $packed_ip[ $whole_bytes ] & $mask ) === ( $packed_range[ $whole_bytes ] & $mask ) );
    }
}
