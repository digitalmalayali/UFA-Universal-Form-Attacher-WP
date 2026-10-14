<?php
/**
 * Storage for webhook connections (one connection = forms -> one sheet tab).
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Connections {

	const OPTION = 'ftgs_connections';

	public static function meta_options() {
		return array(
			'timestamp'  => __( 'Timestamp', 'forms-to-google-sheets' ),
			'form_name'  => __( 'Form name', 'forms-to-google-sheets' ),
			'source'     => __( 'Form plugin', 'forms-to-google-sheets' ),
			'page_url'   => __( 'Page URL', 'forms-to-google-sheets' ),
			'ip'         => __( 'IP address', 'forms-to-google-sheets' ),
			'user_agent' => __( 'User agent', 'forms-to-google-sheets' ),
			'user'       => __( 'Logged-in WP user', 'forms-to-google-sheets' ),
		);
	}

	public static function defaults() {
		return array(
			'id'             => '',
			'name'           => '',
			'enabled'        => 1,
			'source'         => 'cf7',
			'form_id'        => '*',
			'webhook_url'    => '',
			'secret'         => '',
			'sheet'          => 'Sheet1',
			'mapping'        => '',
			'exclude'        => '',
			'column_order'   => '',
			'conditions'     => '',
			'condition_mode' => 'all',
			'meta'           => array( 'timestamp', 'form_name', 'page_url' ),
			'created_at'     => '',
		);
	}

	public static function all() {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			return array();
		}
		$out = array();
		foreach ( $items as $id => $c ) {
			if ( is_array( $c ) ) {
				$out[ $id ] = wp_parse_args( $c, self::defaults() );
			}
		}
		return $out;
	}

	public static function get( $id ) {
		$all = self::all();
		return ( '' !== (string) $id && isset( $all[ $id ] ) ) ? $all[ $id ] : null;
	}

	public static function save( array $data ) {
		$all = self::all();
		$c   = wp_parse_args( $data, self::defaults() );
		if ( empty( $c['id'] ) ) {
			$c['id'] = 'c' . strtolower( wp_generate_password( 10, false, false ) );
		}
		$c['id'] = sanitize_key( $c['id'] );
		if ( empty( $c['secret'] ) ) {
			$c['secret'] = wp_generate_password( 24, false, false );
		}
		if ( empty( $c['created_at'] ) ) {
			$c['created_at'] = current_time( 'mysql' );
		}
		$c['meta']       = array_values( array_intersect( (array) $c['meta'], array_keys( self::meta_options() ) ) );
		$all[ $c['id'] ] = $c;
		update_option( self::OPTION, $all, false );
		return $c;
	}

	public static function delete( $id ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Comma list -> array of form ids. Empty array means "all forms".
	 */
	public static function form_ids( $c ) {
		$ids = array_values( array_filter( array_map( 'trim', explode( ',', (string) $c['form_id'] ) ), 'strlen' ) );
		return ( empty( $ids ) || in_array( '*', $ids, true ) ) ? array() : $ids;
	}

	public static function matches_form( $c, $source, $form_id ) {
		if ( 'any' === $c['source'] ) {
			return true;
		}
		if ( $c['source'] !== $source ) {
			return false;
		}
		$ids = self::form_ids( $c );
		return empty( $ids ) || in_array( (string) $form_id, $ids, true );
	}

	/**
	 * Enabled connections listening for a source + form id (conditions not checked).
	 */
	public static function matching( $source, $form_id ) {
		$out = array();
		foreach ( self::all() as $c ) {
			if ( $c['enabled'] && ! empty( $c['webhook_url'] ) && self::matches_form( $c, $source, $form_id ) ) {
				$out[ $c['id'] ] = $c;
			}
		}
		return $out;
	}

	/**
	 * Parse "original => Column" lines.
	 */
	public static function parse_mapping( $text ) {
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( false === strpos( $line, '=>' ) ) {
				continue;
			}
			list( $from, $to ) = array_map( 'trim', explode( '=>', $line, 2 ) );
			if ( '' !== $from && '' !== $to ) {
				$map[ self::lower( $from ) ] = $to;
			}
		}
		return $map;
	}

	public static function parse_list( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[,\r\n]+/', (string) $text ) ), 'strlen' ) );
	}

	/**
	 * Parse condition lines like:
	 *   service == SEO
	 *   budget > 1000
	 *   email is not empty
	 *   message contains urgent
	 */
	public static function parse_conditions( $text ) {
		$rules = array();
		$ops   = '(==|!=|>=|<=|>|<|not contains|contains|starts with|ends with|is not empty|is empty)';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '/^(.+?)\s+' . $ops . '(?:\s+(.*))?$/i', $line, $m ) ) {
				$rules[] = array(
					'field' => trim( $m[1] ),
					'op'    => strtolower( $m[2] ),
					'value' => isset( $m[3] ) ? trim( $m[3], " \t\"'" ) : '',
				);
			}
		}
		return $rules;
	}

	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
	}
}
