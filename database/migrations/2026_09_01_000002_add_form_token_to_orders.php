<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Токен рендера формы: повторная отправка (F5 / двойной клик)
            // находит ту же заявку, а не создаёт дубль. NULL — заявки без
            // формы (бронь, менеджер) и старые тесты без токена.
            $table->string('form_token', 64)->nullable()->unique()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['form_token']);
            $table->dropColumn('form_token');
        });
    }
};
