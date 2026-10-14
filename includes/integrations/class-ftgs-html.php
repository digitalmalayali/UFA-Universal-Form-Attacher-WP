<?php
/**
 * Any plain HTML form on the site. Add data-ftgs-form="your-form-name" to a <form>;
 * assets/frontend.js mirrors the submission to the REST endpoint without blocking it.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Integration_HTML extends FTGS_Integration {

	public function get_slug() {
		return 'html';
	}

	public function get_label() {
		return 'Any HTML form';
	}

	public function is_active() {
		return true;
	}

	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function get_help() {
		return __( 'Add data-ftgs-form="contact" to your <form> tag and enter contact here. Give a field a nicer column name with data-label="…", skip one with data-ftgs-ignore.', 'forms-to-google-sheets' );
	}

	public function enqueue() {
		$has = false;
		foreach ( FTGS_Connections::all() as $c ) {
			if ( $c['enabled'] && in_array( $c['source'], array( 'html', 'any' ), true ) ) {
				$has = true;
				break;
			}
		}
		if ( ! apply_filters( 'ftgs_enqueue_frontend', $has ) ) {
			return;
		}
		wp_enqueue_script( 'ftgs-frontend', FTGS_URL . 'assets/frontend.js', array(), FTGS_VERSION, true );
		wp_localize_script(
			'ftgs-frontend',
			'FTGS',
			array(
				'endpoint' => esc_url_raw( rest_url( 'ftgs/v1/submit' ) ),
				'selector' => apply_filters( 'ftgs_html_selector', 'form[data-ftgs-form], form.ftgs-form' ),
			)
		);
	}

	/**
	 * Called by the REST controller with already-validated input.
	 */
	public function handle_rest( $form_id, array $fields, $page_url ) {
		$block = array( '_wpnonce', '_wp_http_referer', 'g-recaptcha-response', 'h-captcha-response', 'cf-turnstile-response', 'ftgs_hp' );
		$out   = array();
		foreach ( array_slice( $fields, 0, 100 ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$key = isset( $f['key'] ) ? sanitize_text_field( (string) $f['key'] ) : '';
			if ( '' === $key || in_array( strtolower( $key ), $block, true ) || preg_match( '/^(pass|password|passwd|pwd|confirm[_-]?password|card[_-]?number|cc[_-]?number)$|cvv|cvc|^iban$/i', $key ) ) {
				continue;
			}
			$label = isset( $f['label'] ) ? sanitize_text_field( (string) $f['label'] ) : $key;
			$value = isset( $f['value'] ) ? $f['value'] : '';
			if ( is_array( $value ) ) {
				$value = array_map( 'sanitize_textarea_field', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			} else {
				$value = sanitize_textarea_field( (string) $value );
			}
			$out[] = $this->field( $key, '' !== $label ? $label : $key, $value );
		}
		if ( ! $out ) {
			return 0;
		}
		return $this->capture( $form_id, $form_id, $out, '', array( 'page_url' => $page_url ) );
	}
}
