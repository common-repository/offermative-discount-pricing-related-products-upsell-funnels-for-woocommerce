<?php
/**
 * Class for Offermative tracking
 *
 * @version     1.0.0
 *
 * @package     offermative/includes/
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'OM_Tracking' ) ) {

	/**
	 * Main OM_Tracking Class.
	 *
	 * @return object of OM_Tracking having tracking functionality of Offermative
	 */
	class OM_Tracking {

		/**
		 * Variable to hold instance of Offermative
		 *
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * Get single instance of Offermative.
		 *
		 * @return OM_Tracking Singleton object of OM_Tracking
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
			// Add data in order.
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'om_update_order_data' ), 10, 2 );

			// Update data when order is updated.
			add_action( 'woocommerce_update_order', array( $this, 'om_update_order_tracking' ), 11 );

			// Update valid_order flag when order is trashed/untrashed/deleted.
			add_action( 'trashed_post', array( $this, 'om_update_valid_order_on_trash_delete_untrash' ), 9, 1 );
			add_action( 'untrashed_post', array( $this, 'om_update_valid_order_on_trash_delete_untrash' ), 9, 1 );
			add_action( 'delete_post', array( $this, 'om_update_valid_order_on_trash_delete_untrash' ), 9, 1 );

			// Update valid_order flag when order status changes.
			add_action( 'woocommerce_order_status_changed', array( $this, 'om_update_valid_order_on_status_change' ), 11, 3 );

			// Actions to handling scheduling of tracking events.
			$this->schedule_events();
			add_action( 'om_sync_tracking_data_daily', array( $this, 'sync_data' ) );
		}

		/**
		 * Function to schedule cron events.
		 */
		public function schedule_events() {
			if ( ! wp_next_scheduled( 'om_sync_tracking_data_daily' ) ) {
				wp_schedule_event( ( strtotime( 'tomorrow +1 minutes' ) + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ), 'daily', 'om_sync_tracking_data_daily' );
			}
		}

		/**
		 * Function to track view, accept, skip event of an campaign.
		 *
		 * @param string $event The event to be captured.
		 * @param int    $campaign_id The campaign ID.
		 * @param int    $message_id The message ID.
		 */
		public function om_track_event( $event, $campaign_id, $message_id ) {

			if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
				// Check ip from share internet.
				$user_ip_address = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				// To check ip is pass from proxy.
				$user_ip_address = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			} else {
				$user_ip_address = ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			}
			$modified_ip_address = inet_pton( $user_ip_address );

			$om_tracking_general = array(
				'campaign_id'     => $campaign_id,
				'message_id'      => $message_id,
				'event'           => $event,
				'timestamp'       => time(),
				'user_id'         => get_current_user_id(),
				'user_ip_address' => ( ! empty( $modified_ip_address ) ) ? $modified_ip_address : $user_ip_address,
			);

			$inserted_id = $this->om_update_general_tracking( $om_tracking_general );
			return $inserted_id;

		}

		/**
		 * Insert Offermative params in order data
		 *
		 * @param int   $order_id The ID of a WC_Order object.
		 * @param array $posted The data posted on checkout.
		 */
		public function om_update_order_data( $order_id, $posted ) {

			$om_tracking_general = array();
			$om_tracking_order   = array();

			$order = new WC_Order( $order_id );

			$user_id             = $order->get_user_id();
			$user_ip_address     = $order->get_customer_ip_address();
			$modified_ip_address = inet_pton( $user_ip_address );

			$is_valid_order = ( in_array( $order->get_status(), array( 'on-hold', 'processing', 'completed', 'refunded' ), true ) ) ? 1 : 0;

			$order_used_coupons = $order->get_coupon_codes();

			$order_tracking_ids       = array(); // Variable used to store all tracking ids for this order.
			$total_offered_cart_items = 0;

			if ( ! isset( $_SESSION ) ) {
				session_start();
			}

			$om_accepted_campaigns_session = array();
			if ( ! empty( $_SESSION['_sa_om_accepted_campaigns'] ) ) {
				$om_accepted_campaigns_session = array_map(
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

			foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item_data ) {

				foreach ( $om_accepted_campaigns_session as $index => $accepted_campaign_data ) {
					$do_tracking = false;

					if ( isset( $accepted_campaign_data['_sa_om_coupon_code'] ) && ( '' !== $accepted_campaign_data['_sa_om_coupon_code'] ) && in_array( $accepted_campaign_data['_sa_om_coupon_code'], $order_used_coupons, true ) && ( ! isset( $cart_item_data['sa_om_data'] ) ) ) {

						if ( 'discount' === $accepted_campaign_data['_sa_om_offer_type'] ) {
							$do_tracking = true;
						} elseif ( 'category' === $accepted_campaign_data['_sa_om_offer_type'] && ! empty( $accepted_campaign_data['_sa_om_category_id'] ) ) {
							$terms                     = get_the_terms( $cart_item_data['product_id'], 'product_cat' );
							$cart_product_category_ids = array();
							foreach ( $terms as $term ) {
								$cart_product_category_ids[] = $term->term_id;
							}
							if ( in_array( $accepted_campaign_data['_sa_om_category_id'], $cart_product_category_ids ) ) { // phpcs:ignore
								$do_tracking = true;
							}
						} elseif ( in_array( $accepted_campaign_data['_sa_om_offer_type'], array( 'product', 'reco' ), true ) && ! empty( $accepted_campaign_data['_sa_om_product_id'] ) ) {
							$cart_product_id = ( 0 === intval( $cart_item_data['variation_id'] ) ) ? $cart_item_data['product_id'] : $cart_item_data['variation_id'];
							// Don't do strict comparison.
							if ( $accepted_campaign_data['_sa_om_product_id'] == $cart_product_id ) {  // phpcs:ignore
								$do_tracking = true;
							}
						}

						if ( true === $do_tracking ) {
							$om_tracking_general       = array(
								'campaign_id'     => $accepted_campaign_data['_sa_om_campaign_id'],
								'message_id'      => $accepted_campaign_data['_sa_om_message_id'],
								'event'           => 'convert',
								'timestamp'       => strtotime( $order->get_date_created() ),
								'user_id'         => $user_id,
								'user_ip_address' => ( ! empty( $modified_ip_address ) ) ? $modified_ip_address : $user_ip_address,
							);
							$inserted_id               = $this->om_update_general_tracking( $om_tracking_general );
							$order_tracking_ids[]      = $inserted_id;
							$total_offered_cart_items += intval( $cart_item_data['quantity'] ); // TODO: check later.
							$om_tracking_order         = array(
								'tracking_id'    => $inserted_id,
								'order_id'       => $order_id,
								'product_id'     => $cart_item_data['product_id'],
								'variation_id'   => $cart_item_data['variation_id'],
								'qty'            => $cart_item_data['quantity'],
								'line_total'     => $cart_item_data['line_total'] * 100,
								'is_valid_order' => $is_valid_order,
							);
							$this->om_update_orders_tracking( $om_tracking_order, 'insert' );
						}
					} elseif ( '' === $accepted_campaign_data['_sa_om_coupon_code'] && ( ! isset( $cart_item_data['sa_om_data'] ) ) ) {

						if ( 'discount' === $accepted_campaign_data['_sa_om_offer_type'] ) {
							$do_tracking = true;
						} elseif ( 'category' === $accepted_campaign_data['_sa_om_offer_type'] && ! empty( $accepted_campaign_data['_sa_om_category_id'] ) ) {
							$terms                     = get_the_terms( $cart_item_data['product_id'], 'product_cat' );
							$cart_product_category_ids = array();
							foreach ( $terms as $term ) {
								$cart_product_category_ids[] = $term->term_id;
							}
							if ( in_array( $accepted_campaign_data['_sa_om_category_id'], $cart_product_category_ids ) ) {  // phpcs:ignore
								$do_tracking = true;
							}
						} elseif ( in_array( $accepted_campaign_data['_sa_om_offer_type'], array( 'product', 'reco' ), true ) && ! empty( $accepted_campaign_data['_sa_om_product_id'] ) ) {
							$cart_product_id = ( 0 === intval( $cart_item_data['variation_id'] ) ) ? $cart_item_data['product_id'] : $cart_item_data['variation_id'];
							// Don't do strict comparison.
							if ( $accepted_campaign_data['_sa_om_product_id'] == $cart_product_id ) { // phpcs:ignore
								$do_tracking = true;
							}
						}

						if ( true === $do_tracking ) {
							$om_tracking_general       = array(
								'campaign_id'     => $accepted_campaign_data['_sa_om_campaign_id'],
								'message_id'      => $accepted_campaign_data['_sa_om_message_id'],
								'event'           => 'convert',
								'timestamp'       => strtotime( $order->get_date_created() ),
								'user_id'         => $user_id,
								'user_ip_address' => ( ! empty( $modified_ip_address ) ) ? $modified_ip_address : $user_ip_address,
							);
							$inserted_id               = $this->om_update_general_tracking( $om_tracking_general );
							$order_tracking_ids[]      = $inserted_id;
							$total_offered_cart_items += intval( $cart_item_data['quantity'] ); // TODO: check later.
							$om_tracking_order         = array(
								'tracking_id'    => $inserted_id,
								'order_id'       => $order_id,
								'product_id'     => $cart_item_data['product_id'],
								'variation_id'   => $cart_item_data['variation_id'],
								'qty'            => $cart_item_data['quantity'],
								'line_total'     => $cart_item_data['line_total'] * 100,
								'is_valid_order' => $is_valid_order,
							);
							$this->om_update_orders_tracking( $om_tracking_order, 'insert' );
						}
					}
					// need to destroy session here?
				}

				// For product and reco, sa_om_data will be present in the cart item data if not infoCTA.
				if ( isset( $cart_item_data['sa_om_data'] ) ) {
					$om_tracking_general       = array(
						'campaign_id'     => $cart_item_data['sa_om_data']['_sa_om_campaign_id'],
						'message_id'      => $cart_item_data['sa_om_data']['_sa_om_message_id'],
						'event'           => 'convert',
						'timestamp'       => strtotime( $order->get_date_created() ),
						'user_id'         => $user_id,
						'user_ip_address' => ( ! empty( $modified_ip_address ) ) ? $modified_ip_address : $user_ip_address,
					);
					$inserted_id               = $this->om_update_general_tracking( $om_tracking_general );
					$order_tracking_ids[]      = $inserted_id;
					$total_offered_cart_items += intval( $cart_item_data['quantity'] ); // TODO: check later.
					$om_tracking_order         = array(
						'tracking_id'    => $inserted_id,
						'order_id'       => $order_id,
						'product_id'     => $cart_item_data['product_id'],
						'variation_id'   => $cart_item_data['variation_id'],
						'qty'            => $cart_item_data['quantity'],
						'line_total'     => $cart_item_data['line_total'] * 100,
						'is_valid_order' => $is_valid_order,
					);
					$this->om_update_orders_tracking( $om_tracking_order, 'insert' );
				}
			}

			// Code for entire order level tracking.
			if ( ! empty( $order_tracking_ids ) && ! empty( $order ) ) {
				$order_tracking_ids = array_unique( $order_tracking_ids );
				foreach ( $order_tracking_ids as $track_id ) {
					$om_tracking_order = array(
						'tracking_id'    => $track_id,
						'order_id'       => $order_id,
						'product_id'     => 0,
						'variation_id'   => 0,
						'qty'            => intval( $total_offered_cart_items ),
						'line_total'     => $order->get_total() * 100,
						'is_valid_order' => $is_valid_order,
					);
					$this->om_update_orders_tracking( $om_tracking_order, 'insert' );
				}
			}

			// Destroy session GLOBALLY.
			if ( $_SESSION['_sa_om_accepted_campaigns'] ) {
				unset( $_SESSION['_sa_om_accepted_campaigns'] );
			}
		}

		/**
		 * Insert tracking data
		 *
		 * @param int $data The campaign tracking data.
		 * @return int $insert_id The id of the inserted row.
		 */
		public function om_update_general_tracking( $data ) {
			global $wpdb;

			$result = $wpdb->query( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"INSERT INTO {$wpdb->prefix}om_tracking_general ( `campaign_id`, `message_id`, `event`, `timestamp`, `user_id`, `user_ip_address` )
									VALUES ( %s, %s, %s, %s, %s, %s )",
					intval( $data['campaign_id'] ),
					intval( $data['message_id'] ),
					$data['event'],
					intval( $data['timestamp'] ),
					intval( $data['user_id'] ),
					esc_sql( $data['user_ip_address'] )
				)
			);

			$insert_id = $wpdb->insert_id;

			return $insert_id;
		}

		/**
		 * Insert/Update order tracking data
		 *
		 * @param int    $data The campaign order tracking data.
		 * @param string $status The flag to determine whether its an insert or update.
		 */
		public function om_update_orders_tracking( $data, $status = 'insert' ) {
			global $wpdb;

			if ( 'insert' === $status ) {
				$result = $wpdb->query( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"INSERT INTO {$wpdb->prefix}om_tracking_orders ( tracking_id, order_id, product_id, variation_id, qty, line_total, is_valid_order )
								VALUES ( %d, %d, %d, %d, %d, %d, %d )",
						intval( $data['tracking_id'] ),
						intval( $data['order_id'] ),
						intval( $data['product_id'] ),
						intval( $data['variation_id'] ),
						intval( $data['qty'] ),
						intval( $data['line_total'] ),
						intval( $data['is_valid_order'] )
					)
				);
			} elseif ( 'update' === $status ) {
				$result = $wpdb->query( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"REPLACE INTO {$wpdb->prefix}om_tracking_orders ( tracking_id, order_id, product_id, variation_id, qty, line_total, is_valid_order )
							VALUES ( %d, %d, %d, %d, %d, %d, %d )",
						intval( $data['tracking_id'] ),
						intval( $data['order_id'] ),
						intval( $data['product_id'] ),
						intval( $data['variation_id'] ),
						intval( $data['qty'] ),
						intval( $data['line_total'] ),
						intval( $data['is_valid_order'] )
					)
				);
			}
		}

		/**
		 * Prepare order tracking data
		 *
		 * @param int $order_id Order ID for which tracking data has to be prepared.
		 */
		public function om_update_order_tracking( $order_id = 0 ) {
			global $wpdb;

			$order = '';
			if ( ! empty( $order_id ) && ! ( $order_id instanceof WC_Abstract_Order ) ) {
				$order = wc_get_order( $order_id );
			}

			if ( empty( $order ) ) {
				return;
			}

			$is_valid_order = ( in_array( $order->get_status(), array( 'on-hold', 'processing', 'completed' ), true ) ) ? 1 : 0;
			$decimals       = wc_get_price_decimals();
			$order_items    = $order->get_items();

			foreach ( $order_items as $order_item ) {
				$order_item_id = $order_item->get_id();
				$product_id    = wc_get_order_item_meta( $order_item_id, '_product_id' );
				$variation_id  = wc_get_order_item_meta( $order_item_id, '_variation_id' );
				$line_total    = round( $order_item->get_total( 'edit' ), $decimals ) * 100;

				$tracking_id = $wpdb->get_col( // phpcs:ignore
					$wpdb->prepare( // phpcs:ignore
						"SELECT tracking_id
														FROM {$wpdb->prefix}om_tracking_orders
														WHERE ( `order_id` = %d AND `product_id` = %d AND `variation_id` = %d )
													",
						$order_id,
						$product_id,
						$variation_id
					)
				);

				if ( ! empty( $tracking_id ) ) {
					$om_tracking_order = array(
						'tracking_id'    => $tracking_id[0],
						'order_id'       => $order_id,
						'product_id'     => $product_id,
						'variation_id'   => $variation_id,
						'qty'            => $order_item->get_quantity( 'edit' ),
						'line_total'     => $line_total,
						'is_valid_order' => $is_valid_order,
					);
					$this->om_update_orders_tracking( $om_tracking_order, 'update' );
				}
			}
		}

		/**
		 * Function to update valid_order flag an order is trashed/untrashed/deleted.
		 *
		 * @param int $trashed_order_id Order ID being trashed/untrashed/deleted.
		 */
		public function om_update_valid_order_on_trash_delete_untrash( $trashed_order_id ) {
			if ( empty( $trashed_order_id ) ) {
				return;
			}
			$order = wc_get_order( $trashed_order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$current_action = current_action();
			if ( 'trashed_post' === $current_action || 'delete_post' === $current_action ) {
				$is_valid_order = 0;
			} elseif ( 'untrashed_post' === $current_action ) {
				$is_valid_order = 1;
			}

			$this->om_update_valid_order_tracking( $is_valid_order, $trashed_order_id );
		}

		/**
		 * Function to update valid_order flag an order status is changed.
		 *
		 * @param int    $order_id Order ID being trashed.
		 * @param string $old_status Old order status.
		 * @param string $new_status New order status.
		 */
		public function om_update_valid_order_on_status_change( $order_id, $old_status, $new_status ) {
			if ( empty( $order_id ) ) {
				return;
			}

			if ( in_array( $new_status, array( 'refunded', 'cancelled', 'failed' ), true ) ) {
				$is_valid_order = 0;
			}

			if ( in_array( $new_status, array( 'on-hold', 'processing', 'completed' ), true ) ) {
				$is_valid_order = 1;
			}

			$this->om_update_valid_order_tracking( $is_valid_order, $order_id );
		}

		/**
		 * Function to update valid_order flag.
		 *
		 * @param int $is_valid_order Whether the order is valid or invalid.
		 * @param int $order_id Order ID.
		 */
		public function om_update_valid_order_tracking( $is_valid_order, $order_id ) {
			global $wpdb;
			$update_query = $wpdb->query( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"UPDATE {$wpdb->prefix}om_tracking_orders
										SET is_valid_order = %d
										WHERE order_id = %d",
					$is_valid_order,
					$order_id
				)
			);
		}

		/**
		 * Function to send the tracking request to server.
		 *
		 * @param string $endpoint tracking api endpoint to which the request is to be sent.
		 * @param array  $sync_data data to be synced.
		 * @return array $response_body request body.
		 */
		public function send_tracking_request( $endpoint = 'views', $sync_data = array() ) {

			if ( empty( $endpoint ) || empty( $sync_data ) ) {
				return;
			}

			$response_body = array( 'ACK' => 'Failed' );

			$response = wp_remote_post(
				esc_url_raw( SA_Offermative::$api_url . '/track/' . $endpoint ),
				array(
					'method'  => 'POST',
					'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
					'body'    => wp_json_encode(
						array(
							'client_id'     => SA_Offermative::$client_id,
							'client_secret' => SA_Offermative::$client_secret,
							'token'         => SA_Offermative::$access_token,
							'data'          => $sync_data['data'],
						)
					),
				)
			);

			if ( ! is_wp_error( $response ) ) {
				$response_body = json_decode( $response['body'], true );
				if ( ! empty( $response_body['ACK'] ) && 'Success' === $response_body['ACK'] ) {
					update_option( 'sa_om_' . $endpoint . '_last_synced_at', $sync_data['last_synced_timestamp'] + 1, 'no' );
				}
			}

			return $response_body;
		}

		/**
		 * Function to send the sync data request to server
		 */
		public function sync_data() {

			if ( empty( SA_Offermative::$access_token ) ) {
				return;
			}

			$this->sync_views();
			$this->sync_campaigns();
		}

		/**
		 * Function for fetching & tracking campaign views data to the server
		 */
		public function sync_views() {
			global $wpdb;

			$data = array();

			// Code to get last sync date.
			$last_synced_timestamp = get_option( 'sa_om_views_last_synced_at', 0 );

			// Query to fetch the data to be synced.
			$results = $wpdb->get_results( // phpcs:ignore
									$wpdb->prepare( // phpcs:ignore
												"SELECT tg.campaign_id as campaign_id,
														tg.message_id as message_id,
														tg.timestamp as timestamp,
														tg.user_id as customer_id,
														tg.user_ip_address as ip_address,
														torders.order_id as order_id,
														torders.qty as qty,
														torders.line_total as total
												FROM {$wpdb->prefix}om_tracking_general AS tg
													JOIN {$wpdb->prefix}om_tracking_orders AS torders
														ON(tg.id = torders.tracking_id
															AND tg.event = 'convert'
															AND torders.is_valid_order = 1
															AND torders.product_id = 0
															AND torders.variation_id = 0)
												WHERE tg.timestamp BETWEEN %s AND %s
												GROUP BY order_id, campaign_id, message_id",
										$last_synced_timestamp,
										time()
									),
				ARRAY_A
			);

			// Code to format the data as per server.
			if ( ! empty( $results ) ) {
				$campaign_ids          = array();
				$order_ids             = array();
				$site_url              = get_bloginfo( 'wpurl' );
				$last_synced_timestamp = 0;

				foreach ( $results as $result ) {
					$last_synced_timestamp = ( $last_synced_timestamp < $result['timestamp'] ) ? $result['timestamp'] : $last_synced_timestamp;
					$data[]                = array(
						'event'              => 'campaign.convert',
						'propertyId'         => $site_url,
						'createdAt'          => $result['timestamp'],
						'order_totalInCents' => $result['total'],
						'context'            => array(
							'campaign' => array( 'id' => $result['campaign_id'] ),
							'message'  => array( 'id' => $result['message_id'] ),
							'order'    => array(
								'id'           => $result['order_id'],
								'quantity'     => $result['qty'],
								'totalInCents' => $result['total'],
							),
							'ip'       => ( ! empty( $result['ip_address'] ) ) ? inet_ntop( $result['ip_address'] ) : '',
							'library'  => array(
								'name'    => 'Offermative',
								'version' => SA_Offermative::$plugin_version,
							),
						),
					);
					$campaign_ids[]        = $result['campaign_id'];
					$order_ids[]           = $result['order_id'];
				}

				// Code to fetch campaign data.
				if ( ! empty( $campaign_ids ) ) {
					$cids = array_unique( $campaign_ids );

					$option_nm = 'sa_om_sync_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $cids ), 'no' );

					$campaign_results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT id, params
								FROM {$wpdb->prefix}om_campaigns
								WHERE FIND_IN_SET ( id, ( SELECT option_value
													FROM {$wpdb->prefix}options
													WHERE option_name = %s ) )",
							$option_nm
						),
						ARRAY_A
					);

					if ( ! empty( $campaign_results ) ) {
						foreach ( $campaign_results as $campaign ) {
							$keys = array_keys( $campaign_ids, $campaign['id'] );

							if ( empty( $keys ) ) {
								continue;
							}

							$params = json_decode( $campaign['params'], true );

							foreach ( $keys as $key ) {
								if ( empty( $data[ $key ]['context']['campaign'] ) ) {
									$data[ $key ]['context']['campaign'] = array( 'id' => $result['campaign_id'] );
								}

								$offer_type = ( ! empty( $params['offer']['type'] ) ) ? $params['offer']['type'] : '';
								$offer_type = ( ! empty( $params['offer']['subType'] ) ) ? $params['offer']['subType'] : $offer_type;

								$data[ $key ]['context']['campaign'] = array_merge(
									$data[ $key ]['context']['campaign'],
									array(
										'offerType'     => $offer_type,
										'offerDiscount' => ( ! empty( $params['offer']['discount']['value'] ) ) ? $params['offer']['discount']['value'] : 0,
										'offerDiscountType' => ( ! empty( $params['offer']['discount']['type'] ) ) ? $params['offer']['discount']['type'] : '',
									)
								);

								$message_id = ( ! empty( $data[ $key ]['context']['message']['id'] ) ) ? $data[ $key ]['context']['message']['id'] : '';
								if ( empty( $message_id ) || empty( $params['messages'] ) ) {
									continue;
								}

								foreach ( $params['messages'] as $message ) {
									if ( $message['id'] != $message_id ) {
										continue;
									}

									$data[ $key ]['context']['message'] = array_merge(
										$data[ $key ]['context']['message'],
										array(
											'title' => ( ! empty( $message['content']['heading'] ) ) ? $message['content']['heading'] : '',
											'type'  => ( ! empty( $message['type'] ) ) ? $message['type'] : '',
											'cta'   => ( ! empty( $message['content']['cartCTA'] ) ) ? $message['content']['cartCTA'] : '',
										)
									);
								}
							}
						}
					}
					delete_option( $option_nm );
				}

				// Code to fetch order data.
				if ( ! empty( $order_ids ) ) {
					$oids = array_unique( $order_ids );

					$option_nm = 'sa_om_sync_order_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $oids ), 'no' );

					$order_results = $wpdb->get_results( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"SELECT post_id as id, 
									meta_key,
									meta_value
								FROM {$wpdb->prefix}postmeta
								WHERE meta_key IN ('_order_currency', '_cart_discount', '_billing_country')
									AND FIND_IN_SET ( post_id, ( SELECT option_value
													FROM {$wpdb->prefix}options
													WHERE option_name = %s ) )
								GROUP BY id, meta_key
								ORDER BY id",
							$option_nm
						),
						ARRAY_A
					);

					if ( ! empty( $order_results ) ) {
						$order_data   = array();
						$last_oid     = 0;
						$result_count = count( $order_results );

						foreach ( $order_results as $index => $result ) {
							$oid = $result['id'];

							// Code to transfer order meta data to $data array.
							if ( ! empty( $last_oid ) && ( $last_oid !== $oid || ( $result_count - 1 ) === $index ) ) {
								if ( ! empty( $order_data ) ) {

									$keys = array_keys( $order_ids, $last_oid );

									if ( ( $result_count - 1 ) === $index ) {
										$order_data[ $result['meta_key'] ] = $result['meta_value'];
									}

									if ( ! empty( $keys ) ) {

										$location = '';
										if ( ! empty( $order_data['_billing_country'] ) ) {
											$location = $order_data['_billing_country'];
											unset( $order_data['_billing_country'] );
										}

										foreach ( $keys as $key ) {
											if ( empty( $data[ $key ]['context']['order'] ) ) {
												$data[ $key ]['context']['order'] = array( 'id' => $last_oid );
											}

											if ( ! empty( $order_data['_order_currency'] ) ) {
												$data[ $key ]['order_currency'] = $order_data['_order_currency'];
											}

											$data[ $key ]['context']['order']    = array_merge( $data[ $key ]['context']['order'], $order_data );
											$data[ $key ]['context']['location'] = array( 'country' => $location );
										}
									}
								}
								$order_data = array();
							}

							$order_data[ $result['meta_key'] ] = $result['meta_value'];
							$last_oid                          = $oid;
						}
					}
					delete_option( $option_nm );
				}
			}

			if ( ! empty( $data ) ) {
				$this->send_tracking_request(
					'views',
					array(
						'last_synced_timestamp' => $last_synced_timestamp,
						'data'                  => $data,
					)
				);
			}
		}

		/**
		 * Function for fetching & tracking campaigns data to the server
		 */
		public function sync_campaigns() {
			global $wpdb;

			$data = array();

			// Code to get last sync date.
			$last_synced_timestamp = get_option( 'sa_om_campaigns_last_synced_at', 0 );

			$query = $wpdb->prepare( // phpcs:ignore
				"SELECT * 
				FROM {$wpdb->prefix}campaigns 
				WHERE modified_date >= %d",
				$last_synced_timestamp
			);

			// Query to fetch the data to be synced.
			$results = $wpdb->get_results( // phpcs:ignore
										$wpdb->prepare( // phpcs:ignore
											"SELECT * 
											FROM {$wpdb->prefix}om_campaigns 
											WHERE modified_date >= %d",
											$last_synced_timestamp
										),
				ARRAY_A
			);

			if ( ! empty( $results ) ) {
				$campaigns             = array();
				$last_synced_timestamp = 0;
				foreach ( $results as $result ) {
					$last_synced_timestamp = ( $last_synced_timestamp < $result['modified_date'] ) ? $result['modified_date'] : $last_synced_timestamp;
					$campaigns[]           = array(
						'id'        => ( ! empty( $result['id'] ) ) ? $result['id'] : 0,
						'parent'    => ( ! empty( $result['generated_id'] ) ) ? $result['generated_id'] : 0,
						'status'    => ( ! empty( $result['status'] ) ) ? $result['status'] : '',
						'createdAt' => ( ! empty( $result['created_date'] ) ) ? $result['created_date'] : 0,
						'startAt'   => ( ! empty( $result['start_date'] ) ) ? $result['start_date'] : 0,
						'endAt'     => ( ! empty( $result['end_date'] ) ) ? $result['end_date'] : 0,
						'params'    => ( ! empty( $result['params'] ) ) ? $result['params'] : '',
					);
				}

				if ( ! empty( $campaigns ) ) {
					$response_body = $this->send_tracking_request(
						'campaigns',
						array(
							'last_synced_timestamp' => $last_synced_timestamp,
							'data'                  => $campaigns,
						)
					);

					if ( ! empty( $response_body ) ) {
						$first_inserted_id = ( ! empty( $response_body['firstInsertedId'] ) ) ? $response_body['firstInsertedId'] : 0;
						$inserted_count    = ( ! empty( $response_body['recordsInserted'] ) ) ? $response_body['recordsInserted'] : 0;

						if ( ! empty( $first_inserted_id ) || ! empty( $inserted_count ) ) {
							$inserted_ids = array();
							foreach ( $campaigns as $campaign ) {
								if ( $first_inserted_id >= ( $inserted_count + $first_inserted_id ) ) {
									break;
								}
								if ( empty( $campaign['parent'] ) ) {
									// query to update the generated_id.
									$result = $wpdb->query( // phpcs:ignore
										$wpdb->prepare( // phpcs:ignore
											"UPDATE {$wpdb->prefix}om_campaigns
												SET generated_id = %d
												WHERE id = %d",
											$first_inserted_id,
											$campaign['id']
										)
									);
								}
								$first_inserted_id++;
							}
						}
					}
				}
			}
		}
	}

	OM_Tracking::get_instance();
}
