<?php
/**
 * Capture -> store -> deliver pipeline with background sending and retries.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Queue {

	const MAX_ATTEMPTS = 6;

	/** Seconds to wait after the Nth failed attempt. */
	const BACKOFF = array( 1 => 60, 2 => 300, 3 => 900, 4 => 3600, 5 => 21600 );

	private static $deferred   = array();
	private static $hooked     = false;
	private static $processing = false;

	public static function init() {
		add_action( FTGS_Install::QUEUE_HOOK, array( __CLASS__, 'process_due' ) );
		add_action( FTGS_Install::DAILY_HOOK, array( __CLASS__, 'daily' ) );
	}

	/**
	 * Store a submission and queue it for every matching connection.
	 *
	 * @param string $source     Integration slug.
	 * @param string $form_id    Form id.
	 * @param string $form_name  Form title.
	 * @param array  $fields     List of key/label/value.
	 * @param string $native_id  Entry id in the form plugin's own database ('' if none).
	 * @param array  $extra_meta page_url etc.
	 * @return int Submission id (0 if nothing stored).
	 */
	public static function capture( $source, $form_id, $form_name, array $fields, $native_id = '', array $extra_meta = array() ) {
		$form_id = (string) $form_id;
		$fields  = apply_filters( 'ftgs_fields', array_values( $fields ), $source, $form_id );
		if ( empty( $fields ) ) {
			return 0;
		}

		$deliveries = array();
		foreach ( FTGS_Connections::matching( $source, $form_id ) as $cid => $c ) {
			if ( FTGS_Row::passes( $c, $fields ) ) {
				$deliveries[ $cid ] = array( 's' => 'pending', 'a' => 0 );
			} else {
				$deliveries[ $cid ] = array( 's' => 'skipped', 'a' => 0, 'm' => __( 'Conditions not met', 'forms-to-google-sheets' ) );
			}
		}

		if ( empty( $deliveries ) && ! FTGS_Settings::get( 'store_all' ) ) {
			return 0;
		}

		$meta = array_merge( self::collect_meta(), array_filter( $extra_meta, 'strlen' ) );
		$uid  = '' !== (string) $native_id ? $source . '-' . $native_id : '';

		$id = FTGS_Submissions::insert(
			array(
				'uid'        => $uid,
				'source'     => $source,
				'form_id'    => $form_id,
				'form_name'  => (string) $form_name,
				'fields'     => $fields,
				'meta'       => $meta,
				'status'     => FTGS_Submissions::aggregate_status( $deliveries ),
				'deliveries' => $deliveries,
				'created_at' => $meta['submitted_at'],
			)
		);
		if ( ! $id ) {
			// Database problem: fall back to sending directly so the lead is not lost.
			self::send_without_storage( $source, $form_id, $form_name, $fields, $meta, $deliveries );
			return 0;
		}
		if ( '' === $uid ) {
			FTGS_Submissions::update( $id, array( 'uid' => $source . '-L' . $id ) );
		}

		do_action( 'ftgs_captured', $id, $source, $form_id );

		if ( in_array( 'pending', wp_list_pluck( $deliveries, 's' ), true ) ) {
			if ( 'immediate' === FTGS_Settings::get( 'send_mode' ) || self::$processing ) {
				self::process( $id );
			} else {
				self::defer( $id );
			}
		}
		return $id;
	}

	private static function collect_meta() {
		$meta = array(
			'submitted_at' => current_time( 'mysql' ),
			'page_url'     => '',
		);
		if ( ! empty( $GLOBALS['ftgs_page_url'] ) ) {
			$meta['page_url'] = esc_url_raw( $GLOBALS['ftgs_page_url'] );
			unset( $GLOBALS['ftgs_page_url'] );
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$meta['page_url'] = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		if ( self::meta_needed( 'ip' ) ) {
			$meta['ip'] = self::ip();
		}
		if ( self::meta_needed( 'user_agent' ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$meta['user_agent'] = mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 300 );
		}
		if ( self::meta_needed( 'user' ) && is_user_logged_in() ) {
			$meta['user'] = wp_get_current_user()->user_login;
		}
		return $meta;
	}

	/**
	 * Only collect personal metadata when some connection actually uses it.
	 */
	private static function meta_needed( $key ) {
		foreach ( FTGS_Connections::all() as $c ) {
			if ( $c['enabled'] && in_array( $key, (array) $c['meta'], true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function ip() {
		$keys = apply_filters( 'ftgs_ip_headers', array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) ) );
				$ip    = trim( $parts[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Background sending                                                  */
	/* ------------------------------------------------------------------ */

	private static function defer( $id ) {
		self::$deferred[] = (int) $id;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'run_deferred' ), PHP_INT_MAX );
		}
	}

	/**
	 * After the visitor already has the response, send the queued rows.
	 */
	public static function run_deferred() {
		if ( empty( self::$deferred ) ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		} elseif ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$ids            = array_unique( self::$deferred );
		self::$deferred = array();
		foreach ( $ids as $id ) {
			self::process( $id );
		}
	}

	/**
	 * WP-Cron: deliver everything that is due (new rows whose shutdown send never ran, and retries).
	 */
	public static function process_due() {
		$start = time();
		foreach ( FTGS_Submissions::due_ids( 25 ) as $id ) {
			self::process( $id );
			if ( time() - $start > 45 ) {
				break;
			}
		}
	}

	/**
	 * Deliver one submission to its pending connections.
	 *
	 * @param int  $id    Submission id.
	 * @param bool $force Ignore the retry schedule.
	 * @return array cid => result
	 */
	public static function process( $id, $force = false ) {
		$results = array();
		if ( ! FTGS_Submissions::claim( $id ) ) {
			return $results;
		}
		self::$processing = true;
		try {
			$sub = FTGS_Submissions::get( $id );
			if ( ! $sub ) {
				return $results;
			}
			$now        = time();
			$deliveries = $sub['deliveries'];

			foreach ( $deliveries as $cid => $d ) {
				if ( ! isset( $d['s'] ) || 'pending' !== $d['s'] ) {
					continue;
				}
				if ( ! $force && ! empty( $d['n'] ) && (int) $d['n'] > $now ) {
					continue;
				}
				$c = FTGS_Connections::get( $cid );
				if ( ! $c ) {
					$deliveries[ $cid ] = array( 's' => 'skipped', 'a' => (int) $d['a'], 'm' => __( 'Connection was deleted', 'forms-to-google-sheets' ) );
					continue;
				}
				if ( ! $c['enabled'] ) {
					$deliveries[ $cid ] = array( 's' => 'skipped', 'a' => (int) $d['a'], 'm' => __( 'Connection is paused', 'forms-to-google-sheets' ) );
					continue;
				}

				$row = FTGS_Row::build( $c, $sub );
				$res = FTGS_Client::send( $c, array( $row ) );

				$d['a'] = (int) $d['a'] + 1;
				$d['t'] = current_time( 'mysql' );
				$d['m'] = $res['message'];
				unset( $d['n'] );

				if ( $res['ok'] ) {
					$d['s'] = 'sent';
					$skip   = ! empty( $res['skipped'] ) && empty( $res['added'] );
					FTGS_Logger::add( $c, 'success', $skip ? __( 'Already in sheet (duplicate skipped)', 'forms-to-google-sheets' ) : $res['message'], $sub['form_name'], $id );
					do_action( 'ftgs_delivered', $c, $sub, $row );
				} elseif ( $d['a'] >= self::MAX_ATTEMPTS ) {
					$d['s'] = 'failed';
					FTGS_Logger::add( $c, 'error', sprintf( 'Gave up after %d attempts: %s', $d['a'], $res['message'] ), $sub['form_name'], $id );
					do_action( 'ftgs_delivery_failed', $c, $sub, $res['message'] );
				} else {
					$wait   = isset( self::BACKOFF[ $d['a'] ] ) ? self::BACKOFF[ $d['a'] ] : 3600;
					$d['n'] = $now + $wait;
					FTGS_Logger::add( $c, 'retry', sprintf( 'Attempt %1$d failed: %2$s – retrying in %3$s.', $d['a'], $res['message'], human_time_diff( $now, $now + $wait ) ), $sub['form_name'], $id );
				}
				$deliveries[ $cid ] = $d;
				$results[ $cid ]    = $res;
			}

			$next = null;
			foreach ( $deliveries as $d ) {
				if ( 'pending' === $d['s'] ) {
					$n    = ! empty( $d['n'] ) ? (int) $d['n'] : $now;
					$next = null === $next ? $n : min( $next, $n );
				}
			}
			FTGS_Submissions::update(
				$id,
				array(
					'deliveries' => $deliveries,
					'status'     => FTGS_Submissions::aggregate_status( $deliveries ),
					'next_try'   => null === $next ? null : wp_date( 'Y-m-d H:i:s', $next ),
				)
			);
		} catch ( \Throwable $e ) {
			FTGS_Logger::add( 'System', 'error', 'Exception while sending #' . $id . ': ' . $e->getMessage() );
		} finally {
			FTGS_Submissions::release( $id );
			self::$processing = false;
		}
		return $results;
	}

	/**
	 * Re-queue a submission for one or all connections and send it now.
	 * Connections added after the submission are included if the form matches.
	 */
	public static function resend( $id, $only_cid = '' ) {
		$sub = FTGS_Submissions::get( $id );
		if ( ! $sub ) {
			return array();
		}
		$deliveries = $sub['deliveries'];
		$targets    = $only_cid ? array( $only_cid => FTGS_Connections::get( $only_cid ) ) : FTGS_Connections::matching( $sub['source'], $sub['form_id'] );
		foreach ( $targets as $cid => $c ) {
			if ( ! $c ) {
				continue;
			}
			if ( ! $only_cid && ! FTGS_Row::passes( $c, $sub['fields'] ) ) {
				$deliveries[ $cid ] = array( 's' => 'skipped', 'a' => 0, 'm' => __( 'Conditions not met', 'forms-to-google-sheets' ) );
				continue;
			}
			$deliveries[ $cid ] = array( 's' => 'pending', 'a' => 0 );
		}
		FTGS_Submissions::update(
			$id,
			array(
				'deliveries' => $deliveries,
				'status'     => FTGS_Submissions::aggregate_status( $deliveries ),
				'next_try'   => current_time( 'mysql' ),
			)
		);
		return self::process( $id, true );
	}

	/**
	 * Last resort when the database insert fails.
	 */
	private static function send_without_storage( $source, $form_id, $form_name, $fields, $meta, $deliveries ) {
		$sub = array(
			'uid'       => $source . '-T' . time() . wp_rand( 100, 999 ),
			'source'    => $source,
			'form_id'   => $form_id,
			'form_name' => $form_name,
			'fields'    => $fields,
			'meta'      => $meta,
		);
		foreach ( $deliveries as $cid => $d ) {
			$c = FTGS_Connections::get( $cid );
			if ( $c && 'pending' === $d['s'] ) {
				$res = FTGS_Client::send( $c, array( FTGS_Row::build( $c, $sub ) ) );
				FTGS_Logger::add( $c, $res['ok'] ? 'success' : 'error', '(not stored locally: database error) ' . $res['message'], $form_name );
			}
		}
	}

	public static function daily() {
		$days = (int) FTGS_Settings::get( 'retention_days' );
		if ( $days > 0 ) {
			FTGS_Submissions::delete_older_than( $days );
		}
	}
}
