<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Габариты в стейджинге.
 *
 * Разбор доставал их из названия всегда — «(1315*813)» у КМК, «1428x862x5,00»
 * у AGC, — но при записи строки в стейджинг эти поля просто не перечислили,
 * и всё, что нашёл парсер, терялось между разбором и сборкой каталога.
 * Отсюда нули в размерах у всех пяти тысяч карточек.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_import_rows', function (Blueprint $table) {
            $table->unsignedSmallInteger('width_mm')->nullable()->after('doors');
            $table->unsignedSmallInteger('height_mm')->nullable()->after('width_mm');
            $table->unsignedTinyInteger('holes_count')->nullable()->after('height_mm');
        });
    }

    public function down(): void
    {
        Schema::table('price_import_rows', function (Blueprint $table) {
            $table->dropColumn(['width_mm', 'height_mm', 'holes_count']);
        });
    }
};
