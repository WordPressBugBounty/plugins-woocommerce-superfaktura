<?php
/**
 * SuperFaktúra WooCommerce
 *
 * @package   SuperFaktúra WooCommerce
 * @author    2day.sk <superfaktura@2day.sk>
 * @copyright 2022 2day.sk s.r.o., Webikon s.r.o.
 * @license   GPL-2.0+
 * @link      https://www.superfaktura.sk/integracia/
 *
 * @wordpress-plugin
 * Plugin Name: SuperFaktúra WooCommerce
 * Plugin URI:  https://www.superfaktura.sk/integracia/
 * Description: Integrácia služby <a href="http://www.superfaktura.sk/api/">SuperFaktúra.sk</a> pre WooCommerce. Máte s modulom technický problém? Napíšte nám na <a href="mailto:superfaktura@2day.sk">superfaktura@2day.sk</a>
 * Version:     1.42.6
 * Author:      2day.sk
 * Author URI:  https://www.superfaktura.sk/integracia/
 * Requires Plugins: woocommerce
 * WC requires at least: 3.7.0
 * WC tested up to: 9.3.3
 * Text Domain: woocommerce-superfaktura
 * Domain Path: /languages
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

 // If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
define( 'WC_SF_FILE_PATH', __FILE__ );

require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-sf-admin.php';
require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-sf-api.php';
require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-sf-email.php';
require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-sf-helper.php';
require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-sf-invoice.php';
require_once plugin_dir_path( WC_SF_FILE_PATH ) . 'includes/class-wc-superfaktura.php';

// Register hooks that are fired when the plugin is activated, deactivated, and uninstalled, respectively.
register_activation_hook( WC_SF_FILE_PATH, array( 'WC_SuperFaktura', 'activate' ) );
register_deactivation_hook( WC_SF_FILE_PATH, array( 'WC_SuperFaktura', 'deactivate' ) );

// Declare compatibility with HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_SF_FILE_PATH, true );
	}
} );

WC_SuperFaktura::get_instance();

/**
 * Add link to plugin settings to plugin action links
 *
 * @param array $links Plugin action links.
 */
function sf_action_links( $links ) {

	return array_merge(
		array(
			'settings' => '<a href="' . get_admin_url( null, 'admin.php?page=wc-settings&tab=superfaktura' ) . '">' . __( 'Settings', 'woocommerce-superfaktura' ) . '</a>',
		),
		$links
	);

}
add_filter( 'plugin_action_links_' . plugin_basename( WC_SF_FILE_PATH ), 'sf_action_links' );
