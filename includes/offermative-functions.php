<?php
/**
 * Some common functions for Offermative
 *
 * @since       1.0.0
 * @version     1.0.1
 *
 * @package     offermative/includes/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if the string is JSON or not
 *
 * @param  string $string The string to be checked.
 * @return boolean
 */
function om_is_json( $string ) {
	json_decode( $string );
	return ( json_last_error() === JSON_ERROR_NONE );
}

/**
 * Get Product Titles
 *
 * @param  array $source The source array.
 * @param  array $new The array to be merged.
 * @return array
 */
function om_recurrsive_array_merge( $source, $new ) {
	array_walk(
		$source,
		function( &$value, $key, $new ) {
			$value = array_unique( array_merge( $value, $new[ $key ] ) );
		},
		$new
	);

	return $source;
}

/**
 * To generate unique id
 *
 * Credit: WooCommerce
 */
function om_generate_unique_id() {

	require_once ABSPATH . 'wp-includes/class-phpass.php';
	$hasher = new PasswordHash( 8, false );
	return md5( $hasher->get_random_bytes( 32 ) );

}

/**
 * Generate & get an unique id
 *
 * @return string
 */
function om_get_unique_id() {
	$user_id = get_current_user_id();
	if ( empty( $user_id ) ) {
		$user_id = ( ! empty( $_COOKIE['PHPSESSID'] ) ) ? sanitize_text_field( wp_unslash( $_COOKIE['PHPSESSID'] ) ) : ''; // phpcs:ignore
		if ( ! empty( $user_id ) && 100 < strlen( $user_id ) ) {
			$user_id = substr( $user_id, 0, 100 );
		}
	}
	$unique_id = uniqid( $user_id, true );
	return $unique_id;
}

/**
 * Get Product Titles
 *
 * @param  array $ids The ids.
 * @return array
 */
function om_get_product_titles( $ids = array() ) {
	global $wpdb;
	if ( ! is_array( $ids ) ) {
		$ids = array( $ids );
	}
	$option_nm = 'sa_om_campaign_product_ids_' . om_get_unique_id();
	update_option( $option_nm, implode( ',', $ids ), 'no' );
	$title_results  = $wpdb->get_results( // phpcs:ignore
		$wpdb->prepare( // phpcs:ignore
			"SELECT ID, post_title
                FROM {$wpdb->posts}
                WHERE FIND_IN_SET ( ID, ( SELECT option_value
											FROM {$wpdb->prefix}options
											WHERE option_name = %s ) )",
			$option_nm
		),
		'ARRAY_A'
	);
	delete_option( $option_nm );
	$product_titles = ( ! empty( $title_results ) ) ? wp_list_pluck( $title_results, 'post_title', 'ID' ) : array();
	$product_titles = ( ! empty( $product_titles ) ) ? array_map( 'wp_specialchars_decode', $product_titles ) : array();
	return $product_titles;
}

/**
 * Get Category Data
 *
 * @param  array   $ids The ids.
 * @param  boolean $all_data Flag to determine the data to be returned.
 *
 * @return array $category_data Array of category data
 */
function om_get_category_data( $ids = array(), $all_data = false ) {
	global $wpdb;

	$category_data = array();

	if ( ! is_array( $ids ) ) {
		$ids = array( $ids );
	}
	$option_nm = 'sa_om_campaign_category_ids_' . om_get_unique_id();
	update_option( $option_nm, implode( ',', $ids ), 'no' );

	if ( $all_data ) {
		$results = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( // phpcs:ignore
				"SELECT t.term_id AS id,
                                                    t.name AS name,
                                                    tt.description AS description
                                                FROM {$wpdb->terms} AS t
                                                    JOIN {$wpdb->term_taxonomy} AS tt
                                                        ON( tt.term_id = t.term_id
                                                            AND tt.taxonomy = 'product_cat')
                                                WHERE FIND_IN_SET ( t.term_id, ( SELECT option_value
																					FROM {$wpdb->prefix}options
																					WHERE option_name = %s ) )",
				$option_nm
			),
			'ARRAY_A'
		);

		if ( count( $results ) > 0 ) {
			foreach ( $results as $result ) {
				$category_data[ $result['id'] ] = array(
					'name'        => esc_html( ( ! empty( $result['name'] ) ) ? $result['name'] : '' ),
					'description' => esc_html( ( ! empty( $result['description'] ) ) ? $result['description'] : '' ),
					'permalink'   => get_term_link( (int) ( ( ! empty( $result['id'] ) ) ? $result['id'] : 0 ), 'product_cat' ),
				);
			}
		}
	} else {
		$title_results = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare( // phpcs:ignore
				"SELECT term_id, name
                                                    FROM {$wpdb->terms}
                                                    WHERE FIND_IN_SET ( term_id, ( SELECT option_value
																					FROM {$wpdb->prefix}options
																					WHERE option_name = %s ) )",
				$option_nm
			),
			'ARRAY_A'
		);
		$category_data = ( ! empty( $title_results ) ) ? wp_list_pluck( $title_results, 'name', 'term_id' ) : array();
		$category_data = ( ! empty( $category_data ) ) ? array_map( 'wp_specialchars_decode', $category_data ) : array();

	}
	delete_option( $option_nm );
	return $category_data;
}

/**
 * Function to sanitize the non global PHP variables
 *
 * @param  string|array $value value to be sanitized.
 *
 * @return string|array sanitized string or array of sanitized values
 */
function om_sanitize_field( $value = '' ) {

	if ( is_array( $value ) ) {
		return array_map(
			function ( $request_param ) {
				return om_sanitize_field( $request_param );
			},
			$value
		);
	} else {
		return sanitize_text_field( wp_unslash( $value ) );
	}
}

/**
 * Function to check if remote file exists
 *
 * @param  string|array $url remote file URL to check.
 *
 * @return boolean flag whether the file exists or not.
 */
function om_remote_file_exists( $url ) {

	if ( empty( $url ) ) {
		return false;
	}

	$response = wp_remote_head( $url );
	if ( 200 === wp_remote_retrieve_response_code( $response ) ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Function to get count of active campaigns
 *
 * @return int active campaigns count.
 */
function om_get_active_campaigns_count() {

	global $wpdb;

	return $wpdb->get_var( // phpcs:ignore
		$wpdb->prepare( // phpcs:ignore
			"SELECT IFNULL(COUNT( DISTINCT id ), 0) as count
			FROM {$wpdb->prefix}om_campaigns
			WHERE status = %s
				AND start_date <= %d
				AND end_date > %d",
			'enabled',
			time(),
			time()
		)
	);
}

/**
 * Function to get max count of allowed active campaigns
 *
 * @return int max active campaigns count.
 */
function om_get_max_active_campaigns_count() {

	if ( empty( SA_Offermative::$access_token ) ) {
		return 0;
	}

	$count = get_transient( 'sa_om_max_active_campaigns_count' );
	return ( ! empty( $count ) ) ? $count : 99999;
}


