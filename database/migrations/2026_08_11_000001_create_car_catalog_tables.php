<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник автомобилей: марка -> модель -> поколение.
 * Кузов и число дверей живут в применимости (fitment), потому что в прайсах
 * поставщиков одно стекло часто закрывает несколько кузовов сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // Toyota
            $table->string('name_ru')->nullable();      // Тойота — для поиска кириллицей
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->unsignedSmallInteger('models_count')->default(0);
            $table->boolean('is_popular')->default(false);
            $table->unsignedSmallInteger('sort')->default(500);
            $table->uuid('external_id')->nullable()->index();  // GUID из 1С
            $table->timestamps();
        });

        Schema::create('car_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_brand_id')->constrained()->cascadeOnDelete();
            $table->string('name');                    // Camry
            $table->string('name_ru')->nullable();     // Камри
            $table->string('slug');
            $table->unsignedSmallInteger('products_count')->default(0);
            $table->boolean('is_popular')->default(false);
            $table->uuid('external_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['car_brand_id', 'slug']);
        });

        Schema::create('car_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_model_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();        // III / XV70
            $table->string('code')->nullable();        // W212, E60, U50, FJ200
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->boolean('is_restyling')->default(false);
            $table->uuid('external_id')->nullable()->index();
            $table->timestamps();

            $table->index(['car_model_id', 'year_from', 'year_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_generations');
        Schema::dropIfExists('car_models');
        Schema::dropIfExists('car_brands');
    }
};
