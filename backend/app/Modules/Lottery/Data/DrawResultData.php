<?php

namespace App\Modules\Lottery\Data;

use Carbon\CarbonImmutable;

/** Résultat publié d'un tirage, un jour donné : trois lots de deux chiffres, et les numéros bruts s'ils sont connus. */
final readonly class DrawResultData
{
    public function __construct(
        public string $drawKey,
        /** Date locale du tirage, "YYYY-MM-DD". */
        public string $drawDate,
        public string $lot1,
        public string $lot2,
        public string $lot3,
        public ?string $pick3 = null,
        public ?string $pick4 = null,
        public ?CarbonImmutable $publishedAt = null,
        public ?string $externalId = null,
    ) {}

    /** @return array{string, string, string} */
    public function lots(): array
    {
        return [$this->lot1, $this->lot2, $this->lot3];
    }
}
