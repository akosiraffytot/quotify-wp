<?php
/**
 * Plugin Name: Quotify
 * Plugin URI:  https://github.com/akosiraffytot/quotify-wp
 * Description: Counts pages from any website's XML sitemap and returns a tiered price with a checkout link.
 * Version:     1.6.1
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

define( 'QUOTIFY_VERSION', '1.6.1' );
define( 'QUOTIFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUOTIFY_URL', plugin_dir_url( __FILE__ ) );
define( 'QUOTIFY_FILE', __FILE__ );

require_once QUOTIFY_PATH . 'includes/class-quotify-admin.php';
require_once QUOTIFY_PATH . 'includes/class-quotify-bricks.php';
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
	Quotify\Bricks::init();

	add_shortcode( 'quotify', 'quotify_shortcode' );
	add_shortcode( 'quotify_count', 'quotify_count_shortcode' );
	add_shortcode( 'quotify_price', 'quotify_price_shortcode' );
	add_shortcode( 'quotify_quote', 'quotify_quote_shortcode' );
	add_shortcode( 'quotify_saved_count', 'quotify_saved_count_shortcode' );
	add_shortcode( 'quotify_saved_scanned', 'quotify_saved_scanned_shortcode' );
	add_shortcode( 'quotify_saved_url', 'quotify_saved_url_shortcode' );
	add_shortcode( 'quotify_fluentcart_checkout', 'quotify_fluentcart_checkout_shortcode' );
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
			'button'            => __( 'Estimate', 'qtfy' ),
			'quote_label'       => __( 'Get a Quote', 'qtfy' ),
			'show_pages'        => 1,
			'show_price'        => 1,
			'show_quote'        => 1,
			'mode'              => 'all',
			'instant_checkout'  => '',
			'force_load_assets' => '',
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
			$quote_html = quotify_fluentcart_wrap_html( $fluentcart_seed, $atts['quote_label'] );
		} else {
			$quote_html = '<span class="quotify-field quotify-quote-link" data-quotify-field="quote" data-quotify-quote-label="' . esc_attr( $atts['quote_label'] ) . '"></span>';
		}
	}

	$form = '<div class="quotify-tool">'
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

	if ( filter_var( $atts['force_load_assets'], FILTER_VALIDATE_BOOLEAN ) ) {
		// Builder popups/wizards can render the shortcode after the footer
		// enqueue pass already ran. force_load_assets embeds the asset tags
		// directly in the output (after the form markup so the form parses
		// before the script executes). No ob_start, so the output-buffering
		// display-handler fatal stays impossible.
		$form .= quotify_embedded_assets();
	}

	return $form;
}

/**
 * Inert FluentCart seed wrapper: the frontend builds the real modal-checkout
 * anchor inside it via JS, so content processors never see FluentCart's
 * anchor markup (avoids the output-buffering shorthand fatal).
 *
 * @param string $variation_id FluentCart variation ID to seed.
 * @param string $label        Button label.
 * @param bool   $hidden       Hide until an estimate reveals it.
 * @return string
 */
function quotify_fluentcart_wrap_html( string $variation_id, string $label, bool $hidden = true ): string {
	$style = $hidden ? ' style="display:none"' : '';

	return '<div class="quotify-fluentcart-wrap"' . $style . ' data-quotify-fluentcart-seed="' . esc_attr( $variation_id ) . '" data-quotify-fluentcart-label="' . esc_attr( str_replace( array( '"', "'", '[', ']' ), '', $label ) ) . '"></div>';
}

/**
 * Embedded frontend asset tags (CSS + inline config + JS), emitted at most
 * once per page, for placements that render after the enqueue pass.
 *
 * @return string
 */
