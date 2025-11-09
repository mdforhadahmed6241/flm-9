<?php
/**
 * Handles all REST API endpoints for the FA License Manager.
 * - /my-license/v1/activate
 * - /my-license/v1/deactivate
 * - /courier-check/v1/status
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// FIX: Ensure helper functions (Steps 3 & 7) are loaded for API calls.
// Use a relative path to be self-sufficient and avoid 500 errors.
require_once plugin_dir_path( __FILE__ ) . 'faplm-direct-courier-helpers.php';


/**
 * Register all REST API endpoints for the FA License Manager.
 * Hooks into: rest_api_init
 */
function faplm_register_api_endpoints() {
	
	// --- Step 8: Register the /activate route ---
	register_rest_route(
		'my-license/v1', // Namespace
		'/activate',     // Route
		array(
			'methods'             => 'POST', // Must be a POST request
			'callback'            => 'faplm_handle_activation_request',
			'permission_callback' => '__return_true', // Public endpoint, security is handled by the key
		)
	);

	// --- Step 9: Register the /deactivate route ---
	register_rest_route(
		'my-license/v1', // Namespace
		'/deactivate',   // Route
		array(
			'methods'             => 'POST',
			'callback'            => 'faplm_handle_deactivation_request',
			'permission_callback' => '__return_true', // Public endpoint
		)
	);

	// --- Step 12 & 4: Register the /courier-check route ---
	register_rest_route(
		'courier-check/v1', // New Namespace
		'/status',          // Route
		array(
			'methods'             => 'POST',
			'callback'            => 'faplm_handle_courier_check_request', // The "Brain" (Step 5)
			'permission_callback' => 'faplm_courier_api_permission_check', // The "Security Gate" (Step 4)
		)
	);

}
add_action( 'rest_api_init', 'faplm_register_api_endpoints' );


// --- FUNCTIONS FOR /my-license (Steps 8 & 9) ---

