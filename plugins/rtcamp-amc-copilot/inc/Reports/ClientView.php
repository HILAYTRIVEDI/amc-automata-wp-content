<?php
/**
 * The "Maintenance Reports" wp-admin page: published reports, read only.
 *
 * @package AMC
 */

declare( strict_types = 1 );

namespace AMC\Reports;

use AMC\Core\Capabilities;
use WP_Post;
use WP_Query;
use rtCamp\WPPrimitives\Contracts\Interfaces\Registrable;

/**
 * Class ClientView
 *
 * Page shell for the client's read-only view. Lane D2 supplies the report
 * styling and print stylesheet through the hooks below.
 *
 * - Client administrators (`amc_view_reports`, not engineers) get a top-level
 *   "Maintenance Reports" menu. Engineers already have the post type's menu, so
 *   they get a "Client view" submenu under it, the same page.
 * - `admin.php?page=amc-reports` lists published reports (title, period,
 *   published date). `&report=<id>` shows one report, read only.
 * - Only PUBLISHED reports are listed or shown, except that an engineer may
 *   open a draft here to preview it. No editor, no drafts, no other post types.
 * - The report body is the post content rendered with `do_blocks()` inside
 *   `<div class="amc-report">`. Filter `amc_report_view_content` replaces it.
 *
 * Hooks for lane D2:
 *
 *   do_action( 'amc_report_view_enqueue', int $post_id, WP_Post $post )
 *       On `admin_enqueue_scripts`, only on the view screen of one report:
 *       enqueue the print stylesheet and report styles here.
 *   do_action( 'amc_report_view_toolbar', WP_Post $post )
 *       Inside the page header, for a "Print / Save as PDF" button.
 *   apply_filters( 'amc_report_view_content', string $html, WP_Post $post )
 *
 * @since 0.1.0
 */
final class ClientView implements Registrable {

	/**
	 * Menu page slug.
	 */
	public const SLUG = 'amc-reports';

	/**
	 * Query argument naming the report to show.
	 */
	public const QUERY_ARG = 'report';

	/**
	 * Screen hook suffix of the registered page ('' until the menu is built).
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * URL of a report's read-only view.
	 *
	 * @param int  $post_id Report ID.
	 * @param bool $engineer Whether the link is for an engineer (the page lives under the post type menu).
	 */
	public static function view_url( int $post_id, ?bool $engineer = null ): string {
		$engineer ??= current_user_can( Capabilities::MANAGE );

		$base = $engineer
			? admin_url( 'edit.php?post_type=' . ReportPostType::SLUG . '&page=' . self::SLUG )
			: admin_url( 'admin.php?page=' . self::SLUG );

		return add_query_arg( self::QUERY_ARG, $post_id, $base );
	}

	/**
	 * Add the menu entry.
	 */
	public function register_menu(): void {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			$hook = add_submenu_page(
				'edit.php?post_type=' . ReportPostType::SLUG,
				__( 'Client view', 'rtcamp-amc-copilot' ),
				__( 'Client view', 'rtcamp-amc-copilot' ),
				ReportPostType::READ_CAP,
				self::SLUG,
				[ $this, 'render' ]
			);
		} elseif ( current_user_can( Capabilities::VIEW_REPORTS ) ) {
			$hook = add_menu_page(
				__( 'Maintenance Reports', 'rtcamp-amc-copilot' ),
				__( 'Maintenance Reports', 'rtcamp-amc-copilot' ),
				Capabilities::VIEW_REPORTS,
				self::SLUG,
				[ $this, 'render' ],
				'dashicons-media-document',
				58
			);
		} else {
			return;
		}

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Let lane D2 enqueue report styles on the single-report view only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$post = $this->requested_report();

