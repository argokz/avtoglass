<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стейджинг импорта прайса.
 * Файл никогда не применяется к каталогу напрямую: сначала строки падают сюда,
 * парсятся и валидируются, и только потом переносятся в products/offers/fitments.
 * Здесь же контент-менеджер доразмечает то, что парсер не понял.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_import_runs', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->string('file_hash', 64)->nullable()->index();  // защита от повторного импорта того же файла
            $table->string('source')->nullable();                  // 1С / поставщик
            $table->string('status', 16)->default('pending');      // pending|parsing|parsed|applied|failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_parsed')->default(0);
            $table->unsignedInteger('rows_review')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('price_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_import_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_no');

            // как есть из файла
            $table->text('raw_name');
            $table->string('raw_unit', 16)->nullable();
            $table->string('raw_price', 32)->nullable();
            $table->string('raw_article', 64)->nullable();

            // результат парсинга
            $table->string('dialect', 16)->nullable();     // kmk|sl|spektr|agc|xyg|bgc|unknown
            $table->string('supplier_name')->nullable();
            $table->string('brand_name')->nullable();
            $table->string('model_name')->nullable();
            $table->string('glass_type', 24)->nullable();
            $table->string('position', 16)->nullable();    // left|right — для бокового это разные товары
            $table->unsignedSmallInteger('year_from')->nullable();
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->string('body_type', 24)->nullable();
            $table->string('doors', 8)->nullable();
            $table->string('eurocode', 32)->nullable();
            $table->string('internal_code', 32)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->json('options')->nullable();           // {rain_sensor:true, camera:false, ...}
            $table->json('co_models')->nullable();         // "Berlingo // Partner" -> вторая машина

            $table->string('status', 16)->default('new');  // new|parsed|review|failed|applied
            $table->unsignedTinyInteger('confidence')->default(0);  // 0..100
            $table->json('issues')->nullable();

            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['price_import_run_id', 'status']);
            $table->index(['price_import_run_id', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_import_rows');
        Schema::dropIfExists('price_import_runs');
    }
};
