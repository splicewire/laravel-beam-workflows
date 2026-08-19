<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * The versioned definition store (beam-workflows v2 ticket 03) — TENANT-scoped, because definitions
 * and their versions are tenant data (a tenant adopts or forks the seeded system default).
 *
 * A `workflow_definition_lineage` is a stable identity owning ordered, IMMUTABLE
 * `workflow_definition_versions` — each a frozen WorkflowBlueprint snapshot with an `is_active`
 * pointer. A binding (ticket 02) points at a lineage by `key`; resolution picks the active version
 * for NEW objects while existing objects stay pinned to the version they started on. The
 * immutability invariant is enforced in the model (a version row is never rewritten — editing forks
 * a new one), so this schema only has to store history, never protect it.
 *
 * These mirror the shape the package's DefinitionStore writes to (Splicewire\Beam\Workflows\
 * Definition\*); the stack-specific DDL lives here in the child, per the runbook seam.
 *
 * Shipped as a publish-only spatie/laravel-package-tools stub (`runsMigrations` FALSE): the package
 * publishes this timestamp-less `.php.stub` via `configurePackage()`'s `->hasMigrations([...])`
 * (`vendor:publish --tag=beam-workflows-migrations`), which re-stamps + sequences it into the host at
 * install time. beam-workflows never `loadMigrationsFrom`'s it at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named('workflow_definition_lineages')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('key')->unique();          // e.g. composition.lifecycle
                $table->string('name');
                $table->boolean('is_system')->default(false); // a seeded floor a tenant may fork
                $table->timestamps();
            })
            ->assert();

        ConvergentTable::named('workflow_definition_versions')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('lineage_id');
                $table->unsignedInteger('version');       // ordered: 1, 2, 3…
                $table->json('blueprint');                // the frozen, immutable snapshot
                $table->boolean('is_active')->default(false); // the pointer NEW objects start on
                $table->timestamps();

                $table->unique(['lineage_id', 'version']);
                $table->index(['lineage_id', 'is_active']);
                $table->foreign('lineage_id')->references('id')->on('workflow_definition_lineages')->cascadeOnDelete();
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_definition_versions');
        Schema::dropIfExists('workflow_definition_lineages');
    }
};
