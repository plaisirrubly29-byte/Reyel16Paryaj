<?php

namespace App\Modules\Complaints\Enums;

/** Entrées de l'historique (en ajout seul). Le joueur ne voit ni assigned ni note_added. */
enum ComplaintEventType: string
{
    case Created = 'created';
    case StatusChanged = 'status_changed';
    case Reopened = 'reopened';
    case Assigned = 'assigned';
    case Replied = 'replied';
    case NoteAdded = 'note_added';

    public function visibleToPlayer(): bool
    {
        return ! in_array($this, [self::Assigned, self::NoteAdded], true);
    }
}
