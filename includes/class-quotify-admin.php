<?php
/**
 * Quotify admin settings: Tools > Quotify.
 *
 * @package Quotify
 */

namespace Quotify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page, pricing tier storage and validation.
 */
class Admin {

	const OPTION_NAME = 'quotify_settings';
	const PAGE_SLUG   = 'quotify';
	const PAGE_HOOK   = 'tools_page_quotify';
	const CAPABILITY  = 'manage_options';
	const TOKEN_PAGES = '{page_count}';
	const TOKEN_PRICE = '{total_price}';

	/**
	 * Hook the admin into WordPress.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add the plugin page under Tools.
	 *
	 * @return void
	 */
	public static function add_menu_page(): void {
		add_management_page(
			__( 'Quotify', 'qtfy' ),
			__( 'Quotify', 'qtfy' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the settings option and its sanitize callback.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::OPTION_NAME,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Load admin assets only on the Quotify settings page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( self::PAGE_HOOK !== $hook_suffix ) {
			return;
		}

		wp_enqueue_script( 'quotify-admin', QUOTIFY_URL . 'assets/admin.js', array(), QUOTIFY_VERSION, true );
		wp_localize_script(
			'quotify-admin',
			'quotifyAdmin',
			array(
				'remove' => __( 'Remove', 'qtfy' ),
				'add'    => __( 'Add tier', 'qtfy' ),
			)
		);
	}

	/**
	 * Current settings merged over defaults.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$defaults = array(
			'tiers'        => array(),
			'checkout_url' => '',
			'limits'       => Sitemap::default_limits(),
		);

		$merged = array_merge( $defaults, $settings );

		$merged['limits'] = array_merge(
			$defaults['limits'],
			isset( $settings['limits'] ) && is_array( $settings['limits'] ) ? $settings['limits'] : array()
		);

		return $merged;
	}

	/**
	 * Match a page count against the sorted pricing tiers.
	 *
	 * Tiers are expected to be min-sorted as produced by normalize_tiers()
	 * during settings sanitization. Returns the price of the first tier
	 * where count >= min and (max is empty or count <= max), or null when
	 * no tier matches.
	 *
	 * @param int   $count Number of pages.
	 * @param array $tiers Normalized tier list.
	 * @return float|null
	 */
	public static function get_price( int $count, array $tiers ): ?float {
		foreach ( $tiers as $tier ) {
			if ( $count >= $tier['min'] && ( '' === $tier['max'] || $count <= $tier['max'] ) ) {
				return (float) $tier['price'];
			}
		}

		return null;
	}

	/**
	 * Human readable price label, e.g. "$235".
	 *
	 * Null yields a contact-us fallback string.
	 *
	 * @param float|null $price Matched price or null.
	 * @return string
	 */
	public static function price_label( ?float $price ): string {
		if ( null === $price ) {
			return __( 'Contact us for a custom quote.', 'qtfy' );
		}

		return '$' . number_format_i18n( $price, 0 );
	}

	/**
	 * Build a checkout URL by substituting tokens into the template.
	 *
	 * {page_count} becomes the integer page count and {total_price} the
	 * price with trailing zeros trimmed (e.g. 120, 160.5, 235.75). The
	 * template is returned verbatim when it contains no tokens.
	 *
	 * @param int    $count    Page count.
	 * @param float  $price    Price.
	 * @param string $template Checkout URL template.
	 * @return string
	 */
	public static function build_checkout_url( int $count, float $price, string $template ): string {
		return str_replace(
			array( self::TOKEN_PAGES, self::TOKEN_PRICE ),
			array( (string) $count, self::format_price_for_url( $price ) ),
			$template
		);
	}

	/**
	 * Render a price for use in a URL query: 2dp with trailing zeros trimmed.
	 *
	 * @param float $price Price.
	 * @return string
	 */
	private static function format_price_for_url( float $price ): string {
		$formatted = number_format( $price, 2, '.', '' );

		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings     = self::get_settings();
		$tiers        = $settings['tiers'];
		$checkout_url = $settings['checkout_url'];
		$limits       = $settings['limits'];
		$next_index   = empty( $tiers ) ? 1 : count( $tiers );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php settings_errors( self::OPTION_NAME ); ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_NAME ); ?>

				<h2><?php esc_html_e( 'Pricing tiers', 'qtfy' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: "min" label, 2: "max" label. */
						esc_html__( 'Each tier matches a page count between %1$s and %2$s pages. Leaving %2$s blank makes the tier open-ended. The last tier must be open-ended.', 'qtfy' ),
						'<strong>' . esc_html__( 'min', 'qtfy' ) . '</strong>',
						'<strong>' . esc_html__( 'max', 'qtfy' ) . '</strong>'
					);
					?>
				</p>

				<table class="widefat striped" id="quotify-tiers">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Min pages', 'qtfy' ); ?></th>
							<th><?php esc_html_e( 'Max pages (blank = open-ended)', 'qtfy' ); ?></th>
							<th><?php esc_html_e( 'Price ($)', 'qtfy' ); ?></th>
							<th>&nbsp;</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $tiers ) ) : ?>
							<?php
							self::render_tier_row(
								0,
								array(
									'min'   => '',
									'max'   => '',
									'price' => '',
								)
							);
							?>
						<?php else : ?>
							<?php foreach ( array_values( $tiers ) as $index => $tier ) : ?>
								<?php self::render_tier_row( $index, $tier ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<input type="hidden" id="quotify-tier-index" value="<?php echo esc_attr( $next_index ); ?>">
				<button type="button" class="button" id="quotify-add-tier"><?php esc_html_e( 'Add tier', 'qtfy' ); ?></button>

				<h2><?php esc_html_e( 'Checkout URL template', 'qtfy' ); ?></h2>
				<p><?php esc_html_e( 'Build the checkout link from the page count and price. Allowed tokens:', 'qtfy' ); ?></p>
				<ul>
					<li><code>{page_count}</code> &mdash; <?php esc_html_e( 'the counted number of pages', 'qtfy' ); ?></li>
					<li><code>{total_price}</code> &mdash; <?php esc_html_e( 'the full price', 'qtfy' ); ?></li>
				</ul>
				<p>
					<input
						type="text"
						class="regular-text code"
						name="<?php echo esc_attr( self::OPTION_NAME . '[checkout_url]' ); ?>"
						value="<?php echo esc_attr( $checkout_url ); ?>"
						placeholder="https://example.com/checkout?pages={page_count}&price={total_price}"
					>
				</p>

				<h2><?php esc_html_e( 'Performance limits', 'qtfy' ); ?></h2>
				<p><?php esc_html_e( 'Caps on crawling, caching and lock behavior. The defaults are safe; change them only when needed.', 'qtfy' ); ?></p>
				<p><?php esc_html_e( 'Counting follows every Sitemap: entry in the target robots.txt and totals all listed sitemaps — for example all locale sitemaps of a multilingual site.', 'qtfy' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( self::limit_fields() as $key => $field ) : ?>
						<?php $value = $limits[ $key ]; ?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( 'quotify-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="<?php echo esc_attr( 'quotify-' . $key ); ?>"
									class="small-text"
									min="<?php echo esc_attr( $field['min'] ); ?>"
									max="<?php echo esc_attr( $field['max'] ); ?>"
									step="<?php echo esc_attr( $field['step'] ); ?>"
									name="<?php echo esc_attr( self::OPTION_NAME . '[limits][' . $key . ']' ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
								>
								<p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single tier table row.
	 *
	 * @param int   $index Row index.
	 * @param array $tier  Tier values.
	 * @return void
	 */
	private static function render_tier_row( int $index, array $tier ): void {
		?>
		<tr class="quotify-tier-row">
			<td>
				<input
					type="number"
					class="small-text"
					min="0"
					name="<?php echo esc_attr( sprintf( '%s[tiers][%d][min]', self::OPTION_NAME, $index ) ); ?>"
					value="<?php echo esc_attr( $tier['min'] ); ?>"
				>
			</td>
			<td>
				<input
					type="number"
					class="small-text"
					min="0"
					name="<?php echo esc_attr( sprintf( '%s[tiers][%d][max]', self::OPTION_NAME, $index ) ); ?>"
					value="<?php echo esc_attr( $tier['max'] ); ?>"
				>
			</td>
			<td>
				<input
					type="number"
					class="small-text"
					min="0"
					step="0.01"
					name="<?php echo esc_attr( sprintf( '%s[tiers][%d][price]', self::OPTION_NAME, $index ) ); ?>"
					value="<?php echo esc_attr( $tier['price'] ); ?>"
				>
			</td>
			<td>
				<button type="button" class="button-link-delete quotify-remove-tier"><?php esc_html_e( 'Remove', 'qtfy' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitize and validate settings before they are saved.
	 *
	 * Rejects the save (keeps the previous value) when the last tier is not
	 * open-ended.
	 *
	 * @param mixed $input Raw submitted settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ): array {
		$previous = self::get_settings();

		if ( ! is_array( $input ) ) {
			return $previous;
		}

		$tiers = self::normalize_tiers( $input['tiers'] ?? array() );

		$last = end( $tiers );
		if ( false === $last || '' !== $last['max'] ) {
			add_settings_error(
				self::OPTION_NAME,
				'quotify_last_tier_open_ended',
				__( 'Quotify: the last pricing tier must have an open-ended maximum (leave "Max pages" blank). No changes were saved.', 'qtfy' )
			);
			return $previous;
		}

		$checkout_url = isset( $input['checkout_url'] ) ? esc_url_raw( $input['checkout_url'] ) : '';

		$sanitized                 = $previous;
		$sanitized['tiers']        = $tiers;
		$sanitized['checkout_url'] = $checkout_url;
		$sanitized['limits']       = self::sanitize_limits( $input['limits'] ?? array() );

		return $sanitized;
	}

	/**
	 * Performance-limit field definitions: label, bounds and help text.
	 *
	 * @return array
	 */
	private static function limit_fields(): array {
		return array(
			'max_pages'            => array(
				'label' => __( 'Max pages to count', 'qtfy' ),
				'min'   => 100,
				'max'   => 100000,
				'step'  => 1,
				'desc'  => __( 'Counting stops once this many pages are found (default 5,001).', 'qtfy' ),
			),
			'max_response_size_mb' => array(
				'label' => __( 'Max sitemap response size (MB)', 'qtfy' ),
				'min'   => 0.5,
				'max'   => 50,
				'step'  => 0.5,
				'desc'  => __( 'Sitemap responses larger than this are rejected (default 2 MB).', 'qtfy' ),
			),
			'fetch_timeout'        => array(
				'label' => __( 'Fetch timeout (seconds)', 'qtfy' ),
				'min'   => 1,
				'max'   => 60,
				'step'  => 1,
				'desc'  => __( 'Per-request timeout for sitemap fetches (default 8).', 'qtfy' ),
			),
			'cache_ttl'            => array(
				'label' => __( 'Cache lifetime (minutes)', 'qtfy' ),
				'min'   => 1,
				'max'   => 1440,
				'step'  => 1,
				'desc'  => __( 'How long a page count is cached per site (default 60).', 'qtfy' ),
			),
			'lock_ttl'             => array(
				'label' => __( 'Stampede lock (seconds)', 'qtfy' ),
				'min'   => 5,
				'max'   => 300,
				'step'  => 1,
				'desc'  => __( 'Prevents concurrent crawls of the same site (default 20).', 'qtfy' ),
			),
			'max_depth'            => array(
				'label' => __( 'Max index depth', 'qtfy' ),
				'min'   => 1,
				'max'   => 5,
				'step'  => 1,
				'desc'  => __( 'How deep sitemap indexes may nest (default 2).', 'qtfy' ),
			),
			'max_subsitemaps'      => array(
				'label' => __( 'Max sub-sitemaps', 'qtfy' ),
				'min'   => 10,
				'max'   => 1000,
				'step'  => 1,
				'desc'  => __( 'Cap on sub-sitemaps fetched from one index (default 100).', 'qtfy' ),
			),
		);
	}

	/**
	 * Sanitize and clamp the submitted limits block.
	 *
	 * @param mixed $raw Submitted limits array.
	 * @return array
	 */
	private static function sanitize_limits( $raw ): array {
		$limits = Sitemap::default_limits();

		if ( ! is_array( $raw ) ) {
			return $limits;
		}

		foreach ( $limits as $key => $default ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}

			$field = self::limit_fields()[ $key ];

			if ( 'max_response_size_mb' === $key ) {
				$limits[ $key ] = round( max( (float) $field['min'], min( (float) $field['max'], (float) $raw[ $key ] ) ), 2 );
			} else {
				$limits[ $key ] = (int) max( (int) $field['min'], min( (int) $field['max'], (int) $raw[ $key ] ) );
			}
		}

		return $limits;
	}

	/**
	 * Normalize a price: non-negative, 2dp float.
	 *
	 * @param float $price Raw price.
	 * @return float
	 */
	private static function normalize_price( float $price ): float {
		return round( max( 0.0, $price ), 2 );
	}

	/**
	 * Normalize a list of submitted tiers: absint bounds, 2dp prices, sorted
	 * by min, blank rows dropped.
	 *
	 * @param mixed $tiers Submitted tiers array.
	 * @return array
	 */
	private static function normalize_tiers( $tiers ): array {
		if ( ! is_array( $tiers ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $tiers as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$min   = isset( $raw['min'] ) ? trim( (string) $raw['min'] ) : '';
			$max   = isset( $raw['max'] ) ? trim( (string) $raw['max'] ) : '';
			$price = isset( $raw['price'] ) ? trim( (string) $raw['price'] ) : '';

			if ( '' === $min && '' === $max && '' === $price ) {
				continue;
			}

			$normalized[] = array(
				'min'   => '' !== $min ? absint( $min ) : 0,
				'max'   => '' !== $max ? absint( $max ) : '',
				'price' => '' !== $price ? self::normalize_price( (float) $price ) : 0.0,
			);
		}

		usort(
			$normalized,
			static function ( array $a, array $b ): int {
				return $a['min'] <=> $b['min'];
			}
		);

		return $normalized;
	}
}