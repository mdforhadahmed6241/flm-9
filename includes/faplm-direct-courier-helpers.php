<?php
/**
 * Helper functions for the Direct Courier API logic.
 * - Data Normalization (faplm_normalize_responses)
 * - Steadfast Bot (faplm_get_steadfast_session_data)
 * - RedEx Bot (faplm_get_redex_session_data)
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * =================================================================
 * STEP 3: STEADFAST AUTOMATION BOT
 * =================================================================
 */

/**
 * Automatically logs into Steadfast to fetch and cache a valid session.
 *
 * @return object|WP_Error An object containing {session_cookie, xsrf_token} on success,
 * or WP_Error on failure.
 */
function faplm_get_steadfast_session_data() {

	// 1. Check for Cached Session
	$cached_session = get_transient( 'steadfast_session_data' );
	if ( $cached_session ) {
		return $cached_session;
	}

	// 2. Handle Cache Miss: Run the Bot
	$login_url = 'https://steadfast.com.bd/login';

	// --- Part A: GET Request (Get _token and initial cookies) ---
	$get_response = wp_remote_get( $login_url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $get_response ) ) {
		return new WP_Error( 'steadfast_get_failed', 'Failed to GET Steadfast login page.', $get_response->get_error_message() );
	}

	$initial_cookies = wp_remote_retrieve_cookies( $get_response );
	$html_body       = wp_remote_retrieve_body( $get_response );

	// Parse the _token from the HTML
	$token = '';
	if ( preg_match( '/<input[^>]+name="_token"[^>]+value="([^"]+)"[^>]*>/i', $html_body, $matches ) ) {
		$token = $matches[1];
	}

	if ( empty( $token ) ) {
		return new WP_Error( 'steadfast_token_not_found', 'Could not find _token on Steadfast login page.' );
	}

	// --- Part B: POST Request (Attempt Login) ---

	// Retrieve saved credentials
	$options  = get_option( 'faplm_courier_settings' );
	$email    = isset( $options['steadfast_email'] ) ? $options['steadfast_email'] : '';
	$password = isset( $options['steadfast_password'] ) ? $options['steadfast_password'] : '';

	if ( empty( $email ) || empty( $password ) ) {
		return new WP_Error( 'steadfast_no_creds', 'Steadfast email or password is not set in settings.' );
	}

	$post_args = array(
		'timeout' => 15,
		'body'    => array(
			'_token'   => $token,
			'email'    => $email,
			'password' => $password,
		),
		'cookies' => $initial_cookies, // Pass back the cookies we just received
	);

	$post_response = wp_remote_post( $login_url, $post_args );

	if ( is_wp_error( $post_response ) ) {
		return new WP_Error( 'steadfast_post_failed', 'Failed to POST to Steadfast login page.', $post_response->get_error_message() );
	}

	// --- Part C: Handle Response (Parse Cookies) ---
	$set_cookie_headers = wp_remote_retrieve_header( $post_response, 'set-cookie' );

	if ( empty( $set_cookie_headers ) ) {
		return new WP_Error( 'steadfast_login_failed', 'Login to Steadfast failed. No session cookies were set. (Check credentials)' );
	}

	if ( ! is_array( $set_cookie_headers ) ) {
		$set_cookie_headers = array( $set_cookie_headers );
	}

	$session_cookie = '';
	$xsrf_token     = '';

	foreach ( $set_cookie_headers as $cookie_string ) {
		if ( preg_match( '/steadfast_courier_session=([^;]+);/', $cookie_string, $session_match ) ) {
			$session_cookie = $session_match[1];
		}
		if ( preg_match( '/XSRF-TOKEN=([^;]+);/', $cookie_string, $xsrf_match ) ) {
			$xsrf_token = $xsrf_match[1];
		}
	}

	if ( empty( $session_cookie ) || empty( $xsrf_token ) ) {
		return new WP_Error( 'steadfast_cookie_parse_failed', 'Login to Steadfast succeeded, but could not parse required session cookies.' );
	}

	// --- Part D: Cache and Return ---
	$session_data = (object) array(
		'session_cookie_value' => $session_cookie,
		'xsrf_token_value'     => $xsrf_token,
	);

	// Cache the new session data for 3 hours
	set_transient( 'steadfast_session_data', $session_data, 3 * HOUR_IN_SECONDS );

	return $session_data;
}


