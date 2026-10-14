<?php
/**
 * "Sync existing entries": sends old entries to a connection's sheet in batches.
 * Entry IDs make it safe to run more than once – the Apps Script skips IDs already in the sheet.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Sync {

	public static function init() {
		add_action( 'wp_ajax_ftgs_sync_step', array( __CLASS__, 'ajax_step' ) );
	}

	/**
	 * Which sync modes a connection supports.
	 *
	 * @return array mode => label
	 */
	public static function modes( array $c ) {
		$modes = array();
		$integ = ftgs_integration( $c['source'] );
		if ( 'any' !== $c['source'] && $integ && $integ->is_active() && $integ->supports_native_sync() ) {
			$modes['native'] = sprintf( /* translators: %s: plugin name */ __( 'Entries saved by %s', 'forms-to-google-sheets' ), $integ->get_label() );
		}
		$modes['local'] = __( 'Submissions saved by this plugin', 'forms-to-google-sheets' );
		return $modes;
	}

	public static function ajax_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed' ), 403 );
		}
		check_ajax_referer( 'ftgs_admin', 'nonce' );

		$c = FTGS_Connections::get( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' );
		if ( ! $c || empty( $c['webhook_url'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Save the connection with a webhook URL first.', 'forms-to-google-sheets' ) ) );
		}
		$mode   = isset( $_POST['mode'] ) && 'native' === $_POST['mode'] ? 'native' : 'local';
		$from   = self::date_arg( 'from' );
		$to     = self::date_arg( 'to' );
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$after  = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;

		try {
			$result = self::step( $c, $mode, $from, $to, $offset, $after );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		if ( ! $result['ok'] ) {
			wp_send_json_error( $result );
		}
		wp_send_json_success( $result );
	}

	private static function date_arg( $key ) {
		$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	/**
	 * Process one batch.
	 */
	public static function step( array $c, $mode, $from, $to, $offset, $after ) {
		@set_time_limit( 120 ); // phpcs:ignore
		$batch    = (int) apply_filters( 'ftgs_sync_batch_size', 50 );
		$form_ids = 'any' === $c['source'] ? array() : FTGS_Connections::form_ids( $c );
		$subs     = array();
		$local    = array();
		$has_more = false;

		if ( 'native' === $mode ) {
			$integ = ftgs_integration( $c['source'] );
			if ( ! $integ || ! $integ->is_active() || ! $integ->supports_native_sync() ) {
				return array( 'ok' => false, 'message' => __( 'This form plugin has no stored entries to sync.', 'forms-to-google-sheets' ) );
			}
			$args = array(
				'from'   => $from,
				'to'     => $to,
				'offset' => $offset,
				'limit'  => $batch,
			);
			if ( ! $integ->live_has_native_id() ) {
				$earliest = FTGS_Submissions::earliest( $c['source'] );
				if ( $earliest ) {
					$args['before'] = $earliest; // Newer ones are in our own store with different IDs.
				}
			}
			$res      = $integ->get_entries( $form_ids, $args );
			$has_more = ! empty( $res['has_more'] );
			foreach ( (array) $res['items'] as $it ) {
				$subs[] = array(
					'uid'       => $c['source'] . '-' . $it['native_id'],
					'source'    => $c['source'],
					'form_id'   => (string) $it['form_id'],
					'form_name' => (string) $it['form_name'],
					'fields'    => (array) $it['fields'],
					'meta'      => array(
						'submitted_at' => (string) $it['date'],
						'page_url'     => (string) $it['page_url'],
					),
				);
			}
			$offset += $batch;
		} else {
			$source = 'any' === $c['source'] ? null : $c['source'];
			$rows   = FTGS_Submissions::for_sync( $source, $form_ids, $from, $to, $after, $batch );
			foreach ( $rows as $r ) {
				$after   = $r['id'];
				$subs[]  = $r;
				$local[] = $r;
			}
			$has_more = count( $rows ) === $batch;
		}

		$rows     = array();
		$filtered = 0;
		foreach ( $subs as $s ) {
			if ( FTGS_Row::passes( $c, $s['fields'] ) ) {
				$rows[] = FTGS_Row::build( $c, $s );
			} else {
				++$filtered;
			}
		}

		$added   = 0;
		$skipped = 0;
		if ( $rows ) {
			$res = FTGS_Client::send( $c, $rows );
			if ( ! $res['ok'] ) {
				return array(
					'ok'      => false,
					'message' => $res['message'],
				);
			}
			$added   = (int) $res['added'];
			$skipped = (int) $res['skipped'];
		}

		// Local rows are now in the sheet: mark this connection as delivered.
		foreach ( $local as $r ) {
			if ( ! FTGS_Row::passes( $c, $r['fields'] ) ) {
				continue;
			}
			$d                  = $r['deliveries'];
			$d[ $c['id'] ]      = array(
				's' => 'sent',
				'a' => isset( $d[ $c['id'] ]['a'] ) ? (int) $d[ $c['id'] ]['a'] : 0,
				't' => current_time( 'mysql' ),
				'm' => __( 'Sent by sync', 'forms-to-google-sheets' ),
			);
			$next = null;
			foreach ( $d as $x ) {
				if ( 'pending' === $x['s'] ) {
					$next = current_time( 'mysql' );
				}
			}
			FTGS_Submissions::update( $r['id'], array( 'deliveries' => $d, 'status' => FTGS_Submissions::aggregate_status( $d ), 'next_try' => $next ) );
		}

		return array(
			'ok'        => true,
			'processed' => count( $subs ),
			'added'     => $added,
			'skipped'   => $skipped,
			'filtered'  => $filtered,
			'done'      => ! $has_more,
			'offset'    => $offset,
			'after'     => $after,
		);
	}
}
