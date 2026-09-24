<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            if (! Schema::hasColumn('car_models', 'image_path')) {
                $table->string('image_path')->nullable()->after('name_ru');
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            if (! Schema::hasColumn('suppliers', 'logo_path')) {
                $table->string('logo_path')->nullable()->after('tier');
            }
        });
    }

    public function down(): void
    {
        Schema::table('car_models', function (Blueprint $table) {
            if (Schema::hasColumn('car_models', 'image_path')) {
                $table->dropColumn('image_path');
            }
        });

        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'logo_path')) {
                $table->dropColumn('logo_path');
            }
        });
    }
};
