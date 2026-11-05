<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Шифрование персональных данных клиентов (ЗРК «О персональных данных»).
 *
 * Телефон, почта, адреса, госномер, VIN и реквизиты уходят в шифротекст —
 * при утечке дампа базы они не читаются. Поиск по телефону сохраняется через
 * слепой индекс phone_hash: HMAC от нормализованного номера. Уникальность
 * телефона переезжает на хэш: шифротекст каждый раз новый (случайный IV)
 * и индекс по нему ничего бы не гарантировал.
 *
 * Имя остаётся открытым осознанно: по нему менеджер ищет карточку в CRM,
 * а слепой индекс работает только для точного совпадения.
 */
return new class extends Migration
{
    private const ENCRYPTED = ['phone', 'email', 'default_address', 'vin', 'car_plate', 'company_name', 'bin'];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });

        // Шифротекст длиннее исходника — колонки расширяем до текста.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_hash', 64)->nullable()->after('phone');
            $table->text('phone')->change();
            $table->text('email')->nullable()->change();
            $table->text('default_address')->nullable()->change();
            $table->text('vin')->nullable()->change();
            $table->text('car_plate')->nullable()->change();
            $table->text('company_name')->nullable()->change();
            $table->text('bin')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->text('address')->nullable()->change();
        });

        DB::table('customers')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $updates = ['phone_hash' => $this->hash($row->phone)];

                foreach (self::ENCRYPTED as $column) {
                    if ($row->{$column} !== null && $row->{$column} !== '') {
                        $updates[$column] = Crypt::encryptString((string) $row->{$column});
                    }
                }

                DB::table('customers')->where('id', $row->id)->update($updates);
            }
        });

        DB::table('orders')->whereNotNull('address')->where('address', '!=', '')
            ->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'address' => Crypt::encryptString((string) $row->address),
                    ]);
                }
            });

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('phone_hash');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone_hash']);
        });

        DB::table('customers')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $updates = [];

                foreach (self::ENCRYPTED as $column) {
                    if ($row->{$column} !== null && $row->{$column} !== '') {
                        $updates[$column] = Crypt::decryptString((string) $row->{$column});
                    }
                }

                DB::table('customers')->where('id', $row->id)->update($updates);
            }
        });

        DB::table('orders')->whereNotNull('address')->where('address', '!=', '')
            ->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('orders')->where('id', $row->id)->update([
                        'address' => Crypt::decryptString((string) $row->address),
                    ]);
                }
            });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('phone_hash');
            $table->string('phone', 32)->change();
            $table->string('email')->nullable()->change();
            $table->string('default_address')->nullable()->change();
            $table->string('vin', 24)->nullable()->change();
            $table->string('car_plate', 16)->nullable()->change();
            $table->string('company_name', 190)->nullable()->change();
            $table->string('bin', 12)->nullable()->change();
            $table->unique('phone');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('address')->nullable()->change();
        });
    }

    private function hash(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/u', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (mb_strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.mb_substr($digits, 1);
        }

        return hash_hmac('sha256', $digits, (string) config('app.key'));
    }
};
