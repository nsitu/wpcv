<?php
/**
 * Run with WP-CLI eval-file against an isolated, disposable WordPress database.
 * WPCV_TEST_SITE=1 wp eval-file tests/wordpress-integration.php
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'WPCV_TEST_SITE' ) ) {
	throw new RuntimeException( 'Integration tests require WP-CLI and WPCV_TEST_SITE=1 on a disposable site.' );
}

use WPCV\Entries;
use WPCV\Plugin;
use WPCV\Shortcode;

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert( post_type_exists( Entries::POST_TYPE ), 'CV post type is registered.' );
$assert( taxonomy_exists( Entries::TAXONOMY ), 'CV taxonomy is registered.' );
$assert( post_type_supports( Entries::POST_TYPE, 'thumbnail' ), 'Entries support thumbnails.' );
$assert( current_theme_supports( 'post-thumbnails', Entries::POST_TYPE ), 'The active theme supports CV thumbnails.' );
$assert( count( get_terms( array( 'taxonomy' => Entries::TAXONOMY, 'hide_empty' => false ) ) ) === 6, 'The six initial categories were seeded.' );

$posts = array();
$custom_term = 0;
$user_id = 0;
$original_user = get_current_user_id();
$original_post = $GLOBALS['post'] ?? null;
$original_request = $_POST;

$make_entry = static function ( string $title, int $year, int $month, string $status = 'publish' ) use ( &$posts ): int {
	$id = wp_insert_post( array( 'post_type' => Entries::POST_TYPE, 'post_title' => $title, 'post_status' => $status ), true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$posts[] = $id;
	if ( $year ) {
		update_post_meta( $id, '_wpcv_year', $year );
	}
	if ( $month ) {
		update_post_meta( $id, '_wpcv_month', $month );
	}
	return $id;
};

$entry_ids = static function ( string $html ): array {
	$dom = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	$xpath = new DOMXPath( $dom );
	return array_map( static fn ( $node ): string => $node->textContent, iterator_to_array( $xpath->query( '//h3' ) ) );
};

try {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admins[0]->ID );
	$early = $make_entry( 'Earlier entry', 2022, 3 );
	$late = $make_entry( 'Later entry', 2025, 11 );
	$same_year = $make_entry( 'Earlier month', 2025, 2 );
	$year_only = $make_entry( 'Year only', 2025, 0 );
	$undated = $make_entry( 'Undated entry', 0, 0 );
	$make_entry( 'DRAFT SHOULD BE HIDDEN', 2026, 1, 'draft' );
	$make_entry( 'PRIVATE SHOULD BE HIDDEN', 2026, 1, 'private' );
	$protected = $make_entry( 'PASSWORD SHOULD BE HIDDEN', 2026, 1 );
	wp_update_post( array( 'ID' => $protected, 'post_password' => 'test-password' ) );

	$_POST = array(
		'wpcv_nonce' => wp_create_nonce( 'wpcv_save_entry' ),
		'_wpcv_context' => wp_slash( "Gallery <b>One</b>\nC:\\Studio" ),
		'_wpcv_location' => 'Toronto <script>alert(1)</script>',
		'_wpcv_url' => 'https://example.org/cv?entry=one&year=2025',
		'_wpcv_month' => '11',
		'_wpcv_year' => '2025',
	);
	Entries::save( $late );
	$assert( "Gallery One\nC:\\Studio" === get_post_meta( $late, '_wpcv_context', true ), 'Context is sanitized and preserves newlines and backslashes.' );
	$assert( 'Toronto' === get_post_meta( $late, '_wpcv_location', true ), 'Location strips executable markup.' );
	$assert( 202511 === Entries::date_value( $late ), 'CV date is independent of the publish date.' );
	$_POST['wpcv_nonce'] = 'invalid';
	$_POST['_wpcv_year'] = '1999';
	Entries::save( $late );
	$assert( 202511 === Entries::date_value( $late ), 'Invalid nonces cannot update entries.' );
	$_POST = array();
	Entries::save( $late );
	$assert( 202511 === Entries::date_value( $late ), 'Saves without the meta box do not erase entry details.' );

	$user_id = wp_insert_user( array( 'user_login' => 'wpcv-test-' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	wp_set_current_user( $user_id );
	$_POST = array( 'wpcv_nonce' => wp_create_nonce( 'wpcv_save_entry' ), '_wpcv_year' => '1999' );
	Entries::save( $late );
	$assert( 202511 === Entries::date_value( $late ), 'Users without edit permission cannot update entry details.' );
	wp_set_current_user( $admins[0]->ID );
	$_POST = array();
	update_post_meta( $year_only, '_wpcv_ongoing_as_of', 2025 );
	$assert( Entries::is_ongoing( $year_only ), 'Legacy ongoing metadata is recognized.' );
	$assert( true === get_post_meta( $year_only, '_wpcv_ongoing', true ), 'The editor and REST metadata also see legacy roles as ongoing.' );
	$_POST = array( 'wpcv_nonce' => wp_create_nonce( 'wpcv_save_entry' ), '_wpcv_ongoing' => '1' );
	Entries::save( $year_only );
	$assert( Entries::is_ongoing( $year_only ) && '2025–present' === Entries::date_label( $year_only ), 'The ongoing checkbox saves and renders a current range.' );
	$_POST['_wpcv_ongoing'] = '0';
	Entries::save( $year_only );
	$assert( ! Entries::is_ongoing( $year_only ) && 202500 === Entries::date_value( $year_only ), 'Unchecking ongoing overrides legacy metadata.' );
	$_POST = array();

	$assert( 0 === Entries::sanitize_month( 13 ) && 0 === Entries::sanitize_month( -1 ), 'Invalid months are rejected.' );
	$assert( 0 === Entries::sanitize_year( 10000 ) && 0 === Entries::sanitize_year( '2.5' ), 'Invalid years are rejected.' );
	$assert( '' === Entries::sanitize_url( 'javascript:alert(1)' ), 'Script URLs are rejected.' );
	$assert( '2025' === Entries::date_label( $year_only ), 'Year-only dates do not invent a month.' );
	$assert( 'Undated' === Entries::date_label( $undated ), 'Undated dates have a readable label.' );

	$result = wp_insert_term( 'Custom practice', Entries::TAXONOMY );
	$custom_term = $result['term_id'];
	wp_set_object_terms( $late, array( $custom_term ), Entries::TAXONOMY );
	wp_update_post( array( 'ID' => $late, 'post_excerpt' => 'A short description.', 'post_content' => '<p>Long description.</p><script>alert("unsafe")</script>' ) );

	$html = do_shortcode( '[wpcv]' );
	$assert( $entry_ids( $html ) === array( 'Later entry', 'Earlier month', 'Year only', 'Earlier entry', 'Undated entry' ), 'Default order uses CV year/month, excludes nonpublic entries, and retains undated entries.' );
	$assert( $entry_ids( Shortcode::render( array( 'order' => 'ASC' ) ) ) === array( 'Earlier entry', 'Year only', 'Earlier month', 'Later entry', 'Undated entry' ), 'Ascending CV dates retain undated entries at the end.' );
	$assert( str_contains( $html, 'Custom practice' ) && str_contains( $html, 'data-wpcv-filter="' . $custom_term . '"' ), 'New categories become filter buttons.' );
	$assert( str_contains( $html, 'data-categories="' . $custom_term . '"' ), 'Entries identify their assigned categories.' );
	$assert( str_contains( $html, 'A short description.' ) && str_contains( $html, 'Long description.' ) && str_contains( $html, '<details' ), 'Short and expandable long descriptions render.' );
	$assert( ! str_contains( $html, '<script' ) && ! str_contains( $html, 'javascript:' ), 'Rendered descriptions and links exclude executable markup.' );
	$assert( str_contains( $html, 'https://example.org/cv?' ), 'The entry URL renders.' );
	$assert( ( $GLOBALS['post'] ?? null ) === $original_post, 'Shortcode rendering preserves the surrounding post.' );
	$assert( 5 === substr_count( $html, 'class="wpcv__edit"' ), 'Administrators see an edit link for every displayed entry.' );
	$assert( str_contains( $html, 'href="' . esc_url( get_edit_post_link( $late, 'raw' ) ) . '"' ), 'The edit link targets the correct entry editor.' );
	wp_set_current_user( 0 );
	$assert( ! str_contains( Shortcode::render(), 'class="wpcv__edit"' ), 'Visitors never see frontend edit links.' );
	$member = new WP_User( $user_id );
	foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts' ) as $capability ) {
		$member->add_cap( $capability );
	}
	wp_set_current_user( $user_id );
	$assert( current_user_can( 'edit_post', $late ) && ! current_user_can( 'manage_options' ), 'The non-admin test user can edit entries but is not an administrator.' );
	$assert( ! str_contains( Shortcode::render(), 'class="wpcv__edit"' ), 'Non-administrators do not see edit links even with entry-editing permissions.' );
	wp_set_current_user( $admins[0]->ID );

	$term = get_term_by( 'name', 'Degree', Entries::TAXONOMY );
	wp_delete_term( $term->term_id, Entries::TAXONOMY );
	Plugin::upgrade();
	$assert( ! term_exists( 'Degree', Entries::TAXONOMY ), 'Deleted initial categories stay deleted on later requests.' );
	wp_insert_term( 'Degree', Entries::TAXONOMY );

	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	$posts = array();
	$assert( str_contains( Shortcode::render(), 'No CV entries to display.' ), 'An empty CV has a readable empty state.' );
	WP_CLI::success( 'CV registration, category seeding, secure saves, date ordering, published visibility, taxonomy filters, output escaping, and empty state passed.' );
} finally {
	$_POST = $original_request;
	wp_set_current_user( $original_user );
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $custom_term ) {
		wp_delete_term( $custom_term, Entries::TAXONOMY );
	}
	if ( $user_id && ! is_wp_error( $user_id ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
	}
}
