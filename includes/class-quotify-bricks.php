<?php
/**
 * Quotify Bricks Builder integration: custom dynamic data tags.
 *
 * Registers {quotify_page_count} and {quotify_price} dynamic data tags for
 * the current logged-in user's latest saved scan.
 *
 * @package Quotify
 */

namespace Quotify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks dynamic data tags for saved scan values.
 */
class Bricks {

	const TAG_COUNT = 'quotify_page_count';
	const TAG_PRICE = 'quotify_price';

	/**
	 * Register the Bricks dynamic data filters.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'bricks/dynamic_tags_list', array( __CLASS__, 'tags_list' ) );
		add_filter( 'bricks/dynamic_data/render_tag', array( __CLASS__, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( __CLASS__, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( __CLASS__, 'render_content' ), 20, 2 );
	}

	/**
	 * Add the tags to the builder's dynamic data picker.
	 *
	 * @param array $tags Existing tag registry.
	 * @return array
	 */
	public static function tags_list( array $tags ): array {
		$tags[] = array(
			'name'  => '{' . self::TAG_COUNT . '}',
			'label' => __( 'Quotify saved page count', 'qtfy' ),
			'group' => __( 'Quotify', 'qtfy' ),
		);
		$tags[] = array(
			'name'  => '{' . self::TAG_PRICE . '}',
			'label' => __( 'Quotify price', 'qtfy' ),
			'group' => __( 'Quotify', 'qtfy' ),
		);

		return $tags;
	}

	/**
	 * Render a single dynamic data tag.
	 *
	 * @param mixed $tag Tag name (string) or other data to pass through.
	 * @return string|array
	 */
	public static function render_tag( $tag ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$clean = str_replace( array( '{', '}' ), '', $tag );

		if ( self::TAG_COUNT !== $clean && self::TAG_PRICE !== $clean ) {
			return $tag;
		}

		return self::tag_value( $clean );
	}

	/**
	 * Replace Quotify tags inside mixed content.
	 *
	 * Bails out early when no Quotify tag is present so unrelated content is
	 * never touched.
	 *
	 * @param mixed $content Element content (string) or other data to pass through.
	 * @return mixed
	 */
	public static function render_content( $content ) {
		if ( ! is_string( $content ) ) {
			return $content;
		}

		if ( false === strpos( $content, '{' . self::TAG_COUNT ) && false === strpos( $content, '{' . self::TAG_PRICE ) ) {
			return $content;
		}

		$tags = array( self::TAG_COUNT, self::TAG_PRICE );

		foreach ( $tags as $tag ) {
			$content = str_replace( '{' . $tag . '}', self::tag_value( $tag ), $content );
		}

		return $content;
	}

	/**
	 * Value for a Quotify tag from the current logged-in user's saved scan.
	 *
	 * @param string $tag Tag name without braces.
	 * @return string
	 */
	public static function tag_value( string $tag ): string {
		if ( ! is_user_logged_in() || ( self::TAG_COUNT !== $tag && self::TAG_PRICE !== $tag ) ) {
			return '';
		}

		$saved = \quotify_get_saved_scan( get_current_user_id() );

		if ( $saved['count'] <= 0 ) {
			return '';
		}

		if ( self::TAG_COUNT === $tag ) {
			return number_format_i18n( $saved['count'], 0 );
		}

		$settings = Admin::get_settings();
		$price    = Admin::get_price( $saved['count'], $settings['tiers'] );

		return Admin::price_label( $price );
	}
}
