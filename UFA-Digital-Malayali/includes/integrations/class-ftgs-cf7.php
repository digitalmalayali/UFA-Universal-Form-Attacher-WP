<?php
defined( 'ABSPATH' ) || exit;

class FTGS_Integration_CF7 extends FTGS_Integration {

	public function get_slug() {
		return 'cf7';
	}

	public function get_label() {
		return 'Contact Form 7';
	}

	public function is_active() {
		return defined( 'WPCF7_VERSION' );
	}

	public function hooks() {
		// After validation & spam checks; uploaded files still exist at this point.
		add_action( 'wpcf7_before_send_mail', array( $this, 'handle' ), 99, 1 );
	}

	public function get_help() {
		return __( 'Sync of old entries needs the free Flamingo or CFDB7 plugin (Contact Form 7 itself stores nothing).', 'forms-to-google-sheets' );
	}

	public function get_forms() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->ID ] = $p->post_title;
		}
		return $out;
	}

	public function handle( $contact_form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) || ! is_object( $contact_form ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$posted = (array) $submission->get_posted_data();
		$files  = method_exists( $submission, 'uploaded_files' ) ? (array) $submission->uploaded_files() : array();
		$meta   = array();
		if ( method_exists( $submission, 'get_meta' ) && $submission->get_meta( 'url' ) ) {
			$meta['page_url'] = esc_url_raw( $submission->get_meta( 'url' ) );
		}

		$fields = array();
		$seen   = array();
		foreach ( $contact_form->scan_form_tags() as $tag ) {
			if ( empty( $tag->name ) || in_array( $tag->basetype, array_merge( $this->skip_types(), array( 'quiz' ) ), true ) ) {
				continue;
			}
			$name = $tag->name;
			if ( isset( $seen[ $name ] ) ) {
				continue;
			}
			$seen[ $name ] = true;

			if ( 'file' === $tag->basetype ) {
				$paths = isset( $files[ $name ] ) ? (array) $files[ $name ] : array();
				$value = FTGS_Settings::get( 'cf7_save_files' ) ? $this->save_files( $paths ) : array_map( 'wp_basename', $paths );
			} else {
				$value = isset( $posted[ $name ] ) ? $posted[ $name ] : '';
			}
			$fields[] = $this->field( $name, $name, $value );
		}

		$this->capture( $contact_form->id(), $contact_form->title(), $fields, '', $meta );
	}

	/**
	 * Copy uploads out of CF7's temp folder (it deletes them after sending mail).
	 *
	 * @return string[] Public URLs.
	 */
	private function save_files( array $paths ) {
		$urls = array();
		$up   = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return array_map( 'wp_basename', $paths );
		}
		$root = trailingslashit( $up['basedir'] ) . 'ftgs-uploads';
		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$rel = gmdate( 'Y/m' ) . '/' . wp_generate_password( 16, false, false );
		$dir = $root . '/' . $rel;
		foreach ( $paths as $path ) {
			if ( ! is_string( $path ) || ! is_file( $path ) ) {
				continue;
			}
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$name = sanitize_file_name( wp_basename( $path ) );
			$type = wp_check_filetype( $name );
			if ( empty( $type['ext'] ) || preg_match( '/\.(php\d?|phtml|phar|cgi|pl|py|sh|htaccess)$/i', $name ) ) {
				$urls[] = $name; // Do not publish unexpected file types.
				continue;
			}
			$name = wp_unique_filename( $dir, $name );
			if ( copy( $path, $dir . '/' . $name ) ) {
				$urls[] = trailingslashit( $up['baseurl'] ) . 'ftgs-uploads/' . $rel . '/' . rawurlencode( $name );
			} else {
				$urls[] = $name;
			}
		}
		return $urls;
	}

	/* ------------------------------------------------------------------ */
	/* Sync from Flamingo or CFDB7                                         */
	/* ------------------------------------------------------------------ */

	public function supports_native_sync() {
		return $this->has_flamingo() || $this->has_cfdb7();
	}

	private function has_flamingo() {
		return post_type_exists( 'flamingo_inbound' );
	}

	private function has_cfdb7() {
		global $wpdb;
		return $this->table_exists( $wpdb->prefix . 'db7_forms' );
	}

	public function get_entries( array $form_ids, array $args ) {
		return $this->has_flamingo() ? $this->flamingo_entries( $form_ids, $args ) : $this->cfdb7_entries( $form_ids, $args );
	}

	private function flamingo_entries( array $form_ids, array $args ) {
		$forms    = $this->get_forms();
		$channels = array();
		foreach ( $forms as $id => $title ) {
			if ( empty( $form_ids ) || in_array( (string) $id, $form_ids, true ) ) {
				$post = get_post( $id );
				if ( $post ) {
					$channels[ $post->post_name ] = $id;
				}
			}
		}
		if ( ! $channels ) {
			return array( 'items' => array(), 'has_more' => false );
		}
		$date_query = array( 'inclusive' => true );
		if ( ! empty( $args['from'] ) ) {
			$date_query['after'] = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) ) {
			$date_query['before'] = $args['to'] . ' 23:59:59';
		}
		$q = new WP_Query(
			array(
				'post_type'      => 'flamingo_inbound',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $args['limit'] + 1,
				'offset'         => (int) $args['offset'],
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'flamingo_inbound_channel',
						'field'    => 'slug',
						'terms'    => array_keys( $channels ),
					),
				),
				'date_query'     => count( $date_query ) > 1 ? array( $date_query ) : array(),
			)
		);
		$posts    = $q->posts;
		$has_more = count( $posts ) > (int) $args['limit'];
		$posts    = array_slice( $posts, 0, (int) $args['limit'] );
		$items    = array();
		foreach ( $posts as $p ) {
			if ( ! $this->in_range( $p->post_date, $args ) ) {
				continue;
			}
			$terms   = wp_get_post_terms( $p->ID, 'flamingo_inbound_channel', array( 'fields' => 'slugs' ) );
			$form_id = '';
			foreach ( (array) $terms as $slug ) {
				if ( isset( $channels[ $slug ] ) ) {
					$form_id = $channels[ $slug ];
				}
			}
			$names  = get_post_meta( $p->ID, '_fields', true );
			$fields = array();
			foreach ( array_keys( (array) $names ) as $name ) {
				$fields[] = $this->field( $name, $name, get_post_meta( $p->ID, '_field_' . $name, true ) );
			}
			$meta    = get_post_meta( $p->ID, '_meta', true );
			$items[] = array(
				'native_id' => 'flamingo' . $p->ID,
				'form_id'   => $form_id,
				'form_name' => isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : '',
				'fields'    => $fields,
				'date'      => $p->post_date,
				'page_url'  => is_array( $meta ) && isset( $meta['url'] ) ? $meta['url'] : '',
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}

	private function cfdb7_entries( array $form_ids, array $args ) {
		global $wpdb;
		$t     = $wpdb->prefix . 'db7_forms';
		$forms = $this->get_forms();
		$where = '1=1';
		$vals  = array();
		if ( $form_ids ) {
			$where .= ' AND form_post_id IN (' . implode( ',', array_map( 'absint', $form_ids ) ) . ')';
		}
		list( $dsql, $dvals ) = $this->date_sql( 'form_date', $args );
		$where               .= $dsql;
		$vals                 = array_merge( $vals, $dvals );
		$vals[]               = (int) $args['limit'] + 1;
		$vals[]               = (int) $args['offset'];
		$rows                 = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY form_id ASC LIMIT %d OFFSET %d", $vals ), ARRAY_A ); // phpcs:ignore
		$has_more             = count( $rows ) > (int) $args['limit'];
		$items                = array();
		foreach ( array_slice( (array) $rows, 0, (int) $args['limit'] ) as $r ) {
			$values = is_serialized( $r['form_value'] ) ? @unserialize( trim( $r['form_value'] ), array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
			$fields = array();
			foreach ( (array) $values as $k => $v ) {
				if ( 0 === strpos( (string) $k, 'cfdb7_status' ) ) {
					continue;
				}
				$name     = preg_replace( '/cfdb7_file$/', '', (string) $k );
				$fields[] = $this->field( $name, $name, $v );
			}
			$items[] = array(
				'native_id' => 'cfdb7' . $r['form_id'],
				'form_id'   => (string) $r['form_post_id'],
				'form_name' => isset( $forms[ $r['form_post_id'] ] ) ? $forms[ $r['form_post_id'] ] : '',
				'fields'    => $fields,
				'date'      => $r['form_date'],
				'page_url'  => '',
			);
		}
		return array( 'items' => $items, 'has_more' => $has_more );
	}
}
