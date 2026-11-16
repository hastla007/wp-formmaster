<?php
/**
 * Table name registry.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Database;

final class Tables {

	public const FORMS         = 'fc_forms';
	public const FORM_VERSIONS = 'fc_form_versions';
	public const ENTRIES       = 'fc_entries';
	public const ENTRY_VALUES  = 'fc_entry_values';
	public const ENTRY_NOTES   = 'fc_entry_notes';
	public const JOBS          = 'fc_jobs';
	public const FILES         = 'fc_files';
	public const RATE_LIMITS   = 'fc_rate_limits';
	public const LOG           = 'fc_log';

	public const ALL = array(
		self::FORMS,
		self::FORM_VERSIONS,
		self::ENTRIES,
		self::ENTRY_VALUES,
		self::ENTRY_NOTES,
		self::JOBS,
		self::FILES,
		self::RATE_LIMITS,
		self::LOG,
	);

	public static function name( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}
}