		if ( $post instanceof WP_Post ) {
			/**
			 * Enqueue the report view assets (print stylesheet, block styles).
			 *
			 * @param int     $post_id Report ID.
			 * @param WP_Post $post    Report.
			 */
			do_action( 'amc_report_view_enqueue', $post->ID, $post );
		}
	}

	/**
	 * Render the page: the list, or one report.
	 */
	public function render(): void {
		if ( ! current_user_can( ReportPostType::READ_CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'rtcamp-amc-copilot' ), '', [ 'response' => 403 ] );
		}

		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		$requested = isset( $_GET[ self::QUERY_ARG ] ) ? absint( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.

		if ( $requested > 0 ) {
			$post = $this->requested_report();

			if ( ! $post instanceof WP_Post ) {
				wp_die( esc_html__( 'That report is not available.', 'rtcamp-amc-copilot' ), '', [ 'response' => 404 ] );
			}

			$this->render_report( $post );

			return;
		}

		$this->render_list();
	}

	/**
	 * The report named in the URL, when the current user may see it.
	 *
	 * Published reports for anyone who can read reports; a draft only for an
	 * engineer.
	 */
	public function requested_report(): ?WP_Post {
		$id = isset( $_GET[ self::QUERY_ARG ] ) ? absint( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.

		return self::visible_report( $id );
	}

	/**
	 * A report the current user may view, or null.
	 *
	 * @param int $post_id Report ID.
	 */
	public static function visible_report( int $post_id ): ?WP_Post {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || ReportPostType::SLUG !== $post->post_type || ! current_user_can( ReportPostType::READ_CAP ) ) {
			return null;
		}

		if ( 'publish' === $post->post_status ) {
			return $post;
		}

		return current_user_can( Capabilities::MANAGE ) && current_user_can( 'read_post', $post->ID ) ? $post : null;
	}

	/**
	 * The published-reports table.
	 */
	private function render_list(): void {
		$reports = new WP_Query(
			[
				'post_type'              => ReportPostType::SLUG,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		echo '<div class="wrap amc-reports"><h1 class="wp-heading-inline">' . esc_html__( 'Maintenance Reports', 'rtcamp-amc-copilot' ) . '</h1><hr class="wp-header-end">';

		if ( ! $reports->have_posts() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No reports have been published yet.', 'rtcamp-amc-copilot' ) . '</p></div></div>';

			return;
		}

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Report', 'rtcamp-amc-copilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Period', 'rtcamp-amc-copilot' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Published', 'rtcamp-amc-copilot' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $reports->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			echo '<tr><td><a href="' . esc_url( self::view_url( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></td>';
			echo '<td>' . esc_html( self::period_text( $post->ID ) ) . '</td>';
			echo '<td>' . esc_html( get_the_date( '', $post ) ) . '</td></tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * One report, read only.
	 *
	 * @param WP_Post $post Report.
	 */
	private function render_report( WP_Post $post ): void {
		$list = admin_url( ( current_user_can( Capabilities::MANAGE ) ? 'edit.php?post_type=' . ReportPostType::SLUG . '&' : 'admin.php?' ) . 'page=' . self::SLUG );

		/**
		 * Filters the rendered report body.
		 *
		 * @param string  $html Rendered blocks.
		 * @param WP_Post $post Report.
		 */
		$body = (string) apply_filters( 'amc_report_view_content', do_blocks( $post->post_content ), $post );

		echo '<div class="wrap amc-report-view"><h1 class="wp-heading-inline">' . esc_html( get_the_title( $post ) ) . '</h1>';
		echo '<a href="' . esc_url( $list ) . '" class="page-title-action">' . esc_html__( 'All reports', 'rtcamp-amc-copilot' ) . '</a>';

		if ( 'publish' !== $post->post_status ) {
			echo '<span class="amc-report-status"> ' . esc_html__( '(draft preview)', 'rtcamp-amc-copilot' ) . '</span>';
		}

		/**
		 * Fires in the report page header (add a print button here).
		 *
		 * @param WP_Post $post Report.
		 */
		do_action( 'amc_report_view_toolbar', $post );

		echo '<hr class="wp-header-end"><p class="description">' . esc_html( self::period_text( $post->ID ) ) . '</p>';

		// Block output is escaped by each block's render callback; this is rendered post content.
		echo '<div class="amc-report" id="amc-report-' . (int) $post->ID . '">' . $body . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * "2026-10-01 to 2026-10-31".
	 *
	 * @param int $post_id Report ID.
	 */
	private static function period_text( int $post_id ): string {
		$start = (string) get_post_meta( $post_id, '_amc_period_start', true );
		$end   = (string) get_post_meta( $post_id, '_amc_period_end', true );

		if ( '' === $start && '' === $end ) {
			return '';
		}

		/* translators: 1: period start date, 2: period end date. */
		return sprintf( __( '%1$s to %2$s', 'rtcamp-amc-copilot' ), $start, $end );
	}
}
