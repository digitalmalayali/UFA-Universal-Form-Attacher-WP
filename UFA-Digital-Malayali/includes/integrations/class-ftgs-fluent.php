<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Fluent extends FTGS_Integration {

	private $done   = array();
	private $forms  = array();

	public function get_slug() {
		return 'fluent';
	}

	public function get_label() {
		return 'Fluent Forms';
	}

	public function is_active() {
		return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' );
	}

	public function hooks() {
		add_action( 'fluentform/submission_inserted', array( $this, 'handle' ), 20, 3 );
		add_action( 'fluentform_submission_inserted', array( $this, 'handle' ), 20, 3 ); // Legacy (< 5.0).
	}

	public function get_forms() {
		global $wpdb;
		$out   = array();
		$table = $wpdb->prefix . 'fluentform_forms';
		if ( $this->table_exists( $table ) ) {
			foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$table} ORDER BY title ASC" ) as $r ) { // phpcs:ignore
				$out[ $r->id ] = $r->title;
			}
		}
		return $out;
	}

	public function handle( $entry_id, $form_data, $form ) {
		if ( isset( $this->done[ $entry_id ] ) || ! is_object( $form ) ) {
			return; // Both new and legacy hooks can fire.
		}
		$this->done[ $entry_id ] = true;
		$this->capture( $form->id, $form->title, $this->normalize( (array) $form_data, $form ), (string) $entry_id );
	}

	private function normalize( array $form_data, $form ) {
		$labels = $this->labels( $form );
		$out    = array();
		foreach ( $form_data as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] || 0 === strpos( $key, 'g-recaptcha' ) || 0 === strpos( $key, 'cf-turnstile' ) || 'h-captcha-response' === $key || false !== strpos( $key, 'fluentformnonce' ) ) {
				continue;
			}
			if ( is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				// Name / address fields: join sub-values in order.
				$value = implode( ' ', array_filter( array_map( array( 'FTGS_Row', 'flatten' ), array_values( $value ), array_fill( 0, count( $value ), false ) ), 'strlen' ) );
			}
			$out[] = $this->field( $key, isset( $labels[ $key ] ) ? $labels[ $key ] : $key, $value );
		}
		return $out;
	}

	private function labels( $form ) {
		$labels = array();
		$json   = json_decode( isset( $form->form_fields ) ? (string) $form->form_fields : '', true );
		if ( ! empty( $json['fields'] ) ) {
			$this->walk( $json['fields'], $labels );
		}
		return $labels;
	}

	private function walk( $fields, &$labels ) {
		foreach ( (array) $fields as $f ) {
			if ( ! empty( $f['columns'] ) ) {
				foreach ( (array) $f['columns'] as $col ) {
					$this->walk( isset( $col['fields'] ) ? $col['fields'] : array(), $labels );
				}
			}
			if ( ! empty( $f['attributes']['name'] ) ) {
				$label = isset( $f['settings']['label'] ) ? (string) $f['settings']['label'] : '';
				if ( '' === $label && ! empty( $f['settings']['admin_field_label'] ) ) {
					$label = (string) $f['settings']['admin_field_label'];
				}
				$labels[ $f['attributes']['name'] ] = '' !== trim( wp_strip_all_tags( $label ) ) ? wp_strip_all_tags( $label ) : $f['attributes']['name'];
			}
		}
	}

	public function supports_native_sync() {
		global $wpdb;
		return $this->table_exists( $wpdb->prefix . 'fluentform_submissions' );
	}

	public function live_has_native_id() {
		return true;
	}

	public function get_entries( array $form_ids, array $args ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'fluentform_submissions';
		$where = "status NOT IN ('trashed','spam')";
		$vals  = array();
		if ( $form_ids ) {
			$where .= ' AND form_id IN (' . implode( ',', array_map( 'absint', $form_ids ) ) . ')';
		}
		list( $dsql, $dvals ) = $this->date_sql( 'created_at', $args );
		$where               .= $dsql;
		$vals                 = array_merge( $vals, $dvals, array( (int) $args['limit'] + 1, (int) $args['offset'] ) );
		$rows                 = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, response, source_url, created_at FROM {$t} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $vals ), ARRAY_A ); // phpcs:ignore
		$has_more             = count( $rows ) > (int) $args['limit'];
		$items                = array();
		foreach ( array_slice( $rows, 0, (int) $args['limit'] ) as $r ) {
			$form = $this->form( (int) $r['form_id'] );
			if ( ! $form ) {
				continue;
			}
			$items[] = array(
				'native_id' => (string) $r['id'],
				'form_id'   => (string) $r['form_id'],
				'form_name' => $form->title,
				'fields'    => $this->normalize( (array) json_decode( (string) $r['response'], true ), $form ),
				'date'      => (string) $r['created_at'],
				'page_url'  => (string) $r['source_url'],
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}

	private function form( $id ) {
		global $wpdb;
		if ( ! array_key_exists( $id, $this->forms ) ) {
			$t                  = $wpdb->prefix . 'fluentform_forms';
			$this->forms[ $id ] = $wpdb->get_row( $wpdb->prepare( "SELECT id, title, form_fields FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore
		}
		return $this->forms[ $id ];
	}
}
