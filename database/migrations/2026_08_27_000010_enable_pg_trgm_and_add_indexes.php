<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');

            // GIN триграммные индексы для быстрого поиска и опечаток
            DB::statement('CREATE INDEX IF NOT EXISTS products_name_trgm_idx ON products USING gin (name gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS products_eurocode_trgm_idx ON products USING gin (eurocode gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS products_oem_code_trgm_idx ON products USING gin (oem_code gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS car_models_name_trgm_idx ON car_models USING gin (name gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS car_brands_name_trgm_idx ON car_brands USING gin (name gin_trgm_ops);');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS products_name_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS products_eurocode_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS products_oem_code_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS car_models_name_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS car_brands_name_trgm_idx;');
        }
    }
};
