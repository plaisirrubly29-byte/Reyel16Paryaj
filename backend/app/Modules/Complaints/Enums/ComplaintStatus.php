<?php

namespace App\Modules\Complaints\Enums;

/**
 * Statuts d'une réclamation (V2-G04). Les transitions faites par l'équipe sont bornées par allowedTargets() ;
 * la réouverture par le joueur (resolved ou closed → in_progress) et la fermeture automatique (resolved → closed)
 * passent par ComplaintService.
 */
enum ComplaintStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Investigation = 'investigation';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** @return list<self> statuts que l'équipe peut donner depuis celui-ci */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::New => [self::InProgress, self::Investigation, self::Resolved],
            self::InProgress => [self::Investigation, self::Resolved],
            self::Investigation => [self::InProgress, self::Resolved],
            self::Resolved => [self::Closed, self::InProgress],
            self::Closed => [self::InProgress],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /** En cours de traitement : ni résolue ni fermée. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], true);
    }
}
