<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Formidable extends FTGS_Integration {

	private $field_cache = array();

	public function get_slug() {
		return 'formidable';
	}

	public function get_label() {
		return 'Formidable Forms';
	}

	public function is_active() {
		return class_exists( 'FrmForm' ) && class_exists( 'FrmEntry' ) && class_exists( 'FrmField' );
	}

	public function hooks() {
		add_action( 'frm_after_create_entry', array( $this, 'handle' ), 30, 2 );
	}

	public function get_forms() {
		$out = array();
		foreach ( (array) FrmForm::getAll( array( 'is_template' => 0, 'status' => 'published' ), 'name' ) as $f ) {
			$out[ $f->id ] = $f->name;
		}
		return $out;
	}

	public function handle( $entry_id, $form_id ) {
		$entry = FrmEntry::getOne( $entry_id, true );
		$form  = FrmForm::getOne( $form_id );
		if ( ! $entry || ! $form || ! empty( $entry->is_draft ) ) {
			return;
		}
		$this->capture( $form_id, $form->name, $this->normalize( $entry, $form_id ), (string) $entry_id );
	}

	private function fields( $form_id ) {
		if ( ! isset( $this->field_cache[ $form_id ] ) ) {
			$skip = array_merge( $this->skip_types(), array( 'form', 'summary', 'end_divider' ) );
			$out  = array();
			foreach ( (array) FrmField::get_all_for_form( $form_id ) as $field ) {
				if ( ! in_array( $field->type, $skip, true ) ) {
					$out[] = $field;
				}
			}
			$this->field_cache[ $form_id ] = $out;
		}
		return $this->field_cache[ $form_id ];
	}

	private function normalize( $entry, $form_id ) {
		$out = array();
		foreach ( $this->fields( $form_id ) as $field ) {
			$raw = isset( $entry->metas[ $field->id ] ) ? $entry->metas[ $field->id ] : '';
			if ( class_exists( 'FrmEntriesHelper' ) && method_exists( 'FrmEntriesHelper', 'display_value' ) ) {
				$value = FrmEntriesHelper::display_value( $raw, $field, array( 'type' => $field->type, 'entry_id' => $entry->id, 'html' => false ) );
			} else {
				$value = $raw;
			}
			$out[] = $this->field( $field->field_key, $field->name, $value );
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
		$forms = $this->get_forms();
		$where = array( 'it.is_draft' => 0 );
		if ( $form_ids ) {
			$where['it.form_id'] = array_map( 'absint', $form_ids );
		}
		$limit    = (int) $args['limit'];
		$entries  = (array) FrmEntry::getAll( $where, ' ORDER BY it.id ASC', ' LIMIT ' . (int) $args['offset'] . ',' . ( $limit + 1 ), true, false );
		$has_more = count( $entries ) > $limit;
		$items    = array();
		foreach ( array_slice( $entries, 0, $limit ) as $e ) {
			$local = get_date_from_gmt( $e->created_at );
			if ( ! $this->in_range( $local, $args ) ) {
				continue;
			}
			$items[] = array(
				'native_id' => (string) $e->id,
				'form_id'   => (string) $e->form_id,
				'form_name' => isset( $forms[ $e->form_id ] ) ? $forms[ $e->form_id ] : '',
				'fields'    => $this->normalize( $e, $e->form_id ),
				'date'      => $local,
				'page_url'  => '',
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}
}