/**
 * =================================================================
 * STEP 2: REDEX AUTOMATION BOT (UPDATED)
 * =================================================================
 */

/**
 * Automatically logs into RedEx to fetch and cache a valid session.
 *
 * @return object|WP_Error An object containing {cookie_string} on success,
 * or WP_Error on failure.
 */
function faplm_get_redex_session_data() {

	// 1. Check for Cached Session
	$cached_session = get_transient( 'redex_session_data' );
	if ( $cached_session ) {
		return $cached_session;
	}

	// --- Handle Cache Miss: Run the Bot ---
	$login_url = 'https://api.redx.com.bd/v4/auth/login';

	// --- Part A: Initial GET Request (REMOVED) ---
	// This strategy failed because the server returns a JS-rendered page
	// and wp_remote_get cannot execute JS to get the cookies.
	// We will now try a direct POST, assuming the login API itself
	// will provide the necessary session cookie.

	// --- Part B: POST Request (Attempt Login) ---

	// Retrieve saved credentials
	$options  = get_option( 'faplm_courier_settings' );
	$phone    = isset( $options['redex_phone'] ) ? $options['redex_phone'] : '';
	$password = isset( $options['redex_password'] ) ? $options['redex_password'] : '';

	if ( empty( $phone ) || empty( $password ) ) {
		return new WP_Error( 'redex_no_creds', 'RedEx phone or password is not set in settings.' );
	}

	// Build the comprehensive headers
	$post_headers = array(
		'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		'Origin'       => 'https://redx.com.bd',
		'Referer'      => 'https://redx.com.bd/',
		'Content-Type' => 'application/json;charset=UTF-8',
		// NO 'Cookie' header is sent on the *initial* login.
		// We expect to *receive* cookies from this request.
	);

	// Build the JSON payload
	$post_body = wp_json_encode(
		array(
			'phone'    => $phone,
			'password' => $password,
		)
	);

	$post_args = array(
		'method'  => 'POST',
		'timeout' => 15,
		'headers' => $post_headers,
		'body'    => $post_body,
	);

	$post_response = wp_remote_post( $login_url, $post_args );

	// --- Part C: Handle Login Response ---
	if ( is_wp_error( $post_response ) ) {
		return new WP_Error( 'redex_post_failed', 'Failed to POST to RedEx login API.', $post_response->get_error_message() );
	}

	$response_body = wp_remote_retrieve_body( $post_response );
	$response_code = wp_remote_retrieve_response_code( $post_response );
	$data          = json_decode( $response_body );

	// Check if login was *actually* successful by looking for the accessToken
	if ( $response_code !== 200 || ! isset( $data->data->accessToken ) ) {
		$error_message = 'RedEx login failed.';
		if ( isset( $data->message ) ) {
			$error_message = 'RedEx API Error: ' . $data->message;
		} elseif ( ! empty( $response_body ) ) {
			$error_message = 'RedEx login failed. Raw response: ' . wp_strip_all_tags( $response_body );
		}
		return new WP_Error( 'redex_login_unsuccessful', $error_message, $response_body );
	}

	// Login was successful. Now, extract the *final* session cookie.
	$final_cookie_headers = wp_remote_retrieve_header( $post_response, 'set-cookie' );

	if ( empty( $final_cookie_headers ) ) {
		return new WP_Error( 'redex_no_final_cookie', 'RedEx login succeeded, but no final session cookie was set.' );
	}

	if ( ! is_array( $final_cookie_headers ) ) {
		$final_cookie_headers = array( $final_cookie_headers );
	}

	$final_cookie_string = '';
	foreach ( $final_cookie_headers as $cookie_string ) {
		// Find the specific session cookie
		if ( preg_match( '/^__ti__=s%3A([^;]+);/', $cookie_string, $cookie_match ) ) {
			$final_cookie_string = '__ti__=s%3A' . $cookie_match[1];
			break; // Found it
		}
	}

	if ( empty( $final_cookie_string ) ) {
		return new WP_Error( 'redex_cookie_parse_failed', 'Could not parse final session cookie from RedEx login response.' );
	}

	// --- Part D: Cache and Return ---
	$session_data = (object) array(
		'cookie_string' => $final_cookie_string,
	);

	// Cache the new session data for 3 hours
	set_transient( 'redex_session_data', $session_data, 3 * HOUR_IN_SECONDS );

	return $session_data;
}


