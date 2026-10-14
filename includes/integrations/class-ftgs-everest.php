<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Everest extends FTGS_Integration {

	private $label_cache = array();

	public function get_slug() {
		return 'everest';
	}

	public function get_label() {
		return 'Everest Forms';
	}

	public function is_active() {
		return function_exists( 'evf' ) || class_exists( 'EverestForms' );
	}

	public function hooks() {
		add_action( 'everest_forms_process_complete', array( $this, 'handle' ), 10, 4 );
	}

	public function get_forms() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'everest_form', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	public function handle( $form_fields, $entry, $form_data, $entry_id ) {
		$out = array();
		foreach ( (array) $form_fields as $id => $f ) {
			$f    = (array) $f;
			$type = isset( $f['type'] ) ? $f['type'] : '';
			if ( in_array( $type, array_merge( $this->skip_types(), array( 'title', 'private-note' ) ), true ) ) {
				continue;
			}
			$key   = ! empty( $f['meta_key'] ) ? $f['meta_key'] : ( isset( $f['id'] ) ? $f['id'] : $id );
			$label = ! empty( $f['name'] ) ? $f['name'] : $key;
			$value = isset( $f['value'] ) ? $f['value'] : '';
			if ( is_array( $value ) && isset( $value['label'] ) ) {
				$value = $value['label']; // Choice fields store label + raw value.
			}
			$out[] = $this->field( $key, $label, $value );
		}
		$form_id = isset( $form_data['id'] ) ? $form_data['id'] : 0;
		$title   = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : get_the_title( $form_id );
		$this->capture( $form_id, $title, $out, $entry_id ? (string) $entry_id : '' );
	}

	public function supports_native_sync() {
		global $wpdb;
		return $this->table_exists( $wpdb->prefix . 'evf_entries' ) && $this->table_exists( $wpdb->prefix . 'evf_entrymeta' );
	}

	public function live_has_native_id() {
		return true;
	}

	public function get_entries( array $form_ids, array $args ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'evf_entries';
		$tm    = $wpdb->prefix . 'evf_entrymeta';
		$where = "status NOT IN ('trash','spam','draft')";
		if ( $form_ids ) {
			$where .= ' AND form_id IN (' . implode( ',', array_map( 'absint', $form_ids ) ) . ')';
		}
		list( $dsql, $dvals ) = $this->date_sql( 'date_created', $args );
		$where               .= $dsql;
		$vals                 = array_merge( $dvals, array( (int) $args['limit'] + 1, (int) $args['offset'] ) );
		$rows                 = (array) $wpdb->get_results( $wpdb->prepare( "SELECT entry_id, form_id, referer, date_created FROM {$t} WHERE {$where} ORDER BY entry_id ASC LIMIT %d OFFSET %d", $vals ), ARRAY_A ); // phpcs:ignore
		$has_more             = count( $rows ) > (int) $args['limit'];
		$forms                = $this->get_forms();
		$items                = array();
		foreach ( array_slice( $rows, 0, (int) $args['limit'] ) as $r ) {
			$labels = $this->labels( (int) $r['form_id'] );
			$metas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$tm} WHERE entry_id = %d ORDER BY meta_id ASC", $r['entry_id'] ), ARRAY_A ); // phpcs:ignore
			$fields = array();
			foreach ( $metas as $m ) {
				if ( '' === $m['meta_key'] || '_' === $m['meta_key'][0] ) {
					continue;
				}
				$v        = is_serialized( $m['meta_value'] ) ? @unserialize( trim( $m['meta_value'] ), array( 'allowed_classes' => false ) ) : $m['meta_value']; // phpcs:ignore
				$fields[] = $this->field( $m['meta_key'], isset( $labels[ $m['meta_key'] ] ) ? $labels[ $m['meta_key'] ] : $m['meta_key'], $v );
			}
			$items[] = array(
				'native_id' => (string) $r['entry_id'],
				'form_id'   => (string) $r['form_id'],
				'form_name' => isset( $forms[ $r['form_id'] ] ) ? $forms[ $r['form_id'] ] : '',
				'fields'    => $fields,
				'date'      => (string) $r['date_created'],
				'page_url'  => (string) $r['referer'],
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}

	private function labels( $form_id ) {
		if ( isset( $this->label_cache[ $form_id ] ) ) {
			return $this->label_cache[ $form_id ];
		}
		$labels = array();
		$post   = get_post( $form_id );
		$data   = $post ? json_decode( $post->post_content, true ) : null;
		if ( is_array( $data ) && ! empty( $data['form_fields'] ) ) {
			foreach ( (array) $data['form_fields'] as $id => $f ) {
				$key            = ! empty( $f['meta-key'] ) ? $f['meta-key'] : $id;
				$labels[ $key ] = ! empty( $f['label'] ) ? $f['label'] : $key;
			}
		}
		$this->label_cache[ $form_id ] = $labels;
		return $labels;
	}
}
