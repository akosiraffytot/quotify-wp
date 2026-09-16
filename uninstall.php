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

delete_metadata( 'user', 0, 'quotify_page_count', '', true );
delete_metadata( 'user', 0, 'quotify_scanned_at', '', true );
delete_metadata( 'user', 0, 'quotify_scanned_url', '', true );
