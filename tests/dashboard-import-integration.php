<?php
/** Exercise dashboard staging and batches on a disposable WordPress site. */

use WPCV\Dashboard_Import;
use WPCV\Entries;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'WPCV_TEST_SITE' ) ) {
	throw new RuntimeException( 'Use a disposable site with WPCV_TEST_SITE=1.' );
}

$assert = static function ( bool $value, string $message ): void {
	if ( ! $value ) { throw new RuntimeException( $message ); }
};
$directory = get_temp_dir() . 'wpcv-dashboard-test-' . wp_generate_uuid4();
wp_mkdir_p( $directory );
copy( ABSPATH . WPINC . '/images/w-logo-blue.png', $directory . '/image.png' );
$file = $directory . '/entries.json';
$dataset = 'dashboard-test-' . wp_generate_uuid4();
$original_user = get_current_user_id();
$initial_media = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$tokens = array();
$batches = array();
$other_user = 0;
$base = array( 'schema_version' => 1, 'dataset_id' => $dataset, 'source' => array( 'document' => 'dashboard-test.pdf', 'sha256' => str_repeat( 'b', 64 ) ), 'entries' => array() );
for ( $index = 0; $index < 7; $index++ ) {
	$base['entries'][] = array( 'id' => 'entry-' . $index, 'title' => 'Dashboard entry ' . $index, 'year' => 2020 + $index, 'categories' => array( 'Exhibition' ), 'review_notes' => array( 'Check the title.' ), 'thumbnail' => array( 'path' => 'image.png', 'sha256' => hash_file( 'sha256', $directory . '/image.png' ), 'alt' => 'Test image' ) );
}
$write = static function ( array $data ) use ( $file ): void { file_put_contents( $file, wp_json_encode( $data ) ); };
$zip_file = $directory . '/package.zip';
$make_zip = static function ( array $entries ) use ( $zip_file ): void {
	$zip = new ZipArchive();
	$zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $entries as $path => $content ) { $zip->addFromString( $path, $content ); }
	$zip->close();
};

