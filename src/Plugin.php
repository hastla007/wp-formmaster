<?php
/**
 * Plugin kernel: lazy service container and module registration.
 *
 * Modules are classes with a public `register( Plugin $plugin ): void` method.
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain;

use FormCaptain\Install\Activator;

final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string,callable(Plugin):object> */
	private array $factories = array();

	/** @var array<string,object> */
	private array $services = array();

	/** Module classes registered on boot, in order. */
	private const MODULES = array(
		Jobs\Scheduler::class,
		Http\RestController::class,
		Http\AdminPostController::class,
		Service\DoubleOptIn::class,
		Frontend\Shortcode::class,
		Frontend\Block::class,
		Frontend\Assets::class,
		Frontend\YoastCompat::class,
		Admin\Menu::class,
		Admin\FormsPage::class,
		Admin\EditorPage::class,
		Admin\EntriesPage::class,
		Admin\SettingsPage::class,
		Admin\IntegrationsPage::class,
		Admin\DashboardPage::class,
		Admin\AdminRest::class,
		Privacy\PrivacyIntegration::class,
		Integrations\ConnectorRegistry::class,
		Cli\Commands::class,
		// Form contents per language (SET-FC-36).
		Translation\TranslationModule::class,
	);

	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self();
		self::$instance->init();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** For tests. */
	public static function reset(): void {
		self::$instance = null;
	}

	private function init(): void {
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'form-captain', false, dirname( plugin_basename( FORM_CAPTAIN_FILE ) ) . '/languages' );
			},
			1
		);
		Activator::maybe_upgrade();
		$this->register_default_services();
		foreach ( self::MODULES as $module ) {
			if ( class_exists( $module ) ) {
				$this->get( $module )->register( $this );
			}
		}
		add_action(
			'plugins_loaded',
			static function (): void {
				do_action( 'form_captain_loaded_v1', FORM_CAPTAIN_CONTRACT_VERSION );
			},
			20
		);
	}

	private function register_default_services(): void {
		$this->set( Repository\FormRepository::class, static fn() => new Repository\FormRepository() );
		$this->set( Repository\EntryRepository::class, static fn() => new Repository\EntryRepository() );
		$this->set( Repository\JobRepository::class, static fn() => new Repository\JobRepository() );
		$this->set( Domain\Schema\SchemaValidator::class, static fn() => new Domain\Schema\SchemaValidator() );
	}

	/**
	 * @param callable(Plugin):object $factory
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( isset( $this->factories[ $id ] ) ) {
				$this->services[ $id ] = ( $this->factories[ $id ] )( $this );
			} elseif ( class_exists( $id ) ) {
				$this->services[ $id ] = $this->autowire( $id );
			} else {
				throw new \InvalidArgumentException( 'Unknown service ' . $id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		return $this->services[ $id ];
	}

	/** Constructor autowiring for class-typed parameters. */
	private function autowire( string $class ): object {
		$ref  = new \ReflectionClass( $class );
		$ctor = $ref->getConstructor();
		if ( null === $ctor ) {
			return new $class();
		}
		$args = array();
		foreach ( $ctor->getParameters() as $param ) {
			$type = $param->getType();
			$dep  = $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : '';
			if ( '' !== $dep && ( class_exists( $dep ) || interface_exists( $dep ) ) ) {
				$args[] = self::class === $dep ? $this : $this->get( $dep );
			} elseif ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
			} else {
				throw new \InvalidArgumentException( 'Cannot autowire ' . $class ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
		return $ref->newInstanceArgs( $args );
	}
}
