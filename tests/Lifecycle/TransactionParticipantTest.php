<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Contracts\ParticipatesInWorkflowTransaction;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class ParticipatingTicket extends Model implements WorkflowManaged, ParticipatesInWorkflowTransaction
{
    use WorkflowManagedTrait;
    protected $table = 'participating_tickets';
    protected $guarded = [];
    public $timestamps = false;
    public static bool $refuse = false;
    public function workflowType(): string { return 'participating-ticket'; }
    public function participateInWorkflowTransaction(string $transition, TransitionContext $context): void
    {
        $this->getConnection()->table('participant_writes')->insert(['actor' => $context->actor, 'before_status' => $this->status]);
        if (self::$refuse) { throw new RuntimeException('participant refused'); }
    }
}

beforeEach(function () {
    ParticipatingTicket::$refuse = false;
    Illuminate\Database\Eloquent\Relations\Relation::morphMap(['participating_ticket' => ParticipatingTicket::class]);
    Schema::create('participating_tickets', function (Blueprint $t) {
        $t->increments('id'); $t->string('status')->default('draft'); $t->uuid('workflow_version')->nullable();
    });
    Schema::create('participant_writes', function (Blueprint $t) { $t->increments('id'); $t->string('actor')->nullable(); $t->string('before_status'); });
    app(DefinitionStore::class)->ensureSystemLineage('participant.lifecycle', 'Participant', WorkflowBlueprint::fromArray([
        'name' => 'participant.lifecycle', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('participating-ticket', 'participant.lifecycle');
});

it('participates only after admission and before the marking is written', function () {
    $ticket = ParticipatingTicket::create([]);
    $service = app(LifecycleService::class);
    expect($service->transition($ticket, 'unknown')->applied)->toBeFalse();
    expect($ticket->getConnection()->table('participant_writes')->count())->toBe(0);
    expect($service->transition($ticket, 'publish', context: new TransitionContext(actor: 'user:reviewer'))->applied)->toBeTrue();
    $write = $ticket->getConnection()->table('participant_writes')->first();
    expect($write?->actor)->toBe('user:reviewer')->and($write?->before_status)->toBe('draft');
});

it('rolls back participant writes and workflow history together on failure', function () {
    $ticket = ParticipatingTicket::create([]);
    ParticipatingTicket::$refuse = true;
    expect(fn () => app(LifecycleService::class)->transition($ticket, 'publish'))->toThrow(RuntimeException::class, 'participant refused');
    expect($ticket->fresh()->status)->toBe('draft')
        ->and($ticket->getConnection()->table('participant_writes')->count())->toBe(0)
        ->and(app(Splicewire\Beam\Workflows\Control\WorkflowHistory::class)->forSubject($ticket))->toHaveCount(0);
});

it('uses the same participant for scheduled execution and durable retries', function () {
    app(\Splicewire\Beam\Workflows\Control\SubjectResolverRegistry::class)->register('participant', fn ($id) => ParticipatingTicket::find($id));
    app()->instance(\Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority::class,
        new class implements \Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority {
            public function authorize(Model $subject, string $transition, \Splicewire\Beam\Workflows\Actions\WorkflowActionContext $context): void {}
        });
    $ticket = ParticipatingTicket::create([]);
    $service = app(\Splicewire\Beam\Workflows\Actions\WorkflowActionService::class);
    $context = new \Splicewire\Beam\Workflows\Actions\WorkflowActionContext('user:reviewer', 'user:author', 'tenant:test');
    $data = $service->prepare(new \Splicewire\Beam\Workflows\Actions\Data\WorkflowActionData('participant', (string) $ticket->id, 'publish'), $context);
    $first = $service->execute('release:scheduled', $data, $context);
    $again = $service->execute('release:scheduled', $data, $context);
    expect($first->applied)->toBeTrue()->and($again->transitionId)->toBe($first->transitionId)
        ->and($ticket->getConnection()->table('participant_writes')->count())->toBe(1);
});
