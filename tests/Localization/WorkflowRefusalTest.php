<?php

namespace Splicewire\Beam\Workflows\Tests\Localization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionContextProvider;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Actions\WorkflowActionService;
use Splicewire\Beam\Workflows\History\WorkflowHistoryResources;
use Splicewire\Beam\Workflows\Localization\WorkflowAuthorizationException;
use Splicewire\Beam\Workflows\Localization\WorkflowError;
use Splicewire\Beam\Workflows\Localization\WorkflowResponses;
use Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionData;
use Splicewire\Beam\Workflows\Reactions\WorkflowActionConflict;
use Splicewire\Beam\Workflows\Reactions\WorkflowReactionResources;
use Splicewire\Beam\Workflows\Reactions\WorkflowReactionService;
use Splicewire\Beam\Workflows\Tests\CalendarWorkflowTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class WorkflowRefusalTest extends CalendarWorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'beam.localization.pseudo' => true, 'beam.localization.http_profile' => true,
            'beam.localization.supported' => ['en', 'en-XA']]);
        app()->instance(WorkflowActionContextProvider::class, new class implements WorkflowActionContextProvider
        {
            public function current(): WorkflowActionContext
            {
                return new WorkflowActionContext('user:editor', 'user:creator', 'tenant:test');
            }
        });
        $subject = new class extends Model
        {
            protected $table = 'fake_processes';
        };
        $this->mock(WorkflowActionService::class, function ($mock) use ($subject) {
            $mock->shouldReceive('authorize')->andReturn($subject);
            $mock->shouldReceive('prepare')->andReturnUsing(fn ($data) => $data);
        });
        WorkflowReactionResources::mount('localized-reactions');
        WorkflowHistoryResources::mount('localized-history');
        Route::post('localized-delay', fn () => app(WorkflowReactionService::class)->configure(
            new WorkflowReactionData('fixture', '1', 'publish', 'missing-handler', [], -1, 'UTC'),
            app(WorkflowActionContextProvider::class)->current(),
        ));
        Route::post('localized-read', fn () => app(WorkflowReactionService::class)->read(
            'absent', app(WorkflowActionContextProvider::class)->current(),
        ));
    }

    public function test_real_operations_preserve_english_and_add_explicit_codes_without_storage_changes(): void
    {
        self::assertTrue(class_exists(WorkflowError::class), 'The new typed workflow refusal API must exist.');

        $binding = (string) Str::uuid();
        $delivery = (string) Str::uuid();
        $data = new WorkflowReactionData('fixture', '1', 'publish', 'missing-handler', ['authored' => 'raw handler input'], 0, 'UTC');
        self::assertNotEmpty($data->actionPayload, 'The mounted configure DTO requires a nonempty action payload before handler lookup.');
        DB::table('workflow_reaction_bindings')->insert([
            'id' => $binding, 'subject_type' => 'fixture', 'subject_id' => '1', 'transition' => 'publish',
            'principal' => 'user:editor', 'creator' => 'user:creator', 'tenant_token' => 'tenant:test',
            'configuration' => json_encode(['authored' => $data->toArray(), 'template' => []], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workflow_reaction_deliveries')->insert([
            'id' => $delivery, 'binding_id' => $binding, 'transition_id' => (string) Str::uuid(),
            'status' => 'failed', 'attempts' => 1, 'request' => '[]', 'source_snapshot' => '[]',
            'causal_path' => json_encode([$binding, $binding], JSON_THROW_ON_ERROR), 'blockers' => '["raw diagnostic"]',
            'principal' => 'user:editor', 'creator' => 'user:creator', 'tenant_token' => 'tenant:test',
            'anchored_at' => now(), 'created_at' => now(),
        ]);
        $cases = [
            ['localized-reactions/disable', ['id' => 'absent', 'expected_revision' => 1], 403, WorkflowError::FollowUpUnavailable, null],
            ['localized-read', [], 403, WorkflowError::FollowUpUnavailable, null],
            ['localized-reactions/disable', ['id' => $binding, 'expected_revision' => 2], 409, WorkflowError::FollowUpChanged, null],
            ['localized-reactions/retry', ['id' => 'absent', 'expected_attempts' => 1], 403, WorkflowError::DeliveryUnavailable, null],
            ['localized-reactions/retry', ['id' => $delivery, 'expected_attempts' => 2], 409, WorkflowError::DeliveryChanged, null],
            ['localized-reactions/retry', ['id' => $delivery, 'expected_attempts' => 1], 422, WorkflowError::CausalCycle, 'id'],
            ['localized-reactions/configure', $data->toArray(), 422, WorkflowError::HandlerUnavailable, 'action_kind'],
            ['localized-delay', [], 422, WorkflowError::InvalidDelay, 'calendar_days'],
            ['localized-history/read', ['subject_kind' => 'fixture', 'subject_id' => '1', 'limit' => 101], 422, WorkflowError::HistoryPageSize, 'limit'],
        ];
        $before = $this->storedRows();
        foreach (['en', 'en-XA'] as $locale) {
            foreach ($cases as [$uri, $input, $status, $error, $field]) {
                $response = $this->withHeaders(['Accept-Language' => $locale])->postJson($uri, $input)->assertStatus($status);
                $diagnostic = $locale.' '.$uri.' response: '.$response->getContent();
                if ($field === null) {
                    self::assertSame($error->code(), $response->json('code'), $diagnostic);
                } else {
                    self::assertSame([$field => [$error->code()]], $response->json('codes'), $diagnostic);
                }
                $message = $field === null ? $response->json('message') : $response->json('errors.'.$field.'.0');
                self::assertIsString($message, $diagnostic);
                if ($locale === 'en') {
                    self::assertSame($error->defaultMessage(), $message, $diagnostic);
                } else {
                    self::assertStringContainsString('⟦', $message, $diagnostic);
                }
                if ($field === null) {
                    self::assertSame(['message' => $message, 'code' => $error->code()], $response->json(), $diagnostic);
                } else {
                    self::assertSame([$field => [$message]], $response->json('errors'), $diagnostic);
                }
                self::assertSame($before, $this->storedRows());
            }
        }
    }

    public function test_parent_types_prepared_authorization_headers_flags_and_browser_controls(): void
    {
        self::assertTrue(class_exists(WorkflowAuthorizationException::class), 'The new typed workflow authorization API must exist.');
        self::assertTrue(class_exists(WorkflowError::class), 'The new typed workflow refusal API must exist.');

        $authorization = WorkflowAuthorizationException::for(WorkflowError::FollowUpUnavailable);
        self::assertInstanceOf(AuthorizationException::class, $authorization);
        $prepared = new AccessDeniedHttpException($authorization->getMessage(), $authorization, 0, ['X-Workflow' => 'kept']);
        $json = WorkflowResponses::render($prepared, Request::create('/', 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
        self::assertSame(403, $json->getStatusCode());
        self::assertSame('kept', $json->headers->get('X-Workflow'));
        self::assertSame(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, $json->getEncodingOptions());
        self::assertSame($authorization, $prepared->getPrevious());
        self::assertNull(WorkflowResponses::render($prepared, Request::create('/')));
        self::assertNull(WorkflowResponses::render(new AuthorizationException('unknown diagnostic'), Request::create('/', server: ['HTTP_ACCEPT' => 'application/json'])));
        $conflict = WorkflowActionConflict::for(WorkflowError::DeliveryChanged);
        self::assertInstanceOf(\Splicewire\Beam\Calendars\Actions\ActionConflict::class, $conflict);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame(WorkflowError::DeliveryChanged->defaultMessage(), $conflict->getMessage());
        self::assertNull($conflict->render(Request::create('/')));
        self::assertNull((new WorkflowActionConflict('unknown diagnostic'))->render(Request::create('/', server: ['HTTP_ACCEPT' => 'application/json'])));
        self::assertInstanceOf(\Illuminate\Validation\ValidationException::class, CodedValidationException::for('id', WorkflowError::CausalCycle));
    }

    private function storedRows(): array
    {
        return [DB::table('workflow_reaction_bindings')->orderBy('id')->get()->toJson(),
            DB::table('workflow_reaction_deliveries')->orderBy('id')->get()->toJson()];
    }
}
