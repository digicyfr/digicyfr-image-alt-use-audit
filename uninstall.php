<?php
/**
 * Remove this plugin's private scan data on uninstall only.
 *
 * @package Digicyfr_Image_Alt_Use_Audit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
foreach ( array( 'diau_uses', 'diau_coverage', 'diau_runs' ) as $diau_suffix ) {
	$diau_table = $wpdb->prefix . $diau_suffix;
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $diau_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit uninstall of fixed plugin-owned tables.
}
foreach ( array( 'diau_schema_version', 'diau_active_scan_id', 'diau_latest_complete_id', 'diau_latest_run_id', 'diau_start_lock' ) as $diau_option ) {
	delete_option( $diau_option );
}
