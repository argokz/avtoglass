<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Личный кабинет покупателя.
 *
 * Аккаунт клиента — это тот же users с ролью customer: отдельный guard
 * означал бы вторую систему входа, вторую систему сброса паролей и второй
 * набор мест, где можно ошибиться правами. Роль в админку не пускает —
 * это уже проверено в AdminPanelTest.
 *
 * Клиент входит по телефону, а не по почте: он помнит номер, с которого
 * звонил в сервис. Поэтому email становится необязательным, а телефон —
 * уникальным, но только среди покупателей: у мастера и курьера телефоны
 * тоже записаны, и совпадение с клиентским там ничего не значит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Связь с карточкой в CRM. Не по телефону: номер меняют, а заказы
             * и принятые деньги остаются висеть на карточке.
             */
            $table->foreignId('customer_id')->nullable()->after('role')
                ->constrained('customers')->nullOnDelete();
        });

        // Почта у покупателя может отсутствовать — вход всё равно по телефону.
        DB::statement('ALTER TABLE users ALTER COLUMN email DROP NOT NULL');

        /*
         * Уникальность телефона только среди покупателей: две карточки на один
         * номер — это два личных кабинета с одной историей заказов.
         */
        DB::statement(
            "CREATE UNIQUE INDEX users_customer_phone_unique ON users (phone)
             WHERE role = 'customer' AND phone IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_customer_phone_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email SET NOT NULL');
    }
};
