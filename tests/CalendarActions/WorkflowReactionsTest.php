<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Calendars\Actions\ActionContext;
use Splicewire\Beam\Calendars\Actions\ActionScheduler;
use Splicewire\Beam\Calendars\Actions\ActionService;
use Splicewire\Beam\Workflows\Actions\Contracts\WorkflowActionAuthority;
use Splicewire\Beam\Workflows\Actions\WorkflowActionContext;
use Splicewire\Beam\Workflows\Binding\WorkflowBindingRegistry;
use Splicewire\Beam\Workflows\Blueprint\WorkflowBlueprint;
use Splicewire\Beam\Workflows\Control\SubjectResolverRegistry;
use Splicewire\Beam\Workflows\Control\WorkflowHistory;
use Splicewire\Beam\Workflows\Definition\DefinitionStore;
use Splicewire\Beam\Workflows\Type\Concerns\WorkflowManaged as WorkflowManagedTrait;
use Splicewire\Beam\Workflows\Type\Contracts\WorkflowManaged;

class ReactionCalendarArticle extends Model implements WorkflowManaged
{
    use WorkflowManagedTrait;

    protected $table = 'reaction_calendar_articles';

    protected $guarded = [];

    public $timestamps = false;

    public function workflowType(): string
    {
        return 'reaction-calendar-article';
    }
}

beforeEach(function () {
    Schema::create('reaction_calendar_articles', function (Blueprint $table) {
        $table->id();
        $table->string('status')->default('draft');
        $table->uuid('workflow_version')->nullable();
    });
    app(DefinitionStore::class)->ensureSystemLineage('reaction-calendar-article', 'Calendar Article', WorkflowBlueprint::fromArray([
        'name' => 'reaction-calendar-article', 'places' => ['draft', 'published'], 'initial' => ['draft'],
        'transitions' => [['name' => 'publish', 'from' => 'draft', 'to' => 'published'], ['name' => 'unpublish', 'from' => 'published', 'to' => 'draft']],
    ]));
    app(WorkflowBindingRegistry::class)->bind('reaction-calendar-article', 'reaction-calendar-article');
    app(SubjectResolverRegistry::class)->register('article', fn ($id) => ReactionCalendarArticle::find($id));
    app()->bind(WorkflowActionAuthority::class, fn () => new class implements WorkflowActionAuthority
    {
        public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void
        {
            if ($context->principal !== 'user:editor' || $context->tenantToken !== 'tenant:test') {
                throw new Illuminate\Auth\Access\AuthorizationException('Principal or tenant unavailable.');
            }
        }
    });
});

function relativeReaction(ReactionCalendarArticle $article, int $days = 30): string
{
    return app(Splicewire\Beam\Workflows\Reactions\WorkflowReactionService::class)->configure(
        new Splicewire\Beam\Workflows\Reactions\Data\WorkflowReactionData('article', (string) $article->id, 'publish',
            'kind.workflow-transition', ['subject_kind' => 'article', 'subject_id' => (string) $article->id, 'transition' => 'unpublish'],
            $days, 'America/New_York'),
        new WorkflowActionContext('user:editor', 'user:creator', 'tenant:test'));
}

it('anchors a delayed delivery to committed publication and deduplicates schedule creation', function () {
    $article = ReactionCalendarArticle::create(['status' => 'draft']);
    relativeReaction($article);
    $this->travelTo(CarbonImmutable::parse('2026-10-15T13:30:00Z'));
    $result = app(Splicewire\Beam\Workflows\Control\LifecycleService::class)->transition($article, 'publish');
    $delivery = Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->first();
    $this->travelTo(CarbonImmutable::parse('2026-10-20T18:00:00Z'));
    $dispatcher = app(Splicewire\Beam\Workflows\Reactions\WorkflowReactionDispatcher::class);
    $id = $dispatcher->deliver($delivery->id, 'tenant:test');
    $action = Splicewire\Beam\Calendars\Models\CalendarAction::findOrFail($id);
    expect($action->due_at->utc()->toIso8601String())->toBe('2026-11-14T14:30:00+00:00');
    expect($action->correlation_id)->toBe($result->transitionId)
        ->and($dispatcher->deliver($delivery->id, 'tenant:test'))->toBe($id)
        ->and(Splicewire\Beam\Calendars\Models\CalendarAction::count())->toBe(1);
    $attempt = app(ActionScheduler::class)->run($id, 'tenant:test', CarbonImmutable::parse('2026-11-14T14:30:00Z'));
    expect($attempt->status)->toBe('applied')->and($article->fresh()->status)->toBe('draft');
    expect(app(WorkflowHistory::class)->forSubject($article)->last()->causation_id)->toBe($result->transitionId);
});

