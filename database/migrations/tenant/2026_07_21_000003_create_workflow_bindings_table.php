<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted type→workflow bindings (beam-workflows v2 tickets 02/09) — TENANT-scoped, because a
 * binding is tenant data (the PRD §Multi-tenancy): a binding ROW existing IS the enable; deleting it
 * IS the disable (the type falls back to unmanaged; live objects keep their pinned version until
 * migrated). Pick-one arity → `type_key` is the primary key (one lifecycle governs one type).
 *
 * The in-memory WorkflowBindingRegistry is hydrated from this table at boot; the admin bind/unbind
 * surface writes here and updates the registry, so a governance change survives the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_bindings', function (Blueprint $table) {
            $table->string('type_key')->primary();
            $table->string('lineage_ref');
            $table->json('params')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_bindings');
    }
};
