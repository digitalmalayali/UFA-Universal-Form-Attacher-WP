<?php
/**
 * HTTP client for the Apps Script webhook.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Client {

	const SCRIPT_VERSION = 2;

	/**
	 * Send one or more rows.
	 *
	 * @param array $c    Connection.
	 * @param array $rows List of ordered column => value arrays.
	 * @return array ok(bool), message, added, skipped, version
	 */
	public static function send( array $c, array $rows ) {
		$rows    = array_values( $rows );
		$columns = array();
		foreach ( $rows as $r ) {
			foreach ( array_keys( $r ) as $k ) {
				$columns[ (string) $k ] = true;
			}
		}
		$payload = array(
			'version'   => self::SCRIPT_VERSION,
			'secret'    => (string) $c['secret'],
			'sheet'     => '' !== (string) $c['sheet'] ? (string) $c['sheet'] : 'Sheet1',
			'id_column' => FTGS_Row::ID_COLUMN,
			'columns'   => array_keys( $columns ),
			'rows'      => $rows,
			'site'      => home_url(),
		);
		if ( 1 === count( $rows ) ) {
			$payload['data'] = $rows[0]; // Backwards compatible with the v1 Apps Script.
		}
		$payload = apply_filters( 'ftgs_payload', $payload, $c );

		$result = self::post( $c['webhook_url'], $payload );

		if ( $result['ok'] && null === $result['version'] && count( $rows ) > 1 ) {
			$result['ok']      = false;
			$result['message'] = __( 'Your Apps Script is the old version and cannot receive batches. Copy the new script from the connection screen and deploy a new version.', 'forms-to-google-sheets' );
		} elseif ( $result['ok'] && null === $result['version'] ) {
			$result['message'] .= ' ' . __( '(Old Apps Script: update it to enable duplicate protection.)', 'forms-to-google-sheets' );
			$result['added']    = 1;
		}
		if ( ! $result['ok'] && false !== stripos( $result['message'], 'Invalid secret' ) ) {
			$result['message'] .= ' ' . sprintf(
				/* translators: %s: last 4 characters of the secret */
				__( '– the deployed Apps Script does not contain this connection\'s secret (ends in "…%s"). Re-copy the script from the connection screen, save it, then Deploy > Manage deployments > Edit > Version: New version > Deploy.', 'forms-to-google-sheets' ),
				substr( (string) $c['secret'], -4 )
			);
		}
		return $result;
	}

	/**
	 * POST JSON. Apps Script answers with a 302 to googleusercontent.com, which we
	 * follow ourselves with GET (re-POSTing would fail).
	 */
	public static function post( $url, array $payload ) {
		$out = array(
			'ok'      => false,
			'message' => '',
			'added'   => 0,
			'skipped' => 0,
			'version' => null,
		);

		if ( ! wp_http_validate_url( $url ) && 0 !== strpos( $url, 'http://localhost' ) && 0 !== strpos( $url, 'http://127.0.0.1' ) ) {
			$out['message'] = __( 'The webhook URL is not valid.', 'forms-to-google-sheets' );
			return $out;
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => (int) apply_filters( 'ftgs_http_timeout', 30 ),
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$out['message'] = $response->get_error_message();
			return $out;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $location ) {
				$out['message'] = 'HTTP ' . $code . ' without Location header.';
				return $out;
			}
			$follow = wp_remote_get( $location, array( 'timeout' => 20 ) );
			if ( is_wp_error( $follow ) ) {
				// doPost already ran; we just could not read its answer.
				$out['ok']      = true;
				$out['message'] = __( 'Delivered (response could not be read).', 'forms-to-google-sheets' );
				return $out;
			}
			$response = $follow;
			$code     = (int) wp_remote_retrieve_response_code( $response );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( is_array( $json ) && isset( $json['status'] ) ) {
			$out['ok']      = 'success' === $json['status'];
			$out['message'] = isset( $json['message'] ) ? (string) $json['message'] : (string) $json['status'];
			$out['added']   = isset( $json['added'] ) ? (int) $json['added'] : 0;
			$out['skipped'] = isset( $json['skipped'] ) ? (int) $json['skipped'] : 0;
			$out['version'] = isset( $json['version'] ) ? (int) $json['version'] : null;
			return $out;
		}

		if ( in_array( $code, array( 401, 403 ), true ) || false !== stripos( $body, 'You need access' ) ) {
			$out['message'] = 'HTTP ' . $code . ': ' . __( 'Google refused access to the Apps Script. In Apps Script open Deploy > Manage deployments > Edit, set "Who has access" to "Anyone" (not "Anyone with Google account"), deploy a new version, and use the URL ending in /exec (not /dev).', 'forms-to-google-sheets' );
			return $out;
		}
		if ( false !== stripos( $body, 'accounts.google.com' ) ) {
			$out['message'] = __( 'Google returned a sign-in page instead of running the script. Set "Who has access" to "Anyone" and deploy a new version.', 'forms-to-google-sheets' );
			return $out;
		}
		if ( 404 === $code ) {
			$out['message'] = 'HTTP 404: ' . __( 'Webhook URL not found. Check you copied the full Web app URL ending in /exec.', 'forms-to-google-sheets' );
			return $out;
		}
		if ( false !== stripos( $body, 'Script function not found: doPost' ) ) {
			$out['message'] = __( 'The deployed script has no doPost function. Paste the plugin\'s script and deploy a new version.', 'forms-to-google-sheets' );
			return $out;
		}

		if ( false !== strpos( $url, 'script.google.com' ) ) {
			$out['message'] = 'HTTP ' . $code . ': ' . __( 'Apps Script did not return the expected answer:', 'forms-to-google-sheets' ) . ' ' . trim( html_entity_decode( wp_html_excerpt( wp_strip_all_tags( $body ), 250 ) ) );
			return $out;
		}

		// Some other webhook (Make, Zapier, n8n…) that answers 2xx without our JSON.
		$out['ok']      = $code >= 200 && $code < 300;
		$out['message'] = 'HTTP ' . $code . ( $out['ok'] ? '' : ' ' . trim( html_entity_decode( wp_html_excerpt( wp_strip_all_tags( $body ), 200 ) ) ) );
		if ( $out['ok'] ) {
			$out['version'] = self::SCRIPT_VERSION; // Treat generic webhooks as batch-capable.
			$out['added']   = count( $payload['rows'] );
		}
		return $out;
	}
}
