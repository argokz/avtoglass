<?php

namespace App\Catalog;

use App\Models\CarBrand;
use App\Models\CarModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

readonly class StorefrontSearchResult
{
    /**
     * @param  Collection<int,CarModel>  $models
     * @param  Collection<int,CarBrand>  $brands
     */
    public function __construct(
        public ?string $query,
        public Collection $models,
        public Collection $brands,
        public LengthAwarePaginator $products,
    ) {}

    public function isEmpty(): bool
    {
        return $this->models->isEmpty()
            && $this->brands->isEmpty()
            && $this->products->isEmpty();
    }
}
