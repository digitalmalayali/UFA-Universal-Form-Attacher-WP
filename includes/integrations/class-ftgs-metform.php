<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_MetForm extends FTGS_Integration {

	public function get_slug() {
		return 'metform';
	}

	public function get_label() {
		return 'MetForm';
	}

	public function is_active() {
		return defined( 'METFORM_VERSION' ) || class_exists( '\MetForm\Plugin' );
	}

	public function hooks() {
		add_action( 'metform_after_store_form_data', array( $this, 'handle' ), 20, 4 );
	}

	public function get_forms() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'metform-form', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	public function handle( $form_id, $form_data = array(), $form_settings = array(), $attributes = array() ) {
		$skip   = array( 'action', 'id', 'form_nonce', 'hidden-fields', 'g-recaptcha-response', 'g-recaptcha-response-v3', 'mf-captcha-challenge', 'mf-captcha-challenge', 'mf-captcha' );
		$fields = array();
		foreach ( (array) $form_data as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] || in_array( $key, $skip, true ) || false !== strpos( $key, 'nonce' ) ) {
				continue;
			}
			$label    = ucwords( trim( str_replace( array( 'mf-', '-', '_' ), array( '', ' ', ' ' ), $key ) ) );
			$fields[] = $this->field( $key, '' !== $label ? $label : $key, $value );
		}
		$this->capture( $form_id, get_the_title( $form_id ), $fields );
	}
}
