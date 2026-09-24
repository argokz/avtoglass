<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->char('owner_scope', 64);
            $table->string('operation', 64);
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_payload')->nullable();
            $table->uuid('result_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['owner_scope', 'operation', 'key_hash'], 'idempotency_scope_unique');
            $table->index('expires_at');
        });
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_response_check CHECK (response_status IS NULL OR response_status BETWEEN 200 AND 299)');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
    }
};
