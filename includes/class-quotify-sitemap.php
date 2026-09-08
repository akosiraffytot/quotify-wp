<?php
/**
 * Quotify sitemap parser: robots.txt extraction + XMLReader streaming count.
 *
 * @package Quotify
 */

namespace Quotify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streaming sitemap parsing. No HTTP, no WordPress dependencies.
 */
class Sitemap {

	const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	const CAP_AT     = 5001;

	/**
	 * Extract Sitemap: lines from a robots.txt body.
	 *
	 * Case-insensitive, CRLF-safe, leading indentation allowed, inline
	 * "# comments" stripped. Returns deduped URLs in document order.
	 *
	 * @param string $body robots.txt content.
	 * @return array
	 */
	public static function parse_robots_sitemaps( string $body ): array {
		$found = array();

		if ( ! preg_match_all( '/(?:^|\n)[ \t]*sitemap[ \t]*:[ \t]*([^\s#]+)/i', $body, $matches ) ) {
			return $found;
		}

		foreach ( $matches[1] as $raw ) {
			$url = trim( $raw );

			if ( '' !== $url && ! in_array( $url, $found, true ) ) {
				$found[] = $url;
			}
		}

		return $found;
	}

	/**
	 * Stream-count unique <loc> URLs in a urlset document.
	 *
	 * Stops early at 5,001 unique URLs (CAP_AT) so huge sitemaps never
	 * exhaust memory. image:loc and xhtml:link URLs are not counted.
	 *
	 * @param string $xml Sitemap XML string.
	 * @return array
	 */
	public static function count_urlset( string $xml ): array {
		$seen   = array();
		$count  = 0;
		$capped = false;

		$reader = new \XMLReader();
		if ( ! self::open_reader( $reader, $xml ) ) {
			return array(
				'type'   => 'urlset',
				'count'  => 0,
				'capped' => false,
			);
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType || ! self::is_loc_element( $reader ) ) {
				continue;
			}

			$url = trim( $reader->readString() );

			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}

			$seen[ $url ] = true;
			++$count;

			if ( self::CAP_AT === $count ) {
				$capped = true;
				break;
			}
		}

		$reader->close();

		return array(
			'type'   => 'urlset',
			'count'  => $count,
			'capped' => $capped,
		);
	}

	/**
	 * Stream-extract the <loc> sub-sitemap URLs from an index document.
	 *
	 * @param string $xml Sitemap index XML string.
	 * @return array
	 */
	public static function parse_index( string $xml ): array {
		$sitemaps = array();

		$reader = new \XMLReader();
		if ( ! self::open_reader( $reader, $xml ) ) {
			return array(
				'type'     => 'sitemapindex',
				'sitemaps' => array(),
			);
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType || ! self::is_loc_element( $reader ) ) {
				continue;
			}

			$url = trim( $reader->readString() );

			if ( '' !== $url && ! in_array( $url, $sitemaps, true ) ) {
				$sitemaps[] = $url;
			}
		}

		$reader->close();

		return array(
			'type'     => 'sitemapindex',
			'sitemaps' => $sitemaps,
		);
	}

	/**
	 * Whether the document's root element is a sitemap index.
	 *
	 * @param string $xml Sitemap XML string.
	 * @return bool
	 */
	public static function is_index_sitemap( string $xml ): bool {
		$reader = new \XMLReader();
		if ( ! self::open_reader( $reader, $xml ) ) {
			return false;
		}

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType ) {
				$local_name = $reader->localName;
				$reader->close();

				return 'sitemapindex' === $local_name;
			}
		}

		$reader->close();

		return false;
	}

	/**
	 * Open an XMLReader over a string, suppressing libxml noise.
	 *
	 * @param \XMLReader $reader Reader instance.
	 * @param string     $xml    XML string.
	 * @return bool
	 */
	private static function open_reader( \XMLReader $reader, string $xml ): bool {
		libxml_use_internal_errors( true );
		$ok = $reader->XML( $xml, 'UTF-8' );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		return $ok;
	}

	/**
	 * Whether the current element is a sitemap <loc>.
	 *
	 * Matches on local name plus namespace: the sitemap namespace or an
	 * empty namespace (prefixed-but-standard docs included). Extension
	 * namespaces such as image:loc are excluded.
	 *
	 * @param \XMLReader $reader Reader positioned on an element.
	 * @return bool
	 */
	private static function is_loc_element( \XMLReader $reader ): bool {
		if ( 'loc' !== $reader->localName ) {
			return false;
		}

		$ns = (string) $reader->namespaceURI;

		return '' === $ns || self::SITEMAP_NS === $ns;
	}
}
