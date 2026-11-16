<?php
/**
 * Maps PHP's $_FILES shape for the form inputs `fc[<field_id>]` to field_id => file (contract 1.1.0).
 *
 * The core never moves, stores or opens uploaded files. It only hands the temporary file data of a
 * field to the validator of the extension that registered the field type
 * (form_captain_validate_field_v1). Fields without an extension validator ignore files.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Support;

defined( 'ABSPATH' ) || exit;

final class UploadedFiles {

	/** Maximum number of files accepted per request; more are ignored. */
	public const MAX_FILES = 20;

	/**
	 * @param array<string,mixed> $files $_FILES or WP_REST_Request::get_file_params()
	 * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	public static function by_field( array $files ): array {
		$group = $files['fc'] ?? null;
		if ( ! is_array( $group ) || ! is_array( $group['name'] ?? null ) ) {
			return array();
		}
		$out = array();
		foreach ( $group['name'] as $field_id => $name ) {
			if ( count( $out ) >= self::MAX_FILES ) {
				break;
			}
			if ( ! is_string( $field_id ) || ! preg_match( '/^f_[a-z0-9]{6,32}$/', $field_id ) || ! is_string( $name ) ) {
				continue; // Nested or multiple files per field are not part of contract 1.1.0.
			}
			$out[ $field_id ] = array(
				'name'     => $name,
				'type'     => is_string( $group['type'][ $field_id ] ?? null ) ? $group['type'][ $field_id ] : '',
				'tmp_name' => is_string( $group['tmp_name'][ $field_id ] ?? null ) ? $group['tmp_name'][ $field_id ] : '',
				'error'    => (int) ( $group['error'][ $field_id ] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $group['size'][ $field_id ] ?? 0 ),
			);
		}
		return $out;
	}
}
