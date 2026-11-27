<?php

namespace App\Modules\Complaints\Enums;

/** Auteur d'un message (player, staff) ou d'une entrée d'historique (player, staff, system). */
enum ComplaintActorType: string
{
    case Player = 'player';
    case Staff = 'staff';
    case System = 'system';
}
