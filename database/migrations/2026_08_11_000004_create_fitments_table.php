<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Применимость: какое стекло подходит к какой машине.
 * Отдельная таблица, потому что связь many-to-many: в прайсах постоянно встречается
 * "Citroen Berlingo // Peugeot Partner" и "Toyota Land Cruiser 300 // Lexus LX 600" —
 * сейчас это склеено в одну строку и не ищется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_model_id')->constrained()->cascadeOnDelete();
            $table->foreignId('car_generation_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();   // null = по настоящее время
            $table->string('body_type', 24)->nullable();           // sedan|hatchback|suv|van|pickup|mpv|wagon|truck|coupe|liftback
            $table->string('doors', 8)->nullable();                // "4", "3/5"
            $table->string('note')->nullable();                    // "правый руль", "для базовой комплектации"

            $table->boolean('is_verified')->default(false);        // подтверждено человеком
            $table->timestamps();

            $table->index(['car_model_id', 'year_from', 'year_to']);
            $table->unique(['product_id', 'car_model_id', 'year_from', 'year_to', 'body_type'], 'fitments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fitments');
    }
};
