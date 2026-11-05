<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сохранённые списки оптовика: «еженедельная закупка», «Тойота под заказ».
 *
 * Храним предложения и количества, а не цены: цена на момент заявки
 * всегда сегодняшняя, список — только «что и сколько».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->jsonb('items');
            $table->timestamps();
            $table->unique(['customer_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_lists');
    }
};
