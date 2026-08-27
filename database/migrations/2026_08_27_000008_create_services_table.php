<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Работы: установка, калибровка, снятие-постановка.
 *
 * До сих пор клиент видел цену стекла и платил на пятнадцать тысяч больше —
 * работа не считалась нигде. Из-за этого же в отчётах не было маржи:
 * выручка складывалась только из стекла.
 *
 * Отдельная таблица, а не колонка «цена установки» у товара: работа одна
 * на весь тип стекла, а карточек этого типа тысячи. Менять цену установки
 * лобового стекла правкой пяти тысяч карточек — не работа, а наказание.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->decimal('price', 12, 2);

            /*
             * К какому стеклу применима. Установка лобового и установка
             * бокового — разные деньги и разное время. NULL означает «к любому»:
             * так задают, например, выезд мастера.
             */
            $table->string('glass_type', 24)->nullable();

            // Сколько занимает — по этому планировщик выбирает окно в смене
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            /*
             * Предлагать ли автоматически. Установку лобового предлагаем всем,
             * а калибровку ADAS — только там, где есть камера, и решает это
             * менеджер, а не витрина.
             */
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('sort')->default(500);
            $table->timestamps();

            $table->index(['glass_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
