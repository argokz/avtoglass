<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Город витрины больше не декорация: пост и заявка знают, куда везти.
 * Пустой city у поста — точка видна во всех городах (старые записи).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_points', function (Blueprint $table) {
            $table->string('city', 64)->nullable()->after('address');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('city', 64)->nullable()->after('address');
        });

        $default = (string) (config('company.legal_city') ?: 'Караганда');

        DB::table('service_points')->whereNull('city')->update(['city' => $default]);
    }

    public function down(): void
    {
        Schema::table('service_points', function (Blueprint $table) {
            $table->dropColumn('city');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('city');
        });
    }
};
