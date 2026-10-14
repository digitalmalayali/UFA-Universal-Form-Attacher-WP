<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Divi extends FTGS_Integration {

	public function get_slug() {
		return 'divi';
	}

	public function get_label() {
		return 'Divi Contact Form';
	}

	public function is_active() {
		return defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) || function_exists( 'et_setup_theme' );
	}

	public function hooks() {
		add_action( 'et_pb_contact_form_submit', array( $this, 'handle' ), 20, 3 );
	}

	public function get_help() {
		return __( 'Divi forms are identified by the module\'s "Unique ID" (Contact Form settings → Advanced → Unique ID), or "page-ID#number". Use * for all.', 'forms-to-google-sheets' );
	}

	public function handle( $processed_fields_values, $et_contact_error, $contact_form_info = array() ) {
		if ( $et_contact_error ) {
			return;
		}
		$info    = (array) $contact_form_info;
		$post_id = isset( $info['post_id'] ) ? (int) $info['post_id'] : 0;
		$number  = isset( $info['contact_form_number'] ) ? $info['contact_form_number'] : '';
		$form_id = ! empty( $info['contact_form_unique_id'] ) ? (string) $info['contact_form_unique_id'] : $post_id . '#' . $number;
		$name    = ! empty( $info['contact_form_unique_id'] ) ? (string) $info['contact_form_unique_id'] : sprintf( 'Divi form %s', $number );
		if ( $post_id ) {
			$name .= ' — ' . get_the_title( $post_id );
		}
		$fields = array();
		foreach ( (array) $processed_fields_values as $id => $f ) {
			$fields[] = $this->field( $id, ! empty( $f['label'] ) ? $f['label'] : $id, isset( $f['value'] ) ? $f['value'] : '' );
		}
		$meta = $post_id ? array( 'page_url' => get_permalink( $post_id ) ) : array();
		$this->capture( $form_id, $name, $fields, '', $meta );
	}
}
