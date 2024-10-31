<?php
/**
 * Main Controller class for Offermative Frontend Ajax actions
 *
 * @since       1.0.0
 * @version     1.0.0
 *
 * @package     Offermative/includes/frontend
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'OM_Ajax_Controller' ) ) {

	/**
	 *  Main OM_Ajax_Controller Class.
	 *
	 * @return object of OM_Ajax_Controller having all functionality of Offermative
	 */
	class OM_Ajax_Controller {

		/**
		 * Variable to hold transient expiration
		 *
		 * @var $transient_expiration
		 */
		public $transient_expiration = 60 * 60 * 8; // 8 hrs

		/**
		 * Variable to hold instance of Offermative
		 *
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * Get single instance of Offermative.
		 *
		 * @return OM_Ajax_Controller Singleton object of OM_Ajax_Controller
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
			add_action( 'wp_ajax_om_controller', array( $this, 'request_handler' ) );
			add_action( 'wp_ajax_nopriv_om_controller', array( $this, 'request_handler' ) );
		}

		/**
		 * Function to route AJAX requests
		 */
		public function request_handler() {
			$func_nm = ( isset( $_REQUEST['cmd'] ) ) ? sanitize_text_field( wp_unslash( $_REQUEST['cmd'] ) ) : ''; // phpcs:ignore
			if ( empty( $func_nm ) ) {
				return;
			}

			check_ajax_referer( 'offermative-security', 'security' );

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

			if ( is_callable( array( $this, $func_nm ) ) ) {
				$this->$func_nm( $params );
			}
		}

		/**
		 * Function to get campings & other data required for showing the campaings
		 *
		 * @param array $params Params from the AJAX request.
		 */
		public function get_data( $params = array() ) {
			$this->frontend_data = array();
			$ops = ( ! empty( $params['ops'] ) ) ? $params['ops'] : array(); // phpcs:ignore

			array_walk(
				$ops,
				function( $op ) {
					$func_nm = 'get_' . strtolower( preg_replace( '/\B([A-Z])/', '_$1', $op ) );
					if ( is_callable( array( $this, $func_nm ) ) ) {
						$this->frontend_data[ $op ] = $this->$func_nm();
					}
				}
			);
			wp_send_json( $this->frontend_data );
		}

		/**
		 * Function to get and send only enabled campaigns
		 *
		 * @return array List of active campaigns
		 */
		public function get_campaigns() {
			return $this->get_all_campaigns( 'enabled' );
		}

		/**
		 * Function to get campaigns
		 *
		 * @param string $status Campaign status.
		 * @return array List of campaigns
		 */
		public function get_all_campaigns( $status = 'disabled' ) {
			$results   = array();
			$campaigns = array();

			global $wpdb;

			$current_time = time();

			// get only enabled campaigns.
			if ( 'enabled' === $status ) {
				$results = $wpdb->get_results( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT id, created_date, start_date, end_date, params
										FROM {$wpdb->prefix}om_campaigns
										WHERE status = %s AND ( start_date <= %d AND end_date > %d )
									",
						'enabled',
						$current_time,
						$current_time
					),
					'ARRAY_A'
				);
			}

			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {
					$campaign              = array();
					$campaign['id']        = ( ! empty( $result['id'] ) ) ? $result['id'] : 0;
					$campaign['createdAt'] = ( ! empty( $result['created_date'] ) ) ? $result['created_date'] : $result['start_date'];
					$campaign['startAt']   = ( ! empty( $result['start_date'] ) ) ? $result['start_date'] : 0;
					$campaign['endAt']     = ( ! empty( $result['end_date'] ) ) ? $result['end_date'] : 0;
					$params                = ( ! empty( $result['params'] ) ) ? json_decode( $result['params'], true ) : array();
					$campaign['offer']     = ( ! empty( $params['offer'] ) ) ? $params['offer'] : array();
					$campaign['messages']  = ( ! empty( $params['messages'] ) ) ? $params['messages'] : array();

					$campaigns[] = $campaign;
				}
			}

			return $campaigns;
		}

		/**
		 * Return page details
		 *
		 * @return array Page & URL of the page
		 */
		public function get_page_details() {
			global $wp, $post;

			if ( wp_doing_ajax() ) {
				$requesting_url  = strtok( wp_get_referer(), '?' );
				$page_url        = $requesting_url;
				$om_current_page = ( ! empty( $_SESSION['om_current_page'] ) ) ? sanitize_text_field( wp_unslash( $_SESSION['om_current_page'] ) ) : '';

				if ( ( ! empty( $om_current_page ) ) && 'page' !== $om_current_page ) {
					return array( $om_current_page, $page_url );
				}

				// Code for fetching URL args to detect current page using 'page_id' based on permalink settings.
				parse_str( wp_parse_url( wp_get_referer(), PHP_URL_QUERY ), $referer_query_args );
				$current_page_id = ( ! empty( $referer_query_args['page_id'] ) ) ? intval( $referer_query_args['page_id'] ) : 0;

				if ( empty( $current_page_id ) && ( get_home_url() === rtrim( $requesting_url, '/' ) || get_site_url() === rtrim( $requesting_url, '/' ) ) ) {
					$page = 'home';
				} elseif ( wc_get_cart_url() === $requesting_url ) {
					$page = 'cart';
				} elseif ( false !== strpos( $requesting_url, get_permalink( wc_get_page_id( 'myaccount' ) ) ) || wc_get_page_id( 'myaccount' ) === $current_page_id ) { // Did strpos instead of direct check for compat with pagination.
					$page = 'myaccount';
				} elseif ( false !== strpos( $requesting_url, get_permalink( wc_get_page_id( 'shop' ) ) ) || wc_get_page_id( 'shop' ) === $current_page_id ) { // Did strpos instead of direct check for compat with pagination.
					$page = 'shop';
				} elseif ( false !== strpos( $requesting_url, 'order-received' ) ) {
					$page = 'thankyou';
				} elseif ( wc_get_checkout_url() === $requesting_url ) {
					$page = 'checkout';
				} elseif ( 'page' === $om_current_page ) {
					$page = $om_current_page;
				} else { // TODO: just kept it as an option, in ideal case would never reach here.
					$page = 'any';
				}
			}

			return array( $page, $page_url );
		}

		/**
		 * Return cart details
		 *
		 * @return array Array of cart items with other cart details
		 */
		public function get_cart_contents() {
			$cart_order_contents = array();
			$cart_order_totals   = array();

			if ( isset( WC()->cart ) ) {
				$cart_order_contents = WC()->cart->cart_contents;
				$cart_order_totals   = WC()->cart->get_totals();
			}

			$requesting_url = strtok( wp_get_referer(), '?' );

			if ( strpos( $requesting_url, 'order-received' ) ) {
				parse_str( wp_parse_url( wp_get_referer(), PHP_URL_QUERY ), $order_query_args );
				$order_id = 0;
				if ( ! empty( $order_query_args ) ) {
					foreach ( $order_query_args as $key => $value ) {
						if ( false !== strpos( $key, 'order_id' ) ) {
							$order_id = absint( $value );
							break;
						}
					}
				}
				if ( ! empty( $order_id ) ) {
					$order       = wc_get_order( $order_id );
					$order_items = $order->get_items();

					if ( ! empty( $order_items ) ) {
						$cart_order_contents = array_merge( $cart_order_contents, $order_items );
						$cart_order_totals   = $order->get_data();
					}
				}
			}

			$cart_items = array();

			foreach ( $cart_order_contents as $cart_item ) {

				$category_ids = wp_get_post_terms( $cart_item ['product_id'], 'product_cat', array( 'fields' => 'ids' ) );

				$cart_items[] = array(
					'id'       => ( ( ! empty( $cart_item ['variation_id'] ) ) ? $cart_item ['variation_id'] : $cart_item ['product_id'] ),
					'qty'      => $cart_item ['quantity'],
					'price'    => $cart_item ['line_total'],
					'category' => ( ( ! empty( $category_ids ) ) ? ( ( count( $category_ids ) > 1 ) ? $category_ids : $category_ids[0] ) : '' ),

				);

			}

			$cart = array(
				'items'    => $cart_items,
				'total'    => $cart_order_totals['total'],
				'shipping' => $cart_order_totals['shipping_total'],
				'tax'      => $cart_order_totals['total_tax'],
				'discount' => $cart_order_totals['discount_total'],
			);

			return $cart;
		}

		/**
		 * Return current user details
		 *
		 * @return array $user_details Array of user details for the current user
		 */
		public function get_user_meta() {
			global $wpdb, $current_user;

			$user_id      = ( ! empty( $current_user->ID ) ? $current_user->ID : 0 );
			$user_details = array(
				'id'                => $user_id,
				'since'             => 0,
				'isUserLoggedIn'    => ( ! empty( $user_id ) ? true : false ),
				'ltv'               => 0,
				'orderCount'        => 0,
				'products'          => array(),
				'productCategories' => array(),
			);

			if ( ! empty( $user_id ) ) {

				$user_details['since'] = round( abs( time() - strtotime( $current_user->user_registered ) ) / DAY_IN_SECONDS );

				$valid_order_ids = $wpdb->get_col( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT DISTINCT(p.id) AS valid_order_ids
	    														FROM {$wpdb->posts} AS p
	    															JOIN {$wpdb->postmeta} AS pm
	    																ON( pm.post_id = p.ID
	    																	AND p.post_type = 'shop_order'
	    																	AND p.post_status IN ('wc-completed', 'wc-processing')
	    																	AND pm.meta_key = '_customer_user'
	    																	AND pm.meta_value = %d )",
						$user_id
					)
				);

				if ( ! empty( $valid_order_ids ) ) {

					$user_details['orderCount'] = count( $valid_order_ids );

					$option_name_oids = 'om_valid_orders_user_' . om_get_unique_id();

					// Code for saving in options table.
					update_option( $option_name_oids, implode( ',', $valid_order_ids ), 'no' );

					// query to fetch the order total.
					$user_details['ltv'] = $wpdb->get_var( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT IFNULL(SUM( meta_value ), 0) AS ltv
	    													FROM {$wpdb->postmeta}
	    													WHERE meta_key = '_order_total'
	    														AND FIND_IN_SET ( post_id, ( SELECT option_value
																									FROM {$wpdb->prefix}options
																									WHERE option_name = %s ) )
	    													 ",
							$option_name_oids
						)
					);
					$user_details['ltv'] = absint( $user_details['ltv'] );

					// query to get all the product_ids for the user.
					$user_details['products'] = $wpdb->get_col( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT DISTINCT( oim.meta_value ) AS product_ids
			    															FROM {$wpdb->prefix}woocommerce_order_items AS oi
			    																JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim
			    																	ON( oi.order_item_id = oim.order_item_id
			    																		AND oi.order_item_type = 'line_item'
			    																		AND oim.meta_key IN ('_product_id','_variation_id') )
			    															WHERE oim.meta_value > 0
			    																AND FIND_IN_SET ( oi.order_id, ( SELECT option_value
																													FROM {$wpdb->prefix}options
																													WHERE option_name = %s ) )
					    													 ",
							$option_name_oids
						)
					);

					delete_option( $option_name_oids );

					// query for fetching product categories.
					if ( ! empty( $user_details['products'] ) ) {

						$option_name_pids = 'om_purchased_pids_user_' . om_get_unique_id();

						update_option( $option_name_pids, implode( ',', $user_details['products'] ), 'no' );

						$user_details['productCategories'] = $wpdb->get_col( // phpcs:ignore
							$wpdb->prepare( // phpcs:ignore
								"SELECT DISTINCT(tt.term_id) AS category_ids
																				FROM {$wpdb->prefix}term_relationships AS tr
																					JOIN {$wpdb->prefix}term_taxonomy AS tt
																						ON( tt.term_taxonomy_id = tr.term_taxonomy_id
																							AND tt.taxonomy = 'product_cat' )
																				WHERE FIND_IN_SET ( tr.object_id, ( SELECT option_value
																													FROM {$wpdb->prefix}options
																													WHERE option_name = %s ) )
																				",
								$option_name_pids
							)
						);

						delete_option( $option_name_pids );

					}
				}
			}

			return $user_details;
		}

		/**
		 * Function to get context os current user
		 *
		 * @return array Current user context
		 */
		public function get_user_context() {
			if ( ! isset( $_SESSION ) ) {
				session_start();
			}

			list($page, $page_url) = $this->get_page_details();

			return array(
				'product'        => ( isset( $_SESSION ) && isset( $_SESSION['om_current_product_id'] ) ) ? array( 'id' => sanitize_text_field( wp_unslash( $_SESSION['om_current_product_id'] ) ) ) : '',
				'category'       => ( isset( $_SESSION ) && isset( $_SESSION['om_current_product_category'] ) ) ? sanitize_text_field( wp_unslash( $_SESSION['om_current_product_category'] ) ) : '',
				'aggressiveness' => get_option( 'sa_om_campaign_aggressiveness', 4 ),
				'page'           => $page,
				'cart'           => $this->get_cart_contents(),
			);
		}

		/**
		 * Get Product Recommendations
		 *
		 * @return array List of recommended product ids
		 */
		public function get_reco() {
			global $wpdb;

			if ( ! isset( $_SESSION ) ) {
				session_start();
			}

			$source_product_ids = $this->get_recommendation_source();

			if ( ! empty( $_SESSION['om_current_page'] ) && 'product' === $_SESSION['om_current_page'] && ! empty( $_SESSION['om_current_product_id'] ) ) {
				array_push( $source_product_ids, sanitize_text_field( wp_unslash( $_SESSION['om_current_product_id'] ) ) );
			}

			if ( empty( $source_product_ids ) ) {
				wp_send_json( array() );
			}

			$current_timestamp = microtime( true ) * 10000;

			$option_nm = 'sa_om_reco_source_product_ids_' . om_get_unique_id();
			update_option( $option_nm, implode( ',', $source_product_ids ), 'no' );

			$order_count = apply_filters( 'sa_om_max_orders_to_scan', get_option( 'sa_om_max_orders_to_scan', 200 ), array( 'source' => $this ) );

			$wpdb->query( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"INSERT INTO {$wpdb->prefix}om_fbt_temp( order_id, timestamp )
						SELECT DISTINCT(oi.order_id) as order_id,
							%d AS timestamp
						FROM {$wpdb->prefix}woocommerce_order_itemmeta as oim1
							JOIN {$wpdb->prefix}woocommerce_order_items as oi
								ON(oi.order_item_id = oim1.order_item_id
									AND oi.order_item_type = %s
									AND oim1.meta_key = %s
									AND FIND_IN_SET ( oim1.meta_value, ( SELECT option_value
																		FROM {$wpdb->prefix}options
																		WHERE option_name = %s ) ) )
						ORDER BY oi.order_id DESC
						LIMIT %d",
					$current_timestamp,
					'line_item',
					'_product_id',
					$option_nm,
					$order_count
				)
			);

			$results = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"SELECT product_id,
							fbt_product_id,
							count(combinations) as freq
						FROM (
								SELECT oim1.meta_value as product_id,
										oim2.meta_value as fbt_product_id,
										concat(oim1.meta_value,'_',oim2.meta_value) as combinations
									FROM {$wpdb->prefix}woocommerce_order_itemmeta as oim1
									JOIN (
											SELECT oi1.order_item_id as item_id,
													oi2.order_item_id as fbt_item_id,
													concat(oi1.order_item_id,'_',oi2.order_item_id) as combinations
											FROM {$wpdb->prefix}woocommerce_order_items as oi1
											JOIN {$wpdb->prefix}woocommerce_order_items as oi2
												ON (oi2.order_id = oi1.order_id
													AND oi2.order_item_type = oi1.order_item_type
													AND oi2.order_item_id != oi1.order_item_id
													and oi1.order_item_type = %s)
											WHERE oi1.order_id IN ( SELECT order_id
																	FROM {$wpdb->prefix}om_fbt_temp
																	WHERE timestamp = %d )
										) as ordermeta
										ON( (oim1.order_item_id = ordermeta.item_id)
											AND oim1.meta_key = %s)
									JOIN {$wpdb->prefix}woocommerce_order_itemmeta as oim2
										ON( (oim2.order_item_id = ordermeta.fbt_item_id)
											AND oim2.meta_key = %s)
							) as temp
						WHERE FIND_IN_SET ( product_id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )
						GROUP BY product_id, combinations
						HAVING freq > %d
						ORDER BY freq DESC
						LIMIT %d",
					'line_item',
					$current_timestamp,
					'_product_id',
					'_product_id',
					$option_nm,
					1,
					4
				),
				ARRAY_A
			);

			$frequently_together = array();

			if ( ! empty( $results ) ) {
				$frequently_together = wp_list_pluck( $results, 'fbt_product_id' );
			}

			$wpdb->query( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"DELETE FROM {$wpdb->prefix}om_fbt_temp
						WHERE timestamp = %d",
					$current_timestamp
				)
			);

			$same_category_product_ids = $wpdb->get_col( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"SELECT DISTINCT(object_id) AS product_id
						FROM {$wpdb->prefix}term_relationships
						WHERE term_taxonomy_id IN (
													SELECT tr.term_taxonomy_id AS ttid
														FROM {$wpdb->prefix}term_relationships AS tr
														JOIN {$wpdb->prefix}term_taxonomy AS tt
															ON (tt.term_taxonomy_id = tr.term_taxonomy_id
																AND tt.taxonomy = %s
																AND FIND_IN_SET ( tr.object_id, ( SELECT option_value
																									FROM {$wpdb->prefix}options
																									WHERE option_name = %s ) ) )
												)
							AND NOT FIND_IN_SET ( object_id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )
						LIMIT %d",
					'product_cat',
					$option_nm,
					$option_nm,
					2
				)
			);

			$upsell_crosssell = $wpdb->get_col( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"SELECT meta_value
						FROM {$wpdb->postmeta}
						WHERE FIND_IN_SET ( post_id, ( SELECT option_value
														FROM {$wpdb->prefix}options
														WHERE option_name = %s ) )
							AND meta_key IN (%s,%s)
							AND meta_value IS NOT NULL
							AND meta_value != %s
							AND meta_value != %s
						LIMIT %d",
					$option_nm,
					'_upsell_ids',
					'_crosssell_ids',
					'',
					'a:0:{}',
					4
				)
			);

			$upsell_crosssell_unserialized = ( ! empty( $upsell_crosssell ) ) ? array_map( 'maybe_unserialize', $upsell_crosssell ) : array();

			$upsell_crosssell_product_ids = array();

			if ( ! empty( $upsell_crosssell_unserialized ) ) {
				foreach ( $upsell_crosssell_unserialized as $unserialized ) {
					if ( ! empty( $unserialized ) ) {
						$upsell_crosssell_product_ids = array_merge( $upsell_crosssell_product_ids, $unserialized );
					}
				}
			}

			delete_option( $option_nm );

			return array_values( array_map( 'absint', array_unique( array_merge( $frequently_together, $same_category_product_ids, $upsell_crosssell_product_ids ) ) ) );
		}

		/**
		 * Get products and/or categories for which the recommendation will be fetched
		 *
		 * @return array $source_product_ids List of source product ids based on product page visited & current cart items
		 */
		public function get_recommendation_source() {
			$source_product_ids = array();
			if ( is_product() ) {
				$source_product_ids[] = get_the_ID();
			}
			$cart = ( is_object( WC() ) && isset( WC()->cart ) ) ? WC()->cart : null;
			if ( is_object( $cart ) && is_callable( array( $cart, 'is_empty' ) ) && ! $cart->is_empty() ) {
				$cart_contents      = ( is_callable( array( $cart, 'get_cart' ) ) ) ? $cart->get_cart() : array();
				$cart_product_ids   = wp_list_pluck( $cart_contents, 'product_id' );
				$source_product_ids = array_unique( array_merge( $source_product_ids, array_values( $cart_product_ids ) ) );
			}
			return $source_product_ids;
		}

		/**
		 * Function to perform on CTA click
		 *
		 * @param array $params Params from the AJAX request.
		 */
		public function om_process_cta_actions( $params = array() ) {
			$response = array(
				'ack'        => 'failed',
				'redirectTo' => '',
			);

			$cta_action = ( ! empty( $params['cta_action'] ) ) ? sanitize_text_field( wp_unslash( $params['cta_action'] ) ) : '';

			if ( ! empty( $cta_action ) && ( in_array( $cta_action, array( 'view', 'accept', 'skip' ), true ) ) ) {
				$om_campaign_data = ( ! empty( $params['data'] ) ) ? sanitize_text_field( wp_unslash( $params['data'] ) ) : array();
				if ( ! empty( $om_campaign_data ) ) {
					$campaign_data       = stripslashes( $om_campaign_data );
					$sa_om_campaign_data = json_decode( $campaign_data, true );

					$campaign_message_id = ! empty( $sa_om_campaign_data['id'] ) ? $sa_om_campaign_data['id'] : '';
					$c_m_id              = explode( '_', $campaign_message_id );
					$campaign_id         = $c_m_id[0];
					$message_id          = $c_m_id[1];

					$om_tracking = OM_Tracking::get_instance();
					$inserted_id = $om_tracking->om_track_event( $cta_action, $campaign_id, $message_id );

					if ( false !== $inserted_id ) {
						$response['ack'] = 'success';
					}

					if ( 'accept' === $cta_action && isset( $cta_action ) ) {
						$response = $this->actions_on_accept_offer( $sa_om_campaign_data, $campaign_id, $message_id );
					}
				}
			}

			wp_send_json( $response );
		}

		/**
		 * Actions on accepting offer
		 *
		 * @param array $sa_om_campaign_data Campaign data of the accepted offer.
		 * @param int   $campaign_id Accepted campaign id.
		 * @param int   $message_id Accepted message id.
		 * @return array $response Array containing ACK flag & redirect URL
		 */
		public function actions_on_accept_offer( $sa_om_campaign_data = array(), $campaign_id = 0, $message_id = 0 ) {

			$response = array(
				'ack'        => 'failed',
				'redirectTo' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			);

			$user_id = get_current_user_id();

			$cart          = ( is_object( WC() ) && isset( WC()->cart ) ) ? WC()->cart : null;
			$is_cart_empty = false;

			if ( empty( $cart ) || WC()->cart->is_empty() ) {
				$is_cart_empty = true;
			}

			// Redirect to cart page if cart empty.
			if ( $is_cart_empty ) {
				$response['redirectTo'] = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
			}

			if ( empty( $sa_om_campaign_data ) ) {
				return $response;
			}

			$campaign_message_id = ( ! empty( $sa_om_campaign_data['id'] ) ) ? $sa_om_campaign_data['id'] : ( $campaign_id . '_' . $message_id );

			$campaign_offer_data = $sa_om_campaign_data['offer'];
			$offer_type          = $campaign_offer_data['type'];
			$args                = array();
			$args['sa_om_data']  = array(
				'_sa_om_campaign_id' => $campaign_id,
				'_sa_om_message_id'  => $message_id,
				'_sa_om_offer_type'  => $offer_type,
			);

			$discount_type = $campaign_offer_data['discount']['type'];
			if ( 'amount' === $discount_type ) {
				$coupon_type = 'fixed_product';
			} elseif ( 'percent' === $discount_type ) {
				$coupon_type = 'percent';
			}

			if ( ! isset( $_SESSION ) ) {
				session_start();
			}

			$sa_om_accepted_campaigns = array();
			if ( ! empty( $_SESSION['_sa_om_accepted_campaigns'] ) ) {
				$sa_om_accepted_campaigns = array_map(
					function ( $accepted_campaign_ids ) {
						if ( is_array( $accepted_campaign_ids ) ) {
							return array_map(
								function ( $id ) {
									return sanitize_text_field( wp_unslash( $id ) );
								},
								$accepted_campaign_ids
							);
						} else {
							return sanitize_text_field( wp_unslash( $accepted_campaign_ids ) );
						}
					},
					$_SESSION['_sa_om_accepted_campaigns']
				);
			}

			switch ( $offer_type ) {
				case 'discount':
					$wc_notice     = '';
					$coupon_code   = ! empty( $campaign_offer_data['discount']['coupon'] ) ? $campaign_offer_data['discount']['coupon'] : '';
					$coupon_amount = $campaign_offer_data['discount']['value'];
					if ( empty( $coupon_code ) && ( 0 !== intval( $coupon_amount ) ) ) {
						$data        = array();
						$data        = array(
							'discount_type' => $coupon_type,
							'coupon_amount' => $coupon_amount,
						);
						$coupon_code = $this->om_create_coupon( $data, $campaign_message_id );
						$this->om_update_coupon_in_campaign_params( $coupon_code, $campaign_id );
					}
					if ( ! empty( $coupon_code ) && ( ! WC()->cart->has_discount( $coupon_code ) ) ) {
						$coupon = new WC_Coupon( $coupon_code );
						if ( $coupon->is_valid() ) {

							if ( ! $is_cart_empty ) {
								WC()->cart->add_discount( $coupon_code );
							} else {
								if ( 0 === $user_id ) {
									$this->maybe_save_coupon_in_cookie( $coupon_code );
								} else {
									WC()->cart->add_discount( $coupon_code );
								}
								$wc_notice = __( 'Please add some products to the cart to see the discount.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' );
							}
							$response['ack'] = 'success';
						}
					}

					$session_args = array(
						'_sa_om_campaign_id' => $campaign_id,
						'_sa_om_message_id'  => $message_id,
						'_sa_om_offer_type'  => $offer_type,
						'_sa_om_coupon_code' => ( ! empty( $coupon_code ) ? $coupon_code : '' ),
					);
					array_push( $sa_om_accepted_campaigns, $session_args );
					$_SESSION['_sa_om_accepted_campaigns'] = $sa_om_accepted_campaigns;

					// Code for showing coupon applied notice when cart is empty.
					if ( ! empty( $wc_notice ) ) {
						if ( 0 === $user_id ) {
							$wc_notice             = __( 'Coupon code applied successfully.', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ) . ' ' . $wc_notice;
							$_SESSION['wc_notice'] = $wc_notice;
						} else {
							wc_add_notice( $wc_notice );
						}
					}

					// Code to empty the redirect URL if page is checkout.
					if ( ! empty( $sa_om_campaign_data['currentPage'] ) && 'checkout' === $sa_om_campaign_data['currentPage'] ) {
						$response['redirectTo'] = '';
					}

					break;

				case 'product':
				case 'reco':
					// Add product to cart (Not a variable product).
					$product_id   = $campaign_offer_data['product']['id'];
					$variation_id = '';
					$variation    = '';

					$info_cta = false;
					if ( 'info' === $sa_om_campaign_data['ctaType'] ) {
						$info_cta               = true;
						$response['ack']        = 'success';
						$response['redirectTo'] = get_permalink( $product_id );
					}

					// When a product is a variation.
					$target_product_instance = wc_get_product( $product_id );
					if ( $target_product_instance instanceof WC_Product ) {
						if ( 0 !== intval( $target_product_instance->get_parent_id() ) ) {
							$parent_id    = $target_product_instance->get_parent_id();
							$variation_id = $target_product_instance->get_id();
							$variation    = $target_product_instance->get_variation_attributes();
						} else {
							$parent_id = $product_id;
						}

						if ( ( ! empty( $parent_id ) || ! empty( $variation_id ) || ! empty( $variation ) ) && false === $info_cta ) {
							$quantity = 1;
							WC()->cart->add_to_cart( $parent_id, $quantity, $variation_id, $variation, $args );
							$response['ack'] = 'success';
						}

						$coupon_code   = ! empty( $campaign_offer_data['discount']['coupon'] ) ? $campaign_offer_data['discount']['coupon'] : '';
						$coupon_amount = $campaign_offer_data['discount']['value'];
						if ( empty( $coupon_code ) && ( 0 !== intval( $coupon_amount ) ) ) {
							$data = array();
							$data = array(
								'discount_type'          => $coupon_type,
								'coupon_amount'          => $coupon_amount,
								'product_ids'            => ! empty( $product_id ) ? $product_id : array(), // should pass $parent_id ?
								'limit_usage_to_x_items' => ! empty( $campaign_offer_data['product']['count'] ) ? $campaign_offer_data['product']['count'] : '',
							);

							$coupon_code = $this->om_create_coupon( $data, $campaign_message_id );
							$this->om_update_coupon_in_campaign_params( $coupon_code, $campaign_id );
						}
						if ( ! empty( $coupon_code ) ) {
							$coupon = new WC_Coupon( $coupon_code );
							if ( false === $info_cta && ( isset( WC()->cart ) && ( ! WC()->cart->is_empty() ) && ( ! WC()->cart->has_discount( $coupon_code ) ) && $coupon->is_valid() ) ) {
								WC()->cart->add_discount( $coupon_code );
							} elseif ( true === $info_cta ) {
								$this->maybe_save_coupon_in_cookie( $coupon_code );
								$session_args = array(
									'_sa_om_campaign_id' => $campaign_id,
									'_sa_om_message_id'  => $message_id,
									'_sa_om_offer_type'  => $offer_type,
									'_sa_om_coupon_code' => ( ! empty( $coupon_code ) ? $coupon_code : '' ),
									'_sa_om_product_id'  => $parent_id,
								);
								array_push( $sa_om_accepted_campaigns, $session_args );
								$_SESSION['_sa_om_accepted_campaigns'] = $sa_om_accepted_campaigns;
							}
						}

						if ( 'reco' === $offer_type ) {
							$response['redirectTo'] = '';
						}
					}
					break;

				case 'category':
					$category_id            = $campaign_offer_data['product']['id'];
					$category               = get_term_by( 'id', $category_id, 'product_cat' );
					$archive_url            = get_term_link( $category );
					$response['ack']        = 'success';
					$response['redirectTo'] = $archive_url;

					$category_name = '';
					if ( ! is_wp_error( $category ) && is_object( $category ) && $category->name ) {
						$category_name = $category->name;
					}

					$notification  = __( 'Add some products', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' );
					$notification .= ( ! empty( $category_name ) ) ? ' ' . __( 'from', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' ) . ' ' . $category_name : '';
					$coupon_code   = ! empty( $campaign_offer_data['discount']['coupon'] ) ? $campaign_offer_data['discount']['coupon'] : '';
					$coupon_amount = $campaign_offer_data['discount']['value'];
					if ( empty( $coupon_code ) && ! empty( $coupon_amount ) ) {
						$data        = array();
						$data        = array(
							'discount_type'          => $coupon_type,
							'coupon_amount'          => $coupon_amount,
							'product_categories'     => ! empty( $campaign_offer_data['product']['id'] ) ? $campaign_offer_data['product']['id'] : array(),
							'limit_usage_to_x_items' => ! empty( $campaign_offer_data['product']['count'] ) ? $campaign_offer_data['product']['count'] : '',
						);
						$coupon_code = $this->om_create_coupon( $data, $campaign_message_id );
						$this->om_update_coupon_in_campaign_params( $coupon_code, $campaign_id );
					}
					if ( ! empty( $coupon_code ) ) {
						$coupon = new WC_Coupon( $coupon_code );
						if ( ! $is_cart_empty && ( ! WC()->cart->has_discount( $coupon_code ) ) && $coupon->is_valid() ) {
							WC()->cart->add_discount( $coupon_code );
						} else {
							if ( 0 === $user_id ) {
								$this->maybe_save_coupon_in_cookie( $coupon_code );
							} else {
								WC()->cart->add_discount( $coupon_code );
							}
							$notification .= ' ' . __( 'to see the discount', 'offermative-discount-pricing-related-products-upsell-funnels-for-woocommerce' );
						}
					}

					$session_args = array(
						'_sa_om_campaign_id' => $campaign_id,
						'_sa_om_message_id'  => $message_id,
						'_sa_om_offer_type'  => $offer_type,
						'_sa_om_coupon_code' => ( ! empty( $coupon_code ) ? $coupon_code : '' ),
						'_sa_om_category_id' => $category_id,
					);
					array_push( $sa_om_accepted_campaigns, $session_args );
					$_SESSION['_sa_om_accepted_campaigns'] = $sa_om_accepted_campaigns;

					// Code for showing coupon applied notice when cart is empty.
					if ( ! empty( $notification ) ) {
						if ( 0 === $user_id && $is_cart_empty ) {
							$_SESSION['wc_notice'] = $notification;
						} else {
							wc_add_notice( $notification );
						}
					}
					break;
			}
			return $response;
		}

		/**
		 * Function to create coupon dynamically
		 *
		 * @param array  $data Coupon Data to create coupon.
		 * @param string $campaign_message_id The campaign_message ID.
		 * @return string $coupon_code Generated coupon code
		 */
		public function om_create_coupon( $data = array(), $campaign_message_id = '' ) {
			$defaults = array(
				'discount_type'              => 'fixed_cart',
				'coupon_amount'              => 0,
				'individual_use'             => 'no',
				'product_ids'                => '',
				'exclude_product_ids'        => '',
				'usage_limit'                => '',
				'usage_limit_per_user'       => '',
				'limit_usage_to_x_items'     => '',
				'usage_count'                => '',
				'expiry_date'                => '',
				'free_shipping'              => 'no',
				'product_categories'         => array(),
				'exclude_product_categories' => array(),
				'exclude_sale_items'         => 'no',
				'minimum_amount'             => '',
				'maximum_amount'             => '',
				'customer_email'             => array(),
			);

			$coupon_data = wp_parse_args( $data, $defaults );

			$coupon_description = 'Created by Offermative: ' . $campaign_message_id;
			// Save the coupon in the posts.
			$coupon        = array(
				'post_title'   => $this->om_generate_unique_code( strtotime( gmdate( 'Y-m-d H:i:s' ) ) ),
				'post_excerpt' => $coupon_description,
				'post_status'  => 'publish',
				'post_author'  => 1,
				'post_type'    => 'shop_coupon',
			);
			$new_coupon_id = wp_insert_post( $coupon );
			$coupon_code   = get_the_title( $new_coupon_id );

			// Save data in postmeta.
			foreach ( $coupon_data as $key => $value ) {
				update_post_meta( $new_coupon_id, $key, $value );
			}

			return $coupon_code;
		}

		/**
		 * Generate unique string to be used as coupon code.
		 *
		 * @param string $timestamp Current time.
		 * @return string $unique_code
		 */
		public function om_generate_unique_code( $timestamp = '' ) {
			if ( empty( $timestamp ) ) {
				$timestamp = range( '1', '9' );
			}

			$unique_code = '';
			srand( (float) microtime( true ) * 1000000 ); // phpcs:ignore

			$coupon_code_length = '13';

			$chars = array_merge( range( 'a', 'z' ), str_split( $timestamp ) );
			for ( $rand = 1; $rand <= $coupon_code_length; $rand++ ) {
				$random       = rand( 0, count( $chars ) - 1 ); // phpcs:ignore
				$unique_code .= $chars[ $random ];
			}

			return $unique_code;
		}

		/**
		 * Update coupon in the campaign params
		 *
		 * @param string $coupon_code The coupon code to update.
		 * @param int    $campaign_id Id of the campaign to update.
		 */
		public function om_update_coupon_in_campaign_params( $coupon_code = '', $campaign_id = 0 ) {
			if ( empty( $coupon_code ) || empty( $campaign_id ) ) {
				return;
			}

			global $wpdb;

			$results = array();
			$results = $wpdb->get_row( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"SELECT params
										FROM {$wpdb->prefix}om_campaigns
										WHERE id = %d
										",
					$campaign_id
				),
				'ARRAY_A'
			);

			if ( ! empty( $results ) ) {
				$updated_params                        = array();
				$params                                = ( ! empty( $results['params'] ) ) ? json_decode( $results['params'], true ) : array();
				$params['offer']['discount']['coupon'] = $coupon_code;
				$updated_params                        = wp_json_encode( $params, true );

				$wpdb->query( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"UPDATE {$wpdb->prefix}om_campaigns
							SET params = %s
							WHERE id = %d",
						$updated_params,
						$campaign_id
					)
				);
			}
		}

		/**
		 * Save coupon to be applied in cookie if not already saved
		 *
		 * @param string $coupon_code The coupon code to save.
		 */
		public function maybe_save_coupon_in_cookie( $coupon_code = '' ) {
			$om_prefix   = 'om_applied_coupon_profile_';
			$cookie_name = $om_prefix . 'id';
			if ( empty( $_COOKIE[ $cookie_name ] ) ) {
				$unique_id = om_generate_unique_id();
			} else {
				$unique_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ); // phpcs:ignore
			}

			$applied_coupons = get_option( $om_prefix . $unique_id, array() );

			if ( ! in_array( $coupon_code, $applied_coupons, true ) ) {
				$applied_coupons[]            = $coupon_code;
				$saved_status[ $coupon_code ] = 'saved';
			} else {
				$saved_status[ $coupon_code ] = 'already_saved';
			}

			set_transient( $om_prefix . $unique_id, $applied_coupons, DAY_IN_SECONDS );
			wc_setcookie( $cookie_name, $unique_id );
		}

		/**
		 * Get variation HTML via AJAX
		 *
		 * @param array $params Params from the AJAX request.
		 */
		public function om_get_variations( $params = array() ) {
			global $product;

			$response    = array(
				'ack'  => 'failed',
				'html' => '',
			);
			$data        = ( ! empty( $params['data'] ) ) ? json_decode( stripslashes( sanitize_text_field( wp_unslash( $params['data'] ) ) ), true ) : array();
			$product_ids = ( ! empty( $data['productId'] ) ) ? array_map( 'absint', $data['productId'] ) : array();

			if ( ! empty( $product_ids ) ) {
				$html = array();
				foreach ( $product_ids as $product_id ) {
					$product = wc_get_product( $product_id );

					// Get Available variations?
					$get_variations = count( $product->get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );

					// Load the template.
					ob_start();
					wc_get_template(
						'single-product/add-to-cart/variable.php',
						array(
							'available_variations' => $get_variations ? $product->get_available_variations() : false,
							'attributes'           => $product->get_variation_attributes(),
							'selected_attributes'  => $product->get_default_attributes(),
						)
					);
					$html[ $product_id ] = ob_get_clean();
				}
				$response = array(
					'ack'  => 'success',
					'html' => $html,
				);
			}

			wp_send_json( $response );

		}

		/**
		 * Get all attribute's label
		 *
		 * @return array $attr_label Array of attribute name & its associated label
		 */
		public function get_all_attributes_label() {
			global $wc_product_attributes;

			$attr_label = array();
			if ( ! empty( $wc_product_attributes ) ) {
				foreach ( $wc_product_attributes as $attr_name => $attr ) {
					$attr_label[ $attr_name ] = ( ! empty( $attr->attribute_label ) ) ? $attr->attribute_label : '';
				}
			}
			return $attr_label;
		}

		/**
		 * Get attribute's options
		 *
		 * @param array $attributes Array of attribute name & its associated label.
		 * @return array $attributes_options Array of attribute name & its slug
		 */
		public function get_attributes_options( $attributes = array() ) {
			$attributes_options = array();

			if ( ! empty( $attributes ) ) {
				foreach ( $attributes as $name => $label ) {
					$terms = get_terms(
						array(
							'taxonomy' => $name,
						)
					);
					if ( empty( $terms ) ) {
						continue;
					}
					$slug_to_name = wp_list_pluck( $terms, 'name', 'slug' );
					if ( empty( $slug_to_name ) ) {
						continue;
					}
					$attributes_options[ $name ] = $slug_to_name;
				}
			}

			return $attributes_options;
		}

		/**
		 * Get offered product & categories meta for enabled campaigns
		 *
		 * @return array $response Array of campaigns meta like campaign product & categories meta
		 */
		public function get_campaigns_meta() {
			$response  = array(
				'products'          => array(),
				'productCategories' => array(),
			);
			$campaigns = ( ! empty( $this->frontend_data['campaigns'] ) ) ? $this->frontend_data['campaigns'] : $this->get_campaigns();

			if ( ! empty( $campaigns ) ) {
				$ids = array(
					'products'   => array(),
					'categories' => array(),
				);

				foreach ( $campaigns as $campaign ) {
					if ( ! empty( $campaign['offer']['type'] ) && ! empty( $campaign['offer']['product'] ) ) {
						switch ( $campaign['offer']['type'] ) {
							case 'product':
								if ( ! in_array( $campaign['id'], $ids['products'] ) ) {  // phpcs:ignore
									$ids['products'][] = $campaign['offer']['product']['id'];
								}
								break;
							case 'category':
								if ( ! in_array( $campaign['id'], $ids['categories'] ) ) {  // phpcs:ignore
									$ids['categories'][] = $campaign['offer']['product']['id'];
								}
								break;
						}
					}
				}

				if ( ! empty( $ids['products'] ) ) {
					$response['products'] = $this->get_offered_product_data( $ids['products'], false );
				}

				if ( ! empty( $ids['categories'] ) ) {
					$response['productCategories'] = om_get_category_data( $ids['categories'], true );
				}

				return $response;
			}
		}

		/**
		 * Get offered product meta data
		 *
		 * @param array $params Params from the AJAX request or product ids supplied.
		 * @param bool  $is_ajax Flag to determine if its an AJAX request or not.
		 * @return array $product_data Array of product meta for all the supplied product ids
		 */
		public function get_offered_product_data( $params = array(), $is_ajax = true ) {
			global $wpdb, $wc_product_attributes;

			$product_data = array();

			if ( $is_ajax ) {
				if ( ! empty( $params['productIds'] ) ) {
					$product_ids = json_decode( sanitize_text_field( wp_unslash( $params['productIds'] ) ), true );
				}
			} else {
				$product_ids = $params;
			}

			if ( empty( $product_ids ) ) {
				if ( $is_ajax ) {
					wp_send_json( $product_data );
				}
				return array();
			}

			$object_terms = wp_get_object_terms( $product_ids, 'product_type', array( 'fields' => 'all_with_object_id' ) );

			$product_types = ( ! empty( $object_terms ) ) ? wp_list_pluck( $object_terms, 'slug', 'object_id' ) : array();

			if ( ! empty( $product_ids ) ) {
				$option_nm = 'sa_om_campaign_product_ids_' . om_get_unique_id();
				update_option( $option_nm, implode( ',', $product_ids ), 'no' );

				$results = $wpdb->get_results( // phpcs:ignore
							$wpdb->prepare( // phpcs:ignore
									"SELECT IFNULL(p.ID, 0) AS product_id,
											IFNULL(p.post_title, '') AS product_name,
											IFNULL(p.post_excerpt, '') AS short_description,
											pm.meta_key,
											pm.meta_value
										FROM {$wpdb->posts} AS p
											LEFT JOIN {$wpdb->postmeta} AS pm
												ON (p.ID = pm.post_id AND pm.meta_key IN (%s,%s,%s))
										WHERE FIND_IN_SET ( p.ID, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
								'_thumbnail_id',
								'_price',
								'_default_attributes',
								$option_nm
							),
					ARRAY_A
				);

				delete_option( $option_nm );
			} else {
				$results = $wpdb->get_results( // phpcs:ignore
								$wpdb->prepare( // phpcs:ignore
									"SELECT IFNULL(p.ID, 0) AS product_id,
											IFNULL(p.post_title, '') AS product_name,
											IFNULL(p.post_excerpt, '') AS short_description,
											pm.meta_key,
											pm.meta_value
										FROM {$wpdb->posts} AS p
											LEFT JOIN {$wpdb->postmeta} AS pm
												ON (p.ID = pm.post_id AND pm.meta_key IN (%s,%s,%s))
										WHERE p.post_type IN (%s,%s)",
									'_thumbnail_id',
									'_price',
									'_default_attributes',
									'product',
									'product_variation'
								),
					ARRAY_A
				);
			}

			$attributes_label = $this->get_all_attributes_label();

			$attributes_options = array();

			if ( ! empty( $results ) ) {
				foreach ( $results as $result ) {
					$product_id = ( ! empty( $result['product_id'] ) ) ? absint( $result['product_id'] ) : 0;
					if ( empty( $product_id ) ) {
						continue;
					}

					if ( empty( $product_data[ $product_id ] ) || ! is_array( $product_data[ $product_id ] ) ) {
						$product_data[ $product_id ] = array();
					}

					if ( empty( $product_data[ $product_id ]['id'] ) ) {
						$product_data[ $product_id ]['id'] = $product_id;
					}

					if ( empty( $product_data[ $product_id ]['name'] ) ) {
						$product_data[ $product_id ]['name'] = esc_html( ( ! empty( $result['product_name'] ) ) ? $result['product_name'] : '' );
					}

					if ( empty( $product_data[ $product_id ]['description'] ) ) {
						$product_data[ $product_id ]['description'] = esc_html( ( ! empty( $result['short_description'] ) ) ? $result['short_description'] : '' );
					}

					if ( empty( $product_data[ $product_id ]['type'] ) ) {
						$product_data[ $product_id ]['type'] = ( ! empty( $product_types[ $product_id ] ) ) ? $product_types[ $product_id ] : '';
					}

					if ( empty( $product_data[ $product_id ]['permalink'] ) ) {
						$product_data[ $product_id ]['permalink'] = get_permalink( $product_id );
					}

					$meta_key = ( ! empty( $result['meta_key'] ) ) ? $result['meta_key'] : '';

					if ( ! empty( $meta_key ) ) {
						switch ( $meta_key ) {
							case '_thumbnail_id':
								if ( empty( $product_data[ $product_id ]['image'] ) ) {
									$image                                = ( ! empty( $result['meta_value'] ) ) ? wp_get_attachment_image_src( $result['meta_value'], 'full' ) : array();
									$product_data[ $product_id ]['image'] = ( ! empty( $image[0] ) ) ? $image[0] : '';
								}
								break;

							case '_price':
								$new_price = ( ! empty( $result['meta_value'] ) ) ? $result['meta_value'] : 0;
								if ( ! isset( $product_data[ $product_id ]['price'] ) ) {
									$product_data[ $product_id ]['price'] = 0;
								}
								$old_price                            = $product_data[ $product_id ]['price'];
								$product_data[ $product_id ]['price'] = ( ! empty( $old_price ) && ! empty( $new_price ) ) ? min( $old_price, $new_price ) : max( $old_price, $new_price );
								break;

							case '_default_attributes':
								if ( empty( $product_data[ $product_id ]['default_variation'] ) ) {
									$default_variation = array();
									$attributes        = ( ! empty( $result['meta_value'] ) ) ? maybe_unserialize( $result['meta_value'] ) : array();
									if ( ! empty( $attributes ) ) {
										foreach ( $attributes as $attr => $option ) {
											if ( empty( $attributes_options[ $attr ] ) ) {
												$attributes_options = array_merge( $attributes_options, $this->get_attributes_options( array( $attr => $attributes_label[ $attr ] ) ) );
											}
											$default_variation[ $attributes_label[ $attr ] ] = $attributes_options[ $attr ][ $option ];
										}
									}
									$product_data[ $product_id ]['default_variation'] = $default_variation;
								}
								break;
						}
					}
				}
			}

			if ( ! empty( $product_data ) ) {
				foreach ( $product_data as $id => $product ) {
					if ( empty( $product['image'] ) ) {
						$product_data[ $id ]['image'] = SA_OM_IMG_URL . 'default-offered-product.jpg';
					}
				}
			}

			if ( $is_ajax ) {
				wp_send_json( $product_data );
			} else {
				return $product_data;
			}

		}

		/**
		 * Get whether the campaign is enabled or not
		 *
		 * @param integer|array $id The campaign id.
		 * @return array $enabled List of enabled campaign ids
		 */
		public function get_enabled( $id = 0 ) {
			global $wpdb;

			$enabled = array();

			if ( ! empty( $id ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									status
								FROM {$wpdb->prefix}om_campaigns
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$option_nm
						),
						ARRAY_A
					);

					delete_option( $option_nm );
				} else {
					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									status
								FROM {$wpdb->prefix}om_campaigns
								WHERE id = %d",
							$id
						),
						ARRAY_A
					);
				}
				if ( ! empty( $results ) ) {
					$enabled = wp_list_pluck( $results, 'status', 'id' );
				}
			}

			return $enabled;
		}

		/**
		 * Get campaign start date
		 *
		 * @param integer|array $id The campaign id.
		 * @return array $start_date List of campaign ids with their start date
		 */
		public function get_start_date( $id = 0 ) {
			global $wpdb;

			$start_date = array();

			if ( ! empty( $id ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									start_date
								FROM {$wpdb->prefix}om_campaigns
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$option_nm
						),
						ARRAY_A
					);
					delete_option( $option_nm );
				} else {
					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									start_date
								FROM {$wpdb->prefix}om_campaigns
								WHERE id = %d",
							$id
						),
						ARRAY_A
					);
				}
				if ( ! empty( $results ) ) {
					$start_date = wp_list_pluck( $results, 'start_date', 'id' );
				}
			}

			return $start_date;
		}

		/**
		 * Get campaign end date
		 *
		 * @param integer|array $id The campaign id.
		 * @return array $end_date List of campaign ids with their end dates
		 */
		public function get_end_date( $id = 0 ) {
			global $wpdb;

			$end_date = array();

			if ( ! empty( $id ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									end_date
								FROM {$wpdb->prefix}om_campaigns
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$option_nm
						),
						ARRAY_A
					);
					delete_option( $option_nm );
				} else {
					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									end_date
								FROM {$wpdb->prefix}om_campaigns
								WHERE id = %d",
							$id
						),
						ARRAY_A
					);
				}
				if ( ! empty( $results ) ) {
					$end_date = wp_list_pluck( $results, 'end_date', 'id' );
				}
			}

			return $end_date;
		}

		/**
		 * Get additional campaign data
		 *
		 * @param integer|array $id The campaign id.
		 * @return array $params List of campaign ids with their params
		 */
		public function get_params( $id = 0 ) {
			global $wpdb;

			$params = array();

			if ( ! empty( $id ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									params
								FROM {$wpdb->prefix}om_campaigns
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$option_nm
						),
						ARRAY_A
					);
					delete_option( $option_nm );
				} else {
					$results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id,
									params
								FROM {$wpdb->prefix}om_campaigns
								WHERE id = %d",
							$id
						),
						ARRAY_A
					);
				}
				if ( ! empty( $results ) ) {
					$params = wp_list_pluck( $results, 'params', 'id' );
				}
			}

			return $params;
		}

		/**
		 * Get a campaign
		 *
		 * @param integer|array $id The campaign id.
		 * @return array $enabled List of campaigns for the supplied ids
		 */
		public function get_campaign( $id = 0 ) {
			global $wpdb;

			$campaign = array();

			if ( ! empty( $id ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$campaign = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT *
								FROM {$wpdb->prefix}om_campaigns
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$option_nm
						),
						ARRAY_A
					);
					delete_option( $option_nm );
				} else {
					$campaign = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT *
								FROM {$wpdb->prefix}om_campaigns
								WHERE id = %d",
							$id
						),
						ARRAY_A
					);
				}
			}

			return $campaign;
		}

		/**
		 * Set start date
		 *
		 * @param integer|array $id Campaign id.
		 * @param integer       $start_date Start date to be updated.
		 */
		public function set_start_date( $id = 0, $start_date = '' ) {
			global $wpdb;

			if ( ! empty( $id ) && ! empty( $start_date ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET start_date = %d
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$start_date,
							$option_nm
						)
					);
					delete_option( $option_nm );
				} else {
					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET start_date = %d
								WHERE id = %d",
							$start_date,
							$id
						)
					);
				}
			}
		}

		/**
		 * Set end date
		 *
		 * @param integer|array $id Campaign id.
		 * @param integer       $end_date End date to be updated.
		 */
		public function set_end_date( $id = 0, $end_date = '' ) {
			global $wpdb;

			if ( ! empty( $id ) && ! empty( $end_date ) ) {
				if ( is_array( $id ) ) {
					$option_nm = 'sa_om_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $id ), 'no' );

					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET end_date = %d
								WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							$end_date,
							$option_nm
						)
					);
					delete_option( $option_nm );
				} else {
					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
								SET end_date = %s
								WHERE id = %d",
							$end_date,
							$id
						)
					);
				}
			}
		}

	}

}

OM_Ajax_Controller::get_instance();
