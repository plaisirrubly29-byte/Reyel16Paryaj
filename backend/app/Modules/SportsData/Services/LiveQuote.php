<?php

namespace App\Modules\SportsData\Services;

/** Cotes en direct d'un marché à un instant (LiveOddsService) : ouvert avec ses cotes, ou suspendu avec un motif. */
final readonly class LiveQuote
{
    /**
     * @param  array<string, string>  $odds  clé de sélection => cote ("2.150"), vide si suspendu
     */
    public function __construct(
        public bool $open,
        public ?string $reason,
        public array $odds,
    ) {}

    public static function closed(string $reason): self
    {
        return new self(false, $reason, []);
    }
}
