<?php
/**
 * Plugin Name: Quotify
 * Plugin URI:  https://github.com/akosiraffytot/quotify-wp
 * Description: Counts pages from any website's XML sitemap and returns a tiered price with a checkout link.
 * Version:     1.3.6
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

define( 'QUOTIFY_VERSION', '1.3.6' );
define( 'QUOTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'QUOTIFY_FILE', __FILE__ );

require_once QUOTIFY_PATH . 'includes/class-quotify-admin.php';
require_once QUOTIFY_PATH . 'includes/class-quotify-sitemap.php';
require_once QUOTIFY_PATH . 'includes/class-quotify-updater.php';

if ( function_exists( 'add_action' ) && ( is_admin() || wp_doing_cron() ) ) {
	// Update checks only run in wp-admin; keep PUC off frontend/builder requests.
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
			'button'           => __( 'Estimate', 'qtfy' ),
			'quote_label'      => __( 'Get a Quote', 'qtfy' ),
			'show_pages'       => 1,
			'show_price'       => 1,
			'show_quote'       => 1,
			'mode'             => 'all',
			'instant_checkout' => '',
		),
		$atts,
		'quotify'
	);

	$mode = 'user' === $atts['mode'] ? 'user' : 'all';

	$user_url = '';
	if ( 'user' === $mode ) {
		if ( is_user_logged_in() ) {
			$user_url = (string) wp_get_current_user()->user_url;
			$scheme   = wp_parse_url( $user_url, PHP_URL_SCHEME );

			if ( '' === $user_url || ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
				$user_url = '';
			}
		}
	}

	$instant_checkout = in_array( strtolower( (string) $atts['instant_checkout'] ), array( '1', 'true', 'yes', 'on' ), true );

	/*
	 * FluentCart instant checkout: render FluentCart's own
	 * [fluent_cart_checkout_button] hidden, seeded with the first tier that
	 * has a variation ID. After the estimate AJAX returns the matched tier's
	 * variation, the frontend swaps the button's href to the final
	 * modal_checkout URL and reveals it. The seeded button also makes
	 * FluentCart register its modal container + assets on this page.
	 */
	$fluentcart_seed = $instant_checkout ? quotify_get_fluentcart_seed() : '';

	if ( $instant_checkout && $fluentcart_seed ) {
		quotify_mark_fluentcart();
		$GLOBALS['quotify_instant_checkout'] = true;
	}

	$input_html = '';
	$hint_html  = '';
	if ( 'user' === $mode ) {
		if ( '' === $user_url ) {
			$hint_html = '<p class="quotify-profile-hint">' . esc_html__( 'Add a website URL to your profile to use it here.', 'qtfy' ) . '</p>';
		}
		$input_html = '<input type="url" id="quotify-url" class="regular-text" value="' . esc_attr( $user_url ) . '" disabled>';
	} else {
		$input_html = '<input type="url" id="quotify-url" class="regular-text" placeholder="https://example.com">';
	}

	// No output buffering here: comment/theme pipelines wrap content in
	// ob_start() display handlers, and a nested ob_start() from a shortcode
	// is a PHP fatal ("Cannot use output buffering in output buffering
	// display handlers"). The whole form is built as a string instead.
	$count_html = filter_var( $atts['show_pages'], FILTER_VALIDATE_BOOLEAN )
		? '<span class="quotify-field quotify-count" data-quotify-field="count"></span>'
		: '';

	$price_html = filter_var( $atts['show_price'], FILTER_VALIDATE_BOOLEAN )
		? '<span class="quotify-field quotify-price" data-quotify-field="price"></span>'
		: '';

	$quote_html = '';
	if ( filter_var( $atts['show_quote'], FILTER_VALIDATE_BOOLEAN ) ) {
		if ( $fluentcart_seed ) {
			/*
			 * The FluentCart button is NOT rendered inline into the page
			 * content: content processors (page builders, wp_filter_content_tags)
			 * run WP_HTML_Tag_Processor over the_content inside their own
			 * ob_start() display handlers, and the href-swapped anchor tripped
			 * PHP's "Cannot use output buffering in output buffering display
			 * handlers" fatal there. Render only an inert placeholder; the
			 * frontend builds the real button with JS.
			 */
			$quote_html = '<div class="quotify-fluentcart-wrap" style="display:none" data-quotify-fluentcart-label="' . esc_attr( str_replace( array( '"', "'", '[', ']' ), '', $atts['quote_label'] ) ) . '"></div>';
		} else {
			$quote_html = '<span class="quotify-field quotify-quote-link" data-quotify-field="quote" data-quotify-quote-label="' . esc_attr( $atts['quote_label'] ) . '"></span>';
		}
	}

	return '<div class="quotify-tool">'
		. '<form class="quotify-form" novalidate>'
		. '<label class="screen-reader-text" for="quotify-url">' . esc_html__( 'Website URL', 'qtfy' ) . '</label>'
		. $hint_html
		. $input_html
		. '<button type="submit" class="button button-primary quotify-estimate">' . esc_html( $atts['button'] ) . '</button>'
		. '<span class="quotify-spinner" style="display:none"></span>'
		. '<div class="quotify-result" aria-live="polite">'
		. '<div class="quotify-status"></div>'
		. $count_html
		. $price_html
		. $quote_html
		. '</div>'
		. '</form>'
		. '</div>';
}

