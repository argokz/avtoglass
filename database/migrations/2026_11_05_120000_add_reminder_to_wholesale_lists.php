<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Регулярная заявка: список «каждый понедельник».
 *
 * remind_weekday — день недели по ISO (1 — понедельник), null — без
 * напоминания. reminded_on — день последнего отправленного напоминания,
 * чтобы ежечасный запуск не писал оптовику дважды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wholesale_lists', function (Blueprint $table) {
            $table->unsignedTinyInteger('remind_weekday')->nullable()->after('items');
            $table->date('reminded_on')->nullable()->after('remind_weekday');
            $table->index('remind_weekday');
        });
    }

    public function down(): void
    {
        Schema::table('wholesale_lists', function (Blueprint $table) {
            $table->dropIndex(['remind_weekday']);
            $table->dropColumn(['remind_weekday', 'reminded_on']);
        });
    }
};
