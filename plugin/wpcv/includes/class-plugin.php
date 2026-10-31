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
		add_action( 'admin_menu', array( self::class, 'register_admin_page' ) );
		// Register additional hooks and services here.
	}

	public static function activate(): void {
		// Add one-time setup here. Keep activation safe to run repeatedly.
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
			<p><?php esc_html_e( 'WPCV is active and ready for custom functionality.', 'wpcv' ); ?></p>
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
