<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Bricks extends FTGS_Integration {

	public function get_slug() {
		return 'bricks';
	}

	public function get_label() {
		return 'Bricks Builder Form';
	}

	public function is_active() {
		return defined( 'BRICKS_VERSION' );
	}

	public function hooks() {
		add_action( 'bricks/form/custom_action', array( $this, 'handle' ), 20, 1 );
	}

	public function get_help() {
		return __( 'In the Bricks form element, add "Custom" to "Actions after successful form submit". Forms are identified by the element ID.', 'forms-to-google-sheets' );
	}

	public function handle( $form ) {
		if ( ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) ) {
			return;
		}
		$submitted = (array) $form->get_fields();
		$settings  = method_exists( $form, 'get_settings' ) ? (array) $form->get_settings() : array();
		$form_id   = method_exists( $form, 'get_id' ) ? (string) $form->get_id() : ( isset( $submitted['formId'] ) ? (string) $submitted['formId'] : '' );
		$post_id   = isset( $submitted['postId'] ) ? (int) $submitted['postId'] : 0;

		$fields = array();
		$done   = array();
		foreach ( isset( $settings['fields'] ) ? (array) $settings['fields'] : array() as $def ) {
			if ( empty( $def['id'] ) ) {
				continue;
			}
			$type = isset( $def['type'] ) ? $def['type'] : '';
			if ( in_array( $type, $this->skip_types(), true ) ) {
				continue;
			}
			$key          = 'form-field-' . $def['id'];
			$done[ $key ] = true;
			$label        = ! empty( $def['label'] ) ? $def['label'] : ( ! empty( $def['placeholder'] ) ? $def['placeholder'] : $def['id'] );
			$value        = isset( $submitted[ $key ] ) ? $submitted[ $key ] : '';
			if ( 'file' === $type && method_exists( $form, 'get_uploaded_files' ) ) {
				$files = (array) $form->get_uploaded_files();
				if ( isset( $files[ $key ] ) ) {
					$value = wp_list_pluck( (array) $files[ $key ], 'url' );
				}
			}
			$fields[] = $this->field( $key, $label, $value );
		}
		$name = ! empty( $settings['formName'] ) ? $settings['formName'] : 'Bricks form ' . $form_id;
		if ( $post_id ) {
			$name .= ' — ' . get_the_title( $post_id );
		}
		$meta = $post_id ? array( 'page_url' => get_permalink( $post_id ) ) : array();
		$this->capture( $form_id, $name, $fields, '', $meta );
	}
}
