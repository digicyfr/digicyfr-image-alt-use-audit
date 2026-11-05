<?php
/**
 * Manual, transactional, resumable scan coordinator.
 *
 * @package Digicyfr_Image_Alt_Use_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded scan actions; all public methods still check capability. */
final class DIAU_Run {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The resumable scan requires keyset queries and InnoDB row locks; caching would break transaction correctness.
	public const MAX_POSTS_PER_BATCH = 10;
	public const MAX_BYTES_PER_BATCH = 2097152;
	public const MAX_STORED_USES     = 50000;

	/** Return the two reports exposed by the UI. */
	public static function pointers(): array {
		$storage_error = ! self::reconcile_pointers();
		return array(
			'active'          => (string) get_option( 'diau_active_scan_id', '' ),
			'latest_complete' => (string) get_option( 'diau_latest_complete_id', '' ),
			'latest_run'      => (string) get_option( 'diau_latest_run_id', '' ),
			'storage_error'   => $storage_error,
		);
	}

	/** Recover pointer updates lost after a committed terminal scan. */
	public static function reconcile_pointers(): bool {
		global $wpdb;
		$table      = DIAU_Storage::tables()['runs'];
		$latest_run = (string) $wpdb->get_var( "SELECT scan_id FROM $table ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table.
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		$latest_complete = (string) $wpdb->get_var( "SELECT scan_id FROM $table WHERE status = 'complete' ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table and status.
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		$active = (string) $wpdb->get_var( "SELECT scan_id FROM $table WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table and statuses.
		if ( '' !== $wpdb->last_error ) {
			return false;
		}
		update_option( 'diau_latest_run_id', $latest_run, false );
		update_option( 'diau_latest_complete_id', $latest_complete, false );
		update_option( 'diau_active_scan_id', $active, false );
		return true;
	}

	/**
	 * Get a retained scan row by its UUID.
	 *
	 * @param string $scan_id Run UUID.
	 */
	public static function get( string $scan_id ): ?array {
		global $wpdb;
		if ( ! wp_is_uuid( $scan_id ) ) {
			return null;
		}
		$table = DIAU_Storage::tables()['runs'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE scan_id = %s", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table name.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Start a high-water keyset run without scanning on public requests.
	 *
	 * @return array|WP_Error New run row or error.
	 */
	public static function start() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot start this scan.', 'digicyfr-image-alt-use-audit' ) );
		}
		if ( ! DIAU_Storage::transactional() ) {
			return new WP_Error( 'storage_unavailable', __( 'Scan tables must use InnoDB.', 'digicyfr-image-alt-use-audit' ) );
		}
		if ( ! add_option( 'diau_start_lock', (string) time(), '', false ) ) {
			$old = (int) get_option( 'diau_start_lock', 0 );
			if ( $old > 0 && $old < time() - 120 ) {
				delete_option( 'diau_start_lock' );
			}
			if ( ! add_option( 'diau_start_lock', (string) time(), '', false ) ) {
				return new WP_Error( 'busy', __( 'Another scan action is in progress. Try again.', 'digicyfr-image-alt-use-audit' ) );
			}
		}
		try {
			if ( ! self::reconcile_pointers() ) {
				return new WP_Error( 'storage_failed', __( 'Could not read scan records. Existing reports were retained.', 'digicyfr-image-alt-use-audit' ) );
			}
			$active_id = (string) get_option( 'diau_active_scan_id', '' );
			$active    = '' !== $active_id ? self::get( $active_id ) : null;
			if ( $active && in_array( $active['status'], array( 'queued', 'running' ), true ) ) {
				return new WP_Error( 'already_running', __( 'Finish or cancel the current scan first.', 'digicyfr-image-alt-use-audit' ) );
			}
			$high_water = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status = 'publish'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core posts table; fixed conditions.
			if ( '' !== $wpdb->last_error ) {
				return new WP_Error( 'storage_failed', __( 'Could not read eligible posts. No scan was started.', 'digicyfr-image-alt-use-audit' ) );
			}
			$scan_id  = wp_generate_uuid4();
			$now      = current_time( 'mysql', true );
			$table    = DIAU_Storage::tables()['runs'];
			$inserted = $wpdb->insert(
				$table,
				array(
					'scan_id'    => $scan_id,
					'status'     => 'queued',
					'high_water' => $high_water,
					'cursor_id'  => 0,
					'started_at' => $now,
					'updated_at' => $now,
					'last_error' => '',
				)
			);
			if ( false === $inserted ) {
				return new WP_Error( 'storage_failed', __( 'Could not create the scan record.', 'digicyfr-image-alt-use-audit' ) );
			}
			update_option( 'diau_active_scan_id', $scan_id, false );
			update_option( 'diau_latest_run_id', $scan_id, false );
			DIAU_Storage::prune( $scan_id );
			return self::get( $scan_id );
		} finally {
			delete_option( 'diau_start_lock' );
		}
	}

	/**
	 * Process one bounded batch while holding the run row's InnoDB lock.
	 *
	 * @param string $scan_id Run UUID.
	 * @return array|WP_Error Updated run or error.
	 * @throws RuntimeException When a transactional write fails; caught within this method.
	 */
	public static function continue_scan( string $scan_id ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot continue this scan.', 'digicyfr-image-alt-use-audit' ) );
		}
		if ( ! DIAU_Storage::transactional() ) {
			return new WP_Error( 'storage_unavailable', __( 'Scan tables must use InnoDB.', 'digicyfr-image-alt-use-audit' ) );
		}
		$tables = DIAU_Storage::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required to serialize Continue/Cancel and commit rows with cursor.
			return new WP_Error( 'storage_failed', __( 'Could not begin a safe scan transaction.', 'digicyfr-image-alt-use-audit' ) );
		}
		try {
			$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['runs']} WHERE scan_id = %s FOR UPDATE", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table.
			if ( ! is_array( $run ) || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No transaction changes.
				return new WP_Error( 'not_running', __( 'This scan is no longer running.', 'digicyfr-image-alt-use-audit' ) );
			}
			$cursor     = (int) $run['cursor_id'];
			$high_water = (int) $run['high_water'];
			$batch      = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, OCTET_LENGTH(post_content) AS content_bytes FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('post','page') AND post_status = 'publish' ORDER BY ID ASC LIMIT %d",
					$cursor,
					$high_water,
					self::MAX_POSTS_PER_BATCH
				),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core posts table; all values prepared.
			if ( '' !== $wpdb->last_error || ! is_array( $batch ) ) {
				throw new RuntimeException( 'Failed to read next posts.' );
			}
			$bytes_used  = 0;
			$handled     = 0;
			$hit_row_cap = false;
			foreach ( $batch as $candidate ) {
				$post_id = (int) $candidate['ID'];
				$size    = (int) $candidate['content_bytes'];
				if ( $size <= DIAU_Scanner::MAX_POST_BYTES && $handled > 0 && $bytes_used + $size > self::MAX_BYTES_PER_BATCH ) {
					break;
				}
				++$handled;
				$cursor   = $post_id;
				$parsed   = null;
				$modified = '';
				if ( $size > DIAU_Scanner::MAX_POST_BYTES ) {
					$parsed = array(
						'uses'     => array(),
						'coverage' => array(),
						'skip'     => 'post_too_large',
					);
				} else {
					$source = $wpdb->get_row( $wpdb->prepare( "SELECT post_content, post_modified_gmt, post_status, post_type FROM {$wpdb->posts} WHERE ID = %d", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core posts table; ID prepared.
					if ( ! is_array( $source ) || 'publish' !== $source['post_status'] || ! in_array( $source['post_type'], array( 'post', 'page' ), true ) ) {
						$parsed = array(
							'uses'     => array(),
							'coverage' => array(),
							'skip'     => 'source_changed',
						);
					} elseif ( strlen( $source['post_content'] ) > DIAU_Scanner::MAX_POST_BYTES ) {
						$parsed = array(
							'uses'     => array(),
							'coverage' => array(),
							'skip'     => 'post_too_large',
						);
					} else {
						$bytes_used += strlen( $source['post_content'] );
						$modified    = $source['post_modified_gmt'];
						$parsed      = DIAU_Scanner::scan_post( $post_id, $source['post_content'] );
					}
				}
				if ( '' === $parsed['skip'] && (int) $run['image_count'] + count( $parsed['uses'] ) > self::MAX_STORED_USES ) {
					$parsed      = array(
						'uses'     => array(),
						'coverage' => array(),
						'skip'     => 'row_cap',
					);
					$hit_row_cap = true;
				}
				self::replace_post( $tables, $scan_id, $post_id, $modified, $parsed );
				if ( '' !== $parsed['skip'] ) {
					++$run['skipped_count'];
				} else {
					++$run['processed_count'];
					$run['image_count']       += count( $parsed['uses'] );
					$run['unsupported_count'] += count( $parsed['coverage'] );
					foreach ( $parsed['uses'] as $use ) {
						if ( 'nonempty' !== $use['alt_state'] ) {
							++$run['review_count'];
						}
					}
				}
				if ( $hit_row_cap ) {
					$run['last_error'] = 'row_cap';
					break;
				}
			}
			$run['cursor_id']     = $cursor;
			$now                  = current_time( 'mysql', true );
			$run['updated_at']    = $now;
			$run['last_batch_at'] = $now;
			$remaining            = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('post','page') AND post_status = 'publish' ORDER BY ID ASC LIMIT 1", $cursor, $high_water ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core posts table; values prepared.
			if ( '' !== $wpdb->last_error ) {
				throw new RuntimeException( 'Could not verify remaining posts.' );
			}
			if ( $hit_row_cap || null === $remaining ) {
				$run['status']       = ( (int) $run['skipped_count'] > 0 || (int) $run['unsupported_count'] > 0 ) ? 'complete_with_skips' : 'complete';
				$run['completed_at'] = $now;
			} else {
				$run['status'] = 'running';
			}
			$updated = $wpdb->update(
				$tables['runs'],
				array(
					'status'            => $run['status'],
					'cursor_id'         => $run['cursor_id'],
					'updated_at'        => $run['updated_at'],
					'last_batch_at'     => $run['last_batch_at'],
					'completed_at'      => $run['completed_at'],
					'processed_count'   => $run['processed_count'],
					'skipped_count'     => $run['skipped_count'],
					'unsupported_count' => $run['unsupported_count'],
					'image_count'       => $run['image_count'],
					'review_count'      => $run['review_count'],
					'last_error'        => $run['last_error'],
				),
				array( 'scan_id' => $scan_id )
			);
			if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional scan batch.
				throw new RuntimeException( 'Could not commit scan batch.' );
			}
			if ( in_array( $run['status'], array( 'complete', 'complete_with_skips' ), true ) ) {
				if ( 'complete' === $run['status'] ) {
					update_option( 'diau_latest_complete_id', $scan_id, false );
				}
				self::clear_active_if( $scan_id );
				self::reconcile_pointers();
				DIAU_Storage::prune( $scan_id );
			}
			return self::get( $scan_id );
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Revert partial batch on failure.
			$wpdb->update(
				$tables['runs'],
				array(
					'status'       => 'failed',
					'updated_at'   => current_time( 'mysql', true ),
					'completed_at' => current_time( 'mysql', true ),
					'last_error'   => self::bounded_error( $error->getMessage() ),
				),
				array( 'scan_id' => $scan_id )
			);
			self::clear_active_if( $scan_id );
			return new WP_Error( 'scan_failed', __( 'The scan failed. The previous complete report is still available.', 'digicyfr-image-alt-use-audit' ) );
		}
	}

