<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Contracts\ProvidesGuardContext;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class PrincipalGuardArticle extends Model implements ProvidesGuardContext, WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'principal_guard_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'principal-article';
    }

    public function workflowGuardContext(): array
    {
        return ['private_runtime_fact' => 'not projected'];
    }
}

beforeEach(function () {
    Relation::morphMap(['principal_guard_article' => PrincipalGuardArticle::class]);
    Schema::create('principal_guard_articles', function (Blueprint $table) {
        $table->increments('id');
        $table->string('status')->default('draft');
        $table->uuid('workflow_version')->nullable();
    });
    app(DefinitionStore::class)->ensureSystemLineage('principal.lifecycle', 'Principal Lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'principal.lifecycle', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'test-principal',
            'metadata' => ['guardParams' => ['policy' => 'fixture-policy']]]],
    ]));
    app(WorkflowBindingRegistry::class)->bind('principal-article', 'principal.lifecycle');
    // This TEST guard owns token resolution. It reads no agreement or host identity model.
    app(GuardRegistry::class)->register('test-principal', function (object $subject, ?TransitionContext $context = null, array $params = []): bool|string {
        $principal = ['test:allowed' => 'allowed', 'test:denied' => 'denied'][$context?->actor ?? ''] ?? null;
        if ($principal === null) {
            return 'A resolvable principal is required.';
        }

        return $principal === 'allowed' && ($params['policy'] ?? null) === 'fixture-policy'
            ? true : 'The test principal is not admitted.';
    });
});

it('uses the same host principal and declared params for reads and attempts', function () {
    $article = PrincipalGuardArticle::create();
    $actuator = app(WorkflowActuator::class);
    $context = new TransitionContext(actor: 'test:allowed', runId: 'fixture-run');
    expect($actuator->available($article, $context))->toBe(['publish'])
        ->and($actuator->projection($article, $context)['available'])->toBe(['publish'])
        ->and($actuator->projection($article, $context)['transitions'][0]['reason'])->toBeNull()
        ->and($actuator->transition($article, 'publish', $context)->applied)->toBeTrue()
        ->and($article->fresh()->status)->toBe('published');
});

it('fails closed for absent unresolved and denied principals without persisting a move', function (?string $actor, string $reason) {
    $article = PrincipalGuardArticle::create();
    $context = new TransitionContext(actor: $actor);
    $actuator = app(WorkflowActuator::class);
    expect($actuator->available($article, $context))->toBe([])
        ->and($actuator->projection($article, $context)['transitions'][0]['reason'])->toBe($reason);
    $attempt = $actuator->transition($article, 'publish', $context);
    expect($attempt->applied)->toBeFalse()
        ->and($attempt->blockers)->toContain($reason)
        ->and($article->fresh()->status)->toBe('draft');
})->with([
    'actorless worker' => [null, 'A resolvable principal is required.'],
    'unresolvable token' => ['test:missing', 'A resolvable principal is required.'],
    'resolved denied principal' => ['test:denied', 'The test principal is not admitted.'],
]);

it('projects only declared guard params in the existing metadata slot', function () {
    $article = PrincipalGuardArticle::create();
    $projection = app(WorkflowActuator::class)->projection($article, new TransitionContext(actor: 'test:allowed'));
    expect($projection['transitions'][0]['metadata'])->toBe(['guardParams' => ['policy' => 'fixture-policy']])
        ->and(json_encode($projection))->not->toContain('private_runtime_fact', 'test:allowed', 'fixture-run');
});

it('keeps legacy one-argument actorless guards working', function () {
    app(GuardRegistry::class)->register('test-principal', fn (object $subject): bool => isset($subject->context['private_runtime_fact']));
    $article = PrincipalGuardArticle::create();
    $actuator = app(WorkflowActuator::class);
    expect($actuator->available($article))->toBe(['publish'])
        ->and($actuator->projection($article)['transitions'][0]['reason'])->toBeNull()
        ->and($actuator->transition($article, 'publish')->applied)->toBeTrue();
});

it('does not let a guard rewrite the caller or persisted transition provenance', function () {
    app(GuardRegistry::class)->register('test-principal', function (object $subject, ?TransitionContext $context = null): bool {
        if ($context) {
            $context->actor = 'test:tampered';
        }

        return true;
    });
    $article = PrincipalGuardArticle::create();
    $context = new TransitionContext(actor: 'test:allowed', runId: 'fixture-run');
    $actuator = app(WorkflowActuator::class);
    $actuator->projection($article, $context);
    expect($actuator->transition($article, 'publish', $context)->applied)->toBeTrue()
        ->and($context->actor)->toBe('test:allowed')
        ->and($article->getConnection()->table('workflow_transition_facts')->value('actor'))->toBe('test:allowed');
});

it('keeps native one-argument callable guards working', function () {
    app(GuardRegistry::class)->register('test-principal', 'is_object');
    $article = PrincipalGuardArticle::create();
    $actuator = app(WorkflowActuator::class);
    expect($actuator->available($article))->toBe(['publish'])
        ->and($actuator->transition($article, 'publish')->applied)->toBeTrue();
});

it('does not carry one callers principal into the next read or attempt', function () {
    $article = PrincipalGuardArticle::create();
    $actuator = app(WorkflowActuator::class);
    expect($actuator->available($article, new TransitionContext(actor: 'test:allowed')))->toBe(['publish'])
        ->and($actuator->available($article))->toBe([])
        ->and($actuator->projection($article)['transitions'][0]['reason'])->toBe('A resolvable principal is required.')
        ->and($actuator->transition($article, 'publish')->applied)->toBeFalse();
});
