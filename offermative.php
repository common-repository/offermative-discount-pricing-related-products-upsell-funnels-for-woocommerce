<?php
/**
 * Plugin Name: Offermative: discount pricing, related products, upsell funnels for WooCommerce
 * Description: Automate design, discounts, targeting, copywriting for product recommendation, offers. AI-powered promotions & discount plugin for WooCommerce stores.
 * Version: 5.0.0
 * Author: Offermative
 * Author URI: https://www.offermative.com/
 * Developer: StoreApps
 * Developer URI: https://www.storeapps.org/
 * Requires at least: 5.0.0
 * Tested up to: 6.1.0
 * Requires PHP: 5.6+
 * WC requires at least: 3.7.0
 * WC tested up to: 7.0.1
 * Text Domain: offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce
 * Domain Path: /languages/
 * Copyright (c) 2020 - 2022 Offermative All rights reserved.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package offermative
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SA_OM_PLUGIN_FILE' ) ) {
	define( 'SA_OM_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'SA_OM_PLUGIN_DIRNAME' ) ) {
	define( 'SA_OM_PLUGIN_DIRNAME', dirname( plugin_basename( __FILE__ ) ) );
}
if ( ! defined( 'SA_OM_PLUGIN_BASENAME' ) ) {
	define( 'SA_OM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'SA_OM_PLUGIN_URL' ) ) {
	define( 'SA_OM_PLUGIN_URL', plugins_url( SA_OM_PLUGIN_DIRNAME ) );
}
if ( ! defined( 'SA_OM_IMG_URL' ) ) {
	define( 'SA_OM_IMG_URL', SA_OM_PLUGIN_URL . '/assets/images/' );
}
if ( ! defined( 'SA_OM_AJAX_SECURITY' ) ) {
	define( 'SA_OM_AJAX_SECURITY', 'offermative_ajax_call' );
}

if ( ! class_exists( 'SA_Offermative_Install' ) && file_exists( ( dirname( __FILE__ ) ) . '/includes/class-sa-offermative.php' ) ) {
	include_once 'includes/class-sa-offermative.php';
}

if ( ! class_exists( 'SA_Offermative_Install' ) && file_exists( ( dirname( __FILE__ ) ) . '/includes/class-sa-offermative-install.php' ) ) {
	include_once 'includes/class-sa-offermative-install.php';
}
register_activation_hook( SA_OM_PLUGIN_FILE, array( 'SA_Offermative_Install', 'install' ) );

/**
 * Load Offermative
 */
function sa_initialize_offermative() {

	require_once 'includes/compat/class-offermative-wc-compatibility.php';

	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
	}

	// Instance of main class.
	if ( ( in_array( 'woocommerce/woocommerce.php', $active_plugins, true ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins ) ) ) {
		$GLOBALS['sa_offermative'] = SA_Offermative::get_instance();
	} else {
		if ( is_admin() ) {
			?>
			<div id="message" class="error">
				<p>
				<?php
					printf(
						'<strong>%1$s</strong><br> <a href="%2$s" target="_blank">%3$s</a> %4$s. %5$s',
						esc_html__( 'Offermative needs WooCommerce.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
						'https://wordpress.org/plugins/woocommerce/',
						esc_html__( 'WooCommerce', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
						esc_html__( 'must be active for Offermative to work', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
						esc_html__( 'Please install & activate WooCommerce.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' )
					);
				?>
				</p>
			</div>
			<?php
		}
	}
}
add_action( 'plugins_loaded', 'sa_initialize_offermative' );

/**
 * Action for WooCommerce v7.1 custom order tables related compatibility.
*/
add_action(
	'before_woocommerce_init',
	function() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, false );
		}
	}
);

