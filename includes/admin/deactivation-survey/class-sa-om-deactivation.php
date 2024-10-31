<?php
/**
 * Class for Offermative Deactivation Survey
 *
 * @since       1.1.0
 * @version     1.0.0
 *
 * @package     offermative/includes/admin/deactivation-survey
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'SA_OM_Deactivation' ) ) {

	/**
	 *  OM Deactivation Survey Class.
	 *
	 * @return object of SA_OM_Deactivation
	 */
	class SA_OM_Deactivation {

		/**
		 * Variable to hold deactivation strings
		 *
		 * @var $om_deactivation_string
		 */
		public static $om_deactivation_string;

		/**
		 * Variable to hold Plugin name
		 *
		 * @var $plugin_name
		 */
		public static $plugin_name = '';

		/**
		 * Variable to hold Plugin file name
		 *
		 * @var $sa_plugin_file_name
		 */
		public static $sa_plugin_file_name = '';

		/**
		 * Variable to hold Plugin URL
		 *
		 * @var $sa_plugin_url
		 */
		public static $sa_plugin_url = '';

		/**
		 * Constructor
		 *
		 * @param string $sa_plugin_file_name file name of the plugin.
		 * @param string $sa_plugin_name Name of the plugin.
		 */
		public function __construct( $sa_plugin_file_name = '', $sa_plugin_name = '' ) {

			self::$sa_plugin_file_name = $sa_plugin_file_name;
			self::$plugin_name         = $sa_plugin_name;
			self::$sa_plugin_url       = untrailingslashit( plugin_dir_path( __FILE__ ) );

			self::sa_load_all_str();
			add_action( 'admin_footer', array( $this, 'maybe_load_deactivate_options' ) );
			add_action( 'wp_ajax_om_submit_survey', array( $this, 'sa_submit_deactivation_reason_action' ) );
			add_filter( 'plugin_action_links_' . self::$sa_plugin_file_name, array( $this, 'sa_plugin_settings_link' ) );

		}

		/**
		 * Settings link on Plugins page
		 *
		 * @param array $links array of links for the plugin.
		 * @return array $links modified links array.
		 */
		public static function sa_plugin_settings_link( $links ) {

			if ( isset( $links['deactivate'] ) ) {
				$links['deactivate'] .= '<i class="sa-om-slug" data-slug="' . self::$sa_plugin_file_name . '"></i>';
			}
			return $links;
		}

		/**
		 * Localizes all the string used
		 */
		public static function sa_load_all_str() {
			self::$om_deactivation_string = array(
				'deactivation-headline'              => __( 'Quick Feedback for Offermative plugin', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				'deactivation-share-reason'          => __( 'Take a moment to let us know why you are deactivating', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				'deactivation-modal-button-submit'   => __( 'Submit & Deactivate', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				'deactivation-modal-button-cancel'   => __( 'Skip & Deactivate', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				'deactivation-modal-button-confirm'  => __( 'Yes - Deactivate', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				'deactivation-modal-skip-deactivate' => __( 'Submit a reason to deactivate', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				'deactivation-modal-error'           => __( 'Please select an option', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
			);
		}

		/**
		 * Checking current page and pushing html, js and css for this task
		 *
		 * @global string $pagenow current admin page
		 * @global array $vars global vars to pass to view file
		 */
		public static function maybe_load_deactivate_options() {
			global $pagenow;

			if ( 'plugins.php' === $pagenow ) {
				global $vars;
				$vars = array(
					'slug'    => 'asvbsd',
					'reasons' => self::deactivate_options(),
				);
				include_once self::$sa_plugin_url . '/class-sa-om-deactivation-modal.php';
			}
		}

		/**
		 * Deactivation reasons in array format
		 *
		 * @return array reasons array
		 * @since 1.0.0
		 */
		public static function deactivate_options() {

			$reasons = array();
			$reasons = array(
				array(
					'id'                => 1,
					'text'              => __( 'The plugin is not working / not compatible with another plugin/my theme.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => 'textarea',
					'input_placeholder' => __( 'Kindly share what did not work for you / conflicting with which plugin so we can fix it...', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				),
				array(
					'id'                => 2,
					'text'              => __( 'I don\'t want to sign up for a paid account', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => 'textarea',
					'input_placeholder' => __( 'What specific feature you need?', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				),
				array(
					'id'                => 3,
					'text'              => __( 'I only needed the plugin for a short period', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => 'textarea',
					'input_placeholder' => __( 'What did you wanted to do in short period?', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				),
				array(
					'id'                => 4,
					'text'              => __( 'The plugin is great, but I need specific feature that you don\'t support', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => 'textarea',
					'input_placeholder' => __( 'What specific feature you need?', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				),
				array(
					'id'                => 5,
					'text'              => __( 'I found another plugin for my needs', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => 'textfield',
					'input_placeholder' => __( 'What is that plugin name?', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				),
				array(
					'id'                => 6,
					'text'              => __( 'It is a temporary deactivation. I am just debugging an issue.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => '',
					'input_placeholder' => '',
				),
				array(
					'id'                => 7,
					'text'              => __( 'Other', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
					'input_type'        => 'textarea',
					'input_placeholder' => __( 'Please mention...', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ),
				),
			);

			$uninstall_reasons['default'] = $reasons;

			return $uninstall_reasons;
		}

		/**
		 * Get exact str against the slug.
		 *
		 * @param type $slug plugin slug.
		 * @return type
		 */
		public static function load_str( $slug ) {
			return self::$om_deactivation_string[ $slug ];
		}

		/**
		 * Called after the user has submitted his reason for deactivating the plugin.
		 *
		 * @since  1.1.2
		 */
		public static function sa_submit_deactivation_reason_action() {

			check_ajax_referer( 'offermative-security', 'security' );

			if ( ! isset( $_POST['reason_id'] ) ) {
				exit;
			}

			$api_url = 'https://www.offermative.com/wp-admin/admin-ajax.php';

			// Plugin specific options should be added from here.

			if ( ! empty( $_POST ) ) {
				$plugin_data           = $_POST;
				$plugin_data['domain'] = home_url();
				$plugin_data['action'] = 'submit_survey';
			} else {
				exit();
			}

			$method  = 'POST';
			$qs      = http_build_query( $plugin_data );
			$options = array(
				'timeout' => 45,
				'method'  => $method,
			);
			if ( 'POST' === $method ) {
				$options['body'] = $qs;
			} else {
				if ( strpos( $api_url, '?' ) !== false ) {
					$api_url .= '&' . $qs;
				} else {
					$api_url .= '?' . $qs;
				}
			}

			$response = wp_remote_request( $api_url, $options );

			if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
				$data = json_decode( $response['body'], true );

				if ( empty( $data['error'] ) ) {
					if ( ! empty( $data ) && ! empty( $data['success'] ) ) {
						echo 1;
					}
					wp_send_json( $data );
				}
			}
			// Print '1' for successful operation.
			echo 1;
			exit();
		}

	} // End of Class

}