	/**
	 * Replace one post's rows within the surrounding transaction.
	 *
	 * @param array  $tables Plugin table names.
	 * @param string $scan_id Scan UUID.
	 * @param int    $post_id Source ID.
	 * @param string $modified GMT modified timestamp.
	 * @param array  $parsed Scanner result.
	 * @throws RuntimeException When a transactional write fails.
	 */
	private static function replace_post( array $tables, string $scan_id, int $post_id, string $modified, array $parsed ): void {
		global $wpdb;
		foreach ( array( 'uses', 'coverage' ) as $key ) {
			if ( false === $wpdb->delete(
				$tables[ $key ],
				array(
					'scan_id' => $scan_id,
					'post_id' => $post_id,
				)
			) ) {
				throw new RuntimeException( 'Could not replace source records.' );
			}
		}
		foreach ( $parsed['uses'] as $use ) {
			$record = array_merge(
				array(
					'scan_id'           => $scan_id,
					'post_id'           => $post_id,
					'post_modified_gmt' => $modified,
				),
				$use
			);
			if ( false === $wpdb->insert( $tables['uses'], $record ) ) {
				throw new RuntimeException( 'Could not store image use.' );
			}
		}
		$reasons = '' !== $parsed['skip'] ? array( $parsed['skip'] ) : $parsed['coverage'];
		foreach ( $reasons as $reason ) {
			if ( false === $wpdb->insert(
				$tables['coverage'],
				array(
					'scan_id' => $scan_id,
					'post_id' => $post_id,
					'reason'  => $reason,
				)
			) ) {
				throw new RuntimeException( 'Could not store coverage reason.' );
			}
		}
	}

