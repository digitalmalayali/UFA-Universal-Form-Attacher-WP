<?php
/**
 * Removes data only when "Delete all data on uninstall" is ticked in Settings.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function ftgs_uninstall_site() {
	global $wpdb;
	wp_clear_scheduled_hook( 'ftgs_process_queue' );
	wp_clear_scheduled_hook( 'ftgs_daily_maintenance' );
	wp_unschedule_hook( 'ftgs_retry_delivery' );

	$settings = get_option( 'ftgs_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		return;
	}
	foreach ( array( 'ftgs_connections', 'ftgs_settings', 'ftgs_log', 'ftgs_db_version', 'ftgs_installed_at', 'ftgs_alert_suppressed' ) as $opt ) {
		delete_option( $opt );
	}
	delete_transient( 'ftgs_alert_throttle' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ftgs_submissions" ); // phpcs:ignore
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $ftgs_site_id ) {
		switch_to_blog( $ftgs_site_id );
		ftgs_uninstall_site();
		restore_current_blog();
	}
} else {
	ftgs_uninstall_site();
}
