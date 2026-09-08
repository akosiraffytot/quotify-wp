<?php
/**
 * Quotify updater: checks GitHub releases via Plugin Update Checker.
 *
 * @package Quotify
 */

namespace Quotify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin update checker against the GitHub repo.
 */
final class Updater {

	const REPO_URL = 'https://github.com/akosiraffytot/quotify-wp/';

	/**
	 * Initialize the update checker (safe to run outside any hook).
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! defined( 'QUOTIFY_FILE' ) ) {
			return;
		}

		require_once QUOTIFY_PATH . 'includes/plugin-update-checker/plugin-update-checker.php';

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			\QUOTIFY_FILE,
			'quotify'
		);

		// Pull the quotify.zip release asset attached by our GitHub Action.
		$checker->getVcsApi()->enableReleaseAssets( '/\.zip$/i' );
	}
}