function quotify_embedded_assets(): string {
	if ( ! empty( $GLOBALS['quotify_assets_embedded'] ) ) {
		return '';
	}

	$GLOBALS['quotify_assets_embedded'] = true;
	$config                             = quotify_get_frontend_config();
	$css                                = QUOTIFY_URL . 'assets/frontend.css?ver=' . QUOTIFY_VERSION;
	$js                                 = QUOTIFY_URL . 'assets/frontend.js?ver=' . QUOTIFY_VERSION;

	// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Deliberate opt-in: late-rendered placements get their own asset tags.
	return '<link rel="stylesheet" id="quotify-frontend-css" href="' . esc_url( $css ) . '">'
		. '<script id="quotify-frontend-config">window.quotifyFront=' . wp_json_encode( $config ) . ';</script>'
		. '<script id="quotify-frontend-js" src="' . esc_url( $js ) . '"></script>';
	// phpcs:enable
}

/**
 * Loose URL guard for custom-quote button targets: allows http(s), mailto,
 * tel and relative ("/…") links, rejecting anything else (js:, data:, …).
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function quotify_is_loose_url( string $url ): bool {
	if ( '' === $url ) {
		return false;
	}

	if ( 0 === strpos( $url, '/' ) || 0 === strpos( $url, './' ) ) {
		return true;
	}

	foreach ( array( 'http://', 'https://', 'mailto:', 'tel:' ) as $prefix ) {
		if ( 0 === strpos( $url, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * HTTP(S) scheme guard for checkout URLs.
 *
 * @param string $url Candidate URL.
 * @return bool
 */
function quotify_is_https_url( string $url ): bool {
	return 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' );
}

/**
 * [quotify_fluentcart_checkout] shortcode: current user's saved scan matched
 * to its pricing tier, rendered as the correct checkout button.
 *
 * Variation ID + FluentCart active -> the instant modal button. Otherwise the
 * tier's Checkout URL (verbatim for custom-quote tiers, token-substituted for
 * priced tiers) as a plain link. Server-rendered, no AJAX.
 *
 * @param array|string $atts Shortcode attributes: label, force_load_assets.
 * @return string
 */
