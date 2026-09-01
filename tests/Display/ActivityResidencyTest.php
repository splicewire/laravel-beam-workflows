<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;
use Splicewire\Beam\Workflows\Display\ActivityResidency;
use Splicewire\Beam\Workflows\Tests\Fixtures\AltActivity;
use Splicewire\Beam\Workflows\Tests\Fixtures\FakeProcess;

/*
 * The READ side of Seam A: `beam.workflows.activity_models` decides which activity log a subject's
 * status was written to, and until particle-manifest-repatriation ticket 09 only the WRITER could
 * ask. `ActivityResidency` is the shared walk; `StatusEmitter` delegates to it, so the two sides
 * cannot answer the same question differently.
 *
 * ⚠️ Every expectation below is deliberately NOT the runtime default. The default answer for an
 * unmapped subject is spatie's `Activity`, so a case that asserts `Activity` proves nothing on its
 * own — it would pass identically against a `forSubjectType()` that ignored the map entirely, which
 * is exactly the defect ticket 08's review caught in its own suite. The mapped cases therefore all
 * expect `AltActivity`, a value the code can only produce by actually reading the map, and each is
 * paired with an unmapped control so a stuck-on-AltActivity implementation fails too.
 */

beforeEach(function () {
    Relation::morphMap(['fake_process' => FakeProcess::class]);
});

it('resolves a mapped subject TYPE to the same model the writer would have used', function () {
    config(['beam.workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    $residency = app(ActivityResidency::class);

    // The reader holds a morph alias off a `subject_type` column...
    expect($residency->forSubjectType('fake_process'))->toBe(AltActivity::class)
        // ...and the writer holds the instance. Same map, same answer — this pair is the whole point.
        ->and($residency->forSubject(new FakeProcess))->toBe(AltActivity::class);
});

it('accepts an FQCN subject_type as well as a morph alias', function () {
    config(['beam.workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    // An unaliased model stores its FQCN in `subject_type`, so both spellings must resolve.
    expect(app(ActivityResidency::class)->forSubjectType(FakeProcess::class))->toBe(AltActivity::class);
});

it('matches a map key by inheritance, not by identity', function () {
    // ⚠️ The read side matches a CLASS-STRING against the map, and `is_a()` needs its third argument
    // to do that at all — without `allow_string: true` it answers false for every class-string and
    // the whole map silently never matches. A subclass key is the case that catches it, because
    // string identity would still pass the test above.
    $subclass = new class extends FakeProcess {};

    config(['beam.workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    expect(app(ActivityResidency::class)->forSubjectType($subclass::class))->toBe(AltActivity::class);
});

it('reports NO mapping for an unmapped subject type, so the caller supplies the branch', function () {
    config(['beam.workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    // `composition` is the real unmapped case at the flagship — its status rows are written to the
    // TENANT copy of `activity_log`, which is precisely what the frozen central backing could not see.
    expect(app(ActivityResidency::class)->forSubjectType('composition'))->toBeNull();
});

it('reports NO mapping for an unrecognised subject type rather than guessing', function () {
    config(['beam.workflows.activity_models' => [FakeProcess::class => AltActivity::class]]);

    expect(app(ActivityResidency::class)->forSubjectType('not_a_registered_morph_alias'))->toBeNull()
        ->and(app(ActivityResidency::class)->forSubjectType(null))->toBeNull()
        ->and(app(ActivityResidency::class)->forSubjectType(''))->toBeNull();
});

it('honours the global default for an unmapped subject type', function () {
    config([
        'beam.workflows.activity_models' => ['App\\Nonexistent\\Other' => Activity::class],
        'beam.workflows.activity_model' => AltActivity::class,
    ]);

    expect(app(ActivityResidency::class)->forSubjectType('fake_process'))->toBe(AltActivity::class);
});

it('reads the tenant default off activitylog config rather than hardcoding spatie', function () {
    // A host that points `activitylog.activity_model` at its own subclass must be followed here too,
    // so this expects AltActivity — again, NOT the value a hardcoded implementation would return.
    config(['activitylog.activity_model' => AltActivity::class]);

    expect(app(ActivityResidency::class)->tenantDefault())->toBe(AltActivity::class);
});

it('falls back to spatie Activity when activitylog names no model', function () {
    config(['activitylog.activity_model' => null]);

    expect(app(ActivityResidency::class)->tenantDefault())->toBe(Activity::class);
});
