<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named('workflow_reaction_bindings')->existsUsing(fn (string $table): bool => $this->existsInCurrentSchema($table))->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('transition');
            $table->string('principal');
            $table->string('creator');
            $table->string('tenant_token');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('capture_sequence')->default(0);
            $table->json('configuration');
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->index(['subject_type', 'subject_id', 'transition'], 'workflow_reaction_subject');
        })->assert();
        ConvergentTable::named('workflow_reaction_deliveries')->existsUsing(fn (string $table): bool => $this->existsInCurrentSchema($table))->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('binding_id');
            $table->uuid('transition_id');
            $table->unsignedBigInteger('capture_sequence')->default(0);
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('request');
            $table->json('source_snapshot');
            $table->json('causal_path');
            $table->json('blockers');
            $table->string('principal');
            $table->string('creator');
            $table->string('tenant_token');
            $table->uuid('action_id')->nullable();
            $table->timestampTz('anchored_at', 6);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('completed_at', 6)->nullable();
            $table->unique(['binding_id', 'transition_id'], 'workflow_reaction_once');
        })->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_reaction_deliveries');
        Schema::dropIfExists('workflow_reaction_bindings');
    }

    private function existsInCurrentSchema(string $table): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return Schema::hasTable($table);
        }

        return DB::selectOne('select 1 from information_schema.tables where table_schema = current_schema() and table_name = ?', [$table]) !== null;
    }
};
