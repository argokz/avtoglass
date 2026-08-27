<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Логистика: где ставим стекло и кто везёт.
 *
 * Наряд отделён от заказа намеренно. По одному заказу бывает две работы —
 * привезти стекло и потом поставить, — а бывает, что первая попытка сорвалась
 * и нужна вторая. В поле scheduled_at у заказа это не помещается.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_points', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('phone', 32)->nullable();

            // Сколько машин помещается одновременно. По этому числу проверяется,
            // не записали ли третьего клиента на два поста.
            $table->unsignedTinyInteger('bays')->default(1);

            $table->time('opens_at')->default('09:00');
            $table->time('closes_at')->default('20:00');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('type', 24);
            $table->string('status', 24)->index();

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('service_point_id')->nullable()->constrained()->nullOnDelete();

            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes');

            // Адрес снимком: клиент может поменять его в профиле, а курьер
            // должен ехать туда, куда договаривались.
            $table->string('address')->nullable();
            $table->text('note')->nullable();

            // Порядок в маршруте курьера на день
            $table->unsignedSmallInteger('sequence')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // Основной запрос курьера: «мои наряды на сегодня по порядку»
            $table->index(['assignee_id', 'scheduled_at']);
            $table->index(['service_point_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
        Schema::dropIfExists('service_points');
    }
};
