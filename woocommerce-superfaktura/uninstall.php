<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package   PluginName
 * @author    Your Name <email@example.com>
 * @license   GPL-2.0+
 * @link      http://example.com
 * @copyright 2013 Your Name or Company Name
 */

// If uninstall, not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TODO: Define uninstall functionality here.

// Notices closed by users (eFaktúra announcement, failed documents).
global $wpdb;
$wc_sf_site_ids = ( is_multisite() && function_exists( 'get_sites' ) ) ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( get_current_blog_id() );
foreach ( $wc_sf_site_ids as $wc_sf_site_id ) {
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix( $wc_sf_site_id ) . 'wc_sf_dismissed_notices', '', true );
	if ( is_multisite() ) {
		switch_to_blog( $wc_sf_site_id );
	}
	delete_transient( 'wc_sf_document_errors' );
	if ( is_multisite() ) {
		restore_current_blog();
	}
}