it('replaces an untouched older expiry while preserving a manual override and terminal history', function () {
    $article = ReactionCalendarArticle::create(['status' => 'draft']);
    relativeReaction($article);
    $lifecycle = app(Splicewire\Beam\Workflows\Control\LifecycleService::class);
    $dispatcher = app(Splicewire\Beam\Workflows\Reactions\WorkflowReactionDispatcher::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-01T13:00:00Z'));
    $lifecycle->transition($article, 'publish');
    $first = $dispatcher->sweep('tenant:test')[0];
    $lifecycle->transition($article, 'unpublish');
    $this->travelTo(CarbonImmutable::parse('2026-09-02T13:00:00Z'));
    $lifecycle->transition($article, 'publish');
    $second = $dispatcher->sweep('tenant:test')[0];
    expect(Splicewire\Beam\Calendars\Models\CalendarAction::find($first)->status)->toBe('cancelled');
    $manual = Splicewire\Beam\Calendars\Models\CalendarAction::find($second);
    $data = $manual->toActionData();
    $data->dueAt = '2026-10-20T13:00:00Z';
    app(ActionService::class)->edit($manual->id, 1, $data, new ActionContext('user:editor', 'user:creator', 'tenant:test'));
    $lifecycle->transition($article, 'unpublish');
    $this->travelTo(CarbonImmutable::parse('2026-09-03T13:00:00Z'));
    $lifecycle->transition($article, 'publish');
    $dispatcher->sweep('tenant:test');
    expect($manual->fresh()->status)->toBe('pending')->and($manual->fresh()->revision)->toBe(2);
});

it('does not create expiry from rollback or refusal and visibly holds an unavailable destination', function () {
    $article = ReactionCalendarArticle::create(['status' => 'draft']);
    relativeReaction($article);
    $lifecycle = app(Splicewire\Beam\Workflows\Control\LifecycleService::class);
    $lifecycle->transition($article, 'unpublish');
    expect(Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->count())->toBe(0);
    Illuminate\Support\Facades\DB::beginTransaction();
    $lifecycle->transition($article, 'publish');
    Illuminate\Support\Facades\DB::rollBack();
    expect(Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->count())->toBe(0);
    $lifecycle->transition($article->fresh(), 'publish');
    app()->bind(WorkflowActionAuthority::class, fn () => new class implements WorkflowActionAuthority
    {
        public function authorize(Model $subject, string $transition, WorkflowActionContext $context): void
        {
            throw new Illuminate\Auth\Access\AuthorizationException('Revoked.');
        }
    });
    app(Splicewire\Beam\Workflows\Reactions\WorkflowReactionDispatcher::class)->sweep('tenant:test');
    expect(Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->first()->status)->toBe('blocked');
    expect($article->fresh()->status)->toBe('published');
});

it('orders equal-time publication cycles by capture sequence even when deliveries arrive backwards', function () {
    $article = ReactionCalendarArticle::create(['status' => 'draft']);
    relativeReaction($article);
    $this->travelTo(CarbonImmutable::parse('2026-09-01T13:00:00Z'));
    $lifecycle = app(Splicewire\Beam\Workflows\Control\LifecycleService::class);
    $lifecycle->transition($article, 'publish');
    $lifecycle->transition($article, 'unpublish');
    $lifecycle->transition($article, 'publish');
    $deliveries = Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->orderBy('capture_sequence')->get();
    expect($deliveries->pluck('capture_sequence')->all())->toBe([1, 2]);
    $dispatcher = app(Splicewire\Beam\Workflows\Reactions\WorkflowReactionDispatcher::class);
    $dispatcher->deliver($deliveries[1]->id, 'tenant:test');
    $dispatcher->deliver($deliveries[0]->id, 'tenant:test');
    expect(Splicewire\Beam\Calendars\Models\CalendarAction::where('status', 'pending')->count())->toBe(1);
    expect(Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->where('id', $deliveries[0]->id)->value('status'))->toBe('superseded');
});

it('retries the whole delivery transaction after a transient database concurrency failure', function () {
    $article = ReactionCalendarArticle::create(['status' => 'draft']);
    relativeReaction($article);
    app(Splicewire\Beam\Workflows\Control\LifecycleService::class)->transition($article, 'publish');
    $attempts = 0;
    Splicewire\Beam\Calendars\Models\CalendarAction::creating(function () use (&$attempts): void {
        if (++$attempts === 1) {
            throw new PDOException('Serialization failure', 40001);
        }
    });
    $ids = app(Splicewire\Beam\Workflows\Reactions\WorkflowReactionDispatcher::class)->sweep('tenant:test');
    expect($attempts)->toBe(2)->and($ids)->toHaveCount(1);
    expect(Splicewire\Beam\Calendars\Models\CalendarAction::count())->toBe(1);
    expect(Illuminate\Support\Facades\DB::table('workflow_reaction_deliveries')->first()->status)->toBe('scheduled');
});
