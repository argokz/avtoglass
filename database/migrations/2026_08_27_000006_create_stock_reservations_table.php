<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Резерв стекла под заказ.
 *
 * До сих пор «стекло зарезервировано за вами» было надписью на странице
 * благодарности и больше нигде. Остаток лежал в offers.stock_qty и не менялся
 * ни при оплате, ни при отгрузке — то есть одну и ту же единицу можно было
 * продать онлайн сколько угодно раз.
 *
 * Отдельная таблица, а не вычитание из stock_qty, по двум причинам:
 *  — резерв обратим, а вычитание нет: при отмене надо вернуть ровно столько,
 *    сколько сняли, и знать, по какому заказу;
 *  — остаток приходит извне (прайсы, потом 1С) и перезаписывается целиком;
 *    если резерв жил бы в том же поле, следующая же выгрузка стёрла бы его.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Позиция заказа: одна бронь на строку. Заказ может содержать
            // два разных стекла от одного поставщика — это две брони.
            $table->foreignId('order_item_id')->nullable()->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('quantity');
            $table->string('status', 16)->default('held');

            // Бронь по неоплаченному заказу не может держать склад вечно:
            // половина таких заявок просто не доезжает до оплаты.
            $table->dateTime('expires_at')->nullable();

            $table->timestamps();

            // Дважды забронировать одну строку заказа нельзя: повторное
            // подтверждение заказа не должно удваивать резерв.
            $table->unique('order_item_id');
            $table->index(['offer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
