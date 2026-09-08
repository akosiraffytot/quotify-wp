<?php
/**
 * Uninstall handler: removes Quotify settings.
 *
 * @package Quotify
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'quotify_settings' );
