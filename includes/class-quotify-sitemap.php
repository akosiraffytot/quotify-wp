<?php
/**
 * Quotify sitemap parser + discovery: robots.txt extraction, XMLReader
 * streaming count, HTTP fetch, cache and stampede lock.
 *
 * @package Quotify
 */

namespace Quotify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streaming sitemap parsing and network discovery.
 *
 * Uses WordPress APIs so it always runs under WP; standalone tests provide
 * shims (wp_remote_get over cURL, in-memory transients).
 */
class Sitemap {

	const SITEMAP_NS  = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const OPTION_NAME = 'quotify_settings';
	const UA_BASE     = 'https://github.com/akosiraffytot/quotify-wp';
	const RL_PREFIX   = 'quotify_rl_';

	/**
	 * Default performance limits. Editable in the admin "Performance
	 * limits" section; get_limits() merges saved values over these.
	 *
	 * @return array
	 */
	public static function default_limits(): array {
		return array(
			'max_pages'            => 5001,
			'max_response_size_mb' => 2.0,
			'fetch_timeout'        => 8,
			'cache_ttl'            => 60,
			'lock_ttl'             => 20,
			'max_depth'            => 2,
			'max_subsitemaps'      => 100,
		);
	}

	/**
	 * Effective limits: saved settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get_limits(): array {
		$defaults = self::default_limits();
		$option   = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $option ) || ! isset( $option['limits'] ) || ! is_array( $option['limits'] ) ) {
			return $defaults;
		}

		return array_merge( $defaults, $option['limits'] );
	}

	/**
	 * Whether a submitted website URL is safe to crawl.
	 *
	 * HTTP(S) only, host must resolve, and the (resolved) address must not
	 * be loopback, private or reserved — a basic SSRF guard.
	 *
	 * @param string $url Website URL.
	 * @return bool
	 */
	public static function guard_site_url( string $url ): bool {
		$url   = trim( $url );
		$parts = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';

		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return false;
		}

		if ( false === wp_http_validate_url( $url ) ) {
			return false;
		}

