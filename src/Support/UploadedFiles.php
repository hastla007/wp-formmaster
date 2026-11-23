<?php
/**
 * Maps PHP's $_FILES shape for the form inputs `fc[<field_id>]` (one file, contract 1.1.0) and
 * `fc[<field_id>][]` (several files, contract 1.3.0, SET-FC-39) to field_id => files.
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

	/** Maximum number of files accepted per request (all fields together); more are ignored. */
	public const MAX_FILES = 20;

	/**
	 * First file of each field (contract 1.1.0 shape, context key `file`).
	 *
	 * @param array<string,mixed> $files $_FILES or WP_REST_Request::get_file_params()
	 * @return array<string,array{name:string,type:string,tmp_name:string,error:int,size:int}>
	 */
	public static function by_field( array $files ): array {
		$out = array();
		foreach ( self::lists_by_field( $files ) as $field_id => $list ) {
			$out[ $field_id ] = $list[0];
		}
		return $out;
	}

	/**
	 * All files of each field (contract 1.3.0, SET-FC-39): `fc[<field_id>]` gives a list with one
	 * file, `fc[<field_id>][]` (input with `multiple`) a list with every file in the order sent.
	 * Deeper nesting and invalid field IDs are ignored; at most MAX_FILES files per request.
	 *
	 * @param array<string,mixed> $files $_FILES or WP_REST_Request::get_file_params()
	 * @return array<string,list<array{name:string,type:string,tmp_name:string,error:int,size:int}>>
	 */
	public static function lists_by_field( array $files ): array {
		$group = $files['fc'] ?? null;
		if ( ! is_array( $group ) || ! is_array( $group['name'] ?? null ) ) {
			return array();
		}
		$out   = array();
		$count = 0;
		foreach ( $group['name'] as $field_id => $name ) {
			if ( ! is_string( $field_id ) || ! preg_match( '/^f_[a-z0-9]{6,32}$/', $field_id ) ) {
				continue;
			}
			$indexes = is_string( $name ) ? array( null ) : ( is_array( $name ) && array_is_list( $name ) ? array_keys( $name ) : array() );
			foreach ( $indexes as $i ) {
				if ( $count >= self::MAX_FILES ) {
					break 2;
				}
				$file = self::file_at( $group, $field_id, $i );
				if ( null !== $file ) {
					$out[ $field_id ][] = $file;
					++$count;
				}
			}
		}
		return $out;
	}

	/**
	 * One file from the $_FILES columns; $i = null for a single file input.
	 *
	 * @param array<string,mixed> $group
	 * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
	 */
	private static function file_at( array $group, string $field_id, ?int $i ): ?array {
		$col  = static function ( string $key ) use ( $group, $field_id, $i ): mixed {
			$value = $group[ $key ][ $field_id ] ?? null;
			return null === $i ? $value : ( is_array( $value ) ? ( $value[ $i ] ?? null ) : null );
		};
		$name = $col( 'name' );
		if ( ! is_string( $name ) ) {
			return null; // Deeper nesting is not part of the contract.
		}
		return array(
			'name'     => $name,
			'type'     => is_string( $col( 'type' ) ) ? $col( 'type' ) : '',
			'tmp_name' => is_string( $col( 'tmp_name' ) ) ? $col( 'tmp_name' ) : '',
			'error'    => is_numeric( $col( 'error' ) ) ? (int) $col( 'error' ) : UPLOAD_ERR_NO_FILE,
			'size'     => is_numeric( $col( 'size' ) ) ? (int) $col( 'size' ) : 0,
		);
	}
}
