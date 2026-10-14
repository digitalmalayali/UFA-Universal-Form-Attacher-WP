<?php
/**
 * Global plugin settings.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Settings {

	const OPTION = 'ftgs_settings';

	public static function defaults() {
		return array(
			'send_mode'        => 'background', // background | immediate.
			'store_all'        => 1,            // Keep a copy of submissions even with no connection.
			'retention_days'   => 180,          // 0 = keep forever.
			'alert_enabled'    => 1,
			'alert_email'      => get_option( 'admin_email' ),
			'cf7_save_files'   => 1,
			'html_min_seconds' => 3,
			'html_rate_limit'  => 10,
			'delete_on_uninstall' => 0,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function save( array $input ) {
		$d   = self::defaults();
		$out = array(
			'send_mode'           => in_array( isset( $input['send_mode'] ) ? $input['send_mode'] : '', array( 'background', 'immediate' ), true ) ? $input['send_mode'] : $d['send_mode'],
			'store_all'           => empty( $input['store_all'] ) ? 0 : 1,
			'retention_days'      => max( 0, absint( isset( $input['retention_days'] ) ? $input['retention_days'] : $d['retention_days'] ) ),
			'alert_enabled'       => empty( $input['alert_enabled'] ) ? 0 : 1,
			'alert_email'         => '',
			'cf7_save_files'      => empty( $input['cf7_save_files'] ) ? 0 : 1,
			'html_min_seconds'    => min( 60, absint( isset( $input['html_min_seconds'] ) ? $input['html_min_seconds'] : $d['html_min_seconds'] ) ),
			'html_rate_limit'     => max( 1, absint( isset( $input['html_rate_limit'] ) ? $input['html_rate_limit'] : $d['html_rate_limit'] ) ),
			'delete_on_uninstall' => empty( $input['delete_on_uninstall'] ) ? 0 : 1,
		);
		$emails = array();
		foreach ( explode( ',', isset( $input['alert_email'] ) ? (string) $input['alert_email'] : '' ) as $e ) {
			$e = sanitize_email( trim( $e ) );
			if ( $e && is_email( $e ) ) {
				$emails[] = $e;
			}
		}
		$out['alert_email'] = implode( ', ', $emails );
		update_option( self::OPTION, $out, false );
		return $out;
	}
}
