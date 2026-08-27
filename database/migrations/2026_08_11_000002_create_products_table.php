<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Карточка товара = конкретное стекло с конкретным набором опций.
 * Одна карточка может продаваться несколькими брендами (см. offers):
 * "лобовое Camry 70 с ДД и камерой" -> КМК 41 300 ₸ / SL 34 400 ₸ / XYG Solar-X 161 000 ₸.
 *
 * Опции вынесены в отдельные колонки, а не в JSON: по ним идут фильтры,
 * мастер подбора и валидация заказа — всё это должно индексироваться.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');                       // человекочитаемое имя карточки
            $table->text('description')->nullable();

            // Отпечаток «что это за стекло»: марка+модель+годы+тип+опции.
            // По нему строки прайса от разных поставщиков склеиваются в одну карточку,
            // и по нему же повторный импорт находит существующую карточку.
            $table->string('signature', 64)->unique();

            // --- тип и положение ---
            $table->string('glass_type', 24)->index();    // windshield|rear|side|quarter|roof|consumable
            $table->string('position', 16)->nullable();   // front_left|front_right|rear_left|rear_right
            $table->boolean('is_movable')->nullable();    // опускное / глухое

            // --- опции (то, из чего собран мастер подбора) ---
            $table->boolean('sun_band')->default(false);      // ЗП — солнцезащитная полоса
            $table->boolean('rain_sensor')->default(false);   // ДД — датчик дождя
            $table->boolean('camera')->default(false);        // камера ADAS
            $table->boolean('heated_wipers')->default(false); // обогрев зоны щёток
            $table->boolean('heated_full')->default(false);   // полный электрообогрев
            $table->boolean('heated_rear')->default(false);   // ЭО — обогрев заднего стекла
            $table->boolean('antenna')->default(false);
            $table->boolean('hud')->default(false);           // проекционный дисплей
            $table->boolean('acoustic')->default(false);      // АК — шумопоглощающее
            $table->boolean('atermal')->default(false);       // атермальное (Solar-X и т.п.)
            $table->boolean('silkscreen')->default(false);    // шелкография, «кант» в прайсах
            $table->boolean('rhd')->default(false);           // правый руль
            // держатель зеркала: стекло без кронштейна не встанет на машину,
            // где зеркало клеится к стеклу — это разные товары, не опция «на вкус»
            $table->boolean('mirror_bracket')->default(false);
            $table->boolean('vin_window')->default(false);    // окно под VIN
            $table->string('tint', 24)->nullable();           // green|grey|blue|bronze|clear|tinted
            $table->unsignedTinyInteger('holes_count')->nullable();

            // --- физика ---
            $table->unsignedSmallInteger('width_mm')->nullable();
            $table->unsignedSmallInteger('height_mm')->nullable();
            $table->unsignedSmallInteger('weight_g')->nullable();

            // --- коды ---
            $table->string('eurocode', 32)->nullable()->index();  // 7260AGNGYV — маркировка на стекле
            $table->string('oem_code', 64)->nullable()->index();  // код автопроизводителя
            $table->string('internal_code', 32)->nullable()->index(); // #8445 из прайсов

            // --- витрина ---
            $table->string('image_path')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('views')->default(0);
            $table->decimal('price_min', 12, 2)->nullable();  // денормализация от offers, для сортировки
            $table->decimal('price_max', 12, 2)->nullable();
            $table->boolean('in_stock')->default(false);

            // --- служебное ---
            $table->boolean('needs_review')->default(false);  // распознано неуверенно — на ручную проверку
            $table->uuid('external_id')->nullable()->index();
            $table->timestamps();

            $table->index(['glass_type', 'is_published']);
            $table->index(['is_published', 'price_min']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