		$host = strtolower( trim( $parts['host'] ) );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! self::ip_is_private( $host );
		}

		$resolved = gethostbyname( $host );

		if ( $resolved === $host || false === filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return ! self::ip_is_private( $resolved );
	}

	/**
	 * Sliding-window per-bucket rate limiter.
	 *
	 * @param string $bucket Bucket discriminator (e.g. client IP).
	 * @param int    $max    Hits allowed per window.
	 * @param int    $window Window size in seconds.
	 * @return bool True when the hit is allowed, false when over the limit.
	 */
	public static function throttle( string $bucket, int $max = 10, int $window = 60 ): bool {
		$key  = self::RL_PREFIX . md5( $bucket );
		$now  = time();
		$hits = get_transient( $key );

		if ( ! is_array( $hits ) ) {
			$hits = array();
		}

		$hits = array_values(
			array_filter(
				$hits,
				static function ( $t ) use ( $now, $window ): bool {
					return ( $now - $window ) < (int) $t;
				}
			)
		);

		if ( $max <= count( $hits ) ) {
			return false;
		}

		$hits[] = $now;
		set_transient( $key, $hits, $window );

		return true;
	}

	/**
	 * Full site sitemap count over the network.
	 *
	 * Statuses:
	 * - ok:          unique page count (may be capped at the page limit)
	 * - no_sitemap:  no candidate URL yielded URLs (missing/404/empty)
	 * - fetch_error: a candidate failed at the network level
	 * - too_large:   a response exceeded the size limit
	 * - processing:  another request owns the stampede lock
	 *
	 * @param string $url Website URL.
	 * @return array
	 */
	public static function count_site( string $url ): array {
		delete_expired_transients( true );

		$root   = self::normalize_root( $url );
		$limits = self::get_limits();

		if ( null === $root ) {
			return array(
				'status' => 'fetch_error',
				'count'  => null,
				'capped' => false,
			);
		}

		$cache_key = 'quotify_count_' . md5( $root );
		$lock_key  = 'quotify_lock_' . md5( $root );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['status'] ) ) {
			return $cached;
		}

		if ( false !== get_transient( $lock_key ) ) {
			return array(
				'status' => 'processing',
				'count'  => null,
				'capped' => false,
			);
		}

		set_transient( $lock_key, 1, (int) $limits['lock_ttl'] );

		$seen            = array();
		$total           = 0;
		$capped          = false;
		$had_fetch_error = false;
		$too_large       = false;

		foreach ( self::build_candidates( $root, $limits ) as $candidate ) {
			if ( $capped ) {
				break;
			}

			$outcome = self::crawl_url( $candidate, $limits, $seen, $capped, $total, 0 );

			if ( 'too_large' === $outcome ) {
				$too_large = true;
				break;
			}

			if ( 'fetch_error' === $outcome ) {
				$had_fetch_error = true;
			}
		}

		delete_transient( $lock_key );

		if ( $too_large ) {
			// Not cached: absent/error outcomes should reflect fixes quickly.
			return array(
				'status' => 'too_large',
				'count'  => null,
				'capped' => false,
			);
		}

		if ( 0 < $total || $capped ) {
			$result = array(
				'status' => 'ok',
				'count'  => $total,
				'capped' => $capped,
			);
			set_transient( $cache_key, $result, ( 60 * (int) $limits['cache_ttl'] ) );

			return $result;
		}

		// Not cached: only successful counts are kept for the cache lifetime.
		return array(
			'status' => $had_fetch_error ? 'fetch_error' : 'no_sitemap',
			'count'  => null,
			'capped' => false,
		);
	}

	/**
	 * Candidate sitemap URLs for a website.
	 *
	 * Robots.txt "Sitemap:" lines win; otherwise a known-path ladder is
	 * returned, absolutized against the normalized scheme+host.
	 *
	 * @param string     $url    Website URL.
	 * @param array|null $limits Optional limits array (defaults when null).
	 * @return array
	 */
	public static function build_candidates( string $url, ?array $limits = null ): array {
		$root = self::normalize_root( $url );

		if ( null === $root ) {
			return array();
		}

		$limits = $limits ?? self::get_limits();

		$robots = self::fetch_sitemap( $root . '/robots.txt', (float) $limits['max_response_size_mb'], (int) $limits['fetch_timeout'] );

		if ( $robots['ok'] ) {
			$found = self::parse_robots_sitemaps( $robots['body'] );

			if ( ! empty( $found ) ) {
				return self::filter_candidates( $found, $root );
			}
		}

		$ladder = array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml', '/sitemap/index.xml', '/sitemap.php' );
		$abs    = array();

		foreach ( $ladder as $path ) {
			$abs[] = $root . $path;
		}

		return $abs;
	}

	/**
	 * Extract Sitemap: lines from a robots.txt body.
	 *
	 * Case-insensitive, CRLF-safe, leading indentation allowed, inline
	 * "# comments" stripped. Returns deduped URLs in document order.
	 *
	 * @param string $body robots.txt content.
	 * @return array
	 */
	public static function parse_robots_sitemaps( string $body ): array {
		$found = array();

		if ( ! preg_match_all( '/(?:^|\n)[ \t]*sitemap[ \t]*:[ \t]*([^\s#]+)/i', $body, $matches ) ) {
			return $found;
		}

		foreach ( $matches[1] as $raw ) {
			$url = trim( $raw );

			if ( '' !== $url && ! in_array( $url, $found, true ) ) {
				$found[] = $url;
			}
		}

		return $found;
	}

	/**
	 * Stream-count unique <loc> URLs in a urlset document.
	 *
	 * Stops early once the max-pages limit is reached. image:loc and
	 * xhtml:link URLs are never counted.
	 *
	 * @param string $xml Sitemap XML string.
	 * @return array
	 */
	public static function count_urlset( string $xml ): array {
		$seen   = array();
		$capped = false;
		$count  = self::gather_urlset( $xml, $seen, (int) self::get_limits()['max_pages'], $capped );

		return array(
			'type'   => 'urlset',
			'count'  => $count,
			'capped' => $capped,
		);
	}

	/**
	 * Stream-extract the <loc> sub-sitemap URLs from an index document.
	 *
	 * @param string $xml Sitemap index XML string.
	 * @return array
	 */
	public static function parse_index( string $xml ): array {
		$sitemaps = array();

		$reader = new \XMLReader();
		if ( ! self::open_reader( $reader, $xml ) ) {
			return array(
				'type'     => 'sitemapindex',
				'sitemaps' => array(),
			);
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType || ! self::is_loc_element( $reader ) ) {
				continue;
			}

			$url = trim( $reader->readString() );

			if ( '' !== $url && ! in_array( $url, $sitemaps, true ) ) {
				$sitemaps[] = $url;
			}
		}

		$reader->close();

		return array(
			'type'     => 'sitemapindex',
			'sitemaps' => $sitemaps,
		);
	}

	/**
	 * Whether the document's root element is a sitemap index.
	 *
	 * @param string $xml Sitemap XML string.
	 * @return bool
	 */
	public static function is_index_sitemap( string $xml ): bool {
		$reader = new \XMLReader();
		if ( ! self::open_reader( $reader, $xml ) ) {
			return false;
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType ) {
				$local_name = $reader->localName;
				$reader->close();

				return 'sitemapindex' === $local_name;
			}
		}

		$reader->close();

		return false;
	}

	/**
	 * Fetch and (possibly) count a sitemap URL, recursing into indexes.
	 *
	 * Returns a fetch outcome string: 'ok', 'not_found' (HTTP miss),
	 * 'fetch_error' (network level) or 'too_large'.
	 *
	 * @param string $url    URL to fetch.
	 * @param array  $limits Effective limits.
	 * @param array  $seen   Shared seen-url set.
	 * @param bool   $capped Whether the page cap was reached.
	 * @param int    $total  Running total (by reference).
	 * @param int    $depth  Recursion depth.
	 * @return string
	 */
	private static function crawl_url( string $url, array $limits, array &$seen, bool &$capped, int &$total, int $depth ): string {
		if ( $depth > (int) $limits['max_depth'] ) {
			return 'ok';
		}

		$fetch = self::fetch_sitemap( $url, (float) $limits['max_response_size_mb'], (int) $limits['fetch_timeout'] );

		if ( ! $fetch['ok'] ) {
			return $fetch['error'];
		}

		if ( self::is_index_sitemap( $fetch['body'] ) ) {
			$parsed = self::parse_index( $fetch['body'] );
			$subs   = array_slice( $parsed['sitemaps'], 0, (int) $limits['max_subsitemaps'] );

			foreach ( $subs as $sub ) {
				if ( $capped ) {
					break;
				}

				$outcome = self::crawl_url( $sub, $limits, $seen, $capped, $total, $depth + 1 );

				if ( 'too_large' === $outcome ) {
					return 'too_large';
				}
			}

			return 'ok';
		}

		$total += self::gather_urlset( $fetch['body'], $seen, (int) $limits['max_pages'], $capped );

		return 'ok';
	}

	/**
	 * Add unique <loc> URLs from a urlset into the shared seen-set.
	 *
	 * @param string $xml    Urlset XML string.
	 * @param array  $seen   Shared seen-url set (by reference).
	 * @param int    $cap    Maximum page count.
	 * @param bool   $capped Whether the cap was reached (by reference).
	 * @return int
	 */
	private static function gather_urlset( string $xml, array &$seen, int $cap, bool &$capped ): int {
		$added  = 0;
		$reader = new \XMLReader();

		if ( ! self::open_reader( $reader, $xml ) ) {
			return 0;
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType || ! self::is_loc_element( $reader ) ) {
				continue;
			}

			$url = trim( $reader->readString() );

			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;
			++$added;

			if ( $cap <= count( $seen ) ) {
				$capped = true;
				break;
			}
		}

		$reader->close();

		return $added;
	}

	/**
	 * Fetch a URL via wp_remote_get with a timeout cap.
	 *
	 * The body is measured against the size limit after retrieval — WP's
	 * "stream" + "limit_response_size" combination returns an empty body
	 * on WordPress 7.1, so streaming is deliberately avoided.
	 *
	 * Gzip-magic payloads are gzdecode'd. Returns:
	 * [ 'ok' => bool, 'body' => ?string, 'error' => ?string ] where error is
	 * 'not_found', 'fetch_error' or 'too_large'.
	 *
	 * @param string $url     URL to fetch.
	 * @param float  $size_mb Response size limit in megabytes.
	 * @param int    $timeout Fetch timeout in seconds.
	 * @return array
	 */
	private static function fetch_sitemap( string $url, float $size_mb, int $timeout ): array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array( 'User-Agent' => 'Quotify/' . \QUOTIFY_VERSION . ' (+' . self::UA_BASE . ')' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'body'  => null,
				'error' => 'fetch_error',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! $code || 400 <= $code ) {
			return array(
				'ok'    => false,
				'body'  => null,
				'error' => 'not_found',
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return array(
				'ok'    => false,
				'body'  => null,
				'error' => 'not_found',
			);
		}

		if ( strlen( $body ) > (int) ( $size_mb * 1024 * 1024 ) ) {
			return array(
				'ok'    => false,
				'body'  => null,
				'error' => 'too_large',
			);
		}

		if ( 2 <= strlen( $body ) && "\x1f\x8b" === substr( $body, 0, 2 ) && function_exists( 'gzdecode' ) ) {
			$decoded = gzdecode( $body );

			if ( false !== $decoded ) {
				$body = $decoded;
			}
		}

		return array(
			'ok'    => true,
			'body'  => $body,
			'error' => null,
		);
	}

	/**
	 * Normalize a website URL to scheme://host[:port].
	 *
	 * HTTP/HTTPS only; defaults to https when no scheme is given.
	 *
	 * @param string $url Raw URL.
	 * @return string|null
	 */
	private static function normalize_root( string $url ): ?string {
		$url   = trim( $url );
		$parts = '' !== $url ? wp_parse_url( $url ) : false;

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';

		if ( 'https' !== $scheme && 'http' !== $scheme ) {
			return null;
		}

		$root = $scheme . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$root .= ':' . $parts['port'];
		}

		return $root;
	}

	/**
	 * Normalize and filter a list of candidate sitemap URLs.
	 *
	 * Relative URLs are absolutized against the root; non-http(s) entries
	 * are dropped; results are deduped preserving order.
	 *
	 * @param array  $urls Candidate URLs.
	 * @param string $root Normalized root.
	 * @return array
	 */
	private static function filter_candidates( array $urls, string $root ): array {
		$out = array();

		foreach ( $urls as $url ) {
			$url = trim( (string) $url );

			if ( '' === $url ) {
				continue;
			}

			if ( str_starts_with( $url, '/' ) ) {
				$url = $root . $url;
			}

			if ( ! preg_match( '#^https?://#i', $url ) || in_array( $url, $out, true ) ) {
				continue;
			}

			$out[] = $url;
		}

		return $out;
	}

	/**
	 * Whether an IP address is loopback, private or reserved.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function ip_is_private( string $ip ): bool {
		$ipv4 = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		$ipv6 = false === $ipv4 ? filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) : false;

		if ( false === $ipv4 && false === $ipv6 ) {
			return true;
		}

		if (
			false !== $ipv4
			&& false === filter_var( $ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
		) {
			return true;
		}

		if (
			false !== $ipv6
			&& false === filter_var( $ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
		) {
			return true;
		}

		return false;
	}

	/**
	 * Open an XMLReader over a string, suppressing libxml noise.
	 *
	 * @param \XMLReader $reader Reader instance.
	 * @param string     $xml    XML string.
	 * @return bool
	 */
	private static function open_reader( \XMLReader $reader, string $xml ): bool {
		libxml_use_internal_errors( true );
		$ok = $reader->XML( $xml, 'UTF-8' );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		return $ok;
	}

	/**
	 * Whether the current element is a sitemap <loc>.
	 *
	 * Matches on local name plus namespace: the sitemap namespace or an
	 * empty namespace (prefixed-but-standard docs included). Extension
	 * namespaces such as image:loc are excluded.
	 *
	 * @param \XMLReader $reader Reader positioned on an element.
	 * @return bool
	 */
	private static function is_loc_element( \XMLReader $reader ): bool {
		if ( 'loc' !== $reader->localName ) {
			return false;
		}

		$ns = (string) $reader->namespaceURI;

		return '' === $ns || self::SITEMAP_NS === $ns;
	}
}
