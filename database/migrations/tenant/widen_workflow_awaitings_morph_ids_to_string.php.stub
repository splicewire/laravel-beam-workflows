<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * ADDITIVE ALTER — widens `workflow_awaitings.subject_id` and `.parent_id` from `uuid` to string on hosts
 * that ran `create_workflow_awaitings_table.php.stub` before it declared string morph ids. TENANT-scoped,
 * like the table.
 *
 * ## Why an ALTER, when the create stub is already correct
 *
 * The create stub's docblock carries the argument for the type (a governed subject may be keyed by integer,
 * UUID or ULID; a `uuid` column refuses the integer on Postgres and aborts the transition's transaction).
 * That edit **reaches only a fresh database**: a host that already ran the create keeps the shape it had
 * on the day it ran, and no republish changes that. The convergent guard cannot reach it either —
 * {@see ConvergentTable} handles absence, never conflict: a column present with the wrong type is its
 * tier-three conflict, reported and never converted. So this is a separately stamped file, not an edit to
 * the create — the same split as `splicewire/laravel-beam`'s `widen_beam_hook_morph_ids_to_string`.
 *
 * Listed right AFTER `tenant/create_workflow_awaitings_table` in the service provider's `hasMigrations()`:
 * spatie/laravel-package-tools stamps entries a second apart in declared order, so on a fresh publish the
 * create precedes its ALTER, and a host that already has the create gets only this file, stamped now. On
 * a host without the table it is a no-op (the create owns that case); on a table the current create made
 * it is a no-op too.
 *
 * ## Why it reads the column before writing
 *
 * `->change()` is a full column redefinition, not a type delta: it restates type and nullability from this
 * file. Guarding on the column's actual type means it touches only the hosts that genuinely diverge and is
 * a true no-op everywhere else — on sqlite, where `uuid` already compiles to `varchar`, it never runs, and
 * there `->change()` would be a table rebuild.
 *
 * ## The cast, and the indexes
 *
 * Postgres converts `uuid` to `varchar` without a `USING` clause (an assignment cast to a string type), so
 * the plain `->change()` is enough. Every value a uuid column held stays valid as its text. The morph
 * indexes and the natural-key unique are kept, not dropped and recreated: Postgres rebuilds a dependent
 * index as part of the type change.
 */
return new class extends Migration
{
    /** Morph id => nullable, as the create stub declares them. */
    private const COLUMNS = ['subject_id' => false, 'parent_id' => true];

    /**
     * `type_name` values (as `Schema::getColumns()` reports them) that are already a variable-length string
     * — the database's vocabulary, not Laravel's. Anything else (`uuid`, a fixed-width `char(36)`/`bpchar`)
     * is widened to the create stub's `string`.
     */
    private const ALREADY_STRING = ['varchar', 'character varying', 'nvarchar', 'text', 'clob'];

    public function up(): void
    {
        if (! Schema::hasTable('workflow_awaitings')) {
            return;
        }

        foreach (self::COLUMNS as $column => $nullable) {
            if ($this->alreadyString($column)) {
                continue;
            }

            Schema::table('workflow_awaitings', function (Blueprint $table) use ($column, $nullable) {
                $table->string($column)->nullable($nullable)->change();
            });
        }
    }

    /**
     * Deliberately IRREVERSIBLE: rows written after the widening may hold integer or ULID subjects that no
     * `uuid` column can represent, so narrowing back would fail on a used table. The `up()` repairs a
     * defect; it is not a reversible design choice.
     */
    public function down(): void
    {
        // no-op — see the docblock above.
    }

    private function alreadyString(string $column): bool
    {
        foreach (Schema::getColumns('workflow_awaitings') as $found) {
            if (($found['name'] ?? null) === $column) {
                return in_array(strtolower((string) ($found['type_name'] ?? '')), self::ALREADY_STRING, true);
            }
        }

        // Column absent entirely — not a shape this ALTER knows; the convergent create owns it.
        return true;
    }
};
