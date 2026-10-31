<?php
/**
 * WP-CLI interface for explicit CV content imports.
 *
 * @package WPCV
 */

namespace WPCV;

defined( 'ABSPATH' ) || exit;

final class CLI {

	/**
	 * Preview a reviewed JSON package, or create its entries as drafts.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to entries.json; images are resolved relative to this file.
	 *
	 * [--apply]
	 * : Create drafts. Requires the global --user option for a WP administrator.
	 *
	 * [--dry-run]
	 * : Explicit preview; this is also the default.
	 *
	 * [--batch=<id>]
	 * : Identifier recorded on newly created drafts. Generated if omitted.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 */
	public function import( array $args, array $assoc_args ): void {
		self::output( Importer::run( $args[0], self::apply( $assoc_args ), $assoc_args['batch'] ?? '' ), $assoc_args );
	}

	/**
	 * Preview rollback, or move unchanged imported drafts to Trash.
	 *
	 * Published or edited entries and all media are preserved.
	 *
	 * ## OPTIONS
	 *
	 * <batch>
	 * : Batch identifier printed by the import command.
	 *
	 * [--apply]
	 * : Trash unchanged drafts. Requires --user for a WordPress administrator.
	 *
	 * [--dry-run]
	 * : Explicit preview; this is also the default.
	 *
	 * [--format=<format>]
	 * : Output format: table or json. Default: table.
	 */
	public function rollback( array $args, array $assoc_args ): void {
		self::output( Importer::rollback( $args[0], self::apply( $assoc_args ) ), $assoc_args );
	}

	private static function apply( array $args ): bool {
		foreach ( array( 'apply', 'dry-run' ) as $flag ) {
			if ( isset( $args[ $flag ] ) && true !== $args[ $flag ] ) {
				\WP_CLI::error( '--' . $flag . ' is a flag and does not take a value.' );
			}
		}
		if ( isset( $args['apply'], $args['dry-run'] ) ) {
			\WP_CLI::error( 'Use either --apply or --dry-run.' );
		}
		if ( ! in_array( $args['format'] ?? 'table', array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( 'Format must be table or json.' );
		}
		return isset( $args['apply'] );
	}

	private static function output( array|\WP_Error $result, array $args ): void {
		if ( is_wp_error( $result ) ) {
			$partial = $result->get_error_data();
			if ( is_array( $partial ) && isset( $partial['batch'] ) ) {
				\WP_CLI::warning( 'Batch: ' . $partial['batch'] . '; completed drafts: ' . ( $partial['created'] ?? count( $partial['rows'] ?? array() ) ) );
			}
			\WP_CLI::error( $result->get_error_message() );
		}
		if ( 'json' === ( $args['format'] ?? 'table' ) ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $result['rows'], isset( $result['created'] ) ? array( 'source_id', 'title', 'action', 'post_id', 'notes' ) : array( 'post_id', 'title', 'action' ) );
		\WP_CLI::success( ( $result['applied'] ? 'Applied' : 'Preview only; no changes' ) . '. Batch: ' . $result['batch'] );
	}
}
