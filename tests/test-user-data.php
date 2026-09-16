<?php
/**
 * Standalone saved-scan user meta + shortcode tests — no WordPress.
 *
 * Run: php tests/test-user-data.php
 */

define( 'ABSPATH', 'C:/tmp/' );

function plugin_dir_path( $file ) {
	return dirname( $file ) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url( $file ) {
	return 'http://unit.test/wp-content/plugins/quotify/';
}

function shortcode_atts( $defaults, $atts, $tag ) {
	$atts = is_array( $atts ) ? $atts : array();
	return array_merge( $defaults, array_intersect_key( $atts, $defaults ) );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, $decimals );
}

function date_i18n( $format, $timestamp = null ) {
	return gmdate( (string) $format, (int) $timestamp );
}

$GLOBALS['__settings'] = array(
	'tiers' => array(
		array(
			'min'          => 1,
			'max'          => 5000,
			'price'        => 120.0,
			'variation_id' => '',
			'url'          => '',
		),
		array(
			'min'          => 5001,
			'max'          => '',
			'price'        => 450.0,
			'variation_id' => '',
			'url'          => '',
		),
	),
);

function get_option( $option, $default = false ) {
	$values = array(
		'date_format'     => 'Y-m-d',
		'time_format'     => 'H:i',
		'quotify_settings' => $GLOBALS['__settings'],
	);
	return $values[ $option ] ?? $default;
}

$GLOBALS['__current_user_id'] = 0;
$GLOBALS['__logged_in']       = false;
$GLOBALS['__user_meta']       = array();

function get_current_user_id() {
	return $GLOBALS['__current_user_id'];
}

function is_user_logged_in() {
	return $GLOBALS['__logged_in'];
}

