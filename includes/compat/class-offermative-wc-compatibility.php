<?php
/**
 * Compatibility class for WooCommerce 4.4.0
 *
 * @package     WC-compat
 * @version     1.0.1
 * @since       WooCommerce 4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Offermative_WC_Compatibility' ) ) {

	/**
	 * Class to check for WooCommerce versions & return variables accordingly
	 */
	class Offermative_WC_Compatibility {

		/**
		 * Function to check if WooCommerce is Greater Than And Equal To 4.4.0
		 *
		 * @return boolean
		 */
		public static function is_wc_gte_44() {
			return self::is_wc_greater_than( '4.3.3' );
		}


		/**
		 * Function to get WooCommerce version
		 *
		 * @return string version or null.
		 */
		public static function get_wc_version() {
			if ( defined( 'WC_VERSION' ) && WC_VERSION ) {
				return WC_VERSION;
			}
			if ( defined( 'WOOCOMMERCE_VERSION' ) && WOOCOMMERCE_VERSION ) {
				return WOOCOMMERCE_VERSION;
			}
			return null;
		}

		/**
		 * Function to compare current version of WooCommerce on site with active version of WooCommerce
		 *
		 * @param string $version Version number to compare.
		 * @return bool
		 */
		public static function is_wc_greater_than( $version ) {
			return version_compare( self::get_wc_version(), $version, '>' );
		}
	}
}
