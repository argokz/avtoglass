<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Выданные документы.
 *
 * Смысл таблицы — номер. Документ, однажды выданный клиенту, обязан при
 * повторной печати выглядеть так же и иметь тот же номер: покупатель уже
 * сослался на него в переписке, а бухгалтерия внесла в учёт. Поэтому номер
 * присваивается один раз и хранится, а не считается заново при каждой печати.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            $table->string('type', 32)->index();
            $table->string('number', 32)->unique();

            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('issued_on');

            // Снимок данных на момент выдачи: заказ потом правят, а документ
            // должен остаться тем, который отдали клиенту.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