	/**
	 * Keep errors private and bounded.
	 *
	 * @param string $message Error text.
	 */
	private static function bounded_error( string $message ): string {
		$message = sanitize_text_field( $message );
		return preg_match( '/^.{0,300}/us', $message, $matches ) ? $matches[0] : '';
	}

	/**
	 * Clear active pointer only if it still names this scan.
	 *
	 * @param string $scan_id Run UUID.
	 */
	private static function clear_active_if( string $scan_id ): void {
		if ( get_option( 'diau_active_scan_id', '' ) === $scan_id ) {
			update_option( 'diau_active_scan_id', '', false );
		}
	}

	/**
	 * Cancel a queued/running scan after acquiring the same row lock as Continue.
	 *
	 * @param string $scan_id Run UUID.
	 * @return array|WP_Error Updated run or error.
	 */
	public static function cancel( string $scan_id ) {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'not_allowed', __( 'You cannot cancel this scan.', 'digicyfr-image-alt-use-audit' ) );
		}
		$tables = DIAU_Storage::tables();
		if ( ! DIAU_Storage::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Serialized with Continue.
			return new WP_Error( 'storage_failed', __( 'Could not begin a safe scan transaction.', 'digicyfr-image-alt-use-audit' ) );
		}
		$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['runs']} WHERE scan_id = %s FOR UPDATE", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin table.
		if ( ! is_array( $run ) || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No changes.
			return new WP_Error( 'not_running', __( 'This scan is no longer running.', 'digicyfr-image-alt-use-audit' ) );
		}
		$now     = current_time( 'mysql', true );
		$updated = $wpdb->update(
			$tables['runs'],
			array(
				'status'       => 'cancelled',
				'updated_at'   => $now,
				'completed_at' => $now,
			),
			array( 'scan_id' => $scan_id )
		);
		if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactional status transition.
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Revert failed transition.
			return new WP_Error( 'storage_failed', __( 'Could not cancel the scan.', 'digicyfr-image-alt-use-audit' ) );
		}
		self::clear_active_if( $scan_id );
		return self::get( $scan_id );
	}
}