/**
 * First configured FluentCart tier variation ID, used as the "seed" for the
 * hidden instant-checkout button. Falls back to '' when FluentCart is not
 * active or no tier has a variation ID set.
 *
 * @return string
 */
function quotify_get_fluentcart_seed(): string {
	if ( ! class_exists( 'FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\DirectCheckoutShortcode' ) ) {
		return '';
	}

	$tiers = Quotify\Admin::get_settings()['tiers'];

	foreach ( $tiers as $tier ) {
		if ( ! empty( $tier['variation_id'] ) ) {
			return (string) absint( $tier['variation_id'] );
		}
	}

	return '';
}

/**
 * Tell FluentCart the frontend needs its assets: this makes the globally
 * registered modal-checkout footer hook render the modal container + CSS
 * (FluentCart's WebRoutes::renderModalCheckout() checks isFrontendAssetsMarked()).
 *
 * @return void
 */
function quotify_mark_fluentcart(): void {
	if ( class_exists( 'FluentCart\App\Modules\Templating\AssetLoader' ) ) {
		\FluentCart\App\Modules\Templating\AssetLoader::markFrontendAssetsRequired();
	}
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

	if ( 'quote' === $field && ! empty( $GLOBALS['quotify_instant_checkout'] ) ) {
		$seed = quotify_get_fluentcart_seed();

		if ( $seed ) {
			quotify_mark_fluentcart();
			$raw_atts = is_array( $atts ) ? $atts : array();
			$label    = isset( $raw_atts['label'] ) ? (string) $raw_atts['label'] : __( 'Get a Quote', 'qtfy' );

			// Inert placeholder only; the frontend builds the real button via
			// JS so content processors never see FluentCart's anchor markup.
			return '<div class="quotify-fluentcart-wrap" style="display:none" data-quotify-fluentcart-label="' . esc_attr( str_replace( array( '"', "'", '[', ']' ), '', $label ) ) . '"></div>';
		}
	}

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
			'ajaxurl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'quotify_estimate' ),
			'instant_checkout' => ! empty( $GLOBALS['quotify_instant_checkout'] ),
			'fluentcart_seed'  => ! empty( $GLOBALS['quotify_instant_checkout'] ) ? (int) quotify_get_fluentcart_seed() : '',
			'fluentcart_home'  => home_url(),
			'fluentcart_class' => 'wp-block-button__link wp-element-button',
			/* translators: %s: page count. */
			'pages'            => __( '%s pages', 'qtfy' ),
			'quote'            => __( 'Get a Quote', 'qtfy' ),
			'empty'            => __( 'Please enter a website URL.', 'qtfy' ),
			'error'            => __( 'Something went wrong. Please try again.', 'qtfy' ),
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

	$instant_checkout = isset( $_POST['instant_checkout'] ) && in_array(
		strtolower( sanitize_text_field( wp_unslash( $_POST['instant_checkout'] ) ) ),
		array( '1', 'true', 'yes', 'on' ),
		true
	);

	$result = Quotify\Sitemap::count_site( $url );

	if ( 'ok' !== $result['status'] ) {
		$messages = array(
			'no_sitemap'         => __( 'No XML sitemap was found at that address.', 'qtfy' ),
			'robots_sitemap_404' => __( 'Your site lists a sitemap in robots.txt, but that URL could not be loaded.', 'qtfy' ),
			'fetch_error'        => __( 'Could not fetch the site. It may be slow or blocking requests.', 'qtfy' ),
			'too_large'          => __( 'That website sitemap is too large to check.', 'qtfy' ),
			'processing'         => __( 'Still working, please wait.', 'qtfy' ),
		);

		wp_send_json_error(
			array(
				'code'    => $result['status'],
				'message' => $messages[ $result['status'] ] ?? __( 'Something went wrong. Please try again.', 'qtfy' ),
			)
		);
	}

	$settings = Quotify\Admin::get_settings();
	$tier     = Quotify\Admin::match_tier( $result['count'], $settings['tiers'] );
	$price    = $tier ? (float) $tier['price'] : null;

	// Instant checkout drives the button from the tier's FluentCart variation
	// ID; the Checkout URL template is ignored entirely for this flow.
	$fluentcart_url = '';
	if ( $instant_checkout ) {
		if ( $tier && ! empty( $tier['variation_id'] ) ) {
			$fluentcart_url = add_query_arg(
				array(
					'fluent-cart' => 'modal_checkout',
					'item_id'     => absint( $tier['variation_id'] ),
					'quantity'    => 1,
				),
				home_url()
			);
		}
		$checkout = '';
	} else {
		$template = ( $tier && ! empty( $tier['url'] ) ) ? $tier['url'] : $settings['checkout_url'];
		$site     = home_url();
		$checkout = null !== $price ? Quotify\Admin::build_checkout_url( $result['count'], $price, $template, $site ) : '';
		$checkout = 0 === strpos( (string) $checkout, 'http://' ) || 0 === strpos( (string) $checkout, 'https://' ) ? $checkout : '';
	}

	wp_send_json_success(
		array(
			'page_count'              => $result['count'],
			'count_display'           => number_format_i18n( $result['count'], 0 ),
			'capped'                  => $result['capped'],
			'price'                   => $price,
			'formatted_price'         => Quotify\Admin::price_label( $price ),
			'checkout_url'            => $checkout,
			'fluentcart_url'          => $fluentcart_url,
			'fluentcart_variation_id' => $instant_checkout && $tier ? ( $tier['variation_id'] ?? '' ) : '',
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
