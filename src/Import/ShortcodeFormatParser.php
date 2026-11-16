<?php
/**
 * Parser for the "shortcode form format type A" (docs/planung/import.md, P9-06).
 *
 * Own tokenizer: the source is never executed (no do_shortcode(), no eval). Produces
 * candidate field definitions of form schema v1 plus a conversion report. Pure apart
 * from WordPress string helpers (wp_kses, wp_strip_all_tags, translation).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Import;

use FormCaptain\Domain\HtmlSanitizer;
use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Support\Limits;

final class ShortcodeFormatParser {

	/** Hard limit for the template source (bytes). */
	public const MAX_BYTES = 262144;

	/** Maximum stored length of an original tag in the report. */
	private const TAG_REPORT_CHARS = 300;

	/** Tags whose first bare word is not a field name. */
	private const NAMELESS = array( 'submit', 'response' );

	/** Tags that map to a field type of schema v1. */
	private const FIELD_TAGS = array( 'text', 'email', 'url', 'tel', 'number', 'range', 'date', 'textarea', 'select', 'checkbox', 'radio', 'acceptance', 'hidden' );

	/** Severity order for the overall status. */
	public const SEVERITIES = array( 'info', 'adjusted', 'lost', 'blocked' );

	/** @var array<int,array<string,mixed>> */
	private array $findings = array();

	/** @var array<int,array<string,mixed>> */
	private array $elements = array();

	/** @var array<string,bool> */
	private array $keys = array();

	/** @var array<int,array<string,mixed>> */
	private array $fields = array();

	/** @var array<string,string> */
	private array $source_map = array();

	private string $submit_label = '';

	/**
	 * Parses a template source.
	 *
	 * @return array{fields:array<int,array<string,mixed>>,findings:array<int,array<string,mixed>>,elements:array<int,array<string,mixed>>,source_map:array<string,string>,submit_label:string,status:string,counts:array<string,int>}
	 */
	public function parse( string $source ): array {
		$this->findings     = array();
		$this->elements     = array();
		$this->keys         = array();
		$this->fields       = array();
		$this->source_map   = array();
		$this->submit_label = '';

		if ( strlen( $source ) > self::MAX_BYTES ) {
			$this->finding( 'blocked', 'payload_too_large', '', null, __( 'The form source is too large (maximum 256 KB).', 'form-captain' ) );
			return $this->result();
		}
		if ( ! mb_check_encoding( $source, 'UTF-8' ) ) {
			$source = mb_convert_encoding( $source, 'UTF-8', 'ISO-8859-1' );
			$this->finding( 'info', 'encoding_converted', '', null, __( 'The source was not valid UTF-8 and has been converted.', 'form-captain' ) );
		}
		$source = str_replace( array( "\r\n", "\r" ), "\n", $source );
		$source = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $source );

		// Active content is removed completely (including its text) before tokenizing.
		$stripped = (string) preg_replace( '#<(script|style|iframe|object|embed|template|noscript)\b[^>]*>.*?</\1\s*>#is', '', $source );
		$stripped = (string) preg_replace( '#<(script|style|iframe|object|embed|template|noscript)\b.*$#is', '', $stripped );
		if ( $stripped !== $source ) {
			$this->finding( 'lost', 'html_removed', '', null, __( 'Scripts, styles or embedded objects were removed from the source.', 'form-captain' ) );
			$source = $stripped;
		}

		if ( '' === trim( $source ) ) {
			$this->finding( 'blocked', 'empty_source', '', null, __( 'The form source is empty.', 'form-captain' ) );
			return $this->result();
		}

		$segments = self::tokenize( $source );
		$this->build( $segments );

		$value_fields = array_filter( $this->fields, static fn( array $f ): bool => 'html' !== $f['type'] );
		if ( count( $this->fields ) > Limits::get( 'fields_per_form' ) ) {
			/* translators: %d: maximum number of fields */
			$this->finding( 'blocked', 'limit_exceeded', '', null, sprintf( __( 'The form has more than %d fields and cannot be imported (no partial import).', 'form-captain' ), Limits::get( 'fields_per_form' ) ) );
		} elseif ( ! $value_fields ) {
			$this->finding( 'blocked', 'nothing_importable', '', null, __( 'No supported input fields were found.', 'form-captain' ) );
		}
		return $this->result();
	}

	// Tokenizer.

	/**
	 * Splits the source into text segments and tags. Never executes anything.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function tokenize( string $source ): array {
		$out  = array();
		$len  = strlen( $source );
		$text = '';
		$i    = 0;
		while ( $i < $len ) {
			$c = $source[ $i ];
			if ( '[' !== $c ) {
				$next  = strpos( $source, '[', $i );
				$next  = false === $next ? $len : $next;
				$text .= substr( $source, $i, $next - $i );
				$i     = $next;
				continue;
			}
			// Escaped literal [[...]].
			if ( $i + 1 < $len && '[' === $source[ $i + 1 ] ) {
				$end   = strpos( $source, ']]', $i + 2 );
				$end   = false === $end ? $len : $end + 2;
				$text .= substr( $source, $i + 1, max( 0, $end - $i - 2 ) );
				$i     = $end;
				continue;
			}
			if ( ! preg_match( '/\G\[(\/?)([A-Za-z][A-Za-z0-9_\-]{0,63})(\*?)(?=[\s\]\[]|$)/A', $source, $m, 0, $i ) ) {
				$text .= '[';
				++$i;
				continue;
			}
			$tag = self::read_tag( $source, $i, $m );
			if ( null === $tag ) {
				// Malformed (unterminated or nested): report and continue scanning after "[".
				if ( '' !== $text ) {
					$out[] = array( 'kind' => 'text', 'text' => $text );
					$text  = '';
				}
				$stop  = strpos( $source, '[', $i + 1 );
				$stop  = false === $stop ? $len : $stop;
				$out[] = array(
					'kind' => 'malformed',
					'raw'  => substr( $source, $i, $stop - $i ),
					'type' => strtolower( $m[2] ),
				);
				$i     = $stop;
				continue;
			}
			if ( '' !== $text ) {
				$out[] = array( 'kind' => 'text', 'text' => $text );
				$text  = '';
			}
			$out[] = $tag;
			$i     = (int) $tag['end'];
		}
		if ( '' !== $text ) {
			$out[] = array( 'kind' => 'text', 'text' => $text );
		}
		return $out;
	}

	/**
	 * Reads one tag starting at $start. Returns null for malformed tags.
	 *
	 * @param array<int,string> $m
	 * @return array<string,mixed>|null
	 */
	private static function read_tag( string $source, int $start, array $m ): ?array {
		$len    = strlen( $source );
		$i      = $start + strlen( $m[0] );
		$words  = array();
		$values = array();
		while ( $i < $len ) {
			$c = $source[ $i ];
			if ( ctype_space( $c ) ) {
				++$i;
				continue;
			}
			if ( ']' === $c ) {
				$type = strtolower( $m[2] );
				$name = '';
				if ( '' === $m[1] && ! in_array( $type, self::NAMELESS, true ) && $words ) {
					$name = (string) array_shift( $words );
				}
				return array(
					'kind'     => 'tag',
					'raw'      => substr( $source, $start, $i + 1 - $start ),
					'type'     => $type,
					'closing'  => '/' === $m[1],
					'required' => '*' === $m[3],
					'name'     => $name,
					'options'  => $words,
					'values'   => $values,
					'end'      => $i + 1,
				);
			}
			if ( '[' === $c ) {
				return null; // Nested shortcodes are not supported.
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
				$val   = '';
				++$i;
				$closed = false;
				while ( $i < $len ) {
					$d = $source[ $i ];
					if ( '\\' === $d && $i + 1 < $len && in_array( $source[ $i + 1 ], array( '"', "'", '\\' ), true ) ) {
						$val .= $source[ $i + 1 ];
						$i   += 2;
						continue;
					}
					if ( $d === $quote ) {
						$closed = true;
						++$i;
						break;
					}
					$val .= $d;
					++$i;
				}
				if ( ! $closed ) {
					return null;
				}
				$values[] = $val;
				continue;
			}
			$word = '';
			while ( $i < $len && ! ctype_space( $source[ $i ] ) && ! in_array( $source[ $i ], array( ']', '[', '"', "'" ), true ) ) {
				$word .= $source[ $i ];
				++$i;
			}
			$words[] = $word;
		}
		return null;
	}

	// Mapping.

	/**
	 * @param array<int,array<string,mixed>> $segments
	 */
	private function build( array $segments ): void {
		$buffer = '';
		$count  = count( $segments );
		for ( $s = 0; $s < $count; $s++ ) {
			$seg = $segments[ $s ];
			if ( 'text' === $seg['kind'] ) {
				$buffer .= (string) $seg['text'];
				continue;
			}
			if ( 'malformed' === $seg['kind'] ) {
				$this->flush_html( $buffer );
				$buffer = '';
				$this->drop( (string) $seg['raw'], (string) $seg['type'], '', 'tag_malformed', __( 'Malformed or nested tag ignored.', 'form-captain' ) );
				continue;
			}
			$type = (string) $seg['type'];
			if ( $seg['closing'] ) {
				$this->flush_html( $buffer );
				$buffer = '';
				$this->drop( (string) $seg['raw'], $type, '', 'tag_unexpected_end', __( 'Closing tag without matching start tag ignored.', 'form-captain' ) );
				continue;
			}
			if ( 'submit' === $type ) {
				$this->flush_html( $buffer );
				$buffer             = '';
				$this->submit_label = self::plain( (string) ( $seg['values'][0] ?? '' ), 190 );
				$this->element( $seg, 'adjusted', null, array( 'submit_label' ) );
				$this->finding( 'adjusted', 'setting_unsupported', 'submit', null, __( 'Button text is not taken over; Form Captain uses its own translated button text.', 'form-captain' ) );
				continue;
			}
			if ( 'response' === $type ) {
				$this->flush_html( $buffer );
				$buffer = '';
				$this->element( $seg, 'info', null, array( 'tag_ignored' ) );
				$this->finding( 'info', 'tag_ignored', 'response', null, __( 'The message area is provided by Form Captain automatically.', 'form-captain' ) );
				continue;
			}

			// Field-like tag: derive label from preceding text (hidden fields have no visible label).
			$derived = 'hidden' === $type ? array( 'label' => '', 'remainder' => $buffer, 'source' => '' ) : self::derive_label( $buffer );
			$this->flush_html( $derived['remainder'] );
			$buffer = '';

			if ( 'acceptance' === $type ) {
				$content = '';
				$inner   = false;
				for ( $e = $s + 1; $e < $count; $e++ ) {
					if ( 'tag' === $segments[ $e ]['kind'] && $segments[ $e ]['closing'] && 'acceptance' === $segments[ $e ]['type'] ) {
						break;
					}
					if ( 'text' === $segments[ $e ]['kind'] ) {
						$content .= (string) $segments[ $e ]['text'];
					} else {
						$inner = true;
					}
				}
				if ( $e < $count ) {
					$s = $e; // Skip up to the end tag.
				} else {
					$content = ''; // No end tag: text after the tag belongs to the form.
				}
				$this->map_acceptance( $seg, $derived, $content, $inner );
				continue;
			}
			if ( ! in_array( $type, self::FIELD_TAGS, true ) ) {
				$this->unsupported( $seg );
				continue;
			}
			$this->map_field( $seg, $derived );
		}
		$this->flush_html( $buffer );
	}

	/**
	 * Label from the enclosing <label> or the immediately preceding text node.
	 *
	 * @return array{label:string,remainder:string,source:string}
	 */
	public static function derive_label( string $text ): array {
		$open_pos = false;
		$open_len = 0;
		if ( preg_match_all( '/<label\b[^>]*>/i', $text, $mm, PREG_OFFSET_CAPTURE ) ) {
			$last     = end( $mm[0] );
			$open_pos = (int) $last[1];
			$open_len = strlen( (string) $last[0] );
		}
		$close_pos = strripos( $text, '</label' );
		if ( false !== $open_pos && ( false === $close_pos || $close_pos < $open_pos ) ) {
			$label = self::plain( substr( $text, $open_pos + $open_len ), 100000 );
			if ( '' !== $label ) {
				return array( 'label' => $label, 'remainder' => substr( $text, 0, $open_pos ), 'source' => 'label' );
			}
		}
		// Last non-empty text node, last non-empty line of it.
		preg_match_all( '/<[^>]*>/', $text, $tags, PREG_OFFSET_CAPTURE );
		$gaps = array();
		$pos  = 0;
		foreach ( $tags[0] as $t ) {
			$gaps[] = array( $pos, (int) $t[1] - $pos );
			$pos    = (int) $t[1] + strlen( (string) $t[0] );
		}
		$gaps[] = array( $pos, strlen( $text ) - $pos );
		for ( $g = count( $gaps ) - 1; $g >= 0; $g-- ) {
			$chunk = substr( $text, $gaps[ $g ][0], $gaps[ $g ][1] );
			$lines = explode( "\n", $chunk );
			$off   = strlen( $chunk );
			for ( $l = count( $lines ) - 1; $l >= 0; $l-- ) {
				$off -= strlen( $lines[ $l ] );
				$val  = self::plain( $lines[ $l ], 100000 );
				if ( '' !== $val ) {
					return array( 'label' => $val, 'remainder' => substr( $text, 0, $gaps[ $g ][0] + $off ), 'source' => 'text' );
				}
				--$off; // Newline.
			}
		}
		return array( 'label' => '', 'remainder' => $text, 'source' => '' );
	}

	/** Emits an html field for text blocks with visible text. */
	private function flush_html( string $html ): void {
		$visible = self::plain( $html, 100000 );
		if ( '' === $visible ) {
			return;
		}
		$balanced = force_balance_tags( $html );
		$content  = trim( HtmlSanitizer::clean( $balanced ) );
		$layout   = HtmlSanitizer::allowed() + array( 'label' => array(), 'div' => array( 'class' => true ) );
		$codes    = array();
		$severity = 'info';
		$id       = SchemaValidator::new_field_id();
		if ( '' === self::plain( $content, 100000 ) ) {
			return;
		}
		if ( trim( wp_kses( $balanced, $layout, array( 'http', 'https', 'mailto' ) ) ) !== trim( $balanced ) ) {
			$severity = 'adjusted';
			$codes[]  = 'html_sanitized';
			$this->finding( 'adjusted', 'html_sanitized', 'html', $id, __( 'Disallowed HTML (attributes, tags or links) was removed from a text block.', 'form-captain' ) );
		}
		$field            = array(
			'id'        => $id,
			'type'      => 'html',
			'key'       => $this->unique_key( 'html' ),
			'label'     => '',
			'required'  => false,
			'content'   => $content,
			'css_class' => '',
			'condition' => null,
		);
		$this->fields[]   = $field;
		$this->elements[] = array(
			'tag'         => '',
			'source_type' => 'html',
			'name'        => '',
			'outcome'     => 'adjusted' === $severity ? 'adjusted' : 'taken',
			'target_type' => 'html',
			'key'         => $field['key'],
			'label'       => mb_substr( self::plain( $content, 100000 ), 0, 80 ),
			'field_id'    => $id,
			'codes'       => $codes,
		);
	}

	/**
	 * @param array<string,mixed>                                  $seg
	 * @param array{label:string,remainder:string,source:string}  $derived
	 */
	private function map_field( array $seg, array $derived ): void {
		$src    = (string) $seg['type'];
		$name   = (string) $seg['name'];
		$id     = SchemaValidator::new_field_id();
		$codes  = array();
		$sev    = 'info';
		$opts   = self::options( (array) $seg['options'] );
		$values = array_map( 'strval', (array) $seg['values'] );
		$tname  = '' !== $name ? $name : $src;
		$note   = function ( string $severity, string $code, string $message ) use ( &$codes, &$sev, $tname, $id ): void {
			$codes[] = $code;
			if ( array_search( $severity, self::SEVERITIES, true ) > array_search( $sev, self::SEVERITIES, true ) ) {
				$sev = $severity;
			}
			$this->finding( $severity, $code, $tname, $id, $message );
		};

		$type = array(
			'url'   => 'text',
			'range' => 'number',
		)[ $src ] ?? $src;
		if ( 'checkbox' === $src && isset( $opts['flags']['exclusive'] ) ) {
			$type = 'radio';
			$note( 'adjusted', 'type_mapped', __( 'Exclusive checkboxes were converted to radio buttons.', 'form-captain' ) );
		}
		if ( 'url' === $src ) {
			$note( 'adjusted', 'type_mapped', __( 'URL field converted to a text field (no URL validation).', 'form-captain' ) );
		}
		if ( 'range' === $src ) {
			$note( 'adjusted', 'type_mapped', __( 'Slider converted to a number field.', 'form-captain' ) );
		}
		if ( '' === $name ) {
			$note( 'info', 'name_missing', __( 'Tag without name; a key was generated.', 'form-captain' ) );
		}

		$field = array(
			'id'        => $id,
			'type'      => $type,
			'key'       => '',
			'label'     => '',
			'required'  => 'hidden' !== $type && ! empty( $seg['required'] ),
			'css_class' => '',
			'condition' => null,
		);

		// Placeholder / default.
		$placeholder = '';
		$default     = '';
		$has_choices = in_array( $type, array( 'select', 'radio', 'checkbox' ), true );
		if ( ! $has_choices ) {
			if ( isset( $opts['flags']['placeholder'] ) || isset( $opts['flags']['watermark'] ) ) {
				$placeholder = self::plain( $values[0] ?? '', 190 );
			} else {
				$default = self::plain( $values[0] ?? '', 500 );
			}
		}

		// CSS class.
		if ( $opts['classes'] ) {
			$class = implode( ' ', $opts['classes'] );
			if ( preg_match( '/^[A-Za-z0-9_\- ]{0,100}$/', $class ) ) {
				$field['css_class'] = $class;
			} else {
				$note( 'adjusted', 'option_dropped', __( 'Invalid CSS class removed.', 'form-captain' ) );
			}
		}
		if ( isset( $opts['kv']['id'] ) ) {
			$note( 'info', 'option_dropped', __( 'The id: option is not taken over.', 'form-captain' ) );
		}
		if ( isset( $opts['kv']['default'] ) && ! $has_choices ) {
			$note( 'lost', 'option_dropped', __( 'Dynamic default values (default:…) are not supported; the value stays empty.', 'form-captain' ) );
			$default = '';
		}

		$validation = array();
		switch ( $type ) {
			case 'text':
			case 'email':
			case 'tel':
			case 'textarea':
				$cap  = array(
					'text'     => 'url' === $src ? 1000 : Limits::get( 'text_max' ),
					'email'    => 254,
					'tel'      => 40,
					'textarea' => Limits::get( 'textarea_max' ),
				)[ $type ];
				$min  = isset( $opts['kv']['minlength'] ) ? (int) $opts['kv']['minlength'] : null;
				$max  = isset( $opts['kv']['maxlength'] ) ? (int) $opts['kv']['maxlength'] : null;
				$size = $opts['size'];
				if ( null !== $size ) {
					if ( null === $max && null !== $size['max'] ) {
						$max = $size['max'];
					}
					if ( 'textarea' === $type && null !== $size['rows'] ) {
						$rows          = max( 2, min( 20, $size['rows'] ) );
						$field['rows'] = $rows;
						if ( $rows !== $size['rows'] ) {
							$note( 'adjusted', 'option_adjusted', __( 'Number of rows limited to 2–20.', 'form-captain' ) );
						}
					}
				}
				if ( 'url' === $src && null === $max ) {
					$max = 1000;
				}
				if ( null !== $max && $max > $cap ) {
					/* translators: %d: maximum length */
					$note( 'adjusted', 'option_adjusted', sprintf( __( 'Maximum length limited to %d.', 'form-captain' ), $cap ) );
					$max = $cap;
				}
				if ( 'tel' !== $type ) {
					if ( null !== $max && $max > 0 ) {
						$validation['max_length'] = $max;
					}
					if ( null !== $min && $min > 0 && 'email' !== $type ) {
						$validation['min_length'] = $min;
					}
				} elseif ( null !== $min || null !== $max ) {
					$note( 'info', 'option_dropped', __( 'Length limits of phone fields are not taken over.', 'form-captain' ) );
				}
				break;
			case 'number':
				foreach ( array( 'min', 'max', 'step' ) as $k ) {
					if ( isset( $opts['kv'][ $k ] ) ) {
						if ( is_numeric( $opts['kv'][ $k ] ) ) {
							$validation[ $k ] = (string) $opts['kv'][ $k ];
						} else {
							$note( 'adjusted', 'option_dropped', __( 'Invalid number limit removed.', 'form-captain' ) );
						}
					}
				}
				if ( isset( $validation['min'], $validation['max'] ) && (float) $validation['min'] > (float) $validation['max'] ) {
					unset( $validation['min'], $validation['max'] );
					$note( 'adjusted', 'option_dropped', __( 'Contradictory number limits removed.', 'form-captain' ) );
				}
				if ( '' !== $default && ! is_numeric( $default ) ) {
					$default = '';
				}
				break;
			case 'date':
				foreach ( array( 'min', 'max' ) as $k ) {
					if ( isset( $opts['kv'][ $k ] ) ) {
						$v = (string) $opts['kv'][ $k ];
						if ( 'today' === $v || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
							$validation[ $k ] = $v;
						} else {
							$note( 'adjusted', 'option_dropped', __( 'Relative date limits other than "today" are not supported.', 'form-captain' ) );
						}
					}
				}
				if ( '' !== $default && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $default ) ) {
					$default = '';
				}
				break;
		}
		if ( 'tel' === $type && ( isset( $opts['kv']['pattern'] ) ) ) {
			$note( 'info', 'option_dropped', __( 'Input patterns are not taken over.', 'form-captain' ) );
		}
		if ( $validation ) {
			$field['validation'] = $validation;
		}

		// Choices.
		if ( $has_choices ) {
			$choices = $this->choices( $values, $note );
			if ( null === $choices ) {
				$this->element( $seg, 'blocked', null, array( 'limit_exceeded' ) );
				return;
			}
			if ( 'select' === $type && isset( $opts['flags']['first_as_label'] ) && count( $choices ) > 1 ) {
				array_shift( $choices );
				$note( 'adjusted', 'option_adjusted', __( 'The first option served as a prompt and was removed.', 'form-captain' ) );
			}
			if ( ! $choices ) {
				if ( 'checkbox' === $type ) {
					$choices = array( array( 'value' => '1', 'label' => '' ) );
				} else {
					$note( 'lost', 'field_unsupported', __( 'Selection field without options cannot be imported.', 'form-captain' ) );
					$this->element( $seg, 'lost', null, $codes, $type );
					return;
				}
			}
			$field['options'] = $choices;
			if ( 'select' === $type ) {
				$field['multiple'] = isset( $opts['flags']['multiple'] );
				if ( isset( $opts['flags']['include_blank'] ) ) {
					$note( 'info', 'option_adjusted', __( 'Empty first entry: the field has no preselection.', 'form-captain' ) );
				}
			}
			if ( isset( $opts['flags']['free_text'] ) ) {
				$note( 'lost', 'option_dropped', __( 'Free-text option ("other") is not supported.', 'form-captain' ) );
			}
			if ( isset( $opts['kv']['default'] ) ) {
				$idx = (int) strtok( (string) $opts['kv']['default'], '_' );
				if ( 'checkbox' !== $type && $idx >= 1 && isset( $choices[ $idx - 1 ] ) && ! isset( $opts['flags']['include_blank'] ) ) {
					$default = (string) $choices[ $idx - 1 ]['value'];
				} else {
					$note( 'info', 'option_dropped', __( 'Preselection not taken over.', 'form-captain' ) );
				}
			} elseif ( 'radio' === $src ) {
				$note( 'info', 'option_adjusted', __( 'Radio buttons start without forced preselection.', 'form-captain' ) );
			}
			if ( isset( $opts['kv']['data'] ) ) {
				$note( 'lost', 'option_dropped', __( 'External option sources (data:…) are not supported.', 'form-captain' ) );
			}
		}

		if ( $opts['unknown'] ) {
			/* translators: %s: list of option names */
			$note( 'info', 'option_dropped', sprintf( __( 'Options not taken over: %s', 'form-captain' ), implode( ', ', array_slice( $opts['unknown'], 0, 10 ) ) ) );
		}

		// Key and label.
		$field['key'] = $this->key_for( $name, $type, $note );
		$label        = $derived['label'];
		$label_from   = $derived['source'];
		if ( '' === $label && '' !== $placeholder ) {
			$label      = $placeholder;
			$label_from = 'placeholder';
		}
		if ( '' === $label ) {
			$label      = $field['key'];
			$label_from = 'key';
		}
		if ( 'label' !== $label_from ) {
			$note( 'info', 'label_derived', __( 'Label derived from the surrounding text, placeholder or name – please check.', 'form-captain' ) );
		}
		if ( mb_strlen( $label ) > 190 ) {
			$label = rtrim( mb_substr( $label, 0, 189 ) ) . '…';
			$note( 'adjusted', 'label_truncated', __( 'Label shortened to 190 characters.', 'form-captain' ) );
		}
		$field['label'] = $label;
		if ( 'checkbox' === $type && '' === $field['options'][0]['label'] ) {
			$field['options'][0]['label'] = $label;
			$note( 'info', 'option_adjusted', __( 'Single checkbox converted to a checkbox field with one option.', 'form-captain' ) );
		}
		if ( in_array( $type, array( 'text', 'textarea', 'email', 'tel', 'number' ), true ) ) {
			$field['placeholder'] = $placeholder;
		}
		if ( '' !== $default ) {
			$field['default'] = $default;
		}
		if ( 'email' === $type ) {
			$field['privacy_lookup'] = false;
		}

		$this->fields[] = $field;
		$this->remember( $name, $id );
		$this->element( $seg, $sev, $field, $codes );
	}

	/**
	 * @param array<string,mixed>                                 $seg
	 * @param array{label:string,remainder:string,source:string} $derived
	 */
	private function map_acceptance( array $seg, array $derived, string $content, bool $inner ): void {
		$name  = (string) $seg['name'];
		$id    = SchemaValidator::new_field_id();
		$codes = array();
		$sev   = 'info';
		$tname = '' !== $name ? $name : 'acceptance';
		$note  = function ( string $severity, string $code, string $message ) use ( &$codes, &$sev, $tname, $id ): void {
			$codes[] = $code;
			if ( array_search( $severity, self::SEVERITIES, true ) > array_search( $sev, self::SEVERITIES, true ) ) {
				$sev = $severity;
			}
			$this->finding( $severity, $code, $tname, $id, $message );
		};
		$opts  = self::options( (array) $seg['options'] );
		if ( $inner ) {
			$note( 'lost', 'tag_in_consent', __( 'Tags inside the consent text were removed.', 'form-captain' ) );
		}
		if ( isset( $opts['kv']['default'] ) || isset( $opts['flags']['checked'] ) ) {
			$note( 'adjusted', 'prechecked_removed', __( 'Consent is never preselected; the preselection was removed.', 'form-captain' ) );
		}
		$text = trim( HtmlSanitizer::clean( force_balance_tags( trim( $content ) ) ) );
		if ( '' !== trim( $content ) && trim( wp_kses( force_balance_tags( trim( $content ) ), HtmlSanitizer::allowed(), array( 'http', 'https', 'mailto' ) ) ) !== trim( force_balance_tags( trim( $content ) ) ) ) {
			$note( 'adjusted', 'html_sanitized', __( 'Disallowed HTML was removed from the consent text.', 'form-captain' ) );
		}
		if ( '' === self::plain( $text, 100000 ) ) {
			$text = esc_html( $derived['label'] );
		}
		if ( '' === self::plain( $text, 100000 ) ) {
			$text = esc_html__( 'I agree.', 'form-captain' );
			$note( 'adjusted', 'consent_text_default', __( 'No consent text found; a placeholder text was inserted – please replace it.', 'form-captain' ) );
		}
		$plain = self::plain( $text, 100000 );
		$label = '' !== $derived['label'] && '' !== trim( $content ) ? $derived['label'] : $plain;
		if ( '' === $derived['label'] || '' === trim( $content ) ) {
			$note( 'info', 'label_derived', __( 'Label derived from the consent text – please check.', 'form-captain' ) );
		}
		if ( mb_strlen( $label ) > 190 ) {
			$label = rtrim( mb_substr( $label, 0, 189 ) ) . '…';
			$note( 'adjusted', 'label_truncated', __( 'Label shortened to 190 characters.', 'form-captain' ) );
		}
		if ( isset( $opts['flags']['optional'] ) ) {
			$note( 'adjusted', 'type_mapped', __( 'Optional consent converted to a checkbox (a consent field is always required).', 'form-captain' ) );
			$field = array(
				'id'        => $id,
				'type'      => 'checkbox',
				'key'       => $this->key_for( $name, 'checkbox', $note ),
				'label'     => $label,
				'required'  => false,
				'css_class' => '',
				'options'   => array( array( 'value' => '1', 'label' => mb_strlen( $plain ) > 190 ? rtrim( mb_substr( $plain, 0, 189 ) ) . '…' : $plain ) ),
				'condition' => null,
			);
		} else {
			$field = array(
				'id'               => $id,
				'type'             => 'consent',
				'key'              => $this->key_for( $name, 'consent', $note ),
				'label'            => $label,
				'required'         => true,
				'css_class'        => '',
				'consent_text'     => $text,
				'privacy_link'     => false,
				'consent_revision' => 1,
				'condition'        => null,
			);
			$note( 'info', 'type_mapped', __( 'Acceptance converted to a required consent field (never preselected, revision 1).', 'form-captain' ) );
		}
		$this->fields[] = $field;
		$this->remember( $name, $id );
		$this->element( $seg, $sev, $field, $codes );
	}

	/** @param array<string,mixed> $seg */
	private function unsupported( array $seg ): void {
		$type = (string) $seg['type'];
		$name = '' !== (string) $seg['name'] ? (string) $seg['name'] : $type;
		if ( 'file' === $type || 'mfile' === $type ) {
			$this->drop( (string) $seg['raw'], $type, $name, 'field_unsupported', __( 'File uploads are not supported in the free version (available in Pro). The field was not converted.', 'form-captain' ) );
		} elseif ( 'quiz' === $type ) {
			$this->drop( (string) $seg['raw'], $type, $name, 'field_unsupported', __( 'Quiz fields are not supported; the field was removed.', 'form-captain' ) );
		} elseif ( str_contains( $type, 'captcha' ) || in_array( $type, array( 'turnstile', 'honeypot' ), true ) ) {
			$this->drop( (string) $seg['raw'], $type, $name, 'spam_protection_dropped', __( 'Spam protection of the source is not taken over; Form Captain uses honeypot, minimum time and rate limit.', 'form-captain' ) );
		} else {
			$this->drop( (string) $seg['raw'], $type, $name, 'field_unsupported', __( 'Unknown or unsupported tag (for example calculations or extensions); not converted.', 'form-captain' ) );
		}
	}

	/**
	 * Builds options from quoted values ("Label|value").
	 *
	 * @param array<int,string> $values
	 * @return array<int,array{value:string,label:string}>|null null if limit exceeded
	 */
	private function choices( array $values, callable $note ): ?array {
		if ( count( $values ) > Limits::get( 'options_per_field' ) ) {
			/* translators: %d: maximum number of options */
			$note( 'blocked', 'limit_exceeded', sprintf( __( 'More than %d options – the form cannot be imported (no partial import).', 'form-captain' ), Limits::get( 'options_per_field' ) ) );
			return null;
		}
		$out        = array();
		$seen       = array();
		$normalized = false;
		foreach ( $values as $n => $raw ) {
			$parts = explode( '|', $raw, 2 );
			$label = self::plain( $parts[0], 100000 );
			$value = isset( $parts[1] ) ? $parts[1] : $parts[0];
			$slug  = self::option_value( $value );
			if ( '' === $label ) {
				$label = $value;
			}
			$label = self::plain( $label, 100000 );
			if ( '' === $label ) {
				continue;
			}
			if ( mb_strlen( $label ) > 190 ) {
				$label = rtrim( mb_substr( $label, 0, 189 ) ) . '…';
				$note( 'adjusted', 'label_truncated', __( 'Option label shortened to 190 characters.', 'form-captain' ) );
			}
			if ( '' === $slug ) {
				$slug = 'option_' . ( $n + 1 );
			}
			$base = $slug;
			$k    = 2;
			while ( isset( $seen[ $slug ] ) ) {
				$suffix = '_' . ( $k++ );
				$slug   = substr( $base, 0, 64 - strlen( $suffix ) ) . $suffix;
			}
			if ( $slug !== $value ) {
				$normalized = true;
			}
			$seen[ $slug ] = true;
			$out[]         = array(
				'value' => $slug,
				'label' => $label,
			);
		}
		if ( $normalized ) {
			$note( 'info', 'option_value_normalized', __( 'Stored option values were normalized (letters, digits, "-" and "_").', 'form-captain' ) );
		}
		return $out;
	}

	public static function option_value( string $value ): string {
		$value = function_exists( 'remove_accents' ) ? remove_accents( wp_strip_all_tags( $value ) ) : $value;
		$value = (string) preg_replace( '/[^A-Za-z0-9_\-]+/', '-', trim( $value ) );
		$value = trim( $value, '-' );
		return substr( $value, 0, 64 );
	}

	/**
	 * Splits bare tag words into flags, key:value pairs, classes, size and unknown.
	 *
	 * @param array<int,string> $words
	 * @return array{flags:array<string,bool>,kv:array<string,string>,classes:array<int,string>,size:array{max:?int,rows:?int}|null,unknown:array<int,string>}
	 */
	private static function options( array $words ): array {
		$known_flags = array( 'placeholder', 'watermark', 'include_blank', 'multiple', 'use_label_element', 'label_first', 'exclusive', 'free_text', 'first_as_label', 'optional', 'checked', 'invert' );
		$known_kv    = array( 'minlength', 'maxlength', 'min', 'max', 'step', 'default', 'id', 'data', 'pattern' );
		$out         = array(
			'flags'   => array(),
			'kv'      => array(),
			'classes' => array(),
			'size'    => null,
			'unknown' => array(),
		);
		foreach ( $words as $w ) {
			$w = (string) $w;
			if ( preg_match( '/^(\d*)\/(\d+)$/', $w, $m ) ) {
				$out['size'] = array( 'max' => (int) $m[2], 'rows' => null );
				continue;
			}
			if ( preg_match( '/^(\d*)x(\d*)(?:\/(\d+))?$/', $w, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
				$out['size'] = array( 'max' => isset( $m[3] ) ? (int) $m[3] : null, 'rows' => '' !== $m[2] ? (int) $m[2] : null );
				continue;
			}
			if ( preg_match( '/^\d+$/', $w ) ) {
				continue; // Width only: layout, ignored.
			}
			$pos = strpos( $w, ':' );
			if ( false !== $pos ) {
				$k = strtolower( substr( $w, 0, $pos ) );
				$v = substr( $w, $pos + 1 );
				if ( 'class' === $k ) {
					$out['classes'][] = $v;
				} elseif ( in_array( $k, $known_kv, true ) ) {
					$out['kv'][ $k ] = $v;
				} else {
					$out['unknown'][] = sanitize_key( $k );
				}
				continue;
			}
			$lw = strtolower( $w );
			if ( in_array( $lw, $known_flags, true ) ) {
				$out['flags'][ $lw ] = true;
			} else {
				$out['unknown'][] = sanitize_key( $lw );
			}
		}
		foreach ( array( 'use_label_element', 'label_first', 'invert' ) as $layout ) {
			unset( $out['flags'][ $layout ] );
		}
		$out['unknown'] = array_values( array_unique( array_filter( $out['unknown'] ) ) );
		return $out;
	}

	// Keys.

	public static function normalize_key( string $name ): string {
		$key = strtolower( str_replace( '-', '_', $name ) );
		$key = (string) preg_replace( '/[^a-z0-9_]/', '', $key );
		$key = (string) preg_replace( '/^[^a-z]+/', '', $key );
		return substr( $key, 0, 40 );
	}

	private function key_for( string $name, string $type, callable $note ): string {
		$base = self::normalize_key( $name );
		if ( '' === $base ) {
			$base = $type;
		}
		$key = $this->unique_key( $base );
		if ( $key !== strtolower( str_replace( '-', '_', $name ) ) && '' !== $name ) {
			$note( 'info', 'key_renamed', __( 'Field key normalized or made unique.', 'form-captain' ) );
		}
		return $key;
	}

	private function unique_key( string $base ): string {
		$base = substr( $base, 0, 40 );
		$key  = $base;
		$n    = 2;
		while ( isset( $this->keys[ $key ] ) ) {
			$suffix = '_' . ( $n++ );
			$key    = substr( $base, 0, 40 - strlen( $suffix ) ) . $suffix;
		}
		$this->keys[ $key ] = true;
		return $key;
	}

	private function remember( string $name, string $id ): void {
		if ( '' === $name ) {
			return;
		}
		$source = mb_substr( $name, 0, 64 );
		$n      = 2;
		while ( isset( $this->source_map[ $source ] ) ) {
			$source = mb_substr( $name, 0, 60 ) . '#' . ( $n++ );
		}
		$this->source_map[ $source ] = $id;
	}

	// Report helpers.

	private function drop( string $raw, string $type, string $name, string $code, string $message ): void {
		$this->finding( 'lost', $code, '' !== $name ? $name : $type, null, $message );
		$this->elements[] = array(
			'tag'         => self::report_tag( $raw ),
			'source_type' => sanitize_key( $type ),
			'name'        => self::report_name( $name ),
			'outcome'     => 'dropped',
			'target_type' => '',
			'key'         => '',
			'label'       => '',
			'field_id'    => '',
			'codes'       => array( $code ),
		);
	}

	/**
	 * @param array<string,mixed>      $seg
	 * @param array<string,mixed>|null $field
	 * @param array<int,string>        $codes
	 */
	private function element( array $seg, string $severity, ?array $field, array $codes, string $target = '' ): void {
		$outcome          = null === $field ? ( 'blocked' === $severity || 'lost' === $severity ? 'dropped' : 'setting' ) : ( in_array( $severity, array( 'adjusted', 'lost' ), true ) ? 'adjusted' : 'taken' );
		$this->elements[] = array(
			'tag'         => self::report_tag( (string) $seg['raw'] ),
			'source_type' => sanitize_key( (string) $seg['type'] ),
			'name'        => self::report_name( (string) $seg['name'] ),
			'outcome'     => $outcome,
			'target_type' => null !== $field ? (string) $field['type'] : $target,
			'key'         => null !== $field ? (string) $field['key'] : '',
			'label'       => null !== $field ? (string) $field['label'] : '',
			'field_id'    => null !== $field ? (string) $field['id'] : '',
			'codes'       => array_values( array_unique( $codes ) ),
		);
	}

	private function finding( string $severity, string $code, string $tag, ?string $field_id, string $message ): void {
		$this->findings[] = array(
			'severity'        => $severity,
			'code'            => $code,
			'source_tag_name' => self::report_name( $tag ),
			'target_field_id' => $field_id,
			'message'         => $message,
		);
	}

	private static function report_tag( string $raw ): string {
		$raw = (string) preg_replace( '/\s+/', ' ', $raw );
		return mb_strlen( $raw ) > self::TAG_REPORT_CHARS ? mb_substr( $raw, 0, self::TAG_REPORT_CHARS - 1 ) . '…' : $raw;
	}

	private static function report_name( string $name ): string {
		return mb_substr( (string) preg_replace( '/[^A-Za-z0-9_\-#]/', '', $name ), 0, 64 );
	}

	/**
	 * @return array{fields:array<int,array<string,mixed>>,findings:array<int,array<string,mixed>>,elements:array<int,array<string,mixed>>,source_map:array<string,string>,submit_label:string,status:string,counts:array<string,int>}
	 */
	private function result(): array {
		$worst = 'info';
		foreach ( $this->findings as $f ) {
			if ( array_search( $f['severity'], self::SEVERITIES, true ) > array_search( $worst, self::SEVERITIES, true ) ) {
				$worst = (string) $f['severity'];
			}
		}
		$status  = array(
			'info'     => 'complete',
			'adjusted' => 'adjusted',
			'lost'     => 'lossy',
			'blocked'  => 'blocked',
		)[ $worst ];
		$blocked = 'blocked' === $status;
		// Email: first required email field (else first email field) is used for privacy lookups.
		$fields = $blocked ? array() : $this->fields;
		$lookup = null;
		foreach ( $fields as $i => $f ) {
			if ( 'email' === $f['type'] && ( null === $lookup || ( ! empty( $f['required'] ) && empty( $fields[ $lookup ]['required'] ) ) ) ) {
				$lookup = $i;
			}
		}
		if ( null !== $lookup ) {
			$fields[ $lookup ]['privacy_lookup'] = true;
		}
		$counts = array(
			'total'    => count( $this->elements ),
			'taken'    => 0,
			'adjusted' => 0,
			'dropped'  => 0,
		);
		foreach ( $this->elements as $e ) {
			if ( isset( $counts[ $e['outcome'] ] ) ) {
				++$counts[ $e['outcome'] ];
			}
		}
		return array(
			'fields'       => $fields,
			'findings'     => $this->findings,
			'elements'     => $this->elements,
			'source_map'   => $blocked ? array() : $this->source_map,
			'submit_label' => $this->submit_label,
			'status'       => $status,
			'counts'       => $counts,
		);
	}

	private static function plain( string $value, int $max ): string {
		$value = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		$value = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $value ) );
		return mb_substr( $value, 0, $max );
	}
}
