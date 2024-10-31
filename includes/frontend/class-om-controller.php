<?php
/**
 * Main Controller class for Offermative Frontend actions
 *
 * @since       1.0.0
 * @version     1.0.0
 *
 * @package     offermative/includes/frontend
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'OM_Controller' ) ) {

	/**
	 * Main OM_Controller Class.
	 *
	 * @return object of OM_Controller having all functionality of offermative
	 */
	class OM_Controller {

		/**
		 * Variable to hold instance of offermative
		 *
		 * @var $instance
		 */
		private static $instance = null;

		/**
		 * Get single instance of offermative.
		 *
		 * @return OM_Controller Singleton object of OM_Controller
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
			add_action( 'wp', array( $this, 'detect_current_page' ) );

			if ( ! is_admin() ) {
				add_filter( 'woocommerce_login_redirect', array( $this, 'modify_woocommerce_login_redirect' ), 99, 2 );
				add_filter( 'login_redirect', array( $this, 'modify_login_redirect' ), 99, 3 );
				add_filter( 'logout_redirect', array( $this, 'modify_logout_redirect' ), 99, 3 );
				add_filter( 'logout_url', array( $this, 'modify_logout_url' ), 99, 2 );

				add_action( 'wp_head', array( $this, 'styles_and_scripts' ) );
				add_action( 'login_footer', array( $this, 'styles_and_scripts' ) );
			} else {
				add_action( 'admin_footer', array( $this, 'styles_and_scripts' ) );
			}

			add_action( 'wp_loaded', array( $this, 'maybe_apply_coupons_from_cookie' ) );
		}

		/**
		 * Detect the current page based on conditions
		 */
		public function detect_current_page() {
			if ( wp_doing_ajax() ) {
				return;
			}

			if ( ! isset( $_SESSION ) ) {
				session_start();
			}

			$page = '';

			if ( is_home() && 'post' === get_post_type() ) {
				$page = 'blog';
			} elseif ( is_product() ) {
				$page                              = 'product';
				$_SESSION['om_current_product_id'] = wc_get_product()->get_id();
			} elseif ( is_product_category() ) {
				$page           = 'product-category';
				$queried_object = get_queried_object();
				if ( ! empty( $queried_object ) && 'product_cat' === $queried_object->taxonomy ) {
					$_SESSION['om_current_product_category'] = $queried_object->term_id;
				}
			} elseif ( is_single() ) {
				$page = 'post';
			} elseif ( is_category() ) {
				$page = 'category';
			} elseif ( is_page() ) {
				$page = 'page';
			}

			$_SESSION['om_current_page'] = $page;
		}

		/**
		 * Modify login redirect URL
		 *
		 * @param string $redirect_to Redirect URL.
		 * @param string $requested_redirect_to Requested redirect URL.
		 * @param mixed  $user The user object.
		 * @return string $redirect_to
		 */
		public function modify_login_redirect( $redirect_to = '', $requested_redirect_to = '', $user = null ) {
			if ( did_action( 'wp_login' ) > 0 ) {
				$redirect_to = add_query_arg( array( 'OMUserLoggedIn' => 'yes' ), remove_query_arg( array( 'OMUserLoggedIn', 'OMUserLoggedOut' ), $redirect_to ) );
			}
			return $redirect_to;
		}

		/**
		 * Modify WooCommerce Login Redirect
		 *
		 * @param string $redirect_url The redirect URL.
		 * @param mixed  $user The user object.
		 * @return string $redirect_url
		 */
		public function modify_woocommerce_login_redirect( $redirect_url = '', $user = null ) {
			if ( did_action( 'wp_login' ) > 0 ) {
				$redirect_url = add_query_arg( array( 'OMUserLoggedIn' => 'yes' ), remove_query_arg( array( 'OMUserLoggedIn', 'OMUserLoggedOut' ), $redirect_url ) );
			}
			return $redirect_url;
		}

		/**
		 * Modify logout redirect URL
		 *
		 * @param string $redirect_to Redirect URL.
		 * @param string $requested_redirect_to Requested redirect URL.
		 * @param mixed  $user The user object.
		 * @return string $redirect_to
		 */
		public function modify_logout_redirect( $redirect_to = '', $requested_redirect_to = '', $user = null ) {
			if ( did_action( 'wp_logout' ) > 0 ) {
				$redirect_to = add_query_arg( array( 'OMUserLoggedOut' => 'yes' ), remove_query_arg( array( 'OMUserLoggedIn', 'OMUserLoggedOut' ), $redirect_to ) );
			}
			return $redirect_to;
		}

		/**
		 * Modify logout URL
		 *
		 * @param string $logout_url The logout URL.
		 * @param string $redirect The redirect URL.
		 * @return string $logout_url
		 */
		public function modify_logout_url( $logout_url = '', $redirect = '' ) {

			$url_components = wp_parse_url( html_entity_decode( $logout_url ) );
			parse_str( urldecode( $url_components['query'] ), $args );

			$redirect_url        = ( ! empty( $args['redirect_to'] ) ) ? urldecode( $args['redirect_to'] ) : home_url();
			$args['redirect_to'] = add_query_arg( array( 'OMUserLoggedOut' => 'yes' ), $redirect_url );

			$url_components['query'] = http_build_query( $args );
			$logout_url              = $this->build_url( $url_components );

			return $logout_url;
		}

		/**
		 * Function to build URL
		 *
		 * @param array $parts The URL parts.
		 * @return string
		 */
		public function build_url( $parts = array() ) {
			$scheme = isset( $parts['scheme'] ) ? ( $parts['scheme'] . '://' ) : '';

			$host = isset( $parts['host'] ) ? $parts['host'] : '';
			$port = isset( $parts['port'] ) ? ( ':' . $parts['port'] ) : '';

			$user = isset( $parts['user'] ) ? $parts['user'] : '';
			$pass = isset( $parts['pass'] ) ? ( ':' . $parts['pass'] ) : '';
			$pass = ( $user || $pass ) ? ( $pass . '@' ) : '';

			$path = isset( $parts['path'] ) ? $parts['path'] : '';

			$query = empty( $parts['query'] ) ? '' : ( '?' . $parts['query'] );

			$fragment = empty( $parts['fragment'] ) ? '' : ( '#' . $parts['fragment'] );

			return implode( '', array( $scheme, $user, $pass, $host, $port, $path, $query, $fragment ) );
		}

		/**
		 * Apply coupons from cookie if not already applied
		 */
		public function maybe_apply_coupons_from_cookie() {

			if ( ! isset( $_SESSION ) ) {
				session_start();
			}

			$cart = ( is_object( WC() ) && isset( WC()->cart ) ) ? WC()->cart : null;

			if ( empty( $cart ) || WC()->cart->is_empty() ) {
				// Code to handle display of coupon applied notice when cart is empty.
				if ( ! empty( $_SESSION['wc_notice'] ) ) {
					wc_add_notice( $_SESSION['wc_notice'] );
					unset( $_SESSION['wc_notice'] );
				}
				return;
			}

			$om_prefix = 'om_applied_coupon_profile_';

			$unique_id        = ( ! empty( $_COOKIE[ $om_prefix . 'id' ] ) ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $om_prefix . 'id' ] ) ) : ''; // phpcs:ignore
			$coupons_to_apply = ( ! empty( $unique_id ) ) ? get_transient( $om_prefix . $unique_id, array() ) : array();

			if ( ! empty( $coupons_to_apply ) ) {
				foreach ( $coupons_to_apply as $index => $code ) {
					$coupon = new WC_Coupon( $code );
					if ( ( ! WC()->cart->has_discount( $code ) ) && $coupon->is_valid() && is_callable( array( WC()->cart, 'add_discount' ) ) ) {
						WC()->cart->add_discount( $code );
						unset( $coupons_to_apply[ $index ] );
					}
				}

				set_transient( $om_prefix . $unique_id, $coupons_to_apply, DAY_IN_SECONDS );
			}
		}

		/**
		 * Additional styles & scripts
		 */
		public function styles_and_scripts() {
			$om_logged_out    = ( ! empty( $_GET['OMUserLoggedOut'] ) ) ? sanitize_text_field( wp_unslash( $_GET['OMUserLoggedOut'] ) ) : ''; // phpcs:ignore
			$om_logged_in     = ( ! empty( $_GET['OMUserLoggedIn'] ) ) ? sanitize_text_field( wp_unslash( $_GET['OMUserLoggedIn'] ) ) : ''; // phpcs:ignore
			$om_wp_logged_out = ( ! empty( $_GET['loggedout'] ) ) ? sanitize_text_field( wp_unslash( $_GET['loggedout'] ) ) : ''; // phpcs:ignore
			?>
			<script type="text/javascript">
				(() => {
					function omMaybeResetSessionVariables() {
						sessionStorage.removeItem('om_campaigns')
						sessionStorage.removeItem('om_userMeta')
						sessionStorage.removeItem('om_campaignsMeta')
						sessionStorage.removeItem('om_reco')
						sessionStorage.removeItem('om_acceptedCampaigns')
						sessionStorage.removeItem('om_acceptFailedCampaigns')
						sessionStorage.removeItem('om_shownCampaigns')
						sessionStorage.removeItem('om_skippedCampaigns')
						omMaybeRemoveUrlParams()
					}
					function omMaybeRemoveUrlParams() {
						let paramsToRemove = []
						let currentUrl     = decodeURIComponent(window.location.href)
						let url            = new URL(currentUrl)
						paramsToRemove.push('OMUserLoggedOut')
						paramsToRemove.push('OMUserLoggedIn')
						for (let i = 0; i < paramsToRemove.length; i++) {
							if (url.searchParams.has(paramsToRemove[i])) {
								url.searchParams.delete(paramsToRemove[i])
							}
						}
						window.history.pushState({}, document.title, url.href);
					}
					let omIsResetSession = '<?php echo ( ( ! empty( $om_logged_out ) && 'yes' === $om_logged_out ) || ( ! empty( $om_logged_in ) && 'yes' === $om_logged_in ) || ( ! empty( $om_wp_logged_out ) && 'true' === $om_wp_logged_out ) ) ? 'yes' : 'no'; ?>'
					if (omIsResetSession && 'yes' === omIsResetSession) {
						omMaybeResetSessionVariables()
					}
				})()
			</script>
			<?php
		}

	}

	OM_Controller::get_instance();
}
