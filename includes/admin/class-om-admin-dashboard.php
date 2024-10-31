<?php
/**
 * Class for Offermative Admin Dashboard
 *
 * @since       1.1.0
 * @version     1.0.0
 *
 * @package     offermative/includes/admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'OM_Admin_Dashboard' ) ) {

	/**
	 *  Main OM_Admin_Dashboard Class.
	 *
	 * @return object of OM_Admin_Dashboard having Offermative Dashboard
	 */
	class OM_Admin_Dashboard {

		/**
		 * Variable to hold instance of offermative
		 *
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * Batch limit
		 *
		 * @var int $batch_limit
		 */
		public $batch_limit = 15;

		/**
		 * Start offset
		 *
		 * @var int $start_offset
		 */
		public $start_offset = 0;

		/**
		 * Get single instance of offermative.
		 *
		 * @return OM_Admin_Dashboard Singleton object of OM_Admin_Dashboard
		 */
		public static function get_instance() {

			// Check if instance is already exists.
			if ( is_null( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Constructor
		 */
		private function __construct() {
			add_action( 'admin_menu', array( $this, 'add_om_admin_menu' ), 20 );
			add_action( 'admin_init', array( $this, 'show_dashboard' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_dashboard_scripts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_dashboard_styles' ) );
			add_action( 'wp_ajax_om_dashboard_controller', array( $this, 'request_handler' ) );
			add_action( 'admin_print_scripts', array( $this, 'remove_admin_notices' ) );
			add_action( 'wp_ajax_om_json_search_products_only_and_not_variations', array( $this, 'om_json_search_products_only_and_not_variations' ) );

			// To update footer text on FW screens.
			add_filter( 'admin_footer_text', array( $this, 'om_footer_text' ) );
			add_filter( 'update_footer', array( $this, 'om_update_footer_text' ), 99 );
		}

		/**
		 * Function to handle WC compatibility related function call from appropriate class.
		 *
		 * @param string $function_name Function to call.
		 * @param array  $arguments Array of arguments passed while calling $function_name.
		 * @return mixed Result of function call.
		 */
		public function __call( $function_name, $arguments = array() ) {
			if ( ! is_callable( 'Offermative_WC_Compatibility', $function_name ) ) {
				return;
			}

			if ( ! empty( $arguments ) ) {
				return call_user_func_array( 'Offermative_WC_Compatibility::' . $function_name, $arguments );
			} else {
				return call_user_func( 'Offermative_WC_Compatibility::' . $function_name );
			}
		}

		/**
		 * Sends user to the dashboard/settings page on first activation.
		 */
		public function show_dashboard() {

			if ( ! get_transient( '_sa_om_activation_redirect' ) ) {
				return;
			}

			$default_route = '#!/' . ( ( empty( $this->get_settings() ) || empty( SA_Offermative::$access_token ) ) ? 'settings' : 'dashboard' );

			// Delete the redirect transient.
			delete_transient( '_sa_om_activation_redirect' );

			wp_safe_redirect( admin_url( 'admin.php?page=offermative' . $default_route ) );
			exit;

		}

		/**
		 * Function to remove admin notices from offermative dashboard page.
		 */
		public function remove_admin_notices() {
			$screen    = get_current_screen();
			$screen_id = $screen ? $screen->id : '';

			if ( 'woocommerce_page_offermative' === $screen_id || 'marketing_page_offermative' === $screen_id ) {
				remove_all_actions( 'admin_notices' );
			}
		}

		/**
		 * Admin menus
		 */
		public function add_om_admin_menu() {
			/* translators: A small arrow */
			$parent_slug = ( $this->is_wc_gte_44() ) ? 'woocommerce-marketing' : 'woocommerce';
			add_submenu_page( $parent_slug, __( 'Offermative Dashboard', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ), __( 'Offermative', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ), 'manage_woocommerce', 'offermative', array( $this, 'dashboard_page' ) );
		}

		/**
		 * Function to register required scripts for admin dashboard.
		 */
		public function register_admin_dashboard_scripts() {
			$registered_scripts = array();
			$suffix             = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

			// Dashboard scripts.
			wp_register_script( 'om-frappe-chart', SA_OM_PLUGIN_URL . '/assets/js/admin/frappe-charts/frappe-charts.min.iife.js', array( 'jquery' ), SA_Offermative::$plugin_version, true );
			wp_register_script( 'om-datepicker', SA_OM_PLUGIN_URL . '/assets/js/admin/zebra-datepicker/zebra-datepicker.min.js', array( 'om-frappe-chart' ), SA_Offermative::$plugin_version, true );
			wp_register_script( 'mithril', SA_OM_PLUGIN_URL . '/assets/js/admin/mithril/mithril.min.js', array( 'om-datepicker' ), SA_Offermative::$plugin_version, true );
			wp_register_script( 'om-admin-dashboard-styles', SA_OM_PLUGIN_URL . '/assets/js/admin/styles.js', array( 'mithril' ), SA_Offermative::$plugin_version, true );
			wp_register_script( 'om-admin-dashboard', SA_OM_PLUGIN_URL . '/assets/js/admin/admin.js', array( 'om-admin-dashboard-styles', 'wp-i18n' ), SA_Offermative::$plugin_version, true );

			array_push( $registered_scripts, 'om-admin-dashboard' );

			wp_register_script( 'sa-om-handlebars', SA_OM_PLUGIN_URL . '/assets/js/handlebars/handlebars.min.js', array( 'jquery' ), SA_Offermative::$plugin_version, false );
			wp_register_script( 'sa-om-functions', SA_OM_PLUGIN_URL . '/assets/js/functions.js', array( 'sa-om-handlebars' ), SA_Offermative::$plugin_version, false );
			wp_register_script( 'sa-om-error-handler', SA_OM_PLUGIN_URL . '/assets/js/error-handler.js', array( 'sa-om-functions' ), SA_Offermative::$plugin_version, false );

			if ( ! empty( SA_Offermative::$is_valid_version ) ) {
				wp_register_script( 'sa-offermative', SA_Offermative::$controller_js_url, array( 'sa-om-error-handler' ), SA_Offermative::$plugin_version, false );
			}

			if ( ! wp_script_is( 'selectWoo', 'registered' ) ) {
				wp_register_script( 'selectWoo', WC()->plugin_url() . '/assets/js/selectWoo/selectWoo.full' . $suffix . '.js', array( 'jquery' ), WC_VERSION, false );
			}

			if ( ! wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
				wp_register_script( 'wc-enhanced-select', WC()->plugin_url() . '/assets/js/admin/wc-enhanced-select' . $suffix . '.js', array( 'jquery', 'selectWoo' ), WC_VERSION, false );
			}

			if ( ! wp_style_is( 'select2', 'registered' ) ) {
				wp_register_style( 'select2', WC()->plugin_url() . '/assets/css/select2.css', array(), WC_VERSION, false );
			}

			( is_callable( array( 'OM_Admin_Dashboard', 'set_script_translations' ) ) ) ? self::set_script_translations( $registered_scripts ) : '';
		}

		/**
		 * Set translation script for JS
		 *
		 * @param array $handles array of all the script handles for which translations is to be set.
		 */
		public static function set_script_translations( $handles = array() ) {
			if ( function_exists( 'wp_set_script_translations' ) && ! empty( $handles ) && count( $handles ) > 0 ) {
				foreach ( $handles as $handle ) {
					wp_set_script_translations( $handle, 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce', dirname( SA_OM_PLUGIN_FILE ) . '/languages' );
				}
			}
		}

		/**
		 * Function to register required styles for admin dashboard.
		 */
		public function register_admin_dashboard_styles() {
			wp_register_style( 'om-tailwind', SA_OM_PLUGIN_URL . '/assets/css/admin/styles.css', array(), SA_Offermative::$plugin_version, false );
			wp_register_style( 'om-zebra-datepicker', SA_OM_PLUGIN_URL . '/assets/css/admin/zebra-datepicker/zebra-datepicker.min.css', array(), SA_Offermative::$plugin_version, false );
			wp_register_style( 'om-frappe-chart', SA_OM_PLUGIN_URL . '/assets/css/admin/frappe-charts/frappe-charts.min.css', array( 'om-zebra-datepicker' ), SA_Offermative::$plugin_version, false );
			wp_register_style( 'om-frappe-chart-custom', SA_OM_PLUGIN_URL . '/assets/css/admin/frappe-charts/frappe-charts-custom.css', array( 'om-frappe-chart' ), SA_Offermative::$plugin_version, false );
		}

		/**
		 * Function to show admin dashboard.
		 */
		public function dashboard_page() {
			if ( ! wp_style_is( 'om-tailwind' ) ) {
				wp_enqueue_style( 'om-tailwind' );
			}

			if ( ! wp_style_is( 'select2' ) ) {
				wp_enqueue_style( 'select2' );
			}

			if ( function_exists( 'wp_enqueue_editor' ) ) {
				wp_enqueue_editor();
			}

			if ( ! wp_style_is( 'om-frappe-chart-custom' ) ) {
				wp_enqueue_style( 'om-frappe-chart-custom' );
			}

			if ( ! empty( SA_Offermative::$is_valid_version ) ) {
				if ( ! wp_script_is( 'sa-offermative' ) ) {
					wp_enqueue_script( 'sa-offermative' );
				}
			} else {
				if ( ! wp_script_is( 'sa-om-error-handler' ) ) {
					wp_enqueue_script( 'sa-om-error-handler' );
				}
			}

			if ( ! wp_script_is( 'selectWoo' ) ) {
				wp_enqueue_script( 'selectWoo' );
			}

			if ( ! wp_script_is( 'wc-enhanced-select' ) ) {
				wp_enqueue_script( 'wc-enhanced-select' );
			}

			if ( ! wp_script_is( 'om-admin-dashboard' ) ) {
				wp_enqueue_script( 'om-admin-dashboard' );
			}

			// Default User Details.
			$default_admin_details = array();
			$email                 = get_option( 'admin_email' );
			if ( ! empty( $email ) ) {
				$user = get_user_by( 'email', $email );
				if ( ! empty( $user ) ) {
					$default_admin_details['name']  = ( ! empty( $user->first_name ) ) ? $user->first_name : '';
					$default_admin_details['email'] = $email;
				}
			}

			wp_localize_script(
				'om-admin-dashboard',
				'omDashboardParams',
				array(
					'security'                    => wp_create_nonce( SA_OM_AJAX_SECURITY ),
					'accessToken'                 => SA_Offermative::$access_token,
					'isSettingsEmpty'             => ( empty( $this->get_settings() ) ? 1 : 0 ),
					'defaultRoute'                => '/' . ( ( empty( $this->get_settings() ) || empty( SA_Offermative::$access_token ) ) ? 'settings' : 'dashboard' ),
					'currencySymbol'              => get_woocommerce_currency_symbol(),
					'decimalSeparator'            => wc_get_price_decimal_separator(),
					'isValid'                     => SA_Offermative::$is_valid_version,
					'tailwindCSSURL'              => SA_OM_PLUGIN_URL . '/assets/css/admin/styles.css',
					'frontendTailwindCSSURL'      => SA_OM_PLUGIN_URL . '/assets/css/styles.css',
					'loaderWCGif'                 => WC()->plugin_url() . '/assets/images/wpspin-2x.gif',
					'defaultOfferedProductImage'  => SA_OM_IMG_URL . 'default-offered-product.jpg',
					'defaultAvatarImage'          => SA_OM_IMG_URL . 'default-avatar.jpg',
					'assetsURL'                   => SA_OM_IMG_URL,
					'defaultAdminDetails'         => $default_admin_details,
					'offerPages'                  => array( 'home', 'blog', 'shop', 'cart', 'checkout', 'myaccount', 'post', 'page', 'category', 'product', 'product-category', 'thankyou' ),
					'currentActiveCampaignsCount' => om_get_active_campaigns_count(),
					'maxActiveCampaignsCount'     => om_get_max_active_campaigns_count(),
					'adminURL'                    => admin_url(),
				)
			);

			?>
			<style type="text/css">
				#wpcontent {
					padding-left: 0 !important;
		}
		#wpfooter {
			display: none !important;
		}
			</style>
			<div class="offermative" id="om-admin-dashboard"></div>
			<?php
		}

		/**
		 * Function to handle all ajax request
		 */
		public function request_handler() {
			if ( empty( $_REQUEST ) || empty( $_REQUEST['cmd'] ) ) {
				return;
			}

			check_ajax_referer( SA_OM_AJAX_SECURITY, 'security' );

			$params = array_map(
				function ( $request_param ) {
					if ( is_array( $request_param ) ) {
						return array_map(
							function ( $param ) {
								return sanitize_text_field( wp_unslash( $param ) );
							},
							$request_param
						);
					} else {
						return sanitize_text_field( wp_unslash( $request_param ) );
					}
				},
				$_REQUEST
			);

			$func_nm            = $params['cmd'];
			$params['page'] = isset( $params['page'] ) ? $params['page'] : 1; // phpcs:ignore
			$this->start_offset = ( ! empty( $params['page'] ) ) ? ( intval( $params['page'] ) - 1 ) * $this->batch_limit : 0;

			if ( is_callable( array( $this, $func_nm ) ) ) {
				$this->$func_nm( $params );
			}
		}

		/**
		 * Handler for AJAX request for getting offermative dashboard KPI + Lists data
		 *
		 * @param array $params Params from the AJAX request.
		 */
		public function dashboard_data( $params = array() ) {
			$data = array(
				'kpi'           => $this->get_kpi_data( $params ),
				'campaignsList' => $this->get_campaign_list( $params, false ),
				'recentOrders'  => $this->get_recent_orders(),
				'settings'      => $this->get_settings( $params ),
			);

			if ( isset( $params['page'] ) && 1 === absint( $params['page'] ) ) {
				$rules = $this->get_available_campaign_rules();
				if ( ! empty( $rules ) ) {
					$data['campaignRules'] = $rules;
				}

				$offer_types = $this->get_available_campaign_offer_types();
				if ( ! empty( $offer_types ) ) {
					$data['campaignOfferTypes'] = $offer_types;
				}
			}

			wp_send_json( $data );
		}

		/**
		 * Get Avalaible Campaign Rules
		 *
		 * @return array available campain rule with its possible operators
		 */
		public function get_available_campaign_rules() {

			$rules = array();

			if ( empty( SA_Offermative::$access_token ) ) {
				return $rules;
			}

			$url = SA_Offermative::$generation_api_url . '/campaign-rules';

			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'token'        => SA_Offermative::$access_token,
				),
			);

			$response = wp_remote_get( esc_url_raw( $url ), $args );

			if ( ! is_wp_error( $response ) ) {
				$response_body = json_decode( $response['body'], true );
				$rules         = ( ! empty( $response_body['rules'] ) ) ? $response_body['rules'] : array();
			}

			return $rules;
		}

		/**
		 * Get Avalaible Campaign Rules
		 *
		 * @return array available campain rule with its possible operators
		 */
		public function get_available_campaign_offer_types() {

			$offer_types = array();

			if ( empty( SA_Offermative::$access_token ) ) {
				return $offer_types;
			}

			$url = SA_Offermative::$generation_api_url . '/campaign-offer-types';

			$args = array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'token'        => SA_Offermative::$access_token,
				),
			);

			$response = wp_remote_get( esc_url_raw( $url ), $args );

			if ( ! is_wp_error( $response ) ) {
				$response_body = json_decode( $response['body'], true );
				$offer_types   = ( ! empty( $response_body['offerTypes'] ) ) ? $response_body['offerTypes'] : array();
			}

			return $offer_types;
		}

		/**
		 * Get KPI data
		 *
		 * @param array $params The request params.
		 * @return array dashboard data
		 */
		public function get_kpi_data( $params ) {

			$campaign_kpis = $this->get_campaigns_kpis();
			$chart_data    = $this->get_chart_data();
			return array(
				'totalRevenue'   => $this->get_total_revenue(),
				'views'          => ( ( ! empty( $campaign_kpis[0]['views'] ) ) ? intval( $campaign_kpis[0]['views'] ) : 0 ),
				'earnings'       => ( ( ! empty( $campaign_kpis[0]['earnings'] ) ) ? intval( $campaign_kpis[0]['earnings'] ) : 0 ),
				'orderCount'     => ( ( ! empty( $campaign_kpis[0]['orderCount'] ) ) ? intval( $campaign_kpis[0]['orderCount'] ) : 0 ),
				'orderItemCount' => ( ( ! empty( $campaign_kpis[0]['orderItemCount'] ) ) ? intval( $campaign_kpis[0]['orderItemCount'] ) : 0 ),
				'chartData'      => ( ( ! empty( $chart_data ) ) ? $this->auto_fill_empty_chart_data( $chart_data ) : array() ),
			);
		}

		/**
		 * Get dashboard chart data
		 *
		 * TODO: Logic to auto select data point as days, weeks, months, years based on the range of data
		 *
		 * @param array $campaign_ids Campaign Ids for which chart data is to be fetched.
		 * @return array $chart_data The overall chart data or for the campaigns supplied
		 */
		public function get_chart_data( $campaign_ids = array() ) {
			global $wpdb;

			$chart_data = array();

			if ( ! empty( $campaign_ids ) && ! is_array( $campaign_ids ) ) {
				$campaign_ids = array( $campaign_ids );
			}

			if ( ! empty( $campaign_ids ) ) {
				$option_nm = 'sa_om_chart_campaign_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );

				$results = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT g.campaign_id,
								FROM_UNIXTIME(g.timestamp, '%%Y-%%m-%%d') AS the_date,
								IFNULL(SUM(o.line_total), 0) AS earning
							FROM {$wpdb->prefix}om_tracking_general AS g
								JOIN {$wpdb->prefix}om_tracking_orders AS o
									ON (g.id = o.tracking_id
											AND g.event = %s
											AND o.is_valid_order = %d)
							WHERE FROM_UNIXTIME(g.timestamp, '%%Y-%%m-%%d') BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW()
								AND FIND_IN_SET ( g.campaign_id, ( SELECT option_value
																	FROM {$wpdb->prefix}options
																	WHERE option_name = %s ) )
							GROUP BY g.campaign_id, the_date",
						'convert',
						1,
						$option_nm
					),
					ARRAY_A
				);

				delete_option( $option_nm );
			} else {
				$results = $wpdb->get_results( // phpcs:ignore
								$wpdb->prepare( // phpcs:ignore
									"SELECT g.campaign_id,
											FROM_UNIXTIME(g.timestamp, '%%Y-%%m-%%d') AS the_date,
											IFNULL(SUM(o.line_total), 0) AS earning
										FROM {$wpdb->prefix}om_tracking_general AS g
											JOIN {$wpdb->prefix}om_tracking_orders AS o
												ON (g.id = o.tracking_id
														AND g.event = %s
														AND o.is_valid_order = %d)
										WHERE FROM_UNIXTIME(g.timestamp, '%%Y-%%m-%%d') BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW()
										GROUP BY the_date",
									'convert',
									1
								),
					ARRAY_A
				);
			}

			if ( ! empty( $results ) ) {
				if ( ! empty( $campaign_ids ) ) {
					foreach ( $results as $result ) {
						$campaign_id = ( ! empty( $result['campaign_id'] ) ) ? absint( $result['campaign_id'] ) : 0;
						if ( empty( $campaign_id ) ) {
							continue;
						}
						$the_date = ( ! empty( $result['the_date'] ) ) ? $result['the_date'] : '';
						if ( empty( $the_date ) ) {
							continue;
						}
						if ( empty( $chart_data[ $campaign_id ] ) || ! is_array( $chart_data[ $campaign_id ] ) ) {
							$chart_data[ $campaign_id ] = array();
						}
						$earning                                 = ( ! empty( $result['earning'] ) ) ? intval( $result['earning'] ) : 0;
						$chart_data[ $campaign_id ][ $the_date ] = $earning;
					}
				} else {
					$chart_data = array_map( 'intval', wp_list_pluck( $results, 'earning', 'the_date' ) );
				}
			}

			return $chart_data;
		}

		/**
		 * Auto fill empty chart data
		 *
		 * @param array $chart_data The chart data fetched from db.
		 * @return array $chart_data Empty filled final chart data
		 */
		public function auto_fill_empty_chart_data( $chart_data = array() ) {
			if ( ! empty( $chart_data ) ) {
				$dates = array_keys( $chart_data );
				if ( ! empty( $dates ) ) {
					$end_date   = strtotime( current_time( 'Y-m-d' ) );
					$start_date = gmdate( strtotime( '-30 days', $end_date ) );
					if ( ! empty( $start_date ) && ! empty( $end_date ) ) {
						$period = new DatePeriod(
							new DateTime( gmdate( 'Y-m-d', $start_date ) ),
							new DateInterval( 'P1D' ),
							new DateTime( gmdate( 'Y-m-d', $end_date ) )
						);
						if ( ! empty( $period ) ) {
							$all_dates = array();
							foreach ( $period as $key => $value ) {
								if ( is_object( $value ) && is_callable( array( $value, 'format' ) ) ) {
									$all_dates[] = $value->format( 'Y-m-d' );
								}
							}
							if ( ! empty( $all_dates ) ) {
								$filled_chart_data = array_fill_keys( $all_dates, 0 );
								if ( ! empty( $filled_chart_data ) ) {
									$chart_data = array_merge( $filled_chart_data, $chart_data );
									ksort( $chart_data, SORT_NATURAL );
								}
							}
						}
					}
				}
			}
			return $chart_data;
		}

		/**
		 * Fetch store kpi's
		 *
		 * @return array $kpis
		 */
		public function get_store_kpis() {
			global $wpdb;

			$store_kpis = get_transient( 'sa_om_store_kpis' );

			if ( empty( $store_kpis ) ) {
				$limit = 500;

				$store_kpis['aov'] = $wpdb->get_var( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT IFNULL(ROUND(AVG(pm.meta_value), 2), 0) AS avg_order_total
															FROM {$wpdb->prefix}postmeta AS pm
																JOIN (SELECT ID
																		FROM {$wpdb->prefix}posts
																		WHERE post_type = 'shop_order'
																			AND post_status IN ( 'wc-processing', 'wc-completed' )
																		ORDER BY ID DESC
																		LIMIT %d) AS orders
																ON (orders.ID = pm.post_id
																	AND pm.meta_key = %s
																	AND pm.meta_value > %d)",
						$limit,
						'_order_total',
						0
					)
				);

				$store_kpis['aiq'] = $wpdb->get_var( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT IFNULL(ROUND(AVG(woim.meta_value)), 0) AS avg_order_total
															FROM {$wpdb->prefix}woocommerce_order_items AS woi
																JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS woim
																	ON (woi.order_item_id = woim.order_item_id
																		AND woim.meta_key = %s
																		AND woim.meta_value > %d)
																JOIN (SELECT ID
																		FROM {$wpdb->prefix}posts
																		WHERE post_type = 'shop_order'
																			AND post_status IN ( 'wc-processing', 'wc-completed' )
																		ORDER BY ID DESC
																		LIMIT %d) AS orders
																ON (orders.ID = woi.order_id
																	AND woi.order_item_type = %s)",
						'_qty',
						0,
						$limit,
						'line_item'
					)
				);

				$ltv_results        = $wpdb->get_row( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT IFNULL(ROUND(AVG(order_total), 2), 0) AS avg_order_total,
																			IFNULL(ROUND(AVG(order_count)), 0) AS avg_orders
																	FROM
																		(SELECT IFNULL(ROUND(SUM(pm.meta_value), 2), 0) AS order_total,
																				IFNULL(ROUND(COUNT( DISTINCT pm.post_id), 2), 0) AS order_count
																			FROM {$wpdb->prefix}postmeta AS pm
																				JOIN {$wpdb->prefix}postmeta AS pm_customer
																					ON(pm_customer.post_id = pm.post_id
																						AND pm_customer.meta_key = %s
																						AND pm_customer.meta_value > %d)
																				JOIN (SELECT ID
																						FROM {$wpdb->prefix}posts
																						WHERE post_type = 'shop_order'
																							AND post_status IN ( 'wc-processing', 'wc-completed' )
																						ORDER BY ID DESC
																						LIMIT %d) AS orders
																				ON (orders.ID = pm.post_id
																					AND pm.meta_key = %s
																					AND pm.meta_value > %d)
																			GROUP by pm_customer.meta_value) AS derived",
						'_customer_user',
						0,
						$limit,
						'_order_total',
						0
					),
					'ARRAY_A'
				);
				$store_kpis['altv'] = ( ! empty( $ltv_results['avg_orders'] ) ) ? round( ( $ltv_results['avg_order_total'] / $ltv_results['avg_orders'] ), 0 ) : 0;

				set_transient( 'sa_om_store_kpis', $store_kpis, WEEK_IN_SECONDS );
			}

			return $store_kpis;
		}

		/**
		 * Insert product/category title in all campaigns and send response
		 *
		 * @param array $params The request params.
		 */
		public function generate_campaigns( $params = array() ) {

			$resp = array( 'ACK' => 'Failed' );

			if ( empty( SA_Offermative::$access_token ) ) {
				wp_send_json( $resp );
			}

			$url = SA_Offermative::$generation_api_url . '/campaign';

			$om_controller = OM_Ajax_Controller::get_instance();

			$sa_om_settings = get_option( 'sa_om_generation_settings', array() );

			$restrictions = ( ! empty( $params['restrictions'] ) ) ? json_decode( $params['restrictions'], true ) : array();

			extract( $restrictions, EXTR_PREFIX_ALL, 'restrictions' );
			$generate_offer_types = apply_filters( 'sa_om_generate_offer_types', ( ( ! empty( $restrictions_offerTypes ) ) ? $restrictions_offerTypes : array() ) );

			// Filter for excluded products & product categories.
			$excluded_products   = array_column( $sa_om_settings, 'product' );
			$excluded_categories = array_column( $sa_om_settings, 'category' );
			$exclude_params      = array(
				'excluded_products'   => ( ( ! empty( $excluded_products ) && ( ! empty( $excluded_products[0] ) ) ) ? array_filter( $excluded_products[0] ) : array() ),
				'excluded_categories' => ( ( ! empty( $excluded_categories ) && ( ! empty( $excluded_categories[0] ) ) ) ? array_filter( $excluded_categories[0] ) : array() ),
			);

			if ( empty( $restrictions_product ) ) {
				$products_for_generating_campaigns = $this->get_products_for_generating_campaigns( $exclude_params );
			} else {
				$products_for_generating_campaigns = om_get_product_titles( $restrictions_product );
			}

			if ( empty( $restrictions_category ) ) {
				$categories_for_generating_campaigns = $this->get_product_categories_for_generating_campaigns( $exclude_params );
			} else {
				$categories_for_generating_campaigns = om_get_category_data( $restrictions_category );
			}

			$discount_params     = array();
			$message_copy_params = array();
			foreach ( $sa_om_settings as $key => $value ) {
				if ( 'maxDiscount' === $key ) {
					$discount_params = $value;
				}
				if ( 'messageCopyParams' === $key ) {
					$message_copy_params = $value;
				}
			}

			$args = array(
				'body'    => wp_json_encode(
					array(
						'products'                  => array_keys( $products_for_generating_campaigns ),
						'productCategories'         => array_keys( $categories_for_generating_campaigns ),
						'messageCopyGenerateParams' => array_filter( $message_copy_params ),
						'maxDiscount'               => array_filter( $discount_params ),
						'offerTypesToGenerate'      => $generate_offer_types,
						'order'                     => $this->get_store_kpis(),
					)
				),
				'headers' => array(
					'Content-Type' => 'application/json',
					'token'        => SA_Offermative::$access_token,
				),
			);

			$response = wp_remote_post( esc_url_raw( $url ), $args );

			if ( is_wp_error( $response ) ) {
				wp_send_json( $resp );
			}

			$response_body = json_decode( $response['body'], true );
			$data          = $response_body['data'];

			if ( ! empty( $data['campaigns'] ) ) {
				foreach ( $data['campaigns'] as $c => $campaign ) {
					// date formatting.
					$data['campaigns'][ $c ]['startAt'] = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $campaign['startAt'] ) );
					$data['campaigns'][ $c ]['endAt']   = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $campaign['endAt'] ) );
					// Move message copy content from campaign level to individual message level.
					foreach ( $campaign['messages'] as $m => $message ) {
						$data['campaigns'][ $c ]['messages'][ $m ]['content'] = $campaign['content'];
					}
					unset( $campaign['content'] );
				}
			}

			$max_active_campaigns_count = ( ! empty( $data['max_active_campaigns_count'] ) ) ? $data['max_active_campaigns_count'] : 0;
			if ( ! empty( $max_active_campaigns_count ) ) {
				set_transient( 'sa_om_max_active_campaigns_count', $max_active_campaigns_count, DAY_IN_SECONDS );
			}

			wp_send_json(
				array(
					'ACK'                => 'Success',
					'generatedCampaigns' => $data,
					'params'             => array(
						'products'                   => $om_controller->get_offered_product_data( array_keys( $products_for_generating_campaigns ), false ),
						'productCategories'          => om_get_category_data( array_keys( $categories_for_generating_campaigns ), true ),
						'active_campaigns_count'     => om_get_active_campaigns_count(),
						'max_active_campaigns_count' => ( ! empty( $max_active_campaigns_count ) ) ? $max_active_campaigns_count : om_get_max_active_campaigns_count(),
					),
				)
			);
		}

		/**
		 * Get products for generating campaigns
		 *
		 * @param array $params The request params.
		 * @return array $product_ids_names List of generation product ids with its meta
		 */
		public function get_products_for_generating_campaigns( $params = array() ) {
			global $wpdb;

			$product_ids = array();
			$max_count   = 70;

			if ( ! empty( $params['excluded_products'] ) ) {
				$option_nm = 'sa_om_exclude_product_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $params['excluded_products'] ), 'no' );
				$wpdb->query( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"INSERT INTO {$wpdb->prefix}om_exclude_products_temp(product_id)
							SELECT option_value
							FROM {$wpdb->prefix}options
							WHERE option_name = %s",
						$option_nm
					)
				);
				delete_option( $option_nm );
			}
			if ( ! empty( $params['excluded_categories'] ) ) {
				$option_nm = 'sa_om_exclude_category_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $params['excluded_categories'] ), 'no' );

				$wpdb->query( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"INSERT INTO {$wpdb->prefix}om_exclude_products_temp(product_id)
							SELECT DISTINCT(tr.object_id) AS product_id
								FROM {$wpdb->prefix}term_relationships AS tr
									JOIN {$wpdb->prefix}term_taxonomy AS tt
										ON (tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = %s)
									JOIN {$wpdb->prefix}terms AS t
										ON (t.term_id = tt.term_id AND tt.taxonomy = %s)
								WHERE FIND_IN_SET ( t.term_id, ( SELECT option_value
																	FROM {$wpdb->prefix}options
																	WHERE option_name = %s ) )",
						'product_cat',
						'product_cat',
						$option_nm
					)
				);
				delete_option( $option_nm );
			}

			// ===================================
			// for '_price' != 0 -- causing issue in variable subscription parent products having any 1 vaiation price set as 0
			// OR (CASE WHEN pm.meta_key = %s THEN pm.meta_value = '' END)
			// OR (CASE WHEN pm.meta_key = %s THEN pm.meta_value <= %d END)
			// OR (CASE WHEN pm.meta_key = %s THEN pm.meta_value IS NULL END)
			// ===================================

			$wpdb->query( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"INSERT INTO {$wpdb->prefix}om_exclude_products_temp(product_id)
						SELECT DISTINCT(pm.post_id) AS product_id
							FROM {$wpdb->posts} AS p
								JOIN {$wpdb->postmeta} AS pm
									ON (p.ID = pm.post_id)
							WHERE pm.meta_key IN (%s,%s)
								AND p.post_type = %s
								AND p.post_status = %s
								AND p.post_parent = %d
								AND (
										(CASE WHEN pm.meta_key = %s THEN pm.meta_value > 0 END)
									)",
					'_price',
					'_sale_price',
					'product',
					'publish',
					0,
					'_sale_price'
				)
			);
			$results = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"SELECT p.ID as product_id,
										p.post_title as product_name
									FROM {$wpdb->posts} AS p
										JOIN {$wpdb->postmeta} AS pm
											ON (p.ID = pm.post_id AND pm.meta_key = %s)
									WHERE p.post_type = %s
										AND p.post_status = %s
										AND p.post_parent = %d
										AND p.ID NOT IN (
											SELECT DISTINCT(product_id)
												FROM {$wpdb->prefix}om_exclude_products_temp
										)
									GROUP BY p.ID
									ORDER BY pm.meta_value DESC, p.ID DESC
									LIMIT %d",
					'total_sales',
					'product',
					'publish',
					0,
					$max_count
				),
				ARRAY_A
			);

			$wpdb->query( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"DELETE FROM {$wpdb->prefix}om_exclude_products_temp
						WHERE %d",
					1
				)
			);

			$product_ids_names = array();
			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {
					$product_ids_names[ intval( $result['product_id'] ) ] = $result['product_name'];
				}
			}

			return $product_ids_names;
		}

		/**
		 * Get categories for generating campaigns
		 *
		 * @param array $params The request params.
		 * @return array $product_ids_names List of generation product category ids with its meta
		 */
		public function get_product_categories_for_generating_campaigns( $params = array() ) {
			global $wpdb;

			$max_count = 25;

			if ( ! empty( $params['excluded_categories'] ) ) {
				$option_nm = 'sa_om_exclude_category_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $params['excluded_categories'] ), 'no' );

				$results = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT t.term_id AS category_id,
								t.name AS category_name
							FROM {$wpdb->prefix}term_taxonomy AS tt
								JOIN {$wpdb->prefix}terms AS t
									ON(t.term_id = tt.term_id
									AND tt.taxonomy = %s
									AND tt.count > 0)
							WHERE NOT FIND_IN_SET ( t.term_id, ( SELECT option_value
																	FROM {$wpdb->prefix}options
																	WHERE option_name = %s ) )
							GROUP BY t.term_id
							ORDER BY t.term_id DESC
							LIMIT %d
						",
						'product_cat',
						$option_nm,
						$max_count
					),
					ARRAY_A
				);
				delete_option( $option_nm );
			} else {
				$results = $wpdb->get_results(  // phpcs:ignore
								$wpdb->prepare( // phpcs:ignore
									"SELECT t.term_id AS category_id,
											t.name AS category_name
										FROM {$wpdb->prefix}term_taxonomy AS tt
											JOIN {$wpdb->prefix}terms AS t
												ON(t.term_id = tt.term_id
												AND tt.taxonomy = %s
												AND tt.count > 0)
										GROUP BY t.term_id
										ORDER BY t.term_id DESC
										LIMIT %d
									",
									'product_cat',
									$max_count
								),
					ARRAY_A
				);
			}

			$category_ids_names = array();
			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {
					$category_ids_names[ intval( $result['category_id'] ) ] = $result['category_name'];
				}
			}

			return $category_ids_names;
		}

		/**
		 * Set whether the campaign is enabled or not
		 *
		 * @throws RuntimeException When update query fails.
		 * @param array $params The request params.
		 */
		public function set_enabled( $params = array() ) {
			global $wpdb;

			$response     = array( 'ACK' => 'Failed' );
			$campaigns    = array();
			$campaign_ids = ( ! empty( $params['campaignIds'] ) ? json_decode( $params['campaignIds'], true ) : array() );
			$status       = ( ! empty( $params['enable'] ) ? 'enabled' : 'disabled' );
			$current_time = time();

			if ( ! empty( $campaign_ids ) ) {
				if ( is_array( $campaign_ids ) ) {
					$option_nm = 'sa_om_enabled_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );

					$result = $wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET status = %s,
									end_date = (CASE WHEN end_date < %d THEN %d ELSE end_date END),
									modified_date = %d
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$status,
							$current_time,
							$current_time + ( 14 * 86400 ),
							$current_time,
							$option_nm
						)
					);
					delete_option( $option_nm );
				} else {
					$result = $wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET status = %s,
									end_date = (CASE WHEN end_date < %d THEN %d ELSE end_date END),
									modified_date = %d
								WHERE id = %d",
							$status,
							$current_time,
							$current_time + ( 14 * 86400 ),
							$current_time,
							$campaign_ids
						)
					);
				}

				if ( false === $result ) {
					throw new RuntimeException( __( 'Unable to enable campaigns. Database error.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ) );
				}
				$campaigns = $this->get_campaign_list( $params, false ); // TODO: fetch only updated campaigns & return the same.
				$response  = array(
					'ACK'       => 'Success',
					'campaigns' => $campaigns,
				);
			}

			wp_send_json( $response );
		}

		/**
		 * Function to delete campaigns
		 *
		 * @throws RuntimeException When update query fails.
		 * @param array $params The request params.
		 */
		public function delete_campaigns( $params ) {
			global $wpdb;

			$response     = array( 'ACK' => 'Failed' );
			$campaigns    = array();
			$campaign_ids = ( ! empty( $params['campaignIds'] ) ? json_decode( $params['campaignIds'], true ) : array() );
			if ( ! empty( $campaign_ids ) ) {
				if ( is_array( $campaign_ids ) ) {
					$option_nm = 'sa_om_delete_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );

					$result = $wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET status = 'trash',
								modified_date = %d
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							time(),
							$option_nm
						)
					);
					delete_option( $option_nm );
				} else {
					$result = $wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET status = 'trash'
								WHERE id = %d",
							$campaign_ids
						)
					);
				}

				if ( false === $result ) {
					throw new RuntimeException( __( 'Unable to delete campaigns. Database error.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ) );
				}
				$campaigns = $this->get_campaign_list( $params, false ); // TODO: fetch only updated campaigns & return the same.
				$response  = array(
					'ACK'       => 'Success',
					'campaigns' => $campaigns,
				);
			}

			wp_send_json( $response );
		}

		/**
		 * Function to extend end_date of campaigns by days
		 *
		 * @throws RuntimeException When update query fails.
		 * @param array $params The request params.
		 */
		public function extend_campaigns( $params ) {
			global $wpdb;

			$response               = array( 'ACK' => 'Failed' );
			$campaigns              = array();
			$campaign_ids           = ( ! empty( $params['campaignIds'] ) ? json_decode( $params['campaignIds'], true ) : array() );
			$extend_days_in_seconds = ( ! empty( $params['days'] ) ? intval( $params['days'] ) : 14 ) * 86400;
			if ( ! empty( $campaign_ids ) ) {
				if ( is_array( $campaign_ids ) ) {
					$option_nm = 'sa_om_extend_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );

					$result = $wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET end_date = end_date + %d,
                    				status = 'enabled',
									modified_date = %d
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$extend_days_in_seconds,
							time(),
							$option_nm
						)
					);
					delete_option( $option_nm );
				} else {
					$result = $wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET end_date = end_date + %d,
                					status = 'enabled'
								WHERE id = %d",
							$extend_days_in_seconds,
							$campaign_ids
						)
					);
				}

				if ( false === $result ) {
					throw new RuntimeException( __( 'Unable to extend campaigns. Database error.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ) );
				}
				$campaigns = $this->get_campaign_list( $params, false ); // TODO: fetch only updated campaigns & return the same.
				$response  = array(
					'ACK'       => 'Success',
					'campaigns' => $campaigns,
				);
			}

			wp_send_json( $response );
		}

		/**
		 * Function to insert campaigns in the om_campaigns table
		 *
		 * @throws RuntimeException When insert query fails.
		 * @param array $params The request params.
		 */
		public function insert_campaigns( $params = array() ) {
			global $wpdb;

			$response = array( 'ACK' => 'Failed' );

			if ( ! empty( $params['campaigns'] ) ) {
				$om_campaigns_data = json_decode( $params['campaigns'], true );
				if ( ! empty( $om_campaigns_data ) ) {
					$campaigns    = array();
					$inserted_ids = array();
					foreach ( $om_campaigns_data['campaigns'] as $key => $value ) {

						$campaign_start_date = ( ! empty( $value['startAt'] ) ) ? strtotime( get_gmt_from_date( $value['startAt'] ) ) : time();
						$campaign_end_date   = ( ! empty( $value['endAt'] ) ) ? strtotime( get_gmt_from_date( $value['endAt'] ) ) : time();

						$campaign_status       = ( ! empty( $value['enabled'] ) ) ? 'enabled' : 'disabled';
						$campaign_created_date = ( ! empty( $value['createdAt'] ) ) ? $value['createdAt'] : $campaign_start_date;
						$generated_id          = ( ! empty( $value['generatedId'] ) ) ? $value['generatedId'] : '';
						$params                = array();
						$params['offer']       = ( ! empty( $value['offer'] ) ) ? $value['offer'] : array();
						$params['messages']    = ( ! empty( $value['messages'] ) ) ? $value['messages'] : array();
						$campaign_params       = wp_json_encode( $params );

						$result       = $wpdb->query( // phpcs:ignore
											$wpdb->prepare( // phpcs:ignore
												"INSERT INTO {$wpdb->prefix}om_campaigns ( status, created_date, modified_date, start_date, end_date, generated_id, params )
													VALUES (%s, %d, %d, %d, %d, %d, %s)",
												$campaign_status,
												intval( $campaign_created_date ),
												intval( $campaign_created_date ),
												intval( $campaign_start_date ),
												intval( $campaign_end_date ),
												intval( $generated_id ),
												$campaign_params
											)
						);
						if ( false === $result ) {
							throw new RuntimeException( __( 'Unable to insert campaigns. Database error.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ) );
						} else {
							$inserted_ids[] = $result;
						}
					}
					if ( ! empty( $inserted_ids ) ) {
						$campaigns = $this->get_campaign_list( $params, false );
						$response  = array(
							'ACK'       => 'Success',
							'campaigns' => $campaigns,
						);
					}
				}
			}

			wp_send_json( $response );
		}

		/**
		 * Fetch settings
		 *
		 * @param array $params The request params.
		 */
		public function get_settings( $params = array() ) {
			$admin_details = array( 'adminDetails' => get_option( 'sa_om_admin_details', array() ) );
			$om_settings   = get_option( 'sa_om_generation_settings', array() );
			foreach ( $om_settings as $key => $value ) {
				if ( 'excludedIds' === $key ) {
					foreach ( $value as $option => $id ) {
						if ( 'product' === $option ) {
							$om_settings[ $key ][ $option ] = om_get_product_titles( $id );
						}
						if ( 'category' === $option ) {
							$om_settings[ $key ][ $option ] = om_get_category_data( $id );
						}
					}
				}
			}

			// TODO: returning empty array on activation and not redirecting to settings.
			if ( ! empty( $admin_details ) && ! empty( $om_settings ) ) {
				return array_merge( $om_settings, $admin_details );
			}
			return array();
		}

		/**
		 * Update settings
		 *
		 * @param array $params The request params.
		 */
		public function update_settings( $params = array() ) {

			$response = array( 'ACK' => 'Failure' );

			$settings = ( ! empty( $params['settings'] ) ) ? json_decode( $params['settings'], true ) : array();

			$admin_details = array();
			if ( ! empty( $settings['adminDetails'] ) ) {
				$admin_details = $settings['adminDetails'];
				unset( $settings['adminDetails'] );
			}

			update_option( 'sa_om_generation_settings', $settings, 'no' );
			update_option( 'sa_om_admin_details', $admin_details, 'no' );

			$response = array(
				'ACK'      => 'Success',
				'settings' => $this->get_settings(),
			);

			wp_send_json( $response );
		}

		/**
		 * Log in or sign up for an account
		 *
		 * @param array $params The request params.
		 */
		public function verify_create_account( $params = array() ) {

			$response = array( 'ACK' => 'Failure' );

			$details = ( ! empty( $params['accountDetails'] ) ) ? json_decode( $params['accountDetails'], true ) : array();
			$url     = SA_Offermative::$api_url . '/users';

			if ( empty( $params['isNewUser'] ) || ( ! empty( $params['isNewUser'] ) && 'false' === $params['isNewUser'] ) ) {
				$details['isOnboarding'] = true;
				$url                    .= '/validate';
			} else {
				$url .= '/register';
			}

			$data = array(
				'method'  => 'POST',
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => array(
					'client_id'     => SA_Offermative::$client_id,
					'client_secret' => SA_Offermative::$client_secret,
					'domain'        => get_bloginfo( 'wpurl' ),
				),
			);

			$data['body'] = wp_json_encode( array_merge( $data['body'], $details ) );
			$response     = wp_remote_post( esc_url_raw( $url ), $data );

			if ( is_wp_error( $response ) ) {
				wp_send_json( array( 'ACK' => 'Failed' ) );
			}

			$response_body = json_decode( $response['body'], true );

			if ( ! empty( $response_body['ACK'] ) && 'Success' === $response_body['ACK'] ) {
				if ( ! empty( $response_body['token'] ) ) {
					set_transient( 'sa_om_token', $response_body['token'], MONTH_IN_SECONDS * 3 );
					SA_Offermative::$access_token = $response_body['token'];
				}

				update_option(
					'sa_om_admin_details',
					array(
						'name'  => ( ( ! empty( $response_body['name'] ) ) ? $response_body['name'] : $details['name'] ),
						'email' => $details['email'],
					),
					'no'
				);

				$response_body['isSettingsEmpty'] = ( empty( $this->get_settings() ) ? 1 : 0 );
			} else {
				delete_transient( 'sa_om_token' );
				SA_Offermative::$access_token = '';
			}

			wp_send_json( $response_body );
		}

		/**
		 * Update campaigns params
		 *
		 * @param array $params The request params.
		 */
		public function update_campaign( $params = array() ) {

			$response = array( 'ACK' => 'Failure' );

			// For now it will update only campaign params.
			// TODO: Handle updation of other data of the campaign.

			$id              = ( ! empty( $params['campaignId'] ) ) ? $params['campaignId'] : 0;
			$campaign_params = ( ! empty( $params['campaignParams'] ) ) ? json_decode( $params['campaignParams'], true ) : array(); // TODO: Improve the same hack.

			if ( ! empty( $id ) && ! empty( $campaign_params ) ) {
				// Code for converting the dates to UTC.
				$campaign_start_date = ( ! empty( $campaign_params['startAt'] ) ) ? strtotime( get_gmt_from_date( $campaign_params['startAt'] ) ) : time();
				$campaign_end_date   = ( ! empty( $campaign_params['endAt'] ) ) ? strtotime( get_gmt_from_date( $campaign_params['endAt'] ) ) : time();

				if ( ! empty( $campaign_params['startAt'] ) ) {
					unset( $campaign_params['startAt'] );
				}
				if ( ! empty( $campaign_params['endAt'] ) ) {
					unset( $campaign_params['endAt'] );
				}

				$response = $this->set_params(
					$id,
					array(
						'start_date' => $campaign_start_date,
						'end_date'   => $campaign_end_date,
						'params'     => wp_json_encode( $campaign_params ),
					)
				);
			}

			wp_send_json( $response );
		}

		/**
		 * Set additional campaign data
		 *
		 * @param integer|array $id Campaign id.
		 * @param array         $data Campaign data.
		 */
		public function set_params( $id = 0, $data = array() ) {
			global $wpdb;

			$response = array( 'ACK' => 'Failure' );

			if ( ! empty( $id ) && ! empty( $data ) ) {

				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_extend_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET params = %s,
									start_date = %d,
									end_date = %d,
									modified_date = %d
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$data['params'],
							$data['start_date'],
							$data['end_date'],
							time(),
							$option_nm
						)
					);
					delete_option( $option_nm );
				} else {
					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET params = %s,
								start_date = %d,
								end_date = %d,
								modified_date = %d
								WHERE id = %d",
							$data['params'],
							$data['start_date'],
							$data['end_date'],
							time(),
							$id
						)
					);
				}

				$response = array( 'ACK' => 'Success' );
			}

			return $response;
		}

		/**
		 * Get list of campaigns
		 *
		 * @param array $params The request params.
		 * @param bool  $is_ajax Flag for detecting if function is called from within an ajax request.
		 * @return array $response Campaign list
		 */
		public function get_campaign_list( $params, $is_ajax = true ) {
			global $wpdb;

			$campaigns            = array();
			$offered_category_ids = array();
			$offered_product_ids  = array();
			$total_campaigns      = 0;
			$date_format          = 'Y M d, h:i A';

			if ( isset( $params['page'] ) && absint( $params['page'] ) > 1 ) {
				$this->start_offset = ( $params['page'] - 1 ) * $this->batch_limit;
			}

			if ( ! empty( $params['filter'] ) && 'all' !== $params['filter'] ) {

				$gmt_time  = strtotime( gmdate( 'Y-m-d' ) . '23:59:59' );
				$option_nm = 'sa_om_filtered_campaign_ids_' . om_get_unique_id();
				$results   = array();

				$ids = $wpdb->get_col( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT DISTINCT id
										FROM {$wpdb->prefix}om_campaigns
										WHERE status = %s",
						$params['filter']
					)
				);

				if ( ! empty( $ids ) ) {

					if ( absint( $params['page'] ) === 1 ) {
						$total_campaigns = count( $ids );
					}

					update_option( $option_nm, implode( ',', $ids ), 'no' );
					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
																status,
																(CASE WHEN status = 'enabled' THEN 1 ELSE 0 END) as is_active,
																FROM_UNIXTIME(start_date) as startAt,
																FROM_UNIXTIME(end_date) as endAt,
																end_date as endAtDefault,
																start_date as startAtDefault,
																params
															FROM {$wpdb->prefix}om_campaigns
															WHERE status != 'trash'
																AND FIND_IN_SET ( id, ( SELECT option_value
																								FROM {$wpdb->prefix}options
																								WHERE option_name = %s ) )
															ORDER BY is_active DESC, start_date DESC
															LIMIT %d,%d",
							$option_nm,
							$this->start_offset,
							$this->batch_limit
						),
						ARRAY_A
					);
					delete_option( $option_nm );
				}
			} else {
				$results = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT id,
															status,
															(CASE WHEN status = 'enabled' THEN 1 ELSE 0 END) as is_active,
															FROM_UNIXTIME(start_date) as startAt,
															FROM_UNIXTIME(end_date) as endAt,
															end_date as endAtDefault,
															start_date as startAtDefault,
															params
														FROM {$wpdb->prefix}om_campaigns
														WHERE status != 'trash'
														ORDER BY is_active DESC, start_date DESC
														LIMIT %d,%d",
						$this->start_offset,
						$this->batch_limit
					),
					ARRAY_A
				);

				if ( ! empty( $params['page'] ) && absint( $params['page'] ) === 1 ) {
					$total_campaigns = $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT COUNT(*)
																FROM {$wpdb->prefix}om_campaigns
																WHERE status != %s",
							'trash'
						)
					);
				}
			}

			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {
					$result['id'] = intval( $result['id'] );
					$_params      = ( ! empty( $result['params'] ) ) ? json_decode( $result['params'], true ) : array();
					unset( $result['params'] );

					$result['title']    = ( ! empty( $_params['title'] ) ) ? $_params['title'] : '';
					$result['offer']    = ( ! empty( $_params['offer'] ) ) ? $_params['offer'] : json_decode( wp_json_encode( array() ) );
					$result['messages'] = ( ! empty( $_params['messages'] ) ) ? $_params['messages'] : json_decode( wp_json_encode( array() ) );

					if ( ! empty( $result['startAtDefault'] ) ) {
						$date                       = gmdate( 'Y-m-d H:i:s', $result['startAtDefault'] );
						$result['startAtFormatted'] = get_date_from_gmt( $date, $date_format );
						$result['startAt']          = get_date_from_gmt( $date );
					}

					if ( ! empty( $result['endAtDefault'] ) ) {
						$date                     = gmdate( 'Y-m-d H:i:s', $result['endAtDefault'] );
						$result['endAtFormatted'] = get_date_from_gmt( $date, $date_format );
						$result['endAt']          = get_date_from_gmt( $date );
					}

					if ( ! empty( $result['offer']['product'] ) ) {
						$type = ( ! empty( $result['offer']['product']['type'] ) ) ? $result['offer']['product']['type'] : '';
						if ( ! empty( $type ) ) {
							if ( ! empty( $result['offer']['product']['id'] ) ) {
								if ( 'category' === $type ) {
									$offered_category_ids[] = $result['offer']['product']['id'];
								} elseif ( 'product' === $type ) {
									$offered_product_ids[] = $result['offer']['product']['id'];
								}
							}
						}
					}

					$result['kpi'] = array(
						'views'          => 0,
						'earnings'       => 0,
						'orderCount'     => 0,
						'orderItemCount' => 0,
					);

					$campaigns[ $result['id'] ] = $result;
				}

				$campaign_ids  = array_keys( $campaigns );
				$campaign_kpis = $this->get_campaigns_kpis( $campaign_ids );

				if ( ! empty( $campaign_kpis ) ) {
					foreach ( $campaign_kpis as $result ) {
						$id = ( ! empty( $result['id'] ) ) ? intval( $result['id'] ) : 0;
						if ( ! empty( $campaigns[ $id ] ) ) {
							$campaigns[ $id ]['kpi'] = array(
								'views'          => ( ( ! empty( $result['views'] ) ) ? intval( $result['views'] ) : 0 ),
								'earnings'       => ( ( ! empty( $result['earnings'] ) ) ? intval( $result['earnings'] ) : 0 ),
								'orderCount'     => ( ( ! empty( $result['orderCount'] ) ) ? intval( $result['orderCount'] ) : 0 ),
								'orderItemCount' => ( ( ! empty( $result['orderItemCount'] ) ) ? intval( $result['orderItemCount'] ) : 0 ),
							);
						}
					}
				}
			}

			// Code for getting the campaign params data.
			$campaign_params = array(
				'products'          => array(),
				'productCategories' => array(),
			);

			if ( ! empty( $params['page'] ) && absint( $params['page'] ) === 1 ) {
				$campaign_params['totalCampaigns'] = absint( $total_campaigns );
			}

			if ( ! empty( $offered_category_ids ) ) {
				$campaign_params['productCategories'] = om_get_category_data( $offered_category_ids, true );
			}

			if ( ! empty( $offered_product_ids ) ) {
				$om_controller               = OM_Ajax_Controller::get_instance();
				$campaign_params['products'] = $om_controller->get_offered_product_data( $offered_product_ids, false );
			}

			$response = array(
				'campaigns' => array_values( $campaigns ),
				'params'    => $campaign_params,
			);

			if ( ! empty( $is_ajax ) ) {
				wp_send_json( $response );
			}

			return $response;
		}

		/**
		 * Get campaign detail
		 *
		 * @param array $rules Campaign offer rules.
		 * @return array $ids Product Category ids of campaign rules
		 */
		public function get_product_category_ids_from_rules( $rules ) {
			$ids = array(
				'product'  => array(),
				'category' => array(),
			);

			if ( ! empty( $rules['rules'] ) && is_array( $rules['rules'] ) ) {
				foreach ( $rules['rules'] as $rule ) {
					if ( ! empty( $rule['rules'] ) ) {
						$ids = om_recurrsive_array_merge( $ids, $this->get_product_category_ids_from_rules( $rule ) );
					} else {
						// code for fetching the ids.
						$type = '';
						if ( stripos( $rule['type'], 'category' ) !== false ) {
							$type = 'category';
						} elseif ( stripos( $rule['type'], 'product' ) !== false ) {
							$type = 'product';
						}
						if ( ! empty( $type ) ) {
							$value        = ( ! is_array( $rule['value'] ) ) ? array( $rule['value'] ) : $rule['value'];
							$ids[ $type ] = array_unique( array_merge( $ids[ $type ], $value ) );
						}
					}
				}
			}
			return $ids;
		}

		/**
		 * Get campaign detail
		 *
		 * @param array $params The request params.
		 */
		public function get_campaign_details( $params = array() ) {

			$ids             = array(
				'product'  => array(),
				'category' => array(),
			);
			$campaign_params = array(
				'products'          => array(),
				'productCategories' => array(),
			);

			$current_offer = om_sanitize_field( json_decode( $params['offer'], true ) );

			$product_ids  = array();
			$category_ids = array();

			// Code for fetching product & category ids.
			if ( ! empty( $current_offer['product'] ) ) {
				if ( 'product' === $current_offer['product']['type'] ) {
					$ids['product'][] = $current_offer['product']['id'];
				} elseif ( 'category' === $current_offer['product']['type'] ) {
					$ids['category'][] = $current_offer['product']['id'];
				}
			}

			// Code to get product & category ids from rules.
			if ( ! empty( $current_offer['rules'] ) && ! empty( $current_offer['rules'][0] ) ) {
				$ids = om_recurrsive_array_merge( $ids, $this->get_product_category_ids_from_rules( $current_offer['rules'][0] ) );
			}

			if ( ! empty( $ids['category'] ) ) {
				$campaign_params['productCategories'] = om_get_category_data( $ids['category'], true );
			}

			if ( ! empty( $ids['product'] ) ) {
				$om_controller               = OM_Ajax_Controller::get_instance();
				$campaign_params['products'] = $om_controller->get_offered_product_data( $ids['product'], false );
			}

			wp_send_json(
				array(
					'params'    => $campaign_params,
					'analytics' => $this->get_campaign_analytics( $params ),
				)
			);
		}

		/**
		 * Get campaign analytics
		 *
		 * @param array $params The request params.
		 * @return array $campaign_analytics Analytics data of the campaign
		 */
		public function get_campaign_analytics( $params = array() ) {
			global $wpdb;

			$campaign_analytics = array(
				'recentOrders' => array(),
				'messagesKPI'  => array(),
			);
			$campaign_id        = ( ! empty( $params['campaignId'] ) ) ? array( $params['campaignId'] ) : 0;

			if ( ! empty( $campaign_id ) ) {
				$campaign_message_kpis = $this->get_campaigns_kpis( $campaign_id, true );
				if ( ! empty( $campaign_message_kpis ) ) {
					foreach ( $campaign_message_kpis as $result ) {
						$mid = ( ! empty( $result['message_id'] ) ) ? intval( $result['message_id'] ) : 0;
						if ( ! empty( $mid ) ) {
							$campaign_analytics['messagesKPI'][ $mid ] = array(
								'views'          => ( ( ! empty( $result['views'] ) ) ? intval( $result['views'] ) : 0 ),
								'earnings'       => ( ( ! empty( $result['earnings'] ) ) ? intval( $result['earnings'] ) : 0 ),
								'orderCount'     => ( ( ! empty( $result['orderCount'] ) ) ? intval( $result['orderCount'] ) : 0 ),
								'orderItemCount' => ( ( ! empty( $result['orderItemCount'] ) ) ? intval( $result['orderItemCount'] ) : 0 ),
							);
						}
					}
				}
				$campaign_analytics['recentOrders'] = $this->get_recent_orders( $campaign_id );

				if ( 1 === count( $campaign_id ) ) {
					$chart_data                      = $this->get_chart_data( $campaign_id );
					$current_id                      = current( $campaign_id );
					$campaign_analytics['chartData'] = ( ( ! empty( $chart_data[ $current_id ] ) ) ? $this->auto_fill_empty_chart_data( $chart_data[ $current_id ] ) : array() );
				}
			}

			return $campaign_analytics;
		}

		/**
		 * Add prefix fro order statuses
		 *
		 * @param string $status Order status.
		 * @return string $status The prefixed order status
		 */
		public function prefix_order_status( $status = '' ) {
			if ( ! empty( $status ) ) {
				return 'wc-' . $status;
			}
			return $status;
		}

		/**
		 * Get recent orders
		 *
		 * @param array $campaign_ids List of campaign ids.
		 * @return array $results Recent Orders for supplied campaign ids
		 */
		public function get_recent_orders( $campaign_ids = array() ) {
			global $wpdb;

			$offset = 0;
			$limit  = 20;

			if ( ! empty( $campaign_ids ) ) {
				$limit     = 5;
				$option_nm = 'sa_om_enabled_campaign_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );

				$results = $wpdb->get_results( // phpcs:ignore
											$wpdb->prepare( // phpcs:ignore
												"SELECT IFNULL(order_id, 0) AS id,
														IFNULL(order_status, '') AS status,
														IFNULL(order_total, 0) * 100 AS total,
														IFNULL(CONCAT_WS(' ', first_name, last_name ), '') AS customer
													FROM (SELECT p.ID AS order_id,
															p.post_status AS order_status,
															MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS order_total,
															MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS first_name,
															MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS last_name
															FROM {$wpdb->posts} AS p
																JOIN {$wpdb->postmeta} AS pm
																	ON (p.ID = pm.post_id
																		AND p.post_type = 'shop_order'
																		AND pm.meta_key IN (%s,%s,%s))
															WHERE p.ID IN (SELECT o.order_id
																			FROM {$wpdb->prefix}om_tracking_orders AS o
																				JOIN {$wpdb->prefix}om_tracking_general AS g
																					ON (g.id = o.tracking_id
																						AND g.event = %s
																						AND o.is_valid_order = %d)
																			WHERE FIND_IN_SET ( g.id, ( SELECT option_value
																									FROM {$wpdb->prefix}options
																									WHERE option_name = %s ) ) )
															GROUP BY p.ID
														) AS temp
													ORDER BY order_id DESC
													LIMIT %d, %d",
												'_order_total',
												'_billing_first_name',
												'_billing_last_name',
												'_order_total',
												'_billing_first_name',
												'_billing_last_name',
												'convert',
												1,
												$option_nm,
												$offset,
												$limit
											),
					ARRAY_A
				);
				delete_option( $option_nm );
			} else {
				$results = $wpdb->get_results( // phpcs:ignore
											$wpdb->prepare( // phpcs:ignore
												"SELECT IFNULL(order_id, 0) AS id,
														IFNULL(order_status, '') AS status,
														IFNULL(order_total, 0) * 100 AS total,
														IFNULL(CONCAT_WS(' ', first_name, last_name ), '') AS customer
													FROM (SELECT p.ID AS order_id,
															p.post_status AS order_status,
															MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS order_total,
															MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS first_name,
															MAX(CASE WHEN pm.meta_key = %s THEN pm.meta_value END) AS last_name
															FROM {$wpdb->posts} AS p
																JOIN {$wpdb->postmeta} AS pm
																	ON (p.ID = pm.post_id
																		AND p.post_type = 'shop_order'
																		AND pm.meta_key IN (%s,%s,%s))
															WHERE p.ID IN (SELECT o.order_id
																			FROM {$wpdb->prefix}om_tracking_orders AS o
																				JOIN {$wpdb->prefix}om_tracking_general AS g
																					ON (g.id = o.tracking_id
																						AND g.event = %s
																						AND o.is_valid_order = %d) )
															GROUP BY p.ID
														) AS temp
													ORDER BY order_id DESC
													LIMIT %d, %d",
												'_order_total',
												'_billing_first_name',
												'_billing_last_name',
												'_order_total',
												'_billing_first_name',
												'_billing_last_name',
												'convert',
												1,
												$offset,
												$limit
											),
					ARRAY_A
				);
			}

			if ( ! empty( $results ) ) {
				$order_statuses = wc_get_order_statuses();
				foreach ( $results as $index => $result ) {
					$results[ $index ]['id']         = intval( $result['id'] );
					$results[ $index ]['editURL']    = get_admin_url( null, 'post.php?post=' . $results[ $index ]['id'] . '&action=edit' );
					$results[ $index ]['status']     = ( ! empty( $result['status'] ) && array_key_exists( $result['status'], $order_statuses ) ) ? $order_statuses[ $result['status'] ] : ucfirst( str_replace( array( 'wc-', '-', '_' ), ' ', $result['status'] ) );
					$results[ $index ]['orderTotal'] = intval( $result['total'] );
					$results[ $index ]['customer']   = $result['customer'];
				}
			}
			return $results;
		}

		/**
		 * Get total revenue
		 *
		 * @return float
		 */
		public function get_total_revenue() {
			global $wpdb;

			$paid_statuses     = wc_get_is_paid_statuses();
			$prefixed_statuses = array_map( array( $this, 'prefix_order_status' ), $paid_statuses );
			$option_nm         = 'sa_om_paid_order_statuses_' . om_get_unique_id();
			update_option( $option_nm, implode( ',', $prefixed_statuses ), 'no' );

			$total_revenue = $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT IFNULL(((SUM(pm.meta_value)) * 100), 0) AS total_revenue
								FROM {$wpdb->posts} AS p
									JOIN {$wpdb->postmeta} AS pm
										ON (p.ID = pm.post_id
												AND p.post_type = %s
												AND pm.meta_key = %s)
								WHERE FIND_IN_SET ( p.post_status, ( SELECT option_value
																		FROM {$wpdb->prefix}options
																		WHERE option_name = %s ) )
									AND p.post_date_gmt >= (SELECT FROM_UNIXTIME(MIN(start_date))
															FROM {$wpdb->prefix}om_campaigns)",
							'shop_order',
							'_order_total',
							$option_nm
						)
			);
			delete_option( $option_nm );

			$total_revenue = ( ! empty( $total_revenue ) ) ? $total_revenue : 0;
			return intval( $total_revenue );
		}

		/**
		 * Get campaigns KPI's
		 *
		 * @param array $campaign_ids List of campaign ids.
		 * @param bool  $group_by_msg_id Flag to dwtermine whether to group by 'message_id' or jnot.
		 * @return array $results KPI data for supplies campaign ids
		 */
		public function get_campaigns_kpis( $campaign_ids = array(), $group_by_msg_id = false ) {
			global $wpdb;

			if ( ! empty( $campaign_ids ) ) {
				$option_nm = 'sa_om_enabled_campaign_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );

				if ( ! empty( $group_by_msg_id ) ) {
					$results = $wpdb->get_results( // phpcs:ignore
									$wpdb->prepare( // phpcs:ignore
										"SELECT g.campaign_id AS id,
												g.message_id,
												IFNULL(SUM( CASE WHEN g.event = 'view' THEN 1 END ), 0) AS views,
												IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.line_total END), 0) AS earnings, 
												IFNULL(COUNT( DISTINCT (CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.order_id END)), 0) AS orderCount, 
												IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.qty END), 0) AS orderItemCount
											FROM {$wpdb->prefix}om_tracking_general AS g
												LEFT JOIN {$wpdb->prefix}om_tracking_orders AS o
													ON (g.id = o.tracking_id 
														AND o.is_valid_order = %d)
											WHERE g.event IN (%s, %s)
												AND FIND_IN_SET ( g.campaign_id, ( SELECT option_value
																					FROM {$wpdb->prefix}options
																					WHERE option_name = %s ) )
											GROUP BY g.message_id, g.campaign_id",
										1,
										'view',
										'convert',
										$option_nm
									),
						ARRAY_A
					);
				} else {
					$results = $wpdb->get_results( // phpcs:ignore
							$wpdb->prepare( // phpcs:ignore
								"SELECT g.campaign_id AS id,
										IFNULL(SUM( CASE WHEN g.event = 'view' THEN 1 END ), 0) AS views,
										IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.line_total END), 0) AS earnings, 
										IFNULL(COUNT( DISTINCT (CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.order_id END)), 0) AS orderCount, 
										IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.qty END), 0) AS orderItemCount
									FROM {$wpdb->prefix}om_tracking_general AS g
										LEFT JOIN {$wpdb->prefix}om_tracking_orders AS o
											ON (g.id = o.tracking_id 
												AND o.is_valid_order = %d)
									WHERE g.event IN (%s, %s)
											AND FIND_IN_SET ( g.campaign_id, ( SELECT option_value
																					FROM {$wpdb->prefix}options
																					WHERE option_name = %s ) )
									GROUP BY g.campaign_id",
								1,
								'view',
								'convert',
								$option_nm
							),
						ARRAY_A
					);
				}
				delete_option( $option_nm );

			} else {
				if ( ! empty( $group_by_msg_id ) ) {
					$results = $wpdb->get_results( // phpcs:ignore
									$wpdb->prepare( // phpcs:ignore
										"SELECT g.message_id,
												IFNULL(SUM( CASE WHEN g.event = 'view' THEN 1 END ), 0) AS views,
												IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.line_total END), 0) AS earnings, 
												IFNULL(COUNT( DISTINCT (CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.order_id END)), 0) AS orderCount, 
												IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.qty END), 0) AS orderItemCount
											FROM {$wpdb->prefix}om_tracking_general AS g
												LEFT JOIN {$wpdb->prefix}om_tracking_orders AS o
													ON (g.id = o.tracking_id 
														AND o.is_valid_order = %d)
											WHERE g.event IN (%s, %s)
											GROUP BY g.message_id",
										1,
										'view',
										'convert'
									),
						ARRAY_A
					);
				} else {
					$results = $wpdb->get_results( // phpcs:ignore
							$wpdb->prepare( // phpcs:ignore
								"SELECT IFNULL(SUM( CASE WHEN g.event = 'view' THEN 1 END ), 0) AS views,
										IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.line_total END), 0) AS earnings, 
										IFNULL(COUNT( DISTINCT (CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.order_id END)), 0) AS orderCount, 
										IFNULL(SUM(CASE WHEN g.event = 'convert' AND o.product_id = 0 AND o.variation_id = 0 THEN o.qty END), 0) AS orderItemCount
									FROM {$wpdb->prefix}om_tracking_general AS g
										LEFT JOIN {$wpdb->prefix}om_tracking_orders AS o
											ON (g.id = o.tracking_id 
												AND o.is_valid_order = %d)
									WHERE g.event IN (%s, %s)",
								1,
								'view',
								'convert'
							),
						ARRAY_A
					);
				}
			}

			return $results;
		}

		/**
		 * To search and return only products and not variations
		 *
		 * @param string $x Search string.
		 * @param array  $post_types List os post type to search in.
		 */
		public function om_json_search_products_only_and_not_variations( $x = '', $post_types = array( 'product' ) ) {
			$term = ( ! empty( $_GET['term'] ) ) ? (string) urldecode( sanitize_text_field( wp_unslash( $_GET['term'] ) ) ) : '';  // phpcs:ignore
			if ( empty( $term ) ) {
				die();
			}

			$posts = array();

			if ( is_numeric( $term ) ) {
				$args = array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'post__in'       => array( 0, $term ),
					'fields'         => 'ids',
				);

				$args2 = array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'post_parent'    => $term,
					'fields'         => 'ids',
				);

				$args3 = array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'meta_query'     => array( // phpcs:ignore
						array(
							'key'     => '_sku',
							'value'   => $term,
							'compare' => 'LIKE',
						),
					),
					'fields'         => 'ids',
				);

				$posts = array_unique( array_merge( get_posts( $args ), get_posts( $args2 ), get_posts( $args3 ) ) );
			} else {
				$args = array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					's'              => $term,
					'fields'         => 'ids',
				);

				$args2 = array(
					'post_type'      => $post_types,
					'post_status'    => array( 'publish' ),
					'posts_per_page' => -1,
					'meta_query'     => array( // phpcs:ignore
						array(
							'key'     => '_sku',
							'value'   => $term,
							'compare' => 'LIKE',
						),
					),
					'fields'         => 'ids',
				);

				$posts = array_unique( array_merge( get_posts( $args ), get_posts( $args2 ) ) );
			}

			$found_products = array();
			if ( $posts ) {
				foreach ( $posts as $post ) {
					$product = wc_get_product( $post );
					if ( ( $product instanceof WC_Product ) ) {
						$found_products[ $post ] = $product->get_formatted_name();
					}
				}
			}

			wp_send_json( $found_products );
		}

		/**
		 * Function to ask to review the plugin in footer
		 *
		 * @param  string $om_text Text in footer (left).
		 * @return string $om_text
		 */
		public function om_footer_text( $om_text ) {
			$screen    = get_current_screen();
			$screen_id = $screen ? $screen->id : '';

			if ( 'woocommerce_page_offermative' === $screen_id ) {
				$om_text = '';
			}

			return $om_text;
		}

		/**
		 * Function to ask to leave an idea on WC ideaboard
		 *
		 * @param  string $om_text Text in footer (right).
		 * @return string $om_text
		 */
		public function om_update_footer_text( $om_text ) {
			$screen    = get_current_screen();
			$screen_id = $screen ? $screen->id : '';

			if ( 'woocommerce_page_offermative' === $screen_id ) {
				$om_text = '';
			}

			return $om_text;
		}

	}

	OM_Admin_Dashboard::get_instance();
}
