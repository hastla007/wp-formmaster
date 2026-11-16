<?php
/**
 * Contract 1.1.0: extension points for Form Captain Pro (P7-03 follow-up for P7-07, P7-08, P7-10).
 *
 * @package FormCaptain
 */

declare(strict_types=1);

namespace FormCaptain\Tests\Integration\Contract;

use FormCaptain\Domain\Fields\FieldTypes;
use FormCaptain\Domain\Schema\SchemaValidator;
use FormCaptain\Frontend\Renderer;
use FormCaptain\Mail\MailBuilder;
use FormCaptain\Repository\EntryRepository;
use FormCaptain\Service\FormService;
use FormCaptain\Service\SubmissionService;
use FormCaptain\Support\UploadedFiles;
use FormCaptain\Tests\Integration\TestCase;

final class ExtensionPointsTest extends TestCase {

	/** @var array<int,array{0:string,1:callable,2:int,3:int}> */
	private array $hooks = array();

	public function set_up(): void {
		parent::set_up();
		$this->hook( 'form_captain_field_types_v1', static fn( array $t ): array => $t + array( 'demo_file' => array( 'label' => 'Demo file', 'has_value' => true ) ) );
		FieldTypes::reset();
	}

	public function tear_down(): void {
		foreach ( $this->hooks as $h ) {
			remove_filter( $h[0], $h[1], $h[2] );
		}
		FieldTypes::reset();
		parent::tear_down();
	}

	private function hook( string $name, callable $cb, int $prio = 10, int $args = 1 ): void {
		add_filter( $name, $cb, $prio, $args );
		$this->hooks[] = array( $name, $cb, $prio, $args );
	}

