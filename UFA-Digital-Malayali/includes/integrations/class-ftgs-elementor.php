<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_Elementor extends FTGS_Integration {

	private $label_cache = array();

	public function get_slug() {
		return 'elementor';
	}

	public function get_label() {
		return 'Elementor Pro Forms';
	}

	public function is_active() {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	public function hooks() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'handle' ), 10, 2 );
	}

	public function get_help() {
		return __( 'Forms are identified by the form widget\'s element ID. You can also type the widget\'s "Form Name".', 'forms-to-google-sheets' );
	}

	/**
	 * Find form widgets in Elementor page data: element id => "Form Name — Page".
	 */
	public function get_forms() {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND pm.meta_value LIKE '%\"widgetType\":\"form\"%' AND p.post_status IN ('publish','private','draft') LIMIT 300" ); // phpcs:ignore
		foreach ( (array) $rows as $r ) {
			$data = json_decode( $r->meta_value, true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$title = get_the_title( $r->post_id );
			foreach ( $this->find_forms( $data ) as $el ) {
				$name               = isset( $el['settings']['form_name'] ) ? $el['settings']['form_name'] : 'Form';
				$out[ $el['id'] ] = $name . ' — ' . $title;
			}
		}
		return $out;
	}

	private function find_forms( array $elements ) {
		$found = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] && ! empty( $el['id'] ) ) {
				$found[] = $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = array_merge( $found, $this->find_forms( $el['elements'] ) );
			}
		}
		return $found;
	}

	public function handle( $record, $handler ) {
		$settings = (array) $record->get( 'form_settings' );
		$name     = isset( $settings['form_name'] ) ? (string) $settings['form_name'] : '';
		$elem_id  = isset( $settings['id'] ) ? (string) $settings['id'] : '';

		$meta = array();
		$m    = (array) $record->get( 'meta' );
		if ( isset( $m['page_url']['value'] ) ) {
			$meta['page_url'] = esc_url_raw( $m['page_url']['value'] );
		}

		$out = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $f ) {
			$type = isset( $f['type'] ) ? $f['type'] : '';
			if ( in_array( $type, $this->skip_types(), true ) ) {
				continue;
			}
			$label = ! empty( $f['title'] ) ? $f['title'] : $id;
			$out[] = $this->field( $id, $label, isset( $f['value'] ) ? $f['value'] : '' );
		}

		// Connections may point to the element id (picker) or the form name (typed).
		$form_key = $elem_id;
		if ( '' !== $name && ! FTGS_Connections::matching( 'elementor', $elem_id ) && FTGS_Connections::matching( 'elementor', $name ) ) {
			$form_key = $name;
		}
		$this->capture( '' !== $form_key ? $form_key : $name, $name, $out, '', $meta );
	}

	/* Sync from Elementor Pro "Submissions" ------------------------------ */

	public function supports_native_sync() {
		global $wpdb;
		return $this->table_exists( $wpdb->prefix . 'e_submissions' );
	}

	public function get_entries( array $form_ids, array $args ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'e_submissions';
		$tv    = $wpdb->prefix . 'e_submissions_values';
		$where = "status <> 'trash'";
		$vals  = array();
		if ( $form_ids ) {
			$ph     = implode( ',', array_fill( 0, count( $form_ids ), '%s' ) );
			$where .= " AND (element_id IN ({$ph}) OR form_name IN ({$ph}))";
			$vals   = array_merge( $vals, $form_ids, $form_ids );
		}
		list( $dsql, $dvals ) = $this->date_sql( 'created_at', $args );
		$where               .= $dsql;
		$vals                 = array_merge( $vals, $dvals, array( (int) $args['limit'] + 1, (int) $args['offset'] ) );
		$rows                 = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $vals ), ARRAY_A ); // phpcs:ignore
		$has_more             = count( $rows ) > (int) $args['limit'];
		$items                = array();
		foreach ( array_slice( $rows, 0, (int) $args['limit'] ) as $r ) {
			$labels = $this->labels( (int) $r['post_id'], (string) $r['element_id'] );
			$values = (array) $wpdb->get_results( $wpdb->prepare( "SELECT `key`, `value` FROM {$tv} WHERE submission_id = %d ORDER BY id ASC", $r['id'] ), ARRAY_A ); // phpcs:ignore
			$fields = array();
			foreach ( $values as $v ) {
				$fields[] = $this->field( $v['key'], isset( $labels[ $v['key'] ] ) ? $labels[ $v['key'] ] : $v['key'], $v['value'] );
			}
			$key = ( $form_ids && ! in_array( (string) $r['element_id'], $form_ids, true ) ) ? (string) $r['form_name'] : (string) $r['element_id'];
			$items[] = array(
				'native_id' => (string) $r['id'],
				'form_id'   => $key,
				'form_name' => (string) $r['form_name'],
				'fields'    => $fields,
				'date'      => (string) $r['created_at'],
				'page_url'  => (string) $r['referer'],
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}

	private function labels( $post_id, $element_id ) {
		$k = $post_id . ':' . $element_id;
		if ( isset( $this->label_cache[ $k ] ) ) {
			return $this->label_cache[ $k ];
		}
		$labels = array();
		$data   = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
		if ( is_array( $data ) ) {
			foreach ( $this->find_forms( $data ) as $el ) {
				if ( $el['id'] !== $element_id || empty( $el['settings']['form_fields'] ) ) {
					continue;
				}
				foreach ( (array) $el['settings']['form_fields'] as $f ) {
					if ( ! empty( $f['custom_id'] ) ) {
						$labels[ $f['custom_id'] ] = ! empty( $f['field_label'] ) ? $f['field_label'] : $f['custom_id'];
					}
				}
			}
		}
		$this->label_cache[ $k ] = $labels;
		return $labels;
	}
}
