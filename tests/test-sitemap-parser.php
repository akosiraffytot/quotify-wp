<?php
/**
 * Standalone sitemap parser tests — no WordPress.
 *
 * Run: php tests/test-sitemap-parser.php
 */

define( 'ABSPATH', 'C:/tmp/' );

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

function fixture( $name ) {
	return file_get_contents( __DIR__ . '/fixtures/' . $name );
}

// Real urlset with a duplicate, an empty loc, and multiple pages.
$real = Sitemap::count_urlset( fixture( 'real-urlset.xml' ) );
check( 'real type', $real['type'], 'urlset' );
check( 'real count (dup + empty loc skipped)', $real['count'], 4 );
check( 'real not capped', $real['capped'], false );

// Index detection + extraction.
check( 'index detected', Sitemap::is_index_sitemap( fixture( 'index-sitemap.xml' ) ), true );
check( 'urlset is not index', Sitemap::is_index_sitemap( fixture( 'real-urlset.xml' ) ), false );
$index = Sitemap::parse_index( fixture( 'index-sitemap.xml' ) );
check( 'index type', $index['type'], 'sitemapindex' );
check( 'index sitemaps deduped', $index['sitemaps'], array( 'https://example.com/sitemap-pages.xml', 'https://example.com/sitemap-posts.xml' ) );

// Prefixed namespace still counted.
$prefixed = Sitemap::count_urlset( fixture( 'prefixed-ns-urlset.xml' ) );
check( 'prefixed ns count', $prefixed['count'], 2 );

// xhtml:link alternates NOT counted, locs are.
$xhtml = Sitemap::count_urlset( fixture( 'xhtml-alternate-urlset.xml' ) );
check( 'xhtml alternate count', $xhtml['count'], 2 );

// image:loc NOT counted.
$image = Sitemap::count_urlset( fixture( 'image-sitemap.xml' ) );
check( 'image sitemap count', $image['count'], 2 );

// Empty urlset.
$empty = Sitemap::count_urlset( fixture( 'empty-urlset.xml' ) );
check( 'empty count', $empty['count'], 0 );
check( 'empty not capped', $empty['capped'], false );

// robots.txt extraction: case-insensitive, indent, inline comment.
$robots = Sitemap::parse_robots_sitemaps( fixture( 'robots.txt' ) );
check(
	'robots urls',
	$robots,
	array(
		'https://example.com/sitemap.xml',
		'https://example.com/sitemap-index.xml',
		'https://example.com/sitemap-3.xml',
	)
);

// CRLF + uppercase + dedupe.
$crlf = "User-agent: *\r\nSitemap: https://a.com/s.xml\r\nsitemap: https://a.com/s.xml\r\nSITEMAP: https://b.com/t.xml\r\n";
check(
	'robots CRLF uppercase + dedupe',
	Sitemap::parse_robots_sitemaps( $crlf ),
	array( 'https://a.com/s.xml', 'https://b.com/t.xml' )
);

// No sitemap lines → empty.
check( 'robots none', Sitemap::parse_robots_sitemaps( "User-agent: *\nDisallow: /x/\n" ), array() );

// Early-stop at 5,001 of 5,100 unique URLs.
$big = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
for ( $i = 1; $i <= 5100; $i++ ) {
	$big .= '<url><loc>https://example.com/p' . $i . '/</loc></url>';
}
$big .= '</urlset>';
$big_result = Sitemap::count_urlset( $big );
check( 'big count capped at 5001', $big_result['count'], 5001 );
check( 'big capped flag', $big_result['capped'], true );

echo "ALL SITEMAP PARSER TESTS PASSED\n";