function update_user_meta( $user_id, $key, $value, $prev = '' ) {
	$GLOBALS['__user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function get_user_meta( $user_id, $key, $single = false ) {
	return $GLOBALS['__user_meta'][ $user_id ][ $key ] ?? '';
}

require __DIR__ . '/../quotify.php';

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

// 1. quotify_save_scan_meta / quotify_get_saved_scan round-trip.
quotify_save_scan_meta( 7, 5001, 1234567890, 'https://example.test/sitemap/' );
$saved = quotify_get_saved_scan( 7 );
check( 'saved count', $saved['count'], 5001 );
check( 'saved timestamp', $saved['scanned_at'], 1234567890 );
check( 'saved url', $saved['url'], 'https://example.test/sitemap/' );
check( 'meta keys', $GLOBALS['__user_meta'][7]['quotify_page_count'], 5001 );
check( 'meta stamped key', $GLOBALS['__user_meta'][7]['quotify_scanned_at'], 1234567890 );
check( 'meta url key', $GLOBALS['__user_meta'][7]['quotify_scanned_url'], 'https://example.test/sitemap/' );

// 2. Never-scanned user returns empty record.
check( 'no record count', quotify_get_saved_scan( 99 )['count'], 0 );
check( 'no record stamped', quotify_get_saved_scan( 99 )['scanned_at'], 0 );
check( 'no record url', quotify_get_saved_scan( 99 )['url'], '' );

// 3. Logged-out visitors: empty output, placeholder on demand.
$GLOBALS['__logged_in'] = false;
check( 'logged out count empty', quotify_saved_count_shortcode( array() ), '' );
check( 'logged out scanned empty', quotify_saved_scanned_shortcode( array() ), '' );
check( 'logged out url empty', quotify_saved_url_shortcode( array() ), '' );
check( 'logged out placeholder', quotify_saved_count_shortcode( array( 'placeholder' => '--' ) ), '--' );

// 4. Logged-in user with no scan yet: empty + placeholder.
$GLOBALS['__logged_in']       = true;
$GLOBALS['__current_user_id'] = 8;
check( 'no scan count empty', quotify_saved_count_shortcode( array() ), '' );
check( 'no scan placeholder', quotify_saved_scanned_shortcode( array( 'placeholder' => 'Never' ) ), 'Never' );

// 5. Logged-in user with saved data renders server-side.
$GLOBALS['__current_user_id'] = 7;
check( 'count shortcode', quotify_saved_count_shortcode( array() ), '5,001' );
check( 'count ignores placeholder when set', quotify_saved_count_shortcode( array( 'placeholder' => '--' ) ), '5,001' );
check( 'scanned shortcode format', quotify_saved_scanned_shortcode( array() ), '2009-02-13 23:31' );
check( 'url shortcode', quotify_saved_url_shortcode( array() ), 'https://example.test/sitemap/' );

// 6. Batch: re-scan overwrites the previous record.
quotify_save_scan_meta( 7, 142, 999, 'https://other.test/' );
check( 'rescan count overwrites', quotify_saved_count_shortcode( array() ), '142' );
check( 'rescan url overwrites', quotify_saved_url_shortcode( array() ), 'https://other.test/' );
quotify_save_scan_meta( 7, 5001, 1234567890, 'https://example.test/sitemap/' );

// 7. Escaping: URL and placeholder values are escaped on output.
$GLOBALS['__user_meta'][7]['quotify_scanned_url'] = 'https://evil.test/?q="><script>alert(1)</script>';
check( 'url escaped', strpos( quotify_saved_url_shortcode( array() ), '<script>' ), false );
check( 'placeholder escaped', strpos( quotify_saved_count_shortcode( array( 'placeholder' => '<b>&</b>' ) ), '<b>' ), false );

// 8. Saved shortcodes never flag the page for AJAX asset enqueueing.
unset( $GLOBALS['quotify_shortcode_rendered'] );
quotify_saved_count_shortcode( array() );
check( 'saved shortcode sets no enqueue flag', isset( $GLOBALS['quotify_shortcode_rendered'] ), false );

// 9. The shortcode reference (doc/doc.html) documents every shortcode.
$reference = file_get_contents( __DIR__ . '/../doc/doc.html' );
check( 'reference file exists', false !== $reference, true );
foreach ( array( 'quotify', 'quotify_count', 'quotify_price', 'quotify_quote', 'quotify_saved_count', 'quotify_saved_scanned', 'quotify_saved_url' ) as $tag ) {
	check( "reference documents [{$tag}]", false !== strpos( (string) $reference, "[{$tag}]" ), true );
}

// 10. Bricks tag_value: user 7 has a saved scan of 5001 pages.
$GLOBALS['__current_user_id'] = 7;
check( 'bricks count tag', Quotify\Bricks::tag_value( 'quotify_page_count' ), '5,001' );
check( 'bricks price tag matches open tier', Quotify\Bricks::tag_value( 'quotify_price' ), '$450' );

// A smaller scan matches the first tier.
quotify_save_scan_meta( 7, 2500, 1234567890, 'https://example.test/' );
check( 'bricks price matches lower tier', Quotify\Bricks::tag_value( 'quotify_price' ), '$120' );
check( 'bricks count after re-scan', Quotify\Bricks::tag_value( 'quotify_page_count' ), '2,500' );

// Logged-out visitors get an empty string.
quotify_save_scan_meta( 7, 5001, 1234567890, 'https://example.test/' );
$GLOBALS['__logged_in'] = false;
check( 'bricks logged out count empty', Quotify\Bricks::tag_value( 'quotify_page_count' ), '' );
check( 'bricks logged out price empty', Quotify\Bricks::tag_value( 'quotify_price' ), '' );
$GLOBALS['__logged_in'] = true;

// Never-scanned user gets an empty string.
$GLOBALS['__current_user_id'] = 8;
check( 'bricks no scan count empty', Quotify\Bricks::tag_value( 'quotify_page_count' ), '' );
check( 'bricks no scan price empty', Quotify\Bricks::tag_value( 'quotify_price' ), '' );
$GLOBALS['__current_user_id'] = 7;

// Unknown tags pass through untouched in the single-tag and content paths.
check( 'bricks unknown tag passthrough', Quotify\Bricks::render_tag( '{the_title}', null, 'text' ), '{the_title}' );
check( 'bricks non-string passthrough', Quotify\Bricks::render_tag( array( 'x' => 1 ), null, 'text' ), array( 'x' => 1 ) );
$content = '<p>{the_title}</p>';
check( 'bricks content no tag untouched', Quotify\Bricks::render_content( $content, null, 'text' ), $content );
$content = '<p>{quotify_page_count} pages — {quotify_price}</p>';
check( 'bricks content replaces tags', Quotify\Bricks::render_content( $content, null, 'text' ), '<p>5,001 pages — $450</p>' );

echo "ALL USER-DATA TESTS PASSED\n";