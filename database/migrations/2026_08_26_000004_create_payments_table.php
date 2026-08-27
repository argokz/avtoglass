<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Платежи по заказам.
 *
 * Отдельная таблица, а не поля в заказе: попыток оплаты бывает несколько —
 * первая сорвалась, вторая прошла, — и историю этих попыток надо хранить.
 * Именно её спрашивают, когда клиент говорит «деньги списались, а заказа нет».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Свой публичный идентификатор: id заказа в URL платёжной системы
            // светить незачем, а перебрать его тривиально.
            $table->uuid('token')->unique();

            $table->string('provider', 32);
            $table->string('status', 16)->index();

            // Сумма фиксируется в момент создания платежа. Если прайс потом
            // переедет, платить клиент будет ровно ту сумму, которую видел.
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KZT');

            $table->string('external_id')->nullable()->index();  // id на стороне банка
            $table->json('payload')->nullable();                 // сырой ответ, для разбора споров

            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
