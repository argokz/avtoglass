<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OrderDraftItem extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['offer_id' => 'integer', 'quantity' => 'integer', 'position' => 'integer', 'article_confirmed' => 'boolean', 'manufacturer_confirmed' => 'boolean'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
