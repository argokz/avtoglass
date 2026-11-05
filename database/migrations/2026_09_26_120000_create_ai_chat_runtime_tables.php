<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('owner_key', 80)->unique();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('guest_token')->nullable();
            $table->string('locale', 2);
            $table->uuid('active_turn_id')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->uuid('turn_id')->nullable()->index();
            $table->string('role', 10);
            $table->text('text');
            $table->timestamps();
        });
        Schema::create('ai_turns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->uuid('operation_id')->index();
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedBigInteger('user_message_id');
            $table->unsignedBigInteger('model_message_id');
            $table->string('status', 16)->index();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('deadline_at');
            $table->timestamps();
        });
        Schema::create('ai_tool_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->foreignUuid('turn_id')->constrained('ai_turns')->cascadeOnDelete();
            $table->string('name', 32);
            $table->jsonb('arguments');
            $table->jsonb('result');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_results');
        Schema::dropIfExists('ai_turns');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
    }
};
