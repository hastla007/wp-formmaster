<?php
/**
 * WordPress personal data exporter/eraser and privacy policy text (docs/spec/datenschutz.md 6, 11;
 * P5-01, P5-02).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Privacy;

use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Repository\JobRepository;
use FormCaptain\Settings\Settings;
use FormCaptain\Support\Clock;
use FormCaptain\Support\Logger;

final class PrivacyIntegration {

	public const KEY      = 'form-captain';
	public const PER_PAGE = 50;

	private const DELETION_SERVICE = 'FormCaptain\\Service\\EntryDeletionService';

	/** Field types whose values are replaced on anonymization (null = value removed). */
	private const ANONYMIZE = array(
		'text'     => '[deleted]',
		'textarea' => '[deleted]',
		'email'    => '[deleted]',
		'tel'      => '[deleted]',
		'phone'    => '[deleted]',
		'hidden'   => '[deleted]',
		'date'     => null,
		'number'   => null,
	);

	/** @var array<int,array<string,mixed>> form cache */
	private array $form_cache = array();

	/** @var array<int,array<string,mixed>|null> version cache */
	private array $version_cache = array();

	public function __construct(
		private Plugin $plugin,
		private EntryRepository $entries,
		private FormRepository $forms,
		private JobRepository $jobs
	) {}

	public function register( Plugin $plugin ): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * @param array<string,mixed> $exporters
	 * @return array<string,mixed>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters[ self::KEY ] = array(
			'exporter_friendly_name' => __( 'Form Captain – form submissions', 'form-captain' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,mixed> $erasers
	 * @return array<string,mixed>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers[ self::KEY ] = array(
			'eraser_friendly_name' => __( 'Form Captain – form submissions', 'form-captain' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( __( 'Form Captain', 'form-captain' ), wp_kses_post( wpautop( self::policy_text(), false ) ) );
	}

	/** Suggested privacy policy text (DS-T-70) with the configured retention period. */
	public static function policy_text(): string {
		$period = match ( (string) Settings::get( 'privacy.retention' ) ) {
			'90d' => __( '90 days', 'form-captain' ),
			'12m' => __( '12 months', 'form-captain' ),
			default => __( '[retention period]', 'form-captain' ),
		};
		return sprintf(
			/* translators: %s: retention period */
			__( 'This website uses forms to receive enquiries. The data you enter is stored in our website database and used to process your request. It is deleted after %s unless longer storage is required. Form data is not passed to third parties unless stated in the form.', 'form-captain' ),
			$period
		);
	}

	/**
	 * E-mail field IDs used for privacy lookups across all forms and versions (privacy_lookup default true).
	 *
	 * @return string[]
	 */
	public function lookup_field_ids(): array {
		$ids = array();
		foreach ( $this->forms->all_schemas() as $schema ) {
			foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
				if ( is_array( $field ) && 'email' === ( $field['type'] ?? '' ) && false !== ( $field['privacy_lookup'] ?? true ) && isset( $field['id'] ) ) {
					$ids[ (string) $field['id'] ] = true;
				}
			}
		}
		return array_keys( $ids );
	}

	private static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		return is_email( $email ) ? $email : '';
	}

	/** @return int[] */
	private function matching_ids( string $email, int $limit, int $offset ): array {
		$this->form_cache    = array();
		$this->version_cache = array();
		$email               = self::normalize_email( $email );
		if ( '' === $email ) {
			return array();
		}
		$user = get_user_by( 'email', $email );
		return $this->entries->ids_for_privacy( $this->lookup_field_ids(), $email, $user ? (int) $user->ID : 0, $limit, $offset );
	}

	/**
	 * Exporter callback.
	 *
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$page = max( 1, $page );
		$ids  = $this->matching_ids( $email, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );
		$data = array();
		foreach ( $ids as $id ) {
			$entry = $this->entries->find( $id );
			if ( ! $entry ) {
				continue;
			}
			$data[] = array(
				'group_id'          => 'form-captain-entries',
				'group_label'       => __( 'Form submissions', 'form-captain' ),
				'group_description' => __( 'Submissions you sent through forms on this website.', 'form-captain' ),
				'item_id'           => 'form-captain-entry-' . $id,
				'data'              => $this->export_item( $entry ),
			);
		}
		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Readable name/value pairs for one entry.
	 *
	 * @param array<string,mixed> $entry
	 * @return array<int,array{name:string,value:string}>
	 */
	public function export_item( array $entry ): array {
		$form    = $this->form( (int) $entry['form_id'] );
		$version = $this->version( (int) $entry['form_version_id'] );
		$schema  = $version['schema'] ?? ( $form['schema'] ?? array() );
		$values  = $this->entries->values_for( (int) $entry['id'] );
		$items   = array(
			array(
				'name'  => __( 'Form', 'form-captain' ),
				'value' => (string) ( $form['title'] ?? '#' . $entry['form_id'] ),
			),
			array(
				'name'  => __( 'Received on', 'form-captain' ),
				'value' => (string) get_date_from_gmt( (string) $entry['created_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			),
			array(
				'name'  => __( 'Status', 'form-captain' ),
				'value' => self::status_label( (string) $entry['status'] ),
			),
		);
		// Double opt-in proof (SET-FC-10): time of the confirmation only, no IP address.
		if ( ! empty( $entry['confirmation_status'] ) ) {
			$items[] = array(
				'name'  => __( 'E-mail confirmation (double opt-in)', 'form-captain' ),
				'value' => 'confirmed' === $entry['confirmation_status'] && ! empty( $entry['confirmed_gmt'] )
					/* translators: %s: date and time */
					? sprintf( __( 'Confirmed on %s', 'form-captain' ), (string) get_date_from_gmt( (string) $entry['confirmed_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) )
					: __( 'Not confirmed', 'form-captain' ),
			);
		}
		if ( ! empty( $entry['locale'] ) ) {
			$items[] = array(
				'name'  => __( 'Language', 'form-captain' ),
				'value' => (string) $entry['locale'],
			);
		}
		$known = array();
		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			$id = (string) ( $field['id'] ?? '' );
			if ( '' === $id || 'html' === ( $field['type'] ?? '' ) ) {
				continue;
			}
			$known[ $id ] = true;
			if ( ! array_key_exists( $id, $values ) ) {
				continue;
			}
			$items[] = array(
				'name'  => (string) ( $field['label'] ?? $field['key'] ?? $id ),
				'value' => $this->display_value( $field, $values[ $id ], $entry ),
			);
		}
		foreach ( $values as $id => $value ) {
			if ( ! isset( $known[ $id ] ) ) {
				$items[] = array(
					'name'  => __( 'Removed field', 'form-captain' ),
					'value' => is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value,
				);
			}
		}
		// Internal notes are personal data about the person and part of the Art. 15 GDPR
		// access request (product decision 2026-11-16). Audit entries contain no personal data.
		foreach ( $this->entries->notes_for( (int) $entry['id'] ) as $note ) {
			if ( 'note' !== ( $note['type'] ?? 'note' ) ) {
				continue;
			}
			$items[] = array(
				'name'  => sprintf(
					/* translators: %s: date and time of the note */
					__( 'Internal note (%s)', 'form-captain' ),
					(string) get_date_from_gmt( (string) $note['created_gmt'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
				),
				'value' => (string) $note['message'],
			);
		}
		return $items;
	}

	/**
	 * @param array<string,mixed>         $field
	 * @param string|array<int,string>    $value
	 * @param array<string,mixed>         $entry
	 */
	private function display_value( array $field, string|array $value, array $entry ): string {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'consent' === $type ) {
			$proof = (array) ( $entry['consent'][ $field['id'] ] ?? array() );
			if ( '1' === (string) ( is_array( $value ) ? '' : $value ) || ! empty( $proof['given'] ) ) {
				return sprintf(
					/* translators: 1: date, 2: revision */
					__( 'Given on %1$s, revision %2$s', 'form-captain' ),
					(string) get_date_from_gmt( (string) $entry['created_gmt'], get_option( 'date_format' ) ),
					(string) ( $proof['revision'] ?? ( $field['consent_revision'] ?? 1 ) )
				);
			}
			return __( 'Not given', 'form-captain' );
		}
		$labels = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
			if ( is_array( $opt ) && isset( $opt['value'] ) ) {
				$labels[ (string) $opt['value'] ] = (string) ( $opt['label'] ?? $opt['value'] );
			}
		}
		$list = is_array( $value ) ? $value : array( $value );
		$list = array_map( static fn( $v ) => $labels[ (string) $v ] ?? (string) $v, $list );
		return implode( ', ', $list );
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			'new' => __( 'New', 'form-captain' ),
			'read' => __( 'Read', 'form-captain' ),
			'archived' => __( 'Archived', 'form-captain' ),
			'spam' => __( 'Spam', 'form-captain' ),
			'trash' => __( 'Trash', 'form-captain' ),
			default => $status,
		};
	}

	/**
	 * Eraser callback. Processed entries no longer match, therefore each page reads from offset 0.
	 *
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		$mode      = 'anonymize' === Settings::get( 'privacy.erasure_mode' ) ? 'anonymize' : 'delete';
		$ids       = $this->matching_ids( $email, self::PER_PAGE, 0 );
		$removed   = 0;
		$failed    = array();
		$messages  = array();
		$connected = false;
		foreach ( $ids as $id ) {
			foreach ( $this->jobs->for_entry( $id ) as $job ) {
				if ( str_starts_with( (string) $job['type'], 'connector.' ) && 'succeeded' === $job['status'] ) {
					$connected = true;
				}
			}
			$ok = 'anonymize' === $mode ? $this->anonymize( $id ) : $this->delete( $id );
			if ( $ok ) {
				++$removed;
			} else {
				$failed[] = $id;
				/* translators: %s: entry ID */
				$messages[] = sprintf( __( 'Submission #%s could not be erased. Please try again or delete it manually.', 'form-captain' ), (string) $id );
			}
		}
		if ( $removed && 'anonymize' === $mode ) {
			/* translators: %s: number of submissions */
			$messages[] = sprintf( __( '%s form submissions were anonymized.', 'form-captain' ), number_format_i18n( $removed ) );
		}
		if ( $connected ) {
			$messages[] = __( 'Some submissions were sent to connected integrations. Data in those systems is not deleted automatically unless the integration supports it.', 'form-captain' );
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => (bool) $failed,
			'messages'       => $messages,
			'done'           => count( $ids ) < self::PER_PAGE || count( $failed ) === count( $ids ),
		);
	}

	/** Hard delete via EntryDeletionService (reason erasure), with an equivalent fallback. */
	public function delete( int $entry_id ): bool {
		try {
			if ( class_exists( self::DELETION_SERVICE ) ) {
				$res = $this->plugin->get( self::DELETION_SERVICE )->delete( $entry_id, 'erasure' );
				return false !== $res && ! is_wp_error( $res ) && null === $this->entries->find( $entry_id );
			}
			$entry = $this->entries->find( $entry_id );
			if ( ! $entry ) {
				return false;
			}
			$this->jobs->cancel_for_entry( $entry_id, 'entry_deleted' );
			apply_filters( 'form_captain_delete_entry_files_v1', $entry_id );
			$this->entries->delete_file_rows( $entry_id );
			$this->entries->purge( $entry_id );
			do_action( 'form_captain_entry_deleted_v1', $entry_id, (int) $entry['form_id'], 'erasure' );
			Logger::log( 'info', 'entry_erased', '', array( 'entry_id' => $entry_id, 'form_id' => (int) $entry['form_id'] ) );
			return true;
		} catch ( \Throwable $e ) {
			Logger::log( 'error', 'entry_erase_failed', get_class( $e ), array( 'entry_id' => $entry_id ) );
			return false;
		}
	}

	/**
	 * Anonymization (datenschutz.md 6.4): personal values replaced, choices and consent proof kept.
	 */
	public function anonymize( int $entry_id ): bool {
		try {
			$entry = $this->entries->find( $entry_id );
			if ( ! $entry ) {
				return false;
			}
			$version = $this->version( (int) $entry['form_version_id'] );
			$types   = array();
			foreach ( (array) ( $version['schema']['fields'] ?? array() ) as $field ) {
				if ( isset( $field['id'] ) ) {
					$types[ (string) $field['id'] ] = (string) ( $field['type'] ?? '' );
				}
			}
			$replace = array();
			foreach ( $this->entries->values_for( $entry_id ) as $field_id => $value ) {
				$type = $types[ $field_id ] ?? 'text'; // Unknown fields are treated as personal.
				if ( array_key_exists( $type, self::ANONYMIZE ) ) {
					$replace[ $field_id ] = self::ANONYMIZE[ $type ];
				} elseif ( ! in_array( $type, array( 'select', 'radio', 'checkbox', 'consent' ), true ) ) {
					$replace[ $field_id ] = '[deleted]';
				}
			}
			$this->jobs->cancel_for_entry( $entry_id, 'entry_anonymized' );
			$this->entries->replace_values( $entry_id, $replace );
			$this->entries->delete_notes( $entry_id );
			apply_filters( 'form_captain_delete_entry_files_v1', $entry_id );
			$this->entries->delete_file_rows( $entry_id );
			$this->entries->update(
				$entry_id,
				array(
					'user_id'        => null,
					'source_url'     => null,
					'source_post_id' => null,
					'anonymized_gmt' => Clock::now(),
				)
			);
			do_action( 'form_captain_entry_deleted_v1', $entry_id, (int) $entry['form_id'], 'anonymized' );
			Logger::log( 'info', 'entry_anonymized', '', array( 'entry_id' => $entry_id, 'form_id' => (int) $entry['form_id'] ) );
			return true;
		} catch ( \Throwable $e ) {
			Logger::log( 'error', 'entry_anonymize_failed', get_class( $e ), array( 'entry_id' => $entry_id ) );
			return false;
		}
	}

	/** @return array<string,mixed>|null */
	private function form( int $id ): ?array {
		if ( ! array_key_exists( $id, $this->form_cache ) ) {
			$this->form_cache[ $id ] = $this->forms->find( $id );
		}
		return $this->form_cache[ $id ];
	}

	/** @return array<string,mixed>|null */
	private function version( int $id ): ?array {
		if ( ! array_key_exists( $id, $this->version_cache ) ) {
			$this->version_cache[ $id ] = $this->forms->find_version( $id );
		}
		return $this->version_cache[ $id ];
	}
}
