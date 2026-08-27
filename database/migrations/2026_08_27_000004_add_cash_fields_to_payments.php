<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто принял наличные.
 *
 * У онлайн-оплаты ответственный — банк, и спрашивать не с кого. Наличные
 * принимает конкретный человек и какое-то время держит их у себя, поэтому
 * в платеже должно остаться его имя, время и наряд, при котором это было.
 * Без этого при расхождении в кассе выяснять будет нечего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('received_by')->nullable()->after('external_id')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('assignment_id')->nullable()->after('received_by')
                ->constrained()->nullOnDelete();

            // Сдал ли исполнитель деньги в кассу. Пока false — они у него на руках.
            $table->timestamp('settled_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignKey('received_by');
            $table->dropConstrainedForeignKey('assignment_id');
            $table->dropColumn('settled_at');
        });
    }
};
