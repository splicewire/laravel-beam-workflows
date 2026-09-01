<?php

namespace Splicewire\Beam\Workflows\Display;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\Models\Activity;

/**
 * WHERE a subject's activity lives — the `beam.workflows.activity_models` map, read from the one
 * place both sides of the timeline can reach it.
 *
 * ## Why this is a class and not a private method
 *
 * The map was already being applied, correctly, per subject and per emit, by
 * {@see StatusEmitter::resolveActivityModel()} — a `protected` method. The WRITE side has therefore
 * always known that a `Tenant`'s status belongs on the central connection and a composition's does
 * not. The READ side had no way to ask the same question, so the `activity` particle resource froze
 * `Splicewire\Beam\Models\CentralActivityLog` into its `backing:` and tower's `ActivityQuery` built
 * from `CentralActivityLog::query()` — which means a tenant's own composition activity, written by
 * this estate's own emitter into this estate's own replicated `activity_log`, was structurally
 * unreachable from the tenant feed that exists to show it.
 *
 * Measured against the server on 2026-08-31 (schema census, no Laravel frame):
 *
 *     activity_log exists in 18 schemas: public + all 17 tenant_*
 *     public            240 rows   subject_type: tenant 239 · beam_ux_entry 1
 *     tenant_system       2 rows   subject_type: composition 2
 *     the other 16        0 rows
 *
 * The two sides hold DISJOINT subject classes, and that partition is the map doing its job. So the
 * repair is not to move any row, and emphatically not to delete the central pin — it is to let the
 * reader consult the same map the writer already consults. Extracting the walk here is what makes
 * "read and write cannot drift" a property of the code rather than a promise: there is exactly one
 * `instanceof`/`is_a` loop in the estate, and {@see StatusEmitter} now delegates to it.
 *
 * ## Two entry points, because the two sides hold different things
 *
 * The writer holds an instantiated subject, so it asks {@see forSubject()} and the map key is
 * matched with `instanceof`. The reader holds a `subject_type` STRING off a filter — a morph alias
 * (`tenant`, `composition`) or, on an unaliased model, an FQCN — so it asks
 * {@see forSubjectType()}, which resolves the alias through the morph map and then matches the same
 * keys with `is_a(…, allow_string: true)`. Same keys, same first-match-wins order, same fallback to
 * the global `beam.workflows.activity_model`.
 *
 * ⚠️ The two matchers are NOT interchangeable and the difference is easy to get backwards.
 * `instanceof` on an instance and `is_a($class, $key, true)` on a class-string agree for concrete
 * classes AND for interfaces — but only the string form needs the third argument, and without it
 * `is_a()` silently answers false for every class-string it is given. That is this file's version of
 * the estate's recurring defect: a check that reports "no match" by not running.
 *
 * ## What this class deliberately does NOT decide
 *
 * It does not know about `CentralActivityLog`, and must not: beam-workflows sits below beam-core's
 * models in nothing but dependency terms, and the whole point of the config seam is that the host
 * names its own central model. A caller that wants "and if the map says nothing, read the central
 * copy" is expressing a HOST policy about an unfiltered read, and owns that fallback itself — see
 * `Splicewire\Tower\Particle\Backing\ActivityBacking`, which does exactly that and says why.
 *
 * @see StatusEmitter::emit()  the write side, which scope-swaps `activitylog.activity_model`
 */
class ActivityResidency
{
    public function __construct(protected Config $config) {}

    /**
     * The WRITE side's question: which Activity model does this subject's status get written to?
     *
     * Null means "no mapping applies" — spatie's own configured default, i.e. no scope swap. This is
     * the exact behaviour {@see StatusEmitter::resolveActivityModel()} had before it delegated here,
     * including the fallback to the global `beam.workflows.activity_model` for a null subject.
     *
     * @return class-string|null
     */
    public function forSubject(?Model $subject): ?string
    {
        if ($subject !== null) {
            foreach ($this->map() as $subjectClass => $activityModel) {
                if ($subject instanceof $subjectClass) {
                    return $activityModel;
                }
            }
        }

        return $this->globalDefault();
    }

    /**
     * The READ side's mirror: which Activity model holds the rows for this `subject_type`?
     *
     * `$subjectType` is what a polymorphic `subject_type` column actually stores — a morph alias
     * where one is registered (`tenant`, `composition`), the FQCN where none is. Both are accepted;
     * the alias is resolved through {@see Relation::getMorphedModel()} first.
     *
     * Null means the same thing it means on the write side: no mapping applies. A caller that needs
     * a concrete class for that case supplies its own fallback.
     *
     * @return class-string|null
     */
    public function forSubjectType(?string $subjectType): ?string
    {
        $class = $this->subjectClass($subjectType);

        if ($class === null) {
            return $this->globalDefault();
        }

        foreach ($this->map() as $subjectClass => $activityModel) {
            // `allow_string: true` is load-bearing — without it `is_a()` is false for every
            // class-string, so the whole map would silently never match. See the class docblock.
            if (is_a($class, $subjectClass, true)) {
                return $activityModel;
            }
        }

        return $this->globalDefault();
    }

    /**
     * The model an unmapped subject's rows actually live in: spatie's configured activity model,
     * which under this estate's tenancy layer resolves on the tenant-swapped connection.
     *
     * This is the concrete answer for the branch where {@see forSubjectType()} returns null and the
     * caller knows the subject is real (i.e. a `subject_type` WAS supplied), and it is read from
     * `activitylog.activity_model` rather than hardcoded so a host that points that key at its own
     * subclass is followed here too.
     *
     * @return class-string
     */
    public function tenantDefault(): string
    {
        $model = $this->config->get('activitylog.activity_model');

        return is_string($model) && $model !== '' ? $model : Activity::class;
    }

    /**
     * Resolve a `subject_type` column value to a model class-string, or null when it names nothing
     * this application knows. A morph alias resolves through the morph map; an FQCN is taken as-is
     * when the class exists.
     *
     * Returning null for an unrecognised value is deliberate and is the safe direction: an unknown
     * subject type gets the caller's fallback rather than an arbitrary map hit.
     *
     * @return class-string|null
     */
    public function subjectClass(?string $subjectType): ?string
    {
        if ($subjectType === null || $subjectType === '') {
            return null;
        }

        $mapped = Relation::getMorphedModel($subjectType);

        if (is_string($mapped) && class_exists($mapped)) {
            return $mapped;
        }

        return class_exists($subjectType) ? $subjectType : null;
    }

    /**
     * The `subject-class => activity-model-class` map, first match wins in declared order.
     *
     * @return array<class-string, class-string>
     */
    protected function map(): array
    {
        $map = $this->config->get('beam.workflows.activity_models', []);

        return is_array($map) ? $map : [];
    }

    /** The global override applied when no per-subject key matches. Null = no swap. */
    protected function globalDefault(): ?string
    {
        $model = $this->config->get('beam.workflows.activity_model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
