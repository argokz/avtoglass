<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Журнал поисковых запросов витрины.
 *
 * Запросы с нулём результатов — готовый список пополнения каталога:
 * это то, что люди уже искали и не нашли.
 */
class SearchQuery extends Model
{
    protected $guarded = [];

    public static function record(string $query, int $results): void
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return;
        }

        static::create([
            'query' => mb_substr($query, 0, 190),
            'results_count' => max(0, $results),
        ]);
    }
}
