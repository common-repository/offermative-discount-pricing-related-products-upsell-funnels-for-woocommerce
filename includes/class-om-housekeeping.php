<?php
/**
 * Class for Housekeeping in Offermative
 *
 * @version     1.0.0
 *
 * @package     Offermative/includes/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'OM_Housekeeping' ) ) {

	/**
	 *  Main OM_Housekeeping Class.
	 *
	 * @return object of OM_Housekeeping having housekeeping functionality of Offermative
	 */
	class OM_Housekeeping {

		/**
		 * Variable to hold instance of Offermative
		 *
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * Get single instance of Offermative.
		 *
		 * @return OM_Housekeeping Singleton object of OM_Housekeeping
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
			// Product & variation update when updating a product or when an order is placed.
			add_action( 'woocommerce_update_product', array( $this, 'actions_on_product_update' ), 11, 2 );
			add_action( 'woocommerce_update_product_variation', array( $this, 'actions_on_product_update' ), 11, 2 );

			// Trashing a product.
			add_action( 'trashed_post', array( $this, 'actions_on_product_delete' ) );
			// For variation product deletion since varations are directly deleted instead of being trashed.
			add_action( 'woocommerce_before_delete_product_variation', array( $this, 'actions_on_product_delete' ) );
			add_action( 'woocommerce_before_delete_product', array( $this, 'actions_on_product_delete' ) );
		}

		/**
		 * Function to check when a product or variation is updated.
		 *
		 * @param int    $product_id The product/variation id updated.
		 * @param object $product The product/variation object.
		 */
		public function actions_on_product_update( $product_id, $product ) {
			$product_status     = $product->get_status();
			$product_visibility = $product->get_catalog_visibility();

			$product_managing_stock = $product->managing_stock();
			$product_in_stock       = $product->is_in_stock();
			$product_stock_status   = $product->get_stock_status();

			$product_type = $product->get_type();
			if ( 'variable' === $product_type ) {
				$variation_ids = $product->get_children();
			}

			if ( 'publish' !== $product_status || 'hidden' === $product_visibility || ( 1 === intval( $product_managing_stock ) && 1 !== intval( $product_in_stock ) ) || 'outofstock' === $product_stock_status ) {
				if ( ! empty( $variation_ids ) ) {
					foreach ( $variation_ids as $key => $value ) {
						$this->om_update_offer_status_in_campaigns( $value );
					}
				}
				$this->om_update_offer_status_in_campaigns( $product_id );
			}
		}

		/**
		 * Function to check when a product or variation is trashed
		 *
		 * @param int $trashed_post_id The product/variation id trashed.
		 */
		public function actions_on_product_delete( $trashed_post_id ) {
			if ( empty( $trashed_post_id ) ) {
				return;
			}

			$product = wc_get_product( $trashed_post_id );

			if ( $product instanceof WC_Product || $product instanceof WC_Product_Variation ) {
				$this->om_update_offer_status_in_campaigns( $trashed_post_id );
			}
		}

		/**
		 * Update offer status in the enabled campaigns.
		 *
		 * @param int $product_id The product id to search.
		 */
		public function om_update_offer_status_in_campaigns( $product_id ) {
			if ( empty( $product_id ) ) {
				return;
			}

			global $wpdb;

			$results = array();
			$results = $wpdb->get_results( // phpcs:ignore
				$wpdb->prepare( // phpcs:ignore
					"SELECT id, params
										FROM {$wpdb->prefix}om_campaigns
										WHERE status = %s
										",
					'enabled'
				),
				'ARRAY_A'
			);

			if ( ! empty( $results ) ) {
				$campaign_ids = array();
				foreach ( $results as $key => $value ) {
					$campaign_id = ( ! empty( $value['id'] ) ) ? $value['id'] : '';
					$params      = ( ! empty( $value['params'] ) ) ? json_decode( $value['params'], true ) : array();

					if ( 'product' === $params['offer']['type'] && $product_id === $params['offer']['product']['id'] && ! empty( $campaign_id ) ) {
						array_push( $campaign_ids, $campaign_id );
					}
				}

				if ( ! empty( $campaign_ids ) ) {
					$option_nm = 'sa_om_enabled_campaign_ids_' . om_get_unique_id();
					update_option( $option_nm, implode( ',', $campaign_ids ), 'no' );
					$wpdb->query( // phpcs:ignore
						$wpdb->prepare( // phpcs:ignore
							"UPDATE {$wpdb->prefix}om_campaigns
									SET status = %s
									WHERE FIND_IN_SET ( id, ( SELECT option_value
															FROM {$wpdb->prefix}options
															WHERE option_name = %s ) )",
							'disabled',
							$option_nm
						)
					);
					delete_option( $option_nm );
				}
			}

		}

	}

	OM_Housekeeping::get_instance();
}
