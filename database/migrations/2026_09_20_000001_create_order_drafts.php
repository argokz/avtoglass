<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('guest_owner_hash', 64)->nullable();
            $table->string('sales_mode', 16);
            $table->bigInteger('version')->default(1);
            $table->string('status', 16)->default('active');
            $table->string('locale', 2)->default('ru');
            $table->text('vehicle');
            $table->text('contacts');
            $table->text('fulfillment');
            $table->timestampsTz();
            $table->index(['customer_id', 'updated_at', 'id']);
            $table->index(['guest_owner_hash', 'updated_at', 'id']);
        });
        DB::statement("ALTER TABLE order_drafts ADD CONSTRAINT order_drafts_owner_check CHECK ((customer_id IS NOT NULL AND guest_owner_hash IS NULL) OR (customer_id IS NULL AND guest_owner_hash IS NOT NULL AND guest_owner_hash ~ '^[a-f0-9]{64}$'))");
        DB::statement("ALTER TABLE order_drafts ADD CONSTRAINT order_drafts_state_check CHECK (version > 0 AND sales_mode IN ('retail', 'wholesale') AND status IN ('active', 'submitted', 'archived') AND locale IN ('ru', 'kk'))");

        Schema::create('order_draft_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('draft_id')->constrained('order_drafts')->cascadeOnDelete();
            $table->uuid('line_id');
            $table->integer('position');
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 128);
            $table->string('article_snapshot', 128)->nullable();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->integer('quantity');
            $table->string('selection_mode', 24)->default('vehicle_fitment');
            $table->boolean('article_confirmed')->default(false);
            $table->boolean('manufacturer_confirmed')->default(false);
            $table->text('comment')->nullable();
            $table->timestampsTz();
            $table->unique(['draft_id', 'line_id']);
            $table->unique(['draft_id', 'offer_id']);
            $table->index(['draft_id', 'position']);
        });
        DB::statement("ALTER TABLE order_draft_items ADD CONSTRAINT order_draft_items_values_check CHECK (quantity BETWEEN 1 AND 99 AND position BETWEEN 0 AND 499 AND length(trim(reference)) > 0 AND selection_mode IN ('vehicle_fitment', 'exact_article'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_draft_items');
        Schema::dropIfExists('order_drafts');
    }
};
