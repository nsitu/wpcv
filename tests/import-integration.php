<?php
/** Test import behavior on the same disposable WordPress site as the CV tests. */

use WPCV\Entries;
use WPCV\Importer;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'WPCV_TEST_SITE' ) ) {
	throw new RuntimeException( 'Use a disposable site with WPCV_TEST_SITE=1.' );
}

$assert = static function ( bool $value, string $message ): void {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
};
$count = static fn (): int => count( get_posts( array( 'post_type' => Entries::POST_TYPE, 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
$directory = get_temp_dir() . 'wpcv-import-test-' . wp_generate_uuid4();
wp_mkdir_p( $directory );
copy( ABSPATH . WPINC . '/images/w-logo-blue.png', $directory . '/image.png' );
$file = $directory . '/entries.json';
$dataset = 'test-' . wp_generate_uuid4();
$batch = 'batch-' . wp_generate_uuid4();
$original_user = get_current_user_id();
$initial_count = $count();
$initial_media = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$base = array(
	'id' => 'first', 'title' => 'Imported exhibition', 'summary' => 'Short description', 'description' => 'Long description with <literal> text.',
	'context' => "R&D\\Studio", 'location' => 'Test city', 'url' => 'https://example.org/work',
	'year' => 2014, 'month' => 5, 'end_year' => 2014, 'end_month' => 6, 'date_label' => 'May-Jun 2014',
	'ongoing' => false, 'categories' => array( 'Import test category' ), 'source_page' => 1,
	'source_text' => 'Original source words', 'source_date_text' => 'May-Jun 2014', 'review_notes' => array( 'Review the category.' ),
	'thumbnail' => array( 'path' => 'image.png', 'sha256' => hash_file( 'sha256', $directory . '/image.png' ), 'alt' => 'Test logo' ),
);
$second = array_replace( $base, array( 'id' => 'second', 'title' => 'Ongoing role', 'year' => 2003, 'month' => 0, 'end_year' => 0, 'end_month' => 0, 'ongoing' => true, 'date_label' => '2003-present' ) );
$data = array( 'schema_version' => 1, 'dataset_id' => $dataset, 'source' => array( 'document' => 'synthetic-test.pdf', 'sha256' => str_repeat( 'a', 64 ) ), 'entries' => array( $base, $second ) );
$write = static function ( array $payload ) use ( $file ): void {
	file_put_contents( $file, wp_json_encode( $payload ) );
};
$fail_upload = static function ( array $upload ): array {
	$upload['error'] = 'Intentional upload failure for integration test';
	return $upload;
};

try {
	$write( $data );
	wp_set_current_user( 0 );
	$preview = Importer::run( $file );
	$assert( ! is_wp_error( $preview ) && 2 === $preview['would_create'] && $initial_count === $count(), 'Default preview performs no entry writes.' );
	$assert( ! term_exists( 'Import test category', Entries::TAXONOMY ), 'Preview does not create categories.' );
	$assert( is_wp_error( Importer::run( $file, true, $batch ) ), 'Apply requires an administrator.' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );

	$invalid = $data;
	$invalid['entries'][1]['month'] = 13;
	$write( $invalid );
	$assert( is_wp_error( Importer::run( $file, true, $batch ) ) && $initial_count === $count(), 'A bad later record prevents all writes.' );
	$invalid = $data;
	$invalid['entries'][0]['thumbnail']['path'] = '../image.png';
	$write( $invalid );
	$assert( is_wp_error( Importer::load( $file ) ), 'Images cannot escape the package directory.' );
	$invalid = $data;
	$invalid['entries'][0]['thumbnail']['sha256'] = str_repeat( '0', 64 );
	$write( $invalid );
	$assert( is_wp_error( Importer::load( $file ) ), 'Wrong image checksums are rejected.' );
	$invalid = $data;
	$invalid['entries'][1]['id'] = 'first';
	$write( $invalid );
	$assert( is_wp_error( Importer::load( $file ) ), 'Duplicate record identifiers are rejected.' );
	$invalid = $data;
	$invalid['entries'][0]['end_year'] = 2013;
	$write( $invalid );
	$assert( is_wp_error( Importer::load( $file ) ), 'Reversed date ranges are rejected.' );
	$invalid = $data;
	$invalid['entries'][0]['url'] = 'javascript:alert(1)';
	$write( $invalid );
	$assert( is_wp_error( Importer::load( $file ) ), 'Non-HTTP URL protocols are rejected.' );
	$invalid = $data;
	$invalid['entries'][1]['ongoing'] = 'true';
	$write( $invalid );
	$assert( is_wp_error( Importer::load( $file ) ), 'Ongoing must be a boolean.' );
	$legacy = $data;
	unset( $legacy['entries'][1]['ongoing'] );
	$legacy['entries'][1]['ongoing_as_of'] = 2022;
	$write( $legacy );
	$loaded = Importer::load( $file );
	$assert( ! is_wp_error( $loaded ) && true === $loaded['entries'][1]['ongoing'] && ! isset( $loaded['entries'][1]['ongoing_as_of'] ), 'Older JSON date fields map to an ongoing flag.' );

	$write( $data );
	add_option( 'wpcv_import_lock', 'another-process', '', false );
	$assert( is_wp_error( Importer::run( $file, true, $batch ) ), 'Concurrent imports cannot apply.' );
	$assert( is_wp_error( Importer::rollback( $batch, true ) ), 'Rollback shares the import lock.' );
	$assert( 'another-process' === get_option( 'wpcv_import_lock' ), 'A failed lock acquisition leaves the owner lock intact.' );
	delete_option( 'wpcv_import_lock' );
	$result = Importer::run( $file, true, $batch );
	$assert( ! is_wp_error( $result ) && 2 === $result['created'], 'Import creates both drafts.' );
	$first_id = $result['rows'][0]['post_id'];
	$second_id = $result['rows'][1]['post_id'];
	$assert( 'draft' === get_post_status( $first_id ) && 'draft' === get_post_status( $second_id ), 'Import never publishes entries.' );
	$assert( "R&D\\Studio" === get_post_meta( $first_id, '_wpcv_context', true ), 'Imported text preserves backslashes.' );
	$assert( 201406 === Entries::date_value( $first_id ) && 'May-Jun 2014' === Entries::date_label( $first_id ), 'Completed ranges sort by the end date and preserve their label.' );
	$assert( (int) current_time( 'Ym' ) === Entries::date_value( $second_id ) && '2003-present' === Entries::date_label( $second_id ), 'Ongoing roles remain current without a source-year qualifier.' );
	$assert( get_post_thumbnail_id( $first_id ) > 0 && get_post_thumbnail_id( $first_id ) === get_post_thumbnail_id( $second_id ), 'Equal image hashes reuse one media attachment.' );
	$assert( 1 === count( wp_get_object_terms( $first_id, Entries::TAXONOMY ) ), 'Categories are assigned.' );
	$source_record = json_decode( get_post_meta( $first_id, '_wpcv_import_record', true ), true );
	$assert( $source_record['source_text'] === $base['source_text'] && ! isset( $source_record['_image_path'] ), 'Source records are retained without server-local paths.' );
	$repeat = Importer::run( $file, true, $batch );
	$assert( ! is_wp_error( $repeat ) && 2 === $repeat['skipped'] && 0 === $repeat['created'], 'Reruns do not duplicate posts.' );

	// Simulate an unchanged entry imported before the ongoing-year setting was removed.
	delete_post_meta( $second_id, '_wpcv_ongoing' );
	update_post_meta( $second_id, '_wpcv_ongoing_as_of', 2022 );
	$assert( Entries::is_ongoing( $second_id ) && (int) current_time( 'Ym' ) === Entries::date_value( $second_id ) && '2003-present' === Entries::date_label( $second_id ), 'Legacy entries automatically display as current.' );
	$post = get_post( $second_id );
	$legacy_values = array( $post->post_title, $post->post_excerpt, $post->post_content, $post->post_author, get_post_thumbnail_id( $second_id ) );
	foreach ( array( 'context', 'month', 'year', 'end_month', 'end_year', 'date_label', 'ongoing_as_of', 'url', 'location' ) as $key ) {
		$legacy_values[] = (string) get_post_meta( $second_id, '_wpcv_' . $key, true );
	}
	$legacy_terms = wp_get_object_terms( $second_id, Entries::TAXONOMY, array( 'fields' => 'ids' ) );
	sort( $legacy_terms, SORT_NUMERIC );
	$legacy_values[] = $legacy_terms;
	update_post_meta( $second_id, '_wpcv_import_fingerprint', hash( 'sha256', wp_json_encode( $legacy_values ) ) );

	wp_update_post( array( 'ID' => $first_id, 'post_title' => 'Manually edited title' ) );
	$data['entries'][0]['title'] = 'Changed source title';
	$write( $data );
	$repeat = Importer::run( $file, true, $batch );
	$assert( 'Manually edited title' === get_the_title( $first_id ) && str_contains( $repeat['rows'][0]['notes'], 'Source data changed' ), 'Changed source data never overwrites manual edits.' );
	$rollback = Importer::rollback( $batch );
	$actions = array_column( $rollback['rows'], 'action', 'post_id' );
	$assert( 'preserved' === $actions[ $first_id ] && 'would_trash' === $actions[ $second_id ] && 'draft' === get_post_status( $second_id ), 'Rollback previews preserve edited entries and make no changes.' );
	wp_update_post( array( 'ID' => $second_id, 'post_status' => 'publish' ) );
	$rollback = Importer::rollback( $batch, true );
	$assert( 'publish' === get_post_status( $second_id ), 'Published entries are preserved by rollback.' );
	wp_update_post( array( 'ID' => $second_id, 'post_status' => 'draft' ) );
	$rollback = Importer::rollback( $batch, true );
	$assert( 'trash' === get_post_status( $second_id ) && 'draft' === get_post_status( $first_id ), 'Rollback trashes only unchanged drafts.' );
	$assert( 'attachment' === get_post_type( get_post_thumbnail_id( $first_id ) ), 'Rollback retains media for reuse and restoration.' );
	$repeat = Importer::run( $file, true, $batch );
	$assert( 2 === $repeat['skipped'] && 0 === $repeat['created'], 'Trashed imported entries are not silently recreated.' );

	// A failing media import removes only its newly created, incomplete draft.
	file_put_contents( $directory . '/image.png', 'trailing checksum distinction', FILE_APPEND );
	$failing = $data;
	$failing['entries'] = array( array_replace( $base, array( 'id' => 'upload-retry' ) ) );
	$failing['entries'][0]['thumbnail']['sha256'] = hash_file( 'sha256', $directory . '/image.png' );
	$write( $failing );
	$before_failure = $count();
	add_filter( 'wp_handle_sideload_prefilter', $fail_upload );
	$failure = Importer::run( $file, true, $batch );
	remove_filter( 'wp_handle_sideload_prefilter', $fail_upload );
	$assert( is_wp_error( $failure ) && $before_failure === $count() && ! get_option( 'wpcv_import_lock' ), 'Upload failure cleans its incomplete draft and releases the lock.' );
	$resume = Importer::run( $file, true, $batch );
	$assert( ! is_wp_error( $resume ) && 1 === $resume['created'], 'A failed upload can be retried without duplicate drafts.' );
	WP_CLI::success( 'Import preview, validation, permissions, locking, draft creation, media reuse, source preservation, reruns, failure recovery, and conservative rollback passed.' );
} finally {
	remove_filter( 'wp_handle_sideload_prefilter', $fail_upload );
	delete_option( 'wpcv_import_lock' );
	wp_set_current_user( $original_user );
	$posts = get_posts( array( 'post_type' => Entries::POST_TYPE, 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'meta_key' => '_wpcv_import_batch', 'meta_value' => $batch, 'fields' => 'ids' ) );
	foreach ( $posts as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	$term = term_exists( 'Import test category', Entries::TAXONOMY );
	if ( $term ) {
		wp_delete_term( (int) $term['term_id'], Entries::TAXONOMY );
	}
	$media = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( array_diff( $media, $initial_media ) as $id ) {
		wp_delete_attachment( $id, true );
	}
	foreach ( glob( $directory . '/*' ) as $path ) {
		unlink( $path );
	}
	rmdir( $directory );
}