/**
 * Main callback function to handle license ACTIVATION requests.
 * (From Step 8)
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_activation_request( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get parameters from the request body (e.g., JSON)
	$license_key = sanitize_text_field( $request->get_param( 'license_key' ) );
	$domain      = sanitize_text_field( $request->get_param( 'domain' ) );
	
	// 2. Parameter Validation
	if ( empty( $license_key ) || empty( $domain ) ) {
		return new WP_Error(
			'missing_parameters',
			__( 'Missing required parameters: license_key and domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 3. Core License Validation Logic: Fetch the license
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	// 4. Check 1 (Exists)
	if ( ! $license ) {
		return new WP_Error(
			'invalid_key',
			__( 'The provided license key is invalid.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 5. Check 2 (Status)
	if ( 'active' !== $license->status ) {
		$error_code = 'key_not_active';
		$error_msg  = __( 'This license key is not active.', 'fa-pro-license-manager' );

		if ( 'expired' === $license->status ) {
			$error_code = 'key_expired';
			$error_msg  = __( 'This license key has expired.', 'fa-pro-license-manager' );
		}

		return new WP_Error(
			$error_code,
			$error_msg,
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 6. Check 3 (Expiration)
	if ( null !== $license->expires_at ) {
		$current_time = current_time( 'mysql' ); // Use WP local time
		
		if ( $current_time > $license->expires_at ) {
			
			// License has expired, update status in DB
			$wpdb->update(
				$table_name,
				array( 'status' => 'expired' ),
				array( 'id' => $license->id )
			);
			
			return new WP_Error(
				'key_expired',
				__( 'This license key has expired.', 'fa-pro-license-manager' ),
				array( 'status' => 403 ) // 403 Forbidden
			);
		}
	}

	// 7. Check 4 (Already Activated?)
	$activated_domains = json_decode( $license->activated_domains, true );
	
	if ( ! is_array( $activated_domains ) ) {
		$activated_domains = array();
	}

	if ( in_array( $domain, $activated_domains, true ) ) {
		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => __( 'License is already activated on this domain.', 'fa-pro-license-manager' ),
				'expires_at' => ( null === $license->expires_at ) ? 'Lifetime' : $license->expires_at,
			),
			200 // 200 OK
		);
	}

	// 8. Check 5 (Limit Reached)
	$current_activations = absint( $license->current_activations );
	$activation_limit    = absint( $license->activation_limit );

	if ( $current_activations >= $activation_limit ) {
		return new WP_Error(
			'limit_reached',
			__( 'This license key has reached its activation limit.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 9. Successful Activation Process
	$activated_domains[] = $domain;
	$new_activations_count = $current_activations + 1;

	$data_to_update = array(
		'current_activations' => $new_activations_count,
		'activated_domains'   => wp_json_encode( $activated_domains ),
	);
	$where = array( 'id' => $license->id );

	$updated = $wpdb->update( $table_name, $data_to_update, $where );

	if ( false === $updated ) {
		return new WP_Error(
			'db_error',
			__( 'Could not save activation data to the database.', 'fa-pro-license-manager' ),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	return new WP_REST_Response(
		array(
			'success'    => true,
			'message'    => __( 'License activated successfully.', 'fa-pro-license-manager' ),
			'expires_at' => ( null === $license->expires_at ) ? 'Lifetime' : $license->expires_at,
		),
		200 // 200 OK
	);
}

/**
 * Main callback function to handle license DEACTIVATION requests.
 * (From Step 9)
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_deactivation_request( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get parameters
	$license_key = sanitize_text_field( $request->get_param( 'license_key' ) );
	$domain      = sanitize_text_field( $request->get_param( 'domain' ) );
	
	// 2. Parameter Validation
	if ( empty( $license_key ) || empty( $domain ) ) {
		return new WP_Error(
			'missing_parameters',
			__( 'Missing required parameters: license_key and domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 3. Core Logic: Fetch the license
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	// 4. Check 1 (Exists)
	if ( ! $license ) {
		return new WP_Error(
			'invalid_key',
			__( 'The provided license key is invalid.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 5. Check 2 (Is it activated on this domain?)
	$activated_domains = json_decode( $license->activated_domains, true );
	
	if ( ! is_array( $activated_domains ) ) {
		$activated_domains = array();
	}

	// Search for the domain in the array
	$key = array_search( $domain, $activated_domains, true );

	if ( false === $key ) {
		// Domain was NOT found in the array
		return new WP_Error(
			'not_activated_here',
			__( 'This license is not activated on the specified domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 6. Successful Deactivation Process
	
	// Domain was found. Remove it using its key.
	unset( $activated_domains[ $key ] );

	// Re-index the array to ensure it saves as a JSON array, not an object.
	$updated_domains_array = array_values( $activated_domains );

	// Decrement the activation count, ensuring it doesn't go below zero.
	$new_activations_count = max( 0, absint( $license->current_activations ) - 1 );
	
	// Prepare data for the database update
	$data_to_update = array(
		'current_activations' => $new_activations_count,
		'activated_domains'   => wp_json_encode( $updated_domains_array ), // Save the re-indexed array
	);
	$where = array( 'id' => $license->id );

	$updated = $wpdb->update( $table_name, $data_to_update, $where );

	if ( false === $updated ) {
		// Handle potential database error
		return new WP_Error(
			'db_error',
			__( 'Could not save deactivation data to the database.', 'fa-pro-license-manager' ),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// 7. Return the final success response
	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => __( 'License deactivated successfully.', 'fa-pro-license-manager' ),
		),
		200 // 200 OK
	);
}


// --- FUNCTIONS FOR /courier-check ---

/**
 * Security check for the Courier API endpoint. (Step 4)
 * This function is hooked as the 'permission_callback'
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return bool|WP_Error True if permission is granted, WP_Error otherwise.
 */
function faplm_courier_api_permission_check( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get the Authorization header
	$auth_header = $request->get_header( 'authorization' );

	if ( empty( $auth_header ) ) {
		return new WP_Error(
			'401_unauthorized',
			'Authorization header is missing.',
			array( 'status' => 401 )
		);
	}

	// 2. Parse the "Bearer <LICENSE_KEY>" format
	$license_key = '';
	if ( sscanf( $auth_header, 'Bearer %s', $license_key ) !== 1 ) {
		return new WP_Error(
			'401_unauthorized',
			'Authorization header is malformed. Expected "Bearer <KEY>".',
			array( 'status' => 401 )
		);
	}

	if ( empty( $license_key ) ) {
		return new WP_Error(
			'401_unauthorized',
			'No license key provided in Authorization header.',
			array( 'status' => 401 )
		);
	}

	// 3. Query the database for this license key
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	$error_msg = __( 'Invalid or unauthorized license.', 'fa-pro-license-manager' );

	// 4. Perform Security Checks
	
	// Check 1: Key exists and status is 'active'
	if ( ! $license || 'active' !== $license->status ) {
		return new WP_Error( '403_forbidden', $error_msg, array( 'status' => 403 ) );
	}

	// Check 2: Expiration date (if it's not NULL)
	if ( null !== $license->expires_at ) {
		$current_time = current_time( 'mysql' ); // Use WP local time for consistency
		
		if ( $current_time > $license->expires_at ) {
			// As a courtesy, update the status to 'expired' in the DB
			$wpdb->update( $table_name, array( 'status' => 'expired' ), array( 'id' => $license->id ) );
			return new WP_Error( '403_forbidden', 'This license has expired.', array( 'status' => 403 ) );
		}
	}

	// Check 3: 'allow_courier_api' column must be 1
	if ( 1 !== (int) $license->allow_courier_api ) {
		return new WP_Error( '403_forbidden', 'This license does not have permission to access the courier API.', array( 'status' => 403 ) );
	}

	// 5. All checks passed!
	return true;
}

