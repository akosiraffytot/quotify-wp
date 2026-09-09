<?php
/**
 * Standalone shortcode render tests — no WordPress.
 *
 * Run: php tests/test-shortcode.php
 */

define( 'ABSPATH', 'C:/tmp/' );

function plugin_dir_path( $file ) {
	return dirname( $file ) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url( $file ) {
	return 'http://example.test/wp-content/plugins/quotify/';
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

function esc_html_e( $text, $domain = 'default' ) {
	echo esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['__test_logged_in'] = false;
$GLOBALS['__test_user_url'] = '';

function is_user_logged_in() {
	return $GLOBALS['__test_logged_in'];
}

function wp_get_current_user() {
	return (object) array( 'user_url' => $GLOBALS['__test_user_url'] );
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

// 1. [quotify] defaults render button, status area and all three fields.
$html = quotify_shortcode( array() );
check( 'default button label', strpos( $html, '>Estimate</button>' ) !== false, true );
check( 'default status area', strpos( $html, 'class="quotify-status"' ) !== false, true );
check( 'default count field', strpos( $html, 'data-quotify-field="count"' ) !== false, true );
check( 'default price field', strpos( $html, 'data-quotify-field="price"' ) !== false, true );
check( 'default quote field', strpos( $html, 'data-quotify-field="quote"' ) !== false, true );
check( 'default quote label', strpos( $html, 'data-quotify-quote-label="Get a Quote"' ) !== false, true );

// 2. Custom button + quote label, HTML-escaped.
$html = quotify_shortcode( array( 'button' => "Let's Go", 'quote_label' => 'Start Now' ) );
check( 'custom button label', strpos( $html, ">Let&#039;s Go</button>" ) !== false, true );
check( 'custom quote label', strpos( $html, 'data-quotify-quote-label="Start Now"' ) !== false, true );

// 3. show_* toggles with both truthy and falsy values.
$html = quotify_shortcode( array( 'show_pages' => '0', 'show_price' => 'false', 'show_quote' => 0 ) );
check( 'hidden pages field', strpos( $html, 'data-quotify-field="count"' ), false );
check( 'hidden price field', strpos( $html, 'data-quotify-field="price"' ), false );
check( 'hidden quote field', strpos( $html, 'data-quotify-field="quote"' ), false );
check( 'status area kept', strpos( $html, 'class="quotify-status"' ) !== false, true );

$html = quotify_shortcode( array( 'show_pages' => 1, 'show_price' => 'true' ) );
check( 'pages shown', strpos( $html, 'data-quotify-field="count"' ) !== false, true );
check( 'price shown', strpos( $html, 'data-quotify-field="price"' ) !== false, true );

// 4. [quotify_count] empty vs placeholder.
$html = quotify_count_shortcode( array() );
check( 'count default', $html, '<span class="quotify-field quotify-count" data-quotify-field="count" data-quotify-placeholder=""></span>' );
check( 'count placeholder', quotify_count_shortcode( array( 'placeholder' => '--' ) ) === '<span class="quotify-field quotify-count" data-quotify-field="count" data-quotify-placeholder="--">--</span>', true );

// 5. [quotify_price] empty vs placeholder.
$html = quotify_price_shortcode( array() );
check( 'price default', $html, '<span class="quotify-field quotify-price" data-quotify-field="price" data-quotify-placeholder=""></span>' );
check( 'price placeholder', quotify_price_shortcode( array( 'placeholder' => '$0' ) ) === '<span class="quotify-field quotify-price" data-quotify-field="price" data-quotify-placeholder="$0">$0</span>', true );

// 6. [quotify_quote] default label, custom label and escaping.
$html = quotify_quote_shortcode( array() );
check( 'quote default label', strpos( $html, 'data-quotify-quote-label="Get a Quote"' ) !== false, true );
check( 'quote link class', strpos( $html, 'quotify-quote-link' ) !== false, true );
check( 'quote custom label', strpos( quotify_quote_shortcode( array( 'label' => 'Buy Now' ) ), 'data-quotify-quote-label="Buy Now"' ) !== false, true );

$html = quotify_quote_shortcode( array( 'label' => 'Buy <script>alert(1)</script>' ) );
check( 'quote label escaped', strpos( $html, '<script>' ), false );

$html = quotify_count_shortcode( array( 'placeholder' => '<b>&</b>' ) );
check( 'placeholder escaped in attr + body', strpos( $html, '<b>' ), false );

// 7. Field shortcodes flag the page for asset enqueueing.
unset( $GLOBALS['quotify_shortcode_rendered'] );
quotify_price_shortcode( array() );
check( 'field sets enqueue flag', isset( $GLOBALS['quotify_shortcode_rendered'] ) && $GLOBALS['quotify_shortcode_rendered'], true );

// 8. mode attribute: default/input render editable field.
$GLOBALS['__test_logged_in'] = false;
$html = quotify_shortcode( array( 'mode' => 'input' ) );
check( 'input mode no readonly', strpos( $html, 'readonly' ), false );
check( 'input mode no value attr', strpos( $html, 'value=' ), false );
$html = quotify_shortcode( array( 'mode' => 'garbage' ) );
check( 'unknown mode falls back to input', strpos( $html, 'readonly' ), false );

// 9. user mode, logged out -> editable fallback (silent).
$GLOBALS['__test_logged_in'] = false;
$GLOBALS['__test_user_url'] = 'https://prof.example';
$html = quotify_shortcode( array( 'mode' => 'user' ) );
check( 'user mode logged out no readonly', strpos( $html, 'readonly' ), false );
check( 'user mode logged out no value', strpos( $html, 'https://prof.example' ), false );

// 10. user mode, logged in with profile URL -> readonly prefilled.
$GLOBALS['__test_logged_in'] = true;
$GLOBALS['__test_user_url'] = 'https://prof.example';
$html = quotify_shortcode( array( 'mode' => 'user' ) );
check( 'user mode readonly', strpos( $html, 'readonly' ) !== false, true );
check( 'user mode prefilled value', strpos( $html, 'value="https://prof.example"' ) !== false, true );

// 11. user mode, logged in but empty / malformed profile URL -> editable fallback.
$GLOBALS['__test_logged_in'] = true;
$GLOBALS['__test_user_url'] = '';
$html = quotify_shortcode( array( 'mode' => 'user' ) );
check( 'user mode empty url no readonly', strpos( $html, 'readonly' ), false );
$GLOBALS['__test_user_url'] = 'javascript:alert(1)';
$html = quotify_shortcode( array( 'mode' => 'user' ) );
check( 'user mode bad scheme no readonly', strpos( $html, 'readonly' ), false );
check( 'user mode bad scheme not prefilled', strpos( $html, 'javascript:alert' ), false );

$GLOBALS['__test_logged_in'] = false;
$GLOBALS['__test_user_url'] = '';

echo "ALL SHORTCODE TESTS PASSED\n";