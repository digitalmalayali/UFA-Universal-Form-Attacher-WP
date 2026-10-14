<?php
/**
 * GDPR: hooks the locally stored submissions into Tools > Export / Erase Personal Data.
 * Rows already in Google Sheets must be removed in the sheet itself.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Privacy {

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
	}

	public static function register_exporter( $exporters ) {
		$exporters['forms-to-google-sheets'] = array(
			'exporter_friendly_name' => __( 'Form submissions (Forms to Google Sheets)', 'forms-to-google-sheets' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['forms-to-google-sheets'] = array(
			'eraser_friendly_name' => __( 'Form submissions (Forms to Google Sheets)', 'forms-to-google-sheets' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Rows that really contain this email address as a field value.
	 */
	private static function rows_for( $email ) {
		$out = array();
		if ( ! is_email( $email ) ) {
			return $out;
		}
		foreach ( FTGS_Submissions::find_by_text( $email ) as $r ) {
			foreach ( $r['fields'] as $f ) {
				if ( 0 === strcasecmp( trim( FTGS_Row::flatten( isset( $f['value'] ) ? $f['value'] : '', false ) ), $email ) ) {
					$out[] = $r;
					break;
				}
			}
		}
		return $out;
	}

	public static function export( $email, $page = 1 ) {
		$data = array();
		foreach ( self::rows_for( $email ) as $r ) {
			$items = array(
				array( 'name' => __( 'Form', 'forms-to-google-sheets' ), 'value' => $r['form_name'] ),
				array( 'name' => __( 'Submitted', 'forms-to-google-sheets' ), 'value' => $r['created_at'] ),
			);
			foreach ( $r['fields'] as $f ) {
				$items[] = array( 'name' => $f['label'], 'value' => FTGS_Row::flatten( $f['value'], false ) );
			}
			$data[] = array(
				'group_id'    => 'ftgs-submissions',
				'group_label' => __( 'Form submissions', 'forms-to-google-sheets' ),
				'item_id'     => 'ftgs-' . $r['id'],
				'data'        => $items,
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( $email, $page = 1 ) {
		$rows = self::rows_for( $email );
		$n    = FTGS_Submissions::delete( wp_list_pluck( $rows, 'id' ) );
		return array(
			'items_removed'  => $n > 0,
			'items_retained' => false,
			'messages'       => $n > 0 ? array( __( 'Form submissions were deleted from WordPress. Rows already sent to Google Sheets must be deleted in the sheet.', 'forms-to-google-sheets' ) ) : array(),
			'done'           => true,
		);
	}

	public static function policy_text() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Forms to Google Sheets', 'forms-to-google-sheets' ),
				wp_kses_post( wpautop( __( 'When you submit a form on this site, the information you enter is stored on this website and sent to a Google Sheet operated by the site owner (Google LLC processes this data). Depending on settings, the page address, your IP address and browser user agent may be included.', 'forms-to-google-sheets' ) ) )
			);
		}
	}
}
