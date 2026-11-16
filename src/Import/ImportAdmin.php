<?php
/**
 * Admin UI for the type A import in the forms overview (P9-06): paste source → preview
 * with conversion report → confirm. Capability + nonce are checked by FormsPage::dispatch().
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Import;

use FormCaptain\Admin\FormsPage;
use FormCaptain\Install\Capabilities;

final class ImportAdmin {

	public const ACTION_PREVIEW = 'type_a_preview';
	public const ACTION_IMPORT  = 'type_a_import';

	/** Lifetime of a pending preview (seconds). */
	private const TTL = 1800;

	public function __construct( private ImportService $service ) {}

	private static function transient( string $token ): string {
		return 'form_captain_type_a_' . get_current_user_id() . '_' . $token;
	}

	/**
	 * Handles the two admin-post actions (already verified). Returns the redirect URL.
	 */
	public function handle( string $action, string $list, FormsPage $page ): string {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return add_query_arg( 'fc_error', 'forbidden', $list );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in FormsPage::dispatch().
		if ( self::ACTION_PREVIEW === $action ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw source is parsed by our own tokenizer and never output unescaped.
			$source = isset( $_POST['fc_type_a_source'] ) && is_string( $_POST['fc_type_a_source'] ) ? wp_unslash( $_POST['fc_type_a_source'] ) : '';
			$title  = isset( $_POST['fc_type_a_title'] ) && is_string( $_POST['fc_type_a_title'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_type_a_title'] ) ) : '';
			if ( strlen( $source ) > ImportService::MAX_SOURCE_BYTES ) {
				return add_query_arg( 'fc_error', 'payload_too_large', $list );
			}
			if ( '' === trim( $source ) ) {
				return add_query_arg( 'fc_error', 'import_no_source', $list );
			}
			$token = bin2hex( random_bytes( 12 ) );
			set_transient( self::transient( $token ), array( 'source' => $source, 'title' => $title ), self::TTL );
			return add_query_arg( 'fc_type_a', $token, $list ) . '#fc-type-a';
		}
		$token   = isset( $_POST['fc_type_a_token'] ) ? sanitize_key( wp_unslash( $_POST['fc_type_a_token'] ) ) : '';
		$pending = '' !== $token ? get_transient( self::transient( $token ) ) : false;
		// phpcs:enable
		if ( ! is_array( $pending ) ) {
			return add_query_arg( 'fc_error', 'import_expired', $list );
		}
		$result = $this->service->import( (string) $pending['source'], (string) $pending['title'] );
		if ( ! is_wp_error( $result ) ) {
			delete_transient( self::transient( $token ) );
			$removed = array();
			foreach ( (array) $result['report']['findings'] as $f ) {
				if ( 'info' !== $f['severity'] ) {
					$removed[] = (string) $f['message'];
				}
			}
			$result = array(
				'form_id' => (int) $result['form_id'],
				'report'  => array(
					'recipients' => array( '{admin_email}' ),
					'sanitized'  => array(),
					'removed'    => array_values( array_unique( $removed ) ),
				),
			);
		}
		return $page->import_result_url( $result, $list );
	}

	/** Renders the import box, or the preview with report when a token is present. */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of the current user's own pending preview.
		$token   = isset( $_GET['fc_type_a'] ) ? sanitize_key( wp_unslash( $_GET['fc_type_a'] ) ) : '';
		$pending = '' !== $token ? get_transient( self::transient( $token ) ) : false;
		echo '<details class="fc-import fc-import-type-a" id="fc-type-a"' . ( is_array( $pending ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Import from another form format', 'form-captain' ) . '</summary>';
		if ( is_array( $pending ) ) {
			$this->render_preview( $this->service->preview( (string) $pending['source'], (string) $pending['title'] ), $token );
		} else {
			$this->render_input();
		}
		echo '</details>';
	}

	private function render_input(): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="form_captain_form_' . esc_attr( self::ACTION_PREVIEW ) . '">';
		wp_nonce_field( FormsPage::nonce_action( self::ACTION_PREVIEW ) );
		echo '<p><label for="fc-type-a-title">' . esc_html__( 'Form title', 'form-captain' ) . '</label><br>';
		echo '<input type="text" class="regular-text" id="fc-type-a-title" name="fc_type_a_title" maxlength="170"></p>';
		echo '<p><label for="fc-type-a-source">' . esc_html__( 'Form source in shortcode form format type A (max. 256 KB)', 'form-captain' ) . '</label><br>';
		echo '<textarea id="fc-type-a-source" name="fc_type_a_source" rows="12" class="large-text code" required></textarea></p>';
		echo '<p class="description">' . esc_html__( 'The source is only analysed, never executed. You will see a preview with a conversion report before anything is saved. The form is created as a draft; the original form remains unchanged.', 'form-captain' ) . '</p>';
		submit_button( __( 'Analyse and preview', 'form-captain' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * @param array<string,mixed> $preview
	 */
	private function render_preview( array $preview, string $token ): void {
		$states   = array(
			'complete' => __( 'Complete', 'form-captain' ),
			'adjusted' => __( 'With adjustments', 'form-captain' ),
			'lossy'    => __( 'With losses', 'form-captain' ),
			'blocked'  => __( 'Not importable', 'form-captain' ),
		);
		$outcomes = array(
			'taken'    => __( 'taken over', 'form-captain' ),
			'adjusted' => __( 'adjusted', 'form-captain' ),
			'dropped'  => __( 'dropped', 'form-captain' ),
			'setting'  => __( 'setting', 'form-captain' ),
		);
		$counts   = (array) $preview['counts'];
		echo '<h3>' . esc_html__( 'Preview and conversion report', 'form-captain' ) . '</h3>';
		echo '<p><strong>' . esc_html( (string) $preview['title'] ) . '</strong> – ' . esc_html( $states[ $preview['status'] ] ?? (string) $preview['status'] ) . '</p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: total elements, 2: taken over, 3: adjusted, 4: dropped */
				__( 'Elements: %1$d – taken over: %2$d, adjusted: %3$d, dropped: %4$d', 'form-captain' ),
				(int) ( $counts['total'] ?? 0 ),
				(int) ( $counts['taken'] ?? 0 ),
				(int) ( $counts['adjusted'] ?? 0 ),
				(int) ( $counts['dropped'] ?? 0 )
			)
		) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Original tag', 'form-captain' ), __( 'Target type', 'form-captain' ), __( 'Key', 'form-captain' ), __( 'Label', 'form-captain' ), __( 'Result', 'form-captain' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( (array) $preview['elements'] as $el ) {
			echo '<tr><td><code>' . esc_html( '' !== $el['tag'] ? (string) $el['tag'] : __( '(text block)', 'form-captain' ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) $el['target_type'] ) . '</td><td><code>' . esc_html( (string) $el['key'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $el['label'] ) . '</td><td>' . esc_html( $outcomes[ $el['outcome'] ] ?? (string) $el['outcome'] );
			if ( $el['codes'] ) {
				echo ' <small>(' . esc_html( implode( ', ', (array) $el['codes'] ) ) . ')</small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $preview['warnings'] ) {
			echo '<p>' . esc_html__( 'Notes:', 'form-captain' ) . '</p><ul class="ul-disc">';
			foreach ( (array) $preview['warnings'] as $w ) {
				echo '<li>' . esc_html( (string) $w ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p class="description">' . esc_html__( 'After the import: check recipients and consent text, test the form, then publish it and replace the old shortcode on your pages.', 'form-captain' ) . '</p>';
		if ( $preview['importable'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="form_captain_form_' . esc_attr( self::ACTION_IMPORT ) . '">';
			echo '<input type="hidden" name="fc_type_a_token" value="' . esc_attr( $token ) . '">';
			wp_nonce_field( FormsPage::nonce_action( self::ACTION_IMPORT ) );
			submit_button( __( 'Import as draft', 'form-captain' ), 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=form-captain-forms' ) ) . '">' . esc_html__( 'Cancel', 'form-captain' ) . '</a>';
			echo '</form>';
		} else {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=form-captain-forms' ) ) . '">' . esc_html__( 'Back', 'form-captain' ) . '</a></p>';
		}
	}
}