/**
 * =================================================================
 * STEP 7: DATA NORMALIZATION
 * =================================================================
 */

/**
 * Normalizes the unique JSON responses from Steadfast, Pathao, and RedEx
 * into a single, consistent array matching the Hoorin 'Summaries' format.
 *
 * @param string|false $steadfast_json The raw JSON string from Steadfast, or false on failure.
 * @param string|false $pathao_json    The raw JSON string from Pathao, or false on failure.
 * @param string|false $redex_json     The raw JSON string from RedEx, or false on failure.
 *
 * @return array The final data formatted in the 'Summaries' structure.
 */
function faplm_normalize_responses( $steadfast_json, $pathao_json, $redex_json ) {

	// 1. Initialize the final "Summaries" array (Hoorin format).
	$summaries = array(
		'Summaries' => array(
			'Steadfast' => array(
				'Total Parcels'     => 0,
				'Delivered Parcels' => 0,
				'Canceled Parcels'  => 0,
			),
			'RedX'      => array(
				'Total Parcels'     => 0,
				'Delivered Parcels' => 0,
				'Canceled Parcels'  => 0,
			),
			'Pathao'    => array(
				'Total Delivery'      => 0,
				'Successful Delivery' => 0,
				'Canceled Delivery'   => 0,
			),
		),
	);

	// 2. Process Steadfast Data
	$steadfast_data = json_decode( $steadfast_json );
	if ( $steadfast_data && is_object( $steadfast_data ) && isset( $steadfast_data->total_delivered ) && isset( $steadfast_data->total_cancelled ) ) {
		$delivered = (int) $steadfast_data->total_delivered;
		$canceled  = (int) $steadfast_data->total_cancelled;
		$total     = $delivered + $canceled;

		$summaries['Summaries']['Steadfast'] = array(
			'Total Parcels'     => $total,
			'Delivered Parcels' => $delivered,
			'Canceled Parcels'  => $canceled,
		);
	}

	// 3. Process RedEx Data
	// Expected: {"data": {"totalParcels": "10", "deliveredParcels": "9", ...}}
	$redex_data = json_decode( $redex_json );
	if ( $redex_data && is_object( $redex_data ) && isset( $redex_data->data->totalParcels ) && isset( $redex_data->data->deliveredParcels ) ) {
		$total     = (int) $redex_data->data->totalParcels;
		$delivered = (int) $redex_data->data->deliveredParcels;
		$canceled  = $total - $delivered; // Calculate canceled

		$summaries['Summaries']['RedX'] = array(
			'Total Parcels'     => $total,
			'Delivered Parcels' => $delivered,
			'Canceled Parcels'  => $canceled,
		);
	}

	// 4. Process Pathao Data
	$pathao_data = json_decode( $pathao_json );
	if ( $pathao_data && is_object( $pathao_data ) && isset( $pathao_data->data->customer->total_delivery ) && isset( $pathao_data->data->customer->successful_delivery ) ) {
		$total     = (int) $pathao_data->data->customer->total_delivery;
		$delivered = (int) $pathao_data->data->customer->successful_delivery;
		$canceled  = $total - $delivered; // Calculate canceled

		$summaries['Summaries']['Pathao'] = array(
			'Total Delivery'      => $total,
			'Successful Delivery' => $delivered,
			'Canceled Delivery'   => $canceled,
		);
	}

	// 5. Return the final, normalized array.
	return $summaries;
}

