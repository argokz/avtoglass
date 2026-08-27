<?php

namespace App\Catalog;

/**
 * Результат нормализации имени модели из прайса.
 * name — то, что увидит покупатель в дереве каталога;
 * generation — поколение, вынесенное из имени («Golf II» -> Golf + II).
 */
readonly class NormalizedModel
{
    public function __construct(
        public string $name,
        public ?string $generation = null,
        public bool $fromAlias = false,
    ) {}

    public function key(): string
    {
        return mb_strtolower($this->name);
    }
}
