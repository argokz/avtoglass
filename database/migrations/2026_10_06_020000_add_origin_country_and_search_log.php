<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Происхождение товара и журнал поисковых запросов.
 *
 * origin_country — ISO-2 страны завода. Для витрины РК это не только
 * информация для покупателя: отечественный товар обязан иметь приоритет
 * в общей выдаче, и считать это надо данными, а не подстрокой в названии.
 *
 * search_queries — что искали и сколько нашли. Запросы без результатов —
 * это прямой список того, чего не хватает в каталоге.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->char('origin_country', 2)->nullable()->index()->after('tint');
        });

        // Заполнение из страны поставщика самого дешёвого активного предложения.
        $iso = [
            'казахстан' => 'KZ', 'россия' => 'RU', 'китай' => 'CN',
            'япония' => 'JP', 'польша' => 'PL', 'великобритания' => 'GB',
            'корея' => 'KR', 'южная корея' => 'KR', 'германия' => 'DE',
            'сша' => 'US', 'турция' => 'TR', 'бельгия' => 'BE',
        ];

        $suppliers = DB::table('suppliers')->whereNotNull('country')->pluck('country', 'id');

        foreach ($suppliers as $supplierId => $country) {
            $code = $iso[mb_strtolower(trim((string) $country))] ?? null;

            if ($code === null) {
                continue;
            }

            // Страну карточки не затираем: менеджер мог поставить её руками.
            DB::table('products')
                ->whereNull('origin_country')
                ->whereIn('id', DB::table('offers')
                    ->select('product_id')
                    ->where('supplier_id', $supplierId)
                    ->where('is_active', true))
                ->update(['origin_country' => $code]);
        }

        Schema::create('search_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query');
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamps();

            $table->index(['results_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_queries');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('origin_country');
        });
    }
};
