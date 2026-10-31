<?php
/**
 * CV content model and entry editor.
 *
 * @package WPCV
 */

namespace WPCV;

defined( 'ABSPATH' ) || exit;

final class Entries {

	public const POST_TYPE = 'wpcv_entry';
	public const TAXONOMY = 'wpcv_category';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'after_setup_theme', array( self::class, 'support_thumbnails' ), 20 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( self::class, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( self::class, 'save' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'column_value' ), 10, 2 );
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'CV Entries', 'wpcv' ),
					'singular_name'      => __( 'CV Entry', 'wpcv' ),
					'add_new_item'       => __( 'Add CV Entry', 'wpcv' ),
					'edit_item'          => __( 'Edit CV Entry', 'wpcv' ),
					'new_item'           => __( 'New CV Entry', 'wpcv' ),
					'search_items'       => __( 'Search CV Entries', 'wpcv' ),
					'not_found'          => __( 'No CV entries found.', 'wpcv' ),
					'featured_image'     => __( 'Entry thumbnail', 'wpcv' ),
					'set_featured_image' => __( 'Set entry thumbnail', 'wpcv' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'menu_icon'           => 'dashicons-id-alt',
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				'taxonomies'          => array( self::TAXONOMY ),
				'rewrite'             => false,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'CV Categories', 'wpcv' ),
					'singular_name' => __( 'CV Category', 'wpcv' ),
					'add_new_item'  => __( 'Add CV Category', 'wpcv' ),
					'edit_item'     => __( 'Edit CV Category', 'wpcv' ),
					'search_items'  => __( 'Search CV Categories', 'wpcv' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => true,
				'rewrite'           => false,
			)
		);

		foreach ( self::fields() as $key => $field ) {
			$args = array(
				'type'              => $field['type'],
				'single'            => true,
				'revisions_enabled' => true,
				'default'           => 'integer' === $field['type'] ? 0 : '',
				'sanitize_callback' => $field['sanitize'],
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ): bool {
					return current_user_can( 'edit_post', (int) $post_id );
				},
				'show_in_rest'      => true,
			);
			if ( 'integer' === $field['type'] ) {
				$args['show_in_rest'] = array(
					'schema' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => $field['max'] ),
				);
			}
			register_post_meta( self::POST_TYPE, $key, $args );
		}
	}

	private static function fields(): array {
		return array(
			'_wpcv_context'  => array( 'type' => 'string', 'sanitize' => 'sanitize_textarea_field' ),
			'_wpcv_month'    => array( 'type' => 'integer', 'sanitize' => array( self::class, 'sanitize_month' ), 'max' => 12 ),
			'_wpcv_year'     => array( 'type' => 'integer', 'sanitize' => array( self::class, 'sanitize_year' ), 'max' => 9999 ),
			'_wpcv_end_month' => array( 'type' => 'integer', 'sanitize' => array( self::class, 'sanitize_month' ), 'max' => 12 ),
			'_wpcv_end_year' => array( 'type' => 'integer', 'sanitize' => array( self::class, 'sanitize_year' ), 'max' => 9999 ),
			'_wpcv_date_label' => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
			'_wpcv_ongoing_as_of' => array( 'type' => 'integer', 'sanitize' => array( self::class, 'sanitize_year' ), 'max' => 9999 ),
			'_wpcv_url'      => array( 'type' => 'string', 'sanitize' => array( self::class, 'sanitize_url' ) ),
			'_wpcv_location' => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
		);
	}

	public static function sanitize_month( $value ): int {
		$value = filter_var( $value, FILTER_VALIDATE_INT );
		return false !== $value && $value >= 1 && $value <= 12 ? $value : 0;
	}

	public static function sanitize_year( $value ): int {
		$value = filter_var( $value, FILTER_VALIDATE_INT );
		return false !== $value && $value >= 1 && $value <= 9999 ? $value : 0;
	}

	public static function sanitize_url( $value ): string {
		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	public static function support_thumbnails(): void {
		if ( current_theme_supports( 'post-thumbnails', self::POST_TYPE ) ) {
			return;
		}
		$support = get_theme_support( 'post-thumbnails' );
		$types   = is_array( $support ) && isset( $support[0] ) && is_array( $support[0] ) ? $support[0] : array();
		$types[] = self::POST_TYPE;
		add_theme_support( 'post-thumbnails', array_unique( $types ) );
	}

	public static function add_meta_box(): void {
		add_meta_box( 'wpcv-details', __( 'CV entry details', 'wpcv' ), array( self::class, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function render_meta_box( \WP_Post $post ): void {
		global $wp_locale;
		wp_nonce_field( 'wpcv_save_entry', 'wpcv_nonce' );
		$month = (int) get_post_meta( $post->ID, '_wpcv_month', true );
		$year  = (int) get_post_meta( $post->ID, '_wpcv_year', true );
		?>
		<p><?php esc_html_e( 'Use the title for the entry title, Excerpt for its short description, the main editor for its long description, and Entry thumbnail for its image.', 'wpcv' ); ?></p>
		<p>
			<label for="wpcv-context"><strong><?php esc_html_e( 'Entry context', 'wpcv' ); ?></strong></label>
			<textarea id="wpcv-context" name="_wpcv_context" rows="2" class="widefat"><?php echo esc_textarea( get_post_meta( $post->ID, '_wpcv_context', true ) ); ?></textarea>
			<span class="description"><?php esc_html_e( 'For example: venue, organization, role, or publication.', 'wpcv' ); ?></span>
		</p>
		<p>
			<label for="wpcv-month"><strong><?php esc_html_e( 'Month', 'wpcv' ); ?></strong></label>
			<select id="wpcv-month" name="_wpcv_month">
				<option value="0"><?php esc_html_e( 'Not specified', 'wpcv' ); ?></option>
				<?php for ( $number = 1; $number <= 12; $number++ ) : ?>
					<option value="<?php echo esc_attr( $number ); ?>" <?php selected( $month, $number ); ?>><?php echo esc_html( $wp_locale->get_month( $number ) ); ?></option>
				<?php endfor; ?>
			</select>
			<label for="wpcv-year"><strong><?php esc_html_e( 'Year', 'wpcv' ); ?></strong></label>
			<input id="wpcv-year" name="_wpcv_year" type="number" min="1" max="9999" step="1" value="<?php echo $year ? esc_attr( $year ) : ''; ?>" style="width:7em">
		</p>
		<p class="description"><?php esc_html_e( 'Start date, independent of the publish date. Leave the month blank for year-only dates. Undated entries appear last.', 'wpcv' ); ?></p>
		<p>
			<label for="wpcv-end-month"><strong><?php esc_html_e( 'End month (optional)', 'wpcv' ); ?></strong></label>
			<select id="wpcv-end-month" name="_wpcv_end_month">
				<option value="0"><?php esc_html_e( 'Not specified', 'wpcv' ); ?></option>
				<?php for ( $number = 1; $number <= 12; $number++ ) : ?>
					<option value="<?php echo esc_attr( $number ); ?>" <?php selected( (int) get_post_meta( $post->ID, '_wpcv_end_month', true ), $number ); ?>><?php echo esc_html( $wp_locale->get_month( $number ) ); ?></option>
				<?php endfor; ?>
			</select>
			<label for="wpcv-end-year"><strong><?php esc_html_e( 'End year (optional)', 'wpcv' ); ?></strong></label>
			<input id="wpcv-end-year" name="_wpcv_end_year" type="number" min="1" max="9999" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpcv_end_year', true ) ?: '' ); ?>" style="width:7em">
		</p>
		<p>
			<label for="wpcv-date-label"><strong><?php esc_html_e( 'Date display override (optional)', 'wpcv' ); ?></strong></label>
			<input id="wpcv-date-label" name="_wpcv_date_label" type="text" class="widefat" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpcv_date_label', true ) ); ?>">
			<span class="description"><?php esc_html_e( 'Preserve a source label or multiple occurrences, such as Jan 2018, Oct 2017. Sorting uses the numeric dates; for separate occurrences enter the latest occurrence as the start date.', 'wpcv' ); ?></span>
		</p>
		<p>
			<label for="wpcv-ongoing-as-of"><strong><?php esc_html_e( 'Ongoing as of year (optional)', 'wpcv' ); ?></strong></label>
			<input id="wpcv-ongoing-as-of" name="_wpcv_ongoing_as_of" type="number" min="1" max="9999" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpcv_ongoing_as_of', true ) ?: '' ); ?>" style="width:7em">
			<span class="description"><?php esc_html_e( 'Use for an open-ended date in a historical CV. The display explicitly says as of this year; it does not claim the role continues today. Ranges sort by their latest dated endpoint.', 'wpcv' ); ?></span>
		</p>
		<p>
			<label for="wpcv-url"><strong><?php esc_html_e( 'URL link', 'wpcv' ); ?></strong></label>
			<input id="wpcv-url" name="_wpcv_url" type="url" class="widefat" placeholder="https://" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpcv_url', true ) ); ?>">
		</p>
		<p>
			<label for="wpcv-location"><strong><?php esc_html_e( 'Location', 'wpcv' ); ?></strong></label>
			<input id="wpcv-location" name="_wpcv_location" type="text" class="widefat" value="<?php echo esc_attr( get_post_meta( $post->ID, '_wpcv_location', true ) ); ?>">
		</p>
		<?php if ( get_post_meta( $post->ID, '_wpcv_import_source_id', true ) ) : ?>
			<hr>
			<p><strong><?php esc_html_e( 'Import source', 'wpcv' ); ?></strong>: <?php echo esc_html( get_post_meta( $post->ID, '_wpcv_import_source_id', true ) ); ?></p>
			<p><?php echo esc_html( get_post_meta( $post->ID, '_wpcv_import_source_document', true ) ); ?> · <?php esc_html_e( 'Page', 'wpcv' ); ?> <?php echo esc_html( get_post_meta( $post->ID, '_wpcv_import_source_page', true ) ); ?></p>
			<p><?php esc_html_e( 'Original date:', 'wpcv' ); ?> <?php echo esc_html( get_post_meta( $post->ID, '_wpcv_import_source_date_text', true ) ); ?></p>
			<?php foreach ( (array) get_post_meta( $post->ID, '_wpcv_import_review_notes', true ) as $note ) : ?>
				<p class="description"><?php echo esc_html( $note ); ?></p>
			<?php endforeach; ?>
			<details><summary><?php esc_html_e( 'Extracted source text', 'wpcv' ); ?></summary><pre style="white-space:pre-wrap"><?php echo esc_html( get_post_meta( $post->ID, '_wpcv_import_source_text', true ) ); ?></pre></details>
		<?php endif; ?>
		<?php
	}

	public static function save( int $post_id ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['wpcv_nonce'] ) || ! is_string( $_POST['wpcv_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['wpcv_nonce'] ), 'wpcv_save_entry' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::fields() as $key => $field ) {
			if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
				$value = call_user_func( $field['sanitize'], wp_unslash( $_POST[ $key ] ) );
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}
	}

	public static function date_value( int $post_id ): int {
		$year = self::sanitize_year( get_post_meta( $post_id, '_wpcv_year', true ) );
		if ( ! $year ) {
			return 0;
		}
		$start = $year * 100 + self::sanitize_month( get_post_meta( $post_id, '_wpcv_month', true ) );
		$end_year = self::sanitize_year( get_post_meta( $post_id, '_wpcv_end_year', true ) );
		$end = $end_year ? $end_year * 100 + self::sanitize_month( get_post_meta( $post_id, '_wpcv_end_month', true ) ) : 0;
		$as_of = self::sanitize_year( get_post_meta( $post_id, '_wpcv_ongoing_as_of', true ) );
		return max( $start, $end, $as_of * 100 );
	}

	public static function date_label( int $post_id ): string {
		$year  = self::sanitize_year( get_post_meta( $post_id, '_wpcv_year', true ) );
		$month = self::sanitize_month( get_post_meta( $post_id, '_wpcv_month', true ) );
		$label = (string) get_post_meta( $post_id, '_wpcv_date_label', true );
		$as_of = self::sanitize_year( get_post_meta( $post_id, '_wpcv_ongoing_as_of', true ) );
		if ( '' === $label ) {
			$label = self::format_date( $year, $month );
			$end_year = self::sanitize_year( get_post_meta( $post_id, '_wpcv_end_year', true ) );
			$end_month = self::sanitize_month( get_post_meta( $post_id, '_wpcv_end_month', true ) );
			if ( $year && $end_year && $end_year * 100 + $end_month >= $year * 100 + $month ) {
				$label .= '–' . self::format_date( $end_year, $end_month );
			} elseif ( $year && $as_of >= $year ) {
				$label .= '–' . __( 'present', 'wpcv' );
			}
		}
		if ( $as_of ) {
			/* translators: 1: date label, 2: year of the source document. */
			$label = sprintf( __( '%1$s (as of %2$d)', 'wpcv' ), $label, $as_of );
		}
		return $label;
	}

	private static function format_date( int $year, int $month ): string {
		global $wp_locale;
		return $year ? ( $month ? $wp_locale->get_month( $month ) . ' ' . $year : (string) $year ) : __( 'Undated', 'wpcv' );
	}

	public static function columns( array $columns ): array {
		$columns['wpcv_date'] = __( 'CV date', 'wpcv' );
		return $columns;
	}

	public static function column_value( string $column, int $post_id ): void {
		if ( 'wpcv_date' === $column ) {
			echo esc_html( self::date_label( $post_id ) );
		}
	}
}
