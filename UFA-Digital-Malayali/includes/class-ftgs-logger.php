<?php
/**
 * Lightweight activity log (last N events kept in an option).
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Logger {

	const OPTION = 'ftgs_log';
	const MAX    = 300;

	/**
	 * @param array|string $connection Connection array or label.
	 * @param string       $status     success | retry | error | info | skipped.
	 * @param string       $message    Text.
	 * @param string       $form       Form name.
	 * @param int          $sub_id     Local submission id.
	 */
	public static function add( $connection, $status, $message, $form = '', $sub_id = 0 ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift(
			$log,
			array(
				'time'       => current_time( 'mysql' ),
				'connection' => is_array( $connection ) ? ( isset( $connection['name'] ) ? $connection['name'] : '' ) : (string) $connection,
				'status'     => (string) $status,
				'message'    => wp_strip_all_tags( (string) $message ),
				'form'       => (string) $form,
				'sub_id'     => (int) $sub_id,
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
