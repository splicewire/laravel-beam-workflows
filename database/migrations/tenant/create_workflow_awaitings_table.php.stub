<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * The awaiting projection (beam-workflows-ux tickets 07/09) — TENANT-scoped, because an awaiting is
 * tenant data: the durable "waiting on you" rows the Review inbox arm reads (ticket 14), stamped by the
 * generic `workflow.await` effect and cleared on leave. It is the pull-surface twin of the per-event
 * `mail` delivery — targeted, per-principal, self-expiring — distinct from the global/permanent
 * `activity_log` audit.
 *
 * Deliberately OPAQUE and identity-free: a `principal` is a `kind:selector` string (`owner:`,
 * `role:reviewer`, `watcher:`), NOT a user id — the "everything awaiting me" reverse index is
 * `principal` + subject, read via per-principal-kind inversion (ticket 14). No user column exists.
 *
 * Rows are immutable — created on enter, deleted on leave. The subject morph mirrors the
 * `activity_log`/`StatusEmitted` audit key type (UUID morphs). The nullable `parent` morph is ticket
 * 09's host-supplied rollup key (null = a flat singleton); folded in from the start rather than shipped
 * as a second migration.
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
        ConvergentTable::named('workflow_awaitings')
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();

                // Who this awaiting is about — mirror the audit subject-morph key type (UUID morphs).
                $table->uuidMorphs('subject');

                // The awaiting place (a `$event->to` element) — same vocabulary as `status`.
                $table->string('place');

                // Opaque `kind:selector` recipient token — never a resolved user.
                $table->string('principal');

                // Ticket 09 rollup key: host-supplied, resolved read-time; null = flat singleton.
                $table->nullableUuidMorphs('parent');

                // = enter time; drives ordering. Rows are created/deleted only, never updated, so there is
                // no `updated_at`.
                $table->timestamp('created_at')->nullable();

                // (1) Unique natural key — DB-level stamp idempotency. Its (subject_type, subject_id, place)
                // prefix IS the clearForPlaces query.
                $table->unique(['subject_type', 'subject_id', 'place', 'principal'], 'workflow_awaitings_natural_key');

                // (2) Secondary — fast role-inversion reads (WHERE principal = 'role:reviewer').
                $table->index('principal', 'workflow_awaitings_principal_index');
            })
            ->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_awaitings');
    }
};
