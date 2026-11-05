<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_scope', 64);
            $table->uuid('turn_id');
            $table->string('request_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->text('snapshot')->nullable();
            $table->text('result')->nullable();
            $table->timestampTz('privacy_consented_at')->nullable();
            $table->timestampsTz();
            $table->unique(['owner_scope', 'turn_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_checkouts');
    }
};
