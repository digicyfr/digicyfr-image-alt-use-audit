<?php
/**
 * Private InnoDB schema and retention for bounded image-use reports.
 *
 * @package Digicyfr_Image_Alt_Use_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin-owned scan storage. */
final class DIAU_Storage {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Engine checks and retention operate on plugin-owned transactional tables.
	/** Table names for the current WordPress site. */
	public static function tables(): array {
		global $wpdb;
		return array(
			'runs'     => $wpdb->prefix . 'diau_runs',
			'uses'     => $wpdb->prefix . 'diau_uses',
			'coverage' => $wpdb->prefix . 'diau_coverage',
		);
	}

	/** Install/upgrade three plugin-owned transactional tables. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$tables    = self::tables();
		$collation = $wpdb->get_charset_collate();
		$sql       = array(
			"CREATE TABLE {$tables['runs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id char(36) NOT NULL,
				status varchar(32) NOT NULL,
				high_water bigint(20) unsigned NOT NULL DEFAULT 0,
				cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				started_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				last_batch_at datetime NULL,
				completed_at datetime NULL,
				processed_count bigint(20) unsigned NOT NULL DEFAULT 0,
				skipped_count bigint(20) unsigned NOT NULL DEFAULT 0,
				unsupported_count bigint(20) unsigned NOT NULL DEFAULT 0,
				image_count bigint(20) unsigned NOT NULL DEFAULT 0,
				review_count bigint(20) unsigned NOT NULL DEFAULT 0,
				last_error text NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scan_id (scan_id),
				KEY status (status)
			) ENGINE=InnoDB $collation",
			"CREATE TABLE {$tables['uses']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				post_modified_gmt datetime NOT NULL,
				block_name varchar(100) NOT NULL,
				block_path varchar(255) NOT NULL,
				block_hash char(64) NOT NULL,
				image_ordinal smallint(5) unsigned NOT NULL,
				alt_state varchar(20) NOT NULL,
				alt_preview varchar(200) NOT NULL,
				src_hint varchar(255) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY source (scan_id,post_id,block_hash,image_ordinal),
				KEY report_state (scan_id,alt_state,id),
				KEY report_post (scan_id,post_id,id)
			) ENGINE=InnoDB $collation",
			"CREATE TABLE {$tables['coverage']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				reason varchar(40) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source (scan_id,post_id,reason),
				KEY scan_id (scan_id)
			) ENGINE=InnoDB $collation",
		);
		dbDelta( $sql );
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table names from fixed suffixes and trusted WordPress prefix.
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				wp_die( esc_html__( 'Image Alt Use Audit requires InnoDB tables for safe resumable scans.', 'digicyfr-image-alt-use-audit' ) );
			}
		}
		update_option( 'diau_schema_version', DIGICYFR_IMAGE_ALT_USE_AUDIT_VERSION, false );
	}

	/** Verify transactional table engines at runtime before a scan. */
	public static function transactional(): bool {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
			if ( 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Remove older temporary/reports while retaining the latest complete and current run.
	 *
	 * @param string $current_id Run UUID to retain.
	 */
	public static function prune( string $current_id ): void {
		global $wpdb;
		$tables = self::tables();
		$keep   = array_filter( array( $current_id, (string) get_option( 'diau_latest_complete_id', '' ) ) );
		if ( array() === $keep ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $keep ), '%s' ) );
		foreach ( array( 'uses', 'coverage', 'runs' ) as $key ) {
			$table = $tables[ $key ];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Fixed plugin-owned table and generated placeholders.
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE scan_id NOT IN ($placeholders)", ...$keep ) );
		}
	}
}