	/** @return array<int,array<string,mixed>> */
	private function fields(): array {
		return array(
			array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true ),
			array( 'id' => 'f_file01', 'type' => 'demo_file', 'key' => 'attachment', 'label' => 'Attachment', 'required' => true ),
		);
	}

	public function test_contract_version_is_1_1(): void {
		$this->assertSame( '1.1.0', form_captain_contract_version() );
	}

	public function test_extension_field_is_rendered_by_the_extension_inside_the_core_frame(): void {
		$form     = $this->create_published_form( $this->fields() );
		$renderer = $this->plugin()->get( Renderer::class );
		$html     = $renderer->render( $form );
		$this->assertStringNotContainsString( 'Attachment', $html, 'without a renderer the field is left out' );

		$this->hook(
			'form_captain_render_field_v1',
			static fn( $html, array $field, array $ctx ): string => '<input type="file" id="' . esc_attr( $ctx['id'] ) . '" name="' . esc_attr( $ctx['name'] ) . '" aria-describedby="' . esc_attr( $ctx['aria-describedby'] ) . '">',
			10,
			3
		);
		$this->hook( 'form_captain_form_attributes_v1', static fn(): array => array( 'enctype' => 'multipart/form-data', 'data-fc-x-steps' => '2', 'action' => 'https://evil.example/', 'onsubmit' => 'x()' ) );
		$this->hook( 'form_captain_field_attributes_v1', static fn( array $a, array $field ): array => array( 'data-fc-x-step' => '2', 'class' => 'pro-step', 'data-fc-field' => 'spoof', 'onclick' => 'x()' ), 10, 2 );
		$html = $renderer->render( $form );
		$this->assertMatchesRegularExpression( '/<label class="fc-label" for="[^"]+-f_file01">Attachment <span class="fc-required"/', $html );
		$this->assertStringContainsString( 'type="file"', $html );
		$this->assertStringContainsString( 'name="fc[f_file01]"', $html );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'data-fc-x-steps="2"', $html );
		$this->assertStringContainsString( 'data-fc-x-step="2"', $html );
		$this->assertStringContainsString( 'pro-step', $html );
		$this->assertStringNotContainsString( 'evil.example', $html, 'core attributes cannot be replaced' );
		$this->assertStringNotContainsString( 'onsubmit', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( 'data-fc-field="spoof"', $html );
	}

	public function test_extension_validator_gets_the_uploaded_file_and_its_value_is_stored(): void {
		$form = $this->create_published_form( $this->fields() );
		$seen = null;
		$this->hook(
			'form_captain_validate_field_v1',
			static function ( $result, array $field, $value, array $ctx ) use ( &$seen ) {
				$seen = $ctx['file'];
				if ( ! is_array( $ctx['file'] ) || UPLOAD_ERR_OK !== $ctx['file']['error'] ) {
					return array( 'code' => 'file_missing' );
				}
				return array( 'value' => 'ref:abc123' );
			},
			10,
			4
		);
		$this->hook( 'form_captain_field_error_message_v1', static fn( $msg, string $code ): string => 'file_missing' === $code ? '<b>Please attach a file.</b>' : $msg, 10, 2 );

		$service = $this->plugin()->get( SubmissionService::class );
		$fail    = $service->submit( $form, array( 'f_name01' => 'Ada' ), array( 'trusted' => true, 'idempotency_key' => 'ext-1' ) );
		$this->assertFalse( $fail['success'] );
		$this->assertSame( 'Please attach a file.', $fail['errors']['f_file01'], 'extension message, tags stripped' );

		$files = UploadedFiles::by_field(
			array(
				'fc' => array(
					'name'     => array( 'f_file01' => 'cv.pdf', 'nested' => array( 'x' ) ),
					'type'     => array( 'f_file01' => 'application/pdf' ),
					'tmp_name' => array( 'f_file01' => '/tmp/php123' ),
					'error'    => array( 'f_file01' => UPLOAD_ERR_OK ),
					'size'     => array( 'f_file01' => 1234 ),
				),
			)
		);
		$this->assertSame( array( 'f_file01' ), array_keys( $files ), 'invalid names are dropped' );
		$ok = $service->submit( $form, array( 'f_name01' => 'Ada' ), array( 'trusted' => true, 'idempotency_key' => 'ext-2', 'files' => $files ) );
		$this->assertTrue( $ok['success'], (string) wp_json_encode( $ok ) );
		$this->assertSame( 'cv.pdf', $seen['name'] );
		$this->assertSame( 'ref:abc123', $this->plugin()->get( EntryRepository::class )->values_for( (int) $ok['entry_id'] )['f_file01'] );
	}

	public function test_extension_field_without_validator_never_stores_a_value(): void {
		$form = $this->create_published_form( $this->fields() );
		$res  = $this->plugin()->get( SubmissionService::class )->submit( $form, array( 'f_name01' => 'Ada', 'f_file01' => 'forged' ), array( 'trusted' => true, 'idempotency_key' => 'ext-3' ) );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'required', $res['error_codes']['f_file01'] );
	}

	public function test_extension_settings_on_fields_and_forms(): void {
		$this->hook(
			'form_captain_normalize_field_v1',
			static function ( $ext, array $field ) {
				if ( 'demo_file' !== ( $field['type'] ?? '' ) ) {
					return null;
				}
				$max = (int) ( $field['x']['max_kb'] ?? 0 );
				return $max > 0 ? array( 'settings' => array( 'max_kb' => $max ) ) : array( 'errors' => array( 'max_kb' => 'max_kb_required' ) );
			},
			10,
			2
		);
		$validator = new SchemaValidator();
		$fields    = $this->fields();
		$result    = $validator->validate( array( 'schema_version' => 1, 'fields' => $fields ) );
		$this->assertSame( 'max_kb_required', $result['errors']['fields.1.x.max_kb'] );
		$fields[1]['x'] = array( 'max_kb' => 512, 'evil' => 'dropped' );
		$result         = $validator->validate( array( 'schema_version' => 1, 'fields' => $fields ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( 'max_kb' => 512 ), $result['schema']['fields'][1]['x'] );
		$this->assertArrayNotHasKey( 'x', $result['schema']['fields'][0] );

		$service = $this->plugin()->get( FormService::class );
		$schema  = $result['schema'];
		$kept    = $service->sanitize_settings( array( 'x' => array( 'routing' => array( 'a' ) ) ), $schema );
		$this->assertSame( array( 'routing' => array( 'a' ) ), $kept['settings']['x'], 'without an extension stored settings stay (P7-06)' );
		$this->hook( 'form_captain_normalize_form_settings_v1', static fn( $ext, array $x ): array => array( 'settings' => array( 'routing' => array_slice( (array) ( $x['routing'] ?? array() ), 0, 1 ) ) ), 10, 2 );
		$norm = $service->sanitize_settings( array( 'x' => array( 'routing' => array( 'a', 'b' ) ) ), $schema );
		$this->assertSame( array( 'routing' => array( 'a' ) ), $norm['settings']['x'] );
	}

	public function test_routing_of_recipients_and_confirmation(): void {
		$form = $this->create_published_form();
		$this->hook(
			'form_captain_notification_recipients_v1',
			static fn( $to, array $form, int $entry_id, array $values ) => str_contains( (string) ( $values['f_msg001'] ?? '' ), 'invoice' ) ? array( 'billing@example.org', "x@example.org\r\nBcc: y@example.org", 'not-an-email' ) : $to,
			10,
			4
		);
		$builder = $this->plugin()->get( MailBuilder::class );
		$mail    = $builder->admin( $form, array( 'id' => 1, 'form_version_id' => (int) $form['published_version_id'] ), array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org', 'f_msg001' => 'invoice please' ) );
		$this->assertSame( array( 'billing@example.org' ), $mail['to'], 'routed list passes the same checks' );
		$mail = $builder->admin( $form, array( 'id' => 2, 'form_version_id' => (int) $form['published_version_id'] ), array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org', 'f_msg001' => 'hello' ) );
		$this->assertSame( array( 'admin@example.org' ), $mail['to'] );

		$draft = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$this->hook( 'form_captain_confirmation_v1', static fn( $conf, array $form, int $entry_id ): array => array( 'type' => 'page', 'page_id' => $draft, 'message' => 'Routed thanks' ), 10, 3 );
		$res = $this->plugin()->get( SubmissionService::class )->submit( $form, array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org' ), array( 'trusted' => true, 'idempotency_key' => 'ext-4' ) );
		$this->assertSame( 'Routed thanks', $res['message'] );
		$this->assertArrayNotHasKey( 'redirect_url', $res, 'an unpublished page is never a redirect target' );
	}

	public function test_display_value_of_extension_fields(): void {
		$this->hook( 'form_captain_display_value_v1', static fn( string $display, array $field, $value ): string => '<i>cv.pdf</i> (12 KB)', 10, 3 );
		$this->assertSame( 'cv.pdf (12 KB)', MailBuilder::display_value( array( 'type' => 'demo_file' ), 'ref:abc123' ) );
		$this->assertSame( 'plain', MailBuilder::display_value( array( 'type' => 'text' ), 'plain' ), 'core types are not filtered' );
	}
}
