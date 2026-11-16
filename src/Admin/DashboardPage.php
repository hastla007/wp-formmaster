<?php
/**
 * Dashboard with setup checklist (konzept 6 "Inhalt der Ersteinrichtung"), counters,
 * recent forms and quick actions (P2-01, AC-011 … AC-019). Sparse, dismissible Pro hints (P6-09).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Admin;

use FormCaptain\Admin\Tables\FormsListTable;
use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Repository\FormRepository;
use FormCaptain\Service\Templates;
use FormCaptain\Settings\Settings;

final class DashboardPage {

	public const STEPS_OPTION = 'form_captain_setup_steps';

	public const HINT_META = 'form_captain_pro_hints_dismissed';

	/** Steps that can only be confirmed manually. */
	private const MANUAL_STEPS = array( 'recipients', 'privacy', 'suite' );

	public function __construct(
		private FormRepository $forms,
		private EntryRepository $entries,
		private Templates $templates
	) {}

	public function register( Plugin $plugin ): void {
		add_action( 'admin_post_form_captain_setup_step', array( $this, 'handle_step' ) );
		add_action( 'admin_post_form_captain_setup_finish', array( $this, 'handle_finish' ) );
		add_action( 'admin_post_form_captain_setup_page', array( $this, 'handle_page' ) );
		add_action( 'admin_post_form_captain_dismiss_hint', array( $this, 'handle_dismiss_hint' ) );
	}

	// State.

	/**
	 * Status of the six setup steps.
	 *
	 * @return array<string,bool>
	 */
	public function steps(): array {
		$manual = (array) get_option( self::STEPS_OPTION, array() );
		$total  = $this->forms->list(
			array(
				'per_page' => 1,
			)
		)['total'];
		return array(
			'form'       => $total > 0,
			'recipients' => ! empty( $manual['recipients'] ),
			'embed'      => $this->is_embedded(),
			'test'       => array_sum( $this->entries->status_counts() ) > 0,
			'privacy'    => ! empty( $manual['privacy'] ),
			'suite'      => ! empty( $manual['suite'] ),
		);
	}

	/** True when any post/page contains a Form Captain shortcode or block. */
	public function is_embedded(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future') AND post_type NOT IN ('revision','nav_menu_item') AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 1",
				'%' . $wpdb->esc_like( '[form_captain' ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:form-captain/form' ) . '%'
			)
		);
		return null !== $found;
	}

	/**
	 * Detects Captain Suite plugins and WooCommerce (informational, nothing gets activated).
	 *
	 * @return array<string,array{label:string,active:bool}>
	 */
	public static function suite_plugins(): array {
		$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$known  = array(
			'pass'      => array( __( 'Pass Captain', 'form-captain' ), array( 'pass-captain', 'passmaster' ) ),
			'voucher'   => array( __( 'Voucher Captain', 'form-captain' ), array( 'voucher-captain', 'vouchermaster' ) ),
			'review'    => array( __( 'Review Captain', 'form-captain' ), array( 'review-captain', 'reviewmaster' ) ),
			'affiliate' => array( __( 'Affiliate Captain', 'form-captain' ), array( 'affiliate-captain', 'affiliatemaster' ) ),
			'license'   => array( __( 'License Captain', 'form-captain' ), array( 'license-captain', 'licensemaster' ) ),
			'woo'       => array( __( 'WooCommerce', 'form-captain' ), array( 'woocommerce/woocommerce.php' ) ),
		);
		$out    = array();
		foreach ( $known as $key => $def ) {
			$is = false;
			foreach ( $active as $plugin ) {
				foreach ( $def[1] as $needle ) {
					if ( str_contains( $plugin, $needle ) ) {
						$is = true;
					}
				}
			}
			$out[ $key ] = array(
				'label'  => $def[0],
				'active' => $is,
			);
		}
		return $out;
	}

	// admin-post handlers.

	private function verify( string $nonce_action, string $cap = Capabilities::MANAGE_FORMS ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/** Marks a manual step as done. Returns redirect URL (testable). */
	public function process_step(): string {
		$this->verify( 'form_captain_setup_step' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$step = isset( $_REQUEST['step'] ) ? sanitize_key( wp_unslash( $_REQUEST['step'] ) ) : '';
		if ( in_array( $step, self::MANUAL_STEPS, true ) ) {
			$steps          = (array) get_option( self::STEPS_OPTION, array() );
			$steps[ $step ] = true;
			update_option( self::STEPS_OPTION, $steps, false );
		}
		return admin_url( 'admin.php?page=form-captain&fc_notice=step_done' );
	}

	public function handle_step(): void {
		wp_safe_redirect( $this->process_step() );
		exit;
	}

	public function handle_finish(): void {
		$this->verify( 'form_captain_setup_finish' );
		Settings::set_group( 'general', array( 'setup_completed' => true ) );
		wp_safe_redirect( admin_url( 'admin.php?page=form-captain' ) );
		exit;
	}

	/** Creates a draft page containing the form shortcode (AC-014). */
	public function process_page(): string {
		$this->verify( 'form_captain_setup_page' );
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to create pages.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$form = $this->forms->find( isset( $_REQUEST['form'] ) ? absint( $_REQUEST['form'] ) : 0 );
		if ( ! $form ) {
			return admin_url( 'admin.php?page=form-captain&fc_error=not_found' );
		}
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => (string) $form['title'],
				'post_content' => "<!-- wp:shortcode -->\n[form_captain id=\"" . $form['public_id'] . "\"]\n<!-- /wp:shortcode -->",
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return admin_url( 'admin.php?page=form-captain&fc_error=storage_failed' );
		}
		return (string) get_edit_post_link( (int) $page_id, 'url' );
	}

	public function handle_page(): void {
		wp_safe_redirect( $this->process_page() );
		exit;
	}

	public function handle_dismiss_hint(): void {
		if ( ! is_user_logged_in() || ! Capabilities::current_user_has_any() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'form_captain_dismiss_hint' );
		update_user_meta( get_current_user_id(), self::HINT_META, 1 );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=form-captain' ) );
		exit;
	}

	// Pro hint (P6-09).

	public static function should_show_pro_hint(): bool {
		if ( ! Settings::get( 'general.show_pro_hints' ) || apply_filters( 'form_captain_pro_active', false ) ) {
			return false;
		}
		return ! get_user_meta( get_current_user_id(), self::HINT_META, true );
	}

	/** Small, contextual, dismissible hint; never blocks a feature. */
	public static function pro_hint( string $context ): void {
		if ( ! self::should_show_pro_hint() ) {
			return;
		}
		$text = match ( $context ) {
			'editor' => __( 'Need file uploads, multi-step forms or grouped conditions? They are available with Form Captain Pro – everything you build here keeps working without it.', 'form-captain' ),
			default => __( 'Tip: Form Captain Pro adds file uploads, multi-step forms and workflows. All features you use now stay free.', 'form-captain' ),
		};
		echo '<div class="fc-pro-hint"><p>' . esc_html( $text ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="form_captain_dismiss_hint">';
		wp_nonce_field( 'form_captain_dismiss_hint' );
		echo '<button type="submit" class="button-link"><span aria-hidden="true">&times;</span><span class="screen-reader-text">' . esc_html__( 'Dismiss this hint', 'form-captain' ) . '</span></button></form></div>';
	}

	// Rendering.

	public function render(): void {
		if ( ! Capabilities::current_user_has_any() ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'form-captain' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap fc-admin"><h1>' . esc_html__( 'Form Captain', 'form-captain' ) . '</h1>';
		FormsPage::notices();
		$can_forms = current_user_can( Capabilities::MANAGE_FORMS );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$force_setup = isset( $_GET['fc_setup'] );
		if ( $can_forms && ( $force_setup || ! Settings::get( 'general.setup_completed' ) ) ) {
			$this->render_setup();
		}
		echo '<div class="fc-dashboard-grid">';
		$this->render_counters();
		if ( $can_forms ) {
			$this->render_recent();
		}
		$this->render_quick_actions();
		echo '</div>';
		echo '</div>';
	}

	private function render_setup(): void {
		$steps  = $this->steps();
		$recent = $this->forms->list( array( 'per_page' => 1 ) )['items'][0] ?? null;
		$done   = count( array_filter( $steps ) );
		echo '<div class="fc-card fc-setup"><h2>' . esc_html__( 'Get started', 'form-captain' ) . '</h2>';
		/* translators: 1: completed steps, 2: total steps */
		echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d steps completed. You can skip any step.', 'form-captain' ), $done, count( $steps ) ) ) . '</p>';
		echo '<ol class="fc-checklist">';

		// 1. Form from template.
		$this->step_open( $steps['form'], __( 'Create a form from a template', 'form-captain' ) );
		if ( ! $steps['form'] ) {
			echo '<p>';
			foreach ( $this->templates->all() as $tpl ) {
				echo '<a class="button" href="' . esc_url( FormsPage::action_url( 'create', 0, array( 'template' => $tpl['key'] ) ) ) . '">' . esc_html( $tpl['title'] ) . '</a> ';
			}
			echo '</p>';
		} elseif ( $recent ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-edit&form=' . (int) $recent['id'] ) ) . '">' . esc_html( (string) $recent['title'] ) . '</a></p>';
		}
		echo '</div></li>';

		// 2. Recipients and sender.
		$this->step_open( $steps['recipients'], __( 'Check recipient and sender', 'form-captain' ) );
		if ( $recent ) {
			$to = array_map(
				static fn( $a ) => '{admin_email}' === $a ? (string) get_option( 'admin_email' ) : (string) $a,
				(array) ( $recent['settings']['notification']['to'] ?? array() )
			);
			/* translators: %s: list of email addresses */
			echo '<p>' . esc_html( sprintf( __( 'Notifications go to: %s', 'form-captain' ), $to ? implode( ', ', $to ) : '–' ) ) . '</p>';
			/* translators: %s: sender email address */
			echo '<p>' . esc_html( sprintf( __( 'Sender: %s', 'form-captain' ), (string) ( $recent['settings']['email']['from_address'] ?? Settings::get( 'email.from_address' ) ) ) ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-edit&form=' . (int) $recent['id'] . '&tab=notifications' ) ) . '">' . esc_html__( 'Edit notification', 'form-captain' ) . '</a> ';
		} else {
			echo '<p>';
		}
		$this->done_button( 'recipients', $steps['recipients'] );
		echo '</p></div></li>';

		// 3. Embed.
		$this->step_open( $steps['embed'], __( 'Place the form on a page', 'form-captain' ) );
		if ( $recent ) {
			$sc = '[form_captain id="' . $recent['public_id'] . '"]';
			echo '<p><label for="fc-setup-sc">' . esc_html__( 'Shortcode', 'form-captain' ) . '</label> <input type="text" readonly id="fc-setup-sc" class="code" size="34" value="' . esc_attr( $sc ) . '"> <button type="button" class="button button-small fc-copy" data-target="fc-setup-sc">' . esc_html__( 'Copy', 'form-captain' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'Or add the “Form Captain” block to any page.', 'form-captain' ) . '</p>';
			if ( current_user_can( 'edit_pages' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="form_captain_setup_page"><input type="hidden" name="form" value="' . (int) $recent['id'] . '">';
				wp_nonce_field( 'form_captain_setup_page' );
				echo '<button type="submit" class="button">' . esc_html__( 'Create a new draft page with this form', 'form-captain' ) . '</button></form>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Create a form first.', 'form-captain' ) . '</p>';
		}
		echo '</div></li>';

		// 4. Test submission.
		$this->step_open( $steps['test'], __( 'Send a test submission', 'form-captain' ) );
		echo '<p>' . esc_html__( 'Publish the form, open the page and submit it once. The entry and its email status then appear under Entries.', 'form-captain' ) . '</p>';
		if ( current_user_can( Capabilities::READ_ENTRIES ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-entries' ) ) . '">' . esc_html__( 'View entries', 'form-captain' ) . '</a></p>';
		}
		echo '</div></li>';

		// 5. Privacy / retention.
		$this->step_open( $steps['privacy'], __( 'Choose privacy and retention', 'form-captain' ) );
		$labels = array(
			'90d'    => __( '90 days', 'form-captain' ),
			'12m'    => __( '12 months', 'form-captain' ),
			'manual' => __( 'Manual deletion', 'form-captain' ),
		);
		$mode   = (string) Settings::get( 'privacy.retention' );
		/* translators: %s: retention period */
		echo '<p>' . esc_html( sprintf( __( 'Entries are currently kept for: %s', 'form-captain' ), $labels[ $mode ] ?? $mode ) ) . '</p><p>';
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=form-captain-settings&tab=privacy' ) ) . '">' . esc_html__( 'Open privacy settings', 'form-captain' ) . '</a> ';
		}
		$this->done_button( 'privacy', $steps['privacy'] );
		echo '</p></div></li>';

		// 6. Suite plugins.
		$this->step_open( $steps['suite'], __( 'Detect Captain Suite plugins (optional)', 'form-captain' ) );
		$found = array_filter( self::suite_plugins(), static fn( $p ) => $p['active'] );
		if ( $found ) {
			/* translators: %s: list of plugin names */
			echo '<p>' . esc_html( sprintf( __( 'Detected: %s. Adapters can be activated later under Integrations.', 'form-captain' ), implode( ', ', wp_list_pluck( $found, 'label' ) ) ) ) . '</p><p>';
		} else {
			echo '<p>' . esc_html__( 'No Captain Suite plugins detected. Nothing to do.', 'form-captain' ) . '</p><p>';
		}
		$this->done_button( 'suite', $steps['suite'] );
		echo '</p></div></li>';
		echo '</ol>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="form_captain_setup_finish">';
		wp_nonce_field( 'form_captain_setup_finish' );
		echo '<button type="submit" class="button button-link">' . esc_html__( 'Hide setup checklist', 'form-captain' ) . '</button></form>';
		echo '</div>';
	}

	private function step_open( bool $done, string $title ): void {
		echo '<li class="' . ( $done ? 'is-done' : '' ) . '"><span class="fc-check" aria-hidden="true">' . ( $done ? '&#10003;' : '' ) . '</span><div class="fc-step-body"><strong>' . esc_html( $title ) . '</strong>';
		echo $done ? ' <span class="screen-reader-text">' . esc_html__( '(done)', 'form-captain' ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function done_button( string $step, bool $done ): void {
		if ( $done ) {
			return;
		}
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="form_captain_setup_step"><input type="hidden" name="step" value="' . esc_attr( $step ) . '">';
		wp_nonce_field( 'form_captain_setup_step' );
		echo '<button type="submit" class="button button-small">' . esc_html__( 'Mark as done', 'form-captain' ) . '</button></form><p>';
	}

	private function render_counters(): void {
		echo '<div class="fc-card"><h2>' . esc_html__( 'Overview', 'form-captain' ) . '</h2>';
		$active = $this->forms->list(
			array(
				'status'   => 'published',
				'per_page' => 1,
			)
		)['total'];
		echo '<p><span class="fc-stat">' . esc_html( number_format_i18n( $active ) ) . '</span><br>' . esc_html__( 'active forms', 'form-captain' ) . '</p>';
		if ( current_user_can( Capabilities::READ_ENTRIES ) ) {
			$new = (int) ( $this->entries->status_counts()['new'] ?? 0 );
			echo '<p><a class="fc-stat" href="' . esc_url( admin_url( 'admin.php?page=form-captain-entries&status=new' ) ) . '">' . esc_html( number_format_i18n( $new ) ) . '</a><br>' . esc_html__( 'new entries', 'form-captain' ) . '</p>';
		}
		echo '</div>';
	}

	private function render_recent(): void {
		$items = $this->forms->list( array( 'per_page' => 5 ) )['items'];
		echo '<div class="fc-card"><h2>' . esc_html__( 'Recent forms', 'form-captain' ) . '</h2>';
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No forms yet.', 'form-captain' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $items as $form ) {
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=form-captain-edit&form=' . (int) $form['id'] ) ) . '">' . esc_html( (string) $form['title'] ) . '</a> – ' . esc_html( FormsListTable::status_label( (string) $form['status'] ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private function render_quick_actions(): void {
		$links = array();
		if ( current_user_can( Capabilities::MANAGE_FORMS ) ) {
			$links[] = array( admin_url( 'admin.php?page=form-captain-edit' ), __( 'Create form', 'form-captain' ) );
			$links[] = array( admin_url( 'admin.php?page=form-captain-forms' ), __( 'All forms', 'form-captain' ) );
		}
		if ( current_user_can( Capabilities::READ_ENTRIES ) ) {
			$links[] = array( admin_url( 'admin.php?page=form-captain-entries' ), __( 'Entries', 'form-captain' ) );
		}
		if ( current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) {
			$links[] = array( admin_url( 'admin.php?page=form-captain-integrations' ), __( 'Integrations', 'form-captain' ) );
		}
		if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$links[] = array( admin_url( 'admin.php?page=form-captain-settings' ), __( 'Settings', 'form-captain' ) );
		}
		echo '<div class="fc-card"><h2>' . esc_html__( 'Quick actions', 'form-captain' ) . '</h2><ul>';
		foreach ( $links as $l ) {
			echo '<li><a href="' . esc_url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a></li>';
		}
		echo '</ul></div>';
	}
}
