<?php
/**
 * Main class for Offermative
 *
 * @since       1.0.0
 * @version     1.1.0
 *
 * @package     offermative/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'SA_Offermative' ) ) {

	/**
	 *  Main SA_Offermative Class.
	 *
	 * @return object of SA_Offermative having all functionality of Offermative
	 */
	class SA_Offermative {

		/**
		 * Variable to hold instance of Offermative
		 *
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * Client ID
		 *
		 * @var string $client_id
		 */
		public static $client_id = '97aa84e85e788a40dca1bd24bd389c99';

		/**
		 * Client Secret
		 *
		 * @var string $client_secret
		 */
		public static $client_secret = '4b4332eccbeb7df876b9161eeb677a01';

		/**
		 * Access token
		 *
		 * @var string $access_token
		 */
		public static $access_token = '';

		/**
		 * Valid version flag
		 *
		 * @var boolean $is_valid_version
		 */
		public static $is_valid_version = false;

		/**
		 * API URL
		 *
		 * @var string $api_url
		 */
		public static $api_url = 'https://www.offermative.com/wp-json/om/v1';

		/**
		 * Generation API URL
		 *
		 * @var string $generation_api_url
		 */
		public static $generation_api_url = 'https://services.offermative.com/om';

		/**
		 * Plugin name
		 *
		 * @var string $plugin_name
		 */
		public static $plugin_name = '';

		/**
		 * Plugin version
		 *
		 * @var string $plugin_version
		 */
		public static $plugin_version = '';

		/**
		 * Controller js URL
		 *
		 * @var int $start_offset
		 */
		public static $controller_js_url = '';

		/**
		 * Get single instance of Offermative.
		 *
		 * @return SA_Offermative Singleton object of SA_Offermative
		 */
		public static function get_instance() {

			// Check if instance is already exists.
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Cloning is forbidden.
		 *
		 * @since 1.0.0
		 */
		private function __clone() {
			wc_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ), '1.0.0' );
		}

		/**
		 * Constructor
		 */
		private function __construct() {
			self::$access_token = get_transient( 'sa_om_token' );

			$plugin_data = self::get_plugin_data();

			self::$plugin_name    = $plugin_data['Name'];
			self::$plugin_version = $plugin_data['Version'];

			$this->includes();

			self::$controller_js_url = 'https://d23sljfrdr3lu6.cloudfront.net/general/frontend_v' . self::$plugin_version . '.js';
			self::$is_valid_version  = om_remote_file_exists( self::$controller_js_url );

			$this->init_hooks();
		}

		/**
		 * Include required core files used in admin and on the frontend.
		 */
		public function includes() {
			include_once 'offermative-functions.php';

			if ( is_admin() ) {
				include_once 'admin/class-om-admin-dashboard.php';
				include_once 'admin/deactivation-survey/class-sa-om-deactivation.php';

				if ( class_exists( 'SA_OM_Deactivation' ) ) {
					new SA_OM_Deactivation( SA_OM_PLUGIN_BASENAME, 'Offermative' );
				}
			}

			include_once 'frontend/class-om-controller.php';
			include_once 'frontend/class-om-ajax-controller.php';

			// Needed on admin & frontend both.
			include_once 'class-om-housekeeping.php';
			include_once 'class-om-tracking.php';
		}

		/**
		 * Hook into actions and filters.
		 *
		 * @since 2.3
		 */
		private function init_hooks() {
			add_action( 'wp_enqueue_scripts', array( $this, 'frontend_enqueue_styles_and_scripts' ) );
		}

		/**
		 * Function to register required scripts for frontend.
		 */
		public function frontend_enqueue_styles_and_scripts() {
			// TODO: Enqueue scripts only if offer is there on page.

			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			if ( ! wp_script_is( 'jquery' ) ) {
				wp_enqueue_script( 'jquery' );
			}

			wp_register_script( 'sa-om-handlebars', plugins_url( '/', SA_OM_PLUGIN_FILE ) . 'assets/js/handlebars/handlebars.min.js', array( 'jquery' ), '4.7.2', false );
			wp_register_script( 'sa-om-functions', plugins_url( '/', SA_OM_PLUGIN_FILE ) . 'assets/js/functions.js', array( 'sa-om-handlebars' ), self::$plugin_version, false );
			wp_register_script( 'sa-om-error-handler', plugins_url( '/', SA_OM_PLUGIN_FILE ) . 'assets/js/error-handler.js', array( 'sa-om-functions' ), self::$plugin_version, false );
			if ( ! empty( self::$is_valid_version ) ) {
				wp_register_script( 'sa-om-frontend', self::$controller_js_url, array( 'sa-om-error-handler' ), self::$plugin_version, false );
			}

			$checkout_url = ( is_callable( 'wc_get_checkout_url' ) ) ? untrailingslashit( wc_get_checkout_url() ) : '';
			$om_params    = array(
				'nonce'                      => wp_create_nonce( 'offermative-security' ),
				'ajax_url'                   => admin_url( 'admin-ajax.php' ),
				'checkout_url'               => esc_url_raw( $checkout_url ),
				'is_get_recommendation'      => wc_bool_to_string( is_product() || is_cart() || is_wc_endpoint_url( 'order-received' ) ),
				'currencySymbol'             => get_woocommerce_currency_symbol(),
				'defaultOfferedProductImage' => SA_OM_IMG_URL . 'default-offered-product.jpg',
				'defaultAvatarImage'         => SA_OM_IMG_URL . 'default-avatar.jpg',
				'assetsURL'                  => SA_OM_IMG_URL,
				'isValid'                    => self::$is_valid_version,
			);

			if ( empty( self::$access_token ) ) {
				wp_localize_script( 'sa-om-error-handler', 'OMParams', $om_params );
				wp_enqueue_script( 'sa-om-error-handler' );
			} else {
				wp_localize_script( 'sa-om-frontend', 'OMParams', $om_params );
				wp_enqueue_script( 'sa-om-frontend' );
			}

			wp_register_style( 'sa-om-tailwind', plugins_url( '/', SA_OM_PLUGIN_FILE ) . 'assets/css/styles.css', array(), self::$plugin_version, false );
			wp_enqueue_style( 'sa-om-tailwind' );

			if ( ! wp_script_is( 'wc-add-to-cart-variation' ) ) {
				wp_enqueue_script( 'wc-add-to-cart-variation' );
			}
		}

		/**
		 * Get plugins data
		 *
		 * @return array
		 */
		public static function get_plugin_data() {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				include_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			return get_plugin_data( SA_OM_PLUGIN_FILE );
		}

	}

}
