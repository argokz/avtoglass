<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оптовые покупатели: сервисы, таксопарки, юрлица.
 *
 * Цену закупа (offers.price_wholesale) им не показываем — это себестоимость.
 * Оптовая цена витрины считается от розницы со скидкой, которую ставит
 * менеджер, и не опускается ниже закупа.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('wholesale_status', 16)->default('retail')->after('preferred_fulfillment');
            $table->string('company_name', 190)->nullable()->after('wholesale_status');
            $table->string('bin', 12)->nullable()->after('company_name');
            $table->unsignedTinyInteger('wholesale_discount_percent')->nullable()->after('bin');
            $table->timestamp('wholesale_requested_at')->nullable()->after('wholesale_discount_percent');
            $table->timestamp('wholesale_approved_at')->nullable()->after('wholesale_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'wholesale_status',
                'company_name',
                'bin',
                'wholesale_discount_percent',
                'wholesale_requested_at',
                'wholesale_approved_at',
            ]);
        });
    }
};
