<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Exact-code lookup uses the same normalisation as SearchOffers::normalizeCode.
        DB::statement("CREATE INDEX IF NOT EXISTS offers_article_normalized_idx ON offers (upper(regexp_replace(coalesce(article, ''), '[\\s.\\-]+', '', 'g')))");
        DB::statement("CREATE INDEX IF NOT EXISTS products_eurocode_normalized_idx ON products (upper(regexp_replace(coalesce(eurocode, ''), '[\\s.\\-]+', '', 'g')))");
        DB::statement("CREATE INDEX IF NOT EXISTS products_oem_code_normalized_idx ON products (upper(regexp_replace(coalesce(oem_code, ''), '[\\s.\\-]+', '', 'g')))");
        DB::statement("CREATE INDEX IF NOT EXISTS products_internal_code_normalized_idx ON products (upper(regexp_replace(coalesce(internal_code, ''), '[\\s.\\-]+', '', 'g')))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS offers_article_normalized_idx');
        DB::statement('DROP INDEX IF EXISTS products_eurocode_normalized_idx');
        DB::statement('DROP INDEX IF EXISTS products_oem_code_normalized_idx');
        DB::statement('DROP INDEX IF EXISTS products_internal_code_normalized_idx');
    }
};
