<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_workspaces', function (Blueprint $table) {
            $table->string('owner_scope', 64)->primary();
            $table->uuid('draft_id')->nullable();
        });
        Schema::create('assistant_sessions', function (Blueprint $table) {
            $table->foreignUuid('conversation_id')->primary()->constrained('ai_conversations')->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('state');
            $table->uuid('draft_id')->nullable()->index();
            $table->timestamps();
        });
        Schema::create('assistant_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('consented_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->text('facts');
            $table->timestamps();
        });
        Schema::create('assistant_answers', function (Blueprint $table) {
            $table->id();
            $table->string('topic', 40);
            $table->string('locale', 2);
            $table->string('title');
            $table->text('answer');
            $table->boolean('is_published')->default(false);
            $table->string('source')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamps();
            $table->unique(['topic', 'locale']);
        });
        Schema::create('order_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('draft_id')->constrained('order_drafts');
            $table->uuid('quote_id');
            $table->unsignedInteger('version');
            $table->text('snapshot');
            $table->string('status', 20)->default('prepared');
            $table->timestampTz('expires_at');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['order_id', 'version']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('purchase_draft_id')->nullable()->unique();
            $table->text('purchase_snapshot')->nullable();
            $table->string('purchase_state', 30)->nullable()->index();
            $table->string('purchase_guest_owner_hash', 64)->nullable();
        });
        Schema::table('order_drafts', fn (Blueprint $table) => $table->string('payment_method', 20)->nullable());
        Schema::table('offers', function (Blueprint $table) {
            $table->string('verification_source')->nullable();
        });
        Schema::table('fitments', function (Blueprint $table) {
            $table->string('verification_source')->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fitments', fn (Blueprint $table) => $table->dropColumn(['verification_source', 'verified_at']));
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn('verification_source'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['purchase_draft_id', 'purchase_snapshot', 'purchase_state', 'purchase_guest_owner_hash']));
        Schema::table('order_drafts', fn (Blueprint $table) => $table->dropColumn('payment_method'));
        Schema::dropIfExists('order_proposals');
        Schema::dropIfExists('assistant_answers');
        Schema::dropIfExists('assistant_memories');
        Schema::dropIfExists('assistant_sessions');
        Schema::dropIfExists('purchase_workspaces');
    }
};
