<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_WPForms extends FTGS_Integration {

	public function get_slug() {
		return 'wpforms';
	}

	public function get_label() {
		return 'WPForms';
	}

	public function is_active() {
		return function_exists( 'wpforms' ) && defined( 'WPFORMS_VERSION' );
	}

	public function hooks() {
		add_action( 'wpforms_process_complete', array( $this, 'handle' ), 10, 4 );
	}

	public function get_help() {
		return __( 'Sync of old entries needs WPForms Pro (WPForms Lite does not store entries).', 'forms-to-google-sheets' );
	}

	public function get_forms() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'wpforms', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	public function handle( $fields, $entry, $form_data, $entry_id ) {
		$name = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '';
		$this->capture( $form_data['id'], $name, $this->normalize( $fields ), $entry_id ? (string) $entry_id : '' );
	}

	private function normalize( $fields ) {
		$out = array();
		foreach ( (array) $fields as $id => $f ) {
			$f    = (array) $f;
			$type = isset( $f['type'] ) ? $f['type'] : '';
			if ( in_array( $type, $this->skip_types(), true ) ) {
				continue;
			}
			$fid   = isset( $f['id'] ) ? $f['id'] : $id;
			$label = ! empty( $f['name'] ) ? $f['name'] : 'Field ' . $fid;
			$value = isset( $f['value'] ) ? $f['value'] : '';
			if ( 'file-upload' === $type && ! empty( $f['value_raw'] ) && is_array( $f['value_raw'] ) ) {
				$value = wp_list_pluck( $f['value_raw'], 'value' );
			}
			$out[] = $this->field( 'field_' . $fid, $label, $value );
		}
		return $out;
	}

	/* Sync (WPForms Pro) ------------------------------------------------- */

	private function entry_handler() {
		if ( ! function_exists( 'wpforms' ) ) {
			return null;
		}
		$wp = wpforms();
		if ( method_exists( $wp, 'get' ) ) {
			$h = $wp->get( 'entry' );
			if ( $h && method_exists( $h, 'get_entries' ) ) {
				return $h;
			}
		}
		return ( isset( $wp->entry ) && is_object( $wp->entry ) && method_exists( $wp->entry, 'get_entries' ) ) ? $wp->entry : null;
	}

	public function supports_native_sync() {
		return null !== $this->entry_handler();
	}

	public function live_has_native_id() {
		return $this->supports_native_sync();
	}

	public function get_entries( array $form_ids, array $args ) {
		$h = $this->entry_handler();
		if ( ! $h ) {
			return array( 'items' => array(), 'has_more' => false );
		}
		$forms = $this->get_forms();
		$query = array(
			'number'  => (int) $args['limit'] + 1,
			'offset'  => (int) $args['offset'],
			'order'   => 'ASC',
			'orderby' => 'entry_id',
		);
		if ( $form_ids ) {
			$query['form_id'] = array_map( 'absint', $form_ids );
		}
		$rows     = (array) $h->get_entries( $query );
		$has_more = count( $rows ) > (int) $args['limit'];
		$items    = array();
		foreach ( array_slice( $rows, 0, (int) $args['limit'] ) as $r ) {
			$r     = (object) $r;
			$local = get_date_from_gmt( $r->date );
			if ( ! $this->in_range( $local, $args ) ) {
				continue;
			}
			$items[] = array(
				'native_id' => (string) $r->entry_id,
				'form_id'   => (string) $r->form_id,
				'form_name' => isset( $forms[ $r->form_id ] ) ? $forms[ $r->form_id ] : '',
				'fields'    => $this->normalize( json_decode( (string) $r->fields, true ) ),
				'date'      => $local,
				'page_url'  => '',
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}
}
