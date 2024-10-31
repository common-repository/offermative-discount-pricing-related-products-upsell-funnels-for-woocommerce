<?php
/**
 * Main class for Offermative Install
 *
 * @since       1.0.0
 * @version     1.0.0
 *
 * @package     offermative/includes/
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SA_Offermative_Install' ) ) {

	/**
	 *  Main SA_Offermative_Install Class.
	 *
	 * @return object of SA_Offermative_Install having Offermative install functions
	 */
	class SA_Offermative_Install {

		/**
		 * DB updates and callbacks that need to be run per version.
		 *
		 * @var array
		 */
		private static $db_updates = array(
			'1.2.0' => array(
				'update_120_alter_tables',
				'update_120_sync_options',
			),
		);

		/**
		 * Hook in tabs.
		 */
		public static function init() {
			if ( ! defined( 'DOING_AJAX' ) || true !== DOING_AJAX ) {
				add_action( 'init', array( __CLASS__, 'maybe_update_db_version' ) );
			}
		}

		/**
		 * Install OM.
		 */
		public static function install() {
			if ( ! is_blog_installed() ) {
				return;
			}

			// Check if we are not already running this routine.
			if ( 'yes' === get_transient( 'sa_om_installing' ) ) {
				return;
			}

			// If we made it till here nothing is running yet, lets set the transient now.
			set_transient( 'sa_om_installing', 'yes', MINUTE_IN_SECONDS * 10 );
			self::create_tables();
			self::maybe_update_db_version();
			delete_transient( 'sa_om_installing' );

			// Redirect to welcome screen.
			if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) { // phpcs:ignore
				set_transient( '_sa_om_activation_redirect', true, 30 );
			}
		}

		/**
		 * Get list of DB update callbacks.
		 *
		 * @return array
		 */
		public static function get_db_update_callbacks() {
			return self::$db_updates;
		}

		/**
		 * Is a DB update needed?
		 *
		 * @return boolean
		 */
		public static function needs_db_update() {
			$current_db_version = get_option( 'sa_om_db_version', null );
			$updates            = self::get_db_update_callbacks();
			$update_versions    = array_keys( $updates );
			usort( $update_versions, 'version_compare' );
			return ( ( ! is_null( $current_db_version ) && version_compare( $current_db_version, end( $update_versions ), '<' ) ) || is_null( $current_db_version ) );
		}

		/**
		 * See if we need to show or run database updates during install.
		 */
		public static function maybe_update_db_version() {
			if ( self::needs_db_update() ) {
				self::update();
			}
		}

		/**
		 * Update DB version to current.
		 *
		 * @param string|null $version New Offermative DB version or null.
		 */
		public static function update_db_version( $version = null ) {
			if ( ! empty( $version ) ) {
				update_option( 'sa_om_db_version', $version );
			}
		}

		/**
		 * Process all DB updates.
		 */
		private static function update() {

			// Check if we are not already running this routine.
			if ( 'yes' === get_transient( 'sa_om_updating' ) ) {
				return;
			}

			// If we made it till here nothing is running yet, lets set the transient now.
			set_transient( 'sa_om_updating', 'yes', MINUTE_IN_SECONDS * 10 );

			$current_db_version = get_option( 'sa_om_db_version' );

			foreach ( self::get_db_update_callbacks() as $version => $update_callbacks ) {
				if ( version_compare( $current_db_version, $version, '<' ) ) {
					foreach ( $update_callbacks as $update_callback ) {
						if ( is_callable( array( __CLASS__, $update_callback ) ) ) {
							call_user_func( array( __CLASS__, $update_callback ) );
						}
					}
					self::update_db_version( $version );
				}
			}

			delete_transient( 'sa_om_updating' );

		}

		/**
		 * Create tables required by OM plugin.
		 */
		public static function create_tables() {
			global $wpdb;

			$collate = '';

			if ( $wpdb->has_cap( 'collation' ) ) {
				$collate = $wpdb->get_charset_collate();
			}

			$tables = "CREATE TABLE {$wpdb->prefix}om_tracking_general (
							id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
							campaign_id bigint(20) UNSIGNED NOT NULL,
							message_id bigint(20) UNSIGNED NOT NULL,
							event enum('view','accept','skip','convert') NOT NULL DEFAULT 'view',
							timestamp int UNSIGNED NOT NULL,
							user_id bigint(20) UNSIGNED NOT NULL DEFAULT '0',
							user_ip_address varbinary(16) NOT NULL,
							PRIMARY KEY (id)
						) $collate;
						CREATE TABLE {$wpdb->prefix}om_tracking_orders (
							tracking_id bigint(20) UNSIGNED NOT NULL,
							order_id bigint(20) UNSIGNED NOT NULL DEFAULT '0',
							product_id bigint(20) UNSIGNED NOT NULL DEFAULT '0',
							variation_id bigint(20) UNSIGNED NOT NULL DEFAULT '0',
							qty bigint(20) UNSIGNED NOT NULL DEFAULT '0',
							line_total bigint(20) NOT NULL DEFAULT '0',
							is_valid_order tinyint(1) UNSIGNED NOT NULL DEFAULT '0',
							PRIMARY KEY (tracking_id, order_id, product_id, variation_id)
						) $collate;
						CREATE TABLE {$wpdb->prefix}om_campaigns (
							id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
							status ENUM('enabled','disabled','expired','trash') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'enabled',
							created_date int UNSIGNED NOT NULL,
							start_date int UNSIGNED NOT NULL,
							end_date int UNSIGNED NOT NULL,
							params longtext NOT NULL,
							PRIMARY KEY (id)
						) $collate;
						CREATE TABLE {$wpdb->prefix}om_fbt_temp (
							order_id bigint(20) NOT NULL,
							timestamp bigint(20) NOT NULL
						) $collate;
						CREATE TABLE {$wpdb->prefix}om_exclude_products_temp (
							product_id bigint(20) NOT NULL
						) $collate;
						";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$result = dbDelta( $tables );
		}

		/**
		 * Update tables on plugin update
		 */
		public static function update_120_alter_tables() {

			global $wpdb;

			// alter tables.
			$om_campaigns_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}om_campaigns" ); // phpcs:ignore

			if ( ! in_array( 'modified_date', $om_campaigns_cols, true ) ) {
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}om_campaigns ADD modified_date INT UNSIGNED NOT NULL AFTER created_date" );// phpcs:ignore
			}

			if ( ! in_array( 'generated_id', $om_campaigns_cols, true ) ) {
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}om_campaigns ADD generated_id BIGINT UNSIGNED NOT NULL AFTER end_date" );// phpcs:ignore
			}
		}

		/**
		 * Update sync options on plugin update
		 */
		public static function update_120_sync_options() {
			$views_last_sync = get_option( 'sa_om_last_synced_at', false );
			if ( false !== $views_last_sync ) {
				update_option( 'sa_om_views_last_synced_at', $views_last_sync, 'no' );
				delete_option( 'sa_om_last_synced_at' );
			}
		}
	}
}

SA_Offermative_Install::init();
