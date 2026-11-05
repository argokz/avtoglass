<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropUnique(['owner_key']);
            $table->index(['owner_key', 'updated_at']);
            $table->string('title', 80)->nullable();
        });
    }

    public function down(): void
    {
        // Never discard histories to restore the old one-conversation constraint.
        if (DB::table('ai_conversations')->select('owner_key')->groupBy('owner_key')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Multiple chat histories exist; rollback would lose data.');
        }
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropIndex(['owner_key', 'updated_at']);
            $table->dropColumn('title');
            $table->unique('owner_key');
        });
    }
};
