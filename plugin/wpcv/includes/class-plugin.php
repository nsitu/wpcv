<?php
/**
 * Plugin lifecycle and application hooks.
 *
 * @package WPCV
 */

namespace WPCV;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		Entries::boot();
		Shortcode::boot();
		Dashboard_Import::boot();
		add_action( 'init', array( self::class, 'upgrade' ), 20 );
		add_action( 'admin_menu', array( self::class, 'register_admin_page' ) );
	}

	public static function activate(): void {
		Entries::register();
		self::upgrade();
	}

	public static function upgrade(): void {
		// Run on active-plugin upgrades as well as first activation. Seed once so
		// categories that administrators later delete or rename stay that way.
		if ( '1' === get_option( 'wpcv_schema_version' ) ) {
			return;
		}
		$categories = array( 'Exhibition', 'Performance', 'Publication', 'Degree', 'Teaching', 'Professional' );
		foreach ( $categories as $name ) {
			if ( ! term_exists( $name, Entries::TAXONOMY ) ) {
				$result = wp_insert_term( $name, Entries::TAXONOMY );
				if ( is_wp_error( $result ) ) {
					return;
				}
			}
		}
		update_option( 'wpcv_schema_version', '1', false );
	}

	public static function deactivate(): void {
		// Unschedule plugin jobs here when added. Preserve user data.
	}

	public static function register_admin_page(): void {
		add_management_page(
			__( 'WPCV', 'wpcv' ),
			__( 'WPCV', 'wpcv' ),
			'manage_options',
			'wpcv',
			array( self::class, 'render_admin_page' )
		);
	}

	public static function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wpcv' ) );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPCV', 'wpcv' ); ?></h1>
			<p><?php esc_html_e( 'Create CV Entries, assign CV Categories, and place [wpcv] on a page to display your CV.', 'wpcv' ); ?></p>
			<p><?php esc_html_e( 'Entries appear newest first by their CV year and month. Use [wpcv order="ASC"] for oldest first. Entries without a year appear last.', 'wpcv' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Entries::POST_TYPE ) ); ?>"><?php esc_html_e( 'Manage CV entries', 'wpcv' ); ?></a></p>
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Entries::POST_TYPE . '&page=wpcv-import' ) ); ?>"><?php esc_html_e( 'Import CV entries from JSON or ZIP', 'wpcv' ); ?></a></p>
			<p>
				<?php
				printf(
					/* translators: %s: plugin version. */
					esc_html__( 'Version: %s', 'wpcv' ),
					esc_html( WPCV_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}
}
