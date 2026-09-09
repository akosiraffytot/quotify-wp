<?php
/**
 * Plugin Name: Quotify
 * Plugin URI:  https://github.com/akosiraffytot/quotify-wp
 * Description: Counts pages from any website's XML sitemap and returns a tiered price with a checkout link.
 * Version:     1.0.8
 * Author:      Rafael Mendoza
 * Author URI:  https://akosiraffytot.dev/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: qtfy
 *
 * @package Quotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUOTIFY_VERSION', '1.0.8' );
define( 'QUOTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'QUOTIFY_FILE', __FILE__ );

require_once QUOTIFY_PATH . 'includes/class-quotify-admin.php';
require_once QUOTIFY_PATH . 'includes/class-quotify-sitemap.php';
require_once QUOTIFY_PATH . 'includes/class-quotify-updater.php';

if ( function_exists( 'add_action' ) ) {
	Quotify\Updater::init();
}

/**
 * Boot the plugin: admin hooks, shortcode and AJAX endpoint.
 *
 * @return void
 */
function quotify_boot(): void {
	Quotify\Admin::init();

	add_shortcode( 'quotify', 'quotify_shortcode' );
	add_shortcode( 'quotify_count', 'quotify_count_shortcode' );
	add_shortcode( 'quotify_price', 'quotify_price_shortcode' );
	add_shortcode( 'quotify_quote', 'quotify_quote_shortcode' );
	add_action( 'wp_footer', 'quotify_enqueue_frontend_assets' );
	add_action( 'wp_ajax_quotify_estimate', 'quotify_ajax_estimate' );
	add_action( 'wp_ajax_nopriv_quotify_estimate', 'quotify_ajax_estimate' );
}
if ( function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'quotify_boot' );
}

/**
 * [quotify] shortcode: website URL estimate form.
 *
 * Result fields render inline unless disabled, so the layout stays
 * fully controllable with the standalone field shortcodes instead.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function quotify_shortcode( $atts ): string {
	$GLOBALS['quotify_shortcode_rendered'] = true;

	$atts = shortcode_atts(
		array(
			'button'      => __( 'Estimate', 'qtfy' ),
			'quote_label' => __( 'Get a Quote', 'qtfy' ),
			'show_pages'  => 1,
			'show_price'  => 1,
			'show_quote'  => 1,
		),
		$atts,
		'quotify'
	);

	ob_start();
	?>
	<div class="quotify-tool">
		<form class="quotify-form" novalidate>
			<label class="screen-reader-text" for="quotify-url"><?php esc_html_e( 'Website URL', 'qtfy' ); ?></label>
			<input
				type="url"
				id="quotify-url"
				class="regular-text"
				placeholder="https://example.com"
				required
			>
			<button type="submit" class="button button-primary quotify-estimate"><?php echo esc_html( $atts['button'] ); ?></button>
			<span class="quotify-spinner" style="display:none"></span>
			<div class="quotify-result" aria-live="polite">
				<div class="quotify-status"></div>
				<?php if ( filter_var( $atts['show_pages'], FILTER_VALIDATE_BOOLEAN ) ) : ?>
					<span class="quotify-field quotify-count" data-quotify-field="count"></span>
				<?php endif; ?>
				<?php if ( filter_var( $atts['show_price'], FILTER_VALIDATE_BOOLEAN ) ) : ?>
					<span class="quotify-field quotify-price" data-quotify-field="price"></span>
				<?php endif; ?>
				<?php if ( filter_var( $atts['show_quote'], FILTER_VALIDATE_BOOLEAN ) ) : ?>
					<span class="quotify-field quotify-quote-link" data-quotify-field="quote" data-quotify-quote-label="<?php echo esc_attr( $atts['quote_label'] ); ?>"></span>
				<?php endif; ?>
			</div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Shared renderer for the standalone [quotify_*] field shortcodes.
 *
 * @param array|string $atts  Shortcode attributes.
 * @param string       $field Field slug: count, price or quote.
 * @return string
 */
function quotify_field_shortcode( $atts, string $field ): string {
	$GLOBALS['quotify_shortcode_rendered'] = true;

	$defaults = array( 'placeholder' => '' );
	if ( 'quote' === $field ) {
		$defaults['label'] = __( 'Get a Quote', 'qtfy' );
	}

	$atts = shortcode_atts( $defaults, $atts, 'quotify_' . $field );

	$classes = 'quotify-field quotify-' . $field;
	$extra   = '';
	if ( 'quote' === $field ) {
		$classes .= ' quotify-quote-link';
		$extra    = ' data-quotify-quote-label="' . esc_attr( $atts['label'] ) . '"';
	}

	return '<span class="' . esc_attr( $classes ) . '" data-quotify-field="' . esc_attr( $field ) . '" data-quotify-placeholder="' . esc_attr( $atts['placeholder'] ) . '"' . $extra . '>' . esc_html( $atts['placeholder'] ) . '</span>';
}

