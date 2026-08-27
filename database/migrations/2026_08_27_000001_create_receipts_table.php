<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фискальные чеки.
 *
 * Отдельная таблица, а не поля в платеже, по двум причинам: чек может быть
 * выбит не с первой попытки (касса ОФД недоступна чаще, чем банк), и на один
 * заказ со временем появится второй чек — возвратный.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider', 32)->default('webkassa');
            $table->string('status', 16)->index();

            $table->decimal('amount', 12, 2);

            // Номер чека и ссылка на него — то, что показывают покупателю
            // и чем отчитываются перед налоговой.
            $table->string('check_number')->nullable()->index();
            $table->string('ticket_url')->nullable();
            $table->timestamp('fiscal_at')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error')->nullable();
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
