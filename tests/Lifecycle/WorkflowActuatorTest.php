<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint as TableBlueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\Contracts\ProvidesGuardContext;
use Splicewire\Beam\Workflows\Control\GuardRegistry;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowActuator;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

/*
 * The generic actuation surface — a host registers a resolver per subject `kind`, and the actuator
 * drives ANY managed record's lifecycle (resolve → projection → transition), pulling the record's
 * own guard context. Proven on a non-composition `Article` with a stale-review gate.
 */

class ActuatorArticle extends Model implements ProvidesGuardContext, WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'actuator_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'article';
    }

    public function workflowGuardContext(): array
    {
        return ['reviewed' => (bool) $this->reviewed];
    }
}

beforeEach(function () {
    if (! Schema::hasTable('actuator_articles')) {
        Schema::create('actuator_articles', function (TableBlueprint $table) {
            $table->increments('id');
            $table->string('status')->default('draft');
            $table->boolean('reviewed')->default(false);
            $table->uuid('workflow_version')->nullable();
        });
    }

    app(DefinitionStore::class)->ensureSystemLineage('article.lifecycle', 'Article Lifecycle', WorkflowBlueprint::fromArray([
        'name' => 'article.lifecycle',
        'places' => ['draft', 'published'],
        'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published', 'guard' => 'reviewed']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('article', 'article.lifecycle');
    app(GuardRegistry::class)->register(
        'reviewed',
        fn (object $s): bool|string => ! empty($s->context['reviewed']) ? true : 'The article must be reviewed before publishing.',
    );

    // The host wires the subject kind in ONE line — no per-model endpoint.
    app(SubjectResolverRegistry::class)->register('article', fn (string $id) => ActuatorArticle::find($id));
});

it('resolves a subject by kind + id', function () {
    $article = ActuatorArticle::create(['status' => 'draft']);
    $actuator = app(WorkflowActuator::class);

    expect($actuator->subject('article', (string) $article->id)?->is($article))->toBeTrue()
        ->and($actuator->subject('article', '999'))->toBeNull()
        ->and($actuator->subject('unregistered-kind', '1'))->toBeNull();
});

it('projects and transitions a resolved record, pulling its own guard context', function () {
    $article = ActuatorArticle::create(['status' => 'draft', 'reviewed' => false]);
    $actuator = app(WorkflowActuator::class);

    // Not reviewed → the guard (fed from the record's own context) blocks publish.
    $blocked = $actuator->transition($article, 'publish');
    expect($blocked->applied)->toBeFalse()
        ->and($blocked->blockers[0])->toContain('reviewed')
        ->and($article->fresh()->status)->toBe('draft');

    // Reviewed → the same call now succeeds; the projection reports the article's type + marking.
    $article->update(['reviewed' => true]);
    expect($actuator->projection($article)['type'])->toBe('article')
        ->and($actuator->available($article))->toBe(['publish']);

    $ok = $actuator->transition($article, 'publish');
    expect($ok->applied)->toBeTrue()
        ->and($article->fresh()->status)->toBe('published');
});
