<?php
/** Exercise the installed exact ZIP in a disposable WordPress site. */

declare( strict_types=1 );

$port = getenv( 'DIGICYFR_TEST_PORT' );
$_SERVER['HTTP_HOST']   = '127.0.0.1:' . $port;
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PORT'] = $port;
$_SERVER['HTTPS']       = 'off';

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

function check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: $message\n" );
		exit( 1 );
	}
}

function post_with( string $title, string $content, string $status = 'publish' ): int {
	$id = wp_insert_post( array( 'post_title' => $title, 'post_content' => $content, 'post_status' => $status, 'post_type' => 'post' ), true );
	check( is_int( $id ) && $id > 0, 'created ' . $title );
	return $id;
}

function finish( string $id ): array {
	$initial = DIAU_Run::get( $id );
	$previous = (int) $initial['processed_count'] + (int) $initial['skipped_count'];
	for ( $n = 0; $n < 300; ++$n ) {
		$run = DIAU_Run::continue_scan( $id );
		check( is_array( $run ), 'batch ' . $n . ' succeeds' );
		check( (int) $run['processed_count'] + (int) $run['skipped_count'] - $previous <= 10, 'bounded batch count' );
		$previous = (int) $run['processed_count'] + (int) $run['skipped_count'];
		if ( ! in_array( $run['status'], array( 'queued', 'running' ), true ) ) {
			return array( $run, $n + 1 );
		}
	}
	check( false, 'scan completes within bounded batch count' );
	return array();
}

$plugin = 'digicyfr-image-alt-use-audit/digicyfr-image-alt-use-audit.php';
check( is_plugin_active( $plugin ), 'exact ZIP active' );
check( '0.1.0' === DIGICYFR_IMAGE_ALT_USE_AUDIT_VERSION, 'version' );
check( DIAU_Storage::transactional(), 'three InnoDB tables installed' );
$admin = get_user_by( 'login', 'digicyfr_test_admin' );
check( $admin instanceof WP_User, 'administrator user' );
wp_set_current_user( $admin->ID );
check( load_textdomain( 'digicyfr-image-alt-use-audit', WP_PLUGIN_DIR . '/digicyfr-image-alt-use-audit/languages/digicyfr-image-alt-use-audit-pl_PL.mo' ), 'bundled Polish catalog loads' );
check( 'Audyt użyć obrazów i alt' === __( 'Image Alt Use Audit', 'digicyfr-image-alt-use-audit' ), 'Polish runtime label' );
unload_textdomain( 'digicyfr-image-alt-use-audit' );

check( 'missing' === DIAU_Scanner::alt_state( null ), 'missing alt' );
check( 'empty' === DIAU_Scanner::alt_state( '' ), 'empty alt' );
check( 'whitespace' === DIAU_Scanner::alt_state( " \t\xC2\xA0" ), 'whitespace alt' );
check( 'nonempty' === DIAU_Scanner::alt_state( 'Zażółć' ), 'nonempty alt' );
check( 'invalid' === DIAU_Scanner::alt_state( true ), 'boolean alt invalid' );
check( 'example.org/a.jpg' === DIAU_Scanner::src_hint( 'https://alice:pw@example.org/a.jpg?token=secret#fragment' ), 'src hint strips secrets' );
check( '' === DIAU_Scanner::src_hint( 'data:image/png;base64,abc' ), 'data URL omitted' );
check( DIAU_Admin::csv_safe( ' +SUM(1)' ) === "' +SUM(1)" && DIAU_Admin::csv_safe( "\t=1" ) === "'\t=1", 'CSV formula guard' );

