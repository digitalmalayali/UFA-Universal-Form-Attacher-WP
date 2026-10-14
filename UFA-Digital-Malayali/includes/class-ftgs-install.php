<?php
/**
 * Database table, cron events and upgrades.
 */

defined( 'ABSPATH' ) || exit;

class FTGS_Install {

	const QUEUE_HOOK = 'ftgs_process_queue';
	const DAILY_HOOK = 'ftgs_daily_maintenance';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ftgs_submissions';
	}

	public static function activate() {
		wp_unschedule_hook( 'ftgs_retry_delivery' ); // Version 1 retry events are replaced by the queue.
		self::create_table();
		self::schedule();
		update_option( 'ftgs_db_version', FTGS_DB_VERSION, false );
		if ( ! get_option( 'ftgs_installed_at' ) ) {
			update_option( 'ftgs_installed_at', current_time( 'mysql' ), false );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::QUEUE_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_unschedule_hook( 'ftgs_retry_delivery' ); // v1 leftovers.
	}

	/**
	 * Runs on every load: creates the table on multisite sub-sites / after updates
	 * and keeps cron events scheduled.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ftgs_db_version' ) !== FTGS_DB_VERSION ) {
			self::activate();
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		self::schedule();
	}

	public static function cron_schedules( $schedules ) {
		$schedules['ftgs_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Forms to Google Sheets)', 'forms-to-google-sheets' ),
		);
		return $schedules;
	}

	public static function schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ftgs_five_minutes', self::QUEUE_HOOK );
		}
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uid varchar(191) NOT NULL DEFAULT '',
			source varchar(40) NOT NULL DEFAULT '',
			form_id varchar(191) NOT NULL DEFAULT '',
			form_name varchar(255) NOT NULL DEFAULT '',
			fields longtext NOT NULL,
			meta longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			deliveries longtext NOT NULL,
			next_try datetime NULL DEFAULT NULL,
			locked_until datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY uid (uid),
			KEY source_form (source, form_id),
			KEY status_next (status, next_try),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
