<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_JetFormBuilder extends FTGS_Integration {

	public function get_slug() {
		return 'jetformbuilder';
	}

	public function get_label() {
		return 'JetFormBuilder';
	}

	public function is_active() {
		return function_exists( 'jet_form_builder' ) || class_exists( '\Jet_Form_Builder\Plugin' );
	}

	public function hooks() {
		add_action( 'jet-form-builder/form-handler/after-send', array( $this, 'handle' ), 20, 2 );
	}

	public function get_forms() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'jet-form-builder', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	public function handle( $handler = null, $is_success = true ) {
		if ( ! $is_success ) {
			return;
		}
		$data = array();
		if ( function_exists( 'jet_fb_context' ) ) {
			$ctx = jet_fb_context();
			if ( is_object( $ctx ) && method_exists( $ctx, 'resolve_request' ) ) {
				$data = (array) $ctx->resolve_request();
			}
		}
		if ( ! $data && function_exists( 'jet_fb_action_handler' ) ) {
			$ah = jet_fb_action_handler();
			if ( is_object( $ah ) && isset( $ah->request_data ) ) {
				$data = (array) $ah->request_data;
			}
		}
		$form_id = 0;
		if ( is_object( $handler ) && isset( $handler->form_id ) ) {
			$form_id = (int) $handler->form_id;
		} elseif ( function_exists( 'jet_fb_handler' ) && is_object( jet_fb_handler() ) && isset( jet_fb_handler()->form_id ) ) {
			$form_id = (int) jet_fb_handler()->form_id;
		}
		if ( ! $data || ! $form_id ) {
			return;
		}
		$fields = array();
		foreach ( $data as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] || false !== strpos( $key, 'nonce' ) || false !== strpos( $key, 'captcha' ) || in_array( $key, array( 'action', 'form_id', 'refer', '__form_id', '__refer', '__is_ajax' ), true ) ) {
				continue;
			}
			$fields[] = $this->field( $key, $key, $value );
		}
		$this->capture( $form_id, get_the_title( $form_id ), $fields );
	}
}