function quotify_fluentcart_checkout_shortcode( $atts ): string {
	if ( ! is_user_logged_in() ) {
		return '';
	}

	$saved = quotify_get_saved_scan( get_current_user_id() );

	if ( $saved['count'] <= 0 ) {
		return '';
	}

	$atts = shortcode_atts(
		array(
			'label'             => '',
			'force_load_assets' => '',
		),
		$atts,
		'quotify_fluentcart_checkout'
	);

	$settings = Quotify\Admin::get_settings();
	$tier     = Quotify\Admin::match_tier( $saved['count'], $settings['tiers'] );

	if ( ! $tier ) {
		return '';
	}

	$custom_quote = ! empty( $tier['custom_quote'] );
	$label        = '' !== (string) ( $tier['button_label'] ?? '' ) ? (string) $tier['button_label'] : ( '' !== $atts['label'] ? $atts['label'] : __( 'Get a Quote', 'qtfy' ) );

	$GLOBALS['quotify_shortcode_rendered'] = true;

	// FluentCart active + a variation ID -> instant modal button (client-built).
	if ( class_exists( 'FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\DirectCheckoutShortcode' ) && ! empty( $tier['variation_id'] ) ) {
		quotify_mark_fluentcart();

		$html = quotify_fluentcart_wrap_html( (string) absint( $tier['variation_id'] ), $label, false );

		if ( filter_var( $atts['force_load_assets'], FILTER_VALIDATE_BOOLEAN ) ) {
			$html .= quotify_embedded_assets();
		}

		return $html;
	}

	$tier_url = (string) ( $tier['url'] ?? '' );

	if ( $custom_quote ) {
		$href = Quotify\Admin::build_contact_url( $saved['count'], $tier_url, home_url() );

		if ( ! quotify_is_loose_url( $href ) ) {
			return '';
		}
	} else {
		$price = Quotify\Admin::get_price( $saved['count'], $settings['tiers'] );

		if ( null === $price ) {
			return '';
		}

		$template = '' !== $tier_url ? $tier_url : $settings['checkout_url'];
		$href     = Quotify\Admin::build_checkout_url( $saved['count'], $price, $template, home_url() );

		if ( ! quotify_is_https_url( $href ) ) {
			return '';
		}
	}

	$html = '<a class="quotify-quote-link" href="' . esc_url( $href ) . '" rel="noopener">' . esc_html( $label ) . '</a>';

	if ( filter_var( $atts['force_load_assets'], FILTER_VALIDATE_BOOLEAN ) ) {
		$html .= quotify_embedded_assets();
	}

	return $html;
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
 * Static config array for frontend.js.
 *
 * Shared by both the wp_footer enqueue path and the force_load_assets
 * embedded-tag path so the two stay identical.
 *
 * @return array
 */
function quotify_get_frontend_config(): array {
	return array(
		'ajaxurl'          => admin_url( 'admin-ajax.php' ),
		'nonce'            => wp_create_nonce( 'quotify_estimate' ),
		'fluentcart_home'  => home_url(),
		'fluentcart_class' => 'wp-block-button__link wp-element-button',
		/* translators: %s: page count. */
		'pages'            => __( '%s pages', 'qtfy' ),
		'quote'            => __( 'Get a Quote', 'qtfy' ),
		'empty'            => __( 'Please enter a website URL.', 'qtfy' ),
		'error'            => __( 'Something went wrong. Please try again.', 'qtfy' ),
	);
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
			return quotify_fluentcart_wrap_html( $seed, $label );
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
 * Save the latest successful scan to a user's profile meta.
 *
 * Latest-scan-only: each successful scan overwrites the previous record.
 *
 * @param int    $user_id User ID.
 * @param int    $count   Page count (already capped at 5,001).
 * @param int    $time    Unix timestamp of the scan.
 * @param string $url     Scanned website URL.
 * @return void
 */
function quotify_save_scan_meta( int $user_id, int $count, int $time, string $url ): void {
	update_user_meta( $user_id, Quotify\Admin::META_COUNT, $count );
	update_user_meta( $user_id, Quotify\Admin::META_SCANNED_AT, $time );
	update_user_meta( $user_id, Quotify\Admin::META_URL, $url );
}

/**
 * Latest saved scan for a user, or an empty array when never scanned.
 *
 * @param int $user_id User ID.
 * @return array{count:int,scanned_at:int,url:string}
 */
function quotify_get_saved_scan( int $user_id ): array {
	return array(
		'count'      => (int) get_user_meta( $user_id, Quotify\Admin::META_COUNT, true ),
		'scanned_at' => (int) get_user_meta( $user_id, Quotify\Admin::META_SCANNED_AT, true ),
		'url'        => (string) get_user_meta( $user_id, Quotify\Admin::META_URL, true ),
	);
}

/**
 * Shared renderer for the saved-scan display shortcodes.
 *
 * Server-rendered from the current logged-in user's profile meta; renders
 * nothing (or the placeholder) for logged-out visitors or users who have
 * never run a scan. Deliberately does not set the asset enqueue flag: these
 * render on the server and need no frontend JS.
 *
 * @param array|string $atts  Shortcode attributes (placeholder).
 * @param string       $field Field slug: count, scanned or url.
 * @return string
 */
function quotify_saved_scan_shortcode( $atts, string $field ): string {
	$atts  = shortcode_atts(
		array( 'placeholder' => '' ),
		$atts,
		'quotify_saved_' . $field
	);
	$value = '';

	if ( is_user_logged_in() ) {
		$saved = quotify_get_saved_scan( get_current_user_id() );

		if ( 'count' === $field ) {
			$value = $saved['count'] > 0 ? number_format_i18n( $saved['count'], 0 ) : '';
		} elseif ( 'scanned' === $field ) {
			if ( $saved['scanned_at'] > 0 ) {
				$value = date_i18n( sprintf( '%s %s', get_option( 'date_format' ), get_option( 'time_format' ) ), $saved['scanned_at'] );
			}
		} elseif ( 'url' === $field ) {
			$value = $saved['url'];
		}
	}

	if ( '' === $value ) {
		return esc_html( $atts['placeholder'] );
	}

	return esc_html( $value );
}

/**
 * [quotify_saved_count] shortcode: current user's latest page count.
 *
 * @param array|string $atts Shortcode attributes (placeholder).
 * @return string
 */
function quotify_saved_count_shortcode( $atts ): string {
	return quotify_saved_scan_shortcode( $atts, 'count' );
}

/**
 * [quotify_saved_scanned] shortcode: current user's last scan date/time.
 *
 * @param array|string $atts Shortcode attributes (placeholder).
 * @return string
 */
function quotify_saved_scanned_shortcode( $atts ): string {
	return quotify_saved_scan_shortcode( $atts, 'scanned' );
}

/**
 * [quotify_saved_url] shortcode: current user's last scanned website URL.
 *
 * @param array|string $atts Shortcode attributes (placeholder).
 * @return string
 */
function quotify_saved_url_shortcode( $atts ): string {
	return quotify_saved_scan_shortcode( $atts, 'url' );
}

/**
 * Enqueue frontend assets when the shortcode was rendered on this page.
 *
 * Skipped when force_load_assets already embedded the tags inline at
 * shortcode-render time (that path emits external <link>/<script> tags in
 * the returned output rather than using the WP enqueue pipeline).
 *
 * @return void
 */
function quotify_enqueue_frontend_assets(): void {
	if ( empty( $GLOBALS['quotify_shortcode_rendered'] ) || ! empty( $GLOBALS['quotify_assets_embedded'] ) ) {
		return;
	}

	wp_enqueue_style( 'quotify-frontend', QUOTIFY_URL . 'assets/frontend.css', array(), QUOTIFY_VERSION );
	wp_enqueue_script( 'quotify-frontend', QUOTIFY_URL . 'assets/frontend.js', array(), QUOTIFY_VERSION, true );
	wp_localize_script(
		'quotify-frontend',
		'quotifyFront',
		quotify_get_frontend_config()
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

	// Custom-quote tiers have no fixed price: price is null everywhere, and
	// the button target comes from the tier's own Variation ID / Checkout URL.
	$custom_quote = $tier && ! empty( $tier['custom_quote'] );
	$price        = ( $tier && ! $custom_quote ) ? (float) $tier['price'] : null;

	// Save the latest successful scan to the logged-in user's profile.
	if ( is_user_logged_in() ) {
		quotify_save_scan_meta( get_current_user_id(), $result['count'], time(), $url );
	}

	$quote_label    = '';
	$fluentcart_url = '';
	$checkout       = '';

	if ( $custom_quote ) {
		// Button label comes from the per-tier field (falling back to the
		// default quote label so the shortcode attr still applies where set).
		$quote_label = '' !== (string) ( $tier['button_label'] ?? '' ) ? (string) $tier['button_label'] : __( 'Get a Quote', 'qtfy' );

		// Instant-checkout modal wins when a variation ID is set; otherwise
		// the tier's Checkout URL becomes a verbatim contact link.
		if ( ! empty( $tier['variation_id'] ) ) {
			$fluentcart_url = add_query_arg(
				array(
					'fluent-cart' => 'modal_checkout',
					'item_id'     => absint( $tier['variation_id'] ),
					'quantity'    => 1,
				),
				home_url()
			);
		} elseif ( ! empty( $tier['url'] ) ) {
			$checkout = Quotify\Admin::build_contact_url( $result['count'], $tier['url'], home_url() );
			$checkout = quotify_is_loose_url( $checkout ) ? $checkout : '';
		}
	} elseif ( $instant_checkout ) {
		// Instant checkout drives the button from the tier's FluentCart
		// variation ID; the Checkout URL template is ignored entirely here.
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
		$checkout = quotify_is_https_url( (string) $checkout ) ? $checkout : '';
	}

	wp_send_json_success(
		array(
			'page_count'              => $result['count'],
			'count_display'           => number_format_i18n( $result['count'], 0 ),
			'capped'                  => $result['capped'],
			'price'                   => $price,
			'formatted_price'         => Quotify\Admin::price_label( $price ),
			'checkout_url'            => isset( $checkout ) ? $checkout : '',
			'quote_label'             => $quote_label,
			'fluentcart_url'          => $fluentcart_url,
			'fluentcart_variation_id' => $custom_quote || $instant_checkout ? ( ( $tier && ! empty( $tier['variation_id'] ) ) ? $tier['variation_id'] : '' ) : '',
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
