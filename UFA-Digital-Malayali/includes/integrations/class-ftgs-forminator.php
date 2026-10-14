<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Forminator extends FTGS_Integration {

	private $label_cache = array();

	public function get_slug() {
		return 'forminator';
	}

	public function get_label() {
		return 'Forminator';
	}

	public function is_active() {
		return class_exists( 'Forminator_API' );
	}

	public function hooks() {
		add_action( 'forminator_custom_form_submit_before_set_fields', array( $this, 'handle' ), 20, 3 );
	}

	public function get_forms() {
		$out   = array();
		$forms = Forminator_API::get_forms( null, 1, 999 );
		if ( is_wp_error( $forms ) ) {
			return $out;
		}
		foreach ( (array) $forms as $f ) {
			$name = '';
			if ( ! empty( $f->settings['formName'] ) ) {
				$name = $f->settings['formName'];
			} elseif ( ! empty( $f->name ) ) {
				$name = $f->name;
			}
			$out[ $f->id ] = $name ? $name : '#' . $f->id;
		}
		return $out;
	}

	public function handle( $entry, $form_id, $field_data_array ) {
		$fields = array();
		foreach ( (array) $field_data_array as $item ) {
			if ( ! isset( $item['name'] ) ) {
				continue;
			}
			$fields[ $item['name'] ] = isset( $item['value'] ) ? $item['value'] : '';
		}
		$forms     = $this->get_forms();
		$native_id = ( is_object( $entry ) && ! empty( $entry->entry_id ) ) ? (string) $entry->entry_id : '';
		$this->capture( $form_id, isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : '#' . $form_id, $this->normalize( $form_id, $fields ), $native_id );
	}

	private function normalize( $form_id, array $values ) {
		$labels = $this->labels( $form_id );
		$out    = array();
		foreach ( $values as $slug => $value ) {
			$slug = (string) $slug;
			if ( '' === $slug || '_' === $slug[0] || preg_match( '/^(captcha|html|section|page-break|gdprcheckbox|stripe|paypal|postdata)-/', $slug ) ) {
				continue;
			}
			if ( is_array( $value ) && isset( $value['file'] ) ) {
				$value = isset( $value['file']['file_url'] ) ? $value['file']['file_url'] : '';
			}
			$out[] = $this->field( $slug, isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug, $value );
		}
		return $out;
	}

	private function labels( $form_id ) {
		if ( isset( $this->label_cache[ $form_id ] ) ) {
			return $this->label_cache[ $form_id ];
		}
		$labels = array();
		$fields = Forminator_API::get_form_fields( $form_id );
		if ( ! is_wp_error( $fields ) ) {
			foreach ( (array) $fields as $f ) {
				$arr  = method_exists( $f, 'to_array' ) ? (array) $f->to_array() : (array) $f;
				$slug = ! empty( $f->slug ) ? $f->slug : ( isset( $arr['element_id'] ) ? $arr['element_id'] : '' );
				if ( $slug ) {
					$labels[ $slug ] = ! empty( $arr['field_label'] ) ? $arr['field_label'] : $slug;
				}
			}
		}
		$this->label_cache[ $form_id ] = $labels;
		return $labels;
	}

	public function supports_native_sync() {
		return method_exists( 'Forminator_API', 'get_entries' );
	}

	public function live_has_native_id() {
		return true;
	}

	public function get_entries( array $form_ids, array $args ) {
		$forms = $this->get_forms();
		$ids   = $form_ids ? array_map( 'absint', $form_ids ) : array_keys( $forms );
		sort( $ids );
		// Flatten all entries of the selected forms into one ordered list, then page through it.
		$all = array();
		foreach ( $ids as $fid ) {
			$entries = Forminator_API::get_entries( $fid );
			if ( is_wp_error( $entries ) ) {
				continue;
			}
			foreach ( (array) $entries as $e ) {
				if ( ! empty( $e->entry_id ) && $this->in_range( $e->date_created_sql, $args ) ) {
					$all[] = array( $fid, $e );
				}
			}
		}
		usort(
			$all,
			function ( $a, $b ) {
				return (int) $a[1]->entry_id - (int) $b[1]->entry_id;
			}
		);
		$page  = array_slice( $all, (int) $args['offset'], (int) $args['limit'] );
		$items = array();
		foreach ( $page as $pair ) {
			list( $fid, $e ) = $pair;
			$values          = array();
			foreach ( (array) $e->meta_data as $slug => $m ) {
				$values[ $slug ] = is_array( $m ) && array_key_exists( 'value', $m ) ? $m['value'] : $m;
			}
			$items[] = array(
				'native_id' => (string) $e->entry_id,
				'form_id'   => (string) $fid,
				'form_name' => isset( $forms[ $fid ] ) ? $forms[ $fid ] : '',
				'fields'    => $this->normalize( $fid, $values ),
				'date'      => (string) $e->date_created_sql,
				'page_url'  => '',
			);
		}
		return array( 'items' => $items, 'has_more' => count( $all ) > (int) $args['offset'] + (int) $args['limit'], 'total' => count( $all ) );
	}
}
