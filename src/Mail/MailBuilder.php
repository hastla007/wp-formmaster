<?php
/**
 * Builds admin notifications and submitter confirmations from fresh entry data
 * (P4-07, P4-08, P4-17, T-MAIL-01…06).
 *
 * - Recipients are static per form ({admin_email} placeholder allowed), never from field values.
 * - From comes from form settings (fallback global settings), never from field values.
 * - Reply-To only from the configured e-mail field after CRLF check and is_email().
 * - Subject placeholders: {form_title}, {site_title}, {field:key} (non-sensitive fields only).
 * - Body: field allowlist (default all non-sensitive), html fields and consent texts never.
 *   HTML is fully escaped and a plain-text alternative is always built.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Mail;

use FormCaptain\Domain\Fields\FieldTypes;

use FormCaptain\Repository\FormRepository;
use FormCaptain\Settings\Settings;

final class MailBuilder {

	public const SUBJECT_MAX = 255;

	public function __construct( private FormRepository $forms ) {}

	/** True when a header value contains CR, LF, NUL or their URL-encoded forms. */
	public static function header_unsafe( string $value ): bool {
		return 1 === preg_match( '/[\r\n\0]|%0[ad]|%00/i', $value );
	}

	/** Removes header-breaking characters. */
	public static function strip_header( string $value ): string {
		$value = (string) preg_replace( '/[\r\n\0]+|%0[ad]|%00/i', ' ', $value );
		return trim( (string) preg_replace( '/\s{2,}/', ' ', $value ) );
	}

	/**
	 * Schema the entry was submitted against (falls back to the current form schema).
	 *
	 * @param array<string,mixed> $form
	 * @param array<string,mixed> $entry
	 * @return array<string,mixed>
	 */
	public function schema_for( array $form, array $entry ): array {
		$version = ! empty( $entry['form_version_id'] ) ? $this->forms->find_version( (int) $entry['form_version_id'] ) : null;
		return $version ? $version['schema'] : (array) ( $form['schema'] ?? array() );
	}

	/**
	 * Admin notification.
	 *
	 * @param array<string,mixed>                     $form
	 * @param array<string,mixed>                     $entry
	 * @param array<string,string|array<int,string>> $values
	 * @return array<string,mixed>|\WP_Error
	 */
	public function admin( array $form, array $entry, array $values ): array|\WP_Error {
		$settings = (array) ( $form['settings']['notification'] ?? array() );
		$schema   = $this->schema_for( $form, $entry );
		$fields   = self::fields( $schema );
		$rejected = array();

		/*
		 * Routing (contract 1.1.0): an extension may replace the configured recipient list, e.g. by
		 * conditions on the values. The result goes through the same checks as configured addresses.
		 */
		$configured = apply_filters( 'form_captain_notification_recipients_v1', $settings['to'] ?? array( '{admin_email}' ), $form, (int) ( $entry['id'] ?? 0 ), $values );
		$to         = $this->recipients( is_array( $configured ) || is_string( $configured ) ? $configured : array(), $rejected );
		if ( ! $to ) {
			return new \WP_Error( 'no_recipients', 'No valid recipient configured' );
		}

		$reply_to = null;
		$reply_id = (string) ( $settings['reply_to_field'] ?? '' );
		if ( '' !== $reply_id ) {
			$field = self::find_field( $fields, $reply_id );
			$raw   = $field ? ( $values[ $field['id'] ] ?? '' ) : '';
			if ( is_string( $raw ) && '' !== $raw ) {
				if ( self::header_unsafe( $raw ) ) {
					$rejected[] = 'reply_to';
				} elseif ( is_email( trim( $raw ) ) ) {
					$reply_to = sanitize_email( trim( $raw ) );
				}
			}
		}

		$subject_tpl = (string) ( $settings['subject'] ?? '' );
		if ( '' === trim( $subject_tpl ) ) {
			/* translators: %s: form title placeholder */
			$subject_tpl = sprintf( __( 'New submission: %s', 'form-captain' ), '{form_title}' );
		}
		$subject = $this->subject( $subject_tpl, $form, $fields, $values, $rejected );

		$selection = $settings['fields'] ?? 'all_non_sensitive';
		$rows      = $this->rows( $fields, $values, $selection );
		$link      = admin_url( 'admin.php?page=form-captain-entries&view=' . (int) $entry['id'] );
		$title     = (string) ( $form['title'] ?? '' );
		$intro     = sprintf(
			/* translators: 1: form title, 2: submission ID */
			__( 'A new submission was received via "%1$s" (#%2$d).', 'form-captain' ),
			$title,
			(int) $entry['id']
		);

		$from = $this->from( $form, $rejected );
		return array(
			'to'         => $to,
			'subject'    => $subject,
			'reply_to'   => $reply_to,
			'from_email' => $from['email'],
			'from_name'  => $from['name'],
			'format'     => $this->format( $settings ),
			'html'       => $this->html( $title, $intro, $rows, $link, __( 'View submission in WordPress', 'form-captain' ) ),
			'text'       => $this->text( $intro, $rows, $link, __( 'View submission in WordPress', 'form-captain' ) ),
			'rejected'   => array_values( array_unique( $rejected ) ),
		);
	}

	/**
	 * Confirmation to the submitter: only to a validated e-mail field marked confirmation_recipient;
	 * neutral content without field values unless explicitly opted in (T-MAIL-05).
	 *
	 * @param array<string,mixed>                     $form
	 * @param array<string,mixed>                     $entry
	 * @param array<string,string|array<int,string>> $values
	 * @return array<string,mixed>|\WP_Error
	 */
	public function confirmation( array $form, array $entry, array $values ): array|\WP_Error {
		$settings = (array) ( $form['settings']['confirmation_mail'] ?? array() );
		$schema   = $this->schema_for( $form, $entry );
		$fields   = self::fields( $schema );
		$rejected = array();

		$to = null;
		foreach ( $fields as $field ) {
			if ( 'email' === ( $field['type'] ?? '' ) && ! empty( $field['confirmation_recipient'] ) ) {
				$raw = $values[ $field['id'] ] ?? '';
				if ( is_string( $raw ) && '' !== $raw && ! self::header_unsafe( $raw ) && is_email( trim( $raw ) ) ) {
					$to = sanitize_email( trim( $raw ) );
				} elseif ( is_string( $raw ) && self::header_unsafe( $raw ) ) {
					$rejected[] = 'to';
				}
				break;
			}
		}
		if ( null === $to ) {
			return new \WP_Error( 'invalid_recipient', 'No valid confirmation recipient' );
		}

		$subject_tpl = trim( (string) ( $settings['subject'] ?? '' ) );
		if ( '' === $subject_tpl ) {
			/* translators: %s: form title placeholder */
			$subject_tpl = sprintf( __( 'Thank you for your message: %s', 'form-captain' ), '{form_title}' );
		}
		$subject = $this->subject( $subject_tpl, $form, $fields, $values, $rejected );

		$body = trim( (string) ( $settings['body'] ?? '' ) );
		if ( '' === $body ) {
			$body = __( 'Thank you! We have received your message and will get back to you as soon as possible.', 'form-captain' );
		}
		$body  = wp_strip_all_tags( $this->replace_placeholders( $body, $form, $fields, $values ) );
		$opted = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array();
		$rows  = $opted ? $this->rows( $fields, $values, $opted ) : array();
		$title = (string) ( $form['title'] ?? '' );
		$from  = $this->from( $form, $rejected );

		return array(
			'to'         => array( $to ),
			'subject'    => $subject,
			'reply_to'   => null,
			'from_email' => $from['email'],
			'from_name'  => $from['name'],
			'format'     => $this->format( $settings ),
			'html'       => $this->html( $title, $body, $rows, null, '' ),
			'text'       => $this->text( $body, $rows, null, '' ),
			'rejected'   => array_values( array_unique( $rejected ) ),
		);
	}

	/**
	 * @param string|array<int,string> $configured
	 * @param string[]                 $rejected
	 * @return string[]
	 */
	private function recipients( string|array $configured, array &$rejected ): array {
		$list = is_array( $configured ) ? $configured : explode( ',', $configured );
		$out  = array();
		foreach ( $list as $address ) {
			$address = (string) $address;
			if ( self::header_unsafe( $address ) ) {
				$rejected[] = 'to';
				continue;
			}
			foreach ( explode( ',', str_replace( '{admin_email}', (string) get_option( 'admin_email' ), $address ) ) as $part ) {
				$part = trim( $part );
				if ( '' !== $part && is_email( $part ) ) {
					$out[] = strtolower( sanitize_email( $part ) );
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string,mixed> $form
	 * @param string[]            $rejected
	 * @return array{email:string,name:string}
	 */
	private function from( array $form, array &$rejected ): array {
		$own   = (array) ( $form['settings']['email'] ?? array() );
		$notif = (array) ( $form['settings']['notification'] ?? array() );
		$name  = (string) ( $own['from_name'] ?? $notif['from_name'] ?? '' );
		$email = (string) ( $own['from_address'] ?? $notif['from_address'] ?? '' );
		if ( '' === trim( $name ) ) {
			$name = (string) Settings::get( 'email.from_name' );
		}
		if ( '' === trim( $email ) || self::header_unsafe( $email ) || ! is_email( $email ) ) {
			$email = (string) Settings::get( 'email.from_address' );
		}
		if ( self::header_unsafe( $name ) ) {
			$rejected[] = 'from_name';
		}
		$name = trim( str_replace( array( '<', '>', '"', ',', ';' ), '', self::strip_header( $name ) ) );
		if ( ! is_email( $email ) ) {
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$email = 'wordpress@' . ( is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'localhost' );
		}
		return array(
			'email' => sanitize_email( $email ),
			'name'  => mb_substr( '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ), 0, 190 ),
		);
	}

	/** @param array<string,mixed> $settings */
	private function format( array $settings ): string {
		$format = (string) ( $settings['format'] ?? Settings::get( 'email.format' ) ?? 'html' );
		return 'text' === $format ? 'text' : 'html';
	}

	/**
	 * @param array<string,mixed>                     $form
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values
	 * @param string[]                                $rejected
	 */
	private function subject( string $template, array $form, array $fields, array $values, array &$rejected ): string {
		$subject = $this->replace_placeholders( $template, $form, $fields, $values );
		if ( self::header_unsafe( $subject ) ) {
			$rejected[] = 'subject';
			$subject    = self::strip_header( $subject );
		}
		$subject = wp_strip_all_tags( $subject );
		return mb_substr( $subject, 0, self::SUBJECT_MAX );
	}

	/**
	 * @param array<string,mixed>                     $form
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values
	 */
	private function replace_placeholders( string $text, array $form, array $fields, array $values ): string {
		$text = str_replace(
			array( '{form_title}', '{site_title}' ),
			array( (string) ( $form['title'] ?? '' ), wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$text
		);
		return (string) preg_replace_callback(
			'/\{field:([a-z][a-z0-9_]{0,39})\}/',
			function ( array $m ) use ( $fields, $values ): string {
				foreach ( $fields as $field ) {
					if ( ( $field['key'] ?? '' ) === $m[1] ) {
						if ( ! empty( $field['sensitive'] ) || ! self::mailable_type( (string) $field['type'] ) ) {
							return '';
						}
						return self::display_value( $field, $values[ $field['id'] ] ?? '' );
					}
				}
				return '';
			},
			$text
		);
	}

	/**
	 * Rows for the body according to the field allowlist.
	 *
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values
	 * @param mixed                                   $selection 'all_non_sensitive' or list of field IDs/keys
	 * @return array<int,array{label:string,value:string}>
	 */
	private function rows( array $fields, array $values, mixed $selection ): array {
		$explicit = is_array( $selection ) ? array_map( 'strval', $selection ) : null;
		$rows     = array();
		foreach ( $fields as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( ! self::mailable_type( $type ) ) {
				continue;
			}
			if ( null === $explicit ) {
				if ( ! empty( $field['sensitive'] ) ) {
					continue;
				}
			} elseif ( ! in_array( (string) $field['id'], $explicit, true ) && ! in_array( (string) ( $field['key'] ?? '' ), $explicit, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $field['id'], $values ) ) {
				continue;
			}
			$rows[] = array(
				'label' => (string) ( $field['label'] ?? $field['key'] ?? $field['id'] ),
				'value' => self::display_value( $field, $values[ $field['id'] ] ),
			);
		}
		return $rows;
	}

	/** html fields have no value; anything unknown to the core is skipped too. */
	private static function mailable_type( string $type ): bool {
		return '' !== $type && 'html' !== $type;
	}

	/**
	 * Human-readable value (option labels, consent as Yes/No).
	 *
	 * @param array<string,mixed>       $field
	 * @param string|array<int,string> $value
	 */
	public static function display_value( array $field, string|array $value ): string {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'consent' === $type ) {
			return '1' === (string) ( is_array( $value ) ? ( $value[0] ?? '' ) : $value ) ? __( 'Yes', 'form-captain' ) : __( 'No', 'form-captain' );
		}
		$labels = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $option ) {
			if ( isset( $option['value'] ) ) {
				$labels[ (string) $option['value'] ] = (string) ( $option['label'] ?? $option['value'] );
			}
		}
		$items   = is_array( $value ) ? $value : array( $value );
		$items   = array_map( static fn( $v ): string => $labels[ (string) $v ] ?? (string) $v, $items );
		$display = implode( ', ', $items );
		if ( empty( FieldTypes::all()[ $type ]['core'] ) ) {
			// Extension field types describe their stored value as plain text (contract 1.1.0),
			// e.g. a file name instead of an internal file reference. Callers escape the result.
			$custom  = apply_filters( 'form_captain_display_value_v1', $display, $field, $value );
			$display = is_string( $custom ) ? wp_strip_all_tags( $custom ) : $display;
		}
		return $display;
	}

	/**
	 * @param array<string,mixed> $schema
	 * @return array<int,array<string,mixed>>
	 */
	public static function fields( array $schema ): array {
		return array_values( array_filter( (array) ( $schema['fields'] ?? array() ), static fn( $f ): bool => is_array( $f ) && isset( $f['id'] ) ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<string,mixed>|null
	 */
	private static function find_field( array $fields, string $id_or_key ): ?array {
		foreach ( $fields as $field ) {
			if ( $field['id'] === $id_or_key || ( $field['key'] ?? null ) === $id_or_key ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * @param array<int,array{label:string,value:string}> $rows
	 */
	private function html( string $title, string $intro, array $rows, ?string $link, string $link_label ): string {
		$out  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;color:#1d2327;">';
		$out .= '<h2 style="font-size:18px;">' . esc_html( $title ) . '</h2>';
		$out .= '<p>' . nl2br( esc_html( $intro ) ) . '</p>';
		if ( $rows ) {
			$out .= '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;">';
			foreach ( $rows as $row ) {
				$out .= '<tr><th align="left" valign="top" style="border-bottom:1px solid #dcdcde;">' . esc_html( $row['label'] ) . '</th>';
				$out .= '<td valign="top" style="border-bottom:1px solid #dcdcde;">' . nl2br( esc_html( $row['value'] ) ) . '</td></tr>';
			}
			$out .= '</table>';
		}
		if ( null !== $link ) {
			$out .= '<p><a href="' . esc_url( $link ) . '">' . esc_html( $link_label ) . '</a></p>';
		}
		return $out . '</body></html>';
	}

	/**
	 * @param array<int,array{label:string,value:string}> $rows
	 */
	private function text( string $intro, array $rows, ?string $link, string $link_label ): string {
		$out = $intro . "\n\n";
		foreach ( $rows as $row ) {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $row['value'] );
			$out  .= $row['label'] . ': ' . ( str_contains( $value, "\n" ) ? "\n  " . str_replace( "\n", "\n  ", $value ) : $value ) . "\n";
		}
		if ( null !== $link ) {
			$out .= "\n" . $link_label . ': ' . $link . "\n";
		}
		return $out;
	}
}
