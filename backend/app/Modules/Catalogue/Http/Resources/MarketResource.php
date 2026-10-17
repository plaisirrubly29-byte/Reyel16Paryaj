<?php

namespace App\Modules\Catalogue\Http\Resources;

use App\Modules\SportsData\Models\Market;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Marché du catalogue : 1X2 (h2h) ou plus/moins (totals, avec sa ligne, ex. "2.5").
 * L'événement (compétition et sport chargés) est rattaché par EventResource pour le libellé.
 *
 * @mixin Market
 */
class MarketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'key' => $this->key,
            'name' => $this->label(),
            // Ligne du plus/moins en chaîne décimale (ex. "2.5") ; null pour le 1X2.
            'line' => $this->line,
            // À renvoyer avec la sélection lors du pari : le serveur refuse une version périmée (P4-03).
            'odds_version' => $this->odds_version,
            'odds_updated_at' => $this->odds_updated_at?->toIso8601String(),
            // false : cote trop ancienne (règle de fraîcheur), affichée mais non pariable.
            'bettable' => $this->isBettable(),
            'selections' => $this->selections->map(fn ($s) => [
                'id' => (string) $s->id,
                'key' => $s->key,
                'label' => match ($s->key) {
                    'home' => '1',
                    'draw' => 'X',
                    'away' => '2',
                    'over' => "+{$this->line}",
                    'under' => "-{$this->line}",
                    default => $s->key,
                },
                'name' => $s->name,
                // Cote en chaîne décimale (ex. "2.10") : jamais de nombre flottant.
                'odds' => self::odds($s->odds),
            ])->values(),
        ];
    }

    private function label(): string
    {
        return match ($this->key) {
            'h2h' => 'Résultat du match (1X2)',
            'totals' => $this->event?->competition?->sport?->slug === 'soccer' ? 'Nombre de buts' : 'Total de points',
            default => $this->key,
        };
    }

    /** "2.100" → "2.10", "1.005" → "1.005". */
    public static function odds(string $odds): string
    {
        return str_ends_with($odds, '0') ? substr($odds, 0, -1) : $odds;
    }
}
