<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Предложение (оффер) = конкретное стекло конкретного бренда со своей ценой и остатком.
 * Разделение product/offer нужно, потому что в прайсе одно и то же стекло
 * встречается от 5 поставщиков с разбросом цены до x5 (это разные классы товара,
 * а не конкуренция: СПЕКТР 17 500 ₸ vs XYG Solar-X 97 200 ₸ на одну Camry 30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // КМК, SL, СПЕКТР, XYG, AGC, BGC
            $table->string('slug')->unique();
            $table->string('tier', 16)->default('standard'); // economy|standard|premium|oem
            $table->string('country', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('warranty_months')->default(12);
            $table->unsignedSmallInteger('default_lead_days')->default(0);
            $table->decimal('markup_percent', 5, 2)->default(30);  // наценка опт -> розница
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(500);
            $table->uuid('external_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();

            $table->string('article', 64)->nullable()->index();   // артикул поставщика
            $table->text('raw_name');                             // строка прайса как есть — для сверки

            $table->decimal('price_wholesale', 12, 2)->nullable(); // то, что приходит в прайсе
            $table->decimal('price_retail', 12, 2)->nullable();    // розница = опт * наценка
            $table->string('currency', 3)->default('KZT');

            $table->integer('stock_qty')->default(0);
            $table->unsignedSmallInteger('lead_days')->nullable(); // срок поставки под заказ
            $table->unsignedTinyInteger('warranty_months')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('price_updated_at')->nullable();
            $table->uuid('external_id')->nullable()->index();
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'price_retail']);
            $table->index(['supplier_id', 'article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::dropIfExists('suppliers');
    }
};
