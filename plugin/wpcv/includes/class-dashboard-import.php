<?php
/**
 * Administrator-only uploads, private staging, preview, and batched draft imports.
 *
 * @package WPCV
 */

namespace WPCV;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Import {

	private const PREFIX = 'wpcv_dashboard_import_';
	private const CHUNK_SIZE = 5;
	private const MAX_EXPANDED = 100 * MB_IN_BYTES;
	private static string $page_hook = '';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_wpcv_import_upload', array( self::class, 'upload' ) );
		add_action( 'admin_post_wpcv_import_cancel', array( self::class, 'cancel' ) );
		add_action( 'wp_ajax_wpcv_import_step', array( self::class, 'ajax_step' ) );
		add_action( 'wp_ajax_wpcv_import_image', array( self::class, 'image' ) );
		add_action( 'wpcv_cleanup_dashboard_import', array( self::class, 'discard' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_batch' ) );
	}

	public static function allowed(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'upload_files' );
	}

	public static function menu(): void {
		self::$page_hook = (string) add_submenu_page( 'edit.php?post_type=' . Entries::POST_TYPE, __( 'Import CV entries', 'wpcv' ), __( 'Import', 'wpcv' ), 'manage_options', 'wpcv-import', array( self::class, 'render' ) );
	}

	public static function assets( string $hook ): void {
		if ( ! self::$page_hook || $hook !== self::$page_hook ) {
			return;
		}
		wp_enqueue_style( 'wpcv-import', WPCV_PLUGIN_URL . 'assets/import.css', array(), WPCV_VERSION );
		wp_enqueue_script( 'wpcv-import', WPCV_PLUGIN_URL . 'assets/import.js', array(), WPCV_VERSION, true );
		wp_localize_script( 'wpcv-import', 'wpcvImport', array(
			'url' => admin_url( 'admin-ajax.php' ),
			'working' => __( 'Importing…', 'wpcv' ),
			'resume' => __( 'Resume import', 'wpcv' ),
			'error' => __( 'The request was interrupted. Reload this page to check progress and resume the import.', 'wpcv' ),
		) );
	}

	private static function url( string $token = '' ): string {
		$url = admin_url( 'edit.php?post_type=' . Entries::POST_TYPE . '&page=wpcv-import' );
		return $token ? add_query_arg( 'import', $token, $url ) : $url;
	}

	public static function upload_limit(): int {
		return min( 25 * MB_IN_BYTES, wp_max_upload_size() );
	}

	private static function valid_token( string $token ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/D', $token );
	}

	/** Staging must be outside every known publicly served WordPress directory. */
	private static function private_root(): string|\WP_Error {
		$root = realpath( defined( 'WPCV_IMPORT_TEMP_DIR' ) ? WPCV_IMPORT_TEMP_DIR : sys_get_temp_dir() );
		if ( ! $root || ! is_dir( $root ) || ! is_writable( $root ) ) {
			return new \WP_Error( 'wpcv_temp', __( 'A writable private temporary directory is required for imports.', 'wpcv' ) );
		}
		foreach ( array( ABSPATH, WP_CONTENT_DIR, $_SERVER['DOCUMENT_ROOT'] ?? '' ) as $public ) {
			$public = $public ? realpath( $public ) : false;
			if ( $public ) {
				$normalized = strtolower( wp_normalize_path( $root ) );
				$public = rtrim( strtolower( wp_normalize_path( $public ) ), '/' );
				if ( $normalized === $public || str_starts_with( $normalized, $public . '/' ) ) {
					return new \WP_Error( 'wpcv_temp', __( 'Import staging must be outside the public website directory. Configure a private temporary directory for PHP.', 'wpcv' ) );
				}
			}
		}
		return $root;
	}

