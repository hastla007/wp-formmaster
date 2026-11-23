<?php
/**
 * Entry list table (P4-04): form, date (site time zone), status, contact fields.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin\Tables;

use FormCaptain\Export\CsvExporter;
use FormCaptain\Install\Capabilities;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\RetentionService;
use FormCaptain\Support\Clock;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
if ( ! function_exists( 'convert_to_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
}
if ( ! class_exists( '\WP_Screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
}
if ( ! function_exists( 'get_column_headers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

final class EntriesListTable extends \WP_List_Table {

	public const PER_PAGE = 20;

	/** @var array<int,array<string,mixed>|null> */
	private array $version_cache = array();

	/** @var array<int,array<string,mixed>|null> */
	private array $form_cache = array();

	/** @var array<int,array<string,string|array<int,string>>> */
	private array $values = array();

	/**
	 * @param array{form_id:int,status:string,search:string,date_from:string,date_to:string,paged:int,consent?:string} $filters
	 */
	public function __construct(
		private EntryRepository $entries,
		private FormRepository $forms,
		private array $filters,
		private string $base_url
	) {
		parent::__construct(
			array(
				'singular' => 'fc_entry',
				'plural'   => 'fc_entries',
				'ajax'     => false,
				'screen'   => 'form-captain_page_form-captain-entries',
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return array(
			'cb'        => '<input type="checkbox" />',
			'entry'     => __( 'Submission', 'form-captain' ),
			'form'      => __( 'Form', 'form-captain' ),
			'submitted' => __( 'Submitted', 'form-captain' ),
			'status'    => __( 'Status', 'form-captain' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'entry' );
		$args                  = array(
			'form_id'  => $this->filters['form_id'],
			'search'   => $this->filters['search'],
			'per_page' => self::PER_PAGE,
			'page'     => max( 1, $this->filters['paged'] ),
		);
		if ( in_array( $this->filters['status'], EntryRepository::STATUSES, true ) ) {
			$args['status'] = $this->filters['status'];
		}
		if ( 'expiring' === $this->filters['status'] ) {
			$days = RetentionService::notice_days();
			$ids  = $days > 0 ? $this->entries->ids_expiring_before( Clock::plus( $days * DAY_IN_SECONDS ), $this->filters['form_id'] ) : array();
			if ( ! $ids ) {
				$ids = array( 0 );
			}
			$args['ids'] = $ids;
		}
		if ( in_array( $this->filters['consent'] ?? '', array( 'withdrawn', 'active' ), true ) ) {
			$args['consent'] = $this->filters['consent'];
		}
		if ( $this->filters['date_from'] ) {
			$args['date_from'] = get_gmt_from_date( $this->filters['date_from'] . ' 00:00:00' );
		}
		if ( $this->filters['date_to'] ) {
			$args['date_to'] = get_gmt_from_date( $this->filters['date_to'] . ' 23:59:59' );
		}
		$result       = $this->entries->list( $args );
		$this->items  = $result['items'];
		$this->values = $this->entries->values_for_many( array_map( static fn( array $e ): int => (int) $e['id'], $this->items ) );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			)
		);
	}

	/** @return array<string,string> */
	protected function get_views(): array {
		$counts  = $this->entries->status_counts( $this->filters['form_id'] );
		$current = $this->filters['status'];
		$labels  = array(
			''         => array( __( 'All', 'form-captain' ), $counts['new'] + $counts['read'] + $counts['archived'] ),
			'new'      => array( __( 'New', 'form-captain' ), $counts['new'] ),
			'read'     => array( __( 'Read', 'form-captain' ), $counts['read'] ),
			'archived' => array( __( 'Archived', 'form-captain' ), $counts['archived'] ),
			'spam'     => array( __( 'Spam', 'form-captain' ), $counts['spam'] ),
			'trash'    => array( __( 'Trash', 'form-captain' ), $counts['trash'] ),
		);
		$views   = array();
		foreach ( $labels as $status => $info ) {
			$url                       = add_query_arg(
				array_filter(
					array(
						'status'  => $status,
						'form_id' => $this->filters['form_id'] ?: null,
					)
				),
				$this->base_url
			);
			$views[ $status ?: 'all' ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $info[0] ),
				esc_html( number_format_i18n( $info[1] ) )
			);
		}
		if ( RetentionService::notice_days() > 0 ) {
			$views['expiring'] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( add_query_arg( array_filter( array( 'status' => 'expiring', 'form_id' => $this->filters['form_id'] ?: null ) ), $this->base_url ) ),
				'expiring' === $current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'Expiring soon', 'form-captain' )
			);
		}
		return $views;
	}

	/** @return array<string,string> */
	protected function get_bulk_actions(): array {
		$actions = array();
		$status  = $this->filters['status'];
		if ( 'trash' === $status ) {
			if ( current_user_can( Capabilities::DELETE_ENTRIES ) ) {
				$actions['restore'] = __( 'Restore', 'form-captain' );
				$actions['delete']  = __( 'Delete permanently', 'form-captain' );
			}
			return $actions;
		}
		if ( current_user_can( Capabilities::EDIT_ENTRIES ) ) {
			if ( 'spam' === $status ) {
				$actions['not_spam'] = __( 'Not spam', 'form-captain' );
			} else {
				$actions['read']    = __( 'Mark as read', 'form-captain' );
				$actions['archive'] = __( 'Archive', 'form-captain' );
				$actions['spam']    = __( 'Mark as spam', 'form-captain' );
			}
		}
		if ( current_user_can( Capabilities::DELETE_ENTRIES ) ) {
			$actions['trash'] = __( 'Move to trash', 'form-captain' );
			if ( 'spam' === $status ) {
				$actions['delete'] = __( 'Delete permanently', 'form-captain' );
			}
		}
		return $actions;
	}

	/**
	 * Own select names so the admin-post `action` parameter is not overwritten.
	 *
	 * @param string $which top|bottom
	 */
	protected function bulk_actions( $which = '' ): void {
		$actions = $this->get_bulk_actions();
		if ( ! $actions ) {
			return;
		}
		$name = 'top' === $which ? 'fc_bulk_action' : 'fc_bulk_action2';
		echo '<label for="' . esc_attr( $name ) . '" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'form-captain' ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		echo '<option value="-1">' . esc_html__( 'Bulk actions', 'form-captain' ) . '</option>';
		foreach ( $actions as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Apply', 'form-captain' ), 'action', '', false, array( 'id' => 'doaction' . ( 'top' === $which ? '' : '2' ) ) );
	}

	public function no_items(): void {
		esc_html_e( 'No submissions found.', 'form-captain' );
	}

	/** @param array<string,mixed> $item */
	protected function column_cb( $item ): string {
		return sprintf(
			'<label class="screen-reader-text" for="fc-entry-%1$d">%2$s</label><input type="checkbox" id="fc-entry-%1$d" name="ids[]" value="%1$d" />',
			(int) $item['id'],
			/* translators: %d: submission ID */
			esc_html( sprintf( __( 'Select submission #%d', 'form-captain' ), (int) $item['id'] ) )
		);
	}

	/** @param array<string,mixed> $item */
	protected function column_entry( $item ): string {
		$url     = add_query_arg( 'view', (int) $item['id'], $this->base_url );
		$contact = $this->contact( $item );
		$title   = '' !== $contact['name'] ? $contact['name'] : ( '' !== $contact['email'] ? $contact['email'] : sprintf( '#%d', (int) $item['id'] ) );
		$out     = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></strong>';
		if ( 'new' === $item['status'] ) {
			$out = '<span class="fc-unread" aria-hidden="true">&#9679;</span> ' . $out;
		}
		if ( '' !== $contact['name'] && '' !== $contact['email'] ) {
			$out .= '<br /><span class="description">' . esc_html( $contact['email'] ) . '</span>';
		}
		$out .= '<br /><span class="description">#' . (int) $item['id'] . '</span>';
		return $out;
	}

	/** @param array<string,mixed> $item */
	protected function column_form( $item ): string {
		$form = $this->form( (int) $item['form_id'] );
		if ( ! $form ) {
			return esc_html__( '(deleted form)', 'form-captain' );
		}
		return '<a href="' . esc_url( add_query_arg( 'form_id', (int) $form['id'], $this->base_url ) ) . '">' . esc_html( (string) $form['title'] ) . '</a>';
	}

	/** @param array<string,mixed> $item */
	protected function column_submitted( $item ): string {
		$out  = esc_html( get_date_from_gmt( (string) $item['created_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
		$days = RetentionService::notice_days();
		// SET-FC-P3-1: unconfirmed double opt-in submissions are deleted at their own deadline.
		$until = \FormCaptain\Service\DoubleOptIn::STATUS_PENDING === ( $item['confirmation_status'] ?? null ) && ! empty( $item['unconfirmed_until_gmt'] ) ? (string) $item['unconfirmed_until_gmt'] : (string) ( $item['expires_gmt'] ?? '' );
		if ( $days > 0 && '' !== $until && $until <= Clock::plus( $days * DAY_IN_SECONDS ) && ! in_array( $item['status'], array( 'trash', 'spam' ), true ) ) {
			/* translators: %s: date */
			$out .= '<br /><span class="fc-badge fc-badge-warning">' . esc_html( sprintf( __( 'Deletion on %s', 'form-captain' ), get_date_from_gmt( $until, get_option( 'date_format' ) ) ) ) . '</span>';
		}
		return $out;
	}

	/** @param array<string,mixed> $item */
	protected function column_status( $item ): string {
		$out = esc_html( CsvExporter::status_label( $item ) );
		if ( 'spam' === $item['status'] && ! empty( $item['spam_reason'] ) ) {
			$out .= '<br /><span class="description">' . esc_html( self::spam_reason_label( (string) $item['spam_reason'] ) ) . '</span>';
		}
		$badge = self::consent_withdrawn_badge( $item );
		if ( '' !== $badge ) {
			$out .= '<br />' . $badge;
		}
		return $out;
	}

	/**
	 * Escaped badge "Consent withdrawn on …" or '' when consent was not withdrawn.
	 *
	 * @param array<string,mixed> $entry
	 */
	public static function consent_withdrawn_badge( array $entry ): string {
		if ( empty( $entry['consent_withdrawn_gmt'] ) ) {
			return '';
		}
		return '<span class="fc-badge fc-badge-warning fc-consent-withdrawn">' . esc_html(
			sprintf(
				/* translators: %s: date */
				__( 'Consent withdrawn on %s', 'form-captain' ),
				get_date_from_gmt( (string) $entry['consent_withdrawn_gmt'], (string) get_option( 'date_format' ) )
			)
		) . '</span>';
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		return '';
	}

	public static function spam_reason_label( string $reason ): string {
		return match ( $reason ) {
			'honeypot' => __( 'Honeypot field filled', 'form-captain' ),
			'too_fast' => __( 'Submitted too fast', 'form-captain' ),
			'blocklist' => __( 'Blocklist match', 'form-captain' ),
			default => $reason,
		};
	}

	/**
	 * First non-sensitive text and email field of the entry's version.
	 *
	 * @param array<string,mixed> $item
	 * @return array{name:string,email:string}
	 */
	private function contact( array $item ): array {
		$vid = (int) $item['form_version_id'];
		if ( ! array_key_exists( $vid, $this->version_cache ) ) {
			$this->version_cache[ $vid ] = $this->forms->find_version( $vid );
		}
		$schema = $this->version_cache[ $vid ]['schema'] ?? ( $this->form( (int) $item['form_id'] )['schema'] ?? array() );
		$values = $this->values[ (int) $item['id'] ] ?? array();
		$out    = array(
			'name'  => '',
			'email' => '',
		);
		foreach ( MailBuilder::fields( (array) $schema ) as $field ) {
			$type = $field['type'] ?? '';
			$slot = 'text' === $type ? 'name' : ( 'email' === $type ? 'email' : null );
			if ( null === $slot || '' !== $out[ $slot ] ) {
				continue;
			}
			if ( ! empty( $field['sensitive'] ) ) {
				$out[ $slot ] = '-';
				continue;
			}
			$value = $values[ $field['id'] ] ?? '';
			if ( is_string( $value ) && '' !== $value ) {
				$out[ $slot ] = mb_substr( $value, 0, 80 );
			}
		}
		return array_map( static fn( string $v ): string => '-' === $v ? '' : $v, $out );
	}

	/** @return array<string,mixed>|null */
	private function form( int $id ): ?array {
		if ( ! array_key_exists( $id, $this->form_cache ) ) {
			$this->form_cache[ $id ] = $this->forms->find( $id );
		}
		return $this->form_cache[ $id ];
	}
}
