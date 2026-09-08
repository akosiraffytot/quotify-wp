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

		return array_merge(
			array(
				'tiers'        => array(),
				'checkout_url' => '',
			),
			$settings
		);
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

		return $sanitized;
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
				'price' => '' !== $price ? round( max( 0.0, (float) $price ), 2 ) : 0.0,
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