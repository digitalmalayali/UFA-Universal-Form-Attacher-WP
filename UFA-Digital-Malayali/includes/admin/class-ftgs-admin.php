<?php
/**
 * Admin UI: connections, submissions, activity log, settings and setup guide.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Admin {

	const SLUG = 'ftgs';
	const CAP  = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FTGS_FILE ), array( __CLASS__, 'action_links' ) );

		foreach ( array( 'save', 'delete', 'toggle', 'duplicate', 'clear_log', 'save_settings', 'export', 'import', 'sub_resend', 'sub_delete', 'retry_failed', 'export_csv' ) as $a ) {
			add_action( 'admin_post_ftgs_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'wp_ajax_ftgs_test', array( __CLASS__, 'ajax_test' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'forms-to-google-sheets' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		$counts = FTGS_Submissions::counts();
		$failed = isset( $counts['failed'] ) ? (int) $counts['failed'] : 0;
		$badge  = $failed ? ' <span class="awaiting-mod">' . number_format_i18n( $failed ) . '</span>' : '';
		add_menu_page(
			__( 'Forms to Google Sheets', 'forms-to-google-sheets' ),
			__( 'Forms → Sheets', 'forms-to-google-sheets' ) . $badge,
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-media-spreadsheet',
			58
		);
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ftgs-admin', FTGS_URL . 'assets/admin.css', array(), FTGS_VERSION );
		wp_enqueue_script( 'ftgs-admin', FTGS_URL . 'assets/admin.js', array( 'jquery' ), FTGS_VERSION, true );
		wp_localize_script(
			'ftgs-admin',
			'FTGSAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ftgs_admin' ),
				'i18n'  => array(
					'copied'      => __( 'Copied!', 'forms-to-google-sheets' ),
					'sending'     => __( 'Sending…', 'forms-to-google-sheets' ),
					'failed'      => __( 'Request failed', 'forms-to-google-sheets' ),
					'confirmDel'  => __( 'Delete this connection? Stored submissions are kept.', 'forms-to-google-sheets' ),
					'confirmSubs' => __( 'Delete the selected submissions from WordPress? Rows already in Google Sheets are not affected.', 'forms-to-google-sheets' ),
					'allForms'    => __( 'All forms', 'forms-to-google-sheets' ),
					'syncDone'    => __( 'Sync finished.', 'forms-to-google-sheets' ),
					'syncing'     => __( 'Syncing…', 'forms-to-google-sheets' ),
					'stopped'     => __( 'Sync stopped:', 'forms-to-google-sheets' ),
					'resume'      => __( 'Click "Start sync" again to continue where it stopped.', 'forms-to-google-sheets' ),
					'saveFirst'   => __( 'Save your changes before syncing.', 'forms-to-google-sheets' ),
				),
			)
		);
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function check( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'forms-to-google-sheets' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( $args ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function get( $key, $default = '' ) {
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ================================================================== */
	/* Page shell                                                          */
	/* ================================================================== */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab  = sanitize_key( self::get( 'tab', 'connections' ) );
		$view = sanitize_key( self::get( 'view' ) );
		$tabs = array(
			'connections' => __( 'Connections', 'forms-to-google-sheets' ),
			'submissions' => __( 'Submissions', 'forms-to-google-sheets' ),
			'log'         => __( 'Activity Log', 'forms-to-google-sheets' ),
			'settings'    => __( 'Settings', 'forms-to-google-sheets' ),
			'setup'       => __( 'Setup Guide', 'forms-to-google-sheets' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connections';
		}
		echo '<div class="wrap ftgs-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Forms to Google Sheets', 'forms-to-google-sheets' ) . '</h1>';
		if ( 'connections' === $tab && ! $view ) {
			echo ' <a class="page-title-action" href="' . esc_url( self::url( array( 'view' => 'edit' ) ) ) . '">' . esc_html__( 'Add Connection', 'forms-to-google-sheets' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		self::notices();

		$counts = FTGS_Submissions::counts();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			$extra = ( 'submissions' === $k && ! empty( $counts['failed'] ) ) ? ' <span class="ftgs-count">' . (int) $counts['failed'] . '</span>' : '';
			printf( '<a class="nav-tab %s" href="%s">%s%s</a>', $tab === $k ? 'nav-tab-active' : '', esc_url( self::url( array( 'tab' => $k ) ) ), esc_html( $label ), $extra ); // phpcs:ignore
		}
		echo '</nav><div class="ftgs-body">';

		switch ( $tab ) {
			case 'submissions':
				'view' === $view ? self::render_submission() : self::render_submissions();
				break;
			case 'log':
				self::render_log();
				break;
			case 'settings':
				self::render_settings();
				break;
			case 'setup':
				self::render_setup();
				break;
			default:
				'edit' === $view ? self::render_edit() : self::render_list();
		}
		echo '</div></div>';
	}

	private static function notices() {
		$msg = sanitize_key( self::get( 'ftgs_msg' ) );
		$n   = absint( self::get( 'n', 0 ) );
		$map = array(
			'saved'      => array( 'success', __( 'Connection saved.', 'forms-to-google-sheets' ) ),
			'deleted'    => array( 'success', __( 'Connection deleted.', 'forms-to-google-sheets' ) ),
			'toggled'    => array( 'success', __( 'Connection updated.', 'forms-to-google-sheets' ) ),
			'duplicated' => array( 'success', __( 'Connection duplicated. It is paused until you activate it.', 'forms-to-google-sheets' ) ),
			'cleared'    => array( 'success', __( 'Log cleared.', 'forms-to-google-sheets' ) ),
			'settings'   => array( 'success', __( 'Settings saved.', 'forms-to-google-sheets' ) ),
			'badurl'     => array( 'error', __( 'Saved, but the webhook URL is missing or not a valid https:// URL. Nothing will be sent until you fix it.', 'forms-to-google-sheets' ) ),
			/* translators: %d: number */
			'imported'   => array( 'success', sprintf( __( '%d connection(s) imported.', 'forms-to-google-sheets' ), $n ) ),
			'badimport'  => array( 'error', __( 'That file is not a valid Forms to Google Sheets export.', 'forms-to-google-sheets' ) ),
			/* translators: %d: number */
			'resent'     => array( 'success', sprintf( __( 'Resent %d submission(s). Check the status column for the result.', 'forms-to-google-sheets' ), $n ) ),
			/* translators: %d: number */
			'subdeleted' => array( 'success', sprintf( __( '%d submission(s) deleted.', 'forms-to-google-sheets' ), $n ) ),
			'nothing'    => array( 'warning', __( 'Nothing selected.', 'forms-to-google-sheets' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
		$counts = FTGS_Submissions::counts();
		if ( ! empty( $counts['failed'] ) && 'submissions' !== self::get( 'tab' ) ) {
			printf(
				'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( sprintf( /* translators: %d: number */ _n( '%d submission could not be sent to Google Sheets.', '%d submissions could not be sent to Google Sheets.', $counts['failed'], 'forms-to-google-sheets' ), $counts['failed'] ) ),
				esc_url( self::url( array( 'tab' => 'submissions', 'status' => 'failed' ) ) ),
				esc_html__( 'Review and retry', 'forms-to-google-sheets' )
			);
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON && 'settings' === self::get( 'tab' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'WP-Cron is disabled on this site (DISABLE_WP_CRON). Make sure a real cron job calls wp-cron.php, otherwise retries will not run.', 'forms-to-google-sheets' ) . '</p></div>';
		}
	}

	private static function pill( $status ) {
		$labels = array(
			'sent'    => array( 'on', __( 'Sent', 'forms-to-google-sheets' ) ),
			'pending' => array( 'warn', __( 'Pending', 'forms-to-google-sheets' ) ),
			'failed'  => array( 'bad', __( 'Failed', 'forms-to-google-sheets' ) ),
			'skipped' => array( 'off', __( 'Skipped', 'forms-to-google-sheets' ) ),
			'stored'  => array( 'off', __( 'Stored only', 'forms-to-google-sheets' ) ),
			'success' => array( 'on', __( 'Success', 'forms-to-google-sheets' ) ),
			'retry'   => array( 'warn', __( 'Retry', 'forms-to-google-sheets' ) ),
			'error'   => array( 'bad', __( 'Error', 'forms-to-google-sheets' ) ),
			'info'    => array( 'off', __( 'Info', 'forms-to-google-sheets' ) ),
		);
		$l = isset( $labels[ $status ] ) ? $labels[ $status ] : array( 'off', ucfirst( (string) $status ) );
		return '<span class="ftgs-pill ' . esc_attr( $l[0] ) . '">' . esc_html( $l[1] ) . '</span>';
	}

	private static function source_label( $slug ) {
		if ( 'any' === $slug ) {
			return __( 'All form plugins', 'forms-to-google-sheets' );
		}
		$i = ftgs_integration( $slug );
		return $i ? $i->get_label() : $slug;
	}

	/* ================================================================== */
	/* Connections list                                                    */
	/* ================================================================== */

	private static function render_list() {
		$items = FTGS_Connections::all();

		$active = array();
		foreach ( ftgs_integrations() as $i ) {
			if ( 'html' !== $i->get_slug() ) {
				$active[] = '<span class="ftgs-pill ' . ( $i->is_active() ? 'on' : 'off' ) . '">' . esc_html( $i->get_label() ) . '</span>';
			}
		}
		echo '<p class="ftgs-detected"><strong>' . esc_html__( 'Form plugins:', 'forms-to-google-sheets' ) . '</strong> ' . implode( ' ', $active ) . '</p>'; // phpcs:ignore

		if ( empty( $items ) ) {
			echo '<div class="ftgs-empty"><h2>' . esc_html__( 'No connections yet', 'forms-to-google-sheets' ) . '</h2>';
			echo '<p>' . esc_html__( 'A connection sends the forms you choose to one tab of a Google Sheet.', 'forms-to-google-sheets' ) . '</p>';
			echo '<p><a class="button button-primary button-hero" href="' . esc_url( self::url( array( 'view' => 'edit' ) ) ) . '">' . esc_html__( 'Add your first connection', 'forms-to-google-sheets' ) . '</a> ';
			echo '<a class="button button-hero" href="' . esc_url( self::url( array( 'tab' => 'setup' ) ) ) . '">' . esc_html__( 'Read the setup guide', 'forms-to-google-sheets' ) . '</a></p></div>';
			return;
		}

		echo '<table class="widefat striped ftgs-table"><thead><tr>';
		foreach ( array( __( 'Name', 'forms-to-google-sheets' ), __( 'Form plugin', 'forms-to-google-sheets' ), __( 'Forms', 'forms-to-google-sheets' ), __( 'Sheet tab', 'forms-to-google-sheets' ), __( 'Status', 'forms-to-google-sheets' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $c ) {
			$integ     = ftgs_integration( $c['source'] );
			$edit      = self::url( array( 'view' => 'edit', 'id' => $c['id'] ) );
			$toggle    = wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_toggle&id=' . $c['id'] ), 'ftgs_toggle_' . $c['id'] );
			$delete    = wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_delete&id=' . $c['id'] ), 'ftgs_delete_' . $c['id'] );
			$duplicate = wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_duplicate&id=' . $c['id'] ), 'ftgs_duplicate_' . $c['id'] );
			$ids       = FTGS_Connections::form_ids( $c );
			$forms     = ( 'any' === $c['source'] || ! $ids ) ? __( 'All forms', 'forms-to-google-sheets' ) : implode( ', ', $ids );
			$warning   = '';
			if ( $integ && ! $integ->is_active() ) {
				$warning = ' <span class="ftgs-warn">' . esc_html__( '(plugin not active)', 'forms-to-google-sheets' ) . '</span>';
			}
			if ( empty( $c['webhook_url'] ) ) {
				$warning .= ' <span class="ftgs-warn">' . esc_html__( '(no webhook URL)', 'forms-to-google-sheets' ) . '</span>';
			}
			$extra = '';
			if ( '' !== trim( $c['conditions'] ) ) {
				$extra = ' <span class="ftgs-tag">' . esc_html__( 'conditions', 'forms-to-google-sheets' ) . '</span>';
			}

			echo '<tr>';
			echo '<td><strong><a href="' . esc_url( $edit ) . '">' . esc_html( $c['name'] ? $c['name'] : $c['id'] ) . '</a></strong>' . $extra . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( self::source_label( $c['source'] ) ) . $warning . '</td>'; // phpcs:ignore
			echo '<td>' . esc_html( $forms ) . '</td>';
			echo '<td>' . esc_html( $c['sheet'] ) . '</td>';
			echo '<td>' . ( $c['enabled'] ? self::pill( 'success' ) : '<span class="ftgs-pill off">' . esc_html__( 'Paused', 'forms-to-google-sheets' ) . '</span>' ) . '</td>'; // phpcs:ignore
			echo '<td class="ftgs-actions">';
			echo '<button type="button" class="button ftgs-test" data-id="' . esc_attr( $c['id'] ) . '">' . esc_html__( 'Send test', 'forms-to-google-sheets' ) . '</button> ';
			echo '<a class="button" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'forms-to-google-sheets' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $toggle ) . '">' . ( $c['enabled'] ? esc_html__( 'Pause', 'forms-to-google-sheets' ) : esc_html__( 'Activate', 'forms-to-google-sheets' ) ) . '</a> ';
			echo '<a class="button" href="' . esc_url( $duplicate ) . '">' . esc_html__( 'Duplicate', 'forms-to-google-sheets' ) . '</a> ';
			echo '<a class="button ftgs-delete" href="' . esc_url( $delete ) . '">' . esc_html__( 'Delete', 'forms-to-google-sheets' ) . '</a>';
			echo '<div class="ftgs-test-result" data-for="' . esc_attr( $c['id'] ) . '"></div>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/* ================================================================== */
	/* Connection editor                                                   */
	/* ================================================================== */

	private static function render_edit() {
		$id    = sanitize_key( self::get( 'id' ) );
		$c     = $id ? FTGS_Connections::get( $id ) : null;
		$isnew = ! $c;
		if ( $isnew ) {
			$c           = FTGS_Connections::defaults();
			$c['secret'] = wp_generate_password( 24, false, false );
		}
		$integrations = ftgs_integrations();
		$forms_json   = array();
		$help_json    = array();
		foreach ( $integrations as $slug => $i ) {
			$forms_json[ $slug ] = (object) ( $i->is_active() ? $i->get_forms() : array() );
			$help_json[ $slug ]  = $i->get_help();
		}
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'All connections', 'forms-to-google-sheets' ); ?></a></p>
		<div class="ftgs-grid">
		<div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ftgs-card" id="ftgs-edit-form">
			<input type="hidden" name="action" value="ftgs_save">
			<input type="hidden" name="id" value="<?php echo esc_attr( $c['id'] ); ?>">
			<?php wp_nonce_field( 'ftgs_save' ); ?>
			<h2><?php echo $isnew ? esc_html__( 'New connection', 'forms-to-google-sheets' ) : esc_html__( 'Edit connection', 'forms-to-google-sheets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ftgs-name"><?php esc_html_e( 'Name', 'forms-to-google-sheets' ); ?></label></th>
					<td><input id="ftgs-name" name="name" type="text" class="regular-text" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="<?php esc_attr_e( 'Contact page leads', 'forms-to-google-sheets' ); ?>" required></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'forms-to-google-sheets' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $c['enabled'] ); ?>> <?php esc_html_e( 'Active', 'forms-to-google-sheets' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="ftgs-source"><?php esc_html_e( 'Form plugin', 'forms-to-google-sheets' ); ?></label></th>
					<td>
						<select id="ftgs-source" name="source">
							<option value="any" <?php selected( $c['source'], 'any' ); ?>><?php esc_html_e( 'All form plugins (every form on the site)', 'forms-to-google-sheets' ); ?></option>
							<?php foreach ( $integrations as $slug => $i ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $c['source'], $slug ); ?>>
									<?php echo esc_html( $i->get_label() . ( $i->is_active() ? '' : ' — ' . __( 'not active', 'forms-to-google-sheets' ) ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="ftgs-forms-row">
					<th><label for="ftgs-form-id"><?php esc_html_e( 'Forms', 'forms-to-google-sheets' ); ?></label></th>
					<td>
						<input id="ftgs-form-id" name="form_id" type="text" class="regular-text" value="<?php echo esc_attr( $c['form_id'] ); ?>">
						<p class="description"><?php esc_html_e( 'Use * for all forms, or comma-separated form IDs. Click forms below to pick them.', 'forms-to-google-sheets' ); ?></p>
						<div id="ftgs-form-picker" class="ftgs-picker" data-forms="<?php echo esc_attr( wp_json_encode( $forms_json ) ); ?>" data-help="<?php echo esc_attr( wp_json_encode( $help_json ) ); ?>"></div>
						<p class="description ftgs-help" id="ftgs-source-help"></p>
					</td>
				</tr>
				<tr>
					<th><label for="ftgs-url"><?php esc_html_e( 'Webhook URL', 'forms-to-google-sheets' ); ?></label></th>
					<td>
						<input id="ftgs-url" name="webhook_url" type="url" class="large-text code" value="<?php echo esc_attr( $c['webhook_url'] ); ?>" placeholder="https://script.google.com/macros/s/XXXX/exec" required>
						<p class="description"><?php esc_html_e( 'The Apps Script Web app URL ending in /exec (see Setup Guide). Any webhook that accepts JSON also works.', 'forms-to-google-sheets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ftgs-secret"><?php esc_html_e( 'Secret', 'forms-to-google-sheets' ); ?></label></th>
					<td>
						<input id="ftgs-secret" name="secret" type="text" class="regular-text code" value="<?php echo esc_attr( $c['secret'] ); ?>" pattern="[A-Za-z0-9_\-]{8,64}" title="<?php esc_attr_e( '8–64 letters, numbers, - or _', 'forms-to-google-sheets' ); ?>">
						<button type="button" class="button" id="ftgs-regen"><?php esc_html_e( 'Regenerate', 'forms-to-google-sheets' ); ?></button>
						<p class="description"><?php esc_html_e( 'Must match SECRETS in the Apps Script. If you change it, re-copy the script and deploy a new version.', 'forms-to-google-sheets' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ftgs-sheet"><?php esc_html_e( 'Sheet tab name', 'forms-to-google-sheets' ); ?></label></th>
					<td><input id="ftgs-sheet" name="sheet" type="text" class="regular-text" value="<?php echo esc_attr( $c['sheet'] ); ?>" maxlength="99">
					<p class="description"><?php esc_html_e( 'Created automatically if it does not exist.', 'forms-to-google-sheets' ); ?></p></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Extra columns', 'forms-to-google-sheets' ); ?></th>
					<td class="ftgs-checks">
						<?php foreach ( FTGS_Connections::meta_options() as $k => $label ) : ?>
							<label><input type="checkbox" name="meta[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $c['meta'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'An "Entry ID" column is always added first. It prevents duplicate rows.', 'forms-to-google-sheets' ); ?></p>
					</td>
				</tr>
			</table>

			<details class="ftgs-advanced" <?php echo ( '' !== trim( $c['conditions'] . $c['mapping'] . $c['exclude'] . $c['column_order'] ) ) ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Advanced: conditions, column names, order and exclusions', 'forms-to-google-sheets' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="ftgs-conditions"><?php esc_html_e( 'Only send when', 'forms-to-google-sheets' ); ?></label></th>
						<td>
							<select name="condition_mode">
								<option value="all" <?php selected( $c['condition_mode'], 'all' ); ?>><?php esc_html_e( 'ALL rules match', 'forms-to-google-sheets' ); ?></option>
								<option value="any" <?php selected( $c['condition_mode'], 'any' ); ?>><?php esc_html_e( 'ANY rule matches', 'forms-to-google-sheets' ); ?></option>
							</select>
							<textarea id="ftgs-conditions" name="conditions" rows="4" class="large-text code" placeholder="service == SEO&#10;budget >= 1000&#10;email is not empty"><?php echo esc_textarea( $c['conditions'] ); ?></textarea>
							<p class="description"><?php echo wp_kses_post( __( 'One rule per line: <code>field operator value</code>. Field = key or label. Operators: <code>==</code> <code>!=</code> <code>contains</code> <code>not contains</code> <code>starts with</code> <code>ends with</code> <code>&gt;</code> <code>&lt;</code> <code>&gt;=</code> <code>&lt;=</code> <code>is empty</code> <code>is not empty</code>. Leave empty to send everything.', 'forms-to-google-sheets' ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ftgs-mapping"><?php esc_html_e( 'Rename columns', 'forms-to-google-sheets' ); ?></label></th>
						<td><textarea id="ftgs-mapping" name="mapping" rows="4" class="large-text code" placeholder="your-email => Email&#10;your-name => Full Name"><?php echo esc_textarea( $c['mapping'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line: field key or label => column header.', 'forms-to-google-sheets' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="ftgs-order"><?php esc_html_e( 'Column order', 'forms-to-google-sheets' ); ?></label></th>
						<td><textarea id="ftgs-order" name="column_order" rows="3" class="large-text code" placeholder="Timestamp&#10;Full Name&#10;Email"><?php echo esc_textarea( $c['column_order'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Column headers (after renaming) to put first, one per line. Applies when the columns are created; existing columns in the sheet keep their position.', 'forms-to-google-sheets' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="ftgs-exclude"><?php esc_html_e( 'Exclude fields', 'forms-to-google-sheets' ); ?></label></th>
						<td><input id="ftgs-exclude" name="exclude" type="text" class="large-text code" value="<?php echo esc_attr( $c['exclude'] ); ?>" placeholder="acceptance, gdpr-consent">
						<p class="description"><?php esc_html_e( 'Comma-separated field keys or labels to leave out.', 'forms-to-google-sheets' ); ?></p></td>
					</tr>
				</table>
			</details>
			<?php submit_button( __( 'Save connection', 'forms-to-google-sheets' ) ); ?>
		</form>

		<?php if ( ! $isnew ) : ?>
			<?php $modes = FTGS_Sync::modes( $c ); ?>
			<div class="ftgs-card" id="ftgs-sync" data-id="<?php echo esc_attr( $c['id'] ); ?>">
				<h2><?php esc_html_e( 'Sync existing entries', 'forms-to-google-sheets' ); ?></h2>
				<p><?php esc_html_e( 'Send entries that were submitted before this connection existed, or that were missed. Entries already in the sheet are skipped, so it is safe to run more than once.', 'forms-to-google-sheets' ); ?></p>
				<p class="ftgs-sync-fields">
					<label><?php esc_html_e( 'Source', 'forms-to-google-sheets' ); ?>
						<select id="ftgs-sync-mode">
							<?php foreach ( $modes as $m => $label ) : ?>
								<option value="<?php echo esc_attr( $m ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'From', 'forms-to-google-sheets' ); ?> <input type="date" id="ftgs-sync-from"></label>
					<label><?php esc_html_e( 'To', 'forms-to-google-sheets' ); ?> <input type="date" id="ftgs-sync-to"></label>
				</p>
				<?php if ( ! isset( $modes['native'] ) ) : ?>
					<?php $integ = ftgs_integration( $c['source'] ); ?>
					<?php
					if ( 'any' === $c['source'] ) {
						$note = __( 'With "All form plugins", only submissions saved by this plugin can be synced. Create a connection for a single form plugin to import its older entries.', 'forms-to-google-sheets' );
					} elseif ( $integ && ! $integ->is_active() ) {
						$note = __( 'This form plugin is not active.', 'forms-to-google-sheets' );
					} elseif ( $integ && $integ->get_help() ) {
						$note = $integ->get_help();
					} else {
						$note = __( 'This form plugin does not store entries; only submissions saved by this plugin can be synced.', 'forms-to-google-sheets' );
					}
					?>
					<p class="description"><?php echo esc_html( $note ); ?></p>
				<?php endif; ?>
				<p>
					<button type="button" class="button button-primary" id="ftgs-sync-start"><?php esc_html_e( 'Start sync', 'forms-to-google-sheets' ); ?></button>
					<button type="button" class="button" id="ftgs-sync-stop" hidden><?php esc_html_e( 'Stop', 'forms-to-google-sheets' ); ?></button>
				</p>
				<div class="ftgs-progress" hidden><div class="ftgs-progress-bar"></div></div>
				<p id="ftgs-sync-status" class="ftgs-sync-status" aria-live="polite"></p>
			</div>
		<?php endif; ?>
		</div>

		<div class="ftgs-card ftgs-side">
			<h2><?php esc_html_e( 'Apps Script for this connection', 'forms-to-google-sheets' ); ?></h2>
			<ol class="ftgs-steps">
				<li><?php echo wp_kses_post( __( 'In your Google Sheet open <strong>Extensions → Apps Script</strong>.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Replace all code in <code>Code.gs</code> with this script and click <strong>Save</strong>.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Deploy → New deployment → Web app</strong>. Execute as: <em>Me</em>. Who has access: <em>Anyone</em>.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Paste the <code>/exec</code> URL into <strong>Webhook URL</strong> and save.', 'forms-to-google-sheets' ) ); ?></li>
			</ol>
			<p><button type="button" class="button button-secondary ftgs-copy" data-target="#ftgs-code"><?php esc_html_e( 'Copy script', 'forms-to-google-sheets' ); ?></button></p>
			<textarea id="ftgs-code" class="ftgs-code" readonly rows="14" data-secret="<?php echo esc_attr( $c['secret'] ); ?>"><?php echo esc_textarea( self::script( $c['secret'] ) ); ?></textarea>
			<?php if ( ! $isnew ) : ?>
				<hr>
				<p><button type="button" class="button button-primary ftgs-test" data-id="<?php echo esc_attr( $c['id'] ); ?>"><?php esc_html_e( 'Send test row', 'forms-to-google-sheets' ); ?></button></p>
				<div class="ftgs-test-result" data-for="<?php echo esc_attr( $c['id'] ); ?>"></div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Save the connection to send a test row.', 'forms-to-google-sheets' ); ?></p>
			<?php endif; ?>
		</div>
		</div>
		<?php
	}

	public static function script( $secret ) {
		$code = (string) file_get_contents( FTGS_DIR . 'apps-script/Code.gs' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return str_replace( '{{SECRET}}', preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $secret ), $code );
	}

	/* ================================================================== */
	/* Submissions                                                         */
	/* ================================================================== */

	private static function render_submissions() {
		$status   = sanitize_key( self::get( 'status' ) );
		$source   = sanitize_key( self::get( 'source' ) );
		$search   = self::get( 's' );
		$paged    = max( 1, absint( self::get( 'paged', 1 ) ) );
		$per_page = 20;
		$res      = FTGS_Submissions::query( compact( 'status', 'source', 'search', 'paged', 'per_page' ) );
		$counts   = FTGS_Submissions::counts();
		$conns    = FTGS_Connections::all();

		$statuses = array(
			''        => __( 'All', 'forms-to-google-sheets' ),
			'failed'  => __( 'Failed', 'forms-to-google-sheets' ),
			'pending' => __( 'Pending', 'forms-to-google-sheets' ),
			'sent'    => __( 'Sent', 'forms-to-google-sheets' ),
			'skipped' => __( 'Skipped', 'forms-to-google-sheets' ),
			'stored'  => __( 'Stored only', 'forms-to-google-sheets' ),
		);
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( $statuses as $k => $label ) {
			$n       = '' === $k ? ( isset( $counts['all'] ) ? $counts['all'] : 0 ) : ( isset( $counts[ $k ] ) ? $counts[ $k ] : 0 );
			$links[] = sprintf( '<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a>', esc_url( self::url( array( 'tab' => 'submissions', 'status' => $k ) ) ), $status === $k ? 'current' : '', esc_html( $label ), (int) $n );
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore

		?>
		<form method="get" class="ftgs-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<input type="hidden" name="tab" value="submissions">
			<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<select name="source">
				<option value=""><?php esc_html_e( 'All form plugins', 'forms-to-google-sheets' ); ?></option>
				<?php foreach ( ftgs_integrations() as $slug => $i ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $source, $slug ); ?>><?php echo esc_html( $i->get_label() ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search submissions…', 'forms-to-google-sheets' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'forms-to-google-sheets' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ftgs-subs-form">
			<?php wp_nonce_field( 'ftgs_subs' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions">
					<button type="submit" class="button" name="action" value="ftgs_sub_resend"><?php esc_html_e( 'Resend selected', 'forms-to-google-sheets' ); ?></button>
					<button type="submit" class="button ftgs-subs-delete" name="action" value="ftgs_sub_delete"><?php esc_html_e( 'Delete selected', 'forms-to-google-sheets' ); ?></button>
				</div>
				<div class="alignleft actions">
					<?php if ( ! empty( $counts['failed'] ) ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_retry_failed' ), 'ftgs_retry_failed' ) ); ?>"><?php esc_html_e( 'Retry all failed', 'forms-to-google-sheets' ); ?></a>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_export_csv&status=' . $status . '&source=' . $source . '&s=' . rawurlencode( $search ) ), 'ftgs_export_csv' ) ); ?>"><?php esc_html_e( 'Export CSV', 'forms-to-google-sheets' ); ?></a>
				</div>
				<?php self::pagination( $res['total'], $per_page, $paged ); ?>
			</div>
			<table class="widefat striped ftgs-table">
				<thead><tr>
					<td class="check-column"><input type="checkbox" id="ftgs-check-all"></td>
					<th><?php esc_html_e( 'Date', 'forms-to-google-sheets' ); ?></th>
					<th><?php esc_html_e( 'Form', 'forms-to-google-sheets' ); ?></th>
					<th><?php esc_html_e( 'Data', 'forms-to-google-sheets' ); ?></th>
					<th><?php esc_html_e( 'Delivery', 'forms-to-google-sheets' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $res['items'] ) : ?>
					<tr><td colspan="5"><em><?php esc_html_e( 'No submissions found.', 'forms-to-google-sheets' ); ?></em></td></tr>
				<?php endif; ?>
				<?php foreach ( $res['items'] as $r ) : ?>
					<?php
					$preview = array();
					foreach ( array_slice( $r['fields'], 0, 3 ) as $f ) {
						$preview[] = '<strong>' . esc_html( $f['label'] ) . ':</strong> ' . esc_html( wp_html_excerpt( FTGS_Row::flatten( $f['value'], false ), 60, '…' ) );
					}
					$view = self::url( array( 'tab' => 'submissions', 'view' => 'view', 'id' => $r['id'] ) );
					?>
					<tr>
						<th class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $r['id'] ); ?>"></th>
						<td><a href="<?php echo esc_url( $view ); ?>"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $r['created_at'] ) ); ?></a><br><span class="ftgs-muted">#<?php echo (int) $r['id']; ?></span></td>
						<td><?php echo esc_html( $r['form_name'] ); ?><br><span class="ftgs-muted"><?php echo esc_html( self::source_label( $r['source'] ) ); ?></span></td>
						<td class="ftgs-preview"><?php echo implode( '<br>', $preview ); // phpcs:ignore ?></td>
						<td>
							<?php if ( ! $r['deliveries'] ) : ?>
								<?php echo self::pill( 'stored' ); // phpcs:ignore ?>
							<?php endif; ?>
							<?php foreach ( $r['deliveries'] as $cid => $d ) : ?>
								<div class="ftgs-delivery" title="<?php echo esc_attr( isset( $d['m'] ) ? $d['m'] : '' ); ?>">
									<?php echo self::pill( $d['s'] ); // phpcs:ignore ?>
									<?php echo esc_html( isset( $conns[ $cid ] ) ? $conns[ $cid ]['name'] : __( '(deleted connection)', 'forms-to-google-sheets' ) ); ?>
								</div>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="tablenav bottom"><?php self::pagination( $res['total'], $per_page, $paged ); ?></div>
		</form>
		<p class="description"><?php esc_html_e( 'Every submission is saved here first and then sent in the background. Failed sends are retried automatically for about 7 hours (1 min, 5 min, 15 min, 1 h, 6 h).', 'forms-to-google-sheets' ); ?></p>
		<?php
	}

	private static function pagination( $total, $per_page, $paged ) {
		$pages = (int) ceil( $total / $per_page );
		echo '<div class="tablenav-pages"><span class="displaying-num">' . esc_html( sprintf( /* translators: %s: number */ _n( '%s item', '%s items', $total, 'forms-to-google-sheets' ), number_format_i18n( $total ) ) ) . '</span>';
		if ( $pages > 1 ) {
			echo ' ' . paginate_links( // phpcs:ignore
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $pages,
					'prev_text' => '‹',
					'next_text' => '›',
				)
			);
		}
		echo '</div>';
	}

	private static function render_submission() {
		$r = FTGS_Submissions::get( absint( self::get( 'id' ) ) );
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'submissions' ) ) ) . '">&larr; ' . esc_html__( 'All submissions', 'forms-to-google-sheets' ) . '</a></p>';
		if ( ! $r ) {
			echo '<p>' . esc_html__( 'Submission not found.', 'forms-to-google-sheets' ) . '</p>';
			return;
		}
		$conns  = FTGS_Connections::all();
		$resend = wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_sub_resend&ids[]=' . $r['id'] ), 'ftgs_subs' );
		?>
		<div class="ftgs-grid">
			<div class="ftgs-card">
				<h2><?php echo esc_html( $r['form_name'] ); ?> <span class="ftgs-muted">#<?php echo (int) $r['id']; ?></span></h2>
				<table class="widefat striped">
					<tbody>
					<?php foreach ( $r['fields'] as $f ) : ?>
						<tr><th style="width:30%"><?php echo esc_html( $f['label'] ); ?><br><code class="ftgs-muted"><?php echo esc_html( $f['key'] ); ?></code></th><td><?php echo nl2br( esc_html( FTGS_Row::flatten( $f['value'], false ) ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="ftgs-card ftgs-side">
				<h2><?php esc_html_e( 'Details', 'forms-to-google-sheets' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Entry ID:', 'forms-to-google-sheets' ); ?></strong> <code><?php echo esc_html( $r['uid'] ); ?></code><br>
					<strong><?php esc_html_e( 'Form plugin:', 'forms-to-google-sheets' ); ?></strong> <?php echo esc_html( self::source_label( $r['source'] ) ); ?> (<?php echo esc_html( $r['form_id'] ); ?>)<br>
					<strong><?php esc_html_e( 'Submitted:', 'forms-to-google-sheets' ); ?></strong> <?php echo esc_html( $r['created_at'] ); ?><br>
					<?php foreach ( array( 'page_url' => __( 'Page', 'forms-to-google-sheets' ), 'ip' => __( 'IP', 'forms-to-google-sheets' ), 'user' => __( 'User', 'forms-to-google-sheets' ) ) as $k => $label ) : ?>
						<?php if ( ! empty( $r['meta'][ $k ] ) ) : ?>
							<strong><?php echo esc_html( $label ); ?>:</strong> <?php echo esc_html( $r['meta'][ $k ] ); ?><br>
						<?php endif; ?>
					<?php endforeach; ?>
				</p>
				<h3><?php esc_html_e( 'Delivery', 'forms-to-google-sheets' ); ?></h3>
				<?php if ( ! $r['deliveries'] ) : ?>
					<p><?php esc_html_e( 'No connection matched this form when it was submitted.', 'forms-to-google-sheets' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $r['deliveries'] as $cid => $d ) : ?>
					<p class="ftgs-delivery">
						<?php echo self::pill( $d['s'] ); // phpcs:ignore ?>
						<strong><?php echo esc_html( isset( $conns[ $cid ] ) ? $conns[ $cid ]['name'] : __( '(deleted connection)', 'forms-to-google-sheets' ) ); ?></strong><br>
						<span class="ftgs-muted">
							<?php
							/* translators: %d: attempts */
							echo esc_html( sprintf( __( 'Attempts: %d', 'forms-to-google-sheets' ), (int) $d['a'] ) );
							if ( ! empty( $d['t'] ) ) {
								echo ' · ' . esc_html( $d['t'] );
							}
							if ( ! empty( $d['n'] ) && 'pending' === $d['s'] ) {
								/* translators: %s: time */
								echo ' · ' . esc_html( sprintf( __( 'next try %s', 'forms-to-google-sheets' ), wp_date( 'Y-m-d H:i', (int) $d['n'] ) ) );
							}
							?>
						</span>
						<?php if ( ! empty( $d['m'] ) ) : ?>
							<br><?php echo esc_html( $d['m'] ); ?>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( $resend ); ?>"><?php esc_html_e( 'Resend now', 'forms-to-google-sheets' ); ?></a></p>
				<p class="description"><?php esc_html_e( 'Resend goes to every active connection that matches this form. Rows already in the sheet are skipped.', 'forms-to-google-sheets' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ================================================================== */
	/* Log, settings, setup                                                */
	/* ================================================================== */

	private static function render_log() {
		$log   = FTGS_Logger::all();
		$clear = wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_clear_log' ), 'ftgs_clear_log' );
		echo '<p>' . esc_html__( 'The last 300 events.', 'forms-to-google-sheets' );
		if ( $log ) {
			echo ' <a class="button" href="' . esc_url( $clear ) . '">' . esc_html__( 'Clear log', 'forms-to-google-sheets' ) . '</a>';
		}
		echo '</p>';
		if ( ! $log ) {
			echo '<p><em>' . esc_html__( 'Nothing logged yet.', 'forms-to-google-sheets' ) . '</em></p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time', 'forms-to-google-sheets' ) . '</th><th>' . esc_html__( 'Connection', 'forms-to-google-sheets' ) . '</th><th>' . esc_html__( 'Form', 'forms-to-google-sheets' ) . '</th><th>' . esc_html__( 'Status', 'forms-to-google-sheets' ) . '</th><th>' . esc_html__( 'Message', 'forms-to-google-sheets' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $row ) {
			$form = esc_html( $row['form'] );
			if ( ! empty( $row['sub_id'] ) ) {
				$form = '<a href="' . esc_url( self::url( array( 'tab' => 'submissions', 'view' => 'view', 'id' => $row['sub_id'] ) ) ) . '">' . ( '' !== $form ? $form : '#' . (int) $row['sub_id'] ) . '</a>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row['time'] ),
				esc_html( $row['connection'] ),
				$form, // phpcs:ignore
				self::pill( $row['status'] ), // phpcs:ignore
				esc_html( $row['message'] )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_settings() {
		$s = FTGS_Settings::all();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ftgs-card">
			<input type="hidden" name="action" value="ftgs_save_settings">
			<?php wp_nonce_field( 'ftgs_save_settings' ); ?>
			<h2><?php esc_html_e( 'Sending', 'forms-to-google-sheets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Send mode', 'forms-to-google-sheets' ); ?></th>
					<td>
						<label><input type="radio" name="send_mode" value="background" <?php checked( $s['send_mode'], 'background' ); ?>> <?php esc_html_e( 'Background (recommended) – the visitor does not wait for Google', 'forms-to-google-sheets' ); ?></label><br>
						<label><input type="radio" name="send_mode" value="immediate" <?php checked( $s['send_mode'], 'immediate' ); ?>> <?php esc_html_e( 'Immediately – send before the form shows its success message', 'forms-to-google-sheets' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Failure alerts', 'forms-to-google-sheets' ); ?></th>
					<td>
						<label><input type="checkbox" name="alert_enabled" value="1" <?php checked( $s['alert_enabled'] ); ?>> <?php esc_html_e( 'Email me when a submission cannot be delivered after all retries (max. one email per hour)', 'forms-to-google-sheets' ); ?></label><br>
						<input type="text" name="alert_email" class="regular-text" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="you@example.com, team@example.com">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Contact Form 7 uploads', 'forms-to-google-sheets' ); ?></th>
					<td><label><input type="checkbox" name="cf7_save_files" value="1" <?php checked( $s['cf7_save_files'] ); ?>> <?php esc_html_e( 'Keep uploaded files and put their links in the sheet (stored in uploads/ftgs-uploads with unguessable folder names)', 'forms-to-google-sheets' ); ?></label></td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Storage', 'forms-to-google-sheets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Backup', 'forms-to-google-sheets' ); ?></th>
					<td><label><input type="checkbox" name="store_all" value="1" <?php checked( $s['store_all'] ); ?>> <?php esc_html_e( 'Also save submissions of forms that have no connection yet (so they can be synced later)', 'forms-to-google-sheets' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="ftgs-retention"><?php esc_html_e( 'Keep submissions for', 'forms-to-google-sheets' ); ?></label></th>
					<td><input id="ftgs-retention" type="number" min="0" name="retention_days" value="<?php echo esc_attr( $s['retention_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days (0 = forever). Pending submissions are never deleted.', 'forms-to-google-sheets' ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Uninstall', 'forms-to-google-sheets' ); ?></th>
					<td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Delete all connections, settings and stored submissions when the plugin is deleted', 'forms-to-google-sheets' ); ?></label></td>
				</tr>
			</table>
			<h2><?php esc_html_e( 'Plain HTML forms – spam protection', 'forms-to-google-sheets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ftgs-min"><?php esc_html_e( 'Minimum fill time', 'forms-to-google-sheets' ); ?></label></th>
					<td><input id="ftgs-min" type="number" min="0" max="60" name="html_min_seconds" value="<?php echo esc_attr( $s['html_min_seconds'] ); ?>" class="small-text"> <?php esc_html_e( 'seconds (faster submissions are ignored as bots)', 'forms-to-google-sheets' ); ?></td>
				</tr>
				<tr>
					<th><label for="ftgs-rate"><?php esc_html_e( 'Rate limit', 'forms-to-google-sheets' ); ?></label></th>
					<td><input id="ftgs-rate" type="number" min="1" name="html_rate_limit" value="<?php echo esc_attr( $s['html_rate_limit'] ); ?>" class="small-text"> <?php esc_html_e( 'submissions per minute per visitor', 'forms-to-google-sheets' ); ?>
					<p class="description"><?php echo wp_kses_post( __( 'Optional honeypot: add <code>&lt;input type="text" name="ftgs_hp" style="display:none" tabindex="-1" autocomplete="off"&gt;</code> to your form. Requests from other websites are always rejected.', 'forms-to-google-sheets' ) ); ?></p></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'forms-to-google-sheets' ) ); ?>
		</form>

		<div class="ftgs-card">
			<h2><?php esc_html_e( 'Export / import connections', 'forms-to-google-sheets' ); ?></h2>
			<p><?php esc_html_e( 'Move your connections between sites (e.g. staging → live). The export contains your webhook URLs and secrets – keep the file private.', 'forms-to-google-sheets' ); ?></p>
			<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ftgs_export' ), 'ftgs_export' ) ); ?>"><?php esc_html_e( 'Download export (.json)', 'forms-to-google-sheets' ); ?></a></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ftgs_import">
				<?php wp_nonce_field( 'ftgs_import' ); ?>
				<input type="file" name="file" accept=".json,application/json" required>
				<label><input type="checkbox" name="replace" value="1"> <?php esc_html_e( 'Replace existing connections', 'forms-to-google-sheets' ); ?></label>
				<button class="button"><?php esc_html_e( 'Import', 'forms-to-google-sheets' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function render_setup() {
		?>
		<div class="ftgs-card ftgs-guide">
			<h2><?php esc_html_e( 'Connect a Google Sheet in 5 steps', 'forms-to-google-sheets' ); ?></h2>
			<ol>
				<li><?php echo wp_kses_post( __( 'Create (or open) a Google Sheet and go to <strong>Extensions → Apps Script</strong>.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Here in WordPress click <strong>Connections → Add Connection</strong>, choose the form plugin and forms, then click <strong>Copy script</strong> on the right (it already contains your secret). In Apps Script replace everything in <code>Code.gs</code> with it and click <strong>Save</strong>.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Click <strong>Deploy → New deployment</strong>, choose type <strong>Web app</strong>, set <em>Execute as: Me</em> and <em>Who has access: Anyone</em>, then <strong>Deploy</strong> and authorize the script.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Copy the <strong>Web app URL</strong> (ends in <code>/exec</code>), paste it into <strong>Webhook URL</strong> and save.', 'forms-to-google-sheets' ) ); ?></li>
				<li><?php echo wp_kses_post( __( 'Click <strong>Send test row</strong>. Then use <strong>Sync existing entries</strong> to bring in older entries.', 'forms-to-google-sheets' ) ); ?></li>
			</ol>
			<h3><?php esc_html_e( 'Troubleshooting', 'forms-to-google-sheets' ); ?></h3>
			<table class="widefat striped">
				<tbody>
					<tr><th><?php esc_html_e( 'HTTP 403 / "You need access"', 'forms-to-google-sheets' ); ?></th><td><?php esc_html_e( 'The deployment is not public. Deploy → Manage deployments → Edit → Who has access: Anyone → Version: New version → Deploy. Use the /exec URL, not /dev. On Google Workspace accounts your admin may need to allow "Anyone" – or use a personal Gmail account for the script.', 'forms-to-google-sheets' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Invalid secret', 'forms-to-google-sheets' ); ?></th><td><?php esc_html_e( 'The deployed script has a different secret. Copy the script again from the connection screen, save, then deploy a New version (just saving is not enough).', 'forms-to-google-sheets' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Old Apps Script', 'forms-to-google-sheets' ); ?></th><td><?php esc_html_e( 'If you used version 1 of this plugin, copy the new script and deploy a new version to enable batches (sync) and duplicate protection.', 'forms-to-google-sheets' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Rows stay "Pending"', 'forms-to-google-sheets' ); ?></th><td><?php esc_html_e( 'Retries run via WP-Cron, which needs site traffic. If DISABLE_WP_CRON is set, add a server cron job for wp-cron.php.', 'forms-to-google-sheets' ); ?></td></tr>
				</tbody>
			</table>
			<h3><?php esc_html_e( 'Good to know', 'forms-to-google-sheets' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Headers are created automatically from field labels. New fields become new columns; you can reorder columns in the sheet freely.', 'forms-to-google-sheets' ); ?></li>
				<li><?php esc_html_e( 'Keep the "Entry ID" column: it is how duplicates are detected. You can hide it in Sheets.', 'forms-to-google-sheets' ); ?></li>
				<li><?php esc_html_e( 'Several connections can share one sheet: use different tab names and list every secret in the SECRETS array.', 'forms-to-google-sheets' ); ?></li>
				<li><?php esc_html_e( 'Plain HTML forms: add data-ftgs-form="your-name" to the form tag and create a connection with "Any HTML form".', 'forms-to-google-sheets' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/* ================================================================== */
	/* Handlers                                                            */
	/* ================================================================== */

	public static function handle_save() {
		self::check( 'ftgs_save' );
		$p   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$url = isset( $p['webhook_url'] ) ? esc_url_raw( trim( (string) $p['webhook_url'] ), array( 'https', 'http' ) ) : '';
		$id  = isset( $p['id'] ) ? sanitize_key( $p['id'] ) : '';

		$source = isset( $p['source'] ) ? sanitize_key( $p['source'] ) : '';
		if ( 'any' !== $source && ! ftgs_integration( $source ) ) {
			$source = 'cf7';
		}
		$existing = $id ? FTGS_Connections::get( $id ) : null;
		$secret   = preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $p['secret'] ) ? (string) $p['secret'] : '' );

		$data = array(
			'id'             => $existing ? $id : '',
			'name'           => sanitize_text_field( isset( $p['name'] ) ? $p['name'] : '' ),
			'enabled'        => empty( $p['enabled'] ) ? 0 : 1,
			'source'         => $source,
			'form_id'        => sanitize_text_field( isset( $p['form_id'] ) && '' !== trim( $p['form_id'] ) ? $p['form_id'] : '*' ),
			'webhook_url'    => $url,
			'secret'         => strlen( $secret ) >= 8 ? substr( $secret, 0, 64 ) : '',
			'sheet'          => mb_substr( sanitize_text_field( isset( $p['sheet'] ) && '' !== trim( $p['sheet'] ) ? $p['sheet'] : 'Sheet1' ), 0, 99 ),
			'mapping'        => sanitize_textarea_field( isset( $p['mapping'] ) ? $p['mapping'] : '' ),
			'exclude'        => sanitize_text_field( isset( $p['exclude'] ) ? $p['exclude'] : '' ),
			'column_order'   => sanitize_textarea_field( isset( $p['column_order'] ) ? $p['column_order'] : '' ),
			'conditions'     => sanitize_textarea_field( isset( $p['conditions'] ) ? $p['conditions'] : '' ),
			'condition_mode' => isset( $p['condition_mode'] ) && 'any' === $p['condition_mode'] ? 'any' : 'all',
			'meta'           => isset( $p['meta'] ) ? array_map( 'sanitize_key', (array) $p['meta'] ) : array(),
			'created_at'     => $existing ? $existing['created_at'] : '',
		);
		$saved = FTGS_Connections::save( $data );
		$ok    = $url && ( wp_http_validate_url( $url ) || 0 === strpos( $url, 'http://localhost' ) || 0 === strpos( $url, 'http://127.0.0.1' ) );
		self::redirect( array( 'view' => 'edit', 'id' => $saved['id'], 'ftgs_msg' => $ok ? 'saved' : 'badurl' ) );
	}

	public static function handle_delete() {
		$id = sanitize_key( self::get( 'id' ) );
		self::check( 'ftgs_delete_' . $id );
		FTGS_Connections::delete( $id );
		self::redirect( array( 'ftgs_msg' => 'deleted' ) );
	}

	public static function handle_toggle() {
		$id = sanitize_key( self::get( 'id' ) );
		self::check( 'ftgs_toggle_' . $id );
		$c = FTGS_Connections::get( $id );
		if ( $c ) {
			$c['enabled'] = $c['enabled'] ? 0 : 1;
			FTGS_Connections::save( $c );
		}
		self::redirect( array( 'ftgs_msg' => 'toggled' ) );
	}

	public static function handle_duplicate() {
		$id = sanitize_key( self::get( 'id' ) );
		self::check( 'ftgs_duplicate_' . $id );
		$c = FTGS_Connections::get( $id );
		if ( $c ) {
			$c['id']         = '';
			$c['created_at'] = '';
			$c['enabled']    = 0;
			/* translators: %s: connection name */
			$c['name'] = sprintf( __( '%s (copy)', 'forms-to-google-sheets' ), $c['name'] );
			FTGS_Connections::save( $c );
		}
		self::redirect( array( 'ftgs_msg' => 'duplicated' ) );
	}

	public static function handle_clear_log() {
		self::check( 'ftgs_clear_log' );
		FTGS_Logger::clear();
		self::redirect( array( 'tab' => 'log', 'ftgs_msg' => 'cleared' ) );
	}

	public static function handle_save_settings() {
		self::check( 'ftgs_save_settings' );
		FTGS_Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::redirect( array( 'tab' => 'settings', 'ftgs_msg' => 'settings' ) );
	}

	public static function handle_export() {
		self::check( 'ftgs_export' );
		$data = array(
			'plugin'      => 'forms-to-google-sheets',
			'version'     => FTGS_VERSION,
			'exported'    => gmdate( 'c' ),
			'connections' => array_values( FTGS_Connections::all() ),
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=forms-to-google-sheets-' . gmdate( 'Y-m-d' ) . '.json' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore
		exit;
	}

	public static function handle_import() {
		self::check( 'ftgs_import' );
		$file = isset( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : ''; // phpcs:ignore
		$raw  = ( $file && is_uploaded_file( $file ) ) ? file_get_contents( $file ) : ''; // phpcs:ignore
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['plugin'], $data['connections'] ) || 'forms-to-google-sheets' !== $data['plugin'] || ! is_array( $data['connections'] ) ) {
			self::redirect( array( 'tab' => 'settings', 'ftgs_msg' => 'badimport' ) );
		}
		if ( ! empty( $_POST['replace'] ) ) { // phpcs:ignore
			update_option( FTGS_Connections::OPTION, array(), false );
		}
		$n = 0;
		foreach ( $data['connections'] as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$source = isset( $c['source'] ) ? sanitize_key( $c['source'] ) : '';
			if ( 'any' !== $source && ! ftgs_integration( $source ) ) {
				continue;
			}
			$clean = array(
				'id'             => isset( $c['id'] ) ? sanitize_key( $c['id'] ) : '',
				'name'           => sanitize_text_field( isset( $c['name'] ) ? $c['name'] : '' ),
				'enabled'        => empty( $c['enabled'] ) ? 0 : 1,
				'source'         => $source,
				'form_id'        => sanitize_text_field( isset( $c['form_id'] ) ? $c['form_id'] : '*' ),
				'webhook_url'    => esc_url_raw( isset( $c['webhook_url'] ) ? $c['webhook_url'] : '', array( 'https', 'http' ) ),
				'secret'         => preg_replace( '/[^A-Za-z0-9_\-]/', '', isset( $c['secret'] ) ? (string) $c['secret'] : '' ),
				'sheet'          => sanitize_text_field( isset( $c['sheet'] ) ? $c['sheet'] : 'Sheet1' ),
				'mapping'        => sanitize_textarea_field( isset( $c['mapping'] ) ? $c['mapping'] : '' ),
				'exclude'        => sanitize_text_field( isset( $c['exclude'] ) ? $c['exclude'] : '' ),
				'column_order'   => sanitize_textarea_field( isset( $c['column_order'] ) ? $c['column_order'] : '' ),
				'conditions'     => sanitize_textarea_field( isset( $c['conditions'] ) ? $c['conditions'] : '' ),
				'condition_mode' => isset( $c['condition_mode'] ) && 'any' === $c['condition_mode'] ? 'any' : 'all',
				'meta'           => isset( $c['meta'] ) ? array_map( 'sanitize_key', (array) $c['meta'] ) : array(),
			);
			FTGS_Connections::save( $clean );
			++$n;
		}
		self::redirect( array( 'tab' => 'settings', 'ftgs_msg' => 'imported', 'n' => $n ) );
	}

	private static function posted_ids() {
		$ids = isset( $_REQUEST['ids'] ) ? (array) wp_unslash( $_REQUEST['ids'] ) : array(); // phpcs:ignore
		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	public static function handle_sub_resend() {
		self::check( 'ftgs_subs' );
		$ids = self::posted_ids();
		if ( ! $ids ) {
			self::redirect( array( 'tab' => 'submissions', 'ftgs_msg' => 'nothing' ) );
		}
		$start = time();
		$done  = 0;
		foreach ( $ids as $id ) {
			if ( time() - $start > 40 ) {
				// Leave the rest for the background queue.
				FTGS_Submissions::update( $id, array( 'next_try' => current_time( 'mysql' ) ) );
				continue;
			}
			FTGS_Queue::resend( $id );
			++$done;
		}
		$args = array( 'tab' => 'submissions', 'ftgs_msg' => 'resent', 'n' => $done );
		if ( 1 === count( $ids ) && false !== strpos( (string) wp_get_referer(), 'view=view' ) ) {
			$args = array_merge( $args, array( 'view' => 'view', 'id' => $ids[0] ) );
		}
		self::redirect( $args );
	}

	public static function handle_sub_delete() {
		self::check( 'ftgs_subs' );
		$n = FTGS_Submissions::delete( self::posted_ids() );
		self::redirect( array( 'tab' => 'submissions', 'ftgs_msg' => 'subdeleted', 'n' => $n ) );
	}

	public static function handle_retry_failed() {
		self::check( 'ftgs_retry_failed' );
		$res   = FTGS_Submissions::query( array( 'status' => 'failed', 'per_page' => 500 ) );
		$start = time();
		$done  = 0;
		foreach ( $res['items'] as $r ) {
			if ( time() - $start > 40 ) {
				break;
			}
			FTGS_Queue::resend( $r['id'] );
			++$done;
		}
		self::redirect( array( 'tab' => 'submissions', 'ftgs_msg' => 'resent', 'n' => $done ) );
	}

	public static function handle_export_csv() {
		self::check( 'ftgs_export_csv' );
		$args = array(
			'status'   => sanitize_key( self::get( 'status' ) ),
			'source'   => sanitize_key( self::get( 'source' ) ),
			'search'   => self::get( 's' ),
			'per_page' => 500,
			'paged'    => 1,
		);
		$rows    = array();
		$columns = array( 'ID', 'Entry ID', 'Date', 'Form plugin', 'Form', 'Status' );
		do {
			$res = FTGS_Submissions::query( $args );
			foreach ( $res['items'] as $r ) {
				$line = array(
					'ID'          => $r['id'],
					'Entry ID'    => $r['uid'],
					'Date'        => $r['created_at'],
					'Form plugin' => self::source_label( $r['source'] ),
					'Form'        => $r['form_name'],
					'Status'      => $r['status'],
				);
				foreach ( $r['fields'] as $f ) {
					$col = '' !== $f['label'] ? $f['label'] : $f['key'];
					if ( ! in_array( $col, $columns, true ) ) {
						$columns[] = $col;
					}
					$line[ $col ] = FTGS_Row::flatten( $f['value'] );
				}
				$rows[] = $line;
			}
			++$args['paged'];
		} while ( count( $res['items'] ) === $args['per_page'] && $args['paged'] <= 40 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=form-submissions-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore -- UTF-8 BOM for Excel.
		fputcsv( $out, $columns );
		foreach ( $rows as $line ) {
			$cells = array();
			foreach ( $columns as $col ) {
				$cells[] = isset( $line[ $col ] ) ? $line[ $col ] : '';
			}
			fputcsv( $out, $cells );
		}
		fclose( $out ); // phpcs:ignore
		exit;
	}

	public static function ajax_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed' ), 403 );
		}
		check_ajax_referer( 'ftgs_admin', 'nonce' );
		$c = FTGS_Connections::get( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' );
		if ( ! $c || empty( $c['webhook_url'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Save a webhook URL first.', 'forms-to-google-sheets' ) ) );
		}
		$sub = array(
			'uid'       => 'test-' . time(),
			'source'    => 'any' === $c['source'] ? 'html' : $c['source'],
			'form_id'   => 'test',
			'form_name' => __( 'Test submission', 'forms-to-google-sheets' ),
			'fields'    => array(
				array( 'key' => 'name', 'label' => 'Name', 'value' => 'Test User' ),
				array( 'key' => 'email', 'label' => 'Email', 'value' => 'test@example.com' ),
				array( 'key' => 'message', 'label' => 'Message', 'value' => __( 'This is a test row from Forms to Google Sheets.', 'forms-to-google-sheets' ) ),
			),
			'meta'      => array(
				'submitted_at' => current_time( 'mysql' ),
				'page_url'     => home_url( '/' ),
			),
		);
		$res = FTGS_Client::send( $c, array( FTGS_Row::build( $c, $sub ) ) );
		FTGS_Logger::add( $c, $res['ok'] ? 'success' : 'error', __( 'Test:', 'forms-to-google-sheets' ) . ' ' . $res['message'], $sub['form_name'] );
		if ( $res['ok'] ) {
			wp_send_json_success( array( 'message' => $res['message'] ) );
		}
		wp_send_json_error( array( 'message' => $res['message'] ) );
	}
}
