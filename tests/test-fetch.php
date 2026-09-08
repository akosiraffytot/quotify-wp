<?php
/**
 * Standalone Phase 4 network harness — requires internet access.
 *
 * Runs the production Sitemap class against real sites via a cURL-backed
 * wp_remote_get shim and in-memory transients.
 *
 * Run: php tests/test-fetch.php
 */

define( 'ABSPATH', 'C:/tmp/' );
define( 'QUOTIFY_VERSION', '1.0.3' );

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
	$GLOBALS['__store'][ $key ]        = $value;
	$GLOBALS['__store'][ $key . '_exp' ] = time() + $ttl;
}

function delete_transient( $key ) {
	unset( $GLOBALS['__store'][ $key ], $GLOBALS['__store'][ $key . '_exp' ] );
}

function get_option( $key, $default = false ) {
	return isset( $GLOBALS['__option'][ $key ] ) ? $GLOBALS['__option'][ $key ] : $default;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
}

$GLOBALS['__fetch_count'] = 0;

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['__fetch_count']++;

	$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 8;
	$ua      = isset( $args['headers']['User-Agent'] ) ? $args['headers']['User-Agent'] : 'Quotify test';

	$ch = curl_init( $url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT      => $ua,
		)
	);
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );

	if ( false === $body ) {
		return new WP_Error( 'http_request_failed', $err ? $err : 'connection failed' );
	}

	return array( 'response' => array( 'code' => $code ), 'body' => $body );
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

// 1. Site with no sitemap at all.
reset_state();
$no_sitemap = Sitemap::count_site( 'https://example.com/' );
check( 'example.com -> no_sitemap', $no_sitemap['status'], 'no_sitemap' );
check( 'example.com null count', $no_sitemap['count'], null );

// 2. Live site with a plain urlset.
reset_state();
$sitemaps_org = Sitemap::count_site( 'https://www.sitemaps.org/' );
check( 'sitemaps.org status ok', $sitemaps_org['status'], 'ok' );
check( 'sitemaps.org count positive', 0 < $sitemaps_org['count'], true );
check( 'sitemaps.org not capped', $sitemaps_org['capped'], false );
$sitemaps_org_count = $sitemaps_org['count'];

// 3. Cache hit: second call performs no network fetches.
$GLOBALS['__fetch_count'] = 0;
$again = Sitemap::count_site( 'https://www.sitemaps.org/' );
check( 'cached status ok', $again['status'], 'ok' );
check( 'cached count matches', $again['count'], $sitemaps_org_count );
check( 'no fetches on cache hit', $GLOBALS['__fetch_count'], 0 );

// 4. Stampede lock: in-flight crawl returns "processing".
reset_state();
set_transient( 'quotify_lock_' . md5( 'https://www.sitemaps.org' ), 1, 20 );
$locked = Sitemap::count_site( 'https://www.sitemaps.org/' );
check( 'lock -> processing', $locked['status'], 'processing' );

// 5. Size limit: a tiny min size trips "too_large".
reset_state();
$GLOBALS['__option']['quotify_settings'] = array( 'limits' => array( 'max_response_size_mb' => 0.0001 ) );
$too_large = Sitemap::count_site( 'https://www.sitemaps.org/' );
check( 'tiny size -> too_large', $too_large['status'], 'too_large' );

// 6. Page cap: a tiny max-pages limit stops the crawl at exactly that many.
reset_state();
$GLOBALS['__option']['quotify_settings'] = array( 'limits' => array( 'max_pages' => 5 ) );
$capped = Sitemap::count_site( 'https://www.sitemaps.org/' );
check( 'tiny max_pages -> ok', $capped['status'], 'ok' );
check( 'tiny max_pages count', $capped['count'], 5 );
check( 'tiny max_pages capped', $capped['capped'], true );

// 7. robots.txt "Sitemap:" lines drive discovery, and the site is an index
//    (dev.mozilla.org) -> recursion into sub-sitemaps.
reset_state();
$mdn = Sitemap::count_site( 'https://developer.mozilla.org/' );
check( 'mdn status ok', $mdn['status'], 'ok' );
check( 'mdn count positive', 0 < $mdn['count'], true );

// 8. Candidate discovery produces URLs for a site with a sitemap.
reset_state();
$candidates = Sitemap::build_candidates( 'https://www.sitemaps.org/' );
check( 'sitemaps.org candidates non-empty', array() !== $candidates, true );

echo "ALL FETCH TESTS PASSED\n";