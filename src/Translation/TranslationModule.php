<?php
/**
 * Hooks for form contents per language (P9-07, SET-FC-36): registration on publish, the editor
 * hint about outdated translations, Polylang's string screen and the accepted entry locales.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Translation;

use FormCaptain\Install\Capabilities;
use FormCaptain\Plugin;
use FormCaptain\Repository\FormRepository;

final class TranslationModule {

	public function __construct( private FormRepository $forms ) {}

	public function register( Plugin $plugin ): void {
		unset( $plugin );
		add_action( 'form_captain_form_updated_v1', array( $this, 'on_form_updated' ), 20, 2 );
		add_filter( 'form_captain_entry_locales_v1', array( $this, 'entry_locales' ) );
		add_action( 'admin_init', array( $this, 'register_for_string_screen' ) );
		add_action( 'admin_notices', array( $this, 'editor_notice' ) );
	}

	/**
	 * Publishing registers the texts of the new snapshot; saving a published form registers the
	 * live settings texts again (confirmation, emails), which take effect without publishing.
	 *
	 * @param array<string,mixed> $dto
	 */
	public function on_form_updated( array $dto, string $change = '' ): void {
		unset( $change );
		if ( null === Translations::provider() ) {
			return;
		}
		$form = $this->forms->find( (int) ( $dto['id'] ?? 0 ) );
		if ( $form && ! empty( $form['published_version_id'] ) ) {
			Translations::sync( $form );
		}
	}

	/**
	 * @param mixed $locales
	 * @return string[]
	 */
	public function entry_locales( $locales ): array {
		return array_values( array_unique( array_merge( is_array( $locales ) ? array_map( 'strval', $locales ) : array(), Translations::locales() ) ) );
	}

	/**
	 * Polylang keeps registered strings only for one request: on its string translation screen
	 * the texts of all published forms are registered again.
	 */
	public function register_for_string_screen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page     = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$provider = Translations::provider();
		if ( PolylangProvider::STRINGS_PAGE !== $page || ! $provider instanceof PolylangProvider ) {
			return;
		}
		self::register_all( $this->forms );
	}

	/** Registers the texts of every published form (without changing the outdated marks). */
	public static function register_all( FormRepository $forms ): void {
		foreach ( array( 'published', 'paused' ) as $status ) {
			for ( $page = 1; $page <= 50; $page++ ) {
				$list = $forms->list(
					array(
						'status'   => $status,
						'per_page' => 200,
						'page'     => $page,
					)
				);
				foreach ( $list['items'] as $form ) {
					Translations::sync( $form, true );
				}
				if ( $page * 200 >= $list['total'] ) {
					break;
				}
			}
		}
	}

	/** Hint in the form editor: "N translations outdated". */
	public function editor_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['form'] ) || 'form-captain-edit' !== $_GET['page'] || ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			return;
		}
		$form = $this->forms->find( absint( $_GET['form'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $form ) {
			return;
		}
		$count = Translations::outdated( $form );
		if ( $count <= 0 ) {
			return;
		}
		printf(
			'<div class="notice notice-warning fc-translations-outdated"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of outdated translations */
					_n(
						'%d translation of this form is outdated. Visitors see the source text there until the translation is updated in your multilingual plugin.',
						'%d translations of this form are outdated. Visitors see the source text there until the translations are updated in your multilingual plugin.',
						$count,
						'form-captain'
					),
					$count
				)
			)
		);
	}
}