try {
	$write( $base );
	wp_set_current_user( 0 );
	$assert( is_wp_error( Dashboard_Import::stage_file( $file, 'json' ) ), 'Anonymous callers cannot stage dashboard imports.' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );
	$assert( is_wp_error( Dashboard_Import::stage_file( $file, 'json' ) ), 'JSON thumbnail references require their images or explicit text-only mode.' );
	$text = Dashboard_Import::stage_file( $file, 'json', true );
	$assert( ! is_wp_error( $text ) && 7 === $text['total'] && empty( $text['images'] ), 'Text-only staging strips thumbnail references for preview.' );
	$tokens[] = $text['token'];
	$assert( ! str_starts_with( wp_normalize_path( $text['manifest'] ), wp_normalize_path( ABSPATH ) ), 'Staging is outside the website directory.' );
	$assert( ! get_posts( array( 'post_type' => Entries::POST_TYPE, 'meta_key' => '_wpcv_import_batch', 'meta_value' => $text['batch'] ) ), 'Preview creates no posts.' );
	$other_user = wp_insert_user( array( 'user_login' => 'wpcv-dashboard-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
	wp_set_current_user( $other_user );
	$assert( is_wp_error( Dashboard_Import::state( $text['token'] ) ) && is_wp_error( Dashboard_Import::advance( $text['token'], 0 ) ), 'Even another administrator cannot use someone else\'s staged import.' );
	wp_set_current_user( $admins[0]->ID );
	Dashboard_Import::discard( $text['token'] );
	$assert( ! is_file( $text['manifest'] ) && is_wp_error( Dashboard_Import::state( $text['token'] ) ), 'Discard removes private files and invalidates the preview.' );

	foreach ( array( '../entries.json', '/entries.json', 'C:/entries.json', 'folder/../entries.json' ) as $unsafe ) {
		$make_zip( array( $unsafe => wp_json_encode( $base ) ) );
		$assert( is_wp_error( Dashboard_Import::stage_file( $zip_file, 'zip' ) ), 'ZIP traversal/absolute paths are rejected.' );
	}
	$make_zip( array( 'entries.json' => wp_json_encode( $base ), 'shell.php' => '<?php echo 1;' ) );
	$assert( is_wp_error( Dashboard_Import::stage_file( $zip_file, 'zip' ) ), 'Executable ZIP entries are rejected.' );
	$make_zip( array( 'entries.json' => wp_json_encode( $base ), 'nested/entries.json' => wp_json_encode( $base ) ) );
	$assert( is_wp_error( Dashboard_Import::stage_file( $zip_file, 'zip' ) ), 'Multiple manifests are rejected.' );
	$make_zip( array( 'entries.json' => wp_json_encode( $base ), 'ENTRIES.JSON' => wp_json_encode( $base ) ) );
	$assert( is_wp_error( Dashboard_Import::stage_file( $zip_file, 'zip' ) ), 'Case-insensitive duplicate paths are rejected.' );
	$make_zip( array( 'entries.json' => wp_json_encode( $base ), 'image.png' => '/etc/passwd' ) );
	$zip = new ZipArchive();
	$zip->open( $zip_file );
	$zip->setExternalAttributesName( 'image.png', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
	$zip->close();
	$assert( is_wp_error( Dashboard_Import::stage_file( $zip_file, 'zip' ) ), 'ZIP symlinks are rejected.' );
	$make_zip( array( 'entries.json' => wp_json_encode( $base ), 'large.png' => str_repeat( 'a', 21 * MB_IN_BYTES ) ) );
	$assert( is_wp_error( Dashboard_Import::stage_file( $zip_file, 'zip' ) ), 'Oversized expanded files are rejected before extraction.' );

	$make_zip( array( 'review/entries.json' => wp_json_encode( $base ), 'review/image.png' => file_get_contents( $directory . '/image.png' ), 'review/review.html' => '<script>not extracted</script>', 'review/review.csv' => 'test' ) );
	$state = Dashboard_Import::stage_file( $zip_file, 'zip' );
	$assert( ! is_wp_error( $state ) && 7 === count( $state['images'] ), 'Nested manifests and matching raster images stage successfully.' );
	$tokens[] = $state['token'];
	$batches[] = $state['batch'];
	$assert( ! is_file( dirname( $state['manifest'] ) . '/review.html' ), 'Review HTML is never extracted or served.' );
	$assert( '2020' === $state['rows'][0]['date'] && 'Exhibition' === $state['rows'][0]['categories'], 'Preview exposes readable dates and categories.' );
	$lock = 'wpcv_dashboard_import_lock_' . $state['token'];
	add_option( $lock, 'other-request', '', false );
	$assert( is_wp_error( Dashboard_Import::advance( $state['token'], 0 ) ), 'Concurrent requests on the same session cannot advance together.' );
	delete_option( $lock );
	$first = Dashboard_Import::advance( $state['token'], 0 );
	$assert( ! is_wp_error( $first ) && 5 === $first['cursor'] && 5 === $first['created'] && ! $first['finished'], 'One request creates only one small batch.' );
	$stale = Dashboard_Import::advance( $state['token'], 0 );
	$assert( ! is_wp_error( $stale ) && 5 === $stale['cursor'] && 5 === $stale['created'], 'Replayed progress does not repeat or double-count work.' );
	$finished = Dashboard_Import::advance( $state['token'], 5 );
	$assert( ! is_wp_error( $finished ) && $finished['finished'] && 7 === $finished['created'] && ! is_file( $finished['manifest'] ), 'Completion retains results and removes staged files.' );
	foreach ( $finished['rows'] as $row ) { $assert( 'draft' === get_post_status( $row['post_id'] ), 'Dashboard imports create only drafts.' ); }
	$repeat = Dashboard_Import::stage_file( $zip_file, 'zip' );
	$tokens[] = $repeat['token'];
	$batches[] = $repeat['batch'];
	$repeat = Dashboard_Import::advance( $repeat['token'], 0 );
	$repeat = Dashboard_Import::advance( $repeat['token'], $repeat['cursor'] );
	$assert( $repeat['finished'] && 0 === $repeat['created'] && 7 === $repeat['skipped'], 'A repeat upload skips existing source IDs without CLI use.' );

	$changed = Dashboard_Import::stage_file( $file, 'json', true );
	$tokens[] = $changed['token'];
	file_put_contents( $changed['manifest'], '\n', FILE_APPEND );
	$assert( is_wp_error( Dashboard_Import::advance( $changed['token'], 0 ) ), 'The preview cannot apply a changed staged manifest.' );
	WP_CLI::success( 'Dashboard permissions, user ownership, text-only JSON, private staging, ZIP inspection, image previews, bounded batches, replay protection, reruns, and cleanup passed.' );
} finally {
	wp_set_current_user( $original_user );
	foreach ( $tokens as $token ) { Dashboard_Import::discard( $token ); }
	foreach ( $batches as $batch ) {
		$posts = get_posts( array( 'post_type' => Entries::POST_TYPE, 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'meta_key' => '_wpcv_import_batch', 'meta_value' => $batch, 'fields' => 'ids' ) );
		foreach ( $posts as $post_id ) { wp_delete_post( $post_id, true ); }
	}
	$media = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( array_diff( $media, $initial_media ) as $id ) { wp_delete_attachment( $id, true ); }
	if ( $other_user && ! is_wp_error( $other_user ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $other_user );
	}
	foreach ( glob( $directory . '/*' ) as $path ) { unlink( $path ); }
	rmdir( $directory );
}
