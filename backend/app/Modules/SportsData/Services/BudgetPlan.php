<?php

namespace App\Modules\SportsData\Services;

/** Résultat de CreditBudget::plan() : compétitions à synchroniser maintenant et compétitions reportées. */
final readonly class BudgetPlan
{
    public const REASON_BUDGET = 'budget';

    public const REASON_OFF_SEASON = 'hors saison';

    public const REASON_UNKNOWN = 'hors saison (inconnue du fournisseur)';

    public const REASON_NO_EVENTS = 'aucun match à venir';

    /**
     * @param  list<string>  $sync  dans l'ordre de synchronisation
     * @param  array<string, string>  $skipped  clé de compétition => motif
     */
    public function __construct(
        public array $sync,
        public array $skipped,
        public int $perRunBudget,
        public int $costPerCompetition,
    ) {}

    /** @return list<string> */
    public function skippedForBudget(): array
    {
        return array_keys(array_filter($this->skipped, fn (string $reason) => $reason === self::REASON_BUDGET));
    }
}
