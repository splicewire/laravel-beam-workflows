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
        ConvergentTable::named('workflow_action_receipts')->existsUsing(fn (string $table): bool => $this->existsInCurrentSchema($table))->define(function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('identity');
            $table->string('tenant_token');
            $table->string('request_hash', 64);
            $table->string('principal');
            $table->string('creator');
            $table->json('request');
            $table->json('result')->nullable();
            $table->timestampTz('created_at', 6);
            $table->unique(['tenant_token', 'identity'], 'workflow_action_identity');
        })->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_action_receipts');
    }

    private function existsInCurrentSchema(string $table): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return Schema::hasTable($table);
        }

        return DB::selectOne('select 1 from information_schema.tables where table_schema = current_schema() and table_name = ?', [$table]) !== null;
    }
};
