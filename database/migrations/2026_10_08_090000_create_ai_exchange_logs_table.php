<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал вопросов и ответов помощника.
 *
 * Нужен, чтобы разбирать, какие карточки ушли в чат и почему среди них
 * оказались чужие машины. Телефоны и почта в текст не попадают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_exchange_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('turn_id')->unique();
            $table->uuid('conversation_id')->index();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->string('status', 24);
            $table->string('error_code', 40)->nullable();
            $table->string('search_query', 190)->nullable();
            $table->json('raw_offers');
            $table->json('shown_offers');
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_exchange_logs');
    }
};
