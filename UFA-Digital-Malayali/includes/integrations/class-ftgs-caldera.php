<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Caldera extends FTGS_Integration {

	private $form_cache = array();

	public function get_slug() {
		return 'caldera';
	}

	public function get_label() {
		return 'Caldera Forms';
	}

	public function is_active() {
		return class_exists( 'Caldera_Forms' ) && class_exists( 'Caldera_Forms_Forms' );
	}

	public function hooks() {
		add_action( 'caldera_forms_submit_complete', array( $this, 'handle' ), 55, 4 );
	}

	public function get_forms() {
		$out = array();
		foreach ( (array) Caldera_Forms_Forms::get_forms( true ) as $id => $f ) {
			$out[ $id ] = isset( $f['name'] ) ? $f['name'] : $id;
		}
		return $out;
	}

	private function skip() {
		return array_merge( $this->skip_types(), array( 'section_break', 'live_gravatar', 'summary', 'calculation_hidden' ) );
	}

	public function handle( $form, $referrer = null, $process_id = null, $entry_id = null ) {
		$meta = array();
		if ( is_array( $referrer ) && isset( $referrer['scheme'], $referrer['host'] ) ) {
			$meta['page_url'] = $referrer['scheme'] . '://' . $referrer['host'] . ( isset( $referrer['path'] ) ? $referrer['path'] : '' );
		}
		$fields = array();
		foreach ( (array) $form['fields'] as $field_id => $field ) {
			if ( in_array( $field['type'], $this->skip(), true ) ) {
				continue;
			}
			$value    = Caldera_Forms::get_field_data( $field_id, $form );
			$label    = ! empty( $field['label'] ) ? $field['label'] : $field['slug'];
			$fields[] = $this->field( $field['slug'], $label, $value );
		}
		$this->capture( $form['ID'], isset( $form['name'] ) ? $form['name'] : $form['ID'], $fields, $entry_id ? (string) $entry_id : '', $meta );
	}

	public function supports_native_sync() {
		global $wpdb;
		return $this->table_exists( $wpdb->prefix . 'cf_form_entries' );
	}

	public function live_has_native_id() {
		return true;
	}

	public function get_entries( array $form_ids, array $args ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'cf_form_entries';
		$tv    = $wpdb->prefix . 'cf_form_entry_values';
		$where = "status = 'active'";
		$vals  = array();
		if ( $form_ids ) {
			$where .= ' AND form_id IN (' . implode( ',', array_fill( 0, count( $form_ids ), '%s' ) ) . ')';
			$vals   = array_merge( $vals, $form_ids );
		}
		list( $dsql, $dvals ) = $this->date_sql( 'datestamp', $args );
		$where               .= $dsql;
		$vals                 = array_merge( $vals, $dvals, array( (int) $args['limit'] + 1, (int) $args['offset'] ) );
		$rows                 = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, datestamp FROM {$t} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $vals ), ARRAY_A ); // phpcs:ignore
		$has_more             = count( $rows ) > (int) $args['limit'];
		$items                = array();
		foreach ( array_slice( $rows, 0, (int) $args['limit'] ) as $r ) {
			$form = $this->form( $r['form_id'] );
			if ( ! $form ) {
				continue;
			}
			$values = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT slug, value FROM {$tv} WHERE entry_id = %d ORDER BY id ASC", $r['id'] ), ARRAY_A ) as $v ) { // phpcs:ignore
				$val                    = is_serialized( $v['value'] ) ? @unserialize( trim( $v['value'] ), array( 'allowed_classes' => false ) ) : $v['value']; // phpcs:ignore
				$values[ $v['slug'] ][] = $val;
			}
			$fields = array();
			foreach ( (array) $form['fields'] as $field ) {
				if ( in_array( $field['type'], $this->skip(), true ) ) {
					continue;
				}
				$val      = isset( $values[ $field['slug'] ] ) ? ( 1 === count( $values[ $field['slug'] ] ) ? $values[ $field['slug'] ][0] : $values[ $field['slug'] ] ) : '';
				$fields[] = $this->field( $field['slug'], ! empty( $field['label'] ) ? $field['label'] : $field['slug'], $val );
			}
			$items[] = array(
				'native_id' => (string) $r['id'],
				'form_id'   => (string) $r['form_id'],
				'form_name' => isset( $form['name'] ) ? $form['name'] : $r['form_id'],
				'fields'    => $fields,
				'date'      => (string) $r['datestamp'],
				'page_url'  => '',
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}

	private function form( $id ) {
		if ( ! array_key_exists( $id, $this->form_cache ) ) {
			$this->form_cache[ $id ] = Caldera_Forms_Forms::get_form( $id );
		}
		return $this->form_cache[ $id ];
	}
}
