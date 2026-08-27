<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История общения и задачи менеджера.
 *
 * История пишется и руками, и системой. Системные записи — это журнал: кто
 * перевёл заказ в «Отменён» и когда. Без него на вопрос «почему мы потеряли
 * этого клиента» ответить нечем, а именно этот вопрос и задают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactions', function (Blueprint $table) {
            $table->id();

            // Общение всегда с клиентом, но не всегда по конкретному заказу:
            // человек может позвонить просто спросить.
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();

            // Автора нет у системных записей — их пишет код, а не человек.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 24)->index();
            $table->text('body');

            // Отдельно от created_at: звонок мог быть вчера, а записали сегодня.
            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            $table->index(['customer_id', 'occurred_at']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // Срок обязателен: задача без срока не всплывёт никогда и потеряется.
            $table->timestamp('due_at')->index();

            $table->string('status', 16)->index();
            $table->timestamp('completed_at')->nullable();
            $table->text('result')->nullable();

            $table->timestamps();

            // Главный запрос менеджера: «мои открытые задачи по сроку»
            $table->index(['assigned_to', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('interactions');
    }
};
