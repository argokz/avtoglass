<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 64);
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 64);
            $table->uuid('event_id')->unique();
            $table->text('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['status', 'available_at', 'id']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_check CHECK (status IN ('pending', 'processing', 'done', 'failed') AND attempts >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
