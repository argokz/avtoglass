<?php

namespace App\Services\PriceList;

use App\Enums\GlassType;

/**
 * Результат разбора одной строки прайса.
 * confidence — насколько парсеру можно верить: ниже порога строка уходит
 * контент-менеджеру на ручную разметку, а не в каталог.
 */
class ParsedName
{
    /** @var array<string,bool> */
    public array $options = [];

    /** @var array<int,array{brand:?string,model:?string}> вторая/третья машина из "Berlingo // Partner" */
    public array $coModels = [];

    /** @var array<int,string> */
    public array $issues = [];

    public function __construct(
        public string $raw,
        public ?string $dialect = null,
        public ?string $supplier = null,
        public ?string $brand = null,
        public ?string $model = null,
        public ?string $generation = null,
        public ?GlassType $glassType = null,
        public ?string $position = null,
        public ?int $yearFrom = null,
        public ?int $yearTo = null,
        public ?string $bodyType = null,
        public ?string $doors = null,
        public ?string $eurocode = null,
        public ?string $internalCode = null,
        public ?string $oemCode = null,
        public ?string $tint = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
        public ?int $holesCount = null,
        public string $leftover = '',
    ) {}

    public function option(string $key): bool
    {
        return $this->options[$key] ?? false;
    }

    public function setOption(string $key, bool $value = true): void
    {
        $this->options[$key] = $value;
    }

    public function addIssue(string $issue): void
    {
        if (! in_array($issue, $this->issues, true)) {
            $this->issues[] = $issue;
        }
    }

    /**
     * Оценка достоверности разбора, 0..100.
     * Марка и тип стекла — обязательны, без них карточку не собрать.
     */
    public function confidence(): int
    {
        $score = 0;

        $score += $this->brand ? 30 : 0;
        $score += $this->model ? 20 : 0;
        $score += $this->glassType ? 25 : 0;
        $score += ($this->yearFrom || $this->yearTo) ? 10 : 0;
        $score += $this->supplier ? 5 : 0;
        $score += $this->bodyType ? 5 : 0;
        $score += ($this->eurocode || $this->internalCode) ? 5 : 0;

        // необъяснённый хвост — признак того, что структуру не поняли
        $leftoverWords = preg_split('/\s+/u', trim($this->leftover), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($leftoverWords) > 4) {
            $score -= 15;
        } elseif (count($leftoverWords) > 2) {
            $score -= 5;
        }

        return max(0, min(100, $score));
    }

    public function status(): string
    {
        if (! $this->brand || ! $this->glassType) {
            return 'review';
        }

        return $this->confidence() >= 70 ? 'parsed' : 'review';
    }

    public function toArray(): array
    {
        return [
            'dialect' => $this->dialect,
            'supplier_name' => $this->supplier,
            'brand_name' => $this->brand,
            'model_name' => $this->model,
            'glass_type' => $this->glassType?->value,
            'year_from' => $this->yearFrom,
            'year_to' => $this->yearTo,
            'body_type' => $this->bodyType,
            'doors' => $this->doors,
            'eurocode' => $this->eurocode,
            'internal_code' => $this->internalCode,
            'options' => $this->options,
            'co_models' => $this->coModels,
            'issues' => $this->issues,
            'confidence' => $this->confidence(),
            'status' => $this->status(),
        ];
    }
}
