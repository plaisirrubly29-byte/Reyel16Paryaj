<?php

namespace App\Modules\Betting\Services;

use App\Models\User;
use App\Modules\Betting\Models\BetTicket;
use App\Modules\Compliance\Contracts\StakeHistory;
use DateTimeInterface;

/** Mises acceptées d'un joueur, pour les limites de jeu responsable (P7-02). */
class TicketStakeHistory implements StakeHistory
{
    public function stakedSince(User $user, DateTimeInterface $since): int
    {
        // Montant misé, quel que soit le sort du ticket (gagné, perdu, remboursé) : c'est ce que la limite borne.
        return (int) BetTicket::where('user_id', $user->id)->where('placed_at', '>=', $since)->sum('stake_minor');
    }
}
