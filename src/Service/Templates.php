<?php
/**
 * Versioned form templates (P2-04). JSON files under templates/forms contain English
 * source strings; this loader translates them via __() when loading.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Service;

final class Templates {

	public const KEYS = array( 'contact', 'quote', 'support' );

	/**
	 * Metadata of all templates.
	 *
	 * @return array<string,array{key:string,title:string,description:string,template_version:string,fields:int}>
	 */
	public function all(): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$tpl = $this->get( $key );
			if ( null === $tpl ) {
				continue;
			}
			$out[ $key ] = array(
				'key'              => $key,
				'title'            => $tpl['title'],
				'description'      => $tpl['description'],
				'template_version' => $tpl['template_version'],
				'fields'           => count( $tpl['schema']['fields'] ?? array() ),
			);
		}
		return $out;
	}

	/**
	 * Loads one template with translated strings.
	 *
	 * @return array{key:string,template_version:string,title:string,description:string,schema:array<string,mixed>,settings:array<string,mixed>}|null
	 */
	public function get( string $key ): ?array {
		if ( ! in_array( $key, self::KEYS, true ) ) {
			return null;
		}
		$file = FORM_CAPTAIN_DIR . 'templates/forms/' . $key . '.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true, 32 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || ! is_array( $data['schema'] ?? null ) ) {
			return null;
		}
		$schema = $data['schema'];
		foreach ( $schema['fields'] as $i => $field ) {
			foreach ( array( 'label', 'help', 'placeholder', 'consent_text' ) as $prop ) {
				if ( isset( $field[ $prop ] ) && is_string( $field[ $prop ] ) ) {
					$schema['fields'][ $i ][ $prop ] = self::t( $field[ $prop ] );
				}
			}
			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $j => $opt ) {
					$schema['fields'][ $i ]['options'][ $j ]['label'] = self::t( (string) ( $opt['label'] ?? '' ) );
				}
			}
		}
		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		if ( isset( $settings['notification']['subject'] ) ) {
			$settings['notification']['subject'] = self::t( (string) $settings['notification']['subject'] );
		}
		if ( isset( $settings['confirmation']['message'] ) ) {
			$settings['confirmation']['message'] = self::t( (string) $settings['confirmation']['message'] );
		}
		return array(
			'key'              => $key,
			'template_version' => (string) ( $data['template_version'] ?? '1.0.0' ),
			'title'            => self::t( (string) ( $data['title'] ?? $key ) ),
			'description'      => self::t( (string) ( $data['description'] ?? '' ) ),
			'schema'           => $schema,
			'settings'         => $settings,
		);
	}

	/** Translates a known English template string; unknown strings stay unchanged. */
	private static function t( string $english ): string {
		$map = self::strings();
		return $map[ $english ] ?? $english;
	}

	/**
	 * All template strings as literal __() calls so that they end up in the POT file.
	 *
	 * @return array<string,string>
	 */
	private static function strings(): array {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		$map = array(
			'Contact'                         => __( 'Contact', 'form-captain' ),
			'Name, email and message – the classic contact form.' => __( 'Name, email and message – the classic contact form.', 'form-captain' ),
			'Name'                            => __( 'Name', 'form-captain' ),
			'Email address'                   => __( 'Email address', 'form-captain' ),
			'We will reply to this address.'  => __( 'We will reply to this address.', 'form-captain' ),
			'name@example.com'                => __( 'name@example.com', 'form-captain' ),
			'Subject'                         => __( 'Subject', 'form-captain' ),
			'Message'                         => __( 'Message', 'form-captain' ),
			'Privacy'                         => __( 'Privacy', 'form-captain' ),
			'I agree that my details will be stored to answer my request.' => __( 'I agree that my details will be stored to answer my request.', 'form-captain' ),
			/* translators: {form_title} is a placeholder and must not be translated. */
			'New message: {form_title}'       => __( 'New message: {form_title}', 'form-captain' ),
			'Thank you! We will get back to you soon.' => __( 'Thank you! We will get back to you soon.', 'form-captain' ),
			'Quote request'                   => __( 'Quote request', 'form-captain' ),
			'Collect project details for a quote.' => __( 'Collect project details for a quote.', 'form-captain' ),
			'Company'                         => __( 'Company', 'form-captain' ),
			'Phone'                           => __( 'Phone', 'form-captain' ),
			'Service'                         => __( 'Service', 'form-captain' ),
			'Consulting'                      => __( 'Consulting', 'form-captain' ),
			'Implementation'                  => __( 'Implementation', 'form-captain' ),
			'Support and maintenance'         => __( 'Support and maintenance', 'form-captain' ),
			'Other'                           => __( 'Other', 'form-captain' ),
			'Please describe the service'     => __( 'Please describe the service', 'form-captain' ),
			'Budget'                          => __( 'Budget', 'form-captain' ),
			'Up to 1,000'                     => __( 'Up to 1,000', 'form-captain' ),
			'1,000 to 5,000'                  => __( '1,000 to 5,000', 'form-captain' ),
			'More than 5,000'                 => __( 'More than 5,000', 'form-captain' ),
			'Desired start'                   => __( 'Desired start', 'form-captain' ),
			'Project details'                 => __( 'Project details', 'form-captain' ),
			'I agree that my details will be stored to prepare a quote.' => __( 'I agree that my details will be stored to prepare a quote.', 'form-captain' ),
			/* translators: {form_title} is a placeholder and must not be translated. */
			'New quote request: {form_title}' => __( 'New quote request: {form_title}', 'form-captain' ),
			'Thank you for your request! We will send you a quote shortly.' => __( 'Thank you for your request! We will send you a quote shortly.', 'form-captain' ),
			'Support request'                 => __( 'Support request', 'form-captain' ),
			'Let customers describe a problem with priority and category.' => __( 'Let customers describe a problem with priority and category.', 'form-captain' ),
			'Category'                        => __( 'Category', 'form-captain' ),
			'Question'                        => __( 'Question', 'form-captain' ),
			'Problem or error'                => __( 'Problem or error', 'form-captain' ),
			'Account'                         => __( 'Account', 'form-captain' ),
			'Priority'                        => __( 'Priority', 'form-captain' ),
			'Low'                             => __( 'Low', 'form-captain' ),
			'Normal'                          => __( 'Normal', 'form-captain' ),
			'High'                            => __( 'High', 'form-captain' ),
			'Description'                     => __( 'Description', 'form-captain' ),
			'Please describe what happened and the steps to reproduce it.' => __( 'Please describe what happened and the steps to reproduce it.', 'form-captain' ),
			'I agree that my details will be stored to process my support request.' => __( 'I agree that my details will be stored to process my support request.', 'form-captain' ),
			/* translators: {field:subject} is a placeholder and must not be translated. */
			'Support: {field:subject}'        => __( 'Support: {field:subject}', 'form-captain' ),
			'Thank you! Your support request has been received.' => __( 'Thank you! Your support request has been received.', 'form-captain' ),
		);
		return $map;
	}
}
