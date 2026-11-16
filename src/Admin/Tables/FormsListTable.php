<?php
/**
 * Forms overview table (P2-02).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin\Tables;

use FormCaptain\Admin\FormsPage;
use FormCaptain\Import\EntryImportAdmin;
use FormCaptain\Install\Capabilities;
use FormCaptain\Repository\FormRepository;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class FormsListTable extends \WP_List_Table {

	/** @var array<int,int> */
	private array $counts = array();

	public function __construct( private FormRepository $forms ) {
		parent::__construct(
			array(
				'singular' => 'form',
				'plural'   => 'forms',
				'ajax'     => false,
				'screen'   => 'form-captain-forms',
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return array(
			'title'    => __( 'Title', 'form-captain' ),
			'status'   => __( 'Status', 'form-captain' ),
			'embed'    => __( 'Embed', 'form-captain' ),
			'entries'  => __( 'Entries', 'form-captain' ),
			'modified' => __( 'Last modified', 'form-captain' ),
		);
	}

	/** @return array<string,array{0:string,1:bool}> */
	protected function get_sortable_columns(): array {
		return array(
			'title'    => array( 'title', false ),
			'status'   => array( 'status', false ),
			'modified' => array( 'updated_gmt', true ),
		);
	}

	public static function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return in_array( $status, FormRepository::STATUSES, true ) ? $status : '';
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'updated_gmt';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable
		$per_page              = 20;
		$result                = $this->forms->list(
			array(
				'status'   => self::current_status(),
				'search'   => $search,
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
				'orderby'  => $orderby,
				'order'    => $order,
			)
		);
		$this->counts          = $this->forms->entry_counts();
		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	/** @return array<string,string> */
	protected function get_views(): array {
		$current = self::current_status();
		$base    = admin_url( 'admin.php?page=form-captain-forms' );
		$labels  = array(
			''          => __( 'All', 'form-captain' ),
			'published' => __( 'Published', 'form-captain' ),
			'draft'     => __( 'Drafts', 'form-captain' ),
			'paused'    => __( 'Paused', 'form-captain' ),
			'archived'  => __( 'Archived', 'form-captain' ),
		);
		$views   = array();
		foreach ( $labels as $status => $label ) {
			$count                     = $this->forms->list(
				array(
					'status'   => $status,
					'per_page' => 1,
				)
			)['total'];
			$url                       = '' === $status ? $base : add_query_arg( 'status', $status, $base );
			$views[ $status ?: 'all' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $count
			);
		}
		return $views;
	}

	public function no_items(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) || '' !== self::current_status() ) {
			esc_html_e( 'No forms match your filter.', 'form-captain' );
			return;
		}
		esc_html_e( 'You have not created a form yet. Start with a template – it takes less than a minute.', 'form-captain' );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=form-captain-edit' ) ) . '">' . esc_html__( 'Create your first form', 'form-captain' ) . '</a>';
	}

	public static function status_label( string $status ): string {
		return match ( $status ) {
			'published' => __( 'Published', 'form-captain' ),
			'paused' => __( 'Paused', 'form-captain' ),
			'archived' => __( 'Archived', 'form-captain' ),
			default => __( 'Draft', 'form-captain' ),
		};
	}

	/** @param array<string,mixed> $item */
	protected function column_title( $item ): string {
		$id      = (int) $item['id'];
		$edit    = admin_url( 'admin.php?page=form-captain-edit&form=' . $id );
		$actions = array(
			'edit'      => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'form-captain' ) . '</a>',
			'duplicate' => '<a href="' . esc_url( FormsPage::action_url( 'duplicate', $id ) ) . '">' . esc_html__( 'Duplicate', 'form-captain' ) . '</a>',
			'preview'   => '<a href="' . esc_url( FormsPage::action_url( 'preview', $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Preview', 'form-captain' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'form-captain' ) . '</span></a>',
		);
		switch ( $item['status'] ) {
			case 'published':
				$actions['pause'] = '<a href="' . esc_url( FormsPage::action_url( 'pause', $id ) ) . '">' . esc_html__( 'Pause', 'form-captain' ) . '</a>';
				break;
			case 'paused':
				$actions['resume'] = '<a href="' . esc_url( FormsPage::action_url( 'resume', $id ) ) . '">' . esc_html__( 'Resume', 'form-captain' ) . '</a>';
				break;
			case 'archived':
				$actions['restore'] = '<a href="' . esc_url( FormsPage::action_url( 'restore', $id ) ) . '">' . esc_html__( 'Restore as draft', 'form-captain' ) . '</a>';
				break;
		}
		if ( 'archived' !== $item['status'] ) {
			$actions['archive'] = '<a href="' . esc_url( FormsPage::action_url( 'archive', $id ) ) . '">' . esc_html__( 'Archive', 'form-captain' ) . '</a>';
		}
		$actions['export'] = '<a href="' . esc_url( FormsPage::action_url( 'export', $id ) ) . '">' . esc_html__( 'Export JSON', 'form-captain' ) . '</a>';
		if ( in_array( $item['status'], array( 'published', 'paused' ), true ) && current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			$actions['import_entries'] = '<a href="' . esc_url( EntryImportAdmin::page_url( $id ) ) . '">' . esc_html__( 'Import entries', 'form-captain' ) . '</a>';
		}
		$total = FormsPage::entry_total( $id );
		if ( 0 === $total ) {
			$actions['delete'] = '<a class="submitdelete" href="' . esc_url( FormsPage::action_url( 'delete', $id ) ) . '" onclick="return window.confirm(this.dataset.confirm);" data-confirm="' . esc_attr__( 'Delete this form permanently?', 'form-captain' ) . '">' . esc_html__( 'Delete', 'form-captain' ) . '</a>';
		} elseif ( current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			$actions['delete'] = '<a class="submitdelete" href="' . esc_url( FormsPage::action_url( 'delete', $id, array( 'delete_entries' => 1 ) ) ) . '" onclick="return window.confirm(this.dataset.confirm);" data-confirm="' . esc_attr(
				sprintf(
					/* translators: %d: number of entries */
					_n( 'Delete this form and its %d entry permanently? This cannot be undone.', 'Delete this form and all %d entries permanently? This cannot be undone.', $total, 'form-captain' ),
					$total
				)
			) . '">' . esc_html__( 'Delete with entries', 'form-captain' ) . '</a>';
		}
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( (string) $item['title'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/** @param array<string,mixed> $item */
	protected function column_status( $item ): string {
		return '<span class="fc-status fc-status--' . esc_attr( (string) $item['status'] ) . '">' . esc_html( self::status_label( (string) $item['status'] ) ) . '</span>';
	}

	/** @param array<string,mixed> $item */
	protected function column_embed( $item ): string {
		$code = '[form_captain id="' . $item['public_id'] . '"]';
		$id   = 'fc-sc-' . (int) $item['id'];
		return '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Shortcode', 'form-captain' ) . '</label>'
			. '<input type="text" id="' . esc_attr( $id ) . '" class="fc-shortcode code" readonly value="' . esc_attr( $code ) . '" size="34" onfocus="this.select();"> '
			. '<button type="button" class="button button-small fc-copy" data-target="' . esc_attr( $id ) . '">' . esc_html__( 'Copy', 'form-captain' ) . '</button>'
			. '<p class="description">' . esc_html__( 'Or insert the “Form Captain” block in the block editor.', 'form-captain' ) . '</p>';
	}

	/** @param array<string,mixed> $item */
	protected function column_entries( $item ): string {
		$count = $this->counts[ (int) $item['id'] ] ?? 0;
		if ( current_user_can( Capabilities::READ_ENTRIES ) && $count > 0 ) {
			return '<a href="' . esc_url( admin_url( 'admin.php?page=form-captain-entries&form_id=' . (int) $item['id'] ) ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
		}
		return esc_html( number_format_i18n( $count ) );
	}

	/** @param array<string,mixed> $item */
	protected function column_modified( $item ): string {
		$ts = strtotime( (string) $item['updated_gmt'] . ' UTC' );
		return esc_html( false !== $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '' );
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		return '';
	}
}
