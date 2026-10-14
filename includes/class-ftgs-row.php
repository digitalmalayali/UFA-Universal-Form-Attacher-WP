<?php
/**
 * Turns a stored submission into one sheet row for a connection,
 * and evaluates a connection's conditions.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Row {

	const ID_COLUMN = 'Entry ID';

	/**
	 * @param array $c   Connection.
	 * @param array $sub Submission: uid, source, form_id, form_name, fields[], meta[].
	 * @return array Ordered column => value.
	 */
	public static function build( array $c, array $sub ) {
		$map     = FTGS_Connections::parse_mapping( $c['mapping'] );
		$exclude = array_map( array( 'FTGS_Connections', 'lower' ), FTGS_Connections::parse_list( $c['exclude'] ) );
		$opts    = (array) $c['meta'];
		$meta    = isset( $sub['meta'] ) && is_array( $sub['meta'] ) ? $sub['meta'] : array();
		$data    = array();

		$data[ self::ID_COLUMN ] = (string) $sub['uid'];

		if ( in_array( 'timestamp', $opts, true ) ) {
			$data['Timestamp'] = ! empty( $meta['submitted_at'] ) ? (string) $meta['submitted_at'] : current_time( 'mysql' );
		}
		if ( in_array( 'form_name', $opts, true ) ) {
			$data['Form'] = (string) $sub['form_name'];
		}
		if ( in_array( 'source', $opts, true ) ) {
			$integration    = ftgs_integration( $sub['source'] );
			$data['Source'] = $integration ? $integration->get_label() : (string) $sub['source'];
		}

		foreach ( (array) $sub['fields'] as $f ) {
			$key   = isset( $f['key'] ) ? (string) $f['key'] : '';
			$label = isset( $f['label'] ) && '' !== trim( (string) $f['label'] ) ? trim( (string) $f['label'] ) : $key;
			if ( '' === $label ) {
				continue;
			}
			$lk = FTGS_Connections::lower( $key );
			$ll = FTGS_Connections::lower( $label );
			if ( in_array( $lk, $exclude, true ) || in_array( $ll, $exclude, true ) ) {
				continue;
			}
			if ( isset( $map[ $lk ] ) ) {
				$column = $map[ $lk ];
			} elseif ( isset( $map[ $ll ] ) ) {
				$column = $map[ $ll ];
			} else {
				$column = $label;
			}
			if ( self::ID_COLUMN === $column ) {
				$column = 'Field: ' . $column;
			}
			$value = self::flatten( isset( $f['value'] ) ? $f['value'] : '' );
			if ( isset( $data[ $column ] ) && '' !== $data[ $column ] ) {
				$data[ $column ] = '' === $value ? $data[ $column ] : $data[ $column ] . ', ' . $value;
			} else {
				$data[ $column ] = $value;
			}
		}

		if ( in_array( 'page_url', $opts, true ) ) {
			$data['Page URL'] = isset( $meta['page_url'] ) ? (string) $meta['page_url'] : '';
		}
		if ( in_array( 'ip', $opts, true ) ) {
			$data['IP Address'] = isset( $meta['ip'] ) ? (string) $meta['ip'] : '';
		}
		if ( in_array( 'user_agent', $opts, true ) ) {
			$data['User Agent'] = isset( $meta['user_agent'] ) ? (string) $meta['user_agent'] : '';
		}
		if ( in_array( 'user', $opts, true ) ) {
			$data['WP User'] = isset( $meta['user'] ) ? (string) $meta['user'] : '';
		}

		$data = self::apply_order( $data, FTGS_Connections::parse_list( $c['column_order'] ) );

		return apply_filters( 'ftgs_row', $data, $c, $sub );
	}

	/**
	 * Put the listed columns first, in that order (Entry ID always stays first).
	 */
	public static function apply_order( array $data, array $order ) {
		if ( ! $order ) {
			return $data;
		}
		$out = array( self::ID_COLUMN => $data[ self::ID_COLUMN ] );
		foreach ( $order as $col ) {
			foreach ( $data as $k => $v ) {
				if ( 0 === strcasecmp( (string) $k, $col ) && ! array_key_exists( $k, $out ) ) {
					$out[ $k ] = $v;
				}
			}
		}
		foreach ( $data as $k => $v ) {
			if ( ! array_key_exists( $k, $out ) ) {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/**
	 * Does a submission satisfy the connection's conditions?
	 */
	public static function passes( array $c, array $fields ) {
		$rules = FTGS_Connections::parse_conditions( $c['conditions'] );
		if ( ! $rules ) {
			return true;
		}
		$values = array();
		foreach ( $fields as $f ) {
			$v = self::flatten( isset( $f['value'] ) ? $f['value'] : '', false );
			foreach ( array( 'key', 'label' ) as $k ) {
				if ( isset( $f[ $k ] ) && '' !== (string) $f[ $k ] ) {
					$values[ FTGS_Connections::lower( $f[ $k ] ) ] = $v;
				}
			}
		}
		$any = 'any' === $c['condition_mode'];
		foreach ( $rules as $r ) {
			$field  = FTGS_Connections::lower( $r['field'] );
			$actual = isset( $values[ $field ] ) ? $values[ $field ] : '';
			$ok     = self::compare( $actual, $r['op'], $r['value'] );
			if ( $any && $ok ) {
				return true;
			}
			if ( ! $any && ! $ok ) {
				return false;
			}
		}
		return ! $any;
	}

	public static function compare( $actual, $op, $expected ) {
		$a = FTGS_Connections::lower( trim( (string) $actual ) );
		$e = FTGS_Connections::lower( trim( (string) $expected ) );
		switch ( $op ) {
			case '==':
				// For multi-value fields ("A, B") equality also matches one of the values.
				return $a === $e || in_array( $e, array_map( 'trim', explode( ',', $a ) ), true );
			case '!=':
				return ! ( $a === $e || in_array( $e, array_map( 'trim', explode( ',', $a ) ), true ) );
			case 'contains':
				return '' !== $e && false !== strpos( $a, $e );
			case 'not contains':
				return '' === $e || false === strpos( $a, $e );
			case 'starts with':
				return '' !== $e && 0 === strpos( $a, $e );
			case 'ends with':
				return '' !== $e && substr( $a, -strlen( $e ) ) === $e;
			case 'is empty':
				return '' === $a;
			case 'is not empty':
				return '' !== $a;
			case '>':
			case '<':
			case '>=':
			case '<=':
				$an = self::number( $a );
				$en = self::number( $e );
				if ( null === $an || null === $en ) {
					return false;
				}
				if ( '>' === $op ) {
					return $an > $en;
				}
				if ( '<' === $op ) {
					return $an < $en;
				}
				return '>=' === $op ? $an >= $en : $an <= $en;
		}
		return false;
	}

	private static function number( $s ) {
		$s = preg_replace( '/[^\d.\-]/', '', (string) $s );
		return is_numeric( $s ) ? (float) $s : null;
	}

	/**
	 * Any value -> safe single cell string.
	 *
	 * @param mixed $value  Value.
	 * @param bool  $escape Add the apostrophe guard for Sheets.
	 */
	public static function flatten( $value, $escape = true ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$parts = array();
			foreach ( (array) $value as $v ) {
				$v = self::flatten( $v, false );
				if ( '' !== $v ) {
					$parts[] = $v;
				}
			}
			$value = implode( ', ', $parts );
		} elseif ( is_bool( $value ) ) {
			$value = $value ? 'Yes' : 'No';
		} elseif ( null === $value ) {
			$value = '';
		}
		$value = trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' ) );
		if ( mb_strlen( $value ) > 49000 ) {
			$value = mb_substr( $value, 0, 49000 ); // Sheets cell limit is 50,000 chars.
		}
		if ( ! $escape || '' === $value ) {
			return $value;
		}
		// Stop formula injection and keep phone numbers like +91... / 0123... intact.
		$first = $value[0];
		if ( in_array( $first, array( '=', '+', '@' ), true )
			|| ( '-' === $first && ! is_numeric( $value ) )
			|| preg_match( '/^0\d+$/', $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