$nested = '<!-- wp:gallery --><figure class="wp-block-gallery"><!-- wp:image --><figure><img src="/a.jpg" alt="" /></figure><!-- /wp:image --><!-- wp:image --><figure><a href="/view"><img src="/b.jpg" alt="Zażółć" /></a></figure><!-- /wp:image --></figure><!-- /wp:gallery -->';
$nested_scan = DIAU_Scanner::scan_post( 0, $nested );
check( 2 === count( $nested_scan['uses'] ) && array() === $nested_scan['coverage'], 'nested gallery once per saved img' );
$states = DIAU_Scanner::scan_post( 0, '<img src="/a"/><img src="/b" alt=""/><img src="/c" alt="  "/><img src="/d" alt="Opis"/>' );
check( array( 'missing', 'empty', 'whitespace', 'nonempty' ) === array_column( $states['uses'], 'alt_state' ), 'four saved alt states' );
check( str_contains( DIAU_Admin::source_label( 'core/html' ), 'Saved HTML' ), 'HTML block source label' );
$cover_color = '<!-- wp:cover {"overlayColor":"black"} --><div class="wp-block-cover"><span class="wp-block-cover__background has-black-background-color"></span><div class="wp-block-cover__inner-container"><p>Text</p></div></div><!-- /wp:cover -->';
check( array() === DIAU_Scanner::scan_post( 0, $cover_color )['coverage'], 'solid-color Cover is clean' );
$cover_image = '<!-- wp:cover {"url":"https://example.org/bg.jpg"} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container">Text</div></div><!-- /wp:cover -->';
check( in_array( 'cover_without_saved_img', DIAU_Scanner::scan_post( 0, $cover_image )['coverage'], true ), 'background Cover marked unsupported' );
$cover_saved = '<!-- wp:cover --><div class="wp-block-cover"><img src="/cover.jpg" alt="Okładka"></div><!-- /wp:cover -->';
check( 1 === count( DIAU_Scanner::scan_post( 0, $cover_saved )['uses'] ) && array() === DIAU_Scanner::scan_post( 0, $cover_saved )['coverage'], 'Cover saved img counted without false unsupported note' );
check( 'post_too_large' === DIAU_Scanner::scan_post( 0, str_repeat( 'A', 524289 ) )['skip'], 'oversize source skipped' );
check( 'too_many_images' === DIAU_Scanner::scan_post( 0, str_repeat( '<img src="/a">', 301 ) )['skip'], '301 images skipped' );

// A clean first report remains available while a later incomplete run is in progress.
$clean_id = post_with( 'Clean baseline', '<!-- wp:image --><figure><img src="/clean.jpg" alt="Clean" /></figure><!-- /wp:image -->' );
$clean = DIAU_Run::start();
check( is_array( $clean ) && 'queued' === $clean['status'], 'start clean run' );
list( $clean_done ) = finish( $clean['scan_id'] );
check( 'complete' === $clean_done['status'], 'clean run complete' );
check( $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'complete pointer' );
update_option( 'diau_latest_complete_id', '', false );
check( $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'pointer repaired after simulated post-commit crash' );
$old_suppress = $wpdb->suppress_errors( true );
$break_pointer = static function ( string $sql ): string {
	return str_contains( $sql, 'SELECT scan_id FROM wp_diau_runs ORDER BY id DESC' ) ? 'SELECT nonexistent_column FROM wp_diau_runs' : $sql;
};
add_filter( 'query', $break_pointer );
$failed_pointers = DIAU_Run::pointers();
remove_filter( 'query', $break_pointer );
check( true === $failed_pointers['storage_error'] && $clean['scan_id'] === $failed_pointers['latest_complete'], 'pointer SQL error retains existing report' );
$break_high_water = static function ( string $sql ): string {
	return str_contains( $sql, 'SELECT MAX(ID) FROM wp_posts' ) ? 'SELECT nonexistent_column FROM wp_posts' : $sql;
};
add_filter( 'query', $break_high_water );
check( is_wp_error( DIAU_Run::start() ), 'high-water SQL error prevents false empty scan' );
remove_filter( 'query', $break_high_water );
$wpdb->suppress_errors( $old_suppress );
$break_batch = static function ( string $sql ): string {
	return str_contains( $sql, 'SELECT ID, OCTET_LENGTH(post_content)' ) ? 'SELECT nonexistent_column FROM wp_posts' : $sql;
};
$broken_batch_run = DIAU_Run::start();
check( is_array( $broken_batch_run ), 'batch-error fixture starts' );
$wpdb->suppress_errors( true );
add_filter( 'query', $break_batch );
check( is_wp_error( DIAU_Run::continue_scan( $broken_batch_run['scan_id'] ) ), 'batch SQL error fails instead of false complete' );
remove_filter( 'query', $break_batch );
check( 'failed' === DIAU_Run::get( $broken_batch_run['scan_id'] )['status'] && $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'batch failure retains complete report' );
$break_remaining = static function ( string $sql ): string {
	return str_contains( $sql, 'SELECT ID FROM wp_posts WHERE ID >' ) ? 'SELECT nonexistent_column FROM wp_posts' : $sql;
};
$broken_remaining_run = DIAU_Run::start();
check( is_array( $broken_remaining_run ), 'remaining-error fixture starts' );
add_filter( 'query', $break_remaining );
check( is_wp_error( DIAU_Run::continue_scan( $broken_remaining_run['scan_id'] ) ), 'remaining SQL error fails instead of false complete' );
remove_filter( 'query', $break_remaining );
check( 'failed' === DIAU_Run::get( $broken_remaining_run['scan_id'] )['status'] && $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'remaining failure retains complete report' );
$wpdb->suppress_errors( $old_suppress );

