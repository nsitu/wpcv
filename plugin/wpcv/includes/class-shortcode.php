<?php
/**
 * Public CV rendering.
 *
 * @package WPCV
 */

namespace WPCV;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public static function boot(): void {
		add_shortcode( 'wpcv', array( self::class, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function enqueue_assets(): void {
		// Small, scoped assets also support shortcodes in widgets and templates,
		// where detecting the shortcode in the current post would miss them.
		wp_enqueue_style( 'wpcv', WPCV_PLUGIN_URL . 'assets/cv.css', array(), WPCV_VERSION );
		wp_enqueue_script( 'wpcv', WPCV_PLUGIN_URL . 'assets/cv.js', array(), WPCV_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function render( $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'order' => 'DESC' ), $attributes, 'wpcv' );
		$order      = 'ASC' === strtoupper( (string) $attributes['order'] ) ? 'ASC' : 'DESC';
		$entries    = get_posts(
			array(
				'post_type'        => Entries::POST_TYPE,
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		// Sorting the complete published set retains entries with missing date
		// metadata, which a meta_key-based SQL sort would otherwise exclude.
		usort(
			$entries,
			static function ( \WP_Post $left, \WP_Post $right ) use ( $order ): int {
				$a = Entries::date_value( $left->ID );
				$b = Entries::date_value( $right->ID );
				if ( ! $a || ! $b ) {
					return $a === $b ? $left->ID <=> $right->ID : ( $a ? -1 : 1 );
				}
				$comparison = 'ASC' === $order ? $a <=> $b : $b <=> $a;
				return $comparison ? $comparison : $left->ID <=> $right->ID;
			}
		);
		$terms = $entries ? wp_get_object_terms( wp_list_pluck( $entries, 'ID' ), Entries::TAXONOMY, array( 'orderby' => 'name' ) ) : array();
		$terms = is_wp_error( $terms ) ? array() : $terms;
		$count = count( $entries );
		ob_start();
		?>
		<section class="wpcv" data-wpcv aria-label="<?php esc_attr_e( 'Curriculum vitae', 'wpcv' ); ?>">
			<?php if ( $entries ) : ?>
				<div class="wpcv__filters" data-wpcv-filters role="group" aria-label="<?php esc_attr_e( 'Filter CV by category', 'wpcv' ); ?>" hidden>
					<button type="button" class="wpcv__filter" data-wpcv-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'wpcv' ); ?></button>
					<?php foreach ( $terms as $term ) : ?>
						<button type="button" class="wpcv__filter" data-wpcv-filter="<?php echo esc_attr( $term->term_id ); ?>" aria-pressed="false"><?php echo esc_html( $term->name ); ?></button>
					<?php endforeach; ?>
				</div>
				<p class="wpcv__count" data-wpcv-count role="status" aria-live="polite" aria-atomic="true" data-one="<?php esc_attr_e( '%d entry', 'wpcv' ); ?>" data-many="<?php esc_attr_e( '%d entries', 'wpcv' ); ?>">
					<?php echo esc_html( sprintf( _n( '%d entry', '%d entries', $count, 'wpcv' ), $count ) ); ?>
				</p>
				<div class="wpcv__entries">
					<?php foreach ( $entries as $entry ) : ?>
						<?php self::render_entry( $entry ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<p class="wpcv__empty" data-wpcv-empty <?php echo $entries ? 'hidden' : ''; ?>><?php esc_html_e( 'No CV entries to display.', 'wpcv' ); ?></p>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_entry( \WP_Post $entry ): void {
		$terms       = get_the_terms( $entry->ID, Entries::TAXONOMY );
		$terms       = is_array( $terms ) ? $terms : array();
		$term_ids    = implode( ' ', wp_list_pluck( $terms, 'term_id' ) );
		$context     = get_post_meta( $entry->ID, '_wpcv_context', true );
		$location    = get_post_meta( $entry->ID, '_wpcv_location', true );
		$url         = esc_url( get_post_meta( $entry->ID, '_wpcv_url', true ), array( 'http', 'https' ) );
		$title       = get_the_title( $entry );
		$description = trim( $entry->post_content );
		$edit_url    = is_user_logged_in() && current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $entry->ID ) ? get_edit_post_link( $entry->ID, 'raw' ) : null;
		?>
		<article class="wpcv__entry" data-wpcv-entry data-categories="<?php echo esc_attr( $term_ids ); ?>">
			<p class="wpcv__date"><?php echo esc_html( Entries::date_label( $entry->ID ) ); ?></p>
			<div class="wpcv__thumbnail">
				<?php echo get_the_post_thumbnail( $entry->ID, 'thumbnail', array( 'class' => 'wpcv__image', 'loading' => 'lazy' ) ); ?>
			</div>
			<div class="wpcv__body">
				<div class="wpcv__heading">
					<h3 class="wpcv__title"><?php echo esc_html( $title ); ?></h3>
					<?php if ( $edit_url ) : ?>
						<a class="wpcv__edit" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit entry', 'wpcv' ); ?><span class="wpcv__screen-reader-text">: <?php echo esc_html( $title ); ?></span></a>
					<?php endif; ?>
				</div>
				<?php if ( $context ) : ?><p class="wpcv__context"><?php echo nl2br( esc_html( $context ) ); ?></p><?php endif; ?>
				<?php if ( $location ) : ?><p class="wpcv__location"><?php echo esc_html( $location ); ?></p><?php endif; ?>
				<?php if ( $entry->post_excerpt ) : ?>
					<div class="wpcv__summary"><?php echo wp_kses_post( wpautop( $entry->post_excerpt ) ); ?></div>
				<?php endif; ?>
				<?php if ( $terms ) : ?>
					<ul class="wpcv__categories" aria-label="<?php esc_attr_e( 'Categories', 'wpcv' ); ?>">
						<?php foreach ( $terms as $term ) : ?><li><?php echo esc_html( $term->name ); ?></li><?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if ( $description ) : ?>
					<details class="wpcv__details">
						<summary><?php esc_html_e( 'Read more', 'wpcv' ); ?><span class="wpcv__screen-reader-text"> <?php echo esc_html( $title ); ?></span></summary>
						<div class="wpcv__description"><?php echo wp_kses_post( wpautop( do_blocks( $description ) ) ); ?></div>
					</details>
				<?php endif; ?>
				<?php if ( $url ) : ?>
					<a class="wpcv__link" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Visit link', 'wpcv' ); ?><span class="wpcv__screen-reader-text">: <?php echo esc_html( $title ); ?></span> <span aria-hidden="true">↗</span></a>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}
}
