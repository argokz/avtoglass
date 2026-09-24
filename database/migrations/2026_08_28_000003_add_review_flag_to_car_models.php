<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Названия моделей приезжают из прайсов поставщиков, и разбор строки иногда
 * оставляет в имени хвост от атрибута: «4RUNNER а», «2110 п.ш», «Land Cruiser
 * Оригин». Такое имя видит покупатель в подборе. Удалять нельзя — за моделью
 * висят подборы и товары, а верное имя знает только человек. Поэтому — очередь
 * проверки, как у товаров.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->index();
            $table->string('review_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            $table->dropColumn(['needs_review', 'review_note']);
        });
    }
};
