<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Models;

use App\Domain\Ordering\SalesMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OrderDraft extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $hidden = ['customer_id', 'guest_owner_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'sales_mode' => SalesMode::class, 'vehicle' => 'encrypted:array',
            'contacts' => 'encrypted:array', 'fulfillment' => 'encrypted:array'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderDraftItem::class, 'draft_id')->orderBy('position');
    }
}
