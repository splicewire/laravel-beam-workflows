<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\LifecycleService;
use Splicewire\Beam\Workflows\Control\TransitionContext;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged;

class ReactionArticle extends Illuminate\Database\Eloquent\Model implements Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged
{
    use WorkflowManaged;

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'reaction-article';
    }
}

beforeEach(function () {
    Schema::create('reaction_articles', function (Blueprint $table) {
        $table->id();
        $table->string('status');
        $table->string('workflow_version')->nullable();
    });
    app(Splicewire\Beam\Workflows\Definition\DefinitionStore::class)->ensureSystemLineage('reaction.lifecycle', 'Reactions', WorkflowBlueprint::fromArray([
        'name' => 'reaction.lifecycle', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('reaction-article', 'reaction.lifecycle');
    $this->article = ReactionArticle::create(['status' => 'draft']);
    $this->bindingId = (string) Str::uuid();
    DB::table('workflow_reaction_bindings')->insert([
        'id' => $this->bindingId, 'subject_type' => $this->article->getMorphClass(), 'subject_id' => $this->article->id,
        'transition' => 'publish', 'principal' => 'user:editor', 'creator' => 'user:editor', 'tenant_token' => 'fixture',
        'configuration' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('records required delivery atomically with successful publication and its actual anchor', function () {
    $this->travelTo(now()->parse('2026-09-15T13:30:00Z'));
    DB::beginTransaction();
    $result = app(LifecycleService::class)->transition($this->article, 'publish');
    $delivery = DB::table('workflow_reaction_deliveries')->first();
    expect($delivery)->not->toBeNull()->and($delivery->transition_id)->toBe($result->transitionId);
    expect($delivery->status)->toBe('pending');
    DB::rollBack();
    expect(DB::table('workflow_reaction_deliveries')->count())->toBe(0)->and($this->article->fresh()->status)->toBe('draft');
});

it('records a visible cycle refusal without recursively scheduling another reaction', function () {
    app(LifecycleService::class)->transition($this->article, 'publish', context: new TransitionContext(causalPath: [$this->bindingId]));
    $delivery = DB::table('workflow_reaction_deliveries')->first();
    expect($delivery)->not->toBeNull()->and($delivery->status)->toBe('blocked');
    expect(json_decode($delivery->blockers, true))->toContain('The workflow follow-up would re-enter its causal chain.');
});
