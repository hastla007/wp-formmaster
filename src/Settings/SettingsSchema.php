<?php
/**
 * Schema of every global option (docs/spec/einstellungen.md, P2-17, P2-19, P5-18, P5-19, P5-21).
 *
 * Each option has type, default (from Settings::defaults()), sanitizer/validator, tab and capability.
 * Unknown keys are dropped; invalid values are rejected with a translated error and the stored value
 * stays unchanged.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Settings;

use FormCaptain\Install\Capabilities;
use FormCaptain\Support\Clock;

final class SettingsSchema {

	/** Tabs in display order. `access` and `status` are not stored in the settings option. */
	public const TABS = array( 'general', 'appearance', 'email', 'privacy', 'spam', 'export', 'access', 'status' );

	/** Option-backed groups. */
	public const GROUPS = array( 'general', 'appearance', 'email', 'privacy', 'spam', 'export' );

	/** Accepted confirmation words for "delete data on uninstall" (datenschutz.md 10). */
	public const CONFIRM_WORDS = array( 'DELETE', 'LÖSCHEN', 'LOESCHEN' );

	/** Target of the "Learn more" link below the hint rows; empty = no link (product page still open). */
	public const PRO_INFO_URL = '';

	public static function capability(): string {
		return Capabilities::MANAGE_SETTINGS;
	}

	/** @return array<string,string> tab => label */
	public static function tab_labels(): array {
		return array(
			'general'    => __( 'General', 'form-captain' ),
			'appearance' => __( 'Appearance', 'form-captain' ),
			'email'      => __( 'Email', 'form-captain' ),
			'privacy'    => __( 'Privacy & storage', 'form-captain' ),
			'spam'       => __( 'Spam protection', 'form-captain' ),
			'export'     => __( 'Export', 'form-captain' ),
		) + self::extension_tabs() + array(
			'access' => __( 'Access & roles', 'form-captain' ),
			'status' => __( 'System status', 'form-captain' ),
		);
	}

	/**
	 * Definitions keyed by "group.key".
	 *
	 * Keys: type (bool|int|enum|string|email|hex|text|datetime|attachment), default, tab, label, help,
	 * options (enum), min/max (int), max_length (string/text), internal (not user editable),
	 * secret (masked in the change log, never exported), exportable (false = not in the settings export),
	 * optional (email: empty allowed), keep_lines (text: keep line order and duplicates).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$d   = Settings::defaults();
		$out = array(
			'general.setup_completed'           => array(
				'type'  => 'bool',
				'label' => __( 'Initial setup completed', 'form-captain' ),
				'help'  => __( 'Hides the setup assistant on the dashboard.', 'form-captain' ),
			),
			'general.show_pro_hints'            => array(
				'type'  => 'bool',
				'label' => __( 'Show occasional hints about Form Captain Pro', 'form-captain' ),
				'help'  => __( 'Hints are rare and can be dismissed. Settings sections end with grey text rows naming settings that Form Captain Pro adds. Free features never depend on Pro.', 'form-captain' ),
			),
			'general.log_level'                 => array(
				'type'    => 'enum',
				'options' => array(
					'error'   => __( 'Errors only', 'form-captain' ),
					'warning' => __( 'Warnings and errors', 'form-captain' ),
					'info'    => __( 'Information, warnings and errors (recommended)', 'form-captain' ),
					'debug'   => __( 'Everything including debug details', 'form-captain' ),
				),
				'label'   => __( 'Log level', 'form-captain' ),
				'help'    => __( 'Which events Form Captain writes to its own log. The log never contains submitted values, e-mail or IP addresses. Use the debug level only while troubleshooting; with WP_DEBUG, debug entries are always written.', 'form-captain' ),
			),
			'general.text_max'                  => array(
				'type'  => 'int',
				'min'   => 100,
				'max'   => \FormCaptain\Support\Limits::SETTABLE['text_max'][2],
				'label' => __( 'Maximum length of single-line fields (characters)', 'form-captain' ),
				'help'  => __( 'Upper limit for the length you can allow in text fields. Each field keeps its own maximum within this limit.', 'form-captain' ),
			),
			'general.textarea_max'              => array(
				'type'  => 'int',
				'min'   => 500,
				'max'   => \FormCaptain\Support\Limits::SETTABLE['textarea_max'][2],
				'label' => __( 'Maximum length of paragraph fields (characters)', 'form-captain' ),
				'help'  => __( 'Upper limit for long free texts such as applications. The accepted request size grows with this value.', 'form-captain' ),
			),
			'general.country_profile'           => array(
				'type'     => 'string',
				'internal' => true,
				'label'    => __( 'Applied country profile', 'form-captain' ),
			),
			'general.entry_number_format'       => array(
				'type'        => 'string',
				'max_length'  => 30,
				'allow_empty' => true,
				'validate'    => array( \FormCaptain\Service\EntryNumbers::class, 'validate_format' ),
				'label'       => __( 'Reference number format', 'form-captain' ),
				'help'        => __( 'Gives every new submission a reference number, for example REQ-{year}-{number}. {number} is the running number, {year} the current year. Use {entry_number} in emails and the success message. Empty = no reference numbers.', 'form-captain' ),
			),
			'general.entry_number_digits'       => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 10,
				'label' => __( 'Digits of the running number', 'form-captain' ),
				'help'  => __( 'The number is filled with leading zeros, e.g. 4 digits give 0042.', 'form-captain' ),
			),
			'general.entry_number_yearly_reset' => array(
				'type'  => 'bool',
				'label' => __( 'Restart the running number every year', 'form-captain' ),
				'help'  => __( 'On 1 January (time zone of the website) the running number starts again at 1. Use {year} in the format so numbers stay unique.', 'form-captain' ),
			),
			'appearance.mode'                   => array(
				'type'    => 'enum',
				'options' => array(
					'inherit' => __( 'Use theme styles (recommended)', 'form-captain' ),
					'base'    => __( 'Form Captain base styles', 'form-captain' ),
				),
				'label'   => __( 'Form styling', 'form-captain' ),
				'help'    => __( 'Theme styling only loads layout and error styles. Base styles add a neutral look without loading external fonts or files. Forms keep their own setting.', 'form-captain' ),
			),
			'appearance.accent_color'           => array(
				'type'  => 'hex',
				'label' => __( 'Accent colour', 'form-captain' ),
				'help'  => __( 'Used for buttons and focus outlines with base styles only.', 'form-captain' ),
			),
			'appearance.radius'                 => array(
				'type'  => 'int',
				'min'   => 0,
				'max'   => 24,
				'label' => __( 'Corner radius (pixels)', 'form-captain' ),
				'help'  => __( 'Rounding of input fields and buttons with base styles only. 0 = square corners.', 'form-captain' ),
			),
			'appearance.spacing'                => array(
				'type'  => 'int',
				'min'   => 0,
				'max'   => 48,
				'label' => __( 'Space between fields (pixels)', 'form-captain' ),
				'help'  => __( 'Vertical space between the fields with base styles only.', 'form-captain' ),
			),
			'appearance.font_size'              => array(
				'type'    => 'enum',
				'options' => array(
					'theme' => __( 'Like the theme', 'form-captain' ),
					'14'    => __( 'Small (14 px)', 'form-captain' ),
					'16'    => __( 'Normal (16 px)', 'form-captain' ),
					'18'    => __( 'Large (18 px)', 'form-captain' ),
					'20'    => __( 'Very large (20 px)', 'form-captain' ),
				),
				'label'   => __( 'Font size', 'form-captain' ),
				'help'    => __( 'Font size of forms with base styles only.', 'form-captain' ),
			),
			'appearance.custom_css'             => array(
				'type'       => 'css',
				'max_length' => 10000,
				'edit_cap'   => 'unfiltered_html',
				'label'      => __( 'Custom CSS', 'form-captain' ),
				'help'       => __( 'Loaded after the Form Captain styles on pages with a form, for both styling options. Only users who may post unfiltered HTML can change it. @import and HTML are not allowed.', 'form-captain' ),
			),
			'email.from_name'                   => array(
				'type'       => 'string',
				'max_length' => 190,
				'label'      => __( 'Default sender name', 'form-captain' ),
				'help'       => __( 'Copied into new forms. Existing forms keep their own value.', 'form-captain' ),
			),
			'email.from_address'                => array(
				'type'  => 'email',
				'label' => __( 'Default sender address', 'form-captain' ),
				'help'  => __( 'Use an address of your website domain so that messages are not marked as spam.', 'form-captain' ),
			),
			'email.format'                      => array(
				'type'    => 'enum',
				'options' => array(
					'html' => __( 'HTML', 'form-captain' ),
					'text' => __( 'Plain text', 'form-captain' ),
				),
				'label'   => __( 'Email format', 'form-captain' ),
				'help'    => __( 'Format of notification and confirmation e-mails. Plain text works with every mail program.', 'form-captain' ),
			),
			'email.reply_to'                    => array(
				'type'     => 'email',
				'optional' => true,
				'label'    => __( 'Reply-to address for e-mails to visitors', 'form-captain' ),
				'help'     => __( 'Replies to confirmation and double opt-in e-mails go to this address. Leave empty to use the sender address.', 'form-captain' ),
			),
			'email.footer_text'                 => array(
				'type'       => 'text',
				'max_length' => 2000,
				'keep_lines' => true,
				'label'      => __( 'E-mail footer', 'form-captain' ),
				'help'       => __( 'Shown at the end of every e-mail Form Captain sends, for example company name, address, register number and contact. Which details are mandatory in business e-mails depends on your country – have this text checked legally.', 'form-captain' ),
			),
			'email.logo_id'                     => array(
				'type'  => 'attachment',
				'label' => __( 'Logo in e-mails', 'form-captain' ),
				'help'  => __( 'An image from the media library, shown at the top of HTML e-mails. Leave empty for no logo.', 'form-captain' ),
			),
			'privacy.retention'                 => array(
				'type'  => 'retention',
				'label' => __( 'Keep submissions for', 'form-captain' ),
				'help'  => __( 'Submissions are stored only in this WordPress database. Choose how long they are kept: a period between 1 day and 30 years or until you delete them. Nothing is deleted automatically until you set a period; the country profiles suggest 12 months for contact enquiries. Statutory retention periods depend on your country and purpose – have them checked legally.', 'form-captain' ),
			),
			'privacy.trash_days'                => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 90,
				'label' => __( 'Delete trash and spam after (days)', 'form-captain' ),
				'help'  => __( 'Submissions in the trash or marked as spam are deleted permanently after this number of days.', 'form-captain' ),
			),
			'privacy.erasure_mode'              => array(
				'type'    => 'enum',
				'options' => array(
					'delete'    => __( 'Delete matching submissions completely', 'form-captain' ),
					'anonymize' => __( 'Anonymize them (keep choices and consent records, remove personal values)', 'form-captain' ),
				),
				'label'   => __( 'Personal data erasure requests', 'form-captain' ),
				'help'    => __( 'Applies when you process an erasure request under Tools → Erase Personal Data.', 'form-captain' ),
			),
			'privacy.delete_data_on_uninstall'  => array(
				'type'       => 'bool',
				'exportable' => false,
				'label'      => __( 'Delete all Form Captain data when the plugin is deleted', 'form-captain' ),
				'help'       => __( 'By default, forms and submissions are kept when the plugin is deleted.', 'form-captain' ),
			),
			'privacy.delete_data_confirmed_gmt' => array(
				'type'     => 'datetime',
				'internal' => true,
				'label'    => __( 'Uninstall deletion confirmed', 'form-captain' ),
			),
			'privacy.policy_page_hint'          => array(
				'type'  => 'bool',
				'label' => __( 'Offer a link to the privacy policy page in consent texts', 'form-captain' ),
				'help'  => __( 'Consent fields can link to the privacy policy page that is set in WordPress under Settings → Privacy.', 'form-captain' ),
			),
			'privacy.change_log_months'         => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 120,
				'label' => __( 'Keep the settings change log for (months)', 'form-captain' ),
				'help'  => __( 'Changes of settings and permissions are listed with user, time, old and new value under System status. Secret values are never shown.', 'form-captain' ),
			),
			'privacy.grace_days'                => array(
				'type'  => 'int',
				'min'   => 0,
				'max'   => 90,
				'label' => __( 'Grace period after shortening the retention (days)', 'form-captain' ),
				'help'  => __( 'When you shorten the retention period, existing submissions are deleted no sooner than this many days after the change, so you can still export them.', 'form-captain' ),
			),
			'privacy.expiry_notice_days'        => array(
				'type'  => 'int',
				'min'   => 0,
				'max'   => 90,
				'label' => __( 'Mark submissions as expiring (days before deletion)', 'form-captain' ),
				'help'  => __( 'Submissions that will be deleted within this many days are marked in the list and announced above it. 0 turns the notice off.', 'form-captain' ),
			),
			'privacy.log_days'                  => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 365,
				'label' => __( 'Keep log entries for (days)', 'form-captain' ),
				'help'  => __( 'Technical log entries (no submitted values) are deleted automatically after this period.', 'form-captain' ),
			),
			'spam.honeypot'                     => array(
				'type'  => 'bool',
				'label' => __( 'Hidden honeypot field', 'form-captain' ),
				'help'  => __( 'Invisible for visitors, catches simple bots. No data leaves your site.', 'form-captain' ),
			),
			'spam.min_seconds'                  => array(
				'type'  => 'int',
				'min'   => 0,
				'max'   => 60,
				'label' => __( 'Minimum time to fill in a form (seconds)', 'form-captain' ),
				'help'  => __( 'Submissions sent faster than this are treated as spam. 0 turns the check off.', 'form-captain' ),
			),
			'spam.rate_limit'                   => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => 100,
				'label' => __( 'Submissions per form and visitor in the time window', 'form-captain' ),
				'help'  => __( 'Visitors are recognised by a daily changing pseudonymous hash, never by the stored IP address.', 'form-captain' ),
			),
			'spam.blocklist'                    => array(
				'type'       => 'text',
				'max_length' => 10000,
				'label'      => __( 'Blocklist', 'form-captain' ),
				'help'       => __( 'One word or e-mail domain per line. Matching submissions are stored as spam.', 'form-captain' ),
			),
			'spam.captcha_provider'             => array(
				'type'    => 'enum',
				'options' => self::captcha_options(),
				'label'   => __( 'Captcha', 'form-captain' ),
				'help'    => __( 'The built-in proof of work runs on your own server: the visitor\'s browser solves a small calculation in the background – no puzzle, no cookies, no external service, no personal data. Without JavaScript the other checks apply. External captchas from extensions send visitor data to a third party; have their use checked legally before enabling them. Forms can switch the captcha off or make it stricter.', 'form-captain' ),
			),
			'spam.window_minutes'               => array(
				'type'  => 'int',
				'min'   => \FormCaptain\Security\SpamLimits::BOUNDS['window_minutes'][0],
				'max'   => \FormCaptain\Security\SpamLimits::BOUNDS['window_minutes'][1],
				'label' => __( 'Time window for submission limits (minutes)', 'form-captain' ),
				'help'  => __( 'All submission limits count within this window. Default: 10 minutes.', 'form-captain' ),
			),
			'spam.form_total'                   => array(
				'type'  => 'int',
				'min'   => \FormCaptain\Security\SpamLimits::BOUNDS['form_total'][0],
				'max'   => \FormCaptain\Security\SpamLimits::BOUNDS['form_total'][1],
				'label' => __( 'Submissions per form in the time window (all visitors)', 'form-captain' ),
				'help'  => __( 'Flood brake against attacks from many addresses. Raise it for large campaigns. Default: 300.', 'form-captain' ),
			),
			'spam.receipts_per_hour'            => array(
				'type'  => 'int',
				'min'   => \FormCaptain\Security\SpamLimits::BOUNDS['receipts_per_hour'][0],
				'max'   => \FormCaptain\Security\SpamLimits::BOUNDS['receipts_per_hour'][1],
				'label' => __( 'E-mails to one visitor address per hour', 'form-captain' ),
				'help'  => __( 'Limits confirmation and double opt-in e-mails to the same address, so forms cannot be misused to flood a mailbox. Default: 3.', 'form-captain' ),
			),
			'spam.client_ip_source'             => array(
				'type'    => 'enum',
				'options' => array(
					'remote_addr'      => __( 'Direct connection (recommended)', 'form-captain' ),
					'cf_connecting_ip' => __( 'CF-Connecting-IP header (Cloudflare)', 'form-captain' ),
					'x_forwarded_for'  => __( 'X-Forwarded-For header (first address)', 'form-captain' ),
					'x_real_ip'        => __( 'X-Real-IP header', 'form-captain' ),
				),
				'label'   => __( 'Visitor address for rate limiting', 'form-captain' ),
				'help'    => __( 'Only choose a header if your website is really behind this service (CDN, proxy or load balancer). Otherwise visitors can fake the header and bypass rate limiting. The address is only used for the pseudonymous rate-limit hash and is never stored.', 'form-captain' ),
			),
			'spam.global_per_ip'                => array(
				'type'  => 'int',
				'min'   => 1,
				'max'   => \FormCaptain\Support\Limits::SETTABLE['rate_global_per_ip'][2],
				'label' => __( 'Submissions per visitor across all forms in the rate-limit window', 'form-captain' ),
				'help'  => __( 'Raise this value for events or kiosks where many people submit from the same network.', 'form-captain' ),
			),
			'spam.token_minutes'                => array(
				'type'  => 'int',
				'min'   => 5,
				'max'   => (int) ( \FormCaptain\Support\Limits::SETTABLE['token_ttl'][2] / 60 ),
				'label' => __( 'Time to fill in a form (minutes)', 'form-captain' ),
				'help'  => __( 'How long a loaded form can be submitted. Raise it for long forms; visitors who exceed it have to reload the page.', 'form-captain' ),
			),
			'export.delimiter'                  => array(
				'type'    => 'enum',
				'options' => array(
					',' => __( 'Comma (,)', 'form-captain' ),
					';' => __( 'Semicolon (;) – common for spreadsheet programs in German-speaking countries', 'form-captain' ),
				),
				'label'   => __( 'Default CSV separator', 'form-captain' ),
				'help'    => __( 'Preselected in the CSV export. You can still choose another separator for each export.', 'form-captain' ),
			),
			'export.bom'                        => array(
				'type'  => 'bool',
				'label' => __( 'Add UTF-8 byte order mark by default', 'form-captain' ),
				'help'  => __( 'Helps Excel to show umlauts and other special characters correctly.', 'form-captain' ),
			),
		);
		foreach ( $out as $path => $def ) {
			list( $group, $key )     = explode( '.', $path, 2 );
			$out[ $path ]['default'] = $d[ $group ][ $key ] ?? null;
			$out[ $path ]['tab']     = $group;
			$out[ $path ]['group']   = $group;
			$out[ $path ]['key']     = $key;
			$out[ $path ]['cap']     = self::capability();
		}
		return $out;
	}

	/**
	 * Hint rows for settings that an add-on can provide (S1, SET-FC-06). Text only: no input,
	 * no default, no sanitizer and no feature code. A row is shown at the end of its tab while
	 * `general.show_pro_hints` is on and no add-on has registered a field with the same `id`
	 * through `form_captain_settings_fields_v1`.
	 *
	 * @return array<int,array{id:string,tab:string,title:string,text:string,link:string}>
	 */
	public static function pro_hints(): array {
		// One row per setting of a Pro feature that is on sale today (S5): uploads, multi-step forms and
		// drafts, advanced logic/routing, PDF, webhooks, buyer questionnaires. Features that are
		// not sold yet get no row. Add a row as array( tab, id, title, text ). The id is the id of the
		// add-on field that replaces the row (Form Captain Pro: uploads.types, multistep.draft_days …).
		$rows = array(
			array( 'general', 'uploads.types', __( 'File types and size', 'form-captain' ), __( 'Default file types and size limit for upload fields.', 'form-captain' ) ),
			array( 'general', 'uploads.max_files', __( 'Several files', 'form-captain' ), __( 'Allow several files per upload field.', 'form-captain' ) ),
			array( 'general', 'multistep.draft_days', __( 'Saved drafts', 'form-captain' ), __( 'How long visitors can continue a saved multi-step form.', 'form-captain' ) ),
			array( 'general', 'webhooks', __( 'Webhooks', 'form-captain' ), __( 'Send submissions to other systems with signed webhooks.', 'form-captain' ) ),
			array( 'general', 'webhooks.timeout', __( 'Webhook delivery', 'form-captain' ), __( 'Retries and time limit for webhook deliveries.', 'form-captain' ) ),
			array( 'general', 'woo.reminder_days', __( 'Questionnaire reminder', 'form-captain' ), __( 'Default reminder for buyer questionnaires.', 'form-captain' ) ),
			array( 'general', 'woo.link_days', __( 'Questionnaire link', 'form-captain' ), __( 'How long the questionnaire link for buyers stays valid.', 'form-captain' ) ),
			array( 'general', 'woo.reminder_subject', __( 'Questionnaire e-mails', 'form-captain' ), __( 'Texts of the questionnaire e-mails to buyers.', 'form-captain' ) ),
			array( 'appearance', 'multistep.progress', __( 'Progress display', 'form-captain' ), __( 'Progress display and step labels for multi-step forms.', 'form-captain' ) ),
			array( 'appearance', 'pdf.logo_id', __( 'PDF layout', 'form-captain' ), __( 'Logo, letterhead and paper size for PDF documents.', 'form-captain' ) ),
			array( 'email', 'recipient_rules', __( 'Recipients by rule', 'form-captain' ), __( 'Send notifications to different recipients depending on the answers.', 'form-captain' ) ),
			array( 'email', 'pdf.attach_notification', __( 'PDF attachment', 'form-captain' ), __( 'Attach the submission as a PDF to the notification.', 'form-captain' ) ),
			array( 'privacy', 'uploads.file_retention_days', __( 'Delete files earlier', 'form-captain' ), __( 'Delete uploaded files earlier than the submission itself.', 'form-captain' ) ),
			array( 'spam', 'upload_virus_scan', __( 'Virus scan', 'form-captain' ), __( 'Check uploaded files with a virus scanner.', 'form-captain' ) ),
		);
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'    => $r[1],
				'tab'   => $r[0],
				'title' => $r[2],
				'text'  => $r[3],
				'link'  => self::PRO_INFO_URL,
			);
		}
		return $out;
	}

	/**
	 * Hint rows to show at the end of one tab (empty when hints are switched off).
	 *
	 * @return array<int,array{id:string,tab:string,title:string,text:string,link:string}>
	 */
	public static function visible_pro_hints( string $tab ): array {
		if ( ! Settings::get( 'general.show_pro_hints' ) ) {
			return array();
		}
		$registered = self::extension_fields();
		return array_values( array_filter( self::pro_hints(), static fn( array $h ): bool => $h['tab'] === $tab && ! isset( $registered[ $h['id'] ] ) ) );
	}

	/** Types of add-on fields in the core form (callbacks) and in the storage form. */
	public const EXTENSION_TYPES = array( 'bool', 'int', 'enum', 'string', 'text', 'email', 'hex', 'multiselect', 'attachment', 'confirm' );

	/** Type names of the storage form (Form Captain Pro) mapped to core types. */
	private const STORAGE_TYPES = array(
		'checkbox'    => 'bool',
		'number'      => 'int',
		'text'        => 'string',
		'textarea'    => 'text',
		'select'      => 'enum',
		'multiselect' => 'multiselect',
		'media'       => 'attachment',
		'confirm'     => 'confirm',
	);

	/** Tabs that can never hold add-on fields. */
	private const RESERVED_TABS = array( 'access', 'status' );

	/**
	 * Real settings fields of add-ons (filter `form_captain_settings_fields_v1`). The filter value is
	 * a list of fields or a map tab => list of fields. Two field forms are accepted:
	 *
	 * 1. Callbacks: id, tab, type (bool|int|enum|string|text|email|hex), label, help, options/min/max/
	 *    max_length as for core settings, `get()`, `set( $value )`, optional `validate( $value )`
	 *    (error message or null) and `default` (used by "Reset this section"). The add-on stores.
	 * 2. Storage: id, owner, tab, label, help, type (checkbox|number|text|textarea|select|multiselect|
	 *    media|confirm, or a core type), default, value (current value), optional `sanitize( $raw,
	 *    $submitted )` returning the clean value or a WP_Error, `storage` {option, key}: the core
	 *    writes the clean value into that option under that key (never into Form Captain's own
	 *    option), and per type min/max, max_length, options. Type `confirm` is a switch that needs a
	 *    confirmation word: the word as value, or the box plus the word in `<id>_confirm`.
	 *
	 * Ids may contain a-z, 0-9, `_`, `-` and `.`. A tab outside GROUPS becomes its own tab on the
	 * settings page while it has fields (label from the field's `tab_label` or a default).
	 *
	 * @return array<string,array<string,mixed>> id => field (normalized: get/set callables, core type)
	 */
	public static function extension_fields(): array {
		$out = array();
		foreach ( self::extension_field_list( apply_filters( 'form_captain_settings_fields_v1', array() ) ) as $field ) {
			$field = self::normalize_extension_field( $field );
			if ( null !== $field && ! isset( $out[ $field['id'] ] ) ) {
				$out[ $field['id'] ] = $field;
			}
		}
		return $out;
	}

	/**
	 * Flattens a list of fields or a map tab => fields (the tab key fills a missing `tab`).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function extension_field_list( mixed $raw ): array {
		$list = array();
		foreach ( is_array( $raw ) ? $raw : array() as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['id'] ) ) {
				$list[] = $item;
				continue;
			}
			if ( is_string( $key ) ) {
				foreach ( $item as $field ) {
					if ( is_array( $field ) && isset( $field['id'] ) ) {
						$list[] = $field + array( 'tab' => $key );
					}
				}
			}
		}
		return $list;
	}

	/**
	 * Checks one add-on field and brings both forms to one shape; null when the field is unusable.
	 *
	 * @param array<string,mixed> $field
	 * @return array<string,mixed>|null
	 */
	private static function normalize_extension_field( array $field ): ?array {
		$id  = is_string( $field['id'] ?? null ) ? $field['id'] : '';
		$tab = is_string( $field['tab'] ?? null ) ? $field['tab'] : '';
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_.-]{1,79}$/', $id ) || ! self::valid_extension_tab( $tab ) || '' === trim( (string) ( $field['label'] ?? '' ) ) ) {
			return null;
		}
		$type = (string) ( $field['type'] ?? '' );
		if ( is_callable( $field['get'] ?? null ) && is_callable( $field['set'] ?? null ) ) {
			if ( ! in_array( $type, array( 'bool', 'int', 'enum', 'string', 'text', 'email', 'hex' ), true ) ) {
				return null;
			}
			$field['form'] = 'callbacks';
		} else {
			$storage = $field['storage'] ?? null;
			$option  = is_array( $storage ) && is_string( $storage['option'] ?? null ) ? $storage['option'] : '';
			$key     = is_array( $storage ) && is_scalar( $storage['key'] ?? null ) ? (string) $storage['key'] : '';
			// Never Form Captain's own option: core values only change through SettingsSchema.
			if ( ! preg_match( '/^[a-z0-9_]{3,64}$/', $option ) || Settings::OPTION === $option || '' === $key || strlen( $key ) > 100 ) {
				return null;
			}
			$type = self::STORAGE_TYPES[ $type ] ?? $type;
			if ( ! in_array( $type, self::EXTENSION_TYPES, true ) ) {
				return null;
			}
			$has_value     = array_key_exists( 'value', $field );
			$value         = $field['value'] ?? null;
			$default       = $field['default'] ?? null;
			$field['get']  = static function () use ( $option, $key, $has_value, $value, $default ): mixed {
				if ( $has_value ) {
					return $value;
				}
				$stored = get_option( $option, array() );
				return is_array( $stored ) && array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;
			};
			$field['set']  = static function ( mixed $new ) use ( $option, $key ): void {
				$stored         = get_option( $option, array() );
				$stored         = is_array( $stored ) ? $stored : array();
				$stored[ $key ] = $new;
				update_option( $option, $stored, false );
			};
			$field['form'] = 'storage';
		}
		if ( in_array( $type, array( 'enum', 'multiselect' ), true ) && ! is_array( $field['options'] ?? null ) ) {
			return null;
		}
		$checked_by_addon = 'storage' === $field['form'] && is_callable( $field['sanitize'] ?? null );
		if ( 'int' === $type && ! $checked_by_addon && ( ! is_numeric( $field['min'] ?? null ) || ! is_numeric( $field['max'] ?? null ) ) ) {
			return null;
		}
		$field['id']    = $id;
		$field['type']  = $type;
		$field['label'] = wp_strip_all_tags( (string) $field['label'] );
		$field['help']  = wp_strip_all_tags( (string) ( $field['help'] ?? '' ) );
		$field['key']   = $id;
		$field['group'] = $tab;
		return $field;
	}

	private static function valid_extension_tab( string $tab ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_-]{1,30}$/', $tab ) && ! in_array( $tab, self::RESERVED_TABS, true );
	}

	/**
	 * Tabs that exist only because add-ons registered fields there, with their labels.
	 *
	 * @return array<string,string> tab => label
	 */
	public static function extension_tabs(): array {
		// Other tabs (e.g. of a shop add-on) name themselves with `tab_label`.
		$defaults = array(
			'uploads'      => __( 'Uploads', 'form-captain' ),
			'integrations' => __( 'Integrations', 'form-captain' ),
		);
		$out      = array();
		$named    = array();
		foreach ( self::extension_fields() as $field ) {
			$tab = (string) $field['tab'];
			if ( in_array( $tab, self::GROUPS, true ) || isset( $named[ $tab ] ) ) {
				continue;
			}
			// The first field with a `tab_label` names the tab.
			$label = is_string( $field['tab_label'] ?? null ) ? trim( wp_strip_all_tags( $field['tab_label'] ) ) : '';
			if ( '' !== $label ) {
				$named[ $tab ] = true;
			}
			$out[ $tab ] = '' !== $label ? $label : ( $out[ $tab ] ?? $defaults[ $tab ] ?? ucfirst( str_replace( array( '_', '-' ), ' ', $tab ) ) );
		}
		return $out;
	}

	/** Whether a tab can be shown: a core tab or a tab with add-on fields. */
	public static function is_tab( string $tab ): bool {
		return in_array( $tab, self::TABS, true ) || isset( self::extension_tabs()[ $tab ] );
	}

	/** Whether a tab only holds add-on fields (no core values). */
	public static function is_extension_tab( string $tab ): bool {
		return ! in_array( $tab, self::TABS, true ) && isset( self::extension_tabs()[ $tab ] );
	}

	/**
	 * Extension fields of one tab.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function extension_fields_for( string $tab ): array {
		return array_filter( self::extension_fields(), static fn( array $f ): bool => $f['tab'] === $tab );
	}

	/**
	 * Validates and stores the posted values of the extension fields of one tab.
	 *
	 * @param array<string,mixed> $input posted values keyed by field ID (`<id>_confirm` for confirm fields)
	 * @return array{changed:array<string,array{0:mixed,1:mixed}>,errors:array<string,string>} changed id => [old, new]; errors "ext.id" => message
	 */
	public static function save_extension_fields( string $tab, array $input ): array {
		$changed = array();
		$errors  = array();
		foreach ( self::extension_fields_for( $tab ) as $id => $field ) {
			$type = (string) $field['type'];
			if ( ! array_key_exists( $id, $input ) ) {
				// Unchecked boxes and empty multi-selections are not sent by the browser.
				if ( ! in_array( $type, array( 'bool', 'confirm', 'multiselect' ), true ) ) {
					continue;
				}
				$input[ $id ] = 'multiselect' === $type ? array() : '0';
			}
			list( $value, $error ) = self::check_extension_value( $field, $input[ $id ], $input );
			if ( null === $error && is_callable( $field['validate'] ?? null ) ) {
				$res   = call_user_func( $field['validate'], $value );
				$error = is_string( $res ) && '' !== $res ? $res : null;
			}
			if ( null !== $error ) {
				$errors[ 'ext.' . $id ] = $error;
				continue;
			}
			$old = call_user_func( $field['get'] );
			if ( $old !== $value ) {
				call_user_func( $field['set'], $value );
				$changed[ $id ] = array( $old, $value );
			}
		}
		return array(
			'changed' => $changed,
			'errors'  => $errors,
		);
	}

	/**
	 * Clean value of an add-on field: the add-on's `sanitize` (storage form) or the core check.
	 *
	 * @param array<string,mixed> $field
	 * @param array<string,mixed> $submitted all posted values of the tab's add-on fields
	 * @return array{0:mixed,1:?string} [value, error message]
	 */
	private static function check_extension_value( array $field, mixed $raw, array $submitted ): array {
		if ( 'storage' === $field['form'] && is_callable( $field['sanitize'] ?? null ) ) {
			$res = call_user_func( $field['sanitize'], $raw, $submitted );
			if ( $res instanceof \WP_Error ) {
				return array( null, '' !== $res->get_error_message() ? $res->get_error_message() : __( 'Invalid value.', 'form-captain' ) );
			}
			return array( $res, null );
		}
		switch ( $field['type'] ) {
			case 'bool':
				return array( ! empty( $raw ) && '0' !== $raw, null );
			case 'confirm':
				$word = static fn( mixed $v ): bool => is_string( $v ) && in_array( mb_strtoupper( trim( $v ) ), self::CONFIRM_WORDS, true );
				if ( $word( $raw ) ) {
					return array( true, null );
				}
				if ( empty( $raw ) || '0' === $raw ) {
					return array( false, null );
				}
				return $word( $submitted[ $field['id'] . '_confirm' ] ?? null ) ? array( true, null ) : array( null, __( 'Type DELETE to confirm.', 'form-captain' ) );
			case 'multiselect':
				$list = is_array( $raw ) ? $raw : ( is_string( $raw ) && '' !== $raw ? explode( ',', $raw ) : array() );
				$out  = array();
				foreach ( $list as $item ) {
					$item = is_scalar( $item ) ? trim( (string) $item ) : '';
					if ( '' === $item ) {
						continue;
					}
					if ( ! array_key_exists( $item, (array) $field['options'] ) ) {
						return array( null, __( 'Please choose one of the offered options.', 'form-captain' ) );
					}
					$out[ $item ] = true;
				}
				return array( array_keys( $out ), null );
			default:
				return self::sanitize_value( $field, $raw );
		}
	}

	/** Resets extension fields of one tab that declare a default. */
	public static function reset_extension_fields( string $tab ): void {
		foreach ( self::extension_fields_for( $tab ) as $field ) {
			if ( array_key_exists( 'default', $field ) ) {
				call_user_func( $field['set'], $field['default'] );
			}
		}
	}

	/**
	 * Captcha providers: always "none" and the built-in "pow" (Security\PowCaptcha, no data leaves the
	 * site); extensions add opt-in external providers via form_captain_spam_checks_v1
	 * with `type = captcha`, `label`, `data` (what is sent), `purpose`, `privacy_url`.
	 *
	 * @return array<string,string>
	 */
	public static function captcha_options(): array {
		$opts = array(
			'none' => __( 'None', 'form-captain' ),
			'pow'  => __( 'Built-in proof of work (recommended, no external service)', 'form-captain' ),
		);
		foreach ( self::captcha_providers() as $key => $p ) {
			$opts[ $key ] = (string) $p['label'];
		}
		return $opts;
	}

	/** @return array<string,array<string,string>> */
	public static function captcha_providers(): array {
		$out = array();
		foreach ( (array) apply_filters( 'form_captain_spam_checks_v1', array() ) as $key => $def ) {
			if ( is_string( $key ) && preg_match( '/^[a-z0-9_]{2,40}$/', $key ) && ! in_array( $key, array( 'none', 'pow' ), true ) && is_array( $def ) && 'captcha' === ( $def['type'] ?? '' ) ) {
				$out[ $key ] = array(
					'label'       => (string) ( $def['label'] ?? $key ),
					'data'        => (string) ( $def['data'] ?? '' ),
					'purpose'     => (string) ( $def['purpose'] ?? '' ),
					'privacy_url' => (string) ( $def['privacy_url'] ?? '' ),
				);
			}
		}
		return $out;
	}

	/** @return array<string,array<string,mixed>> key => definition for one group */
	public static function group( string $group ): array {
		$out = array();
		foreach ( self::definitions() as $def ) {
			if ( $def['group'] === $group ) {
				$out[ $def['key'] ] = $def;
			}
		}
		return $out;
	}

	/**
	 * Sanitizes and validates the submitted values of one group.
	 *
	 * Special input keys (not stored): `retention_confirm` (required when the retention changes),
	 * `retention_kind`/`retention_amount`/`retention_unit` (form inputs for the retention period),
	 * `uninstall_confirm_word` (required to enable delete_data_on_uninstall), `captcha_confirm`.
	 *
	 * @param array<string,mixed> $input raw input (e.g. wp_unslash( $_POST['settings'] ))
	 * @return array{0:array<string,mixed>,1:array<string,string>} [values, errors path => message]
	 */
	public static function sanitize_group( string $group, array $input ): array {
		$defs = self::group( $group );
		if ( ! $defs ) {
			return array( array(), array( $group => __( 'Unknown settings section.', 'form-captain' ) ) );
		}
		$current = Settings::all()[ $group ] ?? array();
		$values  = $current;
		$errors  = array();
		if ( 'privacy' === $group && ! array_key_exists( 'retention', $input ) && array_key_exists( 'retention_kind', $input ) ) {
			$input['retention'] = RetentionPeriod::from_parts( $input['retention_kind'], $input['retention_amount'] ?? '', $input['retention_unit'] ?? '' );
		}
		foreach ( $defs as $key => $def ) {
			if ( ! empty( $def['internal'] ) ) {
				continue;
			}
			$path = $group . '.' . $key;
			if ( ! empty( $def['edit_cap'] ) && ! current_user_can( (string) $def['edit_cap'] ) ) {
				// Only users with this extra capability may change the value (e.g. custom CSS: unfiltered_html).
				if ( array_key_exists( $key, $input ) && (string) $input[ $key ] !== (string) ( $current[ $key ] ?? '' ) ) {
					$errors[ $path ] = __( 'You are not allowed to change this setting.', 'form-captain' );
				}
				continue;
			}
			if ( 'bool' === $def['type'] ) {
				$values[ $key ] = ! empty( $input[ $key ] );
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			list( $value, $error ) = self::sanitize_value( $def, $input[ $key ] );
			if ( null !== $error ) {
				$errors[ $path ] = $error;
				continue;
			}
			$values[ $key ] = $value;
		}

		if ( 'privacy' === $group ) {
			if ( ( $values['retention'] ?? '' ) !== ( $current['retention'] ?? '' ) && empty( $input['retention_confirm'] ) ) {
				$errors['privacy.retention'] = __( 'Changing the retention period affects existing submissions. Please confirm the change.', 'form-captain' );
				$values['retention']         = $current['retention'];
			}
			if ( empty( $values['delete_data_on_uninstall'] ) ) {
				$values['delete_data_on_uninstall']  = false;
				$values['delete_data_confirmed_gmt'] = '';
			} elseif ( empty( $current['delete_data_confirmed_gmt'] ) || empty( $current['delete_data_on_uninstall'] ) ) {
				$word = isset( $input['uninstall_confirm_word'] ) && is_scalar( $input['uninstall_confirm_word'] ) ? trim( (string) $input['uninstall_confirm_word'] ) : '';
				if ( in_array( mb_strtoupper( $word ), self::CONFIRM_WORDS, true ) ) {
					$values['delete_data_confirmed_gmt'] = Clock::now();
				} else {
					$errors['privacy.delete_data_on_uninstall'] = __( 'To delete all data on uninstall, type DELETE into the confirmation field.', 'form-captain' );
					$values['delete_data_on_uninstall']         = false;
					$values['delete_data_confirmed_gmt']        = '';
				}
			}
		}

		if ( 'spam' === $group && ! in_array( $values['captcha_provider'] ?? 'none', array( 'none', 'pow' ), true ) && ( $values['captcha_provider'] ?? '' ) !== ( $current['captcha_provider'] ?? '' ) && empty( $input['captcha_confirm'] ) ) {
			$errors['spam.captcha_provider'] = __( 'An external captcha sends visitor data to a third party. Confirm that you have read the data flow and updated your privacy policy.', 'form-captain' );
			$values['captcha_provider']      = $current['captcha_provider'] ?? 'none';
		}

		return array( array_intersect_key( $values, $defs ), $errors );
	}

	/**
	 * @param array<string,mixed> $def
	 * @return array{0:mixed,1:?string} [value, error]
	 */
	public static function sanitize_value( array $def, mixed $raw ): array {
		if ( is_array( $raw ) || is_object( $raw ) ) {
			return array( null, __( 'Invalid value.', 'form-captain' ) );
		}
		$raw = (string) $raw;
		switch ( $def['type'] ) {
			case 'bool':
				return array( '' !== $raw && '0' !== $raw, null );
			case 'int':
				$raw = trim( $raw );
				if ( ! preg_match( '/^-?\d+$/', $raw ) ) {
					return array( null, __( 'Please enter a whole number.', 'form-captain' ) );
				}
				$int = (int) $raw;
				if ( $int < (int) $def['min'] || $int > (int) $def['max'] ) {
					/* translators: 1: minimum, 2: maximum */
					return array( null, sprintf( __( 'Please enter a number between %1$d and %2$d.', 'form-captain' ), (int) $def['min'], (int) $def['max'] ) );
				}
				return array( $int, null );
			case 'enum':
				return array_key_exists( $raw, (array) $def['options'] ) ? array( $raw, null ) : array( null, __( 'Please choose one of the offered options.', 'form-captain' ) );
			case 'retention':
				$period = RetentionPeriod::normalize( $raw );
				return null !== $period ? array( $period, null ) : array( null, RetentionPeriod::error_message() );
			case 'hex':
				$raw = strtolower( trim( $raw ) );
				return preg_match( '/^#[0-9a-f]{6}$/', $raw ) ? array( $raw, null ) : array( null, __( 'Please enter a colour in the format #RRGGBB.', 'form-captain' ) );
			case 'email':
				if ( ! empty( $def['optional'] ) && '' === trim( $raw ) ) {
					return array( '', null );
				}
				$email = sanitize_email( trim( $raw ) );
				return ( '' !== $email && is_email( $email ) ) ? array( strtolower( $email ), null ) : array( null, __( 'Please enter a valid e-mail address.', 'form-captain' ) );
			case 'string':
				$value = sanitize_text_field( $raw );
				if ( mb_strlen( $value ) > (int) ( $def['max_length'] ?? 190 ) ) {
					/* translators: %d: maximum number of characters */
					return array( null, sprintf( __( 'Please use at most %d characters.', 'form-captain' ), (int) ( $def['max_length'] ?? 190 ) ) );
				}
				if ( '' === $value && empty( $def['allow_empty'] ) ) {
					return array( null, __( 'This field cannot be empty.', 'form-captain' ) );
				}
				$invalid = isset( $def['validate'] ) && is_callable( $def['validate'] ) ? call_user_func( $def['validate'], $value ) : null;
				if ( is_string( $invalid ) && '' !== $invalid ) {
					return array( null, $invalid );
				}
				return array( $value, null );
			case 'css':
				$value = trim( str_replace( array( "\r\n", "\r" ), "\n", $raw ) );
				if ( mb_strlen( $value ) > (int) ( $def['max_length'] ?? 10000 ) ) {
					/* translators: %d: maximum number of characters */
					return array( null, sprintf( __( 'Please use at most %d characters.', 'form-captain' ), (int) ( $def['max_length'] ?? 10000 ) ) );
				}
				if ( str_contains( $value, '<' ) || preg_match( '/@import/i', $value ) || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value ) ) {
					return array( null, __( 'The CSS must not contain HTML (<) or @import rules.', 'form-captain' ) );
				}
				return array( $value, null );
			case 'text':
				$value = sanitize_textarea_field( $raw );
				if ( mb_strlen( $value ) > (int) ( $def['max_length'] ?? 10000 ) ) {
					/* translators: %d: maximum number of characters */
					return array( null, sprintf( __( 'Please use at most %d characters.', 'form-captain' ), (int) ( $def['max_length'] ?? 10000 ) ) );
				}
				if ( ! empty( $def['keep_lines'] ) ) {
					// Free text such as an e-mail footer: keep order, duplicates and single blank lines.
					$value = (string) preg_replace( "/\n{3,}/", "\n\n", str_replace( array( "\r\n", "\r" ), "\n", $value ) );
					return array( trim( implode( "\n", array_map( 'rtrim', explode( "\n", $value ) ) ) ), null );
				}
				$lines = array_filter( array_map( 'trim', preg_split( '/\R/', $value ) ?: array() ), static fn( $l ) => '' !== $l );
				return array( implode( "\n", array_unique( $lines ) ), null );
			case 'attachment':
				$raw = trim( $raw );
				if ( '' === $raw || '0' === $raw ) {
					return array( 0, null );
				}
				if ( ! preg_match( '/^\d+$/', $raw ) || ! wp_attachment_is_image( (int) $raw ) ) {
					return array( null, __( 'Please choose an image from the media library.', 'form-captain' ) );
				}
				return array( (int) $raw, null );
			default:
				return array( null, __( 'Invalid value.', 'form-captain' ) );
		}
	}

	/**
	 * Secret values (keys, passwords, tokens) are masked in the change log and never exported.
	 * A definition can mark itself with `secret => true`; names that look like secrets count too.
	 */
	public static function is_secret( string $path ): bool {
		$def = self::definitions()[ $path ] ?? null;
		if ( is_array( $def ) && ! empty( $def['secret'] ) ) {
			return true;
		}
		return (bool) preg_match( '/secret|token|password|passwd|api_?key|private_?key|credential/i', $path );
	}

	/** True when the sender domain differs from the site domain (deliverability hint, P2-22). */
	public static function from_domain_mismatch( ?string $address = null ): bool {
		$address = $address ?? (string) Settings::get( 'email.from_address' );
		$domain  = strtolower( (string) substr( strrchr( $address, '@' ) ?: '', 1 ) );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$host    = is_string( $host ) ? strtolower( (string) preg_replace( '/^www\./', '', $host ) ) : '';
		if ( '' === $domain || '' === $host ) {
			return false;
		}
		return $domain !== $host && ! str_ends_with( $host, '.' . $domain ) && ! str_ends_with( $domain, '.' . $host );
	}
}
