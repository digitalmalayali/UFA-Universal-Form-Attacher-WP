<?php
/**
 * Public REST endpoint used by plain HTML forms (assets/frontend.js).
 * The webhook URL and secret never leave the server.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'ftgs/v1',
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function reply( $ok, $code, $error = '' ) {
		return new WP_REST_Response( $ok ? array( 'ok' => true ) : array( 'ok' => false, 'error' => $error ), $code );
	}

	public static function submit( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		$form_id = isset( $params['form'] ) ? sanitize_text_field( (string) $params['form'] ) : '';
		$fields  = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$page    = isset( $params['page'] ) ? esc_url_raw( (string) $params['page'] ) : '';
		$elapsed = isset( $params['elapsed'] ) ? (int) $params['elapsed'] : 0;

		// Honeypot: pretend success so bots learn nothing.
		if ( ! empty( $params['hp'] ) ) {
			return self::reply( true, 200 );
		}
		if ( '' === $form_id || empty( $fields ) ) {
			return self::reply( false, 400, 'empty' );
		}
		// Only accept forms that a connection is listening for.
		if ( empty( FTGS_Connections::matching( 'html', $form_id ) ) ) {
			return self::reply( false, 404, 'no_connection' );
		}
		// Must come from this site.
		if ( ! self::same_site( $request ) ) {
			return self::reply( false, 403, 'origin' );
		}
		// Humans need a few seconds to fill a form.
		$min = (int) FTGS_Settings::get( 'html_min_seconds' );
		if ( $min > 0 && $elapsed < $min * 1000 ) {
			return self::reply( true, 200 );
		}
		// Rate limit per IP.
		$key   = 'ftgs_rl_' . md5( FTGS_Queue::ip() );
		$count = (int) get_transient( $key );
		if ( $count >= (int) FTGS_Settings::get( 'html_rate_limit' ) ) {
			return self::reply( false, 429, 'rate_limited' );
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		$html = ftgs_integration( 'html' );
		if ( $html ) {
			$html->handle_rest( $form_id, $fields, $page );
		}
		return self::reply( true, 200 );
	}

	private static function same_site( WP_REST_Request $request ) {
		$home   = wp_parse_url( home_url(), PHP_URL_HOST );
		$origin = $request->get_header( 'origin' );
		if ( ! $origin ) {
			$origin = $request->get_header( 'referer' );
		}
		if ( ! $origin ) {
			return (bool) apply_filters( 'ftgs_allow_missing_origin', false );
		}
		$host  = wp_parse_url( $origin, PHP_URL_HOST );
		$hosts = apply_filters( 'ftgs_allowed_hosts', array( $home, preg_replace( '/^www\./', '', (string) $home ), 'www.' . preg_replace( '/^www\./', '', (string) $home ) ) );
		return in_array( $host, $hosts, true );
	}
}
