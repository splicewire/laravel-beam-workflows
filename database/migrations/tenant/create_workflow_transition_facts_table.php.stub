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
        ConvergentTable::named('workflow_transition_facts')->existsUsing(fn (string $table): bool => $this->existsInCurrentSchema($table))
            ->define(function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('subject_type');
                $table->string('subject_id');
                $table->string('transition');
                $table->json('from');
                $table->json('to');
                $table->uuid('version_id')->nullable();
                $table->string('actor')->nullable();
                $table->string('run_id')->nullable();
                $table->string('causation_id')->nullable();
                $table->json('causal_path');
                $table->timestampTz('occurred_at', 6);
                $table->index(['subject_type', 'subject_id'], 'workflow_transition_facts_subject');
                $table->index('occurred_at');
            })->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_transition_facts');
    }

    private function existsInCurrentSchema(string $table): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return Schema::hasTable($table);
        }

        return DB::selectOne('select 1 from information_schema.tables where table_schema = current_schema() and table_name = ?', [$table]) !== null;
    }
};
