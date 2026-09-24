<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адрес и предпочтения доставки на карточке клиента.
 *
 * Кабинет необязателен: заказ и так оформляется гостем. Но вошедшему
 * человеку не нужно каждый раз заново набирать адрес — он уже оставлял
 * его в прошлой заявке. Храним на CRM-карточке, а не на users: менеджер
 * видит тот же адрес в админке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('default_address')->nullable()->after('vin');
            $table->string('default_city', 64)->nullable()->after('default_address');
            $table->string('preferred_fulfillment', 32)->nullable()->after('default_city');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['default_address', 'default_city', 'preferred_fulfillment']);
        });
    }
};
