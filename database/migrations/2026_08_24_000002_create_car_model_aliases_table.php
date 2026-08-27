<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ручные соответствия «как написано в прайсе» -> «как называется на самом деле».
 *
 * Нужны там, где правилами не обойтись: «W140, S-Class» и «LANDCR» кодом
 * не развернуть, это знание о номенклатуре, а не о синтаксисе. Псевдоним
 * переживает пересборку каталога, поэтому правку делают один раз.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_model_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_brand_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('alias');                        // сырое имя из прайса
            $table->string('model_name');                   // каноническое имя модели
            $table->string('generation')->nullable();       // поколение, если оно было в сыром имени
            $table->string('comment')->nullable();

            $table->timestamps();

            // одна и та же строка прайса не может значить две разные машины
            $table->unique(['car_brand_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_model_aliases');
    }
};
