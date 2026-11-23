<?php
/**
 * Builds admin notifications and submitter confirmations from fresh entry data
 * (P4-07, P4-08, P4-17, T-MAIL-01…06).
 *
 * - Recipients are static per form ({admin_email} placeholder allowed), never from field values.
 * - From comes from form settings (fallback global settings), never from field values.
 * - Reply-To only from the configured e-mail field after CRLF check and is_email().
 * - Subject placeholders: {form_title}, {site_title}, {entry_number}, {field:key} (non-sensitive fields only).
 * - Body: field allowlist (default all non-sensitive), html fields and consent texts never.
 *   HTML is fully escaped and a plain-text alternative is always built.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Mail;

use FormCaptain\Domain\Fields\FieldTypes;
use FormCaptain\Domain\HtmlSanitizer;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\DoubleOptIn;
use FormCaptain\Service\EntryNumbers;
use FormCaptain\Settings\FormMessagingSettings;
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
		$subject = $this->subject( $subject_tpl, $form, $fields, $values, $rejected, $entry );

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
			'format'     => $this->format( $settings, $form ),
			'html'       => self::wrap_html( $this->html( $title, $intro, $rows, $link, __( 'View submission in WordPress', 'form-captain' ) ), self::frame( 'admin', $form ) ),
			'text'       => self::wrap_text( $this->text( $intro, $rows, $link, __( 'View submission in WordPress', 'form-captain' ) ), self::frame( 'admin', $form ) ),
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
		$subject = $this->subject( $subject_tpl, $form, $fields, $values, $rejected, $entry );

		$body = trim( (string) ( $settings['body'] ?? '' ) );
		if ( '' === $body ) {
			$body = __( 'Thank you! We have received your message and will get back to you as soon as possible.', 'form-captain' );
		}
		$body  = $this->body( $body, $form, $fields, $values, array(), $entry );
		$opted = isset( $settings['fields'] ) && is_array( $settings['fields'] ) ? $settings['fields'] : array();
		$rows  = $opted ? $this->rows( $fields, $values, $opted ) : array();
		$title = (string) ( $form['title'] ?? '' );
		$from  = $this->from( $form, $rejected );

		return array(
			'to'         => array( $to ),
			'subject'    => $subject,
			'reply_to'   => self::visitor_reply_to(),
			'from_email' => $from['email'],
			'from_name'  => $from['name'],
			'format'     => $this->format( $settings, $form ),
			'html'       => self::wrap_html( $this->html( $title, $body['html'], $rows, null, '', true ), self::frame( 'confirmation', $form ) ),
			'text'       => self::wrap_text( $this->text( $body['text'], $rows, null, '' ), self::frame( 'confirmation', $form ) ),
			'rejected'   => array_values( array_unique( $rejected ) ),
		);
	}

	/**
	 * Double opt-in mail with the confirmation link (SET-FC-10). Goes only to the configured,
	 * validated e-mail field; placeholders {confirm_link}, {link_hours}, {form_title},
	 * {site_title} and {field:key}. Without {confirm_link} the link is added below the text.
	 *
	 * @param array<string,mixed>                     $form
	 * @param array<string,mixed>                     $entry
	 * @param array<string,string|array<int,string>> $values
	 * @return array<string,mixed>|\WP_Error
	 */
	public function double_opt_in( array $form, array $entry, array $values, string $link ): array|\WP_Error {
		$settings = DoubleOptIn::settings( $form );
		$fields   = self::fields( $this->schema_for( $form, $entry ) );
		$rejected = array();
		$raw      = $values[ $settings['email_field'] ] ?? '';
		if ( ! is_string( $raw ) || '' === trim( $raw ) || self::header_unsafe( $raw ) || ! is_email( trim( $raw ) ) ) {
			return new \WP_Error( 'invalid_recipient', 'No valid double opt-in recipient' );
		}
		$to = sanitize_email( trim( $raw ) );

		$subject_tpl = trim( (string) $settings['subject'] );
		if ( '' === $subject_tpl ) {
			/* translators: %s: form title placeholder */
			$subject_tpl = sprintf( __( 'Please confirm your submission: %s', 'form-captain' ), '{form_title}' );
		}
		$subject = $this->subject( $subject_tpl, $form, $fields, $values, $rejected, $entry );

		$template = trim( (string) $settings['body'] );
		if ( '' === $template ) {
			$template = __( 'Please confirm your submission to "{form_title}" with this link:', 'form-captain' ) . "\n{confirm_link}\n\n"
				. __( 'The link is valid for {link_hours} hours. If you did not send this form, simply ignore this email; your submission will then be deleted automatically.', 'form-captain' );
		}
		// Hours shortened to the time until the entry would be deleted (SET-FC-P2-6).
		$hours = (string) DoubleOptIn::link_hours_for( $entry, $form );
		// A link written by hand (<a href="{confirm_link}">…</a>) only gets the URL.
		$template = str_replace( array( 'href="{confirm_link}"', "href='{confirm_link}'" ), 'href="{confirm_url}"', $template );
		$extra    = array(
			'{confirm_url}'  => array( esc_url( $link ), $link ),
			'{confirm_link}' => array( '<a href="' . esc_url( $link ) . '">' . esc_html( $link ) . '</a>', $link ),
			'{link_hours}'   => array( $hours, $hours ),
		);
		$body     = $this->body( $template, $form, $fields, $values, $extra, $entry );
		if ( ! str_contains( $template, '{confirm_link}' ) && ! str_contains( $template, '{confirm_url}' ) ) {
			$body['html'] .= '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'Confirm submission', 'form-captain' ) . '</a></p>';
			$body['text'] .= "\n\n" . $link;
		}
		$from  = $this->from( $form, $rejected );
		$frame = self::frame( 'double_opt_in', $form );
		return array(
			'to'         => array( $to ),
			'subject'    => $subject,
			'reply_to'   => self::visitor_reply_to(),
			'from_email' => $from['email'],
			'from_name'  => $from['name'],
			'format'     => $this->format( $settings, $form ),
			'html'       => self::wrap_html( $this->html( (string) ( $form['title'] ?? '' ), $body['html'], array(), null, '', true ), $frame ),
			'text'       => self::wrap_text( $this->text( $body['text'], array(), null, '' ), $frame ),
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

	/**
	 * Format of one mail: the section's own value, then the form's `email.format`, then the
	 * global setting ("global" or an empty value means: ask the next level; SET-FC-17).
	 *
	 * @param array<string,mixed> $settings section settings (notification, confirmation_mail, double_opt_in)
	 * @param array<string,mixed> $form
	 */
	private function format( array $settings, array $form = array() ): string {
		foreach ( array( $settings['format'] ?? null, $form['settings']['email']['format'] ?? null, Settings::get( 'email.format' ) ) as $candidate ) {
			if ( in_array( $candidate, array( 'html', 'text' ), true ) ) {
				return $candidate;
			}
		}
		return 'html';
	}

	/** Fixed reply-to address for mails to visitors (SET-FC-03), null when not set. */
	public static function visitor_reply_to(): ?string {
		$address = trim( (string) Settings::get( 'email.reply_to' ) );
		return '' !== $address && ! self::header_unsafe( $address ) && is_email( $address ) ? sanitize_email( $address ) : null;
	}

	/**
	 * Logo and footer shared by every mail (SET-FC-03).
	 *
	 * Filter `form_captain_mail_footer_v1` ( string $footer, string $kind, array $form ): the filter
	 * wins over the setting; kinds are admin, confirmation, double_opt_in and test.
	 *
	 * @param array<string,mixed> $form
	 * @return array{footer:string,logo:array{url:string,width:int,height:int,alt:string}|null}
	 */
	public static function frame( string $kind, array $form = array() ): array {
		$footer = apply_filters( 'form_captain_mail_footer_v1', (string) Settings::get( 'email.footer_text' ), $kind, $form );
		$footer = is_string( $footer ) ? trim( wp_strip_all_tags( $footer ) ) : '';
		$logo   = null;
		$id     = (int) Settings::get( 'email.logo_id' );
		if ( $id > 0 && wp_attachment_is_image( $id ) ) {
			$src = wp_get_attachment_image_src( $id, 'medium' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$logo = array(
					'url'    => (string) $src[0],
					'width'  => (int) ( $src[1] ?? 0 ),
					'height' => (int) ( $src[2] ?? 0 ),
					'alt'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				);
			}
		}
		return array(
			'footer' => mb_substr( $footer, 0, 2000 ),
			'logo'   => $logo,
		);
	}

	/**
	 * Adds logo (top) and footer (bottom) to an HTML document built by html().
	 *
	 * @param array{footer:string,logo:array{url:string,width:int,height:int,alt:string}|null} $frame
	 */
	public static function wrap_html( string $html, array $frame ): string {
		if ( null !== $frame['logo'] ) {
			$width = $frame['logo']['width'] > 0 ? min( 240, $frame['logo']['width'] ) : 240;
			$img   = '<p><img src="' . esc_url( $frame['logo']['url'] ) . '" alt="' . esc_attr( $frame['logo']['alt'] ) . '" width="' . (int) $width . '" style="max-width:240px;height:auto;border:0;"></p>';
			$pos   = strpos( $html, '>', (int) strpos( $html, '<body' ) );
			$html  = false !== $pos ? substr_replace( $html, $img, $pos + 1, 0 ) : $img . $html;
		}
		if ( '' !== $frame['footer'] ) {
			$foot = '<hr style="border:0;border-top:1px solid #dcdcde;margin:24px 0 12px;"><p style="font-size:12px;color:#50575e;">' . nl2br( esc_html( $frame['footer'] ) ) . '</p>';
			$pos  = strrpos( $html, '</body>' );
			$html = false !== $pos ? substr_replace( $html, $foot, $pos, 0 ) : $html . $foot;
		}
		return $html;
	}

	/**
	 * Adds the footer to a plain-text mail, separated by the usual signature delimiter.
	 *
	 * @param array{footer:string,logo:array<string,mixed>|null} $frame
	 */
	public static function wrap_text( string $text, array $frame ): string {
		return '' === $frame['footer'] ? $text : rtrim( $text ) . "\n\n-- \n" . $frame['footer'] . "\n";
	}

	/**
	 * @param array<string,mixed>                     $form
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values
	 * @param string[]                                $rejected
	 * @param array<string,mixed>                     $entry
	 */
	private function subject( string $template, array $form, array $fields, array $values, array &$rejected, array $entry = array() ): string {
		$subject = $this->replace_placeholders( $template, $form, $fields, $values, $entry );
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
	 * @param array<string,mixed>                     $entry
	 */
	private function replace_placeholders( string $text, array $form, array $fields, array $values, array $entry = array(), bool $escape = false ): string {
		$esc  = static fn( string $v ): string => $escape ? esc_html( $v ) : $v;
		$text = str_replace(
			array( '{form_title}', '{site_title}', '{entry_number}' ),
			array( $esc( (string) ( $form['title'] ?? '' ) ), $esc( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ), $entry ? $esc( EntryNumbers::display( $entry ) ) : '' ),
			$text
		);
		return (string) preg_replace_callback(
			'/\{field:([a-z][a-z0-9_]{0,39})\}/',
			function ( array $m ) use ( $fields, $values, $esc ): string {
				foreach ( $fields as $field ) {
					if ( ( $field['key'] ?? '' ) === $m[1] ) {
						if ( ! empty( $field['sensitive'] ) || ! self::mailable_type( (string) $field['type'] ) ) {
							return '';
						}
						return $esc( self::display_value( $field, $values[ $field['id'] ] ?? '', true ) );
					}
				}
				return '';
			},
			$text
		);
	}

	/**
	 * Body of a mail to the visitor (SET-FC-19): plain text as before, or simple HTML from the
	 * allowlist of html fields. Field values are escaped before they enter the HTML, and the
	 * result is cleaned again at send time. $extra holds trusted placeholders (e.g. the double
	 * opt-in link) as [placeholder => [html, text]]; they are added after cleaning.
	 *
	 * @param array<string,mixed>                     $form
	 * @param array<int,array<string,mixed>>          $fields
	 * @param array<string,string|array<int,string>> $values
	 * @param array<string,array{0:string,1:string}>  $extra
	 * @param array<string,mixed>                     $entry for {entry_number}
	 * @return array{html:string,text:string}
	 */
	private function body( string $template, array $form, array $fields, array $values, array $extra = array(), array $entry = array() ): array {
		if ( FormMessagingSettings::is_html( $template ) ) {
			$html = HtmlSanitizer::clean( $this->replace_placeholders( $template, $form, $fields, $values, $entry, true ) );
			$html = strtr( $html, array_map( static fn( array $p ): string => $p[0], $extra ) );
			return array(
				'html' => $html,
				'text' => self::html_to_text( $html ),
			);
		}
		$text = wp_strip_all_tags( $this->replace_placeholders( $template, $form, $fields, $values, $entry ) );
		$html = nl2br( esc_html( $text ) );
		return array(
			'html' => strtr( $html, array_map( static fn( array $p ): string => $p[0], $extra ) ),
			'text' => strtr( $text, array_map( static fn( array $p ): string => $p[1], $extra ) ),
		);
	}

	/** Plain-text alternative of allowlisted HTML: paragraphs, line breaks, list items and link targets survive. */
	public static function html_to_text( string $html ): string {
		$html = (string) preg_replace_callback(
			'#<a\s[^>]*href=("|\')([^"\']*)\1[^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$label = trim( wp_strip_all_tags( $m[3] ) );
				$url   = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				return '' === $label || $label === $url ? $url : $label . ' (' . $url . ')';
			},
			$html
		);
		$html = (string) preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = (string) preg_replace( '#<li[^>]*>#i', "\n- ", $html );
		$html = (string) preg_replace( '#</(p|h3|h4|ul|ol)>#i', "\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
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
				'value' => self::display_value( $field, $values[ $field['id'] ], true ),
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
	 * @param bool                      $local_dates date fields in the date format of the site (mails, admin)
	 *                                               instead of the stored YYYY-MM-DD (CSV keeps ISO).
	 */
	public static function display_value( array $field, string|array $value, bool $local_dates = false ): string {
		$type = (string) ( $field['type'] ?? '' );
		if ( 'consent' === $type ) {
			return '1' === (string) ( is_array( $value ) ? ( $value[0] ?? '' ) : $value ) ? __( 'Yes', 'form-captain' ) : __( 'No', 'form-captain' );
		}
		if ( $local_dates && 'date' === $type && is_string( $value ) ) {
			return self::local_date( $value );
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
	 * A stored calendar date (YYYY-MM-DD) in the date format of the site (SET-FC-P3-5), e.g. 01.02.2000
	 * for "d.m.Y". The date has no time zone, so it is formatted in UTC to keep the day. Other values
	 * are returned unchanged.
	 */
	public static function local_date( string $value ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $value;
		}
		$format = (string) get_option( 'date_format' );
		$out    = wp_date( '' !== $format ? $format : 'Y-m-d', (int) gmmktime( 12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ), new \DateTimeZone( 'UTC' ) );
		return is_string( $out ) && '' !== $out ? $out : $value;
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
	private function html( string $title, string $intro, array $rows, ?string $link, string $link_label, bool $intro_is_html = false ): string {
		// Language of the mail (the job switches to the locale of the submission) for screen readers.
		$lang = str_replace( '_', '-', (string) preg_replace( '/_(formal|informal)$/', '', determine_locale() ) );
		$out  = '<!DOCTYPE html><html lang="' . esc_attr( $lang ) . '"><head><meta charset="UTF-8"></head><body style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:14px;color:#1d2327;">';
		$out .= '<h2 style="font-size:18px;">' . esc_html( $title ) . '</h2>';
		$out .= $intro_is_html ? '<div>' . $intro . '</div>' : '<p>' . nl2br( esc_html( $intro ) ) . '</p>';
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
