<?php
/**
 * Plugin Name:       Universal Form Attacher | Forms to Google Sheets
 * Plugin URI:        https://www.digitalmalayali.in/universal-form-attacher/
 * Description:       An Open Source Google Form Synching Plugin Developed For Marketing, Accounts By Digital Malayali Partner - Bestin Jacob | Send form submissions from Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Ninja Forms, Formidable, Forminator, Everest Forms, JetFormBuilder, MetForm, Divi, Bricks, Caldera Forms and plain HTML forms to Google Sheets through a Google Apps Script webhook. Includes a local backup of every submission, background sending, retries, resend and sync of existing entries.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Malayali
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forms-to-google-sheets
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FTGS_VERSION', '2.0.0' );
define( 'FTGS_DB_VERSION', '2' );
define( 'FTGS_FILE', __FILE__ );
define( 'FTGS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FTGS_URL', plugin_dir_url( __FILE__ ) );

require_once FTGS_DIR . 'includes/class-ftgs-install.php';
require_once FTGS_DIR . 'includes/class-ftgs-settings.php';
require_once FTGS_DIR . 'includes/class-ftgs-logger.php';
require_once FTGS_DIR . 'includes/class-ftgs-connections.php';
require_once FTGS_DIR . 'includes/class-ftgs-submissions.php';
require_once FTGS_DIR . 'includes/class-ftgs-row.php';
require_once FTGS_DIR . 'includes/class-ftgs-client.php';
require_once FTGS_DIR . 'includes/class-ftgs-queue.php';
require_once FTGS_DIR . 'includes/class-ftgs-alerts.php';
require_once FTGS_DIR . 'includes/class-ftgs-sync.php';
require_once FTGS_DIR . 'includes/class-ftgs-rest.php';
require_once FTGS_DIR . 'includes/class-ftgs-privacy.php';
require_once FTGS_DIR . 'includes/integrations/abstract-ftgs-integration.php';

foreach ( glob( FTGS_DIR . 'includes/integrations/class-ftgs-*.php' ) as $ftgs_file ) {
	require_once $ftgs_file;
}

if ( is_admin() ) {
	require_once FTGS_DIR . 'includes/admin/class-ftgs-admin.php';
}

/**
 * Registered integrations, keyed by source slug.
 *
 * @return FTGS_Integration[]
 */
function ftgs_integrations() {
	static $integrations = null;
	if ( null === $integrations ) {
		$classes = array(
			'FTGS_Integration_CF7',
			'FTGS_Integration_WPForms',
			'FTGS_Integration_Gravity',
			'FTGS_Integration_Elementor',
			'FTGS_Integration_Fluent',
			'FTGS_Integration_Ninja',
			'FTGS_Integration_Formidable',
			'FTGS_Integration_Forminator',
			'FTGS_Integration_Everest',
			'FTGS_Integration_JetFormBuilder',
			'FTGS_Integration_MetForm',
			'FTGS_Integration_Divi',
			'FTGS_Integration_Bricks',
			'FTGS_Integration_Caldera',
			'FTGS_Integration_HTML',
		);
		$integrations = array();
		foreach ( apply_filters( 'ftgs_integration_classes', $classes ) as $class ) {
			if ( class_exists( $class ) ) {
				$obj = new $class();
				if ( $obj instanceof FTGS_Integration ) {
					$integrations[ $obj->get_slug() ] = $obj;
				}
			}
		}
	}
	return $integrations;
}

/**
 * Single integration by slug, or null.
 *
 * @param string $slug Source slug.
 * @return FTGS_Integration|null
 */
function ftgs_integration( $slug ) {
	$all = ftgs_integrations();
	return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
}

register_activation_hook( __FILE__, array( 'FTGS_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FTGS_Install', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'forms-to-google-sheets', false, dirname( plugin_basename( FTGS_FILE ) ) . '/languages' );

		FTGS_Install::maybe_upgrade();
		FTGS_Queue::init();
		FTGS_Alerts::init();
		FTGS_Sync::init();
		FTGS_REST::init();
		FTGS_Privacy::init();

		foreach ( ftgs_integrations() as $integration ) {
			$integration->register();
		}
		if ( is_admin() ) {
			FTGS_Admin::init();
		}
	},
	20
);
