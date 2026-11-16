<?php
/**
 * Table definitions (spec: docs/spec/datenmodell.md).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Database;

final class Schema {

	/**
	 * Returns CREATE TABLE statements for dbDelta() for schema version 1.
	 *
	 * @return array<string,string> table => sql
	 */
	public static function definitions(): array {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$t = static fn( string $n ): string => Tables::name( $n );

		return array(
			Tables::FORMS         => "CREATE TABLE {$t(Tables::FORMS)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(16) NOT NULL,
  title varchar(190) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  schema_json longtext NOT NULL,
  schema_version int(10) unsigned NOT NULL DEFAULT 1,
  revision int(10) unsigned NOT NULL DEFAULT 1,
  published_version_id bigint(20) unsigned DEFAULT NULL,
  settings_json longtext NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_gmt datetime NOT NULL,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY status (status),
  KEY updated (updated_gmt)
) ENGINE=InnoDB $c;",
			Tables::FORM_VERSIONS => "CREATE TABLE {$t(Tables::FORM_VERSIONS)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  version_no int(10) unsigned NOT NULL,
  schema_json longtext NOT NULL,
  schema_hash char(64) NOT NULL,
  created_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY form_version (form_id,version_no)
) ENGINE=InnoDB $c;",
			Tables::ENTRIES       => "CREATE TABLE {$t(Tables::ENTRIES)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  form_version_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  previous_status varchar(20) DEFAULT NULL,
  request_key char(64) NOT NULL,
  spam_reason varchar(40) DEFAULT NULL,
  source_url varchar(2048) DEFAULT NULL,
  source_post_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned DEFAULT NULL,
  context_json text DEFAULT NULL,
  consent_json text DEFAULT NULL,
  created_gmt datetime NOT NULL,
  updated_gmt datetime NOT NULL,
  trashed_gmt datetime DEFAULT NULL,
  expires_gmt datetime DEFAULT NULL,
  anonymized_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY request_key (request_key),
  KEY form_status_created (form_id,status,created_gmt),
  KEY status_created (status,created_gmt),
  KEY expires (expires_gmt),
  KEY trashed (trashed_gmt)
) ENGINE=InnoDB $c;",
			Tables::ENTRY_VALUES  => "CREATE TABLE {$t(Tables::ENTRY_VALUES)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  field_id varchar(40) NOT NULL,
  value longtext NOT NULL,
  is_multi tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY entry_field (entry_id,field_id),
  KEY field_value (field_id,value(64))
) ENGINE=InnoDB $c;",
			Tables::ENTRY_NOTES   => "CREATE TABLE {$t(Tables::ENTRY_NOTES)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(20) NOT NULL DEFAULT 'note',
  message text NOT NULL,
  created_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry (entry_id,created_gmt)
) ENGINE=InnoDB $c;",
			Tables::JOBS          => "CREATE TABLE {$t(Tables::JOBS)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(60) NOT NULL,
  entry_id bigint(20) unsigned DEFAULT NULL,
  form_id bigint(20) unsigned DEFAULT NULL,
  idempotency_key varchar(191) NOT NULL,
  payload_json longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  max_attempts smallint(5) unsigned NOT NULL DEFAULT 8,
  run_after_gmt datetime NOT NULL,
  locked_until_gmt datetime DEFAULT NULL,
  lock_token char(32) DEFAULT NULL,
  last_error_code varchar(60) DEFAULT NULL,
  last_error_message varchar(255) DEFAULT NULL,
  result_json text DEFAULT NULL,
  created_gmt datetime NOT NULL,
  updated_gmt datetime NOT NULL,
  finished_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY runnable (status,run_after_gmt),
  KEY entry (entry_id)
) ENGINE=InnoDB $c;",
			Tables::FILES         => "CREATE TABLE {$t(Tables::FILES)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  field_id varchar(40) NOT NULL,
  storage_key varchar(191) NOT NULL,
  original_name varchar(255) NOT NULL,
  mime varchar(100) NOT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'quarantine',
  created_gmt datetime NOT NULL,
  expires_gmt datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY storage_key (storage_key),
  KEY entry (entry_id)
) ENGINE=InnoDB $c;",
			Tables::RATE_LIMITS   => "CREATE TABLE {$t(Tables::RATE_LIMITS)} (
  bucket char(64) NOT NULL,
  form_id bigint(20) unsigned NOT NULL DEFAULT 0,
  hits int(10) unsigned NOT NULL DEFAULT 0,
  window_start_gmt datetime NOT NULL,
  PRIMARY KEY  (bucket),
  KEY window_start (window_start_gmt)
) ENGINE=InnoDB $c;",
			Tables::LOG           => "CREATE TABLE {$t(Tables::LOG)} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level varchar(10) NOT NULL,
  code varchar(60) NOT NULL,
  correlation_id char(32) DEFAULT NULL,
  form_id bigint(20) unsigned DEFAULT NULL,
  entry_id bigint(20) unsigned DEFAULT NULL,
  job_id bigint(20) unsigned DEFAULT NULL,
  message varchar(255) NOT NULL DEFAULT '',
  created_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created (created_gmt),
  KEY correlation (correlation_id)
) ENGINE=InnoDB $c;",
		);
	}
}
