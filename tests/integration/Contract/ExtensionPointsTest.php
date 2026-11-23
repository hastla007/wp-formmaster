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

	public function test_contract_version_is_1_3(): void {
		$this->assertSame( '1.3.0', form_captain_contract_version() );
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

	/**
	 * SET-FC-39 (contract 1.3.0): several files per upload field. The render context says
	 * `multiple_files`, the validator gets every file as `files` (and the first one as `file`).
	 */
	public function test_several_files_per_field_reach_the_extension(): void {
		$form = $this->create_published_form( $this->fields() );
		$ctxs = array();
		$this->hook(
			'form_captain_render_field_v1',
			static function ( $html, array $field, array $ctx ) use ( &$ctxs ): string {
				$ctxs[] = $ctx;
				return '<input type="file" id="' . esc_attr( $ctx['id'] ) . '" name="' . esc_attr( $ctx['name'] . ( ! empty( $ctx['multiple_files'] ) ? '[]' : '' ) ) . '" multiple>';
			},
			10,
			3
		);
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertTrue( $ctxs[0]['multiple_files'] );
		$this->assertStringContainsString( 'name="fc[f_file01][]"', $html );

		$lists = UploadedFiles::lists_by_field(
			array(
				'fc' => array(
					'name'     => array( 'f_file01' => array( 'a.pdf', 'b.pdf' ), 'f_name01' => 'single.txt', 'f_deep01' => array( array( 'x' ) ) ),
					'type'     => array( 'f_file01' => array( 'application/pdf', 'application/pdf' ), 'f_name01' => 'text/plain' ),
					'tmp_name' => array( 'f_file01' => array( '/tmp/phpA', '/tmp/phpB' ), 'f_name01' => '/tmp/phpC' ),
					'error'    => array( 'f_file01' => array( UPLOAD_ERR_OK, UPLOAD_ERR_OK ), 'f_name01' => UPLOAD_ERR_OK ),
					'size'     => array( 'f_file01' => array( 10, 20 ), 'f_name01' => 5 ),
				),
			)
		);
		$this->assertSame( array( 'a.pdf', 'b.pdf' ), array_column( $lists['f_file01'], 'name' ) );
		$this->assertSame( 20, $lists['f_file01'][1]['size'] );
		$this->assertSame( array( 'single.txt' ), array_column( $lists['f_name01'], 'name' ), 'a single file input gives a list of one' );
		$this->assertArrayNotHasKey( 'f_deep01', $lists, 'deeper nesting is ignored' );
		$this->assertSame( 'a.pdf', UploadedFiles::by_field( array( 'fc' => array( 'name' => array( 'f_file01' => array( 'a.pdf', 'b.pdf' ) ) ) ) )['f_file01']['name'], 'by_field keeps the 1.1.0 shape: first file' );

		$many = array( 'fc' => array( 'name' => array( 'f_file01' => array_fill( 0, 25, 'x.pdf' ) ), 'error' => array( 'f_file01' => array_fill( 0, 25, UPLOAD_ERR_OK ) ) ) );
		$this->assertCount( UploadedFiles::MAX_FILES, UploadedFiles::lists_by_field( $many )['f_file01'], 'at most 20 files per request' );

		$seen = null;
		$this->hook(
			'form_captain_validate_field_v1',
			static function ( $result, array $field, $value, array $ctx ) use ( &$seen ) {
				$seen = $ctx;
				return array( 'value' => array_map( static fn( array $f ): string => 'ref:' . $f['name'], $ctx['files'] ) );
			},
			10,
			4
		);
		$service = $this->plugin()->get( SubmissionService::class );
		$ok      = $service->submit( $form, array( 'f_name01' => 'Ada' ), array( 'trusted' => true, 'idempotency_key' => 'ext-multi', 'files' => $lists ) );
		$this->assertTrue( $ok['success'], (string) wp_json_encode( $ok ) );
		$this->assertSame( array( 'a.pdf', 'b.pdf' ), array_column( $seen['files'], 'name' ) );
		$this->assertSame( 'a.pdf', $seen['file']['name'], 'the first file stays available as `file`' );
		$this->assertSame( array( 'ref:a.pdf', 'ref:b.pdf' ), $this->plugin()->get( EntryRepository::class )->values_for( (int) $ok['entry_id'] )['f_file01'] );

		// Without files the list is empty, never missing; the 1.1.0 shape (one file) still works.
		$service->submit( $form, array( 'f_name01' => 'Ada' ), array( 'trusted' => true, 'idempotency_key' => 'ext-none' ) );
		$this->assertSame( array(), $seen['files'] );
		$this->assertNull( $seen['file'] );
		$service->submit( $form, array( 'f_name01' => 'Ada' ), array( 'trusted' => true, 'idempotency_key' => 'ext-one', 'files' => array( 'f_file01' => $lists['f_name01'][0] ) ) );
		$this->assertSame( array( 'single.txt' ), array_column( $seen['files'], 'name' ) );
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
		$plain     = $fields;
		$plain[0]['x'] = array( 'condition_group' => array( 'match' => 'any' ) );
		$this->assertSame( array( 'condition_group' => array( 'match' => 'any' ) ), $validator->validate( array( 'schema_version' => 1, 'fields' => $plain ) )['schema']['fields'][0]['x'], 'without an extension for this field stored settings stay (P7-06)' );
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
		$this->hook( 'form_captain_confirmation_v1', static fn( $conf, array $form, int $entry_id, array $values ): array => array( 'type' => 'page', 'page_id' => $draft, 'message' => 'Routed thanks ' . ( $values['f_name01'] ?? '?' ) ), 10, 4 );
		$res = $this->plugin()->get( SubmissionService::class )->submit( $form, array( 'f_name01' => 'Ada', 'f_mail01' => 'ada@example.org' ), array( 'trusted' => true, 'idempotency_key' => 'ext-4' ) );
		$this->assertSame( 'Routed thanks Ada', $res['message'], 'the filter sees the validated values' );
		$this->assertArrayNotHasKey( 'redirect_url', $res, 'an unpublished page is never a redirect target' );
	}

	public function test_display_value_of_extension_fields(): void {
		$this->hook( 'form_captain_display_value_v1', static fn( string $display, array $field, $value ): string => '<i>cv.pdf</i> (12 KB)', 10, 3 );
		$this->assertSame( 'cv.pdf (12 KB)', MailBuilder::display_value( array( 'type' => 'demo_file' ), 'ref:abc123' ) );
		$this->assertSame( 'plain', MailBuilder::display_value( array( 'type' => 'text' ), 'plain' ), 'core types are not filtered' );
	}

	public function test_editor_extension_panels_are_cleaned(): void {
		$this->hook(
			'form_captain_editor_extensions_v1',
			static fn( array $p ): array => array_merge(
				$p,
				array(
					array(
						'key'      => 'logic',
						'scope'    => 'field',
						'label'    => '<b>Grouped conditions</b>',
						'types'    => array( 'text', 'Bad Type' ),
						'controls' => array(
							array( 'key' => 'condition_group', 'type' => 'rules', 'label' => 'Show by rules' ),
							array( 'key' => 'evil', 'type' => 'script' ),
							array( 'key' => 'mode', 'type' => 'select', 'options' => array( array( 'value' => 'a', 'label' => '<i>A</i>' ) ) ),
						),
						'messages' => array( 'too_many_rules' => 'At most 10 rules.', 'Bad Code' => 'x', 'required' => 'override attempt' ),
					),
					array( 'key' => 'logic', 'scope' => 'form', 'controls' => array( array( 'key' => 'dup', 'type' => 'text' ) ) ),
					array( 'key' => 'nocontrols', 'scope' => 'form', 'controls' => array() ),
					array( 'key' => 'routing', 'scope' => 'site', 'controls' => array( array( 'key' => 'routing', 'type' => 'json' ) ) ),
				)
			)
		);
		$ext = \FormCaptain\Admin\EditorPage::extensions();
		$this->assertCount( 1, $ext['panels'], 'duplicate key, no controls and unknown scope are dropped' );
		$panel = $ext['panels'][0];
		$this->assertSame( 'Grouped conditions', $panel['label'] );
		$this->assertSame( array( 'text' ), $panel['types'] );
		$this->assertSame( array( 'condition_group', 'mode' ), array_column( $panel['controls'], 'key' ), 'unknown control types are dropped' );
		$this->assertSame( 'A', $panel['controls'][1]['options'][0]['label'] );
		$this->assertSame( 'At most 10 rules.', $ext['messages']['too_many_rules'] );
		$this->assertArrayNotHasKey( 'Bad Code', $ext['messages'] );
		$boot = $this->plugin()->get( \FormCaptain\Admin\EditorPage::class )->bootstrap( 0 );
		$this->assertNotSame( 'override attempt', $boot['messages']['required'], 'core messages win' );
		$this->assertSame( 'logic', $boot['extensions'][0]['key'] );
	}

	public function test_admin_post_does_not_count_uploaded_files_against_the_request_limit(): void {
		$form       = $this->create_published_form( $this->fields() );
		$controller = $this->plugin()->get( \FormCaptain\Http\AdminPostController::class );
		$limit      = \FormCaptain\Support\Limits::get( 'request_bytes' );
		$files      = array( 'fc' => array( 'name' => array( 'f_file01' => 'cv.pdf' ), 'type' => array( 'f_file01' => 'application/pdf' ), 'tmp_name' => array( 'f_file01' => '/tmp/x' ), 'error' => array( 'f_file01' => 0 ), 'size' => array( 'f_file01' => 5 * $limit ) ) );
		$post       = array( '_fc_form' => $form['public_id'], 'fc' => array( 'f_name01' => 'Ada' ) );
		$with_file  = $controller->process( $post, array( 'CONTENT_LENGTH' => 5 * $limit + 500 ), $files );
		$this->assertNotSame( 'payload_too_large', $with_file['result']['code'], 'the file has its own limit' );
		$too_big    = $controller->process( $post, array( 'CONTENT_LENGTH' => 5 * $limit + $limit + 1 ), $files );
		$this->assertSame( 'payload_too_large', $too_big['result']['code'], 'the form fields alone are still limited' );
	}

	public function test_validate_without_saving_for_intermediate_steps(): void {
		$form   = $this->create_published_form();
		$before = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \FormCaptain\Database\Tables::name( \FormCaptain\Database\Tables::ENTRIES ) );
		$step   = form_captain_validate_v1( (int) $form['id'], array( 'f_name01' => 'Ada' ), array( 'f_name01' ) );
		$this->assertSame( array( 'valid' => true, 'errors' => array(), 'codes' => array() ), $step, 'the required e-mail of a later step is not reported' );
		$bad = form_captain_validate_v1( (string) $form['public_id'], array( 'email' => 'not-an-email' ), array( 'f_mail01' ) );
		$this->assertFalse( $bad['valid'] );
		$this->assertSame( 'invalid_email', $bad['codes']['f_mail01'], 'field keys are accepted' );
		$all = form_captain_validate_v1( (int) $form['id'], array() );
		$this->assertArrayHasKey( 'f_name01', $all['errors'] );
		$this->assertSame( $before, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \FormCaptain\Database\Tables::name( \FormCaptain\Database\Tables::ENTRIES ) ), 'nothing is saved' );
		$this->assertInstanceOf( \WP_Error::class, form_captain_validate_v1( 999999, array() ) );
	}

	public function test_extension_type_without_value_has_no_label(): void {
		$this->hook( 'form_captain_field_types_v1', static fn( array $t ): array => $t + array( 'demo_break' => array( 'label' => 'Break', 'has_value' => false ) ) );
		FieldTypes::reset();
		$this->hook( 'form_captain_render_field_v1', static fn( $h, array $f ): string => 'demo_break' === $f['type'] ? '<h3 class="demo-step">Step 2</h3>' : (string) $h, 10, 2 );
		$form = $this->create_published_form(
			array(
				array( 'id' => 'f_name01', 'type' => 'text', 'key' => 'name', 'label' => 'Name' ),
				array( 'id' => 'f_brk001', 'type' => 'demo_break', 'key' => 'brk', 'label' => 'Step 2' ),
			)
		);
		$html = $this->plugin()->get( Renderer::class )->render( $form );
		$this->assertStringContainsString( '<h3 class="demo-step">Step 2</h3>', $html );
		$this->assertDoesNotMatchRegularExpression( '/<label[^>]+for="[^"]*f_brk001"/', $html );
	}
}
