<?php
/**
 * Local copy of every submission + per-connection delivery state.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Submissions {

	public static function insert( array $row ) {
		global $wpdb;
		$data = array(
			'uid'        => isset( $row['uid'] ) ? (string) $row['uid'] : '',
			'source'     => (string) $row['source'],
			'form_id'    => (string) $row['form_id'],
			'form_name'  => mb_substr( (string) $row['form_name'], 0, 255 ),
			'fields'     => wp_json_encode( isset( $row['fields'] ) ? array_values( $row['fields'] ) : array() ),
			'meta'       => wp_json_encode( isset( $row['meta'] ) ? $row['meta'] : array() ),
			'status'     => isset( $row['status'] ) ? $row['status'] : 'pending',
			'deliveries' => wp_json_encode( isset( $row['deliveries'] ) ? (object) $row['deliveries'] : new stdClass() ),
			'next_try'   => isset( $row['next_try'] ) ? $row['next_try'] : null,
			'created_at' => isset( $row['created_at'] ) ? $row['created_at'] : current_time( 'mysql' ),
		);
		$ok = $wpdb->insert( FTGS_Install::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function get( $id ) {
		global $wpdb;
		$t   = FTGS_Install::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		return $row ? self::decode( $row ) : null;
	}

	public static function decode( array $row ) {
		$row['id']         = (int) $row['id'];
		$row['fields']     = json_decode( (string) $row['fields'], true );
		$row['meta']       = json_decode( (string) $row['meta'], true );
		$row['deliveries'] = json_decode( (string) $row['deliveries'], true );
		foreach ( array( 'fields', 'meta', 'deliveries' ) as $k ) {
			if ( ! is_array( $row[ $k ] ) ) {
				$row[ $k ] = array();
			}
		}
		return $row;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		foreach ( array( 'fields', 'meta' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				$data[ $k ] = wp_json_encode( $data[ $k ] );
			}
		}
		if ( isset( $data['deliveries'] ) && is_array( $data['deliveries'] ) ) {
			$data['deliveries'] = wp_json_encode( (object) $data['deliveries'] );
		}
		return false !== $wpdb->update( FTGS_Install::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore
	}

	/**
	 * Atomically lock a row so two processes never send it twice.
	 */
	public static function claim( $id, $seconds = 120 ) {
		global $wpdb;
		$t     = FTGS_Install::table();
		$now   = current_time( 'mysql' );
		$until = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $seconds ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$rows  = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET locked_until = %s WHERE id = %d AND (locked_until IS NULL OR locked_until < %s)", $until, $id, $now ) ); // phpcs:ignore
		return 1 === (int) $rows;
	}

	public static function release( $id ) {
		global $wpdb;
		$t = FTGS_Install::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET locked_until = NULL WHERE id = %d", $id ) ); // phpcs:ignore
	}

	public static function due_ids( $limit = 25 ) {
		global $wpdb;
		$t = FTGS_Install::table();
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'pending' AND (next_try IS NULL OR next_try <= %s) ORDER BY id ASC LIMIT %d", current_time( 'mysql' ), $limit ) ) ); // phpcs:ignore
	}

	public static function delete( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$t = FTGS_Install::table();
		return (int) $wpdb->query( "DELETE FROM {$t} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore
	}

	public static function delete_older_than( $days ) {
		global $wpdb;
		$days = absint( $days );
		if ( ! $days ) {
			return 0;
		}
		$t      = FTGS_Install::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS ); // phpcs:ignore
		// Never delete rows still waiting to be delivered.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s AND status <> 'pending'", $cutoff ) ); // phpcs:ignore
	}

	public static function earliest( $source ) {
		global $wpdb;
		$t = FTGS_Install::table();
		return $wpdb->get_var( $wpdb->prepare( "SELECT MIN(created_at) FROM {$t} WHERE source = %s", $source ) ); // phpcs:ignore
	}

	public static function counts() {
		global $wpdb;
		$t   = FTGS_Install::table();
		$out = array( 'all' => 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$t} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore
			$out[ $r['status'] ] = (int) $r['n'];
			$out['all']         += (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Admin listing.
	 */
	public static function query( array $args ) {
		global $wpdb;
		$t     = FTGS_Install::table();
		$args  = wp_parse_args( $args, array( 'status' => '', 'source' => '', 'search' => '', 'paged' => 1, 'per_page' => 20 ) );
		$where = array( '1=1' );
		$vals  = array();
		if ( $args['status'] ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( $args['source'] ) {
			$where[] = 'source = %s';
			$vals[]  = $args['source'];
		}
		if ( '' !== $args['search'] ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = '(fields LIKE %s OR form_name LIKE %s OR uid LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		$w      = implode( ' AND ', $where );
		$limit  = max( 1, (int) $args['per_page'] );
		$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * $limit;

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$list_sql  = "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
		if ( $vals ) {
			$count_sql = $wpdb->prepare( $count_sql, $vals ); // phpcs:ignore
			$list_sql  = $wpdb->prepare( $list_sql, $vals ); // phpcs:ignore
		}
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore
		$items = array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $list_sql, ARRAY_A ) ); // phpcs:ignore
		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Rows for local sync, keyset-paginated by id.
	 *
	 * @param string|null $source  Source slug or null for every source.
	 * @param array       $form_ids Form ids (empty = all).
	 */
	public static function for_sync( $source, array $form_ids, $from, $to, $after_id, $limit ) {
		global $wpdb;
		$t     = FTGS_Install::table();
		$where = array( 'id > %d' );
		$vals  = array( (int) $after_id );
		if ( $source ) {
			$where[] = 'source = %s';
			$vals[]  = $source;
		}
		if ( $form_ids ) {
			$where[] = 'form_id IN (' . implode( ',', array_fill( 0, count( $form_ids ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, array_map( 'strval', $form_ids ) );
		}
		if ( $from ) {
			$where[] = 'created_at >= %s';
			$vals[]  = $from . ' 00:00:00';
		}
		if ( $to ) {
			$where[] = 'created_at <= %s';
			$vals[]  = $to . ' 23:59:59';
		}
		$vals[] = (int) $limit;
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d';
		return array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) ); // phpcs:ignore
	}

	public static function find_by_text( $text, $limit = 500 ) {
		global $wpdb;
		$t    = FTGS_Install::table();
		$like = '%' . $wpdb->esc_like( $text ) . '%';
		return array_map( array( __CLASS__, 'decode' ), (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE fields LIKE %s ORDER BY id ASC LIMIT %d", $like, $limit ), ARRAY_A ) ); // phpcs:ignore
	}

	/**
	 * Aggregate status from per-connection deliveries.
	 */
	public static function aggregate_status( array $deliveries ) {
		if ( empty( $deliveries ) ) {
			return 'stored';
		}
		$states = wp_list_pluck( $deliveries, 's' );
		if ( in_array( 'pending', $states, true ) ) {
			return 'pending';
		}
		if ( in_array( 'failed', $states, true ) ) {
			return 'failed';
		}
		if ( in_array( 'sent', $states, true ) ) {
			return 'sent';
		}
		return 'skipped';
	}
}
