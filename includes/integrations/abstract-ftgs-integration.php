<?php
/**
 * Base class for form-plugin integrations.
 */

defined( 'ABSPATH' ) || exit;

abstract class FTGS_Integration {

	/** Unique slug, e.g. "cf7". */
	abstract public function get_slug();

	/** Human label. */
	abstract public function get_label();

	/** Is the form plugin installed and active? */
	abstract public function is_active();

	/** Attach submission hooks. */
	abstract public function hooks();

	/** Forms for the admin picker: array( id => title ). */
	public function get_forms() {
		return array();
	}

	/** Short help text shown under the form picker. */
	public function get_help() {
		return '';
	}

	/**
	 * Can existing entries be read from the form plugin's own database?
	 */
	public function supports_native_sync() {
		return false;
	}

	/**
	 * Does a live submission carry the same entry id the form plugin stores?
	 * If not, native sync only imports entries older than our first stored submission,
	 * so nothing is sent twice.
	 */
	public function live_has_native_id() {
		return false;
	}

	/**
	 * Read stored entries (oldest first).
	 *
	 * @param array $form_ids Form ids, empty = all forms.
	 * @param array $args     from (Y-m-d), to (Y-m-d), before (Y-m-d H:i:s), offset, limit.
	 * @return array items => list of native_id, form_id, form_name, fields, date, page_url; has_more => bool
	 */
	public function get_entries( array $form_ids, array $args ) {
		return array( 'items' => array(), 'has_more' => false );
	}

	public function register() {
		if ( $this->is_active() ) {
			$this->hooks();
		}
	}

	/**
	 * Hand a live submission to the queue. Never breaks the visitor's form submission.
	 */
	protected function capture( $form_id, $form_name, array $fields, $native_id = '', array $meta = array() ) {
		try {
			return FTGS_Queue::capture( $this->get_slug(), (string) $form_id, (string) $form_name, $fields, (string) $native_id, $meta );
		} catch ( \Throwable $e ) {
			FTGS_Logger::add( $this->get_label(), 'error', 'Capture failed: ' . $e->getMessage() );
			return 0;
		}
	}

	protected function field( $key, $label, $value ) {
		return array(
			'key'   => (string) $key,
			'label' => wp_strip_all_tags( (string) $label ),
			'value' => $value,
		);
	}

	/** Field types that carry no user data. */
	protected function skip_types() {
		return array( 'submit', 'html', 'divider', 'pagebreak', 'page', 'page-break', 'section', 'section_break', 'captcha', 'recaptcha', 'hcaptcha', 'turnstile', 'honeypot', 'step', 'hr', 'content', 'break', 'end_divider', 'button', 'password', 'gdpr-notice', 'layout', 'container', 'repeater_end' );
	}

	/** Helpers for date filtering in PHP. */
	protected function in_range( $date, array $args ) {
		$d = substr( (string) $date, 0, 19 );
		if ( ! empty( $args['from'] ) && $d < $args['from'] . ' 00:00:00' ) {
			return false;
		}
		if ( ! empty( $args['to'] ) && $d > $args['to'] . ' 23:59:59' ) {
			return false;
		}
		if ( ! empty( $args['before'] ) && $d >= $args['before'] ) {
			return false;
		}
		return true;
	}

	protected function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore
	}

	/**
	 * SQL date conditions for a column.
	 *
	 * @return array( string $sql, array $values )
	 */
	protected function date_sql( $column, array $args ) {
		$sql  = '';
		$vals = array();
		if ( ! empty( $args['from'] ) ) {
			$sql   .= " AND {$column} >= %s";
			$vals[] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) ) {
			$sql   .= " AND {$column} <= %s";
			$vals[] = $args['to'] . ' 23:59:59';
		}
		if ( ! empty( $args['before'] ) ) {
			$sql   .= " AND {$column} < %s";
			$vals[] = $args['before'];
		}
		return array( $sql, $vals );
	}
}
