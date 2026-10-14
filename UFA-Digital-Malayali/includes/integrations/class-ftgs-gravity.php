<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Gravity extends FTGS_Integration {

	private $form_cache = array();

	public function get_slug() {
		return 'gravity';
	}

	public function get_label() {
		return 'Gravity Forms';
	}

	public function is_active() {
		return class_exists( 'GFForms' ) && class_exists( 'GFAPI' );
	}

	public function hooks() {
		add_action( 'gform_after_submission', array( $this, 'handle' ), 10, 2 );
	}

	public function get_forms() {
		$out = array();
		foreach ( (array) GFAPI::get_forms() as $f ) {
			$out[ $f['id'] ] = $f['title'];
		}
		return $out;
	}

	public function handle( $entry, $form ) {
		if ( isset( $entry['status'] ) && 'spam' === $entry['status'] ) {
			return;
		}
		$meta = array( 'page_url' => isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '' );
		$this->capture( $form['id'], $form['title'], $this->normalize( $entry, $form ), isset( $entry['id'] ) ? (string) $entry['id'] : '', $meta );
	}

	private function normalize( $entry, $form ) {
		$out = array();
		foreach ( (array) $form['fields'] as $field ) {
			$type = is_object( $field ) ? $field->type : ( isset( $field['type'] ) ? $field['type'] : '' );
			if ( in_array( $type, $this->skip_types(), true ) ) {
				continue;
			}
			if ( is_object( $field ) && method_exists( $field, 'get_value_export' ) ) {
				$value = $field->get_value_export( $entry, $field->id, true );
			} else {
				$value = isset( $entry[ (string) $field['id'] ] ) ? $entry[ (string) $field['id'] ] : '';
			}
			$label = ! empty( $field['adminLabel'] ) ? $field['adminLabel'] : $field['label'];
			$out[] = $this->field( 'input_' . $field['id'], $label, $value );
		}
		return $out;
	}

	public function supports_native_sync() {
		return true;
	}

	public function live_has_native_id() {
		return true;
	}

	public function get_entries( array $form_ids, array $args ) {
		$search = array( 'status' => 'active' );
		if ( ! empty( $args['from'] ) ) {
			$search['start_date'] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$search['end_date'] = $args['to'];
		}
		$ids     = $form_ids ? array_map( 'absint', $form_ids ) : 0;
		$total   = 0;
		$entries = GFAPI::get_entries(
			$ids,
			$search,
			array( 'key' => 'id', 'direction' => 'ASC' ),
			array( 'offset' => (int) $args['offset'], 'page_size' => (int) $args['limit'] ),
			$total
		);
		if ( is_wp_error( $entries ) ) {
			throw new Exception( $entries->get_error_message() );
		}
		$items = array();
		foreach ( (array) $entries as $e ) {
			$fid = (int) $e['form_id'];
			if ( ! isset( $this->form_cache[ $fid ] ) ) {
				$this->form_cache[ $fid ] = GFAPI::get_form( $fid );
			}
			$form = $this->form_cache[ $fid ];
			if ( ! $form ) {
				continue;
			}
			$items[] = array(
				'native_id' => (string) $e['id'],
				'form_id'   => (string) $fid,
				'form_name' => $form['title'],
				'fields'    => $this->normalize( $e, $form ),
				'date'      => get_date_from_gmt( $e['date_created'] ),
				'page_url'  => isset( $e['source_url'] ) ? $e['source_url'] : '',
			);
		}
		return array(
			'items'    => $items,
			'has_more' => ( (int) $args['offset'] + count( (array) $entries ) ) < (int) $total,
			'total'    => (int) $total,
		);
	}
}