	/** Shared by the HTTP upload handler and isolated integration tests. */
	public static function stage_file( string $file, string $extension, bool $text_only = false ): array|\WP_Error {
		if ( ! self::allowed() ) {
			return new \WP_Error( 'wpcv_permission', __( 'You do not have permission to import CV entries.', 'wpcv' ) );
		}
		if ( ! in_array( $extension, array( 'json', 'zip' ), true ) || ! is_file( $file ) || ! is_readable( $file ) || filesize( $file ) > self::upload_limit() ) {
			return new \WP_Error( 'wpcv_upload', __( 'Choose a JSON or ZIP file within the upload size limit.', 'wpcv' ) );
		}
		$root = self::private_root();
		if ( is_wp_error( $root ) ) {
			return $root;
		}
		$token = bin2hex( random_bytes( 16 ) );
		$directory = $root . '/wpcv-dashboard-' . $token;
		if ( ! mkdir( $directory, 0700 ) ) {
			return new \WP_Error( 'wpcv_temp', __( 'Could not create the private import directory.', 'wpcv' ) );
		}
		$keep = false;
		try {
			if ( 'zip' === $extension ) {
				$manifest = self::extract_zip( $file, $directory );
			} else {
				$manifest = $directory . '/entries.json';
				if ( filesize( $file ) > 10 * MB_IN_BYTES || ! copy( $file, $manifest ) ) {
					return new \WP_Error( 'wpcv_upload', __( 'The JSON file must be smaller than 10 MB and readable.', 'wpcv' ) );
				}
			}
			if ( is_wp_error( $manifest ) ) {
				return $manifest;
			}
			if ( $text_only ) {
				$text = file_get_contents( $manifest );
				$data = json_decode( str_starts_with( $text, "\xEF\xBB\xBF" ) ? substr( $text, 3 ) : $text, true );
				if ( is_array( $data['entries'] ?? null ) ) {
					foreach ( $data['entries'] as &$entry ) {
						if ( is_array( $entry ) ) {
							unset( $entry['thumbnail'] );
						}
					}
					unset( $entry );
					if ( false === file_put_contents( $manifest, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ) {
						return new \WP_Error( 'wpcv_temp', __( 'Could not prepare the text-only import.', 'wpcv' ) );
					}
				}
			}
			$data = Importer::load( $manifest );
			if ( is_wp_error( $data ) ) {
				if ( 'json' === $extension && ! $text_only && 'wpcv_image' === $data->get_error_code() ) {
					return new \WP_Error( 'wpcv_image', __( 'This JSON references thumbnail files. Upload the ZIP package containing those images, or choose the text-only option.', 'wpcv' ) );
				}
				return $data;
			}
			$batch = 'dashboard-' . gmdate( 'Ymd-His' ) . '-' . substr( $token, 0, 8 );
			$preview = Importer::run( $manifest, false, $batch );
			if ( is_wp_error( $preview ) ) {
				return $preview;
			}
			$images = array();
			foreach ( $data['entries'] as $index => $entry ) {
				$preview['rows'][ $index ]['date'] = Entries::format_date_values( $entry );
				$preview['rows'][ $index ]['categories'] = implode( ', ', $entry['categories'] );
				if ( isset( $entry['_image_path'] ) ) {
					$images[ $index ] = array( 'path' => $entry['_image_path'], 'sha256' => $entry['thumbnail']['sha256'], 'alt' => $entry['thumbnail']['alt'] );
				}
			}
			$state = array(
				'token' => $token, 'user_id' => get_current_user_id(), 'manifest' => $manifest,
				'manifest_hash' => hash_file( 'sha256', $manifest ), 'document' => $data['source']['document'],
				'batch' => $batch, 'total' => count( $data['entries'] ), 'cursor' => 0,
				'created' => 0, 'skipped' => 0, 'finished' => false, 'error' => '',
				'rows' => $preview['rows'], 'images' => $images, 'text_only' => $text_only,
				'expires' => time() + HOUR_IN_SECONDS,
			);
			if ( ! set_transient( self::PREFIX . $token, $state, HOUR_IN_SECONDS ) ) {
				return new \WP_Error( 'wpcv_temp', __( 'Could not save the import preview.', 'wpcv' ) );
			}
			wp_schedule_single_event( $state['expires'], 'wpcv_cleanup_dashboard_import', array( $token ) );
			$keep = true;
			return $state;
		} finally {
			if ( ! $keep ) {
				self::discard( $token );
			}
		}
	}

	/** Inspect first, then stream regular files into a new private directory. */
	private static function extract_zip( string $file, string $directory ): string|\WP_Error {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'wpcv_zip', __( 'ZIP imports require the PHP ZIP extension. JSON text-only imports are also available.', 'wpcv' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return new \WP_Error( 'wpcv_zip', __( 'The ZIP file could not be opened.', 'wpcv' ) );
		}
		try {
			if ( $zip->numFiles > 1000 ) {
				return new \WP_Error( 'wpcv_zip', __( 'The ZIP contains too many files (maximum 1,000).', 'wpcv' ) );
			}
			$seen = array();
			$files = array();
			$manifests = array();
			$total = 0;
			for ( $index = 0; $index < $zip->numFiles; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( ! $stat || ! is_int( $stat['size'] ) || $stat['size'] < 0 ) {
					return new \WP_Error( 'wpcv_zip', __( 'The ZIP directory is invalid.', 'wpcv' ) );
				}
				$name = str_replace( '\\', '/', $stat['name'] );
				$parts = explode( '/', rtrim( $name, '/' ) );
				if ( str_starts_with( $name, '/' ) || preg_match( '/[\x00:]/', $name ) || in_array( '..', $parts, true ) || in_array( '.', $parts, true ) || in_array( '', $parts, true ) ) {
					return new \WP_Error( 'wpcv_zip_path', __( 'The ZIP contains an unsafe file path.', 'wpcv' ) );
				}
				foreach ( $parts as $part ) {
					if ( preg_match( '/[. ]$|^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part ) ) {
						return new \WP_Error( 'wpcv_zip_path', __( 'The ZIP contains an unsupported file name.', 'wpcv' ) );
					}
				}
				$key = strtolower( rtrim( $name, '/' ) );
				if ( isset( $seen[ $key ] ) ) {
					return new \WP_Error( 'wpcv_zip_path', __( 'The ZIP contains duplicate file paths.', 'wpcv' ) );
				}
				$seen[ $key ] = true;
				$os = 0;
				$attributes = 0;
				$zip->getExternalAttributesIndex( $index, $os, $attributes );
				$mode = ( $attributes >> 16 ) & 0170000;
				if ( ! in_array( $mode, array( 0, 0100000, 0040000 ), true ) || ! empty( $stat['encryption_method'] ) ) {
					return new \WP_Error( 'wpcv_zip', __( 'Links, special files, and encrypted files are not supported in import ZIPs.', 'wpcv' ) );
				}
				$total += $stat['size'];
				if ( $total > self::MAX_EXPANDED || $stat['size'] > 20 * MB_IN_BYTES ) {
					return new \WP_Error( 'wpcv_zip', __( 'The ZIP expands beyond the import size limits.', 'wpcv' ) );
				}
				if ( str_ends_with( $name, '/' ) || str_starts_with( $name, '__MACOSX/' ) || '.DS_Store' === basename( $name ) ) {
					continue;
				}
				$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				if ( in_array( $extension, array( 'csv', 'html', 'txt', 'md' ), true ) ) {
					continue; // Review documents are never extracted or served.
				}
				if ( ! in_array( $extension, array( 'json', 'png', 'jpg', 'jpeg', 'gif', 'webp' ), true ) ) {
					return new \WP_Error( 'wpcv_zip', __( 'ZIP imports support JSON and raster thumbnails, plus optional review documents.', 'wpcv' ) );
				}
				if ( 'entries.json' === basename( $name ) ) {
					$manifests[] = $name;
					if ( $stat['size'] > 10 * MB_IN_BYTES ) {
						return new \WP_Error( 'wpcv_zip', __( 'The JSON manifest must be smaller than 10 MB.', 'wpcv' ) );
					}
				}
				$files[] = array( 'name' => $name, 'original' => $stat['name'], 'size' => $stat['size'] );
			}
			if ( 1 !== count( $manifests ) ) {
				return new \WP_Error( 'wpcv_zip', __( 'The ZIP must contain exactly one entries.json file and its referenced thumbnails.', 'wpcv' ) );
			}
			foreach ( $files as $item ) {
				$target = $directory . '/' . $item['name'];
				if ( ! wp_mkdir_p( dirname( $target ) ) ) {
					return new \WP_Error( 'wpcv_zip', __( 'Could not create the import package directories.', 'wpcv' ) );
				}
				$input = $zip->getStream( $item['original'] );
				$output = @fopen( $target, 'xb' );
				if ( ! $input || ! $output ) {
					if ( $input ) { fclose( $input ); }
					if ( $output ) { fclose( $output ); }
					return new \WP_Error( 'wpcv_zip', __( 'Could not read an import package file.', 'wpcv' ) );
				}
				$written = stream_copy_to_stream( $input, $output, $item['size'] + 1 );
				fclose( $input );
				fclose( $output );
				if ( $written !== $item['size'] ) {
					return new \WP_Error( 'wpcv_zip', __( 'A ZIP entry does not match its declared size.', 'wpcv' ) );
				}
			}
			return $directory . '/' . $manifests[0];
		} finally {
			$zip->close();
		}
	}

	public static function state( string $token ): array|\WP_Error {
		if ( ! self::allowed() || ! self::valid_token( $token ) ) {
			return new \WP_Error( 'wpcv_permission', __( 'You do not have access to this import preview.', 'wpcv' ) );
		}
		$state = get_transient( self::PREFIX . $token );
		if ( ! is_array( $state ) || $state['user_id'] !== get_current_user_id() || $state['expires'] <= time() ) {
			return new \WP_Error( 'wpcv_expired', __( 'This preview has expired or belongs to another user. Upload the package again; existing entries will be skipped.', 'wpcv' ) );
		}
		// Keep previews staged by older plugin versions consistent after an upgrade.
		foreach ( $state['rows'] as &$row ) {
			$row['notes'] = implode( ' | ', Entries::review_notes( explode( ' | ', $row['notes'] ) ) );
			$label = preg_replace( '/\s*\(as of \d{4}\)$/', '', $row['date'], -1, $removed );
			$row['date'] = $removed && preg_match( '/[-–—]\s*$/u', $label ) ? $label . __( 'present', 'wpcv' ) : $label;
		}
		unset( $row );
		return $state;
	}

	/** Process one chunk, with a session lock around the shared engine and cursor. */
	public static function advance( string $token, int $expected_cursor ): array|\WP_Error {
		$state = self::state( $token );
		if ( is_wp_error( $state ) ) { return $state; }
		$lock = self::PREFIX . 'lock_' . $token;
		$owner = wp_generate_uuid4();
		if ( ! add_option( $lock, $owner, '', false ) ) {
			return new \WP_Error( 'wpcv_busy', __( 'This import is already processing a batch. Wait a moment and reload to see its progress.', 'wpcv' ) );
		}
		try {
			$state = self::state( $token );
			if ( is_wp_error( $state ) || $state['finished'] || $expected_cursor !== $state['cursor'] ) {
				return $state;
			}
			if ( ! is_file( $state['manifest'] ) || ! hash_equals( $state['manifest_hash'], hash_file( 'sha256', $state['manifest'] ) ) ) {
				return new \WP_Error( 'wpcv_changed', __( 'The staged package changed or is no longer available. Upload it again to preview the current file.', 'wpcv' ) );
			}
			$result = Importer::run( $state['manifest'], true, $state['batch'], $state['cursor'], self::CHUNK_SIZE );
			$partial = is_wp_error( $result ) ? $result->get_error_data() : $result;
			if ( is_array( $partial ) ) {
				foreach ( $partial['rows'] as $row ) {
					$state['rows'][ $state['cursor'] ] = array_replace( $state['rows'][ $state['cursor'] ], $row );
					++$state['cursor'];
				}
				$state['created'] += $partial['created'];
				$state['skipped'] += $partial['skipped'];
			}
			$state['error'] = is_wp_error( $result ) ? $result->get_error_message() : '';
			if ( is_wp_error( $result ) && 'wpcv_locked' === $result->get_error_code() ) {
				$state['error'] = __( 'Another import is running. Wait a moment and try again.', 'wpcv' );
			}
			$state['finished'] = $state['cursor'] >= $state['total'];
			if ( $state['finished'] ) {
				self::remove_files( $token );
				$state['images'] = array();
			}
			set_transient( self::PREFIX . $token, $state, max( 1, $state['expires'] - time() ) );
			return is_wp_error( $result ) ? new \WP_Error( $result->get_error_code(), $state['error'], self::progress( $state ) ) : $state;
		} finally {
			if ( $owner === get_option( $lock ) ) { delete_option( $lock ); }
		}
	}

	private static function progress( array $state ): array {
		return array_intersect_key( $state, array_flip( array( 'cursor', 'total', 'created', 'skipped', 'finished' ) ) );
	}

	public static function ajax_step(): void {
		if ( ! self::allowed() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import CV entries.', 'wpcv' ) ), 403 );
		}
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		check_ajax_referer( 'wpcv_import_' . $token, 'nonce' );
		$cursor = isset( $_POST['cursor'] ) && is_scalar( $_POST['cursor'] ) ? filter_var( $_POST['cursor'], FILTER_VALIDATE_INT ) : false;
		if ( false === $cursor || $cursor < 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import progress.', 'wpcv' ) ), 400 );
		}
		$result = self::advance( $token, $cursor );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message(), 'progress' => $result->get_error_data() ), 'wpcv_busy' === $result->get_error_code() ? 409 : 400 );
		}
		wp_send_json_success( self::progress( $result ) );
	}

	public static function upload(): void {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'You do not have permission to import CV entries.', 'wpcv' ), '', array( 'response' => 403 ) ); }
		$post_limit = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		if ( $post_limit > 0 && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > $post_limit ) {
			self::upload_error( __( 'The upload exceeds the server size limit. Choose a smaller file or increase the PHP upload limits.', 'wpcv' ) );
		}
		check_admin_referer( 'wpcv_import_upload', 'wpcv_nonce' );
		$file = $_FILES['wpcv_file'] ?? null;
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['error'] ) || ! is_string( $file['tmp_name'] ) || ! is_string( $file['name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::upload_error( __( 'The file upload failed. Choose a JSON or ZIP file within the displayed upload limit.', 'wpcv' ) );
		}
		$extension = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
		$state = self::stage_file( $file['tmp_name'], $extension, isset( $_POST['text_only'] ) );
		if ( is_wp_error( $state ) ) { self::upload_error( $state->get_error_message() ); }
		wp_safe_redirect( self::url( $state['token'] ) );
		exit;
	}

	private static function upload_error( string $message ): void {
		set_transient( self::PREFIX . 'error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function cancel(): void {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'You do not have permission to manage this import.', 'wpcv' ), '', array( 'response' => 403 ) ); }
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		check_admin_referer( 'wpcv_import_cancel_' . $token, 'wpcv_nonce' );
		$state = self::state( $token );
		if ( ! is_wp_error( $state ) ) { self::discard( $token ); }
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function image(): void {
		$token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? wp_unslash( $_GET['token'] ) : '';
		check_ajax_referer( 'wpcv_import_' . $token, 'nonce' );
		$state = self::state( $token );
		$index = isset( $_GET['index'] ) && is_scalar( $_GET['index'] ) ? filter_var( $_GET['index'], FILTER_VALIDATE_INT ) : false;
		if ( is_wp_error( $state ) || false === $index || ! isset( $state['images'][ $index ] ) ) { wp_die( '', '', array( 'response' => 403 ) ); }
		$image = $state['images'][ $index ];
		$path = realpath( $image['path'] );
		$directory = realpath( dirname( $state['manifest'] ) );
		if ( ! $path || ! $directory || ! str_starts_with( wp_normalize_path( $path ), wp_normalize_path( $directory ) . '/' ) || ! hash_equals( $image['sha256'], hash_file( 'sha256', $path ) ) ) { wp_die( '', '', array( 'response' => 404 ) ); }
		$size = wp_getimagesize( $path );
		if ( ! $size || ! in_array( $size['mime'], array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) { wp_die( '', '', array( 'response' => 404 ) ); }
		nocache_headers();
		header( 'Content-Type: ' . $size['mime'] );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path );
		exit;
	}

	/** Cleanup only an exact generated child directory; never follow links. */
	private static function remove_files( string $token ): void {
		$root = self::private_root();
		if ( ! self::valid_token( $token ) || is_wp_error( $root ) ) { return; }
		$directory = $root . '/wpcv-dashboard-' . $token;
		if ( ! is_dir( $directory ) || is_link( $directory ) || realpath( dirname( $directory ) ) !== realpath( $root ) ) { return; }
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $path ) {
			if ( $path->isDir() && ! $path->isLink() ) { @rmdir( $path->getPathname() ); }
			else { @unlink( $path->getPathname() ); }
		}
		@rmdir( $directory );
	}

	public static function discard( string $token ): void {
		if ( ! self::valid_token( $token ) ) { return; }
		if ( get_option( self::PREFIX . 'lock_' . $token ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'wpcv_cleanup_dashboard_import', array( $token ) );
			return;
		}
		self::remove_files( $token );
		delete_transient( self::PREFIX . $token );
		wp_clear_scheduled_hook( 'wpcv_cleanup_dashboard_import', array( $token ) );
	}

	public static function filter_batch( \WP_Query $query ): void {
		$batch = isset( $_GET['wpcv_import_batch'] ) && is_string( $_GET['wpcv_import_batch'] ) ? wp_unslash( $_GET['wpcv_import_batch'] ) : '';
		if ( is_admin() && $query->is_main_query() && Entries::POST_TYPE === $query->get( 'post_type' ) && preg_match( '/^[a-z0-9][a-z0-9_-]{0,99}$/D', $batch ) ) {
			$meta = $query->get( 'meta_query' ) ?: array();
			$query->set( 'meta_query', array( 'relation' => 'AND', $meta, array( 'key' => '_wpcv_import_batch', 'value' => $batch ) ) );
		}
	}

	public static function render(): void {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'You do not have permission to import CV entries.', 'wpcv' ), '', array( 'response' => 403 ) ); }
		$token = isset( $_GET['import'] ) && is_string( $_GET['import'] ) ? wp_unslash( $_GET['import'] ) : '';
		$state = $token ? self::state( $token ) : null;
		$error = get_transient( self::PREFIX . 'error_' . get_current_user_id() );
		delete_transient( self::PREFIX . 'error_' . get_current_user_id() );
		?>
		<div class="wrap wpcv-import">
			<h1><?php esc_html_e( 'Import CV entries', 'wpcv' ); ?></h1>
			<p class="wpcv-import__intro"><?php esc_html_e( 'Upload a reviewed CV file, preview the entries, then create drafts to check before publishing.', 'wpcv' ); ?></p>
			<?php if ( $error || is_wp_error( $state ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ?: $state->get_error_message() ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_array( $state ) ) : ?>
				<?php self::render_preview( $state ); ?>
			<?php else : ?>
				<div class="wpcv-import__upload">
					<h2><?php esc_html_e( '1. Choose your file', 'wpcv' ); ?></h2>
					<p><?php esc_html_e( 'Use a ZIP package containing entries.json and its thumbnails to import text and images together. JSON alone works for entries without local thumbnail files.', 'wpcv' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( add_query_arg( 'action', 'wpcv_import_upload', admin_url( 'admin-post.php' ) ) ); ?>">
						<?php wp_nonce_field( 'wpcv_import_upload', 'wpcv_nonce' ); ?>
						<input type="hidden" name="MAX_FILE_SIZE" value="<?php echo esc_attr( self::upload_limit() ); ?>">
						<label for="wpcv-file"><strong><?php esc_html_e( 'CV file (.json or .zip)', 'wpcv' ); ?></strong></label>
						<p><input id="wpcv-file" name="wpcv_file" type="file" accept=".json,.zip,application/json,application/zip" required></p>
						<p class="description"><?php printf( esc_html__( 'Maximum upload: %s.', 'wpcv' ), esc_html( size_format( self::upload_limit() ) ) ); ?></p>
						<p><label><input type="checkbox" name="text_only" value="1"> <?php esc_html_e( 'Import text only and ignore thumbnail references', 'wpcv' ); ?></label></p>
						<p class="description"><?php esc_html_e( 'Choose text only when uploading JSON without its image files. You can add thumbnails to the drafts later.', 'wpcv' ); ?></p>
						<?php submit_button( __( 'Upload and preview', 'wpcv' ) ); ?>
					</form>
				</div>
				<p><?php esc_html_e( 'Existing imported entries are kept. Uploading the same package again skips matching entries and preserves your edits.', 'wpcv' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_preview( array $state ): void {
		$nonce = wp_create_nonce( 'wpcv_import_' . $state['token'] );
		$flagged = count( array_filter( $state['rows'], static fn ( array $row ): bool => '' !== $row['notes'] ) );
		$new = count( array_filter( $state['rows'], static fn ( array $row ): bool => 'would_create' === $row['action'] ) );
		$drafts_url = admin_url( 'edit.php?post_type=' . Entries::POST_TYPE );
		if ( $state['created'] ) { $drafts_url = add_query_arg( array( 'wpcv_import_batch' => $state['batch'], 'post_status' => 'draft' ), $drafts_url ); }
		?>
		<div data-wpcv-import data-token="<?php echo esc_attr( $state['token'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-cursor="<?php echo esc_attr( $state['cursor'] ); ?>">
			<h2><?php echo $state['finished'] ? esc_html__( 'Import complete', 'wpcv' ) : esc_html__( '2. Review your entries', 'wpcv' ); ?></h2>
			<p><strong><?php echo esc_html( $state['document'] ); ?></strong></p>
			<div class="wpcv-import__stats">
				<p><strong><?php echo esc_html( $state['total'] ); ?></strong> <?php esc_html_e( 'entries in file', 'wpcv' ); ?></p>
				<p><strong data-created><?php echo esc_html( $state['created'] ); ?></strong> <?php esc_html_e( 'drafts created', 'wpcv' ); ?></p>
				<p><strong data-skipped><?php echo esc_html( $state['skipped'] ); ?></strong> <?php esc_html_e( 'existing entries skipped', 'wpcv' ); ?></p>
				<p><strong><?php echo esc_html( $flagged ); ?></strong> <?php esc_html_e( 'entries with review notes', 'wpcv' ); ?></p>
			</div>
			<?php if ( $state['finished'] ) : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Your import is complete. New entries are drafts; review them in CV Entries before publishing.', 'wpcv' ); ?></p></div>
				<p><a class="button button-primary" href="<?php echo esc_url( $drafts_url ); ?>"><?php esc_html_e( 'Review CV entries', 'wpcv' ); ?></a> <a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Import another file', 'wpcv' ); ?></a></p>
			<?php else : ?>
				<p><?php printf( esc_html__( '%d new entries are ready to import as drafts. Existing entries will be skipped.', 'wpcv' ), (int) $new ); ?></p>
				<?php if ( $state['text_only'] ) : ?><p><?php esc_html_e( 'Text-only import: thumbnails will not be imported.', 'wpcv' ); ?></p><?php endif; ?>
				<p class="description"><?php esc_html_e( 'This preview is available for one hour. If interrupted, reload this page to resume; completed drafts are kept.', 'wpcv' ); ?></p>
				<div class="notice notice-error inline" data-import-error role="alert" <?php echo $state['error'] ? '' : 'hidden'; ?>><p><?php echo esc_html( $state['error'] ); ?></p></div>
				<div class="wpcv-import__progress" data-import-progress <?php echo $state['cursor'] ? '' : 'hidden'; ?>>
					<label for="wpcv-progress"><?php esc_html_e( 'Entries processed', 'wpcv' ); ?></label>
					<progress id="wpcv-progress" max="<?php echo esc_attr( $state['total'] ); ?>" value="<?php echo esc_attr( $state['cursor'] ); ?>"></progress>
					<p role="status" aria-live="polite"><span data-progress-count><?php echo esc_html( $state['cursor'] ); ?></span> / <?php echo esc_html( $state['total'] ); ?></p>
				</div>
				<p><button type="button" class="button button-primary" data-import-start><?php echo $state['cursor'] || $state['error'] ? esc_html__( 'Resume import', 'wpcv' ) : esc_html__( 'Import as drafts', 'wpcv' ); ?></button></p>
				<noscript><p><?php esc_html_e( 'Enable JavaScript to run this import in small batches.', 'wpcv' ); ?></p></noscript>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-import-cancel>
					<input type="hidden" name="action" value="wpcv_import_cancel"><input type="hidden" name="token" value="<?php echo esc_attr( $state['token'] ); ?>">
					<?php wp_nonce_field( 'wpcv_import_cancel_' . $state['token'], 'wpcv_nonce' ); ?>
					<button class="button" type="submit"><?php esc_html_e( 'Discard uploaded file', 'wpcv' ); ?></button>
				</form>
			<?php endif; ?>
			<div class="wpcv-import__table-wrap">
				<table class="widefat striped wpcv-import__table">
					<caption class="screen-reader-text"><?php esc_html_e( 'CV entry import preview and results', 'wpcv' ); ?></caption>
					<thead><tr><th><?php esc_html_e( 'Image', 'wpcv' ); ?></th><th><?php esc_html_e( 'Entry', 'wpcv' ); ?></th><th><?php esc_html_e( 'Date', 'wpcv' ); ?></th><th><?php esc_html_e( 'Categories', 'wpcv' ); ?></th><th><?php esc_html_e( 'Action', 'wpcv' ); ?></th><th><?php esc_html_e( 'Review notes', 'wpcv' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $state['rows'] as $index => $row ) : ?>
						<tr>
							<td><?php if ( isset( $state['images'][ $index ] ) ) : ?>
								<img width="56" height="56" loading="lazy" src="<?php echo esc_url( add_query_arg( array( 'action' => 'wpcv_import_image', 'token' => $state['token'], 'index' => $index, 'nonce' => $nonce ), admin_url( 'admin-ajax.php' ) ) ); ?>" alt="<?php echo esc_attr( $state['images'][ $index ]['alt'] ); ?>">
							<?php elseif ( $row['post_id'] ) : ?><?php echo get_the_post_thumbnail( $row['post_id'], 'thumbnail', array( 'width' => 56, 'height' => 56 ) ); ?><?php endif; ?></td>
							<td><strong><?php if ( $row['post_id'] && current_user_can( 'edit_post', $row['post_id'] ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'], 'raw' ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a><?php else : ?><?php echo esc_html( $row['title'] ); ?><?php endif; ?></strong></td>
							<td><?php echo esc_html( $row['date'] ); ?></td><td><?php echo esc_html( $row['categories'] ); ?></td>
							<td><?php echo 'created' === $row['action'] ? esc_html__( 'Draft created', 'wpcv' ) : ( 'skipped' === $row['action'] ? esc_html__( 'Already imported', 'wpcv' ) : esc_html__( 'New draft', 'wpcv' ) ); ?></td>
							<td><?php if ( $row['notes'] ) : ?><span class="wpcv-import__note"><?php echo esc_html( $row['notes'] ); ?></span><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}
}
