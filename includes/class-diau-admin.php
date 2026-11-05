<?php
/**
 * Private manual scan controls, report and streaming CSV.
 *
 * @package Digicyfr_Image_Alt_Use_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Administrator UI and nonce-protected handlers. */
final class DIAU_Admin {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Private reports query plugin-owned scan tables; caching could show stale results.
	private const PAGE             = 'digicyfr-image-alt-use-audit';
	private const REPORT_PAGE_SIZE = 50;
	private const CSV_BATCH_SIZE   = 500;

	/** Register only administrator hooks; no public or content filters. */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'load_translations' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'start', 'continue', 'cancel', 'export' ) as $action ) {
			add_action( 'admin_post_diau_' . $action, array( __CLASS__, 'handle' ) );
		}
	}

	/** Load bundled Polish strings on self-hosted installations. */
	public static function load_translations(): void {
		load_plugin_textdomain( 'digicyfr-image-alt-use-audit', false, dirname( plugin_basename( DIGICYFR_IMAGE_ALT_USE_AUDIT_PATH . 'digicyfr-image-alt-use-audit.php' ) ) . '/languages' );
	}

	/** Add private Tools page. */
	public static function menu(): void {
		add_management_page( __( 'Image Alt Use Audit', 'digicyfr-image-alt-use-audit' ), __( 'Image Alt Use Audit', 'digicyfr-image-alt-use-audit' ), 'manage_options', self::PAGE, array( __CLASS__, 'screen' ) );
	}

	/** Load small local admin assets on the plugin page only. */
	public static function assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'tools_page_' . self::PAGE !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'diau-admin', DIGICYFR_IMAGE_ALT_USE_AUDIT_URL . 'assets/admin.css', array(), DIGICYFR_IMAGE_ALT_USE_AUDIT_VERSION );
		wp_enqueue_script( 'diau-admin', DIGICYFR_IMAGE_ALT_USE_AUDIT_URL . 'assets/admin.js', array(), DIGICYFR_IMAGE_ALT_USE_AUDIT_VERSION, true );
	}

	/** Localized scan status.
	 *
	 * @param string $status Stored run status.
	 */
	private static function run_label( string $status ): string {
		return match ( $status ) {
			'queued' => __( 'Queued', 'digicyfr-image-alt-use-audit' ),
			'running' => __( 'Running', 'digicyfr-image-alt-use-audit' ),
			'complete' => __( 'Complete', 'digicyfr-image-alt-use-audit' ),
			'complete_with_skips' => __( 'Incomplete — skipped or unsupported content', 'digicyfr-image-alt-use-audit' ),
			'failed' => __( 'Failed', 'digicyfr-image-alt-use-audit' ),
			'cancelled' => __( 'Cancelled', 'digicyfr-image-alt-use-audit' ),
			default => __( 'Unknown status', 'digicyfr-image-alt-use-audit' ),
		};
	}

	/** Localized image alt state without inferring decorative purpose.
	 *
	 * @param string $state Stored alt state.
	 */
	private static function alt_label( string $state ): string {
		return match ( $state ) {
			'missing' => __( 'Missing alt', 'digicyfr-image-alt-use-audit' ),
			'empty' => __( 'Empty alt — review purpose', 'digicyfr-image-alt-use-audit' ),
			'whitespace' => __( 'Whitespace-only alt', 'digicyfr-image-alt-use-audit' ),
			'nonempty' => __( 'Alt present', 'digicyfr-image-alt-use-audit' ),
			default => __( 'Invalid alt — needs review', 'digicyfr-image-alt-use-audit' ),
		};
	}

	/** Translate current/stale/deleted source state for the report table.
	 *
	 * @param string $status Source state.
	 */
	private static function source_status_label( string $status ): string {
		return match ( $status ) {
			'current' => __( 'Current', 'digicyfr-image-alt-use-audit' ),
			'stale' => __( 'Stale — rescan needed', 'digicyfr-image-alt-use-audit' ),
			default => __( 'Deleted source', 'digicyfr-image-alt-use-audit' ),
		};
	}

	/** Describe a source fragment while preserving actual block context.
	 *
	 * @param string $block_name Saved block name.
	 */
	public static function source_label( string $block_name ): string {
		if ( in_array( $block_name, array( 'core/image', 'core/gallery', 'core/cover' ), true ) ) {
			return $block_name;
		}
		if ( 'saved HTML' === $block_name ) {
			return __( 'Saved HTML', 'digicyfr-image-alt-use-audit' );
		}
		/* translators: %s is the saved block name containing an img tag. */
		return sprintf( __( 'Saved HTML (%s)', 'digicyfr-image-alt-use-audit' ), $block_name );
	}

	/** Human-readable coverage or skip reason.
	 *
	 * @param string $reason Stored coverage reason.
	 */
	private static function coverage_label( string $reason ): string {
		return match ( $reason ) {
			'post_too_large' => __( 'Post content exceeds 512 KiB; skipped', 'digicyfr-image-alt-use-audit' ),
			'too_many_images' => __( 'More than 300 saved images; skipped', 'digicyfr-image-alt-use-audit' ),
			'blocks_too_deep' => __( 'Block nesting exceeds safety limit; skipped', 'digicyfr-image-alt-use-audit' ),
			'source_changed' => __( 'Source changed during scan; skipped', 'digicyfr-image-alt-use-audit' ),
			'row_cap' => __( '50,000 stored-use limit reached; scan incomplete', 'digicyfr-image-alt-use-audit' ),
			'cover_without_saved_img' => __( 'Cover background or dynamic cover has no saved img; outside scope', 'digicyfr-image-alt-use-audit' ),
			'synced_block_reference' => __( 'Synced block reference; referenced content outside scope', 'digicyfr-image-alt-use-audit' ),
			'featured_image_outside_scope' => __( 'Featured image may be theme-rendered; outside saved content scope', 'digicyfr-image-alt-use-audit' ),
			default => __( 'Unsupported or skipped source', 'digicyfr-image-alt-use-audit' ),
		};
	}

	/** Build a nonce-protected report URL with selected filters.
	 *
	 * @param string $scan_id Scan UUID.
	 * @param string $status Alt state filter.
	 * @param int    $post_id Source ID filter.
	 * @param int    $page Page number.
	 */
	private static function report_url( string $scan_id, string $status = 'all', int $post_id = 0, int $page = 1 ): string {
		$args = array(
			'page'      => self::PAGE,
			'view'      => 'report',
			'scan'      => $scan_id,
			'alt_state' => $status,
			'post_id'   => $post_id,
			'diau_page' => $page,
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'tools.php' ) ), 'diau_report', '_diau_nonce' );
	}

	/** Render the scan overview or nonce-protected private report. */
	public static function screen(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot view this audit.', 'digicyfr-image-alt-use-audit' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chooses a read-only page; report itself checks a nonce.
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'report' === $view ) {
			check_admin_referer( 'diau_report', '_diau_nonce' );
			self::report_screen();
			return;
		}
		self::overview_screen();
	}

	/** Show current run, preserved complete report, coverage explanation and controls. */
	private static function overview_screen(): void {
		$pointers = DIAU_Run::pointers();
		$run      = '' !== $pointers['latest_run'] ? DIAU_Run::get( $pointers['latest_run'] ) : null;
		$complete = '' !== $pointers['latest_complete'] ? DIAU_Run::get( $pointers['latest_complete'] ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only auto-continue UI state.
		$auto = isset( $_GET['diau_auto'] ) && '1' === $_GET['diau_auto'];
		?>
		<div class="wrap diau-wrap">
			<h1><?php echo esc_html__( 'Image Alt Use Audit', 'digicyfr-image-alt-use-audit' ); ?></h1>
			<?php
			if ( $pointers['storage_error'] ) :
				?>
				<p class="notice notice-error diau-inline" role="alert"><?php echo esc_html__( 'Scan records could not be read. Existing report pointers were retained; retry later.', 'digicyfr-image-alt-use-audit' ); ?></p><?php endif; ?>
			<?php
			if ( $auto ) :
				?>
				<p><a class="button" data-diau-pause="1" href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ); ?>"><?php echo esc_html__( 'Pause automatic batches', 'digicyfr-image-alt-use-audit' ); ?></a></p><?php endif; ?>
			<p><?php echo esc_html__( 'This local audit inspects saved img tags in published posts and pages. Empty alt may be appropriate for a decorative image; an editor must decide. No alt text is changed.', 'digicyfr-image-alt-use-audit' ); ?></p>
			<p><?php echo esc_html__( 'Scope excludes theme featured images, CSS backgrounds, synced block contents, dynamic output, templates, widgets, page-builder data outside post_content, and later public filters. A partial scan never means the site is clear.', 'digicyfr-image-alt-use-audit' ); ?></p>
			<?php self::notice(); ?>
			<?php if ( $run ) : ?>
				<section aria-labelledby="diau-last-run-heading">
					<h2 id="diau-last-run-heading"><?php echo esc_html__( 'Latest scan', 'digicyfr-image-alt-use-audit' ); ?></h2>
					<?php self::run_summary( $run ); ?>
					<?php if ( in_array( $run['status'], array( 'queued', 'running' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" 
						<?php
						if ( $auto ) :
							?>
							data-diau-autocontinue="1"<?php endif; ?>>
							<input type="hidden" name="action" value="diau_continue" /><input type="hidden" name="scan_id" value="<?php echo esc_attr( $run['scan_id'] ); ?>" />
							<?php wp_nonce_field( 'diau_continue_' . $run['scan_id'] ); ?>
							<?php submit_button( __( 'Continue one bounded batch', 'digicyfr-image-alt-use-audit' ), 'primary', 'submit', false ); ?>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="diau_cancel" /><input type="hidden" name="scan_id" value="<?php echo esc_attr( $run['scan_id'] ); ?>" />
							<?php wp_nonce_field( 'diau_cancel_' . $run['scan_id'] ); ?>
							<?php submit_button( __( 'Cancel scan', 'digicyfr-image-alt-use-audit' ), 'secondary', 'submit', false ); ?>
						</form>
						<p class="description"><?php echo esc_html__( 'While this page stays open, JavaScript continues one bounded batch per request. You may close it and resume later. Cancel stops the run.', 'digicyfr-image-alt-use-audit' ); ?></p>
					<?php endif; ?>
					<p><a href="<?php echo esc_url( self::report_url( $run['scan_id'] ) ); ?>"><?php echo esc_html__( 'View latest scan report', 'digicyfr-image-alt-use-audit' ); ?></a></p>
				</section>
			<?php endif; ?>
			<?php if ( $complete && ( ! $run || $complete['scan_id'] !== $run['scan_id'] ) ) : ?>
				<section aria-labelledby="diau-complete-heading"><h2 id="diau-complete-heading"><?php echo esc_html__( 'Previous complete report', 'digicyfr-image-alt-use-audit' ); ?></h2>
					<?php self::run_summary( $complete ); ?>
					<p><a href="<?php echo esc_url( self::report_url( $complete['scan_id'] ) ); ?>"><?php echo esc_html__( 'View previous complete report', 'digicyfr-image-alt-use-audit' ); ?></a></p>
				</section>
			<?php endif; ?>
			<?php if ( ! $run || ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="diau_start" /><?php wp_nonce_field( 'diau_start' ); ?>
					<?php submit_button( __( 'Start a new scan', 'digicyfr-image-alt-use-audit' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render counts with explicit complete/incomplete semantics.
	 *
	 * @param array $run Scan record.
	 */
	private static function run_summary( array $run ): void {
		$complete = 'complete' === $run['status'];
		?>
		<p><strong><?php echo esc_html( self::run_label( $run['status'] ) ); ?></strong> — <?php echo esc_html( $run['scan_id'] ); ?></p>
		<p><?php echo esc_html__( 'Started (UTC):', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['started_at'] ); ?> · <?php echo esc_html__( 'Last successful batch (UTC):', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['last_batch_at'] ? $run['last_batch_at'] : '—' ); ?></p>
		<p><?php echo esc_html__( 'Processed posts:', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['processed_count'] ); ?> · <?php echo esc_html__( 'Skipped posts:', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['skipped_count'] ); ?> · <?php echo esc_html__( 'Unsupported coverage notes:', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['unsupported_count'] ); ?> · <?php echo esc_html__( 'Saved image uses:', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['image_count'] ); ?></p>
		<p><?php echo esc_html__( 'ID cursor / high-water:', 'digicyfr-image-alt-use-audit' ); ?> <?php echo esc_html( $run['cursor_id'] . ' / ' . $run['high_water'] ); ?></p>
		<?php
		if ( ! $complete ) :
			?>
			<p class="notice notice-warning diau-inline" role="status"><?php echo esc_html__( 'Coverage is incomplete. These results cannot establish a clean site.', 'digicyfr-image-alt-use-audit' ); ?></p><?php endif; ?>
		<?php
		if ( 'row_cap' === $run['last_error'] ) :
			?>
			<p class="notice notice-warning diau-inline" role="status"><?php echo esc_html__( 'The 50,000-use limit stopped this scan. Posts after the ID cursor were not scanned.', 'digicyfr-image-alt-use-audit' ); ?></p><?php endif; ?>
		<?php
	}

	/** Show redirect feedback without trusting arbitrary query text. */
	private static function notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect status flag.
		$notice = isset( $_GET['diau_notice'] ) && is_string( $_GET['diau_notice'] ) ? sanitize_key( wp_unslash( $_GET['diau_notice'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only redirect error flag.
		$error = isset( $_GET['diau_error'] ) && is_string( $_GET['diau_error'] ) ? sanitize_key( wp_unslash( $_GET['diau_error'] ) ) : '';
		if ( '' !== $notice ) {
			echo '<div class="notice notice-success diau-result" tabindex="-1"><p>' . esc_html__( 'Scan action recorded.', 'digicyfr-image-alt-use-audit' ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error diau-result" role="alert" tabindex="-1"><p>' . esc_html__( 'Scan action failed. Check the scan status and try again.', 'digicyfr-image-alt-use-audit' ) . '</p></div>';
		}
	}

	/** Only known status filters are used in SQL.
	 *
	 * @param mixed $input Candidate state.
	 */
	private static function filter_state( $input ): string {
		return is_string( $input ) && in_array( $input, array( 'all', 'missing', 'empty', 'whitespace', 'nonempty', 'invalid' ), true ) ? $input : 'all';
	}

	/** Query one bounded page; never load all stored uses in the report.
	 *
	 * @param string $scan_id Scan UUID.
	 * @param string $state Alt state filter.
	 * @param int    $post_id Source ID filter.
	 * @param int    $page Page number.
	 */
	public static function report_rows( string $scan_id, string $state = 'all', int $post_id = 0, int $page = 1 ): array|WP_Error {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) || ! DIAU_Run::get( $scan_id ) ) {
			return array();
		}
		$state = self::filter_state( $state );
		$page  = max( 1, min( 1000, $page ) );
		$table = DIAU_Storage::tables()['uses'];
		$where = 'scan_id = %s';
		$args  = array( $scan_id );
		if ( 'all' !== $state ) {
			$where .= ' AND alt_state = %s';
			$args[] = $state;
		}
		if ( $post_id > 0 ) {
			$where .= ' AND post_id = %d';
			$args[] = $post_id;
		}
		$args[] = self::REPORT_PAGE_SIZE + 1;
		$args[] = ( $page - 1 ) * self::REPORT_PAGE_SIZE;
		$query  = "SELECT * FROM $table WHERE $where ORDER BY id ASC LIMIT %d OFFSET %d";
		$rows   = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table, whitelisted clauses, prepared values.
		return '' === $wpdb->last_error && is_array( $rows ) ? $rows : new WP_Error( 'storage_failed', __( 'Could not read saved image uses. Retry later.', 'digicyfr-image-alt-use-audit' ) );
	}

	/** Resolve whether a saved use is current, stale, or deleted.
	 *
	 * @param array $row Stored use.
	 */
	public static function source_status( array $row ): string {
		$post = get_post( (int) $row['post_id'] );
		if ( ! $post instanceof WP_Post ) {
			return 'deleted';
		}
		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || $post->post_modified_gmt !== $row['post_modified_gmt'] ) {
			return 'stale';
		}
		return 'current';
	}

	/** Render filtered report and coverage reasons. */
	private static function report_screen(): void {
		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in screen().
		$scan_id = isset( $_GET['scan'] ) && is_string( $_GET['scan'] ) ? sanitize_text_field( wp_unslash( $_GET['scan'] ) ) : '';
		$run     = DIAU_Run::get( $scan_id );
		if ( ! $run ) {
			wp_die( esc_html__( 'This scan report is unavailable.', 'digicyfr-image-alt-use-audit' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in screen().
		$state = self::filter_state( isset( $_GET['alt_state'] ) && is_string( $_GET['alt_state'] ) ? sanitize_key( wp_unslash( $_GET['alt_state'] ) ) : 'all' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in screen().
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce already verified in screen().
		$page = isset( $_GET['diau_page'] ) ? max( 1, min( 1000, absint( $_GET['diau_page'] ) ) ) : 1;
		$rows = self::report_rows( $scan_id, $state, $post_id, $page );
		if ( is_wp_error( $rows ) ) {
			wp_die( esc_html( $rows->get_error_message() ) );
		}
		$has_next = count( $rows ) > self::REPORT_PAGE_SIZE;
		$rows     = array_slice( $rows, 0, self::REPORT_PAGE_SIZE );
		$tables   = DIAU_Storage::tables();
		$counts   = $wpdb->get_results( $wpdb->prepare( "SELECT alt_state, COUNT(*) AS total FROM {$tables['uses']} WHERE scan_id = %s GROUP BY alt_state", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and prepared scan ID.
		if ( '' !== $wpdb->last_error ) {
			wp_die( esc_html__( 'Could not read report counts. Retry later.', 'digicyfr-image-alt-use-audit' ) );
		}
		$coverage = $wpdb->get_results( $wpdb->prepare( "SELECT reason, COUNT(*) AS total FROM {$tables['coverage']} WHERE scan_id = %s GROUP BY reason", $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and prepared scan ID.
		if ( '' !== $wpdb->last_error ) {
			wp_die( esc_html__( 'Could not read coverage notes. Retry later.', 'digicyfr-image-alt-use-audit' ) );
		}
		?>
		<div class="wrap diau-wrap">
			<h1><?php echo esc_html__( 'Saved image uses', 'digicyfr-image-alt-use-audit' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ); ?>"><?php echo esc_html__( 'Back to scan overview', 'digicyfr-image-alt-use-audit' ); ?></a></p>
			<?php self::run_summary( $run ); ?>
			<p><?php echo esc_html__( 'Edit the saved image use in its source post or page. Media Library alt metadata does not override the alt saved on each use. Empty alt may be suitable for decoration; only an editor can judge context.', 'digicyfr-image-alt-use-audit' ); ?></p>
			<div class="diau-counts" role="list">
				<?php
				foreach ( $counts as $count ) :
					?>
					<span role="listitem"><?php echo esc_html( self::alt_label( $count['alt_state'] ) . ': ' . $count['total'] ); ?></span><?php endforeach; ?>
			</div>
			<?php if ( $coverage ) : ?>
				<h2><?php echo esc_html__( 'Skipped and unsupported coverage', 'digicyfr-image-alt-use-audit' ); ?></h2>
				<ul>
				<?php
				foreach ( $coverage as $item ) :
					?>
					<li><?php echo esc_html( self::coverage_label( $item['reason'] ) . ': ' . $item['total'] ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" /><input type="hidden" name="view" value="report" /><input type="hidden" name="scan" value="<?php echo esc_attr( $scan_id ); ?>" /><input type="hidden" name="_diau_nonce" value="<?php echo esc_attr( wp_create_nonce( 'diau_report' ) ); ?>" />
				<label for="diau-state"><?php echo esc_html__( 'Alt state', 'digicyfr-image-alt-use-audit' ); ?></label>
				<select id="diau-state" name="alt_state">
					<?php
					foreach ( array(
						'all'        => __( 'All saved uses', 'digicyfr-image-alt-use-audit' ),
						'missing'    => self::alt_label( 'missing' ),
						'empty'      => self::alt_label( 'empty' ),
						'whitespace' => self::alt_label( 'whitespace' ),
						'nonempty'   => self::alt_label( 'nonempty' ),
						'invalid'    => self::alt_label( 'invalid' ),
					) as $key => $label ) :
						?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $state ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="diau-post-id"><?php echo esc_html__( 'Post ID (optional)', 'digicyfr-image-alt-use-audit' ); ?></label>
				<input id="diau-post-id" name="post_id" type="number" min="1" value="<?php echo $post_id > 0 ? esc_attr( (string) $post_id ) : ''; ?>" />
				<?php submit_button( __( 'Apply filters', 'digicyfr-image-alt-use-audit' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="diau_export" /><input type="hidden" name="scan_id" value="<?php echo esc_attr( $scan_id ); ?>" /><input type="hidden" name="alt_state" value="<?php echo esc_attr( $state ); ?>" /><input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>" />
				<?php wp_nonce_field( 'diau_export_' . $scan_id ); ?>
				<?php submit_button( __( 'Export selected CSV', 'digicyfr-image-alt-use-audit' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php
			if ( array() === $rows ) :
				?>
				<p><?php echo esc_html__( 'No saved image uses match this filter. Review the scan coverage before drawing conclusions.', 'digicyfr-image-alt-use-audit' ); ?></p><?php else : ?>
				<div class="diau-table-scroll"><table class="widefat striped diau-table"><thead><tr>
					<th scope="col"><?php echo esc_html__( 'Source', 'digicyfr-image-alt-use-audit' ); ?></th><th scope="col"><?php echo esc_html__( 'Block / path / image', 'digicyfr-image-alt-use-audit' ); ?></th><th scope="col"><?php echo esc_html__( 'Alt state', 'digicyfr-image-alt-use-audit' ); ?></th><th scope="col"><?php echo esc_html__( 'Alt preview', 'digicyfr-image-alt-use-audit' ); ?></th><th scope="col"><?php echo esc_html__( 'Image hint', 'digicyfr-image-alt-use-audit' ); ?></th><th scope="col"><?php echo esc_html__( 'Source status', 'digicyfr-image-alt-use-audit' ); ?></th>
				</tr></thead><tbody>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$post          = get_post( (int) $row['post_id'] );
							$source_status = self::source_status( $row );
							?>
					<tr><th scope="row">
							<?php echo $post ? esc_html( get_the_title( $post ) ) : esc_html__( 'Deleted source', 'digicyfr-image-alt-use-audit' ); ?> (<?php echo esc_html( $row['post_id'] ); ?>)
							<?php
							if ( $post && current_user_can( 'edit_post', $post->ID ) ) :
								?>
							<br /><a href="<?php echo esc_url( get_edit_post_link( $post->ID, 'raw' ) ); ?>"><?php echo esc_html__( 'Edit source', 'digicyfr-image-alt-use-audit' ); ?></a><?php endif; ?>
					</th><td><?php echo esc_html( self::source_label( $row['block_name'] ) . ' · ' . $row['block_path'] . ' · ' . $row['image_ordinal'] ); ?></td>
					<td><?php echo esc_html( self::alt_label( $row['alt_state'] ) ); ?></td><td><?php echo esc_html( $row['alt_preview'] ); ?></td><td><?php echo esc_html( $row['src_hint'] ); ?></td><td><?php echo esc_html( self::source_status_label( $source_status ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				if ( $page > 1 ) :
					?>
					<a class="button" href="<?php echo esc_url( self::report_url( $scan_id, $state, $post_id, $page - 1 ) ); ?>"><?php echo esc_html__( 'Previous page', 'digicyfr-image-alt-use-audit' ); ?></a><?php endif; ?>
				<?php
				if ( $has_next ) :
					?>
					<a class="button" href="<?php echo esc_url( self::report_url( $scan_id, $state, $post_id, $page + 1 ) ); ?>"><?php echo esc_html__( 'Next page', 'digicyfr-image-alt-use-audit' ); ?></a><?php endif; ?>
			</div></div>
		</div>
		<?php
	}

	/** Prefix formula-like spreadsheet text in every CSV cell.
	 *
	 * @param mixed $value Raw cell.
	 */
	public static function csv_safe( $value ): string {
		$text = (string) $value;
		return preg_match( '/^(?:[\x00-\x20]*[=+\-@]|[\t\r])/u', $text ) ? "'" . $text : $text;
	}

	/** Stream one fixed-header, selected report CSV in bounded ID chunks.
	 *
	 * @param string   $scan_id Scan UUID.
	 * @param string   $state Alt state filter.
	 * @param int      $post_id Source ID filter.
	 * @param resource $stream Writable temporary stream.
	 */
	public static function stream_csv( string $scan_id, string $state, int $post_id, $stream ): bool {
		global $wpdb;
		$run = DIAU_Run::get( $scan_id );
		if ( ! current_user_can( 'manage_options' ) || ! $run || ! is_resource( $stream ) ) {
			return false;
		}
		$state  = self::filter_state( $state );
		$table  = DIAU_Storage::tables()['uses'];
		$header = array( 'scan_id', 'scan_started_at_utc', 'scan_status', 'coverage_status', 'post_id', 'title', 'permalink', 'source_block', 'block_path', 'image_ordinal', 'alt_state', 'alt_preview', 'src_hint', 'attachment_id', 'source_status' );
		if ( false === fputcsv( $stream, $header, ',', '"', '' ) ) {
			return false;
		}
		$cursor = 0;
		do {
			$where = 'scan_id = %s AND id > %d';
			$args  = array( $scan_id, $cursor );
			if ( 'all' !== $state ) {
				$where .= ' AND alt_state = %s';
				$args[] = $state;
			}
			if ( $post_id > 0 ) {
				$where .= ' AND post_id = %d';
				$args[] = $post_id;
			}
			$args[] = self::CSV_BATCH_SIZE;
			$query  = "SELECT * FROM $table WHERE $where ORDER BY id ASC LIMIT %d";
			$rows   = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table, whitelisted clauses, prepared values.
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				$post   = get_post( (int) $row['post_id'] );
				$fields = array(
					$scan_id,
					$run['started_at'],
					$run['status'],
					'complete' === $run['status'] ? 'complete' : 'incomplete',
					$row['post_id'],
					$post ? get_post_field( 'post_title', $post->ID, 'raw' ) : '',
					$post ? get_permalink( $post ) : '',
					self::source_label( $row['block_name'] ),
					$row['block_path'],
					$row['image_ordinal'],
					$row['alt_state'],
					$row['alt_preview'],
					$row['src_hint'],
					$row['attachment_id'],
					self::source_status( $row ),
				);
				if ( false === fputcsv( $stream, array_map( array( __CLASS__, 'csv_safe' ), $fields ), ',', '"', '' ) ) {
					return false;
				}
			}
			$row_count = count( $rows );
		} while ( self::CSV_BATCH_SIZE === $row_count );
		return true;
	}

	/** Validate action-specific POST nonce, capability and target scan. */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage this audit.', 'digicyfr-image-alt-use-audit' ) );
		}
		$raw_action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$action     = str_starts_with( $raw_action, 'diau_' ) ? substr( $raw_action, 5 ) : '';
		if ( ! in_array( $action, array( 'start', 'continue', 'cancel', 'export' ), true ) ) {
			wp_die( esc_html__( 'Unknown scan action.', 'digicyfr-image-alt-use-audit' ) );
		}
		$scan_id = isset( $_POST['scan_id'] ) && is_string( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		check_admin_referer( 'diau_' . $action . ( 'start' === $action ? '' : '_' . $scan_id ) );
		if ( 'export' === $action ) {
			$state   = self::filter_state( isset( $_POST['alt_state'] ) && is_string( $_POST['alt_state'] ) ? sanitize_key( wp_unslash( $_POST['alt_state'] ) ) : 'all' );
			$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
			if ( ! DIAU_Run::get( $scan_id ) ) {
				wp_die( esc_html__( 'This scan report is unavailable.', 'digicyfr-image-alt-use-audit' ) );
			}
			$stream = fopen( 'php://temp/maxmemory:2097152', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Bounded-memory spool prevents partial CSV on database failure.
			if ( ! is_resource( $stream ) ) {
				wp_die( esc_html__( 'Could not open the CSV output stream.', 'digicyfr-image-alt-use-audit' ) );
			}
			if ( ! self::stream_csv( $scan_id, $state, $post_id, $stream ) ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close temporary spool on failure.
				wp_die( esc_html__( 'Could not read the full CSV report. Retry later.', 'digicyfr-image-alt-use-audit' ) );
			}
			rewind( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- Temporary spool, not a filesystem path.
			nocache_headers();
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="digicyfr-image-alt-use-audit.csv"' );
			fpassthru( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru -- Stream validated spool after successful complete export.
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Temporary spool, not a filesystem path.
			exit;
		}
		$result = match ( $action ) {
			'start' => DIAU_Run::start(),
			'continue' => DIAU_Run::continue_scan( $scan_id ),
			'cancel' => DIAU_Run::cancel( $scan_id ),
		};
		$target = admin_url( 'tools.php?page=' . self::PAGE );
		if ( is_wp_error( $result ) ) {
			$target = add_query_arg( 'diau_error', sanitize_key( $result->get_error_code() ), $target );
		} else {
			$target = add_query_arg( 'diau_notice', $action, $target );
			if ( in_array( $result['status'], array( 'queued', 'running' ), true ) ) {
				$target = add_query_arg( 'diau_auto', '1', $target );
			}
		}
		wp_safe_redirect( $target );
		exit;
	}
}
