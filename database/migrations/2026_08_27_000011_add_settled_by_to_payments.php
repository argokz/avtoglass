<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто принял наличные в кассу.
 *
 * У приёма денег от клиента ответственный уже записан — received_by. Но дальше
 * курьер везёт их в офис и кому-то отдаёт, и на этом шаге ответственность
 * до сих пор терялась: settled_at говорил «сдал», но не говорил «кому».
 * При расхождении в кассе спрашивать было бы не с кого — а именно на этом
 * шаге деньги и пропадают.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('settled_by')->nullable()->after('settled_at')
                ->constrained('users')->nullOnDelete();

            // Сводка «у кого сколько на руках» строится по этим двум полям
            $table->index(['received_by', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['received_by', 'settled_at']);
            $table->dropConstrainedForeignId('settled_by');
        });
    }
};