$missing = post_with( '=HYPERLINK("https://bad.example")', '<!-- wp:html --><img src="https://example.org/a.jpg?token=private" alt=""/><!-- /wp:html -->' );
$unsupported = post_with( 'Cover', $cover_image );
$oversize = post_with( 'Huge', str_repeat( 'B', 524289 ) );
$draft = post_with( 'Unpublished', '<img src="/draft.jpg" />', 'draft' );
global $wpdb;
for ( $n = 0; $n < 1000; ++$n ) {
	$inserted = $wpdb->insert( $wpdb->posts, array( 'post_author' => $admin->ID, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_content' => '<!-- wp:paragraph --><p>Batch fixture ' . $n . '</p><!-- /wp:paragraph -->', 'post_title' => 'Batch ' . $n, 'post_status' => 'publish', 'post_type' => 'post', 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ) );
	check( 1 === $inserted, 'bulk fixture insert ' . $n );
}
$partial = DIAU_Run::start();
check( is_array( $partial ) && $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'in-progress run preserves clean report' );
$first_batch = DIAU_Run::continue_scan( $partial['scan_id'] );
check( is_array( $first_batch ) && (int) $first_batch['cursor_id'] > 0, 'first bounded batch' );
$paused_cursor = (int) $first_batch['cursor_id'];
check( $paused_cursor === (int) DIAU_Run::get( $partial['scan_id'] )['cursor_id'], 'paused run retains cursor without browser requests' );
$resumed = DIAU_Run::continue_scan( $partial['scan_id'] );
check( is_array( $resumed ) && (int) $resumed['cursor_id'] > $paused_cursor, 'manual resume advances cursor' );
list( $partial_done, $batches ) = finish( $partial['scan_id'] );
check( $batches + 2 >= 100 && 'complete_with_skips' === $partial_done['status'], '1000-post run is bounded and incomplete with coverage' );
check( $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'incomplete run does not replace clean report' );
$tables = DIAU_Storage::tables();
$missing_rows = DIAU_Admin::report_rows( $partial['scan_id'], 'empty', $missing );
check( 1 === count( $missing_rows ) && 'empty' === $missing_rows[0]['alt_state'], 'saved use report filter' );
check( 'example.org/a.jpg' === $missing_rows[0]['src_hint'], 'stored src hint strips query' );
check( 0 === count( DIAU_Admin::report_rows( $partial['scan_id'], 'all', $draft ) ), 'draft excluded' );
$break_report = static function ( string $sql ): string {
	return str_contains( $sql, 'SELECT * FROM wp_diau_uses WHERE' ) ? 'SELECT nonexistent_column FROM wp_diau_uses' : $sql;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $break_report );
check( is_wp_error( DIAU_Admin::report_rows( $partial['scan_id'] ) ), 'report SQL error is distinct from empty findings' );
$broken_csv = fopen( 'php://temp', 'w+' );
check( false === DIAU_Admin::stream_csv( $partial['scan_id'], 'all', 0, $broken_csv ), 'CSV SQL error aborts export' );
fclose( $broken_csv );
remove_filter( 'query', $break_report );
$wpdb->suppress_errors( $old_suppress );
$coverage = $wpdb->get_results( $wpdb->prepare( "SELECT reason FROM {$tables['coverage']} WHERE scan_id = %s", $partial['scan_id'] ), ARRAY_A );
check( in_array( 'post_too_large', array_column( $coverage, 'reason' ), true ) && in_array( 'cover_without_saved_img', array_column( $coverage, 'reason' ), true ), 'skips and unsupported coverage persisted' );
$stream = fopen( 'php://temp', 'w+' );
check( DIAU_Admin::stream_csv( $partial['scan_id'], 'empty', $missing, $stream ), 'filtered CSV generated' );
rewind( $stream );
$header = fgetcsv( $stream, 0, ',', '"', '' );
$data = fgetcsv( $stream, 0, ',', '"', '' );
check( 15 === count( $header ) && "'=HYPERLINK(\"https://bad.example\")" === $data[5] && 'incomplete' === $data[3] && 'empty' === $data[10], 'CSV header, formula safety, coverage and filter' );
check( false === fgetcsv( $stream, 0, ',', '"', '' ), 'CSV contains selected row only' );
fclose( $stream );

// A saved result becomes stale after editing and deleted after removing its source.
wp_update_post( array( 'ID' => $missing, 'post_content' => '<p>Changed</p>', 'post_modified' => '2028-01-01 00:00:00', 'post_modified_gmt' => '2028-01-01 00:00:00', 'edit_date' => true ) );
check( 'stale' === DIAU_Admin::source_status( $missing_rows[0] ), 'edited source marked stale' );
wp_delete_post( $missing, true );
check( 'deleted' === DIAU_Admin::source_status( $missing_rows[0] ), 'deleted source marked deleted' );

$cancel = DIAU_Run::start();
check( is_array( $cancel ), 'new run can start after terminal partial run' );
check( is_array( DIAU_Run::continue_scan( $cancel['scan_id'] ) ), 'cancel fixture first batch' );
check( 'cancelled' === DIAU_Run::cancel( $cancel['scan_id'] )['status'], 'cancel persists' );
check( $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'cancel preserves clean report' );
$row_cap = DIAU_Run::start();
check( is_array( $row_cap ), 'row-cap fixture starts' );
$wpdb->update( $tables['runs'], array( 'image_count' => 50000 ), array( 'scan_id' => $row_cap['scan_id'] ) );
$capped = DIAU_Run::continue_scan( $row_cap['scan_id'] );
check( is_array( $capped ) && 'complete_with_skips' === $capped['status'] && 'row_cap' === $capped['last_error'] && (int) $capped['cursor_id'] < (int) $capped['high_water'], 'row cap leaves remaining posts explicitly unscanned' );
check( $clean['scan_id'] === DIAU_Run::pointers()['latest_complete'], 'row cap does not replace complete report' );
$subscriber = wp_create_user( 'diau_subscriber', 'test-password-only', 'sub@example.invalid' );
check( is_int( $subscriber ), 'subscriber created' );
( new WP_User( $subscriber ) )->set_role( 'subscriber' );
wp_set_current_user( $subscriber );
check( is_wp_error( DIAU_Run::start() ) && is_wp_error( DIAU_Run::continue_scan( $cancel['scan_id'] ) ), 'non-admin scan actions denied' );
check( array() === DIAU_Admin::report_rows( $clean['scan_id'] ), 'non-admin report rows denied' );
$stream = fopen( 'php://temp', 'w+' );
check( false === DIAU_Admin::stream_csv( $clean['scan_id'], 'all', 0, $stream ), 'non-admin CSV denied' );
fclose( $stream );
wp_set_current_user( $admin->ID );
deactivate_plugins( $plugin );
check( DIAU_Storage::transactional(), 'deactivation retains tables' );
activate_plugin( $plugin );
check( DIAU_Storage::transactional(), 'reactivation retains tables' );
define( 'WP_UNINSTALL_PLUGIN', $plugin );
require WP_PLUGIN_DIR . '/digicyfr-image-alt-use-audit/uninstall.php';
check( ! DIAU_Storage::transactional() && false === get_option( 'diau_schema_version', false ), 'uninstall removes tables and options' );
printf( "PASS: exact ZIP image-alt integration (WP %s, PHP %s, %d batches for 1000 posts)\n", get_bloginfo( 'version' ), PHP_VERSION, $batches );
