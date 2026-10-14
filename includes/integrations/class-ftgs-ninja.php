<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Ninja extends FTGS_Integration {

	private $field_cache = array();

	public function get_slug() {
		return 'ninja';
	}

	public function get_label() {
		return 'Ninja Forms';
	}

	public function is_active() {
		return class_exists( 'Ninja_Forms' ) && function_exists( 'Ninja_Forms' );
	}

	public function hooks() {
		add_action( 'ninja_forms_after_submission', array( $this, 'handle' ), 10, 1 );
	}

	public function get_forms() {
		$out = array();
		foreach ( (array) Ninja_Forms()->form()->get_forms() as $f ) {
			$out[ $f->get_id() ] = $f->get_setting( 'title' );
		}
		return $out;
	}

	private function skip() {
		return array_merge( $this->skip_types(), array( 'spam', 'confirm', 'passwordconfirm', 'save', 'recaptcha_v3' ) );
	}

	public function handle( $form_data ) {
		$out = array();
		foreach ( (array) $form_data['fields'] as $id => $f ) {
			$type = isset( $f['type'] ) ? $f['type'] : '';
			if ( in_array( $type, $this->skip(), true ) ) {
				continue;
			}
			$label = ! empty( $f['admin_label'] ) ? $f['admin_label'] : ( isset( $f['label'] ) ? $f['label'] : $id );
			$key   = ! empty( $f['key'] ) ? $f['key'] : (string) $id;
			$out[] = $this->field( $key, $label, isset( $f['value'] ) ? $f['value'] : '' );
		}
		$title  = isset( $form_data['settings']['title'] ) ? $form_data['settings']['title'] : '';
		$sub_id = isset( $form_data['actions']['save']['sub_id'] ) ? (string) $form_data['actions']['save']['sub_id'] : '';
		$this->capture( $form_data['form_id'], $title, $out, $sub_id );
	}

	public function supports_native_sync() {
		return post_type_exists( 'nf_sub' );
	}

	public function live_has_native_id() {
		return true;
	}

	public function get_entries( array $form_ids, array $args ) {
		$forms = $this->get_forms();
		$ids   = $form_ids ? array_map( 'absint', $form_ids ) : array_map( 'absint', array_keys( $forms ) );
		if ( ! $ids ) {
			return array( 'items' => array(), 'has_more' => false );
		}
		$date_query = array( 'inclusive' => true );
		if ( ! empty( $args['from'] ) ) {
			$date_query['after'] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) ) {
			$date_query['before'] = $args['to'] . ' 23:59:59';
		}
		$q        = new WP_Query(
			array(
				'post_type'      => 'nf_sub',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $args['limit'] + 1,
				'offset'         => (int) $args['offset'],
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => '_form_id', 'value' => $ids, 'compare' => 'IN' ) ), // phpcs:ignore
				'date_query'     => count( $date_query ) > 1 ? array( $date_query ) : array(),
			)
		);
		$posts    = $q->posts;
		$has_more = count( $posts ) > (int) $args['limit'];
		$items    = array();
		foreach ( array_slice( $posts, 0, (int) $args['limit'] ) as $p ) {
			$form_id = (int) get_post_meta( $p->ID, '_form_id', true );
			$fields  = array();
			foreach ( $this->fields( $form_id ) as $fid => $def ) {
				$fields[] = $this->field( $def['key'], $def['label'], get_post_meta( $p->ID, '_field_' . $fid, true ) );
			}
			$items[] = array(
				'native_id' => (string) $p->ID,
				'form_id'   => (string) $form_id,
				'form_name' => isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : '',
				'fields'    => $fields,
				'date'      => $p->post_date,
				'page_url'  => '',
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}

	private function fields( $form_id ) {
		if ( isset( $this->field_cache[ $form_id ] ) ) {
			return $this->field_cache[ $form_id ];
		}
		$out = array();
		foreach ( (array) Ninja_Forms()->form( $form_id )->get_fields() as $f ) {
			$type = $f->get_setting( 'type' );
			if ( in_array( $type, $this->skip(), true ) ) {
				continue;
			}
			$admin               = $f->get_setting( 'admin_label' );
			$out[ $f->get_id() ] = array(
				'key'   => $f->get_setting( 'key' ) ? $f->get_setting( 'key' ) : (string) $f->get_id(),
				'label' => $admin ? $admin : $f->get_setting( 'label' ),
			);
		}
		$this->field_cache[ $form_id ] = $out;
		return $out;
	}
}
