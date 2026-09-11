<?php
/**
 * Standalone pricing tests — no WordPress.
 *
 * Run: php tests/test-pricing.php
 */

define( 'ABSPATH', 'C:/tmp/' );
define( 'QUOTIFY_URL', 'http://unit.test/wp-content/plugins/quotify/' );
define( 'QUOTIFY_VERSION', '1.3.1' );

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( $number, $decimals );
}

function __( $text ) {
	return $text;
}

require __DIR__ . '/../includes/class-quotify-admin.php';

use Quotify\Admin;

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

$tiers = array(
	array( 'min' => 0,    'max' => '50',    'price' => 120.0 ),
	array( 'min' => 51,   'max' => '150',   'price' => 160.0 ),
	array( 'min' => 151,  'max' => '300',   'price' => 200.0 ),
	array( 'min' => 301,  'max' => '1000',  'price' => 235.0 ),
	array( 'min' => 1001, 'max' => '2500',  'price' => 400.0 ),
	array( 'min' => 2501, 'max' => '5000',  'price' => 500.0 ),
	array( 'min' => 5001, 'max' => '',      'price' => 500.0 ),
);

// Boundary edges: inclusive at both min and max.
check( 'count 50', Admin::get_price( 50, $tiers ), 120.0 );
check( 'count 51', Admin::get_price( 51, $tiers ), 160.0 );
check( 'count 150', Admin::get_price( 150, $tiers ), 160.0 );
check( 'count 151', Admin::get_price( 151, $tiers ), 200.0 );
check( 'count 300', Admin::get_price( 300, $tiers ), 200.0 );
check( 'count 301', Admin::get_price( 301, $tiers ), 235.0 );
check( 'count 2500', Admin::get_price( 2500, $tiers ), 400.0 );
check( 'count 5000', Admin::get_price( 5000, $tiers ), 500.0 );

// Open-ended last tier.
check( 'count 5001', Admin::get_price( 5001, $tiers ), 500.0 );
check( 'count 20000', Admin::get_price( 20000, $tiers ), 500.0 );

// Unmatched / gap tiers.
$gap_tiers = array(
	array( 'min' => 51,  'max' => '150', 'price' => 160.0 ),
	array( 'min' => 201, 'max' => '',    'price' => 250.0 ),
);
check( 'count 0 no min-0 tier', Admin::get_price( 0, $gap_tiers ), null );
check( 'count 175 gap', Admin::get_price( 175, $gap_tiers ), null );

// Labels.
check( 'label null', Admin::price_label( null ), 'Contact us for a custom quote.' );
check( 'label whole', Admin::price_label( 235.0 ), '$235' );
check( 'label open tier', Admin::price_label( 500.0 ), '$500' );

// Checkout URL building.
check(
	'url tokens',
	Admin::build_checkout_url( 50, 120.0, 'https://x.com/c?p={page_count}&t={total_price}', 'https://bikes.example' ),
	'https://x.com/c?p=50&t=120'
);
check(
	'url trim half',
	Admin::build_checkout_url( 175, 160.5, 'https://x.com/c?t={total_price}', 'https://bikes.example' ),
	'https://x.com/c?t=160.5'
);
check(
	'url cents',
	Admin::build_checkout_url( 305, 235.75, 'https://x.com/c?t={total_price}', 'https://bikes.example' ),
	'https://x.com/c?t=235.75'
);
check(
	'url no tokens',
	Admin::build_checkout_url( 10, 120.0, 'https://x.com/c', 'https://bikes.example' ),
	'https://x.com/c'
);
check(
	'url site token',
	Admin::build_checkout_url( 50, 120.0, 'https://checkout.com/?site={site}&p={page_count}&t={total_price}', 'https://bikes.example' ),
	'https://checkout.com/?site=https://bikes.example&p=50&t=120'
);

// match_tier returns the full tier (including url), get_price stays a float.
$tiered = array(
	array( 'min' => 0,   'max' => '50',  'price' => 120.0, 'url' => 'https://tier.com/?p={page_count}&t={total_price}' ),
	array( 'min' => 51,  'max' => '',    'price' => 160.0, 'url' => '' ),
);
check( 'match_tier returns tier with url', Admin::match_tier( 10, $tiered ), $tiered[0] );
check( 'match_tier blank url returns tier', Admin::match_tier( 60, $tiered ), $tiered[1] );
check( 'match_tier no match null', Admin::match_tier( 0, array() ), null );
check( 'get_price delegates', Admin::get_price( 10, $tiered ), 120.0 );
check( 'get_price null when no match', Admin::get_price( 0, array() ), null );

// match_tier carries the FluentCart variation_id through to the caller.
$tiered_v = array(
	array( 'min' => 0,   'max' => '50', 'price' => 120.0, 'variation_id' => 113, 'url' => '' ),
	array( 'min' => 51,  'max' => '',   'price' => 160.0, 'variation_id' => '',  'url' => '' ),
);
check( 'match_tier carries variation_id', Admin::match_tier( 10, $tiered_v ), $tiered_v[0] );
check( 'match_tier blank variation tier', Admin::match_tier( 60, $tiered_v ), $tiered_v[1] );
check(
	'tier url token substitution',
	Admin::build_checkout_url( 10, 120.0, $tiered[0]['url'], 'https://bikes.example' ),
	'https://tier.com/?p=10&t=120'
);
check(
	'tier url site token',
	Admin::build_checkout_url( 10, 120.0, 'https://tier.com/?site={site}', 'https://bikes.example' ),
	'https://tier.com/?site=https://bikes.example'
);

echo "ALL PRICING TESTS PASSED\n";