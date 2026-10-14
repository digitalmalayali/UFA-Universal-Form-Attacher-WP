<?php
/**
 * Email alert when a submission could not be delivered after all retries.
 * At most one email per hour; further failures in that hour are counted in the next one.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Alerts {

	const THROTTLE = 'ftgs_alert_throttle';
	const COUNTER  = 'ftgs_alert_suppressed';

	public static function init() {
		add_action( 'ftgs_delivery_failed', array( __CLASS__, 'on_failure' ), 10, 3 );
	}

	public static function on_failure( $c, $sub, $message ) {
		$s = FTGS_Settings::all();
		if ( empty( $s['alert_enabled'] ) || empty( $s['alert_email'] ) ) {
			return;
		}
		if ( get_transient( self::THROTTLE ) ) {
			update_option( self::COUNTER, (int) get_option( self::COUNTER, 0 ) + 1, false );
			return;
		}
		$suppressed = (int) get_option( self::COUNTER, 0 );
		delete_option( self::COUNTER );
		set_transient( self::THROTTLE, 1, HOUR_IN_SECONDS );

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf( /* translators: %s: site name */ __( '[%s] A form submission could not be sent to Google Sheets', 'forms-to-google-sheets' ), $site );
		$link    = admin_url( 'admin.php?page=ftgs&tab=submissions&status=failed' );
		$lines   = array(
			__( 'Forms to Google Sheets could not deliver a submission after several attempts.', 'forms-to-google-sheets' ),
			'',
			__( 'Connection:', 'forms-to-google-sheets' ) . ' ' . ( isset( $c['name'] ) ? $c['name'] : '' ),
			__( 'Form:', 'forms-to-google-sheets' ) . ' ' . ( isset( $sub['form_name'] ) ? $sub['form_name'] : '' ),
			__( 'Error:', 'forms-to-google-sheets' ) . ' ' . $message,
			'',
			__( 'The submission is saved in WordPress. Fix the problem, then click "Retry all failed" here:', 'forms-to-google-sheets' ),
			$link,
		);
		if ( $suppressed > 0 ) {
			array_splice( $lines, 6, 0, array( sprintf( /* translators: %d: number */ __( '%d more submission(s) failed since the last alert.', 'forms-to-google-sheets' ), $suppressed ), '' ) );
		}
		wp_mail( $s['alert_email'], $subject, implode( "\n", $lines ) );
	}
}
