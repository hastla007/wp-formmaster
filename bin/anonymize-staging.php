<?php
/**
 * Anonymizes Form Captain data on a STAGING copy (P9-13, docs/BETRIEB.md section 4).
 *
 * Usage (on the staging site only):
 *   wp eval-file bin/anonymize-staging.php yes
 *
 * Refuses to run when wp_get_environment_type() is "production" unless
 * the argument "not-production" is passed as well. Works directly on the tables, so it does
 * not depend on the installed Form Captain version (schema 1 and 2).
 *
 * What it changes:
 * - entry values: e-mail, phone, text, textarea, hidden and unknown field types are replaced
 *   (length kept up to 500 characters); choice, number, date and consent values are kept
 *   so conditions, exports and limits stay testable;
 * - entries: user_id and context_json removed; notes: text replaced, user_id removed;
 * - jobs: payload/result/error texts removed, open jobs cancelled (staging must not send);
 * - log messages removed, rate limits emptied, file names replaced;
 * - e-mail addresses in form settings replaced by staging@example.invalid.
 * Prints counts only, never values.
 *
 * @package FormCaptain
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.WP.AlternativeFunctions

defined( 'ABSPATH' ) || exit;

$fc_anon_args = isset( $args ) && is_array( $args ) ? $args : array();
if ( ! in_array( 'yes', $fc_anon_args, true ) ) {
	fwrite( STDERR, "Refusing to run without the argument yes. This irreversibly anonymizes Form Captain data.\n" );
	return;
}
if ( 'production' === wp_get_environment_type() && ! in_array( 'not-production', $fc_anon_args, true ) ) {
	fwrite( STDERR, "Refusing to run: WP_ENVIRONMENT_TYPE is production. Set it to staging on the copy (or pass the argument not-production if you are sure).\n" );
	return;
}

global $wpdb;
$fc_t = static fn( string $name ): string => $wpdb->prefix . 'fc_' . $name;

/** Field types whose values are kept (no free text, needed for logic tests). */
$fc_keep_types = array( 'select', 'radio', 'checkbox', 'number', 'date', 'consent', 'heading', 'html' );

// field_id => type, from all stored schemas (published snapshots and drafts).
$fc_types = array();
foreach ( array( $fc_t( 'form_versions' ), $fc_t( 'forms' ) ) as $fc_table ) {
	foreach ( (array) $wpdb->get_col( "SELECT schema_json FROM {$fc_table}" ) as $fc_json ) {
		$fc_schema = json_decode( (string) $fc_json, true );
		foreach ( (array) ( $fc_schema['fields'] ?? array() ) as $fc_field ) {
			if ( isset( $fc_field['id'], $fc_field['type'] ) ) {
				$fc_types[ (string) $fc_field['id'] ] = (string) $fc_field['type'];
			}
		}
	}
}

$fc_counts = array(
	'values_replaced' => 0,
	'values_kept'     => 0,
);
$fc_last   = 0;
do {
	$fc_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, entry_id, field_id, value FROM {$fc_t('entry_values')} WHERE id > %d ORDER BY id ASC LIMIT 500", $fc_last ), ARRAY_A );
	foreach ( (array) $fc_rows as $fc_row ) {
		$fc_last = (int) $fc_row['id'];
		$fc_type = $fc_types[ $fc_row['field_id'] ] ?? 'unknown';
		if ( in_array( $fc_type, $fc_keep_types, true ) || null === $fc_row['value'] || '' === $fc_row['value'] ) {
			++$fc_counts['values_kept'];
			continue;
		}
		$fc_ref = $fc_row['entry_id'] . '-' . substr( md5( $fc_row['field_id'] ), 0, 6 );
		switch ( $fc_type ) {
			case 'email':
				$fc_new = 'anon-' . $fc_ref . '@example.invalid';
				break;
			case 'phone':
				$fc_new = '+00 000 ' . str_pad( (string) ( (int) $fc_row['entry_id'] % 1000000 ), 6, '0', STR_PAD_LEFT );
				break;
			default:
				$fc_len = min( 500, max( 8, mb_strlen( (string) $fc_row['value'] ) ) );
				$fc_new = mb_substr( str_repeat( 'Anonymized text ' . $fc_ref . '. ', (int) ceil( $fc_len / 20 ) + 1 ), 0, $fc_len );
		}
		$wpdb->update( $fc_t( 'entry_values' ), array( 'value' => $fc_new ), array( 'id' => $fc_last ) );
		++$fc_counts['values_replaced'];
	}
} while ( ! empty( $fc_rows ) );

$fc_counts['entries']   = (int) $wpdb->query( "UPDATE {$fc_t('entries')} SET user_id = NULL, context_json = NULL" );
$fc_counts['notes']     = (int) $wpdb->query( "UPDATE {$fc_t('entry_notes')} SET message = CONCAT('Note ', id, ' anonymized.'), user_id = NULL" );
$fc_counts['jobs_open'] = (int) $wpdb->query( "UPDATE {$fc_t('jobs')} SET status = 'cancelled', last_error_code = 'staging_anonymized' WHERE status IN ('pending','running','failed','paused')" );
$wpdb->query( "UPDATE {$fc_t('jobs')} SET payload_json = NULL, result_json = NULL, last_error_message = NULL, lock_token = NULL" );
$fc_counts['log']   = (int) $wpdb->query( "UPDATE {$fc_t('log')} SET message = ''" );
$fc_counts['files'] = (int) $wpdb->query( "UPDATE {$fc_t('files')} SET original_name = CONCAT('file-', id)" );
$wpdb->query( "DELETE FROM {$fc_t('rate_limits')}" );

$fc_counts['form_settings'] = 0;
foreach ( (array) $wpdb->get_results( "SELECT id, settings_json FROM {$fc_t('forms')}", ARRAY_A ) as $fc_form ) {
	$fc_new = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', 'staging@example.invalid', (string) $fc_form['settings_json'] );
	if ( $fc_new !== $fc_form['settings_json'] ) {
		$wpdb->update( $fc_t( 'forms' ), array( 'settings_json' => $fc_new ), array( 'id' => (int) $fc_form['id'] ) );
		++$fc_counts['form_settings'];
	}
}

foreach ( $fc_counts as $fc_key => $fc_value ) {
	echo esc_html( $fc_key ) . ': ' . (int) $fc_value . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
echo "Done. Form Captain data is anonymized. WordPress users, comments and other plugins are not touched.\n";
