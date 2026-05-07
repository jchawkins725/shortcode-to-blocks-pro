<?php
/**
 * Uninstall cleanup for Shortcode to Blocks Pro.
 * Executes when the plugin is deleted via the WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Configuration (matches your codebase)
 */
define( 'STBP_OPTION_RETAIN_DATA', 'stbc_retain_data_on_uninstall' ); // shared retain-data option from the free plugin
define( 'STBP_OPTION_RETAIN_DATA_LEGACY', 'stb_retain_data_on_uninstall' );
define( 'STBP_OPTION_PREFIX',      'stbp_' );                           // your options/transients prefix
define( 'STBP_LOGS_TABLE',         'stbp_logs' );                       // {$wpdb->prefix}stbp_logs
define( 'STBP_CRON_HOOK',          'stbp_cron_purge_backups' );         // scheduled single-event hook

// Post meta keys set by the converter.
$stbp_meta_keys = array(
	'_stbp_original_content',
	'_stbp_original_content_ts',
	'_stbp_converted',
	'_stbp_converted_ts',
	'_stbp_has_vc',
	'_stbp_batch_id',
);

/**
 * Should we retain data? (default: keep)
 *
 * The Pro add-on follows the shared free-plugin setting `stbc_retain_data_on_uninstall`.
 *
 * @return bool True to keep data; false to delete data.
 */
function stbp_should_retain_data() {
	return (bool) get_option( STBP_OPTION_RETAIN_DATA, get_option( STBP_OPTION_RETAIN_DATA_LEGACY, true ) );
}

/**
 * Clear scheduled cron events.
 */
function stbp_clear_cron() {
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( STBP_CRON_HOOK );
	}
}

/**
 * Remove plugin options and transients by prefix.
 */
function stbp_delete_options_and_transients() {
	global $wpdb;

	$like  = $wpdb->esc_like( STBP_OPTION_PREFIX ) . '%';
	$table = $wpdb->options;

	// Delete options with our prefix.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time uninstall cleanup query.
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$table} WHERE option_name LIKE %s", $like )
	);

	// Delete transients containing our prefix.
	// Stored as _transient_{key} and _transient_timeout_{key}.
	$t_like = '%' . $wpdb->esc_like( STBP_OPTION_PREFIX ) . '%';

	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$table} WHERE option_name LIKE %s", '_transient_' . $t_like )
	);
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$table} WHERE option_name LIKE %s", '_transient_timeout_' . $t_like )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Drop plugin tables.
 */
function stbp_drop_tables() {
	global $wpdb;
	$table = $wpdb->prefix . STBP_LOGS_TABLE;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time uninstall cleanup query.
}

/**
 * Delete global post meta keys created by the plugin.
 */
function stbp_delete_post_meta_keys( array $keys ) {
	foreach ( $keys as $key ) {
		delete_post_meta_by_key( $key );
	}
}

/**
 * Purge everything for the current blog.
 */
function stbp_purge_blog( array $meta_keys ) {
	stbp_clear_cron();
	stbp_delete_options_and_transients();
	stbp_drop_tables();
	stbp_delete_post_meta_keys( $meta_keys );
}

/**
 * Entry point.
 */
if ( is_multisite() ) {
	stbp_clear_cron();

	if ( stbp_should_retain_data() ) {
		return;
	}

	$stbp_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $stbp_site_ids as $stbp_site_id ) {
		switch_to_blog( (int) $stbp_site_id );
		stbp_purge_blog( $stbp_meta_keys );
		restore_current_blog();
	}
} else {
	stbp_clear_cron();

	if ( stbp_should_retain_data() ) {
		return;
	}

	stbp_purge_blog( $stbp_meta_keys );
}