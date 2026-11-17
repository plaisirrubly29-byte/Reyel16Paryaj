<?php

namespace App\Modules\Settlement\Exceptions;

use InvalidArgumentException;

/**
 * Sélection pas encore réglable : son marché (1re mi-temps, corners, cartons) attend les statistiques du match.
 * Le règlement la laisse en attente sans erreur ; le rattrapage (settlement:run) la reprend plus tard.
 */
class StatisticsPending extends InvalidArgumentException {}
