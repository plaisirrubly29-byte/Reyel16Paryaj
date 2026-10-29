<?php

namespace App\Modules\Settlement\Services;

use App\Modules\Settlement\Models\Result;

/** Bilan d'un import de résultats pour une compétition (affiché par la commande, journalisé). */
final class ResultImportReport
{
    /** Nouveaux résultats (première version). */
    public int $recorded = 0;

    /** Résultats différents d'une version déjà enregistrée (nouvelle version). */
    public int $corrected = 0;

    /** Même issue que la version enregistrée : rien à faire. */
    public int $unchanged = 0;

    /** Corrections après règlement : demande de correction en attente de validation (P5-06). */
    public int $awaitingApproval = 0;

    /** Matchs pas encore terminés chez le fournisseur. */
    public int $inProgress = 0;

    /** Matchs absents du catalogue (jamais synchronisés). */
    public int $unknown = 0;

    /** Résultats invalides, ignorés et journalisés. */
    public int $rejected = 0;

    public ?string $error = null;

    /** @var list<Result> versions créées pendant cet import */
    public array $results = [];

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
            'recorded' => $this->recorded,
            'corrected' => $this->corrected,
            'unchanged' => $this->unchanged,
            'awaiting_approval' => $this->awaitingApproval,
            'in_progress' => $this->inProgress,
            'unknown' => $this->unknown,
            'rejected' => $this->rejected,
            'error' => $this->error,
        ];
    }
}
