<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клиенты и заказы.
 *
 * Ключевое решение: заказ хранит не ссылку на товар, а снимок позиции —
 * название, цену и поставщика на момент заказа. Прайсы переливаются каждую
 * неделю, карточка может уехать или подорожать, а в заказе должно навсегда
 * остаться то, о чём договорились с клиентом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Телефон — основной идентификатор: почту в этой нише оставляют редко
            $table->string('phone', 32)->index();
            $table->string('email')->nullable();
            $table->text('note')->nullable();

            // машина клиента: заполняется со временем, помогает при повторных обращениях
            $table->foreignId('car_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('car_plate', 16)->nullable();
            $table->string('vin', 24)->nullable();

            $table->unsignedInteger('orders_count')->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // АС-000123 — его называют по телефону. Проставляется после вставки,
            // из настоящего id, поэтому на короткое время строка живёт без номера.
            $table->string('number', 24)->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('status', 24)->index();
            $table->string('source', 24);
            $table->string('fulfillment', 24)->nullable();

            // Менеджер, который ведёт заказ. Заявка приходит ничьей.
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('address')->nullable();
            $table->text('comment')->nullable();             // что написал клиент
            $table->text('manager_note')->nullable();        // что выяснил менеджер

            // Сумма справочная: окончательную с работами называет менеджер
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->string('currency', 3)->default('KZT');

            $table->timestamp('scheduled_at')->nullable();   // время доставки или установки
            $table->timestamp('completed_at')->nullable();
            $table->string('cancel_reason')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Ссылки сохраняем для отчётов, но при удалении карточки заказ выживает:
            // снимок ниже самодостаточен.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');                          // снимок названия
            $table->string('supplier_name')->nullable();     // снимок поставщика
            $table->decimal('price', 12, 2)->nullable();     // снимок цены
            $table->unsignedSmallInteger('quantity')->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
    }
};
