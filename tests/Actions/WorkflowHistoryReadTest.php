<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionContextProvider;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowTransitionFact;
use Splicewire\Beam\Workflows\History\Ops\ReadWorkflowHistory;

class HistoryReadSubject extends Model
{
    protected $table = 'fake_processes';

    protected $guarded = [];
}

beforeEach(function () {
    app(SubjectResolverRegistry::class)->register('history-subject', fn (string $id) => HistoryReadSubject::find($id));
    $this->historyContext = new class implements WorkflowActionContextProvider
    {
        public string $principal = 'user:reader';

        public function current(): WorkflowActionContext
        {
            return new WorkflowActionContext($this->principal, $this->principal, 'tenant:test');
        }
    };
    app()->instance(WorkflowActionContextProvider::class, $this->historyContext);
    app()->instance(WorkflowActionAuthority::class, new class implements WorkflowActionAuthority
    {
        public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void
        {
            if ($context->principal !== 'user:reader' || $context->tenantToken !== 'tenant:test') {
                throw new AuthorizationException('History access is denied.');
            }
        }
    });
});

function historyFact(HistoryReadSubject $subject, string $at): WorkflowTransitionFact
{
    return WorkflowTransitionFact::create([
        'subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->id,
        'transition' => 'publish', 'from' => ['draft' => 1], 'to' => ['published' => 1],
        'actor' => 'user:publisher', 'run_id' => 'run:original', 'causation_id' => 'fact:previous',
        'causal_path' => ['binding:distribution'], 'version_id' => '00000000-0000-0000-0000-000000000007',
        'occurred_at' => $at,
    ]);
}

it('reads authorized durable facts as bounded pages without including another subject or activity log data', function () {
    $subject = HistoryReadSubject::create([]);
    $other = HistoryReadSubject::create([]);
    $first = historyFact($subject, '2026-09-12T12:00:00Z');
    $last = historyFact($subject, '2026-09-12T13:00:00Z');
    historyFact($other, '2026-09-12T14:00:00Z');
    $input = ['subject_kind' => 'history-subject', 'subject_id' => (string) $subject->id, 'limit' => 1];
    $result = ReadWorkflowHistory::handle(null, Request::create('/', 'POST', $input), null);
    $next = ReadWorkflowHistory::handle(null, Request::create('/', 'POST', $input + ['before' => $result->nextBefore]), null);

    expect($result->facts)->toHaveCount(1)->and($result->facts[0]->transitionId)->toBe((string) $last->id)
        ->and($result->facts[0]->toArray())->toMatchArray([
            'transition_id' => (string) $last->id, 'transition' => 'publish', 'from' => ['draft' => 1], 'to' => ['published' => 1],
            'actor' => 'user:publisher', 'run_id' => 'run:original', 'causation_id' => 'fact:previous',
            'causal_path' => ['binding:distribution'], 'occurred_at' => '2026-09-12T13:00:00.000000Z',
            'definition_version' => '00000000-0000-0000-0000-000000000007',
        ])->and($next->facts[0]->transitionId)->toBe((string) $first->id)->and($next->nextBefore)->toBeNull();
});

it('refuses a forged history principal and a cursor from another subject', function () {
    $subject = HistoryReadSubject::create([]);
    $otherFact = historyFact(HistoryReadSubject::create([]), '2026-09-12T14:00:00Z');
    $input = ['subject_kind' => 'history-subject', 'subject_id' => (string) $subject->id];
    expect(fn () => ReadWorkflowHistory::handle(null, Request::create('/', 'POST', $input + ['before' => $otherFact->id]), null))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->historyContext->principal = 'user:revoked';
    expect(fn () => ReadWorkflowHistory::handle(null, Request::create('/', 'POST', $input + ['principal' => 'user:reader']), null))
        ->toThrow(AuthorizationException::class, 'History access is denied.');
});

it('rejects an unbounded history page size', function () {
    expect(fn () => ReadWorkflowHistory::handle(null, Request::create('/', 'POST', [
        'subject_kind' => 'history-subject', 'subject_id' => '1', 'limit' => 101,
    ]), null))->toThrow(Illuminate\Validation\ValidationException::class);
});
