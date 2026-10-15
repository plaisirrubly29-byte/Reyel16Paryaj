<?php

namespace App\Modules\SportsData\Services;

/** Bilan d'une synchronisation de compétition (affiché par la commande, journalisé). */
final class SyncReport
{
    public int $eventsCreated = 0;

    public int $eventsUpdated = 0;

    public int $oddsChanged = 0;

    public int $skipped = 0;

    public ?string $error = null;

    public function __construct(public readonly string $competitionKey) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'competition' => $this->competitionKey,
            'created' => $this->eventsCreated,
            'updated' => $this->eventsUpdated,
            'odds_changed' => $this->oddsChanged,
            'skipped' => $this->skipped,
            'error' => $this->error,
        ];
    }
}
