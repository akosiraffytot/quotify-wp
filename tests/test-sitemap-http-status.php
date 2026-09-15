<?php
/**
 * Standalone offline tests — hosts that return valid sitemap XML with a
 * 4xx status, and the robots.txt-sitemap-404 diagnostic path.
 *
 * Run: php tests/test-sitemap-http-status.php
 */

define( 'ABSPATH', 'C:/tmp/' );
define( 'QUOTIFY_VERSION', '1.3.8' );

class WP_Error {
	private $message;

	public function __construct( $code, $message ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

$GLOBALS['__store'] = array();

function get_transient( $key ) {
	if ( ! isset( $GLOBALS['__store'][ $key ] ) ) {
		return false;
	}
	if ( isset( $GLOBALS['__store'][ $key . '_exp' ] ) && $GLOBALS['__store'][ $key . '_exp' ] < time() ) {
		unset( $GLOBALS['__store'][ $key ], $GLOBALS['__store'][ $key . '_exp' ] );
		return false;
	}
	return $GLOBALS['__store'][ $key ];
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['__store'][ $key ]          = $value;
	$GLOBALS['__store'][ $key . '_exp' ] = time() + $ttl;
}

function delete_transient( $key ) {
	unset( $GLOBALS['__store'][ $key ], $GLOBALS['__store'][ $key . '_exp' ] );
}

function delete_expired_transients( $force_db = false ) {
	$now = time();

	foreach ( $GLOBALS['__store'] as $key => $value ) {
		if ( isset( $GLOBALS['__store'][ $key . '_exp' ] ) && $GLOBALS['__store'][ $key . '_exp' ] < $now ) {
			delete_transient( $key );
		}
	}
}

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__option'][ $key ] ) ? $GLOBALS['__option'][ $key ] : $default;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_http_validate_url( $url ) {
	$parts = parse_url( $url );

	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return false;
	}

	if ( ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
		return false;
	}

	return $url;
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
}

$GLOBALS['__routes'] = array(
	// Good candidate: index carries a 404 status but a valid body.
	'https://statusok.example/robots.txt'                          => array( 200, "User-agent: *\n\nSitemap: https://statusok.example/wp-sitemap.xml\n" ),
	'https://statusok.example/wp-sitemap.xml'                      => array( 404, '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://statusok.example/wp-sitemap-posts-page-1.xml</loc></sitemap></sitemapindex>' ),
	'https://statusok.example/wp-sitemap-posts-page-1.xml'         => array( 200, '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://statusok.example/one</loc></url><url><loc>https://statusok.example/two</loc></url><url><loc>https://statusok.example/one</loc></url></urlset>' ),
	// robots.txt advertises a sitemap that genuinely 404s (non-XML body).
	'https://badrobots.example/robots.txt'                          => array( 200, "User-agent: *\n\nSitemap: https://badrobots.example/sitemap.xml\n" ),
	'https://badrobots.example/sitemap.xml'                         => array( 404, '<html><body>Not Found</body></html>' ),
	// No robots sitemap, every ladder URL 404s: plain no_sitemap.
	'https://plain404.example/robots.txt'                           => array( 200, "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n" ),
);

$GLOBALS['__fetch_count'] = 0;

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__fetch_count']++;

	$routes = $GLOBALS['__routes'];

	if ( isset( $routes[ (string) $url ] ) ) {
		return array(
			'response' => array( 'code' => $routes[ $url ][0] ),
			'body'     => $routes[ $url ][1],
		);
	}

	return array(
		'response' => array( 'code' => 404 ),
		'body'     => '<html>404 not found</html>',
	);
}

require __DIR__ . '/../includes/class-quotify-sitemap.php';

use Quotify\Sitemap;

function check( $label, $actual, $expected ) {
	if ( $actual === $expected ) {
		echo "ok - {$label}\n";
		return;
	}

	fprintf(
		STDERR,
		"FAIL - %s: expected %s, got %s\n",
		$label,
		var_export( $expected, true ),
		var_export( $actual, true )
	);
	exit( 1 );
}

function reset_state() {
	$GLOBALS['__store']       = array();
	$GLOBALS['__option']      = array();
	$GLOBALS['__fetch_count'] = 0;
}

// 1. A robot-listed sitemap served with a 404 status but valid XML body
//    must be accepted (regression for the hosting quirk on wp-sitemap.xml).
reset_state();
$ok = Sitemap::count_site( 'https://statusok.example/' );
check( '404-with-valid-xml status ok', $ok['status'], 'ok' );
check( '404-with-valid-xml count', $ok['count'], 2 );

// 2. robots.txt lists a sitemap whose URL genuinely 404s (HTML body):
//    distinct diagnostic instead of the generic no_sitemap.
reset_state();
$bad = Sitemap::count_site( 'https://badrobots.example/' );
check( 'robots-listed sitemap 404 status', $bad['status'], 'robots_sitemap_404' );

// 3. No robots sitemap and no ladder hit keeps the existing no_sitemap.
reset_state();
$none = Sitemap::count_site( 'https://plain404.example/' );
check( 'plain no_sitemap status', $none['status'], 'no_sitemap' );

// 4. Candidate source flag is exposed for callers.
reset_state();
$from_robots = Sitemap::build_candidate_set( 'https://statusok.example/' )['from_robots'];
check( 'candidates flagged from_robots', $from_robots, true );

reset_state();
$not_robots = Sitemap::build_candidate_set( 'https://plain404.example/' )['from_robots'];
check( 'ladder candidates not from_robots', $not_robots, false );

echo "ALL SITEMAP HTTP STATUS TESTS PASSED\n";