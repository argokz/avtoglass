<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Настройки CRM, согласие на ПДн и публичный токен заявки.
 *
 * Настройки — ключ-значение, а не колонки: список тумблеров растёт чаще,
 * чем хочется писать миграцию на каждый. Согласие храним на заказе, а не
 * на клиенте: повторная заявка — отдельное волеизъявление. Токен в адресе
 * статуса вместо АС-000124, чтобы чужие заявки нельзя было листать подряд.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->uuid('public_token')->nullable()->unique();
        });

        foreach (DB::table('orders')->whereNull('public_token')->pluck('id') as $id) {
            DB::table('orders')->where('id', $id)->update([
                'public_token' => (string) Str::uuid(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['privacy_accepted_at', 'public_token']);
        });

        Schema::dropIfExists('settings');
    }
};
