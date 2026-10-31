<?php
/**
 * Reviewed JSON imports. No network downloads and no existing-entry overwrites.
 *
 * @package WPCV
 */

namespace WPCV;

defined( 'ABSPATH' ) || exit;

final class Importer {

	private const META = array( 'context', 'month', 'year', 'end_month', 'end_year', 'date_label', 'ongoing_as_of', 'url', 'location' );

	/** Validate the entire package before creating any posts, terms, or media. */
	public static function load( string $file ): array|\WP_Error {
		$file = realpath( $file );
		if ( ! $file || ! is_readable( $file ) || filesize( $file ) > 10 * MB_IN_BYTES ) {
			return new \WP_Error( 'wpcv_file', 'The manifest must be a readable local JSON file smaller than 10 MB.' );
		}
		$data = json_decode( file_get_contents( $file ), true );
		if ( ! is_array( $data ) || 1 !== ( $data['schema_version'] ?? null ) || ! self::identifier( $data['dataset_id'] ?? null ) || ! is_array( $data['entries'] ?? null ) || ! array_is_list( $data['entries'] ) || ! $data['entries'] || count( $data['entries'] ) > 5000 ) {
			return new \WP_Error( 'wpcv_schema', 'Expected schema_version 1, a dataset_id, and a nonempty list of at most 5000 entries.' );
		}
		if ( ! is_array( $data['source'] ?? null ) || ! is_string( $data['source']['document'] ?? null ) || '' === trim( $data['source']['document'] ) || ! is_string( $data['source']['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $data['source']['sha256'] ) ) {
			return new \WP_Error( 'wpcv_source', 'Source document name and SHA-256 are required.' );
		}
		$seen = array();
		foreach ( $data['entries'] as $index => &$entry ) {
			if ( ! is_array( $entry ) || ! self::identifier( $entry['id'] ?? null ) || isset( $seen[ $entry['id'] ] ) || ! is_string( $entry['title'] ?? null ) || '' === sanitize_text_field( $entry['title'] ) ) {
				return new \WP_Error( 'wpcv_entry', 'Entry ' . ( $index + 1 ) . ' needs a unique id and a title.' );
			}
			$seen[ $entry['id'] ] = true;
			foreach ( array( 'summary', 'description', 'context', 'date_label', 'url', 'location', 'source_text', 'source_date_text' ) as $key ) {
				$entry[ $key ] = $entry[ $key ] ?? '';
				if ( ! is_string( $entry[ $key ] ) ) {
					return new \WP_Error( 'wpcv_text', $entry['id'] . ': ' . $key . ' must be text.' );
				}
			}
			foreach ( array( 'month' => 12, 'end_month' => 12, 'year' => 9999, 'end_year' => 9999, 'ongoing_as_of' => 9999, 'source_page' => 10000 ) as $key => $max ) {
				$entry[ $key ] = $entry[ $key ] ?? 0;
				if ( ! is_int( $entry[ $key ] ) || $entry[ $key ] < 0 || $entry[ $key ] > $max ) {
					return new \WP_Error( 'wpcv_date', $entry['id'] . ': invalid ' . $key . '.' );
				}
			}
			if ( ! self::dates_valid( $entry ) ) {
				return new \WP_Error( 'wpcv_range', $entry['id'] . ': inconsistent start, end, or ongoing-as-of dates.' );
			}
			if ( $entry['url'] && ( ! in_array( strtolower( (string) wp_parse_url( $entry['url'], PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) || ! wp_parse_url( $entry['url'], PHP_URL_HOST ) ) ) {
				return new \WP_Error( 'wpcv_url', $entry['id'] . ': URL must be an absolute HTTP or HTTPS address.' );
			}
			foreach ( array( 'categories', 'review_notes' ) as $key ) {
				$entry[ $key ] = $entry[ $key ] ?? array();
				if ( ! is_array( $entry[ $key ] ) || ! array_is_list( $entry[ $key ] ) ) {
					return new \WP_Error( 'wpcv_list', $entry['id'] . ': ' . $key . ' must be a list of strings.' );
				}
				foreach ( $entry[ $key ] as $text ) {
					if ( ! is_string( $text ) || '' === trim( $text ) || '' === sanitize_text_field( $text ) ) {
						return new \WP_Error( 'wpcv_list', $entry['id'] . ': empty or invalid ' . $key . ' value.' );
					}
				}
			}
			if ( isset( $entry['thumbnail'] ) ) {
				$image = self::image_path( dirname( $file ), $entry['thumbnail'] );
				if ( is_wp_error( $image ) ) {
					return new \WP_Error( 'wpcv_image', $entry['id'] . ': ' . $image->get_error_message() );
				}
				$entry['_image_path'] = $image;
			}
		}
		unset( $entry );
		return $data;
	}

	private static function identifier( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^[a-z0-9][a-z0-9_-]{0,99}$/D', $value );
	}

	private static function dates_valid( array $entry ): bool {
		if ( ( $entry['month'] && ! $entry['year'] ) || ( $entry['end_month'] && ! $entry['end_year'] ) ) {
			return false;
		}
		if ( ( ( $entry['end_year'] || $entry['ongoing_as_of'] ) && ! $entry['year'] ) || ( $entry['end_year'] && $entry['ongoing_as_of'] ) ) {
			return false;
		}
		if ( $entry['end_year'] && $entry['end_year'] < $entry['year'] ) {
			return false;
		}
		if ( $entry['end_year'] === $entry['year'] && $entry['month'] && $entry['end_month'] && $entry['end_month'] < $entry['month'] ) {
			return false;
		}
		return ! $entry['ongoing_as_of'] || $entry['ongoing_as_of'] >= $entry['year'];
	}

	private static function image_path( string $directory, $image ): string|\WP_Error {
		if ( ! is_array( $image ) || ! is_string( $image['path'] ?? null ) || ! is_string( $image['sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $image['sha256'] ) || ! is_string( $image['alt'] ?? null ) ) {
			return new \WP_Error( 'image', 'Thumbnail needs a relative path, SHA-256, and alt text.' );
		}
		$path = str_replace( '\\', '/', $image['path'] );
		if ( preg_match( '~^(?:/|[a-zA-Z]:)|(?:^|/)\.\.(?:/|$)|\x00~', $path ) || str_contains( $path, '://' ) ) {
			return new \WP_Error( 'image', 'Thumbnail must be a file inside the import package.' );
		}
		$resolved = realpath( $directory . '/' . $path );
		$base = wp_normalize_path( realpath( $directory ) ) . '/';
		if ( ! $resolved || ! str_starts_with( wp_normalize_path( $resolved ), $base ) || ! is_readable( $resolved ) || filesize( $resolved ) > 20 * MB_IN_BYTES ) {
			return new \WP_Error( 'image', 'Thumbnail is missing, outside the package, or larger than 20 MB.' );
		}
		if ( ! hash_equals( $image['sha256'], hash_file( 'sha256', $resolved ) ) ) {
			return new \WP_Error( 'image', 'Thumbnail checksum does not match the manifest.' );
		}
		$size = wp_getimagesize( $resolved );
		if ( ! $size || ! in_array( $size['mime'], array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) || $size[0] * $size[1] > 25000000 ) {
			return new \WP_Error( 'image', 'Thumbnail must be a raster image of at most 25 megapixels.' );
		}
		$checked = wp_check_filetype_and_ext( $resolved, basename( $resolved ) );
		if ( empty( $checked['type'] ) || $checked['type'] !== $size['mime'] ) {
			return new \WP_Error( 'image', 'Thumbnail extension does not match its image type.' );
		}
		return $resolved;
	}

	private static function existing( string $source_id ): array {
		return get_posts( array( 'post_type' => Entries::POST_TYPE, 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => 2, 'fields' => 'ids', 'meta_key' => '_wpcv_import_source_id', 'meta_value' => $source_id ) );
	}

	private static function input_hash( array $entry ): string {
		unset( $entry['_image_path'] );
		return hash( 'sha256', wp_json_encode( $entry ) );
	}

	/** Preview by default; application always creates drafts and skips duplicates. */
	public static function run( string $file, bool $apply = false, string $batch = '' ): array|\WP_Error {
		$data = self::load( $file );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( $apply && ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) ) {
			return new \WP_Error( 'wpcv_permission', 'Apply requires a WordPress administrator. Pass --user=YOUR_WORDPRESS_ADMIN_LOGIN to WP-CLI.' );
		}
		$batch = $batch ?: 'import-' . gmdate( 'Ymd-His' ) . '-' . substr( wp_generate_uuid4(), 0, 8 );
		if ( ! self::identifier( $batch ) ) {
			return new \WP_Error( 'wpcv_batch', 'Batch must use lowercase letters, numbers, hyphens, or underscores (maximum 100 characters).' );
		}
		$result = array( 'batch' => $batch, 'applied' => $apply, 'created' => 0, 'skipped' => 0, 'would_create' => 0, 'rows' => array() );
		$token = wp_generate_uuid4();
		if ( $apply && ! add_option( 'wpcv_import_lock', $token, '', false ) ) {
			return new \WP_Error( 'wpcv_locked', 'Another import is running or left an import lock. Check running processes before removing the wpcv_import_lock option.' );
		}
		try {
			foreach ( $data['entries'] as $entry ) {
				$source_id = $data['dataset_id'] . ':' . $entry['id'];
				$existing = self::existing( $source_id );
				if ( count( $existing ) > 1 ) {
					return new \WP_Error( 'wpcv_duplicates', 'Multiple posts already have source ID ' . $source_id . '. Resolve them before importing.', $result );
				}
				$notes = implode( ' | ', $entry['review_notes'] );
				if ( $existing ) {
					$post_id = (int) $existing[0];
					if ( get_post_meta( $post_id, '_wpcv_import_input_hash', true ) !== self::input_hash( $entry ) ) {
						$notes = 'Source data changed; existing entry preserved. ' . $notes;
					}
					$action = 'skipped';
					++$result['skipped'];
				} elseif ( ! $apply ) {
					$post_id = 0;
					$action = 'would_create';
					++$result['would_create'];
				} else {
					$post_id = self::create_entry( $entry, $data, $source_id, $batch );
					if ( is_wp_error( $post_id ) ) {
						return new \WP_Error( 'wpcv_partial', $source_id . ': ' . $post_id->get_error_message() . ' Completed entries remain drafts; rerun to resume.', $result );
					}
					$action = 'created';
					++$result['created'];
				}
				$result['rows'][] = array( 'source_id' => $source_id, 'title' => $entry['title'], 'action' => $action, 'post_id' => $post_id, 'notes' => $notes );
			}
		} finally {
			if ( $apply && $token === get_option( 'wpcv_import_lock' ) ) {
				delete_option( 'wpcv_import_lock' );
			}
		}
		return $result;
	}

	private static function create_entry( array $entry, array $data, string $source_id, string $batch ): int|\WP_Error {
		$meta = array();
		foreach ( self::META as $key ) {
			$meta[ '_wpcv_' . $key ] = $entry[ $key ];
		}
		$meta += array(
			'_wpcv_import_source_id' => $source_id,
			'_wpcv_import_batch' => $batch,
			'_wpcv_import_source_document' => sanitize_text_field( $data['source']['document'] ),
			'_wpcv_import_source_sha256' => $data['source']['sha256'],
			'_wpcv_import_source_page' => $entry['source_page'],
			'_wpcv_import_source_text' => $entry['source_text'],
			'_wpcv_import_source_date_text' => $entry['source_date_text'],
			'_wpcv_import_review_notes' => $entry['review_notes'],
			'_wpcv_import_input_hash' => self::input_hash( $entry ),
		);
		$source_record = $entry;
		unset( $source_record['_image_path'] );
		$meta['_wpcv_import_record'] = wp_json_encode( $source_record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$post_id = wp_insert_post( wp_slash( array( 'post_type' => Entries::POST_TYPE, 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => sanitize_text_field( $entry['title'] ), 'post_excerpt' => sanitize_textarea_field( $entry['summary'] ), 'post_content' => wpautop( esc_html( $entry['description'] ) ), 'meta_input' => $meta ) ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$terms = array();
		foreach ( $entry['categories'] as $name ) {
			$name = sanitize_text_field( $name );
			$term = term_exists( $name, Entries::TAXONOMY );
			if ( ! $term ) {
				$term = wp_insert_term( $name, Entries::TAXONOMY );
			}
			if ( is_wp_error( $term ) ) {
				wp_delete_post( $post_id, true );
				return $term;
			}
			$terms[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}
		$assigned = wp_set_object_terms( $post_id, $terms, Entries::TAXONOMY );
		if ( is_wp_error( $assigned ) ) {
			wp_delete_post( $post_id, true );
			return $assigned;
		}
		if ( isset( $entry['thumbnail'] ) ) {
			$image_id = self::import_image( $entry, $post_id );
			if ( is_wp_error( $image_id ) ) {
				wp_delete_post( $post_id, true );
				return $image_id;
			}
			set_post_thumbnail( $post_id, $image_id );
		}
		update_post_meta( $post_id, '_wpcv_import_fingerprint', self::fingerprint( $post_id ) );
		return $post_id;
	}

	private static function import_image( array $entry, int $post_id ): int|\WP_Error {
		$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_wpcv_import_media_sha256', 'meta_value' => $entry['thumbnail']['sha256'] ) );
		foreach ( $existing as $id ) {
			if ( get_attached_file( $id ) && is_file( get_attached_file( $id ) ) ) {
				return (int) $id;
			}
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = wp_tempnam( basename( $entry['_image_path'] ) );
		if ( ! $temp || ! copy( $entry['_image_path'], $temp ) ) {
			if ( $temp ) {
				wp_delete_file( $temp );
			}
			return new \WP_Error( 'wpcv_copy', 'Cannot stage the thumbnail for upload.' );
		}
		if ( ! hash_equals( $entry['thumbnail']['sha256'], hash_file( 'sha256', $temp ) ) ) {
			wp_delete_file( $temp );
			return new \WP_Error( 'wpcv_copy', 'Thumbnail changed after validation.' );
		}
		$id = media_handle_sideload( array( 'name' => basename( $entry['_image_path'] ), 'tmp_name' => $temp ), $post_id, sanitize_text_field( $entry['title'] ) );
		wp_delete_file( $temp );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_wpcv_import_media_sha256', $entry['thumbnail']['sha256'] );
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $entry['thumbnail']['alt'] ) ) );
		}
		return $id;
	}

	private static function fingerprint( int $post_id ): string {
		$post = get_post( $post_id );
		$values = array( $post->post_title, $post->post_excerpt, $post->post_content, $post->post_author, get_post_thumbnail_id( $post_id ) );
		foreach ( self::META as $key ) {
			$values[] = (string) get_post_meta( $post_id, '_wpcv_' . $key, true );
		}
		$terms = wp_get_object_terms( $post_id, Entries::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return '';
		}
		sort( $terms, SORT_NUMERIC );
		$values[] = $terms;
		return hash( 'sha256', wp_json_encode( $values ) );
	}

	/** Trash only unchanged drafts in the requested batch. Media remains reusable. */
	public static function rollback( string $batch, bool $apply = false ): array|\WP_Error {
		if ( ! self::identifier( $batch ) ) {
			return new \WP_Error( 'wpcv_batch', 'Invalid batch identifier.' );
		}
		if ( $apply && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wpcv_permission', 'Apply requires --user=YOUR_WORDPRESS_ADMIN_LOGIN.' );
		}
		if ( $apply && ! EMPTY_TRASH_DAYS ) {
			return new \WP_Error( 'wpcv_trash', 'Trash is disabled. Rollback refuses permanent deletion.' );
		}
		$token = wp_generate_uuid4();
		if ( $apply && ! add_option( 'wpcv_import_lock', $token, '', false ) ) {
			return new \WP_Error( 'wpcv_locked', 'An import or rollback is running. Wait before rolling back.' );
		}
		try {
			$posts = get_posts( array( 'post_type' => Entries::POST_TYPE, 'post_status' => array_keys( get_post_stati() ), 'posts_per_page' => -1, 'meta_key' => '_wpcv_import_batch', 'meta_value' => $batch ) );
			$result = array( 'batch' => $batch, 'applied' => $apply, 'rows' => array() );
			foreach ( $posts as $post ) {
				$stored = (string) get_post_meta( $post->ID, '_wpcv_import_fingerprint', true );
				$current = self::fingerprint( $post->ID );
				$unchanged = 'draft' === $post->post_status && '' !== $stored && '' !== $current && hash_equals( $stored, $current );
				$action = $unchanged ? 'would_trash' : 'preserved';
				if ( $unchanged && $apply ) {
					if ( ! wp_trash_post( $post->ID ) ) {
						return new \WP_Error( 'wpcv_trash', 'Could not trash entry ' . $post->ID . '.', $result );
					}
					$action = 'trashed';
				}
				$result['rows'][] = array( 'post_id' => $post->ID, 'title' => $post->post_title, 'action' => $action );
			}
			return $result;
		} finally {
			if ( $apply && $token === get_option( 'wpcv_import_lock' ) ) {
				delete_option( 'wpcv_import_lock' );
			}
		}
	}
}