/**
 * Main callback for the courier-check/v1/status endpoint. (Step 5 - "The Brain")
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_courier_check_request( WP_REST_Request $request ) {
	
	// 1. Get searchTerm from the JSON body
	$params      = $request->get_json_params();
	$search_term = isset( $params['searchTerm'] ) ? sanitize_text_field( $params['searchTerm'] ) : '';

	if ( empty( $search_term ) ) {
		return new WP_Error(
			'400_bad_request',
			'searchTerm is required in the JSON body.',
			array( 'status' => 400 )
		);
	}

	// 2. Implement Caching (Cache-Check)
	$options       = get_option( 'faplm_courier_settings' );
	$cache_duration_hours = isset( $options['cache_duration'] ) ? absint( $options['cache_duration'] ) : 6;
	$cache_in_seconds     = $cache_duration_hours * HOUR_IN_SECONDS;
	
	$transient_key = 'courier_data_' . md5( $search_term );
	
	// Bypass cache if duration is 0
	if ( $cache_in_seconds > 0 ) {
		$cached_data = get_transient( $transient_key );
		
		// 3. (Cache Hit) If data is found, return it.
		if ( false !== $cached_data ) {
			// $cached_data['_cache_status'] = 'HIT'; // Removed
			return new WP_REST_Response( $cached_data, 200 );
		}
	}

	// 4. (Cache Miss) No data found in cache. Proceed to API call.
	$data_source = isset( $options['data_source'] ) ? $options['data_source'] : 'hoorin';
	$final_data  = null;

	// 5. Dispatch Logic
	if ( 'direct' === $data_source ) {
		// Call the "Direct Courier" function (Step 6)
		$final_data = faplm_fetch_direct_data_concurrently( $search_term );
	} else {
		// Default to "Hoorin" function
		$final_data = faplm_fetch_hoorin_data_round_robin( $search_term );
	}

	// 6. Handle potential errors from the fetch functions
	if ( is_wp_error( $final_data ) ) {
		return $final_data;
	}

	// 7. Finalize and Return
	if ( $cache_in_seconds > 0 ) {
		set_transient( $transient_key, $final_data, $cache_in_seconds );
	}

	// $final_data['_cache_status'] = 'MISS'; // Removed
	return new WP_REST_Response( $final_data, 200 );
}

/**
 * Fetches data from the Hoorin API using a Round-Robin key.
 *
 * @param string $search_term The phone number to search.
 * @return array|WP_Error The Hoorin JSON response as an array or a WP_Error on failure.
 */
