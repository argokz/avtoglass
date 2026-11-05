<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Антифрод: вердикт по заявке и стоп-лист.
 *
 * IP храним хэшем — для velocity-проверок достаточно равенства,
 * а сам адрес персональным данным в базе не оседает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('risk_status', 16)->default('allow')->index()->after('source');
            $table->json('risk_reasons')->nullable()->after('risk_status');
            $table->string('ip_hash', 64)->nullable()->after('risk_reasons');
        });

        Schema::create('risk_blocklist', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // phone | ip
            $table->string('value', 64); // хэш, не сам номер/адрес
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_blocklist');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['risk_status', 'risk_reasons', 'ip_hash']);
        });
    }
};
