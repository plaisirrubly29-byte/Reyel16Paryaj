<?php

namespace App\Modules\Settlement\Console;

use App\Modules\Settlement\Services\SettlementReport;
use App\Modules\Settlement\Services\SettlementService;
use App\Modules\SportsData\Models\Event;
use Illuminate\Console\Command;

/**
 * Règle (ou relance le règlement) des tickets (P5-05). Sans --event : rattrapage de tout ce qui attend
 * (SettlementService::sweep), planifié toutes les 15 minutes. Idempotent : relancer ne paie jamais deux fois.
 */
class RunSettlementCommand extends Command
{
    protected $signature = 'settlement:run
        {--event=* : Identifiant(s) de match à régler, par défaut tout ce qui attend un règlement}';

    protected $description = 'Règle les tickets des matchs qui ont un résultat (relançable sans risque)';

    public function handle(SettlementService $settlement): int
    {
        if ($this->option('event')) {
            $report = new SettlementReport;
            foreach (Event::whereKey($this->option('event'))->orderBy('id')->get() as $event) {
                $report->merge($settlement->settleEvent($event));
            }
        } else {
            $report = $settlement->sweep();
        }

        $this->table(
            ['Sélections', 'Gagnés', 'Perdus', 'Annulés', 'Versé (unité mineure)', 'Échecs'],
            [[$report->selections, $report->tickets['won'], $report->tickets['lost'], $report->tickets['void'], $report->paidMinor, $report->failed]],
        );

        return $report->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
