<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('auto_checkout_enabled')->default(false);
            $table->timestampTz('stock_verified_at')->nullable();
            $table->timestampTz('price_valid_until')->nullable();
        });
        Schema::table('service_points', function (Blueprint $table) {
            $table->string('timezone', 64)->default('Asia/Qyzylorda');
        });
        Schema::create('offer_fulfillment_points', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_point_id')->constrained()->cascadeOnDelete();
            $table->boolean('pickup_enabled')->default(false);
            $table->boolean('installation_enabled')->default(false);
            $table->primary(['offer_id', 'service_point_id']);
        });
        Schema::create('checkout_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('draft_id')->constrained('order_drafts')->cascadeOnDelete();
            $table->bigInteger('draft_version');
            $table->bigInteger('version');
            $table->string('status', 16)->default('prepared');
            $table->text('snapshot');
            $table->char('source_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
            $table->unique(['draft_id', 'version']);
            $table->index(['draft_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE checkout_quotes ADD CONSTRAINT checkout_quotes_state_check CHECK (draft_version > 0 AND version > 0 AND status IN ('prepared', 'accepted', 'superseded', 'expired'))");
        // One prepared quote per draft, even if a future writer omits application locking.
        DB::statement("CREATE UNIQUE INDEX checkout_quotes_one_prepared ON checkout_quotes (draft_id) WHERE status = 'prepared'");
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quotes');
        Schema::dropIfExists('offer_fulfillment_points');
        Schema::table('service_points', fn (Blueprint $table) => $table->dropColumn('timezone'));
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn(['auto_checkout_enabled', 'stock_verified_at', 'price_valid_until']));
    }
};
