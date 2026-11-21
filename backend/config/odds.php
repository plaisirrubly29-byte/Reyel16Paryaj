<?php

/*
| Cotes fixées à la main par l'administration (V2-D03, décision D-18 CO-8).
| La marge de l'opérateur, elle, est réglée dans config/sports_data.php (margin, OddsMargin).
*/

return [

    // Écart avec la cote calculée (en %) au-delà duquel une cote fixée à la main attend un second administrateur.
    'override_approval_threshold_percent' => 20,

];