function faplm_fetch_hoorin_data_round_robin( $search_term ) {
	
	// a. Fetch API Keys from settings
	$options = get_option( 'faplm_courier_settings' );
	$api_keys_string = isset( $options['hoorin_api_keys'] ) ? $options['hoorin_api_keys'] : '';
	
	// b. Prepare Key Array
	$api_keys = preg_split( '/\r\n|\r|\n/', $api_keys_string ); // Split by any newline
	$api_keys = array_map( 'trim', $api_keys );               // Trim whitespace from each key
	$api_keys = array_filter( $api_keys );                    // Remove any empty lines

	if ( empty( $api_keys ) ) {
		return new WP_Error(
			'no_api_keys',
			'No Hoorin API keys are configured.',
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// c. Implement Round-Robin (Get Index)
	// We use a separate option to track the index
	$current_index = absint( get_option( 'faplm_hoorin_key_index', 0 ) );

	// d. Select Key
	if ( $current_index >= count( $api_keys ) ) {
		$current_index = 0;
	}
	$key_to_use = $api_keys[ $current_index ];

	// e. Implement Round-Robin (Update Index for next request)
	$next_index = ( $current_index + 1 ) % count( $api_keys );
	update_option( 'faplm_hoorin_key_index', $next_index );

	// 5. Call the External Hoorin API
	$api_url  = 'https://dash.hoorin.com/api/courier/news.php';
	$full_url = add_query_arg(
		array(
			'apiKey'     => $key_to_use,
			'searchTerm' => $search_term,
		),
		$api_url
	);
	
	$response = wp_remote_get( $full_url, array( 'timeout' => 15 ) ); // 15 second timeout

	// 6. Error Handling (WP_Error, e.g., cURL error, DNS failure)
	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'api_call_failed',
			'The external Hoorin API call failed. ' . $response->get_error_message(),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// 7. Response Handling
	$response_body = wp_remote_retrieve_body( $response );
	$response_code = wp_remote_retrieve_response_code( $response );
	
	$data = json_decode( $response_body, true ); // true for associative array

	// 8. Process and Return Response
	if ( 200 === $response_code && is_array( $data ) ) {
		// (Successful Call)
		return $data; // Return the associative array
	} else {
		// (Failed Call - e.g., 401, 403, 500 from Hoorin API or bad JSON)
		return new WP_Error(
			'external_api_error',
			'The external Hoorin API returned an error or invalid JSON.',
			array(
				'status'        => 502, // 502 Bad Gateway
				'upstream_code' => $response_code,
				'upstream_body' => $response_body,
			)
		);
	}
}


/**
 * Fetches data from all 3 Direct Courier APIs sequentially for debugging. (Step 6)
 *
 * @param string $search_term The phone number to search.
 * @return array|WP_Error The normalized data array or a WP_Error on failure.
 */
function faplm_fetch_direct_data_concurrently( $search_term ) {
	
	// --- PRODUCTION DEBUG: TEST ALL 3 SEQUENTIALLY ---

	// 1. Retrieve Credentials
	$options    = get_option( 'faplm_courier_settings' );
	$pathao_token = isset( $options['pathao_bearer_token'] ) ? trim( $options['pathao_bearer_token'] ) : ''; 

	if ( empty( $pathao_token ) ) {
		return new WP_Error( 'pathao_token_missing', 'Pathao Bearer Token is not set.', array( 'status' => 500 ) );
	}
	
	// --- START REDEX BOT (STEP 2) ---
	$redex_session_data = faplm_get_redex_session_data();

	if ( is_wp_error( $redex_session_data ) ) {
		return $redex_session_data;
	}
	$redex_cookie = $redex_session_data->cookie_string;
	// --- END REDEX BOT ---


	// --- START STEADFAST BOT (STEP 3) ---
	$steadfast_session_data = faplm_get_steadfast_session_data();

	// Check if the bot failed (e.g., bad login, token not found)
	if ( is_wp_error( $steadfast_session_data ) ) {
		// Return the specific error from the bot (e.g., "steadfast_login_failed")
		return $steadfast_session_data;
	}

	// If successful, extract the cookie and token
	$steadfast_cookie = 'steadfast_courier_session=' . $steadfast_session_data->session_cookie_value . '; XSRF-TOKEN=' . $steadfast_session_data->xsrf_token_value;
	$steadfast_xsrf   = $steadfast_session_data->xsrf_token_value;
	// --- END STEADFAST BOT ---


	// --- 2. Pathao Logic ---
	$auth_header = '';
	if ( 0 === stripos( $pathao_token, 'Bearer ' ) ) {
		$auth_header = $pathao_token;
	} else {
		$auth_header = 'Bearer ' . $pathao_token;
	}

	$pathao_url = 'https://merchant.pathao.com/api/v1/user/success';
	$pathao_args = array(
		'method'  => 'POST',
		'headers' => array(
			'Authorization' => $auth_header, // Use the corrected auth header
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		),
		'body'    => wp_json_encode( array( 'phone' => $search_term ) ),
		'timeout' => 15,
	);
	$pathao_resp = wp_remote_post( $pathao_url, $pathao_args );
	$pathao_body = is_wp_error( $pathao_resp ) ? false : wp_remote_retrieve_body( $pathao_resp );


	// --- 3. RedEx Logic ---
	$redex_url = 'https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate';
	$redex_url_with_query = add_query_arg(
		array( 'phoneNumber' => '88' . $search_term ), // Add 88 prefix
		$redex_url
	);
	
	$redex_args = array(
		'method'  => 'GET',
		'headers' => array(
			'Cookie' => $redex_cookie,
		),
		'timeout' => 15,
	);
	$redex_resp = wp_remote_get( $redex_url_with_query, $redex_args );
	$redex_body = is_wp_error( $redex_resp ) ? false : wp_remote_retrieve_body( $redex_resp );

	
	// --- 4. Steadfast API Call Logic ---
	$steadfast_url = 'https://steadfast.com.bd/user/consignment/getbyphone/' . $search_term;
	
	$steadfast_args = array(
		'method'  => 'GET',
		'headers' => array(
			'Cookie'       => $steadfast_cookie, // Pass the session cookies
			'X-XSRF-TOKEN' => $steadfast_xsrf,   // Pass the anti-forgery token
		),
		'timeout' => 15,
	);

	$steadfast_resp = wp_remote_get( $steadfast_url, $steadfast_args );
	$steadfast_body = is_wp_error( $steadfast_resp ) ? false : wp_remote_retrieve_body( $steadfast_resp );
	// --- END STEADFAST API CALL ---


	// 5. Process and Normalize (Step 7)
	// All 3 services are now included.
	return faplm_normalize_responses(
		$steadfast_body, // Steadfast
		$pathao_body,    // Pathao
		$redex_body      // RedEx
	);
}

