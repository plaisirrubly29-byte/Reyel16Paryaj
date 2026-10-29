<?php

/*
| Module Compliance — jeu responsable (P7-02).
| VALEURS PAR DÉFAUT, les plus protectrices (D-01 / D-09 §3), en attendant l'avis juridique (question J-8).
| Montants en unité mineure de la devise du portefeuille.
*/

return [

    'limits' => [
        // Limites de mise sur une période glissante (ex. « jour » = 24 dernières heures).
        'periods' => [
            'day' => 24 * 60,       // minutes
            'week' => 7 * 24 * 60,
            'month' => 30 * 24 * 60,
        ],

        // Une baisse s'applique tout de suite ; une hausse ou une suppression après ce délai de réflexion.
        'increase_delay_days' => (int) env('RG_LIMIT_INCREASE_DELAY_DAYS', 7),

        'max_amount_minor' => 1_000_000_000_00,
    ],

    // Pause : irrévocable avant son terme ; le joueur peut se connecter mais pas parier.
    'pause_durations' => [
        '24h' => '24 hours',
        '7d' => '7 days',
        '30d' => '30 days',
    ],

    // Auto-exclusion : irrévocable ; le compte passe en « excluded » et ne peut plus se connecter.
    // À l'échéance, il reste exclu jusqu'à une demande de réouverture traitée par la Conformité.
    'exclusion_durations' => [
        '6m' => '6 months',
        '1y' => '1 year',
        '5y' => '5 years',
    ],

];