/**
 * [quotify_count] shortcode: page-count field filled by AJAX results.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function quotify_count_shortcode( $atts ): string {
	return quotify_field_shortcode( $atts, 'count' );
}

/**
 * [quotify_price] shortcode: price field filled by AJAX results.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function quotify_price_shortcode( $atts ): string {
	return quotify_field_shortcode( $atts, 'price' );
}

/**
 * [quotify_quote] shortcode: "Get a quote" link filled by AJAX results.
 *
 * @param array|string $atts Shortcode attributes (label, placeholder).
 * @return string
 */
function quotify_quote_shortcode( $atts ): string {
	return quotify_field_shortcode( $atts, 'quote' );
}

/**
 * Enqueue frontend assets when the shortcode was rendered.
 *
 * @return void
 */
function quotify_enqueue_frontend_assets(): void {
	if ( empty( $GLOBALS['quotify_shortcode_rendered'] ) ) {
		return;
	}

	wp_enqueue_style( 'quotify-frontend', QUOTIFY_URL . 'assets/frontend.css', array(), QUOTIFY_VERSION );
	wp_enqueue_script( 'quotify-frontend', QUOTIFY_URL . 'assets/frontend.js', array(), QUOTIFY_VERSION, true );
	wp_localize_script(
		'quotify-frontend',
		'quotifyFront',
		array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'quotify_estimate' ),
			/* translators: %s: page count. */
			'pages'   => __( '%s pages', 'qtfy' ),
			'quote'   => __( 'Get a Quote', 'qtfy' ),
			'empty'   => __( 'Please enter a website URL.', 'qtfy' ),
			'error'   => __( 'Something went wrong. Please try again.', 'qtfy' ),
		)
	);
}

/**
 * AJAX handler for estimating a website's page count + price.
 *
 * @return void
 */
function quotify_ajax_estimate(): void {
	if ( ! check_ajax_referer( 'quotify_estimate', 'nonce', false ) ) {
		wp_send_json_error(
			array(
				'code'    => 'bad_nonce',
				'message' => __( 'Security check failed. Please reload the page and try again.', 'qtfy' ),
			)
		);
	}

	if ( ! Quotify\Sitemap::throttle( quotify_client_ip(), 10, 60 ) ) {
		wp_send_json_error(
			array(
				'code'    => 'rate_limited',
				'message' => __( 'Too many requests. Please try again in a minute.', 'qtfy' ),
			)
		);
	}

	$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

	if ( '' === $url || ! Quotify\Sitemap::guard_site_url( $url ) ) {
		wp_send_json_error(
			array(
				'code'    => 'invalid_url',
				'message' => __( 'Please enter a valid public website URL.', 'qtfy' ),
			)
		);
	}

	$result = Quotify\Sitemap::count_site( $url );

	if ( 'ok' !== $result['status'] ) {
		$messages = array(
			'no_sitemap'  => __( 'No XML sitemap was found at that address.', 'qtfy' ),
			'fetch_error' => __( 'Could not fetch the site. It may be slow or blocking requests.', 'qtfy' ),
			'too_large'   => __( 'That website sitemap is too large to check.', 'qtfy' ),
			'processing'  => __( 'Still working, please wait.', 'qtfy' ),
		);

		wp_send_json_error(
			array(
				'code'    => $result['status'],
				'message' => $messages[ $result['status'] ] ?? __( 'Something went wrong. Please try again.', 'qtfy' ),
			)
		);
	}

	$settings = Quotify\Admin::get_settings();
	$price    = Quotify\Admin::get_price( $result['count'], $settings['tiers'] );

	wp_send_json_success(
		array(
			'page_count'      => $result['count'],
			'count_display'   => number_format_i18n( $result['count'], 0 ),
			'capped'          => $result['capped'],
			'price'           => $price,
			'formatted_price' => Quotify\Admin::price_label( $price ),
			'checkout_url'    => null !== $price ? Quotify\Admin::build_checkout_url( $result['count'], $price, $settings['checkout_url'] ) : '',
		)
	);
}

/**
 * Sanitized client IP for rate limiting.
 *
 * @return string
 */
function quotify_client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		$ip = '0.0.0.0';
	}

	return $ip;
}