<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Телефон клиента — уникальный.
 *
 * До сих пор уникальность держалась только на проверке в коде: сначала ищем,
 * потом создаём. Между этими двумя запросами помещается вторая заявка того же
 * человека — а их в этой нише две подряд бывает регулярно (отправил форму,
 * не дождался, отправил снова). В итоге один клиент раздваивался, и половина
 * его истории уходила в карточку-двойника.
 *
 * Уникальный индекс переносит эту гарантию в базу, где её нельзя обойти
 * ни гонкой, ни импортом, ни консолью.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicates();

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }

    /**
     * Слить уже накопившихся двойников.
     *
     * Победитель — самая ранняя карточка: на неё чаще успели сослаться
     * менеджеры, и в ней вручную поправленное имя. Заказы, общение и задачи
     * двойников переезжают к ней, сами двойники удаляются.
     */
    private function mergeDuplicates(): void
    {
        $duplicates = DB::table('customers')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        foreach ($duplicates as $phone) {
            $ids = DB::table('customers')
                ->where('phone', $phone)
                ->orderBy('id')
                ->pluck('id');

            $keep = $ids->shift();

            foreach (['orders', 'interactions', 'tasks'] as $table) {
                DB::table($table)->whereIn('customer_id', $ids)->update(['customer_id' => $keep]);
            }

            DB::table('customers')->whereIn('id', $ids)->delete();

            DB::table('customers')->where('id', $keep)->update([
                'orders_count' => DB::table('orders')->where('customer_id', $keep)->count(),
            ]);
        }
    }
};
