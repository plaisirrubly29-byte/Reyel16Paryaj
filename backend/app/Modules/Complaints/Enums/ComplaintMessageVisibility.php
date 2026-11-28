<?php

namespace App\Modules\Complaints\Enums;

/** player : visible du joueur ; internal : note de l'équipe, jamais montrée au joueur. */
enum ComplaintMessageVisibility: string
{
    case Player = 'player';
    case Internal = 'internal';
}
